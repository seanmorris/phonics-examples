/** The PHP-generated recording contains 64 four-stroke cycles at its reference speed. */
export function prepareEngine(context, recording)
{
	const noise = context.createBuffer(1, recording.length, recording.sampleRate);
	noise.copyToChannel(recording.getChannelData(3), 0);

	return {pressure: recording, rate: 64/recording.duration, noise};
}

export function startEngine(context, instrument, tone)
{
	const crank = context.createBufferSource(), noise = context.createBufferSource();
	const banks = context.createChannelSplitter(4), volume = context.createGain();
	const muffler = context.createBiquadFilter(), collector = context.createBiquadFilter();
	const mount = context.createBiquadFilter(), mountGain = context.createGain();
	const casework = context.createBiquadFilter(), casingGain = context.createGain(), opposite = context.createGain();
	const intake = context.createBiquadFilter(), breath = context.createGain(), flow = context.createGain();
	const scatter = context.createBiquadFilter(), scatterGain = context.createGain(), port = context.createGain();

	const nodes = [banks,volume,muffler,collector,mount,mountGain,casework,casingGain,opposite];
	nodes.push(intake, breath, flow, scatter, scatterGain, port);

	crank.buffer = instrument.pressure;
	crank.playbackRate.value = tone.cycle/instrument.rate;
	crank.loop = true;
	crank.connect(banks);
	noise.buffer = instrument.noise;
	noise.playbackRate.value = .5;
	noise.loop = true;

	volume.gain.value = 0;
	muffler.frequency.value = tone.cutoff;
	muffler.Q.value = -3;
	collector.type = 'peaking';
	collector.frequency.value = 238;
	collector.Q.value = .78;
	collector.gain.value = 2.5;
	muffler.connect(collector).connect(volume).connect(context.destination);

	// Both tailpipes carry the firing order of a six-cylinder bank. Their paths have different
	// lengths. Pressure reflected at the open end returns with opposite polarity.
	for(const [bank, level, delay] of [[0,1,.0041],[1,.42,.0057]])
	{
		const direct = context.createGain(), reflection = context.createDelay(.01), echo = context.createGain();
		direct.gain.value = level;
		reflection.delayTime.value = delay;
		echo.gain.value = -.34;

		banks.connect(direct, bank);
		direct.connect(port);
		direct.connect(reflection).connect(echo).connect(port);
		nodes.push(direct, reflection, echo);
	}

	// The two flat banks push the case in opposite directions. Its damped motion carries
	// the lower bank firing note even as the airborne exhaust builds higher harmonics.
	opposite.gain.value = -1;
	banks.connect(casework, 0);
	banks.connect(opposite, 1);
	opposite.connect(casework);
	casework.frequency.value = 465;
	casework.Q.value = 0;
	casingGain.gain.value = .90;
	casework.connect(casingGain).connect(volume);

	// Airflow varies the pressure at the pipes and rushes through the intake in time with the valves.
	scatter.frequency.value = 220;
	scatter.Q.value = -3;
	scatterGain.gain.value = tone.scatter;
	noise.connect(scatter).connect(scatterGain).connect(port.gain);
	port.connect(muffler);

	intake.type = 'bandpass';
	intake.frequency.value = 545;
	intake.Q.value = .6;
	breath.gain.value = .40;
	flow.gain.value = tone.flow;
	banks.connect(breath.gain, 0);
	noise.connect(intake).connect(breath).connect(flow).connect(muffler);

	mount.frequency.value = 155;
	mount.Q.value = -3;
	mountGain.gain.value = tone.mountGain;
	banks.connect(mount, 2);
	mount.connect(mountGain).connect(volume);

	const now = context.currentTime;
	crank.start(now);
	noise.start(now);
	volume.gain.setTargetAtTime(tone.level, now, .018);

	return {

		update(next) {
			const at = context.currentTime;

			if(next.cycle !== tone.cycle) crank.playbackRate.setTargetAtTime(next.cycle/instrument.rate, at, .018);

			if(next.level !== tone.level) volume.gain.setTargetAtTime(next.level, at, .033);

			if(next.cutoff !== tone.cutoff) muffler.frequency.setTargetAtTime(next.cutoff, at, .035);

			if(next.flow !== tone.flow) flow.gain.setTargetAtTime(next.flow, at, .035);

			if(next.scatter !== tone.scatter) scatterGain.gain.setTargetAtTime(next.scatter, at, .045);

			if(next.mountGain !== tone.mountGain) mountGain.gain.setTargetAtTime(next.mountGain, at, .045);

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

	module.terrainCarSound = (cycle, level, cutoff, flow, scatter, skid, rate, mountGain) => {
		tone = {cycle, level, cutoff, flow, scatter, mountGain};
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
