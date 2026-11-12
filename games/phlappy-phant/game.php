<?php
declare(strict_types=1);

use Phonics\Asset\Resource;
use Phonics\Engine;
use Phonics\FixedStep;
use Phonics\Render\BitmapFont;
use Phonics\Input\Sequences;
use Phonics\Render\Device;
use Phonics\Ui\BindingMenu;
use Phonics\Ui\Menu;
use Phonics\Ui\MenuInput;
use PhlappyPhant\Input\InputSettings;
use PhlappyPhant\Input\PhantActions;
use PhlappyPhant\Render\Scene;
use PhlappyPhant\Simulation;

$engine = new Engine(new Device(640, 960, 'Phlappy Phant'));
$engine->entities = $phonics->entities;
$engine->systems = $phonics->systems;
$engine->clock = new FixedStep(Simulation::STEP, 5);

$settings = new InputSettings(
	static fn(): string => (string)(vrzno_env('phantStorageRead'))()
	, static fn(string $json): bool => (bool)(vrzno_env('phantStorageWrite'))($json)
);

$engine->input->useBindings($settings->bindings);

// The Konami code on the standard button lattice: arrows or the D-pad, then B and A (the controller's face buttons;
// on a keyboard B and A themselves, beside the engine's X/Z and Space defaults). It is only watched while the
// score is zero, and it hands the run to the tool-assisted player.
$engine->input->bindButton(0, [SDLK_SPACE, SDLK_z, SDLK_RETURN, SDLK_KP_ENTER, ord('a')]);
$engine->input->bindButton(1, [SDLK_x, SDLK_LSHIFT, SDLK_LCTRL, SDLK_RCTRL, ord('b')]);
$engine->input->bindButton(14, [SDLK_LEFT]);
$konami = new Sequences;
$konami->add('konami', ['up', 'up', 'down', 'down', 'left', 'right', 'left', 'right', 1, 0], 1.5, strict: false);

$font = new BitmapFont(
	$phonics->loader
	, Resource::framework('resources/font.tsj')
	, [Resource::framework('resources/buttons.tsj')]
);

$scene = new Scene($font, $phonics->loader->map(__DIR__ . '/worlds/phant.tmj'));
$menu = new Menu([], 'PHLAPPY PHANT');
$navigation = new MenuInput;

$bindings = new BindingMenu(
	$settings->bindings
	, PhantActions::rows()
	, $settings->save(...)
	, $settings->reset(...)
	, PhantActions::allowed(...)
	, status: static fn(): string => $settings->sessionOnly ? 'Changes apply to this session' : ''
);

$game = new Simulation(1);
$page = 'title';
$uiTime = 0.0;
$runId = 0;
$seenTaps = 0;
$submitted = true;
$counts = ['flap' => 0, 'score' => 0, 'bonk' => 0, 'night' => 0, 'tas' => 0];
$setPage = null;

$best = static fn(): int => max(0, (int)(vrzno_env('phantBest'))());

$audioItems = static function(): array {
	return [
		'effects' => [
			'label' => 'SFX level'
			, 'input' => 'number'
			, 'subType' => 'range'
			, 'min' => 0
			, 'max' => 100
			, 'step' => 5
			, 'default' => 50
			, 'valueAlign' => 'right'
			, 'subtext' => 'Flaps, scoring, and bonks.'
			, 'get' => static fn(): int => (int)(vrzno_env('phantAudioGet'))('effects')
			, 'set' => static fn(float $value): bool => (bool)(vrzno_env('phantAudioSet'))('effects', $value)
		]
		, 'music' => [
			'label' => 'BGM level'
			, 'input' => 'number'
			, 'subType' => 'range'
			, 'min' => 0
			, 'max' => 100
			, 'step' => 5
			, 'default' => 75
			, 'valueAlign' => 'right'
			, 'subtext' => 'Funktify soundtrack.'
			, 'get' => static fn(): int => (int)(vrzno_env('phantAudioGet'))('music')
			, 'set' => static fn(float $value): bool => (bool)(vrzno_env('phantAudioSet'))('music', $value)
		]
	];
};

$startGame = static function() use (&$game, &$page, &$runId, &$counts, &$submitted, &$seenTaps, $menu, $engine): void {
	$game = new Simulation((int)(vrzno_env('phantSeed'))());
	$runId++;
	$counts = ['flap' => 0, 'score' => 0, 'bonk' => 0, 'night' => 0, 'tas' => 0];
	$submitted = false;
	$seenTaps = (int)(vrzno_env('phantTaps'))();
	$page = 'play';
	$menu->close();
	$engine->userPaused = false;
	$engine->input->reset();
	$engine->input->suppressHeldUi();
};

