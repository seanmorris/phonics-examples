<?php
use TerrainPhonics\{Camera,Landscape};
use TerrainSandbox\{AirPatrol,Car,DemoEvents,DemoTape,FlightTrails,Jet,Session,SpeedSeeker,SeekerMount,VehicleModel};

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
	expect(SpeedSeeker::mountedSlots($pilot->seeker())===[0,1],'The arriving patrol carries a seeker under each wing.');
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
$releaseTime=$stationTime=$hitTime=null; $visibleRelease=false;
$maxLead=0.; $swing=false;
$glances=[];
for($i=0;$i<880;$i++) {
	$drive->step(1/60); $drive->demo->capture($drive);
	$pilot=$drive->airPatrol->snapshot();
	if(!isset($pilot['pose'])) continue;
	$p=$pilot['pose']; [$eye,$car]=$drive->view(1.);
	$first ??= abs($car['speed'])/Car::MPS_PER_MPH;
	$shot=$pilot['seeker']; $time=$drive->demo->duration();
	if(isset($pilot['glance'])) {
		[$pan,$tilt]=$pilot['glance'];
		if(abs($pan)+abs($tilt)>deg2rad(1.)) $glances[$pilot['phase']==='arrival' ? 'arrival' : $shot['phase']]=$time;
	}
	if($shot['phase']==='drop' && $shot['clock']===0.) {
		$releaseTime=$time;
		$wing=Jet::projectileMount($p,0); $released=$pilot['seeker']['pose'];
		$wingRelease=abs($released['x']-$wing['x'])+abs($released['y']-$wing['y'])+abs($released['z']-$wing['z'])<1e-6;
	}
	if($shot['phase']==='drop') {
		$wing=Jet::projectileMount($p,0); $nose=array_slice(VehicleModel::basis($p),6);
		$prop=$shot['pose']; $lead=0.;
		foreach(['x','y','z'] as $j=>$axis) $lead+=($prop[$axis]-$wing[$axis])*$nose[$j];
		if($shot['clock']<.55) $maxLead=max($maxLead,$lead);
		if($maxLead>12. && $lead< -5. && abs($prop['x']-$car['x'])>5. && $prop['y']<$p['y']-3.) $swing=true;
	}
	if($shot['phase']==='chase') $stationTime ??= $time;
	if($drive->airPatrol->carImpact!==null) $hitTime ??= $time;
	[$f,$r,$up]=$eye->basis();
	$rel=[$p['x']-$eye->position[0],$p['y']-$eye->position[1],$p['z']-$eye->position[2]];
	$dot=static fn($v)=>$rel[0]*$v[0]+$rel[1]*$v[1]+$rel[2]*$v[2];
	$depth=$dot($f); $height=$depth*tan($eye->fov/2);
	if($depth>0. && abs($dot($r))<$height*16/9 && abs($dot($up))<$height) {
		if($shot['phase']==='drop' && $shot['clock']===0.) $visibleRelease=true;
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
expect($wingRelease && $visibleRelease,'The seeker starts at the visible wing mount on the frame it detaches.');
expect($releaseTime!==null && $stationTime!==null && abs($stationTime-$releaseTime-1.95)<.05 && $maxLead>13. && $swing,'The prop passes ahead of the moving F5, falls back alongside it and arcs down outside the car.');
expect($stationTime!==null && $hitTime!==null && $hitTime-$stationTime>=3. && $hitTime-$stationTime<=4.,
	'Driving straight through the rear-quarter warning produces a real bumper hit in three to four seconds.');
expect($first>60. && $first<62. && $bank>.67,'The F5 visibly banks into the chase camera just after the driver exceeds 60 mph.');
expect($minSide<2. && $maxSide>11. && $visible>300 && $minSeparation>30.,'The pilot paces overhead and alongside with a clear view of the car.');
expect(isset($glances['arrival'],$glances['drop'],$glances['chase']),'Both the first fly-by and the approaching seeker draw a visible camera glance.');
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

// Exercise the normal pad input even when straight ahead is both the manual and resting look angle.
foreach([$glances['arrival'],$glances['chase']] as $at) {
	$game=new Session($flat);
	foreach([['type'=>'demo-open','json'=>$demo],['type'=>'demo-seek','seconds'=>$at],'demo-take-control'] as $action)
		$game->input(['actions'=>[$action]],0.,16/9);
	$eyeAtCut=$copy->frame($at)['camera'];
	$view=$game->view(1.)[0];
	expect(abs($view->yaw-$eyeAtCut->yaw)+abs($view->pitch-$eyeAtCut->pitch)<.0001,'Taking control preserves the recorded glance exactly once.');
	foreach([[0.,-.34,false],[0.,-.36,true],[1.,0.,true],[-1.,0.,true],[0.,1.,true]] as [$x,$y,$manual]) {
		$game->input(['pad'=>[0.,0.,$x,$y,0.,0.,0]],0.,16/9);
		$view=$game->view(.5)[0]; $base=$game->carView(.5)[0];
		expect(($view->yaw===$base->yaw && $view->pitch===$base->pitch)===$manual,'Any deliberate right-stick direction immediately owns both view angles.');
	}
	$game->step(1/60);
	expect(!isset($game->airPatrol->snapshot()['glance']),'Holding manual look clears the automatic glance.');
	$game->input([],0.,16/9); $game->step(1/60);
	[$pan,$tilt]=$game->airPatrol->snapshot()['glance'] ?? [0.,0.];
	expect(max(abs($pan),abs($tilt))<deg2rad(.5),'Centering the stick eases automatic framing back in.');
	$game->close();
}

$rewound=new AirPatrol;
$copy->restorePatrolSmoke($rewound,$entryTime,$copy->frame($entryTime));
$entryTrails=str_split($rewound->smoke?->instances() ?? '',FlightTrails::STRIDE);
expect(count($entryTrails)>=2 && count($entryTrails)%2===0,'Seeking into the entry bank rebuilds paired wingtip vapor before either seeker fires.');
foreach($entryTrails as $bytes) expect(unpack('gkind',substr($bytes,32,4))['kind']===0.,'Neither wing emits exhaust while its seeker is mounted.');
$when=$stationTime+.5;
$copy->restorePatrolSmoke($rewound,$when,$copy->frame($when));
expect($rewound->smoke?->active()===true,'Seeking into a chase reconstructs the smoke already in the air.');
$copy->restorePatrolSmoke($rewound,0.,$copy->frame(0.));
expect($rewound->smoke===null,'Rewinding before launch clears later smoke.');

$afterHit=$copy->frame($hitTime+.6);
expect(isset($afterHit['events']['airPatrol']['seeker']['mount']) && !isset($copy->frame($hitTime+.92)['events']['airPatrol']['seeker']['mount']),
	'The seated model survives a demo seek and clears before the last explosion puffs fade.');
$copy->restorePatrolSmoke($rewound,$hitTime+.6,$afterHit);
expect($rewound->smoke?->active()===true && !$rewound->smoke->showsLodgedSeeker(),'The fast car has left its shell in the fading burst, including on a demo seek.');

// Test the framing with the same camera-relative projection as the renderer.
$inFrame=static function(Camera $eye,array $point,float $margin): bool {
	[$x,$y,$z]=array_map(static fn($v,$origin)=>$v-$origin,$point,$eye->position);
	$m=$eye->viewProjection(1600,900);
	$depth=$m[3]*$x+$m[7]*$y+$m[11]*$z;
	return $depth>0. && max(abs($m[0]*$x+$m[4]*$y+$m[8]*$z),abs($m[1]*$x+$m[5]*$y+$m[9]*$z))<$margin*$depth;
};
$xyz=static fn(array $p)=>[$p['x'],$p['y'],$p['z']];
[$back]=AirPatrol::interpolateGlance([deg2rad(179.),0.],[deg2rad(-179.),0.],.5);
expect(abs(abs($back)-M_PI)<1e-8,'Tracking across the rear view takes the short way around.');

// Try the handbrake during the fly-out, the swing down, and from both sides of the rear chase.
foreach([[.02,'KeyD',false,false],[.53,'KeyD',false,false],[1.55,'KeyA',true,true],[2.65,'KeyD',true,false],[3.25,'KeyD',true,true],[3.,'KeyA',true,true]] as [$delay,$steer,$shakes,$wins]) {
	$game=new Session($flat);
	foreach([['type'=>'demo-open','json'=>$demo],['type'=>'demo-seek','seconds'=>$releaseTime+$delay],'demo-take-control'] as $action)
		$game->input(['actions'=>[$action]],0.,16/9);
	$lockedAt=$wonAt=$previousPose=null; $missed=$followed=$driftedOnApproach=false;
	$returnSpeeds=[];
	for($tick=0;$tick<360;$tick++) {
		if($tick===0 || $tick===24) $game->input(['keys'=>$tick===0 ? ['KeyW'=>1,$steer=>1,'Space'=>1] : ['KeyW'=>1]],0.,16/9);
		$beforeShot=$game->airPatrol->seeker();
		$beforeCar=$game->car->state();
		$game->step(1/60); $game->demo->capture($game);
		$patrol=$game->airPatrol->snapshot(); $shot=$patrol['seeker'] ?? [];
		if(($beforeShot['phase'] ?? '')==='drop' && abs($game->car->state()['drift'])>=.18) {
			$driftedOnApproach=true;
			expect(in_array($shot['phase'],['drop','chase'],true),'A slide during the approach leaves the car lock intact.');
		}
		if(($shot['phase'] ?? '')==='chase') $followed=true;
		if(($shot['phase'] ?? '')==='missed' && !$missed) {
			$missed=true;
			$body=VehicleModel::basis($beforeCar); $at=$beforeShot['pose'];
			$along=($at['x']-$beforeCar['x'])*$body[6]+($at['y']-$beforeCar['y'])*$body[7]+($at['z']-$beforeCar['z'])*$body[8];
			expect($beforeShot['phase']==='chase' && $along<=-Car::BODY_REAR,'A drift only releases the seeker from behind the bumper.');
		}
		if(($shot['phase'] ?? '')==='return') {
			$at=$shot['pose'];
			if($shot['clock']>=.20) {
				$view=$game->view(1.)[0];
				expect($inFrame($view,$xyz($at),.75) && $inFrame($view,$xyz($patrol['pose']),.75),
					'The chase camera keeps the returning seeker and the jet inside the frame on either side of the road.');
			}
			if($lockedAt===null) {
				$lockedAt=$game->demo->duration();
				expect($followed && $missed,'The lock switches only after a drift has released the seeker.');
			} else {
				$speed=60.*hypot(hypot($at['x']-$previousPose['x'],$at['z']-$previousPose['z']),$at['y']-$previousPose['y']);
				$returnSpeeds[(int)round(60*$shot['clock'])]=$speed/$shot['passSpeed'];
			}
		}
		$previousPose=$shot['pose'] ?? null;
		if(($patrol['repelled'] ?? false) && $wonAt===null) {
			$wonAt=$game->demo->duration();
			expect($shot['impact']===[$patrol['pose']['x'],$patrol['pose']['y'],$patrol['pose']['z']] &&
				$patrol['phase']==='departure' && $game->airPatrol->carImpact===null,'The return bursts on the plane and sends the pilot away.');
		}
		if($wonAt!==null && isset($shot['impact']) && $shot['clock']<=.40)
			expect($inFrame($game->view(1.)[0],$shot['impact'],.22),'The camera holds the world-space flash as the car drives on.');
	}
	if($delay<1.95) expect($driftedOnApproach && $followed,'An early handbrake slide still leads into the bumper chase.');
	expect($missed===$shakes,'A slide that ends before the bumper chase cannot shake the lock later.');
	expect(($lockedAt!==null)===$wins && ($wonAt!==null)===$wins,'Only a drift that brings the loose seeker back near the F5 wins.');
	if($wins) {
		expect(abs($returnSpeeds[1]-1.)<.08 && $returnSpeeds[6]<1.25 && $returnSpeeds[12]>$returnSpeeds[6] &&
			$returnSpeeds[20]>$returnSpeeds[12] && $returnSpeeds[27]>max(1.4,$returnSpeeds[20]),
			'The loose seeker carries its drift speed into the switch and visibly builds speed over the next 440 ms.');
		expect(abs($wonAt-$lockedAt-.79)<.04,'The camera move preserves the timing of the return.');
		[$pan,$tilt]=$game->airPatrol->snapshot()['glance'] ?? [0.,0.];
		expect(max(abs($pan),abs($tilt))<.02,'The view eases back to the road after the flash.');
		$won=$game->airPatrol->snapshot();
		$away=new AirPatrol; $away->resume($won);
		$fast=$game->car->state(); $fast['speed']=70.;
		for($tick=0;$tick<1200;$tick++) $away->step(1/60,$fast,fn()=>$eye,16/9,$land,-10.);
		expect($away->pose()===null && $away->snapshot()['repelled'],'A victorious driver can continue speeding beyond the normal respawn time.');
		$fast['speed']=0.; $away->step(1/60,$fast,fn()=>$eye,16/9,$land,-10.);
		$fast['speed']=70.; $away->step(1/60,$fast,fn()=>$eye,16/9,$land,-10.);
		expect($away->snapshot()['phase']==='arrival','Slowing and crossing the limit again allows a new encounter.');
		$game->input(['actions'=>['demo-stop']],0.,16/9);
		$recording=$game->demo->export(); $result=DemoTape::import($recording);
		expect($result->frame($wonAt+.1)['events']['airPatrol']['repelled'],'The recorded win survives export and a seek.');
		foreach([['type'=>'demo-open','json'=>$recording],['type'=>'demo-seek','seconds'=>$lockedAt+.25],'demo-take-control'] as $action)
			$game->input(['actions'=>[$action]],0.,16/9);
		for($tick=0;$tick<42;$tick++) $game->step(1/60);
		expect($game->airPatrol->snapshot()['repelled'] ?? false,'Taking control halfway through a recorded return completes the encounter.');
		foreach([$lockedAt+.25,$wonAt+.17] as $at) {
			foreach(['demo-stop',['type'=>'demo-open','json'=>$recording],['type'=>'demo-seek','seconds'=>$at],'demo-take-control'] as $action)
				$game->input(['actions'=>[$action]],0.,16/9);
			$recorded=$result->frame($game->demo->duration())['camera']; $view=$game->view(1.)[0];
			expect(abs(atan2(sin($view->yaw-$recorded->yaw),cos($view->yaw-$recorded->yaw)))+abs($view->pitch-$recorded->pitch)<.0001,'Taking control preserves the return or impact framing.');
			$game->input(['pad'=>[0.,0.,1.,0.,0.,0.,0]],0.,16/9); $game->step(1/60);
			$view=$game->view(.5)[0]; $base=$game->carView(.5)[0];
			expect($view->yaw===$base->yaw && $view->pitch===$base->pitch,'The right stick takes over during both the return and the impact hold.');
		}
	}
	$game->close();
}

// Release behind the bumper, then turn the car so the coasting seeker is ahead of it.
$prop=new SpeedSeeker;
$pose=['x'=>-4.,'y'=>4.,'z'=>0.,'yaw'=>M_PI/2,'pitch'=>0.,'roll'=>0.];
$prop->resume(['phase'=>'chase','clock'=>0.,'start'=>array_fill(0,6,0.),'pose'=>$pose,'velocity'=>[1.,0.,0.],'passSpeed'=>1.]);
$car=['x'=>0.,'y'=>0.,'z'=>0.,'yaw'=>M_PI/2,'speed'=>50.,'drift'=>.3];
$plane=array_replace($pose,['x'=>10.,'y'=>8.,'vx'=>0.,'vy'=>0.,'vz'=>0.]);
$prop->step(1/60,$car,$plane,'escort',$land,-10.);
$released=$prop->state();
expect($released['phase']==='missed' && isset($released['rearPassGap']),'A drift behind the bumper earns a retarget opportunity.');
$car['yaw']=-M_PI/2; $car['drift']=0.;
for($i=0;$i<8;$i++) $prop->step(1/60,$car,$plane,'escort',$land,-10.);
expect($prop->state()['phase']==='return','Turning the car after a rear release keeps the converging seeker eligible for the F5.');

// Early takes used passGap for releases that could happen beside the wing.
unset($released['rearPassGap']);
$released['passGap']=25.; $released['clock']=.2;
$prop->resume($released); $prop->step(1/60,$car,$plane,'escort',$land,-10.);
expect($prop->state()['phase']==='missed','Resuming an old wing-side release does not grant a rear release.');
// Cross the 80 ms release between two ticks. Inspect the endpoints and times actually consumed by the shader.
foreach([30,60,120] as $hz) {
	$effects=new FlightTrails; $mount=[0.,.6,-1.7,0.,0.]; $base=['x'=>5.,'y'=>0.,'z'=>0.,'yaw'=>0.,'pitch'=>0.,'roll'=>0.];
	$effects->seeker(1/$hz,null,null,[5.,.6,-1.7],$mount,$base);
	for($tick=1;$tick<=ceil(.15*$hz);$tick++) {
		$car=$base; $car['x']+=100.*$tick/$hz;
		$effects->seeker(1/$hz,null,null,null,$mount,$car);
		$b=array_values(unpack('g*',$effects->instances()));
		expect(count($b)===11 && abs($b[4]-(5.+100.*min(.080,$tick/$hz)))<.0001 &&
			abs($b[7]-$b[3]-min(.080,$tick/$hz))<.00001,"A single flash follows the nose for precisely 80 ms at $hz Hz.");
	}
}
foreach([0.,100.] as $speed) {
	$effects=new FlightTrails; $car=$base;
	$effects->seeker(1/60,null,null,SeekerMount::point($mount,$car),$mount,$car);
	for($tick=1;$tick<=60 && $effects->showsLodgedSeeker();$tick++) {
		$car['z']+=$speed/60.;
		$effects->seeker(1/60,null,null,null,$mount,$car);
	}
	$age=($tick-1)/60.;
	expect(!$effects->showsLodgedSeeker() && $effects->active() && ($speed===0. ? abs($age-.87)<.02 : abs($age-.16)<.02),
		'The shell clears while the burst is still visible, including when the car outruns it.');
}
// Measure the aircraft the player sees, across several passes and in the car's own travel direction.
foreach([[28.,M_PI/2],[72.,0.],[106.,M_PI]] as [$speed,$heading]) {
	$pilot=new AirPatrol; $eye=new Camera; $eye->yaw=M_PI-$heading; $eye->fov=1.36;
	$f=sin($heading); $g=cos($heading);
	$car=['x'=>-18.*$speed*$f,'y'=>0.,'z'=>-18.*$speed*$g,'yaw'=>$heading,'drift'=>0.,'speed'=>$speed];
	$left=180.; $right=-180.; $lead=INF; $crossings=[]; $side=0; $releases=[]; $reloaded=false;
	for($tick=0;$tick<36*60;$tick++) {
		$car['x']+=$speed*$f/60.; $car['z']+=$speed*$g/60.;
		$eye->position=[$car['x']-7.*$f,3.,$car['z']-7.*$g];
		$pilot->step(1/60,$car,fn()=>$eye,16/9,$land,-10.);
		$shot=$pilot->seeker();
		if($shot['phase']==='mounted' && $releases) $reloaded=SpeedSeeker::mountedSlots($shot)===[0,1];
		if($shot['phase']==='drop' && $shot['clock']===0.) {
			$slot=$shot['slot']; $releases[]=$slot;
			$wing=Jet::projectileMount($pilot->pose(),$slot);
			foreach(['x','y','z'] as $axis)
				expect(abs($shot['pose'][$axis]-$wing[$axis])<1e-6,'The flying model departs the wing that just emptied.');
			expect(SpeedSeeker::mountedSlots($shot)===[1-$slot],'The opposite projectile stays mounted on release.');
			$restored=new SpeedSeeker; $restored->resume($shot);
			expect($restored->state()['slot']===$slot,'Taking control preserves the releasing wing.');
		}
		if($tick<15*60) continue;
		$p=$pilot->pose();
		if($p===null) throw new RuntimeException('The patrol left the flat-road crossing fixture.');
		$x=$p['x']-$car['x']; $z=$p['z']-$car['z'];
		$along=$x*$f+$z*$g; $across=$x*$g-$z*$f; $bearing=rad2deg(atan2($across,$along));
		$left=min($left,$bearing); $right=max($right,$bearing); $lead=min($lead,$along);
		$newSide=$across<=>0.; if($side!==0 && $newSide!==$side) $crossings[]=$tick/60.;
		$side=$newSide;
	}
	expect($releases===[0,1] && $reloaded,'Both wings reload and fire in turn on the same patrol.');
	expect($lead>20. && $left<-55. && $left>-68. && $right>55. && $right<68.,
		"The F5 stays ahead and reaches both 10 and 2 o'clock at $speed m/s.");
	expect(count($crossings)>=3,'The pilot keeps alternating shoulders.');
	for($i=1;$i<count($crossings);$i++) expect(abs(($crossings[$i]-$crossings[$i-1])-5.24)<.4,
		'Each crossing gives the driver another predictable drift opportunity.');
}

// A sudden move of the formation station can command a turn but cannot relocate the airframe.
$driver=['x'=>0.,'y'=>0.,'z'=>0.,'yaw'=>0.,'drift'=>0.,'speed'=>72.];
$eye=new Camera; $eye->yaw=M_PI; $eye->pitch=.1;
$eyeAt=function() use (&$eye) { return $eye; };
$pilot=new AirPatrol;
for($i=0;$i<720 && ($pilot->seeker()['phase'] ?? null)!=='chase';$i++) {
	$driver['z']+=$driver['speed']/60.; $eye->position=[0.,3.,$driver['z']-7.];
	$pilot->step(1/60,$driver,$eyeAt,16/9,$land,-10.);
}
$checkpoint=$pilot->snapshot();
expect($checkpoint['phase']==='escort' && $checkpoint['seeker']['phase']==='chase','A joined escort releases a seeker which reaches the rear bumper.');
$tail=$checkpoint['seeker']['pose'];
expect($driver['z']-$tail['z']>2. && $driver['z']-$tail['z']<3.2 && abs($tail['x'])<1.5 &&
	abs($tail['y']-$driver['y']-Car::BODY_HEIGHT-2.12)<.02,'The seeker looms above the roof over the rear bumper.');
// A take recorded before the higher pass should continue at its original height when branched.
$oldTail=$checkpoint['seeker'];
array_pop($oldTail['start']); $oldTail['pose']['y']-=.8;
$prop=new SpeedSeeker; $prop->resume($oldTail);
$nextCar=$driver; $nextCar['z']+=$driver['speed']/60.;
$prop->step(1/60,$nextCar,$checkpoint['pose'],'escort',$land,-10.,$driver);
expect(abs($prop->state()['pose']['y']-$oldTail['pose']['y'])<.01,'An older bumper chase resumes without jumping up.');
// A turning car can put the seeker beside it even during the closing animation.
$sidePass=$checkpoint['seeker']; $sidePass['pose']['z']=$driver['z']; $sidePass['pose']['x']+=4.;
$prop=new SpeedSeeker; $prop->resume($sidePass);
$skid=$driver; $skid['drift']=.3;
$prop->step(1/60,$skid,$checkpoint['pose'],'escort',$land,-10.,$driver);
expect($prop->state()['phase']==='chase','A slide with the seeker beside the car keeps the lock even during the closing animation.');
// Replay the same rear-quarter station at different step sizes, including one late escape.
foreach([30,60,120] as $fps) {
	$prop=new SpeedSeeker; $prop->resume($checkpoint['seeker']); $car=$driver;
	for($tick=1;$tick<=4*$fps;$tick++) {
		$oldCar=$car; $car['z']+=$car['speed']/$fps;
		$hit=$prop->step(1/$fps,$car,$checkpoint['pose'],'escort',$land,-10.,$oldCar);
		if($hit!==null) break;
	}
	expect(($hit['kind'] ?? null)==='car' && $tick/$fps>=3. && $tick/$fps<=4., "Bumper contact stays in the warning window at $fps Hz.");
}
$prop=new SpeedSeeker; $prop->resume($checkpoint['seeker']); $car=$driver;
for($tick=1;$tick<=181;$tick++) {
	$oldCar=$car; $car['z']+=$car['speed']/60.;
	if($tick===181) $car['drift']=.3;
	$hit=$prop->step(1/60,$car,$checkpoint['pose'],'escort',$land,-10.,$oldCar);
}
expect($hit===null && $prop->state()['phase']==='missed','A drift three seconds into the closing pass still breaks the lock.');
$restart=new AirPatrol; $restart->resume($checkpoint);
$pilot->step(1/60,$driver,$eyeAt,16/9,$land,-10.);
$restart->step(1/60,$driver,$eyeAt,16/9,$land,-10.);
expect($pilot->snapshot()===$restart->snapshot(),'Branching a recording retains airspeed, control surfaces, travel direction and seeker continuity.');
$displaced=$driver; $displaced['x']+=90.; $displaced['yaw']=M_PI;
$restart->resume($checkpoint);
$restart->step(1/60,$displaced,$eyeAt,16/9,$land,-10.);
$moved=$restart->seeker()['pose']; $ref=$pilot->seeker()['pose'];
expect($moved['x']>$ref['x']+1. && $moved['x']<$ref['x']+30.,'The rear-quarter prop starts following a sudden steering change without snapping to the new mark.');
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
		if($wide>140. && abs($x*$g-$z*$f)<25. && $x*$f+$z*$g>25. && $range<62. && cos($p['yaw']-$car['yaw'])>.75) $rejoined=true;
	}
	expect($rejoined,"The same pilot loses formation on a $maneuver and recovers the chase.");
}

