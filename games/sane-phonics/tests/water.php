<?php
use PhpWasmEngine\Ecs\Registry;
use PhpWasmEngine\Gameplay\WaterFeatures;
use PhpWasmEngine\Gameplay\WaterParticle;
use PhpWasmEngine\Gameplay\WaterParticles;
use PhpWasmEngine\Input\Controls;
use PhpWasmEngine\Physics\Body;
use PhpWasmEngine\Physics\MapTerrain;
use PhpWasmEngine\Physics\Platformer;
use PhpWasmEngine\Physics\WaterLevel;
use PhpWasmEngine\Physics\WaterRegion;
use PhpWasmEngine\Physics\WaterRegions;
use PhpWasmEngine\Render\Sprite;
use PhpWasmEngine\Tiled\Loader;

test('Tiled water rectangles retain map offsets, legacy anchors, physics, and render settings', function() {
	$loader = fixture([
		'/map.tmj' => mapData(
			[
				[
					'id' => 2
					, 'name' => 'Water'
					, 'type' => 'objectgroup'
					, 'offsetx' => 3
					, 'offsety' => 5
					, 'objects' => [
						[
							'id' => 1
							, 'class' => 'WaterRegion'
							, 'x' => 10
							, 'y' => 20
							, 'width' => 100
							, 'height' => 80
							, 'properties' => [
								['name' => 'gravity','type' => 'float','value' => .3]
								, ['name' => 'drag','type' => 'float','value' => .75]
								, ['name' => 'density','type' => 'float','value' => 1.2]
								, ['name' => 'skimSpeed','type' => 'float','value' => 720]
								, ['name' => 'currentX','type' => 'float','value' => 12]
								, ['name' => 'color','type' => 'color','value' => '#804080c0']
								, ['name' => 'displacement','type' => 'float','value' => 6]
							]
						]
						, [
							'id' => 2
							, 'type' => 'region'
							, 'gid' => 1
							, 'x' => 200
							, 'y' => 300
							, 'width' => 64
							, 'height' => 96
						]
						, [
							'id' => 3
							, 'class' => 'Block'
							, 'x' => 300
							, 'y' => 300
							, 'width' => 32
							, 'height' => 32
							, 'properties' => [
								['name' => 'water','type' => 'object','value' => 1]
							]
						]
					]
				]
			]
			, ['x' => 40,'y' => 50]
		)
		, '/tiles.tsj' => tiles()
	]);

	$map = $loader->map('/map.tmj');
	$map->x = 40;
	$map->y = 50;
	$waters = WaterRegions::fromMaps($map);
	check(count($waters->regions) === 2);
	$first = $waters->regions[0];
	near($first->x, 53);
	near($first->y, 75);
	near($first->gravity, .3);
	near($first->drag, .75);
	near($first->density, 1.2);
	near($first->skimSpeed, 720);
	near($first->currentX, 12);
	near($first->tint[0], 64 / 255);
	near($first->tint[3], 128 / 255);
	near($first->displacement, 6);
	near($waters->regions[1]->y, 259);
});

test('water scales gravity, control, jump force, and optional buoyancy without sticky state', function() {
	$water = new WaterRegion(-200, -200, 400, 400, .4, .85, 1);
	$waters = new WaterRegions($water);
	$movement = new Platformer(ground(), waters: $waters);
	$body = new Body(0, 0);
	$body->gravity = 1800;
	tick($movement, $body, ['right' => true]);
	check($body->underwater && $body->waterEntered && !$body->waterExited);
	near($body->vx, 1080 * .85 / 60, .01);
	near($body->vy, 1800 * .4 / 60, .01);
	tick($movement, $body, ['right' => true]);
	check(!$body->waterEntered && !$body->waterExited);

	$floating = new Body(0, 0);
	$floating->density = .8;
	tick($movement, $floating);
	check($floating->underwater && $floating->waterDepth === 1.0 && $floating->vy < 0);

	$jumper = new Body(0, 82);
	$jumper->grounded = true;
	tick($movement, $jumper, ['jump' => true]);
	check($jumper->jumping && $jumper->vy < -500 && $jumper->vy > -570);

	$body->x = 500;
	tick($movement, $body);
	check(!$body->underwater && $body->waterExited && $body->water === null);
});

