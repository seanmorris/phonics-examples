// Internal terrain coordinates and saved files use SI. These conversions apply at the UI boundary.
const FOOT = .3048, YARD = 3 * FOOT, MILE = 5280 * FOOT, MPH = .44704, KNOT = 1852 / 3600;
const metricPerUnit = {ft: FOOT, mph: MPH, kts: KNOT, cyclesPerMile: 1/MILE, densityPerMile: 1000/MILE};
const exactValues = new WeakMap();
const fieldBounds = new WeakMap();

function distanceLabel(meters, digits = 1)
{
	return Math.abs(meters) < MILE/4 ? Math.round(meters/FOOT) + ' ft' : (meters/MILE).toFixed(digits) + ' mi';
}

function rangeYards(meters)
{
	return Math.round(meters/YARD) + ' yd';
}

function displayNumber(value, unit)
{
	return Number((value / metricPerUnit[unit]).toPrecision(7));
}

// Give native keyboard and spinner controls a useful increment while accepting finer authored numbers.

function updateIncrement(field)
{
	const increment = Number(field.dataset.increment);

	if(field.type !== 'number' || !increment) return;

	field.step = increment;

	for(let n = 1; field.validity.stepMismatch && n <= 7; n++)
		field.step = Number((increment / 10 ** n).toPrecision(10));

	if(field.validity.stepMismatch) field.step = 'any';
}

function setFieldValue(field, value, preset = false)
{
	const unit = field.dataset.unit;

	if(!unit || value == null || value === '')
	{
		field.value = value ?? '';
		updateIncrement(field);

		return;
	}

	const imperial = displayNumber(value, unit);
	field.value = imperial;
	field.removeAttribute('title');

	if(preset)
	{
		field.value = unit === 'ft' && Math.abs(imperial) >= 10
			? Math.round(imperial)
			: Number(imperial.toPrecision(3));

		if(field.validity.rangeUnderflow || field.validity.rangeOverflow) field.value = imperial;

		if(field.valueAsNumber !== imperial)
			field.title = `Preset value ≈ ${imperial} ${unit === 'cyclesPerMile' ? 'cycles/mi' : unit}`;
	}

	updateIncrement(field);
	// Reapplying a preset or editing a sibling field must preserve the exact authored value.
	exactValues.set(field, {display: field.value, metric: value});
}

function metricValue(field)
{
	const unit = field.dataset.unit;

	if(!unit) return Number(field.value);

	const original = exactValues.get(field);

	if(original?.display === field.value) return original.metric;

	const bounds = fieldBounds.get(field);

	if(bounds && field.value === field.min) return bounds.min;

	if(bounds && field.value === field.max) return bounds.max;

	return Number((field.valueAsNumber * metricPerUnit[unit]).toPrecision(14));
}

function configureUnits(container, preset = false)
{
	for(const field of container.querySelectorAll(
		'input[type=number][data-increment]:not([data-unit]), input[type=number][data-increment][data-unit=""]'
	))
		updateIncrement(field);

	for(const field of container.querySelectorAll('input[data-unit]:not([data-unit=""])'))
	{
		const unit = field.dataset.unit, value = field.getAttribute('value');
		fieldBounds.set(field, {min: Number(field.min), max: Number(field.max)});
		field.step = 'any';

		if(field.min !== '') field.min = displayNumber(field.min, unit);

		if(field.max !== '') field.max = displayNumber(field.max, unit);

		if(value !== null) setFieldValue(field, Number(value), preset);
	}
}

export const terrainUnits = {FOOT, MPH, KNOT, distanceLabel, rangeYards, configureUnits, setFieldValue, metricValue};

/** The SDL menu selects by display title. The shared playlist identifies each recording by its ID. */
function musicMenu(music)
{
	const choices = new Map([['Off', null]]);

	function trackChoices()
	{
		if(choices.size === 1)
		{
			for(const track of music.tracks)
			{
				let title = track.title || track.id;

				while(choices.has(title)) title += ' (' + track.id + ')';

				choices.set(title, track.id);
			}
		}

		return choices;
	}

	return {

		get(name) {
			if(name === 'music') return Math.round(music.volume*100);

			if(name === 'tracks') return JSON.stringify([...trackChoices().keys()]);

			if(name === 'track' && !music.playbackPaused)
			{
				const id = music.playlistTrack?.id;

				for(const [title, trackId] of trackChoices()) if(trackId === id) return title;
			}

			return name === 'track' ? 'Off' : null;
		}

		, set(name, value) {
			if(name === 'track')
			{
				if(value !== 'Off' && !music.select(trackChoices().get(value))) return false;

				music.setPlaybackPaused(value === 'Off');

				return true;
			}

			if(!Number.isFinite(value) || value < 0 || value > 100) return false;

			if(name === 'master') music.setMasterVolume((value/100)**2);
			else if(name === 'music') music.setVolume(value/100);
			else return false;

			return true;
		}
	};
}

