const MIX = Object.freeze({
	effects: .5
	, music: .75
});

/** Stream a package-local music track without decoding the whole song into Web Audio memory. */
class BackgroundTrack
{
	constructor(module, assets, packageUrl, {muted = false, volume = MIX.music} = {})
	{
		this.module = module;
		this.assets = assets;
		this.packageUrl = packageUrl;
		this.muted = Boolean(muted);
		this.element = typeof Audio === 'undefined' ? null : new Audio;
		this.pending = null;
		this.url = null;
		this.closed = false;
		this.volume = Math.max(0, Math.min(1, Number(volume)));

		if(this.element)
		{
			this.element.loop = true;
			this.element.preload = 'auto';
			this.element.muted = this.muted;
			this.element.volume = this.volume;
		}
	}

	async load()
	{
		if(this.closed || !this.element) return false;

		this.pending ??= (async() => {
			try
			{
				const source = new URL('assets/funktify.mp3', this.packageUrl).href;
				const bytes = this.module.FS.readFile(this.assets.path(source));

				if(this.closed) return false;

				this.url = URL.createObjectURL(new Blob([bytes], {type: 'audio/mpeg'}));
				this.element.src = this.url;
				this.element.load();

				return true;
			}
			catch
			{
				return false;
			}
		})();

		return this.pending;
	}

	async unlock()
	{
		if(this.closed || this.muted || !this.element || !await this.load()) return false;

		try
		{
			if(this.element.paused) await this.element.play();

			return !this.element.paused;
		}
		catch
		{
			return false;
		}
	}

	setMuted(muted)
	{
		this.muted = Boolean(muted);

		if(!this.element) return;

		this.element.muted = this.muted;

		if(!this.muted) void this.unlock();
	}

	get state()
	{
		return {
			loaded: Boolean(this.url)
			, playing: Boolean(this.element && !this.element.paused)
			, loop: Boolean(this.element?.loop)
			, muted: this.muted
			, volume: this.element?.volume ?? 0
			, baseVolume: this.volume
		};
	}

	close()
	{
		if(this.closed) return;

		this.closed = true;
		this.element?.pause();
		this.element?.removeAttribute('src');
		this.element?.load();

		if(this.url) URL.revokeObjectURL(this.url);

		this.url = null;
		this.pending = null;
		this.element = null;
	}
}

/** Draw the mixed bitmap-font and high-density input hint after the reduced world buffer has been enlarged. */
class HintOverlay
{
	constructor(module, assets, screen)
	{
		this.module = module;
		this.assets = assets;
		this.screen = screen;
		this.scope = assets.scope();
		this.canvas = document.createElement('canvas');
		this.canvas.className = 'phlappy-phant-hints';
		this.canvas.setAttribute('aria-hidden', 'true');
		this.canvas.hidden = true;
		this.state = null;
		this.key = '';
		this.urls = [];
		this.buttons = new Map;
		this.glyphs = new Map;
		this.screen.append(this.canvas);
		this.ready = this.load().then(() => this.draw()).catch(() => {});
	}

	properties(entries = [])
	{
		return Object.fromEntries(entries.map(entry => [entry.name, entry.value]));
	}

	async image(url)
	{
		const bytes = this.module.FS.readFile(this.assets.path(url));
		const objectUrl = URL.createObjectURL(new Blob([bytes], {type: 'image/png'}));
		const image = new Image;
		this.urls.push(objectUrl);
		image.src = objectUrl;
		await image.decode();

		return image;
	}

