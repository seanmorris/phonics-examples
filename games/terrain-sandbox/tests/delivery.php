<?php
declare(strict_types=1);

require_once (getenv('PHONICS_TERRAIN_ROOT') ?: dirname(__DIR__, 3).'/packages/terrain-phonics').'/autoload.php';
require_once dirname(__DIR__).'/autoload.php';

use TerrainPhonics\Landscape;
use TerrainPhonics\Recipe;
use TerrainSandbox\DeliveryRun;
use TerrainSandbox\DriveRegion;
use TerrainSandbox\Session;
use TerrainSandbox\PickupSearch;

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
	if($kind === 'pickup') check(!DeliveryRun::visible($site,$s->camera,16/9), 'Entire plume outside view at spawn');
	check($site['y'] === $s->landscape->height($site['x'],$site['z']) && $site['y'] > $s->water->level(), 'Site on dry ground');
	return $site;
};
$pickup = $next('pickup');
$depot = $s->delivery->depot;
check($depot['kind'] === 'drop' && $s->delivery->sites() === [$depot,$pickup], 'Depot and pickup both appear from the first leg');
check(hypot($pickup['x']-$depot['x'],$pickup['z']-$depot['z']) >= .69*DeliveryRun::MAX_LEG, 'Open land uses the distant band');
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
check($cross($from,$to) !== null && $s->delivery->carrying && $s->navigationTarget() === $depot && $s->delivery->sites() === [$depot], 'Collecting removes only the box and points straight back to the depot');
$drop = $next('drop');
check($drop !== $pickup, 'The drop is a distinct leg');
$site = $s->car->checkedSite($s->landscape,-10,$drop['x'],$drop['z'],0,[]);
$s->car->spawn($site,$s->landscape,-10);
$s->step(1/60);
check($s->delivery->delivered === 1 && !$s->delivery->carrying, 'Drive into the drop to complete delivery');
$second = $next('pickup');
check($s->delivery->sites() === [$depot,$second] && $second !== $pickup, 'The original depot stays marked for a different second package');
$s->input(['values'=>['waterLevel'=>2]],1/60,16/9);
check($s->navigationTarget() === null, 'Rising water retires the submerged box immediately');
check($s->delivery->sites() === [$depot], 'Flooding preserves the permanent depot marker');
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
// The old 64-metre grade average accepted this level eight-metre-high shelf with a narrow vertical lip.
$relief = new Landscape(['worldSize'=>1164,'spacing'=>1,'heightScale'=>0,'detailAmplitude'=>0,'surround'=>'none']);
$relief->queue(['type'=>'road','points'=>[[176,8,0],[208,8,0]],'width'=>28,'shoulder'=>1,'crown'=>0]);
$relief->commit();
$start = ['x'=>.5,'z'=>.25,'y'=>0.];
$region = new DriveRegion($relief,-5.,$start);
$reachable = static function(DriveRegion $region, float $x, float $z): bool {
	$id = $region->nearest($x,$z);
	if($id === null) return false;
	$probe = $region->connect($id);
	foreach($probe as $_) {}
	return $probe->getReturn();
};
check($relief->height(192,0) === 8. && !$reachable($region,192,0), 'A flat mesa beyond a short cliff is disconnected');
check($reachable($region,384,0), 'The floor on the far side remains reachable around the shelf');
$onShelf = $region->containsPosition(193.,1.);
foreach($onShelf as $_) {}
check(!$onShelf->getReturn(), 'An off-grid car on the shelf cannot borrow the depot component');
$before = $relief->stats()['generated'];
check($reachable($region,384,0) && $relief->stats()['generated'] === $before, 'A known route requires no terrain samples');
$relief->queue(['type'=>'road','points'=>[[96,0,0],[176,8,0],[208,8,0]],'width'=>16,'shoulder'=>1,'crown'=>0]);
$relief->commit();
check($reachable(new DriveRegion($relief,-5.,$start),192,0), 'A new gentle ramp connects the previously isolated shelf');
$relief->close();

// The dry endpoints even have identical heights. A narrow submerged channel cuts all the way across the map.
$channel = new Landscape(['worldSize'=>1164,'spacing'=>1,'heightScale'=>0,'detailAmplitude'=>0,'surround'=>'none']);
foreach([[-582,0],[0,582]] as [$from,$to])
	$channel->queue(['type'=>'road','points'=>[[115,-.6,$from],[115,-.6,$to]],'width'=>6,'shoulder'=>4,'crown'=>0]);
$channel->commit();
check(!$reachable(new DriveRegion($channel,-.5,$start),192,0), 'An edge cannot bridge a narrow flooded channel');
check($reachable(new DriveRegion($channel,-5.,$start),192,0), 'Lowering water opens a traversable dry crossing');
$channel->close();

