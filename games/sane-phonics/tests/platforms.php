<?php
use PhpWasmEngine\Ecs\Registry;
use PhpWasmEngine\Gameplay\MovingPlatform;
use PhpWasmEngine\Gameplay\PlatformOscillation;
use PhpWasmEngine\Gameplay\PlatformPath;
use PhpWasmEngine\Gameplay\Platforms;
use PhpWasmEngine\Physics\Body;
use PhpWasmEngine\Physics\MapTerrain;
use PhpWasmEngine\Physics\Platformer;
use PhpWasmEngine\Physics\Terrain;
use PhpWasmEngine\Render\Sprite;
use PhpWasmEngine\Tiled\Loader;

test('floating platforms settle under weight and recover without moving departed riders', function() {
	$registry = new Registry;
	$terrain = new Terrain;
	$platforms = new Platforms($registry, $terrain);
	$platform = new MovingPlatform(50, 116, settle: 8);
	$entity = $platforms->add($platform, new Sprite(1));
	$body = new Body(50, 82);
	$body->grounded = true;
	$registry->create($body);
	$movement = new Platformer($terrain);

	for($i = 0; $i < 60; $i++)
	{
		tick($movement, $body);
		$platforms->update($registry, 1 / 60);
		near($body->supportY(), $platform->top(), .01);
		check($body->grounded);
	}

	near($platform->settlement, 8);
	near($platform->y, 124);
	near($registry->get($entity, Sprite::class)->y, 124);
	tick($movement, $body, ['jump' => true]);
	check(!$body->grounded && $body->jumping);
	$y = $body->y;
	$platforms->update($registry, 1 / 60);
	near($body->y, $y);

	for($i = 0; $i < 60; $i++)
	{
		$platforms->update($registry, 1 / 60);
	}

	near($platform->settlement, 0);
	near($platform->y, 116);
	$shape = $platform->shape;
	$platforms->close();
	$platforms->close();
	check(!$terrain->hasShape($shape) && !$registry->alive($entity));
});

test('squine timing matches reference samples and is independent of simulation frequency', function() {
	$wave = new PlatformOscillation(160);

	foreach([0, 45, 90, 135, 180, 270, 360] as $frame)
	{
		$wave->time = $frame / 60;
		[$x, $y] = $wave->offset();
		near($x, -floor((cos(sin($frame / 90) ** 5) ** (5 * 3.333)) * 160 + .5));
		near($y, 0);
	}

	$states = [];

	foreach([30, 60, 120] as $hz)
	{
		$registry = new Registry;
		$terrain = new Terrain;
		$platforms = new Platforms($registry, $terrain);
		$platform = new MovingPlatform(300, 116);
		$platforms->add($platform, motion: new PlatformOscillation(160));
		$body = new Body($platform->x, $platform->top() - 18);
		$body->grounded = true;
		$registry->create($body);

		for($i = 0; $i < $hz * 2; $i++)
		{
			$platforms->update($registry, 1 / $hz);
			near($body->x, $platform->x);
			near($body->supportY(), $platform->top());
		}

		$states[] = [$platform->x, $platform->y, $body->x, $body->y];
	}

	check($states[0] === $states[1] && $states[1] === $states[2]);
	$ellipse = new PlatformOscillation(80, 40, timeX: 2, timeY: 2);
	$ellipse->time = M_PI;
	[$x, $y] = $ellipse->offset();
	near($x, -80);
	near($y, 0);
});

test('linked paths spend remaining distance around corners, loop, and stop at terminal nodes', function() {
	$registry = new Registry;
	$terrain = new Terrain;
	$platforms = new Platforms($registry, $terrain);
	$platform = new MovingPlatform(0, 116);
	$path = new PlatformPath([[0, 116], [20, 116], [20, 136], [0, 136]], 100, 0);
	$platforms->add($platform, motion: $path);
	$body = new Body(0, 82);
	$body->grounded = true;
	$registry->create($body);
	$platforms->update($registry, .5);
	near($platform->x, 10);
	near($platform->y, 136);
	near($body->x, 10);
	near($body->supportY(), 120);
	$platforms->update($registry, .5);
	near($platform->x, 20);
	near($platform->y, 116);

	$registry->remove($platform->entity, PlatformPath::class);
	$terminal = new PlatformPath([[20, 116], [40, 96]], 100);
	$registry->add($platform->entity, $terminal);
	$platforms->update($registry, 1);
	near($platform->x, 40);
	near($platform->y, 96);
	check($terminal->finished);
	$platforms->update($registry, 1);
	near($platform->x, 40);

	$zero = new PlatformPath([[40, 96], [40, 96]], 100, 0);
	$registry->add($platform->entity, $zero);
	$platforms->update($registry, 1);
	near($platform->x, 40);
});