$titleItems = static function() use (&$startGame, $bindings, $audioItems): array {
	return [
		'play' => [
			'label' => 'Play'
			, 'callback' => static function() use (&$startGame): void { $startGame(); }
		]
		, 'controls' => $bindings->item('Input settings')
		, 'audio' => ['label' => 'Audio', 'children' => $audioItems()]
		// How to play and About are pages to read, not rows to pick: the engine sets them as scrolling text.
		, 'help' => [
			'label' => 'How to play'
			, 'text' => [
				['heading' => 'Fly']
				, 'Flap to stay in the air and slip between the pillars. Each pair you pass scores a point.'
				, 'The pillars come faster and their gaps close in as your score climbs. Every ten points, day and night trade places.'
				, ['heading' => 'Controls']
				, ['name' => 'Flap', 'value' => 'Tap, click, Space or A']
				, ['name' => 'Pause', 'value' => 'Escape, P or Start']
				, 'Change them in Input settings.'
			]
		]
		, 'about' => [
			'label' => 'About'
			, 'text' => [
				'Built with Phonics.'
				, ['heading' => 'Art']
				, ['name' => 'elePHPant', 'value' => 'Vincent Pontier']
				, ['name' => 'Sprite', 'value' => "Traced from Webysther's SVG, GPL-2.0-or-later"]
				, ['name' => 'Terrain', 'value' => 'GrafxKid, CC0']
				, ['heading' => 'Music']
				, ['name' => 'Funktify', 'value' => 'Mr. Spastic, CC BY-NC-ND 3.0']
				, ['heading' => 'Code']
				, 'Apache-2.0.'
			]
		]
	];
};

$setPage = static function(string $next) use (
	&$setPage, &$startGame, &$page, &$game, $titleItems, $audioItems, $menu, $engine, $best
): void {
	$page = $next;

	// Returning from a run gets a fresh waiting preview instead of leaving the crashed phant on the title screen.
	if($next === 'title' && $game->phase !== 'ready') $game = new Simulation(1);

	$menu->close();
	$menu->title = match($next)
	{
		'pause' => 'PAUSED'
		, 'gameover' => 'BONK'
		, default => 'DODGE THE PILLARS'
	};

	if($next === 'title') $items = $titleItems();
	elseif($next === 'pause')
	$items = [
		'resume' => [
			'label' => 'Resume'
			, 'callback' => static function() use (&$page, $menu, $engine): void {
				$page = 'play';
				$menu->close();
				$engine->userPaused = false;
				$engine->input->suppressHeldUi();
			}
		]
		, 'restart' => [
			'label' => 'Restart'
			, 'callback' => static function() use (&$startGame): void { $startGame(); }
		]
		, 'audio' => ['label' => 'Audio', 'children' => $audioItems()]
		, 'title' => [
			'label' => 'Return to title'
			, 'callback' => static function() use (&$setPage): void { $setPage('title'); }
		]
	];
	else
	{
		$record = $best();
		$items = [
			'again' => [
				'label' => 'Play again'
				, 'callback' => static function() use (&$startGame): void { $startGame(); }
			]
			, 'title' => [
				'label' => 'Return to title'
				, 'callback' => static function() use (&$setPage): void { $setPage('title'); }
			]
			, 'score' => ['label' => 'Score  ' . $game->score, 'disabled' => true]
			, 'best' => [
				'label' => ($game->score >= $record && $game->score > 0 ? 'New best  ' : 'Best  ') . max($record, $game->score)
				, 'disabled' => true
			]
		];
	}

	$menu->setItems($items);
	$menu->open();
	// The world keeps moving behind the title and the result card; only Pause freezes it.
	$engine->userPaused = $next === 'pause';
	$engine->input->suppressHeldUi();
};

$setPage('title');

$engine->systems->add('phlappy-phant.simulation', static function() use ($engine, &$game, &$page, &$counts, &$seenTaps, $konami): void {
	$taps = (int)(vrzno_env('phantTaps'))();
	// Up is both a flap and the first step of the Konami code, so a code in progress on the ready screen waits
	// there instead of starting the run; the code's timeout restores the flap.
	$flap = $page === 'play' && ($engine->controls->pressed(PhantActions::FLAP) || $taps > $seenTaps)
		&& !($game->phase === 'ready' && $konami->progress('konami') > 0);
	$seenTaps = $taps;
	$game->step($flap);

	foreach($game->consumeEvents() as $event) $counts[$event] = ($counts[$event] ?? 0) + 1;
});

