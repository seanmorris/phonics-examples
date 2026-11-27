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
	$play=static function(...$tap) use (&$taps) { $taps[]=$tap; };
	$frame=static fn() => $acoustics->frame($source,$world,$ear,-50.,$mode,$play);
	$source->burst([0,2,-34.3],0.);
	for($i=0;$i<100;$i++) $frame();
	expect(count($taps)===1 && $modes===[true],'Flat terrain gives one report across redraws.');
	expect(abs($taps[0][1]-.1)<.0001 && abs($taps[0][4])<.001,'A centered burst travels to the camera at the speed of sound.');

	$world->queue(['type'=>'raise','x'=>224,'z'=>-34,'radius'=>190,'amount'=>100]);
	$world->commit();
	$source->burst([0,2,-34.3],0.);
	for($i=0;$i<100;$i++) $frame();
	expect(count($taps)===3 && $taps[1][0]===$taps[2][0], 'A new nearby face contributes one distinct return to that burst.');
	expect($taps[2][1]>$taps[1][1] && $taps[2][2]<$taps[1][2] && $taps[2][3]<$taps[1][3] && $taps[2][4]>.7,
		'The right-hand wall returns a later, quieter, darker sound from the right.');
	$world->queue(['type'=>'raise','x'=>-2560,'z'=>-34,'radius'=>400,'amount'=>360]);
	$world->commit();
	$source->burst([0,2,-34.3],0.);
	for($i=0;$i<100;$i++) $frame();
	$far=array_filter($taps,static fn($tap) => $tap[0]===3 && $tap[1]>10. && $tap[4]<-.9);
	expect(count($far)===1,'A distant ridge can send a late return from more than two thousand yards away.');
	$source->burst([4095,20,4095],0.);
	$frame();
	$acoustics->frame(null,$world,$ear,-50.,$mode,$play);
	expect($modes===[true,false], 'Leaving cancels sounds and outstanding terrain surveys at the map boundary.');
	$source->burst([0,0,0],0.);
	$source->burst([2,0,0],0.);
	expect(count($source->takeBursts())===2 && !$source->takeBursts(),'Separate impacts in the same step each have one sound.');
})();
