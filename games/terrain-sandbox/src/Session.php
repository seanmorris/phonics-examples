<?php
declare(strict_types=1);
namespace TerrainSandbox;

use TerrainPhonics\Atmosphere;
use TerrainPhonics\Camera;
use TerrainPhonics\Landscape;
use TerrainPhonics\RayQuery;
use TerrainPhonics\Scene;
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
	public Jet $jet;
	/** Race gates on the landscape. Saved with it, and replaced when a file loads or a new landscape is generated. */
	public Course $course;
	/** Timed run through the course by the vehicle being driven, ridden or flown. */
	public Race $race;
	/** Dimensions of each gate kind, for placement, crossings, car collision and drawing. */
	public readonly GateShapes $gateShapes;
	/** Counts vehicle boardings and respawns, so a race can tell when its vehicle was put somewhere new. */
	private int $boarding = 0;
	private CarChase $chase;
	private ?\Generator $carSearch = null;
	/** Whether simulation is paused; authoring remains available. */
	public bool $paused = false;
	/** Current interaction mode: fly, craft, car or jet. */
	public string $mode = 'fly';
	/** Simulation clock in seconds. */
	public float $time = 0;
	/** UI configuration, serialized alongside the terrain. */
	public array $settings = [
		'tool' => 'navigate', 'radius' => 40.0, 'amount' => 12.0, 'flattenHeight' => 0.0,
		'material' => 'grass', 'roadWidth' => 8.0, 'roadShoulder' => 12.0, 'roadRaise' => 0.0,
		'azimuth' => 125.0, 'elevation' => 35.0, 'haze' => 1.0, 'exposure' => 1.0,
		'nightGlow' => Atmosphere::DEFAULT_NIGHT_GLOW, 'clock' => false, 'quality' => 1.0, 'speed' => 100.0,
		'waterLevel' => 0.0, 'waterChoppiness' => 100.0,
		'gateRole' => 'start', 'gateLaps' => 1, 'gateHeight' => 0.0,
	] + WaterAppearance::DEFAULTS;
	/** Gate tool roles. Remove deletes the gate nearest the click. */
	public const GATE_ROLES = ['start', 'checkpoint', 'finish', 'remove'];
	/** Distance in metres a pick ray reaches when it hits no ground. */
	private const PICK_RANGE = 6000.0;
	/** Uncommitted road control points in [x,y,z] metres. */
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
	/** Buttons the page reports in the last element of a pad packet, one bit each, while they are held. */
	public const PAD_A = 1, PAD_B = 2, PAD_X = 4, PAD_Y = 8, PAD_LB = 16, PAD_RB = 32, PAD_LS = 64, PAD_RS = 128;
	/** A gamepad at rest: both sticks centred, both triggers released and no buttons held. */
	private const NO_PAD = ['x' => 0.0, 'y' => 0.0, 'lookX' => 0.0, 'lookY' => 0.0, 'lt' => 0.0, 'rt' => 0.0, 'buttons' => 0];
	private array $keys = [];
	/** Gamepad state from the page: sticks run -1 to 1 with +Y towards the player, triggers 0 to 1. */
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
		$this->gateShapes = $gateShapes ?? GateShapes::packaged();
		$this->course = new Course;
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
		$this->craft = new Watercraft;
		$this->car = new Car;
		$this->jet = new Jet;
		$this->chase = new CarChase;
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
		$this->keys = is_array($input['keys'] ?? null) ? $input['keys'] : [];
		$this->pad = self::readPad($input['pad'] ?? null);
		try
		{
			$wasPicking = $this->picking;
			foreach($input['values'] ?? [] as $name => $value)
			{
				// load() passes every saved name to setting(). Taking these names here keeps them out of reach of files.
				if($name === 'selectionInterval') $this->setSelectionInterval($value);
				elseif($name === 'selectionSliceMs') $this->setSelectionSliceMs($value);
				elseif($name === 'jetControls') $this->setJetControls($value);
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
			if($this->draft !== null) { $this->draft->commit(); $this->dirty = true; }
		}
		catch(\Throwable $error)
		{
			$this->draft?->close(); $this->draft = null;
			$this->cancelPick(); $this->carSearch = null;
			$this->message = $error->getMessage();
			$this->dirty = true;
		}
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
		$racing = $this->course->count() > 0 && $this->mode !== 'fly';
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
				$this->course->posts($this->gateShapes)))
			{
				$this->mode = 'fly';
				$this->clampCamera();
				$this->message = 'The ground under the car changed. Choose Drive car to find another spot.';
				$this->dirty = true;
			}
			elseif($this->chase->advance($this->camera, $before, $this->car->state(), $this->landscape, $seconds))
				$this->dirty = true;
		}
		if($this->mode === 'jet')
		{
			$pad = $this->pad;
			// Pulling the stick back raises the nose unless the player inverted it.
			$climb = $this->blend('KeyQ', 'KeyE', $this->invertPitch ? -$pad['y'] : $pad['y']);
			$rudder = $this->blend('KeyZ', 'KeyX', ($pad['buttons'] & self::PAD_RB ? 1 : 0) - ($pad['buttons'] & self::PAD_LB ? 1 : 0));
			if(!$this->jet->step($seconds, $this->blend('KeyS', 'KeyW', $pad['rt'] - $pad['lt']),
				$this->blend('KeyA', 'KeyD', $pad['x']), $climb,
				$this->camera, $this->landscape, $this->water->level(), $rudder))
			{
				$this->mode = 'fly';
				$this->clampCamera();
				$this->message = 'Reached the edge of the landscape. Free flight controls active.';
			}
			$this->dirty = true;
		}
		$start = $this->time;
		$this->time += $seconds;
		$this->water->update($this->time);
		// The application redraws for wave time whenever water shows, which covers the gates that float on it.
		if($this->course->floats()) $this->course->float($this->water, $this->time);
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
	 * @return array [x, y, z] in metres, or an empty list in free flight.
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
		$event = $this->race->advance($this->course, $this->gateShapes, $from, $this->racePoint(), $start, $seconds,
			$mode, $this->boarding);
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

	/** Describe the jet's controls for the current handling. */
	private function jetMessage(): string
	{
		return $this->jetControls === 'expert'
			? 'Expert · A/D or stick roll, E/Q or stick pitch, Z/X or LB/RB rudder, hold W/S or RT/LT for speed. F leaves the jet.'
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
		$ranges = ['radius' => [2,300],'amount' => [.1,200],'flattenHeight' => [-1000,3000],
			'roadWidth' => [2,80],'roadShoulder' => [1,120],'roadRaise' => [-100,100],
			'azimuth' => [0,360],'elevation' => [-12,88],'haze' => [0,5],'exposure' => [.1,4], 'nightGlow' => [0,1],
			'quality' => [.5,2],'speed' => [5,300],'gateHeight' => [0.0,400.0],
			'waterLevel' => [-10000,10000],'waterChoppiness' => [0,300]];
		if(isset($ranges[$name]))
		{
			if(!is_numeric($value) || !is_finite((float)$value)) throw new \InvalidArgumentException('Invalid control value');
			[$min,$max] = $ranges[$name];
			$value = max($min, min($max, (float)$value));
		}
		elseif($name === 'clock') $value = (bool)$value;
		elseif($name === 'tool' && !in_array($value, ['navigate','raise','lower','flatten','smooth','crater','material','road','gate'], true)) return;
		elseif($name === 'material' && !in_array($value, ['grass','rock','sand','snow','road'], true)) return;
		elseif($name === 'gateRole' && !in_array($value, self::GATE_ROLES, true)) return;
		elseif($name === 'gateLaps')
		{
			if(!is_numeric($value) || !is_finite((float)$value))
				throw new \InvalidArgumentException('Invalid control value');
			$value = (int)max(1, min(Course::MAX_LAPS, round((float)$value)));
			if($this->course->setLaps($value))
				$this->message = $value === 1 ? 'The race runs one lap.' : "The race runs $value laps.";
		}
		if($name === 'waterLevel' || $name === 'waterChoppiness')
		{
			if($name === 'waterLevel') $this->water->setLevel($value);
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
		switch($action['type'] ?? '')
		{
			case 'pause':
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
			case 'generate':
				$recipe = $this->landscape->recipe();
				$preset = (string)($action['preset'] ?? 'coast');
				if($preset !== $recipe['preset'] || ($preset === 'canyon' && $recipe['version'] === 1)) unset($recipe['terraceStrength']);
				$recipe['preset'] = $preset;
				$recipe['seed'] = (string)($action['seed'] ?? 'first-light');
				unset($recipe['version']); // A newly generated world uses the current preset's recipe.
				$defaults = match($recipe['preset']) {
					'mountain' => [1400.0,1 / 2200], 'canyon' => [650.0,1 / 4000], default => [420.0,1 / 6000]
				};
				$recipe['heightScale'] = $defaults[0]; $recipe['baseFrequency'] = $defaults[1];
				$recipe['terraceSpacing'] = $recipe['preset'] === 'canyon' ? 80.0 : 0.0;
				$recipe['waterLevel'] = $recipe['preset'] === 'canyon' ? -450.0 : 0.0;
				foreach(['heightScale','detailAmplitude','slopeScale','damping','waterLevel','warpAmplitude','terraceSpacing'] as $key)
				if(isset($action[$key]))
				{
					if(!is_numeric($action[$key]) || !is_finite((float)$action[$key]))
					throw new \InvalidArgumentException('Invalid terrain generation parameter.');
					$recipe[$key] = (float)$action[$key];
				}
				$this->replace(new Landscape($recipe));
				$this->resetCamera();
				$this->message = 'Generated ' . $recipe['preset'] . ' terrain. Detail streams in as you fly.';
				if($this->course->count() > 0) $this->message .= ' The new landscape starts without gates.';
				$this->course = new Course;
				break;
			case 'gates-clear':
				$count = $this->course->clear();
				$this->message = match($count) {
					0 => 'There are no gates to clear.', 1 => 'Removed the only gate.',
					default => "Removed all $count gates."
				};
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
			case 'car':
				if($this->mode !== 'car' && $this->carSearch === null)
				{
					$this->cancelPick();
					$this->carSearch = $this->car->findSite($this->landscape, $this->water, $this->camera->position,
						M_PI - $this->camera->yaw, $this->course->posts($this->gateShapes));
					$this->message = 'Looking for level ground…';
				}
				break;
			case 'jet':
				if($this->mode === 'jet') break;
				$this->cancelPick();
				$this->carSearch = null;
				$this->releasePin();
				$this->jet->setExpert($this->jetControls === 'expert');
				$this->jet->board($this->camera, $this->landscape, $this->water->level());
				$this->mode = 'jet'; $this->paused = false; $this->boarding++;
				$this->message = $this->jetMessage();
				break;
			case 'jet-controls': $this->setJetControls($this->jetControls === 'expert' ? 'novice' : 'expert'); break;
			case 'craft': $this->cancelPick(); $this->carSearch = null; $this->boardCraft(); break;
			case 'fly':
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
	 * camera's heading at the click, so a vehicle passes through it travelling the way the camera looked.
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
			$id = $this->course->nearest($intent['origin'], $intent['direction'], $hit['distance'] ?? self::PICK_RANGE,
				$this->gateShapes);
			$gate = $id === null ? null : $this->course->remove($id);
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
		elseif($settings['gateHeight'] > 0) { $kind = 'air'; $y += $settings['gateHeight']; }
		else $kind = 'road';
		$yaw = atan2(sin($intent['yaw']), cos($intent['yaw']));
		$placed = $this->course->place($role, $kind, $x, $y, $z, $yaw, (int)$settings['gateLaps']);
		if($placed === null)
		{
			$this->message = 'A course holds ' . Course::MAX_CHECKPOINTS . ' checkpoints. Remove one to add another.';
			return;
		}
		if($kind === 'water') $this->course->float($this->water, $this->time);
		$where = ' at ' . (int)round($x) . ', ' . (int)round($z) . ' m';
		$this->message = ($placed['moved'] ? 'Moved ' . self::gateName($placed['gate']) . ' to' . substr($where, 3)
			: 'Placed ' . self::gateName($placed['gate']) . $where) . match($kind) {
				'water' => ', floating on the water.',
				'air' => ', ' . round($settings['gateHeight']) . ' m above the ground.',
				'road' => '.'
			};
	}

	/** How many checkpoints the course holds. */
	private function checkpointCount(): int
	{
		return count(array_filter($this->course->gates(),
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
		$coast = $this->landscape->recipe()['preset'] === 'coast';
		$canyon = $this->landscape->recipe()['preset'] === 'canyon';
		$x = $canyon ? -min(340, $half * .35) : min($coast ? 3600 : 600, $half * ($coast ? .22 : .15));
		$z = min($canyon ? 1600 : 1800, $half * .3);
		$ground = $this->landscape->height($x, $z);
		$height = $coast ? max(300, $this->water->level() + 300, $ground + 280)
			: ($canyon ? max(50, $this->water->level() + 120, $ground + 120)
				: max(500, $this->water->level() + 300, $ground + 400));
		$this->camera->position = [$x,$height,$z];
		$this->camera->yaw = $coast ? .7 : ($canyon ? 2.68 : -.3);
		$this->camera->pitch = $coast ? -.35 : ($canyon ? -.17 : -.25);
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
			$this->message = 'No dry, level ground nearby. Fly closer to a plain or a graded road.';
			return;
		}
		$this->releasePin();
		$this->car->spawn($site, $this->landscape, $this->water->level());
		$this->mode = 'car'; $this->paused = false; $this->boarding++;
		$this->chase->board($this->camera, $this->car->state(), $this->landscape);
		$this->dirty = true;
		$this->message = 'W or RT accelerates, S or LT brakes then reverses, A/D or stick steer, Space or A handbrake. F returns to flight.';
	}

	/** Return the matched camera and car pose at the engine's presentation time. */
	public function carView(float $alpha): array
	{
		return $this->chase->view($this->camera, $this->car->state(), $alpha);
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
	 * @return void Clears the lease id and its tracked centre.
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
		$this->setting('waterLevel', $landscape->recipe()['waterLevel']);
		$this->road = []; $this->generation++; $this->mode = 'fly';
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
		$this->releasePin();
		$this->landscape->close();
		$this->landscape = $landscape;
		if($this->carSearch !== null)
		{
			$this->carSearch = $this->car->findSite($landscape, $this->water, $this->camera->position,
				M_PI - $this->camera->yaw, $this->course->posts($this->gateShapes));
		}
		$this->preparing = false;
		$this->dirty = true;
	}

	/**
	 * Serialize terrain, controls, camera and race course in a versioned standalone JSON file.
	 * Version 2 added the course. Version 1 files load as landscapes without gates.
	 * @return string Complete portable JSON save document.
	 */
	public function save(): string
	{
		return json_encode(['schema' => 'phonics-landscape-sandbox','version' => 2,
			'landscape' => json_decode($this->landscape->save(), true, flags: JSON_THROW_ON_ERROR),
			'settings' => $this->settings,'camera' => ['position' => $this->camera->position,
				'yaw' => $this->camera->yaw,'pitch' => $this->camera->pitch],
			'course' => $this->course->export()], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
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
		if(($data['schema'] ?? '') !== 'phonics-landscape-sandbox' || !in_array($data['version'] ?? 0, [1, 2], true))
		throw new \InvalidArgumentException('This is not a supported landscape sandbox file.');
		$candidate = Landscape::load(json_encode($data['landscape'] ?? null, JSON_THROW_ON_ERROR));
		try { $course = Course::import($data['course'] ?? null, $candidate->recipe()['worldSize'] / 2); }
		catch(\InvalidArgumentException $error) { $candidate->close(); throw $error; }
		$settings = $data['settings'] ?? [];
		if(!is_array($settings)) throw new \InvalidArgumentException('Invalid saved settings.');
		$appearance = new WaterAppearance($settings);
		$settings = array_diff_key($settings, WaterAppearance::DEFAULTS) + ['waterChoppiness' => 100.0, 'nightGlow' => Atmosphere::DEFAULT_NIGHT_GLOW];
		foreach($settings as $name => $value)
		if(isset($this->settings[$name]) && is_numeric($this->settings[$name]) && (!is_numeric($value) || !is_finite((float)$value)))
		throw new \InvalidArgumentException('Invalid saved control value.');
		$position = $data['camera']['position'] ?? [0,600,1200];
		if(!is_array($position) || !array_is_list($position) || count($position) !== 3 || count(array_filter($position, 'is_numeric')) !== 3
			|| count(array_filter(array_map('floatval', $position), 'is_finite')) !== 3)
		throw new \InvalidArgumentException('Invalid saved camera position.');
		$yaw = $data['camera']['yaw'] ?? 0; $pitch = $data['camera']['pitch'] ?? -.25;
		if(!is_numeric($yaw) || !is_numeric($pitch) || !is_finite((float)$yaw) || !is_finite((float)$pitch))
		throw new \InvalidArgumentException('Invalid saved camera direction.');
		$this->replace($candidate);
		$this->setWaterAppearance($appearance);
		foreach($settings as $name => $value) $this->setting((string)$name, $value);
		$this->course = $course;
		if($course->laps() !== null) $this->settings['gateLaps'] = $course->laps();
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
			'settings' => $this->settings,'selectionInterval' => $this->selectionInterval,
			'selectionSliceMs' => $this->selectionSliceMs,
			'jetControls' => $this->jetControls,'invertPitch' => $this->invertPitch,
			'roadPoints' => $this->road,'message' => $this->message,
			'camera' => ['position' => $this->camera->position,'yaw' => $this->camera->yaw,'pitch' => $this->camera->pitch,'fov' => $this->camera->fov],
			'jet' => $this->mode === 'jet' ? $this->jet->state() : null,
			'car' => $this->mode === 'car' ? $this->car->state() : null,
			'craft' => $this->mode === 'craft' ? $this->craft->state() : null,'surface' => $this->landscape->stats(),
			'course' => $this->courseStatus(),'race' => $this->race->status($this->course, $this->time)];
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
				'x' => $gate['x'], 'y' => $gate['y'], 'z' => $gate['z'], 'yaw' => $gate['yaw']];
		return ['revision' => $this->course->revision, 'laps' => $this->course->laps(), 'gates' => $gates];
	}

	/**
	 * Release terrain sample ownership and pending UI data.
	 * @return void Retires the active surface and any unsubmitted candidate.
	 */
	public function close(): void
	{
		$this->cancelPick(); $this->carSearch = null;
		$this->releasePin(); $this->landscape->close(); $this->draft?->close(); $this->draft = null;
		$this->keys = $this->road = [];
		$this->pad = self::NO_PAD;
	}
}
