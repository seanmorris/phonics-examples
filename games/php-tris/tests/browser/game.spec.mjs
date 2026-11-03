import {test,expect} from '@playwright/test';

const state = page => page.locator('canvas').evaluate(c => JSON.parse(c.dataset.phpTris));

const run = (page, code) => page.evaluate(
	async code => {if(await previewSession.php.run('<?php ' + code)) throw Error('PHPTris probe failed');}
	, code
);

async function load(page, query = '')
{
	await page.goto('/php-tris/' + query);
	await expect(page.locator('#status')).toHaveText('Ready', {timeout: 60000});
	await expect(page.locator('[data-action="start"]')).toBeVisible();
}

async function start(page)
{
	await page.locator('[data-action="start"]').click();
	await expect.poll(async() => (await state(page)).phase, {timeout: 15000}).toBe('playing');
}

test('PHPTris title, input, pause, PHP Info and cleanup', async({page}) => {
	const errors = [],requests = [];
	page.on('pageerror', e => errors.push(e.message));
	page.on('request', r => requests.push(r.url()));
	await load(page);
	await page.screenshot({path: '/tmp/phptris-title.png'});
	await page.locator('[data-action="info"]').click();
	await expect.poll(async() => (await state(page)).info.ready).toBe(true);
	expect((await state(page)).page).toBe('title');
	const back = page.locator('[data-action="close-info"]');
	expect((await back.boundingBox()).height).toBeGreaterThanOrEqual(44);
	await back.click();
	await start(page);
	await page.screenshot({path: '/tmp/phptris-play.png'});
	const x = (await state(page)).active.x;
	await page.keyboard.press('ArrowLeft');
	await expect.poll(async() => (await state(page)).active.x).toBe(x-1);
	await page.keyboard.press('KeyC');
	await expect.poll(async() => (await state(page)).held).not.toBeNull();
	await page.keyboard.press('Space');
	await expect.poll(async() => (await state(page)).placed).toBe(1);
	await page.keyboard.press('Escape');
	await expect.poll(async() => (await state(page)).page).toBe('pause');
	const elapsed = (await state(page)).elapsed;
	await page.waitForTimeout(150);
	expect((await state(page)).elapsed).toBe(elapsed);
	await page.locator('[data-action="resume"]').click();
	await expect.poll(async() => (await state(page)).page).toBe('play');
	await page.click('#fullscreen');
	await expect.poll(() => page.evaluate(() => document.fullscreenElement?.className)).toBe('php-tris-ui');
	await page.evaluate(() => document.exitFullscreen());
	await page.click('#stop');
	await expect(page.locator('.php-tris-ui,.quick-controls')).toHaveCount(0);
	expect(await page.locator('canvas').evaluate(c => c.parentNode.id)).toBe('game-frame');
	expect(requests.filter(url => /\/packages\/(sane-phonics|php-noid)\//.test(url))).toEqual([]);
	expect(errors).toEqual([]);
});

test('all PHPTris modes enter and every challenge rank progresses', async({page}) => {
	await load(page);

	for(const mode of ['endless','race','time','squares','cascade','targets','bonds','reactor'])
	{
		await page.locator('[data-action="modes"]').click();

		await page.locator(`[data-action="${mode}"]`).click();

		await start(page);

		expect((await state(page)).mode).toBe(mode);

		if(!['endless','race','time'].includes(mode))
		{
			await run(
				page
				, `for($rank=1;$rank<=15;$rank++) { $match->run->level=$rank; $match->run->goal=$match->run->target(); $match->run->phase='settled'; $phonics->systems->update(1/60); if($match->run->phase!==($rank===15?'complete':'countdown')) throw new RuntimeException('rank failed'); }`
			);

			await expect.poll(async() => (await state(page)).page).toBe('results');

			await page.locator('[data-action="back"]').click();
		}
		else
		{
			await page.keyboard.press('Escape');
			await page.locator('[data-action="back"]').click();
		}
	}
});

for(const deviceScaleFactor of [1,1.25,2])
test(`portrait touch and integer glyphs at density ${deviceScaleFactor}`, async({browser,baseURL}) => {
	const context = await browser.newContext({
		baseURL
		, viewport: {width: 390,height: 844}
		, hasTouch: true
		, isMobile: true
		, deviceScaleFactor
	});

	const page = await context.newPage();

	try
	{
		await load(page);
		await start(page);
		const s = await state(page);
		expect(s.layout.portrait).toBe(true);
		expect(Number.isInteger(s.layout.scale)).toBe(true);
		const button = page.locator('[data-action="drop"]');
		const box = await button.boundingBox();
		expect(box.height).toBeGreaterThanOrEqual(44);
		await button.tap();
		await expect.poll(async() => (await state(page)).placed).toBe(1);
		await page.screenshot({path: `/tmp/phptris-portrait-${deviceScaleFactor}.png`});
	}
	finally
	{
		await context.close();
	}
});

test('PHPTris controller rotation, hold, drop and pause use the shared SDL mapping', async({page}) => {
	await page.setViewportSize({width: 854, height: 600});

	await page.addInitScript(() => {
		const pad = {
			id: 'Standard Gamepad'
			, index: 0
			, connected: true
			, mapping: 'standard'
			, timestamp: 0
			, axes: [0,0,0,0]
			, buttons: Array.from({length: 17}, () => ({pressed: false, value: 0}))
		};

		Object.defineProperty(navigator, 'getGamepads', {value: () => [pad]});

		window.trisPad = buttons => {
			pad.buttons = pad.buttons.map((_, i) => ({
				pressed: buttons.includes(i)
				, touched: buttons.includes(i)
				, value: buttons.includes(i) ? 1 : 0
			}));

			pad.timestamp = performance.now();
		};
	});

	await load(page);
	await start(page);
	await page.evaluate(() => trisPad([0]));
	await expect.poll(async() => (await state(page)).active.rotation).not.toBe(0);
	await page.evaluate(() => trisPad([]));
	await page.waitForTimeout(60);
	await page.evaluate(() => trisPad([3]));
	await expect.poll(async() => (await state(page)).held).not.toBeNull();
	await page.evaluate(() => trisPad([]));
	await page.waitForTimeout(60);
	await page.evaluate(() => trisPad([2]));
	await expect.poll(async() => (await state(page)).placed).toBe(1);
	await page.evaluate(() => trisPad([]));
	await page.waitForTimeout(60);
	await page.evaluate(() => trisPad([9]));
	await expect.poll(async() => (await state(page)).page).toBe('pause');
	await page.evaluate(() => trisPad([]));
	await page.locator('#stop').click();
});

test('PHPTris starts at the mode and rank selected by the world', async({page}) => {
	await page.route('**/php-tris/worlds/arcade.world?reactor', async route => {
		const response = await route.fetch();
		const world = await response.json();
		world.properties.find(property => property.name === 'phonics.start').value = 'reactor-02.tmj';
		await route.fulfill({response, json: world});
	});

	await load(page, '?world=' + encodeURIComponent('/engine/packages/php-tris/worlds/arcade.world?reactor'));
	await start(page);
	expect(await state(page)).toMatchObject({mode: 'reactor', level: 2, seed: 1});
	await page.locator('#stop').click();
});
