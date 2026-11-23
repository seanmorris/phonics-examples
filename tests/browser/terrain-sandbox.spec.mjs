import {test, expect} from '@playwright/test';
import {PNG} from 'pngjs';
import fs from 'node:fs/promises';
import {execFileSync} from 'node:child_process';
import {fileURLToPath} from 'node:url';
import {gunzipSync} from 'node:zlib';

const canvas = page => page.locator('canvas[data-terrain-sandbox]');

const status = page => canvas(page).evaluate(element => JSON.parse(element.dataset.terrainSandbox));

const control = (page, action) => page.locator(`.terrain-sandbox [data-action="${action}"]`);

/**
 * Open the panel tab that holds an element, as a player would before using it.
 * @param {import('@playwright/test').Page} page Sandbox page.
 * @param {string} selector CSS selector of a panel control.
 * @returns {Promise<import('@playwright/test').Locator>} The control.
 */
const field = async (page, selector) => {
	const control = page.locator(selector).first();
	const section = await control.evaluate(element => element.closest('[role="tabpanel"]')?.id ?? '');

	if(section)
	{
		const collapsed = page.locator('.terrain-panel:not([open]) > summary');

		if(await collapsed.count()) await collapsed.click();

		await page.locator(`.terrain-sandbox [aria-controls="${section}"]`).click();
	}

	return page.locator(selector);
};

/** Open the tab holding a panel button, then press it. */
const press = async (page, action) => (await field(page, `.terrain-sandbox [data-action="${action}"]`)).click();

