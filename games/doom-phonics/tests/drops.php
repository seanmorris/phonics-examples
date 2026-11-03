<?php
declare(strict_types=1);

use DoomPhonics\Adapter\ActorFactory;
use DoomPhonics\Adapter\WadDefinitions;
use DoomPhonics\Component\ActorState;
use DoomPhonics\Component\InventoryPickup;
use DoomPhonics\Component\Transform;
use DoomPhonics\Definition\DecorateParser;
use DoomPhonics\Game\ActorDropper;
use DoomPhonics\Game\DamageEvent;
use DoomPhonics\Game\DamageResolver;
use DoomPhonics\Game\Level;
use DoomPhonics\Game\PlayerState;
use DoomPhonics\System\PickupSystem;
use Phonics\Ecs\Registry;

(static function() use ($stack): void {
	$inheritance = DecorateParser::catalog([
		'drop-inheritance.dec' => <<<'DEC'
ACTOR DropParent : Actor
{
  DropItem "Clip"
}
ACTOR DropChild : DropParent
{
  DropItem None
}
ACTOR MultiDropChild : DropParent
{
  DropItem "Clip"
  DropItem "Shell"
}
DEC
	]);

	verifyWad(
		$inheritance->named('DropChild')->values('DropItem') === [['None']]
		, 'DropItem None replaces inherited loot declarations'
	);

	verifyWad(
		$inheritance->named('MultiDropChild')->values('DropItem') === [['Clip'],['Shell']]
		, 'repeated DropItem declarations remain one class-local drop list'
	);

	$actors = (new WadDefinitions)->actors;
	$factory = new ActorFactory($actors);
	$level = new Level($stack->map('E1M1'));
	$world = new Registry;
	[$x,$y] = $level->sectorCenter(0);
	$sector = $level->sectorAt($x, $y);
	$planes = ['floor' => $sector->floorZ($x, $y), 'ceiling' => $sector->ceilingZ($x, $y)];
	$killer = $world->create(new PlayerState);
	$resolver = new DamageResolver(new ActorDropper($level, $factory, static fn(): int => 255));

	$spawn = static fn(string $name): int => $factory->spawn(
		$world
		, $actors->named($name)
		, ['x' => $x, 'y' => $y, 'z' => 0, 'angle' => 90, 'index' => -1, 'flags' => []]
		, $planes
	);

	$zombie = $spawn('ZombieMan');

	verifyWad(
		$resolver->apply($world, new DamageEvent($zombie, $killer, 25))
		, 'lethal damage resolves the Former Human death once'
	);

	$drops = $world->query(InventoryPickup::class);
	$clip = $world->get($drops[0], InventoryPickup::class);
	$clipPose = $world->get($drops[0], Transform::class);

	verifyWad(
		count($drops) === 1 && $clip->actorName === 'Clip' && $clip->amount === 5 && $clip->dropped
			&& abs($clipPose->x - $x) < .001 && abs($clipPose->y - $y) < .001
			&& abs($clipPose->z - $planes['floor']) < .001
		, 'Former Human death materializes its DECORATE Clip with classic dropped ammo and position'
	);

	$resolver->apply($world, new DamageEvent($zombie, $killer, 100));
	verifyWad(count($world->query(InventoryPickup::class)) === 1, 'repeated corpse damage cannot duplicate drops');

	$player = $spawn('Player1Start');
	$playerState = $world->get($player, PlayerState::class);
	(new PickupSystem($actors))->update($world, 1 / 35);
	$world->flush();
	verifyWad($playerState->ammo['Clip'] === 55, 'dropped Clip grants five bullets');

	$shotgunGuy = $spawn('ShotgunGuy');
	$resolver->apply($world, new DamageEvent($shotgunGuy, $killer, 35));
	$drops = $world->query(InventoryPickup::class);

	verifyWad(
		count($drops) === 1
			&& $world->get($drops[0], InventoryPickup::class)->actorName === 'Shotgun'
			&& $world->get($drops[0], InventoryPickup::class)->dropped
		, 'Shotgun Guy death materializes its DECORATE weapon drop'
	);

	(new PickupSystem($actors))->update($world, 1 / 35);
	$world->flush();

	verifyWad(
		!empty($playerState->weapons['Shotgun']) && $playerState->ammo['Shell'] === 4
		, 'dropped Shotgun grants the weapon and half its placed pickup ammo'
	);

	$chaingunGuy = $spawn('ChaingunGuy');
	$resolver->apply($world, new DamageEvent($chaingunGuy, $killer, 75));
	(new PickupSystem($actors))->update($world, 1 / 35);
	$world->flush();

	verifyWad(
		!empty($playerState->weapons['Chaingun']) && $playerState->ammo['Clip'] === 65
		, 'dropped Chaingun grants the weapon and ten bullets'
	);

	$corpse = $world->create(new ActorState($actors->named('DeadZombieMan')), new Transform($x, $y, $planes['floor']));
	$dropper = new ActorDropper($level, $factory, static fn(): int => 0);
	verifyWad($dropper->spawn($world, $corpse) === [], 'DropItem None suppresses decorative corpse loot');

	$custom = DecorateParser::catalog([
		'explicit-drop.dec' => <<<'DEC'
ACTOR ExplicitDropper : Actor
{
  DropItem "RocketAmmo", 255, 3
}
DEC
	]);

	foreach($custom->all() as $definition) $actors->add($definition);

	$explicit = $world->create(
		new ActorState($actors->named('ExplicitDropper'))
		, new Transform($x, $y, $planes['floor'])
	);

	$spawned = $dropper->spawn($world, $explicit);

	verifyWad(
		count($spawned) === 1 && $world->get($spawned[0], InventoryPickup::class)->amount === 3
		, 'explicit DropItem amount overrides the default dropped ammo quantity'
	);
})();
