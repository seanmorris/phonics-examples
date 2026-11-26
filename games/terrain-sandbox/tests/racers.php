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
	$testedHeading = false;
	[$driverEntity] = $exhibition->query(CircuitDriver::class);
	$driver = $exhibition->get($driverEntity,CircuitDriver::class);
	for($i=0;$i<7000 && $demo->race->state !== 'finished';$i++) {
		// Replay a tick of the slide with the same travel direction but the tail out on the other side.
		$replay = !$testedHeading && $driver->phase === 'Drifting' ? clone $driver : null;
		$before = $demo->car->state();
		$demo->step(1/60);
		$car = $demo->car->state();
		if($replay && $driver->phase === 'Drifting' && $car['nitrous'] < $before['nitrous'] && abs($before['drift']) > .05) {
			$heading = $before['yaw']-$before['drift'];
			$turned = atan2(sin($car['yaw']-$car['drift']-$heading),cos($car['yaw']-$car['drift']-$heading));
			check($car['braking'] && $turned*$before['drift'] > 0.,
				'Nitrous joins the locked rear wheels and pulls the travel direction round after the nose.');
			$before['drift'] = -$before['drift'];
			$before['yaw'] = $heading+$before['drift'];
			[, , , $wrongWayBoost] = $replay->controls($before,1/60,false);
			check(!$wrongWayBoost, 'Withhold nitrous while the tail is out on the wrong side.');
			$testedHeading = true;
		}
	}
	$result = $demo->race->status($demo->course,$demo->time);
	check($demo->race->state==='finished' && count($result['lapTimes'])===2 && $testedHeading,
		'Hold a live slide with handbrake and nitrous together and finish both laps in either direction.');
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
// Both bends have the same entry, and the second leads straight into another. The slide that turns the car
// needs the bottle in either, so the driver spends it in both and goes without when it is empty.
foreach([false,true] as $chicane) {
	$exitLeg = $chicane ? [[45,30],[50,25],[50,0],[800,0]] : [[800,30]];
	$route = new Circuit([[0,-1000],[0,0],[4,15],[15,26],[30,30],...$exitLeg,[800,-1000]],-2.,null);
	$driver = new CircuitDriver($route,$course);
	$pose = ['motion'=>'driving','roadHold'=>0.,'speed'=>17.];
	foreach([.65, 0.] as $charge) {
		$driver->restart(0.,-50.);
		$drift = 0.;
		$slide = $boostedSlide = false;
		foreach($route->stations(range(950,1075)) as [$x,$z,$heading]) {
			$yaw = $heading + $drift;
			[, $steer, $handbrake, $boost] = $driver->controls(
				$pose + compact('x','z','yaw','drift') + ['nitrous'=>$charge], 1./$pose['speed'], false);
			check(!$boost || $charge > 0., 'An empty bottle gives no boost.');
			check(!($handbrake && $boost) || abs($drift) > .02, 'Nitrous joins the handbrake only once the tail is out.');
			$drift = $handbrake ? -.18*$steer : .6*$drift;
			$boostedSlide = $boostedSlide || ($handbrake && $boost);
			$slide = $slide || $handbrake;
		}
		check($slide && $boostedSlide === ($charge > 0.), 'Lock the rear wheels through the bend and add nitrous while the bottle lasts.');
	}

	$straight = ['motion'=>'driving','roadHold'=>0.,'x'=>0.,'yaw'=>0.,'drift'=>0.];
	foreach([[1.,true],[.25,true],[.02,false]] as [$charge,$expected]) {
		$driver->restart(0.,-700.);
		[$pedal,,,$boost] = $driver->controls($straight + ['z'=>-700.,'speed'=>160.*Car::MPS_PER_MPH,'nitrous'=>$charge],1/60,false);
		check($pedal === 1. && $boost === $expected, 'Use a partial bottle at 160 mph and let an empty bottle recharge.');
	}
	$driver->restart(0.,-800.);
	for($i=0; $i<=180; $i++) {
		$t = $i/60;
		[, , , $boost] = $driver->controls($straight + ['z'=>-800.+38.*$t,'speed'=>38.,'nitrous'=>1.-$t/4.],1/60,false);
		check($boost, 'Hold nitrous while the straight stays open and usable charge remains.');
	}
	foreach([239.,237.] as $mph) {
		[, , , $boost] = $driver->controls($straight + ['z'=>-680.,'speed'=>$mph*Car::MPS_PER_MPH,'nitrous'=>.25],1/60,false);
		check(!$boost, 'Release nitrous near top speed and leave it off until it can buy acceleration again.');
	}
}
echo "CPU exhibition laps, drift exits, records, pause and restart passed.\n";

