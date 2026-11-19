/** Authoring UI and lifecycle bridge; PHP owns terrain, camera, edits and simulation. */
export function activate({module, canvas, session, services})
{
	const shell = new services.GameShell({module, canvas, session, title: 'Landscape Sandbox'});
	const gl = shell.context({alpha: false, antialias: false, depth: true, stencil: false});
	const {signal} = shell;
	const frame = document.createElement('div');
	frame.className = 'terrain-sandbox';
	frame.innerHTML = markup();

	const stage = frame.querySelector('.terrain-stage');
	shell.mount(frame, {screen: stage});
	canvas.style.cssText = 'display:block;width:100%;height:100%;max-width:none;max-height:none;border:0;outline:none';

	canvas.setAttribute(
		'aria-label'
		, 'Landscape Sandbox. Right-drag to look; WASD to fly; Q and E down and up. A gamepad also flies and drives.'
	);

	const view = shell.measure({observe: stage, dprCap: 1, skipEmpty: true, initial: '[1280,720,1]'});
	shell.suppressContextMenu();
	const fullscreen = shell.fullscreen({target: frame});

	let latest = null, keys = {}, values = {}, actions = [], clicks = [], look = [0,0], dragging = null;
	let lastPointer = null, lastGeneration = -1, lastRoadProjection = '';
	const status = frame.querySelector('[data-status]');
	const detail = frame.querySelector('[data-diagnostics]');
	const pending = frame.querySelector('[data-pending]');
	const roadPreview = frame.querySelector('[data-road-preview]');

	const controls = new Map([...frame.querySelectorAll('[data-value]')].map(field => [field.dataset.value, field]));

	const coarseFog = controls.get('waterFogDensity');
	const fineFog = frame.querySelector('[data-fine-fog]');
	const fineOutput = fineFog.parentElement.querySelector('output');

	// Hold the fine window still while dragging it. Only a coarse edit or a
	// restored landscape recentres the window on the nearest coarse tick.
	const setFineFogWindow = value => {
		const center = coarseFog.valueAsNumber;
		fineFog.min = Math.max(0, center - .005).toFixed(4);
		fineFog.max = Math.min(1, center + .005).toFixed(4);
		fineFog.value = value;
		fineOutput.value = String(value);
	};

	fineFog.addEventListener('input', () => {
		values.waterFogDensity = fineFog.valueAsNumber;
		fineOutput.value = fineFog.value;
	}, {signal});

	const button = name => frame.querySelector(`[data-action="${name}"]`);

	const push = action => { actions.push(typeof action === 'string' ? {type: action} : action); };

	const focus = () => canvas.focus({preventScroll: true});

	const focusedField = target => target instanceof HTMLElement && Boolean(target.closest('input,select,textarea'));

	for(const field of controls.values())
	{
		field.addEventListener('input', () => {
			if(field.dataset.value === 'waterLevel')
			{
				if(!Number.isFinite(field.valueAsNumber) || !field.validity.valid) return;

				field.defaultValue = field.value;
			}

			values[field.dataset.value] = field.type === 'checkbox'
				? field.checked
				: field.type === 'range' || field.type === 'number' ? Number(field.value) : field.value;

			const output = frame.querySelector(`[data-output="${field.dataset.value}"]`);

			if(output) output.textContent = field.value;

			if(field === coarseFog) setFineFogWindow(field.valueAsNumber);
		}, {signal});

		if(field.dataset.value === 'waterLevel')
		field.addEventListener('blur', () => {
			if(!Number.isFinite(field.valueAsNumber) || !field.validity.valid) field.value = field.defaultValue;
		}, {signal});
	}

	for(const field of frame.querySelectorAll('[data-action]'))
	{
		field.addEventListener('click', () => {
			const type = field.dataset.action;

			if(type === 'generate') push({type, ...generationValues(frame)});
			else if(type === 'load') frame.querySelector('[data-file]').click();
			else if(type === 'fullscreen')
			void fullscreen.toggle().catch(error => {
				status.textContent = error.message;
			});
			else push(type);

			if(type !== 'load') focus();
		}, {signal});
	}

	frame.querySelector('[name="preset"]').addEventListener('change', event => {
		frame.querySelector(
			'[name="heightScale"]'
		).value = {coast: 420, mountain: 1400, canyon: 650}[event.target.value];

		frame.querySelector('[name="terraceSpacing"]').value = event.target.value === 'canyon' ? 80 : 0;
		frame.querySelector('[name="waterLevel"]').value = event.target.value === 'canyon' ? -450 : 0;
	}, {signal});

	frame.querySelector('[data-file]').addEventListener('change', async event => {
		const file = event.target.files?.[0];

		if(!file) return;

		try
		{
			if(file.size > 4 * 1024 * 1024) throw Error('Landscape file exceeds 4 MiB.');

			const json = await file.text();

			if(!shell.closed) push({type: 'load', json});
		}
		catch(error)
		{
			status.textContent = error.message;
		}

		event.target.value = '';
	}, {signal});

	const heldKeys = new Set(
		['KeyW','KeyA','KeyS','KeyD','KeyQ','KeyE','KeyZ','KeyX','Space','ShiftLeft','ShiftRight']
	);

	const commands = {
		KeyP: 'pause'
		, Escape: 'pause'
		, KeyF: 'fly'
		, KeyB: 'craft'
		, KeyC: 'car'
		, KeyJ: 'jet'
		, Home: 'reset-camera'
	};

	frame.addEventListener('keydown', event => {
		if(focusedField(event.target)) return;

		// Space still presses a focused button or link rather than the handbrake.
		const activates = event.code === 'Space' && event.target instanceof HTMLElement
			&& Boolean(event.target.closest('button,summary,a,label'));

		if(heldKeys.has(event.code) && !event.ctrlKey && !event.metaKey && !activates)
		{
			keys[event.code] = true;
			event.preventDefault();
		}

		if((event.ctrlKey || event.metaKey) && event.code === 'KeyZ')
		{
			if(!event.repeat) push(event.shiftKey ? 'redo' : 'undo');

			event.preventDefault();
		}
		else if(commands[event.code])
		{
			if(!event.repeat) push(commands[event.code]);

			event.preventDefault();
		}

		if(event.code === 'KeyH' && !event.repeat)
		{
			const panel = frame.querySelector('.terrain-panel');
			panel.open = !panel.open;
		}
	}, {signal});

	globalThis.addEventListener('keyup', event => { delete keys[event.code]; }, {signal});

	const clearInput = () => { keys = {}; dragging = null; lastPointer = null; look = [0,0]; };

	globalThis.addEventListener('blur', clearInput, {signal});
	document.addEventListener('visibilitychange', () => { if(document.hidden) clearInput(); }, {signal});
	frame.addEventListener('focusin', event => { if(focusedField(event.target)) clearInput(); }, {signal});

	canvas.addEventListener('pointerdown', event => {
		focus();

		if(event.button === 2)
		{
			dragging = event.pointerId;
			lastPointer = [event.clientX,event.clientY];
			canvas.setPointerCapture(event.pointerId);
		}

		if(event.button === 0)
		{
			const box = canvas.getBoundingClientRect();
			clicks.push([(event.clientX - box.left) / box.width,(event.clientY - box.top) / box.height]);
		}

		event.preventDefault();
	}, {signal});

	canvas.addEventListener('pointermove', event => {
		if(dragging !== event.pointerId || !lastPointer) return;

		look[0] += event.clientX - lastPointer[0];
		look[1] += event.clientY - lastPointer[1];
		lastPointer = [event.clientX,event.clientY];
	}, {signal});

	const release = event => {
		if(dragging !== event.pointerId) return;

		dragging = null;
		lastPointer = null;

		if(canvas.hasPointerCapture(event.pointerId)) canvas.releasePointerCapture(event.pointerId);
	};

	canvas.addEventListener('pointerup', release, {signal});
	canvas.addEventListener('pointercancel', release, {signal});

	// Jet handling is the player's preference, so it is remembered in this browser rather than in landscape files.
	const jetControls = controls.get('jetControls');
	const invertPitch = controls.get('invertPitch');

	const rememberJet = () => {
		try
		{
			localStorage.setItem(
				JET_PREFERENCE
				, JSON.stringify({jetControls: jetControls.value, invertPitch: invertPitch.checked})
			);
		}
		catch
		{
			/* Storage can be unavailable; the choice then lasts for this visit. */
		}
	};

	try
	{
		const saved = JSON.parse(localStorage.getItem(JET_PREFERENCE) ?? 'null');

		if(['novice','expert'].includes(saved?.jetControls)) values.jetControls = jetControls.value = saved.jetControls;

		if(typeof saved?.invertPitch === 'boolean') values.invertPitch = invertPitch.checked = saved.invertPitch;
	}
	catch
	{
		/* A missing or unreadable preference leaves Novice with the stick unreversed. */
	}

	jetControls.addEventListener('change', rememberJet, {signal});
	invertPitch.addEventListener('change', rememberJet, {signal});

	let previousViewport, previousKeys = '', previousPad = '', padButtons = new Set();

	shell.bridge('terrainInput', () => {
		const viewport = view.viewport();
		const held = Object.keys(keys).filter(key => keys[key]).join(',');
		// Browsers stop updating gamepads in a background page, so a stick held at blur must not stay held.
		const gamepad = document.hidden || !document.hasFocus() ? null : readGamepads();
		const buttons = gamepad?.buttons ?? new Set();

		for(const [index, command] of PAD_COMMANDS)
		if(buttons.has(index) && !padButtons.has(index)) push(command);

		padButtons = buttons;
		const pad = gamepad
			? [...gamepad.axes.map(value => Math.round(value * 1000) / 1000)
				, PAD_HELD.reduce((mask, [index, bit]) => buttons.has(index) ? mask | bit : mask, 0)
			]
			: [0,0,0,0,0,0,0];

		const padKey = pad.join(',');

		if(viewport === previousViewport && held === previousKeys && padKey === previousPad && !actions.length
			&& !clicks.length && !look[0] && !look[1] && !Object.keys(values).length
		) return '';

		const packet = {keys,values,actions,clicks,look};

		if(viewport !== previousViewport) packet.viewport = JSON.parse(viewport);

		if(padKey !== previousPad) packet.pad = pad.some(value => value !== 0) ? pad : [];

		previousPad = padKey;
		previousKeys = held;
		previousViewport = viewport;
		values = {};
		actions = [];
		clicks = [];
		look = [0,0];

		return JSON.stringify(packet);
	});

	shell.bridge('terrainDownload', json => {
		const url = URL.createObjectURL(new Blob([json], {type: 'application/json'}));
		const link = document.createElement('a');
		link.href = url;
		link.download = 'landscape.json';
		document.body.append(link);
		link.click();
		link.remove();
		setTimeout(() => URL.revokeObjectURL(url), 1000);
	});

	shell.status('terrainStatus', {
		dataset: 'terrainSandbox'
		, handler: state => {
			latest = state;

			// Back on a gamepad switches the handling in PHP. Show it here and remember it.
			if(state.jetControls && jetControls.value !== state.jetControls && document.activeElement !== jetControls)
			{
				jetControls.value = state.jetControls;
				rememberJet();
			}

			if(state.car?.motion === 'overturned') status.textContent = 'Car overturned. Recovering…';
			else if(state.car?.motion === 'tumbling') status.textContent = 'Car tumbling…';
			else status.textContent = state.message;
			button('pause').textContent = state.paused ? 'Resume · P' : 'Pause · P';
			button('craft').hidden = state.mode === 'craft';
			button('jet').hidden = state.mode === 'jet';
			button('car').hidden = state.mode === 'car';
			button('car').disabled = state.findingCarSite;
			button('fly').hidden = state.mode === 'fly' && !state.findingCarSite;

			const patches = state.renderer.pending;
			pending.hidden = !state.preparing && !state.picking && !state.findingCarSite && patches === 0;

			if(state.preparing) pending.textContent = 'Preparing terrain edit…';
			else if(state.findingCarSite) pending.textContent = 'Looking for level ground…';
			else if(state.picking) pending.textContent = 'Finding the ground…';
			else if(state.renderer.visible) pending.textContent = `Refining terrain · ${patches} ${patches === 1 ? 'patch' : 'patches'}`;
			else pending.textContent = 'Preparing terrain…';

			for(const name of ['undo','redo','generate','road-apply','load','save'])
		button(name).disabled = state.preparing || state.picking && ['undo','redo','road-apply','save'].includes(name);
			frame.querySelector('[data-road-count]').textContent = `${state.roadPoints.length} control points`;
			const projection = JSON.stringify([state.roadPoints, state.camera, state.viewport]);

			if(projection !== lastRoadProjection)
			{
				updateRoadPreview(roadPreview, state);
				lastRoadProjection = projection;
			}

			canvas.style.cursor = state.mode === 'fly' && state.settings.tool !== 'navigate' ? 'crosshair' : 'grab';
			const [x,y,z] = state.camera.position;

			const mode = {fly: 'FLIGHT', craft: 'WATERCRAFT', car: 'CAR', jet: 'F5'}[state.mode];

			detail.textContent = `${mode} · ${Math.round(x)}, ${Math.round(z)} m`
			+ ` · altitude ${Math.round(state.jet?.y ?? y)} m`
			+ (state.jet ? ` · ${Math.round(state.jet.speed)} m/s` : '')
			+ `\n${state.renderer.visible} patches · `
			+ `${(state.renderer.meshBytes / 1048576).toFixed(1)} MiB mesh · `
			+ `${state.renderer.generationMs.toFixed(1)} ms generation · revision ${state.revision}\n`
			+ `${state.renderMs.toFixed(1)} ms render submission · `
			+ `${state.drawIntervalMs?.toFixed(1) ?? '—'} ms between draws`;

			if(state.generation !== lastGeneration)
			{
				frame.querySelector('[name="preset"]').value = state.recipe.preset;
				frame.querySelector('[name="seed"]').value = state.recipe.seed;

				for(const key of ['heightScale'
					, 'detailAmplitude'
					, 'slopeScale'
					, 'damping'
					, 'waterLevel'
					, 'warpAmplitude'
					, 'terraceSpacing'
				])
			frame.querySelector(`[name="${key}"]`).value = state.recipe[key];

				for(const [name, value] of Object.entries(state.settings))
				{
					const field = controls.get(name);

					if(!field) continue;

					if(field.type === 'checkbox') field.checked = value;
					else field.value = value;

					if(name === 'waterLevel') field.defaultValue = value;

					const output = frame.querySelector(`[data-output="${name}"]`);

					if(output) output.textContent = field === coarseFog ? field.value : value;

					if(field === coarseFog) setFineFogWindow(value);
				}

				lastGeneration = state.generation;
			}
		}
	});

	const api = {
		get state() { return latest; }
		, action: push
		, values: patch => { Object.assign(values, patch); }
		, click: (x, y) => { clicks.push([x,y]); }
		, keys: patch => { keys = {...patch}; }
		, graphics: () => {
			const extension = gl.getExtension('WEBGL_debug_renderer_info');

			return {
				vendor: gl.getParameter(extension?.UNMASKED_VENDOR_WEBGL ?? gl.VENDOR)
				, renderer: gl.getParameter(extension?.UNMASKED_RENDERER_WEBGL ?? gl.RENDERER)
			};
		}
	};

	session.terrainSandbox = api;
	Object.defineProperty(canvas, 'terrainSandboxDebug', {configurable: true, value: api});
	focus();

	return async () => {
		clearInput();

		if(session.terrainSandbox === api) delete session.terrainSandbox;

		delete canvas.terrainSandboxDebug;
		await shell.close();
	};
}

