<?php
use PhpWasmEngine\Ecs\Registry;
use PhpWasmEngine\Gameplay\Combo;
use PhpWasmEngine\Gameplay\ComboReward;
use PhpWasmEngine\Gameplay\LightDash;
use PhpWasmEngine\Gameplay\LightDashes;
use PhpWasmEngine\Gameplay\PlayerCombat;
use PhpWasmEngine\Gameplay\Ring;
use PhpWasmEngine\Gameplay\RingCounter;
use PhpWasmEngine\Gameplay\Rings;
use PhpWasmEngine\Gameplay\ScatteredRing;
use PhpWasmEngine\Input\Controls;
use PhpWasmEngine\Physics\Body;
use PhpWasmEngine\Physics\Platformer;
use PhpWasmEngine\Physics\Terrain;
use PhpWasmEngine\Tiled\Loader;
use PhpWasmEngine\Tiled\TileMap;

/**
 * Build an isolated runner and ring trail using the production pickup callback.
 *
 * @param list<array{float|int,float|int}> $points Ring center coordinates relative to the origin.
 * @return array{Registry,Body,LightDash,LightDashes,Platformer,Rings,int,RingCounter,Combo} Fixture parts.
 */
function lightDashFixture(array $points): array
{
	$registry = new Registry;
	$body = new Body;
	$dash = new LightDash;
	$counter = new RingCounter;
	$combo = new Combo;
	$player = $registry->create($body, $dash, $counter, $combo);
	$control = new LightDashes($registry);
	$movement = new Platformer(new Terrain);
	$map = new TileMap('empty.tmj', ['orientation' => 'orthogonal', 'tilewidth' => 32, 'tileheight' => 32]);
	$rings = new Rings($registry, $map, onCollect: [$control, 'collected']);
	$rings->sync($player, $body);

	foreach($points as [$x, $y])
	{
		$registry->create(new Ring($x, $y, 8));
	}

	return [$registry, $body, $dash, $control, $movement, $rings, $player, $counter, $combo];
}

test('light-dash requires an airborne runner, button two, and a forward pair within 48 pixels', function() {
	foreach(['runner', 'flyer', 'glider'] as $profile)
	{
		foreach([true, false] as $grounded)
		{
			[, $body, $dash, $control, , , $player] = lightDashFixture([[32, 0], [64, 0]]);
			$body->profile = $profile;
			$body->grounded = $grounded;
			$input = new Controls;
			$input->set(['action' => true]);
			check(!$control->control($player, $input, 1 / 60));
			$input->set(['lightdash' => true]);
			check($control->control($player, $input, 1 / 60) === ($profile === 'runner' && !$grounded));
		}
	}

	foreach([[[32, 0]], [[49, 0], [81, 0]], [[32, 0], [81, 0]], [[32, 0], [30, 40]]] as $points)
	{
		[, , , $control, , , $player] = lightDashFixture($points);
		$input = new Controls;
		$input->set([2 => true]);
		check(!$control->control($player, $input, 1 / 60), 'Reject isolated, distant, or reversing entrance');
	}

	foreach([-1, 1] as $direction)
	{
		[, $body, , $control, , , $player] = lightDashFixture([[-32 * $direction, 0], [-64 * $direction, 0]]);
		$body->vx = 60 * $direction;
		check(!$control->control($player, $input, 1 / 60), 'Do not acquire behind horizontal travel');
		$body->vx = 0;
		check($control->control($player, $input, 1 / 60), 'A stationary actor can choose either direction');
		check($body->direction === -$direction && $body->facing === -$direction);
	}
});

test(
	'light-dash follows curved rings in either direction and releases with momentum after the last pickup'
	, function() {
		foreach([1 / 60, 1 / 120] as $dt)
		{
			foreach([-1, 1] as $direction)
			{
				$points = [[32, 0], [64, 0], [96, -16], [128, -32], [144, -64], [160, -96], [192, -112]];

				foreach($points as &$point)
				{
					$point[0] *= $direction;
				}

				unset($point);
				[$registry, $body, $dash, $control, $movement, $rings, $player, $counter] = lightDashFixture($points);
				$input = new Controls;
				$input->set([2 => true]);
				check($control->control($player, $input, $dt));
				$input->set([]);

				for($i = 0; $i < 100 && $body->ability === 'lightdash'; $i++)
				{
					$movement->step($body, $input, $dt);
					$rings->update($registry, $dt);
					$control->control($player, $input, $dt);
				}

				check($counter->rings === count($points), 'Collect every curved trail ring in both directions');
				check($dash->target === null && $body->ability === '' && !$body->grounded);
				check($body->vx * $direction > 0 && $body->lightDashed);
			}
		}
	}
);

