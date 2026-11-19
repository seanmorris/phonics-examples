<?php
declare(strict_types=1);

$package = getenv('PHONICS_TERRAIN_ROOT') ?: dirname(__DIR__, 3) . '/packages/terrain-phonics';
require_once $package . '/autoload.php';
require_once dirname(__DIR__) . '/autoload.php';

use TerrainSandbox\Session;

$checks = 0;
function expect(bool $condition, string $message): void
{
	global $checks;
	$checks++;
	if(!$condition) throw new RuntimeException($message);
}
function input(Session $session, array $value): void { $session->input($value, 1 / 60, 16 / 9); }
function finishPicking(Session $session): void
{
	for($i = 0; $session->picking && $i < 1000; $i++) input($session, []);
	expect(!$session->picking, 'A bounded picking query eventually completes');
}
function publish(Session $session): void
{
	$candidate = $session->takeCandidate();
	expect($candidate !== null, 'An edited candidate must be handed to the renderer');
	$session->publishLandscape($candidate);
}

$session = new Session(['worldSize' => 256,'heightScale' => 0,'detailAmplitude' => 0]);
expect($session->landscape->height(0, 0) === 0.0, 'Fixture is flat');
expect($session->settings['tool'] === 'navigate', 'Default interaction does not accidentally alter terrain');
$session->dirty = false;
$session->step(1 / 60);
expect(!$session->dirty && $session->time > 0, 'Wave time alone does not redraw an unchanged sky during terrain preparation');
input($session, ['values' => ['clock' => true]]);
$session->dirty = false;
$session->step(1 / 60);
expect($session->dirty, 'An animated sun still redraws the sky before terrain coverage is ready');
input($session, ['values' => ['clock' => false]]);
$session->camera->position = [0,30,0];
$session->camera->pitch = -M_PI / 2 + .001;
$session->camera->yaw = 0;
input($session, ['values' => ['tool' => 'raise','radius' => 20,'amount' => 12],'clicks' => [[.5,.5]]]);
finishPicking($session);
expect($session->landscape->height(0, 0) === 0.0, 'Edits do not publish collision before matching graphics');
$candidate = $session->takeCandidate();
expect($candidate !== null && $candidate->height(0, 0) > 11.9, 'Click raises the picked terrain in the candidate');
expect($session->preparing, 'UI exposes preparation state');
input($session, ['actions' => ['undo']]);
expect($session->landscape->height(0, 0) === 0.0, 'New edits cannot disrupt a prepared transaction');
$session->publishLandscape($candidate);
expect(!$session->preparing && $session->landscape->height(0, 0) > 11.9, 'Prepared terrain publishes atomically');
expect($session->generation === 0, 'Prepared edit does not reset the renderer generation');

input($session, ['actions' => ['undo']]);
expect($session->landscape->height(0, 0) > 11.9, 'Undo also stages its physical change');
publish($session);
expect(abs($session->landscape->height(0, 0)) < 1e-9, 'Undo restores flat terrain');
input($session, ['actions' => ['redo']]);
publish($session);
expect($session->landscape->height(0, 0) > 11.9, 'Redo restores the edited surface');

input($session, ['values' => ['exposure' => 2,'haze' => .5,'elevation' => 8,'nightGlow' => .78]]);
$saved = $session->save();
$beforeHeight = $session->landscape->height(1, 1);
$session->load($saved);
expect($session->generation === 1, 'Load replaces the landscape once');
expect(abs($session->landscape->height(1, 1) - $beforeHeight) < 1e-9, 'Save/load preserves terrain edits');
expect($session->settings['exposure'] === 2.0 && $session->settings['elevation'] === 8.0, 'Save/load preserves environment');
expect($session->atmosphere->uniforms()['nightGlow'] === .78, 'Saved glow reaches the renderer');
$legacy = json_decode($saved, true, flags: JSON_THROW_ON_ERROR);
unset($legacy['settings']['nightGlow']);
$session->load(json_encode($legacy, JSON_THROW_ON_ERROR));
expect($session->settings['nightGlow'] === TerrainPhonics\Atmosphere::DEFAULT_NIGHT_GLOW, 'Legacy saves restore the default night glow');
$before = $session->save();
try { $session->load('{"version":999}'); expect(false, 'Malformed save must be rejected'); }
catch(InvalidArgumentException) { expect($session->save() === $before, 'Rejected save cannot alter the active scene'); }
foreach([['position' => ['x' => 0,'y' => 40,'z' => 0]],['yaw' => 'invalid']] as $invalidCamera)
{
	$invalid = json_decode($before, true, flags: JSON_THROW_ON_ERROR);
	$invalid['camera'] = $invalidCamera + $invalid['camera'];
	try { $session->load(json_encode($invalid, JSON_THROW_ON_ERROR)); expect(false, 'Malformed camera must be rejected'); }
	catch(InvalidArgumentException) { expect($session->save() === $before, 'Rejected camera leaves the current scene intact'); }
}

input($session, ['actions' => ['pause']]);
$time = $session->time;
$session->step(1 / 60);
expect($session->paused && $session->time === $time, 'Pause freezes the simulation clock');
$position = $session->camera->position;
input($session, ['keys' => ['KeyE' => true]]);
expect($session->camera->position[1] > $position[1], 'Paused authoring still permits flight');
input($session, ['actions' => ['pause']]);
$session->step(1 / 60);
expect(!$session->paused && $session->time > $time, 'Resume advances the simulation');

