import {test,expect} from '@playwright/test';
import {externalAssets} from './external-assets.mjs';

test.beforeEach(async ({page}) => externalAssets(page));

const state = page => page.evaluate(() => JSON.parse(document.querySelector('canvas').dataset.world||'{}'));

async function start(page)
{
	const errors = [];
	page.on('pageerror', error => errors.push(error.message));
	await page.goto('/sane-phonics/');
	await page.waitForFunction(() => typeof window.bootEngine==='function');
	await page.evaluate(() => window.bootEngine({scene: 'world'}));
	await expect.poll(async() => (await state(page)).draws||0, {timeout: 60000}).toBeGreaterThan(0);

	return errors;
}

async function pan(page, direction, x)
{
	await page.locator('canvas').focus();
	await page.keyboard.down(direction);

	try
	{
		await page.waitForFunction(({direction,x}) => {
			const state = JSON.parse(document.querySelector('canvas').dataset.world);

			return direction==='ArrowRight' ? state.x>=x : state.x<=x;
		}, {direction,x});
	}
	finally
	{
		await page.keyboard.up(direction);
	}
}

test.afterEach(async({page}) => {await page.evaluate(() => window.enginePhp?.refresh()).catch(() => {});});

test(
	'world downloads nearby maps and shared dependencies, evicts distant files and restores graphics'
	, async({page}) => {
		const requests = [];

		page.on('request', request => {
			if(request.url().includes('/examples/assets/') || request.url().includes('/worlds/'))
			requests.push(new URL(request.url()).pathname.split('/').at(-1));
		});

		const errors = await start(page);
		await expect.poll(async() => (await state(page)).pending.length).toBe(0);
		expect(requests).not.toContain('world.world'); // World definitions remain in the executable archive.
		expect(requests).not.toContain('stream-0.tmj');
		expect(requests).not.toContain('stream-4.tmj');
		expect(requests).not.toContain('platformer.tmj');
		expect(requests).not.toContain('isometric.tmj');
		expect(requests.filter(path => path==='tiles.tsj')).toHaveLength(1);
		expect(requests.filter(path => path==='tiles.png')).toHaveLength(1);
		expect(requests.filter(path => path==='shine-1.png')).toHaveLength(1);
		await pan(page, 'ArrowRight', 2200);
		await expect.poll(async() => (await state(page)).pending.length).toBe(0);
		expect(requests).toContain('stream-4.tmj');
		expect(requests.filter(path => path==='tiles.png')).toHaveLength(1);
		expect((await state(page)).loaded).not.toContain('stream-2.tmj');

		expect(await page.evaluate(async() => {
			const module = await enginePhp.binary;

			return module.FS.analyzePath('/phonics/assets/1/examples/assets/stream-2.tmj').exists;
		})).toBe(false);

		await page.evaluate(() => {
			window.lost = document.querySelector('canvas').getContext('webgl2').getExtension('WEBGL_lose_context');
			lost.loseContext();
		});

		await expect(page.locator('#status')).toContainText('Graphics paused');
		const before = Number(await page.locator('canvas').getAttribute('data-frames'));
		const requestsBefore = requests.length;
		await page.evaluate(() => lost.restoreContext());

		await expect.poll(async() => Number(await page.locator('canvas').getAttribute('data-frames'))).toBeGreaterThan(
			before+10
		);

		expect(requests.length).toBe(requestsBefore);
		await pan(page, 'ArrowLeft', -1600);
		await expect.poll(async() => (await state(page)).pending.length).toBe(0);
		expect(requests).toContain('stream-0.tmj');
		expect(requests.filter(path => path==='stream-2.tmj')).toHaveLength(2);
		expect((await state(page)).errors).toEqual([]);
		await page.screenshot({path: 'test-results/world.png',fullPage: true});
		await page.evaluate(() => window.stopEngine());
		await expect(page.locator('#start')).toBeEnabled();
		expect(errors).toEqual([]);
	}
);

test('failed map fetches stay local, retry with X, and cannot resurrect a stopped world', async({page}) => {
	let attempts = 0,unblock;

	await page.route('**/stream-3.tmj', async route => {
		attempts++;

		if(attempts===1) await route.fulfill({status: 503,body: 'temporarily unavailable'});
		else if(attempts===2) await route.continue();
		else
		{
			await new Promise(resolve => unblock = resolve);
			await route.fulfill({status: 200,body: '{}'}).catch(() => {});
		}
	});

	const errors = await start(page);
	await expect.poll(async() => Object.keys((await state(page)).errors).length).toBe(1);
	expect((await state(page)).loaded).toContain('stream-2.tmj');
	await page.waitForTimeout(250);
	expect(attempts).toBe(1);
	await page.keyboard.press('x');
	await expect.poll(async() => Object.keys((await state(page)).errors).length).toBe(0);
	await expect.poll(async() => (await state(page)).loaded.includes('stream-3.tmj')).toBe(true);
	await pan(page, 'ArrowLeft', -1600);
	await pan(page, 'ArrowRight', 500);
	await expect.poll(() => attempts).toBe(3);
	await page.evaluate(() => window.stopEngine());
	await expect(page.locator('#start')).toBeEnabled();
	unblock();
	await page.waitForTimeout(200);

	expect(await page.evaluate(async() => {
		const module = await enginePhp.binary;

		return module.FS.analyzePath('/phonics/assets/1/examples/assets/stream-3.tmj').exists;
	})).toBe(false);

	expect(errors).toEqual([]);
});
