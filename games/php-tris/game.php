<?php
declare(strict_types=1);
use Phonics\Engine;
use Phonics\Asset\Resource;
use Phonics\Render\
{
	Device,BitmapFont
};
use Phonics\Ui\
{
	Menu,MenuInput,MenuRenderer,DomTextureModal
};
use PhpTris\
{
	Module,MatchState
};
use PhpTris\Render\Scene;

$catalog = json_decode((string)vrzno_env('phpTrisCatalog'), true, 512, JSON_THROW_ON_ERROR);
$mode = (string)vrzno_env('phpTrisStart');

if(!isset($catalog[$mode])) $mode = array_key_first($catalog);

$initialMode = $mode;
$initialRank = (int)vrzno_env('phpTrisStartRank') ?: 1;

$engine = new Engine(new Device(960, 540, 'PHPTris'));
$engine->entities = $phonics->entities;
$engine->systems = $phonics->systems;

// Keep menu confirmation separate from gameplay drop and rotation bindings.
foreach([
	0 => [SDLK_RETURN,SDLK_KP_ENTER]
	, 1 => []
	, 2 => []
	, 3 => []
	, 12 => []
	, 17 => [SDLK_UP,SDLK_x]
	, 18 => [SDLK_z,SDLK_LCTRL,SDLK_RCTRL]
	, 19 => [SDLK_SPACE]
	, 20 => [SDLK_c,SDLK_LSHIFT,SDLK_RSHIFT]
] as $button => $keys)
$engine->input->bindButton($button, $keys);

$font = new BitmapFont(
	$phonics->loader
	, Resource::framework('resources/font.tsj')
	, [Resource::framework('resources/buttons.tsj')]
);

$scene = new Scene($font);
$hint = new MenuRenderer($font);
$info = new DomTextureModal(vrzno_env('domTexture'), $hint);
$page = 'title';
$runId = 0;
$entity = null;
$match = null;
$menu = new Menu([], 'PHPTris');
$navigation = new MenuInput;
$setPage = null;
$startRun = null;

$newMatch = static function() use (&$entity, &$match, &$mode, &$runId, $phonics, $catalog, $initialMode, $initialRank):
void {
	if($entity !== null) $phonics->entities->destroy($entity);

	$rank = $mode === $initialMode ? $initialRank : 1;
	$properties = array_column($catalog[$mode][$rank]['properties'] ?? [], 'value', 'name');
	$seed = (int)($properties['seed'] ?? 1);
	$entity = $phonics->entities->create(...Module::components(['mode' => $mode,'seed' => $seed]));
	$match = MatchState::get($phonics->entities, $entity);
	$match->run->catalog = $catalog;
	$match->stage($catalog[$mode][$rank]);
	$runId++;
};

$newMatch();

$startRun = static function() use ($newMatch, &$page, $menu, $engine): void {
	$newMatch();
	$page = 'play';
	$menu->close();
	$engine->userPaused = false;
	$engine->input->suppressHeldUi();
};

$setPage = static function(
	string $value
) use (&$setPage, &$startRun, &$page, &$mode, &$match, $catalog, $menu, $engine, $info): void {
	$page = $value;
	$items = [];

	if($value === 'title')
	{
		$items = [
			'start' => [
				'label' => 'Start Game'
				, 'callback' => static function() use (&$startRun): void {
					$startRun();
				}
			]
			, 'modes' => [
				'label' => 'Choose Mode'
				, 'callback' => static function() use (&$setPage): void {
					$setPage('modes');
				}
			]
			, 'help' => [
				'label' => 'How to Play'
				, 'callback' => static function() use (&$setPage): void {
					$setPage('help');
				}
			]
			, 'info' => [
				'label' => 'PHP Info'
				, 'callback' => static function() use ($info): void {
					ob_start();
					phpinfo();
					$info->show('PHP Info', (string)ob_get_clean());
				}
			]
		];
	}
	elseif($value === 'modes')
	{
		foreach($catalog as $key => $ranks)
		$items[$key] = [
			'label' => Module::MODES[$key]
			, 'callback' => static function() use (&$mode, $key, &$setPage): void {
				$mode = $key;
				$setPage('title');
			}
		];
	}
	elseif($value === 'pause')
	{
		$items['resume'] = [
			'label' => 'Resume'
			, 'callback' => static function() use (&$page, $engine, $menu): void {
				$page = 'play';
				$menu->close();
				$engine->userPaused = false;
				$engine->input->suppressHeldUi();
			}
		];

		$items['restart'] = [
			'label' => 'Restart'
			, 'callback' => static function() use (&$startRun): void {
				$startRun();
			}
		];
	}
	elseif($value === 'results')
	$items['restart'] = [
		'label' => 'Play Again'
		, 'callback' => static function() use (&$startRun): void {
			$startRun();
		}
	];

	if($value !== 'title')
	$items['back'] = [
		'label' => 'Back to Title'
		, 'callback' => static function() use (&$setPage): void {
			$setPage('title');
		}
	];

	$menu->setItems($items);
	$menu->open();
	$engine->userPaused = true;
	$match->input->clear();
	$engine->input->suppressHeldUi();
};

