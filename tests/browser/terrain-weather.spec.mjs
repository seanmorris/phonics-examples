import {test, expect} from '@playwright/test';
import {PNG} from 'pngjs';
import {slow} from './slow.mjs';

const canvas = page => page.locator('canvas[data-terrain-sandbox]');

const status = page => canvas(page).evaluate(element => JSON.parse(element.dataset.terrainSandbox));

/** Wait until generation has finished and the game has stopped drawing new frames. */
async function settled(page)
{
	let previous = -1;

	await expect.poll(async() => {
		const now = await status(page);
		const unchanged = previous === now.renderer.frames;
		previous = now.renderer.frames;

		return !now.preparing && !now.renderer.pending && !now.renderer.transitions && unchanged;
	}, {timeout: slow(90_000), intervals: [400,800,1200]}).toBe(true);
}

const light = (image, at) => .2126*image.data[at] + .7152*image.data[at+1] + .0722*image.data[at+2];

/**
 * Open the sandbox on its first landscape with still cloud, hide everything but the canvas, stand 150 meters above
 * land looking east-northeast under the cloud, and freeze the world. A strike called from here lands about 1,400
 * meters away, 24 degrees right of center. The horizon lies two fifths of the way down the view.
 */
async function stage(page, controls = {})
{
	await page.setViewportSize({width: 640, height: 400});

	await page.addInitScript(() => {
		if(localStorage.getItem('terrain-sandbox.grass') === null) localStorage.setItem('terrain-sandbox.grass', '0');
	});

	await page.goto('/games/terrain-sandbox/');
	const view = canvas(page);
	await expect(view).toHaveAttribute('data-terrain-sandbox', /"recipe":/, {timeout: slow(60_000)});

	await page.addStyleTag({content: 'body *{visibility:hidden!important}'
		+ 'canvas[data-terrain-sandbox]{visibility:visible!important}'});

	const act = action => view.evaluate((element, value) => element.terrainSandboxDebug.action(value), action);
	await act({type: 'weather', controls: {coverage: 96, wind: 0, ...controls}});
	await expect.poll(async() => (await status(page)).weather.controls.coverage).toBe(96);
	await act({type: 'survey-view', camera: [5000, 0, 150, 60, -6]});
	await expect.poll(async() => (await status(page)).camera.position[0]).toBe(5000);
	await act('pause');
	await expect.poll(async() => (await status(page)).paused).toBe(true);
	await settled(page);

	return act;
}

/** Take the canvas as pixels once the game has stopped drawing. */
async function frame(page)
{
	await settled(page);

	return PNG.sync.read(await canvas(page).screenshot());
}

/** Call a strike and wait for the frame that shows it. Frozen, it stays at the moment it begins. */
async function strike(page, act)
{
	const frames = (await status(page)).renderer.frames;
	await act({type: 'weather', strike: true});
	await expect.poll(async() => (await status(page)).renderer.frames, {timeout: slow(30_000)}).toBeGreaterThan(frames);
}

/** Mean brightness, from 0 to 255, of the rows between two shares of the view's height. */
function band(image, from, to)
{
	let sum = 0, count = 0;

	for(let y = Math.round(image.height*from); y < Math.round(image.height*to); y++)
	for(let x = 0; x < image.width; x++, count++) sum += light(image, 4*(image.width*y+x));

	return sum/count;
}

test('a called lightning strike darkens the land and the cloud away from it', async({page}) => {
	test.setTimeout(slow(150_000));
	const errors = [];
	page.on('pageerror', error => errors.push(error.message));
	const act = await stage(page);
	const before = await frame(page);

	// The same place seen looking the other way, for a strike that will be behind the camera.
	await act({type: 'survey-view', camera: [5000, 0, 150, 240, -6]});
	const away = await frame(page);
	await act({type: 'survey-view', camera: [5000, 0, 150, 60, -6]});
	await settled(page);

	// The bolt has not come down yet and shines at half its full strength. The darkening follows the square of
	// that, a quarter.
	await strike(page, act);
	const lit = await frame(page);

	// Land fills the view below the horizon. A flash this strong, 1,400 meters off, takes a sixth of its brightness.
	const land = band(lit, .45, 1)/band(before, .45, 1);
	expect(band(before, .45, 1)).toBeGreaterThan(20);
	expect(land).toBeLessThan(.93);
	expect(land).toBeGreaterThan(.7);

	// The cloud just above the horizon is kilometers from the strike, so it darkens too.
	const cloud = band(lit, .27, .38)/band(before, .27, .38);
	expect(cloud).toBeLessThan(.93);
	expect(cloud).toBeGreaterThan(.7);

	// A strike whose bolt is behind the camera leaves the view as it was.
	await act({type: 'survey-view', camera: [5000, 0, 150, 240, -6]});
	const behind = await frame(page);
	expect(Math.abs(band(behind, .45, 1)/band(away, .45, 1) - 1)).toBeLessThan(.02);
	expect(Math.abs(band(behind, .27, .38)/band(away, .27, .38) - 1)).toBeLessThan(.02);
	await act({type: 'survey-view', camera: [5000, 0, 150, 60, -6]});

	// With the darkening turned down to a hundredth, a lit strike leaves the land as it was: nothing else dims or
	// tints it while a strike is on.
	await act({type: 'weather', controls: {flashDark: 1}});
	await expect.poll(async() => (await status(page)).weather.controls.flashDark).toBe(1);
	const faint = await frame(page);
	expect(Math.abs(band(faint, .45, 1)/band(before, .45, 1) - 1)).toBeLessThan(.015);
	await act({type: 'weather', controls: {flashDark: 70}});

	// Once the world moves on, the strike ends within a second and the view is as it was.
	const struck = (await status(page)).time;
	await act('pause');
	await expect.poll(async() => (await status(page)).time, {timeout: slow(30_000)}).toBeGreaterThan(struck + 1);
	await act('pause');
	await expect.poll(async() => (await status(page)).paused).toBe(true);
	const after = await frame(page);

	expect(Math.abs(band(after, .45, 1)/band(before, .45, 1) - 1)).toBeLessThan(.03);
	expect(Math.abs(band(after, .27, .38)/band(before, .27, .38) - 1)).toBeLessThan(.03);
	expect(errors).toEqual([]);
});

