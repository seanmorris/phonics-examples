<?php
use PhpWasmEngine\Physics\
{
	Terrain,Body,Platformer,MapTerrain
};
use PhpWasmEngine\Input\Controls;
use PhpWasmEngine\Tiled\Loader;

test('down-jump charges on solid terrain instead of dropping through it', function() {
	$p = new Platformer(ground());
	$body = new Body(0, 60);
	tick($p, $body, [], 20);
	tick($p, $body, ['down' => true,'jump' => true]);
	check($body->grounded && $body->crouching && $body->charge > 0);
	near($body->vy, 0);
	near($body->dropTimer, 0);
});

test('raycasts return the earliest wall across negative spatial cells', function() {
	$terrain = new Terrain([
		['points' => [[-80,-20],[-79,-20],[-79,20],[-80,20]]]
		, [
			'points' => [
				[30, -20], [31,-20]
				, [
					31,20
				]
				, [30,20]
			]
		]
	]);

	$hit = $terrain->raycast(-200, 0, 400, 0);
	check($hit !== null);
	near($hit->x, -80);
	near($hit->t, .3);
	check($terrain->raycast(-200, 40, 400, 0) === null);
});

test('continuous body casts catch subpixel obstacles between sensor positions', function() {
	$terrain = new Terrain([['points' => [[50,1.25],[50.1,1.25],[50.1,1.5],[50,1.5]]]]);
	$body = new Body(0, 0);
	$hit = $terrain->castBody($body, 1000, 0);
	check($hit !== null);
	near($hit->t, .042);
	$p = new Platformer($terrain);
	$body->vx = 60000;
	$body->gravity = 0;
	tick($p, $body);
	check($body->x + $body->radius <= 50.001);
});

test('fast upward and diagonal sweeps hit thin ceilings and corners', function() {
	$terrain = new Terrain([['points' => [[-100,-101],[100,-101],[100,-100],[-100,-100]]]]);
	$p = new Platformer($terrain);
	$body = new Body;
	$body->vy = -8000;
	tick($p, $body);
	check($body->y - $body->halfHeight >= -100.01);
	near($body->vy, 0);
	$terrain = new Terrain([['points' => [[50,50],[50.1,50],[50.1,50.1],[50,50.1]]]]);
	check($terrain->castBody(new Body, 1000, 1000) !== null);
});

test('shared tile edges do not form invisible walls', function() {
	$loader = new Loader;
	$map = $loader->map(dirname(__DIR__, 3) . '/examples/assets/infinite.tmj');
	$terrain = MapTerrain::build($map);
	$p = new Platformer($terrain);
	$body = new Body(80, 100);
	tick($p, $body, [], 40);
	tick($p, $body, ['right' => true], 90);
	check($body->grounded && $body->x > 500);
	near($body->y + $body->halfHeight, 448, .01);
});

test('tangent motion crosses from an open surface onto a solid ledge corner', function() {
	$terrain = new Terrain;
	$terrain->add([[-100,100],[100,100]], true);
	$terrain->add([[100,100],[200,100],[200,200],[100,200]]);
	$body = new Body(80, 82);
	$body->grounded = true;
	$body->groundSpeed = 240;
	$body->friction = 0;
	$body->gravity = 0;
	$movement = new Platformer($terrain);
	tick($movement, $body, [], 20);
	check($body->grounded && $body->x > 120);
	near($body->groundSpeed, 240);
	near($body->supportY(), 100, .01);
});

test('surface attachment follows a full loop and releases at low speed', function() {
	$terrain = new Terrain;

	for($i = 0; $i < 128; $i++)
	{
		$a = M_PI / 2 - $i * M_PI / 64;
		$b = M_PI / 2 - ($i + 1) * M_PI / 64;

		$terrain->add([[300 + cos($a) * 160,300 + sin($a) * 160], [300 + cos($b) * 160, 300 + sin($b) * 160]]);
	}

	$p = new Platformer($terrain);
	$body = new Body(300, 442);
	$body->grounded = true;
	$body->groundSpeed = 1200;
	$body->friction = 0;
	$minY = 1000;
	$minX = 1000;
	$maxX = -1000;
	$angle = 0;

	for($i = 0; $i < 60; $i++)
	{
		tick($p, $body);
		$minY = min($minY, $body->y);
		$minX = min($minX, $body->x);
		$maxX = max($maxX, $body->x);
		$angle = max($angle, abs($body->angle));
		check($body->grounded);
	}

	check($minY < 170 && $minX < 170 && $maxX > 430 && $angle > 3);
	$body = new Body(300, 158);
	$body->grounded = true;
	$body->angle = M_PI;
	tick($p, $body);
	check(!$body->grounded && $body->vy > 0);
});

test('jump impulse follows the surface normal and preserves tangent momentum', function() {
	$body = new Body(0, 0);
	$body->grounded = true;
	$body->angle = -M_PI / 4;
	$body->groundSpeed = 600;
	$body->friction = 0;
	$body->gravity = 0;
	$p = new Platformer(new Terrain);
	tick($p, $body, ['jump' => true]);
	near($body->vx, cos(-M_PI / 4) * 600 + sin(-M_PI / 4) * 660);
	near($body->vy, sin(-M_PI / 4) * 600 - cos(-M_PI / 4) * 660);
});
