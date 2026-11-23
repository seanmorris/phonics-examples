<?php
declare(strict_types=1);

require_once dirname(__DIR__,3) . '/packages/terrain-phonics/autoload.php';
require_once dirname(__DIR__) . '/autoload.php';

use TerrainPhonics\Landscape;
use TerrainSandbox\Car;
use TerrainSandbox\CarEngineSound;
use TerrainSandbox\CarGearbox;
use TerrainSandbox\FlightTrails;
use TerrainSandbox\TireDust;

function check(bool $ok, string $message): void
{
	if(!$ok) throw new RuntimeException($message);
}

$dt = 1/60;
$land = new Landscape(['worldSize'=>16384,'heightScale'=>0,'detailAmplitude'=>0]);
function board(Landscape $land, float $x = 0.): Car
{
	$car = new Car;
	$car->spawn($car->checkedSite($land,-5.,$x,0.,0.,[]),$land,-5.);
	return $car;
}

$car = board($land);
$idle = $car->state();
$car->step($dt,0.,0.,$land,-5.);
check($car->state() === $idle && $idle['rpm'] === CarGearbox::IDLE && !$car->dustContacts,'Parked car is quiet and stable at idle.');
$shifts = []; $gears = [1]; $rpm = $idle['rpm']; $launch = false;
for($i = 0; $i < 4200; $i++)
{
	$car->step($dt,1.,0.,$land,-5.);
	$pose = $car->state();
	if($pose['gear'] > end($gears))
	{
		$gears[] = $pose['gear'];
		$shifts[] = [$rpm,$pose['rpm'],$pose['engineLoad']];
	}
	$rpm = $pose['rpm'];
	$launch = $launch || (count($car->dustContacts) === 2 && $pose['rearWheelSpin'] !== $pose['wheelSpin']);
	if($pose['speed'] >= Car::TOP_SPEED_MPH*Car::MPS_PER_MPH) break;
}
check($gears === [1,2,3,4,5] && $i < 4200,'The car drives through every gear and reaches the governor.');
foreach($shifts as [$before,$after,$load]) check($before > 7000 && $after < $before && $load === 0.,'Upshifts cut torque and drop RPM.');
check($launch && !$car->dustContacts,'Wheelspin throws rear-tire dust at launch and clears in straight-line cruise.');

$light = new CarGearbox; $full = new CarGearbox;
for($i=0;$i<180;$i++) { $light->step($dt,56.,.35,true); $full->step($dt,56.,1.,true); }
$higher = $light->step($dt,56.,1.,true);
$lower = $full->step($dt,56.,1.,true);
check($light->gear === 4 && $full->gear === 3 && $lower > 1.1*$higher && $full->rpm > $light->rpm,
	'At identical road speed and pedal, the lower ratio delivers more acceleration and higher RPM.');
for($i=0;$i<90;$i++) check($full->step($dt,56.,1.,false) === 0.,'Unloaded wheels cannot propel the car.');
check($full->gear === 3 && $full->rpm > 7900.,'Airborne engine free-revs without shifting.');
for($i=0;$i<150;$i++) $full->step($dt,4.,-1.,true);
check($full->gear === 1 && $full->load === 0.,'Braking downshifts to first without selecting reverse while rolling forward.');
check($full->step($dt,0.,-1.,true) < 0. && $full->gear === -1,'Reverse applies torque once stopped.');
$full->step($dt,-8.,-1.,true);
check($full->wheelSlip === 0.,'Steady reversing at the governor does not keep spinning the tires.');

$land->queue(['type'=>'road','points'=>[[0,0,-100],[0,0,100]],'width'=>8,'shoulder'=>8,'crown'=>0]);
$land->commit();
$edge = 4.; // Half the width of the paved strip. Its graded shoulder is unpaved.
$patchLevels = [];
check($land->material($edge+1.,0.) === 'road' && !Car::onPavement($land,$edge+1.,0.),'Grading outside the pavement stays dusty.');
foreach([0.,$edge,16.] as $x)
{
	$car = board($land,$x);
	for($i=0;$i<90;$i++) $car->step($dt,1.,0.,$land,-5.);
	$car->step($dt,0.,0.,$land,-5.,true);
	$pose = $car->state();
	$expected = $x === 0. ? 0 : ($x === $edge ? 1 : 2);
	$patchLevels[] = $pose['tireSqueal'];
	check(count($car->dustContacts) === $expected && ($pose['tireSqueal'] > 0.) === ($expected < 2),
		'Each locked rear tire contributes the effect for its own surface.');
	foreach($car->dustContacts as [$px,$py,$pz,$side,$strength])
		check($py === 0. && abs($pz-($pose['z']-.5*Car::WHEELBASE)) < 1.e-6
			&& abs(($px-$pose['x']) - $side*.5*Car::REAR_TRACK) < 1.e-6 && $strength > .5,'Dust is born at a rear contact patch.');
	$car->step($dt,0.,0.,$land,.1,true);
	check(!$car->dustContacts && $car->state()['tireSqueal'] === 0.,'Submerged contacts suppress dust and asphalt squeal.');
}
check(abs($patchLevels[1]*sqrt(2.) - $patchLevels[0]) < .00001,'A single paved tire contributes half as much acoustic power as two.');
function slideLevel(Landscape $land, int $run, float $pedal, float $steering, bool $lock = false): float
{
	$car = board($land);
	for($i=0;$i<$run;$i++) $car->step(1/60,1.,0.,$land,-5.);
	for($i=0;$i<7;$i++) $car->step(1/60,$pedal,$steering,$land,-5.,$lock);
	return $car->state()['tireSqueal'];
}
$creep = slideLevel($land,20,0.,0.,true);
$lock = slideLevel($land,160,0.,0.,true);
$hardBrake = slideLevel($land,160,-1.,0.);
$hardTurn = slideLevel($land,160,.2,1.);
check($creep < .02 && $creep < slideLevel($land,50,0.,0.,true) && $patchLevels[0] < $lock,
	'Locked tires get louder with actual sliding speed, from parking speed through highway speed.');
