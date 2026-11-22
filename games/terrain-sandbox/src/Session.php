<?php
declare(strict_types=1);
namespace TerrainSandbox;

use TerrainPhonics\Atmosphere;
use TerrainPhonics\Camera;
use TerrainPhonics\Landscape;
use TerrainPhonics\RayQuery;
use TerrainPhonics\Scene;
use TerrainPhonics\SurfaceLayers;
use TerrainPhonics\AtmosphereLayers;
use TerrainPhonics\TerrainAppearance;
use TerrainPhonics\Water;
use TerrainPhonics\WaterAppearance;
use TerrainPhonics\Watercraft;

/**
 * Offline landscape authoring session with fixed-step vehicle simulation.
 *
 * Browser controls provide intent; this object owns camera motion, edit transactions,
 * environment configuration, and portable save files independently of graphics objects.
 */
final class Session
{
	/** Downward acceleration in m/s² for driving and riding. */
	public const DRIVING_GRAVITY = 9.81;
	/** Current authoritative landscape; replaced by generation or loading. */
	public Landscape $landscape;
	/** Shared render and physics environment. */
	public Water $water;
	/** Shared physical atmospheric parameters. */
	public Atmosphere $atmosphere;
	/** Perspective camera used for flight and vehicle chase views. */
	public Camera $camera;
	/** Controllable physical watercraft. */
	public Watercraft $craft;
	public Car $car;
	/** The car's lamp levels and how far its pop-up headlights stand open. */
	private CarLamps $lamps;
	public Jet $jet;
	public BalloonPractice $balloons;
	public ?FlightTrails $flightTrails = null;
	public string $jetView = 'chase';
	public bool $jetMap = true, $jetWideMap = false;
	public bool $carMap = true, $carWideMap = false;
	private int $launches = 0;
	/** Race gates on the landscape. Saved with it, and replaced when a file loads or a new landscape is generated. */
	public Course $course;
	private ?Circuit $circuit = null, $circuitPreview = null;
	private ?string $circuitVariant = null;
	private ?\Generator $circuitSearch = null;
	private ?array $circuitCommit = null;
	/** Timed run through the course by the vehicle being driven, ridden or flown. */
	public Race $race;
	public ?DeliveryRun $delivery = null;
	private float $aspect = 16/9;
	/** Dimensions of each gate kind, for placement, crossings, car collision and drawing. */
	public readonly GateShapes $gateShapes;
	/** Counts vehicle boardings and respawns, so a race can tell when its vehicle was put somewhere new. */
	private int $boarding = 0;
	private CarChase $chase;
	/** Ducks the chase camera through gates; made afresh for each boarding, mode and course revision. */
	private ?GateDuck $duck = null;
	private string $duckFor = '';
	private ?Course $duckCourse = null;
	/**
	 * Distance in meters before and after a gate over which each vehicle's camera ducks and rises again. The F5
	 * covers its 60 m in 0.23 to 1.1 seconds.
	 */
	private const DUCK_LEAD = ['car' => 10.0, 'craft' => 8.0, 'jet' => 60.0];
	private ?\Generator $carSearch = null;
	/** Whether simulation is paused; authoring remains available. */
	public bool $paused = false;
	/** Current interaction mode: fly, craft, car or jet. */
	public string $mode = 'fly';
	/** Simulation clock in seconds. */
	public float $time = 0;
	private const SKY_DEFAULTS = ['azimuth' => 125.0, 'elevation' => 35.0, 'haze' => 1.0];
	private const LIMESTONE_SKY = ['azimuth' => 106.0, 'elevation' => 36.0, 'haze' => .7];
	/** Aerosol layers above sea level in meters, with optical depth per kilometer. */
	private const PRESET_AIR = [
		'coast' => ['name'=>'Sea spray', 'top'=>340., 'density'=>.065, 'color'=>'#d9e2e5'],
		'mountain' => ['name'=>'Valley haze', 'top'=>540., 'density'=>.037, 'color'=>'#cfdbe4'],
		'canyon' => ['name'=>'Canyon dust', 'top'=>620., 'density'=>.038, 'color'=>'#d9c6ac'],
		'limestone' => ['name'=>'Lowland mist', 'top'=>210., 'density'=>.063, 'color'=>'#d9dfd6'],
		'basalt-fjords' => ['name'=>'Fjord mist', 'top'=>195., 'density'=>.072, 'color'=>'#cdd9e0'],
		'amber-dunes' => ['name'=>'Suspended sand', 'top'=>440., 'density'=>.057, 'color'=>'#e0c9a9'],
		'arctic-meltwater' => ['name'=>'Ice haze', 'top'=>225., 'density'=>.020, 'color'=>'#dce5eb'],
		'tidal-lagoon' => ['name'=>'Humid marine air', 'top'=>640., 'density'=>.046, 'color'=>'#dae3e3'],
	];
	public const LIMESTONE_SURFACE = TerrainAppearance::PRESET_DEFAULTS['limestone'];
	private const PRESET_LOOK = [
		'mountain' => TerrainAppearance::PRESET_DEFAULTS['mountain'],
		'limestone' => self::LIMESTONE_SURFACE + self::LIMESTONE_SKY,
		'basalt-fjords' => TerrainAppearance::PRESET_DEFAULTS['basalt-fjords'] + [
			'azimuth'=>82., 'elevation'=>32., 'haze'=>.72, 'waterColor'=>'#1c5965',
			'waterTransparency'=>.78, 'waterReflectivity'=>.72, 'waterFogDensity'=>.04, 'waterChoppiness'=>55.,
		],
		'amber-dunes' => TerrainAppearance::PRESET_DEFAULTS['amber-dunes'] + ['azimuth'=>146., 'elevation'=>18., 'haze'=>.85],
		'arctic-meltwater' => TerrainAppearance::PRESET_DEFAULTS['arctic-meltwater'] + [
			'azimuth'=>112., 'elevation'=>22., 'haze'=>.55, 'waterColor'=>'#345b69',
			'waterTransparency'=>.80, 'waterReflectivity'=>.68, 'waterFogDensity'=>.070, 'waterChoppiness'=>32.,
		],
		'tidal-lagoon' => TerrainAppearance::PRESET_DEFAULTS['tidal-lagoon'] + [
			'azimuth'=>103., 'elevation'=>37., 'haze'=>.70, 'waterColor'=>'#1e7475',
			'waterTransparency'=>.90, 'waterReflectivity'=>.51, 'waterFogDensity'=>.070, 'waterChoppiness'=>27.,
		],
	];
	/** Starting viewpoints in base wavelengths, with clearance above both land and water in meters. */
	private const PRESET_VIEWS = [
		'limestone' => ['x'=>.8, 'z'=>-1.6, 'heading'=>305., 'pitch'=>.10, 'clearance'=>45., 'minimum'=>105.],
		'basalt-fjords' => ['x'=>-1.2, 'z'=>.225, 'heading'=>307., 'pitch'=>-.16, 'clearance'=>275., 'minimum'=>0.],
		'amber-dunes' => ['x'=>.75, 'z'=>.5, 'heading'=>165., 'pitch'=>-.19, 'clearance'=>95., 'minimum'=>0.],
		'arctic-meltwater' => ['x'=>1., 'z'=>.5, 'heading'=>135., 'pitch'=>-.19, 'clearance'=>160., 'minimum'=>0.],
		'tidal-lagoon' => ['x'=>-.875, 'z'=>-.25, 'heading'=>165., 'pitch'=>-.29, 'clearance'=>85., 'minimum'=>0.],
	];

	/**
	 * UI configuration, serialized alongside the terrain. gateAltitude hangs a gate placed on land that many meters
	 * above the ground, which makes it an air gate. gateWidth and gateHeight size a new gate's opening in meters, and
	 * 0 takes the size of the kind the click places; a size outside that kind's range takes the nearest it allows.
	 */
	public array $settings = [
		'tool' => 'navigate', 'radius' => 40.0, 'amount' => 12.0, 'flattenHeight' => 0.0,
		'material' => 'grass', 'roadWidth' => 8.0, 'roadShoulder' => 12.0, 'roadRaise' => 0.0,
		'circuitRelief'=>120.0, 'circuitStartHeight'=>null,
		'exposure' => 1.0,
		'nightGlow' => Atmosphere::DEFAULT_NIGHT_GLOW, 'clock' => false, 'quality' => 1.0, 'speed' => 100.0,
		'waterLevel' => 0.0, 'waterChoppiness' => 100.0,
		'balloonAltitude' => 100.0, 'balloonTool' => 'place',
		'gateRole' => 'start', 'gateLaps' => 1, 'gateAltitude' => 0.0, 'gateWidth' => 0.0, 'gateHeight' => 0.0,
	] + self::SKY_DEFAULTS + WaterAppearance::DEFAULTS + TerrainAppearance::DEFAULTS;
	/** The terrain's colors, banding and blending, which the scene draws with. Its settings are part of $settings. */
	public TerrainAppearance $terrainAppearance;
	public ?SurfaceLayers $surfaceLayers=null;
	public ?TerrainAppearance $paint = null;
	public ?\Closure $prepareMaterial = null;
	private int $materialReply = 0;
	private int $atmosphereReply = 0;
	private string $atmosphereOrigin = 'preset';
	private ?string $materialPreset = null;
	public const SURFACES = ['coast','mountain','limestone','canyon','canyon-v1','basalt-fjords','amber-dunes','arctic-meltwater','tidal-lagoon'];

	private static function materialStyle(array $recipe): string
	{
		return $recipe['preset'] === 'limestone' ? 'limestone' : TerrainAppearance::style($recipe);
	}

	private function paintInks(): array
	{
		return array_intersect_key(($this->paint ?? $this->terrainAppearance)->settings,array_flip(SurfaceLayers::INKS));
	}

	private function setMaterial(array $action): void
	{
		$recipe = $this->landscape->recipe();
		$origin = $this->materialPreset ?? self::materialStyle($recipe);
		$active = $this->activeLayers();
		if(array_key_exists('layers',$action) || array_key_exists('fields',$action)) {
			$candidate = new SurfaceLayers($action['layers'] ?? $active->layers,
				$action['fields'] ?? $active->fields,$active->bounce);
			if($candidate->layers === $active->layers && $candidate->fields === $active->fields) return;
		} elseif(isset($action['preset'])) {
			$origin = $action['preset'];
			if(!in_array($origin,self::SURFACES,true)) throw new \InvalidArgumentException('Unknown material preset.');
			$name = $origin === 'canyon-v1' ? 'canyon' : $origin;
			$recipe = (new \TerrainPhonics\Recipe(['preset'=>$name] + ($origin === 'canyon-v1' ? ['version'=>1] : [])
				+ self::preset($name)))->values();
			$candidate = SurfaceLayers::landscape($recipe,new TerrainAppearance(self::PRESET_LOOK[$name] ?? []));
		} else {
			$candidate = SurfaceLayers::landscape($recipe,$this->terrainAppearance);
			$origin = null;
		}
		if($this->prepareMaterial) ($this->prepareMaterial)($candidate);
		$this->surfaceLayers = $origin === null ? null : $candidate;
		$this->materialPreset = $origin;
		if($origin === null) {
			$this->generatedLayers = $candidate;
			$this->layerRecipe = $recipe;
			$this->layerAppearance = $this->terrainAppearance;
		}
		$this->dirty = true;
	}

	private ?SurfaceLayers $generatedLayers=null;
	private ?TerrainAppearance $layerAppearance=null;
	private array $layerRecipe=[];

	public function activeLayers(): SurfaceLayers
	{
		if($this->surfaceLayers) return $this->surfaceLayers;
		$recipe=$this->landscape->recipe();
		if($this->layerRecipe!==$recipe || $this->layerAppearance!==$this->terrainAppearance) {
			$this->generatedLayers=SurfaceLayers::landscape($recipe,$this->terrainAppearance);
			$this->layerRecipe=$recipe;
			$this->layerAppearance=$this->terrainAppearance;
		}
		return $this->generatedLayers;
	}

