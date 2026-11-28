<?php
declare(strict_types=1);

$archive = 'phar://' . Phar::running(false) . '/';
require_once $archive . 'core/autoload.php';
require_once $archive . 'dependencies/terrain-phonics/autoload.php';
require_once $archive . 'dependencies/phonics-bgm/src/BackgroundMusic.php';
require_once __DIR__ . '/autoload.php';

use Phonics\Application\Context;
use PhonicsBgm\BackgroundMusic;
use Phonics\Engine;
use Phonics\FixedStep;
use Phonics\Render\Device;
use TerrainPhonics\Scene;
use TerrainPhonics\GrassShells;
use TerrainPhonics\AtmosphereLayers;
use TerrainPhonics\Recipe;
use TerrainSandbox\BeachBalls;
use TerrainSandbox\Car;
use TerrainSandbox\CarEngineSound;
use TerrainSandbox\SandboxMenu;
use TerrainSandbox\DeliveryBeacon;
use TerrainSandbox\DeliveryRun;
use TerrainSandbox\DemoPlayback;
use TerrainSandbox\NavigationArrow;
use TerrainSandbox\GateModel;
use TerrainSandbox\CurveSignModel;
use TerrainSandbox\JourneySignModel;
use TerrainPhonics\TerrainAppearance;
use TerrainSandbox\GroundShadow;
use TerrainSandbox\Jet;
use TerrainSandbox\VehicleModel;
use TerrainSandbox\TracerBatch;
use TerrainSandbox\TrailRenderer;
use TerrainSandbox\FlightLights;
use TerrainSandbox\DisplayUnits;
use TerrainSandbox\Session;
use TerrainSandbox\SeekerMount;
use TerrainSandbox\SpeedSeeker;

$app = new Context;
$music = new BackgroundMusic($app);
$app->browser(__DIR__ . '/browser/journey-editor.mjs');
$app->browser(__DIR__ . '/browser/game.mjs');
$app->lobby([
	'provider'=>'@matrix/lobby','title'=>'Landscape Race Lobby',
	'options'=>['homeserver'=>'https://matrix.org','room'=>'#terrain-sandbox:matrix.org','configurationKey'=>'terrain-sandbox'],
	'game'=>['id'=>'org.seanmorris.phonics.terrain.circuit','protocol'=>'1.0.0','rules'=>TerrainSandbox\CircuitTrial::RULES,
		'players'=>2,'checkpointMode'=>'history','spectators'=>true,'aspectRatio'=>'auto','actionTypes'=>['race-finish']]
]);
$app->browser(__DIR__ . '/browser/race-media.mjs');
$app->browser(__DIR__ . '/browser/race-live.mjs');
$app->browser(__DIR__ . '/browser/race-lobby.mjs');
$app->browser(__DIR__ . '/browser/car-audio.mjs');
$music->playlist(json_decode(file_get_contents(__DIR__.'/assets/soundtrack.json'), true, flags: JSON_THROW_ON_ERROR),
	'terrain-sandbox.music.next.v1');
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
$scene = new Scene($session->landscape, $session->water, $session->atmosphere, useClipmap: true, retainJourneyBinding: true);
$scene->weather = $session->weather;
$session->prepareMaterial = $scene->prepareMaterial(...);
$models = [];
$tracers = null;
$trailRenderer = null;
$flightLights = null; $lightSources=[];
$beacon = $arrow = null;
$arrowBearing = null;
$visibleSites = [];
$shotAlpha = 1.0;
$gates = new GateModel($session->gateShapes, __DIR__ . '/assets/gates/');
$curveSigns = new CurveSignModel;
$journeySigns = new JourneySignModel;
$courseRevision = -1;
$course = null;
$viewCamera = $session->camera;
$vehiclePose = null;
$carLamps = null;
$spotLights = [[], 1.0]; // The lights given to the scene and their adaptation gain.
$width = 1280; $height = 720; $dirty = true;
$generation = $session->generation;
$displayTerrain = $session->landscape;
$hiddenGates = [];
$pendingEdit = null;
$lastStatus = 0.0;
$lastJourneyPlan = null;
$lastRender = null;
$drawIntervalMs = null;
$lastRenderedTime = -1.0;
$renderMs = 0.0;
$frameSeconds = 0.0;
$lastBudgetPose = null;
$heldKeys = [];
$heldPad = [];
$menuPad = []; // The page's pad as menu buttons: up, down, left, right, confirm, back, pause, then the two shoulders consumed by music.
$statusPending = true;
$urgentStatus = false;
$inputMs = 0.0;
$pickFrame = false;
$readInput = vrzno_env('terrainInput');
$publish = vrzno_env('terrainStatus');
$hud = vrzno_env('terrainVehicleFrame');
$carSound = vrzno_env('terrainCarSound');
$acoustics = new TerrainSandbox\BurstAcoustics;
$burstMode = vrzno_env('terrainBurstMode');
$burstSound = vrzno_env('terrainBurstSound');
// The pause menu reads and sets audio in the page, and asks it to open the Landscape Lab or leave.
$pauseMenu = new SandboxMenu(vrzno_env('terrainAudioGet'), vrzno_env('terrainAudioSet'), vrzno_env('terrainPage'));
$lastSound = [0,0,0];
$replyRace = vrzno_env('terrainRaceReply');
$publishRace = vrzno_env('terrainRaceFrame');
$online = new TerrainSandbox\CircuitOnline($session, fn($id,$json) => $replyRace($id,$json), fn($json) => $publishRace($json));
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
$lastAuthoringFrame = 0;
$workOnSearch = static function() use (
	$session, $engine, $stopSearchTimer, &$lastAuthoringFrame, &$statusPending, &$urgentStatus
): void {
	if($engine->paused || $session->menu || $session->demo->playhead !== null) { $stopSearchTimer(); return; }
	if($session->findingCircuit() || $session->preparingRoad())
	{
		// At normal frame rates input already services the editor. Reclaim a short slice only between slow frames.
		if(hrtime(true)-$lastAuthoringFrame >= 16_000_000)
		{
			if($session->preparingRoad()) $session->advanceRoadBetweenFrames();
			else $session->advanceCircuit(5.);
			if(!$session->findingCircuit() && !$session->preparingRoad()) $urgentStatus = $statusPending = true;
		}
	}
	$deliveryPending = !$session->paused && $session->delivery?->advanceSearch(5.);
	if(!$deliveryPending && !$session->findingCircuit() && !$session->preparingRoad()) $stopSearchTimer();
};
$startSearchTimer = static function() use ($window, &$searchTimer, $workOnSearch): void {
	if($searchTimer === null) $searchTimer = $window->setInterval($workOnSearch, 10);
};
$engine->systems->add('terrain-sandbox.delivery-search', static function() use ($session, $startSearchTimer): void {
	if($session->delivery?->searchPending()) $startSearchTimer();
}, ['terrain-sandbox.simulate']);

