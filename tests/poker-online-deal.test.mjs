import test from 'node:test';
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import * as mentalPoker from '../games/php-poker/browser/mental-poker-source.mjs';
import {OnlineDeal,ONLINE_ACTION_TYPES,KEPT_TABLES,evaluateHand,readOnlyStorage} from '../games/php-poker/browser/online-deal.mjs';
import {
	acquireTableLock,decorateOnlineView,freezeOnlineDispute,matrixPlayerName,pendingPokerView,pokerCommandBatch,sheetCell
	,tableLockName,tableMenuShortcut
} from '../games/php-poker/browser/game.mjs';

test('table-menu shortcuts follow the physical key, including macOS Option combinations',()=>{
	assert.equal(tableMenuShortcut({altKey:true,code:'KeyG',key:'g'}),'alt+g');
	assert.equal(tableMenuShortcut({altKey:true,code:'KeyP',key:'p'}),'alt+p');
	assert.equal(tableMenuShortcut({altKey:true,code:'KeyG',key:'©'}),'alt+g','macOS Option+G');
	assert.equal(tableMenuShortcut({altKey:true,code:'KeyP',key:'π'}),'alt+p','macOS Option+P');
	assert.equal(tableMenuShortcut({altKey:true,key:'G'}),'alt+g','events without a code fall back to the key');
	assert.equal(tableMenuShortcut({altKey:true,code:'KeyU',key:'g'}),'alt+g','a Dvorak G sits where QWERTY has U');
	assert.equal(tableMenuShortcut({altKey:true,code:'KeyG',key:'i'}),null,'a Dvorak I sits where QWERTY has G');
	assert.equal(tableMenuShortcut({altKey:false,code:'KeyG',key:'g'}),null);
	assert.equal(tableMenuShortcut({altKey:true,ctrlKey:true,code:'KeyG',key:'g'}),null,'AltGr types characters');
	assert.equal(tableMenuShortcut({altKey:true,metaKey:true,code:'KeyP',key:'p'}),null);
});

test('both physical jokers map to their own card-sheet cells',()=>{
	assert.equal(sheetCell(52,[2,3,1,0]),52);
	assert.equal(sheetCell(53,[2,3,1,0]),53);
});

