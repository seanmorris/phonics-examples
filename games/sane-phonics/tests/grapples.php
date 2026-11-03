<?php
use PhpWasmEngine\Ecs\Registry;
use PhpWasmEngine\Gameplay\GrappleGrip;
use PhpWasmEngine\Gameplay\GrapplePoint;
use PhpWasmEngine\Gameplay\Grapples;
use PhpWasmEngine\Gameplay\MovingPlatform;
use PhpWasmEngine\Gameplay\PlatformCamera;
use PhpWasmEngine\Gameplay\Platforms;
use PhpWasmEngine\Gameplay\Rope;
use PhpWasmEngine\Gameplay\RopeAnchor;
use PhpWasmEngine\Input\Controls;
use PhpWasmEngine\Physics\Body;
use PhpWasmEngine\Physics\MapTerrain;
use PhpWasmEngine\Physics\Platformer;
use PhpWasmEngine\Physics\Terrain;
use PhpWasmEngine\Render\Camera;
use PhpWasmEngine\Render\Sprite;
use PhpWasmEngine\Render\SpriteGroup;
use PhpWasmEngine\Tiled\Loader;

/**
 * Create an opt-in rider and a stationary vertical rope above empty terrain.
 *
 * @param bool $noSwing Whether horizontal movement is locked to the anchor.
 * @return array{Registry,Terrain,Grapples,Body,GrappleGrip,int,Rope,RopeAnchor,int} Fixture state.
 */
function grappleFixture(bool $noSwing = false): array
{
	$registry = new Registry;
	$terrain = new Terrain;
	$system = new Grapples($registry, $terrain);
	$anchor = new RopeAnchor(0, 0);
	$anchorId = $system->anchor($anchor);
	$rope = new Rope($anchorId, 0, 100, 100, $noSwing);
	$point = $system->add(new GrapplePoint, $rope, new Sprite(1), 2);
	$body = new Body(0, 90);
	$grip = new GrappleGrip;
	$player = $registry->create($body, $grip);

	return [$registry, $terrain, $system, $body, $grip, $player, $rope, $anchor, $point];
}

test('grapples attach on contact for every profile and hold a standing hanging pose', function() {
	foreach(['runner', 'flyer', 'glider'] as $profile)
	{
		[$registry, $terrain, $system, $body, $grip, $player, $rope, , $point] = grappleFixture();
		$body->profile = $profile;
		$body->rolling = $body->jumping = $body->grounded = true;
		$body->groundSpeed = 240;
		$body->wall = -1;
		$body->dropTimer = $body->crouchTime = 1;
		$body->skimming = true;
		$system->update($registry, 1 / 60);
		check($grip->point === $point && $body->ability === 'hanging');
		check(!$body->grounded && !$body->jumping && !$body->rolling && !$body->skimming);
		check($body->wall === 0 && $body->dropTimer === 0.0 && $body->crouchTime === 0.0);
		near($body->halfHeight, $body->standingHeight);
		near($body->y, $rope->y + $body->halfHeight - 5);
		near($rope->vx, 240);
		$input = new Controls;
		$input->set(['right' => true]);
		$system->control($player, $input, 1 / 60);
		$system->update($registry, 1 / 60);
		check($body->x > 0 && $body->direction === 1);
		$x = $body->x;
		$y = $body->y;
		(new Platformer($terrain))->step($body, $input, 1 / 60);
		near($body->x, $x);
		near($body->y, $y);
		$camera = new PlatformCamera;
		$camera->update(new Camera, $body, 1 / 60);
		check($camera->mode === 'hooked');
	}
});

test('ropes pump in both directions, reverse above the anchor, allow slack, and retain their length', function() {
	foreach([1 / 60, 1 / 120] as $dt)
	{
		foreach([-1, 1] as $direction)
		{
			[$registry, , $system, $body, , $player, $rope, $anchor] = grappleFixture();
			$system->update($registry, $dt);
			$input = new Controls;
			$input->set([$direction < 0 ? 'left' : 'right' => true]);

			for($i = 0; $i < (int)(.3 / $dt); $i++)
			{
				$system->control($player, $input, $dt);
				$system->update($registry, $dt);
				check(hypot($rope->x - $anchor->x, $rope->y - $anchor->y) <= 100.001);
				near($body->x, $rope->x);
			}

			check($rope->vx * $direction > 50 && $rope->x * $direction > 10);
		}
	}

	$anchor = new RopeAnchor(0, 0);
	$rope = new Rope(1, 0, -100, 100);
	$rope->step($anchor, 1 / 60, 1);
	check($rope->vx < 0 && hypot($rope->x, $rope->y) < 100);
});