// On the default canyon a straight drive to these sites runs into a mesa. Valley bends make the route possible.
$canyon = new Session;
$canyon->input(['actions'=>[['type'=>'generate','preset'=>'canyon','seed'=>'first-light'],'car']],1/60,16/9);
while($canyon->findingCarSite()) $canyon->input([],1/60,16/9);
$car = $canyon->car->state();
$car['y'] = $canyon->landscape->height($car['x'],$car['z']);
$valley = new Recipe(['wallHeight'=>0.] + $canyon->landscape->recipe());
$planner = new PickupSearch($canyon->landscape,$canyon->water->level(),$car);
foreach([0.,.5,1.] as $phase)
{
	$search = $planner->find($car,$canyon->camera,16/9,$phase);
	foreach($search as $_) {}
	$box = $search->getReturn();
	check($box !== null && hypot($box['x']-$car['x'],$box['z']-$car['z'])>3800, 'Distant canyon-floor pickup');
	check($box['y'] <= $valley->height($box['x'],$box['z'])+24., 'Pickup stays on the local canyon floor');
	$peak = -INF;
	for($i=1;$i<128;$i++)
		$peak = max($peak,$canyon->landscape->height(round($car['x']+($box['x']-$car['x'])*$i/128),
			round($car['z']+($box['z']-$car['z'])*$i/128)));
	check($peak>max($car['y'],$box['y'])+90., 'The direct line climbs above the available floor route');
}
// Even when the driver starts on top, the local, seed-specific valley floor still governs placement.
$rim = null;
for($x=0; $x<=3000 && $rim === null; $x+=96)
{
	$y = $canyon->landscape->height($x,0);
	if($y > $valley->height($x,0)+500.) $rim = ['x'=>(float)$x,'z'=>0.,'y'=>$y];
}
check($rim !== null, 'Fixture has a high natural plateau');
$search = (new PickupSearch($canyon->landscape,$canyon->water->level(),$rim))->find($rim,$canyon->camera,16/9,0.);
foreach($search as $_) {}
check($search->getReturn() === null, 'Starting on the plateau never authorizes a box on its top');
$canyon->close();
// Ordinary car boarding can use a mesa. Starting deliveries from the seat or the fly camera must choose a floor depot.
foreach([false,true] as $alreadyDriving)
{
	$river = new Session;
	$actions = [['type'=>'generate','preset'=>'canyon','seed'=>'river']];
	if($alreadyDriving) $actions[] = 'car';
	$river->input(['actions'=>$actions],1/60,16/9);
	while($river->findingCarSite()) $river->input([],1/60,16/9);
	$floor = PickupSearch::floorTest($river->landscape);
	if($alreadyDriving)
	{
		$seat = $river->car->state();
		check(!$floor($seat['x'],$seat['z'],$seat['y']), 'Regular driving can board on the river mesa');
	}
	$river->input(['actions'=>['delivery']],1/60,16/9);
	while($river->findingCarSite())
	{
		$river->step(1/60);
		check($river->delivery->depot === null, 'An unfinished boarding search cannot anchor the depot');
		$river->input([],1/60,16/9);
	}
	for($i=0; $i<20000 && $river->navigationTarget() === null; $i++) $river->step(1/60);
	$depot = $river->delivery->depot;
	$site = $river->navigationTarget();
	check($site !== null && $floor($depot['x'],$depot['z'],$depot['y']) && $floor($site['x'],$site['z'],$site['y']),
		'River canyon starts a playable floor-to-floor run');
	check(!DeliveryRun::visible($site,$river->camera,16/9), 'River pickup spawns outside the view');
	$river->close();
}

$mountain = new Session;
$mountain->input(['actions'=>[['type'=>'generate','preset'=>'mountain','seed'=>'first-light'],'car']],1/60,16/9);
while($mountain->findingCarSite()) $mountain->input([],1/60,16/9);
$start = $mountain->car->state();
$start['y'] = $mountain->landscape->height($start['x'],$start['z']);
$find = (new PickupSearch($mountain->landscape,$mountain->water->level(),$start))->find($start,$mountain->camera,16/9,0.);
foreach($find as $_) {}
$box = $find->getReturn();
check($box !== null && !DeliveryRun::visible($box,$mountain->camera,16/9)
	&& hypot($box['x']-$start['x'],$box['z']-$start['z'])>500., 'Mountain roads continue inside the innermost distance band');
$mountain->close();

// Only the local grid can leave this 120-metre island. No coarse-grid neighbour of the depot is dry.
$island = new Landscape(['worldSize'=>4096,'heightScale'=>0,'detailAmplitude'=>0]);
$island->queue(['type'=>'road','points'=>[[-58,3,0],[58,3,0]],'width'=>10,'shoulder'=>1,'crown'=>0]);
$island->commit();
$start = ['x'=>0.,'z'=>0.,'y'=>3.,'motion'=>'driving','speed'=>0.,'recoveries'=>0];
$camera = new TerrainPhonics\Camera;
$camera->position = [0.,40.,300.]; $camera->yaw = 0.; $camera->pitch = -.12;
$run = new DeliveryRun;
for($i=0; $i<5000; $i++)
{
	$message = $run->step($island,1.,$camera,1.6,$start,$start);
	if($message !== null) break;
}
check($message !== null && $run->target() === null, 'A fully visible island waits for the view to change');
$camera->pitch = 1.35;
check($run->step($island,1.,$camera,1.6,$start,$start) === 'Searching for a reachable pickup…',
	'A parked camera change clears the exhausted-search message immediately');
for($i=0; $i<5000 && $run->target() === null; $i++) $run->step($island,1.,$camera,1.6,$start,$start);
$box = $run->target();
check($box !== null && $box['y']>1. && abs($box['x'])>=32 && abs($box['z'])<6,
	'Tilting the camera while parked finds the unseen drive along the small island');
$island->close();
echo "Delivery legs, crossings and shared checkpoint navigation passed.\n";