input($session, ['actions' => [['type' => 'generate','preset' => 'canyon','seed' => 'another-seed']]]);
expect($session->landscape->recipe()['preset'] === 'canyon', 'Generation uses the requested preset');
expect($session->landscape->recipe()['seed'] === 'another-seed', 'Generation uses the requested seed');
expect($session->landscape->recipe()['version'] === 2, 'Generating Canyon country selects the bedded recipe');
expect($session->landscape->recipe()['terraceSpacing'] === 80.0, 'Canyon generation supplies smooth terrace spacing by default');
expect($session->water->level() === -450.0, 'Canyon generation exposes its floor instead of flooding the valley');
expect($session->camera->yaw === 2.68 && $session->camera->pitch === -.17,
	'The canyon home points along the valley rather than overlooking flooded plateaus');
expect($session->landscape->stats()['operations'] === 0, 'Generation starts a fresh edit history');
input($session, ['actions' => [['type' => 'generate','preset' => 'canyon','seed' => 'authored',
	'warpAmplitude' => 480,'terraceSpacing' => 25,'heightScale' => 900]]]);
expect($session->landscape->recipe()['warpAmplitude'] === 480.0 && $session->landscape->recipe()['terraceSpacing'] === 25.0,
	'Advanced warp and terrace controls preserve explicit generation values');
expect($session->landscape->recipe()['heightScale'] === 900.0, 'Additional controls retain existing authoring overrides');
input($session, ['actions' => ['undo',['type' => 'generate','preset' => 'coast','seed' => 'last-action-wins']]]);
expect($session->takeCandidate() === null, 'Generation discards an older candidate from the same input batch');
expect($session->landscape->recipe()['seed'] === 'last-action-wins', 'The replacement remains authoritative');
expect($session->landscape->recipe()['terraceSpacing'] === 0.0, 'Non-canyon presets disable terraces unless explicitly requested');
expect($session->water->level() === 0.0, 'Other presets restore their sea-level default');
$ground = $session->landscape->height(13, 17);
input($session, ['actions' => [['type' => 'generate','preset' => 'coast','seed' => 'last-action-wins','waterLevel' => 40]]]);
expect($session->water->level() === 40.0, 'The generation water elevation controls the shared physical water');
expect(abs($session->landscape->height(13, 17) - $ground) < 1e-9, 'Changing sea level does not move the ground');
input($session, ['actions' => ['save']]);
expect(is_string($session->download()), 'Save queues a portable download');
expect($session->download() === null, 'Downloads are consumed once');
$session->close();
expect($session->landscape->stats()['closed'], 'Close releases the landscape');

$far = new Session;
expect(array_map('floatval', $far->camera->position) === [3600.0,300.0,1800.0] && $far->camera->yaw === .7,
	'The coastal home view faces editable land and its shoreline');
input($far, ['values' => ['tool' => 'raise'],'clicks' => [[.5,.5]]]);
expect($far->picking && $far->takeCandidate() === null, 'Distant picking yields before changing terrain');
input($far, ['values' => ['amount' => 199]]);
finishPicking($far);
publish($far);
$farSave = json_decode($far->save(), true, flags: JSON_THROW_ON_ERROR);
expect($farSave['landscape']['history'][0][0]['amount'] === 12, 'A delayed pick retains the brush settings from its click');
input($far, ['clicks' => [[.45,.5]]]);
expect($far->picking, 'Another distant click starts incremental picking');
input($far, ['actions' => [['type' => 'generate','preset' => 'coast','seed' => 'cancel-pick']]]);
expect(!$far->picking && $far->takeCandidate() === null, 'Replacing terrain cancels stale picking before closing its source');
$far->close();

$canyon = new Session;
input($canyon, ['actions' => [['type' => 'generate','preset' => 'canyon','seed' => 'first-light']]]);
expect(array_map('floatval', $canyon->camera->position) === [-340.0,50.0,1600.0],
	'The default canyon starts in the visually verified corridor');
expect($canyon->landscape->height(-340, 1600) > $canyon->water->level(), 'The canyon floor remains above water');
enterCar($canyon);
$start = $canyon->car->state();
$maxPitchStep = 0;
$clearance = INF;
$frameError = 0;
input($canyon, ['keys'=>['KeyW'=>true]]);
for($i = 0; $i < 600; $i++)
{
	$lastPitch = $canyon->camera->pitch;
	$canyon->step(1/60);
	$camera = $canyon->camera;
	[$x,$y,$z] = $camera->position;
	$car = $canyon->car->state();
	$maxPitchStep = max($maxPitchStep, abs($camera->pitch - $lastPitch));
	$clearance = min($clearance, $y - $canyon->landscape->height($x,$z));
	$angle = atan2($car['y'] + .65 - $y, hypot($car['x']-$x, $car['z']-$z)) - $camera->pitch;
	$frameError = max($frameError, abs(tan($angle)/tan($camera->fov/2)));
}
expect(hypot($car['x']-$start['x'],$car['z']-$start['z']) > 230 && $maxPitchStep < deg2rad(.25),
	'The chase horizon stays steady at speed on the default canyon floor');
