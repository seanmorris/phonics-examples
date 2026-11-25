<?php
declare(strict_types=1);

$package = getenv('PHONICS_TERRAIN_ROOT') ?: dirname(__DIR__, 3) . '/packages/terrain-phonics';
require_once $package . '/autoload.php';
require_once dirname(__DIR__) . '/autoload.php';

use TerrainSandbox\Car;
use TerrainSandbox\CarChase;
use TerrainSandbox\DriveRegion;
use TerrainSandbox\Jet;
use TerrainSandbox\Session;

$checks = 0;
function expect(bool $condition, string $message): void
{
	global $checks;
	$checks++;
	if(!$condition) throw new RuntimeException($message);
}
function input(Session $session, array $value): void { $session->input($value, 1 / 60, 16 / 9); }

/** Place the mass center as the vertex shader draws a point in the Testarossa mesh. */
function drawnCarCenter(array $pose): array
{
	$basis = TerrainSandbox\VehicleModel::basis($pose);
	$h = Car::CENTER_OF_MASS_HEIGHT;
	return [$pose['x'] + $h*$basis[3], $pose['y'] + $h*$basis[4], $pose['z'] + $h*$basis[5]];
}

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
input($session, ['values' => ['skyColor' => '#2C4FA8', 'hazeColor' => '#d9b380', 'skyLight' => 7]]);
expect($session->settings['skyColor'] === '#2c4fa8' && $session->atmosphere->skyColor() === '#2c4fa8'
	&& $session->atmosphere->hazeColor() === '#d9b380' && $session->settings['skyLight'] === 3.0
	&& $session->atmosphere->uniforms()['skyLight'] === 3.0, 'Sky color, haze color and sky light reach the atmosphere');
$skyFile = $session->save();
input($session, ['values' => ['skyColor' => TerrainPhonics\Atmosphere::DEFAULT_SKY_COLOR, 'skyLight' => 1]]);
$session->load($skyFile);
expect($session->atmosphere->skyColor() === '#2c4fa8' && $session->atmosphere->uniforms()['skyLight'] === 3.0,
	'Save/load preserves the sky controls');
input($session, ['values' => ['skyColor' => 'navy']]);
expect($session->settings['skyColor'] === '#2c4fa8' && $session->atmosphere->skyColor() === '#2c4fa8'
	&& $session->message === 'Invalid control value', 'A named sky color is refused and reported');
$badSky = json_decode($skyFile, true, flags: JSON_THROW_ON_ERROR);
$badSky['settings']['hazeColor'] = 'dusty';
try { $session->load(json_encode($badSky, JSON_THROW_ON_ERROR)); expect(false, 'A file with a bad haze color must be refused'); }
catch(InvalidArgumentException) { expect($session->atmosphere->hazeColor() === '#d9b380', 'A file with a bad haze color changes nothing'); }
unset($badSky['settings']['skyColor'], $badSky['settings']['hazeColor'], $badSky['settings']['skyLight']);
$session->load(json_encode($badSky, JSON_THROW_ON_ERROR));
expect($session->atmosphere->skyColor() === TerrainPhonics\Atmosphere::DEFAULT_SKY_COLOR
	&& $session->atmosphere->hazeColor() === TerrainPhonics\Atmosphere::DEFAULT_HAZE_COLOR
	&& $session->settings['skyLight'] === TerrainPhonics\Atmosphere::DEFAULT_SKY_LIGHT, 'Older saves restore the default sky');
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
expect($session->landscape->recipe()['version'] === 2, 'Generating Canyon selects the bedded recipe');
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

$karst = new Session;
input($karst, ['actions' => [['type' => 'generate', 'preset' => 'limestone']]]);
expect($karst->landscape->recipe()['seed'] === 'limestone-plain' && $karst->water->level() === -40.,
	'Limestone defaults supply the example seed and a dry plain.');
[$x, , $z] = $karst->camera->position;
$floor = $karst->landscape->height($x, $z);
$summit = $karst->landscape->height(637, -3747);
expect(abs($floor) < 3 && $summit > 1400, 'The example opens on flat ground beside a high peak.');
foreach([[400,0], [-400,0], [0,400], [0,-400]] as [$dx,$dz])
	expect($karst->landscape->height(637+$dx, -3747+$dz) < $summit-200, 'The summit falls away on each side.');
$region = new DriveRegion($karst->landscape, $karst->water->level(), ['x'=>$x, 'z'=>$z, 'y'=>$floor]);
$route = $region->containsPosition(4110., -4933.);
foreach($route as $_) {}
expect($route->getReturn() && $karst->landscape->height(4110., -4933.)-$floor > 350,
	'A hillside over 350 m above the opening plain is connected by driveable grades on both tire tracks.');
input($karst, ['values' => ['grassColor' => '#224422', 'haze' => 2.0, 'rockSlopeStart' => .65]]);
input($karst, ['actions' => [['type' => 'generate', 'preset' => 'limestone', 'seed' => 'second', 'wallWidth' => .7]]]);
expect($karst->landscape->recipe()['wallWidth'] === .7 && $karst->landscape->recipe()['seed'] === 'second',
	'Limestone uses authored shape coefficients and seeds.');
input($karst, ['actions' => [['type' => 'generate', 'preset' => 'mountain']]]);
expect($karst->settings['snowline'] === 460.0 && $karst->settings['azimuth'] === 125.0
	&& $karst->settings['grassColor'] === '#224422' && $karst->settings['haze'] === 2.0
	&& $karst->settings['rockSlopeStart'] === .65 && $karst->settings['rockSlopeEnd'] === .73,
	'Leaving Limestone for Mountain Ridges applies its snowline and the ordinary sun while preserving authored settings.');
expect($karst->settings['skyColor'] === '#41659a' && $karst->atmosphere->skyColor() === '#41659a',
	'Mountain Ridges brings its deeper alpine sky');
input($karst, ['actions' => [['type' => 'generate', 'preset' => 'coast']]]);
expect($karst->atmosphere->skyColor() === TerrainPhonics\Atmosphere::DEFAULT_SKY_COLOR
	&& $karst->atmosphere->hazeColor() === TerrainPhonics\Atmosphere::DEFAULT_HAZE_COLOR, 'Coast returns to the standard sky');
$karst->close();

$showcase = new Session;
foreach(['basalt-fjords','amber-dunes'] as $preset) {
	input($showcase,['actions'=>[['type'=>'generate','preset'=>$preset]]]);
	$recipe = $showcase->landscape->recipe();
	[$x,$eye,$z] = $showcase->camera->position;
	expect($recipe['seed'] === $preset && $showcase->status()['surfacePreset'] === $preset
		&& $eye > max($showcase->landscape->height($x,$z),$showcase->water->level()),
		"$preset opens above its land and water with its own editable surface.");
	$copy = new Session;
	$copy->load($showcase->save());
	expect($copy->activeLayers()->layers === $showcase->activeLayers()->layers && $copy->settings == $showcase->settings
		&& $copy->landscape->recipe() === $recipe, "$preset restores its material, environment and shape from a save.");
	$copy->close();
	if($preset === 'basalt-fjords') {
		expect($showcase->water->level() > $showcase->landscape->height($x,$z)
			&& $showcase->water->appearance->settings['waterColor'] === '#1c5965',
			'The fjord opens on a flooded channel with its own water optics.');
		input($showcase, ['actions' => ['craft']]);
		expect($showcase->mode === 'craft', 'The starting inlet has enough water to board the jet ski.');
		input($showcase,['values'=>['haze'=>1.6,'waterColor'=>'#103948']]);
	} else {
		enterCar($showcase);
		expect($showcase->mode === 'car', 'The starting dune has a nearby drivable slope.');
	}
}
expect($showcase->settings['waterChoppiness'] === 100. && $showcase->settings['waterColor'] === '#103948'
	&& $showcase->settings['haze'] === 1.6 && $showcase->water->level() < $showcase->landscape->height($x,$z),
	'Leaving the fjord restores standard swells and keeps authored water and sky over dry dunes.');
input($showcase,['actions'=>[['type'=>'generate','preset'=>'coast']]]);
expect($showcase->settings['sandColor'] === TerrainPhonics\TerrainAppearance::DEFAULTS['sandColor']
	&& $showcase->settings['elevation'] === 35.,'Leaving the dunes restores the coastal sand and daylight.');
input($showcase,['actions'=>[['type'=>'surface-layers','preset'=>'amber-dunes']]]);
expect($showcase->landscape->recipe()['preset'] === 'coast' && $showcase->status()['surfaceOrigin'] === 'authored'
	&& in_array('wind-ripples',array_column($showcase->activeLayers()->layers,'id'),true),
	'The dune material can be applied separately to a coastal landform.');
$showcase->close();

$fresh = new Session;
foreach([
	'red-mesas'=>['cap-rock','Desert dust'], 'heather-moor'=>['peat-banks','Moor mist'],
	'rolling-prairie'=>['green-swales','Prairie haze'], 'river-valley'=>['gravel-bars','River mist'],
	'wooded-foothills'=>['woodland-canopy','Foothill haze'], 'high-desert-steppe'=>['sagebrush','Basin dust'],
	'alaska-range'=>['firn-snowfields','Glacial valley haze'], 'chalk-headlands'=>['flint-nodules','Channel mist'],
	'karst-bay'=>['rain-streaks','Bay mist'], 'tepui-highlands'=>['algae-streaks','Cloud forest'],
	'painted-badlands'=>['cap-rock','Clay dust'], 'emerald-ridges'=>['mossy-cliffs','Trade-wind haze'],
	'autumn-folds'=>['hemlock-stands','Blue ridge haze'], 'namib-coast'=>['wind-ripples','Atlantic fog'],
	'black-sand-coast'=>['basalt-scree','North Atlantic haze'], 'yardang-desert'=>['clay-flanks','Dust haze'],
	'rice-terraces'=>['earth-bunds','Valley mist'], 'boreal-lakes'=>['bog-margins','Clear northern air'],
	'salt-pans'=>['salt-crust','Playa dust'], 'cinder-highlands'=>['oxidized-scoria','Highland haze'],
] as $preset=>[$layer,$air]) {
	input($fresh,['actions'=>[['type'=>'generate','preset'=>$preset]]]);
	[$x,$eye,$z] = $fresh->camera->position;
	expect($fresh->landscape->recipe()['seed'] === $preset && $eye > $fresh->landscape->height($x,$z) + 20
		&& in_array($layer,array_column($fresh->activeLayers()->layers,'id'),true)
		&& $fresh->status()['surfacePreset'] === $preset && $fresh->atmosphere->layers()[0]['name'] === $air,
		"$preset opens above its own ground with its materials, surface name and air.");
	$restored = new Session;
	$restored->load($fresh->save());
	expect($restored->landscape->recipe() === $fresh->landscape->recipe()
		&& $restored->activeLayers()->layers === $fresh->activeLayers()->layers
		&& $restored->atmosphere->layers() === $fresh->atmosphere->layers(), "$preset survives a save and reload.");
	if($preset === 'alaska-range') {
		enterCar($restored);
		expect($restored->mode === 'car', 'The opening Alaskan bench supports the car.');
	} elseif($preset === 'chalk-headlands') {
		input($restored,['actions'=>['craft']]);
		expect($restored->mode === 'craft', 'The cove below the chalk headlands is navigable.');
	} elseif($preset === 'karst-bay') {
		input($restored,['actions'=>['craft']]);
		expect($restored->mode === 'craft', 'The bay between the karst towers is navigable.');
	} elseif($preset === 'yardang-desert') {
		enterCar($restored);
		expect($restored->mode === 'car', 'The corridor between the yardangs supports the car.');
	} elseif(in_array($preset, ['autumn-folds', 'namib-coast', 'black-sand-coast', 'boreal-lakes'], true)) {
		input($restored,['actions'=>['craft']]);
		expect($restored->mode === 'craft', "The water at the $preset opening view is navigable.");
	} elseif($preset === 'emerald-ridges') {
		input($restored,['actions'=>['craft']]);
		expect($restored->mode === 'craft', 'The bay below the emerald ridges is navigable.');
	} elseif($preset === 'painted-badlands') {
		enterCar($restored);
		expect($restored->mode === 'car', 'The dusty floor between the badland mounds supports the car.');
	}
	$restored->close();
}
input($fresh,['actions'=>[['type'=>'surface-layers','preset'=>'salt-pans']]]);
$reload = new Session;
$reload->load($fresh->save());
expect($reload->landscape->recipe()['preset'] === 'cinder-highlands' && $reload->status()['surfacePreset'] === 'salt-pans'
	&& $reload->activeLayers()->layers === $fresh->activeLayers()->layers
	&& $reload->atmosphere->layers() === $fresh->atmosphere->layers(),
	'A saved cinder landscape keeps its imported salt surface and its highland atmosphere.');
$reload->close();
$fresh->close();

$waterways = new Session;
foreach(['arctic-meltwater'=>'glacier-ice','tidal-lagoon'=>'seagrass-beds'] as $preset=>$layer) {
	input($waterways,['actions'=>[['type'=>'generate','preset'=>$preset]]]);
	[$x,$eye,$z] = $waterways->camera->position;
	$level = $waterways->water->level();
	expect($waterways->landscape->recipe()['seed'] === $preset && $eye > $level
		&& $waterways->landscape->height($x,$z) < $level-2
		&& in_array($layer,array_column($waterways->activeLayers()->layers,'id'),true),
		"$preset starts over a navigable channel with its terrain and materials selected together.");
	for($ahead = 25; $ahead <= 100; $ahead += 25) {
		$heading = $waterways->camera->yaw;
		expect($waterways->landscape->height($x+$ahead*sin($heading),$z-$ahead*cos($heading)) < $level-2,
			"The $preset channel has open water ahead of the starting craft.");
	}
	$copy = new Session;
	$copy->load($waterways->save());
	expect($copy->activeLayers()->layers === $waterways->activeLayers()->layers
		&& $copy->settings == $waterways->settings && $copy->camera->position === $waterways->camera->position,
		"$preset restores its layers, water, sun and home viewpoint from a save.");
	$copy->close();
	input($waterways,['actions'=>['craft']]);
	$craft = $waterways->craft->state();
	expect($waterways->mode === 'craft' && abs($craft['x']-$x) < .01 && abs($craft['z']-$z) < .01,
		"The jet ski boards directly at the $preset starting site.");
}
// Standing grass is on at its low level by default, follows its setting and comes from the surface that grows it.
$grassy = new Session;
$grown = static fn(string $surface): ?array => TerrainPhonics\GrassShells::style($surface);
expect($grassy->status()['grass'] === 1
	&& $grassy->grassStyles() === [$grown(TerrainPhonics\TerrainAppearance::style($grassy->landscape->recipe()))],
	'Grass starts at its low level on the landscape\'s own surface.');
input($grassy,['values'=>['grass'=>2]]);
expect($grassy->status()['grass'] === 2 && $grassy->dirty, 'Raising the grass level redraws the view.');
input($grassy,['values'=>['grass'=>7]]);
expect($grassy->status()['grass'] === 2, 'The grass level stays within its range.');
input($grassy,['values'=>['grass'=>0]]);
expect($grassy->status()['grass'] === 0, 'Grass can be turned off.');
input($grassy,['actions'=>[['type'=>'generate','preset'=>'painted-badlands']]]);
expect($grassy->grassStyles() === [$grown('painted-badlands')] && $grown('painted-badlands') !== null,
	'A landscape that borrows another style grows its own grass.');
input($grassy,['actions'=>[['type'=>'generate','preset'=>'canyon']]]);
expect($grassy->grassStyles() === [null], 'Bare rock grows no grass.');
// A journey grows each landscape's grass. A destination's height limit follows it to the departure's water level.
$grassy->input(['actions'=>[
	['type'=>'journey-build','west'=>'rolling-prairie','destinations'=>['rice-terraces','canyon'],'span'=>16384],
]],0,1.);
while($grassy->preparingRoad()) $grassy->input([],0,1.,6.);
$joined = $grassy->landscape->recipe();
$lift = $joined['waterLevel']-$joined['join']['destinations'][0]['waterLevel'];
$rice = $grown('rice-terraces');
$rice['top'] = [$rice['top'][0]+$lift, $rice['top'][1]+$lift];
expect(isset($joined['join']) && abs($lift) > 1. && $grassy->grassStyles() === [$grown('rolling-prairie'), $rice, null],
	'A journey lists the grass of its departure and both destinations.');
$grassy->close();

input($waterways,['values'=>['waterColor'=>'#315468','snowSlopeStart'=>.30]]);
input($waterways,['actions'=>[['type'=>'generate','preset'=>'arctic-meltwater']]]);
expect($waterways->settings['waterColor'] === '#315468' && $waterways->settings['snowSlopeStart'] === .30
	&& $waterways->settings['snowSlopeEnd'] === TerrainPhonics\TerrainAppearance::DEFAULTS['snowSlopeEnd'],
	'Generating Arctic Meltwater preserves authored water color and the snow slope range.');
$waterways->close();

$palette = new Session;
foreach(['basalt-fjords'=>'moss-heath', 'limestone'=>'grassland', 'mountain'=>'meadow'] as $preset=>$cover) {
	input($palette,['actions'=>[['type'=>'generate','preset'=>$preset]]]);
	$ground = array_column($palette->activeLayers()->layers,'color','id')[$cover];
	$defaults = TerrainPhonics\TerrainAppearance::PRESET_DEFAULTS[$preset];
	expect($ground === ($defaults['meadowColor'] ?? $defaults['grassColor']),
		"$preset generates its vegetation from its own palette.");
	input($palette,['actions'=>[['type'=>'surface-layers','preset'=>$preset]]]);
	expect(array_column($palette->activeLayers()->layers,'color','id')[$cover] === $ground,
		"Loading the $preset surface independently uses the same vegetation.");
	input($palette,['actions'=>[['type'=>'surface-layers']]]);
}
$palette->close();

