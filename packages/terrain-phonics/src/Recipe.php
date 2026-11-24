<?php
declare(strict_types=1);
namespace TerrainPhonics;

/** Validated serializable terrain recipe and independent coarse slope guide. */
final class Recipe
{
	/** Named sets of coefficients for the shared terrain field. */
	public const PRESETS = [
		'coast'
		, 'mountain'
		, 'canyon'
		, 'limestone'
		, 'basalt-fjords'
		, 'amber-dunes'
		, 'arctic-meltwater'
		, 'tidal-lagoon'
		, 'red-mesas', 'heather-moor', 'salt-pans', 'cinder-highlands'
		, 'rolling-prairie', 'river-valley', 'wooded-foothills', 'high-desert-steppe'
		, 'alaska-range', 'chalk-headlands'
		, 'karst-bay', 'tepui-highlands', 'painted-badlands', 'emerald-ridges'
	];

	/** Parameters are immutable after normalization. */
	private array $values;
	/** One deterministic noise source, sampled with distinct octave offsets. */
	private Noise $noise;
	private float $wallStart = .055;
	private float $wallWidth = .006;
	private bool $layered = false;
	private ?array $axes = null;
	private ?array $coast = null;
	private float $detailShape = 0., $detailLinear = 1., $gullyDepth = 0.;
	private array $octaveSteps = [];
	/** Lattice hash of the noise source. coarse() and height() read these tables directly to avoid Noise calls. */
	private array $permutation;
	private array $gradientX;
	private array $gradientZ;
	/**
	 * Squared lattice radius inside which coarse() and height() expand the noise in place. Both coordinates of
	 * a point inside it lie strictly between -2 ** 29 and 2 ** 29, so their integer casts and cell indices fit
	 * the 32-bit integers of browser PHP. The Noise methods handle every point outside it, including the
	 * non-finite ones they reject.
	 */
	private const REACH = 288230376151711744.0;

	/** Initial parameters used by the Landscape Lab and command-line preview. */
	public static function showcase(string $preset): array
	{
		return ['preset'=>$preset]+match($preset) {
			'mountain' => ['heightScale'=>1400.,'baseFrequency'=>1/2200],
			'canyon' => ['heightScale'=>650.,'baseFrequency'=>1/4000,'terraceSpacing'=>80.,'waterLevel'=>-450.],
			default => [],
		};
	}

