import {test, expect} from '@playwright/test';
import {readFile} from 'node:fs/promises';
import {impactLowpassCutoff,pocketKnockLevel} from '../../games/php-billiards/browser/game.mjs';

test('recorded ball, rail and pocket voices follow their contact strength', async ({page}, testInfo) => {
	const source=await readFile(new URL('../../games/php-billiards/browser/game.mjs',import.meta.url),'utf8');
	const samples={},sizes={};
	for(const [name,file] of Object.entries({
		clack:'clack',rail:'rail-hit',sink:'pocket-sink'
		,pocket1:'pocket-drop-hit-1',pocket2:'pocket-drop-hit-2',pocket3:'pocket-drop-hit-3'
	}))
	{
		const bytes=await readFile(new URL(`../../games/php-billiards/assets/audio/${file}.mp3`,import.meta.url));
		samples[name]=bytes.toString('base64');
		sizes[name]=bytes.length;
	}
	await page.goto('/');
	await page.evaluate(async({source,samples})=>{
		document.body.innerHTML='<div style="width:800px;height:450px"><canvas></canvas></div>';
		const {activate}=await import(URL.createObjectURL(new Blob([source],{type:'text/javascript'})));
		const createFilter=AudioContext.prototype.createBiquadFilter,createSource=AudioContext.prototype.createBufferSource;
		const decode=AudioContext.prototype.decodeAudioData,sources=new Map;
		globalThis.impactVoices=[];globalThis.impactFilters=[];
		// Remember which encoded sample each decoded buffer came from, by its byte length.
		AudioContext.prototype.decodeAudioData=async function(bytes){
			const size=bytes.byteLength,buffer=await decode.call(this,bytes);
			sources.set(buffer,size);
			return buffer;
		};
		const peak=buffer=>{
			let value=0;
			for(let channel=0;channel<buffer.numberOfChannels;channel++)for(const sample of buffer.getChannelData(channel))value=Math.max(value,Math.abs(sample));
			return value;
		};
		AudioContext.prototype.createBiquadFilter=function(){const filter=createFilter.call(this);impactFilters.push(filter);return filter;};
		AudioContext.prototype.createBufferSource=function(){
			const source=createSource.call(this),connect=source.connect,start=source.start;
			let gain;const filterCount=impactFilters.length;
			source.connect=function(node){gain=node;return connect.call(this,node);};
			source.start=function(...args){
				// Read the audio clock first: it keeps running while the peak scan below walks a long sample.
				const when=args[0]??0,now=this.context.currentTime;
				const filter=impactFilters.length>filterCount?impactFilters.at(-1):null;
				impactVoices.push({gain:gain.gain.value,rate:source.playbackRate.value,sampleRate:this.context.sampleRate,
					filter:filter?{type:filter.type,frequency:filter.frequency.value,q:filter.Q.value}:null,
					bytes:sources.get(source.buffer),peak:peak(source.buffer),delay:when>0?when-now:0});
				return start.apply(this,args);
			};
			return source;
		};
		Math.random=()=>.5;
		const module={};globalThis.impactModule=module;
		globalThis.disposeImpact=activate({module,canvas:document.querySelector('canvas'),session:{lobby:{
			protocol:new EventTarget,attachGame:()=>({}),open:async()=>{}
		}}});
		module.billiardsSamples(JSON.stringify(samples));
	}, {source,samples});
	await page.keyboard.press('Space');
	await expect.poll(()=>page.evaluate(()=>document.querySelector('canvas').phpBilliardsDebug.audio.decoded)).toBe(6);
	const voices=[];
	for(const type of ['impact','cushion']) for(const strength of [.01,.25,.65,1])
	{
		await page.waitForTimeout(30);
		await page.evaluate(({type,strength})=>impactModule.billiardsStatus(JSON.stringify({events:[{type,strength}]})),{type,strength});
		const voice=await page.evaluate(()=>impactVoices.at(-1));
		voices.push({type,strength,...voice});
		const cutoff=impactLowpassCutoff(strength,voice.sampleRate,type==='impact'?'ball':'rail');
		if(cutoff===0)expect(voice.filter).toBeNull();
		else
		{
			expect(voice.filter.type).toBe('lowpass');
			expect(voice.filter.q).toBeCloseTo(.6,6);
			expect(Math.abs(voice.filter.frequency-cutoff)).toBeLessThan(.002);
		}
		expect(voice.rate).toBeCloseTo(type==='impact'?.94+.12*strength:1,6);
		if(type==='impact')expect(voice.gain).toBeCloseTo(.12+.88*strength**.8,6);
	}
	expect(await page.evaluate(()=>impactVoices.length)).toBe(8);

	// Pocket knocks choose a recording by strength, 1 strongest, on their own louder curve relative to the clack.
	// Chatter keeps two voices free for a landing, so let the ball and rail voices above finish first.
	const clackPeak=voices.find(voice=>voice.type==='impact').peak;
	await page.waitForTimeout(700);
	for(const [strength,hit] of [[.45,'pocket1'],[.17,'pocket2'],[.05,'pocket3']])
	{
		await page.waitForTimeout(30);
		await page.evaluate(strength=>impactModule.billiardsStatus(JSON.stringify({events:[{type:'pocket-rattle',ball:9,strength}]})),strength);
		const voice=await page.evaluate(()=>impactVoices.at(-1));
		voices.push({type:'pocket-rattle',strength,...voice});
		expect(voice.bytes).toBe(sizes[hit]);
		expect(voice.filter).toBeNull();
		expect(voice.gain*voice.peak).toBeCloseTo(pocketKnockLevel(strength)*clackPeak,5);
	}
	expect(await page.evaluate(()=>impactVoices.length)).toBe(11);

	// Entering a pocket is silent; only the knocks and the landing are heard.
	await page.waitForTimeout(30);
	await page.evaluate(()=>impactModule.billiardsStatus(JSON.stringify({events:[{type:'pocket-enter',ball:3,pocket:0}]})));
	expect(await page.evaluate(()=>impactVoices.length)).toBe(11);

	// A quiet drop lands with one full hit 1 just before the sink.
	await page.waitForTimeout(30);
	await page.evaluate(()=>impactModule.billiardsStatus(JSON.stringify({events:[{type:'pocket',ball:3,pocket:0}]})));
	const quiet=await page.evaluate(()=>impactVoices.slice(11));
	expect(quiet.map(voice=>voice.bytes)).toEqual([sizes.pocket1,sizes.sink]);
	expect(quiet[0].gain*quiet[0].peak).toBeCloseTo(clackPeak,5);
	expect(quiet[0].delay).toBe(0);
	expect(quiet[1].delay).toBeCloseTo(.065,2);

	// Ball 9 struck the walls at .45 above, so its landing bounces once more on hit 2 before the sink.
	await page.waitForTimeout(30);
	await page.evaluate(()=>impactModule.billiardsStatus(JSON.stringify({events:[{type:'pocket',ball:9,pocket:2}]})));
	const lively=await page.evaluate(()=>impactVoices.slice(13));
	expect(lively.map(voice=>voice.bytes)).toEqual([sizes.pocket1,sizes.pocket2,sizes.sink]);
	expect(lively[1].delay).toBeCloseTo(.16,2);
	expect(lively[2].delay).toBeCloseTo(.225,2);
	expect(lively[1].gain*lively[1].peak).toBeCloseTo(.55*clackPeak,5);

	// Two balls dropping into one pocket together reach the bottom one at a time, the second 250 ms behind the first.
	// Every sink rings for 3 s, so let the earlier ones finish rather than meet the voice cap.
	await expect.poll(()=>page.evaluate(()=>document.querySelector('canvas').phpBilliardsDebug.audio.voices),{timeout:6000}).toBe(0);
	await page.evaluate(()=>impactModule.billiardsStatus(JSON.stringify({events:[
		{type:'pocket',ball:4,pocket:5},{type:'pocket',ball:5,pocket:5}
	]})));
	const pair=await page.evaluate(()=>impactVoices.slice(16));
	expect(pair.map(voice=>voice.bytes)).toEqual([sizes.pocket1,sizes.sink,sizes.pocket1,sizes.sink]);
	expect(pair[0].delay).toBe(0);
	expect(pair[1].delay).toBeCloseTo(.065,2);
	expect(pair[2].delay).toBeCloseTo(.25,2);
	expect(pair[3].delay).toBeCloseTo(.315,2);
	await testInfo.attach('impact-voices.json',{body:JSON.stringify(voices,null,2),contentType:'application/json'});
	await page.evaluate(()=>disposeImpact());
});
