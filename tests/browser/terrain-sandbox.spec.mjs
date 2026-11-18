import {test, expect} from '@playwright/test';
import fs from 'node:fs/promises';

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

		return !now.preparing && !now.picking && !now.renderer.pending && !now.renderer.pendingEdits && unchanged;
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
	await control(page, 'craft').click();
	await expect.poll(async() => (await status(page)).mode).toBe('craft');
	expect((await status(page)).paused).toBe(false);
	expect((await status(page)).generationBudgetMs).toBe(2);
	const before = (await status(page)).craft;
	await canvas(page).focus();
	await page.keyboard.down('w');

	await expect.poll(async() => {
		const after = (await status(page)).craft;

		return Math.hypot(after.x - before.x, after.z - before.z);
	}).toBeGreaterThan(.5);

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
