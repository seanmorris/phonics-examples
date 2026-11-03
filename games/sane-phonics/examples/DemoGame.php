<?php
declare(strict_types=1);

use Phonics\Engine;
use Phonics\Ecs\Registry;
use Phonics\Effects\ParticleBurst;
use Phonics\Effects\CameraShake;
use SanePhonics\Effects\Rumble;
use SanePhonics\Gameplay\BreakableBlock;
use SanePhonics\Gameplay\Combo;
use SanePhonics\Gameplay\LightDash;
use SanePhonics\Gameplay\GrappleGrip;
use SanePhonics\Gameplay\Rider;
use SanePhonics\Gameplay\PlayerCombat;
use SanePhonics\Gameplay\PlatformCamera;
use SanePhonics\Gameplay\RingCounter;
use SanePhonics\Gameplay\Shield;

use SanePhonics\Physics\Body;
use Phonics\Render\Camera;
use Phonics\Render\Sprite;
use Phonics\Ui\DomTextureModal;
use Phonics\Ui\PauseMenu;
use Phonics\Ui\TextMessages;
use Phonics\Ui\TextRenderer;

/** One engine session with replaceable level resources and persistent player/menu settings. */
final class DemoGame
{
	public ?DemoLevel $level = null;
	public ?DemoLevel $pending = null;
	public ?PauseMenu $menu = null;
	public ?DemoStartup $startup = null;
	public ?DemoDebugOverlay $debug = null;
	public ?DemoPlayerHud $hud = null;
	public ?DemoComboHud $comboHud = null;
	public ?DomTextureModal $phpInfo = null;
	public ?TextMessages $messages = null;
	public ?TextRenderer $textRenderer = null;
	public PlatformCamera $cameraBehavior;
	public array $renderStats = ['draws' => 0, 'instances' => 0, 'uploads' => 0];
	public array $settings = [];
	public float $levelStartedAt = 0;
	public string $lastSequence = '';
	public int $sequenceCount = 0;
	public string $scene = '';
	public string $error = '';
	public bool $motionBlur = true;
	public bool $rumble = true;
	public string $rumbleTestStatus = 'Not tested';
	private object $host;
	private object $canvas;
	private object $status;
	private int $frames = 0;
	private float $frameElapsed = 0;

	/**
	 * Attach persistent movement and browser diagnostics to the running session.
	 *
	 * @param Engine $engine Engine retained across every level selection.
	 * @param Camera $camera Shared viewport and user zoom setting.
	 * @param Body $body Persistent player body receiving each destination's spawn pose.
	 * @param RingCounter $counter Persistent player score and level ring count.
	 * @param Combo $combo Persistent player movement reward chain and recent result.
	 * @param int $entity Registry ID for the persistent player body and sprite.
	 * @return void Installs one movement system; assets load after selectScene is called.
	 */
	public function __construct(
		public Engine $engine
		, public Camera $camera
		, public Body $body
		, public RingCounter $counter
		, public Combo $combo
		, public int $entity
	){
		$this->host = vrzno_env('demoControls');
		$this->canvas = vrzno_env('canvas');
		$domTexture = vrzno_env('domTexture');

		if(is_object($domTexture))
		{
			$this->phpInfo = new DomTextureModal($domTexture);
		}

		$this->status = (new Vrzno)->document->querySelector('#status') ?? new stdClass;
		$this->cameraBehavior = new PlatformCamera;
		$this->cameraBehavior->setCameraLimits(maxY: 120);
		$engine->systems->add('movement', [$this, 'update']);
		(require __DIR__ . '/sequences.php')($engine->input->sequences);
	}

	/**
	 * Handle diagnostics before delegating the frame to the active startup or pause menu.
	 *
	 * @param Engine $engine Session whose SDL events have already been polled.
	 * @param float $elapsed Active browser frame seconds, unaffected by simulation pause.
	 * @return bool Whether the active menu captures gameplay input for this frame.
	 */
	public function input(Engine $engine, float $elapsed): bool
	{
		$this->frameElapsed = $elapsed;
		$this->messages?->update($elapsed);

		foreach($engine->input->sequences->drain() as $match)
		{
			$this->lastSequence = $match['name'];
			$this->sequenceCount++;

			if($this->debug && in_array($match['name'], ['konami', 'debug'], true))
			{
				$this->debug->enabled = true;
			}
		}

		$this->canvas->dataset->sequences = json_encode([
			'last' => $this->lastSequence
			, 'count' => $this->sequenceCount
			, 'konami' => $engine->input->sequences->progress('konami')
		]);

		$menu = $this->startup !== null && $this->startup->phase !== 'playing'
			? $this->startup->menu
			: $this->menu->menu;

		if($this->phpInfo?->isOpen())
		{
			return $this->phpInfo->update($engine, $elapsed, $menu);
		}

		$frame = $engine->input->uiFrame($menu->editor !== null, $menu->isOpen);
		$this->messages?->setFocused($frame['focused']);
		$this->debug?->update($elapsed, $frame);

		return $this->startup !== null
			? $this->startup->update($engine, $elapsed)
			: $this->menu->update($engine, $elapsed);
	}

