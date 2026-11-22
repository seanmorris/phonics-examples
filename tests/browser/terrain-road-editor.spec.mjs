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
	test.setTimeout(270_000);
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
		await expect.poll(async() => (await status()).renderer.pending, {timeout: 90_000}).toBe(0);

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

	// The first capture can still be the full extent from opening the tab.
	const captureSpan = () => map.evaluate(el => Number(el.dataset.chartSpan));
	await expect.poll(captureSpan).toBeLessThan(recipe.worldSize);
	const fitted = await captureSpan();
	await section.getByRole('button', {name: 'Zoom in'}).click();
	await expect.poll(captureSpan).toBeCloseTo(fitted/2, 2);
	await section.getByRole('button', {name: 'Fit road'}).click();
	await expect.poll(captureSpan).toBeCloseTo(fitted, 2);


	await points.selectOption({value: '1'});
	await expect(section.locator('[data-road-field=x]')).toHaveValue('-8');
	await section.getByRole('button', {name: 'Whole map'}).click();
	await map.scrollIntoViewIfNeeded();
	const chart = await map.boundingBox();
	const unit = Math.min(chart.width, chart.height) / recipe.worldSize;
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

	const rebuilds = async() => (await status()).renderer.roadOverlayBuilds;
	const initialBuilds = await rebuilds();
	await section.getByRole('button', {name: 'Apply road'}).click();
	await expect(picker.locator('option')).toHaveCount(3);
	await expect.poll(rebuilds).toBe(initialBuilds+1);

	// Change only the new road's elevation. Its neighbor retains its GPU overlay.
	await altitude.fill('4.5');
	await altitude.press('Tab');
	await section.getByRole('button', {name: 'Apply road'}).click();
	await expect.poll(rebuilds).toBe(initialBuilds+2);

	await tab('shape');
	await page.locator('[data-action=undo]').click();
	await expect.poll(async() => (await status()).revision).toBe(5);
	await expect.poll(async() => (await status()).preparing).toBe(false);
	await page.locator('[data-action=undo]').click();
	await expect.poll(async() => (await status()).revision).toBe(6);
	await tab('roads');
	await expect(picker.locator('option')).toHaveCount(2);

	expect(errors).toEqual([]);
});

test('the canyon remains legible at full extent in the Roads editor', async({page}) => {
	await page.setViewportSize({width: 1150,height: 760});
	await page.goto('/games/terrain-sandbox/');
	const canvas = page.locator('canvas[data-terrain-sandbox]');
	await expect(canvas).toHaveAttribute('data-terrain-sandbox', /"recipe":/, {timeout: 60_000});
	await canvas.focus(); await page.keyboard.press('p');
	await page.locator('[name=preset]').selectOption('canyon');
	await page.locator('[data-action=generate]').click();
	await expect.poll(() => canvas.evaluate(el => {
		const {recipe, renderer} = JSON.parse(el.dataset.terrainSandbox);
		return recipe.preset === 'canyon' && !renderer.pending;
	}), {timeout: 90_000}).toBe(true);
	await page.getByRole('tab', {name: 'Roads',exact: true}).click();
	const map = page.locator('[data-road-map]');
	await expect(map).toHaveAttribute('data-chart', 'ready', {timeout: 90_000});
	await page.locator('[data-map=world]').click();
	await expect(map).toHaveAttribute('data-chart-span', '32768');
	const edgeFraction = () => map.evaluate(el => {
		// Compare the same ground distance regardless of editor size and device pixel ratio.
		const chart = document.createElement('canvas'), size = 240, side = Math.min(el.width, el.height);
		chart.width = chart.height = size;
		const ctx = chart.getContext('2d');
		ctx.drawImage(el, (el.width-side)/2, (el.height-side)/2, side, side, 0, 0, size, size);
		const ink = ctx.getImageData(0,0,size,size).data;
		let edges = 0, samples = 0;
		for(let y=16; y<size-16; y++)
		for(let x=16; x<size-16; x++) {
			const i = (size*y+x)*4;
			const delta = Math.abs(ink[i]-ink[i+4])+Math.abs(ink[i+1]-ink[i+5])+Math.abs(ink[i+2]-ink[i+6]);
			if(delta>30) edges++;
			samples++;
		}
		return edges/samples;
	});
	await expect.poll(edgeFraction).toBeGreaterThan(.03);
	if(process.env.TERRAIN_ROAD_CAPTURES) {
		await fs.mkdir(process.env.TERRAIN_ROAD_CAPTURES, {recursive: true});
		await map.screenshot({path: path.join(process.env.TERRAIN_ROAD_CAPTURES, 'canyon-whole-map.png')});
	}
});