	/** Gate tool roles. Remove deletes the gate nearest the click. */
	public const GATE_ROLES = ['start', 'checkpoint', 'finish', 'remove'];
	/** Distance in meters a pick ray reaches when it hits no ground. */
	private const PICK_RANGE = 6000.0;
	/** Uncommitted road control points in [x,y,z] meters. */
	public array $road = [];
	/** Last operation result shown in the authoring panel. */
	public string $message = 'Right-drag to look. WASD to fly, Q/E down/up. Choose a tool to edit.';
	/** Whether input or simulation changed the visible scene. */
	public bool $dirty = true;
	/** Incremented for whole landscape replacement; staged edits preserve this generation. */
	public int $generation = 0;
	/**
	 * Seconds between terrain detail selections while the view moves; zero selects on every change.
	 * The page sets it to compare frame rates. It is not an authoring setting, so no file stores or restores it.
	 */
	public float $selectionInterval = Scene::DEFAULT_SELECTION_INTERVAL;
	/**
	 * Milliseconds of terrain selection work the renderer may do in one frame; zero runs each selection whole.
	 * Like the interval, the page sets it to compare frame rates, and no file stores or restores it.
	 */
	public float $selectionSliceMs = Scene::DEFAULT_SELECTION_SLICE_MS;
	/**
	 * Jet handling, after AirForce Delta's Controller option: 'novice' banks to turn and levels itself, 'expert'
	 * rolls, pitches and yaws freely. Like the next setting it belongs to the player, so no file stores it.
	 */
	public string $jetControls = 'novice';
	/** Whether pushing the stick forward raises the jet's nose instead of lowering it. */
	public bool $invertPitch = false;
	/**
	 * The car's headlight switch: 'auto' lights them while the sun is lower than AUTO_HEADLIGHTS, and 'on' and 'off'
	 * keep them lit or dark. Like the jet controls it belongs to the player, so no file stores it.
	 */
	public string $headlights = 'auto';
	/** Headlight switch positions in the order L and the X button step through them. */
	public const HEADLIGHTS = ['auto', 'on', 'off'];
	/** Sun elevation in degrees below which Auto lights the headlights. */
	public const AUTO_HEADLIGHTS = 5.0;
	/** Buttons the page reports in the last element of a pad packet, one bit each, while they are held. */
	public const PAD_A = 1, PAD_B = 2, PAD_X = 4, PAD_Y = 8, PAD_LB = 16, PAD_RB = 32, PAD_LS = 64, PAD_RS = 128;
	/** A gamepad at rest: both sticks centered, both triggers released and no buttons held. */
	private const NO_PAD = ['x' => 0.0, 'y' => 0.0, 'lookX' => 0.0, 'lookY' => 0.0, 'lt' => 0.0, 'rt' => 0.0, 'buttons' => 0];
	private array $keys = [];
	/** Gamepad state from the page: sticks run -1 to 1 with +Y toward the player, triggers 0 to 1. */
	private array $pad = self::NO_PAD;
	/** How far the jet ski's camera has swung round to the right stick's look direction, in radians. */
	private float $craftLook = 0.0;
	private ?string $download = null;
	private ?Landscape $draft = null;
	/** Whether a complete edited revision is awaiting its matching GPU representation. */
	public bool $preparing = false;
	/** Whether exact terrain picking is progressing without blocking a frame. */
	public bool $picking = false;
	/** Diagnostics for the last confirmed hit or completed miss. */
	public ?array $lastPick = null;
	private ?RayQuery $pickQuery = null;
	private array $pickIntent = [], $pickQueue = [];
	private ?int $pin = null;
	private array $pinCenter = [];

	/**
	 * Create a complete offline authoring session.
	 * @param array $recipe Optional terrain recipe; coastal terrain is the sandbox default.
	 * @param GateShapes|null $gateShapes Gate dimensions; the package's gates.json, or its defaults, when omitted.
	 * @return void Creates a flyable landscape with explicit physical swells.
	 */
	public function __construct(array $recipe = [], ?GateShapes $gateShapes = null)
	{
		$this->terrainAppearance = new TerrainAppearance;
		$this->gateShapes = $gateShapes ?? GateShapes::packaged();
		$this->course = new Course($this->gateShapes);
		$this->race = new Race;
		$this->landscape = new Landscape($recipe + ['preset' => 'coast']);
		$this->water = new Water($this->landscape->recipe()['waterLevel'], [
			['amplitude' => .48,'wavelength' => 34,'direction' => [1,.35]],
			['amplitude' => .23,'wavelength' => 17,'direction' => [-.25,1],'phase' => 1.2],
			['amplitude' => .09,'wavelength' => 8,'direction' => [.7,-.6],'phase' => 2.7],
		]);
		$this->settings['waterLevel'] = $this->water->level();
		$this->atmosphere = new Atmosphere;
		$this->camera = new Camera;
		$this->craft = new Watercraft(gravity: self::DRIVING_GRAVITY);
		$this->car = new Car;
		$this->lamps = new CarLamps;
		$this->jet = new Jet;
		$this->balloons = new BalloonPractice;
		$this->chase = new CarChase;
		$this->switchPresetLook('coast', $this->landscape->recipe()['preset']);
		$this->resetCamera();
		$this->sun();
	}

	/**
	 * Process UI intent immediately before the engine's fixed simulation boundary.
	 * @param array $input Held keys, accumulated pointer look, actions, values, and clicks.
	 * @param float $seconds Display elapsed seconds, bounded by the application.
	 * @param float $pickMs Cooperative pick allowance in milliseconds.
	 * @param float $aspect Canvas width divided by height for picking.
	 * @return void Publishes complete edit transactions and updates presentation controls.
	 */
	public function input(array $input, float $seconds, float $aspect, float $pickMs = 2.0): void
	{
		$this->aspect = $aspect;
		$this->keys = is_array($input['keys'] ?? null) ? $input['keys'] : [];
		$this->pad = self::readPad($input['pad'] ?? null);
		$switch = [$this->headlights, $this->headlightsLit()];
		try
		{
			$wasJet = $this->mode === 'jet';
			$wasPicking = $this->picking;
			foreach($input['values'] ?? [] as $name => $value)
			{
				// load() passes every saved name to setting(). Taking these names here keeps them out of reach of files.
				if($name === 'selectionInterval') $this->setSelectionInterval($value);
				elseif($name === 'selectionSliceMs') $this->setSelectionSliceMs($value);
				elseif($name === 'jetView' && in_array($value,['chase','cockpit'],true)) { $this->jetView = $value; $this->dirty = true; }
				elseif($name === 'jetControls') $this->setJetControls($value);
				elseif($name === 'headlights') $this->setHeadlights($value);
				elseif($name === 'invertPitch')
				{
					if(!is_bool($value)) throw new \InvalidArgumentException('Invert pitch must be true or false');
					$this->invertPitch = $value;
				}
				else $this->setting((string)$name, $value);
			}
			foreach($input['actions'] ?? [] as $action) $this->action($action);
			foreach($input['clicks'] ?? [] as $click) $this->pick((float)$click[0], (float)$click[1], $aspect);
			if($wasPicking) $this->advancePick($pickMs);
			if($this->carSearch !== null) $this->advanceCarSearch();
			if($this->circuitSearch !== null && $this->draft === null && !$this->preparing)
				$this->advanceCircuit(min(3.0,$pickMs));
			if($wasJet && $this->mode !== 'jet')
			{
				$this->balloons->reset();
				$this->launches = 0;
				$this->flightTrails = null;
			}
			if($this->draft !== null) { $this->draft->commit(); $this->dirty = true; }
		}
		catch(\Throwable $error)
		{
			if(!$this->preparing) $this->circuitCommit = null;
			$this->draft?->close(); $this->draft = null;
			$this->cancelPick(); $this->carSearch = null;
			$this->circuitSearch = null;
			$this->message = $error->getMessage();
			$this->dirty = true;
		}
		if($this->mode !== 'car' && $this->carSearch === null) $this->delivery = null;
		$this->showPausedHeadlights($switch);
		$this->guardRace();
		$look = $input['look'] ?? [0,0];
		if($this->mode === 'fly' && (($look[0] ?? 0) != 0 || ($look[1] ?? 0) != 0))
		{
			$this->camera->look((float)$look[0] * .003, -(float)$look[1] * .003);
			$this->dirty = true;
		}
		if($this->mode !== 'fly') return;
		$pad = $this->pad;
		$elapsed = max(0, min(.1, $seconds));
		if($pad['lookX'] != 0 || $pad['lookY'] != 0)
		{
			// Square the stick so small deflections aim finely.
			$this->camera->look($pad['lookX'] * abs($pad['lookX']) * 2.2 * $elapsed,
				-$pad['lookY'] * abs($pad['lookY']) * 1.6 * $elapsed);
			$this->dirty = true;
		}
		$forward = $this->blend('KeyS', 'KeyW', -$pad['y']);
		$right = $this->blend('KeyA', 'KeyD', $pad['x']);
		$up = $this->blend('KeyQ', 'KeyE', $pad['rt'] - $pad['lt']);
		if($forward == 0 && $right == 0 && $up == 0) return;
		$speed = ($this->held('ShiftLeft') || $this->held('ShiftRight') || $pad['buttons'] & self::PAD_LS)
			? 300 : $this->settings['speed'];
		$this->camera->move($forward, $right, $up, max(0, min(.1, $seconds)), $speed);
		$this->clampCamera();
		$this->dirty = true;
	}

	/**
	 * Advance simulation using the engine's fixed step; paused sessions do not advance time.
	 * @param float $seconds Fixed update duration.
	 * @return void Advances waves, optional sun motion, and the craft.
	 */
	public function step(float $seconds): void
	{
		if($this->paused) return;
		$racing = $this->delivery === null && $this->course->count() > 0 && $this->mode !== 'fly';
		if($racing)
		{
			$from = $this->racePoint(); $mode = $this->mode;
			$recoveries = $this->car->state()['recoveries'];
		}
		if($this->mode === 'craft')
		{
			$this->reserveCraft();
			$this->craft->step($seconds, $this->blend('KeyS', 'KeyW', $this->pad['rt'] - $this->pad['lt']),
				$this->blend('KeyA', 'KeyD', $this->pad['x']), $this->water, $this->landscape, $this->time);
			$s = $this->craft->state();
			$look = $this->lookAngle();
			if($look != 0 || $this->craftLook != 0)
			{
				$gap = atan2(sin($look - $this->craftLook), cos($look - $this->craftLook));
				$this->craftLook = abs($gap) < 1e-4 ? $look : $this->craftLook + $gap * (1 - exp(-$seconds / .15));
				$s['yaw'] -= $this->craftLook;
			}
			$this->camera->position = [$s['x'] - sin($s['yaw']) * 9,$s['y'] + 4,$s['z'] - cos($s['yaw']) * 9];
			$this->camera->yaw = M_PI - $s['yaw'];
			$this->camera->pitch = -.27;
		}
		if($this->mode === 'car')
		{
			$before = $this->car->state();
			$this->chase->look = $this->lookAngle();
			if(!$this->car->step($seconds, $this->blend('KeyS', 'KeyW', $this->pad['rt'] - $this->pad['lt']),
				$this->blend('KeyA', 'KeyD', $this->pad['x']), $this->landscape, $this->water->level(),
				$this->held('Space') === 1 || ($this->pad['buttons'] & self::PAD_A) !== 0,
				$this->course->posts()))
			{
				$this->mode = 'fly';
				$this->clampCamera();
				$this->delivery = null;
				$this->message = 'The ground under the car changed. Choose Drive car to find another spot.';
				$this->dirty = true;
			}
			else
			{
				$after = $this->car->state();
				if($this->chase->advance($this->camera, $before, $after, $this->landscape, $seconds))
					$this->dirty = true;
				if($this->delivery !== null && $this->carSearch === null)
				{
					$event = $this->delivery->step($this->landscape,$this->water->level(),$before,$after);
					if($event !== null) { $this->message = $event; $this->dirty = true; }
				}
				if($this->lamps->step($seconds, $after['braking'], $after['reversing'], $this->headlightsLit()))
					$this->dirty = true;
			}
		}
		if($this->mode === 'jet')
		{
			$pad = $this->pad;
			// Pulling the stick back raises the nose unless the player inverted it.
			$climb = $this->blend('KeyQ', 'KeyE', $this->invertPitch ? -$pad['y'] : $pad['y']);
			$trigger = $pad['rt'] - $pad['lt'];
			$throttle = $trigger;
			$yaw = $pad['lookX'];
			if($this->jetControls === 'expert')
			{
				$throttle = (int)(bool)($pad['buttons'] & self::PAD_Y) - (int)(bool)($pad['buttons'] & self::PAD_B);
				if(abs($trigger) > abs($yaw)) $yaw = $trigger;
			}
			$rudder = $this->blend('KeyZ', 'KeyX', $yaw);
			$before = $this->jet->state();
			if(!$this->jet->step($seconds, $this->blend('KeyS', 'KeyW', $throttle),
				$this->blend('KeyA', 'KeyD', $pad['x']), $climb,
				$this->camera, $this->landscape, $this->water->level(), $rudder))
			{
				$this->mode = 'fly';
				$this->clampCamera();
				$this->balloons->reset();
				$this->flightTrails = null;
				$this->message = 'Reached the edge of the landscape. Free flight controls active.';
			}
			else
			{
				$pose = $this->jet->state();
				$this->flightTrails->step($seconds,$before,$pose);
				$this->balloons->step($seconds,$pose,$this->launches > 0,
					(bool)($this->held('KeyG') || ($pad['buttons'] & self::PAD_X)), $this->landscape,$this->water->level(), $this->jetView === 'cockpit',$this->flightTrails,$before);
			}
			$this->launches = max(0,$this->launches-1);
			$this->dirty = true;
		}
		$start = $this->time;
		$this->time += $seconds;
		$this->water->update($this->time);
		// The application redraws for wave time whenever water shows, which covers the gates that float on it.
		if($this->course->floats()) $this->course->float($this->water, $this->time);
		if($this->mode !== 'fly' && !($this->mode === 'jet' && $this->jetView === 'cockpit'))
			$this->duck()->step($this->nearGates($this->camera->position), $this->camera->position, $this->racePoint());
		if($racing) $this->advanceRace($from, $mode, $recoveries, $start, $seconds);
		if($this->settings['clock'])
		{
			$this->settings['azimuth'] = fmod($this->settings['azimuth'] + $seconds * .2, 360);
			$this->settings['elevation'] = sin($this->time / 120) * 55 + 8;
			$this->sun();
		}
		// Waves only need a frame once rendering has terrain/water coverage. The application
		// compares simulation time then; sun and craft motion affect the visible sky/camera now.
		$this->dirty = $this->dirty || $this->mode === 'craft' || $this->settings['clock'];
	}

