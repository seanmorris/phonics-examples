<?php
declare(strict_types=1);

$package = getenv('PHONICS_TERRAIN_ROOT') ?: dirname(__DIR__, 3) . '/packages/terrain-phonics';
require_once $package . '/autoload.php';
require_once dirname(__DIR__) . '/autoload.php';
require __DIR__ . '/support/gate-mesh.php';

use TerrainPhonics\Camera;
use TerrainSandbox\Course;
use TerrainSandbox\GateDuck;
use TerrainSandbox\GateModel;
use TerrainSandbox\GateShapes;
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

// The obstructions hold every vertex of each gate's mesh as model.vert resizes it: at the kind's own size, its
// smallest, its largest and one between, and for a road gate also across a slope. Nothing stands in the window once
// it is shrunk by the clearance, where the road gate's base plates reach 0.13 m into its lower corners.
$model = new GateModel($shapes, dirname(__DIR__) . '/assets/gates/');
$slope = static fn(float $x, float $z): float => 10 + .3 * $x - .1 * $z;
$sizes = ['road' => [null, [6.0, 2.0], [30.0, 12.0], [60.0, 40.0]], 'water' => [null, [4.5, 2.0], [20.0, 8.0], [60.0, 40.0]],
	'air' => [null, [20.0, 5.0], [100.0, 30.0], [300.0, 150.0]]];
foreach($sizes as $kind => $list) foreach($list as $size) foreach($kind === 'road' ? [null, $slope] : [null] as $ground)
{
	$course = new Course;
	$placed = $course->place('start', $kind, 40.0, 10.0, -30.0, .6, 1, $size[0] ?? null, $size[1] ?? null, $ground)['gate'];
	[$gate] = $course->obstructions();
	$uniforms = $model->uniforms($course, $placed);
	$window = $gate['opening'];
	// gates.json rounds the extent to millimetres, which an arch's scale multiplies.
	$slack = .001 * max(1, ...array_slice($uniforms['uGateSize'], 2));
	$count = $outside = $inWindow = 0;
	foreach(resizeGateMesh(readGateMesh(dirname(__DIR__) . '/assets/gates/' . $shapes->kind($kind)['mesh']), $uniforms)
		as $triangle)
	{
		foreach($triangle as [$u, $y, $v])
		{
			$count++;
			$point = world($placed, $u, $y, $v);
			if(clearance($point, [$gate]) > $slack) $outside++;
			if(abs($u) < $window['halfWidth'] - $c && $point[1] > $window['bottom'] + $c && $point[1] < $window['top'] - $c)
				$inWindow++;
		}
	}
	$name = sprintf('The %s %s gate%s', $size === null ? 'own size' : "{$size[0]} by {$size[1]} m", $kind,
		$ground === null ? '' : ' across a slope');
	expect($count > 500 && $outside === 0 && $inWindow === 0
		&& count($gate['solids']) === ['road' => 5, 'water' => 3, 'air' => 1][$kind] && $gate['kind'] === $kind,
		"$name holds all $count corners of its drawn mesh in its solid boxes ($outside outside, $inWindow in the window)");
}

// Obstructions follow a gate's pose, list only the gates near a point and float water gates on the waves.
$course = new Course;
$course->place('start', 'road', 100.0, 3.0, -50.0, .6);
$course->place('checkpoint', 'air', -900.0, 70.0, 400.0, -2.0);
[$road, $air] = $course->obstructions();
$window = $shapes->kind('road')['window'];
$post = $shapes->kind('road')['posts'][1];
$foot = $course->measure($course->start())['posts'][1]['bottom'];
expect($road['opening']['bottom'] === 3.0 + $window['bottom'] && $road['opening']['top'] === 3.0 + $window['top']
	&& $road['opening']['yaw'] === .6 && abs($road['opening']['centre'][0] - 100) < 1e-12
	&& abs(distance($road['solids'][1]['centre'], world(['x' => 100.0, 'y' => 3.0, 'z' => -50.0, 'yaw' => .6],
		$post['x'], ($foot + $post['top']) / 2, $post['z']))) < 1e-9 && $road['solids'][1]['yaw'] === .6,
	'A road gate lists its parts where its pose puts them, and its window as world heights');
