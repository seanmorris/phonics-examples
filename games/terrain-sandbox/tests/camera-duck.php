<?php
declare(strict_types=1);

$package = getenv('PHONICS_TERRAIN_ROOT') ?: dirname(__DIR__, 3) . '/packages/terrain-phonics';
require_once $package . '/autoload.php';
require_once dirname(__DIR__) . '/autoload.php';

use TerrainPhonics\Camera;
use TerrainSandbox\Course;
use TerrainSandbox\GateDuck;
use TerrainSandbox\GateShapes;
use TerrainSandbox\Session;

$checks = 0;
function expect(bool $condition, string $message): void
{
	global $checks;
	$checks++;
	if(!$condition) throw new RuntimeException($message);
}
function input(Session $session, array $value): void { $session->input($value, 1 / 60, 16 / 9); }

/**
 * Measure how far a point stands from the nearest solid part of the listed gates.
 * @param array $point [x, y, z] in metres.
 * @param list<array> $gates Gates from Course::obstructions().
 * @return float Metres to the nearest box surface; negative inside a box, by the depth to its nearest face.
 */
function clearance(array $point, array $gates): float
{
	$nearest = INF;
	foreach($gates as $gate) foreach($gate['solids'] as $box)
	{
		$dx = $point[0] - $box['centre'][0]; $dz = $point[2] - $box['centre'][2];
		$cos = cos($box['yaw']); $sin = sin($box['yaw']);
		$gap = [abs($dx * $cos - $dz * $sin) - $box['halfX'], abs($point[1] - $box['centre'][1]) - $box['halfY'],
			abs($dx * $sin + $dz * $cos) - $box['halfZ']];
		$out = 0.0;
		foreach($gap as $d) if($d > 0) $out += $d * $d;
		$nearest = min($nearest, $out > 0 ? sqrt($out) : max($gap));
	}
	return $nearest;
}
/** Turn a point in a gate's frame (across, up, along the travel) into world space. */
function world(array $gate, float $u, float $y, float $v): array
{
	$cos = cos($gate['yaw']); $sin = sin($gate['yaw']);
	return [$gate['x'] + $u * $cos + $v * $sin, $gate['y'] + $y, $gate['z'] - $u * $sin + $v * $cos];
}
function distance(array $a, array $b): float { return sqrt(($a[0] - $b[0]) ** 2 + ($a[1] - $b[1]) ** 2 + ($a[2] - $b[2]) ** 2); }

/**
 * Run an eye along a path past a gate, as a camera would follow a vehicle, and record what ducking does to it.
 * @param GateDuck $duck Ducking under test. Its held passages carry over from step to step.
 * @param list<array> $gates Gates from Course::obstructions().
 * @param callable $path Maps a step number to [eye, vehicle] positions.
 * @return array Least clearance before and after ducking, the largest step of the ducked eye over the step of the
 * eye, the largest offset, and the passages held along the way.
 */
function follow(GateDuck $duck, array $gates, callable $path, int $steps): array
{
	$raw = $placed = INF; $ratio = 0.0; $offset = 0.0; $passages = []; $previous = null;
	for($i = 0; $i <= $steps; $i++)
	{
		[$eye, $vehicle] = $path($i);
		$duck->step($gates, $eye, $vehicle);
		$moved = $duck->place($gates, $eye, $vehicle);
		$raw = min($raw, clearance($eye, $gates));
		$placed = min($placed, clearance($moved, $gates));
		$offset = max($offset, distance($moved, $eye));
		foreach($duck->held() as $passage) $passages[$passage] = true;
		if($previous !== null && distance($eye, $previous[0]) > 0)
			$ratio = max($ratio, distance($moved, $previous[1]) / distance($eye, $previous[0]));
		$previous = [$eye, $moved];
	}
	return ['raw' => $raw, 'placed' => $placed, 'ratio' => $ratio, 'offset' => $offset, 'passages' => array_keys($passages)];
}

$shapes = GateShapes::packaged();
$c = GateDuck::CLEARANCE;

