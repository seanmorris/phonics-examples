const encoder = new TextEncoder(), decoder = new TextDecoder();

const now = () => performance.now();

export function interpolate(a, b, t)
{
	return a.map((v, i) => {
		let d = b[i]-v;

		if(i >= 3) d = Math.atan2(Math.sin(d), Math.cos(d));

		return v + d*t;
	});
}

export function ghostAt(poses, tick)
{
	if(!poses.length) return null;

	let lo = 0, hi = poses.length-1;

	while(lo+1 < hi)
	{
		const mid = (lo+hi)>>>1;

		if(poses[mid][0] <= tick) lo = mid; else hi = mid;
	}

	const a = poses[lo], b = poses[hi];

	return interpolate(a.slice(1), b.slice(1), Math.max(0, Math.min(1, (tick-a[0])/(b[0]-a[0] || 1))));
}

export class LiveCircuit
{

	constructor(lobby, table, callbacks)
	{
		this.lobby = lobby;
		this.provider = lobby.protocol.provider;
		this.table = table;
		this.callbacks = callbacks;
		this.me = this.provider.profile().id;
		this.driver = table.role === 'player';
		this.host = table.players[0];
		this.peer = table.players.find(id => id !== this.me);
		this.links = new Map();
		this.pending = new Map();
		this.inputs = new Map();
		this.poses = new Map();
		this.pings = new Map();
		this.clockSamples = [];
		this.controller = new AbortController();

		this.onSignal = event => { void this.signal(event.detail).catch(e => this.fail(e)); };

		this.provider.addEventListener('signal', this.onSignal, {signal: this.controller.signal});
	}

	fail(e) { if(!this.closed) this.callbacks.error(e.message); }

	async start()
	{
		this.peers = this.driver ? [this.peer] : this.table.players.filter(id => id !== this.me);
		// The receiving tab may still be preparing the terrain when the first announcement arrives.

		const hello = () => {
			const message = {race: this.table.root,hello: true,watch: !this.driver};

			for(const id of this.peers)
			if(!this.links.get(id)?.open) void this.provider.signalPeer(id, message).catch(e => this.fail(e));
		};

		hello();
		this.helloTimer = setInterval(hello, 5000);

		this.setupTimer = setTimeout(() => {
			if(this.driver && !this.links.get(this.peer)?.open)
			this.fail(new Error('Could not connect both drivers. Leave the table to retry.'));
		}, 120000
		);
	}

	async signal({from,payload})
	{
		if(this.closed || payload?.race !== this.table.root || from === this.me) return;

		const seated = this.table.players.includes(from);

		if(!seated && (!this.driver || (!this.links.has(from) && (payload.hello !== true || payload.watch !== true))))
		return;

		if(!this.driver && !seated) return;

		if(!this.links.has(from) && this.links.size >= 10) return;

		let promise = this.pending.get(from);

		if(!promise)
		{
			promise = this.connect(from, seated);
			this.pending.set(from, promise);
		}

		const link = await promise;

		if(this.closed) return;

		if(payload.signal) await link.transport.receiveSignal(payload.signal);
	}

	async connect(id, seated)
	{
		const initiator = this.driver && (!seated || this.me === this.host);

		const transport = await this.lobby.createTransport({
			polite: !initiator
			, sendSignal: signal => this.provider.signalPeer(id, {race: this.table.root,signal})
		});

		if(this.closed)
		{
			transport.close();

			return {};
		}

		const link = {transport,open: false};
		this.links.set(id, link);

		const on = (name, callback) => transport.addEventListener(name, callback, {signal: this.controller.signal});

		on('open', () => {
			link.open = true;

			if(this.last) this.send(id, 'realtime', {type: 'pose',...this.last});

			if(this.driver && seated)
			{
				if(this.me === this.host) this.callbacks.notice('Both drivers connected. Synchronizing the start…');
				else this.ping(id);
			}
		});

		on('recovery', e => {
			if(!seated || !this.driver) return;

			this.callbacks.notice(
				e.detail.state === 'reconnecting' ? 'Reconnecting to the other driver…' : 'Restoring race data…'
			);

			if(e.detail.state === 'resynchronizing')
			{
				link.repair = crypto.randomUUID();

				this.send(
					id
					, 'control'
					, {type: 'resync',token: link.repair,from: (this.inputs.get(id) ?? '').length/4}
				);
			}
		});

		on('message', e => {
			try
			{
				this.message(id, e.detail.channel, JSON.parse(decoder.decode(e.detail.data)));
			}
			catch(error)
			{
				this.fail(error);
			}
		});

		on('error', e => this.fail(e.detail));

		on('close', () => {
			link.open = false;

			if(!this.closed && this.driver && seated) this.callbacks.disconnect();
		});

		// Signaling must remain available while start() waits for the data channels to open.
		void transport.start({initiator}).catch(error => this.fail(error));

		return link;
	}

	send(id, channel, message)
	{
		const link = this.links.get(id);

		if(!link?.open) return;

		link.transport.send(channel, encoder.encode(JSON.stringify(message)));
	}

