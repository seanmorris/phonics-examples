import {test, expect} from '@playwright/test';

const frameCount = async page => Number(await page.locator('canvas').getAttribute('data-frames'));

const state = page => page.locator('canvas').evaluate(canvas => ({
	body: JSON.parse(canvas.dataset.body ?? 'null')
	, vehicles: JSON.parse(canvas.dataset.vehicles ?? 'null')
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

async function input(page, device, button, pressed)
{
	if(device === 'keyboard')
	{
		const keys = {0: 'Space', 2: 'c', up: 'ArrowUp', right: 'ArrowRight', down: 'ArrowDown'};
		await page.keyboard[pressed ? 'down' : 'up'](keys[button]);
	}
	else
	{
		await page.evaluate(({button, pressed}) => {
			const index = {up: 12, right: 15, down: 13}[button] ?? button;
			vehiclePad.buttons[index] = {pressed, touched: pressed, value: pressed ? 1 : 0};
			vehiclePad.timestamp++;
		}, {button, pressed});
	}
}

test.afterEach(async({page}) => {
	await page.evaluate(() => window.enginePhp?.refresh()).catch(() => {});
});

for(const [profile, device] of [['runner', 'keyboard']
	, ['flyer', 'keyboard']
	, ['glider', 'keyboard']
	, ['runner'
		, 'gamepad'
	]
])
test(profile + ' boards, drives, jumps, tricks and exits a skateboard with ' + device, async({page}) => {
	const errors = [];
	page.on('pageerror', error => errors.push(error.message));

	await page.addInitScript(() => {
		window.vehiclePad = {
			id: 'Standard Gamepad'
			, index: 0
			, connected: true
			, mapping: 'standard'
			, timestamp: 1
			, axes: [0, 0, 0, 0]
			, buttons: Array.from({length: 17}, () => ({pressed: false, touched: false, value: 0}))
		};

		Object.defineProperty(navigator, 'getGamepads', {value: () => [vehiclePad]});
	});

	await page.goto('/sane-phonics/');

	await page.evaluate(
		profile => window.bootEngine({intro: false, scene: 'vehicles', profile, motionBlur: false})
		, profile
	);

	await expect.poll(async() => (await state(page)).body?.ability, {timeout: 60000}).toBe('riding');
	await page.locator('canvas').focus();
	const initial = await state(page);
	expect(initial.vehicles).toHaveLength(3);
	expect(initial.vehicles.filter(vehicle => vehicle.occupant !== null)).toHaveLength(1);
	await input(page, device, 'down', true);
	await expect.poll(async() => (await state(page)).body.pose).toBe('riding-crouching');
	await input(page, device, 0, true);

	await page.waitForFunction(
		() => JSON.parse(document.querySelector('canvas').dataset.body ?? '{}').grounded === false
		, null
		, {timeout: 5000}
	);

	await input(page, device, 0, false);
	await input(page, device, 'down', false);
	await input(page, device, 2, true);
	await expect.poll(async() => (await state(page)).body.pose).toBe('handstand');
	const airborne = await state(page);
	expect(airborne.body.ability).toBe('riding');
	expect(airborne.body.jumping).toBe(false);
	expect(airborne.body.charge).toBe(0);
	expect(airborne.body.lightDashed).toBe(false);
	await page.screenshot({path: 'test-results/skateboard-' + profile + '-' + device + '.png'});
	await input(page, device, 2, false);

	await input(page, device, 'right', true);
	await expect.poll(async() => (await state(page)).body.x).toBeGreaterThan(initial.body.x + 4);
	await input(page, device, 'right', false);
	await input(page, device, 'up', true);
	await input(page, device, 0, true);
	await expect.poll(async() => (await state(page)).body.vehicle).toBe(null);
	const exit = await state(page);
	expect(exit.body.ability).toBe('');
	expect(exit.vehicles.every(vehicle => vehicle.occupant === null)).toBe(true);
	await input(page, device, 0, false);
	await input(page, device, 'up', false);
	expect(errors).toEqual([]);
});

test(
	'skateboard course restarts and unloads without replacing the player or leaving a seat attached'
	, async({page}) => {
		const errors = [];
		page.on('pageerror', error => errors.push(error.message));
		await page.goto('/sane-phonics/');
		await page.evaluate(() => window.bootEngine({intro: false, scene: 'vehicles'}));
		await expect.poll(async() => (await state(page)).body?.ability, {timeout: 60000}).toBe('riding');
		const first = (await state(page)).body.vehicle;
		await run(page, `$oldVehicleLevel = $game->level; $oldPlayer = $entity; $game->restartLevel();`);
		await expect.poll(async() => (await state(page)).body.vehicle).not.toBe(first);
		await expect.poll(async() => (await state(page)).body.ability).toBe('riding');

		expect(await run(page, `echo json_encode([$oldPlayer === $entity, $oldVehicleLevel->vehicles->vehicles,
		count($engine->entities->query(PhpWasmEngine\\Gameplay\\Vehicle::class))]);`
		)).toBe('[true,[],3]');

		await run(page, `$game->selectScene('platformer');`);
		await expect(page.locator('canvas')).toHaveAttribute('data-scene', 'platformer');

		expect(
			await run(page, `echo json_encode([count($engine->entities->query(PhpWasmEngine\\Gameplay\\Vehicle::class)),
		$engine->entities->get($entity, PhpWasmEngine\\Gameplay\\Rider::class)->vehicle]);`
			)
		).toBe('[0,null]');

		expect(errors).toEqual([]);
	}
);

test('both menus expose the skateboard course and WebGL restoration keeps its live rider', async({page}) => {
	const errors = [];
	page.on('pageerror', error => errors.push(error.message));
	await page.goto('/sane-phonics/');
	await page.evaluate(() => window.bootEngine({intro: true}));

	expect(await run(page, `
		$game->startup->showMenu();
		$game->startup->menu->select('level');
		$game->startup->menu->activate();
		echo array_key_exists('vehicles', $game->startup->menu->rows()) ? 'yes' : 'no';
		$game->startup->menu->select('vehicles');
		$game->startup->menu->activate();
		$game->startup->menu->select('play');
		$game->startup->menu->activate();
	`
	)).toBe('yes');

	await expect.poll(async() => (await state(page)).body?.ability, {timeout: 60000}).toBe('riding');
	const rider = (await state(page)).body.vehicle;

	await page.evaluate(() => {
		const context = document.querySelector('canvas').getContext('webgl2');
		window.vehicleContext = context.getExtension('WEBGL_lose_context');

		vehicleContext.loseContext();
	});

	await expect(page.locator('#status')).toContainText('Graphics paused');
	const frames = Number(await page.locator('canvas').getAttribute('data-frames'));
	await page.evaluate(() => vehicleContext.restoreContext());

	await expect.poll(() => frameCount(page)).toBeGreaterThan(frames + 5);

	expect((await state(page)).body.vehicle).toBe(rider);
	await page.locator('canvas').focus();
	await page.keyboard.press('p');

	expect(await run(page, `
		$pauseMenu->menu->select('level');
		$pauseMenu->menu->activate();
		echo array_key_exists('vehicles', $pauseMenu->menu->rows()) ? 'yes' : 'no';
	`
	)).toBe('yes');

	expect(errors).toEqual([]);
});
