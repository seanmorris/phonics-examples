import test from 'node:test';
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import * as mentalPoker from '../games/php-poker/browser/mental-poker-source.mjs';
import {OnlineDeal,ONLINE_RULES,validateOnlineSettings} from '../games/php-poker/browser/online-deal.mjs';

// These tests enumerate public rules without shuffling. Cache the immutable corpus deck to avoid repeating
// hash-to-curve for each bankroll split; the two-peer suite separately exercises real shuffles and openings.
const table=(game='draw',settings={})=>{
	let deck;
	const api={...mentalPoker,corpusDeck:corpus=>[...(deck??=mentalPoker.corpusDeck(corpus))]};
	return new OnlineDeal({api,root:'$rules-'+game,sessionId:'rules',players:['alice','bob'],localPlayer:'alice'
		,breaker:'alice',settings:{game,...settings},storage:null,legacyStorage:null});
};

const split=(deal,initial,stacks)=>{
	deal.state=structuredClone(initial);
	deal.state.handNumber=0;deal.state.pot=0;deal.state.history=[];
	deal.state.bankrolls.forEach((seat,index)=>{seat.stack=stacks[index];});
	deal.beginHand(false);
};

const betting=(deal,{bets=[0,0],stacks=[500,500],queue=['alice','bob']}={})=>{
	Object.assign(deal.state,{stage:deal.streetGame?'betting':'final-betting',highest:Math.max(...bets),opener:'alice'
		,pot:bets[0]+bets[1],turnQueue:queue,raises:0,raiseCounts:[0,0]});
	if(deal.streetGame)deal.state.street=deal.rules.streets.length-1;
	deal.state.bankrolls.forEach((seat,index)=>Object.assign(seat,{bet:bets[index],totalBet:bets[index],stack:stacks[index]}));
};

const apply=(deal,action,extra={})=>deal.hash().then(pre_hash=>deal.apply({sequence:deal.state.sequence+1
	,actor:deal.currentActor(),pre_hash,action,...extra}));

test('both the lobby and reducer pin the corrected rules and reject unsupported multiplayer profiles',async()=>{
	const application=await readFile(new URL('../games/php-poker/application.php',import.meta.url),'utf8');
	assert.ok(application.includes(`'rules'=>'${ONLINE_RULES}'`));
	assert.notEqual(ONLINE_RULES,'five-card-draw-anything-v5','older rules cannot negotiate the corrected settlement');
	const deal=table();
	for(const players of [['alice'],['alice','alice'],['alice','bob','carol']])
	assert.throws(()=>new OnlineDeal({api:mentalPoker,root:'$invalid',sessionId:'rules',players,breaker:'alice'
		,localPlayer:'alice',storage:null,legacyStorage:null}),/exactly two distinct/);
	for(const settings of [{variant:'jackpots'},{loans:true},{adaptation:true},{startingStack:10000},{game:'omaha'}])
	assert.throws(()=>validateOnlineSettings(settings));
	assert.equal(deal.state.bankrolls.reduce((sum,seat)=>sum+seat.stack,deal.state.pot),1000);
});

for(const game of ['stud5','stud7'])
test(`${game} antes conserve every positive 1000-chip bankroll split`,()=>{
	for(const ante of [0,1,2,100])
	{
		const deal=table(game,{ante}),initial=deal.publicState();
		for(let left=1;left<1000;left++)
		{
			const stacks=[left,1000-left],matched=Math.min(ante,...stacks);
			split(deal,initial,stacks);
			assert.deepEqual(deal.state.bankrolls.map(seat=>seat.stack),stacks.map(stack=>stack-matched));
			assert.deepEqual(deal.state.bankrolls.map(seat=>seat.totalBet),[matched,matched]);
			assert.deepEqual(deal.state.bankrolls.map(seat=>seat.bet),[0,0],'antes do not satisfy a live bring-in');
			assert.equal(deal.state.pot,2*matched);
			deal.assertState();
		}
	}
});

for(const game of ['draw','holdem','stud5','stud7'])
test(`${game} rejects raises against an all-in but preserves the call-or-fold decision`,async()=>{
	const deal=table(game);
	betting(deal,{bets:[1,2],stacks:[997,0],queue:['alice']});
	assert.equal(deal.legalActionsFor('alice').callAmount,1);
	assert.equal(deal.legalActionsFor('alice').fold,true);
	assert.equal(deal.legalActionsFor('alice').bet,false);
	const before=deal.publicState();
	await assert.rejects(apply(deal,{type:'poker-action',move:'bet',amount:4}),/unavailable/);
	assert.deepEqual(deal.publicState(),before,'a hostile client cannot add an unanswerable raise');
	await apply(deal,{type:'poker-action',move:'call'});
	assert.equal(deal.state.stage,'reveal');
	assert.equal(deal.state.pot,4);
	assert.ok(deal.state.turnQueue.includes('bob'),'the all-in hand must still open its cards');
});

test('short calls and exact all-ins match both contributions across every online game and seat',async()=>{
	for(const game of ['draw','holdem','stud5','stud7'])
	{
		const deal=table(game),initial=deal.publicState();
		for(const short of [0,1])for(const bet of [0,1,2,20])for(const due of [1,2,5,100])for(const chips of [1,2,5,100])
		{
			if(chips>due)continue;
			deal.state=structuredClone(initial);
			const bets=short===0?[bet,bet+due]:[bet+due,bet];
			const stacks=[0,0];stacks[short]=chips;stacks[1-short]=1000-bets[0]-bets[1]-chips;
			betting(deal,{bets,stacks,queue:[deal.players[short],deal.players[1-short]]});
			await apply(deal,{type:'poker-action',move:'call'});
			assert.equal(deal.state.stage,'reveal',`${game}: no extra turn after a matched or short all-in call`);
			assert.equal(deal.state.pot,2*(bet+chips));
			assert.deepEqual(deal.state.bankrolls.map(seat=>seat.bet),[bet+chips,bet+chips]);
			assert.equal(deal.state.bankrolls[short].stack,0);
			assert.equal(deal.state.bankrolls[1-short].stack,1000-deal.state.pot);
			deal.assertState();
		}
	}
});

