<?php
declare(strict_types=1);

// Gates of any size: the size ranges, the resized meshes as model.vert draws them, windows and posts that follow the
// size, the fit to sloping ground, start / finish merging, overlap refusal, the out-of-reach report, obstructions for
// the cameras, and sizes in saved files.

$package = getenv('PHONICS_TERRAIN_ROOT') ?: dirname(__DIR__, 3) . '/packages/terrain-phonics';
require_once $package . '/autoload.php';
require_once dirname(__DIR__) . '/autoload.php';
require __DIR__ . '/support/gate-mesh.php';

use TerrainSandbox\Car;
use TerrainSandbox\Course;
use TerrainSandbox\GateModel;
use TerrainSandbox\GateShapes;
use TerrainSandbox\Race;
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
/** Click the gate tool straight down onto a point, with the camera heading along $heading. */
function click(Session $session, string $role, float $x, float $z, float $heading = 0.0, array $values = []): void
{
	$session->camera->position = [$x, 60, $z];
	$session->camera->pitch = -M_PI / 2 + .001;
	$session->camera->yaw = M_PI - $heading;
	input($session, ['values' => ['tool' => 'gate', 'gateRole' => $role] + $values + ['gateAltitude' => 0,
		'gateWidth' => 0, 'gateHeight' => 0], 'clicks' => [[.5, .5]]]);
	finishPicking($session);
}
function near(float $a, float $b, float $tolerance = 1e-6): bool { return abs($a - $b) <= $tolerance; }

$shapes = GateShapes::packaged();
$road = $shapes->kind('road'); $water = $shapes->kind('water'); $air = $shapes->kind('air');
$folder = dirname(__DIR__) . '/assets/gates/';
$model = new GateModel($shapes, $folder);

// Sizes. Each kind has its own size and a range, and the water arch keeps within a stretch of its own shape.
expect($road['size']['default'] === [2 * $road['window']['halfWidth'], $road['window']['top']]
	&& $road['size']['width'][1] >= 60 && $road['size']['height'][1] >= 40 && $water['size']['width'][1] >= 60
	&& $water['size']['height'][1] >= 40 && $air['size']['width'][1] >= 300 && $air['size']['height'][1] >= 150,
	'Road and water gates reach 60 m wide and 40 m tall, air gates 300 m and 150 m');
$fit = $shapes->fit('road', null, null);
expect($fit === ['width' => 2 * $road['window']['halfWidth'], 'height' => $road['window']['top'], 'limits' => []],
	'No size asks for the kind\'s own');
$fit = $shapes->fit('road', 80, 1);
expect($fit['width'] === 60.0 && $fit['height'] === 2.0
	&& $fit['limits'] === ['a road gate is at most 60 m wide', 'a road gate is at least 2 m tall'],
	'A size beyond the range takes the nearest the range allows, and says which limit held it');
$fit = $shapes->fit('water', 60, 4);
expect(near($fit['height'], 60 / (2 * $water['window']['halfWidth']) * $water['window']['top'] / 2)
	&& $fit['limits'] === ['a water arch 60 m wide is at least 12.1 m tall'],
	'A water arch 60 m wide grows at least half as tall as it widens, so it stretches at most twice its shape');
$fit = $shapes->fit('water', 4.5, 40);
expect(near($fit['height'], 4.5 / (2 * $water['window']['halfWidth']) * $water['window']['top'] * 2),
	'A narrow water arch grows at most twice as tall as it widens');
expect($shapes->fits('air', 300, 150) && !$shapes->fits('air', 301, 150) && !$shapes->fits('road', 13, 41),
	'fits() accepts sizes in range and nothing beyond');
try { (new Course)->place('checkpoint', 'road', 0, 0, 0, 0, 1, 61.0, 3.0); expect(false, 'A road gate 61 m wide must be refused'); }
catch(InvalidArgumentException) { expect(true, 'Course::place() refuses a size outside the kind\'s range'); }

// The shader. These lines of model.vert are the ones tests/support/gate-mesh.php repeats.
$vertex = file_get_contents(dirname(__DIR__) . '/assets/vehicles/model.vert');
foreach(['float shown = clamp(floor(width / column + .5), 1.0, uGateColumns.y);',
	'return side * (label + min(along, shown * column) * width / (shown * column));',
	'if(!square && along > band - .001) return x + side * uGateSize.x;',
	'bool gate = uGateSize.z > 0.0 && tag >= 128 && tag < 224;',
	'vec3 scale = vec3(uGateSize.z, position.y > 0.0 ? uGateSize.w : 1.0, sqrt(uGateSize.z * uGateSize.w));',
	'position.y = position.y > 0.0 ? position.y + uGateSize.y : (side < 0.0 ? uGateFeet.x : uGateFeet.y);',
	'position.y += side < 0.0 ? uGateFeet.z : uGateFeet.w;',
	'vSurface = gate ? vec2(aSurface.x, (tag & 64) != 0 ? 1.0 : 0.0) : aSurface;'] as $line)
	expect(str_contains($vertex, $line), "model.vert still reads: $line");

