const LIMIT = 12 * 1024 * 1024;
const CHUNK = 1024 * 1024;
const EVENT = 'org.seanmorris.phonics.terrain.ghost.v1';
const RESTORED = EVENT + '.media';
const RULES = 'terrain-car-circuit-v1';
const enc = new TextEncoder();

export const digest = async bytes => [...new Uint8Array(await crypto.subtle.digest('SHA-256', bytes))]
	.map(byte => byte.toString(16).padStart(2, '0')).join('');

function ordered(value)
{
	if(Array.isArray(value)) return value.map(ordered);

	if(value && typeof value === 'object')
		return Object.fromEntries(Object.keys(value).sort().map(key => [key, ordered(value[key])]));

	return value;
}

export function checkSpec(spec)
{
	const gates = spec?.world?.course?.gates;

	if(spec?.rules !== RULES || !/^[0-9a-f]{64}$/.test(spec.simulator) || !Number.isInteger(spec.laps) || spec.laps < 1
		|| spec.laps > 20
		|| !Array.isArray(gates) || gates.length < 2 || gates[0]?.role !== 'start' || gates[0].laps !== spec.laps
		|| (spec.laps !== 1 && gates.some(gate => gate.role === 'finish'))
		|| gates.some(gate => gate.kind !== 'road') || spec.world.journeyNetwork
		|| (spec.world.circuit && spec.world.circuit.variant !== 'dry')
	)
		throw new Error('This build requires a dry circuit with 1 to 20 laps.');

	return spec;
}

export const specId = spec => digest(enc.encode(JSON.stringify(ordered(checkSpec(spec)))));

async function read(stream, limit)
{
	const reader = stream.getReader(), pieces = [];
	let length = 0;

	try
	{
		for(; ;)
		{
			const {done, value} = await reader.read();

			if(done) break;

			length += value.byteLength;

			if(length > limit) throw new Error('Race data exceeds the download limit.');

			pieces.push(value);
		}
	}
	finally
	{
		await reader.cancel().catch(() => {});
	}

	const result = new Uint8Array(length);
	let offset = 0;

	for(const piece of pieces)
	{
		result.set(piece, offset);
		offset += piece.length;
	}

	return result;
}

export function mediaPath(uri)
{
	const match = /^mxc:\/\/([a-z0-9.:[\]-]+)\/([a-zA-Z0-9_-]+)$/i.exec(uri);

	if(!match) throw new Error('Invalid Matrix media reference.');

	return '/_matrix/client/v1/media/download/' + match.slice(1).map(encodeURIComponent).join('/');
}

export class RaceMedia
{

	constructor(provider) { this.provider = provider; }

	async transfer(path, bytes)
	{
		const p = this.provider;
		await p.ensureAccessToken();
		const headers = {Authorization: 'Bearer ' + p.accessToken};

		if(bytes) headers['Content-Type'] = 'application/octet-stream';

		for(let attempt = 0; ; attempt++)
		{
			const res = await p.fetch(new URL(path, p.homeserver), {
				method: bytes ? 'POST' : 'GET'
				, headers
				, body: bytes
				, redirect: 'error'
				, signal: p.signal
			});

			if(res.ok) return res;

			if(res.status === 429 && attempt < 3)
			{
				const err = await res.json().catch(() => ({}));
				const delay = Number(err.retry_after_ms ?? (res.headers.get('Retry-After') || 1)*1000);

				if(delay <= 30000)
				{
					await new Promise(resolve => setTimeout(resolve, Math.max(250, delay)));
					continue;
				}
			}

			const error = new Error(
				res.status === 404
					? 'Race media is unavailable on this homeserver.'
					: 'Matrix media transfer failed (HTTP ' + res.status + ').'
			);

			error.status = res.status;
			throw error;
		}
	}

	async put(value)
	{
		const raw = enc.encode(JSON.stringify(value));

		if(raw.length > LIMIT) throw new Error('The recording is too large to post.');

		const zipped = await read(new Blob([raw]).stream().pipeThrough(new CompressionStream('gzip')), LIMIT);
		const parts = [];
		let size = CHUNK;
		const config = await this.provider.request('GET', '/_matrix/client/v1/media/config').catch(() => null);

		if(config?.['m.upload.size'] > 0) size = Math.min(size, config['m.upload.size']);

		for(let offset = 0; offset < zipped.length;)
		{
			if(parts.length >= 96) throw new Error('The homeserver media limit is too small for this race.');

			const bytes = zipped.subarray(offset, offset+size);

			try
			{
				const response = await this.transfer('/_matrix/media/v3/upload', bytes);
				const mxc = (await response.json()).content_uri;
				mediaPath(mxc);
				parts.push([mxc,bytes.length,await digest(bytes)]);
				offset += bytes.length;
			}
			catch(error)
			{
				if(error.status !== 413 || size <= 4096) throw error;

				size = Math.floor(size/2);
			}
		}

		return {version: 1,bytes: raw.length,hash: await digest(raw),parts};
	}

