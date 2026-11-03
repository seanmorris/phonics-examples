<?php
use PhpWasmEngine\Ecs\Registry;
use PhpWasmEngine\Gameplay\BendableBridge;
use PhpWasmEngine\Gameplay\BendableBridges;
use PhpWasmEngine\Physics\Body;
use PhpWasmEngine\Physics\MapTerrain;
use PhpWasmEngine\Physics\Platformer;
use PhpWasmEngine\Physics\Terrain;
use PhpWasmEngine\Render\SpriteGroup;

test('bendable bridges sum several rider loads while retaining fixed seamless endpoints', function() {
	$bridge = new BendableBridge(0, 100, 160, droop: 20, segmentWidth: 20);
	$one = $bridge->targets([40]);
	$two = $bridge->targets([40, 120]);

	check($two[2] > $one[2]);
	check($two[6] > $one[6]);
	near($two[0], 0);
	near($two[8], 0);
	near($two[1] - $two[0], 0, 3);
	near($two[8] - $two[7], 0, 3);
});

test('every grounded body deforms and follows one live bridge collider', function() {
	$registry = new Registry;
	$terrain = new Terrain;
	$bridges = new BendableBridges($registry, $terrain);
	$bridge = new BendableBridge(0, 100, 160, droop: 16, segmentWidth: 16);
	$entity = $bridges->add($bridge);
	$bodies = [new Body(40, 82), new Body(120, 82)];
	$bodyIds = [];

	foreach($bodies as $body)
	{
		$body->grounded = true;
		$bodyIds[] = $registry->create($body);
	}

	for($i = 0; $i < 90; $i++)
	{
		$bridges->update($registry, 1 / 60);
	}

	check(count($bridge->riders) === 2);
	check($bridge->surfaceY(40) > 110 && $bridge->surfaceY(120) > 110);

	foreach($bodies as $body)
	{
		near($body->supportY(), $bridge->surfaceY($body->supportX()), .01);
	}

	$bodies[0]->x = -20;
	$bodies[0]->y = 82;
	$bodies[0]->angle = 0;

	for($i = 0; $i < 30; $i++)
	{
		$bridges->update($registry, 1 / 60);
	}

	check($bridge->riders === [$bodyIds[1]]);
	near($bodies[1]->supportY(), $bridge->surfaceY($bodies[1]->supportX()), .01);

	$shape = $bridge->shape;
	$registry->destroy($entity);
	$bridges->update($registry, 1 / 60);
	check(!$terrain->hasShape($shape) && !$bridges->bridges);
});

test('bridge endpoints transfer a running body to ordinary ground without a collision seam', function() {
	$registry = new Registry;
	$terrain = new Terrain;
	$terrain->add([[-160, 100], [0, 100], [0, 160], [-160, 160]]);
	$terrain->add([[160, 100], [320, 100], [320, 160], [160, 160]]);
	$bridges = new BendableBridges($registry, $terrain);
	$bridge = new BendableBridge(0, 100, 160, droop: 20, segmentWidth: 8);
	$bridges->add($bridge);
	$body = new Body(-40, 82);
	$body->grounded = true;
	$body->groundSpeed = 240;
	$registry->create($body);
	$movement = new Platformer($terrain);
	$controls = new \PhpWasmEngine\Input\Controls;
	$controls->set(['right' => 1]);
	$airborneAtSeam = false;

	for($i = 0; $i < 45; $i++)
	{
		$movement->step($body, $controls, 1 / 60);
		$bridges->update($registry, 1 / 60);

		if((abs($body->x) < 10 || abs($body->x - 160) < 10) && !$body->grounded)
		{
			$airborneAtSeam = true;
		}
	}

	check(!$airborneAtSeam);
	check($body->x > 200 && $body->grounded);
	near($body->supportY(), 100, .02);
});