test('water currents and segment queries retain fast pass-through entry and exit edges', function() {
	$water = new WaterRegion(0, 0, 100, 100, currentX: 120, currentY: -60);
	$waters = new WaterRegions($water);
	$movement = new Platformer(new PhpWasmEngine\Physics\Terrain, waters: $waters);
	$body = new Body(50, 50);
	$body->gravity = 0;
	tick($movement, $body);
	near($body->vx, 2);
	near($body->vy, -1);

	$fast = new Body(-100, 50);
	$fast->gravity = 0;
	$fast->terminalSpeed = 30000;
	$fast->vx = 30000;
	tick($movement, $fast);
	check($fast->x > 100 && !$fast->underwater && $fast->waterEntered && $fast->waterExited);
	check($waters->crossed(-100, 50, 500, 0) === [$water]);
});

test('ground contact remains normal through water and on the exit frame', function() {
	$water = new WaterRegion(0, 0, 100, 100);
	$movement = new Platformer(ground(), waters: new WaterRegions($water));
	$body = new Body(-20, 82);
	$body->grounded = true;
	$body->groundSpeed = 600;
	$body->friction = 0;
	$body->gravity = 0;
	$wet = $exited = false;

	for($frame = 0; $frame < 30; $frame++)
	{
		tick($movement, $body);

		if($body->underwater)
		{
			$wet = true;
			check($body->grounded && !$body->grinding);
		}

		if($body->waterExited)
		{
			$exited = true;
			check($body->grounded && !$body->grinding && !$body->underwater);
		}
	}

	check($wet && $exited && $body->grounded && !$body->grinding);
});

test('the water demo aligns a deep pool surface with both dry land banks', function() {
	$map = (new Loader)->map(dirname(__DIR__, 3) . '/examples/assets/water.tmj');
	$terrain = MapTerrain::build($map);
	$waters = WaterRegions::fromMaps($map);
	$registry = new Registry;
	$features = new WaterFeatures($registry, $terrain, $map, $waters);
	$region = $waters->regions[0] ?? null;
	$left = $terrain->raycast(800, 400, 0, 100);
	$bottom = $terrain->raycast(1000, 400, 0, 220);
	$right = $terrain->raycast(1880, 400, 0, 100);

	check($region !== null && $left !== null && $bottom !== null && $right !== null);
	near($region->x, 896);
	near($region->y, 448);
	near($region->x + $region->width, 1792);
	near($left->y, $region->y);
	near($right->y, $region->y);
	near($bottom->y, 576);
	check($features->isEmpty() && $registry->query(PhpWasmEngine\Render\Sprite::class) === []);
	$features->close();
});

test('the underwater rail retains grinding through water and on exit before returning to land', function() {
	$map = (new Loader)->map(dirname(__DIR__, 3) . '/examples/assets/water-rail.tmj');
	$terrain = MapTerrain::build($map);
	$waters = WaterRegions::fromMaps($map);
	$movement = new Platformer($terrain, waters: $waters);
	$body = new Body(400, 430);
	$body->grounded = true;
	$body->groundSpeed = 960;
	$body->friction = 0;
	$body->gravity = 0;
	$rail = $wet = $exited = $land = false;
	$tileWidth = (int)$map->data['tilewidth'];
	$mapRight = (int)$map->data['width'] * $tileWidth;

	for($column = 0; $column < $map->data['width']; $column++)
	{
		$surface = $terrain->raycast(($column + .5) * $tileWidth, 400, 0, 80);
		check($surface !== null, "Water rail column $column has no visible top collision");
		near($surface->y, 448);
		check($surface->grinding === ($column >= 14 && $column < 56));
		check($surface->oneWay === ($column >= 14 && $column < 56));
	}

	for($frame = 0; $frame < 180; $frame++)
	{
		tick($movement, $body);
		$rail = $rail || ($body->x < 640 && $body->grinding);

		if($body->underwater)
		{
			$wet = true;
			check($body->grounded && $body->grinding);
		}

		if($body->waterExited)
		{
			$exited = true;
			check($body->grounded && $body->grinding && !$body->underwater);
		}

		if($body->x > $mapRight - 64)
		{
			$land = $body->grounded && !$body->grinding;

			break;
		}
	}

	check($rail && $wet && $exited && $land);
});

