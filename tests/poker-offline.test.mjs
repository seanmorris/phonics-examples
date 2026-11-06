import test from 'node:test';
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import vm from 'node:vm';
import {createHash, webcrypto} from 'node:crypto';
import {activate} from '../games/php-poker/browser/persistence.mjs';

test('atomic local saves retain the last complete turn after quota failure',()=>{
	const old=globalThis.localStorage,values=new Map;
	let blocked=false;
	globalThis.localStorage={getItem:key=>values.get(key)??null,setItem:(key,value)=>{
		if(blocked) throw new Error('Quota exceeded'); values.set(key,value);
	}};
	try
	{
		const module={},session={},close=activate({module,session});
		assert.equal(module.pokerLoad(),'');
		assert.equal(module.pokerSave('{"revision":1}'),true);
		blocked=true; assert.equal(module.pokerSave('{"revision":2}'),false);
		assert.equal(module.pokerLoad(),'{"revision":1}');
		assert.match(JSON.parse(module.pokerStorageStatus()).error,/could not be saved/);
		blocked=false; assert.equal(module.pokerSave('{"revision":2}'),true);
		assert.equal(JSON.parse(module.pokerStorageStatus()).error,'');
		close(); assert.equal(module.pokerSave,undefined); assert.equal(session.phpPokerStorage,undefined);
	}
	finally { if(old===undefined) delete globalThis.localStorage; else globalThis.localStorage=old; }
});

test('explicit Poker save and load use native file pickers',async()=>{
	const old=globalThis.localStorage,oldSave=globalThis.showSaveFilePicker,oldOpen=globalThis.showOpenFilePicker,values=new Map;
	globalThis.localStorage={getItem:key=>values.get(key)??null,setItem:(key,value)=>values.set(key,value)};
	let saved='';
	globalThis.showSaveFilePicker=async options=>{
		assert.equal(options.suggestedName,'php-poker-save.json');
		return {createWritable:async()=>({write:async value=>{saved=value;},close:async()=>{}})};
	};
	globalThis.showOpenFilePicker=async()=>[{getFile:async()=>new Blob([saved],{type:'application/json'})}];
	const settled=async module=>{for(let attempt=0;attempt<20;attempt++){if(!JSON.parse(module.pokerFileStatus()).busy)return;await new Promise(resolve=>setTimeout(resolve,0));}assert.fail('file picker did not settle');};
	try
	{
		const module={},session={},close=activate({module,session});
		assert.equal(session.phpPokerStorage.status.file.busy,false);
		assert.equal(module.pokerSave('{"auto":1}'),true);
		assert.equal(module.pokerFileSave('{"manual":2}'),true);await settled(module);
		assert.equal(saved,'{"manual":2}');
		assert.equal(module.pokerLoad(),'{"auto":1}');
		assert.equal(module.pokerFileLoad(),true);await settled(module);
		assert.equal(module.pokerFileTakeLoad(),'{"manual":2}');
		assert.equal(module.pokerFileTakeLoad(),'');
		assert.equal(JSON.parse(module.pokerFileStatus()).notice,'Checking saved game.');
		module.pokerFileCompleteLoad(true);
		assert.equal(JSON.parse(module.pokerFileStatus()).notice,'Game loaded.');
		close();
		assert.equal(module.pokerFileLoad,undefined);
	}
	finally
	{
		if(old===undefined) delete globalThis.localStorage; else globalThis.localStorage=old;
		if(oldSave===undefined) delete globalThis.showSaveFilePicker; else globalThis.showSaveFilePicker=oldSave;
		if(oldOpen===undefined) delete globalThis.showOpenFilePicker; else globalThis.showOpenFilePicker=oldOpen;
	}
});