/** Project exact pending road points; clipping never changes the captured authoring coordinates. */
function roadProjection(point, camera, width, height)
{
	const delta = point.map((value, axis) => value - camera.position[axis]);
	const s = Math.sin(camera.yaw), c = Math.cos(camera.yaw);
	const p = Math.sin(camera.pitch), q = Math.cos(camera.pitch);
	const depth = delta[0] * s * q + delta[1] * p - delta[2] * c * q;

	if(depth <= .25) return null;

	const scale = height / (2 * Math.tan((camera.fov ?? Math.PI / 3) / 2) * depth);
	const x = width / 2 + (delta[0] * c + delta[2] * s) * scale;
	const y = height / 2 - (-delta[0] * s * p + delta[1] * q + delta[2] * c * p) * scale;

	return Number.isFinite(x) && Number.isFinite(y) ? [x, y] : null;
}

/** Keep the preview transparent to pointer input; only the canvas handles editing gestures. */
function updateRoadPreview(svg, state)
{
	const [width,height] = state.viewport;
	svg.setAttribute('viewBox', `0 0 ${width} ${height}`);
	svg.setAttribute('aria-hidden', state.roadPoints.length ? 'false' : 'true');
	svg.replaceChildren();
	let path = '', connected = false;
	const points = state.roadPoints.map(point => roadProjection(point, state.camera, width, height));

	for(const point of points)
	{
		if(!point)
		{
			connected = false;
			continue;
		}

		path += `${connected ? 'L' : 'M'}${point[0]},${point[1]} `;
		connected = true;
	}

	if(path)
	{
		const line = document.createElementNS('http://www.w3.org/2000/svg', 'path');
		line.setAttribute('d', path.trim());
		line.setAttribute('data-road-path', '');
		svg.append(line);
	}

	points.forEach((point, index) => {
		if(!point) return;

		const marker = document.createElementNS('http://www.w3.org/2000/svg', 'g');
		marker.setAttribute('transform', `translate(${point[0]},${point[1]})`);
		marker.setAttribute('data-road-marker', index);
		const circle = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
		circle.setAttribute('r', '10');

		const label = document.createElementNS('http://www.w3.org/2000/svg', 'text');
		label.textContent = String(index + 1);
		marker.append(circle, label);
		svg.append(marker);
	});
}

