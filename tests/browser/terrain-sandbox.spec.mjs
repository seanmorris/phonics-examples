import {test, expect} from '@playwright/test';
import fs from 'node:fs/promises';
import {gunzipSync} from 'node:zlib';

const canvas = page => page.locator('canvas[data-terrain-sandbox]');

const status = page => canvas(page).evaluate(element => JSON.parse(element.dataset.terrainSandbox));

const control = (page, action) => page.locator(`.terrain-sandbox [data-action="${action}"]`);

/** A small, flat landscape keeps authoring regressions independent of the streaming benchmark. */
function fixture({history = [], revision = 0} = {})
{
	const recipe = {
		preset: 'coast'
		, seed: 'browser-authoring'
		, worldSize: 128
		, spacing: 4
	};

	Object.assign(recipe, {heightScale: 0, detailAmplitude: 0, detailOctaves: 1});

	const landscape = {format: 'terrain-phonics', version: 1, recipe, history, cursor: history.length, revision};

	return {
		schema: 'phonics-landscape-sandbox'
		, version: 1
		, landscape
		, settings: {quality: .5, tool: 'navigate', radius: 20, amount: 12}
		, camera: {position: [0,40,0], yaw: 0, pitch: -1.5}
	};
}

async function load(page, document)
{
	const generation = (await status(page)).generation;

	await page.locator('.terrain-sandbox [data-file]').setInputFiles({
		name: 'landscape.json'
		, mimeType: 'application/json'
		, buffer: Buffer.from(JSON.stringify(document))
	});

	await expect.poll(async() => (await status(page)).generation).toBe(generation + 1);
}

async function restoreContext(page)
{
	const events = await canvas(page).evaluate(element => new Promise((resolve, reject) => {
		const extension = element.getContext('webgl2').getExtension('WEBGL_lose_context');

		if(!extension)
		{
			reject(Error('WebGL context-loss testing is unavailable'));

			return;
		}

		const observed = [];

		element.addEventListener('webglcontextlost', () => {
			observed.push('lost');
			setTimeout(() => extension.restoreContext(), 100);
		}, {once: true});

		element.addEventListener('webglcontextrestored', () => resolve([...observed, 'restored']), {once: true});
		extension.loseContext();
	}));

	expect(events).toEqual(['lost', 'restored']);
}

async function boot(page)
{
	const started = Date.now();
	await page.goto('/games/terrain-sandbox/');
	await expect(canvas(page)).toHaveAttribute('data-terrain-sandbox', /"recipe":/, {timeout: 60_000});
	const initial = await status(page);

	console.log(
		'Terrain startup'
		, JSON.stringify({milliseconds: Date.now() - started, renderer: initial.renderer, renderMs: initial.renderMs})
	);

	if(initial.renderer.visible === 0)
	{
		await page.waitForTimeout(300);
		const next = await status(page);

		console.log(
			'Terrain preparation'
			, JSON.stringify({milliseconds: Date.now() - started, renderer: next.renderer, renderMs: next.renderMs})
		);

		if(next.renderer.visible === 0) expect(next.renderer.frames).toBe(initial.renderer.frames);
	}

	await control(page, 'pause').click();
	await expect.poll(async() => (await status(page)).paused).toBe(true);
	await load(page, fixture());
}

/** Wait for both generation and its final presentation frame, without assuming a GPU speed. */
async function settled(page)
{
	let previous = -1;

	await expect.poll(async() => {
		const now = await status(page);
		const unchanged = previous === now.renderer.frames;
		previous = now.renderer.frames;

		return !now.preparing && !now.picking && !now.renderer.pending && !now.renderer.pendingEdits
			&& !now.renderer.selectionPending && !now.renderer.transitions && unchanged;
	}, {timeout: 90_000, intervals: [400,800,1200]}).toBe(true);

	return status(page);
}

async function save(page)
{
	const downloading = page.waitForEvent('download');
	await control(page, 'save').click();
	const download = await downloading;
	expect(download.suggestedFilename()).toBe('landscape.json');

	return JSON.parse(await fs.readFile(await download.path(), 'utf8'));
}

