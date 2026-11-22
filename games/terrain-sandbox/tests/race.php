<?php
declare(strict_types=1);

$package = getenv('PHONICS_TERRAIN_ROOT') ?: dirname(__DIR__, 3) . '/packages/terrain-phonics';
require_once $package . '/autoload.php';
require_once dirname(__DIR__) . '/autoload.php';

use TerrainPhonics\Landscape;
use TerrainSandbox\Car;
use TerrainSandbox\Circuit;
use TerrainSandbox\CircuitRoad;
use TerrainSandbox\CircuitCurves;
use TerrainSandbox\Course;
use TerrainSandbox\GateShapes;
use TerrainSandbox\Jet;
use TerrainSandbox\NavigationArrow;
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
function enterCar(Session $session): void
{
	input($session, ['actions' => ['car']]);
	for($i = 0; $session->findingCarSite() && $i < 2000; $i++) input($session, []);
	expect(!$session->findingCarSite() && $session->mode === 'car', 'The car boards');
}
/** Wrap an angle into -pi to pi. */
function wrap(float $angle): float { return atan2(sin($angle), cos($angle)); }

/**
 * Click the gate tool straight down onto a point from free flight, as a player looking along a heading would.
 * @param Session $session Session in free flight.
 * @param string $role start, checkpoint, finish or remove.
 * @param float $x World X to click.
 * @param float $z World Z to click.
 * @param float $heading Direction of travel the camera faces, in the vehicles' convention.
 * @param float $altitude Gate altitude setting in meters: 0 for road and water gates, above 0 for air gates.
 * @param float $eye Camera height in meters.
 * @param float $width Gate width setting in meters, 0 for the kind's own.
 * @param float $height Gate height setting in meters, 0 for the kind's own.
 * @return array|null The course gate the click placed or moved, or null when the click placed none.
 */
function click(Session $session, string $role, float $x, float $z, float $heading = 0.0, float $altitude = 0.0,
	float $eye = 40.0, float $width = 0.0, float $height = 0.0): ?array
{
	$session->camera->position = [$x, $eye, $z];
	$session->camera->pitch = -M_PI / 2 + .001;
	$session->camera->yaw = M_PI - $heading;
	$revision = $session->course->revision;
	input($session, ['values' => ['tool' => 'gate', 'gateRole' => $role, 'gateAltitude' => $altitude,
		'gateWidth' => $width, 'gateHeight' => $height], 'clicks' => [[.5, .5]]]);
	finishPicking($session);
	if($role === 'remove' || $session->course->revision === $revision) return null;
	$latest = null;
	foreach($session->course->gates() as $gate)
		if($gate['role'] === $role && ($latest === null || $role !== 'checkpoint' || $gate['number'] > $latest['number']))
			$latest = $gate;
	return $latest;
}
/** Check the built pavement at a finer interval than the generator, up to 4 m from either edge. */
function highwayCrests(Session $session, Circuit $route): void
{
	$speed = 75 * Car::MPS_PER_MPH;
	$maximum = 0.;
	foreach($route->stations(range(0, (int)$route->length-1, 4)) as [$x,$z,$yaw])
	{
		$dx = sin($yaw);
		$dz = cos($yaw);
		foreach([-14.,0.,14.] as $offset)
		{
			$px = $x+$offset*$dz;
			$pz = $z-$offset*$dx;
			$middle = $session->landscape->height($px,$pz);
			$before = $session->landscape->height($px-4*$dx,$pz-4*$dz);
			$after = $session->landscape->height($px+4*$dx,$pz+4*$dz);
			$maximum = max($maximum, $speed**2*(2*$middle-$before-$after)/4**2);
		}
	}
	expect($maximum < 9.81, sprintf('At 75 mph the sharpest pavement crest needs %.2f m/s² of downward acceleration', $maximum));
}

/** The gates the status reports, by id. */
function statusGates(Session $session): array { return array_column($session->status()['course']['gates'], null, 'id'); }

// Gate shapes come from assets/gates/gates.json when the package has one, and from defaults of the same layout.
$scratch = sys_get_temp_dir() . '/terrain-gates-' . getmypid();
mkdir($scratch);
$defaults = GateShapes::defaults();
expect(!$defaults->fromFile && GateShapes::load("$scratch/absent.json")->export() === $defaults->export(),
	'A missing gates.json reads as the built-in defaults');
expect(count($defaults->kind('road')['posts']) === 2 && $defaults->kind('water')['posts'] === []
	&& $defaults->kind('air')['posts'] === [], 'Only the road gate has posts');
$shipped = dirname(__DIR__) . '/assets/gates/gates.json';
if(is_file($shipped))
	expect(GateShapes::load($shipped)->export() === $defaults->export(),
		'The built-in defaults repeat the shapes gates.json ships, so a package without the file races the same gates');
$file = GateShapes::DEFAULTS;
foreach($file['kinds']['road']['posts'] as $index => $reach) unset($file['kinds']['road']['posts'][$index]['bottom'],
	$file['kinds']['road']['posts'][$index]['top']);
unset($file['kinds']['water']['extent']['bottom'], $file['kinds']['water']['extent']['halfDepth']);
file_put_contents("$scratch/gates.json", json_encode($file));
$read = GateShapes::load("$scratch/gates.json");
$post = $read->kind('road')['posts'][0];
expect($post['bottom'] === -GateShapes::POST_DEPTH && $post['top'] === $read->kind('road')['extent']['top'],
	'A post without a stated reach runs from 8 m below the base to the top of the gate');
expect($read->kind('water')['extent']['bottom'] === $read->kind('water')['window']['bottom']
	&& $read->kind('water')['extent']['halfDepth'] === GateShapes::HALF_DEPTH,
	'An extent without a stated bottom or depth reaches the window bottom and 1 m along the travel');
$file = GateShapes::DEFAULTS;
$file['kinds']['air']['window']['top'] = 9;
$file['kinds']['road']['posts'][0] = ['bottom' => -6, 'top' => 4] + $file['kinds']['road']['posts'][0];
$file['kinds']['road']['label'] = 'extra fields are left for the renderer';
file_put_contents("$scratch/gates.json", json_encode($file));
$read = GateShapes::load("$scratch/gates.json");
expect($read->fromFile && $read->kind('air')['window']['top'] === 9.0 && $read->kind('road')['posts'][0]['bottom'] === -6.0
	&& $read->kind('road')['posts'][1]['bottom'] === -8.0, 'gates.json overrides the defaults, post by post');
$broken = [
	'no water kind' => function(array &$f) { unset($f['kinds']['water']); },
	'a window without width' => function(array &$f) { $f['kinds']['road']['window']['halfWidth'] = 0; },
	'an extent inside its window' => function(array &$f) { $f['kinds']['air']['extent']['top'] = 1; },
	'a mesh path' => function(array &$f) { $f['kinds']['road']['mesh'] = '../road.bin'; },
	'a post with no size' => function(array &$f) { $f['kinds']['road']['posts'][0]['halfSize'] = -1; },
	'an extent above its window' => function(array &$f) { $f['kinds']['water']['extent']['bottom'] = 1; },
	'an extent with no depth' => function(array &$f) { $f['kinds']['air']['extent']['halfDepth'] = 0; },
	'another version' => function(array &$f) { $f['version'] = 2; },
];
foreach($broken as $name => $break)
{
	$file = GateShapes::DEFAULTS;
	$break($file);
	file_put_contents("$scratch/gates.json", json_encode($file));
	try { GateShapes::load("$scratch/gates.json"); expect(false, "gates.json with $name must be refused"); }
	catch(RuntimeException $error) { expect(!str_contains($error->getMessage(), 'must be refused'), "gates.json with $name is refused"); }
}
// The atlas only matters to the renderer, so a file may lay out its prints another way.
$file = GateShapes::DEFAULTS;
unset($file['atlas']['roles']['start-finish'], $file['kinds']['water']['mesh']);
$file['atlas']['layout'] = 'one texture per role';
file_put_contents("$scratch/gates.json", json_encode($file));
$read = GateShapes::load("$scratch/gates.json");
expect($read->atlas === null && $read->document['atlas']['layout'] === 'one texture per role'
	&& $read->kind('water')['mesh'] === 'water.mesh.bin.gz' && $defaults->atlas['checkpoint'] === [0.0, .25],
	'A file with another print layout loads, keeps its own fields for the renderer and names default meshes');
unlink("$scratch/gates.json");
rmdir($scratch);
$packaged = GateShapes::packaged();
expect($packaged === GateShapes::packaged()
	&& $packaged->fromFile === is_file(dirname(__DIR__) . '/assets/gates/gates.json'),
	'The packaged shapes are read once, from gates.json when it ships');
$shapes = $packaged;
$road = $shapes->kind('road');
$water = $shapes->kind('water');
$air = $shapes->kind('air');

// Placement. The flat fixture stands well above its water.
$flat = ['worldSize' => 512, 'heightScale' => 0, 'detailAmplitude' => 0, 'waterLevel' => -5];
$edit = new Session($flat);
expect($edit->settings['gateRole'] === 'start' && $edit->settings['gateLaps'] === 1 && $edit->settings['gateAltitude'] === 0.0
	&& $edit->settings['gateWidth'] === 0.0 && $edit->settings['gateHeight'] === 0.0,
	'Gate settings start on the start role, one lap, ground height and each kind\'s own size');
$start = click($edit, 'start', 10, 20, M_PI / 2);
expect($start !== null && $start['kind'] === 'road' && abs($start['x'] - 10) < .1 && abs($start['z'] - 20) < .1
	&& abs($start['y']) < 1e-6 && abs($start['yaw'] - M_PI / 2) < 1e-9 && $start['laps'] === 1,
	'A click on land at zero height places a road gate on the ground facing the camera heading');
expect($edit->message === 'Placed the start gate at 10, 20 m, its opening 13.3 by 3.1 m.',
	'Placing a gate says what, where and how big: ' . $edit->message);
$gates = statusGates($edit);
expect(count($gates) === 1 && $gates[$start['id']]['print'] === 'start-finish' && $gates[$start['id']]['laps'] === 1,
	'A start gate without a finish gate shows the START / FINISH print');
$moved = click($edit, 'start', -30, 40, -2.5);
expect($edit->course->count() === 1 && $moved['id'] === $start['id'] && abs($moved['x'] + 30) < .1
	&& abs($moved['yaw'] + 2.5) < 1e-9 && str_starts_with($edit->message, 'Moved the start gate to -30, 40 m'),
	'Placing a second start gate moves the first');
$facing = click($edit, 'checkpoint', 0, 0, 7.5);
expect($facing['number'] === 1 && $facing['yaw'] >= -M_PI && $facing['yaw'] <= M_PI && abs($facing['yaw'] - (7.5 - 2 * M_PI)) < 1e-9,
	'A gate heading is kept within -pi to pi');
for($i = 2; $i <= 4; $i++) click($edit, 'checkpoint', 50 * $i, 0);
$numbers = array_column(array_filter($edit->course->gates(), fn($g) => $g['role'] === 'checkpoint'), 'number');
expect(array_values($numbers) === [1, 2, 3, 4] && $edit->message === 'Placed checkpoint 4 at 200, 0 m, its opening 13.3 by 3.1 m.',
	'Checkpoints are numbered in placement order');
$finish = click($edit, 'finish', 0, -100);
$gates = statusGates($edit);
expect($finish['role'] === 'finish' && $gates[$moved['id']]['print'] === 'start' && $gates[$finish['id']]['print'] === 'finish',
	'With a finish gate the start gate shows the START print');
$again = click($edit, 'finish', 0, -120);
expect($again['id'] === $finish['id'] && abs($again['z'] + 120) < .1 && $edit->course->count() === 6,
	'Placing a second finish gate moves the first');
