import {test, expect} from '@playwright/test';

const hud = page => page.locator('canvas').evaluate(canvas => JSON.parse(canvas.dataset.hud ?? 'null'));

const debug = page => page.locator('canvas').evaluate(canvas => JSON.parse(canvas.dataset.debug ?? 'null'));

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

test('animated map rings become collectible entities and update the separate player HUD', async({page}) => {
	const errors = [];
	page.on('pageerror', error => errors.push(error.message));
	await page.goto('/sane-phonics/');
	await page.evaluate(() => window.bootEngine({intro: false, scene: 'platformer'}));
	await expect.poll(() => hud(page)).not.toBeNull();
	await expect.poll(async() => (await hud(page)).visible).toBe(true);
	const initial = await hud(page);
	const diagnostics = await debug(page);

	expect(initial.rings).toBe(0);
	expect(initial.score).toBe(0);
	expect(initial.rows['SCORE:']).toBe('     0');
	expect(initial.rows['TIME:']).toMatch(/^\d{2}:\d{2}$/);
	expect(initial.rows['RINGS:']).toBe('   0');
	expect(initial.rows.FPS).toBeUndefined();
	expect(initial.draws).toBe(1);
	expect(diagnostics.showFps).toBe(true);
	expect(diagnostics.draws).toBe(1);

	expect(
		Number(await run(page, 'echo count($engine->entities->query(PhpWasmEngine\\Gameplay\\Ring::class));'))
	).toBe(23);

	const remaining = Number(await run(page, `
		$ids = $engine->entities->query(PhpWasmEngine\\Gameplay\\Ring::class);
		$ring = $engine->entities->get($ids[0], PhpWasmEngine\\Gameplay\\Ring::class);
		$body->x = $ring->x - 40;
		$body->y = $ring->y;
		$game->level->rings->sync($game->entity, $body);
		$body->x = $ring->x + 40;
		$game->level->rings->update($engine->entities, 1 / 60);
		echo count($engine->entities->query(PhpWasmEngine\\Gameplay\\Ring::class));
	`
	));

	expect(remaining).toBe(22);
	await expect.poll(async() => (await hud(page)).rings).toBe(1);
	expect((await hud(page)).rows['RINGS:']).toBe('   1');
	expect(errors).toEqual([]);
});
