<?php
declare(strict_types=1);

require_once (getenv('PHONICS_TERRAIN_ROOT') ?: dirname(__DIR__,3).'/packages/terrain-phonics').'/autoload.php';
require_once dirname(__DIR__).'/autoload.php';

use TerrainSandbox\PickupCover;
use TerrainPhonics\Landscape;
use TerrainSandbox\DeliveryRun;
use TerrainSandbox\DriveRegion;
use TerrainSandbox\PickupSearch;
use TerrainSandbox\Session;

function check(bool $ok, string $message): void
{
	if(!$ok) throw new RuntimeException($message);
}

function search(PickupSearch $planner, array $start, float $phase = 0.): array
{
	$find = $planner->find($start,$phase);
	$work = iterator_count($find);
	return [$find->getReturn(),$work];
}

$flat = ['worldSize'=>16384,'spacing'=>1,'heightScale'=>0.,'detailAmplitude'=>0.];
$origin = ['x'=>0.,'z'=>0.,'y'=>0.];


$land = new Landscape($flat);
$start = fn() => search(new PickupSearch($land,-2.,$origin),$origin);
$plain = $start();
$land->queue(['type'=>'material','x'=>0,'z'=>0,'radius'=>6000,'material'=>'grass']);
$land->commit();
check($plain === [null,0] && $plain === $start(), 'Paint cannot hide a package on flat ground or trigger extra route work');
$land->undo();
check($plain === $start(), 'An undone edit leaves the flat-ground shortcut available');

// A low, driveable ridge around the depot offers real cover in all directions.
for($i=0; $i<8; $i++)
	$land->queue(['type'=>'raise','x'=>96*cos($i*M_PI/4),'z'=>96*sin($i*M_PI/4),'radius'=>84,'amount'=>7]);
$land->commit();
$planner = new PickupSearch($land,-2.,$origin);
$sites = []; $quadrants = [];
for($i=0; $i<8; $i++)
{
	[$site] = search($planner,$origin,0.); // Even the same starting phase must distribute published boxes.
	check($site !== null && PickupCover::blocks($land,$origin,$site,$site['cover']), 'Every delivery has a terrain-hidden pickup');
	$angle = atan2($site['x'],$site['z']);
	if($sites) check(cos($angle-$priorAngle) <= .5+1e-9, 'Consecutive open-country legs turn at least sixty degrees');
	foreach(array_slice($sites,-3) as $old)
		check(hypot($site['x']-$old['x'],$site['z']-$old['z']) >= .30*DeliveryRun::MAX_LEG,
			'Open-country boxes clear the last three sites by at least thirty percent of the leg range');
	$quadrants[(int)floor(($angle+M_PI)/(M_PI/2))%4] = true;
	$priorAngle = $angle;
	$sites[] = $site;
	$planner->placed($site);
}
check(count($quadrants)===4, 'A sequence of deliveries explores all four quadrants');
$land->queue(['type'=>'material','x'=>0,'z'=>0,'radius'=>300,'material'=>'grass']);
$land->commit();
[$refittedSite] = search($planner->refitted($land,-2.,$origin),$origin,0.);
foreach(array_slice($sites,-3) as $old)
	check($refittedSite !== null && hypot($refittedSite['x']-$old['x'],$refittedSite['z']-$old['z']) >= .30*DeliveryRun::MAX_LEG,
		'Refitting the route after a terrain edit preserves the spacing from published boxes');
$land->close();

// A low, narrow berm falls between the broad sight probes. Smoke can clear it while the crate is hidden.
$land = new Landscape($flat);
$land->queue(['type'=>'raise','x'=>0,'z'=>121,'radius'=>8,'amount'=>5]);
$land->commit();
$behind = ['x'=>0.,'y'=>0.,'z'=>1024.];
$clear = ['x'=>0.,'y'=>0.,'z'=>-1024.];
$testCover = static function(array $car,array $site) use ($land): ?array {
	$ray = PickupCover::find($land,$car,$site);
	iterator_count($ray);
	return $ray->getReturn();
};
$ridge = $testCover($origin,$behind);
check($ridge !== null && PickupCover::blocks($land,$origin,$behind,$ridge), 'A thin intervening berm hides the crate lid');
check($testCover($origin,$clear) === null && $testCover(['y'=>60.]+$origin,$behind) === null,
	'An open rear sight line and a view from above the berm both expose the crate');
check(!PickupCover::blocks($land,['z'=>140.]+$origin,$behind,$ridge), 'Passing the berm invalidates the saved sight lines');
$land->close();