expect(array_column($course->obstructions([100.0, 0.0, -40.0], 20.0), 'id') === [$road['id']]
	&& $course->obstructions([500.0, 0.0, 500.0], 20.0) === [],
	'A point lists only the gates within the radius of it');
$lake = new Session(['worldSize' => 512, 'waterLevel' => 5, 'heightScale' => 0, 'detailAmplitude' => 0]);
$lake->course->place('start', 'water', 0.0, 5.0, 0.0, 0.0);
$lake->step(1 / 60);
[$arch] = $lake->course->obstructions();
$wave = $lake->water->sample(0.0, 0.0, $lake->time)['height'];
expect(abs($arch['opening']['bottom'] - $wave) < 1e-12 && $wave !== 5.0
	&& abs($arch['solids'][0]['centre'][1] - $wave - ($arch['solids'][0]['centre'][1] - $arch['opening']['bottom'])) < 1e-12,
	'A water gate lists its parts at its height on the waves');
$lake->close();

// Eye paths that would cut through each gate's solid parts stay out of them and move smoothly, at each kind's own size
// and at another size, the road gate's across a slope. Each gate stands turned off the axes. The eye runs at heights
// above the window's top; the vehicle runs the same line one boom ahead of the eye in the window.
$road = ['kind' => 'road', 'lead' => 10.0, 'boom' => 6.7, 'heights' => [.33, .83, 1.13], 'vehicle' => .5, 'step' => .1];
$water = ['kind' => 'water', 'lead' => 8.0, 'boom' => 9.0, 'heights' => [.1, .4, 1.9], 'vehicle' => .3, 'step' => .1];
$air = ['kind' => 'air', 'lead' => 60.0, 'boom' => 24.0, 'heights' => [.59, 2.19, 3.49], 'vehicle' => 4.4, 'step' => .5];
$setups = ['road gate' => $road, 'water gate' => $water, 'air gate' => $air,
	'24 by 6 m road gate across a slope' => ['size' => [24.0, 6.0], 'ground' => $slope] + $road,
	'16 by 6 m water gate' => ['size' => [16.0, 6.0]] + $water, '120 by 30 m air gate' => ['size' => [120.0, 30.0], 'vehicle' => 15.0] + $air];
foreach($setups as $name => $setup)
{
	$course = new Course;
	$placed = $course->place('start', $setup['kind'], 30.0, $setup['kind'] === 'air' ? 70.0 : 2.0, -20.0, .6, 1,
		...($setup['size'] ?? [null, null]), ground: $setup['ground'] ?? null)['gate'];
	$gates = $course->obstructions();
	$measure = $course->measure($placed);
	$inner = $measure['window']['halfWidth']; $outer = $measure['extent']['halfWidth'];
	$setup['heights'] = array_map(fn(float $height): float => $measure['window']['top'] + $height, $setup['heights']);
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
				sprintf('The %s: an eye %.2f m up and %.2f m across stays %.3f m clear of solid parts that it would cut %.3f m into, moving at most %.2f times its own step',
					$name, $height, $across, $run['placed'], -$run['raw'], $run['ratio']));
		}
		// Crossing the gate at 30 degrees, from beside one end to the middle.
		$run = follow(new GateDuck($setup['lead']), $gates, fn(int $i): array => [
			world($placed, tan(M_PI / 6) * $along($i), $height, $along($i)),
			world($placed, tan(M_PI / 6) * ($along($i) + $setup['boom']), $setup['vehicle'], $along($i) + $setup['boom'])],
			$steps);
		expect($run['raw'] < 0 && $run['placed'] >= $c - 1e-9 && $run['ratio'] < 3,
			sprintf('The %s: an eye crossing at 30 degrees %.2f m up stays %.3f m clear, moving at most %.2f times its own step',
				$name, $height, $run['placed'], $run['ratio']));
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
	expect(abs($lowest - ($placed['y'] + $measure['window']['top'] - $c)) < 1e-9,
		"The $name: an eye ducks to the clearance below the window's top and no further");
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
	$top = $measure['extent']['top'];
	$over = follow(new GateDuck($setup['lead']), $gates, fn(int $i): array => [world($placed, 0.0, $top + 3, $along($i)),
		world($placed, 0.0, $top + 1, $along($i) + $setup['boom'])], $steps);
	expect($still && $over['offset'] === 0.0 && $over['passages'] === ['over'],
		"The $name leaves the eye alone beyond its lead, beyond its ends and over a vehicle that passes over it");
	// A vehicle that skims just over the gate takes the eye over too, even from below the top.
	$skim = follow(new GateDuck($setup['lead']), $gates, fn(int $i): array => [world($placed, 0.0, $top - .3, $along($i)),
		world($placed, 0.0, $top + 1, $along($i) + $setup['boom'])], $steps);
	expect($skim['raw'] < 0 && $skim['placed'] >= $c - 1e-9 && $skim['passages'] === ['over'] && $skim['ratio'] < 3,
		"The $name lifts the eye over its top behind a vehicle that passes over it");
	// An eye passing beside a post or a leg, or through the end of the top piece, goes to the nearer side.
	$beside = follow(new GateDuck($setup['lead']), $gates, fn(int $i): array => [world($placed, $outer - .1, $setup['heights'][0],
		$along($i)), world($placed, $outer + 4, $setup['vehicle'], $along($i) + $setup['boom'])], $steps);
	expect($beside['raw'] < 0 && $beside['placed'] >= $c - 1e-9 && $beside['ratio'] < 3,
		"The $name: an eye beside its end stays clear behind a vehicle passing beside it");
}

