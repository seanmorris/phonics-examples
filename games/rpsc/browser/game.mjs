const SOUNDS = Object.freeze({
	move: ['assets/move.wav', .55]
	, flip: ['assets/flip.wav', .6]
	, take: ['assets/take.wav', .75]
	, tie: ['assets/tie.wav', .5]
	, crown: ['assets/crown.wav', .55]
	, win: ['assets/win.wav', .6]
	, lose: ['assets/lose.wav', .55]
});

const LEVELS = Object.freeze(['easy', 'normal', 'hard']);
const SIDES = Object.freeze(['black', 'red', 'random']);

/**
 * The browser half of RPSC: the frame, storage, sounds, and the accessible buttons over the canvas.
 *
 * PHP owns the rules, the bot, and every pixel. This module only gives it what PHP cannot reach: the size of the
 * frame, a place to keep the unfinished game and the options, a random seed, and sample playback. It also mirrors
 * the regions PHP reports as real buttons, so the board works with a pointer, a keyboard, and a screen reader.
 * @param {object} host What the engine's host hands a game module.
 * @returns {Promise<void>} Resolves once the bridges are in place.
 */
export async function activate({module, canvas, assets, packageUrl, services, session, onClose})
{
	const shell = new services.GameShell({module, canvas, session, title: 'RPSC'});
	shell.context({antialias: false});
	const {signal} = shell;

	const gameKey = 'rpsc.game.v1';
	const optionsKey = 'rpsc.options.v1';
	const muteKey = 'rpsc.mute.v1';
	const storage = new services.SafeStorage;
	const ui = document.createElement('section');
	const screen = document.createElement('div');
	const targets = document.createElement('div');
	const status = document.createElement('p');
	const style = document.createElement('style');
	ui.className = 'rpsc-ui';
	ui.setAttribute('aria-label', 'Rock Paper Scissors Checkers');
	screen.className = 'rpsc-screen';
	targets.className = 'rpsc-targets';
	status.className = 'rpsc-sr';
	status.setAttribute('aria-live', 'polite');
	style.textContent = `
		.rpsc-ui{position:relative;display:flex;align-items:center;justify-content:center;width:100%;height:100%;
			max-width:none;margin:auto;background:#11131a;color:#f5ebd1}
		.rpsc-screen{position:relative;width:100%;height:100%;isolation:isolate;overflow:hidden;background:#11131a}
		.rpsc-screen>canvas{position:absolute;inset:0;display:block;width:100%;height:100%;max-width:none;
			max-height:none;margin:0;border:0;border-radius:0;image-rendering:pixelated;touch-action:none}
		.rpsc-targets{position:absolute;inset:0;pointer-events:none}
		.rpsc-targets button{position:absolute;pointer-events:auto;padding:0;border:0;border-radius:0;
			background:transparent;color:transparent;box-shadow:none;touch-action:manipulation;cursor:pointer}
		.rpsc-targets button:is(:hover,:focus,:active){background:transparent;color:transparent;box-shadow:none}
		.rpsc-targets button:focus-visible{outline:2px solid #ffdc80;outline-offset:-2px}
		.rpsc-sr{position:absolute!important;width:1px!important;height:1px!important;padding:0!important;
			margin:-1px!important;overflow:hidden!important;clip-path:inset(50%)!important;white-space:nowrap!important;border:0!important}
		.rpsc-screen .quick-controls{right:6px;top:4px;bottom:auto;z-index:4;padding:0;opacity:.8}
		.rpsc-screen .quick-controls button{width:30px;height:30px;padding:4px}
		.rpsc-ui:fullscreen{display:flex;align-items:center;justify-content:center;width:100%;height:100%;max-width:none}
		@media(pointer:coarse){.rpsc-screen .quick-controls button{width:44px;height:44px}}
	`;

	shell.mount(ui, {screen});
	ui.append(style, screen, status);
	screen.append(targets);
	const quick = new services.QuickControls(screen, ui);
	let state = null;
	const sounded = Object.fromEntries(Object.keys(SOUNDS).map(name => [name, 0]));

	const safeJson = (value, fallback) => {
		try { return JSON.parse(value); } catch { return fallback; }
	};

	const stored = safeJson(storage.get(optionsKey) ?? '{}', {});
	const options = {
		version: 1
		, level: LEVELS.includes(stored?.level) ? stored.level : 'normal'
		, side: SIDES.includes(stored?.side) ? stored.side : 'black'
		, effects: Number.isFinite(stored?.effects) ? Math.max(0, Math.min(100, Math.round(stored.effects))) : 60
	};

	const saveOptions = () => storage.set(optionsKey, JSON.stringify(options));

	// PHP draws at the frame's own pixel size, so the pixel art and the text are both set in whole device pixels.
	const view = shell.measure({
		observe: screen
		, cssSize: false
		, dprCap: 2
		, initial: '[720,960,1,false]'
		, format: (width, height, dpr, box, coarse) => JSON.stringify([Math.max(1, Math.round(width)), Math.max(1, Math.round(height)), dpr, coarse])
	});

	let samples = null;

	const mute = shell.mute({
		key: muteKey
		, parse: value => {
			const saved = safeJson(value, {});

			return saved?.version === 1 && saved.muted === true;
		}
		, serialize: muted => JSON.stringify({version: 1, muted})
		, quick
		, onChange: muted => {
			samples?.setMuted(muted);

			if(samples && !muted) void unlock();
		}
	});

	samples = new services.SamplePlayer(module, assets, packageUrl, {muted: mute.muted});

	/**
	 * Start audio after the first gesture and load the samples once.
	 * @returns {Promise<void>} Resolves when the samples can play, or at once when audio is unavailable.
	 */
	async function unlock()
	{
		if(!await samples.unlock()) return;

		await Promise.all(Object.entries(SOUNDS).map(([name, [path]]) => samples.load(name, path)));
	}

	const play = name => {
		void unlock().then(() => samples.play(name, {gain: SOUNDS[name][1] * options.effects / 100}));
	};

	shell.fullscreen({target: ui, quick, onChange: () => view.measure()});
	shell.suppressContextMenu();

	canvas.addEventListener('focus', () => {
		if(document.fullscreenElement === ui) return;

		// Centering honours the page's scroll padding, so a sticky header never covers the top of the board.
		screen.scrollIntoView({block: 'center', inline: 'nearest', behavior: 'auto'});
	}, {signal});

	canvas.addEventListener('keydown', () => { void unlock(); }, {signal});
	canvas.addEventListener('pointerdown', () => {
		canvas.focus({preventScroll: true});
		void unlock();
	}, {signal});

	const focus = () => { canvas.focus({preventScroll: true}); void unlock(); };
	const action = shell.action('rpscAction');
	const buttons = new services.RegionButtons(targets, {signal, focus, onPress: id => action.act(id)});

	shell.bridge('rpscViewport', () => view.viewport());
	shell.bridge('rpscSeed', () => shell.seed31());
	shell.bridge('rpscLoad', () => storage.get(gameKey) ?? '');
	shell.bridge('rpscSave', value => {
		value = String(value);

		return value === '' ? storage.remove(gameKey) : storage.set(gameKey, value);
	});
	shell.bridge('rpscOptionGet', name => String(options[String(name)] ?? ''));
	shell.bridge('rpscOptionSet', (name, value) => {
		name = String(name);
		value = String(value);

		if(name === 'level' && LEVELS.includes(value)) options.level = value;
		else if(name === 'side' && SIDES.includes(value)) options.side = value;
		else if(name === 'effects' && Number.isFinite(Number(value))) options.effects = Math.max(0, Math.min(100, Math.round(Number(value))));
		else return false;

		return saveOptions();
	});

	shell.status('rpscStatus', {
		dataset: 'rpsc'
		, handler: next => {
			const first = state === null;
			state = next;

			for(const name of Object.keys(SOUNDS))
			{
				const count = next.sounds?.[name] ?? 0;

				// A page that opens on a game already in progress has nothing to catch up on, and a stalled tab
				// replays at most two of what it missed.
				if(first || count - sounded[name] > 2) sounded[name] = first ? count : count - 2;

				while(sounded[name] < count)
				{
					sounded[name]++;
					play(name);
				}
			}

			buttons.sync(next.width, next.height, next.regions);

			if(status.textContent !== next.status) status.textContent = next.status;
		}
	});

	const debug = {
		get state() { return state; }
		, get options() { return structuredClone(options); }
		, get saved() { return storage.get(gameKey) ?? ''; }
	};
	session.rpsc = debug;
	Object.defineProperty(canvas, 'rpscDebug', {configurable: true, value: debug});

	shell.onClose(async() => {
		quick.close();
		await samples.close();
		delete canvas.rpscDebug;
		delete session.rpsc;
	});

	onClose(() => shell.close());
}
