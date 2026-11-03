<?php
use PhpWasmEngine\Effects\CameraShake;
use PhpWasmEngine\Gameplay\PlatformCamera;
use PhpWasmEngine\Physics\Body;
use PhpWasmEngine\Render\Camera;

test('camera top-left panning retains exponential follow compatibility', function() {
	$direct = new Camera(0, 0, 200, 100);
	$follow = clone $direct;
	$direct->panTo(50, 50, .25, 8);
	$follow->follow(150, 100, .25, 8, .5, .5);
	near($direct->x, $follow->x);
	near($direct->y, $follow->y);

	$whole = new Camera;
	$parts = new Camera;
	$whole->panTo(100, 200, 1, 5);

	foreach(range(1, 10) as $_)
	{
		$parts->panTo(100, 200, .1, 5);
	}

	near($whole->x, $parts->x);
	near($whole->y, $parts->y);
});

test('camera zoom keeps its chosen viewport point stationary', function() {
	$camera = new Camera(100, 50, 960, 540);
	$center = [$camera->x + $camera->width / $camera->zoom / 2, $camera->y + $camera->height / $camera->zoom / 2];
	$camera->setZoom(2);
	near($camera->x + $camera->width / $camera->zoom / 2, $center[0]);
	near($camera->y + $camera->height / $camera->zoom / 2, $center[1]);

	$rightTop = [$camera->x + $camera->width / $camera->zoom, $camera->y];
	$camera->setZoom(.5, 1, 0);
	near($camera->x + $camera->width / $camera->zoom, $rightTop[0]);
	near($camera->y, $rightTop[1]);

	rejects(fn() => $camera->setZoom(0));
	rejects(fn() => $camera->setZoom(INF));
	rejects(fn() => $camera->setZoom(1, -1));
	rejects(fn() => $camera->setZoom(1, .5, NAN));
	rejects(fn() => (new Camera(zoom: 0))->setZoom(1));
});

test('camera shake follows the reference alternating decay without moving the follow camera', function() {
	$camera = new Camera(10, 20);
	$shake = new CameraShake;
	$shake->impulse(y: 7);
	$shake->update(1 / 60);
	$view = $shake->view($camera);
	near($shake->y, -6.65);
	near($view->x, 10);
	near($view->y, 13.35);
	near($camera->x, 10);
	near($camera->y, 20);

	$whole = new CameraShake;
	$parts = new CameraShake;
	$whole->impulse(4, 7);
	$parts->impulse(4, 7);
	$whole->update(1 / 30);

	foreach(range(1, 4) as $_) $parts->update(1 / 120);

	near($whole->x, $parts->x);
	near($whole->y, $parts->y);
	rejects(fn() => $shake->impulse(INF));
	rejects(fn() => $shake->update(-1));
});

test('platform camera pans into and out of world bounds without snapping', function() {
	$behavior = new PlatformCamera;
	$behavior->setMode('boss');
	$behavior->setWorldBounds(960, 2240);
	$camera = new Camera(480, 100, 960, 540);
	$body = new Body(960, 430);
	$body->grounded = true;
	$behavior->update($camera, $body, 1 / 60);
	check($camera->x > 480 && $camera->x < 960);

	foreach(range(1, 240) as $_)
	{
		$behavior->update($camera, $body, 1 / 60);
	}

	near($camera->x, 960, .01);
	$behavior->clearWorldBounds();
	$behavior->setMode('normal');
	$behavior->update($camera, $body, 1 / 60);
	check($camera->x > 480 && $camera->x < 960);

	$behavior->setWorldBounds(100, 300);
	$narrow = new Camera(0, 0, 500, 300);
	$behavior->update($narrow, $body, 10);
	near($narrow->x, -50);
});

test('platform camera derives movement modes and directional framing', function() {
	$behavior = new PlatformCamera;
	$camera = new Camera(0, 0, 200, 100);
	$body = new Body(100, 100);
	$body->grounded = true;
	$body->groundSpeed = 1020;
	$behavior->update($camera, $body, 1);
	check($behavior->mode === 'normal');
	near($behavior->targetAnchorX, .1);

	$body->angle = M_PI / 2;
	$body->ability = 'climb';
	$body->groundSpeed = 0;
	$behavior->update($camera, $body, 1);
	check($behavior->mode === 'climbing');
	near($behavior->targetAnchorX, .33);

	$body->ability = '';
	$body->rolling = true;
	$behavior->update($camera, $body, 1);
	check($behavior->mode === 'tube');

	$body->rolling = false;
	$body->grounded = false;
	$behavior->sync(new Body(0, 0));
	$behavior->update($camera, $body, .5);
	check($behavior->mode === 'normal');
	$behavior->update($camera, $body, .25);
	check($behavior->mode === 'aerial');
});

