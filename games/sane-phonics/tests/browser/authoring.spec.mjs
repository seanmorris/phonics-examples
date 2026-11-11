import {test, expect} from '@playwright/test';
import fs from 'node:fs/promises';
import {externalAssets} from './external-assets.mjs';

test('placed templates activate enemies, monitors and object rings in an ordinary course', async ({page}) => {
	const errors = [];
	page.on('pageerror', error => errors.push(error.message));
	const map = JSON.parse(await fs.readFile(new URL('../../examples/assets/platformer.tmj', import.meta.url)));
	map.tilesets.push({firstgid: 1000, source: 'monitors.tsj'});

	map.layers.push({
		id: 100
		, name: 'Placed templates'
		, type: 'objectgroup'
		, objects: [
			{id: 1001, template: '../../templates/crab-meat.tj', x: 600, y: 448}
			, {id: 1002, template: '../../templates/triple-ring-monitor.tj', x: 360, y: 448}
			, {id: 1003, template: '../../templates/ring.tj', x: 320, y: 400}
		]
	});

	await externalAssets(page, {'examples/assets/platformer.tmj': JSON.stringify(map)});

	await page.route(
		'**/examples/assets/platformer.tmj'
		, route => route.fulfill({contentType: 'application/json', body: JSON.stringify(map)})
	);

	await page.goto('/sane-phonics/');
	await page.evaluate(() => window.bootEngine({intro: false, scene: 'platformer'}));
	await expect(page.locator('canvas')).toHaveAttribute('data-scene', 'platformer', {timeout: 60000});

	const counts = await page.evaluate(async () => {
		let output = '';

		const listener = event => output += event.detail.join('');
		enginePhp.addEventListener('output', listener);

		try
		{
			await enginePhp.run(`<?php
			$engine->userPaused = true;
			echo json_encode([
			 count($engine->entities->query(SanePhonics\\Gameplay\\CrabMeat::class)),
			 count($engine->entities->query(SanePhonics\\Gameplay\\Monitor::class)),
			 count($engine->entities->query(SanePhonics\\Gameplay\\Ring::class)),
			 $engine->entities->get($engine->entities->query(SanePhonics\\Gameplay\\RingMonitorEffect::class)[0], SanePhonics\\Gameplay\\RingMonitorEffect::class)->rings
			]);`
			);

			return JSON.parse(output);
		}
		finally
		{
			enginePhp.removeEventListener('output', listener);
		}
	});

	expect(counts).toEqual([1,1,24,30]);
	await page.screenshot({path: '/tmp/sane-placed-templates.png'});
	expect(errors).toEqual([]);
	await page.evaluate(() => window.stopEngine?.());
});
