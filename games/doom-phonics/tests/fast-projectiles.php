<?php
declare(strict_types=1);

use DoomPhonics\Adapter\WadSource;
use DoomPhonics\Archive\Archive;
use DoomPhonics\Archive\Stack;
use DoomPhonics\Component\ActorState;
use DoomPhonics\Component\PlayerStart;
use DoomPhonics\Component\Projectile;
use DoomPhonics\Component\Transform;
use DoomPhonics\Component\Velocity;
use DoomPhonics\Definition\StateFrame;
use DoomPhonics\Game\GameSettings;
use DoomPhonics\System\CombatSystem;
use DoomPhonics\System\EpisodeSystems;
use DoomPhonics\Tests\PortMap;
use Phonics\Ecs\Registry;
use Phonics\Ecs\Scheduler;
use Phonics\Input\Controls;
use Phonics\World\ScopeContext;

require_once __DIR__ . '/fixtures/PortMap.php';

// A FastProjectile is seen by the trail it leaves at every step (its MissileType), as AFastProjectile::Effect spawns
// it; the Omega pack's beams have no sprite of their own. Their trails and fragments come from A_SpawnItemEx and
// A_CustomMissile.
(static function(): void {
	$addon = PortMap::archive(['DECORATE' => <<<'DECORATE'
ACTOR TrailBit
{
  +NOINTERACTION
  +NOGRAVITY
  States
  {
  Spawn:
    PLSE A 4
    Stop
  }
}
ACTOR FastShot : FastProjectile
{
  Speed 50
  Radius 8
  Height 8
  Damage 1
  MissileType "TrailBit"
  MissileHeight 8
  States
  {
  Spawn:
    PLSS A 1
    Loop
  Death:
    PLSE A 2
    Stop
  }
}
ACTOR SlowShot : FastShot
{
  Speed 10
  MissileType "TrailBit"
}
DECORATE
	]);
	$source = new WadSource(new Stack(new Archive(file_get_contents(dirname(__DIR__) . '/demo/DOOM1.WAD'), 'DOOM1.WAD'), $addon));
	$registry = new Registry;
	$scheduler = new Scheduler($registry);
	$descriptor = null;

	foreach($source->catalog() as $candidate) if($candidate->id === 'E1M1') $descriptor = $candidate;

	$scope = new ScopeContext('episode', $descriptor, $registry, $scheduler, services: ['controls' => new Controls, 'doom-settings' => new GameSettings]);
	$scope->setContent($source->activate('E1M1', $scope)->content());
	(new EpisodeSystems)->activate($scope);
	$actors = $scope->content()->actors;
	$combat = null;

	foreach((new ReflectionProperty($scheduler, 'systems'))->getValue($scheduler) as [$callable])
	if(is_array($callable) && $callable[0] instanceof CombatSystem) $combat = $callable[0];

	foreach($registry->query(PlayerStart::class) as $player) break;

	for($i = 0; $i < 3; $i++) $scheduler->update(1 / 35);

	$pose = $registry->get($player, Transform::class);
	$trailBits = static function() use ($registry): array {
		$found = [];

		foreach($registry->query(ActorState::class) as $entity)
		if($registry->get($entity, ActorState::class)->definition->name === 'TrailBit') $found[] = $entity;

		return $found;
	};
	$spawn = new ReflectionMethod($combat, 'spawnProjectile');
	// Fire straight along the player's facing from chest height, away from anything close.
	$startX = $pose->x + 40 * cos($pose->angle);
	$startY = $pose->y + 40 * sin($pose->angle);
	$startZ = $pose->z + 32;
	$shot = $spawn->invoke($combat, $registry, $player, 'FastShot', $startX, $startY, $startZ, $pose->angle, null, 0.0);
	verifyWad($shot !== null && !$trailBits(), 'firing a FastProjectile spawns no trail until it moves');
	$scheduler->update(1 / 35);
	$bits = $trailBits();
	$shotPose = $registry->get($shot, Transform::class);
	$travelled = hypot($shotPose->x - $startX, $shotPose->y - $startY);
	verifyWad(count($bits) === 8 && abs($travelled - 50) < .5, 'a 50-unit step by an 8-radius FastProjectile lays eight trail actors, as Doom takes eight sub-steps');
	$along = [];

	foreach($bits as $bit)
	{
		$bitPose = $registry->get($bit, Transform::class);
		$along[] = round(hypot($bitPose->x - $startX, $bitPose->y - $startY), 3);
		$off = abs(($bitPose->x - $startX) * sin($pose->angle) - ($bitPose->y - $startY) * cos($pose->angle));
		verifyWad($off < 1e-6 && abs($bitPose->z - $startZ) < 1e-6 && abs($bitPose->angle - $pose->angle) < 1e-9, 'each trail actor lies on the path, 8 below the shot plus MissileHeight, facing its way');
	}

	sort($along);
	verifyWad($along === [6.25, 12.5, 18.75, 25.0, 31.25, 37.5, 43.75, 50.0], 'the trail is spaced evenly along the step, ending where the shot is');

	for($i = 0; $i < 6; $i++) $scheduler->update(1 / 35);

	$remaining = count($trailBits());
	verifyWad($remaining >= 8 && $remaining < 56, 'trail actors retire when their four-tic state ends, so part of seven tics of trail remains');
	$registry->destroy($shot);
	$slow = $spawn->invoke($combat, $registry, $player, 'SlowShot', $startX, $startY, $startZ, $pose->angle, null, 0.0);
	$before = count($trailBits());
	$scheduler->update(1 / 35);
	verifyWad($registry->alive($slow) && count($trailBits()) - $before + 8 >= 8, 'a slower FastProjectile still takes Doom\'s minimum of eight sub-steps a tic');
	$registry->destroy($slow);

	for($i = 0; $i < 6; $i++) $scheduler->update(1 / 35);

	verifyWad(!$trailBits(), 'the trail is gone once nothing lays it');

	// A_SpawnItemEx offsets in the caller's frame: forward along its facing, positive y to its right.
	$dispatch = static fn(string $action, array $arguments) => $combat->actions->dispatch($player, new StateFrame('TNT1', 'A', 0, false, $action, $arguments), $registry);
	$dispatch('A_SpawnItemEx', ['TrailBit', 16, 8, 4, 0, 0, 0, 0, 0, 0]);
	[$bit] = $trailBits();
	$bitPose = $registry->get($bit, Transform::class);
	$expectedX = $pose->x + 16 * cos($pose->angle) + 8 * sin($pose->angle);
	$expectedY = $pose->y + 16 * sin($pose->angle) - 8 * cos($pose->angle);
	verifyWad(abs($bitPose->x - $expectedX) < 1e-6 && abs($bitPose->y - $expectedY) < 1e-6 && abs($bitPose->z - $pose->z - 4) < 1e-6, 'A_SpawnItemEx places the actor forward and to the right of the caller, raised by zofs');
	$registry->destroy($bit);
	$dispatch('A_SpawnItemEx', ['TrailBit', 16, 8, 0, 2, 1, 0, 90, 4, 0]);
	[$bit] = $trailBits();
	$bitPose = $registry->get($bit, Transform::class);
	$bitVelocity = $registry->get($bit, Velocity::class);
	verifyWad(
		abs($bitPose->x - $pose->x - 16) < 1e-6 && abs($bitPose->y - $pose->y - 8) < 1e-6
			&& abs($bitVelocity->x - (2 * cos($pose->angle) + 1 * sin($pose->angle)) * 35) < 1e-6
			&& abs(fmod($bitPose->angle - $pose->angle - M_PI / 2 + 4 * M_PI, 2 * M_PI)) < 1e-9
		, 'SXF_ABSOLUTEPOSITION keeps the offsets on the map axes, the velocity follows the caller, and the angle is added to its facing'
	);
	$registry->destroy($bit);

	for($i = 0; $i < 300; $i++) $dispatch('A_SpawnItemEx', ['TrailBit', 0, 0, 0, 0, 0, 0, 0, 0, 256]);

	verifyWad(!$trailBits(), 'a chance of 256 in 256 never spawns, as it is the chance of not spawning');

	// A_CustomMissile: along the facing with CMF_AIMDIRECTION, at the target otherwise, and nothing without one.
	$missiles = static function() use ($registry): array {
		$found = [];

		foreach($registry->query(Projectile::class) as $entity)
		if($registry->get($entity, ActorState::class)->definition->name === 'FastShot') $found[] = $entity;

		return $found;
	};
	$dispatch('A_CustomMissile', ['FastShot', 0, 0, 90, 2, 0]);
	[$missile] = $missiles();
	$velocity = $registry->get($missile, Velocity::class);
	$heading = atan2($velocity->y, $velocity->x);
	verifyWad(abs(fmod($heading - $pose->angle - M_PI / 2 + 4 * M_PI, 2 * M_PI)) < 1e-6 && $registry->get($missile, Projectile::class)->owner === $player, 'CMF_AIMDIRECTION fires along the caller\'s facing turned by the angle, credited to the caller');
	$registry->destroy($missile);
	$dispatch('A_CustomMissile', ['FastShot', 0, 0, 0, 0, 0]);
	verifyWad(!$missiles(), 'without a target and without CMF_AIMDIRECTION a player fires nothing');

	// A missile firing a missile: aimed at its shooter, who is its "target", and with CMF_TRACKOWNER it is owned by
	// the firing missile, so it can strike the shooter.
	$beam = $spawn->invoke($combat, $registry, $player, 'FastShot', $startX + 200 * cos($pose->angle), $startY + 200 * sin($pose->angle), $startZ, $pose->angle, null, 0.0);
	$combat->actions->dispatch($beam, new StateFrame('TNT1', 'A', 0, false, 'A_CustomMissile', ['FastShot', 0, 0, 0, 4, 0]), $registry);
	$fragments = array_values(array_diff($missiles(), [$beam]));
	verifyWad(count($fragments) === 1, 'a missile fires a fragment at its shooter');
	$fragmentVelocity = $registry->get($fragments[0], Velocity::class);
	$beamPose = $registry->get($beam, Transform::class);
	$toShooter = atan2($pose->y - $beamPose->y, $pose->x - $beamPose->x);
	verifyWad(
		abs(fmod(atan2($fragmentVelocity->y, $fragmentVelocity->x) - $toShooter + 4 * M_PI, 2 * M_PI)) < 1e-6
			&& $registry->get($fragments[0], Projectile::class)->owner === $beam
		, 'the fragment heads for the shooter and, with CMF_TRACKOWNER, belongs to the beam rather than the shooter'
	);
})();
