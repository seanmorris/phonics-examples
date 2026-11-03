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
use PhpBuster\Input\BusterActions;
use PhpBuster\Input\InputSettings;
use PhpBuster\Render\Scene;
use PhpBuster\Simulation;

$engine = new Engine(new Device(800, 720, 'PHP Buster'));
$engine->entities = $phonics->entities;
$engine->systems = $phonics->systems;
$engine->clock = new FixedStep(Simulation::STEP, 5);

$settings = new InputSettings(
	static fn(): string => (string)(vrzno_env('busterStorageRead'))()
	, static fn(string $json): bool => (bool)(vrzno_env('busterStorageWrite'))($json)
);

$engine->input->useBindings($settings->bindings);

$font = new BitmapFont(
	$phonics->loader
	, Resource::framework('resources/font.tsj')
	, [Resource::framework('resources/buttons.tsj')]
);

$scene = new Scene($font, $phonics->loader->map(__DIR__ . '/worlds/buster.tmj'));
$menu = new Menu([], 'PHP BUSTER');
$navigation = new MenuInput;

$bindings = new BindingMenu(
	$settings->bindings
	, BusterActions::rows()
	, $settings->save(...)
	, $settings->reset(...)
	, BusterActions::allowed(...)
	, status: static fn(): string => $settings->sessionOnly ? 'Changes apply to this session' : ''
);

$game = new Simulation(1);
$page = 'title';
$uiTime = 0.0;
$runId = 0;
$explosions = 0;
$setPage = null;
$startGame = null;
$titleItems = null;

$startGame = static function() use (
	&$game, &$page, &$runId, &$explosions, $menu, $engine
): void {
	$seed = (int)(vrzno_env('busterSeed'))();
	$game = new Simulation($seed);
	$runId++;
	$explosions = 0;
	$page = 'play';
	$menu->close();
	$engine->userPaused = false;
	$engine->input->reset();
	$engine->input->suppressHeldUi();
};

$scoreItems = static function(): array {
	$data = json_decode((string)(vrzno_env('busterScores'))(), true);
	$rows = is_array($data) ? array_slice($data, 0, 10) : [];
	$items = [];

	foreach($rows as $index => $score)
	{
		$initials = strtoupper(substr(preg_replace('/[^A-Z0-9]/', '', (string)($score['initials'] ?? 'AAA')), 0, 3));

		$items['score-' . $index] = [
			'label' => sprintf(
				'%2d. %-3s %08d'
				, $index + 1
				, str_pad($initials, 3, 'A')
				, max(0, (int)($score['score'] ?? 0))
			)
			, 'disabled' => true
		];
	}

	if(!$items) $items['empty'] = ['label' => 'No local scores yet', 'disabled' => true];

	return $items;
};

$titleItems = static function() use (&$startGame, $scoreItems, $bindings): array {
	return [
		'new-game' => [
			'label' => 'New game'
			, 'callback' => static function() use (&$startGame): void { $startGame(); }
		]
		, 'scores' => ['label' => 'High scores', 'children' => $scoreItems]
		, 'controls' => $bindings->item('Input settings')
		, 'help' => [
			'label' => 'How to play'
			, 'children' => [
				'move' => ['label' => 'Move through the blockade.', 'disabled' => true]
				, 'fire' => ['label' => 'Hold Fire to shoot.', 'disabled' => true]
				, 'ruby' => ['label' => 'Ruby upgrades weapons.', 'disabled' => true]
				, 'shield' => ['label' => 'Shield restores one hit.', 'disabled' => true]
			]
		]
		, 'credits' => [
			'label' => 'About PHP Buster'
			, 'children' => [
				'title' => ['label' => 'PHP BUSTER', 'disabled' => true]
				, 'based-on' => ['label' => 'Based on Blockade Buster', 'disabled' => true]
				, 'original' => ['label' => 'Blockade Buster programmed by Greg Troutman', 'disabled' => true]
				, 'graphics' => ['label' => "Graphics courtesy of Ari Feldman's SpriteLib", 'disabled' => true]
				, 'port' => ['label' => 'PHP port built with Phonics', 'disabled' => true]
			]
		]
	];
};

