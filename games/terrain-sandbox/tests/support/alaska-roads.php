<?php
use TerrainPhonics\{JoinedTerrain,Landscape};
use TerrainSandbox\{JourneyHighways,JourneyNetwork};

// These two independent surveys formerly crossed ten times, at elevations up to 390 m apart.
$network=new JourneyNetwork("A heather-moor -> B,C\nB salt-pans -> E,F\nC high-desert-steppe -> F,G\nE limestone\nF alaska-range\nG cinder-highlands");
$routes=new JourneyHighways($network,16384);
$f=surveyWindow($routes,'F');
$keys=pavement($f,[0.,0.,0.],16384);
checkWindow(count(junctions($f->roadRecords()))===1,'Both Alaska approaches must meet one common onward road.');
foreach(['B'=>1,'C'=>0] as $id=>$slot) {
	$parent=surveyWindow($routes,$id); $recipe=$parent->recipe();
	[$x,$z]=JoinedTerrain::origins(16384,2)[$slot+1];
	$origin=[$x,$recipe['waterLevel']-$recipe['join']['destinations'][$slot]['waterLevel'],$z];
	checkWindow($keys===pavement($parent,$origin,16384),"The Alaska pavement matches from $id.");
	$parent->close();
}
$bare=new Landscape($f->recipe());
$edges=$cells=$earthworks=[]; $worstContact=0.;
foreach($f->roads() as $road) {
	$samples=$road['_road']['samples'];
	foreach($samples as $i=>[$x,$y,$z]) {
		if($i%4===0 && max(abs($x),abs($z))<8192.) {
			$earthworks[]=abs($y-$bare->height($x,$z));
			$worstContact=max($worstContact,abs($y-$f->height($x,$z)));
		}
		if(!$i) continue;
		[$ax,,$az]=$samples[$i-1]; $dx=$x-$ax; $dz=$z-$az;
		$id=count($edges); $edges[]=[$ax,$az,$dx,$dz];
		for($u=(int)floor(min($ax,$x)/64.);$u<=(int)floor(max($ax,$x)/64.);$u++)
		for($v=(int)floor(min($az,$z)/64.);$v<=(int)floor(max($az,$z)/64.);$v++) {
			foreach($cells[$u][$v] ?? [] as $previous) {
				[$bx,$bz,$ex,$ez]=$edges[$previous]; $det=$dx*$ez-$dz*$ex;
				if(abs($det)<1.e-6) continue;
				$t=(($bx-$ax)*$ez-($bz-$az)*$ex)/$det;
				$s=(($bx-$ax)*$dz-($bz-$az)*$dx)/$det;
				checkWindow(min($s,$t)<=1.e-6 || max($s,$t)>=1.-1.e-6,'A road crosses another span inside Alaska.');
			}
			$cells[$u][$v][]=$id;
		}
	}
}
sort($earthworks,SORT_NUMERIC);
checkWindow($earthworks[(int)(.95*count($earthworks))]<140. && $worstContact<3.,'Alaska has a driveable deck through its mountain passes.');
$f->close(); $bare->close();