test('the surface rail transitions through skim grinding in both directions without side entry', function() {
	$map = (new Loader)->map(dirname(__DIR__, 3) . '/examples/assets/water-skim-rail.tmj');
	$terrain = MapTerrain::build($map);
	$waters = WaterRegions::fromMaps($map);
	$region = $waters->regions[0] ?? null;
	$tileWidth = (int)$map->data['tilewidth'];

	check($region !== null);
	near($region->x, 40 * $tileWidth);
	near($region->y, 14 * (int)$map->data['tileheight']);
	near($region->x + $region->width, 64 * $tileWidth);

	for($column = 0; $column < $map->data['width']; $column++)
	{
		$surface = $terrain->raycast(($column + .5) * $tileWidth, 400, 0, 80);
		$rail = ($column >= 8 && $column < 48) || ($column >= 56 && $column < 88);
		$land = $column < 8 || $column >= 88;

		if(!$rail && !$land)
		{
			check($surface === null, "Surface rail column $column must be supported only by water");

			continue;
		}

		check($surface !== null, "Surface rail column $column has no top collision");
		near($surface->y, $region->y);
		check($surface->grinding === $rail);
	}

	foreach([[1100, 960, 2200], [2200, -960, 1100]] as [$x,$speed,$destination])
	{
		$movement = new Platformer($terrain, waters: $waters);
		$body = new Body($x, $region->y - 18);
		$body->grounded = $body->grinding = true;
		$body->groundSpeed = $speed;
		$body->friction = $body->gravity = 0;
		$railOnly = $skimGrinding = $skimOnly = $railAgain = false;

		for($frame = 0; $frame < 180; $frame++)
		{
			tick($movement, $body);
			check(!$body->underwater && !$body->waterEntered && !$body->waterExited);
			near($body->supportY(), $region->y, .01);

			$insideWater = $body->x >= $region->x && $body->x <= $region->x + $region->width;
			$insideOpenWater = $body->x > 48 * $tileWidth && $body->x < 56 * $tileWidth;
			$insideOverlap = $insideWater && !$insideOpenWater;

			$railOnly = $railOnly || (!$insideWater && $body->grinding && !$body->skimming);
			$skimGrinding = $skimGrinding || ($insideOverlap && $body->grinding && $body->skimming);
			$skimOnly = $skimOnly || ($insideOpenWater && !$body->grinding && $body->skimming);

			if(($speed > 0 && $body->x > $region->x + $region->width)
				|| ($speed < 0 && $body->x < $region->x)
			){
				$railAgain = $body->grinding && !$body->skimming;
			}

			if(($speed > 0 && $body->x >= $destination) || ($speed < 0 && $body->x <= $destination))
			{
				break;
			}
		}

		check($railOnly && $skimGrinding && $skimOnly && $railAgain);
	}
});

test('water levels retain a fixed bottom and use separate fill and drain rates', function() {
	$region = new WaterRegion(0, 320, 100, 320, sourceId: 10);
	$level = new WaterLevel($region, 64, 128);
	$level->setTarget(256);
	check($level->update(.5));
	near($region->y, 288);
	near($region->height, 352);
	$level->update(.5);
	near($region->y, 256);
	near($region->y + $region->height, 640);
	$level->setTarget(384);
	$level->update(.5);
	near($region->y, 320);
	near($region->y + $region->height, 640);
});