	/**
	 * Start or replace a nonblocking level request without restarting PHP or simulation.
	 *
	 * @param string $scene Platformer, platforms, bridges, grapples, vehicles, light-dash, water, water-rail,
	 * water-skim-rail, water-loop, gravity, ceiling-curves, tornado, egg-walker, badniks,
	 * grinding, loop, breakables, infinite, isometric, or world identifier.
	 * @param bool $restart Whether to rebuild the currently selected level from its original assets.
	 * @return void Keeps the current level until ready; selecting it again cancels the request.
	 */
	public function selectScene(string $scene, bool $restart = false): void
	{
		if(!in_array(
			$scene
			, [
				'platformer'
				, 'water'
				, 'water-rail'
				, 'water-skim-rail'
				, 'water-loop'
				, 'gravity'
				, 'ceiling-curves'
				, 'platforms'
				, 'bridges'
				, 'light-dash'
				, 'grapples'
				, 'vehicles'
				, 'tornado'
				, 'egg-walker'
				, 'badniks'
				, 'grinding'
				, 'loop'
				, 'breakables'
				, 'infinite', 'isometric', 'world'
			]
			, true
		)){
			throw new InvalidArgumentException('Unknown demo level');
		}

		$this->releasePending();

		if($this->scene === $scene && !$restart)
		{
			$this->menu?->menu->close();

			return;
		}

		$scope = (vrzno_env('createAssetScope'))();
		$this->pending = new DemoLevel($scene, $scope);
	}

	/**
	 * Reload the active level with fresh rings, enemies, platforms, and other level-owned objects.
	 *
	 * @return void Stages the same scene while retaining PHP, player profile, score, and menu settings.
	 */
	public function restartLevel(): void
	{
		if($this->level !== null)
		{
			$this->selectScene($this->scene, true);
		}
	}

	/**
	 * Enable or disable camera motion blur without retaining movement from the old setting.
	 *
	 * @param bool $enabled Whether terrain should blur along camera travel.
	 * @return void Applies the setting immediately and resets the active camera baseline.
	 */
	public function setMotionBlur(bool $enabled): void
	{
		$this->motionBlur = $enabled;
		$this->level?->motionBlur?->reset();
	}

	/** Enable or silence MB2 haptic patterns without retaining pulses from the old preference. */
	public function setRumble(bool $enabled): void
	{
		$this->rumble = $enabled;

		if(!$enabled)
		{
			foreach($this->engine->entities->query(Rumble::class) as $id)
			{
				$this->engine->entities->get($id, Rumble::class)->reset();
			}

			$this->engine->input->gamepads->rumble(0, 0, 0);
		}
	}

	/** Send a conspicuous diagnostic pulse to the gamepad currently driving the menu. */
	public function testRumble(): void
	{
		$status = $this->engine->input->gamepads->rumbleStatus();

		if($status !== 'Ready')
		{
			$this->rumbleTestStatus = $status;

			return;
		}

		$this->rumbleTestStatus = $this->engine->input->gamepads->rumble(1000, 1, 1)
			? 'Pulse accepted'
			: 'SDL rejected the pulse';
	}

	/** Whether this browser session supplied the reusable HTML texture bridge. */
	public function canShowPhpInfo(): bool { return $this->phpInfo !== null; }

	/** Capture PHP's complete native diagnostic document for the in-canvas information panel. */
	public function showPhpInfo(): void
	{
		if($this->phpInfo === null)
		{
			return;
		}

		ob_start();
		phpinfo();
		$html = (string)ob_get_clean();
		$this->phpInfo->show('PHP info', $html);
	}

	/**
	 * Cancel staged resources while leaving the active level and session untouched.
	 *
	 * @return void Releases the pending level and clears the previous load error.
	 */
	public function cancelLoad(): void
	{
		$cancelled = $this->pending !== null;
		$this->releasePending();

		if($cancelled && $this->level === null && $this->startup === null)
		{
			$this->notifyReady('Level load cancelled. Run to retry.');
		}
	}

	/**
	 * Release a staged level when replacing it, handling a failure, or stopping the session.
	 *
	 * @return void Clears staged resources without settling an initial browser start request.
	 */
	private function releasePending(): void
	{
		$this->pending?->close($this->engine);
		$this->pending = null;
		$this->error = '';
	}

	/**
	 * Apply a movement profile to the existing actor at its current pose.
	 *
	 * @param string $profile Runner, glider, or flyer movement profile.
	 * @return void Updates jump tuning and sprite art, retiring incompatible ability state.
	 */
	public function selectProfile(string $profile): void
	{
		$defaults = new Body(profile: $profile);
		$this->level?->vehicles?->dismount($this->entity, 'transfer');
		$this->level?->grapples?->release($this->entity);
		$this->body->profile = $profile;
		$this->body->jumpSpeed = $defaults->jumpSpeed;
		$this->body->ability = '';
		$this->body->glideDirection = 0;
		$this->body->lightDashed = false;
		$this->engine->entities->get($this->entity, LightDash::class)->reset();
		$this->body->crouching = false;
		$this->body->crouchTime = 0;
		$this->body->wall = 0;
		$this->body->charge = $this->body->dropCharge = $this->body->dropTimer = 0;
		$this->body->flightRemaining = $defaults->flightRemaining;

		$this->engine->entities->get($this->entity, Sprite::class)->gid = [
			'runner' => 7
			, 'glider' => 8
			, 'flyer' => 9
		][$profile];

		$this->host->profile = $profile;
		$this->canvas->dataset->profile = $profile;
		$this->menu?->menu->close();
	}