	async load()
	{
		const core = this.assets.packages.find(record => record.data?.name === '@seanmorris/phonics');

		if(!core) throw new Error('Phonics core package is unavailable');

		const references = Object.fromEntries(['buttons.tsj', 'buttons.png', 'font.tsj', 'font.png'].map(name => [
			name, new URL('resources/' + name, core.url).href
		]));
		await Promise.all(Object.values(references).map(reference => this.scope.load(reference)));
		const read = name => this.module.FS.readFile(this.assets.path(references[name]));
		const decode = name => JSON.parse(new TextDecoder().decode(read(name)));
		this.buttonSet = decode('buttons.tsj');
		this.fontSet = decode('font.tsj');
		[this.buttonImage, this.fontImage] = await Promise.all([
			this.image(references['buttons.png']), this.image(references['font.png'])
		]);

		for(const tile of this.buttonSet.tiles ?? [])
		{
			const properties = this.properties(tile.properties);
			const character = properties['glyph.char'], theme = properties['glyph.theme'];

			if(character && theme) this.buttons.set(`${theme}:${character}`, {id: tile.id, ...properties});
		}

		for(const tile of this.fontSet.tiles ?? [])
		{
			const properties = this.properties(tile.properties);
			const character = properties['glyph.char'];

			if(character) this.glyphs.set(character, {id: tile.id, ...properties});
		}

		const fontProperties = this.properties(this.fontSet.properties);
		this.spaceAdvance = Number(fontProperties['font.spaceAdvance'] ?? this.fontSet.tilewidth);
		this.fontFace = this.tint(this.fontImage, [.95, .89, .75]);
		this.fontShadow = this.tint(this.fontImage, [.21, .20, .33]);
	}

	tint(image, colour)
	{
		const canvas = document.createElement('canvas');
		canvas.width = image.naturalWidth;
		canvas.height = image.naturalHeight;
		const context = canvas.getContext('2d');
		context.drawImage(image, 0, 0);
		const pixels = context.getImageData(0, 0, canvas.width, canvas.height);

		// The font sheet has a light face and a dark inset. Multiply both by the requested tint just as the sprite
		// shader does; replacing every non-transparent pixel with one flat colour destroys the letter forms.
		for(let offset = 0; offset < pixels.data.length; offset += 4)
		{
			pixels.data[offset] = Math.round(pixels.data[offset] * colour[0]);
			pixels.data[offset + 1] = Math.round(pixels.data[offset + 1] * colour[1]);
			pixels.data[offset + 2] = Math.round(pixels.data[offset + 2] * colour[2]);
		}

		context.putImageData(pixels, 0, 0);

		return canvas;
	}

	button(character, theme)
	{
		return this.buttons.get(`${theme}:${character}`)
			?? this.buttons.get(`generic:${character}`)
			?? this.buttons.get(`keyboard:${character}`);
	}

	layout(hint, theme)
	{
		const items = [];
		const appendText = text => {
			for(const character of text)
			{
				if(character === ' ')
				{
					items.push({advance: this.spaceAdvance});
					continue;
				}

				const glyph = this.glyphs.get(character.toUpperCase()) ?? this.glyphs.get('?');
				items.push({font: glyph, advance: Number(glyph['glyph.advance'])});
			}
		};
		const appendButton = character => {
			const button = this.button(character, theme);
			items.push({button, advance: Number(button['glyph.advance'])});
		};

		appendButton('✚');
		appendText(' MOVE  ');
		appendButton('⓿');
		appendText(' SELECT');

		if(hint.back)
		{
			appendText('  ');
			appendButton('❶');
			appendText(' BACK');
		}

		return items;
	}

	drawFont(context, atlas, glyph, x, y, scale)
	{
		const id = Number(glyph.id);
		const sourceX = id % this.fontSet.columns * this.fontSet.tilewidth;
		const sourceY = Math.floor(id / this.fontSet.columns) * this.fontSet.tileheight;
		const width = Number(glyph['glyph.width'] ?? this.fontSet.tilewidth);
		const height = Number(glyph['glyph.height'] ?? this.fontSet.tileheight);
		const offsetY = Number(glyph['glyph.offsetY'] ?? 0);
		context.imageSmoothingEnabled = false;
		context.drawImage(atlas, sourceX, sourceY, width, height, x, y + offsetY * scale, width * scale, height * scale);
	}

