<?php
use PhpWasmEngine\Ecs\Registry;
use PhpWasmEngine\Gameplay\Badnik;
use PhpWasmEngine\Gameplay\BadnikBehavior;
use PhpWasmEngine\Gameplay\Badniks;
use PhpWasmEngine\Gameplay\Monitor;
use PhpWasmEngine\Gameplay\Monitors;
use PhpWasmEngine\Gameplay\PlayerCombat;
use PhpWasmEngine\Gameplay\RingCounter;
use PhpWasmEngine\Gameplay\Shield;
use PhpWasmEngine\Gameplay\ShieldAura;
use PhpWasmEngine\Gameplay\ShieldMonitorEffect;
use PhpWasmEngine\Gameplay\Shields;
use PhpWasmEngine\Input\Controls;
use PhpWasmEngine\Physics\Body;
use PhpWasmEngine\Physics\MapTerrain;
use PhpWasmEngine\Physics\Platformer;
use PhpWasmEngine\Physics\Terrain;
use PhpWasmEngine\Render\Sprite;
use PhpWasmEngine\Tiled\Loader;

test('shield inventory selects persistent types and restores one after timed star immunity', function() {
	$shield = new Shield(Shield::NORMAL);
	check($shield->active === Shield::NORMAL && $shield->inventory() === [Shield::NORMAL]);
	$shield->grant(Shield::ELECTRIC);
	check($shield->active === Shield::ELECTRIC);
	check($shield->inventory() === [Shield::NORMAL, Shield::ELECTRIC]);
	check($shield->select(Shield::NORMAL));
	$shield->grant(Shield::STAR, 2);
	check($shield->active === Shield::STAR && $shield->immune('normal'));
	check(!$shield->select(Shield::ELECTRIC));
	$shield->update(1.5);
	near($shield->remaining, .5);
	$shield->update(.5);
	check($shield->active === Shield::NORMAL && $shield->remaining === 0.0);
	check($shield->absorb('normal'));
	check($shield->active === '' && !$shield->has(Shield::NORMAL));
	check($shield->select(Shield::ELECTRIC));
});

test('shield damage keeps rings, elemental immunity skips knockback, and contacts defeat badniks', function() {
	$body = new Body(0, 0);
	$body->grounded = true;
	$counter = new RingCounter(12);
	$combat = new PlayerCombat(1);
	$shield = new Shield(Shield::NORMAL);
	check($combat->damage($body, $counter, 1, $shield));
	check($counter->rings === 12 && $combat->hits === 1 && $shield->active === '');
	near($body->vx, 240);

	$combat->mercy = 0;
	$shield->grant(Shield::FIRE);
	$body->vx = $body->vy = 0;
	check(!$combat->damage($body, $counter, -1, $shield, 'fire'));
	check($shield->active === Shield::FIRE && $combat->hits === 1);
	near($body->vx, 0);
	check(!$combat->damage($body, $counter, -1, $shield, 'projectile'));

	$registry = new Registry;
	$terrain = new Terrain;
	$badniks = new Badniks($registry, $terrain);
	$body = new Body(0, 0);
	$body->grounded = true;
	$counter = new RingCounter(7);
	$combat = new PlayerCombat(1);
	$shield = new Shield(Shield::NORMAL);
	$player = $registry->create($body, $counter, $combat, $shield);

	$enemy = $badniks->create(
		new Badnik(0, 16, points: 100)
		, new class implements BadnikBehavior {}
		, new Sprite(1)
	);

	$badniks->sync($player, $body);
	$badniks->update($registry, 1 / 60);
	check(!$registry->alive($enemy));
	check($counter->rings === 7 && $counter->score === 100);
	check($combat->hits === 1 && $shield->active === '');
	$badniks->close();
});