	/** Shape coefficients copied by preset selection and by the legacy recipe loader. */
	public static function shapeDefaults(string $preset, int $version = 2): array
	{
		// A sharp divide is cut by crossing spurs. Lift the outer valley flanks to leave open troughs between divides.
		if($preset === 'alaska-range')
		return [
			'seed'=>'alaska-range', 'baseFrequency'=>1 / 4270, 'heightScale'=>3048., 'baseBias'=>.62,
			'ridgeHeight'=>1.65, 'ridgeRoundness'=>.014, 'rolling'=>.14, 'secondary'=>.74,
			'wallHeight'=>.25, 'floorWidth'=>.21, 'wallWidth'=>.46, 'directionalRise'=>.045,
			'warpAmplitude'=>609.6, 'warpFrequency'=>1 / 2750,
			'detailAmplitude'=>195.072, 'detailOctaves'=>6, 'persistence'=>.36, 'slopeScale'=>1.35, 'damping'=>.48,
			'terraceStrength'=>0., 'waterLevel'=>0., 'surround'=>'mountains',
		] + self::shapeDefaults('coast', $version);

		// The sea floods the foot of a narrow scarp. The rolling upper surface continues inland as chalk downs.
		if($preset === 'chalk-headlands')
		return [
			'seed'=>'chalk-headlands', 'baseFrequency'=>1 / 1850, 'heightScale'=>121.92, 'baseBias'=>-.16,
			'wallHeight'=>.95, 'floorWidth'=>.19, 'wallWidth'=>.058, 'rolling'=>.38, 'secondary'=>.18,
			'directionalRise'=>.28, 'warpAmplitude'=>228.6, 'warpFrequency'=>1 / 2400,
			'detailAmplitude'=>2.4384, 'detailOctaves'=>5, 'persistence'=>.44, 'terraceStrength'=>0.,
			'waterLevel'=>0., 'surround'=>'sea',
		] + self::shapeDefaults('coast', $version);

		// Narrow walls close the contour rings into steep towers. The sea floods the floor between them.
		if($preset === 'karst-bay')
		return [
			'seed' => 'karst-bay'
			, 'baseFrequency' => 1 / 300
			, 'heightScale' => 260.
			, 'baseBias' => 0.
			, 'rolling' => 0.

			, 'wallHeight' => 1.
			, 'floorWidth' => .32
			, 'wallWidth' => .10
			, 'secondary' => .25
			, 'directionalRise' => 0.

			, 'warpAmplitude' => 120.
			, 'warpFrequency' => 1 / 900

			, 'detailAmplitude' => 18.
			, 'detailOctaves' => 6
			, 'terraceStrength' => 0.

			, 'waterLevel' => 18.
			, 'surround' => 'sea'
		] + self::shapeDefaults('coast', $version);

		// Broad contour rings with sheer walls leave flat-topped tables a kilometer above a forest floor.
		if($preset === 'tepui-highlands')
		return [
			'seed' => 'tepui-highlands'
			, 'baseFrequency' => 1 / 4000
			, 'heightScale' => 1000.
			, 'baseBias' => 0.
			, 'rolling' => 0.

			, 'wallHeight' => 1.
			, 'floorWidth' => .22
			, 'wallWidth' => .02
			, 'secondary' => .12
			, 'directionalRise' => 0.

			, 'warpAmplitude' => 700.
			, 'warpFrequency' => 1 / 3300

			, 'detailAmplitude' => 30.
			, 'detailOctaves' => 6
			, 'terraceStrength' => 0.

			, 'waterLevel' => -30.
			, 'surround' => 'plateau'
		] + self::shapeDefaults('coast', $version);

		// Sharp crests follow the zero contours of the base field and wind over clusters of low mounds, which leaves
		// flat washes between them. Thin beds band every slope like the mesa walls.
		if($preset === 'painted-badlands')
		return [
			'seed' => 'painted-badlands'
			, 'baseFrequency' => 1 / 222
			, 'heightScale' => 120.
			, 'baseBias' => 0.
			, 'rolling' => .15

			, 'ridgeHeight' => 1.6
			, 'ridgeRoundness' => .008
			, 'wallHeight' => .6
			, 'floorWidth' => .12
			, 'wallWidth' => .45
			, 'secondary' => .35
			, 'directionalRise' => 0.

			, 'warpAmplitude' => 60.
			, 'warpFrequency' => 1 / 300

			, 'detailAmplitude' => 3.
			, 'detailOctaves' => 7
			, 'persistence' => .6
			, 'slopeScale' => 1.
			, 'damping' => 0.
			, 'terraceSpacing' => 8.
			, 'terraceStrength' => .3
			, 'bedding' => 1.

			, 'waterLevel' => -300.
			, 'surround' => 'plateau'
		] + self::shapeDefaults('coast', $version);

		// Knife-edged crests follow the zero contours of the base field. The land falls toward negative x, so the sea
		// floods the valleys between the ridges and leaves steep green walls standing in it.
		if($preset === 'emerald-ridges')
		return [
			'seed' => 'emerald-ridges'
			, 'baseFrequency' => 1 / 1100
			, 'heightScale' => 1000.
			, 'baseBias' => .35
			, 'rolling' => .1

			, 'ridgeHeight' => 1.6
			, 'ridgeRoundness' => .003
			, 'wallHeight' => 0.
			, 'secondary' => .4
			, 'directionalRise' => .5

			, 'warpAmplitude' => 250.
			, 'warpFrequency' => 1 / 2000

			, 'detailAmplitude' => 12.
			, 'detailOctaves' => 6
			, 'persistence' => .45
			, 'slopeScale' => 1.
			, 'damping' => .5
			, 'terraceStrength' => 0.

			, 'waterLevel' => 0.
			, 'surround' => 'sea'
		] + self::shapeDefaults('coast', $version);

		if($preset === 'basalt-fjords')
		return [
			'seed' => 'basalt-fjords', 'baseFrequency' => 1 / 2600, 'heightScale' => 1160., 'baseBias' => .055,
			'wallHeight' => .70, 'floorWidth' => .038, 'wallWidth' => .40, 'rolling' => .67,
			'secondary' => .43, 'directionalRise' => .25, 'warpAmplitude' => 445., 'warpFrequency' => 1 / 3450,
			'terraceSpacing' => 76., 'terraceStrength' => 0., 'detailAmplitude' => 42., 'detailOctaves' => 6,
			'slopeScale' => .60,
		] + self::shapeDefaults('coast', $version);
		if($preset === 'amber-dunes')
		return [
			'seed' => 'amber-dunes', 'baseFrequency' => 1 / 950, 'heightScale' => 435., 'baseBias' => .50,
			'ridgeHeight' => 1.12, 'ridgeRoundness' => .018, 'rolling' => .38, 'secondary' => .28,
			'directionalRise' => 0., 'warpAmplitude' => 105., 'warpFrequency' => 1 / 2400,
			'detailAmplitude' => 2., 'detailOctaves' => 4, 'waterLevel' => -600., 'surround' => 'mountains',
		] + self::shapeDefaults('coast', $version);

		// The canyon's wall turned inside out: a wide floor leaves the plain low, and only the contour field's highest
		// and lowest values rise past a narrow wall to flat, bedded tops, about 9% of the ground.
		if($preset === 'red-mesas')
		return [
			'seed' => 'red-mesas'
			, 'baseFrequency' => 1 / 3200
			, 'heightScale' => 340.
			, 'baseBias' => 0.
			, 'rolling' => 0.
			, 'ridgeHeight' => 0.
			, 'wallHeight' => 1.
			, 'floorWidth' => .35
			, 'wallWidth' => .033
			, 'secondary' => .06
			, 'directionalRise' => 0.
			, 'warpAmplitude' => 380.
			, 'warpFrequency' => 1 / 2600
			, 'terraceSpacing' => 42.
			, 'terraceStrength' => .9
			, 'bedding' => 1.
			, 'detailAmplitude' => 3.
			, 'detailOctaves' => 5
			, 'waterLevel' => -500.
			, 'surround' => 'plateau'
		] + self::shapeDefaults('coast', $version);

		// Rounded upland ridges over a broad swell, with peat lochans in the lowest hollows.
		if($preset === 'heather-moor')
		return [
			'seed' => 'heather-moor'
			, 'baseFrequency' => 1 / 2600
			, 'heightScale' => 300.
			, 'baseBias' => .12
			, 'rolling' => .6
			, 'ridgeHeight' => .25
			, 'ridgeRoundness' => .08
			, 'wallHeight' => 0.
			, 'secondary' => .5
			, 'directionalRise' => 0.
			, 'warpAmplitude' => 260.
			, 'warpFrequency' => 1 / 3000
			, 'terraceStrength' => 0.
			, 'detailAmplitude' => 7.
			, 'detailOctaves' => 6
			, 'persistence' => .5
			, 'waterLevel' => -50.
			, 'surround' => 'mountains'
		] + self::shapeDefaults('coast', $version);

		// A wide, nearly level basin floor gives way to alluvial fans and a distant rim.
		if($preset === 'salt-pans')
		return [
			'seed'=>'salt-pans', 'baseFrequency'=>1 / 3450, 'heightScale'=>1300., 'baseBias'=>.004,
			'floorWidth'=>.24, 'wallWidth'=>.55, 'wallHeight'=>.70, 'rolling'=>.012, 'secondary'=>.009,
			'directionalRise'=>0., 'warpAmplitude'=>430., 'warpFrequency'=>1 / 1475,
			'detailAmplitude'=>.95, 'detailOctaves'=>4, 'persistence'=>.43, 'terraceStrength'=>0.,
			'waterLevel'=>1.8, 'surround'=>'mountains',
		] + self::shapeDefaults('coast', $version);

		// Rounded cones and long spurs rise through a broken volcanic apron.
		if($preset === 'cinder-highlands')
		return [
			'seed'=>'cinder-highlands', 'baseFrequency'=>1 / 2000, 'heightScale'=>1110., 'baseBias'=>.10,
			'floorWidth'=>.09, 'wallWidth'=>.74, 'wallHeight'=>.86, 'rolling'=>.32, 'secondary'=>.16,
			'directionalRise'=>.075, 'warpAmplitude'=>305., 'warpFrequency'=>1 / 1750,
			'detailAmplitude'=>15., 'detailOctaves'=>6, 'persistence'=>.47, 'terraceStrength'=>0.,
			'waterLevel'=>-210., 'surround'=>'mountains',
		] + self::shapeDefaults('coast', $version);

		// A high contour threshold leaves occasional buttes standing above the grass swells.
		if($preset === 'rolling-prairie')
		return [
			'seed'=>'rolling-prairie', 'baseFrequency'=>1 / 2100, 'heightScale'=>152.4, 'baseBias'=>.60,
			'rolling'=>.82, 'secondary'=>.17, 'directionalRise'=>.06,
			'wallHeight'=>.64, 'floorWidth'=>.46, 'wallWidth'=>.14,
			'warpAmplitude'=>121.92, 'warpFrequency'=>1 / 3100, 'detailAmplitude'=>1.524,
			'detailOctaves'=>4, 'persistence'=>.40, 'waterLevel'=>-30.48, 'surround'=>'none',
		] + self::shapeDefaults('coast', $version);

		// Flood the lowest part of the contour field. Gentle terraces form the banks above it.
		if($preset === 'river-valley')
		return [
			'seed'=>'river-valley', 'baseFrequency'=>1 / 1950, 'heightScale'=>304.8, 'baseBias'=>-.017,
			'rolling'=>.12, 'secondary'=>.012, 'directionalRise'=>0.,
			'wallHeight'=>.85, 'floorWidth'=>.03, 'wallWidth'=>.52,
			'warpAmplitude'=>487.68, 'warpFrequency'=>1 / 2300, 'terraceSpacing'=>9.144, 'terraceStrength'=>.32,
			'detailAmplitude'=>.6096, 'detailOctaves'=>4, 'persistence'=>.40, 'waterLevel'=>0., 'surround'=>'none',
		] + self::shapeDefaults('coast', $version);

		if($preset === 'wooded-foothills')
		return [
			'seed'=>'wooded-foothills', 'baseFrequency'=>1 / 2250, 'heightScale'=>518.16, 'baseBias'=>.85,
			'rolling'=>.78, 'secondary'=>.26, 'ridgeHeight'=>.48, 'ridgeRoundness'=>.12, 'directionalRise'=>.24,
			'wallHeight'=>.55, 'floorWidth'=>.20, 'wallWidth'=>.54,
			'warpAmplitude'=>365.76, 'warpFrequency'=>1 / 2700, 'detailAmplitude'=>6.096,
			'detailOctaves'=>5, 'persistence'=>.45, 'waterLevel'=>0., 'surround'=>'mountains',
		] + self::shapeDefaults('coast', $version);

		// An elevated apron cut by dry washes, with gravel benches on the climb to the rim.
		if($preset === 'high-desert-steppe')
		return [
			'seed'=>'high-desert-steppe', 'baseFrequency'=>1 / 2750, 'heightScale'=>609.6, 'baseBias'=>1.25,
			'rolling'=>.28, 'secondary'=>.12, 'directionalRise'=>.15,
			'wallHeight'=>.52, 'floorWidth'=>.14, 'wallWidth'=>.49,
			'warpAmplitude'=>274.32, 'warpFrequency'=>1 / 2400, 'terraceSpacing'=>18.288, 'terraceStrength'=>.24,
			'detailAmplitude'=>3.048, 'detailOctaves'=>5, 'persistence'=>.43, 'waterLevel'=>0., 'surround'=>'none',
		] + self::shapeDefaults('coast', $version);

		if($preset === 'arctic-meltwater')
		return [
			'seed' => 'arctic-meltwater', 'baseFrequency' => 1 / 1550, 'heightScale' => 340., 'baseBias' => -.052,
			'wallHeight' => .47, 'floorWidth' => .075, 'wallWidth' => .18, 'rolling' => .11,
			'ridgeHeight' => .12, 'ridgeRoundness' => .07, 'secondary' => .17, 'directionalRise' => .035,
			'warpAmplitude' => 305., 'warpFrequency' => 1 / 2600, 'detailAmplitude' => 9., 'detailOctaves' => 5,
			'terraceStrength' => 0., 'surround' => 'mountains',
		] + self::shapeDefaults('coast', $version);
		if($preset === 'tidal-lagoon')
		return [
			'seed' => 'tidal-lagoon', 'baseFrequency' => 1 / 740, 'heightScale' => 70., 'baseBias' => .093,
			'ridgeHeight' => .82, 'ridgeRoundness' => .035, 'rolling' => .12, 'secondary' => .32,
			'directionalRise' => .045, 'warpAmplitude' => 125., 'warpFrequency' => 1 / 1650,
			'detailAmplitude' => 1.2, 'detailOctaves' => 4, 'persistence' => .45, 'terraceStrength' => 0.,
		] + self::shapeDefaults('coast', $version);
		if($preset === 'limestone')
		return [
			'seed' => 'limestone-plain', 'heightScale' => 3950., 'baseFrequency' => 1 / 3050, 'floorWidth' => .18, 'wallWidth' => .91,
			'wallHeight' => 1., 'rolling' => 0., 'secondary' => 0., 'directionalRise' => 0.,
			'warpAmplitude' => 120., 'warpFrequency' => 1 / 3800, 'detailAmplitude' => 1.2, 'detailOctaves' => 4,
			'terraceSpacing' => 175., 'terraceStrength' => .44, 'waterLevel' => -40., 'surround' => 'mountains'
		] + self::shapeDefaults('coast', $version);

		$coast = $preset === 'coast';
		$canyon = $preset === 'canyon';
		return [
			'baseBias' => $coast ? 0.0 : ($canyon ? -.55 : .35),
			'rolling' => $coast ? .6 : 0.0, 'directionalRise' => $coast ? .35 : 0.0,
			'ridgeHeight' => $coast || $canyon ? 0.0 : 1.0, 'ridgeRoundness' => .04,
			'wallHeight' => $canyon ? 1.0 : 0.0, 'floorWidth' => .055, 'wallWidth' => .006,
			'secondary' => $coast || $canyon ? .15 : .24,
			'bedding' => $canyon && $version === 2 ? 1.0 : 0.0,
			'surround' => $coast ? 'sea' : ($canyon ? 'plateau' : 'mountains'), 'transitionWidth' => 2048.0
		];
	}

