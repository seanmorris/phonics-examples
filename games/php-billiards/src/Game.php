<?php
declare(strict_types=1);
namespace PhpBilliards;

use PhpBilliards\Physics\Simulation;
use PhpBilliards\Render\Camera;
use PhpBilliards\Online\BinaryState;
use PhpBilliards\Online\EightBallMatch;

/**
 * Session-level controls joining deterministic physics, native SDL input, camera state, and HUD intent.
 *
 * The game translates browser diagnostics and SDL snapshots into fixed simulation actions while keeping
 * title flow, cue animation, placement, camera movement, and browser-facing status in one finite state boundary.
 */
final class Game
{
	private const STROKE_BACKSWING = Simulation::STEP * 7;
	private const STROKE_CONTACT = Simulation::STEP * 12;
	private const STROKE_END = Simulation::STEP * 20;
	/** Authoritative deterministic table simulation. */
	public Simulation $simulation;
	/** View camera used for rendering, picking, and player orbit controls. */
	public Camera $camera;
	/** Cue direction in cloth-plane radians. */
	public float $aim = 0.0;
	/** Normalized cue-power setting. */
	public float $power = .58;
	/** Normalized draw or follow offset. */
	public float $top = 0.0;
	/** Normalized left or right English offset. */
	public float $side = 0.0;
	/** Whether game audio events are muted. */
	public bool $muted = false;
	/** Whether the title screen has yielded to active play. */
	public bool $started = false;
	/** Selected title item: zero for Free Play, one for Online Play. */
	public int $titleSelection = 0;
	/** Whether the browser should reveal and connect the embedded room lobby. */
	public bool $onlineRequested = false;
	/** Active play mode: title, free, online, or spectator. */
	public string $mode = 'title';
	/** @var array{float,float} Candidate cue-ball X/Y position after a scratch. */
	public array $placement = [-.65,0.0];
	/** Open modal overlay: `pause`, `controls`, or null during play. */
	public ?string $overlay = null;
	/** Highlighted item in the open overlay menu. */
	public int $menuSelection = 0;
	/** Whether the player has hidden the gameplay HUD. */
	public bool $hudHidden = false;
	/** Latest SDL input family, `keyboard` or a controller theme, used for hints and keycaps. */
	public string $inputTheme = 'keyboard';
	/** Session clock in seconds, advanced by display-rate input frames. */
	public float $clock = 0.0;
	/** Interactive HUD region under the pointer, or null. */
	public ?string $hover = null;
	/** Interactive HUD region currently held down by the pointer, or null. */
	public ?string $pressed = null;
	/** Pointer position in canvas pixels, or null before the first pointer event. */
	public ?array $pointerPosition = null;
	private string $previousPhase = 'aim';
	private array $held = [];
	private ?string $guideKey = null;
	private array $guide = ['path' => [],'contact' => null];
	/** Active pointer gesture: aim, orbit, power, or spin. */
	private ?string $drag = null;
	/** @var array{float,float} Pointer position where the current gesture began. */
	private array $dragStart = [0.0,0.0];
	/** Pointer angle around the cue ball on the previous aim-drag sample. */
	private ?float $dragAngle = null;
	/** Pull-back amount while drawing the cue from the power meter, or null. */
	private ?float $pull = null;
	/** Power before a pull-back began, restored when the gesture is cancelled. */
	private float $pullPrevious = .58;
	/** Pointer distance that draws a full-power pull, fitted to the canvas space below the press. */
	private float $pullTravel = 1.0;
	private array $pointer = [0.0,0.0];
	private bool $fullscreenRequest = false;
	/** Seconds the current keyboard or stick aim input has been held, for acceleration. */
	private float $aimHold = 0.0;
	/** Whether the camera should ease behind the cue. */
	private bool $follow = false;
	/** Session time of the latest manual orbit or zoom. */
	private float $manualCamera = -INF;
	/** @var array{text:string,tone:string,until:float}|null Short-lived feedback for a rejected command. */
	private ?array $notice = null;
	/** Authoritative online eight-ball rules, or null during free play. */
	private ?EightBallMatch $match = null;
	/** Local Matrix player identifier, or null for spectators and free play. */
	private ?string $localPlayer = null;
	/** @var list<array<string,mixed>> Browser-bound deterministic protocol messages. */
	private array $networkOutbox = [];
	/** @var list<array<string,mixed>> Physics events waiting for browser sound and diagnostics. */
	private array $renderEvents = [];
	/** Network sequence currently being simulated, or null between actions. */
	private ?int $activeSequence = null;
	/** Whether Matrix has confirmed the exact state from which the next action may begin. */
	private bool $roomConfirmed = false;
	/** Whether a local action is waiting for its durable Matrix event to return. */
	private bool $actionRequested = false;
	/** Last room-protocol failure that prevents further deterministic play. */
	private ?string $networkProblem = null;
	/** Matrix actor for the action currently being simulated. */
	private ?string $activeActor = null;
	/** Active deterministic action type, shot or place. */
	private ?string $activeAction = null;
	/** @var array{sequence:int,hash:string,nextActor:string,outcome:array<string,mixed>|null}|null Locally reported settlement awaiting room confirmation. */
	private ?array $reportedSettlement = null;
	private int $networkMessageId = 0;
	/** @var array{time:float,previousTime:float,contacted:bool,aim:float,power:float,top:float,side:float,x:float,y:float}|null */
	private ?array $stroke = null;

	public function __construct()
	{
		$this->simulation = new Simulation;
		$this->camera = new Camera;
	}

