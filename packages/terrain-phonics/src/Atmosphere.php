<?php
declare(strict_types=1);
namespace TerrainPhonics;

/**
 * Spherical exponential Rayleigh/Mie single scattering in meters and linear RGB.
 *
 * Optical depth follows Beer-Lambert extinction. The model includes planet shadow and a finite solar disk;
 * multiple scattering, clouds, ozone, and ground-reflected light are deliberately outside this first model.
 */
final class Atmosphere
{
	public const DEFAULT_NIGHT_GLOW = 0.5;

	/** Physical and display parameters, separate from the cached optical-depth integration. */
	private array $parameters;
	/** Most recently requested transmittance texture; bounded to one resolution per instance. */
	private ?array $lut = null;
	/** Signature and generation of the last numerical texture. */
	private string $lutKey = '';
	private int $lutGeneration = 0;

	/**
	 * Configure Earth-like scattering, with optional overrides in meters and inverse meters.
	 * @param array $options Keys match uniforms(); sunIntensity is irradiance in the model's linear units.
	 * @return void Validates all parameters and normalizes the direction from the viewer toward the sun.
	 */
	public function __construct(array $options = [])
	{
		$this->parameters = array_replace([
			'planetRadius' => 6371000.0
			, 'atmosphereHeight' => 80000.0
			, 'rayleighHeight' => 8000.0
			, 'mieHeight' => 1200.0
			, 'rayleigh' => [5.802e-6,13.558e-6,33.1e-6]
			, 'mie' => 3.996e-6
			, 'mieAlbedo' => .9
			, 'mieG' => .76
			, 'haze' => 1.0
			, 'sunDirection' => [.3,.8,.2]
			, 'sunIntensity' => 20.0
			, 'angularRadius' => .004675
			, 'exposure' => 1.0
			, 'nightGlow' => self::DEFAULT_NIGHT_GLOW
		], $options
		);

		foreach(['planetRadius','atmosphereHeight','rayleighHeight','mieHeight','angularRadius','mieAlbedo'] as $key)
		{
			$value = $this->parameters[$key];

			if(!is_numeric($value) || !is_finite((float)$value) || $value <= 0)
			throw new \InvalidArgumentException('Atmosphere requires a positive ' . $key);
		}

		foreach(['mie','haze','sunIntensity','exposure','nightGlow'] as $key)
		{
			$value = $this->parameters[$key];

			if(!is_numeric($value) || !is_finite((float)$value) || $value < 0)
			throw new \InvalidArgumentException('Atmosphere requires a nonnegative ' . $key);
		}

		if(!is_finite((float)$this->parameters['mieG']) || abs($this->parameters['mieG']) >= .99
			|| $this->parameters['mieAlbedo'] > 1 || $this->parameters['angularRadius'] > .1
		)
		throw new \InvalidArgumentException('Invalid atmospheric phase or solar disk parameters');

		if(count($this->parameters['rayleigh']) !== 3)
		throw new \InvalidArgumentException('Rayleigh coefficients require three channels');

		foreach($this->parameters['rayleigh'] as $value)
		if(!is_numeric($value) || !is_finite((float)$value) || $value < 0)
		throw new \InvalidArgumentException('Invalid Rayleigh coefficient');

		$this->parameters['sunDirection'] = self::direction($this->parameters['sunDirection']);
	}

	/**
	 * Move the sun without rebuilding the transmittance texture, which does not depend on sunlight.
	 * @param array $direction Direction toward the sun as [x,y,z], with Y upward.
	 * @param float|null $intensity Optional nonnegative solar irradiance.
	 * @return void Replaces illumination parameters only.
	 */
	public function setSun(array $direction, ?float $intensity = null): void
	{
		$direction = self::direction($direction);

		if($intensity !== null && (!is_finite($intensity) || $intensity < 0))
		throw new \InvalidArgumentException('Sun intensity must be finite and nonnegative');

		$this->parameters['sunDirection'] = $direction;

		if($intensity !== null) $this->parameters['sunIntensity'] = $intensity;
	}