$second = array_values(array_filter($edit->course->gates(), fn($g) => ($g['number'] ?? 0) === 2))[0];
click($edit, 'remove', 100, 3);
$after = array_column(array_filter($edit->course->gates(), fn($g) => $g['role'] === 'checkpoint'), 'number', 'id');
expect(!isset($after[$second['id']]) && array_values($after) === [1, 2, 3]
	&& $edit->message === 'Removed checkpoint 2. Later checkpoints moved up one number.',
	'Remove deletes the gate nearest the click and renumbers the later checkpoints');
$revision = $edit->course->revision;
click($edit, 'remove', 100, 60);
expect($edit->course->revision === $revision && str_starts_with($edit->message, 'No gate there'),
	'A click beyond every gate\'s span removes nothing');
// Two gates 10 m apart, one behind the other: the click lands 3 m from one of them.
$near = click($edit, 'checkpoint', -100, 200);
$far = click($edit, 'checkpoint', -100, 210);
click($edit, 'remove', -100, 207);
expect($edit->course->gate($far['id']) === null && $edit->course->gate($near['id']) !== null,
	'Of two gates within reach, Remove takes the nearer');
$high = click($edit, 'checkpoint', 150, 150, 0, 60);
expect($high['kind'] === 'air' && abs($high['y'] - 60) < 1e-9
	&& str_ends_with($edit->message, ', 60 m above the ground, its opening 40 by 8.8 m.'),
	'With a height above zero a click on land places an air gate that far above the ground');
// The camera looks along +Z, 550 m short of a gate 60 m above the ground. The ray through the gate's window meets the
// ground about 145 m beyond it.
$edit->camera->position = [150, 360, -400];
$edit->camera->yaw = M_PI;
$edit->camera->pitch = atan2(60 + $air['window']['top'] / 2 - 360, 550);
input($edit, ['values' => ['gateRole' => 'remove'], 'clicks' => [[.5, .5]]]);
finishPicking($edit);
expect($edit->course->gate($high['id']) === null, 'Remove finds an air gate along the ray, high above the ground the ray hits');
$count = $edit->course->count();
input($edit, ['values' => ['gateRole' => 'pit lane', 'gateAltitude' => 900]]);
expect($edit->settings['gateRole'] === 'remove' && $edit->settings['gateAltitude'] === 400.0,
	'An unknown role is ignored and the altitude is held to 400 m');
input($edit, ['values' => ['gateAltitude' => -5]]);
expect($edit->settings['gateAltitude'] === 0.0, 'A negative altitude places road gates');

// Laps belong to the start gate. This course has a finish gate, so it keeps them for when that gate goes.
input($edit, ['values' => ['gateLaps' => 3]]);
expect($edit->course->start()['laps'] === 3 && statusGates($edit)[$moved['id']]['laps'] === 3 && $edit->course->laps() === 1
	&& $edit->message === 'Laps apply to a course that finishes at its start gate. This course runs once to its finish gate.',
	'Editing the lap count updates the start gate, and says a course with a finish gate runs once');
input($edit, ['values' => ['gateLaps' => 25]]);
expect($edit->course->start()['laps'] === 20 && $edit->settings['gateLaps'] === 20, 'Laps are held to 20');
input($edit, ['values' => ['gateLaps' => 2.6]]);
expect($edit->course->start()['laps'] === 3 && $edit->settings['gateLaps'] === 3, 'Laps round to a whole number');
input($edit, ['values' => ['gateLaps' => 'many']]);
expect($edit->course->start()['laps'] === 3 && $edit->message === 'Invalid control value', 'A lap count that is not a number is refused');
$movedAgain = click($edit, 'start', 0, 100);
expect($movedAgain['laps'] === 3, 'A moved start gate keeps its laps');

input($edit, ['actions' => ['gates-clear']]);
expect($edit->course->count() === 0 && $edit->message === "Removed all $count gates." && $edit->status()['course']['gates'] === [],
	'Clear gates removes them all');
input($edit, ['actions' => ['gates-clear']]);
expect($edit->message === 'There are no gates to clear.', 'Clearing an empty course says so');
input($edit, ['values' => ['gateLaps' => 4]]);
$fresh = click($edit, 'start', 0, 0);
expect($fresh['laps'] === 4, 'A new start gate takes the lap setting');
// One behind another along their direction of travel, 11 m apart, which keeps their spans clear of each other.
for($i = 1; $i <= Course::MAX_CHECKPOINTS; $i++) click($edit, 'checkpoint', 50, -190 + 11 * $i);
$revision = $edit->course->revision;
click($edit, 'checkpoint', 0, -50);
expect($edit->course->revision === $revision && $edit->message === 'A course holds 32 checkpoints. Remove one to add another.',
	'A course holds at most 32 checkpoints');
$edit->close();

// The route: every lap passes the checkpoints by number, and returns through the start gate unless a finish gate ends
// the last lap.
$course = new Course;
expect($course->route() === [], 'A course without a start gate has no route');
$s = $course->place('start', 'road', 0, 0, 0, 0, 2)['gate'];
expect(array_column($course->route(), 'id') === [$s['id'], $s['id']] && array_column($course->route(), 'lapEnd') === [true, true],
	'Two laps of a lone start gate pass back through it twice');
$c1 = $course->place('checkpoint', 'road', 0, 0, 50, 0)['gate'];
$c2 = $course->place('checkpoint', 'air', 0, 20, 100, 0)['gate'];
$route = $course->route();
expect(array_column($route, 'id') === [$c1['id'], $c2['id'], $s['id'], $c1['id'], $c2['id'], $s['id']]
	&& array_column($route, 'lap') === [1, 1, 1, 2, 2, 2] && array_column($route, 'lapEnd') === [false, false, true, false, false, true],
	'Without a finish gate each lap ends at the start gate');
$f = $course->place('finish', 'road', 0, 0, 150, 0)['gate'];
expect(array_column($course->route(), 'id') === [$c1['id'], $c2['id'], $f['id']] && $course->laps() === 1
	&& $course->start()['laps'] === 2, 'A course with a finish gate runs once from start to finish, whatever its laps');
$revision = $course->revision;
expect($course->setLaps(3) && $course->revision === $revision && $course->laps() === 1,
	'Its start gate keeps a new lap count without changing the course');
$course->remove($f['id']);
expect(count($course->route()) === 9 && $course->laps() === 3, 'Without the finish gate the kept laps run again');

// The crossing test uses the segment between two fixed steps. A road gate on level ground counts a crossing from a
// meter under the ground, which allows for the ground between samples, up to the window top.
$roadWindow = ['halfWidth' => $road['window']['halfWidth'], 'bottom' => -Course::ROAD_BELOW, 'top' => $road['window']['top']];
$airWindow = $air['window'];
$level = new Course;
expect($level->measure($level->place('start', 'road', 0, 0, 0, 0)['gate'])['window'] == $roadWindow,
	'A road gate on level ground measures its window from a meter below the ground to the board');
$gate = ['x' => 0.0, 'y' => 10.0, 'z' => 0.0, 'yaw' => M_PI / 2];
$top = $road['window']['top'];
$half = $road['window']['halfWidth'];
expect(abs(Race::crossing($gate, $roadWindow, [-1, 11, 3], [3, 11, 3]) - .25) < 1e-12, 'A forward crossing reports where along the step it met the gate');
expect(Race::crossing($gate, $roadWindow, [3, 11, 3], [-1, 11, 3]) === null, 'A backward crossing does not count');
expect(Race::crossing($gate, $roadWindow, [-1, 11, $half + .1], [3, 11, $half + .1]) === null
	&& Race::crossing($gate, $roadWindow, [-1, 11, -$half + .1], [3, 11, -$half + .1]) !== null,
	'Only a crossing within the window\'s half width counts');
expect(Race::crossing($gate, $roadWindow, [-1, 10 + $top + .1, 0], [1, 10 + $top + .1, 0]) === null
	&& Race::crossing($gate, $roadWindow, [-1, 10 - Course::ROAD_BELOW + .1, 0], [1, 10 - Course::ROAD_BELOW + .1, 0]) !== null
	&& Race::crossing($gate, $roadWindow, [-1, 10 - Course::ROAD_BELOW - .1, 0], [1, 10 - Course::ROAD_BELOW - .1, 0]) === null,
	'A road crossing counts from just under the ground to the window top');
expect(Race::crossing($gate, $roadWindow, [-2, 11, 0], [0, 11, 0]) === 1.0 && Race::crossing($gate, $roadWindow, [0, 11, 0], [2, 11, 0]) === null,
	'A step ending on the gate plane crosses it, so the next step starting there does not cross again');
$high = ['x' => 0.0, 'y' => 200.0, 'z' => 500.0, 'yaw' => 0.0];
$step = Jet::FASTEST / 60;
expect(abs(Race::crossing($high, $airWindow, [3, 201, 500 - $step / 3], [3, 201, 500 + 2 * $step / 3]) - 1 / 3) < 1e-12,
	'A jet at 260 m/s, 4.3 m a step, meets the window inside one step');
expect(Race::crossing($high, $airWindow, [3, 199.9, 499], [3, 199.9, 501]) === null
	&& Race::crossing($high, $airWindow, [3, 200 + $air['window']['top'] + .1, 499], [3, 200 + $air['window']['top'] + .1, 501]) === null,
	'An air gate counts only between its window\'s bottom and top edges');
// Jump a rotated road course, crossing the checkpoint plane while descending.
$jumpCourse = new Course;
foreach(['start'=>0., 'checkpoint'=>40., 'finish'=>80.] as $role=>$x)
	$jumpCourse->place($role, 'road', $x, 0, 0, M_PI / 2, height: 2 * $top);
$jumpRace = new Race;
$jumpRace->advance($jumpCourse, [-2,0,0], [2,0,0], 0, 1, 'car', 1);
foreach([
	[[38,18,$half+1],[42,8,$half+1]], // Beyond the posts.
	[[42,18,0],[38,8,0]],              // Wrong direction.
	[[38,-4,0],[42,-4,0]],             // Below the ground.
	[[78,18,0],[82,8,0]],              // Finish before checkpoint.
] as [$from,$to])
	expect($jumpRace->advance($jumpCourse,$from,$to,1,1,'car',1) === null, 'A missed or out-of-order jump earns no split');
$jumpRace->advance($jumpCourse, [38,18,0], [42,8,0], 4, 1, 'car', 1);
$jumpRace->advance($jumpCourse, [78,15,0], [82,14,0], 6, 1, 'car', 1);
$jumpResult = $jumpRace->status($jumpCourse,7);
expect($jumpResult['state'] === 'finished' && array_column($jumpResult['splits'],'time') === [4.,6.],
	'A car sailing between the posts above the boards earns the checkpoint and finish at their crossing times');

// One 60 m step of a jet passes the start gate, a checkpoint and the finish gate, each timed where the step meets it.
// The finish gate stands one air gate width beyond the start gate, the nearest it can stand without merging.
$course = new Course;
$course->place('start', 'air', 0, 100, 0, 0);
$course->place('checkpoint', 'air', 0, 100, 20, 0);
$course->place('finish', 'air', 0, 100, 40, 0);
$timer = new Race;
$middle = 100 + ($air['window']['bottom'] + $air['window']['top']) / 2;
$runs = [];
foreach([1.5, 3.0, .75] as $dt)
{
	$event = $timer->advance($course, [0, $middle, -10], [0, $middle, 50], 10.0, $dt, 'jet', 1);
	$runs[] = [$event, $timer->status($course, 10 + $dt)];
}
expect($runs[0][1]['state'] === 'finished' && abs($runs[0][1]['time'] - 1) < 1e-12
	&& abs($runs[0][1]['splits'][0]['time'] - .5) < 1e-12 && count($runs[0][1]['splits']) === 2
	&& $runs[0][0] === 'Finished in 1.00 s.', 'Several gates within one step each count, in order along the step');
expect($runs[1][0] === 'Finished in 2.00 s. Best 1.00 s.' && $runs[2][0] === 'Finished in 0.50 s, a new best by 0.50 s.'
	&& $runs[2][1]['best'] === $runs[2][1]['time'] && $runs[2][1]['last'] === $runs[2][1]['time'],
	'A finish reports the best time, or how much a new best beat it by');
