import test from 'node:test';
import assert from 'node:assert/strict';
import {impactLowpassCutoff,pocketHit} from '../games/php-billiards/browser/game.mjs';

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

test('pocket knocks pick the strongest recording for hard strikes and the weakest for soft ones', () => {
	const steady=()=>.5;
	assert.equal(pocketHit(.68,steady),'pocket1');
	assert.equal(pocketHit(.45,steady),'pocket1');
	assert.equal(pocketHit(.17,steady),'pocket2');
	assert.equal(pocketHit(.06,steady),'pocket3');
	assert.equal(pocketHit(.012,steady),'pocket3');
	for(const random of [()=>0,()=>.999999])
	{
		assert.equal(pocketHit(1,random),'pocket1');
		assert.equal(pocketHit(.01,random),'pocket3');
	}
});

test('jitter varies a pocket knock only between neighbouring recordings', () => {
	const tier=name=>Number(name.slice(-1));
	let varied=false;
	for(let step=1;step<=1000;step++)
	{
		// Low random values lean toward the stronger recording, high ones toward the weaker.
		const strength=step/1000,stronger=tier(pocketHit(strength,()=>0)),weaker=tier(pocketHit(strength,()=>.999999));
		const steady=tier(pocketHit(strength,()=>.5));
		assert.ok(stronger>=1&&weaker<=3&&stronger<=steady&&steady<=weaker&&weaker-stronger<=1);
		varied||=stronger!==weaker;
		if(step>1)assert.ok(steady<=tier(pocketHit((step-1)/1000,()=>.5)),'a harder strike chose a weaker recording');
	}
	assert.ok(varied,'no strike could use either neighbouring recording');
});

test('invalid pocket strengths and random values clamp to a recording', () => {
	for(const strength of [-1,0,NaN,undefined,null,'invalid',-Infinity])assert.equal(pocketHit(strength,()=>.5),'pocket3');
	for(const strength of [2,Infinity])assert.equal(pocketHit(strength,()=>.5),'pocket1');
	for(const random of [()=>-5,()=>5,()=>NaN])assert.match(pocketHit(.2,random),/^pocket[123]$/);
});