// A broad curve at speed asks more than the tires hold. The driver turns the car with brief slides rather than brake.
$table = new Session(['worldSize'=>4096,'heightScale'=>0,'detailAmplitude'=>0,'waterLevel'=>-12]);
$ring = [];
for($i = 0; $i < 96; $i++) {
	$angle = 2 * M_PI * $i / 96;
	$ring[] = [800*cos($angle), 0., 800*sin($angle)];
}
$table->landscape->queue(CircuitRoad::closeLoop(['type'=>'road','material'=>'road','width'=>24.,'shoulder'=>10.,'points'=>$ring]));
$table->landscape->commit();
$loop = CircuitRoad::manualCircuit($table->landscape,0,-12.);
$driver = new CircuitDriver($loop,$table->course,$table->landscape);
[[$x,$z,$yaw]] = $loop->stations([.125*$loop->length]);
$driver->restart($x,$z);
$pose = compact('x','z','yaw') + ['motion'=>'driving','roadHold'=>0.,'drift'=>0.,'nitrous'=>.5];
[$pedal,,$handbrake,$boost] = $driver->controls($pose + ['speed'=>60.],1/60,false);
check($pedal === 1. && $boost && !$handbrake, 'Keep accelerating into a sweeper while tire grip is available.');
// At 100 m/s the tires alone let the car run wide. Its nose is soon a few degrees behind the curve.
[[, , $later]] = $loop->stations([.125*$loop->length + 40.]);
$inward = atan2(sin($later-$yaw),cos($later-$yaw)) <=> 0.;
$wide = ['yaw'=>$yaw - .06*$inward,'speed'=>100.] + $pose;
[$pedal,$steer,$handbrake] = $driver->controls($wide,1/60,false);
check($pedal > 0. && $handbrake && $steer*$inward < 0.,
	'Lock the rear wheels and steer into a broad high-speed curve rather than brake for it.');
$wide['drift'] = .08*$inward;
$wide['yaw'] += $wide['drift'];
[, , , $boost] = $driver->controls(['nitrous'=>0.] + $wide,1/60,false);
check(!$boost, 'Slide without the boost when the bottle is empty.');
$table->close();

// A continuous sweeper has no distinct exit apex. The driver slides the car round it again and again.
$table=new Session(['worldSize'=>4096,'heightScale'=>0,'detailAmplitude'=>0,'waterLevel'=>-12]);
$ring=[];
for($i=0;$i<96;$i++) {
	$angle=2.*M_PI*$i/96;
	$ring[]=[285.*cos($angle),0.,285.*sin($angle)];
}
$table->landscape->queue(CircuitRoad::closeLoop(['type'=>'road','width'=>36.,'shoulder'=>10.,'points'=>$ring]));
$table->landscape->commit();
$loop=CircuitRoad::manualCircuit($table->landscape,0,-12.);
$file=json_decode($table->save(),true);
$file['course']=$loop->course($table->landscape,$table->gateShapes,2,false)->export();
$file['circuit']=['route'=>$loop->export(),'variant'=>'dry'];
$table->load(json_encode($file)); $table->input(['actions'=>['cpu-demo']],1/60,1.);
$slides=0; $sliding=false; $fastest=0.;
for($i=0;$i<5000 && $table->race->state!=='finished';$i++) {
	$before=$table->car->state(); $table->step(1/60); $after=$table->car->state();
	// The rear wheels are locked while the tail swings out. Count each slide the boost joins above 150 mph.
	$locked=$after['braking'] && abs($after['drift'])>abs($before['drift']) && abs($after['drift'])>.04;
	if($locked && $after['speed']>150.*Car::MPS_PER_MPH) $sliding=true;
	if($sliding && $after['braking'] && $after['nitrous']<$before['nitrous']) { $slides++; $sliding=false; }
	if(abs($after['drift'])<.02) $sliding=false;
	$fastest=max($fastest,$after['speed']);
	if($i%10===0) check(RacingLine::edgeDistance($table->landscape,$after['x'],$after['z'])>Car::BODY_HALF_WIDTH,
		'High-speed slides stay within the painted curve.');
}
check($table->race->state==='finished' && $slides>=3 && $after['recoveries']===0 && $fastest>200.*Car::MPS_PER_MPH,
	'Hold repeated slides above 150 mph with handbrake and nitrous together in a sustained curve.');
