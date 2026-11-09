/** Browser shell for PHP Slalom: storage, replay files, sound, touch, and accessible hit targets. */
export async function activate({module, canvas, assets, packageUrl, services, session, onClose})
{
	const shell = new services.GameShell({module, canvas, session, title: 'PHP Slalom'});
	shell.context({antialias: false});
	const {signal} = shell;

	const controlsKey = 'php-slalom.controls.v1';
	const profileKey = 'php-slalom.profile.v1';
	const optionsKey = 'php-slalom.options.v1';
	const storage = new services.SafeStorage;
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
	status.className = 'php-slalom-sr';
	status.setAttribute('aria-live', 'polite');
	file.type = 'file';
	file.accept = '.pslr,application/vnd.phonics.slalom-replay';
	file.className = 'php-slalom-file';
	style.textContent = `
		.php-slalom-ui{position:relative;width:100%;height:100%;max-width:none;margin:auto;background:#000;color:#fff}
		.php-slalom-screen{position:relative;width:100%;height:100%;isolation:isolate;overflow:hidden;background:#000}
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
		.php-slalom-ui:fullscreen{width:100%;height:100%;max-width:none}
		@media(pointer:coarse){.php-slalom-screen .quick-controls button{width:44px;height:44px}}
	`;

	shell.mount(ui, {screen});
	ui.append(style, screen, status, file);
	screen.append(targets, touch);
	const quick = new services.QuickControls(screen, ui);
	const held = {left: false, right: false};
	let imported = '', crashSounded = false, savedRun = '';

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

	const saveProfile = () => storage.set(profileKey, JSON.stringify(profile));

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

	const view = shell.measure({
		observe: screen
		, cssSize: true
		, initial: '[960,600,1,false]'
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
		if(await samples.unlock()) await samples.load('impact', 'assets/bomb.wav');
	}

	function impact()
	{
		void unlock().then(() => samples.play('impact', {gain: .6}));
	}

	shell.fullscreen({target: ui, quick, onChange: () => view.measure()});

	canvas.addEventListener('pointerdown', () => { canvas.focus({preventScroll: true}); void unlock(); }, {signal});
	canvas.addEventListener('keydown', () => { void unlock(); }, {signal});
	shell.suppressContextMenu();

	const focus = () => { canvas.focus({preventScroll: true}); void unlock(); };

	const pad = new services.TouchPad(touch, [
		{id: 'left', label: 'Steer left', text: 'LEFT'}
		, {id: 'right', label: 'Steer right', text: 'RIGHT'}
	], {signal, focus, onChange: ids => { held.left = ids.has('left'); held.right = ids.has('right'); }});

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

	const action = shell.action('slalomAction');
	const buttons = new services.RegionButtons(targets, {signal, focus, onPress: id => action.act(id)});

	shell.bridge('slalomViewport', () => view.viewport());
	shell.bridge('slalomTouch', () => JSON.stringify(held));
	shell.bridge('slalomStorageRead', () => storage.get(controlsKey) ?? '');
	shell.bridge('slalomStorageWrite', value => storage.set(controlsKey, String(value)));
	shell.bridge('slalomSeed', () => shell.seed31());
	shell.bridge('slalomScores', () => JSON.stringify({fresh: profile.fresh, continued: profile.continued}));
	shell.bridge('slalomLastReplay', () => profile.lastReplay);
	shell.bridge('slalomRequestReplayImport', () => file.click());
	shell.bridge('slalomReplayImport', () => { const value = imported; imported = ''; return value; });

	shell.bridge('slalomExportLast', () => {
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
	});

	const state = shell.status('slalomStatus', {
		dataset: 'phpSlalom'
		, handler: nextState => {
			const coarse = globalThis.matchMedia?.('(pointer: coarse)').matches ?? false;
			pad.visible = nextState.page === 'play' && coarse && !nextState.replay;

			if(nextState.crashed && !crashSounded) impact();

			crashSounded = nextState.crashed;
			buttons.sync(nextState.width, nextState.height, nextState.regions);

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

				const best = profile.bestFresh;

				if(!nextState.result.continued && (!best || nextState.result.score > best.score))
				profile.bestFresh = {score: nextState.result.score, replay: nextState.result.replay};

				saveProfile();
			}
		}
	});

	session.phpSlalom = {
		get state() { return state.state; }
		, get profile() { return structuredClone(profile); }
	};

	shell.onClose(async() => {
		quick.close();
		await samples.close();
		delete session.phpSlalom;
	});

	onClose(() => shell.close());
}
