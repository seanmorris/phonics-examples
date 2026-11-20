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
use TerrainSandbox\GateModel;
use TerrainSandbox\FlightMap;
use TerrainSandbox\GroundShadow;
use TerrainSandbox\VehicleModel;
use TerrainSandbox\TracerBatch;
use TerrainSandbox\TrailRenderer;
use TerrainSandbox\Session;

$app = new Context;
$app->browser(__DIR__ . '/browser/game.mjs');
$app->browser(__DIR__ . '/browser/flight-map.mjs');
$app->browser(__DIR__ . '/browser/cockpit.mjs');
$app->browser($archive . 'dependencies/terrain-phonics/browser/PatchMesher.mjs');
$session = new Session;
$engine = new Engine(new Device(1280, 720, 'Landscape Sandbox'));
$engine->clock = new FixedStep(1 / 60, 4);
// The authoring bridge owns pause, avoiding a second toggle from SDL's default P/Escape and Start mappings.
// The page reads gamepads itself and sends Start as the sandbox's own pause action.
$engine->input->bindButton(9, []);
$engine->input->bindGamepad(9, []);
$batch = vrzno_env('terrainPatchBatch');
$builder = new BrowserPatchBuilder(static fn($action, $path, $key, $payload) => $batch($action, $path, $key, $payload));
$scene = new Scene($session->landscape, $session->water, $session->atmosphere, $builder);
$models = [];
$tracers = null;
$trailRenderer = null;
$shotAlpha = 1.0;
$gates = new GateModel($session->gateShapes, __DIR__ . '/assets/gates/');
$courseRevision = -1;
$course = null;
$viewCamera = $session->camera;
$vehiclePose = null;
$carLamps = null;
$spotLights = [];
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
$heldPad = [];
$statusPending = true;
$urgentStatus = false;
$inputMs = 0.0;
$pickFrame = false;
$readInput = vrzno_env('terrainInput');
$publish = vrzno_env('terrainStatus');
$hud = vrzno_env('terrainJetFrame');
$hudShown = false;
$flightMap = null;
// The panel builds its Surface tab once from the appearance settings' groups, ranges, defaults and per-style use.
(vrzno_env('terrainSurface'))(json_encode(['groups' => TerrainPhonics\TerrainAppearance::GROUPS,
	'ranges' => TerrainPhonics\TerrainAppearance::RANGES, 'defaults' => TerrainPhonics\TerrainAppearance::DEFAULTS,
	'uses' => TerrainPhonics\TerrainAppearance::USES], JSON_THROW_ON_ERROR));

$engine->systems->add('terrain-sandbox.simulate', static function(Registry $registry, float $seconds) use ($session): void {
	$session->step($seconds);
});