	/**
	 * Validate a bounded world recipe before allocating any terrain samples.
	 * @param array $values Optional recipe fields overriding the documented defaults.
	 * @return void Validates parameters before generating any terrain.
	 */
	public function __construct(array $values = [])
	{
		$preset = array_key_exists('preset', $values) ? $values['preset'] : 'mountain';
		$canyon = $preset === 'canyon';
		$version = array_key_exists('version', $values) ? $values['version'] : (in_array($preset, ['mountain','coast'], true) ? 1 : 2);
		$defaults = [
			'version' => $version
			, 'seed' => 'first-light'
			, 'preset' => 'mountain'

			, 'worldSize' => 32768.0
			, 'spacing' => 1.0
			, 'tileSize' => 8
			, 'maxTiles' => 2048
			, 'waterLevel' => 0.0
			, 'heightScale' => 420.0
			, 'baseFrequency' => 1 / 6000
			, 'noiseOffsetX' => 0.0
			, 'noiseOffsetZ' => 0.0
			, 'domainRotation' => 0., 'ridgeStretch' => 1., 'ridgeBearing' => 0.
			, 'coastBearing' => 0., 'coastWidth' => 4500.
			, 'detailShape' => 0., 'gullyDepth' => 0., 'gullySpacing' => 80.

			, 'detailOctaves' => 8
			, 'detailAmplitude' => 28.0
			, 'lacunarity' => 2.0
			, 'persistence' => .5
			, 'slopeScale' => .45
			, 'damping' => 2.0

			, 'warpAmplitude' => 200.0
			, 'warpFrequency' => 1 / 10000
			, 'terraceSpacing' => 0.0
			, 'terraceStrength' => $canyon && $version === 2 ? .92 : .7
		];

		if(!in_array($preset, self::PRESETS, true))
		throw new \InvalidArgumentException('Unknown terrain preset. Expected one of ' . implode(', ', self::PRESETS));
		if(!in_array($version, [1,2], true)) throw new \InvalidArgumentException('Unsupported terrain recipe version');
		$defaults = self::shapeDefaults($preset, $version) + $defaults;
		if(array_diff_key($values, $defaults)) throw new \InvalidArgumentException('Unknown terrain recipe field');

		$values += $defaults;

		if(!is_string($values['seed']) && !is_int($values['seed']))
		throw new \InvalidArgumentException('Invalid terrain seed');

		$values['seed'] = (string)$values['seed'];

		if(strlen($values['seed']) > 256) throw new \InvalidArgumentException('Terrain seed is too long');

		if(!in_array($values['surround'], ['none','sea','plateau','mountains'], true))
		throw new \InvalidArgumentException('Unknown terrain surround');

		foreach([
			'baseBias' => [-2,2], 'rolling' => [0,2], 'directionalRise' => [-2,2], 'ridgeHeight' => [0,2],
			'ridgeRoundness' => [.001,1], 'wallHeight' => [0,2], 'floorWidth' => [.03,1],
			'wallWidth' => [.001,1], 'secondary' => [0,2], 'bedding' => [0,1], 'transitionWidth' => [1,65536],
			'worldSize' => [16,65536]
			, 'spacing' => [.25,32]
			, 'waterLevel' => [-10000,10000]
			, 'heightScale' => [0,10000]
			, 'baseFrequency' => [1 / 1000000,1]
			, 'noiseOffsetX' => [-64373.76,64373.76]
			, 'noiseOffsetZ' => [-64373.76,64373.76]
			, 'domainRotation' => [-360,360], 'ridgeStretch' => [1,12], 'ridgeBearing' => [-360,360]
			, 'coastBearing' => [-360,360], 'coastWidth' => [10,65536]
			, 'detailShape' => [-1,1], 'gullyDepth' => [0,64], 'gullySpacing' => [8,1024]

			, 'detailAmplitude' => [0,1000]
			, 'lacunarity' => [1.1,4]
			, 'persistence' => [0,1]
			, 'slopeScale' => [.001,100]
			, 'damping' => [0,100]

			, 'warpAmplitude' => [0,5000]
			, 'warpFrequency' => [1 / 1000000,.01]
			, 'terraceSpacing' => [0,1000]
			, 'terraceStrength' => [0,1]
		] as $key => [$min,$max])
		{
			$value = $values[$key];

			if((!is_int($value) && !is_float($value)) || !is_finite((float)$value) || $value < $min || $value > $max)
			throw new \InvalidArgumentException('Invalid terrain ' . $key);

			$values[$key] = (float)$value;
		}

		foreach(['tileSize' => [4,32], 'maxTiles' => [4,16384], 'detailOctaves' => [0,12]] as $key => [$min,$max])
		{
			if(!is_int($values[$key]) || $values[$key] < $min || $values[$key] > $max)
			throw new \InvalidArgumentException('Invalid terrain ' . $key);
		}

		// Landscape keys its tile cache by row and column within Landscape::TILE_REACH of the origin.
		if($values['worldSize'] / 2 / ($values['spacing'] * $values['tileSize']) > Landscape::TILE_REACH)
		throw new \InvalidArgumentException('Terrain tiles are too fine for this world size; raise spacing or tileSize');

		$this->layered = $values['bedding'] > 0 && $values['terraceSpacing'] > 0 && $values['terraceStrength'] > 0;
		$this->wallWidth = $values['wallWidth'];
		$this->wallStart = $values['floorWidth'];
		if($this->layered)
		{
			// Allow space across the wall for shelves while preserving the unstepped contour at zero strength.
			$this->wallStart -= .025 * $values['terraceStrength'] * $values['bedding'];
			$this->wallWidth += .105 * $values['terraceStrength'] * $values['bedding'];
		}
		$this->values = $values;
		if($values['domainRotation'] != 0 || $values['ridgeStretch'] != 1) $this->axes = self::axes($values);
		if($values['coastBearing'] != 0 || $values['coastWidth'] != 4500 || $values['ridgeStretch'] != 1)
			$this->coast = ($this->axes ?? self::axes($values))['coast'];
		$this->detailShape = $values['detailShape'];
		$this->detailLinear = 1-abs($this->detailShape);
		$this->gullyDepth = $values['gullyDepth'];
		$frequency = $values['baseFrequency']*8;
		$amplitude = $values['detailAmplitude'];
		for($i=0;$i<$values['detailOctaves'] && $amplitude>0.;$i++) {
			$this->octaveSteps[] = [$frequency,$amplitude,$values['damping']*($i+1)*($i+1),$i*19.17,$i*31.29];
			$frequency *= $values['lacunarity']; $amplitude *= $values['persistence'];
		}
		$this->noise = new Noise($values['seed']);
		[$this->permutation, $this->gradientX, $this->gradientZ] = $this->noise->lattice();
	}