async function stroke(page, tool, x = .5, y = .5)
{
	await page.locator('[data-value="tool"]').selectOption(tool);
	await expect.poll(async() => (await status(page)).settings.tool).toBe(tool);
	const before = (await status(page)).revision;
	const box = await canvas(page).boundingBox();
	await page.mouse.click(box.x + box.width * x, box.y + box.height * y);
	await expect.poll(async() => (await status(page)).revision, {timeout: 90_000}).toBeGreaterThan(before);
	await settled(page);
}

test('the default landscape produces visible terrain while input remains responsive', async({page}, testInfo) => {
	test.setTimeout(90_000);
	const errors = [];
	page.on('pageerror', error => errors.push(error.message));
	const started = Date.now();
	await page.goto('/games/terrain-sandbox/');
	await expect(canvas(page)).toHaveAttribute('data-terrain-sandbox', /"recipe":/, {timeout: 60_000});

	await canvas(page).focus();
	await page.keyboard.press('p');
	await expect.poll(async() => (await status(page)).paused).toBe(true);
	await expect.poll(async() => (await status(page)).generationBudgetMs).toBe(8);
	const altitude = (await status(page)).camera.position[1];
	await page.keyboard.down('e');
	await expect.poll(async() => (await status(page)).generationBudgetMs).toBe(2);
	expect((await status(page)).camera.position[1]).toBeGreaterThan(altitude);
	await page.keyboard.up('e');
	await expect.poll(async() => (await status(page)).generationBudgetMs).toBe(8);

	await expect.poll(async() => (await status(page)).renderer.visible, {timeout: 30_000}).toBeGreaterThan(0);
	const now = await status(page);

	console.log(
		'Default terrain coverage'
		, JSON.stringify({milliseconds: Date.now() - started, renderer: now.renderer, renderMs: now.renderMs})
	);

	expect(now.recipe.worldSize).toBe(32768);
	expect(now.renderer.frames).toBeGreaterThan(1);
	expect(errors).toEqual([]);

	const screenshot = testInfo.outputPath('default-landscape.png');
	await page.screenshot({path: screenshot});
	await testInfo.attach('default-landscape', {path: screenshot, contentType: 'image/png'});
});

test('boarding the craft refines the ground near the camera during cold startup', async({page}) => {
	await page.setViewportSize({width: 672, height: 420});
	await page.goto('/games/terrain-sandbox/');
	await canvas(page).focus();
	await page.keyboard.press('b');
	await expect.poll(async() => (await status(page)).mode).toBe('craft');

	await expect.poll(
		async() => (await status(page)).renderer.cameraSpacing ?? Infinity
		, {timeout: 30_000}
	).toBeLessThanOrEqual(32);
});

test('fly the F5, pause in a bank, and return to free flight', async({page}) => {
	const errors = [];
	page.on('pageerror', error => errors.push(error.message));
	await boot(page);
	const airspace = fixture();
	airspace.landscape.recipe.worldSize = 8192;
	airspace.camera = {position: [0,150,0], yaw: 0, pitch: 0};
	await load(page, airspace);
	const view = canvas(page);
	const mesh = await fs.readFile(new URL('../../games/terrain-sandbox/assets/f5/mesh.bin.gz', import.meta.url));

	await view.evaluate((element, count) => {
		const gl = element.getContext('webgl2'), draw = gl.drawElements.bind(gl);
		element.jetDraws = 0;

		gl.drawElements = (...args) => {
			if(args[1] === count) element.jetDraws++;

			return draw(...args);
		};
	}, gunzipSync(mesh).readUInt32LE(4));

	await control(page, 'jet').click();
	await expect.poll(async() => (await status(page)).mode).toBe('jet');
	await expect.poll(() => view.evaluate(el => el.jetDraws)).toBeGreaterThan(0);
	await expect(control(page, 'jet')).toBeHidden();
	await view.focus();
	await page.keyboard.down('d');
	await page.keyboard.down('e');
	await page.keyboard.down('w');
	await expect.poll(async() => (await status(page)).jet.roll).toBeLessThan(-.4);
	await page.keyboard.press('p');
	await expect.poll(async() => (await status(page)).paused).toBe(true);
	const frozen = (await status(page)).jet;
	expect(frozen.x).toBeGreaterThan(0);
	expect(frozen.y).toBeGreaterThan(150);
	expect(frozen.speed).toBeGreaterThan(110);

	for(const key of ['w','e','d']) await page.keyboard.up(key);
	const before = await view.evaluate(el => el.jetDraws);
	await restoreContext(page);
	await expect.poll(() => view.evaluate(el => el.jetDraws)).toBeGreaterThan(before);
	expect((await status(page)).jet).toEqual(frozen);
	expect(await view.evaluate(el => el.getContext('webgl2').getError())).toBe(0);
	await view.focus();
	await page.keyboard.press('f');
	await expect.poll(async() => (await status(page)).mode).toBe('fly');
	await page.keyboard.press('j');
	await expect.poll(async() => (await status(page)).mode).toBe('jet');
	expect(errors).toEqual([]);
});