// A genuinely undulating surface with a small global relief can also certify a link without dense sampling.
$gentle = new Landscape(['heightScale'=>.001,'terraceSpacing'=>0]+$flat);
$region = new DriveRegion($gentle,-2.,['x'=>0.,'z'=>0.,'y'=>$gentle->height(0,0)]);
$link = $region->connect($region->nearest(192.,0.));
check(iterator_count($link)<32 && $link->getReturn(), 'A dry natural surface with bounded relief keeps the sparse path');
$gentle->close();

// Most of the map is dry mainland. Only the inside of this flooded ring connects to the depot.
$moat = ['type'=>'road','points'=>[[-64,-10,-64],[64,-10,-64],[64,-10,64],[-64,-10,64],[-64,-10,-64]],
	'width'=>12,'shoulder'=>1,'crown'=>0];
$land = new Landscape($flat);
$land->queue(['type'=>'road','points'=>[[-48,0,0],[-24,4,0],[0,0,0],[24,4,0],[48,0,0]],
	'width'=>10,'shoulder'=>2,'crown'=>0]);
$land->queue($moat); $land->commit();
$planner = new PickupSearch($land,-5.,$origin);
[$box,$work] = search($planner,$origin);
check($box === null && $work<3000, 'Hidden sites on the small depot island fall inside the one-mile exclusion');
[$next,$work] = search($planner,$origin,1.79);
check($next === null && $work<350, 'Retrying reuses the exhausted depot component');
$land->close();

$land = new Landscape(['worldSize'=>1536]+$flat); $land->queue($moat); $land->commit();
$planner = new PickupSearch($land,-5.,$origin);
[$box] = search($planner,['x'=>512.,'z'=>0.,'y'=>0.]);
check($box === null && (new ReflectionProperty(PickupSearch::class,'regions'))->getValue($planner) === [],
	'A small map with no point a mile from its depot skips the flood entirely');
// A corner depot in the same world can reach a mile, but the observer is isolated inside the moat.
$planner = new PickupSearch($land,-5.,['x'=>700.,'z'=>700.,'y'=>0.]);
[$box] = search($planner,$origin);
$regions = (new ReflectionProperty(PickupSearch::class,'regions'))->getValue($planner);
check($box === null && count($regions)===2 && isset($regions[64],$regions[16]),
	'A separated car checks each actual grid once when two caps round to 64 meters');
$land->close();

// The driver is fifty meters west of the depot. A box at x=1600 would be over a mile
// from the driver, but still nine meters too close to the goal.
$land = new Landscape(['worldSize'=>4096]+$flat);
$land->queue(['type'=>'road','points'=>[[-80,3,0],[1600,3,0]],'width'=>10,'shoulder'=>1,'crown'=>0]);
$land->queue(['type'=>'raise','x'=>96,'z'=>0,'radius'=>54,'amount'=>5]);
$land->commit();
$depot = ['x'=>0.,'y'=>3.,'z'=>0.];
$driver = ['x'=>-50.]+$depot;
$planner = new PickupSearch($land,1.,$depot);
[$box] = search($planner,$driver);
check($box === null, 'A hidden, reachable road end just inside one mile from the depot is excluded');
$land->queue(['type'=>'road','points'=>[[1590,3,0],[1620,3,0]],'width'=>10,'shoulder'=>1,'crown'=>0]);
$land->commit();
[$box] = search($planner->refitted($land,1.,$depot),$driver);
check($box !== null && $box['x'] === 1616.0 && $box['z'] === 0.0,
	'The fine fallback accepts the first reachable grid point beyond one mile');
$land->close();

// The bend is between two coarse rows. A hill screens the distant island across the bend.
$land = new Landscape($flat);
$land->queue(['type'=>'raise','x'=>1750,'z'=>-720,'radius'=>600,'amount'=>200]);
$land->queue(['type'=>'flatten','x'=>3600,'z'=>-1440,'height'=>4,'radius'=>1050]);
foreach([[[0,4,0],[0,4,-1440]],[[0,4,-1440],[1440,4,-1440]],
	[[1440,4,-1440],[2880,4,-1440]],[[2880,4,-1440],[3600,4,-1440]]] as $points)
	$land->queue(['type'=>'road','points'=>$points,'width'=>22,'shoulder'=>3,'crown'=>0]);
$land->commit();
$start = ['x'=>0.,'z'=>0.,'y'=>4.];

$planner = new PickupSearch($land,1.,$start);
[$box] = search($planner,$start);
check($box !== null && $box['x']>2700 && PickupCover::blocks($land,$start,$box,$box['cover']),
	'The finer grid follows the distant bend to a hidden box on the island');
$planner->placed($box);
[$second] = search($planner,$start);
check($second !== null && hypot($second['x']-$box['x'],$second['z']-$box['z'])>200,
	'A single reachable heading offers separated boxes even when the preferred bearing is over water');
