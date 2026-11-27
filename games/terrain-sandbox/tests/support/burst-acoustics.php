<?php
use TerrainPhonics\Camera;
use TerrainPhonics\Landscape;
use TerrainSandbox\BurstAcoustics;
use TerrainSandbox\FlightTrails;

(function() {
	$world=new Landscape(['worldSize'=>8192,'heightScale'=>0,'detailAmplitude'=>0]);
	$ear=new Camera;
	$ear->position=[0,2,0];
	$source=new FlightTrails;
	$acoustics=new BurstAcoustics;
	$taps=$modes=[];
	$mode=static function(bool $enabled) use (&$modes) { $modes[]=$enabled; };
	$play=static function($id,$slot,$fresh,...$mix) use (&$taps) { $taps[]=[$id,...$mix]; return false; };
	$frame=static fn() => $acoustics->frame($source,$world,$ear,-50.,$mode,$play,1/60);
	$source->burst([0,2,-34.3],0.);
	for($i=0;$i<100;$i++) $frame();
	expect(count($taps)===1 && $modes===[true],'Flat terrain gives one report across redraws.');
	expect($taps[0][5] && abs($taps[0][1]-.1)<.0001 && abs($taps[0][4])<.001,'A centered burst travels to the camera at the speed of sound.');

	$world->queue(['type'=>'raise','x'=>224,'z'=>-34,'radius'=>190,'amount'=>100]);
	$world->commit();
	$source->burst([0,2,-34.3],0.);
	for($i=0;$i<100;$i++) $frame();
	expect(count($taps)===3 && $taps[1][0]===$taps[2][0], 'A new nearby face contributes one distinct return to that burst.');
	expect(!$taps[2][5] && $taps[2][1]>$taps[1][1] && $taps[2][2]<$taps[1][2] && $taps[2][3]<$taps[1][3] && $taps[2][4]>.7,
		'The right-hand wall returns a later, quieter, darker sound from the right.');
	$world->queue(['type'=>'raise','x'=>-2560,'z'=>-34,'radius'=>400,'amount'=>360]);
	$world->commit();
	$source->burst([0,2,-34.3],0.);
	for($i=0;$i<100;$i++) $frame();
	$far=array_filter($taps,static fn($tap) => $tap[0]===3 && $tap[1]>10. && $tap[4]<-.9);
	expect(count($far)===1,'A distant ridge can send a late return from more than two thousand yards away.');
	$latest=[];
	$track=static function($id,$slot,$fresh,...$mix) use (&$latest) { $latest[$slot]=[$fresh,...$mix]; return true; };
	$source->burst([0,0,0],0.);
	for($i=0;$i<100;$i++) $acoustics->frame($source,$world,$ear,-50.,$mode,$track,1/60);
	$before=$latest;
	$ear->yaw=M_PI;
	$acoustics->frame($source,$world,$ear,-50.,$mode,$track,1/60);
	expect(count($latest)>1,'At least one terrain reflection remains tracked alongside the direct sound.');
	foreach($latest as $slot=>$arrival) {
		expect(!$arrival[0] && abs($arrival[4]+$before[$slot][4])<.001 && $arrival[2]===$before[$slot][2],
			'Turning in place reverses the bearing of every active arrival while preserving its range.');
	}
	$source->burst([4095,20,4095],0.);
	$frame();
	$acoustics->frame(null,$world,$ear,-50.,$mode,$play,1/60);
	expect($modes===[true,false], 'Leaving cancels sounds and outstanding terrain surveys at the map boundary.');
	$source->burst([0,0,0],0.);
	$source->burst([2,0,0],0.);
	expect(count($source->takeBursts())===2 && !$source->takeBursts(),'Separate impacts in the same step each have one sound.');
})();

(function() {
	$world=new Landscape(['worldSize'=>1024,'heightScale'=>0,'detailAmplitude'=>0]);
	$source=new FlightTrails;
	$ear=new Camera;
	$ear->position=[0.,2.,0.];
	$acoustics=new BurstAcoustics;
	$voices=[]; $playing=true;
	$play=static function($id,$slot,$fresh,...$mix) use (&$voices,&$playing) {
		$voices[] = [$id,$slot,$fresh,...$mix];
		return $playing;
	};
	$frame=static fn()=>$acoustics->frame($source,$world,$ear,-5.,static fn($enabled)=>null,$play,.05);
	$source->burst([60.,2.,0.],0.);
	$frame();
	[$id,$slot,$fresh,,$gain,$cutoff,$bearing,,$pitch]=$voices[0];
	expect($fresh && $bearing===1. && $pitch===1.,'A stationary source on the right starts at its nominal pitch.');
	$ear->position[0]=3.;
	$frame();
	[$id2,$slot2,$fresh,,$nearGain,$nearCutoff,$side,,$pitch]=$voices[1];
	expect(!$fresh && $id2===$id && $slot2===$slot && $side===$bearing && $nearGain>$gain && $nearCutoff>$cutoff
		&& abs($pitch-(1.+60./343.))<.00001,'Approaching revises the same voice: brighter, louder, higher and on the same side.');
	$ear->position[0]=0.; $ear->yaw=M_PI;
	$frame();
	expect($voices[2][6]<-.99 && $voices[2][8]<1.,'Turning and moving away switches ears and drops the pitch.');
	$ear->yaw=M_PI/2; $frame();
	expect(abs($voices[3][6])<.001 && $voices[3][8]===1.,'Turning in place centers the source without a Doppler shift.');
	$ear->position[0]=260.; $frame();
	expect($voices[4][8]===1.,'A camera cut updates location immediately without a false pitch shift.');
	$playing=false; $frame(); $count=count($voices); $frame();
	expect(count($voices)===$count,'An ended arrival stops sending mixer updates.');
})();

(function() {
	$land=new Landscape(['worldSize'=>2048,'heightScale'=>0,'detailAmplitude'=>0]);
	$ear=new Camera;
	$ear->position=[0.,2.,0.];
	$source=new FlightTrails;
	$acoustics=new BurstAcoustics;
	$levels=[];
	$play=static function($id,$slot,$fresh,$delay,$gain,$cutoff) use (&$levels) {
		if($fresh && $slot===0) $levels[]=[$gain,$cutoff];
		return false;
	};
	foreach([10.,250.,500.] as $meters) {
		$source->burst([0.,2.,-$meters],0.);
		$acoustics->frame($source,$land,$ear,-5.,static fn($enabled)=>null,$play,1/60);
	}
	[[$near,$clear],[$middle,$muffled],[$far,$dull]]=$levels;
	expect($near>.9 && $clear>11000.,'A nearby impact retains its level and the crack at the top of the spectrum.');
	expect($middle<.2*$near && $muffled<2000.,'At 250 meters the report recedes and the sharp crack is muffled.');
	expect($far<.6*$middle && $dull<1000.,'At 500 meters the quieter bass tail carries farther than the crack.');
})();
