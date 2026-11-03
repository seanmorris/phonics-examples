import {test, expect} from '@playwright/test';
import {waitForMenuFrames} from './menu-frames.mjs';
import {externalAssets} from './external-assets.mjs';

test.beforeEach(async ({page}) => externalAssets(page));

const data = (page, key) => page.locator('canvas').evaluate(
	(canvas, key) => JSON.parse(canvas.dataset[key] || 'null')
	, key
);

const php = (page, code) => page.evaluate(code => enginePhp.run('<?php ' + code), code);

async function select(page, id)
{
	await waitForMenuFrames(page);
	await php(page, '$pauseMenu->menu->select(' + JSON.stringify(id) + ');');
	await page.keyboard.press('Enter');
}

async function change(page, id)
{
	if(!(await data(page, 'menu')).open) await page.keyboard.press('p');

	await expect.poll(async () => (await data(page, 'menu')).open).toBe(true);
	await php(page, '$pauseMenu->menu->open();');
	await select(page, 'level');
	await select(page, id);
}

async function start(page, scene = 'platformer')
{
	await page.goto('/sane-phonics/');
	await page.waitForFunction(() => typeof bootEngine === 'function');
	await page.evaluate(scene => bootEngine({scene}), scene);
	await expect.poll(async () => (await data(page, 'menu'))?.open, {timeout: 60000}).toBe(false);
	await expect(page.locator('canvas')).toHaveAttribute('data-scene', scene);
}

test.afterEach(async ({page}) => {
	await page.evaluate(() => window.stopEngine?.()).catch(() => {});
});

test(
	'all levels switch in one session, retire debris, retain shared assets and restore graphics'
	, async ({page}) => {
		test.setTimeout(180000); // Seventeen courses, asset retirement, and graphics restoration.
		const errors = [], files = [];
		page.on('pageerror', error => errors.push(error.message));

		page.on('request', request => {
			if(request.url().includes('/examples/assets/')) files.push(request.url().split('/').at(-1));
		});

		await start(page);
		expect(files).not.toContain('loop.tmj');
		expect(files).not.toContain('stream.world');
		await php(page, '$originalEngine = $engine; $originalPlayer = $body;');

		for(const scene of [
			'breakables'
			, 'platforms'
			, 'light-dash'
			, 'water'
			, 'water-rail'
			, 'water-skim-rail'
			, 'water-loop'
			, 'gravity'
			, 'ceiling-curves'
			, 'grinding'
			, 'loop'
			, 'tornado'
			, 'egg-walker'
			, 'infinite', 'isometric', 'world', 'breakables'
		]) {
			await change(page, scene);
			await expect(page.locator('canvas')).toHaveAttribute('data-scene', scene);
			await expect.poll(async () => (await data(page, 'menu')).open).toBe(false);

			await php(page, `vrzno_env('canvas')->dataset->identity = json_encode([
			$originalEngine === $engine, $originalPlayer === $body,
			count($engine->entities->query(PhpWasmEngine\\Gameplay\\BreakableBlock::class)),
			count($engine->entities->query(PhpWasmEngine\\Effects\\ParticleBurst::class)),
			count($engine->entities->query(PhpWasmEngine\\Gameplay\\WaterParticle::class))
		]);`
			);

			expect(await data(page, 'identity')).toEqual([true, true, scene === 'breakables' ? 3 : 0, 0, 0]);

			if(scene === 'breakables')
			{
				await php(page, `$game->level->blocks->shatter(
				$engine->entities->query(PhpWasmEngine\\Gameplay\\BreakableBlock::class)[0],
				$engine->time * 1000, lifetime: 100
			);`
				);

				await expect.poll(async () => (await data(page, 'breakables')).pieces).toBeGreaterThan(0);
			}
		}

		// Isometric uses iso.png, so tiles.png is evicted and fetched again on world entry.
		expect(files.filter(file => file === 'tiles.png')).toHaveLength(2);
		expect(files.filter(file => file === 'tiles.tsj')).toHaveLength(2);

		expect(await page.evaluate(async () => {
			const {FS} = await enginePhp.binary;

			return ['platformer.tmj', 'stream.world', 'stream-2.tmj', 'breakables.tmj', 'tiles.png']
				.map(name => FS.analyzePath('/phonics/assets/1/examples/assets/' + name).exists);
		})).toEqual([false, false, false, true, true]);

		await page.evaluate(() => {
			window.context = document.querySelector('canvas').getContext('webgl2')
				.getExtension('WEBGL_lose_context');

			context.loseContext();
		});

		await expect(page.locator('#status')).toContainText('Graphics paused');
		const frames = Number(await page.locator('canvas').getAttribute('data-frames'));
		await page.evaluate(() => context.restoreContext());

		await expect.poll(async () => Number(await page.locator('canvas').getAttribute('data-frames')))
			.toBeGreaterThan(frames + 10);

		expect(errors).toEqual([]);
	}
);