$table->close();

// Drive the same authored S-bends at several widths. The wide grading shoulder is outside the asphalt.
$points = [[-140,-80],[-140,55],[-130,84],[-104,100],[-40,100],[-22,88],[-14,66],[-5,47],
	[22,40],[90,40],[110,26],[118,0],[110,-40],[75,-100],[-40,-132],[-100,-120]];
$apexCuts = [];
foreach([6,9,12,36] as $width) {
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
	$strayed = 0;
	$braking = false;
	$releasedAt = -100;
	$brakeTaps = 0;
	for($i = 0; $i < 7000 && $table->race->state !== 'finished'; $i++) {
		$table->step(1/60);
		$p = $table->car->state();
		if($braking && !$p['braking']) $releasedAt = $i;
		if(!$braking && $p['braking'] && $i-$releasedAt <= 12) $brakeTaps++;
		$braking = $p['braking'];
		$sin = sin($p['yaw']); $cos = cos($p['yaw']);
		$paved = 0;
		foreach([Car::WHEELBASE/2,-Car::WHEELBASE/2] as $along) {
			$track = $along > 0. ? Car::FRONT_TRACK : Car::REAR_TRACK;
			foreach([-$track/2,$track/2] as $across) {
				$x = $p['x']+$along*$sin+$across*$cos;
				$z = $p['z']+$along*$cos-$across*$sin;
				$material = $table->landscape->material($x,$z,$offset,$mark);
				if($material !== 'road' || $mark === null || abs($mark[0]) >= $mark[2]) continue;
				$paved++;
				if($x < -65. && $z > 65.) $cut = max($cut,-$mark[0]);
			}
		}
		// A slide may carry up to three tires over the edge. The car itself stays on the road.
		check($paved > 0, "At least one tire must stay on the actual $width m pavement through both laps.");
		$strayed += (int)($paved < 4);
	}
	// On this circuit the driver has no need of the allowance for more than a moment.
	check($strayed <= 30, "Keep all four tires on the $width m pavement for all but half a second of both laps.");
	check($table->race->state === 'finished' && $table->car->state()['recoveries'] === 0, 'Finish through every gate at each pavement width.');
	// Reapplying the brakes within a fifth of a second at successive stations made each bend stutter.
	check($brakeTaps <= 10, 'Brake continuously through both laps without pulsing at every route sample.');
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

// A hand-drawn road can turn sharper than the car can steer. The driver rounds what the pavement allows and slows for
// the rest, and when it is left facing the wrong way it turns back to its line instead of circling.
$corners = new Registry;
$table = new Session(['worldSize'=>1024,'heightScale'=>0,'detailAmplitude'=>0,'waterLevel'=>-12], systems: new Scheduler($corners));
$zigzag = [[-200,-100],[-100,-60],[-150,-20],[-40,20],[-110,60],[40,100],[200,100],[200,-100]];
$table->landscape->queue(CircuitRoad::closeLoop(['type'=>'road','material'=>'road','width'=>36.,'shoulder'=>12.,
	'points'=>array_map(static fn($p) => [$p[0],0.,$p[1]],$zigzag)]));
$table->landscape->commit();
$loop = CircuitRoad::manualCircuit($table->landscape,0,-12.);
$file = json_decode($table->save(),true);
$file['course'] = $loop->course($table->landscape,$table->gateShapes,1,false)->export();
$file['circuit'] = ['route'=>$loop->export(),'variant'=>'dry'];
$table->load(json_encode($file));
$table->input(['actions'=>['cpu-demo']],1/60,1.);
[$driverEntity] = $corners->query(CircuitDriver::class);
$driver = $corners->get($driverEntity,CircuitDriver::class);
// Count full circles of the travel direction within any eight seconds. No corner here turns more than 135 degrees.
$circling = static function(Session $table, int $steps, ?callable $each = null): int {
	$turns = [];
	$circles = 0;
	$heading = null;
	for($i = 0; $i < $steps && $table->race->state !== 'finished'; $i++) {
		$table->step(1/60);
		$p = $table->car->state();
		$path = $p['yaw']-$p['drift'];
		if($heading !== null) $turns[] = atan2(sin($path-$heading),cos($path-$heading));
		if(count($turns) > 480) array_shift($turns);
		if(abs(array_sum($turns)) > 2.*M_PI) { $circles++; $turns = []; }
		$heading = $path;
		if($each) $each($p);
	}
	return $circles;
};
[[$x,$z,$yaw]] = $loop->stations([.55*$loop->length]);
foreach([M_PI/2,M_PI] as $away) {
	$site = $table->car->checkedSite($table->landscape,-12.,$x,$z,$yaw+$away,[]);
	check($site !== null, 'The mid-lap pavement takes a car facing any way.');
	$table->car->spawn($site,$table->landscape,-12.);
	$driver->restart($x,$z);
	$circles = $circling($table,600);
	$p = $table->car->state();
	check($circles === 0 && hypot($p['x']-$x,$p['z']-$z) > 60. && $p['speed'] > 12. && $p['recoveries'] === 0,
		'Turn a car left facing across or back down the road onto its line and drive on, without circling.');
}
$table->input(['actions'=>['race-reset']],1/60,1.);
$unpaved = 0;
$circles = $circling($table,6000,static function(array $p) use ($table,&$unpaved): void {
	$sin = sin($p['yaw']); $cos = cos($p['yaw']);
	foreach([[Car::WHEELBASE/2,Car::FRONT_TRACK/2],[-Car::WHEELBASE/2,Car::REAR_TRACK/2]] as [$along,$half]) {
		foreach([-$half,$half] as $across) {
			if(Car::onPavement($table->landscape,$p['x']+$along*$sin+$across*$cos,$p['z']+$along*$cos-$across*$sin)) return;
		}
	}
	$unpaved++;
});
check($table->race->state === 'finished' && $circles === 0 && $unpaved === 0 && $table->car->state()['recoveries'] === 0,
	'Lap a road of 135 degree corners with a tire on the pavement throughout and no circling.');
$table->close();
echo "Sharp corners and wrong-way recovery passed.\n";

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

// A crest followed by a bend on this seed used to carry the CPU outside checkpoint six on every lap.
$canyon = new Session;
$input = static fn(array $actions) => $canyon->input(['actions'=>$actions],1/60,16/9,8.);
$input([['type'=>'generate','preset'=>'canyon','seed'=>'first-light-227']]);
$canyon->input(['values'=>['gateLaps'=>2]],1/60,16/9);
$input(['circuit-find']);
while($canyon->findingCircuit()) $input([]);
$input(['circuit-dry']);
while($canyon->preparingRoad()) $input([]);
if($graded=$canyon->takeCandidate()) $canyon->publishLandscape($graded);
check($canyon->canWatchDriver() && $canyon->course->laps()===2,'The generator applies the selected lap count.');
$input(['cpu-demo']);
for($tick=0; $tick<18000 && $canyon->race->state!=='finished'; $tick++) $canyon->step(1/60);
$result=$canyon->race->status($canyon->course,$canyon->time);
check($result['state']==='finished' && array_column($result['splits'],'gate')===array_column($canyon->course->route(),'id'),
	'The CPU must score every gate in order on both laps of first-light-227.');
echo "Two generated canyon laps passed.\n";
