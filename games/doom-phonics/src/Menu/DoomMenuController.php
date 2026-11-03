<?php
declare(strict_types=1);
namespace DoomPhonics\Menu;

use DoomPhonics\Audio\SoundCue;
use DoomPhonics\Game\EpisodeGame;
use DoomPhonics\Game\GameSettings;
use DoomPhonics\Game\PitchMode;
use DoomPhonics\Game\RenderResolution;
use DoomPhonics\Game\SkillLevel;
use DoomPhonics\Input\InputSettings;
use DoomPhonics\Input\DoomActions;
use DoomPhonics\Render\DoomIntermissionRenderer;
use DoomPhonics\Render\GlesMapRenderer;
use Phonics\Engine;
use Phonics\Ui\DomTextureModal;
use Phonics\Ui\Menu;
use Phonics\Ui\MenuInput;
use Phonics\Ui\MenuRenderer;

/** Owns title, pause, settings, help, and new-game pages around one EpisodeGame lifecycle. */
final class DoomMenuController implements MenuOverlay
{
	public Menu $menu;
	public DoomMenuRenderer $renderer;
	private MenuInput $input;
	private bool $atTitle = true;
	private bool $acted = false;
	private bool $closed = false;
	private int $generation = -1;
	private float $time = 0;
	private ?\Closure $browserAction;
	private int $onlineRequest = 0;
	private int $networkReady = 0;
	private int $contentRequest = 0;
	private DoomControlsMenu $controlsMenu;
	private DoomOptions $options;
	private DoomIntermissionRenderer $intermissionRenderer;

	public function __construct(
		private Engine $engine
		, private EpisodeGame $game
		, private GameSettings $settings
		, private string $startMap
		, private ?DomTextureModal $phpInfo = null
		, private ?MenuRenderer $phpInfoHints = null
		, ?callable $browserAction = null
		, private bool $onlineEnabled = false
		, private ?NetworkMenuSettings $networkSettings = null
		, private ?InputSettings $inputSettings = null
		, ?DoomOptions $options = null
	){
		$this->inputSettings ??= new InputSettings($settings);
		$this->controlsMenu = new DoomControlsMenu($this->inputSettings);
		$this->options = $options ?? new DoomOptions($settings);
		$this->browserAction = $browserAction === null ? null : \Closure::fromCallable($browserAction);
		$this->menu = new Menu($this->titleItems(), 'Doom-Phonics');
		$this->menu->open();
		$this->renderer = new DoomMenuRenderer($this->phpInfoHints);
		$this->intermissionRenderer = new DoomIntermissionRenderer;
		$this->input = new MenuInput;
		$this->engine->userPaused = true;
		$this->engine->setInputHandler([$this, 'update']);
	}

