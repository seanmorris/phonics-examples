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
use TerrainPhonics\Recipe;
use TerrainSandbox\Car;
use TerrainSandbox\DeliveryBeacon;
use TerrainSandbox\DeliveryRun;
use TerrainSandbox\NavigationArrow;
use TerrainSandbox\GateModel;
use TerrainPhonics\TerrainAppearance;
use TerrainSandbox\GroundShadow;
use TerrainSandbox\Jet;
use TerrainSandbox\VehicleModel;
use TerrainSandbox\TracerBatch;
use TerrainSandbox\TrailRenderer;
use TerrainSandbox\Session;

$app = new Context;
$app->browser(__DIR__ . '/browser/game.mjs');
$app->browser(__DIR__ . '/browser/flight-map.mjs');
$app->browser(__DIR__ . '/browser/speedometer.mjs');
$app->browser(__DIR__ . '/browser/cockpit.mjs');
$session = new Session;
$engine = new Engine(new Device(1280, 720, 'Landscape Sandbox'));
$engine->clock = new FixedStep(1 / 60, 4);
// The authoring bridge owns pause, avoiding a second toggle from SDL's default P/Escape and Start mappings.
// The page reads gamepads itself and sends Start as the sandbox's own pause action.
$engine->input->bindButton(9, []);
$engine->input->bindGamepad(9, []);
$scene = new Scene($session->landscape, $session->water, $session->atmosphere, useClipmap: true);
$session->prepareMaterial = $scene->prepareMaterial(...);
$models = [];
$tracers = null;
$trailRenderer = null;
$beacon = $arrow = null;
$arrowBearing = null;
$visibleSites = [];
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
$hud = vrzno_env('terrainVehicleFrame');
$hudShown = false;
// The panel builds its Surface tab once from the appearance settings' groups, ranges, defaults and per-style use.
(vrzno_env('terrainSurface'))(json_encode(['masks'=>TerrainPhonics\SurfaceLayers::MASKS,'fields'=>TerrainPhonics\SurfaceLayers::FIELDS,
	'relief'=>TerrainPhonics\SurfaceLayers::RELIEF,'channels'=>TerrainPhonics\SurfaceLayers::CHANNELS,
	'patterns'=>TerrainPhonics\SurfaceLayers::PATTERNS,'limit'=>TerrainPhonics\SurfaceLayers::LIMIT,
	'layerDefaults'=>TerrainPhonics\SurfaceLayers::DEFAULTS,'ranges'=>TerrainPhonics\TerrainAppearance::RANGES
		+ array_map(static fn($v)=>array_slice($v,1),TerrainPhonics\SurfaceLayers::SCALES),
	'inkKeys'=>TerrainPhonics\SurfaceLayers::INKS,'fieldKeys'=>[...TerrainPhonics\SurfaceLayers::FIELD_KEYS,...array_keys(TerrainPhonics\SurfaceLayers::SCALES)],
	'presets' => array_combine(Recipe::PRESETS, array_map([Session::class, 'preset'], Recipe::PRESETS))], JSON_THROW_ON_ERROR));

