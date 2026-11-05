import test from 'node:test';
import assert from 'node:assert/strict';
import {InMemoryLobbyHub,InMemoryLobbyProvider} from '../node_modules/@seanmorris/phonics/browser/network/InMemoryLobbyProvider.mjs';
import {RoomGameProtocol} from '../node_modules/@seanmorris/phonics/browser/network/RoomGameLobby.mjs';
import * as mentalPoker from '../games/php-poker/browser/mental-poker-source.mjs';
import {OnlineDeal,ONLINE_ACTION_TYPES,ONLINE_RULES} from '../games/php-poker/browser/online-deal.mjs';

const storage=()=>{const values=new Map;return {getItem:key=>values.get(key)??null,setItem:(key,value)=>values.set(key,value),removeItem:key=>values.delete(key)};};
const until=async(condition,timeout=30000)=>{
	const started=Date.now();
	while(Date.now()-started<timeout){if(condition())return;await new Promise(resolve=>setTimeout(resolve,5));}
	throw new Error('Timed out waiting for private room deal');
};

test('the room protocol carries a verified private deal from seating through ten cards',async t=>{
	t.mock.method(console,'log',()=>{});
	const hub=new InMemoryLobbyHub,game={
		id:'org.example.private-poker',protocol:'1.0.0',rules:ONLINE_RULES,application:'poker-app',runtime:'poker-runtime'
		,actionTypes:[...ONLINE_ACTION_TYPES],maximumActionBytes:49152,checkpointMode:'history',actionBatches:true
	};
	const clients={};
	for(const id of ['alice','bob'])
	{
		const provider=new InMemoryLobbyProvider({hub,peerId:id});
		const protocol=new RoomGameProtocol({provider,game,session:{},storage:storage()});
		const client=clients[id]={id,provider,protocol,deal:null,port:null,work:Promise.resolve(),submitting:false,error:null};
		const enqueue=task=>{client.work=client.work.then(task).catch(error=>{client.error=error;});};
		const drive=async()=>{
			if(!client.deal||client.submitting||client.deal.currentActor()!==id)return;
			const action=client.deal.nextAction();if(!action)return;
			client.submitting=true;
			await client.port.submitAction(action,await client.deal.hash(),{settle:client.deal.settlesAction(action)});
		};
		client.port=protocol.attachGame({
			start:detail=>enqueue(async()=>{
				client.deal=new OnlineDeal({api:mentalPoker,...detail,localPlayer:id,rounds:16,storage:storage()});
				const hash=await client.deal.replay(detail.history??[]);await client.port.reportReady(hash);
			})
			,action:detail=>enqueue(async()=>{
				const waiting=await client.port.reportSettled(await client.deal.apply(detail));
				if(!waiting){client.submitting=false;await drive();}
			})
			,confirmed:detail=>enqueue(async()=>{
				assert.equal(detail.hash,await client.deal.hash());client.submitting=false;await drive();
			})
			,stop:detail=>{client.error=new Error(detail?.reason??'Room stopped');}
		});
		await protocol.connect();
	}
	t.after(()=>{for(const client of Object.values(clients)){client.protocol.close();client.provider.close();}});
	const root=await clients.alice.protocol.create();await clients.bob.protocol.join(root);
	await until(()=>Object.values(clients).every(client=>client.error||client.deal?.publicState().stage==='ready'));
	for(const client of Object.values(clients))assert.ifError(client.error);
	assert.equal(await clients.alice.deal.hash(),await clients.bob.deal.hash());
	assert.equal(clients.alice.deal.privateHand().length,5);assert.equal(clients.bob.deal.privateHand().length,5);
	assert.equal(new Set([...clients.alice.deal.privateHand(),...clients.bob.deal.privateHand()]).size,10);
	assert.ok(clients.alice.protocol.snapshot().tables[0].sequence>20);
	const events=hub.history(clients.alice.provider.room).map(item=>item.content);
	assert.equal(events.filter(item=>item.kind==='action').length,24);
	assert.equal(events.filter(item=>item.kind==='settled').length,6);
});