test('drive the Testarossa from a dry clearing and switch vehicles', async({page}) => {
	const errors = [];
	page.on('pageerror', error => errors.push(error.message));
	await boot(page);
	const dry = fixture();
	dry.settings.waterLevel = -4;
	dry.camera = {position: [0,30,0], yaw: Math.PI, pitch: -.4};
	await load(page, dry);
	const view = canvas(page);
	const mesh = await fs.readFile(new URL('../../games/terrain-sandbox/assets/testarossa/mesh.bin.gz', import.meta.url));
	await view.evaluate((element, count) => {
		const gl = element.getContext('webgl2'), draw = gl.drawElements.bind(gl);
		element.carDraws = 0;
		gl.drawElements = (...args) => {
			if(args[1] === count) element.carDraws++;
			return draw(...args);
		};
	}, gunzipSync(mesh).readUInt32LE(4));
	await control(page, 'car').click();
	await expect.poll(async() => (await status(page)).mode).toBe('car');
	await expect.poll(() => view.evaluate(element => element.carDraws)).toBeGreaterThan(0);
	await expect(control(page, 'car')).toBeHidden();
	await expect(control(page, 'fly')).toBeVisible();
	await view.focus();
	await page.keyboard.down('w');
	await page.keyboard.down('d');
	await expect.poll(async() => (await status(page)).car.x).toBeLessThan(-.2);
	await page.keyboard.up('d');
	await page.keyboard.up('w');
	const beforeRestore = await view.evaluate(element => element.carDraws);
	await restoreContext(page);
	await expect.poll(() => view.evaluate(element => element.carDraws)).toBeGreaterThan(beforeRestore);
	expect(await view.evaluate(element => element.getContext('webgl2').getError())).toBe(0);
	await view.focus();
	await page.keyboard.press('f');
	await expect.poll(async() => (await status(page)).mode).toBe('fly');
	await page.keyboard.press('c');
	await expect.poll(async() => (await status(page)).mode).toBe('car');
	await page.locator('[data-value="waterLevel"]').fill('10');
	await expect.poll(async() => (await status(page)).mode).toBe('fly');
	await control(page, 'craft').click();
	await expect.poll(async() => (await status(page)).mode).toBe('craft');
	await control(page, 'car').click();
	await expect.poll(async() => (await status(page)).message).toContain('No dry');
	expect((await status(page)).mode).toBe('craft');
	expect(errors).toEqual([]);
});

