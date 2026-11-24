<?php
declare(strict_types=1);
namespace TerrainSandbox;

use Phonics\Asset\Resource;
use Phonics\Engine;
use Phonics\Render\BitmapFont;
use Phonics\Tiled\Loader;
use Phonics\Ui\Menu;
use Phonics\Ui\MenuInput;
use Phonics\Ui\MenuRenderer;

/**
 * The pause menu: resume, the moves that suit what the player is doing, controls, audio, settings, the Landscape Lab
 * and leaving.
 *
 * Rows act through the same input packets the page sends, so every choice passes Session's checks. Audio levels and
 * the music live in the page, which remembers them, so those rows read and write them through its bridges.
 */
final class SandboxMenu
{
	public const TITLE = 'PAUSED';
	private const SCREENSHOT = 'Screenshot view';
	/** Headings for each mode's controls. */
	private const MODES = ['fly' => 'Free flight', 'car' => 'Car', 'craft' => 'Watercraft', 'jet' => 'F5'];
	public Menu $menu;
	public MenuInput $controls;
	private ?MenuRenderer $renderer = null;
	/** Actions and values chosen in the menu, applied to the session on the next input. */
	private array $pending = [];
	/** Whether the world stays frozen after the menu closes, as it does for the Landscape Lab. */
	private bool $keepPaused = false;
	/** Real seconds the menu has been open, for its animated scrolling. */
	private float $time = 0.;
	/** SDL key names that choose or go back, which never repeat while held. */
	private const SINGLE_PRESS_KEYS = ['Return', 'Keypad Enter', 'Space', 'Y', 'Escape', 'X', 'Left Ctrl', 'Right Ctrl'];
	/** Menu buttons the page's pad sends, in its order. */
	private const PAD = ['up', 'down', 'left', 'right', 'confirm', 'back', 'pause'];
	/** Page pad buttons held when the menu opened, ignored until released, or null until the first update. */
	private ?array $blocked = null;
	/** The menu as last drawn, and how long its scrolling may still be easing. */
	private string $shown = '';
	private float $settle = 0.;
	/** @var callable(string): mixed Reads an audio level or the music track from the page. */
	private $audioGet;
	/** @var callable(string, mixed): bool Sets an audio level or the music track in the page. */
	private $audioSet;
	/** @var callable(string): mixed Asks the page to open the Landscape Lab or leave for the examples. */
	private $page;

	/**
	 * Make a closed menu. Graphics are created on the first draw.
	 * @param callable(string): mixed $audioGet Page bridge that reads master, music, engine, tires, track or tracks.
	 * @param callable(string, mixed): bool $audioSet Page bridge that sets one of those.
	 * @param callable(string): mixed $page Page bridge for lab and quit.
	 */
	public function __construct(mixed $audioGet, mixed $audioSet, mixed $page)
	{
		$this->audioGet = $audioGet;
		$this->audioSet = $audioSet;
		$this->page = $page;
		$this->menu = new Menu([], self::TITLE);
		$this->controls = new MenuInput;
	}

	/**
	 * Open the top page for what the player is doing and freeze the world.
	 * @param Session $session The running sandbox.
	 * @return void Marks the session paused with its menu open.
	 */
	public function open(Session $session): void
	{
		$this->menu->title = self::TITLE;
		$this->menu->setItems($this->items($session));
		// Every pause starts on Resume, whatever was chosen last time.
		$this->menu->select('resume');
		$this->menu->open();
		$this->controls->reset();
		$this->keepPaused = false;
		$this->blocked = null;
		$this->shown = '';
		$session->menu = true;
		$session->paused = true;
		$this->show($session);
	}

	/**
	 * Tell the session which page and row are selected, for the page to announce.
	 * @param Session $session The running sandbox.
	 * @return void Clears the row while the menu is closed.
	 */
	private function show(Session $session): void
	{
		$id = $this->menu->selected;
		$rows = $this->menu->rows();
		$session->menuRow = $this->menu->isOpen && $id !== null
			? ['page' => $this->menu->title, 'id' => $id, 'label' => (string)($rows[$id]['label'] ?? $id)]
			: null;
	}

