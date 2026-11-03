<?php
declare(strict_types=1);

use DoomPhonics\Tools\{FloodBasin,LayeredPrototype,MapConnectivity,RectangularMap,SpatialLayout,TraversalGraph};
use DoomPhonics\Tests\LayeredWalkthrough;

require_once dirname(__DIR__) . '/tools/LayeredPrototype.php';
require_once dirname(__DIR__) . '/tools/MapConnectivity.php';
require_once __DIR__ . '/fixtures/LayeredWalkthrough.php';

$prototype = LayeredPrototype::build();
$graph = MapConnectivity::compile($prototype);
$lowerRegions = ['basement','basement.east-portal','cavern.lower-west-portal','cavern.lower',
	'cavern.lower-east-portal','pump.west-portal','pump'];
$reachable = static fn(array $result,string $region): bool => in_array($region,$result['reachable'],true);
foreach(['dry','shallow'] as $state)
{
	$lower = $graph->search('basement',['water' => $state],false);
	verifyWad($lower['reachable'] === $lowerRegions, "$state water cannot connect the recessed lower doors to the ledge");
	$upper = $graph->search('courtyard',['water' => $state],false);
	verifyWad($reachable($upper,'building.entry') && $reachable($upper,'basement'), 'upper doors connect in both directions and can drop to the lower pair');
	verifyWad(count($upper['traps']) === count($lowerRegions), 'a dry drop from upstairs is reported as a trap when controls are disabled');
}
$full = $graph->search('basement',['water' => 'full'],false);
verifyWad(count($full['reachable']) === count($prototype['regions']) && !$full['traps'], 'full water connects both door pairs and all inhabited regions without traps');
$interactive = $graph->search('basement',['water' => 'dry']);
$modes = array_column($interactive['routes']['balcony'],'mode');
verifyWad($reachable($interactive,'balcony') && !$interactive['traps'], 'an accessible pump permits the complete route from a dry start');
verifyWad(array_search('fill',$modes,true) < array_search('swim',$modes,true), 'the witness route reaches and operates the pump before swimming up');

// Relocating the graph's pump behind its own prerequisite must not unlock it.
$circular = clone $graph;
foreach($circular->edges as &$edge)
if($edge['mode'] === 'fill') $edge['from'] = $edge['to'] = 'courtyard';
unset($edge);
verifyWad(!$reachable($circular->search('basement',['water' => 'dry']),'balcony'), 'a pump on the inaccessible upper ledge creates a detected circular dependency');
foreach([['swim' => false],['height' => 200],['radius' => 65],['step' => 8]] as $profile)
verifyWad(!$reachable(MapConnectivity::compile($prototype,$profile)->search('basement',['water' => 'dry']),'balcony'),
	'player capabilities and clearance affect the route, not just room-level adjacency');
foreach([64,240] as $depth)
{
	$variant = LayeredPrototype::build($depth);
	$routes = MapConnectivity::compile($variant)->search('basement',['water' => 'dry']);
	verifyWad($reachable($routes,'balcony') && !$routes['traps'], 'moving the basement/cavern deeper preserves anchored upper storeys and a solvable flood route');
}

foreach($prototype['pins'] as [$a,$aa,$b,$ba])
verifyWad(SpatialLayout::point($prototype['layers'][$a]['anchors'][$aa]['point'],$prototype['positions'][$a])
	=== SpatialLayout::point($prototype['layers'][$b]['anchors'][$ba]['point'],$prototype['positions'][$b]), 'all architectural doorway and floor/ceiling anchors coincide');
$broken = $prototype['layers'];
$broken['upper']['anchors']['well']['point'][0]++;
$rejected = false;
try { SpatialLayout::solve($broken,$prototype['pins'],'cavern',[0,0,-192]); }
catch(RuntimeException) { $rejected = true; }
verifyWad($rejected,'contradictory wall/plane portal cycles fail before WAD generation');
$rejected = false;
try { SpatialLayout::solve($prototype['layers'],[],'cavern',[0,0,-192]); }
catch(RuntimeException) { $rejected = true; }
verifyWad($rejected,'unanchored layers fail rather than silently moving to the origin');