/** Read only the recipe controls; presentation settings travel through their own input channel. */
function generationValues(frame)
{
	const result = {
		preset: frame.querySelector('[name="preset"]').value
		, seed: frame.querySelector('[name="seed"]').value
	};

	for(const key of ['heightScale'
		, 'detailAmplitude'
		, 'slopeScale'
		, 'damping'
		, 'waterLevel'
		, 'warpAmplitude'
		, 'terraceSpacing'
	])
	result[key] = Number(frame.querySelector(`[name="${key}"]`).value);

	return result;
}

/** Local storage key for the jet handling preference. */
const JET_PREFERENCE = 'terrain-sandbox.jet';

/** Gamepad buttons in the standard layout that issue a command when pressed. */
const PAD_COMMANDS = new Map([[9, 'pause'], [8, 'jet-controls'], [12, 'jet'], [14, 'car'], [15, 'craft'], [13, 'fly']]);

/** Gamepad buttons PHP reads while held: A, B, X, Y, LB, RB and the two stick clicks, with their bits in the packet. */
const PAD_HELD = [[0, 1], [1, 2], [2, 4], [3, 8], [4, 16], [5, 32], [10, 64], [11, 128]];

/**
 * Remove a radial dead zone from a stick and rescale the rest to reach full travel.
 * @param {number} x Raw horizontal value, -1 to 1.
 * @param {number} y Raw vertical value, -1 to 1, positive towards the player.
 * @returns {number[]} The filtered stick.
 */
