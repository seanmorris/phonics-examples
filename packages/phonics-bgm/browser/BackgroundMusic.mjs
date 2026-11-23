/** A playlist at the bottom of a last-in, first-out stack. Media clocks retain the position of each paused cue. */
export class BackgroundMusic
{

	constructor({voice, storage, announce = () => {}, clock = globalThis, fade = .65})
	{
		Object.assign(this, {voice,storage,announce,clock,fade});
		this.tracks = [];
		this.stack = [];
		this.live = new Set();
		this.failed = new Set();
		this.holds = new Set(['gesture']);
		this.revision = 0;
	}

	playlist(tracks, key)
	{
		const ids = new Set();

		for(const track of tracks)
		{
			if(!track.id || !track.src || ids.has(track.id))
			throw new TypeError('Music tracks need a unique id and a source');

			ids.add(track.id);
		}

		this.tracks = [...tracks];
		this.key = key;
		const saved = this.storage?.get(key);
		this.index = Math.max(0, tracks.findIndex(track => track.id === saved));
		this.failed.clear();
		this.base = tracks.length ? this.entry(tracks[this.index]) : null;
		this.stack = this.base ? [this.base] : [];
		this.sync();
	}

	entry(track, loop = false) { return {track,loop,started: false}; }

	get current() { return this.stack.at(-1)?.track ?? null; }

	get depth() { return this.stack.length; }

	push(track, loop = false)
	{
		if(this.closed || !track?.src) return;

		this.stack.push(this.entry(track, loop));
		this.sync();
	}

	pop(seconds = this.fade)
	{
		if(!this.stack.length || this.stack.at(-1) === this.base) return;

		this.stack.pop();
		this.sync(seconds);
	}

	next()
	{
		if(!this.tracks.length) return;

		this.failed.clear();
		this.advance();
	}

	advance()
	{
		if(this.base) this.stack.shift();

		this.base = null;

		if(this.failed.size < this.tracks.length)
		{
			do
			{
				this.index = (this.index+1)%this.tracks.length;
			}
			while(this.failed.has(this.tracks[this.index].id));

			this.base = this.entry(this.tracks[this.index]);
			this.stack.unshift(this.base);
		}

		this.sync();
	}

	hold(reason, held)
	{
		// An unchanged input can retry a play() that the browser previously denied.
		if(this.holds.has(reason) === held && (held || this.wanted || this.holds.size || !this.stack.length)) return;

		if(held) this.holds.add(reason);
		else this.holds.delete(reason);

		this.sync();
	}

	clearTimer(entry, name)
	{
		this.clock.clearTimeout(entry[name]);
		entry[name] = undefined;
	}

	park(entry)
	{
		this.clearTimer(entry, 'retire');
		this.clearTimer(entry, 'tail');
		entry.voice.gain(0, 0);
		entry.voice.media.pause();

		if(!this.stack.includes(entry))
		{
			entry.voice.close();
			this.live.delete(entry);
			entry.voice = null;
		}
	}

	open(entry)
	{
		if(entry.voice) return;

		entry.voice = this.voice(entry.track, entry.loop);
		this.live.add(entry);
		const audio = entry.voice.media;

		audio.onended = () => {
			const position = this.stack.indexOf(entry);

			if(position === -1) return;

			if(entry === this.base) this.advance();
			else
			{
				this.stack.splice(position, 1);
				this.sync();
			}
		};

		audio.onerror = () => this.failure(entry);
		audio.onplaying = audio.onseeked = audio.ondurationchange = () => this.tail(entry);

		audio.onwaiting = () => this.clearTimer(entry, 'tail');
	}

	failure(entry)
	{
		if(this.stack.at(-1) !== entry || this.closed) return;

		if(entry === this.base)
		{
			this.failed.add(entry.track.id);
			this.advance();
		}
		else this.pop();
	}

	// Start bringing the interrupted cue back during the last part of the jingle. Recheck the media clock
	// when the timer fires: a stalled or seeking stream can be behind wall time.

	tail(entry)
	{
		this.clearTimer(entry, 'tail');

		if(this.focus !== entry || this.wanted !== entry || entry.loop || this.stack.length < 2) return;

		const media = entry.voice.media;

		if(media.paused || !Number.isFinite(media.duration) || media.duration <= 0) return;

		const left = Math.max(0, media.duration-media.currentTime);
		const fade = Math.min(this.fade, media.duration/3);

		if(left <= fade+.012) this.pop(Math.min(left, fade));
		else entry.tail = this.clock.setTimeout(() => this.tail(entry), 1000*(left-fade));
	}

	sync(seconds = this.fade)
	{
		if(this.closed) return;

		const next = this.holds.size ? null : this.stack.at(-1);

		if(!next)
		{
			this.revision++;
			this.wanted = this.focus = null;

			for(const entry of this.live) this.park(entry);

			return;
		}

		// A removed, paused cue can be freed even when the same interlude is still on top.
		for(const entry of this.live)
			if(entry !== this.focus && entry !== next && entry.retire === undefined && !this.stack.includes(entry))
			this.park(entry);

		if(this.wanted === next) return;

		this.wanted = next;
		const revision = ++this.revision;
		let playing;

		try
		{
			this.open(next);
			this.clearTimer(next, 'retire');
			playing = next.voice.media.play();
		}
		catch(error)
		{
			this.rejected(next, revision, error);

			return;
		}

		Promise.resolve(playing).then(() => {
			if(revision !== this.revision || this.closed)
			{
				if(next.voice && next !== this.wanted && next !== this.focus && next.retire === undefined)
				this.park(next);

				return;
			}

			const duration = next.voice.media.duration;
			const fade = Number.isFinite(duration) && duration > 0 ? Math.min(seconds, duration/3) : seconds;
			this.focus = next;

			for(const entry of this.live)
			{
				if(entry === next) continue;

				this.clearTimer(entry, 'tail');

				if(entry.retire !== undefined) continue;

				if(entry.voice.media.paused || !fade) this.park(entry);
				else
				{
					entry.voice.gain(0, fade);
					entry.retire = this.clock.setTimeout(() => this.park(entry), fade*1000);
				}
			}

			next.voice.gain(1, fade);

			if(!next.started)
			{
				next.started = true;

				if(next === this.base)
				{
					this.failed.clear();
					this.storage?.set(this.key, this.tracks[(this.index+1)%this.tracks.length].id);
				}

				if(next.track.credit !== false) this.announce(next.track);
			}

			this.tail(next);
		}, error => this.rejected(next, revision, error));
	}