expect(Race::clock(12.345) === '12.35 s' && Race::clock(83.456) === '1:23.46' && Race::clock(59.996) === '1:00.00',
	'Race times read in hundredths, with minutes from one minute up');


/**
 * Drive the car round waypoints with the gamepad, steering at each in turn and holding a cruising speed.
 * @param Session $session Session with the car boarded.
 * @param list<array> $waypoints [x, z] points, visited in a loop. The car moves on to the next within 12 m.
 * @param int $steps Most fixed steps to drive.
 * @param callable $until Called after each step; driving stops when it returns true.
 * @return int Steps driven.
 */
function autopilot(Session $session, array $waypoints, int $steps, callable $until, float $cruise = 12.0): int
{
	$next = 0;
	for($i = 1; $i <= $steps; $i++)
	{
		$car = $session->car->state();
		[$x, $z] = $waypoints[$next % count($waypoints)];
		if(hypot($x - $car['x'], $z - $car['z']) < 12) [$x, $z] = $waypoints[++$next % count($waypoints)];
		// Positive steering turns right, which lowers the heading.
		$turn = max(-1.0, min(1.0, -2.5 * wrap(atan2($x - $car['x'], $z - $car['z']) - $car['yaw'])));
		$pedal = max(-1.0, min(1.0, ($cruise - $car['speed']) * .5));
		input($session, ['pad' => [$turn, 0, 0, 0, max(0.0, -$pedal), max(0.0, $pedal), 0]]);
		$session->step(1 / 60);
		if($until($session)) return $i;
	}
	return $steps;
}
/** Step a session with keys held until a condition holds. */
function drive(Session $session, array $keys, int $steps, callable $until): int
{
	input($session, ['keys' => $keys]);
	for($i = 1; $i <= $steps; $i++) { $session->step(1 / 60); if($until($session)) return $i; }
	return $steps;
}
$race = fn(Session $session): array => $session->status()['race'];
$state = fn(string $wanted): Closure => fn(Session $session): bool => $session->race->state === $wanted;

// A rolling start on a straight: the clock starts where the car meets the start gate within its step.
$open = ['worldSize' => 1024, 'heightScale' => 0, 'detailAmplitude' => 0, 'waterLevel' => -5];
$sprint = new Session($open);
$s = click($sprint, 'start', 0, 20);
$c = click($sprint, 'checkpoint', 0, 100);
$f = click($sprint, 'finish', 0, 180);
$idle = $race($sprint);
expect($idle['state'] === 'idle' && $idle['time'] === null && $idle['laps'] === 1 && $idle['next']['id'] === $s['id']
	&& $idle['next']['print'] === 'start' && $idle['splits'] === [] && $idle['best'] === null,
	'Before a race the status points at the start gate');
$sprint->camera->position = [0, 30, -10]; $sprint->camera->yaw = M_PI;
enterCar($sprint);
$revision = $sprint->course->revision;
input($sprint, ['values' => ['tool' => 'gate', 'gateRole' => 'remove'], 'clicks' => [[.5, .5]]]);
input($sprint, ['values' => ['gateRole' => 'checkpoint'], 'clicks' => [[.5, .5]]]);
expect(!$sprint->picking && $sprint->course->revision === $revision, 'Gate clicks while driving change nothing');
$before = $sprint->car->state();
drive($sprint, ['KeyW' => true], 600, function(Session $session) use (&$before, $state): bool {
	if($state('running')($session)) return true;
	$before = $session->car->state();
	return false;
});
$after = $sprint->car->state();
$running = $race($sprint);
$share = ($s['z'] - $before['z']) / ($after['z'] - $before['z']);
expect($running['state'] === 'running' && $before['z'] < $s['z'] && $after['z'] >= $s['z'] && $before['speed'] > 10
	&& abs($running['time'] - (1 - $share) / 60) < 1e-9 && $running['lap'] === 1 && $running['vehicle'] === 'car'
	&& $running['next']['id'] === $c['id'] && $sprint->message === 'Race started. Next: checkpoint 1.',
	'Driving through the start gate at speed starts the clock at the crossing, part way through the step');
input($sprint, ['actions'=>['pad-y']]);
expect($race($sprint) === $running, 'Plain Y leaves a running race alone');
$times = [];
drive($sprint, ['KeyW' => true], 900, function(Session $session) use (&$times, $state): bool {
	$times[] = $session->race->status($session->course, $session->time)['time'];
	return $state('finished')($session);
});
$done = $race($sprint);
expect($done['state'] === 'finished' && array_column($done['splits'], 'gate') === [$c['id'], $f['id']]
	&& $done['time'] === $done['splits'][1]['time'] && $done['lastSplit'] === $done['time'] && $done['last'] === $done['time']
	&& $done['best'] === $done['time'] && $done['lapTimes'] === [$done['time']] && $done['lap'] === 1
	&& str_starts_with($sprint->message, 'Finished in ') && $done['splits'][0]['time'] < $done['time'],
	'The finish gate stops the clock and records the time as the best for the course');
$monotonic = true;
for($i = 1; $i < count($times) - 1; $i++) $monotonic = $monotonic && abs($times[$i] - $times[$i - 1] - 1 / 60) < 1e-9;
expect($monotonic, 'The race clock runs at simulation speed');
// This short straight measures the gate crossings while the car is still accelerating.
expect($done['time'] > 4 && $done['time'] < 16, 'The time is the simulated time between the two crossings');
$t = $done['time'];
input($sprint, ['actions' => ['pause']]);
for($i = 0; $i < 30; $i++) $sprint->step(1 / 60);
input($sprint, ['actions' => ['pause']]);
// Run the same straight again, slower, from a fresh boarding behind the start gate.
input($sprint, ['actions' => ['fly']]);
$sprint->camera->position = [0, 30, -10]; $sprint->camera->yaw = M_PI;
enterCar($sprint);
expect($race($sprint)['state'] === 'finished' && !$race($sprint)['guidance'] && $sprint->navigationTarget() === null,
	'A finished result is retained when reboarding without bringing back the HUD');
$pad = [0, 0, 0, 0, 0, .6, 0];
for($i = 0; $i < 2400 && $sprint->race->state !== 'running'; $i++) { input($sprint, ['pad' => $pad]); $sprint->step(1 / 60); }
expect($race($sprint)['state'] === 'running' && $race($sprint)['best'] === $t && $race($sprint)['splits'] === [] && $race($sprint)['guidance'] && $sprint->navigationTarget() !== null,
	'Passing the start gate again starts a new race');
for($i = 0; $i < 2400 && $sprint->race->state !== 'finished'; $i++) { input($sprint, ['pad' => $pad]); $sprint->step(1 / 60); }
$slow = $race($sprint);
expect($slow['state'] === 'finished' && $slow['time'] > $t && $slow['best'] === $t && $slow['last'] === $slow['time']
	&& $sprint->message === 'Finished in ' . Race::clock($slow['time']) . '. Best ' . Race::clock($t) . '.',
	'A slower run keeps the earlier best and says so');
input($sprint, ['actions'=>['pad-y']]);
expect($race($sprint)['state'] === 'idle' && !$race($sprint)['guidance'] && $race($sprint)['best'] === $t,
	'Y after finishing resets the clock and keeps guidance hidden until a new start');
// Pausing stops the clock.
input($sprint, ['actions' => ['fly']]);
$sprint->camera->position = [0, 30, -10]; $sprint->camera->yaw = M_PI;
enterCar($sprint);
drive($sprint, ['KeyW' => true], 600, $state('running'));
input($sprint, ['actions' => ['pause']]);
$held = $race($sprint)['time'];
for($i = 0; $i < 60; $i++) $sprint->step(1 / 60);
expect($race($sprint)['time'] === $held && $sprint->race->state === 'running', 'Pausing stops the race clock');
input($sprint, ['actions' => ['pause', 'race-reset']]);
expect($race($sprint)['state'] === 'idle' && $race($sprint)['best'] === $t
	&& $sprint->message === 'Race reset. Pass through the start gate to start the clock.',
	'Race reset returns to waiting for a start and keeps the best time');
// Changing the course mid-race stops the race.
input($sprint, ['actions' => ['fly']]);
$sprint->camera->position = [0, 30, -10]; $sprint->camera->yaw = M_PI;
enterCar($sprint);
drive($sprint, ['KeyW' => true], 600, $state('running'));
input($sprint, ['values' => ['gateLaps' => 2]]);
expect($race($sprint)['state'] === 'running' && $race($sprint)['laps'] === 1 && $race($sprint)['best'] === $t
	&& $sprint->message === 'Laps apply to a course that finishes at its start gate. This course runs once to its finish gate.',
	'Laps leave a race to a finish gate running on the same course, and say they apply without one');
// Changing the course mid-race stops the race, and the course without its finish gate runs the laps and keeps a best
// time of its own.
$sprint->course->remove($f['id']);
input($sprint, []);
expect($race($sprint)['state'] === 'idle' && str_ends_with($sprint->message, 'The race stopped because the course changed.')
	&& $race($sprint)['laps'] === 2 && $race($sprint)['best'] === null,
	'Removing the finish gate mid-race stops the race; the course then runs its two laps with a best time of its own');
$sprint->close();

// One authored road with two gates. Start with the car off to one side beyond its checkpoint.
$rescue = new Session($open);
$rescue->landscape->queue(['type'=>'road','points'=>[[0,0,-90],[0,0,0],[0,0,90]],'width'=>12,'shoulder'=>2,'material'=>'road']);
$rescue->landscape->commit();
$rescue->course->place('start','road',0,0,-50,0);
$rescue->course->place('checkpoint','road',0,0,50,0);
$rescue->camera->position = [25,20,65]; $rescue->camera->yaw = M_PI;
enterCar($rescue);
$pose = $rescue->car->state();
input($rescue,['actions'=>['pad-rs']]);
expect($rescue->car->state() === $pose,'Stick click leaves an off-road car alone outside a race');
$rescue->race->advance($rescue->course,[0,0,-51],[0,0,-49],0.,1/60,'car',1);
input($rescue,['actions'=>['pad-rs']]);
$placed = $rescue->car->state();
expect(abs($placed['x']) < .001 && abs($placed['z']-40) < .001 && abs($placed['yaw']) < .001
	&& $placed['roadHold'] === 1.5 && $placed['speed'] === 0. && $race($rescue)['splits'] === [],
	'Stick click resets the car facing forward ten meters upstream without crossing the gate');
input($rescue,['actions'=>['pause','pad-rs']]); $rescue->step(.2);
expect($rescue->car->state() === $placed,'Pausing freezes the recovery penalty');
input($rescue,['actions'=>['pause'],'keys'=>['KeyW'=>true,'ShiftLeft'=>true]]);
for($i=0;$i<90;$i++) $rescue->step(1/60);
$ready = $rescue->car->state();
expect($ready['roadHold'] === 0. && $ready['z'] === $placed['z'] && $ready['nitrous'] === $placed['nitrous']
	&& $race($rescue)['time'] > 1.4 && $race($rescue)['splits'] === [],'Recovery costs race time and holds the car even at full boost');
$rescue->step(1/60);
$moving = $rescue->car->state();
input($rescue,['actions'=>['pad-rs']]);
expect($moving['speed'] > 0. && $moving === $rescue->car->state(),'Held pedals work after the delay and right click does nothing on pavement');
$rescue->close();

// The last leg crosses the spline seam. A car beside a later part of the lap must recover on the approach.
$track = new Circuit([[0.,0.],[0.,100.],[80.,100.],[80.,-60.],[0.,-60.]],-5.,null);
$ground = new Landscape($open);
$gates = new Course;
$gates->place('start','road',0,0,20,0);
$gates->place('checkpoint','road',80,0,60,M_PI);
$run = new Race;
$run->advance($gates,[0,0,19],[0,0,21],0.,1.,'car',1);
$run->advance($gates,[80,0,61],[80,0,59],1.,1.,'car',1);
$testCar = new Car;
foreach([[60.,10.],[-10.,-10.]] as [$beside,$expected]) {
	$testCar->spawn($testCar->checkedSite($ground,-5,-15,$beside,0.,[]),$ground,-5);
	$site = TerrainSandbox\RoadRecovery::site($testCar,$ground,-5,$gates,$run,$track);
	expect($site !== null && abs($site[0])<.001 && abs($site[1]-$expected)<.001 && abs($site[2])<.001,
		'The wraparound leg picks the closest eligible point, including the interior of a straight');
}
$ground->close();

