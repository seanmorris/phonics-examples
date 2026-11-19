<?php
declare(strict_types=1);
namespace TerrainSandbox;

use TerrainPhonics\Atmosphere;
use TerrainPhonics\Camera;
use TerrainPhonics\Landscape;
use TerrainPhonics\RayQuery;
use TerrainPhonics\Water;
use TerrainPhonics\WaterAppearance;
use TerrainPhonics\Watercraft;

/**
 * Offline landscape authoring session with fixed-step watercraft simulation.
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
	/** Perspective camera used for flight and craft chase view. */
	public Camera $camera;
	/** Controllable physical watercraft. */
	public Watercraft $craft;
	/** Whether simulation is paused; authoring remains available. */
	public bool $paused = false;
	/** Current interaction mode: fly or craft. */
	public string $mode = 'fly';
	/** Simulation clock in seconds. */
	public float $time = 0;
	/** UI configuration, serialized alongside the terrain. */
	public array $settings = [
		'tool' => 'navigate', 'radius' => 40.0, 'amount' => 12.0, 'flattenHeight' => 0.0,
		'material' => 'grass', 'roadWidth' => 8.0, 'roadShoulder' => 12.0, 'roadRaise' => 0.0,
		'azimuth' => 125.0, 'elevation' => 35.0, 'haze' => 1.0, 'exposure' => 1.0,
		'clock' => false, 'quality' => 1.0, 'speed' => 100.0,
	] + WaterAppearance::DEFAULTS;
	/** Uncommitted road control points in [x,y,z] metres. */
	public array $road = [];
	/** Last operation result shown in the authoring panel. */
	public string $message = 'Right-drag to look. WASD to fly, Q/E down/up. Choose a tool to edit.';
	/** Whether input or simulation changed the visible scene. */
	public bool $dirty = true;
	/** Incremented for whole landscape replacement; staged edits preserve this generation. */
	public int $generation = 0;
	private array $keys = [];
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
	 * @return void Creates a flyable landscape with explicit physical swells.
	 */
	public function __construct(array $recipe = [])
	{
		$this->landscape = new Landscape($recipe + ['preset' => 'coast']);
		$this->water = new Water($this->landscape->recipe()['waterLevel'], [
			['amplitude' => .48,'wavelength' => 34,'direction' => [1,.35]],
			['amplitude' => .23,'wavelength' => 17,'direction' => [-.25,1],'phase' => 1.2],
			['amplitude' => .09,'wavelength' => 8,'direction' => [.7,-.6],'phase' => 2.7],
		]);
		$this->atmosphere = new Atmosphere;
		$this->camera = new Camera;
		$this->craft = new Watercraft;
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
		try
		{
			$wasPicking = $this->picking;
			foreach($input['values'] ?? [] as $name => $value) $this->setting((string)$name, $value);
			foreach($input['actions'] ?? [] as $action) $this->action($action);
			foreach($input['clicks'] ?? [] as $click) $this->pick((float)$click[0], (float)$click[1], $aspect);
			if($wasPicking) $this->advancePick($pickMs);
			if($this->draft !== null) { $this->draft->commit(); $this->dirty = true; }
		}
		catch(\Throwable $error)
		{
			$this->draft?->close(); $this->draft = null;
			$this->cancelPick();
			$this->message = $error->getMessage();
			$this->dirty = true;
		}
		$look = $input['look'] ?? [0,0];
		if($this->mode === 'fly' && (($look[0] ?? 0) != 0 || ($look[1] ?? 0) != 0))
		{
			$this->camera->look((float)$look[0] * .003, -(float)$look[1] * .003);
			$this->dirty = true;
		}
		if($this->mode !== 'fly') return;
		$forward = $this->held('KeyW') - $this->held('KeyS');
		$right = $this->held('KeyD') - $this->held('KeyA');
		$up = $this->held('KeyE') - $this->held('KeyQ');
		if($forward === 0 && $right === 0 && $up === 0) return;
		$speed = ($this->held('ShiftLeft') || $this->held('ShiftRight')) ? 300 : $this->settings['speed'];
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
		if($this->mode === 'craft')
		{
			$this->reserveCraft();
			$this->craft->step($seconds, $this->held('KeyW') - $this->held('KeyS'),
				$this->held('KeyD') - $this->held('KeyA'), $this->water, $this->landscape, $this->time);
			$s = $this->craft->state();
			$this->camera->position = [$s['x'] - sin($s['yaw']) * 9,$s['y'] + 4,$s['z'] - cos($s['yaw']) * 9];
			$this->camera->yaw = M_PI - $s['yaw'];
			$this->camera->pitch = -.27;
		}
		$this->time += $seconds;
		$this->water->update($this->time);
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
	 * Read one held movement control.
	 * @param string $key Browser KeyboardEvent code.
	 * @return int One when held, otherwise zero.
	 */
	private function held(string $key): int { return !empty($this->keys[$key]) ? 1 : 0; }

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
			'azimuth' => [0,360],'elevation' => [-12,88],'haze' => [0,5],'exposure' => [.1,4],
			'quality' => [.5,2],'speed' => [5,300]];
		if(isset($ranges[$name]))
		{
			if(!is_numeric($value) || !is_finite((float)$value)) throw new \InvalidArgumentException('Invalid control value');
			[$min,$max] = $ranges[$name];
			$value = max($min, min($max, (float)$value));
		}
		elseif($name === 'clock') $value = (bool)$value;
		elseif($name === 'tool' && !in_array($value, ['navigate','raise','lower','flatten','smooth','crater','material','road'], true)) return;
		elseif($name === 'material' && !in_array($value, ['grass','rock','sand','snow','road'], true)) return;
		$this->settings[$name] = $value;
		if($name === 'haze') $this->atmosphere->setHaze($value);
		if($name === 'exposure') $this->atmosphere->setExposure($value);
		if($name === 'azimuth' || $name === 'elevation') $this->sun();
		$this->dirty = $this->dirty || in_array($name,['quality','exposure','haze','azimuth','elevation','clock'],true);
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
			case 'pause': $this->paused = !$this->paused; break;
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
				$recipe['preset'] = (string)($action['preset'] ?? 'coast');
				$recipe['seed'] = (string)($action['seed'] ?? 'first-light');
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
			case 'craft': $this->boardCraft(); break;
			case 'fly': $this->mode = 'fly'; $this->releasePin(); break;
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
		if($tool === 'navigate' || $this->mode === 'craft') return;
		if($this->preparing) { $this->message = 'Preparing the previous terrain edit. Please wait.'; return; }
		$queuedTool = $this->pickIntent['settings']['tool'] ?? $this->pickQueue[0]['settings']['tool'] ?? '';
		if($this->picking && ($tool !== 'road' || $queuedTool !== 'road'))
		{ $this->message = 'Finding the ground for the previous click. Please wait.'; return; }
		if(count($this->pickQueue) + count($this->road) >= 64) return;
		$this->pickQueue[] = ['origin' => $this->camera->position, 'direction' => $this->camera->ray($x, $y, $aspect),
			'settings' => $this->settings];
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
		$settings = $this->pickIntent['settings'];
		$this->pickQuery = null; $this->pickIntent = [];
		$this->picking = $this->pickQueue !== [];
		$this->applyPick($hit, $settings);
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
	 * @return void Stages a complete operation against the unchanged source revision.
	 */
	private function applyPick(?array $hit, array $settings): void
	{
		$tool = $settings['tool'];
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
		$this->mode = 'fly'; $this->releasePin();
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
		$this->mode = 'craft'; $this->paused = false; $this->reserveCraft();
		$this->message = 'W/S throttle, A/D steer. Return to flight to edit the landscape.';
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
		$this->cancelPick();
		$this->draft?->close(); $this->draft = null;
		$this->releasePin();
		$this->landscape->close();
		$this->landscape = $landscape;
		$this->water->setLevel($landscape->recipe()['waterLevel']);
		$this->road = []; $this->generation++; $this->mode = 'fly';
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
		$this->preparing = false;
		$this->dirty = true;
	}

	/**
	 * Serialize terrain, controls and camera in a versioned standalone JSON file.
	 * @return string Complete portable JSON save document.
	 */
	public function save(): string
	{
		return json_encode(['schema' => 'phonics-landscape-sandbox','version' => 1,
			'landscape' => json_decode($this->landscape->save(), true, flags: JSON_THROW_ON_ERROR),
			'settings' => $this->settings,'camera' => ['position' => $this->camera->position,
				'yaw' => $this->camera->yaw,'pitch' => $this->camera->pitch]], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
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
		if(($data['schema'] ?? '') !== 'phonics-landscape-sandbox' || ($data['version'] ?? 0) !== 1)
		throw new \InvalidArgumentException('This is not a supported landscape sandbox file.');
		$candidate = Landscape::load(json_encode($data['landscape'] ?? null, JSON_THROW_ON_ERROR));
		$settings = $data['settings'] ?? [];
		if(!is_array($settings)) throw new \InvalidArgumentException('Invalid saved settings.');
		$appearance = new WaterAppearance($settings);
		$settings = array_diff_key($settings, WaterAppearance::DEFAULTS);
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
		$this->camera->position = array_map('floatval', $position);
		$this->camera->yaw = (float)$yaw; $this->camera->pitch = max(-1.52, min(1.52, (float)$pitch));
		$this->clampCamera();
		$this->message = 'Landscape loaded, including terrain edits and environment settings.';
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
			'preparing' => $this->preparing,'picking' => $this->picking,
			'pickProgress' => $this->pickQuery?->stats(),'lastPick' => $this->lastPick,'recipe' => $this->landscape->recipe(),'revision' => $this->landscape->revision(),
			'settings' => $this->settings,'roadPoints' => $this->road,'message' => $this->message,
			'camera' => ['position' => $this->camera->position,'yaw' => $this->camera->yaw,'pitch' => $this->camera->pitch,'fov' => $this->camera->fov],
			'craft' => $this->mode === 'craft' ? $this->craft->state() : null,'surface' => $this->landscape->stats()];
	}

	/**
	 * Release terrain sample ownership and pending UI data.
	 * @return void Retires the active surface and any unsubmitted candidate.
	 */
	public function close(): void
	{
		$this->cancelPick();
		$this->releasePin(); $this->landscape->close(); $this->draft?->close(); $this->draft = null;
		$this->keys = $this->road = [];
	}
}
