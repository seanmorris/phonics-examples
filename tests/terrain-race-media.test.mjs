import test from 'node:test';
import assert from 'node:assert/strict';
import {RaceMedia, mediaPath, specId} from '../games/terrain-sandbox/browser/race-media.mjs';
import {ghostAt} from '../games/terrain-sandbox/browser/race-live.mjs';

const spec = {
	rules: 'terrain-car-circuit-v1'
	, simulator: 'a'.repeat(64)
	, laps: 1
	, world: {
		course: {
			gates: [
				{kind: 'road',role: 'start',laps: 1},{kind: 'road',role: 'checkpoint'}
			]
		}
	}
};

function room()
{
	const media = new Map(), events = new Map();

	const client = id => new RaceMedia({
		homeserver: 'https://room.invalid'
		, accessToken: id
		, roomId: '!r:room.invalid'
		, profile: () => ({id})
		, ensureAccessToken: async() => {}
		, signal: new AbortController().signal
		, request: async(method, path) => path.endsWith('/config')
			? {'m.upload.size': 32}
			: {chunk: [...events.values()]}


		, async fetch(url, options) {
			if(options.method==='POST')
			{
				const key = crypto.randomUUID();
				media.set(key, options.body);

				return Response.json({content_uri: 'mxc://room.invalid/' + key});
			}

			const bytes = media.get(url.pathname.split('/').at(-1));

			return new Response(bytes, {status: bytes ? 200 : 404});
		}


		, async send(type, content, txn) {
			const event = {event_id: '$' + txn,type,sender: id,content};
			events.set(txn, event);

			return event;
		}
	});

	return {client,media};
}

test('a full race and concurrent winning replies remain downloadable after the host leaves', async() => {
	const {client} = room(), first = {ticks: 123,inputs: 'stream'};
	const root = await client('@author').publish(spec, first, null, 'Test course');
	assert.ok(root.content.media.parts.length>1);
	const challenger = client('@challenger'),third = client('@other');
	const old = (await challenger.page()).items[0];
	assert.deepEqual(await challenger.get(old.content.media), {spec,run: first});
	const faster = {ticks: 121,inputs: 'quicker'};

	await Promise.all(
		[challenger.publish(spec, faster, old, 'Course'),third.publish(spec, {...faster,ticks: 120}, old, 'Course')]
	);

	const page = await third.page();
	assert.equal(page.items.length, 3);
	assert.deepEqual(page.items.slice(1).map(e => e.content.parent), [old.event_id,old.event_id]);
	assert.equal((await third.get(old.content.media)).run.ticks, 123);
});

test('verify length and hashes before accepting compressed media', async() => {
	const {client,media} = room(), store = client('a'), ref = await store.put({spec});
	await assert.rejects(store.get({...ref,bytes: ref.bytes-1}));
	await assert.rejects(store.get({...ref,hash: '0'.repeat(64)}));
	media.set(ref.parts[0][0].split('/').at(-1), new Uint8Array(32));
	await assert.rejects(store.get(ref), /digest/);
	assert.throws(() => mediaPath('https://unrelated.example/private'));
});

test('stable course identity, finite laps, and shortest-path ghost heading interpolation', async() => {
	assert.equal(
		await specId(spec)
		, await specId({world: spec.world,laps: 1,rules: spec.rules,simulator: spec.simulator})
	);

	for(const laps of [0,21,-1,1.5,'4']) assert.throws(() => specId({...spec,laps}));
	const sample = ghostAt([[0,0,0,0,3,0,0],[12,12,0,0,-3,0,0]], 6);
	assert.equal(sample[0], 6);
	assert.ok(Math.abs(sample[3]-Math.PI)<1e-8);
});

test('only matching replacement media can restore an unavailable ghost', async() => {
	const {client,media} = room(), store = client('archive'), run = {ticks: 120,inputs: 'test'};
	const event = await store.publish(spec, run, null, 'Test');
	media.clear();
	await assert.rejects(store.ghost(event));
	await assert.rejects(store.restore(event, spec, {...run,ticks: 119}), /exact saved copy/);
	await store.restore(event, spec, run);
	assert.deepEqual(await store.ghost(event), {spec,run});
	await assert.rejects(store.publish(spec, run, event, 'Tie'), /faster/);
	await assert.rejects(store.publish(spec, {...run,ticks: 121}, event, 'Slower'), /faster/);
});
