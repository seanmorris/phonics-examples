/** The PHP-generated recording contains 64 four-stroke cycles at its reference speed. */
export function prepareEngine(context, recording)
{
	const noise = context.createBuffer(1, recording.length, recording.sampleRate);
	noise.copyToChannel(recording.getChannelData(3), 0);

	return {pressure: recording, rate: 64/recording.duration, noise};
}

/** Turbulent flow noise centers this far into the muffler's passband. */
const HISS = .40;

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

export function startEngine(context, instrument, tone)
{
	const crank = context.createBufferSource(), noise = context.createBufferSource();
	const banks = context.createChannelSplitter(4), volume = context.createGain();
	const collector = context.createGain(), port = context.createGain(), tailpipes = context.createGain();
	const body = context.createBiquadFilter(), rumble = context.createBiquadFilter();
	const muffler = context.createBiquadFilter(), turbulence = context.createBiquadFilter();
	const gate = context.createGain(), flow = context.createGain();
	const scatter = context.createBiquadFilter(), scatterGain = context.createGain();
	const nodes = [banks,volume,collector,port,tailpipes,body,rumble,muffler,turbulence,gate,flow,scatter,scatterGain];

	crank.buffer = instrument.pressure;
	crank.playbackRate.value = tone.cycle/instrument.rate;
	crank.loop = true;
	crank.connect(banks);
	noise.buffer = instrument.noise;
	noise.playbackRate.value = .5;
	noise.loop = true;

	// Both banks reach the listener at the same level,
	// so their interleaved firings sum to the full twelve-cylinder note.
	banks.connect(collector, 0);
	banks.connect(collector, 1);

	// Turbulent gas rushes through the collectors with each discharge. Faster gas hisses higher.
	turbulence.type = 'bandpass';
	turbulence.frequency.value = HISS*tone.cutoff;
	turbulence.Q.value = .55;
	gate.gain.value = .12;
	banks.connect(gate.gain, 2);
	flow.gain.value = tone.flow;
	noise.connect(turbulence).connect(gate).connect(flow).connect(collector);

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

	// The muffler shell rings in the low mids, and subsonic pressure drift stays out of the speakers.
	body.type = 'peaking';
	body.frequency.value = 420;
	body.Q.value = .9;
	body.gain.value = 3;
	rumble.type = 'highpass';
	rumble.frequency.value = 45;
	rumble.Q.value = -3;
	muffler.frequency.value = tone.cutoff;
	muffler.Q.value = -3;
	volume.gain.value = 0;
	tailpipes.connect(body).connect(rumble).connect(muffler).connect(volume).connect(context.destination);

	const now = context.currentTime;
	crank.start(now);
	noise.start(now);
	volume.gain.setTargetAtTime(tone.level, now, .018);

	return {

		update(next) {
			const at = context.currentTime;

			if(next.cycle !== tone.cycle) crank.playbackRate.setTargetAtTime(next.cycle/instrument.rate, at, .018);

			if(next.level !== tone.level) volume.gain.setTargetAtTime(next.level, at, .033);

			if(next.cutoff !== tone.cutoff)
			{
				muffler.frequency.setTargetAtTime(next.cutoff, at, .035);
				turbulence.frequency.setTargetAtTime(HISS*next.cutoff, at, .035);
			}

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
	let context, instrument, engine, tireBuffer, tire, parkTimer, loading;
	let closed = false, contextLost = false, pageHidden = false, parked = false;
	let tone = {cycle: 0}, tireLevel = 0, tireRate = 1;

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
		else if(instrument) engine = startEngine(context, instrument, tone);

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
			source.connect(gain).connect(context.destination);

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

	return () => {
		closed = true;
		page.clearTimeout(parkTimer);
		delete module.terrainCarSound;
		engine?.stop();
		stopTire();
		resources.close();

		if(context) void context.close().catch(() => {});
	};
}
