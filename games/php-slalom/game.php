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
use PhpSlalom\Input\InputSettings;
use PhpSlalom\Input\SlalomActions;
use PhpSlalom\Render\Scene;
use PhpSlalom\Replay;
use PhpSlalom\Simulation;

$engine = new Engine(new Device(960, 600, 'PHP Slalom'));
$engine->entities = $phonics->entities;
$engine->systems = $phonics->systems;
$engine->clock = new FixedStep(Simulation::STEP, 5);

$settings = new InputSettings(
	static fn(): string => (string)(vrzno_env('slalomStorageRead'))()
	, static fn(string $json): bool => (bool)(vrzno_env('slalomStorageWrite'))($json)
);

$engine->input->useBindings($settings->bindings);

$font = new BitmapFont(
	$phonics->loader
	, Resource::framework('resources/font.tsj')
	, [Resource::framework('resources/buttons.tsj')]
);

$scene = new Scene($font, $phonics->loader->map(__DIR__ . '/worlds/slalom.tmj'));
$menu = new Menu([], 'JET SLALOM');
$navigation = new MenuInput;

$bindings = new BindingMenu(
	$settings->bindings
	, SlalomActions::rows()
	, $settings->save(...)
	, $settings->reset(...)
	, SlalomActions::allowed(...)
	, status: static fn(): string => $settings->sessionOnly ? 'Changes apply to this session' : ''
);

$game = new Simulation(1);
$page = 'title';
$inputs = [];
$runId = 0;
$continued = false;
$replayMode = false;
$replayInputs = [];
$replayIndex = 0;
$runStage = 0;
$result = null;
$uiTime = 0.0;
$setPage = null;
$startRun = null;
$titleItems = null;

$startRun = static function(
	int $stage = 0
	, ?array $replay = null
) use (
	&$game, &$page, &$inputs, &$runId, &$continued, &$replayMode, &$replayInputs, &$replayIndex
	, &$runStage
	, &$result, $menu, $engine
): void {
	$seed = $replay['seed'] ?? (int)(vrzno_env('slalomSeed'))();
	$stage = $replay['stage'] ?? $stage;
	$game = new Simulation($seed, $stage);
	$runStage = $stage;
	$inputs = [];
	$continued = $stage > 0;
	$replayMode = $replay !== null;
	$replayInputs = $replay['inputs'] ?? [];
	$replayIndex = 0;
	$result = null;
	$runId++;
	$page = 'play';
	$menu->close();
	$engine->userPaused = false;
	$engine->input->reset();
	$engine->input->suppressHeldUi();
};

$scoreItems = static function(): array {
	$data = json_decode((string)(vrzno_env('slalomScores'))(), true);
	$items = [];

	foreach(['fresh' => 'Fresh runs','continued' => 'Checkpoint runs'] as $kind => $label)
	{
		$items[$kind . '-heading'] = ['label' => $label, 'disabled' => true];
		$rows = is_array($data[$kind] ?? null) ? $data[$kind] : [];

		foreach(array_slice($rows, 0, 10) as $index => $score)
		$items[$kind . '-' . $index] = [
			'label' => sprintf('%2d. %d', $index + 1, (int)$score)
			, 'disabled' => true
		];

		if(!$rows) $items[$kind . '-empty'] = ['label' => 'No scores yet', 'disabled' => true];
	}

	return $items;
};

$watchReplay = static function(string $base64) use (&$startRun, $menu): void {
	try
	{
		$bytes = base64_decode($base64, true);

		if($bytes === false) throw new UnexpectedValueException('Replay data is not valid base64');

		$startRun(0, Replay::decode($bytes));
	} catch(Throwable $error)
	{
		$menu->error = 'Could not load replay: ' . $error->getMessage();
	}
};

$titleItems = static function() use (
	&$startRun, $watchReplay, $scoreItems, $bindings
): array {
	$checkpoints = [];

	foreach(Simulation::START_SCORES as $stage => $score)
	$checkpoints['stage-' . ($stage + 1)] = [
		'label' => 'Stage ' . ($stage + 1) . ' — ' . $score
		, 'callback' => static function() use (&$startRun, $stage): void { $startRun($stage); }
	];

	return [
		'new-game' => [
			'label' => 'New game'
			, 'callback' => static function() use (&$startRun): void { $startRun(); }
		]
		, 'checkpoint' => ['label' => 'Stage checkpoint', 'children' => $checkpoints]
		, 'replays' => [
			'label' => 'Replays'
			, 'children' => static fn(): array => [
				'watch-last' => [
					'label' => 'Watch last replay'
					, 'callback' => static fn() => $watchReplay((string)(vrzno_env('slalomLastReplay'))())
				]
				, 'import' => [
					'label' => 'Import replay'
					, 'callback' => static fn() => (vrzno_env('slalomRequestReplayImport'))()
				]
				, 'export' => [
					'label' => 'Export last replay'
					, 'callback' => static fn() => (vrzno_env('slalomExportLast'))()
				]
			]
		]
		, 'scores' => ['label' => 'High scores', 'children' => $scoreItems]
		, 'controls' => $bindings->item('Input settings')
		, 'help' => [
			'label' => 'How to play'
			, 'children' => [
				'goal' => ['label' => 'Thread the gaps and survive.', 'disabled' => true]
				, 'steer' => ['label' => 'Steer left or right.', 'disabled' => true]
				, 'pace' => ['label' => 'The course changes as score rises.', 'disabled' => true]
			]
		]
		, 'credits' => [
			'label' => 'Credits'
			, 'children' => [
				'original' => ['label' => 'Original Jet Slalom programmed by MR-C.', 'disabled' => true]
				, 'port' => ['label' => 'PHP port built with Phonics.', 'disabled' => true]
			]
		]
	];
};