expect($clearance >= 1.349 && $frameError < .55, 'The camera clears the ground and keeps the chassis in frame');
[$previousEye,$previousCar] = $canyon->carView(0);
[$halfEye,$halfCar] = $canyon->carView(.5);
expect(abs(2*$halfEye->position[2] - $previousEye->position[2] - $camera->position[2]) < 1e-8
	&& abs(2*$halfCar['z'] - $previousCar['z'] - $car['z']) < 1e-8 && $previousCar['z'] < $halfCar['z'],
	'The rendered car and eye advance through the same fraction of the physics step');
input($canyon, ['actions'=>['pause']]);
expect($canyon->carView(0) === [$camera,$car], 'Pausing flushes both interpolation histories');
$canyon->close();

$roads = new Session(['worldSize' => 256,'heightScale' => 0,'detailAmplitude' => 0]);
$roads->camera->position = [0,40,0]; $roads->camera->pitch = -1.5;
input($roads, ['values' => ['tool' => 'road'],'clicks' => [[.3,.5],[.6,.5]]]);
finishPicking($roads);
expect(count($roads->road) === 2, 'Rapid road clicks queue distinct exact picks without losing a point');
$roads->close();

$bay = new Session(['worldSize' => 128, 'waterLevel' => 6, 'heightScale' => 0]);
$legacy = json_decode($bay->save(), true, flags: JSON_THROW_ON_ERROR);
$legacy['settings'] = [];
$physical = $bay->water->sample(2, 4, 1);
$bay->dirty = false;
$optics = ['waterColor' => '#316fc0', 'waterTransparency' => .5, 'waterReflectivity' => .2,
	'waterFogDensity' => .12, 'waterFoam' => .7];
input($bay, ['values' => $optics]);
expect($bay->dirty && $bay->water->appearance->settings === $optics, 'Appearance edits reach the renderer and request a frame');
expect($bay->water->sample(2, 4, 1) === $physical && $bay->water->revision() === 0, 'Optical edits preserve the physical water');
$generation = $bay->generation;
$terrain = $bay->landscape->save();
input($bay, ['actions' => ['pause']]);
$bay->dirty = false;
input($bay, ['values' => ['waterLevel' => 12.5, 'waterChoppiness' => 0]]);
expect($bay->dirty && $bay->paused && $bay->water->sample(2, 4, 1)['height'] === 12.5,
	'Live water controls reach the physical surface and request a paused frame');
expect($bay->landscape->save() === $terrain && $bay->generation === $generation,
	'Live water changes preserve terrain edits and generation');
$custom = $bay->save();
$bay->load(json_encode($legacy, JSON_THROW_ON_ERROR));
expect($bay->water->level() === 6.0 && $bay->water->sample(2, 4, 1) === $physical, 'Older files restore recipe elevation and original swell');
expect($bay->water->appearance->settings === TerrainPhonics\WaterAppearance::DEFAULTS, 'Older landscapes get the default water appearance');
$bay->load($custom);
expect($bay->water->appearance->settings === $optics, 'All optical settings round-trip');
expect($bay->water->level() === 12.5 && $bay->water->waveScale() === 0.0, 'Live sea state round-trips');
$generation = $bay->generation;
try { $bay->load(str_replace('#316fc0', '#zzzzzz', $custom)); expect(false, 'Invalid water colors must be rejected'); }
catch(InvalidArgumentException) { expect($bay->generation === $generation && $bay->save() === $custom, 'Invalid optics leave the current scene intact'); }
$bay->close();

$coast = new Session;
$heading = M_PI - $coast->camera->yaw;
input($coast, ['actions' => ['craft'], 'keys' => ['KeyW' => true]]);
$launch = $coast->craft->state();
expect($coast->mode === 'craft' && $launch['yaw'] === $heading, 'Automatic coastal launch keeps the flight heading');
$contacts = 0;
for($i = 0; $i < 480; $i++)
{
	$coast->step(1 / 60);
	$contacts += $coast->craft->state()['contacts'];
}
$run = $coast->craft->state();
expect($contacts === 0 && hypot($run['x'] - $launch['x'], $run['z'] - $launch['z']) > 150,
	'Default coastal launch has room to accelerate straight ahead before reaching shore');
$coast->close();

$bay = new Session(['worldSize' => 128, 'waterLevel' => 5, 'heightScale' => 0, 'detailAmplitude' => 0]);
$bay->camera->position = [12,20,-5];
input($bay, ['actions' => ['craft']]);
$launch = $bay->craft->state();
expect($launch['x'] === 12.0 && $launch['z'] === -5.0, 'Boarding over deep water keeps the chosen location on a small lake');
input($bay, ['values' => ['waterLevel' => 10, 'waterChoppiness' => 0]]);
for($i = 0; $i < 240; $i++) $bay->step(1 / 60);
expect($bay->mode === 'craft' && $bay->craft->state()['y'] > $launch['y'] + 4,
	'The boarded jet ski floats up when the lake rises');
$bay->close();

function enterCar(Session $session): void
{
	input($session, ['actions' => ['car']]);
	for($i = 0; $session->findingCarSite() && $i < 2000; $i++) input($session, []);
	expect(!$session->findingCarSite(), 'The bounded car search completes');
}

