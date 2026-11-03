<?php
declare(strict_types=1);

use DoomPhonics\Tools\{SeededLevel,SeedSequence,MapConnectivity,SpatialLayout,RectangularMap,LiftTraversal,PolygonGeometry,PolygonMap,RoomCluster};
use DoomPhonics\Tests\SeededWalkthrough;

require_once dirname(__DIR__) . '/tools/SeededLevel.php';
require_once __DIR__ . '/fixtures/SeededWalkthrough.php';

// Convex decomposition cells can be narrow; measure the logical room's exterior.
function verifyClusterInteriors(array $cells): void
{
	$owners=[];
	foreach($cells as $cell) if(isset($cell['room']) && ($cell['traversable']??true)) $owners[$cell['room']][]=$cell;
	foreach($owners as $name=>$parts) {
		$edges=\DoomPhonics\Tools\RoomOutline::edges($parts); $spacious=false;
		foreach($parts as $part) {
			[$x,$y]=$part['center']; $clearance=INF;
			foreach($edges as [$a,$b]) {
				$dx=$b[0]-$a[0]; $dy=$b[1]-$a[1]; $l2=$dx*$dx+$dy*$dy;
				if($l2<1e-8) continue;
				$t=max(0,min(1,(($x-$a[0])*$dx+($y-$a[1])*$dy)/$l2));
				$clearance=min($clearance,hypot($x-$a[0]-$t*$dx,$y-$a[1]-$t*$dy));
			}
			if($clearance>=RoomCluster::MIN_ROOM_SIZE/2-1e-6) { $spacious=true; break; }
		}
		verifyWad($spacious,'logical cluster room '.$name.' retains a 384-unit clear interior despite decomposition seams');
	}
}

$clusterOutlines=[];
foreach(range(0,7) as $variant)
{
	$cluster=RoomCluster::packed('cluster',0,SeededLevel::HEADROOM,$variant);
	$clusterOutlines[]=json_encode($cluster['outline']);
	$footprint=RoomCluster::ENVELOPE_SIZE-2*RoomCluster::APPROACH;
	verifyWad(count($cluster['outline'])>4 && PolygonGeometry::area($cluster['outline'])<$footprint*$footprint,
		'cluster exteriors have seed-dependent angled boundaries instead of rectangular containers');
	verifyClusterInteriors($cluster['cells']);
	verifyWad(count($cluster['cells'][0]['polygon'])>=4 && !PolygonGeometry::overlaps($cluster['cells'][0]['polygon'],$cluster['intrusion']['polygon']),
		'the invading polygon clips the courtyard outline as well as its surrounding rooms');
	$radialWalls=0;
	foreach($cluster['walls'] as $wall) {
		[$nx,$ny]=$wall['normal']; $length=hypot($nx,$ny);
		verifyWad(abs(($wall['faces'][1]-$wall['faces'][0])/$length-$wall['thickness'])<1e-6,
			'shared walls measure perpendicular thickness between parallel faces');
		if(abs($nx*$ny)===2) $radialWalls++;
	}

	$union=PolygonGeometry::union($cluster['outlineComponents']);
	verifyWad(abs(array_sum(array_map([PolygonGeometry::class,'area'],$union))-PolygonGeometry::area($cluster['outline']))<1e-6,
		'cluster contour follows the actual polygon union without filling exterior recesses');
	foreach($cluster['cells'] as $cell) {
		$covered=0;
		foreach($union as $piece) $covered+=PolygonGeometry::area(PolygonGeometry::intersect($cell['polygon'],$piece));
		verifyWad(abs($covered-PolygonGeometry::area($cell['polygon']))<1e-6,'packed rooms and doorways stay inside their preselected exterior');
	}

	verifyWad($radialWalls>0 && $radialWalls<8,'the radial fan is interrupted by larger chambers without internal dividing walls');
}
verifyWad(count(array_unique($clusterOutlines))>=4,'outline-first clusters vary orientation and corner proportions');

