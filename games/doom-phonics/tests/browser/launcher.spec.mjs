import {readFile} from 'node:fs/promises';
import {test, expect} from '@playwright/test';

// Reuse shareware map geometry under commercial/custom names; no owned IWAD is needed in CI.

const mapArchive = async(type, maps) => {
	const source = await readFile(new URL('../../demo/DOOM1.WAD', import.meta.url));
	const count = source.readUInt32LE(4), directory = source.readUInt32LE(8);
	const lumps = [];

	for(let index = 0; index < count; index++)
	{
		const entry = directory + index * 16;
		const offset = source.readUInt32LE(entry), length = source.readUInt32LE(entry + 4);
		const name = source.toString('ascii', entry + 8, entry + 16).replace(/\0.*$/, '');
		lumps.push({name, data: source.subarray(offset, offset + length)});
	}

	const marker = lumps.findIndex(lump => lump.name === 'E1M1');

	const entries = maps.flatMap(({name, broken = false, noStart = false, startType = null}) => [
		{name, data: Buffer.alloc(0)}
		, ...lumps.slice(marker + 1, marker + 11).map(lump => {
			let data = Buffer.from(lump.data);

			if(lump.name === 'THINGS')
			for(let offset = 0; offset < data.length; offset += 10)
			{
				if(data.readUInt16LE(offset + 6) !== 1) continue;

				if(broken) data.writeUInt16LE(32767, offset + 6);

				if(noStart) data.writeUInt16LE(2, offset + 6);
			}

			if(lump.name === 'THINGS' && startType !== null)
			{
				const records = [];

				for(let offset = 0; offset < data.length; offset += 10)
				{
					const type = data.readUInt16LE(offset + 6);

					if(type === 11 || (type >= 2 && type <= 4)) continue;

					if(type === 1) data.writeUInt16LE(startType, offset + 6);

					records.push(data.subarray(offset, offset + 10));
				}

				data = Buffer.concat(records);
			}

			return {name: lump.name, data};
		})
	]);

	const header = Buffer.alloc(12), table = Buffer.alloc(entries.length * 16);
	let offset = 12;

	for(const [index, entry] of entries.entries())
	{
		table.writeUInt32LE(offset, index * 16);
		table.writeUInt32LE(entry.data.length, index * 16 + 4);
		table.write(entry.name, index * 16 + 8, 8, 'ascii');
		offset += entry.data.length;
	}

	header.write(type);
	header.writeUInt32LE(entries.length, 4);
	header.writeUInt32LE(offset, 8);

	return Buffer.concat([header, ...entries.map(entry => entry.data), table]);
};