test('a flash that lights the land brightens ground near the strike and dims the rest', async({page}) => {
	test.setTimeout(slow(150_000));
	const errors = [];
	page.on('pageerror', error => errors.push(error.message));
	const act = await stage(page, {flashLight: 100});
	const before = await frame(page);

	await strike(page, act);
	const lit = await frame(page);

	// The same frozen strike with the slider at nothing: the land is darkened evenly and nothing is lit.
	await act({type: 'weather', controls: {flashLight: 0}});
	await expect.poll(async() => (await status(page)).weather.controls.flashLight).toBe(0);
	const flat = await frame(page);
	let darker = 0, shone = 0, land = 0;

	for(let y = Math.round(before.height*.42); y < before.height; y++)
	for(let x = 0; x < before.width; x++, land++)
	{
		const at = 4*(before.width*y+x);

		if(light(lit, at) < light(before, at)*.95) darker++;

		if(light(lit, at) > light(flat, at)*1.15 + 4) shone++;
	}

	// At half strength the flash lifts the ground nearest the strike, about a tenth of the land in view, well
	// above what the even darkening would leave. Most of the land, farther off, ends darker than it began.
	console.log('Flash on the land', JSON.stringify({darker: darker/land, shone: shone/land}));
	expect(shone/land).toBeGreaterThan(.04);
	expect(darker/land).toBeGreaterThan(.3);
	expect(errors).toEqual([]);
});

test('the Weather panel sets wetness and lightning and refuses a strike from a clear sky', async({page}) => {
	test.setTimeout(slow(120_000));
	await page.goto('/games/terrain-sandbox/');
	const view = canvas(page);
	await expect(view).toHaveAttribute('data-terrain-sandbox', /"recipe":/, {timeout: slow(60_000)});
	const collapsed = page.locator('.terrain-panel:not([open]) > summary');

	if(await collapsed.count()) await collapsed.click();

	await page.locator('#terrain-tab-weather').click();
	const panel = page.locator('#terrain-section-weather');

	await panel.locator('[data-weather-strike]').click();
	await expect.poll(async() => (await status(page)).message).toBe('Lightning needs cloud to strike from.');

	await panel.locator('[data-weather-hold]').check();
	await panel.locator('[data-weather-wet]').fill('65');
	await expect.poll(async() => (await status(page)).weather.ground[0]).toBeCloseTo(.65, 5);
	await expect(panel.locator('[data-weather-ground]')).toHaveText(/Wetness 65%/);

	// Half the sunlight a deck stops comes back as even light unless the slider says otherwise.
	await expect(panel.locator('[data-weather="cloudLight"]')).toHaveValue('50');
	await panel.locator('[data-weather="cloudLight"]').fill('30');
	await expect.poll(async() => (await status(page)).weather.controls.cloudLight).toBe(30);

	await panel.locator('[data-weather-preset="storm"]').click();
	await expect.poll(async() => (await status(page)).weather.controls.lightning).toBe(60);
	await expect(panel.locator('[data-weather="lightning"]')).toHaveValue('60');
	await panel.locator('[data-weather="lightning"]').fill('25');
	await expect.poll(async() => (await status(page)).weather.controls.lightning).toBe(25);

	// The options that shape a strike are weather controls, and choosing another preset keeps them.
	await panel.locator('[data-weather="boltColor"]').fill('#ff8800');
	await panel.locator('[data-weather="boltForks"]').fill('40');
	await panel.locator('[data-weather="boltSeconds"]').fill('1.5');

	await expect.poll(async() => {
		const {boltColor, boltForks, boltSeconds} = (await status(page)).weather.controls;

		return [boltColor, boltForks, boltSeconds];
	}).toEqual(['#ff8800', 40, 1.5]);

	await panel.locator('[data-weather-preset="rain"]').click();
	await expect.poll(async() => (await status(page)).weather.controls.lightning).toBe(0);
	expect((await status(page)).weather.controls.boltColor).toBe('#ff8800');
	await expect.poll(async() => (await status(page)).weather.ground[0]).toBeCloseTo(.65, 5);
});
