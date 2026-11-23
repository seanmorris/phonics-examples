import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import test from 'node:test';
import {activate, joinSkid} from '../games/terrain-sandbox/browser/car-audio.mjs';

function buffer(channels, length, sampleRate)
{
	const data = Array.from({length: channels}, () => new Float32Array(length));

	return {numberOfChannels: channels, length, sampleRate, getChannelData: channel => data[channel]};
}

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

	class LockedContext extends EventTarget
	{
		state = 'suspended';

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

	for(let i = 0; i<90; i++) module.terrainCarSound(8+i, .1, 700, .01, 80, 0, 1, .15);
	assert.equal(attempts, 1);
	doc.dispatchEvent(new Event('keydown'));
	assert.equal(attempts, 2);
	stop();
	controller.abort();
});