$setPage('title');
$pauseHeld = false;
$inputGeneration = -1;

$engine->setInputHandler(
	static function(
		Engine $engine
		, float $elapsed
	) use (&$match, &$page, $menu, $navigation, $info, &$setPage, &$pauseHeld, &$inputGeneration): bool {
		$action = (string)(vrzno_env('phpTrisAction'))();
		$touch = json_decode((string)(vrzno_env('phpTrisTouch'))(), true);

		if($info->isOpen())
		{
			if($action === 'close-info')
			{
				$info->close();
				$engine->input->suppressHeldUi();
			}
			else $info->update($engine, $elapsed, $menu);

			$engine->userPaused = true;

			return true;
		}

		if(in_array($match->run->phase, ['gameover','complete','finished'], true) && $page === 'play')
		$setPage('results');

		$raw = $engine->input->snapshot();
		$pause = !empty($raw['button9']);

		if($page === 'play' && (($pause && !$pauseHeld) || $action === 'pause'))
		{
			$setPage('pause');
			$pauseHeld = $pause;
			$engine->input->suppressHeldUi();

			return true;
		}

		$pauseHeld = $pause;

		if($page !== 'play')
		{
			if($action && isset($menu->rows()[$action]))
			{
				$menu->select($action);
				$menu->activate();
			}

			$ui = $engine->input->uiFrame(false, true);
			$buttons = $ui['buttons'];
			$buttons['up'] = ($buttons['up'] ?? false) || !empty($raw['button17']);
			$buttons['confirm'] = ($buttons['confirm'] ?? false) || !empty($raw['button19']) || ($buttons['pause']
				?? false
			);

			$buttons['pause'] = false;

			if(($buttons['back'] ?? false) && $page !== 'title') $setPage('title');
			else $navigation->update($menu, $buttons, min(.25, max(0, $elapsed)), $ui['events']);

			return true;
		}

		$held = [];

		foreach([
			'left' => [14]
			, 'right' => [15]
			, 'soft' => [13]
			, 'cw' => [0,17]
			, 'ccw' => [1,18]
			, 'drop' => [2,12,19]
			, 'hold' => [3,20]
		] as $key => $indices)
		{
			$held[$key] = !empty($touch[$key]);

			foreach($indices as $index) $held[$key] = $held[$key] || !empty($raw['button' . $index]);
		}

		$match->input->sample($held);

		if($inputGeneration !== $engine->input->generation)
		{
			$inputGeneration = $engine->input->generation;
			$match->input->clear();
		}

		$engine->userPaused = false;

		return false;
	}
);

(vrzno_env('sceneReady'))(null);

$engine->start(static function() use ($engine, $scene, $font, $info, $menu, &$match, &$page, &$runId, &$mode): void {
	[$w,$h,$dpr,$touch] = json_decode((string)(vrzno_env('phpTrisViewport'))(), true);

	$engine->device->resize($w, $h);

	$engine->device->begin([.03,.025,.05,1]);
	glViewport(0, 0, $w, $h);
	$font->theme = $engine->input->inputTheme;

	$scene->render(
		$match
		, $w
		, $h
		, $dpr
		, $touch
		, $page
		, $menu
		, (string)(vrzno_env('phpTrisBest'))($match->run->mode)
		, $mode
	);

	$info->render($w, $h, max(2, (int)round(2 * $dpr)));
	$s = $match->run;

	(vrzno_env('phpTrisStatus'))(json_encode([
		'page' => $page
		, 'mode' => $s->mode
		, 'phase' => $s->phase
		, 'score' => $s->score
		, 'lines' => $s->lines
		, 'level' => $s->level
		, 'goal' => $s->goal
		, 'elapsed' => $s->elapsed
		, 'placed' => $s->placed
		, 'seed' => $s->seed
		, 'events' => $s->events
		, 'active' => (array)$match->active
		, 'queue' => array_slice($match->queue->items, 0, 6)
		, 'held' => $match->queue->held
		, 'cells' => count($match->board->cells)
		, 'regions' => $scene->regions
		, 'layout' => $scene->snapshot
		, 'width' => $w
		, 'height' => $h
		, 'info' => $info->state()
		, 'runId' => $runId
		, 'selection' => $menu->selected
	]));

	$s->events = [];
}, static function() use ($scene, $hint, $info, $menu, $phonics): void {
	$info->close();
	$menu->close();
	$scene->close();
	$hint->close();
	$phonics->close();
}, static function() use ($scene, $hint): void {
	$scene->restore();
	$hint->restore();
});
