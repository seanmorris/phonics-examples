<?php
declare(strict_types=1);
namespace TerrainPhonics;

/** Authored aerosol shells. Heights are meters above sea level, density is optical depth per kilometer. */
final class AtmosphereLayers
{
	public const LIMIT = 6;
	public const MAX_DENSITY = 10.;
	public const DEFAULTS = [
		'name' => 'Mist'
		, 'enabled' => true
		, 'bottom' => 0.
		, 'top' => 900.
		, 'density' => .18
		, 'color' => '#adc7d6'
	];
	public readonly array $rows, $bands, $colors, $uniforms;

	/**
	 * Validate the layer definitions and pack enabled density and color uniforms.
	 *
	 * @param array $rows Atmospheric band definitions in display order.
	 * @param float $ceiling Maximum atmosphere altitude in meters.
	 * @return void No value.
	 */
	public function __construct(array $rows = [], float $ceiling = 80000.)
	{
		if(!array_is_list($rows) || count($rows) > self::LIMIT)
		throw new \InvalidArgumentException('The atmosphere supports up to six layers.');

		$records = $bands = $colors = $uniforms = [];

		foreach($rows as $row)
		{
			if(!is_array($row) || array_diff_key($row, self::DEFAULTS))
			throw new \InvalidArgumentException('Unknown atmosphere layer property.');

			$row += self::DEFAULTS;

			if(!is_string($row['name']) || trim($row['name']) === '' || strlen($row['name']) > 120
				|| !is_bool($row['enabled']) || !is_string($row['color']) || !preg_match('/^#[a-f0-9]{6}$/iD', $row[
					'color'
				])
			)
			throw new \InvalidArgumentException('An atmosphere layer needs a name, a color and a visibility setting.');

			foreach(['bottom','top','density'] as $key)
			{
				if(!is_numeric($row[$key]) || !is_finite((float)$row[$key]))
				throw new \InvalidArgumentException('Layer heights and density must be finite numbers.');

				$row[$key] = (float)$row[$key];
			}

			if($row['bottom'] < -$ceiling || $row['top'] > $ceiling || $row['bottom'] >= $row['top']
				|| $row['density'] < 0 || $row['density'] > self::MAX_DENSITY
			)
			throw new \InvalidArgumentException(
				'Layer top must exceed its base inside the atmosphere, with density from 0 to 10 per km.'
			);

			$row['color'] = strtolower($row['color']);
			$row['name'] = trim($row['name']);
			$records[] = $row;

			if(!$row['enabled'] || $row['density'] == 0) continue;

			$i = count($bands);
			$bands[] = $uniforms["airBand[$i]"] = [$row['bottom'], $row['top'], $row['density'] / 1000];
			$colors[] = $uniforms["airColor[$i]"] = TerrainAppearance::linear($row['color']);
		}

		$this->rows = $records;
		$this->bands = $bands;
		$this->colors = $colors;
		$this->uniforms = ['airLayerCount' => count($bands)] + $uniforms;
	}

	/**
	 * Positive ray interval inside a sphere. Factored roots retain meter precision near the surface.
	 *
	 * @param float $planet Planet radius in meters.
	 * @param float $eye Observer altitude above the planet's surface in meters.
	 * @param float $projection Signed projection of the eye radius onto the viewing ray.
	 * @param float $height Render target height in pixels.
	 * @return array Entry and exit distances along the forward ray.
	 */
	private static function sphere(float $planet, float $eye, float $projection, float $height): array
	{
		$delta = ($height - $eye) * (2 * $planet + $height + $eye);
		$discriminant = $projection * $projection + $delta;

		if($discriminant <= 0) return [0.,0.];

		$root = sqrt($discriminant);
		$q = abs($projection) + $root;

		return $projection >= 0 ? [0., max(0., $delta / $q)] : [max(0., -$delta / $q), $q];
	}

	/**
	 * Outer and inner sphere intervals. A grazing ray can traverse the same shell twice.
	 *
	 * @param float $planet Planet radius in meters.
	 * @param float $eye Observer altitude above the planet's surface in meters.
	 * @param float $mu Cosine of the angle between the ray and the local vertical.
	 * @return array Shell intersections and coefficients for each active density profile.
	 */
	public function spans(float $planet, float $eye, float $mu): array
	{
		$projection = ($planet + $eye) * $mu;
		$result = [];

		foreach($this->bands as [$bottom, $top])
		{
			$scale = 1 / (($top - $bottom) * (2 * $planet + $top + $bottom));
			// Normalized squared radius along the ray is quadratic in distance.
			$profile = [($eye - $bottom) * (2 * $planet + $eye + $bottom) * $scale, $projection, $scale];
			$result[] = [
				...self::sphere($planet, $eye, $projection, $top)
				, ...self::sphere($planet, $eye, $projection, $bottom)
				, 'profile' => $profile
			];
		}

		return $result;
	}

	/**
	 * Integrated density over a segment entirely in the layer. The parabolic profile 6h(1-h) fades to zero at each
	 * boundary and keeps the authored mean density through the column.
	 *
	 * @param array $profile Quadratic density-profile coefficients for the intersected layer.
	 * @param float $start Near end of the ray interval.
	 * @param float $end Far end of the interval.
	 * @return float Integrated column density along the ray segment.
	 */
	public static function column(array $profile, float $start, float $end): float
	{
		if($end <= $start) return 0.;

		[$origin,$projection,$scale] = $profile;
		$mid = .5 * ($start + $end);
		$half = .5 * ($end - $start);
		$b = 2 * $half * ($projection + $mid) * $scale;
		$c = $half * $half * $scale;
		$mean = $origin + $mid * (2 * $projection + $mid) * $scale + $c / 3;

		// E[h(1-h)] = E[h](1-E[h]) - Var[h] for a uniform distance along the segment.
		return ($end - $start) * 6 * max(0., $mean * (1 - $mean) - $b * $b / 3 - 4 * $c * $c / 45);
	}

	/**
	 * Accumulate optical depth over the parts of the viewing ray inside active layers.
	 *
	 * @param array $spans Intersections with active atmospheric density layers.
	 * @param float $distance Length along the ray in meters.
	 * @return float Integrated extinction up to the specified distance.
	 */
	public function opticalDepth(array $spans, float $distance): float
	{
		$depth = 0.;

		foreach($spans as $i => $span)
		{
			[$enter,$exit,$innerEnter,$innerExit] = $span;
			$exit = min($distance, $exit);
			$length = self::column($span['profile'], $enter, min($exit, $innerEnter))
				+ self::column($span['profile'], max($enter, $innerExit), $exit);

			$depth += $length * $this->bands[$i][2];
		}

		return $depth;
	}
}