function stick(x, y)
{
	const length = Math.hypot(x, y);

	if(!(length > .15)) return [0,0];

	const scale = (Math.min(1, length) - .15) / (.85 * length);

	return [x * scale, y * scale];
}

/**
 * Read every connected gamepad in the standard layout as one: the stick or trigger pushed furthest wins, and a
 * button counts while any pad holds it.
 * @returns {{axes: number[], buttons: Set<number>}|null} Left X and Y, right X and Y, left and right trigger,
 * and the held button indices; null when no such pad is connected.
 */
function readGamepads()
{
	let found = false;
	const axes = [0,0,0,0,0,0];
	const buttons = new Set();

	for(const pad of navigator.getGamepads?.() ?? [])
	{
		if(!pad?.connected || pad.mapping !== 'standard') continue;

		found = true;

		const trigger = index => {
			const value = pad.buttons[index]?.value ?? 0;

			return value > .04 ? Math.min(1, (value - .04) / .96) : 0;
		};

		const values = [...stick(pad.axes[0] ?? 0, pad.axes[1] ?? 0)
			, ...stick(pad.axes[2] ?? 0, pad.axes[3] ?? 0)
			, trigger(6), trigger(7)
		];

		values.forEach((value, index) => { if(Math.abs(value) > Math.abs(axes[index])) axes[index] = value; });

		pad.buttons.forEach((button, index) => { if(button.pressed) buttons.add(index); });
	}

	return found ? {axes, buttons} : null;
}