	drawButton(context, glyph, x, y, scale)
	{
		const id = Number(glyph.id);
		const sourceX = id % this.buttonSet.columns * this.buttonSet.tilewidth;
		const sourceY = Math.floor(id / this.buttonSet.columns) * this.buttonSet.tileheight;
		const width = Number(glyph['glyph.width'] ?? this.buttonSet.tilewidth);
		const height = Number(glyph['glyph.height'] ?? this.buttonSet.tileheight);
		const ratio = Number(glyph['glyph.pixelRatio'] ?? 1);
		const offsetY = Number(glyph['glyph.offsetY'] ?? 0);
		context.imageSmoothingEnabled = glyph['glyph.smooth'] !== false;
		context.drawImage(
			this.buttonImage, sourceX, sourceY, width, height, x, y + offsetY * scale
			, width / ratio * scale, height / ratio * scale
		);
	}

	sync(state)
	{
		this.state = state;
		this.draw();
	}

	draw()
	{
		const hint = this.state?.hint;

		if(!hint || !this.buttonImage || !this.fontImage)
		{
			this.canvas.hidden = true;
			this.key = '';
			return;
		}

		const box = this.screen.getBoundingClientRect();
		const theme = String(this.state.inputTheme || 'keyboard');
		const dpr = Math.min(2, globalThis.devicePixelRatio || 1);
		const key = JSON.stringify([
			hint, theme, this.state.width, this.state.height
			, Math.round(box.width * 100), Math.round(box.height * 100), dpr
		]);

		// Status arrives every rendered frame; the hint changes only on navigation, input-theme changes, or resize.
		if(this.key === key) return;

		this.key = key;
		const scale = Number(hint.scale) * Math.min(box.width / this.state.width, box.height / this.state.height);
		const items = this.layout(hint, theme);
		const logicalWidth = items.reduce((width, item) => width + item.advance, 0);
		const padding = 2 * scale;
		const width = logicalWidth * scale + 2 * padding;
		const height = 20 * scale;
		const top = (Number(hint.y) - 2 * Number(hint.scale)) * box.height / this.state.height;

		this.canvas.hidden = false;
		this.canvas.style.width = `${width}px`;
		this.canvas.style.height = `${height}px`;
		this.canvas.style.left = `${(box.width - width) / 2}px`;
		this.canvas.style.top = `${top}px`;

		this.canvas.width = Math.max(1, Math.ceil(width * dpr));
		this.canvas.height = Math.max(1, Math.ceil(height * dpr));
		const context = this.canvas.getContext('2d');
		context.setTransform(dpr, 0, 0, dpr, 0, 0);
		context.clearRect(0, 0, width, height);

		let x = padding, y = 2 * scale;

		// The pixel font keeps its one-pixel ink shadow. The already shaded control art draws once at source density.
		for(const item of items)
		{
			if(item.font) this.drawFont(context, this.fontShadow, item.font, x + scale, y + scale, scale);
			x += item.advance * scale;
		}

		x = padding;

		for(const item of items)
		{
			if(item.font) this.drawFont(context, this.fontFace, item.font, x, y, scale);
			else if(item.button) this.drawButton(context, item.button, x, y, scale);
			x += item.advance * scale;
		}
	}

	close()
	{
		this.scope.close();
		this.canvas.remove();

		for(const url of this.urls) URL.revokeObjectURL(url);

		this.urls = [];
	}
}

