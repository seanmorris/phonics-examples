import {test,expect} from '@playwright/test';

async function boot(page)
{
	await page.goto('/sane-phonics/');
	await page.waitForFunction(() => typeof window.bootEngine === 'function');
	await page.evaluate(version => window.bootEngine({run: false,version}), process.env.ENGINE_PHP_VERSION ?? '8.4');
}

async function mountTitleMusic(page)
{
	return page.evaluate(async() => {
		const response = await fetch('/engine/packages/doom-phonics/DOOM1_OGG.wad');

		if(!response.ok) throw Error(`Music WAD request failed with ${response.status}`);

		const wad = new Uint8Array(await response.arrayBuffer());
		const view = new DataView(wad.buffer, wad.byteOffset, wad.byteLength);
		const count = view.getUint32(4, true);
		const directory = view.getUint32(8, true);
		const decoder = new TextDecoder;
		let title;

		for(let index = 0; index < count; index++)
		{
			const entry = directory + index * 16;
			const name = decoder.decode(wad.subarray(entry + 8, entry + 16)).replaceAll('\0', '');

			if(name !== 'D_INTRO') continue;

			const offset = view.getUint32(entry, true);
			const size = view.getUint32(entry + 4, true);
			title = wad.slice(offset, offset + size);

			break;
		}

		if(!title || decoder.decode(title.subarray(0, 4)) !== 'OggS')
		throw Error('D_INTRO is missing or is not Ogg data');

		const pwad = new Uint8Array(12 + title.length + 16);
		const output = new DataView(pwad.buffer);
		pwad.set(new TextEncoder().encode('PWAD'), 0);
		output.setUint32(4, 1, true);
		output.setUint32(8, 12 + title.length, true);
		pwad.set(title, 12);
		output.setUint32(12 + title.length, 12, true);
		output.setUint32(16 + title.length, title.length, true);
		pwad.set(new TextEncoder().encode('D_INTRO'), 20 + title.length);

		const module = await enginePhp.binary;
		const phpFiles = [
			'autoload.php'
			, 'src/Archive/Archive.php'
			, 'src/Archive/Binary.php'
			, 'src/Archive/Lump.php'
			, 'src/Archive/Stack.php'
			, 'src/Audio/MusicCue.php'
			, 'src/Audio/SdlSoundEmitter.php'
			, 'src/Audio/SoundEmitter.php'
		];

		await Promise.all(phpFiles.map(async file => {
			const source = await fetch('/engine/packages/doom-phonics/' + file);

			if(!source.ok) throw Error(`${file} request failed with ${source.status}`);

			const destination = '/engine/packages/doom-phonics/' + file;
			module.FS.mkdirTree(destination.slice(0, destination.lastIndexOf('/')));
			module.FS.writeFile(destination, new Uint8Array(await source.arrayBuffer()));
		}));

		module.FS.writeFile('/doom-title.wad', pwad);

		return title.length;
	});
}

async function run(page, source)
{
	const result = await page.evaluate(async source => {
		let output = '',errors = '';

		const out = event => output += event.detail.join('');

		const error = event => errors += event.detail.join('');
		enginePhp.addEventListener('output', out);
		enginePhp.addEventListener('error', error);

		try
		{
			const code = await enginePhp.run('<?php ' + source);

			return {output,errors,code};
		}
		finally
		{
			enginePhp.removeEventListener('output', out);
			enginePhp.removeEventListener('error', error);
		}
	}, source
	);

	expect(result.errors).toBe('');
	expect(result.code).toBe(0);

	return result.output;
}

test.afterEach(async({page}) => {
	await page.evaluate(() => window.enginePhp?.refresh()).catch(() => {});
});

test('the owned title cue streams from the Ogg WAD and stops with its scope', async({page}) => {
	await boot(page);
	expect(await mountTitleMusic(page)).toBe(117362);

	const result = JSON.parse(await run(page, String.raw`
		require '/engine/packages/doom-phonics/autoload.php';

		$base = new DoomPhonics\Archive\Archive("IWAD\0\0\0\0\x0c\0\0\0", 'empty.wad');
		$music = new DoomPhonics\Archive\Archive(file_get_contents('/doom-title.wad'), 'doom-title.wad');
		$stack = new DoomPhonics\Archive\Stack($base, $music);
		$emitter = new DoomPhonics\Audio\SdlSoundEmitter;
		$emitter->beginScope($stack);
		$emitter->playMusic(DoomPhonics\Audio\MusicCue::title());
		$started = Mix_PlayingMusic();
		$emitter->playMusic(DoomPhonics\Audio\MusicCue::title());
		$continued = Mix_PlayingMusic();
		$error = $started ? '' : Mix_GetError();
		$emitter->stopScope();
		$stopped = Mix_PlayingMusic();
		$emitter->close();
		echo json_encode(compact('started', 'continued', 'stopped', 'error'));
	`
	));

	expect(result).toEqual({started: 1,continued: 1,stopped: 0,error: ''});
});

