<?php
declare(strict_types=1);
namespace TerrainPhonics;

use Phonics\Render\Program;

/**
 * Up to two spot lights, such as a vehicle's headlights, for the ground and water shaders.
 *
 * Light falls off with the square of distance and with the cosine of incidence. It fades to nothing at the light's
 * range and across the band between its inner and outer cone. No surface casts a shadow, so a lamp also lights the
 * far side of a hill within its range.
 */
final class SpotLights
{
	/** Lights one scene holds. TERRAIN_MAX_SPOT_LIGHTS in spot-lights.glsl matches it. */
	public const MAX = 2;
	/** Scene irradiance units in one lux. Atmosphere's default sunIntensity, 20, is sunlight above the atmosphere. */
	public const LUX = 20 / 128000;
	/**
	 * Gain on lamp light at full night, reached along the dusk curve of Atmosphere's night fill.
	 *
	 * Sunlight above the atmosphere is about 128,000 lux. The default night fill lights level ground as about 4,200
	 * lux of that sunlight would, standing for moonlit ground seen by eyes adapted to the dark. Under this gain the
	 * fill matches about 0.4 lux; a full moon on a clear night gives 0.05 to 0.3 lux. Lamps seen by the same eyes get
	 * the same gain. By day the gain is one.
	 */
	public const NIGHT_GAIN = 1e4;
	/** Keys every light needs, as keys. */
	private const KEYS = [
		'position' => 0
		, 'direction' => 0
		, 'color' => 0
		, 'intensity' => 0
		, 'range' => 0
		, 'inner' => 0
		, 'outer' => 0
	];
	/** Twelve floats for each light, in the layout of uSpotLights, with colour in scene units before the gain. */
	private array $values = [];
	/** The floats each program last received, by the spl_object_id() of its Program. */
	private array $sent = [];

	/**
	 * Replace the lights. On an invalid list the previous lights stay.
	 * @param array $lights List of at most MAX lights, each with exactly these keys: position [x,y,z] in world
	 * metres; direction [x,y,z] of the beam's axis, any nonzero length; color [r,g,b], linear and nonnegative,
	 * scaling the intensity per channel; intensity in candela along the axis; range in metres, at least 0.01,
	 * where the light fades out; inner and outer, half-angles of the cone in radians, with 0 <= inner <= outer
	 * and 0 < outer <= pi/2. The light is full inside inner and absent outside outer.
	 * @return void Stores the packed lights. An empty list clears them.
	 */
	public function set(array $lights): void
	{
		if(!array_is_list($lights) || count($lights) > self::MAX)
		throw new \InvalidArgumentException('Spot lights must be a list of at most ' . self::MAX . ' lights');

		$values = [];

		foreach($lights as $index => $light) $values = [...$values, ...self::pack($light, $index)];

		$this->values = $values;
	}

	/**
	 * Count the lights set.
	 * @return int Lights currently set, from zero to MAX.
	 */
	public function count(): int { return intdiv(count($this->values), 12); }

	/**
	 * Read the uniform values bind() uploads, for a custom shader that includes shaderSource().
	 * @param float $night Atmosphere::uniforms()['nightVisibility'], from zero by day to one at full night.
	 * @return array uSpotLightCount, and uSpotLights[0] with twelve floats per light: world position and the inverse
	 * square of the range; unit axis and cone scale; irradiance on the axis at one metre in scene units, including
	 * the night gain, and cone offset. The cone factor is the square of clamp(cos θ * scale + offset, 0, 1).
	 */
	public function uniforms(float $night): array
	{
		$gain = 1 + (self::NIGHT_GAIN - 1) * max(0.0, min(1.0, $night));
		$values = $this->values;

		for($i = 8; $i < count($values); $i += 12)
		for($channel = $i; $channel < $i + 3; $channel++) $values[$channel] *= $gain;

		return ['uSpotLightCount' => $this->count(), 'uSpotLights[0]' => $values];
	}

	/**
	 * Upload the lights to the program in use, when they differ from what that program last received.
	 * @param Program $program Program in use that includes shaderSource().
	 * @param float $night Atmosphere::uniforms()['nightVisibility'].
	 * @return void Sends nothing to a program that holds these values, or that never received a light.
	 */
	public function bind(Program $program, float $night): void
	{
		$values = $this->uniforms($night)['uSpotLights[0]'];
		$key = spl_object_id($program);
		$sent = $this->sent[$key] ?? [];

		if($values === $sent) return;

		// A linked program starts with a count of zero, and the count changes less often than the lights move.
		if(count($values) !== count($sent))
		glUniform1i($program->location('uSpotLightCount'), intdiv(count($values), 12));

		if($values) glUniform4fv($program->location('uSpotLights[0]'), intdiv(count($values), 4), $values);

		$this->sent[$key] = $values;
	}

