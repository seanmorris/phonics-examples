<?php
declare(strict_types=1);
namespace DoomPhonics\Game;
use DoomPhonics\Input\DoomActions;

use DoomPhonics\Component\ActorState;
use DoomPhonics\Component\HitPoints;
use DoomPhonics\Component\NetworkPlayer;
use DoomPhonics\Component\PlayerStart;
use DoomPhonics\Component\StatusFace;
use DoomPhonics\Component\ViewInput;
use DoomPhonics\Component\ViewLighting;
use DoomPhonics\Component\WeaponOverlay;
use Phonics\Ecs\Query;
use Phonics\Engine;
use Phonics\Input\Source;
use Phonics\Package\Context;
use Phonics\Render\FramePipeline;
use Phonics\World\ScopeLease;
use Phonics\Network\MatchContext;
use DoomPhonics\Audio\SdlSoundEmitter;
use DoomPhonics\Audio\SoundEmitter;
use DoomPhonics\Audio\MusicCue;
use DoomPhonics\Audio\SoundCue;
use DoomPhonics\Audio\SoundEvent;
use DoomPhonics\Audio\SoundSource;
use DoomPhonics\Adapter\WadLevel;
use DoomPhonics\System\CheatSystem;
use DoomPhonics\Render\ScreenWipe;

/** Owns one active map lease and preserves single-player state across Episode 1 transitions. */
final class EpisodeGame
{
	private ?ScopeLease $lease = null;
	private ?PlayerState $carry = null;
	private int $carryHealth = 100;
	private bool $switching = false;
	private SoundEmitter $sound;
	private CheatSequence $cheatSequence;
	private CheatSystem $cheats;
	private bool $applyingInitialCheats = false;
	private bool $pauseRequested = false;
	private DoomInitialization $initialization;
	private ?ScreenWipe $wipe;
	private ?DoomMatch $match = null;
	private ?ViewInput $viewOwner = null;
	private ?string $networkRecoveryId = null;
	private ?LevelIntermission $intermission = null;
	private float $levelStartedAt = 0;
	private int $levelBaseKills = 0, $levelBaseItems = 0, $levelBaseSecrets = 0;
	private int $levelTotalKills = 0, $levelTotalItems = 0, $levelTotalSecrets = 0;
	/** @var array<string,mixed> Last successfully bound source resources. */
	private array $boundWorld;
	public GameSettings $settings;
	public string $map = '';
	public bool $complete = false;
	public float $updateMs = 0;
	public float $renderMs = 0;

	/** Keep network recovery alive while fixed gameplay and input are suspended. */
	public function recoveringNetwork(): bool
	{
		if(!$this->match?->replication || !function_exists('vrzno_env')) return false;
		$read = vrzno_env('phonicsNetworkRecovery');
		if(!$read || !($json = (string)$read()))
		{
			$this->networkRecoveryId = null;
			return false;
		}
		$state = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
		if($this->networkRecoveryId !== (string)$state['id'])
		{
			$this->networkRecoveryId = (string)$state['id'];
			$this->engine->input->reset();
		}
		$this->engine->controls->reset();
		if($this->match->replication->recover((string)$state['id'], ($state['state'] ?? '') === 'resynchronizing'))
		{
			$done = vrzno_env('phonicsNetworkRecovered');
			if($done) $done((string)$state['id']);
			$this->engine->input->reset();
			$this->viewOwner?->release();
		}
		return true;
	}

	public function __construct(
		public Context $phonics
		, public Engine $engine
		, public FramePipeline $pipeline
		, public string $source = 'episode'
		, ?GameSettings $settings = null
		, ?ScreenWipe $wipe = null
		, ?DoomInitialization $initialization = null
	){
		$this->boundWorld = $phonics->world;
		$this->settings = $settings ?? new GameSettings;
		$this->wipe = $wipe;
		$this->initialization = $initialization ?? new DoomInitialization;
		$phonics->runtime->pipeline = $pipeline;
		$phonics->runtime->service('controls', $engine->controls);
		$phonics->runtime->service('doom-settings', $this->settings);
		$this->sound = new SdlSoundEmitter;
		$this->cheatSequence = new CheatSequence;
		$this->cheats = new CheatSystem;
		$phonics->runtime->service('doom-cheats', $this->cheats);
		$phonics->runtime->service('doom-sound', $this->sound);

		if($wipe) $phonics->runtime->service('doom-wipe', $wipe);
	}