test('bendable rails retain grinding metadata on their deformed continuous surface', function() {
	$registry = new Registry;
	$terrain = new Terrain;
	$bridges = new BendableBridges($registry, $terrain);
	$bridge = new BendableBridge(0, 100, 200, droop: 12, segmentWidth: 10, grinding: true);
	$bridges->add($bridge);
	$body = new Body(100, 82);
	$body->grounded = true;
	$body->grinding = true;
	$registry->create($body);

	for($i = 0; $i < 60; $i++)
	{
		$bridges->update($registry, 1 / 60);
	}

	$hit = $terrain->raycast(100, 50, 0, 100);
	check($hit !== null && $hit->shape === $bridge->shape && $hit->grinding);
	check($body->grinding && $bridge->surfaceY(100) > 108);
});

test('Tiled bridge objects become segmented ECS surfaces without static ghost geometry', function() {
	$loader = fixture([
		'/map.tmj' => mapData([
			[
				'type' => 'objectgroup'
				, 'offsetx' => 10
				, 'offsety' => 20
				, 'objects' => [
					[
						'id' => 7
						, 'class' => 'BendableRail'
						, 'gid' => 1
						, 'x' => 30
						, 'y' => 100
						, 'width' => 64
						, 'height' => 16
						, 'properties' => [
							['name' => 'droop', 'value' => 24]
							, ['name' => 'segmentWidth', 'value' => 8]
						]
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
	check($terrain->raycast(1040, 2000, 0, 200) === null);
	$registry = new Registry;
	$bridges = new BendableBridges($registry, $terrain, $map);
	$bridge = reset($bridges->bridges);
	near($bridge->x, 1040);
	near($bridge->y, 2104);
	check($bridge->grinding && $bridge->sourceId === 7 && $bridge->segments === 8);
	$group = $registry->get($bridge->entity, SpriteGroup::class);
	check(count($group->sprites) === 8);
	check($terrain->raycast(1040, 2080, 0, 40)?->shape === $bridge->shape);
	$body = new Body(1072, 2086);
	$body->grounded = true;
	$registry->create($body);

	for($i = 0; $i < 60; $i++)
	{
		$bridges->update($registry, 1 / 60);
	}

	$scales = [];

	foreach($group->sprites as $sprite)
	{
		$scales[] = $sprite->scaleX;
	}

	check(max($scales) > .51);
	$bridges->close();
	check(!$registry->alive($bridge->entity));
});

test('bridge demo aligns two flexible decks and one rail with ordinary ground', function() {
	$map = (new \PhpWasmEngine\Tiled\Loader)->map(dirname(__DIR__, 3) . '/examples/assets/bridges.tmj');
	$terrain = MapTerrain::build($map);
	$registry = new Registry;
	$bridges = new BendableBridges($registry, $terrain, $map);
	check(count($bridges->bridges) === 3);
	$spans = array_values($bridges->bridges);
	check(!$spans[0]->grinding && !$spans[1]->grinding && $spans[2]->grinding);

	foreach($spans as $bridge)
	{
		near($bridge->y, 448);
		check($terrain->raycast($bridge->x - 1, 428, 0, 40) !== null);
		check($terrain->raycast($bridge->x + 1, 428, 0, 40)?->shape === $bridge->shape);
		check($terrain->raycast($bridge->x + $bridge->width - 1, 428, 0, 40)?->shape === $bridge->shape);
		check($terrain->raycast($bridge->x + $bridge->width + 1, 428, 0, 40) !== null);
	}
});

test('bridge inputs reject invalid dimensions, clocks, and rotated Tiled objects', function() {
	rejects(fn() => new BendableBridge(0, 0, 0));
	rejects(fn() => new BendableBridge(0, 0, 100, response: 0));
	rejects(fn() => new BendableBridge(NAN, 0, 100));

	$map = fixture([
		'/map.tmj' => mapData([
			[
				'type' => 'objectgroup'
				, 'objects' => [
					[
						'id' => 1
						, 'class' => 'BendableBridge'
						, 'x' => 0
						, 'y' => 100
						, 'width' => 100
						, 'height' => 16
						, 'rotation' => 5
					]
				]
			]
		])
		, '/tiles.tsj' => tiles()
	])->map('/map.tmj');

	rejects(fn() => new BendableBridges(new Registry, new Terrain, $map));
});
