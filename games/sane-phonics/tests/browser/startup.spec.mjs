import {test, expect} from '@playwright/test';
import {waitForMenuFrames} from './menu-frames.mjs';
import {externalAssets} from './external-assets.mjs';

test.beforeEach(async ({page}) => externalAssets(page));

const data = (page, key = 'intro') => page.locator('canvas').evaluate(
	(canvas, key) => JSON.parse(canvas.dataset[key] || 'null')
	, key
);

const run = (page, source) => page.evaluate(source => enginePhp.run('<?php ' + source), source);

async function start(page)
{
	await page.goto('/sane-phonics/');
	await page.click('#start');
	await expect.poll(async () => (await data(page))?.card, {timeout: 60000}).toBe('engine');
}

async function mainMenu(page)
{
	await run(page, '$game->startup->showMenu();');
	await expect.poll(async () => (await data(page))?.phase).toBe('menu');
}

async function activate(page, id, pause = false)
{
	await waitForMenuFrames(page);
	await run(page, (pause ? '$pauseMenu->menu' : '$game->startup->menu') + '->select(' + JSON.stringify(id) + ');');

	await page.keyboard.press('Enter');
}

test.afterEach(async ({page}) => {
	await page.evaluate(() => window.stopEngine?.()).catch(() => {});
});

test(
	'Run shows real preload phases, guarded cards, a scrolling title, and the main menu'
	, async ({page}) => {
		const requests = [], errors = [];
		page.on('request', request => requests.push(request.url()));
		page.on('pageerror', error => errors.push(error.message));
		let application, title;
		await page.route('**/engine/packages/sane-phonics/dist/game.phar', route => { application = route; });

		await page.route('**/engine/packages/sane-phonics/examples/assets/title.tmj', route => { title = route; });

		await page.goto('/sane-phonics/');
		await page.click('#start');
		await expect(page.locator('#preloader')).toBeVisible();
		await expect(page.locator('#preload-progress')).not.toHaveAttribute('value');
		await expect.poll(() => Boolean(application), {timeout: 60000}).toBe(true);
		await application.fallback();
		await expect.poll(() => Boolean(title), {timeout: 60000}).toBe(true);
		await expect(page.locator('#preloader')).toBeHidden();
		await expect.poll(async () => (await data(page))?.card).toBe('loading');
		await page.keyboard.down('Enter');
		await expect.poll(async () => (await data(page))?.elapsed).toBeGreaterThan(.7);
		expect((await data(page)).card).toBe('loading');
		await title.continue();
		await page.keyboard.up('Enter');
		await expect.poll(async () => (await data(page))?.card, {timeout: 15000}).toBe('title');
		await page.keyboard.down('Enter');

		await expect.poll(async () => (await data(page))?.elapsed, {timeout: 10000})
			.toBeGreaterThan(2.2);

		expect((await data(page)).card).toBe('title');
		expect((await data(page)).gameTime).toBe(0);
		const pan = (await data(page)).pan;
		await expect.poll(async () => (await data(page)).pan).toBeGreaterThan(pan + 10);
		expect(requests.filter(url => /\/(platformer|tiles)\.(tmj|tsj|png)$/.test(url))).toEqual([]);
		await page.keyboard.up('Enter');
		await page.waitForTimeout(80);
		await page.keyboard.press('Enter');
		await expect.poll(async () => (await data(page)).phase).toBe('menu');
		expect((await data(page)).pan).toBeGreaterThan(pan);
		expect((await data(page, 'menu')).title).toBe('Main menu');
		await run(page, '$startupIdentity = [$engine, $engine->clock, $game, $pauseMenu];');
		const region = (await data(page, 'menu')).regions.find(region => region.id === 'play');
		const [x, y, w, h] = region.rect;
		const bounds = await page.locator('canvas').boundingBox();

		await page.mouse.click(
			bounds.x + (x + w / 2) * bounds.width / 960
			, bounds.y + (y + h / 2) * bounds.height / 540
		);

		await expect(page.locator('canvas')).toHaveAttribute('data-scene', 'platformer');
		await expect.poll(async () => (await data(page)).phase).toBe('playing');

		await run(page, `vrzno_env('canvas')->dataset->identity = json_encode(
		$startupIdentity === [$engine, $engine->clock, $game, $pauseMenu]);`
		);

		await expect(page.locator('canvas')).toHaveAttribute('data-identity', 'true');
		expect(requests.filter(url => url.endsWith('.wasm'))).toHaveLength(1);
		expect(errors).toEqual([]);
	}
);