	public function enter(string $map, bool $preserve = true, bool $startMusic = true): void
	{
		if($this->switching) return;

		$this->switching = true;

		try
		{
			if($this->map !== '') $this->beginTransition();

			$this->intermission = null;

			if($preserve) $this->rememberPlayer();
			else
			{
				$this->carry = null;
				$this->carryHealth = 100;
			}

			$this->lease?->release();
			$this->lease = null;
			$this->map = strtoupper($map);
			$this->complete = false;
			$this->lease = $this->phonics->runtime->activate($this->source, $this->map);

			if($this->player() === null)
			throw new \UnexpectedValueException("Map {$this->map} has no playable player start or gameplay binding");

			$content = $this->lease->context->content();

			if($content instanceof WadLevel)
			$this->cheats->configure($content->weapons, $content->actors, $content->language);

			$this->restorePlayer();

			if(($entity = $this->player()) !== null)
			$this->cheats->applyActive($this->phonics->entities, $entity);

			$this->beginLevelStatistics();

			$this->cheatSequence->reset();
			$this->engine->input->reset();
			$this->engine->controls->reset();

			if($startMusic) $this->playLevelMusic();
		}
		finally
		{
			$this->switching = false;
		}
	}

	/** Start the configured campaign without carrying inventory or health from the hidden map. */
	public function newGame(string $startMap, bool $startMusic = true): void
	{
		$this->enter($startMap, false, $startMusic);
		$this->applyInitialCheats();
	}

	/** Recreate the current map and its scoped systems with the current settings. */
	public function restart(): void
	{
		if($this->map !== '') $this->newGame($this->map);
	}

	/** Apply each configured classic sequence independently after the player exists. */
	private function applyInitialCheats(): void
	{
		$this->applyingInitialCheats = true;

		try
		{
			foreach($this->initialization->cheats as $cheat)
			{
				$this->cheatSequence->reset();
				$this->typeCheats($cheat);
			}
		}
		finally
		{
			$this->applyingInitialCheats = false;
			$this->cheatSequence->reset();
		}
	}

	/** Bind the verified peer-selected world and stage its start map behind the open menu. */
	public function loadNetworkWorld(): bool
	{
		if(!function_exists('vrzno_env')) return false;

		$read = vrzno_env('phonicsNetworkWorld');
		$json = $read ? (string)$read() : '';

		if($json === '') return false;

		$world = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
		$network = MatchContext::browser();

		if(!$network) throw new \UnexpectedValueException('Negotiated world has no match assignment');

		$this->match = new DoomMatch($network);
		$this->phonics->runtime->service('doom-match', $this->match);
		$this->settings->chooseMode($this->match->mode->value());
		$skill = (int)($network->settings()['skill'] ?? $this->settings->skill()->index());
		$this->settings->chooseSkill(SkillLevel::fromIndex($skill));
		$this->bindSelectedWorld($world, 'Negotiated world', false);
		$this->playTitleMusic();

		return true;
	}

