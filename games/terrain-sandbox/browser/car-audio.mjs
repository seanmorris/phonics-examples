/** The PHP-generated recording contains 64 four-stroke cycles at its reference speed. */
export function prepareEngine(context, recording)
{
	const noise = context.createBuffer(1, recording.length, recording.sampleRate);
	noise.copyToChannel(recording.getChannelData(3), 0);

	return {pressure: recording, rate: 64/recording.duration, noise};
}

/** The far bank's share of the note. Each bank's six firings set the pitch; the near pipe carries most of it. */
const FAR_BANK = .15;

/** Center of the tailpipe rasp for a four-stroke cycle rate in hertz. */
const raspHz = cycle => 500 + 12*cycle;

/**
 * Feed the collectors into a pipe whose open end reflects pressure back inverted. The round trip is fixed in time,
 * so its resonances stay put while the firing harmonics sweep through them with the revs.
 */
function pipe(context, nodes, input, output, roundTrip, reflection)
{
	const sum = context.createGain(), delay = context.createDelay(.02), damping = context.createBiquadFilter();
	const back = context.createGain();
	delay.delayTime.value = roundTrip;
	damping.frequency.value = 2600;
	damping.Q.value = -3;
	back.gain.value = reflection;
	input.connect(sum).connect(output);
	sum.connect(delay).connect(damping).connect(back).connect(sum);
	nodes.push(sum, delay, damping, back);
}

export function startEngine(context, instrument, tone, output = context.destination)
{
	const crank = context.createBufferSource(), noise = context.createBufferSource();
	const banks = context.createChannelSplitter(4), volume = context.createGain();
	const collector = context.createGain(), far = context.createGain(), port = context.createGain();
	const tailpipes = context.createGain(), body = context.createBiquadFilter(), rumble = context.createBiquadFilter();
	const muffler = context.createBiquadFilter(), baffle = context.createBiquadFilter();
	const rasp = context.createBiquadFilter(), tear = context.createBiquadFilter();
	const gate = context.createGain(), flow = context.createGain();
	const scatter = context.createBiquadFilter(), scatterGain = context.createGain();
	const nodes = [banks,volume,collector,far,port,tailpipes,body,rumble,muffler,baffle];
	nodes.push(rasp, tear, gate, flow, scatter, scatterGain);

	crank.buffer = instrument.pressure;
	crank.playbackRate.value = tone.cycle/instrument.rate;
	crank.loop = true;
	crank.connect(banks);
	noise.buffer = instrument.noise;
	noise.playbackRate.value = .5;
	noise.loop = true;

	// The near bank's pipe carries the note: its six firings per cycle, rpm/20. The far bank's firings fall between
	// them and add the octave above.
	banks.connect(collector, 0);
	far.gain.value = FAR_BANK;
	banks.connect(far, 1).connect(collector);

	// Gas tears out of the tailpipes with each discharge.
	// The rasp leaves after the muffler, and faster gas rasps higher.
	rasp.type = 'bandpass';
	rasp.frequency.value = raspHz(tone.cycle);
	rasp.Q.value = .7;
	tear.frequency.value = 3200;
	tear.Q.value = -3;
	gate.gain.value = .12;
	banks.connect(gate.gain, 2);
	flow.gain.value = tone.flow;
	noise.connect(rasp).connect(tear).connect(gate).connect(flow).connect(volume);

	// Airflow varies the pressure at the pipes from one firing to the next.
	scatter.frequency.value = 220;
	scatter.Q.value = -3;
	scatterGain.gain.value = tone.scatter;
	noise.connect(scatter).connect(scatterGain).connect(port.gain);
	collector.connect(port);

	// Left and right tailpipes of different lengths, heard together.
	pipe(context, nodes, port, tailpipes, .0053, -.42);
	pipe(context, nodes, port, tailpipes, .0069, -.34);
	// Sets the exhaust's loudness against the tire recordings.
	tailpipes.gain.value = 1.7;

	// The muffler's volume booms below the note, and subsonic pressure drift stays out of the speakers.
	body.type = 'lowshelf';
	body.frequency.value = 180;
	body.gain.value = 17;
	rumble.type = 'highpass';
	rumble.frequency.value = 30;
	rumble.Q.value = -3;

	// Two baffles round the note off steeply above its cutoff.
	for(const stage of [muffler, baffle])
	{
		stage.frequency.value = tone.cutoff;
		stage.Q.value = -3;
	}

	volume.gain.value = 0;
	tailpipes.connect(body).connect(rumble).connect(muffler);
	muffler.connect(baffle).connect(volume).connect(output);

	const now = context.currentTime;
	crank.start(now);
	noise.start(now);
	volume.gain.setTargetAtTime(tone.level, now, .018);

	return {

		update(next) {
			const at = context.currentTime;

			if(next.cycle !== tone.cycle)
			{
				crank.playbackRate.setTargetAtTime(next.cycle/instrument.rate, at, .018);
				rasp.frequency.setTargetAtTime(raspHz(next.cycle), at, .035);
			}

			if(next.level !== tone.level) volume.gain.setTargetAtTime(next.level, at, .033);

			if(next.cutoff !== tone.cutoff)
			for(const stage of [muffler, baffle]) stage.frequency.setTargetAtTime(next.cutoff, at, .035);

			if(next.flow !== tone.flow) flow.gain.setTargetAtTime(next.flow, at, .035);

			if(next.scatter !== tone.scatter) scatterGain.gain.setTargetAtTime(next.scatter, at, .045);

			tone = next;
		}


		, stop() {
			const at = context.currentTime;
			volume.gain.setTargetAtTime(0, at, .012);

			crank.onended = () => { for(const node of [crank,noise,...nodes]) node.disconnect(); };

			crank.stop(at + .075);
			noise.stop(at + .075);
		}
	};
}

