<?php
declare(strict_types=1);

use TerrainPhonics\Landscape;
use TerrainSandbox\BalloonPractice;
use TerrainSandbox\Jet;
use TerrainSandbox\FlightTrails;
use TerrainSandbox\Session;
use TerrainSandbox\VehicleModel;

require_once dirname(__DIR__, 3) . '/packages/terrain-phonics/autoload.php';
require_once dirname(__DIR__) . '/autoload.php';

$checks = 0;
function check(bool $ok, string $message): void
{
	global $checks;
	$checks++;
	if(!$ok) throw new RuntimeException($message);
}

function bursts(FlightTrails $trails): array
{
	$points = [];
	foreach(str_split($trails->instances(),FlightTrails::STRIDE) as $bytes)
	{
		$record = array_values(unpack('g*',$bytes));
		if($record[8] === 2.0) $points[] = array_slice($record,0,3);
	}
	return $points;
}

$recipe = ['worldSize'=>16384, 'heightScale'=>0, 'detailAmplitude'=>0];
$land = new Landscape($recipe);
$pose = ['x'=>0, 'y'=>100, 'z'=>0, 'speed'=>140, 'yaw'=>0, 'pitch'=>0, 'roll'=>0];
foreach([[0,0,0],[1,.54,-.78],[-2,1.56,2.4],[3,-.4,M_PI]] as [$yaw,$pitch,$roll])
{
	$p = array_replace($pose, compact('yaw','pitch','roll'));
	$eye = Jet::cockpit($p);
	[$nose,$right,$up] = $eye->basis();
	$model = VehicleModel::basis($p);
	foreach(['x','y','z'] as $i=>$axis)
	{
		check(abs($nose[$i]-$model[6+$i]) < 1e-9 && abs($right[$i]+$model[$i]) < 1e-9
			&& abs($up[$i]-$model[3+$i]) < 1e-9, 'Cockpit follows all three model axes');
		check(abs($eye->position[$i]-($p[$axis]+4.6*$nose[$i]+.85*$up[$i])) < 1e-9, 'Canopy offset follows bank');
	}
	$mounts = new BalloonPractice;
	foreach([0,1] as $slot)
	{
		$mounts->step(1/60,$pose,true,false,$land,0,false,previousPose:$p);
		$shot = end($mounts->shots);
		$attached = Jet::projectileMount($p,$slot);
		$world = [$attached['x'],$attached['y'],$attached['z']];
		check($shot['previous'] === $world, 'A banked release starts at the last attached position');
		foreach(['x','y','z'] as $i=>$axis)
			check(abs($world[$i] - ($p[$axis]+($slot === 0 ? -2.65 : 2.65)*$right[$i]-.62*$up[$i]-.45*$nose[$i])) < 1e-8,
				'Each mount follows the aircraft body under its own wing');
	}
}
$trails = new FlightTrails;
$dt = 1/60;
$clipped = array_replace($pose,['roll'=>.9]);
$trails->step($dt,$clipped,$clipped);
check(!$trails->active(), 'A bank without turning load leaves no vapor');
$turn = array_replace($clipped,['yaw'=>.01,'z'=>2.3]);
$trails->step($dt,$clipped,$turn);
$segments = str_split($trails->instances(),FlightTrails::STRIDE);
check(count($segments) === 2, 'A loaded bank emits a trail from each wing');
$ends = array_map(static fn($bytes)=>array_values(unpack('g*',$bytes)), $segments);
check(abs(hypot($ends[0][0]-$ends[1][0],$ends[0][1]-$ends[1][1]) - 8.34) < 1e-4
	&& $ends[0][1] > $ends[1][1] && abs($ends[1][2] + 2.16) < 1e-4,
	'Vapor starts at the two banked mesh wingtips');
check(abs($trails->timeAt(.5) - 1.5*$dt) < 1e-9, 'Birth times follow fixed-step interpolation');
for($i=0; $i<100; $i++) $trails->step($dt,$pose,$pose);
check(!$trails->active(), 'Old wingtip vapor dissipates after leveling out');
$smoke = new BalloonPractice;
$smoke->place(0,36,100,$land,0);
for($i=0; $i<8; $i++)
{
	$trails->step($dt,$pose,$pose);
	$smoke->step($dt,$pose,$i === 0,false,$land,0,true,$trails);
}
check($smoke->hits === 1 && !$smoke->shots && $trails->active(), 'Smoke lingers after the projectile pops a balloon');
$impact = bursts($trails);
check(count($impact) === 1 && $impact[0][2] < 36
	&& abs(($impact[0][0]/6.7)**2 + (($impact[0][1]-100)/10)**2 + (($impact[0][2]-36)/6.7)**2 - 1) < 1e-5,
	'One burst appears on the near surface of the popped balloon');
$first = array_values(unpack('g*',substr($trails->instances(),0,FlightTrails::STRIDE)));
check($first[8] === 1.0 && abs($first[2] + 2.45) < 1e-4 && abs($first[0]-2.65) < 1e-4 && $first[6] > $first[2],
	'Smoke starts at the tail of the projectile on the left wing');
