<?php
declare(strict_types=1);

use DoomPhonics\Tools\{SeededLevel,MapConnectivity,PolygonGeometry,PolygonMap,SwitchRecess,DoorRecess,DoorTraversal};
use DoomPhonics\Tests\SeededWalkthrough;
use DoomPhonics\Archive\{Archive,Stack};
use DoomPhonics\Graphics\{TextureCatalog,Palette,IndexedAtlas};
use DoomPhonics\Render\{MapMesh,MapMeshBuilder};

// Chance changes doors independently of topology, elevations and special entrances.
$openConnections=SeededLevel::build('first-light',portalDoors:'open',doorChance:0);
$allConnections=SeededLevel::build('first-light',portalDoors:'open',doorChance:100);
$mixedConnections=SeededLevel::build('first-light',portalDoors:'open');
verifyWad(!$openConnections['doors'],'zero chance leaves room connections open independently of portal entrances');
$allDoorIds=array_column($allConnections['doors'],'region');
$mixedDoorIds=array_column($mixedConnections['doors'],'region');
verifyWad(count($mixedDoorIds)>0 && count($mixedDoorIds)<count($allDoorIds) && !array_diff($mixedDoorIds,$allDoorIds),
	'default chance produces a reproducible subset of the full eligible door set');
verifyWad($openConnections['topology']===$allConnections['topology'] && $openConnections['positions']===$allConnections['positions']
	&& $openConnections['merged']===$allConnections['merged'],'door probability does not reshuffle rooms, heights or broad merged spaces');
$kinds=array_column($allConnections['doors'],'kind');
verifyWad(in_array('cluster',$kinds,true) && in_array('connection',$kinds,true),'doors are available both within clusters and between room envelopes');
foreach(['cluster','connection'] as $kind)
verifyWad((bool)array_filter($allConnections['doors'],static fn($d)=>$d['kind']===$kind && $d['width']===128.0),
	'generated '.$kind.' connections keep full-width doors instead of cramped hallways');
$pairs=[];
foreach($allConnections['doors'] as $door)
{
	$pairs[]=$door['connectionId']??json_encode($door['connection']);
	foreach([[0,1],[1,0]] as [$from,$to])
	{
		$local=$allConnections; $local['start']=$door['faces'][$from]['neighbor'];
		$destination=$door['faces'][$to]['neighbor']; $route=[];
		foreach([[$local['start'],$door['region']],[$door['region'],$destination]] as [$a,$b])
		$route[]=array_values(array_filter($local['graph']->edges,static fn($e)=>$e['from']===$a && $e['to']===$b))[0];
		$trace=SeededWalkthrough::run(MapConnectivity::level($local),$local,$route);
		verifyWad(end($trace)['sector']===$local['regions'][$destination],'new door passages are physically traversable in both directions');
	}
}
verifyWad(count($pairs)===count(array_unique($pairs)),'one connection never gets duplicate doors');
$openCluster=$openConnections['layers']['ground.0'];
$eligiblePassages=array_filter($openCluster['cells'],static fn($c)=>in_array($c['id'],$openCluster['cluster']['doorways'],true) && $c['clearWidth']<=128);
verifyWad(count($allConnections['layers']['ground.0']['cluster']['doors'])===count($eligiblePassages),
	'full door chance covers every texture-width passage; wider diagonal halls remain open');
$wetRooms=static fn($p)=>array_values(array_filter(array_map(static fn($id)=>$p['cells'][$id]['id'],$p['waterSectors']),
	static fn($id)=>str_ends_with($id,'.core') || str_ends_with($id,'.wing')));
verifyWad($wetRooms($openConnections)===$wetRooms($allConnections),'initially closed doors do not permanently exclude connected rooms from the basin');
foreach([-1,101] as $chance)
{
	$invalid=false;
	try { SeededLevel::build(doorChance:$chance); } catch(InvalidArgumentException) { $invalid=true; }
	verifyWad($invalid,'door percentages outside the supported range are rejected');
}

