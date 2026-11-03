import {test, expect} from '@playwright/test';

const frameCount = async page => Number(await page.locator('canvas').getAttribute('data-frames'));

const state = page => page.locator('canvas').evaluate(canvas => ({
	body: JSON.parse(canvas.dataset.body ?? 'null')
	, grapples: JSON.parse(canvas.dataset.grapples ?? 'null')
}));

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

test.afterEach(async({page}) => {
	await page.evaluate(() => window.enginePhp?.refresh()).catch(() => {});
});

async function jump(page, device, pressed)
{
	if(device === 'keyboard')
	{
		await page.keyboard[pressed ? 'down' : 'up']('Space');
	}
	else
	{
		await page.evaluate(pressed => {
			grapplePad.buttons[0] = {pressed, touched: pressed, value: pressed ? 1 : 0};
			grapplePad.timestamp++;
		}, pressed
		);
	}
}

for(const [profile, device] of [['runner', 'keyboard']
	, ['flyer', 'keyboard']
	, ['glider', 'keyboard']
	, ['runner'
		, 'gamepad'
	]
])
test(
	profile + ' uses ' + device + ' to grab, pump, and release a rope without activating another ability'
	, async({page}) => {
		const errors = [];
		page.on('pageerror', error => errors.push(error.message));

		await page.addInitScript(() => {
			window.grapplePad = {
				id: 'Standard Gamepad'
				, index: 0
				, connected: true
				, mapping: 'standard'
				, timestamp: 1
				, axes: [0, 0, 0, 0]
				, buttons: Array.from({length: 17}, () => ({pressed: false, touched: false, value: 0}))
			};

			Object.defineProperty(navigator, 'getGamepads', {value: () => [grapplePad]});
		});

		await page.goto('/sane-phonics/');
		await page.evaluate(profile => window.bootEngine({intro: false, scene: 'grapples', profile}), profile);
		await expect.poll(async() => (await state(page)).body?.grounded, {timeout: 60000}).toBe(true);
		await page.locator('canvas').focus();

		if(device === 'keyboard') await page.keyboard.down('ArrowRight');
		else await page.evaluate(() => grapplePad.axes[0] = 1);

		await jump(page, device, true);
		await expect.poll(async() => (await state(page)).body?.ability).toBe('hanging');
		await jump(page, device, false);

		// Observe the swing each frame so polling cannot skip its positive half-cycle.
		await page.waitForFunction(
			() => JSON.parse(document.querySelector('canvas').dataset.grapples ?? '[]')[0]?.vx > 100
			, null
			, {timeout: 5000}
		);

		if(device === 'keyboard') await page.keyboard.up('ArrowRight');
		else await page.evaluate(() => grapplePad.axes[0] = 0);

		await page.screenshot({path: 'test-results/grapple-' + profile + '-' + device + '.png'});
		const held = await state(page);
		expect(held.body.pose).toBe('hanging');
		expect(held.grapples[0].riders).toHaveLength(1);

		await page.evaluate(() => {
			window.grappleRelease = null;

			const sample = () => {
				const body = JSON.parse(document.querySelector('canvas').dataset.body);

				if(body.grapple === null && body.jumping) grappleRelease = body;
				else requestAnimationFrame(sample);
			};

			requestAnimationFrame(sample);
		});

		await jump(page, device, true);
		await expect.poll(() => page.evaluate(() => grappleRelease?.ability)).toBe('');
		await jump(page, device, false);
		expect(await page.evaluate(() => grappleRelease.jumping)).toBe(true);
		expect(errors).toEqual([]);
	}
);