	/** Bind a locally selected WAD stack and launch its selected map. */
	public function loadContentWorld(): bool
	{
		if(!function_exists('vrzno_env')) return false;

		$read = vrzno_env('phonicsContentWorld');
		$json = $read ? (string)$read() : '';

		if($json === '') return false;

		$world = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
		$skill = SkillLevel::fromIndex((int)($world['settings']['skill'] ?? $this->settings->skill()->index()));
		$previous = $this->boundWorld;
		$previous['start'] = ['source' => $this->source, 'scope' => $this->map];
		$previousSkill = $this->settings->skill();
		$previousMode = $this->settings->mode();
		$previousMatch = $this->match;

		try
		{
			$this->settings->chooseMode((string)($world['settings']['mode'] ?? 'singleplayer'));
			$this->match = null;
			$this->phonics->runtime->service('doom-match', null);
			$this->settings->chooseSkill($skill);
			$this->bindSelectedWorld($world, 'Selected content world');
		}
		catch(\Throwable $error)
		{
			// Recreate the previous scope so its renderer and menu survive a failed selection.
			$this->match = $previousMatch;
			$this->phonics->runtime->service('doom-match', $previousMatch);
			$this->settings->chooseSkill($previousSkill);
			$this->settings->chooseMode($previousMode);
			$this->bindSelectedWorld($previous, 'Previous content world', false);

			throw $error;
		}

		return true;
	}

	/** @param array<string,mixed> $world */
	private function bindSelectedWorld(array $world, string $label, bool $startMusic = true): void
	{
		$start = $world['start'] ?? null;

		if(!is_array($start) || !is_string($start['source'] ?? null) || !is_string($start['scope'] ?? null))
		throw new \UnexpectedValueException("$label has no valid start scope");

		$this->beginTransition();
		$this->lease?->release();
		$this->lease = null;
		$this->map = '';
		$this->phonics->runtime->bindWorld($world);
		$this->source = $start['source'];
		$this->newGame($start['scope'], $startMusic);
		$this->boundWorld = $world;
	}

	/** Freeze the active map beneath the title screen and clear one-shot input. */
	public function returnToTitle(): void
	{
		$this->beginTransition();
		$this->intermission = null;

		if($this->match !== null)
		{
			if(function_exists('vrzno_env') && ($leave = vrzno_env('phonicsNetworkLeave'))) $leave();

			$this->match = null;
			$this->phonics->runtime->service('doom-match', null);
			// Release the multiplayer scope, including replication and remote-owned actors.
			// Keep a local map behind the title so its renderer/assets remain available.
			$this->enter($this->map, false, false);
		}

		$this->complete = false;
		$this->playTitleMusic();

		if(($entity = $this->player()) !== null)
		{
			$view = $this->phonics->entities->get($entity, ViewInput::class);
			$view->fire = false;
			$view->altFire = false;
			$view->consumeYaw();
			$view->consumeUse();
			$view->consumeWeaponSteps();
		}

		$this->engine->input->reset();
		$this->engine->controls->reset();
	}

	/** Apply exits and death restarts after the current fixed update has flushed. */
	public function update(): void
	{
		if($this->intermission !== null) return;

		$entity = $this->player();

		if($entity === null) return;

		$player = $this->phonics->entities->get($entity, PlayerState::class);
		$health = $this->phonics->entities->get($entity, HitPoints::class);

		if($health->current <= 0)
		{
			if($player->canRestart() && ($this->engine->controls->pressed(
				DoomActions::FIRE
			) || $this->engine->controls->pressed(DoomActions::USE)))
			$this->enter($this->map, false);

			return;
		}

		if(!$player->exited) return;

		$content = $this->lease?->context->content();
		$maps = $content instanceof WadLevel ? $content->wad->maps() : [];
		$next = LevelProgression::next($this->map, $player->secretExit, $maps);
		$this->beginTransition();

		$this->intermission = new LevelIntermission(
			$this->map
			, $next
			, $player->secretExit
			, max(0, $player->kills - $this->levelBaseKills)
			, $this->levelTotalKills
			, max(0, $player->items - $this->levelBaseItems)
			, $this->levelTotalItems
			, max(0, $player->secrets - $this->levelBaseSecrets)
			, $this->levelTotalSecrets
			, max(0, (int)floor($this->engine->time - $this->levelStartedAt))
			, LevelProgression::par($this->map)
			, $content instanceof WadLevel ? $content->language->mapTitle($this->map) : $this->map
			, $content instanceof WadLevel && $next !== null ? $content->language->mapTitle($next) : $next
		);

		$this->engine->userPaused = true;
		$this->sound->playMusic(MusicCue::intermissionForMap($this->map));
		$this->engine->input->reset();
		$this->engine->controls->reset();
	}

