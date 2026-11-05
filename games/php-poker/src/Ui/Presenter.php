<?php
declare(strict_types=1);
namespace PhpPoker\Ui;

use PhpPoker\Render\Text;
use PhpPoker\Render\Theme;
use PhpPoker\Render\UiCanvas;
use PhpPoker\Render\UiRenderer;

/**
 * The table's presentation: turns the session view and raw input into intents and frames.
 *
 * The presenter never touches the rules. It holds only presentation state: the selected discards, the raise-to
 * amount, the local screens that the session does not know (table setup, help, settings, history), the visual
 * mode, and the input lock. It sends at most one game intent per view revision and ignores further game input
 * until a view with a new revision or a non-empty error arrives.
 */
final class Presenter
{
	private const KEY_CARDS = ['1' => 0, '2' => 1, '3' => 2, '4' => 3, '5' => 4];

	/** @var array<string,mixed> */
	/** Revision and text of the error currently shown, so it can be retired when the table moves on. */
	private ?int $errorRevision = null;
	private string $errorText = '';
	private array $view = ['screen' => 'menu', 'phase' => 'ready', 'players' => [], 'legalActions' => [], 'revision' => 0, 'generation' => 0, 'error' => '', 'canContinue' => false];
	/** @var array<string,mixed> */
	private array $textures;
	private Text $text;
	private ?UiRenderer $renderer = null;
	private ?UiCanvas $frame = null;
	private bool $dirty = true;
	private bool $closed = false;
	private string $mode = Theme::CLASSIC;
	/** Local screen when the session is on its menu: menu, setup, help or settings. */
	private string $local = 'menu';
	/** @var list<int> Card ids selected for discard; presentation only until Draw is sent. */
	private array $discards = [];
	private int $amount = 0;
	private int $setupPlayers = 4;
	private string $setupVariant = 'jackpots';
	private bool $historyOpen = false;
	/** View revision a game intent was sent for; input is locked until the view moves past it. */
	private ?int $lockedRevision = null;
	private ?array $cardAtlas = null;
	private bool $cardArtSent = false;
	/** @var array{float,float}|null Last pointer position in device pixels. */
	private ?array $pointer = null;
	private int $width = 1280;
	private int $height = 720;
	private float $dpr = 1.0;
	private bool $receivedView = false;
	private array $chips = [];
	private ?Motion $motion = null;
	private float $winnerElapsed = 4;
	private bool $winnerVisible = true;
	private string $networkWaitKey = '';
	private float $networkWaitElapsed = 0;
	private int $networkWaitDots = 0;
	/** Latest input family, used to expose controller focus and swap the on-canvas help. */
	private string $inputTheme = 'keyboard';
	/** Stable controller target ID. The rectangle is always resolved from current layout state. */
	private ?string $controllerFocus = null;
	/** Held controller actions and their next navigation repeat times. */
	private array $controllerHeld = [], $controllerNext = [];

	/**
	 * @param string $root Absolute root directory of the Poker package.
	 */
	public function __construct(private string $root)
	{
		$this->textures = json_decode((string)file_get_contents($root . '/assets/generated/textures.json'), true, flags: JSON_THROW_ON_ERROR);
		$this->text = Text::load($root, $this->textures['uiFont']);
		if(isset($this->textures['uiFont']) && !isset($this->text->faces()['dos'])) $this->mode = Theme::MODERN;
	}