$flat = ['worldSize' => 128, 'heightScale' => 0, 'detailAmplitude' => 0, 'waterLevel' => -5];
$drive = new Session($flat);
$drive->camera->position = [.25,50,0]; $drive->camera->yaw = M_PI;
enterCar($drive);
$s = $drive->car->state();
expect($drive->mode === 'car' && $s['x'] === .25 && $s['z'] === 0.0, 'Board in place on dry planar ground');
expect(abs($s['y'] - .02) < .001 && $s['pitch'] === 0.0 && abs($s['roll']) < .001, 'Tires rest on flat ground');
$drive->dirty = false;
$drive->step(1/60);
expect(!$drive->dirty, 'A parked car leaves the dry scene idle');
input($drive, ['keys' => ['KeyW'=>true,'KeyD'=>true]]);
for($i = 0; $i < 60; $i++) $drive->step(1/60);
$s = $drive->car->state();
expect($s['z'] > 2 && $s['x'] < 0 && $s['speed'] > 6 && $s['steering'] < 0 && $s['wheelSpin'] != 0,
	'Forward right steering turns toward the driver’s right and rolls the wheels');
input($drive, ['actions'=>['pause'], 'keys'=>['KeyS'=>true]]);
$drive->step(1/60);
expect($drive->car->state() === $s, 'Pause freezes vehicle motion');
input($drive, ['actions'=>['pause'], 'keys'=>['KeyS'=>true,'KeyD'=>true]]);
for($i = 0; $i < 100; $i++) $drive->step(1/60);
$reverse = $drive->car->state();
$drive->step(1/60);
expect($reverse['speed'] < -2 && $drive->car->state()['yaw'] > $reverse['yaw'], 'Braking engages reverse and reverses the turn');
input($drive, ['values'=>['tool'=>'raise'],'clicks'=>[[.5,.5]]]);
expect(!$drive->picking && $drive->takeCandidate() === null, 'Clicks while driving cannot edit terrain');
input($drive, ['values'=>['waterLevel'=>10]]);
$drive->step(1/60);
expect($drive->mode === 'fly', 'Flooding the car returns control to flight');
input($drive, ['actions'=>['craft']]);
$craft = $drive->craft->state();
enterCar($drive);
expect($drive->mode === 'craft' && $drive->craft->state() === $craft && str_contains($drive->message, 'No dry'),
	'An unsuccessful search leaves the boarded vehicle in place');
$drive->close();

$road = new Session($flat);
$road->landscape->queue(['type'=>'road','points'=>[[0,2,-45],[0,8,45]],'width'=>10,'shoulder'=>1,'crown'=>0]);
$road->landscape->commit();
input($road, ['values'=>['waterLevel'=>1]]);
$road->camera->position = [30,30,0]; $road->camera->yaw = M_PI;
enterCar($road);
$s = $road->car->state();
expect($road->mode === 'car' && abs($s['x']) < 5 && abs($s['pitch']) > .04 && abs($s['roll']) < .02,
	'Search relocates from water to the gently graded dry road');
expect(abs($s['y'] - $road->landscape->height($s['x'],$s['z']) - .02) < .02, 'Car rests on the edited road elevation');
input($road, ['keys'=>['KeyW'=>true]]);
for($i = 0; $i < 600; $i++) $road->step(1/60);
$s = $road->car->state();
expect($s['z'] <= 52 && $s['speed'] < 1 && $road->landscape->height($s['x'],$s['z']) > 1, 'Car stops before leaving the road for water');
$road->close();

$ledge = new Session($flat);
$ledge->landscape->queue(['type'=>'raise','x'=>0,'z'=>26,'radius'=>6,'amount'=>30]);
$ledge->landscape->commit();
$ledge->camera->position = [0,20,0]; $ledge->camera->yaw = M_PI;
input($ledge, ['actions'=>['car','fly']]);
expect(!$ledge->findingCarSite() && $ledge->mode === 'fly', 'Flight cancels a pending boarding request');
$ledge->close();

// The road runs across the car's path. Both turns enter the same 31° bank at the same speed.
$dry = ['worldSize'=>512,'spacing'=>1,'heightScale'=>0,'detailAmplitude'=>0,'waterLevel'=>-100];
foreach(['KeyD','KeyA'] as $steer)
{
	$bank = new Session($dry);
	$bank->landscape->queue(['type'=>'road','points'=>[[-60,-36,0],[60,36,0]],'width'=>190,'shoulder'=>40,'crown'=>0]);
	$bank->landscape->commit();
	$bank->camera->position = [0,30,-155]; $bank->camera->yaw = M_PI;
	enterCar($bank);
	input($bank, ['keys'=>['KeyW'=>true]]);
	for($i = 0; $i < 265; $i++) $bank->step(1/60);
	$entry = $bank->car->state();
	expect($entry['speed'] > 26 && abs($entry['roll']) > deg2rad(30), 'A drivable bank carries speed through the former slope cutoff');
	input($bank, ['keys'=>['KeyW'=>true,$steer=>true]]);
	for($i = 0; $i < 24; $i++) $bank->step(1/60);
	$exit = $bank->car->state();
	$turns[$steer] = $exit['yaw'] - $entry['yaw'];
	expect($exit['motion'] === 'driving' && $exit['z'] > $entry['z'] + 10 && $exit['speed'] >= $entry['speed'],
		'The car stays on its tires and continues across the bank through the corner');
	$bank->close();
}
expect($turns['KeyD'] < -2*$turns['KeyA'] && $turns['KeyA'] > 0, 'Banking into the turn provides more cornering grip than adverse camber');

