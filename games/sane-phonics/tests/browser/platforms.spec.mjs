import {test, expect} from '@playwright/test';

const state = page => page.locator('canvas').evaluate(canvas => ({
	body: JSON.parse(canvas.dataset.body ?? 'null')
	, platforms: JSON.parse(canvas.dataset.platforms ?? 'null')
}));

async function run(page, source)
{
	return page.evaluate(async source => {
		let output = '';

		const listener = event => output += event.detail.join('');
		enginePhp.addEventListener('output', listener);

		try
		{
			const code = await enginePhp.run('<?php ' + source);

			if(code) throw Error('PHP returned ' + code);

			return output;
		}
		finally
		{
			enginePhp.removeEventListener('output', listener);
		}
	}, source
	);
}

test.afterEach(async({page}) => {
	await page.evaluate(() => window.enginePhp?.refresh()).catch(() => {});
});

test('platform course renders and carries riders through squine and linked motion in WASM', async({page}) => {
	const errors = [];
	page.on('pageerror', error => errors.push(error.message));
	await page.goto('/sane-phonics/');
	await page.evaluate(() => window.bootEngine({intro: false, scene: 'platforms'}));
	await expect.poll(async() => (await state(page)).platforms?.length, {timeout: 60000}).toBe(6);
	await expect.poll(async() => (await state(page)).platforms?.[0].settlement).toBe(8);

	const results = JSON.parse(await run(page, `
		$engine->userPaused = true;
		$engine->controls->reset();
		$engine->controls->set([]);
		$checks = [];
		foreach($game->level->platforms->platforms as $platform)
		{
			$body->x = $platform->x;
			$body->halfHeight = $body->standingHeight;
			$body->y = $platform->top() - $body->halfHeight;
			$body->vx = $body->vy = $body->groundSpeed = $body->angle = 0;
			$body->grounded = true;
			$body->jumping = $body->rolling = false;
			$body->dropTimer = 0;
			$start = [$platform->x, $platform->y];
			$error = 0;
			for($i = 0; $i < 180; $i++)
			{
				$game->level->movement->step($body, $engine->controls, 1 / 60);
				$game->level->platforms->update($engine->entities, 1 / 60);
				$error = max($error, abs($body->x - $platform->x), abs($body->supportY() - $platform->top()));
			}
			$sprite = $engine->entities->get($platform->entity, PhpWasmEngine\\Render\\Sprite::class);
			$checks[] = [$platform->sourceId, $error, $body->grounded,
				abs($sprite->x - $platform->x) + abs($sprite->y - $platform->y),
				hypot($start[0] - $platform->x, $start[1] - $platform->y)];
		}
		echo json_encode($checks);
	`
	));

	for(const [sourceId, error, grounded, spriteError, distance] of results)
	{
		expect(error, 'rider contact on ' + sourceId).toBeLessThan(.02);
		expect(grounded).toBe(true);
		expect(spriteError).toBeLessThan(.001);

		if(sourceId !== 100) expect(distance).toBeGreaterThan(1);
	}

	await page.screenshot({path: 'test-results/platforms-course.png'});
	expect(errors).toEqual([]);
});

test('main and pause menus expose platforms and level changes remove their entities and shapes', async({page}) => {
	await page.goto('/sane-phonics/');
	await page.evaluate(() => window.bootEngine({intro: true}));

	const main = JSON.parse(await run(page, `
		$game->startup->showMenu();
		$game->startup->menu->select('level');
		$game->startup->menu->activate();
		echo json_encode(array_keys($game->startup->menu->rows()));
	`
	));

	expect(main).toContain('platforms');

	await run(page, `
		$game->startup->menu->select('platforms');
		$game->startup->menu->activate();
		$game->startup->menu->select('play');
		$game->startup->menu->activate();
	`
	);

	await expect.poll(async() => (await state(page)).platforms?.length, {timeout: 60000}).toBe(6);

	await run(page, `
		$oldPlatformSystem = $game->level->platforms;
		$oldPlatformTerrain = $game->level->terrain;
		$oldPlatformShapes = [];
		foreach($oldPlatformSystem->platforms as $platform)
		{
			$oldPlatformShapes[] = [$platform->entity, $platform->shape];
		}
		$game->selectScene('platformer');
	`
	);

	await expect(page.locator('canvas')).toHaveAttribute('data-scene', 'platformer');

	const retired = JSON.parse(await run(page, `
		$clean = true;
		foreach($oldPlatformShapes as [$id, $shape])
		{
			$clean = $clean && !$engine->entities->alive($id) && !$oldPlatformTerrain->hasShape($shape);
		}
		echo json_encode([$clean, $oldPlatformSystem->platforms === []]);
	`
	));

	expect(retired).toEqual([true, true]);
	await page.keyboard.press('p');
	await expect.poll(() => page.locator('canvas').evaluate(canvas => JSON.parse(canvas.dataset.menu).open)).toBe(true);
	await run(page, "$pauseMenu->menu->select('level'); $pauseMenu->menu->activate();");

	await expect.poll(
		() => page.locator('canvas').evaluate(canvas => Object.keys(JSON.parse(canvas.dataset.menu).values))
	)
		.toContain('platforms');
});