// Slow GPU frames can be hundreds of milliseconds apart. Give a pending route short turns in the gaps.
$window = new \Vrzno;
$searchTimer = null;
$stopSearchTimer = static function() use ($window, &$searchTimer): void {
	if($searchTimer !== null) $window->clearInterval($searchTimer);
	$searchTimer = null;
};
$workOnSearch = static function() use ($session, $engine, $stopSearchTimer): void {
	if($engine->paused || $session->paused || !$session->delivery?->advanceSearch(5.))
		$stopSearchTimer();
};
$engine->systems->add('terrain-sandbox.simulate', static function(Registry $registry, float $seconds) use (
	$session, $window, &$searchTimer, $workOnSearch
): void {
	$session->step($seconds);
	if($searchTimer === null && $session->delivery?->searchPending())
		$searchTimer = $window->setInterval($workOnSearch, 10);
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
	$wasFindingCircuit = $session->findingCircuit();
	$previousRoadPoints = count($session->road);
	$keysChanged = isset($input['keys']) && $heldKeys !== $input['keys'];
	if($keysChanged) $heldKeys = $input['keys'];
	// The page sends the pad only when it changes; a pad at rest arrives as an empty list.
	if(isset($input['pad'])) $heldPad = is_array($input['pad']) && array_filter($input['pad']) ? $input['pad'] : [];
	if($packet !== '' || $heldKeys || $heldPad || $wasPicking || $wasSearching || $wasFindingCircuit)
	{
		$input['keys'] = $heldKeys;
		$input['pad'] = $heldPad;
		$moving = $heldKeys || $heldPad || in_array($session->mode, ['craft','jet'], true) || !empty($input['look'][0]) || !empty($input['look'][1]);
		$session->input($input, min(.1, $frameSeconds), $width / $height, $moving ? 1.0 : 7.5);
	}
	$urgentStatus = $urgentStatus || $keysChanged || isset($input['clicks'][0]) || $wasPicking !== $session->picking
		|| $previousRoadPoints !== count($session->road) || $wasSearching !== $session->findingCarSite()
		|| $wasFindingCircuit !== $session->findingCircuit();
	$pickFrame = $wasPicking || $session->picking;
	$statusPending = $statusPending || $packet !== '' || $wasPicking || $wasSearching || $wasFindingCircuit || $prepared !== null;
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
	$scene->layers = $session->activeLayers();
	$scene->paint = $session->paint;
	$scene->selectionInterval = $session->selectionInterval;
	$scene->selectionSliceMs = $session->selectionSliceMs;
	$engine->userPaused = $session->paused;
	if(($download = $session->download()) !== null) (vrzno_env('terrainDownload'))($download);
	$dirty = $dirty || $session->dirty;
	return false;
});

