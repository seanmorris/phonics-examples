<?php
declare(strict_types=1);
namespace TerrainPhonics;

use Phonics\Render\Program;

/** Ordered colour masks shared by the ground and both map scales. */
final class SurfaceLayers
{
	public const LIMIT = 12;
	public const PATTERNS = ['none', 'mottle', 'streaks', 'gravel', 'bedded'];
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
		$this->markRepeatingSections();
	}

	/** Blend a complete, contiguous section of sibling beds together at each contact. */
	private function markRepeatingSections(): void
	{
		$fields = array_flip(['datum', 'period', 'parent', 'noise', 'scale', 'slopeLow', 'slopeHigh', 'slopeFade']);
		$count = count($this->layers);
		for($first = 1; $first < $count; $first = $next)
		{
			$start = $this->layers[$first];
			$next = $first + 1;
			if($start['period'] <= 0) continue;

			$mask = array_intersect_key($start, $fields);
			$precision = $start['period'] * 1e-7;
			$end = $start['high'];
			while($next < $count)
			{
				$layer = $this->layers[$next];
				if(abs($layer['low'] - $end) > $precision || array_intersect_key($layer, $fields) != $mask) break;

				$end = $layer['high'];
				$next++;
			}

			if($next - $first < 2 || abs($end - $start['low'] - $start['period']) > $precision) continue;

			for($i = $first; $i < $next; ++$i) $this->modes[4*$i + 3] = $i + 1 === $next ? 2 : 1;
		}
	}

	public static function landscape(array $r, TerrainAppearance $appearance): self
	{
		$s = $appearance->settings;
		$style = TerrainAppearance::style($r);
		$canyon = str_starts_with($style, 'canyon');
		$rows = match($style)
		{
			'canyon' => self::canyonLayers($r, $appearance),
			'canyon-v1' => self::earlyCanyonLayers($s),
			'mountain' => [['color' => $s['forestColor']]],
			default => [['color' => $s['grassColor']]],
		};

		if($style === 'mountain')
		{
			$rows[] = ['color' => $s['meadowColor'],'low' => $s['snowline'] + $s['treeline'],'fade' => 40,'noise' => 15];
			$rows[] = ['color' => $s['tundraColor'],'low' => $s['snowline'] + $s['tundraStart'],'fade' => 34,'noise' => 12];
		}

		if(!$canyon) $rows[] = self::exposedRock($s)
			+ ['color' => $s[$style === 'mountain' ? 'graniteColor' : 'rockColor']];

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

	/** Translate a start/end slope blend into a layer's full-coverage threshold and fade. */
	private static function exposedRock(array $settings): array
	{
		return ['slopeLow' => $settings['rockSlopeEnd'], 'slopeFade' => $settings['rockSlopeEnd'] - $settings['rockSlopeStart']];
	}

	private static function hex(array $linear): string
	{
		$channels = array_map(static function(float $value): int {
			$srgb = $value <= .0031308 ? 12.92 * $value : 1.055 * $value ** (1 / 2.4) - .055;

			return (int)round(255 * $srgb);
		}, $linear);

		return sprintf('#%02x%02x%02x', ...$channels);
	}

	/** Recover all seven exposed beds from the same dipping contacts as the elevation field. */
	private static function canyonLayers(array $recipe, TerrainAppearance $appearance): array
	{
		$settings = $appearance->settings;
		$palette = $appearance->canyonColors();
		$spacing = $recipe['terraceSpacing'] ?: $settings['bandSpacing'];
		$contacts = CanyonBeds::CONTACTS;
		$average = $palette['distant'];
		$contrast = $settings['bandContrast'];
		$rows = [
			[
				'color' => self::hex($palette['dust']), 'pattern' => 'mottle',
				'patternColor' => self::hex($palette['cap']), 'patternScale' => 61., 'patternAmount' => .24,
			],
		];

		$rock = self::exposedRock($settings);
		foreach(['rust', 'shale', 'ledge', 'sandstone', 'redBeds', 'sandstone', 'cap'] as $i => $name)
		{
			$color = array_map(
				static fn(float $base, float $bed) => $base + $contrast * ($bed - $base),
				$average, $palette[$name]
			);
			$rows[] = [
				'color' => self::hex($color),
				'datum' => 'beds',
				'period' => ($contacts[7] - $contacts[0]) * $spacing,
				'low' => ($contacts[$i] - $contacts[0]) * $spacing,
				'high' => ($contacts[$i + 1] - $contacts[0]) * $spacing,
				'fade' => .055 * $spacing / $settings['bandSharpness'],
				'noise' => .014 * $spacing,
				'scale' => 72.,
				'pattern' => 'bedded',
				'patternColor' => self::hex(array_map(static fn(float $channel) => .42 * $channel, $color)),
				'patternScale' => 33.,
				'patternStretch' => 4.5,
				'patternAmount' => .64,
			] + $rock;
		}

		return $rows;
	}

	/** Version-one terrain has horizontal sediment bands and its own soil and rock controls. */
	private static function earlyCanyonLayers(array $settings): array
	{
		$spacing = $settings['bandSpacing'];
		$contrast = $settings['bandContrast'];
		$rock = TerrainAppearance::linear($settings['rockColor']);
		$light = TerrainAppearance::linear($settings['rockBandColor']);
		$mean = array_map(static fn(float $a, float $b) => .5 * ($a + $b), $rock, $light);
		$rows = [['color' => $settings['soilColor']]];
		foreach([$light, $rock] as $i => $color)
		{
			$blend = array_map(static fn(float $a, float $b) => $a + $contrast * ($b - $a), $mean, $color);
			$rows[] = [
				'color' => self::hex($blend),
				'period' => $spacing,
				'low' => .5 * ($i + 1) * $spacing,
				'high' => .5 * ($i + 2) * $spacing,
				'fade' => .16 * $spacing / $settings['bandSharpness'],
			] + self::exposedRock($settings);
		}

		return $rows;
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