test('the default canyon lets all four wheels leave the ground and touch down again', async({page}) => {
	const errors = [];
	page.on('pageerror', error => errors.push(error.message));
	await page.setViewportSize({width:672,height:420});
	await page.goto('/games/terrain-sandbox/');
	const view = canvas(page);
	await expect(view).toHaveAttribute('data-terrain-sandbox', /"recipe":/, {timeout:60000});
	await page.locator('[name="preset"]').selectOption('canyon');
	await control(page,'generate').click();
	await expect.poll(async() => (await status(page)).recipe.preset).toBe('canyon');
	await control(page,'car').click();
	await expect.poll(async() => (await status(page)).mode).toBe('car');
	await view.evaluate(el => {
		const gl = el.getContext('webgl2');
		const draw = gl.drawElements.bind(gl), locations = new Map();
		el.suspensionDraw = {drooped:false,compressed:false};
		gl.drawElements = (...args) => {
			const program = gl.getParameter(gl.CURRENT_PROGRAM);
			if(!locations.has(program)) locations.set(program,gl.getUniformLocation(program,'uWheelTravel'));
			const location = locations.get(program);
			if(location) {
				const travel = gl.getUniform(program,location);
				el.suspensionDraw.drooped ||= travel.every(v => v < -.12);
				el.suspensionDraw.compressed ||= travel.some(v => v > .04);
			}
			return draw(...args);
		};
		new MutationObserver(() => {
			const car = JSON.parse(el.dataset.terrainSandbox).car;
			if(car?.motion === 'airborne' && car.wheelContacts === 0) el.airborneCar = car;
			if(el.airborneCar && car?.wheelContacts === 4) el.landedCar = car;
		}).observe(el, {attributes:true,attributeFilter:['data-terrain-sandbox']});
	});
	await view.focus();
	await page.keyboard.down('w');
	await expect.poll(() => view.evaluate(el => !!el.landedCar), {timeout:90000}).toBe(true);
	await page.keyboard.up('w');
	const result = await view.evaluate(el => [el.airborneCar,el.landedCar,el.suspensionDraw,el.getContext('webgl2').getError()]);
	expect(result[1].z).toBeGreaterThan(result[0].z);
	expect(result[1].recoveries).toBe(0);
	expect(result[2]).toEqual({drooped:true,compressed:true});
	expect(result[3]).toBe(0);
	expect(errors).toEqual([]);
});

test('a rollover settles on the roof before the Testarossa recovers', async({page}) => {
	const errors = [];
	page.on('pageerror', error => errors.push(error.message));
	await page.setViewportSize({width:672,height:420});
	await boot(page);
	const ramp = fixture({history:[[{type:'raise',x:0,z:45,radius:16,amount:30}]],revision:1});
	Object.assign(ramp.landscape.recipe, {worldSize:512,spacing:1});
	ramp.settings.waterLevel = -100;
	ramp.camera = {position:[0,30,-40],yaw:Math.PI,pitch:-.4};
	await load(page,ramp);
	await control(page,'car').click();
	await expect.poll(async() => (await status(page)).mode).toBe('car');
	await canvas(page).focus();
	await page.keyboard.down('w');
	await expect.poll(async() => (await status(page)).car.motion).toBe('tumbling');
	await page.keyboard.up('w');
	await expect.poll(async() => (await status(page)).car.motion, {timeout:60000}).toBe('overturned');
	const landed = (await status(page)).car;
	expect(Math.cos(landed.pitch)*Math.cos(landed.roll)).toBeLessThan(-.8);
	await expect.poll(async() => (await status(page)).car.recoveries).toBe(1);
	const righted = (await status(page)).car;
	expect(righted.motion).toBe('driving');
	expect(Math.hypot(righted.x-landed.x,righted.z-landed.z)).toBeLessThan(.1);
	expect(Math.abs(righted.roll)+Math.abs(righted.pitch)).toBeLessThan(.1);
	expect(errors).toEqual([]);
});

test('terrain without a mesh worker still renders and settles', async({page}) => {
	await page.addInitScript(() => {

		window.Worker = class extends Worker {

			constructor(url, options) {
				if(options?.name === 'terrain-patch-mesher') throw Error('Worker disabled for test');

				super(url, options);
			}
		};
	});

	await boot(page);
	expect((await settled(page)).renderer.visible).toBeGreaterThan(0);
});

