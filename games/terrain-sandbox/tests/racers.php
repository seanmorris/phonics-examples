<?php
declare(strict_types=1);

$package = getenv('PHONICS_TERRAIN_ROOT') ?: dirname(__DIR__, 3) . '/packages/terrain-phonics';
require_once $package . '/autoload.php';
require_once dirname(__DIR__) . '/autoload.php';

use Phonics\Ecs\Registry;
use Phonics\Ecs\Scheduler;
use TerrainSandbox\Car;
use TerrainSandbox\Circuit;
use TerrainSandbox\CircuitDriver;
use TerrainSandbox\CircuitRoad;
use TerrainSandbox\Course;
use TerrainSandbox\RacingLine;
use TerrainSandbox\Race;
use TerrainSandbox\RacerMotion;
use TerrainSandbox\Session;

function check(bool $condition, string $reason): void
{
	if(!$condition) throw new RuntimeException($reason);
}

$entities = new Registry;
$systems = new Scheduler($entities);
$session = new Session(['worldSize' => 512, 'heightScale' => 0, 'detailAmplitude' => 0, 'waterLevel' => -5],
	systems: $systems, movementSystems: ['rival.drive']);
$course = $session->course;
$course->place('start', 'road', 0, 0, 10, 0);
$gate = $course->place('checkpoint', 'road', 0, 0, 50, 0)['gate']['id'];
$finish = $course->place('finish', 'road', 0, 0, 100, 0)['gate']['id'];
[$local] = $entities->query(Car::class);
check($entities->get($local, Car::class) === $session->car, 'The entity holds the car that the sandbox drives.');
$session->camera->position = [0, 30, 0];
$session->camera->yaw = M_PI;
$session->input(['actions' => ['car']], 1/60, 1.);
for($i = 0; $i < 2000 && $session->findingCarSite(); $i++) $session->input([], 1/60, 1.);
check($session->mode === 'car' && $session->race === $entities->get($local, Race::class), 'Boarding selects the local race.');

$rival = $entities->create(new Car, new RacerMotion('car'), new Race);
$place = function(Car $car, float $z) use ($session): void {
	$site = $car->checkedSite($session->landscape, -5, 2, $z, 0, $session->course->posts());
	check($site !== null, 'The rival has a dry landing site.');
	$car->spawn($site, $session->landscape, -5);
};
$place($entities->get($rival, Car::class), -10);
$recoverTo = null;
$driveRival = true;
// Register after the race system to exercise the declared ordering, including a crossing on this very tick.
$systems->add('rival.drive', function(Registry $registry, float $dt) use ($rival, $session, $place, &$recoverTo, &$driveRival): void {
	if($session->paused || (!$driveRival && $recoverTo === null)) return;
	$car = $registry->get($rival, Car::class);
	$before = $car->state();
	$relocated = $recoverTo !== null;
	if($relocated) { $place($car, $recoverTo); $recoverTo = null; }
	else $car->step($dt, 1., 0., $session->landscape, -5, false, $session->course->posts());
	$registry->get($rival, RacerMotion::class)->publish($before, $car->state(), $relocated);
}, ['terrain-sandbox.simulate']);
$dt = 1/60;
$session->input(['keys' => ['KeyW' => true]], $dt, 1.);
$rivalRace = $entities->get($rival, Race::class);
for($i = 0; $i < 900 && $session->car->state()['z'] < 50; $i++) $systems->update($dt);
$localSplit = $session->race->status($course, $session->time)['splits'];
check(array_column($localSplit, 'gate') === [$gate], 'The local car earns its split on the crossing tick.');
check($rivalRace->state === 'running' && $rivalRace->nextGate($course)['id'] === $gate,
	'The second car started its own clock and still owes the same gate.');