	/**
	 * Build the top page: Resume first, then moves for the current mode, then pages that are always there.
	 * @param Session $session The running sandbox.
	 * @return array<string, array> Menu rows keyed by stable ID.
	 */
	public function items(Session $session): array
	{
		$items = [
			'resume' => ['label' => 'Resume', 'callback' => fn() => $this->close()],
			'screenshot' => [
				'label' => self::SCREENSHOT,
				'subtext' => 'Hide the interface. Esc, B or a tap returns here.',
				'children' => [],
			],
		];
		$act = fn(string $label, string $action): array => [
			'label' => $label
			, 'callback' => function() use ($action): void {
				$this->pending['actions'][] = $action;
				$this->close();
			}
		];

		if($session->mode !== 'fly' && $session->race->state !== 'idle') $items['race'] = $act('Restart race', 'race-reset');

		if($session->mode === 'fly')
		{
			$items['car'] = $act('Drive car', 'car');
			$items['craft'] = $act('Watercraft', 'craft');
			$items['jet'] = $act('Fly F5', 'jet');
		}
		else $items['fly'] = $act('Return to flight', 'fly');

		if($session->watchingDriver() || $session->canWatchDriver())
			$items['cpu-demo'] = $act($session->watchingDriver() ? 'Stop CPU demo' : 'Watch CPU racer', 'cpu-demo');
		if(!$session->watchingDriver() && in_array($session->mode, ['fly', 'car'], true)) $items['delivery'] = $act('Package run', 'delivery');

		if($session->mode === 'jet')
		{
			$items['balloons'] = $act('Balloon practice', 'balloon-practice');
			$items['reset-balloons'] = $act('Reset balloons', 'balloons-reset');
		}

		$items['controls'] = ['label' => 'Controls', 'text' => $this->controlsPage($session)];
		$items['audio'] = ['label' => 'Audio', 'children' => $this->audioItems()];
		$items['settings'] = ['label' => 'Settings', 'children' => $this->settingsItems($session)];
		$items['lab'] = [
			'label' => 'Landscape Lab'
			, 'callback' => function(): void {
				($this->page)('lab');
				$this->close(true);
			}
		];
		$items['quit'] = [
			'label' => 'Quit to examples'
			, 'children' => [
				// Choosing Quit here confirms it. The page adds Back below, which keeps playing.
				'leave' => [
					'label' => 'Quit'
					, 'subtext' => 'Unsaved landscape edits are lost.'
					, 'callback' => fn() => ($this->page)('quit')
				]
			]
		];

		return $items;
	}

	/**
	 * List the current mode's controls for the device in use, button art beside each action.
	 * @param Session $session The running sandbox.
	 * @return list<string|array> Text page blocks.
	 */
	public function controlsPage(Session $session): array
	{
		$theme = $session->inputTheme;
		$blocks = [['heading' => self::MODES[$session->mode] ?? 'Controls']];

		foreach($session->hint() as $entry)
		{
			// An entry leads with its buttons, each one character or a short gesture, then names the action.
			$words = explode(' ', $entry);
			$buttons = [];

			while(count($words) > 1 && (preg_match('/^\X$/u', $words[0]) || $words[0] === 'Right-drag'))
				$buttons[] = array_shift($words);

			$blocks[] = ['name' => implode(' ', $words), 'value' => implode(' ', $buttons)];
		}

		$blocks[] = ['heading' => 'Anywhere'];
		$blocks[] = match($theme)
		{
			'touch' => ['name' => 'Pause menu', 'value' => 'Menu button'],
			'keyboard' => ['name' => 'Pause menu', 'value' => 'Esc'],
			default => ['name' => 'Pause menu', 'value' => '❾'],
		};

		if($theme === 'keyboard')
		{
			$blocks[] = ['name' => 'Freeze the world', 'value' => 'P'];
			$blocks[] = ['name' => 'Landscape Lab', 'value' => 'H'];
		}

		return $blocks;
	}

	/**
	 * Audio rows: the track, then a level for music, the engine, the tires and everything.
	 * @return array<string, array> Rows reading and writing the page's audio settings.
	 */
	private function audioItems(): array
	{
		$tracks = json_decode((string)($this->audioGet)('tracks'), true) ?: ['Off'];
		$level = fn(string $name, string $label, string $note): array => [
			'label' => $label
			, 'input' => 'number'
			, 'subType' => 'range'
			, 'min' => 0
			, 'max' => 100
			, 'step' => 5
			, 'valueAlign' => 'right'
			, 'subtext' => $note
			, 'get' => fn(): int => (int)($this->audioGet)($name)
			, 'set' => fn(float $value): bool => (bool)($this->audioSet)($name, $value)
		];

		return [
			'track' => [
				'label' => 'Music'
				, 'input' => 'select'
				, 'options' => $tracks
				, 'subtext' => 'A song from the soundtrack, or Off.'
				, 'get' => fn(): string => (string)($this->audioGet)('track')
				, 'set' => fn(string $value): bool => (bool)($this->audioSet)('track', $value)
			]
			, 'music' => $level('music', 'Music level', 'The chosen track.')
			, 'engine' => $level('engine', 'Engine level', 'The car\'s flat twelve.')
			, 'tires' => $level('tires', 'Tire level', 'Squeal and scrub.')
			, 'master' => $level('master', 'Master level', 'Everything together.')
		];
	}

