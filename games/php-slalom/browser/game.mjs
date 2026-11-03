/** Browser shell for PHP Slalom: storage, replay files, sound, touch, and accessible hit targets. */
export async function activate({module, canvas, assets, packageUrl, services, session, onClose})
{
	if(!canvas.getContext('webgl2', {antialias: false})) throw Error('PHP Slalom requires WebGL2');

	const controlsKey = 'php-slalom.controls.v1';
	const profileKey = 'php-slalom.profile.v1';
	const optionsKey = 'php-slalom.options.v1';
	const storage = new services.SafeStorage;
	const parent = canvas.parentNode, next = canvas.nextSibling, originalStyle = canvas.getAttribute('style');
	const abort = new AbortController(), {signal} = abort;
	const ui = document.createElement('section');
	const screen = document.createElement('div');
	const targets = document.createElement('div');
	const touch = document.createElement('div');
	const status = document.createElement('p');
	const file = document.createElement('input');
	const style = document.createElement('style');
	ui.className = 'php-slalom-ui';
	ui.setAttribute('aria-label', 'PHP Slalom');
	screen.className = 'php-slalom-screen';
	targets.className = 'php-slalom-targets';
	touch.className = 'php-slalom-touch';
	touch.hidden = true;
	touch.innerHTML = `<button type="button" data-direction="left" aria-label="Steer left">LEFT</button>
		<button type="button" data-direction="right" aria-label="Steer right">RIGHT</button>`;

	status.className = 'php-slalom-sr';
	status.setAttribute('aria-live', 'polite');
	file.type = 'file';
	file.accept = '.pslr,application/vnd.phonics.slalom-replay';
	file.className = 'php-slalom-file';
	style.textContent = `
		.php-slalom-ui{position:relative;width:100%;max-width:none;margin:auto;background:#000;color:#fff}
		.php-slalom-screen{position:relative;width:100%;aspect-ratio:8/5;isolation:isolate;overflow:hidden;background:#000}
		.php-slalom-screen>canvas{position:absolute;inset:0;display:block;width:100%;height:100%;max-width:none;
			max-height:none;margin:0;border:0;border-radius:0;image-rendering:pixelated;touch-action:none}
		.php-slalom-targets{position:absolute;inset:0;pointer-events:none}
		.php-slalom-targets button{position:absolute;pointer-events:auto;padding:0;border:0;border-radius:0;
			background:transparent;color:transparent;box-shadow:none;touch-action:manipulation}
		.php-slalom-targets button:is(:hover,:focus,:active){background:transparent;color:transparent;box-shadow:none}
		.php-slalom-targets button:focus-visible{outline:2px solid #ffe56b;outline-offset:-2px}
		.php-slalom-touch{position:absolute;inset:auto 0 0;display:grid;grid-template-columns:1fr 1fr;height:26%;z-index:2;
			pointer-events:none}
		.php-slalom-touch[hidden]{display:none}
		.php-slalom-touch button{pointer-events:auto;border:0;background:#001b2b18;color:transparent;touch-action:none;
			box-shadow:inset 0 0 0 1px #fff2;border-radius:0;padding:0}
		.php-slalom-touch button:active{background:#8bdcff22}
		.php-slalom-sr,.php-slalom-file{position:absolute!important;width:1px!important;height:1px!important;
			padding:0!important;margin:-1px!important;overflow:hidden!important;clip-path:inset(50%)!important;
			white-space:nowrap!important;border:0!important}
		.php-slalom-screen .quick-controls{right:6px;bottom:4px;z-index:4;padding:0;opacity:.8}
		.php-slalom-screen .quick-controls button{width:30px;height:30px;padding:4px}
		.php-slalom-ui:fullscreen{display:flex;align-items:center;justify-content:center;width:100%;height:100%;max-width:none}
		.php-slalom-ui:fullscreen .php-slalom-screen{width:min(100vw,160vh)}
		[data-game-embed="true"] .php-slalom-ui{display:flex;align-items:center;justify-content:center;height:100%}
		[data-game-embed="true"] .php-slalom-screen{width:min(100vw,160vh)}
		@media(pointer:coarse){.php-slalom-screen .quick-controls button{width:44px;height:44px}}
	`;

	parent.insertBefore(ui, canvas);
	ui.append(style, screen, status, file);
	screen.append(canvas, targets, touch);
	canvas.tabIndex = Math.max(0, canvas.tabIndex);
	const quick = new services.QuickControls(screen, ui);
	const buttons = new Map(), pointers = new Map();
	const held = {left: false, right: false};
	let viewport = '[960,600,1,false]', action = '', imported = '', state = null, signature = '';
	let fullscreenBusy = false, fullscreenTask = null, closed = false;
	let crashSounded = false, savedRun = '';

	const safeJson = (value, fallback) => {
		try
		{
			return JSON.parse(value);
		}
		catch
		{
			return fallback;
		}
	};

	const scores = value => Array.isArray(value)
		? value.filter(score => Number.isSafeInteger(score) && score >= 0).slice(0, 10)
		: [];

	const rawProfile = safeJson(storage.get(profileKey) ?? '{}', {});
	const profile = {
		version: 1
		, fresh: scores(rawProfile?.fresh)
		, continued: scores(rawProfile?.continued)
		, bestFresh: rawProfile?.bestFresh && Number.isSafeInteger(rawProfile.bestFresh.score)
			&& typeof rawProfile.bestFresh.replay === 'string'
			? rawProfile.bestFresh
			: null
		, lastReplay: typeof rawProfile?.lastReplay === 'string' ? rawProfile.lastReplay : ''
	};

	const rawOptions = safeJson(storage.get(optionsKey) ?? '{}', {});
	const options = {version: 1, muted: rawOptions?.version === 1 && rawOptions.muted === true};

	const saveProfile = () => storage.set(profileKey, JSON.stringify(profile));

	const saveOptions = () => storage.set(optionsKey, JSON.stringify(options));

	const bytesToBase64 = bytes => {
		let binary = '';

		for(let offset = 0; offset < bytes.length; offset += 0x8000)
		binary += String.fromCharCode(...bytes.subarray(offset, offset + 0x8000));

		return btoa(binary);
	};

	const base64ToBytes = value => {
		const binary = atob(value), bytes = new Uint8Array(binary.length);

		for(let index = 0; index < binary.length; index++) bytes[index] = binary.charCodeAt(index);

		return bytes;
	};

	function measure()
	{
		const box = screen.getBoundingClientRect(), dpr = devicePixelRatio || 1;
		const width = Math.max(1, Math.round(box.width * dpr));
		const height = Math.max(1, Math.round(box.height * dpr));
		const coarse = globalThis.matchMedia?.('(pointer: coarse)').matches ?? false;
		viewport = JSON.stringify([width, height, dpr, coarse]);
		canvas.style.width = box.width + 'px';
		canvas.style.height = box.height + 'px';
	}

	const resize = new ResizeObserver(measure);
	resize.observe(screen);
	measure();

	const samples = new services.SamplePlayer(module, assets, packageUrl, {muted: options.muted});

	async function unlock()
	{
		if(await samples.unlock()) await samples.load('impact', 'assets/bomb.wav');
	}

	function impact()
	{
		void unlock().then(() => samples.play('impact', {gain: .6}));
	}

	quick.setMuted(options.muted);

	quick.muteButton.addEventListener('click', () => {
		options.muted = !options.muted;
		saveOptions();
		quick.setMuted(options.muted);
		samples.setMuted(options.muted);

		if(!options.muted) void unlock();

		canvas.focus({preventScroll: true});
	}, {signal});

	function updateFullscreen(error = '')
	{
		quick.setFullscreen(
			document.fullscreenElement === ui
			, Boolean(document.fullscreenEnabled && ui.requestFullscreen)
			, fullscreenBusy
			, error
		);

		measure();
	}

	quick.fullscreenButton.addEventListener('click', async() => {
		if(fullscreenBusy) return;

		fullscreenBusy = true;
		updateFullscreen();

		try
		{
			fullscreenTask = document.fullscreenElement === ui ? document.exitFullscreen() : ui.requestFullscreen();
			await fullscreenTask;
		}
		catch(error)
		{
			if(!closed) updateFullscreen(error.message);
		}

		fullscreenBusy = false;

		if(!closed)
		{
			updateFullscreen();
			canvas.focus({preventScroll: true});
		}
	}, {signal});

	document.addEventListener('fullscreenchange', updateFullscreen, {signal});
	updateFullscreen();

	canvas.addEventListener('pointerdown', () => { canvas.focus({preventScroll: true}); void unlock(); }, {signal});
	canvas.addEventListener('keydown', () => { void unlock(); }, {signal});
	canvas.addEventListener('contextmenu', event => event.preventDefault(), {signal});

	for(const button of touch.querySelectorAll('button'))
	{
		const direction = button.dataset.direction;

		const release = event => {
			pointers.delete(event.pointerId);
			held[direction] = [...pointers.values()].includes(direction);
		};

		button.addEventListener('pointerdown', event => {
			event.preventDefault();
			void unlock();
			button.setPointerCapture(event.pointerId);
			pointers.set(event.pointerId, direction);
			held[direction] = true;
			canvas.focus({preventScroll: true});
		}, {signal});

		button.addEventListener('pointerup', release, {signal});
		button.addEventListener('pointercancel', release, {signal});
		button.addEventListener('lostpointercapture', release, {signal});
	}

	file.addEventListener('change', async() => {
		const selected = file.files?.[0];
		file.value = '';

		if(!selected) return;

		if(selected.size > 1024 * 1024)
		{
			status.textContent = 'Replay rejected: the file is larger than one megabyte.';

			return;
		}

		try
		{
			imported = bytesToBase64(new Uint8Array(await selected.arrayBuffer()));
		}
		catch(error)
		{
			status.textContent = 'Could not read replay: ' + error.message;
		}
	}, {signal});

	module.slalomViewport = () => viewport;

	module.slalomAction = () => { const value = action; action = ''; return value; };

	module.slalomTouch = () => JSON.stringify(held);

	module.slalomStorageRead = () => storage.get(controlsKey) ?? '';

	module.slalomStorageWrite = value => storage.set(controlsKey, String(value));

	module.slalomSeed = () => {
		const seed = new Uint32Array(1);
		globalThis.crypto.getRandomValues(seed);

		return (seed[0] & 0x7fffffff) || 1;
	};

	module.slalomScores = () => JSON.stringify({fresh: profile.fresh, continued: profile.continued});

	module.slalomLastReplay = () => profile.lastReplay;

	module.slalomRequestReplayImport = () => file.click();

	module.slalomReplayImport = () => { const value = imported; imported = ''; return value; };

	module.slalomExportLast = () => {
		if(!profile.lastReplay)
		{
			status.textContent = 'There is no local replay to export yet.';

			return false;
		}

		try
		{
			const blob = new Blob([base64ToBytes(profile.lastReplay)], {type: 'application/vnd.phonics.slalom-replay'});
			const url = URL.createObjectURL(blob), link = document.createElement('a');
			link.href = url;
			link.download = `php-slalom-${Date.now()}.pslr`;
			link.click();
			setTimeout(() => URL.revokeObjectURL(url), 0);

			return true;
		}
		catch(error)
		{
			status.textContent = 'Could not export replay: ' + error.message;

			return false;
		}
	};

	module.slalomStatus = json => {
		const nextState = JSON.parse(json);
		state = nextState;
		canvas.dataset.phpSlalom = json;
		const coarse = globalThis.matchMedia?.('(pointer: coarse)').matches ?? false;
		touch.hidden = nextState.page !== 'play' || !coarse || nextState.replay;

		if(nextState.crashed && !crashSounded) impact();

		crashSounded = nextState.crashed;
		const regions = Array.isArray(nextState.regions) ? nextState.regions : [];
		const key = JSON.stringify([nextState.width, nextState.height, regions]);

		if(key !== signature)
		{
			signature = key;
			const wanted = new Set(regions.map(region => String(region.id)));

			for(const [id,button] of buttons)
			if(!wanted.has(id))
			{
				button.remove();
				buttons.delete(id);
			}

			for(const region of regions)
			{
				const id = String(region.id);
				let button = buttons.get(id);

				if(!button)
				{
					button = document.createElement('button');
					button.type = 'button';
					button.dataset.action = id;

					button.addEventListener(
						'click'
						, () => { action = id; canvas.focus({preventScroll: true}); void unlock(); }
						, {signal}
					);

					buttons.set(id, button);
					targets.append(button);
				}

				button.setAttribute('aria-label', region.label || id);
				button.disabled = Boolean(region.disabled);
				const [x,y,width,height] = region.rect;

				Object.assign(button.style, {
					left: 100 * x / nextState.width + '%'
					, top: 100 * y / nextState.height + '%'
					, width: 100 * width / nextState.width + '%'
					, height: 100 * height / nextState.height + '%'
				});
			}
		}

		const summary = `${nextState.page}. Score ${nextState.score}. Stage ${nextState.stage}${nextState
			.replay
			? '. Replay.'
			: '.'}`;

		if(status.textContent !== summary) status.textContent = summary;

		if(nextState.result && !nextState.replay && savedRun !== String(nextState.result.runId))
		{
			savedRun = String(nextState.result.runId);
			const table = nextState.result.continued ? profile.continued : profile.fresh;
			table.push(nextState.result.score);
			table.sort((a, b) => b - a);
			table.length = Math.min(10, table.length);
			profile.lastReplay = nextState.result.replay;

			if(!nextState.result.continued && (!profile.bestFresh || nextState.result.score > profile.bestFresh.score))
			profile.bestFresh = {score: nextState.result.score, replay: nextState.result.replay};

			saveProfile();
		}
	};

	session.phpSlalom = {get state(){ return state; }, get profile(){ return structuredClone(profile); }};

	onClose(async() => {
		closed = true;
		abort.abort();
		resize.disconnect();
		quick.close();

		await fullscreenTask?.catch(() => {});

		if(document.fullscreenElement === ui) await document.exitFullscreen().catch(() => {});

		await samples.close();

		if(next?.parentNode === parent) parent.insertBefore(canvas, next); else parent.append(canvas);

		if(originalStyle === null) canvas.removeAttribute('style'); else canvas.setAttribute('style', originalStyle);

		delete canvas.dataset.phpSlalom;
		ui.remove();

		for(const key of ['slalomViewport'
			, 'slalomAction'
			, 'slalomTouch'
			, 'slalomStorageRead'
			, 'slalomStorageWrite'
			, 'slalomSeed'
			, 'slalomScores'
			, 'slalomLastReplay'
			, 'slalomRequestReplayImport'
			, 'slalomReplayImport'
			, 'slalomExportLast','slalomStatus'
		]) delete module[key];

		delete session.phpSlalom;
	});
}
