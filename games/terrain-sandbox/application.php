<?php
declare(strict_types=1);

$archive = 'phar://' . Phar::running(false) . '/';
require_once $archive . 'core/autoload.php';
require_once $archive . 'dependencies/terrain-phonics/autoload.php';
require_once __DIR__ . '/autoload.php';

use Phonics\Application\Context;
use Phonics\Ecs\Registry;
use Phonics\Engine;
use Phonics\FixedStep;
use Phonics\Render\Device;
use TerrainPhonics\Scene;
use TerrainPhonics\BrowserPatchBuilder;
use TerrainSandbox\VehicleModel;
use TerrainSandbox\Session;

$app = new Context;
$app->browser(__DIR__ . '/browser/game.mjs');
$app->browser($archive . 'dependencies/terrain-phonics/browser/PatchMesher.mjs');
$session = new Session;
$engine = new Engine(new Device(1280, 720, 'Landscape Sandbox'));
$engine->clock = new FixedStep(1 / 60, 4);
// The authoring bridge owns pause, avoiding a second toggle from SDL's default P/Escape mapping.
$engine->input->bindButton(9, []);
$batch = vrzno_env('terrainPatchBatch');
$builder = new BrowserPatchBuilder(static fn($action, $path, $key, $payload) => $batch($action, $path, $key, $payload));
$scene = new Scene($session->landscape, $session->water, $session->atmosphere, $builder);
$models = [];
$width = 1280; $height = 720; $dirty = true;
$generation = $session->generation;
$lastStatus = 0.0;
$lastRender = null;
$drawIntervalMs = null;
$lastRenderedTime = -1.0;
$renderMs = 0.0;
$frameSeconds = 0.0;
$lastBudgetPose = null;
$heldKeys = [];
$statusPending = true;
$urgentStatus = false;
$inputMs = 0.0;
$pickFrame = false;
$readInput = vrzno_env('terrainInput');
$publish = vrzno_env('terrainStatus');

$engine->systems->add('terrain-sandbox.simulate', static function(Registry $registry, float $seconds) use ($session): void {
	$session->step($seconds);
});

$engine->setInputHandler(static function(Engine $engine, float $elapsed) use (
	$session, $scene, &$width, &$height, &$dirty, &$generation, &$frameSeconds,
	&$heldKeys, &$statusPending, &$urgentStatus, &$inputMs, &$pickFrame, $readInput
): bool {
	$frameSeconds = max(0.0, $elapsed);
	$started = hrtime(true);
	$packet = (string)$readInput();
	$input = $packet === '' ? [] : json_decode($packet, true, flags: JSON_THROW_ON_ERROR);
	if(isset($input['viewport']))
	{
		[$w,$h] = $input['viewport'];
		$width = max(1, min(3840, (int)$w));
		$height = max(1, min(2160, (int)$h));
		$dirty = true;
	}
	$prepared = $scene->takePreparedLandscape();
	if($prepared !== null) $session->publishLandscape($prepared);
	$wasPicking = $session->picking;
	$wasSearching = $session->findingCarSite();
	$previousRoadPoints = count($session->road);
	$keysChanged = isset($input['keys']) && $heldKeys !== $input['keys'];
	if($keysChanged) $heldKeys = $input['keys'];
	if($packet !== '' || $heldKeys || $wasPicking || $wasSearching)
	{
		$input['keys'] = $heldKeys;
		$moving = $heldKeys || $session->mode === 'craft' || !empty($input['look'][0]) || !empty($input['look'][1]);
		$session->input($input, min(.1, $frameSeconds), $width / $height, $moving ? 1.0 : 7.5);
	}
	$urgentStatus = $urgentStatus || $keysChanged || isset($input['clicks'][0]) || $wasPicking !== $session->picking
		|| $previousRoadPoints !== count($session->road) || $wasSearching !== $session->findingCarSite();
	$pickFrame = $wasPicking || $session->picking;
	$statusPending = $statusPending || $packet !== '' || $wasPicking || $wasSearching || $prepared !== null;
	$inputMs = (hrtime(true) - $started) / 1e6;
	if($generation !== $session->generation)
	{
		$scene->setLandscape($session->landscape);
		$generation = $session->generation;
		$dirty = true;
	}
	$candidate = $session->takeCandidate();
	if($candidate !== null) $scene->prepareLandscape($candidate);
	$scene->quality = $session->settings['quality'];
	$engine->userPaused = $session->paused;
	if(($download = $session->download()) !== null) (vrzno_env('terrainDownload'))($download);
	$dirty = $dirty || $session->dirty;
	return false;
});

