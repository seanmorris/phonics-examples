import test from 'node:test';
import assert from 'node:assert/strict';
import {execFileSync} from 'node:child_process';
import {fileURLToPath} from 'node:url';
import * as mentalPoker from '../games/php-poker/browser/mental-poker-source.mjs';
import {
	DEFAULT_ONLINE_SETTINGS,OnlineDeal,cardLabel,describeOnlineSettings,evaluateHand,limitsLabel,onlineDeck,onlineSettingsForm
	,onlineWildCards,validateOnlineSettings,wildSelectionLabel
} from '../games/php-poker/browser/online-deal.mjs';

const root=fileURLToPath(new URL('../',import.meta.url));
/** Run a PHP snippet with the poker autoloader; $input holds the decoded JSON sent on stdin. */
const php=(code,input)=>JSON.parse(execFileSync('php',['-r',`declare(strict_types=1); require $argv[1].'/games/php-poker/autoload.php';
$input=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);${code}`,root],{encoding:'utf8',input:JSON.stringify(input)}));
/** Small deterministic generator so failures reproduce. */
const random=seed=>()=>{seed=(seed*1103515245+12345)%2147483648;return seed/2147483648;};
const storage=()=>{const values=new Map;return {getItem:key=>values.get(key)??null,setItem:(key,value)=>values.set(key,value),removeItem:key=>values.delete(key)};};
const PRESETS=['joker','deuces','one-eyed-jacks','mustached-kings','deuces-treys','kings'];

test('the browser evaluator matches Hand::evaluate with wild cards and jokers',()=>{
	const next=random(20261107),cases=[];
	for(let index=0;index<600;index++)
	{
		const presets=PRESETS.filter(()=>next()<.3),custom=next()<.4?[...new Set(Array.from({length:1+Math.floor(next()*6)},()=>Math.floor(next()*54)))].sort((a,b)=>a-b):[];
		const settings=validateOnlineSettings({wild:custom.length?[...presets,'custom']:presets,customWild:custom});
		const deck=onlineDeck(settings),wild=onlineWildCards(settings),hand=[];
		while(hand.length<5){const card=deck[Math.floor(next()*deck.length)];if(!hand.includes(card))hand.push(card);}
		cases.push({hand,wild});
	}
	const expected=php('echo json_encode(array_map(static fn(array $case): array => PhpPoker\\Hand::evaluate($case["hand"],$case["wild"]),$input));',cases);
	cases.forEach(({hand,wild},index)=>{
		const actual=evaluateHand(hand,wild),reference=expected[index];
		assert.deepEqual({category:actual.category,ranks:actual.ranks,score:actual.score,name:actual.name}
			,{category:reference.category,ranks:reference.ranks,score:reference.score,name:reference.name},`hand ${JSON.stringify(hand)} wild ${JSON.stringify(wild)}`);
	});
	assert.ok(cases.some(({hand,wild})=>evaluateHand(hand,wild).category===9),'some hands reach five of a kind');
});

test('online settings accept and reject exactly what the offline Profile does',()=>{
	const candidates=[
		{},{ante:0},{ante:100},{ante:101},{minimum:0},{minimum:5,maximum:4},{minimum:30},{maximum:100},{maximum:101}
		,{finalMinimum:3},{finalMinimum:7,finalMaximum:5},{finalMaximum:100},{raiseLimit:0},{raiseLimit:11},{playerRaiseLimit:2}
		,{raiseLimit:2,playerRaiseLimit:10},{playerRaiseLimit:11},{wild:['kings','deuces']},{wild:'none'},{wild:['none','deuces']}
		,{wild:['custom'],customWild:[0,52]},{wild:['custom'],customWild:[52,0]},{customWild:[3]},{wild:['bogus']}
		,{wild:['custom'],customWild:[0,0]},{wild:['joker','custom'],customWild:[53]},{ante:1.5},{ante:'1'}
	];
	const verdicts=php(`echo json_encode(array_map(static function(array $c): bool {
		try { new PhpPoker\\Profile('anything',$c['ante']??1,$c['minimum']??2,$c['maximum']??20,$c['raiseLimit']??3,$c['wild']??[],$c['customWild']??[],
			$c['finalMinimum']??null,$c['finalMaximum']??null,$c['playerRaiseLimit']??null); return true; }
		catch(\\Throwable) { return false; }
	},$input));`,candidates);
	candidates.forEach((candidate,index)=>{
		let accepted=true;try{validateOnlineSettings(candidate);}catch{accepted=false;}
		assert.equal(accepted,verdicts[index],`settings ${JSON.stringify(candidate)}`);
	});
	assert.throws(()=>validateOnlineSettings({variant:'jackpots'}),/Anything Opens/,'Jackpots is not an online variant');
	assert.throws(()=>validateOnlineSettings({players:4}),/Unknown/);
	assert.deepEqual(validateOnlineSettings({wild:['kings','deuces']}).wild,['deuces','kings'],'presets take the offline order');
	assert.deepEqual(validateOnlineSettings(),DEFAULT_ONLINE_SETTINGS);
});