test(
	'failed target keeps the current level running and can be retried from the same menu'
	, async ({page}) => {
		await start(page);
		await page.route('**/loop.tmj', route => route.fulfill({status: 503, body: 'Unavailable'}));
		await change(page, 'loop');
		await expect(page.locator('#status')).toContainText('503');
		await expect(page.locator('canvas')).toHaveAttribute('data-scene', 'platformer');
		await expect(page.locator('#start')).toBeHidden();
		const frames = Number(await page.locator('canvas').getAttribute('data-frames'));

		await expect.poll(async () => Number(await page.locator('canvas').getAttribute('data-frames')))
			.toBeGreaterThan(frames + 5);

		await page.unroute('**/loop.tmj');
		await select(page, 'loop');
		await expect(page.locator('canvas')).toHaveAttribute('data-scene', 'loop');
		await expect.poll(async () => (await data(page, 'menu')).open).toBe(false);
	}
);

test('latest level request wins and cancelled assets cannot reappear', async ({page}) => {
	await start(page);
	let pending;
	await page.route('**/loop.tmj', route => { pending = route; });

	await change(page, 'loop');
	await expect.poll(() => Boolean(pending), {timeout: 15000}).toBe(true);
	await select(page, 'infinite');
	await expect(page.locator('canvas')).toHaveAttribute('data-scene', 'infinite');
	await pending.fulfill({status: 200, body: '{}'}).catch(() => {});

	await expect.poll(async () => page.evaluate(async () => {
		const {FS} = await enginePhp.binary;

		return FS.analyzePath('/phonics/assets/1/examples/assets/loop.tmj').exists;
	})).toBe(false);

	await expect(page.locator('canvas')).toHaveAttribute('data-scene', 'infinite');
});

test(
	'movement selection changes the current actor without relocating it or clearing settings'
	, async ({page}) => {
		await start(page);
		await page.keyboard.press('p');
		await expect.poll(async () => (await data(page, 'menu')).open).toBe(true);

		await php(page, `$body->x = 300; $body->y = 90; $body->ability = 'flight';
		$body->vx = $body->vy = $body->gravity = 0;
		$pauseMenu->menu->select('settings'); $pauseMenu->menu->activate();
		$pauseMenu->menu->setValue('name', 'Test player'); $pauseMenu->menu->back();`
		);

		await select(page, 'movement');
		await select(page, 'glider');
		await expect(page.locator('canvas')).toHaveAttribute('data-profile', 'glider');
		const body = await data(page, 'body');
		expect(body.x).toBe(300);
		expect(body.y).toBe(90);
		expect(body.ability).toBe('');
		await page.keyboard.press('p');
		await expect.poll(async () => (await data(page, 'menu')).values.name).toBe('Test player');
	}
);

test('cancelling the first level load settles startup and Run can retry', async ({page}) => {
	await page.goto('/sane-phonics/');
	await page.waitForFunction(() => typeof bootEngine === 'function');
	let pending;
	await page.route('**/loop.tmj', route => { pending = route; });

	await page.evaluate(() => {
		window.startResult = bootEngine({scene: 'loop'}).catch(error => error.message);
	});

	await expect.poll(() => Boolean(pending), {timeout: 15000}).toBe(true);
	await page.locator('canvas').focus();
	await page.keyboard.press('p');
	await expect.poll(async () => (await data(page, 'menu')).open).toBe(true);
	await select(page, 'level');
	await select(page, 'cancel-load');
	await expect(page.locator('#start')).toBeEnabled();
	await expect(page.locator('#status')).toContainText('cancelled');
	expect(await page.evaluate(() => startResult)).toContain('cancelled');
	await pending.fulfill({status: 200, body: '{}'}).catch(() => {});

	await page.unroute('**/loop.tmj');
	await page.evaluate(() => window.bootEngine({intro: false}));
	await expect(page.locator('canvas')).toHaveAttribute('data-scene', 'loop');
	await expect(page.locator('#start')).toBeHidden();
});