// A loop with a finish gate and the lap setting at 2, which a course with a finish gate leaves aside: the race runs
// once. The car passes the finish gate early in the lap and the start gate late in it, and neither counts out of
// order.
$loop = new Session($open);
$s = click($loop, 'start', 0, 20);
$c = click($loop, 'checkpoint', 60, 60, M_PI);
$f = click($loop, 'finish', 0, 60);
input($loop, ['values' => ['gateLaps' => 2]]);
$loop->camera->position = [0, 30, -30]; $loop->camera->yaw = M_PI;
enterCar($loop);
$route = [[0, 110], [60, 110], [60, -30], [0, -30]];
$events = [];
$track = function(Session $session) use (&$events): bool {
	if(end($events) !== $session->message) $events[] = $session->message;
	return $session->race->state === 'finished';
};
autopilot($loop, $route, 9000, $track);
$lapped = $race($loop);
expect($lapped['state'] === 'finished' && $lapped['laps'] === 1
	&& array_column($lapped['splits'], 'gate') === [$c['id'], $f['id']]
	&& array_column($lapped['splits'], 'lap') === [1, 1] && $lapped['lapTimes'] === [$lapped['time']],
	'A course with a finish gate runs once to it, passing the start gate on the way without counting it');
expect(array_slice($events, 1) === ['Race started. Next: checkpoint 1.',
	'Checkpoint 1 at ' . Race::clock($lapped['splits'][0]['time']) . '. Next: the finish gate.',
	'Finished in ' . Race::clock($lapped['time']) . '.'], 'Each gate passed in order reports its split and the next gate');
$loop->close();

// The same loop with the checkpoint facing north, so the car passes it the wrong way, and no finish gate.
$wrong = new Session($open);
$s = click($wrong, 'start', 0, 20);
$c = click($wrong, 'checkpoint', 60, 60, 0);
$wrong->camera->position = [0, 30, -30]; $wrong->camera->yaw = M_PI;
enterCar($wrong);
$passes = 0;
autopilot($wrong, $route, 9000, function(Session $session) use (&$passes): bool {
	$car = $session->car->state();
	if($session->race->state === 'running' && $car['z'] < -20) $passes++;
	return $passes === 1;
});
$stuck = $race($wrong);
expect($stuck['state'] === 'running' && $stuck['splits'] === [] && $stuck['next']['id'] === $c['id']
	&& $stuck['remaining'] === 2, 'Passing a checkpoint the wrong way does not count');
autopilot($wrong, $route, 2400, fn(Session $session): bool => $session->car->state()['z'] > 40);
expect($race($wrong)['splits'] === [] && $race($wrong)['next']['id'] === $c['id'],
	'Nor does passing the start gate again before the checkpoint');
$wrong->close();

// A course without checkpoints ends each lap at the gate it began from. Passing that gate again counts only once the
// vehicle has been well away from it, so reversing out of a lone start gate and rolling through it again counts no lap.
$wiggle = new Session($open);
input($wiggle, ['values' => ['gateLaps' => 2]]);
$s = click($wiggle, 'start', 0, 20);
$wiggle->camera->position = [0, 30, 0]; $wiggle->camera->yaw = M_PI;
enterCar($wiggle);
$states = [];
for($i = 0; $i < 3; $i++)
{
	drive($wiggle, ['KeyW' => true], 600, fn(Session $session): bool => $session->car->state()['z'] > 24);
	drive($wiggle, ['KeyS' => true], 900, fn(Session $session): bool => $session->car->state()['z'] < 16);
	$states[] = $wiggle->race->state;
}
drive($wiggle, ['KeyW' => true], 600, fn(Session $session): bool => $session->car->state()['z'] > 24);
$rocked = $race($wiggle);
expect($states === ['running', 'running', 'running'] && $rocked['state'] === 'running' && $rocked['lapTimes'] === []
	&& $rocked['splits'] === [] && $rocked['lap'] === 1,
	'Rocking back and forth through a lone start gate counts no lap: ' . json_encode([$states, $rocked['lapTimes']]));
autopilot($wiggle, [[0, 110], [60, 110], [60, -30], [0, -30]], 9000, $state('finished'));
expect($race($wiggle)['state'] === 'finished' && count($race($wiggle)['lapTimes']) === 2,
	'Driving round and back through the start gate counts each lap');
$wiggle->close();
// The rule in numbers, on a lone start gate with one lap. Each step is one second of travel along +Z.
$course = new Course;
$course->place('start', 'road', 0, 0, 0, 0);
$timer = new Race;
$reach = Race::LEAVE * $road['extent']['halfWidth'];
$segment = fn(float $from, float $to, float $time): ?string
	=> $timer->advance($course, [0, 1, $from], [0, 1, $to], $time, 1.0, 'car', 1);
$events = [$segment(-1, 1, 0), $segment(1, $reach - .01, 1), $segment($reach - .01, -1, 2), $segment(-1, 1, 3),
	$segment(1, $reach, 4), $segment($reach, -1, 5), $segment(-1, 1, 6)];
expect($events === ['Race started. Next: the start gate to finish.', null, null, null, null, null, 'Finished in 6.00 s.']
	&& abs($reach - 22.746) < .001, 'A lap back to the same road gate counts once the car has been 22.7 m from it');
// A checkpoint just clear of the start gate, 1.1 m behind or ahead of it, gives rocking no lap either: a lap ends at
// the start gate once the car has been 22.7 m from it since it last passed through.
foreach([-1.1, 1.1] as $behind)
{
	$course = new Course;
	$course->place('start', 'road', 0, 0, 0, 0, 5);
	$checkpoint = $course->place('checkpoint', 'road', 0, 0, $behind, 0)['gate'];
	$timer = new Race;
	$time = 0.0;
	$segment = function(float $from, float $to) use ($course, $timer, &$time): ?string {
		$event = $timer->advance($course, [0, 1, $from], [0, 1, $to], $time, 1.0, 'car', 1);
		$time++;
		return $event;
	};
	for($rock = 0; $rock < 20; $rock++) { $segment(-3, 3); $segment(3, -3); }
	$rocked = $timer->status($course, $time);
	expect($checkpoint !== null && $rocked['state'] === 'running' && $rocked['lapTimes'] === [],
		"Rocking through the start gate and a checkpoint $behind m from it counts no lap: "
			. json_encode($rocked['lapTimes']));
	$segment(-3, 3); $segment(3, $reach); $segment($reach, -3); $segment(-3, 3);
	$lapped = $timer->status($course, $time);
	expect(count($lapped['lapTimes']) === 1 && $lapped['lap'] === 2,
		'Once the car has been 22.7 m from the start gate, the lap counts: ' . json_encode($lapped['lapTimes']));
}
// Gates the route passes in turn are unaffected. Out and back along one road: the car passes the turnaround
// checkpoint, turns beyond it and drives back through its window the other way to a finish gate behind the start.
$return = new Session($open);
click($return, 'start', 0, 20);
$c = click($return, 'checkpoint', 0, 150);
click($return, 'finish', 0, -60, M_PI);
$return->camera->position = [0, 30, 0]; $return->camera->yaw = M_PI;
enterCar($return);
$against = 0;
$previous = $return->car->state();
autopilot($return, [[0, 200], [-20, 215], [-8, 185], [0, 160], [0, -120]], 12000,
	function(Session $session) use (&$against, &$previous, $c, $roadWindow, $state): bool {
		$car = $session->car->state();
		if(Race::crossing(['yaw' => $c['yaw'] + M_PI] + $c, $roadWindow, [$previous['x'], $previous['y'], $previous['z']],
			[$car['x'], $car['y'], $car['z']]) !== null) $against++;
		$previous = $car;
		return $state('finished')($session);
	});
expect($against === 1 && $race($return)['state'] === 'finished'
	&& array_column($race($return)['splits'], 'role') === ['checkpoint', 'finish'],
	'Driving back through a checkpoint\'s window the other way leaves an out-and-back course to finish');
$return->close();

// Relocations do not pass gates. A freshly made car stands at the origin, behind this start gate; boarding beyond the
// gate moves it there without driving through.
$moves = new Session($open);
$s = click($moves, 'start', 0, 20);
expect(Race::crossing($s, $roadWindow, [0, 0, 0], [0, .02, 30]) !== null, 'The boarding jump would pass the gate if it counted');
$moves->camera->position = [0, 30, 30]; $moves->camera->yaw = M_PI;
enterCar($moves);
for($i = 0; $i < 30; $i++) $moves->step(1 / 60);
expect($moves->car->state()['z'] === 30.0 && $race($moves)['state'] === 'idle', 'Boarding a car past the start gate does not start a race');
input($moves, ['actions' => ['fly']]);
$moves->camera->position = [0, 30, 0]; $moves->camera->yaw = M_PI;
enterCar($moves);
drive($moves, ['KeyW' => true], 300, $state('running'));
expect($race($moves)['state'] === 'running', 'Driving through it from behind does');
input($moves, ['actions' => ['fly']]);
expect($race($moves)['state'] === 'idle' && $moves->message === 'Flight controls active. The race stopped when you left the car.',
	'Leaving the car stops the race');
// Home leaves the car without a message of its own. The reason the race stopped replaces the race's last event
// rather than following it, which read "Race started. Next: the start gate to finish. The race stopped when...".
$moves->camera->position = [0, 30, 0]; $moves->camera->yaw = M_PI;
enterCar($moves);
drive($moves, ['KeyW' => true], 300, $state('running'));
input($moves, ['actions' => ['reset-camera']]);
expect($race($moves)['state'] === 'idle' && $moves->message === 'The race stopped when you left the car.',
	'Leaving the car with Home says only why the race stopped: ' . $moves->message);
$moves->camera->position = [0, 30, 0]; $moves->camera->yaw = M_PI;
enterCar($moves);
drive($moves, ['KeyW' => true], 300, $state('running'));
input($moves, ['actions' => ['gates-clear']]);
expect($race($moves)['state'] === 'idle' && $moves->message === 'Removed the only gate. The race stopped because the course changed.',
	'Clearing the gates stops the race');
$moves->close();

// Righting a wreck can move the car back to its last safe spot. Here that jump runs forward through a start gate the
// car drove through the wrong way before it crashed.
$dry = ['worldSize' => 512, 'spacing' => 1, 'heightScale' => 0, 'detailAmplitude' => 0, 'waterLevel' => -100];
$crash = new Session($dry);
$crash->landscape->queue(['type' => 'raise', 'x' => 0, 'z' => 45, 'radius' => 16, 'amount' => 30]);
$crash->landscape->commit();
$s = click($crash, 'start', 0, 31, M_PI);
$crash->camera->position = [0, 30, -54]; $crash->camera->yaw = M_PI;
enterCar($crash);
drive($crash, ['KeyW' => true], 500, fn(Session $session): bool => $session->car->state()['motion'] !== 'driving');
drive($crash, [], 1300, fn(Session $session): bool => $session->car->state()['motion'] !== 'tumbling');
$wreck = $crash->car->state();
expect($wreck['motion'] === 'overturned' && $race($crash)['state'] === 'idle', 'The car crashes on its roof beyond the gate');
$crash->landscape->queue(['type' => 'crater', 'x' => $wreck['x'], 'z' => $wreck['z'], 'radius' => 6, 'amount' => 4]);
$crash->landscape->commit();
$jumped = null;
drive($crash, [], 3000, function(Session $session) use (&$jumped, &$wreck): bool {
	$car = $session->car->state();
	if($car['recoveries'] === 0) { $wreck = $car; return false; }
	$jumped = $car;
	return true;
});
expect($jumped !== null && hypot($jumped['x'] - $wreck['x'], $jumped['z'] - $wreck['z']) > 20
	&& Race::crossing($crash->course->start(), $crash->course->measure($crash->course->start())['window'],
		[$wreck['x'], $wreck['y'], $wreck['z']],
		[$jumped['x'], $jumped['y'], $jumped['z']]) !== null,
	'Settling in a crater moves the wreck back through the start gate the way it faces');