	/**
	 * Apply one display-rate SDL input sample before fixed simulation updates.
	 *
	 * Browser diagnostics, native buttons, pointer events, and the right stick enter through this boundary, which
	 * advances title-camera animation and maps accepted commands onto stable game and simulation state.
	 *
	 * @param array<string,mixed> $browser Browser commands and diagnostic control overrides.
	 * @param array<string,int|float> $controls Current keyboard and gamepad control values.
	 * @param list<array<string,mixed>> $events Pointer and wheel events received since the last frame.
	 * @param array<string,array<string,mixed>> $regions Interactive HUD regions keyed by control name.
	 * @param array{float,float} $rightStick Horizontal and vertical camera-stick axes.
	 * @param float $elapsed Display-frame duration in seconds.
	 * @param int $width Canvas width in pixels.
	 * @param int $height Canvas height in pixels.
	 */
	public function input(
		array $browser, array $controls, array $events, array $regions, array $rightStick
		, float $elapsed, int $width, int $height
	): void
	{
		$elapsed = max(0, min(.1, is_finite($elapsed) ? $elapsed : 0));
		$this->clock += $elapsed;
		$this->networkInput($browser);
		$actions = array_values(array_filter((array)($browser['actions'] ?? []), 'is_string'));
		$fine = (float)($controls['button20'] ?? 0) >= .5;

		foreach([
			'primary' => 0,'reset' => 1,'clear-spin' => 2,'rack' => 3,'pause' => 9
			, 'mute' => 18,'fullscreen' => 17,'camera-reset' => 19,'controls' => 21,'toggle-hud' => 22
		] as $name => $button)
		{
			$down = (float)($controls['button' . $button] ?? 0) >= .5;
			if($down && empty($this->held[$name])) $actions[] = $name;
			$this->held[$name] = $down;
		}

		$up = (float)($controls['up'] ?? 0) >= .5;
		$downward = (float)($controls['down'] ?? 0) >= .5;
		$stepUp = $up && empty($this->held['nav-up']);
		$stepDown = $downward && empty($this->held['nav-down']);
		$this->held['nav-up'] = $up;
		$this->held['nav-down'] = $downward;

		foreach($events as $event) if(is_array($event)) $this->pointerEvent($event, $regions, $width, $height, $fine);

		if($this->overlay !== null)
		{
			$items = $this->menuItems();
			if($stepUp) $this->menuSelection = ($this->menuSelection + count($items) - 1) % max(1, count($items));
			if($stepDown) $this->menuSelection = ($this->menuSelection + 1) % max(1, count($items));
			foreach(array_unique($actions) as $action) $this->menuAction($action);
			$this->phaseTransition();
			return;
		}

		if(!$this->started)
		{
			if($stepUp) $this->titleSelection = 0;
			if($stepDown) $this->titleSelection = 1;
		}
		foreach(array_unique($actions) as $action) $this->action($action);
		if(isset($browser['debugCamera']) && is_array($browser['debugCamera'])) $this->camera->patch($browser['debugCamera']);

		if(!$this->started || $this->overlay !== null) return;

		$values = isset($browser['values']) && is_array($browser['values']) ? $browser['values'] : [];
		foreach(['aim','power','top','side'] as $name) if($this->canControl()
			&& isset($values[$name]) && is_numeric($values[$name]))
		{
			$value = (float)$values[$name];
			if(!is_finite($value)) continue;
			if($name === 'aim') $this->aim = deg2rad(max(-180, min(180, $value)));
			elseif($name === 'power') $this->power = max(.05, min(1, $value));
			else $this->{$name} = max(-1, min(1, $value));
		}

		$this->camera->animate($elapsed);
		if(abs((float)($rightStick[0] ?? 0)) > 1e-6 || abs((float)($rightStick[1] ?? 0)) > 1e-6)
		{
			$this->camera->orbitStick((float)($rightStick[0] ?? 0), (float)($rightStick[1] ?? 0), $elapsed);
			$this->manualCamera = $this->clock;
			$this->follow = false;
		}

		$horizontal = max(-1, min(1, (float)($controls['right'] ?? 0) - (float)($controls['left'] ?? 0)));
		$vertical = max(-1, min(1, (float)($controls['down'] ?? 0) - (float)($controls['up'] ?? 0)));
		if($this->canControl() && $this->simulation->phase === 'placing')
		{
			// Move the preview in screen directions so arrow keys agree with the current orbit.
			$speed = ($fine ? .18 : .72) * $elapsed;
			$forward = [-cos($this->camera->yaw),-sin($this->camera->yaw)];
			$right = [-$forward[1],$forward[0]];
			$this->placement[0] += ($horizontal * $right[0] - $vertical * $forward[0]) * $speed;
			$this->placement[1] += ($horizontal * $right[1] - $vertical * $forward[1]) * $speed;
			$this->clampPlacement();
		}
		elseif($this->canAdjustShot())
		{
			if(abs($horizontal) > 1e-6)
			{
				$this->aimHold += $elapsed;
				// Squared stick response gives fine control near the dead zone; held keys accelerate.
				$ramp = min(1, max(0, ($this->aimHold - .25) / .75));
				$rate = $fine ? 2.5 : 12 + 78 * $ramp * $ramp;
				$this->aim = self::wrapAngle($this->aim + $horizontal * abs($horizontal) * deg2rad($rate) * $elapsed);
				$this->follow = true;
			}
			else $this->aimHold = 0.0;

			$this->power = max(.05, min(1, $this->power - $vertical * ($fine ? .15 : .6) * $elapsed));
			$spinRate = ($fine ? .3 : .9) * $elapsed;
			$this->top = max(-1, min(1, $this->top + (
				(float)($controls['button7'] ?? 0) - (float)($controls['button6'] ?? 0)
			) * $spinRate));
			$this->side = max(-1, min(1, $this->side + (
				(float)($controls['button5'] ?? 0) - (float)($controls['button4'] ?? 0)
			) * $spinRate));
			self::clampSpin($this->top, $this->side);
		}

		$this->phaseTransition();
		$cue = $this->simulation->cue();
		if($this->follow && $cue->active && $this->drag === null && $this->clock - $this->manualCamera > 1.5
			&& in_array($this->simulation->phase, ['aim','placing'], true) && $this->stroke === null)
		$this->camera->follow($cue->x, $cue->y, $this->aim, $elapsed);
	}

	/**
	 * Wrap an angle into (-pi, pi].
	 *
	 * @param float $angle Angle in radians; non-finite input becomes zero.
	 */
	public static function wrapAngle(float $angle): float
	{
		if(!is_finite($angle)) return 0.0;
		$angle = fmod($angle + M_PI, M_PI * 2);
		if($angle <= 0) $angle += M_PI * 2;

		return $angle - M_PI;
	}

	/** Keep the cue-tip offset inside the unit disc drawn by the spin control. */
	private static function clampSpin(float &$top, float &$side): void
	{
		$length = hypot($top, $side);
		if($length <= 1) return;
		$top /= $length;
		$side /= $length;
	}

	private function canAdjustShot(): bool
	{
		return $this->started && $this->overlay === null && $this->canControl()
			&& $this->simulation->phase === 'aim' && $this->stroke === null && !$this->simulation->moving();
	}

	private function clampPlacement(): void
	{
		[$limitX,$limitY] = Simulation::placementLimits();
		$this->placement[0] = max(-$limitX, min($limitX, $this->placement[0]));
		$this->placement[1] = max(-$limitY, min($limitY, $this->placement[1]));
	}

	/** Consume a browser-only fullscreen request generated by native SDL input. */
	public function consumeFullscreenRequest(): bool
	{
		$request = $this->fullscreenRequest;
		$this->fullscreenRequest = false;
		return $request;
	}

