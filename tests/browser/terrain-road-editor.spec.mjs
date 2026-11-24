import {test, expect} from '@playwright/test';
import {PNG} from 'pngjs';
import fs from 'node:fs/promises';
import path from 'node:path';

const road = {type: 'road', points: [[-46,1,-32],[-8,3,-12],[0,1,35]], width: 12, shoulder: 3};
const recipe = {preset: 'coast', seed: 'road-lab', worldSize: 256, spacing: 1, heightScale: 0, detailAmplitude: 0};
const fixture = {
	schema: 'phonics-landscape-sandbox'
	, version: 1
	, landscape: {format: 'terrain-phonics', version: 1, recipe, history: [[road]], cursor: 1, revision: 1}
	, settings: {waterLevel: -24, waterChoppiness: 0, azimuth: 110, elevation: 45, clock: false, quality: .5}
	, camera: {position: [0,4,32], yaw: 0, pitch: -.12}
};

test('road eyes, disclosure, deletion and undo are independent', async({page}) => {
	await page.setViewportSize({width: 800, height: 600});
	await page.goto('/games/terrain-sandbox/');
	const canvas = page.locator('canvas[data-terrain-sandbox]');
	const status = () => canvas.evaluate(el => JSON.parse(el.dataset.terrainSandbox));
	await expect(canvas).toHaveAttribute('data-terrain-sandbox', /"recipe":/, {timeout: 60_000});
	const arc = Array.from({length:7}, (_,i) => [60*(Math.cos(i*Math.PI/12)-1),0,60*Math.sin(i*Math.PI/12)]);
	const bend = {...road, points:[[0,0,-110],[0,0,-52],...arc,[-115,0,60]]};
	await page.locator('[data-file]').setInputFiles({
		name:'two-roads.json', mimeType:'application/json', buffer:Buffer.from(JSON.stringify({
			...fixture, landscape:{...fixture.landscape, history:[[bend,{...road,points:[[30,0,-30],[30,0,55]]}]]}
			, camera:{position:[0,5,-55],yaw:Math.PI,pitch:-.035}
			, course:{gates:[0,30].map((x,i) => ({role:'checkpoint',kind:'road',x,y:0,z:15,yaw:0,width:20,height:4,number:i+1}))}
		}))
	});
	await expect.poll(async()=>(await status()).recipe.seed).toBe('road-lab');
	await expect.poll(async()=>(await status()).renderer.gateDraws).toBe(2);
	await expect.poll(async()=>(await status()).renderer.curveSignsDrawn).toBeGreaterThan(0);
	await page.getByRole('tab',{name:'Roads',exact:true}).click();
	const first = page.locator('[data-road-layer="0"]'), second = page.locator('[data-road-layer="1"]');
	const eye = page.getByRole('button',{name:'Road 1 visibility',exact:true});
	await second.locator('summary').click();
	await expect(second).toHaveAttribute('open','');
	await eye.click();
	await expect(eye).toHaveAttribute('aria-pressed','false');
	await expect(first).not.toHaveAttribute('open','');
	await expect(second).toHaveAttribute('open','');
	const otherEye = page.getByRole('button',{name:'Road 2 visibility',exact:true});
	await expect.poll(async()=>(await status()).renderer.gateDraws).toBe(1);
	await expect.poll(async()=>(await status()).renderer.curveSigns).toBe(0);
	await otherEye.click();
	await expect.poll(async()=>(await status()).renderer.roadTextureBytes).toBe(0);
	await expect.poll(async()=>(await status()).renderer.gateDraws).toBe(0);
	expect((await status()).course.gates).toHaveLength(2);
	expect((await status()).revision).toBe(1);
	await second.locator(':scope > summary').click();
	await expect(second).not.toHaveAttribute('open','');
	await expect(otherEye).toHaveAttribute('aria-pressed','false');
	await otherEye.click();
	await expect.poll(async()=>(await status()).renderer.roadTextureBytes).toBeGreaterThan(0);
	await expect.poll(async()=>(await status()).renderer.gateDraws).toBe(1);
	await eye.click();
	await expect.poll(async()=>(await status()).renderer.gateDraws).toBe(2);
	await expect.poll(async()=>(await status()).renderer.curveSignsDrawn).toBeGreaterThan(0);
	await eye.click();
	await first.locator('summary').click();
	await first.getByRole('button',{name:'Delete road'}).click();
	await expect(first).toHaveCount(0);
	await expect(second).toBeVisible();
	expect((await status()).revision).toBe(2);
	await page.getByRole('tab',{name:'Shape',exact:true}).click();
	await page.locator('[data-action=undo]').click();
	await expect.poll(async()=>(await status()).revision).toBe(3);
	await page.getByRole('tab',{name:'Roads',exact:true}).click();
	await expect(eye).toHaveAttribute('aria-pressed','true');
	await expect(second).toBeVisible();
});