test('terrain controls preserve input focus and return paused rendering to idle', async({page}) => {
	test.setTimeout(240_000);
	const errors = [];
	page.on('pageerror', error => errors.push(error.message));
	await boot(page);
	let now = await settled(page);

	const frame = now.renderer.frames;
	const time = now.time;
	expect(now.generationBudgetMs).toBe(8);
	await page.waitForTimeout(1200);
	now = await status(page);
	expect(now.renderer.frames).toBe(frame);
	expect(now.time).toBe(time);
	const idleUpdates = await canvas(page).evaluate(async element => {
		let updates = 0;
		const observer = new MutationObserver(records => { updates += records.length; });
		observer.observe(element, {attributes:true, attributeFilter:['data-terrain-sandbox']});
		await new Promise(resolve => setTimeout(resolve, 600));
		observer.disconnect();
		return updates;
	});
	expect(idleUpdates).toBe(0);

	// A water edit redraws a paused scene and is included in the portable save.
	await page.locator('[data-value="waterColor"]').fill('#147083');
	await expect.poll(async() => (await status(page)).settings.waterColor).toBe('#147083');
	const waterEdit = await settled(page);
	expect(waterEdit.renderer.frames).toBeGreaterThan(frame);
	expect(waterEdit.time).toBe(time);
	expect((await save(page)).settings.waterColor).toBe('#147083');

	const nightGlow = page.getByRole('slider', {name: /Night glow/});
	await nightGlow.focus();
	await nightGlow.press('End');
	await expect.poll(async() => (await status(page)).settings.nightGlow).toBe(1);

	const coarseFog = page.getByRole('slider', {name: /Fog density · coarse/});
	const fineFog = page.getByRole('slider', {name: /Fog density · fine/});
	await coarseFog.focus();
	await coarseFog.press('Home');
	await expect(fineFog).toHaveAttribute('min', '0.0000');
	await fineFog.focus();
	await fineFog.press('ArrowRight');
	await expect.poll(async() => (await status(page)).settings.waterFogDensity).toBe(.0001);
	await coarseFog.focus();
	await coarseFog.press('End');
	await expect(fineFog).toHaveAttribute('max', '1.0000');
	await fineFog.focus();
	await fineFog.press('ArrowLeft');
	await expect.poll(async() => (await status(page)).settings.waterFogDensity).toBe(.9999);

	const level = page.getByLabel('Water level · m', {exact: true});
	const chop = page.getByRole('slider', {name: /Choppiness/});
	await level.fill('-10');
	await expect.poll(async() => (await status(page)).renderer.waterDraws).toBe(0);
	await level.fill('6.5');
	await chop.focus();
	await chop.press('End');
	await expect.poll(async() => (await status(page)).settings.waterChoppiness).toBe(300);
	const rough = await settled(page);
	expect(rough.renderer.waterDraws).toBeGreaterThan(0);
	expect(rough.time).toBe(time);
	expect(rough.generation).toBe(now.generation);
	const sea = await save(page);
	expect(sea.settings.waterLevel).toBe(6.5);
	expect(sea.settings.waterFogDensity).toBe(.9999);
	await load(page, fixture());
	await expect(chop).toHaveValue('100');
	await expect(nightGlow).toHaveValue('0.5');
	await load(page, sea);
	await expect(level).toHaveValue('6.5');
	await expect(chop).toHaveValue('300');
	await expect(nightGlow).toHaveValue('1');
	await expect(fineFog).toHaveValue('0.9999');
	await expect(coarseFog).toHaveValue('1');

	// Native fields keep WASD and pause shortcuts as text while focused.
	const position = now.camera.position;
	await page.locator('[name="seed"]').fill('wasd-pause');
	await page.keyboard.press('p');
	await page.waitForTimeout(500);
	expect((await status(page)).camera.position).toEqual(position);
	expect((await status(page)).paused).toBe(true);

	await canvas(page).focus();
	await page.keyboard.down('e');
	await expect.poll(async() => (await status(page)).camera.position[1]).toBeGreaterThan(position[1]);
	await expect.poll(async() => (await status(page)).generationBudgetMs).toBe(2);
	await page.locator('[name="seed"]').focus();
	await page.keyboard.up('e');
	await expect.poll(async() => (await status(page)).generationBudgetMs).toBe(8);
	await settled(page);
	const stopped = (await status(page)).camera.position;
	await page.waitForTimeout(500);
	expect((await status(page)).camera.position).toEqual(stopped);

	// A right drag changes orientation; left clicks in Navigate cannot edit terrain.
	const box = await canvas(page).boundingBox();
	const yaw = (await status(page)).camera.yaw;
	await page.mouse.move(box.x + box.width * .4, box.y + box.height * .4);
	await page.mouse.down({button: 'right'});
	await page.mouse.move(box.x + box.width * .45, box.y + box.height * .42, {steps: 3});
	await page.mouse.up({button: 'right'});
	await expect.poll(async() => (await status(page)).camera.yaw).not.toBe(yaw);
	await page.mouse.click(box.x + box.width * .4, box.y + box.height * .4);
	expect((await status(page)).revision).toBe(0);

	for(const [name,value] of [['exposure',1.5],['haze',2]])
	{
		await page.locator(`[data-value="${name}"]`).evaluate((element, next) => {
			element.value = next;
			element.dispatchEvent(new Event('input', {bubbles: true}));
		}, value
		);
	}

	await expect.poll(async() => (await status(page)).settings.haze).toBe(2);
	expect((await status(page)).settings.exposure).toBe(1.5);
	await page.locator('[name="preset"]').selectOption('mountain');
	await page.locator('[name="seed"]').fill('mountain-browser-check');
	await control(page, 'generate').click();
	await expect.poll(async() => (await status(page)).recipe.seed).toBe('mountain-browser-check');
	expect((await status(page)).recipe.heightScale).toBe(1400);
	await settled(page);
	expect(errors).toEqual([]);
});

