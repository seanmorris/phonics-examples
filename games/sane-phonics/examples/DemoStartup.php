<?php
declare(strict_types=1);

use Phonics\Engine;
use Phonics\Render\Camera;
use Phonics\Ui\Card;
use Phonics\Ui\CardRenderer;
use Phonics\Ui\CardSequence;
use Phonics\Ui\Menu;

/** Example application flow; the card and menu primitives remain independent of this demo. */
final class DemoStartup
{
	public CardSequence $cards;
	public Menu $menu;
	public string $phase = 'intro';
	public float $time = 0;
	public string $error = '';

	private DemoBackdrop $backdrop;
	private CardRenderer $renderer;
	private Camera $camera;
	private int $generation = -1;
	private bool $held = true;
	private bool $closed = false;

	/**
	 * Attach startup UI to a session, borrowing its pause menu's font and input adapter.
	 *
	 * @param DemoGame $game Persistent game session that will receive the selected level.
	 * @param string $scene Initial level selection, downloaded only after choosing Play.
	 * @return void Installs one input owner and starts the preload and intro sequence.
	 */
	public function __construct(private DemoGame $game, public string $scene)
	{
		$ui = $game->menu->renderer;
		$this->renderer = new CardRenderer($ui->font, $ui->atlas);
		$this->camera = new Camera;
		$this->backdrop = new DemoBackdrop;
		$this->menu = $this->createMenu();
		$this->replay();
		$game->engine->setInputHandler([$this, 'update']);
	}

	/**
	 * Build a main menu using the same live settings and declarative items as the pause menu.
	 *
	 * @return Menu Closed root page with level, movement, settings, credits, and replay actions.
	 */
	private function createMenu(): Menu
	{
		$levels = [];

		foreach([
			'platformer' => 'Platformer'
			, 'platforms' => 'Moving platforms'
			, 'bridges' => 'Bendable bridges & rails'
			, 'light-dash' => 'Light dash'
			, 'grapples' => 'Grapple ropes'
			, 'vehicles' => 'Skateboard course'
			, 'tornado' => 'Tornado flight course'
			, 'egg-walker' => 'EggWalker proving ground'
			, 'water' => 'Water regions'
			, 'water-rail' => 'Underwater rail'
			, 'water-skim-rail' => 'Surface skim rail'
			, 'water-loop' => 'Rising water loop'
			, 'gravity' => 'Gravity switches & springs'
			, 'ceiling-curves' => 'Curved ceiling springs'
			, 'badniks' => 'CrabMeat badniks'
			, 'world' => 'Streaming world'
			, 'loop' => 'Loop-de-loop'
			, 'breakables' => 'Breakable blocks'
			, 'infinite' => 'Infinite map'
			, 'isometric' => 'Isometric map'
		] as $id => $label)
		{
			$levels[$id] = [
				'label' => $label
				, 'input' => 'output'
				, 'get' => fn() => $this->scene === $id ? 'Selected' : ''
				, 'callback' => function(Menu $menu) use ($id): void {
					$this->scene = $id;
					$menu->back();
				}
			];
		}

		$items = [
			'play' => [
				'label' => 'Play'
				, 'callback' => [$this, 'play']
				, 'subtext' => 'Load the selected level and enter the game.'
			]
			, 'resume' => [
				'label' => 'Resume game'
				, 'callback' => [$this, 'resume']
				, 'hidden' => fn() => $this->game->level === null
			]
			, 'level' => [
				'label' => 'Level'
				, 'input' => 'output'
				, 'get' => fn() => $this->scene
				, 'children' => $levels
			]
			, 'movement' => [
				'label' => 'Movement'
				, 'input' => 'select'
				, 'options' => ['runner', 'glider', 'flyer']
				, 'get' => fn() => $this->game->body->profile
				, 'set' => fn($value) => $this->game->selectProfile($value)
			]
			, 'settings' => ['label' => 'Settings', 'children' => $this->game->settings]
			, 'credits' => ['label' => 'Credits', 'callback' => [$this, 'credits']]
			, 'replay' => ['label' => 'Replay intro', 'callback' => [$this, 'replay']]
		];

		return new Menu($items, 'Main menu');
	}

	/**
	 * Restart the intro sequence without replacing PHP, the engine, or downloaded resources.
	 *
	 * @return void Cancels previous cards and pending level work, preserving the active level.
	 */
	public function replay(): void
	{
		$this->game->cancelLoad();
		$this->cards = $this->sequence(false);
		$this->transition('intro');
		$this->cards->start();
	}

	/**
	 * Make the configured intro or title-only sequence with a live backdrop readiness gate.
	 *
	 * @param bool $titleOnly Whether to omit loading, credit, and controls screens.
	 * @return CardSequence Fresh configuration; any previous sequence is cancelled first.
	 */
	private function sequence(bool $titleOnly): CardSequence
	{
		if(isset($this->cards))
		{
			$this->cards->cancel();
		}

		return demoCards(fn(): bool => $this->backdrop->renderer !== null, $titleOnly);
	}

