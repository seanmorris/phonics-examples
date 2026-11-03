import {test,expect} from '@playwright/test';

test('Doom controller bindings separate movement, turning, use, fire and weapon cycling', async({page}) => {
	const errors = [];
	page.on('pageerror', error => errors.push(error.message));
	page.on('console', message => {if(/PHP Fatal|Uncaught/.test(message.text())) errors.push(message.text());});

	await page.addInitScript(() => {
		const pad = {
			id: 'Xbox 360 Controller'
			, index: 0
			, connected: true
			, mapping: 'standard'
			, timestamp: performance.now()
			, axes: [0, 0, 0, 0]
			, buttons: Array.from({length: 17}, () => ({pressed: false, touched: false, value: 0}))
		};

		Object.defineProperty(navigator, 'getGamepads', {value: () => [pad]});

		window.doomPad = ({axes = [], buttons = []} = {}) => {
			pad.axes = [0, 0, 0, 0].map((_, index) => axes[index] ?? 0);

			pad.buttons = pad.buttons.map((_, index) => ({
				pressed: buttons.includes(index)
				, touched: buttons.includes(index)
				, value: buttons.includes(index) ? 1 : 0
			}));

			pad.timestamp = performance.now();
		};
	});

	await page.goto('/doom-phonics/');
	await page.waitForFunction(() => window.previewSession?.doomState?.menu?.screen === 'title');
	await page.locator('canvas').focus();

	const pad = state => page.evaluate(state => doomPad(state), state);

	const menu = () => page.evaluate(() => previewSession.doomState.menu);

	const frame = () => page.evaluate(() => doomInputFrames.at(-1) ?? {});

	const clear = () => page.evaluate(() => {doomInputFrames = [];});

	// A keeps its menu-confirm role, and D-pad navigation remains independent of gameplay.
	await pad({buttons: [13]});
	await expect.poll(async() => (await menu()).selected).toBe('online-play');
	await pad({});
	await page.waitForTimeout(80);

	await pad({buttons: [12]});
	await expect.poll(async() => (await menu()).selected).toBe('new-game');
	await pad({});
	await page.waitForTimeout(80);

	await pad({buttons: [0]});
	await expect.poll(async() => (await menu()).screen).toBe('skill');
	await pad({});
	await page.waitForTimeout(80);

	await pad({buttons: [0]});
	await page.waitForFunction(() => !previewSession.doomState.menu.open);
	await pad({});
	await page.waitForFunction(() => !previewSession.doomState.wipe.active && !previewSession.doomState.paused);

	expect(await page.evaluate(async() => {
		window.doomInputFrames = [];
		const module = await previewSession.php.binary;

		module.doomInputProbe = value => {
			doomInputFrames.push(JSON.parse(String(value)));

			if(doomInputFrames.length > 500) doomInputFrames.shift();
		};

		return previewSession.php.run(String.raw`<?php
			$inputPlayer = $engine->entities->query(DoomPhonics\Game\PlayerState::class)[0];
			$inputState = $engine->entities->get($inputPlayer, DoomPhonics\Game\PlayerState::class);
			$inputState->godMode = true;
			$inputState->weapons['Shotgun'] = true;
			$inputState->ammo['Shell'] = 10;
			$inputCommand = new DoomPhonics\Component\PlayerCommand;
			$inputView = new DoomPhonics\Component\ViewInput;
			$engine->systems->add('doom-input-probe', function() use ($engine, $inputPlayer, $inputState, $inputCommand, $inputView): void {
				$inputCommand->sample($engine->controls, $inputView);
				$pose = $engine->entities->get($inputPlayer, DoomPhonics\Component\Transform::class);
				(vrzno_env('doomInputProbe'))(json_encode($inputCommand->actions() + [
					'position' => [$pose->x, $pose->y, $pose->angle], 'weapon' => $inputState->weapon
					, 'viewPitch' => $engine->entities->get($inputPlayer, DoomPhonics\Component\ViewInput::class)->pitch
					, 'pendingWeapon' => $inputState->pendingWeapon, 'ammo' => $inputState->ammo['Clip']
				]));
			});
		`
		);
	})).toBe(0);

	await expect.poll(frame).toMatchObject({weapon: 'Pistol', ammo: 50});
	const beforeMouse = await frame();
	const canvas = page.locator('canvas');
	const bounds = await canvas.boundingBox();
	await page.mouse.move(bounds.x + bounds.width / 2, bounds.y + bounds.height / 2);
	await page.mouse.down();
	await expect.poll(frame).toMatchObject({fire: true});
	await page.mouse.up();
	await page.waitForFunction(() => document.pointerLockElement?.tagName === 'CANVAS');
	await page.mouse.move(bounds.x + bounds.width / 2 + 70, bounds.y + bounds.height / 2 + 45);
	await expect.poll(async() => Math.abs((await frame()).position[2] - beforeMouse.position[2])).toBeGreaterThan(.02);
	await expect.poll(async() => Math.abs((await frame()).viewPitch - beforeMouse.viewPitch)).toBeGreaterThan(.02);
	const afterMouse = await frame();
	const origin = afterMouse.position;
	const baselineAmmo = afterMouse.ammo;

	for(const [button, steps, weapon] of [
		[15, 1, 'Shotgun']
		, [14, -1, 'Pistol']
		, [12, 1, 'Shotgun']
		, [13, -1, 'Pistol']
	]) {
		await clear();
		await pad({buttons: [button]});
		await expect.poll(frame).toMatchObject({weapon, pendingWeapon: null});
		await page.waitForTimeout(200);

		expect(await frame()).toMatchObject({weapon, pendingWeapon: null, position: origin});
		expect(await frame()).toMatchObject({forward: 0, strafe: 0, turn: 0});

		const requests = await page.evaluate(
			() => doomInputFrames.filter(frame => frame.weaponSteps !== 0).map(frame => frame.weaponSteps)
		);

		expect(requests).toEqual([steps]);
		await pad({});
		await page.waitForTimeout(80);
	}

	// Physical number keys must respect edits instead of a browser weapon shortcut.
	expect(await page.evaluate(() => previewSession.php.run(String.raw`<?php
		$inputSettings->bindings->remove(DoomPhonics\Input\DoomActions::SLOT_3, 1, new Phonics\Input\Source(Phonics\Input\Source::KEY, ord('3')));
		$inputSettings->bindings->bind(DoomPhonics\Input\DoomActions::SLOT_3, 1, new Phonics\Input\Source(Phonics\Input\Source::KEY, ord('q')));
	`
	))).toBe(0);

	await clear();
	await page.keyboard.press('3');
	await page.waitForTimeout(200);
	expect(await frame()).toMatchObject({weapon: 'Pistol', pendingWeapon: null});
	expect(await page.evaluate(() => doomInputFrames.some(frame => frame.weaponSlot === 3))).toBe(false);
	await page.keyboard.press('q');
	await expect.poll(frame).toMatchObject({weapon: 'Shotgun', pendingWeapon: null});
	await page.keyboard.press('2');
	await expect.poll(frame).toMatchObject({weapon: 'Pistol', pendingWeapon: null});

	await clear();
	await pad({buttons: [0]});
	await expect.poll(() => page.evaluate(() => doomInputFrames.some(frame => frame.use))).toBe(true);
	await page.waitForTimeout(200);
	expect(await page.evaluate(() => doomInputFrames.filter(frame => frame.use).length)).toBe(1);
	expect(await frame()).toMatchObject({fire: false, ammo: baselineAmmo});
	await pad({});
	await page.waitForTimeout(80);

	await clear();
	await pad({buttons: [7]});
	await expect.poll(async() => (await frame()).ammo).toBeLessThan(baselineAmmo);
	expect(await frame()).toMatchObject({fire: true, altFire: false, use: false});
	expect(await page.evaluate(() => doomInputFrames.some(frame => frame.use))).toBe(false);
	await pad({});
	await expect.poll(async() => (await frame()).fire).toBe(false);

	await clear();
	await pad({buttons: [6]});
	await expect.poll(async() => (await frame()).altFire).toBe(true);
	expect(await frame()).toMatchObject({fire: false, use: false});
	await pad({});
	await expect.poll(async() => (await frame()).altFire).toBe(false);

	await pad({axes: [.6]});
	await expect.poll(async() => (await frame()).strafe).toBeCloseTo(.5, 3);

	await expect.poll(
		async() => Math.hypot(...(await frame()).position.slice(0, 2).map((value, index) => value - origin[index]))
	).toBeGreaterThan(1);

	expect(await frame()).toMatchObject({forward: 0, turn: 0});
	expect((await frame()).position[2]).toBe(origin[2]);
	await pad({axes: [0, -.6]});
	await expect.poll(async() => (await frame()).forward).toBeCloseTo(.5, 3);
	expect(await frame()).toMatchObject({strafe: 0, turn: 0});

	await pad({axes: [0, 0, .6]});
	await expect.poll(async() => (await frame()).turn).toBe(0);
	await expect.poll(async() => (await frame()).position[2]).toBeLessThan(origin[2]);
	expect(await frame()).toMatchObject({forward: 0, strafe: 0, weaponSlot: null, weaponSteps: 0});

	const turned = (await frame()).position[2];
	await pad({axes: [.6, 0, -.6, -.6], buttons: [15]});
	await expect.poll(async() => (await frame()).position[2]).toBeGreaterThan(turned);
	await expect.poll(async() => (await frame()).viewPitch).toBeGreaterThan(.05);
	expect((await frame()).turn).toBe(0);
	expect((await frame()).strafe).toBeCloseTo(.5, 3);
	await pad({axes: [.15, -.15, .05, .05]});
	await expect.poll(frame).toMatchObject({forward: 0, strafe: 0, turn: 0});
	await pad({});
	await page.waitForTimeout(120);
	const stopped = (await frame());
	await page.waitForTimeout(200);
	expect((await frame()).position[2]).toBe(stopped.position[2]);
	expect((await frame()).viewPitch).toBe(stopped.viewPitch);

	await clear();
	await page.keyboard.down('e');
	await expect.poll(() => page.evaluate(() => doomInputFrames.some(frame => frame.use))).toBe(true);
	await page.keyboard.up('e');
	await page.keyboard.down('Space');
	await expect.poll(async() => (await frame()).fire).toBe(true);
	await page.keyboard.up('Space');

	await pad({buttons: [9]});
	await expect.poll(menu).toMatchObject({screen: 'pause', open: true});
	await pad({});
	await page.waitForTimeout(80);
	await pad({buttons: [0]});
	await expect.poll(async() => (await menu()).open).toBe(false);
	await pad({});
	expect(errors).toEqual([]);
});

