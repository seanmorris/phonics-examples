<?php
use PhpWasmEngine\Ecs\Registry;
use PhpWasmEngine\Gameplay\Boss;
use PhpWasmEngine\Gameplay\BossExplosion;
use PhpWasmEngine\Gameplay\MiniBoss;
use PhpWasmEngine\Gameplay\MiniBosses;
use PhpWasmEngine\Gameplay\PlayerCombat;
use PhpWasmEngine\Gameplay\RingCounter;
use PhpWasmEngine\Gameplay\Rings;
use PhpWasmEngine\Gameplay\SpikeBomb;
use PhpWasmEngine\Physics\Body;
use PhpWasmEngine\Physics\MapTerrain;
use PhpWasmEngine\Render\Sprite;
use PhpWasmEngine\Tiled\Loader;

test('Tiled mini bosses wake, stalk, and drop splitting spike bombs', function() {
	$registry = new Registry;
	$map = (new Loader)->map(dirname(__DIR__, 3) . '/examples/assets/badniks.tmj');
	$terrain = MapTerrain::build($map);
	$bosses = new MiniBosses($registry, $terrain, $map);
	$entities = $bosses->entities();
	check(count($entities) === 1);
	$entity = $entities[0];
	$boss = $registry->get($entity, Boss::class);
	$behavior = $registry->get($entity, MiniBoss::class);
	near($boss->x, 1728);
	near($boss->y, 352);
	check($boss->hitPoints === 8 && $boss->phase === 'idle');
	check($bosses->entityForSourceId(3) === $entity && $bosses->aliveSource(3));
	check($registry->get($entity, Sprite::class)->outline);

	$body = new Body($boss->x + 600, 100);
	$combat = new PlayerCombat;
	$player = $registry->create($body, $combat);
	$bosses->sync($player, $body);
	check($bosses->wakeSource(3) && $boss->phase === 'stalking');

	for($frame = 0; $frame < 60; $frame++)
	{
		$bosses->update($registry, 1 / 60);
	}

	near($boss->x, 1998);
	check($behavior->bombs === 1);
	check(count($registry->query(SpikeBomb::class)) === 1);
	check($registry->get($registry->query(SpikeBomb::class)[0], Sprite::class)->outline);

	for($frame = 0; $frame < 45; $frame++)
	{
		$bosses->update($registry, 1 / 60);
	}

	check(count($registry->query(SpikeBomb::class)) >= 2, 'a landed spike bomb must split into fragments');
	$bosses->close();
	check($registry->query(Boss::class) === [] && $registry->query(SpikeBomb::class) === []);
});

test('mini-boss tile objects use a bottom-center anchor and retain layer opacity', function() {
	$registry = new Registry;
	$map = (new Loader)->map(dirname(__DIR__, 3) . '/examples/assets/badniks.tmj');
	$terrain = MapTerrain::build($map);

	foreach($map->layers as $layer)
	{
		foreach($layer->objects as &$object)
		{
			if(($object['id'] ?? null) === 3)
			{
				$object['gid'] = 2147483710.0;
				$object['width'] = 48;
				$object['height'] = 48;
				$layer->opacity = .5;
			}
		}

		unset($object);
	}

	$bosses = new MiniBosses($registry, $terrain, $map);
	$entity = $bosses->entities()[0];
	$boss = $registry->get($entity, Boss::class);
	$sprite = $registry->get($entity, Sprite::class);
	near($boss->x, 1752);
	near($boss->y, 352);
	near($sprite->opacity, .5);
	check($sprite->flipX && !$sprite->flipY);
	$bosses->update($registry, 1 / 60);
	near($sprite->opacity, .5);
	$bosses->close();
});

