import {test, expect} from '@playwright/test';

const status = page => page.locator('canvas').evaluate(canvas => JSON.parse(canvas.dataset.phpPhant ?? 'null'));

async function boot(page)
{
	await page.goto('/games/phlappy-phant/');
	const canvas = page.locator('canvas');
	await expect(canvas).toHaveAttribute('data-php-phant', /"page":"title"/, {timeout: 45_000});
	await expect(page.locator('.loading')).toHaveClass(/complete/);

	return canvas;
}

test('a run flaps, scores, dies, keeps the best and fills any viewport', async({page}) => {
	const errors = [];
	page.on('pageerror', error => errors.push(error.message));
	await page.setViewportSize({width: 1280, height: 720});
	const canvas = await boot(page);
	const title = await status(page);

	// The title is a live waiting preview: the phant bobs while the terrain scrolls behind the open menu.
	await expect.poll(async() => (await status(page)).scroll).toBeGreaterThan(title.scroll + 10);
	await expect.poll(async() => (await status(page)).y).not.toBe(title.y);

	await page.locator('.phlappy-phant-targets').getByRole('button', {name: 'Play', exact: true}).click();
	await expect.poll(async() => (await status(page)).page).toBe('play');
	await expect.poll(async() => (await status(page)).phase).toBe('ready');
	await expect.poll(() => canvas.evaluate(element => element.phpPhantDebug.music.loaded)).toBe(true);
	await expect.poll(() => canvas.evaluate(element => element.phpPhantDebug.music.playing)).toBe(true);
	expect(await canvas.evaluate(element => element.phpPhantDebug.music)).toMatchObject({
		loop: true, muted: false, volume: .75, baseVolume: .75
	});
	expect(await canvas.evaluate(element => element.phpPhantDebug.mix)).toEqual({effects: .5, music: .75});

	// Wide frame: the phant sits near 28 % of the visible width, at the largest whole scale that keeps the course in view.
	const wide = await status(page);
	expect(wide.height).toBeGreaterThan(0);
	const scale = Math.max(1, Math.floor(wide.height / 320));
	expect(wide.viewport[2]).toBeCloseTo(scale, 5);
	expect(wide.viewport[3]).toBeCloseTo(Math.max(60, Math.min(200, .28 * wide.width / scale)) * scale, 3);
	const raster = await canvas.evaluate(element => {
		const box = element.parentElement.getBoundingClientRect();
		const dpr = Math.min(2, devicePixelRatio || 1);
		const physical = [Math.round(box.width * dpr), Math.round(box.height * dpr)];
		const block = Math.max(1, Math.floor(physical[1] / 320));

		return {physical, block, buffer: [element.width, element.height]};
	});
	expect(raster.buffer).toEqual(raster.physical.map(value => Math.ceil(value / raster.block)));
	expect(raster.buffer[0] * raster.buffer[1]).toBeLessThan(raster.physical[0] * raster.physical[1]);

	await canvas.focus();
	await page.keyboard.press('Space');
	await expect.poll(async() => (await status(page)).phase).toBe('playing');
	await expect.poll(async() => (await status(page)).counts.flap).toBeGreaterThanOrEqual(1);
	expect(await canvas.evaluate(element => element.phpPhantDebug.music.volume)).toBe(.75);

	// A click on the canvas is a flap too.
	const before = (await status(page)).counts.flap;
	await canvas.click({position: {x: 100, y: 100}});
	await expect.poll(async() => (await status(page)).counts.flap).toBeGreaterThan(before);

	// Stop flapping: gravity ends the run and the result card opens with the best kept locally.
	await expect.poll(async() => (await status(page)).phase, {timeout: 15_000}).toBe('dead');
	await expect.poll(async() => (await status(page)).page, {timeout: 15_000}).toBe('gameover');
	await expect(page.locator('.phlappy-phant-targets').getByRole('button', {name: 'Play again'})).toBeAttached();
	const profile = await page.evaluate(() => JSON.parse(localStorage.getItem('phlappy-phant.profile.v1') ?? 'null'));
	expect(profile?.version).toBe(1);
	expect(profile.best).toBeGreaterThanOrEqual(0);

	// A portrait phone gets the same world, scaled to its height and clamped to the narrower width.
	await page.setViewportSize({width: 390, height: 844});
	await expect.poll(async() => (await status(page)).width, {timeout: 10_000}).toBeLessThan(wide.width);
	const tall = await status(page);
	const tallScale = Math.max(1, Math.floor(tall.height / 320));
	expect(tall.viewport[2]).toBeCloseTo(tallScale, 5);
	expect(tall.viewport[3]).toBeCloseTo(Math.max(60, Math.min(200, .28 * tall.width / tallScale)) * tallScale, 3);

	expect(errors).toEqual([]);
});

test('the Konami code with no points hands the run to a perfect player that never records a best', async({page}) => {
	const errors = [];
	page.on('pageerror', error => errors.push(error.message));
	await page.setViewportSize({width: 1280, height: 720});
	const canvas = await boot(page);
	await page.locator('.phlappy-phant-targets').getByRole('button', {name: 'Play', exact: true}).click();
	await expect.poll(async() => (await status(page)).phase).toBe('ready');
	await canvas.focus();

	// The Up presses are also flaps, but a code in progress holds the ready screen until the code resolves.
	for(const key of ['ArrowUp', 'ArrowUp', 'ArrowDown', 'ArrowDown', 'ArrowLeft', 'ArrowRight', 'ArrowLeft', 'ArrowRight', 'b', 'a'])
	{
		await page.keyboard.press(key);
		await page.waitForTimeout(150);
	}

	await expect.poll(async() => (await status(page)).autopilot).toBe(true);
	expect((await status(page)).counts.tas).toBe(1);
	await expect.poll(async() => (await status(page)).score, {timeout: 45_000}).toBeGreaterThanOrEqual(10);
	await page.waitForTimeout(500);
	const flying = await status(page);
	expect(flying.phase).toBe('playing');
	expect(flying.page).toBe('play');
	expect(flying.palette).toBe(1);
	expect(flying.best).toBe(0);

	// Pausing out of the run leaves nothing behind: the best is untouched and the next run is the player's again.
	await page.keyboard.press('Escape');
	await expect.poll(async() => (await status(page)).page).toBe('pause');
	await page.locator('.phlappy-phant-targets').getByRole('button', {name: 'Return to title'}).click();
	await expect.poll(async() => (await status(page)).page).toBe('title');
	await page.locator('.phlappy-phant-targets').getByRole('button', {name: 'Play', exact: true}).click();
	await expect.poll(async() => (await status(page)).phase).toBe('ready');
	expect((await status(page)).autopilot).toBe(false);
	const profile = await page.evaluate(() => JSON.parse(localStorage.getItem('phlappy-phant.profile.v1') ?? 'null'));
	expect(profile?.best ?? 0).toBe(0);
	expect(errors).toEqual([]);
});