test(
	'main-menu settings persist in gameplay and returning home retains the level'
	, async ({page}) => {
		await start(page);
		await mainMenu(page);
		await activate(page, 'settings');
		await activate(page, 'name');
		await expect.poll(async () => (await data(page, 'menu')).editing).toBe(true);
		await page.keyboard.press('Control+a');
		await page.keyboard.type('Intro player');
		await page.keyboard.press('Enter');
		await expect.poll(async () => (await data(page, 'menu')).values.name).toBe('Intro player');
		await activate(page, 'hints');
		await expect.poll(async () => (await data(page, 'menu')).values.hints).toBe(false);
		await page.keyboard.press('Escape');
		await activate(page, 'level');
		await activate(page, 'water');
		await activate(page, 'movement');
		await expect.poll(async () => (await data(page, 'menu')).values.movement).toBe('glider');
		await activate(page, 'play');
		await expect.poll(async () => (await data(page)).phase).toBe('playing');
		await expect(page.locator('canvas')).toHaveAttribute('data-scene', 'water');
		await page.keyboard.press('p');
		await expect.poll(async () => (await data(page, 'menu')).open).toBe(true);
		expect((await data(page, 'menu')).values.name).toBe('Intro player');
		await run(page, '$retained = [$game->level, $engine->time, $body->x, $body->y];');
		await activate(page, 'main-menu', true);
		await expect.poll(async () => (await data(page)).phase).toBe('menu');
		await activate(page, 'credits');
		await expect.poll(async () => (await data(page)).elapsed).toBeGreaterThan(.6);
		await page.keyboard.press('Enter');
		await expect.poll(async () => (await data(page)).phase).toBe('menu');

		await run(page, `vrzno_env('canvas')->dataset->retained = json_encode(
		$retained === [$game->level, $engine->time, $body->x, $body->y]);`
		);

		await expect(page.locator('canvas')).toHaveAttribute('data-retained', 'true');
		await activate(page, 'resume');
		await expect.poll(async () => (await data(page)).phase).toBe('playing');
	}
);

test(
	'title and level download errors can be retried in the same PHP session'
	, async ({page}) => {
		const errors = [];
		page.on('pageerror', error => errors.push(error.message));
		await page.route('**/title.tmj', route => route.fulfill({status: 503, body: 'Offline'}));
		await page.goto('/sane-phonics/');
		await page.click('#start');
		await expect.poll(async () => (await data(page))?.error, {timeout: 60000}).toContain('503');
		await run(page, '$initialEngine = $engine;');
		await page.unroute('**/title.tmj');
		await page.keyboard.press('Enter');
		await expect.poll(async () => (await data(page)).card).toBe('engine');
		await mainMenu(page);
		await page.route('**/loop.tmj', route => route.fulfill({status: 503, body: 'Offline'}));
		await run(page, '$game->startup->scene = "loop"; $game->startup->play();');
		await expect.poll(async () => (await data(page)).error).toContain('503');
		await page.unroute('**/loop.tmj');
		await page.keyboard.press('Enter');
		await expect.poll(async () => (await data(page)).phase).toBe('playing');
		await expect(page.locator('canvas')).toHaveAttribute('data-scene', 'loop');
		await run(page, 'vrzno_env("canvas")->dataset->identity = json_encode($engine === $initialEngine);');
		await expect(page.locator('canvas')).toHaveAttribute('data-identity', 'true');
		expect(errors).toEqual([]);
	}
);

test('scripted stop cancels the title download and ignores its late response', async ({page}) => {
	let title;
	await page.route('**/title.tmj', route => { title = route; });

	await page.goto('/sane-phonics/');
	await page.click('#start');
	await expect.poll(() => Boolean(title), {timeout: 60000}).toBe(true);
	await page.evaluate(() => window.stopEngine());
	await title.fulfill({json: {layers: []}}).catch(() => {});

	await page.unroute('**/title.tmj');
	await expect(page.locator('#start')).toBeEnabled();

	expect(
		await page.evaluate(
			async () => (await enginePhp.binary).FS.analyzePath('/phonics/assets/1/examples/assets/title.tmj').exists
		)
	).toBe(false);

	await page.click('#start');
	await expect.poll(async () => (await data(page))?.card, {timeout: 60000}).toBe('engine');
});