test('terrain brushes, undo, roads, files, and craft share complete terrain revisions', async({page}) => {
	test.setTimeout(420_000);
	const errors = [];
	page.on('pageerror', error => errors.push(error.message));
	await boot(page);
	await settled(page);

	const initialGeneration = (await status(page)).generation;
	await stroke(page, 'raise');
	let saved = await save(page);
	expect(saved.landscape.history[0][0].type).toBe('raise');
	expect(saved.landscape.cursor).toBe(1);
	expect((await status(page)).generation).toBe(initialGeneration);

	await control(page, 'undo').click();
	await expect.poll(async() => (await status(page)).surface.operations, {timeout: 90_000}).toBe(0);
	await settled(page);
	await control(page, 'redo').click();
	await expect.poll(async() => (await status(page)).surface.operations, {timeout: 90_000}).toBe(1);
	await settled(page);

	await stroke(page, 'material', .4, .5);
	await stroke(page, 'crater', .55, .4);
	await stroke(page, 'smooth', .55, .4);
	await stroke(page, 'flatten', .45, .6);
	await stroke(page, 'lower', .5, .5);
	await page.locator('[data-value="tool"]').selectOption('road');
	await expect.poll(async() => (await status(page)).settings.tool).toBe('road');
	const box = await canvas(page).boundingBox();

	for(const x of [.3,.6]) await page.mouse.click(box.x + box.width * x, box.y + box.height * .5);
	await expect.poll(async() => (await status(page)).roadPoints.length).toBe(2);
	await expect(page.locator('[data-road-marker]')).toHaveCount(2);
	await expect(page.locator('[data-road-path]')).toHaveAttribute('d', /^M.+L/);

	const preview = page.locator('[data-road-preview]');
	const pointerEvents = await preview.evaluate(element => getComputedStyle(element).pointerEvents);

	expect(pointerEvents).toBe('none');
	const lookX = box.x + box.width * .4, lookY = box.y + box.height * .5;
	await page.mouse.move(lookX, lookY);
	await page.mouse.down({button: 'right'});
	await page.mouse.move(lookX, lookY - 900);
	await page.mouse.up({button: 'right'});
	await expect(page.locator('[data-road-marker]')).toHaveCount(0);

	await page.mouse.move(lookX, lookY);
	await page.mouse.down({button: 'right'});
	await page.mouse.move(lookX, lookY + 900);
	await page.mouse.up({button: 'right'});
	await expect(page.locator('[data-road-marker]')).toHaveCount(2);

	await control(page, 'road-apply').click();
	await expect.poll(async() => (await status(page)).surface.operations, {timeout: 90_000}).toBe(7);
	await settled(page);

	saved = await save(page);
	expect(saved.landscape.history.at(-1)[0].type).toBe('road');
	expect(saved.landscape.history.at(-1)[0].points).toHaveLength(2);
	expect((await status(page)).roadPoints).toEqual([]);
	await expect(page.locator('[data-road-marker]')).toHaveCount(0);
	await load(page, saved);
	await settled(page);
	expect((await save(page)).landscape).toEqual(saved.landscape);

	// The craft sees the same persisted seabed as the visible landscape.
	await load(page, fixture({history: [[{type: 'lower', x: 0, z: 0, radius: 100, amount: 20}]], revision: 1}));
	await settled(page);
	const mesh = await fs.readFile(new URL('../../games/terrain-sandbox/assets/jetski/mesh.bin.gz', import.meta.url));
	const indexCount = gunzipSync(mesh).readUInt32LE(4);
	await canvas(page).evaluate((element, count) => {
		const gl = element.getContext('webgl2');
		const draw = gl.drawElements.bind(gl);
		element.craftDraws = 0;
		gl.drawElements = (...args) => {
			if(args[1] === count) element.craftDraws++;
			return draw(...args);
		};
	}, indexCount);
	await control(page, 'craft').click();
	await expect.poll(async() => (await status(page)).mode).toBe('craft');
	expect((await status(page)).paused).toBe(false);
	expect((await status(page)).generationBudgetMs).toBe(2);
	await expect.poll(() => canvas(page).evaluate(element => element.craftDraws)).toBeGreaterThan(0);
	await restoreContext(page);
	const priorDraws = await canvas(page).evaluate(element => element.craftDraws);
	await expect.poll(() => canvas(page).evaluate(element => element.craftDraws)).toBeGreaterThan(priorDraws);
	expect(await canvas(page).evaluate(element => element.getContext('webgl2').getError())).toBe(0);
	const before = (await status(page)).craft;
	await canvas(page).focus();
	await page.keyboard.down('w');

	await expect.poll(async() => {
		const after = (await status(page)).craft;

		return Math.hypot(after.x - before.x, after.z - before.z);
	}).toBeGreaterThan(.5);

	for(const [key, direction] of [['d', 1], ['a', -1]])
	{
		const heading = (await status(page)).camera.yaw;
		await page.keyboard.down(key);
		await expect.poll(async() => direction * Math.sin((await status(page)).camera.yaw - heading))
			.toBeGreaterThan(.05);
		await page.keyboard.up(key);
	}

	await page.keyboard.up('w');
	await control(page, 'fly').click();
	await expect.poll(async() => (await status(page)).mode).toBe('fly');
	await control(page, 'pause').click();
	await settled(page);
	expect(errors).toEqual([]);
});

