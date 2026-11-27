<?php
declare(strict_types=1);

// A four-stroke flat twelve with a collector per bank. Pressure is sampled in crank angle, so the browser can follow
// the gearbox continuously at any audio device's sample rate. The exhaust pipes and muffler live in the browser's
// audio graph instead, where their resonances stay fixed in hertz as the revs change.
const CYCLES = 64, SAMPLES_PER_CYCLE = 512, SAMPLE_RATE = 32768;
// Blowdown: pressure builds over a couple of degrees as the exhaust valve opens, then the cylinder empties over about
// seven. The rounded pulse keeps the note full; the browser adds the tailpipe rasp as noise.
function pressurePulse(float $age, float $rise, float $decay): float
{
	return (1. - exp(-$age/$rise))*exp(-$age/$decay);
}

const RISE = 3., DECAY = 10., TAIL = 96;
// Cylinders 0–11 fire 60° apart and alternate banks. Each has its own charge and primary pipe length, so the
// firings differ in strength and arrival time. Within a bank, cylinders alternate richer and leaner, so every other
// discharge is stronger: that growl sits an octave below the bank's note. Both banks carry the same total charge.
$charge = [1.15, 1.12, .85, .88, 1.13, 1.15, .87, .85, 1.14, 1.13, .86, .87];
$primary = [.0, 1.6, -1.1, .7, 1.9, -.4, -1.5, .3, .9, -1.8, -.6, 1.2];
$length = CYCLES*SAMPLES_PER_CYCLE;
$tracks = array_fill(0,3,array_fill(0,$length,0.));
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
			$tracks[$bank][$index] += .38*$pressure*pressurePulse($age,RISE,DECAY);
			// Gas speed through the collectors. The browser gates turbulent noise with it.
			$tracks[2][$index] += .22*$pressure*exp(-$age/(2.*DECAY));
		}
	}
}

// Four discrete PCM channels: both collectors, collector flow, and independently played noise.
// Pressure is heard relative to its mean; flow is a speed and keeps its sign.
$means = [array_sum($tracks[0])/$length, array_sum($tracks[1])/$length, 0.];
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
$dir = dirname(__DIR__).'/games/terrain-sandbox/assets/car-audio/';
function writeRecording(string $file, string $wav, bool $check): void
{
	if($check) {
		if(!is_file($file) || file_get_contents($file) !== $wav)
			throw new RuntimeException("Regenerate $file with php scripts/generate-terrain-engine.php");
	} elseif(file_put_contents($file,$wav) !== strlen($wav)) throw new RuntimeException("Could not write $file");
}
$check = in_array('--check',$argv,true);
writeRecording($dir.'flat-twelve.wav',$wav,$check);

// A short pressure front, a low body and a ragged air tail. The same pressure envelope drives each exhaust firing.
// Bake the dry sound once. Each landscape supplies its own reflection times and directions during play.
$rate=32000;
$pcm='';
$low=$mid=$slow=0.;
$phase=0.;
for($i=0; $i<$rate; $i++) {
	$t=$i/$rate;
	$n=2.*$random()-1.;
	$low+=.018*($n-$low);
	$mid+=.19*($n-$mid);
	$slow+=.0022*($n-$slow);
	$phase+=2*M_PI*(43.+42.*exp(-$t/.046))/$rate;
	$front=pressurePulse($t,.0008,.022);
	$body=pressurePulse($t,.005,.12);
	$tail=pressurePulse($t,.017,.17);
	$value=$front*(.49*$n+.34*$mid) + $body*(.45*sin($phase)+2.6*($low-$slow))
		+ $tail*(1.3*($mid-$low)+2.2*$slow);
	$fade=min(1.,(1.-$t)/.04);
	$pcm.=pack('v',(int)round(30000*tanh($value)*$fade));
}
$format=pack('vvVVvv',1,1,$rate,$rate*2,2,16);
$chunks='fmt '.pack('V',strlen($format)).$format.'data'.pack('V',strlen($pcm)).$pcm;
writeRecording($dir.'burst.wav','RIFF'.pack('V',strlen($chunks)+4).'WAVE'.$chunks,$check);