function range(label, key, min, max, step, value)
{
	return `<label class="terrain-control"><span>${label}</span><input data-value="${key}" type="range"`
		+ ` min="${min}" max="${max}" step="${step}" value="${value}">`
			+ `<output data-output="${key}">${value}</output></label>`;
}

function markup()
{
	return `<style>
	.terrain-sandbox{position:relative;width:100%;height:100%;min-height:320px;overflow:hidden;background:#101f28;color:#edf4ee;font:14px/1.4 system-ui,sans-serif}
	.terrain-road-preview{position:absolute;inset:0;width:100%;height:100%;pointer-events:none;overflow:hidden}.terrain-road-preview path{stroke:#efc275;stroke-width:3;fill:none;filter:drop-shadow(0 1px 2px #000);vector-effect:non-scaling-stroke}.terrain-road-preview circle{fill:#102822;stroke:#efc275;stroke-width:2}.terrain-road-preview text{fill:#fff;font:700 12px system-ui,sans-serif;text-anchor:middle;dominant-baseline:central}
	.terrain-note a{color:inherit}.terrain-stage{position:absolute;inset:0}.terrain-panel{position:absolute;right:14px;top:14px;max-height:calc(100% - 28px);width:300px;overflow:auto;border:1px solid #aec8bb65;border-radius:12px;background:#102822eb;box-shadow:0 8px 30px #0006;backdrop-filter:blur(10px)}
	.terrain-panel>summary{padding:12px 15px;cursor:pointer;letter-spacing:.07em;font-weight:700}.terrain-panel>div{padding:0 14px 14px}.terrain-panel h2{font-size:12px;letter-spacing:.12em;text-transform:uppercase;color:#a8cfbd;margin:20px 0 8px}.terrain-panel h2:first-child{margin-top:3px}
	.terrain-sandbox button,.terrain-sandbox select,.terrain-sandbox input[type=number],.terrain-sandbox input[type=text]{box-sizing:border-box;background:#244437;color:#fff;border:1px solid #779d8970;border-radius:5px;min-height:32px;padding:5px 8px;font:inherit}
	.terrain-sandbox button{cursor:pointer}.terrain-sandbox button:hover{background:#355e4b}.terrain-sandbox button:disabled{opacity:.45;cursor:default}.terrain-sandbox :focus-visible{outline:2px solid #efc275;outline-offset:2px}.terrain-sandbox input{accent-color:#e5bc75}.terrain-sandbox input[type=range]{min-width:0;width:100%}
	.terrain-row{display:flex;gap:6px;margin:6px 0}.terrain-row>*{flex:1;min-width:0}.terrain-field{display:grid;gap:4px;margin:8px 0}.terrain-control{display:grid;grid-template-columns:90px 1fr 36px;gap:6px;align-items:center;margin:7px 0}.terrain-control output{font-variant-numeric:tabular-nums;text-align:right;color:#e5bc75;font-size:12px}
	.terrain-note{font-size:12px;color:#bdd2c7;margin:8px 0}.terrain-check{display:flex;align-items:center;gap:8px;margin:10px 0}.terrain-hud{position:absolute;left:16px;bottom:16px;max-width:calc(100% - 355px);pointer-events:none;text-shadow:0 1px 4px #000;background:#102822ac;border-radius:8px;padding:10px 12px}.terrain-hud p{margin:2px 0}.terrain-hud pre{font:11px/1.5 ui-monospace,monospace;color:#c1d4c6;margin:8px 0 0;white-space:pre-wrap}.terrain-pending{color:#efc275}.terrain-sandbox [hidden]{display:none!important}
	@media(max-width:700px){.terrain-panel{width:260px;right:8px;top:8px;max-height:70%}.terrain-hud{max-width:calc(100% - 56px);font-size:12px;bottom:8px}.terrain-panel:not([open]){width:auto}.terrain-panel>div{padding:0 10px 10px}}
	</style><div class="terrain-stage"></div><svg class="terrain-road-preview" data-road-preview aria-label="Pending road route" aria-hidden="true" focusable="false"></svg>
	<details class="terrain-panel" open><summary>LANDSCAPE LAB</summary><div>
	<h2>Landscape</h2><label class="terrain-field">Preset<select name="preset"><option value="coast">Coastal mountains</option><option value="mountain">Mountain ridges</option><option value="canyon">Canyon country</option></select></label>
	<label class="terrain-field">Seed<input name="seed" type="text" maxlength="256" value="first-light"></label>
	<details><summary>Landform parameters</summary>
	<label class="terrain-field">Height scale · m<input name="heightScale" type="number" min="0" max="10000" value="420"></label>
	<label class="terrain-field">Fine detail · m<input name="detailAmplitude" type="number" min="0" max="1000" value="28"></label>
	<label class="terrain-field">Slope scale<input name="slopeScale" type="number" min="0.001" max="100" step="0.001" value="0.45"></label>
	<label class="terrain-field">Steep-face detail damping<input name="damping" type="number" min="0" max="100" step="0.1" value="2"></label>
	<label class="terrain-field">Starting water level · m<input name="waterLevel" type="number" min="-10000" max="10000" value="0"></label>
	<label class="terrain-field">Domain warp · m<input name="warpAmplitude" type="number" min="0" max="5000" value="200"></label>
	<label class="terrain-field">Terrace spacing · m<input name="terraceSpacing" type="number" min="0" max="1000" value="0"></label>
	<p class="terrain-note">Domain warp bends the landforms. Terrace spacing creates smooth ledges; zero turns terraces off.</p>
	<p class="terrain-note">Higher damping smooths steep faces by reducing fine-noise amplitude. These values apply when you generate.</p></details>
	<div class="terrain-row"><button data-action="generate">Generate</button><button data-action="reset-camera">Home view</button></div>
	<label class="terrain-field">Detail<select data-value="quality"><option value="0.5">Economical</option><option value="1" selected>Balanced</option><option value="2">Detailed</option></select></label>
	<h2>Shape the ground</h2><label class="terrain-field">Tool<select data-value="tool"><option value="navigate">Navigate</option><option value="raise">Raise</option><option value="lower">Lower</option><option value="flatten">Flatten</option><option value="smooth">Smooth</option><option value="crater">Crater</option><option value="material">Paint material</option><option value="road">Road control points</option></select></label>
	${range('Radius · m', 'radius', 2, 300, 1, 40)}${range('Strength · m', 'amount', .1, 200, .1, 12)}
	<label class="terrain-field">Flatten elevation · m<input data-value="flattenHeight" type="number" min="-1000" max="3000" value="0"></label>
	<label class="terrain-field">Paint<select data-value="material"><option value="grass">Grass</option><option value="rock">Rock</option><option value="sand">Sand</option><option value="snow">Snow</option><option value="road">Road</option></select></label>
	<p class="terrain-note">Click the ground to apply one brush stroke. Ctrl+Z undoes; Ctrl+Shift+Z redoes.</p>
	<div class="terrain-row"><button data-action="undo">Undo</button><button data-action="redo">Redo</button></div>
	<h2>Road grading</h2>${range(
		'Width · m'
		, 'roadWidth'
		, 2
		, 80
		, 1
		, 8
	)}${range(
		'Shoulder · m'
		, 'roadShoulder'
		, 1
		, 120
		, 1
		, 12
	)}${range(
		'Offset · m'
		, 'roadRaise'
		, -100
		, 100
		, 1
		, 0
	)}
	<p class="terrain-note">Choose Road control points, then click along a route. Offset raises or cuts each new point.</p><p class="terrain-note" data-road-count>0 control points</p>
	<div class="terrain-row"><button data-action="road-apply">Apply road</button><button data-action="road-clear">Clear points</button></div>
	<h2>Sun & atmosphere</h2>${range(
		'Sun azimuth'
		, 'azimuth'
		, 0
		, 360
		, 1
		, 125
	)}${range(
		'Sun elevation'
		, 'elevation'
		, -12
		, 88
		, 1
		, 35
	)}${range(
		'Haze'
		, 'haze'
		, 0
		, 5
		, .1
		, 1
	)}${range(
		'Exposure'
		, 'exposure'
		, .1
		, 4
		, .1
		, 1
	)}
	${range('Night glow', 'nightGlow', 0, 1, .01, .5)}
	<label class="terrain-check"><input data-value="clock" type="checkbox">Animate the sun</label>
	<h2>Water</h2>
	<label class="terrain-field">Water level · m<input data-value="waterLevel" type="number" min="-10000" max="10000" step="any" value="0"></label>
	${range('Choppiness · %', 'waterChoppiness', 0, 300, 1, 100)}
	<label class="terrain-control"><span>Water color</span><input data-value="waterColor" type="color" value="#287276"></label>
	${range('Transparency', 'waterTransparency', 0, 1, .01, .85)}
	${range('Reflectivity', 'waterReflectivity', 0, 1, .01, .85)}
	${range('Fog density · coarse', 'waterFogDensity', 0, 1, .005, .035)}
	<label class="terrain-control"><span>Fog density · fine</span><input data-fine-fog type="range" min=".030" max=".040" step=".0001" value=".035"><output>0.035</output></label>
	${range('Shore and wake foam', 'waterFoam', 0, 1, .01, .65)}
	<p class="terrain-note">Lower fog density to see the bottom in deeper water.</p>
	<h2>Explore</h2>${range('Flight · m/s', 'speed', 5, 300, 5, 100)}
	<div class="terrain-row"><button data-action="car">Drive car · C</button><button data-action="craft">Board watercraft · B</button><button data-action="jet">Fly F5 · J</button><button data-action="fly" hidden>Return to flight · F</button></div>
	<div class="terrain-row"><button data-action="pause">Pause · P</button><button data-action="fullscreen">Fullscreen</button></div>
	<label class="terrain-field">Jet controls<select data-value="jetControls"><option value="novice" selected>Novice · bank to turn, levels itself</option><option value="expert">Expert · roll, pitch and rudder</option></select></label>
	<label class="terrain-check"><input data-value="invertPitch" type="checkbox">Push the stick forward to climb</label>
	<p class="terrain-note">Right-drag: look · WASD: fly or drive · Q/E: down/up · Shift: 300 m/s · Space: handbrake · H: hide controls. Pause freezes waves and physics; editing remains available.</p>
	<p class="terrain-note">Gamepad: left stick flies, steers or banks · right stick looks · RT/LT climb or descend, accelerate or brake · A: handbrake · LB/RB: Expert rudder · D-pad: ↑ jet, ← car, → watercraft, ↓ free flight · Start: pause · Back: Novice or Expert.</p>
	<div class="terrain-row"><button data-action="save">Save file</button><button data-action="load">Load file</button></div><input data-file type="file" accept=".json,application/json" hidden>
	<p class="terrain-note">Watercraft adapted from <a href="https://sketchfab.com/3d-models/jetski-15e79c65f67a468592cdc2ac1f5b389b" target="_blank" rel="noopener">Jetski by Futurealiti</a>  · Car adapted from <a href="https://sketchfab.com/3d-models/ferrari-testarossa-84-low-poly-2eb128eab34a4c25bfcdb2d75152d31a" target="_blank" rel="noopener">Ferrari-Testarossa-84-low-poly by XGLOL235</a> · Aircraft adapted from <a href="https://sketchfab.com/3d-models/f5-8089d66a8a8748b2a4b049b3c06f6a02" target="_blank" rel="noopener">F5 by manilov.ap</a> · <a href="https://creativecommons.org/licenses/by/4.0/" target="_blank" rel="noopener">CC BY 4.0</a></p>
	</div></details><div class="terrain-hud"><p data-status role="status">Preparing the landscape…</p><p class="terrain-pending" data-pending>Preparing terrain…</p><pre data-diagnostics></pre></div>`;
}
