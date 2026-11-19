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

input($session, ['values' => ['exposure' => 2,'haze' => .5,'elevation' => 8]]);
$saved = $session->save();
$beforeHeight = $session->landscape->height(1, 1);
$session->load($saved);
expect($session->generation === 1, 'Load replaces the landscape once');
expect(abs($session->landscape->height(1, 1) - $beforeHeight) < 1e-9, 'Save/load preserves terrain edits');
expect($session->settings['exposure'] === 2.0 && $session->settings['elevation'] === 8.0, 'Save/load preserves environment');
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
$custom = $bay->save();
$bay->load(json_encode($legacy, JSON_THROW_ON_ERROR));
expect($bay->water->appearance->settings === TerrainPhonics\WaterAppearance::DEFAULTS, 'Older landscapes get the default water appearance');
$bay->load($custom);
expect($bay->water->appearance->settings === $optics, 'All optical settings round-trip');
$generation = $bay->generation;
try { $bay->load(str_replace('#316fc0', '#zzzzzz', $custom)); expect(false, 'Invalid water colors must be rejected'); }
catch(InvalidArgumentException) { expect($bay->generation === $generation && $bay->save() === $custom, 'Invalid optics leave the current scene intact'); }
$bay->close();

echo "Landscape sandbox session: $checks checks passed\n";