test('PHP input intents cross the browser bridge before online routing',async()=>{
	const clicked={type:'bet',amount:2,generation:1,revision:12};
	const injected={type:'menu'};
	assert.deepEqual(pokerCommandBatch(JSON.stringify([clicked]),[injected]),[clicked,injected]);
	assert.deepEqual(pokerCommandBatch('',[]),[]);
	assert.throws(()=>pokerCommandBatch('{}',[]),/commands must be a list/);
	const application=await readFile(new URL('../games/php-poker/application.php',import.meta.url),'utf8');
	assert.match(application,/\$commands\(json_encode\(\$intents,/);
});

test('unconfirmed room actions expose state changes without exposing the next turn',()=>{
	const confirmed={revision:13,turn:1,pot:5,message:'Any hand may open.',legalActions:{fold:true,call:true,callAmount:2}};
	const pending=pendingPokerView(confirmed);
	assert.equal(pending.revision,13);
	assert.equal(pending.pot,5);
	assert.deepEqual(pending.legalActions,{});
	assert.equal(pending.waitingForConfirmation,true);
	assert.match(pending.message,/both players/);
	assert.deepEqual(confirmed.legalActions,{fold:true,call:true,callAmount:2});
});

test('a rejected online transcript freezes locally without resigning',()=>{
	const calls=[];
	const lobby={
		dispute:reason=>calls.push(['dispute',reason])
		, resign:reason=>calls.push(['resign',reason])
	};
	assert.equal(freezeOnlineDispute(lobby,new Error('Bad shuffle proof')),'Bad shuffle proof');
	assert.deepEqual(calls,[['dispute','Bad shuffle proof']]);
});

test('online player presentation uses Matrix names, avatar keys and neutral fallbacks',()=>{
	const view={mode:'online',players:[
		{name:'You',portrait:'you',isHuman:true},{name:'@bob:example.org',portrait:'ada',isHuman:false}
	]};
	const decorated=decorateOnlineView(view,{
		players:['@alice:example.org','@bob:example.org'],localPlayer:'@alice:example.org'
		,profiles:new Map([['@bob:example.org',{displayName:'Bobby'}]])
		,avatars:new Map([['@alice:example.org','matrix-local']])
	});
	assert.equal(decorated.players[0].name,'You');
	assert.equal(decorated.players[0].portrait,'matrix-local');
	assert.equal(decorated.players[1].name,'Bobby');
	assert.equal(decorated.players[1].portrait,'');
	assert.equal(view.players[1].portrait,'ada');
	assert.equal(matrixPlayerName('@carol:example.org'),'carol');
});

const storage=()=>{
	const values=new Map;
	return {getItem:key=>values.get(key)??null,setItem:(key,value)=>values.set(key,value),removeItem:key=>values.delete(key)};
};

test('the default shuffle proof fits four bounded room actions and advances packet by packet',async()=>{
	const memory=storage(),options={
		api:mentalPoker,root:'$packets',sessionId:'packet-session',players:['alice','bob']
		,localPlayer:'alice',breaker:'alice',storage:memory
	},deal=new OnlineDeal(options);
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
	const verifier=new OnlineDeal({...options,localPlayer:'bob',storage:storage()});
	const before=verifier.publicState(),malformed=structuredClone(first);
	malformed.records.push({type:'unknown-proof-record',v:1,id:'0'.repeat(64)});
	await assert.rejects(verifier.apply({sequence:1,actor:'alice',pre_hash:preHash,action:malformed}),/proof|chunk/i);
	assert.deepEqual(verifier.publicState(),before,'a rejected packet leaves no partial deck or proof state');
	const downgraded=structuredClone(first);
	downgraded.records.find(record=>record.type==='shuffle-proof-header').rounds=16;
	await assert.rejects(verifier.apply({sequence:1,actor:'alice',pre_hash:preHash,action:downgraded}),/round count/);
	assert.deepEqual(verifier.publicState(),before);
	const envelope={sequence:1,actor:'alice',pre_hash:preHash,action:first};
	await deal.apply(envelope);
	assert.deepEqual(deal.nextAction(),actions[1]);
	const restored=new OnlineDeal(options);await restored.replay([envelope]);
	assert.deepEqual(restored.nextAction(),actions[1]);
});

test('replay keeps a saved shuffle plan for the future hand',()=>{
	const memory=storage(),options={
		api:mentalPoker,root:'$future-plan',sessionId:'future-plan',players:['alice','bob']
		,localPlayer:'alice',breaker:'alice',rounds:16,storage:memory
	};
	const first=new OnlineDeal(options),plan={hand:2,shuffleIndex:0,actor:'alice',actions:[{type:'shuffle-packet',records:[]}]};
	first.saved.plan=plan;first.saveSaved();
	const restored=new OnlineDeal(options);
	assert.deepEqual(restored.saved.plan,plan);
});

test('the browser evaluator matches high-poker category and wheel rules',()=>{
	assert.equal(evaluateHand([8,9,10,11,12]).name,'Royal flush');
	assert.equal(evaluateHand([12,0,14,28,42]).name,'Straight');
	assert.ok(evaluateHand([0,13,1,14,2]).score>evaluateHand([0,13,1,15,3]).score);
});

test('two peers privately deal, bet, draw, reveal, settle and start the next hand',async()=>{
	const stores={alice:storage(),bob:storage()},options={
		api:mentalPoker,root:'$private-table',sessionId:'private-session',players:['alice','bob'],breaker:'alice',rounds:16
	};
	const peers={
		alice:new OnlineDeal({...options,localPlayer:'alice',storage:stores.alice})
		,bob:new OnlineDeal({...options,localPlayer:'bob',storage:stores.bob})
	};
	assert.equal(await peers.alice.hash(),await peers.bob.hash());
	const history=[],boundaries=[];
	const advance=async action=>{
		const actor=peers.alice.currentActor();action??=peers[actor].nextAction();
		assert.ok(action,`${actor} supplies the next action`);
		assert.ok(ONLINE_ACTION_TYPES.includes(action.type));
		assert.ok(Buffer.byteLength(JSON.stringify(action))<49152,`${action.type} fits one room action`);
		if(peers[actor].settlesAction(action))boundaries.push({sequence:history.length+1,type:action.type});
		const envelope={sequence:history.length+1,actor,pre_hash:await peers.alice.hash(),action};
		const [left,right]=await Promise.all([peers.alice.apply(envelope),peers.bob.apply(envelope)]);
		assert.deepEqual(left,right);assert.equal(left.hash,await peers.alice.hash());history.push(structuredClone(envelope));
		return action;
	};
	while(['shuffle','deal'].includes(peers.alice.publicState().stage))
	{
		await advance();
	}
	const alice=peers.alice.privateHand(),bob=peers.bob.privateHand();
	assert.equal(alice.length,5);assert.equal(bob.length,5);
	assert.equal(new Set([...alice,...bob]).size,10);
	assert.deepEqual(boundaries.map(value=>value.type),['shuffle-packet','shuffle-packet','card-layers']);
	assert.deepEqual(peers.alice.view().players.find(player=>player.isHuman).cards,alice);
	assert.deepEqual(peers.bob.view().players.find(player=>player.isHuman).cards,bob);
	assert.ok(peers.alice.publicState().allocations.every(card=>!('id' in card)));
	assert.ok(!JSON.stringify(history).includes(JSON.stringify(alice)));
	assert.equal(peers.alice.publicState().stage,'betting');

	peers.bob.command({type:'bet',amount:2,revision:history.length,generation:1});await advance();
	peers.alice.command({type:'call',revision:history.length,generation:1});await advance();
	assert.equal(peers.alice.publicState().stage,'draw');
	peers.bob.command({type:'draw',cards:bob.slice(0,2),revision:history.length,generation:1});
	assert.equal((await advance()).type,'draw-choice');
	assert.equal((await advance()).type,'card-layers');
	assert.equal(peers.bob.privateHand().length,5);
	assert.equal(new Set([...peers.alice.privateHand(),...peers.bob.privateHand()]).size,10);
	peers.alice.command({type:'draw',cards:[],revision:history.length,generation:1});await advance();
	assert.equal(peers.alice.publicState().stage,'final-betting');
	peers.bob.command({type:'check',revision:history.length,generation:1});await advance();
	peers.alice.command({type:'check',revision:history.length,generation:1});await advance();
	assert.equal(peers.alice.publicState().stage,'reveal');
	const firstRevealer=peers.alice.currentActor(),firstReveal=peers[firstRevealer].nextAction();
	assert.equal(firstReveal.type,'reveal-cards');
	assert.equal(peers[firstRevealer].settlesAction(firstReveal),false);
	await advance(firstReveal);
	const finalRevealer=peers.alice.currentActor(),finalReveal=peers[finalRevealer].nextAction();
	assert.equal(finalReveal.type,'reveal-cards');
	assert.equal(peers[finalRevealer].settlesAction(finalReveal),true);
	await advance(finalReveal);
	assert.equal(peers.alice.publicState().stage,'showdown');
	assert.equal(peers.alice.publicState().pot,0);
	assert.equal(peers.alice.publicState().awards.reduce((sum,award)=>sum+award.amount,0),5);
	assert.equal(peers.alice.view().players[1].cards.length,5);
	assert.doesNotThrow(()=>evaluateHand(peers.alice.view().players[1].cards));
	assert.equal(peers.alice.saved.keys?.[1],undefined);
	assert.equal(peers.bob.saved.keys?.[1],undefined);
	assert.equal(Object.keys(peers.alice.saved.cards?.[1]??{}).length,5);
	assert.equal(Object.keys(peers.bob.saved.cards?.[1]??{}).length,7);
	const showdownRestore=new OnlineDeal({...options,localPlayer:'alice',storage:stores.alice});
	assert.equal(await showdownRestore.replay(history),await peers.alice.hash());
	assert.deepEqual(showdownRestore.privateHand(),peers.alice.privateHand());

	peers.alice.command({type:'next',revision:history.length,generation:1});await advance();
	assert.equal(peers.alice.publicState().stage,'shuffle');
	assert.equal(peers.alice.publicState().handNumber,2);
	assert.equal(peers.alice.currentActor(),'alice');

	const restored=new OnlineDeal({...options,localPlayer:'alice',storage:stores.alice});
	assert.equal(await restored.replay(history),await peers.alice.hash());
	assert.deepEqual(restored.privateHand(),[]);
});

test('folding settles uncontested without publishing either hand',async()=>{
	const stores={alice:storage(),bob:storage()},options={api:mentalPoker,root:'$fold',sessionId:'fold-session',players:['alice','bob'],breaker:'alice',rounds:16};
	const peers={alice:new OnlineDeal({...options,localPlayer:'alice',storage:stores.alice}),bob:new OnlineDeal({...options,localPlayer:'bob',storage:stores.bob})};
	let sequence=0;const history=[];
	const advance=async()=>{
		const actor=peers.alice.currentActor(),action=peers[actor].nextAction(),envelope={sequence:++sequence,actor,pre_hash:await peers.alice.hash(),action};
		await Promise.all([peers.alice.apply(envelope),peers.bob.apply(envelope)]);history.push(envelope);
	};
	while(['shuffle','deal'].includes(peers.alice.publicState().stage))await advance();
	peers.bob.command({type:'bet',amount:2});await advance();peers.alice.command({type:'fold'});await advance();
	assert.equal(peers.alice.publicState().stage,'showdown');
	assert.equal(peers.alice.publicState().reveal,false);
	assert.ok(history.every(envelope=>envelope.action.type!=='reveal-cards'));
	assert.ok(peers.alice.view().players[1].cards.every(card=>card===null));
});

test('all-in seats leave betting queues while unmatched raises are refunded',()=>{
	for(const stage of ['betting','final-betting'])
	{
		const deal=new OnlineDeal({
			api:mentalPoker,root:`$all-in-${stage}`,sessionId:`all-in-${stage}`
			,players:['alice','bob'],localPlayer:'alice',breaker:'alice',rounds:16,storage:storage()
		});
		const state=deal.state;state.stage=stage;state.opener='alice';state.highest=4;state.raises=1;state.pot=6;
		state.turnQueue=['alice'];
		Object.assign(deal.bankroll('alice'),{stack:498,bet:2,totalBet:2,folded:false});
		Object.assign(deal.bankroll('bob'),{stack:0,bet:4,totalBet:4,folded:false});

		deal.applyPokerAction('alice',{type:'poker-action',move:'bet',amount:6});

		assert.equal(deal.bankroll('alice').bet,4);
		assert.equal(deal.bankroll('alice').stack,496);
		assert.equal(state.pot,8);
		assert.equal(deal.bankroll('bob').folded,false);
		assert.equal(state.stage,stage==='betting'?'draw':'reveal');
		assert.ok(state.turnQueue.includes('bob'),`${stage} keeps the all-in hand in later phases`);
	}
});

test('a heads-up all-in skips betting but still draws and reveals',()=>{
	const deal=new OnlineDeal({
		api:mentalPoker,root:'$all-in-rounds',sessionId:'all-in-rounds'
		,players:['alice','bob'],localPlayer:'alice',breaker:'alice',rounds:16,storage:storage()
	});
	deal.bankroll('alice').stack=0;deal.state.opener='bob';deal.beginBetting();
	assert.equal(deal.state.stage,'draw');
	assert.ok(deal.state.turnQueue.includes('alice'));
	deal.beginFinalBetting();
	assert.equal(deal.state.stage,'reveal');
	assert.ok(deal.state.turnQueue.includes('alice'));
});

test('the public reducer rejects wrong actors, parents and altered layer proofs',async()=>{
	const options={api:mentalPoker,root:'$reject',sessionId:'reject-session',players:['alice','bob'],breaker:'alice',rounds:16};
	const alice=new OnlineDeal({...options,localPlayer:'alice',storage:storage()});
	const action=alice.nextAction(),hash=await alice.hash();
	await assert.rejects(alice.apply({sequence:1,actor:'bob',pre_hash:hash,action}),/wrong player/);
	await assert.rejects(alice.apply({sequence:1,actor:'alice',action}),/parent hash/);
	await assert.rejects(alice.apply({sequence:1,actor:'alice',pre_hash:'wrong',action}),/parent hash/);
	const altered=structuredClone(action),deck=altered.records.find(record=>record.type==='deck-shuffle').deck;
	deck[1]=deck[2];
	await assert.rejects(alice.apply({sequence:1,actor:'alice',pre_hash:hash,action:altered}),/duplicate|invalid|card/i);
});

/** A Storage-like map with key() and length, as local storage provides. */
const webStorage=({failAfter=Infinity}={})=>{
	const values=new Map;let writes=0;
	return {values,getItem:key=>values.get(key)??null,removeItem:key=>values.delete(key),key:index=>[...values.keys()][index]??null,
		get length(){return values.size;},setItem(key,value){if(++writes>failAfter)throw new Error('QuotaExceededError');values.set(key,String(value));}};
};
const tableOptions=(root,store,extra={})=>({api:mentalPoker,root,sessionId:root+'-session',players:['alice','bob']
	,localPlayer:'alice',breaker:'alice',rounds:16,storage:store,legacyStorage:null,...extra});

test('the online record moves from session storage to local storage on first load',()=>{
	const local=webStorage(),legacy=webStorage();
	const first=new OnlineDeal(tableOptions('$moved',legacy));
	first.makeShufflePlan();
	const key=first.keyName,record=legacy.getItem(key);
	assert.ok(record,'an earlier build wrote the record to session storage');
	const reopened=new OnlineDeal(tableOptions('$moved',local,{legacyStorage:legacy}));
	assert.equal(local.getItem(key),record,'the record now lives in local storage');
	assert.equal(legacy.getItem(key),null,'and leaves session storage');
	assert.deepEqual(reopened.saved.keys,first.saved.keys,'the live hand keeps its layer key');
	assert.deepEqual(reopened.makeShufflePlan(),first.saved.plan,'and resumes the same unfinished proof');
	// A full local storage keeps reading the old copy instead of losing the keys.
	const full=webStorage({failAfter:0}),kept=webStorage();
	const old=new OnlineDeal(tableOptions('$full',kept));old.makeShufflePlan();
	const stuck=new OnlineDeal(tableOptions('$full',full,{legacyStorage:kept}));
	assert.deepEqual(stuck.saved.keys,old.saved.keys);
	assert.ok(kept.getItem(stuck.keyName),'the session copy stays until local storage accepts it');
});

test('local storage keeps the newest tables and never prunes the table in play',()=>{
	const store=webStorage();let clock=0;
	const tables=[];
	for(let index=0;index<KEPT_TABLES+3;index++)
	{
		const table=new OnlineDeal(tableOptions(`$table-${index}`,store,{now:()=>++clock}));
		table.makeShufflePlan();tables.push(table);
	}
	const kept=tables.filter(table=>store.getItem(table.keyName)!==null);
	assert.equal(kept.length,KEPT_TABLES,'twenty tables keep their records');
	assert.deepEqual(kept.map(table=>table.root),tables.slice(-KEPT_TABLES).map(table=>table.root),'the oldest go first');
	// Reopening an old table refreshes it, so the next prune drops a different one.
	const revisit=tables.at(-KEPT_TABLES);
	new OnlineDeal(tableOptions(revisit.root,store,{now:()=>++clock})).saveSaved();
	new OnlineDeal(tableOptions('$newest',store,{now:()=>++clock})).saveSaved();
	assert.ok(store.getItem(revisit.keyName),'a table used again is kept');
	assert.equal(store.getItem(tables.at(-KEPT_TABLES+1).keyName),null,'the next oldest is pruned instead');
});

test('a tab watching a table reads its record but never writes it',async()=>{
	const store=webStorage(),player=new OnlineDeal(tableOptions('$watched',store));
	player.makeShufflePlan();
	const before=new Map(store.values),watcher=new OnlineDeal(tableOptions('$watched',readOnlyStorage(store)));
	assert.deepEqual(watcher.saved.keys,player.saved.keys,'the watcher sees the stored record');
	const action=player.nextAction(),envelope={sequence:1,actor:'alice',pre_hash:await player.hash(),action};
	await watcher.apply(envelope);watcher.saveSaved();
	assert.deepEqual(new Map(store.values),before,'nothing the watcher does changes local storage');
});

test('one tab holds a table seat at a time',async()=>{
	const held=new Set,waiting=[];
	const locks={request(name,options,callback){
		if(held.has(name)){assert.equal(options.ifAvailable,true);return Promise.resolve(callback(null));}
		held.add(name);
		return Promise.resolve(callback({name})).then(()=>held.delete(name));
	}};
	const name=tableLockName('$root','@alice:example.org');
	assert.equal(name,'php-poker:table:$root:@alice:example.org');
	const release=await acquireTableLock(name,locks);
	assert.equal(typeof release,'function','the first tab plays');
	assert.equal(await acquireTableLock(name,locks),null,'a second tab only watches');
	assert.equal(typeof await acquireTableLock(tableLockName('$other','@alice:example.org'),locks),'function','other tables are separate');
	release();await new Promise(resolve=>setTimeout(resolve,0));
	assert.equal(typeof await acquireTableLock(name,locks),'function','the seat frees when the playing tab lets go');
	assert.equal(typeof await acquireTableLock(name,undefined),'function','without Web Locks a tab plays as before');
});

