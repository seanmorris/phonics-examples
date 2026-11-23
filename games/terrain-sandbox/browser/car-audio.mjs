const tireFile = 'tires_squal_loop.wav';

/** Blend neighboring recordings at their native RPM. Both throttle banks share the idle recording. */
export function carAudioMix(banks, rpm, load, skid)
{
	const mix = new Map();

	function voice(loop, weight)
	{
		if(weight < .001) return;

		const previous = mix.get(loop.file)?.gain ?? 0;
		const gain = weight * loop.gain * (.29 + .14*load);
		mix.set(loop.file, {gain: Math.hypot(previous, gain), rate: rpm/loop.rpm});
	}

	function blend(bank, volume)
	{
		const hi = bank.findIndex(loop => loop.rpm >= rpm);

		if(hi <= 0)
		{
			voice(hi === 0 ? bank[0] : bank.at(-1), volume);

			return;
		}

		const a = bank[hi-1], b = bank[hi];
		const angle = Math.log(rpm/a.rpm)/Math.log(b.rpm/a.rpm) * Math.PI/2;
		voice(a, volume*Math.cos(angle));
		voice(b, volume*Math.sin(angle));
	}

	blend(banks.on, Math.sin(load*Math.PI/2));
	blend(banks.off, Math.cos(load*Math.PI/2));

	if(skid > .01) mix.set(tireFile, {gain: .30*skid, rate: .92 + .14*skid});

	return mix;
}

/** The engine, gearbox and contact forces live in PHP. This bridge only plays their current sound. */
export function activate({module, canvas, archive, signal})
{
	const doc = canvas.ownerDocument, page = doc.defaultView;
	const Context = page.AudioContext ?? page.webkitAudioContext;
	const resources = archive.store.scope(signal);
	const directory = new URL('assets/car-audio/', archive.applicationUrl);
	const voices = new Map(), buffers = new Map();
	let context, banks, loading, closed = false, contextLost = false, pageHidden = false;
	let parkTimer, parked = false;
	let rpm = 0, load = 0, skid = 0;

	function retire(file)
	{
		const {source, gain} = voices.get(file);
		voices.delete(file);
		const now = context.currentTime;
		gain.gain.setTargetAtTime(0, now, .012);
		source.stop(now + .07);
	}

	function update()
	{
		const audible = rpm > 0 && !contextLost && !pageHidden && !doc.hidden && !closed;

		if(audible && Context)
		{
			page.clearTimeout(parkTimer);
			parkTimer = undefined;

			if(!context || parked && context.state === 'suspended') unlock();

			if(context && !loading)
			{
				// Read archive members between PHP frames, after the driving callback returns.
				loading = Promise.resolve().then(loadSamples).catch(error => {
					if(!closed) console.error('Car audio samples could not be loaded', error);
				});
			}
		}
		else if(!closed && context?.state === 'running' && parkTimer === undefined)
		{
			// Stop the audio device once the retiring voices have faded, even if animation frames have stopped.
			parkTimer = page.setTimeout(() => {
				parkTimer = undefined;
				parked = true;
				void context.suspend().catch(() => {});
			}, 100
			);
		}

		const mix = audible && banks && context?.state === 'running' ? carAudioMix(banks, rpm, load, skid) : new Map();

		for(const file of voices.keys()) if(!mix.has(file)) retire(file);

		for(const [file, sound] of mix)
		{
			let voice = voices.get(file);
			const now = context.currentTime;

			if(!voice)
			{
				const source = context.createBufferSource(), gain = context.createGain();
				source.buffer = buffers.get(file);
				source.loop = true;
				source.playbackRate.value = sound.rate;
				gain.gain.value = 0;
				source.connect(gain).connect(context.destination);

				source.onended = () => { source.disconnect(); gain.disconnect(); };

				source.start();
				voice = {source, gain};
				voices.set(file, voice);
			}

			voice.gain.gain.setTargetAtTime(sound.gain, now, .024);
			voice.source.playbackRate.setTargetAtTime(sound.rate, now, .024);
		}
	}

	async function loadSamples()
	{

		const read = async name => {
			const url = new URL(name, directory).href;

			try
			{
				return await resources.read(url);
			}
			finally
			{
				resources.release(url);
			}
		};

		const {loops} = JSON.parse(new TextDecoder().decode(await read('manifest.json')));
		const on = loops.filter(loop => loop.layer === 'on'), off = loops.filter(loop => loop.layer === 'off');

		await Promise.all([...loops.map(loop => loop.file),tireFile].map(async file => {
			const bytes = await read(file);

			const buffer = await context.decodeAudioData(
				bytes.buffer.slice(bytes.byteOffset, bytes.byteOffset+bytes.byteLength)
			);

			if(!closed) buffers.set(file, buffer);
		}));

		if(closed) return;

		banks = {on, off: [on[0],...off]};
		update();
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
		if(rpm || event.code === 'KeyC' || event.target?.closest?.('[data-action="car"],[data-action="delivery"]'))
		unlock();
	}

	for(const type of ['keydown','pointerdown','touchend']) doc.addEventListener(type, gesture, {capture: true,signal});
	doc.addEventListener('visibilitychange', update, {signal});
	page.addEventListener('pagehide', () => { pageHidden = true; update(); }, {signal});
	page.addEventListener('pageshow', () => { pageHidden = false; update(); }, {signal});
	canvas.addEventListener('webglcontextlost', () => { contextLost = true; update(); }, {signal});
	canvas.addEventListener('webglcontextrestored', () => { contextLost = false; update(); }, {signal});

	module.terrainCarSound = (engineRpm, engineLoad, tireSlip) => {
		rpm = engineRpm;
		load = engineLoad;
		skid = tireSlip;
		update();
	};

	return () => {
		closed = true;
		page.clearTimeout(parkTimer);
		delete module.terrainCarSound;

		for(const {source, gain} of voices.values())
		{
			source.stop();
			source.disconnect();
			gain.disconnect();
		}

		voices.clear();
		resources.close();
		buffers.clear();

		if(context) void context.close().catch(() => {});
	};
}