test('a held gamepad Start cannot skip the title or activate the menu after it', async ({page}) => {
	await page.addInitScript(() => {
		window.pad = {
			id: 'Standard Gamepad'
			, index: 0
			, connected: true
			, mapping: 'standard'
			, axes: [0, 0, 0, 0]
			, buttons: Array.from({length: 17}, () => ({pressed: false, touched: false, value: 0}))
			, timestamp: 1
		};

		Object.defineProperty(navigator, 'getGamepads', {value: () => [pad]});

		window.pressStart = pressed => {
			pad.buttons[9] = {pressed, touched: pressed, value: Number(pressed)};
			pad.timestamp++;
		};
	});

	await start(page);
	await run(page, '$game->startup->title();');
	await expect.poll(async () => (await data(page)).card).toBe('title');
	await page.evaluate(() => pressStart(true));
	await expect.poll(async () => (await data(page)).elapsed).toBeGreaterThan(2.2);
	expect((await data(page)).phase).toBe('intro');
	await page.evaluate(() => pressStart(false));
	await page.waitForTimeout(100);
	await page.evaluate(() => pressStart(true));
	await expect.poll(async () => (await data(page)).phase).toBe('menu');
	await page.waitForTimeout(500);
	expect((await data(page)).phase).toBe('menu');
	await page.evaluate(() => pressStart(false));
	await page.waitForTimeout(100);
	await page.evaluate(() => pressStart(true));
	await expect.poll(async () => (await data(page)).phase).toBe('playing');
});

test(
	'main menu survives context loss and hidden-tab resume without advancing gameplay'
	, async ({page}) => {
		const errors = [];
		page.on('pageerror', error => errors.push(error.message));
		await start(page);
		await mainMenu(page);
		await activate(page, 'settings');
		await expect.poll(async () => (await data(page, 'menu')).title).toBe('Settings');
		await run(page, '$game->startup->menu->select("theme");');
		await expect.poll(async () => (await data(page, 'menu')).selected).toBe('theme');

		await page.evaluate(() => {
			window.lost = document.querySelector('canvas').getContext('webgl2')
				.getExtension('WEBGL_lose_context');

			lost.loseContext();
		});

		await page.waitForTimeout(200);
		const before = await data(page);
		await page.waitForTimeout(300);
		expect((await data(page)).time).toBe(before.time);
		await page.evaluate(() => lost.restoreContext());
		await expect.poll(async () => (await data(page)).time).toBeGreaterThan(before.time);
		expect((await data(page, 'menu')).selected).toBe('theme');

		await page.evaluate(() => {
			Object.defineProperty(document, 'hidden', {configurable: true, value: true});
			document.dispatchEvent(new Event('visibilitychange'));
		});

		await page.waitForTimeout(100);
		const hidden = await data(page);
		await page.waitForTimeout(300);
		expect((await data(page)).time).toBe(hidden.time);

		await page.evaluate(() => {
			Object.defineProperty(document, 'hidden', {configurable: true, value: false});
			document.dispatchEvent(new Event('visibilitychange'));
		});

		await expect.poll(async () => (await data(page)).time).toBeGreaterThan(hidden.time);
		expect((await data(page)).gameTime).toBe(0);
		await page.keyboard.press('Escape');
		await expect.poll(async () => (await data(page, 'menu')).selected).toBe('settings');
		await waitForMenuFrames(page);
		await page.keyboard.press('Escape');
		await expect.poll(async () => (await data(page)).card).toBe('title');
		await expect.poll(async () => (await data(page)).elapsed).toBeGreaterThan(2);
		await page.locator('canvas').click({position: {x: 200, y: 150}});
		await expect.poll(async () => (await data(page)).phase).toBe('menu');
		await activate(page, 'replay');
		await expect.poll(async () => (await data(page)).card).toBe('engine');
		expect(errors).toEqual([]);
	}
);