$far = new Session;
expect(array_map('floatval', $far->camera->position) === [3600.0,300.0,1800.0] && $far->camera->yaw === .7,
	'The coastal home view faces editable land and its shoreline');
input($far, ['values' => ['tool' => 'raise'],'clicks' => [[.5,.5]]]);
expect($far->picking && $far->takeCandidate() === null, 'Distant picking yields before changing terrain');
input($far, ['values' => ['amount' => 199]]);
finishPicking($far);
publish($far);
$farSave = json_decode($far->save(), true, flags: JSON_THROW_ON_ERROR);
expect($farSave['landscape']['history'][0][0]['amount'] === 40 * TerrainSandbox\DisplayUnits::FOOT, 'A delayed pick retains the brush settings from its click');
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
	[$camera,$car] = $canyon->carView(1.);
	[$x,$y,$z] = $camera->position;
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
	&& abs(2*drawnCarCenter($halfCar)[2] - drawnCarCenter($previousCar)[2] - drawnCarCenter($car)[2]) < 1e-8
	&& $previousCar['z'] < $halfCar['z'],
	'The rendered car and eye advance through the same fraction of the physics step');
input($canyon, ['actions'=>['pause']]);
[$pausedEye,$pausedCar] = $canyon->carView(0);
expect($pausedEye == $camera && $pausedCar === $car, 'Pausing holds the last camera and car poses at every render fraction');
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
$distance = 0.0;
for($i = 0; $i < 480 && $distance < 200; $i++)
{
	$coast->step(1 / 60);
	$run = $coast->craft->state();
	$contacts += $run['contacts'];
	$distance = hypot($run['x'] - $launch['x'], $run['z'] - $launch['z']);
}
expect($contacts === 0 && $distance >= 200, 'Default coastal launch has 200 meters of clear water ahead');
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
$verticalSpeed = $bay->craft->state()['velocity'][1];
input($bay, ['values' => ['waterLevel' => 5]]);
for($i = 0; $i < 15; $i++) $bay->step(1 / 60);
expect(abs($bay->craft->state()['velocity'][1] - $verticalSpeed + .25 * Session::DRIVING_GRAVITY) < 1e-8,
	'The boarded jet ski falls at the driving gravity when the water drops away');
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
input($drive, ['actions'=>['pad-down']]);
expect($drive->mode === 'car' && $drive->carWideMap && !$drive->jetWideMap && $drive->dirty,
	'D-pad down zooms out the car minimap independently of the jet');
input($drive, ['actions'=>['pad-down']]);
expect($drive->mode === 'car' && $drive->carMap && !$drive->carWideMap, 'A second tap restores the car minimap');
input($drive, ['keys' => ['KeyW'=>true,'KeyD'=>true]]);
for($i = 0; $i < 60; $i++) $drive->step(1/60);
$s = $drive->car->state();
expect($s['z'] > 2 && $s['x'] < 0 && $s['speed'] > 6 && $s['steering'] < 0 && $s['wheelSpin'] != 0,
	'Forward right steering turns toward the driver’s right and rolls the wheels');
[$inside,$outside] = Car::frontWheelAngles($s['steering']);
expect($inside < $outside && $outside < 0 && abs(1/tan($inside) - 1/tan($outside) - Car::FRONT_TRACK/Car::WHEELBASE) < 1e-9,
	'The inside wheel steers farther and both front wheels follow the same center of rotation');
input($drive, ['actions'=>['pause'], 'keys'=>['KeyS'=>true]]);
$drive->step(1/60);
expect($drive->car->state() === $s, 'Pause freezes vehicle motion');
input($drive, ['actions'=>['pause'], 'keys'=>['KeyS'=>true,'KeyD'=>true]]);
for($i = 0; $i < 100; $i++) $drive->step(1/60);
$reverse = $drive->car->state();
$drive->step(1/60);
expect($reverse['speed'] < -2 && $drive->car->state()['yaw'] > $reverse['yaw'], 'Braking engages reverse and reverses the turn');
// At 240 mph a steep face is a crash, not a ramp: only the old 36 m/s top speed rides up it. Before, a 30 m raise
// threw the car about 400 m high.
$face = new Session(['worldSize' => 8192, 'heightScale' => 0, 'detailAmplitude' => 0, 'waterLevel' => -50]);
$face->landscape->queue(['type' => 'raise', 'x' => 0, 'z' => 1500, 'radius' => 16, 'amount' => 30]);
$face->landscape->commit();
$face->camera->position = [0, 30, -2500];
$face->camera->yaw = M_PI;
input($face, ['actions' => ['car']]);
for($i = 0; $face->findingCarSite() && $i < 4000; $i++) input($face, []);
$fastest = $highest = $widest = 0.0;
for($i = 0; $i < 3600; $i++)
{
	input($face, ['keys' => ['KeyW' => true]]);
	$face->step(1/60);
	[$eye,$c] = $face->carView(1.);
	$fastest = max($fastest, $c['speed']);
	$widest = max($widest, rad2deg($eye->fov));
	if(abs($c['z'] - 1500) < 300) $highest = max($highest, $c['y'] - $face->landscape->height($c['x'], $c['z']));
	if($c['z'] > 2200) break;
}
expect($fastest > 100 && $highest < 60,
	sprintf('At %.0f m/s a steep raise throws the car %.1f m up, not hundreds of meters', $fastest, $highest));
expect(abs($fastest/Car::MPS_PER_MPH-240.) < .01 && abs($widest-90.) < .02,
	'The driving camera reaches 90 degrees on the full-throttle run to 240 mph');
$face->close();
// Rolling backward faster than reverse gear, as after a landing, the car slows while S is held instead of keeping its
// speed: the pedal once added reverse power and the clamp held whatever speed the car had.
(function() { $this->speed = -20.0; })->call($drive->car);
for($i = 0; $i < 60; $i++) $drive->step(1/60);
$backward = $drive->car->state()['speed'];
expect($backward > -19.8 && $backward < -8, "Reverse gear does not hold a backward speed beyond its own ($backward m/s)");
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
$road->landscape->queue(['type'=>'road','points'=>[[0,8,-45],[0,0,45]],'width'=>10,'shoulder'=>1,'crown'=>0]);
$road->landscape->commit();
input($road, ['values'=>['waterLevel'=>1]]);
$road->camera->position = [30,30,0]; $road->camera->yaw = M_PI;
enterCar($road);
$s = $road->car->state();
expect($road->mode === 'car' && abs($s['x']) < 5 && abs($s['pitch']) > .04 && abs($s['roll']) < .02,
	'Search relocates from water to the gently graded dry road');
expect(abs($s['y'] - $road->landscape->height($s['x'],$s['z']) - .02) < .02, 'Car rests on the edited road elevation');
input($road, ['keys'=>['KeyW'=>true]]);
$lastDry = $furthest = $s['z'];
for($i = 0; $i < 600; $i++) {
	$road->step(1/60);
	$s = $road->car->state();
	if($s['recoveries']) break;
	$furthest = max($furthest,$s['z']);
	if($road->car->checkedSite($road->landscape,1.,$s['x'],$s['z'],$s['yaw'],[]) !== null && $s['wheelContacts'] === 4)
		$lastDry = $s['z'];
}
expect($furthest > $lastDry + 3 && $s['z'] === $lastDry && $s['floodedHold'] && $s['speed'] === 0.
	&& $road->mode === 'car', 'The car wades past the shoreline and a flooded intake returns it to its dry approach');

// Enter the same ford in reverse. The dry pose must be uphill of the flooded car.
$reverse = new Car;
$site = $reverse->checkedSite($road->landscape,1.,0.,15.,M_PI,[]);
$reverse->spawn($site,$road->landscape,1.);
for($i = 0; $i < 600 && $reverse->step(1/60,-1.,0.,$road->landscape,1.); $i++) {}
$bank = $reverse->shoreSite($road->landscape,1.,[]);
expect($reverse->engineFlooded(1.) && $bank !== null && $bank[1] > 15. && $bank[1] < $reverse->state()['z']
	&& $bank[2] === M_PI, 'Reversing into water recovers along the traveled path with the original heading');

// Identical cars wade across a flat bed, so only the submerged tire depth varies.
$speeds = [];
$wadingLand = new TerrainPhonics\Landscape($flat);
foreach([-.1,.15,.35] as $water) {
	$car = new Car;
	$site = $car->checkedSite($wadingLand,-5.,0.,0.,0.,[]);
	$car->spawn($site,$wadingLand,-5.);
	for($i=0; $i<90; ++$i) {
		if(!$car->step(1/60,1.,0.,$wadingLand,$water)) break;
	}
	expect($i === 90, 'Tire immersion alone keeps the engine running');
	$speeds[] = $car->state()['speed'];
}
expect($speeds[0] > $speeds[1] && $speeds[1] > $speeds[2] && $speeds[2] > 0., 'Deeper water progressively slows travel');
$air = new TerrainSandbox\CarTumble(0.,3.,0.,0.,0.,0.,8.,0.,0.,0.);
$air->advance(.05,$wadingLand,1.);
expect($air->pose()[2] > .39 && $air->pose()[5] === 8., 'An airborne chassis carries its momentum over a flooded bed');
$wadingLand->close();
// A driver's right-stick view can turn independently of the road. Detail follows the signed tire path.
$pose = ['x'=>10.,'z'=>20.,'pitch'=>0.,'yaw'=>M_PI/2,'drift'=>M_PI/6,'motion'=>'driving','speed'=>24.];
[$fx,$fz] = Car::terrainFocus($pose);
expect(abs($fx-10.-18.*sin(M_PI/3))<1e-8 && abs($fz-29.)<1e-8, 'Terrain leads both shoulders into a skid');
$pose['speed'] = -8.;
[$fx,$fz] = Car::terrainFocus($pose);
expect(abs($fx-10.+6.*sin(M_PI/3))<1e-8 && abs($fz-17.)<1e-8, 'Reversing prepares the ground behind the car');
$pose['motion'] = 'tumbling';
expect(Car::terrainFocus($pose) === [10.,20.], 'A tumbling body keeps its detail around the landing site');
$road->close();

$ledge = new Session($flat);
$ledge->landscape->queue(['type'=>'raise','x'=>0,'z'=>26,'radius'=>6,'amount'=>30]);
$ledge->landscape->commit();
$ledge->camera->position = [0,20,0]; $ledge->camera->yaw = M_PI;
input($ledge, ['actions'=>['car','fly']]);
expect(!$ledge->findingCarSite() && $ledge->mode === 'fly', 'Flight cancels a pending boarding request');
$ledge->close();

$dry = ['worldSize'=>512,'spacing'=>1,'heightScale'=>0,'detailAmplitude'=>0,'waterLevel'=>-100];

// Drop past a ledge that is still under the camera after the car has reached the ground below.
$drop = new Session($dry);
$drop->landscape->queue(['type'=>'road','points'=>[[-30,16,-10],[30,16,-10]],'width'=>18,'shoulder'=>.5,'crown'=>0]);
$drop->landscape->commit();
$chase = new CarChase;
$pose = ['y'=>16.02,'z'=>-.3,'speed'=>2.0] + $drop->car->state();
$chase->board($drop->camera, $pose, $drop->landscape);
$frameError = 0.0; $landingPitch = null;
for($i = 1; $i <= 360; $i++)
{
	$time = $i/60;
	$y = max(.02, 16.02 - .5*Session::DRIVING_GRAVITY*$time**2);
	$next = ['y'=>$y,'z'=>-.3 + 2*$time,'motion'=>$y > .02 ? 'airborne' : 'driving'] + $pose;
	$chase->advance($drop->camera, $pose, $next, $drop->landscape, 1/60);
	foreach([.5,1.0] as $alpha)
	{
		[$eye,$presented] = $chase->view($drop->camera, $next, $alpha);
		[$x,$eyeY,$z] = $eye->position;
		$focus = atan2($presented['y'] + .65 - $eyeY, hypot($presented['x'] - $x, $presented['z'] - $z));
		$frameError = max($frameError, abs(tan($focus - $eye->pitch)/tan($eye->fov/2)));
	}
	if($y === .02 && $landingPitch === null)
	{
		expect($drop->camera->position[1] >= 17.34, 'The ledge keeps the camera high above the landed car');
		$landingPitch = $drop->camera->pitch;
	}
	$pose = $next;
}
expect($frameError < .52 && $landingPitch !== null && $landingPitch < -.8 && $drop->camera->pitch > $landingPitch + .4,
	'The chase camera tilts with a falling car through interpolated frames and lifts after clearing the ledge');
$belowLedge = ['y'=>.02, 'z'=>-.3, 'speed'=>0.] + $drop->car->state();
$chase->board($drop->camera,$belowLedge,$drop->landscape);
$blockedEye = clone $drop->camera;
$belowLedge['speed'] = Car::TOP_SPEED_MPH * Car::MPS_PER_MPH;
$chase->board($drop->camera,$belowLedge,$drop->landscape);
expect($blockedEye->position[1] >= 17.34 && $drop->camera->position === $blockedEye->position
	&& $drop->camera->pitch === $blockedEye->pitch, 'A ledge that blocks the high-speed drop also keeps its original sightline');
$drop->close();

foreach([-1,0,1] as $downhill)
{
	$crossfall = new Session($dry);
	$crossfall->landscape->queue(['type'=>'road','points'=>[[-100,18*$downhill,0],[100,-18*$downhill,0]],'width'=>130,'shoulder'=>6,'crown'=>0]);
	$crossfall->landscape->commit();
	$crossfall->camera->position = [0,30,-42]; $crossfall->camera->yaw = M_PI;
	enterCar($crossfall);
	$crossfall->step(1/60);
	expect($crossfall->car->state()['steering'] === 0.0, 'A parked car keeps its wheels centered on a bank');
	input($crossfall, ['keys'=>['KeyW'=>true]]);
	for($i = 0; $i < 180; $i++) $crossfall->step(1/60);
	$car = $crossfall->car->state();
	expect($downhill === 0 ? $car['x'] === 0.0 && $car['yaw'] === 0.0
		: $downhill*$car['x'] > .5 && $downhill*$car['yaw'] > .02 && $downhill*$car['steering'] > 0,
		'With the steering released, a rolling car pulls gently downhill on either bank and stays straight on level ground');
	$crossfall->close();
}

// The road runs across the car's path. Both turns enter the same 31° bank at the same speed.
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

// Approach a cross-slope from level ground so the tires take up the load gradually.
foreach([-1.40,1.40,1.55] as $grade)
{
	$land = new TerrainPhonics\Landscape($dry);
	$land->queue(['type'=>'road','points'=>[[-65,-65*$grade,0],[65,65*$grade,0]],'width'=>190,'shoulder'=>40,'crown'=>0]);
	$land->commit();
	$vehicle = new Car;
	$vehicle->spawn($vehicle->checkedSite($land,-150.,0.,-155.,0.,[]),$land,-150.);
	for($i = 0; $i < 240; $i++)
	{
		$vehicle->step(1/60,1.,0.,$land,-150.);
		$pose = $vehicle->state();
		if($pose['motion'] === 'tumbling') break;
	}
	if(abs($grade) < 1.5)
		expect($pose['motion'] === 'driving' && $pose['wheelContacts'] >= 2 && abs($pose['roll']) > deg2rad(50),
			'A 54-degree bank supports the moving car in either direction');
	else expect($pose['motion'] === 'tumbling', 'A 57-degree bank still overloads the outside tires');
	$land->close();
}

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
				$gravityError = max($gravityError,abs(3600*($pose['y']-2*$previous['y']+$older['y'])+Session::DRIVING_GRAVITY));
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

$high = new TerrainSandbox\CarSuspension;
$high->reset([2.02,0.,0.,0.,[0.,0.,0.,0.]]);
$ballistic = clone $high;
$high->step(1/60,[0.,0.,0.,0.],true,7.5);
$ballistic->step(1/60,[0.,0.,0.,0.],true);
expect($high->contacts === 0 && $high->y === $ballistic->y && $high->verticalSpeed === $ballistic->verticalSpeed,
	'Boost cannot pull a high airborne car down to the road');

// A one-meter drop should compress the springs and settle with little rebound.
$springs->y += 1;
$bottomed = false; $rise = 0.0;
for($i = 0; $i < 240; $i++)
{
	$springs->step(1/120,[0,0,0,0],true);
	if($springs->y < -.10) $bottomed = true;
	if($bottomed) $rise = max($rise, $springs->y - .02);
}
expect($bottomed && $rise > 0 && $rise < .017 && $springs->contacts === 4,
	'Landing dampers limit rebound to less than 17 mm after a one-meter drop');

$landingSurface = new TerrainPhonics\Landscape($flat);

// Start both airborne motion models with the same bank and spin, far above tire contact.
foreach([30,120] as $hz) foreach([-1,1] as $direction)
{
	$springs = new TerrainSandbox\CarSuspension;
	$springs->reset([30.,.1,.2,0.,[0.,0.,0.,0.]]);
	$springs->rollRate = .8*$direction;
	$springs->pitchRate = .4*$direction;
	$body = new TerrainSandbox\CarTumble(0.,30.,0.,0.,.1,.2,8.,0.,$springs->pitchRate,$springs->rollRate);
	for($i = 0; $i < $hz; $i++)
	{
		$springs->step(1/$hz,[0.,0.,0.,0.],true);
		$body->advance(1/$hz,$landingSurface,-5.);
	}
	$rollTravel = ($springs->roll-.2)*$direction;
	$pitchTravel = ($springs->pitch-.1)*$direction;
	expect($springs->contacts === 0 && $rollTravel > .71 && $rollTravel < .74 && $pitchTravel > .39 && $pitchTravel < .4,
		'An unsupported car keeps turning in its launch direction with mild resistance to spin');
	expect(abs($body->roll-$springs->roll) < 1e-9 && abs($body->pitch-$springs->pitch) < 1e-9,
		'A jump and a tumble lose the same angular speed in free flight at either step rate');
}