	/** Route SDL UI frames even while simulation is paused, then resume browser look input. */
	public function update(Engine $engine, float $elapsed): bool
	{
		$recovering = $this->game->recoveringNetwork();
		$this->game->advanceTransition($elapsed);
		$action = $this->browserAction ? (string)($this->browserAction)() : '';

		if($action === 'network-load')
		{
			if($this->game->loadNetworkWorld()) $this->networkReady++;
		}
		elseif($action === 'content-load')
		{
			$error = null;

			try
			{
				if(!$this->game->loadContentWorld())
				throw new \UnexpectedValueException('No selected content world is available');

				$this->startMap = $this->game->map;
				$this->atTitle = false;
				$this->menu->close();
				$this->actionSound(SoundCue::menuSelect());
			}
			catch(\Throwable $failure)
			{
				$error = 'Could not start game: ' . $failure->getMessage();
			}

			if(function_exists('vrzno_env') && ($ready = vrzno_env('phonicsContentReady'))) $ready($error);
		}
		elseif($action === 'network-start')
		{
			$this->atTitle = false;
			$this->menu->close();
			$this->game->playLevelMusic();
			$this->actionSound(SoundCue::menuSelect());
		}
		elseif($action === 'network-end')
		{
			$this->game->returnToTitle();
			$this->phpInfo?->close();

			$this->installRoot('Disconnected', [
				'title' => ['label' => 'Back to Title', 'callback' => function(): void { $this->showTitle(); }]
				, 'online-play' => $this->onlineItem()
			], true
			);
		}
		elseif($action === 'network-leave') $this->showTitle();

		if($this->game->transitioning())
		{
			$engine->userPaused = true;

			return true;
		}

		if($this->game->intermission() !== null)
		{
			$frame = $engine->input->uiFrame(false, true);
			$actions = $engine->input->frameActions();
			$activation = ($frame['buttons']['confirm'] ?? 0) >= .5
				|| abs($actions->value(DoomActions::FIRE)) >= .5
				|| abs($actions->value(DoomActions::USE)) >= .5;

			$this->game->advanceIntermission(min(.25, max(0, $elapsed)), $activation);

			if($this->game->complete && !$this->atTitle) $this->showTitle();

			$engine->userPaused = true;

			return true;
		}

		if($this->game->complete && !$this->atTitle) $this->showTitle();

		if($this->game->transitioning())
		{
			$engine->userPaused = true;

			return true;
		}

		if($this->phpInfo?->isOpen())
		{
			if($action === 'close-info')
			$this->closePhpInfo($engine);
			else
			{
				$this->phpInfo->update($engine, $elapsed, $this->menu);

				if(!$this->phpInfo->isOpen()) $this->closePhpInfo($engine, true);
			}

			$engine->userPaused = true;

			return true;
		}

		if($this->menu->isOpen && in_array($this->menu->title, ['Sound','Video'], true))
		$this->options->refresh();

		$frame = $engine->input->uiFrame($this->menu->editor !== null, $this->menu->isOpen);

		if($frame['generation'] !== $this->generation || !$frame['focused'])
		{
			$this->input->reset();
			$this->generation = $frame['generation'];
		}

		$browserInput = $this->game->browserInput();
		$browserPause = !$this->atTitle && $this->game->consumePauseRequest();
		$browserCaptured = !$this->menu->isOpen && !$this->atTitle && $browserInput;

		if($browserPause) $frame['buttons']['pause'] = 1;

		if((!$browserCaptured || $browserPause) && !$this->menu->isOpen && !$this->atTitle
			&& ($frame['buttons']['pause'] ?? 0) >= .5
		)
		$this->installRoot('Paused', $this->pauseItems(), false, false, 'resume');

		$this->time += min(.25, max(0, $elapsed));
		$beforeOpen = $this->menu->isOpen;
		$beforeDepth = $this->menu->depth();
		$beforeSelected = $this->menu->selected;
		$this->acted = false;

		$this->controlsMenu->theme = $engine->input->controllerTheme();
		$bindingCaptured = $this->controlsMenu->update($this->menu, $engine->input->actions, $frame['focused']);

		if($bindingCaptured && !$this->controlsMenu->capturing())
		{
			$engine->input->suppressHeldUi();
			$this->input->reset();
		}

		$engine->input->lookDeadZone = $this->settings->controllerDeadZone;

		$captured = $browserCaptured || $bindingCaptured || $this->input->update(
			$this->menu
			, $frame['buttons']
			, min(.25, max(0, $elapsed))
			, $frame['events']
			, $this->renderer->regions
		);

		// A submenu owns the input that opened or closed it. Do not let a held
		// Enter/A/Escape/B become a fresh edge in the newly active page.
		if($this->menu->depth() !== $beforeDepth)
		{
			$engine->input->suppressHeldUi();
			$this->input->reset();
		}

		if($this->menu->title === 'New Game' && $this->menu->depth() > $beforeDepth)
		$this->menu->select('skill-' . $this->settings->skill()->index());

		if($this->atTitle && !$this->menu->isOpen) $this->menu->open();

		if(!$this->acted)
		{
			if(!$beforeOpen && $this->menu->isOpen || $this->menu->depth() > $beforeDepth)
			$this->game->playMenuSound(SoundCue::menuOpen());
			elseif($beforeOpen && !$this->menu->isOpen || $this->menu->depth() < $beforeDepth)
			$this->game->playMenuSound(SoundCue::menuBack());
			elseif($this->menu->selected !== $beforeSelected)
			$this->game->playMenuSound(SoundCue::menuMove());
		}

		$engine->userPaused = $recovering || $this->menu->isOpen || $this->atTitle || $this->game->transitioning();

		return $recovering || $captured || $browserCaptured || $this->menu->isOpen || $this->atTitle
			|| $this->game->transitioning();
	}