	private function action(string $action): void
	{
		if($action === 'online-close' || $action === 'main-menu')
		{
			$this->returnToTitle();
			return;
		}
		if(!$this->started)
		{
			if($action === 'free-play' || (in_array($action, ['start','shoot','primary'], true)
				&& $this->titleSelection === 0)) $this->startFreePlay();
			elseif($action === 'online-play' || (in_array($action, ['start','shoot','primary'], true)
				&& $this->titleSelection === 1))
			{
				$this->titleSelection = 1;
				$this->onlineRequested = true;
				$this->mode = 'online';
			}
			elseif($action === 'fullscreen') $this->fullscreenRequest = true;
			elseif($action === 'mute') $this->muted = !$this->muted;

			return;
		}

		if($action === 'pause' || $action === 'menu')
		{
			$this->openOverlay('pause');
			return;
		}
		if($action === 'controls')
		{
			$this->openOverlay('controls');
			return;
		}

		if($action === 'shoot' || $action === 'primary')
		{
			if(!$this->canControl()) return;
			if($this->simulation->phase === 'placing') $this->placeCue();
			elseif($this->stroke === null && $this->simulation->phase === 'aim' && !$this->simulation->moving())
			{
				if($this->match) $this->requestAction([
					'type' => 'shot'
					, 'aim_udeg' => (int)round(rad2deg($this->aim) * 1000000)
					, 'power_bp' => (int)round($this->power * 10000)
					, 'top_bp' => (int)round($this->top * 10000)
					, 'side_bp' => (int)round($this->side * 10000)
				]);
				else $this->beginStroke($this->aim, $this->power, $this->top, $this->side);
			}
		}
		elseif($action === 'rack' && !$this->match) {$this->stroke = null; $this->simulation->rack(); $this->follow = true;}
		elseif($action === 'reset' && !$this->match) {$this->stroke = null; $this->simulation->resetShot(); $this->follow = true;}
		elseif($action === 'clear-spin' && $this->canControl()) $this->top = $this->side = 0;
		elseif($action === 'mute') $this->muted = !$this->muted;
		elseif($action === 'fullscreen') $this->fullscreenRequest = true;
		elseif($action === 'toggle-hud') $this->hudHidden = !$this->hudHidden;
		elseif($action === 'leave-table') $this->leaveTable();
		elseif($action === 'camera-reset')
		{
			$cue = $this->simulation->cue();
			if($cue->active) $this->camera->behind($cue->x, $cue->y, $this->aim);
			else $this->camera->reset();
			$this->follow = true;
			$this->manualCamera = -INF;
		}
	}

	/**
	 * Confirm the previewed scratch placement.
	 *
	 * Illegal spots never reach the room: a rejected online placement would fail validation on both clients and end
	 * the match, so every input path checks the simulation rules here and explains the problem instead.
	 */
	private function placeCue(): void
	{
		$problem = $this->simulation->placementProblem(...$this->placement);
		if($problem !== null)
		{
			$this->notice = ['text' => self::placementMessage($problem),'tone' => 'error','until' => $this->clock + 2.4];
			$this->renderEvents[] = ['type' => 'denied'];
			return;
		}

		if($this->match) $this->requestAction([
			'type' => 'place'
			, 'x_um' => (int)round($this->placement[0] * 1000000)
			, 'y_um' => (int)round($this->placement[1] * 1000000)
		]);
		else
		{
			$this->simulation->placeCue(...$this->placement);
			$this->follow = true;
		}
	}

	/** @param array{reason:string,ball?:int} $problem */
	private static function placementMessage(array $problem): string
	{
		return match($problem['reason']) {
			'ball' => 'Too close to the ' . ($problem['ball'] ?? '') . '-ball',
			'pocket' => 'Too close to the pocket',
			default => 'Keep the cue ball on the cloth'
		};
	}

	private function returnToTitle(): void
	{
		$this->started = false;
		$this->onlineRequested = false;
		$this->mode = 'title';
		$this->overlay = null;
		$this->match = null;
		$this->localPlayer = null;
		$this->stroke = null;
		$this->activeSequence = null;
		$this->roomConfirmed = false;
		$this->actionRequested = false;
		$this->networkProblem = null;
		$this->reportedSettlement = null;
		$this->drag = null;
		$this->pull = null;
	}

	/** Ask the room to release this seat; the lobby then stops the match. */
	private function leaveTable(): void
	{
		$this->overlay = null;
		if($this->match) $this->emitNetwork('leave', []);
		else $this->returnToTitle();
	}

	private function openOverlay(string $overlay): void
	{
		$this->overlay = $overlay;
		$this->menuSelection = 0;
		$this->drag = null;
		$this->pressed = null;
		if($this->pull !== null) {$this->power = $this->pullPrevious; $this->pull = null;}
	}

	/**
	 * Items of the open overlay as [action, label] pairs, in display order.
	 *
	 * @return list<array{string,string}>
	 */
	public function menuItems(): array
	{
		if($this->overlay === 'controls') return [['back','Back']];
		if($this->overlay !== 'pause') return [];

		$items = [['resume','Resume'],['controls','Controls']];
		$items[] = ['mute',$this->muted ? 'Sound: off' : 'Sound: on'];
		$items[] = ['toggle-hud',$this->hudHidden ? 'Show HUD' : 'Hide HUD'];
		$items[] = ['fullscreen','Fullscreen'];
		if($this->match && $this->localPlayer !== null && $this->match->outcome === null) $items[] = ['leave-table','Leave table'];
		elseif(!$this->match) $items[] = ['main-menu','Main menu'];

		return $items;
	}

	/** Route one command while a modal overlay owns input. */
	private function menuAction(string $action): void
	{
		$items = $this->menuItems();
		if(in_array($action, ['primary','shoot','start'], true))
		$action = $items[$this->menuSelection][0] ?? 'resume';

		if(in_array($action, ['pause','resume','menu'], true)) $this->overlay = null;
		elseif($action === 'reset' || $action === 'back') $this->overlay = $this->overlay === 'controls' ? 'pause' : null;
		elseif($action === 'controls')
		{
			$this->overlay = $this->overlay === 'controls' ? null : 'controls';
			$this->menuSelection = 0;
		}
		elseif(in_array($action, ['mute','toggle-hud','fullscreen'], true))
		{
			if($action === 'mute') $this->muted = !$this->muted;
			elseif($action === 'toggle-hud') $this->hudHidden = !$this->hudHidden;
			else $this->fullscreenRequest = true;
		}
		elseif($action === 'leave-table') $this->leaveTable();
		elseif($action === 'main-menu') $this->returnToTitle();

		if($this->overlay === null) $this->menuSelection = 0;
	}

	private function startFreePlay(): void
	{
		$this->mode = 'free';
		$this->started = true;
		$this->onlineRequested = false;
		$this->follow = false;
		$this->camera->beginGameplayView();
	}

	private function beginStroke(float $aim, float $power, float $top, float $side): void
	{
		$cue = $this->simulation->cue();
		if(!$cue->active) return;
		$this->stroke = [
			'time' => 0.0, 'previousTime' => 0.0, 'contacted' => false
			, 'aim' => $aim, 'power' => $power, 'top' => $top, 'side' => $side
			, 'x' => $cue->x, 'y' => $cue->y
		];
	}

	private function canControl(): bool
	{
		return $this->match === null || ($this->localPlayer !== null && $this->match->actor === $this->localPlayer
			&& $this->match->outcome === null && $this->roomConfirmed && !$this->actionRequested
			&& $this->activeSequence === null);
	}

	/** Whether the current session is governed by room-backed eight-ball rules. */
	public function online(): bool { return $this->match !== null; }