test('rider paths wait for passengers and release on jumping, drop-through, walking off, and other planes', function() {
	$registry = new Registry;
	$terrain = new Terrain;
	$platforms = new Platforms($registry, $terrain);
	$platform = new MovingPlatform(0, 116, collisionLayer: 2);
	$platforms->add($platform, motion: new PlatformPath([[0, 116], [200, 116]], 120, rideOnly: true));
	$body = new Body(0, 82);
	$body->grounded = true;
	$registry->create($body);
	$platforms->update($registry, 1 / 60);
	near($platform->x, 0);
	$body->collisionLayer = 2;
	$platforms->update($registry, 1 / 60);
	near($platform->x, 2);
	near($body->x, 2);

	tick(new Platformer($terrain), $body, ['down' => true, 'jump' => true]);
	check(!$body->grounded && $body->dropTimer > 0);
	$x = $body->x;
	$platforms->update($registry, 1 / 60);
	near($body->x, $x);
	near($platform->x, 2);

	$body->grounded = true;
	$body->dropTimer = 0;
	$body->y = 82;
	$body->x = 200;
	$platforms->update($registry, 1 / 60);
	near($platform->x, 2);
	near($body->x, 200);
});

test('fast platforms sweep riders and stop at thin walls or ceilings without crushing', function() {
	foreach([[100, 116], [0, -84]] as [$x, $y])
	{
		$registry = new Registry;
		$terrain = new Terrain;

		if($x > 0)
		{
			$terrain->add([[50, -100], [51, -100], [51, 100], [50, 100]]);
		}
		else
		{
			$terrain->add([[-100, 39], [100, 39], [100, 40], [-100, 40]]);
		}

		$platforms = new Platforms($registry, $terrain);
		$platform = new MovingPlatform(0, 116);
		$platforms->add($platform, motion: new PlatformPath([[0, 116], [$x, $y]], 12000));
		$body = new Body(0, 82);
		$body->grounded = true;
		$registry->create($body);
		$platforms->update($registry, 1 / 60);
		near($body->supportY(), $platform->top(), .01);
		check($terrain->raycasts > 0);

		if($x > 0)
		{
			check($body->x + $body->radius <= 50 && $body->x > 40);
			near($platform->x, $body->x);
		}
		else
		{
			check($body->y - $body->halfHeight >= 40 && $body->y < 60);
		}
	}
});

test('moving solid faces push stationary bodies and rising one-way supports catch falling bodies', function() {
	foreach([false, true] as $oneWay)
	{
		$registry = new Registry;
		$terrain = new Terrain;
		$platforms = new Platforms($registry, $terrain);
		$platform = new MovingPlatform(0, 116, 32, 16, $oneWay);
		$destination = $oneWay ? [0, -84] : [200, 116];
		$platforms->add($platform, motion: new PlatformPath([[0, 116], $destination], 12000));
		$body = $oneWay ? new Body(0, 50) : new Body(80, 108);
		$registry->create($body);
		$platforms->update($registry, 1 / 60);

		if($oneWay)
		{
			check($body->grounded && !$body->jumping);
			near($body->supportY(), $platform->top(), .01);
		}
		else
		{
			check($body->x >= $platform->x + $platform->width / 2 + $body->radius - .01);
		}
	}
});

test('Tiled moving platforms resolve offsets, flips, links, and avoid static ghost colliders', function() {
	$loader = fixture([
		'/map.tmj' => mapData([
			[
				'type' => 'objectgroup'
				, 'offsetx' => 10
				, 'offsety' => 20
				, 'objects' => [
					[
						'id' => 1
						, 'class' => 'MovingPlatform'
						, 'gid' => 2147483649.0
						, 'x' => 0
						, 'y' => 116
						, 'width' => 32
						, 'height' => 16
						, 'properties' => [
							['name' => 'path', 'value' => 2]
							, ['name' => 'pathSpeed', 'value' => 1]
							, ['name' => 'solid', 'value' => true]
						]
					]
					, [
						'id' => 2
						, 'x' => 100
						, 'y' => 116
						, 'point' => true
						, 'properties' => [['name' => 'next', 'value' => 3]]
					]
					, [
						'id' => 3
						, 'x' => 160
						, 'y' => 116
						, 'point' => true
						, 'properties' => [['name' => 'next', 'value' => 2]]
					]
				]
			]
		])
		, '/tiles.tsj' => tiles()
	]);

	$map = $loader->map('/map.tmj');
	$map->x = 1000;
	$map->y = 2000;
	$terrain = MapTerrain::build($map);
	check(!$terrain->solidAt(1020, 2140));
	$registry = new Registry;
	$platforms = new Platforms($registry, $terrain, $map);
	$platform = reset($platforms->platforms);
	near($platform->x, 1110);
	near($platform->y, 2136);
	$sprite = $registry->get($platform->entity, Sprite::class);
	check($sprite->gid === 1 && $sprite->flipX && $sprite->outline);
	$platforms->update($registry, .5);
	near($platform->x, 1140);
	near($sprite->x, 1140);
	$platforms->update($registry, 1);
	near($platform->x, 1140);
	$shape = $platform->shape;
	$registry->destroy($platform->entity);
	$platforms->update($registry, 1 / 60);
	check(!$terrain->hasShape($shape) && !$platforms->platforms);
});