test('a saved road can be inspected, reshaped and reopened on the terrain map', async({page}) => {
	test.setTimeout(360_000);
	const errors = [];
	page.on('pageerror', error => errors.push(error.message));

	page.on(
		'console'
		, msg => { if(/Fatal error|Uncaught RuntimeException/.test(msg.text())) errors.push(msg.text()); }
	);

	await page.setViewportSize({width: 960, height: 640});
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

	const map = page.locator('[data-road-map]'), layers = page.locator('[data-road-layers]');
	const points = page.locator('[data-road-point]'), section = page.locator('[data-tab-panel=roads]');
	await tab('roads');
	await expect(layers.locator('[data-road-layer]')).toHaveCount(2);
	await layers.locator('[data-road-layer="0"] > summary').click();
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
	await expect.poll(async() => Number(await section.locator('[data-road-field=x]').inputValue())).toBeCloseTo(-8/.3048, 4);
	await section.getByRole('button', {name: 'Whole map'}).click();
	await map.scrollIntoViewIfNeeded();
	const chart = await map.boundingBox();
	const unit = Math.min(chart.width, chart.height) / recipe.worldSize;
	const camera = section.locator('[data-road-camera]');
	await expect(camera).toBeVisible();
	const center = await camera.boundingBox();
	expect(center.x + center.width/2).toBeCloseTo(chart.x + chart.width/2, 0);
	expect(center.y + center.height/2).toBeCloseTo(chart.y + chart.height/2 + 32*unit, 0);
	expect(await camera.evaluate(el => new DOMMatrix(getComputedStyle(el).transform).a)).toBeCloseTo(1);
	const stage = await canvas.boundingBox();
	await page.mouse.move(stage.x+25, stage.y+100);
	await page.mouse.down({button: 'right'});
	await page.mouse.move(stage.x+125, stage.y+100);
	await page.mouse.up({button: 'right'});
	await expect.poll(() => camera.evaluate(el => new DOMMatrix(getComputedStyle(el).transform).b)).toBeCloseTo(Math.sin(.3), 2);
	const x = chart.x + chart.width/2 - 8*unit, y = chart.y + chart.height/2 - 12*unit;

	await page.mouse.move(x, y);
	await page.mouse.down();
	await page.mouse.move(x+4*unit, y+2*unit, {steps: 3});
	await page.mouse.up();
	await expect.poll(async() => Number(await section.locator('[data-road-field=x]').inputValue())).toBeCloseTo(-4/.3048, 0);

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
	await expect(layers.locator('details[open] > summary').first()).toContainText('4 points');


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
	expect(saved.landscape.history.at(-1)[0].points[2][1]).toBeCloseTo(5.25*.3048, 9);
	expect(saved.landscape.history.at(-1)[0].handles[1].out).toBeDefined();

	await load(saved);
	await tab('roads');
	await layers.locator('[data-road-layer="0"] > summary').click();
	await expect(points.locator('option')).toHaveCount(4);

	await layers.locator('[data-road-layer="new"] > summary').click();
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
	await expect(layers.locator('[data-road-layer]')).toHaveCount(3);
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
	await expect(layers.locator('[data-road-layer]')).toHaveCount(2);

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
	test.setTimeout(180_000);
	await page.setViewportSize({width: 1150,height: 760});
	await page.goto('/games/terrain-sandbox/');
	const canvas = page.locator('canvas[data-terrain-sandbox]');
	await expect(canvas).toHaveAttribute('data-terrain-sandbox', /"recipe":/, {timeout: 60_000});
	const doc = structuredClone(fixture);
	// The west (closing) span crosses a ridge, with both of its hand-placed endpoints on the plain.
	const ridge = {type:'raise',x:-337,z:0,radius:125,amount:32};
	doc.settings.roadWidth = 24;
	doc.landscape = {...doc.landscape,recipe: {...recipe,worldSize: 2048},history: [[ridge]],cursor: 1,revision: 1};
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
	// The first span bows one sixteenth of the way away from the third point at t = 0.5.
	await map.click({position:{x:frame.width/2,y:frame.height/2-219.375*scale}});
	const point = page.locator('[data-road-point]');
	await expect(point.locator('option')).toHaveCount(5);
	await expect(point).toHaveValue('1');
	await expect(page.locator('[data-road-grade]')).toContainText('Ground spans are fitted on Apply');
	await page.getByRole('button',{name:'Close loop and create circuit'}).click();
	await expect.poll(async() => (await status()).circuit.active,{timeout:60_000}).toBe('dry');
	await expect.poll(() => point.locator('option').count()).toBeGreaterThan(6);
	await expect(page.locator('[data-road-layer="1"]')).toHaveAttribute('open','');
	expect((await status()).course.gates).toHaveLength(3);
	await page.getByText('Road dimensions',{exact:true}).click();
	const width = page.locator('[data-road-field=width]');
	for(const feet of [12,150]) {
		await width.fill(String(feet));
		await width.press('Tab');
		await page.getByRole('button', {name:'Apply road',exact:true}).click();
		await expect.poll(async() => Math.min(...(await status()).course.gates.map(gate => gate.width)),
			{timeout:60_000}).toBeCloseTo(feet*.3048+1.5,1);
		expect((await status()).circuit.active).toBe('dry');
	}

	await page.getByRole('tab', {name: 'Landscape', exact:true}).click();
	const download = page.waitForEvent('download');
	await page.locator('[data-action=save]').click();
	const saved = JSON.parse(await fs.readFile(await (await download).path(), 'utf8'));
	const graded = saved.landscape.history.at(-1)[0];
	expect(graded.width).toBeCloseTo(150*.3048);
	const openings = saved.course.gates.map(gate => gate.width);
	// The narrowest crossing is perpendicular. Gates across bends can be wider.
	expect(Math.min(...openings)).toBeCloseTo(graded.width + 1.5, 1);
	expect(graded.points.at(-1)).toEqual(graded.points[0]);
	expect(graded.points.some(([x,y,z]) => x < -270 && Math.abs(z) < 65 && y > 26)).toBe(true);
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
	const layers = page.locator('[data-road-layers]');
	await layers.locator('[data-road-layer="0"] > summary').click();
	const x = page.locator('[data-road-field=x]'), point = page.locator('[data-road-point]');
	for(const [i, before, after] of [['0','-100','-12000'],['1','100','12000']]) {
		await point.selectOption({value: i});
		await expect.poll(async() => Number(await x.inputValue())*.3048).toBeCloseTo(Number(before), 4);
		await x.fill(String(Number(after)/.3048));
		await x.press('Tab');
	}
	await page.getByRole('button', {name: 'Apply road'}).click();
	await expect.poll(async() => (await status()).revision, {timeout: 90_000}).toBe(2);
	await expect(layers.locator('[data-road-layer]')).toHaveCount(2); // New road and the single 24 km highway.
	await expect(layers.locator('[data-road-layer="0"]')).toHaveAttribute('open','');
	await page.getByRole('tab', {name: 'Landscape', exact: true}).click();
	const download = page.waitForEvent('download');
	await page.locator('[data-action=save]').click();
	const saved = JSON.parse(await fs.readFile(await (await download).path(), 'utf8'));
	expect(saved.landscape.history.at(-1)).toHaveLength(1);
	expect(saved.landscape.history.at(-1)[0]).toMatchObject({replaceAt: 0, points: [[-12000,1,0],[12000,1,0]]});
});


