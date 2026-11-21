<?php
declare(strict_types=1);

require_once (getenv('PHONICS_TERRAIN_ROOT') ?: dirname(__DIR__,3).'/packages/terrain-phonics').'/autoload.php';
require_once dirname(__DIR__).'/autoload.php';

use TerrainPhonics\Camera;
use TerrainPhonics\Landscape;
use TerrainSandbox\DeliveryRun;
use TerrainSandbox\DriveRegion;
use TerrainSandbox\PickupSearch;
use TerrainSandbox\Session;

function check(bool $ok, string $message): void
{
	if(!$ok) throw new RuntimeException($message);
}

function search(PickupSearch $planner, array $start, Camera $camera, float $phase = 0.): array
{
	$find = $planner->find($start,$camera,16/9,$phase);
	$work = iterator_count($find);
	return [$find->getReturn(),$work];
}

$flat = ['worldSize'=>16384,'spacing'=>1,'heightScale'=>0.,'detailAmplitude'=>0.];
$origin = ['x'=>0.,'z'=>0.,'y'=>0.];
$camera = new Camera;
$camera->position = [0.,3.,0.];

$land = new Landscape($flat);
$start = fn() => search(new PickupSearch($land,-2.,$origin),$origin,$camera);
$plain = $start();
$land->queue(['type'=>'material','x'=>0,'z'=>0,'radius'=>6000,'material'=>'grass']);
$land->commit();
check($plain[0] !== null && $plain === $start(), 'Painting flat ground keeps the same pickup and the same search work');
$land->undo();
check($plain === $start(), 'An undone edit leaves the flat-ground shortcut available');

$planner = new PickupSearch($land,-2.,$origin);
$above = new Camera;
$above->position = [0.,5.,0.]; $above->pitch = 1.4;
$sites = []; $quadrants = [];
for($i=0; $i<8; $i++)
{
	[$site] = search($planner,$origin,$above,0.); // Even the same starting phase must distribute published boxes.
	check($site !== null && !DeliveryRun::visible($site,$above,16/9), 'Every delivery has a hidden pickup');
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
[$refittedSite] = search($planner->refitted($land,-2.,$origin),$origin,$above,0.);
foreach(array_slice($sites,-3) as $old)
	check($refittedSite !== null && hypot($refittedSite['x']-$old['x'],$refittedSite['z']-$old['z']) >= .30*DeliveryRun::MAX_LEG,
		'Refitting the route after a terrain edit preserves the spacing from published boxes');
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
$land = new Landscape($flat); $land->queue($moat); $land->commit();
$planner = new PickupSearch($land,-5.,$origin);
[$box,$work] = search($planner,$origin,$camera);
check($box !== null && max(abs($box['x']),abs($box['z']))<=64 && $work<2500,
	'The search exhausts the small depot component before repeatedly exploring the mainland');
$planner->placed($box);
[$next,$work] = search($planner,$origin,$camera,1.79);
check($next !== null && $work<350 && hypot($next['x']-$box['x'],$next['z']-$box['z'])>32,
	'A second island leg reuses the depot component and picks a distinct destination');
$land->close();

$land = new Landscape(['worldSize'=>1536]+$flat); $land->queue($moat); $land->commit();
$planner = new PickupSearch($land,-5.,$origin);
[$box] = search($planner,['x'=>512.,'z'=>0.,'y'=>0.],$camera);
$regions = (new ReflectionProperty(PickupSearch::class,'regions'))->getValue($planner);
check($box === null && count($regions)===2 && isset($regions[64],$regions[16]),
	'A separated car checks each actual grid once when two caps round to 64 metres');
$land->close();

// The bend is on a 96 m row halfway between two 192 m rows. The road before it is visible.
$land = new Landscape($flat);
$land->queue(['type'=>'flatten','x'=>3600,'z'=>-1440,'height'=>4,'radius'=>1050]);
foreach([[[0,4,0],[0,4,-1440]],[[0,4,-1440],[1440,4,-1440]],
	[[1440,4,-1440],[2880,4,-1440]],[[2880,4,-1440],[3600,4,-1440]]] as $points)
	$land->queue(['type'=>'road','points'=>$points,'width'=>22,'shoulder'=>3,'crown'=>0]);
$land->commit();
$start = ['x'=>0.,'z'=>0.,'y'=>4.];
$camera->position = [0.,6.55,6.7]; $camera->pitch = -.20;
$planner = new PickupSearch($land,1.,$start);
[$box] = search($planner,$start,$camera);
check($box !== null && $box['x']>2700 && !DeliveryRun::visible($box,$camera,16/9),
	'The finer grid follows the distant bend to a hidden box on the island');
$planner->placed($box);
[$second] = search($planner,$start,$camera);
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
	'A camera retry enumerates only the discovered sites with no flood work');
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
	check(!$probe->getReturn(), 'The sharp tyre-track rise between the first-light canyon probes rejects the link');
}
$canyon->close();
echo "Delivery search, grade, cache and spacing checks passed.\n";