$localMessage = $session->message;
$recoverTo = 55.;
$systems->update($dt);
check($rivalRace->status($course, $session->time)['splits'] === [], 'Recovering across the gate earns no split.');
$motion = $entities->get($rival, RacerMotion::class);
$before = ['x' => 2., 'y' => 0., 'z' => 46., 'recoveries' => 0];
$after = ['x' => 2., 'y' => 0., 'z' => 55., 'recoveries' => 1];
$motion->publish($before, $after);
check($motion->take() === null, 'A changed chassis recovery counter also suppresses the segment.');
$recoverTo = 45.;
$systems->update($dt);
for($i = 0; $i < 300 && $entities->get($rival, Car::class)->state()['z'] < 50; $i++) $systems->update($dt);
$rivalSplit = $rivalRace->status($course, $session->time)['splits'];
check(array_column($rivalSplit, 'gate') === [$gate] && $rivalSplit[0]['time'] !== $localSplit[0]['time'],
	'Driving back through the gate records the second car’s own split time.');
check($session->race->status($course, $session->time)['splits'] === $localSplit && $session->message === $localMessage,
	'The rival crossing leaves the local splits and HUD alone.');
$driveRival = false;
for($i = 0; $i < 600 && $session->race->state !== 'finished'; $i++) $systems->update($dt);
check($session->race->state === 'finished' && $rivalRace->nextGate($course)['id'] === $finish
	&& $rivalRace->status($course, $session->time)['best'] === null, 'Only the finisher records a result.');
$session->input(['actions' => ['fly']], $dt, 1.);
$systems->update($dt);
check($rivalRace->state === 'running' && $rivalRace->nextGate($course)['id'] === $finish,
	'Leaving the local car keeps the other driver racing.');
// Replacing the course with an identical revision must still retire the old run.
$session->course = clone $course;
$systems->update($dt);
check($rivalRace->state === 'idle' && $session->race->state === 'idle', 'A replacement course invalidates both runs.');
$systems->remove('rival.drive');
$session->close();
check($entities->query(Car::class) === [$rival], 'Closing the session removes only the vehicles it owns.');
echo "Two-racer checkpoint, recovery, clock, HUD and lifecycle checks passed.\n";