$drawVehicle = static function() use ($session, &$models, &$width, &$height): void {
	$car = $session->mode === 'car';
	$name = $car ? 'testarossa' : 'jetski';
	$model = $models[$name] ??= new VehicleModel(__DIR__ . '/assets/' . $name . '/mesh.bin.gz');
	$model->draw($car ? $session->car->state() : $session->craft->state(),
		$session->camera, $session->atmosphere, $session->water, $width, $height);
};

$engine->start(
	static function(Engine $engine, float $alpha) use (
		$session, $scene, $drawVehicle, &$width, &$height, &$dirty, &$lastStatus, &$lastRender, &$drawIntervalMs, &$lastRenderedTime,
		&$renderMs, &$frameSeconds, &$lastBudgetPose, &$statusPending, &$urgentStatus, &$inputMs, &$pickFrame, $publish
	): void {
		$pose = [$session->camera->position,$session->camera->yaw,$session->camera->pitch,$session->camera->fov];
		$moving = $session->mode === 'craft' || $pose !== $lastBudgetPose;
		$budget = $moving ? 2.0 : 8.0;
		$scene->budgetMs = max(.25, $budget - $inputMs);
		$lastBudgetPose = $pose;
		$work = $scene->dirty() || $moving || $dirty || $session->dirty;
		// A stationary click gets the generation slice until its confirmed marker is published.
		if($pickFrame && !$moving && !$dirty) $work = false;
		$prepared = $work && $scene->update($frameSeconds, $session->camera);
		$wavesChanged = $scene->showsWater() && $session->time !== $lastRenderedTime;
		$statusPending = $statusPending || $work || $wavesChanged || !$session->paused;
		$dirty = $dirty || $prepared || $session->dirty || $wavesChanged;
		$now = microtime(true);
		if($dirty)
		{
			$start = microtime(true);
			$engine->device->resize($width, $height);
			$engine->device->begin([.15,.25,.35,1]);
			$scene->render($session->camera, $width, $height, $session->time,
				drawOpaque: $session->mode === 'fly' ? null : $drawVehicle);
			$engine->device->present();
			$renderMs = (microtime(true) - $start) * 1000;
			$drawIntervalMs = $lastRender === null ? null : ($now - $lastRender) * 1000;
			$lastRender = $now;
			$lastRenderedTime = $session->time;
			$dirty = $session->dirty = false;
		}
		if($urgentStatus || $statusPending && $now - $lastStatus >= .2)
		{
			$status = $session->status() + ['renderer' => $scene->stats(),'renderMs' => $renderMs, 'drawIntervalMs' => $drawIntervalMs,
				'viewport' => [$width,$height],'simulationMs' => $engine->simulationCpuMs,
				'generationBudgetMs' => $budget,'inputMs' => $inputMs,'frameIntervalMs' => $frameSeconds*1000];
			$publish(json_encode($status, JSON_THROW_ON_ERROR));
			$lastStatus = $now;
			$statusPending = $urgentStatus = false;
		}
	},
	static function() use ($engine, $session, $scene, &$models): void {
		$engine->systems->remove('terrain-sandbox.simulate');
		foreach($models as $model) $model->close();
		$scene->close(); $session->close();
	},
	static function() use ($scene, &$models, &$dirty): void {
		foreach($models as $model) $model->close();
		$models = [];
		$scene->restore();
		$dirty = true;
	}
);

$app->ready();