test('Tiled shield monitors grant animated auras and each movement ability uses generic controls', function() {
	$map = (new Loader)->map(dirname(__DIR__, 3) . '/examples/assets/badniks.tmj');
	$registry = new Registry;
	$terrain = MapTerrain::build($map);
	$shields = new Shields($registry, $map);
	$monitors = new Monitors($registry, $terrain, $map, shields: $shields);
	$body = new Body(0, 0);
	$body->grounded = false;
	$body->jumping = true;
	$player = $registry->create($body, new RingCounter, new PlayerCombat);
	$ids = $monitors->entities();

	check($registry->get($ids[2], ShieldMonitorEffect::class)->type === Shield::NORMAL);
	check($registry->get($ids[6], ShieldMonitorEffect::class)->type === Shield::STAR);
	check($monitors->pop($ids[2], $player));
	$shield = $registry->get($player, Shield::class);
	check($shield->active === Shield::NORMAL);
	check(count($registry->query(ShieldAura::class)) === 1);
	$aura = $registry->query(ShieldAura::class)[0];
	check($registry->get($aura, Sprite::class)->gid === 52);
	check($registry->get($aura, Sprite::class)->outline);

	$controls = new Controls;
	$shields->grant($player, Shield::ELECTRIC);
	$controls->set([]);
	$controls->set(['jump' => true]);
	$shields->control($player, $controls, 1 / 60);
	near($body->vy, -600);
	check($shield->electricJumps === 2 && $body->ability === 'electric-jump');

	$shields->grant($player, Shield::FIRE);
	$body->vx = 0;
	$controls->set([]);
	$controls->set([5 => true]);
	$shields->control($player, $controls, .1);
	check($body->vx > 0 && $body->ability === 'fire-dash');
	near($shield->firePower, .15);

	$shields->grant($player, Shield::WATER);
	$controls->set([]);
	$controls->set(['jump' => true]);
	$shields->control($player, $controls, 1 / 60);
	near($body->vy, 840);
	check($shield->bubbleArmed && $body->ability === 'bubble-bounce');
	$body->grounded = true;
	$shields->update($registry, 1 / 60);
	check(!$body->grounded && $body->jumping && !$body->rolling);
	check(!$shield->bubbleArmed && $body->ability === 'bubble-rebound');
	near($body->halfHeight, $body->rollingHeight);
	near($body->vy, -840);

	$shields->grant($player, Shield::STAR, .5);
	$shields->update($registry, .5);
	check($shield->active === Shield::WATER);
	check($registry->get($aura, Sprite::class)->gid === 58);
	$shields->close();
	check($registry->query(ShieldAura::class) === [] && $registry->has($player, Shield::class));
	$restored = new Shields($registry, $map);
	$restored->update($registry, 1 / 60);
	check(count($registry->query(ShieldAura::class)) === 1);
	$restored->close();
	$monitors->close();
});

test('flight profiles reserve neutral jump and route modified jumps through their shields', function() {
	foreach([['flyer', 'fly'], ['glider', 'glide']] as [$profile, $ability])
	{
		foreach([Shield::ELECTRIC, Shield::WATER] as $type)
		{
			$registry = new Registry;
			$shields = new Shields($registry);
			$body = new Body(0, -100, $profile);
			$body->jumping = true;
			$player = $registry->create($body);
			$shield = $shields->grant($player, $type);
			$controls = new Controls;
			$controls->set([]);
			$controls->set(['jump' => true]);
			$consumed = $shields->control($player, $controls, 1 / 60);
			check(!$consumed);
			check($shield->electricJumps === 3 && !$shield->bubbleArmed);
			(new Platformer(new Terrain))->step($body, $controls, 1 / 60, $consumed);
			check($body->ability === $ability);
			$shields->close();
		}
	}

	$registry = new Registry;
	$shields = new Shields($registry);
	$body = new Body(0, -100, 'flyer');
	$body->jumping = true;
	$player = $registry->create($body);
	$shield = $shields->grant($player, Shield::ELECTRIC);
	$controls = new Controls;
	$controls->set([]);
	$controls->set(['up' => true, 'jump' => true]);
	$consumed = $shields->control($player, $controls, 1 / 60);
	check($consumed && $shield->electricJumps === 2);
	(new Platformer(new Terrain))->step($body, $controls, 1 / 60, $consumed);
	check($body->ability === 'electric-jump');

	$shields->grant($player, Shield::WATER);
	$body->profile = 'glider';
	$body->ability = '';
	$body->rolling = false;
	$controls = new Controls;
	$controls->set([]);
	$controls->set(['down' => true, 'jump' => true]);
	$consumed = $shields->control($player, $controls, 1 / 60);
	check($consumed && $shield->bubbleArmed);
	(new Platformer(new Terrain))->step($body, $controls, 1 / 60, $consumed);
	check($body->ability === 'bubble-bounce');
	check($body->jumping && !$body->rolling);
	$shields->close();
});

