import assert from 'node:assert/strict';
import test from 'node:test';
import {BICYCLE_POKER_CUES,BicyclePokerBuzzer,bicyclePokerTones} from '../games/php-poker/browser/game.mjs';

class FakeParam
{
	constructor(){this.events=[];}
	setValueAtTime(value,at){this.events.push(['set',value,at]);}
	linearRampToValueAtTime(value,at){this.events.push(['ramp',value,at]);}
}

class FakeSource
{
	constructor(context){this.context=context;this.frequency=new FakeParam;this.type='';this.started=[];this.stopped=[];}
	connect(node){this.connected=node;return node;}
	start(at){this.started.push(at);}
	stop(at){this.stopped.push(at);}
}

class FakeContext
{
	constructor(){this.currentTime=10;this.state='suspended';this.destination={};this.sources=[];this.gains=[];this.closed=false;}
	createOscillator(){const source=new FakeSource(this);this.sources.push(source);return source;}
	createGain(){const gain={gain:new FakeParam,connect:node=>node};this.gains.push(gain);return gain;}
	resume(){this.state='running';return Promise.resolve();}
	close(){this.closed=true;return Promise.resolve();}
}

test('Bicycle Poker cue constants match the DOS PC-speaker calls',()=>{
	assert.deepEqual(BICYCLE_POKER_CUES.card,[{at:0,frequency:200,duration:.001}]);
	assert.deepEqual(BICYCLE_POKER_CUES.chips,[
		{at:0,frequency:900,duration:.010},{at:.020,frequency:500,duration:.015}
	]);
	assert.deepEqual(bicyclePokerTones('chips',2),[
		{at:2,frequency:900,duration:.010},{at:2.02,frequency:500,duration:.015}
	]);
	assert.deepEqual(bicyclePokerTones('missing',2),[]);
	assert.deepEqual(bicyclePokerTones('card',Number.NaN),[]);
});

test('the browser buzzer schedules each motion once with square oscillators',()=>{
	const context=new FakeContext,buzzer=new BicyclePokerBuzzer({createContext:()=>context});
	assert.equal(buzzer.unlock(),true);
	buzzer.motion({elapsed:0,sounds:[{at:0,cue:'card'},{at:.1,cue:'chips'}]},'deal:1');
	assert.equal(context.sources.length,3);
	assert.deepEqual(context.sources.map(source=>source.type),['square','square','square']);
	assert.deepEqual(context.sources.map(source=>source.frequency.events[0][1]),[200,900,500]);
	assert.deepEqual(context.sources.map(source=>source.started[0]),[10,10.1,10.12]);
	assert.equal(buzzer.status().played,2);
	buzzer.motion({elapsed:.02,sounds:[{at:0,cue:'card'},{at:.1,cue:'chips'}]},'deal:1');
	assert.equal(context.sources.length,3,'a later status for the same motion does not duplicate its sounds');

	context.currentTime=11;
	buzzer.motion({elapsed:.2,sounds:[{at:.1,cue:'card'},{at:.18,cue:'card'},{at:.3,cue:'card'}]},'draw:2');
	assert.equal(context.sources.length,5,'a cue up to 50 ms late is recovered and an older cue is skipped');
	assert.deepEqual(context.sources.slice(3).map(source=>source.started[0]),[11,11.1]);
	buzzer.setMuted(true);
	assert.equal(buzzer.status().muted,true);
	assert.ok(context.sources.every(source=>source.stopped.length>0),'muting stops active and scheduled oscillators');
	buzzer.close();
	assert.equal(context.closed,true);
});