test('jump release consumes the edge, inherits momentum, and excludes the handle for fifteen frames', function() {
	[$registry, , $system, $body, $grip, $player, $rope, $anchor, $point] = grappleFixture();
	$system->update($registry, 1 / 60);
	$rope->vx = 210;
	$rope->vy = -30;
	$input = new Controls;
	$input->set([0 => true]);
	check($system->control($player, $input, 1 / 60));
	check($grip->point === null && $body->jumping && $body->ability === '');
	near($body->vx, 210);
	near($body->vy, -30 - $body->jumpSpeed * .75);
	near($grip->cooldowns[$point], .25);
	check(!$system->control($player, $input, 1 / 60));

	$rope->vx = $rope->vy = 0;
	$body->x = $body->vx = $body->vy = 0;
	$body->y = 90;
	$system->sync($player, $body);

	for($i = 0; $i < 14; $i++)
	{
		$system->update($registry, 1 / 60);
		check($grip->point === null);
	}

	$system->update($registry, 1 / 60);
	check($grip->point === $point);
	$anchor->vx = 60;
	$anchor->vy = 20;
	$system->release($player);
	near($body->vx, 60);
	near($body->vy, 20);
});

test('swept grapple acquisition catches fast crossings and reset sync forgets teleport paths', function() {
	[$registry, , $system, $body, $grip, $player, , , $point] = grappleFixture();
	$body->x = -500;
	$system->sync($player, $body);
	$body->x = 500;
	$body->vx = 60000;
	$system->update($registry, 1 / 60);
	check($grip->point === $point);
	$system->release($player, inherit: false);
	$grip->reset();
	$body->x = -500;
	$system->sync($player, $body);
	$body->x = 500;
	$system->sync($player, $body);
	$system->update($registry, 1 / 60);
	check($grip->point === null);
});

test('grapple attachment and high-speed passenger motion cannot tunnel through thin walls', function() {
	[$registry, $terrain, $system, $body, $grip, $player, $rope] = grappleFixture();
	$wall = $terrain->addShape([[40, -200], [41, -200], [41, 300], [40, 300]]);
	$body->x = -100;
	$system->sync($player, $body);
	$body->x = 100;
	$system->update($registry, 1 / 60);
	check($grip->point === null, 'Alignment must not drag a rider through a wall');
	$body->x = 0;
	$system->sync($player, $body);
	$system->update($registry, 1 / 60);
	check($grip->point !== null);
	$rope->vx = 60000;
	$system->update($registry, 1 / 60);
	check($body->x + $body->radius <= 40.001);
	check($rope->x + 14 <= 40.001);

	$terrain->removeShape($wall);
	$system->release($player, inherit: false);
	$grip->reset();
	$rope->x = $rope->vx = $rope->vy = 0;
	$rope->y = 100;
	$body->x = 0;
	$body->y = 90;
	$terrain->addShape([[-50, 120], [50, 120], [50, 200], [-50, 200]]);
	$system->sync($player, $body);
	$system->update($registry, 1 / 60);
	check($grip->point === null, 'Standing bounds below the handle must fit above the ground');
});

test('no-swing ropes follow moving suspension entities, release momentum, and retire missing anchors', function() {
	[$registry, , $system, $body, $grip, $player, $rope, $anchor] = grappleFixture(true);
	$platform = new MovingPlatform(0, 0);
	$external = $registry->create($platform);
	$anchor->followEntity = $external;
	$system->update($registry, 1 / 60);
	$input = new Controls;
	$input->set(['right' => true]);
	$platform->x = 2;
	$system->control($player, $input, 1 / 60);
	$system->update($registry, 1 / 60);
	near($anchor->x, 2);
	near($rope->x, 2);
	near($body->x, 2);
	near($rope->vx, 120);
	$registry->destroy($external);
	$system->update($registry, 1 / 60);
	check(!$anchor->enabled && $grip->point === null && $body->ability === '');
	near($body->vx, 120);
	$system->close();
	$system->close();
	check($registry->alive($player) && count($registry->query(Rope::class)) === 0);
});

test('grapples support multiple riders and preserve external damage on release', function() {
	[$registry, , $system, $body, $grip, $player, $rope, , $point] = grappleFixture();
	$other = new Body(0, 90, 'flyer');
	$otherGrip = new GrappleGrip;
	$second = $registry->create($other, $otherGrip);
	$system->update($registry, 1 / 60);
	check($grip->point === $point && $otherGrip->point === $point);
	$grip->axis = 1;
	$otherGrip->axis = -1;
	$system->update($registry, 1 / 60);
	check($rope->vx > 0, 'First rider controls shared pumping');
	near($body->x, $other->x);
	$body->ability = 'hurt';
	$body->vx = -300;
	$body->vy = -240;
	$system->update($registry, 1 / 60);
	check($grip->point === null && $body->ability === 'hurt');
	near($body->vx, -300);
	near($body->vy, -240);
	check($otherGrip->point === $point);
	$registry->destroy($point);
	$system->update($registry, 1 / 60);
	check($otherGrip->point === null && $registry->alive($second));
});