// Compare two seconds of free flight with and without a complete barrel roll on the same trajectory.
function freeRoll(TerrainPhonics\Landscape $land, int $direction): array
{
	$yaw = .83;
	$template = ['yaw'=>$yaw, 'motion'=>'tumbling'] + (new Car)->state();
	$body = new TerrainSandbox\CarTumble(2,30,4,$yaw,.16,0,6,4,.45*$direction,4.*$direction,1.7);
	$pose = static function() use ($body,$template): array {
		[$x,$y,$z,$pitch,$roll,$speed] = $body->pose();
		return compact('x','y','z','pitch','roll','speed') + $template;
	};
	$eye = new TerrainPhonics\Camera;
	$chase = new CarChase;
	$from = $pose();
	$chase->board($eye,$from,$land);
	$trace = [];
	for($step = 0; $step < 120; $step++)
	{
		$body->advance(1/60,$land,-100);
		$to = $pose();
		$chase->advance($eye,$from,$to,$land,1/60);
		foreach([.5,1.] as $alpha)
		{
			[$camera,$model] = $chase->view($eye,$to,$alpha);
			$trace[] = [...$camera->position, $camera->pitch, ...drawnCarCenter($model)];
		}
		$from = $to;
	}
	expect($direction === 0 || $body->roll*$direction > 2*M_PI, 'A hard launch can still complete a barrel roll');
	return $trace;
}
$straight = freeRoll($landingSurface,0);
foreach([-1,1] as $direction)
{
	$error = 0.;
	foreach(freeRoll($landingSurface,$direction) as $frame => $sample)
		foreach($sample as $i => $value) $error = max($error, abs($value - $straight[$frame][$i]));
	expect($error < 1e-9, "A full roll ($direction) keeps the drawn mass and the chase eye on their paths between ticks");
}

foreach([[0,3,0,true],[0,-3,0,true],[2,0,0,true],[-2,0,0,true],[0,12,0,false],[0,3,.08,false]] as [$pitch,$roll,$clearance,$catch])
{
	$p = deg2rad($pitch); $r = deg2rad($roll);
	$y = abs(sin($p))*1.26 + abs(cos($p)*sin($r))*.82 + $clearance;
	$body = new TerrainSandbox\CarTumble(0,$y,0,0,$p,$r,8,-.08,0,0,3);
	$body->advance(1/120,$landingSurface,-5);
	expect($body->onWheels === $catch, "Wheel-first recovery at pitch $pitch, roll $roll and clearance $clearance");
}
$landingSurface->close();