// Cross exactly the same crest at low speed and at full throttle.
foreach([false,true] as $fast)
{
	$jump = new Session($dry);
	$jump->landscape->queue(['type'=>'raise','x'=>0,'z'=>0,'radius'=>22,'amount'=>2.8]);
	$jump->landscape->commit();
	$jump->camera->position = [0,30,-75]; $jump->camera->yaw = M_PI;
	enterCar($jump);
	$air = 0; $peakGap = 0.0; $gravityError = 0.0; $drift = 0.0; $roll = 0.0;
	$landingStroke = 0.0; $previous = $older = $jump->car->state();
	for($i = 0; $i < 1700 && $previous['z'] < 60; $i++)
	{
		$flying = $previous['motion'] === 'airborne';
		input($jump, ['keys'=>['KeyW'=>!$flying && ($fast || $previous['speed'] < 6), 'KeyS'=>$flying,'KeyA'=>$flying]]);
		$jump->step(1/60);
		$pose = $jump->car->state();
		$roll = max($roll,abs($pose['roll']));
		if($pose['motion'] === 'airborne')
		{
			$air++;
			$peakGap = max($peakGap, $pose['y']-$jump->landscape->height($pose['x'],$pose['z']));
			if($flying && $older['motion'] === 'airborne')
			{
				$gravityError = max($gravityError,abs(3600*($pose['y']-2*$previous['y']+$older['y'])+9.81));
				$drift = max($drift,abs($pose['yaw']-$previous['yaw']),abs($pose['speed']-$previous['speed']),
					abs($pose['z']-2*$previous['z']+$older['z']));
			}
		}
		elseif($air) $landingStroke = max($landingStroke,max($pose['wheelTravel']));
		$older = $previous; $previous = $pose;
	}
	expect($pose['z'] >= 60 && $pose['motion'] === 'driving' && $pose['recoveries'] === 0, 'A clean crest crossing finishes under driver control');
	if($fast)
	{
		expect($air > 30 && $peakGap > 1 && $gravityError < 1e-5 && $drift < 1e-7,
			'All four tires can unload, with gravity alone curving the flight path even under braking and steering');
		expect($landingStroke > .08 && $pose['wheelContacts'] === 4, 'The tires take up the landing through real suspension travel');
	}
	else expect($air === 0 && $roll < 1e-6, 'A slow crossing keeps tire contact and a symmetric crest induces no roll');
	$jump->close();
}

$springs = new TerrainSandbox\CarSuspension;
$springs->reset([.02,0.0,0.0,0.0,[0,0,0,0]]);
$springs->step(1/60,[.07,0,0,0],true);
expect($springs->travel[0] > .05 && abs($springs->travel[1]) < .01 && $springs->y < .032,
	'A single tire takes up a short bump before the sprung chassis follows');
for($i = 0; $i < 240; $i++) $springs->step(1/60,[0,0,0,0],false);
expect($springs->resting && abs($springs->y-.02) < .001, 'A parked suspension settles back to its static ride height');

// Drive into a steep face from the same approach, varying its width to land on the roof or the tires.
foreach([[45,16,30,true],[55,32,34,false]] as [$z,$radius,$amount,$landsOnRoof])
{
	$ramp = new Session($dry);
	$ramp->landscape->queue(['type'=>'raise','x'=>0,'z'=>$z,'radius'=>$radius,'amount'=>$amount]);
	$ramp->landscape->commit();
	$ramp->camera->position = [0,30,$landsOnRoof ? -40 : -32]; $ramp->camera->yaw = M_PI;
	enterCar($ramp);
	input($ramp, ['keys'=>['KeyW'=>true]]);
	for($i = 0; $i < 500 && $ramp->car->state()['motion'] === 'driving'; $i++) $ramp->step(1/60);
	$launch = $ramp->car->state();
	expect($launch['motion'] === 'tumbling' && $launch['speed'] > 8, 'A steep face releases the car with its momentum');
	input($ramp, ['keys'=>[]]);
	$inverted = false;
	for($i = 0; $i < 1300 && $ramp->car->state()['motion'] === 'tumbling'; $i++)
	{
		$ramp->step(1/60);
		$pose = $ramp->car->state();
		if(cos($pose['pitch'])*cos($pose['roll']) < -.7) $inverted = true;
	}
	$landing = $ramp->car->state();
	if($landsOnRoof)
	{
		expect($inverted && $landing['motion'] === 'overturned' && $landing['y'] > .95,
			'The chassis rotates over the roof and comes to rest on its roof contacts');
		$ramp->landscape->queue(['type'=>'lower','x'=>$landing['x'],'z'=>$landing['z'],'radius'=>18,'amount'=>3]);
		$ramp->landscape->commit();
		for($i = 0; $i < 18; $i++) $ramp->step(1/60);
		$falling = $ramp->car->state();
		expect($falling['motion'] === 'tumbling' && $falling['y'] < $landing['y']-.2,
			'Removing supporting ground wakes the overturned chassis and interrupts recovery');
		for($i = 0; $i < 400 && $ramp->car->state()['motion'] === 'tumbling'; $i++) $ramp->step(1/60);
		$landing = $ramp->car->state();
		expect($landing['motion'] === 'overturned', 'Recovery begins afresh on the new surface');
		input($ramp, ['actions'=>['pause'],'keys'=>['KeyW'=>true]]);
		for($i = 0; $i < 180; $i++) $ramp->step(1/60);
		expect($ramp->car->state() === $landing, 'Pausing freezes the wreck and the recovery clock');
		input($ramp, ['actions'=>['pause'],'keys'=>['KeyW'=>true]]);
		for($i = 0; $i < 89; $i++) $ramp->step(1/60);
		expect($ramp->car->state() === $landing, 'The settled wreck waits in place even with the accelerator held');
		$ramp->step(1/60);
		$upright = $ramp->car->state();
		expect($upright['motion'] === 'driving' && $upright['recoveries'] === 1 && $upright['speed'] === 0.0
			&& abs($upright['roll']) < .05 && abs($upright['pitch']) < .05
			&& hypot($landing['x']-$upright['x'],$landing['z']-$upright['z']) < .1,
			'A settled roof landing rights in place after 1.5 seconds and clears crash momentum');
		$ramp->step(1/60);
		expect($ramp->car->state()['speed'] > 0, 'The pedals work again after recovery');
	}
	else expect($landing['motion'] === 'driving' && $landing['recoveries'] === 0 && abs($landing['speed']) > 2,
		'A wheels-down landing returns control while the chassis is still moving');
	$ramp->close();
}