test('water switches let jumps pass upward and retain a level solid top at the edge', function() {
	$map = (new Loader)->map(dirname(__DIR__, 3) . '/examples/assets/water-loop.tmj');
	$terrain = MapTerrain::build($map);
	$waters = WaterRegions::fromMaps($map);
	$registry = new Registry;
	$features = new WaterFeatures($registry, $terrain, $map, $waters);
	$switch = $features->switches[101];
	$body = new Body($switch->x - 4, $switch->y - 18);
	$body->grounded = true;
	$registry->create($body);
	$movement = new Platformer($terrain, waters: $waters);
	$passedAbove = false;

	check($switch->shape !== null && $terrain->hasShape($switch->shape));
	$features->update($registry, 1 / 60);
	check($features->activeSwitch === null);

	for($frame = 0; $frame < 180 && !$switch->active; $frame++)
	{
		tick($movement, $body, $frame === 0?['jump' => true]:[]);
		$features->update($registry, 1 / 60);
		$passedAbove = $passedAbove || $body->supportY() < $switch->top() - 1;
	}

	check($passedAbove && $switch->active && $features->activeSwitch === 101);
	check($body->grounded && !$body->jumping);
	near($body->supportY(), $switch->top(), .01);

	for($frame = 0; $frame < 3; $frame++)
	{
		tick($movement, $body);
		$features->update($registry, 1 / 60);
	}

	check($body->grounded);
	near($body->angle, 0, .001);
	near($body->x, $switch->x - 4, .001);
	near($body->supportY(), $switch->top(), .01);
	$maxAngle = 0;

	for($frame = 0; $frame < 30; $frame++)
	{
		tick($movement, $body, ['left' => true]);
		$features->update($registry, 1 / 60);
		$maxAngle = max($maxAngle, abs($body->angle));
	}

	near($maxAngle, 0, .001);
	check($body->grounded && $body->x + $body->radius < $switch->x);
	near($body->supportY(), $switch->y, .01);
	check($features->snapshot()['switches'][101] === ['active' => true,'top' => 440.0]);
	$shape = $switch->shape;
	$features->close();
	check($shape !== null && !$terrain->hasShape($shape));
});

test('off-center switch landings preserve horizontal momentum across the edge', function() {
	$map = (new Loader)->map(dirname(__DIR__, 3) . '/examples/assets/water-loop.tmj');
	$terrain = MapTerrain::build($map);
	$waters = WaterRegions::fromMaps($map);
	$registry = new Registry;
	$features = new WaterFeatures($registry, $terrain, $map, $waters);
	$switch = $features->switches[101];
	$body = new Body($switch->x - 20, $switch->top() - 60);
	$body->vx = 180;
	$body->vy = 120;
	$registry->create($body);
	$movement = new Platformer($terrain, waters: $waters);

	for($frame = 0; $frame < 120 && !$body->grounded; $frame++)
	{
		tick($movement, $body);
		$features->update($registry, 1 / 60);
	}

	check($switch->active && $body->grounded);
	check(abs($body->x - ($switch->x + $switch->width / 2)) > 4);
	near($body->groundSpeed, 180, .01);
	near($body->vx, 180, .01);
	near($body->angle, 0, .001);
	near($body->supportY(), $switch->top(), .01);
	$maxAngle = 0;

	for($frame = 0; $frame < 30; $frame++)
	{
		tick($movement, $body, ['right' => true]);
		$features->update($registry, 1 / 60);
		$maxAngle = max($maxAngle, abs($body->angle));
	}

	near($maxAngle, 0, .001);
	check($body->grounded && $body->x - $body->radius > $switch->x + $switch->width);
	near($body->supportY(), $switch->y, .01);
	$features->close();
});

