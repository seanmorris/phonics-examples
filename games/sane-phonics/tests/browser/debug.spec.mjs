import {test, expect} from '@playwright/test';

const state = page => page.locator('canvas').evaluate(canvas => JSON.parse(canvas.dataset.debug ?? 'null'));

const menu = page => page.locator('canvas').evaluate(canvas => JSON.parse(canvas.dataset.menu ?? 'null'));

async function run(page, source)
{
	return page.evaluate(async source => {
		let output = '';

		const listener = event => output += event.detail.join('');
		enginePhp.addEventListener('output', listener);

		try
		{
			const code = await enginePhp.run('<?php ' + source);

			if(code) throw Error('PHP returned ' + code);

			return output;
		}
		finally
		{
			enginePhp.removeEventListener('output', listener);
		}
	}, source
	);
}

async function start(page, intro = false)
{
	const errors = [];
	page.on('pageerror', error => errors.push(error.message));
	await page.goto('/sane-phonics/');
	await page.waitForFunction(() => typeof window.bootEngine === 'function');
	await page.evaluate(intro => window.bootEngine({intro}), intro);
	await expect.poll(() => state(page)).not.toBeNull();

	return errors;
}

async function activate(page, id, startup = false)
{
	await run(page, (startup ? '$game->startup->menu' : '$pauseMenu->menu') + '->select(' + JSON.stringify(id) + ');');

	await page.keyboard.press('Enter');
}

test.afterEach(async ({page}) => {
	await page.evaluate(() => window.enginePhp?.refresh()).catch(() => {});
});

test(
	'F9 toggles a live bitmap overlay once per focused press without capturing movement'
	, async ({page}) => {
		const errors = await start(page);
		await expect.poll(async () => (await state(page)).fps).toBeGreaterThan(0);
		expect((await state(page)).enabled).toBe(false);
		expect((await state(page)).showFps).toBe(true);
		expect((await state(page)).draws).toBe(1);
		await page.keyboard.down('F9');
		await expect.poll(async () => (await state(page)).enabled).toBe(true);
		await expect.poll(async () => (await state(page)).rows.Player).toBe('runner');
		expect((await state(page)).draws).toBe(2);
		await page.keyboard.down('F9');
		await page.keyboard.down('F9');
		await page.keyboard.up('F9');
		const previous = (await state(page)).frame;
		await expect.poll(async () => (await state(page)).frame).toBeGreaterThan(previous + 2);
		expect((await state(page)).enabled).toBe(true);

		const liveFrames = await page.evaluate(async() => {
			const canvas = document.querySelector('canvas');
			const frames = [];

			for(let index = 0; index < 4; index++)
			{
				await new Promise(requestAnimationFrame);
				const debug = JSON.parse(canvas.dataset.debug);
				frames.push([debug.frame, Number(debug.rows['Render frame'])]);
			}

			return frames;
		});

		expect(liveFrames.every(([frame, row]) => frame === row)).toBe(true);

		const x = Number((await state(page)).rows['X px']);
		await page.keyboard.down('ArrowRight');
		await expect.poll(async () => Number((await state(page)).rows['X px'])).toBeGreaterThan(x + 10);
		await page.keyboard.up('ArrowRight');
		const bounds = (await state(page)).panel;
		await run(page, '$camera->zoom = 2;');
		await expect.poll(async () => (await state(page)).rows.Zoom).toBe('2.00');
		expect((await state(page)).panel).toEqual(bounds);

		await page.locator('#mute').focus();
		await page.keyboard.press('F9');
		const blurred = (await state(page)).frame;
		await expect.poll(async () => (await state(page)).frame).toBeGreaterThan(blurred + 2);
		expect((await state(page)).enabled).toBe(true);
		await page.locator('canvas').focus();
		await page.keyboard.press('F9');
		await expect.poll(async () => (await state(page)).enabled).toBe(false);
		await page.keyboard.press('F9');
		await expect.poll(async () => (await state(page)).panel).not.toBeNull();
		await page.locator('#fullscreen').click();
		await expect.poll(() => page.evaluate(() => document.fullscreenElement?.id)).toBe('game-frame');
		expect((await state(page)).panel).toEqual(bounds);
		await page.locator('#fullscreen').click();
		await run(page, '$camera->zoom = 1;');
		await page.locator('#game-frame').screenshot({path: 'test-results/debug-overlay.png'});
		expect(await run(page, 'echo glGetError();')).toBe('0');
		expect(errors).toEqual([]);
	}
);