$shadow = null;
$drawOpaque = static function() use ($scene, $session, $gates, &$models, &$tracers, &$shotAlpha, &$shadow, &$width, &$height, &$viewCamera, &$vehiclePose, &$beacon, &$visibleSites): void {
	foreach($visibleSites as $site) ($beacon ??= new DeliveryBeacon)->solid($site,$viewCamera,$width,$height);
	// The car's shadow darkens the ground under it, so it draws after the terrain and before the car.
	if($session->mode === 'car' && $vehiclePose !== null)
		($shadow ??= new GroundShadow)->draw($vehiclePose, $session->landscape, $viewCamera, $width, $height, $scene);
	if($session->mode !== 'fly' && !($session->mode === 'jet' && $session->jetView === 'cockpit'))
	{
		$name = match($session->mode) { 'car' => 'testarossa', 'jet' => 'f5', 'craft' => 'jetski' };
		$model = $models[$name] ??= new VehicleModel(__DIR__ . '/assets/' . $name . '/mesh.bin.gz');
		$model->draw($vehiclePose,
			$viewCamera, $session->atmosphere, $session->water, $width, $height);
	}
	$projectiles = [];
	if($session->mode === 'jet' && $session->jetView === 'chase')
	{
		foreach($session->balloons->readySlots() as $slot)
			$projectiles[] = Jet::projectileMount($vehiclePose,$slot);
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
		$projectiles[] = ['x'=>$position[0],'y'=>$position[1],'z'=>$position[2],'yaw'=>atan2($dir[0],$dir[2]),
			'pitch'=>asin(max(-1,min(1,$dir[1]))),'roll'=>$shot['roll']];
	}
	if($projectiles)
	{
		$model = $models['projectile'] ??= new VehicleModel(__DIR__ . '/assets/projectile/mesh.bin.gz');
		foreach($projectiles as $pose) $model->draw($pose,$viewCamera,$session->atmosphere,$session->water,$width,$height);
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
		&$carLamps, &$spotLights, &$shotAlpha, &$trailRenderer, &$beacon, &$arrow, &$arrowBearing, &$visibleSites, &$hudShown, $hud, $publish
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
			if($session->course !== $course) $arrowBearing = null;
			$course = $session->course;
			$courseRevision = $course->revision;
			$dirty = true;
		}
		$target = $session->navigationTarget();
		$previousBearing = $arrowBearing;
		$arrowBearing = $target === null ? null : NavigationArrow::bearing($target, $vehiclePose, $previousBearing,
			$session->paused ? 0.0 : $frameSeconds);
		if($arrowBearing !== $previousBearing) $dirty = true;
		$sites = $session->delivery?->sites() ?? [];
		$visibleSites = [];
		foreach($sites as $site)
			if(DeliveryRun::beaconInView($site,$viewCamera,$width/$height)) $visibleSites[] = $site;
		if(count($visibleSites) === 2)
		{
			[$a,$b] = $visibleSites;
			[$forward] = $viewCamera->basis();
			// Composite the farther plume first when both signals lie along the same bearing.
			if(($b['x']-$a['x'])*$forward[0]+($b['y']-$a['y'])*$forward[1]+($b['z']-$a['z'])*$forward[2] > 0)
				$visibleSites = [$b,$a];
		}
		if($visibleSites && $session->time !== $lastRenderedTime) $dirty = true;
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
			foreach($visibleSites as $site) $beacon->smoke($site,$viewCamera,$width,$height,$session->time);
			if($target !== null) ($arrow ??= new NavigationArrow)->draw($target,$arrowBearing,$viewCamera,$width,$height);
			$jet = $session->mode === 'jet';
			$car = $session->mode === 'car';
			$craft = $session->mode === 'craft';
			$waterRace = $craft && $session->usingFloodedCircuit();
			$dialSize = $car || $craft ? (int)round(min(240, $width*.275, $height*.30)) : null;
			$frame = $jet || $car || $craft ? ['width'=>$width,'height'=>$height] : null;
			$wide = $jet ? $session->jetWideMap : $car && $session->carWideMap;
			$showMap = $waterRace || $wide || ($jet ? $session->jetMap : $car && $session->carMap);
			if($showMap)
			{
				$mapSize = $car ? (int)round(min(320, $width*.36, $height*.40)) : null;
				$bearing = M_PI-$vehiclePose['yaw']+($jet && cos($vehiclePose['pitch']) < 0 ? M_PI : 0);
				$position = [$vehiclePose['x'],$vehiclePose['z']];
				$span = $scene->drawChart($position,$bearing,$wide,$width,$height,
					TerrainAppearance::linear($session->settings['waterColor']),$mapSize,
					$car || $waterRace ? $session->circuitChartSpan() : null, circular: $car);
				if($span !== null)
					$frame['map'] = ['position'=>$position,'bearing'=>$bearing,'span'=>$span,'size'=>$mapSize,'circular'=>$car,
						'markers'=>$sites ?: ($target === null ? [] : [$target])];
			}
			$engine->device->present();
			if($jet)
			{
				$frame += ['eye'=>$viewCamera->position,'basis'=>$viewCamera->basis(),
					'fov'=>$viewCamera->fov,'jet'=>$vehiclePose,'thrust'=>TerrainSandbox\Jet::thrustPercent($vehiclePose['power']),'cockpit'=>$session->jetView === 'cockpit',
					'range'=>$session->balloons->status(),'ring'=>TerrainSandbox\BalloonPractice::RING,
					'contactRange'=>TerrainSandbox\BalloonPractice::CONTACT_RANGE,
					'targets'=>$session->balloons->targets()];
			}
			if($car || $craft) $frame['speedometer'] = ['mph'=>abs($vehiclePose['speed'])/Car::MPS_PER_MPH,
				'maximum'=>$car ? Car::TOP_SPEED_MPH : 120,'size'=>$dialSize];
			if($frame !== null || $hudShown)
			{
				$hud($frame !== null ? json_encode($frame, JSON_THROW_ON_ERROR) : '');
				$hudShown = $frame !== null;
			}
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
	static function() use ($engine, $session, $scene, $stopSearchTimer, $gates, &$models, &$tracers, &$trailRenderer, &$beacon, &$arrow, &$shadow): void {
		$stopSearchTimer();
		$engine->systems->remove('terrain-sandbox.simulate');
		foreach($models as $model) $model->close();
		$tracers?->close(); $tracers = null;
		$trailRenderer?->close(); $trailRenderer = null;
		$shadow?->close();
		$beacon?->close(); $arrow?->close();
		$gates->release();
		$scene->close(); $session->close();
	},
	static function() use ($scene, $gates, &$models, &$tracers, &$trailRenderer, &$beacon, &$arrow, &$shadow, &$dirty): void {
		foreach($models as $model) $model->close();
		$models = [];
		$tracers?->close(); $tracers = null;
		$trailRenderer?->close(); $trailRenderer = null;
		// The lost context took the shadow's program and grid with it; the next frame that needs it builds them again.
		$shadow?->close();
		$shadow = null;
		$beacon?->close(); $beacon = null;
		$arrow?->close(); $arrow = null;
		$gates->release();
		$scene->restore();
		$dirty = true;
	}
);

$app->ready();
