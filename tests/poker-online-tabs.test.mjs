import test from 'node:test';
import assert from 'node:assert/strict';
import {InMemoryLobbyHub,InMemoryLobbyProvider} from '../node_modules/@seanmorris/phonics/browser/network/InMemoryLobbyProvider.mjs';
import {RoomGameProtocol} from '../node_modules/@seanmorris/phonics/browser/network/RoomGameLobby.mjs';
import * as mentalPoker from '../games/php-poker/browser/mental-poker-source.mjs';
import {OnlineDeal,ONLINE_ACTION_TYPES,ONLINE_RULES,readOnlyStorage} from '../games/php-poker/browser/online-deal.mjs';
import {seatStorage} from '../games/php-poker/browser/game.mjs';

const storage=()=>{const values=new Map;return {getItem:key=>values.get(key)??null,setItem:(key,value)=>values.set(key,value),removeItem:key=>values.delete(key)};};
const until=async(condition,label,timeout=60000)=>{
	const started=Date.now();
	while(Date.now()-started<timeout){if(condition())return;await new Promise(resolve=>setTimeout(resolve,5));}
	throw new Error('Timed out waiting for '+label);
};

/** Web Locks shared by one browser's tabs: exclusive, with ifAvailable, steal and abortable waits. */
class Locks
{
	held=new Map;queues=new Map;
	request(name,options,callback)
	{
		return new Promise((resolve,reject)=>{
			const entry={callback,resolve,reject},holder=this.held.get(name),queue=this.queues.get(name)??[];
			if(options.steal){this.held.delete(name);holder?.reject(new DOMException('The lock was stolen','AbortError'));this.grant(name,entry);return;}
			if(!holder&&!queue.length){this.grant(name,entry);return;}
			if(options.ifAvailable){this.run(entry,null);return;}
			queue.push(entry);this.queues.set(name,queue);
			options.signal?.addEventListener('abort',()=>{
				if(!queue.includes(entry))return;
				queue.splice(queue.indexOf(entry),1);reject(options.signal.reason);
			},{once:true});
		});
	}
	grant(name,entry)
	{
		this.held.set(name,entry);
		void this.run(entry,{name}).finally(()=>{
			if(this.held.get(name)!==entry)return;
			this.held.delete(name);
			const next=this.queues.get(name)?.shift();if(next)this.grant(name,next);
		});
	}
	run(entry,lock){return Promise.resolve().then(()=>entry.callback(lock)).then(entry.resolve,entry.reject);}
}

// Bob opens the table in a second tab mid-deal and takes his seat over there. The tab that played first falls back to
// watching, and the deal still reaches showdown with Bob's private record intact in the browser's shared storage.
for(const [stage,sequence] of [['shuffle',1],['deal',4],['draw',8]])
test(`a seat taken over by another tab during the ${stage} finishes the hand from the shared private record`,async t=>{
	t.mock.method(console,'log',()=>{});
	const hub=new InMemoryLobbyHub,game={
		id:'org.example.private-poker',protocol:'1.0.0',rules:ONLINE_RULES,application:'poker-app',runtime:'poker-runtime'
		,actionTypes:[...ONLINE_ACTION_TYPES],maximumActionBytes:49152,checkpointMode:'history',actionBatches:true
	};
	const browsers={alice:{locks:new Locks,storage:storage()},bob:{locks:new Locks,storage:storage()}};
	const clients={};
	const open=async(name,id)=>{
		const browser=browsers[id];
		const provider=new InMemoryLobbyProvider({hub,peerId:id,tab:name});
		const protocol=new RoomGameProtocol({provider,game,session:{},storage:browser.storage,locks:browser.locks,tabs:null});
		const client=clients[name]={id,provider,protocol,deal:null,port:null,work:Promise.resolve(),submitting:false,errors:[],run:0,watching:false,starts:[]};
		// Mirrors the Poker adapter: work from a retired run may fail once its seat moved, and is not reported.
		const enqueue=task=>{const run=client.run;client.work=client.work.then(task).catch(error=>{if(run===client.run)client.errors.push(error);});};
		const drive=async()=>{
			if(!client.deal||client.watching||client.submitting||client.deal.currentActor()!==id)return;
			let action=client.deal.nextAction();
			if(!action)
			{
				const state=client.deal.publicState(),legal=client.deal.view().legalActions;
				if(state.stage==='betting')client.deal.command(legal.call?{type:'call'}:{type:'bet',amount:legal.minBet});
				else if(state.stage==='draw')client.deal.command({type:'draw',cards:client.deal.privateHand().slice(0,1)});
				else if(state.stage==='final-betting')client.deal.command({type:'check'});
				action=client.deal.nextAction();if(!action)return;
			}
			client.submitting=true;
			await client.port.submitAction(action,await client.deal.hash(),{settle:client.deal.settlesAction(action)});
		};
		client.port=protocol.attachGame({
			start:detail=>{
				const run=++client.run,standby=Boolean(detail.standby),seat=standby?detail.seat:detail.localPlayer;
				client.starts.push({standby,seat});
				enqueue(async()=>{
					client.watching=standby;client.submitting=false;
					client.deal=new OnlineDeal({
						api:mentalPoker,...detail,localPlayer:seat,rounds:16,legacyStorage:null
						,storage:standby?readOnlyStorage(browser.storage):seatStorage(browser.storage,()=>run===client.run),follow:standby
					});
					const hash=await client.deal.replay(detail.history??[]);
					if(!standby)await client.port.reportReady(hash);
				});
			}
			,action:detail=>enqueue(async()=>{
				const settlement=await client.deal.apply(detail);
				if(client.watching)return;
				const waiting=await client.port.reportSettled(settlement);
				if(!waiting){client.submitting=false;await drive();}
			})
			,confirmed:detail=>enqueue(async()=>{
				assert.equal(detail.hash,await client.deal.hash());
				if(client.watching)return;
				client.submitting=false;await drive();
			})
			,stop:detail=>{client.errors.push(new Error(detail?.reason??'Room stopped'));}
		});
		await protocol.connect();
		return client;
	};
	t.after(()=>{for(const client of Object.values(clients)){client.protocol.close();client.provider.close();}});

	const alice=await open('alice','alice'),first=await open('bob-1','bob'),second=await open('bob-2','bob');
	const root=await alice.protocol.create();await first.protocol.join(root);
	await second.protocol.resume(root);
	await until(()=>second.protocol.snapshot().standby,'the second tab to stand by');
	await until(()=>alice.protocol.sessions.get(root)?.sequence>=sequence,'the '+stage);

	await second.protocol.takeOverSeat();
	await until(()=>first.protocol.snapshot().standby&&first.starts.at(-1)?.standby,'the first tab to watch');

	const done=client=>client.errors.length||client.deal?.publicState().stage==='showdown';
	await until(()=>[alice,first,second].every(done),'showdown');
	for(const client of [alice,first,second])assert.deepEqual(client.errors,[],client.id);
	assert.equal(await second.deal.hash(),await alice.deal.hash());
	assert.equal(await first.deal.hash(),await alice.deal.hash(),'the watching tab follows the same deal');
	assert.equal(second.deal.privateHand().length,5);
	assert.deepEqual(first.deal.privateHand(),second.deal.privateHand(),'both tabs show Bob the same hand');
	assert.equal(new Set([...alice.deal.privateHand(),...second.deal.privateHand()]).size,10);
	const transcript=hub.history(alice.provider.room).map(item=>item.content);
	assert.equal(transcript.filter(item=>item.kind==='resign'||item.kind==='timeout').length,0);
});