	/**
	 * Change aerosol density and invalidate optical data only when it changes.
	 * @param float $haze Nonnegative multiplier of Mie scattering and extinction.
	 * @return void Keeps molecular Rayleigh scattering unchanged.
	 */
	public function setHaze(float $haze): void
	{
		if(!is_finite($haze) || $haze < 0) throw new \InvalidArgumentException('Haze must be nonnegative');

		if($this->parameters['haze'] != $haze)
		{
			$this->parameters['haze'] = $haze;
			$this->lut = null;
		}
	}

	/**
	 * Change display exposure without changing physical radiance or cached transmittance.
	 * @param float $exposure Nonnegative linear exposure multiplier.
	 * @return void Updates the display parameter.
	 */
	public function setExposure(float $exposure): void
	{
		if(!is_finite($exposure) || $exposure < 0) throw new \InvalidArgumentException('Exposure must be nonnegative');

		$this->parameters['exposure'] = $exposure;
	}

	/** Set the artistic night illumination without rebuilding solar scattering or extinction textures. */
	public function setNightGlow(float $strength): void
	{
		if(!is_finite($strength) || $strength < 0) throw new \InvalidArgumentException('Night glow must be nonnegative');
		$this->parameters['nightGlow'] = $strength;
	}

	/**
	 * Read shader parameters.
	 * @return array Physical uniforms plus texture dimensions and generation.
	 */
	public function uniforms(): array
	{
		// Smooth dusk from the horizon to ten degrees below. Star visibility is independent of the fill dimmer.
		$t = min(1.0, max(0.0, -$this->parameters['sunDirection'][1] / 0.17364818));
		$night = $t * $t * (3 - 2 * $t);
		$strength = $night * $this->parameters['nightGlow'];
		return [
			'nightVisibility' => $night
			, 'nightAmbient' => [.075 * $strength, .15 * $strength, .60 * $strength]
		] + $this->parameters + [
			'transmittanceSize' => [$this->lut['width'] ?? 0,$this->lut['height'] ?? 0]
			, 'lutGeneration' => $this->lutGeneration
		];
	}

	/**
	 * Integrate extinction toward the top of the spherical atmosphere.
	 * @param float $altitude Height above sea level; clamped to the supported atmosphere domain.
	 * @param float $cosZenith Dot product of ray direction with local upward, from minus one to one.
	 * @param int $samples Midpoint integration samples, between eight and 256.
	 * @return array Three linear transmittances, or zero when the ray intersects the planet.
	 */
	public function transmittance(float $altitude, float $cosZenith, int $samples = 48): array
	{
		[$altitude,$cosZenith] = $this->coordinates($altitude, $cosZenith);
		$samples = max(8, min(256, $samples));
		[$distance,$ground] = $this->boundary($altitude, $cosZenith);

		if($ground) return [0.0,0.0,0.0];

		$dr = $dm = 0.0;
		$step = $distance / $samples;

		for($i = 0; $i < $samples; $i++)
		{
			$h = $this->rayAltitude($altitude, $cosZenith, ($i + .5) * $step);
			$dr += exp(-$h / $this->parameters['rayleighHeight']) * $step;
			$dm += exp(-$h / $this->parameters['mieHeight']) * $step;
		}

		return $this->attenuation($dr, $dm);
	}

	/**
	 * Build or reuse a GPU-ready lookup texture, with more samples near sea level and the horizon.
	 * @param int $width Zenith samples, between four and 512.
	 * @param int $height Altitude samples, between two and 256.
	 * @param int $samples Optical-depth samples per texel, between eight and 256.
	 * @return array Width/height, RGB float data, RGBA32F little-endian bytes, RGBA8 bytes, and generation.
	 * Texel X encodes sign(mu)*sqrt(abs(mu)); texel Y encodes sqrt(altitude/atmosphereHeight).
	 */
	public function transmittanceLut(int $width = 128, int $height = 32, int $samples = 48): array
	{
		if($width < 4 || $width > 512 || $height < 2 || $height > 256 || $samples < 8 || $samples > 256)
		throw new \InvalidArgumentException('Unsupported transmittance texture dimensions');

		$key = $width . ':' . $height . ':' . $samples;

		if($this->lut !== null && $this->lutKey === $key) return $this->lut;

		$data = [];
		$bytes = $rgba8 = '';

		for($y = 0; $y < $height; $y++)
		for($x = 0; $x < $width; $x++)
		{
			$altitude = ($y / ($height - 1)) ** 2 * $this->parameters['atmosphereHeight'];
			$u = 2 * $x / ($width - 1) - 1;
			$rgb = $this->transmittance($altitude, $u * abs($u), $samples);
			array_push($data, ...$rgb);
			$bytes .= pack('g4', ...[...$rgb,1.0]);

			foreach($rgb as $channel) $rgba8 .= chr((int)round(255 * $channel));

			$rgba8 .= chr(255);
		}

		$this->lutKey = $key;
		$this->lutGeneration++;
		$this->lut = compact('width', 'height', 'data', 'bytes', 'rgba8') + ['generation' => $this->lutGeneration];

		return $this->lut;
	}