$setPage = static function(
	string $next
) use (
	&$setPage, &$startGame, &$page, &$game, $titleItems, $scoreItems, $menu, $engine
): void {
	$page = $next;
	$menu->close();
	$menu->title = match($next)
	{
		'title' => 'PHP BUSTER'
		, 'pause' => 'PAUSED'
		, 'initials' => 'GAME OVER'
		, 'scores' => 'HIGH SCORES'
		, default => 'PHP BUSTER'
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
			'label' => 'Restart game'
			, 'callback' => static function() use (&$startGame): void { $startGame(); }
		]
		, 'title' => [
			'label' => 'Return to title'
			, 'callback' => static function() use (&$setPage): void { $setPage('title'); }
		]
	];
	elseif($next === 'initials')
	{
		$letters = array_merge(range('A', 'Z'), range('0', '9'));
		$items = [
			'score' => ['label' => 'Score', 'value' => (string)$game->score, 'disabled' => true]
			, 'initial-1' => ['label' => 'Initial 1', 'input' => 'select', 'options' => $letters, 'value' => 'A']
			, 'initial-2' => ['label' => 'Initial 2', 'input' => 'select', 'options' => $letters, 'value' => 'A']
			, 'initial-3' => ['label' => 'Initial 3', 'input' => 'select', 'options' => $letters, 'value' => 'A']
			, 'save' => [
				'label' => 'Save score'
				, 'callback' => static function() use (&$setPage, &$game, $menu): void {
					$initials = (string)$menu->value('initial-1')
						. (string)$menu->value('initial-2') . (string)$menu->value('initial-3');

					(vrzno_env('busterSubmitScore'))($initials, $game->score);
					$setPage('scores');
				}
			]
			, 'skip' => [
				'label' => 'Return to title'
				, 'callback' => static function() use (&$setPage): void { $setPage('title'); }
			]
		];
	}
	else
	$items = array_merge($scoreItems(), [
		'title' => [
			'label' => 'Return to title'
			, 'callback' => static function() use (&$setPage): void { $setPage('title'); }
		]
	]);

	$menu->setItems($items);
	$menu->open();
	$engine->userPaused = true;
	$engine->input->suppressHeldUi();
};

$setPage('title');

$engine->systems->add('php-buster.simulation', static function() use (
	$engine, &$game, &$explosions
): void {
	$pointer = json_decode((string)(vrzno_env('busterPointer'))(), true);
	$active = is_array($pointer) && !empty($pointer['active']);
	$pointerX = $active ? (float)($pointer['x'] ?? NAN) : null;
	$pointerY = $active ? (float)($pointer['y'] ?? NAN) : null;
	$fire = $engine->controls->down(BusterActions::FIRE) || !empty($pointer['fire']);

	$game->step(
		$engine->controls->value(BusterActions::MOVE_X)
		, $engine->controls->value(BusterActions::MOVE_Y)
		, $fire
		, $pointerX
		, $pointerY
	);

	foreach($game->consumeEvents() as $event) if($event === 'explosion') $explosions++;
});

$engine->setInputHandler(static function(
	Engine $engine
	, float $elapsed
) use (
	&$game, &$page, &$uiTime, &$setPage, $menu, $navigation, $bindings, $scene
): bool {
	$uiTime += min(.25, max(0, $elapsed));
	$action = (string)(vrzno_env('busterAction'))();

	if($page === 'play' && $game->phase === 'gameover')
	{
		$setPage('initials');

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

	$engine->userPaused = true;

	return true;
});

(vrzno_env('sceneReady'))(null);

$engine->start(static function(
	Engine $engine
	, float $alpha
) use (
	&$game, &$page, &$uiTime, &$runId, &$explosions, $scene, $font, $menu
): void {
	[$width,$height] = json_decode((string)(vrzno_env('busterViewport'))(), true);
	$engine->device->resize($width, $height);
	$engine->device->begin([0,0,0,1]);
	glViewport(0, 0, $width, $height);
	$font->theme = $engine->input->inputTheme;
	$scene->render($game, $menu, $page, $width, $height, $alpha, $uiTime);

	(vrzno_env('busterStatus'))(json_encode([
		'page' => $page
		, 'score' => $game->score
		, 'level' => $game->level
		, 'stage' => $game->stage
		, 'shields' => $game->shields
		, 'phase' => $game->phase
		, 'shots' => $game->shots
		, 'actors' => count($game->actors)
		, 'runId' => $runId
		, 'explosions' => $explosions
		, 'player' => [$game->player->x + $game->player->width / 2, $game->player->y + $game->player->height / 2]
		, 'regions' => $scene->regions
		, 'viewport' => $scene->viewport
		, 'width' => $width
		, 'height' => $height
	]));
}, static function() use ($engine, $scene, $menu, $phonics): void {
	$menu->close();
	$scene->close();
	$engine->systems->remove('php-buster.simulation');
	$phonics->close();
}, static function() use ($scene): void {
	$scene->restore();
});
