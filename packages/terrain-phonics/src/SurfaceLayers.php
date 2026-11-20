<?php
declare(strict_types=1);
namespace TerrainPhonics;

use Phonics\Render\Program;

/** Ordered colour masks shared by the ground and both map scales. */
final class SurfaceLayers
{
	public const LIMIT = 12;
	public const PATTERNS = ['none', 'mottle', 'streaks', 'gravel'];
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
		, 'pattern' => 'none'
		, 'patternColor' => '#807355'
		, 'patternAmount' => .5
		, 'patternScale' => 20.
		, 'patternStretch' => 4.
	];
	public readonly array $layers;
	private array $colors = [], $ranges = [], $blends = [], $modes = [];
	private array $patterns = [], $patternColors = [];

	public function __construct(array $layers)
	{
		if(!array_is_list($layers) || !$layers || count($layers) > self::LIMIT)
		throw new \InvalidArgumentException('A surface needs one to twelve colour layers.');

		$rows = [];

		foreach($layers as $i => $row)
		{
			if(!is_array($row) || array_diff_key($row, self::DEFAULTS))
			throw new \InvalidArgumentException('Unknown layer field.');

			$row += ['patternColor' => $row['color'] ?? self::DEFAULTS['color']] + self::DEFAULTS;

			foreach(['color', 'patternColor'] as $field)
			if(!is_string($row[$field]) || !preg_match('/^#[0-9a-f]{6}$/iD', $row[$field]))
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
				, 'patternAmount'
				, 'patternScale'
				, 'patternStretch'
			] as $field)
			{
				if(!is_numeric($row[$field]) || !is_finite((float)$row[$field]) || abs((float)$row[$field]) > 1e8)
				throw new \InvalidArgumentException('Invalid layer value.');

				$row[$field] = (float)$row[$field];
			}

			$datum = array_search($row['datum'], ['absolute','water','beds'], true);
			$pattern = array_search($row['pattern'], self::PATTERNS, true);

			if($pattern === false || $row['patternAmount'] < 0 || $row['patternAmount'] > 1
				|| $row['patternScale'] < .05 || $row['patternScale'] > 10000
				|| $row['patternStretch'] < .25 || $row['patternStretch'] > 64)
			throw new \InvalidArgumentException('Invalid colour pattern, amount, scale, or vertical stretch.');

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
			array_push($this->patterns, $pattern, $row['patternAmount'], $row['patternScale'], $row['patternStretch']);
			array_push($this->patternColors, ...TerrainAppearance::linear($row['patternColor']));
		}

		$this->layers = $rows;
	}

	public static function landscape(array $r, TerrainAppearance $appearance): self
	{
		$s = $appearance->settings;
		$style = TerrainAppearance::style($r);
		$canyon = str_starts_with($style, 'canyon');
		$rows = [['color' => $s[$canyon?'canyonSandstoneColor':($style === 'mountain'?'forestColor':'grassColor')]]];

		if($canyon) $rows[0] += [
			'pattern' => 'mottle', 'patternColor' => $s['canyonCapColor'],
			'patternScale' => 42., 'patternAmount' => .35,
		];

		$cliffPattern = $canyon ? [
			'pattern' => 'streaks', 'patternColor' => $s['canyonShaleColor'],
			'patternScale' => 24., 'patternStretch' => 5., 'patternAmount' => .55,
		] : [];

		if($style === 'mountain')
		{
			$rows[] = ['color' => $s['meadowColor'],'low' => $s['snowline'] + $s['treeline'],'fade' => 40,'noise' => 15];
			$rows[] = ['color' => $s['tundraColor'],'low' => $s['snowline'] + $s['tundraStart'],'fade' => 34,'noise' => 12];
		}

		$rows[] = [
			'color' => $s[$canyon?'canyonCapColor':($style === 'mountain'?'graniteColor':'rockColor')]
			// The layer lower bound is where the fade finishes. The rock control is where it begins.
			, 'slopeLow' => $s['rockSlopeEnd']
			, 'slopeFade' => $s['rockSlopeEnd'] - $s['rockSlopeStart']
		] + $cliffPattern;

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
				, 'patternColor' => $s[$key === 'canyonShaleColor' ? 'canyonRustColor' : 'canyonShaleColor']
			] + $cliffPattern;
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
		glUniform4fv($p->location('uLayerPattern[0]'), $n, $this->patterns);
		glUniform3fv($p->location('uLayerPatternColor[0]'), $n, $this->patternColors);
	}
}