	async get(ref)
	{
		if(ref?.version !== 1 || !Number.isSafeInteger(ref.bytes) || ref.bytes <= 0 || ref.bytes > LIMIT
			|| !Array.isArray(ref.parts) || !ref.parts.length || ref.parts.length > 96
				|| !/^[0-9a-f]{64}$/.test(ref.hash)
		)
			throw new Error('Invalid race manifest.');

		const chunks = [];
		let total = 0;

		for(const [mxc,length,hash] of ref.parts)
		{
			if(!Number.isSafeInteger(length) || length <= 0 || length > CHUNK || (total += length) > LIMIT)
				throw new Error('Invalid race part length.');

			const bytes = await read((await this.transfer(mediaPath(mxc))).body, length);

			if(bytes.length !== length || await digest(bytes) !== hash)
			throw new Error('Race part did not match its digest.');

			chunks.push(bytes);
		}

		const raw = await read(new Blob(chunks).stream().pipeThrough(new DecompressionStream('gzip')), ref.bytes);

		if(raw.length !== ref.bytes || await digest(raw) !== ref.hash)
		throw new Error('Race did not match its digest.');

		return JSON.parse(new TextDecoder().decode(raw));
	}

	async ghost(event)
	{
		const original = event.content.media;

		try
		{
			return await this.get(original);
		}
		catch(error)
		{
			if(error.status!==404) throw error;

			const path = '/_matrix/client/v1/rooms/' + encodeURIComponent(this.provider.roomId) + '/relations/'
				+ encodeURIComponent(event.event_id) + '/m.reference/' + RESTORED + '?dir=b&limit=32';

			const page = await this.provider.request('GET', path);

			for(const repair of page.chunk??[])
			{
				const ref = repair.content?.media;

				if(repair.type!==RESTORED || ref?.hash!==original.hash || ref.bytes!==original.bytes) continue;

				try {return await this.get(ref);} catch { /* An older redundant copy may still exist. */ }
			}

			throw error;
		}
	}

	async restore(event, spec, run)
	{
		const bytes = enc.encode(JSON.stringify({spec,run})), original = event.content.media;

		if(bytes.length!==original.bytes || await digest(bytes)!==original.hash)
			throw new Error('Choose an exact saved copy of this race.');

		const media = await this.put({spec,run});

		return this.provider.send(RESTORED, {media,'m.relates_to': {rel_type: 'm.reference',event_id: event.event_id}});
	}

	async page(from = null)
	{
		const query = new URLSearchParams({dir: 'b',limit: '35',filter: JSON.stringify({types: [EVENT]})});

		if(from) query.set('from', from);

		const p = this.provider;

		const page = await p.request(
			'GET'
			, '/_matrix/client/v3/rooms/' + encodeURIComponent(p.roomId) + '/messages?' + query
		);

		return {
			items: (page.chunk ?? []).filter(
				e => e.type === EVENT && e.content?.version === 1 && e.content?.media && !e.unsigned?.redacted_because
			)
			, next: page.end === from ? null : page.end
		};
	}

	async publish(spec, run, parent, summary)
	{
		const id = await specId(spec);

		if(parent && (parent.content.course !== id || run.ticks >= parent.content.ticks))
			throw new Error('Only a faster finish on the same circuit can replace this ghost.');

		const bundle = await this.put({spec,run});
		const record = {version: 1,rules: RULES,course: id,media: bundle,ticks: run.ticks,laps: spec.laps,summary};

		if(parent)
		{
			record.parent = parent.event_id;
			record.root = parent.content.root ?? parent.event_id;
			record['m.relates_to'] = {rel_type: 'm.reference',event_id: parent.event_id};
		}

		const key = await digest(
			enc.encode([this.provider.roomId,this.provider.profile().id,id,bundle.hash,record.parent].join('/'))
		);

		return this.provider.send(EVENT, record, 'race-' + key);
	}
}

export function activate({session})
{
	session.terrainRaceHash = digest;
	session.terrainRaceMedia = {RaceMedia, checkSpec, specId, event: EVENT};

	return () => { delete session.terrainRaceMedia; delete session.terrainRaceHash; };
}