	/**
	 * Accept a new authorized view from the session.
	 *
	 * @param array<string,mixed> $view `Session::view()`.
	 */
	public function update(array $view): void
	{
		$previous = $this->view;
		$this->view = $view;
		if(($view['phase'] ?? '') === 'showdown' && ($previous['phase'] ?? '') !== 'showdown' && $this->receivedView)
		{ $this->winnerElapsed = 0; $this->winnerVisible = true; }
		elseif(($view['phase'] ?? '') !== 'showdown') { $this->winnerElapsed = 4; $this->winnerVisible = true; }
		if(!$this->receivedView)
		{
			$this->chips = Chips::fromView($view);
			$this->receivedView = true;
		}
		elseif(($view['revision'] ?? 0) !== ($previous['revision'] ?? 0) || ($view['generation'] ?? 0) !== ($previous['generation'] ?? 0))
		{
			$change = Chips::transition($previous, $view, $this->chips);
			$this->chips = $change['after'];
			$motion = new Motion($previous, $view, $change, $this->discards);
			$this->motion = $motion->active() ? $motion : null;
		}
		$revision = (int)($view['revision'] ?? 0);
		if($revision !== (int)($previous['revision'] ?? -1) || ($view['generation'] ?? 0) !== ($previous['generation'] ?? 0))
		{
			$this->discards = [];
			$this->lockedRevision = null;
			$this->amount = (int)($view['legalActions']['minBet'] ?? 0);
		}
		// An online action is first shown with its controls suppressed, then confirmed at the same revision. Clamp
		// when betting becomes visible as well as when the revision changes, so its spinbox never exposes stale zero.
		if(!empty($view['legalActions']['bet']))
		$this->amount = max((int)$view['legalActions']['minBet'], min((int)$view['legalActions']['maxBet'], $this->amount));
		// A rejected action's message belongs to the revision it was rejected on: once the table has moved on, drop it.
		$error = (string)($view['error'] ?? '');
		if($error === '') $this->errorRevision = null;
		elseif($this->errorRevision === null || $error !== $this->errorText) [$this->errorRevision, $this->errorText] = [$revision, $error];
		elseif($revision !== $this->errorRevision) $this->view['error'] = '';
		if(($this->view['error'] ?? '') !== '') $this->lockedRevision = null;
		if(($view['screen'] ?? 'menu') !== 'menu') $this->local = (string)$view['screen'];
		elseif(($previous['screen'] ?? 'menu') !== 'menu' || !in_array($this->local, ['setup','online'], true)) $this->local = 'menu';
		if(($view['phase'] ?? '') !== 'showdown' && ($view['phase'] ?? '') !== 'redeal') $this->historyOpen = false;
		$this->syncNetworkWait();
		$this->dirty = true;
	}

	/**
	 * Turn one frame of input into session intents.
	 *
	 * @param list<array{key:string}> $controls Key presses since the last frame, lowercase key names.
	 * @param array{x:float,y:float,down:bool,clicks:list<array{x:float,y:float}>}|null $pointer Pointer state in device pixels.
	 * @param float $elapsed Seconds since the last frame.
	 * @param array<string,bool|float> $controller Shared SDL UI buttons: directions, confirm, back, pause and shoulders.
	 * @param string $inputTheme `keyboard`, `generic`, `xbox`, or `playstation`.
	 * @return list<array<string,mixed>> Intents for `Session::command()`, in order.
	 */
	public function input(array $controls, ?array $pointer, float $elapsed, array $controller = [], string $inputTheme = 'keyboard'): array
	{
		$intents = [];
		if($inputTheme !== $this->inputTheme)
		{
			$this->inputTheme = $inputTheme;
			$this->dirty = true;
		}
		if($inputTheme === 'keyboard') $controller = [];
		array_push($intents, ...$this->controller($controller, $elapsed));
		if($pointer && isset($pointer['x'], $pointer['y']))
		{
			$position = [(float)$pointer['x'], (float)$pointer['y']];
			if($this->pointer === null || abs($this->pointer[0] - $position[0]) > .5 || abs($this->pointer[1] - $position[1]) > .5) $this->dirty = true;
			$this->pointer = $position;
			foreach($pointer['clicks'] ?? [] as $click) if(isset($click['x'], $click['y']))
			array_push($intents, ...$this->click((float)$click['x'], (float)$click['y']));
		}
		foreach($controls as $control) if(isset($control['key'])) array_push($intents, ...$this->key(strtolower((string)$control['key'])));

		return $intents;
	}

