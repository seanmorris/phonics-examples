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
use TerrainSandbox\Session;

$app = new Context;
$app->browser(__DIR__ . '/browser/game.mjs');
$session = new Session;
$engine = new Engine(new Device(1280, 720, 'Landscape Sandbox'));
$engine->clock = new FixedStep(1 / 60, 4);
// The authoring bridge owns pause, avoiding a second toggle from SDL's default P/Escape mapping.
$engine->input->bindButton(9, []);
$scene = new Scene($session->landscape, $session->water, $session->atmosphere);
$width = 1280; $height = 720; $dirty = true;
$generation = $session->generation;
$lastStatus = 0.0;
$lastRender = 0.0;
$lastRenderedTime = -1.0;
$renderMs = 0.0;
$frameSeconds = 0.0;
$lastBudgetPose = null;

$engine->systems->add('terrain-sandbox.simulate', static function(Registry $registry, float $seconds) use ($session): void {
	$session->step($seconds);
});

$engine->setInputHandler(static function(Engine $engine, float $elapsed) use (
	$session, $scene, &$width, &$height, &$dirty, &$generation, &$frameSeconds
): bool {
	$frameSeconds = min(.1, max(0, $elapsed));
	$viewport = json_decode((string)(vrzno_env('terrainViewport'))(), true, flags: JSON_THROW_ON_ERROR);
	$nextWidth = max(1, min(3840, (int)($viewport[0] ?? 1280)));
	$nextHeight = max(1, min(2160, (int)($viewport[1] ?? 720)));
	if($nextWidth !== $width || $nextHeight !== $height) $dirty = true;
	[$width,$height] = [$nextWidth,$nextHeight];
	$prepared = $scene->takePreparedLandscape();
	if($prepared !== null) $session->publishLandscape($prepared);
	$input = json_decode((string)(vrzno_env('terrainInput'))(), true, flags: JSON_THROW_ON_ERROR);
	$session->input(is_array($input) ? $input : [], $frameSeconds, $width / max(1, $height));
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

$engine->start(
	static function(Engine $engine, float $alpha) use (
		$session, $scene, &$width, &$height, &$dirty, &$lastStatus, &$lastRender, &$lastRenderedTime,
		&$renderMs, &$frameSeconds, &$lastBudgetPose
	): void {
		$pose = [$session->camera->position,$session->camera->yaw,$session->camera->pitch,$session->camera->fov];
		$scene->budgetMs = $session->mode === 'craft' || $pose !== $lastBudgetPose ? 2.0 : 8.0;
		$lastBudgetPose = $pose;
		$prepared = $scene->update($frameSeconds, $session->camera);
		$wavesChanged = $scene->stats()['visible'] > 0 && $session->time !== $lastRenderedTime;
		$dirty = $dirty || $prepared || $session->dirty || $wavesChanged;
		$now = microtime(true);
		if($dirty && $now - $lastRender >= 1 / 65)
		{
			$start = microtime(true);
			$engine->device->resize($width, $height);
			$engine->device->begin([.15,.25,.35,1]);
			$scene->render($session->camera, $width, $height, $session->time,
				$session->mode === 'craft' ? $session->craft->state() : null);
			$engine->device->present();
			$renderMs = (microtime(true) - $start) * 1000;
			$lastRender = $now;
			$lastRenderedTime = $session->time;
			$dirty = $session->dirty = false;
		}
		if($now - $lastStatus >= .2)
		{
			$status = $session->status() + ['renderer' => $scene->stats(),'renderMs' => $renderMs,
				'viewport' => [$width,$height],'simulationMs' => $engine->simulationCpuMs,
				'generationBudgetMs' => $scene->budgetMs];
			(vrzno_env('terrainStatus'))(json_encode($status, JSON_THROW_ON_ERROR));
			$lastStatus = $now;
		}
	},
	static function() use ($engine, $session, $scene): void {
		$engine->systems->remove('terrain-sandbox.simulate');
		$scene->close(); $session->close();
	},
	static function() use ($scene, &$dirty): void { $scene->restore(); $dirty = true; }
);

$app->ready();
