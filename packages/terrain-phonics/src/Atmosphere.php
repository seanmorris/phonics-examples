<?php
declare(strict_types=1);
namespace TerrainPhonics;

/**
 * Spherical exponential Rayleigh/Mie single scattering in meters and linear RGB.
 *
 * Optical depth follows Beer-Lambert extinction. The model includes planet shadow, a finite solar disk, and ozone
 * absorption along sunlight paths; multiple scattering, clouds, and ground-reflected light are deliberately outside it.
 */
final class Atmosphere
{
	public const DEFAULT_NIGHT_GLOW = 0.5;
	/** Molecular scattering per meter for red, green and blue: Earth's sea-level Rayleigh coefficients. */
	public const DEFAULT_RAYLEIGH = [5.802e-6,13.558e-6,33.1e-6];
	/** How the zenith displays with DEFAULT_RAYLEIGH, the sun 35 degrees up and an exposure of 1. */
	public const DEFAULT_SKY_COLOR = '#4b698a';
	/** Aerosol haze scatters every channel equally by default. */
	public const DEFAULT_HAZE_COLOR = '#ffffff';
	/**
	 * Ozone absorption per meter at the peak of its layer, which rises linearly from 10 km to 25 km and falls to
	 * zero at 40 km (Bruneton and Hillaire's Earth values). It absorbs green and red, so long sunlight paths at
	 * sunrise and sunset turn orange instead of yellow-green.
	 */
	public const DEFAULT_OZONE = [0.650e-6,1.881e-6,0.085e-6];
	/** Scale of the sky's diffuse light on terrain; 1 is the single-scattering model's own irradiance. */
	public const DEFAULT_SKY_LIGHT = 1.0;

	/** Physical and display parameters, separate from the cached optical-depth integration. */
	private array $parameters;
	private AtmosphereLayers $air;
	/** Most recently requested transmittance texture; bounded to one resolution per instance. */
	private ?array $lut = null;
	/** Signature and generation of the last numerical texture. */
	private string $lutKey = '';
	private int $lutGeneration = 0;
	private string $skyColor = self::DEFAULT_SKY_COLOR;
	private string $hazeColor = self::DEFAULT_HAZE_COLOR;
	/** Sky irradiance on level ground over pi, by whole degree of sun elevation, for the current atmosphere. */
	private array $skyFill = [];

	/**
	 * Configure Earth-like scattering, with optional overrides in meters and inverse meters.
	 * @param array $options Keys match uniforms(); sunIntensity is irradiance in the model's linear units.
	 * @return void Validates all parameters and normalizes the direction from the viewer toward the sun.
	 */
	public function __construct(array $options = [])
	{
		$layers = $options['layers'] ?? [];
		unset($options['layers']);
		$this->parameters = array_replace([
			'planetRadius' => 6371000.0
			, 'atmosphereHeight' => 80000.0
			, 'rayleighHeight' => 8000.0
			, 'mieHeight' => 1200.0
			, 'rayleigh' => self::DEFAULT_RAYLEIGH
			, 'mie' => 3.996e-6
			, 'mieAlbedo' => .9
			, 'mieG' => .76
			, 'haze' => 1.0
			, 'sunDirection' => [.3,.8,.2]
			, 'sunIntensity' => 20.0
			, 'angularRadius' => .00935
			, 'exposure' => 1.0
			, 'nightGlow' => self::DEFAULT_NIGHT_GLOW
			, 'hazeColor' => [1.0,1.0,1.0]
			, 'skyLight' => self::DEFAULT_SKY_LIGHT
			, 'ozone' => self::DEFAULT_OZONE
		], $options
		);

		foreach(['planetRadius','atmosphereHeight','rayleighHeight','mieHeight','angularRadius','mieAlbedo'] as $key)
		{
			$value = $this->parameters[$key];

			if(!is_numeric($value) || !is_finite((float)$value) || $value <= 0)
			throw new \InvalidArgumentException('Atmosphere requires a positive ' . $key);
		}

		foreach(['mie','haze','sunIntensity','exposure','nightGlow','skyLight'] as $key)
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

		if(count($this->parameters['ozone']) !== 3)
		throw new \InvalidArgumentException('Ozone absorption requires three channels');

		foreach($this->parameters['ozone'] as $value)
		if(!is_numeric($value) || !is_finite((float)$value) || $value < 0)
		throw new \InvalidArgumentException('Invalid ozone absorption');

		if(count($this->parameters['hazeColor']) !== 3)
		throw new \InvalidArgumentException('Haze color requires three channels');

		foreach($this->parameters['hazeColor'] as $value)
		if(!is_numeric($value) || !is_finite((float)$value) || $value < 0 || $value > 1)
		throw new \InvalidArgumentException('Haze color channels must be between zero and one');

		$this->parameters['sunDirection'] = self::direction($this->parameters['sunDirection']);
		$this->setLayers($layers);
	}