$survey = null;
$surveyId = 0;
$journeyAtlas = new TerrainSandbox\JourneyAtlas;
$atlasResult = vrzno_env('terrainJourneyMap');
$verificationTimer = null;
$advanceVerification = static function() use ($online,$window,&$verificationTimer): void {
	$online->advanceVerification();
	if(!$online->verifying() && $verificationTimer !== null) {
		$window->clearInterval($verificationTimer);
		$verificationTimer = null;
	}
};
$engine->setInputHandler(static function(Engine $engine, float $elapsed) use (
	$session, $scene, &$width, &$height, &$dirty, &$generation, &$frameSeconds, &$displayTerrain, &$pendingEdit, &$hiddenGates,
	&$heldKeys, &$heldPad, &$menuPad, &$statusPending, &$urgentStatus, &$inputMs, &$pickFrame, $readInput, $startSearchTimer, &$lastAuthoringFrame,
	$pauseMenu, &$survey, &$surveyId, $journeyAtlas, $atlasResult, $online, $window, $advanceVerification, &$verificationTimer
): bool {
	$frameSeconds = max(0.0, $elapsed);
	$started = hrtime(true);
	$packet = (string)$readInput();
	$input = $packet === '' ? [] : json_decode($packet, true, flags: JSON_THROW_ON_ERROR);
	$online->update($input['onlineCommand'] ?? null);
	if($online->verifying() && $verificationTimer === null) $verificationTimer = $window->setInterval($advanceVerification,10);
	if($session->trial !== null && isset($input['onlineFollow']) && count($input['onlineFollow']) === 6) {
		$session->spectatorPose = array_combine(['x','y','z','yaw','pitch','roll'],$input['onlineFollow']);
		$session->dirty = true;
	}
	if($session->trial !== null && array_key_exists('onlineRival',$input)) {
		$pose = $input['onlineRival'];
		$session->rivalPose = is_array($pose) && count($pose) === 6
			? array_combine(['x','y','z','yaw','pitch','roll'],$pose) : null;
		$session->dirty = true;
	}
	if(isset($input['landformSurvey']) && $session->trial === null) {
		$survey = null;
		$request = $input['landformSurvey'];
		$surveyId = $request['id'];
		if(isset($request['recipe'])) {
			try {
				$r = $request['recipe'];
				$grid = new TerrainPhonics\LandformPreview($r,(float)$r['waterLevel']);
				$survey = (static function() use ($grid): \Generator {
					$result = yield from $grid->survey();
					$result['view'] = yield from $grid->findView();
					return $result;
				})();
			} catch(\InvalidArgumentException $error) {
				(vrzno_env('terrainSurveyResult'))(json_encode(['id'=>$surveyId,'error'=>$error->getMessage()],JSON_THROW_ON_ERROR));
			}
		}
	}
	if($survey !== null) {
		$deadline = hrtime(true)+600000;
		do { $survey->next(); } while($survey->valid() && hrtime(true)<$deadline);
		if(!$survey->valid()) {
			(vrzno_env('terrainSurveyResult'))(json_encode(['id'=>$surveyId]+$survey->getReturn(),JSON_THROW_ON_ERROR));
			$survey=null;
		}
	}
	if(isset($input['journeyMap'])) $journeyAtlas->request($input['journeyMap'],$session,$atlasResult);
	$journeyAtlas->step($session,$atlasResult,$session->mode === 'fly' && !$heldKeys && !$heldPad && !$session->preparingRoad());
	if(isset($input['journeyDraft'])) {
		$plan = $input['journeyDraft'];
		try { $preview = ['nodes' => (new TerrainSandbox\JourneyNetwork($plan))->nodes]; }
		catch(InvalidArgumentException $error) { $preview = ['error' => $error->getMessage()]; }
		(vrzno_env('terrainJourneyPreview'))(json_encode(['plan'=>$plan] + $preview, JSON_THROW_ON_ERROR));
	}
	if(isset($input['viewport']))
	{
		[$w,$h] = $input['viewport'];
		$width = max(1, min(3840, (int)$w));
		$height = max(1, min(2160, (int)$h));
		$dirty = true;
	}
	$prepared = ($session->menu || $session->demo->playhead !== null) && $pendingEdit !== null ? null : $scene->takePreparedLandscape();
	if($prepared !== null) {
		if($pendingEdit !== null) $session->publishLandscape($pendingEdit);
		$pendingEdit = null;
		$session->roadEditor->captured = null;
		$dirty = true;
	}
	$wasPicking = $session->picking;
	$wasSearching = $session->findingCarSite();
	$wasFindingCircuit = $session->findingCircuit();
	$previousRoadPoints = count($session->road);
	$keysChanged = isset($input['keys']) && $heldKeys !== $input['keys'];
	if($keysChanged) $heldKeys = $input['keys'];
	// The page sends the pad only when it changes; a pad at rest arrives as an empty list.
	if(isset($input['pad'])) $heldPad = is_array($input['pad']) && array_filter($input['pad']) ? $input['pad'] : [];
	// The page sends the pause menu's pad buttons only when they change.
	if(isset($input['menuPad']) && is_array($input['menuPad'])) $menuPad = $input['menuPad'];
	// The open pause menu takes the keys, pad and pointer itself. Only page values, such as the input device, pass.
	$menuOpen = $pauseMenu->menu->isOpen;
	if($pauseMenu->screenshotView() && in_array('menu-back',array_column($input['actions'] ?? [],'type'),true))
		$pauseMenu->menu->back();
	if($menuOpen) $input = isset($input['values']) ? ['values' => $input['values']] : [];
	if($packet !== '' || $heldKeys || $heldPad || $wasPicking || $wasSearching || $wasFindingCircuit || $session->preparingRoad())
	{
		$input['keys'] = $menuOpen ? [] : $heldKeys;
		$input['pad'] = $menuOpen ? [] : $heldPad;
		$moving = $heldKeys || $heldPad || in_array($session->mode, ['craft','jet'], true) || !empty($input['look'][0]) || !empty($input['look'][1]);
		$session->input($input, min(.1, $frameSeconds), $width / $height, $moving ? 1.0 : 7.5);
		if(!$menuOpen && ($session->findingCircuit() || $session->preparingRoad()))
		{
			$lastAuthoringFrame = hrtime(true);
			$startSearchTimer();
		}
	}
	if($session->menuRequested)
	{
		$session->menuRequested = false;
		$pauseMenu->open($session);
		// Escape or Start opened the menu and is still down. It must not also close it.
		$engine->input->suppressHeldUi();
		$dirty = $urgentStatus = true;
	}
	$menuShown = $pauseMenu->menu->isOpen;
	if($pauseMenu->update($engine, $session, $frameSeconds, $menuPad)) $dirty = $statusPending = true;
	if($menuShown && !$pauseMenu->menu->isOpen) $urgentStatus = true;
	// Choices made in the menu reach the session as the page's would, through its checks.
	if(($chosen = $pauseMenu->takePending()) !== [])
	{
		$session->input($chosen + ['keys' => [], 'pad' => []], 0., $width / $height);
		$urgentStatus = true;
	}
	$urgentStatus = $urgentStatus || $keysChanged || isset($input['clicks'][0]) || $wasPicking !== $session->picking
		|| $previousRoadPoints !== count($session->road) || $wasSearching !== $session->findingCarSite()
		|| $wasFindingCircuit !== $session->findingCircuit();
	$pickFrame = $wasPicking || $session->picking;
	$statusPending = $statusPending || $session->preparingRoad() || $packet !== '' || $wasPicking || $wasSearching || $wasFindingCircuit || $prepared !== null;
	$inputMs = (hrtime(true) - $started) / 1e6;
	if($generation !== $session->generation)
	{
		$pendingEdit = null;
		$displayTerrain = $session->landscape;
		$scene->setLandscape($displayTerrain);
		$generation = $session->generation;
		$dirty = true;
	}
	$pendingEdit ??= $session->takeCandidate();
	$source = $pendingEdit ?? $session->landscape;
	$preview = $session->roadEditor->displayedLandscape($source);
	$hiddenGates = $session->roadEditor->hiddenGates($source,$session->course,$session->usingFloodedCircuit());
	if($displayTerrain !== $preview) {
		$scene->prepareLandscape($preview);
		$displayTerrain = $preview;
	}
	$scene->quality = $session->settings['quality'];
	// The same object while nothing changes, so the scene uploads the appearance only after a change.
	$scene->appearance = $session->terrainAppearance;
	$scene->layers = $session->displayedLayers();
	$scene->paint = $session->paint;
	$scene->selectionInterval = $session->selectionInterval;
	$scene->selectionSliceMs = $session->selectionSliceMs;
	$engine->userPaused = $session->paused;
	if(($download = $session->download()) !== null) (vrzno_env('terrainDownload'))($download,$session->downloadName);
	$dirty = $dirty || $session->dirty;
	return false;
});