	/**
	 * Return the portable normalized parameters, without cached samples.
	 * @return array Normalized portable recipe parameters.
	 */
	public function values(): array { return $this->values; }

	/**
	 * Evaluate the C2 macro field, including world-space first derivatives.
	 *
	 * A node sampled through the Noise methods makes over a hundred calls, and calls are the main cost of PHP
	 * in the browser. Each sample here is therefore Noise::sample() written out with the same operations in
	 * the same order, which returns the same bits. A scalar suffixed 0, 1 or 2 holds a sample's value, X
	 * derivative or Z derivative. An integer cast stepped down below a negative fraction replaces floor().
	 * @param float $x World X coordinate in meters.
	 * @param float $z World Z coordinate in meters.
	 * @return array Coarse height and world-space derivatives [height,dx,dz].
	 */
	public function coarse(float $x, float $z, ?array $profile = null): array
	{
		$x += $this->values['noiseOffsetX'];
		$z += $this->values['noiseOffsetZ'];
		if(!$this->axes) return $this->coarseAt($x,$z,$profile);
		[$a,$b,$c,$d] = $this->axes['macro'];
		[$h,$dx,$dz] = $this->coarseAt($a*$x+$b*$z,$c*$x+$d*$z,$profile);
		return [$h,$a*$dx+$c*$dz,$b*$dx+$d*$dz];
	}

	/** Pull world coordinates into the rotated seed and stretch the macro field along its bearing. */
	public static function axes(array $r): array
	{
		$t = deg2rad($r['domainRotation']); $u = cos($t); $v = sin($t);
		$t = deg2rad($r['ridgeBearing']); $x = cos($t); $z = sin($t);
		$k = 1/$r['ridgeStretch']-1;
		$a = 1+$k*$x*$x; $b = $k*$x*$z; $d = 1+$k*$z*$z;
		// Undo the stretch for the coast term so its bearing and width remain independent.
		$t = deg2rad($r['coastBearing']); $cx = cos($t); $cz = sin($t);
		$p = ($r['ridgeStretch']-1)*($cx*$x+$cz*$z);
		// Store identical coefficients in the PHP evaluator and the binary32 shader uniforms.
		$float = static fn(float $n): float => unpack('g',pack('g',$n))[1];
		return ['macro'=>array_map($float,[$a*$u-$b*$v,$a*$v+$b*$u,$b*$u-$d*$v,$b*$v+$d*$u]),
			'rotation'=>array_map($float,[$u,$v]), 'coast'=>[($cx+$p*$x)/$r['coastWidth'],($cz+$p*$z)/$r['coastWidth']]];
	}

