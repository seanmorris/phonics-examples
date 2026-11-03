import test from 'node:test';
import assert from 'node:assert/strict';
import {impactLowpassCutoff} from '../games/php-billiards/browser/game.mjs';

test('soft impact cutoff rises monotonically on the contact curve for both materials', () => {
	for(const material of ['ball','rail'])
	{
		let previous=0;
		for(let step=0;step<650;step++)
		{
			const strength=step/1000,cutoff=impactLowpassCutoff(strength,48000,material);
			assert.ok(cutoff>=previous&&cutoff<18000);
			if(strength>=.02)assert.equal(cutoff,18000*(strength/.65)**.2);
			previous=cutoff;
		}
	}
});

test('material floors retain a softer rail timbre without changing ordinary impacts', () => {
	const ball=impactLowpassCutoff(0,48000,'ball'),rail=impactLowpassCutoff(0,48000,'rail');
	assert.equal(ball,18000*(.02/.65)**.2);
	assert.equal(rail,18000*(.01/.65)**.2);
	assert.ok(rail<ball);
	assert.equal(impactLowpassCutoff(.019,48000,'ball'),ball);
	assert.equal(impactLowpassCutoff(.009,48000,'rail'),rail);
	assert.equal(impactLowpassCutoff(.3,48000,'ball'),impactLowpassCutoff(.3,48000,'rail'));
});

test('cutoff stays below Nyquist at every sample rate and caps at 18 kHz', () => {
	for(const rate of [8000,16000,22050,32000,44100,48000,96000,192000])
	{
		const cutoff=impactLowpassCutoff(.649999,rate,'ball');
		assert.ok(cutoff<rate*.42&&cutoff<18000);
		assert.equal(cutoff,Math.min(18000,rate*.42)*(.649999/.65)**.2);
	}
	for(const rate of [0,-1,NaN,Infinity,undefined])assert.equal(impactLowpassCutoff(.1,rate,'ball'),0);
});

test('hard impacts bypass at the threshold and invalid strengths clamp safely', () => {
	for(const material of ['ball','rail'])
	{
		for(const strength of [.65,.8,1,2,Infinity])assert.equal(impactLowpassCutoff(strength,48000,material),0);
		for(const strength of [-Infinity,-1,NaN,undefined,null,'invalid'])
		assert.equal(impactLowpassCutoff(strength,48000,material),impactLowpassCutoff(0,48000,material));
		assert.equal(impactLowpassCutoff('0.3',48000,material),impactLowpassCutoff(.3,48000,material));
	}
});