$engine->setInputHandler(static function(
	Engine $engine
	, float $elapsed
) use (
	&$game, &$page, &$uiTime, &$setPage, &$submitted, &$counts, $menu, $navigation, $bindings, $scene, $konami
): bool {
	$uiTime += min(.25, max(0, $elapsed));
	$action = (string)(vrzno_env('phantAction'))();

	if($page === 'play' && $game->score === 0 && !$game->autopilot)
	{
		$konami->update($engine->input->snapshot(), $uiTime);

		foreach($konami->drain() as $match)
		{
			if($match['name'] !== 'konami') continue;

			$game->autopilot = true;
			$counts['tas']++;
		}
	}

	if($page === 'play' && $game->finished)
	{
		// A tool-assisted run is not the player's: it never touches the best score.
		if(!$submitted && !$game->autopilot)
		{
			(vrzno_env('phantSubmitScore'))($game->score);
			$submitted = true;
		}

		$setPage('gameover');

		return true;
	}

	$frame = $engine->input->uiFrame($menu->editor !== null, $menu->isOpen);

	if($page === 'play')
	{
		if($action === 'pause' || ($frame['buttons']['pause'] ?? 0) >= .5)
		{
			$setPage('pause');

			return true;
		}

		$engine->userPaused = false;

		return false;
	}

	// The browser's region buttons press rows, and a text page's scroll arrows.
	if($action !== '') $menu->press($action);

	$bindings->theme = $engine->input->controllerTheme();
	$bindingCaptured = $bindings->update($menu, $engine->input->actions, $frame['focused']);

	if(!$bindingCaptured)
	$navigation->update($menu, $frame['buttons'], min(.25, max(0, $elapsed)), $frame['events'], $scene->regions);

	// Information rows are text to read, not controls: the selection steps over them in the direction of travel.
	$rows = $menu->rows();

	if($menu->isOpen && $menu->editor === null && ($rows[$menu->selected]['disabled'] ?? false))
	{
		$ids = array_keys($rows);
		$from = (int)array_search($menu->selected, $ids, true);
		$direction = ($frame['buttons']['up'] ?? 0) >= .5 ? -1 : 1;

		for($offset = 1; $offset < count($ids); $offset++)
		{
			$id = $ids[((($from + $direction * $offset) % count($ids)) + count($ids)) % count($ids)];

			if($rows[$id]['disabled']) continue;

			$menu->select((string)$id);
			break;
		}
	}

	if($page === 'play')
	{
		$engine->userPaused = false;

		return true;
	}

	if(!$menu->isOpen)
	{
		if($page === 'pause')
		{
			$page = 'play';
			$engine->userPaused = false;
			$engine->input->suppressHeldUi();

			return true;
		}

		$setPage('title');
	}

	$engine->userPaused = $page === 'pause';

	// Captured frames deliberately receive no simulation time. Title and result menus still animate their world;
	// the explicit pause page is the only menu that freezes it.
	return $page === 'pause';
});

(vrzno_env('sceneReady'))(null);

$engine->start(static function(
	Engine $engine
	, float $alpha
) use (
	&$game, &$page, &$uiTime, &$runId, &$counts, &$seenTaps, $scene, $font, $menu, $best, $konami
): void {
	[$width, $height] = json_decode((string)(vrzno_env('phantViewport'))(), true);
	$engine->device->resize($width, $height);
	$engine->device->begin($scene->skyColor());
	glViewport(0, 0, $width, $height);
	$font->theme = $engine->input->inputTheme;
	$scene->controllerTheme = $engine->input->controllerTheme();
	$record = $best();
	$scene->render($game, $menu, $page, $width, $height, $alpha, $uiTime, $record);
	$next = $game->nextPillar();

	(vrzno_env('phantStatus'))(json_encode([
		'page' => $page
		, 'phase' => $game->phase
		, 'score' => $game->score
		, 'best' => $record
		, 'finished' => $game->finished
		, 'autopilot' => $game->autopilot
		, 'konami' => $konami->progress('konami')
		, 'palette' => $game->palette
		, 'y' => $game->y
		, 'vy' => $game->vy
		, 'pillars' => count($game->pillars)
		, 'nextGap' => $next ? [$next->x, $next->gapTop, $next->gap] : null
		, 'scroll' => $game->scroll
		, 'runId' => $runId
		, 'taps' => $seenTaps
		, 'counts' => $counts
		, 'viewport' => $scene->viewport
		, 'regions' => $scene->regions
		, 'menu' => $scene->menuBounds
		, 'text' => $menu->text === null ? null : ['first' => $menu->text->first, 'last' => $menu->text->last()]
		, 'hint' => $scene->hint
		, 'inputTheme' => $engine->input->inputTheme
		, 'width' => $width
		, 'height' => $height
	]));
}, static function() use ($engine, $scene, $menu, $phonics): void {
	$menu->close();
	$scene->close();
	$engine->systems->remove('phlappy-phant.simulation');
	$phonics->close();
}, static function() use ($scene): void {
	$scene->restore();
});