	/**
	 * Find the point of the active vehicle that passes through gates.
	 * @return array [x, y, z] in meters, or an empty list in free flight.
	 */
	private function racePoint(): array
	{
		$pose = match($this->mode) {
			'car' => $this->car->state(), 'craft' => $this->craft->state(), 'jet' => $this->jet->state(),
			default => null
		};
		return $pose === null ? [] : [$pose['x'], $pose['y'], $pose['z']];
	}

	/**
	 * Test the step just taken against the race, unless the vehicle was put somewhere rather than driven there.
	 * @param array $from Vehicle position before the step.
	 * @param string $mode Mode before the step.
	 * @param int $recoveries Car recoveries before the step. Righting a wreck can move the car to its last safe spot.
	 * @param float $start Simulation time at the start of the step.
	 * @param float $seconds Step length.
	 * @return void Updates the race and shows its latest event.
	 */
	private function advanceRace(array $from, string $mode, int $recoveries, float $start, float $seconds): void
	{
		if($this->guardRace() || $this->mode !== $mode) return;
		if($mode === 'car' && $this->car->state()['recoveries'] !== $recoveries) return;
		$event = $this->race->advance($this->course, $from, $this->racePoint(), $start, $seconds, $mode,
			$this->boarding);
		if($event !== null) { $this->message = $event; $this->dirty = true; }
	}

	/**
	 * Stop a running race whose course, vehicle or ride has changed since it started.
	 * @return bool Whether a race stopped.
	 */
	private function guardRace(): bool
	{
		// A message still showing the race's last event is out of date once the race stops, so the reason replaces it.
		$stale = $this->message === $this->race->message;
		$why = $this->race->guard($this->course, $this->mode, $this->boarding);
		if($why === null) return false;
		$this->message = $stale ? $why : trim($this->message . ' ' . $why);
		$this->dirty = true;
		return true;
	}

	public function navigationTarget(): ?array
	{
		if($this->mode === 'fly') return null;
		if($this->delivery !== null) return $this->delivery->target();
		$gate = $this->race->nextGate($this->course);
		return $gate === null ? null : ['kind'=>'checkpoint','color'=>DeliveryRun::COLORS['checkpoint'],
			'x'=>$gate['x'],'y'=>$gate['y'],'z'=>$gate['z'],'yaw'=>$gate['yaw'],
			'halfWidth'=>$this->course->measure($gate)['window']['halfWidth']];
	}

	public function findingCircuit(): bool { return $this->circuitSearch !== null; }
	public function usingFloodedCircuit(): bool { return $this->circuitVariant === 'flooded'; }
	public function circuitChartSpan(): ?float
	{
		return $this->circuit === null ? null : max(320.,$this->circuit->length*1.15);
	}

	private function findCircuit(): void
	{
		if($this->mode !== 'fly') throw new \RuntimeException('Return to free flight to find a circuit.');
		if($this->preparing || $this->picking || $this->draft !== null)
			throw new \RuntimeException('Wait for the terrain edit before finding a circuit.');
		[$x,,$z] = $this->camera->position;
		$dry = $this->circuitVariant === 'flooded' ? $this->circuit->dryLevel : $this->water->level();
		$water = clone $this->water;
		$water->setWaveScale(3.);
		$swell = $water->level()-$water->heightRange()[0];
		$this->circuitPreview = null;
		$this->circuitSearch = (new CircuitFinder($this->landscape,$dry,$swell,$this->settings['circuitRelief'],
			$this->settings['circuitStartHeight']))
			->find($x,$z,$this->gateShapes,$this->settings['gateLaps']);
		$this->message = 'Looking for a closed route around the terrain…';
	}

	private function advanceCircuit(float $budgetMs): void
	{
		$deadline = hrtime(true)+(int)($budgetMs*1e6);
		$job = $this->circuitSearch;
		do
		{
			if($job->valid()) $job->next();
			if(!$job->valid())
			{
				$this->circuitSearch = null;
				$this->circuitPreview = $job->getReturn();
				$this->message = $this->circuitPreview
					? 'Circuit found. Choose a course to place its gates.'
					: 'No loop matches those elevations here. Try another site or adjust the circuit settings.';
				return;
			}
		}
		while(hrtime(true)<$deadline);
	}

	private function applyCircuit(bool $flooded): void
	{
		if($this->mode !== 'fly' || $this->preparing || $this->draft !== null || $this->picking)
			throw new \RuntimeException('Use free flight on the settled landscape to apply a circuit.');
		$circuit = $this->circuitPreview ?? $this->circuit;
		if($circuit === null) throw new \RuntimeException('Find a circuit first.');
		$this->carSearch = null;
		if($this->circuitPreview?->road)
		{
			$graded = $this->editable();
			foreach($circuit->road as $road) $graded->queue($road);
			$graded->commit();
			$course = $circuit->course($graded,$this->gateShapes,$this->settings['gateLaps'],$flooded);
			$this->circuitCommit = [$circuit,$course,$flooded];
			$this->message = 'Preparing the graded circuit…';
			return;
		}
		$course = $circuit->course($this->landscape,$this->gateShapes,$this->settings['gateLaps'],$flooded);
		$this->activateCircuit($circuit,$course,$flooded);
	}

	private function activateCircuit(Circuit $circuit, Course $course, bool $flooded): void
	{
		$this->course = $course;
		$this->race->reset();
		$this->circuit = $circuit; $this->circuitPreview = null; $this->circuitSearch = null;
		$this->circuitVariant = $flooded ? 'flooded' : 'dry';
		$this->setting('waterLevel',$flooded ? $circuit->floodedLevel : $circuit->dryLevel);
		[$x,$z,$yaw] = $circuit->approach();
		$this->mode = 'fly';
		$this->camera->position = [$x,max($this->landscape->height($x,$z),$this->water->level())+45,$z];
		$this->camera->yaw = M_PI-$yaw; $this->camera->pitch = -.4;
		$this->message = $flooded ? 'Flooded circuit ready. Board the jet ski and pass through the start arch.'
			: 'Dry circuit ready. Board the car and pass through the start gate.';
		$this->dirty = true;
	}

	/**
	 * Read one held movement control.
	 * @param string $key Browser KeyboardEvent code.
	 * @return int One when held, otherwise zero.
	 */
	private function held(string $key): int { return !empty($this->keys[$key]) ? 1 : 0; }

	/**
	 * Combine a pair of opposing keys with a gamepad axis. Held keys win, so keyboard control stays whole and exact.
	 * @param string $negative Key that drives the control to -1.
	 * @param string $positive Key that drives the control to 1.
	 * @param float $analog Gamepad value from -1 to 1, used while neither key decides.
	 * @return float The control from -1 to 1.
	 */
	private function blend(string $negative, string $positive, float $analog): float
	{
		$keys = $this->held($positive) - $this->held($negative);
		return $keys !== 0 ? (float)$keys : max(-1.0, min(1.0, $analog));
	}

	/**
	 * Read the right stick as a direction to look in, as racing games do: up looks ahead, down looks back.
	 * @return float Radians from straight ahead, positive to the right, or zero while the stick rests.
	 */
	private function lookAngle(): float
	{
		$x = $this->pad['lookX']; $y = $this->pad['lookY'];
		return $x*$x + $y*$y < .35*.35 ? 0.0 : atan2($x, -$y);
	}

	/**
	 * Validate the page's gamepad packet: six axes and a button mask. Anything malformed reads as a pad at rest.
	 * @param mixed $pad [left X, left Y, right X, right Y, left trigger, right trigger, buttons].
	 * @return array Clamped stick, trigger and button state.
	 */
	private static function readPad(mixed $pad): array
	{
		if(!is_array($pad) || !array_is_list($pad) || count($pad) !== 7) return self::NO_PAD;
		foreach($pad as $value) if(!(is_int($value) || is_float($value)) || !is_finite((float)$value)) return self::NO_PAD;
		$stick = static fn($value): float => max(-1.0, min(1.0, (float)$value));
		$trigger = static fn($value): float => max(0.0, min(1.0, (float)$value));
		return ['x' => $stick($pad[0]), 'y' => $stick($pad[1]), 'lookX' => $stick($pad[2]), 'lookY' => $stick($pad[3]),
			'lt' => $trigger($pad[4]), 'rt' => $trigger($pad[5]), 'buttons' => max(0, min(255, (int)$pad[6]))];
	}

	/**
	 * Choose Novice or Expert jet handling.
	 * @param mixed $value 'novice' or 'expert'.
	 * @return void Switches the jet now if it is flying, and says which controls apply.
	 */
	private function setJetControls(mixed $value): void
	{
		if(!in_array($value, ['novice','expert'], true))
			throw new \InvalidArgumentException('Jet controls must be novice or expert');
		$this->jetControls = $value;
		$this->jet->setExpert($value === 'expert');
		if($this->mode === 'jet') $this->message = $this->jetMessage();
		$this->dirty = true;
	}

	/**
	 * Set the car's headlight switch.
	 * @param mixed $value 'auto', 'on' or 'off'.
	 * @return void Says what the switch now does while the car is driven. Its lamps follow from the next step, or at
	 * once while the game is paused (showPausedHeadlights()).
	 */
	private function setHeadlights(mixed $value): void
	{
		if(!in_array($value, self::HEADLIGHTS, true))
			throw new \InvalidArgumentException('Headlights must be auto, on or off');
		$this->headlights = $value;
		if($this->mode === 'car') $this->message = $this->headlightMessage();
		$this->dirty = true;
	}

	/**
	 * Show a change of the headlight switch on a paused car, which takes no steps to move its lamps.
	 * @param array $before The switch and whether it lit the headlights before this input, as [position, lit].
	 * @return void Snaps the headlamps and pods to what the switch, or the sun on Auto, now asks for.
	 */
	private function showPausedHeadlights(array $before): void
	{
		if($this->paused && $this->mode === 'car' && $before !== [$this->headlights, $this->headlightsLit()]
			&& $this->lamps->snap($this->headlightsLit())) $this->dirty = true;
	}

