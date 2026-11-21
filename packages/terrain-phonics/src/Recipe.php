<?php
declare(strict_types=1);
namespace TerrainPhonics;

/** Validated serializable terrain recipe and independent coarse slope guide. */
final class Recipe
{
	/** Named sets of coefficients for the shared terrain field. */
	public const PRESETS = ['coast', 'mountain', 'canyon', 'limestone'];

	/** Parameters are immutable after normalization. */
	private array $values;
	/** One deterministic noise source, sampled with distinct octave offsets. */
	private Noise $noise;
	private float $wallStart = .055;
	private float $wallWidth = .006;
	private bool $layered = false;
	/** Lattice hash of the noise source. coarse() and height() read these tables directly to avoid Noise calls. */
	private array $permutation;
	private array $gradientX;
	private array $gradientZ;
	/**
	 * Squared lattice radius inside which coarse() and height() expand the noise in place. Both coordinates of
	 * a point inside it lie strictly between -2 ** 29 and 2 ** 29, so their integer casts and cell indices fit
	 * the 32-bit integers of browser PHP. The Noise methods handle every point outside it, including the
	 * non-finite ones they reject.
	 */
	private const REACH = 288230376151711744.0;

	/** Shape coefficients copied by preset selection and by the legacy recipe loader. */
	public static function shapeDefaults(string $preset, int $version = 2): array
	{
		if($preset === 'limestone')
		return [
			'seed' => 'limestone-plain', 'heightScale' => 3950., 'baseFrequency' => 1 / 3050, 'floorWidth' => .18, 'wallWidth' => .91,
			'wallHeight' => 1., 'rolling' => 0., 'secondary' => 0., 'directionalRise' => 0.,
			'warpAmplitude' => 120., 'warpFrequency' => 1 / 3800, 'detailAmplitude' => 1.2, 'detailOctaves' => 4,
			'terraceSpacing' => 175., 'terraceStrength' => .44, 'waterLevel' => -40., 'surround' => 'mountains'
		] + self::shapeDefaults('coast', $version);

		$coast = $preset === 'coast';
		$canyon = $preset === 'canyon';
		return [
			'baseBias' => $coast ? 0.0 : ($canyon ? -.55 : .35),
			'rolling' => $coast ? .6 : 0.0, 'directionalRise' => $coast ? .35 : 0.0,
			'ridgeHeight' => $coast || $canyon ? 0.0 : 1.0, 'ridgeRoundness' => .04,
			'wallHeight' => $canyon ? 1.0 : 0.0, 'floorWidth' => .055, 'wallWidth' => .006,
			'secondary' => $coast || $canyon ? .15 : .24,
			'bedding' => $canyon && $version === 2 ? 1.0 : 0.0,
			'surround' => $coast ? 'sea' : ($canyon ? 'plateau' : 'mountains'), 'transitionWidth' => 2048.0
		];
	}

