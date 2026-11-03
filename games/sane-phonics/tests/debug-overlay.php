<?php
use PhpWasmEngine\Physics\Body;
use PhpWasmEngine\Render\BitmapFont;
use PhpWasmEngine\Tiled\Loader;

require_once dirname(__DIR__, 3) . '/examples/DemoDebugOverlay.php';

test(
	'debug FPS measures browser frames independently of simulation and resets after suspension'
	, function() {
		$font = new BitmapFont(new Loader, dirname(__DIR__, 3) . '/examples/assets/font.tsj');
		$debug = new DemoDebugOverlay($font);
		$frame = ['focused' => true, 'events' => []];

		for($i = 0; $i < 30; $i++)
		{
			$debug->update(1 / 60, $frame);
		}

		near($debug->fps, 60);
		near($debug->frameMilliseconds, 1000 / 60);
		check($debug->frame === 30);
		$debug->update(0, $frame);
		check($debug->fps === null && $debug->frameMilliseconds === null);

		for($i = 0; $i < 15; $i++)
		{
			$debug->update(1 / 30, $frame);
		}

		near($debug->fps, 30);
		check($debug->frame === 46);
		$debug->close();
		$debug->close();
	}
);

test(
	'debug shortcut accepts fresh focused F9 presses without repeats or modified keys'
	, function() {
		$font = new BitmapFont(new Loader, dirname(__DIR__, 3) . '/examples/assets/font.tsj');
		$debug = new DemoDebugOverlay($font);
		$key = ['type' => 'key', 'key' => 'F9', 'repeat' => false, 'ctrl' => false];
		check(!$debug->enabled && $debug->showFps);
		$debug->update(.02, ['focused' => true, 'events' => [$key]]);
		check($debug->enabled);

		$debug->update(.02, ['focused' => true, 'events' => [array_replace($key, ['repeat' => true])]]);
		$debug->update(.02, ['focused' => false, 'events' => [$key]]);

		$debug->update(.02, ['focused' => true, 'events' => [array_replace($key, ['ctrl' => true])]]);
		check($debug->enabled);
		$debug->update(.02, ['focused' => true, 'events' => [$key]]);
		check(!$debug->enabled && $debug->showFps);
	}
);

test(
	'debug player values report surface orientation and distinguish camera-only scenes'
	, function() {
		$body = new Body(12.5, -30.25, 'glider');
		$body->grounded = true;
		$body->groundSpeed = -240;
		$body->vx = -180;
		$body->vy = 240;
		$body->collisionLayer = 2;

		foreach([0, M_PI / 2, M_PI, -M_PI / 2, M_PI * 2] as $index => $angle)
		{
			$body->angle = $angle;
			$rows = DemoDebugOverlay::playerRows($body);

			check(
				$rows['Surface'] === [
					'Floor (0)', 'L-wall (1)', 'Ceiling (2)', 'R-wall (3)'
					, 'Floor (0)'
				][$index]
			);
		}

		check($rows['X px'] === '12.500' && $rows['Y px'] === '-12.250');
		check($rows['Gnd px/s'] === '-240.000' && $rows['Collision layer'] === '2');
		check($rows['Facing / direction'] === '1 / 1');
		check($rows['X px/s'] === '-180.000' && $rows['Y px/s'] === '240.000');
		near((float)$rows['Air rad'], atan2(240, -180));
		$body->grounded = false;
		$body->ability = 'glide';
		$rows = DemoDebugOverlay::playerRows($body);
		check($rows['Surface'] === 'Air' && $rows['State'] === 'glide');
		check(DemoDebugOverlay::playerRows(null) === ['Player' => 'None (camera)']);
	}
);