	/** Describe the headlight switch's position. */
	private function headlightMessage(): string
	{
		return match($this->headlights) {
			'auto' => 'Headlights on Auto: lit while the sun is below ' . self::AUTO_HEADLIGHTS . '°.',
			'on' => 'Headlights on.',
			'off' => 'Headlights off.',
		};
	}

	/** Whether the switch lights the headlights now: on, or on Auto with the sun low. */
	public function headlightsLit(): bool
	{
		return $this->headlights === 'on'
			|| $this->headlights === 'auto' && $this->settings['elevation'] < self::AUTO_HEADLIGHTS;
	}

	/** Describe the jet's controls for the current handling. */
	private function jetMessage(): string
	{
		return $this->jetControls === 'expert'
			? 'Expert · A/D or stick roll, E/Q or stick pitch, LT/RT or Z/X rudder, hold Y/B or W/S for speed. F leaves the jet.'
			: 'Novice · A/D or stick turn, E/Q or stick climb and dive, hold W/S or RT/LT for speed. F leaves the jet.';
	}

	/**
	 * Apply a validated authoring or environment control.
	 * @param string $name Known setting name.
	 * @param mixed $value Control value before range validation.
	 * @return void Updates settings and dependent atmosphere state.
	 */
	private function setting(string $name, mixed $value): void
	{
		if(!array_key_exists($name, $this->settings)) return;
		if(array_key_exists($name, WaterAppearance::DEFAULTS))
		{
			$this->setWaterAppearance(new WaterAppearance([$name => $value] + $this->settings));
			return;
		}
		if(array_key_exists($name, TerrainAppearance::DEFAULTS))
		{
			// The appearance clamps the value and keeps each blend's start below its end, which can move the partner too.
			$this->setTerrainAppearance(new TerrainAppearance([$name => $value] + $this->settings));
			return;
		}
		$ranges = ['radius' => [2,300],'amount' => [.1,200],'flattenHeight' => [-1000,3000],
			'roadWidth' => [2,80],'roadShoulder' => [1,120],'roadRaise' => [-100,100], 'circuitRelief'=>[-2000,2000],
			'circuitStartHeight'=>[-10000,10000],
			'azimuth' => [0,360],'elevation' => [-12,88],'haze' => [0,5],'exposure' => [.1,4], 'nightGlow' => [0,1],
			'quality' => [.5,2],'speed' => [5,300],'gateAltitude' => [0.0,400.0],
			'balloonAltitude' => [12.0,1200.0], 'gateWidth' => [0.0,300.0],'gateHeight' => [0.0,150.0],
			'waterLevel' => [-10000,10000],'waterChoppiness' => [0,300]];
		if(isset($ranges[$name]) && !($name === 'circuitStartHeight' && $value === null))
		{
			if(!is_numeric($value) || !is_finite((float)$value)) throw new \InvalidArgumentException('Invalid control value');
			[$min,$max] = $ranges[$name];
			$value = max($min, min($max, (float)$value));
		}
		elseif($name === 'clock') $value = (bool)$value;
		elseif($name === 'tool' && !in_array($value, ['navigate','raise','lower','flatten','smooth','crater','material','road','gate','balloon'], true)) return;
		elseif($name === 'material' && !in_array($value, ['grass','rock','sand','snow','road'], true)) return;
		elseif($name === 'balloonTool' && !in_array($value,['place','remove'],true)) return;
		elseif($name === 'gateRole' && !in_array($value, self::GATE_ROLES, true)) return;
		elseif($name === 'gateLaps')
		{
			if(!is_numeric($value) || !is_finite((float)$value))
				throw new \InvalidArgumentException('Invalid control value');
			$value = (int)max(1, min(Course::MAX_LAPS, round((float)$value)));
			if($this->course->setLaps($value))
				$this->message = $this->course->finish() !== null
					? 'Laps apply to a course that finishes at its start gate. This course runs once to its finish gate.'
					: ($value === 1 ? 'The race runs one lap.' : "The race runs $value laps.");
		}
		if($name === 'waterLevel' || $name === 'waterChoppiness')
		{
			if($name === 'waterLevel')
			{
				if($this->water->level() !== (float)$value)
				{
					$this->balloons->refit($this->landscape,$value);
					$this->message = $this->delivery?->refit($this->landscape,$value) ?? $this->message;
				}
				$this->water->setLevel($value);
			}
			else $this->water->setWaveScale($value / 100);
			if($this->course->floats()) $this->course->float($this->water, $this->time);
			$this->dirty = $this->dirty || $this->settings[$name] !== $value;
		}
		$this->settings[$name] = $value;
		if($name === 'haze') $this->atmosphere->setHaze($value);
		if($name === 'nightGlow') $this->atmosphere->setNightGlow($value);
		if($name === 'exposure') $this->atmosphere->setExposure($value);
		if($name === 'azimuth' || $name === 'elevation') $this->sun();
		$this->dirty = $this->dirty || in_array($name,['quality','exposure','nightGlow','haze','azimuth','elevation','clock'],true);
	}

	/**
	 * Set how often the renderer may select terrain detail while the view moves.
	 * @param mixed $value Seconds between selections before validation; zero removes the limit.
	 * @return void Stores a spacing between zero and one second for the application to copy to the scene.
	 */
	private function setSelectionInterval(mixed $value): void
	{
		if(!is_numeric($value) || !is_finite((float)$value))
		throw new \InvalidArgumentException('Invalid control value');
		$this->selectionInterval = max(0.0, min(1.0, (float)$value));
	}

	/**
	 * Set how many milliseconds of terrain selection work the renderer may do in one frame.
	 * @param mixed $value Milliseconds before validation; zero runs every selection whole in one frame.
	 * @return void Stores a slice between zero and 100 ms for the application to copy to the scene.
	 */
	private function setSelectionSliceMs(mixed $value): void
	{
		if(!is_numeric($value) || !is_finite((float)$value))
		throw new \InvalidArgumentException('Invalid control value');
		$this->selectionSliceMs = max(0.0, min(100.0, (float)$value));
	}

	/** Keep the saved controls and renderer in sync, including while the simulation is paused. */
	private function setWaterAppearance(WaterAppearance $appearance): void
	{
		if($this->water->appearance->settings === $appearance->settings) return;
		$this->water->appearance = $appearance;
		$this->settings = $appearance->settings + $this->settings;
		$this->dirty = true;
	}

	/**
	 * Draw the terrain with new colors, banding and blending. The scene uploads a new appearance when it changes.
	 * @param TerrainAppearance $appearance Validated appearance.
	 * @return void Keeps the current appearance object when nothing changed, so the scene uploads nothing.
	 */
	private function setTerrainAppearance(TerrainAppearance $appearance): void
	{
		if($this->terrainAppearance->settings === $appearance->settings) return;
		$this->terrainAppearance = $appearance;
		$this->settings = $appearance->settings + $this->settings;
		$this->dirty = true;
	}

	private function usePresetAtmosphere(string $preset): void
	{
		$this->atmosphere->setLayers([self::PRESET_AIR[$preset]]);
		$this->atmosphereOrigin = 'preset';
		$this->dirty = true;
	}

	/** Keep authored colors and lighting when moving between preset defaults. */
	private function switchPresetLook(string $previous, string $next): void
	{
		if($this->atmosphereOrigin === 'preset') $this->usePresetAtmosphere($next);
		$from = self::PRESET_LOOK[$previous] ?? [];
		$to = self::PRESET_LOOK[$next] ?? [];
		if($from === $to) return;
		$standard = TerrainAppearance::DEFAULTS + WaterAppearance::DEFAULTS + self::SKY_DEFAULTS + ['waterChoppiness'=>100.];
		$changes = [];
		foreach($from + $to as $key => $_)
		{
			if($this->settings[$key] === ($from[$key] ?? $standard[$key])) $changes[$key] = $to[$key] ?? $standard[$key];
		}
		// The two endpoints belong to the same blend. Keep an authored range together.
		if(!isset($changes['rockSlopeStart'], $changes['rockSlopeEnd'])) unset($changes['rockSlopeStart'], $changes['rockSlopeEnd']);
		if(!isset($changes['snowSlopeStart'], $changes['snowSlopeEnd'])) unset($changes['snowSlopeStart'], $changes['snowSlopeEnd']);
		$this->setTerrainAppearance(new TerrainAppearance($changes + $this->settings));
		$this->setWaterAppearance(new WaterAppearance($changes + $this->settings));
		foreach(array_diff_key($changes, TerrainAppearance::DEFAULTS, WaterAppearance::DEFAULTS) as $key => $value)
			$this->setting($key, $value);
	}

	/** Values the Landscape tab fills when a preset is selected. */
	public static function preset(string $name): array
	{
		$options = match($name) {
			'mountain' => ['heightScale' => 1400., 'baseFrequency' => 1 / 2200],
			'canyon' => ['heightScale' => 650., 'baseFrequency' => 1 / 4000, 'terraceSpacing' => 80., 'waterLevel' => -450.],
			default => [],
		};
		$fields = (new \TerrainPhonics\Recipe(['preset' => $name] + $options))->values();
		foreach(['preset', 'version', 'maxTiles', 'tileSize', 'worldSize', 'spacing'] as $key) unset($fields[$key]);
		return $fields;
	}

