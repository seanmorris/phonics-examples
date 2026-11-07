import test from 'node:test';
import assert from 'node:assert/strict';
import {InMemoryLobbyHub,InMemoryLobbyProvider} from '../node_modules/@seanmorris/phonics/browser/network/InMemoryLobbyProvider.mjs';
import {RoomGameProtocol} from '../node_modules/@seanmorris/phonics/browser/network/RoomGameLobby.mjs';
import * as mentalPoker from '../games/php-poker/browser/mental-poker-source.mjs';
import {OnlineDeal,ONLINE_ACTION_TYPES,ONLINE_RULES} from '../games/php-poker/browser/online-deal.mjs';
import {matrixPlayerName,onlineTimeoutNotice} from '../games/php-poker/browser/game.mjs';

const storage=()=>{const values=new Map;return {getItem:key=>values.get(key)??null,setItem:(key,value)=>values.set(key,value),removeItem:key=>values.delete(key)};};
const until=async(condition,label,timeout=90000)=>{
	const started=Date.now();
	while(Date.now()-started<timeout){if(condition())return;await new Promise(resolve=>setTimeout(resolve,10));}
	throw new Error(`Timed out waiting for ${label}`);
};

test('a player who stops during the private deal loses on time, and both browsers agree',async t=>{
	t.mock.method(console,'log',()=>{});
	// Poker plays with five minutes per step; fifteen seconds keeps this real-time test short with room for slow CI.
	const hub=new InMemoryLobbyHub,game={
		id:'org.example.private-poker',protocol:'1.0.0',rules:ONLINE_RULES,application:'poker-app',runtime:'poker-runtime'
		,actionTypes:[...ONLINE_ACTION_TYPES],maximumActionBytes:49152,checkpointMode:'history',actionBatches:true
		,actionTimeoutMs:15000
	};
	const clients={};
	for(const id of ['alice','bob'])
	{
		const provider=new InMemoryLobbyProvider({hub,peerId:id});
		const protocol=new RoomGameProtocol({provider,game,session:{},storage:storage()});
		const client=clients[id]={id,provider,protocol,deal:null,port:null,work:Promise.resolve(),submitting:false,error:null,stops:[]};
		const enqueue=task=>{client.work=client.work.then(task).catch(error=>{client.error=error;});};
		const drive=async()=>{
			if(!client.deal||client.submitting||client.deal.currentActor()!==id)return;
			// Bob walks away once the cards start to be dealt, but his browser keeps checking Alice's steps.
			if(id==='bob'&&client.deal.publicState().stage==='deal'){client.stalled=true;return;}
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
			,stop:detail=>{client.stops.push(detail);}
		});
		await protocol.connect();
	}
	t.after(()=>{for(const client of Object.values(clients)){client.protocol.close();client.provider.close();}});
	const root=await clients.alice.protocol.create();await clients.bob.protocol.join(root);
	await until(()=>clients.bob.stalled,'Bob to stop at the deal');
	const session=id=>clients[id].protocol.sessions.get(root);
	await until(()=>session('alice').status==='complete'&&session('bob').status==='complete','the timeout claim');
	for(const client of Object.values(clients))assert.equal(client.error,null,`${client.id} saw no protocol error`);
	const outcome={winner:'alice',loser:'bob',reason:'timeout',timeout:true};
	assert.deepEqual(session('alice').outcome,outcome);
	assert.deepEqual(session('bob').outcome,outcome,'Bob\'s own game confirms that he owed the step');
	assert.deepEqual(clients.bob.stops.at(-1),outcome);
	const claims=hub.history(clients.alice.provider.room).filter(item=>item.content?.kind==='timeout');
	assert.equal(claims.length,1);
	assert.equal(claims[0].sender,'alice');
	assert.equal(claims[0].content.against,'bob');
	assert.ok(claims[0].origin_server_ts>=session('alice').clockSince+15000,'the claim waited for the deadline');
	assert.equal(clients.alice.deal.publicState().stage,'deal','the hand stopped where Bob walked away');
});

test('the online result names who ran out of time',()=>{
	const name=id=>({'@alice:example.org':'Alice','@bob:example.org':'Bob'})[id]??matrixPlayerName(id);
	const detail={winner:'@alice:example.org',loser:'@bob:example.org',reason:'timeout',timeout:true};
	assert.deepEqual(onlineTimeoutNotice(detail,'@alice:example.org',name),{message:'Bob ran out of time. You win the match.',error:''});
	assert.deepEqual(onlineTimeoutNotice(detail,'@bob:example.org',name)
		,{message:'You ran out of time.',error:'You ran out of time. Alice wins the match.'});
	assert.deepEqual(onlineTimeoutNotice(detail,'@carol:example.org',name)
		,{message:'Bob ran out of time. Alice wins the match.',error:''},'a watcher sees both names');
	assert.equal(onlineTimeoutNotice(detail,'@alice:example.org').message,'bob ran out of time. You win the match.'
		,'without profiles the Matrix localpart stands in');
	assert.equal(onlineTimeoutNotice({winner:'@alice:example.org',reason:'resigned'},'@alice:example.org'),null);
	assert.equal(onlineTimeoutNotice(null,'@alice:example.org'),null);
});