/** A small, flat landscape keeps authoring regressions independent of the streaming benchmark. */
function fixture({history = [], revision = 0} = {})
{
	const recipe = {
		preset: 'coast'
		, seed: 'browser-authoring'
		, worldSize: 128
		, spacing: 4
		, surround: 'none'
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

	await press(page, 'pause');
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
	await press(page, 'save');
	const download = await downloading;
	expect(download.suggestedFilename()).toBe('landscape.json');

	return JSON.parse(await fs.readFile(await download.path(), 'utf8'));
}

async function stroke(page, tool, x = .5, y = .5)
{
	await (await field(page, '[data-value="tool"]')).selectOption(tool);
	await expect.poll(async() => (await status(page)).settings.tool).toBe(tool);
	const before = (await status(page)).revision;
	await page.locator('.terrain-panel > summary').click();
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

test('local terrain shadows follow the sun and reuse stationary probes', async({page}) => {
	test.setTimeout(120000);
	await page.goto('/games/terrain-sandbox/');
	const view = canvas(page);
	await expect(view).toHaveAttribute('data-terrain-sandbox', /"recipe":/, {timeout: 60000});
	await view.evaluate(el => el.terrainSandboxDebug.action('pause'));
	await expect.poll(async() => (await status(page)).paused).toBe(true);

	const generation = async() => (await status(page)).renderer.triangleShadowGeneration;
	await expect.poll(generation, {timeout: 60000}).toBeGreaterThan(0);
	const first = await generation();

	const values = patch => view.evaluate((el, v) => el.terrainSandboxDebug.values(v), patch);
	await values({exposure: 1.25});
	await expect.poll(async() => (await status(page)).settings.exposure).toBe(1.25);
	expect(await generation()).toBe(first);
	await values({azimuth: 45,elevation: 18});
	await expect.poll(generation).toBeGreaterThan(first);
	const day = await generation();
	await values({elevation: -6});
	await expect.poll(async() => (await status(page)).settings.elevation).toBe(-6);
	expect(await generation()).toBe(day);
	await values({elevation: 18});
	await expect.poll(generation).toBeGreaterThan(day);
	expect(await view.evaluate(el => el.getContext('webgl2').getError())).toBe(0);
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

test('the jet ski speedometer passes 90 mph on open water', async({page}) => {
	await page.setViewportSize({width: 672,height: 420});
	await boot(page);
	const lake = fixture();
	lake.landscape.recipe.worldSize = 1024;
	lake.settings.waterLevel = 12;
	lake.camera = {position: [0,30,-300],yaw: Math.PI,pitch: -.3};
	await load(page, lake);
	await press(page, 'craft');
	const dial = page.locator('.terrain-speedometer');
	await expect(dial).toBeVisible();
	await expect(dial).toHaveAttribute('aria-label', 'Speedometer, 0 miles per hour');
	await canvas(page).focus();
	await page.keyboard.down('w');

	try
	{
		await expect.poll(
			async() => (await status(page)).craft.speed / .44704
			, {timeout: 90_000}
		).toBeGreaterThanOrEqual(90);

		await page.keyboard.press('p');
		await expect.poll(async() => (await status(page)).paused).toBe(true);
		const mph = Math.round((await status(page)).craft.speed / .44704);
		await expect(dial).toHaveAttribute('aria-label', `Speedometer, ${mph} miles per hour`);
	}
	finally
	{
		await page.keyboard.up('w');
	}

	await press(page, 'fly');
	await expect(dial).toBeHidden();
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

	await press(page, 'jet');
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

test('the heading-up map and altitude radar agree in chase and cockpit views', async({page}) => {
	await page.setViewportSize({width: 960, height: 760});

	await page.addInitScript(() => {
		const proto = CanvasRenderingContext2D.prototype;
		const fillText = proto.fillText;
		const gl = WebGL2RenderingContext.prototype;
		const headings = new WeakSet(), charts = new WeakSet();
		const getUniformLocation = gl.getUniformLocation, uniform2f = gl.uniform2f, uniform4f = gl.uniform4f;

		gl.getUniformLocation = function(program, name) {
			const location = getUniformLocation.call(this, program, name);

			if(location && name === 'uHeading') headings.add(location);

			if(location && name === 'uChart') charts.add(location);

			return location;
		};

		gl.uniform2f = function(location, cosine, sine) {
			if(headings.has(location)) window.terrainChartHeading = [cosine, sine];

			return uniform2f.call(this, location, cosine, sine);
		};

		gl.uniform4f = function(location, x, z, span, side) {
			if(charts.has(location)) window.terrainChart = [x,z,span,side];

			return uniform4f.call(this, location, x, z, span, side);
		};

		proto.fillText = function(label, x, y, ...rest) {
			if(this.canvas.matches('.terrain-instruments') && ['SPD','ALT'].includes(label))
			{
				const position = this.getTransform().transformPoint({x,y});
				(window.tapeLabels ??= {})[label] = [position.x/this.canvas.width, this.fillStyle];
			}

			return fillText.call(this, label, x, y, ...rest);
		};
	});

	await boot(page);
	const airspace = fixture();
	airspace.landscape.recipe.worldSize = 8192;
	airspace.settings.waterLevel = -1;
	airspace.camera = {position: [0,200,0], yaw: Math.PI/2, pitch: 0};
	// Facing east, the level northern balloon is left, the high eastern one ahead, and the low southern one right.
	airspace.balloons = [[0,-650,200],[800,0,400],[0,650,80]];
	await load(page, airspace);
	await canvas(page).focus();
	await page.keyboard.press('j');
	await expect.poll(async() => (await status(page)).mode).toBe('jet');
	await page.keyboard.press('p');
	await expect.poll(async() => (await status(page)).paused).toBe(true);

	const map = page.locator('.terrain-flight-map'), radar = page.locator('.terrain-instruments');

	// Group the actual blip pixels from left to right and compare the top and bottom rows of each glyph.

	const blips = el => {
		const isMap = el.matches('.terrain-flight-map'), w = el.width, h = el.height;
		const pixels = el.getContext('2d').getImageData(0, 0, w, h).data;
		const groups = [];
		let last = -2;

		for(let x = isMap ? 0 : Math.floor(.82*w); x<w; x++)
		for(let y = isMap ? 0 : Math.floor(.77*h); y<h; y++)
		{
			const i = (w*y+x)*4;
			const amber = pixels[i+1] === (isMap ? 220 : 202) && pixels[i+2] === (isMap ? 105 : 81);
			const red = pixels[i+1] === (isMap ? 99 : 81) && pixels[i+2] === (isMap ? 87 : 72);

			if(pixels[i] !== 255 || pixels[i+3] < 250 || !(amber || red)) continue;

			if(x > last+1) groups.push(new Map());

			const rows = groups.at(-1);
			rows.set(y, (rows.get(y) ?? 0)+1);
			last = x;
		}

		return groups.map(rows => {
			const top = Math.min(...rows.keys()), bottom = Math.max(...rows.keys());

			return {y: (top+bottom)/2, tip: Math.sign(rows.get(bottom)-rows.get(top))};
		});
	};

	for(const view of ['chase','cockpit'])
	{
		if(view === 'cockpit') await page.keyboard.press('v');

		await expect.poll(async() => (await status(page)).jetView).toBe(view);
		await expect(radar).toBeVisible();
		await expect(map).toBeVisible();
		await expect.poll(() => page.evaluate(() => window.terrainChartHeading?.[1])).toBeCloseTo(1);
		const chart = await map.evaluate(blips);
		expect(chart).toHaveLength(3);
		expect(chart[1].y).toBeLessThan(Math.min(chart[0].y, chart[2].y)-10);
		const contacts = await radar.evaluate(blips);
		expect(contacts.map(b => b.tip)).toEqual([0,1,-1]);
		expect(contacts[1].y).toBeLessThan(Math.min(contacts[0].y, contacts[2].y)-10);
	}

	const {SPD,ALT} = await page.evaluate(() => window.tapeLabels);
	const before = await radar.evaluate(el => el.width);
	expect(ALT[0]-.5).toBeCloseTo(.5-SPD[0], 3);
	await page.setViewportSize({width: 1440, height: 760});
	await expect.poll(() => radar.evaluate(el => el.width)).toBeGreaterThan(before+300);
	const after = await radar.evaluate(el => el.width);
	const wider = await page.evaluate(() => window.tapeLabels);
	expect((.5-wider.SPD[0])*after).toBeCloseTo((.5-SPD[0])*before, 1);
	expect((wider.ALT[0]-.5)*after).toBeCloseTo((ALT[0]-.5)*before, 1);
	expect([SPD[1],ALT[1]]).toEqual(['#59ff86','#59ff86']);

	const chart = () => page.evaluate(() => window.terrainChart);
	const local = await chart();
	await page.keyboard.press('n');
	await expect.poll(async() => (await chart())[2]).toBeGreaterThan(local[2]);
	const wide = await chart();
	expect(wide.slice(0, 2)).toEqual(local.slice(0, 2));
	expect(wide[2]).toBeLessThanOrEqual(8192/Math.SQRT2 + .001);
	await page.keyboard.press('n');
	await expect.poll(chart).toEqual(local);
});

test('cockpit cues show the nearest balloon and the diamond meets the selected balloon at lock', async({page}) => {
	const sources = await Promise.all(['game','cockpit'].map(async name => {
		const path = new URL(`../../games/terrain-sandbox/browser/${name}.mjs`, import.meta.url);

		return 'data:text/javascript;base64,' + (await fs.readFile(path)).toString('base64');
	}));

	await page.setContent('<div><canvas></canvas></div>');

	const states = await page.evaluate(async ([game, cockpit]) => {
		const {terrainUnits} = await import(game);
		const {activate} = await import(cockpit);
		const module = {
			terrainUnits

			, drawTerrainMap() {}

			, drawTerrainSpeedometer() {}
		};

		activate({module, canvas: document.querySelector('canvas')});
		const ctx = document.querySelector('.terrain-instruments').getContext('2d');
		let rangeText;
		const fillText = ctx.fillText.bind(ctx);

		ctx.fillText = (text, ...args) => {
			if(String(text).endsWith(' yd')) rangeText = text;

			fillText(text, ...args);
		};

		const frame = {
			width: 800
			, height: 720
			, cockpit: true
			, ring: .285
			, contactRange: 4000
			, fov: Math.PI/2
			, eye: [0,0,0]
			, basis: [[0,0,-1],[1,0,0],[0,1,0]]
			, jet: {x: 0, y: 0, z: 0, yaw: Math.PI, pitch: 0, vx: -20, vy: 15, vz: -120, speed: 120}
			, thrust: 75
			, targets: {1: [250,125,-1000], 2: [400,400,-400]}
			, range: {selected: 1, visible: true, locked: false, elapsed: 5, ground: 0, ready: 2}
		};

		// The winged marker is at (-60,-45). Check ink at diamond tips, clear of the target box and pitch ladder.

		function ink(x, y, red = false)
		{
			const pixels = ctx.getImageData(399+x, 359+y, 3, 3).data;

			for(let i = 0; i<pixels.length; i += 4)
				if(pixels[i+3] > 120 && (red
					? pixels[i] > 200 && pixels[i+1] < 100
					: pixels[i+1] > 200
					&& pixels[i] < 140
				))
					return true;

			return false;
		}

		function sample(elapsed, progress)
		{
			frame.range.elapsed = elapsed;
			frame.range.lockProgress = progress;
			frame.range.locked = progress === 1;
			module.terrainVehicleFrame(JSON.stringify(frame));

			return [ink(-60, -50), ink(15, -52), ink(6, -52), ink(72, -54, true), ink(72, -58, true)];
		}

		sample(5, 0);
		const farRange = rangeText;
		const waiting = sample(9, 0), nearestRight = ink(150, -150), underRim = ink(142, -142);
		// Move the nearest balloon across the rim with a farther contact still outside on the opposite side.
		frame.targets[3] = [-700,-700,-600];
		frame.targets[2] = [161,161,-400];
		sample(9.04, 0);
		const inside = ink(150, -150) || ink(-150, 150);
		frame.targets[2] = [162,162,-400];
		sample(9.08, 0);
		const outsideAgain = ink(150, -150);
		frame.targets[2] = [-10,-10,400];
		const moving = sample(9.15, .5), nearestBehind = ink(-150, 150);
		const paused = sample(9.15, .5);
		frame.targets[1][0] = 200;
		const follows = sample(9.16, .5);
		const contact = sample(9.3, 1), pausedContact = sample(9.3, 1), locked = sample(9.33, 1);
		const interrupted = sample(10, 0);
		frame.range.visible = false;
		const hidden = sample(10.2, 0);
		frame.targets = {2: [5000,5000,0]};
		sample(10.3, 0);
		const distant = ink(150, -150) || ink(-150, 150);
		frame.range.visible = true;
		frame.range.selected = 2;
		const cycle = sample(10.4, 0);
		frame.targets[2] = [0,0,-91.44];
		sample(10.45, 0);
		const nearRange = rangeText;
		frame.cockpit = false;
		const chase = sample(10.5, .5);

		return {
			farRange
			, nearRange
			, waiting
			, moving
			, paused
			, follows
			, contact
			, pausedContact
			, locked
			, interrupted
			, hidden
			, cycle
			, chase
			, nearestRight
			, underRim
			, inside
			, outsideAgain
			, nearestBehind
			, distant
		};
	}, sources
	);

	expect(states.farRange).toBe(Math.round(Math.hypot(250, 125, 1000)/.9144) + ' yd');
	expect(states.nearRange).toBe('100 yd');
	const origin = [true,false,false,false,false];

	for(const state of ['waiting','interrupted','hidden','cycle']) expect(states[state]).toEqual(origin);
	expect(states.moving).toEqual([true,true,false,false,false]);
	expect(states.paused).toEqual(states.moving);
	expect(states.follows).toEqual([true,false,true,false,false]);
	expect(states.contact).toEqual([true,false,false,true,true]);
	expect(states.pausedContact).toEqual(states.contact);
	expect(states.locked).toEqual([true,false,false,false,true]);
	expect(states.chase).toEqual([false,false,false,false,false]);
	expect(states.nearestRight).toBe(true);
	expect(states.underRim).toBe(false);
	expect(states.inside).toBe(false);
	expect(states.outsideAgain).toBe(true);
	expect(states.nearestBehind).toBe(true);
	expect(states.distant).toBe(false);
});

test('the cockpit flight-path diamond projects ground track through the banked pilot view', async({page}) => {
	await page.addInitScript(() => {
		const axes = [0,0,0,0];
		const buttons = Array.from({length: 17}, () => ({value: 0, pressed: false, touched: false}));
		const pad = {
			id: 'Flight-path test'
			, index: 0
			, mapping: 'standard'
			, timestamp: 1
			, connected: true
			, axes
			, buttons
		};

		Object.defineProperty(navigator, 'getGamepads', {value: () => [pad]});

		window.flightStick = (x, y) => {
			axes[0] = x;
			axes[1] = y;
			pad.timestamp++;
		};

		const lineTo = CanvasRenderingContext2D.prototype.lineTo;

		CanvasRenderingContext2D.prototype.lineTo = function(x, y) {
			if(this.canvas.className === 'terrain-instruments' && x === 0 && y === -5)
			{
				const t = this.getTransform();
				window.pathOffset = [t.e-this.canvas.width/2, t.f-this.canvas.height/2];
			}

			return lineTo.call(this, x, y);
		};
	});

	await boot(page);
	const airspace = fixture();
	airspace.landscape.recipe.worldSize = 8192;
	airspace.camera = {position: [0,1500,0], yaw: 0, pitch: 0};
	await load(page, airspace);
	await press(page, 'jet');
	await expect.poll(async() => (await status(page)).mode).toBe('jet');
	await canvas(page).focus();
	await page.keyboard.press('v');
	await expect.poll(async() => (await status(page)).jetView).toBe('cockpit');
	await expect.poll(() => page.evaluate(() => Math.hypot(...(window.pathOffset ?? [Infinity])))).toBeLessThan(.01);

	await page.evaluate(() => window.flightStick(.5, .35));
	await expect.poll(async() => (await status(page)).jet.pitch).toBeGreaterThan(.14);
	await page.keyboard.press('p');
	await expect.poll(async() => (await status(page)).paused).toBe(true);
	const {jet,viewport,camera} = await status(page);
	expect(jet.vy).toBeGreaterThan(0);
	const focal = viewport[1]/(2*Math.tan(camera.fov/2));
	const along = jet.vx*Math.sin(jet.yaw) + jet.vz*Math.cos(jet.yaw);
	const left = jet.vx*Math.cos(jet.yaw) - jet.vz*Math.sin(jet.yaw);
	const up = jet.vy*Math.cos(jet.pitch) - along*Math.sin(jet.pitch);
	const depth = along*Math.cos(jet.pitch) + jet.vy*Math.sin(jet.pitch);
	const [x,y] = await page.evaluate(() => window.pathOffset);
	expect(Math.hypot(x, y)).toBeGreaterThan(12);
	expect(x).toBeCloseTo(focal*(-left*Math.cos(jet.roll) + up*Math.sin(jet.roll))/depth, 3);
	expect(y).toBeCloseTo(-focal*(left*Math.sin(jet.roll) + up*Math.cos(jet.roll))/depth, 3);
	await page.evaluate(() => window.flightStick(0, 0));
	await page.keyboard.press('p');
	await expect.poll(() => page.evaluate(() => Math.hypot(...window.pathOffset))).toBeLessThan(1);
});

test('both F5 exhausts brighten as the engine spools through cruise and afterburner', async({page}) => {
	await boot(page);
	const airspace = fixture();
	airspace.landscape.recipe.worldSize = 8192;
	airspace.camera = {position: [0,500,0], yaw: 0, pitch: 0};
	await load(page, airspace);

	const indexCount = gunzipSync(
		await fs.readFile(new URL('../../games/terrain-sandbox/assets/f5/mesh.bin.gz', import.meta.url))
	).readUInt32LE(4);

	const view = canvas(page);

	await view.evaluate((el, count) => {
		const gl = el.getContext('webgl2'), draw = gl.drawElements.bind(gl);
		el.exhausts = {};

		gl.drawElements = (...args) => {
			draw(...args);

			if(args[1] !== count) return;

			const pg = gl.getParameter(gl.CURRENT_PROGRAM);

			const uniform = key => gl.getUniform(pg, gl.getUniformLocation(pg, key));
			const level = uniform('uEngineThrust');
			const state = level < .32 ? 'idle' : Math.abs(level-.75) < .005 ? 'cruise' : level > 1.38 ? 'full' : '';

			if(!state || el.exhausts[state]) return;

			const origin = uniform('uOrigin'), basis = uniform('uRotation'), projection = uniform('uViewProjection');

			el.exhausts[state] = [-.296,.290].map(x => {
				const local = [x,-.233,-6.13];
				const point = [0,1,2].map(i => origin[i]+local.reduce((v, n, j) => v+n*basis[3*j+i], 0));
				point.push(1);
				const clip = [0,1,2,3].map(i => point.reduce((v, n, j) => v+n*projection[4*j+i], 0));
				const px = Math.floor((1+clip[0]/clip[3])*gl.drawingBufferWidth/2);
				const py = Math.floor((1+clip[1]/clip[3])*gl.drawingBufferHeight/2);
				const pixels = new Uint8Array(36);
				gl.readPixels(px-1, py-1, 3, 3, gl.RGBA, gl.UNSIGNED_BYTE, pixels);

				return pixels.reduce((sum, v, i) => sum+(i%4 < 2 ? v : 0), 0)/9;
			});
		};
	}, indexCount
	);

	await view.focus();
	await page.keyboard.press('j');

	const light = state => view.evaluate((el, name) => el.exhausts[name], state);
	await expect.poll(() => light('cruise')).toBeTruthy();
	await page.keyboard.down('w');
	await expect.poll(() => light('full')).toBeTruthy();
	await page.keyboard.up('w');
	await page.keyboard.down('s');
	await expect.poll(() => light('idle')).toBeTruthy();
	await page.keyboard.up('s');
	const [idle,cruise,full] = await Promise.all(['idle','cruise','full'].map(light));

	for(let nozzle = 0; nozzle<2; nozzle++)
	{
		expect(cruise[nozzle]).toBeGreaterThan(idle[nozzle]+10);
		expect(full[nozzle]).toBeGreaterThan(cruise[nozzle]+25);
	}
});

test('F5 underwing models leave and refill on their own wings', async({page}) => {
	await boot(page);
	const airspace = fixture();
	airspace.landscape.recipe.worldSize = 8192;
	airspace.camera = {position: [0,1500,0], yaw: 0, pitch: 0};
	await load(page, airspace);

	const counts = await Promise.all(
		['f5','projectile'].map(
			async name => gunzipSync(
				await fs.readFile(new URL(`../../games/terrain-sandbox/assets/${name}/mesh.bin.gz`, import.meta.url))
			).readUInt32LE(4)
		)
	);

	const view = canvas(page);

	await view.evaluate((el, [jetCount,projectileCount]) => {
		const gl = el.getContext('webgl2'), draw = gl.drawElements.bind(gl);
		let body;
		el.mounted = [];

		gl.drawElements = (...args) => {
			if(args[1] === jetCount || args[1] === projectileCount)
			{
				const p = gl.getParameter(gl.CURRENT_PROGRAM);
				const origin = gl.getUniform(p, gl.getUniformLocation(p, 'uOrigin'));

				if(args[1] === jetCount)
				{
					body = origin;
					el.mounted = [];
				}
				else if(body && Math.abs(origin[2]-body[2]) < 1 && origin[1] < body[1])
					el.mounted.push(origin[0] < body[0] ? 'left' : 'right');
			}

			return draw(...args);
		};
	}, counts
	);

	const wings = () => view.evaluate(el => el.mounted);
	await press(page, 'jet');
	await expect.poll(wings).toEqual(['left','right']);
	await view.focus();
	await page.keyboard.press('Space');
	await expect.poll(wings).toEqual(['right']);
	const firstLaunch = (await status(page)).balloons.elapsed;
	await expect.poll(async() => (await status(page)).balloons.elapsed-firstLaunch).toBeGreaterThan(.8);
	await page.keyboard.press('Space');
	await expect.poll(wings).toEqual([]);
	await expect.poll(wings, {intervals: [75]}).toEqual(['left']);
	await expect.poll(wings).toEqual(['left','right']);
});

test('F5 cockpit, charge and Vulcan controls work in balloon practice', async({page}) => {
	test.setTimeout(180_000);
	await page.setViewportSize({width: 720, height: 480});
	const errors = [];
	page.on('pageerror', error => errors.push(error.message));

	await page.addInitScript(() => {
		const buttons = Array.from({length: 17}, () => ({value: 0, pressed: false}));
		const pad = {
			id: 'F5 test controller'
			, index: 0
			, timestamp: 1
			, mapping: 'standard'
			, connected: true
			, axes: [0,0,0,0]
			, buttons
		};

		Object.defineProperty(navigator, 'getGamepads', {value: () => [pad]});

		window.testF5Button = (n, on) => {
			pad.timestamp++;
			buttons[n] = {value: Number(on), pressed: on, touched: on};
		};
	});

	await boot(page);
	const airspace = fixture();
	airspace.landscape.recipe.worldSize = 8192;
	airspace.camera = {position: [0,150,0], yaw: 0, pitch: 0};
	airspace.balloons = [[0,-1350,150],[0,-1750,150]];
	await load(page, airspace);
	await press(page, 'jet');
	await press(page, 'balloon-practice');

	const setButton = (n, on) => page.evaluate(([n,on]) => window.testF5Button(n, on), [n,on]);
	await setButton(11, true);
	await expect.poll(async() => (await status(page)).jetView).toBe('cockpit');
	await setButton(11, false);
	await expect(page.locator('.terrain-instruments')).toBeVisible();
	await expect(page.locator('.terrain-panel')).not.toHaveAttribute('open', '');
	const map = page.locator('.terrain-flight-map');

	await expect.poll(() => map.evaluate(el => {
		const pixels = el.getContext('2d').getImageData(0, 0, el.width, el.height).data;

		for(let i = 0; i < pixels.length; i += 4)
		{
			const x = (i/4)%el.width;

			if(Math.abs(x-el.width/2) > .12*el.width
				&& pixels[i] === 255 && pixels[i+1] === 99 && pixels[i+2] === 87
			) return true;
		}

		return false;
	})).toBe(true);

	expect((await status(page)).balloons.locked).toBe(false);
	// Cycle from the distant practice blip to the first of two authored balloons on this flight path.
	await canvas(page).focus();
	await page.keyboard.press('t');
	await expect.poll(async() => (await status(page)).balloons.locked).toBe(true);

	// The authored balloon is straight ahead. Read the rendered impact at the center of the 3D canvas,
	// before the cockpit overlay, and compare the same pixels immediately before the effect draw.
	await canvas(page).evaluate(el => {
		const gl = el.getContext('webgl2'), draw = gl.drawArraysInstanced.bind(gl);
		const before = new Uint8Array(48*48*4), after = new Uint8Array(before.length);

		const read = pixels => gl.readPixels(
			Math.floor(gl.drawingBufferWidth/2)-24
			, Math.floor(gl.drawingBufferHeight/2)-24
			, 48
			, 48
			, gl.RGBA
			, gl.UNSIGNED_BYTE
			, pixels
		);

		gl.drawArraysInstanced = (...args) => {
			if(el.sawBurst || args[1] !== 0 || args[2] !== 72 || !JSON.parse(el.dataset.terrainSandbox).balloons.hit)
				return draw(...args);

			read(before);
			draw(...args);
			read(after);

			for(let i = 0; i < after.length; i += 4)
				if(after[i] > 190 && after[i] > after[i+2]+70 && after[i] > before[i]+35)
				{
					el.sawBurst = true;
					break;
				}
		};
	});

	await setButton(0, true);
	await expect.poll(async() => (await status(page)).balloons.hits, {timeout: 60_000}).toBe(1);
	await setButton(0, false);
	await expect.poll(() => canvas(page).evaluate(el => el.sawBurst)).toBe(true);
	await expect.poll(async() => (await status(page)).balloons.locked).toBe(true);
	await setButton(2, true);
	await expect.poll(async() => (await status(page)).balloons.hits, {timeout: 60_000}).toBe(2);
	await setButton(2, false);
	expect((await status(page)).headlights).toBe('auto');
	await canvas(page).focus();
	await page.keyboard.press('p');
	await expect.poll(async() => (await status(page)).paused).toBe(true);
	const before = (await status(page)).balloons;
	await restoreContext(page);
	expect((await status(page)).balloons).toEqual(before);
	expect(await canvas(page).evaluate(el => el.getContext('webgl2').getError())).toBe(0);
	await expect(map).toBeVisible();
	await setButton(10, true);
	await expect(map).toBeHidden();
	await setButton(10, false);
	await page.keyboard.press('m');
	await expect(map).toBeVisible();

	await setButton(13, true);
	await expect.poll(async() => (await status(page)).jetWideMap).toBe(true);
	await setButton(13, false);

	const color = async() => {
		const image = PNG.sync.read(await map.screenshot());
		const offset = 4 * (24 * image.width + 23);

		return Array.from(image.data.subarray(offset, offset + 4));
	};

	await canvas(page).evaluate(el => el.terrainSandboxDebug.values({waterColor: '#247dd1', waterLevel: 25}));
	await expect.poll(async() => { const [r,g,b] = await color(); return b > g && g > r+40; }).toBe(true);
	await canvas(page).evaluate(el => el.terrainSandboxDebug.values({waterLevel: -1, sandColor: '#d38129'}));
	await expect.poll(async() => { const [r,g,b] = await color(); return r > g+40 && g > b+40; }).toBe(true);

	await setButton(3, true);
	await setButton(1, true);
	await expect.poll(async() => (await status(page)).jetView).toBe('chase');
	await setButton(1, false);
	await setButton(3, false);
	await expect(page.locator('.terrain-panel')).toHaveAttribute('open', '');

	await setButton(8, true);
	await setButton(13, true);
	await expect.poll(async() => (await status(page)).mode).toBe('fly');
	await setButton(13, false);
	await setButton(8, false);
	await expect(page.locator('.terrain-instruments')).toBeHidden();
	await expect(map).toBeHidden();
	expect((await status(page)).jetControls).toBe('novice');
	expect(errors).toEqual([]);
});

async function installCarPad(page)
{
	await page.addInitScript(() => {
		const buttons = Array.from({length: 17}, () => ({value: 0, pressed: false}));
		const pad = {
			id: 'Car test controller'
			, index: 0
			, timestamp: 1
			, mapping: 'standard'
			, connected: true
			, axes: [0,0,0,0]
			, buttons
		};

		Object.defineProperty(navigator, 'getGamepads', {value: () => [pad]});

		window.testCarButton = (n, pressed) => {
			buttons[n] = {value: Number(pressed), pressed};
			pad.timestamp++;
		};
	});
}

test('tight road bends and checkpoint banners return the headlight beam at night', async({page}, testInfo) => {
	test.setTimeout(180_000);
	await page.setViewportSize({width: 720,height: 420});
	const errors = [];
	page.on('pageerror', error => errors.push(error.message));
	await boot(page);

	const arc = Array.from({length: 7}, (_, i) => {
		const angle = i*Math.PI/12;

		return [60*(Math.cos(angle)-1),0,60*Math.sin(angle)];
	});

	const road = {
		type: 'road'
		, points: [[0,0,-110],[0,0,-52],...arc,[-115,0,60]]
		, width: 12
		, shoulder: 5
		, material: 'road'
	};

	const doc = fixture({history: [[road]],revision: 1});
	doc.landscape.recipe.worldSize = 512;
	doc.settings.waterLevel = -5;
	doc.camera = {position: [0,5,-55],yaw: Math.PI,pitch: -.035};
	doc.course = {gates: [{role: 'checkpoint',kind: 'road',x: 0,y: 0,z: 15,yaw: 0,width: 20,height: 4,number: 1}]};
	await load(page, doc);
	const view = canvas(page);
	await expect.poll(async() => (await status(page)).renderer.curveSignsDrawn).toBeGreaterThan(12);
	await press(page, 'car');
	await expect.poll(async() => (await status(page)).mode).toBe('car');
	await view.focus();
	await page.keyboard.press('p');
	await expect.poll(async() => (await status(page)).paused).toBe(true);
	const panel = page.locator('.terrain-panel');

	if(await panel.evaluate(el => el.open)) await panel.locator('summary').first().click();

	async function frame(name)
	{
		const bytes = await view.screenshot();
		await testInfo.attach(name, {body: bytes,contentType: 'image/png'});

		return PNG.sync.read(bytes);
	}

	const day = await frame('day');
	await view.evaluate(el => el.terrainSandboxDebug.values({elevation: -12,headlights: 'off'}));

	await expect.poll(async() => {
		const s = await status(page);

		return [s.settings.elevation,s.headlights,s.renderer.spotLights];
	}).toEqual([-12,'off',0]);

	const dark = await frame('night-off');
	await view.evaluate(el => el.terrainSandboxDebug.values({headlights: 'on'}));
	await expect.poll(async() => (await status(page)).renderer.spotLights).toBe(2);
	const lit = await frame('night-on');
	// The printed center of the checkpoint crosses the straight approach, inside the two rows of signs.
	let banner = 0;

	for(let y = Math.floor(day.height*.25); y < day.height*.58; y++)
	for(let x = Math.floor(day.width*.46); x < day.width*.54; x++)
	{
		const i = (y*day.width+x)*4;

		if(lit.data[i]>dark.data[i]+55 && lit.data[i+1]>dark.data[i+1]+55 && lit.data[i+2]>dark.data[i+2]+45) banner++;
	}

	expect(banner).toBeGreaterThan(22);
	// Count the face pixels that are yellow by day, dark without the beam, and bright yellow under headlamps.
	let yellow = 0, returned = 0;

	for(let y = Math.floor(day.height*.25); y < day.height*.65; y++)
	for(let x = Math.floor(day.width*.18); x < day.width*.82; x++)
	{
		const i = (y*day.width+x)*4;

		if(day.data[i]<140 || day.data[i+1]<115 || day.data[i+2]>.55*day.data[i+1]) continue;

		yellow++;

		if(lit.data[i] > dark.data[i]+75 && lit.data[i+1] > dark.data[i+1]+60 && lit.data[i+2] < lit.data[i+1])
		returned++;
	}

	expect(yellow).toBeGreaterThan(40);
	expect(returned).toBeGreaterThan(25);
	await load(page, fixture());
	await expect.poll(async() => (await status(page)).renderer.curveSigns).toBe(0);
	expect(errors).toEqual([]);
});

test('the local racer records checkpoint and finish splits in the packaged sandbox', async({page}) => {
	await page.setViewportSize({width: 480, height: 300});

	await page.addInitScript(() => {
		const tracks = [], createSource = AudioContext.prototype.createMediaElementSource;
		window.finishMusic = [];

		AudioContext.prototype.createMediaElementSource = function(media) {
			tracks.push(media);

			const snapshot = () => window.finishMusic.push({
				type: media.paused ? 'pause' : 'play'
				, source: media.currentSrc
				, at: media.currentTime
				, others: tracks.filter(other => other !== media && !other.paused).map(other => other.currentSrc)
			});

			media.addEventListener('playing', snapshot);
			media.addEventListener('pause', snapshot);

			return createSource.call(this, media);
		};
	});

	await installCarPad(page);
	await boot(page);

	const road = {type: 'road',points: [[0,0,-48],[0,0,48]],width: 6,shoulder: 2,material: 'road'};
	const doc = fixture({history: [[road]], revision: 1});
	doc.settings.waterLevel = -5;
	doc.camera = {position: [10, 15, 0], yaw: Math.PI, pitch: -.35};
	doc.course = {
		gates: [
			{role: 'start', kind: 'road', x: 0, y: 0, z: 8, yaw: 0, width: 32, height: 4, laps: 1}
			, {role: 'checkpoint', kind: 'road', x: 0, y: 0, z: 32, yaw: 0, number: 1}
			, {role: 'finish', kind: 'road', x: 0, y: 0, z: 46, yaw: 0}
		]
	};

	await load(page, doc);
	await press(page, 'car');
	await expect.poll(async() => (await status(page)).mode).toBe('car');
	await canvas(page).focus();
	await page.keyboard.down('w');

	const readout = page.locator('[data-race]');

	try
	{
		await expect.poll(async() => (await status(page)).race.state).toBe('running');
		await page.evaluate(() => window.testCarButton(3, true));
		await expect(readout).toContainText('Lap 1');
		await expect(readout).toHaveText(/Next: .*?, \d+ yd/);
		await page.evaluate(() => window.testCarButton(3, false));
		await page.evaluate(() => window.testCarButton(11, true));
		await expect.poll(async() => (await status(page)).car.recoveries).toBe(1);
		await page.evaluate(() => window.testCarButton(11, false));
		await page.keyboard.down('Shift');
		await expect(readout).toHaveText(/^Finished in /, {timeout: 90_000});
		const {race} = await status(page);

		expect(race.state).toBe('finished');
		expect(race.guidance).toBe(false);
		await expect(readout).toBeVisible();
		await expect(readout).toHaveText(race.message);
		await expect(readout).toHaveCSS('animation-name', 'terrain-finish-pulse');
		await page.emulateMedia({reducedMotion: 'reduce'});
		await expect(readout).toHaveCSS('animation-name', 'none');
	}
	finally
	{
		await page.keyboard.up('Shift');
		await page.keyboard.up('w');
	}

	const {race} = await status(page);
	expect(race.vehicle).toBe('car');
	await expect(readout).toBeHidden({timeout: 10_000});
	await expect(page.locator('[data-status]')).toBeHidden();
	expect(race.splits.map(split => split.role)).toEqual(['checkpoint', 'finish']);
	expect(race.time).toBeGreaterThan(race.splits[0].time);
	const events = await page.evaluate(() => window.finishMusic);
	const cue = events.findIndex(e => e.type === 'play' && e.source.endsWith('/race-finish.wav'));
	expect(cue).toBeGreaterThan(-1);
	expect(events[cue].others.some(src => src.endsWith('.mp3'))).toBe(true);
	const parked = events.slice(cue+1).find(e => e.type === 'pause' && e.source.endsWith('.mp3'));
	const resumed = events.slice(cue+1).find(e => e.type === 'play' && e.source === parked?.source);
	expect(parked).toBeDefined();
	expect(resumed?.at).toBeCloseTo(parked.at, 0);
	expect(resumed.others.some(src => src.endsWith('/race-finish.wav'))).toBe(true);

	await canvas(page).focus();
	await page.keyboard.press('r');
	await expect.poll(async() => (await status(page)).race.state).toBe('idle');
	const restarted = await status(page);
	expect(restarted.car.z).toBeLessThan(doc.course.gates[0].z);
	expect(restarted.car.speed).toBe(0);
	expect(restarted.race.splits).toEqual([]);
	expect(restarted.race.best).toBe(race.best);
});

test('the rendered arrow keeps an outside lane straight past an invisible waypoint', async({page}) => {
	await page.setViewportSize({width: 480, height: 300});

	await page.addInitScript(() => {
		const gl = WebGL2RenderingContext.prototype, bearings = new WeakSet();
		const getLocation = gl.getUniformLocation, uniform = gl.uniform2f;
		window.waypointPass = [];

		gl.getUniformLocation = function(program, name) {
			const location = getLocation.call(this, program, name);

			if(location && name === 'bearing') bearings.add(location);

			return location;
		};

		gl.uniform2f = function(location, sine, cosine) {
			if(bearings.has(location))
			{
				const state = this.canvas.terrainSandboxDebug?.state;

				if(state?.race?.state === 'running' && state.car.z >= 70 && state.car.z <= 145)
					window.waypointPass.push([state.car.x,state.car.z,Math.atan2(sine, cosine)]);
			}

			return uniform.call(this, location, sine, cosine);
		};
	});

	await boot(page);
	const road = {type: 'road',points: [[4,0,-40],[4,0,280]],width: 36,shoulder: 12};
	const doc = fixture({history: [[road]],revision: 1});
	doc.landscape.recipe.worldSize = 1024;
	doc.settings.waterLevel = -5;
	doc.camera = {position: [18,5,-20],yaw: Math.PI,pitch: 0};
	doc.circuit = {variant: 'dry',route: {points: [[0,0],[0,240],[240,240],[240,0]],dryLevel: -5,floodedLevel: null}};
	const gate = {kind: 'road',y: 0,width: 13.324,height: 6.146};
	doc.course = {
		gates: [
			{...gate,role: 'start',x: 4,z: 0,yaw: 0,laps: 1,width: 37.5}
			, {...gate,role: 'checkpoint',x: 120,z: 240,yaw: Math.PI/2,number: 1}
			, {...gate,role: 'checkpoint',x: 240,z: 120,yaw: Math.PI,number: 2}
		]
	};

	await load(page, doc);
	await press(page, 'car');
	await expect.poll(async() => (await status(page)).mode).toBe('car');
	await canvas(page).focus();
	await page.keyboard.down('w');

	try
	{
		await expect.poll(async() => (await status(page)).car.z, {timeout: 90_000}).toBeGreaterThan(145);
	}
	finally
	{
		await page.keyboard.up('w');
	}

	const pass = await page.evaluate(() => window.waypointPass);
	expect(pass.some(([,z]) => z<108)).toBe(true);
	expect(pass.some(([,z]) => z>120)).toBe(true);
	expect(pass.every(([x,,angle]) => Math.abs(x-18)<.1 && Math.abs(angle)<.02)).toBe(true);
	expect((await status(page)).race.splits).toEqual([]);
});

test('the overhead checkpoint arrow points down for forward and enlarges its near end', async({page}) => {
	await page.setViewportSize({width: 760,height: 460});
	await boot(page);
	const doc = fixture();
	doc.settings.waterLevel = -5;
	doc.camera = {position: [0,15,0],yaw: 0,pitch: -.35};
	doc.landscape.recipe.worldSize = 512;
	await canvas(page).focus();
	await page.keyboard.press('h');

	const faceWidths = async z => {
		doc.course = {gates: [{role: 'start',kind: 'road',x: 0,y: 0,z,yaw: Math.PI,laps: 1}]};
		await load(page, doc);
		await canvas(page).focus();
		await page.keyboard.press('c');
		await expect.poll(async() => (await status(page)).mode).toBe('car');
		const {width,height,data} = PNG.sync.read(await canvas(page).screenshot());
		const rows = [];

		for(let y = 0; y<height*.22; y++)
		{
			let count = 0;

			for(let x = Math.floor(width*.4); x<width*.6; x++)
			{
				const i = 4*(y*width+x), r = data[i], g = data[i+1], b = data[i+2];

				if(r>205 && Math.abs(g-.8*r)<2 && Math.abs(b-84*r/255)<2) count++;
			}

			if(count) rows.push(count);
		}

		expect(rows.length).toBeGreaterThan(20);

		return {top: rows[0],bottom: rows.at(-1)};
	};

	const ahead = await faceWidths(-100), behind = await faceWidths(100);
	expect(ahead.bottom).toBeLessThan(ahead.top/2);
	expect(behind.top).toBeLessThan(behind.bottom/2);
	expect(ahead.top).toBeGreaterThan(1.8*behind.bottom);
});

test('a package run marks its terrain-hidden pickup and reveals smoke above the ridge', async({page}) => {
	const errors = [];
	page.on('pageerror', error => errors.push(error.message));
	await page.setViewportSize({width: 760,height: 460});

	await page.addInitScript(() => {
		const pad = {
			id: 'Delivery test controller'
			, index: 0
			, timestamp: 1
			, mapping: 'standard'
			, connected: true
			, axes: [0,0,0,0]
			, buttons: Array.from({length: 17}, () => ({pressed: false,value: 0}))
		};

		Object.defineProperty(navigator, 'getGamepads', {value: () => [pad]});

		window.lookAtPackage = () => {
			const {delivery,car} = document.querySelector('canvas[data-terrain-sandbox]').terrainSandboxDebug.state;
			const angle = Math.atan2(delivery.target.x-car.x, car.z-delivery.target.z)-(Math.PI-car.yaw);
			pad.axes[2] = Math.sin(angle);
			pad.axes[3] = -Math.cos(angle);
			pad.timestamp++;
		};
	});

	await boot(page);
	// A mile of dry causeway on either side puts both possible pickup plumes clear of the red depot smoke.
	// Water surrounds the causeway, so each qualifying box must lie on the same narrow road.
	const road = {
		type: 'road'
		, points: [[-1920,5,0],[0,5,0],[1920,5,0]]
		, width: 22
		, shoulder: 2
		, crown: 0
	};

	// Both exits cross a gentle crest above the crate. Its smoke clears the crest.
	const crests = [-96, 96].map(x => ({type: 'raise', x, z: 0, radius: 44, amount: 6}));
	const dry = fixture({history: [[road, ...crests]], revision: 1});

	dry.landscape.recipe.worldSize = 8192;
	dry.settings.waterLevel = 1;
	await load(page, dry);
	await press(page, 'delivery');
	await expect.poll(async() => (await status(page)).delivery?.target?.kind).toBe('pickup');
	const {delivery,car} = await status(page);
	const {target,depot} = delivery;
	const yards = Math.round(Math.hypot(target.x-car.x, target.z-car.z)/.9144);
	await expect(page.locator('[data-race]')).toContainText(`Pickup · ${yards} yd`);
	expect(Math.abs(target.x-depot.x)).toBeGreaterThanOrEqual(1609.344);
	expect(target.z).toBe(depot.z);
	await canvas(page).focus();
	await page.keyboard.press('h');

	const lime = (r, g, b) => g>120 && g>r*1.15 && r>80 && b<g*.35;

	const count = async(arrow) => {
		const {width,height,data} = PNG.sync.read(await canvas(page).screenshot());
		let match = 0;

		for(let y = 0; y<height*.48; y++)
		for(let x = width>>2; x<width*.75; x++)
		{
			if((y < height*.21 && Math.abs(x-width/2)<width*.14) !== arrow) continue;

			const i = 4*(y*width+x);

			if(lime(data[i], data[i+1], data[i+2])) match++;
		}

		return match;
	};

	expect(await count(true)).toBeGreaterThan(40);
	expect(await count(false)).toBe(0);
	const map = page.locator('.terrain-flight-map');

	const markers = await map.evaluate(el => {
		const rgba = el.getContext('2d').getImageData(0, 0, el.width, el.height).data;

		const has = (r, g, b) => rgba.some((v, i) => i%4===0 && v===r && rgba[i+1]===g && rgba[i+2]===b);

		return {pickup: has(202, 255, 24),depot: has(255, 53, 40)};
	});

	expect(markers).toEqual({pickup: true,depot: true});
	await page.evaluate(() => window.lookAtPackage());
	await expect.poll(async() => await count(false), {timeout: 30000}).toBeGreaterThan(12);
	expect(errors).toEqual([]);
});

test('the car refines roadside banks through the middle rings and releases the near grid off road', async({page}) => {
	await page.setViewportSize({width: 720,height: 460});
	await boot(page);

	const cut = fixture(
		{history: [[{type: 'road',points: [[0,-8,-900],[0,-8,900]],width: 18,shoulder: 6}]],revision: 1}
	);

	Object.assign(cut.landscape.recipe, {worldSize: 2048,spacing: 1});
	Object.assign(cut.settings, {waterLevel: -40,quality: 1});
	cut.camera = {position: [0,8,0],yaw: 0,pitch: -.25};
	await load(page, cut);
	expect((await settled(page)).renderer.roadsideCoverage).toBeNull();
	await press(page, 'car');
	await expect.poll(async() => (await status(page)).renderer.roadsideCoverage).not.toBeNull();
	const near = (await status(page)).renderer;
	expect(near.roadsideRings).toHaveLength(4);

	for(const i of [1,2,3])
	{
		const [baseX,baseZ,width,spacing] = near.visibleCoverage[i];
		const [left,top,right,bottom] = near.roadsideRings[i];
		expect(spacing).toBe(2**i);
		expect(top).toBeLessThan(baseZ);
		expect(bottom).toBeGreaterThan(baseZ+width);

		if(i>=2)
		{
			// The straight road extends north/south. Its banks fit inside the regular width.
			expect(left).toBe(baseX);
			expect(right).toBe(baseX+width);
		}
	}

	const [x,z,span] = near.visibleCoverage[0];
	const [left,top,right,bottom] = near.roadsideCoverage;
	expect(left).toBeLessThanOrEqual(x);
	expect(right).toBeGreaterThanOrEqual(x+span);
	expect(Math.max(x-left, z-top, right-x-span, bottom-z-span)).toBeGreaterThan(0);
	await press(page, 'fly');
	await expect.poll(async() => (await status(page)).renderer.roadsideCoverage).toBeNull();

	cut.camera.position = [350,8,0];
	await load(page, cut);
	await press(page, 'car');
	await expect.poll(async() => (await status(page)).mode).toBe('car');
	await expect.poll(async() => (await status(page)).renderer.visibleCoverage[0]?.[2]).toBe(span);
	expect((await status(page)).renderer.roadsideCoverage).toBeNull();
});

test('a shoreline wipeout restarts the car on its approach', async({page}) => {
	await page.setViewportSize({width: 640, height: 400});
	await boot(page);
	const road = {type: 'road', points: [[0,8,-45],[0,0,45]], width: 10, shoulder: 1, crown: 0};
	const shore = fixture({history: [[road]], revision: 1});
	Object.assign(shore.landscape.recipe, {spacing: 1, waterLevel: 1});
	Object.assign(shore.settings, {waterLevel: 1, waterChoppiness: 0});
	shore.camera = {position: [0,20,0], yaw: Math.PI, pitch: -.4};
	await load(page, shore);
	await press(page, 'car');
	await expect.poll(async() => (await status(page)).mode).toBe('car');

	if((await status(page)).paused) await press(page, 'pause');

	await canvas(page).focus();
	await page.keyboard.down('w');
	await expect.poll(async() => (await status(page)).car.recoveries, {timeout: 60_000}).toBeGreaterThan(0);
	await page.keyboard.up('w');
	const state = await status(page);
	expect(state.mode).toBe('car');
	expect(state.car.z).toBeGreaterThan(0);
	expect(state.car.y).toBeGreaterThan(1);
	await expect(page.locator('[data-status]')).toContainText('Engine flooded');
});

test('a racing water wipeout restarts on the course road across the dirt from the shore', async({page}) => {
	await page.setViewportSize({width: 480,height: 300});
	await boot(page);
	const road = {type: 'road',points: [[0,0,-60],[0,0,-20]],width: 10,shoulder: 2};
	const lake = {type: 'lower',x: 0,z: 20,radius: 20,amount: 5};
	const doc = fixture({history: [[road,lake]],revision: 1});
	Object.assign(doc.landscape.recipe, {spacing: 1,waterLevel: -1});
	Object.assign(doc.settings, {waterLevel: -1,waterChoppiness: 0});
	doc.camera = {position: [0,20,-48],yaw: Math.PI,pitch: -.4};
	doc.course = {
		gates: [
			{role: 'start',kind: 'road',x: 0,y: 0,z: -35,yaw: 0,laps: 1}
			, {role: 'checkpoint',kind: 'road',x: 25,y: 0,z: 42,yaw: 0,number: 1}
		]
	};

	await load(page, doc);
	await press(page, 'car');
	await expect.poll(async() => (await status(page)).mode).toBe('car');
	await canvas(page).focus();
	await page.keyboard.down('w');

	try
	{
		await expect.poll(async() => (await status(page)).race.state).toBe('running');
		await expect.poll(async() => (await status(page)).car.z, {timeout: 60_000}).toBeGreaterThan(0);
		await expect.poll(async() => (await status(page)).car.floodedHold, {timeout: 60_000}).toBe(true);
	}
	finally
	{
		await page.keyboard.up('w');
	}

	const {car,race} = await status(page);
	expect(car.x).toBeCloseTo(0);
	expect(car.z).toBeGreaterThanOrEqual(-60);
	expect(car.z).toBeLessThanOrEqual(-20);
	expect(car.yaw).toBeCloseTo(0);
	expect(car.roadHold).toBeGreaterThan(0);
	expect(race.state).toBe('running');
	expect(race.next.number).toBe(1);
	expect(race.splits).toEqual([]);
});

test('drive the Testarossa from a dry clearing and switch vehicles', async({page}) => {
	test.setTimeout(180_000);
	const errors = [];
	page.on('pageerror', error => errors.push(error.message));
	await page.setViewportSize({width: 900, height: 600});

	await installCarPad(page);

	await boot(page);
	const dry = fixture();
	dry.landscape.recipe.worldSize = 512;
	dry.settings.waterLevel = -4;
	dry.camera = {position: [0,30,0], yaw: Math.PI, pitch: -.4};
	await load(page, dry);
	const cameraWidth = (await settled(page)).renderer.visibleCoverage[0][2];
	const view = canvas(page);

	const mesh = await fs.readFile(
		new URL('../../games/terrain-sandbox/assets/testarossa/mesh.bin.gz', import.meta.url)
	);

	await view.evaluate((element, count) => {
		const gl = element.getContext('webgl2'), draw = gl.drawElements.bind(gl);
		const viewport = gl.viewport.bind(gl);
		const triangle = gl.drawArrays.bind(gl);
		let chartDraw = false;
		element.carDraws = 0;

		gl.viewport = (x, y, w, h) => {
			chartDraw = x === 10 && y === 10;

			if(chartDraw) element.chartSize = [w,h];

			return viewport(x, y, w, h);
		};

		gl.drawArrays = (...args) => {
			if(!chartDraw || element.chartSamples) return triangle(...args);

			const [size] = element.chartSize;

			const pixels = () => {
				const data = new Uint8Array(size*size*4);
				gl.readPixels(10, 10, size, size, gl.RGBA, gl.UNSIGNED_BYTE, data);

				return new Uint32Array(data.buffer);
			};

			const before = pixels();
			triangle(...args);
			const after = pixels(), mid = Math.floor(size/2);

			// The center and four cardinal points get terrain. All four corners keep the driving scene.
			element.chartSamples = [[mid,mid],[mid,3],[mid,size-4],[3,mid],[size-4,mid]
				, [3,3],[3,size-4],[size-4,3],[size-4,size-4]
			].map(([x,y]) => before[y*size+x] !== after[y*size+x]);
		};

		gl.drawElements = (...args) => {
			if(args[1] === count)
			{
				element.carDraws++;

				if(!element.splitFrontSteering)
				{
					const program = gl.getParameter(gl.CURRENT_PROGRAM);
					const location = gl.getUniformLocation(program, 'uWheelAngles');
					const angles = location && gl.getUniform(program, location);
					element.carProgram = program;
					element.splitFrontSteering = angles?.length === 4 && angles[1] < angles[2] && angles[2] < 0;
				}
			}

			return draw(...args);
		};
	}, gunzipSync(mesh).readUInt32LE(4));

	await press(page, 'car');
	await expect.poll(async() => (await status(page)).mode).toBe('car');
	await expect.poll(() => view.evaluate(element => element.carDraws)).toBeGreaterThan(0);
	await expect.poll(async() => (await status(page)).renderer.visibleCoverage[0]?.[2]).toBeGreaterThan(cameraWidth);
	const map = page.locator('.terrain-flight-map'), dial = page.locator('.terrain-speedometer');
	await expect(map).toBeVisible();
	await expect(dial).toHaveAttribute('aria-label', 'Speedometer, 0 miles per hour, nitrous 100%');
	await expect(page.locator('.terrain-instruments')).toBeHidden();
	const viewport = await view.boundingBox(), mapBox = await map.boundingBox(), dialBox = await dial.boundingBox();
	// The car's nitrous arc adds top/left padding outside the matching instrument faces.
	expect(dialBox.width).toBe(mapBox.width + Math.ceil(mapBox.width*9/160));

	const diameter = await dial.evaluate((el, size) => {
		// Across the lower rim, the solid face fills the full diameter, including the lower-right tangent.
		const pad = el.width-size, y = pad+Math.floor(size/2);
		const rgba = el.getContext('2d').getImageData(pad, y, size, 1).data;

		return rgba.filter((value, i) => i%4 === 3 && value > 100).length;
	}, mapBox.width
	);

	expect(diameter).toBe(mapBox.width);
	expect(mapBox.width).toBe(mapBox.height);
	expect(await view.evaluate(el => el.chartSamples)).toEqual([true,true,true,true,true,false,false,false,false]);

	const outline = await map.evaluate(el => {
		const ctx = el.getContext('2d'), mid = Math.floor(el.width/2);

		return [[2,2],[el.width-3,el.height-3],[mid,1]].map(([x,y]) => ctx.getImageData(x, y, 1, 1).data[3]);
	});

	expect(outline.slice(0, 2)).toEqual([0,0]);
	expect(outline[2]).toBeGreaterThan(200);
	await expect.poll(() => view.evaluate(element => element.chartSize)).toEqual([mapBox.width,mapBox.height]);
	expect(mapBox.x-viewport.x).toBeCloseTo(10);
	expect(viewport.x+viewport.width-dialBox.x-dialBox.width).toBeCloseTo(10);

	for(const box of [mapBox,dialBox])
		expect(viewport.y+viewport.height-box.y-box.height).toBeCloseTo(10);
	const statusBox = await page.locator('.terrain-hud').boundingBox();
	expect(statusBox.x).toBeGreaterThan(mapBox.x+mapBox.width);
	expect(statusBox.x+statusBox.width).toBeLessThan(dialBox.x);

	const needle = () => dial.evaluate(element => {
		const size = element.width, ctx = element.getContext('2d');
		const pixels = ctx.getImageData(0, 0, size, size).data;
		let cx = 0, cy = 0, count = 0;

		for(let i = 0; i < pixels.length; i += 4)
		{
			if(pixels[i] !== 217 || pixels[i+1] !== 53 || pixels[i+2] !== 46) continue;

			cx += i/4 % size;
			cy += Math.floor(i/4/size);
			count++;
		}

		cx /= count;
		cy /= count;
		let xSum = 0, ySum = 0;

		for(let y = 0; y < size; y++)
		for(let x = 0; x < size; x++)
		{
			const i = 4*(y*size+x);

			if(Math.hypot(x-cx, y-cy) < .13*size || pixels[i] < 200 || pixels[i+1] > 90) continue;

			xSum += x-cx;
			ySum += y-cy;
		}

		return (Math.atan2(ySum, xSum) + 2*Math.PI) % (2*Math.PI);
	});

	expect(await needle()).toBeCloseTo(.75*Math.PI, 1);
	const parkedCarDial = await dial.evaluate(element => element.toDataURL());
	await expect(control(page, 'car')).toBeHidden();
	await expect(control(page, 'fly')).toBeVisible();
	await view.focus();
	await page.keyboard.press('m');
	await expect(map).toBeHidden();
	await expect(dial).toBeVisible();

	const setButton = (n, on) => page.evaluate(([n,on]) => window.testCarButton(n, on), [n,on]);
	await setButton(10, true);
	await expect(map).toBeVisible();
	await setButton(10, false);
	await setButton(13, true);
	await expect.poll(async() => (await status(page)).carWideMap).toBe(true);
	expect((await status(page)).mode).toBe('car');
	await setButton(13, false);
	await page.keyboard.press('n');
	await expect.poll(async() => (await status(page)).carWideMap).toBe(false);

	await dial.evaluate(element => {
		const ctx = element.getContext('2d'), arc = ctx.arc.bind(ctx);
		let previous = 100;
		element.fractionalCharge = {draining: false, filling: false};

		ctx.arc = (...args) => {
			if(ctx.strokeStyle === '#caff18' && args[2] === 85)
			{
				const percent = 100 * (args[4] - args[3]) / (Math.PI * 2/3);

				if(Math.abs(percent - Math.round(percent)) > 1e-6)
				{
					if(percent < previous) element.fractionalCharge.draining = true;
					else if(percent > previous) element.fractionalCharge.filling = true;
				}

				previous = percent;
			}

			return arc(...args);
		};
	});

	const chargeMotion = () => dial.evaluate(element => element.fractionalCharge);

	const renderedFov = () => view.evaluate(element => {
		const gl = element.getContext('webgl2'), program = element.carProgram;
		const matrix = gl.getUniform(program, gl.getUniformLocation(program, 'uViewProjection'));

		return 2 * Math.atan(1 / Math.hypot(matrix[1], matrix[5], matrix[9])) * 180 / Math.PI;
	});

	expect(await renderedFov()).toBeCloseTo(60, 3);
	await page.keyboard.down('w');
	await expect.poll(renderedFov, {timeout: 30_000}).toBeGreaterThan(66.5);
	expect((await status(page)).car.nitrous).toBe(1);

	await page.keyboard.down('d');
	await setButton(4, true);
	await expect.poll(async() => (await status(page)).car.nitrous).toBeLessThan(.99);
	await expect(dial).toHaveAttribute('aria-label', /nitrous \d{1,2}%/);
	await expect.poll(renderedFov, {timeout: 30_000}).toBeGreaterThan(74);

	await setButton(4, false);
	await expect.poll(chargeMotion).toEqual({draining: true, filling: true});
	expect(await renderedFov()).toBeGreaterThan(72.5);
	await expect.poll(async() => (await status(page)).car.x).toBeLessThan(-.2);
	await expect.poll(needle).toBeGreaterThan(.75*Math.PI + .15);
	await expect.poll(() => view.evaluate(element => element.splitFrontSteering)).toBe(true);
	await page.keyboard.up('d');
	await page.keyboard.up('w');
	await page.keyboard.down('s');
	await expect.poll(renderedFov, {timeout: 30_000}).toBeLessThan(67.2);
	await page.keyboard.up('s');

	const beforeRestore = await view.evaluate(element => element.carDraws);
	await restoreContext(page);
	await expect.poll(() => view.evaluate(element => element.carDraws)).toBeGreaterThan(beforeRestore);
	expect(await view.evaluate(element => element.getContext('webgl2').getError())).toBe(0);
	await view.focus();
	await setButton(8, true);
	await setButton(13, true);
	await expect.poll(async() => (await status(page)).mode).toBe('fly');
	await setButton(13, false);
	await setButton(8, false);
	await expect(map).toBeHidden();
	await expect(dial).toBeHidden();
	await expect.poll(async() => (await status(page)).renderer.visibleCoverage[0]?.[2]).toBe(cameraWidth);
	await page.keyboard.press('c');
	await expect.poll(async() => (await status(page)).mode).toBe('car');
	await (await field(page, '[data-value="waterLevel"]')).fill('10');
	await expect.poll(async() => (await status(page)).mode).toBe('fly');
	await press(page, 'craft');
	await expect.poll(async() => (await status(page)).mode).toBe('craft');
	await expect(dial).toBeVisible();
	await expect(dial).toHaveAttribute('aria-label', 'Speedometer, 0 miles per hour');
	// Switching vehicles at the same viewport size must repaint the speed markings.
	expect(await dial.evaluate((element, car) => element.toDataURL() === car, parkedCarDial)).toBe(false);
	await press(page, 'car');
	await expect.poll(async() => (await status(page)).message).toContain('No dry');
	expect((await status(page)).mode).toBe('craft');
	expect(errors).toEqual([]);
});

test('the Testarossa lights its lamps and the page keeps the headlight switch', async({page}) => {
	test.setTimeout(180_000);
	const errors = [];
	page.on('pageerror', error => errors.push(error.message));
	await boot(page);

	const dry = fixture();
	Object.assign(dry.landscape.recipe, {worldSize: 512});
	dry.settings.waterLevel = -4;
	dry.camera = {position: [0,30,0], yaw: Math.PI, pitch: -.4};
	await load(page, dry);
	await press(page, 'car');
	await expect.poll(async() => (await status(page)).mode).toBe('car');

	await canvas(page).focus();
	await page.keyboard.down('w');
	await expect.poll(async() => (await status(page)).car.speed, {timeout: 60_000}).toBeGreaterThan(12);
	await page.keyboard.up('w');
	await page.keyboard.down('s');

	const braking = async() => {
		const {car, lamps} = await status(page);

		return car.braking && !car.reversing && lamps.brake;
	};

	await expect.poll(braking, {intervals: [100], timeout: 60_000}).toBe(1);
	await expect.poll(async() => (await status(page)).car.reversing, {timeout: 60_000}).toBe(true);
	await page.keyboard.up('s');

	const headlights = page.locator('.terrain-sandbox [data-value="headlights"]');
	await expect(headlights).toHaveValue('auto');
	await page.keyboard.press('l');
	await expect.poll(async() => (await status(page)).headlights).toBe('on');
	await expect(headlights).toHaveValue('on');
	expect((await status(page)).message).toBe('Headlights on.');
	await expect.poll(async() => (await status(page)).lamps.pods, {timeout: 60_000}).toBe(1);
	// By day the beams keep their physical brightness, so each open headlamp is already a spot light.
	await expect.poll(async() => (await status(page)).renderer.spotLights, {timeout: 30_000}).toBe(2);
	expect(await page.evaluate(() => localStorage.getItem('terrain-sandbox.car'))).toBe('{"headlights":"on"}');

	// After sunset they stay the same two lights; only the night gain changes.
	await page.locator('.terrain-sandbox [data-value="elevation"]').evaluate(element => {
		element.value = -12;
		element.dispatchEvent(new Event('input', {bubbles: true}));
	});

	await expect.poll(async() => (await status(page)).renderer.spotLights, {timeout: 30_000}).toBe(2);

	// While paused, the switch opens and closes the pods at once.
	await page.keyboard.press('p');
	await expect.poll(async() => (await status(page)).paused).toBe(true);

	const lit = async() => {
		const {paused, headlights: setting, lamps, renderer} = await status(page);

		return [paused, setting, lamps.headlamps, lamps.pods, renderer.spotLights];
	};

	await page.keyboard.press('l');
	await expect.poll(lit).toEqual([true, 'off', 0, 0, 0]);
	await page.keyboard.press('l');
	await expect.poll(lit).toEqual([true, 'auto', 1, 1, 2]);
	await page.keyboard.press('l');
	await expect.poll(lit).toEqual([true, 'on', 1, 1, 2]);
	await page.keyboard.press('p');
	await expect.poll(async() => (await status(page)).paused).toBe(false);
	await expect(headlights).toHaveValue('on');

	await page.reload();
	await expect(canvas(page)).toHaveAttribute('data-terrain-sandbox', /"recipe":/, {timeout: 60_000});
	await expect.poll(async() => (await status(page)).headlights).toBe('on');
	await expect(headlights).toHaveValue('on');
	expect((await status(page)).message).not.toContain('Headlights');
	expect((await status(page)).renderer.spotLights).toBe(0);
	expect(errors).toEqual([]);
});

test('both taillights brighten visibly in daylight for running lights and braking', async({page}) => {
	await page.setViewportSize({width: 820, height: 510});
	await page.goto('/games/terrain-sandbox/');
	const view = canvas(page);
	await expect(view).toHaveAttribute('data-terrain-sandbox', /"recipe":/, {timeout: 60_000});
	const dry = fixture();
	Object.assign(dry.landscape.recipe, {worldSize: 256, spacing: 2});
	Object.assign(dry.settings, {waterLevel: -10, elevation: 30, azimuth: 180, clock: false});
	dry.camera = {position: [0,15,0], yaw: Math.PI, pitch: -.3};
	await load(page, dry);
	await view.evaluate(el => el.terrainSandboxDebug.action({type: 'car'}));
	await expect.poll(async() => (await status(page)).mode).toBe('car');
	await page.addStyleTag({content: '.terrain-panel, .terrain-hud { visibility: hidden !important; }'});

	async function rearLights(headlights, brake)
	{
		await view.evaluate((el, settings) => {
			el.terrainSandboxDebug.keys({Space: settings.brake});
			el.terrainSandboxDebug.values({headlights: settings.headlights});
		}, {headlights, brake});

		await expect.poll(async() => {
			const {lamps} = await status(page);

			return [lamps.headlamps, lamps.brake];
		}).toEqual([Number(headlights === 'on'), Number(brake)]);

		return PNG.sync.read(await page.screenshot({scale: 'css'}));
	}

	const dark = await rearLights('off', false);
	const running = await rearLights('on', false);
	const braking = await rearLights('on', true);

	// Compare each rear strip on the stationary car. Isolated bright lens pixels can disappear behind the grille.
	for(const [before, after] of [[dark, running], [running, braking]])
	for(const left of [330, 440])
	{
		let brighter = 0;

		for(let y = 340; y < 360; ++y)
		for(let x = left; x < left + 50; ++x)
		{
			const i = 4 * (y * after.width + x);

			if(after.data[i] - before.data[i] > 15) ++brighter;
		}

		expect(brighter).toBeGreaterThan(60);
	}
});

test.describe('on a touchscreen', () => {
	test.use({hasTouch: true, viewport: {width: 390, height: 844}});

	test('a finger drags to look, taps to use the tool, and reads a touch hint', async({page}) => {
		await page.goto('/games/terrain-sandbox/');
		await expect(canvas(page)).toHaveAttribute('data-terrain-sandbox', /"recipe":/, {timeout: 60_000});
		await (await field(page, '[data-value="tool"]')).selectOption('raise');
		await page.locator('.terrain-panel > summary').click();
		const box = await canvas(page).boundingBox();
		const x = box.x + box.width / 2, y = box.y + box.height * .6;
		const cdp = await page.context().newCDPSession(page);

		const touch = (type, points) => cdp.send('Input.dispatchTouchEvent', {type, touchPoints: points});

		const before = await status(page);

		await touch('touchStart', [{x, y}]);

		for(let step = 1; step <= 6; step++) await touch('touchMove', [{x: x + step * 20, y}]);

		await touch('touchEnd', []);
		await expect.poll(async() => (await status(page)).camera.yaw).not.toBe(before.camera.yaw);
		expect((await status(page)).revision).toBe(before.revision);
		await expect.poll(async() => (await status(page)).inputTheme).toBe('touch');
		expect((await status(page)).hint).toEqual(['Drag to look', 'Tap the ground to use the tool']);

		await touch('touchStart', [{x, y}]);
		await touch('touchEnd', []);
		await expect.poll(async() => (await status(page)).revision, {timeout: 90_000}).toBeGreaterThan(before.revision);
	});
});

test('the default canyon lets all four wheels leave the ground and touch down again', async({page}) => {
	const errors = [];
	page.on('pageerror', error => errors.push(error.message));
	await page.setViewportSize({width: 672,height: 420});
	await page.goto('/games/terrain-sandbox/');
	const view = canvas(page);
	await expect(view).toHaveAttribute('data-terrain-sandbox', /"recipe":/, {timeout: 60000});
	await (await field(page, '[name="preset"]')).selectOption('canyon');
	await press(page, 'generate');
	await expect.poll(async() => (await status(page)).recipe.preset).toBe('canyon');
	await press(page, 'car');
	await expect.poll(async() => (await status(page)).mode).toBe('car');

	await view.evaluate(el => {
		const gl = el.getContext('webgl2');
		const use = gl.useProgram.bind(gl), draw = gl.drawElements.bind(gl), strip = gl.drawArraysInstanced.bind(gl);
		const locations = new Map();
		let program = null;
		el.suspensionDraw = {drooped: false,compressed: false,terrainShadow: false};

		gl.useProgram = next => {
			program = next;

			if(next && !locations.has(next))
			locations.set(next, {
				travel: gl.getUniformLocation(next, 'uWheelTravel')
				, shade: gl.getUniformLocation(next, 'uShade')
			});

			return use(next);
		};

		gl.drawArraysInstanced = (...args) => {
			if(locations.get(program)?.shade && args[0] === gl.TRIANGLE_STRIP && args[3] > 0)
				el.suspensionDraw.terrainShadow = true;

			return strip(...args);
		};

		gl.drawElements = (...args) => {
			const location = locations.get(program)?.travel;

			if(location)
			{
				const travel = gl.getUniform(program, location);
				el.suspensionDraw.drooped ||= travel.every(v => v < -.12);
				el.suspensionDraw.compressed ||= travel.some(v => v > .04);
			}

			return draw(...args);
		};

		new MutationObserver(() => {
			if(el.landedCar) return;

			const {car, camera} = JSON.parse(el.dataset.terrainSandbox);

			if(car?.motion === 'airborne' && car.wheelContacts === 0)
			{
				el.airborneCar ??= car;
				const [x,y,z] = camera.position;
				const focus = Math.atan2(car.y+.65-y, Math.hypot(car.x-x, car.z-z));
				const frameY = Math.tan(focus-camera.pitch)/Math.tan(camera.fov/2);
				el.airborneFrameError = Math.max(el.airborneFrameError ?? 0, Math.abs(frameY));
			}

			if(el.airborneCar && car?.wheelContacts === 4) el.landedCar = car;
		}).observe(el, {attributes: true,attributeFilter: ['data-terrain-sandbox']});
	});

	await view.focus();
	await page.keyboard.down('w');
	await expect.poll(() => view.evaluate(el => !!el.landedCar), {timeout: 90000}).toBe(true);
	await page.keyboard.up('w');

	const {airborneCar, landedCar, suspensionDraw, airborneFrameError, glError} = await view.evaluate(el => ({
		airborneCar: el.airborneCar
		, landedCar: el.landedCar
		, suspensionDraw: el.suspensionDraw
		, airborneFrameError: el.airborneFrameError
		, glError: el.getContext('webgl2').getError()
	}));

	expect(landedCar.z).toBeGreaterThan(airborneCar.z);
	expect(landedCar.recoveries).toBe(0);
	expect(suspensionDraw).toEqual({drooped: true,compressed: true,terrainShadow: true});
	expect(airborneFrameError).toBeLessThan(.51);
	expect(glError).toBe(0);
	expect(errors).toEqual([]);
});

test('Arctic Meltwater can place an editable causeway circuit at its original water level', async({page}) => {
	test.setTimeout(480_000);
	await page.setViewportSize({width: 672, height: 420});
	await page.goto('/games/terrain-sandbox/');
	await expect(canvas(page)).toHaveAttribute('data-terrain-sandbox', /"recipe":/, {timeout: 60_000});

	await (await field(page, '[name="preset"]')).selectOption('arctic-meltwater');
	await (await field(page, '[data-value="quality"]')).selectOption('0.5');
	await press(page, 'generate');
	await expect.poll(async() => (await status(page)).recipe.preset).toBe('arctic-meltwater');
	await press(page, 'pause');
	const water = (await status(page)).settings.waterLevel;

	await page.getByRole('tab', {name: 'Roads', exact: true}).click();
	await page.getByText('Circuit generator', {exact: true}).click();
	const causeways = page.getByRole('checkbox', {name: 'Allow causeways'});

	await expect(causeways).not.toBeChecked();
	await causeways.check();
	await control(page, 'circuit-find').click();
	await expect(control(page, 'circuit-cancel')).toBeVisible();
	await control(page, 'circuit-cancel').click();
	await expect.poll(async() => (await status(page)).circuit).toMatchObject({searching: false, preview: false});
	await control(page, 'circuit-find').click();
	await expect.poll(async() => (await status(page)).circuit.preview, {timeout: 340_000}).toBe(true);
	expect((await status(page)).revision).toBe(0);

	await control(page, 'circuit-dry').click();
	await expect.poll(async() => (await status(page)).circuit.active, {timeout: 80_000}).toBe('dry');
	const applied = await status(page);

	expect(applied.settings.waterLevel).toBe(water);
	expect(applied.settings.circuitCauseways).toBe(true);

	const waypointCount = Math.min(33, Math.max(8, Math.ceil(applied.circuit.length/240)));
	expect(applied.course.gates).toHaveLength(Math.round(waypointCount/3));

	await expect(page.locator('[data-road-picker] option').nth(1)).toContainText(/Road/i);
});

test('preview, apply and switch between dry and flooded canyon circuits', async({page}) => {
	test.setTimeout(330_000);
	const errors = [];
	page.on('pageerror', error => errors.push(error.message));
	await page.setViewportSize({width: 672,height: 420});
	await page.goto('/games/terrain-sandbox/');
	const view = canvas(page);
	await expect(view).toHaveAttribute('data-terrain-sandbox', /"recipe":/, {timeout: 60_000});
	await (await field(page, '[name="preset"]')).selectOption('canyon');
	await (await field(page, '[data-value="quality"]')).selectOption('0.5');
	await press(page, 'generate');
	await expect.poll(async() => (await status(page)).recipe.preset).toBe('canyon');
	await press(page, 'pause');
	await page.getByRole('tab', {name: 'Roads', exact: true}).click();
	await page.getByText('Circuit generator', {exact: true}).click();
	await page.getByLabel('Minimum feature height difference').fill(String(200/.3048));
	await page.getByLabel('Start elevation').fill(String(-360/.3048));
	await control(page, 'circuit-find').click();
	await expect.poll(async() => (await status(page)).circuit.preview, {timeout: 140_000}).toBe(true);
	const preview = await status(page);
	expect(preview.course.gates).toHaveLength(0);
	expect(preview.circuit.length).toBeGreaterThan(12_000);
	expect(preview.circuit.gates).toHaveLength(11);
	expect(preview.circuit.featureArea).toBeGreaterThan(10_000_000);
	expect(preview.circuit.heightDifference).toBeGreaterThanOrEqual(200);
	expect(Math.abs(preview.circuit.startHeight + 360)).toBeLessThanOrEqual(.5);
	expect(preview.revision).toBe(0);
	await expect(page.getByRole('img', {name: 'Circuit route and gates'})).toBeVisible();

	const sections = page.locator('[data-road-picker]');
	await expect(sections).toHaveValue('-1');
	const options = await sections.locator('option').allTextContents();
	const counts = options.filter(s => s.startsWith('Circuit section')).map(s => Number(/(\d+) points/.exec(s)[1]));
	expect(counts.reduce((a, b) => a+b, 0)).toBeLessThan(253); // This lap previously displayed 506 points.
	const controls = page.locator('[data-road-point] option');
	await expect(controls).toHaveCount(counts[0]);
	await sections.selectOption('-2');
	await expect(controls).toHaveCount(counts[1]);

	await control(page, 'circuit-dry').click();

	await expect.poll(
		() => view.evaluate(element => element.terrainSandboxDebug.state.circuit.active, undefined, {timeout: 45_000})
		, {timeout: 75_000}
	).toBe('dry');

	const dry = await status(page);
	expect(dry.revision).toBe(1);
	expect(dry.course.gates).toHaveLength(preview.circuit.gates.length);
	await expect(sections).toHaveValue('1');
	await expect(controls).toHaveCount(counts[1]);


	expect(
		dry.course.gates.every(
			gate => gate.kind === 'road' && gate.width >= 37 && gate.width < 40
				&& gate.height === 2 * dry.gateSizes.road.height
		)
	).toBe(true);

	// Moving the clipmap over this road must not transfer height or paint samples from PHP.
	await expect.poll(async() => (await status(page)).renderer.roadTextureBytes).toBeGreaterThan(0);
	const start = await status(page);
	await view.evaluate(el => el.terrainSandboxDebug.keys({KeyW: true}));

	try
	{
		await expect.poll(async() => {
			const {camera} = await status(page);

			return Math.hypot(camera.position[0]-start.camera.position[0], camera.position[2]-start.camera.position[2]);
		}).toBeGreaterThan(12);
	}
	finally
	{
		await view.evaluate(el => el.terrainSandboxDebug.keys({}));
	}

	const moved = await status(page);
	expect(moved.renderer.heightTexelsWritten).toBeGreaterThan(start.renderer.heightTexelsWritten);
	expect(moved.renderer.editUploadBytes).toBe(start.renderer.editUploadBytes);

	// Start the CPU, then freeze and reset it to inspect its grid position before it accelerates again.
	const demo = control(page, 'cpu-demo');
	await expect(demo).toHaveText('Watch CPU racer');
	await demo.click();
	await expect(demo).toHaveText('Stop CPU demo');
	await expect.poll(async() => (await status(page)).car?.speed ?? 0).toBeGreaterThan(3);

	await view.focus();
	await page.keyboard.press('p');
	await expect.poll(async() => (await status(page)).paused).toBe(true);
	const rolling = await status(page);
	await page.keyboard.press('r');
	await expect.poll(async() => (await status(page)).car.speed).toBe(0);

	const restarted = await status(page);
	expect(restarted.race.state).toBe('idle');
	expect(Math.hypot(rolling.car.x-restarted.car.x, rolling.car.z-restarted.car.z)).toBeGreaterThan(.25);
	const line = restarted.course.gates.find(gate => gate.role === 'start');
	const behind = (line.x-restarted.car.x)*Math.sin(line.yaw) + (line.z-restarted.car.z)*Math.cos(line.yaw);
	expect(behind).toBeGreaterThan(1);
	expect(behind).toBeLessThanOrEqual(21);
	expect(restarted.car.speed).toBe(0);
	expect(restarted.race.splits).toEqual([]);

	await demo.click();
	await expect.poll(async() => (await status(page)).mode).toBe('fly');

	await control(page, 'circuit-flooded').click();
	await expect.poll(async() => (await status(page)).circuit.active).toBe('flooded');
	await expect(demo).toBeHidden();
	const flooded = await status(page);
	expect(flooded.revision).toBe(1);
	expect(flooded.course.gates.every(gate => gate.kind === 'water')).toBe(true);
	expect(flooded.settings.waterLevel).toBe(preview.circuit.floodedLevel);

	expect(flooded.course.gates).toHaveLength(dry.course.gates.length);

	flooded.course.gates.forEach(({x,z,yaw}, i) => {
		const road = dry.course.gates[i];
		expect(yaw).toBe(road.yaw);
		expect((x-road.x)*Math.sin(yaw)+(z-road.z)*Math.cos(yaw)).toBeCloseTo(0, 8);
	});

	await page.getByLabel('Start elevation').fill('');
	await expect.poll(async() => (await status(page)).settings.circuitStartHeight).toBeNull();

	await press(page, 'craft');
	await expect.poll(async() => (await status(page)).mode).toBe('craft');
	await expect(page.locator('.terrain-speedometer')).toBeVisible();
	const map = page.getByRole('img', {name: 'Heading-up terrain elevation map'});
	await expect(map).toBeVisible();
	expect((await map.boundingBox()).width).toBe((await page.locator('.terrain-speedometer').boundingBox()).width);

	await expect.poll(() => map.evaluate(element => {
		const colors = element.getContext('2d').getImageData(0, 0, element.width, element.height).data;
		let left = Infinity, right = -Infinity;

		for(let i = 0; i < colors.length; i += 4)
			if(colors[i] === 255 && colors[i+1] === 210 && colors[i+2] === 114 && colors[i+3] > 200)
			{
				const x = (i/4)%element.width;
				left = Math.min(left, x);
				right = Math.max(right, x);
			}

		return right-left;
	})).toBeGreaterThan(12);

	expect(errors).toEqual([]);
});

test('a rollover settles on the roof before the Testarossa recovers', async({page}) => {
	test.setTimeout(360_000);
	const errors = [];
	page.on('pageerror', error => errors.push(error.message));
	await page.setViewportSize({width: 672,height: 420});
	await boot(page);
	const ramp = fixture({history: [[{type: 'raise',x: 0,z: 45,radius: 16,amount: 30}]],revision: 1});
	Object.assign(ramp.landscape.recipe, {worldSize: 512,spacing: 1});
	ramp.settings.waterLevel = -100;
	ramp.camera = {position: [0,30,-25],yaw: Math.PI,pitch: -.4};
	await load(page, ramp);
	await press(page, 'car');
	await expect.poll(async() => (await status(page)).mode).toBe('car');
	await canvas(page).focus();
	await page.keyboard.down('w');
	// The software renderer simulates well below real time, and slower still on a loaded machine.
	await expect.poll(async() => (await status(page)).car.motion, {timeout: 60000}).toBe('tumbling');
	await page.keyboard.up('w');
	await expect.poll(async() => (await status(page)).car.motion, {timeout: 120000}).toBe('overturned');
	const landed = (await status(page)).car;
	expect(Math.cos(landed.pitch)*Math.cos(landed.roll)).toBeLessThan(-.8);
	await expect.poll(async() => (await status(page)).car.recoveries, {timeout: 60000}).toBe(1);
	const righted = (await status(page)).car;
	expect(righted.motion).toBe('driving');
	expect(Math.hypot(righted.x-landed.x, righted.z-landed.z)).toBeLessThan(.1);
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

test('atmospheric layers tint the scene, retain edits and settle while paused', async({page}) => {
	const errors = [];
	page.on('pageerror', e => errors.push(e.message));
	await boot(page);
	await settled(page);
	await page.getByRole('tab', {name: 'Sky',exact: true}).click();
	const add = page.getByRole('button', {name: 'Add atmosphere layer',exact: true});
	const preset = page.getByRole('button', {name: 'Use preset layers',exact: true});
	const groups = page.locator('[data-air-list] fieldset');
	await add.click();
	await expect.poll(async() => (await status(page)).atmosphereLayers.length).toBe(1);
	const first = groups.nth(0);
	await expect(first.getByLabel('Top (ft above sea level)')).toHaveValue('3000');
	const density = first.getByLabel('Density per mile');
	await expect(density).toHaveValue('0.3');
	await density.press('ArrowUp');
	await expect(density).toHaveValue('0.301');
	await first.getByLabel('Layer name').fill('Sea mist');
	await first.getByLabel('Layer name').press('Tab');
	await first.getByLabel('Scattered light').fill('#6688aa');
	await first.getByLabel('Base (ft above sea level)', {exact: true}).fill('-1250');
	await first.getByLabel('Base (ft above sea level)', {exact: true}).press('Tab');
	await first.getByLabel('Top (ft above sea level)', {exact: true}).fill('-450');
	await first.getByLabel('Top (ft above sea level)', {exact: true}).press('Tab');
	await first.getByLabel('Density per mile').fill('0.6437376');
	await first.getByLabel('Density per mile').press('Tab');

	await expect.poll(async() => (await status(page)).atmosphereLayers[0]).toMatchObject({
		name: 'Sea mist'
		, color: '#6688aa'
		, bottom: -381
		, top: -137.16
		, density: .4
		, enabled: true
	});

	await add.click();
	await expect.poll(async() => (await status(page)).atmosphereLayers.length).toBe(2);
	await groups.nth(1).getByLabel('Visible').uncheck();
	await expect.poll(async() => (await status(page)).atmosphereLayers[1].enabled).toBe(false);
	const stable = await settled(page);
	await page.waitForTimeout(500);
	expect((await status(page)).renderer.frames).toBe(stable.renderer.frames);
	const saved = await save(page);
	expect(saved.atmosphereLayers).toEqual(stable.atmosphereLayers);
	expect(saved.atmosphereOrigin).toBe('authored');
	await load(page, fixture());
	await expect(groups).toHaveCount(0);
	await load(page, saved);
	await page.getByRole('tab', {name: 'Sky',exact: true}).click();
	await expect(first.getByLabel('Layer name')).toHaveValue('Sea mist');
	await expect(groups.nth(1).getByLabel('Visible')).not.toBeChecked();
	await first.getByRole('button', {name: 'Remove atmosphere layer'}).click();
	await expect.poll(async() => (await status(page)).atmosphereLayers.length).toBe(1);
	await preset.click();
	await expect(first.getByLabel('Layer name')).toHaveValue('Sea spray');
	await expect(preset).toBeDisabled();
	await (await field(page, '[name="preset"]')).selectOption('arctic-meltwater');
	await press(page, 'generate');
	await page.getByRole('tab', {name: 'Sky',exact: true}).click();
	await expect(first.getByLabel('Layer name')).toHaveValue('Ice haze');
	await first.getByLabel('Visible').uncheck();
	await expect(preset).toBeEnabled();
	expect(errors).toEqual([]);
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

		observer.observe(element, {attributes: true, attributeFilter: ['data-terrain-sandbox']});
		await new Promise(resolve => setTimeout(resolve, 600));
		observer.disconnect();

		return updates;
	});

	expect(idleUpdates).toBe(0);

	// A water edit redraws a paused scene and is included in the portable save.
	await (await field(page, '[data-value="waterColor"]')).fill('#147083');
	await expect.poll(async() => (await status(page)).settings.waterColor).toBe('#147083');
	const waterEdit = await settled(page);
	expect(waterEdit.renderer.frames).toBeGreaterThan(frame);
	expect(waterEdit.time).toBe(time);
	expect((await save(page)).settings.waterColor).toBe('#147083');

	await field(page, '[data-value="nightGlow"]');
	const nightGlow = page.getByRole('slider', {name: /Night glow/});
	await nightGlow.focus();
	await nightGlow.press('End');
	await expect.poll(async() => (await status(page)).settings.nightGlow).toBe(1);

	await (await field(page, '[data-value="skyColor"]')).fill('#2c4fa8');
	await expect.poll(async() => (await status(page)).settings.skyColor).toBe('#2c4fa8');
	const skyLight = page.getByRole('slider', {name: /Sky light/});
	await skyLight.focus();
	await skyLight.press('End');
	await expect.poll(async() => (await status(page)).settings.skyLight).toBe(3);
	expect((await save(page)).settings).toMatchObject({skyColor: '#2c4fa8', skyLight: 3});

	// Renderer statistics stay off the HUD until this browser asks for them.
	const diagnostics = page.locator('[data-diagnostics]');
	await expect(diagnostics).toContainText('altitude');
	await expect(diagnostics).not.toContainText('terrain levels');
	await (await field(page, '[data-show-diagnostics]')).check();
	await expect(diagnostics).toContainText('terrain levels');
	expect(await page.evaluate(() => localStorage.getItem('terrain-sandbox.diagnostics'))).toBe('1');

	// Fog density is one logarithmic slider from clear water to one per meter: (1000^p - 1) / 999, to 3 digits.
	await field(page, '[data-fog]');
	const fog = page.getByRole('slider', {name: /Fog density/});
	await fog.focus();
	await fog.press('Home');
	await expect.poll(async() => (await status(page)).settings.waterFogDensity).toBe(0);
	await fog.press('ArrowRight');
	await expect.poll(async() => (await status(page)).settings.waterFogDensity).toBe(0.0000352);
	await fog.press('End');
	await expect.poll(async() => (await status(page)).settings.waterFogDensity).toBe(1);
	await fog.press('ArrowLeft');
	await expect.poll(async() => (await status(page)).settings.waterFogDensity).toBe(0.966);

	const level = page.getByLabel('Water level · ft', {exact: true});
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
	expect(sea.settings.waterLevel).toBeCloseTo(6.5*.3048, 10);
	expect(sea.settings.waterFogDensity).toBe(0.966);

	// The file buttons open the Landscape tab. The Water and Sky fields still hold the loaded values while hidden.
	const chopField = page.locator('[data-value="waterChoppiness"]');
	const glowField = page.locator('[data-value="nightGlow"]');

	const levelField = page.locator('[data-value="waterLevel"]'), fogField = page.locator('[data-fog]');
	await load(page, fixture());
	await expect(chopField).toHaveValue('100');
	await expect(glowField).toHaveValue('0.5');
	await load(page, sea);
	await expect(levelField).toHaveValue('6.5');
	await expect(chopField).toHaveValue('300');
	await expect(glowField).toHaveValue('1');
	await expect(fogField).toHaveValue('0.995');
	await expect(page.locator('[data-fog-output]')).toHaveText('0.294');

	// Native fields keep WASD and pause shortcuts as text while focused.
	const position = now.camera.position;
	await (await field(page, '[name="seed"]')).fill('wasd-pause');
	await page.keyboard.press('p');
	await page.waitForTimeout(500);
	expect((await status(page)).camera.position).toEqual(position);
	expect((await status(page)).paused).toBe(true);

	await canvas(page).focus();
	await page.keyboard.down('e');
	await expect.poll(async() => (await status(page)).camera.position[1]).toBeGreaterThan(position[1]);
	await expect.poll(async() => (await status(page)).generationBudgetMs).toBe(2);
	await (await field(page, '[name="seed"]')).focus();
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
	await (await field(page, '[name="preset"]')).selectOption('mountain');
	await (await field(page, '[name="seed"]')).fill('mountain-browser-check');
	await press(page, 'generate');
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

	await press(page, 'undo');
	await expect.poll(async() => (await status(page)).surface.operations, {timeout: 90_000}).toBe(0);
	await settled(page);
	await press(page, 'redo');
	await expect.poll(async() => (await status(page)).surface.operations, {timeout: 90_000}).toBe(1);
	await settled(page);

	await stroke(page, 'material', .4, .5);
	await stroke(page, 'crater', .55, .4);
	await stroke(page, 'smooth', .55, .4);
	await stroke(page, 'flatten', .45, .6);
	await stroke(page, 'lower', .5, .5);
	await (await field(page, '[data-value="tool"]')).selectOption('road');
	await expect.poll(async() => (await status(page)).settings.tool).toBe('road');
	await page.locator('.terrain-panel > summary').click();
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

	// Points placed on the terrain are the Roads tab's new road, and that tab applies them.
	await (await field(page, '[data-road-apply]')).click();
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
	}, indexCount
	);

	await press(page, 'craft');
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
	await press(page, 'fly');
	await expect.poll(async() => (await status(page)).mode).toBe('fly');
	await press(page, 'pause');
	await settled(page);
	expect(errors).toEqual([]);
});

test('the Landscape Lab explains each parameter, reflows, and folds to one round control', async({page}) => {
	await page.setViewportSize({width: 1180, height: 760});
	await page.goto('/games/terrain-sandbox/');
	const panel = page.locator('.terrain-panel');
	const toggle = panel.locator(':scope > summary');
	const landform = panel.locator('[data-landform]');
	const pages = panel.locator('.lab-pages');
	await landform.locator('summary').click();

	const fields = landform.locator('input,select');

	const descriptions = await fields.evaluateAll(
		nodes => nodes.map(
			field => document.getElementById(field.getAttribute('aria-describedby'))?.textContent.trim() || ''
		)
	);

	expect(descriptions).toHaveLength(25);
	expect(descriptions).not.toContain('');
	expect(new Set(descriptions).size).toBe(descriptions.length);

	const height = landform.locator('[name=heightScale]');
	const bias = landform.locator('[name=baseBias]');
	const first = await height.boundingBox(), second = await bias.boundingBox();
	expect(second.y).toBe(first.y);
	expect(second.x).toBeGreaterThanOrEqual(first.x + first.width);

	const navigation = panel.getByRole('tablist');
	const tabsBefore = await navigation.boundingBox();
	await landform.getByRole('combobox', {name: 'Surround', exact: true}).scrollIntoViewIfNeeded();
	expect(await navigation.boundingBox()).toEqual(tabsBefore);
	expect(await pages.evaluate(el => el.scrollTop)).toBeGreaterThan(0);
	await expect(panel.getByRole('button', {name: 'Generate', exact: true})).toBeInViewport();

	await toggle.focus();
	await page.keyboard.press('Enter');
	await expect(toggle).toHaveAccessibleName('Landscape Lab');
	await expect(navigation).toBeHidden();
	const circle = await panel.boundingBox();
	expect(circle.width).toBe(44);
	expect(circle.height).toBe(circle.width);
	expect(await panel.evaluate(el => getComputedStyle(el).borderRadius)).toBe('50%');
	await page.keyboard.press('Space');
	await expect(navigation).toBeVisible();
	await expect(landform).toHaveAttribute('open', '');

	await page.setViewportSize({width: 375, height: 680});
	const narrowHeight = await height.boundingBox(), narrowBias = await bias.boundingBox();
	expect(narrowBias.x).toBe(narrowHeight.x);
	expect(narrowBias.y).toBeGreaterThan(narrowHeight.y + narrowHeight.height);
	expect(await pages.evaluate(el => el.scrollWidth)).toBe(await pages.evaluate(el => el.clientWidth));
	await panel.getByRole('tab', {name: 'Water', exact: true}).click();
	await expect(panel.getByLabel('Water level · ft', {exact: true})).toBeInViewport();
	expect(await pages.evaluate(el => el.scrollTop)).toBe(0);
});

test('Surface keeps field help with its control and column widths steady when scrolling', async({page}) => {
	await page.setViewportSize({width: 1180, height: 1280});
	await boot(page);
	const panel = page.locator('.terrain-panel');
	await panel.getByRole('tab', {name: 'Surface', exact: true}).click();
	const layers = [{id: 'base'}, {id: 'ribs', channel: 'relief', relief: 'ribs'}, {id: 'top'}];

	await canvas(page).evaluate(
		(el, rows) => el.terrainSandboxDebug.action({type: 'surface-layers', layers: rows})
		, layers
	);

	const base = panel.locator('[data-layer="base"]'), ribs = panel.locator('[data-layer="ribs"]');
	await base.locator('summary').click();
	const name = base.getByRole('textbox', {name: 'Layer name', exact: true});
	await expect(name).toHaveAccessibleDescription(/choosing this layer as a mask/);
	const pages = panel.locator('.lab-pages');
	await expect(pages).toHaveCSS('scrollbar-gutter', 'stable');
	await expect(pages).toHaveCSS('scrollbar-width', 'thin');

	const layout = () => name.evaluate(field => {
		const pages = field.closest('.lab-pages');
		const hint = document.getElementById(field.getAttribute('aria-describedby'));
		const box = hint.getBoundingClientRect();

		return {
			overflow: pages.scrollHeight > pages.clientHeight
			, horizontal: pages.scrollWidth - pages.clientWidth
			, column: [box.x, box.width, box.height, field.getBoundingClientRect().width]
		};
	});

	// Keep the first layer in view while opening the second makes the page scroll.
	for(const [width, height] of [[1180, 1280], [375, 2048]])
	{
		await page.setViewportSize({width, height});
		const before = await layout();
		expect(before.overflow).toBe(false);
		expect(before.horizontal).toBe(0);
		await ribs.locator('summary').click();
		expect(await layout()).toEqual({...before, overflow: true, horizontal: 0});
		await ribs.locator('summary').click();
		expect(await layout()).toEqual(before);
	}

	await ribs.locator('summary').click();
	const source = ribs.getByRole('combobox', {name: 'Relief source', exact: true});
	const pattern = ribs.getByRole('combobox', {name: 'Pattern', exact: true});
	await expect(source).toHaveAccessibleDescription(/Gully spacing under Shared field settings/);
	await expect(pattern).toHaveAccessibleDescription(/Choose Pattern as the relief source/);

	await source.selectOption('pattern');
	await expect(pattern).toHaveAccessibleDescription(/shapes the bumps/);
	await pattern.selectOption('ripples');
	await expect(ribs.getByLabel('Pattern scale · ft', {exact: true})).toHaveAccessibleDescription(/wider texture/);
	await ribs.getByRole('button', {name: 'Move down'}).click();
	await expect(pattern).toHaveAccessibleDescription(/shapes the bumps/);
	await panel.getByRole('button', {name: 'Apply layers', exact: true}).click();
	await expect.poll(async() => (await status(page)).surfaceLayers[2].pattern).toBe('ripples');

	const bindings = await panel.locator('[data-layer-key],[data-shared]').evaluateAll(fields => fields.map(field => {
		const id = field.getAttribute('aria-describedby'), help = document.getElementById(id);

		return help?.textContent.trim() && help.closest('.surface-field')===field.closest('.surface-field') ? id : null;
	}));

	expect(bindings).not.toContain(null);
	expect(new Set(bindings).size).toBe(bindings.length);
});

test('the panel tabs work from the keyboard and the Surface tab changes the ground', async({page}) => {
	await page.goto('/games/terrain-sandbox/');
	await expect(canvas(page)).toHaveAttribute('data-terrain-sandbox', /"recipe":/, {timeout: 60_000});

	const tab = name => page.locator(`.terrain-sandbox #terrain-tab-${name}`);
	await expect(page.locator('.terrain-sandbox [role="tab"]')).toHaveCount(7);
	await expect(tab('landscape')).toHaveAttribute('aria-selected', 'true');

	// Arrow keys, Home and End move between tabs and wrap round; Home on a tab does not reset the camera.
	await tab('landscape').focus();
	await page.keyboard.press('ArrowRight');
	await expect(tab('shape')).toBeFocused();
	await expect(tab('shape')).toHaveAttribute('aria-selected', 'true');
	await expect(page.locator('#terrain-section-landscape')).toBeHidden();
	await page.keyboard.press('End');
	await expect(tab('explore')).toHaveAttribute('aria-selected', 'true');
	await page.keyboard.press('ArrowRight');
	await expect(tab('landscape')).toHaveAttribute('aria-selected', 'true');
	const position = (await status(page)).camera.position;
	await page.keyboard.press('End');
	await page.keyboard.press('Home');
	await expect(tab('landscape')).toBeFocused();
	expect((await status(page)).camera.position).toEqual(position);

	// Paused, the view stops drawing, and a surface change must still redraw it. Pausing also stops terrain
	// refinement, so the test waits for the drawing to stop rather than for the detail to finish.
	await press(page, 'pause');
	await expect.poll(async() => (await status(page)).paused).toBe(true);

	await tab('surface').click();
	const grass = page.locator('[data-layer="grass"]');
	await grass.locator('summary').click();
	await expect(page.locator('[data-layer="coastal-bands"]')).toBeVisible();
	await expect(page.locator('[data-layer="granite-slabs"]')).toHaveCount(0);

	let frames = -1;

	await expect.poll(async() => {
		const now = (await status(page)).renderer.frames;
		const still = now === frames;
		frames = now;

		return still;
	}, {timeout: 120_000, intervals: [1500]}).toBe(true);

	await grass.locator('[data-layer-key="color"]').fill('#ff0000');
	await expect.poll(async() => (await status(page)).surfaceLayers[0].color).toBe('#ff0000');
	await expect.poll(async() => (await status(page)).renderer.frames).toBeGreaterThan(frames);
	await page.getByText('Paint inks', {exact: true}).click();
	await page.locator('[data-ink="grassColor"]').fill('#112211');
	await expect.poll(async() => (await status(page)).paintInks.grassColor).toBe('#112211');
	const file = await save(page);
	expect(file.surface.layers[0].color).toBe('#ff0000');
	expect(file.surface.paint.grassColor).toBe('#112211');
	await tab('surface').click();
	await page.locator('[data-layer-reset]').click();
	await expect.poll(async() => (await status(page)).surfaceLayers[0].color).toBe('#55693a');
	await expect.poll(async() => (await status(page)).paintInks.grassColor).toBe('#112211');

	// The page opens on the tab shown last.
	await page.reload();
	await expect(canvas(page)).toHaveAttribute('data-terrain-sandbox', /"recipe":/, {timeout: 60_000});
	await expect(tab('surface')).toHaveAttribute('aria-selected', 'true');
	await expect(page.locator('#terrain-section-surface')).toBeVisible();
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
	expect(restored.renderer.frames - before.renderer.frames).toBeLessThanOrEqual(restored.renderer.visible + 2);
	expect(restored.renderer.pending).toBe(0);
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

test('Limestone Plain generates its skyline and round-trips as a named preset', async({page}) => {
	await page.setViewportSize({width: 720, height: 460});
	await page.goto('/games/terrain-sandbox/');
	await expect(canvas(page)).toHaveAttribute('data-terrain-sandbox', /"recipe":/, {timeout: 60_000});
	const preset = page.locator('[name="preset"]');
	const seed = page.locator('[name="seed"]');
	await preset.selectOption({label: 'Limestone Plain'});
	await expect(seed).toHaveValue('limestone-plain');
	await expect(page.locator('[name="floorWidth"]')).toHaveValue('0.18');
	await expect(page.locator('[data-landform] :invalid')).toHaveCount(0);
	await press(page, 'generate');
	await expect.poll(async() => (await status(page)).recipe.preset).toBe('limestone');
	const {surfaceLayers} = await status(page);
	expect(surfaceLayers[0]).toMatchObject({color: '#566b40', pattern: 'fractal'});
	const veins = surfaceLayers.find(layer => layer.pattern === 'veins');
	expect(surfaceLayers[veins.parent].color).toBe('#8a9193');
	const document = await save(page);

	expect(document.landscape.recipe).toMatchObject({
		seed: 'limestone-plain'
		, version: 2
		, heightScale: 3950
		, wallWidth: .91
		, terraceSpacing: 175
		, terraceStrength: .44
	});

	expect(document.camera.position).toEqual([2440, 105, -4880]);
	expect(document.settings).toMatchObject({grassColor: '#566b40', snowline: 3000, waterLevel: -40, azimuth: 106});
	await preset.selectOption('coast');
	await expect(seed).toHaveValue('first-light');
	await seed.fill('my-karst');
	await preset.selectOption('limestone');
	await expect(seed).toHaveValue('my-karst');
	await load(page, fixture());
	await load(page, document);
	await expect(preset).toHaveValue('limestone');
	await expect(seed).toHaveValue('limestone-plain');
	await seed.fill('another-plain');
	await press(page, 'generate');

	const reseeded = {...document.landscape.recipe, seed: 'another-plain'};

	await expect.poll(async() => (await status(page)).recipe).toEqual(reseeded);
});

for(const [first, second] of [
	[{label: 'Basalt Fjords', name: 'basalt-fjords', layer: 'basalt-faces'}
		, {label: 'Amber Dunes', name: 'amber-dunes', layer: 'wind-ripples'}
	]
	, [{label: 'Arctic Meltwater', name: 'arctic-meltwater', layer: 'glacier-ice'}
		, {label: 'Tidal Lagoon', name: 'tidal-lagoon', layer: 'seagrass-beds'}
	]
	, [{label: 'Red Mesas', name: 'red-mesas', layer: 'cap-rock'}
		, {label: 'Heather Moor', name: 'heather-moor', layer: 'peat-banks'}
	]
	, [{label: 'Salt Pans', name: 'salt-pans', layer: 'salt-crust'}
		, {label: 'Cinder Highlands', name: 'cinder-highlands', layer: 'oxidized-scoria'}
	]
	, [{label: 'Rolling Prairie', name: 'rolling-prairie', layer: 'green-swales'}
		, {label: 'River Valley & Floodplain', name: 'river-valley', layer: 'gravel-bars'}
	]
	, [{label: 'Wooded Foothills', name: 'wooded-foothills', layer: 'woodland-canopy'}
		, {label: 'High Desert Steppe', name: 'high-desert-steppe', layer: 'sagebrush'}
	]
	, [{label: 'Alaska Range', name: 'alaska-range', layer: 'firn-snowfields'}
		, {label: 'Chalk Headlands', name: 'chalk-headlands', layer: 'flint-nodules'}
	]
]) {
	test(
		`${first.label} and ${second.label} are available as landscapes and as independent surface presets`
		, async({page}) => {
			await page.setViewportSize({width: 700, height: 440});
			await page.goto('/games/terrain-sandbox/');
			await expect(canvas(page)).toHaveAttribute('data-terrain-sandbox', /"recipe":/, {timeout: 60_000});
			const picker = page.locator('[name="preset"]');

			for(const {label, name, layer} of [first, second])
			{
				await picker.selectOption({label});
				await expect(page.locator('[name="seed"]')).toHaveValue(name);
				await expect(page.locator('[data-landform] :invalid')).toHaveCount(0);
				await press(page, 'generate');
				await expect.poll(async() => (await status(page)).surfacePreset).toBe(name);
				const doc = await save(page);
				expect(doc.landscape.recipe).toMatchObject({preset: name, seed: name});
				expect(doc.surface.layers.map(row => row.id)).toContain(layer);
			}

			await page.getByRole('tab', {name: 'Surface', exact: true}).click();
			await page.getByLabel('Material preset', {exact: true}).selectOption({label: first.label});
			await page.getByRole('button', {name: 'Load preset surface', exact: true}).click();
			await expect.poll(async() => (await status(page)).surfacePreset).toBe(first.name);
			expect((await status(page)).recipe.preset).toBe(second.name);
		}
	);
}

test('landform parameters and nested colors survive a save and reload', async({page}) => {
	await page.setViewportSize({width: 720, height: 460});
	await boot(page);
	await page.getByRole('tab', {name: 'Landscape', exact: true}).click();
	await page.locator('[name="preset"]').selectOption('canyon');
	await page.getByText('Landform parameters', {exact: true}).click();
	const frequency = page.getByLabel('Base frequency · cycles/mi');
	await expect(frequency).toHaveValue('0.402');
	const terrace = page.getByLabel('Terrace spacing · ft');
	await expect(terrace).toHaveValue('262');
	const wall = page.getByLabel('Wall width', {exact: true});
	await expect(wall).toHaveValue('0.006');
	await wall.press('ArrowUp');
	await expect(wall).toHaveValue('0.0061');
	await wall.fill('0.0085');
	await page.getByRole('combobox', {name: 'Surround', exact: true}).selectOption('plateau');
	await press(page, 'generate');
	await expect.poll(async() => (await status(page)).recipe.wallWidth).toBe(.0085);
	await expect.poll(async() => (await status(page)).recipe.version).toBe(2);

	await page.locator('[aria-controls="terrain-section-surface"]').click();
	const layers = page.locator('[data-layer-fields] [data-layer]');
	const child = layers.nth(1);
	await child.locator('summary').click();
	await child.locator('[data-layer-key="parent"]').selectOption('canyon-dust');
	await child.locator('[data-layer-key="color"]').fill('#7e513d');
	await child.getByRole('combobox', {name: 'Pattern',exact: true}).selectOption('streaks');
	await child.locator('[data-layer-key="patternColor"]').fill('#2f2120');
	await child.locator('[data-layer-key="patternAmount"]').fill('0.7');
	await child.locator('[data-layer-key="patternScale"]').fill('18');
	await child.locator('[data-layer-key="patternStretch"]').fill('6');
	await child.getByLabel('Reflection strength · 0–1', {exact: true}).fill('0.8');
	await child.getByLabel('Reflection roughness · 0–1', {exact: true}).fill('0.22');
	await page.getByRole('button', {name: 'Apply layers', exact: true}).click();
	await expect.poll(async() => (await status(page)).surfaceLayers[1].color).toBe('#7e513d');

	const document = await save(page);
	expect(document.version).toBe(5);
	expect(document.landscape.recipe.wallWidth).toBe(.0085);
	expect(document.landscape.recipe.terraceSpacing).toBe(80);
	expect(document.landscape.recipe.baseFrequency).toBe(1/4000);
	expect(document.landscape.recipe.surround).toBe('plateau');

	expect(document.surface.layers[1]).toMatchObject({
		color: '#7e513d'
		, parent: 'canyon-dust'
		, pattern: 'streaks'
		, patternColor: '#2f2120'
		, patternAmount: .7
		, patternScale: 5.4864
		, patternStretch: 6
		, reflectivity: .8
		, roughness: .22
	});

	await load(page, fixture());
	await load(page, document);

	await expect.poll(async() => (await status(page)).surfaceLayers).toEqual(document.surface.layers.map(row => ({
		...row
		, parent: row.parent===-1 ? -1 : document.surface.layers.findIndex(parent => parent.id===row.parent)
	})));

	await expect(wall).toHaveValue('0.0085');
});

test('the signed clipmap grid covers the ground after relocation', async({page}) => {
	await page.setViewportSize({width: 640, height: 420});
	await boot(page);
	const doc = fixture();
	doc.landscape.recipe.worldSize = 4096;
	doc.landscape.recipe.spacing = 1;
	doc.settings.quality = 1;
	doc.settings.waterLevel = -50;
	doc.settings.elevation = 55;
	doc.version = 4;
	doc.layers = [{color: '#da492b'}];

	for(const position of [[-701, 550, -703], [709, 550, 717]])
	{
		doc.camera = {position, yaw: .71, pitch: -1.52};
		await load(page, doc);
		const result = await settled(page);
		expect(result.renderer.visible).toBeGreaterThan(4);
		expect(result.renderer.proceduralVertexBytes).toBe(0);
		const image = PNG.sync.read(await canvas(page).screenshot());
		let sky = 0;

		// Left-hand crop is clear of the controls. A missing triangle in this down-facing
		// view shows the sky's blue or the blue clear color through the red ground.
		for(let y = 45; y < image.height - 110; ++y)
		for(let x = 35; x < 265; ++x)
		{
			const index = 4 * (y * image.width + x);

			if(image.data[index + 2] > image.data[index] + 5) ++sky;
		}

		expect(sky).toBe(0);
	}
});

test('the coastal palette shows inland grass and shore sand', async({page}) => {
	await page.setViewportSize({width: 640, height: 460});
	await boot(page);
	const coast = fixture();
	Object.assign(coast.settings, {waterLevel: -40, waterChoppiness: 0, elevation: 55});
	await load(page, coast);
	await settled(page);

	// The expanded control window can cover the previous sample point at this viewport.
	const overlays = page.locator('.terrain-panel, .terrain-hud');
	await overlays.evaluateAll(nodes => nodes.forEach(node => { node.style.visibility = 'hidden'; }));


	const groundColor = async() => {
		const image = PNG.sync.read(await canvas(page).screenshot());
		const offset = 4 * (Math.floor(image.height * .42) * image.width + Math.floor(image.width * .3));

		return [...image.data.subarray(offset, offset + 3)];
	};

	let [red, green, blue] = await groundColor();
	expect(green).toBeGreaterThan(red + 5);
	expect(green).toBeGreaterThan(blue + 5);

	await canvas(page).evaluate(element => element.terrainSandboxDebug.values({waterLevel: -2}));
	await expect.poll(async() => (await status(page)).settings.waterLevel).toBe(-2);
	await settled(page);
	[red, green, blue] = await groundColor();
	expect(red).toBeGreaterThan(green + 5);
	expect(green).toBeGreaterThan(blue + 5);
});

test('Mountain Ridges shades lichen and custom layers can stipple their interiors', async({page}) => {
	await page.setViewportSize({width: 520, height: 360});
	await boot(page);
	const mountain = fixture();

	Object.assign(mountain.landscape.recipe, {
		preset: 'mountain'
		, seed: 'visual-mountain'
		, worldSize: 8192
		, spacing: 2
		, heightScale: 1400
		, baseFrequency: 1/2200
		, surround: 'mountains'
	});

	Object.assign(mountain.settings, {lichenAmount: 0, waterLevel: 0, elevation: 45, azimuth: 140});
	mountain.camera = {position: [-1880,235,-1328], yaw: -2.02, pitch: -.42};
	await load(page, mountain);

	await page.locator('.terrain-panel, .terrain-hud').evaluateAll(nodes => nodes.forEach(node => {
		node.style.visibility = 'hidden';
	}));

	const view = canvas(page);

	const capture = async() => {
		await settled(page);

		return PNG.sync.read(await view.screenshot()).data;
	};

	const changedFraction = (first, second) => {
		let changed = 0;

		for(let i = 0; i < first.length; i += 4)
			if(Math.abs(first[i+1]-second[i+1]) > 2) changed++;

		return changed/(first.length/4);
	};

	const withoutLichen = await capture();
	await view.evaluate(el => el.terrainSandboxDebug.values({lichenAmount: 1}));
	await expect.poll(async() => (await status(page)).settings.lichenAmount).toBe(1);
	const withLichen = await capture();
	const stack = (await status(page)).surfaceLayers;

	await view.evaluate(
		(el, layers) => el.terrainSandboxDebug.action({type: 'surface-layers',layers,request: 17})
		, stack
	);

	await expect.poll(async() => (await status(page)).materialReply).toBe(17);
	expect(Buffer.compare(withLichen, await capture())).toBe(0);
	expect(changedFraction(withLichen, withoutLichen)).toBeGreaterThan(.01);

	// One unbounded layer gives the pattern no edges to hide behind.

	const setStipple = async amount => {
		await view.evaluate(
			(el, patternAmount) => el.terrainSandboxDebug.action({
				type: 'surface-layers'
				, layers: [{
					color: '#aa906f'
					, pattern: 'stipple'
					, patternColor: '#514431'
					, patternScale: 1.6
					, patternAmount
				}]
			})
			, amount
		);

		const stippled = {pattern: 'stipple', patternAmount: amount};

		await expect.poll(async() => (await status(page)).surfaceLayers[0]).toMatchObject(stippled);

		return capture();
	};

	const solidRock = await setStipple(0);
	const stippledRock = await setStipple(.8);
	expect(changedFraction(solidRock, stippledRock)).toBeGreaterThan(.02);
	await view.evaluate(el => el.terrainSandboxDebug.action({type: 'surface-layers'}));
	await expect.poll(async() => (await status(page)).surfaceLayers[0].color).not.toBe('#aa906f');
	expect(changedFraction(await capture(), withLichen)).toBeLessThan(.005);
});

test('the distant face in the default canyon stays sunlit at the starting viewpoint', async({page}) => {
	test.setTimeout(150_000);
	await page.setViewportSize({width: 960,height: 600});
	await page.goto('/games/terrain-sandbox/');
	const view = canvas(page);
	await expect(view).toHaveAttribute('data-terrain-sandbox', /"recipe":/, {timeout: 60_000});
	await (await field(page, '[name="preset"]')).selectOption('canyon');
	await press(page, 'generate');
	await expect.poll(async() => (await status(page)).recipe.preset).toBe('canyon');
	await press(page, 'pause');
	await settled(page);

	// This sunlit sandstone band is about six kilometers away. The coarse mesh used
	// to drop a blue triangle into it until the camera had traveled about 1.2 km.
	const image = PNG.sync.read(await view.screenshot());
	const cx = Math.round(image.width/2 - image.height*11/561);
	const cy = Math.round(image.height*203/561);

	for(let y = cy-1; y<=cy+1; y++)
	{
		const adjacent = image.data[4*(image.width*y+cx-6)];

		for(let x = cx-1; x<=cx+1; x++)
			expect(Math.abs(image.data[4*(image.width*y+x)]-adjacent)).toBeLessThan(28);
	}
});

test('the F5 keeps the canyon wall in the fine grid as it flies past clipmap joins', async({page}) => {
	test.setTimeout(180_000);
	await page.setViewportSize({width: 720, height: 440});
	const errors = [];
	page.on('pageerror', error => errors.push(error.message));
	await boot(page);

	const canyon = fixture();
	canyon.landscape.recipe = {
		preset: 'canyon'
		, version: 2
		, seed: 'visual-canyon'
		, heightScale: 650
		, baseFrequency: 1/4000
		, terraceSpacing: 80
		, waterLevel: -450
	};

	canyon.settings.quality = 1;
	canyon.camera = {position: [791,570,-1771], yaw: .09, pitch: -.38};
	await load(page, canyon);

	// This wall is about 1.5 km from the starting camera. The old 64 m mesh smoothed
	// the ledges and changed their shape as the aircraft advanced.

	const wallSpacing = ({renderer}) => renderer.visibleCoverage.find(
		([left,front,size]) => left <= 1867 && 1867 < left+size && front <= -3217 && -3217 < front+size
	)?.[3];

	const before = await settled(page);
	expect(wallSpacing(before)).toBeLessThanOrEqual(16);

	await press(page, 'jet');
	await expect.poll(async() => (await status(page)).mode).toBe('jet');
	await canvas(page).focus();
	await page.keyboard.press('v');
	await expect.poll(async() => (await status(page)).jetView).toBe('cockpit');

	const initial = await status(page);
	await expect.poll(async() => (await status(page)).jet.z, {timeout: 90_000}).toBeLessThan(initial.jet.z - 65);
	const moving = await status(page);
	expect(moving.renderer.pending).toBe(0);
	expect(moving.renderer.visible).toBe(before.renderer.visible);
	expect(wallSpacing(moving)).toBeLessThanOrEqual(16);
	expect(moving.renderer.proceduralVertexBytes).toBe(0);
	expect(errors).toEqual([]);
});

test('the wider landscape can be generated, saved, and flown beyond the original boundary', async({page}) => {
	test.setTimeout(180_000);
	await page.setViewportSize({width: 640, height: 420});
	const errors = [];
	page.on('pageerror', error => errors.push(error.message));
	await boot(page);

	await page.getByRole('tab', {name: 'Landscape', exact: true}).click();
	const worldSize = page.getByRole('combobox', {name: 'World size', exact: true});
	await expect(worldSize).toHaveValue('128');
	await worldSize.selectOption('65536');
	await page.locator('[name="preset"]').selectOption('canyon');
	await expect(worldSize).toHaveValue('65536');
	await press(page, 'generate');
	await expect.poll(async() => (await status(page)).recipe.worldSize).toBe(65536);

	const wide = await save(page);
	expect(wide.landscape.recipe.worldSize).toBe(65536);
	wide.camera = {position: [32550,850,-32000], yaw: Math.PI/2, pitch: -.22};
	await load(page, wide);
	const nearEdge = await settled(page);
	expect(nearEdge.camera.position[0]).toBe(32550);
	expect(nearEdge.camera.position[2]).toBe(-32000);
	expect(nearEdge.renderer.visible).toBeGreaterThan(0);
	await expect(worldSize).toHaveValue('65536');

	await canvas(page).focus();
	await page.keyboard.press('j');
	await expect.poll(async() => (await status(page)).mode).toBe('jet');
	await expect.poll(async() => (await status(page)).jet.x).toBeGreaterThan(32550);
	await page.keyboard.press('n');
	await expect.poll(async() => (await status(page)).jetWideMap).toBe(true);
	await expect(page.locator('.terrain-flight-map')).toBeVisible();
	expect(await canvas(page).evaluate(element => element.getContext('webgl2').getError())).toBe(0);
	expect(errors).toEqual([]);
});

test('a relief-only layer changes sun shading while a painted road keeps its ink and normal', async({page}) => {
	await page.setViewportSize({width: 480,height: 360});
	await boot(page);
	const road = {type: 'road',points: [[-60,0,0],[60,0,0]],width: 14,shoulder: 4,crown: 0};
	const doc = fixture({history: [[road]],revision: 1});
	doc.version = 4;
	doc.layers = [{id: 'sand',name: 'Sand',color: '#9e8053'}];
	Object.assign(doc.settings, {waterLevel: -25,waterChoppiness: 0,elevation: 27,azimuth: 100,roadColor: '#545150'});
	doc.camera = {position: [0,45,0],yaw: 0,pitch: -1.52};
	await load(page, doc);

	const overlays = page.locator('.terrain-panel, .terrain-hud');
	await overlays.evaluateAll(nodes => nodes.forEach(node => { node.style.visibility = 'hidden'; }));

	const view = canvas(page);

	const image = async() => {
		await settled(page);

		return PNG.sync.read(await view.screenshot());
	};

	const before = await image();
	const relief = {
		id: 'ripples'
		, name: 'Sand ripples'
		, parent: 'sand'
		, channel: 'relief'
		, relief: 'pattern'
		, pattern: 'ripples'
		, patternScale: 4
		, patternAmount: 1
		, strength: 4
	};

	await view.evaluate(
		(e, layers) => e.terrainSandboxDebug.action({type: 'surface-layers',layers})
		, [...doc.layers,relief]
	);

	await expect.poll(async() => (await status(page)).surfaceLayers.length).toBe(2);
	const after = await image();

	const difference = (y0, y1) => {
		let sum = 0,count = 0;

		for(let y = Math.floor(y0*before.height); y<y1*before.height; y++)
		for(let x = 90; x<before.width-90; x++)
		{
			const i = 4*(y*before.width+x);
			sum += Math.abs(before.data[i]-after.data[i]);
			count++;
		}

		return sum/count;
	};

	expect(difference(.46, .51)).toBeLessThan(.1);
	expect(difference(.18, .26)).toBeGreaterThan(1);
	await view.evaluate(e => e.terrainSandboxDebug.values({azimuth: 280}));
	await expect.poll(async() => (await status(page)).settings.azimuth).toBe(280);
	const rotated = await image();
	expect(rotated.data.equals(after.data)).toBe(false);
});

test('car sounds both exhaust banks, skids on pavement and raises dust', async({page}, testInfo) => {
	const errors = [];
	page.on('pageerror', error => errors.push(error.message));
	page.on('console', message => { if(message.type() === 'error') errors.push(message.text()); });

	await page.setViewportSize({width: 760, height: 480});

	await page.addInitScript(() => {
		// Observe the PHAR's running audio graph, including PCM at the output.
		const probe = window.driveAudio = {buffers: 0, live: new Set(), starts: 0, skids: 0, analyser: null};
		const decode = BaseAudioContext.prototype.decodeAudioData;

		BaseAudioContext.prototype.decodeAudioData = async function(...args) {
			const buffer = await decode.apply(this, args);
			probe.buffers++;

			return buffer;
		};

		const connect = AudioNode.prototype.connect;

		AudioNode.prototype.connect = function(destination, ...args) {
			if(destination instanceof AudioDestinationNode && this instanceof GainNode)
			{
				probe.analyser ??= this.context.createAnalyser();
				probe.analyser.fftSize = 8192;
				probe.analyser.smoothingTimeConstant = 0;
				connect.call(this, probe.analyser);
			}

			return connect.call(this, destination, ...args);
		};

		const start = AudioBufferSourceNode.prototype.start;

		AudioBufferSourceNode.prototype.start = function(...args) {
			if(this.loop && this.buffer)
			{
				probe.live.add(this);

				if(this.buffer.numberOfChannels === 4) probe.starts++;

				if(Math.abs(this.buffer.duration - 2.92) < .001) probe.skids++;

				this.addEventListener('ended', () => probe.live.delete(this), {once: true});
			}

			return start.apply(this, args);
		};
	});

	await boot(page);
	const dry = fixture();
	dry.landscape.recipe.worldSize = 512;
	dry.settings.waterLevel = -5;
	dry.camera = {position: [0,30,0],yaw: Math.PI,pitch: -.4};
	await load(page, dry);
	const view = canvas(page);

	await view.evaluate(element => {
		const gl = element.getContext('webgl2'), draw = gl.drawArraysInstanced.bind(gl);
		element.dustPixels = 0;

		gl.drawArraysInstanced = (mode, first, count, instances) => {
			const isDust = count === 6 && instances >= 6 && element.dustPixels < 30
				&& gl.getUniformLocation(gl.getParameter(gl.CURRENT_PROGRAM), 'clock') !== null;

			if(!isDust) return draw(mode, first, count, instances);

			const w = gl.drawingBufferWidth, h = gl.drawingBufferHeight;

			const pixels = () => {
				const bytes = new Uint8Array(w*h*4);
				gl.readPixels(0, 0, w, h, gl.RGBA, gl.UNSIGNED_BYTE, bytes);

				return new Uint32Array(bytes.buffer);
			};

			const before = pixels();
			draw(mode, first, count, instances);
			element.dustPixels = pixels().reduce((sum, color, i) => sum + Number(color !== before[i]), 0);
		};
	});

	await press(page, 'car');
	await expect.poll(async() => (await status(page)).car?.rpm).toBe(950);
	await expect.poll(() => page.evaluate(() => window.driveAudio.buffers)).toBe(2);
	await expect.poll(() => page.evaluate(() => window.driveAudio.starts)).toBe(1);

	const firingHz = () => page.evaluate(() => {
		const analyser = window.driveAudio.analyser;

		if(!analyser) return 0;

		const bins = new Float32Array(analyser.frequencyBinCount);
		analyser.getFloatFrequencyData(bins);
		const peak = bins.indexOf(Math.max(...bins));

		return peak*analyser.context.sampleRate/analyser.fftSize;
	});

	/** At idle each bank fires at 47.5 Hz. That note or its octave leads. */
	const firing = hz => [47.5, 95].some(note => Math.abs(hz - note) < 7);
	await expect.poll(async() => firing(await firingHz())).toBe(true);

	await page.locator('.terrain-panel[open] > summary').click();
	await view.focus();
	await page.keyboard.down('w');
	await page.keyboard.down('Shift');
	await expect.poll(() => view.evaluate(element => element.dustPixels)).toBeGreaterThan(30);
	await expect.poll(firingHz).toBeGreaterThan(107);
	expect(await page.evaluate(() => window.driveAudio.starts)).toBe(1);
	await page.keyboard.down('d');
	await expect.poll(async() => (await status(page)).car.roll).toBeGreaterThan(.025);
	await page.screenshot({path: testInfo.outputPath('tire-dust.png')});

	await page.keyboard.up('d');
	await page.keyboard.up('Shift');
	await page.keyboard.up('w');
	await page.keyboard.press('p');
	await expect.poll(() => page.evaluate(() => window.driveAudio.live.size)).toBe(0);
	await expect.poll(() => page.evaluate(() => window.driveAudio.analyser.context.state)).toBe('suspended');

	await page.keyboard.press('p');
	await expect.poll(() => page.evaluate(() => window.driveAudio.live.size)).toBeGreaterThan(0);

	const road = fixture(
		{history: [[{type: 'road',points: [[0,0,-90],[0,0,90]],width: 10,shoulder: 1,crown: 0}]],revision: 1}
	);

	road.landscape.recipe.worldSize = 512;
	road.settings.waterLevel = -5;
	road.camera = dry.camera;
	await load(page, road);
	await press(page, 'car');
	await view.focus();

	await page.keyboard.down('w');
	await expect.poll(async() => (await status(page)).car?.speed ?? 0).toBeGreaterThan(6);
	await page.keyboard.up('w');

	// Launch can scrub the rear tires. Wait for it to finish so the lock must start its own voice.
	await expect.poll(
		() => page.evaluate(
			() => [...window.driveAudio.live].some(node => node.buffer && Math.abs(node.buffer.duration - 2.92) < .001)
		)
	).toBe(false);

	const previousSkids = await page.evaluate(() => window.driveAudio.skids);
	await page.keyboard.down('Space');
	await expect.poll(() => page.evaluate(() => window.driveAudio.skids)).toBeGreaterThan(previousSkids);
	await page.keyboard.up('Space');

	await page.keyboard.press('f');
	await expect.poll(async() => (await status(page)).mode).toBe('fly');
	await expect.poll(() => page.evaluate(() => window.driveAudio.live.size)).toBe(0);
	expect(await page.evaluate(() => window.driveAudio.buffers)).toBe(2);
	expect(errors).toEqual([]);
});

test('rendered exhaust holds a steady note and follows an upshift through the throttle cut', async({page}) => {
	const synth = new URL('../../games/terrain-sandbox/browser/car-audio.mjs', import.meta.url);
	const module = 'data:text/javascript;base64,' + (await fs.readFile(synth)).toString('base64');
	const autoload = fileURLToPath(new URL('../../games/terrain-sandbox/autoload.php', import.meta.url));

	const file = new URL('../../games/terrain-sandbox/assets/car-audio/flat-twelve.wav', import.meta.url);
	const wav = await fs.readFile(file);

	const targets = JSON.parse(execFileSync('php', ['-r'
		, `
		require $argv[1];
		use TerrainSandbox\\CarEngineSound;
		$states = [[2500,1],[2500,0],[7400,1],[5650,0],[5650,1],[8000,1]];
		echo json_encode(array_map(fn($s) => CarEngineSound::controls($s[0],$s[1],0), $states));
	`
		, autoload
	], {encoding: 'utf8'}));

	const measured = await page.evaluate(async({module, wav, targets}) => {
		const {prepareEngine, startEngine} = await import(module);
		const sampleRate = 44100, context = new OfflineAudioContext(1, 5*sampleRate, sampleRate);
		const recording = await context.decodeAudioData(Uint8Array.from(atob(wav), char => char.charCodeAt(0)).buffer);
		const instrument = prepareEngine(context, recording);

		const tones = targets.map(([cycle,level,cutoff,flow,scatter]) => ({cycle, level, cutoff, flow, scatter}));

		const engine = startEngine(context, instrument, tones[0]);
		const stops = [.9,1.6,2.45,3.05,3.65,4.45].map(t => context.suspend(t));
		const rendered = context.startRendering();

		for(const [i, stop] of stops.entries())
		{
			await stop;

			if(i+1 < tones.length) engine.update(tones[i+1]);
			else engine.stop();

			await context.resume();
		}

		const pcm = (await rendered).getChannelData(0);

		const section = (time, duration) => pcm.subarray(
			Math.round(time*sampleRate)
			, Math.round((time+duration)*sampleRate)
		);

		const rms = wave => Math.sqrt(wave.reduce((sum, x) => sum+x*x, 0)/wave.length);

		function spectrum(time, shaftHz)
		{
			const samples = section(time, .36);
			const wave = samples.map((v, i) => v*(.5-.5*Math.cos(2*Math.PI*i/(samples.length-1))));
			const binHz = sampleRate/wave.length;
			// Parseval's identity gives total one-sided power with the same normalization as the bins below.
			const total = wave.reduce((sum, x) => sum+x*x, 0)*wave.length/2;
			let loudest = 0, peak = 0, bass = 0, overtones = 0, mid = 0, betweenOrders = 0;
			// Strongest bin within one bin of the shaft rate, the growl, the note (one bank's firings) and its octave.
			const lines = {shaft: 0, growl: 0, note: 0, octave: 0};

			/** Whether a bin lies within one bin of an engine order. */
			const near = (hz, order) => Math.abs(hz - order*shaftHz) <= binHz;

			for(let bin = Math.ceil(30/binHz); bin*binHz <= 5000; bin++)
			{
				// Goertzel power for this frequency bin.
				const coefficient = 2*Math.cos(2*Math.PI*bin/wave.length);
				let previous = 0, older = 0;

				for(let i = 0; i < wave.length; i++)
				{
					const next = wave[i] + coefficient*previous - older;
					older = previous;
					previous = next;
				}

				const energy = previous*previous + older*older - coefficient*previous*older;
				const hz = bin*binHz;

				if(hz >= 40 && hz < 160) bass += energy;

				for(const [line, order] of [['shaft', 1], ['growl', 1.5], ['note', 3], ['octave', 6]])
				if(near(hz, order)) lines[line] = Math.max(lines[line], energy);

				if(hz >= 1000) overtones += energy;

				if(hz >= 350 && hz <= 3200)
				{
					mid += energy;
					const order = hz/shaftHz;

					if(Math.abs(order-Math.round(order)) > .15) betweenOrders += energy;
				}

				if(energy > loudest)
				{
					loudest = energy;
					peak = hz;
				}
			}

			return {
				peak
				, note: peak/(3*shaftHz)
				, bass: bass/total
				, overtones: overtones/total
				, betweenOrders: betweenOrders/mid
				, growl: 10*Math.log10(lines.growl/lines.note)
				, octave: 10*Math.log10(lines.octave/lines.note)
				, shaft: 10*Math.log10(lines.shaft/lines.note)
			};
		}

		const levels = Array.from({length: 13}, (_, i) => rms(section(.2+i*.05, .05))).sort((a, b) => a-b);

		return {
			spectra: [.3,1.1,1.9,2.66,3.22,3.92].map((time, i) => spectrum(time, 2*tones[i].cycle))
			, variation: 20*Math.log10(levels.at(-2)/levels[1])
			, pull: rms(section(.3, .5))
			, coast: rms(section(1.1, .4))
			, stopped: rms(section(4.65, .15))
		};
	}, {module, wav: wav.toString('base64'), targets});

	// Every state sounds one bank's firings as the note, with a growl an octave below, a quieter octave above, and no
	// hum at the shaft's rotation rate.
	for(const spectrum of measured.spectra)
	{
		expect(Math.abs(spectrum.note-1)).toBeLessThan(.01);
		expect(spectrum.growl).toBeGreaterThan(-10);
		expect(spectrum.growl).toBeLessThan(0);
		expect(spectrum.octave).toBeLessThan(-4);
		expect(spectrum.shaft).toBeLessThan(-18);
	}

	// Under load at high revs the tailpipes rasp a little above the note, kept low so the note sounds clean.
	for(const i of [2,5])
	{
		expect(measured.spectra[i].bass).toBeLessThan(.05);
		expect(measured.spectra[i].overtones).toBeGreaterThan(.015);
		expect(measured.spectra[i].overtones).toBeLessThan(.15);
	}

	// The throttle cut quiets the rasp.
	expect(measured.spectra[3].overtones).toBeLessThan(.6*measured.spectra[4].overtones);

	for(const i of [2,5]) expect(measured.spectra[i].betweenOrders).toBeGreaterThan(.03);

	expect(measured.variation).toBeLessThan(1.5);
	expect(measured.pull).toBeGreaterThan(1.3*measured.coast);
	expect(measured.coast).toBeGreaterThan(.005);
	expect(measured.stopped).toBeLessThan(.00001);
});

test('the pause menu opens from Escape, Start and its button and acts through the session', async({page}) => {
	test.setTimeout(360_000);
	const errors = [];
	page.on('pageerror', error => errors.push(error.message));
	page.on('console', message => { if(message.type() === 'error') errors.push(message.text()); });

	await page.addInitScript(() => {
		localStorage.setItem('terrain-sandbox.music.off', '1');
		const buttons = Array.from({length: 17}, () => ({pressed: false, touched: false, value: 0}));
		const pad = {
			id: 'Menu test controller'
			, index: 0
			, timestamp: 1
			, mapping: 'standard'
			, connected: true
			, axes: [0,0,0,0]
			, buttons
		};

		Object.defineProperty(navigator, 'getGamepads', {value: () => [pad]});

		window.testMenuButton = (n, on) => {
			pad.timestamp++;
			buttons[n] = {pressed: on, touched: on, value: on ? 1 : 0};
		};
	});

	await boot(page);
	await settled(page);
	const view = canvas(page);
	const KNOT = 1852 / 3600;

	/** Resolve once the canvas status shows a menu state, checked every frame so a held key cannot repeat past it. */
	const until = (check, arg) => page.waitForFunction(({check, arg}) => {
		const state = JSON.parse(document.querySelector('canvas[data-terrain-sandbox]').dataset.terrainSandbox);

		return new Function('state', 'arg', 'return ' + check)(state, arg);
	}, {check, arg}, {polling: 'raf', timeout: 60_000});

	/** Wait until the menu stops redrawing, so the frames have seen the last release before the next press. */
	const idle = async() => {
		let frames = -1;

		while(frames !== (frames = (await status(page)).renderer.frames)) await page.waitForTimeout(600);
	};

	/** Hold a key until the menu selects a row, then let go. */
	const key = async(name, row) => {
		await page.keyboard.down(name);
		await until('state.menuRow?.id === arg', row);
		await page.keyboard.up(name);
		await idle();
	};

	/** Hold a key until the menu opens or closes, then let go. */
	const toggle = async(name, open) => {
		await page.keyboard.down(name);
		await until('state.menu === arg', open);
		await page.keyboard.up(name);
		await idle();
	};

	await view.focus();
	await toggle('Escape', true);
	let state = await status(page);
	expect(state.paused).toBe(true);
	expect(state.menuRow).toEqual({page: 'PAUSED', id: 'resume', label: 'Resume'});
	await expect(page.locator('.terrain-sandbox')).toHaveClass(/terrain-menu-open/);
	await expect(page.locator('.terrain-hud')).toBeHidden();
	await expect(page.locator('.terrain-panel')).not.toHaveAttribute('open', '');
	await expect(page.locator('[data-menu-live]')).toHaveText('PAUSED: Resume');

	// Escape on the top page resumes and gives the Landscape Lab back.
	await toggle('Escape', false);
	expect((await status(page)).paused).toBe(false);
	await expect(page.locator('.terrain-panel')).toHaveAttribute('open', '');

	/** Hold a pad button until the menu reaches a state, then let go. */
	const pad = async(button, check, arg) => {
		await page.evaluate(n => window.testMenuButton(n, true), button);
		await until(check, arg);
		await page.evaluate(n => window.testMenuButton(n, false), button);
	};

	// Start opens the menu, and the press that opened it does not close it again. B closes it from the top page.
	await pad(9, 'state.menu === arg', true);
	await idle();
	expect((await status(page)).menu).toBe(true);
	await pad(1, 'state.menu === arg', false);

	// The D-pad moves the selection, and Start closes the menu.
	await pad(9, 'state.menu === arg', true);
	await idle();
	await pad(13, 'state.menuRow?.id === arg', 'car');
	await pad(9, 'state.menu === arg', false);

	// The pause button opens it too. Settings sets the flight speed in knots, and the Explore slider follows.
	await control(page, 'menu').click();
	await until('state.menu === arg', true);
	await view.focus();

	// End jumps to Quit at the foot of the page, and Up walks back to Settings.
	await key('End', 'quit');
	await key('ArrowUp', 'lab');
	await key('ArrowUp', 'settings');
	await key('Enter', 'headlights');
	await key('End', '@back');
	await key('ArrowUp', 'speed');

	await page.keyboard.down('ArrowRight');
	await until('state.settings.speed > arg', 202 * KNOT);
	await page.keyboard.up('ArrowRight');
	state = await status(page);
	const knots = state.settings.speed / KNOT;
	expect(Math.abs(knots - Math.round(knots / 5) * 5)).toBeLessThan(1e-6);
	await expect(page.locator('[data-value="speed"]')).toHaveValue(String(Math.round(knots)));

	// The menu can audition a song while the simulation is paused. Both surfaces share transport and volume.
	await key('Escape', 'settings');
	await key('ArrowUp', 'audio');
	await key('Enter', 'track');
	const pauseMusic = page.locator('[data-music-pause]');
	await expect(pauseMusic).toHaveAttribute('aria-pressed', 'true');

	await page.keyboard.down('ArrowRight');
	await expect(pauseMusic).toHaveAttribute('aria-pressed', 'false');
	await page.keyboard.up('ArrowRight');
	await expect(page.locator('[data-music-credit] strong')).toHaveText('Peak Shiawase Remix');
	expect((await status(page)).paused).toBe(true);
	await idle();

	await page.keyboard.down('ArrowLeft');
	await expect(pauseMusic).toHaveAttribute('aria-pressed', 'true');
	await page.keyboard.up('ArrowLeft');
	expect(await page.evaluate(() => localStorage.getItem('terrain-sandbox.music.off'))).toBe('1');
	await idle();

	await key('ArrowDown', 'music');
	const volume = page.locator('[data-music-level]');
	const initialVolume = Number(await volume.inputValue());
	await page.keyboard.down('ArrowRight');

	await page.waitForFunction(
		initial => document.querySelector('[data-music-level]').valueAsNumber > initial
		, initialVolume
	);

	await page.keyboard.up('ArrowRight');
	expect(Number(await volume.inputValue())).toBe(Math.round((initialVolume+5)/5)*5);
	await idle();

	await key('ArrowDown', 'engine');
	await page.keyboard.down('ArrowLeft');

	/** The engine level this browser remembers. */
	const engineLevel = async() => {
		const saved = await page.evaluate(() => localStorage.getItem('terrain-sandbox.audio'));

		return JSON.parse(saved ?? '{}').engine;
	};

	await expect.poll(engineLevel).toBeLessThan(100);
	await page.keyboard.up('ArrowLeft');
	expect((await engineLevel()) % 5).toBe(0);
	await idle();

	// The Landscape Lab row closes the menu, keeps the world frozen and opens the editor.
	await key('Escape', 'audio');
	await key('End', 'quit');
	await key('ArrowUp', 'lab');
	await toggle('Enter', false);
	expect((await status(page)).paused).toBe(true);
	await expect(page.locator('.terrain-panel')).toHaveAttribute('open', '');
	expect(errors).toEqual([]);

	// Quit asks first, then leaves for the examples index.
	await view.focus();
	await toggle('Escape', true);
	await key('ArrowUp', 'quit');
	await key('Enter', 'leave');
	await page.keyboard.down('Enter');
	await page.waitForURL(url => !url.pathname.includes('terrain-sandbox'), {timeout: 30_000});
	expect(new URL(page.url()).pathname).toBe('/');
});