// The provisional obstructions hold every vertex of the shipped meshes. Nothing stands in the window once it is
// shrunk by the clearance, where the road gate's base plates reach 0.13 m into its lower corners.
foreach(GateShapes::KINDS as $kind)
{
	$course = new Course;
	$course->place('start', $kind, 0.0, 0.0, 0.0, 0.0);
	[$gate] = $course->obstructions($shapes);
	$data = gzdecode(file_get_contents(dirname(__DIR__) . '/assets/gates/' . $shapes->kind($kind)['mesh']));
	$count = unpack('V', $data)[1];
	$outside = $inWindow = 0;
	$window = $gate['opening'];
	for($i = 0; $i < $count; $i++)
	{
		$point = array_values(unpack('g3', $data, 16 + 28 * $i));
		// The air gate's base plates rise 0.024 m above the window's lower edge, where their box stops.
		if(clearance($point, [$gate]) > .03) $outside++;
		if(abs($point[0]) < $window['halfWidth'] - $c && $point[1] > $window['bottom'] + $c
			&& $point[1] < $window['top'] - $c) $inWindow++;
	}
	expect($count > 500 && $outside === 0 && $inWindow === 0 && count($gate['solids']) === ($kind === 'road' ? 5 : 3),
		"The $kind gate's solid boxes hold all $count vertices of its mesh ($outside outside, $inWindow in the window)");
}

// Obstructions follow a gate's pose, list only the gates near a point and float water gates on the waves.
$course = new Course;
$course->place('start', 'road', 100.0, 3.0, -50.0, .6);
$course->place('checkpoint', 'air', -900.0, 70.0, 400.0, -2.0);
[$road, $air] = $course->obstructions($shapes);
$window = $shapes->kind('road')['window'];
$post = $shapes->kind('road')['posts'][1];
expect($road['opening']['bottom'] === 3.0 + $window['bottom'] && $road['opening']['top'] === 3.0 + $window['top']
	&& $road['opening']['yaw'] === .6 && abs($road['opening']['centre'][0] - 100) < 1e-12
	&& abs(distance($road['solids'][2]['centre'], world(['x' => 100.0, 'y' => 3.0, 'z' => -50.0, 'yaw' => .6],
		$post['x'], ($post['bottom'] + $post['top']) / 2, $post['z']))) < 1e-9 && $road['solids'][2]['yaw'] === .6,
	'A road gate lists its parts where its pose puts them, and its window as world heights');
expect(array_column($course->obstructions($shapes, [100.0, -40.0, 20.0]), 'id') === [$road['id']]
	&& $course->obstructions($shapes, [500.0, 500.0, 20.0]) === [],
	'A point lists only the gates within the radius of it');
$lake = new Session(['worldSize' => 512, 'waterLevel' => 5, 'heightScale' => 0, 'detailAmplitude' => 0]);
$lake->course->place('start', 'water', 0.0, 5.0, 0.0, 0.0);
$lake->step(1 / 60);
[$arch] = $lake->course->obstructions($shapes);
$wave = $lake->water->sample(0.0, 0.0, $lake->time)['height'];
expect(abs($arch['opening']['bottom'] - $wave) < 1e-12 && $wave !== 5.0
	&& abs($arch['solids'][0]['centre'][1] - $wave - ($arch['solids'][0]['centre'][1] - $arch['opening']['bottom'])) < 1e-12,
	'A water gate lists its parts at its height on the waves');
$lake->close();