expect($race($crash)['state'] === 'idle', 'A car moved by recovery does not pass a gate');
$crash->close();

// The F5 through air gates at 260 m/s. The gates stand so that the jet's 75 m boarding height meets the middle of
// each window.
$plain = ['worldSize' => 8192, 'heightScale' => 0, 'detailAmplitude' => 0];
$flight = new Session($plain);
$lift = 75 - ($air['window']['bottom'] + $air['window']['top']) / 2;
$s = click($flight, 'start', 0, 0, 0, $lift);
$side = click($flight, 'checkpoint', $air['window']['halfWidth'] + 1, 700, 0, $lift);
expect($s['kind'] === 'air' && abs($s['y'] - $lift) < 1e-6, 'The air gates float at their height above the plain');
$flight->camera->position = [0, 75, -1000]; $flight->camera->yaw = M_PI;
input($flight, ['actions' => ['jet'], 'keys' => ['KeyW' => true]]);
$from = null;
drive($flight, ['KeyW' => true], 600, function(Session $session) use (&$from, $state): bool {
	if($state('running')($session)) return true;
	$from = $session->jet->state();
	return false;
});
$to = $flight->jet->state();
expect($from['speed'] === Jet::FASTEST && $to['z'] - $from['z'] > 4.3
	&& abs($race($flight)['time'] - ($to['z'] - $s['z']) / ($to['z'] - $from['z']) / 60) < 1e-9,
	'At full speed the jet starts the clock where its 4.3 m step meets the start gate');
drive($flight, ['KeyW' => true], 900, fn(Session $session): bool => $session->jet->state()['z'] > 800);
expect($race($flight)['splits'] === [] && $race($flight)['next']['id'] === $side['id'],
	'A jet passing just beside a window does not pass the gate');
$flight->close();
$flight = new Session($plain);
$s = click($flight, 'start', 0, 0, 0, $lift);
$c = click($flight, 'checkpoint', 0, 1500, 0, $lift);
$f = click($flight, 'finish', 0, 2500, 0, $lift);
$flight->camera->position = [0, 75, -1000]; $flight->camera->yaw = M_PI;
input($flight, ['actions' => ['jet']]);
drive($flight, ['KeyW' => true], 1200, $state('finished'));
$flown = $race($flight);
expect($flown['state'] === 'finished' && array_column($flown['splits'], 'gate') === [$c['id'], $f['id']]
	&& abs($flown['time'] - 2500 / Jet::FASTEST) < 1e-6 && $flown['vehicle'] === 'jet',
	'The jet finishes 2500 m on in 2500 / 260 seconds');
// Board again on the same line: the race starts afresh at the start gate. The jet then climbs over the other gates
// and flies on to the map edge, which ends the race.
input($flight, ['actions' => ['fly']]);
$flight->camera->position = [0, 75, -1000]; $flight->camera->yaw = M_PI;
input($flight, ['actions' => ['jet']]);
drive($flight, ['KeyW' => true], 600, $state('running'));
input($flight, ['actions' => ['jet-controls']]);
expect($race($flight)['state'] === 'running' && $flight->jet->expert(), 'Switching the jet handling keeps the race going');
input($flight, ['actions' => ['jet-controls']]);
drive($flight, ['KeyW' => true, 'KeyE' => true], 3000, fn(Session $session): bool => $session->mode === 'fly');
expect($race($flight)['state'] === 'idle' && str_ends_with($flight->message, 'The race stopped when you left the F5.'),
	'Reaching the map edge ends the jet\'s race');
$flight->close();

// The jet ski through floating gates on a lake with the sandbox's swell.
$lake = ['worldSize' => 512, 'waterLevel' => 5, 'heightScale' => 0, 'detailAmplitude' => 0];
$bay = new Session($lake);
$s = click($bay, 'start', 0, -100);
$c = click($bay, 'checkpoint', 0, 0);
$f = click($bay, 'finish', 0, 100);
expect($s['kind'] === 'water' && $bay->message === 'Placed the finish gate at 0, 100 m, floating on the water, its opening 8.9 by 3.6 m.'
	&& abs($c['y'] - $bay->water->sample($c['x'], $c['z'], $bay->time)['height']) < 1e-12
	&& $bay->course->export()['gates'][1]['y'] === 5.0,
	'A click on water places a gate that floats on the surface and saves the water level');
// The click aims 20 m ahead of the camera, so the ray meets the water well before the lake bed.
$bay->camera->position = [0, 40, -60]; $bay->camera->yaw = M_PI; $bay->camera->pitch = -atan2(35, 20);
input($bay, ['values' => ['gateRole' => 'checkpoint'], 'clicks' => [[.5, .5]]]);
finishPicking($bay);
$ahead = $bay->course->gate(max(array_column($bay->course->gates(), 'id')));
expect(abs($ahead['z'] + 40) < 1e-6 && $ahead['kind'] === 'water', 'A water gate stands where the ray meets the water');
input($bay, ['values' => ['gateRole' => 'remove'], 'clicks' => [[.5, .5]]]);
finishPicking($bay);
$heights = [];
$bay->camera->position = [0, 20, -150]; $bay->camera->yaw = M_PI;
input($bay, ['actions' => ['craft']]);
drive($bay, ['KeyW' => true], 3000, function(Session $session) use (&$heights, $c, $state): bool {
	$gate = $session->course->gate($c['id']);
	$heights[] = $gate['y'] - $session->water->sample($gate['x'], $gate['z'], $session->time)['height'];
	return $state('finished')($session);
});
$ridden = $race($bay);
expect($ridden['state'] === 'finished' && array_column($ridden['splits'], 'gate') === [$c['id'], $f['id']]
	&& $ridden['vehicle'] === 'craft', 'The jet ski races through the floating gates');
$bob = array_column(array_slice($race($bay)['splits'], 0, 0), 'time');
expect(max(array_map('abs', $heights)) < 1e-12, 'Each step lifts the floating gates to the wave height at their position');
$range = [];
for($i = 0; $i < 240; $i++) { $bay->step(1 / 60); $range[] = $bay->course->gate($c['id'])['y']; }
expect(max($range) - min($range) > .3, 'The floating gates rise and fall with the swell');
// A floating gate rides at the wave height at its center, so the swell can leave the water at a foot lower than
// that. At the panel's highest choppiness, every corner of each foot must still reach under the water.
input($bay, ['values' => ['waterChoppiness' => 300]]);
['window' => $opening, 'extent' => $reach] = $bay->gateShapes->kind('water');
$exposed = -INF;
for($yaw = 0; $yaw < 180; $yaw += 10)
{
	$cos = cos(deg2rad($yaw)); $sin = sin(deg2rad($yaw));
	for($along = 0; $along < 34; $along += 4)
		for($t = 0; $t < 12; $t += .1)
		{
			$feet = $bay->water->sample($along, 0, $t)['height'] + $reach['bottom'];
			foreach([-$reach['halfWidth'], -$opening['halfWidth'], $opening['halfWidth'], $reach['halfWidth']] as $x)
				foreach([-$reach['halfDepth'], $reach['halfDepth']] as $z)
					$exposed = max($exposed,
						$feet - $bay->water->sample($along + $x * $cos + $z * $sin, -$x * $sin + $z * $cos, $t)['height']);
		}
}
expect($exposed < 0, sprintf('The water gate\'s feet stay in the water at 300%% choppiness (highest foot %.2f m)', $exposed));
input($bay, ['actions' => ['pause'], 'values' => ['waterLevel' => 9, 'waterChoppiness' => 0]]);
expect($bay->course->gate($c['id'])['y'] === 9.0 && $bay->status()['course']['gates'][1]['y'] === 9.0,
	'A paused water level change moves the floating gates at once');
input($bay, ['actions' => ['pause', 'race-reset']]);
// Board again behind the start gate and race, then board again mid-race.
input($bay, ['values' => ['waterChoppiness' => 100]]);
$bay->camera->position = [0, 20, -150]; $bay->camera->yaw = M_PI;
input($bay, ['actions' => ['craft']]);
drive($bay, ['KeyW' => true], 600, $state('running'));
input($bay, ['actions' => ['craft']]);
expect($race($bay)['state'] === 'idle' && str_ends_with($bay->message, 'The race stopped because the jet ski moved to a new spot.'),
	'Boarding the jet ski again mid-race stops the race');
$bay->close();
// A gate click still finding the ground when the player boards the jet ski is dropped, as boarding the car or the jet
// drops one, so no gate lands under a vehicle that is already moving.
$late = new Session($lake);
$late->camera->position = [0, 40, -100]; $late->camera->yaw = M_PI; $late->camera->pitch = -.3;
$late->input(['values' => ['tool' => 'gate', 'gateRole' => 'start'], 'clicks' => [[.5, .5]]], 1 / 60, 16 / 9, .0001);
$pending = $late->picking;
$late->input(['actions' => ['craft']], 1 / 60, 16 / 9, .0001);
for($i = 0; $i < 100; $i++) input($late, []);
expect($pending && $late->mode === 'craft' && !$late->picking && $late->course->count() === 0
	&& str_starts_with($late->message, 'W or RT throttle'), 'Boarding the jet ski drops a gate click still in progress');
$late->close();

// Courses save with the landscape.
$file = new Session($open);
$s = click($file, 'start', 0, 20, .5);
click($file, 'checkpoint', 0, 100);
click($file, 'checkpoint', 50, 150, 0, 30);
click($file, 'finish', 0, 180, -1);
input($file, ['values' => ['gateLaps' => 3, 'gateRole' => 'checkpoint', 'gateAltitude' => 30]]);
// Loading holds the camera within the look limits, which the straight-down clicks exceed.
$file->camera->pitch = -.4;
$saved = $file->save();
$data = json_decode($saved, true, flags: JSON_THROW_ON_ERROR);
expect($data['version'] === 5 && count($data['course']['gates']) === 4 && $data['course']['gates'][0]['laps'] === 3
	&& !isset($data['course']['gates'][0]['id']) && $data['course']['gates'][2]['number'] === 2
	&& $data['course']['gates'][2]['width'] === 2 * $air['window']['halfWidth'],
	'Version 3 files carry the course: every gate, its laps, its checkpoint numbers and its size');
$copy = new Session($open);
$copy->load($saved);
expect($copy->course->export() === $file->course->export() && $copy->course->signature() === $file->course->signature()
	&& $copy->settings['gateLaps'] === 3 && $copy->settings['gateAltitude'] === 30.0
	&& array_column($copy->status()['course']['gates'], 'id') === [1, 2, 3, 4]
	&& $copy->message === 'Landscape loaded, including terrain edits, environment settings and 4 gates.',
	'Loading restores the course, its laps and the gate settings');
expect($copy->save() === $saved, 'A loaded course saves back byte for byte');
// A file's lap count follows its start gate.
$data['settings']['gateLaps'] = 7;
$copy->load(json_encode($data));
expect($copy->course->start()['laps'] === 3 && $copy->settings['gateLaps'] === 3, 'The start gate\'s laps win over the lap setting');
$legacy = $data;
$legacy['version'] = 1;
unset($legacy['course'], $legacy['settings']['gateRole'], $legacy['settings']['gateLaps'], $legacy['settings']['gateAltitude'],
	$legacy['settings']['gateWidth'], $legacy['settings']['gateHeight']);
$copy->load(json_encode($legacy));
expect($copy->course->count() === 0 && $copy->status()['course']['gates'] === [] && $copy->settings['gateLaps'] === 3,
	'A version 1 file loads without gates and leaves the gate settings alone');
