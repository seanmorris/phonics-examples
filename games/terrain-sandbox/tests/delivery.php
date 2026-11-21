<?php
declare(strict_types=1);

require_once (getenv('PHONICS_TERRAIN_ROOT') ?: dirname(__DIR__, 3).'/packages/terrain-phonics').'/autoload.php';
require_once dirname(__DIR__).'/autoload.php';

use TerrainSandbox\DeliveryRun;
use TerrainSandbox\Session;

function check(bool $valid, string $message): void
{
	if(!$valid) throw new RuntimeException($message);
}

$s = new Session(['worldSize'=>16384,'heightScale'=>0,'detailAmplitude'=>0,'waterLevel'=>-10,'surround'=>'none']);
$s->camera->position = [0,25,0];
$action = static fn($name) => $s->input(['actions'=>[$name]],1/60,16/9);
$action('delivery');
while($s->findingCarSite()) $s->input([],1/60,16/9);
check($s->mode === 'car', 'Explore boards the driver');
$next = static function(string $kind) use ($s): array {
	for($i=0; $i<1500 && $s->navigationTarget() === null; $i++) $s->step(1/60);
	$site = $s->navigationTarget();
	check($site !== null && $site['kind'] === $kind && $site['color'] === DeliveryRun::COLORS[$kind], 'Correct leg and dye');
	$car = $s->car->state();
	check(hypot($site['x']-$car['x'],$site['z']-$car['z']) <= DeliveryRun::MAX_LEG, 'Site within driving radius');
	check(!DeliveryRun::visible($site,$s->camera,16/9), 'Entire plume outside view at spawn');
	check($site['y'] === $s->landscape->height($site['x'],$site['z']) && $site['y'] > $s->water->level(), 'Site on dry ground');
	return $site;
};
$pickup = $next('pickup');
$action('pause');
$time = $s->time;
$s->step(1.);
check($s->time === $time && $s->navigationTarget() === $pickup, 'Paused time and objective stay fixed');
$action('pause');

$from = $to = $s->car->state();
$from['x'] = $pickup['x']-12; $to['x'] = $pickup['x']+12;
$from['z'] = $to['z'] = $pickup['z'];
$cross = static fn($a,$b) => $s->delivery->step($s->landscape,-10,$s->camera,16/9,$a,$b);
check($cross(['y'=>20]+$from,['y'=>20]+$to) === null, 'Passing overhead leaves the box');
check($cross($from,['recoveries'=>1]+$to) === null, 'Vehicle recovery cannot collect a box');
check($cross($from,$to) !== null && $s->delivery->carrying && $s->navigationTarget() === null, 'Swept crossing collects exactly once');
$drop = $next('drop');
check($drop !== $pickup, 'The drop is a distinct leg');
$site = $s->car->checkedSite($s->landscape,-10,$drop['x'],$drop['z'],0,[]);
$s->car->spawn($site,$s->landscape,-10);
$s->step(1/60);
check($s->delivery->delivered === 1 && !$s->delivery->carrying, 'Drive into the drop to complete delivery');
$next('pickup');
$s->input(['values'=>['waterLevel'=>2]],1/60,16/9);
check($s->navigationTarget() === null, 'Rising water retires the submerged box immediately');
$action('fly');
check($s->delivery === null && $s->navigationTarget() === null, 'Leaving the car ends the run');

$s->course->place('start','road',0,0,0,0);
$s->course->place('checkpoint','road',0,0,80,0);
$s->course->place('finish','road',0,0,160,0);
$s->input(['values'=>['waterLevel'=>-10]],1/60,16/9);
$action('car');
while($s->findingCarSite()) $s->input([],1/60,16/9);
check($s->navigationTarget()['z'] === 0.0, 'Checkpoint navigation initially leads to the start');
$s->race->advance($s->course,[0,0,-1],[0,0,1],0,1/60,'car',2);
check($s->navigationTarget()['z'] === 80.0, 'Crossing the start points to checkpoint one');
$s->race->advance($s->course,[0,0,79],[0,0,81],1,1/60,'car',2);
check($s->navigationTarget()['z'] === 160.0, 'The arrow and map target advances to the finish');
$s->race->advance($s->course,[0,0,159],[0,0,161],2,1/60,'car',2);
check($s->race->state === 'finished' && $s->navigationTarget()['z'] === 0.0, 'Finished course leads to its restart');
$action('delivery');
check($s->delivery !== null && $s->navigationTarget() === null, 'Package run takes over the navigation');
$action('delivery');
check($s->delivery === null && $s->navigationTarget()['kind'] === 'checkpoint', 'Ending deliveries restores race guidance');
$s->close();
echo "Delivery legs, crossings and shared checkpoint navigation passed.\n";