	public function render(GlesMapRenderer $renderer): void
	{
		if($intermission = $this->game->intermission())
		{
			$this->intermissionRenderer->render($renderer, $intermission);

			return;
		}

		$this->renderer->render(
			$renderer
			, $this->menu
			, $this->atTitle
			, $this->time
			, $this->engine->input->inputTheme
			, $this->engine->input->controllerTheme()
		);

		$this->phpInfo?->render($renderer->width, $renderer->height, 2);
	}

	public function restore(): void { $this->phpInfoHints?->restore(); }

	public function coversWorld(): bool
	{
		return $this->game->intermission() !== null || ($this->atTitle && $this->menu->isOpen);
	}

	/** Return browser-readable menu state without exposing renderer internals. */
	public function status(): array
	{
		return [
			'open' => $this->menu->isOpen
			, 'screen' => $this->screen()
			, 'selected' => $this->menu->selected
			, 'orientation' => $this->menu->orientation
			, 'editing' => $this->menu->editor !== null
			, 'skill' => $this->settings->skill()->label()
			, 'renderResolution' => $this->settings->renderResolution()->value
			, 'pitchMode' => $this->settings->pitchMode()->value
			, 'reticle' => $this->settings->reticle()
			, 'bloodSplatter' => $this->settings->bloodSplatter()
			, 'options' => $this->options->state()
			, 'font' => $this->renderer->font()
			, 'inputTheme' => $this->engine->input->inputTheme
			, 'controllerTheme' => $this->engine->input->controllerTheme()
			, 'phpInfo' => $this->phpInfo?->state() ?? ['open' => false]
			, 'onlineRequest' => $this->onlineRequest
			, 'networkReady' => $this->networkReady
			, 'contentRequest' => $this->contentRequest
			, 'bindingCapture' => $this->controlsMenu->capturing()
			, 'controlsRows' => $this->menu->value('controls-page')
				? array_map(static fn(array $row): array => [
					'label' => $row['label']
					, 'value' => $row['value'] ?? ''
				], $this->menu->rows()
				)
				: null
			, 'networking' => $this->networkSettings
				? [
					'homeserver' => $this->networkSettings->homeserver()
					, 'lobbyChannel' => $this->networkSettings->lobbyChannel()
					, 'dirty' => $this->networkSettings->dirty()
					, 'message' => $this->networkSettings->message()
					, 'error' => $this->networkSettings->failed()
				]
				: null
		];
	}

	public function close(): void
	{
		if($this->closed) return;

		$this->closed = true;
		$this->phpInfo?->close();
		$this->phpInfoHints?->close();
		$this->menu->close();
		$this->engine->setInputHandler(null);
		$this->engine->userPaused = false;
	}

	/** @return array<string,array<string,mixed>> */
	private function titleItems(): array
	{
		return [
			'new-game' => $this->newGameItem()
			, 'online-play' => $this->onlineItem()
			, 'wad-stack' => [
				'label' => 'WAD Stack'
				, 'hidden' => !$this->onlineEnabled
				, 'callback' => function(): void { $this->openContent(); }
			]
			, 'settings' => $this->settingsItem()
			, 'read-this' => $this->readThisItem()
			, 'php-info' => [
				'label' => 'PHP Info'
				, 'disabled' => $this->phpInfo === null
				, 'callback' => function(): void { $this->showPhpInfo(); }
			]
		];
	}

