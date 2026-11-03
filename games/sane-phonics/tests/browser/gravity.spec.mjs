import {expect, test} from '@playwright/test';

/**
 * Execute PHP inside the running demo module.
 * @param {import('@playwright/test').Page} page Browser page owning the PHP runtime.
 * @param {string} source PHP statements to execute after the opening tag.
 * @returns {Promise<string>} Captured standard output from the PHP invocation.
 */
async function run(page, source)
{
	const result = await page.evaluate(async source => {
		let output = '';
		let errors = '';

		const out = event => output += event.detail.join('');

		const error = event => errors += event.detail.join('');
		enginePhp.addEventListener('output', out);
		enginePhp.addEventListener('error', error);

		try
		{
			const code = await enginePhp.run('<?php ' + source);

			return {output, errors, code};
		}
		finally
		{
			enginePhp.removeEventListener('output', out);
			enginePhp.removeEventListener('error', error);
		}
	}, source
	);

	expect(result.errors).toBe('');
	expect(result.code).toBe(0);

	return result.output;
}

test('gravity course permits settling and exposes every control through the live ECS', async({page}) => {
	const errors = [];
	page.on('pageerror', error => errors.push(error.message));
	await page.goto('/sane-phonics/');
	await page.waitForFunction(() => typeof window.bootEngine === 'function');
	await page.evaluate(() => window.bootEngine({intro: false, scene: 'gravity', motionBlur: false}));
	await expect(page.locator('canvas')).toHaveAttribute('data-scene', 'gravity', {timeout: 60000});

	const features = JSON.parse(await page.locator('canvas').getAttribute('data-gravity'));
	expect(Object.keys(features.switches)).toHaveLength(4);
	expect(Object.keys(features.springs)).toHaveLength(4);

	const settled = await page.evaluate(async() => {
		const canvas = document.querySelector('canvas');
		const started = performance.now();

		while(performance.now() - started < 3000)
		{
			await new Promise(resolve => requestAnimationFrame(resolve));
			const body = JSON.parse(canvas.dataset.body || '{}');

			if(body.grounded)
			{
				await new Promise(resolve => setTimeout(resolve, 500));

				return JSON.parse(canvas.dataset.body || '{}');
			}
		}

		return {};
	});

	expect([settled.gravityX, settled.gravityY]).toEqual([0, 1]);
	expect(settled.grounded).toBe(true);
	expect(settled.x).toBeCloseTo(240, 0);

	const directions = JSON.parse(await run(page, `
		$directions = [];

		foreach([401, 402, 403, 404] as $id)
		{
			$game->level->gravityFeatures->activate($id, $body);
			$directions[] = [$body->gravityX, $body->gravityY];
		}

		echo json_encode($directions);
	`
	));

	expect(directions).toEqual([[1, 0], [0, -1], [-1, 0], [0, 1]]);

	const launch = JSON.parse(await run(page, `
		$body->x = 1200;
		$body->y = 526;
		$body->vx = $body->vy = $body->groundSpeed = 0;
		$body->setGravityDirection(0, 1);
		$body->angle = 0;
		$body->grounded = true;
		$game->level->gravityFeatures->update($engine->entities, 1 / 60);
		echo json_encode([
			$body->vx,
			$body->vy,
			$body->grounded,
			$body->jumping,
			$body->ability,
			$game->level->gravityFeatures->springs[411]->active
		]);
	`
	));

	expect(launch).toEqual([0, -1100, false, false, 'spring', true]);

	const curvedCeilings = JSON.parse(await run(page, `
		$results = [];

		foreach([[0, 1], [1, 0], [0, -1], [-1, 0]] as [$gravityX, $gravityY])
		{
			$world = static fn($tangent, $gravity) => [
				$gravityY * $tangent + $gravityX * $gravity,
				-$gravityX * $tangent + $gravityY * $gravity
			];
			$points = [];

			foreach(range(0, 24) as $segment)
			{
				$angle = $segment / 24 * M_PI_2;
				$points[] = $world(96 * cos($angle), -96 * sin($angle));
			}

			$points[] = $world(-240, -96);
			$terrain = new \\PhpWasmEngine\\Physics\\Terrain;
			$terrain->addShape($points, false, 0, false, false, true);
			[$x, $y] = $world(77.999, 28);
			$testBody = new \\PhpWasmEngine\\Physics\\Body($x, $y);
			$testBody->setGravityDirection($gravityX, $gravityY);
			$testBody->terminalSpeed = 4000;
			(new \\PhpWasmEngine\\Gameplay\\Spring(
				1, $x, $y, -$gravityX, -$gravityY, 32, 1100
			))->launch($testBody);
			$testMovement = new \\PhpWasmEngine\\Physics\\Platformer($terrain);

			foreach(range(1, 24) as $_)
			{
				$testMovement->step($testBody, new \\PhpWasmEngine\\Input\\Controls, 1 / 60);
			}

			$results[] = [
				$testBody->grounded,
				round(sin($testBody->angle) * $gravityX - cos($testBody->angle) * $gravityY, 3),
				round($testBody->x * $gravityX + $testBody->y * $gravityY, 3)
			];
		}

		echo json_encode($results);
	`
	));

	expect(curvedCeilings).toEqual([
		[true, 1, -78]
		, [true, 1, -78]
		, [true, 1, -78]
		, [true, 1, -78]
	]);

	const camera = JSON.parse(await run(page, `
		$game->cameraBehavior->setMode('auto');
		$game->cameraBehavior->setCameraLimits();
		$body->x = 1200;
		$body->y = 320;
		$body->setGravityDirection(1, 0);
		$body->angle = $body->uprightAngle();
		$body->grounded = false;
		$body->jumping = true;
		$body->vx = -660;
		$body->vy = 0;
		$camera->x = 690;
		$camera->y = 50;
		$game->cameraBehavior->sync($body);

		foreach(range(1, 60) as $_)
		{
			$body->x += $body->vx / 60;
			$game->cameraBehavior->update($camera, $body, 1 / 60);
		}

		$wall = [
			$game->cameraBehavior->targetAnchorX
			, $game->cameraBehavior->targetAnchorY
			, $camera->y
		];

		$body->x = 1000;
		$body->y = 180;
		$body->setGravityDirection(0, -1);
		$body->angle = $body->uprightAngle();
		$body->vx = 600;
		$body->vy = 300;
		$camera->x = 500;
		$camera->y = -150;
		$game->cameraBehavior->sync($body);
		$ceilingFollows = true;

		foreach(range(1, 60) as $_)
		{
			$previousX = $camera->x;
			$body->x += $body->vx / 60;
			$body->y += $body->vy / 60;
			$game->cameraBehavior->update($camera, $body, 1 / 60);
			$ceilingFollows = $ceilingFollows && $camera->x >= $previousX;
		}

		echo json_encode([
			$wall
			, $game->cameraBehavior->targetAnchorX
			, $game->cameraBehavior->targetAnchorY
			, $ceilingFollows
			, $camera->x
		]);
	`
	));

	expect(camera[0]).toEqual([.75, .5, 50]);
	expect(camera[1]).toBeLessThan(.5);
	expect(camera[2]).toBe(.25);
	expect(camera[3]).toBe(true);
	expect(camera[4]).toBeGreaterThan(500);
	expect(errors).toEqual([]);
});