$bare = $data;
unset($bare['course']);
$copy->load(json_encode($bare));
expect($copy->course->count() === 0, 'A file without a course loads without gates');
$copy->load($saved);
$intact = $copy->save();
$generation = $copy->generation;
$gate = $data['course']['gates'];
$malformed = [
	'a course that is a list' => [['role' => 'start']],
	'gates that are not a list' => ['gates' => ['a' => $gate[0]]],
	'an unknown course key' => ['gates' => $gate, 'lap' => 2],
	'an unknown role' => ['gates' => [['role' => 'pit'] + $gate[1]]],
	'an unknown kind' => ['gates' => [['kind' => 'lava'] + $gate[1]]],
	'an extra gate key' => ['gates' => [$gate[1] + ['color' => 'red']]],
	'a start gate without laps' => ['gates' => [array_diff_key($gate[0], ['laps' => 0])]],
	'laps on a checkpoint' => ['gates' => [$gate[1] + ['laps' => 2]]],
	'no laps' => ['gates' => [['laps' => 0] + $gate[0]]],
	'too many laps' => ['gates' => [['laps' => 21] + $gate[0]]],
	'fractional laps' => ['gates' => [['laps' => 2.5] + $gate[0]]],
	'a string coordinate' => ['gates' => [['x' => '1'] + $gate[1]]],
	'a gate off the landscape' => ['gates' => [['z' => 513] + $gate[1]]],
	'a gate out of height range' => ['gates' => [['y' => 1e6] + $gate[1]]],
	'a yaw beyond pi' => ['gates' => [['yaw' => 4] + $gate[1]]],
	'two start gates' => ['gates' => [$gate[0], $gate[0]]],
	'two finish gates' => ['gates' => [$gate[3], $gate[3]]],
	'a gap in the checkpoint numbers' => ['gates' => [$gate[1], ['number' => 3] + $gate[2]]],
	'a repeated checkpoint number' => ['gates' => [$gate[1], ['number' => 1] + $gate[2]]],
	'a fractional checkpoint number' => ['gates' => [['number' => 1.5] + $gate[1]]],
	'33 checkpoints' => ['gates' => array_map(fn($n) => ['number' => $n] + $gate[1], range(1, 33))],
	'a gate that is a list' => ['gates' => [array_values($gate[1])]],
	'a width without a height' => ['gates' => [array_diff_key($gate[1], ['height' => 0])]],
	'a string width' => ['gates' => [['width' => '20'] + $gate[1]]],
	'a road gate too wide' => ['gates' => [['width' => 61] + $gate[1]]],
	'a road gate too low' => ['gates' => [['height' => 1] + $gate[1]]],
];
foreach($malformed as $name => $course)
{
	$bad = $data;
	$bad['course'] = $course;
	try { $copy->load(json_encode($bad)); expect(false, "A file with $name must be refused"); }
	catch(InvalidArgumentException $error)
	{
		expect(str_starts_with($error->getMessage(), 'Invalid saved course: ') && $copy->save() === $intact
			&& $copy->generation === $generation, "A file with $name is refused and leaves the scene intact");
	}
}
$future = $data;
$future['version'] = $data['version'] + 1;
try { $copy->load(json_encode($future)); expect(false, 'An unsupported file version must be refused'); }
catch(InvalidArgumentException) { expect($copy->save() === $intact, 'A file from a later version is refused'); }
// The page loads files through an action, which reports the problem instead of throwing.
$bad = $data;
$bad['course']['gates'][0]['laps'] = 99;
input($copy, ['actions' => [['type' => 'load', 'json' => json_encode($bad)]]]);
expect($copy->message === 'Invalid saved course: laps must be a whole number from 1 to 20.' && $copy->save() === $intact,
	'A refused course says why');
// Loading in the middle of a race stops it, and a best time follows the course into the file and back.
input($copy, ['actions' => ['gates-clear']]);
click($copy, 'start', 0, 20);
click($copy, 'finish', 0, 120);
input($copy, ['values' => ['gateLaps' => 1]]);
$copy->camera->position = [0, 30, -10]; $copy->camera->yaw = M_PI;
enterCar($copy);
drive($copy, ['KeyW' => true], 3000, $state('finished'));
$best = $race($copy)['best'];
$one = $copy->save();
$copy->camera->position = [0, 30, -10]; $copy->camera->yaw = M_PI;
input($copy, ['actions' => ['fly']]);
enterCar($copy);
drive($copy, ['KeyW' => true], 600, $state('running'));
$copy->load($one);
expect($race($copy)['state'] === 'idle' && $copy->mode === 'fly' && $best !== null && $race($copy)['best'] === $best,
	'Loading stops a race, and the reloaded course keeps its best time for the session');
input($copy, ['actions' => [['type' => 'generate', 'preset' => 'coast', 'seed' => 'no-gates']]]);
expect($copy->course->count() === 0 && str_ends_with($copy->message, 'The new landscape starts without gates.'),
	'Generating a new landscape clears its course');
$copy->close();
$file->close();

// Road gate posts are solid for the car. The gate faces across the car's line, so one post stands in its path.
$wide = ['worldSize' => 8192, 'heightScale' => 0, 'detailAmplitude' => 0, 'waterLevel' => -5];
$wall = new Session($wide);
$gate = click($wall, 'checkpoint', 0, 400, M_PI / 2);
$posts = $wall->course->posts();
usort($posts, fn($a, $b) => $a[1] <=> $b[1]);
$post = $posts[0];
expect(count($posts) === count($road['posts']) && abs($post[0] - $gate['x']) < 1e-9
	&& abs($post[1] - (400 - abs($road['posts'][0]['x']))) < .1 && abs($post[5] - ($gate['y'] - Course::POST_FOOT)) < 1e-9,
	'Each road post stands at its place across the gate and reaches into the ground');
$wall->camera->position = [0, 30, -3500]; $wall->camera->yaw = M_PI;
enterCar($wall);
$overlap = 0.0; $fastest = 0.0; $impact = null; $topSpeedTicks = 0;
$front = $post[1] - $post[2] - Car::BODY_FRONT;
$deepest = function(Session $session) use (&$overlap, $posts): array {
	$car = $session->car->state();
	foreach($posts as $p) $overlap = max($overlap, Car::penetration($car['x'], $car['z'], $car['yaw'], $p)[0] ?? 0.0);
	return $car;
};
drive($wall, ['KeyW' => true], 4800, function(Session $session) use ($deepest, &$fastest, &$impact, &$topSpeedTicks): bool {
	$car = $deepest($session);
	if($impact === null && $car['speed'] < $fastest - 5) $impact = ['before' => $fastest, 'after' => $car];
	$fastest = max($fastest, $car['speed']);
	if(abs($car['speed'] - 107.2896) < 1e-6) $topSpeedTicks++;
	return $impact !== null && $car['speed'] > 0 && abs($car['speed']) < .1 && $car['z'] > $impact['after']['z'] + .1;
});
expect($topSpeedTicks >= 60 && abs($fastest - 107.2896) < 1e-6, 'The car reaches and holds 240 mph on level ground');
expect($impact !== null && abs($impact['before'] - 107.2896) < 1e-6 && $impact['after']['speed'] < -5 && $overlap < 1e-3
	&& abs($impact['after']['z'] - $front) < .01, 'At 240 mph the car stops at the post and bounces back');
drive($wall, ['KeyW' => true], 120, fn(Session $session): bool => $deepest($session) && false);
$pressing = $wall->car->state();
expect($pressing['z'] <= $front + 1e-3 && $pressing['z'] > $front - .05 && abs($pressing['speed']) < .3 && $overlap < 1e-3,
	'Holding the throttle against the post leaves the car resting on it');
drive($wall, ['KeyS' => true], 600, fn(Session $session): bool => $deepest($session)['z'] < $front - 6);
drive($wall, ['KeyW' => true, 'KeyA' => true], 600, fn(Session $session): bool => $deepest($session)['yaw'] > .6);
drive($wall, ['KeyW' => true, 'KeyD' => true], 600, fn(Session $session): bool => $deepest($session)['yaw'] < 0);
$away = $wall->car->state();
expect($away['z'] > $post[1] + 5 && $away['motion'] === 'driving' && $overlap < 1e-3,
	'Backing off and steering round, the car drives on past the post');
$wall->close();
// A glancing blow. The car runs past a post with 13 cm to spare and steers toward it, so its side scrapes the post's
// face. The same drive without the gate shows what the post took.
$scrapes = [];
foreach([true, false] as $withPost)
{
	$scrape = new Session($wide);
	// The gate stands across the car's line with one post at x = 0, z = 400 and the other 14 m away.
	if($withPost) $scrape->course->place('checkpoint', 'road', $road['posts'][0]['x'], 0, 400, 0);
	$posts = $scrape->course->posts();
	$scrape->camera->position = [Car::BODY_HALF_WIDTH + $road['posts'][1]['halfSize'] + .13, 30, 385]; $scrape->camera->yaw = M_PI;
	enterCar($scrape);
	$overlap = 0.0;
	drive($scrape, ['KeyW' => true], 600, fn(Session $session): bool => $session->car->state()['z'] > 397);
	drive($scrape, ['KeyW' => true, 'KeyD' => true], 40, function(Session $session) use (&$overlap, $posts): bool {
		$car = $session->car->state();
		foreach($posts as $p) $overlap = max($overlap, Car::penetration($car['x'], $car['z'], $car['yaw'], $p)[0] ?? 0.0);
		return false;
	});
	$scrapes[] = $scrape->car->state() + ['overlap' => $overlap, 'posts' => count($posts)];
	$scrape->close();
}
[$scraped, $clean] = $scrapes;
expect($scraped['posts'] === 2 && $scraped['overlap'] < 1e-3 && $scraped['z'] > 405 && $scraped['motion'] === 'driving'
	&& $scraped['speed'] > .9 * $clean['speed'] && $scraped['speed'] < $clean['speed'] && $scraped['x'] > $clean['x'] + .05,
	sprintf('A car that scrapes a post slides along its face, pushed %.2f m aside and %.2f m/s slower',
		$scraped['x'] - $clean['x'], $clean['speed'] - $scraped['speed']));
// Boarding beside a post finds a spot clear of it.
$park = new Session($open);
$gate = click($park, 'start', 0, 20);
$posts = $park->course->posts();
$park->camera->position = [$posts[1][0], 30, 20]; $park->camera->yaw = M_PI / 2;
enterCar($park);
$car = $park->car->state();
expect(hypot($car['x'] - $posts[1][0], $car['z'] - 20) > 1 && Car::penetration($car['x'], $car['z'], $car['yaw'], $posts[1]) === null,
	'Boarding over a post moves the car to clear ground');
$park->close();
// A tumbling body pushed out of a post loses the speed that carried it in.
$body = new TerrainSandbox\CarTumble(0, 0, 0, 0, 0, 0, 10, 0, 0, 0);
$body->push(0, -.1, 0, -1, .25);
expect(abs($body->pose()[2] + .1) < 1e-12 && abs($body->pose()[5] + 2.5) < 1e-12 && !$body->settled,
	'A tumbling car that meets a post bounces back off it');

// Gates elsewhere leave the car's path bit for bit as it was: the existing driving checks pin that path exactly.
$paths = [];
foreach(['none', 'air and water', 'far road'] as $layout)
{
	$session = new Session($open);
	if($layout === 'air and water')
	{
		$session->course->place('checkpoint', 'air', 0, 30, 60, 0);
		$session->course->place('finish', 'water', 0, -5, 90, 0);
	}
	if($layout === 'far road') $session->course->place('checkpoint', 'road', 300, 0, 300, 0);
	$session->camera->position = [0, 30, 0]; $session->camera->yaw = M_PI;
	enterCar($session);
	$run = [];
	input($session, ['keys' => ['KeyW' => true, 'KeyD' => true]]);
	for($i = 0; $i < 240; $i++) { $session->step(1 / 60); $run[] = $session->car->state(); }
	input($session, ['keys' => ['KeyS' => true, 'Space' => true, 'KeyA' => true]]);
	for($i = 0; $i < 120; $i++) { $session->step(1 / 60); $run[] = $session->car->state(); }
	$paths[$layout] = $run;
	$session->close();
}
expect($paths['air and water'] === $paths['none'] && $paths['far road'] === $paths['none'],
	'Gates without posts, or with posts out of reach, leave the car\'s path exactly as it was');