$kinds = $handedness = $hashes = [];
$uphill=[[],[]];
foreach(['first-light','0','1','2','3','4','5','6','7','8','9','10','11','12','13','14','15','16','17','18','19','a < b & "c"','雪'] as $i=>$seed)
{
	try { $p = SeededLevel::build($seed,2+$i%15); }
	catch(Throwable $error) { throw new RuntimeException('Seed '.json_encode($seed).', rooms '.(2+$i%15).': '.$error->getMessage(),0,$error); }
	foreach($p['topology'] as $network) foreach($network['elevation']['direction'] as $axis=>$direction) $uphill[$axis][$direction]=true;
	$hashes[] = hash('sha256',RectangularMap::archive($p['lumps']));
	$handedness[(int)$p['layers']['spiral']['mirror']] = true;
	foreach($p['repairs'] as $repair) $kinds[$repair['kind']] = true;
	verifyWad(!$p['overlaps'],'generated layers do not overlap their architectural air volumes');
	foreach($p['checks'] as $check)
	verifyWad($check['reachable'] === count($p['regions']) && $check['traps'] === 0,
		'all generated regions have an accessible route and return path from each initial water state');
	foreach($p['pins'] as [$a,$aa,$b,$ba])
	verifyWad(SpatialLayout::point($p['layers'][$a]['anchors'][$aa]['point'],$p['positions'][$a])
		=== SpatialLayout::point($p['layers'][$b]['anchors'][$ba]['point'],$p['positions'][$b]),'layered doorway frames agree in architectural space');
	$level = MapConnectivity::level($p);
	$lowApertures=['water-model'];
	foreach($p['doors'] as $door)
	array_push($lowApertures,$door['region'],...array_column($door['faces'],'neighbor'));
	foreach($p['cells'] as $cell)
	{
		if(($cell['traversable']??true) && !in_array($cell['id'],$lowApertures,true))
		verifyWad($cell['ceiling']-$cell['floor']>=SeededLevel::HEADROOM,
			'ordinary rooms, corridors, lift landings and spiral steps retain at least 192 units of headroom');
		foreach(PolygonGeometry::points($cell) as [$x,$y])
		verifyWad(min($x,$y)>=-32768 && max($x,$y)<=32767,'enlarged room packing remains within classic WAD coordinate limits');
	}
	foreach($level->cameraPortalLinks() as $link)
	{
		if($link->originLine === null) continue;
		$a = $level->sectors[$link->originSector]; $b = $level->sectors[$link->destinationSector];
		verifyWad($a->floor === $b->floor && $a->ceiling === $b->ceiling,
			'generated portal apertures match in height without a vertical warp or exposed sky');
	}
	foreach($p['cells'] as $id=>$c)
	verifyWad($level->sectorAt(...($c['center'] ?? [($c['x0']+$c['x1'])/2,($c['y0']+$c['y1'])/2]))->index === $id,'generated BSP identifies every convex region');
	$area = array_sum(array_map(static fn($polygon)=>PolygonGeometry::area($polygon),$level->map->subsectorPolygons()));
	$authored = array_sum(array_map(static fn($cell)=>PolygonGeometry::area(PolygonGeometry::points($cell)),$p['cells']));
	verifyWad(abs($area-$authored)<.01,'BSP reconstruction preserves all floor polygons without gaps or overlapping pieces');
	foreach($p['layers'] as $layer)
	if(isset($layer['cluster']))
	{
		$cluster=$layer['cluster'];
		foreach($cluster['walls'] as $wall)
		verifyWad($wall['thickness'] >= 48-1e-6,'conforming and central-room wall faces retain at least 48 units of solid thickness');
		foreach($layer['cells'] as $cell)
		if(isset($cell['joins'])) verifyWad($cell['clearWidth']>=128,'cluster doorways retain player clearance along parallel walls');
		verifyWad(count($cluster['rooms'])>1,'clusters contain several independently navigable rooms');
		if($cluster['kind']==='conforming') verifyClusterInteriors($layer['cells']);
	}
	$dry = $p['graph']->search($p['start'],['water'=>'dry'],false);
	verifyWad(!in_array($p['goal'],$dry['reachable'],true),'the flood prerequisite is not bypassed by abstract room adjacency');
	$full = $p['graph']->search($p['start'],['water'=>'dry']);
	$trace = SeededWalkthrough::run($level,$p,$full['routes'][$p['goal']]);
	verifyWad(end($trace)['sector'] === $p['regions'][$p['goal']],'a continuously simulated player reaches the goal without position corrections');
	$return = $p['graph']->search($p['goal'],['water'=>'full']);
	$roundTrip = SeededWalkthrough::run(MapConnectivity::level($p),$p,array_merge($full['routes'][$p['goal']],$return['routes'][$p['start']]));
	verifyWad(end($roundTrip)['sector'] === $p['regions'][$p['start']],'the soulsphere room has a physical return route, not just an abstract reachable flag');
	foreach($p['layers'] as $name=>$layer)
	{
		if($layer['plane'] === 'connector') continue;
		$core = array_values(array_filter($layer['cells'],static fn($c)=>preg_match('/\.(core|.*wing)$/',$c['id'])));
		if(!$core) $core=$layer['cells'];
		$area = array_sum(array_map(static fn($c)=>PolygonGeometry::area(PolygonGeometry::points($c)),$core));
		$bounds = (max(array_column($core,'x1'))-min(array_column($core,'x0')))*(max(array_column($core,'y1'))-min(array_column($core,'y0')));
		verifyWad($area <= $bounds+1e-6,'ordinary room core '.$name.' in seed '.$seed.' fits its footprint: '.$area.' / '.$bounds);
		foreach($layer['corridors'] as $port=>$corridor)
		{
			$byId = array_column($layer['cells'],null,'id');
			$floors = [$corridor['from']];
			foreach($corridor['cells'] as $id) $floors[] = $byId[$id]['floor'];
			$floors[] = $corridor['to'];
			$sign = $corridor['to'] <=> $corridor['from'];
			for($j=1;$j<count($floors);$j++)
			verifyWad(abs($floors[$j]-$floors[$j-1]) <= 16 && ($sign ? ($floors[$j]-$floors[$j-1])*$sign >= 0 : $floors[$j] === $floors[0]),
				'hallway elevations are monotonic, including level joins and bend landings');
		}
	}
	foreach($p['merged'] as $join)
	{
		[$a,$b] = $join['rooms'];
		verifyWad($p['layers'][$a]['localFloor'] === $p['layers'][$b]['localFloor'] && $p['layers'][$a]['ceiling'] === $p['layers'][$b]['ceiling'],
			'fused neighbors share an uninterrupted floor and ceiling');
		$opening = 0;
		foreach($level->map->linedefs as $line)
		{
			$sides = $level->sides($line['index']);
			$pair = [$p['cells'][$sides['front']]['layer'] ?? null,$p['cells'][$sides['back'] ?? -1]['layer'] ?? null];
			if($pair !== [$a,$b] && $pair !== [$b,$a]) continue;
			$from=$level->map->vertices[$line['from']];$to=$level->map->vertices[$line['to']];
			$opening += hypot($to['x']-$from['x'],$to['y']-$from['y']);
			verifyWad($line['action'] === 0,'merged rooms share physical open boundaries instead of portals or switches');
		}
		verifyWad($opening >= 384,'neighboring room polygons meet through a broad merged opening');
	}
	foreach($p['lifts'] as $lift)
	{
		$l = MapConnectivity::level($p);
		$edges = array_values(array_filter($p['graph']->edges,static fn($e)=>$e['mode'] === 'elevator' && in_array($e['from'],[$lift['low'],$lift['platform'],$lift['high']],true)));
		$visit = [];
		foreach([[$lift['low'],$lift['platform']],[$lift['platform'],$lift['high']],[$lift['high'],$lift['low']]] as [$a,$b])
		$visit[] = array_values(array_filter($edges,static fn($e)=>$e['from']===$a && $e['to']===$b))[0];
		$local = $p; $local['start'] = $lift['low'];
		$trace = SeededWalkthrough::run($l,$local,array_merge($visit,$visit));
		verifyWad(end($trace)['sector'] === $p['regions'][$lift['low']],'every lift can be called from above and below on repeated physical visits');
		// Reproduce the original raised-doorway trap: the old low roof must fail certification.
		$l->sectors[$p['regions'][$lift['low']]]->ceiling = $lift['highPoint'][2]+32;
		$trapped = false;
		try { LiftTraversal::verify($l,$lift,$p['regions'],['radius'=>16,'height'=>56,'step'=>24]); }
		catch(LogicException $e) { $trapped = str_contains($e->getMessage(),'traps a player'); }
		verifyWad($trapped,'a lift doorway with only 32 units above the upper floor is rejected');
	}
	$trace = SeededWalkthrough::spiral($level,$p['layers']['spiral']);
	verifyWad(end($trace)['z'] === (float)$p['layers']['spiral']['height'],'ordinary movement climbs each turning tread in the curved stair hall');
}
foreach($uphill as $directions) verifyWad(count($directions)===2,'each independent uphill axis varies its direction across seeds');
verifyWad(count($handedness) === 2 && count(array_unique($hashes)) === count($hashes),'seeds vary handedness and actual map geometry');
foreach(['clearance','layer-height'] as $kind)
verifyWad(isset($kinds[$kind]),'seed coverage exercises the ' . $kind . ' recovery option');
$default = SeededLevel::build();
verifyWad($default['rooms']===14 && count($default['verticalConnections'])>=3,
	'default layers have fourteen requested candidates per layer and vertical portal routes');