test('launcher starts local game modes, recovers from invalid starts, and opens online play', async({page}) => {
	test.setTimeout(180000);
	const errors = [];
	page.on('pageerror', error => errors.push(error.message));

	page.on('console', message => {
		if(/PHP Fatal error|Uncaught/.test(message.text())) errors.push(message.text());
	});

	await page.goto('/doom-phonics/');

	const state = () => page.evaluate(() => window.previewSession?.doomState);
	await expect.poll(async() => (await state())?.menu?.screen, {timeout: 60000}).toBe('title');

	const press = async key => {
		await page.keyboard.press(key, {delay: 80});
		// Let SDL sample the released button before sending another press of the same key.
		await page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));
	};

	// Open through the actual title-menu action.
	await page.locator('canvas').focus();
	await press('ArrowDown');
	await expect.poll(async() => (await state()).menu.selected).toBe('online-play');
	await press('ArrowDown');
	await expect.poll(async() => (await state()).menu.selected).toBe('wad-stack');
	await press('Enter');
	const dialog = page.locator('.phonics-launcher');
	await expect(dialog).toBeVisible();
	await expect(dialog.getByRole('heading', {name: 'Game launcher', exact: true})).toBeVisible();
	const mode = dialog.getByLabel(/^Mode/);
	const skill = dialog.getByLabel(/^Skill/);
	await expect(mode).toHaveValue('singleplayer');
	await expect(mode.locator('option')).toHaveText(['Single Player', 'Co-op', 'Deathmatch']);
	await expect(dialog.getByLabel('Frag limit')).toBeHidden();
	await skill.selectOption('1');

	// Local and online settings retain independent values, and online offers keep their modes.
	await page.evaluate(() => previewSession.launcher.openSettings());
	await expect(mode).toHaveValue('deathmatch');
	await expect(mode.locator('option')).toHaveText(['Deathmatch', 'Co-op']);
	await expect(dialog.getByLabel('Frag limit')).toBeVisible();
	await mode.selectOption('cooperative');
	await skill.selectOption('4');
	await page.evaluate(() => previewSession.launcher.openLocal());
	await expect(mode).toHaveValue('singleplayer');
	await expect(skill).toHaveValue('1');

	await dialog.locator('input[name="resource:wad-stack"]').setInputFiles([
		{
			name: 'second-iwad.wad'
			, mimeType: 'application/octet-stream'
			, buffer: await mapArchive('IWAD', [{name: 'MAP01'}])
		}
		, {
			name: 'custom-maps.wad'
			, mimeType: 'application/octet-stream'
			, buffer: await mapArchive('PWAD', [
				{name: 'CUSTOM'}
				, {name: 'D2ST9', startType: 5080}
				, {name: 'ARENA', startType: 11}
				, {name: 'BADMAP', broken: true}
				, {name: 'NOSTART', noStart: true}
			])
		}
	]);

	const map = dialog.getByLabel('Starting map');
	await expect(map.locator('option[value="D2ST9"]')).toHaveCount(1);
	const start = dialog.getByRole('button', {name: 'Start locally', exact: true});
	await map.selectOption('MAP01');

	expect(await page.evaluate(async() => {
		window.launcherGameplayFire = 0;
		const module = await previewSession.php.binary;

		module.launcherGameplayFire = () => {window.launcherGameplayFire++;};

		return previewSession.php.run(String.raw`<?php
			$engine->systems->add('launcher-gameplay-fire-probe', function() use ($engine): void {
				if($engine->controls->down(DoomPhonics\Input\DoomActions::FIRE))
				(vrzno_env('launcherGameplayFire'))();
			});
		`
		);
	})).toBe(0);

	await start.click();
	await expect(dialog).toBeHidden({timeout: 45000});
	await expect.poll(async() => (await state())?.map).toBe('MAP01');
	await expect.poll(async() => (await state())?.wipe.active, {timeout: 10000}).toBe(false);
	expect(await page.evaluate(() => window.launcherGameplayFire)).toBe(0);

	// The first canvas press must fire immediately; pointer-lock acquisition is not
	// a reason to throw it away. Later locked presses must remain equally reliable.
	const canvas = page.locator('canvas');
	const box = await canvas.boundingBox();
	await page.mouse.move(box.x + box.width / 2, box.y + box.height / 2);
	await page.mouse.down();
	await expect.poll(async() => page.evaluate(() => window.launcherGameplayFire)).toBeGreaterThan(0);
	await expect.poll(async() => (await state()).ammo.Clip).toBeLessThan(50);
	await page.mouse.up();
	await page.waitForFunction(() => document.pointerLockElement?.tagName === 'CANVAS');

	for(let click = 0; click < 3; click++)
	{
		const before = await page.evaluate(() => window.launcherGameplayFire);
		await page.mouse.down();
		await expect.poll(async() => page.evaluate(() => window.launcherGameplayFire)).toBeGreaterThan(before);
		await page.mouse.up();
	}

	await page.evaluate(() => document.exitPointerLock());
	await page.waitForFunction(() => document.pointerLockElement === null);

	expect(await page.evaluate(() => previewSession.php.run(String.raw`<?php
		$engine->systems->remove('launcher-gameplay-fire-probe');
	`
	))).toBe(0);

	expect((await state()).mode).toBe('singleplayer');
	expect(await state()).toMatchObject({skill: 'Hey, not too rough', health: 100, paused: false});

	expect((await state()).render).toBeTruthy();
	const spawn = (await state()).position;
	await canvas.focus();
	await page.keyboard.down('w');
	await expect.poll(async() => (await state()).position.slice(0, 2)).not.toEqual(spawn.slice(0, 2));
	await page.keyboard.up('w');

	// The fixed update consumes an exit special into a paused, renderable tally before changing maps.
	expect(await page.evaluate(() => previewSession.php.run(String.raw`<?php
		$players = iterator_to_array($engine->entities->query(DoomPhonics\Game\PlayerState::class));
		$engine->entities->get($players[0], DoomPhonics\Game\PlayerState::class)->exited = true;
	`
	))).toBe(0);

	await expect.poll(async() => (await state()).intermission?.map).toBe('MAP01');
	await expect.poll(async() => (await state()).wipe.active).toBe(true);
	await expect.poll(async() => (await state()).wipe.active, {timeout: 10000}).toBe(false);
	expect(await state()).toMatchObject({map: 'MAP01', paused: true, intermission: {phase: 'kills'}});
	expect(await page.evaluate(() => previewSession.php.run(String.raw`<?php $game->restart();`))).toBe(0);
	await expect.poll(async() => (await state()).intermission).toBe(null);
	await expect.poll(async() => (await state()).wipe.active, {timeout: 10000}).toBe(false);
	await expect.poll(async() => (await state()).paused).toBe(false);

	for(const [name, message] of [
		['BADMAP', 'Unresolved WAD thing types: 32767']
		, ['NOSTART', 'no playable player start']
		, ['D2ST9', 'choose Deathmatch or Co-op']
	]){
		await page.evaluate(() => previewSession.launcher.openLocal());
		await skill.selectOption('3');
		await map.selectOption(name);
		await start.click();
		await expect(dialog.locator('output')).toContainText(message, {timeout: 45000});
		await expect(start).toBeEnabled();
		await expect(dialog).toBeVisible();
		await expect.poll(async() => (await state())?.wipe.active, {timeout: 10000}).toBe(false);
		expect(await state()).toMatchObject({map: 'MAP01', skill: 'Hey, not too rough'});
		expect((await state()).render).toBeTruthy();
	}

	// Retry in the same launcher after failures; custom map names also receive gameplay bindings.
	await map.selectOption('CUSTOM');
	await start.click();
	await expect(dialog).toBeHidden({timeout: 45000});
	await expect.poll(async() => (await state())?.map).toBe('CUSTOM');
	await expect.poll(async() => (await state())?.wipe.active, {timeout: 10000}).toBe(false);
	expect(await state()).toMatchObject({skill: 'Ultra-violence', paused: false});
	expect((await state()).render).toBeTruthy();

	// New Game must keep the selected campaign start instead of jumping back to E1M1.
	await page.locator('canvas').focus();
	await press('Escape');
	await expect.poll(async() => (await state()).menu.screen).toBe('pause');
	await press('ArrowDown');
	await expect.poll(async() => (await state()).menu.selected).toBe('new-game');
	await press('Enter');
	await expect.poll(async() => (await state()).menu.screen).toBe('skill');
	await press('Enter');
	await expect.poll(async() => (await state()).menu.open, {timeout: 10000}).toBe(false);
	await expect.poll(async() => (await state()).wipe.active, {timeout: 10000}).toBe(false);
	expect((await state()).map).toBe('CUSTOM');

	// Actual multiplayer-only spawn layouts work locally without a fabricated remote player.
	for(const [selectedMap, selectedMode, marker] of [['ARENA', 'deathmatch', 11]
		, ['D2ST9', 'deathmatch', 5080]
		, [
			'D2ST9', 'cooperative', 5080
		]
	]){
		await page.evaluate(() => previewSession.launcher.openLocal());
		await map.selectOption(selectedMap);
		await mode.selectOption(selectedMode);
		await start.click();
		await expect(dialog).toBeHidden({timeout: 45000});
		await expect.poll(async() => (await state()).wipe.active).toBe(false);
		expect(await state()).toMatchObject({map: selectedMap, mode: selectedMode, paused: false});

		const players = await page.evaluate(async() => {
			const module = await previewSession.php.binary;
			let result;

			module.capturePlayers = value => { result = JSON.parse(String(value)); };

			await previewSession.php.run(String.raw`<?php
				$players = iterator_to_array($engine->entities->query(DoomPhonics\Game\PlayerState::class));
				(vrzno_env('capturePlayers'))(json_encode([
					'count' => count($players),
					'network' => count($engine->entities->query(DoomPhonics\Component\NetworkPlayer::class)),
					'marker' => $engine->entities->get($players[0], DoomPhonics\Component\MapThing::class)->type,
				]));
			`
			);

			delete module.capturePlayers;

			return result;
		});

		expect(players).toEqual({count: 1, network: 0, marker});
	}

	// Failed single-player selection restores the previous multiplayer rules and team spawn.
	await page.evaluate(() => previewSession.launcher.openLocal());
	await mode.selectOption('singleplayer');
	await start.click();
	await expect(dialog.locator('output')).toContainText('choose Deathmatch or Co-op', {timeout: 45000});
	await expect(start).toBeEnabled();
	expect(await state()).toMatchObject({map: 'D2ST9', mode: 'cooperative'});

	// Enter the real lobby flow with a local provider stub; no Matrix account is needed in CI.
	await mode.selectOption('deathmatch');
	await skill.selectOption('4');

	await page.evaluate(() => {

		previewSession.lobby.controller.provider.connect = async() => ({id: 'launcher-test'});
	});

	await dialog.getByRole('button', {name: 'Play online', exact: true}).click();
	await expect(dialog).toBeHidden();
	const lobby = page.locator('.phonics-lobby');
	await expect(lobby).toBeVisible();
	await expect(lobby.locator('.phonics-lobby-status')).toHaveText('available');
	await lobby.getByRole('button', {name: 'Game settings', exact: true}).click();
	await expect(mode).toHaveValue('deathmatch');
	await expect(skill).toHaveValue('4');
	await expect(map).toHaveValue('D2ST9');
	await expect(mode.locator('option')).toHaveText(['Deathmatch', 'Co-op']);
	await expect(dialog.getByLabel('Frag limit')).toBeVisible();
	await dialog.getByRole('button', {name: 'Done', exact: true}).click();
	await lobby.getByRole('button', {name: 'Close lobby'}).click();

	expect(errors).toEqual([]);
	await page.click('#stop');
	await expect(page.locator('#status')).toHaveText('Stopped');
});