test('light-dash timeout, cooldown, and jump-release suppression use seconds and reset on landing', function() {
	[, $body, $dash, $control, $movement, , $player] = lightDashFixture([[32, -16], [64, -32]]);
	$input = new Controls;
	$input->set([2 => true, 0 => true]);
	check($control->control($player, $input, 1 / 60));
	near($dash->remaining, .15);
	near($dash->cooldown, .15);
	$input->set([]);

	for($i = 0; $i < 8; $i++)
	{
		check($control->control($player, $input, 1 / 60));
	}

	check(!$control->control($player, $input, 1 / 60));
	near($dash->cooldown, 0);
	$body->vy = -600;
	$body->jumping = true;
	$input->set([0 => true]);
	$input->set([]);
	$movement->step($body, $input, 1 / 60);
	near($body->vy, -570);
	$body->grounded = true;
	$control->control($player, $input, 1 / 60);
	check(!$body->lightDashed && $dash->remaining === 0.0);
	$body->grounded = false;
	$body->vy = -600;
	$movement->step($body, $input, 1 / 60);
	near($body->vy, -270);
});

test('active light-dash ignores directional and drop-dash input while preserving gravity', function() {
	[, $body, , $control, $movement, , $player] = lightDashFixture([[32, 0], [64, 0]]);
	$input = new Controls;
	$input->set([2 => true, 1 => true, 'left' => true]);
	$body->jumping = true;
	$body->charge = $body->dropCharge = 1;
	check($control->control($player, $input, 1 / 60));
	near($body->vx, 1650);
	$movement->step($body, $input, 1 / 60);
	near($body->vx, 1650);
	near($body->vy, 30);
	near($body->x, 27.5);
	near($body->dropCharge, 0);
	near($body->charge, 0);
	check($body->facing === 1 && $body->direction === 1);
});

test('light-dash collision casts stop at thin walls and ceilings without collecting beyond them', function() {
	foreach([false, true] as $vertical)
	{
		$points = $vertical ? [[0, -32], [0, -64], [0, -128]] : [[32, 0], [64, 0], [128, 0]];
		[$registry, $body, $dash, $control, $movement, $rings, $player, $counter] = lightDashFixture($points);

		$movement->terrain->add(
			$vertical
				? [[-100, -22], [100, -22], [100, -21], [-100, -21]]
				: [[20, -100], [21, -100], [21, 100], [20, 100]]
		);

		$input = new Controls;
		$input->set([2 => true]);
		check($control->control($player, $input, 1 / 60));
		$movement->step($body, $input, .1);
		$rings->update($registry, .1);
		check($body->ability === '');
		check($vertical ? $body->y >= -3.01 : $body->x <= 12);
		check($counter->rings === 0, 'A solid barrier prevents pickups on its far side');
		check(!$control->control($player, $input, 1 / 60));
		check($dash->target === null);
	}
});

test('light-dash adds one accruing reward to an existing combo and does not start one alone', function() {
	[$registry, $body, $dash, $control, , $rings, $player, , $combo] = lightDashFixture([[32, 0], [64, 0], [96, 0]]);
	$input = new Controls;
	$input->set([2 => true]);
	check($control->control($player, $input, 1 / 60));
	check($combo->chain === []);
	$combo->add(new ComboReward('Rail Grind', 100));
	$body->x = 32;
	$rings->update($registry, 1 / 60);
	$control->control($player, $input, 1 / 60);
	check(count($combo->chain) === 2 && $dash->reward->points === 10);
	$body->x = 64;
	$rings->update($registry, 1 / 60);
	$control->control($player, $input, 1 / 60);
	check(count($combo->chain) === 2 && $dash->reward->points === 20);
	$body->grounded = true;
	$control->control($player, $input, 1 / 60);
	check($combo->update($body, .1) === 240);
	check($dash->reward === null);
});