	/** Evaluate at coordinates already translated into the seeded field. */
	private function coarseAt(float $x, float $z, ?array $profile): array
	{
		$r = $this->values;
		$f = $r['baseFrequency'];
		$scale = $r['heightScale'];

		if($scale === 0.0) return [0.0,0.0,0.0];

		$datum = $this->layered ? CanyonBeds::DIP[0]*$x + CanyonBeds::DIP[1]*$z : 0;
		// Warp Jacobian in row order. It stays the identity without a warp.
		$j0 = $j3 = 1.0;
		$j1 = $j2 = 0.0;
		$p = $this->permutation;
		$gx = $this->gradientX;
		$gz = $this->gradientZ;

		if($r['warpAmplitude'] > 0)
		{
			$wf = $r['warpFrequency'];
			$wa = $r['warpAmplitude'];
			$sx = $x * $wf + 271.13;
			$sz = $z * $wf - 197.41;

			// Warp along X: Noise::sample($sx, $sz) written out.
			if($sx * $sx + $sz * $sz < self::REACH)
			{
				$ix = (int)$sx; $iz = (int)$sz;

				if($sx < $ix) $ix--;

				if($sz < $iz) $iz--;

				$sx -= $ix; $sz -= $iz; $ix &= 255; $iz &= 255;
				$h = $p[$ix] + $iz; $k = $p[$ix + 1] + $iz;
				$tx = $sx - 1; $tz = $sz - 1;
				$u = $sx * $sx * $sx * ($sx * ($sx * 6 - 15) + 10);
				$v = $sz * $sz * $sz * ($sz * ($sz * 6 - 15) + 10);
				$du = 30 * $sx * $sx * $tx * $tx;

				$lx = $gx[$h]; $lz = $gz[$h]; $rx = $gx[$k]; $rz = $gz[$k];
				$left = $lx * $sx + $lz * $sz;
				$span = $rx * $tx + $rz * $sz - $left;
				$near = $left + $u * $span;
				$nearX = $lx + $u * ($rx - $lx) + $du * $span;
				$nearZ = $lz + $u * ($rz - $lz);

				$h++; $k++;
				$lx = $gx[$h]; $lz = $gz[$h]; $rx = $gx[$k]; $rz = $gz[$k];
				$left = $lx * $sx + $lz * $tz;
				$span = $rx * $tx + $rz * $tz - $left;
				$rise = $left + $u * $span - $near;
				$wx0 = $near + $v * $rise;
				$wx1 = $nearX + $v * ($lx + $u * ($rx - $lx) + $du * $span - $nearX);
				$wx2 = $nearZ + $v * ($lz + $u * ($rz - $lz) - $nearZ) + 30 * $sz * $sz * $tz * $tz * $rise;
			}
			else [$wx0,$wx1,$wx2] = $this->noise->sample($sx, $sz);

			$sx = $x * $wf - 419.27;
			$sz = $z * $wf + 113.71;

			// Warp along Z: Noise::sample($sx, $sz) written out.
			if($sx * $sx + $sz * $sz < self::REACH)
			{
				$ix = (int)$sx; $iz = (int)$sz;

				if($sx < $ix) $ix--;

				if($sz < $iz) $iz--;

				$sx -= $ix; $sz -= $iz; $ix &= 255; $iz &= 255;
				$h = $p[$ix] + $iz; $k = $p[$ix + 1] + $iz;
				$tx = $sx - 1; $tz = $sz - 1;
				$u = $sx * $sx * $sx * ($sx * ($sx * 6 - 15) + 10);
				$v = $sz * $sz * $sz * ($sz * ($sz * 6 - 15) + 10);
				$du = 30 * $sx * $sx * $tx * $tx;

				$lx = $gx[$h]; $lz = $gz[$h]; $rx = $gx[$k]; $rz = $gz[$k];
				$left = $lx * $sx + $lz * $sz;
				$span = $rx * $tx + $rz * $sz - $left;
				$near = $left + $u * $span;
				$nearX = $lx + $u * ($rx - $lx) + $du * $span;
				$nearZ = $lz + $u * ($rz - $lz);

				$h++; $k++;
				$lx = $gx[$h]; $lz = $gz[$h]; $rx = $gx[$k]; $rz = $gz[$k];
				$left = $lx * $sx + $lz * $tz;
				$span = $rx * $tx + $rz * $tz - $left;
				$rise = $left + $u * $span - $near;
				$wz0 = $near + $v * $rise;
				$wz1 = $nearX + $v * ($lx + $u * ($rx - $lx) + $du * $span - $nearX);
				$wz2 = $nearZ + $v * ($lz + $u * ($rz - $lz) - $nearZ) + 30 * $sz * $sz * $tz * $tz * $rise;
			}
			else [$wz0,$wz1,$wz2] = $this->noise->sample($sx, $sz);

			$x += $wa * $wx0;
			$z += $wa * $wz0;
			$j0 = 1 + $wa * $wf * $wx1;
			$j1 = $wa * $wf * $wx2;
			$j2 = $wa * $wf * $wz1;
			$j3 = 1 + $wa * $wf * $wz2;
		}

		$sx = $x * $f + 13.17;
		$sz = $z * $f - 9.31;

		// Contour field: Noise::sample($sx, $sz) written out.
		if($sx * $sx + $sz * $sz < self::REACH)
		{
			$ix = (int)$sx; $iz = (int)$sz;

			if($sx < $ix) $ix--;

			if($sz < $iz) $iz--;

			$sx -= $ix; $sz -= $iz; $ix &= 255; $iz &= 255;
			$h = $p[$ix] + $iz; $k = $p[$ix + 1] + $iz;
			$tx = $sx - 1; $tz = $sz - 1;
			$u = $sx * $sx * $sx * ($sx * ($sx * 6 - 15) + 10);
			$v = $sz * $sz * $sz * ($sz * ($sz * 6 - 15) + 10);
			$du = 30 * $sx * $sx * $tx * $tx;

			$lx = $gx[$h]; $lz = $gz[$h]; $rx = $gx[$k]; $rz = $gz[$k];
			$left = $lx * $sx + $lz * $sz;
			$span = $rx * $tx + $rz * $sz - $left;
			$near = $left + $u * $span;
			$nearX = $lx + $u * ($rx - $lx) + $du * $span;
			$nearZ = $lz + $u * ($rz - $lz);

			$h++; $k++;
			$lx = $gx[$h]; $lz = $gz[$h]; $rx = $gx[$k]; $rz = $gz[$k];
			$left = $lx * $sx + $lz * $tz;
			$span = $rx * $tx + $rz * $tz - $left;
			$rise = $left + $u * $span - $near;
			$a0 = $near + $v * $rise;
			$a1 = $nearX + $v * ($lx + $u * ($rx - $lx) + $du * $span - $nearX);
			$a2 = $nearZ + $v * ($lz + $u * ($rz - $lz) - $nearZ) + 30 * $sz * $sz * $tz * $tz * $rise;
		}
		else [$a0,$a1,$a2] = $this->noise->sample($sx, $sz);

		$sx = $x * $f * 2 + 71.43;
		$sz = $z * $f * 2 + 53.11;

		// Broad relief: Noise::sample($sx, $sz) written out.
		if($sx * $sx + $sz * $sz < self::REACH)
		{
			$ix = (int)$sx; $iz = (int)$sz;

			if($sx < $ix) $ix--;

			if($sz < $iz) $iz--;

			$sx -= $ix; $sz -= $iz; $ix &= 255; $iz &= 255;
			$h = $p[$ix] + $iz; $k = $p[$ix + 1] + $iz;
			$tx = $sx - 1; $tz = $sz - 1;
			$u = $sx * $sx * $sx * ($sx * ($sx * 6 - 15) + 10);
			$v = $sz * $sz * $sz * ($sz * ($sz * 6 - 15) + 10);
			$du = 30 * $sx * $sx * $tx * $tx;

			$lx = $gx[$h]; $lz = $gz[$h]; $rx = $gx[$k]; $rz = $gz[$k];
			$left = $lx * $sx + $lz * $sz;
			$span = $rx * $tx + $rz * $sz - $left;
			$near = $left + $u * $span;
			$nearX = $lx + $u * ($rx - $lx) + $du * $span;
			$nearZ = $lz + $u * ($rz - $lz);

			$h++; $k++;
			$lx = $gx[$h]; $lz = $gz[$h]; $rx = $gx[$k]; $rz = $gz[$k];
			$left = $lx * $sx + $lz * $tz;
			$span = $rx * $tx + $rz * $tz - $left;
			$rise = $left + $u * $span - $near;
			$b0 = $near + $v * $rise;
			$b1 = $nearX + $v * ($lx + $u * ($rx - $lx) + $du * $span - $nearX);
			$b2 = $nearZ + $v * ($lz + $u * ($rz - $lz) - $nearZ) + 30 * $sz * $sz * $tz * $tz * $rise;
		}
		else [$b0,$b1,$b2] = $this->noise->sample($sx, $sz);

		$value = $r['baseBias'] + $r['rolling']*$a0;
		$dx = $r['rolling']*$a1*$f;
		$dz = $r['rolling']*$a2*$f;
		if($r['ridgeHeight'] > 0)
		{
			$rounded = sqrt($a0 * $a0 + ($profile[0] ?? $r['ridgeRoundness']) ** 2);
			$value -= $r['ridgeHeight'] * $rounded;
			$factor = -$r['ridgeHeight']*$a0 / $rounded;
			$dx += $factor*$a1*$f;
			$dz += $factor*$a2*$f;
		}
		if($r['wallHeight'] > 0 || $this->layered)
		{
			// A narrow contour band connects a distinct floor and plateau. The quintic
			// has zero first/second derivatives at both ends, so clamping remains C2.
			// abs() is harmless at zero because a constant floor surrounds that point.
			// Comparisons replace its call for every ordinary value. A zero or NaN goes through abs(), which
			// defines its sign bit.
			$start = $this->wallStart;
			$width = $this->wallWidth;
			if($profile !== null) {
				$start += $profile[1] - $r['floorWidth'];
				$width += $profile[2] - $r['wallWidth'];
			}
			$distance = ($a0 > 0 ? $a0 : ($a0 < 0 ? -$a0 : abs($a0))) - $start;
			$sign = $a0 < 0 ? -1 : 1;
			$erosionX = $erosionZ = 0.0;

			if($this->layered)
			{
				// A drainage channel cuts sideways into the escarpment and buries part of its benches.
				$ef = 22*$f;
				$sx = $x*$ef+43.7;
				$sz = $z*$ef-81.3;

				// Drainage: Noise::sample($sx, $sz) written out.
				if($sx * $sx + $sz * $sz < self::REACH)
				{
					$ix = (int)$sx; $iz = (int)$sz;

					if($sx < $ix) $ix--;

					if($sz < $iz) $iz--;

					$sx -= $ix; $sz -= $iz; $ix &= 255; $iz &= 255;
					$h = $p[$ix] + $iz; $k = $p[$ix + 1] + $iz;
					$tx = $sx - 1; $tz = $sz - 1;
					$u = $sx * $sx * $sx * ($sx * ($sx * 6 - 15) + 10);
					$v = $sz * $sz * $sz * ($sz * ($sz * 6 - 15) + 10);
					$du = 30 * $sx * $sx * $tx * $tx;

					$lx = $gx[$h]; $lz = $gz[$h]; $rx = $gx[$k]; $rz = $gz[$k];
					$left = $lx * $sx + $lz * $sz;
					$span = $rx * $tx + $rz * $sz - $left;
					$near = $left + $u * $span;
					$nearX = $lx + $u * ($rx - $lx) + $du * $span;
					$nearZ = $lz + $u * ($rz - $lz);

					$h++; $k++;
					$lx = $gx[$h]; $lz = $gz[$h]; $rx = $gx[$k]; $rz = $gz[$k];
					$left = $lx * $sx + $lz * $tz;
					$span = $rx * $tx + $rz * $tz - $left;
					$rise = $left + $u * $span - $near;
					$erosion0 = $near + $v * $rise;
					$erosion1 = $nearX + $v * ($lx + $u * ($rx - $lx) + $du * $span - $nearX);
					$erosion2 = $nearZ + $v * ($lz + $u * ($rz - $lz) - $nearZ) + 30 * $sz * $sz * $tz * $tz * $rise;
				}
				else [$erosion0,$erosion1,$erosion2] = $this->noise->sample($sx, $sz);

				$retreat = .029*$r['terraceStrength']*$r['bedding'];
				$distance -= $retreat*$erosion0;
				$erosionX = $retreat*$erosion1*$ef;
				$erosionZ = $retreat*$erosion2*$ef;

				// Comparisons clamp every ordinary value to [0,1] without a call. A zero or NaN goes through
				// max() and min(), which define the sign of zero and the handling of NaN.
				$channel = ($erosion0+.13)/.62;
				$channel = $channel >= 1.0 ? 1.0
					: ($channel > 0.0 ? $channel : ($channel < 0.0 ? 0.0 : max(0.0,min(1.0,$channel))));

				// Noise::fade() written out, as is every fade below.
				$fade = $channel * $channel * $channel * ($channel * ($channel * 6 - 15) + 10);
				$strength = $r['bedding']*$r['terraceStrength']*(1-.80*$fade);
				$ds = -$r['bedding']*$r['terraceStrength']*.80*30*$channel**2*($channel-1)**2/.62*$ef;
				$strengthX = $ds*($erosion1*$j0+$erosion2*$j2);
				$strengthZ = $ds*($erosion1*$j1+$erosion2*$j3);
			}

			// The same clamp as the channel above.
			$wall = $distance / $width;
			$wall = $wall >= 1.0 ? 1.0 : ($wall > 0.0 ? $wall : ($wall < 0.0 ? 0.0 : max(0.0, min(1.0, $wall))));

			$value += $r['wallHeight'] * $wall * $wall * $wall * ($wall * ($wall * 6 - 15) + 10);
			$factor = $r['wallHeight'] * 30 * $wall * $wall * ($wall - 1) ** 2 / $width;
			$dx += $sign*$factor*$a1*$f - $factor*$erosionX;
			$dz += $sign*$factor*$a2*$f - $factor*$erosionZ;
		}
		if($r['directionalRise'] != 0)
		{
			if($this->coast) {
				[$cx,$cz] = $this->coast;
				$coast = tanh($cx*$x+$cz*$z);
				$factor = $r['directionalRise']*(1-$coast*$coast);
				$dx += $cx*$factor; $dz += $cz*$factor;
			} else {
				$coast = tanh($x / 4500);
				$dx += $r['directionalRise']*(1-$coast*$coast)/4500;
			}
			$value += $r['directionalRise']*$coast;
		}

		$value += $r['secondary']*$b0;
		$dx += 2*$r['secondary']*$b1*$f;
		$dz += 2*$r['secondary']*$b2*$f;
		$height = $scale * $value;
		// Both world derivatives read both field derivatives, so hold the first until the second exists.
		$worldX = $scale * ($dx * $j0 + $dz * $j2);
		$dz = $scale * ($dx * $j1 + $dz * $j3);
		$dx = $worldX;

		if($this->layered)
		{
			[$relative, $factor, $displacement] = CanyonBeds::shape($height - $datum, $r['terraceSpacing'], $strength);
			$height = $relative + $datum;
			$dx = $factor * $dx + (1 - $factor) * CanyonBeds::DIP[0] + $displacement*$strengthX;
			$dz = $factor * $dz + (1 - $factor) * CanyonBeds::DIP[1] + $displacement*$strengthZ;
		}
		elseif($r['terraceSpacing'] > 0 && $r['terraceStrength'] > 0)
		{
			$spacing = $r['terraceSpacing'];
			$strength = $r['terraceStrength'];
			$level = floor($height / $spacing);
			$fraction = $height / $spacing - $level;

			$fade = $fraction * $fraction * $fraction * ($fraction * ($fraction * 6 - 15) + 10);
			$height += $strength * ($spacing * ($level + $fade) - $height);
			$factor = 1 - $strength + $strength * 30 * $fraction * $fraction * ($fraction - 1) ** 2;
			$dx *= $factor;
			$dz *= $factor;
		}

		return [$height,$dx,$dz];
	}