	/**
	 * Return from the main menu to the guarded press-start screen.
	 *
	 * @return void Keeps the background clock and assets continuous across the transition.
	 */
	public function title(): void
	{
		$this->cards = $this->sequence(true);
		$this->transition('intro');
		$this->cards->start();
	}

	/**
	 * Show attribution as a skippable timed card, then return to the main menu.
	 *
	 * @return void Demonstrates composing a separate card sequence at runtime.
	 */
	public function credits(): void
	{
		$this->cards->cancel();
		$text = "Engine: Sean Morris\nFlow inspired by moving-boxes-2\n"
			. 'Font: tigl. Input art: moving-boxes-2';

		$this->cards = new CardSequence([new Card('credits', 'Credits', $text, duration: 6)]);
		$this->transition('intro');
		$this->cards->start();
	}

	/**
	 * Open the root menu, cancelling a staged level while retaining the last playable scene.
	 *
	 * @return void Pauses gameplay and shares settings with the in-game pause menu.
	 */
	public function showMenu(): void
	{
		$this->game->cancelLoad();
		$this->cards->cancel();
		$this->transition('menu');
		$this->menu->open();
	}

	/**
	 * Load the selected scene within the current engine session.
	 *
	 * @return void Shows a readiness-gated preloader until the level commits or fails.
	 */
	public function play(): void
	{
		$this->cards->cancel();
		$this->game->selectScene($this->scene);

		$this->cards = new CardSequence(
			[new Card('level-loading', 'Loading level', $this->scene, duration: null, skippable: false)]
		);

		$this->transition('loading');
		$this->cards->start();
	}

	/**
	 * Return to the retained level without resetting its actors or simulation clock.
	 *
	 * @return void Resumes only when a playable level exists.
	 */
	public function resume(): void
	{
		if($this->game->level === null)
		{
			return;
		}

		$this->cards->cancel();
		$this->transition('playing');
	}

	/**
	 * Change the current input owner, quarantining held buttons and ending text composition.
	 *
	 * @param string $phase Intro, menu, loading, or playing state.
	 * @return void Closes both menus and resets input without altering the engine or its clock.
	 */
	private function transition(string $phase): void
	{
		$this->phase = $phase;
		$this->held = true;
		$this->menu->close();
		$this->game->menu->menu->close();
		$this->game->menu->controls->reset();

		$this->game->engine->input->setTextInput(false);
		$this->game->engine->input->reset();
		$this->game->engine->controls->reset();
		$this->game->engine->userPaused = $phase !== 'playing';
	}

	/**
	 * Route keyboard, controller, pointer, and text input before gameplay simulation.
	 *
	 * @param Engine $engine Engine invoking its single UI input hook.
	 * @param float $elapsed Active seconds since the previous frame; zero after suspension.
	 * @return bool True while startup owns input; delegates pause behavior during gameplay.
	 */
	public function update(Engine $engine, float $elapsed): bool
	{
		$adapter = $this->game->menu;

		if($this->phase === 'playing')
		{
			return $adapter->update($engine, $elapsed);
		}

		$dt = min(.25, $elapsed);
		$this->time += $dt;
		$frame = $engine->input->uiFrame($this->menu->editor !== null, true);

		if($frame['generation'] !== $this->generation || !$frame['focused'])
		{
			$adapter->controls->reset();
			$this->menu->editor?->compose('');
			$this->held = true;
			$this->generation = $frame['generation'];
		}

		$adapter->renderer->font->theme = $adapter->theme === 'auto' ? $frame['theme'] : $adapter->theme;
		$this->prepare();

		if($this->phase === 'menu')
		{
			$buttons = $frame['buttons'];
			$buttons['confirm'] = ($buttons['confirm'] ?? false) || ($buttons['pause'] ?? false);
			$buttons['pause'] = false;

			$adapter->controls->update($this->menu, $buttons, $dt, $frame['events'], $adapter->renderer->regions);

			if($this->phase === 'menu' && !$this->menu->isOpen)
			{
				$this->title();
			}
		}
		else
		{
			$this->updateCards($frame, $dt);
		}

		$engine->input->setTextInput(
			$this->phase === 'menu' && $this->menu->editor !== null && $frame['focused']
			, $adapter->renderer->editorRect
		);

		return true;
	}

	/**
	 * Poll the backdrop, keeping transport errors inside the loading screen.
	 *
	 * @return void Records a retryable error; graphics loss remains owned by Engine recovery.
	 */
	private function prepare(): void
	{
		if($this->error !== '' || $this->backdrop->renderer !== null)
		{
			return;
		}

		try
		{
			$this->backdrop->prepare();
		}
		catch(Throwable $error)
		{
			if($this->game->engine->device->isLost())
			{
				throw $error;
			}

			$this->error = $error->getMessage();
		}
	}