test('light-dash ignores ineligible scatter and cannot continue toward removed or stolen rings', function() {
	[$registry, $body, $dash, $control, , , $player] = lightDashFixture([[32, 0], [64, 0]]);
	$ids = $registry->query(Ring::class);
	$scatter = new ScatteredRing($player, 0, 0);
	$registry->add($ids[0], $scatter);
	$input = new Controls;
	$input->set([2 => true]);
	check(!$control->control($player, $input, 1 / 60));
	$scatter->age = $scatter->pickupDelay;
	check($control->control($player, $input, 1 / 60));
	$registry->destroy($dash->target);
	check(!$control->control($player, $input, 1 / 60));
	check($body->ability === '' && $dash->target === null);
});

test('light-dash damage and profile changes release steering without erasing knockback', function() {
	[, $body, $dash, $control, , , $player, $counter] = lightDashFixture([[32, 0], [64, 0]]);
	$input = new Controls;
	$input->set([2 => true]);
	$control->control($player, $input, 1 / 60);
	(new PlayerCombat)->damage($body, $counter, -1);
	check(!$control->control($player, $input, 1 / 60));
	near($body->vx, -240);
	near($body->vy, -360);
	check($dash->target === null);
	$body->profile = 'flyer';
	check(!$control->control($player, $input, 1 / 60));
	check(!$body->lightDashed && $dash->reward === null);
});

test('light-dash state is independent per collector and invalid deltas do not mutate it', function() {
	[$registry, $body, $dash, $control, , , $player] = lightDashFixture([[32, 0], [64, 0]]);
	$other = $registry->create(new Body(0, 200), new RingCounter, new LightDash);
	$input = new Controls;
	$input->set([2 => true]);
	check($control->control($player, $input, 1 / 60));
	check(!$control->control($other, $input, 1 / 60));
	check($body->ability === 'lightdash');

	foreach([0, -1, INF, NAN] as $dt)
	{
		try
		{
			$control->control($player, $input, $dt);
			check(false, 'Invalid delta must throw');
		}
		catch(InvalidArgumentException $error)
		{
		}
	}

	near($dash->remaining, .15);
});

test('light-dash retargeting respects the 128 pixel limit and removes every same-step pickup first', function() {
	foreach([128, 129] as $gap)
	{
		[$registry, $body, $dash, $control, , $rings, $player] = lightDashFixture([[32, 0], [64, 0], [64 + $gap, 0]]);
		$input = new Controls;
		$input->set([2 => true]);
		$control->control($player, $input, 1 / 60);
		$body->x = 64;
		$rings->update($registry, 1 / 60);
		check(count($registry->query(Ring::class)) === 1);
		check($control->control($player, $input, 1 / 60) === ($gap === 128));

		if($gap === 128)
		{
			check($dash->target === $registry->query(Ring::class)[0]);
			near($dash->remaining, .15);
		}
	}
});

test('both Tiled light-dash routes can be collected in either direction', function() {
	foreach([[80, 13], [528, 13], [656, 12], [1104, 12]] as [$start, $expected])
	{
		$map = (new Loader)->map(dirname(__DIR__, 3) . '/examples/assets/light-dash.tmj');
		$registry = new Registry;
		$body = new Body($start, 336);
		$dash = new LightDash;
		$counter = new RingCounter;
		$player = $registry->create($body, $dash, $counter);
		$control = new LightDashes($registry);
		$rings = new Rings($registry, $map, onCollect: [$control, 'collected']);
		$rings->sync($player, $body);
		$movement = new Platformer(new Terrain);
		$input = new Controls;
		$input->set([2 => true]);
		check($control->control($player, $input, 1 / 60));
		$input->set([]);

		for($i = 0; $body->ability === 'lightdash' && $i < 100; $i++)
		{
			$movement->step($body, $input, 1 / 60);
			$rings->update($registry, 1 / 60);
			$control->control($player, $input, 1 / 60);
		}

		check($counter->rings === $expected, 'Collect the whole route from ' . $start);
		check($body->ability === '' && $dash->target === null);
	}
});
