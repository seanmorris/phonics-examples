// Keep browser helpers inline: this entry executes from a verified blob URL.

/** Convert browser pointer movement into canonical WAD look deltas. */
export function mouseLookDelta(movementX, movementY)
{
	return {yaw: -movementX * .0025, pitch: -movementY * .002};
}

/** Async browser output for WAD samples decoded and selected by PHP. */
class WadAudio
{

	constructor(muted = false, volume = 1)
	{
		this.muted = muted;
		this.volume = Math.max(0, Math.min(1, Number(volume)));
		this.context = null;
		this.master = null;
		this.buffers = new Map;
		this.sources = new Set;
	}

	setMuted(muted)
	{
		this.muted = Boolean(muted);

		this.applyVolume();
	}

	setVolume(volume)
	{
		this.volume = Math.max(0, Math.min(1, Number(volume)));
		this.applyVolume();
	}

	applyVolume()
	{
		if(this.master) this.master.gain.value = this.muted ? 0 : this.volume;
	}

	async play(value)
	{
		const event = typeof value === 'string' ? JSON.parse(value) : value;

		if(event.stop)
		{
			for(const source of this.sources) try { source.stop(); } catch {}

			this.sources.clear();

			return;
		}

		const context = this.open();
		let buffer = this.buffers.get(event.key);

		if(!buffer && event.wav)
		{
			const binary = atob(event.wav);
			const bytes = Uint8Array.from(binary, character => character.charCodeAt(0));
			buffer = context.decodeAudioData(bytes.buffer);
			this.buffers.set(event.key, buffer);
		}

		buffer = await buffer;

		if(!buffer) return;

		await this.unlock();

		const source = context.createBufferSource();
		const gain = context.createGain();
		const panner = context.createStereoPanner();

		source.buffer = buffer;
		gain.gain.value = Math.max(0, Math.min(1, Number(event.gain ?? 1)));
		panner.pan.value = Math.max(-1, Math.min(1, Number(event.pan ?? 0)));
		source.connect(gain).connect(panner).connect(this.master);
		this.sources.add(source);
		source.addEventListener('ended', () => this.sources.delete(source), {once: true});
		source.start();
	}

	open()
	{
		if(this.context) return this.context;

		this.context = new AudioContext;
		this.master = this.context.createGain();
		this.applyVolume();
		this.master.connect(this.context.destination);

		return this.context;
	}

	async unlock()
	{
		const context = this.open();

		if(context.state === 'suspended') await context.resume();

		return context;
	}

	close()
	{
		for(const source of this.sources) try { source.stop(); } catch {}

		this.sources.clear();
		this.buffers.clear();
		this.master?.disconnect();
		this.context?.close();
		this.master = this.context = null;
	}
}

/** Let the browser decode and stream the active Ogg track independently of PHP frames. */
export class WadMusic
{

	constructor(muted = false, volume = 1)
	{
		this.element = new Audio;
		this.element.loop = true;
		this.element.preload = 'auto';
		this.element.muted = Boolean(muted);
		this.element.volume = Math.max(0, Math.min(1, Number(volume)));
		this.key = null;
		this.url = null;
		this.closed = false;
	}

	setMuted(muted) { this.element.muted = Boolean(muted); }

	setVolume(volume) { this.element.volume = Math.max(0, Math.min(1, Number(volume))); }

	async play(value)
	{
		if(this.closed) return;

		const event = typeof value === 'string' ? JSON.parse(value) : value;

		if(event.stop)
		{
			this.stop();

			return;
		}

		if(event.key !== this.key || !this.url)
		{
			if(!event.ogg) return;

			const binary = atob(event.ogg);
			const bytes = new Uint8Array(binary.length);

			for(let index = 0; index < binary.length; index++) bytes[index] = binary.charCodeAt(index);

			const url = URL.createObjectURL(new Blob([bytes], {type: 'audio/ogg'}));
			this.stop();
			this.key = event.key;
			this.url = url;
			this.element.src = url;
		}

		await this.unlock();
	}

	async unlock()
	{
		if(!this.closed && this.url && this.element.paused) await this.element.play();
	}

	stop()
	{
		this.element.pause();
		this.element.removeAttribute('src');
		this.element.load();

		if(this.url) URL.revokeObjectURL(this.url);

		this.key = this.url = null;
	}

	close()
	{
		this.stop();
		this.closed = true;
	}
}

