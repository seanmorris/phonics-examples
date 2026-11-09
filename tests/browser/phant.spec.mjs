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

	await page.locator('.phlappy-phant-targets').getByRole('button', {name: 'Play', exact: true}).click();
	await expect.poll(async() => (await status(page)).page).toBe('play');
	await expect.poll(async() => (await status(page)).phase).toBe('ready');

	// Wide frame: the phant sits near 28 % of the visible width, scaled from the 480-unit world.
	const wide = await status(page);
	expect(wide.height).toBeGreaterThan(0);
	const scale = wide.height / 480;
	expect(wide.viewport[2]).toBeCloseTo(scale, 5);
	expect(wide.viewport[3]).toBeCloseTo(Math.max(60, Math.min(200, .28 * wide.width / scale)) * scale, 3);

	await canvas.focus();
	await page.keyboard.press('Space');
	await expect.poll(async() => (await status(page)).phase).toBe('playing');
	expect((await status(page)).vy).toBeLessThan(0);
	expect((await status(page)).counts.flap).toBeGreaterThanOrEqual(1);

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
	const tallScale = tall.height / 480;
	expect(tall.viewport[2]).toBeCloseTo(tallScale, 5);
	expect(tall.viewport[3]).toBeCloseTo(Math.max(60, Math.min(200, .28 * tall.width / tallScale)) * tallScale, 3);

	expect(errors).toEqual([]);
});
