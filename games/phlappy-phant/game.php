<?php
declare(strict_types=1);

use Phonics\Asset\Resource;
use Phonics\Engine;
use Phonics\FixedStep;
use Phonics\Render\BitmapFont;
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
$counts = ['flap' => 0, 'score' => 0, 'bonk' => 0, 'night' => 0];
$setPage = null;

$best = static fn(): int => max(0, (int)(vrzno_env('phantBest'))());

$startGame = static function() use (&$game, &$page, &$runId, &$counts, &$submitted, &$seenTaps, $menu, $engine): void {
	$game = new Simulation((int)(vrzno_env('phantSeed'))());
	$runId++;
	$counts = ['flap' => 0, 'score' => 0, 'bonk' => 0, 'night' => 0];
	$submitted = false;
	$seenTaps = (int)(vrzno_env('phantTaps'))();
	$page = 'play';
	$menu->close();
	$engine->userPaused = false;
	$engine->input->reset();
	$engine->input->suppressHeldUi();
};

$titleItems = static function() use (&$startGame, $bindings): array {
	return [
		'play' => [
			'label' => 'Play'
			, 'callback' => static function() use (&$startGame): void { $startGame(); }
		]
		, 'controls' => $bindings->item('Input settings')
		, 'help' => [
			'label' => 'How to play'
			, 'children' => [
				'flap' => ['label' => 'Tap, click, Space or A to flap.', 'disabled' => true]
				, 'dodge' => ['label' => 'Slip between the DB silo barrels.', 'disabled' => true]
				, 'night' => ['label' => 'Every ten barrels, day turns to night.', 'disabled' => true]
				, 'pause' => ['label' => 'Escape, P or Start pauses.', 'disabled' => true]
			]
		]
		, 'about' => [
			'label' => 'About Phlappy Phant'
			, 'children' => [
				'title' => ['label' => 'PHLAPPY PHANT', 'disabled' => true]
				, 'built' => ['label' => 'Built with Phonics', 'disabled' => true]
				, 'elephpant' => ['label' => 'elePHPant by Vincent Pontier', 'disabled' => true]
				, 'vector' => ['label' => "Pixel art traced from Webysther's SVG", 'disabled' => true]
				, 'art-licence' => ['label' => 'Sprite licence: GPL-2.0-or-later', 'disabled' => true]
				, 'code-licence' => ['label' => 'Code licence: Apache-2.0', 'disabled' => true]
			]
		]
	];
};

$setPage = static function(string $next) use (
	&$setPage, &$startGame, &$page, &$game, $titleItems, $menu, $engine, $best
): void {
	$page = $next;
	$menu->close();
	$menu->title = match($next)
	{
		'pause' => 'PAUSED'
		, 'gameover' => 'BONK'
		, default => 'DODGE THE BARRELS'
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

$engine->systems->add('phlappy-phant.simulation', static function() use ($engine, &$game, &$page, &$counts, &$seenTaps): void {
	$taps = (int)(vrzno_env('phantTaps'))();
	$flap = $page === 'play' && ($engine->controls->pressed(PhantActions::FLAP) || $taps > $seenTaps);
	$seenTaps = $taps;
	$game->step($flap);

	foreach($game->consumeEvents() as $event) $counts[$event] = ($counts[$event] ?? 0) + 1;
});

$engine->setInputHandler(static function(
	Engine $engine
	, float $elapsed
) use (
	&$game, &$page, &$uiTime, &$setPage, &$submitted, $menu, $navigation, $bindings, $scene
): bool {
	$uiTime += min(.25, max(0, $elapsed));
	$action = (string)(vrzno_env('phantAction'))();

	if($page === 'play' && $game->finished)
	{
		if(!$submitted)
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

	if($action && isset($menu->rows()[$action]))
	{
		$menu->select($action);
		$menu->activate();
	}

	$bindings->theme = $engine->input->controllerTheme();
	$bindingCaptured = $bindings->update($menu, $engine->input->actions, $frame['focused']);

	if(!$bindingCaptured)
	$navigation->update($menu, $frame['buttons'], min(.25, max(0, $elapsed)), $frame['events'], $scene->regions);

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

	return true;
});

(vrzno_env('sceneReady'))(null);

$engine->start(static function(
	Engine $engine
	, float $alpha
) use (
	&$game, &$page, &$uiTime, &$runId, &$counts, &$seenTaps, $scene, $font, $menu, $best
): void {
	[$width, $height] = json_decode((string)(vrzno_env('phantViewport'))(), true);
	$engine->device->resize($width, $height);
	$engine->device->begin($scene->skyColor());
	glViewport(0, 0, $width, $height);
	$font->theme = $engine->input->inputTheme;
	$record = $best();
	$scene->render($game, $menu, $page, $width, $height, $alpha, $uiTime, $record);
	$next = $game->nextBarrel();

	(vrzno_env('phantStatus'))(json_encode([
		'page' => $page
		, 'phase' => $game->phase
		, 'score' => $game->score
		, 'best' => $record
		, 'finished' => $game->finished
		, 'palette' => $game->palette
		, 'y' => $game->y
		, 'vy' => $game->vy
		, 'barrels' => count($game->barrels)
		, 'nextGap' => $next ? [$next->x, $next->gapTop, $next->gap] : null
		, 'scroll' => $game->scroll
		, 'runId' => $runId
		, 'taps' => $seenTaps
		, 'counts' => $counts
		, 'viewport' => $scene->viewport
		, 'regions' => $scene->regions
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