foreach($default['topology'] as $network)
{
	$degree=array_fill(0,count($network['rooms']),0);
	foreach($network['edges'] as $edge) { $degree[$edge['from']]++; $degree[$edge['to']]++; }
	verifyWad(count($network['edges'])>=($network['surviving']??count($network['rooms']))-1 && max($degree)>=3,
		'each expanded layer retains a connected planar graph with branching choices');
}
verifyWad($default['lumps'] === SeededLevel::build()['lumps'],'repeating a seed produces identical WAD map bytes');
$native = MapConnectivity::level($default);
$routes = $default['graph']->search($default['start'],['water'=>'dry'])['routes'];
foreach($default['falls'] as [$from,$to])
{
	$a=$default['regions'][$from]; $b=$default['regions'][$to];
	$down=$native->cameraPortalForPlane($a,0)?->link;
	$up=$native->cameraPortalForPlane($b,1)?->link;
	verifyWad($down?->destinationSector===$b && $up?->destinationSector===$a,
		'each new shaft has reciprocal floor and ceiling views to its paired room');
	$edge=array_values(array_filter($default['graph']->edges,static fn($e)=>$e['mode']==='floor-portal' && $e['from']===$from))[0];
	$return=$default['graph']->search($edge['to'],['water'=>'full'])['routes'][$default['start']];
	$route=array_merge($routes[$from],[$edge],$return);
	$trace=SeededWalkthrough::run(MapConnectivity::level($default),$default,$route);
	verifyWad(end($trace)['sector']===$default['regions'][$default['start']],
		'walking into every vertical shaft, landing and returning to spawn works without position patches');
}
foreach(['ground.0','upper.0'] as $clusterName)
foreach($default['layers'][$clusterName]['cluster']['rooms'] as $name)
{
	if(!isset($default['regions'][$name])) continue;
	$route = $routes[$name];
	// Include the fall: merely reaching the well edge is not a successful traversal.
	if($name === 'upper.0.core')
	{
		$route[] = array_values(array_filter($default['graph']->edges,static fn($e)=>$e['mode']==='floor-portal'))[0];
		$name = 'basement.0.arrival';
	}
	$trace = SeededWalkthrough::run(MapConnectivity::level($default),$default,$route);
	verifyWad(end($trace)['sector']===$default['regions'][$name],'ordinary movement reaches every room in the packed cluster and falls through the central well');
}
$phases = $default['timings'];
verifyWad(min($phases)>=0 && $phases['totalMs']>0
	&& abs($phases['totalMs']-$phases['layoutMs']-$phases['wadMs']-$phases['validationMs']-$phases['recoveryMs'])<.001,
	'generation measures monotonic nonnegative phase durations that account for the total');
