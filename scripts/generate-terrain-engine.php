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

// Enemy Vulcan fire hitting the player in the F5.
// The bright crack leads the pressure thump by 3 ms. Its treble dies first, followed by the midrange and low roll.
$rate = 32000;
$duration = .42;
$frames = (int)round($duration*$rate);
$pcm = '';
$bright = $mid = $barkBand = $air = $bass = $rumble = $drift = $phase = 0.;
for($i = 0; $i < $frames; $i++) {
	$t = $i / $rate;
	$noise = 2.*$random() - 1.;
	$bright += .69 * ($noise - $bright);
	$mid += .24 * ($bright - $mid);
	$barkBand += .21 * ($mid - $barkBand);
	$air += .068 * ($barkBand - $air);
	// Differences between the filtered signals isolate the bands. Only the leading crack gets the brightest one.
	$crack = 1.3*pressurePulse($t,.00035,.008)*($bright - $mid);
	$bark = 3.1*pressurePulse($t,.0008,.025)*($barkBand - $air);

	$bandwidth = .012 + .031*exp(-$t/.060);
	$bass += $bandwidth * ($air - $bass);
	$rumble += $bandwidth * ($bass - $rumble);
	$drift += .0055 * ($rumble - $drift);

	$phase += 2.*M_PI*(47. + 24.*exp(-$t/.024))/$rate;
	$body = pressurePulse(max(0.,$t-.003),.0018,.062);
	$thump = $body * (.91*sin($phase) + .27*sin(2.2*$phase));
	$edge = .32*pressurePulse($t,.0008,.019)*sin(4.6*$phase);
	$roll = pressurePulse(max(0.,$t-.021),.004,.072) + .44*pressurePulse(max(0.,$t-.059),.007,.085);
	$tail = (2.2*pressurePulse($t,.002,.04) + 6.4*$roll) * ($rumble - $drift);
	$fade = min(1., ($duration-$t)/.02);
	$pcm .= pack('v',(int)round(30000*tanh($crack + $bark + $thump + $edge + $tail)*$fade));
}
writeMonoRecording($dir.'f5-enemy-vulcan-hit.wav',$rate,$pcm,$check);

// Projectile explosion. A broad band of turbulent bass rolls down in frequency while the brighter tearing sound
// breaks up and dies away. The landscape supplies the echoes at playback.
$duration = 2.8;
$frames = (int)round($duration*$rate);
$pcm = '';
$state = 6288.;
$noise = static function() use (&$state): float {
	$state = fmod(16807.*$state,2147483647.);
	return $state/1073741823.5 - 1.;
};

$low = $band = $drift = $grit = $mid = $midFloor = $flutter = 0.;
for($i = 0; $i < $frames; $i++) {
	$t = $i/$rate;
	// Broad, noise-driven resonance. The center moves from about 125 Hz toward 25 Hz.
	$tuning = .0048 + .020*exp(-$t/.66);
	$low += $tuning*$band;
	$band += $tuning*($noise() - $low - .94*$band);
	$drift += .0063*($low-$drift);
	$roar = (6.3*$band + 12.4*($low-$drift))*sqrt(.022/$tuning);

	$grit += .39*($noise()-$grit);
	$mid += .122*($grit-$mid);
	$midFloor += .025*($mid-$midFloor);
	$flutter += .0011*($noise()-$flutter);
	$tearing = (1.78*($mid-$midFloor) + (.54+1.03*pressurePulse($t,.18,.51))*exp(-$t/.85)*($grit-$mid))
		* max(.45, 1.+13.*$flutter);

	$late = max(0.,$t-1.23);
	$level = (1.-exp(-$t/.025))*(.89+.11*exp(-$t/.35))*exp(-.10*$t-.71*$late-.86*$late*$late);
	$fade = min(1.,($duration-$t)/.075);
	$pcm .= pack('v',(int)round(28800*$level*tanh(1.6*($roar+$tearing))*$fade));
}
writeMonoRecording($dir.'burst.wav',$rate,$pcm,$check);

function writeMonoRecording(string $file, int $rate, string $pcm, bool $check): void
{
	$format = pack('vvVVvv',1,1,$rate,$rate*2,2,16);
	$chunks = 'fmt '.pack('V',strlen($format)).$format.'data'.pack('V',strlen($pcm)).$pcm;
	writeRecording($file,'RIFF'.pack('V',strlen($chunks)+4).'WAVE'.$chunks,$check);
}