	public function intermission(): ?LevelIntermission { return $this->intermission; }

	/** Advance the tally with display time while gameplay remains paused. */
	public function advanceIntermission(float $elapsed, bool $activation): void
	{
		if($this->intermission === null) return;

		if($cue = $this->intermission->advance($elapsed, $activation)) $this->playMenuSound($cue);

		if(!$this->intermission->ready()) return;

		$next = $this->intermission->nextMap;
		$this->beginTransition();
		$this->intermission = null;

		if($next !== null) $this->enter($next);
		else
		{
			$this->complete = true;
			$this->engine->userPaused = true;
		}
	}

	/** Consume the cheat text stream; physical gameplay inputs use the shared binding profile. */
	public function browserInput(): bool
	{
		$this->pauseRequested = false;

		if(!function_exists('vrzno_env')) return false;

		$read = vrzno_env('doomInput');

		if(!$read) return false;

		$value = json_decode((string)$read(), true);

		if(!is_array($value)) return false;

		$this->pauseRequested = is_numeric($value['pause'] ?? null) && (int)$value['pause'] > 0;

		$cheatInput = is_string($value['cheats'] ?? null)
			? $this->typeCheats($value['cheats'])
			: false;

		return $cheatInput;
	}

	/** Consume one browser P press that was not part of a recognized cheat prefix. */
	public function consumePauseRequest(): bool
	{
		$requested = $this->pauseRequested;
		$this->pauseRequested = false;

		return $requested;
	}

	/** Sample local look after UI gating, including frames without a simulation update. */
	public function frameInput(Engine $engine, float $elapsed): void
	{
		if($this->intermission !== null) return;

		// Browser pointer lock is active even though this PHP/Wasm SDL build does
		// not emit relative SDL_MOUSEMOTION. Import raw displacement as physical
		// mouse axes so rebinding and device-specific sensitivity remain in PHP.
		if(function_exists('vrzno_env') && ($read = vrzno_env('doomPointerInput')))
		{
			$motion = json_decode((string)$read(), true);

			if(is_array($motion) && count($motion) >= 2)
			{
				if(is_numeric($motion[0]))
				$engine->input->actions?->move(Source::MOUSE_AXIS, Source::X, (float)$motion[0]);

				if(is_numeric($motion[1]))
				$engine->input->actions?->move(Source::MOUSE_AXIS, Source::Y, (float)$motion[1]);
			}
		}

		$frame = $engine->input->frameActions();
		$entity = $this->player();
		$view = $entity === null ? null : $this->phonics->entities->get($entity, ViewInput::class);

		if($view !== $this->viewOwner)
		{
			$this->viewOwner?->consumeYaw();
			$view?->consumeYaw();
			$this->viewOwner = $view;
			$engine->input->actions?->reset();

			return;
		}

		if(!$view) return;

		if($this->phonics->entities->get($entity, HitPoints::class)->current <= 0)
		{
			$view->release();

			return;
		}

		\DoomPhonics\Input\LookInput::advance($view, $frame, $elapsed, $this->settings);
	}

	/** Feed text from a browser or native host into the incremental classic-cheat matcher. */
	public function typeCheats(string $characters): bool
	{
		$commands = [];

		foreach(str_split($characters) as $character)
		{
			$matched = $this->cheatSequence->feed($character);

			if(strtolower($character) === 'p' && !$matched && !$this->cheatSequence->awaiting())
			$this->pauseRequested = true;

			array_push($commands, ...$matched);
		}

		$captured = (bool)$commands || $this->cheatSequence->awaiting();

		foreach($commands as $command)
		{
			$entity = $this->player();

			if($entity === null) return $captured;

			if($this->applyingInitialCheats
				&& $command->type === CheatCommand::OOPS_ALL_GIBS
				&& $this->cheats->oopsAllGibsEnabled()
			){
				$this->cheats->applyActive($this->phonics->entities, $entity);

				continue;
			}

			$result = $this->cheats->apply($command, $this->phonics->entities, $entity);

			if($result->warpMap === null) continue;

			$content = $this->lease?->context->content();

			if(!$content instanceof WadLevel || !in_array($result->warpMap, $content->wad->maps(), true))
			{
				$this->phonics->entities->get(
					$entity
					, PlayerState::class
				)->showMessage('NO SUCH LEVEL ' . $result->warpMap);

				continue;
			}

			$this->enter($result->warpMap);
		}

		return $captured;
	}

