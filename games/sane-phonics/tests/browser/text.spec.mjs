import {test, expect} from '@playwright/test';

const textState = page => page.locator('canvas').evaluate(canvas => JSON.parse(canvas.dataset.text ?? 'null'));

const messageState = page => page.locator('canvas').evaluate(canvas => JSON.parse(canvas.dataset.messages ?? 'null'));

test.afterEach(async({page}) => { await page.evaluate(() => window.enginePhp?.refresh()).catch(() => {}); });

test('map TextActors and MB2-style dialog layers render through context recovery', async({page}) => {
	const errors = [];
	page.on('pageerror', error => errors.push(error.message));
	await page.goto('/sane-phonics/');
	await page.waitForFunction(() => typeof window.bootEngine === 'function');
	await page.evaluate(() => window.bootEngine({scene: 'platformer'}));
	await expect.poll(async() => (await textState(page))?.worldActors).toBeGreaterThanOrEqual(2);

	await page.evaluate(async() => {
		const code = await enginePhp.run('<?php $game->showTextDemo();');

		if(code) throw Error('text demo failed');
	});

	await expect.poll(async() => (await messageState(page))?.dialog?.speaker).toBe('TEXT SYSTEM');
	await expect.poll(async() => (await messageState(page))?.center?.text).toBe('TEXT SYSTEM READY');
	await expect.poll(async() => (await messageState(page))?.demo?.text).toBe('▶ PLAY');
	await expect.poll(async() => (await textState(page))?.overlayGlyphs).toBeGreaterThan(0);

	await page.evaluate(() => {
		window.textContext = document.querySelector('canvas').getContext('webgl2').getExtension('WEBGL_lose_context');
		textContext.loseContext();
	});

	await expect(page.locator('#status')).toContainText('Graphics paused');
	await page.evaluate(() => textContext.restoreContext());
	await expect.poll(async() => (await textState(page))?.worldActors).toBeGreaterThanOrEqual(2);
	await expect.poll(async() => (await messageState(page))?.dialog?.speaker).toBe('TEXT SYSTEM');
	expect(errors).toEqual([]);
});