	/**
	 * Validate a bounded world recipe before allocating any terrain samples.
	 * @param array $values Optional recipe fields overriding the documented defaults.
	 * @return void Validates parameters before generating any terrain.
	 */
	public function __construct(array $values = [])
	{
		$preset = array_key_exists('preset', $values) ? $values['preset'] : 'mountain';
		$canyon = $preset === 'canyon';
		$version = array_key_exists('version', $values) ? $values['version'] : ($canyon || $preset === 'limestone' ? 2 : 1);
		$defaults = [
			'version' => $version
			, 'seed' => 'first-light'
			, 'preset' => 'mountain'

			, 'worldSize' => 32768.0
			, 'spacing' => 1.0
			, 'tileSize' => 8
			, 'maxTiles' => 2048
			, 'waterLevel' => 0.0
			, 'heightScale' => 420.0
			, 'baseFrequency' => 1 / 6000

			, 'detailOctaves' => 8
			, 'detailAmplitude' => 28.0
			, 'lacunarity' => 2.0
			, 'persistence' => .5
			, 'slopeScale' => .45
			, 'damping' => 2.0

			, 'warpAmplitude' => 200.0
			, 'warpFrequency' => 1 / 10000
			, 'terraceSpacing' => 0.0
			, 'terraceStrength' => $canyon && $version === 2 ? .92 : .7
		];

		if(!in_array($preset, self::PRESETS, true))
		throw new \InvalidArgumentException('Unknown terrain preset. Expected one of ' . implode(', ', self::PRESETS));
		if(!in_array($version, [1,2], true)) throw new \InvalidArgumentException('Unsupported terrain recipe version');
		$defaults = self::shapeDefaults($preset, $version) + $defaults;
		if(array_diff_key($values, $defaults)) throw new \InvalidArgumentException('Unknown terrain recipe field');

		$values += $defaults;

		if(!is_string($values['seed']) && !is_int($values['seed']))
		throw new \InvalidArgumentException('Invalid terrain seed');

		$values['seed'] = (string)$values['seed'];

		if(strlen($values['seed']) > 256) throw new \InvalidArgumentException('Terrain seed is too long');

		if(!in_array($values['surround'], ['none','sea','plateau','mountains'], true))
		throw new \InvalidArgumentException('Unknown terrain surround');

		foreach([
			'baseBias' => [-2,2], 'rolling' => [0,2], 'directionalRise' => [-2,2], 'ridgeHeight' => [0,2],
			'ridgeRoundness' => [.001,1], 'wallHeight' => [0,2], 'floorWidth' => [.03,1],
			'wallWidth' => [.001,1], 'secondary' => [0,2], 'bedding' => [0,1], 'transitionWidth' => [1,65536],
			'worldSize' => [16,65536]
			, 'spacing' => [.25,32]
			, 'waterLevel' => [-10000,10000]
			, 'heightScale' => [0,10000]
			, 'baseFrequency' => [1 / 1000000,1]

			, 'detailAmplitude' => [0,1000]
			, 'lacunarity' => [1.1,4]
			, 'persistence' => [0,1]
			, 'slopeScale' => [.001,100]
			, 'damping' => [0,100]

			, 'warpAmplitude' => [0,5000]
			, 'warpFrequency' => [1 / 1000000,.01]
			, 'terraceSpacing' => [0,1000]
			, 'terraceStrength' => [0,1]
		] as $key => [$min,$max])
		{
			$value = $values[$key];

			if((!is_int($value) && !is_float($value)) || !is_finite((float)$value) || $value < $min || $value > $max)
			throw new \InvalidArgumentException('Invalid terrain ' . $key);

			$values[$key] = (float)$value;
		}

		foreach(['tileSize' => [4,32], 'maxTiles' => [4,16384], 'detailOctaves' => [0,12]] as $key => [$min,$max])
		{
			if(!is_int($values[$key]) || $values[$key] < $min || $values[$key] > $max)
			throw new \InvalidArgumentException('Invalid terrain ' . $key);
		}

		$this->layered = $values['bedding'] > 0 && $values['terraceSpacing'] > 0 && $values['terraceStrength'] > 0;
		$this->wallWidth = $values['wallWidth'];
		$this->wallStart = $values['floorWidth'];
		if($this->layered)
		{
			// Allow space across the wall for shelves while preserving the unstepped contour at zero strength.
			$this->wallStart -= .025 * $values['terraceStrength'] * $values['bedding'];
			$this->wallWidth += .105 * $values['terraceStrength'] * $values['bedding'];
		}
		$this->values = $values;
		$this->noise = new Noise($values['seed']);
		[$this->permutation, $this->gradientX, $this->gradientZ] = $this->noise->lattice();
	}

	/**
	 * Return the portable normalized parameters, without cached samples.
	 * @return array Normalized portable recipe parameters.
	 */
	public function values(): array { return $this->values; }