	/**
	 * Reset transient player movement while retaining the body, profile, and tuning.
	 *
	 * @return void Places the actor at the demo spawn with no buffered actions or old collisions.
	 */
	private function resetPlayer(): void
	{
		$this->level?->motionBlur?->reset();
		$this->engine->entities->get($this->entity, Rumble::class)->reset();
		$this->level?->vehicles?->dismount($this->entity, 'reset');
		$this->engine->entities->get($this->entity, Rider::class)->cooldowns = [];
		$this->level?->grapples?->release($this->entity, inherit: false);
		$this->engine->entities->get($this->entity, GrappleGrip::class)->reset();
		$spawn = $this->level?->spawn ?? [80.0, 100.0];
		$defaults = new Body($spawn[0], $spawn[1], $this->body->profile);

		foreach([
			'x'
			, 'y'
			, 'vx'
			, 'vy'
			, 'groundSpeed'
			, 'angle'
			, 'grounded'
			, 'rolling'
			, 'jumping'
			, 'grinding'
			, 'crouching'
			, 'facing'
			, 'direction'
			, 'glideDirection'
			, 'wall'
			, 'collisionLayer'
			, 'gravityX'
			, 'gravityY'
			, 'ability'
			, 'lightDashed'
			, 'charge'
			, 'crouchTime'
			, 'dropCharge'
			, 'flightRemaining'
			, 'dropTimer', 'coyote', 'jumpBuffer'
		] as $property)
		{
			$this->body->$property = $defaults->$property;
		}

		$this->body->halfHeight = $this->body->standingHeight;
		$this->body->water = null;
		$this->body->underwater = $this->body->waterEntered = $this->body->waterExited = false;
		$this->body->skimming = false;
		$this->body->waterDepth = 0;
		$this->combo->reset();
		$this->engine->entities->get($this->entity, LightDash::class)->reset();
		$this->level?->vehicles?->sync($this->entity, $this->body);
		$this->level?->grapples?->sync($this->entity, $this->body);
		$this->level?->rings?->sync($this->entity, $this->body);
		$this->level?->badniks?->sync($this->entity, $this->body);
		$this->level?->miniBosses?->sync($this->entity, $this->body);
		$this->level?->bossRegions?->sync($this->body);
		$this->cameraBehavior->setMode('auto');
		$this->cameraBehavior->clearFocus();
		$this->cameraBehavior->clearWorldBounds();
		$this->cameraBehavior->sync($this->body);

		if($this->engine->entities->has($this->entity, PlayerCombat::class))
		{
			$this->engine->entities->get($this->entity, PlayerCombat::class)->mercy = 0;
		}
	}

	/**
	 * Name the player's mutually exclusive visible movement pose.
	 *
	 * @param Body $body Player body carrying crouch, charge, grind, roll, jump, and grounded state.
	 * @return string Lightdash, hanging, spindash, grinding, rolling, jumping, crouching, ground, or air pose.
	 */
	private function playerPose(Body $body): string
	{
		if($body->ability === 'riding')
		{
			return $this->level?->vehicles?->ridden($this->entity)?->pose ?? 'riding';
		}

		if(in_array($body->ability, ['lightdash', 'hanging'], true))
		{
			return $body->ability;
		}

		if($body->charge > 0)
		{
			return 'spindash';
		}

		if($body->rolling)
		{
			return 'rolling';
		}

		if($body->grinding)
		{
			return $body->crouching ? 'grinding-crouching' : 'grinding';
		}

		if($body->jumping)
		{
			return 'jumping';
		}

		if($body->crouching)
		{
			return 'crouching';
		}

		return $body->grounded ? 'ground' : 'air';
	}

	/**
	 * Resolve generated player art for a movement profile and visible pose.
	 *
	 * @param string $profile Runner, glider, or flyer artwork family.
	 * @param string $pose Pose returned by playerPose; plain air and ground share standing art.
	 * @return int Global demo tileset ID, including animated rolling art for a spindash.
	 */
	private function playerGid(string $profile, string $pose): int
	{
		$art = in_array($pose, ['rolling', 'jumping', 'spindash'], true)
			? 'rolling'
			: (in_array($pose, ['crouching', 'grinding-crouching', 'riding-crouching'], true)
				? 'crouching'
				: 'standing'
			);

		return [
			'runner' => ['standing' => 7, 'crouching' => 10, 'rolling' => 13]
			, 'glider' => ['standing' => 8, 'crouching' => 11, 'rolling' => 15]
			, 'flyer' => ['standing' => 9, 'crouching' => 12, 'rolling' => 17]
		][$profile][$art];
	}