	/** Play a non-positional menu cue through the WAD-owned SDL_mixer cache. */
	public function playMenuSound(SoundCue $cue): void
	{
		$this->sound->play(new SoundEvent($cue, new SoundSource(0, 0)));
	}

	/** Use the directly owned title cue; the OPL-only D_INTROA alias is unnecessary. */
	public function playTitleMusic(): void { $this->sound->playMusic(MusicCue::title()); }

	/** Select the canonical D_ExMy lump for the active episode map. */
	public function playLevelMusic(): void
	{
		if(!preg_match('/^E[1-9]M[1-9]$/', $this->map))return;

		$this->sound->playMusic(MusicCue::level($this->map));
	}

	public function beginTransition(): bool { return $this->wipe?->begin() ?? false; }

	public function advanceTransition(float $elapsed): void { $this->wipe?->advance($elapsed); }

	public function transitioning(): bool { return $this->wipe?->active() ?? false; }

	public function status(): array
	{
		$entity = $this->player();
		$wipe = $this->wipe?->status() ?? ['active' => false,'progress' => 1.0,'columns' => 0,'transitions' => 0];

		if($entity === null)
		return [
			'map' => $this->map
			, 'mode' => $this->settings->mode()
			, 'complete' => $this->complete
			, 'intermission' => $this->intermission?->status()
			, 'wipe' => $wipe
			, 'renderResolution' => $this->settings->renderResolution()->value
			, 'pitchMode' => $this->settings->pitchMode()->value
			, 'reticle' => $this->settings->reticle()
			, 'bloodSplatter' => $this->settings->bloodSplatter()
		];

		$player = $this->phonics->entities->get($entity, PlayerState::class);
		$health = $this->phonics->entities->get($entity, HitPoints::class);
		$pose = $this->phonics->entities->get($entity, \DoomPhonics\Component\Transform::class);
		$content = $this->lease?->context->content();

		return [
			'map' => $this->map
			, 'mode' => $this->settings->mode()
			, 'health' => $health->current
			, 'face' => $this->phonics->entities->has($entity, StatusFace::class)
				? $this->phonics->entities->get($entity, StatusFace::class)->patch(
					$health->current
					, $player->godMode || isset($player->powers['Invulnerable'])
				)
				: null
			, 'armor' => $player->armor
			, 'ammo' => $player->ammo
			, 'weapon' => $player->weapon
			, 'weapons' => array_keys(array_filter($player->weapons))
			, 'weaponLayers' => count(
				array_filter(
					$this->phonics->entities->query(WeaponOverlay::class)
					, fn(int $layer): bool => $this->phonics->entities->get($layer, WeaponOverlay::class)
						->playerEntity === $entity
				)
			)
			, 'viewLight' => $this->phonics->entities->has($entity, ViewLighting::class)
				? $this->phonics->entities->get($entity, ViewLighting::class)->mapLightIncrease()
				: 0
			, 'keys' => array_keys(array_filter($player->keys))
			, 'powers' => array_keys($player->powers)
			, 'godMode' => $player->godMode
			, 'noClip' => $player->noClip
			, 'oopsAllGibs' => $this->cheats->oopsAllGibsEnabled()
			, 'kills' => $player->kills
			, 'items' => $player->items
			, 'secrets' => $player->secrets
			, 'message' => $player->message
			, 'paused' => $this->engine->userPaused
			, 'complete' => $this->complete
			, 'intermission' => $this->intermission?->status()
			, 'wipe' => $wipe
			, 'skill' => $this->settings->skill()->label()
			, 'renderResolution' => $this->settings->renderResolution()->value
			, 'pitchMode' => $this->settings->pitchMode()->value
			, 'reticle' => $this->settings->reticle()
			, 'bloodSplatter' => $this->settings->bloodSplatter()
			, 'position' => [$pose->x,$pose->y,$pose->z,$pose->angle]
			, 'previousPosition' => [$pose->previousX,$pose->previousY,$pose->previousZ]
			, 'render' => $content instanceof \DoomPhonics\Adapter\WadLevel ? $content->renderer?->metrics() : null
			, 'skippedThings' => $content instanceof \DoomPhonics\Adapter\WadLevel ? $content->skippedThings : []
			, 'unsupportedSpecials' => $content instanceof \DoomPhonics\Adapter\WadLevel
				? $content->level->unsupportedSpecials
				: []
			, 'frame' => [
				'simulationCpuMs' => $this->engine->simulationCpuMs
				, 'simulationSteps' => $this->engine->simulationSteps
				, 'systems' => $this->engine->systems->timings
				, 'updateCpuMs' => $this->updateMs
				, 'renderCpuMs' => $this->renderMs
			]
		];
	}

