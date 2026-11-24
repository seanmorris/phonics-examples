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
	public const PATTERNS = ['none', 'mottle', 'streaks', 'gravel', 'bedded', 'stipple', 'fractal', 'veins', 'ripples', 'jointed', 'contacts', 'fractures'];
	public const DEFAULTS = [
		'id' => '', 'name' => '', 'mask' => 'all', 'field' => 'plain',
		'channel' => 'color', 'relief' => 'none', 'strength' => 1., 'flatten' => 0., 'opacity' => 1.,
		'reflectivity' => 0., 'roughness' => .35,
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
	private array $patterns = [], $patternColors = [], $effects = [], $finishes = [];
	public readonly bool $reflective;
	/** Identical structures share a linked program. All editable numbers and colors are uniforms. */
	public readonly string $structure;
	public readonly array $fields;
	private array $fieldUniforms;
	public const INKS = ['rockColor','grassColor','sandColor','snowColor','roadColor'];
	public const SCALES = ['gullySize'=>[48.,1.,512.], 'blockSize'=>[3.,.25,64.],
		'jointWidth'=>[13.,.5,128.], 'jointHeight'=>[23.,.5,256.]];
	/**
	 * Gully spacing in meters for presets whose rib relief needs its own scale. Badland rills sit a few meters apart
	 * on slopes tens of meters high; the flutes of the emerald ridges run down walls hundreds of meters high.
	 */
	private const PRESET_GULLIES = ['painted-badlands' => 14., 'emerald-ridges' => 60.];
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
		$reflective = false;

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
			foreach(['strength' => 32., 'flatten' => 1., 'opacity' => 1., 'reflectivity' => 1., 'roughness' => 1.] as $key=>$limit)
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
			$reflective = $reflective || ($row['channel'] !== 'relief' && $row['reflectivity'] > 0.);
			// Premultiply the roughness by coating coverage so opaque snow can cleanly cover reflective ice.
			array_push($this->finishes, $row['reflectivity'], $row['reflectivity']*$row['roughness']**2);
			array_push($this->effects,$row['strength'],$row['flatten'],$row['opacity'], 1.);
			array_push($this->colors, ...TerrainAppearance::linear($row['color']));
			array_push($this->ranges, $row['low'], $row['high'], $row['slopeLow'], $row['slopeHigh']);
			array_push($this->blends, $row['fade'], $row['slopeFade'], $row['noise'], $row['scale']);
			array_push($this->modes, $row['parent'], $datum, $row['period'], 0);
			array_push($this->patterns, $pattern, $row['patternAmount'], $row['patternScale'], $row['patternStretch']);
			array_push($this->patternColors, ...TerrainAppearance::linear($row['patternColor']));
		}

		$this->reflective = $reflective;
		$ordinary = !$reflective;
		foreach($rows as $row) if($row['mask'] !== 'all' || $row['field'] !== 'plain' || $row['channel'] !== 'color') $ordinary = false;
		$patterns = array_values(array_unique(array_column($rows,'pattern')));
		sort($patterns);
		$this->layers = $rows;
		$this->markRepeatingSections();
		if(!$ordinary) foreach($rows as $i=>$row) {
			$structure[$i][] = [$this->restricted($i),$row['parent'],$this->section($i)];
		}
		$this->structure = $ordinary ? 'color/'.implode('/',$patterns)
			: ($reflective ? 'reflective/' : '').json_encode($structure, JSON_THROW_ON_ERROR);
	}

	/** Whether the advanced shader needs an elevation or grade mask for this row. */
	public function restricted(int $index): bool
	{
		return self::hasRange($this->layers[$index]);
	}

	private static function hasRange(array $row): bool
	{
		return $row['low'] > -1e6 || $row['high'] < 1e6 || $row['slopeLow'] != 0. || $row['slopeHigh'] != 1. || $row['period'] != 0.;
	}

	/** Preview the stack with selected contributions hidden. Children still receive their authored parent masks. */
	public function previewWithout(array $layerIds): self
	{
		$view = clone $this;
		foreach($this->layers as $i=>$row)
			$view->effects[4*$i+3] = in_array($row['id'],$layerIds,true) ? -1. : (float)!self::hasRange($row);
		return $view;
	}

	/** A repeating section covers a full period. 1 continues the section and 2 closes it. */
	public function section(int $index): int { return (int)$this->modes[4*$index+3]; }

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
			'arctic-meltwater' => self::arcticLayers($s),
			'tidal-lagoon' => self::lagoonLayers($s),
			'heather-moor' => self::moorLayers($s),
			'salt-pans' => self::saltLayers($s,$r),
			'cinder-highlands' => self::cinderLayers($s),
			'rolling-prairie' => self::prairieLayers($s,$r),
			'river-valley' => self::riverLayers($s),
			'wooded-foothills' => self::foothillLayers($s),
			'high-desert-steppe' => self::steppeLayers($s,$r),
			'alaska-range' => self::alaskaLayers($s),
			'chalk-headlands' => self::chalkLayers($s),
			'karst-bay' => self::karstLayers($s),
			'tepui-highlands' => self::tepuiLayers($s),
			'emerald-ridges' => self::emeraldLayers($s),
			default => [['color'=>$s['grassColor']]],
		};
		if($style === 'coast')
		{
			$rock = $s['rockColor'];
			$rows[] = self::exposedRock($s) + ['color' => $rock]
				+ self::stipple($rock, .80, .60);
		}

		$standardShore = in_array($style, ['coast','limestone','basalt-fjords','canyon','canyon-v1'], true);
		if($standardShore) {
			$shore = ['color' => $s['sandColor'],'high' => $s['beachHeight'],'datum' => 'water','fade' => $s['beachWidth']]
				+ self::stipple($s['sandColor'], .80, .55, .70);
			if($style === 'basalt-fjords') $shore += ['slopeHigh'=>.10,'slopeFade'=>.08];
			$rows[] = $shore;
		}

		if($standardShore && !$canyon)
		$rows[] = [
			'color' => $s['snowColor']
			, 'low' => $s['snowline']
			, 'fade' => $s['snowTransition']
			, 'slopeHigh' => $s['snowSlopeEnd']
			, 'slopeFade' => $s['snowSlopeEnd'] - $s['snowSlopeStart']
			, 'noise' => 24.
		];

		// Badlands draw the canyon beds, then dry grass in the washes and rills down every bare slope.
		$badlands = ($r['preset'] ?? '') === 'painted-badlands';
		if($badlands) array_push($rows, ...self::badlandLayers($s));

		$names = match($style) {
			'canyon' => ['Canyon dust','Rust beds','Shale','Ledge','Lower sandstone','Red beds','Upper sandstone','Cap rock','Sand'],
			'canyon-v1' => ['Soil','Light strata','Dark strata','Sand'],
			'limestone' => ['Grassland','Valley grass','Outcrops','Limestone','Mineral veins','Sand','Snow'],
			'basalt-fjords' => ['Moss heath','Scree','Basalt faces','Lava beds','Seep stains','Tidal gravel','Summit snow'],
			'arctic-meltwater' => ['Moraine gravel','Glacier ice','Rock outcrops','Wind-packed snow','Snow ripples'],
			'tidal-lagoon' => ['Reef floor','Shoal sand','Seagrass beds','Shell beach','Salt grass','Cay scrub','Sand ripples'],
			'amber-dunes' => ['Dune sand','Slip faces','Crest sand','Wind ripples','Desert pavement','Salt crust'],
			'heather-moor' => ['Heather','Moor grass','Bracken','Peat banks','Granite tors'],
			'salt-pans' => ['Alluvial fans','Rim outcrops','Lake clay','Salt crust','Silt stains','Brine margin'],
			'cinder-highlands' => ['Lava clinker','Lichen islands','Ash drifts','Oxidized scoria','Lava ribs'],
			'rolling-prairie' => ['Tallgrass','Green swales','Cured grass','Butte outcrops'],
			'river-valley' => ['Flood meadow','Alluvial terraces','Wet silt','Gravel bars'],
			'wooded-foothills' => ['Glades','Fern slopes','Woodland canopy','Spur outcrops'],
			'high-desert-steppe' => ['Gravel apron','Sagebrush','Dry washes','Rim rock'],
			'alaska-range' => ['Tussock tundra','Outwash gravel','Moraine talus','Schist headwalls','Firn snowfields'],
			'chalk-headlands' => ['Downland turf','Chalk faces','Flint nodules','Wet chalk','Shingle beach'],
			'karst-bay' => [
				'Jungle understory'
				, 'Forest canopy'
				, 'Limestone faces'
				, 'Rain streaks'
				, 'Tidal notch'
				, 'Cove sand'
			],
			'emerald-ridges' => [
				'Fern slopes'
				, 'Rainforest canopy'
				, 'Mossy cliffs'
				, 'Red earth'
				, 'Black sand'
				, 'Flutes'
			],
			'tepui-highlands' => [
				'Rainforest canopy'
				, 'Tree crowns'
				, 'Summit scrub'
				, 'Sandstone walls'
				, 'Algae streaks'
				, 'Quartz sand'
			],
			default => ['Grass','Exposed rock','Sand','Snow'],
		};
		if($badlands) array_push($names, 'Wash grass', 'Rills');
		foreach($rows as $i=>&$row) {
			$row['name'] = $names[$i];
			$row['id'] = strtolower(str_replace(' ','-',$names[$i]));
		}
		unset($row);
		if($style === 'coast') $rows[] = ['id'=>'coastal-bands','name'=>'Coastal bands','color'=>$s['rockBandColor'],
			'parent'=>'exposed-rock','pattern'=>'bedded','period'=>$s['bandSpacing'],'low'=>0.,'high'=>.45*$s['bandSpacing'],
			'fade'=>.08*$s['bandSpacing'],'opacity'=>0.];
		$fields = array_intersect_key($s, array_flip(self::FIELD_KEYS));
		$preset = $r['preset'] ?? '';
		if(isset(self::PRESET_GULLIES[$preset])) $fields['gullySize'] = self::PRESET_GULLIES[$preset];
		return new self($rows, $fields, $style === 'canyon' ? $appearance->canyonColors()['bounce'] : [0.,0.,0.]);
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
		$deepGrass = self::tint($grass, .22, .32, .32);
		$dryGrass = self::tint($grass, 1.14, 1.01, .93);
		$weathering = self::tint($rock, .25, .30, .33);
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

	/**
	 * Build the moor: heather flowering in patches, straw grass in the hollows below a wavering line, rust bracken on
	 * the moderate flanks, dark peat banks with moss at the lochans' edges, and lichened granite where the tors steepen.
	 * @param array $s Validated appearance settings.
	 * @return array Material rows from the bottom up.
	 */
	private static function moorLayers(array $s): array
	{
		$heather = $s['meadowColor'];
		$grass = $s['grassColor'];
		$bracken = $s['forestColor'];

		return [
			[
				'color' => $heather
				, 'pattern' => 'fractal'
				, 'patternColor' => '#4c3d4a'
				, 'patternScale' => 18.
				, 'patternAmount' => .3
			]
			, [
				'color' => $grass
				, 'datum' => 'water'
				, 'high' => 30.
				, 'fade' => 12.
				, 'noise' => 22.
				, 'scale' => 260.
				, 'pattern' => 'mottle'
				, 'patternColor' => self::tint($grass, .78, .84, .70)
				, 'patternScale' => 6.
				, 'patternAmount' => .45
			],
			// Moor slopes are gentle: half the ground is under 6 degrees and the steepest near 18.
			[
				'color' => $bracken
				, 'slopeLow' => .016
				, 'slopeHigh' => .026
				, 'slopeFade' => .004
				, 'high' => 90.
				, 'fade' => 25.
				, 'noise' => 40.
				, 'scale' => 200.
				, 'opacity' => .8
				, 'pattern' => 'mottle'
				, 'patternColor' => self::tint($bracken, .70, .74, .60)
				, 'patternScale' => 4.
				, 'patternAmount' => .5
			]
			, [
				'color' => $s['soilColor']
				, 'datum' => 'water'
				, 'high' => $s['beachHeight']
				, 'fade' => $s['beachWidth']
				, 'noise' => 1.2
				, 'scale' => 25.
				, 'pattern' => 'mottle'
				, 'patternColor' => '#6f7a3a'
				, 'patternScale' => 3.5
				, 'patternAmount' => .45
			]
			, [
				'color' => $s['rockColor']
				, 'pattern' => 'jointed'
				, 'patternColor' => $s['rockBandColor']
				, 'patternAmount' => .8
				, 'patternScale' => 4.
				, 'patternStretch' => 1.6
			] + self::exposedRock($s)
		];
	}

	/** Dry salt follows the basin floor. Only the wet margin follows the water when it rises. */
	private static function saltLayers(array $s, array $recipe): array
	{
		$recipe += Recipe::shapeDefaults('salt-pans');
		$floor = $recipe['baseBias']*$recipe['heightScale'];
		return [
			['color'=>$s['sandColor'], 'pattern'=>'fractal', 'patternColor'=>$s['rockBandColor'],
				'patternScale'=>72., 'patternAmount'=>.74, 'patternStretch'=>1.25],
			self::exposedRock($s) + ['color'=>$s['rockColor'], 'pattern'=>'gravel', 'patternColor'=>$s['rockBandColor'],
				'patternScale'=>3., 'patternAmount'=>.52],
			['color'=>$s['soilColor'], 'high'=>$floor+12., 'fade'=>10., 'noise'=>4., 'scale'=>62.,
				'pattern'=>'fractal', 'patternColor'=>$s['sandColor'], 'patternScale'=>52., 'patternAmount'=>.68],
			['color'=>$s['snowColor'], 'high'=>$floor+3., 'fade'=>3.5, 'noise'=>2.8, 'scale'=>68.,
				'slopeHigh'=>.002, 'slopeFade'=>.004, 'pattern'=>'fractures', 'patternColor'=>$s['soilColor'],
				'patternScale'=>2.5, 'patternStretch'=>.35, 'patternAmount'=>.83],
			['parent'=>'salt-crust', 'color'=>$s['snowColor'], 'pattern'=>'fractal', 'patternColor'=>$s['sandColor'],
				'patternScale'=>36., 'patternStretch'=>1., 'patternAmount'=>.86, 'opacity'=>.38],
			['color'=>self::tint($s['soilColor'],.76,.85,.86), 'datum'=>'water', 'high'=>.30, 'fade'=>.95,
				'noise'=>.45, 'scale'=>32., 'slopeHigh'=>.009, 'slopeFade'=>.016, 'reflectivity'=>.25,
				'roughness'=>.37, 'pattern'=>'fractal', 'patternColor'=>$s['sandColor'], 'patternScale'=>7., 'patternAmount'=>.42],
		];
	}

	/** Dark clinker on the apron, pockets of ash and lichen, and rusty cinders on the cone flanks. */
	private static function cinderLayers(array $s): array
	{
		return [
			['color'=>$s['rockColor'], 'pattern'=>'gravel', 'patternColor'=>$s['rockBandColor'],
				'patternScale'=>1.4, 'patternStretch'=>1., 'patternAmount'=>.90],
			['color'=>$s['grassColor'], 'high'=>130., 'fade'=>35., 'noise'=>170., 'scale'=>105.,
				'slopeHigh'=>.012, 'slopeFade'=>.024, 'opacity'=>.45, 'pattern'=>'fractal',
				'patternColor'=>$s['rockColor'], 'patternScale'=>6., 'patternAmount'=>.85],
			['color'=>$s['sandColor'], 'high'=>152., 'fade'=>48., 'noise'=>135., 'scale'=>84.,
				'slopeHigh'=>.028, 'slopeFade'=>.038, 'opacity'=>.36] + self::stipple($s['sandColor'],.61,.58,.85),
			['color'=>$s['soilColor'], 'low'=>190., 'fade'=>90., 'noise'=>77., 'scale'=>140.,
				'pattern'=>'fractal', 'patternColor'=>$s['rockBandColor'], 'patternScale'=>43., 'patternAmount'=>.78],
			self::exposedRock($s) + ['color'=>$s['rockColor'], 'pattern'=>'jointed', 'patternColor'=>$s['rockBandColor'],
				'patternScale'=>6., 'patternStretch'=>1.45, 'patternAmount'=>.61, 'opacity'=>.85],
		];
	}

	private static function prairieLayers(array $s, array $recipe): array
	{
		$recipe += Recipe::shapeDefaults('rolling-prairie');
		$plain = $recipe['heightScale']*$recipe['baseBias'];
		return [
			['color'=>$s['grassColor'], 'field'=>'meadow', 'pattern'=>'fractal', 'patternColor'=>$s['sandColor'],
				'patternAmount'=>.46, 'patternScale'=>45.],
			['color'=>$s['meadowColor'], 'high'=>$plain-8., 'fade'=>20., 'noise'=>16., 'scale'=>240.,
				'field'=>'meadow'] + self::stipple($s['meadowColor'],.83,.38),
			['color'=>$s['sandColor'], 'low'=>$plain+14., 'fade'=>27., 'noise'=>26., 'scale'=>170.,
				'opacity'=>.62, 'pattern'=>'fractal', 'patternColor'=>$s['grassColor'], 'patternAmount'=>.52, 'patternScale'=>13.],
			self::exposedRock($s) + ['color'=>$s['rockColor'], 'pattern'=>'jointed',
				'patternColor'=>$s['rockBandColor'], 'patternAmount'=>.48, 'patternScale'=>3.],
		];
	}

	private static function riverLayers(array $s): array
	{
		return [
			['color'=>$s['grassColor'], 'field'=>'meadow', 'pattern'=>'fractal', 'patternColor'=>$s['meadowColor'],
				'patternAmount'=>.68, 'patternScale'=>27.],
			['color'=>$s['meadowColor'], 'datum'=>'water', 'low'=>6., 'high'=>34., 'fade'=>8., 'noise'=>4.,
				'scale'=>95., 'field'=>'meadow'] + self::stipple($s['meadowColor'],.80,.4),
			['color'=>$s['soilColor'], 'datum'=>'water', 'high'=>$s['beachHeight'], 'fade'=>$s['beachWidth'],
				'noise'=>.6, 'scale'=>28., 'pattern'=>'fractal', 'patternColor'=>$s['rockColor'], 'patternAmount'=>.42,
				'patternScale'=>4., 'roughness'=>.55, 'reflectivity'=>.12],
			['color'=>$s['sandColor'], 'datum'=>'water', 'low'=>-.7, 'high'=>3., 'fade'=>1.5, 'noise'=>1.4,
				'scale'=>43., 'slopeHigh'=>.013, 'slopeFade'=>.02, 'pattern'=>'gravel',
				'patternColor'=>$s['rockColor'], 'patternScale'=>1.2, 'patternAmount'=>.82],
		];
	}

	private static function foothillLayers(array $s): array
	{
		return [
			['color'=>$s['grassColor'], 'field'=>'meadow', 'pattern'=>'fractal', 'patternColor'=>$s['meadowColor'],
				'patternAmount'=>.54, 'patternScale'=>30.],
			['mask'=>'shrubs', 'field'=>'shrubs', 'color'=>$s['meadowColor'], 'patternColor'=>$s['soilColor']],
			['mask'=>'trees', 'field'=>'crowns', 'channel'=>'both', 'relief'=>'crowns', 'strength'=>.48,
				'color'=>$s['forestColor'], 'pattern'=>'fractal', 'patternColor'=>$s['meadowColor'],
				'patternScale'=>29., 'patternAmount'=>.34],
			self::exposedRock($s) + ['color'=>$s['rockColor'], 'pattern'=>'jointed',
				'patternColor'=>$s['rockBandColor'], 'patternScale'=>3.2, 'patternAmount'=>.68],
		];
	}

	private static function steppeLayers(array $s, array $recipe): array
	{
		$recipe += Recipe::shapeDefaults('high-desert-steppe');
		$apron = $recipe['heightScale']*$recipe['baseBias'];
		return [
			['color'=>$s['soilColor'], 'pattern'=>'gravel', 'patternColor'=>$s['rockBandColor'],
				'patternAmount'=>.65, 'patternScale'=>1.7],
			['color'=>$s['grassColor'], 'mask'=>'shrubs', 'field'=>'shrubs', 'patternColor'=>$s['soilColor'],
				'slopeHigh'=>.038, 'slopeFade'=>.045],
			['color'=>$s['sandColor'], 'high'=>$apron+10., 'fade'=>32., 'slopeHigh'=>.004, 'slopeFade'=>.009,
				'noise'=>16., 'scale'=>180., 'pattern'=>'gravel', 'patternColor'=>$s['rockBandColor'],
				'patternAmount'=>.59, 'patternScale'=>1.2],
			self::exposedRock($s) + ['color'=>$s['rockColor'], 'pattern'=>'fractures', 'patternColor'=>$s['rockBandColor'],
				'patternScale'=>3.5, 'patternStretch'=>1.2, 'patternAmount'=>.64],
		];
	}

	private static function alaskaLayers(array $s): array
	{
		return [
			['color'=>$s['grassColor'], 'pattern'=>'fractal', 'patternColor'=>$s['meadowColor'],
				'patternScale'=>36., 'patternStretch'=>1.4, 'patternAmount'=>.78],
			['color'=>$s['sandColor'], 'high'=>.43*$s['snowline'], 'fade'=>45., 'noise'=>65., 'scale'=>170.,
				'slopeHigh'=>.012, 'slopeFade'=>.028, 'pattern'=>'gravel', 'patternColor'=>$s['talusColor'],
				'patternScale'=>1.4, 'patternAmount'=>.8],
			['color'=>$s['talusColor'], 'low'=>.65*$s['snowline'], 'fade'=>105., 'noise'=>95., 'scale'=>210.,
				'pattern'=>'gravel', 'patternColor'=>$s['rockColor'], 'patternScale'=>3., 'patternAmount'=>.64],
			self::exposedRock($s) + ['color'=>$s['rockColor'], 'pattern'=>'jointed', 'patternColor'=>$s['rockBandColor'],
				'patternScale'=>6., 'patternStretch'=>1.8, 'patternAmount'=>.76],
			['color'=>$s['snowColor'], 'low'=>$s['snowline'], 'fade'=>$s['snowTransition'], 'noise'=>195., 'scale'=>180.,
				'slopeHigh'=>$s['snowSlopeStart'], 'slopeFade'=>$s['snowSlopeEnd']-$s['snowSlopeStart'],
				'pattern'=>'fractal', 'patternColor'=>self::tint($s['snowColor'],.71,.81,.90),
				'patternScale'=>26., 'patternStretch'=>1.7, 'patternAmount'=>.62],
		];
	}

	private static function chalkLayers(array $s): array
	{
		return [
			['color'=>$s['grassColor'], 'field'=>'meadow', 'pattern'=>'fractal', 'patternColor'=>$s['meadowColor'],
				'patternScale'=>30., 'patternStretch'=>1.4, 'patternAmount'=>.68],
			self::exposedRock($s) + ['color'=>$s['rockColor'], 'pattern'=>'fractures', 'patternColor'=>$s['rockBandColor'],
				'patternScale'=>4.2, 'patternStretch'=>1.6, 'patternAmount'=>.42],
			['parent'=>'chalk-faces', 'color'=>$s['rockColor'], 'period'=>15.24, 'low'=>3., 'high'=>4.2, 'fade'=>.7,
				'noise'=>3.4, 'scale'=>44., 'opacity'=>.5, 'pattern'=>'gravel', 'patternColor'=>$s['rockBandColor'],
				'patternScale'=>.85, 'patternAmount'=>1.],
			['color'=>$s['soilColor'], 'datum'=>'water', 'high'=>$s['beachHeight'], 'fade'=>$s['beachWidth'],
				'noise'=>.65, 'scale'=>32., 'roughness'=>.55, 'reflectivity'=>.13],
			['color'=>$s['sandColor'], 'datum'=>'water', 'low'=>-1.2, 'high'=>$s['beachHeight']+1.5,
				'fade'=>$s['beachWidth'], 'noise'=>1.2, 'scale'=>47., 'slopeHigh'=>.024, 'slopeFade'=>.034,
				'pattern'=>'gravel', 'patternColor'=>$s['rockBandColor'], 'patternScale'=>.70, 'patternAmount'=>.9],
		];
	}

	/** Forest holds every ledge of the towers. Rain streaks the bare limestone, and the tide cuts a dark notch. */
	private static function karstLayers(array $s): array
	{
		$rock = $s['rockColor'];

		return [
			[
				'color' => $s['grassColor']
				, 'field' => 'meadow'
				, 'pattern' => 'fractal'
				, 'patternColor' => $s['meadowColor']
				, 'patternScale' => 26.
				, 'patternStretch' => 1.3
				, 'patternAmount' => .6
			]

			, [
				'mask' => 'trees'
				, 'field' => 'crowns'
				, 'channel' => 'both'
				, 'relief' => 'crowns'
				, 'strength' => .48
				, 'color' => $s['forestColor']
				, 'pattern' => 'fractal'
				, 'patternColor' => $s['meadowColor']
				, 'patternScale' => 24.
				, 'patternAmount' => .34
			]

			, self::exposedRock($s) + [
				'color' => $rock
				, 'pattern' => 'fractures'
				, 'patternColor' => self::tint($rock, .80, .81, .78)
				, 'patternScale' => 5.
				, 'patternStretch' => 1.8
				, 'patternAmount' => .5
			]

			, [
				'parent' => 'limestone-faces'
				, 'color' => $rock
				, 'opacity' => .9
				, 'pattern' => 'streaks'
				, 'patternColor' => $s['rockBandColor']
				, 'patternScale' => 9.
				, 'patternStretch' => 8.
				, 'patternAmount' => .9
			]

			, [
				'color' => self::tint($s['rockBandColor'], .62, .66, .64)
				, 'datum' => 'water'
				, 'low' => -3.
				, 'high' => 2.5
				, 'fade' => 1.2
				, 'noise' => .6
				, 'scale' => 14.
				, 'slopeLow' => .25
				, 'slopeFade' => .1
				, 'roughness' => .35
				, 'reflectivity' => .15
			]

			, [
				'color' => $s['sandColor']
				, 'datum' => 'water'
				, 'low' => -1.
				, 'high' => $s['beachHeight']
				, 'fade' => $s['beachWidth']
				, 'noise' => .8
				, 'scale' => 30.
				, 'slopeHigh' => .05
				, 'slopeFade' => .05
				, 'pattern' => 'gravel'
				, 'patternColor' => $s['soilColor']
				, 'patternScale' => .8
				, 'patternAmount' => .5
			]
		];
	}

	/**
	 * Rainforest fills the floor to the foot of the walls and scrub covers the summits above the treeline. The walls'
	 * joints and algae streaks are drawn tens of meters wide so they still read from the next table.
	 */
	private static function tepuiLayers(array $s): array
	{
		$rock = $s['rockColor'];
		$sand = $s['sandColor'];

		return [
			[
				'color' => $s['forestColor']
				, 'pattern' => 'fractal'
				, 'patternColor' => $s['meadowColor']
				, 'patternScale' => 22.
				, 'patternStretch' => 1.
				, 'patternAmount' => .55
			]

			, [
				'mask' => 'trees'
				, 'field' => 'crowns'
				, 'channel' => 'both'
				, 'relief' => 'crowns'
				, 'strength' => .55
				, 'color' => self::tint($s['forestColor'], .80, .85, .80)
				, 'pattern' => 'fractal'
				, 'patternColor' => $s['meadowColor']
				, 'patternScale' => 31.
				, 'patternAmount' => .3
			]

			, [
				'color' => $s['grassColor']
				, 'field' => 'meadow'
				, 'low' => $s['snowline'] + $s['treeline']
				, 'fade' => 90.
				, 'noise' => 40.
				, 'scale' => 260.
				, 'pattern' => 'fractal'
				, 'patternColor' => self::tint($s['grassColor'], .62, .70, .58)
				, 'patternScale' => 34.
				, 'patternStretch' => 1.2
				, 'patternAmount' => .7
			]

			, self::exposedRock($s) + [
				'color' => $rock
				, 'pattern' => 'jointed'
				, 'patternColor' => $s['rockBandColor']
				, 'patternScale' => 30.
				, 'patternAmount' => .6
			]

			, [
				'parent' => 'sandstone-walls'
				, 'color' => $rock
				, 'opacity' => .9
				, 'pattern' => 'streaks'
				, 'patternColor' => self::tint($rock, .30, .31, .33)
				, 'patternScale' => 40.
				, 'patternStretch' => 10.
				, 'patternAmount' => .9
			]

			, [
				'color' => $sand
				, 'datum' => 'water'
				, 'low' => -1.2
				, 'high' => $s['beachHeight'] + 1.
				, 'fade' => $s['beachWidth']
				, 'noise' => 1.
				, 'scale' => 40.
				, 'slopeHigh' => .03
				, 'slopeFade' => .04
				, 'pattern' => 'gravel'
				, 'patternColor' => self::tint($sand, .80, .78, .72)
				, 'patternScale' => .8
				, 'patternAmount' => .6
			]
		];
	}

	/**
	 * Fern and forest cover what holds soil. Moss mottles the fluted cliffs, and red earth shows where the steepest
	 * faces slip. The flutes are 60 m apart, so they need a strong relief to tilt the light as much as the badland
	 * rills do.
	 */
	private static function emeraldLayers(array $s): array
	{
		$rock = $s['rockColor'];
		$exposed = self::exposedRock($s);

		return [
			[
				'color' => $s['grassColor']
				, 'field' => 'meadow'
				, 'pattern' => 'fractal'
				, 'patternColor' => $s['meadowColor']
				, 'patternScale' => 28.
				, 'patternStretch' => 1.2
				, 'patternAmount' => .65
			]

			, [
				'mask' => 'trees'
				, 'field' => 'crowns'
				, 'channel' => 'both'
				, 'relief' => 'crowns'
				, 'strength' => .5
				, 'color' => $s['forestColor']
				, 'pattern' => 'fractal'
				, 'patternColor' => $s['meadowColor']
				, 'patternScale' => 26.
				, 'patternAmount' => .3
			]

			, $exposed + [
				'color' => $rock
				, 'pattern' => 'fractal'
				, 'patternColor' => $s['rockBandColor']
				, 'patternScale' => 40.
				, 'patternStretch' => 1.5
				, 'patternAmount' => .5
			]

			, [
				'color' => $s['soilColor']
				, 'slopeLow' => min(.95, $s['rockSlopeEnd'] + .22)
				, 'slopeFade' => .1
				, 'opacity' => .4
				, 'pattern' => 'fractal'
				, 'patternColor' => $rock
				, 'patternScale' => 18.
				, 'patternAmount' => .5
			]

			, [
				'color' => $s['sandColor']
				, 'datum' => 'water'
				, 'low' => -1.5
				, 'high' => $s['beachHeight']
				, 'fade' => $s['beachWidth']
				, 'slopeHigh' => .08
				, 'slopeFade' => .08
				, 'roughness' => .5
				, 'reflectivity' => .1
				, 'pattern' => 'gravel'
				, 'patternColor' => self::tint($s['sandColor'], 1.6, 1.6, 1.6)
				, 'patternScale' => .8
				, 'patternAmount' => .5
			]

			// On gentler ground the cuts wander, so the flutes start where the cliffs do.
			, [
				'channel' => 'relief'
				, 'relief' => 'ribs'
				, 'mask' => 'all'
				, 'strength' => 3.
				, 'slopeLow' => $s['rockSlopeStart']
				, 'slopeFade' => .1
			]
		];
	}

	/** Dry grass holds the flat washes with bare clay between its tufts. Rills cut down every steeper slope. */
	private static function badlandLayers(array $s): array
	{
		return [
			[
				'color' => $s['grassColor']
				, 'slopeHigh' => .025
				, 'slopeFade' => .03
				, 'opacity' => .85
				, 'pattern' => 'fractal'
				, 'patternColor' => $s['sandColor']
				, 'patternScale' => 9.
				, 'patternAmount' => .6
			]

			, ['channel' => 'relief', 'relief' => 'ribs', 'mask' => 'all', 'strength' => .8]
		];
	}

	/** Debris and blue ice show through where the wind or slope leaves little snow. */
	private static function arcticLayers(array $s): array
	{
		$ice = $s['rockBandColor'];
		$snow = $s['snowColor'];
		return [
			['color'=>$s['soilColor'], 'pattern'=>'gravel', 'patternColor'=>$s['sandColor'],
				'patternScale'=>1.3, 'patternStretch'=>1., 'patternAmount'=>.85],
			['color'=>$ice, 'low'=>8., 'fade'=>14., 'datum'=>'water', 'noise'=>21., 'scale'=>85.,
				'reflectivity'=>1., 'roughness'=>.28,
				'pattern'=>'fractures', 'patternColor'=>self::tint($ice,2.15,1.80,1.55),
				'patternScale'=>24., 'patternStretch'=>1.7, 'patternAmount'=>.83],
			self::exposedRock($s) + ['color'=>$s['rockColor'], 'pattern'=>'fractal', 'patternColor'=>$s['soilColor'],
				'patternScale'=>24., 'patternStretch'=>1.5, 'patternAmount'=>.72],
			['color'=>$snow, 'low'=>$s['snowline'], 'fade'=>$s['snowTransition'], 'noise'=>29., 'scale'=>105.,
				'slopeHigh'=>$s['snowSlopeStart'], 'slopeFade'=>$s['snowSlopeEnd']-$s['snowSlopeStart'],
				'pattern'=>'fractal', 'patternColor'=>self::tint($snow,.74,.84,.89),
				'patternScale'=>17., 'patternStretch'=>1.6, 'patternAmount'=>.55],
			['parent'=>3, 'channel'=>'relief', 'relief'=>'pattern', 'strength'=>.028,
				'pattern'=>'ripples', 'patternScale'=>1.6, 'patternStretch'=>1., 'patternAmount'=>.80],
		];
	}

	/** The seabed grades into sand flats and scrub as it rises through the waterline. */
	private static function lagoonLayers(array $s): array
	{
		$sand = $s['sandColor'];
		return [
			['color'=>$s['rockColor'], 'pattern'=>'gravel', 'patternColor'=>$s['rockBandColor'],
				'patternScale'=>4.5, 'patternStretch'=>1., 'patternAmount'=>.82],
			['color'=>self::tint($sand,.60,.74,.74), 'datum'=>'water', 'low'=>-8., 'fade'=>5.,
				'noise'=>3., 'scale'=>55.] + self::stipple($sand,.51,.65,1.1),
			['color'=>self::tint($sand,.67,.78,.72), 'datum'=>'water', 'low'=>-8., 'high'=>-2.,
				'fade'=>1.7, 'noise'=>3.3, 'scale'=>36., 'pattern'=>'fractal', 'patternColor'=>$s['soilColor'],
				'patternScale'=>23., 'patternStretch'=>1.2, 'patternAmount'=>.89],
			['color'=>$sand, 'datum'=>'water', 'low'=>$s['beachHeight'], 'fade'=>$s['beachWidth'], 'noise'=>.6, 'scale'=>28.]
				+ self::stipple($sand,.78,.61,.9),
			['color'=>self::tint($sand,.48,.60,.40), 'datum'=>'water', 'low'=>$s['beachHeight']+.55*$s['beachWidth'],
				'fade'=>.55*$s['beachWidth'], 'noise'=>1.7, 'scale'=>42., 'pattern'=>'fractal',
				'patternColor'=>$s['grassColor'], 'patternScale'=>4.2, 'patternAmount'=>.78],
			['color'=>$s['grassColor'], 'datum'=>'water', 'low'=>$s['beachHeight']+1.35*$s['beachWidth'],
				'fade'=>.70*$s['beachWidth'], 'noise'=>3.0, 'scale'=>54., 'pattern'=>'fractal',
				'patternColor'=>self::tint($s['grassColor'],.62,.73,.72), 'patternAmount'=>.92, 'patternScale'=>8.5],
			['parent'=>1, 'datum'=>'water', 'high'=>.7, 'fade'=>.7, 'channel'=>'relief', 'relief'=>'pattern',
				'strength'=>.023, 'pattern'=>'ripples', 'patternScale'=>.64, 'patternStretch'=>1., 'patternAmount'=>.72],
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

	/** Pack the matching std140 JourneyPalette in journey-palette.glsl, padding every array entry to a vec4. */
	public function journeyBlock(array $form, array $origin): string
	{
		$f = $this->fieldUniforms;
		$seed = crc32((string)$form['seed']);
		$values = [...$origin,$f['uMacroVariation'][0], ...$f['uSlopeBlend'], ...$f['uHeightBlend'],
			...$f['uSnowline'], ...$f['uMountainZones'], ...$f['uMountainAmounts'],$form['damping']/$form['slopeScale']**2,
			...$f['uFieldScale'], $seed & 65535,($seed >> 16) & 65535,count($this->layers),0., ...$this->bounce,0.];
		foreach([[$this->colors,3],[$this->ranges,4],[$this->blends,4],[$this->modes,4],[$this->effects,4],
			[$this->finishes,2],[$this->patterns,4],[$this->patternColors,3]] as [$array,$components]) {
			$padded = [];
			foreach(array_chunk($array,$components) as $row) array_push($padded,...array_pad($row,4,0.));
			array_push($values,...array_pad($padded,self::LIMIT*4,0.));
		}
		return pack('g*',...$values);
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
		if($this->reflective) glUniform2fv($p->location('uLayerFinish[0]'), $n, $this->finishes);
		glUniform4fv($p->location('uLayerPattern[0]'), $n, $this->patterns);
		glUniform3fv($p->location('uLayerPatternColor[0]'), $n, $this->patternColors);
	}
}
