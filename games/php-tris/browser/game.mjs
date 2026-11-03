/** Browser ownership, accessible hit targets, touch actions, and original synthesized audio. */
export async function activate({module,canvas,world,assets,loadData,services,session,onClose,start})
{
	if(!canvas.getContext('webgl2', {antialias: false})) throw Error('PHPTris requires WebGL2');

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
			module.phpTrisStart = p.mode;
			module.phpTrisStartRank = p.rank;
		}
	}

	for(const [mode,ranks] of Object.entries(catalog))
	{
		const count = ['endless','race','time'].includes(mode) ? 1 : 15;

		for(let rank = 1; rank<=count; rank++) if(!ranks[rank]) throw Error(`${source}: missing ${mode} rank ${rank}`);
	}

	if(!Object.keys(catalog).length) throw Error(`${source}: no PHPTris boards`);

	module.phpTrisCatalog = JSON.stringify(catalog);
	const parent = canvas.parentNode, next = canvas.nextSibling, originalStyle = canvas.getAttribute('style');
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
 .php-tris-ui{position:relative;width:100%;max-width:1100px;margin:auto;background:#090813;color:#eef}
 .php-tris-screen{position:relative;width:100%;aspect-ratio:16/9;isolation:isolate}
 .php-tris-screen>canvas{position:absolute;display:block;margin:0;border:0;border-radius:0;max-width:none;max-height:none;image-rendering:pixelated;touch-action:none}
 .php-tris-targets{position:absolute;inset:0;pointer-events:none}
 .php-tris-ui .php-tris-targets button{position:absolute;pointer-events:auto;touch-action:none;background:transparent;color:transparent;border:0;padding:0;border-radius:0;box-shadow:none}
 .php-tris-ui .php-tris-targets button:is(:hover,:focus,:active){background:transparent;color:transparent;box-shadow:none}
 .php-tris-ui .php-tris-targets button:focus-visible{outline:2px solid #e4bd7c;outline-offset:1px}
 .php-tris-sr{position:absolute;width:1px;height:1px;overflow:hidden;clip-path:inset(50%)}
 .php-tris-ui:fullscreen{max-width:none;display:flex;align-items:center;justify-content:center;height:100%;width:100%}
 @media(max-width:600px) and (orientation:portrait){.php-tris-screen{aspect-ratio:9/17}}
 .php-tris-screen .quick-controls{right:8px;bottom:2px;padding:0;opacity:.85}
 .php-tris-screen .quick-controls button{width:28px;height:28px;padding:3px}
 @media(pointer:coarse){.php-tris-screen .quick-controls button{width:44px;height:44px}}
 `;

	parent.insertBefore(ui, canvas);
	ui.append(style, screen, status);
	screen.append(canvas, targets);
	const abort = new AbortController(), {signal} = abort;
	let viewport = '[960,540,1,false]', frameState = null, action = '', pulses = {}, held = {}, muted = false, audio
		= null;

	const pointers = new Map(), buttons = new Map();
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

	function measure()
	{
		const portrait = innerWidth<600 && innerHeight>innerWidth;

		if(document.fullscreenElement===ui)
		{
			const ratio = portrait ? 9/17 : 16/9;
			screen.style.width = Math.min(innerWidth, innerHeight*ratio) + 'px';
		}
		else screen.style.width = '100%';

		const box = screen.getBoundingClientRect(), dpr = devicePixelRatio||1;
		const w = Math.max(1, Math.round(box.width*dpr)),h = Math.max(1, Math.round(box.height*dpr));
		canvas.style.width = w/dpr + 'px';
		canvas.style.height = h/dpr + 'px';
		canvas.style.left = (Math.round(box.left*dpr)/dpr-box.left) + 'px';
		canvas.style.top = (Math.round(box.top*dpr)/dpr-box.top) + 'px';
		viewport = JSON.stringify([w,h,dpr,matchMedia('(pointer:coarse)').matches || navigator.maxTouchPoints>0]);
	}

	const resize = new ResizeObserver(measure);
	resize.observe(screen);
	window.addEventListener('resize', measure, {signal});
	window.addEventListener('scroll', measure, {signal,capture: true});
	measure();

	function reset() { held = {}; pulses = {}; pointers.clear(); }

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
	let fullscreenBusy = false, fullscreenTask = null, closed = false;

	function fullscreen(error = '')
	{
		quick.setFullscreen(document.fullscreenElement===ui, !!document.fullscreenEnabled, fullscreenBusy, error);
		measure();
	}

	quick.fullscreenButton.addEventListener('click', async() => {
		if(fullscreenBusy) return;

		fullscreenBusy = true;
		fullscreen();

		try
		{
			fullscreenTask = document.fullscreenElement===ui ? document.exitFullscreen() : ui.requestFullscreen();
			await fullscreenTask;

			if(closed) return;
		}
		catch(error)
		{
			fullscreenBusy = false;

			if(!closed) fullscreen(error.message);

			return;
		}

		fullscreenBusy = false;
		fullscreen();
		canvas.focus();
	}, {signal}
	);

	document.addEventListener('fullscreenchange', () => {reset();fullscreen();}, {signal});
	fullscreen();
	let signature = '', lastPage = '', savedRun = '';

	module.phpTrisViewport = () => viewport;

	module.phpTrisAction = () => {const value = action;action = '';return value;};

	module.phpTrisTouch = () => {const result = JSON.stringify({...held,...pulses});pulses = {};return result;};

	module.phpTrisBest = mode => {
		const r = records[mode];

		return r ? (mode==='race' ? 'BEST ' + Number(r.elapsed).toFixed(2) + ' S' : 'BEST ' + r.score) : 'BEST --';
	};

	module.phpTrisStatus = json => {
		const s = JSON.parse(json);
		frameState = s;

		if(s.page!==lastPage)
		{
			reset();
			lastPage = s.page;
		}

		const regions = s.info?.open && s.info?.backHint
			? [{id: 'close-info',label: 'Back to title screen',rect: s.info.backHint}]
			: (s.info?.open ? [] : s.regions);

		const key = JSON.stringify([s.width,s.height,regions]);

		if(key!==signature)
		{
			signature = key;
			const wanted = new Set(regions.map(r => r.id));

			for(const [id,button] of buttons)
			{
				if(!wanted.has(id))
				{
					button.remove();
					buttons.delete(id);
				}
			}

			for(const region of regions)
			{
				let button = buttons.get(region.id);

				if(!button)
				{
					button = document.createElement('button');
					button.type = 'button';
					button.dataset.action = region.id;

					button.addEventListener('pointerdown', event => {
						if(!button.holdAction) return;

						event.preventDefault();
						canvas.focus();
						button.setPointerCapture(event.pointerId);
						pointers.set(event.pointerId, region.id);
						held[region.id] = true;
						pulses[region.id] = true;
					}, {signal}
					);

					const release = event => {
						const id = pointers.get(event.pointerId);
						pointers.delete(event.pointerId);

						if(id && ![...pointers.values()].includes(id)) delete held[id];
					};

					button.addEventListener('pointerup', release, {signal});
					button.addEventListener('pointercancel', release, {signal});
					button.addEventListener('lostpointercapture', release, {signal});

					button.addEventListener(
						'click'
						, () => {if(!button.holdAction) action = region.id;canvas.focus();}
						, {signal}
					);

					buttons.set(region.id, button);
					targets.append(button);
				}

				button.holdAction = region.hold;
				button.setAttribute('aria-label', region.label);
				const [x,y,w,h] = region.rect;

				Object.assign(
					button.style
					, {
						left: 100*x/s.width + '%'
						, top: 100*y/s.height + '%'
						, width: 100*w/s.width + '%'
						, height: 100*h/s.height + '%'
					}
				);
			}
		}

		const summary = `${s.mode}. ${s.page}. Score ${s.score}. Lines ${s.lines}. Rank ${s.level}. Goal ${s.goal}.`;

		if(status.textContent!==summary) status.textContent = summary;

		canvas.dataset.phpTris = json;

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
	};

	// Read-only diagnostics are useful to consumers and browser acceptance tests.
	session.phpTris = {get state(){return frameState;}};

	onClose(async() => {
		closed = true;
		abort.abort();
		resize.disconnect();
		reset();
		quick.close();

		await fullscreenTask?.catch(() => {});

		if(document.fullscreenElement===ui) await document.exitFullscreen().catch(() => {});

		await audio?.close();

		if(next?.parentNode===parent) parent.insertBefore(canvas, next);else parent.append(canvas);

		if(originalStyle===null) canvas.removeAttribute('style');else canvas.setAttribute('style', originalStyle);

		delete canvas.dataset.phpTris;
		ui.remove();

		for(const key of ['phpTrisViewport'
			, 'phpTrisAction'
			, 'phpTrisTouch'
			, 'phpTrisBest'
			, 'phpTrisStatus'
			, 'phpTrisCatalog'
			, 'phpTrisStart'
			, 'phpTrisStartRank'
		])
  delete module[key];
		delete session.phpTris;
	});
}