	/**
	 * Turn shared SDL controller state into focus movement and activations.
	 *
	 * Directions repeat after a deliberate hold; activation, Back, Start and shoulders only fire on a new press.
	 * @param array<string,bool|float> $buttons
	 * @return list<array<string,mixed>>
	 */
	private function controller(array $buttons, float $elapsed): array
	{
		$elapsed = is_finite($elapsed) ? max(0, $elapsed) : 0;
		$names = ['left','right','up','down','confirm','back','pause','pageUp','pageDown'];
		$pulses = [];
		foreach($names as $name)
		{
			$down = (float)($buttons[$name] ?? 0) >= .5;
			if(!$down)
			{
				unset($this->controllerHeld[$name], $this->controllerNext[$name]);
				$pulses[$name] = 0;
				continue;
			}
			if(!isset($this->controllerHeld[$name]))
			{
				$this->controllerHeld[$name] = 0.0;
				$this->controllerNext[$name] = .5;
				$pulses[$name] = 1;
				continue;
			}
			$this->controllerHeld[$name] += $elapsed;
			$pulses[$name] = 0;
			if(!in_array($name, ['left','right','up','down'], true)) continue;
			while($this->controllerHeld[$name] + 1e-9 >= $this->controllerNext[$name])
			{
				$pulses[$name]++;
				$this->controllerNext[$name] += $this->controllerNext[$name] < 1.5 - 1e-9 ? .25 : .05;
			}
		}

		if($this->inputTheme === 'keyboard')
		{
			$this->controllerHeld = $this->controllerNext = [];
			return [];
		}

		$targets = $this->controllerTargets();
		$this->ensureControllerFocus($targets);
		if($pulses['pause']) return $this->controllerPause();
		if($pulses['back']) return $this->controllerBack();
		if($pulses['pageUp']) { $this->step(-1); $this->dirty = true; }
		if($pulses['pageDown']) { $this->step(1); $this->dirty = true; }
		foreach(['left','right','up','down'] as $direction)
		for($count = $pulses[$direction]; $count > 0; $count--) $this->moveControllerFocus($direction, $targets);
		if($pulses['confirm'])
		{
			$this->ensureControllerFocus($targets);
			foreach($targets as $target) if($target['id'] === $this->controllerFocus) return $this->activateTarget($target);
		}
		return [];
	}

	/** @return list<array<string,mixed>> */
	private function click(float $x, float $y): array
	{
		$point = $this->layout()->design($x, $y);
		$this->dirty = true;
		foreach($this->clickTargets() as $target) if(Layout::hit($point, $target['rect']))
		return $this->activateTarget($target);
		return [];
	}

	/** @return list<array<string,mixed>> */
	private function activateTarget(array $target): array
	{
		$this->dirty = true;
		if(isset($target['key'])) return $this->key($target['key']);
		if(isset($target['menu'])) return $this->activateMenu($target['menu']);
		if(isset($target['card'])) { $this->toggleCard($target['card']); return []; }
		if(isset($target['button'])) return $this->activate($target['button']['id'], $target['button']['intent']);
		return [];
	}