/** Car audio levels in percent. The player's choice, remembered in this browser. */
export const AUDIO_DEFAULTS = {master: 80, engine: 100, tires: 100};

/** Settings the soundtrack owns. The pause menu reaches them through the same bridge. */
const MUSIC_SETTINGS = ['tracks', 'track', 'music'];

const AUDIO_PREFERENCE = 'terrain-sandbox.audio';

/** A level's gain. Squaring gives the lower half of a slider the quiet end, where ears tell levels apart. */
const fader = percent => (percent/100)**2;

/**
 * Check a stored or requested car audio level.
 * @param {string} name master, engine or tires.
 * @param {*} value Percent from 0 to 100.
 * @returns {boolean} Whether the value is valid for that level.
 */
export function audioSetting(name, value)
{
	return name in AUDIO_DEFAULTS && Number.isFinite(value) && value >= 0 && value <= 100;
}

/** Join the end of the skid to its beginning over 80 ms. The loop wraps between consecutive original samples. */
export function joinSkid(context, buffer)
{
	const overlap = Math.min(Math.round(.08*buffer.sampleRate), Math.floor(buffer.length/2));
	const length = buffer.length - overlap;
	const loop = context.createBuffer(buffer.numberOfChannels, length, buffer.sampleRate);

	for(let c = 0; c < loop.numberOfChannels; c++)
	{
		const original = buffer.getChannelData(c), joined = loop.getChannelData(c);
		joined.set(original.subarray(0, length));

		for(let i = 0; i < overlap; i++)
		{
			const t = i/Math.max(1, overlap-1);
			const angle = t*t*(3-2*t)*Math.PI/2;
			joined[i] = original[length+i]*Math.cos(angle) + original[i]*Math.sin(angle);
		}
	}

	return loop;
}