// A reusable diagonal reservation keeps integer vertices and real two-sided controls.
$fields=['floor'=>0,'ceiling'=>192,'tag'=>0,'light'=>192,'floorTexture'=>'FLOOR0_1','ceilingTexture'=>'CEIL3_5'];
$p=[[-48,-48],[-112,16],[-16,112],[48,48]];
$diagonal=DoorRecess::build(PolygonGeometry::cell(['id'=>'diagonal']+$fields,$p),['inner','outer']);
$before=PolygonGeometry::cell(['id'=>'inner']+$fields,[[-144,-144],[-208,-80],$p[1],$p[0]]);
$after=PolygonGeometry::cell(['id'=>'outer']+$fields,[$p[3],$p[2],[80,208],[144,144]]);
$cells=[$before,$after,...$diagonal['cells']]; $boundaries=[];
foreach($diagonal['door']['faces'] as $face)
$boundaries[]=['from'=>$face['from'],'to'=>$face['to'],'action'=>12,'args'=>[0,16,150,0,0],'flags'=>1540,'upperTexture'=>'BIGDOOR2'];
$fixture=['map'=>'DOORTEST','lumps'=>PolygonMap::build('DOORTEST',$cells,$boundaries)];
$height=DoorTraversal::verify(MapConnectivity::level($fixture),$diagonal['door'],array_flip(array_column($cells,'id')),['radius'=>16,'height'=>56,'step'=>24]);
verifyWad($height===124.0,'diagonal door geometry survives WAD emission, repeated use and collision in both directions');

// Exercise both entrance choices without depending on which one a particular seed selects.
foreach(['open','doors'] as $style)
{
	$details=SeededLevel::build('first-light',5,'organic',$style);
	verifyWad(count(array_filter($details['doors'],static fn($d)=>$d['kind']==='portal')) === ($style==='doors' ? 4 : 0),'portal entrances support both open and working-door variants');
	foreach([1,3,5,7] as $side)
	{
		$local=$details; $local['start']='upper.0.ring-'.$side;
		$route=$details['graph']->search($local['start'],['water'=>'dry'])['routes']['basement.0.arrival'];
		verifyWad(in_array('door',array_column($route,'mode'),true) === ($style==='doors'),'navigation uses door activation only when a door exists');
		$trace=SeededWalkthrough::run(MapConnectivity::level($details),$local,$route);
		verifyWad(end($trace)['sector'] === $details['regions']['basement.0.arrival'],'each circular entrance leads physically through the well to the lower room');
	}
	verifyWad($details['lumps']===SeededLevel::build('first-light',5,'organic',$style)['lumps'],'entrance selection preserves repeatable generation');
}
$detailLevel=MapConnectivity::level($details);
$wadStack=new Stack(new Archive(file_get_contents(dirname(__DIR__).'/demo/DOOM1.WAD'),'DOOM1.WAD'));
$catalog=new TextureCatalog($wadStack);
$bigdoor=$catalog->texture('BIGDOOR2');
foreach($details['doors'] as $door)
{
	$id=$details['regions'][$door['region']]; $moving=$details['cells'][$id];
	verifyWad($door['width']>=64 && $door['width']<=$bigdoor->width && $door['height']===$bigdoor->height,'door panels retain their texture height and diagonal openings crop without tiling');
	verifyWad($moving['floor']===$moving['ceiling'],'doors begin shut');
	verifyWad(array_sum(array_column($door['faces'],'xOffset'))===max(0,(int)floor(128-$door['width'])),
		'opposite door faces retain opposite outer columns of a half-width texture');

	foreach($door['faces'] as $face)
	{
		$lintel=$details['cells'][$details['regions'][$face['neighbor']]];
		verifyWad(abs($lintel['ceiling']-$lintel['floor']-$bigdoor->height)<.01,'fixed lintels constrain both sides to the panel height');
		$depth=PolygonGeometry::area(PolygonGeometry::points($lintel))/$door['width'];
		verifyWad(abs($depth-($door['recess']??40))<.01 && $depth>=36,'door panels sit behind fixed lintels on the integer map grid');
		$span=0;
		foreach($details['cells'] as $adjacent) {
			if(in_array($adjacent['id'],[$lintel['id'],$door['region']],true)) continue;
			$shared=PolygonGeometry::shared(PolygonGeometry::points($lintel),PolygonGeometry::points($adjacent));
			if($shared) $span+=hypot($shared[1][0]-$shared[0][0],$shared[1][1]-$shared[0][1]);
		}
		verifyWad(abs($span-$door['width'])<.01,'lintels meet the complete neighboring wall across all convex subdivisions');
	}
}
foreach($detailLevel->map->linedefs as $line)
if($line['action']===12)
{
	$face=$detailLevel->map->sidedefs[$line['right']];
	$a=$detailLevel->map->vertices[$line['from']]; $b=$detailLevel->map->vertices[$line['to']];
	$width=hypot($b['x']-$a['x'],$b['y']-$a['y']);
	verifyWad($face['upper']==='BIGDOOR2' && in_array($face['xOffset'],[0,max(0,(int)floor($bigdoor->width-$width))],true) && $face['yOffset']===0,
		'door faces crop from the left or right texture edge without centering, scaling or tiling');
	verifyWad(($line['flags']&8)===0 && ($line['flags']&16)!==0 && ($line['flags']&512)!==0,'doors keep moving upper textures, aligned lower spans and repeatable activation');
}