test('platform inputs reject invalid dimensions, clocks, nodes and partial Tiled construction', function() {
	rejects(fn() => new MovingPlatform(0, 0, width: 0));
	rejects(fn() => new MovingPlatform(INF, 0));
	rejects(fn() => new PlatformOscillation(period: 0));
	rejects(fn() => new PlatformPath([]));
	rejects(fn() => new PlatformPath([[0, 0]], loop: 1));
	rejects(fn() => new PlatformPath([[NAN, 0]]));
	$registry = new Registry;
	$terrain = new Terrain;

	$map = fixture([
		'/map.tmj' => mapData([
			[
				'type' => 'objectgroup'
				, 'objects' => [
					['id' => 1, 'class' => 'FloatingPlatform', 'x' => 0, 'y' => 100]
					, [
						'id' => 2
						, 'class' => 'PathPlatform'
						, 'x' => 0
						, 'y' => 100
						, 'properties' => [['name' => 'path', 'value' => 999]]
					]
				]
			]
		])
		, '/tiles.tsj' => tiles()
	])->map('/map.tmj');

	rejects(fn() => new Platforms($registry, $terrain, $map));
	check($registry->query(MovingPlatform::class) === []);
	check($terrain->raycast(20, 0, 0, 200) === null);
});

test('platform course exposes floating, squine, elliptical and linked rider routes as ECS entities', function() {
	$map = (new Loader)->map(dirname(__DIR__, 3) . '/examples/assets/platforms.tmj');
	$terrain = MapTerrain::build($map);
	$registry = new Registry;
	$platforms = new Platforms($registry, $terrain, $map);
	check(count($registry->query(MovingPlatform::class)) === 6);
	check(count($registry->query(PlatformOscillation::class)) === 3);
	check(count($registry->query(PlatformPath::class)) === 2);
	$body = new Body(80, 100);
	$registry->create($body);
	$movement = new Platformer($terrain);

	for($i = 0; $i < 120; $i++)
	{
		tick($movement, $body);
		$platforms->update($registry, 1 / 60);
	}

	$first = reset($platforms->platforms);
	check($first->sourceId === 100 && $first->weighted && $body->grounded);
	near($first->settlement, 8);
	near($body->supportY(), $first->top(), .01);
	$ids = $registry->query(MovingPlatform::class);
	$platforms->close();

	foreach($ids as $id)
	{
		check(!$registry->alive($id));
	}
});

test('platform GID transforms retain the centered bottom pivot for all eight Tiled flips', function() {
	foreach(range(0, 7) as $flags)
	{
		$map = fixture([
			'/map.tmj' => mapData([
				[
					'type' => 'objectgroup'
					, 'objects' => [
						[
							'id' => 1
							, 'class' => 'FloatingPlatform'
							, 'gid' => 1 + $flags * 536870912.0
							, 'x' => 20
							, 'y' => 100
							, 'width' => 64
							, 'height' => 16
						]
					]
				]
			])
			, '/tiles.tsj' => tiles()
		])->map('/map.tmj');

		$registry = new Registry;
		$platforms = new Platforms($registry, new Terrain, $map);
		$platform = reset($platforms->platforms);
		$sprite = $registry->get($platform->entity, Sprite::class);
		$xs = $ys = [];

		foreach([[0, 0], [1, 0], [1, 1], [0, 1]] as [$u, $v])
		{
			$localX = ($u - $sprite->originX) * 16 * $sprite->scaleX;
			$localY = ($v - $sprite->originY) * 16 * $sprite->scaleY;
			$xs[] = $sprite->x + $localX * cos($sprite->rotation) - $localY * sin($sprite->rotation);
			$ys[] = $sprite->y + $localX * sin($sprite->rotation) + $localY * cos($sprite->rotation);
		}

		near(min($xs), 20);
		near(max($xs), 84);
		near(min($ys), 84);
		near(max($ys), 100);
	}
});
