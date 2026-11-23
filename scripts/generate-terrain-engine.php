<?php
declare(strict_types=1);

// A four-stroke flat twelve with separate left and right collectors. Sample pressure in crank
// angle so the browser can follow the gearbox continuously at any audio device's sample rate.
const CYCLES = 64, SAMPLES_PER_CYCLE = 512, SAMPLE_RATE = 32768;
$cylinders = [1.00, .94, .92, 1.02, .98, .89, .88, .97, 1.04, .92, .95, 1.00];
$length = CYCLES*SAMPLES_PER_CYCLE;
$tracks = array_fill(0,3,array_fill(0,$length,0.));
$seed = 17493;
$random = static function() use (&$seed): float {
	$seed = (25173*$seed + 13849) & 65535;
	return $seed/65536.;
};

for($cycle = 0; $cycle < CYCLES; $cycle++)
{
	foreach($cylinders as $i => $trim)
	{
		// Each firing has its own pressure, valve timing and discharge duration.
		$at = ($cycle + $i/12.)*SAMPLES_PER_CYCLE + 3.*($random()-.5);
		$pressure = $trim*(.79 + .42*$random());
		$duration = 6.1 + 5.3*$random();
		$lever = .66*cos($i*M_PI/3.) + .34*cos($i*M_PI/6. + .8);
		$bank = $i % 2;
		$first = (int)ceil($at);
		for($j = 0; $j < 80; $j++)
		{
			$age = ($first + $j - $at)/$duration;
			$flow = $pressure*$age*exp(1.-$age);
			// Wrap the last firing's tail into the start of the next pass.
			$index = ($first + $j + $length) % $length;
			$tracks[$bank][$index] += .63*$flow*(1.85 - .72*$age);
			// The force reaching the mounts depends on where the crank is during the same firing.
			$tracks[2][$index] += .70*$lever*$flow;
		}
	}
}

// Four discrete PCM channels: both collectors, mount excitation, and independently played flow noise.
$means = array_map(static fn(array $track): float => array_sum($track)/$length,$tracks);
$pcm = '';
for($i=0; $i<$length; $i++)
{
	foreach($tracks as $c => $track)
	{
		$value = $track[$i]-$means[$c];
		if(abs($value) > 1.) throw new RuntimeException('Pressure exceeds PCM headroom.');
		$pcm .= pack('v',(int)round(32767.*$value));
	}
	$pcm .= pack('v',(int)round(32767.*(2.*$random()-1.)));
}

// WAVE_FORMAT_EXTENSIBLE, quad, signed 16-bit PCM.
$format = pack('vvVVvvvvV',0xfffe,4,SAMPLE_RATE,SAMPLE_RATE*8,8,16,22,16,0x33)
	. hex2bin('0100000000001000800000aa00389b71');
$chunks = 'fmt '.pack('V',strlen($format)).$format.'data'.pack('V',strlen($pcm)).$pcm;
$wav = 'RIFF'.pack('V',strlen($chunks)+4).'WAVE'.$chunks;
$file = dirname(__DIR__).'/games/terrain-sandbox/assets/car-audio/flat-twelve.wav';
if(in_array('--check',$argv,true))
{
	if(!is_file($file) || file_get_contents($file) !== $wav)
	{
		fwrite(STDERR,"Regenerate the terrain engine with php scripts/generate-terrain-engine.php.\n");
		exit(1);
	}
}
elseif(file_put_contents($file,$wav) !== strlen($wav)) throw new RuntimeException("Could not write $file");