$shadow = null;
$skidRenderer = null;
$grass = null;
$traffic=[];
$drawOpaque = static function(Session $session, Scene $scene, array $hiddenGates, array $events) use (&$traffic, &$lightSources, $gates, $curveSigns, $journeySigns, &$spotLights, &$models, &$tracers, &$shotAlpha, &$shadow, &$skidRenderer, &$width, &$height, &$viewCamera, &$vehiclePose, &$beacon, &$visibleSites, &$grass): void {
	// Grass multiplies the ground the terrain pass drew, so it comes first: signs, shadows and vehicles then cover it.
	$grassQuality = (int)round($session->settings['grass']);
	if($grassQuality > 0 && array_filter($styles = $session->grassStyles()))
	{
		$steps = GrassShells::steps($grassQuality);
		[$eyeX,$eyeY,$eyeZ] = $viewCamera->position;
		// The chase camera can swing past the edge while the tires are still on the map.
		$half = $session->landscape->recipe()['worldSize']/2;
		$floor = max($session->landscape->height(max(-$half,min($half,$eyeX)),max(-$half,min($half,$eyeZ))), $session->water->level());
		($grass ??= new GrassShells)->draw($scene, $viewCamera, $styles, $session->water->level(), $session->time,
			$steps, $width, $height, $eyeY-$floor);
	}
	if($session->skidMarks->tiles)
		($skidRenderer ??= new TerrainSandbox\SkidRenderer)->draw($session->skidMarks,$scene,$viewCamera,$width,$height);
	$curveSigns->draw($viewCamera,$session->atmosphere,$session->water->level(),$width,$height,$spotLights);
	$journeySigns->draw($viewCamera,$session->atmosphere,$session->water->level(),$width,$height,$spotLights);
	foreach($visibleSites as $site)
		if($site['kind'] !== 'smoke') ($beacon ??= new DeliveryBeacon)->solid($site,$viewCamera,$width,$height);
	// The car's shadow darkens the ground under it, so it draws after the terrain and before the car.
	if($session->mode === 'car' && $vehiclePose !== null)
		($shadow ??= new GroundShadow)->draw($vehiclePose, $session->landscape, $viewCamera, $width, $height, $scene);
	if($session->mode !== 'fly' && !($session->mode === 'jet' && $session->jetView === 'cockpit'))
	{
		if($session->mode==='jet') $lightSources[]=[$vehiclePose,true];
		$name = match($session->mode) { 'car' => 'testarossa', 'jet' => 'f5', 'craft' => 'jetski' };
		$model = $models[$name] ??= new VehicleModel(__DIR__ . '/assets/' . $name . '/mesh.bin.gz');
		$model->draw($vehiclePose,
			$viewCamera, $session->atmosphere, $session->water, $width, $height);
	}
	if($session->trial !== null && $session->rivalPose !== null) {
		$rival = $models['testarossa'] ??= new VehicleModel(__DIR__ . '/assets/testarossa/mesh.bin.gz');
		$rival->draw($session->rivalPose,$viewCamera,$session->atmosphere,$session->water,$width,$height);
	}
	$projectiles = [];
	if($session->mode==='car') {
		$patrol = $events ? ($events['airPatrol']['pose'] ?? null) : $session->airPatrol->pose($shotAlpha);
		$seeker=$events ? ($events['airPatrol']['seeker'] ?? null) : $session->airPatrol->seeker($shotAlpha);
		if($patrol !== null) {
			$model = $models['f5'] ??= new VehicleModel(__DIR__.'/assets/f5/mesh.bin.gz');
			$traffic[]=['plane',$patrol['x'],$patrol['z']];
			$lightSources[]=[$patrol,true];
			$model->draw($patrol+['power'=>.65],$viewCamera,$session->atmosphere,$session->water,$width,$height);
			foreach(SpeedSeeker::mountedSlots($seeker) as $slot) $projectiles[]=Jet::projectileMount($patrol,$slot);
		}
		if(isset($seeker['mount']) && $session->airPatrol->smoke?->showsLodgedSeeker()) $projectiles[]=SeekerMount::model($seeker['mount'],$vehiclePose);
		elseif(($flyingSeeker=SpeedSeeker::modelPose($seeker))!==null) {
			$projectiles[]=$flyingSeeker;
			$lightSources[]=[$flyingSeeker,false];
			$traffic[]=['missile',$flyingSeeker['x'],$flyingSeeker['z']];
		}
	}
	if($session->mode === 'jet' && $session->jetView === 'chase')
	{
		foreach($events['slots'] ?? $session->balloons->readySlots() as $slot)
			$projectiles[] = Jet::projectileMount($vehiclePose,$slot);
	}
	foreach($events['balloons'] ?? $session->balloons->targets() as [$x,$y,$z])
	{
		$model = $models['balloon'] ??= new VehicleModel(__DIR__ . '/assets/balloon/mesh.bin.gz');
		$model->draw(['x'=>$x,'y'=>$y,'z'=>$z,'yaw'=>0,'pitch'=>0,'roll'=>0],
			$viewCamera,$session->atmosphere,$session->water,$width,$height);
	}
	foreach($events['beachBalls'] ?? $session->balloons->beachBalls->states() as [$x,$y,$z,,,,$pitch,$roll]) {
		$model=$models['beach-ball'] ??= new VehicleModel(__DIR__.'/assets/beach-ball/mesh.bin.gz');
		$model->draw(['x'=>$x,'y'=>$y,'z'=>$z,'yaw'=>0.,'pitch'=>$pitch,'roll'=>$roll],
			$viewCamera,$session->atmosphere,$session->water,$width,$height);
	}
	$streaks = [];
	foreach($events['shots'] ?? $session->balloons->shots as $shot)
	{
		if(isset($events['shots'])) {
			[$tracer,$x,$y,$z,$dx,$dy,$dz,$roll] = $shot;
			$position = [$x,$y,$z]; $dir = [$dx,$dy,$dz];
		} else {
			$position = [];
			foreach($shot['previous'] as $i=>$from) $position[] = $from+($shot['position'][$i]-$from)*$shotAlpha;
			$dir = $shot['direction']; $roll = $shot['roll']; $tracer = $shot['tracer'];
		}
		if($tracer) { $streaks[] = [$position,$dir]; continue; }
		$traffic[]=['missile',$position[0],$position[2]];
		$projectiles[] = ['x'=>$position[0],'y'=>$position[1],'z'=>$position[2],'yaw'=>atan2($dir[0],$dir[2]),
			'pitch'=>asin(max(-1,min(1,$dir[1]))),'roll'=>$roll];
		$lightSources[]=[$projectiles[array_key_last($projectiles)],false];
	}
	if($projectiles)
	{
		$model = $models['projectile'] ??= new VehicleModel(__DIR__ . '/assets/projectile/mesh.bin.gz');
		foreach($projectiles as $pose) $model->draw($pose,$viewCamera,$session->atmosphere,$session->water,$width,$height);
	}
	if($streaks) ($tracers ??= new TracerBatch)->draw($streaks,$viewCamera,$width,$height);
	// Gates draw in free flight too, where the course is edited.
	if($session->course->count() > 0)
		$gates->draw($session->course, $viewCamera, $session->atmosphere, $session->water, $width, $height, $spotLights, $hiddenGates);
};

