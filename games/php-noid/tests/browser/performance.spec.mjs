import {test, expect} from '@playwright/test';
import fs from 'node:fs/promises';

test('sustained multiball and lasers keep rendering and fixed updates responsive', async ({page}, testInfo) => {
	await page.addInitScript(() => {
		const request = window.requestAnimationFrame.bind(window);
		window.collisionFrameProfile = [];
		let previous;

		window.requestAnimationFrame = callback => request(async time => {
			const start = performance.now();
			await callback(time);
			const duration = performance.now() - start;
			const raw = document.querySelector('canvas')?.dataset.phpNoid;

			if(!raw) return;

			const state = JSON.parse(raw);

			// Fixed-step accumulation can render between simulation ticks. Those are real
			// presented frames, so include zero-update frames in presentation-rate measurements.
			if(previous && state.phase === 'playing' && !state.paused)
			window.collisionFrameProfile.push({duration, updates: state.frames - previous.frames, time});

			previous = state;
		});
	});

	const errors = [];
	page.on('pageerror', error => errors.push(error.message));
	await page.goto('/php-noid/');
	await expect(page.locator('#status')).toHaveText('Ready', {timeout: 60000});
	await page.locator('[data-action="start"]').click();
	await expect(page.locator('.php-noid-title')).toBeHidden();

	await page.evaluate(() => {
		window.hudMutations = 0;
		window.hudObserver = new MutationObserver(records => { window.hudMutations += records.length; });

		hudObserver.observe(document.querySelector('.php-noid-hud'), {
			attributes: true
			, characterData: true
			, childList: true
			, subtree: true
		});
	});

	await page.evaluate(async () => {
		const result = await previewSession.php.run(String.raw`<?php
			$r=$field->registry();

			foreach($r->query(\PhpNoid\Component\Brick::class) as $id) {
				$brick=$r->get($id,\PhpNoid\Component\Brick::class);
				$brick->unbreakable=true; $brick->capsule='';
			}
			// Sustain the collision workload without losing balls or clearing the stage.
			$r->create(new \PhpNoid\Component\Brick(20,30,8,8,100000));
			$p=$r->get($field->paddle,\PhpNoid\Component\Paddle::class);
			$p->baseWidth=620;
			$field->power('split'); $field->power('laser'); $p->powers['laser']=120;
			// Keep the descending ships out of the outer laser lanes so an arbitrary sample
			// cannot lose its six-shot workload to a ship crossing the muzzle.
			for($i=0;$i<3;$i++) {
				$ship=new \PhpNoid\Component\Enemy(80+$i*220,240,'floater','copper',1000,100000);
				$ship->direction=0; $r->create($ship);
			}
		`
		);

		if(result) throw new Error('Failed to set up the sustained collision workload');
	});

	await page.locator('canvas').focus();
	await page.keyboard.down('Space');
	const reports = [];

	for(const fullscreen of [false, true])
	{
		if(fullscreen)
		{
			await page.keyboard.up('Space');
			await page.click('#fullscreen');
			await expect.poll(() => page.evaluate(() => !!document.fullscreenElement)).toBe(true);
			await page.keyboard.down('Space');
		}

		await page.waitForTimeout(1500);
		await page.evaluate(() => { window.collisionFrameProfile = []; window.hudMutations = 0; });

		await expect.poll(
			() => page.evaluate(() => collisionFrameProfile.length)
			, {timeout: 20000}
		).toBeGreaterThanOrEqual(240);

		const report = await page.evaluate(fullscreen => {
			const frames = collisionFrameProfile.slice(0, 240);
			const durations = frames.map(frame => frame.duration).sort((a, b) => a - b);
			const caps = frames.filter(frame => frame.updates >= 8).length;

			return {
				fullscreen
				, frames: frames.length
				, median_ms: durations[120]
				, p95_ms: durations[227]

				, fps: 239000 / (frames[239].time - frames[0].time)
				, capped_frames: caps
				, max_updates: Math.max(...frames.map(frame => frame.updates))
				, zero_update_frames: frames.filter(frame => frame.updates === 0).length
				, hud_mutations: hudMutations
			};
		}, fullscreen
		);

		reports.push(report);
		// The timer changes once per second, even when gameplay extends a power past its original duration.
		expect(report.hud_mutations).toBeLessThan(30);
		const name = `php-noid-frame-profile-${fullscreen ? 'fullscreen' : 'window'}`;
		const file = testInfo.outputPath(name + '.json');
		const samples = await page.evaluate(() => collisionFrameProfile.slice(0, 240));
		await fs.writeFile(file, JSON.stringify({report, samples}, null, 2));
		await testInfo.attach(name, {path: file, contentType: 'application/json'});

		// Shared CI runners retain measurements; enforce wall-clock budgets on an idle reference machine.
		if(process.env.PHONICS_PERF_BUDGET === '1')
		{
			expect(report.capped_frames).toBeLessThan(2);
			expect(report.p95_ms).toBeLessThan(16.7);
			expect(report.fps).toBeGreaterThan(55);
		}
	}

	await testInfo.attach(
		'php-noid-frame-profile'
		, {body: JSON.stringify(reports, null, 2), contentType: 'application/json'}
	);

	await page.evaluate(async () => {
		const result = await previewSession.php.run(String.raw`<?php
			$r=$field->registry();
			vrzno_env('canvas')->dataset->workload = json_encode([
				'balls'=>array_map(fn($id)=>$r->get($id,\PhpNoid\Component\Ball::class), $r->query(\PhpNoid\Component\Ball::class)),
				'shots'=>count($r->query(\PhpNoid\Component\Projectile::class)),
				'paddle'=>$r->get($field->paddle,\PhpNoid\Component\Paddle::class),
				'maxSteps'=>$engine->clock->maxSteps, 'step'=>$engine->clock->step
			]);
			if(count($r->query(\PhpNoid\Component\Ball::class)) !== 3
				|| count($r->query(\PhpNoid\Component\Projectile::class)) < 6
				|| $engine->clock->maxSteps !== 8 || $engine->clock->step !== 1/60)
			throw new \RuntimeException('Stress workload or fixed-step settings changed');
		`
		);

		if(result)
		throw new Error(
			'Sustained workload did not remain active: ' + document.querySelector('canvas').dataset.workload
		);
	});

	await page.keyboard.up('Space');
	await page.evaluate(() => hudObserver.disconnect());
	await page.evaluate(() => previewSession.dispose());
	await expect.poll(() => page.evaluate(() => !!document.fullscreenElement)).toBe(false);
	expect(errors).toEqual([]);
});