test('a funded big blind retains its option when the caller still has chips',async()=>{
	const deal=table('holdem');
	betting(deal,{bets:[1,2],stacks:[499,498]});
	await apply(deal,{type:'poker-action',move:'call'});
	assert.equal(deal.state.stage,'betting');
	assert.equal(deal.currentActor(),'bob');
	assert.equal(deal.legalActionsFor('bob').check,true);
	assert.equal(deal.legalActionsFor('bob').bet,true);
});

test('malformed amounts and unsolicited state changes are rejected atomically',async()=>{
	const deal=table('holdem');betting(deal);
	const before=deal.publicState(),hash=await deal.hash();
	const actions=[...[undefined,null,-1,0,1,1.5,'2',NaN,Infinity,21,Number.MAX_SAFE_INTEGER].map(amount=>({type:'poker-action',move:'bet',amount}))
		,...['loan','load','settings','next','draw','call'].map(move=>({type:'poker-action',move}))
		,{type:'snapshot',state:{pot:1000}},{type:'draw-choice',discards:[]},{type:'reveal-cards',items:[]}];
	for(const action of actions)
	{
		await assert.rejects(apply(deal,action));
		assert.deepEqual(deal.publicState(),before);
		assert.equal(await deal.hash(),hash);
	}
	await assert.rejects(apply(deal,{type:'poker-action',move:'check'},{actor:'bob'}),/wrong player/);
	await assert.rejects(apply(deal,{type:'poker-action',move:'check'},{sequence:2}),/out of sequence/);
	await assert.rejects(apply(deal,{type:'poker-action',move:'check'},{pre_hash:'0'.repeat(64)}),/parent hash/);
	await apply(deal,{type:'poker-action',move:'bet',amount:2});
	assert.equal(deal.state.sequence,1,'rejections did not consume a turn');
});

test('chip and turn invariants fail closed and roll back a faulty transition',async()=>{
	const deal=table('holdem');betting(deal);
	const before=deal.publicState(),reduce=deal.applyPokerAction;
	for(const corrupt of [
		state=>{state.bankrolls[0].stack++;},state=>{state.pot++;state.bankrolls[0].stack--;}
		,state=>{state.bankrolls[0].stack-=.5;state.bankrolls[1].stack+=.5;}
		,state=>{state.turnQueue=[];},state=>{state.turnQueue=['bob','bob'];}
		,state=>{
			Object.assign(state.bankrolls[0],{stack:499,bet:1,totalBet:1});
			state.pot=1;state.highest=1;state.turnQueue=['alice']; // Bob still owes a call and cannot be skipped.
		}
	])
	{
		deal.applyPokerAction=function(actor,action){reduce.call(this,actor,action);corrupt(this.state);};
		await assert.rejects(apply(deal,{type:'poker-action',move:'check'}),/chips|pot|turn/);
		assert.deepEqual(deal.publicState(),before);
	}
	deal.applyPokerAction=reduce;
	await apply(deal,{type:'poker-action',move:'check'});
	assert.equal(deal.currentActor(),'bob');
});

test('a short Stud winner gets only the matched pot and a mismatched contested pot is rejected',()=>{
	for(const game of ['stud5','stud7'])
	{
		const deal=table(game);
		betting(deal,{bets:[0,0],stacks:[0,998]});
		deal.state.pot=2;deal.state.bankrolls.forEach(seat=>{seat.totalBet=1;});
		deal.state.revealed=[[8,9,10,11,12],[0,14,28,42,5]]; // A royal flush beats the other hand.
		deal.settle(true);
		assert.deepEqual(deal.state.bankrolls.map(seat=>seat.stack),[2,998]);
		assert.equal(deal.state.awards[0].amount,2);
		deal.assertState();
		deal.state.bankrolls[1].totalBet=100;
		assert.throws(()=>deal.settle(true),/unmatched contributions/);
	}
});

test('ties split every chip with the odd chip going left of the dealer',()=>{
	for(const dealer of [0,1])
	{
		const deal=table('draw');
		betting(deal,{bets:[3,3],stacks:[496,497]});
		deal.state.pot=7;deal.state.bankrolls[0].totalBet=4;deal.state.dealer=dealer;
		deal.state.revealed=[[8,9,10,11,12],[21,22,23,24,25]];
		deal.settle(true);
		assert.deepEqual(deal.state.awards.map(({player,amount})=>({player,amount})),[
			{player:deal.players[1-dealer],amount:4},{player:deal.players[dealer],amount:3}
		]);
		deal.assertState();
	}
});

test('draw keeps its dead dealer ante and all-pass carryover separate from matched bets',async()=>{
	const deal=table('draw',{ante:100});
	deal.state.stage='betting';deal.state.turnQueue=['bob','alice'];
	await apply(deal,{type:'poker-action',move:'pass'});
	await apply(deal,{type:'poker-action',move:'pass'});
	assert.equal(deal.state.stage,'redeal');
	assert.equal(deal.state.pot,100);
	await apply(deal,{type:'poker-action',move:'next'});
	assert.equal(deal.state.pot,200);
	assert.deepEqual(deal.state.bankrolls.map(seat=>seat.stack),[400,400]);
	deal.assertState();
});