	/** Active targets in hit-test order, shared by clicks and browser cursor feedback. */
	private function clickTargets(): array
	{
		$layout = $this->layout();
		$targets = [];
		$addKey = static function(array $rect, string $key, ?string $id = null) use (&$targets): void {
			$target = ['rect' => $rect, 'key' => $key];
			if($id !== null) $target['id'] = $id;
			$targets[] = $target;
		};
		// These informational screens already dismiss on any click; controls take priority over dismissal.
		$wholeCanvas = [-$layout->left / $layout->scale, -$layout->top / $layout->scale, $this->width / $layout->scale, $this->height / $layout->scale];
		if(($this->view['screen'] ?? 'menu') === 'menu')
		{
			if($this->local === 'setup')
			{
				$setup = $layout->setup($this->setupPlayers, $this->setupVariant);
				if($this->setupPlayers > 2) $addKey($setup['less'], 'arrowleft', 'setup:less');
				if($this->setupPlayers < 8) $addKey($setup['more'], 'arrowright', 'setup:more');
				$addKey($setup['variant'], 'v', 'setup:variant');
				$addKey($setup['back'], 'escape', 'setup:back');
				$addKey($setup['start'], 'enter', 'setup:start');
			}
			elseif($this->local !== 'menu') $addKey($wholeCanvas, 'escape');
			else foreach($layout->menu(!empty($this->view['canContinue'])) as $item)
			$targets[] = ['id' => 'menu:' . $item['id'], 'rect' => $item['rect'], 'menu' => $item];
			return $targets;
		}

		if(in_array($this->view['screen'] ?? '', ['help','settings'], true))
		{
			if(($this->view['screen'] ?? '') === 'settings')
			{
				$toggle = $layout->setup(0, '');
				$addKey($toggle['pace'], 'p', 'settings:pace');
				$addKey($toggle['variant'], 'm', 'settings:look');
				$addKey($toggle['back'], 'escape', 'settings:back');
			}
			else $addKey($layout->information()['back'], 'escape', 'help:back');
			$addKey($wholeCanvas, 'escape');
			return $targets;
		}

		if($this->historyOpen)
		{
			$addKey($layout->history()['back'], 'enter', 'history:back');
			$addKey($wholeCanvas, 'escape');
			return $targets;
		}

		if(!empty($this->view['legalActions']['draw']) && !$this->locked())
		{
			$cards = $this->view['players'][0]['cards'] ?? [];
			$dialog = $layout->discardDialog($cards, $this->discards);
			foreach($dialog['cards'] as $index => $rect) if(isset($cards[$index]))
			$targets[] = ['id' => 'card:' . $index, 'rect' => $rect, 'card' => $index];
		}

		if($stepper = $layout->stepper($this->view))
		{
			$addKey($stepper['less'], '-', 'step:less');
			$addKey($stepper['more'], '+', 'step:more');
		}

		foreach($layout->actions($this->view, $this->controlState()) as $button) if(!$this->locked() || $button['intent'] === null)
		$targets[] = ['id' => 'action:' . $button['id'], 'rect' => $button['rect'], 'button' => $button];

		// The menu bar's left entries open the menu; the right-hand title does nothing. The bar is drawn on the
		// canvas's top-left corner, which lies outside the field when the field is centred.
		$addKey([-$layout->left / $layout->scale, -$layout->top / $layout->scale, 260, $layout->menuHeight()], 'escape');
		return $targets;
	}

	/** Targets which can receive visible controller focus; broad click-to-dismiss regions are deliberately omitted. */
	private function controllerTargets(): array
	{
		return array_values(array_filter($this->clickTargets(), static fn(array $target): bool => isset($target['id'])));
	}

	/** @param list<array<string,mixed>> $targets */
	private function ensureControllerFocus(array $targets): void
	{
		foreach($targets as $target) if($target['id'] === $this->controllerFocus) return;
		$preferences = !empty($this->view['legalActions']['draw']) ? ['card:0']
			: ['action:call','action:check','action:pass','action:draw','action:next','action:deal','setup:start','settings:look'];
		$next = null;
		foreach($preferences as $id) foreach($targets as $target) if($target['id'] === $id) { $next = $id; break 2; }
		$next ??= $targets[0]['id'] ?? null;
		if($next !== $this->controllerFocus) { $this->controllerFocus = $next; $this->dirty = true; }
	}

	/** Move focus to the nearest target in one screen-space direction. */
	private function moveControllerFocus(string $direction, array $targets): void
	{
		$this->ensureControllerFocus($targets);
		$current = null;
		foreach($targets as $target) if($target['id'] === $this->controllerFocus) { $current = $target; break; }
		if($current === null) return;
		[$x, $y, $w, $h] = $current['rect'];
		$cx = $x + $w / 2; $cy = $y + $h / 2;
		$best = null; $bestScore = INF;
		foreach($targets as $target)
		{
			if($target['id'] === $this->controllerFocus) continue;
			[$tx, $ty, $tw, $th] = $target['rect'];
			$dx = $tx + $tw / 2 - $cx; $dy = $ty + $th / 2 - $cy;
			[$primary, $cross] = match($direction) {
				'left' => [-$dx, abs($dy)], 'right' => [$dx, abs($dy)], 'up' => [-$dy, abs($dx)], default => [$dy, abs($dx)]
			};
			// Raised discard cards differ slightly in Y. Require meaningful travel on the requested axis so Down
			// reaches the action row instead of slipping sideways to an unraised neighbour.
			if($primary <= max(1e-6, $cross * .25)) continue;
			$score = $primary + 1.75 * $cross + hypot($dx, $dy) * .05;
			if($score < $bestScore) { $bestScore = $score; $best = $target['id']; }
		}
		if($best !== null && $best !== $this->controllerFocus)
		{ $this->controllerFocus = $best; $this->dirty = true; }
	}