	/**
	 * Dispatch a single authoring action.
	 * @param mixed $action Action name or structured action record.
	 * @return void Stages edits or changes session state.
	 */
	private function action(mixed $action): void
	{
		if(!is_array($action)) $action = ['type' => $action];
		if($this->preparing && in_array($action['type'] ?? '', ['undo','redo','generate','load','road-apply'], true))
		throw new \RuntimeException('Preparing the previous terrain edit. Please wait.');
		if($this->picking && in_array($action['type'] ?? '', ['undo','redo','road-apply','save'], true))
		{ $this->message = 'Finding the ground for the previous click. Please wait.'; return; }
		$jet = $this->mode === 'jet';
		$type = match($action['type'] ?? '')
		{
			'pad-up' => $jet ? 'jet-target' : 'jet',
			'pad-down' => $jet || $this->mode === 'car' ? 'vehicle-wide-map' : 'fly',
			'pad-y' => $jet ? '' : 'race-reset',
			default => $action['type'] ?? ''
		};
		switch($type)
		{
			case 'pause':
				$this->launches = 0;
				$this->paused = !$this->paused;
				$this->chase->resetInterpolation();
				break;
			case 'reset-camera': $this->mode = 'fly'; $this->resetCamera(); break;
			case 'undo':
				$this->editable()->commit();
				$this->message = $this->editable()->undo() ? 'Undid the last terrain transaction.' : 'Nothing to undo.';
				break;
			case 'redo':
				$this->message = $this->editable()->redo() ? 'Redid the terrain transaction.' : 'Nothing to redo.';
				break;
			case 'atmosphere-layers':
				$this->atmosphereReply = (int)($action['request'] ?? 0);
				if(!is_array($action['layers'] ?? null)) throw new \InvalidArgumentException('Expected a list of atmosphere layers.');
				$this->atmosphere->setLayers($action['layers']);
				$this->atmosphereOrigin = 'authored';
				$this->dirty = true;
				break;
			case 'atmosphere-preset':
				$this->atmosphereReply = (int)($action['request'] ?? 0);
				$this->usePresetAtmosphere($this->landscape->recipe()['preset']);
				break;
			case 'surface-layers':
				$this->setMaterial($action);
				$this->materialReply = (int)($action['request'] ?? 0);
				break;
			case 'surface-edit':
				$rows = $this->activeLayers()->layers;
				$index = array_search($action['id'] ?? null,array_column($rows,'id'),true);
				if($index === false) throw new \InvalidArgumentException('The layer has changed.');
				$key = $action['key'] ?? '';
				if(!array_key_exists($key,SurfaceLayers::DEFAULTS) || $key === 'id') throw new \InvalidArgumentException('Unknown material property.');
				$rows[$index][$key] = $action['value'] ?? null;
				$this->setMaterial(['layers'=>$rows]);
				break;
			case 'surface-field':
				$this->setMaterial(['fields'=>[$action['key']=>$action['value']] + $this->activeLayers()->fields]);
				break;
			case 'surface-ink':
				$key = $action['ink'] ?? '';
				if(!in_array($key,SurfaceLayers::INKS,true)) throw new \InvalidArgumentException('Unknown paint ink.');
				$this->paint = new TerrainAppearance([$key=>$action['color'] ?? null] + $this->paintInks());
				$this->dirty = true;
				break;
			case 'generate':
				$preset=(string)($action['preset'] ?? 'coast');
				$defaults=self::preset($preset);
				$recipe=$defaults+$this->landscape->recipe();
				$recipe['preset']=$preset;
				unset($recipe['version']); // A new landscape uses the selected preset's current recipe version.
				foreach($defaults + ['worldSize' => $recipe['worldSize']] as $key=>$default) if(array_key_exists($key,$action)) {
					if(is_string($default)) $recipe[$key]=(string)$action[$key];
					elseif(!is_numeric($action[$key]) || !is_finite((float)$action[$key]))
						throw new \InvalidArgumentException('Invalid terrain generation parameter.');
					else $recipe[$key]=is_int($default)?(int)$action[$key]:(float)$action[$key];
				}
				// A fine lattice from a loaded file can be too fine for a wider world: Landscape keys its tile cache
				// within TILE_REACH tiles of the origin. Take larger tiles rather than refuse the world.
				while($recipe['tileSize'] < 32
					&& $recipe['worldSize'] / 2 / ($recipe['spacing'] * $recipe['tileSize']) > Landscape::TILE_REACH)
					$recipe['tileSize'] = min(32, $recipe['tileSize'] * 2);
				$previous = $this->landscape->recipe()['preset'];
				$next = new Landscape($recipe);
				if($this->prepareMaterial && !$this->surfaceLayers) {
					try { ($this->prepareMaterial)(SurfaceLayers::landscape($next->recipe(),$this->terrainAppearance)); }
					catch(\Throwable $error) { $next->close(); throw $error; }
				}
				$this->replace($next);
				$this->switchPresetLook($previous, $preset);
				$this->resetCamera();
				$this->message = 'Generated ' . $recipe['preset'] . ' terrain. Detail streams in as you fly.';
				if($this->course->count() > 0) $this->message .= ' The new landscape starts without gates.';
				$this->course = new Course($this->gateShapes);
				break;
			case 'gates-clear':
				$count = $this->course->clear();
				$this->circuit = null; $this->circuitVariant = null;
				$this->message = match($count) {
					0 => 'There are no gates to clear.', 1 => 'Removed the only gate.',
					default => "Removed all $count gates."
				};
				break;
			case 'circuit-find': $this->findCircuit(); break;
			case 'circuit-cancel':
				$this->circuitSearch = null; $this->circuitPreview = null;
				$this->message = 'Circuit search cleared.';
				break;
			case 'circuit-dry':
			case 'circuit-flooded':
				$this->applyCircuit($type === 'circuit-flooded');
				break;
			case 'race-reset':
				$this->race->reset();
				$this->message = $this->course->start() === null ? 'Race reset. Place a start gate to race.'
					: 'Race reset. Pass through the start gate to start the clock.';
				break;
			case 'road-clear': $this->cancelPick(); $this->road = []; $this->message = 'Road control points cleared.'; break;
			case 'road-apply':
				if(count($this->road) < 2) throw new \RuntimeException('Place at least two road points on the terrain.');
				$this->editable()->queue(['type' => 'road','points' => $this->road,'width' => $this->settings['roadWidth'],
					'shoulder' => $this->settings['roadShoulder'],'crown' => .12,'material' => 'road']);
				$this->message = 'Graded a road through ' . count($this->road) . ' control points.';
				$this->road = [];
				break;
			case 'save':
				if($this->preparing || $this->draft !== null) throw new \RuntimeException('Wait for the terrain edit before saving.');
				$this->download = $this->save(); break;
			case 'load': $this->load((string)($action['json'] ?? '')); break;
			case 'delivery':
				if($this->delivery !== null)
				{
					$this->delivery = null;
					$this->carSearch = null;
					$this->message = 'Package run ended.';
					break;
				}
				if($this->race->state === 'running') break;
				$this->delivery = new DeliveryRun;
				$this->message = 'Finding a package site…';
				$this->paused = false;
				if($this->mode === 'car')
				{
					$floor = PickupSearch::floorTest($this->landscape);
					$car = $this->car->state();
					if($floor === null || $floor($car['x'],$car['z'],$this->landscape->height($car['x'],$car['z']))) break;
				}
				$this->startCarSearch();
				break;
			case 'car':
				if($this->mode !== 'car' && $this->carSearch === null) $this->startCarSearch();
				break;
			case 'jet':
				if($this->mode === 'jet') break;
				$this->cancelPick();
				$this->carSearch = null;
				$this->releasePin();
				$this->jet->setExpert($this->jetControls === 'expert');
				$this->jet->board($this->camera, $this->landscape, $this->water->level());
				$this->flightTrails = new FlightTrails;
				$this->mode = 'jet'; $this->paused = false; $this->boarding++;
				$this->message = $this->jetMessage();
				break;
			case 'vehicle-map':
			case 'jet-map':
				if($this->mode === 'jet')
				{
					$this->jetMap = !($this->jetMap || $this->jetWideMap);
					$this->jetWideMap = false;
					$this->dirty = true;
				}
				elseif($this->mode === 'car')
				{
					$this->carMap = !($this->carMap || $this->carWideMap);
					$this->carWideMap = false;
					$this->dirty = true;
				}
				break;
			case 'vehicle-wide-map':
			case 'jet-wide-map':
				if($jet)
				{
					$this->jetWideMap = !$this->jetWideMap;
					$this->dirty = true;
				}
				elseif($this->mode === 'car')
				{
					$this->carWideMap = !$this->carWideMap;
					$this->dirty = true;
				}
				break;
			case 'jet-view':
				if($this->mode === 'jet')
				{
					$this->jetView = $this->jetView === 'cockpit' ? 'chase' : 'cockpit';
					$this->dirty = true;
				}
				break;
			case 'jet-launch':
				if($this->mode === 'jet' && !$this->paused) $this->launches = min(2,$this->launches+1);
				break;
			case 'jet-target':
				if($this->mode === 'jet') { $this->balloons->cycle(); $this->dirty = true; }
				break;
			case 'balloon-practice':
				if($this->mode === 'jet')
				{
					$this->dirty = true;
					$this->message = $this->balloons->start($this->jet->state(),$this->landscape,$this->water->level())
						? 'Balloon practice. Follow the red blip on your map.' : 'Fly toward open terrain to place a practice balloon.';
				}
				break;
			case 'balloons-reset': $this->dirty = true; $this->balloons->reset(false); $this->launches = 0; $this->message = 'Balloons restored.'; break;
			case 'jet-controls': $this->setJetControls($this->jetControls === 'expert' ? 'novice' : 'expert'); break;
			case 'pad-x': if($this->mode === 'jet') break;
				// Outside the F5 the X button retains its headlight shortcut.
			case 'headlights':
				$next = (array_search($this->headlights, self::HEADLIGHTS, true) + 1) % count(self::HEADLIGHTS);
				$this->setHeadlights(self::HEADLIGHTS[$next]);
				$this->message = $this->headlightMessage();
				break;
			case 'craft': $this->cancelPick(); $this->carSearch = null; $this->boardCraft(); break;
			case 'fly':
				// Free flight starts from the eye a race gate ducked, not from inside the gate.
				if($this->mode === 'jet' && $this->jetView === 'cockpit')
				{
					$eye = Jet::cockpit($this->jet->state());
					[$nose] = $eye->basis();
					$this->camera->position = $eye->position;
					$this->camera->yaw = atan2($nose[0],-$nose[2]);
					$this->camera->pitch = max(-1.52,min(1.52,asin(max(-1,min(1,$nose[1])))));
				}
				elseif($this->mode !== 'fly' && $this->duck !== null)
					for($i = 0; $i < 3; $i++) $this->camera->position[$i] += $this->duck->offset[$i];
				$this->carSearch = null; $this->mode = 'fly'; $this->releasePin(); $this->clampCamera();
				$this->message = 'Flight controls active.';
				break;
		}
		$this->dirty = true;
	}

	/**
	 * Apply the selected tool at a camera ray intersection.
	 * @param float $x Normalized viewport X.
	 * @param float $y Normalized viewport Y.
	 * @param float $aspect Viewport width divided by height.
	 * @return void Stages one brush operation or one road control point.
	 */
	private function pick(float $x, float $y, float $aspect): void
	{
		$tool = $this->settings['tool'];
		if($tool === 'navigate' || $this->mode !== 'fly' || $this->carSearch !== null) return;
		if($this->preparing) { $this->message = 'Preparing the previous terrain edit. Please wait.'; return; }
		$queuedTool = $this->pickIntent['settings']['tool'] ?? $this->pickQueue[0]['settings']['tool'] ?? '';
		if($this->picking && ($tool !== 'road' || $queuedTool !== 'road'))
		{ $this->message = 'Finding the ground for the previous click. Please wait.'; return; }
		if(count($this->pickQueue) + count($this->road) >= 64) return;
		$this->pickQueue[] = ['origin' => $this->camera->position, 'direction' => $this->camera->ray($x, $y, $aspect),
			'yaw' => M_PI - $this->camera->yaw, 'settings' => $this->settings];
		$this->picking = true;
		$this->message = 'Finding the ground…';
	}

	/**
	 * Spend a bounded slice finding an exact intersection with the current surface.
	 * @param float $budgetMs Maximum pick work for this frame in milliseconds.
	 * @return void Applies a complete hit and leaves the remaining queued road clicks for later frames.
	 */
	private function advancePick(float $budgetMs): void
	{
		if($this->pickQuery === null && $this->pickQueue !== [])
		{
			$this->pickIntent = array_shift($this->pickQueue);
			$this->pickQuery = $this->landscape->rayQuery($this->pickIntent['origin'], $this->pickIntent['direction'], 6000);
		}
		if($this->pickQuery === null || !$this->pickQuery->advance($budgetMs, 512)) return;
		$hit = $this->pickQuery->result();
		$this->lastPick = $this->pickQuery->stats();
		$intent = $this->pickIntent;
		$this->pickQuery = null; $this->pickIntent = [];
		$this->picking = $this->pickQueue !== [];
		$this->applyPick($hit, $intent['settings'], $intent);
	}

	/**
	 * Cancel incomplete picks before replacing their source landscape.
	 * @return void Releases query ownership and queued road intents.
	 */
	private function cancelPick(): void
	{
		$this->pickQuery?->cancel(); $this->pickQuery = null;
		$this->pickIntent = $this->pickQueue = []; $this->picking = false;
	}

	/**
	 * Turn a completed exact terrain hit into one edit or road control point.
	 * @param array|null $hit Exact ray intersection, or null when the ray misses.
	 * @param array $settings Authoring settings captured when the user clicked.
	 * @param array $intent The click's ray origin and direction and the camera heading at the time.
	 * @return void Stages a complete operation against the unchanged source revision.
	 */
	private function applyPick(?array $hit, array $settings, array $intent): void
	{
		$tool = $settings['tool'];
		if($tool === 'balloon')
		{
			[$ox,$oy,$oz] = $intent['origin']; [$dx,$dy,$dz] = $intent['direction'];
			$distance = $hit['distance'] ?? self::PICK_RANGE;
			if($dy < 0 && $oy >= $this->water->level())
				$distance = min($distance,($this->water->level()-$oy)/$dy);
			if($settings['balloonTool'] === 'remove')
				$this->message = $this->balloons->remove($intent['origin'],$intent['direction'],$distance)
					? 'Removed balloon.' : 'Aim at the balloon to remove it.';
			elseif($distance < self::PICK_RANGE || $hit !== null)
			{
				$this->balloons->place($ox+$dx*$distance,$oz+$dz*$distance,$settings['balloonAltitude'],$this->landscape,$this->water->level());
				$this->message = 'Placed a balloon.';
			}
			else $this->message = 'Aim at land or water to place a balloon.';
			$this->dirty = true;
			return;
		}
		if($tool === 'gate') { $this->pickGate($hit, $settings, $intent); return; }
		if($hit === null) { $this->message = 'No terrain within reach. Fly closer or aim down.'; return; }
		[$wx,$wy,$wz] = $hit['position'];
		if($tool === 'road')
		{
			if(count($this->road) >= 64) throw new \RuntimeException('Apply or clear this road before adding more points.');
			$this->road[] = [$wx,$wy + $settings['roadRaise'],$wz];
			$this->message = count($this->road) . ' road points. Apply road when the route is ready.';
			return;
		}
		$operation = ['type' => $tool,'x' => $wx,'z' => $wz,'radius' => $settings['radius'],
			'amount' => in_array($tool, ['flatten','smooth'], true) ? .65 : $settings['amount']];
		if($tool === 'flatten') $operation['height'] = $settings['flattenHeight'];
		if($tool === 'material') $operation['material'] = $settings['material'];
		$this->editable()->queue($operation);
		$this->message = ucfirst($tool) . ' at ' . round($wx) . ', ' . round($wz) . ' m.';
	}