	public function close(): void
	{
		$this->lease?->release();
		$this->lease = null;
		$this->sound->close();
		$this->wipe?->close();
	}

	private function player(): ?int
	{
		$players = $this->phonics->entities->query(
			Query::all(PlayerStart::class, PlayerState::class, HitPoints::class)
		);

		foreach($players as $entity)
		if($this->phonics->entities->has($entity, NetworkPlayer::class)
			&& $this->phonics->entities->get($entity, NetworkPlayer::class)->local
		) return $entity;

		return $players[0] ?? null;
	}

	private function rememberPlayer(): void
	{
		$entity = $this->player();

		if($entity === null) return;

		$this->carry = clone $this->phonics->entities->get($entity, PlayerState::class);

		if($this->carry->pendingWeapon !== null)
		{
			$this->carry->weapon = $this->carry->pendingWeapon;
			$this->carry->pendingWeapon = null;
		}

		$this->carry->weaponEntity = null;
		$this->carry->exited = $this->carry->secretExit = false;
		$this->carry->deathTime = null;
		$this->carry->viewHeight = $this->carry->previousViewHeight = PlayerState::VIEW_HEIGHT;
		$this->carry->keys = [];
		$this->carryHealth = max(1, $this->phonics->entities->get($entity, HitPoints::class)->current);
	}

	private function restorePlayer(): void
	{
		if(!$this->carry || ($entity = $this->player()) === null) return;

		$this->phonics->entities->add($entity, clone $this->carry);
		$this->phonics->entities->get($entity, HitPoints::class)->current = $this->carryHealth;
	}

	/** Capture per-map baselines and totals after the active scope has populated its actors. */
	private function beginLevelStatistics(): void
	{
		$this->levelStartedAt = $this->engine->time;
		$this->levelTotalKills = $this->levelTotalItems = $this->levelTotalSecrets = 0;
		$entity = $this->player();

		if($entity !== null)
		{
			$player = $this->phonics->entities->get($entity, PlayerState::class);
			$this->levelBaseKills = $player->kills;
			$this->levelBaseItems = $player->items;
			$this->levelBaseSecrets = $player->secrets;
		}
		else $this->levelBaseKills = $this->levelBaseItems = $this->levelBaseSecrets = 0;

		foreach($this->phonics->entities->query(ActorState::class) as $actor)
		{
			$definition = $this->phonics->entities->get($actor, ActorState::class)->definition;

			if($definition->hasFlag('COUNTKILL')) $this->levelTotalKills++;

			if($definition->hasFlag('COUNTITEM')) $this->levelTotalItems++;
		}

		$content = $this->lease?->context->content();

		if($content instanceof WadLevel && $content->level->map->format !== 'HEXEN')
		foreach($content->level->sectors as $sector)
		if($sector->special === 9) $this->levelTotalSecrets++;
	}
}
