import {test,expect} from '@playwright/test';
import {waitForMenuFrames} from './menu-frames.mjs';

async function start(page, {scene = 'platformer',fallback  = false} = {})
{
	const errors = [];
	page.on('pageerror', e => errors.push(e.message));

	await page.addInitScript(({fallback}) => {
		if(fallback) Object.defineProperty(window, 'EditContext', {value: undefined});

		window.menuPads = [];
		Object.defineProperty(navigator, 'getGamepads', {value: () => menuPads});

		window.menuPad = (name, buttons = []) => {
			if(!menuPads[0] || menuPads[0].id!==name)
			{
				if(menuPads[0])
				window.dispatchEvent(Object.assign(new Event('gamepaddisconnected'), {gamepad: menuPads[0]}));

				menuPads[0] = {
					id: name
					, index: 0
					, connected: true
					, mapping: 'standard'
					, timestamp: performance.now()
					, axes: [0,0,0,0]
					, buttons: Array.from({length: 17}, () => ({pressed: false,value: 0}))
				};

				window.dispatchEvent(Object.assign(new Event('gamepadconnected'), {gamepad: menuPads[0]}));
			}

			menuPads[0].buttons = Array.from({length: 17}, (_, i) => ({
				pressed: buttons.includes(i)
				, value: buttons.includes(i) ? 1 : 0
			}));

			menuPads[0].timestamp = performance.now();
		};
	}, {fallback});

	await page.goto('/sane-phonics/');
	await page.waitForFunction(() => typeof window.bootEngine==='function');
	await page.evaluate(scene => window.bootEngine({scene}), scene);
	await expect.poll(() => state(page)).not.toBeNull();

	return errors;
}

const state = page => page.locator('canvas').evaluate(canvas => JSON.parse(canvas.dataset.menu??'null'));

async function camera(page)
{
	return page.evaluate(async() => {
		const module = await enginePhp.binary;

		await enginePhp.run(`<?php
		vrzno_env('canvas')->dataset->cameraProbe = json_encode([
			$camera->x, $camera->y, $camera->width, $camera->height, $camera->zoom
		]);
		`
		);

		return JSON.parse(module.canvas.dataset.cameraProbe);
	});
}

async function open(page)
{
	await page.locator('canvas').focus();
	await page.keyboard.press('p');
	await expect.poll(async() => (await state(page)).open).toBe(true);
}

async function choose(page, id)
{
	await waitForMenuFrames(page);

	await page.evaluate(async id => {
		const code = await enginePhp.run('<?php $pauseMenu->menu->select(' + JSON.stringify(id) + ');');

		if(code) throw Error('select failed');
	}, id
	);
}

test.afterEach(async({page}) => {await page.evaluate(() => window.enginePhp?.refresh()).catch(() => {});});

test('pause menus keep simulation stopped while nested controls apply live', async({page}) => {
	const errors = await start(page);
	await open(page);
	const time = (await state(page)).time;
	await page.keyboard.press('ArrowDown');
	await expect.poll(async() => (await state(page)).selected).toBe('settings');
	await page.keyboard.press('Enter');
	await expect.poll(async() => (await state(page)).title).toBe('Settings');
	await page.evaluate(() => enginePhp.run('<?php $camera->x = 100; $camera->y = 50;'));
	const beforeZoom = await camera(page);
	const center = [
		beforeZoom[0] + beforeZoom[2] / beforeZoom[4] / 2
		, beforeZoom[1] + beforeZoom[3] / beforeZoom[4] / 2
	];

	await choose(page, 'zoom');
	await page.keyboard.press('ArrowRight');
	await expect.poll(async() => (await state(page)).values.zoom).toBe(1.25);
	const afterZoom = await camera(page);
	expect(afterZoom[0] + afterZoom[2] / afterZoom[4] / 2).toBeCloseTo(center[0]);
	expect(afterZoom[1] + afterZoom[3] / afterZoom[4] / 2).toBeCloseTo(center[1]);
	await choose(page, 'hints');
	await page.keyboard.press('Space');
	await expect.poll(async() => (await state(page)).values.hints).toBe(false);
	await choose(page, 'motion-blur');
	await page.keyboard.press('Space');
	await expect.poll(async() => (await state(page)).values['motion-blur']).toBe(false);
	await page.keyboard.press('Escape');
	await expect.poll(async() => (await state(page)).selected).toBe('settings');
	expect((await state(page)).time).toBe(time);
	await waitForMenuFrames(page);
	await page.keyboard.press('Escape');
	await expect.poll(async() => (await state(page)).open).toBe(false);
	await expect.poll(async() => (await state(page)).time).toBeGreaterThan(time);
	expect(errors).toEqual([]);
});

