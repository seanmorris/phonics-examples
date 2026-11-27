<?php
use TerrainPhonics\{Camera,Landscape};
use TerrainSandbox\{AirPatrol,Car,DemoEvents,DemoTape,FlightLights,FlightTrails,Jet,Session,SpeedSeeker,SeekerMount,VehicleModel};

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

// Observe a level F5 from each sector and inspect the lights sent to the renderer.
$level=['x'=>0.,'y'=>10.,'z'=>0.,'yaw'=>0.,'pitch'=>0.,'roll'=>0.,'power'=>0.];
$lightEye=new Camera;
foreach([[[450.,10.,0.],[1.,.022,.012]],[[-450.,10.,0.],[.015,1.,.12]],[[0.,10.,-450.],[1.,.96,.88]]] as [$where,$color]) {
	$lightEye->position=$where;
	$lamps=FlightLights::lamps($level,true,$lightEye,.5,1.);
	expect(strlen($lamps)===32,'A side or rear observer sees its single steady navigation color on the unpowered jet.');
	$v=array_values(unpack('g*',$lamps));
	foreach($color as $i=>$c) expect(abs($v[4+$i]-$c)<.00001,'The navigation lamp color identifies the observed aspect.');
}
expect(strlen(FlightLights::lamps($level,true,$lightEye,.14,1.))===64,'The beacon flashes independently of engine power.');
$level['power']=1.;
expect(strlen(FlightLights::lamps($level,true,$lightEye,.5,1.))===96,'The running F5 has two glowing exhausts when seen from aft.');
expect(strlen(FlightLights::lamps($level,false,$lightEye,.5,1.))===32,'A flying projectile has a visible nozzle glow from behind.');

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
	if($pilot['phase']==='arrival') {
		$driving=$drive->carView(1.)[0];
		expect($eye->yaw===$driving->yaw && $eye->pitch===$driving->pitch,
			'The arrival plays in the normal driving view all the way into formation.');
	}
	if(isset($pilot['glance'])) {
		[$pan,$tilt]=$pilot['glance'];
		if(abs($pan)+abs($tilt)>deg2rad(1.)) $glances[$shot['phase']]=$time;
	}
	if($shot['phase']==='drop' && $shot['clock']===0.) {
		$releaseTime=$time;
		$wing=Jet::projectileMount($p,0); $released=$pilot['seeker']['pose'];
		expect(SpeedSeeker::modelPose($pilot['seeker'])===$released,'The sway starts at zero on the releasing wing.');
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
expect(isset($glances['drop'],$glances['chase']),'The approaching seeker still draws a visible camera glance.');
$drive->input(['actions'=>['demo-stop']],0.,16/9);
$demo=$drive->demo->export();
$copy=DemoTape::import($demo);
// Scrub the bumper chase at sub-frame times. The forward balance point stays on course as the tail swings.
$minSwing=INF; $maxSwing=-INF; $maxPivotError=$maxTailTravel=$maxNoseTravel=0.;
$onAxis=static function(array $p,float $offset): array {
	$nose=array_slice(VehicleModel::basis($p),6);
	return [$p['x']+$offset*$nose[0],$p['y']+$offset*$nose[1],$p['z']+$offset*$nose[2]];
};
$displacement=static function(array $base,array $shown,float $offset) use ($onAxis): float {
	$a=$onAxis($base,$offset); $b=$onAxis($shown,$offset);
	return hypot(hypot($a[0]-$b[0],$a[2]-$b[2]),$a[1]-$b[1]);
};
for($i=0;$i<120;$i++) {
	$t=$stationTime+.05+$i/120.;
	$sample=$copy->frame($t)['events']['airPatrol']['seeker'];
	$base=$sample['pose']; $shown=SpeedSeeker::modelPose($sample);
	$swing=rad2deg($shown['yaw']-$base['yaw']);
	$minSwing=min($minSwing,$swing); $maxSwing=max($maxSwing,$swing);
	$maxPivotError=max($maxPivotError,$displacement($base,$shown,1.4));
	$maxNoseTravel=max($maxNoseTravel,$displacement($base,$shown,2.));
	$maxTailTravel=max($maxTailTravel,$displacement($base,$shown,-2.));
	if($i>0) expect(abs($sample['airtime']-$lastAirtime-1/120.)<.00001,'Replay interpolates the fishtail between recorded samples.');
	$lastAirtime=$sample['airtime'];
}
expect($minSwing< -4. && $maxSwing>4. && max(-$minSwing,$maxSwing)<=8.01,'The airborne mesh fishtails gently to both sides.');
expect($maxPivotError<1.e-8 && $maxNoseTravel<.09 && $maxTailTravel>.30,'The tail sweeps around a balance point 60 cm behind the nose.');
$effects=new AirPatrol;
$effects->stepSmoke(1./60.,$sample,$sample,[],null,null,null);
$exhaust=array_values(unpack('g*',$effects->smoke->instances()));
$nozzle=$onAxis($shown,-2.);
foreach($nozzle as $i=>$axis) expect(abs($exhaust[4+$i]-$axis)<.0001,'The smoke follows the nozzle of the fishtailing mesh.');
$branched=new SpeedSeeker; $branched->resume($sample);
expect(SpeedSeeker::modelPose($branched->state())===$shown,'Branching a demo preserves the swing at the cut.');
unset($sample['airtime']);
expect(SpeedSeeker::modelPose($sample)===$base,'Earlier recordings keep their recorded projectile pose.');

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
foreach([$glances['drop'],$glances['chase']] as $at) {
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
foreach([[.02,'KeyD',false,false],[.53,'KeyD',false,false],[1.55,'KeyA',true,false],[2.65,'KeyD',true,false],[3.25,'KeyD',true,false],[4.30,'KeyD',true,true],[4.50,'KeyA',true,true]] as [$delay,$steer,$shakes,$wins]) {
	$game=new Session($flat);
	foreach([['type'=>'demo-open','json'=>$demo],['type'=>'demo-seek','seconds'=>$releaseTime+$delay],'demo-take-control'] as $action)
		$game->input(['actions'=>[$action]],0.,16/9);
	$lockedAt=$wonAt=$previousPose=null; $missed=$followed=$driftedOnApproach=false;
	$returnSpeeds=[]; $releaseAngle=$missLead=null;
	for($tick=0;$tick<360;$tick++) {
		if($tick===0 || $tick===24) $game->input(['keys'=>$tick===0 ? ['KeyW'=>1,$steer=>1,'Space'=>1] : ['KeyW'=>1]],0.,16/9);
		$beforeShot=$game->airPatrol->seeker();
		$beforeGlance=$game->airPatrol->snapshot()['glance'] ?? [0.,0.];
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
			$delta=array_map(static fn($plane,$prop)=>$plane-$prop,$xyz($patrol['pose']),$xyz($shot['pose']));
			$nose=array_slice(VehicleModel::basis($shot['pose']),6);
			$cosine=array_sum(array_map(static fn($d,$n)=>$d*$n,$delta,$nose))/hypot(hypot($delta[0],$delta[2]),$delta[1]);
			$releaseAngle=rad2deg(acos(max(-1.,min(1.,$cosine))));
		}
		if(($beforeShot['phase'] ?? '')==='missed' && ($shot['phase'] ?? '')==='reload') {
			$car=$game->car->state(); $heading=$car['yaw']-$car['drift']; $point=$shot['impact'];
			$missLead=($point[0]-$car['x'])*sin($heading)+($point[2]-$car['z'])*cos($heading);
			expect($inFrame($game->view(1.)[0],$point,.90),'A missed drift bursts inside the driving view.');
		}
		if(($shot['phase'] ?? '')==='return') {
			$at=$shot['pose'];
			expect($inFrame($game->view(1.)[0],$xyz($at),.98),
				'The driver sees the loose prop change direction and can follow it on every frame of its return.');
			expect($inFrame($game->view(.5)[0],$xyz($game->airPatrol->seeker(.5)['pose']),1.),
				'Interpolated chase frames keep the same moving prop in sight.');
			$glance=$patrol['glance'] ?? [0.,0.];
			$change=hypot($glance[0]-$beforeGlance[0],$glance[1]-$beforeGlance[1]);
			expect($change<deg2rad($shot['clock']<.02 ? .15 : 1.8),
				'The handoff starts gently and the chase camera pans smoothly through the return.');
			if($shot['clock']>=.15) {
				$base=$game->carView(1.)[0];
				[$x,$y,$z]=array_map(static fn($v,$o)=>$v-$o,$xyz($at),$base->position);
				$yaw=atan2($x,-$z)-$base->yaw;
				$pan=atan2(sin($yaw),cos($yaw)); $tilt=atan2($y,hypot($x,$z))-$base->pitch;
				$remaining=hypot($pan-$glance[0],$tilt-$glance[1])/hypot($pan,$tilt);
				expect($remaining>.25 && $remaining<.90,
					'The camera turns partway from the road toward the seeker, leaving it visibly off-center.');
			}
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
			$wonAt=$game->demo->duration(); $hitPatrol=$patrol;
			expect($shot['impact']===[$patrol['pose']['x'],$patrol['pose']['y'],$patrol['pose']['z']] &&
				$patrol['phase']==='glide' && $patrol['pose']['power']===0. && $game->airPatrol->carImpact===null,'The return bursts on the F5 and cuts its engines.');
		}
		if($wonAt!==null && $game->demo->duration()-$lockedAt>=.95)
			expect(!isset($patrol['glance']), 'The complete camera cue is over within a second of the lock.');
		if($wonAt!==null && isset($shot['impact']) && $shot['clock']<=.50) {
			$glance=$patrol['glance'] ?? [0.,0.];
			expect(hypot($glance[0]-$beforeGlance[0],$glance[1]-$beforeGlance[1])<deg2rad(1.8),
				'The flash and the return to the road keep the same gentle camera movement.');
			if($shot['clock']<=.20) expect($inFrame($game->view(1.)[0],$shot['impact'],.85),
				'The flash stays visible from the driving view as the car passes.');
		}
	}
	if($delay<1.95) expect($driftedOnApproach && $followed,'An early handbrake slide still leads into the bumper chase.');
	expect($missed===$shakes,'A slide that ends before the bumper chase cannot shake the lock later.');
	if($shakes) expect(($releaseAngle<=22.)===$wins,'Only a flick pointed toward the jet at release can win.');
	if($shakes && !$wins) expect(abs($missLead-AirPatrol::ESCORT_LEAD)<6.,'The miss flashes ahead at the usual patrol lead.');
	expect(($lockedAt!==null)===$wins && ($wonAt!==null)===$wins,'The aimed flick returns to the jet and the other flicks miss.');
	if($wins) {
		$last=1.;
		foreach($returnSpeeds as $speed) {
			expect($speed>=$last-.00001,'The mesh covers at least as much ground each tick from the moment of handoff.');
			$last=$speed;
		}
		expect($returnSpeeds[1]<1.3 && $last>1.2,
			'The returning prop carries its drift into a short, eased acceleration.');
		expect($wonAt-$lockedAt<.50 && $wonAt-$lockedAt>.10,'The visible return reaches the jet in under half a second.');
		[$pan,$tilt]=$game->airPatrol->snapshot()['glance'] ?? [0.,0.];
		expect(max(abs($pan),abs($tilt))<.02,'The view eases back to the road after the flash.');
		$away=new AirPatrol; $away->resume($hitPatrol);
		$fast=$game->car->state(); $fast['speed']=70.; $watch=new Camera;
		$touch=null; $flare=false; $puffs=$streak=false;
		for($tick=0;$tick<1500;$tick++) {
			$prev=$away->pose();
			$fast['x']=$prev['x']; $fast['z']=$prev['z']+30.;
			$watch->position=[$fast['x'],$prev['y']+2.,$fast['z']]; $watch->yaw=M_PI;
			$away->step(1/60,$fast,fn()=>$watch,16/9,$land,-10.);
			$state=$away->snapshot(); $p=$state['pose'];
			if($tick===36 || ($touch===null && $state['phase']==='slide')) {
				$recorded=DemoEvents::import([[0,json_encode(['airPatrol'=>$state])]],1)->at(0,0.,'car')['airPatrol'];
				$branch=new AirPatrol; $branch->resume($recorded);
				$branch->step(1/60,$fast,fn()=>$watch,16/9,$land,-10.);
				$nextLandingPose=$branch->pose();
			} elseif(isset($nextLandingPose)) {
				expect(abs($nextLandingPose['y']-$p['y'])<1.e-8 && abs($nextLandingPose['z']-$p['z'])<1.e-8,
					'Branching during the glide or slide preserves landing velocity and surface contact.');
				unset($nextLandingPose);
			}
			if($state['phase']==='glide') {
				expect($p['power']===0. && abs($p['roll'])<=abs($prev['roll'])+.000001,'The gliding pilot levels the wings without thrust.');
				if($p['pitch']>.01 && $p['vy']<0.) $flare=true;
				foreach(str_split($away->smoke->instances(),FlightTrails::STRIDE) as $bytes) {
					$kind=unpack('g',substr($bytes,32,4))[1];
					$puffs=$puffs || $kind===5.; $streak=$streak || $kind===4.;
				}
			} else {
				expect($state['phase']==='slide' && $p['y']>=.82,'The downed aircraft remains on its belly when the nearby driver looks away.');
				$touch??=$p;
				if($p['speed']===0.) break;
			}
		}
		expect($touch && $flare && $puffs && $streak && $p['speed']===0. && hypot($p['x']-$touch['x'],$p['z']-$touch['z'])>20.,
			'The powerless aircraft trails smoke, flares, travels along the ground, then stops.');
		$fast['x']=$p['x']; $fast['z']=$p['z']+650.; $watch->position=[$fast['x'],$p['y'],$fast['z']]; $watch->yaw=0.;
		$away->step(1/60,$fast,fn()=>$watch,16/9,$land,-10.);
		expect($away->pose()!==null,'A distant wreck stays as long as the camera can see it.');
		$watch->yaw=M_PI;
		$away->step(1/60,$fast,fn()=>$watch,16/9,$land,-10.);
		expect($away->pose()===null && $away->snapshot()['repelled'],'A stopped, distant wreck despawns once offscreen.');
		for($tick=0;$tick<750;$tick++) $away->step(1/60,$fast,fn()=>$watch,16/9,$land,-10.);
		$fast['x']=$fast['z']=$fast['yaw']=$fast['drift']=0.; $watch->position=[0.,3.,-7.];
		$fast['speed']=0.; $away->step(1/60,$fast,fn()=>$watch,16/9,$land,-10.);
		$fast['speed']=70.; $away->step(1/60,$fast,fn()=>$watch,16/9,$land,-10.);
		expect($away->snapshot()['phase']==='arrival','Slowing and crossing the limit again allows a new encounter.');
		$game->input(['actions'=>['demo-stop']],0.,16/9);
		$recording=$game->demo->export(); $result=DemoTape::import($recording);
		expect($result->frame($wonAt+.1)['events']['airPatrol']['repelled'],'The recorded win survives export and a seek.');
		foreach([['type'=>'demo-open','json'=>$recording],['type'=>'demo-seek','seconds'=>($lockedAt+$wonAt)/2],'demo-take-control'] as $action)
			$game->input(['actions'=>[$action]],0.,16/9);
		for($tick=0;$tick<42;$tick++) $game->step(1/60);
		expect($game->airPatrol->snapshot()['repelled'] ?? false,'Taking control halfway through a recorded return completes the encounter.');
		foreach([($lockedAt+$wonAt)/2,$wonAt+.12] as $at) {
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

// A crossing after the release cannot turn an unaimed flick into a win, even at close range.
$awayPlane=array_replace($plane,['x'=>0.,'z'=>15.]);
$car['yaw']=M_PI/2; $car['drift']=.3;
$prop->resume(['phase'=>'chase','clock'=>0.,'start'=>array_fill(0,6,0.),'pose'=>$pose,'velocity'=>[1.,0.,0.],'passSpeed'=>1.]);
$prop->step(1/60,$car,$awayPlane,'escort',$land,-10.);
expect(!isset($prop->state()['rearPassGap']),'A nearby jet across the drift direction receives no lock.');
$hit=null;
for($i=0;$i<60 && !$hit;$i++) $hit=$prop->step(1/60,$car,$plane,'escort',$land,-10.);
expect($hit['kind']==='air','A jet crossing the released path later still leaves an ordinary airburst.');

// Looking away during the loose pass closes the handoff, including after branching a recording.
$watch=new Camera; $watch->position=[0.,4.,-14.]; $watch->yaw=0.;
$prop->resume($released);
$prop->step(1/60,$car,$plane,'escort',$land,-10.,null,fn()=>$watch,4/3);
$lost=$prop->state();
expect($lost['phase']==='missed' && !isset($lost['rearPassGap']),'An offscreen pass loses its return cue.');
$prop->resume($lost); $watch->yaw=M_PI;
$burst=null;
for($i=0;$i<60 && $burst===null;$i++) {
	$burst=$prop->step(1/60,$car,$plane,'escort',$land,-10.,null,fn()=>$watch,4/3);
	expect($prop->state()['phase']!=='return','Bringing the prop back into view cannot restart a missed handoff.');
}
expect($burst['kind']==='air' && $i<40 && $inFrame($watch,$burst['point'],.96),
	'A sideways pass flashes before it leaves the viewport, ahead of its normal expiry.');

// Early takes used passGap for releases that could happen beside the wing.
unset($released['rearPassGap'],$released['driftPass']);
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
// The car patrol maneuvers on a faster clock while covering the same distance per tick as a player.
foreach([-.35,.35] as $climb) {
	$camera=new Camera; $camera->position=[0.,500.,0.];
	$player=new Jet; $player->board($camera,$land,-10.); $patrolJet=clone $player;
	for($tick=1;$tick<=180;$tick++) {
		$before=$patrolJet->state();
		$player->step(1/60.,0.,.55,$climb,$camera,$land,-10.);
		$patrolJet->fly(1/60.,0.,.55,$climb,$land,-10.,maneuverRate:2.);
		$p=$patrolJet->state(); $regular=$player->state();
		$distance=hypot(hypot($p['x']-$before['x'],$p['z']-$before['z']),$p['y']-$before['y']);
		expect($p['speed']===$regular['speed'] && $p['power']===$regular['power'] && abs($distance-$p['speed']/60.)<1e-8,
			'Patrol maneuvers spend the same airspeed and engine power as the player.');
		if($tick===1) {
			$firstRoll=$p['roll'];
			expect(abs($firstRoll)>1.9*abs($regular['roll']),'The CPU banks more sharply on the first tick of steering.');
		}
		if($tick===2) expect(abs($firstRoll-$regular['roll'])<1e-9,'One CPU roll tick gives two ticks of player roll response.');
	}
	$grade=$p['vy']/hypot($p['vx'],$p['vz']); $normalGrade=$regular['vy']/hypot($regular['vx'],$regular['vz']);
	expect(abs($grade/$normalGrade-2.)<.10,'A sustained patrol climb or descent takes about twice the player’s grade.');
	expect(abs($p['yaw']-$before['yaw']-2.*sin($p['roll'])*Jet::BANKED_TURN_RATE/60.)<1e-8,
		'At a given bank, the powered patrol turns at twice the normal rate.');
}

// Measure the aircraft the player sees, across several passes and in the car's own travel direction.
foreach([[28.,M_PI/2],[72.,0.],[106.,M_PI]] as [$speed,$heading]) {
	$pilot=new AirPatrol; $eye=new Camera; $eye->yaw=M_PI-$heading; $eye->fov=1.36;
	$f=sin($heading); $g=cos($heading);
	$car=['x'=>-18.*$speed*$f,'y'=>0.,'z'=>-18.*$speed*$g,'yaw'=>$heading,'drift'=>0.,'speed'=>$speed];
	$left=180.; $right=-180.; $lead=INF; $low=INF; $high=-INF; $crossings=[]; $side=0; $releases=[]; $reloaded=false;
	for($tick=0;$tick<36*60;$tick++) {
		$car['x']+=$speed*$f/60.; $car['z']+=$speed*$g/60.;
		// Include short chassis bumps during the middle-speed run on the same flat road.
		if($speed===72. && $tick>=15*60) $car['y']=2.*max(0.,sin(3.*$tick/60.));
		$eye->position=[$car['x']-7.*$f,$car['y']+3.,$car['z']-7.*$g];
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
		$low=min($low,$p['y']); $high=max($high,$p['y']);
		$left=min($left,$bearing); $right=max($right,$bearing); $lead=min($lead,$along);
		$newSide=$across<=>0.; if($side!==0 && $newSide!==$side) $crossings[]=$tick/60.;
		$side=$newSide;
	}
	expect($releases===[0,1] && $reloaded,'Both wings reload and fire in turn on the same patrol.');
	expect($lead>20. && $left<-55. && $left>-68. && $right>55. && $right<68.,
		"The F5 stays ahead and reaches both 10 and 2 o'clock at $speed m/s.");
	expect($high-$low<.25,"Banking across a level road at $speed m/s keeps a steady patrol altitude.");
	expect(count($crossings)>=3,'The pilot keeps alternating shoulders.');
	for($i=1;$i<count($crossings);$i++) expect(abs(($crossings[$i]-$crossings[$i-1])-5.24)<.4,
		'Each crossing gives the driver another predictable drift opportunity.');
}

// A spent wing reloads in ten seconds, then has to earn a new visible launch.
$reload=new SpeedSeeker;
$reload->resume(['phase'=>'reload','slot'=>0,'pose'=>null,'clock'=>9.9,'start'=>[],'passSpeed'=>0.]);
$reload->step(.05,$driver,null,'escort',$land,-10.);
expect($reload->state()['phase']==='reload','The spent wing stays empty until its cooldown ends.');
$reload->step(.06,$driver,null,'escort',$land,-10.);
expect($reload->state()['phase']==='mounted' && $reload->state()['slot']===1 && $reload->state()['clock']===0.,
	'The opposite wing is ready for a fresh warning beat ten seconds after impact.');

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
		// The normal 10-to-2 sweep reaches 72 m. A 90 m separation is clear of formation.
		if($wide>90. && abs($x*$g-$z*$f)<25. && $x*$f+$z*$g>25. && $range<62. && cos($p['yaw']-$car['yaw'])>.75) $rejoined=true;
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
expect($miss['pose']['pitch']>$plain['pose']['pitch'] && $miss['velocity'][1]>$plain['velocity'][1]+2. &&
	$miss['pose']['y']>$plain['pose']['y'] && $miss['passSpeed']>$driver['speed'],
	'The shaken prop lifts above the roof and overtakes the driver.');
$widePass=$pilot->snapshot(); $widePass['pose']['x']+=180.; // Keep the F5 out of retargeting range on both paths.
$pilot->resume($widePass); $other=new AirPatrol; $other->resume($widePass);
for($i=0;$i<12;$i++) {
	$pilot->step(1/60,$driver,$eyeAt,16/9,$land,-10.);
	$other->step(1/60,$displaced,$eyeAt,16/9,$land,-10.);
}
expect($pilot->seeker()['pose']===$other->seeker()['pose'] && $pilot->seeker()['pose']['z']>$miss['pose']['z'] &&
	abs($pilot->seeker()['pose']['y']-$miss['pose']['y']-.2*$miss['velocity'][1])<.0001,
	'The shaken prop holds its release course even when the driver changes direction.');
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

// A raised roadbed gives a reproducible canyon wall with a level approach, a steep face and a flat top.
$cut=new Landscape(['worldSize'=>512,'spacing'=>2.,'heightScale'=>0.,'detailAmplitude'=>0.,'waterLevel'=>-4.]);
$cut->queue(['type'=>'road','points'=>[[-200,40,10],[200,40,10]],'width'=>60,'shoulder'=>4,'crown'=>0.]); $cut->commit();
foreach([30,60,120] as $hz) foreach([[.83,0.],[.83,20.],[18.,0.],[85.,0.]] as [$height,$sideSpeed]) {
	$wreck=new Jet;
	$wreck->resumeFlight(array_replace($checkpoint['pose'],['x'=>-60.,'y'=>$height,'z'=>-56.,'vx'=>$sideSpeed,
		'vz'=>80.,'vy'=>0.,'speed'=>hypot(80.,$sideSpeed),'power'=>0.,'yaw'=>atan2($sideSpeed,80.),'pitch'=>0.,'roll'=>0.]));
	$grounded=$height===.83; $highest=$height;
	for($i=0;$i<12*$hz;$i++) {
		$grounded=$wreck->landUnpowered(1/$hz,$cut,-4.,$grounded);
		$p=$wreck->state(); $highest=max($highest,$p['y']);
		if($p['speed']===0.) break;
	}
	expect($highest<=$height+.02, "A canyon face cannot lift the wreck at $hz Hz.");
	if($height===85.) expect($p['z']>70.,'A gliding jet above the rim clears the canyon wall.');
	else {
		expect($p['z']<-25. && $p['y']<1. && $p['speed']===0. && $grounded,'A wall strike settles at the foot of the face.');
		expect($sideSpeed===0. ? abs($p['x']+60.)<.01 : $p['x']>-45., 'Only a glancing strike carries the wreck along the wall.');
	}
}
$cut->close();

// The first-light canyon has a narrow shelf which the nose can overlap while the belly is below it.
$canyon=new Session;
$canyon->input(['actions'=>[['type'=>'generate','preset'=>'canyon','seed'=>'first-light']]],1/60,16/9);
$ground=$canyon->landscape; $startY=$ground->height(-453.,1933.)+4.;
$wreck=new Jet;
$wreck->resumeFlight(array_replace($checkpoint['pose'],['x'=>-453.,'y'=>$startY,'z'=>1933.,'vx'=>-53.,
	'vz'=>53.,'vy'=>-1.,'speed'=>75.,'power'=>0.,'yaw'=>-M_PI/4,'pitch'=>0.,'roll'=>0.]));
$grounded=false; $peak=$startY;
for($i=0;$i<1200;$i++) {
	$grounded=$wreck->landUnpowered(1/60,$ground,$canyon->water->level(),$grounded);
	$p=$wreck->state(); $peak=max($peak,$p['y']);
	if($p['speed']===0.) break;
}
expect($peak<$startY+1. && $grounded && $p['speed']===0.,'The wreck settles below the canyon shelf without gaining altitude.');
$canyon->close();

// Drive over a raised hill with high ground across the pilot's approach. The same escort climbs and rejoins.
$pilot->resume($checkpoint); $car=$driver; $p=$pilot->pose();
$hillZ=$car['z']+180.;
$land->queue(['type'=>'raise','x'=>$car['x'],'z'=>$hillZ,'radius'=>145.,'amount'=>90.]);
$land->commit();
$peak=$p['y']; $carPeak=$car['y']; $approach=false; $joined=false; $climbing=false; $descending=false;
for($i=0;$i<9*60;$i++) {
	$car['z']+=$car['speed']/60.;
	$car['y']=$land->height($car['x'],$car['z']);
	$eye->position=[$car['x'],$car['y']+3.,$car['z']-7.]; $eye->yaw=M_PI;
	$pilot->step(1/60,$car,$eyeAt,16/9,$land,-10.);
	$state=$pilot->snapshot(); $new=$state['pose'];
	expect($state['phase']==='escort','The active patrol stays with the driver on both sides of the crest.');
	$approach=$approach || $state['flight'][5]>max($car['y']+23.,$p['y']+8.);
	if($new['vy']>1.) {
		expect(!$descending,'The pilot climbs once for the crest without a second pitch-up.');
		$climbing=true;
	}
	if($climbing && $new['vy']< -1.) $descending=true;
	$peak=max($peak,$new['y']); $carPeak=max($carPeak,$car['y']);
	if($car['z']>$hillZ+145. && $new['y']<$car['y']+26. && $new['z']>$car['z'] &&
		hypot($new['x']-$car['x'],$new['z']-$car['z'])<80.) $joined=true;
	$p=$new;
}
expect($approach && $descending && $carPeak>80. && $peak>$carPeak+8. && $joined,
	'The driver crests the hill, the pilot clears it and returns to formation on the far side.');
$land->close();
