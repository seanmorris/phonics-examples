import test from 'node:test';
import assert from 'node:assert/strict';
import * as mentalPoker from '../games/php-poker/browser/mental-poker-source.mjs';
import {OnlineDeal,ONLINE_ACTION_TYPES} from '../games/php-poker/browser/online-deal.mjs';

const storage=()=>{
	const values=new Map;
	return {getItem:key=>values.get(key)??null,setItem:(key,value)=>values.set(key,value),removeItem:key=>values.delete(key)};
};

test('the default shuffle proof fits four bounded room actions and advances packet by packet',async()=>{
	const deal=new OnlineDeal({
		api:mentalPoker,root:'$packets',sessionId:'packet-session',players:['alice','bob']
		,localPlayer:'alice',breaker:'alice',storage:storage()
	});
	const {actions}=deal.makeShufflePlan();
	assert.equal(actions.length,4);
	for(const [index,action] of actions.entries())
	{
		assert.equal(action.type,'shuffle-packet');
		assert.ok(Buffer.byteLength(JSON.stringify(action))<49152);
		assert.equal(action.records.filter(record=>record.type==='shuffle-proof-commitments').length,1);
		assert.equal(action.records.filter(record=>record.type==='shuffle-proof-responses').length,1);
		assert.equal(action.records.some(record=>record.type==='deck-shuffle'),index===0);
		assert.equal(action.records.some(record=>record.type==='shuffle-proof-tail'),index===actions.length-1);
	}
	const first=deal.nextAction(),preHash=await deal.hash();
	assert.deepEqual(first,actions[0]);
	await deal.apply({sequence:1,actor:'alice',pre_hash:preHash,action:first});
	assert.deepEqual(deal.nextAction(),actions[1]);
});

test('two peers jointly shuffle and privately deal five unique cards apiece',async()=>{
	const stores={alice:storage(),bob:storage()},options={
		api:mentalPoker,root:'$private-table',sessionId:'private-session',players:['alice','bob'],breaker:'alice',rounds:16
	};
	const peers={
		alice:new OnlineDeal({...options,localPlayer:'alice',storage:stores.alice})
		,bob:new OnlineDeal({...options,localPlayer:'bob',storage:stores.bob})
	};
	assert.equal(await peers.alice.hash(),await peers.bob.hash());
	const history=[],boundaries=[];
	while(peers.alice.publicState().stage!=='ready')
	{
		const actor=peers.alice.currentActor(),action=peers[actor].nextAction();
		assert.ok(action,`${actor} supplies the next action`);
		assert.ok(ONLINE_ACTION_TYPES.includes(action.type));
		assert.ok(Buffer.byteLength(JSON.stringify(action))<49152,`${action.type} fits one room action`);
		if(peers[actor].settlesAction(action))boundaries.push({sequence:history.length+1,type:action.type});
		const envelope={sequence:history.length+1,actor,pre_hash:await peers.alice.hash(),action};
		const [left,right]=await Promise.all([peers.alice.apply(envelope),peers.bob.apply(envelope)]);
		assert.deepEqual(left,right);
		assert.equal(left.hash,await peers.alice.hash());
		history.push(structuredClone(envelope));
	}
	const alice=peers.alice.privateHand(),bob=peers.bob.privateHand();
	assert.equal(alice.length,5);assert.equal(bob.length,5);
	assert.equal(new Set([...alice,...bob]).size,10);
	assert.deepEqual(boundaries.map(value=>value.type),['shuffle-packet','shuffle-packet','card-layers']);
	assert.deepEqual(peers.alice.view().players.find(player=>player.isHuman).cards,alice);
	assert.deepEqual(peers.bob.view().players.find(player=>player.isHuman).cards,bob);
	assert.ok(peers.alice.publicState().allocations.every(card=>!('id' in card)));
	assert.ok(!JSON.stringify(history).includes(JSON.stringify(alice)));

	const restored=new OnlineDeal({...options,localPlayer:'alice',storage:stores.alice});
	assert.equal(await restored.replay(history),await peers.alice.hash());
	assert.deepEqual(restored.privateHand(),alice);
});

test('the public reducer rejects wrong actors, parents and altered layer proofs',async()=>{
	const options={api:mentalPoker,root:'$reject',sessionId:'reject-session',players:['alice','bob'],breaker:'alice',rounds:16};
	const alice=new OnlineDeal({...options,localPlayer:'alice',storage:storage()});
	const action=alice.nextAction(),hash=await alice.hash();
	await assert.rejects(alice.apply({sequence:1,actor:'bob',pre_hash:hash,action}),/wrong player/);
	await assert.rejects(alice.apply({sequence:1,actor:'alice',pre_hash:'wrong',action}),/parent hash/);
	const altered=structuredClone(action),deck=altered.records.find(record=>record.type==='deck-shuffle').deck;
	deck[1]=deck[2];
	await assert.rejects(alice.apply({sequence:1,actor:'alice',pre_hash:hash,action:altered}),/duplicate|invalid|card/i);
});