	/**
	 * Place or remove a gate where a click met the ground.
	 * A click on water places a floating water gate where the ray meets the water level. A click on land places a road
	 * gate on the ground when the gate height is zero, or an air gate that height above the ground. The gate faces the
	 * camera's heading at the click, so a vehicle passes through it traveling the way the camera looked.
	 * @param array|null $hit Exact ray intersection, or null when the ray met no ground in range.
	 * @param array $settings Authoring settings captured at the click.
	 * @param array $intent The click's ray and camera heading.
	 * @return void Changes the course and says what changed.
	 */
	private function pickGate(?array $hit, array $settings, array $intent): void
	{
		$role = $settings['gateRole'];
		if($role === 'remove')
		{
			$id = $this->course->nearest($intent['origin'], $intent['direction'], $hit['distance'] ?? self::PICK_RANGE);
			$gate = $id === null ? null : $this->course->remove($id);
			if($gate !== null) { $this->circuit = null; $this->circuitVariant = null; }
			$renumbered = $gate !== null && $gate['role'] === 'checkpoint'
				&& $this->checkpointCount() >= $gate['number'];
			$this->message = $gate === null ? 'No gate there. Click on a gate or the ground beside it to remove it.'
				: 'Removed ' . self::gateName($gate) . ($renumbered ? '. Later checkpoints moved up one number.' : '.');
			return;
		}
		if($hit === null) { $this->message = 'No terrain within reach. Fly closer or aim down.'; return; }
		[$x, $y, $z] = $hit['position'];
		$level = $this->water->level();
		if($y < $level)
		{
			$kind = 'water';
			[$ox, $oy, $oz] = $intent['origin'];
			[$dx, $dy, $dz] = $intent['direction'];
			// The ray crosses the water surface before it reaches the bed, and the gate floats where it crossed.
			if($oy > $level && $dy < 0)
			{
				$half = $this->landscape->recipe()['worldSize'] / 2;
				$t = ($level - $oy) / $dy;
				$x = max(-$half, min($half, $ox + $dx * $t));
				$z = max(-$half, min($half, $oz + $dz * $t));
			}
			$y = $level;
		}
		elseif($settings['gateAltitude'] > 0) { $kind = 'air'; $y += $settings['gateAltitude']; }
		else $kind = 'road';
		$yaw = atan2(sin($intent['yaw']), cos($intent['yaw']));
		$size = $this->gateShapes->fit($kind, $settings['gateWidth'] > 0 ? (float)$settings['gateWidth'] : null,
			$settings['gateHeight'] > 0 ? (float)$settings['gateHeight'] : null);
		$placed = $this->course->place($role, $kind, $x, $y, $z, $yaw, (int)$settings['gateLaps'], $size['width'],
			$size['height'], self::ground($this->landscape));
		if($placed['gate'] !== null) { $this->circuit = null; $this->circuitVariant = null; }
		if($placed['refused'] === 'full')
		{
			$this->message = 'A course holds ' . Course::MAX_CHECKPOINTS . ' checkpoints. Remove one to add another.';
			return;
		}
		$name = $role === 'checkpoint' ? 'Checkpoint ' . ($this->checkpointCount() + 1) : 'The ' . $role . ' gate';
		if($placed['refused'] === 'overlap')
		{
			$this->message = "$name would overlap " . self::gateName($placed['with'])
				. '. Place it clear of that gate.';
			return;
		}
		if($placed['merged'] && $role === 'finish')
		{
			$this->message = 'The finish is within one gate width of the start gate, so the start gate is now the start / '
				. 'finish gate.' . ($placed['removed'] !== null ? ' The finish gate that stood elsewhere is removed.' : '')
				. ($this->course->laps() > 1 ? ' The race runs ' . $this->course->laps() . ' laps.' : '');
			return;
		}
		if($kind === 'water') $this->course->float($this->water, $this->time);
		$gate = $placed['gate'];
		$where = ' at ' . (int)round($gate['x']) . ', ' . (int)round($gate['z']) . ' m';
		$this->message = ($placed['moved'] ? 'Moved ' . self::gateName($gate) . ' to' . substr($where, 3)
			: 'Placed ' . self::gateName($gate) . $where) . match($kind) {
				'water' => ', floating on the water',
				'air' => ', ' . round($settings['gateAltitude']) . ' m above the ground',
				'road' => ''
			} . ', its opening ' . GateShapes::meters($size['width']) . ' by ' . GateShapes::meters($size['height'])
			. ' m.' . ($size['limits'] ? ' ' . ucfirst(implode(', and ', $size['limits'])) . '.' : '')
			. ($placed['merged'] ? ' The finish gate stood within one gate width, so the start gate now also finishes.'
				: '');
	}

	/**
	 * Sample the ground height anywhere on a landscape, holding points beyond its edge to the edge.
	 * @param Landscape $land The landscape a course stands on.
	 * @return \Closure fn(float $x, float $z): float, as Course::place() and Course::import() take it.
	 */
	private static function ground(Landscape $land): \Closure
	{
		$half = $land->recipe()['worldSize'] / 2;
		return static fn(float $x, float $z): float
			=> $land->height(max(-$half, min($half, $x)), max(-$half, min($half, $z)));
	}

	/** How many checkpoints the course holds. */
	private function checkpointCount(): int
	{
		return count(array_filter($this->course->gates(false),
			static fn(array $gate): bool => $gate['role'] === 'checkpoint'));
	}

	/** Name a gate for a message: the start gate, checkpoint 3 or the finish gate. */
	private static function gateName(array $gate): string
	{
		return $gate['role'] === 'checkpoint' ? 'checkpoint ' . $gate['number'] : 'the ' . $gate['role'] . ' gate';
	}

	/**
	 * Convert the authoring sun angles to a normalized world direction.
	 * @return void Updates shared sunlight.
	 */
	private function sun(): void
	{
		$a = deg2rad($this->settings['azimuth']); $e = deg2rad($this->settings['elevation']);
		$this->atmosphere->setSun([sin($a) * cos($e),sin($e),cos($a) * cos($e)]);
	}

	/**
	 * Place the fly camera above the current landscape.
	 * @return void Clears craft mode and its terrain pin.
	 */
	private function resetCamera(): void
	{
		$half = $this->landscape->recipe()['worldSize'] / 2;
		$preset = $this->landscape->recipe()['preset'];
		if(isset(self::PRESET_VIEWS[$preset]))
		{
			$view = self::PRESET_VIEWS[$preset];
			$wavelength = 1 / $this->landscape->recipe()['baseFrequency'];
			$x = max(-.3*$half,min(.3*$half,$view['x']*$wavelength));
			$z = max(-.3*$half,min(.3*$half,$view['z']*$wavelength));
			$ground = max($this->landscape->height($x,$z),$this->water->level());
			$this->camera->position = [$x,max($view['minimum'],$ground+$view['clearance']),$z];
			$this->camera->yaw = deg2rad($view['heading']);
			$this->camera->pitch = $view['pitch'];
		}
		else
		{
			$coast = $preset === 'coast';
			$canyon = $preset === 'canyon';
			$x = $canyon ? -min(340, $half * .35) : min($coast ? 3600 : 600, $half * ($coast ? .22 : .15));
			$z = min($canyon ? 1600 : 1800, $half * .3);
			$ground = $this->landscape->height($x, $z);
			$height = $coast ? max(300, $this->water->level() + 300, $ground + 280)
				: ($canyon ? max(50, $this->water->level() + 120, $ground + 120)
					: max(500, $this->water->level() + 300, $ground + 400));
			$this->camera->position = [$x,$height,$z];
			$this->camera->yaw = $coast ? .7 : ($canyon ? 2.68 : -.3);
			$this->camera->pitch = $coast ? -.35 : ($canyon ? -.17 : -.25);
		}
		$this->mode = 'fly'; $this->carSearch = null; $this->releasePin();
	}

	/**
	 * Keep the fly camera inside the landscape and above its surface.
	 * @return void Limits physical position without changing terrain.
	 */
	private function clampCamera(): void
	{
		$half = $this->landscape->recipe()['worldSize'] / 2 - 2;
		$this->camera->position[0] = max(-$half, min($half, $this->camera->position[0]));
		$this->camera->position[2] = max(-$half, min($half, $this->camera->position[2]));
		$ground = $this->landscape->height($this->camera->position[0], $this->camera->position[2]);
		$this->camera->position[1] = max($ground + 2, min(15000, $this->camera->position[1]));
	}

	private function startCarSearch(): void
	{
		$this->cancelPick();
		$eye = $this->camera->position;
		$yaw = M_PI - $this->camera->yaw;
		$atStart = false;
		if($this->mode === 'car')
		{
			$car = $this->car->state();
			$eye = [$car['x'],$car['y'],$car['z']];
			$yaw = $car['yaw'];
		}
		elseif($this->mode === 'fly' && $this->circuitVariant === 'dry')
		{
			[$x,$z,$heading] = $this->circuit->approach();
			$atStart = hypot($x-$eye[0],$z-$eye[2]) < .1 && cos($heading-$yaw) > .999;
		}
		$floor = $this->delivery !== null ? PickupSearch::floorTest($this->landscape) : null;
		$this->carSearch = $this->car->findSite($this->landscape,$this->water,$eye,$yaw,$this->course->posts(),$atStart,$floor);
		$this->message = $floor !== null ? 'Looking for a dry canyon floor for the depot…' : 'Looking for level ground…';
	}

	public function findingCarSite(): bool { return $this->carSearch !== null; }

	/** Limit cold collision queries to a small slice of each input frame. */
	private function advanceCarSearch(): void
	{
		$end = hrtime(true) + 2000000;
		while($this->carSearch->valid())
		{
			$this->carSearch->next();
			if(hrtime(true) >= $end && $this->carSearch->valid()) return;
		}
		$site = $this->carSearch->getReturn();
		$this->carSearch = null;
		if($site === null)
		{
			$this->message = $this->delivery !== null ? 'No dry depot site nearby. Start a package run closer to the valley floor.'
				: 'No dry, level ground nearby. Fly closer to a plain or a graded road.';
			$this->delivery = null;
			return;
		}
		$this->releasePin();
		$this->car->spawn($site, $this->landscape, $this->water->level());
		$this->lamps->reset();
		$this->mode = 'car'; $this->paused = false; $this->boarding++;
		$this->chase->board($this->camera, $this->car->state(), $this->landscape);
		$this->dirty = true;
		$this->message = $this->delivery !== null ? 'Finding a reachable package site…'
			: 'W or RT accelerates, S or LT brakes then reverses, A/D or stick steer, Space or A handbrake, '
			. 'L or X headlights. F returns to flight.';
	}

	/** Return the matched camera and car pose at the engine's presentation time. */
	public function carView(float $alpha): array
	{
		return $this->chase->view($this->camera, $this->car->state(), $alpha);
	}

	/**
	 * Return the camera and vehicle pose to draw at the engine's presentation time. A chase camera ducks through any
	 * race gate it would otherwise pass through; the session's camera keeps the eye the vehicle's camera chose.
	 * @param float $alpha Fraction of a fixed step since the last one.
	 * @return array [Camera, vehicle pose or null in free flight]. The camera is the session's own when nothing moves it.
	 */
	public function view(float $alpha): array
	{
		[$eye, $pose] = match($this->mode) {
			'car' => $this->carView($alpha),
			'craft' => [$this->camera, $this->craft->state()],
			'jet' => $this->jet->view($this->camera, $this->paused ? 1.0 : $alpha),
			default => [$this->camera, null]
		};
		if($this->mode === 'jet' && $this->jetView === 'cockpit') return [Jet::cockpit($pose),$pose];
		if($this->mode === 'fly' || $this->course->count() === 0) return [$eye, $pose];
		return [$this->duck()->apply($eye, [$pose['x'], $pose['y'], $pose['z']], $this->nearGates($eye->position)),
			$pose];
	}