	/**
	 * Sample finite smooth detail, gating every octave from the independent coarse guide.
	 *
	 * The octave loop uses precomputed coefficients and writes Noise::value() out in place, for the reason
	 * given on coarse(). The operations keep their order, so every height keeps its bits.
	 * @param float $x World X coordinate in meters.
	 * @param float $z World Z coordinate in meters.
	 * @return float Final procedural lattice height in meters.
	 */
	public function height(float $x, float $z, ?array $profile = null): float
	{
		$r = $this->values;
		$x += $r['noiseOffsetX'];
		$z += $r['noiseOffsetZ'];
		if($this->axes) {
			[$a,$b,$c,$d] = $this->axes['macro'];
			[$height,$gx,$gz] = $this->coarseAt($a*$x+$b*$z,$c*$x+$d*$z,$profile);
			$dx = $a*$gx+$c*$gz; $dz = $b*$gx+$d*$gz;
			[$u,$v] = $this->axes['rotation'];
			[$x,$z] = [$u*$x+$v*$z,$u*$z-$v*$x];
			[$dx,$dz] = [$u*$dx+$v*$dz,$u*$dz-$v*$dx];
		} else [$height,$dx,$dz] = $this->coarseAt($x, $z, $profile);
		if($this->gullyDepth > 0.) $height -= $this->gullyCut($x,$z,$dx,$dz);
		$slope = ($dx * $dx + $dz * $dz) / ($r['slopeScale'] * $r['slopeScale']);
		if(!$this->octaveSteps) return $height;
		$shape = $this->detailShape;
		$linear = $this->detailLinear;
		$p = $this->permutation;
		$gx = $this->gradientX;
		$gz = $this->gradientZ;

		foreach($this->octaveSteps as [$frequency,$amplitude,$damping,$offsetX,$offsetZ])
		{
			$weight = 1 / (1 + $damping * $slope);
			$sx = $x * $frequency + 91.73 + $offsetX;
			$sz = $z * $frequency - 23.49 - $offsetZ;

			// Detail octave: Noise::value($sx, $sz) written out.
			if($sx * $sx + $sz * $sz < self::REACH)
			{
				$ix = (int)$sx; $iz = (int)$sz;

				if($sx < $ix) $ix--;

				if($sz < $iz) $iz--;

				$sx -= $ix; $sz -= $iz; $ix &= 255; $iz &= 255;
				$h = $p[$ix] + $iz; $k = $p[$ix + 1] + $iz;
				$tx = $sx - 1; $tz = $sz - 1;
				$u = $sx * $sx * $sx * ($sx * ($sx * 6 - 15) + 10);
				$v = $sz * $sz * $sz * ($sz * ($sz * 6 - 15) + 10);

				$left = $gx[$h] * $sx + $gz[$h] * $sz;
				$near = $left + $u * ($gx[$k] * $tx + $gz[$k] * $sz - $left);
				$h++; $k++;
				$left = $gx[$h] * $sx + $gz[$h] * $tz;
				$value = $near + $v * ($left + $u * ($gx[$k] * $tx + $gz[$k] * $tz - $left) - $near);
			}
			else $value = $this->noise->value($sx, $sz);

			if($shape != 0.) $value = $linear*$value+$shape*(.185-sqrt($value*$value+.0025));
			$height += $amplitude * $weight * $value;
		}

		return $height;
	}