	/** @return list<array<string,mixed>> */
	private function controllerBack(): array
	{
		if($this->historyOpen) return $this->key('escape');
		if(($this->view['screen'] ?? 'menu') === 'menu' && $this->local === 'menu') return [];
		return $this->key('escape');
	}

	/** @return list<array<string,mixed>> */
	private function controllerPause(): array
	{
		if(($this->view['screen'] ?? 'menu') === 'table') return $this->key('escape');
		if(!empty($this->view['canContinue'])) return [['type' => 'resume']];
		if(($this->view['screen'] ?? 'menu') !== 'menu') return [['type' => 'menu']];
		if($this->local !== 'menu') { $this->local = 'menu'; $this->dirty = true; }
		return [];
	}

	/** @return list<array<string,mixed>> */
	private function key(string $key): array
	{
		$this->dirty = true;
		$screen = $this->view['screen'] ?? 'menu';

		if($screen === 'menu')
		{
			if($this->local === 'setup')
			{
				return match($key) {
					'arrowleft', '-' => (function(): array { $this->setupPlayers = max(2, $this->setupPlayers - 1); return []; })(),
					'arrowright', '+', '=' => (function(): array { $this->setupPlayers = min(8, $this->setupPlayers + 1); return []; })(),
					'arrowup', 'arrowdown', 'v' => (function(): array { $this->setupVariant = $this->setupVariant === 'jackpots' ? 'anything' : 'jackpots'; return []; })(),
					'escape', 'backspace' => (function(): array { $this->local = 'menu'; return []; })(),
					'enter', ' ' => [['type' => 'start', 'players' => $this->setupPlayers, 'variant' => $this->setupVariant]],
					default => [],
				};
			}
			if($this->local !== 'menu') { if(in_array($key, ['escape','backspace','enter',' '], true)) $this->local = 'menu'; return []; }
			$items = $this->layout()->menu(!empty($this->view['canContinue']));
			return match($key) {
				'enter', ' ' => $this->activateMenu($items[0]),
				'c' => !empty($this->view['canContinue']) ? [['type' => 'resume']] : [],
				'o', 'p' => $this->activateMenu($items[0]),
				'h' => [['type' => 'help']],
				'm' => [['type' => 'settings']],
				default => [],
			};
		}

		if(in_array($screen, ['help','settings'], true))
		{
			if($screen === 'settings' && $key === 'p') return [['type' => 'pace', 'value' => ($this->view['pace'] ?? 'normal') === 'fast' ? 'normal' : 'fast']];
			if($screen === 'settings' && in_array($key, ['m','v','enter',' '], true)) { $this->mode = $this->mode === Theme::CLASSIC ? Theme::MODERN : Theme::CLASSIC; return []; }
			if(in_array($key, ['escape','backspace','enter',' ','q'], true)) return [['type' => $this->view['canContinue'] ? 'resume' : 'menu']];
			return [];
		}

		if($key === 'escape') { if($this->historyOpen) { $this->historyOpen = false; return []; } return [['type' => 'menu']]; }
		if($key === 'h') { $this->historyOpen = !$this->historyOpen; return []; }
		if($this->historyOpen) { if(in_array($key, ['enter',' '], true)) $this->historyOpen = false; return []; }

		if(isset(self::KEY_CARDS[$key]) && !empty($this->view['legalActions']['draw']) && !$this->locked())
		{
			$this->toggleCard(self::KEY_CARDS[$key]);
			return [];
		}
		if($key === '-' || $key === 'arrowdown') { $this->step(-1); return []; }
		if($key === '+' || $key === '=' || $key === 'arrowup') { $this->step(1); return []; }

		$legend = match($key) {'f' => 'F', 'c' => 'C', 'b' => 'B', 'd' => 'D', 'enter', ' ' => '↵', 'l' => 'L', default => null};
		if($legend === null) return [];
		foreach($this->layout()->actions($this->view, $this->controlState()) as $button) if($button['key'] === $legend)
		return $this->activate($button['id'], $button['intent']);

		return [];
	}