// The status gives the page and the renderer everything they draw.
$view = new Session($lake);
$s = click($view, 'start', 0, -100);
$status = $view->status();
expect(array_keys($status['course']) === ['revision', 'laps', 'gates']
	&& array_keys($status['course']['gates'][0]) === ['id', 'role', 'kind', 'print', 'number', 'laps', 'x', 'y', 'z', 'yaw',
		'width', 'height']
	&& array_keys($status['race']) === ['state', 'guidance', 'time', 'lap', 'laps', 'vehicle', 'next', 'remaining', 'splits', 'lastSplit',
		'lapTimes', 'last', 'best', 'message', 'reach'] && is_string(json_encode($status, JSON_THROW_ON_ERROR))
	&& array_keys($status['gateSizes']) === ['road', 'water', 'air'],
	'The status reports the course and the race');
input($view, ['actions' => ['gates-clear', 'race-reset']]);
expect($view->message === 'Race reset. Place a start gate to race.' && $view->status()['race']['next'] === null,
	'Without a start gate the race has nothing to point at');
$view->close();

// Compare the fitted cubics with the original cyclic Catmull–Rom road between its survey stations.
$points = $banks = [];
for($i=0; $i<180; $i++) {
	$a = 2*M_PI*$i/180;
	$points[] = [900*cos($a)+80*sin(3*$a),30*cos(4*$a),650*sin($a)];
	$banks[] = .12*sin(5*$a);
}
$fit = CircuitCurves::sections($points,$banks);
foreach($fit as $_) {}
$curves = $fit->getReturn();
$fitted = array_sum(array_map(fn($r) => count($r['points']),$curves));
expect($fitted<90, 'Smooth, banked laps need fewer than half their original controls');
$cubic = static fn($a,$out,$b,$in,$t) => array_map(static fn($x,$dx,$y,$dy) =>
	(1-$t)**3*$x + 3*$t*(1-$t)**2*($x+$dx) + 3*$t**2*(1-$t)*($y+$dy) + $t**3*$y,$a,$out,$b,$in);
$maxSide = $maxHeight = $maxBank = 0.;
$visited = [];
foreach($curves as $road) {
	for($i=0; $i+1<count($road['points']); $i++) {
		$a = $road['points'][$i]; $b = $road['points'][$i+1];
		$start = array_search($a,$points,true); $end = array_search($b,$points,true);
		$length = ($end-$start+180)%180;
		for($j=0; $j<$length; $j++) {
			$at = ($start+$j)%180; $next = ($at+1)%180;
			$visited[] = $at;
			$out = array_map(fn($p,$q)=>($q-$p)/6,$points[($at+179)%180],$points[$next]);
			$in = array_map(fn($p,$q)=>($p-$q)/6,$points[$at],$points[($next+1)%180]);
			foreach([0.,.25,.5,.75] as $u) {
				$t = ($j+$u)/$length;
				$old = $cubic($points[$at],$out,$points[$next],$in,$u);
				$new = $cubic($a,$road['handles'][$i]['out'],$b,$road['handles'][$i+1]['in'],$t);
				$maxSide = max($maxSide,hypot($old[0]-$new[0],$old[2]-$new[2]));
				$maxHeight = max($maxHeight,abs($old[1]-$new[1]));
				$maxBank = max($maxBank,abs((1-$u)*$banks[$at]+$u*$banks[$next]
					-(1-$t)*$road['banks'][$i]-$t*$road['banks'][$i+1]));
			}
		}
	}
}
sort($visited);
expect($visited === range(0,179) && $maxSide<=3. && $maxHeight<=.5 && $maxBank<=.025,
	'Each source span, including the lap closure, fits the horizontal, vertical and banking limits');

// One lane crests at station zero while the centerline and the approach grades remain gentle.
$roadFixture = new Landscape(['worldSize'=>512,'heightScale'=>0,'detailAmplitude'=>0]);
$roadFixture->queue(['type'=>'material','x'=>0,'z'=>0,'radius'=>220,'material'=>'road']);
$roadFixture->commit();
$loop = new Circuit([[0.,0.],[80.,0.],[80.,70.],[-80.,70.],[-80.,0.]], -10., null);
$check = CircuitRoad::clearance($roadFixture,$loop,-10.);
iterator_to_array($check);
expect($check->getReturn() !== null, 'A level circuit passes the highway crest check');
$roadFixture->queue(['type'=>'raise','x'=>0,'z'=>12,'radius'=>16,'amount'=>2]);
$roadFixture->commit();
$check = CircuitRoad::clearance($roadFixture,$loop,-10.);
iterator_to_array($check);
expect($check->getReturn() === null, 'The highway crest check catches a sharp crest in a side lane across the lap line');
$roadFixture->close();

$generator = new Session;
input($generator, ['actions' => [['type'=>'generate','preset'=>'canyon','seed'=>'first-light']]]);
$generator->course->place('start', 'air', 0, 0, 0, 0);
$original = $generator->course;
input($generator, ['actions' => ['circuit-find']]);
expect($generator->findingCircuit(), 'Find starts a cooperative circuit search');
input($generator, ['actions' => ['circuit-cancel']]);
expect(!$generator->findingCircuit() && $generator->course === $original,
	'Cancel keeps the original course');
input($generator, ['actions' => ['circuit-find']]);
for($i = 0; $generator->findingCircuit() && $i < 6000; $i++) input($generator, []);
$preview = $generator->status()['circuit'];
expect($preview['preview'] && $generator->course === $original && $preview['floodedLevel'] > $preview['dryLevel'],
	'The default canyon previews both variants without replacing a course');
expect($preview['length']>12000 && $preview['featureArea']>10000000 && $preview['gradeWidth']===36.
	&& $generator->landscape->revision()===0, 'Preview finds a major plateau and leaves the original ground intact');
// The search places both variants' gates on its graded copy, a gate at a time, and keeps them for apply to reuse.
$placed = array_keys((fn() => $this->circuitPreview->placed)->call($generator));
$laps = (int)$generator->settings['gateLaps'];
expect(in_array("dry:$laps", $placed, true) && in_array("flooded:$laps", $placed, true),
	'The search keeps the gates it placed for applying either variant');

// A second author edits one generated section before applying the preview.
$edited = new Session($generator->landscape->recipe());
$plan = (fn() => $this->circuitPreview)->call($generator);
(function($plan) { $this->circuitPreview = $plan; })->call($edited,$plan);
$sections = $generator->roadEditor->circuitRoads();
$edited->roadEditor->circuitDrafts($sections);
input($edited,['actions'=>[['type'=>'road-editor','op'=>'open']]]);
$controlCount = array_sum(array_map(fn($r) => count($r['points']),$sections));
expect($controlCount < 253, 'The real canyon lap uses less than half its former 506 visible controls');
[$jx,$jy,$jz] = $sections[0]['points'][0];
input($edited,['actions'=>[['type'=>'road-editor','op'=>'point','generation'=>$edited->generation,
	'revision'=>0,'id'=>-1,'index'=>0,'x'=>$jx+2,'y'=>$jy,'z'=>$jz]]]);
$joined = $edited->roadEditor->circuitRoads();
$lastSection = end($joined);
expect($joined[0]['points'][0] === end($lastSection['points']), 'Moving the lap junction keeps the next section attached');
input($edited,['actions'=>[['type'=>'road-editor','op'=>'revert','generation'=>$edited->generation,'revision'=>0,'id'=>-1]]]);
expect($edited->roadEditor->circuitRoads() === $sections, 'Reverting the junction restores both ends');
[$px,$py,$pz] = $sections[0]['points'][10];
input($edited,['actions'=>[['type'=>'road-editor','op'=>'point','generation'=>$edited->generation,
	'revision'=>0,'id'=>-1,'index'=>10,'x'=>$px+30,'y'=>$py,'z'=>$pz+20]]]);
input($edited,['actions'=>['circuit-dry']]);
while($edited->preparingRoad()) input($edited,[]);
$changed = $edited->takeCandidate();
expect($changed !== null && $changed->roadRecords()[0]['points'][10] === [$px+30,$py,$pz+20],
	'Editing a generated section grades the exact control point the editor shows');
$edited->publishLandscape($changed);
$savedEdit = json_decode($edited->save(),true,flags:JSON_THROW_ON_ERROR);
$editedLine = $savedEdit['circuit']['route']['points'];
$gap = min(array_map(static fn($p) => hypot($p[0]-($px+30),$p[1]-($pz+20)),$editedLine));
expect($gap<12 && $savedEdit['course'] !== $original->export(),
	'The saved race line follows the modified section and publishes its own gates');
$edited->close();