	/**
	 * Settings rows that live in the session: headlights, jet handling, pitch and flight speed.
	 * @param Session $session The running sandbox, read for current values.
	 * @return array<string, array> Rows whose changes reach the session as input values.
	 */
	private function settingsItems(Session $session): array
	{
		$set = function(string $name, mixed $value): bool {
			$this->pending['values'][$name] = $value;
			return true;
		};

		return [
			'headlights' => [
				'label' => 'Car headlights'
				, 'input' => 'select'
				, 'options' => ['Auto', 'On', 'Off']
				, 'subtext' => 'Auto turns them on in low light.'
				, 'get' => fn(): string => ucfirst($session->headlights)
				, 'set' => fn(string $value): bool => $set('headlights', strtolower($value))
			]
			, 'jetControls' => [
				'label' => 'Jet handling'
				, 'input' => 'select'
				, 'options' => ['Novice', 'Expert']
				, 'subtext' => 'Novice banks to turn and levels itself.'
				, 'get' => fn(): string => ucfirst($session->jetControls)
				, 'set' => fn(string $value): bool => $set('jetControls', strtolower($value))
			]
			, 'invertPitch' => [
				'label' => 'Invert pitch'
				, 'input' => 'boolean'
				, 'subtext' => 'Push the stick forward to climb.'
				, 'get' => fn(): bool => $session->invertPitch
				, 'set' => fn(bool $value): bool => $set('invertPitch', $value)
			]
			, 'speed' => [
				'label' => 'Flight speed · kts'
				, 'input' => 'number'
				, 'subType' => 'range'
				, 'min' => 10
				, 'max' => 300
				, 'step' => 5
				, 'valueAlign' => 'right'
				, 'subtext' => 'Free flight with Shift held goes faster.'
				, 'get' => fn(): int => (int)round($session->settings['speed'] / DisplayUnits::KNOT)
				, 'set' => fn(float $value): bool => $set('speed', $value * DisplayUnits::KNOT)
			]
		];
	}

	/**
	 * Close the menu. The next update unfreezes the world unless asked to stay paused.
	 * @param bool $keepPaused Whether the world stays frozen, as when the Landscape Lab opens.
	 * @return void Leaves pending choices for the next input.
	 */
	public function close(bool $keepPaused = false): void
	{
		$this->keepPaused = $keepPaused;
		$this->menu->close();
	}

	/**
	 * Hand over the choices made since the last call.
	 * @return array{actions?: list<string>, values?: array<string, mixed>} An input packet for Session::input().
	 */
	public function takePending(): array
	{
		[$pending, $this->pending] = [$this->pending, []];
		return $pending;
	}

