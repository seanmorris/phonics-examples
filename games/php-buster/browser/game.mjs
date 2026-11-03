/** Browser shell for PHP Buster: storage, sound, pointer/touch motion, and accessible menus. */
export async function activate({module, canvas, assets, packageUrl, services, session, onClose})
{
	if(!canvas.getContext('webgl2', {antialias: false})) throw Error('PHP Buster requires WebGL2');

	const controlsKey = 'php-buster.controls.v1';
	const profileKey = 'php-buster.profile.v1';
	const optionsKey = 'php-buster.options.v1';
	const storage = new services.SafeStorage;
	const parent = canvas.parentNode, next = canvas.nextSibling, originalStyle = canvas.getAttribute('style');
	const abort = new AbortController(), {signal} = abort;
	const ui = document.createElement('section');
	const screen = document.createElement('div');
	const targets = document.createElement('div');
	const touch = document.createElement('div');
	const fire = document.createElement('button');
	const status = document.createElement('p');
	const style = document.createElement('style');
	ui.className = 'php-buster-ui';
	ui.setAttribute('aria-label', 'PHP Buster');
	screen.className = 'php-buster-screen';
	targets.className = 'php-buster-targets';
	touch.className = 'php-buster-touch';
	touch.hidden = true;
	fire.type = 'button';
	fire.textContent = 'FIRE';
	fire.setAttribute('aria-label', 'Fire');
	status.className = 'php-buster-sr';
	status.setAttribute('aria-live', 'polite');
	style.textContent = `
		.php-buster-ui{position:relative;display:flex;align-items:center;justify-content:center;width:100%;max-width:none;
			margin:auto;background:#000;color:#fff}
		.php-buster-screen{position:relative;width:min(100%,66.6667vh);max-height:100vh;aspect-ratio:2/3;
			isolation:isolate;overflow:hidden;background:#000}
		.php-buster-screen>canvas{position:absolute;inset:0;display:block;width:100%;height:100%;max-width:none;
			max-height:none;margin:0;border:0;border-radius:0;image-rendering:pixelated;touch-action:none}
		.php-buster-targets{position:absolute;inset:0;pointer-events:none}
		.php-buster-targets button{position:absolute;pointer-events:auto;padding:0;border:0;border-radius:0;
			background:transparent;color:transparent;box-shadow:none;touch-action:manipulation}
		.php-buster-targets button:is(:hover,:focus,:active){background:transparent;color:transparent;box-shadow:none}
		.php-buster-targets button:focus-visible{outline:2px solid #ffe56b;outline-offset:-2px}
		.php-buster-touch{position:absolute;right:4%;bottom:3%;z-index:3;pointer-events:none}
		.php-buster-touch[hidden]{display:none}
		.php-buster-touch button{pointer-events:auto;width:clamp(64px,18vw,104px);height:clamp(64px,18vw,104px);
			border:2px solid #fff8;border-radius:50%;background:#65182788;color:#fff;font:700 14px/1 sans-serif;
			touch-action:none;box-shadow:0 2px 12px #000a}
		.php-buster-touch button:active{background:#b62442aa}
		.php-buster-sr{position:absolute!important;width:1px!important;height:1px!important;padding:0!important;
			margin:-1px!important;overflow:hidden!important;clip-path:inset(50%)!important;white-space:nowrap!important;border:0!important}
		.php-buster-screen .quick-controls{right:6px;top:auto;bottom:4px;z-index:4;padding:0;opacity:.8}
		.php-buster-screen .quick-controls button{width:30px;height:30px;padding:4px}
		.php-buster-ui:fullscreen{display:flex;align-items:center;justify-content:center;width:100%;height:100%;max-width:none}
		.php-buster-ui:fullscreen .php-buster-screen{height:100vh;width:min(100vw,66.6667vh)}
		[data-game-embed="true"] .php-buster-ui{height:100%}
		[data-game-embed="true"] .php-buster-screen{width:min(100%,66.6667vh)}
		@media(pointer:coarse){.php-buster-screen .quick-controls button{width:44px;height:44px}}
	`;

	parent.insertBefore(ui, canvas);
	ui.append(style, screen, status);
	screen.append(canvas, targets, touch);
	touch.append(fire);
	canvas.tabIndex = Math.max(0, canvas.tabIndex);
	const quick = new services.QuickControls(screen, ui);
	const buttons = new Map;
	const pointer = {active: false, x: 160, y: 400, fire: false};
	const drags = new Map;
	const firePointers = new Set;
	let viewport = '[800,720,1,false]', action = '', state = null, signature = '';
	let fullscreenBusy = false, fullscreenTask = null, closed = false, soundedExplosion = 0;
	let mouseInside = false;

	const safeJson = (value, fallback) => {
		try { return JSON.parse(value); } catch { return fallback; }
	};

	const cleanScores = value => Array.isArray(value)
		? value.filter(
			row => row && Number.isSafeInteger(row.score) && row.score >= 0 && typeof row.initials === 'string'
		)
			.map(row => ({
				initials: row.initials.toUpperCase().replace(/[^A-Z0-9]/g, '').slice(0, 3).padEnd(3, 'A')
				, score: row.score
			}))
			.slice(0, 10)
		: [];

	const rawProfile = safeJson(storage.get(profileKey) ?? '{}', {});
	const profile = {version: 1, scores: cleanScores(rawProfile?.scores)};
	const rawOptions = safeJson(storage.get(optionsKey) ?? '{}', {});
	const options = {version: 1, muted: rawOptions?.version === 1 && rawOptions.muted === true};

	const saveProfile = () => storage.set(profileKey, JSON.stringify(profile));

	const saveOptions = () => storage.set(optionsKey, JSON.stringify(options));

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

	function logical(clientX, clientY)
	{
		const box = canvas.getBoundingClientRect();
		const physicalX = (clientX - box.left) * (state?.width ?? canvas.width) / Math.max(1, box.width);
		const physicalY = (clientY - box.top) * (state?.height ?? canvas.height) / Math.max(1, box.height);
		const [x = 0,y = 0,width = state?.width ?? 320,height = state?.height ?? 480] = state?.viewport ?? [];

		return [
			Math.max(0, Math.min(320, (physicalX - x) * 320 / Math.max(1, width)))
			, Math.max(0, Math.min(480, (physicalY - y) * 480 / Math.max(1, height)))
		];
	}

	const resize = new ResizeObserver(measure);
	resize.observe(screen);
	measure();
	const samples = new services.SamplePlayer(module, assets, packageUrl, {muted: options.muted});

	async function unlock()
	{
		if(await samples.unlock()) await samples.load('explosion', 'assets/explosion.wav');
	}

	function explosion()
	{
		void unlock().then(() => samples.play('explosion', {gain: .55}));
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
	canvas.addEventListener('contextmenu', event => event.preventDefault(), {signal});

	canvas.addEventListener('focus', () => {
		if(document.fullscreenElement === ui) return;

		globalThis.scrollTo({top: globalThis.scrollY + screen.getBoundingClientRect().top, behavior: 'auto'});
	}, {signal});

	canvas.addEventListener('keydown', event => {
		void unlock();

		if(state?.page === 'play' && [
			'ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown', 'a', 'd', 's', 'w'
		].includes(event.key)) pointer.active = false;
	}, {signal});

	canvas.addEventListener('pointerenter', event => {
		if(event.pointerType !== 'touch' && state?.page === 'play')
		{
			mouseInside = true;
			[pointer.x,pointer.y] = logical(event.clientX, event.clientY);
		}
	}, {signal});

	canvas.addEventListener('pointermove', event => {
		if(state?.page !== 'play') return;

		if(event.pointerType === 'touch')
		{
			const drag = drags.get(event.pointerId);

			if(!drag) return;

			const box = canvas.getBoundingClientRect();

			pointer.x = Math.max(
				0
				, Math.min(320, drag.x + (event.clientX - drag.clientX) * 320 / Math.max(1, box.width))
			);

			pointer.y = Math.max(
				0
				, Math.min(480, drag.y + (event.clientY - drag.clientY) * 480 / Math.max(1, box.height))
			);

			pointer.active = true;
		}
		else
		{
			mouseInside = true;
			[pointer.x,pointer.y] = logical(event.clientX, event.clientY);
			pointer.active = true;
		}
	}, {signal});

	canvas.addEventListener('pointerdown', event => {
		canvas.focus({preventScroll: true});
		void unlock();

		if(state?.page !== 'play') return;

		if(event.pointerType === 'touch')
		{
			event.preventDefault();
			canvas.setPointerCapture(event.pointerId);
			const [x,y] = state?.player ?? [pointer.x,pointer.y];
			drags.set(event.pointerId, {clientX: event.clientX, clientY: event.clientY, x, y});
			pointer.x = x;
			pointer.y = y;
			pointer.active = true;
		}
		else
		{
			[pointer.x,pointer.y] = logical(event.clientX, event.clientY);
			pointer.active = true;

			if(event.button === 0)
			{
				canvas.setPointerCapture(event.pointerId);
				firePointers.add(event.pointerId);
				pointer.fire = true;
			}
		}
	}, {signal});

	const releasePointer = event => {
		if(event.pointerType === 'touch')
		{
			drags.delete(event.pointerId);
			pointer.active = drags.size > 0 || mouseInside;
		}
		else
		{
			firePointers.delete(event.pointerId);
			pointer.fire = firePointers.size > 0;
			pointer.active = mouseInside;
		}
	};

	canvas.addEventListener('pointerup', releasePointer, {signal});
	canvas.addEventListener('pointercancel', releasePointer, {signal});
	canvas.addEventListener('lostpointercapture', releasePointer, {signal});

	canvas.addEventListener('pointerleave', event => {
		if(event.pointerType !== 'touch')
		{
			mouseInside = false;

			if(!pointer.fire) pointer.active = false;
		}
	}, {signal});

	const releaseFire = event => {
		firePointers.delete(event.pointerId);
		pointer.fire = firePointers.size > 0;
	};

	fire.addEventListener('pointerdown', event => {
		event.preventDefault();
		void unlock();
		fire.setPointerCapture(event.pointerId);
		firePointers.add(event.pointerId);
		pointer.fire = true;
		canvas.focus({preventScroll: true});
	}, {signal});

	fire.addEventListener('pointerup', releaseFire, {signal});
	fire.addEventListener('pointercancel', releaseFire, {signal});
	fire.addEventListener('lostpointercapture', releaseFire, {signal});

	module.busterViewport = () => viewport;

	module.busterAction = () => { const value = action; action = ''; return value; };

	module.busterPointer = () => JSON.stringify(pointer);

	module.busterStorageRead = () => storage.get(controlsKey) ?? '';

	module.busterStorageWrite = value => storage.set(controlsKey, String(value));

	module.busterSeed = () => {
		const seed = new Uint32Array(1);
		globalThis.crypto.getRandomValues(seed);

		return (seed[0] & 0x7fffffff) || 1;
	};

	module.busterScores = () => JSON.stringify(profile.scores);

	module.busterSubmitScore = (initials, score) => {
		initials = String(initials).toUpperCase().replace(/[^A-Z0-9]/g, '').slice(0, 3).padEnd(3, 'A');
		score = Math.max(0, Math.trunc(Number(score) || 0));
		profile.scores.push({initials, score});
		profile.scores.sort((a, b) => b.score - a.score);
		profile.scores.length = Math.min(10, profile.scores.length);

		return saveProfile();
	};

	module.busterStatus = json => {
		const nextState = JSON.parse(json);
		const previousRun = state?.runId;
		state = nextState;
		canvas.dataset.phpBuster = json;
		const coarse = globalThis.matchMedia?.('(pointer: coarse)').matches ?? false;
		touch.hidden = nextState.page !== 'play' || !coarse;

		if(nextState.runId !== previousRun) soundedExplosion = 0;

		while(soundedExplosion < nextState.explosions)
		{
			soundedExplosion++;

			if(nextState.explosions - soundedExplosion < 4) explosion();
		}

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

					button.addEventListener('click', () => {
						action = id;
						canvas.focus({preventScroll: true});
						void unlock();
					}, {signal});

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

		const summary = `${nextState.page}. Score ${nextState.score}. Level ${nextState.level}, stage ${nextState
			.stage}. ${nextState.shields} shields.`;

		if(status.textContent !== summary) status.textContent = summary;
	};

	session.phpBuster = {get state(){ return state; }, get profile(){ return structuredClone(profile); }};

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

		delete canvas.dataset.phpBuster;
		ui.remove();

		for(const key of ['busterViewport'
			, 'busterAction'
			, 'busterPointer'
			, 'busterStorageRead'
			, 'busterStorageWrite'
			, 'busterSeed'
			, 'busterScores'
			, 'busterSubmitScore'
			, 'busterStatus'
		]) delete module[key];

		delete session.phpBuster;
	});
}