	/** Whether this browser may alter the current shot or placement. */
	public function controllable(): bool { return $this->canControl(); }

	/**
	 * Build the compact authoritative player and table progress used by the online OSD.
	 *
	 * Pocketed group balls are derived from the confirmed simulation itself, so a reconnecting player or spectator
	 * receives the same scoreboard without adding a second history stream to the deterministic room protocol.
	 *
	 * @return array<string,mixed>|null Online turn and group rows, or null during free play.
	 */
	public function onlineScoreboard(): ?array
	{
		if($this->match === null) return null;
		$down = ['solid' => [],'stripe' => []];
		$eightDown = false;
		foreach($this->simulation->balls as $ball) if(!$ball->active)
		{
			if($ball->number === 8) $eightDown = true;
			elseif($ball->number >= 1 && $ball->number <= 7) $down['solid'][] = $ball->number;
			elseif($ball->number >= 9 && $ball->number <= 15) $down['stripe'][] = $ball->number;
		}
		sort($down['solid']);
		sort($down['stripe']);
		$assigned = count(array_filter($this->match->groups, static fn(?string $group): bool => $group !== null)) > 0;
		$players = [];
		foreach($this->match->players as $player)
		{
			$group = $this->match->groups[$player] ?? null;
			$players[] = [
				'id' => $player
				, 'name' => self::playerName($player)
				, 'local' => $player === $this->localPlayer
				, 'turn' => $player === $this->match->actor
				, 'group' => $group
				, 'down' => $group === null ? [] : $down[$group]
			];
		}

		return [
			'actor' => $this->match->actor
			, 'assigned' => $assigned
			, 'openDown' => $assigned ? [] : array_values(array_merge($down['solid'], $down['stripe']))
			, 'eightDown' => $eightDown
			, 'players' => $players
		];
	}

	/**
	 * Convert a Matrix user identifier into its display localpart, keeping case and punctuation.
	 *
	 * @param string $player Matrix user identifier such as `@alice:example.org`.
	 */
	public static function playerName(string $player): string
	{
		$localpart = explode(':', ltrim($player, '@'), 2)[0];
		return $localpart === '' ? $player : $localpart;
	}

	/**
	 * Contextual one-line feedback for the HUD toast, or null when nothing needs saying.
	 *
	 * Wording is sentence case and input-aware; raw `statusText()` stays stable for the browser bridge and tests.
	 *
	 * @return array{text:string,tone:string}|null Tone is info, turn, warning, or error.
	 */
	public function toast(): ?array
	{
		if(!$this->started || $this->overlay !== null) return null;
		if($this->notice !== null && $this->notice['until'] > $this->clock)
		return ['text' => $this->notice['text'],'tone' => $this->notice['tone']];
		$this->notice = null;
		$pad = $this->inputTheme !== 'keyboard';

		if($this->match)
		{
			if($this->match->outcome) return null;
			if($this->networkProblem !== null) return ['text' => 'Connection problem: ' . $this->networkProblem,'tone' => 'error'];
			$actor = self::playerName((string)$this->match->actor);
			if($this->localPlayer === null)
			return ['text' => 'Watching · ' . $actor . ($this->simulation->phase === 'placing' ? ' has ball in hand' : ' to shoot'),'tone' => 'info'];
			if($this->match->actor !== $this->localPlayer)
			return ['text' => $actor . ($this->simulation->phase === 'placing' ? ' has ball in hand' : '’s turn'),'tone' => 'turn'];
			if(!$this->roomConfirmed || $this->actionRequested || $this->activeSequence !== null)
			return $this->activeSequence !== null ? null : ['text' => 'Syncing with the table…','tone' => 'info'];
		}

		if($this->stroke !== null || $this->simulation->moving()) return null;
		if($this->simulation->phase === 'placing')
		{
			$problem = $this->simulation->placementProblem(...$this->placement);
			if($problem !== null) return ['text' => self::placementMessage($problem),'tone' => 'warning'];

			return ['text' => $pad ? 'Ball in hand · move with the stick, press A to place'
				: 'Ball in hand · click a clear spot or press Space','tone' => 'info'];
		}

		$contact = $this->simulation->contactWarning($this->aim);
		if($contact !== null) return ['text' => "Nearly frozen to the $contact-ball · contact is immediate",'tone' => 'warning'];
		if($this->match && $this->roomConfirmed) return ['text' => 'Your turn','tone' => 'turn'];

		return null;
	}

	/**
	 * Final result card for a finished online game or a cleared free-play rack.
	 *
	 * @return array{title:string,detail:string,actions:list<array{string,string}>}|null
	 */
	public function endCard(): ?array
	{
		if(!$this->started || $this->overlay !== null) return null;
		if($this->match && $this->match->outcome)
		{
			$outcome = $this->match->outcome;
			$winner = (string)($outcome['winner'] ?? '');
			$title = $winner === $this->localPlayer ? 'You win' : self::playerName($winner) . ' wins';
			$detail = match($outcome['reason'] ?? '') {
				'legal-eight' => 'Cleared the group and sank the 8-ball',
				'early-eight' => 'The 8-ball went down early',
				'scratched-eight' => 'Scratched while shooting the 8-ball',
				default => 'Game over'
			};

			return ['title' => $title,'detail' => $detail,'actions' => [['leave-table','Back to lobby']]];
		}
		if(!$this->match && $this->simulation->phase === 'aim' && !$this->simulation->moving() && $this->stroke === null)
		{
			foreach($this->simulation->balls as $ball) if($ball->number !== 0 && $ball->active) return null;
			$shots = $this->simulation->shots;

			return ['title' => 'Rack cleared','detail' => "Every ball down in $shots shot" . ($shots === 1 ? '' : 's')
				, 'actions' => [['rack','New rack']]];
		}

		return null;
	}

	private function requestAction(array $action): void
	{
		if(!$this->match || !$this->canControl()) return;
		$this->emitNetwork('action-request', ['action' => $action,'preHash' => $this->match->hash($this->simulation)]);
		$this->actionRequested = true;
	}

	/** Apply browser protocol messages only after their room events have been accepted and validated. */
	private function networkInput(array $browser): void
	{
		$acks = array_fill_keys(array_map('strval', (array)($browser['networkAcks'] ?? [])), true);
		if($acks) $this->networkOutbox = array_values(array_filter(
			$this->networkOutbox, static fn(array $message): bool => !isset($acks[(string)$message['id']])
		));

		foreach((array)($browser['network'] ?? []) as $message)
		{
			if(!is_array($message)) continue;
			try
			{
					switch($message['type'] ?? null)
					{
						case 'start': $this->startOnline($message['detail'] ?? []); break;
						case 'action': $this->acceptOnlineAction($message['detail'] ?? []); break;
						case 'confirmed': $this->confirmOnlineTurn($message['detail'] ?? []); break;
						case 'write-rejected': $this->rejectNetworkWrite($message['detail'] ?? []); break;
						case 'stop': $this->stopOnline($message['detail'] ?? []); break;
						case 'online-close': $this->action('online-close'); break;
					}
			}
				catch(\Throwable $error)
				{
					$this->networkProblem = $error->getMessage();
					$this->emitNetwork('fatal', ['message' => $error->getMessage()]);
				}
		}
	}