test('wall-gravity jumps pan only along their horizontal motion', function() {
	$behavior = new PlatformCamera;
	$camera = new Camera(690, 50, 960, 540);
	$body = new Body(1200, 320);
	$body->setGravityDirection(1, 0);
	$body->angle = $body->uprightAngle();
	$body->grounded = false;
	$body->jumping = true;
	$body->vx = -660;

	$behavior->sync($body);
	$startY = $camera->y;

	foreach(range(1, 60) as $_)
	{
		$body->x += $body->vx / 60;
		$behavior->update($camera, $body, 1 / 60);
	}

	check($behavior->mode === 'aerial');
	near($behavior->targetAnchorX, .75);
	near($behavior->targetAnchorY, .5);
	near($camera->y, $startY);
	check($camera->x < 690);
});

test('ceiling-gravity jumps retain world-direction look ahead while airborne', function() {
	$behavior = new PlatformCamera;
	$camera = new Camera(500, -150, 960, 540);
	$body = new Body(1000, 180);
	$body->setGravityDirection(0, -1);
	$body->angle = $body->uprightAngle();
	$body->grounded = false;
	$body->jumping = true;
	$body->vx = 600;
	$body->vy = 300;

	$behavior->sync($body);
	$previousX = $camera->x;

	foreach(range(1, 60) as $_)
	{
		$body->x += $body->vx / 60;
		$body->y += $body->vy / 60;
		$behavior->update($camera, $body, 1 / 60);
		check($camera->x >= $previousX, 'camera must keep following positive world motion');
		$previousX = $camera->x;
	}

	check($behavior->mode === 'aerial');
	check($behavior->targetAnchorX < .5);
	near($behavior->targetAnchorY, .25);
	check($camera->x > 500);
});

test('platform camera delays look framing and supports scripted focus', function() {
	$behavior = new PlatformCamera;
	$camera = new Camera(0, 0, 200, 100);
	$body = new Body(100, 100);
	$body->grounded = true;

	$behavior->update($camera, $body, .74, 1);
	near($behavior->targetAnchorY, .5);
	$behavior->update($camera, $body, .01, 1);
	near($behavior->targetAnchorY, 0);
	$behavior->update($camera, $body, .75, -1);
	near($behavior->targetAnchorY, .75);

	$before = $camera->x;
	$behavior->focus(1000, 300);
	$behavior->update($camera, $body, 1 / 60);
	check($behavior->mode === 'panning' && $camera->x > $before && $camera->x < 900);
	$behavior->clearFocus();
	$behavior->update($camera, $body, 1 / 60);
	check($behavior->mode === 'normal');
});

test('platform camera validates modes, focus, bounds, limits, and update values', function() {
	$behavior = new PlatformCamera;
	$body = new Body;
	$camera = new Camera;
	$modes = PlatformCamera::modes();

	foreach($modes as $mode)
	{
		$behavior->setMode($mode);
		$behavior->update($camera, $body, 1 / 60);
		check(is_finite($behavior->targetAnchorX) && is_finite($behavior->targetAnchorY));
	}

	rejects(fn() => $behavior->setMode('unknown'));
	rejects(fn() => $behavior->focus(INF, 0));
	rejects(fn() => $behavior->focus(0, 0, 'auto'));
	rejects(fn() => $behavior->setWorldBounds(10, 0));
	rejects(fn() => $behavior->setWorldBounds(0, 10, 0, null));
	rejects(fn() => $behavior->setCameraLimits(10, 0));
	rejects(fn() => $behavior->update($camera, $body, -1));

	$behavior->setCameraLimits(maxX: 20, maxY: 30);
	$behavior->update($camera, $body, 10);
	check($camera->x <= 20 && $camera->y <= 30);
});