/**
 * Measure a resized gate mesh.
 * @return array Each part's bounds, the label quads' corners, and the widths of the checker squares shown.
 */
function measureMesh(array $mesh, array $uniforms): array
{
	$parts = [];
	$labels = [];
	foreach($mesh['vertices'] as $vertex)
	{
		$at = resizeGateVertex($vertex['position'], $vertex['tag'], $uniforms);
		$part = $vertex['tag'] & 63;
		$parts[$part]['low'] = array_map('min', $parts[$part]['low'] ?? $at, $at);
		$parts[$part]['high'] = array_map('max', $parts[$part]['high'] ?? $at, $at);
		if($part === GATE_LABEL) $labels[] = $at;
	}
	$squares = [];
	for($k = 0; $k < count($mesh['corners']); $k += 3)
	{
		$vertices = array_map(fn(int $i): array => $mesh['vertices'][$i], array_slice($mesh['corners'], $k, 3));
		if(($vertices[0]['tag'] & 63) !== GATE_SQUARE) continue;
		$triangle = array_map(fn(array $v): array => resizeGateVertex($v['position'], $v['tag'], $uniforms), $vertices);
		if(triangleArea($triangle) < 1e-9) continue;
		$xs = array_column($triangle, 0);
		$squares[] = max($xs) - min($xs);
	}
	return ['parts' => $parts, 'labels' => $labels, 'squares' => $squares];
}

$meshes = [];
foreach(['road', 'water', 'air'] as $kind) $meshes[$kind] = readGateMesh($folder . $shapes->kind($kind)['mesh']);
// Each size a gate takes, as a course on level ground measures it, against the mesh model.vert draws for it.
// Each list starts at the kind's own size, which the others compare with.
$sizes = ['road' => [[13.324, 3.073], [6, 2], [30, 12], [60, 40]], 'air' => [[39.972, 8.813], [20, 5], [100, 30], [300, 150]],
	'water' => [[8.916, 3.596], [4.5, 2], [20, 8], [60, 40]]];
$ownLabel = $ownOverhang = [];
foreach($sizes as $kind => $list) foreach($list as [$width, $height])
{
	$course = new Course;
	$gate = $course->place('start', $kind, 0, 0, 0, 0, 1, (float)$width, (float)$height)['gate'];
	$measure = $course->measure($gate);
	$uniforms = $model->uniforms($course, $gate);
	$mesh = $meshes[$kind];
	$found = measureMesh($mesh, $uniforms);
	$triangles = resizeGateMesh($mesh, $uniforms);
	$name = "A $width by $height m $kind gate";
	['window' => $window, 'extent' => $extent] = $measure;
	expect(near($window['halfWidth'], $width / 2) && near($window['top'], $height),
		"$name measures its window at its size");
	// Nothing of the resized mesh stands in the window, above its lowest 15 centimetres.
	$box = [$window['halfWidth'] - .01, ($window['top'] - .15) / 2 - .005, $extent['halfDepth'] + 1];
	$blocked = count(array_filter($triangles, fn(array $t): bool => triangleMeetsBox($t, [0, .15 + $box[1], 0], $box)));
	expect($blocked === 0, "$name keeps its window clear ($blocked triangles in it)");
	$low = [INF, INF, INF]; $high = [-INF, -INF, -INF];
	foreach($triangles as $t) foreach($t as $p) { $low = array_map('min', $low, $p); $high = array_map('max', $high, $p); }
	// gates.json rounds the extent to millimetres, which an arch's scale multiplies.
	$slack = .001 * max(1, ...array_slice($uniforms['uGateSize'], 2));
	expect(near(max(-$low[0], $high[0]), $extent['halfWidth'], $slack) && near($high[1], $extent['top'], $slack)
		&& near(min(0, $low[1]), min(0, $extent['bottom']), $slack), "$name stays inside its measured extent: "
		. json_encode([$low, $high, $extent]));
	if($kind === 'water')
	{
		[, , $across, $up] = $uniforms['uGateSize'];
		expect(max($across / $up, $up / $across) <= 2 + 1e-9, "$name stretches its arch and lettering at most twice");
		continue;
	}
	// The label keeps its size and rises with the board, which sits on top of the opening.
	$labelLow = $found['parts'][GATE_LABEL]['low']; $labelHigh = $found['parts'][GATE_LABEL]['high'];
	$label = [$labelHigh[0] - $labelLow[0], $labelHigh[1] - $labelLow[1], $labelLow[1] - $height];
	$ownLabel[$kind] ??= $label;
	expect(near($label[0], $ownLabel[$kind][0]) && near($label[1], $ownLabel[$kind][1]) && near($label[2], $ownLabel[$kind][2]),
		"$name keeps its label's size and height above the opening");
	// The board reaches past the opening's sides by as much as at its own size.
	$frame = $found['parts'][GATE_FRAME];
	$overhang = max(-$frame['low'][0], $frame['high'][0]) - $width / 2;
	$ownOverhang[$kind] ??= $overhang;
	expect(near($frame['low'][1], $height, .001) && near($overhang, $ownOverhang[$kind], 1e-4),
		"$name sits its board on top of the opening, spanning its width");
	// Whole checker columns fill each band, each within a sixth of its own width.
	$column = $shapes->kind($kind)['board']['column'][1];
	expect($found['squares'] !== [] && min($found['squares']) > $column * 5 / 6 - 1e-6 && max($found['squares']) < $column * 7 / 6 + 1e-6,
		sprintf("$name shows whole checker columns %.3f to %.3f m wide, for %.3f m at its own size",
			min($found['squares']), max($found['squares']), $column));
	if($kind === 'road')
	{
		$posts = $found['parts'][GATE_POST];
		expect(near($posts['high'][0] - 2 * $road['posts'][1]['halfSize'], $width / 2, 1e-4)
			&& near($posts['high'][1], $height + $road['posts'][1]['top'] - $road['window']['top'], .001)
			&& near($posts['low'][1], -Course::POST_FOOT, 1e-4),
			"$name stands its posts beside the opening, up to the board and a metre into the ground");
		$tabs = $found['parts'][GATE_CAP];
		expect(near($tabs['low'][1] - $height, $road['posts'][1]['top'] - $road['window']['top'], .001),
			"$name caps its posts with their tabs");
	}
}

