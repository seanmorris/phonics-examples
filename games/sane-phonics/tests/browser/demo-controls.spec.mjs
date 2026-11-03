import {test, expect} from '@playwright/test';
import {externalAssets} from './external-assets.mjs';

const menu = page => page.locator('canvas').evaluate(canvas => JSON.parse(canvas.dataset.menu || 'null'));

async function open(page)
{
	await page.locator('canvas').focus();
	await page.keyboard.press('p');
	await expect.poll(async () => (await menu(page))?.open).toBe(true);
}

async function activate(page, id)
{
	await page.evaluate(async id => {
		await enginePhp.run('<?php $pauseMenu->menu->select(' + JSON.stringify(id) + ');');
	}, id
	);

	await page.keyboard.press('Enter');
}

async function start(page)
{
	await expect(page.locator('#start')).toBeEnabled();
	await page.evaluate(() => window.bootEngine({intro: false, scene: 'platformer'}));
	await expect.poll(async () => (await menu(page))?.open, {timeout: 60000}).toBe(false);
	await expect(page.locator('#start')).toBeHidden();
	await expect(page.locator('canvas')).toHaveAttribute('data-scene', /.+/);
}

test.afterEach(async ({page}) => {
	await page.evaluate(() => window.enginePhp?.refresh()).catch(() => {});
});

test(
	'two corner shortcuts sit at the lower right and mute survives reload without loading PHP'
	, async ({page}) => {
		const binaries = [];

		page.on('request', request => {
			if(request.url().includes('.wasm')) binaries.push(request.url());
		});

		await page.goto('/sane-phonics/');
		await expect(page.locator('.quick-controls button')).toHaveCount(2);
		await expect(page.locator('#scene, #profile, #stop, .toolbar')).toHaveCount(0);
		const viewport = await page.locator('.viewport').boundingBox();
		const controls = await page.locator('.quick-controls').boundingBox();
		const run = await page.locator('#start').boundingBox();
		expect(run.x + run.width / 2).toBeCloseTo(viewport.x + viewport.width / 2, 0);
		expect(run.y + run.height / 2).toBeCloseTo(viewport.y + viewport.height / 2, 0);
		expect(viewport.x + viewport.width - controls.x - controls.width).toBeGreaterThanOrEqual(0);
		expect(viewport.x + viewport.width - controls.x - controls.width).toBeLessThan(20);
		expect(viewport.y + viewport.height - controls.y - controls.height).toBeLessThan(20);
		await page.click('#fullscreen');

		await expect.poll(() => page.evaluate(() => document.fullscreenElement?.id))
			.toBe('game-frame');

		const fullFrame = await page.locator('#game-frame').boundingBox();
		const fullRun = await page.locator('#start').boundingBox();
		expect(fullRun.x + fullRun.width / 2).toBeCloseTo(fullFrame.width / 2, 0);
		expect(fullRun.y + fullRun.height / 2).toBeCloseTo(fullFrame.height / 2, 0);
		await page.click('#fullscreen');

		await expect(page.getByRole('button', {name: 'Mute audio', exact: true}))
			.toHaveAttribute('aria-pressed', 'false');

		await page.click('#mute');

		await expect(page.getByRole('button', {name: 'Unmute audio', exact: true}))
			.toHaveAttribute('aria-pressed', 'true');

		await page.reload();
		await expect(page.locator('#mute')).toHaveAttribute('aria-pressed', 'true');
		expect(binaries).toEqual([]);
	}
);

