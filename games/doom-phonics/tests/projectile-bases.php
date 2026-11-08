<?php
declare(strict_types=1);

use DoomPhonics\Adapter\WadSource;
use DoomPhonics\Archive\Archive;
use DoomPhonics\Archive\Stack;
use DoomPhonics\Component\Body;
use DoomPhonics\Component\PlayerStart;
use DoomPhonics\Component\Projectile;
use DoomPhonics\Component\Transform;
use DoomPhonics\Game\GameSettings;
use DoomPhonics\System\CombatSystem;
use DoomPhonics\System\EpisodeSystems;
use DoomPhonics\Tests\PortMap;
use Phonics\Ecs\Registry;
use Phonics\Ecs\Scheduler;
use Phonics\Input\Controls;
use Phonics\World\ScopeContext;

require_once __DIR__ . '/fixtures/PortMap.php';

// Add-ons derive their shots from ZDoom's FastProjectile and launch plain actors with A_CustomMissile. The Omega
// Weapon Pack's Channeler did both, and its beam, a FastProjectile child, had no body: the first tic after firing read
// a missing Body and ended the program.
(static function(): void {
	$addon = PortMap::archive(['DECORATE' => <<<'DECORATE'
ACTOR FastShot : FastProjectile
{
  Speed 50
  Radius 8
  Height 8
  Damage 1
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
ACTOR Drone
{
  Radius 0
  Height 0
  +NOGRAVITY
  +NOINTERACTION
  RenderStyle None
  States
  {
  Spawn:
    TNT1 A 1
    Loop
  }
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

	$fast = $actors->named('FastShot');
	verifyWad($fast !== null && $actors->named('FastProjectile') !== null && $fast->hasFlag('MISSILE') && $fast->hasFlag('NOGRAVITY'), 'a FastProjectile child inherits the projectile flags');
	verifyWad($actors->numbered(49)?->name === 'BloodyTwitch', 'BloodyTwitch answers to editor number 49');

	foreach($registry->query(PlayerStart::class) as $player) break;

	for($i = 0; $i < 3; $i++) $scheduler->update(1 / 35);

	$pose = $registry->get($player, Transform::class);
	$spawn = new ReflectionMethod($combat, 'spawnProjectile');
	$fired = [];

	foreach(['FastShot', 'Drone'] as $name)
	{
		$entity = $spawn->invoke($combat, $registry, $player, $name, $pose->x + 40 * cos($pose->angle), $pose->y + 40 * sin($pose->angle), $pose->z + 32, $pose->angle, null, 0.0);
		verifyWad($entity !== null && $registry->has($entity, Projectile::class) && $registry->has($entity, Body::class) && $registry->get($entity, Body::class)->missile, "$name fired as a missile has a missile body");
		$fired[$name] = [$entity, $registry->get($entity, Transform::class)];
	}

	for($i = 0; $i < 5; $i++) $scheduler->update(1 / 35);

	[$drone, $dronePose] = $fired['Drone'];
	verifyWad(
		!$registry->alive($drone) || hypot($dronePose->x - $pose->x, $dronePose->y - $pose->y) > 40
		, 'a plain actor fired as a missile flies by its velocity, or has struck something, without ending the simulation'
	);
})();
