import {test, expect} from '@playwright/test';
import fs from 'node:fs/promises';

test('distant portal walls retain the submerged observer color exactly once', async({page}, testInfo) => {
	test.setTimeout(180000);
	const errors = [];
	page.on('pageerror', error => errors.push(error.message));
	page.on('console', message => { if(message.type() === 'error') errors.push(message.text()); });

	await page.goto('/engine/packages/doom-phonics/demo/seeded-navigation.html');
	const report = JSON.parse(await page.locator('#data').textContent());
	await page.locator('#play').click();
	await page.locator('.game-embed-poster').click();
	await expect(page.locator('#status')).toHaveText('Ready', {timeout: 60000});
	await expect.poll(() => page.evaluate(() => previewSession.doomState?.menu?.screen)).toBe('title');

	await page.locator('canvas').focus();
	await page.keyboard.press('Enter');
	await expect.poll(() => page.evaluate(() => previewSession.doomState.menu.screen)).toBe('skill');
	await page.keyboard.press('Enter');
	await expect.poll(() => page.evaluate(() => previewSession.doomState.menu.open)).toBe(false);
	await expect.poll(() => page.evaluate(() => previewSession.doomState.wipe.active), {timeout: 30000}).toBe(false);

	const run = source => page.evaluate(async source => {
		let result;
		const runtime = await previewSession.php.binary;

		runtime.waterLightProbe = json => { result = JSON.parse(String(json)); };

		const code = await previewSession.php.run('<?php ' + source);

		if(code !== 0) throw new Error(`Water lighting probe exited with ${code}`);

		return result;
	}, source
	);

	await run(String.raw`
		$engine->userPaused=true; $engine->setInputHandler(static fn()=>true);
		$content=(new ReflectionProperty($game,'lease'))->getValue($game)->context->content();
		$level=$content->level; $renderer=$content->renderer;
		$player=$engine->entities->query(DoomPhonics\Game\PlayerState::class)[0];
		$pose=$engine->entities->get($player,DoomPhonics\Component\Transform::class);
		$view=$engine->entities->get($player,DoomPhonics\Component\ViewInput::class);
		$engine->entities->get($player,DoomPhonics\Component\Body::class)->grounded=false;
		$readViews=static function() use($renderer,$engine,$pose,$view,$player) {
			$renderer->renderCameraPortals($engine->entities,$pose->x,$pose->y,$pose->z,$pose->angle,$view->pitch,$player,0,1);
			$count=(new ReflectionProperty($renderer,'cameraPortalTargetCursor'))->getValue($renderer);
			$targets=(new ReflectionProperty($renderer,'cameraPortalTargets'))->getValue($renderer);
			$parent=glGetIntegerv(GL_FRAMEBUFFER_BINDING); $samples=[];
			try {
				foreach(array_slice($targets,0,$count) as $target) {
					$fbo=(new ReflectionProperty($target,'framebuffer'))->getValue($target)[0];
					glBindFramebuffer(GL_FRAMEBUFFER,$fbo);
					$samples[]=base64_encode(glReadPixels(intdiv($target->width,2)-32,intdiv($target->height,2)-32,64,64,GL_RGBA,GL_UNSIGNED_BYTE));
				}
			} finally { glBindFramebuffer(GL_FRAMEBUFFER,$parent); }
			return $samples;
		};
	`
	);

	for(const name of ['../../tools/PlayerWalk'
		, '../../tools/RouteTraversal'
		, '../fixtures/LayeredWalkthrough'
		, '../fixtures/SeededWalkthrough'
	]){
		const source = await fs.readFile(new URL(`${name}.php`, import.meta.url), 'utf8');
		await run(source.replace(/^<\?php\s*/, '').replace(/^require_once .*;$/mg, ''));
	}

	const encoded = Buffer.from(JSON.stringify({...report, regions: report.sectorIds})).toString('base64');
	await run(`$r=json_decode(base64_decode('${encoded}'),true);`);

	let nestedViews = 0;

	for(const kind of ['gallery','shaft'])
	{
		const portal = report.verticalConnections.find(
			p => p.kind === kind && report.cells[report.sectorIds[p.to]].ceiling <= 0
		);

		const c = report.cells[report.sectorIds[portal.to]];

		await run(
			`$portal=json_decode('${JSON.stringify(
				portal
			)}',true); DoomPhonics\\Tests\\SeededWalkthrough::swimPortal($level,$r,$portal);`
		);

		for(const distance of [256,32])
		{
			await run(`
				$pose->x=$pose->previousX=${c.center[0]-distance}; $pose->y=$pose->previousY=${c.center[1]};
				$pose->z=$pose->previousZ=${c.ceiling-96-41}; $pose->angle=0; $view->setPitch(${Math.atan2(
					96
					, distance
				)});
			`
			);

			await page.evaluate(async() => { for(let i = 0; i < 8; i++) await new Promise(requestAnimationFrame); });

			await page.locator('canvas').screenshot({path: testInfo.outputPath(`${kind}-${distance}-underwater.png`)});

			// Keep geometry and water surfaces unchanged. Only suppress the source
			// observer's blend to obtain an exact untinted reference for every capture.
			const result = await run(String.raw`
				$source=$level->sectorAt($pose->x,$pose->y);
				$submerged=$level->waterSurface($pose->x,$pose->y,$pose->z+41,.01)!==null;
				$wet=$readViews(); $saved=$source->heightTransfer;
				$roots=count((new ReflectionProperty($renderer,'cameraPortalViews'))->getValue($renderer));
				try {
					$source->heightTransfer=new DoomPhonics\Game\HeightTransfer($source,16,[]);
					$clear=$readViews();
				} finally { $source->heightTransfer=$saved; }
				(vrzno_env('waterLightProbe'))(json_encode(compact('submerged','wet','clear','roots')));
			`
			);

			expect(result.submerged).toBe(true);
			expect(result.wet.length).toBeGreaterThan(0);
			expect(result.clear.length).toBe(result.wet.length);
			nestedViews = Math.max(nestedViews, result.wet.length - result.roots);
			let sampled = 0, mismatched = 0;

			for(let target = 0; target < result.wet.length; target++)
			{
				const wet = Buffer.from(result.wet[target], 'base64');
				const clear = Buffer.from(result.clear[target], 'base64');

				for(let i = 0; i < clear.length; i += 4)
				{
					// Unrendered framebuffer pixels and exhausted portal views stay black.
					if(clear[i]+clear[i+1]+clear[i+2] < 12) continue;

					sampled++;

					if([0,.31,.65].some(
						(tint, channel) => Math.abs(wet[i+channel]-(clear[i+channel]*.65+tint*255*.35)) > 3
					))
					mismatched++;
				}
			}

			await testInfo.attach(`${kind}-${distance}-lighting`, {
				body: JSON.stringify({roots: result.roots, captures: result.wet.length, sampled, mismatched})
				, contentType: 'application/json'
			});

			expect(sampled, `${kind} ${distance}: visible captured geometry`).toBeGreaterThan(100);

			expect(
				mismatched/sampled
				, `${kind} ${distance}: one source water blend throughout portal captures`
			).toBeLessThan(.02);
		}
	}

	expect(nestedViews).toBeGreaterThan(0);
	expect(await run(`(vrzno_env('waterLightProbe'))(json_encode(glGetError()));`)).toBe(0);
	expect(errors).toEqual([]);
});
