import {test,expect} from '@playwright/test';

test('the Doom application boots through the preview host with its verified browser export', async({page}) => {
	const errors = [];
	page.on('pageerror', error => errors.push(error.message));
	await page.goto('/doom-phonics/');

	await page.waitForFunction(() => {
		const status = document.querySelector('#status').textContent;

		return status === 'Ready' || document.querySelector('.phonics-preloader[data-failed]') !== null;
	}, null, {timeout: 60000});

	await expect(page.locator('#status')).toHaveText('Ready');
	await expect(page.getByLabel('Application PHAR URL')).toHaveValue(/doom-phonics\/dist\/game\.phar$/);

	await expect.poll(async() => {
		const state = await page.evaluate(() => window.previewSession?.doomState);

		return state?.menu?.screen;
	}).toBe('title');

	expect(await page.evaluate(async() => {
		const module = await previewSession.php.binary;

		return typeof module.doomMusic;
	})).toBe('function');

	expect(errors).toEqual([]);
	await page.click('#stop');
	await expect(page.locator('#status')).toHaveText('Stopped');
	expect(await page.evaluate(() => window.previewSession)).toBeNull();
});

test('ammo boxes refill the equipped launcher and the HUD ammo table', async({page}, testInfo) => {
	const errors = [];
	page.on('pageerror', error => errors.push(error.message));
	await page.goto('/doom-phonics/');
	await expect(page.locator('#status')).toHaveText('Ready', {timeout: 60000});
	const canvas = page.locator('canvas');

	const state = async() => page.evaluate(() => window.previewSession?.doomState);
	await expect.poll(async() => (await state())?.menu?.screen).toBe('title');
	await canvas.focus();
	await page.keyboard.press('Enter');
	await expect.poll(async() => (await state()).menu.screen).toBe('skill');
	await page.keyboard.press('Enter');
	await expect.poll(async() => (await state()).menu.open).toBe(false);
	await expect.poll(async() => (await state()).wipe.active).toBe(false);

	const collect = async names => {
		const code = await page.evaluate(async names => previewSession.php.run(String.raw`<?php
			$pickupActors = (new DoomPhonics\Adapter\WadDefinitions)->actors;
			$pickupFactory = new DoomPhonics\Adapter\ActorFactory($pickupActors);
			$pickupRegistry = $engine->entities;
			$pickupPlayer = $pickupRegistry->query(DoomPhonics\Game\PlayerState::class)[0];
			$pickupPose = $pickupRegistry->get($pickupPlayer, DoomPhonics\Component\Transform::class);
			$pickupRegistry->get($pickupPlayer, DoomPhonics\Game\PlayerState::class)->godMode = true;
			foreach(json_decode('${JSON.stringify(names)}', true) as $name)
			$pickupFactory->spawn($pickupRegistry, $pickupActors->named($name),
				['x' => $pickupPose->x, 'y' => $pickupPose->y],
				['floor' => $pickupPose->z, 'ceiling' => $pickupPose->z + 128]);
		`
		), names
		);

		expect(code).toBe(0);
	};

	await collect(['RocketLauncher']);
	await expect.poll(async() => (await state()).weapon).toBe('RocketLauncher');
	await expect.poll(async() => (await state()).ammo.RocketAmmo).toBe(2);
	await collect(['RocketBox', 'RocketBox', 'ClipBox', 'ShellBox', 'CellPack']);
	await expect.poll(async() => (await state()).ammo).toEqual({Clip: 100, Shell: 20, RocketAmmo: 12, Cell: 100});

	// Let the pickup flash finish before capturing the normal HUD palette.
	await page.waitForTimeout(1000);

	const screenshot = await canvas.screenshot({path: testInfo.outputPath('ammo-table.png')});

	const visibleNumbers = await page.evaluate(async png => {
		const response = await fetch('data:image/png;base64,' + png);
		const image = await createImageBitmap(await response.blob());
		const context = new OffscreenCanvas(image.width, image.height).getContext('2d');
		context.drawImage(image, 0, 0);

		const pixels = context.getImageData(0, 0, image.width, image.height).data;
		const scale = Math.min(image.width / 320, image.height / 200);
		const offsetX = (image.width - 320 * scale) / 2;
		const counts = [];

		for(const top of [173, 179, 185, 191])
		for(const right of [288, 314])
		{
			let yellowPixels = 0;

			for(let y = top; y < top + 6; y++)
			for(let x = right - 12; x < right; x++)
			{
				const px = Math.floor(offsetX + (x + .5) * scale);
				const py = Math.floor((y + .5) * scale);
				const index = (py * image.width + px) * 4;
				const [red, green, blue] = pixels.slice(index, index + 3);

				if(red > blue + 40 && green > blue + 20) yellowPixels++;
			}

			counts.push(yellowPixels);
		}

		return counts;
	}, screenshot.toString('base64'));

	// The STBAR background has gray labels; all eight numeric fields need yellow digit ink.
	for(const count of visibleNumbers) expect(count).toBeGreaterThan(0);

	expect(errors).toEqual([]);
	await page.click('#stop');
	await expect(page.locator('#status')).toHaveText('Stopped');
});