	/** @return list<array<string,mixed>> */
	private function activateMenu(array $item): array
	{
		if($item['id'] === 'offline') { $this->local = 'setup'; return []; }
		if($item['intent'] === null) { $this->local = 'online'; return []; }
		return [$item['intent']];
	}

	/** @return list<array<string,mixed>> */
	private function activate(string $id, ?array $intent): array
	{
		if($id === 'clear') { $this->discards = []; return []; }
		if($id === 'history') { $this->historyOpen = true; return []; }
		if($intent === null || $this->locked()) return [];
		$this->lockedRevision = (int)($this->view['revision'] ?? 0);
		return [$intent + ['generation' => $this->view['generation'] ?? 0, 'revision' => $this->view['revision'] ?? 0]];
	}

	private function toggleCard(int $index): void
	{
		$cards = $this->view['players'][0]['cards'] ?? [];
		if(!isset($cards[$index]) || $cards[$index] === null) return;
		$id = (int)$cards[$index];
		$position = array_search($id, $this->discards, true);
		if($position === false) $this->discards[] = $id;
		else array_splice($this->discards, (int)$position, 1);
		sort($this->discards);
	}

	private function step(int $direction): void
	{
		$legal = $this->view['legalActions'] ?? [];
		if(empty($legal['bet'])) return;
		$increment = max(1, (int)($legal['minBet'] ?? 1) - (int)($this->view['highest'] ?? 0));
		$this->amount = max((int)$legal['minBet'], min((int)$legal['maxBet'], $this->amount + $direction * max(1, (int)($this->view['profile']['minimum'] ?? $increment))));
	}

	/** Layout of the current canvas; the profile follows its size and pixel ratio. */
	public function layout(): Layout
	{
		$seats = count($this->view['players'] ?? []);
		return Layout::fit($this->width, $this->height, $this->dpr, $seats >= 2 ? $seats : 8);
	}

	/** Update geometry before consuming input, including the first frame after a resize. */
	public function resize(int $width, int $height, float $dpr): void
	{
		if([$width, $height, $dpr] === [$this->width, $this->height, $this->dpr]) return;
		[$this->width, $this->height, $this->dpr] = [$width, $height, $dpr];
		$this->dirty = true;
	}

	/** Whether an intent is outstanding or a visible card/chip transfer has yet to finish. */
	public function locked(): bool
	{
		return $this->animating() || ($this->lockedRevision !== null && $this->lockedRevision === (int)($this->view['revision'] ?? 0) && ($this->view['error'] ?? '') === '');
	}

	/** A visible transition holds game input and bot turns until its chips/cards have arrived. */
	public function animating(): bool { return $this->motion !== null && ($this->view['screen'] ?? '') === 'table'; }

	/** Advance presentation time only while the table is visible. No redraw remains scheduled after completion. */
	public function advance(float $seconds): void
	{
		if(($this->view['screen'] ?? '') !== 'table' || $seconds <= 0) return;
		if($this->motion)
		{
			$this->motion->advance($seconds);
			if(!$this->motion->active()) $this->motion = null;
			$this->dirty = true;
		}
		if($this->winnerElapsed < 4)
		{
			$this->winnerElapsed = min(4, $this->winnerElapsed + $seconds);
			$visible = $this->winnerElapsed >= 4 || (int)floor($this->winnerElapsed / .3) % 2 === 0;
			if($visible !== $this->winnerVisible || $this->winnerElapsed === 4) $this->dirty = true;
			$this->winnerVisible = $visible;
		}
		$this->syncNetworkWait();
		if($this->networkWaitKey !== '')
		{
			$this->networkWaitElapsed += $seconds;
			$dots = (int)floor($this->networkWaitElapsed / .3) % 4;
			if($dots !== $this->networkWaitDots) $this->dirty = true;
			$this->networkWaitDots = $dots;
		}
	}