/** Authoring UI and lifecycle bridge; PHP owns terrain, camera, edits and simulation. */
export function activate({module, canvas, session, services})
{
	const shell = new services.GameShell({module, canvas, session, title: 'Landscape Sandbox'});
	const gl = shell.context({alpha: false, antialias: true, depth: true, stencil: false});
	const {signal} = shell;
	const frame = document.createElement('div');
	frame.className = 'terrain-sandbox';
	frame.innerHTML = markup();
	configureUnits(frame);

	frame.addEventListener('input', ({target}) => {
		if(target.type !== 'number') return;

		exactValues.delete(target);
		target.removeAttribute('title');
		updateIncrement(target);
	}, {capture: true, signal});

	module.terrainUnits = terrainUnits;

	const stage = frame.querySelector('.terrain-stage');
	shell.mount(frame, {screen: stage});
	canvas.style.cssText = 'display:block;width:100%;height:100%;max-width:none;max-height:none;border:0;outline:none';

	canvas.setAttribute(
		'aria-label'
		, 'Landscape Sandbox. Right-drag, or drag a finger, to look; WASD to fly; Q and E down and up. A gamepad also flies and drives.'
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
	const race = frame.querySelector('[data-race]');
	let finishTimer = null;

	const credit = frame.querySelector('[data-music-credit]');
	const cover = credit.querySelector('img');
	let creditTimer;
	cover.addEventListener('error', () => { cover.hidden = true; }, {signal});

	function showCredit(track)
	{
		clearTimeout(creditTimer);
		credit.querySelector('strong').textContent = track.title || track.id;
		credit.querySelector('[data-artist]').textContent = track.artist || '';
		credit.querySelector('[data-album]').textContent = track.album || '';
		cover.hidden = !track.cover;

		if(track.cover) cover.src = track.cover;
		else cover.removeAttribute('src');

		credit.hidden = false;
		creditTimer = setTimeout(() => { credit.hidden = true; }, 6000);
	}

	canvas.addEventListener('phonics-music-track', ({detail}) => showCredit(detail), {signal});

	const music = session.backgroundMusic;
	module.terrainMusic = musicMenu(music);
	const musicPreferences = new services.SafeStorage();
	const musicOffKey = 'terrain-sandbox.music.off';
	let rememberedPause = musicPreferences.get(musicOffKey) === '1';

	if(rememberedPause) music.setPlaybackPaused(true);

	const musicLevel = frame.querySelector('[data-music-level]');

	musicLevel.addEventListener('input', () => music.setVolume(musicLevel.valueAsNumber/100), {signal});

	const playMusic = frame.querySelector('[data-music-play]');
	const pauseMusic = frame.querySelector('[data-music-pause]');

	const showMusicState = () => {
		musicLevel.value = String(Math.round(music.volume*100));
		playMusic.setAttribute('aria-pressed', String(!music.playbackPaused));
		pauseMusic.setAttribute('aria-pressed', String(music.playbackPaused));

		if(rememberedPause !== music.playbackPaused)
		{
			rememberedPause = music.playbackPaused;
			musicPreferences.set(musicOffKey, rememberedPause ? '1' : '0');
		}
	};

	canvas.addEventListener('phonics-music-controls', showMusicState, {signal});

	for(const [button, paused] of [[playMusic, false], [pauseMusic, true]])
	{
		button.addEventListener('click', () => music.setPlaybackPaused(paused), {signal});
	}

	showMusicState();

	for(const [selector, skip] of [['[data-music-prev]', music.previous], ['[data-music-next]', music.next]])
	{
		frame.querySelector(selector).addEventListener('click', () => {
			const track = skip();

			if(music.playbackPaused && track && track.credit !== false) showCredit(track);
		}, {signal});
	}

	const dismissFinish = () => {
		finishTimer = null;
		updateRace(race, latest);
	};

	const controls = new Map([...frame.querySelectorAll('[data-value]')].map(field => [field.dataset.value, field]));

	// The controls shown are for the device used last: the keyboard and mouse, a touchscreen, or a pad by its maker.
	let inputTheme = globalThis.matchMedia?.('(pointer: coarse)').matches ? 'touch' : 'keyboard';
	values.inputTheme = inputTheme;

	const useTheme = theme => {
		if(theme === inputTheme) return;

		inputTheme = theme;
		values.inputTheme = theme;
	};

	// Fog density spans four orders of magnitude, so its slider is logarithmic: position 0 is clear water, 1 is one per
	// meter, and the usual 0.035 sits near the middle.
	const fog = frame.querySelector('[data-fog]');
	const fogOutput = frame.querySelector('[data-fog-output]');

	const fogDensity = position => (1000 ** position - 1) / 999;

	const fogPosition = density => Math.log1p(999 * density) / Math.log(1000);

	const showFog = density => {
		fogOutput.textContent = String(Number((density * FOOT).toPrecision(3)));
		fog.setAttribute('aria-valuetext', fogOutput.textContent + ' per foot');
	};

	fog.addEventListener('input', () => {
		values.waterFogDensity = Number(fogDensity(fog.valueAsNumber).toPrecision(3));
		showFog(values.waterFogDensity);
	}, {signal});

	const button = name => frame.querySelector(`[data-action="${name}"]`);

	const push = action => { actions.push(typeof action === 'string' ? {type: action} : action); };

	const focus = () => canvas.focus({preventScroll: true});

	const focusedField = target => target instanceof HTMLElement && Boolean(target.closest('input,select,textarea'));

	// These fields send nothing while their text is out of range, and return to the last value sent when left so.
	const checked = new Set(['waterLevel'
		, 'gateLaps'
		, 'gateAltitude'
		, 'gateWidth'
		, 'gateHeight'
		, 'balloonAltitude'
		, 'circuitRelief', 'circuitStartHeight'
	]);

	const gateTool = frame.querySelector('[data-gate-tool]');
	const circuitMap = frame.querySelector('[data-circuit-map]');
	const circuitSummary = frame.querySelector('[data-circuit-summary]');
	let lastCircuit = '';

	const showToolControls = () => {
		gateTool.hidden = controls.get('tool').value !== 'gate';
		frame.querySelector('[data-balloon-tool]').hidden = controls.get('tool').value !== 'balloon';
		frame.querySelector('[data-road-tool]').hidden = controls.get('tool').value !== 'road';
	};

	const listen = field => {

		const blankStart = () => field.dataset.value === 'circuitStartHeight'
			&& field.value === '' && !field.validity.badInput;

		field.addEventListener('input', () => {
			if(checked.has(field.dataset.value))
			{
				if(!blankStart() && (!Number.isFinite(field.valueAsNumber) || !field.validity.valid)) return;

				field.defaultValue = field.value;
			}

			values[field.dataset.value] = blankStart()
				? null
				: field.type === 'checkbox'
					? field.checked
					: field.type === 'range' || field.type === 'number' ? metricValue(field) : field.value;

			const output = frame.querySelector(`[data-output="${field.dataset.value}"]`);

			if(output) output.textContent = readout(field.value);

			if(field.dataset.value === 'tool') showToolControls();
		}, {signal});

		if(checked.has(field.dataset.value))
		field.addEventListener('blur', () => {
			if(!blankStart() && (!Number.isFinite(field.valueAsNumber) || !field.validity.valid))
			field.value = field.defaultValue;
		}, {signal});
	};

	for(const field of controls.values()) listen(field);

	// Tabs: a click or the arrow, Home and End keys select one, and the page remembers the last for next time.
	const tabs = [...frame.querySelectorAll('[role="tab"]')];

	const selectTab = (tab, moveFocus = false) => {
		for(const other of tabs)
		{
			const selected = other === tab;

			other.setAttribute('aria-selected', String(selected));
			other.tabIndex = selected ? 0 : -1;
			frame.querySelector(`#${other.getAttribute('aria-controls')}`).hidden = !selected;
		}

		frame.querySelector('.lab-pages').scrollTop = 0;

		if(moveFocus) tab.focus();

		try
		{
			localStorage.setItem(TAB_PREFERENCE, tab.dataset.tab);
		}
		catch
		{
			/* Without storage the panel opens on its first tab next time. */
		}
	};

	for(const tab of tabs) tab.addEventListener('click', () => selectTab(tab), {signal});

	frame.querySelector('[role="tablist"]').addEventListener('keydown', event => {
		const index = tabs.indexOf(event.target);

		if(index < 0) return;

		const next = {ArrowRight: index + 1, ArrowLeft: index - 1, Home: 0, End: tabs.length - 1}[event.code];

		if(next === undefined) return;

		selectTab(tabs[(next + tabs.length) % tabs.length], true);
		event.preventDefault();
		event.stopPropagation();
	}, {signal});

	try
	{
		const remembered = tabs.find(tab => tab.dataset.tab === localStorage.getItem(TAB_PREFERENCE));

		if(remembered) selectTab(remembered);
	}
	catch
	{
		/* An unreadable preference leaves the first tab open. */
	}

	let surface = null;
	const materials = materialEditor(frame, push, status);
	const air = atmosphereEditor(frame, push);

	shell.bridge('terrainSurface', json => {
		surface = JSON.parse(json);
		materials.configure(surface);
		air.configure(surface.air);
	});

	for(const field of frame.querySelectorAll('[data-action]'))
	{
		field.addEventListener('click', () => {
			const type = field.dataset.action;

			if(type === 'generate') push({type, ...generationValues(frame)});
			else if(type === 'journey-build')
			{
				const value = key => journeyField(key).value;
				const destinations = [value('east')];
				if(value('fork') === 'divide') destinations.push(value('south'));
				const network = value('fork') === 'network' ? {network: value('network')} : {};
				journeyRequest = 'queued';
				journeyResult = '';
				field.disabled = true;
				field.textContent = 'Building journey…';
				journeyProgress.textContent = 'Surveying the highway. You’ll start driving when the road is ready.';
				push({type, west: value('west'), destinations, span: Number(value('span')), ...network});
			}
			else if(type === 'load') frame.querySelector('[data-file]').click();
			else if(type === 'fullscreen')
			void fullscreen.toggle().catch(error => {
				status.textContent = error.message;
			});
			else push(type);

			if(type !== 'load') focus();
		}, {signal});
	}

	const journeyField = key => frame.querySelector(`[data-journey="${key}"]`);
	const junction = journeyField('fork');
	const destinationNames = new Map([...journeyField('east').options].map(option => [option.value, option.text]));
	const networkField = journeyField('network');
	const journeyProgress = frame.querySelector('[data-journey-progress]');
	const guideBody = frame.querySelector('[data-journey-guide] tbody');
	const guideError = frame.querySelector('[data-journey-error]');
	let activeNetwork = null, preview = null, pendingDraft = null, currentRow = null;
	let journeyRequest = null, journeyResult = '';
	const squareName = (id, nodes) => id + ' · ' + destinationNames.get(nodes[id].preset);
	const showRoute = result => {
		preview = result;
		guideError.textContent = result?.error ?? '';
		guideBody.closest('table').hidden = !result?.nodes;
		guideBody.replaceChildren();
		currentRow = null;
		if(!result?.nodes) return;
		for(const [id, node] of Object.entries(result.nodes)) {
			const row = guideBody.insertRow();
			row.dataset.square = id;
			const title = document.createElement('th');
			title.scope = 'row';
			title.textContent = squareName(id, result.nodes);
			row.append(title);
			const exits = [...new Set(node.exits)];
			if(exits.length === 2) for(const exit of exits) row.insertCell().textContent = exit;
			else {
				const cell = row.insertCell();
				cell.colSpan = 2;
				cell.textContent = exits.length ? 'Both roads to ' + exits[0] : 'End of route';
			}
		}
	};
	const requestPreview = () => {
		if(junction.value !== 'network' || preview?.plan === networkField.value) return;
		if(activeNetwork?.plan === networkField.value) showRoute(activeNetwork);
		else {
			showRoute(null);
			button('journey-build').disabled = true;
			pendingDraft = networkField.value;
		}
	};
	shell.bridge('terrainJourneyPreview', json => {
		const result = JSON.parse(json);
		if(result.plan === networkField.value) showRoute(result);
	});
	networkField.addEventListener('input', requestPreview, {signal});
	const updateJunction = () => {
		frame.querySelector('[data-journey-south]').hidden = junction.value !== 'divide';
		frame.querySelector('[data-journey-network]').hidden = junction.value !== 'network';
		for(const field of ['west', 'east']) journeyField(field).closest('label').hidden = junction.value === 'network';
		requestPreview();
	};
	junction.addEventListener('change', updateJunction, {signal});
	updateJunction();

	const presetField = frame.querySelector('[name="preset"]');
	const seedField = frame.querySelector('[name="seed"]');
	let presetSeed = seedField.defaultValue;

	presetField.addEventListener('change', () => {
		const {seed, ...parameters} = surface.presets[presetField.value];

		if(seedField.value === '' || seedField.value === presetSeed) seedField.value = seed;

		presetSeed = seed;

		for(const [name, value] of Object.entries(parameters))
		setFieldValue(frame.querySelector(`[name="${name}"]`), value, true);
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
		['KeyW','KeyA','KeyS','KeyD','KeyQ','KeyE','KeyZ','KeyX','KeyG','Space','ShiftLeft','ShiftRight']
	);

	const commands = {
		KeyP: 'pause'
		, Escape: 'menu'
		, KeyF: 'fly'
		, KeyB: 'craft'
		, KeyC: 'car'
		, KeyJ: 'jet'
		, Home: 'reset-camera'
		, KeyR: 'race-reset'

		, KeyL: 'headlights'
		, KeyM: 'vehicle-map'
		, KeyN: 'vehicle-wide-map'
		, KeyV: 'jet-view'
		, KeyT: 'jet-target'
	};

	frame.addEventListener('keydown', event => {
		if(focusedField(event.target)) return;

		useTheme('keyboard');

		// Space still presses a focused button or link rather than the handbrake.
		const activates = event.code === 'Space' && event.target instanceof HTMLElement
			&& Boolean(event.target.closest('button,summary,a,label'));

		if(heldKeys.has(event.code) && !event.ctrlKey && !event.metaKey && !activates)
		{
			keys[event.code] = true;

			if(event.code === 'Space' && !event.repeat) push('jet-launch');

			event.preventDefault();
		}

		if((event.ctrlKey || event.metaKey) && event.code === 'KeyZ')
		{
			if(!event.repeat) push(event.shiftKey ? 'redo' : 'undo');

			event.preventDefault();
		}
		// With Ctrl, Cmd or Alt held the key belongs to the browser, so Cmd+R reloads rather than resetting the race.
		else if(commands[event.code] && !event.ctrlKey && !event.metaKey && !event.altKey)
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

	// A finger has no right button: a touch drag looks around, and a touch that ends without moving is a click.
	let tap = null;


	const click = (x, y) => {
		const box = canvas.getBoundingClientRect();
		clicks.push([(x - box.left) / box.width,(y - box.top) / box.height]);
	};

	canvas.addEventListener('pointerdown', event => {
		focus();
		const touch = event.pointerType === 'touch';
		useTheme(touch ? 'touch' : 'keyboard');

		if(event.button === 2 || touch)
		{
			dragging = event.pointerId;
			lastPointer = [event.clientX,event.clientY];
			canvas.setPointerCapture(event.pointerId);
		}

		if(touch) tap = {id: event.pointerId, x: event.clientX, y: event.clientY};
		else if(event.button === 0) click(event.clientX, event.clientY);

		event.preventDefault();
	}, {signal});

	canvas.addEventListener('pointermove', event => {
		if(dragging !== event.pointerId || !lastPointer) return;

		look[0] += event.clientX - lastPointer[0];
		look[1] += event.clientY - lastPointer[1];
		lastPointer = [event.clientX,event.clientY];
	}, {signal});

	const release = event => {
		const still = tap?.id === event.pointerId && Math.hypot(event.clientX - tap.x, event.clientY - tap.y) < 10;

		if(event.type === 'pointerup' && still) click(tap.x, tap.y);

		if(tap?.id === event.pointerId) tap = null;

		if(dragging !== event.pointerId) return;

		dragging = null;
		lastPointer = null;

		if(canvas.hasPointerCapture(event.pointerId)) canvas.releasePointerCapture(event.pointerId);
	};

	canvas.addEventListener('pointerup', release, {signal});
	canvas.addEventListener('pointercancel', release, {signal});

	// The renderer's statistics under the HUD are for developers: off unless this browser asked for them.
	const diagnostics = frame.querySelector('[data-show-diagnostics]');
	let lastDetail = null;

	const showDetail = (...args) => {
		lastDetail = args;
		const [state, mode, x, y, z] = args;

		const statistics = `\n${state.renderer.visible} terrain levels · `
			+ `${(state.renderer.terrainTextureBytes / 1048576).toFixed(1)} MiB elevation textures · `
			+ `${state.renderer.generationMs.toFixed(1)} ms generation · revision ${state.revision}\n`
			+ `${state.renderMs.toFixed(1)} ms render submission · `
			+ `${state.drawIntervalMs?.toFixed(1) ?? '—'} ms between draws`;

		detail.textContent = `${mode} · ${Math.round(x/FOOT)}, ${Math.round(z/FOOT)} ft`
			+ ` · altitude ${Math.round((state.jet?.y ?? y)/FOOT)} ft`
			+ (state.jet ? ` · ${Math.round(state.jet.speed/KNOT)} kts` : '')
			+ (diagnostics.checked ? statistics : '');
	};

	try
	{
		diagnostics.checked = localStorage.getItem(DIAGNOSTICS_PREFERENCE) === '1';
	}
	catch
	{
		/* Without storage the statistics stay hidden. */
	}

	diagnostics.addEventListener('change', () => {
		if(lastDetail) showDetail(...lastDetail);

		try
		{
			localStorage.setItem(DIAGNOSTICS_PREFERENCE, diagnostics.checked ? '1' : '0');
		}
		catch
		{
			/* The choice then lasts for this visit. */
		}
	}, {signal});

	// Jet handling is the player's preference, so it is remembered in this browser rather than in landscape files.
	const jetControls = controls.get('jetControls');
	const invertPitch = controls.get('invertPitch');

	let jetView = 'chase';
	const panel = frame.querySelector('.terrain-panel');
	let panelBeforeCockpit = true, panelBeforeMenu = false, menuPanel = false;
	const menuLive = frame.querySelector('[data-menu-live]');

	const rememberJet = () => {
		try
		{
			localStorage.setItem(
				JET_PREFERENCE
				, JSON.stringify({jetControls: jetControls.value, invertPitch: invertPitch.checked, jetView})
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

		if(['cockpit','chase'].includes(saved?.jetView)) values.jetView = jetView = saved.jetView;

		if(typeof saved?.invertPitch === 'boolean') values.invertPitch = invertPitch.checked = saved.invertPitch;
	}
	catch
	{
		/* A missing or unreadable preference leaves Novice with the stick unreversed. */
	}

	jetControls.addEventListener('change', rememberJet, {signal});
	invertPitch.addEventListener('change', rememberJet, {signal});

	// The headlight switch is also the player's, remembered here and left out of landscape files.
	const headlights = controls.get('headlights');

	const rememberHeadlights = () => {
		try
		{
			localStorage.setItem(CAR_PREFERENCE, JSON.stringify({headlights: headlights.value}));
		}
		catch
		{
			/* Storage can be unavailable; the choice then lasts for this visit. */
		}
	};

	try
	{
		const saved = JSON.parse(localStorage.getItem(CAR_PREFERENCE) ?? 'null');

		if(HEADLIGHTS.includes(saved?.headlights)) values.headlights = headlights.value = saved.headlights;
	}
	catch
	{
		/* A missing or unreadable preference leaves the headlights on Auto. */
	}

	headlights.addEventListener('change', rememberHeadlights, {signal});

	let previousViewport, previousKeys = '', previousPad = '', padButtons = new Set(), viewChord = false;
	let previousMenuPad = '';
	let backShortcut = false;

	shell.bridge('terrainInput', () => {
		const viewport = view.viewport();
		const held = Object.keys(keys).filter(key => keys[key]).join(',');
		// Browsers stop updating gamepads in a background page, so a stick held at blur must not stay held.
		const gamepad = document.hidden || !document.hasFocus() ? null : readGamepads();

		if(gamepad?.active) useTheme(padTheme(gamepad.active));

		const buttons = gamepad?.buttons ?? new Set();

		if(buttons.has(8) && !padButtons.has(8)) backShortcut = false;

		for(const [index, command] of PAD_COMMANDS)
		{
			if(!buttons.has(index) || padButtons.has(index)) continue;

			if(buttons.has(8) && (index === 13 || index === 3))
			{
				push(index === 13 ? 'fly' : 'race-reset');
				backShortcut = true;
			}
			else push(command);
		}

		if(gamepad && !buttons.has(8) && padButtons.has(8) && !backShortcut) push('jet-controls');

		// Y+B changes perspective once, including when the second button arrives a poll later.
		// Keep its throttle buttons released until both are up again.
		if(!buttons.has(1) && !buttons.has(3)) viewChord = false;

		if(!buttons.has(8) && buttons.has(1) && buttons.has(3) && !viewChord)
		{
			push('jet-view');
			viewChord = true;
		}

		padButtons = buttons;
		const heldMask = PAD_HELD.reduce((mask, [index, bit]) => buttons.has(index) ? mask | bit : mask, 0);
		const pad = gamepad
			? [...gamepad.axes.map(value => Math.round(value * 1000) / 1000)
				, viewChord || buttons.has(8) ? heldMask & ~SPEED_BUTTONS : heldMask
			]
			: [0,0,0,0,0,0,0];

		const padKey = pad.join(',');

		// The pause menu reads the pad from here too.
		// The D-pad or left stick moves, A chooses, B goes back and Start leaves.
		const [x, y] = gamepad?.axes ?? [0, 0];
		const up = buttons.has(12) || y < -.5, down = buttons.has(13) || y > .5;
		const left = buttons.has(14) || x < -.5, right = buttons.has(15) || x > .5;
		const menuPad = [up, down, left, right, buttons.has(0), buttons.has(1), buttons.has(9)].map(Number);
		const menuPadKey = menuPad.join(',');
		const padsSame = padKey === previousPad && menuPadKey === previousMenuPad;

		if(viewport === previousViewport && held === previousKeys && padsSame && !actions.length
			&& pendingDraft === null && !clicks.length && !look[0] && !look[1] && !Object.keys(values).length
		) return '';

		const packet = {keys,values,actions,clicks,look};
		if(pendingDraft !== null) {
			packet.journeyDraft = pendingDraft;
			pendingDraft = null;
		}
		if(journeyRequest === 'queued' && actions.some(action => action.type === 'journey-build')) journeyRequest = 'sent';

		if(viewport !== previousViewport) packet.viewport = JSON.parse(viewport);

		if(padKey !== previousPad) packet.pad = pad.some(value => value !== 0) ? pad : [];

		if(menuPadKey !== previousMenuPad) packet.menuPad = menuPad;

		previousPad = padKey;
		previousMenuPad = menuPadKey;
		previousKeys = held;
		previousViewport = viewport;
		values = {};
		actions = [];
		clicks = [];
		look = [0,0];

		return JSON.stringify(packet);
	});

	// The pause menu's Landscape Lab row opens the panel; Quit leaves for the examples index the header links to.
	shell.bridge('terrainPage', command => {
		if(command === 'lab')
		{
			menuPanel = true;
			panel.open = true;
		}
		else if(command === 'quit') location.assign(new URL('../../', location.href));
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

			// A vehicle retains its result on reboarding. Celebrate the crossing once, on the ride that made it.
			const finished = state.race.state === 'finished' && !state.delivery && state.mode === state.race.vehicle
				&& latest?.mode === state.mode && latest.generation === state.generation;

			if(finished && (latest.race.state !== 'finished' || latest.race.time !== state.race.time))
			{
				clearTimeout(finishTimer);
				finishTimer = setTimeout(dismissFinish, 4000);

				session.backgroundMusic.push({id: 'terrain-race-finish', src: 'music/race-finish.wav', credit: false});
			}
			else if(!finished && finishTimer !== null)
			{
				clearTimeout(finishTimer);
				finishTimer = null;
			}

			const quietPause = Boolean(state.paused && !state.menu);

			if(quietPause !== Boolean(latest?.paused && !latest.menu)) music.pause(quietPause);

			latest = state;
			if('journeyNetwork' in state) activeNetwork = state.journeyNetwork;
			if(activeNetwork && state.journeySquare && state.generation !== lastGeneration) {
				networkField.value = activeNetwork.plan;
				junction.value = 'network';
				showRoute(activeNetwork);
				updateJunction();
			}
			const location = state.journeyArrival ?? state.journeySquare;
			if(currentRow?.dataset.square !== location || preview?.plan !== activeNetwork?.plan) {
				currentRow?.removeAttribute('aria-current');
				currentRow = location && activeNetwork && preview?.plan === activeNetwork.plan
					? guideBody.querySelector('[data-square="' + CSS.escape(location) + '"]') : null;
				currentRow?.setAttribute('aria-current','location');
			}
			module.updateTerrainHints(state);

			if(jetView !== state.jetView)
			{
				jetView = state.jetView;
				rememberJet();
			}

			frame.querySelector('[data-jet-actions]').hidden = state.mode !== 'jet';
			button('jet-view').textContent = jetView === 'chase' ? 'Cockpit · V' : 'Chase view · V';
			const cockpit = state.mode === 'jet' && jetView === 'cockpit';

			if(frame.classList.contains('in-cockpit') !== cockpit)
			{
				if(cockpit)
				{
					panelBeforeCockpit = panel.open;
					panel.open = false;
				}
				else panel.open = panelBeforeCockpit;

				frame.classList.toggle('in-cockpit', cockpit);
			}

			materials.show(state);
			air.show(state);

			// The pause menu draws in the canvas, so screen readers hear its selected row from here.
			const menuRow = state.menuRow ? state.menuRow.page + ': ' + state.menuRow.label : '';

			if(menuLive.textContent !== menuRow) menuLive.textContent = menuRow;

			// The page's own overlays step aside while the menu is open.
			if(Boolean(state.menu) !== frame.classList.contains('terrain-menu-open'))
			{
				frame.classList.toggle('terrain-menu-open', Boolean(state.menu));

				if(state.menu)
				{
					panelBeforeMenu = panel.open;
					menuPanel = false;
					panel.open = false;
				}
				else if(!menuPanel) panel.open = panelBeforeMenu;
			}

			if(typeof state.invertPitch === 'boolean' && invertPitch.checked !== state.invertPitch
				&& document.activeElement !== invertPitch
			){
				invertPitch.checked = state.invertPitch;
				rememberJet();
			}

			const speed = controls.get('speed');

			// The pause menu can set flight speed too. Within half a slider step the field already shows it.
			if(state.settings?.speed && document.activeElement !== speed
				&& Math.abs(metricValue(speed) - state.settings.speed) > 2.5 * KNOT
			){
				setFieldValue(speed, state.settings.speed);
			}

			// Back on a gamepad switches the handling in PHP. Show it here and remember it.
			if(state.jetControls && jetControls.value !== state.jetControls && document.activeElement !== jetControls)
			{
				jetControls.value = state.jetControls;
				rememberJet();
			}

			// L and X switch the headlights in PHP. Show and remember that too.
			if(state.headlights && headlights.value !== state.headlights && document.activeElement !== headlights)
			{
				headlights.value = state.headlights;
				rememberHeadlights();
			}

			if(state.car?.roadHold > 0)
			{
				const reason = state.car.floodedHold ? 'Engine flooded' : 'Back on the road';
				status.textContent = `${reason} · controls in ${state.car.roadHold.toFixed(1)} s`;
			}
			else if(state.car?.motion === 'overturned') status.textContent = 'Car overturned. Recovering…';
			else if(state.car?.motion === 'tumbling') status.textContent = 'Car tumbling…';
			else if(state.cpuDriver && state.race.state !== 'finished')
			status.textContent = 'CPU racer · ' + state.cpuDriver;
			else if(state.mode === 'car' && state.journeyForkAhead && !state.journeySquare && !state.delivery && state.race.state === 'idle')
			{
				const [north, south] = state.recipe.join.destinations.map((form, i) =>
					[state.journeyExits?.[i], destinationNames.get(form.preset)].filter(Boolean).join(' · '));
				status.textContent = south ? `Left · ${north}   /   Right · ${south}` : `Both branches rejoin toward ${north}.`;
			}
			else status.textContent = state.message;

			const sign = frame.querySelector('[data-journey-sign]');
			const showSign = activeNetwork && state.journeySquare && state.mode === 'car' && !state.delivery
				&& !state.cpuDriver && state.race.state === 'idle';
			sign.hidden = !showSign;
			if(showSign) {
				const name = id => squareName(id, activeNetwork.nodes);
				const here = name(state.journeySquare);
				const [left, right] = [...new Set(state.journeyExits)];
				let text;
				if(state.journeyArrival) text = 'Arrived at ' + name(state.journeyArrival) + ' · End of route';
				else if(state.journeyBranch) text = here + ' → ' + name(state.journeyBranch);
				else if(right) text = here + ' — Left to ' + name(left) + ' / Right to ' + name(right);
				else text = here + ' — Both roads to ' + name(left);
				if(sign.textContent !== text) sign.textContent = text;
			}

			status.hidden = state.race.state === 'finished' && status.textContent === state.race.message;

			button('pause').textContent = state.paused ? 'Resume · P' : 'Pause · P';
			button('craft').hidden = state.mode === 'craft';
			button('jet').hidden = state.mode === 'jet';
			button('car').hidden = state.mode === 'car';
			button('car').disabled = state.findingCarSite;
			button('cpu-demo').hidden = !state.canWatchDriver && !state.cpuDriver;
			button('cpu-demo').textContent = state.cpuDriver ? 'Stop CPU demo' : 'Watch CPU racer';

			button(
				'delivery'
			).disabled = Boolean(state.cpuDriver) || state.findingCarSite || state.race.state === 'running';

			button('delivery').textContent = state.delivery ? 'End package run' : 'Package run';
			button('fly').hidden = state.mode === 'fly' && !state.findingCarSite;

			const levelsPending = state.renderer.pending;
			pending.hidden = !state.preparing && !state.picking && !state.findingCarSite && levelsPending === 0;

			if(state.preparing) pending.textContent = 'Preparing terrain edit…';
			else if(state.findingCarSite) pending.textContent = 'Looking for level ground…';
			else if(state.picking) pending.textContent = 'Finding the ground…';
			else if(state.renderer.visible)
			pending.textContent = `Refining terrain · ${levelsPending} ${levelsPending
				=== 1
				? 'level'
				: 'levels'} remaining`;
			else pending.textContent = 'Preparing terrain…';

			for(const name of ['undo','redo','generate','load','save'])
		button(name).disabled = state.preparing || state.picking && ['undo','redo','save'].includes(name);
			const circuit = state.circuit;
			const editable = state.mode === 'fly' && !state.preparing && !state.picking && !circuit.searching;
			button('circuit-find').disabled = !editable;
			if(state.journeyBuildPhase) journeyRequest = 'building';
			else if(journeyRequest === 'sent' || journeyRequest === 'building') {
				journeyResult = state.generation !== lastGeneration && state.recipe.join && state.mode === 'car'
					? 'Road ready. Follow the gold highway, or choose Drive from fork to try the split.' : state.message;
				journeyRequest = null;
			}
			const progress = state.journeyBuildPhase ?? (journeyRequest ? 'Surveying the highway…' : journeyResult);
			if(journeyProgress.textContent !== progress) journeyProgress.textContent = progress;
			button('journey-build').textContent = journeyRequest ? 'Building journey…' : 'Build new journey';
			button('journey-build').disabled = !editable || Boolean(journeyRequest) || (junction.value === 'network' && !preview?.nodes);
			for(const key of ['journey-drive','journey-fork'])
			{
				button(key).hidden = !state.recipe.join;
				button(key).disabled = Boolean(journeyRequest) || state.preparing || state.picking;
			}
			button('circuit-cancel').hidden = !circuit.searching && !circuit.preview;
			button('circuit-cancel').disabled = state.preparing;
			button('circuit-dry').disabled = !editable || !circuit.points;
			button('circuit-flooded').disabled = !editable || circuit.floodedLevel === null;

			circuitSummary.textContent = circuit.searching
				? 'Searching the surrounding terrain…'
				: circuit.points
					? `${(circuit.length/MILE).toFixed(2)} mi · ${circuit.gates.length} gates`
					+ (circuit.featureArea === null
						? ''
						: ` · ${(circuit.featureArea/(MILE*MILE)).toFixed(
							1
						)} mi² ${circuit.heightDifference < 0 ? 'lake' : 'high ground'}`
					)
					+ (circuit.heightDifference === null
						? ''
						: ` · ${Math.abs(
							Math.round(circuit.heightDifference/FOOT)
						)} ft ${circuit.heightDifference < 0 ? 'below' : 'above'} the route`
					)
					+ (circuit.startHeight === null
						? ''
						: ` · road starts at ${(circuit.startHeight/FOOT).toFixed(
							1
						)} ft`
					)
					+ (circuit.gradeWidth === null
						? ''
						: ` · apply to grade a ${Math.round(circuit.gradeWidth/FOOT)} ft road`
					)
					+ (circuit.floodedLevel === null
						? ''
						: ` · flood level ${Math.round(
							circuit.floodedLevel/FOOT
						)} ft`
					)
					: '';

			const circuitKey = JSON.stringify(circuit.points);

			if(circuitKey !== lastCircuit)
			{
				drawCircuit(circuitMap, circuit);
				lastCircuit = circuitKey;
			}

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

			showDetail(state, mode, x, y, z);

			if(state.generation !== lastGeneration)
			{
				if(state.recipe.join)
				{
					const {span, destinations} = state.recipe.join;
					journeyField('west').value = state.recipe.preset;
					journeyField('span').value = String(span);
					junction.value = state.journeySquare ? 'network' : destinations.length === 2 ? 'divide' : 'rejoin';
					journeyField('east').value = destinations[0].preset;
					if(destinations[1]) journeyField('south').value = destinations[1].preset;
					updateJunction();
				}
				presetField.value = state.recipe.preset;
				presetSeed = surface.presets[state.recipe.preset].seed;
				const worldSize = frame.querySelector('[name="worldSize"]');
				const customSize = worldSize.querySelector('[data-loaded-size]');
				const size = String(state.recipe.join?.span ?? state.recipe.worldSize);

				customSize.hidden = [...worldSize.options].some(
					option => option !== customSize && option.value === size
				);

				if(!customSize.hidden)
				{
					customSize.value = size;
					customSize.textContent = distanceLabel(Number(size), 2) + ' per side';
				}

				worldSize.value = size;

				const defaults = surface.presets[state.recipe.preset];

				for(const key of Object.keys(defaults))
				setFieldValue(
					frame.querySelector(`[name="${key}"]`)
					, state.recipe[key]
					, state.recipe[key] === defaults[key]
				);

				for(const [name, value] of Object.entries(state.settings))
				{
					const field = controls.get(name);

					if(!field) continue;

					if(field.type === 'checkbox') field.checked = value;
					else setFieldValue(field, value, name === 'waterLevel' && value === defaults.waterLevel);

					if(checked.has(name)) field.defaultValue = field.value;

					const output = frame.querySelector(`[data-output="${name}"]`);

					if(output) output.textContent = readout(field.value);
				}

				fog.value = String(fogPosition(state.settings.waterFogDensity));
				showFog(state.settings.waterFogDensity);

				showToolControls();
				lastGeneration = state.generation;
			}

			updateRace(race, state, finishTimer !== null);
			updateGateTool(gateTool, state);
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
		clearTimeout(finishTimer);
		clearTimeout(creditTimer);
		clearInput();

		if(session.terrainSandbox === api) delete session.terrainSandbox;

		delete module.terrainUnits;
		delete module.terrainMusic;
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

/** Draw a plan view of the circuit with a larger mark at the start/finish. */
function drawCircuit(canvas, circuit)
{
	canvas.hidden = !circuit.points;

	if(canvas.hidden) return;

	const xs = circuit.points.map(point => point[0]), zs = circuit.points.map(point => point[1]);
	const minX = Math.min(...xs), maxX = Math.max(...xs), minZ = Math.min(...zs), maxZ = Math.max(...zs);
	const w = canvas.width, h = canvas.height;
	const scale = Math.min((w-24)/Math.max(1, maxX-minX), (h-24)/Math.max(1, maxZ-minZ));

	const point = (x, z) => [w/2+(x-(minX+maxX)/2)*scale,h/2+(z-(minZ+maxZ)/2)*scale];
	const ctx = canvas.getContext('2d');
	ctx.clearRect(0, 0, w, h);
	ctx.beginPath();
	circuit.points.forEach(([x,z], i) => i ? ctx.lineTo(...point(x, z)) : ctx.moveTo(...point(x, z)));
	ctx.closePath();
	ctx.lineWidth = 3;
	ctx.lineJoin = 'round';
	ctx.strokeStyle = '#efc275';
	ctx.stroke();

	circuit.gates.forEach(([x,z], i) => {
		ctx.beginPath();
		ctx.arc(...point(x, z), i ? 2 : 4, 0, 2*Math.PI);
		ctx.fillStyle = i ? '#eadbb8' : '#fff';
		ctx.fill();
	});
}

/**
 * Show the race clock, lap, next gate, last split and best time while the landscape has gates, and say when the
 * vehicle cannot reach the next gate's opening.
 * @param {HTMLElement} line The HUD line under the status message.
 * @param {object} state Latest status from PHP.
 * @param {boolean} showFinish Show the recorded crossing time during the post-race display.
 */
function updateRace(line, state, showFinish = false)
{
	const {course, race, delivery} = state;
	line.classList.toggle('terrain-finish', showFinish);

	if(showFinish)
	{
		line.hidden = false;

		if(line.textContent !== race.message) line.textContent = race.message;

		return;
	}

	if(delivery)
	{
		line.hidden = false;
		const leg = delivery.carrying ? 'Drop-off' : 'Pickup';
		const distance = delivery.target && state.car
			? ' · ' + rangeYards(Math.hypot(delivery.target.x-state.car.x, delivery.target.z-state.car.z))
			: '';

		line.textContent = leg + distance + ' · Delivered ' + delivery.delivered;

		return;
	}

	line.hidden = !course?.gates.length || !race.guidance;

	if(line.hidden) return;

	const parts = [];

	if(race.state === 'running') parts.push(raceClock(race.time, 1), `Lap ${race.lap} of ${race.laps}`);
	else if(race.laps === null) parts.push('Place a start gate to race');
	else parts.push(race.laps === 1 ? '1 lap' : `${race.laps} laps`);

	const next = race.next && course.gates.find(gate => gate.id === race.next.id);

	if(next)
	{
		const from = state.car ?? state.craft ?? state.jet;
		const [x, y, z] = from ? [from.x, from.y, from.z] : state.camera.position;
		const meters = Math.hypot(next.x - x, next.y - y, next.z - z);
		const away = rangeYards(meters);
		parts.push(`Next: ${gateName(race.next, race)}, ${away}`);
	}

	if(race.state === 'running' && race.lastSplit !== null) parts.push(`Split ${raceClock(race.lastSplit)}`);

	if(race.best !== null) parts.push(`Best ${raceClock(race.best)}`);

	// Why the vehicle cannot pass the next gate follows the race figures.
	line.textContent = parts.join(' · ') + (race.reach ? ` · ${race.reach.message}` : '');
}

/**
 * Show the opening sizes each kind of gate takes, and whether the course runs laps.
 * @param {HTMLElement} tool The gate tool's controls.
 * @param {object} state Latest status from PHP.
 */
function updateGateTool(tool, state)
{
	const sizes = tool.querySelector('[data-gate-sizes]');

	if(state.gateSizes && !sizes.textContent)
	{
		const lines = Object.entries(state.gateSizes).map(([kind, size]) => gateSizeLine(kind, size));

		sizes.textContent = lines.join('\n');
	}

	tool.querySelector('[data-gate-laps]').textContent = lapNote(state.course);
}

/**
 * Describe the opening sizes of one kind of gate.
 * @param {string} kind road, water or air.
 * @param {{width: number, height: number, widths: number[], heights: number[], stretch: ?number}} size Its own size
 * and ranges in meters, from the status's gateSizes.
 * @returns {string} For example The dimensions in feet and the maximum stretch ratio, if applicable.
 */
function gateSizeLine(kind, size)
{
	const name = kind[0].toUpperCase() + kind.slice(1);
	const wide = `${roundFeet(size.widths[0])} to ${roundFeet(size.widths[1])} ft wide`;
	const tall = `${roundFeet(size.heights[0])} to ${roundFeet(size.heights[1])} ft tall`;
	const own = `its own ${roundFeet(size.width)} by ${roundFeet(size.height)} ft`;

	if(!size.stretch) return `${name}: ${wide}, ${tall}, ${own}.`;

	return `${name}: ${wide}, ${tall}, ${own}, stretching at most ${readout(size.stretch)} times more one way.`;
}

/**
 * Round a length for the gate tool's notes.
 * @param {number} value Meters.
 * @returns {string} The length to a tenth of a foot, without a trailing zero.
 */
function roundFeet(value)
{
	return String(Number((value/FOOT).toFixed(1)));
}

/**
 * Say whether a course runs the laps its start gate carries.
 * @param {?{laps: ?number, gates: object[]}} course The status's course.
 * @returns {string} The note under the Laps field.
 */
function lapNote(course)
{
	const gates = course?.gates ?? [];
	const start = gates.find(gate => gate.role === 'start');

	if(!start) return 'Laps count when the start gate is also the finish.';

	const finish = gates.some(gate => gate.role === 'finish');

	if(finish) return 'This course runs once, from its start gate to its finish gate.';

	return `This course runs ${course.laps} ${course.laps === 1 ? 'lap' : 'laps'} through its start / finish gate.`;
}

/**
 * Name the gate a race needs next.
 * @param {{role: string, number: ?number}} gate The race status's next gate.
 * @param {{state: string, remaining: ?number}} race The race status.
 * @returns {string} For example checkpoint 2, finish gate or start gate to finish.
 */
function gateName(gate, race)
{
	if(gate.role === 'checkpoint') return `checkpoint ${gate.number}`;

	if(gate.role === 'finish') return 'finish gate';

	return race.state === 'running' && race.remaining === 1 ? 'start gate to finish' : 'start gate';
}

/**
 * Format a race time as Race::clock() does in PHP.
 * @param {number} seconds Time in seconds.
 * @param {number} digits Decimal places of a second.
 * @returns {string} For example 12.34 s, or 1:02.35 from one minute up.
 */
function raceClock(seconds, digits = 2)
{
	const scale = 10 ** digits;
	const units = Math.round(seconds * scale);
	const minutes = Math.floor(units / (60 * scale));
	const rest = ((units - minutes * 60 * scale) / scale).toFixed(digits);

	return minutes ? `${minutes}:${rest.padStart(digits + 3, '0')}` : `${rest} s`;
}

/** Read only the recipe controls; presentation settings travel through their own input channel. */
function generationValues(frame)
{
	const result = {
		preset: frame.querySelector('[name="preset"]').value
		, seed: frame.querySelector('[name="seed"]').value
		, worldSize: Number(frame.querySelector('[name="worldSize"]').value)
	};

	for(const field of frame.querySelectorAll('[data-landform] [name]'))
		result[field.name] = field.tagName==='SELECT' ? field.value : metricValue(field);

	return result;
}

/** Local storage key for the jet handling preference. */
const JET_PREFERENCE = 'terrain-sandbox.jet';
/** localStorage key for whether this browser shows the renderer's statistics under the HUD. */
const DIAGNOSTICS_PREFERENCE = 'terrain-sandbox.diagnostics';

/** Local storage key for the car's headlight switch, and the positions PHP accepts. */
const CAR_PREFERENCE = 'terrain-sandbox.car';
const HEADLIGHTS = ['auto', 'on', 'off'];

/** Gamepad buttons in the standard layout that issue a command when pressed. */
const PAD_COMMANDS = new Map([
	[9, 'menu'], [12, 'pad-up'], [14, 'car'], [15, 'craft']
	, [13, 'pad-down']
	, [3, 'pad-y']
	, [2, 'pad-x']
	, [0, 'jet-launch']
	, [10, 'vehicle-map']
	, [11, 'pad-rs']
]);

/** Gamepad buttons PHP reads while held: A, B, X, Y, LB, RB and the two stick clicks, with their bits in the packet. */
const PAD_HELD = [[0, 1], [1, 2], [2, 4], [3, 8], [4, 16], [5, 32], [10, 64], [11, 128]];

/** Y and B share speed control with the view chord in flight. */
const SPEED_BUTTONS = 8 | 2;

/**
 * Remove a radial dead zone from a stick and rescale the rest to reach full travel.
 * @param {number} x Raw horizontal value, -1 to 1.
 * @param {number} y Raw vertical value, -1 to 1, positive toward the player.
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
 * @returns {{axes: number[], buttons: Set<number>, active: ?string}|null} Left X and Y, right X and Y, left and right
 * trigger, the held button indices, and the id of a pad being used this poll; null when no such pad is connected.
 */
function readGamepads()
{
	let found = false, active = null;
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

		if(values.some(value => Math.abs(value) > .3) || pad.buttons.some(button => button.pressed)) active = pad.id;
	}

	return found ? {axes, buttons, active} : null;
}

/**
 * Pick a pad's button art from its name, as SdlInput::padTheme() does.
 * @param {string} id The pad's id from the Gamepad API.
 * @returns {string} playstation, xbox or generic.
 */
function padTheme(id)
{
	if(/playstation|dualshock|dualsense|sony|054c/i.test(id)) return 'playstation';

	if(/xbox|xinput|microsoft|045e/i.test(id)) return 'xbox';

	return 'generic';
}

/**
 * Show a control's number short enough for its readout: whole numbers from 100, one decimal from 10, and below that
 * at most four decimals with trailing zeros dropped.
 * @param {string|number} value The field's value or a setting.
 * @returns {string} The readout, or the value unchanged when it is not a number.
 */
function readout(value)
{
	const number = Number(value);

	if(value === '' || !Number.isFinite(number)) return String(value);

	const decimals = Math.abs(number) >= 100 ? 0 : Math.abs(number) >= 10 ? 1 : 4;

	return String(Number(number.toFixed(decimals)));
}

/** Local storage key for the panel tab shown last. */
const TAB_PREFERENCE = 'terrain-sandbox.tab';

const LAYER_FIELDS = {
	name: ['Layer name', 'Use a name you can recognize when choosing this layer as a mask.']
	, channel: ['Affects', 'Color tints the surface. Relief shades bumps. Both combines the two.']
	, mask: ['Coverage field', 'Choose a feature to cover, such as scree. All uses the whole area within your limits.']
	, parent: ['Within layer mask', 'Use an earlier layer’s coverage, for example to put ripples only on sand.']
	, datum: ['Heights relative to', 'Measure from world zero, the water level, or the tilted rock beds.']

	, color: ['Color', 'The base tint of this layer before its surface detail and pattern are applied.']
	, field: ['Surface detail', 'Varies the tint. Try Joints for fractured rock or Crowns for a leafy canopy.']
	, pattern: ['Pattern']
	, patternColor: ['Pattern color', 'Second tint mixed in by the pattern. Shrubs also uses it for foliage.']
	, reflectivity: ['Reflection strength · 0–1'
		, 'Adds a clear reflective coating. At 1, polished ice catches the sky most strongly at a grazing angle.'
	]
	, roughness: ['Reflection roughness · 0–1'
		, 'Lower values sharpen the reflected sun. Higher values spread the highlight across a frosted surface.'
	]
	, relief: ['Relief source']

	, opacity: ['Coverage · 0–1', 'At 0 the layer disappears. At 1 it has full effect wherever its masks allow.']
	, low: ['Minimum elevation · ft', 'Lower edge of the height band. Raise it to leave lower ground exposed.', 'ft']
	, high: ['Maximum elevation · ft', 'Upper edge of the height band. Lower it to leave higher ground exposed.', 'ft']
	, fade: ['Elevation fade · ft', 'Distance over which the layer blends out at its height limits.', 'ft']
	, slopeLow: ['Minimum slope', 'Raise to keep the layer on steeper ground. Level ground is 0. A 60° slope is 0.5.']
	, slopeHigh: ['Maximum slope', 'Lower this to keep the layer off cliffs. A vertical face is 1.']
	, slopeFade: ['Slope fade', 'Width of the blend at each slope limit. Small values give sharper edges.']
	, noise: ['Boundary noise · ft', 'Shifts an elevation edge up and down where that edge crosses the terrain.', 'ft']
	, scale: ['Boundary noise scale · ft'
		, 'Widens bends along elevation edges when Boundary noise is above zero.'
		, 'ft'
	]
	, period: ['Repeat interval · ft', 'Repeats the height band at this spacing. Use 0 for a single band.', 'ft']

	, patternAmount: ['Pattern amount', 'Increase for a stronger tint or bump pattern. Zero removes the pattern.']
	, patternScale: ['Pattern scale · ft', 'Increase for wider texture features, such as patches or ripples.', 'ft']
	, patternStretch: ['Vertical stretch', 'Values above 1 stretch the texture upward on cliff faces.']
	, strength: ['Relief strength', 'Sets bump intensity. Zero removes these bumps. Negative values invert them.']
	, flatten: ['Flatten underlying relief · 0–1', 'At 1, full coverage smooths away all bumps from earlier layers.']
};

const RELIEF_HELP = {
	none: 'Keeps the lighting from earlier layers, subject to Flatten underlying relief.'
	, pattern: 'Uses the selected texture for bumps. Pattern scale sets their size and Relief strength their intensity.'
	, ribs: 'Lights the shoulders of winding gullies. Set their width with Gully spacing under Shared field settings.'
	, facets: 'Lights rock slabs and cracks. Set Slab width and Slab height under Shared field settings.'
	, crowns: 'Gives tree crowns rounded highlights. Block and crown spacing in Shared field settings sets their size.'
	, blocks: 'Lights loose stones and boulders. Block and crown spacing in Shared field settings sets their size.'
};

/** Keep unapplied structural edits in the panel while the renderer uses the committed stack. */
// Changes are committed on blur or selection. Keep unfinished number edits across ordinary status updates.

function atmosphereEditor(frame, push)
{
	const list = frame.querySelector('[data-air-list]');
	const template = frame.querySelector('[data-air-template]');
	const add = frame.querySelector('[data-air-add]');
	const preset = frame.querySelector('[data-air-preset]');
	let schema, generation = -1, serial = 0, pending = 0, accepted = '';

	function row(values)
	{
		const el = template.content.firstElementChild.cloneNode(true);

		for(const input of el.querySelectorAll('[data-air-key]'))
		{
			const key = input.dataset.airKey;

			if(key==='top' || key==='bottom')
			{
				input.min = -schema.ceiling;
				input.max = schema.ceiling;
			}

			if(key==='density') input.max = schema.maxDensity;
		}

		configureUnits(el);

		for(const input of el.querySelectorAll('[data-air-key]'))
		{
			const key = input.dataset.airKey;

			if(key==='enabled') input.checked = values[key];
			else setFieldValue(input, values[key]);
		}

		el.querySelector('legend').textContent = values.name;

		return el;
	}

	function submit()
	{
		const rows = [];

		for(const el of list.children)
		{
			const record = {};
			const top = el.querySelector('[data-air-key=top]');
			const base = el.querySelector('[data-air-key=bottom]');
			top.setCustomValidity(top.valueAsNumber <= base.valueAsNumber ? 'Top must be above the base.' : '');

			for(const input of el.querySelectorAll('[data-air-key]'))
			{
				if(!input.reportValidity()) return;

				record[input.dataset.airKey] = input.type==='checkbox'
					? input.checked
					: input.type==='number' ? metricValue(input) : input.value.trim();
			}

			el.querySelector('legend').textContent = record.name;
			rows.push(record);
		}

		pending = ++serial;
		push({type: 'atmosphere-layers', layers: rows, request: pending});
		add.disabled = rows.length >= schema.limit;
	}

	add.onclick = () => {
		if(!schema || list.children.length >= schema.limit) return;

		const names = new Set(Array.from(list.querySelectorAll('[data-air-key=name]'), el => el.value));
		const first = schema.defaults.name;
		let name = first;

		for(let i = 2; names.has(name); ++i) name = first + ' ' + i;
		const el = row({...schema.defaults,name});
		list.append(el);
		submit();
		el.querySelector('[data-air-key=name]').focus();
	};

	preset.onclick = () => {
		pending = ++serial;
		push({type: 'atmosphere-preset', request: pending});
	};

	list.onchange = submit;

	list.onclick = ({target}) => {
		const remove = target.closest('[data-air-remove]');

		if(remove)
		{
			remove.closest('fieldset').remove();
			submit();
			add.focus();
		}
	};

	return {

		configure(value) { schema = value; add.disabled = false; }


		, show(state) {
			if(!schema || !state.atmosphereLayers) return;

			const replaced = generation !== state.generation;

			if(!replaced && pending > state.atmosphereReply) return;

			preset.disabled = state.atmosphereOrigin === 'preset';
			const key = JSON.stringify(state.atmosphereLayers);

			if(!replaced && !pending && key===accepted) return;

			generation = state.generation;
			pending = 0;
			accepted = key;
			const rows = state.atmosphereLayers;

			if(replaced || rows.length !== list.children.length) list.replaceChildren(...rows.map(row));
			else
			for(const [i,el] of Array.from(list.children).entries())
			{
				el.querySelector('legend').textContent = rows[i].name;

				for(const input of el.querySelectorAll('[data-air-key]'))
				{
					const value = rows[i][input.dataset.airKey];

					if(input.type==='checkbox') input.checked = value;
					else if(input!==document.activeElement) setFieldValue(input, value);
				}
			}

			add.disabled = rows.length >= schema.limit;
		}
	};
}

function materialEditor(frame, push, status)
{
	let surface = null;
	const layerBox = frame.querySelector('[data-layer-fields]');
	const fieldBox = frame.querySelector('[data-shared-fields]');
	const inkBox = frame.querySelector('[data-paint-inks]');
	const applyLayers = frame.querySelector('[data-layer-apply]');
	let draft = false, materialKey = '', lastValues = '', editorGeneration = -1, serial = 0, submitted = 0;

	const entities = {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'};

	const escaped = value => String(value).replace(/[&<>"']/g, char => entities[char]);

	const title = value => value.replace(/-/g, ' ').replace(/^./, letter => letter.toUpperCase());

	const list = keys => keys.map(key => [key,title(key)]);

	const rowsFrom = records => records.map(row => ({
		...row
		, parent: typeof row.parent==='number' ? records[row.parent]?.id ?? '' : row.parent
	}));

	const structural = new Set(['channel','mask','field','pattern','relief','parent','datum']);
	const fraction = 'type="number" min="0" max="1" step="any" required';
	const inputTypes = {
		name: 'type="text" maxlength="64" required'
		, color: 'type="color"'
		, patternColor: 'type="color"'
		, reflectivity: fraction
		, roughness: fraction
	};

	function layerRow(row, index, rows)
	{
		const menus = {
			channel: list(surface.channels)
			, mask: list(surface.masks)
			, parent: [['', 'Anywhere'], ...rows.slice(0, index).map(r => [r.id, r.name])]
			, datum: list(['absolute','water','beds'])
			, field: list(surface.fields)
			, pattern: list(surface.patterns)
			, relief: list(surface.relief)
		};

		const fields = Object.entries(LAYER_FIELDS).map(([key, [label, help = '', unit = '']]) => {
			const id = `${layerBox.id}-row-${index}-${key}`;
			const increment = unit === 'ft' || key === 'patternStretch' ? .01 : .001;
			const attrs
				= `data-increment="${increment}" data-layer-key="${key}" data-unit="${unit}" aria-describedby="${id}"`;

			let widget;

			if(menus[key])
			{
				const options = menus[key].map(([value, name]) => {
					const selected = value===row[key] ? ' selected' : '';

					return `<option value="${escaped(value)}"${selected}>${escaped(name)}</option>`;
				});

				widget = `<select ${attrs}>${options.join('')}</select>`;
			}
			else
			{
				const type = inputTypes[key] ?? 'type="number" step="any" required';
				widget = `<input ${attrs} ${type} value="${escaped(row[key])}">`;
			}

			return `<div class="surface-field"><label class="terrain-field">${label}${widget}</label>
				<small id="${id}">${help}</small></div>`;
		});

		return `<details data-layer="${escaped(row.id)}"><summary>${escaped(row.name)}</summary>
			<div class="terrain-grid">${fields.join('')}</div><div class="terrain-row">
			<button data-move="-1" ${index ? '' : 'disabled'}>Move up</button>
			<button data-move="1" ${index + 1 < rows.length ? '' : 'disabled'}>Move down</button>
			<button data-remove ${rows.length === 1 ? 'disabled' : ''}>Remove layer</button></div></details>`;
	}

	function layerControls(el, index)
	{

		const field = key => el.querySelector(`[data-layer-key="${key}"]`);

		const group = key => field(key).closest('.surface-field');

		const show = (keys, visible) => keys.forEach(key => { group(key).hidden = !visible; });

		const color = field('channel').value!=='relief', relief = field('channel').value!=='color';
		const pattern = field('pattern').value!=='none';
		const source = field('relief').value;
		group('relief').querySelector('small').textContent = RELIEF_HELP[source];
		const patternHelp = group('pattern').querySelector('small');

		if(!color && source!=='pattern')
			patternHelp.textContent = 'Choose Pattern as the relief source to use this texture on a relief-only layer.';
		else if(relief && source==='pattern')
			patternHelp.textContent = color
				? 'The same texture tints this layer and shapes its lighting bumps.'
				: 'The texture shapes the bumps that catch the light. Try Ripples on sand.';
		else patternHelp.textContent = 'Blends the pattern color into the base tint. Try Gravel for scattered flecks.';

		show(['field','color','reflectivity','roughness'], color);
		show(['relief','strength'], relief);
		show(['patternColor'], color && (pattern || field('field').value==='shrubs'));
		show(['patternAmount','patternScale','patternStretch'], pattern);

		show(
			['mask'
				, 'parent'
				, 'datum'
				, 'opacity'
				, 'low'
				, 'high'
				, 'fade'
				, 'slopeLow'
				, 'slopeHigh'
				, 'slopeFade'
				, 'noise'
				, 'scale'
				, 'period','flatten'
			]
			, index>0
		);
	}

	function drawRows(rows, preset = false)
	{
		const open = new Set([...layerBox.querySelectorAll('details[open]')].map(el => el.dataset.layer));
		layerBox.innerHTML = rows.map(layerRow).join('');
		configureUnits(layerBox, preset);

		for(const [index,el] of [...layerBox.children].entries())
		{
			el.open = open.has(el.dataset.layer);
			layerControls(el, index);
		}

		frame.querySelector('[data-layer-add]').disabled = rows.length>=surface.limit;
	}

	function editorRows()
	{
		return [...layerBox.children].map(el => ({
			id: el.dataset.layer
			, ...Object.fromEntries(
				[...el.querySelectorAll('[data-layer-key]')].map(field => [field.dataset.layerKey
					, field.type==='number' ? metricValue(field) : field.value
				])
			)
		}));
	}

	function stageLayers()
	{
		draft = true;
		submitted = 0;
		applyLayers.disabled = false;
	}

	function sendRows()
	{
		const rows = editorRows();
		const invalid = layerBox.querySelector('input:invalid');

		if(invalid)
		{
			invalid.closest('details').open = true;
			invalid.reportValidity();

			return;
		}

		submitted = ++serial;
		push({type: 'surface-layers',request: submitted,layers: rows.map(row => ({...row,parent: row.parent || -1}))});
	}

	layerBox.addEventListener('input', event => {
		const field = event.target, key = field.dataset.layerKey;

		if(!key) return;

		if(draft || structural.has(key) || key==='name')
		{
			stageLayers();
			const el = field.closest('[data-layer]');
			layerControls(el, [...layerBox.children].indexOf(el));
		}
		else if(field.checkValidity())
			push({
				type: 'surface-edit'
				, id: field.closest('[data-layer]').dataset.layer
				, key
				, value: field.type==='number' ? metricValue(field) : field.value
			});
	});

	layerBox.addEventListener('click', event => {
		const button = event.target.closest('button');

		if(!button) return;

		const rows = editorRows(), i = rows.findIndex(row => row.id===button.closest('[data-layer]').dataset.layer);

		if(button.hasAttribute('data-remove'))
		{
			if(rows.some(row => row.parent===rows[i].id))
			{
				status.textContent = 'Choose another parent for the child layers before removing this mask.';

				return;
			}

			rows.splice(i, 1);
		}
		else
		{
			const j = i+Number(button.dataset.move);
			[rows[i],rows[j]] = [rows[j],rows[i]];

			if(rows.some((row, k) => row.parent && !rows.slice(0, k).some(before => before.id===row.parent)))
			{
				status.textContent = 'A parent mask must precede its children.';

				return;
			}
		}

		stageLayers();
		drawRows(rows);
	});

	frame.querySelector('[data-layer-add]').onclick = () => {
		const rows = editorRows();

		if(rows.length>=surface.limit) return;

		let n = rows.length+1;

		while(rows.some(row => row.id==='layer-' + n)) n++;
		rows.push({...surface.layerDefaults,id: 'layer-' + n,name: 'Layer ' + n,parent: ''});
		stageLayers();
		drawRows(rows);
		layerBox.lastElementChild.open = true;
	};

	applyLayers.onclick = sendRows;

	function replaceStack(preset)
	{
		submitted = ++serial;
		push({type: 'surface-layers',request: submitted,...(preset ? {preset} : {})});
	}

	frame.querySelector('[data-layer-reset]').onclick = () => replaceStack(null);

	const preset = frame.querySelector('[data-layer-preset]');

	frame.querySelector('[data-layer-load]').onclick = () => replaceStack(preset.value);

	function configure(schema)
	{
		surface = schema;

		inkBox.innerHTML = surface.inkKeys.map(
			key => `<label class="terrain-control"><span>${INK_LABELS[key]}</span>
			<input data-ink="${key}" type="color" aria-describedby="${layerBox.id}-paint"></label>`
		).join('');

		fieldBox.innerHTML = surface.fieldKeys.map(key => {
			const [min,max] = surface.ranges[key], [label,step,help,unit = ''] = SHARED_FIELDS[key];
			const id = `${layerBox.id}-shared-${key}`;

			return `<div class="surface-field"><label class="terrain-control"><span>${label}</span>
				<input data-shared="${key}" data-unit="${unit}" aria-describedby="${id}" type="range" min="${min}" max="${max}" step="${step}">
				<output aria-hidden="true"></output></label><small id="${id}">${help}</small></div>`;
		}).join('');

		configureUnits(fieldBox);
	}

	inkBox.oninput = ({target}) => push({type: 'surface-ink',ink: target.dataset.ink,color: target.value});

	fieldBox.oninput = ({target}) => {
		target.nextElementSibling.textContent = readout(target.value);
		push({type: 'surface-field',key: target.dataset.shared,value: metricValue(target)});
	};

	const showSurface = state => {
		if(!surface || !state.surfaceLayers) return;

		if(editorGeneration!==state.generation || (submitted && submitted===state.materialReply))
		{
			draft = false;
			submitted = 0;
			materialKey = '';
			lastValues = '';
			editorGeneration = state.generation;
		}

		const valuesKey = JSON.stringify(state.surfaceLayers);

		if(!draft && valuesKey!==lastValues)
		{
			lastValues = valuesKey;
			const rows = rowsFrom(state.surfaceLayers);

			const key = JSON.stringify(
				rows.map(
					row => [row.id,row.name,row.parent,row.channel,row.field,row.mask,row.pattern,row.relief,row.datum]
				)
			);

			if(key!==materialKey)
			{
				drawRows(rows, state.surfaceOrigin === 'preset');
				materialKey = key;
			}
			else
			for(const [i,el] of [...layerBox.children].entries())
			{
				for(const field of el.querySelectorAll('[data-layer-key]'))
				{
					if(field!==document.activeElement)
					setFieldValue(field, rows[i][field.dataset.layerKey], state.surfaceOrigin === 'preset');
				}
			}
		}

		applyLayers.disabled = !draft;

		frame.querySelector(
			'[data-surface-note]'
		).textContent = (state.surfaceOrigin==='authored' ? 'Edited surface · ' : 'Landform preset · ')+title(
			state.surfacePreset
		);

		for(const field of inkBox.querySelectorAll('input'))
		if(field!==document.activeElement)
		field.value = state.paintInks[field.dataset.ink];

		const usesReliefFields = state.surfaceLayers.some(
			row => row.mask!=='all' || row.field!=='plain' || (row.channel!=='color'
				&& !['none','pattern'].includes(row.relief)
			)
		);

		for(const field of fieldBox.querySelectorAll('input'))
		{
			const key = field.dataset.shared;
			field.closest('.surface-field').hidden = !usesReliefFields && key!=='macroVariation';

			if(field!==document.activeElement)
			{
				setFieldValue(field, state.surfaceFields[key]);
				field.nextElementSibling.textContent = readout(field.value);
			}
		}
	};

	return {configure, show: showSurface};
}

const INK_LABELS = {grassColor: 'Grass', rockColor: 'Rock', sandColor: 'Sand', snowColor: 'Snow', roadColor: 'Road'};

/** Labels, slider steps and inline help for fields sampled by multiple layers. */
const SHARED_FIELDS = {
	gullySize: ['Gully spacing · ft'
		, .5
		, 'Wider spacing spreads out the ribs and the color bands that follow them.'
		, 'ft'
	]
	, blockSize: ['Block and crown spacing · ft'
		, .25
		, 'Increase for larger, more widely spaced rocks and tree crowns.'
		, 'ft'
	]
	, jointWidth: ['Slab width · ft', .5, 'Horizontal spacing between fractures. Increase for wider rock slabs.', 'ft']
	, jointHeight: ['Slab height · ft', .5, 'Vertical spacing between fractures. Increase for taller rock faces.', 'ft']

	, talusAmount: ['Talus', .01, 'Sets the share of moderately steep ground covered by fans of loose rock.']
	, stainAmount: ['Iron stain', .01, 'Increase to cover more of the exposed rock with iron stains.']
	, lichenAmount: ['Lichen', .01, 'Strengthens the gray-green patches on weathered rock.']

	, rockSlopeStart: ['Rock starts · slope', .01, 'Lower this threshold to expose rock on gentler slopes.']
	, rockSlopeEnd: ['Rock covers · slope', .01, 'Slope where the transition from soil to exposed rock finishes.']
	, beachHeight: ['Beach top · ft', 1, 'Height above the water where shore sand begins to thin.', 'ft']
	, beachWidth: ['Beach fade · ft', .5, 'Vertical distance over which sand blends out above the beach top.', 'ft']
	, snowline: ['Snowline · ft', 1, 'World elevation where snow starts. Alpine and vegetation zones follow it.', 'ft']
	, snowTransition: ['Snow fade · ft'
		, 1
		, 'Climb above the snowline needed for full snow cover on gentle ground.'
		, 'ft'
	]
	, alpineStart: ['Alpine rock from · ft', 1, 'Bare rock starts at this height relative to the snowline.', 'ft']
	, alpineEnd: ['Alpine rock to · ft'
		, 1
		, 'Height from the snowline where bare rock replaces the last alpine meadow.'
		, 'ft'
	]
	, snowSlopeStart: ['Snow thins · slope', .01, 'Slope where snow begins to give way to the material underneath.']
	, snowSlopeEnd: ['Snow gone · slope', .01, 'Raise this threshold to keep snow on steeper faces.']

	, macroVariation: ['Variation', .05, 'Varies tint across broad areas and makes snow and vegetation edges wander.']
	, treeline: ['Treeline · ft'
		, 1
		, 'Height of the forest edge relative to the snowline. Negative values lie below it.'
		, 'ft'
	]
	, tundraStart: ['Tundra from · ft'
		, 1
		, 'Height relative to the snowline where tundra begins to replace meadow.'
		, 'ft'
	]
};

function range(label, key, min, max, step, value, unit = '')
{
	return `<label class="terrain-control"><span>${label}</span><input data-value="${key}"`
		+ ` data-unit="${unit}" type="range" min="${min}" max="${max}" step="${step}" value="${value}">`
			+ `<output data-output="${key}">${value}</output></label>`;
}

let labCount = 0;

function markup()
{
	const hintPrefix = `landform-${++labCount}`;
	const landscapeOptions = [
		['coast', 'Coast']
		, ['mountain', 'Mountain Ridges']
		, ['canyon', 'Canyon']
		, ['limestone', 'Limestone Plain']
		, ['basalt-fjords', 'Basalt Fjords']
		, ['amber-dunes', 'Amber Dunes']
		, ['arctic-meltwater', 'Arctic Meltwater']
		, ['tidal-lagoon', 'Tidal Lagoon']
		, ['red-mesas', 'Red Mesas']
		, ['heather-moor', 'Heather Moor']
		, ['salt-pans', 'Salt Pans']
		, ['cinder-highlands', 'Cinder Highlands']
		, ['rolling-prairie', 'Rolling Prairie']
		, ['river-valley', 'River Valley &amp; Floodplain']
		, ['wooded-foothills', 'Wooded Foothills']
		, ['high-desert-steppe', 'High Desert Steppe']
		, ['alaska-range', 'Alaska Range']
		, ['chalk-headlands', 'Chalk Headlands']
		, ['karst-bay', 'Karst Bay']
		, ['tepui-highlands', 'Tepui Highlands']
		, ['painted-badlands', 'Painted Badlands']
		, ['emerald-ridges', 'Emerald Ridges']
	];

	const option = ([value, label]) => `<option value="${value}">${label}</option>`;

	const journeyOptions = selected => landscapeOptions.map(([value, label]) =>
		`<option value="${value}"${value === selected ? ' selected' : ''}>${label}</option>`
	).join('');

	const surfaceOptions = landscapeOptions.flatMap(
		row => row[0] === 'canyon' ? [row, ['canyon-v1', 'Early Canyon']] : [row]
	);

	const surfaceId = `surface-${labCount}`;

	return `<style>
	.terrain-sandbox{container:terrain-view / size;--lab-inset:12px;--lab-width:min(620px,max(44%,400px),calc(100% - 24px));--lab-height:calc(100cqh - 26px - var(--radar-room,0px));position:relative;width:100%;height:100%;min-height:320px;overflow:hidden;background:#101f28;color:#edf4ee;font:14px/1.4 system-ui,sans-serif}
	.terrain-sandbox:has(.terrain-instruments:not([hidden])){--radar-room:min(20cqh,18cqw)}.terrain-flight-map{position:absolute;left:10px;bottom:10px;pointer-events:none}.terrain-sandbox.has-flight-map .terrain-hud{bottom:calc(var(--flight-map-size) + 18px)}.terrain-road-preview{position:absolute;inset:0;width:100%;height:100%;pointer-events:none;overflow:hidden}.terrain-road-preview path{stroke:#efc275;stroke-width:3;fill:none;filter:drop-shadow(0 1px 2px #000);vector-effect:non-scaling-stroke}.terrain-road-preview circle{fill:#102822;stroke:#efc275;stroke-width:2}.terrain-road-preview text{fill:#fff;font:700 12px system-ui,sans-serif;text-anchor:middle;dominant-baseline:central}
	.terrain-sandbox:has(.terrain-speedometer:not([hidden])){--radar-room:var(--speedometer-size)}.terrain-speedometer{position:absolute;right:10px;bottom:10px;pointer-events:none}
	.in-cockpit:not(:has(.terrain-panel[open])) .terrain-hud{display:none}.in-cockpit:not(:has(.terrain-panel[open])) .terrain-hud:has([data-race]:not([hidden])){display:block;bottom:52px}.in-cockpit:not(:has(.terrain-panel[open])) .terrain-hud>:not([data-race]){display:none}.terrain-note a{color:inherit}.terrain-stage{position:absolute;inset:0}
	.terrain-sandbox button,.terrain-sandbox select,.terrain-sandbox input[type=number],.terrain-sandbox input[type=text]{box-sizing:border-box;background:#244437;color:#fff;border:1px solid #658271;border-radius:6px;min-height:34px;padding:5px 9px;font:inherit}
	.terrain-sandbox button{cursor:pointer}.terrain-sandbox button:hover{background:#355e4b}.terrain-sandbox button:disabled{opacity:.45;cursor:default}.terrain-sandbox :focus-visible{outline:2px solid #efc275;outline-offset:2px}.terrain-sandbox input{accent-color:#e5bc75}.terrain-sandbox input[type=range]{min-width:0;width:100%}@media(pointer:coarse){.terrain-sandbox input[type=range]{min-height:32px}}
	.terrain-note{color:#bdd2c7}.terrain-check{display:flex;align-items:center;gap:8px}.terrain-hud{position:absolute;left:16px;bottom:16px;box-sizing:border-box;max-width:calc(100% - 32px);pointer-events:none;text-shadow:0 1px 4px #000;background:#102822ac;border-radius:8px;padding:10px 12px}.terrain-hud p{margin:2px 0}.terrain-hud pre{font:11px/1.5 ui-monospace,monospace;color:#c1d4c6;margin:8px 0 0;white-space:pre-wrap}.terrain-pending{color:#efc275}.terrain-race{font-variant-numeric:tabular-nums;font-weight:600;color:#fff}.terrain-sizes{white-space:pre-line}.terrain-sandbox [hidden]{display:none!important}
	.terrain-route-scroll{max-height:18rem;overflow:auto}
	[data-journey-guide]{width:100%;border-collapse:collapse;text-align:left;font-variant-numeric:tabular-nums}
	[data-journey-guide] caption{text-align:left;font-weight:700;margin:8px 0}
	[data-journey-guide] :is(th,td){padding:5px 8px;border-bottom:1px solid #466056;overflow-wrap:anywhere}
	[data-journey-guide] tbody th{font-weight:500}
	[data-journey-guide] [aria-current="location"]{background:#284638;color:#ffe1a2}
	[data-journey-guide] [aria-current="location"] th::after{content:" · You are here";font-size:.85em}
	[data-journey-sign]{color:#ffe1a2;font-weight:600}
	[data-journey-progress]:empty,[data-journey-error]:empty{display:none}
	.terrain-panel{
		--header-height:48px;
		position:absolute;z-index:1;top:var(--lab-inset);right:var(--lab-inset);width:var(--lab-width);
		box-sizing:border-box;container:terrain-lab / inline-size;overflow:hidden;
		color:#edf4ee;background:#112a24f7;border:1px solid #668477;border-radius:14px;
		box-shadow:0 6px 24px #07171366;backdrop-filter:blur(10px)
	}
	.terrain-panel>summary{
		box-sizing:border-box;height:var(--header-height);display:flex;align-items:center;gap:12px;padding:0 16px;
		list-style:none;cursor:pointer;background:#19382f;color:#fff7de;font-size:17px;font-weight:650
	}
	.terrain-panel>summary::-webkit-details-marker{display:none}
	.terrain-panel>summary:hover{background:#254737}
	.terrain-panel[open]{background:linear-gradient(#19382f,#19382f) 0 0/100% var(--header-height) no-repeat,#112a24f7}
	.terrain-panel[open]>summary{width:calc(100% - 182px)}
	.terrain-panel[open]>summary::after{content:"";position:absolute;top:0;right:0;width:45px;height:var(--header-height);background:linear-gradient(#d9e7d7,#d9e7d7) center/13px 2px no-repeat}
	.terrain-panel[open] .lab-title{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
	.terrain-panel:not([open]){width:44px;border-radius:50%}
	.terrain-menu-button{
		position:absolute;z-index:1;top:var(--lab-inset);right:calc(var(--lab-inset) + 52px);width:44px;height:44px;
		display:flex;align-items:center;justify-content:center;gap:5px;padding:0;cursor:pointer;
		background:#19382f;border:1px solid #668477;border-radius:50%;box-shadow:0 6px 24px #07171366
	}
	.terrain-menu-button:hover{background:#254737}
	.terrain-menu-live{position:absolute;width:1px;height:1px;overflow:hidden;clip-path:inset(50%)}
	.terrain-menu-button i{width:4px;height:15px;border-radius:1px;background:#fff7de}
	.terrain-sandbox:has(.terrain-panel[open]) .terrain-menu-button{right:calc(var(--lab-inset) + var(--lab-width) + 8px)}
	.terrain-menu-open :is(.terrain-hud,.terrain-control-hints,.terrain-speedometer,.terrain-flight-map,.terrain-instruments,.terrain-race,.terrain-panel,.terrain-menu-button,.terrain-credit-space){visibility:hidden}
	.terrain-panel:not([open])>summary{height:42px;padding:0;justify-content:center}
	.terrain-panel:not([open]) .lab-title{position:absolute;width:1px;height:1px;overflow:hidden;clip-path:inset(50%)}
	.terrain-panel>summary:focus-visible{outline-offset:-4px;border-radius:inherit}
	.lab-music{position:absolute;top:0;right:45px;height:var(--header-height);display:flex;align-items:center;gap:3px}
	.terrain-panel:not([open]) .lab-music{display:none}
	.lab-music button{display:grid;place-items:center;width:32px;min-height:32px;padding:0;border-color:transparent;background:transparent;color:#e2ebe1}
	.lab-music button:hover{background:#355747}
	.lab-music button[aria-pressed=true]{color:#f2cc82;background:#304c3e}
	.lab-music i{display:block;width:15px;height:14px;background:currentColor}
	[data-music-play] i{clip-path:polygon(15% 0,95% 50%,15% 100%)}
	[data-music-pause] i{background:linear-gradient(to right,transparent 6%,currentColor 6% 38%,transparent 38% 62%,currentColor 62% 94%,transparent 94%)}
	[data-music-prev] i,[data-music-next] i{clip-path:polygon(0 0,20% 0,20% 47%,100% 0,100% 100%,20% 53%,20% 100%,0 100%)}
	[data-music-next] i{transform:scaleX(-1)}
	@container terrain-lab (max-width:350px){
		.terrain-panel[open]>summary{width:calc(100% - 147px);gap:6px;padding-inline:8px 2px}
		.terrain-panel[open]>summary::after{width:32px}
		.terrain-panel[open] .lab-title{font-size:14px}
		.lab-music{right:32px;gap:1px}.lab-music button{width:28px}
	}
	.lab-sliders{display:grid;gap:4px;width:19px;color:#edcb82}
	.lab-sliders i{position:relative;height:2px;background:currentColor;border-radius:2px}
	.lab-sliders i::after{content:"";position:absolute;left:3px;top:-2px;width:4px;height:6px;border-radius:2px;background:currentColor;box-shadow:0 0 0 1px #19382f}
	.lab-sliders i:nth-child(2)::after{left:12px}
	.lab-workspace{display:flex;flex-direction:column;max-height:calc(var(--lab-height) - 50px)}
	.lab-pages{overflow:auto;overscroll-behavior:contain;min-height:0;padding:16px;scrollbar-gutter:stable;scrollbar-width:thin;scrollbar-color:#819a8b transparent}
	.terrain-tabs{display:grid;flex:none;grid-template-columns:repeat(4,minmax(0,1fr));gap:3px;padding:4px 8px;background:#0e231d;border-block:1px solid #446050}
	.terrain-tabs [role=tab]{min-height:32px;padding:4px 0;font-size:13px;border-color:transparent;background:transparent;color:#c9d6cb}
	.terrain-tabs [role=tab][aria-selected=true]{background:#304c3e;color:#fff4d3;box-shadow:inset 0 -2px #e5bc75}
	.terrain-section,.terrain-grid,.terrain-surface-group{display:grid;grid-template-columns:minmax(0,1fr);gap:12px 18px;align-items:start}
	.terrain-section>*,.terrain-grid>*,.terrain-surface-group>*{min-width:0}
	.terrain-section>:is(h2,p,details,.terrain-row,div),.terrain-grid>:is(p,canvas,.terrain-row),.terrain-surface-group>:is(h2,.terrain-row){grid-column:1/-1}
	.terrain-panel h2,.terrain-landform-group legend{font-size:13px;font-weight:700;color:#d6e6cf;letter-spacing:.025em;margin:0}
	.terrain-panel h2{padding-top:7px}
	.terrain-section details{min-width:0;border:1px solid #425e50;border-radius:8px;background:#152f27}
	.terrain-section details>summary{cursor:pointer;padding:10px 12px;color:#f1e9d4;font-weight:650}
	.terrain-section details[open]>summary{border-bottom:1px solid #425e50}
	.terrain-section details>:is(.terrain-grid,.terrain-row,.terrain-note,[data-layer-fields]){margin:12px}
	.terrain-landform-group{margin:14px 12px 16px;padding:9px 0 0;border:0;border-top:1px solid #3a5448;min-width:0}
	.terrain-landform-group legend{padding:0 8px 0 0}
	.landform-field small,.surface-field small{display:block;margin-top:4px;color:#bdcfc3;font-size:12px;line-height:1.45}
	.terrain-panel .terrain-field{display:grid;gap:5px;align-content:start;color:#e8f0e7;font-weight:550}
	.terrain-panel :is(input[type=number],input[type=text],select){width:100%;min-width:0;font-weight:400}
	.terrain-surface-group{padding-block:12px;border-bottom:1px solid #3a5448}
	.terrain-section [data-layer]+[data-layer]{margin-top:6px}
	.terrain-control{display:grid;grid-template-columns:minmax(0,1fr) auto;align-items:center;gap:2px 8px}
	.terrain-control>input[type=range]{grid-row:2;grid-column:1/-1}
	.terrain-control>output{grid-row:1;grid-column:2;font-variant-numeric:tabular-nums;color:#eed194;font-size:13px}
	.terrain-row{display:flex;flex-wrap:wrap;gap:7px}
	.terrain-row>*{flex:1 1 104px;min-width:0}
	.lab-pages:has([data-tab-panel=landscape]:not([hidden])){scroll-padding-bottom:66px}
	.terrain-file-actions{position:sticky;bottom:-16px;margin:0 -16px -16px;padding:10px 16px;background:#19362b;border-top:1px solid #496352}
	.terrain-section>.terrain-wide{grid-column:1/-1}
	.terrain-panel .terrain-note{font-size:13px;line-height:1.5;margin:0}
	.terrain-panel details>.terrain-note{margin:10px 12px}
	.terrain-panel button[data-action=generate]{background:#dfbd79;color:#18271e;border-color:#e9cd94;font-weight:700}
	.terrain-panel button[data-action=generate]:hover{background:#edd095}
	@container terrain-lab (min-width:350px){
		[data-tab-panel=landscape]{grid-template-columns:repeat(2,minmax(0,1fr))}
	}
	@container terrain-lab (min-width:380px){
		.terrain-section,.terrain-grid,.terrain-surface-group{grid-template-columns:repeat(2,minmax(0,1fr))}
	}
	@container terrain-lab (min-width:440px){
		.terrain-tabs{grid-template-columns:repeat(7,minmax(0,1fr))}
	}
	@container terrain-view (max-width:560px){
		.terrain-panel{--lab-inset:8px;--lab-width:calc(100% - 16px);--lab-height:min(62cqh,calc(100cqh - 16px - var(--radar-room,0px)))}
		.terrain-panel{--header-height:40px}
		.terrain-panel>summary{font-size:15px}
		.terrain-sandbox:has(.terrain-panel[open]) .terrain-menu-button{display:none}
		.lab-workspace{max-height:calc(var(--lab-height) - 42px)}
		.terrain-tabs [role=tab]{min-height:30px}
		.lab-pages{padding:12px}
		.lab-pages:has([data-tab-panel=landscape]:not([hidden])){scroll-padding-bottom:100px}
		.terrain-file-actions{bottom:-12px;margin:0 -12px -12px;padding:8px 12px}
		.terrain-file-actions>*{flex-basis:calc(50% - 7px)}
	}
	.terrain-sandbox:has(.terrain-speedometer:not([hidden])){--map-room:0px}
	.terrain-sandbox.has-flight-map:has(.terrain-speedometer:not([hidden])){--map-room:calc(var(--flight-map-size) + 8px)}
	.terrain-sandbox{--credit-left:12px}
	.terrain-sandbox.has-flight-map{--credit-left:calc(10px + var(--flight-map-size) + 8px)}
	.terrain-sandbox:has(.terrain-speedometer:not([hidden])){--credit-right:calc(var(--speedometer-size) + 16px)}
	.terrain-credit-space{position:absolute;z-index:2;left:var(--credit-left);bottom:10px;width:min(580px,calc(100% - var(--credit-left) - var(--credit-right,12px)));container:music-credit / inline-size;pointer-events:none}
	.terrain-music-credit{box-sizing:border-box;display:flex;align-items:center;width:max-content;max-width:100%;height:128px;gap:18px;padding:12px 20px 12px 14px;border-radius:14px;background:#102822ed;color:#edf4ee}
	.terrain-music-credit img{width:96px;height:96px;object-fit:contain;flex:none}
	.terrain-music-credit div{min-width:0}.terrain-music-credit strong,.terrain-music-credit span,.terrain-music-credit small{display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;line-height:1.25}
	.terrain-music-credit strong{font-size:28px}.terrain-music-credit span{font-size:26px}.terrain-music-credit small{font-size:22px;color:#c2d2c8}
	@container music-credit (min-width:256px) and (max-width:330px){
		.terrain-music-credit{gap:8px;padding-inline:10px}
		.terrain-music-credit img{width:80px;height:80px}
		.terrain-music-credit strong{font-size:22px;white-space:normal;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical}
		.terrain-music-credit span{font-size:20px}.terrain-music-credit small{font-size:16px}
	}
	@container music-credit (max-width:255px){
		.terrain-music-credit{flex-direction:column;justify-content:center;gap:4px;padding:6px 8px}
		.terrain-music-credit img{width:56px;height:56px}.terrain-music-credit div{max-width:100%;text-align:center}
		.terrain-music-credit strong{font-size:18px}.terrain-music-credit span{font-size:16px}.terrain-music-credit small{font-size:14px}
	}
	@container music-credit (max-width:108px){
		.terrain-music-credit{position:absolute;bottom:calc(var(--speedometer-size,0px) + 8px);flex-direction:row;height:96px;gap:8px;max-width:calc(100cqw + var(--credit-right,12px) - 12px)}
		.terrain-music-credit div{text-align:left}
	}
	.terrain-sandbox:has(.terrain-music-credit:not([hidden])) .terrain-hud{margin-bottom:134px}
	.terrain-sandbox:has(.terrain-speedometer:not([hidden])) .terrain-hud{left:calc(16px + var(--map-room));bottom:16px;max-width:calc(100% - var(--map-room) - var(--speedometer-size) - 58px)}
	@container terrain-view (min-width:960px){
		.terrain-panel[open]~.terrain-hud{max-width:calc(100% - var(--lab-width) - 48px)}
	}
	.terrain-control-hints{position:absolute;top:12px;left:12px;display:flex;flex-wrap:wrap;align-items:center;gap:3px 12px;box-sizing:border-box;max-width:min(max(235px,36%),calc(100% - 24px));padding:6px 9px;font-size:15px;background:#102822d9;border-radius:6px;color:#edf4ee;pointer-events:none}
	.terrain-control-hints>span{max-width:100%}
	.terrain-hint-button{display:inline-block;vertical-align:middle;background-image:var(--hint-buttons);background-repeat:no-repeat}
	@keyframes terrain-finish-pulse{50%{opacity:.4}}
	@media(prefers-reduced-motion:no-preference){.terrain-finish{animation:terrain-finish-pulse .8s ease-in-out 3}}
	@media(max-width:640px){.terrain-sandbox:has(.terrain-panel:not([open])) .terrain-hud:has([data-race]:not([hidden])) [data-diagnostics]{display:none}}
	</style><div class="terrain-stage"></div><div class="terrain-control-hints" hidden></div><svg class="terrain-road-preview" data-road-preview aria-label="Pending road route" aria-hidden="true" focusable="false"></svg>
	<div class="terrain-credit-space"><aside class="terrain-music-credit" data-music-credit role="status" aria-live="polite" aria-atomic="true" hidden><img alt="" hidden><div><strong></strong><span data-artist></span><small data-album></small></div></aside></div>
	<button type="button" class="terrain-menu-button" data-action="menu" title="Pause menu (Esc)" aria-label="Pause menu"><i></i><i></i></button>
	<details class="terrain-panel" open>
	<summary title="Landscape Lab (H)"><span class="lab-sliders" aria-hidden="true"><i></i><i></i><i></i></span><span class="lab-title">Landscape Lab</span></summary>
	<div class="lab-music" role="group" aria-label="Soundtrack controls">
	<button type="button" data-music-prev aria-label="Previous track" title="Previous track"><i aria-hidden="true"></i></button>
	<button type="button" data-music-play aria-label="Play music" title="Play music"><i aria-hidden="true"></i></button>
	<button type="button" data-music-pause aria-label="Pause music" title="Pause music"><i aria-hidden="true"></i></button>
	<button type="button" data-music-next aria-label="Next track" title="Next track"><i aria-hidden="true"></i></button>
	</div>
	<div class="lab-workspace">
	<div class="terrain-tabs" role="tablist" aria-label="Landscape Lab sections"><button type="button" role="tab" id="terrain-tab-landscape" aria-controls="terrain-section-landscape" data-tab="landscape" aria-selected="true" tabindex="0">Landscape</button><button type="button" role="tab" id="terrain-tab-shape" aria-controls="terrain-section-shape" data-tab="shape" aria-selected="false" tabindex="-1">Shape</button><button type="button" role="tab" id="terrain-tab-roads" aria-controls="terrain-section-roads" data-tab="roads" aria-selected="false" tabindex="-1">Roads</button><button type="button" role="tab" id="terrain-tab-surface" aria-controls="terrain-section-surface" data-tab="surface" aria-selected="false" tabindex="-1">Surface</button><button type="button" role="tab" id="terrain-tab-water" aria-controls="terrain-section-water" data-tab="water" aria-selected="false" tabindex="-1">Water</button><button type="button" role="tab" id="terrain-tab-sky" aria-controls="terrain-section-sky" data-tab="sky" aria-selected="false" tabindex="-1">Sky</button><button type="button" role="tab" id="terrain-tab-explore" aria-controls="terrain-section-explore" data-tab="explore" aria-selected="false" tabindex="-1">Explore</button></div>
	<div class="lab-pages"><section class="terrain-section" role="tabpanel" id="terrain-section-landscape" aria-labelledby="terrain-tab-landscape" data-tab-panel="landscape">
	<label class="terrain-field">Preset<select name="preset">${landscapeOptions.map(option).join('')}</select></label>
	<label class="terrain-field">Seed<input name="seed" type="text" maxlength="256" value="first-light"></label>
	<label class="terrain-field">World size<select name="worldSize">
		<option value="32768">20.4 mi per side</option>
		<option value="65536">40.7 mi per side</option>
		<option data-loaded-size hidden></option>
	</select></label>
	<label class="terrain-field">Rendering detail<select data-value="quality"><option value="0.5">Economical</option><option value="1" selected>Balanced</option><option value="2">Detailed</option></select></label>
	<details data-landform><summary>Landform parameters</summary>
	<p class="terrain-note">Preset measurements are rounded for display. Generate keeps their original precision until you edit them.</p>
	<fieldset class="terrain-landform-group"><legend>Elevation and hills</legend><div class="terrain-grid">
	<div class="landform-field"><label class="terrain-field">Height scale · ft<input data-unit="ft" aria-describedby="${hintPrefix}-heightScale" name="heightScale" data-increment="1" type="number" min="0" max="10000" value="420"></label>
		<small id="${hintPrefix}-heightScale">Scales the broad terrain vertically. Increase it for more relief between lowlands and peaks.</small></div>
	<div class="landform-field"><label class="terrain-field">Base bias<input aria-describedby="${hintPrefix}-baseBias" name="baseBias" data-increment="0.001" type="number" step="any"></label>
		<small id="${hintPrefix}-baseBias">Raises or lowers the whole landform before fine detail is added.</small></div>
	<div class="landform-field"><label class="terrain-field">Base frequency · cycles/mi<input data-unit="cyclesPerMile" aria-describedby="${hintPrefix}-baseFrequency" name="baseFrequency" data-increment="0.0001" type="number" step="any"></label>
		<small id="${hintPrefix}-baseFrequency">Sets the horizontal scale of the terrain. Lower values make broader hills and valleys.</small></div>
	<div class="landform-field"><label class="terrain-field">Rolling<input aria-describedby="${hintPrefix}-rolling" name="rolling" data-increment="0.001" type="number" step="any"></label>
		<small id="${hintPrefix}-rolling">Adds broad, rolling hills to the base terrain. Zero removes this contribution.</small></div>
	<div class="landform-field"><label class="terrain-field">Secondary<input aria-describedby="${hintPrefix}-secondary" name="secondary" data-increment="0.001" type="number" step="any"></label>
		<small id="${hintPrefix}-secondary">Adds a second set of hills at half the width of the main hills.</small></div>
	<div class="landform-field"><label class="terrain-field">Directional rise<input aria-describedby="${hintPrefix}-directionalRise" name="directionalRise" data-increment="0.001" type="number" step="any"></label>
		<small id="${hintPrefix}-directionalRise">Tilts the land from west to east. Positive values raise the eastern side.</small></div>
	</div></fieldset>
	<fieldset class="terrain-landform-group"><legend>Ridges and canyon walls</legend><div class="terrain-grid">
	<div class="landform-field"><label class="terrain-field">Ridge height<input aria-describedby="${hintPrefix}-ridgeHeight" name="ridgeHeight" data-increment="0.001" type="number" step="any"></label>
		<small id="${hintPrefix}-ridgeHeight">Deepens the valleys between ridge crests. Increase it for more pronounced ridgelines.</small></div>
	<div class="landform-field"><label class="terrain-field">Ridge roundness<input aria-describedby="${hintPrefix}-ridgeRoundness" name="ridgeRoundness" data-increment="0.001" type="number" step="any"></label>
		<small id="${hintPrefix}-ridgeRoundness">Rounds off ridge crests. Smaller values leave a sharper spine.</small></div>
	<div class="landform-field"><label class="terrain-field">Floor width<input aria-describedby="${hintPrefix}-floorWidth" name="floorWidth" data-increment="0.001" type="number" step="any"></label>
		<small id="${hintPrefix}-floorWidth">Widens the valley floor before the canyon walls start to rise.</small></div>
	<div class="landform-field"><label class="terrain-field">Wall width<input aria-describedby="${hintPrefix}-wallWidth" name="wallWidth" data-increment="0.0001" type="number" step="any"></label>
		<small id="${hintPrefix}-wallWidth">Spreads the climb from floor to rim. Smaller values make steeper walls.</small></div>
	<div class="landform-field"><label class="terrain-field">Wall height<input aria-describedby="${hintPrefix}-wallHeight" name="wallHeight" data-increment="0.001" type="number" step="any"></label>
		<small id="${hintPrefix}-wallHeight">Sets how far the plateau rises above the canyon floor, relative to the height scale.</small></div>
	</div></fieldset>
	<fieldset class="terrain-landform-group"><legend>Terraces and rock beds</legend><div class="terrain-grid">
	<div class="landform-field"><label class="terrain-field">Terrace spacing · ft<input data-unit="ft" aria-describedby="${hintPrefix}-terraceSpacing" name="terraceSpacing" data-increment="0.1" type="number" min="0" max="1000" value="0"></label>
		<small id="${hintPrefix}-terraceSpacing">Sets the vertical interval between ledges. Zero disables terracing.</small></div>
	<div class="landform-field"><label class="terrain-field">Terrace strength<input aria-describedby="${hintPrefix}-terraceStrength" name="terraceStrength" data-increment="0.001" type="number" step="any"></label>
		<small id="${hintPrefix}-terraceStrength">Sets how strongly the slope breaks into benches and risers.</small></div>
	<div class="landform-field"><label class="terrain-field">Bedding<input aria-describedby="${hintPrefix}-bedding" name="bedding" data-increment="0.001" type="number" step="any"></label>
		<small id="${hintPrefix}-bedding">Shapes terraces into irregular rock beds with eroded channels. Requires terrace spacing and strength above zero.</small></div>
	</div></fieldset>
	<fieldset class="terrain-landform-group"><legend>Fine detail</legend><div class="terrain-grid">
	<div class="landform-field"><label class="terrain-field">Fine detail · ft<input data-unit="ft" aria-describedby="${hintPrefix}-detailAmplitude" name="detailAmplitude" data-increment="0.1" type="number" min="0" max="1000" step="any" value="28"></label>
		<small id="${hintPrefix}-detailAmplitude">Sets the height of the first layer of small bumps on the terrain.</small></div>
	<div class="landform-field"><label class="terrain-field">Detail octaves<input aria-describedby="${hintPrefix}-detailOctaves" name="detailOctaves" type="number" step="1"></label>
		<small id="${hintPrefix}-detailOctaves">Sets how many progressively finer layers of bumps are added.</small></div>
	<div class="landform-field"><label class="terrain-field">Lacunarity<input aria-describedby="${hintPrefix}-lacunarity" name="lacunarity" data-increment="0.01" type="number" step="any"></label>
		<small id="${hintPrefix}-lacunarity">Sets the frequency multiplier per layer. At 2, each new layer has features half as wide.</small></div>
	<div class="landform-field"><label class="terrain-field">Persistence<input aria-describedby="${hintPrefix}-persistence" name="persistence" data-increment="0.001" type="number" step="any"></label>
		<small id="${hintPrefix}-persistence">Sets how much height each finer layer retains. At 0.5, each layer is half as tall.</small></div>
	<div class="landform-field"><label class="terrain-field">Slope scale<input aria-describedby="${hintPrefix}-slopeScale" name="slopeScale" data-increment="0.001" type="number" min="0.001" max="100" step="0.001" value="0.45"></label>
		<small id="${hintPrefix}-slopeScale">Sets how steep the ground can get before fine detail fades. Increase it to keep roughness on steeper slopes.</small></div>
	<div class="landform-field"><label class="terrain-field">Steep-face detail damping<input aria-describedby="${hintPrefix}-damping" name="damping" data-increment="0.01" type="number" min="0" max="100" step="0.1" value="2"></label>
		<small id="${hintPrefix}-damping">Smooths small bumps on steep ground. Higher values suppress the finest layers most.</small></div>
	</div></fieldset>
	<fieldset class="terrain-landform-group"><legend>Terrain bends</legend><div class="terrain-grid">
	<div class="landform-field"><label class="terrain-field">Domain warp · ft<input data-unit="ft" aria-describedby="${hintPrefix}-warpAmplitude" name="warpAmplitude" data-increment="0.1" type="number" min="0" max="5000" value="200"></label>
		<small id="${hintPrefix}-warpAmplitude">Shifts the terrain pattern sideways. Larger shifts make valleys and ridges meander more.</small></div>
	<div class="landform-field"><label class="terrain-field">Warp frequency · cycles/mi<input data-unit="cyclesPerMile" aria-describedby="${hintPrefix}-warpFrequency" name="warpFrequency" data-increment="0.0001" type="number" step="any"></label>
		<small id="${hintPrefix}-warpFrequency">Sets how frequently the bends change direction. Lower values produce longer, gentler bends.</small></div>
	</div></fieldset>
	<fieldset class="terrain-landform-group"><legend>Water and world edge</legend><div class="terrain-grid">
	<div class="landform-field"><label class="terrain-field">Starting water level · ft<input data-unit="ft" aria-describedby="${hintPrefix}-waterLevel" name="waterLevel" data-increment=".1" type="number" min="-10000" max="10000" step="any" value="0"></label>
		<small id="${hintPrefix}-waterLevel">Sets the water elevation on generation. Ground below this level is flooded.</small></div>
	<div class="landform-field"><label class="terrain-field">Surround<select aria-describedby="${hintPrefix}-surround" name="surround"><option value="sea">Open sea</option><option value="plateau">Desert plateau</option><option value="mountains">Continuing terrain</option><option value="none">None</option></select></label>
		<small id="${hintPrefix}-surround">Chooses the scenery beyond the playable area.</small></div>
	<div class="landform-field"><label class="terrain-field">Transition width · ft<input data-unit="ft" aria-describedby="${hintPrefix}-transitionWidth" name="transitionWidth" data-increment="1" type="number" step="any"></label>
		<small id="${hintPrefix}-transitionWidth">Sets how far beyond the boundary the terrain takes to blend into the surrounding scenery.</small></div>
	</div></fieldset>
	</details>
	<div class="terrain-row terrain-file-actions"><button data-action="generate">Generate</button><button data-action="reset-camera">Home view</button><button data-action="save">Save file</button><button data-action="load">Load file</button></div><input data-file type="file" accept=".json,application/json" hidden>
	</section>
	<section class="terrain-section" role="tabpanel" id="terrain-section-shape" aria-labelledby="terrain-tab-shape" data-tab-panel="shape" hidden>
	<label class="terrain-field terrain-wide">Tool<select data-value="tool"><option value="navigate">Navigate</option><option value="raise">Raise</option><option value="lower">Lower</option><option value="flatten">Flatten</option><option value="smooth">Smooth</option><option value="crater">Crater</option><option value="material">Paint material</option><option value="road">Road control points</option><option value="gate">Race gate</option><option value="balloon">Balloon</option></select></label>
	<div class="terrain-grid" data-balloon-tool hidden><label class="terrain-field">Balloon tool<select data-value="balloonTool"><option value="place">Place</option><option value="remove">Remove</option></select></label>
	<label class="terrain-field">Height above surface · ft<input data-unit="ft" data-value="balloonAltitude" type="number" min="12" max="1200" value="${325 * FOOT}"></label></div>
	<div class="terrain-grid" data-gate-tool hidden><label class="terrain-field">Gate role<select data-value="gateRole"><option value="start" selected>Start</option><option value="checkpoint">Checkpoint</option><option value="finish">Finish</option><option value="remove">Remove</option></select></label>
	<label class="terrain-field">Laps · start / finish courses only<input data-value="gateLaps" type="number" min="1" max="20" step="1" value="1"></label>
	<p class="terrain-note" data-gate-laps>Laps count when the start gate is also the finish.</p>
	<label class="terrain-field">Height above ground · ft<input data-unit="ft" data-value="gateAltitude" data-increment=".1" type="number" min="0" max="400" step="any" value="0"></label>
	<label class="terrain-field">Opening width · ft<input data-unit="ft" data-value="gateWidth" data-increment=".1" type="number" min="0" max="300" step="any" value="0"></label>
	<label class="terrain-field">Opening height · ft<input data-unit="ft" data-value="gateHeight" data-increment=".1" type="number" min="0" max="150" step="any" value="0"></label>
	<p class="terrain-note terrain-sizes" data-gate-sizes></p>
	<p class="terrain-note">Click the ground to place a gate facing the way you look. A gate on water floats. On land a gate stands on the ground at height 0, or hangs that high for the F5. An opening width or height of 0 takes the gate's own. Placing a second start or finish gate moves it, and a finish within one gate width of the start makes the start gate a start / finish gate. A gate that would overlap another is refused. Checkpoints count in the order you place them, and Remove takes away the gate you click.</p>
	<div class="terrain-row"><button data-action="gates-clear">Clear gates</button><button data-action="race-reset">Restart race · R</button></div></div>

	${range(
		'Radius · ft'
		, 'radius'
		, 2
		, 300
		, 1
		, 130 * FOOT
		, 'ft'
	)}${range(
		'Strength · ft'
		, 'amount'
		, .1
		, 200
		, .1
		, 40 * FOOT
		, 'ft'
	)}
	<label class="terrain-field">Flatten elevation · ft<input data-unit="ft" data-value="flattenHeight" data-increment=".1" type="number" min="-1000" max="3000" value="0"></label>
	<label class="terrain-field">Paint<select data-value="material"><option value="grass">Grass</option><option value="rock">Rock</option><option value="sand">Sand</option><option value="snow">Snow</option><option value="road">Road</option></select></label>
	<p class="terrain-note">Click the ground to apply one brush stroke. Ctrl+Z undoes; Ctrl+Shift+Z redoes.</p>
	<div class="terrain-row"><button data-action="undo">Undo</button><button data-action="redo">Redo</button></div>
	<div class="terrain-row" data-road-tool hidden><p class="terrain-note" data-road-count>0 control points</p>
	<button type="button" data-open-roads>Shape and apply in Roads</button></div>
	</section>
	<section class="terrain-section" role="tabpanel" id="terrain-section-roads" aria-labelledby="terrain-tab-roads" data-tab-panel="roads" hidden>
	<details><summary>Circuit generator</summary><div class="terrain-grid">
	<label class="terrain-field">Minimum feature height difference · ft<input data-unit="ft" data-value="circuitRelief" type="number" min="-2000" max="2000" step="1" value="${400 * FOOT}"></label>
	<label class="terrain-field">Start elevation · ft<input data-unit="ft" data-value="circuitStartHeight" type="number" min="-10000" max="10000" step="any" placeholder="Automatic"></label>
	<p class="terrain-note">Use a positive height for a mountain or plateau, or a negative height to circle water below the road.</p>
	<label class="terrain-check"><input data-value="circuitCauseways" type="checkbox">Allow causeways</label>
	<p class="terrain-note">Raise the road across water up to 98 ft deep while keeping the current water level.</p>
	<div class="terrain-row"><button data-action="circuit-find">Find circuit near camera</button><button data-action="circuit-cancel" hidden>Cancel</button></div>
	<p class="terrain-note" data-circuit-summary aria-live="polite"></p>
	<canvas data-circuit-map width="272" height="154" role="img" aria-label="Circuit route and gates" style="width:100%;height:auto;background:#0c221c;border-radius:5px" hidden></canvas>
	<div class="terrain-row"><button data-action="circuit-dry" disabled>Apply dry course</button><button data-action="circuit-flooded" disabled>Apply flooded course</button><button data-action="cpu-demo" hidden>Watch CPU racer</button></div>
	</div></details>
	<details class="terrain-section"><summary>Landscape journey</summary>
	<p class="terrain-note">Build surveys the terrain and lays the highway. When the road is ready, you start in the car at the western edge.</p>
	<div class="terrain-row"><label class="terrain-field">Departure<select data-journey="west">${journeyOptions('rolling-prairie')}</select></label>
	<label class="terrain-field">Map width<select data-journey="span"><option value="32768">20.4 mi (32 km class)</option><option value="65536">40.7 mi (64 km class)</option></select></label></div>
	<div class="terrain-row"><label class="terrain-field">Fork layout<select data-journey="fork"><option value="rejoin">Rejoin the highway</option><option value="divide">Separate destinations</option><option value="network">Linked landscape network</option></select></label>
	<label class="terrain-field">Destination<select data-journey="east">${journeyOptions('mountain')}</select></label>
	<label class="terrain-field" data-journey-south hidden>South branch destination<select data-journey="south">${landscapeOptions.map(option).join('')}</select></label></div>
	<div data-journey-network hidden><div class="terrain-route-scroll"><table data-journey-guide hidden><caption>Route network</caption>
	<thead><tr><th scope="col">Square</th><th scope="col">Left fork</th><th scope="col">Right fork</th></tr></thead><tbody></tbody></table></div>
	<p class="terrain-note">Start in the first square. Follow the gold road on the minimap and steer onto the branch you want. Two roads with the same destination meet again.</p>
	<details><summary>Edit route network</summary><label class="terrain-field">Squares and exits
	<textarea data-journey="network" rows="7" spellcheck="false">A rolling-prairie -> B,C
B canyon -> E,F
C mountain -> F,G
E limestone
F river-valley
G coast</textarea><small>One square per line. Enter its name, preset ID, and up to two exits. Reuse a name as an exit to rejoin. A row without exits ends the drive.</small>
	<small>Preset IDs: ${landscapeOptions.map(([id]) => id).join(', ')}</small></label></details><p class="terrain-note" data-journey-error role="status"></p></div>
	<div class="terrain-row"><button data-action="journey-build">Build new journey</button><button data-action="journey-drive" hidden>Drive from west edge</button><button data-action="journey-fork" hidden>Drive from fork</button></div><p class="terrain-note" data-journey-progress role="status"></p></details>
	<div class="terrain-section" data-road-workspace></div></section>
	<section class="terrain-section" role="tabpanel" id="terrain-section-surface" aria-labelledby="terrain-tab-surface" data-tab-panel="surface" hidden>
	<p class="terrain-note" data-surface-note></p>
	<div class="terrain-row"><select data-layer-preset aria-label="Material preset" aria-describedby="${surfaceId}-preset">${surfaceOptions.map(
		option
	).join(
		''
	)}</select>
	<button data-layer-load>Load preset surface</button><button data-layer-reset>Follow landform preset</button></div>
	<p class="terrain-note" id="${surfaceId}-preset">Load preset surface replaces the stack. Follow landform preset restores the materials for the current landform.</p>
	<h2>Material layers</h2><p class="terrain-note">The base layer covers the terrain. Colors and numbers preview as you edit an applied stack. After changing the layer order, a source, or a mask, choose Apply layers to preview the draft.</p>
	<div id="${surfaceId}" data-layer-fields></div><div class="terrain-row"><button data-layer-add>Add layer</button><button data-layer-apply disabled>Apply layers</button></div>
	<details><summary>Shared field settings</summary><p class="terrain-note">Each setting changes every layer that uses that feature.</p><div class="terrain-grid" data-shared-fields></div></details>
	<details><summary>Paint inks</summary><p class="terrain-note" id="${surfaceId}-paint">These colors apply to existing and new paint strokes and roads. At full coverage, paint covers the natural color and relief.</p><div class="terrain-grid" data-paint-inks></div></details>
	</section>
	<section class="terrain-section" role="tabpanel" id="terrain-section-water" aria-labelledby="terrain-tab-water" data-tab-panel="water" hidden>
	<label class="terrain-field">Water level · ft<input data-unit="ft" data-value="waterLevel" data-increment=".1" type="number" min="-10000" max="10000" step="any" value="0"></label>
	${range('Choppiness · %', 'waterChoppiness', 0, 300, 1, 100)}
	<label class="terrain-control"><span>Water color</span><input data-value="waterColor" type="color" value="#287276"></label>
	${range('Transparency', 'waterTransparency', 0, 1, .01, .85)}
	${range('Reflectivity', 'waterReflectivity', 0, 1, .01, .85)}
	<label class="terrain-control"><span>Fog density · per foot</span><input data-fog type="range" min="0" max="1" step=".005" value=".52" aria-label="Fog density per foot"><output data-fog-output>0.0107</output></label>
	${range('Shore and wake foam', 'waterFoam', 0, 1, .01, .65)}
	<p class="terrain-note">Lower fog density to see the bottom in deeper water.</p>
	</section>
	<section class="terrain-section" role="tabpanel" id="terrain-section-sky" aria-labelledby="terrain-tab-sky" data-tab-panel="sky" hidden>
	${range(
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
		, .01
		, 1
	)}${range(
		'Exposure'
		, 'exposure'
		, .1
		, 4
		, .1
		, 1
	)}
	${range('Sky light', 'skyLight', 0, 3, .01, 1)}
	<label class="terrain-control"><span>Sky color</span><input data-value="skyColor" type="color" value="#4b698a"></label>
	<label class="terrain-control"><span>Haze color</span><input data-value="hazeColor" type="color" value="#ffffff"></label>
	<p class="terrain-note">Sky color sets the blue overhead and how clear the distance looks. Haze color tints the haze; Haze sets how much. Sky light brightens shaded ground.</p>
	${range('Night glow', 'nightGlow', 0, 1, .01, .5)}
	<label class="terrain-check"><input data-value="clock" type="checkbox">Animate the sun</label>
	<h2>Atmospheric layers</h2>
	<p class="terrain-note">Layers follow the landform preset until you edit them. Use negative heights below sea level. Haze thins at both boundaries and builds with distance through the layer.</p>
	<div data-air-list></div><div class="terrain-row"><button type="button" data-air-add disabled>Add atmosphere layer</button>
	<button type="button" data-air-preset disabled>Use preset layers</button></div>
	<template data-air-template><fieldset class="terrain-landform-group"><legend></legend>
	<div class="terrain-grid">
	<label class="terrain-field">Layer name<input type="text" maxlength="40" required data-air-key="name"></label>
	<label class="terrain-field">Scattered light<input type="color" data-air-key="color"></label>
	<label class="terrain-field">Base (ft above sea level)<input data-unit="ft" type="number" data-increment=".1" step="any" required data-air-key="bottom"></label>
	<label class="terrain-field">Top (ft above sea level)<input data-unit="ft" type="number" data-increment=".1" step="any" required data-air-key="top"></label>
	<div class="surface-field"><label class="terrain-field">Density per mile<input data-unit="densityPerMile" type="number" min="0" data-increment=".001" step=".001" required data-air-key="density"></label>
	<small>Mean density through the layer. Across a one-mile-thick layer at 0.1, about 90% of direct light gets through.</small></div>
	<label class="terrain-check"><input type="checkbox" data-air-key="enabled">Visible</label>
	<div class="terrain-row"><button type="button" data-air-remove>Remove atmosphere layer</button></div>
	</div></fieldset></template>
	</section>
	<section class="terrain-section" role="tabpanel" id="terrain-section-explore" aria-labelledby="terrain-tab-explore" data-tab-panel="explore" hidden>
	<div class="terrain-row"><button data-action="car">Drive car · C</button><button data-action="craft">Watercraft · B</button><button data-action="jet">Fly F5 · J</button><button data-action="fly" hidden>Return to flight · F</button><button data-action="delivery">Package run</button></div>
	<div class="terrain-row"><button data-action="pause">Pause · P</button><button data-action="fullscreen">Fullscreen</button></div>
	<div data-jet-actions hidden><div class="terrain-row"><button data-action="jet-view">Cockpit · V</button><button data-action="balloon-practice">Balloon practice</button></div>
	<div class="terrain-row"><button data-action="balloons-reset">Reset balloons</button></div>
	<p class="terrain-note">The F5's launch, Vulcan, balloon and view buttons show at the top left of the view for the controller or keyboard you are using.</p></div>
	${range('Flight speed · kts', 'speed', 10, 300, 5, 200 * KNOT, 'kts')}
	<label class="terrain-field">Car headlights · L<select data-value="headlights"><option value="auto" selected>Auto · on in low light</option><option value="on">On</option><option value="off">Off</option></select></label>
	<label class="terrain-field terrain-wide">Jet controls<select data-value="jetControls"><option value="novice" selected>Novice · bank to turn, levels itself</option><option value="expert">Expert · roll, pitch and rudder</option></select></label>
	<label class="terrain-check"><input data-value="invertPitch" type="checkbox">Push the stick forward to climb</label>
	<label class="terrain-check"><input data-show-diagnostics type="checkbox">Show diagnostics</label>
	<label class="terrain-field terrain-wide">Music volume<input type="range" min="0" max="100" step="1" data-music-level></label>
	<details><summary>Driving and flight controls</summary><p class="terrain-note">Press M or click the left stick to show or hide the car or F5 minimap. Press N or tap D-pad ↓ to change its zoom.</p>
	<p class="terrain-note">Right-drag: look · WASD: fly or drive · Q/E: down/up · Shift: fast flight · Shift / LB: car nitrous · Space: handbrake · L: headlights · R: reset the race · H: hide controls · Esc: pause menu. P freezes waves and physics and leaves editing available.</p>
	<p class="terrain-note">D-pad ↑ boards the F5, ← boards the car and → boards the watercraft. In the F5, ↑ cycles balloons. On the watercraft, ↓ returns to free flight. Novice uses RT/LT for speed. Expert uses LT/RT for rudder and Y/B for speed. Tap Back for handling, Back + ↓ for free flight, or Back + Y to reset the race. Click the right stick to return to the road during a race or landscape journey. Start opens the pause menu.</p>
	</details><details><summary>Credits</summary><p class="terrain-note">Watercraft adapted from <a href="https://sketchfab.com/3d-models/jetski-15e79c65f67a468592cdc2ac1f5b389b" target="_blank" rel="noopener">Jetski by Futurealiti</a>  · Car adapted from <a href="https://sketchfab.com/3d-models/ferrari-testarossa-84-low-poly-2eb128eab34a4c25bfcdb2d75152d31a" target="_blank" rel="noopener">Ferrari-Testarossa-84-low-poly by XGLOL235</a> · Aircraft adapted from <a href="https://sketchfab.com/3d-models/f5-8089d66a8a8748b2a4b049b3c06f6a02" target="_blank" rel="noopener">F5 by manilov.ap</a> · <a href="https://sketchfab.com/3d-models/cc0-balloon-6068c15b20bc4d9689c72b9e15a78891" target="_blank" rel="noopener">Balloon by plaggy</a> · Race gates adapted from <a href="https://sketchfab.com/3d-models/finish-line-70aecb6bac234266bdebd22322396caa" target="_blank" rel="noopener">Finish Line by Kemal Çolak</a> and <a href="https://sketchfab.com/3d-models/davita-finish-line-2020-003d816301084f719897503bdb4ffbc3" target="_blank" rel="noopener">Davita Finish-Line 2020 by BrandXR</a> · <a href="https://creativecommons.org/licenses/by/4.0/" target="_blank" rel="noopener">CC BY 4.0</a>. <a href="https://sketchfab.com/3d-models/heatseeker-air-air-missile-e9c5bba6f3b34f73b22961a726667856" target="_blank" rel="noopener">Projectile by Martini-SF</a> · <a href="https://creativecommons.org/licenses/by-nc/4.0/" target="_blank" rel="noopener">CC BY-NC 4.0</a>. HUD reference: Airforce Delta, Konami.</p></details>
	</section>
	</div></div></details><p class="terrain-menu-live" data-menu-live aria-live="polite"></p><div class="terrain-hud"><p data-journey-sign hidden></p><p data-status role="status">Preparing the landscape…</p><p class="terrain-race" data-race hidden></p><p class="terrain-pending" data-pending>Preparing terrain…</p><pre data-diagnostics></pre></div>`;
}