// Eye paths that would cut through each kind's solid parts stay out of them and move smoothly. Each gate stands
// turned off the axes. The vehicle runs the same line one boom ahead of the eye at the window's height.
$kinds = [
	'road' => ['lead' => 10.0, 'boom' => 6.7, 'heights' => [3.4, 3.9, 4.2], 'vehicle' => .5, 'step' => .1],
	'water' => ['lead' => 8.0, 'boom' => 9.0, 'heights' => [3.7, 4.0, 5.5], 'vehicle' => .3, 'step' => .1],
	'air' => ['lead' => 60.0, 'boom' => 24.0, 'heights' => [9.4, 11.0, 12.3], 'vehicle' => 4.4, 'step' => .5],
];
foreach($kinds as $kind => $setup)
{
	$course = new Course;
	$placed = $course->place('start', $kind, 30.0, $kind === 'air' ? 70.0 : 2.0, -20.0, .6)['gate'];
	$gates = $course->obstructions($shapes);
	$shape = $shapes->kind($kind);
	$inner = $shape['window']['halfWidth']; $outer = $shape['extent']['halfWidth'];
	$far = $setup['lead'] + 12;
	$steps = (int)(2 * $far / $setup['step']);
	$along = fn(int $i): float => -$far + $i * $setup['step'];
	foreach($setup['heights'] as $height)
	{
		// Straight through the middle, through the window beside a post or leg, and through the post or leg itself
		// on each side of its middle.
		foreach([0.0, $inner - .2, $inner + .1, ($inner + $outer) / 2 + .05, -($inner + .1)] as $across)
		{
			$run = follow(new GateDuck($setup['lead']), $gates, fn(int $i): array => [
				world($placed, $across, $height, $along($i)),
				world($placed, $across, $setup['vehicle'], $along($i) + $setup['boom'])], $steps);
			expect($run['raw'] < 0 && $run['placed'] >= $c - 1e-9 && $run['ratio'] < 3 && $run['passages'] === ['through'],
				sprintf('A %s gate eye %.2f m up and %.2f m across stays %.3f m clear of solid parts that it would cut %.3f m into, moving at most %.2f times its own step',
					$kind, $height, $across, $run['placed'], -$run['raw'], $run['ratio']));
		}
		// Crossing the gate at 30 degrees, from beside one end to the middle.
		$run = follow(new GateDuck($setup['lead']), $gates, fn(int $i): array => [
			world($placed, tan(M_PI / 6) * $along($i), $height, $along($i)),
			world($placed, tan(M_PI / 6) * ($along($i) + $setup['boom']), $setup['vehicle'], $along($i) + $setup['boom'])],
			$steps);
		expect($run['raw'] < 0 && $run['placed'] >= $c - 1e-9 && $run['ratio'] < 3,
			sprintf('A %s gate eye crossing at 30 degrees %.2f m up stays %.3f m clear, moving at most %.2f times its own step',
				$kind, $height, $run['placed'], $run['ratio']));
	}
	// The eye's lowest point lies in the window, below the top piece by the clearance.
	$lowest = INF;
	$duck = new GateDuck($setup['lead']);
	for($i = 0; $i <= $steps; $i++)
	{
		$eye = world($placed, 0.0, $setup['heights'][0], $along($i));
		$duck->step($gates, $eye, world($placed, 0.0, $setup['vehicle'], $along($i) + $setup['boom']));
		$lowest = min($lowest, $duck->place($gates, $eye, world($placed, 0.0, $setup['vehicle'], $along($i) + $setup['boom']))[1]);
	}
	expect(abs($lowest - ($placed['y'] + $shape['window']['top'] - $c)) < 1e-9,
		"A $kind gate eye ducks to the clearance below the window's top and no further");
	// Beyond the lead, beside the gate's ends and over a vehicle that passes over, the eye does not move.
	$duck = new GateDuck($setup['lead']);
	$still = true;
	foreach([[0.0, $setup['heights'][0], -$far], [0.0, $setup['heights'][0], $far], [$outer + $c + GateDuck::SIDE, 1.0, 0.0],
		[-$outer - 30, $setup['heights'][2], .1]] as [$u, $y, $v])
	{
		$eye = world($placed, $u, $y, $v);
		$duck->step($gates, $eye, world($placed, $u, $setup['vehicle'], $v + $setup['boom']));
		$still = $still && $duck->place($gates, $eye, world($placed, $u, $setup['vehicle'], $v + $setup['boom'])) === $eye;
	}
	$top = $shape['extent']['top'];
	$over = follow(new GateDuck($setup['lead']), $gates, fn(int $i): array => [world($placed, 0.0, $top + 3, $along($i)),
		world($placed, 0.0, $top + 1, $along($i) + $setup['boom'])], $steps);
	expect($still && $over['offset'] === 0.0 && $over['passages'] === ['over'],
		"A $kind gate leaves the eye alone beyond its lead, beyond its ends and over a vehicle that passes over it");
	// A vehicle that skims just over the gate takes the eye over too, even from below the top.
	$skim = follow(new GateDuck($setup['lead']), $gates, fn(int $i): array => [world($placed, 0.0, $top - .3, $along($i)),
		world($placed, 0.0, $top + 1, $along($i) + $setup['boom'])], $steps);
	expect($skim['raw'] < 0 && $skim['placed'] >= $c - 1e-9 && $skim['passages'] === ['over'] && $skim['ratio'] < 3,
		"A $kind gate lifts the eye over its top behind a vehicle that passes over it");
	// An eye passing beside a post or a leg, or through the end of the top piece, goes to the nearer side.
	$beside = follow(new GateDuck($setup['lead']), $gates, fn(int $i): array => [world($placed, $outer - .1, $setup['heights'][0],
		$along($i)), world($placed, $outer + 4, $setup['vehicle'], $along($i) + $setup['boom'])], $steps);
	expect($beside['raw'] < 0 && $beside['placed'] >= $c - 1e-9 && $beside['ratio'] < 3,
		"A $kind gate eye beside its end stays clear behind a vehicle passing beside it");
}