for($i=0; $i<150; $i++)
{
	$trails->step($dt,$pose,$pose);
	$smoke->step($dt,$pose,false,true,$land,0,true,$trails);
}
check(!$trails->active(), 'Exhaust expires completely and held Vulcan fire produces no smoke');

$fire = static function(array $flight, float $water, bool $tracer = false) use ($dt,$land): array {
	$range = new BalloonPractice;
	$effects = new FlightTrails;
	for($i = 0; $i < 220; $i++)
	{
		$effects->step($dt,$flight,$flight);
		$range->step($dt,$flight,$i === 0 && !$tracer,$i === 0 && $tracer,$land,$water,false,$effects);
		if(!$range->shots) break;
	}
	return bursts($effects);
};
$dive = array_replace($pose,['pitch'=>-.5]);
foreach([-10,25] as $water)
{
	$impact = $fire($dive,$water);
	$surface = max(0,$water);
	$mount = Jet::projectileMount($dive,0);
	$z = $mount['z'] + ($surface-$mount['y'])/tan($dive['pitch']);
	check(count($impact) === 1 && abs($impact[0][1]-$surface) < .001 && abs($impact[0][2]-$z) < .001,
		'A surface impact bursts at the intersection within the step');
}
check(!$fire($pose,-10) && !$fire(array_replace($pose,['z'=>8188]),-10) && !$fire($dive,-10,true),
	'Expiry, map exits and tracer contacts leave no projectile burst');

$range = new BalloonPractice;
$tick = static function(int $steps, bool $launch = false, bool $gun = false) use ($range,$pose,$land): void {
	for($i=0; $i<$steps; $i++) $range->step(1/60,$pose,$launch && $i === 0,$gun,$land,0,true);
};
$tick(1,true);
check($range->status()['ready'] === 1 && reset($range->shots)['target'] === null, 'Can launch with no target');
check($range->readySlots() === [1], 'Launching from the left wing leaves the right wing loaded');
$tick(30); $tick(1,true); $tick(1,true);
check($range->readySlots() === [] && $range->status()['ready'] === 0 && count($range->shots) === 2, 'Two independently expended charges');
$tick(149);
check($range->readySlots() === [0] && $range->status()['ready'] === 1, 'Left wing refills ahead of the right');
$tick(32);
check($range->readySlots() === [0,1] && $range->status()['ready'] === 2, 'Both wing mounts refill');
$range->reset();
$range->place(0,150,100,$land,0);
$range->place(0,200,100,$land,0);
$range->cycle();
$tick(8);
check(!$range->status()['locked'] && $range->status()['visible'], 'Visible before the dwell completes');
$tick(14);
check($range->status()['locked'], 'Sustained alignment locks');
$range->cycle();
check(!$range->status()['locked'], 'A target change clears the lock');
$tick(20); $tick(1,true);
check(reset($range->shots)['target'] === $range->status()['selected'], 'Launched projectile remembers the locked balloon');
$tick(24);
check($range->hits === 1 && count($range->targets()) === 1, 'Nearer balloon intercepts the shot aimed past it');
$tick(30,false,true);
check($range->hits === 2 && $range->status()['ready'] === 1, 'Held Vulcan pops the second balloon independently');
check(count($range->export()) === 2, 'Popping preserves authored placements');
$range->reset();
check(count($range->targets()) === 2 && !$range->shots, 'Reset restores targets and clears shots');
check($range->remove([0,100,0],[0,0,1],160) && count($range->targets()) === 1, 'Removal picks the nearest balloon');
check(!$range->remove([0,100,0],[0,0,1],10), 'Solid foreground clips an editor pick');
$range->refit($land,40);
check(array_values($range->targets())[0][1] === 140.0, 'Changing water level refits an authored placement');
check($range->start($pose,$land,0), 'Quick practice places a balloon');
$point = $range->targets()[$range->status()['selected']];
$bearing = atan2($point[0],$point[2]);
$tick(24);
check(abs(rad2deg($bearing)) >= 29.99 && !$range->status()['locked'], 'Starting practice requires a turn');
// Approach the selected balloon, turn onto it and pop it from inside the lock range.
$approach = array_replace($pose,['x'=>$point[0]-700*sin($bearing),'y'=>$point[1],
	'z'=>$point[2]-700*cos($bearing),'yaw'=>$bearing]);
for($i=0; $i<110; $i++) $range->step(1/60,$approach,$i === 24,false,$land,0,true);
check($range->hits === 1, 'Quick-practice shot pops the transient balloon');
for($i=0; $i<60; $i++) $range->step(1/60,$approach,false,false,$land,0,true);
check(count($range->targets()) === 2 && count($range->export()) === 1, 'Practice replaces its balloon without adding it to saves');
$next = $range->targets()[$range->status()['selected']];
$nextBearing = atan2($next[0]-$approach['x'],$next[2]-$approach['z']);
check(sin($nextBearing-$bearing)*sin($bearing) < 0, 'The replacement favours the other side of the new heading');