test('title music stays audible while connection work stalls the main thread', async({page}) => {
	await boot(page);
	await mountTitleMusic(page);
	await page.mouse.click(20, 20);

	await page.evaluate(async() => {
		const {WadMusic} = await import('/engine/packages/doom-phonics/browser/game.mjs');
		const module = await enginePhp.binary;
		window.music = new WadMusic;
		window.musicErrors = [];

		module.doomMusic = value => { music.play(String(value)).catch(error => musicErrors.push(error.message)); };
	});

	await run(page, String.raw`
		require '/engine/packages/doom-phonics/autoload.php';
		$base = new DoomPhonics\Archive\Archive("IWAD\0\0\0\0\x0c\0\0\0", 'empty.wad');
		$stack = new DoomPhonics\Archive\Stack($base, new DoomPhonics\Archive\Archive(file_get_contents('/doom-title.wad')));
		$emitter = new DoomPhonics\Audio\SdlSoundEmitter;
		$emitter->beginScope($stack);
		$emitter->playMusic(DoomPhonics\Audio\MusicCue::title());
	`
	);

	expect(await page.evaluate(() => music.url)).toMatch(/^blob:/);

	const result = await page.evaluate(async() => {
		const context = new AudioContext;
		await context.resume();
		const processor = `class Meter extends AudioWorkletProcessor {
			process(inputs) {
				const samples = inputs[0]?.[0] ?? [];
				let power = 0;
				for(const sample of samples) power += sample * sample;
				this.port.postMessage({time: currentTime, power: power / (samples.length || 1)});
				return true;
			}
		}
		registerProcessor('meter', Meter);`;

		const url = URL.createObjectURL(new Blob([processor], {type: 'text/javascript'}));
		await context.audioWorklet.addModule(url);
		URL.revokeObjectURL(url);
		const meter = new AudioWorkletNode(context, 'meter');
		const samples = [];

		meter.port.onmessage = ({data}) => samples.push(data);
		const source = context.createMediaElementSource(music.element);

		source.connect(meter).connect(context.destination);
		source.connect(context.destination);

		await new Promise(resolve => setTimeout(resolve, 2000));
		const start = context.currentTime;
		const until = performance.now() + 750;

		// Resource mounting/map activation also blocks PHP and JavaScript on this thread.
		while(performance.now() < until)
		{
			/* Deliberately prevent main-thread audio callbacks. */
		}

		const end = context.currentTime;
		await new Promise(resolve => setTimeout(resolve, 200));
		const during = samples.filter(sample => sample.time >= start + .15 && sample.time <= end - .05);
		meter.disconnect();

		await context.close();

		return {
			blocks: during.length
			, audible: during.filter(sample => sample.power > 1e-8).length / during.length
			, unique: new Set(during.map(sample => sample.power)).size
			, errors: musicErrors
		};
	});

	expect(result.errors).toEqual([]);
	expect(result.blocks).toBeGreaterThan(100);
	expect(result.audible).toBeGreaterThan(.95);

	expect(
		result.unique / result.blocks
		, 'music must advance instead of repeating the last SDL buffer'
	).toBeGreaterThan(.95);

	const playing = await page.evaluate(() => ({url: music.url, time: music.element.currentTime}));
	await run(page, '$emitter->playMusic(DoomPhonics\\Audio\\MusicCue::title());');
	expect(await page.evaluate(() => music.url)).toBe(playing.url);
	expect(await page.evaluate(() => music.element.currentTime)).toBeGreaterThanOrEqual(playing.time);
	await page.evaluate(() => music.setMuted(true));
	expect(await page.evaluate(() => music.element.muted)).toBe(true);

	await run(page, '$emitter->stopScope();');

	expect(await page.evaluate(() => ({url: music.url, paused: music.element.paused})))
		.toEqual({url: null, paused: true});

	await run(page, String.raw`
		$emitter->beginScope($stack);
		$emitter->playMusic(DoomPhonics\Audio\MusicCue::title());
	`
	);

	expect(await page.evaluate(() => music.url)).toMatch(/^blob:/);
	expect(await page.evaluate(() => music.url)).not.toBe(playing.url);
	await run(page, '$emitter->close();');
	expect(await page.evaluate(() => music.url)).toBeNull();
	await page.evaluate(() => music.close());
});