// An eye crossing a post sideways within the slab is held clear. It jumps to the far side halfway across the band
// from the clear opening's edge to the gate's end, which the post tabs and base plates set 0.12 m beyond the post.
$course = new Course;
$course->place('start', 'road', 0.0, 0.0, 0.0, 0.0);
$gates = $course->obstructions($shapes);
$road = $shapes->kind('road');
$band = $road['extent']['halfWidth'] + $c - ($road['window']['halfWidth'] - $c);
$run = follow(new GateDuck(10.0), $gates, fn(int $i): array => [[5.0 + $i * .01, 2.0, 0.0], [0.0, .5, 6.7]], 500);
expect($run['raw'] < 0 && $run['placed'] >= $c - 1e-9 && $run['offset'] > $band / 2 && $run['offset'] < $band / 2 + .15,
	sprintf('An eye sliding sideways through a post stays clear of it, moving at most %.2f m to the nearer side',
		$run['offset']));

// The held passage keeps the eye ducking behind an F5 that climbs above the banner while the eye is still on its way.
$course = new Course;
$air = $course->place('start', 'air', 0.0, 70.0, 0.0, 0.0)['gate'];
$gates = $course->obstructions($shapes);
$climb = follow(new GateDuck(60.0), $gates, function(int $i): array {
	$v = -80 + $i * .5;
	$jet = $v + 24;
	$height = 4.4 + max(0.0, $jet) * .6;
	return [[0.0, 70 + $height + 5 - max(0.0, $jet) * .3, $v], [0.0, 70 + $height, $jet]];
}, 320);
expect($climb['passages'] === ['through'] && $climb['placed'] >= $c - 1e-9 && $climb['ratio'] < 3,
	'An F5 that climbs above the banner after its window keeps the eye ducking through the window');

// A gate placed beside an eye already deep in its blend takes the passage the eye stands in, so the eye stays put.
$duck = new GateDuck(10.0);
$eye = [0.0, 2.0, -3.0];
$duck->step($gates = (function() use ($shapes): array {
	$course = new Course;
	$course->place('start', 'road', 0.0, 0.0, 0.0, 0.0);
	return $course->obstructions($shapes);
})(), $eye, [0.0, 7.0, 3.0]);
expect($duck->held() === [1 => 'through'] && $duck->place($gates, $eye, [0.0, 7.0, 3.0]) === $eye,
	'A gate placed around an eye in its window holds the window, whatever the vehicle\'s height');

// apply() returns the very camera without a gate near and keeps looking at the same point when it moves the eye.
$camera = new Camera;
$camera->position = [0.0, 3.5, -.2]; $camera->yaw = M_PI; $camera->pitch = -.3;
$duck = new GateDuck(10.0);
expect($duck->apply($camera, [0.0, .5, 6.5], []) === $camera
	&& $duck->apply($camera, [0.0, .5, 6.5], (function() use ($shapes): array {
		$course = new Course;
		$course->place('start', 'road', 0.0, 0.0, 500.0, 0.0);
		return $course->obstructions($shapes);
	})()) === $camera, 'Without a gate near, apply() returns the camera itself');
$view = $duck->apply($camera, [0.0, .5, 6.5], $gates);
[$before] = $camera->basis();
[$after] = $view->basis();
$looked = [];
for($i = 0; $i < 3; $i++) $looked[] = $camera->position[$i] + $before[$i] * distance($camera->position, [0.0, .5, 6.5]);
$towards = [];
for($i = 0; $i < 3; $i++) $towards[] = $looked[$i] - $view->position[$i];
$length = sqrt($towards[0] ** 2 + $towards[1] ** 2 + $towards[2] ** 2);
expect($view !== $camera && $camera->position === [0.0, 3.5, -.2] && abs($view->position[1] - (3.073 - $c)) < 1e-9
	&& abs($after[0] * $towards[0] + $after[1] * $towards[1] + $after[2] * $towards[2] - $length) < 1e-9,
	'apply() lowers a copy of the camera and turns it to the point the camera looked at');

