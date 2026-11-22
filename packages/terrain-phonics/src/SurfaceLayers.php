<?php
declare(strict_types=1);
namespace TerrainPhonics;

use Phonics\Render\Program;

/** Ordered material masks shared by the ground and both map scales. */
final class SurfaceLayers
{
	public const LIMIT = 16;
	public const MASKS = ['all','shrubs','tundra','lowland','trees','scree','blocks','slabs','stains','lichen','joints','gullies','crowns','facet-cover','block-cover','crown-cover','shore','snow'];
	public const FIELDS = ['plain','meadow','shrubs','tundra','lowland','crowns','talus','blocks','joints','slab-tone','lichen','sand','snow'];
	public const RELIEF = ['none','pattern','ribs','facets','crowns','blocks'];
	public const CHANNELS = ['color','relief','both'];
	public const PATTERNS = ['none', 'mottle', 'streaks', 'gravel', 'bedded', 'stipple', 'fractal', 'veins', 'ripples', 'jointed', 'contacts'];
	public const DEFAULTS = [
		'id' => '', 'name' => '', 'mask' => 'all', 'field' => 'plain',
		'channel' => 'color', 'relief' => 'none', 'strength' => 1., 'flatten' => 0., 'opacity' => 1.,
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
	private array $patterns = [], $patternColors = [], $effects = [];
	/** Identical structures share a linked program. All editable numbers and colors are uniforms. */
	public readonly string $structure;
	public readonly array $fields;
	private array $fieldUniforms;
	public const INKS = ['rockColor','grassColor','sandColor','snowColor','roadColor'];
	public const SCALES = ['gullySize'=>[48.,1.,512.], 'blockSize'=>[3.,.25,64.],
		'jointWidth'=>[13.,.5,128.], 'jointHeight'=>[23.,.5,256.]];
	public const FIELD_KEYS = ['rockSlopeStart','rockSlopeEnd','beachHeight','beachWidth','snowline','snowTransition',
		'alpineStart','alpineEnd','snowSlopeStart','snowSlopeEnd','macroVariation','treeline','tundraStart',
		'talusAmount','stainAmount','lichenAmount'];

	public function __construct(array $layers, array $fields = [], public readonly array $bounce = [0.,0.,0.])
	{
		if(!array_is_list($layers) || !$layers || count($layers) > self::LIMIT)
		throw new \InvalidArgumentException('A surface needs one to sixteen material layers.');

		if(!array_is_list($bounce) || count($bounce) !== 3 || array_filter($bounce, static fn($n) =>
			(!is_float($n) && !is_int($n)) || !is_finite($n) || $n < 0 || $n > 1))
			throw new \InvalidArgumentException('Invalid reflected light color.');
		$keys = array_flip(self::FIELD_KEYS);
		if(array_diff_key($fields,$keys,self::SCALES)) throw new \InvalidArgumentException('Unknown shared material field.');
		$appearance = new TerrainAppearance($fields);
		$scales = [];
		foreach(self::SCALES as $key=>[$default,$min,$max]) {
			$value = $fields[$key] ?? $default;
			if((!is_float($value) && !is_int($value)) || !is_finite($value) || $value < $min || $value > $max)
				throw new \InvalidArgumentException('Invalid material field scale.');
			$scales[$key] = (float)$value;
		}
		$this->fields = array_intersect_key($appearance->settings,$keys) + $scales;
		$this->fieldUniforms = array_intersect_key($appearance->uniforms(),array_flip([
			'uSlopeBlend','uHeightBlend','uSnowline','uMacroVariation','uMountainZones','uMountainAmounts',
		])) + ['uFieldScale'=>array_values($scales)];
		$rows = [];
		$ids = [];
		$structure = [];

		foreach($layers as $i => $row)
		{
			if(!is_array($row) || array_diff_key($row, self::DEFAULTS))
			throw new \InvalidArgumentException('Unknown layer field.');

			$row += ['patternColor' => $row['color'] ?? self::DEFAULTS['color']] + self::DEFAULTS;

			$row['id'] = $row['id'] === '' ? 'layer-'.($i+1) : $row['id'];
			$row['name'] = $row['name'] === '' ? 'Layer '.($i+1) : $row['name'];
			if(!is_string($row['id']) || !preg_match('/^[a-z][a-z0-9-]{0,63}$/D',$row['id']) || isset($ids[$row['id']])
				|| !is_string($row['name']) || trim($row['name']) === '' || strlen($row['name']) > 96)
			throw new \InvalidArgumentException('Choose a unique layer ID and a short name.');
			if(is_string($row['parent']) && !is_numeric($row['parent']))
				$row['parent'] = $ids[$row['parent']] ?? -2;
			$ids[$row['id']] = $i;
			foreach(['field'=>self::FIELDS,'mask'=>self::MASKS,'relief'=>self::RELIEF,'channel'=>self::CHANNELS] as $key=>$choices)
				if(!in_array($row[$key],$choices,true)) throw new \InvalidArgumentException('Unknown material '.$key.'.');
			if($i === 0 && $row['channel'] === 'relief')
				throw new \InvalidArgumentException('The base layer must supply a color.');
			foreach(['strength' => 32., 'flatten' => 1., 'opacity' => 1.] as $key=>$limit)
			{
				if(!is_numeric($row[$key]) || !is_finite((float)$row[$key]) || $row[$key] < ($key === 'strength' ? -32. : 0.) || $row[$key] > $limit)
					throw new \InvalidArgumentException('Invalid material '.$key.'.');
				$row[$key] = (float)$row[$key];
			}

			foreach(['color', 'patternColor'] as $field)
			if(!is_string($row[$field]) || !preg_match('/^#[0-9a-f]{6}$/iD', $row[$field]))
			throw new \InvalidArgumentException('Choose a six-digit layer color.');

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
			throw new \InvalidArgumentException('Invalid color pattern, amount, scale, or vertical stretch.');

			if($datum === false || $row['parent'] < -1 || $row['parent'] >= $i || floor($row['parent']) !== $row['parent']
				|| $row['low'] > $row['high'] || $row['slopeLow'] > $row['slopeHigh'] || $row['slopeLow'] < 0
				|| $row['slopeHigh'] > 1 || $row['fade'] < 0 || $row['slopeFade'] < 0 || $row['noise'] < 0
				|| $row['scale'] < .001 || $row['scale'] > 1e8 || $row['period'] < 0 || $row['period'] > 1e8
			)
			throw new \InvalidArgumentException('Invalid layer bounds, noise, or parent.');

			$row['parent'] = (int)$row['parent'];
			$rows[] = array_replace(self::DEFAULTS,$row);
			$structure[] = [$row['mask'], $row['field'], $row['channel'], $row['relief'], $row['pattern']];
			array_push($this->effects,$row['strength'],$row['flatten'],$row['opacity'], (float)($row['low'] <= -1e6 && $row['high'] >= 1e6 && $row['slopeLow'] == 0. && $row['slopeHigh'] == 1. && $row['period'] == 0.));
			array_push($this->colors, ...TerrainAppearance::linear($row['color']));
			array_push($this->ranges, $row['low'], $row['high'], $row['slopeLow'], $row['slopeHigh']);
			array_push($this->blends, $row['fade'], $row['slopeFade'], $row['noise'], $row['scale']);
			array_push($this->modes, $row['parent'], $datum, $row['period'], 0);
			array_push($this->patterns, $pattern, $row['patternAmount'], $row['patternScale'], $row['patternStretch']);
			array_push($this->patternColors, ...TerrainAppearance::linear($row['patternColor']));
		}

		$ordinary = true;
		foreach($rows as $row) if($row['mask'] !== 'all' || $row['field'] !== 'plain' || $row['channel'] !== 'color') $ordinary = false;
		$patterns = array_values(array_unique(array_column($rows,'pattern')));
		sort($patterns);
		$this->structure = $ordinary ? 'color/'.implode('/',$patterns) : json_encode($structure, JSON_THROW_ON_ERROR);
		$this->layers = $rows;
		$this->markRepeatingSections();
	}

	/** Blend a complete, contiguous section of sibling beds together at each contact. */
	private function markRepeatingSections(): void
	{
		$fields = array_flip(['datum', 'period', 'parent', 'noise', 'scale', 'slopeLow', 'slopeHigh', 'slopeFade', 'channel', 'field', 'mask', 'opacity', 'flatten']);
		$count = count($this->layers);
		for($first = 1; $first < $count; $first = $next)
		{
			$start = $this->layers[$first];
			$next = $first + 1;
			if($start['period'] <= 0 || $start['channel'] !== 'color' || $start['field'] !== 'plain' || $start['mask'] !== 'all' || $start['flatten'] != 0.) continue;

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
		$style = ($r['preset'] ?? '') === 'limestone' ? 'limestone' : TerrainAppearance::style($r);
		$canyon = str_starts_with($style, 'canyon');
		if($style === 'mountain') return self::ridges($s);
		$rows = match($style) {
			'canyon' => self::canyonLayers($r,$appearance),
			'canyon-v1' => self::earlyCanyonLayers($s),
			'limestone' => self::limestoneLayers($s,($r['baseBias'] ?? 0.) * ($r['heightScale'] ?? 0.)),
			'basalt-fjords' => self::fjordLayers($s),
			'amber-dunes' => self::duneLayers($s,$r),
			default => [['color'=>$s['grassColor']]],
		};
		if($style === 'coast')
		{
			$rock = $s['rockColor'];
			$rows[] = self::exposedRock($s) + ['color' => $rock]
				+ self::stipple($rock, .80, .60);
		}

		if($style !== 'amber-dunes') {
			$shore = ['color' => $s['sandColor'],'high' => $s['beachHeight'],'datum' => 'water','fade' => $s['beachWidth']]
				+ self::stipple($s['sandColor'], .80, .55, .70);
			if($style === 'basalt-fjords') $shore += ['slopeHigh'=>.10,'slopeFade'=>.08];
			$rows[] = $shore;
		}

		if(!$canyon && $style !== 'amber-dunes')
		$rows[] = [
			'color' => $s['snowColor']
			, 'low' => $s['snowline']
			, 'fade' => $s['snowTransition']
			, 'slopeHigh' => $s['snowSlopeEnd']
			, 'slopeFade' => $s['snowSlopeEnd'] - $s['snowSlopeStart']
			, 'noise' => 24.
		];

		$names = match($style) {
			'canyon' => ['Canyon dust','Rust beds','Shale','Ledge','Lower sandstone','Red beds','Upper sandstone','Cap rock','Sand'],
			'canyon-v1' => ['Soil','Light strata','Dark strata','Sand'],
			'limestone' => ['Grassland','Valley grass','Outcrops','Limestone','Mineral veins','Sand','Snow'],
			'basalt-fjords' => ['Moss heath','Scree','Basalt faces','Lava beds','Seep stains','Tidal gravel','Summit snow'],
			'amber-dunes' => ['Dune sand','Slip faces','Crest sand','Wind ripples','Desert pavement','Salt crust'],
			default => ['Grass','Exposed rock','Sand','Snow'],
		};
		foreach($rows as $i=>&$row) {
			$row['name'] = $names[$i];
			$row['id'] = strtolower(str_replace(' ','-',$names[$i]));
		}
		unset($row);
		if($style === 'coast') $rows[] = ['id'=>'coastal-bands','name'=>'Coastal bands','color'=>$s['rockBandColor'],
			'parent'=>'exposed-rock','pattern'=>'bedded','period'=>$s['bandSpacing'],'low'=>0.,'high'=>.45*$s['bandSpacing'],
			'fade'=>.08*$s['bandSpacing'],'opacity'=>0.];
		return new self($rows,array_intersect_key($s,array_flip(self::FIELD_KEYS)),
			$style === 'canyon' ? $appearance->canyonColors()['bounce'] : [0.,0.,0.]);
	}

	/** Every field is available to other recipes. A material can also use a different field's coverage. */
	private static function ridges(array $s): self
	{
		$rows = [];
		foreach([
			['Meadow','meadow','all','meadow'], ['Shrubs','meadow','shrubs','shrubs'],
			['Tundra','tundra','tundra','tundra'], ['Valley grass','grass','lowland','lowland'],
			['Forest','forest','trees','crowns'], ['Talus fans','talus','scree','talus'],
			['Block fields','talus','blocks','blocks'], ['Granite slabs','granite','slabs','joints'],
			['Iron stains','stain','stains','joints'],
		] as [$name,$color,$mask,$field]) {
			$row = ['name'=>$name,'id'=>strtolower(str_replace(' ','-',$name)),'color'=>$s[$color.'Color'],
				'mask'=>$mask,'field'=>$field];
			if($field === 'shrubs') $row['patternColor'] = $s['forestColor'];
			if($mask === 'slabs') {
				$row['color'] = self::tint($s['graniteColor'],.70,.50,.39);
				$row += ['pattern'=>'jointed','patternColor'=>self::tint($s['graniteColor'],1.84,1.88,1.88),
					'patternAmount'=>1.,'patternScale'=>self::SCALES['jointWidth'][0],
					'patternStretch'=>self::SCALES['jointHeight'][0]/self::SCALES['jointWidth'][0]];
			}
			$rows[] = $row;
		}
		foreach([
			['Facets','facet-cover',1.], ['Blocks','block-cover',1.],
			['Crowns','crown-cover',1.], ['Ribs','all',.5],
		] as [$name,$mask,$strength])
			$rows[] = ['id'=>'relief-'.strtolower($name),'name'=>$name.' relief','channel'=>'relief','relief'=>strtolower($name),
				'mask'=>$mask,'strength'=>$strength];
		$rows[] = ['id'=>'shore','name'=>'Sandy shore','field'=>'sand','mask'=>'shore','color'=>$s['sandColor']];
		$rows[] = ['id'=>'snow','name'=>'Snow','field'=>'snow','mask'=>'snow','color'=>$s['snowColor']];
		return new self($rows,array_intersect_key($s,array_flip(self::FIELD_KEYS)));
	}

	private static function stipple(string $color, float $shade, float $amount = 1., float $size = 1.6): array
	{
		$darker = array_map(static fn(float $channel): float => $shade*$channel, TerrainAppearance::linear($color));

		return ['pattern'=>'stipple', 'patternColor'=>self::hex($darker), 'patternAmount'=>$amount,
			'patternScale'=>$size, 'patternStretch'=>1.];
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

	/** Grassland on the valley floor, broken vegetation and weathered stone on its steeper sides. */
	private static function limestoneLayers(array $s, float $floor): array
	{
		$grass = $s['grassColor'];
		$rock = $s['rockColor'];
		$deepGrass = self::tint($grass, .18, .32, .47);
		$dryGrass = self::tint($grass, 1.14, 1.01, .93);
		$weathering = self::tint($rock, .34, .36, .34);
		$exposure = self::exposedRock($s);
		return [
			['color' => $grass, 'pattern' => 'fractal', 'patternColor' => $deepGrass,
				'patternScale' => 69., 'patternAmount' => 1.],
			// Fine grass gives way to darker vegetation at different heights along the feet of the peaks.
			['color' => $dryGrass, 'high' => $floor - 9., 'fade' => 62., 'noise' => 60., 'scale' => 49.,
				'pattern' => 'fractal', 'patternColor' => $deepGrass, 'patternScale' => 5.5, 'patternAmount' => .83],
			// Small outcrops among the darker vegetation break up the boundary between the grass and the cliffs.
			['color' => $deepGrass, 'slopeLow' => $s['rockSlopeStart'], 'slopeFade' => $exposure['slopeFade'] * .30,
				'slopeHigh' => $s['rockSlopeEnd'], 'pattern' => 'fractal', 'patternColor' => $rock,
				'patternScale' => 26., 'patternStretch' => 1.4, 'patternAmount' => .58],
			['color' => $rock, 'pattern' => 'fractal', 'patternColor' => $weathering,
				'patternScale' => 94., 'patternStretch' => 2.2, 'patternAmount' => .95] + $exposure,
			// A second, finer pattern gives exposed faces long mineral stains.
			['color' => $s['rockBandColor'], 'parent' => 3, 'slopeLow' => 1., 'slopeFade' => .85,
				'pattern' => 'veins', 'patternColor' => $weathering, 'patternScale' => 26.,
				'patternStretch' => 5.2, 'patternAmount' => .86],
		];
	}

	private static function tint(string $color, float $red, float $green, float $blue): string
	{
		$base = TerrainAppearance::linear($color);
		return self::hex([min(1., $red * $base[0]), min(1., $green * $base[1]), min(1., $blue * $base[2])]);
	}

	private static function fjordLayers(array $s): array
	{
		$grass = $s['grassColor'];
		$rock = $s['rockColor'];
		$exposed = self::exposedRock($s);
		return [
			['color'=>$grass, 'pattern'=>'fractal', 'patternColor'=>self::tint($grass,.44,.52,.40),
				'patternScale'=>49., 'patternAmount'=>.84],
			['color'=>$s['soilColor'], 'slopeLow'=>$s['rockSlopeStart']+.29*$exposed['slopeFade'],
				'slopeFade'=>.34*$exposed['slopeFade'], 'opacity'=>.64,
				'pattern'=>'stipple', 'patternColor'=>$rock, 'patternScale'=>2.2, 'patternAmount'=>.8],
			['color'=>$rock, 'pattern'=>'jointed', 'patternColor'=>$s['rockBandColor'], 'patternAmount'=>.95,
				'patternScale'=>14., 'patternStretch'=>2.7] + $exposed,
			['color'=>self::tint($rock,1.38,1.32,1.24), 'parent'=>2, 'opacity'=>.45,
				'pattern'=>'contacts', 'patternColor'=>self::tint($s['soilColor'],.87,.61,.57),
				'patternAmount'=>1., 'patternScale'=>84., 'patternStretch'=>.30],
			['color'=>self::tint($s['rockBandColor'],.65,.68,.68), 'parent'=>2, 'opacity'=>.34,
				'pattern'=>'veins', 'patternColor'=>self::tint($rock,.42,.47,.50),
				'patternAmount'=>.89, 'patternScale'=>45., 'patternStretch'=>3.3],
		];
	}

	private static function duneLayers(array $s, array $recipe): array
	{
		$sand = $s['sandColor'];
		$height = $recipe['heightScale'] ?? Recipe::shapeDefaults('amber-dunes')['heightScale'];
		$crest = ($recipe['baseBias'] ?? Recipe::shapeDefaults('amber-dunes')['baseBias'])*$height;
		return [
			['color'=>$sand, 'pattern'=>'fractal', 'patternColor'=>$s['soilColor'], 'patternScale'=>85., 'patternAmount'=>.32],
			['color'=>$s['soilColor'], 'slopeLow'=>.105, 'slopeFade'=>.067, 'opacity'=>.52,
				'pattern'=>'fractal', 'patternColor'=>$sand, 'patternScale'=>34., 'patternAmount'=>.40],
			['color'=>self::tint($sand,1.13,1.21,1.24), 'low'=>$crest-.12*$height, 'fade'=>.08*$height,
				'noise'=>.027*$height, 'scale'=>72.] + self::stipple($sand,.86,.40,1.1),
			['channel'=>'relief', 'relief'=>'pattern', 'strength'=>.028, 'pattern'=>'ripples', 'patternAmount'=>1.,
				'patternScale'=>.78, 'patternStretch'=>1., 'slopeHigh'=>.065, 'slopeFade'=>.045],
			['color'=>$s['rockColor'], 'high'=>$crest-.49*$height, 'fade'=>.10*$height, 'noise'=>.035*$height,
				'scale'=>90., 'slopeHigh'=>.030, 'slopeFade'=>.035, 'flatten'=>1.] + self::stipple($s['soilColor'],.90,.65,.65),
			['color'=>$s['rockBandColor'], 'high'=>$crest-.61*$height, 'fade'=>.045*$height, 'noise'=>.019*$height,
				'scale'=>44., 'slopeHigh'=>.009, 'slopeFade'=>.018, 'flatten'=>1.] + self::stipple($s['rockBandColor'],.79,.65,1.4),
		];
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
		foreach($this->fieldUniforms as $name=>$values) {
			$location = $p->location($name);
			match(count($values)) {
				1=>glUniform1f($location,...$values), 2=>glUniform2f($location,...$values),
				3=>glUniform3f($location,...$values), 4=>glUniform4f($location,...$values),
			};
		}
		glUniform3f($p->location('uMaterialBounce'),...$this->bounce);
		$n = count($this->layers);
		glUniform1i($p->location('uLayerCount'), $n);
		glUniform3fv($p->location('uLayerColor[0]'), $n, $this->colors);
		glUniform4fv($p->location('uLayerRange[0]'), $n, $this->ranges);
		glUniform4fv($p->location('uLayerBlend[0]'), $n, $this->blends);
		glUniform4fv($p->location('uLayerMode[0]'), $n, $this->modes);
		glUniform4fv($p->location('uLayerEffect[0]'), $n, $this->effects);
		glUniform4fv($p->location('uLayerPattern[0]'), $n, $this->patterns);
		glUniform3fv($p->location('uLayerPatternColor[0]'), $n, $this->patternColors);
	}
}