/** Browser shell for Phlappy Phant: taps, music, sounds, the local best score, and accessible menus in any viewport. */
export async function activate({module, canvas, assets, packageUrl, services, session, onClose})
{
	const shell = new services.GameShell({module, canvas, session, title: 'Phlappy Phant'});
	shell.context({antialias: false});
	const {signal} = shell;

	const controlsKey = 'phlappy-phant.controls.v1';
	const profileKey = 'phlappy-phant.profile.v1';
	const optionsKey = 'phlappy-phant.options.v1';
	const storage = new services.SafeStorage;
	const ui = document.createElement('section');
	const screen = document.createElement('div');
	const targets = document.createElement('div');
	const status = document.createElement('p');
	const style = document.createElement('style');
	ui.className = 'phlappy-phant-ui';
	ui.setAttribute('aria-label', 'Phlappy Phant');
	screen.className = 'phlappy-phant-screen';
	targets.className = 'phlappy-phant-targets';
	status.className = 'phlappy-phant-sr';
	status.setAttribute('aria-live', 'polite');
	style.textContent = `
		.phlappy-phant-ui{position:relative;display:flex;align-items:center;justify-content:center;width:100%;height:100%;
			max-width:none;margin:auto;background:#4866c5;color:#363254}
		.phlappy-phant-screen{position:relative;width:100%;height:100%;isolation:isolate;overflow:hidden;background:#4866c5}
		.phlappy-phant-screen>canvas{position:absolute;inset:0;display:block;width:100%;height:100%;max-width:none;
			max-height:none;margin:0;border:0;border-radius:0;image-rendering:pixelated;touch-action:none;cursor:pointer}
		.phlappy-phant-screen>canvas.phlappy-phant-hints{inset:auto;display:block;max-width:none;max-height:none;
			margin:0;border:0;image-rendering:auto;pointer-events:none;cursor:default;z-index:3}
		.phlappy-phant-screen>canvas.phlappy-phant-hints[hidden]{display:none}
		.phlappy-phant-targets{position:absolute;inset:0;pointer-events:none}
		.phlappy-phant-targets button{position:absolute;pointer-events:auto;padding:0;border:0;border-radius:0;
			background:transparent;color:transparent;box-shadow:none;touch-action:manipulation}
		.phlappy-phant-targets button:is(:hover,:focus,:active){background:transparent;color:transparent;box-shadow:none}
		.phlappy-phant-targets button:focus-visible{outline:2px solid #f1e4bf;outline-offset:-2px}
		.phlappy-phant-sr{position:absolute!important;width:1px!important;height:1px!important;padding:0!important;
			margin:-1px!important;overflow:hidden!important;clip-path:inset(50%)!important;white-space:nowrap!important;border:0!important}
		.phlappy-phant-screen .quick-controls{right:6px;top:auto;bottom:4px;z-index:4;padding:0;opacity:.8}
		.phlappy-phant-screen .quick-controls button{width:30px;height:30px;padding:4px}
		.phlappy-phant-ui:fullscreen{display:flex;align-items:center;justify-content:center;width:100%;height:100%;max-width:none}
		@media(pointer:coarse){.phlappy-phant-screen .quick-controls button{width:44px;height:44px}}
	`;

	shell.mount(ui, {screen});
	ui.append(style, screen, status);
	screen.append(targets);
	const quick = new services.QuickControls(screen, ui);
	const hints = new HintOverlay(module, assets, screen);
	let state = null, taps = 0;
	const sounded = {flap: 0, score: 0, bonk: 0, tas: 0};

	const safeJson = (value, fallback) => {
		try { return JSON.parse(value); } catch { return fallback; }
	};

	const rawProfile = safeJson(storage.get(profileKey) ?? '{}', {});
	const profile = {version: 1, best: Number.isSafeInteger(rawProfile?.best) && rawProfile.best >= 0 ? rawProfile.best : 0};

	const saveProfile = () => storage.set(profileKey, JSON.stringify(profile));

	// The game fills whatever frame it is given, but its artwork already scales in whole pixel blocks. Render one
	// physical pixel per block and let the pixelated canvas enlarge it: drawing every repeated device pixel through
	// the sprite shader wastes most of a core on large and high-DPI frames without adding any image information.
	const view = shell.measure({
		observe: screen
		, cssSize: false
		, dprCap: 2
		, initial: '[640,960,1,false]'
		, format: (width, height, dpr, box, coarse) => {
			const block = Math.max(1, Math.floor(height / 320));
			const renderWidth = Math.ceil(width / block);
			const renderHeight = Math.ceil(height / block);
			canvas.style.width = `${renderWidth * block / dpr}px`;
			canvas.style.height = `${renderHeight * block / dpr}px`;
			canvas.style.top = 'auto';
			canvas.style.bottom = '0';

			return JSON.stringify([renderWidth, renderHeight, dpr, coarse]);
		}
	});

	let samples = null, music = null;

	const mute = shell.mute({
		key: optionsKey
		, parse: value => {
			const options = safeJson(value, {});

			return options?.version === 1 && options.muted === true;
		}
		, serialize: muted => JSON.stringify({version: 1, muted})
		, quick
		, onChange: muted => {
			samples?.setMuted(muted);
			music?.setMuted(muted);

			if(samples && !muted) void unlock();
		}
	});

	samples = new services.SamplePlayer(module, assets, packageUrl, {muted: mute.muted});
	music = new BackgroundTrack(module, assets, packageUrl, {muted: mute.muted});

	async function unlock()
	{
		const [effectsReady] = await Promise.all([samples.unlock(), music.unlock()]);

		if(!effectsReady) return;

		await Promise.all([
			samples.load('flap', 'assets/flap.wav')
			, samples.load('score', 'assets/ding.wav')
			, samples.load('bonk', 'assets/bonk.wav')
		]);
	}

	function play(name, gain)
	{
		void unlock().then(() => samples.play(name, {gain: gain * MIX.effects}));
	}

	shell.fullscreen({target: ui, quick, onChange: () => view.measure()});
	shell.suppressContextMenu();

	canvas.addEventListener('focus', () => {
		if(document.fullscreenElement === ui) return;

		globalThis.scrollTo({top: globalThis.scrollY + screen.getBoundingClientRect().top, behavior: 'auto'});
	}, {signal});

	canvas.addEventListener('keydown', () => { void unlock(); }, {signal});

	// Any press on the canvas is a flap while playing; menus keep their own accessible buttons.
	canvas.addEventListener('pointerdown', event => {
		canvas.focus({preventScroll: true});
		void unlock();

		if(state?.page !== 'play' || (event.pointerType !== 'touch' && event.button !== 0)) return;

		event.preventDefault();
		taps++;
	}, {signal});

	const focus = () => { canvas.focus({preventScroll: true}); void unlock(); };
	const action = shell.action('phantAction');
	const buttons = new services.RegionButtons(targets, {signal, focus, onPress: id => action.act(id)});

	shell.bridge('phantViewport', () => view.viewport());
	shell.bridge('phantTaps', () => taps);
	shell.bridge('phantStorageRead', () => storage.get(controlsKey) ?? '');
	shell.bridge('phantStorageWrite', value => storage.set(controlsKey, String(value)));
	shell.bridge('phantSeed', () => shell.seed31());
	shell.bridge('phantBest', () => profile.best);

	shell.bridge('phantSubmitScore', score => {
		profile.best = Math.max(profile.best, Math.max(0, Math.trunc(Number(score) || 0)));

		return saveProfile();
	});

	shell.status('phantStatus', {
		dataset: 'phpPhant'
		, handler: nextState => {
			const previousRun = state?.runId;
			state = nextState;

			if(nextState.runId !== previousRun) for(const name of Object.keys(sounded)) sounded[name] = 0;

			// The Konami code's acceptance rings the score bell.
			for(const [name, gain, sample] of [['flap', .5], ['score', .45], ['bonk', .7], ['tas', .8, 'score']])
			{
				const count = nextState.counts?.[name] ?? 0;

				// Catch up at most two missed events so a stalled tab does not replay a whole run.
				if(count - sounded[name] > 2) sounded[name] = count - 2;

				while(sounded[name] < count)
				{
					sounded[name]++;
					play(sample ?? name, gain);
				}
			}

			buttons.sync(nextState.width, nextState.height, nextState.regions);
			hints.sync(nextState);

			const summary = `${nextState.page}. Score ${nextState.score}. Best ${nextState.best}.${nextState.autopilot ? ' Tool-assisted.' : ''}`;

			if(status.textContent !== summary) status.textContent = summary;
		}
	});

	const debug = {
		get state() { return state; }
		, get profile() { return structuredClone(profile); }
		, get music() { return music.state; }
		, get mix() { return {effects: MIX.effects, music: MIX.music}; }
	};
	session.phlappyPhant = debug;
	Object.defineProperty(canvas, 'phpPhantDebug', {configurable: true, value: debug});

	shell.onClose(async() => {
		quick.close();
		hints.close();
		music.close();
		await samples.close();
		delete canvas.phpPhantDebug;
		delete session.phlappyPhant;
	});

	onClose(() => shell.close());
}