$setPage = static function(
	string $next
) use (
	&$setPage, &$startRun, &$page, &$game, &$inputs, &$result, &$runStage, &$replayMode
	, $titleItems, $menu, $engine
): void {
	$page = $next;
	$menu->close();
	$menu->title = match($next)
	{
		'title' => 'JET SLALOM', 'pause' => 'PAUSED', 'results' => 'RUN OVER', default => 'JET SLALOM'
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
			'label' => 'Restart run'
			, 'callback' => static function() use (&$startRun, &$runStage): void { $startRun($runStage); }
		]
		, 'title' => [
			'label' => 'Return to title'
			, 'callback' => static function() use (&$setPage): void { $setPage('title'); }
		]
	];
	else
	$items = [
		'score' => ['label' => 'Score', 'value' => (string)$game->score, 'disabled' => true]
		, 'again' => [
			'label' => 'Play again'
			, 'callback' => static function() use (&$startRun): void { $startRun(); }
		]
		, 'continue' => [
			'label' => 'Continue from stage ' . ($game->stage + 1)
			, 'callback' => static function() use (&$startRun, &$game): void { $startRun($game->stage); }
		]
		, 'watch' => [
			'label' => 'Watch this replay'
			, 'disabled' => $replayMode || !$result
			, 'callback' => static function() use (&$startRun, &$result): void {
				if($result) $startRun(0, Replay::decode(base64_decode($result['replay'], true)));
			}
		]
		, 'export' => [
			'label' => 'Export replay'
			, 'disabled' => $replayMode || !$result
			, 'callback' => static fn() => (vrzno_env('slalomExportLast'))()
		]
		, 'title' => [
			'label' => 'Return to title'
			, 'callback' => static function() use (&$setPage): void { $setPage('title'); }
		]
	];

	$menu->setItems($items);
	$menu->open();
	$engine->userPaused = true;
	$engine->input->suppressHeldUi();
};

$setPage('title');

$engine->systems->add('php-slalom.simulation', static function() use (
	$engine, &$game, &$inputs, &$replayMode, &$replayInputs, &$replayIndex
): void {
	if($replayMode)
	{
		$bits = $replayInputs[$replayIndex++] ?? 0;
		$left = (bool)($bits & 1);
		$right = (bool)($bits & 2);
	} else
	{
		$touch = json_decode((string)(vrzno_env('slalomTouch'))(), true);
		$left = $engine->controls->down(SlalomActions::LEFT) || !empty($touch['left']);
		$right = $engine->controls->down(SlalomActions::RIGHT) || !empty($touch['right']);
		$bits = ($left ? 1 : 0) | ($right ? 2 : 0);
		$inputs[] = $bits;
	}

	$game->step($left, $right);
});

$engine->setInputHandler(static function(
	Engine $engine
	, float $elapsed
) use (
	&$game, &$page, &$inputs, &$runId, &$continued, &$replayMode, &$result, &$uiTime, &$setPage
	, &$runStage
	, $watchReplay, $menu, $navigation, $bindings, $scene
): bool {
	$uiTime += min(.25, max(0, $elapsed));
	$action = (string)(vrzno_env('slalomAction'))();
	$import = (string)(vrzno_env('slalomReplayImport'))();

	if($import !== '') $watchReplay($import);

	if($page === 'play' && $game->finished)
	{
		if(!$replayMode)
		{
			$result = [
				'runId' => $runId
				, 'score' => $game->score
				, 'continued' => $continued
				, 'replay' => base64_encode(
					Replay::encode($runStage, $game->seed, Simulation::START_SCORES[$runStage], $inputs)
				)
			];
		}

		$setPage('results');

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

		if($page === 'play')
		{
			$engine->userPaused = false;

			return true;
		}
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
	&$game, &$page, &$result, &$uiTime, &$runId, &$replayMode, $scene, $font, $menu
): void {
	[$width,$height,$dpr,$touch] = json_decode((string)(vrzno_env('slalomViewport'))(), true);
	$engine->device->resize($width, $height);
	$sky = array_map(static fn(int $channel): float => $channel / 255, $game->sky());
	$engine->device->begin([...$sky,1]);
	glViewport(0, 0, $width, $height);
	$font->theme = $engine->input->inputTheme;
	$scene->render($game, $menu, $page, $width, $height, $alpha, $uiTime, $touch);

	(vrzno_env('slalomStatus'))(json_encode([
		'page' => $page
		, 'score' => $game->score
		, 'stage' => $game->stage + 1
		, 'crashed' => $game->crashed
		, 'finished' => $game->finished
		, 'replay' => $replayMode
		, 'runId' => $runId
		, 'result' => $result
		, 'regions' => $scene->regions
		, 'viewport' => $scene->viewport
		, 'width' => $width
		, 'height' => $height
	]));
}, static function() use ($engine, $scene, $menu, $phonics): void {
	$menu->close();
	$scene->close();
	$engine->systems->remove('php-slalom.simulation');
	$phonics->close();
}, static function() use ($scene): void {
	$scene->restore();
});