test('terrain restores a lost WebGL context and releases page ownership on navigation', async({page}) => {
	test.setTimeout(180_000);
	const errors = [];
	page.on('pageerror', error => errors.push(error.message));
	await boot(page);
	await settled(page);
	await stroke(page, 'raise');
	const before = await settled(page);

	await restoreContext(page);
	await expect.poll(async() => (await status(page)).renderer.frames).toBeGreaterThan(before.renderer.frames);
	const restored = await settled(page);
	expect(restored.renderer.frames).toBe(before.renderer.frames + 1);
	expect(restored.revision).toBe(before.revision);
	expect(restored.surface.operations).toBe(before.surface.operations);
	await page.waitForTimeout(1000);
	expect((await status(page)).renderer.frames).toBe(restored.renderer.frames);

	// Exercise the host's actual navigation cleanup handler while its old DOM is inspectable.
	await page.evaluate(() => dispatchEvent(new PageTransitionEvent('pagehide')));
	await expect(page.locator('.terrain-sandbox')).toHaveCount(0);
	expect(await page.locator('canvas').evaluate(element => 'terrainSandboxDebug' in element)).toBe(false);
	await page.getByRole('link', {name: '← All examples'}).click();
	await expect(page.locator('.terrain-sandbox')).toHaveCount(0);
	expect(errors).toEqual([]);
});