test(
	'pause menu changes levels and movement without restarting PHP or the engine'
	, async ({page}) => {
		const errors = [], binaries = [];
		page.on('pageerror', error => errors.push(error.message));

		page.on('request', request => {
			if(request.url().endsWith('.wasm')) binaries.push(request.url());
		});

		await page.goto('/sane-phonics/');
		await start(page);

		await page.evaluate(async () => {
			window.firstRuntime = enginePhp;
			window.refreshes = 0;
			const refresh = enginePhp.refresh.bind(enginePhp);

			enginePhp.refresh = (...args) => { refreshes++; return refresh(...args); };

			await enginePhp.run(`<?php
			$sessionMarker = [$engine, $engine->clock, $engine->entities, $body, $pauseMenu];
			$sessionTime = $engine->time;
			$camera->zoom = 1.25;
			$pauseMenu->theme = 'playstation';
			$extraEntity = $engine->entities->create(new stdClass);
		`
			);
		});

		await open(page);
		await activate(page, 'movement');
		await expect.poll(async () => (await menu(page)).title).toBe('Movement');
		await activate(page, 'glider');
		await expect(page.locator('canvas')).toHaveAttribute('data-profile', 'glider');
		await expect.poll(async () => (await menu(page))?.open).toBe(false);
		expect(await page.evaluate(() => enginePhp === firstRuntime)).toBe(true);
		expect(await page.evaluate(() => refreshes)).toBe(0);
		await open(page);
		await activate(page, 'level');
		await activate(page, 'world');
		await expect(page.locator('canvas')).toHaveAttribute('data-scene', 'world');

		await expect.poll(
			() => page.locator('canvas').evaluate(canvas => JSON.parse(canvas.dataset.world || '{}').draws || 0)
		).toBeGreaterThan(0);

		await open(page);
		await activate(page, 'level');
		await activate(page, 'breakables');
		await expect(page.locator('canvas')).toHaveAttribute('data-scene', 'breakables');
		await expect(page.locator('canvas')).toHaveAttribute('data-profile', 'glider');

		const body = await page.evaluate(async () => {
			const module = await enginePhp.binary;

			await enginePhp.run(`<?php
			vrzno_env('canvas')->dataset->profileProbe = json_encode(
				[$body->profile, $body->jumpSpeed, $body->ability]
			);
		`
			);

			return JSON.parse(module.canvas.dataset.profileProbe);
		});

		expect(body).toEqual(['glider', 570, '']);

		await page.evaluate(() => enginePhp.run(`<?php
		vrzno_env('canvas')->dataset->sessionProbe = json_encode([
			$sessionMarker === [$engine, $engine->clock, $engine->entities, $body, $pauseMenu],
			$engine->time >= $sessionTime, $camera->zoom, $pauseMenu->theme,
			$engine->entities->alive($extraEntity)
		]);
	`
		));

		await expect(page.locator('canvas')).toHaveAttribute(
			'data-session-probe'
			, '[true,true,1.25,"playstation",true]'
		);

		expect(await page.evaluate(() => refreshes)).toBe(0);
		expect(binaries).toHaveLength(1);
		expect(errors).toEqual([]);
	}
);

test(
	'mute gates real SDL output, retains mixer volumes, and covers devices opened after muting'
	, async ({page}) => {
		await page.addInitScript(() => {
			window.outputGains = [];
			const NativeAudioContext = window.AudioContext;

			window.AudioContext = class extends NativeAudioContext {

				createGain() {
					const gain = super.createGain();
					outputGains.push(gain);

					return gain;
				}
			};
		});

		await page.goto('/sane-phonics/');
		await page.click('#mute');
		await start(page);

		const play = async () => page.evaluate(async () => {
			const module = await enginePhp.binary;
			const samples = 22050, wav = new Uint8Array(44 + samples * 2);
			const view = new DataView(wav.buffer);

			const text = (at, value) => wav.set(new TextEncoder().encode(value), at);
			text(0, 'RIFF');
			view.setUint32(4, wav.length - 8, true);
			text(8, 'WAVEfmt ');
			view.setUint32(16, 16, true);
			view.setUint16(20, 1, true);
			view.setUint16(22, 1, true);
			view.setUint32(24, 22050, true);
			view.setUint32(28, 44100, true);
			view.setUint16(32, 2, true);
			view.setUint16(34, 16, true);
			text(36, 'data');
			view.setUint32(40, samples * 2, true);

			for(let i = 0; i < samples; i++)
			{
				view.setInt16(44 + i * 2, Math.sin(i * Math.PI * 2 * 440 / 22050) * 20000, true);
			}

			module.FS.writeFile('/tone.wav', wav);

			const code = await enginePhp.run(`<?php
			SDL_InitSubSystem(SDL_INIT_AUDIO);
			if(Mix_OpenAudio(22050, MIX_DEFAULT_FORMAT, 1, 1024) !== 0)
			{
				throw new Exception(Mix_GetError());
			}
			$tone = Mix_LoadWAV('/tone.wav'); Mix_Volume(0, 53); Mix_VolumeMusic(77);
			Mix_PlayChannel(0, $tone, -1);
		`
			);

			if(code !== 0) throw Error('Audio fixture failed');

			const gain = outputGains.at(-1);
			window.outputProbe = gain.context.createAnalyser();
			gain.connect(outputProbe);
			await gain.context.resume();
		});

		const peak = () => page.evaluate(() => {
			const samples = new Float32Array(outputProbe.fftSize);
			outputProbe.getFloatTimeDomainData(samples);

			return Math.max(...samples.map(Math.abs));
		});

		await test.step('Open SDL output', play);
		await expect.poll(() => page.evaluate(() => outputGains.at(-1).gain.value)).toBe(0);
		await expect.poll(peak).toBe(0);
		await page.click('#mute');
		await expect.poll(peak).toBeGreaterThan(.01);
		await open(page);
		await activate(page, 'mute');
		await expect(page.locator('#mute')).toHaveAttribute('aria-pressed', 'true');
		await expect.poll(peak).toBe(0);
		await page.click('#mute');
		await expect.poll(async () => (await menu(page)).values.mute).toBe(false);
		await expect.poll(peak).toBeGreaterThan(.01);

		await page.evaluate(async () => {
			await enginePhp.run(`<?php
			vrzno_env('canvas')->dataset->volumes = json_encode(
				[Mix_Volume(0, -1), Mix_VolumeMusic(-1), Mix_Playing(0)]
			);
		`
			);
		});

		await expect(page.locator('canvas')).toHaveAttribute('data-volumes', '[53,77,1]');
		await page.click('#mute');
		await page.evaluate(() => enginePhp.run('<?php Mix_CloseAudio();'));
		await test.step('Open SDL output', play);
		await expect.poll(peak).toBe(0);
		await test.step('Stop audio demo', () => page.evaluate(() => window.stopEngine()));
		await test.step('Restart audio demo', () => start(page));
		await test.step('Open SDL output', play);
		await expect(page.locator('#mute')).toHaveAttribute('aria-pressed', 'true');
		await expect.poll(peak).toBe(0);
	}
);

