<?php
declare(strict_types=1);
namespace TerrainPhonics;

/** Analytic directional waves and bounded expanding wakes, shared with water-functions.glsl. */
final class Water implements WaterSurface
{
	/** Limits shared by the shader include. */
	public const MAX_WAVES = 8, MAX_WAKES = 16, WAKE_LIFETIME = 8.0;
	/** Render configuration shared with the scene and its opaque objects. */
	public WaterAppearance $appearance;
	/** Authored waves and their current amplitude-scaled values used by both samplers. */
	private array $baseWaves, $waves = [];
	private float $waveScale = 1.0;
	/** Wake centres, amplitudes, and creation times. */
	private array $wakes = [];
	/** Time sent to the renderer and monotonic edit revision. */
	private float $time = 0;
	private int $revision = 0;

	/**
	 * Construct a water plane with optional explicit waves; an empty list is flat water.
	 * @param float $level Mean world-space surface height in metres.
	 * @param array $waves Records with amplitude, wavelength, direction [x,z], optional angularSpeed and phase.
	 * Angular speed defaults to the deep-water dispersion relation sqrt(9.81 * wavenumber).
	 * @return void Retains validated, normalized parameters for CPU and GPU use.
	 */
	public function __construct(private float $level = 0, array $waves = [])
	{
		if(!is_finite($level) || count($waves) > self::MAX_WAVES)
		throw new \InvalidArgumentException('Water requires a finite level and at most eight waves');

		$this->appearance = new WaterAppearance;

		foreach($waves as $wave)
		{
			$amplitude = (float)($wave['amplitude'] ?? .15);
			$wavelength = (float)($wave['wavelength'] ?? 12);
			$direction = $wave['direction'] ?? [1,0];
			$x = (float)($direction[0] ?? 0);
			$z = (float)($direction[1] ?? 0);
			$length = hypot($x, $z);
			$phase = (float)($wave['phase'] ?? 0);

			if(!is_finite($amplitude) || !is_finite($wavelength) || $wavelength <= 0
				|| !is_finite($length) || $length < 1e-9 || !is_finite($phase)
			)
			throw new \InvalidArgumentException('Invalid directional water wave');

			$k = 2 * M_PI / $wavelength;
			$omega = (float)($wave['angularSpeed'] ?? sqrt(9.81 * $k));

			if(!is_finite($omega)) throw new \InvalidArgumentException('Invalid wave angular speed');

			$this->waves[] = [
				'direction' => [$x / $length,$z / $length]
				, 'amplitude' => $amplitude
				, 'wavenumber' => $k
				, 'angularSpeed' => $omega
				, 'phase' => $phase
			];
		}
		$this->baseWaves = $this->waves;
	}

	/**
	 * Scale the amplitudes of the authored waves. Wakes retain their own strengths.
	 * @param float $scale Nonnegative multiplier. Zero gives calm water between wakes.
	 * @return void Updates the physical samples, shader inputs and visibility bounds together.
	 */
	public function setWaveScale(float $scale): void
	{
		if(!is_finite($scale) || $scale < 0) throw new \InvalidArgumentException('Wave scale must be finite and nonnegative');
		if($this->waveScale === $scale) return;

		$waves = [];
		if($scale > 0)
		{
			foreach($this->baseWaves as $wave)
			{
				$wave['amplitude'] *= $scale;
				if(!is_finite($wave['amplitude'])) throw new \InvalidArgumentException('Scaled wave amplitude must be finite');
				$waves[] = $wave;
			}
		}
		$this->waves = $waves;
		$this->waveScale = $scale;
		$this->revision++;
	}

	/**
	 * Read the amplitude multiplier of the directional waves.
	 * @return float Current scale relative to the authored waves.
	 */
	public function waveScale(): float { return $this->waveScale; }

	/**
	 * Replace the common water level without changing any wave phase.
	 * @param float $level Mean surface height in metres.
	 * @return void Increments the revision only when the level changes.
	 */
	public function setLevel(float $level): void
	{
		if(!is_finite($level)) throw new \InvalidArgumentException('Water level must be finite');

		if($this->level !== $level)
		{
			$this->level = $level;
			$this->revision++;
		}
	}

	/**
	 * Read the common water-plane elevation.
	 * @return float Mean surface height in metres.
	 */
	public function level(): float { return $this->level; }

	/**
	 * @return array Conservative height interval for the displaced mesh including every wake.
	 */
	public function heightRange(): array
	{
		$span = 0.0;
		foreach($this->waves as $wave) $span += abs($wave['amplitude']);
		foreach($this->wakes as $wake) $span += abs($wake[2]);
		return [$this->level-$span,$this->level+$span];
	}

	/**
	 * Read the revision of explicit water level, wave scale and wake edits.
	 * @return int Structural revision, separate from time.
	 */
	public function revision(): int { return $this->revision; }