	/**
	 * Calculate the localized background displacement used by charged and braking poses.
	 *
	 * @param Body $body Player body carrying charge, direction, surface, and tangent speed state.
	 * @return array{strength:float,x:float,y:float} Signed strength and field center matching the
	 * reference effect's distinct charge and braking offsets.
	 */
	private function playerBackdropTwist(Body $body): array
	{
		$strength = 0.0;
		$offset = 0.0;

		if($body->charge > 0)
		{
			$strength = 120 * $body->charge * $body->direction;
			$offset = -13 * $body->direction;
		}

		if($strength == 0 && $body->grounded && !$body->rolling && !$body->grinding
			&& $body->direction * $body->groundSpeed < 0
		){
			$strength = max(-256, min(256, -$body->groundSpeed * 22 / 60));
			$offset = -16 * $body->direction;
		}

		return [
			'strength' => $strength
			, 'x' => $body->supportX() + $offset
			, 'y' => $body->supportY()
		];
	}

	/**
	 * Poll staging at a frame boundary and commit only a fully prepared destination.
	 *
	 * @return void Keeps failures local to the request, with the old scene still usable.
	 */
	private function load(): void
	{
		if($this->pending === null)
		{
			return;
		}

		try
		{
			if(!$this->pending->prepare($this->engine, $this->camera))
			{
				return;
			}
		}
		catch(Throwable $error)
		{
			// A context loss is handled by Engine; preserve the destination for a fresh prepare.
			$id = $this->pending->id;
			$this->releasePending();

			if($this->engine->device->isLost())
			{
				$this->selectScene($id);
				throw $error;
			}

			$this->error = $error->getMessage();

			if($this->level === null && $this->startup === null)
			{
				$this->notifyReady($this->error);
			}

			return;
		}

		$previous = $this->level;
		$this->level = $this->pending;
		$this->pending = null;
		$this->scene = $this->level->id;
		$previous?->close($this->engine);
		$this->counter->rings = 0;
		$this->levelStartedAt = $this->engine->time;

		$this->cameraBehavior->setCameraLimits(
			maxY: in_array($this->scene, ['ceiling-curves', 'tornado'], true) ? null : 120
		);

		$this->resetPlayer();
		[$this->camera->x, $this->camera->y] = $this->level->origin();
		$this->engine->input->reset();
		$this->engine->controls->reset();
		$this->host->scene = $this->scene;
		$this->canvas->dataset->scene = $this->scene;
		$this->menu?->menu->close();

		if($previous === null)
		{
			$this->notifyReady();
		}

		foreach(['world', 'breakables', 'badniks', 'boss', 'water', 'bossLock'] as $key)
		{
			unset($this->canvas->dataset->$key);
		}
	}

	/**
	 * Settle the browser's initial Run request after a scene loads or fails.
	 *
	 * @param string|null $error Failure message, or null after successful preparation.
	 * @return void Leaves later level transitions entirely inside the running PHP session.
	 */
	private function notifyReady(?string $error = null): void
	{
		$ready = vrzno_env('sceneReady');

		if($ready)
		{
			$ready($error);
		}
	}