	rejected(entry, revision, error)
	{
		if(revision !== this.revision || this.closed) return;

		if(error?.name === 'NotAllowedError')
		{
			this.wanted = null; // Retry inside the next trusted input.

			return;
		}

		this.failure(entry);
	}

	close()
	{
		this.closed = true;
		this.revision++;
		this.stack = [];
		this.base = null;
		this.wanted = this.focus = null;

		for(const entry of this.live) this.park(entry);
	}
}

/** One streaming element per cue and a hardware-timed gain ramp. Interrupting a ramp starts at its audible level. */
function streamingVoice(context, destination, page, url, loop)
{
	const media = new page.Audio();
	const gain = context.createGain(), source = context.createMediaElementSource(media);
	gain.gain.value = 0;
	source.connect(gain).connect(destination);
	media.crossOrigin = 'anonymous';
	media.loop = loop;
	media.src = url;
	let from = 0, to = 0, start = 0, end = 0;

	return {
		media


		, gain(value, seconds) {
			const now = context.currentTime;
			from = now >= end ? to : from+(to-from)*(now-start)/(end-start);
			start = now;
			end = now+seconds;
			to = value;
			gain.gain.cancelScheduledValues(now);
			gain.gain.setValueAtTime(seconds ? from : value, now);

			if(seconds) gain.gain.linearRampToValueAtTime(value, end);
		}


		, close() {
			media.onended = media.onerror = media.onplaying = media.onwaiting = media.onseeked = media
				.ondurationchange = null;

			media.removeAttribute('src');
			media.load();
			source.disconnect();
			gain.disconnect();
		}
	};
}

export function activate({module,session,canvas,archive,services,signal,options})
{
	const doc = canvas.ownerDocument, page = doc.defaultView;
	const Context = page.AudioContext ?? page.webkitAudioContext;
	const storage = new services.SafeStorage();
	const volumeKey = archive.id + '.bgm.volume';
	const stored = storage.get(volumeKey);
	let volume = stored !== null && Number.isFinite(Number(stored)) ? Math.max(0, Math.min(1, Number(stored))) : .38;
	let context, master, closed = false;

	const resolve = url => new URL(url, archive.url).href;

	const music = new BackgroundMusic({
		storage
		, clock: page
		, voice: (track, loop) => streamingVoice(context, master, page, resolve(track.src), loop)

		, announce(track) {
			const detail = {...track,cover: track.cover ? resolve(track.cover) : null};
			canvas.dispatchEvent(new page.CustomEvent('phonics-music-track', {detail}));
		}
	});

	function unlock()
	{
		if(closed || !Context || doc.hidden) return;

		if(!context)
		{
			context = new Context();
			master = context.createGain();
			master.gain.value = volume;
			master.connect(context.destination);
		}

		if(context.state !== 'running') void context.resume().catch(() => {});

		music.hold('gesture', false);
	}

	function visibility(hidden)
	{
		music.hold('hidden', hidden);

		if(hidden && context?.state === 'running') void context.suspend().catch(() => {});
		else if(!hidden && context) unlock();
	}

	music.hold('hidden', doc.hidden);
	music.hold('muted', Boolean(options?.muted) || volume === 0);

	for(const type of ['pointerdown','keydown','touchend'])
		doc.addEventListener(type, unlock, {capture: true,signal});
	doc.addEventListener('visibilitychange', () => visibility(doc.hidden), {signal});
	page.addEventListener('pagehide', () => visibility(true), {signal});
	page.addEventListener('pageshow', () => visibility(doc.hidden), {signal});
	canvas.addEventListener('webglcontextlost', () => music.hold('context', true), {signal});
	canvas.addEventListener('webglcontextrestored', () => music.hold('context', false), {signal});
	session.backgroundMusic = {
		get track() { return music.current; }
		, get depth() { return music.depth; }
		, get volume() { return volume; }

		, setVolume(value) {
			if(!Number.isFinite(value)) return;

			volume = Math.max(0, Math.min(1, value));
			storage.set(volumeKey, String(volume));

			if(master) master.gain.setTargetAtTime(volume, context.currentTime, .025);

			music.hold('muted', Boolean(options?.muted) || !volume);
		}
		, next: () => music.next()
		, push: (track, loop) => music.push(track, loop)
		, pop: () => music.pop()
		, pause: paused => music.hold('game', paused)
	};

	module.phonicsBgmPlaylist = (json, key) => {
		music.playlist(JSON.parse(json), key);

		if(page.navigator.userActivation?.hasBeenActive) unlock();
	};

	module.phonicsBgmPush = (json, loop) => music.push(JSON.parse(json), Boolean(loop));

	module.phonicsBgmPop = () => music.pop();

	return () => {
		closed = true;
		music.close();
		delete session.backgroundMusic;
		delete module.phonicsBgmPlaylist;
		delete module.phonicsBgmPush;
		delete module.phonicsBgmPop;
		master?.disconnect();

		if(context && context.state !== 'closed') return context.close().catch(() => {});
	};
}