export function activate({module, canvas, archive, signal})
{
	const doc = canvas.ownerDocument, page = doc.defaultView;
	const Context = page.AudioContext ?? page.webkitAudioContext;
	const resources = archive.store.scope(signal);
	let context, instrument, engine, tireBuffer, tire, parkTimer, loading, buses;
	let closed = false, contextLost = false, pageHidden = false, parked = false;
	let tone = {cycle: 0}, tireLevel = 0, tireRate = 1;
	const mix = {...AUDIO_DEFAULTS};

	try
	{
		const saved = JSON.parse(page.localStorage?.getItem(AUDIO_PREFERENCE) ?? 'null') ?? {};

		for(const [name, value] of Object.entries(saved)) if(audioSetting(name, value)) mix[name] = value;
	}
	catch
	{
		/* Without storage the defaults play for this visit. */
	}

	/** Set each bus to its level. The engine and tires pass through the master. */
	function level()
	{
		if(!buses) return;

		const at = context.currentTime;

		for(const name of ['master', 'engine', 'tires']) buses[name].gain.setTargetAtTime(fader(mix[name]), at, .03);
	}

	async function loadSound(file, ready)
	{
		if(closed) return;

		const url = new URL('assets/car-audio/' + file, archive.applicationUrl).href;
		let bytes;

		try
		{
			bytes = await resources.read(url);
		}
		finally
		{
			resources.release(url);
		}

		if(closed) return;

		const buffer = await context.decodeAudioData(
			bytes.buffer.slice(bytes.byteOffset, bytes.byteOffset+bytes.byteLength)
		);

		if(closed) return;

		ready(buffer);
		update();
	}

	function stopTire()
	{
		if(!tire) return;

		const at = context.currentTime;
		tire.gain.gain.setTargetAtTime(0, at, .018);
		tire.source.stop(at + .09);
		tire = undefined;
	}

	function update()
	{
		if(closed || !Context) return;

		if(!tone.cycle || pageHidden || doc.hidden || contextLost)
		{
			engine?.stop();
			engine = undefined;
			stopTire();

			if(context?.state === 'running' && parkTimer === undefined)
			{
				parkTimer = page.setTimeout(() => {
					parkTimer = undefined;
					parked = true;
					void context.suspend().catch(() => {});
				}, 125
				);
			}

			return;
		}

		page.clearTimeout(parkTimer);
		parkTimer = undefined;

		if(!context || parked && context.state === 'suspended') unlock();

		if(context?.state !== 'running') return;

		// Start archive reads after the PHP frame returns. Either sound can start as soon as it is ready.
		if(!loading)
		{
			loading = true;

			const sounds = [
				['flat-twelve.wav', buffer => { instrument = prepareEngine(context, buffer); }]
				, ['tires_squal_loop.wav', buffer => { tireBuffer = joinSkid(context, buffer); }]
			];

			for(const [file, ready] of sounds)
			Promise.resolve().then(() => loadSound(file, ready)).catch(error => {
				if(!closed) console.error('Car audio could not load ' + file, error);
			});
		}

		if(engine) engine.update(tone);
		else if(instrument) engine = startEngine(context, instrument, tone, buses.engine);

		if(!tireBuffer || tireLevel < .00025)
		{
			stopTire();

			return;
		}

		if(!tire)
		{
			const source = context.createBufferSource(), gain = context.createGain();
			source.buffer = tireBuffer;
			source.loop = true;
			source.playbackRate.value = tireRate;
			gain.gain.value = 0;
			source.connect(gain).connect(buses.tires);

			source.onended = () => { source.disconnect(); gain.disconnect(); };

			source.start();
			tire = {source, gain};
		}

		if(tire.level !== tireLevel)
		{
			tire.gain.gain.setTargetAtTime(tireLevel, context.currentTime, .045);
			tire.source.playbackRate.setTargetAtTime(tireRate, context.currentTime, .045);
			tire.level = tireLevel;
		}
	}

	function unlock()
	{
		if(!Context || closed || doc.hidden) return;

		if(!context)
		{
			context = new Context();
			context.addEventListener('statechange', update, {signal});
			// The engine and tires each have a level, and both pass through the master.
			buses = {};

			for(const name of ['master', 'engine', 'tires'])
			{
				buses[name] = context.createGain();
				buses[name].gain.value = fader(mix[name]);
				buses[name].connect(name === 'master' ? context.destination : buses.master);
			}
		}

		parked = false;

		if(context.state === 'suspended') void context.resume().catch(() => {});
	}

	function gesture(event)
	{
		if(tone.cycle || event.code === 'KeyC'
			|| event.target?.closest?.('[data-action="car"],[data-action="delivery"]')
		)
		unlock();
	}

	for(const type of ['keydown','pointerdown','touchend']) doc.addEventListener(type, gesture, {capture: true,signal});
	doc.addEventListener('visibilitychange', update, {signal});
	page.addEventListener('pagehide', () => { pageHidden = true; update(); }, {signal});
	page.addEventListener('pageshow', () => { pageHidden = false; update(); }, {signal});
	canvas.addEventListener('webglcontextlost', () => { contextLost = true; update(); }, {signal});
	canvas.addEventListener('webglcontextrestored', () => { contextLost = false; update(); }, {signal});

	module.terrainCarSound = (cycle, level, cutoff, flow, scatter, skid, rate) => {
		tone = {cycle, level, cutoff, flow, scatter};
		tireLevel = skid;
		tireRate = rate;
		update();
	};

	/**
	 * The pause menu reads levels through this. The soundtrack answers for the track list, the track and the music
	 * level through module.terrainMusic, {get(name), set(name, value)}.
	 */
	module.terrainAudioGet = name => {
		if(MUSIC_SETTINGS.includes(name))
		return module.terrainMusic?.get(name) ?? {tracks: '["Off"]', track: 'Off', music: 0}[name];

		return mix[name];
	};

	/** The pause menu sets levels through this. The soundtrack also hears the master level. */
	module.terrainAudioSet = (name, value) => {
		if(MUSIC_SETTINGS.includes(name)) return Boolean(module.terrainMusic?.set(name, value));

		if(!audioSetting(name, value)) return false;

		mix[name] = value;

		try
		{
			page.localStorage?.setItem(AUDIO_PREFERENCE, JSON.stringify(mix));
		}
		catch
		{
			/* Storage can be unavailable; the levels then last for this visit. */
		}

		if(name === 'master') module.terrainMusic?.set('master', value);

		level();

		return true;
	};

	return () => {
		closed = true;
		page.clearTimeout(parkTimer);
		delete module.terrainCarSound;
		delete module.terrainAudioGet;
		delete module.terrainAudioSet;
		engine?.stop();
		stopTire();
		resources.close();

		if(context) void context.close().catch(() => {});
	};
}
