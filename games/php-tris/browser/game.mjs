/** Browser ownership, accessible hit targets, touch actions, and original synthesized audio. */
export async function activate({module,canvas,world,assets,loadData,services,session,onClose,start})
{
	const shell = new services.GameShell({module, canvas, session, title: 'PHPTris'});
	shell.context({antialias: false});
	const {signal} = shell;

	const source = JSON.parse(module.phonicsWorld).sourceUrl;
	const catalog = {}, names = new Set(['endless','race','time','squares','cascade','targets','bonds','reactor']);

	for(const entry of world.maps ?? [])
	{
		const url = new URL(entry.fileName, source).href;
		const data = await loadData(url);
		const p = Object.fromEntries((data.properties??[]).map(p => [p.name,p.value]));

		if(!names.has(p.mode) || !Number.isInteger(p.rank) || p.rank<1 || p.rank>15)
  throw Error(`${url}: invalid PHPTris mode or rank`);

		if(data.width!==10 || data.height!==20 || data.infinite)
  throw Error(`${url}: PHPTris boards must be finite 10 by 20 maps`);

		for(const [name, maximum] of [['seed',2147483646],['lineTarget',100000],['duration',86400]])
		{
			if(p[name] !== undefined && (!Number.isInteger(p[name]) || p[name] < 1 || p[name] > maximum))
				throw Error(`${url}: ${name} must be a positive integer no larger than ${maximum}`);
		}

		if(p.ghost !== undefined && typeof p.ghost !== 'boolean') throw Error(`${url}: ghost must be boolean`);

		const initial = data.layers?.find(layer => layer.name==='Initial cells');

		if(!initial || initial.data?.length!==200 || initial.data.some(gid => !Number.isInteger(gid)||gid<0||gid>11))
  throw Error(`${url}: invalid initial cell layer`);

		if((catalog[p.mode] ??= {})[p.rank]) throw Error(`${url}: duplicate mode/rank`);

		catalog[p.mode][p.rank] = data;

		if(url===start)
		{
			shell.bridge('phpTrisStart', p.mode);
			shell.bridge('phpTrisStartRank', p.rank);
		}
	}

	for(const [mode,ranks] of Object.entries(catalog))
	{
		const count = ['endless','race','time'].includes(mode) ? 1 : 15;

		for(let rank = 1; rank<=count; rank++) if(!ranks[rank]) throw Error(`${source}: missing ${mode} rank ${rank}`);
	}

	if(!Object.keys(catalog).length) throw Error(`${source}: no PHPTris boards`);

	shell.bridge('phpTrisCatalog', JSON.stringify(catalog));
	const ui = document.createElement('section');
	ui.className = 'php-tris-ui';
	ui.setAttribute('aria-label', 'PHPTris');
	const screen = document.createElement('div');
	screen.className = 'php-tris-screen';
	const targets = document.createElement('div');
	targets.className = 'php-tris-targets';
	const status = document.createElement('p');
	status.className = 'php-tris-sr';
	status.setAttribute('aria-live', 'polite');
	const style = document.createElement('style');
	style.textContent = `
 .php-tris-ui{position:relative;width:100%;height:100%;margin:auto;background:#090813;color:#eef}
 .php-tris-screen{position:relative;width:100%;height:100%;isolation:isolate}
 .php-tris-screen>canvas{position:absolute;display:block;margin:0;border:0;border-radius:0;max-width:none;max-height:none;image-rendering:pixelated;touch-action:none}
 .php-tris-targets{position:absolute;inset:0;pointer-events:none}
 .php-tris-ui .php-tris-targets button{position:absolute;pointer-events:auto;touch-action:none;background:transparent;color:transparent;border:0;padding:0;border-radius:0;box-shadow:none}
 .php-tris-ui .php-tris-targets button:is(:hover,:focus,:active){background:transparent;color:transparent;box-shadow:none}
 .php-tris-ui .php-tris-targets button:focus-visible{outline:2px solid #e4bd7c;outline-offset:1px}
 .php-tris-sr{position:absolute;width:1px;height:1px;overflow:hidden;clip-path:inset(50%)}
 .php-tris-ui:fullscreen{height:100%;width:100%}
 .php-tris-screen .quick-controls{right:8px;bottom:2px;padding:0;opacity:.85}
 .php-tris-screen .quick-controls button{width:28px;height:28px;padding:3px}
 @media(pointer:coarse){.php-tris-screen .quick-controls button{width:44px;height:44px}}
 `;

	shell.mount(ui, {screen});
	ui.append(style, screen, status);
	screen.append(targets);
	let frameState = null, pulses = {}, held = {}, muted = false, audio = null;
	let records = {};
	const recordKey = 'phptris:v1:' + source;
	const storage = new services.SafeStorage;

	try
	{
		records = JSON.parse(storage.get(recordKey)??'{}');

		if(!records || typeof records!=='object') records = {};
	}
	catch
	{
		records = {};
	}

	// The screen fills its frame on the page and in fullscreen; the scene lays itself out for the shape it gets,
	// switching to the portrait board when the frame is taller than wide. The canvas snaps to device pixels.
	const view = shell.measure({
		observe: screen
		, initial: '[960,540,1,false]'
		, windowEvents: ['resize']
		, format: (w, h, dpr, box) => {
			canvas.style.width = w/dpr + 'px';
			canvas.style.height = h/dpr + 'px';
			canvas.style.left = (Math.round(box.left*dpr)/dpr-box.left) + 'px';
			canvas.style.top = (Math.round(box.top*dpr)/dpr-box.top) + 'px';

			return JSON.stringify([w,h,dpr,matchMedia('(pointer:coarse)').matches || navigator.maxTouchPoints>0]);
		}
	});

	window.addEventListener('scroll', view.measure, {signal,capture: true});

	function reset() { held = {}; pulses = {}; }

	window.addEventListener('blur', reset, {signal});
	canvas.addEventListener('blur', reset, {signal});
	document.addEventListener('visibilitychange', reset, {signal});

	function unlock()
	{
		const Audio = window.AudioContext||window.webkitAudioContext;

		if(!Audio) return;

		audio ??= new Audio();
		audio.resume().catch(() => {});
	}

	ui.addEventListener('pointerdown', unlock, {signal});
	ui.addEventListener('keydown', unlock, {signal});

	function sound(event)
	{
		if(muted || !audio || audio.state!=='running') return;

		const frequency = {
			rotate: 260
			, lock: 130
			, clear: 650
			, hold: 340
			, mass: 880
			, rank: 1040
			, complete: 1200
			, finished: 440
			, gameover: 90
		}[
	  event
		];

		if(!frequency) return;

		const oscillator = audio.createOscillator(),gain = audio.createGain(),now = audio.currentTime;
		oscillator.type = 'triangle';
		oscillator.frequency.setValueAtTime(frequency, now);
		oscillator.frequency.exponentialRampToValueAtTime(frequency*.55, now+.12);
		gain.gain.setValueAtTime(.045, now);
		gain.gain.exponentialRampToValueAtTime(.001, now+.14);
		oscillator.connect(gain);
		gain.connect(audio.destination);
		oscillator.start(now);
		oscillator.stop(now+.15);

		oscillator.onended = () => {oscillator.disconnect();gain.disconnect();};
	}

	const quick = new services.QuickControls(screen, ui);
	quick.muteButton.addEventListener('click', () => {muted = !muted;quick.setMuted(muted);canvas.focus();}, {signal});
	shell.fullscreen({target: ui, quick, onChange: () => view.measure()});
	document.addEventListener('fullscreenchange', reset, {signal});
	let lastPage = '', savedRun = '';

	const action = shell.action('phpTrisAction');

	const buttons = new services.RegionButtons(targets, {
		signal
		, focus: () => canvas.focus()
		, onPress: id => action.act(id)
		, onHold: (id, down) => {
			if(down)
			{
				held[id] = true;
				pulses[id] = true;
			}
			else delete held[id];
		}
	});

	shell.bridge('phpTrisViewport', () => view.viewport());
	shell.bridge('phpTrisTouch', () => {const result = JSON.stringify({...held,...pulses});pulses = {};return result;});

	shell.bridge('phpTrisBest', mode => {
		const r = records[mode];

		return r ? (mode==='race' ? 'BEST ' + Number(r.elapsed).toFixed(2) + ' S' : 'BEST ' + r.score) : 'BEST --';
	});

	shell.status('phpTrisStatus', {
		dataset: 'phpTris'
		, handler: s => {
			frameState = s;

			if(s.page!==lastPage)
			{
				reset();
				lastPage = s.page;
			}

			const regions = s.info?.open && s.info?.backHint
				? [{id: 'close-info',label: 'Back to title screen',rect: s.info.backHint}]
				: (s.info?.open ? [] : s.regions);

			buttons.sync(s.width, s.height, regions);

			const summary = `${s.mode}. ${s.page}. Score ${s.score}. Lines ${s.lines}. Rank ${s.level}. Goal ${s
				.goal}.`;

			if(status.textContent!==summary) status.textContent = summary;

			for(const event of s.events) sound(event);

			if(s.page==='results' && savedRun!==String(s.runId))
			{
				savedRun = String(s.runId);
				const old = records[s.mode];
				const eligible = s.mode!=='race'||s.phase==='complete';
				const challenge = !['endless','race','time'].includes(s.mode);
				const better = !old || (s.mode==='race'
					? s.elapsed<old.elapsed
					: challenge && s.level!==old.level ? s.level>old.level : s.score>old.score
				);

				if(eligible&&better)
				{
					records[s.mode] = {score: s.score,elapsed: s.elapsed,level: s.level};

					storage.set(recordKey, JSON.stringify(records));
				}
			}
		}
	});

	// Read-only diagnostics are useful to consumers and browser acceptance tests.
	session.phpTris = {get state(){return frameState;}};

	shell.onClose(async() => {
		reset();
		quick.close();
		await audio?.close();
		delete session.phpTris;
	});

	onClose(() => shell.close());
}