$pilot=new AirPatrol; $pilot->resume($checkpoint);
$skid=$driver; $skid['drift']=.3;
$pilot->step(1/60,$skid,$eyeAt,16/9,$land,-10.);
$miss=$pilot->seeker();
expect($miss['phase']==='missed','A real slide immediately shakes the current seeker.');
$coast=new SpeedSeeker; $coast->resume($checkpoint['seeker']);
$coast->step(1/60,$driver,null,'departure',$land,-10.);
$plain=$coast->state();
expect(abs($miss['pose']['pitch']-$plain['pose']['pitch']-deg2rad(5.))<1e-9 &&
	$miss['velocity'][1]>$plain['velocity'][1]+2. && $miss['pose']['y']>$plain['pose']['y'] &&
	$miss['velocity'][0]===$plain['velocity'][0] && $miss['velocity'][2]===$plain['velocity'][2],
	'Shaking the seeker lifts its nose and its path while carrying the sideways drift.');
$widePass=$pilot->snapshot(); $widePass['pose']['x']+=180.; // Keep the F5 out of retargeting range on both paths.
$pilot->resume($widePass); $other=new AirPatrol; $other->resume($widePass);
for($i=0;$i<60;$i++) {
	$pilot->step(1/60,$driver,$eyeAt,16/9,$land,-10.);
	$other->step(1/60,$displaced,$eyeAt,16/9,$land,-10.);
}
expect($pilot->seeker()['pose']===$other->seeker()['pose'] && $pilot->seeker()['pose']['z']>$miss['pose']['z']+30. &&
	abs($pilot->seeker()['pose']['y']-$miss['pose']['y']-$miss['velocity'][1])<.0001,
	'Once shaken, the prop sails past on the same course whichever way the car turns.');