for(const fallback of [false,true])
test('live text editing and external focus with ' + (fallback
	? 'textarea'
	: 'EditContext'
), async({page}) => {
	const errors = await start(page, {fallback});
	await open(page);
	await choose(page, 'settings');
	await page.keyboard.press('Enter');
	await choose(page, 'name');
	await page.keyboard.press('Enter');
	await expect.poll(async() => (await state(page)).editing).toBe(true);
	await page.keyboard.press('Control+a');
	await page.keyboard.insertText('Paws xrz');
	await expect.poll(async() => (await state(page)).values.name).toBe('Paws xrz');
	expect((await state(page)).open).toBe(true);
	await page.keyboard.press('Backspace');
	await expect.poll(async() => (await state(page)).values.name).toBe('Paws xr');
	await page.keyboard.down('Escape');
	await expect.poll(async() => (await state(page)).editing).toBe(false);
	await page.waitForTimeout(150);
	expect((await state(page)).title).toBe('Settings');
	await page.keyboard.up('Escape');
	await page.locator('#mute').focus();
	await page.keyboard.press('p');
	expect((await state(page)).open).toBe(true);
	await page.locator('canvas').focus();
	await choose(page, 'name');
	await page.keyboard.press('Enter');
	await expect.poll(async() => (await state(page)).editing).toBe(true);
	await page.keyboard.press('End');
	await page.keyboard.type(' ok');
	await expect.poll(async() => (await state(page)).values.name).toBe('Paws xr ok');
	await page.keyboard.down('Enter');
	await page.waitForTimeout(150);
	expect((await state(page)).editing).toBe(false);
	await page.keyboard.up('Enter');
	expect(errors).toEqual([]);
});

for(const fallback of [false,true])
for(const recovery of ['visibility','focus','graphics'])
{
	test(
		'editing discards held-key repeats after ' + recovery + ' recovery with '
			+ (fallback ? 'textarea' : 'EditContext')
		, async(
			{page}
		) => {
			const errors = await start(page, {fallback});
			await open(page);
			await choose(page, 'settings');
			await page.keyboard.press('Enter');
			await choose(page, 'name');
			await page.keyboard.press('Enter');
			await expect.poll(async() => (await state(page)).editing).toBe(true);
			await page.keyboard.press('Control+a');
			await page.keyboard.insertText('abcdefgh');
			await expect.poll(async() => (await state(page)).values.name).toBe('abcdefgh');
			await page.keyboard.down('Backspace');
			await expect.poll(async() => (await state(page)).values.name).toBe('abcdefg');
			await page.keyboard.down('Backspace');
			await expect.poll(async() => (await state(page)).values.name).toBe('abcdef');

			const frames = () => page.locator('canvas').evaluate(canvas => Number(canvas.dataset.frames));

			if(recovery==='visibility')
			{
				await page.evaluate(() => {
					Object.defineProperty(document, 'hidden', {configurable: true,get: () => true});
					document.dispatchEvent(new Event('visibilitychange'));
				});

				await page.waitForTimeout(100);

				await page.evaluate(() => {
					delete document.hidden;
					document.dispatchEvent(new Event('visibilitychange'));
				});
			}
			else if(recovery==='focus')
			{
				await page.locator('#mute').focus();
				await page.waitForTimeout(100);
				await page.locator('canvas').focus();
			}
			else
			{
				await page.evaluate(() => {
					window.menuLoss = document.querySelector('canvas').getContext('webgl2').getExtension(
						'WEBGL_lose_context'
					);

					menuLoss.loseContext();
				});

				await expect(page.locator('#status')).toContainText('Graphics paused');
				await page.evaluate(() => menuLoss.restoreContext());
			}

			const recovered = await frames();
			await expect.poll(frames).toBeGreaterThan(recovered+2);
			expect((await state(page)).editing).toBe(true);
			await page.keyboard.down('Backspace');
			await page.keyboard.down('Backspace');
			const repeated = await frames();
			await expect.poll(frames).toBeGreaterThan(repeated+2);
			expect((await state(page)).values.name).toBe('abcdef');
			await page.keyboard.up('Backspace');
			await page.keyboard.down('Backspace');
			await expect.poll(async() => (await state(page)).values.name).toBe('abcde');
			await page.keyboard.down('Backspace');
			await expect.poll(async() => (await state(page)).values.name).toBe('abcd');
			await page.keyboard.up('Backspace');
			expect(errors).toEqual([]);
		}
	);
}