// An eye that swings sideways into a post within the slab, as the look stick swings it, keeps to the opening's side
// of the post while it is less than half way across the post's band, from the clear opening's edge to the post's far
// side and its clearance, and moves no farther than the eye does at any step.
$course = new Course;
$course->place('start', 'road', 0.0, 0.0, 0.0, 0.0);
$gates = $course->obstructions();
$road = $shapes->kind('road');
// The base plates reach 0.14 m into the window's lower corners, which narrows the clear opening at every height.
$edge = min($road['window']['halfWidth'], $road['extent']['halfWidth'] - 2 * $road['extent']['halfDepth']) - $c;
$band = $road['extent']['halfWidth'] + $c - $edge;
$swing = follow(new GateDuck(10.0), $gates, fn(int $i): array => [[5.0 + ($edge + .45 * $band - 5) * sin(M_PI * $i / 300),
	2.0, 0.0], [0.0, .5, 6.7]], 300);
expect($swing['raw'] < 0 && $swing['placed'] >= $c - 1e-9 && $swing['ratio'] <= 1 + 1e-9
	&& abs($swing['offset'] - .45 * $band) < 1e-3 && $swing['passages'] === ['through'],
	sprintf('An eye swinging less than half way into a post and back stays %.3f m clear on the opening\'s side, moving at most %.2f times its own step',
		$swing['placed'], $swing['ratio']));
// Farther in, the eye goes round the nearer face of the slab and comes back in beyond the post, without a jump. A jump
// across the band, 2.06 m, would be 70 times the eye's largest step in the swing and 200 times its step in the slide.
$deep = follow(new GateDuck(10.0), $gates, fn(int $i): array => [[5.0 + 2.9 * sin(M_PI * $i / 300), 2.0, -.3],
	[0.0, .5, 6.7]], 300);
$run = follow(new GateDuck(10.0), $gates, fn(int $i): array => [[5.0 + $i * .01, 2.0, .2], [0.0, .5, 6.7]], 500);
expect($deep['raw'] < 0 && $deep['placed'] >= $c - 1e-9 && $deep['ratio'] < 10 && $run['raw'] < 0
	&& $run['placed'] >= $c - 1e-9 && $run['ratio'] < 10,
	sprintf('An eye swinging or sliding through a post goes round it %.3f m clear, moving at most %.1f times its own step',
		min($deep['placed'], $run['placed']), max($deep['ratio'], $run['ratio'])));