test('the rising-water loop retains object 3958 and moves its buoyant solids with switches', function() {
	$map = (new Loader)->map(dirname(__DIR__, 3) . '/examples/assets/water-loop.tmj');
	$terrain = MapTerrain::build($map);
	$waters = WaterRegions::fromMaps($map);
	$registry = new Registry;
	$features = new WaterFeatures($registry, $terrain, $map, $waters);
	$region = $waters->bySourceId(10);
	$door = $features->platforms[3958] ?? null;
	$platform = $features->platforms[120] ?? null;
	$data = json_decode(file_get_contents(dirname(__DIR__, 3) . '/examples/assets/water-loop.tmj'), true);
	$firstLoopGid = $data['tilesets'][1]['firstgid'];
	$loopRows = [];

	foreach($data['layers'] as $layer)
	{
		if($layer['type'] !== 'tilelayer')
		{
			continue;
		}

		foreach($layer['data'] as $index => $gid)
		{
			if($gid >= $firstLoopGid)
			{
				$loopRows[] = intdiv($index, $data['width']);
			}
		}
	}

	check($region !== null && $door !== null && $platform !== null);
	check(count($waters->regions) === 1);
	near($region->y, 448);
	near($region->y + $region->height, 640);
	check(min($loopRows) === 10 && max($loopRows) === 17);
	near($door->width, 32);
	near($door->height, 160);
	near($door->density, .8);
	check($door->horizontalLock);
	check(count($features->switches) === 3);
	check(count($registry->query(PhpWasmEngine\Gameplay\WaterSwitch::class)) === 3);
	check(count($registry->query(PhpWasmEngine\Gameplay\BuoyantPlatform::class)) === 2);
	check($terrain->hasShape($features->switches[101]->shape));
	check($terrain->hasShape($features->switches[102]->shape));
	check($terrain->hasShape($features->switches[103]->shape));
	check($terrain->hasShape($door->shape) && $terrain->hasShape($platform->shape));
	$switchShapes = array_map(fn($switch) => $switch->shape, $features->switches);
	$doorEntity = $door->entity;
	$platformEntity = $platform->entity;

	$features->activate(103);

	for($frame = 0; $frame < 240; $frame++)
	{
		$features->update($registry, 1 / 60);
	}

	near($region->y, 384);
	near($region->y + $region->height, 640);
	near($door->y, 512, .05);
	near($platform->y, 400, .05);
	check($features->snapshot()['activeSwitch'] === 103);
	$doorShape = $door->shape;
	$platformShape = $platform->shape;
	$features->close();

	foreach($switchShapes as $shape)
	{
		check(!$terrain->hasShape($shape));
	}

	check(!$terrain->hasShape($doorShape) && !$terrain->hasShape($platformShape));
	check(!$registry->alive($doorEntity) && !$registry->alive($platformEntity));
});