$open = new Landscape(array_replace($recipe,['worldSize'=>32768]));
$practice = new BalloonPractice;
foreach([[0,0,0,0],[0,0,1.8,2.2],[16380,16380,M_PI/4,0],[-16380,-16380,-3*M_PI/4,0]] as [$x,$z,$yaw,$pitch])
{
	$flight = array_replace($pose,compact('x','z','yaw','pitch'));
	check($practice->start($flight,$open,200), 'Practice finds an approach even from an outward-facing map corner');
	[$bx,$by,$bz] = $practice->targets()[$practice->status()['selected']];
	$distance = hypot($bx-$x,$bz-$z);
	check($distance >= 1799.99 && $distance <= 3200.01, 'Full-size landscapes give the pilot a long approach');
	check(max(abs($bx),abs($bz)) <= 16384-160 && $by >= 280, 'The balloon clears the shoreline and map edge');
	if($x === 0)
	{
		$heading = $yaw + ($pitch > M_PI/2 ? M_PI : 0);
		$angle = rad2deg(acos(cos(atan2($bx-$x,$bz-$z)-$heading)));
		check($angle >= 29.99 && $angle <= 75.01, 'Off-axis bearings follow the horizontal nose direction even past vertical');
	}
}
$open->close();
$small = new Landscape(array_replace($recipe,['worldSize'=>128]));
check($practice->start($pose,$small,0), 'Practice scales down on a compact map');
[$x,,$z] = $practice->targets()[$practice->status()['selected']];
check(hypot($x,$z) > 28 && max(abs($x),abs($z)) <= 57, 'The compact approach fits the map and balloon envelope');
$small->close();

// A real heightfield edit blocks sight and both kinds of shot along the same segment.
$ridge = new Landscape($recipe);
$ridge->queue(['type'=>'raise','x'=>0,'z'=>190,'radius'=>70,'amount'=>260]);
$ridge->commit();
$hidden = new BalloonPractice;
$hidden->place(0,360,100,$ridge,0);
for($i=0; $i<120; $i++) $hidden->step(1/60,$pose,$i === 30,$i < 40,$ridge,0,true);
check(!$hidden->status()['locked'] && !$hidden->status()['visible'], 'A ridge conceals the targeting box and interrupts dwell');
check($hidden->hits === 0 && !$hidden->shots, 'Both kinds of shot stop at the ridge');
$ridge->close();
$range->refit($land,200);
check(min(array_column($range->targets(),1)) >= 216, 'Raising the water keeps practice and authored balloons above the surface');

$session = new Session($recipe);
$session->camera->position = [0,200,0]; $session->camera->pitch = -1.5;
$input = static fn(array $controls) => $session->input($controls,1/60,16/9);
$input(['values'=>['tool'=>'balloon','balloonAltitude'=>65], 'clicks'=>[[.5,.5]]]);
for($i=0; $i<200 && $session->picking; $i++) $input([]);
check($session->balloons->status()['placements'] === 1, 'Placement works through the ground picker');
$saved = json_decode($session->save(),true,flags:JSON_THROW_ON_ERROR);
$session->load(json_encode($saved,JSON_THROW_ON_ERROR));
check($session->balloons->export() == $saved['balloons'], 'Placements round-trip through landscape files');
$bad = array_replace($saved,['balloons'=>[[0,0,0]]]);
try { $session->load(json_encode($bad,JSON_THROW_ON_ERROR)); throw new RuntimeException('Invalid save accepted'); }
catch(InvalidArgumentException) { check($session->balloons->export() == $saved['balloons'], 'Invalid load leaves the active range intact'); }
$clamped = $saved;
$clamped['settings']['waterLevel'] = 10001;
$session->load(json_encode($clamped,JSON_THROW_ON_ERROR));
check(array_values($session->balloons->targets())[0][1] === 10065.0, 'Loaded balloons follow the applied water level');
unset($saved['balloons']);
$session->load(json_encode($saved,JSON_THROW_ON_ERROR));
check(!$session->balloons->targets(), 'Older saves open with an empty balloon list');
$input(['actions'=>['jet','jet-view','balloon-practice','jet-launch']]);
$session->step(1/60);
check($session->jetView === 'cockpit' && $session->balloons->status()['ready'] === 1, 'Jet controls work through the session');
$input(['actions'=>['pause','jet-launch']]);
$frozen = $session->balloons->status();
$session->step(1/60);
check($session->balloons->status() === $frozen, 'Pause freezes shots, dwell, respawn and recharge');
$input(['actions'=>['fly']]);
check(!$session->balloons->shots && !$session->balloons->status()['practice'], 'Leaving the F5 clears transient flight state');
$session->close(); $land->close();
echo "$checks balloon practice checks passed.\n";
