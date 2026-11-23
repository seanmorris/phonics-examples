/** Web Audio renders the crank-angle pressure waves supplied by PHP on its own audio clock. */
export function prepareEngine(context, definition)
{
	const waves = definition.banks.map(
		({real, imag}) => context.createPeriodicWave(
			Float32Array.from(real)
			, Float32Array.from(imag)
			, {disableNormalization: true}
		)
	);

	const bytes = atob(definition.flowNoise);
	const noise = context.createBuffer(1, bytes.length, definition.noiseRate);
	const samples = noise.getChannelData(0);

	for(let i = 0; i < samples.length; i++) samples[i] = bytes.charCodeAt(i)/127.5 - 1;

	return {waves, noise};
}

export function startEngine(context, instrument, tone)
{
	const clock = context.createConstantSource(), noise = context.createBufferSource();
	const muffler = context.createBiquadFilter(), body = context.createBiquadFilter(), volume = context.createGain();
	const flutter = context.createBiquadFilter(), jitter = context.createGain();
	const breath = context.createBiquadFilter(), flow = context.createGain(), pulses = context.createGain();
	const sources = [clock, noise], nodes = [muffler,body,volume,flutter,jitter,breath,flow,pulses];

	clock.offset.value = tone.cycle;
	noise.buffer = instrument.noise;
	noise.loop = true;

	muffler.frequency.value = tone.cutoff;
	muffler.Q.value = .63;
	body.type = 'peaking';
	body.frequency.value = 245;
	body.Q.value = .8;
	body.gain.value = 2.5;
	volume.gain.value = 0;
	muffler.connect(body).connect(volume).connect(context.destination);

	// The same small irregularity reaches both banks. Opening the throttle admits more pulsed exhaust noise.
	flutter.frequency.value = 11;
	flutter.Q.value = .55;
	jitter.gain.value = tone.jitter;
	noise.connect(flutter).connect(jitter);
	breath.type = 'bandpass';
	breath.frequency.value = 1450;
	breath.Q.value = .65;
	pulses.gain.value = .38;
	flow.gain.value = tone.flow;
	noise.connect(breath).connect(pulses).connect(flow).connect(muffler);

	for(const [bank, wave] of instrument.waves.entries())
	{
		const exhaust = context.createOscillator(), direct = context.createGain();
		const reflection = context.createDelay(.01), echo = context.createGain();
		exhaust.setPeriodicWave(wave);
		exhaust.frequency.value = 0;
		clock.connect(exhaust.frequency);
		jitter.connect(exhaust.detune);

		direct.gain.value = bank === 0 ? 1 : .78;
		reflection.delayTime.value = bank === 0 ? .0027 : .0038;
		echo.gain.value = .24;
		exhaust.connect(direct).connect(muffler);
		direct.connect(reflection).connect(echo).connect(muffler);
		direct.connect(pulses.gain);
		sources.push(exhaust);
		nodes.push(direct, reflection, echo);
	}

	const now = context.currentTime;

	for(const source of sources) source.start(now);

	volume.gain.setTargetAtTime(tone.level, now, .018);

	return {

		update(next) {
			const at = context.currentTime;

			if(next.cycle !== tone.cycle) clock.offset.setTargetAtTime(next.cycle, at, .018);

			if(next.level !== tone.level) volume.gain.setTargetAtTime(next.level, at, .033);

			if(next.cutoff !== tone.cutoff) muffler.frequency.setTargetAtTime(next.cutoff, at, .035);

			if(next.flow !== tone.flow) flow.gain.setTargetAtTime(next.flow, at, .035);

			if(next.jitter !== tone.jitter) jitter.gain.setTargetAtTime(next.jitter, at, .045);

			tone = next;
		}


		, stop() {
			const at = context.currentTime;
			volume.gain.setTargetAtTime(0, at, .012);

			clock.onended = () => { for(const node of [...sources,...nodes]) node.disconnect(); };

			for(const source of sources) source.stop(at + .075);
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
	let context, definition, instrument, engine, tireBuffer, tireLoading, tire, parkTimer;
	let closed = false, contextLost = false, pageHidden = false, parked = false;
	let tone = {cycle: 0}, tireLevel = 0, tireRate = 1;

	async function loadTire()
	{
		const url = new URL('assets/car-audio/tires_squal_loop.wav', archive.applicationUrl).href;
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

		tireBuffer = joinSkid(context, buffer);
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

		if(context?.state !== 'running' || !definition) return;

		instrument ??= prepareEngine(context, definition);

		if(engine) engine.update(tone);
		else engine = startEngine(context, instrument, tone);

		// Archive reads must start after returning from the current PHP frame.
		tireLoading ??= Promise.resolve().then(loadTire).catch(error => {
			if(!closed) console.error('Car tire audio could not be loaded', error);
		});

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

	module.terrainEngineVoice = json => { definition = JSON.parse(json); };

	module.terrainCarSound = (cycle, level, cutoff, flow, jitter, skid, rate) => {
		tone = {cycle, level, cutoff, flow, jitter};
		tireLevel = skid;
		tireRate = rate;
		update();
	};

	return () => {
		closed = true;
		page.clearTimeout(parkTimer);
		delete module.terrainCarSound;
		delete module.terrainEngineVoice;
		engine?.stop();
		stopTire();
		resources.close();

		if(context) void context.close().catch(() => {});
	};
}