test(
	'menu settings persist through level changes and camera scenes report no player'
	, async ({page}) => {
		const binaries = [];

		page.on('request', request => {
			if(request.url().endsWith('.wasm')) binaries.push(request.url());
		});

		const errors = await start(page);
		const downloads = binaries.length;
		await page.keyboard.press('p');
		await expect.poll(async () => (await menu(page)).open).toBe(true);
		await activate(page, 'settings');
		const pausedTime = (await menu(page)).time;
		await activate(page, 'debug');
		await expect.poll(async () => (await state(page)).enabled).toBe(true);
		await activate(page, 'fps');
		await expect.poll(async () => (await state(page)).showFps).toBe(false);
		await page.keyboard.press('F9');
		await expect.poll(async () => (await state(page)).draws).toBe(0);
		expect((await menu(page)).time).toBe(pausedTime);
		await page.keyboard.press('F9');
		await expect.poll(async () => (await state(page)).draws).toBe(2);
		await expect.poll(async () => (await state(page)).fps).toBeGreaterThan(0);
		expect((await menu(page)).time).toBe(pausedTime);
		await expect.poll(async () => (await state(page)).rows['Casts/frame']).toBe('0');
		expect(Number(await run(page, 'echo $game->level->terrain->raycasts;'))).toBeGreaterThan(0);
		const worldStats = JSON.parse(await run(page, 'echo json_encode($game->renderStats);'));
		expect(Number((await state(page)).rows['World draws'])).toBe(worldStats.draws);
		expect(Number((await state(page)).rows.Instances)).toBe(worldStats.instances);

		await page.keyboard.press('Escape');
		await activate(page, 'level');
		await activate(page, 'world');
		await expect.poll(async () => (await state(page)).rows.Scene).toBe('world');
		expect((await state(page)).rows.Player).toBe('None (camera)');
		expect((await state(page)).rows['X px']).toBeUndefined();
		expect((await state(page)).rows['Casts/frame']).toBe('-');
		expect((await state(page)).showFps).toBe(false);

		await expect.poll(async () => Number((await state(page)).rows['Maps R/P/E'].split(' / ')[0]))
			.toBeGreaterThan(0);

		await run(page, '$game->selectScene("isometric");');
		await expect.poll(async () => (await state(page)).rows.Scene).toBe('isometric');
		expect((await state(page)).rows.Player).toBe('None (camera)');
		await run(page, '$game->selectScene("loop"); $game->selectProfile("glider");');
		await expect.poll(async () => (await state(page)).rows.Scene).toBe('loop');
		expect((await state(page)).rows.Player).toBe('glider');
		expect(binaries.length).toBe(downloads);

		await page.keyboard.press('p');
		await expect.poll(async () => (await menu(page)).open).toBe(true);
		await activate(page, 'settings');
		await activate(page, 'defaults');
		await activate(page, 'yes');
		await expect.poll(async () => (await state(page)).enabled).toBe(false);
		expect((await state(page)).showFps).toBe(true);
		expect(errors).toEqual([]);
	}
);