$level = MapConnectivity::level($prototype);
$waterNames = array_map(static fn(int $id): string => $prototype['cells'][$id]['id'],$prototype['waterSectors']);
sort($waterNames);
$expectedWater = $lowerRegions;
sort($expectedWater);
verifyWad($waterNames === $expectedWater, 'flood fill includes the basement, pump and submerged portal passages, but not the upper ledge');

// A low floor alone is insufficient: water needs an open, below-surface connection.
$floodCells = [
	['x0'=>0,'y0'=>0,'x1'=>64,'y1'=>64,'floor'=>-64,'ceiling'=>128],
	['x0'=>64,'y0'=>0,'x1'=>128,'y1'=>64,'floor'=>-64,'ceiling'=>-16],
	['x0'=>128,'y0'=>0,'x1'=>192,'y1'=>64,'floor'=>0,'ceiling'=>128],
	['x0'=>192,'y0'=>0,'x1'=>256,'y1'=>64,'floor'=>-64,'ceiling'=>128],
	['x0'=>0,'y0'=>64,'x1'=>64,'y1'=>128,'floor'=>-64,'ceiling'=>-64],
	['x0'=>-64,'y0'=>64,'x1'=>0,'y1'=>128,'floor'=>-64,'ceiling'=>128]
];
verifyWad(FloodBasin::find($floodCells,0,0) === [0,1], 'flood fill passes under a low ceiling, but stops at a sill, closed opening and corner-only contact');
verifyWad(FloodBasin::find($floodCells,0,0,[[1,3]]) === [0,1,3], 'an explicit portal carries water to a disconnected packed room');
verifyWad(FloodBasin::find($floodCells,0,1) === [0,1], 'water cannot pass through a ceiling below the neighboring sill');
$openFloodCells = $floodCells;
$openFloodCells[1]['ceiling'] = 128;
verifyWad(FloodBasin::find($openFloodCells,0,1) === [0,1,2,3], 'raising the surface above an open sill connects the next basin');
verifyWad(FloodBasin::find($floodCells,0,-64) === [], 'a surface at the seed floor produces no water volume');

$waterWalk = new LayeredWalkthrough($level);
foreach([['fill',4320,64,0],['drain',4320,192,-192]] as [$action,$x,$y,$height])
{
	$pose = new DoomPhonics\Component\Transform($x,$y,-192,0);
	verifyWad($waterWalk->specials->use($pose,new DoomPhonics\Game\PlayerState), "$action switch remains usable in the flooded pump");
	for($tick = 0; $tick < 200; $tick++) $waterWalk->specials->update(1 / 35);
	foreach($prototype['cells'] as $id => $cell)
	{
		$surface = $level->sectors[$id]->waterSurface(($cell['x0']+$cell['x1'])/2,($cell['y0']+$cell['y1'])/2,$cell['floor'],56);
		verifyWad($surface === ($action === 'fill' && in_array($id,$prototype['waterSectors'],true) ? 0.0 : null),
			"$action updates the shared basin in {$cell['id']} without flooding dry rooms");
	}
}

$collision = new DoomPhonics\Game\Collision($level);
$wallAtlas = DoomPhonics\Graphics\IndexedAtlas::pack([
	'wall:STARTAN3' => new DoomPhonics\Graphics\IndexedImage(64,128,str_repeat("\3",8192),str_repeat("\xff",8192))
]);
$wallMesh = (new DoomPhonics\Render\MapMeshBuilder($level,$wallAtlas))->build();
foreach($level->cameraPortalLinks() as $link)
{
	if($link->originLine === null) continue;
	$front = $level->sectors[$link->originSector];
	$back = $level->sectors[$link->destinationSector];
	verifyWad($front->floor === $back->floor && $front->ceiling === $back->ceiling,
		'the recessed portal apertures have matching floors and ceilings');
}
foreach([64,960] as $edge)
{
	$lineId = null;
	foreach($level->map->linedefs as $id => $line)
	{
		$a = $level->map->vertices[$line['from']]; $b = $level->map->vertices[$line['to']];
		if($a['x'] === $edge && $b['x'] === $edge && min($a['y'],$b['y']) === 0 && max($a['y'],$b['y']) === 256) $lineId = $id;
	}
	verifyWad($lineId !== null && $collision->blocked($lineId,0,0,56) && !$collision->blocked($lineId,0,-192,56),
		'the textured wall above a submerged doorway is solid while its passage remains traversable');
	$upperWalls = array_values(array_filter($wallMesh->wallSpans,static fn(array $span): bool => $span['line'] === $lineId && $span['slot'] === 'upper'));
	verifyWad(count($upperWalls) === 1 && $wallMesh->vertices[$upperWalls[0]['first'] * DoomPhonics\Render\MapMesh::FLOATS + 4] >= 0,
		'the wall above each lower doorway emits a textured opaque upper span');
}
verifyWad($level->sectors[$prototype['regions']['building.well']]->ceiling === 192.0
	&& $level->sectors[$prototype['regions']['building.west']]->ceiling === 160.0,
	'the gallery floor portal has a 32-unit recessed ceiling shaft below it');