	/**
	 * Advance the active level using the persistent input and player objects.
	 *
	 * @param Registry $entities Session registry supplied by the scheduler.
	 * @param float $dt Fixed simulation delta in seconds.
	 * @return void Updates player physics or pans the camera in the two inspection demos.
	 */
	public function update(Registry $entities, float $dt): void
	{
		if($this->level === null)
		{
			return;
		}

		$controls = $this->engine->controls;

		if($controls->pressed('reset') && $controls->down('action'))
		{
			$this->restartLevel();

			return;
		}

		if($this->scene === 'world' || $this->scene === 'isometric')
		{
			$speed = $this->scene === 'world' ? 900 : 300;
			$this->camera->x += $controls->axis('left', 'right') * $speed * $dt;
			$this->camera->y += $controls->axis('up', 'down') * $speed * $dt;

			if($controls->pressed('reset'))
			{
				[$this->camera->x, $this->camera->y] = $this->level->origin();
			}

			if($controls->pressed('action'))
			{
				foreach(array_keys($this->level->stream->errors) as $path)
				{
					$this->level->stream->retry($path);
				}
			}

			return;
		}

		if($this->level->escaped($this->body) || $controls->pressed('reset'))
		{
			$this->resetPlayer();
		}

		$vehicleInput = $this->level->vehicles?->control($this->entity, $controls, $dt) ?? false;
		$gameplayControls = $controls->gravityRelative($this->body->gravityX, $this->body->gravityY);
		$released = !$vehicleInput && ($this->level->grapples?->control($this->entity, $gameplayControls, $dt) ?? false
		);

		$hanging = $this->body->ability === 'hanging';
		$lightDashing = !$vehicleInput && !$released && !$hanging
			&& ($this->level->lightDashes?->control($this->entity, $gameplayControls, $dt) ?? false);

		$jumpConsumed = $vehicleInput || $released || $hanging || $lightDashing
			|| ($this->level->shields?->control($this->entity, $gameplayControls, $dt) ?? false);

		$wasGrounded = $this->body->grounded;
		$landingSpeed = abs($this->body->gravityVelocity());
		$this->level->movement->step($this->body, $gameplayControls, $dt, $jumpConsumed);

		if(!$vehicleInput && !$wasGrounded && $this->body->grounded)
		{
			$rumble = $this->engine->entities->get($this->entity, Rumble::class);

			// Platformer lets an impact already produced by this terrain sweep take precedence.
			if(!$rumble->hasImmediatePulse()) $rumble->landing($landingSpeed);
		}

		$this->level->vehicles?->update($entities, $dt);
		$bossRegion = $this->level->bossRegions?->update($this->body);

		if($bossRegion !== null)
		{
			$this->level->miniBosses?->wakeSource($bossRegion->bossSourceId);
		}

		$score = $this->body->ability === 'riding' ? null : $this->combo->update($this->body, $dt);

		if($score !== null)
		{
			$this->counter->score += $score;
		}

		if($bossRegion !== null)
		{
			$this->cameraBehavior->setMode('boss');
			$this->cameraBehavior->bossAnchorY = $bossRegion->cameraAnchorY;

			$this->cameraBehavior->setWorldBounds($bossRegion->x, $bossRegion->x + $bossRegion->width);
		}
		else
		{
			$this->cameraBehavior->setMode($this->level->vehicles?->ridden($this->entity)?->cameraMode ?? 'auto');
			$this->cameraBehavior->clearWorldBounds();
		}

		$look = 0;

		if($this->body->grounded && !$this->body->rolling && !$this->body->grinding
			&& abs($this->body->groundSpeed) < 1 && $this->body->charge <= 0
		){
			$look = $controls->axis('up', 'down');
		}

		$this->cameraBehavior->update($this->camera, $this->body, $dt, $look);
		$this->dispatchRumble($entities, $dt);
	}

	/** Advance every ECS haptic queue and route ready pulses through the active gamepad adapter. */
	private function dispatchRumble(Registry $entities, float $dt): void
	{
		$pulse = null;

		foreach($entities->query(Rumble::class) as $id)
		{
			foreach($entities->get($id, Rumble::class)->update($dt) as $ready)
			{
				// MB2's Controller retains only its final willRumble request per input frame.
				$pulse = $ready;
			}
		}

		if($this->rumble && $pulse !== null)
		{
			$this->engine->input->gamepads->rumble($pulse['duration'], $pulse['strong'], $pulse['weak']);
		}
	}

	/**
	 * Draw the active scene and persistent pause menu, polling staged assets once per frame.
	 *
	 * @return void Presents graphics and publishes current diagnostics without replacing the loop.
	 */
	public function render(): void
	{
		$this->load();
		$this->dispatchRumble($this->engine->entities, 0);
		$this->engine->device->begin();

		if($this->startup?->render())
		{
			$this->hud?->render($this, false);
			$this->comboHud?->render($this, false);
			$this->debug?->render($this, false);

			$this->textRenderer?->renderMessages(
				$this->messages ?? throw new LogicException('Text messages were not initialized')
				, $this->engine->device->width
				, $this->engine->device->height
			);

			$this->canvas->dataset->hud = json_encode($this->hud?->snapshot);
			$this->canvas->dataset->combo = json_encode($this->comboHud?->snapshot);
			$this->canvas->dataset->debug = json_encode($this->debug?->snapshot);
			$this->canvas->dataset->messages = json_encode($this->messages?->snapshot());
			$this->canvas->dataset->text = json_encode($this->textRenderer?->snapshot);
			$this->phpInfo?->render($this->engine->device->width, $this->engine->device->height);
			$this->canvas->dataset->phpInfo = json_encode($this->phpInfo?->state());
			$this->engine->device->present();

			$this->canvas->dataset->menu = demoMenuState($this->menu, $this->engine, $this->startup->menu);

			return;
		}

		if($this->level !== null)
		{
			$this->drawLevel();
			$this->canvas->dataset->frames = (string)++$this->frames;
		}

		$playerVisible = $this->level !== null && !in_array($this->scene, ['world', 'isometric'], true);
		$this->hud?->render($this, $playerVisible);
		$this->comboHud?->render($this, $playerVisible);
		$this->debug?->render($this, $this->level !== null);

		$this->textRenderer?->renderMessages(
			$this->messages ?? throw new LogicException('Text messages were not initialized')
			, $this->engine->device->width
			, $this->engine->device->height
		);

		$this->canvas->dataset->hud = json_encode($this->hud?->snapshot);
		$this->canvas->dataset->combo = json_encode($this->comboHud?->snapshot);
		$this->canvas->dataset->debug = json_encode($this->debug?->snapshot);
		$this->canvas->dataset->messages = json_encode($this->messages?->snapshot());
		$this->canvas->dataset->text = json_encode($this->textRenderer?->snapshot);
		$this->menu->render();
		$this->phpInfo?->render($this->engine->device->width, $this->engine->device->height);
		$this->canvas->dataset->phpInfo = json_encode($this->phpInfo?->state());
		$this->engine->device->present();
		$this->canvas->dataset->menu = demoMenuState($this->menu, $this->engine);
		$this->canvas->dataset->loading = $this->pending?->id ?? '';
		$this->canvas->dataset->levelError = $this->error;

		if($this->error !== '')
		{
			$this->status->textContent = 'Level load failed. Select the level to retry. ' . $this->error;
		}
		elseif($this->pending !== null)
		{
			$this->status->textContent = 'Loading ' . $this->pending->id . '…';
		}
		elseif($this->menu->menu->isOpen)
		{
			$this->status->textContent = 'Paused. Press Start / Options or P to resume.';
		}
	}