/**
 * Drive a session through a course and test every drawn eye.
 * @param Session $session Session with the vehicle boarded.
 * @param array $keys Keys held.
 * @param int $steps Fixed steps to run.
 * @param bool $draw Whether to ask for the drawn view, as the application does, between steps.
 * @return array Vehicle states, the session camera after each step, the least clearance of a drawn eye, the
 * largest step of the drawn eye beyond the step of the session's eye, the largest duck and the steps that ducked.
 */
function race(Session $session, array $keys, int $steps, bool $draw = true): array
{
	global $shapes;
	input($session, ['keys' => $keys]);
	$states = $eyes = [];
	$least = INF; $jump = 0.0; $duck = 0.0; $ducked = 0; $previous = null; $still = 0;
	for($i = 0; $i < $steps; $i++)
	{
		$session->step(1 / 60);
		$states[] = match($session->mode) { 'car' => $session->car->state(), 'craft' => $session->craft->state(),
			'jet' => $session->jet->state() };
		$eyes[] = [$session->camera->position, $session->camera->yaw, $session->camera->pitch];
		if(!$draw) continue;
		$gates = $session->course->obstructions($shapes);
		foreach([.5, 1.0] as $alpha)
		{
			[$view] = $session->view($alpha);
			$least = min($least, clearance($view->position, $gates));
		}
		[$view] = $session->view(1.0);
		if($view === $session->camera) $still++;
		$offset = distance($view->position, $session->camera->position);
		$duck = max($duck, $offset);
		if($offset > 0) $ducked++;
		if($previous !== null)
			$jump = max($jump, distance($view->position, $previous[0]) - distance($session->camera->position, $previous[1]));
		$previous = [$view->position, $session->camera->position];
	}
	return ['states' => $states, 'eyes' => $eyes, 'least' => $least, 'jump' => $jump, 'duck' => $duck,
		'ducked' => $ducked, 'still' => $still];
}

// The car through a road gate. On flat ground the chase eye rides 2.56 m up, 0.5 m under the board, so the gate
// moves it a centimetre at most. Sunk 0.8 m into the ground, as a 2.27 m tall gate would stand, the board would cut
// the eye's path: the drawn eye ducks under it and back, and nothing else changes.
$open = ['worldSize' => 1024, 'heightScale' => 0, 'detailAmplitude' => 0, 'waterLevel' => -5];
$drivers = [];
foreach(['flat' => 0.0, 'sunk' => -.8, 'sunk unseen' => -.8, 'none' => null] as $name => $sink)
{
	$session = new Session($open);
	$session->camera->position = [0, 30, 0]; $session->camera->yaw = M_PI;
	input($session, ['actions' => ['car']]);
	for($i = 0; $session->findingCarSite() && $i < 2000; $i++) input($session, []);
	expect($session->mode === 'car', 'The car boards');
	if($sink !== null)
		$session->course->place('start', 'road', 0.0, $session->landscape->height(0.0, 60.0) + $sink, 60.0, 0.0);
	$drivers[$name] = [$session, race($session, ['KeyW' => true], 420, $name !== 'sunk unseen')];
}
[, $flat] = $drivers['flat'];
expect($flat['duck'] < .02 && $flat['least'] >= $c - 1e-9,
	sprintf('On flat ground the road gate lowers the drawn car eye %.3f m', $flat['duck']));
[$session, $drive] = $drivers['sunk'];
$car = end($drive['states']);
expect($car['z'] > 60 + 6.7 + 10 && $drive['states'] === $drivers['sunk unseen'][1]['states']
	&& $drive['states'] === $drivers['none'][1]['states'] && $drive['eyes'] === $drivers['none'][1]['eyes']
	&& $flat['states'] === $drivers['none'][1]['states'],
	'The car and its chase camera move exactly as they do without the gate or without drawing');