	/** Four overlapping cell-local groove trains follow the coarse fall line without seams at cell edges. */
	private function gullyCut(float $x,float $z,float $dx,float $dz): float
	{
		$grade = sqrt($dx*$dx+$dz*$dz);
		if($grade <= .05) return 0.;
		$spacing = $this->values['gullySpacing'];
		$x /= 3*$spacing; $z /= 3*$spacing;
		$ix = (int)floor($x); $iz = (int)floor($z);
		$x -= floor($x); $z -= floor($z);
		$u = Noise::fade($x); $v = Noise::fade($z);
		$acrossX = -3*$dz/$grade; $acrossZ = 3*$dx/$grade;
		$cut = 0.;
		for($j=0;$j<2;$j++) for($i=0;$i<2;$i++) {
			$phase = $this->permutation[($this->permutation[($ix+$i)&255]+$iz+$j)&255]/256;
			$t = ($x-$i)*$acrossX+($z-$j)*$acrossZ+$phase;
			$q = max(0.,1.-4.*abs($t-floor($t+.5)));
			$cut += ($i ? $u : 1-$u)*($j ? $v : 1-$v)*$q*$q*(3-2*$q);
		}
		return $this->gullyDepth*$cut*Noise::fade(min(1.,($grade-.05)/.45));
	}