// Windows and posts follow the size. A crossing counts anywhere in a 60 by 40 m road gate's window, and nowhere beyond.
$course = new Course;
$wide = $course->place('start', 'road', 0, 0, 100, 0, 1, 60.0, 40.0)['gate'];
$window = $course->measure($wide)['window'];
expect(Race::crossing($wide, $window, [29.5, 39.5, 99], [29.5, 39.5, 101]) !== null
	&& Race::crossing($wide, $window, [30.5, 1, 99], [30.5, 1, 101]) === null
	&& Race::crossing($wide, $window, [0, 40.5, 99], [0, 40.5, 101]) === null,
	'A 60 by 40 m road gate counts crossings across its whole window and none beside or above it');
$posts = $course->posts();
expect(count($posts) === 2 && near($posts[0][0], -30 - $road['posts'][0]['halfSize']) && near($posts[1][0], 30 + $road['posts'][1]['halfSize'])
	&& near($posts[0][6], 40 + $road['posts'][0]['top'] - $road['window']['top']) && near($posts[0][5], -Course::POST_FOOT),
	'Its posts stand beside the 60 m window and reach above 40 m');
$air300 = (new Course)->place('start', 'air', 0, 500, 0, 0, 1, 300.0, 150.0)['gate'];
expect(near((new Course)->measure($air300)['window']['halfWidth'], 150) && near((new Course)->measure($air300)['window']['top'], 150),
	'A 300 by 150 m air gate measures its window at that size');

// The car collides with the posts of a gate of any size. A 40 m gate stands across the car's line with one post in
// its path; the car stops at that post and drives on through the middle of the window.
$plainCar = ['worldSize' => 2048, 'heightScale' => 0, 'detailAmplitude' => 0, 'waterLevel' => -5];
$wall = new Session($plainCar);
$gate = $wall->course->place('checkpoint', 'road', -20 - $road['posts'][0]['halfSize'], 0, 400, 0, 1, 40.0, 12.0,
	fn(float $x, float $z): float => $wall->landscape->height($x, $z))['gate'];
$posts = $wall->course->posts();
$post = $posts[1];
expect(near($post[0], 0, 1e-9) && near($post[6], 12 + $road['posts'][1]['top'] - $road['window']['top'], 1e-9),
	'The 40 m gate\'s +X post stands on the car\'s line, as tall as the opening');
$wall->camera->position = [0, 30, 300]; $wall->camera->yaw = M_PI;
input($wall, ['actions' => ['car']]);
for($i = 0; $wall->findingCarSite() && $i < 2000; $i++) input($wall, []);
$overlap = 0.0;
input($wall, ['keys' => ['KeyW' => true]]);
for($i = 0; $i < 900; $i++)
{
	$wall->step(1 / 60);
	$car = $wall->car->state();
	$overlap = max($overlap, Car::penetration($car['x'], $car['z'], $car['yaw'], $post)[0] ?? 0.0);
}
$stopped = $wall->car->state();
expect($wall->mode === 'car' && $stopped['z'] < 400 && $stopped['z'] > 390 && $overlap < 1e-3,
	sprintf('The car stops at a post of a 40 m gate without passing into it (at z %.2f)', $stopped['z']));