/** Pointer-lock input, shared mute/fullscreen controls, and accessible live status. */
export function activate({module, canvas, services, session})
{
	const gl = canvas.getContext('webgl2', {antialias: false});

	if(!gl) throw Error('Doom-Phonics requires WebGL2');

	services.cacheProgramValidation(gl, canvas);
	services.cachePixelStoreQueries(gl, canvas);

	const frame = canvas.closest('#game-frame') ?? canvas.parentElement;
	const abort = new AbortController();
	const quick = new services.QuickControls(frame, frame);

	let fullscreenBusy = false, fullscreenError = '', menuOpen = true;
	let cheats = '', pause = 0, pointerX = 0, pointerY = 0;

	const logicalWidth = canvas.width, logicalHeight = canvas.height;
	const aspect = logicalWidth / logicalHeight;
	const maxViewport = gl.getParameter(gl.MAX_VIEWPORT_DIMS);

	const maximumWidth = Math.min(
		gl.getParameter(gl.MAX_TEXTURE_SIZE)
		, gl.getParameter(gl.MAX_RENDERBUFFER_SIZE)
		, maxViewport[0]
	);

	const maximumHeight = Math.min(
		gl.getParameter(gl.MAX_TEXTURE_SIZE)
		, gl.getParameter(gl.MAX_RENDERBUFFER_SIZE)
		, maxViewport[1]
	);

	let viewport = JSON.stringify([logicalWidth, logicalHeight, 1, logicalWidth, logicalHeight]);
	let measuredDpr = 0;

	/** Keep CSS layout stable while SDL owns the physical drawing-buffer attributes. */
	const measureViewport = () => {
		const dpr = window.devicePixelRatio || 1;
		const fullscreen = document.fullscreenElement === frame;
		const availableWidth = Math.max(1, frame.clientWidth || logicalWidth);
		const availableHeight = Math.max(1, frame.clientHeight || logicalHeight);
		const cssWidth = fullscreen
			? Math.min(availableWidth, availableHeight * aspect)
			: Math.min(logicalWidth, availableWidth);

		const cssHeight = cssWidth / aspect;
		let width = Math.max(1, Math.round(cssWidth * dpr));
		let height = Math.max(1, Math.round(cssHeight * dpr));
		const limit = Math.min(1, maximumWidth / width, maximumHeight / height);

		if(limit < 1)
		{
			width = Math.max(1, Math.floor(width * limit));
			height = Math.max(1, Math.floor(height * limit));
		}

		if(canvas.style.width !== `${cssWidth}px`) canvas.style.width = `${cssWidth}px`;

		if(canvas.style.height !== `${cssHeight}px`) canvas.style.height = `${cssHeight}px`;

		viewport = JSON.stringify([width, height, dpr, cssWidth, cssHeight]);
		measuredDpr = dpr;
	};

	const viewportObserver = new ResizeObserver(measureViewport);
	viewportObserver.observe(frame);
	window.addEventListener('resize', measureViewport, {signal: abort.signal});
	window.visualViewport?.addEventListener('resize', measureViewport, {signal: abort.signal});
	measureViewport();

	module.doomViewport = () => {
		if(measuredDpr !== (window.devicePixelRatio || 1)) measureViewport();

		return viewport;
	};

	const optionsKey = 'doom-phonics.options.v1';
	const storage = new services.SafeStorage;
	const options = {
		version: 1
		, muted: false
		, effectsVolume: 1
		, musicVolume: 1
		, renderResolution: 'native'
		, pitchMode: 'perspective'
		, reticle: true
		, bloodSplatter: 1
	};

	let optionsSessionOnly = false, migrateOptions = false;

	{
		let saved = null;
		const raw = storage.get(optionsKey);

		try { saved = raw ? JSON.parse(raw) : null; } catch {}

		if(saved?.version === 1)
		{
			if(typeof saved.muted === 'boolean') options.muted = saved.muted;

			for(const name of ['effectsVolume', 'musicVolume'])
			if(typeof saved[name] === 'number' && Number.isFinite(saved[name]) && saved[name] >= 0 && saved[name] <= 1)
			options[name] = saved[name];

			if(['native', 'classic'].includes(saved.renderResolution))
			options.renderResolution = saved.renderResolution;

			if(['perspective', 'classic'].includes(saved.pitchMode)) options.pitchMode = saved.pitchMode;

			if(typeof saved.reticle === 'boolean') options.reticle = saved.reticle;

			if(typeof saved.bloodSplatter === 'number' && Number.isFinite(saved.bloodSplatter)
				&& saved.bloodSplatter >= 0 && saved.bloodSplatter <= 2
			) options.bloodSplatter = saved.bloodSplatter;
		}
		else
		{
			const legacyMuted = storage.get('phonics-muted');

			if(legacyMuted === 'true' || legacyMuted === 'false')
			{
				options.muted = legacyMuted === 'true';
				migrateOptions = true;
			}
		}
}

	optionsSessionOnly = storage.sessionOnly;

	const audio = new services.SdlAudio(module, options.muted);
	const wadAudio = new WadAudio(options.muted, options.effectsVolume);
	const wadMusic = new WadMusic(options.muted, options.musicVolume);

	const unlockAudio = () => {
		audio.gain?.context?.resume().catch(() => {});

		wadAudio.unlock().catch(() => {});

		wadMusic.unlock().catch(() => {});
	};

	window.addEventListener('keydown', unlockAudio, {signal: abort.signal});
	window.addEventListener('pointerdown', unlockAudio, {signal: abort.signal});

	module.doomSound = value => { wadAudio.play(value).catch(() => {}); };

	module.doomMusic = value => { wadMusic.play(value).catch(() => {}); };

	const infoBack = document.createElement('button');
	infoBack.type = 'button';
	infoBack.className = 'doom-phonics-info-back';
	infoBack.setAttribute('aria-label', 'Back to title screen');
	infoBack.hidden = true;
	frame.append(infoBack);

	const readout = document.createElement('output');
	readout.className = 'doom-phonics-readout';
	readout.setAttribute('aria-live', 'polite');
	readout.textContent = 'Click the arena to capture the mouse';
	frame.append(readout);

	const style = document.createElement('style');
	style.textContent = `.doom-phonics-readout{position:absolute;width:1px;height:1px;padding:0;margin:-1px;
		overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}.doom-phonics-pointer{cursor:none}
		button.doom-phonics-info-back{position:absolute;z-index:4;padding:0;border:0;border-radius:0;background:transparent;
		color:transparent;box-shadow:none;cursor:pointer}button.doom-phonics-info-back:is(:hover,:focus,:active){
		background:transparent;color:transparent;box-shadow:none}button.doom-phonics-info-back:hover,
		button.doom-phonics-info-back:focus-visible{outline:2px solid #d8bc72;outline-offset:-2px}
		.doom-phonics-info-back[hidden]{display:none}`;

	document.head.append(style);

	const updateControls = () => {
		quick.setMuted(options.muted);

		quick.setFullscreen(
			document.fullscreenElement === frame
			, Boolean(document.fullscreenEnabled && frame.requestFullscreen)
			, fullscreenBusy
			, fullscreenError
		);
	};

	const persistentOptions = () => ({
		version: 1
		, muted: options.muted
		, effectsVolume: options.effectsVolume
		, musicVolume: options.musicVolume
		, renderResolution: options.renderResolution
		, pitchMode: options.pitchMode
		, reticle: options.reticle
		, bloodSplatter: options.bloodSplatter
	});

	const saveOptions = () => {
		storage.set(optionsKey, JSON.stringify(persistentOptions()));
		optionsSessionOnly = storage.sessionOnly;
	};

	if(migrateOptions) saveOptions();

	const optionState = () => ({
		...persistentOptions()
		, fullscreen: document.fullscreenElement === frame
		, fullscreenAvailable: Boolean(document.fullscreenEnabled && frame.requestFullscreen)
		, fullscreenBusy
		, fullscreenError
		, sessionOnly: optionsSessionOnly
	});

	const applyAudioOptions = () => {
		audio.setMuted(options.muted);
		wadAudio.setMuted(options.muted);
		wadAudio.setVolume(options.effectsVolume);
		wadMusic.setMuted(options.muted);
		wadMusic.setVolume(options.musicVolume);
	};

	const requestFullscreen = async enabled => {
		if(fullscreenBusy || !document.fullscreenEnabled || !frame.requestFullscreen
			|| Boolean(document.fullscreenElement === frame) === enabled
		) return;

		fullscreenBusy = true;
		fullscreenError = '';
		updateControls();

		try
		{
			if(enabled) await frame.requestFullscreen();
			else await document.exitFullscreen();
		}
		catch
		{
			fullscreenError = 'Fullscreen could not be changed. Try the corner fullscreen button.';
		}
		finally
		{
			fullscreenBusy = false;
			updateControls();
			canvas.focus({preventScroll: true});
		}
	};

	const applyOptions = patch => {
		let persist = false;

		if(typeof patch?.muted === 'boolean')
		{
			options.muted = patch.muted;
			persist = true;
		}

		for(const name of ['effectsVolume', 'musicVolume'])
		if(typeof patch?.[name] === 'number' && Number.isFinite(patch[name]) && patch[name] >= 0 && patch[name] <= 1)
		{
			options[name] = patch[name];
			persist = true;
		}

		if(['native', 'classic'].includes(patch?.renderResolution))
		{
			options.renderResolution = patch.renderResolution;
			persist = true;
		}

		if(['perspective', 'classic'].includes(patch?.pitchMode))
		{
			options.pitchMode = patch.pitchMode;
			persist = true;
		}

		if(typeof patch?.reticle === 'boolean')
		{
			options.reticle = patch.reticle;
			persist = true;
		}

		if(typeof patch?.bloodSplatter === 'number' && Number.isFinite(patch.bloodSplatter)
			&& patch.bloodSplatter >= 0 && patch.bloodSplatter <= 2
		) {
			options.bloodSplatter = patch.bloodSplatter;
			persist = true;
		}

		applyAudioOptions();

		if(persist) saveOptions();

		if(typeof patch?.fullscreen === 'boolean') requestFullscreen(patch.fullscreen);

		updateControls();

		return optionState();
	};

	module.doomReadOptions = () => JSON.stringify(optionState());

	module.doomWriteOptions = value => {
		let patch = value;

		try { if(typeof value === 'string') patch = JSON.parse(value); } catch { patch = null; }

		return JSON.stringify(applyOptions(patch && typeof patch === 'object' ? patch : {}));
	};

	quick.muteButton.addEventListener('click', () => {
		applyOptions({muted: !options.muted});
		canvas.focus({preventScroll: true});
	}, {signal: abort.signal});

	quick.fullscreenButton.addEventListener('click', () => {
		requestFullscreen(document.fullscreenElement !== frame);
	}, {signal: abort.signal});

	document.addEventListener('fullscreenchange', () => {
		measureViewport();
		updateControls();
	}, {signal: abort.signal});

	const actions = [];
	let phpInfoState = null, networkInstalled = false, onlineRequest = 0, networkReady = 0, contentRequest = 0;

	const enqueue = value => {
		if(!actions.includes(value)) actions.push(value);
	};

	const installNetwork = () => {
		if(networkInstalled || !session.network) return;

		networkInstalled = true;

		session.network.addEventListener('resourcesmounted', ({detail}) => {
			enqueue(detail.role === 'local' ? 'content-load' : 'network-load');
		}, {signal: abort.signal});

		session.network.addEventListener('start', () => {
			session.lobby?.close();
			enqueue('network-start');
		}, {signal: abort.signal});

		session.network.addEventListener('disconnect', ({detail}) => {
			// An abandoned load/start must never reopen a match after its transport closed.
			for(let index = actions.length - 1; index >= 0; index--)
			if(actions[index].startsWith('network-')) actions.splice(index, 1);

			session.lobby?.close();
			enqueue(detail.local ? 'network-leave' : 'network-end');
		}, {signal: abort.signal});

		session.network.addEventListener('recovery', ({detail}) => {
			readout.textContent = detail.state === 'playing' ? 'Connected' : 'Reconnecting…';
		}, {signal: abort.signal});

		session.network.addEventListener('error', ({detail}) => {
			readout.textContent = detail?.message ?? 'Online match failed';
		}, {signal: abort.signal});
	};

	const infoBackLayout = {};

	const positionInfoBack = () => {
		const hidden = !phpInfoState?.open || !phpInfoState?.backHint;

		if(infoBack.hidden !== hidden) infoBack.hidden = hidden;

		if(infoBack.hidden) return;

		const [x, y, width, height] = phpInfoState.backHint;
		const canvasBox = canvas.getBoundingClientRect();
		const frameBox = frame.getBoundingClientRect();
		const layout = {
			left: `${canvasBox.left - frameBox.left + x * canvasBox.width / canvas.width}px`
			, top: `${canvasBox.top - frameBox.top + y * canvasBox.height / canvas.height}px`
			, width: `${width * canvasBox.width / canvas.width}px`
			, height: `${height * canvasBox.height / canvas.height}px`
		};

		for(const [property, value] of Object.entries(layout))
		if(infoBackLayout[property] !== value)
		{
			infoBack.style[property] = value;
			infoBackLayout[property] = value;
		}
	};

	const statusText = state => {
		if(phpInfoState?.open) return 'PHP information. Back returns to the title screen.';

		if(menuOpen) return `${state.menu?.screen ?? 'menu'}: ${state.menu?.selected ?? ''}`;

		return [
			state.map ?? ''
			, `${state.health ?? 0}%`
			, `${state.armor ?? 0} armor`
			, state.weapon ?? ''
		].join('  ');
	};

	document.addEventListener('fullscreenchange', positionInfoBack, {signal: abort.signal});
	window.addEventListener('resize', positionInfoBack, {signal: abort.signal});

	infoBack.addEventListener('click', () => {
		enqueue('close-info');
		canvas.focus({preventScroll: true});
	}, {signal: abort.signal});

	canvas.addEventListener('pointerdown', () => {
		canvas.focus({preventScroll: true});
	}, {signal: abort.signal});

	canvas.addEventListener('click', () => {
		canvas.focus({preventScroll: true});

		if(!menuOpen && document.pointerLockElement !== canvas) canvas.requestPointerLock?.();
	}, {signal: abort.signal});

	canvas.addEventListener('contextmenu', event => event.preventDefault(), {signal: abort.signal});

	canvas.addEventListener('wheel', event => {
		if(menuOpen || event.deltaY === 0) return;

		event.preventDefault();
	}, {signal: abort.signal, passive: false});

	document.addEventListener('pointerlockchange', () => {
		const locked = document.pointerLockElement === canvas;
		canvas.classList.toggle('doom-phonics-pointer', locked);

		if(!locked) pointerX = pointerY = 0;
	}, {signal: abort.signal});

	// The browser SDL build does not currently forward relative mouse motion to
	// PHP. Carry only the raw physical displacement across the bridge; PHP feeds
	// it through the same editable mouse-axis bindings as native SDL input.
	document.addEventListener('mousemove', event => {
		if(document.pointerLockElement !== canvas) return;

		pointerX += event.movementX;
		pointerY += event.movementY;
	}, {signal: abort.signal});

	window.addEventListener('keydown', event => {
		if(event.repeat || event.ctrlKey || event.metaKey || event.altKey) return;

		if(menuOpen && event.key.toLowerCase() === 'p')
		{
			pause++;

			return;
		}

		if(menuOpen) return;

		if(!/^[a-z0-9]$/i.test(event.key)) return;

		cheats = (cheats + event.key.toLowerCase()).slice(-32);
	}, {signal: abort.signal});

	const controlsKey = 'doom-phonics.controls.v1';

	module.doomReadControls = () => {
		return storage.get(controlsKey) ?? '';
	};

	module.doomWriteControls = value => {
		return storage.set(controlsKey, value);
	};

	module.doomInput = () => {
		const value = JSON.stringify({cheats, pause});
		cheats = '';
		pause = 0;

		return value;
	};

	module.doomPointerInput = () => {
		const value = JSON.stringify([pointerX, pointerY]);
		pointerX = pointerY = 0;

		return value;
	};

	module.doomAction = () => {
		return actions.shift() ?? '';
	};

	module.doomStatus = value => {
		const state = JSON.parse(value);
		// Diagnostics belong to the session; publishing them as an attribute mutates
		// the DOM on every sample, even when the accessible UI has not changed.
		session.doomState = state;
		installNetwork();
		menuOpen = Boolean(state.menu?.open);
		phpInfoState = state.menu?.phpInfo;

		if((state.menu?.onlineRequest ?? 0) > onlineRequest)
		{
			onlineRequest = state.menu.onlineRequest;
			session.lobby?.open().catch(error => { readout.textContent = error.message; });
		}

		if((state.menu?.contentRequest ?? 0) > contentRequest)
		{
			contentRequest = state.menu.contentRequest;
			session.launcher?.openLocal();
		}

		if((state.menu?.networkReady ?? 0) > networkReady)
		{
			networkReady = state.menu.networkReady;

			try
			{
				session.network?.gameReady();
			}
			catch(error)
			{
				readout.textContent = error.message;
			}
		}

		positionInfoBack();

		if(menuOpen)
		{
			cheats = '';
			pointerX = pointerY = 0;

			if(document.pointerLockElement === canvas) document.exitPointerLock?.();
		}

		const text = statusText(state);

		if(readout.textContent !== text) readout.textContent = text;
	};

	updateControls();

	return () => {
		abort.abort();
		viewportObserver.disconnect();

		if(document.pointerLockElement === canvas) document.exitPointerLock?.();

		audio.close();
		wadAudio.close();
		wadMusic.close();
		quick.close();

		infoBack.remove();
		readout.remove();
		style.remove();

		delete module.doomInput;
		delete module.doomPointerInput;
		delete module.doomReadControls;
		delete module.doomWriteControls;
		delete module.doomReadOptions;
		delete module.doomWriteOptions;
		delete module.doomSound;
		delete module.doomMusic;
		delete module.doomAction;
		delete module.doomStatus;
		delete module.doomViewport;
		delete session.doomState;
	};
}