	/**
	 * Bound every procedural node in a world rectangle, including warp, terraces and gated detail.
	 * @param array $box Horizontal bounds [minX,minZ,maxX,maxZ], including any sample halo.
	 * @return array Conservative node height interval in meters.
	 */
	public function regionalBounds(array $box, ?array $profileRange = null): array
	{
		$r = $this->values;
		$x = ($box[0] + $box[2]) / 2 + $r['noiseOffsetX'];
		$z = ($box[1] + $box[3]) / 2 + $r['noiseOffsetZ'];
		$rx = ($box[2] - $box[0]) / 2 + 1e-9;
		$rz = ($box[3] - $box[1]) / 2 + 1e-9;
		if($this->axes) {
			[$a,$b,$c,$d] = $this->axes['macro'];
			[$u,$v] = $this->axes['rotation'];
			$detailBox = [$u*$x+$v*$z,$u*$z-$v*$x,abs($u)*$rx+abs($v)*$rz,abs($v)*$rx+abs($u)*$rz];
			[$x,$z,$rx,$rz] = [$a*$x+$b*$z,$c*$x+$d*$z,abs($a)*$rx+abs($b)*$rz,abs($c)*$rx+abs($d)*$rz];
		}
		$wx = $x; $wz = $z; $hx = $rx; $hz = $rz;
		$jac = 1.0;
		$w = $r['warpAmplitude'];
		$f = $r['warpFrequency'];
		if($w > 0)
		{
			$a = $this->noise->envelope($x*$f+271.13, $z*$f-197.41, $rx*$f, $rz*$f);
			$b = $this->noise->envelope($x*$f-419.27, $z*$f+113.71, $rx*$f, $rz*$f);
			$wx += $w * ($a[0]+$a[1])/2; $hx += $w * ($a[1]-$a[0])/2 + 1e-9;
			$wz += $w * ($b[0]+$b[1])/2; $hz += $w * ($b[1]-$b[0])/2 + 1e-9;
			$jac += $w*$f * max($a[2]+$b[2], $a[3]+$b[3]);
		}
		$f = $r['baseFrequency'];
		$a = $this->noise->envelope($wx*$f+13.17, $wz*$f-9.31, $hx*$f, $hz*$f);
		$b = $this->noise->envelope($wx*$f*2+71.43, $wz*$f*2+53.11, $hx*$f*2, $hz*$f*2);
		$absMin = max(0.0, $a[0], -$a[1]);
		$absMax = max(-$a[0], $a[1]);
		$da = max($a[2],$a[3])*$f;
		$db = max($b[2],$b[3])*$f*2;
		$lo = $r['baseBias'] + $r['rolling']*$a[0] + $r['secondary']*$b[0];
		$hi = $r['baseBias'] + $r['rolling']*$a[1] + $r['secondary']*$b[1];
		$gradient = $r['rolling']*$da + 2*$r['secondary']*$db;
		if($r['ridgeHeight'] > 0)
		{
			$lo -= $r['ridgeHeight']*sqrt($absMax**2 + ($profileRange[1][0] ?? $r['ridgeRoundness'])**2);
			$hi -= $r['ridgeHeight']*sqrt($absMin**2 + ($profileRange[0][0] ?? $r['ridgeRoundness'])**2);
			$gradient += $r['ridgeHeight']*$da;
		}
		if($r['directionalRise'] != 0)
		{
			[$cx,$cz] = $this->coast ?? [1/4500,0.];
			$center = $cx*$wx+$cz*$wz; $radius = abs($cx)*$hx+abs($cz)*$hz;
			$left = $r['directionalRise']*tanh($center-$radius);
			$right = $r['directionalRise']*tanh($center+$radius);
			$lo += min($left,$right);
			$hi += max($left,$right);
			$gradient += abs($r['directionalRise'])*max(abs($cx),abs($cz));
		}
		if($r['wallHeight'] > 0 || $this->layered)
		{
			if($this->layered)
			{
				$ef = 22*$f;
				$erosion = $this->noise->envelope($wx*$ef+43.7,$wz*$ef-81.3,$hx*$ef,$hz*$ef);
				$retreat = .029*$r['terraceStrength']*$r['bedding'];
				$absMin -= $retreat*$erosion[1];
				$absMax -= $retreat*$erosion[0];
				$dg = max($erosion[2],$erosion[3])*$ef;
				$da += $retreat*$dg;
				$strengthGradient = $r['bedding']*$r['terraceStrength']*.80*1.875/.62*$dg*$jac;
			}
			$startLo=$startHi=$this->wallStart;
			$widthLo=$widthHi=$this->wallWidth;
			if($profileRange !== null) {
				[$min,$max]=$profileRange;
				$startLo+=$min[1]-$r['floorWidth']; $startHi+=$max[1]-$r['floorWidth'];
				$widthLo+=$min[2]-$r['wallWidth']; $widthHi+=$max[2]-$r['wallWidth'];
			}
			$wallLo = max(0.0,min(1.0,($absMin-$startHi)/$widthHi));
			$wallHi = max(0.0,min(1.0,($absMax-$startLo)/$widthLo));
			$lo += $r['wallHeight']*Noise::fade($wallLo);
			$hi += $r['wallHeight']*Noise::fade($wallHi);
			$gradient += $r['wallHeight']*($wallHi === 0.0 || $wallLo === 1.0 ? 0 : 1.875/$widthLo)*$da + .15*$db;
		}
		$lo *= $r['heightScale']; $hi *= $r['heightScale'];
		$gradient *= $r['heightScale']*$jac;
		$spacing = $r['terraceSpacing'];
		if($this->layered)
		{
			$datum = CanyonBeds::DIP[0]*$x + CanyonBeds::DIP[1]*$z;
			$spread = abs(CanyonBeds::DIP[0])*$rx + abs(CanyonBeds::DIP[1])*$rz;
			// The section is monotone. Bound its argument and its added datum separately.
			$low = $lo-$datum-$spread; $high = $hi-$datum+$spread;
			$strength = $r['bedding']*$r['terraceStrength'];
			// Elevation is affine in local strength, and strength falls as the erosion noise rises. The noise
			// envelope of this box therefore gives the two strengths that enclose every gully inside it.
			$weak = $strength*(1-.80*Noise::fade(max(0.0,min(1.0,($erosion[1]+.13)/.62))));
			$firm = $strength*(1-.80*Noise::fade(max(0.0,min(1.0,($erosion[0]+.13)/.62))));
			$lo = min(CanyonBeds::shape($low,$spacing,$weak)[0], CanyonBeds::shape($low,$spacing,$firm)[0])
				+ $datum-$spread;
			$hi = max(CanyonBeds::shape($high,$spacing,$weak)[0], CanyonBeds::shape($high,$spacing,$firm)[0])
				+ $datum+$spread;
			// The narrowest ramp spans .46 of its bed. The widest bed is 2.96 spacing units.
			$gradient = 4.1*$gradient + 3.1*max(abs(CanyonBeds::DIP[0]),abs(CanyonBeds::DIP[1]))
				+ 3*$spacing*$strengthGradient;
		}
		elseif($spacing > 0)
		{
			// Within a terrace the displacement is always less than the terrace spacing.
			// Tiny spacings can lose the fractional part of the level in binary64.
			$lo -= $spacing*$r['terraceStrength'];
			$hi += $spacing*$r['terraceStrength'];
			$gradient *= 1.875;
		}
		// The rotation and non-expanding stretch have unit spectral norm up to binary32 rounding.
		if($this->axes) $gradient *= 1.000001;
		$slope = 2 * (($gradient+1e-8) / $r['slopeScale'])**2;
		if($this->axes) [$x,$z,$rx,$rz] = $detailBox;
		$lo -= $this->gullyDepth;
		$f *= 8;
		$amp = $r['detailAmplitude'];
		for($i=0; $i<$r['detailOctaves'] && $amp>0; $i++)
		{
			[$n,$m] = $this->noise->envelope($x*$f+91.73+$i*19.17, $z*$f-23.49-$i*31.29, $rx*$f, $rz*$f);
			if($this->detailShape != 0.) {
				// Bound the linear and rounded terms independently, including a zero crossing.
				$k = $this->detailShape; $a = 1-abs($k);
				$near = max(0.,$n,-$m); $far = max(abs($n),abs($m));
				$p = $k*(.185-sqrt($near*$near+.0025)); $q = $k*(.185-sqrt($far*$far+.0025));
				[$n,$m] = [$a*$n+min($p,$q),$a*$m+max($p,$q)];
			}
			$minWeight = 1 / (1+$r['damping']*($i+1)**2*$slope);
			$lo += $amp * min($n,$n*$minWeight);
			$hi += $amp * max($m,$m*$minWeight);
			$f *= $r['lacunarity'];
			$amp *= $r['persistence'];
		}
		return [$lo-1e-6,$hi+1e-6];
	}

	/**
	 * Conservative node extrema include the maximum possible gradient-noise magnitude.
	 * @return array Conservative lower and upper node heights.
	 */
	public function bounds(?float $ridgeRoundness = null): array
	{
		$r = $this->values;
		$detail = 0;
		$amplitude = $r['detailAmplitude'];

		for($i = 0; $i < $r['detailOctaves']; $i++)
		{
			$detail += 2 * $amplitude;
			$amplitude *= $r['persistence'];
		}

		$macro = abs($r['baseBias']) + 2*($r['rolling']+$r['secondary']) + abs($r['directionalRise'])
			+ $r['ridgeHeight']*sqrt(4+($ridgeRoundness ?? $r['ridgeRoundness'])**2) + $r['wallHeight'];
		$range = max(3.0,$macro) * $r['heightScale'] + $detail + ($this->layered ? 3 : 1) * $r['terraceSpacing'];

		return [-$range-$this->gullyDepth, $range];
	}
}
