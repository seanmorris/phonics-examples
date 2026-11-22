import {test, expect} from '@playwright/test';
import fs from 'node:fs/promises';
import path from 'node:path';

const road = {type: 'road', points: [[-46,1,-32],[-8,3,-12],[0,1,35]], width: 12, shoulder: 3};
const recipe = {preset: 'coast', seed: 'road-lab', worldSize: 256, spacing: 1, heightScale: 0, detailAmplitude: 0};
const fixture = {
	schema: 'phonics-landscape-sandbox'
	, version: 1
	, landscape: {format: 'terrain-phonics', version: 1, recipe, history: [[road]], cursor: 1, revision: 1}
	, settings: {waterLevel: -24, waterChoppiness: 0, azimuth: 110, elevation: 45, clock: false}
	, camera: {position: [0,4,32], yaw: 0, pitch: -.12}
};

test('a saved road can be inspected, reshaped and reopened on the terrain map', async({page}) => {
	const errors = [];
	page.on('pageerror', error => errors.push(error.message));

	page.on(
		'console'
		, msg => { if(/Fatal error|Uncaught RuntimeException/.test(msg.text())) errors.push(msg.text()); }
	);

	await page.setViewportSize({width: 1200, height: 700});
	await page.goto('/games/terrain-sandbox/');
	const canvas = page.locator('canvas[data-terrain-sandbox]');
	await expect(canvas).toHaveAttribute('data-terrain-sandbox', /"recipe":/, {timeout: 60_000});

	const status = () => canvas.evaluate(el => JSON.parse(el.dataset.terrainSandbox));

	async function load(document)
	{
		const generation = (await status()).generation;

		await page.locator('[data-file]').setInputFiles({
			name: 'road.json'
			, mimeType: 'application/json'
			, buffer: Buffer.from(JSON.stringify(document))
		});

		await expect.poll(async() => (await status()).generation).toBe(generation+1);
	}

	const tabs = page.locator('.terrain-panel');

	async function tab(name)
	{
		if(!await tabs.evaluate(el => el.open)) await tabs.locator('summary').first().click();

		await tabs.locator('[data-tab="' + name + '"]').click();
	}

	await canvas.focus();
	await page.keyboard.press('p');
	await expect.poll(async() => (await status()).paused).toBe(true);

	await load(fixture);
	const output = process.env.TERRAIN_ROAD_CAPTURES;

	if(output)
	{
		await fs.mkdir(output, {recursive: true});
		await expect.poll(async() => (await status()).renderer.pending).toBe(0);

		await canvas.screenshot({
			path: path.join(output, 'road-centerline.jpg')
			, style: '.terrain-panel,.terrain-hud{visibility:hidden!important}'
		});
	}

	const map = page.locator('[data-road-map]'), picker = page.locator('[data-road-picker]');
	const points = page.locator('[data-road-point]'), section = page.locator('[data-tab-panel=roads]');
	await tab('roads');
	await expect(picker.locator('option')).toHaveCount(2);
	await picker.selectOption('0');
	await expect(points.locator('option')).toHaveCount(3);
	await expect(map).toHaveAttribute('data-chart', 'ready');
	await expect(section.locator('[data-road-grade]')).toContainText('Maximum sampled grade');

	const scale = section.locator('[data-map-scale]'), original = await scale.innerText();
	await section.getByRole('button', {name: 'Zoom in'}).click();
	await expect(scale).not.toHaveText(original);
	await section.getByRole('button', {name: 'Fit road'}).click();
	await expect(scale).toHaveText(original);


	await points.selectOption({value: '1'});
	await expect(section.locator('[data-road-field=x]')).toHaveValue('-8');
	await section.getByRole('button', {name: 'Whole map'}).click();
	await map.scrollIntoViewIfNeeded();
	const chart = await map.boundingBox();
	const unit = chart.width / recipe.worldSize;
	const x = chart.x + chart.width/2 - 8*unit, y = chart.y + chart.height/2 - 12*unit;

	await page.mouse.move(x, y);
	await page.mouse.down();
	await page.mouse.move(x+4*unit, y+2*unit, {steps: 3});
	await page.mouse.up();
	await expect.poll(async() => Number(await section.locator('[data-road-field=x]').inputValue())).toBeCloseTo(-4, 0);

	await section.locator('summary').filter({hasText: /curve handles/i}).click();
	const dy = section.locator('[data-road-field=dy]');
	await dy.fill('2.5');
	await dy.press('Tab');
	await expect(section.locator('[data-point-action=auto]')).toBeEnabled();

	await section.getByRole('button', {name: 'Split curve'}).click();
	await expect(points.locator('option')).toHaveCount(4);

	const altitude = section.locator('[data-road-field=y]');
	await altitude.fill('5.25');
	await altitude.press('Tab');

	await section.getByRole('button', {name: 'Apply road'}).click();
	await expect.poll(async() => (await status()).revision).toBe(2);
	await expect(picker.locator('option:checked')).toContainText('4 points');


	if(output)
	{
		await section.locator('[data-map=fit]').click();
		await map.scrollIntoViewIfNeeded();
		await page.screenshot({path: path.join(output, 'road-editor.jpg')});
	}


	await tab('landscape');
	const download = page.waitForEvent('download');
	await page.locator('[data-action=save]').click();
	const saved = JSON.parse(await fs.readFile(await (await download).path(), 'utf8'));
	expect(saved.landscape.version).toBe(2);
	expect(saved.landscape.history.at(-1)[0].replaceAt).toBe(0);
	expect(saved.landscape.history.at(-1)[0].points[2][1]).toBe(5.25);
	expect(saved.landscape.history.at(-1)[0].handles[1].out).toBeDefined();

	await load(saved);
	await tab('roads');
	await picker.selectOption('0');
	await expect(points.locator('option')).toHaveCount(4);

	await picker.selectOption('');
	await expect(points.locator('option')).toHaveCount(0);
	await section.getByRole('button', {name: 'Place points'}).click();

	const box = await map.boundingBox();
	await map.click({position: {x: .51*box.width,y: .29*box.height}});
	await expect(points.locator('option')).toHaveCount(1);
	await map.click({position: {x: .66*box.width,y: .45*box.height}});
	await expect(points.locator('option')).toHaveCount(2);

	await section.getByRole('button', {name: 'Apply road'}).click();
	await expect(picker.locator('option')).toHaveCount(3);

	await tab('shape');
	await page.locator('[data-action=undo]').click();
	await expect.poll(async() => (await status()).revision).toBe(4);
	await tab('roads');
	await expect(picker.locator('option')).toHaveCount(2);

	expect(errors).toEqual([]);
});