$wall->close();

// The F5 keeps 8 m above the ground, so a road gate at its own size is out of reach. The status says so, with the
// height of opening it would need; a road gate 12 m tall lets the F5 through and starts the race.
$plain = ['worldSize' => 8192, 'heightScale' => 0, 'detailAmplitude' => 0];
$flight = new Session($plain);
click($flight, 'start', 0, 1500);
$flight->camera->position = [0, 75, 0]; $flight->camera->yaw = M_PI; $flight->camera->pitch = 0.0;
input($flight, ['actions' => ['jet']]);
$reach = $flight->status()['race']['reach'];
$level = max(0.0, $flight->water->level());
$needed = ceil($level + Race::JET_FLOOR + Race::JET_ROOM);
expect($reach !== null && $reach['needed'] === $needed && $reach['gate'] === $flight->course->start()['id']
	&& $reach['message'] === "The start gate is out of reach for the F5, which keeps 8 m above the ground and the water. It needs an opening at least $needed m tall.",
	'A road gate under the F5\'s floor is out of reach, and the status gives the height it needs: ' . json_encode($reach));
input($flight, ['actions' => ['fly']]);
click($flight, 'start', 0, 1500, 0, ['gateHeight' => 12]);
$flight->camera->position = [0, 75, 0]; $flight->camera->yaw = M_PI; $flight->camera->pitch = 0.0;
input($flight, ['actions' => ['jet']]);
expect($flight->status()['race']['reach'] === null && $flight->course->start()['height'] === 12.0,
	'A road gate 12 m tall is within the F5\'s reach');
// Dive to the floor and level out short of the gate.
input($flight, ['keys' => ['KeyQ' => true]]);
for($i = 0; $i < 600 && $flight->jet->state()['y'] > $level + Race::JET_FLOOR + 1e-6; $i++) $flight->step(1 / 60);
input($flight, ['keys' => []]);
for($i = 0; $i < 240; $i++) $flight->step(1 / 60);
$low = $flight->jet->state();
expect($low['z'] < 1500 && near($low['y'], $level + Race::JET_FLOOR, 1e-6) && abs($low['pitch']) < .01
	&& $flight->race->state === 'idle', sprintf('The F5 flies level on its floor, %.0f m short of the gate', 1500 - $low['z']));
for($i = 0; $i < 900 && $flight->race->state === 'idle'; $i++) $flight->step(1 / 60);
expect($flight->race->state === 'running' && $flight->status()['race']['vehicle'] === 'jet',
	'The F5 flies through a 12 m road gate on its floor and starts the race');
$flight->close();

// The other vehicles: the car cannot reach a floating gate or one hanging above the ground, and the jet ski cannot
// reach a gate on land.
$shore = ['worldSize' => 1024, 'heightScale' => 0, 'detailAmplitude' => 0, 'waterLevel' => -5];
$reacher = new Session($shore);
$course = $reacher->course;
$ground = fn(float $x, float $z): float => $reacher->landscape->height($x, $z);
$roadGate = $course->place('start', 'road', 0, 0, 0, 0, 1, null, null, $ground)['gate'];
$floating = $course->place('checkpoint', 'water', 0, -5, 100, 0)['gate'];
$hanging = $course->place('checkpoint', 'air', 0, 20, 200, 0, 1, null, null, $ground)['gate'];
$lowAir = $course->place('checkpoint', 'air', 0, .3, 300, 0, 1, null, null, $ground)['gate'];
$race = new Race;
$waterLevel = $reacher->water->level();
expect($race->reach($course, $roadGate, 'car', $waterLevel) === null && $race->reach($course, $lowAir, 'car', $waterLevel) === null,
	'The car reaches a road gate and an air gate hanging within half a metre of the ground');
expect($race->reach($course, $floating, 'car', $waterLevel)['message'] === 'Checkpoint 1 floats on the water, out of reach for the car.'
	&& $race->reach($course, $hanging, 'car', $waterLevel)['message'] === 'Checkpoint 2 hangs 20 m above the ground, out of reach for the car.',
	'The car cannot reach a floating gate or one hanging 20 m up');
expect($race->reach($course, $floating, 'craft', $waterLevel) === null
	&& $race->reach($course, $roadGate, 'craft', $waterLevel)['message'] === 'The start gate stands over land, out of reach for the jet ski.',
	'The jet ski reaches a floating gate but not one on land');