// A saved closed circuit uses exactly the same action and scorer as a freshly generated dry circuit.
foreach([-1,1] as $direction) {
	$exhibition = new Registry;
	$demo = new Session(['worldSize'=>512,'heightScale'=>0,'detailAmplitude'=>0,'waterLevel'=>-5], systems: new Scheduler($exhibition));
	$line = [[-60,0],[-60,55],[-45,70],[45,70],[60,55],[60,-55],[45,-70],[-45,-70],[-60,-55]];
	$circuit = new TerrainSandbox\Circuit(array_map(fn($p)=>[$direction*$p[0],$p[1]],$line),-5.,10.);
	$file = json_decode($demo->save(),true);
	$file['circuit'] = ['route'=>$circuit->export(),'variant'=>'dry'];
	$file['course'] = $circuit->course($demo->landscape,$demo->gateShapes,2,false)->export();
	$demo->load(json_encode($file));
	$humanCar = $demo->car;
	$humanRace = $demo->race;
	$act = fn($name) => $demo->input(['actions'=>[$name]],1/60,1.);
	$act('cpu-demo');
	check($demo->watchingDriver() && $humanCar !== $demo->car && $humanRace !== $demo->race,'Demo has its own chassis and race.');
	$act('pause');
	$parked = $demo->car->state();
	$demo->step(1/60);
	check($parked === $demo->car->state(), 'Pausing freezes the driver and car.');
	$act('pause');
	$exit = false;
	$lastSlide = -1000;
	$charge = $parked['nitrous'];
	[$driverEntity] = $exhibition->query(CircuitDriver::class);
	$driver = $exhibition->get($driverEntity,CircuitDriver::class);
	for($i=0;$i<7000 && $demo->race->state !== 'finished';$i++) {
		$demo->step(1/60);
		$car = $demo->car->state();
		if($driver->phase === 'Handbraking' && abs($car['drift']) > .01) $lastSlide = $i;
		if($driver->phase === 'Boosting' && $i-$lastSlide < 180 && $car['nitrous'] < $charge) $exit = true;
		$charge = $car['nitrous'];
	}
	$result = $demo->race->status($demo->course,$demo->time);
	check($demo->race->state==='finished' && count($result['lapTimes'])===2 && $exit,
		'The CPU slides, spends nitrous on exit and earns two complete laps in either direction.');
	check($humanRace->status($demo->course,$demo->time)['best'] === null,'Demo finishes leave player records alone.');
	$beforeRestart = $demo->car->state();
	$demo->input(['values'=>['waterLevel'=>100.]],1/60,1.);
	$act('race-reset');
	check($demo->car->state() === $beforeRestart && $demo->race->state === 'finished',
		'An unusable starting grid preserves both the result and the car.');
	$demo->input(['values'=>['waterLevel'=>-5.]],1/60,1.);
	$menu = new TerrainSandbox\SandboxMenu(static fn($key) => $key === 'tracks' ? '["Off"]' : 0,
		static fn() => true, static fn() => null);
	$menu->open($demo);
	$menu->menu->select('race');
	$menu->menu->activate();
	$menu->finish($demo);
	$demo->input($menu->takePending(),1/60,1.);
	check($demo->watchingDriver() && $demo->race->state === 'idle' && $demo->car->state()['speed'] === 0.
		&& $demo->car->state()['nitrous'] === 1. && $demo->car->state()['z'] < 0 && $demo->race->status($demo->course,$demo->time)['best'] === $result['best'],
		'Restart stages the CPU before the line with a fresh bottle and retains its own record.');
	$act('cpu-demo');
	check($demo->mode==='fly' && !$demo->watchingDriver() && $demo->car===$humanCar && $demo->race===$humanRace,
		'Stopping the demo returns the player to free flight with their own racer.');
	$act('circuit-flooded'); $act('cpu-demo');
	check(!$demo->watchingDriver(),'The CPU car is unavailable on a flooded course.');
	$demo->close();
}
// Both bends have the same entry. Only the first has enough road after the apex to spend the bottle.
foreach([false,true] as $chicane) {
	$exitLeg = $chicane ? [[45,30],[50,25],[50,0],[800,0]] : [[800,30]];
	$route = new Circuit([[0,-1000],[0,0],[4,15],[15,26],[30,30],...$exitLeg,[800,-1000]],-2.,null);
	$driver = new CircuitDriver($route,$course);
	$driver->restart(0.,-50.);
	$pose = ['motion'=>'driving','roadHold'=>0.,'drift'=>0.,'speed'=>17.,'nitrous'=>.65];
	$slide = $boostedAfterSlide = false;
	foreach($route->stations(range(950,1075)) as [$x,$z,$yaw]) {
		[,,$handbrake,$boost] = $driver->controls($pose + compact('x','z','yaw'),1./$pose['speed'],false);
		$boostedAfterSlide = $boostedAfterSlide || ($slide && $boost);
		$slide = $slide || $handbrake;
	}
	check($slide && $boostedAfterSlide === !$chicane, 'Slide into both bends and spend nitrous only on the open exit.');

	$driver->restart(0.,-700.);
	$pose['speed'] = 160. * Car::MPS_PER_MPH;
	$pose['nitrous'] = 1.;
	[$pedal,,,$boost] = $driver->controls($pose + ['x'=>0.,'z'=>-700.,'yaw'=>0.],1/60,false);
	check($pedal === 1. && $boost, 'Keep accelerating with a full bottle at 160 mph on a clear straight.');
}
echo "CPU exhibition laps, drift exits, records, pause and restart passed.\n";

// Drive the same authored S-bends at two widths. The wide grading shoulder is outside the asphalt.
$points = [[-140,-80],[-140,55],[-130,84],[-104,100],[-40,100],[-22,88],[-14,66],[-5,47],
	[22,40],[90,40],[110,26],[118,0],[110,-40],[75,-100],[-40,-132],[-100,-120]];
