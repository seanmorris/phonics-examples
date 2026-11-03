<?php
declare(strict_types=1);

use DoomPhonics\Adapter\ActorFactory;
use DoomPhonics\Definition\DecorateParser;
use DoomPhonics\Game\PlayerState;
use DoomPhonics\System\PickupSystem;
use Phonics\Ecs\Registry;

(static function() use ($actors): void {
	$actors = clone $actors;

	$custom = DecorateParser::catalog([
		'pickup-test.dec' => <<<'DEC'
ACTOR SmallRocketCrate : rocketbox
{
  Inventory.Amount 3
}
ACTOR TestAmmo : Ammo
{
  Inventory.Amount 2
  Inventory.MaxAmount 30
}
ACTOR TestAmmoCrate : TestAmmo
{
  Inventory.Amount 8
}
ACTOR NumberedAmmoGun : Weapon
{
  Weapon.AmmoType1 "TestAmmo"
  Weapon.AmmoGive1 6
}
DEC
	]);

	foreach($custom->all() as $definition) $actors->add($definition);

	$world = new Registry;
	$factory = new ActorFactory($actors);
	$system = new PickupSystem($actors);
	$sector = ['floor' => 0, 'ceiling' => 128];
	$entity = $factory->spawn($world, $actors->numbered(1), ['x' => 0, 'y' => 0], $sector);
	$player = $world->get($entity, PlayerState::class);

	$collect = static function(
		string $name
		, bool $accepted = true
	) use ($world, $factory, $system, $actors, $sector): int {
		$pickup = $factory->spawn($world, $actors->named($name), ['x' => 0, 'y' => 0], $sector);
		$system->update($world, 1 / 35);
		$world->flush();
		verifyWad($world->alive($pickup) !== $accepted, "$name is removed only when its pickup is accepted");

		return $pickup;
	};

	$collect('RocketLauncher');

	verifyWad(
		!empty($player->weapons['RocketLauncher']) && $player->ammo['RocketAmmo'] === 2
		, 'rocket launcher grants two usable rockets'
	);

	$collect('RocketBox');
	verifyWad($player->ammo['RocketAmmo'] === 7, 'rocket box adds five rockets to the launcher ammo');
	$collect('RocketBox');
	verifyWad($player->ammo['RocketAmmo'] === 12, 'successive rocket boxes accumulate usable rockets');
	$collect('SmallRocketCrate');

	verifyWad(
		$player->ammo['RocketAmmo'] === 15
		, 'nested ammo pickups resolve case-insensitive ancestry and retain their own amount'
	);

	foreach([
		['ClipBox', 'Clip', 100, 200]
		, ['ShellBox', 'Shell', 20, 50]
		, ['CellPack', 'Cell', 100, 300]
	] as [$pickup, $type, $expected, $maximum])
	{
		$collect($pickup);

		verifyWad(
			$player->ammo[$type] === $expected && $player->ammoMaximum[$type] === $maximum
			, "$pickup fills the shared $type ammo pool"
		);
	}

	verifyWad(
		array_keys($player->ammo) === ['Clip', 'Shell', 'RocketAmmo', 'Cell']
		, 'ammo boxes do not create unusable inventory pools'
	);

	$player->ammo['RocketAmmo'] = 49;
	$collect('RocketBox');
	verifyWad($player->ammo['RocketAmmo'] === 50, 'ammo boxes clamp at the shared capacity');
	$fullPickup = $collect('RocketBox', false);
	verifyWad($player->ammo['RocketAmmo'] === 50, 'a full ammo pool leaves a box available in the world');
	$world->destroy($fullPickup);

	$collect('Backpack');

	verifyWad(
		$player->ammo['RocketAmmo'] === 51 && $player->ammoMaximum['RocketAmmo'] === 100
		, 'backpack expands rocket capacity and grants ammo'
	);

	$collect('RocketBox');
	$collect('RocketAmmo');

	verifyWad(
		$player->ammo['RocketAmmo'] === 57 && $player->ammoMaximum['RocketAmmo'] === 100
		, 'box and loose ammo pickups preserve backpack capacity'
	);

	$collect('TestAmmoCrate');
	$collect('TestAmmo');

	verifyWad(
		$player->ammo['TestAmmo'] === 10 && $player->ammoMaximum['TestAmmo'] === 30 && !isset($player->ammo[
			'TestAmmoCrate'
		])
		, 'custom ammo classes share a pool with their derived pickups'
	);

	$player->ammo['TestAmmo'] = 0;
	$collect('NumberedAmmoGun');

	verifyWad(
		!empty($player->weapons['NumberedAmmoGun'])
			&& $player->ammo['TestAmmo'] === 6
			&& $player->ammoMaximum['TestAmmo'] === 30
		, 'weapon pickups support numbered DECORATE primary-ammo properties and initialize custom pools'
	);
})();