	/**
	 * Feed one frame of menu input and finish closing when the player leaves.
	 * @param Engine $engine Engine whose SDL input carries keys, pads and pointer events.
	 * @param Session $session The running sandbox.
	 * @param float $elapsed Real seconds since the previous frame.
	 * @param array $pad The page's pad as menu buttons, in PAD order. The page reads pads itself, as SDL may not.
	 * @return bool Whether the menu looks different and needs drawing.
	 */
	public function update(Engine $engine, Session $session, float $elapsed, array $pad = []): bool
	{
		if(!$this->menu->isOpen) return false;

		$dt = min(.25, max(0., $elapsed));
		$frame = $engine->input->uiFrame($this->menu->editor !== null, true);
		$this->time += $dt;

		// On a slow frame, letting go of Enter and pressing it again can both happen between two looks at the
		// keyboard. The menu then sees one long hold and never a second press. A fresh key press starts over.
		foreach($frame['events'] as $event)
		if(($event['type'] ?? '') === 'key' && !($event['repeat'] ?? false)
			&& in_array($event['key'] ?? '', self::SINGLE_PRESS_KEYS, true)
		){
			$this->controls->reset();
			break;
		}

		$regions = $this->renderer?->regions ?? [];
		if($this->screenshotView())
		{
			// Ctrl is normally Back. Leave it available for the operating system's screenshot shortcuts.
			$frame['buttons']['back'] = $engine->input->gamepads->button(SDL_CONTROLLER_BUTTON_B)
				|| in_array('Escape', array_column($frame['events'], 'key'), true);
			// The entire canvas is the screenshot page's Back row, including on touchscreens.
			$regions = [['id' => '@back', 'rect' => [0, 0, INF, INF]]];
		}
		$this->controls->update($this->menu, $this->withPad($frame['buttons'], $pad), $dt, $frame['events'], $regions);

		if($this->finish($session))
		{
			// The button that closed the menu must not also act in the world.
			$engine->input->suppressHeldUi();

			return true;
		}

		$shown = json_encode([$this->menu->selected, $this->menu->depth(), $this->menu->title, $this->menu->text !== null,
			array_map(static fn(array $row): mixed => $row['value'] ?? null, $this->menu->rows())], JSON_PARTIAL_OUTPUT_ON_ERROR);

		// Scrolling eases for a moment after the selection moves.
		if($shown !== $this->shown) $this->settle = .5;
		else $this->settle = max(0., $this->settle - $dt);

		$this->shown = (string)$shown;
		$this->show($session);

		return $this->settle > 0.;
	}

	/**
	 * Add the page's pad to SDL's menu buttons. A button held when the menu opened counts once it is let go.
	 * @param array $buttons SDL's menu buttons for this frame.
	 * @param array $pad The page's pad as menu buttons, in PAD order.
	 * @return array The buttons with the pad's added.
	 */
	public function withPad(array $buttons, array $pad): array
	{
		$held = [];

		foreach(self::PAD as $i => $name)
		{
			$value = is_numeric($pad[$i] ?? null) ? max(0., min(1., (float)$pad[$i])) : 0.;

			if($value >= .5) $held[$name] = true;
		}

		$this->blocked ??= $held;
		$this->blocked = array_intersect_key($this->blocked, $held);

		foreach(array_diff_key($held, $this->blocked) as $name => $_) $buttons[$name] = max($buttons[$name] ?? 0, 1);

		return $buttons;
	}

	/**
	 * Hand the world back once a row or Back has closed the menu.
	 * @param Session $session The running sandbox.
	 * @return bool Whether the menu had just closed.
	 */
	public function finish(Session $session): bool
	{
		if($this->menu->isOpen || !$session->menu) return false;

		$session->menu = false;
		$session->paused = $this->keepPaused;
		$this->keepPaused = false;
		$this->show($session);

		return true;
	}

	/** The screenshot page keeps menu input and the simulation pause while showing only the scene. */
	public function screenshotView(): bool { return $this->menu->isOpen && $this->menu->title === self::SCREENSHOT; }

	/**
	 * Draw the menu over the finished frame.
	 * @param int $width Drawing-buffer width in pixels.
	 * @param int $height Drawing-buffer height in pixels.
	 * @param string $theme The session's input theme, which picks the button art.
	 * @return void Draws nothing while the menu is closed.
	 */
	public function render(int $width, int $height, string $theme): void
	{
		if(!$this->menu->isOpen || $this->screenshotView()) return;

		if($this->renderer === null)
		{
			$this->renderer = new MenuRenderer(new BitmapFont(
				new Loader
				, Resource::framework('resources/font.tsj')
				, [Resource::framework('resources/buttons.tsj')]
			));
			// Wide enough for the renderer's hint line, Move through Hold Reset, at its largest scale.
			$this->renderer->style['width'] = 820;
		}

		$this->renderer->font->theme = $theme === 'touch' ? 'keyboard' : $theme;
		glDisable(GL_DEPTH_TEST);
		glEnable(GL_BLEND);
		glBlendFunc(GL_SRC_ALPHA, GL_ONE_MINUS_SRC_ALPHA);
		$this->renderer->render($this->menu, $width, $height, $this->time);
	}

	/**
	 * Rebuild the menu's graphics after the WebGL context comes back.
	 * @return void Keeps the menu's pages and selection.
	 */
	public function restore(): void { $this->renderer?->restore(); }

	/**
	 * Release the menu's graphics.
	 * @return void A later draw creates them again.
	 */
	public function release(): void
	{
		$this->renderer?->close();
		$this->renderer = null;
	}
}
