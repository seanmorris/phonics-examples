<?php
declare(strict_types=1);

require __DIR__.'/terrain-audio-wav.php';

// A four-stroke flat twelve with a collector per bank. Pressure is sampled in crank angle, so the browser can follow
// the gearbox continuously at any audio device's sample rate. The exhaust pipes and muffler live in the browser's
// audio graph instead, where their resonances stay fixed in hertz as the revs change.
const CYCLES = 64, SAMPLES_PER_CYCLE = 512, SAMPLE_RATE = 32768;
// Blowdown: pressure builds over a couple of degrees as the exhaust valve opens, then the cylinder empties over about
// seven. The rounded pulse keeps the note full.
const RISE = 3., DECAY = 10., TAIL = 96;
// Cylinders 0–11 fire 60° apart and alternate banks. Each has its own charge and primary pipe length, so the
// firings differ in strength and arrival time. Within a bank, cylinders alternate richer and leaner, so every other
// discharge is stronger: that growl sits an octave below the bank's note. Both banks carry the same total charge.
$charge = [1.15, 1.12, .85, .88, 1.13, 1.15, .87, .85, 1.14, 1.13, .86, .87];
$primary = [.0, 1.6, -1.1, .7, 1.9, -.4, -1.5, .3, .9, -1.8, -.6, 1.2];
$length = CYCLES*SAMPLES_PER_CYCLE;
$tracks = array_fill(0,2,array_fill(0,$length,0.));
$seed = 17493;
$random = static function() use (&$seed): float {
	$seed = (25173*$seed + 13849) & 65535;
	return $seed/65536.;
};

for($cycle = 0; $cycle < CYCLES; $cycle++)
{
	foreach($charge as $i => $trim)
	{
		// Combustion varies a little from one cycle to the next. Firings sit mid-slot, so the loop joins between them.
		$at = ($cycle + ($i + .5)/12.)*SAMPLES_PER_CYCLE + $primary[$i] + .8*($random()-.5);
		$pressure = $trim*(.94 + .12*$random());
		$bank = $i % 2;
		$first = (int)ceil($at);
		for($j = 0; $j < TAIL; $j++)
		{
			$age = $first + $j - $at;
			// Wrap the last firing's tail into the start of the next pass.
			$index = ($first + $j + $length) % $length;
			$pulse = (1. - exp(-$age/RISE))*exp(-$age/DECAY);
			$tracks[$bank][$index] += .38*$pressure*$pulse;
		}
	}
}

// Each side of the stereo recording carries the pressure from one collector.
$means = array_map(static fn(array $track): float => array_sum($track)/$length, $tracks);
$pcm = '';
for($i=0; $i<$length; $i++)
{
	foreach($tracks as $c => $track)
	{
		$value = $track[$i]-$means[$c];
		if(abs($value) > 1.) throw new RuntimeException('Pressure exceeds PCM headroom.');
		$pcm .= pack('v',(int)round(32767.*$value));
	}
}
writeTerrainPcm('flat-twelve.wav',2,SAMPLE_RATE,$pcm,in_array('--check',$argv,true));
