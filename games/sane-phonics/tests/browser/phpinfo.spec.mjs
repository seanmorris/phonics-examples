import {test,expect} from '@playwright/test';
import {waitForMenuFrames} from './menu-frames.mjs';

const menuState = page => page.locator('canvas').evaluate(canvas => JSON.parse(canvas.dataset.menu ?? 'null'));

const infoState = page => page.locator('canvas').evaluate(canvas => JSON.parse(canvas.dataset.phpInfo ?? 'null'));

async function select(page, id)
{
	await waitForMenuFrames(page);

	await page.evaluate(async id => {
		const code = await enginePhp.run('<?php $pauseMenu->menu->select(' + JSON.stringify(id) + ');');

		if(code) throw Error('menu selection failed');
	}, id
	);
}

test.afterEach(async({page}) => { await page.evaluate(() => window.enginePhp?.refresh()).catch(() => {}); });

test('PHP info opens in the WebGL canvas and scrolls without leaving Settings', async({page}) => {
	const errors = [];
	page.on('pageerror', error => errors.push(error.message));

	await page.addInitScript(() => {
		window.phpInfoPad = {
			id: 'PHP info controller'
			, index: 0
			, connected: true
			, mapping: 'standard'
			, timestamp: performance.now()
			, axes: [0, 0, 0, 0]
			, buttons: Array.from({length: 17}, () => ({pressed: false, touched: false, value: 0}))
		};

		Object.defineProperty(navigator, 'getGamepads', {value: () => [phpInfoPad]});
	});

	await page.goto('/sane-phonics/');
	await page.waitForFunction(() => typeof window.bootEngine === 'function');
	await page.evaluate(() => window.bootEngine({scene: 'platformer'}));
	await expect.poll(() => menuState(page)).not.toBeNull();
	await page.locator('canvas').focus();
	await page.keyboard.press('p');
	await expect.poll(async() => (await menuState(page)).open).toBe(true);

	await select(page, 'settings');
	await page.keyboard.press('Enter');
	await expect.poll(async() => (await menuState(page)).title).toBe('Settings');
	await select(page, 'php-info');
	await page.keyboard.press('Enter');
	await expect.poll(async() => (await infoState(page))?.ready).toBe(true);
	expect(await page.evaluate(() => document.activeElement === document.querySelector('canvas'))).toBe(true);

	const opened = await infoState(page);
	expect(opened.open).toBe(true);
	expect(opened.markupBytes).toBeGreaterThan(10000);
	expect(opened.contentWidth).toBeGreaterThan(900);
	expect(opened.maxScroll).toBeGreaterThan(0);
	expect(opened.uploads).toBeGreaterThan(0);

	await page.evaluate(() => new Promise(resolve => {
		window.phpInfoHeadingChanges = [];
		const heading = document.querySelector('h2');

		window.phpInfoHeadingObserver = new ResizeObserver(
			entries => phpInfoHeadingChanges.push(entries[0].contentRect.height)
		);

		phpInfoHeadingObserver.observe(heading);

		requestAnimationFrame(() => requestAnimationFrame(() => {
			phpInfoHeadingChanges = [];
			resolve();
		}));
	}));

	await page.keyboard.press('ArrowDown');
	await expect.poll(async() => (await infoState(page)).scroll).toBeGreaterThan(opened.scroll);
	await page.waitForTimeout(100);
	expect((await infoState(page)).uploads).toBe(opened.uploads);
	expect(await page.evaluate(() => phpInfoHeadingChanges)).toEqual([]);
	expect(await page.evaluate(() => scrollY)).toBe(0);

	const smoothing = await page.evaluate(async () => {
		const domTexture = (await enginePhp.binary).domTexture;

		domTexture.scrollBy(480);

		return {scroll: domTexture.scroll, target: domTexture.targetScroll};
	});

	expect(smoothing.target).toBeGreaterThan(smoothing.scroll);
	await expect.poll(async() => (await infoState(page)).scroll).toBeGreaterThan(smoothing.scroll);
	await page.keyboard.press('PageDown');
	await expect.poll(async() => (await infoState(page)).targetScroll).toBeGreaterThan(smoothing.target);
	await expect.poll(async() => (await infoState(page)).scroll).toBeGreaterThan(48);

	expect((await infoState(page)).uploads).toBe(opened.uploads);
	expect(await page.evaluate(() => scrollY)).toBe(0);

	for(let press = 0; press < 3; press++)
	{
		const scroll = (await infoState(page)).scroll;
		await page.keyboard.press('PageDown');
		await expect.poll(async() => (await infoState(page)).scroll).toBeGreaterThan(scroll);
	}

	await expect.poll(async() => (await infoState(page)).scroll).toBeGreaterThan(1900);
	expect((await infoState(page)).error).toBe('');
	await page.locator('canvas').hover();
	const hostScroll = await page.evaluate(() => scrollY);
	await page.mouse.wheel(0, 300);
	await expect.poll(async() => (await infoState(page)).scroll).toBeGreaterThan(480);
	expect((await infoState(page)).uploads).toBe(opened.uploads);
	expect(await page.evaluate(() => scrollY)).toBe(hostScroll);
	await page.keyboard.press('End');

	await expect.poll(async() => {
		const state = await infoState(page);

		return state.scroll === state.maxScroll;
	}).toBe(true);

	expect((await infoState(page)).uploads).toBe(opened.uploads);
	expect(await page.evaluate(() => scrollY)).toBe(hostScroll);

	const uploads = (await infoState(page)).uploads;

	await page.evaluate(() => {
		window.phpInfoLoss = document.querySelector('canvas').getContext('webgl2').getExtension('WEBGL_lose_context');

		phpInfoLoss.loseContext();
	});

	await expect(page.locator('#status')).toContainText('Graphics paused');
	await page.evaluate(() => phpInfoLoss.restoreContext());

	await expect.poll(async() => {
		const state = await infoState(page);

		return state.ready && state.uploads > uploads;
	}).toBe(true);

	// Keep the physical B button held across the modal handoff: it must close PHP info only,
	// rather than immediately backing out of Settings as well.

	await page.evaluate(() => {
		phpInfoPad.buttons[1] = {pressed: true, touched: true, value: 1};
		phpInfoPad.timestamp = performance.now();
	});

	await expect.poll(async() => (await infoState(page)).open).toBe(false);
	await page.waitForTimeout(150);
	expect((await menuState(page)).title).toBe('Settings');

	await page.evaluate(() => {
		phpInfoPad.buttons[1] = {pressed: false, touched: false, value: 0};
		phpInfoPad.timestamp = performance.now();
	});

	expect(errors).toEqual([]);
});
