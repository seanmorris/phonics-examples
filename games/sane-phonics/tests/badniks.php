<?php
use PhpWasmEngine\Ecs\Registry;
use PhpWasmEngine\Gameplay\Badnik;
use PhpWasmEngine\Gameplay\BadnikBehavior;
use PhpWasmEngine\Gameplay\BadnikProjectile;
use PhpWasmEngine\Gameplay\Combo;
use PhpWasmEngine\Gameplay\ComboReward;
use PhpWasmEngine\Gameplay\Badniks;
use PhpWasmEngine\Gameplay\CrabMeat;
use PhpWasmEngine\Gameplay\CrabMeats;
use PhpWasmEngine\Gameplay\PlayerCombat;
use PhpWasmEngine\Gameplay\RingCounter;
use PhpWasmEngine\Gameplay\Rings;
use PhpWasmEngine\Gameplay\ScatteredRing;
use PhpWasmEngine\Physics\Body;
use PhpWasmEngine\Physics\MapTerrain;
use PhpWasmEngine\Physics\Terrain;
use PhpWasmEngine\Render\Sprite;
use PhpWasmEngine\Tiled\Loader;

test('Tiled CrabMeat objects retain their timed patrol, poses, and paired delayed shots', function() {
	$map = (new Loader)->map(dirname(__DIR__, 3) . '/examples/assets/badniks.tmj');
	$registry = new Registry;
	$terrain = MapTerrain::build($map);
	$badniks = new Badniks($registry, $terrain);
	$crabs = new CrabMeats($registry, $terrain, $map, $badniks);
	$ids = $crabs->entities();
	check(count($ids) === 1);
	$entity = $ids[0];
	$badnik = $registry->get($entity, Badnik::class);
	$crab = $registry->get($entity, CrabMeat::class);
	$sprite = $registry->get($entity, Sprite::class);
	near($badnik->x, 480);
	near($badnik->y, 448);
	check($crab->direction === -1 && $sprite->gid === 20 && $sprite->flipX);

	$crabs->update($registry, 65 / 60 + .001);
	near($badnik->x, 480 - 65 * .25);
	check(!$crab->moving && $crab->animation === 'standing' && $sprite->gid === 19);
	$crabs->update($registry, 10 / 60);
	check($crab->animation === 'shooting' && $sprite->gid === 22);
	$crabs->update($registry, 12 / 60);
	check($crab->shots === 2);

	$shots = array_values(
		array_filter(
			$registry->query(BadnikProjectile::class)
			, fn($id) => $registry->get($id, BadnikProjectile::class)->owner === $entity
		)
	);

	check(count($shots) === 2);
	$right = $registry->get($shots[1], BadnikProjectile::class);
	$startX = $right->x;
	$startY = $right->y;
	$badniks->update($registry, .04);
	near($right->x, $startX);
	near($right->y, $startY);
	$badniks->update($registry, .02);
	near($right->x, $startX + .9);
	near($right->y, $startY - 1.773);
	$beforeReverse = $badnik->x;
	$crabs->update($registry, .05);
	check($crab->direction === 1 && $crab->moving && $badnik->x > $beforeReverse);
	$badniks->close();
	check($registry->query(Badnik::class) === [] && $registry->query(BadnikProjectile::class) === []);
});

