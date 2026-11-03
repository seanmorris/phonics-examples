<?php
use PhpWasmEngine\Ecs\Registry;
use PhpWasmEngine\Effects\ParticleBurst;
use PhpWasmEngine\Effects\ParticleSystem;
use PhpWasmEngine\Gameplay\BreakableBlock;
use PhpWasmEngine\Gameplay\BreakableBlocks;

use PhpWasmEngine\Physics\Body;
use PhpWasmEngine\Physics\Platformer;
use PhpWasmEngine\Physics\Terrain;
use PhpWasmEngine\Render\Sprite;
use PhpWasmEngine\Render\SpriteGroup;
use PhpWasmEngine\Render\SpriteLayers;
use PhpWasmEngine\Render\SpriteSheet;
use PhpWasmEngine\Tiled\Loader;

test(
	'Tiled breakable objects decode unsigned flips and keep their rotated collision bounds'
	, function() {
		require_once dirname(__DIR__, 3) . '/examples/breakables.php';

		$engine = (new ReflectionClass(PhpWasmEngine\Engine::class))->newInstanceWithoutConstructor();
		$engine->entities = new Registry;
		$engine->systems = new PhpWasmEngine\Ecs\Scheduler($engine->entities);

		foreach([0, 4, 8, 12, 2, 6, 10, 14] as $flags)
		{
			$object = [
				'class' => 'breakable'
				, 'gid' => 1 + $flags * 268435456.0
				, 'x' => 60
				, 'y' => 74
				, 'width' => 48
				, 'height' => 24
				, 'rotation' => 30
				, 'properties' => [
					['name' => 'columns', 'value' => 3]
					, [
						'name' => 'rows'
						, 'value' => 4
					]
				]
			];

			$data = mapData([
				[
					'id' => 1
					, 'type' => 'objectgroup'
					, 'offsetx' => 3
					, 'offsety' => 5
					, 'objects' => [
						$object
					]
				]
			]);

			$map = fixture(['blocks.tmj' => $data, 'tiles.tsj' => tiles()])->map('blocks.tmj');
			$map->x = 7;
			$map->y = 11;
			$terrain = new Terrain;
			$blocks = configureBreakables($engine, $terrain, $map, 'flags-' . $flags . '-');
			$ids = $engine->entities->query(Sprite::class);
			check(count($ids) === 1);
			$id = $ids[0];
			$sprite = $engine->entities->get($id, Sprite::class);
			check($sprite->gid === 1, 'flip bits must never reach tile lookup');

			// Check the Tiled object rectangle independently of the sprite's transformed anchor.
			foreach([
				[24, -12, true], [-1, -12, false], [49, -12, false]
				, [24, -25, false], [24, 1, false]
			] as [$x, $y, $solid])
			{
				$worldX = 70 + $x * cos(M_PI / 6) - $y * sin(M_PI / 6);
				$worldY = 90 + $x * sin(M_PI / 6) + $y * cos(M_PI / 6);
				check($terrain->solidAt($worldX, $worldY) === $solid, 'object bounds ' . $flags);
			}

			check($blocks->shatter($id));
			check(count($engine->entities->get($id, SpriteGroup::class)->sprites) === 12);

			check(
				!$terrain->solidAt(
					70 + 24 * cos(M_PI / 6) + 12 * sin(M_PI / 6)
					, 90 + 24 * sin(M_PI / 6) - 12 * cos(M_PI / 6)
				)
			);

			$blocks->close();
		}
	}
);

test('breakable boxes disable outlines and shards inherit their source outline flag', function() {
	$sheet = new SpriteSheet(new Loader, dirname(__DIR__, 3) . '/tests/assets/shatter.tsj');
	$r = new Registry;
	$t = new Terrain;
	$blocks = new BreakableBlocks($r, $t, $sheet->map);
	$sprite = new Sprite(1, 50, 50, 0, 0);
	check($sprite->outline ?? false, 'ordinary sprites enable outlining by default');
	$outlined = ParticleBurst::grid($sprite, $sheet->map, 2, 2);

	foreach($outlined->sprites->sprites as $piece)
	{
		check($piece->outline, 'independent particles inherit the source setting');
	}

	$id = $blocks->create($sprite, 2, 2);
	check($r->get($id, Sprite::class) === $sprite && !$sprite->outline);
	check(!$t->solidAt(49.5, 58) && $t->solidAt(50.5, 58));
	check($blocks->shatter($id));

	foreach($r->get($id, SpriteGroup::class)->sprites as $piece)
	{
		check(!$piece->outline, 'block shards must retain the opt-out');
	}

	$blocks->close();
});