test('pad hints switch themes on input and allow an explicit override', async({page}) => {
	const errors = await start(page);
	await open(page);

	for(const [name,theme] of [
		['Xbox Wireless Controller','xbox']
		, ['Sony DualSense (054c)','playstation']
		, ['10-button USB Controller','generic']
	]){
		await page.evaluate(name => menuPad(name), name);
		await page.waitForTimeout(60);
		await page.evaluate(name => menuPad(name, [13]), name);
		await expect.poll(async() => (await state(page)).theme).toBe(theme);
		await page.evaluate(name => menuPad(name), name);
		await page.waitForTimeout(60);
	}

	await page.keyboard.press('ArrowUp');
	await expect.poll(async() => (await state(page)).theme).toBe('keyboard');
	await choose(page, 'settings');
	await page.keyboard.press('Enter');
	await choose(page, 'theme');
	await page.keyboard.press('ArrowRight');
	await expect.poll(async() => (await state(page)).values.theme).toBe('keyboard');
	await page.evaluate(() => menuPad('Xbox Wireless Controller', [13]));
	await page.waitForTimeout(100);
	expect((await state(page)).theme).toBe('keyboard');
	expect(errors).toEqual([]);
});

async function point(page, id, at = 'center')
{
	const region = (await state(page)).regions.find(row => row.id===id);
	expect(region, 'visible hit region for ' + id).toBeDefined();

	return page.locator('canvas').evaluate((canvas, {region,at}) => {
		const bounds = canvas.getBoundingClientRect();
		const [x,y,w,h] = region.rect;
		const px = at==='slider' ? region.slider[0]+region.slider[1]/2 : x+w/2;

		return {x: bounds.x+px/canvas.width*bounds.width,y: bounds.y+(y+h/2)/canvas.height*bounds.height};
	}, {region,at});
}

test('pointer sliders clamp outside their track and reset without opening an editor', async({page}) => {
	const errors = await start(page);
	await open(page);
	const settings = await point(page, 'settings');
	await page.mouse.click(settings.x, settings.y);
	await expect.poll(async() => (await state(page)).title).toBe('Settings');
	const slider = await point(page, 'scale', 'slider');
	await page.mouse.move(slider.x, slider.y);
	await page.mouse.down();
	const canvas = await page.locator('canvas').boundingBox();
	await page.mouse.move(canvas.x+10, slider.y, {steps: 3});
	await expect.poll(async() => (await state(page)).values.scale).toBe(1);
	expect((await state(page)).editing).toBe(false);
	await page.mouse.move(canvas.x+canvas.width-10, slider.y, {steps: 3});
	await expect.poll(async() => (await state(page)).values.scale).toBe(3);
	await page.mouse.up();
	const reset = await point(page, 'scale');
	await page.mouse.click(reset.x, reset.y, {button: 'middle'});
	await expect.poll(async() => (await state(page)).values.scale).toBe(2);
	await choose(page, 'hints');
	const hints = await point(page, 'hints');
	await page.mouse.click(hints.x, hints.y);
	await expect.poll(async() => (await state(page)).values.hints).toBe(false);
	await page.mouse.wheel(0, 200);
	await expect.poll(async() => (await state(page)).selected).toBe('debug');
	expect(errors).toEqual([]);
});

test('wheel pixels, lines and pages each move one menu item', async ({page}) => {
	const errors = await start(page);
	await open(page);
	await choose(page, 'settings');
	await page.keyboard.press('Enter');

	for(const [deltaMode, deltaY] of [[0, 120], [1, 4], [2, 1]])
	{
		await choose(page, 'hints');

		for(const direction of [1, -1])
		{
			await page.locator('canvas').evaluate((canvas, options) => {
				canvas.dispatchEvent(new WheelEvent('wheel', {
					...options
					, bubbles: true
					, cancelable: true
				}));
			}, {deltaMode, deltaY: deltaY * direction});

			await expect.poll(async () => (await state(page)).selected)
				.toBe(direction > 0 ? 'debug' : 'hints');
		}
	}

	expect(errors).toEqual([]);
});