test('diagonal road edges stay continuous and only the markings glow in darkness', async({page}) => {
	await page.setViewportSize({width:800,height:550});
	await page.goto('/games/terrain-sandbox/');
	const canvas = page.locator('canvas[data-terrain-sandbox]');
	await expect(canvas).toHaveAttribute('data-terrain-sandbox', /"recipe":/, {timeout:60_000});
	const status = () => canvas.evaluate(el => el.terrainSandboxDebug.state);
	await canvas.focus();
	await page.keyboard.press('p');
	await expect.poll(async() => (await status()).paused).toBe(true);

	for(const night of [false,true]) {
		const angled = {...road,points:[[-10.2,0,-60],[11,0,60]],width:8,shoulder:.01};
		const document = {...fixture,
			landscape:{...fixture.landscape,history:[[angled]]},
			settings:{...fixture.settings,quality:1,elevation:night ? -28 : 45,skyLight:night ? 0 : 1,nightGlow:0},
			camera:{position:[0,18,0],yaw:0,pitch:-1.52},
		};
		const generation = (await status()).generation;
		await page.locator('[data-file]').setInputFiles({
			name:'diagonal-road.json',mimeType:'application/json',buffer:Buffer.from(JSON.stringify(document)),
		});
		await expect.poll(async() => (await status()).generation).toBe(generation+1);
		await expect.poll(async() => (await status()).renderer.pending,{timeout:70_000}).toBe(0);
		const {width,height,data} = PNG.sync.read(await canvas.screenshot({
			style:'.terrain-panel,.terrain-hud{visibility:hidden!important}',
		}));

		let dashedRows = 0, gapRows = 0;
		for(let y = Math.floor(height*.20); y < height*.80; y += 4) {
			const edges = [];
			// Separating the runs also checks that the road surface between them stays dark.
			let inStripe = false;
			for(let x = Math.floor(width*.2); x < width*.8; x++) {
				const i = 4*(y*width+x);
				const neutral = Math.max(data[i],data[i+1],data[i+2])-Math.min(data[i],data[i+1],data[i+2]);
				const white = night ? data[i]>=8 && neutral<=2 : data[i]>=190 && neutral<15;
				if(white && !inStripe) edges.push(x);
				inStripe = white;
			}
			expect(edges, 'two distinct white lines at row '+y).toHaveLength(2);
			if(!night) continue;
			const span = edges[1]-edges[0];
			const quarter = 4*(y*width+Math.round(edges[0]+.25*span));
			expect(data[quarter], 'unlit pavement').toBeLessThan(3);
			const stripe = 4*(y*width+edges[0]+2);
			expect(data[stripe], 'faint shoulder glow').toBeLessThan(25);
			let warm = false;
			for(let x = Math.round(edges[0]+.4*span); x < edges[0]+.6*span; x++) {
				const i = 4*(y*width+x);
				if(data[i]>8 && data[i]<25 && data[i]>data[i+2]*2) warm = true;
			}
			if(warm) dashedRows++;
			else gapRows++;
		}
		if(night) {
			expect(dashedRows).toBeGreaterThan(10);
			expect(gapRows).toBeGreaterThan(10);
		}
	}
});