// With the patrol peeling away, the same loose shot expires and cannot score a return.
$escaping=new SpeedSeeker; $escaping->resume($miss);
for($i=0;$i<370;$i++) $escaping->step(1/60,$driver,null,'departure',$land,-10.);
expect($escaping->state()['phase']==='reload' && $escaping->state()['pose']===null,'A loose shot expires after an escape.');

// A short stop or two accumulated slow periods cannot dismiss the patrol. Test both sides of 45 continuous seconds.
$pilot->resume($checkpoint); $parked=$driver; $parked['speed']=0.;
for($i=0;$i<600;$i++) $pilot->step(1/60,$parked,$eyeAt,16/9,$land,-10.);
$pilot->step(1/60,$driver,$eyeAt,16/9,$land,-10.);
for($i=0;$i<2699;$i++) $pilot->step(1/60,$parked,$eyeAt,16/9,$land,-10.);
expect($pilot->snapshot()['phase']==='escort','The patrol stays through 44.98 continuous seconds under speed.');
$pilot->step(1/60,$parked,$eyeAt,16/9,$land,-10.);
expect($pilot->snapshot()['phase']==='departure','The pilot breaks off at 45 seconds below the threshold.');

// The drop clears a raised ridge even with the parked driver on its low side.
$ridge=new Landscape($flat);
$ridge->queue(['type'=>'raise','x'=>0.,'z'=>20.,'radius'=>14.,'amount'=>18.]); $ridge->commit();
$drop=new SpeedSeeker;
$car=['x'=>0.,'y'=>0.,'z'=>0.,'yaw'=>0.,'speed'=>72.,'drift'=>0.];
$plane=$checkpoint['pose']; $plane['x']=0.; $plane['y']=26.; $plane['z']=44.; $plane['yaw']=$plane['pitch']=$plane['roll']=0.;
$wingEye=new Camera; $wingEye->position=[0.,1.,-8.]; $wingEye->yaw=M_PI; $wingEye->pitch=.35;
for($i=0;$i<120;$i++) $drop->step(1/60,$car,$plane,'escort',$ridge,-10.,null,fn()=>$wingEye,16/9);
expect($drop->state()['phase']==='mounted','A ridge hiding the wing holds the release even when the wing projects on screen.');
$wingEye->position=[0.,30.,-8.]; $wingEye->pitch=-.08; $wingEye->yaw=0.;
for($i=0;$i<120;$i++) $drop->step(1/60,$car,$plane,'escort',$ridge,-10.,null,fn()=>$wingEye,16/9);
expect($drop->state()['clock']===0.,'A plane behind the camera cannot start a release.');
$wingEye->yaw=M_PI;
for($i=0;$i<75;$i++) $drop->step(1/60,$car,$plane,'escort',$ridge,-10.,null,fn()=>$wingEye,16/9);
$wingEye->yaw=0.;
$drop->step(1/60,$car,$plane,'escort',$ridge,-10.,null,fn()=>$wingEye,16/9);
$wingEye->yaw=M_PI;
for($i=0;$i<75;$i++) $drop->step(1/60,$car,$plane,'escort',$ridge,-10.,null,fn()=>$wingEye,16/9);
expect($drop->state()['phase']==='mounted','Looking away resets the visible warning beat.');
$minGap=INF;
for($i=0;$i<240 && $drop->state()['phase']!=='chase';$i++) {
	$drop->step(1/60,$car,$plane,'escort',$ridge,-10.,null,fn()=>$wingEye,16/9);
	$body=$drop->state()['pose']; if($body===null) continue;
	$b=TerrainSandbox\VehicleModel::basis($body);
	foreach([-2.,0.,2.] as $along) {
		$ground=$ridge->height($body['x']+$along*$b[6],$body['z']+$along*$b[8]);
		$minGap=min($minGap,$body['y']+$along*$b[7]-.22-$ground);
	}
}
expect($minGap>=1.24 && $drop->state()['phase']==='chase','The nose, body and fins all clear the ridge before taking station over the bumper.');
$ridge->close();