expect($race->reach($course, $hanging, 'jet', $waterLevel) === null && $race->reach($course, $floating, 'jet', $waterLevel)['needed'] === 9.0,
	'An air gate 20 m up is within the F5\'s reach, and a water arch 3.6 m tall needs 9 m');
$reacher->close();

// A floating gate rises and falls with the waves, but what the F5 can reach of it does not: on the roughest water
// the sandbox makes, an arch 3.6 m tall needs 9 m at every moment, and one 9.5 m tall stays within reach.
$waves = new Session(['worldSize' => 2048, 'heightScale' => 0, 'detailAmplitude' => 0, 'waterLevel' => 5]);
input($waves, ['values' => ['waterChoppiness' => 300]]);
$level = $waves->water->level();
$short = $waves->course->place('start', 'water', 0, $level, 400, 0)['gate'];
$tall = $waves->course->place('checkpoint', 'water', 0, $level, 600, 0, 1, 12.0, 9.5)['gate'];
$race = new Race;
$needs = $reached = [];
for($i = 0; $i < 600; $i++)
{
	$waves->water->update($i / 60);
	$waves->course->float($waves->water, $i / 60);
	$needs[] = $race->reach($waves->course, $waves->course->gate($short['id']), 'jet', $level)['needed'] ?? null;
	$reached[] = $race->reach($waves->course, $waves->course->gate($tall['id']), 'jet', $level);
}
$heights = [];
for($i = 0; $i < 600; $i += 30)
{
	$waves->course->float($waves->water, $i / 60);
	$heights[] = $waves->course->height($short);
}
expect(max($heights) - min($heights) > 1 && array_unique($needs) === [9.0] && array_filter($reached) === [],
	'The F5\'s reach for a floating gate holds steady on the waves: '
		. json_encode([array_count_values(array_map('strval', $needs)), count(array_filter($reached))]));
$waves->close();

// An air gate hanging just above the ground or the water is within reach of the car and the jet ski, and they pass
// through it: the car's body rises above its position on the ground, and the jet ski rides the swell.
foreach([['car', .3, -5], ['craft', 1.0, 5], ['craft', 2.5, 5]] as [$vehicle, $altitude, $waterLevel])
{
	$low = new Session(['worldSize' => 2048, 'heightScale' => 0, 'detailAmplitude' => 0, 'waterLevel' => $waterLevel]);
	if($vehicle === 'craft') input($low, ['values' => ['waterChoppiness' => 0]]);
	$low->camera->position = [0, 30, 380]; $low->camera->yaw = 0; $low->camera->pitch = -.3;
	input($low, ['actions' => [$vehicle]]);
	for($i = 0; $low->findingCarSite() && $i < 2000; $i++) input($low, []);
	$ride = $vehicle === 'car' ? $low->car->state() : $low->craft->state();
	$surface = max($low->landscape->height($ride['x'], $ride['z'] - 60), $low->water->level());
	$low->course->place('start', 'air', $ride['x'], $surface + $altitude, $ride['z'] - 60, M_PI, 1, null, null,
		fn(float $x, float $z): float => $low->landscape->height($x, $z));
	$reach = $low->status()['race']['reach'];
	input($low, ['keys' => ['KeyW' => true]]);
	for($i = 0; $i < 900 && $low->race->state === 'idle'; $i++) $low->step(1 / 60);
	$passed = ($vehicle === 'car' ? $low->car->state() : $low->craft->state())['z'] < $ride['z'] - 60;
	expect($low->mode === $vehicle && $reach === null && $passed && $low->race->state === 'running',
		"The $vehicle passes through an air gate hanging $altitude m above the "
		. ($vehicle === 'car' ? 'ground' : 'calm water') . ' and starts the race: '
		. json_encode([$low->mode, $reach, $passed, $low->race->state]));
	$low->close();
}

// The gate tool sizes the opening: 0 takes the kind's own size, and a size beyond the kind's range takes the nearest
// the range allows, which the message names.
$flat = ['worldSize' => 1024, 'heightScale' => 0, 'detailAmplitude' => 0, 'waterLevel' => -5];
$edit = new Session($flat);
input($edit, ['values' => ['gateWidth' => 500, 'gateHeight' => -3]]);
expect($edit->settings['gateWidth'] === 300.0 && $edit->settings['gateHeight'] === 0.0,
	'The size settings run from 0 to the widest and tallest any kind takes');
click($edit, 'checkpoint', 0, 0, 0, ['gateWidth' => 30, 'gateHeight' => 12]);
$sized = $edit->course->gates()[0];
expect($sized['width'] === 30.0 && $sized['height'] === 12.0
	&& $edit->message === 'Placed checkpoint 1 at 0, 0 m, its opening 30 by 12 m.'
	&& $edit->status()['course']['gates'][0]['width'] === 30.0, 'A click places a gate of the size set');
