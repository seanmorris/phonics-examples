<?php
declare(strict_types=1);

use DoomPhonics\Tools\{MapConnectivity,OutdoorSpace,PolygonGeometry,PolygonMap,RaisedLedge,StairHallway};
use DoomPhonics\Tests\SeededWalkthrough;
use DoomPhonics\Graphics\{IndexedAtlas,TextureCatalog,Palette};
use DoomPhonics\Archive\{Archive,Stack};
use DoomPhonics\Render\MapMeshBuilder;

require_once __DIR__.'/../tools/RaisedLedge.php';

// Verify complete recessed stair routes in both directions, including the turn.
foreach([96,128] as $rise) foreach(range(0,3) as $seed) {
	$body=['id'=>'ledge.core','floor'=>0,'ceiling'=>$rise+192,'light'=>192,'tag'=>0,'floorTexture'=>'FLOOR0_1','ceilingTexture'=>'CEIL3_5'];
	$layer=['plane'=>'basement','cells'=>[PolygonGeometry::cell($body,[[0,0],[0,1280],[1536,1280],[1536,0]])]];
	verifyWad(RaisedLedge::carve($layer,'ledge',(string)$seed),'a broad room fits a recessed wrapping ledge stair');
	verifyWad($layer['terrace']['rise']===$rise,'ledge rises respect the available headroom');
	$cells=$layer['cells']; $area=0;
	foreach($cells as $i=>$cell) {
		$area+=PolygonGeometry::area($cell['polygon']);
		verifyWad($cell['ceiling']-$cell['floor']>=192,'upper ledges retain ordinary standing headroom');
		foreach($cells as $j=>$other) if($j>$i) verifyWad(!PolygonGeometry::overlaps($cell['polygon'],$other['polygon']),'the recessed staircase and ledge never overlap');
	}
	verifyWad(abs($area-1536*1280)<.01,'the raised ledge and its cut-in stairs preserve the complete room footprint');
	$regions=array_flip(array_column($cells,'id')); $model=count($cells);
	$cells[]=PolygonGeometry::cell(array_replace($body,['id'=>'model','floor'=>-256,'ceiling'=>-256]),[[-1024,-1024],[-1024,-960],[-960,-960],[-960,-1024]]);
	$ids=$layer['terrace']['route']; $fixture=['map'=>'LEDGE','cells'=>$cells,'regions'=>$regions,'model'=>$model,'depth'=>256,'connections'=>[],'actions'=>[],'falls'=>[],
		'lumps'=>PolygonMap::build('LEDGE',$cells),'start'=>$ids[0]];
	$graph=MapConnectivity::compile($fixture); $route=[]; $visits=[...$ids,...array_slice(array_reverse($ids),1)];
	foreach($visits as $i=>$target) if($i) {
		$segment=$graph->search($visits[$i-1],['water'=>'dry'],false)['routes'][$target]??null;
		verifyWad($segment!==null,'every successive recessed tread and landing has a continuous route');
		array_push($route,...$segment);
	}
	$trace=SeededWalkthrough::run(MapConnectivity::level($fixture),$fixture,$route);
	verifyWad(end($trace)['sector']===$regions[$ids[0]],'real player commands traverse both recessed flights and return to the lower room');
}