// Validate visible texture anchors, not only the writer's flags.
$atlas=IndexedAtlas::forLevel($detailLevel,$catalog,1024,null,new Palette($wadStack));
$mesh=(new MapMeshBuilder($detailLevel,$atlas))->build();
foreach($details['actions'] as $action)
{
	$panel=array_values(array_filter($details['cells'],static fn($c)=>$c['id']===$action['panel']))[0];
	verifyWad(!isset($details['regions'][$panel['id']]) && $panel['ceiling']-$panel['floor']===32.0,'shallow switch alcoves are bounded panels, not fictitious walkable rooms');
	verifyWad(min($panel['x1']-$panel['x0'],$panel['y1']-$panel['y0'])===8.0,'default switch recesses are eight units deep');
	$control=array_values(array_filter($detailLevel->map->linedefs,static fn($l)=>$l['action']===47
		&& $detailLevel->map->sidedefs[$l['right']]['sector']===$detailLevel->sectorAt(...$panel['center'])->index))[0];
	$side=$detailLevel->map->sidedefs[$control['right']];
	$cropX=str_starts_with($action['id'],'drain') ? 48 : 16;
	verifyWad([$side['xOffset'],$side['yOffset']]===[$cropX,72],'computer and brown switch crops include their button in both states');
	foreach(['SW1','SW2'] as $state)
	{
		$image=$catalog->texture($state.substr($side['middle'],3));
		verifyWad($image && $cropX+32<=$image->width && 104<=$image->height,'both switch images cover the complete crop without tiling');
	}
	$span=array_values(array_filter($mesh->wallSpans,static fn($s)=>$s['line']===$control['index'] && $s['slot']==='middle'))[0];
	$v=array_slice($mesh->vertices,$span['first']*MapMesh::FLOATS,MapMesh::FLOATS);
	verifyWad(array_slice($v,8,3)===[(float)$side['sector'],1.0,72.0],'rendered switch panel uses its bounded ceiling and crop origin');
}
// Lower texture on each opening uses the same ceiling origin as the surrounding middle wall.
foreach($detailLevel->map->linedefs as $line)
{
	if($line['left']<0) continue;
	$front=$detailLevel->map->sidedefs[$line['right']]; $back=$detailLevel->map->sidedefs[$line['left']];
	if(!str_contains($details['cells'][$back['sector']]['id'],'switch-') || $line['action']) continue;
	verifyWad(($line['flags']&24)===24,'switch openings align both upper and lower structural textures');
	$spans=array_values(array_filter($mesh->wallSpans,static fn($s)=>$s['line']===$line['index'] && $s['slot']==='lower'));
	$v=array_slice($mesh->vertices,$spans[0]['first']*MapMesh::FLOATS,MapMesh::FLOATS);
	verifyWad(array_slice($v,8,3)===[(float)$front['sector'],1.0,0.0],'lower surround stays on the adjacent wall ceiling origin');
	// All collinear pieces on this room wall retain their distance from one original vertex.
	$a=$detailLevel->map->vertices[$line['from']]; $b=$detailLevel->map->vertices[$line['to']];
	foreach($detailLevel->map->linedefs as $neighbor)
	{
		$other=$detailLevel->map->sidedefs[$neighbor['right']];
		if($other['sector']!==$front['sector'] || $neighbor['left']>=0) continue;
		$p=$detailLevel->map->vertices[$neighbor['from']]; $q=$detailLevel->map->vertices[$neighbor['to']];
		if(($b['x']-$a['x'])*($p['y']-$a['y'])-($b['y']-$a['y'])*($p['x']-$a['x'])!==0) continue;
		if($p===$b) verifyWad($other['xOffset']===$front['xOffset']+32,'horizontal texture phase continues after the switch opening');
		if($q===$a) verifyWad($front['xOffset']===$other['xOffset']+(int)hypot($q['x']-$p['x'],$q['y']-$p['y']),'horizontal texture phase continues into the switch opening');
	}
}
$upper=$details; $upper['start']='basement.0.ledge';
$pair=array_values(array_filter($details['graph']->edges,static fn($e)=>in_array($e['mode'],['fill-high','drain-high'],true)));
$trace=SeededWalkthrough::run(MapConnectivity::level($details),$upper,array_merge($pair,$pair));
verifyWad(count($trace)===4 && end($trace)['sector']===$details['regions']['basement.0.ledge'],'upper drain/refill controls work on repeated visits without leaving the ledge');