test(
	'fullscreen menu and corner shortcut follow browser exits and recover from denied requests'
	, async ({page}) => {
		const errors = [];
		page.on('pageerror', error => errors.push(error.message));
		await page.setViewportSize({width: 1100, height: 700});
		await page.goto('/sane-phonics/');
		await start(page);
		await open(page);
		await activate(page, 'fullscreen');
		await expect.poll(() => page.evaluate(() => document.fullscreenElement?.id)).toBe('game-frame');
		await expect(page.locator('#fullscreen')).toHaveAttribute('aria-pressed', 'true');
		await expect(page.locator('.quick-controls')).toBeVisible();

		const frame = await page.evaluate(() => {
			const target = document.fullscreenElement;
			const rect = target.getBoundingClientRect();

			return {
				width: rect.width
				, height: rect.height
				, viewport: [innerWidth, innerHeight]
				, canvas: target.contains(document.querySelector('canvas'))
				, shortcuts: target.contains(document.querySelector('.quick-controls'))
				, start: target.contains(document.querySelector('#start'))
				, status: target.contains(document.querySelector('#status'))
				, text: target.contains(document.querySelector('.controls'))
			};
		});

		expect([frame.width, frame.height]).toEqual(frame.viewport);

		expect(frame).toMatchObject({
			canvas: true
			, shortcuts: true
			, start: true
			, status: false
			, text: false
		});

		const mutePoint = await page.locator('canvas').evaluate(canvas => {
			const rect = canvas.getBoundingClientRect();

			const [x, y, w, h] = JSON.parse(canvas.dataset.menu).regions
				.find(row => row.id === 'mute').rect;

			return {
				x: rect.x + (x + w / 2) / canvas.width * rect.width
				, y: rect.y + (y + h / 2) / canvas.height * rect.height
			};
		});

		await page.mouse.click(mutePoint.x, mutePoint.y);
		await expect(page.locator('#mute')).toHaveAttribute('aria-pressed', 'true');
		await page.evaluate(() => document.exitFullscreen());
		await expect.poll(async () => (await menu(page)).values.fullscreen).toBe(false);
		await expect(page.locator('#fullscreen')).toHaveAttribute('aria-pressed', 'false');

		await page.evaluate(() => {
			const demo = document.querySelector('#game-frame');
			window.requestDemoFullscreen = demo.requestFullscreen;

			demo.requestFullscreen = () => Promise.reject(new DOMException('Denied', 'NotAllowedError'));
		});

		await page.click('#fullscreen');
		await expect(page.locator('#fullscreen-error')).toBeVisible();
		await expect(page.locator('#fullscreen')).toBeEnabled();
		await expect(page.locator('#fullscreen')).toHaveAttribute('aria-pressed', 'false');

		await page.evaluate(() => {
			document.querySelector('#game-frame').requestFullscreen = requestDemoFullscreen;
		});

		await page.click('#fullscreen');
		await expect.poll(() => page.evaluate(() => document.fullscreenElement?.id)).toBe('game-frame');
		await expect(page.locator('#fullscreen-error')).toBeHidden();
		await page.click('#fullscreen');
		await expect.poll(() => page.evaluate(() => document.fullscreenElement)).toBeNull();
		expect(errors).toEqual([]);
	}
);