$land->close();

// Pause a confined walk after its first site. The next walk replays it and completes the remaining frontier.
$land = new Landscape($flat);
$land->queue(['type'=>'flatten','x'=>0,'z'=>-250,'height'=>6,'radius'=>160]);
$land->queue(['type'=>'road','points'=>[[0,4,0],[0,4,-250]],'width'=>10,'shoulder'=>5,'crown'=>0]);
$land->commit();
$start['y'] = $land->height(0,0);
$region = new DriveRegion($land,1.,$start,16.);
foreach($region->nearby(384.) as $first) if($first !== null) break;
$points = [];
foreach($region->nearby(384.) as $point) if($point !== null) $points[] = $point;
check(count($points)>10 && $points[0] === $first && count(array_unique(array_column($points,0)))>1,
	'The paused walk retains its first site and explores the island');
check(iterator_to_array($region->nearby(384.),false) === $points,
	'A repeated search enumerates only the discovered sites with no flood work');
$land->close();

$canyon = new Session;
$canyon->input(['actions'=>[['type'=>'generate','preset'=>'canyon','seed'=>'first-light']]],1/60,16/9);
$land = $canyon->landscape;
$a = [-1847.,2719.,$land->height(-1847,2719)];
$b = [-1655.,2719.,$land->height(-1655,2719)];
$region = new DriveRegion($land,$canyon->water->level(),['x'=>$a[0],'z'=>$a[1],'y'=>$a[2]]);
$edge = new ReflectionMethod(DriveRegion::class,'driveEdge');
foreach([[$a,$b],[$b,$a]] as [$from,$to])
{
	$probe = $edge->invoke($region,$from,$to);
	iterator_count($probe);
	check(!$probe->getReturn(), 'The sharp tire-track rise between the first-light canyon probes rejects the link');
}
$canyon->input(['actions'=>['delivery']],1/60,16/9);
while($canyon->findingCarSite()) $canyon->input([],1/60,16/9);
$start = $canyon->car->state();
$start['y'] = $land->height($start['x'],$start['z']);
[$box,$work] = search(new PickupSearch($land,$canyon->water->level(),$start),$start,13*M_PI/12);
check($box !== null && hypot($box['x']-$start['x'],$box['z']-$start['z']) > 2000 && $work < 11000,
	'A difficult first canyon bearing gives other distant routes a turn before exhausting its detours');
// The original default canyon sent every phase down the same rear branch. This phase prefers the fork ahead.
[$front] = search(new PickupSearch($land,$canyon->water->level(),$start),$start,5*M_PI/4);
check($front !== null && ($front['x']-$start['x'])*sin($start['yaw'])+($front['z']-$start['z'])*cos($start['yaw']) > 500,
	'A covered box can be more than half a kilometer ahead of the driver on the default canyon floor');
$turned = ['yaw'=>$start['yaw']+M_PI]+$start;
[$same] = search(new PickupSearch($land,$canyon->water->level(),$start),$turned,5*M_PI/4);
check($same === $front && PickupCover::blocks($land,$turned,$front,$front['cover']), 'Reversing heading leaves a fixed-phase hidden site eligible');
$deliveries = new PickupSearch($land,$canyon->water->level(),$start);
$sectors = []; $last = null;
for($leg=0; $leg<9; $leg++) {
	[$box] = search($deliveries,$start,1.79*$leg);
	check($box !== null && isset($box['plumeCover']), 'Successive default canyon deliveries hide the complete plume');
	$dx = $box['x']-$start['x']; $dz = $box['z']-$start['z'];
	$distance = hypot($dx,$dz);
	check($distance>=4200 && $distance<=5600 && PickupCover::hidesPlume($land,$start,$box,$box['plumeCover']),
		'Every default canyon leg lies in the distant depot annulus behind a plateau');
	$run = $best = 0.;
	for($i=1; $i<128; $i++) {
		$t = $i/128; $ground = $land->height($start['x']+$t*$dx,$start['z']+$t*$dz);
		$run = $ground>max($start['y'],$box['y'])+195. ? $run+$distance/128 : 0.;
		$best = max($best,$run);
	}
	check($best>230.,'The direct route crosses a broad section of elevated rock');
	if($last) check(hypot($box['x']-$last['x'],$box['z']-$last['z'])>1000.,'Successive destinations stay apart');
	$sectors[(int)floor((atan2($dx,$dz)+M_PI)/(M_PI/2))%4] = true;
	$last = $box; $deliveries->placed($box);
}
check(count($sectors)>=3,'Deliveries explore multiple branches around the mesas');
$canyon->close();
echo "Delivery search, grade, cache and spacing checks passed.\n";