click($edit, 'checkpoint', 0, 100, 0, ['gateWidth' => 90]);
expect($edit->course->gates()[1]['width'] === 60.0
	&& $edit->message === 'Placed checkpoint 2 at 0, 100 m, its opening 60 by 3.1 m. A road gate is at most 60 m wide.',
	'A size beyond the range is held to it, and the message says so');
click($edit, 'checkpoint', 0, 200, 0, ['gateWidth' => 250, 'gateHeight' => 100, 'gateAltitude' => 50]);
expect($edit->course->gates()[2]['kind'] === 'air' && $edit->course->gates()[2]['width'] === 250.0
	&& $edit->course->gates()[2]['height'] === 100.0, 'An air gate takes sizes up to 300 by 150 m');
$sizes = $edit->status()['gateSizes'];
expect($sizes['road']['widths'] === [6.0, 60.0] && $sizes['air']['heights'] === [5.0, 150.0] && $sizes['water']['stretch'] === 2.0
	&& near($sizes['road']['width'], 13.324), 'The status gives each kind\'s own size and its ranges');
$edit->close();

// Start / finish. A finish gate within one gate width of the start gate makes the start gate the start / finish gate.
$course = new Course;
$start = $course->place('start', 'road', 0, 0, 0, 0, 3)['gate'];
$merged = $course->place('finish', 'road', 2, 0, -10, 0);
expect($merged['merged'] && $merged['gate']['id'] === $start['id'] && $course->finish() === null && $course->count() === 1
	&& $course->print($course->start()) === 'start-finish' && $course->laps() === 3,
	'A finish gate placed 10 m behind the start gate merges into it, and the course runs the start gate\'s laps');
$finish = $course->place('finish', 'road', 0, 0, 200, 0)['gate'];
$revision = $course->revision;
$merged = $course->place('finish', 'road', -5, 0, 5, M_PI);
expect($merged['merged'] && $merged['removed']['id'] === $finish['id'] && $course->finish() === null
	&& $course->revision > $revision, 'A finish gate placed level with the start gate removes the finish gate that stood elsewhere');
$apart = $course->place('finish', 'road', 0, 0, 13.4, 0);
expect(!$apart['merged'] && $course->finish() !== null && $course->laps() === 1,
	'A finish gate more than one gate width from the start gate stands on its own, and the course runs once');
$moved = $course->place('start', 'road', 0, 0, 20, 0);
expect($moved['merged'] && $moved['moved'] && $course->finish() === null && $course->start()['z'] === 20.0,
	'Moving the start gate within one gate width of the finish gate merges them too');
$wideStart = new Course;
$wideStart->place('start', 'road', 0, 0, 0, 0, 1, 60.0, 4.0);
expect($wideStart->place('finish', 'road', 50, 0, 20, 0)['merged'], 'One gate width is the start gate\'s own width');

// Overlap. A gate whose span meets another's is refused, and the course stays as it was.
$course = new Course;
$start = $course->place('start', 'road', 0, 0, 0, 0)['gate'];
$revision = $course->revision;
$onTop = $course->place('checkpoint', 'road', 1, 0, .5, 0);
$across = $course->place('checkpoint', 'road', 5, 0, 0, M_PI / 2);
expect($onTop['refused'] === 'overlap' && $onTop['with']['id'] === $start['id'] && $across['refused'] === 'overlap'
	&& $course->count() === 1 && $course->revision === $revision,
	'A checkpoint on top of the start gate, or across it, is refused');
expect($course->place('checkpoint', 'road', 0, 0, 2, 0)['gate'] !== null, 'A checkpoint 2 m behind the start gate stands');
expect($course->place('checkpoint', 'air', 0, 20, 0, 0)['gate'] !== null, 'An air gate above the start gate\'s board stands');
expect($course->place('checkpoint', 'air', 0, 3, 0, 0)['refused'] === 'overlap', 'An air gate through its board does not');
expect($course->place('start', 'road', 1, 0, -.5, 0)['gate'] !== null, 'The start gate moves over its own old place');
$edit = new Session($flat);
click($edit, 'start', 0, 0);
click($edit, 'checkpoint', 0, 0, M_PI / 2);
expect($edit->course->count() === 1 && $edit->message === 'Checkpoint 1 would overlap the start gate. Place it clear of that gate.',
	'The gate tool says which gate a refused gate would overlap');
click($edit, 'finish', 0, -8);
expect($edit->course->count() === 1 && $edit->message === 'The finish is within one gate width of the start gate, so the start gate is now the start / finish gate.',
	'The gate tool says when a finish gate merges into the start gate');
