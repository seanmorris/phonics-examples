<?php
use TerrainPhonics\{Camera,Landscape};
use TerrainSandbox\{AirPatrol,Car,DemoEvents,DemoTape,Jet,Session};

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
expect($drive->settings['airPatrolEnabled']===false, 'The experimental patrol starts switched off.');
for($i=0;$i<900 && $drive->car->state()['speed']<=60.*Car::MPS_PER_MPH;$i++) {
	$drive->step(1/60); $drive->demo->capture($drive);
}
expect($i<900 && $drive->airPatrol->snapshot()===null, 'Crossing the limit keeps the sky clear while the checkbox is off.');
$drive->input(['values'=>['airPatrolEnabled'=>true],'keys'=>['KeyW'=>1,'ShiftLeft'=>1]],0.,16/9);
$bank=$visible=0.; $minSide=INF; $maxSide=0.; $minSeparation=INF; $first=null; $entryTime=0.; $wingRelease=false;
for($i=0;$i<880;$i++) {
	$drive->step(1/60); $drive->demo->capture($drive);
	$pilot=$drive->airPatrol->snapshot();
	if(!isset($pilot['pose'])) continue;
	$p=$pilot['pose']; [$eye,$car]=$drive->view(1.);
	$first ??= abs($car['speed'])/Car::MPS_PER_MPH;
	if($pilot['seeker']['phase']==='drop' && $pilot['seeker']['clock']===0.) {
		$wing=Jet::projectileMount($p,0); $released=$pilot['seeker']['pose'];
		$wingRelease=abs($released['x']-$wing['x'])+abs($released['y']-$wing['y'])+abs($released['z']-$wing['z'])<1e-6;
	}
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
expect($wingRelease,'The seeker starts at the visible wing mount on the frame it detaches.');
expect($first>60. && $first<62. && $bank>.67,'The F5 visibly banks into the chase camera just after the driver exceeds 60 mph.');
expect($minSide<2. && $maxSide>11. && $visible>300 && $minSeparation>30.,'The pilot paces overhead and alongside with a clear view of the car.');
$drive->input(['actions'=>['demo-stop']],0.,16/9);
$demo=$drive->demo->export();
$copy=DemoTape::import($demo);
expect($copy->frame($entryTime)['events']['airPatrol']['phase']==='arrival','A recorded pass survives export and import.');
foreach([['type'=>'demo-open','json'=>$demo],['type'=>'demo-seek','seconds'=>$entryTime],'demo-take-control'] as $action)
	$drive->input(['actions'=>[$action]],0.,16/9);
expect($drive->airPatrol->snapshot()['phase']==='arrival','Taking the wheel resumes the fly-by in progress.');
$drive->input(['values'=>['airPatrolEnabled'=>false]],0.,16/9);
expect($drive->airPatrol->pose()===null,'Switching off dismisses an active patrol immediately.');
for($i=0;$i<10;$i++) $drive->step(1/60);
expect($drive->car->state()['speed']>60.*Car::MPS_PER_MPH && $drive->airPatrol->snapshot()===null,'The patrol stays off at speed.');
$drive->close();

// A sudden move of the formation station can command a turn but cannot relocate the airframe.
$driver=['x'=>0.,'y'=>0.,'z'=>0.,'yaw'=>0.,'drift'=>0.,'speed'=>72.];
$eye=new Camera; $eye->yaw=M_PI;
$eyeAt=function() use (&$eye) { return $eye; };
$pilot=new AirPatrol;
for($i=0;$i<480;$i++) {
	$driver['z']+=$driver['speed']/60.; $eye->position=[0.,3.,$driver['z']-7.];
	$pilot->step(1/60,$driver,$eyeAt,16/9,$land,-10.);
}
$checkpoint=$pilot->snapshot();
expect($checkpoint['phase']==='escort' && $checkpoint['seeker']['phase']==='chase','A joined escort releases a seeker which reaches the rear bumper.');
$tail=$checkpoint['seeker']['pose'];
expect($driver['z']-$tail['z']>2. && $driver['z']-$tail['z']<3. && abs($tail['x'])>1.5,'The seeker tails a rear quarter, clear of the car.');
$restart=new AirPatrol; $restart->resume($checkpoint);
$pilot->step(1/60,$driver,$eyeAt,16/9,$land,-10.);
$restart->step(1/60,$driver,$eyeAt,16/9,$land,-10.);
expect($pilot->snapshot()===$restart->snapshot(),'Branching a recording retains airspeed, control surfaces, travel direction and seeker continuity.');
$displaced=$driver; $displaced['x']+=90.; $displaced['yaw']=M_PI;
$restart->resume($checkpoint);
$restart->step(1/60,$displaced,$eyeAt,16/9,$land,-10.);
$p=$restart->pose(); $a=$checkpoint['pose']; $straight=$pilot->pose();
expect(abs(hypot($p['x']-$a['x'],$p['z']-$a['z'])-$p['speed']/60.)<.01 &&
	hypot($p['x']-$straight['x'],$p['z']-$straight['z'])<.03,'An abrupt change in car position and heading leaves the aircraft on its own flight path.');

$legacy=['phase'=>'escort','flight'=>[4.,0.,0.,1.,-112.,1.,0.,0.,0.],'pose'=>array_intersect_key($a,array_flip(['x','y','z','yaw','pitch','roll']))];
$old=DemoEvents::import([[0,json_encode(['airPatrol'=>$legacy])]],1)->at(0,0.,'car')['airPatrol'];
$restart->resume($old);
expect($restart->pose()['x']===$a['x'] && $restart->pose()['speed']===Jet::CRUISE,'Older visual-only patrol demos can still take flight from their recorded pose.');

foreach(['hairpin','stop'] as $maneuver) {
	$car=$driver; $pilot=new AirPatrol; $pilot->resume($checkpoint); $wide=0.; $rejoined=false;
	for($i=0;$i<1260;$i++) {
		$t=$i/60.;
		if($maneuver==='hairpin' && $t<2.) $car['yaw']+=2.65/120.;
		if($maneuver==='stop') $car['speed']=$t<2. ? 0. : min(72.,($t-2.)*22.);
		$f=sin($car['yaw']); $g=cos($car['yaw']);
		$car['x']+=$f*$car['speed']/60.; $car['z']+=$g*$car['speed']/60.;
		$eye->position=[$car['x']-7.*$f,3.,$car['z']-7.*$g]; $eye->yaw=M_PI-$car['yaw'];
		$before=$pilot->pose(); $pilot->step(1/60,$car,$eyeAt,16/9,$land,-10.); $p=$pilot->pose();
		expect($p!==null && $p['speed']>=Jet::SLOWEST && abs($p['speed']-$before['speed'])<=Jet::ACCELERATION/60.+.05,
			'Even when shaken, the pilot keeps flying with the F5 engine limits.');
		$x=$p['x']-$car['x']; $z=$p['z']-$car['z']; $range=hypot($x,$z); $wide=max($wide,$range);
		if($wide>140. && abs($x*$g-$z*$f)<25. && $x*$f+$z*$g>25. && $range<62. && cos($p['yaw']-$car['yaw'])>.95) $rejoined=true;
	}
	expect($rejoined,"The same pilot loses formation on a $maneuver and recovers the chase.");
}

$pilot=new AirPatrol; $pilot->resume($checkpoint);
$skid=$driver; $skid['drift']=.3;
$pilot->step(1/60,$skid,$eyeAt,16/9,$land,-10.);
$miss=$pilot->seeker();
expect($miss['phase']==='missed','A real slide immediately shakes the current seeker.');
$other=new AirPatrol; $other->resume($pilot->snapshot());
for($i=0;$i<60;$i++) {
	$pilot->step(1/60,$driver,$eyeAt,16/9,$land,-10.);
	$other->step(1/60,$displaced,$eyeAt,16/9,$land,-10.);
}
expect($pilot->seeker()['pose']===$other->seeker()['pose'] && $pilot->seeker()['pose']['z']>$miss['pose']['z']+30.,
	'Once shaken, the prop sails past on the same course whichever way the car turns.');
for($i=0;$i<60;$i++) $pilot->step(1/60,$driver,$eyeAt,16/9,$land,-10.);
expect($pilot->seeker()['phase']==='reload' && $pilot->seeker()['pose']===null,'Escaping gives the driver a breather before the next release.');

// A short stop or two accumulated slow periods cannot dismiss the patrol. Test both sides of 45 continuous seconds.
$pilot->resume($checkpoint); $parked=$driver; $parked['speed']=0.;
for($i=0;$i<600;$i++) $pilot->step(1/60,$parked,$eyeAt,16/9,$land,-10.);
$pilot->step(1/60,$driver,$eyeAt,16/9,$land,-10.);
for($i=0;$i<2699;$i++) $pilot->step(1/60,$parked,$eyeAt,16/9,$land,-10.);
expect($pilot->snapshot()['phase']==='escort' && $pilot->seeker()['phase']==='chase','The patrol and its seeker stay through 44.98 continuous seconds under speed.');
$pilot->step(1/60,$parked,$eyeAt,16/9,$land,-10.);
expect($pilot->snapshot()['phase']==='departure' && $pilot->seeker()['phase']==='missed','Both break off at 45 seconds below the threshold.');

// Raise a wall on the flight path itself, which can be well away from the driver's path.
$pilot->resume($checkpoint); $p=$pilot->pose();
$land->queue(['type'=>'raise','x'=>$p['x']+$p['vx'],'z'=>$p['z']+$p['vz'],'radius'=>45.,'amount'=>90.]);
$land->commit();
for($i=0;$i<25 && $pilot->snapshot()['phase']!=='departure';$i++) $pilot->step(1/60,$driver,$eyeAt,16/9,$land,-10.);
expect($pilot->snapshot()['phase']==='departure','Rising ground ahead of the aircraft triggers a peel-away.');
$before=$pilot->pose();
for($i=0;$i<60;$i++) $pilot->step(1/60,$driver,$eyeAt,16/9,$land,-10.);
expect($pilot->pose()['y']>$before['y']+15. && $pilot->pose()['pitch']>.5,'The departing jet raises its nose and climbs.');
$land->close();