$liveSession = $session;
$liveScene = $scene;
$playback = null;
$engine->start(
	static function(Engine $engine, float $alpha) use (
		$liveSession, $liveScene, &$playback, &$hiddenGates, $drawOpaque, &$traffic, &$lightSources, &$flightLights, $gates, $curveSigns, $journeySigns, &$viewCamera, &$vehiclePose, &$width, &$height, &$dirty, &$lastStatus, &$lastJourneyPlan, &$lastRender, &$drawIntervalMs, &$lastRenderedTime,
		&$renderMs, &$frameSeconds, &$lastBudgetPose, &$statusPending, &$urgentStatus, &$inputMs, &$pickFrame, &$courseRevision, &$course,
		&$carLamps, &$spotLights, &$shotAlpha, &$trailRenderer, &$skidRenderer, &$beacon, &$arrow, &$arrowBearing, &$visibleSites, &$hudShown, $hud, $publish, $roadStatus, $roadCapture, $roadCamera, &$lastRoadCamera, $carSound, &$lastSound, $acoustics, $burstMode, $burstSound,
		$pauseMenu, &$displayTerrain, $journeyAtlas, $atlasResult
	): void {
		$tape = $liveSession->demo;
		$events = [];
		$session = $liveSession;
		$scene = $liveScene;
		$sample = null;
		if($playback && $playback->ready && !$tape->held && !$liveSession->menu && $tape->playhead !== null && $tape->playhead >= $tape->duration() && $playback->shown >= $tape->duration())
			$liveSession->endPlayback();
		if($tape->playhead !== null) {
			try {
				$playback ??= new DemoPlayback($tape);
				if($playback->ready && !$tape->held && !$liveSession->menu)
					$tape->playhead = min($tape->duration(),$tape->playhead + min(.25,$frameSeconds));
				$sample = $tape->frame($tape->playhead);
				$playback->apply($tape,$sample);
			} catch(\Exception $error) {
				$liveSession->endPlayback();
				$liveSession->message = 'Could not play demo. ' . $error->getMessage();
				$sample = null;
			}
		}
		if($sample !== null) {
			$events = $sample['events'];
			$dirty = $dirty || !$playback->ready || $playback->shown !== $tape->playhead;
			$playback->shown = $tape->playhead;
			$session = $playback->world;
			$scene = $playback->scene;
			$session->paused = $liveSession->menu || $tape->held;
			$session->inputTheme = $liveSession->inputTheme;
		} elseif($playback) {
			$playback->close();
			$playback = null;
			$dirty = $urgentStatus = true;
		}
		$tape->capture($liveSession);
		$shotAlpha = $session->paused ? 1.0 : $alpha;
		$lastVehicle = $vehiclePose;
		[$viewCamera,$vehiclePose] = $sample === null ? $session->view($shotAlpha)
			: [$sample['camera'],$sample['mode'] === 'fly' ? null : $sample['vehicle']];
		if($sample && $vehiclePose !== null) {
			$vehiclePose['motion'] = 'driving';
			$vehiclePose['floodedHold'] = false;
		}
		$burstSource = match($session->mode) {
			'car' => $session->airPatrol->smoke, 'jet' => $session->flightTrails, default => null
		};
		if($session->paused) $burstSource?->takeBursts();
		$acoustics->frame($session->paused ? null : $burstSource,$session->landscape,$viewCamera,
			$session->water->level(),$burstMode,$burstSound,$frameSeconds);
		$sound = $session->mode === 'car' && !$session->paused && !$vehiclePose['floodedHold']
			? [(int)round($vehiclePose['rpm']), round($vehiclePose['engineLoad'],3), round($vehiclePose['tireSqueal'],3)] : [0,0,0];
		if($sound !== $lastSound)
		{
			$carSound(...CarEngineSound::controls(...$sound));
			$lastSound = $sound;
		}
		// Lamp levels blend between steps like the pose, and a change in either redraws the frame below.
		if(!$sample && $session->mode === 'car') $vehiclePose['lamps'] = $session->carLamps($alpha);
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
		$moving = ($session->mode === 'craft' && !$session->paused) || $pose !== $lastBudgetPose;
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
		$target = $vehiclePose === null ? null : ($sample ? ($events['target'] ?? null) : $session->navigationTarget($vehiclePose));
		$previousBearing = $arrowBearing;
		$arrowBearing = $target === null ? null : NavigationArrow::bearing($target, $vehiclePose, $previousBearing,
			$session->paused ? 0.0 : $frameSeconds);
		if($arrowBearing !== $previousBearing) $dirty = true;
		$hadVisibleSmoke = (bool)$visibleSites;
		$visibleSites = [];
		foreach($events['sites'] ?? $session->delivery?->beacons($session->time) ?? [] as $site)
			if(DeliveryRun::beaconInView($site,$viewCamera,$width/$height)) $visibleSites[] = $site;
		if(count($visibleSites) > 1)
		{
			[$forward] = $viewCamera->basis();
			// Blend all visible columns back to front, including smoke left by collected boxes.
			usort($visibleSites, static fn($a,$b) => (($b['x']-$a['x'])*$forward[0]
				+ ($b['y']-$a['y'])*$forward[1] + ($b['z']-$a['z'])*$forward[2]) <=> 0.);
		}
		if(!$dirty && $lightSources && FlightLights::beaconPulse($session->time)!==FlightLights::beaconPulse($lastRenderedTime)) $dirty=true;
		if(($hadVisibleSmoke || $visibleSites) && $session->time !== $lastRenderedTime) $dirty = true;
		$signTerrain = $sample ? $playback->terrain : $displayTerrain;
		$dirty = $curveSigns->update($signTerrain) || $dirty;
		$dirty = $journeySigns->update($signTerrain,$session->journeyEndpoints(),
			$signTerrain === $session->landscape ? $session->journeySigns : null) || $dirty;
		$work = $scene->dirty() || $moving || $dirty || $session->dirty;
		// A stationary click gets the generation slice until its confirmed marker is published.
		if($pickFrame && !$moving && !$dirty) $work = false;
		$prepared = $work && $scene->update($frameSeconds, $viewCamera,
			$session->mode === 'car' ? Car::terrainFocus($vehiclePose) : null);
		$wavesChanged = $scene->showsWater() && $session->time !== $lastRenderedTime;
		$statusPending = $statusPending || $work || $wavesChanged || !$session->paused;
		$dirty = $dirty || $prepared || $session->dirty || $wavesChanged || $session->weather->moving($session->time,$lastRenderedTime)
			|| $session->atmosphere->starsMoving($session->time,$lastRenderedTime);
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
		$dirty = $dirty || $captureChart || $journeyAtlas->needsChart;
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
			if($journeyAtlas->needsChart) $journeyAtlas->capture($scene,$session,$width,$height,$atlasResult);
			if(!$session->skidMarks->tiles && $skidRenderer) { $skidRenderer->close(); $skidRenderer=null; }
			$engine->device->begin([.15,.25,.35,1]);
			$traffic=[]; $lightSources=[];
			$scene->render($viewCamera, $width, $height, $session->time,
				drawOpaque: !$session->skidMarks->tiles && $session->mode === 'fly' && $session->course->count() === 0 && !$curveSigns->count && !$journeySigns->count && !($events['balloons'] ?? $session->balloons->targets())
					&& !($events['beachBalls'] ?? $session->balloons->beachBalls->states()) ? null
					: fn() => $drawOpaque($session,$scene,$sample ? $playback->hiddenGates : $hiddenGates,$events));
			$trails = match($session->mode) { 'jet' => $session->flightTrails, 'car' => $session->tireDust, default => null };
			if($trails?->active())
				($trailRenderer ??= new TrailRenderer)->draw($trails,$shotAlpha,$viewCamera,$width,$height,
					$session->atmosphere->uniforms()['nightVisibility']);
			if($session->mode==='car' && $session->airPatrol->smoke?->active())
				($trailRenderer ??= new TrailRenderer)->draw($session->airPatrol->smoke,$sample ? 1. : $shotAlpha,$viewCamera,$width,$height,
					$session->atmosphere->uniforms()['nightVisibility'],closeExhaust:true);
			foreach($visibleSites as $site) ($beacon ??= new DeliveryBeacon)->smoke($site,$viewCamera,$width,$height,$session->time);
			if($lightSources) ($flightLights ??= new FlightLights)->draw($lightSources,$viewCamera,$width,$height,$session->time,$session->atmosphere->uniforms()['nightVisibility']);
			if($target !== null) ($arrow ??= new NavigationArrow)->draw($target,$arrowBearing,$viewCamera,$width,$height);
			$jet = $session->mode === 'jet';
			$car = $session->mode === 'car';
			$craft = $session->mode === 'craft';
			$raceGuidance = $events['race']['guidance'] ?? $session->race->showGuidance($session->course);
			$waterRace = $craft && $raceGuidance && $session->usingFloodedCircuit();
			$instrumentSize = $car || $craft ? (int)round(min(320, $width*.36, $height*.40)) : null;
			$frame = $jet || $car || $craft ? ['width'=>$width,'height'=>$height,'vehicle'=>$session->mode,'traffic'=>$traffic] : null;
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
						'mileMarkers'=>$journeySigns->mileMarkers(),
						'showRoute'=>$raceGuidance, 'markers'=>($events['sites'] ?? $session->delivery?->sites()) ?: ($target === null ? [] : [$target])];
			}
			// The pause menu draws last, over the frozen frame and its charts.
			$pauseMenu->render($width, $height, $session->inputTheme);
			$engine->device->present();
			if($sample && !$playback->ready) $playback->ready = $scene->stats()['visible'] > 0;
			if($jet)
			{
				$frame += ['eye'=>$viewCamera->position,'basis'=>$viewCamera->basis(),
					'fov'=>$viewCamera->fov,'jet'=>$vehiclePose,'thrust'=>TerrainSandbox\Jet::thrustPercent($vehiclePose['power']),'cockpit'=>$session->jetView === 'cockpit',
					'range'=>$events['range'] ?? $session->balloons->status(),'ring'=>TerrainSandbox\BalloonPractice::RING,
					'contactRange'=>TerrainSandbox\BalloonPractice::CONTACT_RANGE,
					'targets'=>isset($events['balloons']) ? $events['balloons']+BeachBalls::positions($events['beachBalls'] ?? []) : $session->balloons->contacts()];
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
			$status = $session->status() + ['screenshotView' => $pauseMenu->screenshotView(), 'renderer' => $scene->stats() + ($session->course->count() > 0
					? ['gateDraws' => $gates->drawn, 'gateMs' => $gates->ms] : ['gateDraws' => 0, 'gateMs' => 0.0])
					+ ['spotLights' => count($spotLights[0]),'curveSigns'=>$curveSigns->count,'curveSignsDrawn'=>$curveSigns->drawn,
						'journeySigns'=>$journeySigns->count,'journeySignsDrawn'=>$journeySigns->drawn],
				'renderMs' => $renderMs, 'drawIntervalMs' => $drawIntervalMs,
				'viewport' => [$width,$height],'simulationMs' => $engine->simulationCpuMs,
				'generationBudgetMs' => $budget,'inputMs' => $inputMs,'frameIntervalMs' => $frameSeconds*1000];
			if($sample) {
				$status['demo'] = $liveSession->demoStatus() + ['world'=>$sample['world']];
				$status['menu'] = $liveSession->menu;
				$status['menuRow'] = $liveSession->menuRow;
				$status['message'] = 'Playing demo';
				$status['race'] = ($events['race'] ?? []) + $status['race'];
				$status['journeySplit'] = $events['journeyClock']['split'] ?? null;
				$status['delivery'] = $events['delivery'] ?? null;
				$status['balloons'] = ($events['range'] ?? []) + $status['balloons'];
				$status['airPatrol'] = $events['airPatrol'] ?? null;
				$status['beachBalls'] = BeachBalls::positions($events['beachBalls'] ?? []);
				if($vehiclePose !== null) $status[$sample['mode']] = $vehiclePose;
			}
			// A large route graph crosses the UI bridge only when the active plan changes.
			if($session->journeyPlan() !== $lastJourneyPlan) {
				$lastJourneyPlan = $session->journeyPlan();
				$status['journeyNetwork'] = $lastJourneyPlan === null ? null
					: ['plan'=>$lastJourneyPlan,'nodes'=>$session->journeyNodes()];
			}
			$publish(json_encode($status, JSON_THROW_ON_ERROR));
			$lastStatus = $now;
			$statusPending = $urgentStatus = false;
		}
	},
	static function() use ($engine, $session, $scene, &$playback, $stopSearchTimer, $gates, $curveSigns, $journeySigns, &$models, &$tracers, &$trailRenderer, &$flightLights, &$beacon, &$arrow, &$shadow, &$skidRenderer, &$grass, $pauseMenu, $journeyAtlas, $window, &$verificationTimer): void {
		if($verificationTimer !== null) $window->clearInterval($verificationTimer);
		$journeyAtlas->cancel();
		$stopSearchTimer();
		$engine->systems->remove('terrain-sandbox.delivery-search');
		foreach($models as $model) $model->close();
		$tracers?->close(); $tracers = null;
		$trailRenderer?->close(); $trailRenderer = null;
		$flightLights?->close(); $flightLights = null;
		$skidRenderer?->close(); $skidRenderer=null;
		$shadow?->close();
		$grass?->close();
		$beacon?->close(); $arrow?->close();
		$pauseMenu->release();
		$gates->release();
		$curveSigns->close();
		$journeySigns->close();
		$playback?->close();
		$scene->close(); $session->close();
	},
	static function() use ($scene, $session, &$playback, $gates, $curveSigns, $journeySigns, &$models, &$tracers, &$trailRenderer, &$flightLights, &$beacon, &$arrow, &$shadow, &$skidRenderer, &$grass, &$dirty, $pauseMenu): void {
		foreach($models as $model) $model->close();
		$models = [];
		$tracers?->close(); $tracers = null;
		$trailRenderer?->close(); $trailRenderer = null;
		$flightLights?->close(); $flightLights = null;
		// The lost context took the shadow's program and grid with it; the next frame that needs it builds them again.
		$skidRenderer?->close(); $skidRenderer=null;
		$shadow?->close();
		$shadow = null;
		$grass?->close();
		$grass = null;
		$beacon?->close(); $beacon = null;
		$arrow?->close(); $arrow = null;
		$pauseMenu->release();
		$gates->release();
		$curveSigns->close();
		$journeySigns->close();
		$scene->restore();
		$playback?->scene->restore();
		$session->roadEditor->captured = null;
		$dirty = true;
	}
);

$app->ready();