	/** Initialize or restore a room-negotiated online match. */
	private function startOnline(array $detail): void
	{
		$players = array_values(array_map('strval', (array)($detail['players'] ?? [])));
		$seed = (string)($detail['seed'] ?? '');
		$breaker = (string)($detail['breaker'] ?? '');
		if(count($players) !== 2 || !preg_match('/^[a-f0-9]{64}$/', $seed))
		throw new \UnexpectedValueException('Invalid online match start');

		$this->simulation = new Simulation($seed, 0);
		$this->match = new EightBallMatch($players, $breaker, $seed);
		$this->localPlayer = isset($detail['localPlayer']) ? (string)$detail['localPlayer'] : null;
		$this->mode = ($detail['role'] ?? null) === 'spectator' ? 'spectator' : 'online';
		$this->activeSequence = null;
		$this->roomConfirmed = false;
		$this->actionRequested = false;
		$this->networkProblem = null;
		$this->activeActor = null;
		$this->activeAction = null;
		$this->reportedSettlement = null;
		$this->stroke = null;
		$this->renderEvents = [];

		if(!empty($detail['checkpoint']))
		{
			$state = BinaryState::import((string)$detail['checkpoint']);
			if(!is_array($state)) throw new \UnexpectedValueException('Invalid online checkpoint');
			$this->match->restore($state, $this->simulation);
			if(isset($detail['hash']) && $detail['hash'] !== null
				&& !hash_equals((string)$detail['hash'], $this->match->hash($this->simulation)))
			throw new \UnexpectedValueException('Online checkpoint hash differs from the confirmed state');
		}

		$this->started = true;
		$this->onlineRequested = true;
		$this->camera->beginGameplayView();
		if($this->localPlayer !== null && empty($detail['checkpoint']))
		$this->emitNetwork('ready', ['hash' => $this->match->hash($this->simulation)]);
	}

	/** Accept an explicitly sequenced and quantized room action. */
	private function acceptOnlineAction(array $detail): void
	{
		if(!$this->match) throw new \LogicException('Online action arrived without a match');
		$sequence = (int)($detail['sequence'] ?? -1);
		$actor = (string)($detail['actor'] ?? '');
		$preHash = (string)($detail['pre_hash'] ?? '');
		$action = $detail['action'] ?? null;
		if(!is_array($action) || $preHash !== $this->match->hash($this->simulation))
		throw new \UnexpectedValueException('Online action pre-state differs');
		$this->match->begin($sequence, $actor, $action, $preHash);
		$this->activeSequence = $sequence;
		$this->roomConfirmed = false;
		$this->actionRequested = false;
		$this->networkProblem = null;
		$this->activeActor = $actor;
		$this->activeAction = (string)($action['type'] ?? '');
		$this->reportedSettlement = null;

		if($this->activeAction === 'shot')
		{
			$aim = max(-180000000, min(180000000, (int)($action['aim_udeg'] ?? 0)));
			$power = max(500, min(10000, (int)($action['power_bp'] ?? 0)));
			$top = max(-10000, min(10000, (int)($action['top_bp'] ?? 0)));
			$side = max(-10000, min(10000, (int)($action['side_bp'] ?? 0)));
			$this->aim = deg2rad($aim / 1000000);
			$this->power = $power / 10000;
			$this->top = $top / 10000;
			$this->side = $side / 10000;
			$this->beginStroke($this->aim, $this->power, $this->top, $this->side);
		}
		elseif($this->activeAction === 'place')
		{
			$x = max(-2000000, min(2000000, (int)($action['x_um'] ?? 0))) / 1000000;
			$y = max(-2000000, min(2000000, (int)($action['y_um'] ?? 0))) / 1000000;
			if(!$this->simulation->placeCue($x, $y)) throw new \UnexpectedValueException('Illegal cue-ball placement');
			$this->settleOnline($this->match->settlePlacement($this->simulation));
		}
	}

	private function settleOnline(array $result): void
	{
		$this->reportedSettlement = [
			'sequence' => (int)$result['sequence']
			, 'hash' => (string)$result['hash']
			, 'nextActor' => (string)$result['nextActor']
			, 'outcome' => is_array($result['outcome'] ?? null) ? $result['outcome'] : null
		];
		$body = $result;
		if($this->localPlayer !== null && $this->activeActor === $this->localPlayer)
		$body['checkpoint'] = $this->match?->checkpoint($this->simulation);
		$this->emitNetwork('settled', $body);
		$this->activeActor = null;
		$this->activeAction = null;
	}

	/** Unlock a turn only after both Matrix players acknowledged the same deterministic state. */
	private function confirmOnlineTurn(array $detail): void
	{
		if(!$this->match) throw new \LogicException('Online confirmation arrived without a match');
		$sequence = (int)($detail['sequence'] ?? -1);
		$hash = (string)($detail['hash'] ?? '');
		$nextActor = (string)($detail['nextActor'] ?? '');
		$outcome = is_array($detail['outcome'] ?? null) ? $detail['outcome'] : null;
		$checkpoint = (string)($detail['checkpoint'] ?? '');

		// Matrix delivery and a display frame can cross. A confirmation for the state
		// immediately preceding an action is harmless once that action is in flight.
		if($sequence < $this->match->sequence
			|| ($this->activeSequence !== null && $sequence < $this->activeSequence)) return;

		$expected = $this->reportedSettlement ?? [
			'sequence' => $this->match->sequence
			, 'hash' => $this->match->hash($this->simulation)
			, 'nextActor' => $this->match->actor
			, 'outcome' => $this->match->outcome
		];
		$sequenceMismatch = $sequence !== $expected['sequence'];
		$hashMismatch = $hash === '' || !hash_equals($expected['hash'], $hash);
		$nextActorMismatch = $nextActor !== $expected['nextActor'];
		$outcomeMismatch = $outcome !== $expected['outcome'];
		$activeMismatch = $this->activeSequence !== null && $sequence !== $this->activeSequence;
		$localHashMismatch = $hash === '' || !hash_equals($hash, $this->match->hash($this->simulation));
		$recovered = !$activeMismatch && $checkpoint !== ''
			&& ($sequenceMismatch || $hashMismatch || $nextActorMismatch || $outcomeMismatch || $localHashMismatch)
			&& $this->restoreConfirmedCheckpoint($checkpoint, $sequence, $hash, $nextActor, $outcome);

		if(!$recovered)
		{
			if($sequenceMismatch)
			throw new \UnexpectedValueException("Room confirmed sequence $sequence; local sequence is {$expected['sequence']}");
			if($hashMismatch)
			throw new \UnexpectedValueException('Room confirmation hash differs from the locally reported settlement');
			if($nextActorMismatch)
			throw new \UnexpectedValueException('Room confirmation names a different next player');
			if($outcomeMismatch)
			throw new \UnexpectedValueException('Room confirmation has a different game outcome');
			if($activeMismatch)
			throw new \UnexpectedValueException('Room confirmation does not match the active action');
			if($localHashMismatch)
			throw new \UnexpectedValueException('Local state changed after reporting its settlement');
		}

		$this->activeSequence = null;
		$this->reportedSettlement = null;
		$this->roomConfirmed = $this->match->outcome === null;
		$this->actionRequested = false;
		$this->networkProblem = null;
	}