// Reusable alcoves support any cardinal wall and caller-supplied size/action/crop.
$room=['id'=>'room','x0'=>0,'y0'=>0,'x1'=>256,'y1'=>256,'floor'=>0,'ceiling'=>256,
	'tag'=>0,'light'=>192,'floorTexture'=>'FLOOR0_1','ceilingTexture'=>'CEIL3_5','alignTextures'=>true];
foreach([[[0,128],[-1,0]],[[256,128],[1,0]],[[128,0],[0,-1]],[[128,256],[0,1]]] as [$point,$normal])
{
	$recess=SwitchRecess::build($room,$point,$normal,'panel','SW1COMP',47,[200,16,0,0,0],48,56,32,24,[8,64]);
	$fixture=['map'=>'SWTEST','lumps'=>PolygonMap::build('SWTEST',[$room,$recess['cell']],[$recess['boundary']])];
	$l=MapConnectivity::level($fixture);
	verifyWad($l->sectorAt(...$recess['cell']['center'])->index===1 && $recess['cell']['ceiling']===88,
		'parameterized switch geometry survives WAD/BSP generation in each cardinal orientation');
}
$invalid=false;
try { SwitchRecess::build($room,[256,128],[1,0],'bad','SW1COMP',47,[],width:64,height:256); }
catch(InvalidArgumentException) { $invalid=true; }
verifyWad($invalid,'switch recess rejects a panel extending above its room ceiling');

// Texture-sized panels may be rectangular; patch crops come from the WAD, not a nominal tile size.
verifyWad(SwitchRecess::panel($catalog,'SW1COMP')===['width'=>64,'height'=>128,'crop'=>[0,0]],'full computer switch retains its rectangular texture size');
verifyWad(SwitchRecess::panel($catalog,'SW1COMP','SW2S0')===['width'=>32,'height'=>32,'crop'=>[16,72]],'patch mode selects the computer switch button at its authored offset');
verifyWad(SwitchRecess::panel($catalog,'SW1BRCOM','SW1S0')===['width'=>32,'height'=>32,'crop'=>[48,72]],'patch mode selects the brown switch button at its different authored offset');
$customPanel=SwitchRecess::panel($catalog,'SW1COMP',crop:[9,64,47,55]);
$customRecess=SwitchRecess::build($room,[256,128],[1,0],'custom','SW1COMP',47,[],...$customPanel);
$customFixture=['map'=>'SWTEST','lumps'=>PolygonMap::build('SWTEST',[$room,$customRecess['cell']],[$customRecess['boundary']])];
$customLevel=MapConnectivity::level($customFixture);
verifyWad($customLevel->sectorAt(...$customRecess['cell']['center'])->index===1
	&& abs($customRecess['cell']['y1']-$customRecess['cell']['y0']-47)<.01,'odd-width rectangular crops preserve integer map geometry');
foreach(['COMP03_4','MISSING'] as $patch)
{
	$invalid=false;
	try { SwitchRecess::panel($catalog,'SW1COMP',$patch); } catch(InvalidArgumentException) { $invalid=true; }
	verifyWad($invalid,'ambiguous or missing patch selections require an explicit valid crop');
}
$invalid=false;
try { SwitchRecess::panel($catalog,'SW1COMP',crop:[32,72,64,64]); } catch(InvalidArgumentException) { $invalid=true; }
verifyWad($invalid,'panel crops cannot silently tile beyond the source texture');
foreach(['COMP'=>'SW2S','BRCOM'=>'SW1S'] as $suffix=>$prefix)
foreach([0,1] as $pressed)
{
	$image=$catalog->texture('SW'.($pressed+1).$suffix); $patch=$catalog->picture($prefix.$pressed);
	$p=SwitchRecess::panel($catalog,'SW'.($pressed+1).$suffix,$prefix.$pressed);
	$matches=true;
	for($y=0;$y<$p['height'];$y++) for($x=0;$x<$p['width'];$x++)
	{
		$index=$y*$patch->width+$x;
		if(ord($patch->alpha[$index])===0) continue;
		$matches=$matches && $image->pixels[($y+$p['crop'][1])*$image->width+$x+$p['crop'][0]]===$patch->pixels[$index];
	}
	verifyWad($matches,'cropped composite preserves visible switch patch pixels in both states');
}
$fullPanels=SeededLevel::build('first-light',5,'organic','doors','texture');
foreach($fullPanels['actions'] as $action)
{
	$cell=array_values(array_filter($fullPanels['cells'],static fn($c)=>$c['id']===$action['panel']))[0];
	$width=str_starts_with($action['id'],'drain') ? 128 : 64;
	verifyWad(max($cell['x1']-$cell['x0'],$cell['y1']-$cell['y0'])===$width+0.0
		&& $cell['ceiling']-$cell['floor']===128.0,'full-texture mode builds a correctly sized alcove for each selected texture');
}
