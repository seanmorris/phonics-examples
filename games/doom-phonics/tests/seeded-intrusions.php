<?php
declare(strict_types=1);

use DoomPhonics\Tools\{RoomIntrusion,PolygonGeometry,MapConnectivity};
use DoomPhonics\Tests\SeededWalkthrough;

// The reusable subtraction handles an enclosed courtyard, a corner and a half cut.
$original=[[[0,0],[0,1200],[1200,1200],[1200,0]],[[1200,0],[1200,1200],[2400,1200],[2400,0]]];
foreach([
	[[900,300],[900,900],[1500,900],[1500,300]],
	[[1800,600],[1800,1500],[2700,1500],[2700,600]],
	[[0,600],[0,1500],[2700,1500],[2700,600]]
] as $polygon) {
	$cut=RoomIntrusion::partition($original,[0,1],$polygon,$original);
	verifyWad(abs(array_sum(array_map([PolygonGeometry::class,'area'],$cut['partitions']))-2880000)<1e-6,
		'intrusion subtraction preserves the complete footprint before reserving walls');
	foreach($cut['partitions'] as $i=>$p) {
		if($cut['owners'][$i]!==$cut['intruder']) verifyWad(!PolygonGeometry::overlaps($p,$polygon),'old rooms surrender every overlapping area');
		foreach($cut['partitions'] as $j=>$q) if($j>$i) verifyWad(!PolygonGeometry::overlaps($p,$q),'new and surviving partitions never overlap');
	}
}

$cluster=$default['layers']['ground.0']['cluster'];
$outdoors=$cluster['outdoors'];
verifyWad(count($cluster['intrusion']['entrances'])>=2,'an edge garden connects multiple neighboring interiors');
foreach(array_merge($outdoors['courtyard'],$outdoors['garden']) as $id)
verifyWad($default['cells'][$default['regions'][$id]]['ceilingTexture']==='F_SKY1','courtyard and garden both have sky ceilings');
verifyWad($outdoors['rim']!==null && !isset($outdoors['trench']),'the garden has a horizon wall without an incidental center pit');
$rim=array_values(array_filter($default['cells'],static fn($c)=>$c['id']===$outdoors['rim']['region']))[0];
verifyWad(!$rim['traversable'] && $rim['floor']===$rim['ceiling'] && $rim['ceilingTexture']==='F_SKY1',
	'the garden boundary uses a solid collapsed sky sector like MAP01');
// Verify the horizon wall is a real obstruction, independently of graph metadata.
$level=MapConnectivity::level($default);
$rimId=array_search($rim['id'],array_column($default['cells'],'id'),true); $blocked=false;
foreach($level->map->linedefs as $line) {
	$sides=$level->sides($line['index']);
	if($sides['back']===null || !in_array($rimId,[$sides['front'],$sides['back']],true)) continue;
	$a=$level->map->vertices[$line['from']]; $b=$level->map->vertices[$line['to']];
	$dx=$b['x']-$a['x']; $dy=$b['y']-$a['y']; $length=hypot($dx,$dy);
	if($length<128) continue;
	$side=$sides['front']===$rimId ? -1 : 1; $nx=$side*$dy/$length; $ny=-$side*$dx/$length;
	$x=($a['x']+$b['x'])/2; $y=($a['y']+$b['y'])/2;
	$from=$sides['front']===$rimId ? $sides['back'] : $sides['front'];
	$pose=new \DoomPhonics\Component\Transform($x+$nx*24,$y+$ny*24,$level->sectors[$from]->floor);
	$hit=(new \DoomPhonics\Game\Collision($level))->move($pose,new \DoomPhonics\Component\Body(16,56),-$nx*64,-$ny*64);
	$blocked=$hit!==null; break;
}
verifyWad($blocked,'the sky beyond the garden boundary does not let a player leave the map');
