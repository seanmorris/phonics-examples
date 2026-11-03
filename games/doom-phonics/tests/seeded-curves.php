<?php
declare(strict_types=1);

use DoomPhonics\Tools\{SpiralStaircase,RoomJoin,PolygonGeometry,PolygonMap,MapConnectivity,SeededLevel};
use DoomPhonics\Tests\SeededWalkthrough;

// Check the actual inner/outer boundaries, not just a configured width field.
foreach([90,180,270] as $sweep) foreach([4,12,28] as $steps) foreach([false,true] as $mirror) {
	$stair=SpiralStaircase::build(steps:$steps,sweep:$sweep,mirror:$mirror);
	$cells=$stair['cells'];
	foreach($cells as $i=>$cell) {
		foreach($cell['polygon'] as [$x,$y]) verifyWad($x===(int)$x && $y===(int)$y,'curved stair vertices are exact WAD integers');
		foreach($cells as $j=>$other) if($j>$i) verifyWad(!PolygonGeometry::overlaps($cell['polygon'],$other['polygon']),'curved stair treads and landings never overlap');
		if($i===0 || $i===count($cells)-1) continue;
		$p=$cell['polygon'];
		// Reflection reverses winding: its outer boundary is now the first pair.
		[$a,$b,$c,$d]=$p;
		$length=hypot($b[0]-$a[0],$b[1]-$a[1]);
		$distance=static fn($v)=>abs(($b[0]-$a[0])*($v[1]-$a[1])-($b[1]-$a[1])*($v[0]-$a[0]))/$length;
		verifyWad(min($distance($c),$distance($d))>=160,'every curved hall tread preserves at least the requested clear width');
		verifyWad(min($length,hypot($d[0]-$c[0],$d[1]-$c[1]))>=48,'the inner tread never tapers to an unusable point');
		verifyWad($cell['floor']-$cells[$i-1]['floor']===16,'curved steps rise evenly while turning');
	}
	$regions=array_flip(array_column($cells,'id')); $model=count($cells);
	$cells[]=PolygonGeometry::cell(['id'=>'model','light'=>192,'tag'=>0,'floor'=>-256,'ceiling'=>-256,'floorTexture'=>'FLOOR0_1','ceilingTexture'=>'CEIL3_5'],[[-1024,-1024],[-1024,-960],[-960,-960],[-960,-1024]]);
	$fixture=['map'=>'CURVE','cells'=>$cells,'regions'=>$regions,'model'=>$model,'depth'=>256,'connections'=>[],'actions'=>[],'falls'=>[],
		'lumps'=>PolygonMap::build('CURVE',$cells),'start'=>'landing-in'];
	$graph=MapConnectivity::compile($fixture); $level=MapConnectivity::level($fixture);
	$up=$graph->search('landing-in',['water'=>'dry'],false)['routes']['landing-out'];
	$down=$graph->search('landing-out',['water'=>'dry'],false)['routes']['landing-in'];
	$trace=SeededWalkthrough::run($level,$fixture,[...$up,...$down]);
	verifyWad(end($trace)['sector']===$regions['landing-in'],'ordinary player motion traverses the complete curved hall in both directions');
}

$fields=['floor'=>0,'ceiling'=>192];
$a=PolygonGeometry::cell(['id'=>'a']+$fields,[[0,0],[0,768],[512,768],[512,0]]);
$b=PolygonGeometry::cell(['id'=>'b']+$fields,[[640,384],[640,768],[1152,768],[1152,384]]);
$join=RoomJoin::candidate($a,$b,'east');
verifyWad($join!==null && !$join['rectangular'] && $join['gap']===128,'a short hall between offset rectangles is an L-shaped join candidate');
$union=PolygonGeometry::union([$a['polygon'],$b['polygon'],$join['band']]);
verifyWad(abs(array_sum(array_map([PolygonGeometry::class,'area'],$union))-$join['area'])<1e-6
	&& $join['area']<1152*768,'joining preserves the L-shaped recess instead of filling a bounding rectangle');
$far=PolygonGeometry::translate($b,512,0,0);
verifyWad(RoomJoin::candidate($a,$far,'east')===null,'long hallways do not qualify as short room joins');
$thin=PolygonGeometry::cell(['id'=>'thin']+$fields,[[640,704],[640,1216],[1152,1216],[1152,704]]);
verifyWad(RoomJoin::candidate($a,$thin,'east')===null,'a pinched overlapping span cannot become a joined room');

verifyWad((bool)array_filter($default['repairs'],static fn($r)=>in_array($r['kind'],['merge','remnant-merge','clearance'],true)),'room shaping records merges or their clearance repairs');
foreach($default['merged'] as $join) verifyWad($join['hallLength']<=RoomJoin::MAX_HALL && $join['area']<=RoomJoin::MAX_AREA,
	'generated joins honor distance and combined-room size limits');
$corners=0;
foreach($default['layers'] as $name=>$layer) {
	if(isset($layer['footprint']) && !isset($layer['cluster']) && $name!=='basement.0')
	verifyWad(max($layer['footprint']['dimensions'])<=4096,'room footprints remain bounded after fitting anchors and merged subdivisions');
	foreach($layer['corridors']??[] as $corridor) verifyWad($corridor['width']>=128,'generated hallways no longer use the cramped half-width profile');
	if(!isset($layer['cornerStair'])) continue;
	$corners++; $corner=$layer['cornerStair'];
	verifyWad(isset($corner['exit']) && $layer['elevation']['floors'][$corner['exit']]===max($layer['elevation']['floors']),
		'the corner stair now serves a higher entrance instead of an isolated ledge'); $fixture=$default; $fixture['start']=$corner['low'];
	$up=$default['graph']->search($corner['low'],['water'=>'dry'],false)['routes'][$corner['ledge']];
	verifyWad(count(array_filter($up,static fn($e)=>str_contains($e['to'],'.curve.step-')))===$corner['steps'],'reaching the ledge requires the gradually turning quarter staircase');
	$down=$default['graph']->search($corner['ledge'],['water'=>'dry'],false)['routes'][$corner['low']];
	$trace=SeededWalkthrough::run(MapConnectivity::level($default),$fixture,[...$up,...$down]);
	verifyWad(end($trace)['sector']===$default['regions'][$corner['low']],'the room corner stair and full-length ledge have a real return route');
}
verifyWad($default['layers']['spiral']['landing']>=256,'the curved connector reserves a complete top landing');
