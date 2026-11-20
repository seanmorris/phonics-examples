import {test, expect} from '@playwright/test';
import {PNG} from 'pngjs';
import fs from 'node:fs/promises';
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
	const section = await page.locator(selector).first().evaluate(element => element.closest('[role="tabpanel"]')?.id ?? '');

	if(section) await page.locator(`.terrain-sandbox [aria-controls="${section}"]`).click();

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
		const locations = new WeakSet();
		const getUniformLocation = gl.getUniformLocation, uniform2f = gl.uniform2f;
		gl.getUniformLocation = function(program, name) {
			const location = getUniformLocation.call(this, program, name);
			if(location && name === 'uHeading') locations.add(location);
			return location;
		};
		gl.uniform2f = function(location, cosine, sine) {
			if(locations.has(location)) window.terrainChartHeading = [cosine, sine];
			return uniform2f.call(this, location, cosine, sine);
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
		const pixels = el.getContext('2d').getImageData(0,0,w,h).data;
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
});

test('cockpit cues show the nearest balloon and the diamond meets the selected balloon at lock', async({page}) => {
	const source = await fs.readFile(new URL('../../games/terrain-sandbox/browser/cockpit.mjs', import.meta.url), 'utf8');
	await page.setContent('<div><canvas></canvas></div>');

	const states = await page.evaluate(async source => {
		const url = URL.createObjectURL(new Blob([source], {type: 'text/javascript'}));
		const {activate} = await import(url);
		URL.revokeObjectURL(url);
		const module = {drawTerrainMap() {}, drawTerrainSpeedometer() {}};
		activate({module, canvas: document.querySelector('canvas')});
		const ctx = document.querySelector('.terrain-instruments').getContext('2d');
		const frame = {
			width: 800, height: 720, cockpit: true, ring: .285, contactRange: 4000, fov: Math.PI/2
			, eye: [0,0,0], basis: [[0,0,-1],[1,0,0],[0,1,0]]
			, jet: {x: 0, y: 0, z: 0, yaw: Math.PI, pitch: 0, vx: -20, vy: 15, vz: -120, speed: 120}
			, thrust: 75, targets: {1: [250,125,-1000], 2: [400,400,-400]}
			, range: {selected: 1, visible: true, locked: false, elapsed: 5, ground: 0, ready: 2}
		};

		// The winged marker is at (-60,-45). Check ink at diamond tips, clear of the target box and pitch ladder.
		function ink(x, y, red = false)
		{
			const pixels = ctx.getImageData(399+x,359+y,3,3).data;

			for(let i = 0; i<pixels.length; i+=4)
				if(pixels[i+3] > 120 && (red ? pixels[i] > 200 && pixels[i+1] < 100 : pixels[i+1] > 200 && pixels[i] < 140))
					return true;

			return false;
		}

		function sample(elapsed, progress)
		{
			frame.range.elapsed = elapsed;
			frame.range.lockProgress = progress;
			frame.range.locked = progress === 1;
			module.terrainVehicleFrame(JSON.stringify(frame));

			return [ink(-60,-50), ink(15,-52), ink(6,-52), ink(72,-54,true), ink(72,-58,true)];
		}

		sample(5,0);
		const waiting = sample(9,0), nearestRight = ink(150,-150), underRim = ink(142,-142);
		// Move the nearest balloon across the rim with a farther contact still outside on the opposite side.
		frame.targets[3] = [-700,-700,-600];
		frame.targets[2] = [161,161,-400];
		sample(9.04,0);
		const inside = ink(150,-150) || ink(-150,150);
		frame.targets[2] = [162,162,-400];
		sample(9.08,0);
		const outsideAgain = ink(150,-150);
		frame.targets[2] = [-10,-10,400];
		const moving = sample(9.15,.5), nearestBehind = ink(-150,150);
		const paused = sample(9.15,.5);
		frame.targets[1][0] = 200;
		const follows = sample(9.16,.5);
		const contact = sample(9.3,1), pausedContact = sample(9.3,1), locked = sample(9.33,1);
		const interrupted = sample(10,0);
		frame.range.visible = false;
		const hidden = sample(10.2,0);
		frame.targets = {2: [5000,5000,0]};
		sample(10.3,0);
		const distant = ink(150,-150) || ink(-150,150);
		frame.range.visible = true;
		frame.range.selected = 2;
		const cycle = sample(10.4,0);
		frame.cockpit = false;
		const chase = sample(10.5,.5);

		return {waiting,moving,paused,follows,contact,pausedContact,locked,interrupted,hidden,cycle,chase,
			nearestRight,underRim,inside,outsideAgain,nearestBehind,distant};
	}, source);

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
		const pad = {id: 'Flight-path test', index: 0, mapping: 'standard', timestamp: 1, connected: true, axes, buttons};
		Object.defineProperty(navigator, 'getGamepads', {value: () => [pad]});
		window.flightStick = (x,y) => {
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

	await page.evaluate(() => window.flightStick(.5,.35));
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
	expect(Math.hypot(x,y)).toBeGreaterThan(12);
	expect(x).toBeCloseTo(focal*(-left*Math.cos(jet.roll) + up*Math.sin(jet.roll))/depth, 3);
	expect(y).toBeCloseTo(-focal*(left*Math.sin(jet.roll) + up*Math.cos(jet.roll))/depth, 3);
	await page.evaluate(() => window.flightStick(0,0));
	await page.keyboard.press('p');
	await expect.poll(() => page.evaluate(() => Math.hypot(...window.pathOffset))).toBeLessThan(1);
});

test('both F5 exhausts brighten as the engine spools through cruise and afterburner', async({page}) => {
	await boot(page);
	const airspace = fixture();
	airspace.landscape.recipe.worldSize = 8192;
	airspace.camera = {position: [0,500,0], yaw: 0, pitch: 0};
	await load(page, airspace);
	const indexCount = gunzipSync(await fs.readFile(new URL('../../games/terrain-sandbox/assets/f5/mesh.bin.gz', import.meta.url))).readUInt32LE(4);
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
				const point = [0,1,2].map(i => origin[i]+local.reduce((v,n,j) => v+n*basis[3*j+i], 0));
				point.push(1);
				const clip = [0,1,2,3].map(i => point.reduce((v,n,j) => v+n*projection[4*j+i], 0));
				const px = Math.floor((1+clip[0]/clip[3])*gl.drawingBufferWidth/2);
				const py = Math.floor((1+clip[1]/clip[3])*gl.drawingBufferHeight/2);
				const pixels = new Uint8Array(36);
				gl.readPixels(px-1,py-1,3,3,gl.RGBA,gl.UNSIGNED_BYTE,pixels);
				return pixels.reduce((sum,v,i) => sum+(i%4 < 2 ? v : 0), 0)/9;
			});
		};
	}, indexCount);
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
	const counts = await Promise.all(['f5','projectile'].map(async name =>
		gunzipSync(await fs.readFile(new URL(`../../games/terrain-sandbox/assets/${name}/mesh.bin.gz`, import.meta.url))).readUInt32LE(4)));
	const view = canvas(page);
	await view.evaluate((el,[jetCount,projectileCount]) => {
		const gl = el.getContext('webgl2'), draw = gl.drawElements.bind(gl);
		let body;
		el.mounted = [];
		gl.drawElements = (...args) => {
			if(args[1] === jetCount || args[1] === projectileCount)
			{
				const p = gl.getParameter(gl.CURRENT_PROGRAM);
				const origin = gl.getUniform(p,gl.getUniformLocation(p,'uOrigin'));
				if(args[1] === jetCount) { body = origin; el.mounted = []; }
				else if(body && Math.abs(origin[2]-body[2]) < 1 && origin[1] < body[1])
					el.mounted.push(origin[0] < body[0] ? 'left' : 'right');
			}
			return draw(...args);
		};
	}, counts);
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
	await expect.poll(wings, {intervals:[75]}).toEqual(['left']);
	await expect.poll(wings).toEqual(['left','right']);
});