	/** Reset the visual cadence whenever the table starts or finishes a distinct network wait. */
	private function syncNetworkWait(): void
	{
		$key = $this->networkWaitKey();
		if($key === $this->networkWaitKey) return;
		$this->networkWaitKey = $key;
		$this->networkWaitElapsed = 0;
		$this->networkWaitDots = 0;
		$this->dirty = true;
	}

	/** A presentation-only key for online work or settlement that may take more than one frame. */
	private function networkWaitKey(): string
	{
		if(($this->view['screen'] ?? '') !== 'table' || ($this->view['mode'] ?? 'offline') !== 'online'
			|| ($this->view['error'] ?? '') !== '' || $this->motion) return '';
		$line = $this->guidance();
		if($line === '') return '';
		if(!empty($this->view['waitingForConfirmation']) || ($this->view['phase'] ?? '') === 'ready') return $line;
		return preg_match('/\b(?:waiting|shuffl|verif|private cards?)\b/i', $line) === 1 ? $line : '';
	}

	/** Explain the current step without inviting a draw while betting or waiting for another seat. */
	private function guidance(): string
	{
		if(($this->view['screen'] ?? '') !== 'table') return '';
		if(($this->view['phase'] ?? '') === 'showdown') return '';
		if($this->motion) return match($this->motion->status()['kind']) { 'deal' => 'Dealing cards.', 'draw' => 'Discarding and drawing replacements.', default => 'Moving chips.' };
		if(!empty($this->view['waitingForConfirmation'])) return (string)($this->view['message'] ?? 'Waiting for both players to confirm the action.');
		$phase = $this->view['phase'] ?? '';
		$legal = $this->view['legalActions'] ?? [];
		$human = $this->view['players'][0] ?? [];
		if(in_array($phase, ['betting','draw','final-betting'], true) && !empty($human['folded'])) return 'You folded. Waiting for the other players.';
		if($phase === 'betting') return !empty($legal['openingRequired'])
			? 'Need jacks or better. Pass to stay in.' : 'Betting first; choose discards afterward.';
		if($phase === 'draw' && empty($legal['draw'])) return isset($human['drawCount'])
			? 'Your draw is complete. Waiting for the others.' : 'Waiting for your turn to choose discards.';
		return (string)($this->view['message'] ?? '');
	}

	/** Minimal presentation state needed to derive action labels and intents without recursively resolving focus. */
	private function controlState(): array { return ['discards' => $this->discards, 'amount' => $this->amount]; }

	/** Current controller target with a live geometry rectangle, or null for keyboard/pointer input. */
	private function focusedTarget(): ?array
	{
		if($this->inputTheme === 'keyboard') return null;
		$targets = $this->controllerTargets();
		$this->ensureControllerFocus($targets);
		foreach($targets as $target) if($target['id'] === $this->controllerFocus)
		return ['id' => $target['id'], 'rect' => $target['rect']];
		return null;
	}

	/**
	 * Presentation state that layout and painting depend on.
	 *
	 * @return array<string,mixed> Selection, controls, chip inventories and the current presentation frame.
	 */
	public function state(): array
	{
		return [
			'discards' => $this->discards, 'amount' => $this->amount, 'mode' => $this->mode, 'local' => $this->local
			, 'history' => $this->historyOpen, 'locked' => $this->locked(), 'hint' => $this->guidance()
			, 'setup' => ['players' => $this->setupPlayers, 'variant' => $this->setupVariant], 'pointer' => $this->pointer
			, 'chips' => $this->chips, 'motion' => $this->motion?->frame($this->layout())
			, 'winnerFlash' => ['active' => $this->winnerElapsed < 4, 'visible' => $this->winnerVisible]
			, 'networkWait' => ['active' => $this->networkWaitKey !== '', 'dots' => $this->networkWaitDots]
			, 'inputTheme' => $this->inputTheme, 'controllerFocus' => $this->focusedTarget()
		];
	}