	/**
	 * Evaluate the C2 macro field, including world-space first derivatives.
	 *
	 * A node sampled through the Noise methods makes over a hundred calls, and calls are the main cost of PHP
	 * in the browser. Each sample here is therefore Noise::sample() written out with the same operations in
	 * the same order, which returns the same bits. A scalar suffixed 0, 1 or 2 holds a sample's value, X
	 * derivative or Z derivative. An integer cast stepped down below a negative fraction replaces floor().
	 * @param float $x World X coordinate in meters.
	 * @param float $z World Z coordinate in meters.
	 * @return array Coarse height and world-space derivatives [height,dx,dz].
	 */
	public function coarse(float $x, float $z): array
	{
		$r = $this->values;
		$f = $r['baseFrequency'];
		$scale = $r['heightScale'];

		if($scale === 0.0) return [0.0,0.0,0.0];

		$datum = $this->layered ? CanyonBeds::DIP[0]*$x + CanyonBeds::DIP[1]*$z : 0;
		// Warp Jacobian in row order. It stays the identity without a warp.
		$j0 = $j3 = 1.0;
		$j1 = $j2 = 0.0;
		$p = $this->permutation;
		$gx = $this->gradientX;
		$gz = $this->gradientZ;

		if($r['warpAmplitude'] > 0)
		{
			$wf = $r['warpFrequency'];
			$wa = $r['warpAmplitude'];
			$sx = $x * $wf + 271.13;
			$sz = $z * $wf - 197.41;

			// Warp along X: Noise::sample($sx, $sz) written out.
			if($sx * $sx + $sz * $sz < self::REACH)
			{
				$ix = (int)$sx; $iz = (int)$sz;

				if($sx < $ix) $ix--;

				if($sz < $iz) $iz--;

				$sx -= $ix; $sz -= $iz; $ix &= 255; $iz &= 255;
				$h = $p[$ix] + $iz; $k = $p[$ix + 1] + $iz;
				$tx = $sx - 1; $tz = $sz - 1;
				$u = $sx * $sx * $sx * ($sx * ($sx * 6 - 15) + 10);
				$v = $sz * $sz * $sz * ($sz * ($sz * 6 - 15) + 10);
				$du = 30 * $sx * $sx * $tx * $tx;

				$lx = $gx[$h]; $lz = $gz[$h]; $rx = $gx[$k]; $rz = $gz[$k];
				$left = $lx * $sx + $lz * $sz;
				$span = $rx * $tx + $rz * $sz - $left;
				$near = $left + $u * $span;
				$nearX = $lx + $u * ($rx - $lx) + $du * $span;
				$nearZ = $lz + $u * ($rz - $lz);

				$h++; $k++;
				$lx = $gx[$h]; $lz = $gz[$h]; $rx = $gx[$k]; $rz = $gz[$k];
				$left = $lx * $sx + $lz * $tz;
				$span = $rx * $tx + $rz * $tz - $left;
				$rise = $left + $u * $span - $near;
				$wx0 = $near + $v * $rise;
				$wx1 = $nearX + $v * ($lx + $u * ($rx - $lx) + $du * $span - $nearX);
				$wx2 = $nearZ + $v * ($lz + $u * ($rz - $lz) - $nearZ) + 30 * $sz * $sz * $tz * $tz * $rise;
			}
			else [$wx0,$wx1,$wx2] = $this->noise->sample($sx, $sz);

			$sx = $x * $wf - 419.27;
			$sz = $z * $wf + 113.71;

			// Warp along Z: Noise::sample($sx, $sz) written out.
			if($sx * $sx + $sz * $sz < self::REACH)
			{
				$ix = (int)$sx; $iz = (int)$sz;

				if($sx < $ix) $ix--;

				if($sz < $iz) $iz--;

				$sx -= $ix; $sz -= $iz; $ix &= 255; $iz &= 255;
				$h = $p[$ix] + $iz; $k = $p[$ix + 1] + $iz;
				$tx = $sx - 1; $tz = $sz - 1;
				$u = $sx * $sx * $sx * ($sx * ($sx * 6 - 15) + 10);
				$v = $sz * $sz * $sz * ($sz * ($sz * 6 - 15) + 10);
				$du = 30 * $sx * $sx * $tx * $tx;

				$lx = $gx[$h]; $lz = $gz[$h]; $rx = $gx[$k]; $rz = $gz[$k];
				$left = $lx * $sx + $lz * $sz;
				$span = $rx * $tx + $rz * $sz - $left;
				$near = $left + $u * $span;
				$nearX = $lx + $u * ($rx - $lx) + $du * $span;
				$nearZ = $lz + $u * ($rz - $lz);

				$h++; $k++;
				$lx = $gx[$h]; $lz = $gz[$h]; $rx = $gx[$k]; $rz = $gz[$k];
				$left = $lx * $sx + $lz * $tz;
				$span = $rx * $tx + $rz * $tz - $left;
				$rise = $left + $u * $span - $near;
				$wz0 = $near + $v * $rise;
				$wz1 = $nearX + $v * ($lx + $u * ($rx - $lx) + $du * $span - $nearX);
				$wz2 = $nearZ + $v * ($lz + $u * ($rz - $lz) - $nearZ) + 30 * $sz * $sz * $tz * $tz * $rise;
			}
			else [$wz0,$wz1,$wz2] = $this->noise->sample($sx, $sz);

			$x += $wa * $wx0;
			$z += $wa * $wz0;
			$j0 = 1 + $wa * $wf * $wx1;
			$j1 = $wa * $wf * $wx2;
			$j2 = $wa * $wf * $wz1;
			$j3 = 1 + $wa * $wf * $wz2;
		}

		$sx = $x * $f + 13.17;
		$sz = $z * $f - 9.31;

		// Contour field: Noise::sample($sx, $sz) written out.
		if($sx * $sx + $sz * $sz < self::REACH)
		{
			$ix = (int)$sx; $iz = (int)$sz;

			if($sx < $ix) $ix--;

			if($sz < $iz) $iz--;

			$sx -= $ix; $sz -= $iz; $ix &= 255; $iz &= 255;
			$h = $p[$ix] + $iz; $k = $p[$ix + 1] + $iz;
			$tx = $sx - 1; $tz = $sz - 1;
			$u = $sx * $sx * $sx * ($sx * ($sx * 6 - 15) + 10);
			$v = $sz * $sz * $sz * ($sz * ($sz * 6 - 15) + 10);
			$du = 30 * $sx * $sx * $tx * $tx;

			$lx = $gx[$h]; $lz = $gz[$h]; $rx = $gx[$k]; $rz = $gz[$k];
			$left = $lx * $sx + $lz * $sz;
			$span = $rx * $tx + $rz * $sz - $left;
			$near = $left + $u * $span;
			$nearX = $lx + $u * ($rx - $lx) + $du * $span;
			$nearZ = $lz + $u * ($rz - $lz);

			$h++; $k++;
			$lx = $gx[$h]; $lz = $gz[$h]; $rx = $gx[$k]; $rz = $gz[$k];
			$left = $lx * $sx + $lz * $tz;
			$span = $rx * $tx + $rz * $tz - $left;
			$rise = $left + $u * $span - $near;
			$a0 = $near + $v * $rise;
			$a1 = $nearX + $v * ($lx + $u * ($rx - $lx) + $du * $span - $nearX);
			$a2 = $nearZ + $v * ($lz + $u * ($rz - $lz) - $nearZ) + 30 * $sz * $sz * $tz * $tz * $rise;
		}
		else [$a0,$a1,$a2] = $this->noise->sample($sx, $sz);

		$sx = $x * $f * 2 + 71.43;
		$sz = $z * $f * 2 + 53.11;

		// Broad relief: Noise::sample($sx, $sz) written out.
		if($sx * $sx + $sz * $sz < self::REACH)
		{
			$ix = (int)$sx; $iz = (int)$sz;

			if($sx < $ix) $ix--;

			if($sz < $iz) $iz--;

			$sx -= $ix; $sz -= $iz; $ix &= 255; $iz &= 255;
			$h = $p[$ix] + $iz; $k = $p[$ix + 1] + $iz;
			$tx = $sx - 1; $tz = $sz - 1;
			$u = $sx * $sx * $sx * ($sx * ($sx * 6 - 15) + 10);
			$v = $sz * $sz * $sz * ($sz * ($sz * 6 - 15) + 10);
			$du = 30 * $sx * $sx * $tx * $tx;

			$lx = $gx[$h]; $lz = $gz[$h]; $rx = $gx[$k]; $rz = $gz[$k];
			$left = $lx * $sx + $lz * $sz;
			$span = $rx * $tx + $rz * $sz - $left;
			$near = $left + $u * $span;
			$nearX = $lx + $u * ($rx - $lx) + $du * $span;
			$nearZ = $lz + $u * ($rz - $lz);

			$h++; $k++;
			$lx = $gx[$h]; $lz = $gz[$h]; $rx = $gx[$k]; $rz = $gz[$k];
			$left = $lx * $sx + $lz * $tz;
			$span = $rx * $tx + $rz * $tz - $left;
			$rise = $left + $u * $span - $near;
			$b0 = $near + $v * $rise;
			$b1 = $nearX + $v * ($lx + $u * ($rx - $lx) + $du * $span - $nearX);
			$b2 = $nearZ + $v * ($lz + $u * ($rz - $lz) - $nearZ) + 30 * $sz * $sz * $tz * $tz * $rise;
		}
		else [$b0,$b1,$b2] = $this->noise->sample($sx, $sz);

		$value = $r['baseBias'] + $r['rolling']*$a0;
		$dx = $r['rolling']*$a1*$f;
		$dz = $r['rolling']*$a2*$f;
		if($r['ridgeHeight'] > 0)
		{
			$rounded = sqrt($a0 * $a0 + $r['ridgeRoundness'] * $r['ridgeRoundness']);
			$value -= $r['ridgeHeight'] * $rounded;
			$factor = -$r['ridgeHeight']*$a0 / $rounded;
			$dx += $factor*$a1*$f;
			$dz += $factor*$a2*$f;
		}
		if($r['wallHeight'] > 0 || $this->layered)
		{
			// A narrow contour band connects a distinct floor and plateau. The quintic
			// has zero first/second derivatives at both ends, so clamping remains C2.
			// abs() is harmless at zero because a constant floor surrounds that point.
			// Comparisons replace its call for every ordinary value. A zero or NaN goes through abs(), which
			// defines its sign bit.
			$distance = ($a0 > 0 ? $a0 : ($a0 < 0 ? -$a0 : abs($a0))) - $this->wallStart;
			$sign = $a0 < 0 ? -1 : 1;
			$erosionX = $erosionZ = 0.0;

			if($this->layered)
			{
				// A drainage channel cuts sideways into the escarpment and buries part of its benches.
				$ef = 22*$f;
				$sx = $x*$ef+43.7;
				$sz = $z*$ef-81.3;

				// Drainage: Noise::sample($sx, $sz) written out.
				if($sx * $sx + $sz * $sz < self::REACH)
				{
					$ix = (int)$sx; $iz = (int)$sz;

					if($sx < $ix) $ix--;

					if($sz < $iz) $iz--;

					$sx -= $ix; $sz -= $iz; $ix &= 255; $iz &= 255;
					$h = $p[$ix] + $iz; $k = $p[$ix + 1] + $iz;
					$tx = $sx - 1; $tz = $sz - 1;
					$u = $sx * $sx * $sx * ($sx * ($sx * 6 - 15) + 10);
					$v = $sz * $sz * $sz * ($sz * ($sz * 6 - 15) + 10);
					$du = 30 * $sx * $sx * $tx * $tx;

					$lx = $gx[$h]; $lz = $gz[$h]; $rx = $gx[$k]; $rz = $gz[$k];
					$left = $lx * $sx + $lz * $sz;
					$span = $rx * $tx + $rz * $sz - $left;
					$near = $left + $u * $span;
					$nearX = $lx + $u * ($rx - $lx) + $du * $span;
					$nearZ = $lz + $u * ($rz - $lz);

					$h++; $k++;
					$lx = $gx[$h]; $lz = $gz[$h]; $rx = $gx[$k]; $rz = $gz[$k];
					$left = $lx * $sx + $lz * $tz;
					$span = $rx * $tx + $rz * $tz - $left;
					$rise = $left + $u * $span - $near;
					$erosion0 = $near + $v * $rise;
					$erosion1 = $nearX + $v * ($lx + $u * ($rx - $lx) + $du * $span - $nearX);
					$erosion2 = $nearZ + $v * ($lz + $u * ($rz - $lz) - $nearZ) + 30 * $sz * $sz * $tz * $tz * $rise;
				}
				else [$erosion0,$erosion1,$erosion2] = $this->noise->sample($sx, $sz);

				$retreat = .029*$r['terraceStrength']*$r['bedding'];
				$distance -= $retreat*$erosion0;
				$erosionX = $retreat*$erosion1*$ef;
				$erosionZ = $retreat*$erosion2*$ef;

				// Comparisons clamp every ordinary value to [0,1] without a call. A zero or NaN goes through
				// max() and min(), which define the sign of zero and the handling of NaN.
				$channel = ($erosion0+.13)/.62;
				$channel = $channel >= 1.0 ? 1.0
					: ($channel > 0.0 ? $channel : ($channel < 0.0 ? 0.0 : max(0.0,min(1.0,$channel))));

				// Noise::fade() written out, as is every fade below.
				$fade = $channel * $channel * $channel * ($channel * ($channel * 6 - 15) + 10);
				$strength = $r['bedding']*$r['terraceStrength']*(1-.80*$fade);
				$ds = -$r['bedding']*$r['terraceStrength']*.80*30*$channel**2*($channel-1)**2/.62*$ef;
				$strengthX = $ds*($erosion1*$j0+$erosion2*$j2);
				$strengthZ = $ds*($erosion1*$j1+$erosion2*$j3);
			}

			// The same clamp as the channel above.
			$wall = $distance / $this->wallWidth;
			$wall = $wall >= 1.0 ? 1.0 : ($wall > 0.0 ? $wall : ($wall < 0.0 ? 0.0 : max(0.0, min(1.0, $wall))));

			$value += $r['wallHeight'] * $wall * $wall * $wall * ($wall * ($wall * 6 - 15) + 10);
			$factor = $r['wallHeight'] * 30 * $wall * $wall * ($wall - 1) ** 2 / $this->wallWidth;
			$dx += $sign*$factor*$a1*$f - $factor*$erosionX;
			$dz += $sign*$factor*$a2*$f - $factor*$erosionZ;
		}
		if($r['directionalRise'] != 0)
		{
			$coast = tanh($x / 4500);
			$value += $r['directionalRise']*$coast;
			$dx += $r['directionalRise']*(1-$coast*$coast)/4500;
		}

		$value += $r['secondary']*$b0;
		$dx += 2*$r['secondary']*$b1*$f;
		$dz += 2*$r['secondary']*$b2*$f;
		$height = $scale * $value;
		// Both world derivatives read both field derivatives, so hold the first until the second exists.
		$worldX = $scale * ($dx * $j0 + $dz * $j2);
		$dz = $scale * ($dx * $j1 + $dz * $j3);
		$dx = $worldX;

		if($this->layered)
		{
			[$relative, $factor, $displacement] = CanyonBeds::shape($height - $datum, $r['terraceSpacing'], $strength);
			$height = $relative + $datum;
			$dx = $factor * $dx + (1 - $factor) * CanyonBeds::DIP[0] + $displacement*$strengthX;
			$dz = $factor * $dz + (1 - $factor) * CanyonBeds::DIP[1] + $displacement*$strengthZ;
		}
		elseif($r['terraceSpacing'] > 0 && $r['terraceStrength'] > 0)
		{
			$spacing = $r['terraceSpacing'];
			$strength = $r['terraceStrength'];
			$level = floor($height / $spacing);
			$fraction = $height / $spacing - $level;

			$fade = $fraction * $fraction * $fraction * ($fraction * ($fraction * 6 - 15) + 10);
			$height += $strength * ($spacing * ($level + $fade) - $height);
			$factor = 1 - $strength + $strength * 30 * $fraction * $fraction * ($fraction - 1) ** 2;
			$dx *= $factor;
			$dz *= $factor;
		}

		return [$height,$dx,$dz];
	}

