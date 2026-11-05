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
		// A rejected action's message belongs to the revision it was rejected on: once the table has moved on, drop it.
		$error = (string)($view['error'] ?? '');
		if($error === '') $this->errorRevision = null;
		elseif($this->errorRevision === null || $error !== $this->errorText) [$this->errorRevision, $this->errorText] = [$revision, $error];
		elseif($revision !== $this->errorRevision) $this->view['error'] = '';
		if(($this->view['error'] ?? '') !== '') $this->lockedRevision = null;
		if(($view['screen'] ?? 'menu') !== 'menu') $this->local = (string)$view['screen'];
		elseif(($previous['screen'] ?? 'menu') !== 'menu' || !in_array($this->local, ['setup','online'], true)) $this->local = 'menu';
		if(($view['phase'] ?? '') !== 'showdown' && ($view['phase'] ?? '') !== 'redeal') $this->historyOpen = false;
		$this->dirty = true;
	}

	/**
	 * Turn one frame of input into session intents.
	 *
	 * @param list<array{key:string}> $controls Key presses since the last frame, lowercase key names.
	 * @param array{x:float,y:float,down:bool,clicks:list<array{x:float,y:float}>}|null $pointer Pointer state in device pixels.
	 * @param float $elapsed Seconds since the last frame.
	 * @return list<array<string,mixed>> Intents for `Session::command()`, in order.
	 */
	public function input(array $controls, ?array $pointer, float $elapsed): array
	{
		$intents = [];
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

	/** @return list<array<string,mixed>> */
	private function click(float $x, float $y): array
	{
		$point = $this->layout()->design($x, $y);
		$this->dirty = true;
		foreach($this->clickTargets() as $target) if(Layout::hit($point, $target['rect']))
		{
			if(isset($target['key'])) return $this->key($target['key']);
			if(isset($target['menu'])) return $this->activateMenu($target['menu']);
			if(isset($target['card'])) { $this->toggleCard($target['card']); return []; }
			return $this->activate($target['button']['id'], $target['button']['intent']);
		}
		return [];
	}

	/** Active targets in hit-test order, shared by clicks and browser cursor feedback. */
	private function clickTargets(): array
	{
		$layout = $this->layout();
		$targets = [];
		$addKey = static function(array $rect, string $key) use (&$targets): void { $targets[] = ['rect' => $rect, 'key' => $key]; };
		// These informational screens already dismiss on any click; controls take priority over dismissal.
		$wholeCanvas = [-$layout->left / $layout->scale, -$layout->top / $layout->scale, $this->width / $layout->scale, $this->height / $layout->scale];
		if(($this->view['screen'] ?? 'menu') === 'menu')
		{
			if($this->local === 'setup')
			{
				$setup = $layout->setup($this->setupPlayers, $this->setupVariant);
				if($this->setupPlayers > 2) $addKey($setup['less'], 'arrowleft');
				if($this->setupPlayers < 8) $addKey($setup['more'], 'arrowright');
				$addKey($setup['variant'], 'v');
				$addKey($setup['back'], 'escape');
				$addKey($setup['start'], 'enter');
			}
			elseif($this->local !== 'menu') $addKey($wholeCanvas, 'escape');
			else foreach($layout->menu(!empty($this->view['canContinue'])) as $item) $targets[] = ['rect' => $item['rect'], 'menu' => $item];
			return $targets;
		}

		if(in_array($this->view['screen'] ?? '', ['help','settings'], true))
		{
			if(($this->view['screen'] ?? '') === 'settings')
			{
				$toggle = $layout->setup(0, '');
				$addKey($toggle['pace'], 'p');
				$addKey($toggle['variant'], 'm');
			}
			$addKey($wholeCanvas, 'escape');
			return $targets;
		}

		if($this->historyOpen) { $addKey($wholeCanvas, 'escape'); return $targets; }

		if(!empty($this->view['legalActions']['draw']) && !$this->locked())
		{
			$cards = $this->view['players'][0]['cards'] ?? [];
			$dialog = $layout->discardDialog($cards, $this->discards);
			foreach($dialog['cards'] as $index => $rect) if(isset($cards[$index])) $targets[] = ['rect' => $rect, 'card' => $index];
		}

		if($stepper = $layout->stepper($this->view))
		{
			$addKey($stepper['less'], '-');
			$addKey($stepper['more'], '+');
		}

		foreach($layout->actions($this->view, $this->state()) as $button) if(!$this->locked() || $button['intent'] === null)
		$targets[] = ['rect' => $button['rect'], 'button' => $button];

		// The menu bar's left entries open the menu; the right-hand title does nothing.
		$addKey([0, 0, 260, Layout::MENU_BAR], 'escape');
		return $targets;
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
		foreach($this->layout()->actions($this->view, $this->state()) as $button) if($button['key'] === $legend)
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
	public function layout(): Layout { return Layout::fit($this->width, $this->height, $this->dpr); }

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
	}

	/** Explain the current step without inviting a draw while betting or waiting for another seat. */
	private function guidance(): string
	{
		if(($this->view['screen'] ?? '') !== 'table') return '';
		if(($this->view['phase'] ?? '') === 'showdown') return '';
		if($this->motion) return match($this->motion->status()['kind']) { 'deal' => 'Dealing cards.', 'draw' => 'Discarding and drawing replacements.', default => 'Moving chips.' };
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
		if($this->cardAtlas === null && $this->pollCardAtlas()) $this->dirty = true;
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
		if($this->cardAtlas === null) $this->pollCardAtlas();
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
		$canvas = new UiCanvas($this->text, $layout->scale);
		(new Painter($canvas, $layout, $this->view, $this->state(), $this->cardAtlas))->paint($width, $height);
		return $canvas;
	}

	/** Ask the browser bridge for the rasterised card atlas; true once it has arrived. */
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