	ping(id)
	{
		const seq = this.clockSamples.length;
		this.pings.set(seq, now());
		this.send(id, 'control', {type: 'ping',seq});
	}

	message(id, channel, m)
	{
		const driver = this.table.players.includes(id);

		if(!driver || !m || typeof m !== 'object') return;

		if(channel === 'realtime')
		{
			if(m.type !== 'pose' || !Number.isSafeInteger(m.tick) || m.tick < 0 || !Array.isArray(m.pose)
				|| m.pose.length !== 6 || !m.pose.every(v => Number.isFinite(v) && Math.abs(v)<1000000)
			) return;

			const previous = this.poses.get(id);

			if(previous && m.tick <= previous.b.tick) return;

			this.poses.set(id, {a: previous?.b ?? m,b: m,at: now()});

			return;
		}

		if(m.type === 'inputs')
		{
			if(!Number.isSafeInteger(m.from) || m.from < 0 || typeof m.data !== 'string' || m.data.length>65536
				|| m.data.length%4 || !/^[A-Za-z0-9+/]*$/.test(m.data)
			) return;

			const have = this.inputs.get(id) ?? '';

			const overlap = Math.min(have.length-m.from*4, m.data.length);

			if(overlap > 0 && have.slice(m.from*4, m.from*4+overlap) !== m.data.slice(0, overlap))
				throw new Error('The peer changed previously received inputs.');

			if(m.from*4 <= have.length && m.from*4+m.data.length <= 864000*4)
				this.inputs.set(id, have+m.data.slice(Math.max(0, overlap)));
			else if(have.length/4 < m.from) this.send(id, 'control', {type: 'resync',from: have.length/4});

			return;
		}

		if(!this.driver || id !== this.peer) return;

		if(m.type === 'resync' && Number.isSafeInteger(m.from) && m.from >= 0)
		{
			const data = this.localInputs ?? '';

			for(let pos = m.from*4; pos<data.length; pos += 16384)
				this.send(id, 'control', {type: 'inputs',from: pos/4,data: data.slice(pos, pos+16384)});

			if(typeof m.token === 'string' && m.token.length <= 64)
				this.send(id, 'control', {type: 'repaired',token: m.token,ticks: data.length/4});
		}

		const link = this.links.get(id);

		if(m.type === 'repaired' && link?.repair && m.token === link.repair && Number.isSafeInteger(m.ticks)
			&& m.ticks <= (this.inputs.get(id) ?? '').length/4
		) {
			link.repair = null;
			link.transport.finishRecovery();
			this.callbacks.notice('Both drivers reconnected.');
		}

		if(this.me === this.host && m.type === 'ping') this.send(id, 'control', {type: 'pong',seq: m.seq,host: now()});

		if(this.me !== this.host && m.type === 'pong' && this.pings.has(m.seq) && Number.isFinite(m.host))
		{
			const sent = this.pings.get(m.seq), end = now();
			this.pings.delete(m.seq);
			this.clockSamples.push({rtt: end-sent,offset: m.host-(sent+end)/2});

			if(this.clockSamples.length < 5) this.ping(id);
			else
			{
				this.offset = this.clockSamples.sort((a, b) => a.rtt-b.rtt)[0].offset;
				this.send(id, 'control', {type: 'ready'});
			}
		}

		if(this.me === this.host && m.type === 'ready' && !this.launch)
		{
			this.launch = now()+3500;
			this.send(id, 'control', {type: 'start',at: this.launch});
			this.schedule(this.launch);
		}

		if(this.me !== this.host && m.type === 'start' && !this.launch && Number.isFinite(this.offset))
		{
			const at = m.at-this.offset;

			if(!Number.isFinite(at) || at-now() < -50 || at-now() > 10000)
			throw new Error('The shared start expired. Rematch to retry.');

			this.launch = at;
			this.schedule(at);
		}
	}

	schedule(at)
	{
		this.callbacks.countdown(at);
		this.timer = setTimeout(() => { if(!this.closed) this.callbacks.go(); }, Math.max(0, at-now()));
	}

	frame(frame)
	{
		if(!this.driver) return;

		this.last = {tick: frame.tick,pose: frame.pose};

		if(frame.data) this.localInputs = (this.localInputs ?? '') + frame.data;

		for(const [id, link] of this.links)
		if(link.open)
		{
			this.send(id, 'realtime', {type: 'pose',...this.last});

			if(frame.data && id === this.peer)
			this.send(id, 'control', {type: 'inputs',from: frame.from,data: frame.data});
		}
	}

	view(id = this.peer)
	{
		const sample = this.poses.get(id);

		if(!sample) return null;

		return interpolate(sample.a.pose, sample.b.pose, Math.min(1, (now()-sample.at)/100));
	}

	close()
	{
		this.closed = true;
		clearInterval(this.helloTimer);
		clearTimeout(this.timer);
		clearTimeout(this.setupTimer);
		this.controller.abort();

		for(const link of this.links.values()) link.transport.close();
		this.links.clear();
	}
}

export function activate({session})
{
	session.terrainRaceNet = {LiveCircuit,ghostAt};

	return () => { delete session.terrainRaceNet; };
}