// An unsteered prop hits either the moving chassis or the ground. Drive the public Session through the impact.
foreach(['car','ground'] as $object) {
	$game=new Session($flat); $game->camera->position=[0.,35.,0.]; $game->camera->yaw=M_PI;
	$game->input(['actions'=>['car'],'values'=>['airPatrolEnabled'=>true]],0.,16/9);
	for($i=0;$game->findingCarSite() && $i<1000;$i++) $game->input([],0.,16/9);
	$c=$game->car->state(); $hitState=$checkpoint;
	$shot=&$hitState['seeker']; $shot['phase']='missed'; $shot['clock']=0.; $shot['impact']=null;
	$shot['pose']=['x'=>$c['x']+($object==='car' ? 0. : 12.),'y'=>$c['y']+($object==='car' ? .65 : 4.),
		'z'=>$c['z']-9.,'yaw'=>0.,'roll'=>0.,'pitch'=>$object==='car' ? 0. : -.5];
	$shot['velocity']=$object==='car' ? [0.,0.,90.] : [0.,-22.,40.];
	$game->airPatrol->resume($hitState); unset($shot);
	$hits=0; $flashes=0; $peak=0.; $sawSmoke=false; $lodged=false; $cleared=false;
	for($i=0;$i<600;$i++) {
		$game->step(1/60);
		if($game->airPatrol->carImpact!==null) $hits++;
		$effect=$game->airPatrol->seeker();
		if(isset($effect['mount'])) {
			expect($object==='car' && $effect['phase']==='reload' && $effect['pose']===null,'Only a direct hit leaves an inert projectile.');
			if($effect['clock']>.5) {
				$pose=$game->car->state(); $mount=$effect['mount']; $body=VehicleModel::basis($pose);
				$m=SeekerMount::model($mount,$pose);
				foreach(['x','y','z'] as $k=>$axis) {
					$tip=$m[$axis]+2.*$m['basis'][6+$k];
					$expected=$pose[$axis]+$mount[0]*$body[$k]+$mount[1]*$body[3+$k]+$mount[2]*$body[6+$k];
					expect(abs($tip-$expected)<1e-6,'The model rotates around its buried nose during the tumble.');
				}
				$lodged=true;
			}
		} elseif($lodged) $cleared=true;
		$peak=max($peak,$game->car->state()['y']-$c['y']);
		$burstCount=$exhaustCount=0;
		foreach(str_split($game->airPatrol->smoke?->instances() ?? '',FlightTrails::STRIDE) as $bytes) {
			$instance=unpack('g*',$bytes);
			if($instance[9]===1.) $exhaustCount++;
			if($instance[9]===2.) $burstCount++;
		}
		$sawSmoke=$sawSmoke || $exhaustCount>0;
		$flashes=max($flashes,$burstCount);
	}
	expect($object==='ground' || ($lodged && $cleared),'The inert shell lasts through the flash and then clears.');
	expect($sawSmoke && $flashes===1 && $burstCount===0 && $exhaustCount===0,"A $object hit emits one burst and its exhaust and flash expire.");
	expect($game->mode==='car' && $game->car->state()['motion']==='driving' &&
		($object==='car' ? $hits===1 && $peak>3. : $hits===0 && $peak<.5),
		'Only a direct hit throws the car, and the normal landing or recovery returns control.');
	$game->close();
}

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
