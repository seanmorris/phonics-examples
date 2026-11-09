/** Browser shell for PHP Buster: storage, sound, pointer/touch motion, and accessible menus. */
export async function activate({module, canvas, assets, packageUrl, services, session, onClose})
{
	const shell = new services.GameShell({module, canvas, session, title: 'PHP Buster'});
	shell.context({antialias: false});
	const {signal} = shell;

	const controlsKey = 'php-buster.controls.v1';
	const profileKey = 'php-buster.profile.v1';
	const optionsKey = 'php-buster.options.v1';
	const storage = new services.SafeStorage;
	const ui = document.createElement('section');
	const screen = document.createElement('div');
	const targets = document.createElement('div');
	const touch = document.createElement('div');
	const status = document.createElement('p');
	const style = document.createElement('style');
	ui.className = 'php-buster-ui';
	ui.setAttribute('aria-label', 'PHP Buster');
	screen.className = 'php-buster-screen';
	targets.className = 'php-buster-targets';
	touch.className = 'php-buster-touch';
	status.className = 'php-buster-sr';
	status.setAttribute('aria-live', 'polite');
	style.textContent = `
		.php-buster-ui{position:relative;display:flex;align-items:center;justify-content:center;width:100%;height:100%;
			max-width:none;margin:auto;background:#000;color:#fff}
		.php-buster-screen{position:relative;width:100%;height:100%;isolation:isolate;overflow:hidden;background:#000}
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
		@media(pointer:coarse){.php-buster-screen .quick-controls button{width:44px;height:44px}}
	`;

	shell.mount(ui, {screen});
	ui.append(style, screen, status);
	screen.append(targets, touch);
	const quick = new services.QuickControls(screen, ui);
	const pointer = {active: false, x: 160, y: 400, fire: false};
	const drags = new Map;
	const firePointers = new Set;
	let state = null, soundedExplosion = 0, mouseInside = false;

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

	const saveProfile = () => storage.set(profileKey, JSON.stringify(profile));

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

	const view = shell.measure({
		observe: screen
		, cssSize: true
		, initial: '[800,720,1,false]'
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
		if(await samples.unlock()) await samples.load('explosion', 'assets/explosion.wav');
	}

	function explosion()
	{
		void unlock().then(() => samples.play('explosion', {gain: .55}));
	}

	shell.fullscreen({target: ui, quick, onChange: () => view.measure()});
	shell.suppressContextMenu();

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

	const focus = () => { canvas.focus({preventScroll: true}); void unlock(); };

	const pad = new services.TouchPad(touch, [{id: 'fire', label: 'Fire', text: 'FIRE'}], {
		signal
		, focus
		, onChange: held => { pointer.fire = held.has('fire') || firePointers.size > 0; }
	});

	const releasePointer = event => {
		if(event.pointerType === 'touch')
		{
			drags.delete(event.pointerId);
			pointer.active = drags.size > 0 || mouseInside;
		}
		else
		{
			firePointers.delete(event.pointerId);
			pointer.fire = firePointers.size > 0 || pad.held.has('fire');
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

	const action = shell.action('busterAction');
	const buttons = new services.RegionButtons(targets, {signal, focus, onPress: id => action.act(id)});

	shell.bridge('busterViewport', () => view.viewport());
	shell.bridge('busterPointer', () => JSON.stringify(pointer));
	shell.bridge('busterStorageRead', () => storage.get(controlsKey) ?? '');
	shell.bridge('busterStorageWrite', value => storage.set(controlsKey, String(value)));
	shell.bridge('busterSeed', () => shell.seed31());
	shell.bridge('busterScores', () => JSON.stringify(profile.scores));

	shell.bridge('busterSubmitScore', (initials, score) => {
		initials = String(initials).toUpperCase().replace(/[^A-Z0-9]/g, '').slice(0, 3).padEnd(3, 'A');
		score = Math.max(0, Math.trunc(Number(score) || 0));
		profile.scores.push({initials, score});
		profile.scores.sort((a, b) => b.score - a.score);
		profile.scores.length = Math.min(10, profile.scores.length);

		return saveProfile();
	});

	shell.status('busterStatus', {
		dataset: 'phpBuster'
		, handler: nextState => {
			const previousRun = state?.runId;
			state = nextState;
			const coarse = globalThis.matchMedia?.('(pointer: coarse)').matches ?? false;
			pad.visible = nextState.page === 'play' && coarse;

			if(nextState.runId !== previousRun) soundedExplosion = 0;

			while(soundedExplosion < nextState.explosions)
			{
				soundedExplosion++;

				if(nextState.explosions - soundedExplosion < 4) explosion();
			}

			buttons.sync(nextState.width, nextState.height, nextState.regions);

			const summary = `${nextState.page}. Score ${nextState.score}. Level ${nextState.level}, stage ${nextState
				.stage}. ${nextState.shields} shields.`;

			if(status.textContent !== summary) status.textContent = summary;
		}
	});

	session.phpBuster = {
		get state() { return state; }
		, get profile() { return structuredClone(profile); }
	};

	shell.onClose(async() => {
		quick.close();
		await samples.close();
		delete session.phpBuster;
	});

	onClose(() => shell.close());
}