test('plain falls cannot trigger shield jumps, flight, or glide', function() {
	foreach(['flyer', 'glider'] as $profile)
	{
		foreach([Shield::ELECTRIC, Shield::WATER] as $type)
		{
			$registry = new Registry;
			$shields = new Shields($registry);
			$body = new Body(0, -100, $profile);
			$player = $registry->create($body);
			$shield = $shields->grant($player, $type);
			$controls = new Controls;
			$controls->set([]);
			$controls->set(['jump' => true]);
			$consumed = $shields->control($player, $controls, 1 / 60);
			check(!$consumed);
			(new Platformer(new Terrain))->step($body, $controls, 1 / 60, $consumed);
			check(!$body->jumping && !$body->rolling && $body->ability === '');

			$controls->set([]);
			$controls->set(['down' => true, 'jump' => true]);
			$consumed = $shields->control($player, $controls, 1 / 60);
			check($consumed);
			(new Platformer(new Terrain))->step($body, $controls, 1 / 60, $consumed);
			check(!$body->jumping && !$body->rolling && $body->ability === '');
			check($shield->electricJumps === 3 && !$shield->bubbleArmed);
			$shields->close();
		}
	}
});

test('fire thrust composes with flight and glide while climbing rejects shield movement', function() {
	foreach([['flyer', 'fly'], ['glider', 'glide']] as [$profile, $ability])
	{
		$registry = new Registry;
		$shields = new Shields($registry);
		$body = new Body(0, -100, $profile);
		$body->jumping = true;
		$body->ability = $ability;
		$player = $registry->create($body);
		$shield = $shields->grant($player, Shield::FIRE);
		$controls = new Controls;
		$controls->set([4 => true]);
		check(!$shields->control($player, $controls, .1));
		check($body->ability === $ability && $body->vx < 0);
		check($body->facing === 1 && $body->direction === 1);
		near($shield->firePower, .15);
		(new Platformer(new Terrain))->step($body, $controls, 1 / 60);
		check($body->ability === $ability);
		$shields->close();
	}

	$registry = new Registry;
	$shields = new Shields($registry);
	$body = new Body(0, -100, 'glider');
	$body->ability = 'climb';
	$player = $registry->create($body);
	$shield = $shields->grant($player, Shield::ELECTRIC);
	$controls = new Controls;
	$controls->set(['down' => true, 'jump' => true]);
	check(!$shields->control($player, $controls, 1 / 60));
	check($body->ability === 'climb' && $shield->electricJumps === 3);
	$shields->close();
});

test('shield movement and damage knockback follow alternate gravity axes', function() {
	$registry = new Registry;
	$shields = new Shields($registry);
	$body = new Body;
	$body->setGravityDirection(1, 0);
	$body->jumping = true;
	$player = $registry->create($body);
	$shields->grant($player, Shield::ELECTRIC);
	$controls = new Controls;
	$controls->set([]);
	$controls->set(['jump' => true]);
	check($shields->control($player, $controls, 1 / 60));
	near($body->vx, -600);
	near($body->vy, 0);

	$shields->grant($player, Shield::FIRE);
	$body->vx = $body->vy = 0;
	$controls = new Controls;
	$controls->set([4 => true]);
	$shields->control($player, $controls, .1);
	near($body->vx, 0);
	check($body->vy > 0);

	$combat = new PlayerCombat;
	$shield = $registry->get($player, Shield::class);
	$shield->active = '';
	check($combat->damage($body, null, 1, $shield));
	near($body->vx, -360);
	near($body->vy, -240);
	$shields->close();
});

test('shield components and effects reject invalid types, durations, recipients, and timing', function() {
	rejects(fn() => new Shield('unknown'));
	rejects(fn() => new Shield(Shield::STAR, 0));
	rejects(fn() => new ShieldMonitorEffect(Shield::STAR, NAN));
	rejects(fn() => new ShieldAura(0));
	$shield = new Shield(Shield::NORMAL);
	rejects(fn() => $shield->immune(''));
	rejects(fn() => $shield->absorb(''));
	rejects(fn() => $shield->update(-1));
	$shields = new Shields(new Registry);
	rejects(fn() => $shields->grant(1, Shield::NORMAL));
	$shields->close();
});