	/**
	 * Advance renderer time and discard expired wakes. Sampling remains explicitly time-indexed.
	 * @param float $time Finite simulation time in seconds.
	 * @return void Keeps only the bounded live wake records.
	 */
	public function update(float $time): void
	{
		if(!is_finite($time)) throw new \InvalidArgumentException('Water time must be finite');

		$this->time = $time;
		$live = [];

		foreach($this->wakes as $wake)
		if($time - $wake[3] < self::WAKE_LIFETIME) $live[] = $wake;

		$this->wakes = $live;
	}

	/**
	 * Add an expanding wave packet; oldest packets are evicted at the fixed capacity.
	 * @param float $x World X in metres.
	 * @param float $z World Z in metres.
	 * @param float $strength Signed amplitude in metres, clamped to two metres.
	 * @param float $time Creation time in seconds.
	 * @return void Adds one bounded wake and advances the edit revision.
	 */
	public function wake(float $x, float $z, float $strength, float $time): void
	{
		foreach([$x,$z,$strength,$time] as $value)
		if(!is_finite($value)) throw new \InvalidArgumentException('Wake parameters must be finite');

		$this->wakes[] = [$x,$z,max(-2.0, min(2.0, $strength)),$time];

		if(count($this->wakes) > self::MAX_WAKES) array_shift($this->wakes);

		$this->revision++;
	}

	/**
	 * Evaluate exact heights and first derivatives of the analytic wave field.
	 * @param float $x World X in metres.
	 * @param float $z World Z in metres.
	 * @param float $time Simulation time in seconds.
	 * @return array{height:float,normal:array,velocity:float,dx:float,dz:float} Height and analytic derivatives.
	 */
	public function sample(float $x, float $z, float $time): array
	{
		foreach([$x,$z,$time] as $value)
		if(!is_finite($value)) throw new \InvalidArgumentException('Water coordinates must be finite');

		$height = $this->level;
		$dx = $dz = $velocity = 0.0;

		foreach($this->waves as $wave)
		{
			[$wx,$wz] = $wave['direction'];
			$k = $wave['wavenumber'];
			$omega = $wave['angularSpeed'];
			$phase = $k * ($wx * $x + $wz * $z) - $omega * $time + $wave['phase'];
			$slope = $wave['amplitude'] * cos($phase);
			$height += $wave['amplitude'] * sin($phase);
			$dx += $slope * $k * $wx;
			$dz += $slope * $k * $wz;
			$velocity -= $slope * $omega;
		}

		foreach($this->wakes as [$wx,$wz,$strength,$start])
		{
			$age = $time - $start;

			if($age <= 0 || $age >= self::WAKE_LIFETIME) continue;

			// Smooth radial distance avoids an undefined derivative at the packet centre.
			$r = sqrt(($x - $wx) ** 2 + ($z - $wz) ** 2 + .04);
			$q = $r - 3 * $age;
			$fade = sin(M_PI * $age / self::WAKE_LIFETIME) ** 2;
			$envelope = $strength * exp(-.45 * $age - .35 * $q * $q);
			$phase = 2.2 * $q;
			$radial = $envelope * $fade * (2.2 * cos($phase) - .7 * $q * sin($phase));
			$height += $envelope * $fade * sin($phase);
			$dx += $radial * ($x - $wx) / $r;
			$dz += $radial * ($z - $wz) / $r;
			$fadeRate = M_PI / self::WAKE_LIFETIME * sin(2 * M_PI * $age / self::WAKE_LIFETIME);
			$velocity += $envelope * ($fadeRate - .45 * $fade) * sin($phase) - 3 * $radial;
		}

		$length = sqrt(1 + $dx * $dx + $dz * $dz);

		return [
			'height' => $height
			, 'normal' => [-$dx / $length,1 / $length,-$dz / $length]
			, 'velocity' => $velocity
			, 'dx' => $dx
			, 'dz' => $dz
		];
	}

	/**
	 * Supply packed vectors matching water-functions.glsl; unused slots need not be uploaded.
	 * @return array Level/time plus waveVectors vec4(dx,dz,k,amplitude), waveDynamics vec2(omega,phase),
	 * and wakeVectors vec4(x,z,strength,start), each represented as a list of vectors.
	 */
	public function uniforms(): array
	{
		$vectors = $dynamics = [];

		foreach($this->waves as $wave)
		{
			$vectors[] = [...$wave['direction'],$wave['wavenumber'],$wave['amplitude']];
			$dynamics[] = [$wave['angularSpeed'],$wave['phase']];
		}

		return [
			'level' => $this->level
			, 'time' => $this->time
			, 'waveCount' => count($vectors)
			, 'waveVectors' => $vectors
			, 'waveDynamics' => $dynamics
			, 'wakeCount' => count($this->wakes)
			, 'wakeVectors' => $this->wakes
			, 'waves' => $this->waves
			, 'wakes' => $this->wakes
		];
	}
}