$apexCuts = [];
foreach([6,36] as $width) {
	$table = new Session(['worldSize'=>512,'heightScale'=>0,'detailAmplitude'=>0,'waterLevel'=>-12]);
	$table->landscape->queue(CircuitRoad::closeLoop(['type'=>'road','material'=>'road','width'=>$width,'shoulder'=>15.,
		'points'=>array_map(static fn($p)=>[$p[0],0.,$p[1]],$points)]));
	$table->landscape->commit();
	$loop = CircuitRoad::manualCircuit($table->landscape,0,-12.);
	$save = json_decode($table->save(),true);
	$save['course'] = $loop->course($table->landscape,$table->gateShapes,2,false)->export();
	$save['circuit'] = ['route'=>$loop->export(),'variant'=>'dry'];
	$table->load(json_encode($save));
	$table->input(['actions'=>['cpu-demo']],1/60,1.);
	$cut = 0.;
	for($i = 0; $i < 7000 && $table->race->state !== 'finished'; $i++) {
		$table->step(1/60);
		$p = $table->car->state();
		$sin = sin($p['yaw']); $cos = cos($p['yaw']);
		foreach([Car::WHEELBASE/2,-Car::WHEELBASE/2] as $along) {
			$track = $along > 0. ? Car::FRONT_TRACK : Car::REAR_TRACK;
			foreach([-$track/2,$track/2] as $across) {
				$x = $p['x']+$along*$sin+$across*$cos;
				$z = $p['z']+$along*$cos-$across*$sin;
				$material = $table->landscape->material($x,$z,$offset,$mark);
				check($material === 'road' && $mark !== null && abs($mark[0])+.12 < $mark[2],
					"All four tires must clear the actual $width m pavement edge through both laps.");
				if($x < -65. && $z > 65.) $cut = max($cut,-$mark[0]);
			}
		}
	}
	check($table->race->state === 'finished' && $table->car->state()['recoveries'] === 0, 'Finish through every gate on both widths.');
	$apexCuts[$width] = $cut;
	if($width === 36) {
		// A narrow gate on this broad apex must still admit the full car.
		$count = (int)ceil($loop->length/6.);
		$spacing = $loop->length/$count;
		$stations = $loop->stations(array_map(static fn($i) => $i*$spacing,range(0,$count-1)));
		$gates = new Course($table->gateShapes);
		$apex = 34;
		[$x,$z,$yaw] = $stations[$apex];
		$gates->place('start','road',$x,0.,$z,$yaw,width:8.,pavementSpan:true);
		$line = RacingLine::fit($stations,$spacing,$table->landscape,$gates);
		$across = ($line[$apex][0]-$x)*cos($yaw) - ($line[$apex][1]-$z)*sin($yaw);
		check(abs($across)+Car::BODY_HALF_WIDTH < 4., 'A narrow gate limits the cut across a wide apex.');
		$open = new Course($table->gateShapes);
		$before = RacingLine::fit($stations,$spacing,$table->landscape,$open)[$apex];
		$table->landscape->queue(['type'=>'material','x'=>$before[0],'z'=>$before[1],'radius'=>6.,'material'=>'grass']);
		$table->landscape->commit();
		$after = RacingLine::fit($stations,$spacing,$table->landscape,$open)[$apex];
		check($table->landscape->material(...$after) === 'road' && hypot($after[0]-$before[0],$after[1]-$before[1]) > 3.,
			'Repainting the inside edge pulls the apex back onto asphalt.');
	}
	$table->close();
}
check($apexCuts[36] > $apexCuts[6]+8., 'The wider road permits a deeper cut on the same right-hand apex.');
echo "Width-aware racing lines and tire clearance passed.\n";

