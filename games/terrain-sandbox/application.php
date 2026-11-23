<?php
declare(strict_types=1);

$archive = 'phar://' . Phar::running(false) . '/';
require_once $archive . 'core/autoload.php';
require_once $archive . 'dependencies/terrain-phonics/autoload.php';
require_once __DIR__ . '/autoload.php';

use Phonics\Application\Context;
use Phonics\Engine;
use Phonics\FixedStep;
use Phonics\Render\Device;
use TerrainPhonics\Scene;
use TerrainPhonics\AtmosphereLayers;
use TerrainPhonics\Recipe;
use TerrainSandbox\Car;
use TerrainSandbox\CarEngineSound;
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
use TerrainSandbox\DisplayUnits;
use TerrainSandbox\Session;

$app = new Context;
$app->browser(__DIR__ . '/browser/game.mjs');
$app->browser(__DIR__ . '/browser/car-audio.mjs');
$app->browser(__DIR__ . '/browser/screen-awake.mjs');
$app->browser(__DIR__ . '/browser/control-hints.mjs');
$app->browser(__DIR__ . '/browser/road-editor.mjs');
$app->browser(__DIR__ . '/browser/flight-map.mjs');
$app->browser(__DIR__ . '/browser/speedometer.mjs');
$app->browser(__DIR__ . '/browser/cockpit.mjs');
$engine = new Engine(new Device(1280, 720, 'Landscape Sandbox'));
$session = new Session(systems: $engine->systems);
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
$spotLights = [[], 1.0]; // The lights given to the scene and their adaptation gain.
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
$carSound = vrzno_env('terrainCarSound');
$lastSound = [0,0,0];
$roadStatus = vrzno_env('terrainRoadEditor');
$roadCapture = vrzno_env('terrainRoadCapture');
$roadCamera = vrzno_env('terrainRoadCamera');
$lastRoadCamera = null;
$hudShown = false;
// The panel builds its Surface tab once from the appearance settings' groups, ranges, defaults and per-style use.
(vrzno_env('terrainSurface'))(json_encode(['masks'=>TerrainPhonics\SurfaceLayers::MASKS,'fields'=>TerrainPhonics\SurfaceLayers::FIELDS,
	'relief'=>TerrainPhonics\SurfaceLayers::RELIEF,'channels'=>TerrainPhonics\SurfaceLayers::CHANNELS,
	'patterns'=>TerrainPhonics\SurfaceLayers::PATTERNS,'limit'=>TerrainPhonics\SurfaceLayers::LIMIT,
	'air'=>['defaults'=>['top'=>3000 * DisplayUnits::FOOT,'density'=>.3 / DisplayUnits::KM_PER_MILE] + AtmosphereLayers::DEFAULTS,'limit'=>AtmosphereLayers::LIMIT,'maxDensity'=>AtmosphereLayers::MAX_DENSITY,
		'ceiling'=>$session->atmosphere->uniforms()['atmosphereHeight']],
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
$lastCircuitFrame = 0;
$workOnSearch = static function() use (
	$session, $engine, $stopSearchTimer, &$lastCircuitFrame, &$statusPending, &$urgentStatus
): void {
	if($engine->paused) { $stopSearchTimer(); return; }
	if($session->findingCircuit())
	{
		// At normal frame rates input already services the editor. Reclaim a short slice only between slow frames.
		if(hrtime(true)-$lastCircuitFrame >= 16_000_000)
		{
			$session->advanceCircuit(5.);
			if(!$session->findingCircuit()) $urgentStatus = $statusPending = true;
		}
	}
	$deliveryPending = !$session->paused && $session->delivery?->advanceSearch(5.);
	if(!$deliveryPending && !$session->findingCircuit()) $stopSearchTimer();
};
$startSearchTimer = static function() use ($window, &$searchTimer, $workOnSearch): void {
	if($searchTimer === null) $searchTimer = $window->setInterval($workOnSearch, 10);
};
$engine->systems->add('terrain-sandbox.delivery-search', static function() use ($session, $startSearchTimer): void {
	if($session->delivery?->searchPending()) $startSearchTimer();
}, ['terrain-sandbox.simulate']);

$engine->setInputHandler(static function(Engine $engine, float $elapsed) use (
	$session, $scene, &$width, &$height, &$dirty, &$generation, &$frameSeconds,
	&$heldKeys, &$heldPad, &$statusPending, &$urgentStatus, &$inputMs, &$pickFrame, $readInput, $startSearchTimer, &$lastCircuitFrame
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
	if($packet !== '' || $heldKeys || $heldPad || $wasPicking || $wasSearching || $wasFindingCircuit || $session->preparingRoad())
	{
		$input['keys'] = $heldKeys;
		$input['pad'] = $heldPad;
		$moving = $heldKeys || $heldPad || in_array($session->mode, ['craft','jet'], true) || !empty($input['look'][0]) || !empty($input['look'][1]);
		$session->input($input, min(.1, $frameSeconds), $width / $height, $moving ? 1.0 : 7.5);
		if($session->findingCircuit())
		{
			$lastCircuitFrame = hrtime(true);
			$startSearchTimer();
		}
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
	foreach($visibleSites as $site)
		if($site['kind'] !== 'smoke') ($beacon ??= new DeliveryBeacon)->solid($site,$viewCamera,$width,$height);
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
		&$carLamps, &$spotLights, &$shotAlpha, &$trailRenderer, &$beacon, &$arrow, &$arrowBearing, &$visibleSites, &$hudShown, $hud, $publish, $roadStatus, $roadCapture, $roadCamera, &$lastRoadCamera, $carSound, &$lastSound
	): void {
		$shotAlpha = $session->paused ? 1.0 : $alpha;
		$lastVehicle = $vehiclePose;
		[$viewCamera,$vehiclePose] = $session->view($alpha);
		$sound = $session->mode === 'car' && !$session->paused && !$vehiclePose['floodedHold']
			? [(int)round($vehiclePose['rpm']), round($vehiclePose['engineLoad'],3), round($vehiclePose['tireSqueal'],3)] : [0,0,0];
		if($sound !== $lastSound)
		{
			$carSound(...CarEngineSound::controls(...$sound));
			$lastSound = $sound;
		}
		// Lamp levels blend between steps like the pose, and a change in either redraws the frame below.
		if($session->mode === 'car') $vehiclePose['lamps'] = $session->carLamps($alpha);
		// Lit headlamps light the ground and water from this frame's pose, day or night. Dark lamps and other modes
		// clear the lights.
		// In a shadow by day, eyes adapted to it see the beams brighter, as they do at night.
		$lights = $session->mode === 'car' ? [VehicleModel::spotLights(
			$carLamps ??= VehicleModel::lamps(__DIR__ . '/assets/testarossa/mesh.bin.gz'), $vehiclePose),
			$session->lampAdaptation()] : [[], 1.0];
		if($lights !== $spotLights)
		{
			$scene->setSpotLights(...($spotLights = $lights));
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
		$hadVisibleSmoke = (bool)$visibleSites;
		$visibleSites = [];
		foreach($session->delivery?->beacons($session->time) ?? [] as $site)
			if(DeliveryRun::beaconInView($site,$viewCamera,$width/$height)) $visibleSites[] = $site;
		if(count($visibleSites) > 1)
		{
			[$forward] = $viewCamera->basis();
			// Blend all visible columns back to front, including smoke left by collected boxes.
			usort($visibleSites, static fn($a,$b) => (($b['x']-$a['x'])*$forward[0]
				+ ($b['y']-$a['y'])*$forward[1] + ($b['z']-$a['z'])*$forward[2]) <=> 0.);
		}
		if(($hadVisibleSmoke || $visibleSites) && $session->time !== $lastRenderedTime) $dirty = true;
		$work = $scene->dirty() || $moving || $dirty || $session->dirty;
		// A stationary click gets the generation slice until its confirmed marker is published.
		if($pickFrame && !$moving && !$dirty) $work = false;
		$prepared = $work && $scene->update($frameSeconds, $viewCamera,
			$session->mode === 'car' ? Car::terrainFocus($vehiclePose) : null);
		$wavesChanged = $scene->showsWater() && $session->time !== $lastRenderedTime;
		$statusPending = $statusPending || $work || $wavesChanged || !$session->paused;
		$dirty = $dirty || $prepared || $session->dirty || $wavesChanged;
		$editor = $session->roadEditor;
		if(($packet = $editor->packet($session)) !== null) $roadStatus(json_encode($packet, JSON_THROW_ON_ERROR));
		$mapCamera = $editor->open ? [$viewCamera->position[0], $viewCamera->position[2],
			$viewCamera->yaw + (cos($viewCamera->pitch) < 0 ? M_PI : 0)] : null;
		if($mapCamera !== $lastRoadCamera)
		{
			$lastRoadCamera = $mapCamera;
			if($mapCamera !== null) $roadCamera(...$mapCamera);
		}
		$chartKey = $editor->open && $editor->view !== null
			? [$session->generation, $session->landscape->revision(), $editor->view, $width, $height,
				$session->settings['waterLevel'], $session->settings['waterColor'], $scene->layers, $scene->paint,
				$scene->appearance, $scene->dirty()] : null;
		$captureChart = $chartKey !== null && $chartKey !== $editor->captured;
		$dirty = $dirty || $captureChart;
		$now = microtime(true);
		if($dirty)
		{
			$start = microtime(true);
			$engine->device->resize($width, $height);
			if($captureChart)
			{
				[$x,$z,$meters] = $editor->view;
				$size = min(512, $width-10, $height-10);
				if($size > 0 && $scene->drawChart([$x,$z], 0., false, $width, $height,
					TerrainAppearance::linear($session->settings['waterColor']), $size, fixedSpan: $meters) !== null)
				{
					$roadCapture($x, $z, $meters, $size);
					$editor->captured = $chartKey;
				}
			}
			$engine->device->begin([.15,.25,.35,1]);
			$scene->render($viewCamera, $width, $height, $session->time,
				drawOpaque: $session->mode === 'fly' && $session->course->count() === 0 && !$session->balloons->targets() ? null : $drawOpaque);
			$trails = match($session->mode) { 'jet' => $session->flightTrails, 'car' => $session->tireDust, default => null };
			if($trails?->active())
				($trailRenderer ??= new TrailRenderer)->draw($trails,$shotAlpha,$viewCamera,$width,$height,
					$session->atmosphere->uniforms()['nightVisibility']);
			foreach($visibleSites as $site) ($beacon ??= new DeliveryBeacon)->smoke($site,$viewCamera,$width,$height,$session->time);
			if($target !== null) ($arrow ??= new NavigationArrow)->draw($target,$arrowBearing,$viewCamera,$width,$height);
			$jet = $session->mode === 'jet';
			$car = $session->mode === 'car';
			$craft = $session->mode === 'craft';
			$raceGuidance = $session->race->showGuidance($session->course);
			$waterRace = $craft && $raceGuidance && $session->usingFloodedCircuit();
			$instrumentSize = $car || $craft ? (int)round(min(320, $width*.36, $height*.40)) : null;
			$frame = $jet || $car || $craft ? ['width'=>$width,'height'=>$height] : null;
			$wide = $jet ? $session->jetWideMap : $car && $session->carWideMap;
			$showMap = $waterRace || $wide || ($jet ? $session->jetMap : $car && $session->carMap);
			if($showMap)
			{
				$bearing = M_PI-$vehiclePose['yaw']+($jet && cos($vehiclePose['pitch']) < 0 ? M_PI : 0);
				$position = [$vehiclePose['x'],$vehiclePose['z']];
				$span = $scene->drawChart($position,$bearing,$wide,$width,$height,
					TerrainAppearance::linear($session->settings['waterColor']),$instrumentSize,
					$car || $waterRace ? $session->circuitChartSpan() : null, circular: $car);
				if($span !== null)
					$frame['map'] = ['position'=>$position,'bearing'=>$bearing,'span'=>$span,'size'=>$instrumentSize,'circular'=>$car,
						'showRoute'=>$raceGuidance, 'markers'=>$session->delivery?->sites() ?: ($target === null ? [] : [$target])];
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
				'maximum'=>$car ? Car::TOP_SPEED_MPH : 120,'size'=>$instrumentSize];
			if($car) $frame['speedometer']['nitrous'] = $vehiclePose['nitrous'];
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
					+ ['spotLights' => count($spotLights[0])],
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
		$engine->systems->remove('terrain-sandbox.delivery-search');
		foreach($models as $model) $model->close();
		$tracers?->close(); $tracers = null;
		$trailRenderer?->close(); $trailRenderer = null;
		$shadow?->close();
		$beacon?->close(); $arrow?->close();
		$gates->release();
		$scene->close(); $session->close();
	},
	static function() use ($scene, $session, $gates, &$models, &$tracers, &$trailRenderer, &$beacon, &$arrow, &$shadow, &$dirty): void {
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
		$session->roadEditor->captured = null;
		$dirty = true;
	}
);

$app->ready();
