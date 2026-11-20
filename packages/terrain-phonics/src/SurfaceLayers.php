<?php
declare(strict_types=1);
namespace TerrainPhonics;

use Phonics\Render\Program;

/** Ordered colour masks shared by the ground and both map scales. */
final class SurfaceLayers
{
	public const LIMIT = 12;
	public const DEFAULTS = [
		'color' => '#807355'
		, 'low' => -1000000.
		, 'high' => 1000000.
		, 'fade' => 10.
		, 'slopeLow' => 0.
		, 'slopeHigh' => 1.
		, 'slopeFade' => .05
		, 'noise' => 0.
		, 'scale' => 100.
		, 'parent' => -1
		, 'datum' => 'absolute'
		, 'period' => 0.
	];
	public readonly array $layers;
	private array $colors = [], $ranges = [], $blends = [], $modes = [];

	public function __construct(array $layers)
	{
		if(!array_is_list($layers) || !$layers || count($layers) > self::LIMIT)
		throw new \InvalidArgumentException('A surface needs one to twelve colour layers.');

		$rows = [];

		foreach($layers as $i => $row)
		{
			if(!is_array($row) || array_diff_key($row, self::DEFAULTS))
			throw new \InvalidArgumentException('Unknown layer field.');

			$row += self::DEFAULTS;

			if(!is_string($row['color']) || !preg_match('/^#[0-9a-f]{6}$/iD', $row['color']))
			throw new \InvalidArgumentException('Choose a six-digit layer colour.');

			foreach([
				'low'
				, 'high'
				, 'fade'
				, 'slopeLow'
				, 'slopeHigh'
				, 'slopeFade'
				, 'noise'
				, 'scale'
				, 'period'
				, 'parent'
			] as $field)
			{
				if(!is_numeric($row[$field]) || !is_finite((float)$row[$field]) || abs((float)$row[$field]) > 1e8)
				throw new \InvalidArgumentException('Invalid layer value.');

				$row[$field] = (float)$row[$field];
			}

			$datum = array_search($row['datum'], ['absolute','water','beds'], true);

			if($datum === false || $row['parent'] < -1 || $row['parent'] >= $i || floor($row['parent']) !== $row['parent']
				|| $row['low'] > $row['high'] || $row['slopeLow'] > $row['slopeHigh'] || $row['slopeLow'] < 0
				|| $row['slopeHigh'] > 1 || $row['fade'] < 0 || $row['slopeFade'] < 0 || $row['noise'] < 0
				|| $row['scale'] < .001 || $row['scale'] > 1e8 || $row['period'] < 0 || $row['period'] > 1e8
			)
			throw new \InvalidArgumentException('Invalid layer bounds, noise, or parent.');

			$row['parent'] = (int)$row['parent'];
			$rows[] = $row;
			array_push($this->colors, ...TerrainAppearance::linear($row['color']));
			array_push($this->ranges, $row['low'], $row['high'], $row['slopeLow'], $row['slopeHigh']);
			array_push($this->blends, $row['fade'], $row['slopeFade'], $row['noise'], $row['scale']);
			array_push($this->modes, $row['parent'], $datum, $row['period'], 0);
		}

		$this->layers = $rows;
	}

	public static function landscape(array $r, TerrainAppearance $appearance): self
	{
		$s = $appearance->settings;
		$style = TerrainAppearance::style($r);
		$canyon = str_starts_with($style, 'canyon');
		$rows = [['color' => $s[$canyon?'canyonSandstoneColor':($style === 'mountain'?'forestColor':'grassColor')]]];

		if($style === 'mountain')
		{
			$rows[] = ['color' => $s['meadowColor'],'low' => $s['snowline'] + $s['treeline'],'fade' => 40,'noise' => 15];
			$rows[] = ['color' => $s['tundraColor'],'low' => $s['snowline'] + $s['tundraStart'],'fade' => 34,'noise' => 12];
		}

		$rows[] = [
			'color' => $s[$canyon?'canyonCapColor':($style === 'mountain'?'graniteColor':'rockColor')]
			, 'slopeLow' => $s['rockSlopeStart']
			, 'slopeFade' => $s['rockSlopeEnd'] - $s['rockSlopeStart']
		];

		if($canyon)
		{
			$spacing = $r['terraceSpacing'] ?: $s['bandSpacing'];

			foreach([[1,'canyonRustColor'],[3,'canyonShaleColor'],[5,'canyonCapColor']] as [$i,$key])
			$rows[] = [
				'color' => $s[$key]
				, 'datum' => 'beds'
				, 'period' => 10 * $spacing
				, 'low' => (CanyonBeds::CONTACTS[$i] + 5) * $spacing
				, 'high' => (CanyonBeds::CONTACTS[$i + 1] + 5) * $spacing
				, 'fade' => .07 * $spacing
				, 'noise' => .025 * $spacing
			];
		}

		$rows[] = ['color' => $s['sandColor'],'high' => $s['beachHeight'],'datum' => 'water','fade' => $s['beachWidth']];

		if(!$canyon)
		$rows[] = [
			'color' => $s['snowColor']
			, 'low' => $s['snowline']
			, 'fade' => $s['snowTransition']
			, 'slopeHigh' => $s['snowSlopeEnd']
			, 'slopeFade' => $s['snowSlopeEnd'] - $s['snowSlopeStart']
			, 'noise' => 24.
		];

		return new self($rows);
	}

	public function bind(Program $p): void
	{
		$n = count($this->layers);
		glUniform1i($p->location('uLayerCount'), $n);
		glUniform3fv($p->location('uLayerColor[0]'), $n, $this->colors);
		glUniform4fv($p->location('uLayerRange[0]'), $n, $this->ranges);
		glUniform4fv($p->location('uLayerBlend[0]'), $n, $this->blends);
		glUniform4fv($p->location('uLayerMode[0]'), $n, $this->modes);
	}
}