	/**
	 * Restore the room's actor-supplied settlement checkpoint after independently verifying every confirmation field.
	 *
	 * A rejected or malformed candidate is rolled back to the exact local state captured before import. This lets a
	 * client recover from local delivery drift without accepting state that the two-player protocol did not hash.
	 *
	 * @param string $checkpoint Unpadded base64url checkpoint attached to the confirmed settlement.
	 * @param int $sequence Room-confirmed action sequence.
	 * @param string $hash Room-confirmed canonical state hash.
	 * @param string $nextActor Room-confirmed player allowed to act next.
	 * @param array<string,mixed>|null $outcome Room-confirmed terminal result, or null during play.
	 */
	private function restoreConfirmedCheckpoint(
		string $checkpoint
		, int $sequence
		, string $hash
		, string $nextActor
		, ?array $outcome
	): bool {
		if(!$this->match || strlen($checkpoint) > 1024 * 1024 || !preg_match('/^[a-f0-9]{64}$/D', $hash))
		return false;

		$before = $this->match->checkpoint($this->simulation);

		try
		{
			$state = BinaryState::import($checkpoint);
			if(!is_array($state)) throw new \UnexpectedValueException('Checkpoint state is not a map');
			$this->match->restore($state, $this->simulation);
			if($this->match->sequence !== $sequence || $this->match->actor !== $nextActor
				|| $this->match->outcome !== $outcome
				|| !hash_equals($hash, $this->match->hash($this->simulation))
			) throw new \UnexpectedValueException('Checkpoint does not match room confirmation');

			return true;
		}
		catch(\Throwable)
		{
			$state = BinaryState::import($before);
			if(!is_array($state)) throw new \LogicException('Local checkpoint could not be restored');
			$this->match->restore($state, $this->simulation);

			return false;
		}
	}

	/** Retire a terminal browser write without allowing its stale action to retry every frame. */
	private function rejectNetworkWrite(array $detail): void
	{
		if(($detail['messageType'] ?? null) === 'action-request')
		{
			$this->actionRequested = false;
			$this->roomConfirmed = false;
		}
		$this->networkProblem = (string)($detail['message'] ?? 'Room write rejected');
		$this->renderEvents[] = [
			'type' => 'network-error'
			, 'message' => $this->networkProblem
		];
	}

	private function stopOnline(array $detail): void
	{
		$this->roomConfirmed = false;
		$this->actionRequested = false;
		if(isset($detail['reason']))
		{
			$this->networkProblem = (string)$detail['reason'];
			$this->renderEvents[] = ['type' => 'network-stop','reason' => $this->networkProblem];
		}
	}

	private function emitNetwork(string $type, array $payload): void
	{
		$this->networkOutbox[] = ['id' => (string)++$this->networkMessageId,'type' => $type] + $payload;
	}

	private function pointerEvent(array $event, array $regions, int $width, int $height, bool $fine = false): void
	{
		$type = (string)($event['type'] ?? '');
		if($type === 'wheel')
		{
			if($this->started && $this->overlay === null)
			{
				$this->camera->zoom(-(float)($event['y'] ?? 0) * 120);
				$this->manualCamera = $this->clock;
			}
			return;
		}
		if($type !== 'pointer') return;

		$x = (float)($event['x'] ?? NAN);
		$y = (float)($event['y'] ?? NAN);
		if(!is_finite($x) || !is_finite($y)) return;
		$action = (string)($event['action'] ?? '');
		$button = (int)($event['button'] ?? 1);
		$previous = $this->pointer;
		$this->pointer = [$x,$y];
		$this->pointerPosition = [$x,$y];
		$hit = self::hit($regions, $x, $y);
		$interactive = $hit !== null && in_array($hit['type'] ?? '', ['action','spin','power'], true);
		$this->hover = $interactive ? (string)$hit['name'] : null;

		if($action === 'up')
		{
			if($this->drag === 'power') $this->releasePull();
			$this->drag = null;
			$this->pressed = null;
			$this->dragAngle = null;
			return;
		}

		// Title and overlay menus: hovering selects, pressing activates.
		if(!$this->started || $this->overlay !== null)
		{
			if($hit && ($hit['type'] ?? '') === 'action')
			{
				$name = (string)$hit['name'];
				if(!$this->started) $this->titleSelection = $name === 'online-play' ? 1 : 0;
				else foreach($this->menuItems() as $index => [$item]) if($item === $name) $this->menuSelection = $index;
				if($action === 'down' && $button === 1)
				{
					$this->pressed = $name;
					$this->started ? $this->menuAction($name) : $this->action($name);
				}
			}
			return;
		}

		if($action === 'move' && $this->drag !== null)
		{
			match($this->drag) {
				'spin' => $this->setSpin($x, $y, $regions['spin'] ?? null),
				'power' => $this->updatePull($y),
				'aim' => $this->aimAt($x, $y, $width, $height, $fine),
				'orbit' => $this->camera->orbit($x - $previous[0], $y - $previous[1], $this->dragStart[1] / max(1, $height)),
				default => null
			};
			if($this->drag === 'orbit') $this->manualCamera = $this->clock;
			return;
		}

		$placing = $this->canControl() && $this->simulation->phase === 'placing';
		if($action === 'move')
		{
			if($placing && !$interactive && ($hit['type'] ?? '') !== 'panel') $this->previewPlacement($x, $y, $width, $height);
			return;
		}
		if($action !== 'down') return;

		$this->dragStart = [$x,$y];
		if($button === 1 && $hit && ($hit['type'] ?? '') === 'action')
		{
			$this->pressed = (string)$hit['name'];
			$this->action((string)$hit['name']);
			return;
		}
		if($button === 1 && $hit && ($hit['type'] ?? '') === 'spin')
		{
			if($this->canAdjustShot()) {$this->drag = 'spin'; $this->pressed = 'spin'; $this->setSpin($x, $y, $hit);}
			return;
		}
		if($button === 1 && $hit && ($hit['type'] ?? '') === 'power')
		{
			if($this->canAdjustShot())
			{
				$this->drag = 'power';
				$this->pressed = 'power';
				$this->pull = 0.0;
				$this->pullPrevious = $this->power;
				// SDL stops reporting motion at the canvas edge, so a full pull must fit below the press point.
				$this->pullTravel = max(1.0, min((float)$hit['travel'], $height - $y - (float)($hit['reserve'] ?? 0)));
			}
			return;
		}
		if($hit && ($hit['type'] ?? '') === 'panel') return;

		$point = $this->camera->tablePoint($x / max(1, $width), $y / max(1, $height), $width, $height);
		[$limitX,$limitY] = Simulation::placementLimits();
		$onCloth = $point !== null && abs($point[0]) <= $limitX + .05 && abs($point[1]) <= $limitY + .05;

		if($button === 1 && $placing && $onCloth)
		{
			$this->previewPlacement($x, $y, $width, $height);
			$this->placeCue();
			return;
		}
		if($button === 1 && $onCloth && $this->canAdjustShot())
		{
			$this->drag = 'aim';
			$this->dragAngle = null;
			$this->follow = false;
			$this->aimAt($x, $y, $width, $height, $fine);
			return;
		}

		// Right or middle drag, or any drag that starts off the cloth, orbits the camera.
		$this->drag = 'orbit';
		$this->follow = false;
		$this->camera->hold();
	}

