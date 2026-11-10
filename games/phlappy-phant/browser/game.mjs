/** Browser shell for Phlappy Phant: taps, sounds, the local best score, and accessible menus in any viewport. */
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
			max-width:none;margin:auto;background:#cfe6f5;color:#363254}
		.phlappy-phant-screen{position:relative;width:100%;height:100%;isolation:isolate;overflow:hidden;background:#cfe6f5}
		.phlappy-phant-screen>canvas{position:absolute;inset:0;display:block;width:100%;height:100%;max-width:none;
			max-height:none;margin:0;border:0;border-radius:0;image-rendering:pixelated;touch-action:none;cursor:pointer}
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
	let state = null, taps = 0;
	const sounded = {flap: 0, score: 0, bonk: 0};

	const safeJson = (value, fallback) => {
		try { return JSON.parse(value); } catch { return fallback; }
	};

	const rawProfile = safeJson(storage.get(profileKey) ?? '{}', {});
	const profile = {version: 1, best: Number.isSafeInteger(rawProfile?.best) && rawProfile.best >= 0 ? rawProfile.best : 0};

	const saveProfile = () => storage.set(profileKey, JSON.stringify(profile));

	// The game fills whatever frame it is given: PHP reads the measured size every frame and scales its world to
	// the height in whole pixels, so wider screens simply see further ahead.
	const view = shell.measure({
		observe: screen
		, cssSize: true
		, dprCap: 2
		, initial: '[640,960,1,false]'
		, format: (width, height, dpr, box, coarse) => JSON.stringify([width, height, dpr, coarse])
	});

	let samples = null;

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

			if(samples && !muted) void unlock();
		}
	});

	samples = new services.SamplePlayer(module, assets, packageUrl, {muted: mute.muted});

	async function unlock()
	{
		if(!await samples.unlock()) return;

		await Promise.all([
			samples.load('flap', 'assets/flap.wav')
			, samples.load('score', 'assets/ding.wav')
			, samples.load('bonk', 'assets/bonk.wav')
		]);
	}

	function play(name, gain)
	{
		void unlock().then(() => samples.play(name, {gain}));
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

			for(const [name, gain] of [['flap', .5], ['score', .6], ['bonk', .7]])
			{
				const count = nextState.counts?.[name] ?? 0;

				// Catch up at most two missed events so a stalled tab does not replay a whole run.
				if(count - sounded[name] > 2) sounded[name] = count - 2;

				while(sounded[name] < count)
				{
					sounded[name]++;
					play(name, gain);
				}
			}

			buttons.sync(nextState.width, nextState.height, nextState.regions);

			const summary = `${nextState.page}. Score ${nextState.score}. Best ${nextState.best}.`;

			if(status.textContent !== summary) status.textContent = summary;
		}
	});

	session.phlappyPhant = {
		get state() { return state; }
		, get profile() { return structuredClone(profile); }
	};

	shell.onClose(async() => {
		quick.close();
		await samples.close();
		delete session.phlappyPhant;
	});

	onClose(() => shell.close());
}