check(slideLevel($land,160,.2,.12) === 0. && slideLevel($land,160,-.4,0.) === 0.,
	'Rolling through a gentle turn or braking within available grip produces no squeal.');
check($hardBrake > 0 && $hardBrake < .1*$lock && $hardTurn > 0 && $hardTurn < .3*$lock,
	'Heavy brake scrub and understeer stay quieter than a sideways or locked-wheel slide.');

$voice = CarEngineSound::voice();
check(count($voice['banks']) === 2 && strlen(base64_decode($voice['flowNoise'],true)) === 32768,'Both exhaust collectors and their turbulence are available.');
[$left,$right] = $voice['banks'];
foreach([6,12] as $order)
{
	$energy = hypot($left['real'][$order],$left['imag'][$order]) * hypot($right['real'][$order],$right['imag'][$order]);
	$alignment = ($left['real'][$order]*$right['real'][$order] + $left['imag'][$order]*$right['imag'][$order])/$energy;
	check($order === 6 ? $alignment < -.99 : $alignment > .99,'The banks alternate at half firing order and reinforce the full firing order.');
}
$coast = CarEngineSound::controls(3600.,0.,0.); $pull = CarEngineSound::controls(3600.,1.,1.);
check($pull[0] === 30. && $coast[0] === $pull[0] && $pull[1] > $coast[1] && $pull[2] > 3*$coast[2],
	'At 3600 RPM the twelve-cylinder firing note is 360 Hz at every load; open throttle brightens the exhaust.');
check($coast[5] === 0. && $pull[5] === .08,'Full tire gain is capped and rolling contact is silent.');

$car = board($land,16.);
$car->step($dt,0.,0.,$land,-5.,nitrousHeld:true);
check(count($car->dustContacts) === 2 && $car->state()['tireSqueal'] === 0.,'Nitrous alone kicks up loose ground under the driven tires.');
$dust = new TireDust;
for($i=0;$i<600;$i++) $dust->step($dt,$car->dustContacts,$car->state());
$count = intdiv(strlen($dust->instances()),FlightTrails::STRIDE);
check($count > 35 && $count <= 54,'Sustained boost has a bounded world-space trail.');
for($i=0;$i<90;$i++) $dust->step($dt,[],$car->state());
check(!$dust->active() && $dust->instances() === '', 'Old puffs expire after the car leaves the surface.');
$land->queue(['type'=>'raise','x'=>16,'z'=>75,'radius'=>22,'amount'=>2.8]);
$land->commit();
$jump = board($land,16.);
$air = 0;
for($i=0;$i<400;$i++)
{
	$jump->step($dt,1.,0.,$land,-5.,nitrousHeld:true);
	if($jump->state()['motion'] === 'airborne')
	{
		$air++;
		check(!$jump->dustContacts && $jump->state()['tireSqueal'] === 0.,'Airborne wheels produce no fresh tire effects.');
	}
	elseif($air) break;
}
check($air > 4,'The boosted car clears the crest.');

// Measure roll relative to the local road plane on the same corner, with and without banking.
function corner(float $steer, float $grade = 0.): array
{
	$land = new Landscape(['worldSize'=>512,'spacing'=>1,'heightScale'=>0,'detailAmplitude'=>0]);
	if($grade != 0.)
	{
		$land->queue(['type'=>'road','points'=>[[-100,-100*$grade,0],[100,100*$grade,0]],'width'=>190,'shoulder'=>4,'crown'=>0]);
		$land->commit();
	}
	$car = board($land);
	for($i=0;$i<100;$i++) $car->step(1/60,1.,0.,$land,-100.);
	$before = $car->state()['roll'];
	$car->step(1/60,.35,$steer,$land,-100.);
	$first = $car->state()['roll'] - $before;
	for($i=1;$i<48;$i++) $car->step(1/60,.35,$steer,$land,-100.);
	$pose = $car->state();
	$plane = $car->checkedSite($land,-100.,$pose['x'],$pose['z'],$pose['yaw'],[])[3][2];
	for($i=0;$i<120;$i++) $car->step(1/60,0.,0.,$land,-100.);
	return [rad2deg($pose['roll']-$plane),$pose['wheelTravel'],rad2deg($first),rad2deg($car->state()['roll'])];
}
[$right,$travel,$first,$straight] = corner(1.);
[$left,$mirror] = corner(-1.);
check($right > 2. && $right < 4. && abs($left+$right) < .001, 'Hard left and right turns produce modest, mirrored body roll.');
check($travel[1] > .03 && $travel[3] > .03 && $travel[0] < -.03 && $travel[2] < -.03 && abs($mirror[0]-$travel[1]) < .0001,
	'Both outside springs compress while the inside extends.');
check(abs($first) < .1 && abs($straight) < .005,'The springs take up load gradually and settle after steering release.');
$mild = corner(.2)[0];
check(corner(.2,.18)[0] < $mild && $mild < corner(.2,-.18)[0] && $mild < $right, 'Lighter steering and a helpful road bank both reduce lean.');
$park = board($land,16.);
for($i=0;$i<60;$i++) $park->step($dt,0.,1.,$land,-5.);
check($park->state()['steering'] != 0. && abs($park->state()['roll']) < 1.e-8, 'Turning the steering wheel while parked produces no body roll.');
echo "Car transmission, tire contact, dust and cornering checks passed\n";