test('grapple course follows moving anchors and releases riders when changing levels or restarting', async({page}) => {
	const errors = [];
	page.on('pageerror', error => errors.push(error.message));
	await page.goto('/sane-phonics/');
	await page.evaluate(() => window.bootEngine({intro: false, scene: 'grapples'}));
	await expect.poll(async() => (await state(page)).grapples?.length, {timeout: 60000}).toBe(4);

	const result = JSON.parse(await run(page, `
		$engine->userPaused = true;
		$system = $game->level->grapples;
		$points = array_keys($system->points);
		$rope = $engine->entities->get($points[2], PhpWasmEngine\\Gameplay\\Rope::class);
		$anchor = $engine->entities->get($rope->anchor, PhpWasmEngine\\Gameplay\\RopeAnchor::class);
		$motion = $engine->entities->get($anchor->followEntity, PhpWasmEngine\\Gameplay\\PlatformOscillation::class);
		// Start on the moving part of the squine curve, independent of browser startup timing.
		$motion->time = $motion->period / 4 - $motion->phase - 1/60;
		$game->level->platforms->update($engine->entities, 1/60);
		$system->update($engine->entities, 1/60);
		$body->x = $rope->x;
		$body->y = $rope->y - 8;
		$body->vx = $body->vy = 0;
		$system->sync($entity, $body);
		$system->update($engine->entities, 1/60);
		$attached = $body->ability === 'hanging';
		$x = $body->x;
		for($i = 0; $i < 30; $i++)
		{
			$game->level->platforms->update($engine->entities, 1/60);
			$system->update($engine->entities, 1/60);
		}
		echo json_encode([$attached, abs($body->x-$x) > 5, abs($body->x-$anchor->x) < .01]);
		$oldGrappleLevel = $game->level;
		$game->restartLevel();
		$engine->userPaused = false;
	`
	));

	expect(result).toEqual([true, true, true]);
	await expect.poll(async() => (await state(page)).body?.grounded).toBe(true);

	expect(await run(page, `
		echo json_encode([
			$oldGrappleLevel !== $game->level,
			count($engine->entities->query(PhpWasmEngine\\Gameplay\\Rope::class)),
			$engine->entities->get($entity, PhpWasmEngine\\Gameplay\\GrappleGrip::class)->point
		]);
	`
	)).toBe('[true,4,null]');

	await run(page, `$game->selectScene('platformer');`);
	await expect(page.locator('canvas')).toHaveAttribute('data-scene', 'platformer');
	expect(await run(page, `echo count($engine->entities->query(PhpWasmEngine\\Gameplay\\Rope::class));`)).toBe('0');
	expect(errors).toEqual([]);
});

test('grapple rope sprites survive WebGL restoration and both menus expose the course', async({page}) => {
	await page.goto('/sane-phonics/');
	await page.evaluate(() => window.bootEngine({intro: true}));

	expect(await run(page, `
		$game->startup->showMenu();
		$game->startup->menu->select('level');
		$game->startup->menu->activate();
		echo array_key_exists('grapples', $game->startup->menu->rows()) ? 'yes' : 'no';
		$game->startup->menu->select('grapples');
		$game->startup->menu->activate();
		$game->startup->menu->select('play');
		$game->startup->menu->activate();
	`
	)).toBe('yes');

	await expect.poll(async() => (await state(page)).grapples?.length, {timeout: 60000}).toBe(4);
	await run(page, `$savedGrappleLevel = $game->level;`);

	await page.evaluate(() => {
		const context = document.querySelector('canvas').getContext('webgl2');
		window.grappleContext = context.getExtension('WEBGL_lose_context');

		grappleContext.loseContext();
	});

	await expect(page.locator('#status')).toContainText('Graphics paused');
	const frames = await frameCount(page);
	await page.evaluate(() => grappleContext.restoreContext());

	await expect.poll(() => frameCount(page)).toBeGreaterThan(frames + 5);

	expect(await run(page, `echo $savedGrappleLevel === $game->level ? 'same' : 'different';`)).toBe('same');
	await page.locator('canvas').focus();
	await page.keyboard.press('p');

	expect(await run(page, `
		$pauseMenu->menu->select('level');
		$pauseMenu->menu->activate();
		echo array_key_exists('grapples', $pauseMenu->menu->rows()) ? 'yes' : 'no';
	`
	)).toBe('yes');
});