test('badnik contacts damage walking players and pop under swept rolling attacks', function() {
	$registry = new Registry;
	$map = (new Loader)->map(dirname(__DIR__, 3) . '/examples/assets/badniks.tmj');
	$terrain = MapTerrain::build($map);
	$rings = new Rings($registry, $map, terrain: $terrain);
	$badniks = new Badniks($registry, $terrain, $rings);
	$body = new Body(0, 0);
	$body->grounded = true;
	$counter = new RingCounter(5, 10);
	$combat = new PlayerCombat(1);
	$combo = new Combo;
	$combo->add(new ComboReward('Before damage', 100));
	$player = $registry->create($body, $counter, $combat, $combo);

	$enemy = $badniks->create(
		new Badnik(100, 0, points: 100, explosionGid: 2)
		, new class implements BadnikBehavior {}
		, new Sprite(1)
	);

	$badniks->sync($player, $body);
	$body->x = 200;
	$badniks->update($registry, 1 / 60);
	check($combat->hits === 1 && $counter->rings === 0 && $registry->has($enemy, Badnik::class));
	check(count($registry->query(ScatteredRing::class)) === 5);
	check(!$body->rolling && !$body->grounded && $body->vx === 240.0 && $body->vy === -360.0);
	check($combo->chain === [] && $combo->failed && $combo->result === 100);
	check(!$combat->attacking($body), 'damage knockback must not immediately pop the attacker');
	check($combat->flickerOpacity() === 1.0);
	$combat->mercy = .4;
	check($combat->flickerOpacity() === 1.0);
	$combat->mercy = .35;
	check($combat->flickerOpacity() === 0.0);
	$combat->mercy = .225;
	check($combat->flickerOpacity() === 1.0);
	$combat->mercy = .1;
	check($combat->flickerOpacity() === 0.0);
	$combat->mercy = 1;

	$body->x = 0;
	$body->y = 0;
	$body->grounded = true;
	$badniks->update($registry, 1 / 60);
	check($combat->hits === 1, 'post-hit mercy must suppress repeated overlap damage');

	$combat->mercy = 0;
	$body->rolling = true;
	$body->x = 200;
	$badniks->update($registry, 1 / 60);
	check(!$registry->has($enemy, Badnik::class));
	check($counter->score === 110 && $combat->defeats === 1);
	check($badniks->counts() === ['badniks' => 0,'projectiles' => 0,'explosions' => 1]);
	$badniks->close();
	$rings->close();
});

test('only a roll or jump somersault counts as a contact attack', function() {
	$combat = new PlayerCombat;
	$body = new Body(0, -100);
	check(!$body->grounded && !$body->jumping && !$combat->attacking($body));
	$body->jumping = true;
	check($combat->attacking($body));
	$body->jumping = false;
	$body->rolling = true;
	check($combat->attacking($body));
});

test('projectiles use continuous terrain casts and become harmless when their owner is gone', function() {
	$registry = new Registry;
	$terrain = new Terrain([['points' => [[50,50],[50,-50]]]]);
	$badniks = new Badniks($registry, $terrain);

	$owner = $badniks->create(
		new Badnik(-100, 0)
		, new class implements BadnikBehavior {}
		, new Sprite(1)
	);

	$fast = $badniks->projectile(
		new BadnikProjectile($owner, 0, 0, 6000, 0, gravity: 0, delay: 0, lifetime: 1)
		, new Sprite(2)
	);

	$badniks->update($registry, 1 / 60);
	check(!$registry->alive($fast), 'the full projectile displacement must hit the thin wall');

	$body = new Body(0, 0);
	$body->grounded = true;
	$combat = new PlayerCombat;
	$player = $registry->create($body, $combat);
	$badniks->sync($player, $body);

	$stale = $badniks->projectile(
		new BadnikProjectile($owner, 0, 0, 0, 0, gravity: 0, delay: 0, lifetime: 1)
		, new Sprite(2)
	);

	$badniks->pop($owner);
	$badniks->update($registry, 1 / 60);
	check(!$registry->alive($stale) && $combat->hits === 0);
	$badniks->close();
});

test('badnik components reject invalid bounds, timing, and ownership', function() {
	rejects(fn() => new Badnik(NAN, 0));
	rejects(fn() => new Badnik(0, 0, width: 0));
	rejects(fn() => new Badnik(0, 0, points: -1));
	rejects(fn() => new CrabMeat(patrolBeat: 0));
	rejects(fn() => new CrabMeat(patrolPause: 2));
	rejects(fn() => new CrabMeat(collisionLayer: -1));
	rejects(fn() => new BadnikProjectile(0, 0, 0, 0, 0));
	rejects(fn() => new BadnikProjectile(1, 0, 0, 0, 0, radius: 0));
	rejects(fn() => new ScatteredRing(0, 0, 0));
	rejects(fn() => new ScatteredRing(1, 0, 0, pickupDelay: -1));
	rejects(fn() => new ScatteredRing(1, 0, 0, restitution: 2));
});