	/** Return the camera ducking for the current vehicle, boarding and course, starting a fresh one when any changed. */
	private function duck(): GateDuck
	{
		$for = $this->mode . ' ' . $this->boarding . ' ' . $this->course->revision;
		if($this->duck === null || $for !== $this->duckFor || $this->course !== $this->duckCourse)
		{
			$this->duck = new GateDuck(self::DUCK_LEAD[$this->mode], self::ground($this->landscape));
			$this->duckFor = $for;
			$this->duckCourse = $this->course;
		}
		return $this->duck;
	}

	/** List the gates that could move a chase camera's eye at this position. */
	private function nearGates(array $eye): array
	{
		return $this->course->count() === 0 ? []
			: $this->course->obstructions($eye, $this->duck()->reach());
	}

	/**
	 * Read the car's lamps at the engine's presentation time, for drawing beside carView().
	 * @param float $alpha Fraction of the latest fixed step to show. A paused car shows the step it stopped on, with
	 * the headlamps and pods of any change to the headlight switch since.
	 * @return array Lamp levels and the pods' opening as CarLamps::view() gives them.
	 */
	public function carLamps(float $alpha): array
	{
		return $this->lamps->view($this->paused ? 1.0 : $alpha);
	}

	/**
	 * Find nearby deep water and spawn the craft.
	 * @return void Starts craft mode or reports that no suitable water was found.
	 */
	private function boardCraft(): void
	{
		[$eyeX, , $eyeZ] = $this->camera->position;
		$half = max(0.0, $this->landscape->recipe()['worldSize'] / 2 - 16);
		$yaw = M_PI - $this->camera->yaw;
		$dx = sin($yaw); $dz = cos($yaw);
		$launch = null;

		// Board in place over deep water. When relocating, prefer open water along
		// the pilot's heading among the sites on the nearest navigable ring.
		for($ring = 0; $ring < 12 && $launch === null; $ring++)
		{
			$bestDistance = -1;
			$lookAhead = $ring === 0 ? 0 : 200;
			$sites = $ring === 0 ? 1 : 16;
			for($i = 0; $i < $sites; $i++)
			{
				$a = $i * M_PI / 8;
				$x = max(-$half, min($half, $eyeX + cos($a) * $ring * 200));
				$z = max(-$half, min($half, $eyeZ + sin($a) * $ring * 200));

				for($ahead = 0; $ahead <= $lookAhead; $ahead += 50)
				{
					$px = $x + $ahead * $dx;
					$pz = $z + $ahead * $dz;
					if(abs($px) > $half || abs($pz) > $half
						|| $this->landscape->height($px, $pz) >= $this->water->level() - 2) break;

					if($ahead > $bestDistance)
					{
						$bestDistance = $ahead;
						$launch = [$x, $z];
					}
				}
				if($bestDistance === $lookAhead) break;
			}
		}

		if($launch === null) throw new \RuntimeException('No deep water nearby. Try the coastal preset or fly closer to the coast.');
		$this->craft->spawn($launch[0], $launch[1], $yaw, $this->water, $this->landscape, $this->time);
		$this->mode = 'craft'; $this->paused = false; $this->reserveCraft(); $this->boarding++;
		$this->craftLook = 0.0;
		$this->message = 'W or RT throttle, S or LT reverse, A/D or stick steer. Return to flight to edit the landscape.';
	}

	/**
	 * Retain fine terrain data around the physical craft.
	 * @return void Refreshes the residency lease after enough travel.
	 */
	private function reserveCraft(): void
	{
		$s = $this->craft->state();
		if($this->pinCenter !== [] && hypot($s['x'] - $this->pinCenter[0], $s['z'] - $this->pinCenter[1]) < 4) return;
		$this->releasePin();
		$half = $this->landscape->recipe()['worldSize'] / 2;
		$this->pin = $this->landscape->pin([max(-$half, $s['x'] - 8),max(-$half, $s['z'] - 8),
			min($half, $s['x'] + 8),min($half, $s['z'] + 8)]);
		$this->pinCenter = [$s['x'],$s['z']];
	}

	/**
	 * Release the physical craft terrain lease.
	 * @return void Clears the lease id and its tracked center.
	 */
	private function releasePin(): void
	{
		if($this->pin !== null) $this->landscape->unpin($this->pin);
		$this->pin = null; $this->pinCenter = [];
	}

	/**
	 * Replace the entire landscape after generation or loading.
	 * @param Landscape $landscape Validated new landscape instance.
	 * @return void Retires the old surface and advances the renderer generation.
	 */
	private function replace(Landscape $landscape): void
	{
		$this->cancelPick(); $this->carSearch = null;
		$this->draft?->close(); $this->draft = null;
		$this->releasePin();
		$this->landscape->close();
		$this->landscape = $landscape;
		$this->duck = null;
		$this->balloons = new BalloonPractice;
		$this->delivery = null;
		$this->launches = 0;
		$this->setting('waterLevel', $landscape->recipe()['waterLevel']);
		$this->flightTrails = null;
		$this->road = []; $this->generation++; $this->mode = 'fly';
		$this->circuit = $this->circuitPreview = null; $this->circuitVariant = null; $this->circuitSearch = null;
		$this->circuitCommit = null;
		$this->race->reset();
	}

	/**
	 * Get a private clone for the next terrain transaction.
	 * @return Landscape Candidate that cannot affect the active physics before publication.
	 */
	private function editable(): Landscape
	{
		if($this->preparing) throw new \RuntimeException('Preparing the previous terrain edit. Please wait.');
		return $this->draft ??= Landscape::load($this->landscape->save());
	}

	/**
	 * Hand the candidate to rendering while physics retains the committed revision.
	 * @return Landscape|null Complete candidate, consumed once, or null when no edit is waiting.
	 */
	public function takeCandidate(): ?Landscape
	{
		if($this->draft === null) return null;
		$candidate = $this->draft; $this->draft = null;
		$this->preparing = true;
		return $candidate;
	}

	/**
	 * Adopt an edited surface only after its graphics representation is prepared.
	 * @param Landscape $landscape Prepared candidate returned by the renderer.
	 * @return void Publishes geometry and collision together at the input/update boundary.
	 */
	public function publishLandscape(Landscape $landscape): void
	{
		$this->circuitSearch = null; $this->circuitPreview = null;
		$this->circuit = null; $this->circuitVariant = null;
		$this->releasePin();
		$this->balloons->refit($landscape,$this->water->level());
		$this->message = $this->delivery?->refit($landscape,$this->water->level()) ?? $this->message;
		// Gates on the edited ground stand on it again.
		if($this->circuitCommit === null && $this->course->count() > 0)
			$this->course->refit(self::ground($landscape), $landscape->changedRegion($this->landscape));
		$this->landscape->close();
		$this->landscape = $landscape;
		$this->duck = null;
		if($this->circuitCommit !== null)
		{
			$this->carSearch = null;
			$this->activateCircuit(...$this->circuitCommit);
			$this->circuitCommit = null;
		}
		if($this->carSearch !== null) $this->startCarSearch();
		$this->preparing = false;
		$this->dirty = true;
	}

