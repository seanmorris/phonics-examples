<?php
use PhpWasmEngine\Ecs\Registry;
use PhpWasmEngine\Gameplay\Monitor;
use PhpWasmEngine\Gameplay\MonitorEffect;
use PhpWasmEngine\Gameplay\MonitorExplosion;
use PhpWasmEngine\Gameplay\MonitorPopup;
use PhpWasmEngine\Gameplay\Monitors;
use PhpWasmEngine\Gameplay\PlayerCombat;
use PhpWasmEngine\Gameplay\RingCounter;
use PhpWasmEngine\Gameplay\RingMonitorEffect;
use PhpWasmEngine\Physics\Body;
use PhpWasmEngine\Physics\MapTerrain;
use PhpWasmEngine\Physics\Platformer;
use PhpWasmEngine\Render\Sprite;
use PhpWasmEngine\Render\SpriteGroup;
use PhpWasmEngine\Tiled\Loader;

test('Tiled monitors stay solid for walking and reward a continuous rolling impact', function() {
	$map = (new Loader)->map(dirname(__DIR__, 3) . '/examples/assets/badniks.tmj');
	$registry = new Registry;
	$ground = MapTerrain::build($map);
	$terrain = MapTerrain::build($map);
	$monitors = new Monitors($registry, $terrain, $map);
	$ids = $monitors->entities();
	check(count($ids) === 7);
	check($monitors->counts() === ['intact' => 7,'broken' => 0,'popups' => 0,'explosions' => 0]);
	$expected = [[288, 448], [800, 288], [1056, 288], [1312, 448], [1632, 448], [1936, 448], [2080, 448]];

	foreach($ids as $index => $id)
	{
		$placed = $registry->get($id, Monitor::class);
		near($placed->x, $expected[$index][0]);
		near($placed->y, $expected[$index][1]);

		foreach([-$placed->width / 2 + 1, 0, $placed->width / 2 - 1] as $offset)
		{
			$surface = $ground->raycast($placed->x + $offset, $placed->y - 64, 0, 128);
			check($surface !== null, 'each monitor must have terrain under its full base');
			near($surface->y, $placed->y);
		}
	}

	$first = $registry->get($ids[0], Monitor::class);
	near($first->x, 288);
	near($first->y, 448);
	check($terrain->solidAt(288, 430));
	check($registry->has($ids[0], SpriteGroup::class));
	$screen = $registry->get($ids[0], SpriteGroup::class)->sprites[0];
	check($registry->get($ids[0], Sprite::class)->outline && $screen->outline);
	check($screen->gid === 41);
	check($screen->frame($map, 0) === 42);
	check($screen->frame($map, 200) === 41);
	check($screen->frame($map, 700) === 42);

	$walking = new Body(250, 430);
	$walking->grounded = true;
	$hit = $terrain->castBody($walking, 100, 0);
	check($hit !== null && !$monitors->impact($walking, $hit));
	$walking->grounded = false;
	check(!$monitors->impact($walking, $hit), 'plain falling must not break a monitor');
	check($terrain->solidAt(288, 430) && !$first->broken);

	$body = new Body(200, 430);
	$body->grounded = true;
	$body->rolling = true;
	$body->groundSpeed = 12000;
	$counter = new RingCounter;
	$combat = new PlayerCombat;
	$player = $registry->create($body, $counter, $combat);
	$combat->mercy = 1;
	$hit = $terrain->castBody($body, 200, 0);
	check($hit !== null && !$monitors->impact($body, $hit));
	check(!$first->broken, 'mercy knockback must not break a monitor');
	$combat->mercy = 0;

	/**
	 * Route continuous movement impacts through the monitor manager.
	 *
	 * @param Body $moving Actor at the beginning of the current sweep.
	 * @param PhpWasmEngine\Physics\Hit $contact Earliest terrain contact in that sweep.
	 * @return bool True when an attacking body removes a monitor shape.
	 */
	$impact = fn(Body $moving, PhpWasmEngine\Physics\Hit $contact): bool =>
		$monitors->impact($moving, $contact, 250);

	$movement = new Platformer($terrain, $impact);
	tick($movement, $body);
	check($first->broken && !$terrain->solidAt(288, 430));
	check($body->x > 303 + $body->radius, 'removed monitor geometry must not stop the remaining sweep');
	check($counter->rings === 10 && $counter->score === 10);
	check(!$registry->has($ids[0], SpriteGroup::class));
	check($registry->get($ids[0], Sprite::class)->gid === 40);
	check($monitors->counts() === ['intact' => 6,'broken' => 1,'popups' => 1,'explosions' => 1]);

	$popupId = $registry->query(MonitorPopup::class)[0];
	$popup = $registry->get($popupId, MonitorPopup::class);
	$popupSprite = $registry->get($popupId, Sprite::class);
	$initialY = $popupSprite->y;
	$monitors->update($registry, .25);
	near($popupSprite->y, $initialY - $popup->rise);
	check($registry->query(MonitorExplosion::class) !== []);
	$monitors->update($registry, .8);
	check($registry->query(MonitorPopup::class) === []);
	check($registry->query(MonitorExplosion::class) === []);

	$body->grounded = false;
	$body->vy = 600;
	check($monitors->pop($ids[1], $player, $body));
	check(!$monitors->pop($ids[1], $player, $body));
	check($counter->rings === 40 && $counter->score === 20);
	near($body->vy, -600);
	$monitors->close();

	foreach($ids as $id)
	{
		check(!$registry->alive($id));
	}
});

test('monitor effects are pluggable and external entity removal releases collision', function() {
	$registry = new Registry;
	$terrain = new PhpWasmEngine\Physics\Terrain;
	$monitors = new Monitors($registry, $terrain);

	$effect = new class implements MonitorEffect {
		public int $calls = 0;

		/**
		 * Record one custom reward invocation.
		 *
		 * @param Registry $registry Registry containing the player.
		 * @param int $player Player entity selected by the pop call.
		 * @param Monitor $monitor Monitor carrying the custom reward.
		 * @return void Increments the observable invocation count.
		 */
		public function apply(Registry $registry, int $player, Monitor $monitor): void
		{
			$this->calls++;
		}
	};

	$id = $monitors->create(new Monitor(50, 50), $effect, new Sprite(1, 50, 50));
	$player = $registry->create(new Body, new RingCounter);
	check($terrain->solidAt(50, 40));
	check($monitors->pop($id, $player));
	check($effect->calls === 1 && $registry->get($player, RingCounter::class)->score === 10);

	$removed = $monitors->create(new Monitor(100, 50), new RingMonitorEffect, new Sprite(1, 100, 50));

	check($terrain->solidAt(100, 40));
	$registry->destroy($removed);
	check(!$terrain->solidAt(100, 40));
	$monitors->close();
});

test('monitor components reject invalid bounds, rewards, timing, and collision', function() {
	rejects(fn() => new Monitor(NAN, 0));
	rejects(fn() => new Monitor(0, 0, width: 0));
	rejects(fn() => new Monitor(0, 0, points: -1));
	rejects(fn() => new RingMonitorEffect(0));
	rejects(fn() => new MonitorPopup(0, 0, rise: 0));
	rejects(fn() => new MonitorPopup(0, 0, fadeStart: 1, lifetime: 1));
	rejects(fn() => new MonitorExplosion(0));
});
