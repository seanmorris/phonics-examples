<?php
declare(strict_types=1);
namespace TerrainPhonics;

use Phonics\Render\Program;

/** Optical properties for the surface and for opaque geometry seen through the water. */
final class WaterAppearance
{
	public const DEFAULTS = [
		'waterColor' => '#287276'
		, 'waterTransparency' => .85
		, 'waterReflectivity' => .85
		, 'waterFogDensity' => .035
		, 'waterFoam' => .65
	];
	public readonly array $settings;
	private readonly array $linearColor;

	/**
	 * Color is sRGB. Fog density is per meter. The other controls are fractions.
	 *
	 * @param array $options Water color and optical controls.
	 * @return void No value.
	 */
	public function __construct(array $options = [])
	{
		$settings = self::DEFAULTS;

		foreach($settings as $name => $default)
		{
			if(!array_key_exists($name, $options)) continue;

			$value = $options[$name];

			if($name === 'waterColor')
			{
				if(!is_string($value) || !preg_match('/^#[0-9a-f]{6}$/iD', $value))
				throw new \InvalidArgumentException('Water color must be a six-digit hex color.');

				$settings[$name] = strtolower($value);
				continue;
			}

			if(!is_numeric($value) || !is_finite((float)$value))
			throw new \InvalidArgumentException("Invalid water setting: $name");

			$settings[$name] = max(0.0, min(1.0, (float)$value));
		}

		$this->settings = $settings;
		$rgb = [];

		foreach(str_split(substr($settings['waterColor'], 1), 2) as $channel)
		{
			$c = hexdec($channel) / 255;
			$rgb[] = $c <= .04045 ? $c / 12.92 : (($c + .055) / 1.055) ** 2.4;
		}

		$this->linearColor = $rgb;
	}

	/**
	 * Shared fragment functions for water and submerged opaque materials.
	 *
	 * @return string GLSL functions for water optics.
	 */
	public static function shaderSource(): string
	{
		return file_get_contents(dirname(__DIR__) . '/shaders/water-optics.glsl');
	}

	/**
	 * Bind linear tint and optical coefficients to the active program.
	 *
	 * @param Program $program Active GPU program receiving the bindings.
	 * @return void No value.
	 */
	public function bind(Program $program): void
	{
		glUniform3f($program->location('uWaterTint'), ...$this->linearColor);
		$s = $this->settings;

		glUniform4f(
			$program->location('uWaterOptics')
			, $s['waterTransparency']
			, $s['waterReflectivity']
			, $s['waterFogDensity']
			, $s['waterFoam']
		);
	}
}
