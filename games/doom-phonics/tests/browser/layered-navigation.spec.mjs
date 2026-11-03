import {test, expect} from '@playwright/test';
import fs from 'node:fs/promises';

test(
	'LAYERS realizes the flood-dependent route in browser PHP and renders its gallery portal'
	, async({page}, testInfo) => {
		test.setTimeout(120000);
		const errors = [];
		page.on('pageerror', error => errors.push(error.message));
		page.on('console', message => { if(message.type() === 'error') errors.push(message.text()); });

		await page.goto(
			'/games/doom-phonics/?world=/engine/packages/doom-phonics/demo/layered-navigation.phonics-world.json'
		);

		await page.locator('.game-embed-poster').click();
		await expect(page.locator('#status')).toHaveText('Ready', {timeout: 60000});

		const state = () => page.evaluate(() => previewSession.doomState);
		await expect.poll(async() => (await state())?.menu?.screen).toBe('title');
		await page.locator('canvas').focus();
		await page.keyboard.press('Enter');
		await expect.poll(async() => (await state()).menu.screen).toBe('skill');
		await page.keyboard.press('Enter');
		await expect.poll(async() => (await state()).menu.open).toBe(false);
		await expect.poll(async() => (await state()).wipe.active, {timeout: 30000}).toBe(false);
		expect((await state()).map).toBe('LAYERS');
		expect((await state()).unsupportedSpecials).toEqual([]);

		const run = source => page.evaluate(async source => {
			let result = null;
			const runtime = await previewSession.php.binary;

			runtime.layerProbe = json => { result = JSON.parse(String(json)); };

			const code = await previewSession.php.run('<?php ' + source);

			if(code !== 0) throw new Error(`Layer probe exited with ${code}`);

			return result;
		}, source
		).catch(error => { throw new Error(`${error.message}\n${errors.join('\n')}`); });

		await run(String.raw`
		$engine->userPaused = true;
		$engine->setInputHandler(static fn() => true);
		$layerContent = (new ReflectionProperty($game,'lease'))->getValue($game)->context->content();
		$layerLevel = $layerContent->level;
		$layerPlayer = $engine->entities->query(DoomPhonics\Game\PlayerState::class)[0];
		$layerPose = $engine->entities->get($layerPlayer,DoomPhonics\Component\Transform::class);
		$layerView = $engine->entities->get($layerPlayer,DoomPhonics\Component\ViewInput::class);
	`
		);

		for(const name of ['../../tools/PlayerWalk', '../fixtures/LayeredWalkthrough'])
		{
			const fixture = await fs.readFile(new URL(`${name}.php`, import.meta.url), 'utf8');
			await run(fixture.replace(/^<\?php\s*/, '').replace(/^require_once .*;$/mg, ''));
		}

		const checkpoints = await run(String.raw`
		$layerWalk = new DoomPhonics\Tests\LayeredWalkthrough($layerLevel);
		$layerRegions = ['pump' => 3,'cavern.lower' => 0,'cavern.upper' => 1,'building.entry' => 9,'upper.hall' => 27,'balcony' => 29];
		(vrzno_env('layerProbe'))(json_encode($layerWalk->run($layerRegions)));
	`
		);

		expect(checkpoints.ledge.z).toBe(0);
		expect(checkpoints.stairs.z).toBe(192);
		expect(checkpoints.balcony.sector).toBe(29);

		const basin = await run(String.raw`
		$wet = [];
		foreach($layerLevel->sectors as $sector)
		if($sector->waterSurface(0,0,-192,56) !== null) $wet[] = $sector->index;
		(vrzno_env('layerProbe'))(json_encode($wet));
	`
		);

		expect(basin).toEqual([0, 2, 3, 30, 31, 32, 33]);

		const crossings = await run(String.raw`
		(vrzno_env('layerProbe'))(json_encode(DoomPhonics\Tests\LayeredWalkthrough::portalCrossings($layerLevel)));
	`
		);

		for(const [name, trace] of Object.entries(crossings))
		{
			expect(trace.frames.map(frame => frame.sector), name).toContain(trace.destination);

			const verticalStates = [...new Set(trace.frames.map(frame => JSON.stringify([
				frame.z, frame.previousZ, frame.velocityZ, frame.grounded
			])))].map(value => JSON.parse(value));

			expect(verticalStates, name).toEqual(trace.airborne ? [[221, 224, -105, false]] : [[192, 192, 0, true]]);
		}

		await run(String.raw`
		$layerPose->x = $layerPose->previousX = 512;
		$layerPose->y = $layerPose->previousY = 320;
		$layerPose->z = $layerPose->previousZ = 0;
		$layerPose->angle = -M_PI / 2;
		$layerView->setPitch(-.45);
	`
		);

		await expect.poll(async() => (await state()).position[0]).toBe(512);
		await page.waitForTimeout(500);
		await page.locator('canvas').screenshot({path: testInfo.outputPath('layers-flooded-cavern.png')});

		for(const [name, x, angle] of [['west', 240, Math.PI], ['east', 784, 0]])
		{
			await run(`
			$layerPose->x = $layerPose->previousX = ${x};
			$layerPose->y = $layerPose->previousY = 128;
			$layerPose->angle = ${angle};
			$layerView->setPitch(0);
		`
			);

			await expect.poll(async() => (await state()).position[0]).toBe(x);
			await page.waitForTimeout(300);
			await page.locator('canvas').screenshot({path: testInfo.outputPath(`layers-${name}-wall-above-water.png`)});
		}

		await run(String.raw`
		$layerPose->x = $layerPose->previousX = 8384;
		$layerPose->y = $layerPose->previousY = 96;
		$layerPose->z = $layerPose->previousZ = 192;
		$layerPose->angle = M_PI / 2;
		$layerView->setPitch(-.65);
	`
		);

		await expect.poll(async() => (await state()).position[2]).toBe(192);
		await page.waitForTimeout(500);
		await page.locator('canvas').screenshot({path: testInfo.outputPath('layers-gallery.png')});

		const render = await run(String.raw`
		(vrzno_env('layerProbe'))(json_encode(['portals' => $layerContent->renderer->cameraPortalViewsRendered,'error' => glGetError()]));
	`
		);

		expect(render.portals).toBeGreaterThan(0);
		expect(render.error).toBe(0);
		expect(errors).toEqual([]);
		await page.click('#stop');
		await expect(page.locator('#status')).toHaveText('Stopped');
	}
);

test('navigation report exposes conditional routes and traps without starting a game', async({page}) => {
	await page.goto('/engine/packages/doom-phonics/demo/layered-navigation.html');
	await expect(page.locator('#verdict')).toContainText('46/46 regions reachable');
	await expect(page.locator('#route')).toContainText('fill: pump');

	await page.locator('#actions').uncheck();
	await expect(page.locator('#verdict')).toContainText('7/46 regions reachable');
	await page.locator('#start').selectOption('courtyard');
	await expect(page.locator('#traps')).toContainText('7 reachable states have no route back');
	await page.locator('#water').selectOption('full');
	await expect(page.locator('#traps')).toContainText('Every reachable state');
});
