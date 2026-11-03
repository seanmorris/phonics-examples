<?php
use PhpWasmEngine\Gameplay\Combo;
use PhpWasmEngine\Gameplay\ComboReward;
use PhpWasmEngine\Physics\Body;
use PhpWasmEngine\Physics\MapTerrain;
use PhpWasmEngine\Physics\Platformer;
use PhpWasmEngine\Physics\Terrain;
use PhpWasmEngine\Tiled\Loader;

require_once dirname(__DIR__, 3) . '/examples/DemoComboHud.php';

test('continuous rail contacts enter grinding without high-speed tunneling', function() {
	$terrain = new Terrain;
	$terrain->addRail([[-200,100],[200,100]]);
	$body = new Body(0, -300);
	$body->vy = 30000;
	$body->gravity = 0;
	$body->terminalSpeed = 30000;
	tick(new Platformer($terrain), $body);
	check($body->grounded && $body->grinding);
	near($body->y + $body->halfHeight, 100, .01);
});

test('grinding locks travel direction independently of visual facing', function() {
	$terrain = new Terrain;
	$terrain->addRail([[-1000,100],[1000,100]]);
	$movement = new Platformer($terrain);
	$body = new Body(0, 82);
	$body->grounded = true;
	$body->grinding = true;
	$body->groundSpeed = 120;
	$body->friction = 0;
	$body->gravity = 0;
	tick($movement, $body, ['left' => true]);
	check($body->grinding && $body->groundSpeed >= 360);
	check($body->facing === -1, 'opposite input must turn the grinding sprite');
	check(property_exists($body, 'direction') && $body->direction === 1);
	tick($movement, $body, ['down' => true]);
	check($body->crouching && !$body->rolling);
	tick($movement, $body, ['jump' => true]);
	check(!$body->grounded && !$body->grinding && $body->jumping && $body->vy < 0);
});

test('rail jump drop dashes follow retained travel direction rather than facing', function() {
	foreach([-1, 1] as $direction)
	{
		$terrain = new Terrain;
		$terrain->addRail([[-2000,100],[2000,100]]);
		$movement = new Platformer($terrain);
		$body = new Body(0, 82, 'runner');
		$body->grounded = true;
		$body->grinding = true;
		$body->groundSpeed = $direction * $body->minGrindSpeed;
		$body->facing = $body->direction = $direction;
		$body->friction = 0;

		$opposite = $direction > 0 ? 'left' : 'right';
		tick($movement, $body, [$opposite => true,'jump' => true]);
		check($body->jumping && !$body->grinding);
		check($body->facing === -$direction && $body->direction === $direction);

		for($frame = 0; !$body->grounded && $frame < 120; $frame++)
		{
			tick($movement, $body, ['action' => true]);
		}

		check($body->grounded && $body->rolling, 'charged rail jump must land in a roll');
		check($body->groundSpeed * $direction > 0, 'drop boost must preserve rail travel direction');
	}
});

test('held landing input chooses drop-dash direction while neutral input retains orientation', function() {
	foreach([-1, 1] as $direction)
	{
		foreach([-1, 0, 1] as $axis)
		{
			foreach([360, 1200] as $speed)
			{
				$movement = new Platformer(ground());
				$body = new Body(0, 86);
				$body->halfHeight = $body->rollingHeight;
				$body->jumping = true;
				$body->dropCharge = 1;
				$body->vy = 240;
				$body->vx = $direction * $speed;
				$body->direction = $direction;
				$body->facing = -$direction;
				$body->airAcceleration = 0;
				tick($movement, $body, ['action' => true, 'left' => max(0, -$axis), 'right' => max(0, $axis)]);
				$boostDirection = $axis ?: $direction;
				check($body->grounded && $body->rolling);
				check($body->direction === $boostDirection);
				near($body->groundSpeed, $direction * $speed + $boostDirection * 840);
			}
		}
	}
});

test('drop-dash preserves neutral and fast-rail direction locks at the landing boundary', function() {
	foreach([false, true] as $rail)
	{
		$terrain = $rail ? new Terrain : ground();

		if($rail)
		{
			$terrain->addRail([[-2000, 100], [2000, 100]]);
		}

		$body = new Body(0, 86);
		$body->halfHeight = 12;
		$body->jumping = true;
		$body->dropCharge = 1;
		$body->vy = 240;
		$body->vx = $rail ? 600 : 0;
		$body->airAcceleration = 0;
		$body->direction = 1;
		tick(new Platformer($terrain), $body, ['action' => true, 'left' => true]);
		check($body->grounded && $body->rolling);
		check($body->direction === 1);
		near($body->groundSpeed, ($rail ? 600 : 0) + 840);
	}
});

test('rail endpoints release tangent momentum into an ordinary fall', function() {
	$terrain = new Terrain;
	$terrain->addRail([[0,100],[80,100]]);
	$movement = new Platformer($terrain);
	$body = new Body(40, 82);
	$body->grounded = true;
	$body->grinding = true;
	$body->groundSpeed = 900;
	$body->friction = 0;
	$body->gravity = 0;
	tick($movement, $body, [], 8);
	check(!$body->grounded && !$body->grinding && !$body->jumping && $body->vx > 0);
});