test(
	'entities retain single sprites and ordered groups without changing component replacement'
	, function() {
		$r = new Registry;
		$a = new Sprite(1);
		$b = new Sprite(2);
		$c = new Sprite(3);
		$d = new Sprite(4);

		$single = $r->create($a);
		$group = new SpriteGroup($b, $c);
		$multiple = $r->create($group);
		$both = $r->create($d, new SpriteGroup($a));

		check(SpriteGroup::collect($r) === [$a, $b, $c, $d, $a]);
		check(SpriteGroup::collect($r, [$both, $multiple]) === [$d, $a, $b, $c]);
		check(SpriteGroup::collectGroups($r) === [[$a], [$b, $c], [$d, $a]]);
		check(SpriteGroup::collectGroups($r, [$both, $multiple]) === [[$d, $a], [$b, $c]]);

		$group->sprites[] = $d;
		check(SpriteGroup::collect($r, [$multiple]) === [$b, $c, $d]);
		check(SpriteGroup::collectGroups($r, [$multiple]) === [[$b, $c, $d]]);

		$r->add($single, $c);
		check($r->get($single, Sprite::class) === $c);

		$r->destroy($multiple);
		check(SpriteGroup::collect($r) === [$c, $d, $a]);
		check(SpriteGroup::collectGroups($r) === [[$c], [$d, $a]]);
	}
);

test('sprite layers interleave independent outline planes around a selected anchor', function() {
	$r = new Registry;
	$anchor = new Sprite(1);
	$behind = new Sprite(2);
	$front = new Sprite(3);
	$owner = $r->create($behind, $layers = new SpriteLayers([$behind], [$front]));
	$anchorId = $r->create($anchor);
	$layers->anchor = $anchorId;

	check(SpriteGroup::collectGroups($r) === [[$behind], [$anchor], [$front]]);
	check(SpriteGroup::collectGroups($r, [$anchorId]) === [[$anchor]]);
	check(SpriteGroup::collectGroups($r, [$owner]) === [[$behind], [$front]]);

	$layers->anchor = null;
	check(SpriteGroup::collectGroups($r) === [[$behind], [$front], [$anchor]]);
});

test(
	'grid particles partition odd crop dimensions and freeze the current animation frame'
	, function() {
		$sheet = new SpriteSheet(new Loader, dirname(__DIR__, 3) . '/tests/assets/shatter.tsj');
		$sprite = $sheet->sprite('block', 50, 70);
		$sprite->crop = [1, 2, 13, 11];
		$sprite->flipX = $sprite->flipY = true;
		$sprite->rotation = .3;
		$sprite->scaleX = -2;
		$sprite->scaleY = 1.5;
		$sprite->tint = [.5, .7, .9, .8];

		$before = get_object_vars($sprite);
		$burst = ParticleBurst::grid($sprite, $sheet->map, 3, 4);
		check(count($burst->sprites->sprites) === 12);
		$coverage = [];

		foreach($burst->sprites->sprites as $piece)
		{
			check($piece !== $sprite && !$piece->animated);
			check($piece->tint === $sprite->tint && $piece->flipX && $piece->flipY);
			near($piece->rotation, .3);
			[$x, $y, $width, $height] = $piece->crop;

			for($row = $y; $row < $y + $height; $row++)
			{
				for($column = $x; $column < $x + $width; $column++)
				{
					check(!isset($coverage["$column,$row"]), 'overlapping source crops');
					check($column >= 1 && $column < 14 && $row >= 2 && $row < 13);
					$coverage["$column,$row"] = true;
				}
			}
		}

		check(count($coverage) === 13 * 11);
		check(get_object_vars($sprite) === $before);

		$animated = new SpriteSheet(new Loader, dirname(__DIR__, 3) . '/tests/assets/sprites.tsj');
		$burst = ParticleBurst::grid($animated->sprite('run'), $animated->map, 2, 2, 150);

		foreach($burst->sprites->sprites as $piece)
		{
			check($piece->gid === 2 && $piece->frame($animated->map, 1000) === 2);
		}
	}
);

test('removable terrain restores shared edges and never reuses stale handles', function() {
	$t = new Terrain;
	$a = $t->addShape([[0, 0], [16, 0], [16, 16], [0, 16]]);
	$b = $t->addShape([[16, 0], [32, 0], [32, 16], [16, 16]]);
	check($t->raycast(24, 8, -16, 0) === null);
	check($t->solidAt(24, 8));

	check($t->removeShape($b));
	check(!$t->solidAt(24, 8) && $t->solidAt(8, 8));
	near($t->raycast(24, 8, -16, 0)->x, 16);

	$c = $t->addShape([[16, 0], [32, 0], [32, 16], [16, 16]]);
	check($c !== $b && !$t->removeShape($b));
	check($t->solidAt(24, 8) && $t->raycast(24, 8, -16, 0) === null);

	check($t->removeShape($a) && $t->removeShape($c));
	check($t->castBody(new Body(-100, 8), 1000, 0) === null);
});