async function workerHarness({corrupt=false}={})
{
	const root='https://example.test/preview/',scope=root+'games/php-poker/';
	const content=new Map([
		['games/php-poker/index.html','<html>poker</html>'],
		['host.mjs','export const boot=true;'],
		['applications/php-poker/game.phar','a pinned game archive']
	]);
	const files=[...content].map(([path,bytes])=>({path,bytes:Buffer.byteLength(bytes),sha256:createHash('sha256').update(bytes).digest('hex')}));
	const manifest={schema:1,build:'fixture-build',files},stores=new Map,listeners=new Map,requests=[];
	let online=true,claimed=0;
	const caches={
		async open(name){
			if(!stores.has(name)) stores.set(name,new Map);
			const entries=stores.get(name);
			return {
				async match(key){return entries.get(typeof key==='string'?key:key.url??key.href)?.clone();},
				async put(key,response){entries.set(typeof key==='string'?key:key.url??key.href,response.clone());},
				async keys(){return [...entries.keys()].map(url=>new Request(url));}
			};
		},
		async keys(){return [...stores.keys()];},
		async delete(name){return stores.delete(name);}
	};
	const fetch=async request=>{
		const url=typeof request==='string'?request:request.url??request.href;
		requests.push(url);
		if(!online) throw Error('Network disabled');
		if(url===scope+'offline-manifest.json') return Response.json(manifest);
		const path=url.slice(root.length);
		if(!content.has(path)) return new Response('missing',{status:404});
		return new Response(corrupt&&path==='host.mjs'?'tampered':content.get(path));
	};
	const self={location:new URL(scope+'offline-worker.js'),registration:{scope},
		clients:{async claim(){claimed++;}},addEventListener:(type,fn)=>listeners.set(type,fn)};
	const code=(await readFile(new URL('../site/src/poker-offline-worker.js',import.meta.url),'utf8')).replace('__POKER_BUILD__','fixture-build');
	vm.runInNewContext(code,{self,fetch,caches,URL,Response,Request,crypto:webcrypto,Uint8Array,Set,Map,Number,Boolean,Error,JSON});
	const dispatch=async(type,extra={})=>{
		let promise;
		listeners.get(type)({...extra,waitUntil:value=>{promise=value;},respondWith:value=>{promise=value;}});
		return await promise;
	};
	return {root,scope,stores,requests,dispatch,setOnline:value=>{online=value;},get claimed(){return claimed;}};
}

test('offline install verifies every asset and directory navigation reopens without network',async()=>{
	const h=await workerHarness();
	await h.dispatch('install'); await h.dispatch('activate'); assert.equal(h.claimed,1);
	const fetched=h.requests.length; h.setOnline(false);
	const page=await h.dispatch('fetch',{request:new Request(h.scope)});
	assert.equal(await page.text(),'<html>poker</html>');
	const runtime=await h.dispatch('fetch',{request:new Request(h.root+'host.mjs?cache=123')});
	assert.equal(await runtime.text(),'export const boot=true;');
	assert.equal(h.requests.length,fetched);
	await assert.rejects(h.dispatch('fetch',{request:new Request(h.root+'games/php-billiards/')}),/Network disabled/);
});

test('integrity mismatch never activates a partially downloaded game',async()=>{
	const h=await workerHarness({corrupt:true});
	await assert.rejects(h.dispatch('install'),/integrity mismatch/);
	assert.equal(h.stores.size,0);
	await assert.rejects(h.dispatch('activate'),/Incomplete offline installation/);
	assert.equal(h.claimed,0);
});

test('evicted offline assets are repaired only from matching verified bytes',async()=>{
	const h=await workerHarness(); await h.dispatch('install'); await h.dispatch('activate');
	const cache=[...h.stores.values()][0]; cache.delete(h.root+'host.mjs');
	let ready;
	await h.dispatch('message',{data:{type:'poker-offline-check'},ports:[{postMessage:value=>{ready=value.ready;}}]});
	assert.equal(ready,true); assert.ok(cache.has(h.root+'host.mjs'));
	h.setOnline(false); cache.delete(h.root+'host.mjs'); ready=undefined;
	await h.dispatch('message',{data:{type:'poker-offline-check'},ports:[{postMessage:value=>{ready=value.ready;}}]});
	assert.equal(ready,false); assert.ok(cache.has(h.root+'games/php-poker/index.html'));
});