	/**
	 * Authorized presentation status published beside the view for tests and assistive tooling.
	 *
	 * @return array{ui:array<string,mixed>}
	 */
	public function status(): array
	{
		$state = $this->state();
		unset($state['pointer'], $state['motion']);
		$state['animation'] = $this->motion?->status();
		$state['cardArt'] = $this->cardAtlas !== null;
		$layout = $this->layout();
		$state['profile'] = $layout->profile;
		// Normalized canvas rectangles let browser hover feedback use the same targets without copying layout rules.
		$state['hitRegions'] = [];
		foreach($this->clickTargets() as $target)
		{
			[$x, $y, $w, $h] = $layout->rect(...$target['rect']);
			$state['hitRegions'][] = [$x / $this->width, $y / $this->height, $w / $this->width, $h / $this->height];
		}
		return ['ui' => $state];
	}

	/** Whether the next frame must be drawn. */
	public function dirty(): bool
	{
		if($this->pollCardAtlas()) $this->dirty = true;
		return $this->dirty;
	}

	/**
	 * Draw one frame.
	 *
	 * @param int $width Canvas width in device pixels.
	 * @param int $height Canvas height in device pixels.
	 * @param float $dpr Device-pixel ratio of the canvas.
	 */
	public function render(int $width, int $height, float $dpr): void
	{
		if($this->closed) return;
		$this->resize($width, $height, $dpr);
		$this->dirty = false;
		$this->renderer ??= new UiRenderer($this->root, $this->textures);
		$this->pollCardAtlas();
		$this->frame = $this->paint($width, $height);
		$this->renderer->render($this->frame, $width, $height);
	}

	/**
	 * Build the draw list for the current view without a GL context.
	 *
	 * @param int $width Canvas width in device pixels.
	 * @param int $height Canvas height in device pixels.
	 */
	public function paint(int $width, int $height): UiCanvas
	{
		[$this->width, $this->height] = [$width, $height];
		$layout = $this->layout();
		$canvas = new UiCanvas($this->text, $layout->uiScale());
		(new Painter($canvas, $layout, $this->view, $this->state(), $this->cardAtlas))->paint($width, $height);
		return $canvas;
	}

	/** Ask the browser bridge for the latest rasterised card and avatar atlas. */
	private function pollCardAtlas(): bool
	{
		if(!function_exists('vrzno_env')) return false;
		if(!$this->cardArtSent)
		{
			$this->cardArtSent = true;
			$art = $this->textures['cards'];
			$send = vrzno_env('pokerCardArt');
			$icons = [];
			foreach($art['icons'] ?? [] as $key => $icon) $icons[$key] = (string)file_get_contents($this->root . '/' . $icon['file']);
			if($send) $send(json_encode([
				'sheet' => (string)file_get_contents($this->root . '/' . $art['sheet']['file']), 'columns' => $art['sheet']['columns'], 'rows' => $art['sheet']['rows']
				, 'backs' => ['red' => (string)file_get_contents($this->root . '/' . $art['backs']['red']['file']), 'blue' => (string)file_get_contents($this->root . '/' . $art['backs']['blue']['file'])]
				, 'icons' => $icons, 'suitRows' => $art['suitRows'], 'aspect' => $art['aspect']
			], JSON_THROW_ON_ERROR));
		}
		$fetch = vrzno_env('pokerCardAtlas');
		if(!$fetch) return false;
		$json = (string)$fetch();
		if($json === '') return false;
		$atlas = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
		$pixels = base64_decode((string)$atlas['base64'], true);
		if($pixels === false) return false;
		$this->cardAtlas = [
			'width' => (int)$atlas['width'], 'height' => (int)$atlas['height'], 'cell' => $atlas['cell'], 'columns' => (int)$atlas['columns']
			, 'backs' => $atlas['backs'], 'icons' => $atlas['icons'] ?? []
		];
		$this->renderer?->setAtlas($pixels, $this->cardAtlas['width'], $this->cardAtlas['height']);
		return true;
	}

	public function restore(): void
	{
		$this->renderer?->restore();
		$this->dirty = true;
	}

	public function close(): void
	{
		$this->closed = true;
		$this->renderer?->close();
	}
}