verifyWad(!str_contains(serialize($default['lumps']),'totalMs'),'elapsed timings stay out of reproducible WAD bytes');
$building = SeededLevel::build('building',5,'building');
verifyWad(count(array_filter($building['layers']['ground.0']['cells'],static fn($c)=>str_contains($c['id'],'wing'))) === 0,
	'building-floor mode explicitly permits rectangular cores');
$interleaved = new SeedSequence('repro');
$first = $interleaved->integer(-10,10);
mt_srand(19); mt_rand();
$second = $interleaved->integer(-10,10);
$isolated = new SeedSequence('repro');
verifyWad([$first,$second] === [$isolated->integer(-10,10),$isolated->integer(-10,10)],'seed generation does not depend on PHP global random state');
// Break the prerequisite deliberately: the checker must find the circular dependency.
$bad = clone $default['graph'];
foreach($bad->edges as &$edge)
if($edge['mode'] === 'fill') $edge['from'] = $edge['to'] = $default['goal'];
unset($edge);
verifyWad(!in_array($default['goal'],$bad->search($default['start'],['water'=>'dry'])['reachable'],true),
	'an inaccessible pump cannot falsely certify a generated level');
$packed = $default['layers'];
$packed['upper.0']['architectural'] = $packed['ground.0']['architectural'];
verifyWad((bool)SeededLevel::overlaps($packed),'architectural overlap checks catch conflicting storeys even when WAD packing is disjoint');
foreach([1,17] as $rooms)
{
	$rejected = false;
	try { SeededLevel::build('invalid',$rooms); } catch(InvalidArgumentException) { $rejected = true; }
	verifyWad($rejected,'unsupported room counts fail as parameter errors, not disconnected generated levels');
}

require __DIR__ . '/seeded-details.php';

require __DIR__.'/seeded-footprints.php';

require __DIR__.'/seeded-intrusions.php';

require __DIR__ . "/seeded-curves.php";

require __DIR__ . "/seeded-swimming.php";

require __DIR__.'/seeded-elevations.php';

require __DIR__.'/seeded-organic.php';