test('wild ids, decks and wild descriptions match WildCards',()=>{
	const selections=[[[],[]],...PRESETS.map(preset=>[[preset],[]]),[['joker','one-eyed-jacks'],[]],[['custom'],[0,1,2,13,14,15,26,27,28,39,40,41]]
		,[['kings','custom'],[5,12,25,38,51,53]],[['custom'],[3,4,5,6,7,8]]];
	const reference=php(`echo json_encode(array_map(static fn(array $s): array => [
		'ids'=>PhpPoker\\WildCards::ids($s[0],$s[1]),'deck'=>PhpPoker\\WildCards::deck($s[0],$s[1]),'describe'=>PhpPoker\\WildCards::describe($s[0],$s[1])
	],$input));`,selections);
	selections.forEach(([wild,customWild],index)=>{
		const settings={wild,customWild};
		assert.deepEqual(onlineWildCards(settings),reference[index].ids);
		assert.deepEqual(onlineDeck(settings),reference[index].deck);
		const label=describeOnlineSettings(settings),expected=reference[index].describe;
		if(expected==='No wild cards')assert.doesNotMatch(label,/wild/);
		else assert.ok(label.endsWith(expected),`${label} ends with ${expected}`);
	});
	assert.equal(describeOnlineSettings(),'Anything opens · Ante 1 · Bets 2–20 · 3 raises');
	assert.equal(describeOnlineSettings({ante:0,minimum:5,maximum:10,finalMinimum:10,finalMaximum:40,raiseLimit:4,playerRaiseLimit:2,wild:['deuces']})
		,'Anything opens · No ante · Bets 5–10, 10–40 after the draw · 4 raises, 2 each · Deuces wild');
});

const pair=(name,settings,extra={})=>{
	const options={api:mentalPoker,root:`$${name}`,sessionId:`${name}-session`,players:['alice','bob'],breaker:'alice',rounds:16,settings,legacyStorage:null,...extra};
	const peers={alice:new OnlineDeal({...options,localPlayer:'alice',storage:storage()}),bob:new OnlineDeal({...options,localPlayer:'bob',storage:storage()})};
	const history=[];
	const advance=async action=>{
		const actor=peers.alice.currentActor();action??=peers[actor].nextAction();
		const envelope={sequence:history.length+1,actor,pre_hash:await peers.alice.hash(),action};
		const [left,right]=await Promise.all([peers.alice.apply(envelope),peers.bob.apply(envelope)]);
		assert.deepEqual(left,right);history.push(envelope);return action;
	};
	return {peers,history,advance};
};

test('online betting follows the table ante, increments and both raise caps',async()=>{
	const settings={ante:3,minimum:5,maximum:10,finalMinimum:10,finalMaximum:30,raiseLimit:3,playerRaiseLimit:1};
	const {peers,advance}=pair('limits',settings);
	assert.equal(peers.alice.publicState().pot,3,'the dealer antes 3');
	while(['shuffle','deal'].includes(peers.alice.publicState().stage))await advance();
	const first=peers.alice.currentActor(),second=first==='alice'?'bob':'alice';
	let legal=peers[first].legalActionsFor(first);
	assert.deepEqual([legal.minBet,legal.maxBet,legal.betIncrement],[5,10,5],'opening bets run 5 to 10');
	peers[first].command({type:'bet',amount:10});await advance();
	legal=peers[second].legalActionsFor(second);
	assert.deepEqual([legal.minBet,legal.maxBet],[15,20],'a raise adds 5 to 10');
	peers[second].command({type:'bet',amount:15});await advance();
	legal=peers[first].legalActionsFor(first);
	assert.equal(legal.bet,true,'the opener has not raised yet');
	peers[first].command({type:'bet',amount:20});await advance();
	legal=peers[second].legalActionsFor(second);
	assert.equal(legal.bet,false,'one raise each is the per-player cap');
	assert.equal(legal.call,true);
	peers[second].command({type:'call'});await advance();
	while(peers.alice.publicState().stage==='draw'||peers.alice.publicState().drawAllocations?.length)
	{
		const actor=peers.alice.currentActor();
		if(peers.alice.publicState().stage==='draw'&&!peers.alice.publicState().drawAllocations.length)peers[actor].command({type:'draw',cards:[]});
		await advance();
	}
	assert.equal(peers.alice.publicState().stage,'final-betting');
	const opener=peers.alice.currentActor();legal=peers[opener].legalActionsFor(opener);
	assert.deepEqual([legal.minBet,legal.maxBet,legal.betIncrement],[10,30,10],'after the draw bets run 10 to 30');
	assert.deepEqual(peers.alice.view().profile,{version:'five-card-draw-anything-v5',...validateOnlineSettings(settings)},'the view reports the table rules');
});