	/**
	 * Evaluate sky glow, single-scattered radiance and the attenuated solar disk for CPU previews and tests.
	 * @param array $direction Viewing direction as [x,y,z].
	 * @param float $altitude Viewer height above sea level, in meters.
	 * @param int $samples View-ray integration samples, clamped between eight and 128.
	 * @return array Linear HDR RGB; tone mapping belongs to the final composition pass.
	 */
	public function sky(array $direction, float $altitude = 0, int $samples = 24): array
	{
		$ray = self::direction($direction);
		[$altitude,$mu] = $this->coordinates($altitude, $ray[1]);
		[$distance,$ground] = $this->boundary($altitude, $mu);
		$samples = max(8, min(128, $samples));
		$step = $distance / $samples;
		$sun = $this->parameters['sunDirection'];
		$nu = max(-1.0, min(1.0, $ray[0] * $sun[0] + $ray[1] * $sun[1] + $ray[2] * $sun[2]));
		$g = $this->parameters['mieG'];
		$phaseR = 3 * (1 + $nu * $nu) / (16 * M_PI);
		$phaseM = (1 - $g * $g) / (4 * M_PI * (1 + $g * $g - 2 * $g * $nu) ** 1.5);
		$dr = $dm = 0.0;
		$rgb = [0.0,0.0,0.0];
		$radius = $this->parameters['planetRadius'] + $altitude;

		for($i = 0; $i < $samples; $i++)
		{
			$d = ($i + .5) * $step;
			$h = $this->rayAltitude($altitude, $mu, $d);
			$r = exp(-$h / $this->parameters['rayleighHeight']) * $step;
			$m = exp(-$h / $this->parameters['mieHeight']) * $step;
			$sunMu = ($d * $ray[0] * $sun[0] + ($radius + $d * $ray[1]) * $sun[1]
				+ $d * $ray[2] * $sun[2]
			) / ($this->parameters['planetRadius'] + $h);

			$light = $this->transmittance($h, $sunMu, 32);
			$view = $this->attenuation($dr + .5 * $r, $dm + .5 * $m);

			for($channel = 0; $channel < 3; $channel++)
			$rgb[$channel] += $view[$channel] * $light[$channel] * $this->parameters['sunIntensity']
				* ($r * $this->parameters['rayleigh'][$channel] * $phaseR
					+ $m * $this->parameters['mie'] * $this->parameters['haze'] * $phaseM
				);

			$dr += $r;
			$dm += $m;
		}

		if(!$ground && $nu >= cos($this->parameters['angularRadius']))
		{
			$disk = $this->parameters['sunIntensity'] / (2 * M_PI * (1 - cos($this->parameters['angularRadius'])));
			$view = $this->attenuation($dr, $dm);

			for($channel = 0; $channel < 3; $channel++) $rgb[$channel] += $view[$channel] * $disk;
		}

		$gradient = max(0.0, min(1.0, $ray[1]));
		$glow = .27 - .17 * $gradient * (2 - $gradient);
		foreach($this->uniforms()['nightAmbient'] as $channel => $fill) $rgb[$channel] += $fill * $glow;
		return $rgb;
	}

