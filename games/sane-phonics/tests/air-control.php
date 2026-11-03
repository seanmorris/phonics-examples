<?php
use PhpWasmEngine\Input\Controls;
use PhpWasmEngine\Physics\Body;
use PhpWasmEngine\Physics\Platformer;
use PhpWasmEngine\Physics\Terrain;
use PhpWasmEngine\Physics\WaterRegion;
use PhpWasmEngine\Physics\WaterRegions;

test('ground and rail jumps and edge falls share the reference air steering gate', function() {
	foreach([false, true] as $rail)
	foreach([false, true] as $jump)
	foreach([-1, 1] as $direction)
	foreach([600, 1080, 1440] as $speed)
	{
		$terrain = new Terrain;

		if($rail)
		{
			$terrain->addRail([[-80, 100], [80, 100]]);
		}
		else
		{
			$terrain->add([[-80, 100], [80, 100], [80, 500], [-80, 500]]);
		}

		$movement = new Platformer($terrain);
		$body = new Body(0, 82);
		$body->grounded = true;
		$body->grinding = $rail;
		$body->groundSpeed = $speed * $direction;
		$body->facing = $body->direction = $direction;
		$body->friction = $body->gravity = $body->grindAcceleration = 0;
		$input = new Controls;
		$input->set($jump ? ['jump' => true] : []);

		for($frame = 0; $body->grounded && $frame < 60; $frame++)
		{
			$movement->step($body, $input, 1 / 60);
		}

		check(!$body->grounded && !$body->grinding && $body->jumping === $jump);
		near($body->vx, $direction * $speed);
		$input->set(['jump' => $jump, $direction > 0 ? 'left' : 'right' => true]);
		$movement->step($body, $input, 1 / 60);
		near($body->vx, $direction * ($speed < 1080 ? $speed - 18 : $speed));
		check($body->facing === $direction && $body->direction === $direction);
	}
});

test('all profiles reverse ordinary air travel at reference strength without turning their look', function() {
	foreach(['runner', 'flyer', 'glider'] as $profile)
	foreach([-1, 1] as $direction)
	foreach([.5, 1] as $strength)
	foreach([60, 120] as $rate)
	{
		$movement = new Platformer(new Terrain);
		$body = new Body(0, -1000, $profile);
		$body->vx = 240 * $direction;
		$body->gravity = 0;
		$body->facing = $body->direction = $direction;
		$input = new Controls;
		$input->set([$direction > 0 ? 'left' : 'right' => $strength]);

		for($frame = 0; $frame < $rate / 2; $frame++)
		{
			$movement->step($body, $input, 1 / $rate);
		}

		near($body->vx, $direction * (240 - 540 * $strength));
		check($body->vx * $direction < 0 && $body->ability === '');
		check($body->facing === $direction && $body->direction === $direction);
		$before = $body->vx;
		$input->set([]);
		$movement->step($body, $input, 1 / $rate);
		near($body->vx, $before);
	}
});

test('apex drag affects only rising jumps inside its open velocity interval', function() {
	foreach([false, true] as $jumping)
	foreach([-600, -240, -120, -.001, 0, 120] as $vy)
	foreach([-1, 1] as $direction)
	{
		$body = new Body(0, -1000);
		$body->jumping = $jumping;
		$body->gravity = 0;
		$body->vx = 600 * $direction;
		$body->vy = $vy;
		(new Platformer(new Terrain))->step($body, new Controls, 1 / 60);
		$expected = $jumping && $vy > -240 && $vy < 0 ? 581.25 : 600;
		near($body->vx, $expected * $direction);
	}
});

test('apex damping has the same elapsed-time decay at sixty and one hundred twenty hertz', function() {
	foreach([60, 120] as $rate)
	{
		$movement = new Platformer(new Terrain);
		$body = new Body(0, -1000);
		$body->jumping = true;
		$body->gravity = 0;
		$body->vx = 600;
		$body->vy = -120;
		$input = new Controls;

		for($frame = 0; $frame < $rate / 10; $frame++)
		{
			$movement->step($body, $input, 1 / $rate);
		}

		near($body->vx, 600 * (31 / 32) ** 6);
	}
});

test('apex damping restores air steering below the speed gate while fast falls retain momentum', function() {
	foreach([-1, 1] as $direction)
	foreach([false, true] as $jumping)
	foreach([1080, 1100, 1440] as $speed)
	{
		$body = new Body(0, -1000);
		$body->jumping = $jumping;
		$body->gravity = 0;
		$body->vx = $direction * $speed;
		$body->vy = -120;
		$input = new Controls;
		$input->set([$direction > 0 ? 'left' : 'right' => true]);
		(new Platformer(new Terrain))->step($body, $input, 1 / 60);
		$expected = $jumping ? $speed * 31 / 32 : $speed;
		near($body->vx, $direction * ($expected < 1080 ? $expected - 18 : $expected));
	}
});

test('jump release halves upward velocity once without changing plain falls or ability movement', function() {
	foreach(['runner', 'flyer', 'glider'] as $profile)
	foreach([false, true] as $jumping)
	foreach([-660, -300, -120, 0, 120] as $vy)
	{
		$movement = new Platformer(new Terrain);
		$body = new Body(0, -1000, $profile);
		$body->jumping = $jumping;
		$body->gravity = 0;
		$body->vy = $vy;
		$input = new Controls;
		$input->set(['jump' => true]);
		$input->set([]);
		$expected = $jumping && $vy < 0 ? $vy / 2 : $vy;
		$movement->step($body, $input, 1 / 60);
		near($body->vy, $expected);
		$input->set([]);
		$movement->step($body, $input, 1 / 60);
		near($body->vy, $expected);
	}

	foreach(['', 'fly', 'glide', 'lightdash', 'hanging', 'electric-jump'] as $ability)
	{
		$body = new Body(0, -1000);
		$body->jumping = true;
		$body->ability = $ability;
		$body->lightDashed = $ability === '';
		$body->gravity = 0;
		$body->vy = -120;
		$input = new Controls;
		$input->set(['jump' => true]);
		$input->set([]);
		(new Platformer(new Terrain))->step($body, $input, 1 / 60);
		near($body->vy, -120);
	}
});

test('jump release precedes apex damping and damping precedes steering', function() {
	$body = new Body(0, -1000);
	$body->jumping = true;
	$body->vx = 600;
	$body->vy = -300;
	$input = new Controls;
	$input->set(['jump' => true]);
	$input->set(['left' => true]);
	(new Platformer(new Terrain))->step($body, $input, 1 / 60);
	near($body->vx, 563.25);
	near($body->vy, -120);
});

test('air steering keeps water scaling and respects a customized speed limit', function() {
	$water = new WaterRegion(-1000, -1000, 2000, 2000, drag: .5);
	$movement = new Platformer(new Terrain, waters: new WaterRegions($water));
	$body = new Body;
	$body->maxSpeed = 720;
	$body->vx = 600;
	$input = new Controls;
	$input->set(['left' => true]);
	$movement->step($body, $input, 1 / 60);
	near($body->vx, 591);
	$body->vx = 720;
	$movement->step($body, $input, 1 / 60);
	near($body->vx, 720);
});