	/** @return array<string,mixed> */
	private function onlineItem(): array
	{
		return [
			'label' => 'Online Play'
			, 'hidden' => !$this->onlineEnabled
			, 'children' => [
				'enter-lobby' => [
					'label' => 'Enter Lobby'
					, 'callback' => function(): void { $this->openOnline(); }
				]
				, 'networking' => $this->networkingItem()
			]
		];
	}

	/** @return array<string,mixed> */
	private function networkingItem(): array
	{
		return [
			'label' => 'Networking'
			, 'disabled' => $this->networkSettings === null
			, 'children' => [
				'homeserver' => [
					'label' => 'Homeserver'
					, 'input' => 'string'
					, 'valueAlign' => 'left'
					, 'get' => fn(): string => $this->networkSettings?->homeserver() ?? ''
					, 'set' => function(string $value): void { $this->networkSettings?->stageHomeserver($value); }
					, 'revert' => fn(): string => $this->networkSettings?->revertHomeserver() ?? ''
				]
				, 'lobby-channel' => [
					'label' => 'Lobby channel'
					, 'input' => 'string'
					, 'valueAlign' => 'left'
					, 'get' => fn(): string => $this->networkSettings?->lobbyChannel() ?? ''
					, 'set' => function(string $value): void { $this->networkSettings?->stageLobbyChannel($value); }
					, 'revert' => fn(): string => $this->networkSettings?->revertLobbyChannel() ?? ''
				]
				, 'apply-networking' => [
					'label' => 'Apply Settings'
					, 'disabled' => fn(): bool => !($this->networkSettings?->dirty() ?? false)
					, 'callback' => function(): void { $this->applyNetworkSettings(); }
				]
				, 'network-status' => [
					'label' => 'Status'
					, 'input' => 'output'
					, 'disabled' => true
					, 'hidden' => true
					, 'get' => fn(): string => $this->networkSettings?->message() ?? ''
				]
			]
		];
	}

	/** @return array<string,array<string,mixed>> */
	private function pauseItems(): array
	{
		return [
			'resume' => ['label' => 'Resume Game','callback' => function(): void { $this->resume(); }]
			, 'new-game' => $this->newGameItem()
			, 'settings' => $this->settingsItem()
			, 'restart' => ['label' => 'Restart Level','callback' => function(): void { $this->restart(); }]
			, 'title' => ['label' => 'Quit to Title','callback' => function(): void { $this->showTitle(); }]
		];
	}

	/** @return array<string,mixed> */
	private function newGameItem(): array
	{
		$skills = [];

		foreach(SkillLevel::all() as $skill)
		$skills['skill-' . $skill->index()] = [
			'label' => $skill->label()
			, 'callback' => function() use ($skill): void { $this->start($skill); }
		];

		return ['label' => 'New Game','children' => $skills];
	}

	/** @return array<string,mixed> */
	private function settingsItem(): array
	{
		return [
			'label' => 'Settings'
			, 'children' => [
				'input' => $this->controlsMenu->item('Input')
				, 'sound' => ['label' => 'Sound', 'children' => fn(): array => $this->soundItems()]
				, 'video' => ['label' => 'Video', 'children' => fn(): array => $this->videoItems()]
			]
		];
	}