foreach($prototype['cells'] as $id => $cell)
verifyWad($level->sectorAt(($cell['x0']+$cell['x1'])/2,($cell['y0']+$cell['y1'])/2)->index === $id,
	'the emitted BSP locates every packed rectangular cell');
foreach($prototype['cells'] as $id => $cell)
foreach([$cell['x0'] + .02,$cell['x1'] - .02] as $x)
foreach([$cell['y0'] + .02,$cell['y1'] - .02] as $y)
verifyWad($level->sectorAt($x,$y)->index === $id, 'fractional positions just inside cell edges retain the correct sector');
$walk = new LayeredWalkthrough($level);
$checkpoints = $walk->run($prototype['regions']);
verifyWad($checkpoints['ledge']['z'] === 0.0 && $checkpoints['stairs']['z'] === 192.0
	&& $checkpoints['balcony']['sector'] === $prototype['regions']['balcony'],
	'a continuously simulated player operates the pump, swims up, climbs the stairs and reaches the balcony');
foreach(LayeredWalkthrough::portalCrossings($level) as $name => $trace)
{
	verifyWad(in_array($trace['destination'],array_column($trace['frames'],'sector'),true), "$name reaches the destination through ordinary player movement");
	foreach($trace['frames'] as $tick => $frame)
	{
		verifyWad($trace['airborne']
			? $frame['z'] === 221.0 && $frame['previousZ'] === 224.0 && $frame['velocityZ'] === -105.0 && !$frame['grounded']
			: $frame['z'] === 192.0 && $frame['previousZ'] === 192.0 && $frame['velocityZ'] === 0.0 && $frame['grounded'],
			"$name tick $tick preserves floor height and render history, or applies exactly one airborne gravity step");
	}
}
foreach(['dry' => -192,'shallow' => -96] as $state => $surface)
{
	$testLevel = MapConnectivity::level($prototype);
	$testLevel->sectors[$prototype['model']]->ceiling = $surface;
	$blocked = new LayeredWalkthrough($testLevel,[512,128,-192]);
	$blocked->pose->angle = M_PI / 2;
	$blocked->command->forward = 1;
	$blocked->command->pitch = .75;
	$blocked->tick(250);
	verifyWad($blocked->position()['sector'] === $prototype['regions']['cavern.lower'], "$state ascent is blocked by real swimming/collision, matching the graph");
}

$budgeted = false;
try { $graph->search('basement',['water' => 'dry'],limit: 1); }
catch(RuntimeException) { $budgeted = true; }
verifyWad($budgeted,'a state budget failure produces no successful connectivity verdict');
$invalid = false;
try { $graph->search('basement',['water' => 'missing']); }
catch(InvalidArgumentException) { $invalid = true; }
verifyWad($invalid,'invalid world-state values fail explicitly');
$overlap = false;
try { RectangularMap::build('BAD',[$prototype['cells'][0],$prototype['cells'][0]]); }
catch(InvalidArgumentException) { $overlap = true; }
verifyWad($overlap,'packed overlap fails instead of generating ambiguous BSP geometry');
verifyWad(LayeredPrototype::build()['lumps'] === $prototype['lumps'],'the prototype map is deterministic');
