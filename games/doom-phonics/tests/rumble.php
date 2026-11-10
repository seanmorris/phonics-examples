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
$maximum = new Rumble;
$maximum->damage(220);
$maximumDamage = $maximum->update(0)[0];
$middle = new Rumble;
$middle->damage(110);
$middleDamage = $middle->update(0)[0];
$clamped = new Rumble;
$clamped->damage(880);
$clampedDamage = $clamped->update(0)[0];

verifyWad(
	$minimumDamage === ['duration' => 65,'strong' => .2,'weak' => .35]
		&& $middleDamage === ['duration' => 123,'strong' => .575,'weak' => .625]
		&& $maximumDamage === ['duration' => 180,'strong' => .95,'weak' => .9]
		&& $clampedDamage === $maximumDamage
	, 'damage feedback scales with knockback between perceptible and bounded motor limits'
);

$fatal = new Rumble;
$fatal->damage(55, true);
$fatalStart = $fatal->update(0);
$fatalTail = $fatal->update(.18);
verifyWad(
	count($fatalStart) === 1 && $fatalStart[0]['strong'] > .2
		&& $fatalTail === [['duration' => 220,'strong' => .5375,'weak' => .2]]
	, 'fatal damage adds a low-frequency collapse after the impact'
);

$pickup = new Rumble;
$pickup->pickup();
verifyWad(
	$pickup->update(0) === [['duration' => 35,'strong' => 0.0,'weak' => .25]]
	, 'ordinary pickups use a restrained high-frequency tick'
);

$pickup->pickup(true);
verifyWad(
	$pickup->update(0) === [['duration' => 35,'strong' => 0.0,'weak' => .55]]
		&& $pickup->update(.035) === [['duration' => 75,'strong' => .35,'weak' => .2]]
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