test('a wild-card table deals jokers, reads private wild hands and scores the showdown with them',async()=>{
	const settings={wild:['joker','deuces']};
	const {peers,advance}=pair('wild',settings);
	assert.equal(peers.alice.deckIds.length,54,'both jokers join the deck');
	assert.equal(peers.alice.view().stockCount,54);
	while(['shuffle','deal'].includes(peers.alice.publicState().stage))await advance();
	const hands=[peers.alice.privateHand(),peers.bob.privateHand()];
	assert.ok(hands.every(hand=>hand.length===5&&hand.every(card=>card>=0&&card<=53)));
	const first=peers.alice.currentActor(),second=first==='alice'?'bob':'alice';
	peers[first].command({type:'bet',amount:2});await advance();peers[second].command({type:'call'});await advance();
	while(peers.alice.publicState().stage==='draw')
	{
		const actor=peers.alice.currentActor();
		if(!peers.alice.publicState().drawAllocations.length)peers[actor].command({type:'draw',cards:[]});
		await advance();
	}
	for(const actor of [peers.alice.currentActor(),peers.alice.currentActor()==='alice'?'bob':'alice'])
	{peers[actor].command({type:'check'});await advance();}
	while(peers.alice.publicState().stage==='reveal')await advance();
	const state=peers.alice.publicState(),wild=onlineWildCards(settings);
	assert.equal(state.stage,'showdown');
	assert.deepEqual(state.revealed,[peers.alice.privateHand(),peers.bob.privateHand()].map(hand=>[...hand]),'openings reveal the dealt faces');
	const scores=state.revealed.map(cards=>evaluateHand(cards,wild).score),best=Math.max(...scores);
	const winners=state.players.filter((player,seat)=>scores[seat]===best);
	assert.deepEqual(state.awards.map(award=>award.player).sort(),winners.sort(),'the pot goes to the best hand counting wild cards');
});

test('browsers that disagree on table settings fail at the first handshake',async()=>{
	const base={api:mentalPoker,root:'$mismatch',sessionId:'mismatch-session',players:['alice','bob'],breaker:'alice',rounds:16,legacyStorage:null};
	const alice=new OnlineDeal({...base,localPlayer:'alice',storage:storage(),settings:{ante:2}});
	const bob=new OnlineDeal({...base,localPlayer:'bob',storage:storage()});
	assert.notEqual(await alice.hash(),await bob.hash(),'different settings give different initial hashes');
	assert.notEqual(alice.context,bob.context);
	const same=new OnlineDeal({...base,localPlayer:'bob',storage:storage(),settings:{ante:2}});
	assert.equal(await alice.hash(),await same.hash());
});