	/**
	 * Forget what each program received, after context restoration relinks the programs.
	 * @return void The next bind() uploads to each program again.
	 */
	public function forget(): void { $this->sent = []; }

	/**
	 * Read the GLSL that the ground and water shaders include at their "// SPOT LIGHTS" marker.
	 * @return string Source declaring the uniforms, terrainSpotLight() and terrainSpotWaterColumn().
	 */
	public static function shaderSource(): string
	{
		return file_get_contents(dirname(__DIR__) . '/shaders/spot-lights.glsl');
	}

	/**
	 * Validate one light and pack it in the layout of uSpotLights. Applications call this on every frame, so error
	 * messages are built only on failure.
	 * @param mixed $light Light from set().
	 * @param int $index Position of the light in the list, for error messages.
	 * @return array Twelve floats.
	 */
	private static function pack(mixed $light, int $index): array
	{
		if(!is_array($light)) throw new \InvalidArgumentException("Spot light $index must be an array");

		if(count($light) !== count(self::KEYS) || array_diff_key(self::KEYS, $light))
		{
			foreach(array_diff_key(self::KEYS, $light) as $key => $unused)
			throw new \InvalidArgumentException("Spot light $index has no $key");

			throw new \InvalidArgumentException(
				"Spot light $index has an unknown key: " . implode(', ', array_keys(array_diff_key($light, self::KEYS)))
			);
		}

		[$px, $py, $pz] = self::vector($light['position'], $index, 'position');
		[$dx, $dy, $dz] = self::vector($light['direction'], $index, 'direction');
		[$red, $green, $blue] = self::vector($light['color'], $index, 'color');
		$intensity = self::number($light['intensity'], $index, 'intensity');
		$range = self::number($light['range'], $index, 'range');
		$inner = self::number($light['inner'], $index, 'inner');
		$outer = self::number($light['outer'], $index, 'outer');
		$length = sqrt($dx * $dx + $dy * $dy + $dz * $dz);

		if(!($length > 1e-9)) throw new \InvalidArgumentException("Spot light $index direction must not be zero");

		if(min($red, $green, $blue, $intensity) < 0)
		throw new \InvalidArgumentException("Spot light $index color and intensity must be nonnegative");

		if($range < .01) throw new \InvalidArgumentException("Spot light $index range must be at least 0.01 metres");

		if(!($outer > 0 && $outer <= M_PI / 2 && $inner >= 0 && $inner <= $outer))
		throw new \InvalidArgumentException("Spot light $index needs 0 <= inner <= outer and 0 < outer <= pi/2");

		// The cone fades as (cos θ - cos outer) / (cos inner - cos outer); a hard edge keeps a finite slope.
		$scale = 1 / max(cos($inner) - cos($outer), 1e-4);
		$irradiance = $intensity * self::LUX;

		return [
			$px, $py, $pz, 1 / ($range * $range)
			, $dx / $length, $dy / $length, $dz / $length, $scale
			, $red * $irradiance
			, $green * $irradiance
			, $blue * $irradiance
			, -cos($outer) * $scale
		];
	}

	/**
	 * Read three finite numbers.
	 * @param mixed $value Candidate list.
	 * @param int $index Position of the light, for the error message.
	 * @param string $key Key of the value, for the error message.
	 * @return array Three floats.
	 */
	private static function vector(mixed $value, int $index, string $key): array
	{
		if(!is_array($value) || count($value) !== 3 || !array_is_list($value))
		throw new \InvalidArgumentException("Spot light $index $key must be a list of three numbers");

		foreach($value as $c)
		if(is_float($c) ? !is_finite($c) : !is_int($c))
		throw new \InvalidArgumentException("Spot light $index $key must hold finite numbers");

		return [(float)$value[0], (float)$value[1], (float)$value[2]];
	}

	/**
	 * Read one finite integer or float; numeric strings are rejected.
	 * @param mixed $value Candidate number.
	 * @param int $index Position of the light, for the error message.
	 * @param string $key Key of the value, for the error message.
	 * @return float The number.
	 */
	private static function number(mixed $value, int $index, string $key): float
	{
		if(is_float($value) ? !is_finite($value) : !is_int($value))
		throw new \InvalidArgumentException("Spot light $index $key must be a finite number");

		return (float)$value;
	}
}