$edit->close();

// Slopes. On ground rising 1 m in 5 across the gate, a road gate stands on the higher side, so its board stays clear;
// each post reaches below the ground under it, and the window reaches down to the lower side.
$rise = static fn(float $x, float $z): float => .2 * $x;
$course = new Course;
$sloped = $course->place('start', 'road', 0, 0, 0, 0, 1, null, null, $rise)['gate'];
$half = $road['window']['halfWidth'] + 2 * $road['posts'][1]['halfSize'];
$measure = $course->measure($sloped);
expect(near($sloped['y'], .2 * $half) && near($sloped['ground'][0], -.4 * $half) && near($sloped['ground'][1], 0.0)
	&& near($measure['feet'][2], .2 * $road['posts'][0]['x'] - $sloped['y']) && near($measure['feet'][3], .2 * $road['posts'][1]['x'] - $sloped['y'])
	&& near($measure['feet'][0], $measure['feet'][2] - Course::POST_FOOT),
	'A road gate on a cross slope stands on its higher side, and each post reaches below the ground under it');
expect(near($measure['window']['bottom'], -.4 * $half - Course::ROAD_BELOW)
	&& Race::crossing($sloped, $measure['window'], [-6, .2 * -6 + .05, -1], [-6, .2 * -6 + .05, 1]) !== null,
	'A car on the low side of the slope passes through the window');
$turned = $course->place('checkpoint', 'road', 0, 0, 100, M_PI / 2, 1, null, null, $rise)['gate'];
// Along its travel only the posts' footprints, 0.8 m deep, meet the slope.
expect(near($turned['y'], .2 * $road['posts'][1]['halfSize']) && near($turned['ground'][0], -.4 * $road['posts'][1]['halfSize']),
	'A gate facing up the slope stands level across it');
// On a landscape, the gate tool fits a road gate to the ground, and the drawn posts reach the ground under them.
$ramp = new Session(['worldSize' => 512, 'spacing' => 1, 'heightScale' => 0, 'detailAmplitude' => 0, 'waterLevel' => -50]);
$ramp->landscape->queue(['type' => 'road', 'points' => [[-60, 0, 0], [60, 12, 0]], 'width' => 60, 'shoulder' => 20,
	'crown' => 0, 'material' => 'road']);
$ramp->landscape->commit();
click($ramp, 'start', 0, 0);
$gate = $ramp->course->start();
$heights = [];
for($x = -$half; $x <= $half; $x += .25) $heights[] = $ramp->landscape->height($x, 0);
$uniforms = $model->uniforms($ramp->course, $gate);
expect($ramp->landscape->height(7, 0) - $ramp->landscape->height(-7, 0) > 1 && near($gate['y'], max($heights), .05)
	&& near($gate['y'] + $uniforms['uGateFeet'][2], $ramp->landscape->height($road['posts'][0]['x'], 0), 1e-6)
	&& near($gate['y'] + $uniforms['uGateFeet'][3], $ramp->landscape->height($road['posts'][1]['x'], 0), 1e-6),
	'On a graded ramp the gate stands on the higher ground and model.vert sets each base plate on the ground under it');
$ramp->close();

// Saved files keep each gate's size. A file from before sizes loads with each kind's own size, reads its old
// gateHeight setting as the air gate altitude, and stands its road gates on the higher ground across their span.
$file = new Session($flat);
click($file, 'start', 0, 0, 0, ['gateWidth' => 24, 'gateHeight' => 9]);
click($file, 'checkpoint', 0, 100, 0, ['gateWidth' => 120, 'gateHeight' => 60, 'gateAltitude' => 40]);
input($file, ['values' => ['gateWidth' => 24, 'gateHeight' => 9]]);
$saved = json_decode($file->save(), true, flags: JSON_THROW_ON_ERROR);
expect($saved['version'] === 3 && $saved['course']['gates'][0]['width'] == 24 && $saved['course']['gates'][1]['height'] == 60
	&& $saved['settings']['gateWidth'] == 24, 'A saved file holds each gate\'s size and the size settings');
$copy = new Session($flat);
$copy->load(json_encode($saved));
expect($copy->course->export() === $file->course->export() && $copy->course->gates()[1]['width'] === 120.0,
	'Loading the file brings the sizes back');
$old = $saved;
$old['version'] = 2;
foreach($old['course']['gates'] as $index => $gate) unset($old['course']['gates'][$index]['width'], $old['course']['gates'][$index]['height']);
unset($old['settings']['gateAltitude'], $old['settings']['gateWidth']);
$old['settings']['gateHeight'] = 40;
$older = new Session($flat);
$older->load(json_encode($old));
expect($older->course->gates()[0]['width'] === 2 * $road['window']['halfWidth'] && $older->course->gates()[1]['height'] === $air['window']['top']
	&& $older->settings['gateAltitude'] === 40.0 && $older->settings['gateHeight'] === 0.0,
	'A version 2 file loads its gates at their kinds\' own sizes and its gate height as the altitude');