test('rope segments follow geometry and retain authored visibility and tint', function() {
	[$registry, , $system, , , , $rope, $anchor, $point] = grappleFixture();
	$segments = $registry->get($point, SpriteGroup::class)->sprites;
	check(count($segments) === 11);
	near($segments[0]->x, 0);
	near($segments[0]->y, 0);
	near($segments[10]->scaleY, .5);
	$rope->x = 60;
	$rope->y = 80;
	$system->update($registry, 1 / 120);
	$segments = $registry->get($point, SpriteGroup::class)->sprites;
	near($segments[0]->rotation, atan2($rope->y, $rope->x) - M_PI_2);
	$anchor->enabled = false;
	$system->update($registry, 1 / 60);
	check($registry->get($point, SpriteGroup::class)->sprites === []);
	near($registry->get($point, Sprite::class)->opacity, 0);
});

test('grapple demo resolves static, squine, and path anchors with map offsets and Tiled flips', function() {
	$map = (new Loader)->map(__DIR__ . '/../examples/assets/grapples.tmj');
	$map->x = 500;
	$map->y = -200;
	$layer = $map->layer('Ropes and suspensions');
	$layer->offsetX = 20;
	$layer->offsetY = 30;
	$layer->opacity = .4;
	$layer->tint = [.5, .6, .7, 1];

	foreach($layer->objects as &$object)
	{
		if(($object['class'] ?? '') === 'GrapplePoint')
		{
			$object['gid'] += 2147483648.0;
		}
	}

	unset($object);
	$registry = new Registry;
	$terrain = MapTerrain::build($map);
	$platforms = new Platforms($registry, $terrain, $map);

	$resolve = function(int $source) use ($platforms): ?int {
		foreach($platforms->platforms as $id => $platform)
		{
			if($source === $platform->sourceId) return $id;
		}

		return null;
	};

	$system = new Grapples($registry, $terrain, $map, $resolve);
	check(count($system->points) === 4 && count($system->anchors) === 4);
	$first = $system->snapshot()[0];
	near($first['anchorX'], 648);
	near($first['anchorY'], -10);
	near($first['y'], 182);
	check($system->snapshot()[2]['noSwing']);

	foreach($system->points as $id => $point)
	{
		$sprite = $registry->get($id, Sprite::class);
		check($sprite->flipX && $sprite->gid > 0 && $sprite->outline);
		near($sprite->opacity, .4);
		check($sprite->tint === $layer->tint);
		$rope = $registry->get($id, SpriteGroup::class)->sprites[0];
		near($rope->opacity, .4);
		check(!$rope->outline, 'repeated rope strips remain an MB2-style structural exclusion');
	}

	$system->close();
	check(count($registry->query(MovingPlatform::class)) === 2);
	$platforms->close();
	check($registry->query(RopeAnchor::class) === []);
});

test('grapple loading rolls back missing references and validates rope configuration', function() {
	$map = (new Loader)->map(__DIR__ . '/../examples/assets/grapples.tmj');
	$layer = $map->layer('Ropes and suspensions');
	$layer->objects[] = ['id' => 999, 'class' => 'GrapplePoint', 'x' => 0, 'y' => 0];
	$registry = new Registry;
	rejects(fn() => new Grapples($registry, new Terrain, $map));
	check($registry->query(Rope::class) === [] && $registry->query(RopeAnchor::class) === []);
	rejects(fn() => new Rope(1, 0, 0, 0));
	rejects(fn() => new Rope(1, INF, 0));
	rejects(fn() => new RopeAnchor(NAN, 0));
	rejects(fn() => new GrapplePoint(0));
});

test('grapple collision planes, shared anchors, and attachment notifications remain independent', function() {
	$registry = new Registry;
	$events = [];

	$system = new Grapples(
		$registry
		, new Terrain
		, onChange: function(int $player, int $point, bool $attached) use (&$events) {
			$events[] = [$player, $point, $attached];
		}
	);

	$anchor = $system->anchor(new RopeAnchor(0, 0));
	$point = $system->add(new GrapplePoint(collisionLayer: 2), new Rope($anchor, 0, 100, 100));
	$other = $system->add(new GrapplePoint, new Rope($anchor, 80, 60, 100));
	$body = new Body(0, 90);
	$grip = new GrappleGrip;
	$player = $registry->create($body, $grip);
	$system->update($registry, 1 / 60);
	check($grip->point === null && $events === []);
	$body->collisionLayer = 2;
	$system->update($registry, 1 / 60);
	check($grip->point === $point && $events === [[$player, $point, true]]);
	$system->remove($point);
	check($registry->alive($anchor) && $registry->alive($other));
	check($events === [[$player, $point, true], [$player, $point, false]]);
	$registry->destroy($anchor);
	$system->update($registry, 1 / 60);
	check(!$system->snapshot()[0]['active']);
	$system->close();
});

test('a blocked moving-anchor rope releases passengers before stretching can drag them through a wall', function() {
	[$registry, $terrain, $system, $body, $grip, $player, $rope, $anchor] = grappleFixture(true);
	$terrain->addShape([[40, -200], [41, -200], [41, 300], [40, 300]]);
	$system->update($registry, 1 / 60);
	check($grip->point !== null);
	$anchor->x = 250;
	$system->update($registry, 1 / 60);
	check($grip->point === null && $body->jumping);
	check($body->x + $body->radius < 40 && $terrain->canPlace($body));
	$system->close();
});