test('a fork carries dashed centerlines in both directions and shoulders stay at the pavement edge', async({page}) => {
	test.setTimeout(150_000);
	await page.setViewportSize({width:900,height:560});
	await page.goto('/games/terrain-sandbox/');
	const canvas=page.locator('canvas[data-terrain-sandbox]');
	await expect(canvas).toHaveAttribute('data-terrain-sandbox', /"recipe":/, {timeout:60_000});
	const origin=[.35,0,-.4], eye=[8,48,2], pitch=-1.52;
	const endpoints=[[-110,0,-4],[110,0,-32],[110,0,29]];
	const roads=endpoints.map((end,i)=>({...road,width:20,shoulder:14,crown:0,
		points:i===1 ? [origin,end] : [end,origin]}));
	const doc={...fixture,landscape:{...fixture.landscape,history:[roads],recipe:{...recipe,seed:'fork-stripes'}},
		settings:{...fixture.settings,quality:1,elevation:-28,nightGlow:0,skyLight:0},
		camera:{position:eye,yaw:0,pitch}};
	await page.locator('[data-file]').setInputFiles({name:'fork.json',mimeType:'application/json',
		buffer:Buffer.from(JSON.stringify(doc))});
	await expect.poll(() => canvas.evaluate(el=>{const s=el.terrainSandboxDebug.state;
		return s.recipe.seed==='fork-stripes' && s.renderer.roadTextureBytes>0 && !s.renderer.pending;
	}),{timeout:85_000}).toBe(true);
	const capture=await canvas.screenshot({style:'body *{visibility:hidden!important} canvas[data-terrain-sandbox]{visibility:visible!important}'});
	if(process.env.TERRAIN_ROAD_CAPTURES) {
		await fs.mkdir(process.env.TERRAIN_ROAD_CAPTURES,{recursive:true});
		await fs.writeFile(path.join(process.env.TERRAIN_ROAD_CAPTURES,'fork-stripes.png'),capture);
	}
	const {width,height,data}=PNG.sync.read(capture);

	// Project each painted pixel onto the known flat road. Distances are
	// measured against the three authored segments, independently of the paint shader.
	const arms=endpoints.map(([x,,z])=>{const dx=x-origin[0],dz=z-origin[2],len=Math.hypot(dx,dz);
		return [dx/len,dz/len];});
	const seen=arms.map(()=>new Set());
	const scale=Math.tan(Math.PI/6)*2/height, sp=Math.sin(pitch), cp=Math.cos(pitch);
	let white=0, misplacedWhite=0, misplacedYellow=0;
	for(let y=3;y<height-3;y++) for(let x=3;x<width-3;x++) {
		const i=4*(y*width+x);
		const r=data[i],g=data[i+1],b=data[i+2];
		const shoulder=r>=8 && Math.max(r,g,b)-Math.min(r,g,b)<=2;
		const dash=r>=8 && r>2*b && r>g;
		if(!shoulder && !dash) continue;
		const up=(height/2-y-.5)*scale, t=-eye[1]/(sp+up*cp);
		const wx=eye[0]+(x+.5-width/2)*scale*t-origin[0], wz=eye[2]+(-cp+up*sp)*t-origin[2];
		let nearest=Infinity;
		for(const [arm,[dx,dz]] of arms.entries()) {
			const along=Math.max(0,wx*dx+wz*dz);
			const distance=Math.hypot(wx-along*dx,wz-along*dz);
			nearest=Math.min(nearest,distance);
			if(dash && distance<.6 && along<30) seen[arm].add(Math.floor(along));
		}
		if(shoulder) {
			white++;
			// Sampled pavement can round the median tip by one grid cell.
			if(Math.abs(nearest-10)>1) misplacedWhite++;
		}
		if(dash && nearest>.65) misplacedYellow++;
	}
	expect(white).toBeGreaterThan(450);
	expect(misplacedWhite,'stray white pixels among '+white+' shoulder pixels').toBeLessThan(5);
	expect(misplacedYellow).toBeLessThan(5);
	for(const bins of seen) for(let start=0;start<26;start++)
		expect([0,1,2,3,4].some(n=>bins.has(start+n)), 'missing dash near '+start+' m').toBe(true);
});