test('charged spindashes traverse the submerged loop without skimming off its walls', function() {
	$map = (new Loader)->map(dirname(__DIR__, 3) . '/examples/assets/water-loop.tmj');
	$terrain = MapTerrain::build($map);

	foreach([60, 120] as $frequency)
	foreach([384, 448, 512] as $surface)
	foreach([1, -1] as $direction)
	{
		$waters = WaterRegions::fromMaps($map);
		$waters->regions[0]->setSurface($surface);
		$movement = new Platformer($terrain, waters: $waters);

		$body = new Body($direction > 0 ? 736 : 1056, 558);
		$body->grounded = true;
		$body->facing = $body->direction = $direction;
		$body->collisionLayer = $direction > 0 ? 1 : 2;
		$controls = new Controls;
		$context = "frequency=$frequency surface=$surface direction=$direction";

		for($frame = 0; $frame < $frequency; $frame++)
		{
			$controls->set(['down' => true, 'action' => true]);
			$movement->step($body, $controls, 1 / $frequency);
		}

		check($body->charge > .99 && $body->underwater, $context);
		$turn = $previousAngle = 0;
		$exited = $entered = false;
		$crownLayer = null;

		for($frame = 0; $frame < $frequency * 2; $frame++)
		{
			$controls->set([]);
			$movement->step($body, $controls, 1 / $frequency);
			$delta = atan2(sin($body->angle - $previousAngle), cos($body->angle - $previousAngle));
			$turn += $delta;
			$previousAngle = $body->angle;

			check(!$body->skimming, "Wall contact became a water skim: $context frame=$frame");
			check($body->grounded && $body->rolling, "Lost rolling contact: $context frame=$frame");
			check(abs($delta) < .35, "Surface angle snapped: $context frame=$frame");
			$exited = $exited || $body->waterExited;
			$entered = $entered || ($exited && $body->waterEntered);

			if(($direction > 0 ? $body->x < 880 : $body->x > 912) && $body->y < 378)
			{
				$crownLayer = $body->collisionLayer;
			}

			if($direction > 0 ? $body->x > 1100 : $body->x < 700)
			{
				break;
			}
		}

		check($frame < $frequency * 2 && $exited && $entered && $body->underwater, $context);
		check($crownLayer === ($direction > 0 ? 1 : 2), $context);
		check($body->collisionLayer === ($direction > 0 ? 2 : 1), $context);
		check($body->groundSpeed * $direction > 900, $context);
		near($turn, -$direction * 2 * M_PI, .01);
		near($body->supportY(), 576, .01);
	}
});

test('losing wall or ceiling support near water does not become a floor skim', function() {
	$waters = new WaterRegions(new WaterRegion(0, 100, 500, 300));
	$movement = new Platformer(new PhpWasmEngine\Physics\Terrain, waters: $waters);

	foreach([[M_PI / 2, 1000], [-M_PI / 2, -1000], [M_PI, 1000]] as [$angle, $speed])
	{
		$body = new Body(50, 82);
		$body->angle = $angle;
		$body->groundSpeed = $speed;
		$body->grounded = true;
		$body->gravity = $body->friction = 0;
		tick($movement, $body);

		check(!$body->grounded && !$body->skimming);
		near($body->vx, cos($angle) * $speed);
		near($body->vy, sin($angle) * $speed);
	}
});

test('only grounded fast movement starts skimming while airborne and slow falls enter water', function() {
	$waters = new WaterRegions(new WaterRegion(0, 100, 500, 300, skimSpeed: 900));
	$movement = new Platformer(new PhpWasmEngine\Physics\Terrain, waters: $waters);
	$fast = new Body(50, 82);
	$fast->gravity = 0;
	$fast->grounded = true;
	$fast->groundSpeed = 1000;
	$fast->vx = 1000;
	$supportY = $fast->supportY();
	tick($movement, $fast);
	check($fast->skimming && $fast->grounded && !$fast->underwater);
	near($fast->y + $fast->halfHeight, 100, .01);
	near($fast->supportY(), $supportY, .01);
	near($fast->vy, 0);
	tick($movement, $fast);
	check($fast->skimming && $fast->grounded);
	near($fast->supportY(), $supportY, .01);
	$fast->groundSpeed = $fast->vx = 800;
	tick($movement, $fast);
	check(!$fast->skimming && !$fast->grounded);
	near($fast->supportY(), $supportY, .01);

	$airborne = new Body(50, 81);
	$airborne->gravity = 0;
	$airborne->vx = 1000;
	$airborne->vy = 120;
	tick($movement, $airborne);
	check(!$airborne->skimming && !$airborne->grounded && $airborne->y > 82);

	$slow = new Body(50, 81);
	$slow->gravity = 0;
	$slow->vx = 800;
	$slow->vy = 120;
	tick($movement, $slow);
	check(!$slow->skimming && !$slow->grounded && $slow->y > 82);
	tick($movement, $slow, [], 10);
	check($slow->underwater);
});