$edge = new Session($flat);
$edge->camera->position = [0,20,40]; $edge->camera->yaw = M_PI;
enterCar($edge);
input($edge, ['keys'=>['KeyW'=>true]]);
for($i = 0; $i < 400; $i++) $edge->step(1/60);
$s = $edge->car->state();
expect($edge->mode === 'car' && $s['z'] > 59 && $s['z'] <= 61.4 && $s['speed'] < 1, 'Car stays inside the terrain at the world edge');
input($edge, ['actions'=>['fly']]);
expect($edge->mode === 'fly' && $edge->status()['car'] === null, 'Returning to flight retires the active car pose');
$edge->close();

$flight = new Session(['worldSize'=>4096,'heightScale'=>0,'detailAmplitude'=>0]);
$flight->camera->position = [0,1,0];
$flight->camera->yaw = 0;
input($flight, ['values'=>['waterLevel'=>20], 'actions'=>['pause','jet']]);
$spawn = $flight->status()['jet'];
expect($flight->mode === 'jet' && !$flight->paused && $spawn['y'] >= 95 && $spawn['yaw'] === M_PI,
	'Boarding from low over water starts airborne facing the current heading');
$flight->step(1/60);
expect($flight->jet->state()['z'] < $spawn['z'], 'The jet cruises forward without a held key');
input($flight, ['keys'=>['KeyW'=>true,'KeyD'=>true,'KeyE'=>true]]);
for($i=0; $i<60; $i++) $flight->step(1/60);
$turn = $flight->jet->state();
expect($turn['x'] > 0 && $turn['roll'] < 0 && $turn['yaw'] < $spawn['yaw'],
	'Right bank turns toward screen right when facing north');
expect($turn['y'] > $spawn['y'] && $turn['speed'] > $spawn['speed'], 'Throttle and climb carry the jet upward and accelerate it');
[$eye,$middle] = $flight->jet->view($flight->camera,.5);
expect($middle['y'] < $turn['y'] && abs($eye->position[1] - $middle['eyeY']) < 1e-9
	&& abs($eye->yaw + $middle['yaw'] - M_PI) < 1e-9, 'The chase camera and jet interpolate at the same presentation time');
input($flight, ['actions'=>['pause']]);
$flight->dirty = false;
$flight->step(1/60);
expect($flight->jet->state() === $turn && !$flight->dirty, 'Pausing freezes the jet without dirtying the frame');
input($flight, ['actions'=>['pause'], 'keys'=>['KeyA'=>true,'KeyQ'=>true,'KeyS'=>true]]);
for($i=0; $i<360; $i++) $flight->step(1/60);
$low = $flight->jet->state();
expect($flight->mode === 'jet' && $low['speed'] === 55.0 && abs($low['y'] - 28) < .001 && $low['roll'] > 0,
	'Diving and braking in a left turn respects the water clearance and minimum cruise speed');
input($flight, ['actions'=>['fly']]);
expect($flight->status()['jet'] === null && $flight->mode === 'fly', 'Leaving the jet restores free flight');
$flight->camera->position = [0,40,-2047];
$flight->camera->yaw = 0;
input($flight, ['actions'=>['jet']]);
$flight->step(1/60);
expect($flight->mode === 'fly' && str_contains($flight->message,'edge'), 'Flight at the map boundary returns control inside the landscape');
$flight->close();