test('draw a manual lap circuit with the editor controls', async({page}) => {
	await page.setViewportSize({width: 1150,height: 760});
	await page.goto('/games/terrain-sandbox/');
	const canvas = page.locator('canvas[data-terrain-sandbox]');
	await expect(canvas).toHaveAttribute('data-terrain-sandbox', /"recipe":/, {timeout: 60_000});
	const doc = structuredClone(fixture);
	doc.landscape = {...doc.landscape,recipe: {...recipe,worldSize: 2048},history: [],cursor: 0,revision: 0};
	await page.locator('[data-file]').setInputFiles({name: 'manual.json',mimeType: 'application/json',buffer: Buffer.from(JSON.stringify(doc))});
	const status = () => canvas.evaluate(el => JSON.parse(el.dataset.terrainSandbox));
	await expect.poll(async() => (await status()).recipe.worldSize).toBe(2048);
	await canvas.focus(); await page.keyboard.press('p');
	await page.getByRole('tab', {name: 'Roads',exact: true}).click();
	const map = page.locator('[data-road-map]');
	await expect(map).toHaveAttribute('data-chart', 'ready');
	await page.locator('[data-map=world]').click();
	await expect(map).toHaveAttribute('data-chart-span','2048');
	await page.locator('[data-mode=add]').click();
	await map.scrollIntoViewIfNeeded();
	const frame = await map.boundingBox(), scale = Math.min(frame.width,frame.height)/2048;
	const points = [[-270,-195],[270,-195],[270,195],[-270,195]];
	for(const [i,[x,z]] of points.entries()) {
		await map.click({position:{x:frame.width/2+x*scale,y:frame.height/2+z*scale}});
		await expect(page.locator('[data-road-point] option')).toHaveCount(i+1);
	}
	await page.getByRole('button',{name:'Close loop and create circuit'}).click();
	await expect.poll(async() => (await status()).circuit.active,{timeout:60_000}).toBe('dry');
	await expect(page.locator('[data-road-point] option')).toHaveCount(5);
	await expect(page.locator('[data-road-picker]')).toHaveValue('0');
	expect((await status()).course.gates.length).toBeGreaterThanOrEqual(8);
});

test('a 24 km spline applies and saves as one road through the editor', async({page}) => {
	test.setTimeout(180_000);
	await page.setViewportSize({width: 900, height: 540});
	await page.goto('/games/terrain-sandbox/');
	const canvas = page.locator('canvas[data-terrain-sandbox]');
	await expect(canvas).toHaveAttribute('data-terrain-sandbox', /"recipe":/, {timeout: 60_000});
	const status = () => canvas.evaluate(el => JSON.parse(el.dataset.terrainSandbox));
	const generation = (await status()).generation;
	await canvas.focus();
	await page.keyboard.press('p');
	await expect.poll(async() => (await status()).paused).toBe(true);

	const doc = structuredClone(fixture);
	doc.landscape.recipe = {...recipe, worldSize: 32768, spacing: 4};
	doc.landscape.history = [[{...road, points: [[-100,1,0],[100,1,0]]}]];
	doc.settings.quality = .5;
	await page.locator('[data-file]').setInputFiles({
		name: 'highway.json', mimeType: 'application/json', buffer: Buffer.from(JSON.stringify(doc))
	});
	await expect.poll(async() => (await status()).generation).toBe(generation+1);
	const closed = page.locator('.terrain-panel:not([open]) > summary');
	if(await closed.count()) await closed.click();
	await page.getByRole('tab', {name: 'Roads', exact: true}).click();
	const picker = page.locator('[data-road-picker]');
	await picker.selectOption('0');
	const x = page.locator('[data-road-field=x]'), point = page.locator('[data-road-point]');
	for(const [i, before, after] of [['0','-100','-12000'],['1','100','12000']]) {
		await point.selectOption({value: i});
		await expect(x).toHaveValue(before);
		await x.fill(after);
		await x.press('Tab');
	}
	await page.getByRole('button', {name: 'Apply road'}).click();
	await expect.poll(async() => (await status()).revision, {timeout: 90_000}).toBe(2);
	await expect(picker.locator('option')).toHaveCount(2); // New road and the single 24 km highway.
	await expect(picker).toHaveValue('0');
	await page.getByRole('tab', {name: 'Landscape', exact: true}).click();
	const download = page.waitForEvent('download');
	await page.locator('[data-action=save]').click();
	const saved = JSON.parse(await fs.readFile(await (await download).path(), 'utf8'));
	expect(saved.landscape.history.at(-1)).toHaveLength(1);
	expect(saved.landscape.history.at(-1)[0]).toMatchObject({replaceAt: 0, points: [[-12000,1,0],[12000,1,0]]});
});