// An eye that comes within the lead at the banner's height and then climbs steeply before it reaches the gate, as
// an Expert F5's eye does when the jet loops up in front of a gate, is let go: it never moves more than 2 m and
// passes over the gate. Without the release it would be pulled down to the window from 95 m above it.
$course = new Course;
$air = $course->place('start', 'air', 0.0, 70.0, 0.0, 0.0)['gate'];
$gates = $course->obstructions();
$window = $shapes->kind('air')['window'];
$banner = 70 + ($window['top'] + $shapes->kind('air')['extent']['top']) / 2;
$loop = follow(new GateDuck(60.0), $gates, function(int $i) use ($banner, $window): array {
	$v = -62 + $i * .25;
	$eye = $banner + .05 * max(0.0, $v + 45) ** 2;
	return [[0.0, $eye, $v], [0.0, $v < -45 ? 70 + $window['top'] / 2 : $eye + 10, $v + 24]];
}, 242);
expect($loop['raw'] > 25 && $loop['placed'] >= $c - 1e-9 && $loop['offset'] < 2 && $loop['ratio'] < 3
	&& in_array('over', $loop['passages'], true),
	sprintf('An eye that climbs away in front of an air gate moves at most %.2f m and passes over it', $loop['offset']));
// Nothing stands under an air gate's window, so an eye below it stays where it is.
$under = follow(new GateDuck(60.0), $gates, fn(int $i): array => [[0.0, 68.5, -70 + $i * .5],
	[0.0, 70 + $window['top'] / 2, -46 + $i * .5]], 280);
expect($under['offset'] === 0.0 && $under['passages'] === ['through'],
	sprintf('An eye 1.5 m below an air gate\'s window passes under it unmoved (moved %.2f m)', $under['offset']));

// The held passage keeps the eye ducking behind an F5 that climbs above the banner while the eye is still on its way.
$course = new Course;
$air = $course->place('start', 'air', 0.0, 70.0, 0.0, 0.0)['gate'];
$gates = $course->obstructions();
$climb = follow(new GateDuck(60.0), $gates, function(int $i): array {
	$v = -80 + $i * .5;
	$jet = $v + 24;
	$height = 4.4 + max(0.0, $jet) * .6;
	return [[0.0, 70 + $height + 5 - max(0.0, $jet) * .3, $v], [0.0, 70 + $height, $jet]];
}, 320);
expect($climb['passages'] === ['through','over'] && $climb['placed'] >= $c - 1e-9 && $climb['ratio'] < 3,
	'A climbing F5 keeps the eye in the window at the slab, then releases it above the banner');