	/** Replace the authored aerosol shells together. Only a change to extinction invalidates sunlight. */
	public function setLayers(array $rows): void
	{
		$next = new AtmosphereLayers($rows, $this->parameters['atmosphereHeight']);
		if(!isset($this->air) || $this->air->bands !== $next->bands) $this->lut = null;

		if(!isset($this->air) || $this->air->bands !== $next->bands || $this->air->colors !== $next->colors)
		$this->skyFill = [];

		$this->air = $next;
	}

	public function layers(): array { return $this->air->rows; }

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

		if($intensity !== null && $intensity != $this->parameters['sunIntensity'])
		{
			$this->parameters['sunIntensity'] = $intensity;
			$this->skyFill = [];
		}
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
			$this->skyFill = [];
		}
	}

	/**
	 * Color the sky through the molecular scattering law fitted to a chosen zenith color.
	 * @param string $hex Six-digit hex color the zenith should display with the sun 35 degrees up at exposure 1.
	 * @return void Rebuilds sunlight and sky data only when the coefficients change.
	 * The tone map is inverted, each channel's radiance is compared with the default sky's, and a density and a
	 * wavelength exponent are fitted to those ratios in log space, as for Earth's air (exponent 4). The exponent stays
	 * between 3 and 5: freer channels turn sunsets yellow-green, since red and green then cross a long path alike
	 * while green scatters far more. A deeper blue raises the exponent and thins the air, so distant land stays
	 * clearer; a paler blue lowers the exponent or thickens it.
	 */
	public function setSkyColor(string $hex): void
	{
		$target = self::displayedRadiance($hex);
		$reference = self::displayedRadiance(self::DEFAULT_SKY_COLOR);
		$beta = self::DEFAULT_RAYLEIGH;
		// Spectral positions that give the default coefficients a fourth-power law around green.
		$x = [log($beta[0] / $beta[1]) / 4, 0.0, log($beta[2] / $beta[1]) / 4];
		$y = [];

		foreach($target as $c => $radiance) $y[] = log(max(1e-6, $radiance) / $reference[$c]);
		$mx = array_sum($x) / 3;
		$my = array_sum($y) / 3;
		$covariance = $variance = 0.0;

		for($c = 0; $c < 3; $c++)
		{
			$covariance += ($x[$c] - $mx) * ($y[$c] - $my);
			$variance += ($x[$c] - $mx) ** 2;
		}

		$slope = max(-1.0, min(1.0, $covariance / $variance));
		$density = exp($my - $slope * $mx);
		$rayleigh = [];

		foreach($beta as $c => $value) $rayleigh[] = $value * $density * exp($slope * $x[$c]);

		if(strtolower($hex) === self::DEFAULT_SKY_COLOR) $rayleigh = self::DEFAULT_RAYLEIGH;

		$this->skyColor = strtolower($hex);

		if($rayleigh === $this->parameters['rayleigh']) return;

		$this->parameters['rayleigh'] = $rayleigh;
		$this->lut = null;
		$this->skyFill = [];
	}

	/**
	 * Read the sky color last set.
	 * @return string Lower-case six-digit hex color; DEFAULT_SKY_COLOR until setSkyColor() is called.
	 */
	public function skyColor(): string { return $this->skyColor; }

	/**
	 * Tint the light aerosol haze scatters, leaving its extinction gray.
	 * @param string $hex Six-digit hex color. Only its hue counts: the brightest channel scatters fully, so white
	 * and every gray keep the haze neutral, and the Haze setting alone sets its strength.
	 * @return void Sunlight and the transmittance texture are unchanged; the sky and aerial light follow.
	 * The other channels scatter their fraction and the haze absorbs the rest, so a warm color reads as dust.
	 */
	public function setHazeColor(string $hex): void
	{
		$linear = self::linearColor($hex);
		$peak = max($linear);
		$tint = [0.0,0.0,0.0];

		if($peak > 0)
		foreach($linear as $c => $value) $tint[$c] = $value / $peak;

		$this->hazeColor = strtolower($hex);

		if($tint === $this->parameters['hazeColor']) return;

		$this->parameters['hazeColor'] = $tint;
		$this->skyFill = [];
	}

	/**
	 * Read the haze color last set.
	 * @return string Lower-case six-digit hex color; DEFAULT_HAZE_COLOR until setHazeColor() is called.
	 */
	public function hazeColor(): string { return $this->hazeColor; }

	/**
	 * Scale the sky's diffuse light on terrain.
	 * @param float $strength Nonnegative multiple of the modeled sky irradiance.
	 * @return void Updates the fill without integrating the sky again.
	 */
	public function setSkyLight(float $strength): void
	{
		if(!is_finite($strength) || $strength < 0) throw new \InvalidArgumentException('Sky light must be nonnegative');

		$this->parameters['skyLight'] = $strength;
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
		$fill = $this->skyIrradiance($this->parameters['sunDirection'][1]);

		foreach($fill as $c => $value) $fill[$c] = $value * $this->parameters['skyLight'];

		return [
			'nightVisibility' => $this->nightVisibility()
			, 'nightAmbient' => $this->nightAmbient()
			, 'skyAmbient' => $fill
		] + $this->parameters + $this->air->uniforms + [
			'transmittanceSize' => [$this->lut['width'] ?? 0,$this->lut['height'] ?? 0]
			, 'lutGeneration' => $this->lutGeneration
		];
	}

	/**
	 * Smooth dusk from the horizon to ten degrees below. Star visibility is independent of the fill dimmer.
	 * @return float Zero while the sun is up, rising to one with the sun ten degrees below the horizon.
	 */
	private function nightVisibility(): float
	{
		$t = min(1.0, max(0.0, -$this->parameters['sunDirection'][1] / 0.17364818));

		return $t * $t * (3 - 2 * $t);
	}

	/**
	 * Blue night fill, scaled by the night glow along the dusk curve.
	 * @return array Linear RGB in scene units.
	 */
	private function nightAmbient(): array
	{
		$strength = $this->nightVisibility() * $this->parameters['nightGlow'];

		return [.075 * $strength, .15 * $strength, .60 * $strength];
	}

	/**
	 * Diffuse sky light on level ground at sea level, over pi so it multiplies albedo like the shaders' sunlight.
	 * @param float $sine Sine of the sun's elevation.
	 * @return array Linear RGB, before the sky-light scale. Only single scattering is modeled.
	 * The sky is symmetric about the sun's vertical plane and level ground ignores azimuth, so whole degrees of
	 * elevation are integrated once per atmosphere and interpolated between.
	 */
	public function skyIrradiance(float $sine): array
	{
		$degrees = rad2deg(asin(max(-1.0, min(1.0, $sine))));
		$low = (int)floor($degrees);
		$t = $degrees - $low;
		$a = $this->skyFillAt($low);

		if($t <= 0) return $a;

		$b = $this->skyFillAt($low + 1);

		return [$a[0] + ($b[0] - $a[0]) * $t, $a[1] + ($b[1] - $a[1]) * $t, $a[2] + ($b[2] - $a[2]) * $t];
	}

	/**
	 * Integrate and cache the sky's light on level ground for one whole degree of sun elevation.
	 * @param int $degrees Sun elevation in whole degrees; values past the zenith or nadir are clamped.
	 * @return array Linear RGB over pi, before the sky-light scale.
	 */
	private function skyFillAt(int $degrees): array
	{
		if(isset($this->skyFill[$degrees])) return $this->skyFill[$degrees];

		$sun = $this->parameters['sunDirection'];
		$elevation = deg2rad(max(-90, min(90, $degrees)));
		$this->parameters['sunDirection'] = [cos($elevation), sin($elevation), 0.0];
		$sum = [0.0,0.0,0.0];

		try
		{
			// Three 30-degree rings and six azimuths; mirrored azimuths share one evaluation.
			foreach([15, 45, 75] as $ring)
			{
				$zenith = deg2rad(90 - $ring);
				$weight = cos($zenith) * sin($zenith) * deg2rad(30) * M_PI / 3;

				foreach([0 => 1, 60 => 2, 120 => 2, 180 => 1] as $azimuth => $count)
				{
					$phi = deg2rad($azimuth);

					$radiance = $this->radiance(
						[sin($zenith) * cos($phi), cos($zenith), sin($zenith) * sin($phi)]
						, 0.0
						, 12
						, false
					);

					for($c = 0; $c < 3; $c++) $sum[$c] += $radiance[$c] * $weight * $count;
				}
			}
		}
		finally
		{
			$this->parameters['sunDirection'] = $sun;
		}

		return $this->skyFill[$degrees] = [$sum[0] / M_PI, $sum[1] / M_PI, $sum[2] / M_PI];
	}

	/**
	 * How much brighter sunlit level ground is than shaded level ground, for eyes that adapt to a shadow by day.
	 * @return float The sun's and sky's light on level ground over the sky's alone, in green, at sea level; one once
	 * the sun has set. About 11 with the sun 35 degrees up, falling to one as it sets, where the night gain takes over.
	 */
	public function shadeAdaptation(): float
	{
		$sine = $this->parameters['sunDirection'][1];

		if($sine <= 0) return 1.0;

		$sky = $this->skyIrradiance($sine)[1] * $this->parameters['skyLight'];
		$sun = $this->parameters['sunIntensity'] * $this->transmittance(0.0, $sine, 32)[1] * $sine / M_PI;

		return $sky > 0 ? max(1.0, min(SpotLights::NIGHT_GAIN, ($sun + $sky) / $sky)) : 1.0;
	}

	/**
	 * Convert a six-digit hex color to linear channels.
	 * @param string $hex Color with a leading #.
	 * @return array Red, green and blue from 0 to 1.
	 */
	private static function linearColor(string $hex): array
	{
		if(!preg_match('/^#[0-9a-fA-F]{6}$/', $hex))
		throw new \InvalidArgumentException('Colors must be six-digit hex');

		$rgb = [];

		foreach(str_split(substr($hex, 1), 2) as $channel)
		{
			$c = hexdec($channel) / 255;
			$rgb[] = $c <= .04045 ? $c / 12.92 : (($c + .055) / 1.055) ** 2.4;
		}

		return $rgb;
	}

	/**
	 * Find the radiance that displays as a color at exposure 1, inverting toneMap().
	 * @param string $hex Six-digit hex color; channels above 99% count as 99%.
	 * @return array Linear RGB radiance.
	 */
	private static function displayedRadiance(string $hex): array
	{
		$radiance = [];

		foreach(self::linearColor($hex) as $c) $radiance[] = -log(1 - min(.99, $c));

		return $radiance;
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
		$airTransmission = 1.;
		return $this->traceTransmission($altitude,$cosZenith,$samples,$airTransmission);
	}

	private function traceTransmission(float $altitude, float $cosZenith, int $samples, float &$airTransmission,
		bool $layers = true): array
	{
		[$altitude,$cosZenith] = $this->coordinates($altitude, $cosZenith);
		$samples = max(8, min(256, $samples));
		[$distance,$ground] = $this->boundary($altitude, $cosZenith);

		$airDepth = 0.;
		if($this->air->bands) {
			$spans = $this->air->spans($this->parameters['planetRadius'],$altitude,$cosZenith);
			$airDepth = $this->air->opticalDepth($spans,$distance);
		}
		$airTransmission = exp(-$airDepth);
		if($ground) return [0.0,0.0,0.0];

		$dr = $dm = $do = 0.0;
		$step = $distance / $samples;

		for($i = 0; $i < $samples; $i++)
		{
			$h = $this->rayAltitude($altitude, $cosZenith, ($i + .5) * $step);
			$dr += exp(-$h / $this->parameters['rayleighHeight']) * $step;
			$dm += exp(-$h / $this->parameters['mieHeight']) * $step;
			$do += max(0.0, 1 - abs($h - 25000) / 15000) * $step;
		}

		// Ozone lies far above any view of the terrain, so only sunlight paths cross it.
		$light = $this->attenuation($dr, $dm, $layers ? $airDepth : 0.);

		foreach($this->parameters['ozone'] as $c => $absorption) $light[$c] *= exp(-$absorption * $do);

		return $light;
	}

	/**
	 * Build or reuse a GPU-ready lookup texture, with more samples near sea level and the horizon.
	 * @param int $width Zenith samples, between four and 512.
	 * @param int $height Altitude samples, between two and 256.
	 * @param int $samples Optical-depth samples per texel, between eight and 256.
	 * @return array Width/height, RGB float data, RGBA32F little-endian bytes, RGBA8 bytes, and generation.
	 * RGB is clear air only. The shaders add the authored layers exactly (terrainAirDepth in atmosphere.glsl): the
	 * texture's 16 or so altitude rows sit hundreds of meters apart near the ground, too coarse for sub-kilometer layers.
	 * Alpha still carries transmission through the authored layers.
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
			$airTransmission = 1.;
			$rgb = $this->traceTransmission($altitude, $u * abs($u), $samples, $airTransmission, false);
			array_push($data, ...$rgb);
			$bytes .= pack('g4', ...[...$rgb,$airTransmission]);

			foreach($rgb as $channel) $rgba8 .= chr((int)round(255 * $channel));

			$rgba8 .= chr((int)round(255 * $airTransmission));
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
		return $this->radiance($direction, $altitude, $samples, true);
	}

	/**
	 * Integrate single scattering along a view ray.
	 * @param array $direction Viewing direction as [x,y,z].
	 * @param float $altitude Viewer height above sea level, in meters.
	 * @param int $samples View-ray integration samples, clamped between eight and 128.
	 * @param bool $extras Whether to add the attenuated solar disk and the night glow.
	 * @return array Linear HDR RGB.
	 */
	private function radiance(array $direction, float $altitude, int $samples, bool $extras): array
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

		// Split the molecular quadrature at every shell crossing. Even a thin layer gets its own segment.
		$spans = $this->air->spans($this->parameters['planetRadius'],$altitude,$mu);
		$ends = [];
		for($i = 1; $i <= $samples; ++$i) $ends[] = $i * $step;
		foreach($spans as $span)
			foreach($span as $crossing) if($crossing > 0 && $crossing < $distance) $ends[] = $crossing;
		if($spans) sort($ends,SORT_NUMERIC);
		$start = $airDepth = 0.;
		foreach($ends as $end) {
			$step = $end - $start;
			if($step <= 0) continue;
			$d = $start + .5 * $step;
			$start = $end;
			$depth = 0.;
			$scatter = [0.,0.,0.];
			foreach($spans as $j => [$enter,$exit,$innerEnter,$innerExit]) {
				if($d < $enter || $d >= $exit || ($d >= $innerEnter && $d < $innerExit)) continue;
				$tau = $this->air->bands[$j][2] * $step;
				$depth += $tau;
				foreach($this->air->colors[$j] as $c=>$tint) $scatter[$c] += $tau * $tint;
			}
			$h = $this->rayAltitude($altitude, $mu, $d);
			$r = exp(-$h / $this->parameters['rayleighHeight']) * $step;
			$m = exp(-$h / $this->parameters['mieHeight']) * $step;
			$sunMu = ($d * $ray[0] * $sun[0] + ($radius + $d * $ray[1]) * $sun[1]
				+ $d * $ray[2] * $sun[2]
			) / ($this->parameters['planetRadius'] + $h);

			$light = $this->transmittance($h, $sunMu, 32);
			$view = $this->attenuation($dr + .5 * $r, $dm + .5 * $m, $airDepth);
			// Average Beer-Lambert transmission within this constant-density segment.
			$weight = $depth > .001 ? (1 - exp(-$depth)) / $depth : 1 - .5 * $depth;

			for($channel = 0; $channel < 3; $channel++)
			$rgb[$channel] += $view[$channel] * $light[$channel] * $this->parameters['sunIntensity']
				* $weight * ($r * $this->parameters['rayleigh'][$channel] * $phaseR
					+ $m * $this->parameters['mie'] * $this->parameters['haze'] * $this->parameters['hazeColor'][
						$channel
					] * $phaseM
					+ $scatter[$channel] / (4 * M_PI)
				);

			$airDepth += $depth;
			$dr += $r;
			$dm += $m;
		}

		if(!$extras) return $rgb;

		if(!$ground && $nu >= cos($this->parameters['angularRadius']))
		{
			$disk = $this->parameters['sunIntensity'] / (2 * M_PI * (1 - cos($this->parameters['angularRadius'])));
			$view = $this->attenuation($dr, $dm, $airDepth);

			for($channel = 0; $channel < 3; $channel++) $rgb[$channel] += $view[$channel] * $disk;
		}

		$gradient = max(0.0, min(1.0, $ray[1]));
		$glow = exp(-$airDepth) * (.27 - .17 * $gradient * (2 - $gradient));

		foreach($this->nightAmbient() as $channel => $fill) $rgb[$channel] += $fill * $glow;

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
	private function attenuation(float $rayleigh, float $mie, float $airDepth = 0.): array
	{
		$extinction = $this->parameters['mie'] * $this->parameters['haze'] / $this->parameters['mieAlbedo'];

		$result = [];

		foreach($this->parameters['rayleigh'] as $beta) $result[] = exp(-$beta * $rayleigh - $extinction * $mie - $airDepth);

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