	/**
	 * Sample finite smooth detail, gating every octave from the independent coarse guide.
	 *
	 * The octave loop reads each recipe field once and writes Noise::value() out in place, for the reason
	 * given on coarse(). The operations keep their order, so every height keeps its bits.
	 * @param float $x World X coordinate in meters.
	 * @param float $z World Z coordinate in meters.
	 * @return float Final procedural lattice height in meters.
	 */
	public function height(float $x, float $z): float
	{
		$r = $this->values;
		[$height,$dx,$dz] = $this->coarse($x, $z);
		$slope = ($dx * $dx + $dz * $dz) / ($r['slopeScale'] * $r['slopeScale']);
		$frequency = $r['baseFrequency'] * 8;
		$amplitude = $r['detailAmplitude'];

		if($amplitude === 0.0) return $height;

		$octaves = $r['detailOctaves'];
		$damping = $r['damping'];
		$lacunarity = $r['lacunarity'];
		$persistence = $r['persistence'];
		$p = $this->permutation;
		$gx = $this->gradientX;
		$gz = $this->gradientZ;

		for($i = 0; $i < $octaves; $i++)
		{
			$weight = 1 / (1 + $damping * ($i + 1) * ($i + 1) * $slope);
			$sx = $x * $frequency + 91.73 + $i * 19.17;
			$sz = $z * $frequency - 23.49 - $i * 31.29;

			// Detail octave: Noise::value($sx, $sz) written out.
			if($sx * $sx + $sz * $sz < self::REACH)
			{
				$ix = (int)$sx; $iz = (int)$sz;

				if($sx < $ix) $ix--;

				if($sz < $iz) $iz--;

				$sx -= $ix; $sz -= $iz; $ix &= 255; $iz &= 255;
				$h = $p[$ix] + $iz; $k = $p[$ix + 1] + $iz;
				$tx = $sx - 1; $tz = $sz - 1;
				$u = $sx * $sx * $sx * ($sx * ($sx * 6 - 15) + 10);
				$v = $sz * $sz * $sz * ($sz * ($sz * 6 - 15) + 10);

				$left = $gx[$h] * $sx + $gz[$h] * $sz;
				$near = $left + $u * ($gx[$k] * $tx + $gz[$k] * $sz - $left);
				$h++; $k++;
				$left = $gx[$h] * $sx + $gz[$h] * $tz;
				$value = $near + $v * ($left + $u * ($gx[$k] * $tx + $gz[$k] * $tz - $left) - $near);
			}
			else $value = $this->noise->value($sx, $sz);

			$height += $amplitude * $weight * $value;
			$frequency *= $lacunarity;
			$amplitude *= $persistence;
		}

		return $height;
	}

