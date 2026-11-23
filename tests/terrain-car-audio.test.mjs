import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import test from 'node:test';
import {activate, joinSkid} from '../games/terrain-sandbox/browser/car-audio.mjs';

function buffer(channels, length, sampleRate)
{
	const data = Array.from({length: channels}, () => new Float32Array(length));

	return {numberOfChannels: channels, length, sampleRate, getChannelData: channel => data[channel]};
}

test('each collector receives its own six firings per cycle, flow gates both, and the loop wraps continuously', () => {
	const file = readFileSync(new URL('../games/terrain-sandbox/assets/car-audio/flat-twelve.wav', import.meta.url));
	const start = file.indexOf('data') + 8;
	const frames = file.readUInt32LE(start-4)/8;
	assert.equal(file.readUInt16LE(22), 4);
	assert.equal(frames, 64*512);

	const pressure = (frame, channel) => file.readInt16LE(start + ((frame+frames)%frames)*8 + 2*channel)/32768;

	const strengths = Array.from({length: 12}, () => []);

	for(let cylinder = 0; cylinder < 12; cylinder++)
	for(let cycle = 0; cycle < 64; cycle++)
	{
		// Firings sit mid-slot. Unequal primaries move each by up to two samples either side.
		const at = Math.round((cycle + (cylinder+.5)/12)*512) - 3;
		const bank = cylinder%2;

		const surge = channel => Math.max(...Array.from({length: 12}, (_, i) => pressure(at+i, channel)));
		const peak = surge(bank);
		assert.ok(peak > 4*surge(1-bank), `cylinder ${cylinder}, cycle ${cycle}`);
		assert.ok(surge(2) > .15);
		strengths[cylinder].push(peak);
	}

	const mean = values => values.reduce((sum, value) => sum+value, 0)/values.length;

	for(const cylinder of strengths)
	{
		// Combustion varies a few percent from cycle to cycle.
		const spread = (Math.max(...cylinder)-Math.min(...cylinder))/mean(cylinder);
		assert.ok(spread > .04 && spread < .25, String(spread));
	}

	/** Mean firing strength on one bank. */
	const bank = side => mean(strengths.filter((_, cylinder) => cylinder%2 === side).flat());

	// The banks balance, so their interleaved firings sum to the twelve-cylinder note, not one bank's.
	assert.ok(Math.abs(bank(0)/bank(1)-1) < .03);

	// Collector flow is a speed. It never reverses.
	for(let frame = 0; frame < frames; frame += 7) assert.ok(pressure(frame, 2) >= 0);

	for(let channel = 0; channel < 3; channel++) assert.ok(Math.abs(pressure(0, channel)-pressure(-1, channel)) < .05);
});

test('the real skid recording wraps across adjacent samples and retains its level through the overlap', () => {
	const wav = readFileSync(
		new URL('../games/terrain-sandbox/assets/car-audio/tires_squal_loop.wav', import.meta.url)
	);

	const rate = wav.readUInt32LE(24), data = wav.indexOf('data') + 8;
	assert.equal(wav.readUInt16LE(34), 24);
	const original = buffer(1, wav.readUInt32LE(data-4)/3, rate);
	const samples = original.getChannelData(0);

	for(let i = 0; i < samples.length; i++) samples[i] = wav.readIntLE(data+i*3, 3)/8388608;

	const joined = joinSkid({createBuffer: buffer}, original).getChannelData(0);
	const overlap = Math.round(.08*rate);
	assert.equal(joined.length, samples.length-overlap);
	assert.equal(joined.at(-1), samples[joined.length-1]);
	assert.equal(joined[0], samples[joined.length]);
	assert.equal(joined[overlap-1], samples[overlap-1]);
	assert.deepEqual(joined.subarray(overlap), samples.subarray(overlap, joined.length));

	const rms = values => Math.sqrt(values.reduce((sum, value) => sum+value*value, 0)/values.length);
	const neighboringLevel = Math.hypot(rms(joined.subarray(overlap, 2*overlap)), rms(joined.subarray(-overlap)))
		/Math.SQRT2;

	const relativeLevel = rms(joined.subarray(0, overlap))/neighboringLevel;
	assert.ok(relativeLevel > .85 && relativeLevel < 1.15, String(relativeLevel));
});

test('a browser awaiting an audio gesture receives one resume request across driving frames', () => {
	let attempts = 0;

	/** A gain node that accepts levels and connections without producing sound. */
	const silentGain = () => ({gain: {value: 1, setTargetAtTime: () => {}}, connect: () => {}});

	class LockedContext extends EventTarget
	{
		state = 'suspended';

		destination = {};

		createGain() { return silentGain(); }

		async resume() { attempts++; }

		async close() {}
	}

	const page = Object.assign(new EventTarget(), {AudioContext: LockedContext, clearTimeout});
	const doc = Object.assign(new EventTarget(), {defaultView: page});
	const canvas = Object.assign(new EventTarget(), {ownerDocument: doc});
	const controller = new AbortController(), module = {};
	const archive = {
		store: {
			scope: () => ({

				close() {}
			})
		}
	};

	const stop = activate({module, canvas, archive, signal: controller.signal});

	for(let i = 0; i<90; i++) module.terrainCarSound(8+i, .1, 700, .01, 80, 0, 1);
	assert.equal(attempts, 1);
	doc.dispatchEvent(new Event('keydown'));
	assert.equal(attempts, 2);
	stop();
	controller.abort();
});

test('the saved master reaches the soundtrack at startup and when the listener adjusts it', () => {
	const saved = new Map([['terrain-sandbox.audio', '{"master":65,"engine":75}']]);

	const page = Object.assign(new EventTarget(), {
		clearTimeout
		, localStorage: {
			getItem: key => saved.get(key)
			, setItem: (key, value) => saved.set(key, value)
		}
	});

	const canvas = Object.assign(
		new EventTarget()
		, {ownerDocument: Object.assign(new EventTarget(), {defaultView: page})}
	);

	const writes = [];
	const module = {terrainMusic: {set: (name, value) => { writes.push([name, value]); return true; }}};
	const controller = new AbortController();
	const archive = {
		store: {
			scope: () => ({

				close() {}
			})
		}
	};

	const stop = activate({module, canvas, archive, signal: controller.signal});
	assert.deepEqual(writes, [['master', 65]]);
	assert.equal(module.terrainAudioGet('engine'), 75);

	assert.equal(module.terrainAudioSet('master', 40), true);
	assert.equal(module.terrainAudioSet('master', -1), false);
	assert.equal(module.terrainAudioSet('engine', 55), true);
	assert.deepEqual(writes, [['master', 65], ['master', 40]]);
	assert.deepEqual(JSON.parse(saved.get('terrain-sandbox.audio')), {master: 40, engine: 55, tires: 100});

	stop();
	controller.abort();
});
