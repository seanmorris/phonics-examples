import {test, expect} from '@playwright/test';
import {PNG} from 'pngjs';

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

/**
 * Wait until the game has published this many more frames. The status is written once per frame, so counting the
 * writes follows the game's own pace on any machine, where a fixed sleep assumes one.
 */
const frames = (page, count) => gameCanvas(page).evaluate((canvas, wanted) => new Promise(resolve => {
	let seen = 0;
	const observer = new MutationObserver(records => {
		seen += records.length;

		if(seen < wanted) return;

		observer.disconnect();
		resolve();
	});
	observer.observe(canvas, {attributes: true, attributeFilter: ['data-php-phant']});
}), count);

/**
 * Press a key and give it frames of its own. Keys that arrive inside one slow frame are read together: two presses
 * of one key merge, and opposite directions cancel. Three frames cover the frame already under way, the frame that
 * reads the key, and one with nothing held before the next press.
 */
async function press(page, key)
{
	await page.keyboard.press(key);
	await frames(page, 3);
}

/**
 * Wait until a new run's ready screen takes keys, and return the status it first showed.
 *
 * The engine clears the keyboard when a menu closes, so a key pressed in the first frame of the ready screen is
 * dropped. The ground starts to scroll once the run is being stepped, and from then on every key counts. Clicks and
 * taps are counted in the browser and are never dropped.
 */
async function ready(page)
{
	await expect.poll(async() => {
		const now = await status(page);

		return `${now.page} ${now.phase}`;
	}).toBe('play ready');
	const shown = await status(page);
	await expect.poll(async() => (await status(page)).scroll).toBeGreaterThan(shown.scroll);

	return shown;
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

	// The main menu is large wherever it fits: its choices are double-height rows at 16:9, and a very wide frame
	// keeps them at that whole-number scale.
	expect(title.regions[0].rect[3]).toBe(36);
	await page.setViewportSize({width: 2560, height: 1222});
	await expect.poll(async() => (await status(page)).width).toBeGreaterThan(720);
	const largeTitle = await status(page);
	expect(largeTitle.regions[0].rect[3]).toBeGreaterThanOrEqual(title.regions[0].rect[3]);
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

	// A click on the canvas is a flap, and the first flap starts the run. Each input in this test is the first flap
	// of its own run: the ready screen waits for it however long the machine takes, while a second flap inside one
	// run has about a second before the phant lands, and a hosted runner spends that long on the click alone.
	await canvas.click({position: {x: 100, y: 100}});
	await expect.poll(async() => (await status(page)).counts.flap).toBe(1);
	expect(['playing', 'dead']).toContain((await status(page)).phase);
	expect(await canvas.evaluate(element => element.phpPhantDebug.music.volume)).toBe(.75);

	// Stop flapping: gravity ends the run and the result card opens with the best kept locally.
	await expect.poll(async() => (await status(page)).phase, {timeout: 15_000}).toBe('dead');
	await expect.poll(async() => (await status(page)).page, {timeout: 15_000}).toBe('gameover');
	await expect(hint).toBeVisible();
	const again = page.locator('.phlappy-phant-targets').getByRole('button', {name: 'Play again'});
	await expect(again).toBeAttached();
	const profile = await page.evaluate(() => JSON.parse(localStorage.getItem('phlappy-phant.profile.v1') ?? 'null'));
	expect(profile?.version).toBe(1);
	expect(profile.best).toBeGreaterThanOrEqual(0);

	// Play again starts a new run with its counters at zero, and Space is a flap as well.
	await again.click();
	expect((await ready(page)).counts.flap).toBe(0);
	await canvas.focus();
	await page.keyboard.press('Space');
	await expect.poll(async() => (await status(page)).counts.flap).toBe(1);
	expect(['playing', 'dead']).toContain((await status(page)).phase);

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
	await ready(page);
	await canvas.focus();

	// The Up presses are also flaps, but a code in progress holds the ready screen until the code resolves.
	for(const key of ['ArrowUp', 'ArrowUp', 'ArrowDown', 'ArrowDown', 'ArrowLeft', 'ArrowRight', 'ArrowLeft', 'ArrowRight', 'b', 'a'])
		await press(page, key);

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
	await frames(page, 2);
	await press(page, 'ArrowRight');
	await press(page, 'ArrowDown');
	await press(page, 'ArrowLeft');

	await expect.poll(() => canvas.evaluate(element => element.phpPhantDebug.mix)).toEqual({effects: .55, music: .7});
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
	await ready(page);
	await reloaded.focus();
	await page.keyboard.press('Escape');
	await expect.poll(async() => (await status(page)).page).toBe('pause');
	await expect(targets.getByRole('button', {name: 'Audio', exact: true})).toBeAttached();
	expect(errors).toEqual([]);
});