test(
	'unsupported fullscreen and blocked preference storage leave audio controls usable'
	, async ({page}) => {
		await page.addInitScript(() => {
			Object.defineProperty(document, 'fullscreenEnabled', {value: false});

			Object.defineProperty(window, 'localStorage', {

				get() { throw new DOMException('Blocked', 'SecurityError'); }
			});
		});

		await page.goto('/sane-phonics/');
		await expect(page.locator('#fullscreen')).toBeHidden();
		await page.click('#mute');
		await expect(page.locator('#mute')).toHaveAttribute('aria-pressed', 'true');
		await start(page);
		await open(page);
		expect((await menu(page)).values).not.toHaveProperty('fullscreen');
		await activate(page, 'mute');
		await expect(page.locator('#mute')).toHaveAttribute('aria-pressed', 'false');
	}
);

for(const [asset, scene] of [
	['examples/assets/stream.world', 'world'], ['examples/assets/loop.tmj', 'loop']
])
	test(
		'Scripted stop cancels ' + asset + ' during a level change and Run can retry'
		, async ({page}) => {
			await externalAssets(page);
			const errors = [];
			page.on('pageerror', error => errors.push(error.message));
			await page.goto('/sane-phonics/');
			await start(page);
			let pending;
			await page.route('**/engine/packages/sane-phonics/' + asset, route => { pending = route; });

			await open(page);
			await activate(page, 'level');
			await activate(page, scene);
			await expect.poll(() => Boolean(pending)).toBe(true);
			await page.evaluate(() => window.stopEngine());
			await expect(page.locator('#start')).toBeEnabled();
			await expect(page.locator('#status')).toContainText('Stopped');

			await test.step('Release cancelled manifest', () =>
				pending.fulfill({json: ['autoload.php']}).catch(() => {})
			);

			await page.unroute('**/engine/packages/sane-phonics/' + asset);
			await test.step('Retry cancelled level', () => start(page));
			await expect(page.locator('canvas')).toHaveAttribute('data-scene', 'platformer');
			expect(errors).toEqual([]);
		}
	);


for(const asset of ['dist/game.phar', 'examples/assets/platformer.tmj'])
	test(
		'failed startup ' + asset + ' exposes Run without leaving a frame loop'
		, async ({page}) => {
			await externalAssets(page);
			await page.goto('/sane-phonics/');

			await page.route(
				'**/engine/packages/sane-phonics/' + asset
				, route => route.fulfill({status: 503, body: 'Unavailable'})
			);

			await page.evaluate(() => window.bootEngine({intro: false}).catch(() => {}));

			await expect(page.locator('#status')).toContainText('503');
			await expect(page.locator('#start')).toBeEnabled();
			await expect(page.locator('#stop')).toHaveCount(0);
			await page.unroute('**/engine/packages/sane-phonics/' + asset);
			await start(page);
			await expect(page.locator('canvas')).toHaveAttribute('data-scene', 'platformer');
		}
	);

test(
	'a newer scripted boot keeps its loading control while the previous run stops'
	, async ({page}) => {
		await page.goto('/sane-phonics/');
		await start(page);
		let pending;
		await page.route('**/engine/packages/sane-phonics/dist/game.phar', route => { pending = route; });

		await page.evaluate(() => {
			window.stopEngine();
			window.nextRun = window.bootEngine({scene: 'world'});
		});

		await expect.poll(() => Boolean(pending)).toBe(true);
		await expect(page.locator('#start')).toBeVisible();
		await expect(page.locator('#start')).toBeDisabled();
		await expect(page.locator('#start')).toHaveText('Loading PHP…');
		await expect(page.locator('#status')).toContainText('Loading');
		await pending.continue();
		await page.evaluate(() => window.nextRun);
		await expect(page.locator('#start')).toBeHidden();
		await expect(page.locator('canvas')).toHaveAttribute('data-scene', 'world');
	}
);