test('F5 cockpit, charge and Vulcan controls work in balloon practice', async({page}) => {
	test.setTimeout(180_000);
	await page.setViewportSize({width: 720, height: 480});
	const errors = [];
	page.on('pageerror', error => errors.push(error.message));

	await page.addInitScript(() => {
		const buttons = Array.from({length: 17}, () => ({value: 0, pressed: false}));
		const pad = {id: 'F5 test controller', index: 0, timestamp: 1, mapping: 'standard', connected: true,
			axes: [0,0,0,0], buttons};
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
		const pixels = el.getContext('2d').getImageData(0,0,el.width,el.height).data;
		for(let i = 0; i < pixels.length; i += 4)
		{
			const x = (i/4)%el.width;
			if(Math.abs(x-el.width/2) > .12*el.width
				&& pixels[i] === 255 && pixels[i+1] === 99 && pixels[i+2] === 87) return true;
		}
		return false;
	})).toBe(true);
	expect((await status(page)).balloons.locked).toBe(false);
	// Cycle from the distant practice blip to the first of two authored balloons on this flight path.
	await canvas(page).focus();
	await page.keyboard.press('t');
	await expect.poll(async() => (await status(page)).balloons.locked).toBe(true);
	// The authored balloon is straight ahead. Read the rendered impact at the centre of the 3D canvas,
	// before the cockpit overlay, and compare the same pixels immediately before the effect draw.
	await canvas(page).evaluate(el => {
		const gl = el.getContext('webgl2'), draw = gl.drawArraysInstanced.bind(gl);
		const before = new Uint8Array(48*48*4), after = new Uint8Array(before.length);
		const read = pixels => gl.readPixels(Math.floor(gl.drawingBufferWidth/2)-24,Math.floor(gl.drawingBufferHeight/2)-24,
			48,48,gl.RGBA,gl.UNSIGNED_BYTE,pixels);
		gl.drawArraysInstanced = (...args) => {
			if(el.sawBurst || args[1] !== 0 || args[2] !== 72 || !JSON.parse(el.dataset.terrainSandbox).balloons.hit)
				return draw(...args);
			read(before);
			draw(...args);
			read(after);
			for(let i = 0; i < after.length; i += 4)
				if(after[i] > 190 && after[i] > after[i+2]+70 && after[i] > before[i]+35) { el.sawBurst = true; break; }
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

test('drive the Testarossa from a dry clearing and switch vehicles', async({page}) => {
	const errors = [];
	page.on('pageerror', error => errors.push(error.message));
	await page.setViewportSize({width: 900, height: 600});
	await page.addInitScript(() => {
		const buttons = Array.from({length: 17}, () => ({value: 0, pressed: false}));
		const pad = {id: 'Car test controller', index: 0, timestamp: 1, mapping: 'standard', connected: true,
			axes: [0,0,0,0], buttons};
		Object.defineProperty(navigator, 'getGamepads', {value: () => [pad]});
		window.clickCarStick = pressed => {
			buttons[10] = {value: Number(pressed), pressed};
			pad.timestamp++;
		};
	});
	await boot(page);
	const dry = fixture();
	dry.settings.waterLevel = -4;
	dry.camera = {position: [0,30,0], yaw: Math.PI, pitch: -.4};
	await load(page, dry);
	const view = canvas(page);
	const mesh = await fs.readFile(new URL('../../games/terrain-sandbox/assets/testarossa/mesh.bin.gz', import.meta.url));
	await view.evaluate((element, count) => {
		const gl = element.getContext('webgl2'), draw = gl.drawElements.bind(gl);
		const viewport = gl.viewport.bind(gl);
		element.carDraws = 0;
		gl.viewport = (x,y,w,h) => {
			if(x === 10 && y === 10) element.chartSize = [w,h];
			return viewport(x,y,w,h);
		};
		gl.drawElements = (...args) => {
			if(args[1] === count) element.carDraws++;
			return draw(...args);
		};
	}, gunzipSync(mesh).readUInt32LE(4));
	await press(page, 'car');
	await expect.poll(async() => (await status(page)).mode).toBe('car');
	await expect.poll(() => view.evaluate(element => element.carDraws)).toBeGreaterThan(0);
	const map = page.locator('.terrain-flight-map'), dial = page.locator('.terrain-speedometer');
	await expect(map).toBeVisible();
	await expect(dial).toHaveAttribute('aria-label', 'Speedometer, 0 miles per hour');
	await expect(page.locator('.terrain-instruments')).toBeHidden();
	const viewport = await view.boundingBox(), mapBox = await map.boundingBox(), dialBox = await dial.boundingBox();
	expect(mapBox.width).toBe(dialBox.width);
	expect(mapBox.width).toBeGreaterThan(Math.round(Math.min(184, viewport.width*.235, viewport.height*.245)));
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
		const pixels = ctx.getImageData(0,0,size,size).data;
		let xSum = 0, ySum = 0;
		for(let y = 0; y < size; y++)
		for(let x = 0; x < size; x++)
		{
			const i = 4*(y*size+x);
			if(Math.hypot(x-size/2,y-size/2) < .13*size || pixels[i] < 200 || pixels[i+1] > 90) continue;
			xSum += x-size/2;
			ySum += y-size/2;
		}
		return (Math.atan2(ySum,xSum) + 2*Math.PI) % (2*Math.PI);
	});
	expect(await needle()).toBeCloseTo(.75*Math.PI, 1);
	await expect(control(page, 'car')).toBeHidden();
	await expect(control(page, 'fly')).toBeVisible();
	await view.focus();
	await page.keyboard.press('m');
	await expect(map).toBeHidden();
	await expect(dial).toBeVisible();
	await page.evaluate(() => window.clickCarStick(true));
	await expect(map).toBeVisible();
	await page.evaluate(() => window.clickCarStick(false));
	await page.keyboard.down('w');
	await page.keyboard.down('d');
	await expect.poll(async() => (await status(page)).car.x).toBeLessThan(-.2);
	await expect.poll(needle).toBeGreaterThan(.75*Math.PI + .15);
	await page.keyboard.up('d');
	await page.keyboard.up('w');
	const beforeRestore = await view.evaluate(element => element.carDraws);
	await restoreContext(page);
	await expect.poll(() => view.evaluate(element => element.carDraws)).toBeGreaterThan(beforeRestore);
	expect(await view.evaluate(element => element.getContext('webgl2').getError())).toBe(0);
	await view.focus();
	await page.keyboard.press('f');
	await expect.poll(async() => (await status(page)).mode).toBe('fly');
	await expect(map).toBeHidden();
	await expect(dial).toBeHidden();
	await page.keyboard.press('c');
	await expect.poll(async() => (await status(page)).mode).toBe('car');
	await (await field(page, '[data-value="waterLevel"]')).fill('10');
	await expect.poll(async() => (await status(page)).mode).toBe('fly');
	await press(page, 'craft');
	await expect.poll(async() => (await status(page)).mode).toBe('craft');
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
	// By day the beams would make no visible difference, so the renderer gets no spot lights.
	expect((await status(page)).renderer.spotLights).toBe(0);
	expect(await page.evaluate(() => localStorage.getItem('terrain-sandbox.car'))).toBe('{"headlights":"on"}');

	// Below the horizon each open headlamp lights the ground as one of the renderer's spot lights.
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

test('the default canyon lets all four wheels leave the ground and touch down again', async({page}) => {
	const errors = [];
	page.on('pageerror', error => errors.push(error.message));
	await page.setViewportSize({width:672,height:420});
	await page.goto('/games/terrain-sandbox/');
	const view = canvas(page);
	await expect(view).toHaveAttribute('data-terrain-sandbox', /"recipe":/, {timeout:60000});
	await (await field(page, '[name="preset"]')).selectOption('canyon');
	await press(page, 'generate');
	await expect.poll(async() => (await status(page)).recipe.preset).toBe('canyon');
	await press(page, 'car');
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
	test.setTimeout(360_000);
	const errors = [];
	page.on('pageerror', error => errors.push(error.message));
	await page.setViewportSize({width:672,height:420});
	await boot(page);
	const ramp = fixture({history:[[{type:'raise',x:0,z:45,radius:16,amount:30}]],revision:1});
	Object.assign(ramp.landscape.recipe, {worldSize:512,spacing:1});
	ramp.settings.waterLevel = -100;
	ramp.camera = {position:[0,30,-40],yaw:Math.PI,pitch:-.4};
	await load(page,ramp);
	await press(page, 'car');
	await expect.poll(async() => (await status(page)).mode).toBe('car');
	await canvas(page).focus();
	await page.keyboard.down('w');
	// The software renderer simulates well below real time, and slower still on a loaded machine.
	await expect.poll(async() => (await status(page)).car.motion, {timeout:60000}).toBe('tumbling');
	await page.keyboard.up('w');
	await expect.poll(async() => (await status(page)).car.motion, {timeout:120000}).toBe('overturned');
	const landed = (await status(page)).car;
	expect(Math.cos(landed.pitch)*Math.cos(landed.roll)).toBeLessThan(-.8);
	await expect.poll(async() => (await status(page)).car.recoveries, {timeout:60000}).toBe(1);
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

	await field(page, '[data-value="waterFogDensity"]');
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
	// The file buttons open the Landscape tab. The Water and Sky fields still hold the loaded values while hidden.
	const chopField = page.locator('[data-value="waterChoppiness"]'), glowField = page.locator('[data-value="nightGlow"]');
	const levelField = page.locator('[data-value="waterLevel"]'), fineField = page.locator('[data-fine-fog]');
	await load(page, fixture());
	await expect(chopField).toHaveValue('100');
	await expect(glowField).toHaveValue('0.5');
	await load(page, sea);
	await expect(levelField).toHaveValue('6.5');
	await expect(chopField).toHaveValue('300');
	await expect(glowField).toHaveValue('1');
	await expect(fineField).toHaveValue('0.9999');
	await expect(page.locator('[data-value="waterFogDensity"]')).toHaveValue('1');

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

	await press(page, 'road-apply');
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

test('the panel tabs work from the keyboard and the Surface tab changes the ground', async({page}) => {
	await page.goto('/games/terrain-sandbox/');
	await expect(canvas(page)).toHaveAttribute('data-terrain-sandbox', /"recipe":/, {timeout: 60_000});
	const tab = name => page.locator(`.terrain-sandbox #terrain-tab-${name}`);
	await expect(page.locator('.terrain-sandbox [role="tab"]')).toHaveCount(6);
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

	// The coast reads grass and the rock strata, not the mountain granite.
	await tab('surface').click();
	await expect(page.locator('[data-surface-key="grassColor"]')).toBeVisible();
	await expect(page.locator('[data-surface-key="bandSpacing"]')).toBeVisible();
	await expect(page.locator('[data-surface-key="graniteColor"]')).toBeHidden();
	await expect(page.locator('[data-surface-group="rock"]')).toBeHidden();

	let frames = -1;
	await expect.poll(async() => {
		const now = (await status(page)).renderer.frames;
		const still = now === frames;
		frames = now;
		return still;
	}, {timeout: 120_000, intervals: [1500]}).toBe(true);
	await page.locator('[data-value="grassColor"]').fill('#ff0000');
	await expect.poll(async() => (await status(page)).settings.grassColor).toBe('#ff0000');
	await expect.poll(async() => (await status(page)).renderer.frames).toBeGreaterThan(frames);

	// Raising where rock starts past where it covers moves the end up with it, and the end's slider shows that.
	await page.locator('[data-value="rockSlopeStart"]').evaluate(element => {
		element.value = '0.6';
		element.dispatchEvent(new Event('input', {bubbles: true}));
	});
	await expect.poll(async() => (await status(page)).settings.rockSlopeEnd).toBeCloseTo(.61, 9);
	await expect(page.locator('[data-value="rockSlopeEnd"]')).toHaveValue('0.61');
	const file = await save(page);
	expect(file.settings.grassColor).toBe('#ff0000');
	expect(file.settings.rockSlopeStart).toBe(.6);

	// Saving opened the Landscape tab, which holds the file buttons.
	await tab('surface').click();
	await page.locator('[data-surface-reset="all"]').click();
	await expect.poll(async() => (await status(page)).settings.grassColor).toBe('#425b2d');
	await expect.poll(async() => (await status(page)).settings.rockSlopeStart).toBe(.08);

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
test('landform parameters and nested colours survive a save and reload', async({page}) => {
	await page.setViewportSize({width: 720, height: 460});
	await boot(page);
	await page.getByRole('tab', {name: 'Landscape', exact: true}).click();
	await page.locator('[name="preset"]').selectOption('canyon');
	await page.getByText('Landform parameters', {exact: true}).click();
	const wall = page.getByLabel('Wall width', {exact: true});
	await expect(wall).toHaveValue('0.006');
	await wall.fill('0.0085');
	await page.getByRole('combobox', {name: 'Surround', exact: true}).selectOption('plateau');
	await press(page, 'generate');
	await expect.poll(async() => (await status(page)).recipe.wallWidth).toBe(.0085);
	await expect.poll(async() => (await status(page)).recipe.version).toBe(2);

	await page.locator('[aria-controls="terrain-section-surface"]').click();
	await page.getByText('Colour layers', {exact: true}).click();
	const layers = page.locator('[data-layer-fields] [data-layer]');
	const child = layers.nth(1);
	await child.locator('summary').click();
	await child.locator('[data-layer-key="parent"]').fill('0');
	await child.locator('[data-layer-key="color"]').fill('#7e513d');
	await child.getByRole('combobox', {name:'Colour pattern'}).selectOption('streaks');
	await child.locator('[data-layer-key="patternColor"]').fill('#2f2120');
	await child.locator('[data-layer-key="patternAmount"]').fill('0.7');
	await child.locator('[data-layer-key="patternScale"]').fill('18');
	await child.locator('[data-layer-key="patternStretch"]').fill('6');
	await page.getByRole('button', {name: 'Apply layers', exact: true}).click();
	await expect.poll(async() => (await status(page)).surfaceLayers[1].color).toBe('#7e513d');

	const document = await save(page);
	expect(document.version).toBe(4);
	expect(document.landscape.recipe.wallWidth).toBe(.0085);
	expect(document.landscape.recipe.surround).toBe('plateau');
	expect(document.layers[1]).toMatchObject({
		color: '#7e513d', parent: 0, pattern: 'streaks', patternColor: '#2f2120',
		patternAmount: .7, patternScale: 18, patternStretch: 6,
	});
	await load(page, fixture());
	await load(page, document);
	await expect.poll(async() => (await status(page)).surfaceLayers).toEqual(document.layers);
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

	for(const position of [[-701, 550, -703], [709, 550, 717]]) {
		doc.camera = {position, yaw: .71, pitch: -1.52};
		await load(page, doc);
		const result = await settled(page);
		expect(result.renderer.visible).toBeGreaterThan(4);
		expect(result.renderer.proceduralVertexBytes).toBe(0);
		const image = PNG.sync.read(await canvas(page).screenshot());
		let sky = 0;
		// Left-hand crop is clear of the controls. A missing triangle in this down-facing
		// view shows the sky's blue or the blue clear colour through the red ground.
		for(let y = 45; y < image.height - 110; ++y)
		for(let x = 35; x < 265; ++x) {
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

test('the F5 keeps the canyon wall in the fine grid as it flies past clipmap joins', async({page}) => {
	test.setTimeout(180_000);
	await page.setViewportSize({width: 720, height: 440});
	const errors = [];
	page.on('pageerror', error => errors.push(error.message));
	await boot(page);

	const canyon = fixture();
	canyon.landscape.recipe = {
		preset: 'canyon', version: 2, seed: 'visual-canyon', heightScale: 650,
		baseFrequency: 1/4000, terraceSpacing: 80, waterLevel: -450,
	};
	canyon.settings.quality = 1;
	canyon.camera = {position: [791,570,-1771], yaw: .09, pitch: -.38};
	await load(page, canyon);

	// This wall is about 1.5 km from the starting camera. The old 64 m mesh smoothed
	// the ledges and changed their shape as the aircraft advanced.
	const wallSpacing = ({renderer}) => renderer.visibleCoverage.find(([left,front,size]) =>
		left <= 1867 && 1867 < left+size && front <= -3217 && -3217 < front+size)?.[3];
	const before = await settled(page);
	expect(wallSpacing(before)).toBeLessThanOrEqual(16);

	await press(page, 'jet');
	await expect.poll(async() => (await status(page)).mode).toBe('jet');
	await canvas(page).focus();
	await page.keyboard.press('v');
	await expect.poll(async() => (await status(page)).jetView).toBe('cockpit');

	const initial = await status(page);
	await expect.poll(async() => (await status(page)).jet.z, {timeout:90_000}).toBeLessThan(initial.jet.z - 65);
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