	/** @return array<string,array<string,mixed>> */
	private function soundItems(): array
	{
		return [
			'settings-section' => ['hidden' => true, 'value' => 'sound']
			, 'effects-volume' => [
				'label' => 'Sound Effects'
				, 'input' => 'number'
				, 'subType' => 'range'
				, 'min' => 0
				, 'max' => 1
				, 'step' => .1
				, 'format' => 'percent'
				, 'get' => fn(): float => $this->options->effectsVolume()
				, 'set' => function(float $value): void { $this->changeEffectsVolume($value); }
			]
			, 'music-volume' => [
				'label' => 'Music'
				, 'input' => 'number'
				, 'subType' => 'range'
				, 'min' => 0
				, 'max' => 1
				, 'step' => .1
				, 'format' => 'percent'
				, 'get' => fn(): float => $this->options->musicVolume()
				, 'set' => function(float $value): void { $this->changeMusicVolume($value); }
			]
			, 'mute-all' => [
				'label' => 'Mute All'
				, 'input' => 'boolean'
				, 'get' => fn(): bool => $this->options->muted()
				, 'set' => function(bool $value): void { $this->changeMuted($value); }
			]
			, 'settings-message' => ['hidden' => true, 'get' => fn(): string => $this->options->message()]
		];
	}

	/** @return array<string,array<string,mixed>> */
	private function videoItems(): array
	{
		return [
			'settings-section' => ['hidden' => true, 'value' => 'video']
			, 'fullscreen' => [
				'label' => 'Fullscreen'
				, 'input' => 'boolean'
				, 'hidden' => fn(): bool => !$this->options->fullscreenAvailable()
				, 'disabled' => fn(): bool => $this->options->fullscreenBusy()
				, 'get' => fn(): bool => $this->options->fullscreen()
				, 'set' => function(bool $value): void { $this->changeFullscreen($value); }
			]
			, 'render-resolution' => [
				'label' => 'Render Resolution'
				, 'input' => 'select'
				, 'options' => [RenderResolution::Native->value,RenderResolution::Classic->value]
				, 'get' => fn(): string => $this->settings->renderResolution()->value
				, 'set' => function(string $value): void { $this->changeRenderResolution($value); }
			]
			, 'pitch-mode' => [
				'label' => 'Pitch Mode'
				, 'input' => 'select'
				, 'options' => [PitchMode::Perspective->value,PitchMode::ClassicShear->value]
				, 'get' => fn(): string => $this->settings->pitchMode()->value
				, 'set' => function(string $value): void { $this->changePitchMode($value); }
			]
			, 'reticle' => [
				'label' => 'Target Reticle'
				, 'input' => 'boolean'
				, 'get' => fn(): bool => $this->settings->reticle()
				, 'set' => function(bool $value): void { $this->changeReticle($value); }
			]
			, 'blood-splatter' => [
				'label' => 'Blood Splatter'
				, 'input' => 'number'
				, 'subType' => 'range'
				, 'min' => 0
				, 'max' => 2
				, 'step' => .25
				, 'format' => 'percent'
				, 'get' => fn(): float => $this->settings->bloodSplatter()
				, 'set' => function(float $value): void { $this->changeBloodSplatter($value); }
			]
			, 'settings-message' => ['hidden' => true, 'get' => fn(): string => $this->options->message()]
		];
	}

	/** @return array<string,mixed> */
	private function readThisItem(): array
	{
		return [
			'label' => 'Read This'
			, 'orientation' => Menu::HORIZONTAL
			, 'children' => [
				'read-this-page' => ['hidden' => true, 'value' => true]
				, 'rebind' => $this->controlsMenu->item('Rebind')
			]
		];
	}

	private function showPhpInfo(): void
	{
		if($this->phpInfo === null) return;

		ob_start();
		phpinfo();
		$this->phpInfo->show('PHP Info', (string)ob_get_clean());
		$this->actionSound(SoundCue::menuSelect());
	}

	private function openOnline(): void
	{
		if(!$this->onlineEnabled) return;

		$this->onlineRequest++;
		$this->actionSound(SoundCue::menuSelect());
	}

	private function openContent(): void
	{
		if(!$this->onlineEnabled) return;

		$this->contentRequest++;
		$this->actionSound(SoundCue::menuSelect());
	}

