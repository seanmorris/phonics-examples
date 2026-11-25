<?php
use TerrainPhonics\{Camera,Landscape};
use TerrainSandbox\{AirPatrol,Car,DemoTape,Session};

$flat = ['worldSize'=>4096,'heightScale'=>0.,'detailAmplitude'=>0.,'waterLevel'=>-10.];
$land = new Landscape($flat);
$eye = new Camera;
$eye->position=[0.,3.,-7.]; $eye->pitch=-.14; $eye->fov=1.36;
$driver=['x'=>0.,'y'=>0.,'z'=>0.,'yaw'=>0.,'drift'=>0.,'speed'=>60.*Car::MPS_PER_MPH];
$pilot=new AirPatrol;
expect(!$pilot->step(1/60,$driver,fn()=>$eye,16/9,$land,-10.) && $pilot->pose()===null,'The speed limit itself does not summon an aircraft.');
foreach([0.,M_PI/2,M_PI,3*M_PI/2] as $eye->yaw) {
	$pilot=new AirPatrol;
	$driver['speed']=30.;
	expect($pilot->step(1/60,$driver,fn()=>$eye,16/9,$land,-10.),'Open sky admits an arrival.');
	$p=$pilot->pose();
	expect(!Camera::inView($eye->clipPlanes(1600,900),$eye->position,
		['x'=>$p['x']-9.,'z'=>$p['z']-9.,'size'=>18.],$p['y']-9.,$p['y']+9.),'The whole aircraft spawns outside the current look direction.');
}

// A driver crossing the limit in the real simulation gets a visible banking pass and two distinct stations.
$drive = new Session($flat);
$drive->camera->position=[0.,35.,0.]; $drive->camera->yaw=M_PI;
$drive->input(['actions'=>['car']],0.,16/9);
for($i=0;$drive->findingCarSite() && $i<1000;$i++) $drive->input([],0.,16/9);
expect($drive->mode==='car','The patrol fixture boards the car.');
$drive->input(['actions'=>['demo-record'],'keys'=>['KeyW'=>1,'ShiftLeft'=>1]],0.,16/9);
$bank=$visible=0.; $minSide=INF; $maxSide=0.; $minSeparation=INF; $first=null; $entryTime=0.;
for($i=0;$i<880;$i++) {
	$drive->step(1/60); $drive->demo->capture($drive);
	$pilot=$drive->airPatrol->snapshot();
	if(!isset($pilot['pose'])) continue;
	$p=$pilot['pose']; [$eye,$car]=$drive->view(1.);
	$first ??= abs($car['speed'])/Car::MPS_PER_MPH;
	[$f,$r,$up]=$eye->basis();
	$rel=[$p['x']-$eye->position[0],$p['y']-$eye->position[1],$p['z']-$eye->position[2]];
	$dot=static fn($v)=>$rel[0]*$v[0]+$rel[1]*$v[1]+$rel[2]*$v[2];
	$depth=$dot($f); $height=$depth*tan($eye->fov/2);
	if($depth>0. && abs($dot($r))<$height*16/9 && abs($dot($up))<$height) {
		if($pilot['phase']==='arrival' && abs($p['roll'])>$bank) { $bank=abs($p['roll']); $entryTime=$drive->demo->duration(); }
		if($pilot['phase']==='escort') $visible++;
	}
	if($pilot['phase']==='escort') {
		$x=$p['x']-$car['x']; $z=$p['z']-$car['z'];
		$side=abs($x*cos($car['yaw'])-$z*sin($car['yaw']));
		$minSide=min($minSide,$side); $maxSide=max($maxSide,$side);
		$minSeparation=min($minSeparation,hypot($x,$z));
	}
}
expect($first>60. && $first<62. && $bank>.67,'The F5 visibly banks into the chase camera just after the driver exceeds 60 mph.');
expect($minSide<2. && $maxSide>11. && $visible>300 && $minSeparation>30.,'The pilot paces overhead and alongside with a clear view of the car.');
$drive->input(['actions'=>['demo-stop']],0.,16/9);
$demo=$drive->demo->export();
$copy=DemoTape::import($demo);
expect($copy->frame($entryTime)['events']['airPatrol']['phase']==='arrival','A recorded pass survives export and import.');
foreach([['type'=>'demo-open','json'=>$demo],['type'=>'demo-seek','seconds'=>$entryTime],'demo-take-control'] as $action)
	$drive->input(['actions'=>[$action]],0.,16/9);
expect($drive->airPatrol->snapshot()['phase']==='arrival','Taking the wheel resumes the fly-by in progress.');
$drive->close();

// Give identical pilots a straight stretch and a sustained bend at the same moment.
$lanes=[];
foreach([0.,.70] as $turnRate) {
	$pilot=new AirPatrol; $driver['yaw']=0.; $driver['x']=$driver['z']=0.; $driver['speed']=38.;
	$eye=new Camera; $eye->yaw=M_PI;
	for($i=0;$i<302;$i++) {
		if($i>=202) $driver['yaw']+=$turnRate/60.;
		$driver['x']+=sin($driver['yaw'])*$driver['speed']/60.;
		$driver['z']+=cos($driver['yaw'])*$driver['speed']/60.;
		$eye->position=[$driver['x'],3.,$driver['z']-7.];
		$pilot->step(1/60,$driver,fn()=>$eye,16/9,$land,-10.);
	}
	$s=$pilot->snapshot(); $p=$s['pose']; $heading=$s['flight'][2];
	$lanes[]=abs(($p['x']-$driver['x'])*cos($heading)-($p['z']-$driver['z'])*sin($heading));
}
expect($lanes[0]-$lanes[1]>6.,'The escort moves inward on a bend while the straightaway pilot stays alongside.');

// A wall looming ahead of the escort starts a climbing departure, as does sustained slowing.
foreach(['wall','brake'] as $reason) {
	$terrain=new Landscape($flat); $pilot=new AirPatrol;
	$eye=new Camera; $eye->yaw=M_PI;
	$driver['speed']=35.; $driver['x']=$driver['z']=$driver['yaw']=0.;
	for($i=0;$i<600;$i++) {
		$driver['z']+=$driver['speed']/60; $eye->position=[0.,3.,$driver['z']-7.];
		$pilot->step(1/60,$driver,fn()=>$eye,16/9,$terrain,-10.);
		if($i===220) {
			if($reason==='wall') {
				$terrain->queue(['type'=>'raise','x'=>0.,'z'=>$driver['z']+95.,'radius'=>38.,'amount'=>80.]);
				$terrain->commit();
			} else $driver['speed']=20.;
		}
		if(($pilot->snapshot()['phase'] ?? '')==='departure') break;
	}
	expect($i<500,"A $reason triggers a peel-away.");
	$before=$pilot->pose();
	for($j=0;$j<60;$j++) $pilot->step(1/60,$driver,fn()=>$eye,16/9,$terrain,-10.);
	expect($pilot->pose()['y']>$before['y']+15.,'The departing jet climbs under its own power.');
	$terrain->close();
}
$land->close();
