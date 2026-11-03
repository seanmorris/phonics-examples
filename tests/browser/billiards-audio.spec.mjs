import {test, expect} from '@playwright/test';
import {readFile} from 'node:fs/promises';
import {impactLowpassCutoff} from '../../games/php-billiards/browser/game.mjs';

test('recorded ball, rail and pocket voices follow their contact strength', async ({page}, testInfo) => {
	const source=await readFile(new URL('../../games/php-billiards/browser/game.mjs',import.meta.url),'utf8');
	const samples={},sizes={};
	for(const [name,file] of Object.entries({
		clack:'clack',rail:'rail-hit',pocket1:'pocket-drop-hit-1',pocket2:'pocket-drop-hit-2',pocket3:'pocket-drop-hit-3'
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
				const filter=impactFilters.length>filterCount?impactFilters.at(-1):null;
				impactVoices.push({gain:gain.gain.value,rate:source.playbackRate.value,sampleRate:this.context.sampleRate,
					filter:filter?{type:filter.type,frequency:filter.frequency.value,q:filter.Q.value}:null,
					bytes:sources.get(source.buffer),peak:peak(source.buffer)});
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
	await expect.poll(()=>page.evaluate(()=>document.querySelector('canvas').phpBilliardsDebug.audio.decoded)).toBe(5);
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

	// Pocket knocks choose a recording by strength, 1 strongest, and peak like a ball clack of the same strength.
	const clackPeak=voices.find(voice=>voice.type==='impact').peak;
	for(const [strength,hit] of [[.45,'pocket1'],[.17,'pocket2'],[.05,'pocket3']])
	{
		await page.waitForTimeout(30);
		await page.evaluate(strength=>impactModule.billiardsStatus(JSON.stringify({events:[{type:'pocket-rattle',strength}]})),strength);
		const voice=await page.evaluate(()=>impactVoices.at(-1));
		voices.push({type:'pocket-rattle',strength,...voice});
		expect(voice.bytes).toBe(sizes[hit]);
		expect(voice.filter).toBeNull();
		expect(voice.gain*voice.peak).toBeCloseTo((.12+.88*strength**.8)*clackPeak,5);
	}
	expect(await page.evaluate(()=>impactVoices.length)).toBe(11);

	// Entering a pocket is silent; only the knocks and the final sink are heard.
	await page.waitForTimeout(30);
	await page.evaluate(()=>impactModule.billiardsStatus(JSON.stringify({events:[{type:'pocket-enter',ball:3,pocket:0}]})));
	expect(await page.evaluate(()=>impactVoices.length)).toBe(11);
	await testInfo.attach('impact-voices.json',{body:JSON.stringify(voices,null,2),contentType:'application/json'});
	await page.evaluate(()=>disposeImpact());
});