	private function applyNetworkSettings(): void
	{
		if(!$this->networkSettings?->apply()) return;

		$this->actionSound(SoundCue::menuSelect());
	}

	/** Replace the retained PHP Info frame with the title through the shared screen melt. */
	private function closePhpInfo(Engine $engine, bool $alreadyClosed = false): void
	{
		$this->game->beginTransition();

		if(!$alreadyClosed) $this->phpInfo?->close();

		// PHP Info is a title-page action. Keep the title as the incoming wipe frame
		// even when Pause/Start was the control that closed the modal.
		if(!$this->menu->isOpen) $this->menu->open();

		$engine->input->suppressHeldUi();
		$this->actionSound(SoundCue::menuBack());
	}

	/** @param array<string,array<string,mixed>> $items */
	private function installRoot(
		string $title
		, array $items
		, bool $atTitle
		, bool $open = true
		, ?string $selected = null
	): void {
		$this->menu->close();
		$this->menu->title = $title;
		$this->menu->setItems($items);

		if($selected !== null) $this->menu->select($selected);

		if($open) $this->menu->open();

		$this->atTitle = $atTitle;
	}

	private function start(SkillLevel $skill): void
	{
		$this->settings->chooseSkill($skill);
		$this->game->newGame($this->startMap);
		$this->atTitle = false;
		$this->menu->close();
		$this->actionSound(SoundCue::menuSelect());
	}

	private function resume(): void
	{
		$this->menu->close();
		$this->actionSound(SoundCue::menuBack());
	}

	private function restart(): void
	{
		$this->game->restart();
		$this->menu->close();
		$this->actionSound(SoundCue::menuSelect());
	}

	private function showTitle(): void
	{
		$this->game->returnToTitle();
		$this->installRoot('Doom-Phonics', $this->titleItems(), true);
		$this->actionSound(SoundCue::menuBack());
	}

	private function changeEffectsVolume(float $value): void
	{
		$this->options->setEffectsVolume($value);
		$this->actionSound(SoundCue::menuMove());
	}

	private function changeMusicVolume(float $value): void
	{
		$this->options->setMusicVolume($value);
		$this->actionSound(SoundCue::menuMove());
	}

	private function changeMuted(bool $value): void { $this->options->setMuted($value); }

	private function changeFullscreen(bool $value): void
	{
		$this->options->setFullscreen($value);
		$this->actionSound(SoundCue::menuMove());
	}

	private function changeRenderResolution(string $value): void
	{
		$this->options->setRenderResolution(RenderResolution::from($value));
		$this->actionSound(SoundCue::menuMove());
	}

	private function changePitchMode(string $value): void
	{
		$this->options->setPitchMode(PitchMode::from($value));
		$this->actionSound(SoundCue::menuMove());
	}

	private function changeReticle(bool $value): void
	{
		$this->options->setReticle($value);
		$this->actionSound(SoundCue::menuMove());
	}

	private function changeBloodSplatter(float $value): void
	{
		$this->options->setBloodSplatter($value);
		$this->actionSound(SoundCue::menuMove());
	}

	private function actionSound(SoundCue $cue): void
	{
		$this->acted = true;
		$this->game->playMenuSound($cue);
	}

	private function screen(): ?string
	{
		if(!$this->menu->isOpen) return null;

		return match($this->menu->title)
		{
			'Doom-Phonics' => 'title',
			'Disconnected' => 'disconnected',
			'Paused' => 'pause',
			'New Game' => 'skill',
			'Online Play' => 'online',
			'Networking' => 'networking',
			'Settings' => 'settings',
			'Input' => 'input',
			'Sound' => 'sound',
			'Video' => 'video',
			'Read This' => 'read-this',
			'Rebind' => 'controls',
			'Controls' => 'controls',
			default => 'menu',
		};
	}
}