$gateLine = [];
foreach(['dry'=>'car','flooded'=>'craft'] as $variant => $vehicle)
{
	input($generator, ['actions' => ['circuit-' . $variant]]);
	$terrain = $generator->takeCandidate();
	if($variant === 'dry')
	{
		expect($terrain !== null && $generator->course === $original && $generator->landscape->revision()===0,
			'Road preparation leaves the original course and terrain together until publication');
		$generator->publishLandscape($terrain);
		// Each fitted section ends in a rounded earthwork cap. Check the markings on the
		// actual centerline and both road edges, on either side of every default canyon join.
		foreach($terrain->roads() as $section) {
			$samples = $section['_road']['samples'];
			[$x,,$z,$tx,$tz] = $samples[0];
			$terrain->material($x-.1*$tx,$z-.1*$tz,$offset,$before);
			$terrain->material($x+.1*$tx,$z+.1*$tz,$offset,$after);
			expect($before !== null && $after !== null,'The dash crosses the join, including the lap closure');
			$phaseStep = abs((TerrainPhonics\RoadAtlas::markCode($after)&2047) - (TerrainPhonics\RoadAtlas::markCode($before)&2047));
			expect(min($phaseStep,2048-$phaseStep) <= 6,'Dash phase is continuous across the section boundary');
			foreach([...array_slice($samples,0,4), ...array_slice($samples,-4)] as [$x,,$z,$tx,$tz]) {
				foreach([-21.,-17.65,0.,17.65,21.] as $lane) {
					$terrain->material($x-$tz*$lane,$z+$tx*$lane,$offset,$mark);
					expect($mark !== null && abs($mark[0]-$lane)<.15,
						"The joined section carries the correct lateral paint coordinate at ($x, $z), lane $lane");
				}
			}
		}
	}
	else expect($terrain === null, 'Switching to water reuses the same graded route');
	$active = $generator->status()['circuit'];
	$gates = $generator->course->gates();
	expect($active['active'] === $variant && !$active['preview'] && count($gates) >= 8
		&& $generator->course->finish() === null, 'Apply places a start/finish and ordered checkpoints');
	$gateLine[] = array_map(fn(array $g): array => [$g['x'],$g['z'],$g['yaw']], $gates);
	$copy = new Session;
	input($copy, ['actions' => [['type'=>'load','json'=>$generator->save()]]]);
	expect($copy->status()['circuit'] == $active && $copy->course->export() == $generator->course->export(),
		'Save and load preserve the applied circuit and its gate positions');
	if($variant === 'dry')
	{
		$dryGates = $gates;
		$stock = $generator->gateShapes->kind('road')['size']['default'][1];
		expect(array_unique(array_column($gates,'height')) === [2 * $stock],
			'Every dry circuit gate has double the stock road clearance');
		foreach($gates as $gate) foreach([-1,1] as $side) {
			$innerFace = $side*$generator->course->measure($gate)['window']['halfWidth'];
			$generator->landscape->material($gate['x']+$innerFace*cos($gate['yaw']),
				$gate['z']-$innerFace*sin($gate['yaw']),$offset,$mark);
			expect($mark !== null && abs(abs($mark[0])-$mark[2]-CircuitRoad::POST_CLEARANCE)<.01,
				'Both inner post faces clear the painted pavement by 0.75 m, including on bends.');
		}
		// The gantries stand on the road. Against a wall the highest ground across the 60 m span once lifted a banner
		// over 20 m above the deck; only the deck's own bank may raise one now.
		$lift = max(array_map(fn(array $g): float => $g['y'] - $generator->landscape->height($g['x'], $g['z']), $gates));
		expect($lift < 3, "Every dry circuit gantry stands on its road deck ($lift m at most above the centerline)");
		$legacy = json_decode($generator->save(), true, flags: JSON_THROW_ON_ERROR);
		foreach($legacy['course']['gates'] as &$gate) $gate['height'] = $stock;
		unset($gate);
		$copy->load(json_encode($legacy, JSON_THROW_ON_ERROR));
		expect($copy->course->export() === $generator->course->export(), 'A saved stock-height circuit upgrades on load');
		$oldRoute = Circuit::import($legacy['circuit']['route'], $generator->landscape->recipe()['worldSize']/2);
		foreach([30.,60.] as $oldWidth) {
			$legacy['course'] = $oldRoute->course($generator->landscape,$shapes,$generator->course->laps(),false,$oldWidth)->export();
			$copy->load(json_encode($legacy, JSON_THROW_ON_ERROR));
			expect($copy->course->export() === $generator->course->export(), "A saved $oldWidth m circuit refits to the pavement.");
		}
		// Native and WASM hypot/atan2 can differ by a last bit on the same imported racing line.
		$legacy['course']['gates'][4]['z'] += 2.3e-13;
		$legacy['course']['gates'][24]['yaw'] -= 4.4e-16;
		$copy->load(json_encode($legacy, JSON_THROW_ON_ERROR));
		expect($copy->course->export() === $generator->course->export(), 'Roundoff in a saved gate pose restores the canonical layout.');
		$legacy['course']['gates'][1]['x'] += 1;
		try {
			$copy->load(json_encode($legacy, JSON_THROW_ON_ERROR));
			throw new RuntimeException('An altered legacy circuit was accepted');
		} catch(InvalidArgumentException $error) {
			expect($error->getMessage() === 'The saved circuit and its gates disagree.', 'Upgrading still checks gate placement');
		}
	}
	if($vehicle === 'car') enterCar($copy);
	else input($copy, ['actions'=>[$vehicle]]);
	input($copy, ['keys'=>['KeyW'=>true]]);
	for($i = 0; $i < 300 && $copy->race->status($copy->course,$copy->time)['state'] === 'idle'; $i++)
		$copy->step(1/60);
	expect($copy->mode === $vehicle && $copy->race->status($copy->course,$copy->time)['state'] === 'running',
		'The generated launch pose lets the vehicle go straight through the first gate');
	if($vehicle === 'car') {
		$gate = $copy->race->nextGate($copy->course);
		$left = [cos($gate['yaw']),-sin($gate['yaw'])];
		// Walk off the asphalt on either side of the real canyon gate until all tires can stand safely.
		$offRoad = null;
		foreach([35.,-35.,50.,-50.,70.,-70.] as $offset) {
			$x = $gate['x']+$left[0]*$offset; $z = $gate['z']+$left[1]*$offset;
			if($copy->landscape->material($x,$z) === 'road') continue;
			$offRoad = $copy->car->checkedSite($copy->landscape,$copy->water->level(),$x,$z,$gate['yaw'],[]);
			if($offRoad !== null) break;
		}
		expect($offRoad !== null,'An off-road test pose exists beside the generated checkpoint');
		$copy->car->spawn($offRoad,$copy->landscape,$copy->water->level());
		input($copy,['actions'=>['pad-rs']]);
		$back = $copy->car->state();
		$material = $copy->landscape->material($back['x'],$back['z'],$centerOffset,$mark);
		expect($back['roadHold'] > 0 && $material === 'road' && abs($mark[0]) < .1
			&& $copy->race->nextGate($copy->course)['id'] === $gate['id'],
			'Recovery on the saved default canyon finds the paved centerline and retains the outstanding gate');
	}
	$copy->close();
}
foreach($gateLine[0] as $i => [$x,$z,$yaw]) {
	[$wx,$wz,$heading] = $gateLine[1][$i];
	expect($yaw === $heading && abs(($wx-$x)*sin($yaw)+($wz-$z)*cos($yaw)) < 1e-8,
		'The road gates and water arches cross the route at the same station and heading.');
}
$record = json_decode($generator->save(),true);
expect(count($record['landscape']['history'])===1 && $generator->landscape->revision()===1,
	'The whole circuit is one undoable grade even after switching variants');
$route = $record['circuit']['route'];
highwayCrests($generator, new Circuit($route['points'],$route['dryLevel'],null));
// A placed gate stands where the route passes 30 m beyond a fork, not just near where one could.
$routeLine = new Circuit($route['points'],$route['dryLevel'],null);
$snapped = false;
foreach($route['junctions'] as $fork)
{
	[[$sx,$sz]] = $routeLine->stations([$fork+30]);
	foreach($gateLine[0] as $i => [$gx,$gz,$yaw]) {
		$along = ($sx-$gx)*sin($yaw)+($sz-$gz)*cos($yaw);
		$across = ($sx-$gx)*cos($yaw)-($sz-$gz)*sin($yaw);
		if(abs($along) < 1. && abs($across) < $dryGates[$i]['width']/2) $snapped = true;
	}
}
expect($snapped, 'The plateau circuit places a checkpoint 30 m downstream of an identified corridor junction');
expect(max(array_map(static fn(array $edit): float => max(array_map('abs',$edit['banks'])),
	$record['landscape']['history'][0]))>.06, 'Natural embankments supply cross slope to the graded bends');
$generator->close();

$lakeRace = new Session;
input($lakeRace, ['values'=>['circuitRelief'=>-60,'circuitStartHeight'=>100],
	'actions'=>[['type'=>'generate','preset'=>'mountain','seed'=>'first-light'],'circuit-find']]);
for($i = 0; $lakeRace->findingCircuit() && $i<6000; $i++) input($lakeRace, []);
$lakePreview = $lakeRace->status()['circuit'];
expect($lakePreview['preview'] && $lakePreview['heightDifference']<=-60
	&& abs($lakePreview['startHeight']-100)<=.5 && $lakePreview['featureArea']>500000 && $lakeRace->water->level()===0.,
	'A negative requirement finds an enclosed lake under a road with the requested start elevation');
input($lakeRace, ['actions'=>['circuit-dry']]);
$terrain = $lakeRace->takeCandidate();
expect($terrain !== null, 'The lake course previews an ordinary terrain edit');
$lakeRace->publishLandscape($terrain);
$start = $lakeRace->course->start();
highwayCrests($lakeRace, new Circuit($lakePreview['points'],$lakeRace->water->level(),null));
expect(abs($lakeRace->landscape->height($start['x'],$start['z'])-100)<=.5,
	'The dry lake route really starts at the requested height after grading');
$lakeCopy = new Session;
input($lakeCopy, ['actions'=>[['type'=>'load','json'=>$lakeRace->save()]]]);
expect($lakeCopy->settings['circuitRelief']===-60. && $lakeCopy->settings['circuitStartHeight']===100.
	&& $lakeCopy->status()['circuit'] == $lakeRace->status()['circuit'], 'The signed controls and lake route survive reload');
$blank = new Session;
$blankFile = $blank->save();
$blank->close();
$lakeCopy->load($blankFile);
expect($lakeCopy->settings['circuitStartHeight'] === null,
	'A file with the default blank start height loads over a session that has a numeric one');
$lakeCopy->close();
$lakeRace->close();

// From the preset home, cross Arctic Meltwater without draining the channels.
$arctic = new Session;
input($arctic,['actions'=>[['type'=>'generate','preset'=>'arctic-meltwater','seed'=>'arctic-meltwater']]]);
expect($arctic->settings['circuitCauseways'] === false,'Causeways are opt-in');
$natural = $arctic->landscape->fork();
$level = $arctic->water->level();
$waves = clone $arctic->water; $waves->setWaveScale(3.);
input($arctic,['values'=>['circuitCauseways'=>true],'actions'=>['circuit-find']]);
while($arctic->findingCircuit()) input($arctic,[]);
$found = $arctic->status()['circuit'];
expect($found['preview'] && $arctic->landscape->revision() === 0 && $found['heightDifference'] >= 120.,
	'The default Arctic relief produces an editable causeway preview before altering the terrain');
input($arctic,['actions'=>['circuit-dry']]);
while($arctic->preparingRoad()) input($arctic,[]);
$arctic->publishLandscape($arctic->takeCandidate());
expect($arctic->water->level() === $level && $arctic->status()['circuit']['active'] === 'dry',
	'Applying the drivable variant preserves the Arctic sea level');
$overWater = $onLand = false;
$lowestEdge = INF; $deepest = $level;
foreach($arctic->landscape->roads() as $road) {
	$half = $road['width']/2;
	foreach($road['_road']['samples'] as $i=>[$x,,$z,$tx,$tz]) {
		if($i%3 !== 0) continue;
		$bed = $natural->height($x,$z);
		$overWater = $overWater || $bed < $level-3.;
		$onLand = $onLand || $bed > $level+3.;
		$deepest = min($deepest,$bed);
		foreach([-$half,0.,$half] as $across)
			$lowestEdge = min($lowestEdge,$arctic->landscape->height($x-$tz*$across,$z+$tx*$across));
	}
}
expect($overWater && $onLand && $deepest >= $level-30. && $lowestEdge > $waves->heightRange()[1],
	'The Arctic circuit travels on land and on an embankment with both shoulders above maximum swell');
$natural->close();
$arcticCopy = new Session;
$arcticCopy->load($arctic->save());
expect($arcticCopy->settings['circuitCauseways'] === true && $arcticCopy->course->export() === $arctic->course->export(),
	'Saved causeways retain their setting, graded road and gates');
$legacy = json_decode($blankFile,true);
unset($legacy['settings']['circuitCauseways']);
$arcticCopy->load(json_encode($legacy));
expect($arcticCopy->settings['circuitCauseways'] === false,'An older landscape restores the dry-ground default');
$arcticCopy->close();
enterCar($arctic);
$arctic->close();

$vehicle = ['x'=>12., 'z'=>10.];
$target = ['kind'=>'checkpoint', 'x'=>0., 'z'=>0., 'yaw'=>0., 'halfWidth'=>15.];
expect(NavigationArrow::bearing($target,$vehicle,null,0.) === 0.,
	'An off-center vehicle within the checkpoint span aims straight across it.');
expect(abs(NavigationArrow::bearing($target,['x'=>19.,'z'=>4.],null,0.) + M_PI/4) < 1e-9,
	'A vehicle outside the span aims at the near end.');
$target['yaw'] = M_PI/3;
$across = 11.; $along = 7.;
$at = ['x'=>$across*cos($target['yaw'])+$along*sin($target['yaw']),
	'z'=>-$across*sin($target['yaw'])+$along*cos($target['yaw'])];
expect(abs(NavigationArrow::bearing($target,$at,null,0.) + $target['yaw']) < 1e-9,
	'A rotated gate uses its own across and travel axes.');
foreach([1.,0.,-1.] as $z)
	expect(NavigationArrow::bearing($target,['x'=>0.,'z'=>$z],.75,.05) === .75, 'The cue holds its world bearing across a close pass.');
$drop = ['kind'=>'drop','x'=>sin(deg2rad(-175)), 'z'=>-cos(deg2rad(-175))];
$old = deg2rad(175); $origin = ['x'=>0.,'z'=>0.];
$half = NavigationArrow::bearing($drop,$origin,$old,.1);
$split = NavigationArrow::bearing($drop,$origin,NavigationArrow::bearing($drop,$origin,$old,.05),.05);
expect(abs($half-M_PI)<1e-9 && abs($half-$split)<1e-9, 'A new target turns along the short arc by elapsed time.');

echo "Landscape sandbox race: $checks checks passed\n";