test('Doom binding capture, conflicts and saved alternatives use physical input', async({page}) => {
	const errors = [];
	page.on('pageerror', error => errors.push(error.message));
	page.on('console', message => {if(/PHP Fatal|Uncaught/.test(message.text())) errors.push(message.text());});

	await page.addInitScript(() => {
		const pad = {
			id: 'Xbox 360 Controller'
			, index: 0
			, connected: true
			, mapping: 'standard'
			, timestamp: performance.now()
			, axes: [0, 0, 0, 0]
			, buttons: Array.from({length: 17}, () => ({pressed: false, touched: false, value: 0}))
		};

		Object.defineProperty(navigator, 'getGamepads', {value: () => [pad]});
	});

	await page.goto('/doom-phonics/');
	await page.waitForFunction(() => window.previewSession?.doomState?.menu?.screen === 'title');
	await page.locator('canvas').focus();

	const menu = () => page.evaluate(() => previewSession.doomState.menu);

	const key = async code => {await page.keyboard.press(code); await page.waitForTimeout(120);};

	const select = async id => {
		for(let n = 0; n < 35; n++)
		{
			if((await menu()).selected === id) return n;

			await key('ArrowDown');
		}

		throw new Error(`Could not select ${id}: ${JSON.stringify(await menu())}`);
	};

	const enter = async id => {await select(id); await key('Enter');};

	const clickTitleRow = async index => {
		const box = await page.locator('canvas').boundingBox();

		if(!box) throw new Error('Game canvas has no clickable bounds');

		await page.mouse.click(box.x + box.width * .5, box.y + box.height * (80 + index * 20) / 200);
	};

	const saved = () => page.evaluate(() => JSON.parse(localStorage.getItem('doom-phonics.controls.v1')));
	const readThisIndex = await select('read-this');

	await clickTitleRow(readThisIndex);
	await expect.poll(menu).toMatchObject({screen: 'read-this', selected: 'rebind', orientation: 'horizontal'});

	await key('ArrowRight');
	await expect.poll(menu).toMatchObject({screen: 'read-this', selected: '@back'});
	await key('ArrowDown');
	expect(await menu()).toMatchObject({screen: 'read-this', selected: '@back'});

	await key('ArrowLeft');
	await expect.poll(menu).toMatchObject({screen: 'read-this', selected: 'rebind'});
	await key('Enter');
	await expect.poll(menu).toMatchObject({screen: 'controls'});

	await key('Escape');
	await expect.poll(menu).toMatchObject({screen: 'read-this'});
	await key('Escape');
	await expect.poll(menu).toMatchObject({screen: 'title', selected: 'read-this'});

	await page.keyboard.down('Enter');
	await expect.poll(menu).toMatchObject({screen: 'read-this', selected: 'rebind'});
	await page.waitForTimeout(750);
	expect(await menu()).toMatchObject({screen: 'read-this', selected: 'rebind'});
	await page.keyboard.up('Enter');
	await key('Escape');
	await expect.poll(menu).toMatchObject({screen: 'title', selected: 'read-this'});

	await enter('settings');
	await expect.poll(menu).toMatchObject({screen: 'settings'});
	await enter('sound');
	await expect.poll(menu).toMatchObject({screen: 'sound'});
	await select('effects-volume');
	await key('ArrowLeft');
	await expect.poll(async() => (await menu()).options.effectsVolume).toBe(.9);
	await select('music-volume');
	await key('ArrowLeft');
	await expect.poll(async() => (await menu()).options.musicVolume).toBe(.9);
	await enter('mute-all');
	await expect.poll(async() => (await menu()).options.muted).toBe(true);

	expect(await page.evaluate(() => JSON.parse(localStorage.getItem('doom-phonics.options.v1')))).toMatchObject({
		version: 1
		, muted: true
		, effectsVolume: .9
		, musicVolume: .9
	});

	await key('Escape');
	await enter('video');
	await expect.poll(menu).toMatchObject({screen: 'video'});
	await select('render-resolution');
	await key('ArrowRight');
	await expect.poll(menu).toMatchObject({renderResolution: 'classic'});
	await select('pitch-mode');
	await key('ArrowRight');
	await expect.poll(menu).toMatchObject({pitchMode: 'classic'});
	await select('reticle');
	await key('Enter');
	await expect.poll(menu).toMatchObject({reticle: false});
	await select('blood-splatter');
	await key('ArrowRight');
	await expect.poll(menu).toMatchObject({bloodSplatter: 1.25});

	expect(await page.evaluate(() => JSON.parse(localStorage.getItem('doom-phonics.options.v1')))).toMatchObject({
		reticle: false
		, bloodSplatter: 1.25
	});

	await key('Escape');
	await enter('input');
	await expect.poll(async() => (await menu()).controlsRows).toHaveProperty('keyboard');
	await enter('controller');
	await expect.poll(menu).toMatchObject({inputTheme: 'keyboard', controllerTheme: 'xbox'});

	await expect.poll(async() => (await menu()).controlsRows).toMatchObject({
		forward: {value: '🡩'}
		, fire: {value: '❼'}
		, 'alt-fire': {value: '❻'}
		, use: {value: '⓿'}
		, 'previous-weapon': {value: '←  ↓'}
	});

	await key('Escape');
	await expect.poll(async() => (await menu()).controlsRows).toHaveProperty('keyboard');
	await enter('keyboard');
	await expect.poll(async() => (await menu()).controlsRows).toHaveProperty('fire');
	await enter('fire');
	await select('add-binding');
	await page.keyboard.down('Enter');
	await expect.poll(async() => (await menu()).bindingCapture).toBe(true);
	await expect.poll(async() => (await menu()).controlsRows.capture.label).toBe('Release controls first');
	await page.keyboard.up('Enter');
	await expect.poll(async() => (await menu()).controlsRows.capture.label).toBe('Press a key or move the mouse');
	await key('w');
	await expect.poll(async() => (await menu()).controlsRows).toHaveProperty('replace');
	await enter('share');
	await expect.poll(async() => (await menu()).controlsRows).toHaveProperty('remove-key:119:1');
	let data = await saved();
	expect(data.version).toBe(2);
	expect(data.bindings[0x10004][1].keyboard).toContainEqual({kind: 'key', code: 119, sign: 1});
	expect(data.bindings[0x10000][1].keyboard).toContainEqual({kind: 'key', code: 119, sign: 1});

	await enter('add-binding');
	await expect.poll(async() => (await menu()).controlsRows.capture.label).toBe('Press a key or move the mouse');
	await key('Escape');
	await expect.poll(async() => (await menu()).bindingCapture).toBe(false);
	await expect.poll(async() => (await menu()).controlsRows).toHaveProperty('add-binding');
	expect(await saved()).toEqual(data);

	await enter('remove-key:32:1');
	await expect.poll(async() => (await menu()).controlsRows['remove-key:32:1']).toBeUndefined();
	await key('Escape');
	await expect.poll(async() => (await menu()).controlsRows?.fire?.value ?? '').toContain('W');
	await page.reload();
	await page.waitForFunction(() => window.previewSession?.doomState?.menu?.screen === 'title');
	await page.locator('canvas').focus();
	await enter('settings');
	await enter('input');
	await enter('keyboard');
	await expect.poll(async() => (await menu()).controlsRows.fire.value).toContain('W');
	expect((await menu()).controlsRows.fire.value).not.toContain('Space');
	await enter('use');
	await enter('add-binding');
	await expect.poll(async() => (await menu()).controlsRows.capture.label).toBe('Press a key or move the mouse');
	await key('w');
	await expect.poll(async() => (await menu()).controlsRows).toHaveProperty('replace');
	await enter('replace');
	data = await saved();
	expect(data.bindings[0x10005][1].keyboard).toContainEqual({kind: 'key', code: 119, sign: 1});
	expect(data.bindings[0x10004][1].keyboard).not.toContainEqual({kind: 'key', code: 119, sign: 1});
	expect(data.bindings[0x10000][1].keyboard).not.toContainEqual({kind: 'key', code: 119, sign: 1});
	expect(data.bindings[0x10000][1].controller).toEqual([{kind: 'pad-axis', code: 1, sign: -1}]);
	await key('Escape');
	await key('End');
	await key('ArrowUp');
	await expect.poll(async() => (await menu()).selected).toBe('defaults');
	await key('Enter');
	await expect.poll(async() => (await menu()).controlsRows.fire.value).toContain('Space');
	expect((await menu()).controlsRows.forward.value).toContain('W');
	expect(errors).toEqual([]);
});