async function composition(page, fallback, phase, text = 'かな')
{
	await page.evaluate(({fallback,phase,text}) => {
		const target = fallback
			? document.querySelector('textarea[aria-label="SDL text input"]')
			: document.querySelector('canvas').editContext;

		if(phase==='start')
		{
			target.dispatchEvent(new CompositionEvent('compositionstart'));

			if(fallback)
			{
				target.value = text;
				target.setSelectionRange(1, 2);
			}
			else
			{
				target.updateText(0, target.text.length, text);
				target.updateSelection(1, 2);
			}

			target.dispatchEvent(
				fallback
					? new InputEvent('input', {data: text,inputType: 'insertCompositionText',isComposing: true})
					: new TextUpdateEvent('textupdate', {text,selectionStart: 1,selectionEnd: 2})
			);
		}
		else
		{
			target.dispatchEvent(new CompositionEvent('compositionend', {data: text}));

			if(fallback) target.dispatchEvent(new InputEvent('input', {data: text,inputType: 'insertText'}));
		}
	}, {fallback,phase,text});
}

for(const fallback of [false,true])
test(
	'fullscreen IME commits once and cancels on blur with ' + (fallback ? 'textarea' : 'EditContext')
	, async({page}) => {
		const errors = await start(page, {fallback});
		await open(page);
		await choose(page, 'settings');
		await page.keyboard.press('Enter');
		await choose(page, 'name');
		await page.keyboard.press('Enter');
		await expect.poll(async() => (await state(page)).editing).toBe(true);
		await page.click('#fullscreen');
		await expect.poll(() => page.evaluate(() => document.fullscreenElement?.id)).toBe('game-frame');
		await expect.poll(async() => (await state(page)).editing).toBe(true);

		if(fallback) await expect(page.locator('textarea[aria-label="SDL text input"]')).toBeFocused();

		await page.keyboard.press('Control+a');
		await composition(page, fallback, 'start');
		await expect.poll(async() => (await state(page)).composition).toBe('かな');
		expect((await state(page)).values.name).toBe('Player');
		await composition(page, fallback, 'end');
		await expect.poll(async() => (await state(page)).values.name).toBe('かな');
		await expect.poll(async() => (await state(page)).composition).toBe('');
		await composition(page, fallback, 'start', '未完');
		await expect.poll(async() => (await state(page)).composition).toBe('未完');
		await page.locator('#mute').focus();
		await expect.poll(async() => (await state(page)).composition).toBe('');
		expect((await state(page)).values.name).toBe('かな');
		await page.locator('canvas').focus();
		await page.keyboard.press('Escape');
		await expect.poll(async() => (await state(page)).editing).toBe(false);
		await page.evaluate(() => document.fullscreenElement&&document.exitFullscreen());
		await page.evaluate(() => window.stopEngine());
		await expect(page.locator('textarea[aria-label="SDL text input"]')).toHaveCount(0);
		expect(await page.locator('canvas').evaluate(c => c.editContext)).toBeNull();
		expect(errors).toEqual([]);
	}
);

test('streaming-world menu restores graphics and releases held controls before resuming', async({page}) => {
	const errors = await start(page, {scene: 'world'});
	await open(page);
	await choose(page, 'settings');
	await page.keyboard.press('Enter');
	await choose(page, 'zoom');
	await page.keyboard.press('ArrowRight');
	await expect.poll(async() => (await state(page)).values.zoom).toBe(1.25);
	const before = await state(page);
	expect(before.values.zoom).toBe(1.25);

	await page.evaluate(() => {
		menuPad('Xbox Wireless Controller');
		window.menuLoss = document.querySelector('canvas').getContext('webgl2').getExtension('WEBGL_lose_context');
		menuLoss.loseContext();
	});

	await expect(page.locator('#status')).toContainText('Graphics paused');
	const frames = Number(await page.locator('canvas').getAttribute('data-frames'));
	await page.evaluate(() => menuPad('Xbox Wireless Controller', [0,13]));
	await page.waitForTimeout(120);
	await page.evaluate(() => menuLoss.restoreContext());

	await expect.poll(async() => Number(await page.locator('canvas').getAttribute('data-frames')))
		.toBeGreaterThan(frames+2);

	await page.waitForTimeout(150);
	expect((await state(page)).selected).toBe('zoom');
	expect((await state(page)).values.zoom).toBe(1.25);
	expect((await state(page)).time).toBe(before.time);
	await page.evaluate(() => menuPad('Xbox Wireless Controller'));
	await page.waitForTimeout(60);
	await page.keyboard.press('Escape');
	await expect.poll(async() => (await state(page)).selected).toBe('settings');
	await waitForMenuFrames(page);
	await page.keyboard.press('Escape');
	await expect.poll(async() => (await state(page)).open).toBe(false);
	await expect.poll(async() => (await state(page)).time).toBeGreaterThan(before.time);
	await open(page);
	expect((await state(page)).selected).toBe('settings');
	expect(errors).toEqual([]);
});
