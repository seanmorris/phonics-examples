import {test, expect} from '@playwright/test';

const menuOpen = page => page.locator('canvas').evaluate(canvas => JSON.parse(canvas.dataset.menu).open);

const state = page => page.locator('canvas').evaluate(canvas => ({
	body: JSON.parse(canvas.dataset.body ?? 'null')
	, hud: JSON.parse(canvas.dataset.hud ?? 'null')
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

for(const device of ['keyboard', 'gamepad'])
test(device + ' button two acquires a light-dash trail from an actual jump', async({page}) => {
	const errors = [];
	page.on('pageerror', error => errors.push(error.message));

	await page.addInitScript(() => {
		window.dashPad = {
			id: 'Standard Gamepad'
			, index: 0
			, connected: true
			, mapping: 'standard'
			, timestamp: 1
			, axes: [0, 0, 0, 0]
			, buttons: Array.from({length: 17}, () => ({pressed: false, touched: false, value: 0}))
		};

		Object.defineProperty(navigator, 'getGamepads', {value: () => [window.dashPad]});
	});

	await page.goto('/sane-phonics/');
	await page.evaluate(() => window.bootEngine({intro: false, scene: 'light-dash'}));
	await expect.poll(async() => (await state(page)).body?.grounded, {timeout: 60000}).toBe(true);
	await page.locator('canvas').focus();

	await page.evaluate(() => {
		window.dashSamples = [];

		const sample = () => {
			const body = JSON.parse(document.querySelector('canvas').dataset.body ?? 'null');

			if(body) dashSamples.push(body);

			if(dashSamples.length < 600) requestAnimationFrame(sample);
		};

		requestAnimationFrame(sample);
	});

	if(device === 'keyboard')
	{
		await page.keyboard.down('c');
		await page.keyboard.down('Space');
	}
	else
	{
		await page.evaluate(() => {
			for(const button of [0, 2]) dashPad.buttons[button] = {pressed: true, touched: true, value: 1};

			dashPad.timestamp++;
		});
	}

	await expect.poll(() => page.evaluate(() => dashSamples.some(body => body.ability === 'lightdash'))).toBe(true);

	if(device === 'keyboard')
	{
		await page.keyboard.up('c');
		await page.keyboard.up('Space');
	}
	else
	{
		await page.evaluate(() => {
			dashPad.buttons = dashPad.buttons.map(() => ({pressed: false, touched: false, value: 0}));
			dashPad.timestamp++;
		});
	}

	await expect.poll(async() => (await state(page)).hud?.rings).toBe(13);
	await expect.poll(async() => (await state(page)).body?.ability).toBe('');

	// Frames are sampled at the render rate, so a slow renderer may skip the dash's highest frames;
	// the 13 collected rings already prove the trail was traversed.
	const dashFollowsJump = await page.evaluate(() => {
		const dash = dashSamples.findIndex(body => body.pose === 'lightdash');

		return dash > 0
			&& !dashSamples[dash].grounded
			&& dashSamples.slice(0, dash).some(body => body.pose === 'jumping' && !body.grounded);
	});

	expect(dashFollowsJump).toBe(true);
	await page.screenshot({path: 'test-results/light-dash-' + device + '.png'});
	expect(errors).toEqual([]);
});

test(
	'restart shortcut and pause action restore ring trails without restarting PHP or changing settings'
	, async({page}) => {
		const files = [];
		page.on('request', request => files.push(request.url()));
		await page.goto('/sane-phonics/');
		await page.evaluate(() => window.bootEngine({intro: false, scene: 'light-dash'}));
		await expect.poll(async() => (await state(page)).body?.grounded, {timeout: 60000}).toBe(true);

		await run(page, `
		$originalEngine = $engine;
		$originalBody = $body;
		$oldDashLevel = $game->level;
		$counter->score = 1234;
		$counter->rings = 7;
		foreach($engine->entities->query(PhpWasmEngine\\Gameplay\\Ring::class) as $id)
		{
			$engine->entities->destroy($id);
		}
	`
		);

		await page.locator('canvas').focus();
		await page.keyboard.press('Shift+R');
		await expect.poll(async() => (await state(page)).hud?.rings).toBe(0);

		const restored = JSON.parse(await run(page, `
		echo json_encode([
			$game->level !== $oldDashLevel,
			$engine === $originalEngine, $body === $originalBody,
			count($engine->entities->query(PhpWasmEngine\\Gameplay\\Ring::class)),
			$counter->score, $body->lightDashed
		]);
	`
		));

		expect(restored).toEqual([true, true, true, 25, 1234, false]);
		await page.keyboard.press('p');

		await expect.poll(() => menuOpen(page)).toBe(true);

		await run(page, `
		$oldDashLevel = $game->level;
		$pauseMenu->menu->select('restart');
		$pauseMenu->menu->activate();
	`
		);

		await expect.poll(() => menuOpen(page)).toBe(false);

		expect(await run(page, 'echo $game->level !== $oldDashLevel ? "restarted" : "old";')).toBe('restarted');
		expect(files.filter(url => url.endsWith('/light-dash.tmj'))).toHaveLength(0);
		expect(files.filter(url => url.endsWith('/tiles.png'))).toHaveLength(0);
	}
);

test('main menu exposes light dash and held landing input can reverse a drop dash in WASM', async({page}) => {
	await page.goto('/sane-phonics/');
	await page.evaluate(() => window.bootEngine({intro: true}));

	const result = JSON.parse(await run(page, `
		$game->startup->showMenu();
		$game->startup->menu->select('level');
		$game->startup->menu->activate();
		$available = array_key_exists('light-dash', $game->startup->menu->rows());
		$terrain = new PhpWasmEngine\\Physics\\Terrain;
		$terrain->add([[-1000, 100], [1000, 100], [1000, 200], [-1000, 200]]);
		$movement = new PhpWasmEngine\\Physics\\Platformer($terrain);
		$actor = new PhpWasmEngine\\Physics\\Body(0, 86);
		$actor->halfHeight = 12;
		$actor->jumping = true;
		$actor->dropCharge = 1;
		$actor->vx = 360;
		$actor->vy = 240;
		$actor->airAcceleration = 0;
		$input = new PhpWasmEngine\\Input\\Controls;
		$input->set(['action' => true, 'left' => true]);
		$movement->step($actor, $input, 1 / 60);
		echo json_encode([$available, $actor->direction, $actor->groundSpeed, $actor->rolling]);
	`
	));

	expect(result).toEqual([true, -1, -480, true]);
});