	/**
	 * Render either the streamed world or a map with its player and optional block entities.
	 *
	 * @return void Updates scene diagnostics and status text after drawing the current level.
	 */
	private function drawLevel(): void
	{
		$level = $this->level;
		$camera = $this->camera;
		$time = $this->engine->time * 1000;

		$motionBlur = $level->motionBlur->sample(
			$camera
			, $this->frameElapsed
			, $this->motionBlur && !$this->engine->userPaused && !$this->menu->menu->isOpen
		);

		$vehicle = $level->vehicles?->ridden($this->entity);

		if($vehicle !== null && $this->engine->entities->has($vehicle->entity, CameraShake::class))
		{
			$camera = $this->engine->entities->get($vehicle->entity, CameraShake::class)->view($camera);
		}

		$this->canvas->dataset->motionBlur = json_encode($motionBlur);
		$level->waterRenderer?->begin();

		if($motionBlur['active'])
		{
			$level->motionBlurRenderer->begin();
		}

		$this->canvas->dataset->platforms = json_encode($level->platforms?->snapshot() ?? []);
		$this->canvas->dataset->bridges = json_encode($level->bridges?->snapshot() ?? []);
		$this->canvas->dataset->vehicles = json_encode($level->vehicles?->snapshot() ?? []);
		$this->canvas->dataset->grapples = json_encode($level->grapples?->snapshot() ?? []);
		$this->canvas->dataset->gravity = json_encode($level->gravityFeatures?->snapshot() ?? []);

		if($this->scene === 'world')
		{
			$level->stream->update(
				$camera->x
				, $camera->y
				, $camera->width / $camera->zoom
				, $camera->height / $camera->zoom
			);

			$level->syncFactories($this->engine);
			$stats = $this->renderStats = $level->renderer->render($camera, $time, 0);

			if($motionBlur['active'])
			{
				$level->motionBlurRenderer->compose($motionBlur['sigma'], $motionBlur['x'], $motionBlur['y']);

				$stats['draws'] += $level->motionBlurRenderer->draws;
				$this->renderStats = $stats;
			}

			$loaded = array_map('basename', array_keys($level->stream->loaded()));
			$pending = array_map('basename', $level->stream->pending());

			$this->canvas->dataset->world = json_encode([
				'x' => $camera->x
				, 'y' => $camera->y
				, 'loaded' => $loaded
				, 'pending' => $pending
				, 'errors' => $level->stream->errors
				, 'draws' => $stats['draws']
			]);

			$this->status->textContent = sprintf(
				'Camera %.0f, %.0f · %d maps ready · %d pending · %d draws · %s'
				, $camera->x
				, $camera->y
				, count($loaded)
				, count($pending)
				, $stats['draws']
				, implode(', ', $loaded)
			);

			if($level->stream->errors)
			{
				$this->status->textContent = 'Map download failed. Press X or B / Circle to retry. '
					. implode('; ', $level->stream->errors);
			}

			return;
		}

		$body = $this->body;
		$backdropTwist = $this->playerBackdropTwist($body);
		$twistingBackdrop = $level->backdropTwist !== null && $backdropTwist['strength'] != 0;

		if($level->backdropTwist !== null)
		{
			$level->backdropTwist->draws = 0;
		}

		if($twistingBackdrop)
		{
			$level->backdropTwist->begin();
		}

		$level->renderer->render($camera, $time);

		if($twistingBackdrop)
		{
			$level->backdropTwist->compose(
				$camera
				, $backdropTwist['x']
				, $backdropTwist['y']
				, $backdropTwist['strength']
			);
		}

		if($motionBlur['active'])
		{
			$level->motionBlurRenderer->compose($motionBlur['sigma'], $motionBlur['x'], $motionBlur['y']);
		}

		$pose = $this->playerPose($body);
		$playerOpacity = 1.0;

		if($this->scene !== 'isometric')
		{
			$sprite = $this->engine->entities->get($this->entity, Sprite::class);
			$combat = $this->engine->entities->get($this->entity, PlayerCombat::class);
			$gid = $this->playerGid($body->profile, $pose);

			if($sprite->gid !== $gid)
			{
				$sprite->gid = $gid;
				$sprite->time = -$time;
			}

			$sprite->x = $body->supportX();
			$sprite->y = $body->supportY();
			$sprite->rotation = $body->angle;
			$sprite->flipX = $body->facing < 0;
			$sprite->scaleY = 1;
			$sprite->originY = 30 / 32;

			if($pose === 'handstand')
			{
				$sprite->rotation += M_PI;
				$sprite->originY = 2 / 32;
			}
			elseif($pose === 'leanback')
			{
				$sprite->rotation -= .4 * $body->facing;
			}

			$playerOpacity = $sprite->opacity = $combat->flickerOpacity();
			$level->sprites->renderEntities($this->engine->entities, $camera, $time);
		}

		$this->textRenderer?->renderActors($this->engine->entities, $camera);

		$level->waterRenderer?->compose($level->waters, $camera, $time);

		$this->canvas->dataset->body = json_encode([
			'x' => $body->x
			, 'y' => $body->y
			, 'supportX' => $body->supportX()
			, 'supportY' => $body->supportY()
			, 'grounded' => $body->grounded
			, 'rolling' => $body->rolling
			, 'jumping' => $body->jumping
			, 'grinding' => $body->grinding
			, 'crouching' => $body->crouching
			, 'crouchTime' => $body->crouchTime
			, 'charge' => $body->charge
			, 'dropCharge' => $body->dropCharge
			, 'backdropTwist' => $backdropTwist['strength']
			, 'backdropTwistX' => $backdropTwist['x']
			, 'backdropTwistY' => $backdropTwist['y']
			, 'facing' => $body->facing
			, 'direction' => $body->direction
			, 'pose' => $pose
			, 'ability' => $body->ability
			, 'vehicle' => $this->engine->entities->get($this->entity, Rider::class)->vehicle
			, 'grapple' => $this->engine->entities->get($this->entity, GrappleGrip::class)->point
			, 'lightDashed' => $body->lightDashed
			, 'lightDashTarget' => $this->engine->entities->get($this->entity, LightDash::class)->target
			, 'speed' => $body->groundSpeed
			, 'angle' => $body->angle
			, 'collisionLayer' => $body->collisionLayer
			, 'gravityX' => $body->gravityX
			, 'gravityY' => $body->gravityY
			, 'mercy' => $this->engine->entities->get($this->entity, PlayerCombat::class)->mercy
			, 'opacity' => $playerOpacity
			, 'shield' => $this->engine->entities->has($this->entity, Shield::class)
				? $this->engine->entities->get($this->entity, Shield::class)->active
				: ''
			, 'underwater' => $body->underwater
			, 'waterDepth' => $body->waterDepth
			, 'waterEntered' => $body->waterEntered
			, 'waterExited' => $body->waterExited
			, 'skimming' => $body->skimming
		]);

		$this->canvas->dataset->bossLock = json_encode(
			$level->bossRegions?->snapshot($body) ?? [
				'active' => false
				, 'sourceId' => null
				, 'bossSourceId' => null
				, 'x' => null
				, 'y' => null
				, 'width' => null
				, 'height' => null
			]
		);

		$stats = $level->renderer->stats;
		$this->renderStats = $stats;

		if($this->scene !== 'isometric')
		{
			$this->renderStats['draws'] += $level->sprites->draws;
			$this->renderStats['instances'] += $level->sprites->instances;
		}

		$this->renderStats['draws'] += ($this->textRenderer?->snapshot['worldGlyphs'] ?? 0) > 0 ? 1 : 0;
		$this->renderStats['instances'] += $this->textRenderer?->snapshot['worldGlyphs'] ?? 0;

		$this->renderStats['draws'] += $level->waterRenderer?->draws ?? 0;
		$this->renderStats['draws'] += $level->backdropTwist?->draws ?? 0;
		$this->renderStats['draws'] += $motionBlur['active'] ? $level->motionBlurRenderer->draws : 0;

		$this->status->textContent = sprintf(
			'%s · %.0f px/s · %d draws · %d visible instances · %d tile uploads · %d collision casts'
			, $body->ability ?: $pose
			, $body->groundSpeed
			, $this->renderStats['draws']
			, $this->renderStats['instances']
			, $stats['uploads']
			, $level->terrain->raycasts
		);

		if(in_array(
			$this->scene
			, ['water-rail', 'water-skim-rail', 'water-loop', 'grinding', 'loop', 'vehicles', 'tornado', 'egg-walker']
			, true
		)){
			$this->status->textContent .= ' · collision layer ' . $body->collisionLayer;
		}

		$vehicle = $level->vehicles?->ridden($this->entity);

		if($vehicle !== null && $this->engine->entities->has($vehicle->entity, \SanePhonics\Gameplay\Tornado::class))
		{
			$tornado = $this->engine->entities->get($vehicle->entity, \SanePhonics\Gameplay\Tornado::class);

			$this->status->textContent .= sprintf(
				' · fuel %d%% · gear %s · %s'
				, (int)round($tornado->fuel)
				, $tornado->landingGear ? 'down' : 'up'
				, $tornado->crashed ? 'crash recovery' : ($tornado->thrusting ? 'rocket thrust' : 'cruise')
			);
		}

		if($level->waters?->regions)
		{
			$this->canvas->dataset->water = json_encode([
				'underwater' => $body->underwater
				, 'depth' => $body->waterDepth
				, 'skimming' => $body->skimming
				, 'draws' => $level->waterRenderer?->draws ?? 0
				, 'features' => $level->waterFeatures?->snapshot()
				, 'particles' => $level->waterParticles?->snapshot()
			]);

			$this->status->textContent .= $body->underwater
				? sprintf(' · underwater %.0f%%', $body->waterDepth * 100)
				: ($body->skimming
					? ($body->grinding ? ' · skim-grinding' : ' · skimming')
					: ' · approach the water at speed'
				);
		}

		if($this->scene === 'breakables')
		{
			$intact = count($this->engine->entities->query(BreakableBlock::class));
			$pieces = 0;

			foreach($this->engine->entities->query(ParticleBurst::class) as $id)
			{
				$pieces += count($this->engine->entities->get($id, ParticleBurst::class)->sprites->sprites);
			}

			$this->canvas->dataset->breakables = json_encode(compact('intact', 'pieces'));
			$this->status->textContent .= " · $intact intact blocks · $pieces shards · Roll into blocks";
		}

		if($this->scene === 'badniks')
		{
			$counts = $level->badniks->counts();
			$boss = $level->miniBosses->snapshot();
			$counts['monitors'] = $level->monitors->counts();
			$counts['shields'] = $level->shields->counts();
			$combat = $this->engine->entities->get($this->entity, PlayerCombat::class);
			$counts['hits'] = $combat->hits;
			$counts['defeats'] = $combat->defeats;
			$counts['scatteredRings'] = $level->rings->scatteredCount();
			$this->canvas->dataset->badniks = json_encode($counts);
			$this->canvas->dataset->boss = json_encode($boss);

			$this->status->textContent .= sprintf(
				' · %d badniks · %d shots · %d monitors · %d shields · %d defeated'
				, $counts['badniks']
				, $counts['projectiles']
				, $counts['monitors']['intact']
				, $counts['shields']['active']
				, $counts['defeats']
			);

			if($level->bossRegions?->active($body))
			{
				$this->status->textContent .= sprintf(
					' · boss %d/%d HP · %s · arena locked'
					, $boss['hitPoints']
					, $boss['maxHitPoints']
					, $boss['phase']
				);
			}
			elseif($boss['present'])
			{
				$this->status->textContent .= sprintf(
					' · boss %d/%d HP · %s'
					, $boss['hitPoints']
					, $boss['maxHitPoints']
					, $boss['phase']
				);
			}
		}
	}

