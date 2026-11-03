<?php
use PhpWasmEngine\Gameplay\BossRegion;
use PhpWasmEngine\Gameplay\BossRegions;
use PhpWasmEngine\Physics\Body;
use PhpWasmEngine\Render\Camera;
use PhpWasmEngine\Tiled\Layer;
use PhpWasmEngine\Tiled\Loader;
use PhpWasmEngine\Tiled\TileMap;

test('Tiled boss regions resolve target references, offsets, and legacy names', function() {
	$map = new TileMap('boss.tmj', [
		'orientation' => 'orthogonal'
		, 'tilewidth' => 16
		, 'tileheight' => 16
		, 'height' => 1
	]);

	$map->x = 100;
	$map->y = 200;
	$layer = new Layer(1, 'Boss', 'objectgroup');
	$layer->offsetX = 3;
	$layer->offsetY = 4;
	$layer->objects = [
		[
			'id' => 8
			, 'name' => 'boss-region'
			, 'x' => 20
			, 'y' => 50
			, 'width' => 640
			, 'height' => 320
			, 'properties' => [
				['name' => 'boss', 'type' => 'object', 'value' => 7]
				, ['name' => 'cameraAnchorY', 'type' => 'float', 'value' => .75]
			]
		]
	];

	$map->layers[] = $layer;
	$regions = BossRegions::fromMaps(fn($id) => $id === 7, $map);
	check(count($regions->regions) === 1);
	$region = $regions->regions[0];
	near($region->x, 123);
	near($region->y, 254);
	near($region->width, 640);
	near($region->height, 320);
	near($region->cameraAnchorY, .75);
	check($region->sourceId === 8 && $region->bossSourceId === 7);
});

test('boss locks activate on grounded entry and release when the target is defeated', function() {
	$alive = true;
	$region = new BossRegion(960, 0, 1280, 448, 3, 11);

	$regions = new BossRegions(function($id) use (&$alive) {
		return $id === 3 && $alive;
	}, $region
	);

	$body = new Body(800, 430);
	$body->grounded = true;
	$regions->sync($body);
	$body->x = 1000;
	check($regions->update($body) === $region);

	check($regions->snapshot($body) === [
		'active' => true
		, 'sourceId' => 11
		, 'bossSourceId' => 3
		, 'x' => 960.0
		, 'y' => 0.0
		, 'width' => 1280.0
		, 'height' => 448.0
	]);

	$camera = new Camera(-100, 0, 960, 540);
	check($regions->constrainCamera($body, $camera));
	near($camera->x, 960);
	$camera->x = 2000;
	$regions->constrainCamera($body, $camera);
	near($camera->x, 1280);

	$body->x = 2500;
	$body->vx = 900;
	$body->groundSpeed = 900;
	$regions->update($body);
	near($body->x, 2240);
	near($body->vx, 0);
	near($body->groundSpeed, 0);

	$body->grounded = false;
	$body->x = 900;
	$body->vx = -900;
	$regions->update($body);
	near($body->x, 960);
	near($body->vx, 0);

	$alive = false;
	$body->x = 2500;
	check($regions->update($body) === null);
	check(!$regions->snapshot($body)['active'] && $region->completed);
	near($body->x, 2500);
});

test('boss entry uses the full movement segment without trapping airborne pass-throughs', function() {
	$region = new BossRegion(100, 0, 200, 200, 1);
	$regions = new BossRegions(fn($id) => $id === 1, $region);
	$body = new Body(0, 100);
	$body->grounded = false;
	$regions->sync($body);
	$body->x = 400;
	check($regions->update($body) === null);
	near($body->x, 400);

	$body->x = 0;
	$body->grounded = true;
	$regions->sync($body);
	$body->x = 400;
	$body->vx = 60000;
	$body->groundSpeed = 60000;
	check($regions->update($body) === $region);
	near($body->x, 100);
	near($body->vx, 0);
	near($body->groundSpeed, 0);

	$reverse = new Body(400, 100);
	$reverse->grounded = true;
	$regions->sync($reverse);
	$reverse->x = 0;
	$reverse->vx = -60000;
	$reverse->groundSpeed = -60000;
	check($regions->update($reverse) === $region);
	near($reverse->x, 300);
	near($reverse->vx, 0);
	near($reverse->groundSpeed, 0);
});

test('boss regions validate shape, references, bounds, and narrow camera framing', function() {
	rejects(fn() => new BossRegion(0, 0, 0, 10, 1));
	rejects(fn() => new BossRegion(0, 0, 10, 10, 0));
	rejects(fn() => new BossRegion(0, 0, 10, 10, 1, cameraAnchorY: 2));

	$region = new BossRegion(100, 0, 200, 200, 1);
	$regions = new BossRegions(fn() => true, $region);
	$body = new Body(150, 100);
	$body->grounded = true;
	$regions->update($body);
	$camera = new Camera(0, 0, 500, 300);
	$regions->constrainCamera($body, $camera);
	near($camera->x, -50);

	$camera = new Camera(0, 0, 200, 100);
	$camera->follow(150, 100, 1, 1000, .5, .72);
	near($camera->x, 50);
	near($camera->y, 28);

	$map = (new Loader)->map(dirname(__DIR__, 3) . '/examples/assets/badniks.tmj');
	$loaded = BossRegions::fromMaps(fn($id) => $id === 3, $map);
	check(count($loaded->regions) === 1 && $loaded->regions[0]->sourceId === 11);
});