$hill = new Session(['worldSize' => 512, 'spacing' => 1, 'heightScale' => 0, 'detailAmplitude' => 0, 'waterLevel' => -50]);
$hill->landscape->queue(['type' => 'road', 'points' => [[-60, 0, 0], [60, 12, 0]], 'width' => 60, 'shoulder' => 20,
	'crown' => 0, 'material' => 'road']);
$hill->landscape->commit();
$old['landscape'] = json_decode($hill->landscape->save(), true, flags: JSON_THROW_ON_ERROR);
$old['course']['gates'] = [['role' => 'start', 'kind' => 'road', 'x' => 0, 'y' => $hill->landscape->height(0, 0), 'z' => 0,
	'yaw' => 0, 'laps' => 1]];
$hill->load(json_encode($old));
expect($hill->course->start()['y'] > $hill->landscape->height(0, 0) + .5,
	'A road gate from an older file stands on the higher ground across its span');
$file->close(); $copy->close(); $older->close(); $hill->close();

// Obstructions for the cameras: every solid part as a box, and the opening, at each gate's size and fit.
$course = new Course;
$roadGate = $course->place('start', 'road', 10, 0, 20, M_PI / 2, 1, 30.0, 12.0, $rise)['gate'];
$airGate = $course->place('checkpoint', 'air', 0, 100, 400, 0, 1, 100.0, 30.0, $rise)['gate'];
$arch = $course->place('checkpoint', 'water', -300, 2, 0, 0, 1, 20.0, 8.0)['gate'];
$found = array_column($course->obstructions(), null, 'id');
$parts = $found[$roadGate['id']]['solids'];
expect(count($found) === 3 && count($parts) === 5 && count($found[$airGate['id']]['solids']) === 1
	&& count($found[$arch['id']]['solids']) === 3,
	'A road gate has five boxes (posts, base plates and board), an air gate one and a water arch three');
$posts = $course->posts();
expect(near($parts[0]['centre'][0], $posts[0][0]) && near($parts[0]['centre'][2], $posts[0][1])
	&& near($parts[0]['centre'][1] - $parts[0]['halfY'], $posts[0][5]) && near($parts[0]['centre'][1] + $parts[0]['halfY'], $posts[0][6])
	&& near($parts[1]['centre'][1] - $parts[1]['halfY'], $posts[1][5]) && $parts[0]['yaw'] === M_PI / 2
	&& near($parts[0]['halfX'], $road['posts'][0]['halfSize']), 'Each post\'s box is the post the car collides with');
$plate = $parts[3];
expect(near($plate['centre'][1] - $plate['halfY'], $roadGate['y'] + $roadGate['posts'][1])
	&& near($plate['halfX'], $road['extent']['halfDepth']),
	'Each base plate\'s box lies on the ground under its post');
$board = $parts[4];
expect(near($board['centre'][1] - $board['halfY'], $roadGate['y'] + 12) && near($board['halfX'], 15 + $road['extent']['halfWidth'] - $road['window']['halfWidth'])
	&& near($board['centre'][1] + $board['halfY'], $roadGate['y'] + 12 + $road['extent']['top'] - $road['window']['top']),
	'The board\'s box sits on top of the 12 m opening and spans the 30 m gate');
$opening = $found[$roadGate['id']]['opening'];
expect($opening['centre'] === [$roadGate['x'], $roadGate['y'], $roadGate['z']] && $opening['halfWidth'] === 15.0
	&& near($opening['top'], $roadGate['y'] + 12) && near($opening['bottom'], $roadGate['y'] + $roadGate['ground'][0]),
	'The road gate\'s opening reaches from the lowest ground across it to the board');
$legs = $found[$arch['id']]['solids'];
$scale = 20 / (2 * $water['window']['halfWidth']);
expect(near($legs[0]['centre'][0], -300 - $scale * ($water['window']['halfWidth'] + $water['extent']['halfWidth']) / 2)
	&& near($legs[2]['halfX'], $scale * $water['extent']['halfWidth']) && near($found[$arch['id']]['opening']['top'], 2 + 8)
	&& near($found[$arch['id']]['opening']['bottom'], 2), 'The arch\'s legs and crossbar scale with it');
expect(array_column($course->obstructions([0, 100, 420], 80), 'id') === [$airGate['id']],
	'Asked for the gates near a point, it lists those within reach');
echo "Landscape sandbox gate sizes: $checks checks passed\n";