	private function previewPlacement(float $x, float $y, int $width, int $height): void
	{
		$point = $this->camera->tablePoint($x / max(1, $width), $y / max(1, $height), $width, $height);
		if($point === null) return;
		$this->placement = $point;
		$this->clampPlacement();
	}

	/** Point the cue from the cue ball toward the cloth under the pointer, or rotate finely with Shift held. */
	private function aimAt(float $x, float $y, int $width, int $height, bool $fine): void
	{
		if(!$this->canAdjustShot()) return;
		$point = $this->camera->tablePoint($x / max(1, $width), $y / max(1, $height), $width, $height);
		$cue = $this->simulation->cue();
		if($point === null || hypot($point[0] - $cue->x, $point[1] - $cue->y) < .02) return;

		$angle = atan2($point[1] - $cue->y, $point[0] - $cue->x);
		if($fine && $this->dragAngle !== null) $this->aim = self::wrapAngle($this->aim + self::wrapAngle($angle - $this->dragAngle) * .12);
		elseif(!$fine) $this->aim = self::wrapAngle($angle);
		$this->dragAngle = $angle;
	}

	private function setSpin(float $x, float $y, ?array $region): void
	{
		if($region === null || !isset($region['center'],$region['radius']) || !$this->canAdjustShot()) return;
		[$centerX,$centerY] = $region['center'];
		$radius = max(1, (float)$region['radius']);
		$side = ($x - $centerX) / $radius;
		$top = ($centerY - $y) / $radius;
		self::clampSpin($top, $side);
		$this->top = $top;
		$this->side = $side;
	}

	/** Drawing down from the power meter pulls the cue back; the drawn distance becomes the shot power. */
	private function updatePull(float $y): void
	{
		if($this->pull === null) return;
		$this->pull = max(0, min(1, ($y - $this->dragStart[1]) / $this->pullTravel));
		$this->power = max(.05, $this->pull);
	}

	private function releasePull(): void
	{
		$pull = $this->pull;
		$this->pull = null;
		if($pull === null) return;
		if($pull < .05)
		{
			$this->power = $this->pullPrevious;
			return;
		}
		$this->power = max(.05, min(1, $pull));
		$this->action('primary');
	}

	/** Cue pull-back distance shown while the player draws from the power meter. */
	public function cueDraw(): float
	{
		return $this->pull === null ? 0.0 : .02 + .17 * $this->pull;
	}

	private static function hit(array $regions, float $x, float $y): ?array
	{
		foreach($regions as $region)
		{
			if(!isset($region['rect']) || count($region['rect']) !== 4) continue;
			[$left,$top,$width,$height] = $region['rect'];
			if($x >= $left && $x < $left + $width && $y >= $top && $y < $top + $height) return $region;
		}
		return null;
	}

	/**
	 * Advance cue animation and deterministic physics by one fixed interval.
	 *
	 * Contact is emitted on the exact animation update where the cue tip reaches the ball, after which the method
	 * preserves the simulation's ordinary stepping and phase-transition rules through the follow-through.
	 *
	 * @param float $seconds Fixed simulation interval in seconds.
	 */
	public function step(float $seconds): void
	{
		if(!$this->started) return;
		// A room hash describes an action boundary. Keep that exact state frozen
		// while the initial agreement or the next sequenced action is in flight;
		// even an idle physics tick may renormalize a quaternion by a few ulps.
		if($this->match && $this->activeSequence === null)
		{
			$this->phaseTransition();
			return;
		}
		if($this->match && $this->activeSequence !== null && $this->activeAction === null)
		{
			$this->phaseTransition();
			return;
		}
		if($this->stroke === null && !$this->simulation->moving())
		{
			$this->phaseTransition();
			return;
		}

		if($this->stroke !== null)
		{
			$this->stroke['previousTime'] = $this->stroke['time'];
			$this->stroke['time'] = min(self::STROKE_END, $this->stroke['time'] + $seconds);
		}

		// Advance the old physics state before contact. On the contact update this leaves the
		// interpolated ball at rest under the tip; the following update starts its motion.
		$this->simulation->step($seconds);
		$events = $this->simulation->consumeEvents();
		array_push($this->renderEvents, ...$events);
		$this->match?->record($events);
		if($this->stroke !== null)
		{
			if(!$this->stroke['contacted'] && $this->stroke['time'] + 1e-12 >= self::STROKE_CONTACT)
			{
				$this->stroke['contacted'] = $this->simulation->shoot(
					$this->stroke['aim'], $this->stroke['power'], $this->stroke['top'], $this->stroke['side']
				);
				if(!$this->stroke['contacted']) $this->stroke = null;
			}
		}
		if($this->stroke !== null && $this->stroke['previousTime'] >= self::STROKE_END) $this->stroke = null;
		if($this->match && $this->activeAction === 'shot' && $this->stroke === null
			&& !$this->simulation->moving() && $this->simulation->phase !== 'moving')
		$this->settleOnline($this->match->settleShot($this->simulation));
		$this->phaseTransition();
	}

	public function stroking(): bool { return $this->stroke !== null; }

	/**
	 * Return the fixed-step interpolated render pose for the cue stroke.
	 *
	 * The pose follows a power-scaled backswing, accelerated contact, and eased follow-through while retaining the
	 * shot controls and cue-ball origin captured before physics begins moving the ball.
	 *
	 * @param float $alpha Interpolation fraction between fixed updates.
	 */
	public function stroke(float $alpha = 1): ?array
	{
		if($this->stroke === null) return null;
		$alpha = max(0, min(1, is_finite($alpha) ? $alpha : 1));
		$time = $this->stroke['previousTime']
			+ ($this->stroke['time'] - $this->stroke['previousTime']) * $alpha;
		$power = $this->stroke['power'];
		$pullback = .055 + .14 * $power;

		if($time <= self::STROKE_BACKSWING)
		{
			$amount = $time / self::STROKE_BACKSWING;
			$amount = $amount * $amount * (3 - 2 * $amount);
			$offset = $pullback * $amount;
		}
		elseif($time < self::STROKE_CONTACT)
		{
			$amount = ($time - self::STROKE_BACKSWING) / (self::STROKE_CONTACT - self::STROKE_BACKSWING);
			$offset = $pullback + (-.008 - $pullback) * $amount ** 2;
		}
		else
		{
			$amount = min(1, ($time - self::STROKE_CONTACT) / (self::STROKE_END - self::STROKE_CONTACT));
			$amount = 1 - (1 - $amount) ** 3;
			$followThrough = .014 + .03 * $power;
			$offset = -.008 + (-$followThrough + .008) * $amount;
		}

		return [
			'time' => $time, 'offset' => $offset, 'contacted' => $this->stroke['contacted']
			, 'aim' => $this->stroke['aim'], 'power' => $power
			, 'x' => $this->stroke['x'], 'y' => $this->stroke['y']
		];
	}