	/**
	 * Handle fresh activation, failed downloads, and completion of timed card sequences.
	 *
	 * @param array $frame SDL UI snapshot containing buttons and ordered pointer events.
	 * @param float $dt Capped active frame delta in seconds.
	 * @return void Advances cards or retries a failed asset scope without restarting the game.
	 */
	private function updateCards(array $frame, float $dt): void
	{
		$pressed = ($frame['buttons']['confirm'] ?? false) || ($frame['buttons']['pause'] ?? false);

		foreach($frame['events'] as $event)
		{
			$pressed = $pressed || ($event['type'] === 'pointer'
				&& $event['action'] === 'down' && ($event['button'] ?? 0) === 1
			);
		}

		$fresh = $pressed && !$this->held;
		$this->held = $pressed;

		if($this->phase === 'loading' && ($frame['buttons']['back'] ?? false))
		{
			$this->showMenu();

			return;
		}

		if($fresh && ($this->error !== '' || $this->game->error !== ''))
		{
			if($this->phase === 'loading')
			{
				$this->play();
			}
			else
			{
				$this->backdrop->close();
				$this->backdrop = new DemoBackdrop;
				$this->error = '';
				$this->replay();
			}

			return;
		}

		$this->cards->update($dt, $pressed);

		if($this->cards->complete())
		{
			$this->showMenu();
		}
	}

	/**
	 * Draw startup UI after polling staged levels and before the engine swaps buffers.
	 *
	 * @return bool True when this method drew the frame; false when gameplay should render.
	 */
	public function render(): bool
	{
		if($this->phase === 'loading' && $this->game->pending === null
			&& $this->game->error === '' && $this->game->level !== null
		){
			$this->resume();
		}

		if($this->phase === 'playing')
		{
			$this->publish();

			return false;
		}

		$device = $this->game->engine->device;
		$this->camera->width = $device->width;
		$this->camera->height = $device->height;
		$this->camera->x = $this->time * 90;
		$card = $this->cards->current();
		$background = $this->phase === 'menu' || $card?->id === 'title';

		if($background)
		{
			$this->backdrop->renderer?->render($this->camera, $this->time * 1000);
		}

		if($this->phase === 'menu')
		{
			$this->game->menu->renderer->render($this->menu, $device->width, $device->height, $this->time);
		}
		else
		{
			$this->renderCard($background);
		}

		$this->publish();

		return true;
	}

	/**
	 * Resolve loading text and prompts while keeping content and timing in PHP.
	 *
	 * @param bool $background Whether the parallax scene is visible below the card.
	 * @return void Draws the current card and exposes full failures in the browser status line.
	 */
	private function renderCard(bool $background): void
	{
		$card = $this->cards->current();
		$progress = null;
		$prompt = 'Start / Enter / Click to skip';
		$error = $this->error ?: $this->game->error;

		if($card?->id === 'loading' || $this->phase === 'loading')
		{
			$progress = $this->phase === 'loading' ? $this->game->pending?->progress() : $this->backdrop->progress();
			$card->title = $error !== '' ? 'Download failed' : 'Loading the demo';

			$card->text = $error !== ''
				? 'Check the connection and try again.'
				: sprintf('%d / %d discovered files ready', $progress['ready'] ?? 0, $progress['total'] ?? 0);

			$prompt = $error !== '' ? '⓿ Retry' : '';
			$prompt .= $this->phase === 'loading' ? '   ❶ Main menu' : '';
		}
		elseif($card?->id === 'title')
		{
			$prompt = $this->cards->elapsed >= $card->skipAfter && fmod($this->time, 1.2) < .8
				? 'Press Start / Enter / Click'
				: '';
		}

		$device = $this->game->engine->device;

		$this->renderer->render($this->cards, $device->width, $device->height, $prompt, $progress, $background);
	}

	/**
	 * Expose useful state for scripts, regression tests, and the page's status text.
	 *
	 * @return void Publishes UI time separately from paused simulation time.
	 */
	private function publish(): void
	{
		$canvas = vrzno_env('canvas');

		$canvas->dataset->intro = json_encode([
			'phase' => $this->phase
			, 'card' => $this->cards->current()?->id
			, 'state' => $this->cards->state
			, 'elapsed' => $this->cards->elapsed
			, 'time' => $this->time
			, 'pan' => $this->camera->x
			, 'gameTime' => $this->game->engine->time
			, 'error' => $this->error ?: $this->game->error
			, 'progress' => $this->backdrop->progress()
		]);

		if($this->phase !== 'playing')
		{
			$status = (new Vrzno)->document->querySelector('#status') ?? new stdClass;
			$status->textContent = ($this->error ?: $this->game->error)
				?: ($this->phase === 'menu'
					? 'Choose Play, or adjust Level, Movement, and Settings.'
					: 'Start / Enter selects. Arrow keys or the D-pad move. Escape / B goes back.'
				);
		}
	}

	/**
	 * Rebuild startup graphics after the shared menu atlas has been restored.
	 *
	 * @return void Preserves the active card, menu selection, and background phase.
	 */
	public function restore(): void
	{
		$this->renderer->restore();
		$this->backdrop->restore();
	}

	/**
	 * Cancel cards and downloads before the game releases its shared UI atlas and device.
	 *
	 * @return void Releases this flow's graphics and asset references once.
	 */
	public function close(): void
	{
		if($this->closed)
		{
			return;
		}

		$this->closed = true;
		$this->cards->cancel();
		$this->menu->close();
		$this->renderer->close();
		$this->backdrop->close();
	}
}