	/**
	 * Bound every procedural node in a world rectangle, including warp, terraces and gated detail.
	 * @param array $box Horizontal bounds [minX,minZ,maxX,maxZ], including any sample halo.
	 * @return array Conservative node height interval in meters.
	 */
	public function regionalBounds(array $box): array
	{
		$r = $this->values;
		$x = ($box[0] + $box[2]) / 2;
		$z = ($box[1] + $box[3]) / 2;
		$rx = ($box[2] - $box[0]) / 2 + 1e-9;
		$rz = ($box[3] - $box[1]) / 2 + 1e-9;
		$wx = $x; $wz = $z; $hx = $rx; $hz = $rz;
		$jac = 1.0;
		$w = $r['warpAmplitude'];
		$f = $r['warpFrequency'];
		if($w > 0)
		{
			$a = $this->noise->envelope($x*$f+271.13, $z*$f-197.41, $rx*$f, $rz*$f);
			$b = $this->noise->envelope($x*$f-419.27, $z*$f+113.71, $rx*$f, $rz*$f);
			$wx += $w * ($a[0]+$a[1])/2; $hx += $w * ($a[1]-$a[0])/2 + 1e-9;
			$wz += $w * ($b[0]+$b[1])/2; $hz += $w * ($b[1]-$b[0])/2 + 1e-9;
			$jac += $w*$f * max($a[2]+$b[2], $a[3]+$b[3]);
		}
		$f = $r['baseFrequency'];
		$a = $this->noise->envelope($wx*$f+13.17, $wz*$f-9.31, $hx*$f, $hz*$f);
		$b = $this->noise->envelope($wx*$f*2+71.43, $wz*$f*2+53.11, $hx*$f*2, $hz*$f*2);
		$absMin = max(0.0, $a[0], -$a[1]);
		$absMax = max(-$a[0], $a[1]);
		$da = max($a[2],$a[3])*$f;
		$db = max($b[2],$b[3])*$f*2;
		$lo = $r['baseBias'] + $r['rolling']*$a[0] + $r['secondary']*$b[0];
		$hi = $r['baseBias'] + $r['rolling']*$a[1] + $r['secondary']*$b[1];
		$gradient = $r['rolling']*$da + 2*$r['secondary']*$db;
		if($r['ridgeHeight'] > 0)
		{
			$lo -= $r['ridgeHeight']*sqrt($absMax**2 + $r['ridgeRoundness']**2);
			$hi -= $r['ridgeHeight']*sqrt($absMin**2 + $r['ridgeRoundness']**2);
			$gradient += $r['ridgeHeight']*$da;
		}
		if($r['directionalRise'] != 0)
		{
			$left = $r['directionalRise']*tanh(($wx-$hx)/4500);
			$right = $r['directionalRise']*tanh(($wx+$hx)/4500);
			$lo += min($left,$right);
			$hi += max($left,$right);
			$gradient += abs($r['directionalRise'])/4500;
		}
		if($r['wallHeight'] > 0 || $this->layered)
		{
			if($this->layered)
			{
				$ef = 22*$f;
				$erosion = $this->noise->envelope($wx*$ef+43.7,$wz*$ef-81.3,$hx*$ef,$hz*$ef);
				$retreat = .029*$r['terraceStrength']*$r['bedding'];
				$absMin -= $retreat*$erosion[1];
				$absMax -= $retreat*$erosion[0];
				$dg = max($erosion[2],$erosion[3])*$ef;
				$da += $retreat*$dg;
				$strengthGradient = $r['bedding']*$r['terraceStrength']*.80*1.875/.62*$dg*$jac;
			}
			$wallLo = max(0.0,min(1.0,($absMin-$this->wallStart)/$this->wallWidth));
			$wallHi = max(0.0,min(1.0,($absMax-$this->wallStart)/$this->wallWidth));
			$lo += $r['wallHeight']*Noise::fade($wallLo);
			$hi += $r['wallHeight']*Noise::fade($wallHi);
			$gradient += $r['wallHeight']*($wallHi === 0.0 || $wallLo === 1.0 ? 0 : 1.875/$this->wallWidth)*$da + .15*$db;
		}
		$lo *= $r['heightScale']; $hi *= $r['heightScale'];
		$gradient *= $r['heightScale']*$jac;
		$spacing = $r['terraceSpacing'];
		if($this->layered)
		{
			$datum = CanyonBeds::DIP[0]*$x + CanyonBeds::DIP[1]*$z;
			$spread = abs(CanyonBeds::DIP[0])*$rx + abs(CanyonBeds::DIP[1])*$rz;
			// The section is monotone. Bound its argument and its added datum separately.
			$low = $lo-$datum-$spread; $high = $hi-$datum+$spread;
			$strength = $r['bedding']*$r['terraceStrength'];
			// Elevation is affine in local strength, and strength falls as the erosion noise rises. The noise
			// envelope of this box therefore gives the two strengths that enclose every gully inside it.
			$weak = $strength*(1-.80*Noise::fade(max(0.0,min(1.0,($erosion[1]+.13)/.62))));
			$firm = $strength*(1-.80*Noise::fade(max(0.0,min(1.0,($erosion[0]+.13)/.62))));
			$lo = min(CanyonBeds::shape($low,$spacing,$weak)[0], CanyonBeds::shape($low,$spacing,$firm)[0])
				+ $datum-$spread;
			$hi = max(CanyonBeds::shape($high,$spacing,$weak)[0], CanyonBeds::shape($high,$spacing,$firm)[0])
				+ $datum+$spread;
			// The narrowest ramp spans .46 of its bed. The widest bed is 2.96 spacing units.
			$gradient = 4.1*$gradient + 3.1*max(abs(CanyonBeds::DIP[0]),abs(CanyonBeds::DIP[1]))
				+ 3*$spacing*$strengthGradient;
		}
		elseif($spacing > 0)
		{
			// Within a terrace the displacement is always less than the terrace spacing.
			// Tiny spacings can lose the fractional part of the level in binary64.
			$lo -= $spacing*$r['terraceStrength'];
			$hi += $spacing*$r['terraceStrength'];
			$gradient *= 1.875;
		}
		$slope = 2 * (($gradient+1e-8) / $r['slopeScale'])**2;
		$f *= 8;
		$amp = $r['detailAmplitude'];
		for($i=0; $i<$r['detailOctaves'] && $amp>0; $i++)
		{
			[$n,$m] = $this->noise->envelope($x*$f+91.73+$i*19.17, $z*$f-23.49-$i*31.29, $rx*$f, $rz*$f);
			$minWeight = 1 / (1+$r['damping']*($i+1)**2*$slope);
			$lo += $amp * min($n,$n*$minWeight);
			$hi += $amp * max($m,$m*$minWeight);
			$f *= $r['lacunarity'];
			$amp *= $r['persistence'];
		}
		return [$lo-1e-6,$hi+1e-6];
	}

	/**
	 * Conservative node extrema include the maximum possible gradient-noise magnitude.
	 * @return array Conservative lower and upper node heights.
	 */
	public function bounds(): array
	{
		$r = $this->values;
		$detail = 0;
		$amplitude = $r['detailAmplitude'];

		for($i = 0; $i < $r['detailOctaves']; $i++)
		{
			$detail += 2 * $amplitude;
			$amplitude *= $r['persistence'];
		}

		$macro = abs($r['baseBias']) + 2*($r['rolling']+$r['secondary']) + abs($r['directionalRise'])
			+ $r['ridgeHeight']*sqrt(4+$r['ridgeRoundness']**2) + $r['wallHeight'];
		$range = max(3.0,$macro) * $r['heightScale'] + $detail + ($this->layered ? 3 : 1) * $r['terraceSpacing'];

		return [-$range, $range];
	}
}
