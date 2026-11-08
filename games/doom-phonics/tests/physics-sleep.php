<?php
declare(strict_types=1);

use DoomPhonics\Adapter\WadSource;
use DoomPhonics\Archive\Archive;
use DoomPhonics\Archive\Stack;
use DoomPhonics\Component\Body;
use DoomPhonics\Component\HitPoints;
use DoomPhonics\Component\MonsterBrain;
use DoomPhonics\Component\PlayerStart;
use DoomPhonics\Component\Transform;
use DoomPhonics\Component\Velocity;
use DoomPhonics\Game\DamageEvent;
use DoomPhonics\Game\GameSettings;
use DoomPhonics\System\CombatSystem;
use DoomPhonics\System\EpisodeSystems;
use Phonics\Ecs\Registry;
use Phonics\Ecs\Scheduler;
use Phonics\Input\Controls;
use Phonics\World\ScopeContext;

// Actor physics stops visiting a body left at rest on a plain floor. Everything that can move such a body must wake
// it: damage thrust, a rising floor in its sector, and a Collision move. A floor raised behind the level's back, with
// no planeChanged(), is the control that shows the sleep is real.
(static function(): void {
	$source = new WadSource(new Stack(new Archive(file_get_contents(dirname(__DIR__) . '/demo/DOOM1.WAD'), 'DOOM1.WAD')));
	$registry = new Registry;
	$scheduler = new Scheduler($registry);
	$descriptor = null;

	foreach($source->catalog() as $candidate) if($candidate->id === 'E1M3') $descriptor = $candidate;

	$scope = new ScopeContext('episode', $descriptor, $registry, $scheduler, services: ['controls' => new Controls, 'doom-settings' => new GameSettings]);
	$scope->setContent($source->activate('E1M3', $scope)->content());
	(new EpisodeSystems)->activate($scope);
	$level = $scope->content()->level;
	$combat = null;

	foreach((new ReflectionProperty($scheduler, 'systems'))->getValue($scheduler) as [$callable])
	if(is_array($callable) && $callable[0] instanceof CombatSystem) $combat = $callable[0];

	$resolver = (new ReflectionProperty($combat, 'damageResolver'))->getValue($combat);
	$tick = static function(int $count) use ($scheduler): void { for(; $count > 0; $count--) $scheduler->update(1 / 35); };
	$monsters = [];

	foreach($registry->query(MonsterBrain::class) as $entity)
	{
		$registry->remove($entity, MonsterBrain::class);
		$monsters[] = $entity;
	}

	foreach($registry->query(PlayerStart::class) as $player) break;

	$tick(20);
	[$pushed, $lifted, $unseen] = $monsters;
	$pose = $registry->get($pushed, Transform::class);
	$before = [$pose->x, $pose->y];
	$registry->get($pushed, HitPoints::class)->painChance = 0;
	$resolver->apply($registry, new DamageEvent($pushed, $player, 1, thrust: true));
	$tick(3);
	verifyWad([$pose->x, $pose->y] !== $before, 'damage thrust wakes a sleeping monster, which then slides');

	$pose = $registry->get($lifted, Transform::class);
	$sector = $level->sectorAt($pose->x, $pose->y);
	$sector->floor += 8;
	$level->planeChanged($sector->index);
	$tick(1);
	verifyWad($pose->z === $sector->floor, 'a floor raised through planeChanged lifts the monster sleeping on it');

	$pose = $registry->get($unseen, Transform::class);
	$sector = $level->sectorAt($pose->x, $pose->y);
	$floor = $pose->z;
	$sector->floor += 8;
	$tick(1);
	verifyWad($pose->z === $floor, 'a floor raised without planeChanged leaves the sleeping monster where it was');
	$sector->floor -= 8;

	$pose = $registry->get($monsters[3], Transform::class);
	$body = $registry->get($monsters[3], Body::class);
	$combat->collision->move($pose, $body, 2, 0, $monsters[3]);
	$registry->get($monsters[3], Velocity::class)->z = 40;
	$tick(1);
	verifyWad($pose->z > $pose->previousZ, 'a Collision move wakes the monster, so a velocity set in the same tic takes effect');

	// A sleeping body that is destroyed must leave the sleep index: a later plane change in its sector used to wake
	// the dead id and the next step read a missing cache entry. A spawn elsewhere must not wake the sleepers either.
	$physics = null;

	foreach((new ReflectionProperty($scheduler, 'systems'))->getValue($scheduler) as [$callable])
	if(is_array($callable) && $callable[0] instanceof DoomPhonics\System\ActorPhysicsSystem) $physics = $callable[0];

	$awake = new ReflectionProperty($physics, 'awake');
	$tick(20);
	$sleepersBefore = count($registry->query(Body::class)) - count($awake->getValue($physics));
	$spawned = $registry->create(new Transform(-1472.0, -3344.0, 64.0), new Velocity, new Body(16, 20));
	$tick(1);
	verifyWad(
		count($awake->getValue($physics)) <= 2 && $sleepersBefore > 50
		, 'spawning a body elsewhere does not wake the sleeping ones'
	);

	$doomed = $monsters[4];
	$doomedPose = $registry->get($doomed, Transform::class);
	$doomedSector = $level->sectorAt($doomedPose->x, $doomedPose->y);
	$registry->destroy($doomed);
	$tick(1);
	$doomedSector->floor += 8;
	$level->planeChanged($doomedSector->index);
	$tick(2);
	verifyWad(!isset($awake->getValue($physics)[$doomed]), 'a destroyed sleeper is forgotten, so a later plane change cannot wake it');
	$doomedSector->floor -= 8;

	// Blood retires mid-fall: a body destroyed while awake must leave the awake set before the next step reads it.
	$falling = $monsters[5];
	$registry->get($falling, Velocity::class)->z = 60;
	$physics->wake($falling);
	$tick(1);
	verifyWad(isset($awake->getValue($physics)[$falling]), 'a body given upward velocity is awake');
	$registry->destroy($falling);
	$tick(2);
	verifyWad(!isset($awake->getValue($physics)[$falling]), 'a body destroyed while awake is forgotten before the next step');
})();
