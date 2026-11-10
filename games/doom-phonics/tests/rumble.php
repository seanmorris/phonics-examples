<?php
declare(strict_types=1);

use DoomPhonics\Effects\Rumble;

$rumble = new Rumble;
$rumble->weapon('Pistol');
verifyWad(
	$rumble->update(0) === [['duration' => 55,'strong' => .18,'weak' => .72]]
	, 'pistol fire is a short high-frequency kick'
);

$rumble->weapon('Shotgun');
verifyWad(
	$rumble->update(0) === [['duration' => 110,'strong' => .78,'weak' => .48]]
		&& $rumble->update(.339) === []
		&& $rumble->update(.001) === [['duration' => 60,'strong' => .28,'weak' => .36]]
	, 'shotgun fire separates the blast from its pump catch'
);

$rumble->weapon('BFG9000');
verifyWad(
	$rumble->update(0) === [['duration' => 90,'strong' => .15,'weak' => .45]]
		&& $rumble->update(.18) === [['duration' => 120,'strong' => .4,'weak' => .6]]
		&& $rumble->update(.3) === [['duration' => 160,'strong' => 1.0,'weak' => .8]]
	, 'BFG fire builds through staged charge and discharge pulses'
);

$minimum = new Rumble;
$minimum->damage(0);
$minimumDamage = $minimum->update(0)[0];
$bullet = new Rumble;
$bullet->damage(13.125);
$bulletDamage = $bullet->update(0)[0];
$maximumBullet = new Rumble;
$maximumBullet->damage(65.625);
$maximumBulletDamage = $maximumBullet->update(0)[0];
$replicatedBlast = new Rumble;
$replicatedBlast->damage(70);
$replicatedBlastDamage = $replicatedBlast->update(0)[0];
$clamped = new Rumble;
$clamped->damage(880);
$clampedDamage = $clamped->update(0)[0];

verifyWad(
	$minimumDamage === ['duration' => 90,'strong' => .65,'weak' => .7]
		&& $bulletDamage['duration'] === 96
		&& $bulletDamage['strong'] >= .7
		&& $bulletDamage['weak'] >= .75
		&& $maximumBulletDamage['duration'] === 118
		&& $maximumBulletDamage['strong'] > $bulletDamage['strong']
		&& $maximumBulletDamage['weak'] > $bulletDamage['weak']
		&& $replicatedBlastDamage['duration'] === 170
		&& $clampedDamage === ['duration' => 210,'strong' => 1.0,'weak' => .98]
	, 'bullet damage stays sharp and bounded while replicated explosion knockback selects the blast envelope'
);

$blast = new Rumble;
$blast->blast(140);
$blastDamage = $blast->update(0)[0];
$blastBeforeTail = $blast->update(.209);
$blastTail = $blast->update(.001);
$blastFade = $blast->update(.18);
verifyWad(
	$blastDamage === ['duration' => 210,'strong' => 1.0,'weak' => .98]
		&& $blastBeforeTail === []
		&& $blastTail[0]['duration'] === 180
		&& abs($blastTail[0]['strong'] - .85) < 1e-12
		&& $blastTail[0]['weak'] === .35
		&& $blastFade[0]['duration'] === 150
		&& abs($blastFade[0]['strong'] - .45) < 1e-12
		&& $blastFade[0]['weak'] === .08
	, 'explosion knockback decays through a long two-stage aftershock'
);

$fatal = new Rumble;
$fatal->damage(17.5, true);
$fatalStart = $fatal->update(0);
$fatalTail = $fatal->update(.1);
verifyWad(
	count($fatalStart) === 1 && $fatalStart[0]['strong'] > .2
		&& $fatalTail === [['duration' => 220,'strong' => .5375,'weak' => .2]]
	, 'fatal damage adds a low-frequency collapse after the impact'
);

$hazard = new Rumble;
$hazard->hazard();
verifyWad(
	$hazard->update(0) === [['duration' => 80,'strong' => .72,'weak' => .12]]
		&& $hazard->update(.139) === []
		&& $hazard->update(.001) === [['duration' => 105,'strong' => .5,'weak' => .08]]
	, 'environmental damage beats twice like a heart'
);

$shortFall = new Rumble;
$shortFall->landing(70);
$shortLanding = $shortFall->update(0)[0];
$mediumFall = new Rumble;
$mediumFall->landing(280);
$mediumLanding = $mediumFall->update(0)[0];
$longFall = new Rumble;
$longFall->landing(1120);
$longLanding = $longFall->update(0)[0];
verifyWad(
	$shortLanding['duration'] === 56
		&& $mediumLanding['duration'] === 88
		&& $longLanding === ['duration' => 130,'strong' => .95,'weak' => .6]
		&& $shortLanding['strong'] < $mediumLanding['strong']
		&& $mediumLanding['strong'] < $longLanding['strong']
		&& $shortLanding['weak'] < $mediumLanding['weak']
		&& $mediumLanding['weak'] < $longLanding['weak']
	, 'landing feedback scales with downward speed and clamps severe falls'
);

$pickup = new Rumble;
$pickup->pickup();
verifyWad(
	$pickup->update(0) === [['duration' => 90,'strong' => .3,'weak' => .55]]
	, 'ordinary pickups crossfeed both motors long enough for legacy controllers'
);

$pickup->pickup(true);
verifyWad(
	$pickup->update(0) === [['duration' => 90,'strong' => .3,'weak' => .72]]
		&& $pickup->update(.099) === []
		&& $pickup->update(.001) === [['duration' => 105,'strong' => .52,'weak' => .32]]
	, 'weapon pickups add a low-frequency catch after the item tick'
);

$rumble->preview();
verifyWad(
	$rumble->pending() === 1
		&& $rumble->update(0) === [['duration' => 120,'strong' => 1.0,'weak' => 1.0]]
	, 'settings preview replaces delayed gameplay feedback with the literal slider level'
);

foreach([-1, INF, NAN] as $invalid)
{
	try
	{
		(new Rumble)->damage($invalid);
		verifyWad(false, 'invalid damage knockback rejected');
	}
	catch(InvalidArgumentException)
	{
		verifyWad(true, 'invalid damage knockback rejected');
	}
}
