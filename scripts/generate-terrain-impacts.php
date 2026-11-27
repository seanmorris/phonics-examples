<?php
declare(strict_types=1);

require __DIR__.'/terrain-audio-wav.php';

function pressurePulse(float $age, float $rise, float $decay): float
{
	return (1. - exp(-$age/$rise))*exp(-$age/$decay);
}

// Enemy Vulcan fire hitting the player in the F5.
// The bright crack leads the pressure thump by 3 ms. Its treble dies first, followed by the midrange and low roll.
$rate = 32000;
$check = in_array('--check',$argv,true);
$seed = 58965;
$random = static function() use (&$seed): float {
	$seed = (25173*$seed + 13849) & 65535;
	return $seed/65536.;
};
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
writeTerrainPcm('f5-enemy-vulcan-hit.wav',1,$rate,$pcm,$check);

// Projectile explosion. A broad band of turbulent bass rolls down in frequency while the brighter tearing sound
// breaks up and dies away. The landscape supplies the echoes at playback.
$duration = .72;
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
	$tuning = .0048 + .020*exp(-$t/.16);
	$low += $tuning*$band;
	$band += $tuning*($noise() - $low - .94*$band);
	$drift += .0063*($low-$drift);
	$roar = (6.3*$band + 12.4*($low-$drift))*sqrt(.022/$tuning);

	$grit += .39*($noise()-$grit);
	$mid += .122*($grit-$mid);
	$midFloor += .025*($mid-$midFloor);
	$flutter += .0011*($noise()-$flutter);
	$tearing = (1.78*($mid-$midFloor) + (.46+1.45*exp(-$t/.045))*exp(-$t/.22)*($grit-$mid))
		* max(.45, 1.+13.*$flutter);

	// A fast pressure front gives way to a short body and a quieter bass tail.
	$level = (1.-exp(-$t/.002))*(.78*exp(-$t/.15)+.22*exp(-$t/.32));
	$fade = min(1.,($duration-$t)/.04);
	$pcm .= pack('v',(int)round(28800*$level*tanh(1.6*($roar+$tearing))*$fade));
}
writeTerrainPcm('burst.wav',1,$rate,$pcm,$check);
