import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import test from 'node:test';
import {carAudioMix} from '../games/terrain-sandbox/browser/car-audio.mjs';

const assets = new URL('../games/terrain-sandbox/assets/car-audio/', import.meta.url);
const {loops} = JSON.parse(readFileSync(new URL('manifest.json', assets)));
const on = loops.filter(loop => loop.layer === 'on');
const banks = {on, off: [on[0], ...loops.filter(loop => loop.layer === 'off')]};

test('engine pitch follows crank RPM, including between overrun recordings', () => {
	const mix = carAudioMix(banks, 7000, 0, 0);
	assert.deepEqual([...mix.keys()], ['off_6000.wav','off_8000.wav']);
	assert.equal(mix.get('off_6000.wav').rate, 7000/6000);
	assert.equal(mix.get('off_8000.wav').rate, 7000/8000);
	assert.deepEqual([...carAudioMix(banks, 7000, 1, 0).keys()], ['on_7000.wav']);
});

test('the shared idle and the adjacent RPM and load crossfades keep equal power', () => {
	for(const rpm of [950,1500,1999.99,2000,2000.01,3500,7250,8000])
	for(const load of [0,.1,.5,.9,1])
	{
		const voices = carAudioMix(banks, rpm, load, 0);
		assert.ok(voices.size >= 1 && voices.size <= 4);
		const power = [...voices.values()].reduce((sum, voice) => sum+voice.gain**2, 0);
		assert.ok(Math.abs(power-(.29+.14*load)**2) < 1e-6);
	}

	assert.equal(carAudioMix(banks, 950, .5, 0).size, 1);
	const skid = carAudioMix(banks, 3500, .5, .8);
	assert.equal(skid.size, 5);
	assert.ok(skid.get('tires_squal_loop.wav').gain > 0);
});