// A gate placed beside an eye already deep in its blend takes the passage the eye stands in, so the eye stays put.
$duck = new GateDuck(10.0);
$eye = [0.0, 2.0, -3.0];
$duck->step($gates = (function() use ($shapes): array {
	$course = new Course;
	$course->place('start', 'road', 0.0, 0.0, 0.0, 0.0);
	return $course->obstructions();
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
		return $course->obstructions();
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

// Descend from a raised road toward a short gantry. The gate's own base cannot bound ground behind the car.
$graded = new Session(['worldSize'=>256, 'heightScale'=>0, 'detailAmplitude'=>0, 'waterLevel'=>-20]);
$graded->landscape->queue(['type'=>'road', 'points'=>[[0,24,-24],[0,0,0],[0,-12,24]], 'width'=>36, 'shoulder'=>2, 'crown'=>.1]);
$graded->landscape->commit();
$ground = $graded->landscape->height(...);
$graded->course->place('start','road',0,0,0,0,1,36,2,$ground);
$gates = $graded->course->obstructions();
$raw = new GateDuck(10);
$bounded = new GateDuck(10, $ground);
$before = $after = $solid = INF;
for($i = 0; $i <= 100; $i++)
{
	$z = -8 + $i*.1;
	$camera->position = [0.,$ground(0,$z)+2.55,$z];
	$vehicle = [0.,$ground(0,$z+6.7),$z+6.7];
	$bounded->step($gates,$camera->position,$vehicle);
	$before = min($before,$raw->place($gates,$camera->position,$vehicle)[1]-$ground(0,$z));
	[$x,$y,$eyeZ] = $bounded->apply($camera,$vehicle,$gates)->position;
	$after = min($after,$y-$ground($x,$eyeZ));
	$solid = min($solid,clearance([$x,$y,$eyeZ],$gates));
}
expect($before < 0 && $after >= $c-1e-9 && $solid >= $c-1e-9,
	'A duck into a descending road keeps the final eye clear of both the edited surface and the gantry.');
$graded->close();

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
		$gates = $session->course->obstructions();
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

// The car through a road gate. On flat ground the chase eye rides 2.56 m up, 0.5 m under the board of a gate at its
// own size, so that gate moves it a centimetre at most. The board of a gate 2.3 m tall cuts the eye's path: the drawn
// eye ducks under it and back, and nothing else changes.
$open = ['worldSize' => 1024, 'heightScale' => 0, 'detailAmplitude' => 0, 'waterLevel' => -5];
$drivers = [];
foreach(['flat' => 0.0, 'short' => 2.3, 'short unseen' => 2.3, 'none' => null] as $name => $height)
{
	$session = new Session($open);
	$session->camera->position = [0, 30, 0]; $session->camera->yaw = M_PI;
	input($session, ['actions' => ['car']]);
	for($i = 0; $session->findingCarSite() && $i < 2000; $i++) input($session, []);
	expect($session->mode === 'car', 'The car boards');
	if($height !== null)
		$session->course->place('start', 'road', 0.0, $session->landscape->height(0.0, 60.0), 60.0, 0.0, 1, null,
			$height ?: null, fn(float $x, float $z): float => $session->landscape->height($x, $z));
	$drivers[$name] = [$session, race($session, ['KeyW' => true], 420, $name !== 'short unseen')];
}
[, $flat] = $drivers['flat'];
expect($flat['duck'] < .02 && $flat['least'] >= $c - 1e-9,
	sprintf('On flat ground the road gate lowers the drawn car eye %.3f m', $flat['duck']));
[$session, $drive] = $drivers['short'];
$car = end($drive['states']);
expect($car['z'] > 60 + 6.7 + 10 && $drive['states'] === $drivers['short unseen'][1]['states']
	&& $drive['states'] === $drivers['none'][1]['states'] && $drive['eyes'] === $drivers['none'][1]['eyes']
	&& $flat['states'] === $drivers['none'][1]['states'],
	'The car and its chase camera move exactly as they do without the gate or without drawing');
expect($drive['ducked'] > 20 && $drive['duck'] > .5 && $drive['least'] >= $c - 1e-9 && $drive['jump'] < .05
	&& $drive['still'] > 100,
	sprintf('Under a 2.3 m road gate the drawn car eye ducks %.2f m over %d steps, stays %.3f m clear and steps at most %.3f m beyond the chase eye',
		$drive['duck'], $drive['ducked'], $drive['least'], $drive['jump']));
expect($session->status()['duck']['offset'] === [0.0, 0.0, 0.0] && $session->view(1.0)[0] === $session->camera,
	'Past the gate the drawn eye is the chase eye again');
// Leaving the car while its eye ducks under the board starts free flight from the ducked eye, raised to the free
// camera's 2 m above the ground, which still leaves it outside the board.
$session = new Session($open);
$session->camera->position = [0, 30, 0]; $session->camera->yaw = M_PI;
input($session, ['actions' => ['car']]);
for($i = 0; $session->findingCarSite() && $i < 2000; $i++) input($session, []);
$session->course->place('start', 'road', 0.0, $session->landscape->height(0.0, 60.0), 60.0, 0.0, 1, null, 2.3,
	fn(float $x, float $z): float => $session->landscape->height($x, $z));
input($session, ['keys' => ['KeyW' => true]]);
for($i = 0; $i < 600 && abs($session->camera->position[2] - 60) > .2; $i++) $session->step(1 / 60);
$gates = $session->course->obstructions();
$inside = clearance($session->camera->position, $gates);
$drawn = $session->view(1.0)[0]->position;
input($session, ['actions' => ['fly']]);
$floor = $session->landscape->height($drawn[0], $drawn[2]) + 2;
expect($inside < 0 && $session->mode === 'fly' && abs($session->camera->position[0] - $drawn[0]) < 1e-9
	&& abs($session->camera->position[2] - $drawn[2]) < 1e-9 && abs($session->camera->position[1] - max($floor, $drawn[1])) < 1e-9
	&& clearance($session->camera->position, $gates) > .25,
	sprintf('Leaving the car under the board starts free flight %.2f m clear of it instead of %.2f m inside',
		clearance($session->camera->position, $gates), -$inside));
$session->close();
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

// The F5 on its 8 m floor through a road gate 12 m tall, with each chase camera. The drawn eye ducks under the board.
foreach(['novice', 'expert'] as $controls)
{
	$flights = [];
	foreach(['gate', 'none'] as $name)
	{
		$session = new Session(['worldSize' => 8192, 'heightScale' => 0, 'detailAmplitude' => 0]);
		$session->camera->position = [0, 75, -1000]; $session->camera->yaw = M_PI; $session->camera->pitch = 0.0;
		input($session, ['values' => ['jetControls' => $controls], 'actions' => ['jet']]);
		if($name === 'gate') $session->course->place('start', 'road', 0.0, 0.0, 0.0, 0.0, 1, 30.0, 12.0,
			fn(float $x, float $z): float => $session->landscape->height($x, $z));
		// Dive to the floor, then fly level on it. Expert controls turn the nose at a rate, so they level out by
		// holding the pitch, and the way it is turning, at zero.
		input($session, ['keys' => ['KeyQ' => true]]);
		$states = [];
		for($i = 0; $i < 600 && $session->jet->state()['y'] > max(0.0, $session->water->level()) + 8 + 1e-6; $i++)
		{
			$session->step(1 / 60);
			$states[] = $session->jet->state();
		}
		$pitch = $session->jet->state()['pitch'];
		for($i = 0; $i < 240; $i++)
		{
			$turning = $session->jet->state()['pitch'] - $pitch;
			$pitch = $session->jet->state()['pitch'];
			$error = $pitch + 15 * $turning;
			input($session, ['keys' => $controls === 'novice' ? [] : ($error < -.01 ? ['KeyE' => true]
				: ($error > -.002 ? ['KeyQ' => true] : []))]);
			$session->step(1 / 60);
			$states[] = $session->jet->state();
		}
		$flights[$name] = [$session, $states, race($session, [], 600, $name === 'gate')];
	}
	[$session, $dive, $flight] = $flights['gate'];
	expect($dive === $flights['none'][1] && $flight['states'] === $flights['none'][2]['states']
		&& $flight['eyes'] === $flights['none'][2]['eyes'] && end($flight['states'])['z'] > 60
		&& abs(end($flight['states'])['y'] - 8) < 1e-6,
		"The $controls F5 and its camera fly exactly as they do without the road gate");
	expect($session->race->state === 'running' && $flight['ducked'] > 20 && $flight['least'] >= $c - 1e-9 && $flight['jump'] < 1,
		sprintf('Through a 12 m road gate the drawn %s F5 eye ducks %.2f m, stays %.3f m clear and steps at most %.3f m beyond its camera',
			$controls, $flight['duck'], $flight['least'], $flight['jump']));
	foreach($flights as [$session]) $session->close();
}

/**
 * Step a session and measure the drawn eye against the session's camera after each step.
 * @param callable $control fn(int $i): array input for the step.
 * @return array Vehicle states, the least clearance of the drawn eye, its largest offset from the camera and the
 * largest step of the drawn eye beyond the camera's step.
 */
function watch(Session $session, callable $control, int $steps): array
{
	$states = []; $least = INF; $offset = 0.0; $jump = 0.0; $previous = null;
	for($i = 0; $i < $steps; $i++)
	{
		input($session, $control($i));
		$session->step(1 / 60);
		$states[] = match($session->mode) { 'car' => $session->car->state(), 'jet' => $session->jet->state() };
		[$view] = $session->view(1.0);
		$least = min($least, clearance($view->position, $session->course->obstructions()));
		$offset = max($offset, distance($view->position, $session->camera->position));
		if($previous !== null)
			$jump = max($jump, distance($view->position, $previous[0]) - distance($session->camera->position, $previous[1]));
		$previous = [$view->position, $session->camera->position];
	}
	return ['states' => $states, 'least' => $least, 'offset' => $offset, 'jump' => $jump];
}

// With momentum the slower F5 turns short of the gate from 90 m out. From 60 m it crosses, climbs,
// and returns over the banner. The drawn eye must release the window before coming back across.
foreach([90,60] as $leadIn)
{
	$crosses = $leadIn === 60;
	$loops = [];
	foreach(['gate', 'none'] as $name)
	{
		$session = new Session(['worldSize' => 8192, 'heightScale' => 0, 'detailAmplitude' => 0]);
		$session->camera->position = [0, 200, -1000]; $session->camera->yaw = M_PI; $session->camera->pitch = 0.0;
		input($session, ['values' => ['jetControls' => 'expert'], 'actions' => ['jet']]);
		if($name === 'gate') $session->course->place('start', 'air', 0.0, 200 - $shapes->kind('air')['window']['top'] / 2, 0.0, 0.0);
		input($session, ['keys' => ['KeyS' => true]]);
		for($i = 0; $i < 2000 && $session->jet->state()['z'] < -$leadIn; $i++) $session->step(1 / 60);
		$loops[$name] = [$session, $session->jet->state()['speed'],
			watch($session, fn(int $i): array => ['keys' => ['KeyS' => true, 'KeyE' => true]], 300)];
	}
	[$session, $speed, $loop] = $loops['gate'];
	expect($speed === Jet::SLOWEST && $loop['states'] === $loops['none'][2]['states'] && end($loop['states'])['z'] < 0
		&& (max(array_column($loop['states'],'z')) > 0) === $crosses && $loop['offset'] < ($crosses ? 12 : 3) && $loop['least'] >= $c - 1e-9 && $loop['jump'] < 2,
		sprintf('The slowest Expert F5 looping in front of an air gate moves its drawn eye at most %.2f m, %.2f m beyond its camera\'s step',
			$loop['offset'], $loop['jump']));
	foreach($loops as [$session]) $session->close();
}

// The car stops in a road gate 1 m off its middle and the driver swings the look stick right round. The drawn eye
// keeps to its side of each post instead of jumping across it, and stays clear of the gate.
$session = new Session($open);
$session->camera->position = [0, 30, 0]; $session->camera->yaw = M_PI;
input($session, ['actions' => ['car']]);
for($i = 0; $session->findingCarSite() && $i < 2000; $i++) input($session, []);
$x = $session->car->state()['x'];
$session->course->place('start', 'road', $x - 1, $session->landscape->height($x - 1, 60.0), 60.0, 0.0, 1, null, null,
	fn(float $x, float $z): float => $session->landscape->height($x, $z));
for($i = 0; $i < 1200 && !($session->car->state()['z'] > 58.5 && abs($session->car->state()['speed']) < .01); $i++)
{
	$car = $session->car->state();
	input($session, ['pad' => [0, 0, 0, 0, $car['z'] > 58.5 ? 1 : 0, $car['z'] <= 58.5 && $car['speed'] < 6 ? .5 : 0, 0]]);
	$session->step(1 / 60);
}
$swing = watch($session, fn(int $i): array => ['pad' => [0, 0, sin(2 * M_PI * $i / 300), -cos(2 * M_PI * $i / 300), 0, 0, 0]],
	320);
$car = $session->car->state();
expect(abs($car['x'] - $x) < .05 && abs($car['z'] - 59) < 1 && $swing['offset'] > .5 && $swing['least'] >= $c - 1e-9
	&& $swing['jump'] < .3,
	sprintf('Swinging the look stick round in a road gate moves the drawn car eye up to %.2f m, at most %.2f m beyond the chase eye\'s step',
		$swing['offset'], $swing['jump']));
$session->close();

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