// On this kilometer of alternating bends a 28 m road leaves one clear line, and a 14 m road does not.
$weave = [[0,-1150],[0,-850],[0,-640],[12,-500],[-12,-350],[12,-190],[-12,-60],[12,130],[-12,270],
	[12,430],[0,590],[0,830],[0,1050],[60,1150],[270,1150],[330,1050],[330,600],[330,0],[330,-600],
	[330,-1150],[270,-1250],[60,-1250]];
foreach([[28,false],[14,false],[36,true]] as [$width,$painted]) {
	$table = new Session(['worldSize'=>4096,'spacing'=>2,'heightScale'=>0,'detailAmplitude'=>0,'waterLevel'=>-12]);
	$table->landscape->queue(CircuitRoad::closeLoop(['type'=>'road','material'=>'road','width'=>$width,'shoulder'=>14.,
		'points'=>array_map(static fn($p) => [$p[0],0.,$p[1]],$weave)]));
	if($painted) $table->landscape->queue(['type'=>'material','x'=>0.,'z'=>-498.,'radius'=>4.,'material'=>'grass']);
	$table->landscape->commit();
	$route = CircuitRoad::manualCircuit($table->landscape,0,-12.);
	// Put the start/finish line inside the open weave and observe it at speed on the second lap.
	$laps = $width === 28 ? 2 : 1;
	if($laps === 2) {
		$seam = 0;
		foreach($route->points as $k => [$x,$z]) {
			if($x < 20. && abs($z) < abs($route->points[$seam][1])) $seam = $k;
		}
		$route = new Circuit(array_merge(array_slice($route->points,$seam),array_slice($route->points,0,$seam)),-12.,null);
	}
	$file = json_decode($table->save(),true);
	$file['course'] = $route->course($table->landscape,$table->gateShapes,$laps,false)->export();
	$file['circuit'] = ['route'=>$route->export(),'variant'=>'dry'];
	$table->load(json_encode($file));
	$table->input(['actions'=>['cpu-demo']],1/60,1.);
	$steering = 0.; $brakes = $observations = 0;
	$slowest = $minX = INF; $maxX = -INF;
	$leftStart = false;
	for($i = 0; $i < 18000 && $table->race->state !== 'finished'; $i++) {
		$table->step(1/60);
		$p = $table->car->state();
		if($p['x'] > 150.) $leftStart = true;
		if($p['x'] > 50. || $p['z'] < -510. || $p['z'] > 500.) continue;
		$sin = sin($p['yaw']); $cos = cos($p['yaw']);
		foreach([Car::WHEELBASE/2,-Car::WHEELBASE/2] as $along) {
			$halfTrack = ($along > 0. ? Car::FRONT_TRACK : Car::REAR_TRACK)/2;
			foreach([-$halfTrack,$halfTrack] as $across) {
				$x = $p['x']+$along*$sin+$across*$cos; $z = $p['z']+$along*$cos-$across*$sin;
				check(RacingLine::edgeDistance($table->landscape,$x,$z) > .12,"Tire clearance on the {$width} m serpentine at ($x, $z), frame $i.");
			}
		}
		if($laps === 2 && !$leftStart) continue;
		$observations++;
		$minX = min($minX,$p['x']); $maxX = max($maxX,$p['x']);
		$steering = max($steering,abs($p['steering']));
		$brakes += (int)$p['braking'];
		$slowest = min($slowest,$p['speed']);
	}
	check($table->race->state === 'finished' && $observations > 500,'Finish the serpentine circuit through its ordered gates.');
	if($width === 28) check($maxX-$minX < .15 && $steering < .00025 && $brakes === 0 && $slowest > 190*Car::MPS_PER_MPH,
		'Hold a single heading at speed through the open bends across the timing line.');
	if($width === 14) check($steering > .003 && $brakes > 0,'Steer and brake where the narrower road prevents a straight.');
	$table->close();
}
echo "Open, narrow and repainted serpentine race checks passed.\n";