	/**
	 * Expose linear radiance, then encode sRGB; called once after all linear lighting and atmosphere.
	 * @param array $linear Three nonnegative linear RGB values.
	 * @param float $exposure Nonnegative exposure multiplier.
	 * @return array Display RGB values bounded to zero through one.
	 */
	public static function toneMap(array $linear, float $exposure = 1): array
	{
		if(count($linear) !== 3 || !is_finite($exposure) || $exposure < 0)
		throw new \InvalidArgumentException('Tone mapping requires RGB and finite nonnegative exposure');

		$result = [];

		foreach($linear as $channel)
		{
			if(!is_finite((float)$channel) || $channel < 0)
			throw new \InvalidArgumentException('Radiance must be finite and nonnegative');

			$value = 1 - exp(-$channel * $exposure);

			$result[] = $value <= .0031308 ? 12.92 * $value : 1.055 * $value ** (1 / 2.4) - .055;
		}

		return $result;
	}

	/**
	 * Convert molecular/aerosol optical lengths into channel transmittances.
	 * @param float $rayleigh Integrated molecular density in meters.
	 * @param float $mie Integrated aerosol density in meters.
	 * @return array Three Beer-Lambert transmittances.
	 */
	private function attenuation(float $rayleigh, float $mie): array
	{
		$extinction = $this->parameters['mie'] * $this->parameters['haze'] / $this->parameters['mieAlbedo'];

		$result = [];

		foreach($this->parameters['rayleigh'] as $beta) $result[] = exp(-$beta * $rayleigh - $extinction * $mie);

		return $result;
	}

	/**
	 * Find the nearest atmosphere or planet boundary without near-vertical subtractive cancellation.
	 * @param float $altitude Validated altitude above the planet.
	 * @param float $mu Cosine of ray zenith.
	 * @return array Distance in meters and whether the boundary is opaque ground.
	 */
	private function boundary(float $altitude, float $mu): array
	{
		$planet = $this->parameters['planetRadius'];
		$radius = $planet + $altitude;
		$groundDelta = $altitude * (2 * $planet + $altitude);
		$groundRoot = $radius * $radius * $mu * $mu - $groundDelta;

		if($mu < 0 && $groundRoot >= 0)
		return [$groundDelta / max(1e-12, -$radius * $mu + sqrt($groundRoot)),true];

		$height = $this->parameters['atmosphereHeight'];
		$delta = ($height - $altitude) * (2 * $planet + $height + $altitude);
		$root = sqrt(max(0.0, $radius * $radius * $mu * $mu + $delta));

		return [$mu >= 0 ? $delta / max(1e-12, $root + $radius * $mu) : $root - $radius * $mu,false];
	}

	/**
	 * Compute altitude along a ray while retaining precision close to sea level.
	 * @param float $altitude Starting altitude.
	 * @param float $mu Starting zenith cosine.
	 * @param float $distance Distance along the ray.
	 * @return float Nonnegative sample altitude.
	 */
	private function rayAltitude(float $altitude, float $mu, float $distance): float
	{
		$radius = $this->parameters['planetRadius'] + $altitude;
		$delta = $distance * $distance + 2 * $radius * $mu * $distance;

		return max(0.0, $altitude + $delta / (sqrt(max(0.0, $radius * $radius + $delta)) + $radius));
	}

	/**
	 * Validate finite sampling coordinates and restrict them to the LUT domain.
	 * @param float $altitude Requested height in meters.
	 * @param float $mu Requested zenith cosine.
	 * @return array Clamped altitude and cosine.
	 */
	private function coordinates(float $altitude, float $mu): array
	{
		if(!is_finite($altitude) || !is_finite($mu)) throw new \InvalidArgumentException('Invalid atmospheric ray');

		return [max(0.0, min($this->parameters['atmosphereHeight'], $altitude)),max(-1.0, min(1.0, $mu))];
	}

	/**
	 * Validate and normalize a direction vector.
	 * @param array $value Three finite vector components.
	 * @return array Unit-length direction.
	 */
	private static function direction(array $value): array
	{
		if(count($value) !== 3 || count(array_filter($value, 'is_finite')) !== 3)
		throw new \InvalidArgumentException('A direction requires three finite components');

		$length = sqrt($value[0] ** 2 + $value[1] ** 2 + $value[2] ** 2);

		if(!is_finite($length) || $length < 1e-12) throw new \InvalidArgumentException('A direction cannot be zero');

		return [$value[0] / $length,$value[1] / $length,$value[2] / $length];
	}
}
