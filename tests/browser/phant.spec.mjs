import {test, expect} from '@playwright/test';

const gameCanvas = page => page.locator('.phlappy-phant-screen > canvas:not(.phlappy-phant-hints)');
const status = page => gameCanvas(page).evaluate(canvas => JSON.parse(canvas.dataset.phpPhant ?? 'null'));

async function boot(page)
{
	await page.goto('/games/phlappy-phant/');
	const canvas = gameCanvas(page);
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
	const hint = page.locator('.phlappy-phant-hints');
	await expect(hint).toBeVisible();
	expect(title.hint).toMatchObject({back: false, scale: 1});
	expect(title.inputTheme).toBe('keyboard');
	const hintDensity = await page.evaluate(() => {
		const world = document.querySelector('.phlappy-phant-screen > canvas:not(.phlappy-phant-hints)');
		const hints = document.querySelector('.phlappy-phant-hints');
		const pixels = hints.getContext('2d').getImageData(0, 0, hints.width, hints.height).data;
		const colours = new Set;

		for(let offset = 0; offset < pixels.length; offset += 4)
		if(pixels[offset + 3] === 255) colours.add(`${pixels[offset]},${pixels[offset + 1]},${pixels[offset + 2]}`);

		return {
			world: world.width / world.getBoundingClientRect().width
			, hints: hints.width / hints.getBoundingClientRect().width
			, face: colours.has('242,224,191')
			, inset: colours.has('69,64,83')
		};
	});
	expect(hintDensity.hints).toBeGreaterThan(hintDensity.world);
	expect(hintDensity).toMatchObject({face: true, inset: true});

	// The title is a live waiting preview: the phant bobs while the terrain scrolls behind the open menu.
	await expect.poll(async() => (await status(page)).scroll).toBeGreaterThan(title.scroll + 10);
	await expect.poll(async() => (await status(page)).y).not.toBe(title.y);

	// A very wide title uses the spare field below the wordmark to enlarge its choices at an integer scale.
	await page.setViewportSize({width: 2560, height: 1222});
	await expect.poll(async() => (await status(page)).width).toBeGreaterThan(720);
	const largeTitle = await status(page);
	expect(largeTitle.regions[0].rect[3]).toBeGreaterThan(title.regions[0].rect[3]);
	await page.setViewportSize({width: 1280, height: 720});
	await expect.poll(async() => (await status(page)).width).toBeLessThan(720);

	await page.locator('.phlappy-phant-targets').getByRole('button', {name: 'Play', exact: true}).click();
	await expect.poll(async() => (await status(page)).page).toBe('play');
	await expect.poll(async() => (await status(page)).phase).toBe('ready');
	await expect(hint).toBeHidden();
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

	// Space starts the run with a flap, and a click on the canvas is a flap too. The click follows at once: without
	// another flap the run ends about a second after the first one, and a busy machine can spend longer than that on
	// the checks this test used to make between the two inputs. The counter keeps its value after the run ends.
	await canvas.focus();
	await page.keyboard.press('Space');
	await page.waitForTimeout(150);
	await canvas.click({position: {x: 100, y: 100}});
	await expect.poll(async() => (await status(page)).counts.flap).toBeGreaterThanOrEqual(2);
	expect(['playing', 'dead']).toContain((await status(page)).phase);
	expect(await canvas.evaluate(element => element.phpPhantDebug.music.volume)).toBe(.75);

	// Stop flapping: gravity ends the run and the result card opens with the best kept locally.
	await expect.poll(async() => (await status(page)).phase, {timeout: 15_000}).toBe('dead');
	await expect.poll(async() => (await status(page)).page, {timeout: 15_000}).toBe('gameover');
	await expect(hint).toBeVisible();
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

test('audio levels adjust live from title and pause, then survive a reload', async({page}) => {
	const errors = [];
	page.on('pageerror', error => errors.push(error.message));
	const canvas = await boot(page);
	const targets = page.locator('.phlappy-phant-targets');

	await targets.getByRole('button', {name: 'Audio', exact: true}).click();
	await expect.poll(async() => (await status(page)).regions.map(region => region.label)).toEqual([
		'SFX level', 'BGM level', 'Back'
	]);
	await canvas.focus();
	await page.keyboard.press('ArrowRight');
	await page.waitForTimeout(160);
	await page.keyboard.press('ArrowDown');
	await page.waitForTimeout(160);
	await page.keyboard.press('ArrowLeft');
	await page.waitForTimeout(160);

	expect(await canvas.evaluate(element => element.phpPhantDebug.mix)).toEqual({effects: .55, music: .7});
	expect(await canvas.evaluate(element => element.phpPhantDebug.music)).toMatchObject({
		volume: .7, baseVolume: .7
	});
	expect(await page.evaluate(() => JSON.parse(localStorage.getItem('phlappy-phant.profile.v1'))?.audio)).toEqual({
		effects: .55, music: .7
	});

	await page.reload();
	const reloaded = await boot(page);
	expect(await reloaded.evaluate(element => element.phpPhantDebug.mix)).toEqual({effects: .55, music: .7});
	expect(await reloaded.evaluate(element => element.phpPhantDebug.music)).toMatchObject({
		volume: .7, baseVolume: .7
	});

	await targets.getByRole('button', {name: 'Play', exact: true}).click();
	await expect.poll(async() => (await status(page)).page).toBe('play');
	await reloaded.focus();
	await page.keyboard.press('Escape');
	await expect.poll(async() => (await status(page)).page).toBe('pause');
	await expect(targets.getByRole('button', {name: 'Audio', exact: true})).toBeAttached();
	expect(errors).toEqual([]);
});

test('menu pages stand on their plate, skip text rows and stay inside a narrow phone frame', async({browser}) => {
	const context = await browser.newContext({viewport: {width: 390, height: 844}, deviceScaleFactor: 2});
	const page = await context.newPage();
	const errors = [];
	page.on('pageerror', error => errors.push(error.message));
	const canvas = await boot(page);
	await canvas.focus();

	// Presses of one key inside a single slow frame merge, so each one is given time to land.
	const press = async key => {
		await page.keyboard.press(key);
		await page.waitForTimeout(160);
	};

	const fits = async name => {
		const state = await status(page);
		const menu = state.menu;
		expect(menu.left, `${name} left`).toBeGreaterThanOrEqual(0);
		expect(menu.top, `${name} top`).toBeGreaterThanOrEqual(0);
		expect(menu.right, `${name} right`).toBeLessThanOrEqual(menu.width);
		expect(menu.bottom, `${name} clears the hint line`).toBeLessThanOrEqual(state.hint.y);

		// The hint line itself is drawn by the browser and must not be cut off at the frame's edges.
		const overhang = await page.evaluate(() => {
			const hint = document.querySelector('.phlappy-phant-hints').getBoundingClientRect();
			const frame = document.querySelector('.phlappy-phant-screen').getBoundingClientRect();

			return Math.max(frame.left - hint.left, hint.right - frame.right);
		});
		expect(overhang, `${name} hint`).toBeLessThanOrEqual(0);

		return state;
	};

	await fits('title');

	for(const [steps, name] of [[3, 'How to play'], [4, 'About']])
	{
		for(let step = 0; step < steps; step++) await press('ArrowDown');

		await press('Enter');
		await expect.poll(async() => (await status(page)).regions.map(region => region.label)).toEqual(['Back']);

		// Information rows are text: they publish no button, and moving the selection never lands on one.
		await press('ArrowDown');
		await press('ArrowUp');
		await page.waitForTimeout(200);
		await fits(name);
		await press('Enter');
		await expect.poll(async() => (await status(page)).regions.length).toBe(5);

		for(let step = 0; step < steps; step++) await press('ArrowUp');
	}

	expect(errors).toEqual([]);
	await context.close();
});