test('a breakable blocks every side and a fast player until its grid shatters', function() {
	$sheet = new SpriteSheet(new Loader, dirname(__DIR__, 3) . '/tests/assets/shatter.tsj');
	$r = new Registry;
	$t = new Terrain;
	$blocks = new BreakableBlocks($r, $t, $sheet->map);
	$id = $blocks->create(new Sprite(1, 50, -8, 0, 0), 4, 2);

	check($t->solidAt(58, 0));
	near($t->raycast(0, 0, 100, 0)->x, 50);
	near($t->raycast(100, 0, -100, 0)->x, 66);
	near($t->raycast(58, -100, 0, 200)->y, -8);
	near($t->raycast(58, 100, 0, -200)->y, 8);

	$movement = new Platformer($t);

	$body = new Body;
	$body->vx = 60000;
	$body->gravity = 0;
	tick($movement, $body);
	check($body->x + $body->radius <= 50.001, 'high-speed movement tunneled through the block');

	check($blocks->shatter($id));
	check(!$blocks->shatter($id), 'shattering twice duplicated debris');
	check(!$r->has($id, BreakableBlock::class) && !$r->has($id, Sprite::class));
	check(count($r->get($id, SpriteGroup::class)->sprites) === 8);
	check(!$t->solidAt(58, 0) && $t->raycast(0, 0, 100, 0) === null);

	$body = new Body;
	$body->vx = 60000;
	$body->gravity = 0;
	tick($movement, $body);
	check($body->x > 66 + $body->radius, 'debris must not retain the solid collider');

	$particles = new ParticleSystem;
	$particles->update($r, .2);
	check($r->get($id, ParticleBurst::class)->age > 0);

	$particles->update($r, 2);
	$r->flush();
	check(!$r->alive($id) && SpriteGroup::collect($r) === []);
});

test('standing on a broken block releases support on the next physics step', function() {
	$sheet = new SpriteSheet(new Loader, dirname(__DIR__, 3) . '/tests/assets/shatter.tsj');
	$r = new Registry;
	$t = new Terrain;
	$blocks = new BreakableBlocks($r, $t, $sheet->map);
	$id = $blocks->create(new Sprite(1, 0, 100, 0, 0), 2, 3);

	$body = new Body(8, 60);

	$movement = new Platformer($t);
	tick($movement, $body, [], 30);
	check($body->grounded);
	near($body->y + $body->halfHeight, 100, .01);

	$blocks->shatter($id);
	tick($movement, $body, [], 10);
	check(!$body->grounded && $body->y > 82);
});

test('block collision planes and entity cleanup release only owned geometry', function() {
	$sheet = new SpriteSheet(new Loader, dirname(__DIR__, 3) . '/tests/assets/shatter.tsj');
	$r = new Registry;
	$t = new Terrain;
	$t->add([[-100, 50], [100, 50], [100, 60], [-100, 60]]);
	$blocks = new BreakableBlocks($r, $t, $sheet->map);
	$id = $blocks->create(new Sprite(1, 0, 0, 0, 0), 2, 2, collisionLayer: 2);

	check(!$t->solidAt(8, 8, 1) && $t->solidAt(8, 8, 2));
	check($t->raycast(-30, 8, 60, 0, collisionLayer: 1) === null);
	check($t->raycast(-30, 8, 60, 0, collisionLayer: 2) !== null);

	$r->destroy($id);
	check(!$t->solidAt(8, 8, 2));

	$id = $blocks->create(new Sprite(1, 0, 0, 0, 0), 2, 2);
	$other = $blocks->create(new Sprite(1, 32, 0, 0, 0), 2, 2);
	$blocks->shatter($other);

	$blocks->close();
	$blocks->close();
	check(!$r->alive($id) && !$r->alive($other));
	check(!$t->solidAt(8, 8) && $t->solidAt(8, 55));
});

test('invalid grids and crops leave an intact block solid and visible', function() {
	$sheet = new SpriteSheet(new Loader, dirname(__DIR__, 3) . '/tests/assets/shatter.tsj');
	$r = new Registry;
	$t = new Terrain;
	$blocks = new BreakableBlocks($r, $t, $sheet->map);

	foreach([[0, 2], [2, -1], [17, 2], [2, 17]] as [$columns, $rows])
	{
		rejects(fn() => $blocks->create(new Sprite(1), $columns, $rows));
	}

	check($r->query(Sprite::class) === []);

	$id = $blocks->create(new Sprite(1, 0, 0, 0, 0), 2, 2);
	$r->get($id, BreakableBlock::class)->columns = 0;
	rejects(fn() => $blocks->shatter($id));
	check($t->solidAt(8, 8) && $r->has($id, Sprite::class));
	$sprite = $r->get($id, Sprite::class);

	foreach([[-1, 0, 2, 2], [0, 0, 17, 2], [0, 0, 2, 0], [0, 0, .5, 2]] as $crop)
	{
		$sprite->crop = $crop;
		rejects(fn() => $sprite->rectangle([0, 0, 16, 16]));
	}
});

