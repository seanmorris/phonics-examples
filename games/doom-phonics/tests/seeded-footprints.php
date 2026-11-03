<?php
declare(strict_types=1);

use DoomPhonics\Tools\SeededLevel;

// Use the emitted default map: these checks describe player-visible geometry.
$footprints=[]; $leaning=0;
foreach($default['layers'] as $name=>$layer) {
	if($name==='spiral' || isset($layer['cluster']) || $name==='basement.0') continue;
	$footprints[]=implode(':',$layer['footprint']['dimensions']);
	$p=$layer['footprint']['polygon'];
	foreach($p as $i=>$a) {
		$b=$p[($i+1)%count($p)]; $dx=abs($b[0]-$a[0]); $dy=abs($b[1]-$a[1]);
		if(min($dx,$dy)>=64 && max($dx,$dy)>=512) { $leaning++; break; }
	}
	verifyWad(count($p)>=4,'ordinary rooms retain clear, simple outlines');
}
verifyWad(count(array_unique($footprints))>=6,'room widths and depths vary independently before wall deformation');
verifyWad($leaning===0,'organic rooms no longer use independent shearing');
$compounds=[];
foreach($default['layers'] as $layer) if(count($layer['footprint']['sourceRectangles']??[])>1) $compounds[$layer['plane']]=true;
verifyWad(count($compounds)===3,'the default map retains compound rectangle rooms on every layer after cuts and clearance repairs');
verifyWad($default['layers']['upper.0']['cluster']['minimumRoomSize']>=448
	&& $default['layers']['upper.0']['cluster']['outerDiameter']>=1152,'concentric chamber and surrounding hall have larger minimum dimensions');
foreach($default['layers']['basement.0']['ledges'] as $name) {
	$id=$default['regions'][$name]; $cell=$default['cells'][$id];
	verifyWad($cell['floor']>=0 && !in_array($id,$default['waterSectors'],true),'ledge chambers remain above the full water surface through geometric flood selection');
	verifyWad($cell['ceiling']-$cell['floor']>=192,'dry ledges retain standing headroom');
	$return=$default['graph']->search($name,['water'=>'dry'],false);
	verifyWad(in_array($default['start'],$return['reachable'],true),'a drained basin does not strand someone on a dry ledge');
}
verifyWad(count($default['galleryStack'])===3,'the default map contains a three-level portal gallery');
[$top,$middle,$bottom]=$default['galleryStack'];
foreach([$top,$middle] as $from) {
	$edges=array_values(array_filter($default['graph']->edges,static fn($e)=>$e['from']===$from && $e['mode']==='floor-portal'));
	verifyWad(count($edges)===1 && $edges[0]['to']===$bottom,'gallery fall edges end at the actual safe landing after traversing the stack');
}
verifyWad(!array_filter($default['graph']->edges,static fn($e)=>$e['from']===$bottom && $e['mode']==='floor-portal'),
	'falling into a gallery does not invent an upward traversal');
foreach(['all','none'] as $mode) {
	$galleryMap=SeededLevel::build(galleryHalls:$mode);
	verifyWad(count($galleryMap['galleryStack'])===3,'explicit gallery styles retain the full descending stack');
	foreach($galleryMap['layers'] as $layer) if(isset($layer['gallery'])) {
		verifyWad($layer['gallery']['hallways']===($mode==='all'),'gallery halls can be enabled or removed independently of the drop route');
		if($mode==='all') verifyWad($layer['gallery']['wallThickness']===64 && $layer['gallery']['rimWidth']>=128,
			'four-sided gallery halls reserve parallel walls and clear inner walkways');
	}
	verifyWad(!$galleryMap['overlaps'],'gallery hall options preserve architectural separation');
}

// Shared slopes, half-door crops and room subgraphs describe actual generated geometry.
$slopes=[];
foreach($default['layers'] as $name=>$layer) {
	if(isset($layer['footprint']['wallSlope']) && !isset($layer['cluster'])) $slopes[$layer['plane']][]=json_encode($layer['footprint']['wallSlope']);
	verifyWad(!isset($layer['terrain']),'ordinary rooms no longer receive isolated decorative center pits');
}
foreach($slopes as $values) verifyWad(count(array_unique($values))===1,'neighboring angled rooms use the same layer-wide slope');
foreach([90,180,270] as $sweep) foreach([0,2,4,6] as $rotation) {
	$c=\DoomPhonics\Tools\RoomCluster::circular('arc',64,256,false,$sweep,$rotation);
	verifyWad(count($c['curvedBays'])===$sweep/45 && count($c['straightBays'])===8-$sweep/45,
		'concentric arcs stop at the selected sweep and leave a straight asymmetric gallery');
	foreach($c['cells'] as $i=>$a) foreach($c['cells'] as $j=>$b) if($j>$i)
	verifyWad(!\DoomPhonics\Tools\PolygonGeometry::overlaps($a['polygon'],$b['polygon']),'partial arcs and their replacement bays never overlap');
}