$changes=0;
foreach($default['layers'] as $name=>$layer) {
	if(!isset($layer['elevation'])) continue;
	$changes++; $elevation=$layer['elevation'];
	if($elevation['kind']==='hallway-stairs') {
		foreach($layer['reservations'] as $reservation) verifyWad($reservation['landing']>=256 && $reservation['width']>=256 && $reservation['wallThickness']>=32,'enclosed stair halls retain wide treads, solid side walls and full-width top landings');
	} elseif($elevation['kind']==='ledge-stairs') {
		verifyWad($layer['terrace']['rise']>=96 && $layer['terrace']['landing']>=256,'large room ledges retain a substantial rise and clear top landing');
	} else {
		[$a,$b]=$elevation['ports'];
		verifyWad(abs($elevation['floors'][$a]-$elevation['floors'][$b])>=32,'opposing entrances occupy different room elevations');
		foreach($elevation['floors'] as $port=>$floor)
		verifyWad($layer['corridors'][$port]['from']===$floor,'each approach starts at its own room landing height');
	}
	$owned=array_fill_keys(array_column($layer['cells'],'id'),true); $graph=clone $default['graph'];
	$graph->edges=array_values(array_filter($graph->edges,static fn($e)=>isset($owned[$e['from']],$owned[$e['to']])));
	$entrances=$default['roomNavigation'][$name]['entrances'];
	foreach($entrances as $port=>$entry) foreach($entrances as $exit=>$target) {
		if($port===$exit) continue;
		$route=$graph->search($entry,['water'=>'dry'],false)['routes'][$target]??null;
		verifyWad($route!==null,'different-height entrances connect inside '.$name.': '.$entry.' -> '.$target);
		$fixture=$default; $fixture['start']=$entry;
		$trace=SeededWalkthrough::run(MapConnectivity::level($default),$fixture,$route);
		verifyWad(end($trace)['sector']===$default['regions'][$target],'real player motion reaches every room exit in either direction');
	}
	if($elevation['kind']==='hallway-stairs') {
		foreach($layer['cells'] as $cell) if(str_contains($cell['id'],'.stair-')) {
			verifyWad($cell['floor']%16===0,'entrance stair terraces remain quantized to 16 units');
			if(str_ends_with($cell['id'],'-0')) verifyWad(max($cell['x1']-$cell['x0'],$cell['y1']-$cell['y0'])>=256,'final stair terraces retain a full 256-unit landing before the wall or door');
			foreach($layer['reservations'] as $reservation) {
				if(!PolygonGeometry::overlaps($cell['polygon'],$reservation['polygon'])) continue;
				[$nx,$ny]=$reservation['inward'];
				foreach($layer['cells'] as $other) {
					if($other['id']===$cell['id'] || ($other['role']??'')==='stair-hall') continue;
					$edge=PolygonGeometry::shared($cell['polygon'],$other['polygon']);
					if($edge) verifyWad(abs(($edge[1][0]-$edge[0][0])*$nx+($edge[1][1]-$edge[0][1])*$ny)<1e-6,'stair sides are enclosed by walls instead of exposed to the main room');
				}
			}
		}
		continue;
	}
	if($elevation['kind']==='ledge-stairs') {
		$low=$layer['terrace']['route'][0]; $high=end($layer['terrace']['route']);
		$up=$graph->search($low,['water'=>'dry'],false)['routes'][$high];
		$down=$graph->search($high,['water'=>'dry'],false)['routes'][$low];
		$fixture=$default; $fixture['start']=$low;
		$trace=SeededWalkthrough::run(MapConnectivity::level($default),$fixture,[...$up,...$down]);
		verifyWad(end($trace)['sector']===$default['regions'][$low],'the recessed wrapping stair reaches the ledge and returns through actual player movement');
		continue;
	}
	$interior=array_values(array_filter($layer['cells'],static fn($c)=>in_array($c['id'],$elevation['route'],true)));
	verifyWad(count(array_unique(array_column($interior,'floorTexture')))===1,'landings and stairs share one continuous floor material');
	if($elevation['kind']!=='stairs') continue;
	verifyWad(abs(array_sum(array_map(static fn($c)=>PolygonGeometry::area($c['polygon']),$interior))
		-PolygonGeometry::area($layer['footprint']['polygon']))<.01,'stair room subdivisions preserve the whole footprint');
	foreach($interior as $i=>$cell) {
		if($i) verifyWad(abs($cell['floor']-$interior[$i-1]['floor'])===16,'room treads change height monotonically in climbable steps');
		foreach($interior as $j=>$other) if($j>$i) verifyWad(!PolygonGeometry::overlaps($cell['polygon'],$other['polygon']),'stair landings and treads do not overlap');
	}
}
verifyWad($changes>=2,'the default layout has multiple functional rooms with elevation changes');

// Check the emitted sidedefs and actual upper wall spans, not just roof metadata.
$roofLevel=MapConnectivity::level($default);
$stack=new Stack(new Archive(file_get_contents(dirname(__DIR__).'/demo/DOOM1.WAD'),'DOOM1.WAD'));
$textures=new TextureCatalog($stack);
$atlas=IndexedAtlas::forLevel($roofLevel,$textures,1024,null,new Palette($stack));
$mesh=(new MapMeshBuilder($roofLevel,$atlas))->build(); $openings=0;
foreach($mesh->volumeSpans as $span) if($span['volume']?->swimmable()) {
	$v=array_slice($mesh->vertices,$span['first']*16,16);
	verifyWad($v[8]<0 || ($v[4]<0 && $v[5]<0),'liquid side faces cannot overlay walls with texture anchors tied to the rising water model');
}
foreach($roofLevel->map->linedefs as $line) {
	if($line['left']<0) continue;
	foreach([[$line['right'],$line['left']],[$line['left'],$line['right']]] as [$outside,$inside]) {
		$a=$roofLevel->map->sidedefs[$outside]; $b=$roofLevel->map->sidedefs[$inside];
		$sky=$default['cells'][$a['sector']]; $room=$default['cells'][$b['sector']];
		if(!isset($sky['outdoor']) || $sky['ceilingTexture']!=='F_SKY1' || $sky['floor']===$sky['ceiling'] || $room['ceilingTexture']==='F_SKY1') continue;
		$openings++;
		verifyWad($sky['ceiling']-$room['ceiling']>=OutdoorSpace::ROOF_THICKNESS,'open sky leaves a visible solid roof above indoor openings');
		verifyWad($a['upper']!=='-' && $textures->texture($a['upper'])!==null,'the wall above each outdoor opening has an available texture');
		$spans=array_filter($mesh->wallSpans,static fn($s)=>$s['line']===$line['index'] && $s['slot']==='upper');
		verifyWad((bool)$spans,'the renderer emits an upper wall at the exterior opening');
	}
}
verifyWad($openings>=2,'roof coverage includes multiple courtyard and garden openings');
