import {test, expect} from '@playwright/test';

test(
	'POOL3D renders stacked solids, linked rooms and repeatable draining liquid in the browser'
	, async({page}, testInfo) => {
		test.setTimeout(120000);
		const errors = [];
		page.on('pageerror', error => errors.push(error.message));

		page.on('console', message => {
			if(message.type() === 'error') errors.push(message.text());
		});

		await page.goto('/games/doom-phonics/?world=/engine/packages/doom-phonics/demo/pool-3d.phonics-world.json');
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
		expect((await state()).map).toBe('POOL3D');
		expect((await state()).unsupportedSpecials).toEqual([]);

		const run = async source => page.evaluate(async source => {
			const runtime = await previewSession.php.binary;
			let result = null;

			runtime.poolProbe = json => { result = JSON.parse(String(json)); };

			const code = await previewSession.php.run('<?php ' + source);

			if(code !== 0) throw new Error(`Pool probe exited with ${code}`);

			return result;
		}, source
		);

		await run(String.raw`
		$engine->userPaused = true;
		$engine->setInputHandler(static fn() => true);
		$poolContent = (new ReflectionProperty($game, 'lease'))->getValue($game)->context->content();
		$poolLevel = $poolContent->level;
		$poolPlayer = $engine->entities->query(DoomPhonics\Game\PlayerState::class)[0];
		$poolPose = $engine->entities->get($poolPlayer, DoomPhonics\Component\Transform::class);
		$poolView = $engine->entities->get($poolPlayer, DoomPhonics\Component\ViewInput::class);
		$poolPose->x = $poolPose->previousX = 320;
		$poolPose->y = $poolPose->previousY = 1056;
		$poolPose->z = $poolPose->previousZ = 120;
		$poolPose->angle = -1.1;
		$poolView->setPitch(-.45);
		$poolSpecials = new DoomPhonics\Game\LineSpecials($poolLevel);
		$poolWater = $poolLevel->sectorAt(704,704)->volumes[0];
		$poolPlatform = $poolLevel->sectorAt(704,704)->volumes[1];
	`
		);

		const probe = String.raw`
		(vrzno_env('poolProbe'))(json_encode([
			'water' => $poolWater->top(704,704),
			'platform' => $poolPlatform->top(704,704),
			'swimming' => $poolLevel->sectorAt(704,704)->waterSurface(704,704,-80,56) !== null,
			'glError' => glGetError()
		]));
	`;

		await expect.poll(async() => (await state()).position[2]).toBe(120);
		await page.waitForTimeout(1500);

		expect(await run(String.raw`
		$poolAtlas = (new ReflectionProperty($poolContent->renderer, 'atlas'))->getValue($poolContent->renderer);
		(vrzno_env('poolProbe'))(json_encode($poolAtlas->id('flat','P3WATER')));
	`
		)).toBeGreaterThanOrEqual(0);

		const blueCoverage = async screenshot => page.evaluate(async base64 => {
			const bytes = Uint8Array.from(atob(base64), character => character.charCodeAt(0));
			const bitmap = await createImageBitmap(new Blob([bytes], {type: 'image/png'}));
			const surface = new OffscreenCanvas(bitmap.width, bitmap.height);
			const context = surface.getContext('2d');
			context.drawImage(bitmap, 0, 0);
			bitmap.close();
			const {data} = context.getImageData(0, 0, surface.width, Math.floor(surface.height * .8));
			let blue = 0;

			for(let i = 0; i < data.length; i += 4)
			if(data[i + 2] > data[i] + 20 && data[i + 2] > data[i + 1] + 20) blue++;

			return blue / (data.length / 4);
		}, screenshot.toString('base64')
		);

		const full = await page.locator('canvas').screenshot({path: testInfo.outputPath('pool-full.png')});
		const fullBlue = await blueCoverage(full);
		expect(fullBlue).toBeGreaterThan(.01);
		expect(await run(probe)).toEqual({water: 0, platform: 80, swimming: true, glError: 0});

		const overview = String.raw`
		$poolPose->x = $poolPose->previousX = 320;
		$poolPose->y = $poolPose->previousY = 1056;
		$poolPose->z = $poolPose->previousZ = 120;
		$poolPose->angle = -1.1;
		$poolView->setPitch(-.45);
	`;

		const galleryView = String.raw`
		$poolPose->x = $poolPose->previousX = 576;
		$poolPose->y = $poolPose->previousY = 2624;
		$poolPose->z = $poolPose->previousZ = 320;
		$poolPose->angle = -M_PI / 2;
		$poolView->setPitch(-.65);
	`;

		const topView = String.raw`
		$poolPose->x = $poolPose->previousX = 576;
		$poolPose->y = $poolPose->previousY = 4112;
		$poolPose->z = $poolPose->previousZ = 576;
		$poolPose->angle = -M_PI / 2;
		$poolView->setPitch(-.65);
	`;

		await run(String.raw`
		$poolPose->x = $poolPose->previousX = 576;
		$poolPose->y = $poolPose->previousY = 896;
		$poolPose->z = $poolPose->previousZ = 32;
		$poolPose->angle = -M_PI / 2;
		$poolView->setPitch(.8);
	`
		);

		await page.waitForTimeout(500);
		await page.locator('canvas').screenshot({path: testInfo.outputPath('pool-ceiling-portal.png')});
		expect((await run(probe)).glError).toBe(0);

		expect(
			await run("(vrzno_env('poolProbe'))(json_encode($poolContent->renderer->cameraPortalViewsRendered));")
		).toBe(2);

		await run(galleryView);
		await expect.poll(async() => (await state()).position[2]).toBe(320);
		await page.waitForTimeout(500);

		const canvas = page.locator('canvas');
		const galleryFull = await canvas.screenshot({path: testInfo.outputPath('pool-gallery-full.png')});

		const galleryBlue = await blueCoverage(galleryFull);
		expect(galleryBlue).toBeGreaterThan(.01);
		expect((await run(probe)).glError).toBe(0);

		expect(
			await run("(vrzno_env('poolProbe'))(json_encode($poolContent->renderer->cameraPortalViewsRendered));")
		).toBe(1);

		await run(topView);
		await expect.poll(async() => (await state()).position[2]).toBe(576);
		await page.waitForTimeout(500);
		const topFull = await canvas.screenshot({path: testInfo.outputPath('pool-top-full.png')});
		const topBlue = await blueCoverage(topFull);
		expect(topBlue).toBeGreaterThan(.01);
		expect((await run(probe)).glError).toBe(0);

		expect(
			await run("(vrzno_env('poolProbe'))(json_encode($poolContent->renderer->cameraPortalViewsRendered));")
		).toBe(2);

		await run(overview);

		for(let cycle = 0; cycle < 2; cycle++)
		{
			await run(String.raw`
			if(!$poolSpecials->use(new DoomPhonics\Component\Transform(320,1120,16,M_PI / 2), new DoomPhonics\Game\PlayerState))
			throw new RuntimeException('Drain switch did not activate');
			for($i = 0; $i < 110; $i++) $poolSpecials->update(1 / 35);
		`
			);

			await page.waitForTimeout(500);
			expect(await run(probe)).toEqual({water: -192, platform: 80, swimming: false, glError: 0});

			if(cycle === 0)
			{
				const drained = await canvas.screenshot({path: testInfo.outputPath('pool-drained.png')});

				expect(fullBlue - await blueCoverage(drained)).toBeGreaterThan(.01);
				await run(galleryView);
				await page.waitForTimeout(500);

				const drainedPath = testInfo.outputPath('pool-gallery-drained.png');
				const galleryDrained = await canvas.screenshot({path: drainedPath});

				expect(galleryBlue - await blueCoverage(galleryDrained)).toBeGreaterThan(.01);
				await run(topView);
				await page.waitForTimeout(500);
				const topPath = testInfo.outputPath('pool-top-drained.png');
				const topDrained = await canvas.screenshot({path: topPath});
				expect(topBlue - await blueCoverage(topDrained)).toBeGreaterThan(.01);

				await run(String.raw`
				$poolPose->x = $poolPose->previousX = 960;
				$poolPose->y = $poolPose->previousY = 576;
				$poolPose->z = $poolPose->previousZ = -192;
				$poolPose->angle = M_PI;
				$poolView->setPitch(.1);
			`
				);

				await expect.poll(async() => (await state()).position[2]).toBe(-192);
				await page.waitForTimeout(500);
				await page.locator('canvas').screenshot({path: testInfo.outputPath('pool-stairs.png')});
				expect((await run(probe)).glError).toBe(0);

				await run(overview);
			}

			await run(String.raw`
			if(!$poolSpecials->use(new DoomPhonics\Component\Transform(832,1120,16,M_PI / 2), new DoomPhonics\Game\PlayerState))
			throw new RuntimeException('Refill switch did not activate');
			for($i = 0; $i < 110; $i++) $poolSpecials->update(1 / 35);
		`
			);

			await page.waitForTimeout(500);
			expect(await run(probe)).toEqual({water: 0, platform: 80, swimming: true, glError: 0});
		}

		await run(String.raw`
		$poolPose->x = $poolPose->previousX = 704;
		$poolPose->y = $poolPose->previousY = 896;
		$poolPose->z = $poolPose->previousZ = -100;
		$poolPose->angle = -M_PI / 2;
		$poolView->setPitch(.25);
	`
		);

		await expect.poll(async() => (await state()).position[2]).toBe(-100);
		await page.waitForTimeout(1000);
		await page.locator('canvas').screenshot({path: testInfo.outputPath('pool-submerged.png')});
		expect((await run(probe)).glError).toBe(0);

		// A liquid bottom flush with the floor must look exactly like a buried bottom.
		// Lowering just this target sector must then expose it, without rebuilding the mesh.
		await run(String.raw`
		$poolPose->x = $poolPose->previousX = 832;
		$poolPose->y = $poolPose->previousY = 832;
		$poolPose->z = $poolPose->previousZ = -184;
		$poolView->setPitch(-1.1);
		$poolBottomSector = $poolLevel->sectorAt(832,832);
	`
		);

		await expect.poll(async() => (await state()).position[2]).toBe(-184);
		await page.waitForTimeout(1000);

		const canvasBox = await page.locator('canvas').boundingBox();

		const floorShot = name => page.screenshot({
			path: testInfo.outputPath(name)
			, clip: {
				x: canvasBox.x + canvasBox.width * .35
				, y: canvasBox.y + canvasBox.height * .3
				, width: canvasBox.width * .3
				, height: canvasBox.height * .3
			}
		});

		const floorDifference = async(a, b) => page.evaluate(async images => {
			const pixels = [];

			for(const base64 of images)
			{
				const bytes = Uint8Array.from(atob(base64), character => character.charCodeAt(0));
				const bitmap = await createImageBitmap(new Blob([bytes], {type: 'image/png'}));
				const surface = new OffscreenCanvas(bitmap.width, bitmap.height);
				const context = surface.getContext('2d');
				context.drawImage(bitmap, 0, 0);
				bitmap.close();
				pixels.push(context.getImageData(0, 0, surface.width, surface.height).data);
			}

			let changed = 0;

			for(let i = 0; i < pixels[0].length; i += 4)
			if(Math.abs(pixels[0][i] - pixels[1][i]) + Math.abs(pixels[0][i + 1] - pixels[1][i + 1])
				+ Math.abs(pixels[0][i + 2] - pixels[1][i + 2]) > 20
			) changed++;

			return changed / (pixels[0].length / 4);
		}, [a.toString('base64'), b.toString('base64')]
		);

		const flush = await floorShot('pool-bottom-flush.png');
		await run('$poolWater->model->floor = $poolWater->model->previousFloor = -224;');
		await page.waitForTimeout(300);
		const buried = await floorShot('pool-bottom-buried.png');
		expect(await floorDifference(flush, buried)).toBeLessThan(.005);

		await run(String.raw`
		$poolWater->model->floor = $poolWater->model->previousFloor = -192;
		$poolBottomSector->floor = $poolBottomSector->previousFloor = -224;
	`
		);

		await page.waitForTimeout(300);
		const exposed = await floorShot('pool-bottom-exposed.png');
		expect(await floorDifference(flush, exposed)).toBeGreaterThan(.05);
		expect((await run(probe)).glError).toBe(0);
		expect(errors).toEqual([]);
		await page.click('#stop');
		await expect(page.locator('#status')).toHaveText('Stopped');
	}
);