test('Tiled grinding layers and rail polylines annotate collision contacts', function() {
	$loader = fixture([
		'/map.tmj' => mapData([
			layer([
				'name' => 'Grinding'
				, 'properties' => [['name' => 'grinding','type' => 'bool','value' => true]]
			])
			, [
				'id' => 2
				, 'name' => 'Scripted rails'
				, 'type' => 'objectgroup'
				, 'objects' => [
					[
						'id' => 1
						, 'class' => 'Rail'
						, 'x' => 32
						, 'y' => 64
						, 'polyline' => [['x' => 0,'y' => 0],['x' => 64,'y' => 0]]
					]
				]
			]
		])
		, '/tiles.tsj' => tiles()
	]);

	$terrain = MapTerrain::build($loader->map('/map.tmj'));
	check($terrain->raycast(8, -20, 0, 40)?->grinding === true);
	check($terrain->raycast(48, 40, 0, 40)?->grinding === true);
});

test('the grinding course follows four 90-degree bends and switches loop routes both ways', function() {
	$terrain = MapTerrain::build((new Loader)->map(dirname(__DIR__, 3) . '/examples/assets/grinding.tmj'));

	foreach([1,-1] as $direction)
	{
		foreach([900,6000] as $speed)
		{
			$body = new Body($direction > 0?80:1880, 430);
			$body->collisionLayer = $direction > 0?1:2;
			$body->grounded = true;
			$body->groundSpeed = $direction * $speed;
			$body->friction = 0;
			$movement = new Platformer($terrain);
			$quarterBend = $ceiling = false;
			$raisedRail = false;
			$normalGround = false;
			$returnedToRail = false;
			$crownLayer = null;

			for($i = 0; $i < 240; $i++)
			{
				tick($movement, $body);
				check($body->grounded, 'the complete course must retain surface contact');
			$quarterBend = $quarterBend || ($body->x < 1280
				&& abs(abs($body->angle) - M_PI / 2) < .2
			);

			$ceiling = $ceiling || abs(abs($body->angle) - M_PI) < .2;
				$raisedRail = $raisedRail || ($body->x > 768 && $body->x < 1024 && $body->y < 190);
				$normalGround = $normalGround || ($body->x > 210 && $body->x < 300 && !$body->grinding);
				$returnedToRail = $returnedToRail || ($direction > 0
					? $body->x > 340 && $body->grinding
					: $body->x < 170 && $body->grinding
				);

				if(($direction > 0?$body->x < 1648:$body->x > 1680) && $body->y < 250)
				{
					$crownLayer = $body->collisionLayer;
				}

				if($direction > 0?$body->x > 1920:$body->x < 160)
				{
					break;
				}
			}

			check($i < 240 && $quarterBend && $raisedRail && $ceiling && $normalGround && $returnedToRail);
			check($crownLayer === ($direction > 0?1:2));
			check($body->collisionLayer === ($direction > 0?2:1));
			near($body->y + $body->halfHeight, 448, .01);
		}
	}
});

test('combo chains accrue rail points, settle on safe ground, and fail on damage', function() {
	$combo = new Combo;
	$body = new Body;
	$body->grounded = true;
	$body->grinding = true;
	$body->groundSpeed = 1200;
	check($combo->update($body, 1 / 60) === null);
	check(count($combo->chain) === 1 && $combo->chain[0]->label === 'Rail Grind');
	$railPoints = $combo->chain[0]->points;
	$combo->update($body, 1 / 60);
	check($combo->chain[0]->points > $railPoints);
	$combo->add(new ComboReward('Trick', 50, 2));
	check($combo->base() > 150 && $combo->multiplier() === 3.0);

	$body->grinding = false;
	$body->grounded = false;
	check($combo->update($body, 1) === null && count($combo->chain) === 2);
	$body->grounded = true;
	check($combo->update($body, 1 / 60) === null);
	$score = $combo->update($body, 1 / 30);
	check($score !== null && $score > 450 && $combo->result === $score && !$combo->failed);
	check($combo->chain === []);

	$combo->add(new ComboReward('Lost trick', 100));
	check($combo->fail() === 100 && $combo->failed && $combo->result === 100);
	check($combo->chain === []);
	$combo->update($body, 6);
	check($combo->result === null);
});

test('combo rewards reject malformed score data and reset all display state', function() {
	rejects(fn() => (new Terrain)->addRail([]));
	rejects(fn() => (new Terrain)->addRail([[0, 0], [NAN, 1]]));
	rejects(fn() => new ComboReward('', 1));
	rejects(fn() => new ComboReward('bad', -1));
	rejects(fn() => new ComboReward('bad', 1, 0));
	rejects(fn() => (new Combo)->update(new Body, 0));
	$combo = new Combo;
	$combo->add(new ComboReward('Test', 10));
	$combo->settle();
	$combo->reset();
	check($combo->chain === [] && $combo->result === null && !$combo->failed);
});

test('top-right combo HUD bounds its action list and distinguishes results', function() {
	$combo = new Combo;

	foreach(range(1, 6) as $index)
	{
		$combo->add(new ComboReward("Trick $index", $index * 10, $index === 6 ? 2 : 1));
	}

	$view = DemoComboHud::view($combo);
	check($view['visible'] && $view['labels'] === ['Trick 3','Trick 4','Trick 5','Trick 6']);
	check($view['base'] === 210 && $view['multiplier'] === 7.0 && $view['total'] === 1470);
	check($view['overflow'] === 2 && $view['result'] === null);
	$combo->fail();
	$view = DemoComboHud::view($combo);
	check($view['visible'] && $view['labels'] === [] && $view['result'] === 1470 && $view['failed']);
});