expect($drive['ducked'] > 20 && $drive['duck'] > .5 && $drive['least'] >= $c - 1e-9 && $drive['jump'] < .05
	&& $drive['still'] > 100,
	sprintf('The drawn car eye ducks %.2f m over %d steps, stays %.3f m clear and steps at most %.3f m beyond the chase eye',
		$drive['duck'], $drive['ducked'], $drive['least'], $drive['jump']));
expect($session->status()['duck']['offset'] === [0.0, 0.0, 0.0] && $session->view(1.0)[0] === $session->camera,
	'Past the gate the drawn eye is the chase eye again');
foreach($drivers as [$session]) $session->close();

// The jet ski through a floating arch on the swell.
$riders = [];
foreach(['gate', 'none'] as $name)
{
	$session = new Session(['worldSize' => 512, 'waterLevel' => 5, 'heightScale' => 0, 'detailAmplitude' => 0]);
	$session->camera->position = [0, 20, -60]; $session->camera->yaw = M_PI;
	input($session, ['actions' => ['craft']]);
	expect($session->mode === 'craft', 'The jet ski boards');
	if($name === 'gate') $session->course->place('start', 'water', 0.0, 5.0, 0.0, 0.0);
	$riders[$name] = [$session, race($session, ['KeyW' => true], 600, $name === 'gate')];
}
[$session, $ride] = $riders['gate'];
expect(end($ride['states'])['z'] > 9 + 8 + 2 && $ride['states'] === $riders['none'][1]['states']
	&& $ride['eyes'] === $riders['none'][1]['eyes'],
	'The jet ski and its camera move exactly as they do without the arch');
expect($ride['ducked'] > 20 && $ride['duck'] > .5 && $ride['least'] >= $c - 1e-9 && $ride['jump'] < .1,
	sprintf('The drawn jet ski eye ducks %.2f m under the arch, stays %.3f m clear and steps at most %.3f m beyond its camera',
		$ride['duck'], $ride['least'], $ride['jump']));
foreach($riders as [$session]) $session->close();

// The F5 through an air gate with both chase cameras.
$window = $shapes->kind('air')['window'];
foreach(['novice', 'expert'] as $controls)
{
	$flights = [];
	foreach(['gate', 'none'] as $name)
	{
		$session = new Session(['worldSize' => 8192, 'heightScale' => 0, 'detailAmplitude' => 0]);
		$session->camera->position = [0, 75, -1000]; $session->camera->yaw = M_PI;
		input($session, ['values' => ['jetControls' => $controls], 'actions' => ['jet']]);
		expect($session->mode === 'jet' && $session->jet->expert() === ($controls === 'expert'), "The $controls F5 boards");
		if($name === 'gate')
			$session->course->place('start', 'air', 0.0, 75 - ($window['bottom'] + $window['top']) / 2, 0.0, 0.0);
		$flights[$name] = [$session, race($session, [], 600, $name === 'gate')];
	}
	[$session, $flight] = $flights['gate'];
	expect(end($flight['states'])['z'] > 24 + 60 + 2 && $flight['states'] === $flights['none'][1]['states']
		&& $flight['eyes'] === $flights['none'][1]['eyes'],
		"The $controls F5 and its camera fly exactly as they do without the gate");
	expect($flight['ducked'] > 20 && $flight['least'] >= $c - 1e-9 && $flight['jump'] < 1,
		sprintf('The drawn %s F5 eye ducks %.2f m through the banner\'s window, stays %.3f m clear and steps at most %.3f m beyond its camera',
			$controls, $flight['duck'], $flight['least'], $flight['jump']));
	foreach($flights as [$session]) $session->close();
}

// A gate far from the drive changes nothing the application draws.
$session = new Session($open);
$session->camera->position = [0, 30, 0]; $session->camera->yaw = M_PI;
input($session, ['actions' => ['car']]);
for($i = 0; $session->findingCarSite() && $i < 2000; $i++) input($session, []);
$session->course->place('start', 'road', 300.0, $session->landscape->height(300.0, 60.0), 60.0, 0.0);
$far = race($session, ['KeyW' => true], 240);
expect($far['ducked'] === 0 && $far['still'] === 240 && $session->status()['duck']['passages'] === [],
	'A gate 300 m away leaves the drawn eye as the chase camera placed it');
$session->close();

echo "camera duck checks passed: $checks\n";