// A narrow ramp overturns the car. Hitting the wider ramp off center also sends it sliding sideways.
foreach([[0,45,16,30,true],[0,55,32,34,false],[12,55,32,34,false],[-8,55,32,34,false]] as [$x,$z,$radius,$amount,$landsOnRoof])
{
	$ramp = new Session($dry);
	$ramp->landscape->queue(['type'=>'raise','x'=>0,'z'=>$z,'radius'=>$radius,'amount'=>$amount]);
	$ramp->landscape->commit();
	$ramp->camera->position = [$x,30,$landsOnRoof ? -25 : -32]; $ramp->camera->yaw = M_PI;
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
	if($x != 0)
	{
		$side = -$landing['speed']*sin($landing['drift'])*cos($landing['pitch']);
		expect(($x <=> 0)*$side > 2 && $landing['wheelContacts'] >= 3,
			'Steady tire contact ends the tumble during a fast sideways skid in either direction');
		input($ramp, ['keys'=>['KeyW'=>true,'KeyD'=>true]]);
		$ramp->step(1/60);
		$next = $ramp->car->state();
		expect(($next['x']-$landing['x'])*60/$side > .8 && abs($next['drift']) < abs($landing['drift']),
			'The car carries its sideways velocity into the next step as the tires regain grip');
		expect($next['motion'] === 'driving' && $next['steering'] < $landing['steering'] && $next['speed'] > $landing['speed'],
			'Steering and pedals respond on the first driving step after the skid landing');
	}
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
expect(Jet::thrustPercent(0) === 30 && Jet::thrustPercent(Jet::CRUISE_POWER) === 75
	&& Jet::thrustPercent(1) === 140, 'ENG reads 30% at idle, 75% at cruise and 140% at full throttle');
expect($flight->jet->state()['power'] === Jet::CRUISE_POWER, 'The engine starts at cruise power');
input($flight, ['keys'=>['KeyW'=>true]]);
$flight->step(1/60);
$first = $flight->jet->state();
expect($first['power'] > Jet::CRUISE_POWER && $first['power'] < 1.0
	&& $first['speed'] > Jet::CRUISE && $first['speed'] < Jet::CRUISE + Jet::ACCELERATION/60,
	'Applying full throttle spools up and eases into acceleration');
[, $between] = $flight->jet->view($flight->camera,.5);
expect(abs($between['power'] - ($first['power'] + Jet::CRUISE_POWER)/2) < 1e-10,
	'The cockpit samples engine power at the presented flight pose');
input($flight, ['keys'=>[]]);
$flight->step(1/60);
expect($flight->jet->state()['power'] < $first['power'] && $flight->jet->state()['power'] > Jet::CRUISE_POWER,
	'Letting go eases power back down toward cruise');
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

/** Reference Novice attitude after one fixed step. */
function expectedAttitude(array $p, int $bank, int $climb): array
{
	$dt = 1 / 60;
	$roll = $p['roll'] + (-$bank * 1.10 - $p['roll']) * (1 - exp(-3.6 * $dt));
	$pitch = $p['pitch'] + ($climb * .78 - $p['pitch']) * (1 - exp(-2.1 * $dt));
	$yaw = $p['yaw'] + sin($roll) * .68 * $dt;

	return [$roll, $pitch, $yaw];
}

/** Advance an independent free-flight reference through the same sequence of body attitudes. */
function expectedTravel(array $before, array $after, ?array &$track): array
{
	$track ??= jetAxes($before)[2];
	$nose = jetAxes($after)[2];
	$keep = exp(-1 / 60 / .36);
	foreach($track as $i => $v) $track[$i] = $keep*$v + (1-$keep)*$nose[$i];
	$length = vectorLength($track);
	foreach($track as &$v) $v /= $length;
	unset($v);
	return array_map(static fn($v) => $v*$after['speed']/60, $track);
}

// Check the flight path and the direction, travel and rate of the animated control surfaces.
$controls = new Session(['worldSize'=>4096,'heightScale'=>0,'detailAmplitude'=>0]);
$ground = new TerrainPhonics\Landscape($controls->landscape->recipe());
$controls->camera->position = [0,300,0];
$controls->camera->yaw = 0;
input($controls, ['actions'=>['jet']]);
$s = $controls->jet->state();
expect($s['aileron'] === 0.0 && $s['stabilator'] === 0.0 && $s['rudder'] === 0.0,
	'Boarding starts the control surfaces at neutral');

$travel = ['aileron'=>TerrainSandbox\Jet::AILERON, 'stabilator'=>TerrainSandbox\Jet::STABILATOR,
	'rudder'=>TerrainSandbox\Jet::RUDDER];
$oldKeys = ['x','y','z','yaw','pitch','roll','speed','power','vx','vy','vz','aileron','stabilator','rudder','boom','eyeY','eyeX','eyeZ',
	'lookYaw','lookPitch'];
$matched = $bounded = $interpolated = true;
$track = null;
$peak = array_fill_keys(array_keys($travel), 0.0);
$seen = [];
// Bank left and climb, reverse into a right bank and a dive, then level out at low speed and let go.
$script = [['left climb', ['KeyA','KeyE'], 120], ['right dive', ['KeyD','KeyQ','KeyW'], 150],
	['level brake', ['KeyS'], 200], ['hands off', [], 120]];
foreach($script as [$name, $held, $steps])
{
	input($controls, ['keys'=>array_fill_keys($held, true)]);
	$bank = (int)in_array('KeyD', $held) - (int)in_array('KeyA', $held);
	$climb = (int)in_array('KeyE', $held) - (int)in_array('KeyQ', $held);

	for($i = 0; $i < $steps; $i++)
	{
		$before = $controls->jet->state();
		$controls->step(1 / 60);
		$after = $controls->jet->state();
		[$dx,$dy,$dz] = expectedTravel($before,$after,$track);
		[$y,$eyeY] = exactJet($before['y']+$dy,$after,$ground,$controls->water->level());
		$matched = $matched && array_keys($after) === $oldKeys
			&& [$after['roll'],$after['pitch'],$after['yaw']] === expectedAttitude($before,$bank,$climb)
			&& abs($after['x']-$before['x']-$dx) < 1e-9 && abs($after['z']-$before['z']-$dz) < 1e-9
			&& abs($after['y']-$y) < 1e-9 && abs($after['eyeY']-$eyeY) < 1e-9
			&& $after['boom'] === 24.0 * cos(.38 - .75 * $after['pitch']);

		foreach($travel as $key => $limit)
		{
			$bounded = $bounded && abs($after[$key]) <= $limit
				&& abs($after[$key] - $before[$key]) <= TerrainSandbox\Jet::SURFACE_RATE / 60 + 1e-15;
			$peak[$key] = max($peak[$key], abs($after[$key]));
		}
		if($i === 11 || $i === $steps - 1) $seen[$name][$i === 11 ? 'early' : 'late'] = $after;
		if($i !== 30) continue;

		[$eye, $middle] = $controls->jet->view($controls->camera, .25);
		foreach($travel as $key => $limit)
			$interpolated = $interpolated && $middle[$key] === $before[$key] + .25 * ($after[$key] - $before[$key]);
	}
}

expect($matched, 'Control surfaces preserve the expected flight path, attitude and chase eye at the delivered speed');
expect($bounded && $peak['aileron'] > .99 * $travel['aileron'] && $peak['stabilator'] > .99 * $travel['stabilator']
	&& $peak['rudder'] > .99 * $travel['rudder'],
	'Each control surface reaches its stop without passing it and moves no faster than its actuator');

expect($seen['left climb']['early']['aileron'] > 0 && $seen['right dive']['early']['aileron'] < 0
	&& $seen['level brake']['early']['aileron'] > 0,
	'The aileron follows the roll demand: positive while the +X wing is going down');
expect($seen['left climb']['early']['stabilator'] > 0 && $seen['right dive']['early']['stabilator'] < 0
	&& $seen['level brake']['early']['stabilator'] > 0,
	'The stabilator follows the pitch demand: positive while the nose is coming up');
expect($seen['left climb']['late']['rudder'] > .99 * $travel['rudder'] && $seen['left climb']['late']['roll'] > 1
	&& $seen['right dive']['late']['rudder'] < -.99 * $travel['rudder'] && $seen['right dive']['late']['roll'] < -1,
	'The rudder follows the turn: positive while the nose swings toward +X in a left bank');

$settled = $seen['hands off']['late'];
expect(abs($settled['aileron']) < .01 && abs($settled['stabilator']) < .01 && abs($settled['rudder']) < .01,
	'Letting go of the controls returns every surface to neutral');
expect($interpolated, 'Presentation frames interpolate the control surfaces with the rest of the pose');

input($controls, ['keys'=>['KeyA'=>true,'KeyE'=>true]]);
for($i = 0; $i < 12; $i++) $controls->step(1 / 60);
$held = $controls->jet->state();
input($controls, ['actions'=>['pause'], 'keys'=>['KeyD'=>true,'KeyQ'=>true]]);
for($i = 0; $i < 30; $i++) $controls->step(1 / 60);
expect($controls->jet->state() === $held && $held['aileron'] > 0 && $held['stabilator'] > 0,
	'Pausing freezes the control surfaces where they stand');

// The checks above measure travel and rate against Jet's own constants, so pin those to the requested feel too.
expect(abs($travel['aileron'] - deg2rad(20)) < 1e-9 && abs($travel['stabilator'] - deg2rad(15)) < 1e-9
	&& abs($travel['rudder'] - deg2rad(10)) < 1e-9,
	'The stops sit at 20 degrees of aileron, 15 of stabilator and 10 of rudder');

// Leave the jet with its surfaces deflected, then board again: the new flight starts from neutral.
input($controls, ['actions'=>['fly']]);
input($controls, ['actions'=>['jet']]);
$s = $controls->jet->state();
expect($controls->mode === 'jet' && !$controls->paused && $s['aileron'] === 0.0 && $s['stabilator'] === 0.0
	&& $s['rudder'] === 0.0, 'Boarding again after leaving with deflected surfaces starts them at neutral');

// A full demand from neutral is held back by the rate limit, one degree in a 1/60 s step. Once the stabilator is
// near its stop in a held climb, it closes the remaining gap with the 0.08 s lag.
input($controls, ['keys'=>['KeyA'=>true,'KeyE'=>true]]);
$controls->step(1 / 60);
$s = $controls->jet->state();
expect(abs($s['aileron'] - deg2rad(1)) < 1e-9 && abs($s['stabilator'] - deg2rad(1)) < 1e-9,
	'A full demand swings the aileron and stabilator at 60 degrees a second');

// The rate limit lets go after ten steps, and the climb demand keeps the command at the stop until step 39.
$lagged = true;
for($i = 2; $i <= 30; $i++)
{
	$gap = $travel['stabilator'] - $controls->jet->state()['stabilator'];
	$controls->step(1 / 60);
	if($i > 10) $lagged = $lagged && $gap > .001
		&& abs($travel['stabilator'] - $controls->jet->state()['stabilator'] - $gap * exp(-1 / 60 / .08)) < 1e-12;
}
expect($lagged, 'Near its stop the stabilator closes on it with a 0.08 s time constant');

$ground->close();
$controls->close();

/**
 * Turn a point about a hinge line as model.vert does, by Rodrigues' rotation.
 * @param array $point Point in model meters.
 * @param array $hinge Hinge from VehicleModel::hinges(), with a point on the line and a unit axis.
 * @param float $angle Deflection in radians, by the right-hand rule about the axis.
 * @return array The turned point.
 */
function turnAboutHinge(array $point, array $hinge, float $angle): array
{
	[$kx, $ky, $kz] = $hinge['axis'];
	[$px, $py, $pz] = $hinge['point'];
	[$ax, $ay, $az] = [$point[0] - $px, $point[1] - $py, $point[2] - $pz];

	$c = cos($angle);
	$s = sin($angle);
	$along = ($kx*$ax + $ky*$ay + $kz*$az) * (1 - $c);
	return [$px + $ax*$c + ($ky*$az - $kz*$ay)*$s + $kx*$along, $py + $ay*$c + ($kz*$ax - $kx*$az)*$s + $ky*$along,
		$pz + $az*$c + ($kx*$ay - $ky*$ax)*$s + $kz*$along];
}

/**
 * Find the arm from a hinge line to a point, square to the line.
 * @param array $point Point in model meters.
 * @param array $hinge Hinge with a point on the line and a unit axis.
 * @return array Arm vector in meters.
 */
function hingeArm(array $point, array $hinge): array
{
	[$kx, $ky, $kz] = $hinge['axis'];
	$arm = [$point[0] - $hinge['point'][0], $point[1] - $hinge['point'][1], $point[2] - $hinge['point'][2]];
	$along = $arm[0]*$kx + $arm[1]*$ky + $arm[2]*$kz;
	return [$arm[0] - $along*$kx, $arm[1] - $along*$ky, $arm[2] - $along*$kz];
}

/**
 * Measure a vector.
 * @param array $v Three components in meters.
 * @return float Length in meters.
 */
function vectorLength(array $v): float
{
	return sqrt($v[0]**2 + $v[1]**2 + $v[2]**2);
}

// The importer centers the F5 on its center of gravity, tags its moving surfaces and writes their hinges beside the
// mesh. Turned the way model.vert turns them, the baked surfaces move as their controls say and stay on their hinges.
$meshFile = dirname(__DIR__) . '/assets/f5/mesh.bin.gz';
$hinges = TerrainSandbox\VehicleModel::hinges($meshFile);
$mesh = gzdecode(file_get_contents($meshFile));

$parts = [];
$low = [INF, INF, INF];
$high = [-INF, -INF, -INF];

for($i = 0, $count = unpack('V', $mesh)[1]; $i < $count; $i++)
{
	$point = array_values(unpack('g3', $mesh, 16 + 28 * $i));
	$slot = ord($mesh[16 + 28 * $i + 27]) - TerrainSandbox\VehicleModel::FIRST_SURFACE;
	if($slot >= 0 && $slot < TerrainSandbox\VehicleModel::SURFACES) $parts[$slot][] = $point;
	$low = array_map('min', $low, $point);
	$high = array_map('max', $high, $point);
}

expect(abs($low[0] + $high[0]) < .001 && abs($high[1] - 2.461) < .001 && abs($low[1] + .826) < .001
	&& abs(($low[2] + $high[2]) / 2 - 1.53) < .001,
	'Removing the belly tank raises the keel while the tail, span and nose retain their balance-point frame');
ksort($parts);
expect(array_keys($parts) === array_keys($hinges) && count($hinges) === 5
	&& array_count_values(array_column($hinges, 'control')) === ['aileron'=>2, 'stabilator'=>2, 'rudder'=>1],
	'Two ailerons, two stabilators and the rudder are tagged, each with a hinge');

$conventions = true;
$slip = 0.0;
foreach($hinges as $slot => $hinge)
{
	$arms = [];
	foreach($parts[$slot] as $index => $point) $arms[$index] = hingeArm($point, $hinge);
	$reach = max(array_map('vectorLength', $arms));

	foreach($parts[$slot] as $index => $point)
	{
		$moved = turnAboutHinge($point, $hinge, $travel[$hinge['control']]);
		// Trailing-edge vertices lie within 2 cm of the farthest reach from the hinge.
		if(vectorLength($arms[$index]) > $reach - .02) $conventions = $conventions && match($hinge['control']) {
			'aileron' => ($moved[1] - $point[1]) * $hinge['point'][0] > 0,
			'stabilator' => $moved[1] > $point[1],
			'rudder' => $moved[0] > $point[0]
		};
		// An aileron or the rudder meets the skin along its leading edge: the vertices within 6 cm of the hinge
		// along the chord, through the whole thickness. An all-moving stabilator has no such seam.
		if($hinge['control'] !== 'stabilator' && abs($arms[$index][2]) < .06)
			$slip = max($slip, vectorLength([$moved[0] - $point[0], $moved[1] - $point[1], $moved[2] - $point[2]]));
	}
}
expect($conventions, 'At full travel the +X aileron trailing edge rises, the -X one falls, both stabilator trailing'
	. ' edges rise and the rudder swings toward +X');
expect($slip < .02, sprintf('Vertices along the hinges move %.1f mm at full travel', $slip * 1000));

/**
 * Measure how far below a point of the baked F5 the nearest fixed triangle lies.
 * @param array $point Point in model meters.
 * @param array $positions Position of every vertex in model meters.
 * @param array $vertexTags Surface tag of every vertex.
 * @param array $corners Vertex indices, three per triangle.
 * @return float Distance straight down to fixed skin in meters, or INF when there is none.
 */
function skinBelow(array $point, array $positions, array $vertexTags, array $corners): float
{
	$depth = INF;
	for($k = 0; $k < count($corners); $k += 3)
	{
		$slot = $vertexTags[$corners[$k]] - TerrainSandbox\VehicleModel::FIRST_SURFACE;
		if($slot >= 0 && $slot < TerrainSandbox\VehicleModel::SURFACES) continue;
		[$a, $b, $c] = [$positions[$corners[$k]], $positions[$corners[$k + 1]], $positions[$corners[$k + 2]]];
		$area = ($b[2] - $c[2]) * ($a[0] - $c[0]) + ($c[0] - $b[0]) * ($a[2] - $c[2]);
		if(abs($area) < 1e-12) continue;

		$u = (($b[2] - $c[2]) * ($point[0] - $c[0]) + ($c[0] - $b[0]) * ($point[2] - $c[2])) / $area;
		$v = (($c[2] - $a[2]) * ($point[0] - $c[0]) + ($a[0] - $c[0]) * ($point[2] - $c[2])) / $area;
		if($u < 0 || $v < 0 || $u + $v > 1) continue;
		$below = $point[1] - ($u * $a[1] + $v * $b[1] + (1 - $u - $v) * $c[1]);
		if($below >= 0) $depth = min($depth, $below);
	}

	return $depth;
}

// A deflected part must not uncover the inside of the model. Every moving part is a closed surface, and fixed skin
// lies just below the rudder's foot, where the source model leaves the fuselage spine open under the rudder.
$positions = $vertexTags = $names = $edges = $feet = [];
for($i = 0; $i < $count; $i++)
{
	$positions[] = array_values(unpack('g3', $mesh, 16 + 28 * $i));
	$vertexTags[] = ord($mesh[16 + 28 * $i + 27]);
	// Corners are named to a tenth of a millimeter. Adding zero turns -0 into 0 on the centerline.
	$names[] = implode(',', [round($positions[$i][0], 4) + 0.0, round($positions[$i][1], 4) + 0.0,
		round($positions[$i][2], 4) + 0.0]);
}
$corners = array_values(unpack('v*', substr($mesh, 16 + 28 * $count, 2 * unpack('V', $mesh, 4)[1])));

for($k = 0; $k < count($corners); $k += 3)
{
	$slot = $vertexTags[$corners[$k]] - TerrainSandbox\VehicleModel::FIRST_SURFACE;
	if($slot < 0 || $slot >= TerrainSandbox\VehicleModel::SURFACES) continue;

	for($j = 0; $j < 3; $j++)
	{
		$ends = [$names[$corners[$k + $j]], $names[$corners[$k + ($j + 1) % 3]]];
		sort($ends);
		$edges[$slot][implode(' ', $ends)] = ($edges[$slot][implode(' ', $ends)] ?? 0) + 1;
	}
	if($hinges[$slot]['control'] === 'rudder') $feet[] = array_slice($corners, $k, 3);
}

$closed = count($edges) === TerrainSandbox\VehicleModel::SURFACES;
foreach($edges as $uses) $closed = $closed && !array_diff($uses, [2]);
expect($closed, 'Each moving part of the F5 is a closed surface, so a deflection never shows its inside');

$foot = INF;
foreach($feet as $triangle) foreach($triangle as $i) $foot = min($foot, $positions[$i][1]);
$floored = true;
$standing = 0;

foreach($feet as [$a, $b, $c])
{
	if(max($positions[$a][1], $positions[$b][1], $positions[$c][1]) > $foot + .012) continue;
	$middle = [];
	for($axis = 0; $axis < 3; $axis++)
		$middle[] = ($positions[$a][$axis] + $positions[$b][$axis] + $positions[$c][$axis]) / 3;
	$standing++;
	$floored = $floored && skinBelow($middle, $positions, $vertexTags, $corners) < .01;
}
expect($floored && $standing > 0, 'Fixed skin lies under the rudder\'s foot, so the spine stays closed as it swings');

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

// The selection slice is a second comparison value of the same kind: zero runs every selection whole.
$knob = new Session(['worldSize' => 256,'heightScale' => 0,'detailAmplitude' => 0]);
expect($knob->selectionSliceMs === TerrainPhonics\Scene::DEFAULT_SELECTION_SLICE_MS
	&& $knob->status()['selectionSliceMs'] === $knob->selectionSliceMs,
	'The selection slice starts at the renderer default and the status reports it');
$settings = $knob->settings;
$file = $knob->save();
$knob->dirty = false;

input($knob, ['values' => ['selectionSliceMs' => 0]]);
expect($knob->selectionSliceMs === 0.0 && $knob->status()['selectionSliceMs'] === 0.0,
	'The page can turn spread selections off');
expect($knob->settings === $settings && $knob->save() === $file && !$knob->dirty,
	'The slice changes neither the saved settings, the save file nor the picture');
input($knob, ['values' => ['selectionSliceMs' => '3','selectionInterval' => .2]]);
expect($knob->selectionSliceMs === 3.0 && $knob->selectionInterval === .2,
	'The slice travels beside the interval in one input packet');

input($knob, ['values' => ['selectionSliceMs' => 500]]);
expect($knob->selectionSliceMs === 100.0, 'The slice is held to 100 ms at most');
input($knob, ['values' => ['selectionSliceMs' => -2]]);
expect($knob->selectionSliceMs === 0.0, 'A negative slice turns spreading off');
input($knob, ['values' => ['selectionSliceMs' => 'lots']]);
expect($knob->selectionSliceMs === 0.0 && $knob->message === 'Invalid control value',
	'A slice that is not a number is refused and reported');

input($knob, ['values' => ['selectionSliceMs' => 1.5]]);
$file = json_decode($knob->save(), true, flags: JSON_THROW_ON_ERROR);
expect(!array_key_exists('selectionSliceMs', $file) && !array_key_exists('selectionSliceMs', $file['settings']),
	'Save files do not record the slice');
$file['settings']['selectionSliceMs'] = 9;
$knob->load(json_encode($file, JSON_THROW_ON_ERROR));
expect($knob->selectionSliceMs === 1.5 && !array_key_exists('selectionSliceMs', $knob->settings),
	'A file cannot set the slice or add it to the settings');
$knob->close();

/**
 * Work out where one step leaves the jet and its chase eye when both sample the exact surface every time.
 * @param float $freeY Altitude the flight path reaches before clearance.
 * @param array $after Jet state after the step, which supplies the new attitude, speed and position.
 * @param TerrainPhonics\Landscape $ground Surface with the same recipe and edits as the one the jet flies over.
 * @param float $water Water level in meters.
 * @return array Jet height and eye height in meters.
 */
function exactJet(float $freeY, array $after, TerrainPhonics\Landscape $ground, float $water): array
{
	$y = min(15000.0, $freeY);
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
 * @param array|null $track Reference direction carried between successive calls for the same flight.
 * @return array Whether every step matched, and how many steps left the jet resting on the ground's 8 meter floor.
 */
function flyExactly(Session $session, TerrainPhonics\Landscape $ground, int $steps, ?array &$track): array
{
	$matched = true;
	$resting = 0;
	for($i = 0; $i < $steps; $i++)
	{
		$before = $session->jet->state();
		$session->step(1 / 60);
		$after = $session->jet->state();
		[, $dy] = expectedTravel($before,$after,$track);
		[$y,$eyeY] = exactJet($before['y']+$dy, $after, $ground, $session->water->level());
		$matched = $matched && abs($after['y']-$y) < 1e-9 && abs($after['eyeY']-$eyeY) < 1e-9;
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
$track = null;
$nodes = $air->landscape->stats()['generated'];
[$matched] = flyExactly($air, $ground, 600, $track);
expect($matched && $air->landscape->stats()['generated'] === $nodes,
	'A jet cruising high over the coast stays on the exact path without generating a lattice node');
input($air, ['keys'=>['KeyW'=>true,'KeyQ'=>true]]);
[$matched] = flyExactly($air, $ground, 420, $track);
$nodes = $air->landscape->stats()['generated'];
[$skimmed, $resting] = flyExactly($air, $ground, 300, $track);
expect($matched && $skimmed && $resting > 200 && $air->landscape->stats()['generated'] > $nodes + 3000,
	"A diving jet samples the true surface and rests on its 8 meter floor: $resting of 300 steps");
input($air, ['keys'=>['KeyW'=>true,'KeyE'=>true]]);
[$matched] = flyExactly($air, $ground, 240, $track);
$nodes = $air->landscape->stats()['generated'];
input($air, ['keys'=>['KeyW'=>true]]);
[$level] = flyExactly($air, $ground, 240, $track);
expect($matched && $level && $air->landscape->stats()['generated'] === $nodes,
	'Climbing away returns the jet to steps without node generation');

// A bound remembered from before an edit would let the jet fly through ground that the edit raised.
$s = $air->jet->state();
[$eyeX, $eyeY, $eyeZ] = $air->camera->position;
$bump = ['type'=>'raise','x'=>$eyeX,'z'=>$eyeZ,'radius'=>12.0,'amount'=>$eyeY - $ground->height($eyeX, $eyeZ) + 40];
foreach([$air->landscape, $ground] as $surface) { $surface->queue($bump); $surface->commit(); }
[$matched] = flyExactly($air, $ground, 1, $track);
[$eyeX, $lifted, $eyeZ] = $air->camera->position;
expect($matched && $lifted === $ground->height($eyeX, $eyeZ) + 3 && $lifted > $eyeY + 20
	&& abs($air->jet->state()['y'] - $s['y']) < 1,
	'Ground raised behind the jet lifts the chase eye onto the true surface and leaves the jet level');
$s = $air->jet->state();
$dome = ['type'=>'raise','x'=>$s['x'],'z'=>$s['z'],'radius'=>600.0,'amount'=>2500.0];
foreach([$air->landscape, $ground] as $surface) { $surface->queue($dome); $surface->commit(); }
[$matched, $resting] = flyExactly($air, $ground, 1, $track);
expect($matched && $resting === 1 && $air->jet->state()['y'] > $s['y'] + 1500,
	'Ground raised under a cruising jet carries it up on the next step');
[$matched] = flyExactly($air, $ground, 400, $track);
$s = $air->jet->state();
$mesa = ['type'=>'raise','x'=>$s['x'],'z'=>$s['z'],'radius'=>500.0,'amount'=>6000.0];
$replacement = TerrainPhonics\Landscape::load($air->landscape->save());
$replacement->queue($mesa); $replacement->commit();
$air->publishLandscape($replacement);
$ground->queue($mesa); $ground->commit();
[$published, $resting] = flyExactly($air, $ground, 1, $track);
expect($matched && $published && $resting === 1 && $air->jet->state()['y'] > $s['y'] + 1500,
	'A published replacement landscape takes effect on the next step');
[$matched] = flyExactly($air, $ground, 120, $track);
expect($matched, 'The jet stays on the exact path over the replacement');
$ground->close();
$air->close();

// A generated world starts again at revision zero, so only its identity tells the jet that the ground changed.
$worlds = new Session(['heightScale'=>0,'detailAmplitude'=>0]);
$ground = new TerrainPhonics\Landscape($worlds->landscape->recipe());
$worlds->camera->position = [600,40,1800];
input($worlds, ['actions'=>['jet']]);
$track = null;
[$matched] = flyExactly($worlds, $ground, 20, $track);
expect($matched && $worlds->jet->state()['y'] === 75.0, 'A jet over a featureless plain holds its boarding height');
$ground->close();
input($worlds, ['actions'=>[['type'=>'generate','preset'=>'mountain','seed'=>'first-light']]]);
$ground = new TerrainPhonics\Landscape($worlds->landscape->recipe());
expect($worlds->landscape->revision() === 0 && $ground->height(600, 1800) > 100,
	'The generated mountains stand where the plain was');
$worlds->camera->position = [600,40,1800];
input($worlds, ['actions'=>['jet'], 'keys'=>['KeyQ'=>true]]);
$track = null;
[$matched, $resting] = flyExactly($worlds, $ground, 240, $track);
expect($matched && $resting > 60, 'Boarding over a newly generated world forgets the bounds of the old one');
$ground->close();
$worlds->close();

// A block bound says nothing about ground beyond its own rectangle. Six thin towers stand 37 meters apart on a
// plain, each 100 meters taller than the last, so they meet the 2 to 256 meter blocks at six different offsets.
$tower = new Session(['heightScale'=>0,'detailAmplitude'=>0]);
for($i = 0; $i < 6; $i++)
	$tower->landscape->queue(['type'=>'raise','x'=>600,'z'=>1900 + 37 * $i,'radius'=>6.0,'amount'=>500.0 + 100 * $i]);
$tower->landscape->commit();
$ground = TerrainPhonics\Landscape::load($tower->landscape->save());
$tower->camera->position = [600,40,1800];
$tower->camera->yaw = M_PI;
input($tower, ['actions'=>['jet']]);
$track = null;
$nodes = $tower->landscape->stats()['generated'];
// At the 140 m/s cruise, 31 steps stop 72 meters on, short of the first tower, and 102 more cross all six.
[$matched] = flyExactly($tower, $ground, 31, $track);
expect($matched && $tower->landscape->stats()['generated'] === $nodes,
	'A jet approaching a tower clears the open plain without sampling it');
[$matched, $resting] = flyExactly($tower, $ground, 102, $track);
expect($matched && $resting >= 12 && $tower->jet->state()['y'] > 1007,
	"The jet rides up each tower face in turn: $resting steps on a face");
$ground->close();
$tower->close();


// Gamepads. The page sends [left X, left Y, right X, right Y, left trigger, right trigger, button bits] with +Y
// toward the player, and only when the pad changes; the application re-sends the last packet every frame.

/** Build a gamepad packet. */
function pad(float $x = 0, float $y = 0, float $lookX = 0, float $lookY = 0, float $lt = 0, float $rt = 0,
	int $buttons = 0): array
{
	return [$x, $y, $lookX, $lookY, $lt, $rt, $buttons];
}

/**
 * Hold a pad and keys for some fixed steps, as the application does each frame.
 * @return list<array> Vehicle state after each step, taken with $read.
 */
function hold(Session $session, array $pad, int $steps, callable $read, array $keys = []): array
{
	$states = [];
	for($i = 0; $i < $steps; $i++)
	{
		input($session, ['pad'=>$pad, 'keys'=>$keys]);
		$session->step(1 / 60);
		$states[] = $read($session);
	}
	return $states;
}

/** The jet's left, up and forward axes for a pose, as VehicleModel draws them. */
function jetAxes(array $p): array
{
	$cy = cos($p['yaw']); $sy = sin($p['yaw']); $cp = cos($p['pitch']); $sp = sin($p['pitch']);
	$cr = cos($p['roll']); $sr = sin($p['roll']);
	return [[$cy*$cr + $sy*$sp*$sr, -$cp*$sr, -$sy*$cr + $cy*$sp*$sr],
		[$cy*$sr - $sy*$sp*$cr, $cp*$cr, -$sy*$sr - $cy*$sp*$cr], [$sy*$cp, $sp, $cy*$cp]];
}

/** Largest change of heading, pitch or roll between consecutive states. */
function largestTurn(array $states): float
{
	$largest = 0.0;
	for($i = 1; $i < count($states); $i++)
		foreach(['yaw','pitch','roll'] as $key)
			$largest = max($largest, abs($states[$i][$key] - $states[$i - 1][$key]));
	return $largest;
}

/** Board a jet high over an open plain, heading toward +Z. */
function pilot(string $controls = 'novice'): Session
{
	$session = new Session(['worldSize'=>8192,'heightScale'=>0,'detailAmplitude'=>0]);
	$session->camera->position = [0,1500,-2500];
	$session->camera->yaw = M_PI;
	input($session, ['values'=>['jetControls'=>$controls], 'actions'=>['jet']]);
	return $session;
}

$jet = fn(Session $session): array => $session->jet->state();
$eye = fn(Session $session): array => [$session->camera->position, $session->camera->yaw, $session->camera->pitch];

// Free flight.
$roam = new Session(['worldSize'=>4096,'heightScale'=>0,'detailAmplitude'=>0]);
$roam->camera->position = [0,300,0]; $roam->camera->yaw = 0; $roam->camera->pitch = 0;
input($roam, ['pad'=>pad(y: -1)]);
[$x,,$z] = $roam->camera->position;
expect($z < -1.6 && abs($x) < 1e-9, 'Pushing the left stick forward flies the free camera ahead');
$before = $roam->camera->position;
input($roam, ['pad'=>pad(rt: 1)]);
expect($roam->camera->position[1] > $before[1] + 1.6, 'The right trigger climbs in free flight');
$yaw = $roam->camera->yaw;
$before = $roam->camera->position;
input($roam, ['pad'=>pad(lookX: 1)]);
expect($roam->camera->yaw > $yaw && $roam->camera->position === $before,
	'The right stick turns the free view without moving it');
$before = $roam->camera->position;
input($roam, ['pad'=>pad(y: -1, buttons: Session::PAD_LS)]);
$fast = hypot($roam->camera->position[0] - $before[0], $roam->camera->position[2] - $before[2]);
expect(abs($fast - 5) < 1e-9, 'Clicking the left stick flies at 300 m/s, as Shift does');
foreach([[1,2], ['1',0,0,0,0,0,0], [INF,0,0,0,0,0,0], ['x'=>1], 'pad', [0,0,0,0,0,0,0,0]] as $bad)
{
	$before = [$roam->camera->position, $roam->camera->yaw];
	input($roam, ['pad'=>$bad]);
	expect([$roam->camera->position, $roam->camera->yaw] === $before, 'A malformed pad packet reads as a pad at rest');
}
$before = $roam->camera->position;
input($roam, ['pad'=>pad(y: -7)]);
$far = $before[2] - $roam->camera->position[2];
$before = $roam->camera->position;
input($roam, ['pad'=>pad(y: -1)]);
expect(abs($far - ($before[2] - $roam->camera->position[2])) < 1e-9 && $far > 1.6,
	'Stick values beyond full travel count as full travel');
$roam->close();

// At the ceiling the aircraft can point upward while traveling horizontally.
$path = new Session(['worldSize'=>8192, 'heightScale'=>0, 'detailAmplitude'=>0]);
$path->camera->position = [0,15000,0];
input($path, ['actions'=>['jet']]);
$start = $path->jet->state();
expect(abs($start['vy']) < 1e-9 && abs(hypot($start['vx'],$start['vz']) - Jet::CRUISE) < 1e-8,
	'The flight path starts at the boarding heading and cruise speed');
input($path, ['pad'=>pad(x:.45,y:.4)]);
for($i = 0; $i < 40; $i++) $path->step(1/60);
$last = $path->jet->state();
$path->step(1/60);
$now = $path->jet->state();
[, $mid] = $path->jet->view($path->camera,.5);
foreach(['x','y','z'] as $axis)
{
	$v = 'v'.$axis;
	expect(abs($now[$v] - 60*($now[$axis]-$last[$axis])) < 1e-9 && abs($mid[$v] - ($last[$v]+$now[$v])/2) < 1e-9,
		"Flight path $axis follows real movement and interpolates with the attitude");
}
expect($now['pitch'] > .1 && $now['vy'] === 0.0, 'The flight path stays horizontal at the ceiling while the nose climbs');
$path->close();

foreach(['novice','expert'] as $handling)
{
	$path = pilot($handling);
	$pull = hold($path,pad(y:.7),30,$jet);
	$p = end($pull);
	$pathPitch = atan2($p['vy'],hypot($p['vx'],$p['vz']));
	expect($pathPitch > 0 && $p['pitch']-$pathPitch > .07, "$handling pull-up carries forward below the nose in open air");
	$straight = hold($path,pad(),260,$jet);
	$p = end($straight);
	expect(abs($p['pitch']-atan2($p['vy'],hypot($p['vx'],$p['vz']))) < .001,
		"$handling flight path catches up once the nose steadies");
	$path->close();
}

// The two Dreamcast handling layouts share the aircraft and expose different throttle controls.
$bindings = pilot('expert');
$triggerTurn = hold($bindings, pad(rt:.5), 60, $jet);
$halfTurn = end($triggerTurn);
expect($halfTurn['yaw'] < -.10 && $halfTurn['yaw'] > -.20 && $halfTurn['power'] === Jet::CRUISE_POWER,
	'Expert right trigger applies proportional right rudder at cruise power');
$full = hold($bindings, pad(buttons:Session::PAD_Y), 90, $jet);
$slow = hold($bindings, pad(buttons:Session::PAD_B), 100, $jet);
expect(end($full)['power'] > .98 && end($slow)['power'] < .01, 'Expert Y and B spool the engine up and down');
input($bindings, ['actions'=>['pad-down']]);
expect($bindings->jetWideMap && $bindings->mode === 'jet', 'D-pad down opens the wide chart in flight');
input($bindings, ['actions'=>['pad-down','jet-map']]);
expect(!$bindings->jetMap && !$bindings->jetWideMap, 'Stick click can hide the local chart after closing the wide chart');
$bindings->balloons->place(10,-2000,200,$bindings->landscape,0);
$bindings->balloons->place(10,-1800,200,$bindings->landscape,0);
$bindings->balloons->cycle();
$selected = $bindings->balloons->status()['selected'];
input($bindings, ['actions'=>['pad-up']]);
expect($selected !== $bindings->balloons->status()['selected'] && $bindings->mode === 'jet',
	'D-pad up cycles a placed balloon while flying');
$bindings->close();

// Novice: AirForce Delta's default handling. A full stick or trigger flies exactly as the key it stands for.
$keys = pilot(); $stick = pilot();
$keyed = hold($keys, pad(), 200, $jet, ['KeyW'=>true,'KeyD'=>true,'KeyE'=>true]);
$padded = hold($stick, pad(x: 1, y: 1, rt: 1), 200, $jet);
expect($keyed === $padded, 'In Novice the full stick and right trigger fly exactly as D, E and W');
$keys->close();
$s = $stick->jet->state();
expect($stick->jetControls === 'novice' && !$stick->jet->expert() && $s['speed'] === Jet::FASTEST,
	'Holding the right trigger reaches top speed');
hold($stick, pad(x: .5), 300, $jet);
$s = $stick->jet->state();
expect(abs($s['roll'] + .55) < 1e-3 && $s['speed'] === Jet::CRUISE,
	'Half stick holds half the Novice bank, and releasing the trigger returns to cruise');
hold($stick, pad(lt: .5), 300, $jet);
expect($stick->jet->state()['speed'] === Jet::CRUISE - .5 * (Jet::CRUISE - Jet::SLOWEST) && abs($stick->jet->state()['roll']) < 1e-3,
	'Half the left trigger slows to a speed halfway toward the slowest, and the wings level hands off');
hold($stick, pad(y: 1), 150, $jet);
expect($stick->jet->state()['pitch'] > .75, 'Pulling the stick back raises the nose');
input($stick, ['values'=>['invertPitch'=>true]]);
hold($stick, pad(y: 1), 150, $jet);
expect($stick->invertPitch && $stick->jet->state()['pitch'] < -.75, 'With the stick inverted, pulling back lowers the nose');
input($stick, ['values'=>['invertPitch'=>'yes']]);
expect($stick->invertPitch && $stick->message === 'Invert pitch must be true or false', 'A non-boolean invert is refused');
input($stick, ['values'=>['invertPitch'=>false]]);
hold($stick, pad(x: -1), 60, $jet, ['KeyD'=>true]);
expect($stick->jet->state()['roll'] < -1, 'Held keys win over the stick');
$stick->close();

// Expert: roll, pitch and rudder about the jet's own axes. The attitude holds when the stick is released.
$ace = pilot('expert');
expect($ace->jet->expert() && str_starts_with($ace->message, 'Expert') && $ace->status()['jetControls'] === 'expert',
	'Expert handling can be chosen before boarding');
$start = $ace->jet->state();
$rolled = hold($ace, pad(x: 1), (int)round(2 * M_PI / Jet::ROLL_RATE * 60), $jet);
$rolled = array_merge($rolled, hold($ace, pad(), 60, $jet));
$s = $ace->jet->state();
[, $up] = jetAxes($s);
expect($rolled[5]['roll'] < $start['roll'] && $rolled[5]['aileron'] < 0 && min(array_column($rolled, 'aileron')) < -.99 * Jet::AILERON,
	'Right stick rolls right on full aileron');
expect($up[1] > .995 && abs($s['roll'] - ($start['roll'] - 2 * M_PI)) < .05 && largestTurn($rolled) < 1.05 * Jet::ROLL_RATE / 60,
	'A full roll comes back wings level, and its angles change smoothly all the way round');
$heading = $s['yaw'];
[, , $ahead] = jetAxes($s);
// Read each step's pose, the drawn pose halfway to it, and the camera.
$looped = hold($ace, pad(y: 1), (int)round(2 * M_PI / Jet::PITCH_RATE * 60), fn($session) => $session->jet->state()
	+ ['camera'=>[$session->camera->position, $session->camera->yaw], 'middle'=>$session->jet->view($session->camera, .5)[1]]);
$turning = count($looped);
$looped = array_merge($looped, hold($ace, pad(), 60, $jet));
$s = $ace->jet->state();
[, $up, $nose] = jetAxes($s);
$top = [INF, 0.0];
$noseTurn = $drawn = $cameraTurn = 0.0;
$farthest = 0.0; $nearest = INF;
for($i = 1; $i < $turning; $i++)
{
	[, , $a] = jetAxes($looped[$i - 1]); [, $u, $b] = jetAxes($looped[$i]); [, , $m] = jetAxes($looped[$i]['middle']);
	$back = $b[0]*$ahead[0] + $b[1]*$ahead[1] + $b[2]*$ahead[2];
	if($back < $top[0]) $top = [$back, $u[1]];
	$noseTurn = max($noseTurn, acos(min(1.0, $a[0]*$b[0] + $a[1]*$b[1] + $a[2]*$b[2])));
	$half = array_map(fn($u, $v) => $u + $v, $a, $b);
	$length = sqrt(array_sum(array_map(fn($u) => $u * $u, $half)));
	$drawn = max($drawn, acos(min(1.0, ($half[0]*$m[0] + $half[1]*$m[1] + $half[2]*$m[2]) / $length)));
	$cameraTurn = max($cameraTurn, abs($looped[$i]['camera'][1] - $looped[$i - 1]['camera'][1]));
	$distance = sqrt(array_sum(array_map(fn($u, $v) => ($u - $v) ** 2, $looped[$i]['camera'][0],
		[$looped[$i]['x'], $looped[$i]['y'], $looped[$i]['z']])));
	$farthest = max($farthest, $distance); $nearest = min($nearest, $distance);
}
expect($top[0] < -.999 && $top[1] < -.99,
	'Halfway round a loop the jet flies inverted the opposite way');
expect($noseTurn < 1.05 * Jet::PITCH_RATE / 60 && $drawn < 1e-3,
	'The nose turns smoothly through the loop, and the pose drawn between steps lies halfway between them');
expect($nose[1] < .05 && $up[1] > .99 && abs(atan2(sin($s['yaw'] - $heading), cos($s['yaw'] - $heading))) < .05,
	'A full loop returns level on the heading it started on');
expect($cameraTurn <= 4.0 / 60 + 1e-9 && $nearest > 15 && $farthest < 40,
	'The Expert chase camera stays behind the jet through the loop and turns smoothly over the top');
$level = $ace->jet->state();
hold($ace, pad(), 30, $jet, ['KeyX'=>true]);
$s = $ace->jet->state();
expect($s['yaw'] < $level['yaw'] - .1 && abs($s['roll'] - $level['roll']) < .02 && $s['rudder'] < 0,
	'X yaws the nose right on the rudder');
hold($ace, pad(lookX: -1), 60, $jet);
expect($ace->jet->state()['yaw'] > $s['yaw'] + .2, 'The right stick yaws the nose left');
hold($ace, pad(x: 1), (int)round(M_PI / 4 / Jet::ROLL_RATE * 60), $jet);
hold($ace, pad(), 30, $jet);
$banked = $ace->jet->state();
hold($ace, pad(), 120, $jet);
$s = $ace->jet->state();
expect(abs($s['roll'] - $banked['roll']) < .01 && $s['yaw'] < $banked['yaw'] - .05 && $s['yaw'] > $banked['yaw'] - .2,
	'Released in a bank, the jet holds it and slowly turns toward the lower wing');
input($ace, ['actions'=>['jet-controls']]);
expect(!$ace->jet->expert() && $ace->jetControls === 'novice' && str_starts_with($ace->message, 'Novice'),
	'Back on the pad switches to Novice in flight');
input($ace, ['values'=>['jetControls'=>'arcade']]);
expect($ace->jetControls === 'novice' && $ace->message === 'Jet controls must be novice or expert',
	'Unknown handling is refused');
$ace->close();
// Over the top of a half loop the jet flies inverted with its nose past vertical.
$ace = pilot('expert');
hold($ace, pad(y: 1), (int)round(.75 * M_PI / Jet::PITCH_RATE * 60), $jet);
hold($ace, pad(), 20, $jet);
$over = $ace->jet->state();
input($ace, ['values'=>['jetControls'=>'novice']]);
$s = $ace->jet->state();
$same = true;
foreach([jetAxes($over), jetAxes($s)] as $i => $axes) $orientation[$i] = $axes;
foreach([0, 1, 2] as $axis) foreach([0, 1, 2] as $j) $same = $same && abs($orientation[0][$axis][$j] - $orientation[1][$axis][$j]) < 1e-9;
expect($over['pitch'] > M_PI / 2 && abs($s['pitch']) <= M_PI / 2 && $same,
	'Novice takes over an inverted jet in the same orientation, with the nose within 90 degrees of level');
hold($ace, pad(), 600, $jet);
$s = $ace->jet->state();
expect(abs($s['pitch']) < .01 && abs(atan2(sin($s['roll']), cos($s['roll']))) < .01
	&& abs(atan2(sin($ace->camera->yaw + $s['yaw'] - M_PI), cos($ace->camera->yaw + $s['yaw'] - M_PI))) < 1e-9,
	'Novice then rolls the jet upright, levels it and puts the camera back behind it');
$file = json_decode($ace->save(), true, flags: JSON_THROW_ON_ERROR);
expect(!array_key_exists('jetControls', $file['settings']) && !array_key_exists('invertPitch', $file['settings']),
	'Save files do not record the jet handling');
$file['settings']['jetControls'] = 'expert';
$ace->load(json_encode($file, JSON_THROW_ON_ERROR));
expect($ace->jetControls === 'novice', 'A file cannot choose the jet handling');
$ace->close();

// The car: right trigger accelerates, left trigger brakes and then reverses, A is the handbrake.
$open = ['worldSize' => 1024, 'heightScale' => 0, 'detailAmplitude' => 0, 'waterLevel' => -5];
$car = fn(Session $session): array => $session->car->state();
$park = function() use ($open): Session {
	$session = new Session($open);
	$session->camera->position = [0,50,-300]; $session->camera->yaw = M_PI;
	enterCar($session);
	return $session;
};
$keys = $park(); $stick = $park();
expect(hold($keys, pad(), 120, $car, ['KeyW'=>true,'KeyD'=>true]) === hold($stick, pad(x: 1, rt: 1), 120, $car),
	'A full stick and right trigger drive exactly as W and D');
$keys->close();
$floored = $park(); $light = $park();
$full = hold($floored, pad(rt: 1), 60, $car);
$half = hold($light, pad(rt: .5), 60, $car);
// Half the pedal gives half the drive and leaves half the rolling resistance a released pedal meets.
expect(end($half)['speed'] > end($full)['speed'] / 3 && end($half)['speed'] < end($full)['speed'] / 2,
	'Half the right trigger accelerates more gently');
$normalFov = $floored->camera->fov;
$ordinaryFov = $floored->view(1.)[0]->fov;
expect($light->view(1.)[0]->fov > $normalFov && $ordinaryFov > $light->view(1.)[0]->fov && $car($floored)['nitrous'] === 1.,
	'A normal launch opens the lens progressively with the speed gained on each pedal');
$light->close(); $floored->close();
$boosted = $park(); $shift = $park(); $unassisted = $park();
$boost = pad(rt: 1, buttons: Session::PAD_LB);
$first = hold($boosted,$boost,60,$car);
expect($first === hold($shift,pad(),60,$car,['KeyW'=>true,'ShiftRight'=>true]),
	'Shift and LB draw from the same full bottle');
expect(abs(end($first)['nitrous']-.75)<1e-9 && end($first)['speed'] > end($full)['speed']+10
	&& min(end($first)['wheelTravel'])>.06, 'One second of boost consumes a quarter bottle, pulls harder and loads all four tires');
$halfway = $boosted->carView(.5)[1]['nitrous'];
expect(abs($halfway - ($first[58]['nitrous']+$first[59]['nitrous'])/2)<1e-12, 'The gauge interpolates a draining bottle between steps');
$wideFov = $shift->view(1.)[0]->fov;
expect($wideFov > $ordinaryFov && $wideFov < $normalFov+deg2rad(15) && $boosted->view(1.)[0]->fov === $wideFov,
	'The faster launch on keyboard and controller opens the lens equally and leaves room for further speed');
input($shift,['actions'=>['pause']]);
$shift->step(.1);
expect($shift->view(0.)[0]->fov === $wideFov, 'Pausing freezes the current driving view');
input($shift,['actions'=>['fly']]);
expect($shift->view(1.)[0]->fov === $normalFov, 'Free flight immediately uses its own normal lens');
enterCar($shift);
expect($shift->view(0.)[0]->fov === $normalFov, 'A new drive begins with the normal lens');
hold($boosted,$boost,180,$car);
$emptyFov = $boosted->view(1.)[0]->fov;
expect($car($boosted)['nitrous'] === 0. && $emptyFov > $wideFov && $emptyFov > $normalFov+deg2rad(24),
	'The high-speed view is still wide when the last nitrous burns');
hold($boosted,$boost,10,$car);
expect($boosted->view(1.)[0]->fov >= $emptyFov, 'Continued acceleration keeps opening the lens after depletion');
$rolling = hold($boosted,pad(),5,$car);
$trickle = end($rolling)['nitrous'];
expect($trickle > 0. && $trickle < .03 && abs($boosted->carView(.5)[1]['nitrous'] - ($rolling[3]['nitrous']+$trickle)/2)<1e-12,
	'Even the small initial refill is visible between simulation steps');
$blocked = hold($boosted,$boost,6,$car);
expect(min(array_column($blocked,'nitrous')) === $trickle && max(array_column($blocked,'nitrous')) === $trickle,
	'A fresh press after depletion cannot spend a charge below the restart threshold');
for($i = 0; $i < 60 && $car($boosted)['nitrous'] < .03; $i++) hold($boosted,pad(),1,$car);
$available = $car($boosted)['nitrous'];
$burst = hold($boosted,$boost,12,$car);
expect($available >= .03 && abs($burst[0]['nitrous'] - ($available-1/240))<1e-12 && $burst[1]['nitrous'] < $burst[0]['nitrous']
	&& end($burst)['nitrous'] === 0., 'At 3% a fresh burst starts and runs all the way back to empty');
$beforeBrake = $boosted->view(1.)[0]->fov;
hold($boosted,pad(lt: 1),120,$car);
expect($car($boosted)['speed'] < end($burst)['speed'] && $boosted->view(1.)[0]->fov < $beforeBrake-deg2rad(3),
	'Service braking narrows the view as the car sheds speed');
$boosted->close(); $shift->close();

// Hold known speeds on level ground to measure the chase boom and lens independently of the drivetrain.
function speedChaseCurve(Session $parked, int $hz): array
{
	$lens = new CarChase;
	$eye = new TerrainPhonics\Camera;
	$eye->fov = deg2rad(64);
	$pose = $parked->car->state();
	$land = $parked->landscape;
	$pose['y'] = $land->height($pose['x'],$pose['z']);
	$lens->board($eye,$pose,$land);
	$boomAngle = static fn() => rad2deg(atan2($eye->position[1] - $pose['y'] - .65,
		hypot($eye->position[0]-$pose['x'],$eye->position[2]-$pose['z'])));
	$parkedAngle = $boomAngle();
	$parkedPitch = $eye->pitch;
	$holdSpeed = function(float $mph, int $steps, float $charge = 1.) use (&$pose,$lens,$eye,$land,$hz): float {
		for($i = 0; $i < $steps; $i++)
		{
			$from = $pose;
			$pose['speed'] = $mph*Car::MPS_PER_MPH;
			$pose['nitrous'] = $charge;
			$lens->advance($eye,$from,$pose,$land,1/$hz);
		}
		return rad2deg($lens->fieldOfView($eye) - $eye->fov);
	};
	expect($holdSpeed(0.,1,.5) === 0., 'Fuel use alone at rest leaves the lens at its base angle');
	$rise = $holdSpeed(120.,intdiv($hz,6));
	$earlyDrop = $parkedAngle - $boomAngle();
	$previousEye = $lens->view($eye,$pose,0.)[0]; $middleEye = $lens->view($eye,$pose,.5)[0];
	expect(abs(2*$middleEye->fov-$previousEye->fov-$lens->fieldOfView($eye)) < 1e-9, 'The lens blends between fixed steps');
	expect($earlyDrop > 0. && $earlyDrop < 4.5 && abs(2*$middleEye->position[1]-$previousEye->position[1]-$eye->position[1]) < 1e-9
		&& abs(2*$middleEye->pitch-$previousEye->pitch-$eye->pitch) < 1e-9, 'The lowering camera and its pitch blend between ticks');
	$street = $holdSpeed(30.,4*$hz);
	expect($boomAngle() === $parkedAngle && $eye->pitch === $parkedPitch, 'At street speeds the chase keeps its parked angle');
	$highway = $holdSpeed(60.,4*$hz);
	$highwayDrop = $parkedAngle - $boomAngle();
	$holdSpeed(80.,4*$hz);
	expect($highwayDrop > 0. && $highwayDrop < 2.25 && abs($parkedAngle-$boomAngle()-2.25) < .01,
		'The chase is halfway down at 80 mph');
	$cruise = $holdSpeed(120.,4*$hz);
	expect(abs(rad2deg($parkedPitch) + 10.00) < .01 && abs(rad2deg($eye->pitch) + 5.38) < .01,
		'On level ground the camera looks 10 degrees below the horizon at rest and about 5.4 degrees below it at speed');
	expect(abs($parkedAngle-$boomAngle()-4.5) < .01 && abs(rad2deg($eye->pitch-$parkedPitch)-4.5) < .5,
		'At 120 mph the boom has lowered 4.5 degrees with a flatter view ahead');
	$cruiseHeight = $eye->position[1];
	expect(abs($street-9.90) < .01 && abs($highway-17.34) < .01 && abs($cruise-26.25) < .01,
		'The view opens early at 30 and 60 mph and continues widening at 120 mph');
	expect($rise > .27*$cruise && $rise < .30*$cruise, 'A sudden jump in speed eases into the new angle');
	expect($holdSpeed(120.,1,.1) === $cruise && $holdSpeed(120.,1,0.) === $cruise && $holdSpeed(120.,1) === $cruise,
		'At a constant speed the view holds through burning, emptying, and refilling the bottle');
	$fast = $holdSpeed(Car::TOP_SPEED_MPH,4*$hz);
	expect(abs($fast-30.) < 1e-9 && $holdSpeed(Car::TOP_SPEED_MPH+20,1) === $fast, 'The governor reaches the widest lens and overspeed is capped');
	expect($eye->position[1] === $cruiseHeight, 'The camera holds its low chase angle up to and beyond top speed');
	$fall = $holdSpeed(0.,intdiv($hz,2));
	$returnDrop = $parkedAngle - $boomAngle();
	expect($returnDrop > 0. && $returnDrop < $earlyDrop, 'Braking eases the camera back up');
	expect($fall > .36*$fast && $fall < .38*$fast, 'Even a sudden stop eases the camera back toward its base angle');
	expect($holdSpeed(0.,4*$hz) === 0. && !$lens->advance($eye,$pose,$pose,$land,1/$hz),
		'The lens returns exactly to the original field of view and the parked camera goes idle');
	$reverse = $holdSpeed(-12.,4*$hz);
	expect(abs($reverse-4.28) < .01 && $holdSpeed(12.,1) === $reverse, 'Equal forward and reverse road speeds use the same angle');
	return [$rise,$fall,$earlyDrop,$returnDrop];
}
$curve30 = speedChaseCurve($unassisted,30);
$curve120 = speedChaseCurve($unassisted,120);
foreach($curve30 as $i=>$value) expect(abs($value-$curve120[$i]) < 1e-9,
	'The chase angle and lens ease in and out at the same rate at 30 and 120 steps per second');

$assisted = $park();
foreach([$assisted,$unassisted] as $session) {
	hold($session,pad(rt: 1),150,$car);
	hold($session,pad(x: 1,buttons: Session::PAD_A),40,$car);
}
$skid = abs($car($assisted)['drift']);
hold($assisted,pad(x: 1,rt: 1,buttons: Session::PAD_LB),8,$car);
hold($unassisted,pad(x: 1,rt: 1),8,$car);
expect(abs($car($assisted)['drift']) > 0. && abs($car($assisted)['drift']) < abs($car($unassisted)['drift'])
	&& abs($car($unassisted)['drift']) < $skid, 'Boost helps a sliding car track the steered heading progressively');
$assisted->close(); $unassisted->close();

$feathered = $park(); $released = $park();
hold($feathered, pad(rt: 1), 60, $car);
hold($released, pad(rt: 1), 60, $car);
$feather = hold($feathered, pad(rt: .02), 60, $car);
$coast = hold($released, pad(), 60, $car);
$lost = fn(array $run): float => $run[0]['speed'] - $run[59]['speed'];
expect(abs($lost($feather) - $lost($coast)) < .25 && $lost($coast) > 1,
	'A feathered trigger rolls on almost as freely as a released one');
$feathered->close(); $released->close();
$rolling = $stick->car->state()['speed'];
$braked = hold($stick, pad(lt: 1), 240, $car);
expect($braked[10]['speed'] < $rolling - 1.5 && end($braked)['speed'] < -2, 'The left trigger brakes, then reverses');
$stick->close();

$slide = $park(); $straight = $park(); $grip = $park();
foreach([$slide, $straight, $grip] as $session) hold($session, pad(rt: 1), 150, $car);
$speed = $straight->car->state()['speed'];
$locked = hold($straight, pad(buttons: Session::PAD_A), 30, $car);
$rolled = hold($grip, pad(), 30, $car);
expect($speed > 15 && end($locked)['speed'] < end($rolled)['speed'] - 2.5 && max(array_map('abs', array_column($locked, 'drift'))) === 0.0,
	'The handbrake slows a car running straight without sliding it');
$space = $park();
hold($space, pad(rt: 1), 150, $car);
expect(hold($space, pad(), 30, $car, ['Space'=>true]) === $locked, 'Space is the same handbrake as A');
$space->close(); $grip->close();
$entry = $slide->car->state();
$turning = hold($slide, pad(x: 1, buttons: Session::PAD_A), 40, $car);
$release = end($turning);
$travel = atan2($release['x'] - $turning[38]['x'], $release['z'] - $turning[38]['z']);
expect($release['drift'] < -.3 && abs(atan2(sin($release['yaw'] - $release['drift'] - $travel), cos($release['yaw'] - $release['drift'] - $travel))) < .05,
	'Handbrake and right steer swing the tail out, so the car points right of where it travels');
$recovered = hold($slide, pad(), 90, $car);
$s = end($recovered);
expect($s['drift'] === 0.0 && abs($s['yaw'] - $release['yaw']) < .15 && $s['speed'] > 1,
	'Releasing the handbrake lets the path swing round to where the car points');
$straight->close();

$parked = $slide;
// Brake until the car stops. Holding on would reverse it.
for($i = 0; $parked->car->state()['speed'] > 0 && $i < 600; $i++) hold($parked, pad(lt: 1), 1, $car);
hold($parked, pad(), 120, $car);
$s = $parked->car->state();
expect(abs($s['speed']) < .01, 'The car comes to rest');
$view = hold($parked, pad(lookY: 1), 60, $eye);
[$position, $yaw] = end($view);
$ahead = ($position[0] - $s['x']) * sin($s['yaw']) + ($position[2] - $s['z']) * cos($s['yaw']);
expect($ahead > 5 && abs(atan2(sin($yaw - (2 * M_PI - $s['yaw'])), cos($yaw - (2 * M_PI - $s['yaw'])))) < .01,
	'Pulling the right stick back swings the camera round to look back over a parked car');
$view = hold($parked, pad(), 60, $eye);
[$position] = end($view);
$ahead = ($position[0] - $s['x']) * sin($s['yaw']) + ($position[2] - $s['z']) * cos($s['yaw']);
expect($ahead < -5, 'Releasing the stick brings the camera back behind the car');
$parked->close();

// The jet ski answers the triggers and stick as the car does, and the right stick looks around it.
$boat = function(): Session {
	$session = new Session(['worldSize' => 512, 'waterLevel' => 5, 'heightScale' => 0, 'detailAmplitude' => 0]);
	$session->camera->position = [0,20,-150]; $session->camera->yaw = M_PI;
	input($session, ['actions' => ['craft']]);
	return $session;
};
$craft = fn(Session $session): array => $session->craft->state();
$keys = $boat(); $stick = $boat();
expect(hold($keys, pad(), 120, $craft, ['KeyW'=>true,'KeyA'=>true]) === hold($stick, pad(x: -1, rt: 1), 120, $craft),
	'A full stick and right trigger ride exactly as W and A');
$keys->close();
$s = $stick->craft->state();
$view = hold($stick, pad(lookX: 1), 60, $eye);
[, $yaw] = end($view);
$s = $stick->craft->state();
expect(abs(atan2(sin($yaw - (M_PI - $s['yaw'] + M_PI / 2)), cos($yaw - (M_PI - $s['yaw'] + M_PI / 2)))) < .02,
	'Pushing the right stick right looks to the jet ski\'s right');
$stick->close();

// The terrain's colors, banding and blending are saved settings that the session hands the renderer.
$look = new Session(['worldSize'=>256,'heightScale'=>0,'detailAmplitude'=>0]);
$coastLook = array_replace(TerrainPhonics\TerrainAppearance::DEFAULTS, TerrainPhonics\TerrainAppearance::PRESET_DEFAULTS['coast']);
expect(array_intersect_key($look->settings, TerrainPhonics\TerrainAppearance::DEFAULTS) == $coastLook
	&& $look->terrainAppearance->settings === $coastLook,
	'A new session draws the coast preset\'s terrain appearance');
expect($look->status()['surfaceStyle'] === 'coast', 'Status names the terrain style the Surface tab shows');
$before = $look->terrainAppearance;
input($look, ['values' => ['grassColor' => '#FF0000', 'bandSpacing' => 2]]);
expect($look->settings['grassColor'] === '#ff0000' && $look->settings['bandSpacing'] === 5.0
	&& $look->terrainAppearance !== $before && $look->terrainAppearance->settings['grassColor'] === '#ff0000',
	'Appearance settings reach the renderer as a new appearance, lower-cased and clamped');
$kept = $look->terrainAppearance;
input($look, ['values' => ['grassColor' => '#ff0000']]);
expect($look->terrainAppearance === $kept, 'An unchanged setting keeps the same appearance, so the scene uploads nothing');
input($look, ['values' => ['rockSlopeStart' => .6]]);
expect($look->settings['rockSlopeStart'] === .6 && abs($look->settings['rockSlopeEnd'] - .61) < 1e-12,
	'Raising a blend start past its end moves the end with it');
$held = $look->terrainAppearance;
input($look, ['values' => ['grassColor' => 'red']]);
expect($look->settings['grassColor'] === '#ff0000' && str_contains($look->message, 'grassColor')
	&& $look->terrainAppearance === $held, 'A color that is not six-digit hex is refused and reported');
$file = json_decode($look->save(), true, flags: JSON_THROW_ON_ERROR);
expect($file['settings']['grassColor'] === '#ff0000' && $file['settings']['rockSlopeEnd'] === $look->settings['rockSlopeEnd'],
	'Save files carry the terrain appearance');
$other = new Session(['worldSize'=>256,'heightScale'=>0,'detailAmplitude'=>0]);
$other->load(json_encode($file, JSON_THROW_ON_ERROR));
expect($other->terrainAppearance->settings === $look->terrainAppearance->settings,
	'Loading a file restores its terrain appearance');
$plain = $file;
foreach(TerrainPhonics\TerrainAppearance::DEFAULTS as $name => $value) unset($plain['settings'][$name]);
$other->load(json_encode($plain, JSON_THROW_ON_ERROR));
expect($other->terrainAppearance->settings === $coastLook,
	'A coast file without terrain appearance settings draws the coast preset\'s look');
$generation = $other->generation;
$hostile = $file;
$hostile['settings']['grassColor'] = 'red';
try { $other->load(json_encode($hostile, JSON_THROW_ON_ERROR)); expect(false, 'A file with a bad color must be refused'); }
catch(InvalidArgumentException)
{
	expect($other->generation === $generation && $other->terrainAppearance->settings === $coastLook,
		'A file with a bad terrain color changes nothing');
}
$order = $file;
$order['settings']['rockSlopeStart'] = .7;
$order['settings']['rockSlopeEnd'] = .9;
$other->load(json_encode($order, JSON_THROW_ON_ERROR));
expect($other->settings['rockSlopeStart'] === .7 && $other->settings['rockSlopeEnd'] === .9,
	'A file sets both ends of a blend together, whatever order the session would apply them in');
input($other, ['actions' => [['type' => 'generate', 'preset' => 'mountain', 'seed' => 'look']]]);
expect($other->status()['surfaceStyle'] === 'mountain' && $other->settings['rockSlopeStart'] === .7,
	'Generating keeps the appearance and the status follows the new style');
$look->close(); $other->close();

$materials = new Session(['preset'=>'mountain']);
$natural = $materials->activeLayers();
$before = json_decode($materials->save(),true)['surface'];
input($materials,['actions'=>[['type'=>'surface-visibility','id'=>'relief-ribs','visible'=>false]]]);
$preview = $materials->displayedLayers();
expect($preview !== $natural && $materials->displayedLayers() === $preview && $preview->structure === $natural->structure
	&& $materials->activeLayers() === $natural, 'Hiding a relief layer reuses the shader and caches its preview uniforms.');
expect(json_decode($materials->save(),true)['surface'] === $before, 'A visibility comparison preserves the authored surface and its preset origin.');
input($materials,['actions'=>[['type'=>'surface-visibility','id'=>'relief-ribs','visible'=>true]]]);
expect($materials->displayedLayers() === $natural, 'Showing the last hidden layer restores the original palette.');
expect(array_column($natural->layers,'strength','id')['relief-ribs'] === .5,'Mountain Ridges starts with half-strength rib relief.');
$granite = array_column($natural->layers,null,'id')['granite-slabs'];
expect($granite['pattern'] === 'jointed' && $granite['channel'] === 'color' && $granite['color'] !== $granite['patternColor']
	&& $granite['patternScale'] === $natural->fields['jointWidth']
	&& abs($granite['patternScale']*$granite['patternStretch']-$natural->fields['jointHeight']) < .00001,
	'Fresh and weathered granite share the default joint spacing.');
foreach([['coast',1],['limestone',2],['canyon',1],['canyon',2]] as [$preset,$version]) {
	$recipe = (new TerrainPhonics\Recipe(['preset'=>$preset,'version'=>$version]))->values();
	$otherLayers = TerrainPhonics\SurfaceLayers::landscape($recipe,$materials->terrainAppearance);
	expect(!in_array('jointed',array_column($otherLayers->layers,'pattern'),true),"$preset version $version keeps its own rock texture.");
}
input($materials,['actions'=>[['type'=>'surface-layers','layers'=>$natural->layers]]]);
expect($materials->surfaceLayers===null,'An unchanged Apply keeps the pristine preset.');
input($materials,['actions'=>[['type'=>'surface-edit','id'=>'granite-slabs','key'=>'color','value'=>'#765432'],
	['type'=>'surface-field','key'=>'jointWidth','value'=>19], ['type'=>'surface-ink','ink'=>'roadColor','color'=>'#515151']]]);
$edited=$materials->activeLayers();
input($materials,['actions'=>[['type'=>'generate','preset'=>'limestone']]]);
expect($materials->activeLayers()===$edited,'Authored relief and masks survive a change of landform.');
$file=json_decode($materials->save(),true);
expect($file['version']===5 && $file['surface']['origin']==='authored' && $file['surface']['preset']==='mountain',
	'The save records the material origin independently of the landform.');
$materials->load(json_encode($file));
expect($materials->activeLayers()->layers===$edited->layers && $materials->activeLayers()->fields===$edited->fields
	&& $materials->status()['paintInks']['roadColor']==='#515151','Material masks, shared fields and paint survive a reload.');
$materials->prepareMaterial=static function() { throw new RuntimeException('Test shader failed to link'); };
$prior=$materials->save();
input($materials,['actions'=>[['type'=>'surface-layers','preset'=>'coast']]]);
expect($materials->save()===$prior && $materials->status()['message']==='Test shader failed to link',
	'A link failure keeps the complete previously committed material.');
$materials->prepareMaterial=null;
$file['version']=4;
unset($file['surface']);
$file['landscape']['recipe']['preset']='mountain';
$file['layers']=[['color'=>'#999999']];
$materials->load(json_encode($file));
expect(count($materials->activeLayers()->layers)===1 && $materials->activeLayers()->layers[0]['relief']==='none',
	'An explicit version-four mountain stack remains color-only.');
$file['layers']=null;
$materials->load(json_encode($file));
expect(in_array('relief-ribs',array_column($materials->activeLayers()->layers,'id'),true),
	'A version-four mountain with no custom layers recovers the complete preset.');
$materials->close();

$sky = new Session;
$original = json_decode($sky->save(),true);
unset($original['atmosphereLayers'], $original['atmosphereOrigin']);
$profiles = $optics = [];
foreach(TerrainPhonics\Recipe::PRESETS as $preset) {
	$initial = new Session(['preset'=>$preset]);
	input($sky,['actions'=>[['type'=>'generate','preset'=>$preset]]]);
	$profile = $sky->atmosphere->layers();
	expect($profile === $initial->atmosphere->layers() && $sky->status()['atmosphereOrigin'] === 'preset',
		"Starting and generating $preset apply the same preset atmosphere.");
	$uniforms = $sky->atmosphere->uniforms();
	expect($uniforms['airLayerCount'] > 0, "$preset sends its active atmosphere to the renderer.");
	$optics[] = json_encode([$uniforms['airBand[0]'], $uniforms['airColor[0]']]);
	$profiles[$preset] = $profile;
	$initial->close();
}
expect(count(array_unique($optics)) === count($profiles), 'Every landform has a distinct optical profile.');
$stock = $sky->save();
$foot = TerrainSandbox\DisplayUnits::FOOT;
expect($profiles['canyon'][0]['bottom'] === -2000 * $foot && $profiles['canyon'][0]['top'] === 2000 * $foot,
	'The default canyon dust spans two thousand feet on either side of sea level');
expect($sky->settings['roadWidth'] === 25 * $foot && $sky->settings['roadShoulder'] === 40 * $foot,
	'New roads start with round imperial pavement and shoulder widths');
$rows = [['name'=>'Sea fog','bottom'=>-420.,'top'=>-120.,'color'=>'#a1c3cc'], ['name'=>'Dust','bottom'=>1400.,'top'=>2400.,'enabled'=>false]];
input($sky,['actions'=>[['type'=>'atmosphere-layers','layers'=>$rows,'request'=>11]]]);
$authored = $sky->status()['atmosphereLayers'];
expect(count($authored)===2 && $sky->status()['atmosphereReply']===11 && $authored[1]['enabled']===false,
	'Sky controls retain disabled layers and acknowledge committed edits.');
input($sky,['actions'=>[['type'=>'generate','preset'=>'basalt-fjords']]]);
expect($sky->atmosphere->layers()===$authored,'Generating another landform preserves the authored atmosphere.');
$file = $sky->save();
$sky->load(json_encode($original));
expect($sky->atmosphere->layers()===[],'An earlier save restores clear air over its own landscape.');
input($sky,['actions'=>[['type'=>'generate','preset'=>'arctic-meltwater']]]);
expect($sky->atmosphere->layers()===[], 'An old landscape keeps its clear air until the author chooses preset layers.');
$sky->load($file);
expect($sky->atmosphere->layers()===$authored && $sky->status()['atmosphereOrigin'] === 'authored',
	'Atmosphere layers round-trip with their titles, heights, densities, colors, visibility and authored status.');
$bad = json_decode($file,true);
$bad['atmosphereLayers'][1]['top']=100.;
$beforeFailure = $sky->save();
try { $sky->load(json_encode($bad)); expect(false,'Reject inverted atmosphere bounds'); }
catch(InvalidArgumentException) { expect($sky->save()===$beforeFailure,'A malformed layer leaves the complete live session intact.'); }
$bad = json_decode($file,true);
$bad['atmosphereOrigin'] = 'other';
try { $sky->load(json_encode($bad)); expect(false,'Reject unknown atmosphere origin'); }
catch(InvalidArgumentException) { expect($sky->save()===$beforeFailure,'A bad atmosphere origin leaves the session intact.'); }
unset($bad['atmosphereOrigin']);
$sky->load(json_encode($bad));
input($sky,['actions'=>[['type'=>'generate','preset'=>'mountain']]]);
expect($sky->atmosphere->layers()===$authored,'Earlier saves with custom layers keep them when generating a different landscape.');

$sky->load($stock);
input($sky,['actions'=>[['type'=>'generate','preset'=>'mountain']]]);
expect($sky->atmosphere->layers()===$profiles['mountain'],'A saved preset atmosphere resumes following the landform.');
input($sky,['actions'=>[['type'=>'atmosphere-layers','layers'=>[]]]]);
$sky->load($sky->save());
input($sky,['actions'=>[['type'=>'generate','preset'=>'limestone']]]);
expect($sky->atmosphere->layers()===[], 'A deliberately cleared stack survives saving, loading and changing landform.');
input($sky,['values'=>['haze'=>.42,'elevation'=>17.],'actions'=>[['type'=>'atmosphere-preset','request'=>15]]]);
expect($sky->atmosphere->layers()===$profiles['limestone'] && $sky->status()['atmosphereReply']===15,
	'Restoring preset layers applies the current landform and acknowledges the request.');
input($sky,['actions'=>[['type'=>'atmosphere-layers','layers'=>[['density'=>-1.]]]]]);
expect($sky->status()['atmosphereOrigin']==='preset','An invalid layer edit preserves preset following.');
input($sky,['actions'=>[['type'=>'generate','preset'=>'coast']]]);
expect($sky->atmosphere->layers()===$profiles['coast'] && $sky->settings['haze']===.42 && $sky->settings['elevation']===17.,
	'The next landscape supplies its own layers while preserving manually adjusted sunlight and haze.');
$sky->close();

// A fine lattice kept from a loaded file still generates a 65.5 km world, with tiles large enough for the cache keys.
$fine = new Session(['worldSize' => 512, 'spacing' => .25, 'tileSize' => 4]);
$fine->input(['actions' => [['type' => 'generate', 'preset' => 'mountain', 'seed' => 'first-light', 'worldSize' => 65536]]],
	1 / 60, 16 / 9);
$wideRecipe = $fine->landscape->recipe();
expect($wideRecipe['worldSize'] === 65536.0 && $wideRecipe['spacing'] === .25 && $wideRecipe['tileSize'] === 8,
	'Generating a 65.5 km world from a fine lattice takes larger tiles instead of failing');
$fine->close();

// Exercise road authoring through the same queued actions used by the browser.
$lab = new Session(['worldSize'=>256, 'heightScale'=>0, 'detailAmplitude'=>0]);
$command = static function(string $op, array $fields = []) use ($lab): void {
	input($lab,['actions'=>[['type'=>'road-editor','op'=>$op,'generation'=>$lab->generation,
		'revision'=>$lab->landscape->revision()] + $fields]]);
};
$command('open');
expect($lab->roadEditor->packet($lab)['roads'] === [] && $lab->roadEditor->packet($lab) === null, 'An unchanged map sends no additional road packet');
foreach([[-40,0],[0,-20],[40,0]] as [$x,$z]) $command('add',['x'=>$x,'z'=>$z]);
$command('handle',['index'=>0,'side'=>'out','vector'=>[8,3,12]]);
$command('bank',['index'=>0,'value'=>10]);
$unsplit = $lab->roadEditor->packet($lab)['road'];
$command('insert',['index'=>0,'t'=>.5]);
$split = $lab->roadEditor->packet($lab)['road'];
expect(count($split['points'])===4 && abs($split['banks'][1]-.05)<1e-8,'Splitting interpolates the bank');
$expected = [-40*.125 + (-40+8)*.375 + (-80/6)*.375, $lab->settings['roadRaise']+1.125,
	12*.375 - 20*.375 -20*.125];
foreach($expected as $axis=>$v) expect(abs($split['points'][1][$axis]-$v)<1e-8,'Split lands exactly on the existing cubic');
$position = static function(array $road,int $i,float $t): array {
	$points = $road['points']; $handles = $road['handles'] ?? [];
	$out = TerrainPhonics\Edits::roadHandles($points,$handles,$i)['out'];
	$in = TerrainPhonics\Edits::roadHandles($points,$handles,$i+1)['in'];
	return array_map(static fn($a,$b,$d,$c) => (1-$t)**3*$a+3*$t*(1-$t)**2*($a+$b)
		+3*$t*$t*(1-$t)*($d+$c)+$t**3*$d,$points[$i],$out,$points[$i+1],$in);
};
$error = 0.;
foreach([0,1] as $i) for($j=0; $j<=20; $j++) {
	$t = $j/20; $secondHalf = $t>=.5;
	$before = $position($unsplit,$i,$t);
	$after = $i === 1 ? $position($split,2,$t) : $position($split,(int)$secondHalf,2*$t-(int)$secondHalf);
	foreach($before as $axis=>$value) $error = max($error,abs($value-$after[$axis]));
}
expect($error<1e-10,'Inserting a control preserves both halves of the cubic and its adjoining span in three dimensions');
input($lab,['actions'=>[['type'=>'road-editor','op'=>'apply','id'=>null,'generation'=>$lab->generation,
	'revision'=>$lab->landscape->revision()],['type'=>'load','json'=>'{']]]);
expect($lab->takeCandidate() === null && $lab->roadEditor->packet($lab)['road']['points'] === $split['points'],
	'A rejected transaction keeps the entire working road available to retry');
$command('apply');
$command('close');
publish($lab);
expect($lab->road === [],'Publishing a road while the map is closed clears the shared sketch');
$command('open');
$review = $lab->roadEditor->packet($lab);
expect($review['id'] === 0 && !$review['changed'] && count($review['roads']) === 1,'A new road becomes an editable saved road');
$command('point',['id'=>0,'index'=>1,'x'=>-20,'y'=>9,'z'=>8]);
$command('view',['x'=>20,'z'=>-6,'span'=>44.]);
expect($lab->roadEditor->view === [20.,-6.,44.],'The independent editor camera uses the requested chart span');
$command('apply',['id'=>0]);
publish($lab);
$review = $lab->roadEditor->packet($lab);
expect(count($review['roads']) === 1 && $review['road']['points'][1] === [-20.,9.,8.], 'Editing replaces the selected road once');
input($lab,['actions'=>['undo']]); publish($lab);
expect($lab->roadEditor->packet($lab)['road']['points'][1] === $split['points'][1], 'Undo restores the prior shape in the open editor');
$document = $lab->landscape->save();
$command('visibility',['id'=>0,'visible'=>false]);
$preview = $lab->roadEditor->displayedLandscape($lab->landscape);
expect(!$preview->roads() && $lab->landscape->save() === $document && $lab->roadEditor->packet($lab)['id'] === 0,
	'Hiding the selected road preserves its selection, saved edits and collision surface');
$command('delete',['id'=>0]); publish($lab);
expect(!$lab->landscape->roads() && $lab->roadEditor->packet($lab)['id'] === null, 'Deleting a hidden road clears its editor');
input($lab,['actions'=>['undo']]); publish($lab);
expect($lab->roadEditor->displayedLandscape($lab->landscape) === $lab->landscape && count($lab->landscape->roads()) === 1,
	'Undo restores the removed road as a visible layer');
$command('close');
expect($lab->roadEditor->packet($lab) === null && $lab->roadEditor->view === null, 'Closing the editor releases pending chart work');

$crossing = new Session(['worldSize'=>512,'heightScale'=>0.,'detailAmplitude'=>0.]);
$land = $crossing->landscape;
foreach([[[0,0,-120],[0,0,120]],[[-120,0,0],[120,0,0]]] as $points)
	$land->queue(['type'=>'road','points'=>$points,'width'=>12,'shoulder'=>6]);
$land->queue(['type'=>'material','x'=>0,'z'=>60,'radius'=>18,'material'=>'grass']);
$land->commit();
$course = $crossing->course;
$start = $course->place('start','road',0,0,60,0)['gate']['id'];
$course->place('checkpoint','road',0,0,0,0);
$course->place('checkpoint','road',60,0,0,0);
$course->place('checkpoint','road',60,0,60,0);
$course->place('checkpoint','air',0,180,60,0);
$waterGate = $course->place('checkpoint','water',0,2,-60,0)['gate']['id'];
$before = $course->export();
$editor = $crossing->roadEditor;
$editor->action($crossing,['op'=>'open']);
$toggle = ['op'=>'visibility','id'=>0,'generation'=>$crossing->generation,'revision'=>$land->revision()];
$editor->action($crossing,$toggle+['visible'=>false]);
expect(count($before['gates']) === 6 && $editor->hiddenGates($land,$course,false) === [$start=>true],
	'Only the gate on the hidden road disappears, even under a paint edit. The crossing, aircraft and standalone gates stay visible');
expect($editor->hiddenGates($land,$course,true) === [$start=>true,$waterGate=>true] && $course->export() === $before,
	'A flooded circuit also hides its water gate above the road and retains its complete course');
$course->place('start','road',60,0,95,0);
expect($editor->hiddenGates($land,$course,false) === [], 'Moving the gate off the hidden road refreshes its visibility');
$editor->action($crossing,$toggle+['visible'=>true]);
expect($editor->hiddenGates($land,$course,true) === [], 'Showing the road restores the flooded gate');
$crossing->close();

// Make a lap beyond the former 40 km limit directly from editor points, then edit and reload it.
$manual = new Session(['worldSize'=>32768,'heightScale'=>0.,'detailAmplitude'=>0.]);
input($manual,['values'=>['waterLevel'=>-10.,'gateLaps'=>3,'roadWidth'=>24.]]);
$edit = static function(string $op,array $values=[]) use ($manual) {
	input($manual,['actions'=>[['type'=>'road-editor','op'=>$op,'generation'=>$manual->generation,
		'revision'=>$manual->landscape->revision()]+$values]]);
};
$edit('open');
foreach([[-10000,-6500],[10000,-6500],[10000,6500],[-10000,6500]] as [$x,$z]) $edit('add',['x'=>$x,'z'=>$z]);
$edit('circuit'); while($manual->preparingRoad()) input($manual,[]); publish($manual);
$made = $manual->status()['circuit']; $roads = $manual->landscape->roadRecords();
expect($made['active']==='dry' && $made['length']>60000. && count($manual->course->gates())===11 && $manual->course->laps()===3,'The long hand-drawn circuit gets a start and ten scored checkpoints');
expect($roads[0]['points'][0]===end($roads[0]['points']) && $manual->roadEditor->packet($manual)['id']===0,'The closed road is selected for further editing');
// Measure each inner post against the same painted edge the driver sees, including the closing bend.
$checkRoadGates = static function(Session $session, float $roadWidth): void {
	foreach($session->course->gates() as $gate) {
		expect($gate['width'] >= $roadWidth + 1.49,'The full road fits inside the race opening');
		foreach([-1,1] as $side) {
			$half = $side * $session->course->measure($gate)['window']['halfWidth'];
			$session->landscape->material($gate['x']+$half*cos($gate['yaw']),$gate['z']-$half*sin($gate['yaw']),$offset,$mark);
			expect($mark !== null && abs(abs($mark[0])-$mark[2]-TerrainSandbox\CircuitRoad::POST_CLEARANCE)<.01,
				'Each inner post face clears its local pavement edge');
		}
	}
};
$checkRoadGates($manual,24.);
$legacy = json_decode($manual->save(),true,flags:JSON_THROW_ON_ERROR);
$route = TerrainSandbox\Circuit::import($legacy['circuit']['route'],16384.);
$stock = $manual->gateShapes->kind('road')['size']['default'][0];
$legacy['course'] = $route->course($manual->landscape,$manual->gateShapes,3,false,$stock)->export();
$oldSave = new Session;
$oldSave->load(json_encode($legacy,JSON_THROW_ON_ERROR));
expect($oldSave->course->export()===$manual->course->export(),'A saved stock-width circuit refits the start and checkpoints when loaded');
$legacy['course']['gates'][1]['x'] += 1;
try { $oldSave->load(json_encode($legacy,JSON_THROW_ON_ERROR)); expect(false,'A mismatched historical circuit must be rejected'); }
catch(InvalidArgumentException) { expect($oldSave->course->export()===$manual->course->export(),'A bad historical checkpoint leaves the race intact'); }
$oldSave->close();
$originalCourse = $manual->course->export();
$originalCamera = clone $manual->camera;
$edit('section',['id'=>0,'key'=>'width','value'=>42.]);
$edit('apply',['id'=>0]);
while($manual->preparingRoad()) input($manual,[]);
expect($manual->course->export() === $originalCourse, 'Resized gates wait for their pavement to publish');
publish($manual);
$checkRoadGates($manual,42.);
$wideCourse = $manual->course->export();
expect($manual->status()['circuit']['points'] === $made['points'] && $manual->camera == $originalCamera
	&& $manual->course->laps() === 3, 'Apply road keeps the racing line, camera and lap count');
foreach(['undo'=>$originalCourse,'redo'=>$wideCourse] as $action=>$expected) {
	input($manual,['actions'=>[$action]]); while($manual->preparingRoad()) input($manual,[]); publish($manual);
	expect($manual->course->export() === $expected, "$action restores the matching crossing planes and post positions");
}
$edit('section',['id'=>0,'key'=>'width','value'=>80.]);
$edit('point',['id'=>0,'index'=>1,'x'=>10500,'y'=>3,'z'=>-6500]);
$edit('circuit',['id'=>0]); while($manual->preparingRoad()) input($manual,[]); publish($manual);
expect(count($manual->landscape->roads())===1 && $manual->status()['circuit']['points']!==$made['points'],'Editing a saved circuit road moves the race line without adding another road');
$checkRoadGates($manual,80.);
$manualReload = new Session; $manualReload->load($manual->save());
expect($manualReload->course->export()==$manual->course->export() && $manualReload->landscape->roadRecords()==$manual->landscape->roadRecords(),
	'The manual course, laps and road control points survive a save round trip');
$detached = json_decode($manual->save(),true,flags:JSON_THROW_ON_ERROR);
unset($detached['circuit']);
$manualReload->load(json_encode($detached,JSON_THROW_ON_ERROR));
$checkRoadGates($manualReload,80.);
$editDetached = static function(string $op,array $fields=[]) use ($manualReload) {
	input($manualReload,['actions'=>[['type'=>'road-editor','op'=>$op,'generation'=>$manualReload->generation,
		'revision'=>$manualReload->landscape->revision(),'id'=>0]+$fields]]);
};
$editDetached('open'); $editDetached('select');
$editDetached('section',['key'=>'width','value'=>32.]); $editDetached('apply');
while($manualReload->preparingRoad()) input($manualReload,[]); publish($manualReload);
$checkRoadGates($manualReload,32.);
$manualReload->close();
// A short loop can use the smallest permitted road without exhausting its terrain sampling budget.
$edit('select',['id'=>null]);
foreach([[-90.,-80.],[90.,-80.],[90.,80.],[-90.,80.]] as [$x,$z]) $edit('add',['x'=>$x,'z'=>$z]);
$edit('circuit'); while($manual->preparingRoad()) input($manual,[]); publish($manual);
$shortRoad = array_key_last($manual->landscape->roadRecords());
$edit('section',['id'=>$shortRoad,'key'=>'width','value'=>.5]);
$edit('apply',['id'=>$shortRoad]); while($manual->preparingRoad()) input($manual,[]); publish($manual);
$checkRoadGates($manual,.5);
$narrow = json_decode($manual->save(),true,flags:JSON_THROW_ON_ERROR);
expect(abs(min(array_column($narrow['course']['gates'],'width'))-2.) < .01, 'The narrowest road brings both posts in to its edges');
$narrowLoad = new Session;
$narrowLoad->load($manual->save());
expect($narrowLoad->course->export() === $manual->course->export(), 'Narrow fitted gates survive a save and reload');
$shortRoute = TerrainSandbox\Circuit::import($narrow['circuit']['route'],16384.);
$narrow['course'] = $shortRoute->course($manual->landscape,$manual->gateShapes,3,false,legacyPavementFit:true)->export();
$narrowLoad->load(json_encode($narrow,JSON_THROW_ON_ERROR));
expect($narrowLoad->course->export() === $manual->course->export(), 'Older minimum-width road gates refit when loaded');
$narrowLoad->close();
$manual->close();

$longLab = new Session(['worldSize'=>32768,'heightScale'=>0.,'detailAmplitude'=>0.]);
$longLab->road = [[-11500.,2.,0.],[11500.,2.,0.]];
input($longLab,['actions'=>['road-apply']]);
expect($longLab->preparingRoad() && $longLab->takeCandidate()===null,'A long authored road spans input frames without early publication');
while($longLab->preparingRoad()) input($longLab,[]);
publish($longLab);
expect(count($longLab->landscape->roadRecords())===1 && $longLab->landscape->height(0,0)>1.9,'All long-road chunks publish as one operation');
$longLab->close();
$lab->close();

// Sketch across a ridge and a hollow from Shape and Roads, then pin the grade by hand.
$grading = new Session(['worldSize'=>2048,'spacing'=>2,'heightScale'=>0.,'detailAmplitude'=>0.]);
foreach([['raise',-160.,35.],['lower',90.,22.],['raise',230.,24.]] as [$type,$x,$amount])
	$grading->landscape->queue(['type'=>$type,'x'=>$x,'z'=>0.,'amount'=>$amount,'radius'=>90.+$amount]);
$grading->landscape->commit();
$before = $grading->landscape->fork();
$draw = static function(string $op,array $fields=[]) use ($grading): void {
	input($grading,['actions'=>[['type'=>'road-editor','op'=>$op,'generation'=>$grading->generation,
		'revision'=>$grading->landscape->revision()] + $fields]]);
};
$draw('open');
$grading->camera->position = [-400.,70.,0.]; $grading->camera->pitch = -M_PI/2;
input($grading,['values'=>['tool'=>'road','roadWidth'=>12.,'roadRaise'=>1.5],'clicks'=>[[.5,.5]]]);
finishPicking($grading);
$draw('add',['x'=>420.,'z'=>0.]);
input($grading,['actions'=>['road-apply']]);
while($grading->preparingRoad()) input($grading,[]);
publish($grading);
$road = array_values($grading->landscape->roadRecords())[0];
expect(count($road['points'])>2 && count($road['points'])<=128 && !isset($road['_ground']),
	'Ground fitting bakes ordinary editable knots in one road');
$worst = 0.;
for($x=-400; $x<=420; $x+=2)
	$worst = max($worst,abs($grading->landscape->height($x,0)-$before->height($x,0)-1.5));
expect($worst<3.5 && $grading->landscape->height(-160.,0.)>33. && $grading->landscape->height(90.,0.)<-18.,
	'The surveyed deck follows the ridge and the hollow between two sparse clicks, retaining the road offset');
$copy = new Session; $copy->load($grading->save());
expect($copy->landscape->roadRecords()==$grading->landscape->roadRecords(), 'Reloading keeps the exact fitted curves');
$copy->close();
input($grading,['actions'=>['undo']]); publish($grading);
expect(!$grading->landscape->roads() && $grading->landscape->height(-160.,0.)===$before->height(-160.,0.),
	'Undo restores the original hillside in one step');
$draw('select',['id'=>null]);
foreach([[-400.,0.],[0.,0.],[420.,0.]] as [$x,$z]) $draw('add',compact('x','z'));
$draw('handle',['index'=>0,'side'=>'out','vector'=>[50.,-10.,0.]]);
$draw('bank',['index'=>1,'value'=>10.]);
$draw('apply'); while($grading->preparingRoad()) input($grading,[]); publish($grading);
$road = array_values($grading->landscape->roadRecords())[0];
expect($road['points'][1][0]===0. && $road['handles'][0]['out']===[50.,-10.,0.] && $road['banks'][1]===.1
	&& count($road['points'])>3 && $grading->landscape->height(-160.,0.)<4.,
	'A manual vertical handle keeps that span cut through the ridge while the next span still follows the ground');
input($grading,['actions'=>['undo']]); publish($grading);
$draw('select',['id'=>null]);
foreach([-400.,420.] as $x) $draw('add',['x'=>$x,'z'=>0.]);
$draw('point',['index'=>0,'x'=>-400.,'y'=>-6.,'z'=>0.]);
$draw('apply'); while($grading->preparingRoad()) input($grading,[]); publish($grading);
$road = array_values($grading->landscape->roadRecords())[0];
expect(count($road['points'])===2 && $road['points'][0][1]===-6. && $grading->landscape->height(-160.,0.)<0.,
	'An explicitly lowered point keeps its authored grade');
// A nearly full open road can still be closed and split after fitting.
$points = [[-400.,0.,0.],[420.,0.,0.]];
for($i=1; $i<=123; $i++) $points[] = [420.,0.,3.*$i];
$ground = ['in'=>true,'out'=>true,'offset'=>0.];
$survey = TerrainSandbox\RoadGrading::fit($before,['type'=>'road','points'=>$points,'_ground'=>[$ground,$ground]]);
foreach($survey as $_) {}
$open = $survey->getReturn();
$closed = TerrainSandbox\CircuitRoad::closeLoop($open);
expect(count($open['points'])===126 && count($closed['points'])===127,
	'The fitter spends the available knot on terrain relief and leaves room for closing and splitting');
$grading->close(); $before->close();

// The controls shown follow the input family the page reports, one device at a time.
$hint = new Session(['worldSize'=>256,'heightScale'=>0,'detailAmplitude'=>0]);
expect($hint->inputTheme === 'keyboard' && $hint->message === '' && $hint->status()['hint'] === $hint->hint()
	&& $hint->hint() === ['⬲ Fly', '❹ ❺ Down, up', '❷ Fast', 'Right-drag Look'], 'A new session shows keyboard flight controls');
input($hint, ['values' => ['inputTheme' => 'xbox']]);
expect(array_slice($hint->hint(), 0, 2) === ['⬲ Fly', '▦ Look'] && $hint->status()['inputTheme'] === 'xbox', 'A pad shows its sticks');
input($hint, ['values' => ['inputTheme' => 'touch']]);
expect($hint->hint() === ['Drag to look', 'Tap the ground to use the tool'], 'A touchscreen shows touch controls');
input($hint, ['values' => ['inputTheme' => 'nintendo']]);
expect($hint->message === 'Unknown input theme' && $hint->inputTheme === 'touch', 'An unknown input theme is refused');
$hint->close();
foreach(['fly', 'car', 'craft', 'jet'] as $mode)
foreach(TerrainSandbox\ControlHints::THEMES as $theme)
foreach(['novice', 'expert'] as $handling)
{
	$entries = TerrainSandbox\ControlHints::entries($mode, $theme, $handling, true);
	expect($entries !== [] && !array_filter($entries, static fn(string $e): bool => str_contains($e, ' / ') || str_contains($e, ' or ')),
		"$mode controls on $theme name one device's buttons, with no slash lists");
}
$keys = TerrainSandbox\ControlHints::entries('car', 'keyboard');
$pad = TerrainSandbox\ControlHints::entries('car', 'playstation');
expect(in_array('L Lights', $keys, true) && in_array('⓿ Handbrake', $keys, true) && in_array('❷ Lights', $pad, true)
	&& in_array('❼ Accelerate', $pad, true) && $pad === TerrainSandbox\ControlHints::entries('car', 'xbox'),
	'The car uses L and the X button for lights; every pad shares one list in its own art');
expect(in_array('❸ ❶ Speed', TerrainSandbox\ControlHints::entries('jet', 'xbox', 'expert'), true)
	&& !in_array('↑ Next balloon', TerrainSandbox\ControlHints::entries('jet', 'xbox'), true)
	&& in_array('↑ Next balloon', TerrainSandbox\ControlHints::entries('jet', 'xbox', 'novice', true), true),
	'Expert F5 speed is on Y and B, and balloon cycling shows only when there are balloons');

// The pause menu offers what suits the mode, sends choices through the session's checks, and hands the world back.
$audio = ['master' => 80, 'music' => 40, 'engine' => 100, 'tires' => 100, 'track' => 'Off'];
$pageCommands = [];
$paused = new Session(['worldSize'=>256,'heightScale'=>0,'detailAmplitude'=>0]);
$pauseMenu = new TerrainSandbox\SandboxMenu(
	static function(string $name) use (&$audio): mixed { return $name === 'tracks' ? json_encode(['Off', 'Drive']) : $audio[$name]; },
	static function(string $name, mixed $value) use (&$audio): bool { $audio[$name] = $value; return true; },
	static function(string $command) use (&$pageCommands): void { $pageCommands[] = $command; }
);
input($paused, ['actions' => ['menu']]);
expect($paused->menuRequested && !$paused->menu && !$paused->paused, 'Escape or Start asks for the menu');
$pauseMenu->open($paused);
$rows = array_keys($pauseMenu->menu->rows());
expect($paused->menu && $paused->paused && $paused->status()['menu'] === true && $rows[0] === 'resume'
	&& array_intersect(['car', 'craft', 'jet', 'delivery'], $rows) === ['car', 'craft', 'jet', 'delivery']
	&& !in_array('fly', $rows, true) && !in_array('race', $rows, true) && array_slice($rows, -5) === ['controls', 'audio', 'settings', 'lab', 'quit'],
	'In free flight the menu offers the vehicles and a package run, then controls, audio, settings, the Lab and Quit');
$pauseMenu->menu->press('screenshot');
$photoTime = $paused->time;
$paused->step(.25);
expect($pauseMenu->screenshotView() && $paused->paused && $paused->time === $photoTime
	&& array_keys($pauseMenu->menu->rows()) === ['@back'], 'The screenshot page holds the scene and takes only menu input');
$pauseMenu->menu->back();
expect(!$pauseMenu->screenshotView() && $paused->paused && $pauseMenu->menu->selected === 'screenshot',
	'Leaving the screenshot returns to the same pause-menu row');
$paused->mode = 'jet';
$jetRows = array_keys($pauseMenu->items($paused));
expect(in_array('fly', $jetRows, true) && in_array('balloons', $jetRows, true) && !in_array('car', $jetRows, true)
	&& !in_array('delivery', $jetRows, true), 'In the F5 the menu returns to flight and offers balloon practice');
$paused->mode = 'fly';
$controls = $pauseMenu->controlsPage($paused);
expect($controls[0] === ['heading' => 'Free flight'] && in_array(['name' => 'Fly', 'value' => '⬲'], $controls, true)
	&& in_array(['name' => 'Look', 'value' => 'Right-drag'], $controls, true)
	&& in_array(['name' => 'Pause menu', 'value' => 'Esc'], $controls, true), 'Controls pair each action with its keys');
$paused->inputTheme = 'xbox';
expect(in_array(['name' => 'Down, up', 'value' => '❻ ❼'], $pauseMenu->controlsPage($paused), true)
	&& in_array(['name' => 'Pause menu', 'value' => '❾'], $pauseMenu->controlsPage($paused), true), 'A pad sees its own buttons');
$pageButtons = ['pageUp'=>1, 'pageDown'=>1];
expect($pauseMenu->withPad($pageButtons, [7=>1]) === ['pageUp'=>0, 'pageDown'=>1]
	&& $pauseMenu->withPad($pageButtons, [8=>1]) === ['pageUp'=>1, 'pageDown'=>0]
	&& $pauseMenu->withPad($pageButtons, []) === $pageButtons, 'Track changes consume only the matching menu shoulder');
$paused->inputTheme = 'keyboard';
$pauseMenu->menu->select('settings');
$pauseMenu->menu->activate();
$pauseMenu->menu->setValue('headlights', 'On');
$pauseMenu->menu->setValue('jetControls', 'Expert');
$pauseMenu->menu->setValue('invertPitch', true);
$pauseMenu->menu->setValue('speed', 250);
input($paused, $pauseMenu->takePending());
expect($paused->headlights === 'on' && $paused->jetControls === 'expert' && $paused->invertPitch
	&& abs($paused->settings['speed'] - 250 * TerrainSandbox\DisplayUnits::KNOT) < 1e-9 && $pauseMenu->takePending() === [],
	'Settings reach the session as values, with flight speed set in knots');
$pauseMenu->menu->back();
$pauseMenu->menu->select('audio');
$pauseMenu->menu->activate();
$pauseMenu->menu->setValue('track', 'Drive');
$pauseMenu->menu->setValue('engine', 55);
expect($audio['track'] === 'Drive' && $audio['engine'] == 55 && $pauseMenu->menu->value('music') === 40,
	'Audio rows read and set the page\'s levels and track');
$pauseMenu->menu->back();
$pauseMenu->menu->select('lab');
$pauseMenu->menu->activate();
expect($pageCommands === ['lab'] && $pauseMenu->finish($paused) && !$paused->menu && $paused->paused,
	'The Landscape Lab row opens the editor and leaves the world frozen');
$pauseMenu->open($paused);
expect($pauseMenu->menu->selected === 'resume', 'Opening the menu again starts on Resume');
$pauseMenu->menu->select('car');
$pauseMenu->menu->activate();
expect($pauseMenu->finish($paused) && !$paused->paused && $pauseMenu->takePending() === ['actions' => ['car']],
	'A vehicle row closes the menu and boards through the session');
$pauseMenu->open($paused);
$pauseMenu->menu->select('quit');
$pauseMenu->menu->activate();
expect(array_keys($pauseMenu->menu->rows()) === ['leave', '@back'] && $pauseMenu->menu->selected === 'leave',
	'Quit asks first, with Quit selected and Back below');
$pauseMenu->menu->select('leave');
$pauseMenu->menu->activate();
expect($pageCommands === ['lab', 'quit'], 'Quit leaves for the examples');
$pauseMenu->menu->back();
$pauseMenu->menu->back();
expect($pauseMenu->finish($paused) && !$paused->menu && !$paused->paused, 'Back from the top page resumes');
$paused->close();

echo "Landscape sandbox session: $checks checks passed\n";
