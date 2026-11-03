import test from 'node:test';
import assert from 'node:assert/strict';
import {bottomKnocks,impactLowpassCutoff,pocketHit,pocketKnockLevel,queuedDrop} from '../games/php-billiards/browser/game.mjs';

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

test('pocket knocks stay audible down to the finest chatter and peak like a full clack', () => {
	assert.equal(pocketKnockLevel(0),.3);
	assert.equal(pocketKnockLevel(.45),1);
	assert.equal(pocketKnockLevel(1),1);
	let previous=0;
	for(let step=0;step<=1000;step++)
	{
		const level=pocketKnockLevel(step/1000);
		assert.ok(level>=previous&&level>=.3&&level<=1);
		previous=level;
	}
	// The chatter of a ball rolling around the edge is far louder than on the ball-ball curve it replaces.
	assert.ok(pocketKnockLevel(.02)/(.12+.88*.02**.8)>2);
	for(const strength of [NaN,undefined,null,'invalid',-1])assert.equal(pocketKnockLevel(strength),.3);
});

test('a landing knocks with hit 1 before the sink, and a lively one bounces once more', () => {
	for(const energy of [0,.1,.199,NaN,undefined])
	{
		const landing=bottomKnocks(energy,()=>.5);
		assert.deepEqual(landing.knocks,[{hit:'pocket1',level:1,delay:0}]);
		assert.ok(Math.abs(landing.sinkDelay-.065)<1e-12);
	}
	for(const energy of [.2,.44,1])
	{
		const landing=bottomKnocks(energy,()=>.5);
		assert.deepEqual(landing.knocks.map(knock=>knock.hit),['pocket1','pocket2']);
		assert.ok(landing.knocks[1].level<landing.knocks[0].level);
		assert.ok(Math.abs(landing.knocks[1].delay-.16)<1e-12&&Math.abs(landing.sinkDelay-.225)<1e-12);
	}
});

test('landing gaps vary within their ranges and keep their order', () => {
	const bounces=new Set,sinks=new Set;
	for(let step=0;step<=100;step++)
	{
		const value=step/100,lively=bottomKnocks(1,()=>value),quiet=bottomKnocks(0,()=>value);
		const bounce=lively.knocks[1].delay;
		assert.ok(bounce>=.13-1e-12&&bounce<=.19+1e-12,'the bounce left its window');
		assert.ok(lively.sinkDelay-bounce>=.05-1e-12&&lively.sinkDelay-bounce<=.08+1e-12,'the sink did not follow the bounce');
		assert.ok(quiet.sinkDelay>=.05-1e-12&&quiet.sinkDelay<=.08+1e-12,'the sink did not follow the knock');
		bounces.add(bounce.toFixed(4));sinks.add(quiet.sinkDelay.toFixed(4));
	}
	assert.ok(bounces.size>50&&sinks.size>50,'landing timing did not vary');
	for(const random of [()=>-1,()=>2,()=>NaN])
	{
		const landing=bottomKnocks(1,random);
		assert.ok(landing.knocks[1].delay>=.13&&landing.knocks[1].delay<=.19&&landing.sinkDelay>landing.knocks[1].delay);
	}
});

test('balls in the same pocket land one at a time, 200-300 ms apart', () => {
	// A clear pocket lands at once.
	assert.equal(queuedDrop(undefined,4,()=>.5),4);
	assert.equal(queuedDrop(1,4,()=>.5),4);
	// A second ball waits behind the first, and a third behind the second.
	const second=queuedDrop(4,4.05,()=>.5),third=queuedDrop(second,4.1,()=>.5);
	assert.ok(Math.abs(second-4.25)<1e-12&&Math.abs(third-4.5)<1e-12);
	// A ball arriving partway through the gap waits only for the rest of it.
	assert.ok(Math.abs(queuedDrop(4,4.2,()=>.5)-4.25)<1e-12);
	const gaps=new Set;
	for(let step=0;step<=100;step++)
	{
		const gap=queuedDrop(4,4,()=>step/100)-4;
		assert.ok(gap>=.2-1e-12&&gap<=.3+1e-12,'the gap left its window');
		gaps.add(gap.toFixed(4));
	}
	assert.ok(gaps.size>50,'the gap did not vary');
	for(const random of [()=>-1,()=>2,()=>NaN])
	{
		const gap=queuedDrop(4,4,random)-4;
		assert.ok(gap>=.2&&gap<=.3);
	}
	assert.equal(queuedDrop(NaN,4,()=>.5),4);
});
