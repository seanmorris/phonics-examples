<?php
use PhpWasmEngine\Ecs\Registry;
use PhpWasmEngine\Gameplay\Ring;
use PhpWasmEngine\Gameplay\RingCounter;
use PhpWasmEngine\Gameplay\Rings;
use PhpWasmEngine\Gameplay\ScatteredRing;
use PhpWasmEngine\Physics\Body;
use PhpWasmEngine\Physics\MapTerrain;
use PhpWasmEngine\Render\Sprite;
use PhpWasmEngine\Tiled\Loader;
use PhpWasmEngine\Tiled\TileMap;

require_once dirname(__DIR__, 3) . '/examples/DemoPlayerHud.php';

test('annotated Tiled ring cells become owned animated sprite entities', function() {
	$map = (new Loader)->map(dirname(__DIR__, 3) . '/examples/assets/platformer.tmj');
	$layer = $map->layer('Markers');
	$expected = 0;

	foreach($layer->chunks as $chunk)
	{
		for($y = $chunk->y; $y < $chunk->y + $chunk->height; $y++)
		{
			for($x = $chunk->x; $x < $chunk->x + $chunk->width; $x++)
			{
				$gid = $chunk->gidAt($x, $y);

				if(($map->tile($gid->id)['class'] ?? '') === 'ring')
				{
					$expected++;
				}
			}
		}
	}

	$registry = new Registry;
	$rings = new Rings($registry, $map);
	$entities = $registry->query(Ring::class);
	check($expected === 23 && count($entities) === $expected);

	foreach($layer->chunks as $chunk)
	{
		for($y = $chunk->y; $y < $chunk->y + $chunk->height; $y++)
		{
			for($x = $chunk->x; $x < $chunk->x + $chunk->width; $x++)
			{
				check(($map->tile($chunk->gidAt($x, $y)->id)['class'] ?? '') !== 'ring');
			}
		}
	}

	$first = $entities[0];
	$ring = $registry->get($first, Ring::class);
	$sprite = $registry->get($first, Sprite::class);

	near($ring->x, 784);
	near($ring->y, 240);
	near($ring->radius, 12);
	check($sprite->gid === 5 && $sprite->animated && $sprite->x === $ring->x && $sprite->y === $ring->y);
	$rings->close();
	check($registry->query(Ring::class) === []);
	$rings->close();
});

test('ring sweeps collect at high speed without tunneling and ignore teleport paths', function() {
	$registry = new Registry;

	$map = new TileMap('empty.tmj', [
		'orientation' => 'orthogonal'
		, 'tilewidth' => 32
		, 'tileheight' => 32
		, 'height' => 1
	]);

	$rings = new Rings($registry, $map);
	$body = new Body(0, 0);
	$counter = new RingCounter;
	$player = $registry->create($body, $counter);
	$hit = $registry->create(new Ring(100, 0, 8, 2, 250), new Sprite(1));
	$miss = $registry->create(new Ring(100, 100), new Sprite(1));

	$rings->sync($player, $body);
	$body->x = 200;
	$rings->update($registry, 1 / 60);
	check(!$registry->alive($hit) && $registry->alive($miss));
	check($counter->rings === 2 && $counter->score === 250);

	$teleportedAcross = $registry->create(new Ring(300, 0), new Sprite(1));
	$body->x = 400;
	$rings->sync($player, $body);
	$rings->update($registry, 1 / 60);
	check($registry->alive($teleportedAcross) && $counter->rings === 2);
	$rings->close();
});

test('ring values and HUD rows validate and format gameplay state', function() {
	$counter = new RingCounter(7, 1234);
	$rows = DemoPlayerHud::rows($counter, 125.9);
	check($rows === ['SCORE:' => '  1234', 'TIME:' => '02:05', 'RINGS:' => '   7']);

	$counter->collect(new Ring(0, 0, value: 3, points: 100));
	check($counter->rings === 10 && $counter->score === 1334);
	check(DemoPlayerHud::rows($counter, -1)['TIME:'] === '00:00');
	rejects(fn() => new Ring(NAN, 0));
	rejects(fn() => new Ring(0, 0, radius: 0));
	rejects(fn() => new Ring(0, 0, value: 0));
	rejects(fn() => new RingCounter(-1));

	rejects(fn() => new Rings(new Registry, new TileMap('empty', [
		'orientation' => 'orthogonal'
		, 'tilewidth' => 1
		, 'tileheight' => 1
	]), ''
	));
});

test('damage rings scatter in bounded arcs, bounce continuously, delay pickup, and expire', function() {
	$map = (new Loader)->map(dirname(__DIR__, 3) . '/examples/assets/badniks.tmj');
	$registry = new Registry;
	$terrain = MapTerrain::build($map);
	$rings = new Rings($registry, $map, terrain: $terrain);
	$body = new Body(480, 400);
	$counter = new RingCounter(20);
	$player = $registry->create($body, $counter);
	$scattered = $rings->scatter($player, $body, $counter);
	check(count($scattered) === 16 && $counter->rings === 20);
	check($rings->scatteredCount() === 16);
	check(count(array_filter($scattered, fn($id) => $registry->get($id, ScatteredRing::class)->collectible)) === 11);

	$first = $scattered[0];
	$ring = $registry->get($first, Ring::class);
	$motion = $registry->get($first, ScatteredRing::class);
	$start = [$ring->x, $ring->y];
	$rings->update($registry, .05);
	check([$ring->x, $ring->y] === $start, 'launch delay must hold scattered rings in place');
	$rings->update($registry, .06);
	check([$ring->x, $ring->y] !== $start);

	$body->x = $body->y = 0;
	$rings->sync($player, $body);
	$motion->collectible = false;
	$motion->age = .2;
	$motion->launchDelay = 0;
	$motion->vx = 0;
	$motion->vy = 6000;
	$ring->x = 480;
	$ring->y = 400;
	$rings->update($registry, .02);
	check($ring->y <= 440.01 && $motion->vy < 0, 'a fast ring must bounce without crossing the floor');

	$recoverable = $scattered[1];
	$pickup = $registry->get($recoverable, Ring::class);
	$pickupMotion = $registry->get($recoverable, ScatteredRing::class);
	$pickupMotion->age = $pickupMotion->pickupDelay;
	$pickupMotion->launchDelay = 0;
	$pickupMotion->vx = $pickupMotion->vy = $pickupMotion->gravity = 0;
	$body->x = $pickup->x;
	$body->y = $pickup->y;
	$rings->sync($player, $body);
	$rings->update($registry, 1 / 60);
	check(!$registry->alive($recoverable) && $counter->rings === 21);

	$expiring = $scattered[2];
	$expiry = $registry->get($expiring, ScatteredRing::class);
	$expiry->age = $expiry->lifetime - .01;
	$rings->update($registry, .02);
	check(!$registry->alive($expiring));
	$rings->close();
});
