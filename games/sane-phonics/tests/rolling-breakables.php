<?php

use PhpWasmEngine\Ecs\Registry;
use PhpWasmEngine\Effects\ParticleBurst;
use PhpWasmEngine\Gameplay\BreakableBlock;
use PhpWasmEngine\Gameplay\BreakableBlocks;
use PhpWasmEngine\Physics\Body;
use PhpWasmEngine\Physics\Hit;
use PhpWasmEngine\Physics\Platformer;
use PhpWasmEngine\Physics\Terrain;

use PhpWasmEngine\Render\Sprite;
use PhpWasmEngine\Render\SpriteSheet;
use PhpWasmEngine\Tiled\Loader;

test('walking hits stay solid while rolling sweeps shatter without losing momentum', function() {
	foreach([false, true] as $rolling)
	{
		foreach([-1, 1] as $direction)
		{
			$sheet = new SpriteSheet(new Loader, dirname(__DIR__, 3) . '/tests/assets/shatter.tsj');
			$r = new Registry;
			$t = new Terrain;
			$blocks = new BreakableBlocks($r, $t, $sheet->map);
			$id = $blocks->create(new Sprite(1, 50, -8, 0, 0), 4, 2);

			$movement = new Platformer($t, [$blocks, 'impact']);
			$body = new Body($direction === 1 ? 0 : 120, 0);
			$body->gravity = 0;
			$body->vx = $direction * 60000;
			$body->rolling = $rolling;

			tick($movement, $body);

			if($rolling)
			{
				near($body->x, ($direction === 1 ? 0 : 120) + $direction * 1000);
				near($body->vx, $direction * 60000);

				check(!$r->has($id, BreakableBlock::class));
				check(count($r->get($id, ParticleBurst::class)->sprites->sprites) === 8);
			}
			else
			{
				near($body->x, $direction === 1 ? 41.999 : 74.001);
				near($body->vx, 0);

				check($r->has($id, BreakableBlock::class) && $t->solidAt(58, 0));
			}
		}
	}
});

test('down starts a grounded roll and breaks on contact but action alone does not', function() {
	$sheet = new SpriteSheet(new Loader, dirname(__DIR__, 3) . '/tests/assets/shatter.tsj');
	$r = new Registry;
	$t = new Terrain;
	$t->add([[-500, 100], [1000, 100], [1000, 120], [-500, 120]]);

	$blocks = new BreakableBlocks($r, $t, $sheet->map);
	$image = new Sprite(1, 50, 100, 0, 1);
	$image->scaleY = 4;
	$id = $blocks->create($image, 2, 2);

	$movement = new Platformer($t, [$blocks, 'impact']);
	$body = new Body(20, 82);
	$body->grounded = true;
	$body->groundSpeed = 600;

	tick($movement, $body, ['action' => true], 4);
	check($r->has($id, BreakableBlock::class) && !$body->rolling);
	check($body->x + $body->radius <= 50.001);

	$body->groundSpeed = 600;
	tick($movement, $body, ['down' => true], 4);
	check($body->rolling && !$r->has($id, BreakableBlock::class));
	check($body->grounded && $body->x > 70 && $body->groundSpeed > 590);
});

test(
	'rolling consumes an entire block chain in one sweep and then stops at ordinary terrain'
	, function() {
		$sheet = new SpriteSheet(new Loader, dirname(__DIR__, 3) . '/tests/assets/shatter.tsj');
		$r = new Registry;
		$t = new Terrain;
		$blocks = new BreakableBlocks($r, $t, $sheet->map);

		for($i = 0; $i < 12; $i++)
		{
			$blocks->create(new Sprite(1, 50 + $i * 24, -8, 0, 0), 2, 2);
		}

		$t->add([[360, -100], [376, -100], [376, 100], [360, 100]]);
		$behind = $blocks->create(new Sprite(1, 410, -8, 0, 0), 2, 2);

		$movement = new Platformer($t, [$blocks, 'impact']);
		$body = new Body;
		$body->gravity = 0;
		$body->vx = 60000;
		$body->rolling = true;

		tick($movement, $body);
		near($body->x, 351.999);
		near($body->vx, 0);
		check(count($r->query(ParticleBurst::class)) === 12);
		check($r->query(BreakableBlock::class) === [$behind]);
	}
);

test(
	'rolling respects planes and misses and impact snapshots use the supplied animation clock'
	, function() {
		$sheet = new SpriteSheet(new Loader, dirname(__DIR__, 3) . '/tests/assets/sprites.tsj');
		$r = new Registry;
		$t = new Terrain;
		$blocks = new BreakableBlocks($r, $t, $sheet->map);
		$inactive = $blocks->create(new Sprite(1, 32, -4, 0, 0), 2, 2, collisionLayer: 2);
		$miss = $blocks->create(new Sprite(1, 64, 40, 0, 0), 2, 2);
		$hit = $blocks->create($sheet->sprite('run', 100, 4), 2, 2);

		$movement = new Platformer($t, fn(Body $body, Hit $contact) => $blocks->impact($body, $contact, 150));
		$body = new Body;
		$body->gravity = 0;
		$body->rolling = true;
		$body->vx = 60000;

		tick($movement, $body);
		near($body->x, 1000);
		check($r->query(BreakableBlock::class) === [$inactive, $miss]);

		foreach($r->get($hit, ParticleBurst::class)->sprites->sprites as $piece)
		{
			check($piece->gid === 2 && !$piece->animated);
		}
	}
);

test(
	'rolling probes and resting support do not shatter and stale shape hits cannot hit replacements'
	, function() {
		$sheet = new SpriteSheet(new Loader, dirname(__DIR__, 3) . '/tests/assets/shatter.tsj');
		$r = new Registry;
		$t = new Terrain;
		$blocks = new BreakableBlocks($r, $t, $sheet->map);
		$id = $blocks->create(new Sprite(1, 0, 100, 0, 0), 2, 2);

		$body = new Body(8, 82);
		$body->grounded = $body->rolling = true;
		$movement = new Platformer($t, [$blocks, 'impact']);
		tick($movement, $body, ['down' => true], 10);
		check($r->has($id, BreakableBlock::class) && $body->grounded);

		$contact = $t->castBody($body, 0, 20);
		check($contact !== null && $t->hasShape($contact->shape));
		check($r->has($id, BreakableBlock::class), 'read-only casts must not shatter');
		check($blocks->impact($body, $contact));
		check(!$blocks->impact($body, $contact));

		$replacement = $blocks->create(new Sprite(1, 0, 100, 0, 0), 2, 2);
		check(!$blocks->impact($body, $contact));
		check($r->has($replacement, BreakableBlock::class));
	}
);

test('impact callbacks cannot bypass a live shape by returning a truthy value', function() {
	$t = new Terrain;
	$t->addShape([[50, -100], [66, -100], [66, 100], [50, 100]]);
	$movement = new Platformer($t, fn() => true);
	$body = new Body;
	$body->gravity = 0;
	$body->vx = 60000;
	$body->rolling = true;

	tick($movement, $body);
	near($body->x, 41.999);
	near($body->vx, 0);
});