// The page compares frame rates through one value that is neither an authoring setting nor part of a file.
$knob = new Session(['worldSize' => 256,'heightScale' => 0,'detailAmplitude' => 0]);
expect($knob->selectionInterval === TerrainPhonics\Scene::DEFAULT_SELECTION_INTERVAL
	&& $knob->status()['selectionInterval'] === $knob->selectionInterval,
	'The selection interval starts at the renderer default and the status reports it');
$settings = $knob->settings;
$file = $knob->save();
$knob->dirty = false;

input($knob, ['values' => ['selectionInterval' => 0]]);
expect($knob->selectionInterval === 0.0 && $knob->status()['selectionInterval'] === 0.0,
	'The page can turn the selection limiter off');
expect($knob->settings === $settings && $knob->save() === $file && !$knob->dirty,
	'The interval changes neither the saved settings, the save file nor the picture');
input($knob, ['values' => ['selectionInterval' => '0.25','exposure' => 2]]);
expect($knob->selectionInterval === .25 && $knob->settings['exposure'] === 2.0,
	'The interval travels beside ordinary settings in one input packet');

input($knob, ['values' => ['selectionInterval' => 5]]);
expect($knob->selectionInterval === 1.0, 'The interval is held to one second at most');
input($knob, ['values' => ['selectionInterval' => -1]]);
expect($knob->selectionInterval === 0.0, 'A negative interval turns the limiter off');
input($knob, ['values' => ['selectionInterval' => 'fast']]);
expect($knob->selectionInterval === 0.0 && $knob->message === 'Invalid control value',
	'An interval that is not a number is refused and reported');

input($knob, ['values' => ['selectionInterval' => .05]]);
$file = json_decode($knob->save(), true, flags: JSON_THROW_ON_ERROR);
expect(!array_key_exists('selectionInterval', $file) && !array_key_exists('selectionInterval', $file['settings']),
	'Save files do not record the interval');
$file['settings']['selectionInterval'] = .5;
$knob->load(json_encode($file, JSON_THROW_ON_ERROR));
expect($knob->selectionInterval === .05 && !array_key_exists('selectionInterval', $knob->settings),
	'A file cannot set the interval or add it to the settings');
$knob->close();

/**
 * Work out where one step leaves the jet and its chase eye when both sample the exact surface every time.
 * @param array $before Jet state before the step.
 * @param array $after Jet state after the step, which supplies the new attitude, speed and position.
 * @param TerrainPhonics\Landscape $ground Surface with the same recipe and edits as the one the jet flies over.
 * @param float $water Water level in metres.
 * @return array Jet height and eye height in metres.
 */
function exactJet(array $before, array $after, TerrainPhonics\Landscape $ground, float $water): array
{
	$travel = $after['speed'] * (1 / 60);
	$y = min(15000.0, $before['y'] + sin($after['pitch']) * $travel);
	$y = max($y, $ground->height($after['x'], $after['z']) + 8, $water + 8);
	$half = $ground->recipe()['worldSize'] / 2;
	$x = max(-$half, min($half, $after['x'] - $after['boom'] * sin($after['yaw'])));
	$z = max(-$half, min($half, $after['z'] - $after['boom'] * cos($after['yaw'])));
	$eye = max($water + 3, $y + 24.0 * sin(.38 - .75 * $after['pitch']));
	return [$y, max($eye, $ground->height($x, $z) + 3)];
}

/**
 * Fly the session's jet and compare every step with the exact rule.
 * @param Session $session Session with a boarded jet and held keys.
 * @param TerrainPhonics\Landscape $ground Second surface, so the exact rule leaves the session's cache alone.
 * @param int $steps Fixed steps to fly.
 * @return array Whether every step matched, and how many steps left the jet resting on the ground's 8 metre floor.
 */
function flyExactly(Session $session, TerrainPhonics\Landscape $ground, int $steps): array
{
	$matched = true;
	$resting = 0;
	for($i = 0; $i < $steps; $i++)
	{
		$before = $session->jet->state();
		$session->step(1 / 60);
		$after = $session->jet->state();
		$expected = exactJet($before, $after, $ground, $session->water->level());
		$matched = $matched && [$after['y'], $after['eyeY']] === $expected;
		$resting += (int)($after['y'] === $ground->height($after['x'], $after['z']) + 8);
	}
	return [$matched, $resting];
}

// The jet samples the ground only as finely as its height demands. A second landscape with the same recipe answers
// the exact rule, so the session's own counter shows the nodes that the jet alone generated.
$air = new Session;
$ground = new TerrainPhonics\Landscape($air->landscape->recipe());
$air->camera->position = [9000,900,1800];
$air->camera->yaw = M_PI / 2;
input($air, ['actions'=>['jet'], 'keys'=>['KeyW'=>true,'KeyD'=>true]]);
$nodes = $air->landscape->stats()['generated'];
[$matched] = flyExactly($air, $ground, 600);
expect($matched && $air->landscape->stats()['generated'] === $nodes,
	'A jet cruising high over the coast stays on the exact path without generating a lattice node');
input($air, ['keys'=>['KeyW'=>true,'KeyQ'=>true]]);
[$matched] = flyExactly($air, $ground, 420);
$nodes = $air->landscape->stats()['generated'];
[$skimmed, $resting] = flyExactly($air, $ground, 300);
expect($matched && $skimmed && $resting > 200 && $air->landscape->stats()['generated'] > $nodes + 3000,
	"A diving jet samples the true surface and rests on its 8 metre floor: $resting of 300 steps");