test('live player input rotates into the active gravity basis', async({page}) => {
	const errors = [];
	page.on('pageerror', error => errors.push(error.message));
	await page.goto('/sane-phonics/');
	await page.waitForFunction(() => typeof window.bootEngine === 'function');
	await page.evaluate(() => window.bootEngine({intro: false, scene: 'gravity', motionBlur: false}));
	const canvas = page.locator('canvas');
	await expect(canvas).toHaveAttribute('data-scene', 'gravity', {timeout: 60000});

	const initial = JSON.parse(await run(page, `
		$body->x = 1500;
		$body->y = 300;
		$body->vx = $body->vy = $body->groundSpeed = 0;
		$body->gravity = 0;
		$body->setGravityDirection(1, 0);
		$body->angle = $body->uprightAngle();
		$body->grounded = $body->jumping = false;
		$body->ability = '';
		echo json_encode([$body->x, $body->y]);
	`
	));

	await page.keyboard.down('ArrowUp');

	await expect.poll(async() => JSON.parse(await canvas.getAttribute('data-body')).y, {timeout: 3000})
		.toBeLessThan(initial[1] - 80);

	await page.keyboard.up('ArrowUp');

	const body = JSON.parse(await canvas.getAttribute('data-body'));
	expect(body.x).toBeCloseTo(initial[0], 0);
	expect(body.gravityX).toBe(1);
	expect(body.gravityY).toBe(0);
	expect(errors).toEqual([]);
});

test('the visible curved-ceiling course cycles through all four gravity directions', async({page}) => {
	const errors = [];
	page.on('pageerror', error => errors.push(error.message));
	await page.goto('/sane-phonics/');
	await page.waitForFunction(() => typeof window.bootEngine === 'function');
	await page.evaluate(() => window.bootEngine({intro: false, scene: 'ceiling-curves', motionBlur: false}));
	const canvas = page.locator('canvas');
	await expect(canvas).toHaveAttribute('data-scene', 'ceiling-curves', {timeout: 60000});

	const features = JSON.parse(await canvas.getAttribute('data-gravity'));
	expect(Object.keys(features.switches)).toHaveLength(4);
	expect(Object.keys(features.springs)).toHaveLength(4);

	// Record the authored downward-gravity phase synchronously. Calling into the
	// runtime first, then waiting for rAF, can skip it while the automatic course
	// advances into the first wall-gravity section.
	const directionsPromise = page.evaluate(async() => {
		const canvas = document.querySelector('canvas');
		const seen = [];
		const started = performance.now();
		let previous = '';

		const observe = () => {
			const body = JSON.parse(canvas.dataset.body || '{}');
			const direction = JSON.stringify([body.gravityX, body.gravityY]);

			if(direction !== previous)
			{
				seen.push(JSON.parse(direction));
				previous = direction;
			}
		};

		observe();

		while(seen.length < 5 && performance.now() - started < 10000)
		{
			await new Promise(resolve => requestAnimationFrame(resolve));
			observe();
		}

		return seen;
	});

	const initialView = JSON.parse(await run(page, `echo json_encode([
		$body->x - $camera->x,
		$body->y - $camera->y,
		$camera->x,
		$camera->y
	]);`
	));

	expect(initialView[0]).toBeGreaterThan(0);
	expect(initialView[0]).toBeLessThan(1000);
	expect(initialView[1]).toBeGreaterThan(0);
	expect(initialView[1]).toBeLessThan(566);
	expect(initialView[2]).toBeGreaterThan(1000);
	expect(initialView[3]).toBeGreaterThan(1800);

	const directions = await directionsPromise;

	expect(directions.slice(0, 5)).toEqual([[0, 1], [-1, 0], [0, -1], [1, 0], [0, 1]]);
	expect(errors).toEqual([]);
});