test('water particles animate surface crossings and retain one directional skim spray per body', function() {
	$map = (new Loader)->map(dirname(__DIR__, 3) . '/examples/assets/water.tmj');
	$waters = WaterRegions::fromMaps($map);
	$region = $waters->regions[0];
	$registry = new Registry;
	$body = new Body(1000, 400);
	$bodyId = $registry->create($body);
	$particles = new WaterParticles($registry, $map, $waters);
	$particles->update($registry, 1 / 60);
	$body->y = 500;
	$body->water = $region;
	$body->underwater = $body->waterEntered = true;
	$particles->update($registry, 1 / 60);
	$ids = $registry->query(WaterParticle::class);

	check(count($ids) === 1);
	$splashId = $ids[0];
	$splash = $registry->get($splashId, WaterParticle::class);
	$splashSprite = $registry->get($splashId, Sprite::class);
	check($splash->kind === WaterParticle::SPLASH && $splash->body === $bodyId);
	near($splashSprite->x, 1000);
	near($splashSprite->y, $region->y + 2);
	check($splashSprite->outline && $map->tile($splashSprite->gid)['class'] === 'water-splash');

	$body->waterEntered = $body->underwater = false;
	$body->water = null;
	$body->y = $region->y - $body->halfHeight - .001;
	$body->grounded = $body->skimming = true;
	$body->groundSpeed = 1000;
	$particles->update($registry, 1 / 60);
	$snapshot = $particles->snapshot();
	check($snapshot === ['splashes' => 1,'skims' => 1,'total' => 2]);

	$skimId = array_values(
		array_filter(
			$registry->query(WaterParticle::class)
			, fn($id) => $registry->get($id, WaterParticle::class)->kind === WaterParticle::SKIM
		)
	)[0];

	$skimSprite = $registry->get($skimId, Sprite::class);
	near($skimSprite->x, $body->x - 24);
	near($skimSprite->y, $region->y + 2);
	check(!$skimSprite->flipX && $skimSprite->outline && $skimSprite->scaleX === 1.5);

	$body->x = 1100;
	$body->groundSpeed = -1000;
	$particles->update($registry, 1 / 60);
	check($registry->query(WaterParticle::class) === [$splashId,$skimId]);
	near($skimSprite->x, $body->x + 24);
	check($skimSprite->flipX);

	$body->skimming = false;
	$particles->update($registry, 1 / 60);
	check(!$registry->alive($skimId));
	$particles->update($registry, .4);
	check(!$registry->alive($splashId) && $particles->snapshot()['total'] === 0);
	$scripted = $particles->splash(1000, $region->y);
	check($registry->alive($scripted));
	$particles->close();
	check(!$registry->alive($scripted));
});

test('water regions reject shapes and invalid physical or shader parameters', function() {
	rejects(fn() => new WaterRegion(0, 0, 0, 1));
	rejects(fn() => new WaterRegion(0, 0, 1, 1, drag: 2));
	rejects(fn() => new WaterRegion(0, 0, 1, 1, tint: [1,1,1]));
	rejects(fn() => new WaterRegion(0, 0, 1, 1, tint: [1,1,1,2]));

	$loader = fixture([
		'/map.tmj' => mapData([
			[
				'id' => 1
				, 'name' => 'Water'
				, 'type' => 'objectgroup'
				, 'objects' => [
					[
						'id' => 1
						, 'class' => 'Water'
						, 'x' => 0
						, 'y' => 0
						, 'width' => 10
						, 'height' => 10
						, 'rotation' => 10
					]
				]
			]
		])
		, '/tiles.tsj' => tiles()
	]);

	rejects(fn() => WaterRegions::fromMaps($loader->map('/map.tmj')));
});