	/**
	 * Serialize terrain, controls, camera and race course in a versioned standalone JSON file.
	 * Version 2 added the course. Version 3 added each gate's size and renamed the gateHeight setting, the height an
	 * air gate hangs at, to gateAltitude; gateHeight now sizes a new gate's opening. Version 1 files load as
	 * landscapes without gates, and the gates of version 2 files take their kinds' own sizes.
	 * Version 4 adds color layers. Version 5 records a complete material stack, shared fields, origin and paint inks.
	 * @return string Complete portable JSON save document.
	 */
	public function save(): string
	{
		$stack = $this->activeLayers();
		$rows = $stack->layers;
		foreach($rows as &$row) if($row['parent'] >= 0) $row['parent'] = $stack->layers[$row['parent']]['id'];
		unset($row);
		$document = ['schema' => 'phonics-landscape-sandbox','version' => 5,
			'atmosphereLayers'=>$this->atmosphere->layers(),
			'atmosphereOrigin'=>$this->atmosphereOrigin,
			'surface'=>['origin'=>$this->surfaceLayers ? 'authored' : 'preset',
				'preset'=>$this->materialPreset ?? self::materialStyle($this->landscape->recipe()),
				'layers'=>$rows,'fields'=>$stack->fields,'bounce'=>$stack->bounce,'paint'=>$this->paintInks(),'paintAuthored'=>$this->paint !== null],

			'landscape' => json_decode($this->landscape->save(), true, flags: JSON_THROW_ON_ERROR),
			'settings' => $this->settings,'camera' => ['position' => $this->camera->position,
				'yaw' => $this->camera->yaw,'pitch' => $this->camera->pitch],
			'balloons' => $this->balloons->export(), 'course' => $this->course->export()];
		if($this->circuit !== null)
			$document['circuit'] = ['route'=>$this->circuit->export(),'variant'=>$this->circuitVariant];
		return json_encode($document, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
	}

	/** A circuit's derived coordinates can round differently in native and WASM math libraries. */
	private static function sameCircuitGates(array $expected, array $saved): bool
	{
		if(count($expected) !== count($saved)) return false;
		foreach($expected as $i => $gate)
		{
			$other = $saved[$i];
			foreach(['x', 'y', 'z', 'yaw'] as $axis)
			{
				$delta = abs($gate[$axis] - $other[$axis]);
				if($axis === 'yaw') $delta = min($delta, abs(2 * M_PI - $delta));
				if($delta > 1e-9) return false;
				unset($gate[$axis], $other[$axis]);
			}
			if($gate !== $other) return false;
		}
		return true;
	}

	/**
	 * Load a complete save only after validating its terrain and authoring settings.
	 * @param string $json UTF-8 saved sandbox document.
	 * @return void Replaces the landscape and restores authoring controls.
	 */
	public function load(string $json): void
	{
		if(strlen($json) > 4 * 1024 * 1024) throw new \InvalidArgumentException('Landscape file exceeds 4 MiB.');
		$data = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
		if(($data['schema'] ?? '') !== 'phonics-landscape-sandbox' || !in_array($data['version'] ?? 0, [1, 2, 3, 4, 5], true))
		throw new \InvalidArgumentException('This is not a supported landscape sandbox file.');
		$candidate = Landscape::load(json_encode($data['landscape'] ?? null, JSON_THROW_ON_ERROR));
		$circuit = null; $variant = null;
		try
		{
			$course = Course::import($data['course'] ?? null, $candidate->recipe()['worldSize'] / 2,
				self::ground($candidate), $this->gateShapes);
			if(isset($data['circuit']))
			{
				$savedCircuit = $data['circuit'];
				if(!is_array($savedCircuit) || !in_array($savedCircuit['variant'] ?? null,['dry','flooded'],true))
					throw new \InvalidArgumentException('Invalid saved circuit variant.');
				$variant = $savedCircuit['variant'];
				$circuit = Circuit::import($savedCircuit['route'] ?? null,$candidate->recipe()['worldSize']/2);
				// Validate older road gates at their original width. Refitting a wider span on a bank can change Y.
				$legacyWidth = $variant === 'dry' && $circuit->featureArea !== null && ($course->start()['width'] ?? null) === 30.;
				$derived = $circuit->course($candidate,$this->gateShapes,$course->laps(),$variant === 'flooded',$legacyWidth);
				$expected = $derived->export();
				$savedGates = $course->export();
				$oldRoadHeight = $this->gateShapes->kind('road')['size']['default'][1];
				if($variant === 'dry' && ($course->start()['height'] ?? null) === $oldRoadHeight)
				{
					// Older generated circuits used the stock road height for every gate.
					foreach($expected['gates'] as &$gate) $gate['height'] = $oldRoadHeight;
					unset($gate);
				}
				if(!self::sameCircuitGates($expected['gates'],$savedGates['gates']))
					throw new \InvalidArgumentException('The saved circuit and its gates disagree.');
				$course = $derived;
				if($legacyWidth)
				{
					try { $course = $circuit->course($candidate,$this->gateShapes,$course->laps(),false); }
					catch(\RangeException|\OutOfBoundsException) { $course = $derived; } // Tight legacy layout or map edge.
				}
			}
		}
		catch(\RuntimeException | \InvalidArgumentException $error) { $candidate->close(); throw $error; }
		$settings = $data['settings'] ?? [];
		if(!is_array($settings)) throw new \InvalidArgumentException('Invalid saved settings.');
		$settings += (self::PRESET_LOOK[$candidate->recipe()['preset']] ?? []) + self::SKY_DEFAULTS;
		// Before version 3, gateHeight was the height an air gate hangs at.
		if($data['version'] < 3 && array_key_exists('gateHeight', $settings))
		{
			$settings['gateAltitude'] = $settings['gateHeight'];
			unset($settings['gateHeight']);
		}
		try
		{
			// Both appearances come from the whole file at once, so a blend's start and end apply together, and a bad
			// file throws here, before anything changes. A file without terrain settings draws the defaults.
			if(array_key_exists('atmosphereLayers',$data) && !is_array($data['atmosphereLayers']))
				throw new \InvalidArgumentException('Invalid saved atmosphere layers.');
			$air = new AtmosphereLayers($data['atmosphereLayers'] ?? [], $this->atmosphere->uniforms()['atmosphereHeight']);
			// Earlier saves used one fixed sky across landforms, including saves with clear air.
			$airOrigin = array_key_exists('atmosphereOrigin', $data) ? $data['atmosphereOrigin'] : 'authored';
			if(!in_array($airOrigin, ['preset', 'authored'], true))
				throw new \InvalidArgumentException('Invalid saved atmosphere origin.');
			$appearance = new WaterAppearance($settings);
			$terrain = new TerrainAppearance($settings);
			$generated = SurfaceLayers::landscape($candidate->recipe(),$terrain);
			$style = self::materialStyle($candidate->recipe());
			$origin = null;
			$paint = null;
			$layers = null;
			if($data['version'] === 5) {
				$surface = $data['surface'] ?? [];
				if(!is_array($surface) || !in_array($surface['origin'] ?? null,['preset','authored'],true)
					|| !in_array($surface['preset'] ?? null,self::SURFACES,true)
					|| !is_array($surface['layers'] ?? null) || !is_array($surface['fields'] ?? null)
					|| !is_array($surface['bounce'] ?? null) || !is_array($surface['paint'] ?? null)
					|| array_diff(SurfaceLayers::INKS,array_keys($surface['paint'])) || array_diff(array_keys($surface['paint']),SurfaceLayers::INKS))
					throw new \InvalidArgumentException('Invalid saved material stack or paint inks.');
				$saved = new SurfaceLayers($surface['layers'],$surface['fields'],$surface['bounce']);
				$inkPalette = new TerrainAppearance($surface['paint']);
				$paint = ($surface['paintAuthored'] ?? true) ? $inkPalette : null;
				if(isset($surface['paintAuthored']) && !is_bool($surface['paintAuthored']))
					throw new \InvalidArgumentException('Invalid saved paint origin.');
				if(!$paint && array_intersect_key($terrain->settings,$surface['paint']) != $surface['paint']) $paint=$inkPalette;
				if($surface['origin'] === 'authored') { $origin = $surface['preset']; $layers = $saved; }
				else $generated = $saved;
			} elseif(isset($data['layers'])) {
				$layers = new SurfaceLayers($data['layers'],$generated->fields,$generated->bounce);
				$origin = $style;
			}
			if($this->prepareMaterial) ($this->prepareMaterial)($layers ?? $generated);
			$settings = array_diff_key($settings, WaterAppearance::DEFAULTS, TerrainAppearance::DEFAULTS)
				+ ['waterChoppiness' => 100.0, 'nightGlow' => Atmosphere::DEFAULT_NIGHT_GLOW];
			// Check each value against its setting's default, not the value now in use: a setting that defaults to
			// null, such as circuitStartHeight, may hold a number now and be blank in the file.
			$defaults = (new \ReflectionProperty(self::class, 'settings'))->getDefaultValue();
			foreach($settings as $name => $value)
			if(isset($this->settings[$name]) && is_numeric($this->settings[$name])
				&& !($value === null && array_key_exists($name, $defaults) && $defaults[$name] === null)
				&& (!is_numeric($value) || !is_finite((float)$value)))
			throw new \InvalidArgumentException('Invalid saved control value.');
			$balloons = BalloonPractice::load(array_key_exists('balloons',$data) ? $data['balloons'] : [],
				$candidate,$candidate->recipe()['waterLevel']);
			$position = $data['camera']['position'] ?? [0,600,1200];
			if(!is_array($position) || !array_is_list($position) || count($position) !== 3 || count(array_filter($position, 'is_numeric')) !== 3
				|| count(array_filter(array_map('floatval', $position), 'is_finite')) !== 3)
			throw new \InvalidArgumentException('Invalid saved camera position.');
			$yaw = $data['camera']['yaw'] ?? 0; $pitch = $data['camera']['pitch'] ?? -.25;
			if(!is_numeric($yaw) || !is_numeric($pitch) || !is_finite((float)$yaw) || !is_finite((float)$pitch))
			throw new \InvalidArgumentException('Invalid saved camera direction.');
		}
		catch(\Throwable $error) { $candidate->close(); throw $error; }
		$this->replace($candidate);
		$this->balloons = $balloons;
		$this->atmosphere->setLayers($air->rows);
		$this->atmosphereOrigin = $airOrigin;
		$this->setWaterAppearance($appearance);
		$this->setTerrainAppearance($terrain);
		$this->surfaceLayers=$layers;
		$this->materialPreset = $origin;
		$this->paint = $paint;
		$this->generatedLayers = $generated;
		$this->layerAppearance = $this->terrainAppearance;
		$this->layerRecipe = $candidate->recipe();
		foreach($settings as $name => $value) $this->setting((string)$name, $value);
		$this->course = $course;
		$this->circuit = $circuit; $this->circuitVariant = $variant;
		if($course->start() !== null) $this->settings['gateLaps'] = $course->start()['laps'];
		$this->course->float($this->water, $this->time);
		$this->camera->position = array_map('floatval', $position);
		$this->camera->yaw = (float)$yaw; $this->camera->pitch = max(-1.52, min(1.52, (float)$pitch));
		$this->clampCamera();
		$gates = $course->count();
		$this->message = match($gates) {
			0 => 'Landscape loaded, including terrain edits and environment settings.',
			1 => 'Landscape loaded, including terrain edits, environment settings and one gate.',
			default => "Landscape loaded, including terrain edits, environment settings and $gates gates.",
		};
		$this->dirty = true;
	}

	/**
	 * Consume a requested download once.
	 * @return string|null JSON to download, or null when no save is waiting.
	 */
	public function download(): ?string { $value = $this->download; $this->download = null; return $value; }

	/**
	 * Report UI state without serializing the terrain cache.
	 * @return array Authoring, camera and simulation diagnostics.
	 */
	public function status(): array
	{
		return ['mode' => $this->mode,'paused' => $this->paused,'time' => $this->time,'generation' => $this->generation,
			'findingCarSite' => $this->findingCarSite(),'preparing' => $this->preparing,'picking' => $this->picking,
			'pickProgress' => $this->pickQuery?->stats(),'lastPick' => $this->lastPick,'recipe' => $this->landscape->recipe(),'revision' => $this->landscape->revision(),
			'atmosphereLayers'=>$this->atmosphere->layers(),'atmosphereOrigin'=>$this->atmosphereOrigin,'atmosphereReply'=>$this->atmosphereReply,
			'settings' => $this->settings,'surfaceLayers'=>$this->activeLayers()->layers,'materialReply'=>$this->materialReply,'surfaceOrigin'=>$this->surfaceLayers ? 'authored' : 'preset',
			'surfacePreset'=>$this->materialPreset ?? self::materialStyle($this->landscape->recipe()),
			'surfaceFields'=>$this->activeLayers()->fields,'paintInks'=>$this->paintInks(),
			'surfaceStyle' => TerrainAppearance::style($this->landscape->recipe()),
			'selectionInterval' => $this->selectionInterval,
			'selectionSliceMs' => $this->selectionSliceMs,
			'jetView' => $this->jetView, 'jetMap' => $this->jetMap, 'jetWideMap' => $this->jetWideMap, 'balloons' => $this->balloons->status(),
			'jetControls' => $this->jetControls,'invertPitch' => $this->invertPitch,
			'carMap' => $this->carMap,'carWideMap' => $this->carWideMap,'headlights' => $this->headlights,'headlightsLit' => $this->headlightsLit(),
			'roadPoints' => $this->road,'message' => $this->message,
			'camera' => ['position' => $this->camera->position,'yaw' => $this->camera->yaw,'pitch' => $this->camera->pitch,'fov' => $this->camera->fov],
			'duck' => $this->mode === 'fly' || $this->duck === null ? null
				: ['offset' => $this->duck->offset, 'passages' => $this->duck->held()],
			'jet' => $this->mode === 'jet' ? $this->jet->state() : null,
			'car' => $this->mode === 'car' ? $this->car->state() : null,
			'delivery' => $this->delivery === null ? null : ['carrying'=>$this->delivery->carrying,
				'delivered'=>$this->delivery->delivered, 'target'=>$this->delivery->target(), 'depot'=>$this->delivery->depot],
			'lamps' => $this->mode === 'car' ? $this->lamps->view(1.0) : null,
			'craft' => $this->mode === 'craft' ? $this->craft->state() : null,'surface' => $this->landscape->stats(),
			'course' => $this->courseStatus(),'gateSizes' => $this->gateSizes(),'circuit' => $this->circuitStatus(),
			'race' => $this->race->status($this->course, $this->time, $this->mode === 'fly' ? null : $this->mode,
				$this->water->level())];
	}

	private function circuitStatus(): array
	{
		$plan = $this->circuitPreview ?? $this->circuit;
		return ['searching'=>$this->findingCircuit(),'preview'=>$this->circuitPreview !== null,
			'active'=>$this->circuitVariant,'points'=>$plan?->points,
			'activePoints'=>$this->circuitPreview !== null ? $this->circuit?->points : null,'length'=>$plan?->length,
			'featureArea'=>$plan?->featureArea,'gradeWidth'=>$this->circuitPreview?->road ? CircuitRoad::WIDTH : null,
			'heightDifference'=>$plan?->heightDifference,'startHeight'=>$plan?->startHeight,
			'floodedLevel'=>$plan?->floodedLevel,'dryLevel'=>$plan?->dryLevel,'gates'=>$plan?->gates()];
	}

	/**
	 * Describe the course for the page and the renderer.
	 * @return array Revision, laps and every gate with its pose now, its print and, on a checkpoint, its number.
	 */
	private function courseStatus(): array
	{
		$gates = [];
		foreach($this->course->gates() as $gate)
			$gates[] = ['id' => $gate['id'], 'role' => $gate['role'], 'kind' => $gate['kind'],
				'print' => $this->course->print($gate), 'number' => $gate['number'] ?? null,
				'laps' => $gate['laps'] ?? null,
				'x' => $gate['x'], 'y' => $gate['y'], 'z' => $gate['z'], 'yaw' => $gate['yaw'],
				'width' => $gate['width'], 'height' => $gate['height']];
		return ['revision' => $this->course->revision, 'laps' => $this->course->laps(), 'gates' => $gates];
	}

	/**
	 * Describe the opening sizes each kind of gate takes, for the gate tool.
	 * @return array<string,array{width:float,height:float,widths:array,heights:array,stretch:?float}> By kind, the
	 * kind's own width and height in meters, the [least, greatest] of each, and for the water arch the most its
	 * scale up may exceed its scale across, or the reverse.
	 */
	private function gateSizes(): array
	{
		$sizes = [];
		foreach(GateShapes::KINDS as $kind)
		{
			$size = $this->gateShapes->kind($kind)['size'];
			$sizes[$kind] = ['width' => $size['default'][0], 'height' => $size['default'][1], 'widths' => $size['width'],
				'heights' => $size['height'], 'stretch' => $size['stretch']];
		}
		return $sizes;
	}

	/**
	 * Release terrain sample ownership and pending UI data.
	 * @return void Retires the active surface and any unsubmitted candidate.
	 */
	public function close(): void
	{
		$this->cancelPick(); $this->carSearch = null;
		$this->circuitSearch = null;
		$this->releasePin(); $this->landscape->close(); $this->draft?->close(); $this->draft = null;
		$this->keys = $this->road = [];
		$this->pad = self::NO_PAD;
	}
}