$engine->setInputHandler(static function(Engine $engine, float $elapsed) use (
	$session, $scene, &$width, &$height, &$dirty, &$generation, &$frameSeconds,
	&$heldKeys, &$heldPad, &$statusPending, &$urgentStatus, &$inputMs, &$pickFrame, $readInput
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
	// The page sends the pad only when it changes; a pad at rest arrives as an empty list.
	if(isset($input['pad'])) $heldPad = is_array($input['pad']) && array_filter($input['pad']) ? $input['pad'] : [];
	if($packet !== '' || $heldKeys || $heldPad || $wasPicking || $wasSearching)
	{
		$input['keys'] = $heldKeys;
		$input['pad'] = $heldPad;
		$moving = $heldKeys || $heldPad || in_array($session->mode, ['craft','jet'], true) || !empty($input['look'][0]) || !empty($input['look'][1]);
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
	// The same object while nothing changes, so the scene uploads the appearance only after a change.
	$scene->appearance = $session->terrainAppearance;
	$scene->selectionInterval = $session->selectionInterval;
	$scene->selectionSliceMs = $session->selectionSliceMs;
	$engine->userPaused = $session->paused;
	if(($download = $session->download()) !== null) (vrzno_env('terrainDownload'))($download);
	$dirty = $dirty || $session->dirty;
	return false;
});

$shadow = null;
$drawOpaque = static function() use ($session, $gates, &$models, &$tracers, &$shotAlpha, &$shadow, &$width, &$height, &$viewCamera, &$vehiclePose): void {
	// The car's shadow darkens the ground it stands on, so it draws after the terrain and before the car.
	if($session->mode === 'car' && $vehiclePose !== null)
		($shadow ??= new GroundShadow)->draw($vehiclePose, $session->landscape,
			$session->atmosphere->uniforms()['sunDirection'], $viewCamera, $width, $height);
	if($session->mode !== 'fly' && !($session->mode === 'jet' && $session->jetView === 'cockpit'))
	{
		$name = match($session->mode) { 'car' => 'testarossa', 'jet' => 'f5', 'craft' => 'jetski' };
		$model = $models[$name] ??= new VehicleModel(__DIR__ . '/assets/' . $name . '/mesh.bin.gz');
		$model->draw($vehiclePose ?? $session->craft->state(),
			$viewCamera, $session->atmosphere, $session->water, $width, $height);
	}
	foreach($session->balloons->targets() as [$x,$y,$z])
	{
		$model = $models['balloon'] ??= new VehicleModel(__DIR__ . '/assets/balloon/mesh.bin.gz');
		$model->draw(['x'=>$x,'y'=>$y,'z'=>$z,'yaw'=>0,'pitch'=>0,'roll'=>0],
			$viewCamera,$session->atmosphere,$session->water,$width,$height);
	}
	$streaks = [];
	foreach($session->balloons->shots as $shot)
	{
		$position = [];
		foreach($shot['previous'] as $i=>$from) $position[] = $from+($shot['position'][$i]-$from)*$shotAlpha;
		$dir = $shot['direction'];
		if($shot['tracer']) { $streaks[] = [$position,$dir]; continue; }
		$model = $models['projectile'] ??= new VehicleModel(__DIR__ . '/assets/projectile/mesh.bin.gz');
		$model->draw(['x'=>$position[0],'y'=>$position[1],'z'=>$position[2],'yaw'=>atan2($dir[0],$dir[2]),
			'pitch'=>asin(max(-1,min(1,$dir[1]))),'roll'=>0], $viewCamera,$session->atmosphere,$session->water,$width,$height);
	}
	if($streaks) ($tracers ??= new TracerBatch)->draw($streaks,$viewCamera,$width,$height);
	// Gates draw in free flight too, where the course is edited.
	if($session->course->count() > 0)
		$gates->draw($session->course, $viewCamera, $session->atmosphere, $session->water, $width, $height);
};

$engine->start(
	static function(Engine $engine, float $alpha) use (
		$session, $scene, $drawOpaque, $gates, &$viewCamera, &$vehiclePose, &$width, &$height, &$dirty, &$lastStatus, &$lastRender, &$drawIntervalMs, &$lastRenderedTime,
		&$renderMs, &$frameSeconds, &$lastBudgetPose, &$statusPending, &$urgentStatus, &$inputMs, &$pickFrame, &$courseRevision, &$course,
		&$carLamps, &$spotLights, &$shotAlpha, &$trailRenderer, &$hudShown, &$flightMap, $hud, $publish
	): void {
		$shotAlpha = $session->paused ? 1.0 : $alpha;
		$lastVehicle = $vehiclePose;
		[$viewCamera,$vehiclePose] = $session->view($alpha);
		// Lamp levels blend between steps like the pose, and a change in either redraws the frame below.
		if($session->mode === 'car') $vehiclePose['lamps'] = $session->carLamps($alpha);
		// Lit headlamps light the ground and water from this frame's pose once the sun has set. Daylight, dark lamps
		// and other modes clear the lights.
		$lights = $session->mode === 'car' ? VehicleModel::spotLights(
			$carLamps ??= VehicleModel::lamps(__DIR__ . '/assets/testarossa/mesh.bin.gz'), $vehiclePose,
			$session->atmosphere->uniforms()['nightVisibility']) : [];
		if($lights !== $spotLights)
		{
			$scene->setSpotLights($spotLights = $lights);
			$dirty = true;
		}
		$pose = [$viewCamera->position,$viewCamera->yaw,$viewCamera->pitch,$viewCamera->roll,$viewCamera->fov];
		$moving = $session->mode === 'craft' || $pose !== $lastBudgetPose;
		// High-refresh frames can advance the presentation fraction without a new simulation tick.
		if($vehiclePose !== null && ($lastVehicle !== $vehiclePose || $moving)) $dirty = true;
		$budget = $moving ? 2.0 : 8.0;
		$scene->budgetMs = max(.25, $budget - $inputMs);
		$lastBudgetPose = $pose;
		// Placing, moving or removing a gate needs a new frame even while the view stands still.
		if($session->course !== $course || $session->course->revision !== $courseRevision)
		{
			$course = $session->course;
			$courseRevision = $course->revision;
			$dirty = true;
		}
		$work = $scene->dirty() || $moving || $dirty || $session->dirty;
		// A stationary click gets the generation slice until its confirmed marker is published.
		if($pickFrame && !$moving && !$dirty) $work = false;
		$prepared = $work && $scene->update($frameSeconds, $viewCamera);
		$wavesChanged = $scene->showsWater() && $session->time !== $lastRenderedTime;
		$statusPending = $statusPending || $work || $wavesChanged || !$session->paused;
		$dirty = $dirty || $prepared || $session->dirty || $wavesChanged;
		$now = microtime(true);
		if($dirty)
		{
			$start = microtime(true);
			$engine->device->resize($width, $height);
			$engine->device->begin([.15,.25,.35,1]);
			$scene->render($viewCamera, $width, $height, $session->time,
				drawOpaque: $session->mode === 'fly' && $session->course->count() === 0 && !$session->balloons->targets() ? null : $drawOpaque);
			if($session->mode === 'jet' && $session->flightTrails->active())
				($trailRenderer ??= new TrailRenderer)->draw($session->flightTrails,$shotAlpha,$viewCamera,$width,$height,
					$session->atmosphere->uniforms()['nightVisibility']);
			$engine->device->present();
			if($session->mode === 'jet' && ($session->jetMap || $session->jetWideMap || $session->jetView === 'cockpit' || $session->balloons->targets()
				|| $session->balloons->elapsed < $session->balloons->hitUntil))
			{
				$hudShown = true;
				$showMap = $session->jetMap || $session->jetWideMap;
				if(!$showMap) $flightMap = null;
				$hud(json_encode(['width'=>$width,'height'=>$height,'eye'=>$viewCamera->position,'basis'=>$viewCamera->basis(),
					'fov'=>$viewCamera->fov,'jet'=>$vehiclePose,'thrust'=>TerrainSandbox\Jet::thrustPercent($vehiclePose['power']),'cockpit'=>$session->jetView === 'cockpit',
					'map'=>$showMap ? ($flightMap ??= new FlightMap)->update($session,$scene,$vehiclePose,$now) : null,
					'range'=>$session->balloons->status(),'ring'=>TerrainSandbox\BalloonPractice::RING,
					'targets'=>$session->balloons->targets()], JSON_THROW_ON_ERROR));
			}
			elseif($hudShown) { $hud(''); $hudShown = false; $flightMap = null; }
			$renderMs = (microtime(true) - $start) * 1000;
			$drawIntervalMs = $lastRender === null ? null : ($now - $lastRender) * 1000;
			$lastRender = $now;
			$lastRenderedTime = $session->time;
			$dirty = $session->dirty = false;
		}
		if($urgentStatus || $statusPending && $now - $lastStatus >= .2)
		{
			$status = $session->status() + ['renderer' => $scene->stats() + ($session->course->count() > 0
					? ['gateDraws' => $gates->drawn, 'gateMs' => $gates->ms] : ['gateDraws' => 0, 'gateMs' => 0.0])
					+ ['spotLights' => count($spotLights)],
				'renderMs' => $renderMs, 'drawIntervalMs' => $drawIntervalMs,
				'viewport' => [$width,$height],'simulationMs' => $engine->simulationCpuMs,
				'generationBudgetMs' => $budget,'inputMs' => $inputMs,'frameIntervalMs' => $frameSeconds*1000];
			$publish(json_encode($status, JSON_THROW_ON_ERROR));
			$lastStatus = $now;
			$statusPending = $urgentStatus = false;
		}
	},
	static function() use ($engine, $session, $scene, $gates, &$models, &$tracers, &$trailRenderer, &$shadow): void {
		$engine->systems->remove('terrain-sandbox.simulate');
		foreach($models as $model) $model->close();
		$tracers?->close(); $tracers = null;
		$trailRenderer?->close(); $trailRenderer = null;
		$shadow?->close();
		$gates->release();
		$scene->close(); $session->close();
	},
	static function() use ($scene, $gates, &$models, &$tracers, &$trailRenderer, &$shadow, &$dirty): void {
		foreach($models as $model) $model->close();
		$models = [];
		$tracers?->close(); $tracers = null;
		$trailRenderer?->close(); $trailRenderer = null;
		// The lost context took the shadow's program and grid with it; the next frame that needs it builds them again.
		$shadow?->close();
		$shadow = null;
		$gates->release();
		$scene->restore();
		$dirty = true;
	}
);

$app->ready();
