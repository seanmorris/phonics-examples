<?php
declare(strict_types=1);
namespace TerrainPhonics;

/** Authored aerosol shells. Heights are meters above sea level, density is optical depth per kilometer. */
final class AtmosphereLayers
{
	public const LIMIT = 6;
	public const MAX_DENSITY = 10.;
	public const DEFAULTS = ['name'=>'Mist', 'enabled'=>true, 'bottom'=>0., 'top'=>900., 'density'=>.18, 'color'=>'#adc7d6'];
	public readonly array $rows, $bands, $colors, $uniforms;

	public function __construct(array $rows = [], float $ceiling = 80000.)
	{
		if(!array_is_list($rows) || count($rows) > self::LIMIT)
			throw new \InvalidArgumentException('The atmosphere supports up to six layers.');
		$records = $bands = $colors = $uniforms = [];
		foreach($rows as $row) {
			if(!is_array($row) || array_diff_key($row, self::DEFAULTS))
				throw new \InvalidArgumentException('Unknown atmosphere layer property.');
			$row += self::DEFAULTS;
			if(!is_string($row['name']) || trim($row['name']) === '' || strlen($row['name']) > 120
				|| !is_bool($row['enabled']) || !is_string($row['color']) || !preg_match('/^#[a-f0-9]{6}$/iD', $row['color']))
				throw new \InvalidArgumentException('An atmosphere layer needs a name, a color and a visibility setting.');
			foreach(['bottom','top','density'] as $key) {
				if(!is_numeric($row[$key]) || !is_finite((float)$row[$key]))
					throw new \InvalidArgumentException('Layer heights and density must be finite numbers.');
				$row[$key] = (float)$row[$key];
			}
			if($row['bottom'] < 0 || $row['top'] > $ceiling || $row['bottom'] >= $row['top']
				|| $row['density'] < 0 || $row['density'] > self::MAX_DENSITY)
				throw new \InvalidArgumentException('Layer top must exceed its base inside the atmosphere, with density from 0 to 10 per km.');
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
		$this->uniforms = ['airLayerCount'=>count($bands)] + $uniforms;
	}

	/** Positive ray interval inside a sphere. Factored roots retain meter precision near the surface. */
	private static function sphere(float $planet, float $eye, float $projection, float $height): array
	{
		$delta = ($height - $eye) * (2 * $planet + $height + $eye);
		$discriminant = $projection * $projection + $delta;
		if($discriminant <= 0) return [0.,0.];
		$root = sqrt($discriminant);
		$q = abs($projection) + $root;
		return $projection >= 0 ? [0., max(0., $delta / $q)]
			: [max(0., -$delta / $q), $q];
	}

	/** Outer and inner sphere intervals. A grazing ray can traverse the same shell twice. */
	public function spans(float $planet, float $eye, float $mu): array
	{
		$projection = ($planet + $eye) * $mu;
		$result = [];
		foreach($this->bands as [$bottom, $top])
			$result[] = [...self::sphere($planet,$eye,$projection,$top), ...self::sphere($planet,$eye,$projection,$bottom)];
		return $result;
	}

	public function opticalDepth(array $spans, float $distance): float
	{
		$depth = 0.;
		foreach($spans as $i => [$enter,$exit,$innerEnter,$innerExit]) {
			$length = (min($distance,$exit) - min($distance,$enter)) - (min($distance,$innerExit) - min($distance,$innerEnter));
			$depth += max(0.,$length) * $this->bands[$i][2];
		}
		return $depth;
	}
}