	private function phaseTransition(): void
	{
		$phase = $this->simulation->phase;
		if($phase === 'placing' && $this->previousPhase !== 'placing') $this->placement = $this->clearSpot(-.65, 0.0);
		// Each new shot starts from a view behind the cue unless the player is holding the camera.
		if($this->previousPhase === 'moving' && $phase !== 'moving') $this->follow = true;
		$this->previousPhase = $phase;
	}

	/**
	 * Find the nearest legal placement to a preferred spot, spiralling outward so ball in hand starts valid.
	 *
	 * @return array{float,float}
	 */
	private function clearSpot(float $x, float $y): array
	{
		if($this->simulation->placementProblem($x, $y) === null) return [$x,$y];

		for($ring = 1; $ring <= 24; $ring++) for($step = 0; $step < 12; $step++)
		{
			$angle = $step / 12 * M_PI * 2;
			$candidate = [$x + cos($angle) * $ring * .03,$y + sin($angle) * $ring * .03];
			if($this->simulation->placementProblem(...$candidate) === null) return $candidate;
		}

		return [$x,$y];
	}

	/**
	 * Return the simulation-backed cue-ball centre path for the aiming guide.
	 *
	 * A digest of deterministic state and shot controls keeps the relatively expensive predictive simulation
	 * cached until the table or controls actually change, and suppresses it during movement or cue animation.
	 */
	public function guide(): array
	{
		return $this->shotPreview()['path'];
	}

	/**
	 * Return the cached guide path and its first object-ball contact for the ghost-ball preview.
	 *
	 * @return array{path:list<array{float,float}>,contact:array<string,mixed>|null}
	 */
	public function shotPreview(): array
	{
		$key = $this->simulation->phase === 'aim' && $this->stroke === null
			? $this->simulation->hash() . ':' . sprintf('%.9f:%.6f:%.6f:%.6f', $this->aim, $this->power, $this->top, $this->side)
			: null;
		if($key !== $this->guideKey)
		{
			$this->guideKey = $key;
			$this->guide = $key === null ? ['path' => [],'contact' => null]
				: $this->simulation->projectShot($this->aim, $this->power, $this->top, $this->side);
		}

		return $this->guide;
	}

	/**
	 * Build finite browser-facing state for accessibility, sound, controls, and diagnostics.
	 *
	 * The payload combines interpolated simulation data with title, camera, cue, placement, guide, and event state
	 * while leaving authoritative gameplay and rendering in PHP.
	 *
	 * @param float $alpha Interpolation fraction for browser-facing render state.
	 */
	public function status(float $alpha): array
	{
		$valid = $this->simulation->phase === 'placing' && $this->simulation->canPlaceCue(...$this->placement);

		return $this->simulation->state($alpha) + [
			'started' => $this->started
			, 'aim' => $this->aim
			, 'power' => $this->power
			, 'top' => $this->top
			, 'side' => $this->side
			, 'guide' => $this->guide()
			, 'events' => $this->consumeRenderEvents()
			, 'placement' => ['position' => $this->placement,'valid' => $valid]
			, 'camera' => $this->camera->state()
			, 'stroke' => $this->stroke($alpha)
			, 'muted' => $this->muted
			, 'status' => $this->statusText()
			, 'mode' => $this->mode
			, 'overlay' => $this->overlay
			, 'ui' => [
				'hover' => $this->hover,'pressed' => $this->pressed,'menuSelection' => $this->menuSelection
				, 'hudHidden' => $this->hudHidden,'theme' => $this->inputTheme,'drag' => $this->drag
				, 'toast' => $this->toast()
			]
			, 'titleSelection' => $this->titleSelection
			, 'onlineRequested' => $this->onlineRequested
			, 'network' => [
				'outbox' => $this->networkOutbox
				, 'role' => $this->mode
				, 'localPlayer' => $this->localPlayer
					, 'actor' => $this->match?->actor
					, 'groups' => $this->match?->groups
					, 'outcome' => $this->match?->outcome
					, 'confirmed' => $this->roomConfirmed
					, 'problem' => $this->networkProblem
					, 'scoreboard' => $this->onlineScoreboard()
				]
		];
	}

	/** Drain browser-facing physics events without changing authoritative match state. */
	private function consumeRenderEvents(): array
	{
		$events = $this->renderEvents;
		$this->renderEvents = [];
		return $events;
	}

	public function statusText(): string
	{
		if(!$this->started) return $this->titleSelection === 0 ? 'Free Play' : 'Online Play';
		if($this->match && $this->match->outcome)
		return ($this->match->outcome['winner'] ?? '') === $this->localPlayer ? 'You win' : 'Game complete';
		if($this->match && $this->networkProblem !== null) return 'Online: ' . $this->networkProblem;
		if($this->match && $this->localPlayer === null) return 'Spectating ' . self::playerName($this->match->actor);
		if($this->match && $this->match->actor !== $this->localPlayer) return 'Waiting for ' . self::playerName($this->match->actor);
		if($this->match && (!$this->roomConfirmed || $this->actionRequested || $this->activeSequence !== null))
		return 'Synchronizing turn';

		$contact = $this->simulation->contactWarning($this->aim);
		if($this->stroke !== null) return $this->stroke['contacted'] ? 'Follow-through' : 'Cue stroke';
		if($this->simulation->phase === 'placing') return $this->simulation->canPlaceCue(...$this->placement)
			? 'Scratch - click or press A to place the cue ball'
			: 'Scratch - choose a clear point on the cloth';
		if($this->simulation->moving()) return 'Balls in motion';
		if($contact !== null) return "Cue nearly frozen to ball $contact - impact will be immediate";
		return 'Ready - ' . $this->simulation->shots . ' shot' . ($this->simulation->shots === 1 ? '' : 's');
	}

	/**
	 * Ensure the browser boundary can never reach json_encode with Inf or NaN.
	 *
	 * @param array<mixed> $value Nested payload to inspect for non-finite numbers.
	 */
	public static function finite(array $value): bool
	{
		foreach($value as $entry)
		{
			if(is_float($entry) && !is_finite($entry)) return false;
			if(is_array($entry) && !self::finite($entry)) return false;
		}

		return true;
	}
}