input($air, ['keys'=>['KeyW'=>true,'KeyE'=>true]]);
[$matched] = flyExactly($air, $ground, 240);
$nodes = $air->landscape->stats()['generated'];
input($air, ['keys'=>['KeyW'=>true]]);
[$level] = flyExactly($air, $ground, 240);
expect($matched && $level && $air->landscape->stats()['generated'] === $nodes,
	'Climbing away returns the jet to steps without node generation');

// A bound remembered from before an edit would let the jet fly through ground that the edit raised.
$s = $air->jet->state();
[$eyeX, $eyeY, $eyeZ] = $air->camera->position;
$bump = ['type'=>'raise','x'=>$eyeX,'z'=>$eyeZ,'radius'=>12.0,'amount'=>$eyeY - $ground->height($eyeX, $eyeZ) + 40];
foreach([$air->landscape, $ground] as $surface) { $surface->queue($bump); $surface->commit(); }
[$matched] = flyExactly($air, $ground, 1);
[$eyeX, $lifted, $eyeZ] = $air->camera->position;
expect($matched && $lifted === $ground->height($eyeX, $eyeZ) + 3 && $lifted > $eyeY + 20
	&& abs($air->jet->state()['y'] - $s['y']) < 1,
	'Ground raised behind the jet lifts the chase eye onto the true surface and leaves the jet level');
$s = $air->jet->state();
$dome = ['type'=>'raise','x'=>$s['x'],'z'=>$s['z'],'radius'=>600.0,'amount'=>2500.0];
foreach([$air->landscape, $ground] as $surface) { $surface->queue($dome); $surface->commit(); }
[$matched, $resting] = flyExactly($air, $ground, 1);
expect($matched && $resting === 1 && $air->jet->state()['y'] > $s['y'] + 1500,
	'Ground raised under a cruising jet carries it up on the next step');
[$matched] = flyExactly($air, $ground, 400);
$s = $air->jet->state();
$mesa = ['type'=>'raise','x'=>$s['x'],'z'=>$s['z'],'radius'=>500.0,'amount'=>6000.0];
$replacement = TerrainPhonics\Landscape::load($air->landscape->save());
$replacement->queue($mesa); $replacement->commit();
$air->publishLandscape($replacement);
$ground->queue($mesa); $ground->commit();
[$published, $resting] = flyExactly($air, $ground, 1);
expect($matched && $published && $resting === 1 && $air->jet->state()['y'] > $s['y'] + 1500,
	'A published replacement landscape takes effect on the next step');
[$matched] = flyExactly($air, $ground, 120);
expect($matched, 'The jet stays on the exact path over the replacement');
$ground->close();
$air->close();

// A generated world starts again at revision zero, so only its identity tells the jet that the ground changed.
$worlds = new Session(['heightScale'=>0,'detailAmplitude'=>0]);
$ground = new TerrainPhonics\Landscape($worlds->landscape->recipe());
$worlds->camera->position = [600,40,1800];
input($worlds, ['actions'=>['jet']]);
[$matched] = flyExactly($worlds, $ground, 20);
expect($matched && $worlds->jet->state()['y'] === 75.0, 'A jet over a featureless plain holds its boarding height');
$ground->close();
input($worlds, ['actions'=>[['type'=>'generate','preset'=>'mountain','seed'=>'first-light']]]);
$ground = new TerrainPhonics\Landscape($worlds->landscape->recipe());
expect($worlds->landscape->revision() === 0 && $ground->height(600, 1800) > 100,
	'The generated mountains stand where the plain was');
$worlds->camera->position = [600,40,1800];
input($worlds, ['actions'=>['jet'], 'keys'=>['KeyQ'=>true]]);
[$matched, $resting] = flyExactly($worlds, $ground, 240);
expect($matched && $resting > 60, 'Boarding over a newly generated world forgets the bounds of the old one');
$ground->close();
$worlds->close();

// A block bound says nothing about ground beyond its own rectangle. Six thin towers stand 37 metres apart on a
// plain, each 100 metres taller than the last, so they meet the 2 to 256 metre blocks at six different offsets.
$tower = new Session(['heightScale'=>0,'detailAmplitude'=>0]);
for($i = 0; $i < 6; $i++)
	$tower->landscape->queue(['type'=>'raise','x'=>600,'z'=>1900 + 37 * $i,'radius'=>6.0,'amount'=>500.0 + 100 * $i]);
$tower->landscape->commit();
$ground = TerrainPhonics\Landscape::load($tower->landscape->save());
$tower->camera->position = [600,40,1800];
$tower->camera->yaw = M_PI;
input($tower, ['actions'=>['jet']]);
$nodes = $tower->landscape->stats()['generated'];
[$matched] = flyExactly($tower, $ground, 40);
expect($matched && $tower->landscape->stats()['generated'] === $nodes,
	'A jet approaching a tower clears the open plain without sampling it');
[$matched, $resting] = flyExactly($tower, $ground, 130);
expect($matched && $resting >= 12 && $tower->jet->state()['y'] > 1007,
	"The jet rides up each tower face in turn: $resting steps on a face");
$ground->close();
$tower->close();

echo "Landscape sandbox session: $checks checks passed\n";