test('main, pause and result menus are large with centred rows; submenus share the Audio page\'s size', async({page}) => {
	const errors = [];
	page.on('pageerror', error => errors.push(error.message));
	page.on('console', message => { if(/PHP (Warning|Notice|Fatal)/.test(message.text())) errors.push(message.text()); });
	await page.setViewportSize({width: 1280, height: 720});
	const canvas = await boot(page);
	await canvas.focus();

	// A page turns a frame or more after its input. Wait for the published rows to change, then for that page to be
	// on screen, since the checks below read pixels.
	const labels = async() => JSON.stringify((await status(page)).regions.map(region => region.label));
	const turned = async act => {
		const before = await labels();

		await act();
		await expect.poll(labels).not.toBe(before);
		await frames(page, 3);

		return status(page);
	};
	const open = async label => {
		const state = await status(page);
		const region = state.regions.find(item => item.label === label);
		const box = await canvas.boundingBox();
		const [x, y, width, height] = region.rect;

		return turned(() => page.mouse.click(
			box.x + (x + width / 2) * box.width / state.width, box.y + (y + height / 2) * box.height / state.height
		));
	};
	const back = () => turned(() => page.keyboard.press('Escape'));
	// Where the cream lettering of one unselected row sits, in buffer pixels, read from what the page really drew.
	const lettering = async label => {
		const state = await status(page);
		const region = state.regions.find(item => item.label === label);
		const box = await canvas.boundingBox();
		const unit = box.width / state.width;
		const [x, y, width, height] = region.rect;
		const clip = {x: box.x + x * unit, y: box.y + y * unit, width: width * unit, height: height * unit};
		const image = PNG.sync.read(await page.screenshot({clip}));
		let left = Infinity, right = -Infinity;

		for(let row = 0; row < image.height; row++) for(let column = 0; column < image.width; column++)
		{
			const offset = 4 * (row * image.width + column);

			if(image.data[offset] > 235 && image.data[offset + 1] > 235 && image.data[offset + 2] > 225)
			{
				left = Math.min(left, column);
				right = Math.max(right, column);
			}
		}

		return {left: x + left / unit, right: x + (right + 1) / unit, band: [x, x + width]};
	};
	const centred = async(labels, name) => {
		const state = await status(page);
		const middle = (state.menu.left + state.menu.right) / 2;

		expect(state.menu.large, `${name} is a large menu`).toBe(true);
		expect(state.menu.scale, `${name} text scale`).toBe(2);

		for(const label of labels)
		{
			const text = await lettering(label);

			expect(Math.abs((text.left + text.right) / 2 - middle), `${name}: ${label} is centred on the plate`).toBeLessThanOrEqual(2);
			expect(text.left, `${name}: ${label} stays on its band`).toBeGreaterThanOrEqual(text.band[0]);
			expect(text.right, `${name}: ${label} stays on its band`).toBeLessThanOrEqual(text.band[1]);
		}
	};

	// The main menu keeps its large size at 16:9, where it used to fall back to small text.
	await centred(['Input settings', 'Audio', 'How to play', 'About'], 'main menu');

	// Every page under it is compact: the Audio page's whole-number scale and plaque width, with no stray warnings.
	const widths = new Set;

	for(const label of ['Input settings', 'How to play', 'About'])
	{
		const state = await open(label);

		expect(state.menu.large, `${label} is a compact page`).toBe(false);
		expect(state.menu.scale, `${label} text scale`).toBe(1);
		expect(state.menu.right - state.menu.left, `${label} is as wide as the Audio plaque inside its border`).toBe(344 - 16);
		expect(state.hint, `${label} shows the hint line`).toMatchObject({back: true});
		widths.add(state.menu.right - state.menu.left);
		await back();
	}

	expect(widths.size).toBe(1);
	const audio = await open('Audio');
	expect(audio.regions.map(region => region.label)).toEqual(['SFX level', 'BGM level', 'Back']);
	// The Audio plaque is 344 wide with ten of padding; its rows span the rest, centred in the frame like the others.
	const [rowLeft, , rowWidth] = audio.regions[0].rect;
	expect(rowWidth).toBe(344 - 20);
	expect(rowLeft + rowWidth / 2).toBeCloseTo(audio.width / 2, 0);
	expect(audio.hint, 'the Audio page shows the same hint line as the other pages').toMatchObject({back: true});
	await back();

	// Pause and the result card are large and centred too.
	await open('Play');
	await ready(page);
	expect((await back()).page).toBe('pause');
	await centred(['Restart', 'Audio', 'Return to title'], 'pause menu');
	expect((await back()).page).toBe('play');
	await ready(page);
	await page.keyboard.press('Space');
	await expect.poll(async() => (await status(page)).page, {timeout: 15_000}).toBe('gameover');
	await frames(page, 3);
	await centred(['Return to title'], 'result card');

	expect(errors).toEqual([]);
});

test('menu pages stand on their plate, skip text rows and stay inside a narrow phone frame', async({browser}) => {
	const context = await browser.newContext({viewport: {width: 390, height: 844}, deviceScaleFactor: 2});
	const page = await context.newPage();
	const errors = [];
	page.on('pageerror', error => errors.push(error.message));
	const canvas = await boot(page);
	await canvas.focus();

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
		for(let step = 0; step < steps; step++) await press(page, 'ArrowDown');

		await press(page, 'Enter');
		await expect.poll(async() => (await status(page)).regions.map(region => region.label)).toEqual(['Back']);

		// Information rows are text: they publish no button, and moving the selection never lands on one.
		await press(page, 'ArrowDown');
		await press(page, 'ArrowUp');
		await page.waitForTimeout(200);
		await fits(name);
		await press(page, 'Enter');
		await expect.poll(async() => (await status(page)).regions.length).toBe(5);

		for(let step = 0; step < steps; step++) await press(page, 'ArrowUp');
	}

	expect(errors).toEqual([]);
	await context.close();
});
