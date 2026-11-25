<?php
use TerrainPhonics\{Edits,RoadAtlas,RoadFill};

// The upland bank rises beyond the authored shoulder. The opposite side is an open drop.
$road=Edits::normalize(['type'=>'road','width'=>20.,'shoulder'=>6.,'points'=>[[-80.,12.,0.],[80.,12.,0.]]]);
foreach([-1.,1.] as $side) {
	$field=static fn(float $x,float $z): float=>2.+max(0.,$side*$z-24.)*.8;
	$work=RoadFill::prepare($road['_road'],$field,0.);
	iterator_count($work); $fitted=$road; $fitted['_road']=$work->getReturn();
	$z=$side*30.;
	checkWindow(Edits::apply(0.,$z,$field(0.,$z),$fitted)>$field(0.,$z)+2.,'Fill the dry pocket on the side facing the mountain.');
	checkWindow(Edits::apply(0.,-$z,$field(0.,-$z),$fitted)===$field(0.,-$z),'Keep the open downhill side.');
	checkWindow(Edits::apply(0.,$side*60.,$field(0.,$side*60.),$fitted)===$field(0.,$side*60.),'Stop filling at the natural bank.');
	checkWindow(Edits::material(0.,$z,[$fitted])===null && Edits::material(0.,0.,[$fitted])==='road','Earthwork leaves the painted pavement width intact.');
	checkWindow(Edits::apply(0.,0.,$field(0.,0.),$fitted)===12.,'The road deck retains its elevation.');
	$atlas=RoadAtlas::build([$fitted],256.);
	$floats=array_values(unpack('g*',$atlas['bytes']));
	$xTile=-$atlas['minTile']; $zTile=(int)floor($z/$atlas['cell'])-$atlas['minTile'];
	$block=intdiv($zTile,8)*$atlas['blocksAcross']+intdiv($xTile,8);
	$leaves=(int)$floats[4*$atlas['directory']+$block];
	$group=(int)$floats[4*$leaves+($zTile%8)*8+$xTile%8];
	$addresses=array_slice($floats,4*($group+1),(int)$floats[4*$group+1]);
	checkWindow(min($addresses)<0.,'The GPU tile over the pocket references its fill strips.');
	foreach([static fn()=>2.,static fn($x,$z)=>$field($x,$z)-10.] as $open) {
		$job=RoadFill::prepare($road['_road'],$open,0.); iterator_count($job);
		checkWindow(!isset($job->getReturn()['fills']),'Raised roads over open terrain and water retain their own shoulders.');
	}
}