test('the House rules form covers every online setting in the offline names, bounds and card labels',()=>{
	const form=onlineSettingsForm();
	const fields=form.groups.flatMap(group=>group.fields),byName=Object.fromEntries(fields.map(field=>[field.name,field]));
	assert.deepEqual(fields.map(field=>field.name).sort(),Object.keys(DEFAULT_ONLINE_SETTINGS).filter(key=>key!=='variant').sort()
		,'one field per setting except the opening, which is always Anything opens online');
	assert.equal(form.values.variant,'anything','the variant still travels with the chosen settings');
	assert.deepEqual(form.values,{...DEFAULT_ONLINE_SETTINGS,wild:[],customWild:[]});
	assert.deepEqual(form.defaults,form.values);
	assert.ok(!Object.isFrozen(form.values.wild),'the dialog receives plain copies');
	for(const field of fields.filter(field=>field.type==='number'))
	{
		const low={...DEFAULT_ONLINE_SETTINGS,[field.name]:field.min-1},high={...DEFAULT_ONLINE_SETTINGS,[field.name]:field.max+1};
		assert.throws(()=>validateOnlineSettings(low),/must be a whole number from/,`${field.name} below its field`);
		assert.throws(()=>validateOnlineSettings(high),/must be a whole number from/,`${field.name} above its field`);
		assert.equal(field.default,DEFAULT_ONLINE_SETTINGS[field.name]);
	}
	assert.throws(()=>validateOnlineSettings({minimum:5,maximum:4}),/^RangeError: Maximum bet before the draw must be a whole number from 5 to 100$/);
	const reference=php(`echo json_encode(['options'=>PhpPoker\\WildCards::OPTIONS
		,'cards'=>array_map(static fn(int $card): string => PhpPoker\\WildCards::cardLabel($card),range(0,53))]);`,null);
	assert.deepEqual(byName.wild.options.map(option=>[option.value,option.label])
		,Object.entries(reference.options).filter(([id])=>id!=='none'),'wild checkboxes use the offline names and order');
	assert.deepEqual(byName.customWild.options.map(option=>option.label),reference.cards);
	assert.deepEqual(Array.from({length:54},(_,card)=>cardLabel(card)),reference.cards);
	assert.deepEqual(byName.customWild.options.map(option=>option.value),Array.from({length:54},(_,card)=>card));
	assert.deepEqual([...new Set(byName.customWild.options.map(option=>option.group))],['Clubs','Diamonds','Hearts','Spades','Jokers']);
	assert.deepEqual(byName.customWild.visibleWhen,{field:'wild',includes:'custom'});
	const remembered=onlineSettingsForm({ante:4,raiseLimit:2,wild:['custom'],customWild:[0,53]});
	assert.deepEqual([remembered.values.ante,remembered.values.raiseLimit,remembered.values.customWild],[4,2,[0,53]]);
	assert.deepEqual(onlineSettingsForm({ante:-1}).values,form.values,'stored settings this build rejects fall back to the defaults');
});

test('House rules follow the offline TABLE SETUP rows, labels, captions and row summaries',()=>{
	const form=onlineSettingsForm({ante:3,maximum:30,finalMinimum:4,finalMaximum:40,raiseLimit:2,playerRaiseLimit:1,wild:['joker']});
	assert.deepEqual(form.groups.map(group=>group.legend),['Opponents','Wild cards','Limits']);
	const [opponents,wild,limits]=form.groups;
	assert.deepEqual(opponents.fields,[],'online tables are heads-up, so the row is fixed');
	assert.equal(opponents.summary(form.values),'1 · heads-up');
	assert.equal(wild.summary(form.values),'Joker');
	assert.equal(wild.fields[0].label,'','the presets sit directly under the Wild cards row, like the offline dialog');
	assert.equal(limits.summary(form.values),'3 · 2–30 / 4–40 · 2/1');
	assert.equal(limitsLabel(DEFAULT_ONLINE_SETTINGS),'1 · 2–20 / 2–20 · 3/3');
	assert.equal(limitsLabel({...DEFAULT_ONLINE_SETTINGS,maximum:null}),'1 · 2–? / 2–20 · 3/3','a half-typed number reads as a gap');
	// BETTING & RAISING LIMITS in Painter::limitsDialog, in Layout::limits() wide order.
	assert.deepEqual(limits.fields.map(field=>[field.name,field.label,field.help]),[
		['ante','Ante','Dealer pays pot'],['minimum','Before min','Minimum bet/raise'],['maximum','Before max','Maximum bet/raise']
		,['finalMinimum','After min','Minimum bet/raise'],['finalMaximum','After max','Maximum bet/raise']
		,['raiseLimit','Total raises','Whole-table cap'],['playerRaiseLimit','Raises/player','Each player\'s cap']
	]);
	const selections=[[],['joker'],['kings'],['custom'],['joker','deuces'],['deuces','kings','custom']];
	const expected=php(`echo json_encode(array_map(static fn(array $presets): string => PhpPoker\\WildCards::selectionLabel($presets),$input));`,selections);
	assert.deepEqual(selections.map(wildSelectionLabel),expected,'the Wild cards row reads like WildCards::selectionLabel');
});