	/**
	 * Restore scene and UI graphics after browser context recovery.
	 *
	 * @return void Keeps the active level, clock, input bindings, and menu settings intact.
	 */
	public function restore(): void
	{
		$this->level?->restore();
		$this->menu?->restore();
		$this->hud?->restore();
		$this->comboHud?->restore();
		$this->debug?->restore();
		$this->startup?->restore();
		$this->textRenderer?->restore();
	}

	/**
	 * Release active and staged resources when the user stops the actual game session.
	 *
	 * @return void Detaches the menu and retires scene resources before Engine closes its device.
	 */
	public function close(): void
	{
		$this->startup?->close();
		$this->hud?->close();
		$this->comboHud?->close();
		$this->debug?->close();
		$this->menu?->close();
		$this->phpInfo?->close();
		$this->textRenderer?->close();
		$this->releasePending();
		$this->level?->close($this->engine);
		$this->level = null;
		unset(
			$this->canvas->dataset->hud
			, $this->canvas->dataset->combo
			, $this->canvas->dataset->debug
			, $this->canvas->dataset->sequences
			, $this->canvas->dataset->badniks
			, $this->canvas->dataset->boss
			, $this->canvas->dataset->bossLock
			, $this->canvas->dataset->water
			, $this->canvas->dataset->vehicles
			, $this->canvas->dataset->grapples
			, $this->canvas->dataset->platforms
			, $this->canvas->dataset->bridges
			, $this->canvas->dataset->motionBlur
			, $this->canvas->dataset->phpInfo
			, $this->canvas->dataset->messages
			, $this->canvas->dataset->text
		);
	}

	/** Return the persistent MB2-style text channel for gameplay scripts and menu callbacks. */
	public function textMessages(): TextMessages
	{
		return $this->messages ?? throw new LogicException('Text messages were not initialized');
	}

	/** Briefly demonstrate each portable text capability without inventing a replay subsystem. */
	public function showTextDemo(): void
	{
		$messages = $this->textMessages();

		$messages->showDialog(
			['World labels, dialog, and HUD text use the same bitmap font.', '⓿ continues   ❶ closes the menu']
			, 'TEXT SYSTEM'
			, 'left'
		);

		$messages->showCenter('TEXT SYSTEM READY', [1, .9, .3, 1], 2.5);
		$messages->showFocus('Focus and controller prompts are available.', 2.5);
		$messages->showDemo('play', 2.5);
	}
}