test('mini-boss contacts preserve dud hits, eight-hit health, finisher, and escape phases', function() {
	$registry = new Registry;
	$map = (new Loader)->map(dirname(__DIR__, 3) . '/examples/assets/badniks.tmj');
	$terrain = MapTerrain::build($map);
	$bosses = new MiniBosses($registry, $terrain, $map);
	$entity = $bosses->entities()[0];
	$boss = $registry->get($entity, Boss::class);
	$behavior = $registry->get($entity, MiniBoss::class);
	$body = new Body($boss->x, $boss->y + 76);
	$body->jumping = true;
	$body->vy = -600;
	$combat = new PlayerCombat;
	$counter = new RingCounter;
	$player = $registry->create($body, $combat, $counter);
	$bosses->sync($player, $body);
	$body->y = $boss->y - $boss->height / 2;
	$bosses->update($registry, 1 / 60);
	check($boss->hitPoints === 8 && $boss->phase === 'knocked');
	check($body->vy === 600.0, 'an underside attack must rebound downward without causing damage');

	for($hit = 0; $hit < 8; $hit++)
	{
		$behavior->age += 1;
		$boss->phase = 'stalking';
		$body->x = $boss->x - 100;
		$body->y = $boss->y - $boss->height / 2;
		$body->grounded = true;
		$body->jumping = false;
		$body->rolling = true;
		$combat->mercy = 0;
		$bosses->sync($player, $body);
		$body->x = $boss->x + 100;
		$bosses->update($registry, 1 / 60);
		check($boss->hitPoints === 7 - $hit);
	}

	check(!$bosses->aliveSource(3));
	check($behavior->hits === 8 && $combat->defeats === 1 && $counter->score === 1000);
	$body->x = 0;
	$body->y = 0;
	$body->rolling = false;
	$bosses->sync($player, $body);
	$bosses->update($registry, $behavior->deathDelay + .001);
	check($boss->phase === 'dead');

	$body->x = $boss->x - 100;
	$body->y = $boss->y - $boss->height / 2;
	$body->rolling = true;
	$bosses->sync($player, $body);
	$body->x = $boss->x + 100;
	$bosses->update($registry, 1 / 60);
	check($boss->phase === 'exploding');
	$bosses->update($registry, $behavior->explosionDuration);
	check($boss->phase === 'escaped' && $behavior->explosions === 30);
	check(count($registry->query(BossExplosion::class)) === 0, 'large updates must also retire crossed effects');
	$bosses->update($registry, $behavior->escapeDuration);
	check(!$registry->alive($entity) && $bosses->entityForSourceId(3) === null);
	$bosses->close();
});

test('spike bombs damage swept players, scatter rings, and respect mercy', function() {
	$registry = new Registry;
	$map = (new Loader)->map(dirname(__DIR__, 3) . '/examples/assets/badniks.tmj');
	$terrain = MapTerrain::build($map);
	$rings = new Rings($registry, $map, terrain: $terrain);
	$bosses = new MiniBosses($registry, $terrain, $map, $rings);
	$entity = $bosses->entities()[0];
	$boss = $registry->get($entity, Boss::class);
	$body = new Body($boss->x, 410);
	$body->grounded = true;
	$combat = new PlayerCombat;
	$counter = new RingCounter(5);
	$player = $registry->create($body, $combat, $counter);
	$bosses->sync($player, $body);
	$bosses->wakeSource(3);

	for($frame = 0; $frame < 120 && $combat->hits === 0; $frame++)
	{
		$bosses->update($registry, 1 / 60);
	}

	check($combat->hits === 1 && $counter->rings === 0);
	check($rings->scatteredCount() === 5);
	$hits = $combat->hits;
	$mercy = $combat->mercy;
	$bosses->update($registry, 1 / 60);
	check($combat->mercy < $mercy, 'a standalone boss manager must advance mercy clocks');

	for($frame = 1; $frame < 60; $frame++)
	{
		$bosses->update($registry, 1 / 60);
	}

	check($combat->hits === $hits, 'mercy must suppress repeated bomb and boss contact damage');
	$bosses->close();
	$rings->close();
});

test('boss components reject invalid health, timing, and projectile state', function() {
	rejects(fn() => new Boss(NAN, 0));
	rejects(fn() => new Boss(0, 0, width: 0));
	rejects(fn() => new Boss(0, 0, maxHitPoints: 0));
	rejects(fn() => new MiniBoss(bombInterval: 0));
	rejects(fn() => new MiniBoss(hitCooldown: -1));
	rejects(fn() => new SpikeBomb(0, 0, 0, 0, 0));
	rejects(fn() => new SpikeBomb(1, 0, 0, 0, 0, radius: 0));
	rejects(fn() => new BossExplosion(0));
});