test(
	'particle motion is independent of step partition and expires without stale sprites'
	, function() {
		$sheet = new SpriteSheet(new Loader, dirname(__DIR__, 3) . '/tests/assets/shatter.tsj');
		$a = ParticleBurst::grid(new Sprite(1), $sheet->map, 3, 2);
		$b = ParticleBurst::grid(new Sprite(1), $sheet->map, 3, 2);
		$a->step(.2);
		$b->step(.1);
		$b->step(.1);

		foreach($a->sprites->sprites as $i => $sprite)
		{
			$other = $b->sprites->sprites[$i];
			near($sprite->x, $other->x);
			near($sprite->y, $other->y);
			near($sprite->rotation, $other->rotation);
			near($sprite->opacity, $other->opacity);
		}

		$age = $a->age;

		foreach([-1, INF, NAN] as $dt)
		{
			rejects(fn() => $a->step($dt));
		}

		near($a->age, $age);
		$a->step(2);
		check($a->finished() && $a->sprites->sprites === []);
	}
);

test('rotated cropped blocks use the rendered rectangle for scaled collision bounds', function() {
	$sheet = new SpriteSheet(new Loader, dirname(__DIR__, 3) . '/tests/assets/shatter.tsj');
	$r = new Registry;
	$t = new Terrain;
	$blocks = new BreakableBlocks($r, $t, $sheet->map);

	$sprite = new Sprite(1, 100, 100, .25, .75);
	$sprite->crop = [2, 3, 12, 10];
	$sprite->scaleX = -2;
	$sprite->scaleY = 3;
	$sprite->rotation = M_PI / 2;

	$id = $blocks->create($sprite, 3, 2);
	check($t->solidAt(100, 100));
	near($t->raycast(0, 90, 200, 0)->x, 92.5);
	near($t->raycast(200, 90, -200, 0)->x, 122.5);
	near($t->raycast(110, 0, 0, 200)->y, 82);
	near($t->raycast(110, 200, 0, -200)->y, 106);

	$blocks->shatter($id);
	check($t->raycast(0, 90, 200, 0) === null);
});

test(
	'terrain removals clear negative spatial cells and reveal neighbors on the selected plane'
	, function() {
		$t = new Terrain;
		$shared = $t->addShape([[-192, -64], [0, -64], [0, 128], [-192, 128]]);
		$plane = $t->addShape([[0, -64], [128, -64], [128, 128], [0, 128]], collisionLayer: 2);
		check($t->raycast(64, 0, -100, 0, collisionLayer: 2) === null);
		near($t->raycast(64, 0, -100, 0, collisionLayer: 1)->x, 0);

		$t->removeShape($shared);
		near($t->raycast(-64, 0, 200, 0, collisionLayer: 2)->x, 0);
		check(!$t->solidAt(-128, 64));

		$t->removeShape($plane);
		check($t->raycast(-300, 0, 600, 0, collisionLayer: 2) === null);

		$oneWay = $t->addShape([[-192, -64], [128, -64]], oneWay: true);
		near($t->raycast(-128, -100, 0, 100)->y, -64);
		check($t->raycast(-128, 0, 0, -100) === null);
		check(!$t->solidAt(-128, -64));

		$t->removeShape($oneWay);
		check($t->raycast(-128, -100, 0, 100) === null);
	}
);

test(
	'queued particle retirement tolerates replacement and duplicate updates before a flush'
	, function() {
		$sheet = new SpriteSheet(new Loader, dirname(__DIR__, 3) . '/tests/assets/shatter.tsj');
		$r = new Registry;
		$system = new ParticleSystem;
		$old = ParticleBurst::grid(new Sprite(1), $sheet->map, 2, 2);
		$id = $r->create($old, $old->sprites);

		$system->update($r, 2);
		$system->update($r, 2);

		$new = ParticleBurst::grid(new Sprite(1), $sheet->map, 3, 3);
		$r->add($id, $new);
		$r->add($id, $new->sprites);
		$r->flush();
		check($r->alive($id) && count(SpriteGroup::collect($r)) === 9);

		$system->update($r, 2);
		$system->update($r, 2);
		$r->flush();
		check(!$r->alive($id));
	}
);