test(
	'debug graphics and shortcuts survive context loss, idle recovery, and restarting PHP'
	, async ({page}) => {
		const errors = await start(page);
		await page.keyboard.down('F9');
		await expect.poll(async () => (await state(page)).enabled).toBe(true);

		await page.evaluate(() => {
			Object.defineProperty(document, 'hidden', {configurable: true, get: () => true});
			document.dispatchEvent(new Event('visibilitychange'));
		});

		await page.waitForTimeout(100);
		const hiddenFrame = (await state(page)).frame;

		await page.evaluate(() => {
			delete document.hidden;
			document.dispatchEvent(new Event('visibilitychange'));
		});

		await expect.poll(async () => (await state(page)).frame).toBeGreaterThan(hiddenFrame + 2);
		await page.keyboard.down('F9');
		await page.keyboard.up('F9');
		const repeatFrame = (await state(page)).frame;
		await expect.poll(async () => (await state(page)).frame).toBeGreaterThan(repeatFrame + 2);
		expect((await state(page)).enabled).toBe(true);

		for(let cycle = 0; cycle < 2; cycle++)
		{
			await page.evaluate(() => {
				window.debugLoss = document.querySelector('canvas').getContext('webgl2')
					.getExtension('WEBGL_lose_context');

				debugLoss.loseContext();
			});

			await expect(page.locator('#status')).toContainText('Graphics paused');
			const lostFrame = (await state(page)).frame;
			await page.evaluate(() => debugLoss.restoreContext());
			await expect.poll(async () => (await state(page)).frame).toBeGreaterThan(lostFrame + 2);
			expect((await state(page)).enabled).toBe(true);
			expect((await state(page)).draws).toBe(2);
			expect(await run(page, 'echo glGetError();')).toBe('0');
		}

		await page.keyboard.press('F9');
		await expect.poll(async () => (await state(page)).enabled).toBe(false);
		await page.evaluate(() => stopEngine());
		expect(await state(page)).toBeNull();
		await page.evaluate(() => bootEngine());
		await expect.poll(async () => (await state(page))?.showFps).toBe(true);
		await page.keyboard.press('F9');
		await expect.poll(async () => (await state(page)).enabled).toBe(true);
		expect((await state(page)).draws).toBe(2);
		expect(errors).toEqual([]);
	}
);

test(
	'startup shares debug settings but keeps telemetry off the intro and main menu'
	, async ({page}) => {
		const errors = await start(page, true);
		expect((await state(page)).visible).toBe(false);
		expect((await state(page)).draws).toBe(0);
		await run(page, '$game->startup->showMenu();');
		await expect.poll(async () => (await menu(page)).title).toBe('Main menu');
		await activate(page, 'settings', true);
		await activate(page, 'debug', true);
		await activate(page, 'fps', true);
		await expect.poll(async () => (await state(page)).enabled).toBe(true);
		expect((await state(page)).showFps).toBe(false);
		expect((await state(page)).draws).toBe(0);
		await run(page, '$game->startup->play();');
		await expect.poll(async () => (await state(page)).visible).toBe(true);
		expect((await state(page)).enabled).toBe(true);
		expect((await state(page)).draws).toBe(2);
		await run(page, '$game->startup->showMenu();');
		await expect.poll(async () => (await state(page)).visible).toBe(false);
		await run(page, '$game->startup->resume();');
		await expect.poll(async () => (await state(page)).visible).toBe(true);
		expect((await state(page)).enabled).toBe(true);
		expect(errors).toEqual([]);
	}
);

test('demo recognizes Konami and reports fighting inputs in its debug panel', async ({page}) => {
	const errors = await start(page);

	for(const key of ['ArrowUp', 'ArrowUp', 'ArrowDown', 'ArrowDown', 'ArrowLeft'
		, 'ArrowRight', 'ArrowLeft', 'ArrowRight', 'x', 'Space'
	]){
		await page.keyboard.press(key);
	}

	await expect.poll(async () => (await state(page)).enabled).toBe(true);
	await expect.poll(async () => (await state(page)).rows.Sequence).toBe('konami');
	await page.keyboard.down('ArrowDown');
	await page.keyboard.press('c');
	await page.keyboard.up('ArrowDown');
	await expect.poll(async () => (await state(page)).rows.Sequence).toBe('uppercut');
	expect(Number((await state(page)).rows.Matches)).toBeGreaterThanOrEqual(2);
	const panel = (await state(page)).panel;
	expect(panel[1] + panel[3]).toBeLessThanOrEqual(540);
	await page.evaluate(() => window.bootEngine());
	await expect.poll(async () => (await state(page)).enabled).toBe(false);
	expect(JSON.parse(await page.locator('canvas').getAttribute('data-sequences')).count).toBe(0);
	expect(errors).toEqual([]);
});
