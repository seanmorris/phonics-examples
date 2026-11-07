/**
 * Deterministic public reducer for two-player private five-card draw.
 *
 * The room transcript contains encrypted cards, proofs and public game actions. Each browser keeps only its own
 * commutative layer key and card faces. Drawn cards are identified publicly by encrypted allocation number; a
 * face enters the transcript only when its owner proves it at a contested showdown.
 */

export const ONLINE_DEAL_VERSION=4;
export const ONLINE_RULES='five-card-draw-anything-v5';
export const ONLINE_ACTION_TYPES=Object.freeze(['shuffle-packet','card-layers','poker-action','draw-choice','reveal-cards']);

const encoder=new TextEncoder;
const stable=value=>JSON.stringify(value,(_,item)=>item&&typeof item==='object'&&!Array.isArray(item)
	?Object.fromEntries(Object.entries(item).sort(([a],[b])=>a.localeCompare(b))):item);
const clone=value=>structuredClone(value);
const rotate=(values,start)=>[...values.slice(start),...values.slice(0,start)];
const hex=bytes=>[...bytes].map(value=>value.toString(16).padStart(2,'0')).join('');
const sha256=async value=>hex(new Uint8Array(await crypto.subtle.digest('SHA-256',encoder.encode(value))));
const EMPTY_LEGAL=Object.freeze({fold:false,pass:false,check:false,call:false,callAmount:0,bet:false,minBet:0,maxBet:0,betLabel:'Bet',openingRequired:false});

const validatePlayers=(players,localPlayer,breaker)=>{
	if(!Array.isArray(players)||players.length!==2||new Set(players).size!==players.length
		||!players.every(player=>typeof player==='string'&&player.length>0&&player.length<=255)
	)throw new TypeError('Online five-card draw needs two distinct players');
	if(!players.includes(localPlayer)||!players.includes(breaker))throw new TypeError('Local player and breaker need seats');
};

const storageKey=(root,player)=>`php-poker:mental-game:v${ONLINE_DEAL_VERSION}:${root}:${player}`;
/** Last-use time of every table record, so old tables can be pruned without parsing their records. */
const storageIndex=`php-poker:mental-game:v${ONLINE_DEAL_VERSION}:index`;
/** Tables whose private records (keys, proof plans and the player's own card history) stay on this device. */
export const KEPT_TABLES=20;

/** Storage that reads through and ignores writes, for a tab watching a table another tab is playing. */
export function readOnlyStorage(storage)
{
	return Object.freeze({getItem:key=>storage?.getItem(key)??null,setItem(){},removeItem(){}});
}

/** Wild-card presets in offline order, with the physical cards each makes wild (WildCards.php). */
const WILD_PRESETS=Object.freeze({
	joker:[52,53],deuces:[0,13,26,39],'one-eyed-jacks':[35,48],'mustached-kings':[11,24,50]
	,'deuces-treys':[0,1,13,14,26,27,39,40],kings:[11,24,37,50],custom:[]
});
const WILD_NAMES=Object.freeze({joker:'Jokers',deuces:'Deuces','one-eyed-jacks':'One-Eyed Jacks','mustached-kings':'Mustached Kings'
	,'deuces-treys':'Deuces and Treys',kings:'Kings'});
const SETTING_KEYS=Object.freeze(['variant','ante','minimum','maximum','finalMinimum','finalMaximum','raiseLimit','playerRaiseLimit','wild','customWild']);
/** Checkbox names, as the offline wild-card menu shows them (WildCards::OPTIONS). */
const WILD_OPTION_NAMES=Object.freeze({joker:'Joker',deuces:'Deuces','one-eyed-jacks':'One-Eyed Jacks','mustached-kings':'Mustached Kings'
	,'deuces-treys':'Deuces and Treys',kings:'Kings',custom:'Custom'});
/** Names for the numeric settings, used in the house-rules form and in its error messages. */
const SETTING_NAMES=Object.freeze({ante:'Dealer ante',minimum:'Minimum bet before the draw',maximum:'Maximum bet before the draw'
	,finalMinimum:'Minimum bet after the draw',finalMaximum:'Maximum bet after the draw',raiseLimit:'Raises per round'
	,playerRaiseLimit:'Raises per player'});
const SUIT_NAMES=Object.freeze(['Clubs','Diamonds','Hearts','Spades']);

/** A card's name, as WildCards::cardLabel writes it: "10♣", "Q♥" or "Joker 1". */
export function cardLabel(card)
{
	return card>=52?`Joker ${card-51}`:['2','3','4','5','6','7','8','9','10','J','Q','K','A'][card%13]+['♣','♦','♥','♠'][Math.floor(card/13)];
}

/** Table settings when the host changes nothing: the rules every online table used before settings existed. */
export const DEFAULT_ONLINE_SETTINGS=Object.freeze({variant:'anything',ante:1,minimum:2,maximum:20,finalMinimum:2,finalMaximum:20
	,raiseLimit:3,playerRaiseLimit:3,wild:Object.freeze([]),customWild:Object.freeze([])});

/**
 * Canonical online table settings, with the offline Profile's bounds. Online tables are Anything Opens: a Jackpots
 * opener's cards stay private, so nobody could check the openers. Omitted fields default as Profile does.
 */
export function validateOnlineSettings(settings=DEFAULT_ONLINE_SETTINGS)
{
	if(!settings||typeof settings!=='object'||Array.isArray(settings))throw new TypeError('Online table settings must be an object');
	if(Object.keys(settings).some(key=>!SETTING_KEYS.includes(key)))throw new TypeError('Unknown online table setting');
	if((settings.variant??'anything')!=='anything')throw new RangeError('Online tables use Anything Opens');
	const integer=(key,low,high,fallback)=>{
		const value=settings[key]??fallback;
		if(!Number.isSafeInteger(value)||value<low||value>high)
		throw new RangeError(`${SETTING_NAMES[key]} must be a whole number from ${low} to ${high}`);
		return value;
	};
	const ante=integer('ante',0,100,1),minimum=integer('minimum',1,100,2),maximum=integer('maximum',minimum,100,20);
	const finalMinimum=integer('finalMinimum',1,100,minimum),finalMaximum=integer('finalMaximum',finalMinimum,100,maximum);
	const raiseLimit=integer('raiseLimit',0,10,3),playerRaiseLimit=integer('playerRaiseLimit',0,10,raiseLimit);
	let presets=settings.wild??[];
	if(typeof presets==='string')presets=presets==='none'?[]:[presets];
	if(!Array.isArray(presets)||!presets.every(preset=>preset==='none'||Object.hasOwn(WILD_PRESETS,preset)))
	throw new RangeError('Invalid wild-card option');
	if(presets.includes('none')&&presets.length!==1)throw new RangeError('None cannot be combined with wild cards');
	const wild=Object.keys(WILD_PRESETS).filter(preset=>presets.includes(preset));
	const custom=settings.customWild??[];
	if(!Array.isArray(custom)||!custom.every(card=>Number.isSafeInteger(card)&&card>=0&&card<=53)
		||custom.some((card,index)=>index>0&&card<=custom[index-1])
	)throw new RangeError('Custom wild cards must be sorted, unique card ids');
	if(custom.length&&!wild.includes('custom'))throw new RangeError('Custom wild cards require the Custom option');
	return Object.freeze({variant:'anything',ante,minimum,maximum,finalMinimum,finalMaximum,raiseLimit,playerRaiseLimit
		,wild:Object.freeze(wild),customWild:Object.freeze([...custom])});
}

/** @return list<int> Physical card ids that are wild under these settings. */
export function onlineWildCards(settings)
{
	settings=validateOnlineSettings(settings);
	const cards=new Set(settings.wild.includes('custom')?settings.customWild:[]);
	for(const preset of settings.wild)for(const card of WILD_PRESETS[preset])cards.add(card);
	return [...cards].sort((a,b)=>a-b);
}

/** @return list<int> The table's deck: 52 cards, plus each joker that is wild. */
export function onlineDeck(settings)
{
	const wild=onlineWildCards(settings);
	return [...Array.from({length:52},(_,card)=>card),...[52,53].filter(card=>wild.includes(card))];
}

/** One line for lobby rows and challenge notices, e.g. "Anything opens · Ante 1 · Bets 2–20 · 3 raises · Deuces wild". */
export function describeOnlineSettings(settings)
{
	settings=validateOnlineSettings(settings);
	const parts=['Anything opens',settings.ante?`Ante ${settings.ante}`:'No ante'];
	const range=(low,high)=>low===high?`${low}`:`${low}–${high}`;
	const before=range(settings.minimum,settings.maximum),after=range(settings.finalMinimum,settings.finalMaximum);
	parts.push(before===after?`Bets ${before}`:`Bets ${before}, ${after} after the draw`);
	parts.push(settings.raiseLimit===0?'No raises'
		:`${settings.raiseLimit} raise${settings.raiseLimit===1?'':'s'}`
			+(settings.playerRaiseLimit<settings.raiseLimit?`, ${settings.playerRaiseLimit} each`:''));
	const names=settings.wild.filter(preset=>preset!=='custom').map(preset=>WILD_NAMES[preset]);
	if(settings.wild.includes('custom'))
	{
		const plurals=['Twos','Threes','Fours','Fives','Sixes','Sevens','Eights','Nines','Tens','Jacks','Queens','Kings','Aces'];
		const byRank=new Map,singles=[];
		for(const card of settings.customWild)
		{
			if(card>=52)singles.push(card);
			else byRank.set(card%13,[...(byRank.get(card%13)??[]),card]);
		}
		for(const [rank,cards] of [...byRank].sort(([a],[b])=>a-b))
		{
			if(cards.length===4)names.push(plurals[rank]);
			else singles.push(...cards);
		}
		singles.sort((a,b)=>a-b);
		if(singles.length&&singles.length<=4)names.push(...singles.map(cardLabel));
		else if(singles.length)names.push(`${singles.length} chosen cards`);
	}
	if(names.length)parts.push((names.length===1?names[0]:names.slice(0,-1).join(', ')+' and '+names.at(-1))+' wild');
	return parts.join(' · ');
}

/** The offline setup's Wild cards row: None, the one chosen preset, or how many are checked (WildCards::selectionLabel). */
export function wildSelectionLabel(presets)
{
	const chosen=Object.keys(WILD_PRESETS).filter(preset=>Array.isArray(presets)&&presets.includes(preset));
	if(!chosen.length)return 'None';
	return chosen.length===1?WILD_OPTION_NAMES[chosen[0]]:`${chosen.length} selections`;
}

/** The offline setup's Limits row: ante · before-draw range / after-draw range · total/per-player raises. */
export function limitsLabel(settings)
{
	const value=key=>Number.isSafeInteger(settings?.[key])?settings[key]:'?';
	return `${value('ante')} · ${value('minimum')}–${value('maximum')} / ${value('finalMinimum')}–${value('finalMaximum')}`
		+` · ${value('raiseLimit')}/${value('playerRaiseLimit')}`;
}

/**
 * The House rules form the room lobby shows when this player opens a table or challenges someone. It follows the
 * offline TABLE SETUP screen: Opponents (fixed, online tables are heads-up), Wild cards with the custom card list, and
 * Limits with the BETTING & RAISING LIMITS labels and captions. Each folded row shows the same summary the offline row
 * does. Online tables always play Anything opens, so the Opening row is left out and the variant keeps its value.
 * @param settings Starting values, usually the host's last online table; invalid values fall back to the defaults.
 */
export function onlineSettingsForm(settings=DEFAULT_ONLINE_SETTINGS)
{
	let values;
	try{values=validateOnlineSettings(settings);}
	catch{values=DEFAULT_ONLINE_SETTINGS;}
	const copy=value=>({...value,wild:[...value.wild],customWild:[...value.customWild]});
	const limit=(name,label,help,low,high)=>({name,label,help,type:'number',min:low,max:high,default:DEFAULT_ONLINE_SETTINGS[name]});
	return {
		title:'House rules'
		,intro:'Both players see these rules before the first deal. Your choice is kept for your next table.'
		,values:copy(values)
		,defaults:copy(DEFAULT_ONLINE_SETTINGS)
		,groups:[
			{legend:'Opponents',fields:[],summary:()=>'1 · heads-up'}
			,{
				legend:'Wild cards'
				,summary:value=>wildSelectionLabel(value.wild)
				,fields:[
					{name:'wild',label:'',type:'checkboxes',columns:8,options:Object.keys(WILD_PRESETS).map(id=>({value:id,label:WILD_OPTION_NAMES[id]}))}
					,{name:'customWild',label:'Custom wild cards',type:'checkboxes',columns:4.5,visibleWhen:{field:'wild',includes:'custom'}
						,options:Array.from({length:54},(_,card)=>({value:card,label:cardLabel(card),group:SUIT_NAMES[Math.floor(card/13)]??'Jokers'}))}
				]
			}
			,{
				legend:'Limits'
				,summary:limitsLabel
				,fields:[
					limit('ante','Ante','Dealer pays pot',0,100)
					,limit('minimum','Before min','Minimum bet/raise',1,100)
					,limit('maximum','Before max','Maximum bet/raise',1,100)
					,limit('finalMinimum','After min','Minimum bet/raise',1,100)
					,limit('finalMaximum','After max','Maximum bet/raise',1,100)
					,limit('raiseLimit','Total raises','Whole-table cap',0,10)
					,limit('playerRaiseLimit','Raises/player','Each player\'s cap',0,10)
				]
			}
		]
	};
}

const HAND_LABELS=Object.freeze(['High card','One pair','Two pair','Three of a kind','Straight','Flush','Full house','Four of a kind'
	,'Straight flush','Five of a kind']);

/** One complete five-rank interpretation, exactly as Hand::evaluateRanks scores it. */
function evaluateRanks(ranks,flushPossible,wild)
{
	ranks=[...ranks].sort((a,b)=>b-a);
	const counts=new Map;for(const rank of ranks)counts.set(rank,(counts.get(rank)??0)+1);
	const groups=[...counts.keys()].sort((a,b)=>counts.get(b)-counts.get(a)||b-a);
	const flush=flushPossible&&counts.size===5;
	let straight=0;
	if(counts.size===5)
	{
		if(ranks[0]-ranks[4]===4)straight=ranks[0];
		else if(stable(ranks)===stable([14,5,4,3,2]))straight=5;
	}
	const first=counts.get(groups[0]),second=groups.length>1?counts.get(groups[1]):0;let category,kickers;
	if(first===5&&wild>0){category=9;kickers=[groups[0]];}
	else if(straight&&flush){category=8;kickers=[straight];}
	else if(first===4){category=7;kickers=groups;}
	else if(first===3&&second===2){category=6;kickers=groups;}
	else if(flush){category=5;kickers=ranks;}
	else if(straight){category=4;kickers=[straight];}
	else if(first===3){category=3;kickers=groups;}
	else if(first===2&&second===2){category=2;kickers=groups;}
	else if(first===2){category=1;kickers=groups;}
	else{category=0;kickers=ranks;}
	let score=category;for(const rank of [...kickers,0,0,0,0,0].slice(0,5))score=score*15+rank;
	return {category,ranks:kickers,score,name:category===8&&straight===14?'Royal flush':HAND_LABELS[category]};
}

/**
 * Exact high-poker evaluator shared by both public reducers, a port of Hand::evaluate. Wild cards (52 and 53 are
 * the jokers) may stand for any rank and suit; every assignment of ranks to them is scored and the best kept.
 */
export function evaluateHand(cards,wildCards=[])
{
	const wild=new Set(wildCards);
	if(!Array.isArray(cards)||cards.length!==5||new Set(cards).size!==5
		||!cards.every(card=>Number.isSafeInteger(card)&&card>=0&&card<=53&&(card<52||wild.has(card)))
	)throw new TypeError('A poker hand requires five distinct cards');
	const ranks=[],suits=[];let wilds=0;
	for(const card of cards)
	{
		if(wild.has(card)){wilds++;continue;}
		ranks.push(card%13+2);suits.push(Math.floor(card/13));
	}
	const flushPossible=new Set(suits).size<=1;
	let best=null;
	// Combinations with repetition rather than every substitution, as in PHP.
	const allocate=(remaining,minimum,assigned)=>{
		if(remaining===0)
		{
			const candidate=evaluateRanks([...ranks,...assigned],flushPossible,wilds);
			if(!best||candidate.score>best.score)best=candidate;
			return;
		}
		for(let rank=minimum;rank<=14;rank++)allocate(remaining-1,rank,[...assigned,rank]);
	};
	allocate(wilds,2,[]);
	return best;
}

export class OnlineDeal
{
	/**
	 * @param storage Private per-table record: this browser's layer key for the live hand, any unfinished shuffle
	 * proof, and the player's own card ids for every hand, kept as hand history. Local storage keeps it across tabs
	 * and restarts; the room lobby lets only one tab play a seat, and game.mjs stops a tab's writes once it loses it.
	 * @param legacyStorage Where earlier builds kept the record; it is moved on first load.
	 * @param follow Another tab plays this seat and keeps the record: read it again before every step, so this tab can
	 * show the player's cards from keys that tab stores after this one started.
	 */
	constructor({api,root,sessionId,players,localPlayer,breaker,settings=DEFAULT_ONLINE_SETTINGS,rounds=64,storage=globalThis.localStorage
		,legacyStorage=globalThis.sessionStorage,now=()=>Date.now(),follow=false})
	{
		if(!api||typeof api.prepareCorpus!=='function')throw new TypeError('Mental-poker API is unavailable');
		if(typeof root!=='string'||!root||typeof sessionId!=='string'||!sessionId)throw new TypeError('Online game needs a room identity');
		validatePlayers(players,localPlayer,breaker);
		if(!Number.isSafeInteger(rounds)||rounds<16||rounds>128)throw new RangeError('Shuffle proof needs 16 to 128 rounds');
		this.api=api;this.root=root;this.sessionId=sessionId;this.players=[...players];this.localPlayer=localPlayer;
		this.breaker=breaker;this.rounds=rounds;this.storage=storage;this.legacyStorage=legacyStorage;this.now=now;this.follow=Boolean(follow);
		this.keyName=storageKey(root,localPlayer);
		// The table's settings are part of every proof context, the corpus and the hashed state, so two browsers that
		// disagree about them fail at the initial ready handshake instead of mid-hand.
		// The room lobby reports a table without settings as null; it plays the defaults, like an omitted value.
		this.settings=validateOnlineSettings(settings??DEFAULT_ONLINE_SETTINGS);this.wild=onlineWildCards(this.settings);this.deckIds=onlineDeck(this.settings);
		this.context=`php-poker:${ONLINE_DEAL_VERSION}:${ONLINE_RULES}:${root}:${sessionId}:${stable(this.settings)}`;
		this.corpus=api.prepareCorpus(this.deckIds,{context:`${this.context}:corpus`});
		this.shuffleOrder=rotate(this.players,this.players.indexOf(breaker));
		this.saved=this.loadSaved();this.assembler=null;this.privateCards=[];this.intent=null;this.localError='';
		this.pruneTables();
		this.state={
			v:ONLINE_DEAL_VERSION,rules:ONLINE_RULES,context:this.context,players:[...this.players],breaker,sequence:0
			,handNumber:0,dealer:this.players.indexOf(breaker),stage:'ready',shuffleIndex:0,deck:[],layers:[],pending:null
			,allocations:[],allocationIndex:0,nextDeckIndex:1,hands:this.players.map(()=>[]),discards:[],drawAllocations:[]
			,drawRecipient:null,bankrolls:this.players.map((player,id)=>({id,player,stack:500,folded:false,bet:0,totalBet:0,drawCount:null,lastAction:''}))
			,pot:0,highest:0,raises:0,raiseCounts:this.players.map(()=>0),opener:null,turnQueue:[],awards:[],reveal:false,revealed:this.players.map(()=>null)
			,message:'',history:[],outcome:null
		};
		this.beginHand(false);
	}

	loadSaved()
	{
		try
		{
			let value=this.storage?.getItem(this.keyName);
			// Earlier builds kept the record in session storage. Move it so a table open during the upgrade keeps its
			// keys; if local storage refuses it, keep reading the old copy rather than lose them.
			if(!value&&this.legacyStorage&&this.legacyStorage!==this.storage)
			{
				const legacy=this.legacyStorage.getItem(this.keyName);
				if(legacy)
				{
					value=legacy;
					try{this.storage?.setItem(this.keyName,legacy);this.legacyStorage.removeItem(this.keyName);this.touch();}
					catch{this.storage=this.legacyStorage;}
				}
			}
			if(!value)return {};
			const saved=JSON.parse(value);
			return saved&&saved.v===ONLINE_DEAL_VERSION&&saved.context===this.context?saved:{};
		}
		catch{return {};}
	}

	saveSaved()
	{
		const value=JSON.stringify({...this.saved,v:ONLINE_DEAL_VERSION,context:this.context});
		try{this.storage?.setItem(this.keyName,value);this.touch();}
		catch
		{
			// Full storage: make room from the oldest other tables once, then retry.
			try{this.pruneTables(Math.max(1,this.tableKeys().length-1));this.storage?.setItem(this.keyName,value);this.touch();}
			catch{}
		}
	}

	/** @return array<string,number> Table record keys and their last-use times. */
	readIndex()
	{
		try
		{
			const index=JSON.parse(this.storage?.getItem(storageIndex)??'{}');
			return index&&typeof index==='object'&&!Array.isArray(index)?index:{};
		}
		catch{return {};}
	}

	/** Record that this table was just used. */
	touch()
	{
		const index=this.readIndex();index[this.keyName]=this.now();
		try{this.storage?.setItem(storageIndex,JSON.stringify(index));}catch{}
	}

	/** @return list<string> Indexed table keys, newest first. */
	tableKeys()
	{
		const index=this.readIndex();
		return Object.keys(index).sort((a,b)=>(Number(index[b])||0)-(Number(index[a])||0));
	}

	/** Keep the newest tables' records, never this one, and forget the rest. */
	pruneTables(keep=KEPT_TABLES)
	{
		const index=this.readIndex(),keys=this.tableKeys().filter(key=>key!==this.keyName);
		if(keys.length<keep)return;
		for(const key of keys.slice(Math.max(0,keep-1)))
		{
			try{this.storage?.removeItem(key);}catch{}
			delete index[key];
		}
		try{this.storage?.setItem(storageIndex,JSON.stringify(index));}catch{}
	}

	publicState(){return clone(this.state);}
	seat(player){return this.players.indexOf(player);}
	bankroll(player){return this.state.bankrolls[this.seat(player)];}
	handAllocations(player){return this.state.hands[this.seat(player)];}
	privateEntry(allocation){return this.privateCards.find(card=>card.allocation===allocation);}
	storedPrivateId(allocation)
	{
		const hand=this.state.allocations[allocation]?.hand,value=this.saved.cards?.[hand]?.[allocation];
		return Number.isSafeInteger(value)&&this.deckIds.includes(value)?value:null;
	}
	rememberPrivateId(allocation,id)
	{
		const hand=this.state.allocations[allocation]?.hand,existing=this.storedPrivateId(allocation);
		if(!Number.isSafeInteger(hand)||!Number.isSafeInteger(id)||!this.deckIds.includes(id))throw new Error('Invalid private card mapping');
		if(existing!==null&&existing!==id)throw new Error('Private card changed during replay');
		if(existing===id)return;
		this.saved={...this.saved,cards:{...(this.saved.cards??{}),[hand]:{...(this.saved.cards?.[hand]??{}),[allocation]:id}}};
		this.saveSaved();
	}
	readLocalAllocation(allocation)
	{
		const record=this.state.allocations[allocation],stored=this.storedPrivateId(allocation);
		const key=this.keyForLocal({required:false,hand:record.hand});
		if(!key)
		{
			if(stored===null)throw new Error('This browser lost its private card mapping');
			return stored;
		}
		const id=this.api.readPrivateCard(record.value,key,this.corpus).definition;
		if(stored!==null&&stored!==id)throw new Error('Private card changed during replay');
		this.rememberPrivateId(allocation,id);return id;
	}
	retireLocalKey(hand)
	{
		if(!Number.isSafeInteger(hand)||!Object.hasOwn(this.saved.keys??{},hand))return;
		const allocations=this.state.allocations
			.map((card,index)=>({card,index})).filter(({card})=>card.hand===hand&&card.recipient===this.localPlayer);
		if(!allocations.length||allocations.some(({index})=>this.storedPrivateId(index)===null))return;
		const keys={...this.saved.keys};delete keys[hand];this.saved={...this.saved,keys};this.saveSaved();
	}
	privateHand()
	{
		return this.handAllocations(this.localPlayer).map(allocation=>this.privateEntry(allocation)?.id)
			.filter(Number.isSafeInteger);
	}
	async hash(){return sha256(stable(this.state));}

	currentActor()
	{
		if(this.state.stage==='shuffle')return this.shuffleOrder[this.state.shuffleIndex];
		if(this.state.stage==='deal'||(this.state.stage==='draw'&&this.state.drawAllocations.length))
		{
			const allocation=this.relevantAllocations().map(id=>this.state.allocations[id]).find(card=>!this.allocationComplete(card));
			return allocation?this.requiredRemover(allocation):this.state.turnQueue[0]??this.players[(this.state.dealer+1)%this.players.length];
		}
		if(['betting','draw','final-betting','reveal'].includes(this.state.stage))return this.state.turnQueue[0];
		if(this.state.stage==='match-over')return this.state.outcome.winner;
		return this.players[this.state.dealer];
	}

	requiredRemover(allocation)
	{
		if(!allocation)return this.players[(this.state.dealer+1)%this.players.length];
		return this.shuffleOrder.find(player=>player!==allocation.recipient&&!allocation.removed.includes(player))??allocation.recipient;
	}

	allocationComplete(allocation){return this.requiredRemover(allocation)===allocation.recipient;}
	relevantAllocations(){return this.state.stage==='draw'?this.state.drawAllocations:this.state.hands.flat();}
	shuffleContext(player){return `${this.context}:hand:${this.state.handNumber}:shuffle:${this.state.shuffleIndex}:${player}`;}
	drawContext(index,player){return `${this.context}:hand:${this.state.handNumber}:allocation:${index}:remove:${player}`;}
	openContext(index,player){return `${this.context}:hand:${this.state.handNumber}:allocation:${index}:open:${player}`;}

	keyForLocal({required=true,hand=this.state.handNumber}={})
	{
		const key=this.saved.keys?.[hand];
		if(required&&(!key||typeof key!=='object'))throw new Error('This browser lost its private shuffle key');
		return key??null;
	}

	makeShufflePlan()
	{
		const actor=this.currentActor(),hand=this.state.handNumber;
		if(actor!==this.localPlayer)throw new Error('Only the current player can prepare this shuffle');
		if(this.saved.plan)
		{
			if(this.saved.plan.hand!==hand||this.saved.plan.shuffleIndex!==this.state.shuffleIndex||this.saved.plan.actor!==actor)
			throw new Error('Stored shuffle plan belongs to another stage');
			return this.saved.plan;
		}
		const key=this.keyForLocal({required:false})??this.api.generateLayerKey();
		const result=this.api.shuffleAndProve(this.state.deck,key,{context:this.shuffleContext(actor),rounds:this.rounds});
		const chunks=this.api.splitShuffleProof(result.proof,{roundsPerChunk:16});
		const header=chunks.find(chunk=>chunk.kind==='shuffle-proof-header');
		const tail=chunks.find(chunk=>chunk.kind==='shuffle-proof-tail');
		const commitments=chunks.filter(chunk=>chunk.kind==='shuffle-proof-commitments');
		const responses=new Map(chunks.filter(chunk=>chunk.kind==='shuffle-proof-responses').map(chunk=>[chunk.offset,chunk]));
		if(!header||!tail||commitments.length!==responses.size)throw new Error('Shuffle proof did not split into matching packets');
		const record=({kind,...value})=>({type:kind,...value});
		const actions=commitments.map((commitment,index)=>({
			type:'shuffle-packet',records:[
				...(index===0?[{type:'deck-shuffle',deck:result.deck},record(header)]:[])
				,record(commitment),record(responses.get(commitment.offset))
				,...(index===commitments.length-1?[record(tail)]:[])
			]
		}));
		if(actions.some(action=>encoder.encode(JSON.stringify(action)).length>=49152))throw new Error('Shuffle packet exceeds the room action limit');
		this.saved={...this.saved,keys:{...(this.saved.keys??{}),[hand]:key},plan:{hand,shuffleIndex:this.state.shuffleIndex,actor,actions}};
		this.saveSaved();return this.saved.plan;
	}

	nextAction()
	{
		if(this.currentActor()!==this.localPlayer||this.state.stage==='match-over')return null;
		if(this.state.stage==='shuffle')
		{
			const plan=this.makeShufflePlan(),index=this.state.pending
				?this.state.pending.records.filter(record=>record.type==='shuffle-proof-commitments').length:0;
			const action=plan.actions[index];if(!action)throw new Error('Stored shuffle proof is incomplete');return clone(action);
		}
		if(this.state.stage==='deal'||(this.state.stage==='draw'&&this.state.drawAllocations.length))return this.makeLayerAction();
		if(this.state.stage==='reveal')return this.makeRevealAction();
		return this.intent?clone(this.intent):null;
	}

	makeLayerAction()
	{
		const layer=this.state.layers.find(item=>item.player===this.localPlayer)?.descriptor;
		if(!layer)throw new Error('Local encryption layer is unavailable');
		const items=[];
		for(const index of this.relevantAllocations())
		{
			const allocation=this.state.allocations[index];
			if(allocation.recipient!==this.localPlayer&&this.requiredRemover(allocation)===this.localPlayer)
			items.push({card:index,...this.api.removeLayer(allocation.value,this.keyForLocal(),layer,{context:this.drawContext(index,this.localPlayer)})});
		}
		if(!items.length)throw new Error('No private cards await this encryption layer');
		return {type:'card-layers',items};
	}

	makeRevealAction()
	{
		const layer=this.state.layers.find(item=>item.player===this.localPlayer)?.descriptor;
		if(!layer)throw new Error('Local encryption layer is unavailable');
		const items=this.handAllocations(this.localPlayer).map(allocation=>{
			const card=this.privateEntry(allocation)?.id;if(!Number.isSafeInteger(card))throw new Error('A private showdown card is unavailable');
			return {allocation,...this.api.openCard(this.state.allocations[allocation].value,this.corpus.definitions.indexOf(card),this.keyForLocal(),layer,this.corpus,
				{context:this.openContext(allocation,this.localPlayer)})};
		});
		return {type:'reveal-cards',items};
	}

	/** Queue one UI intent. Cryptographic protocol steps continue automatically. */
	command(intent)
	{
		this.localError='';
		if(!intent||typeof intent!=='object')throw new TypeError('Invalid online Poker action');
		if(intent.generation!==undefined&&intent.generation!==this.state.handNumber)throw new Error('That online table has already changed');
		if(intent.revision!==undefined&&intent.revision!==this.state.sequence)throw new Error('That online turn has already changed');
		if(this.currentActor()!==this.localPlayer||this.intent)throw new Error('It is not your turn');
		const legal=this.legalActionsFor(this.localPlayer),move=intent.type;
		if(move==='draw')
		{
			if(!legal.draw||!Array.isArray(intent.cards)||intent.cards.length>5||new Set(intent.cards).size!==intent.cards.length)
			throw new Error('Invalid discards');
			const entries=this.handAllocations(this.localPlayer).map(allocation=>({allocation,id:this.privateEntry(allocation)?.id}));
			const discards=intent.cards.map(card=>entries.find(entry=>entry.id===card)?.allocation);
			if(discards.some(value=>!Number.isSafeInteger(value)))throw new Error('Discard is not in your hand');
			this.intent={type:'draw-choice',discards};return true;
		}
		if(move==='next')
		{
			if(!legal.next)throw new Error('The next deal is unavailable');this.intent={type:'poker-action',move:'next'};return true;
		}
		if(!['fold','pass','check','call','bet'].includes(move)||legal[move]!==true)throw new Error('That action is unavailable');
		this.intent={type:'poker-action',move,...(move==='bet'?{amount:intent.amount}:{})};return true;
	}

	/** End a durable batch after each shuffle, a complete private allocation, or one public turn. */
	settlesAction(action)
	{
		if(this.state.stage==='shuffle')return action?.type==='shuffle-packet'&&action.records?.some(record=>record.type==='shuffle-proof-tail');
		if(action?.type==='draw-choice')return action.discards?.length===0;
		// Every opening is verified and hashed as it arrives, but only the last one needs the room's two reports.
		// Keeping the reveal actions in one batch removes a redundant confirmation between visible hands and payout.
		if(action?.type==='reveal-cards')return this.state.turnQueue.length<=1;
		if(action?.type!=='card-layers')return true;
		const actor=this.currentActor(),cards=new Set(action.items?.map(item=>item.card));
		return this.relevantAllocations().every(index=>{
			const allocation=this.state.allocations[index];
			return this.shuffleOrder.every(player=>player===allocation.recipient||allocation.removed.includes(player)||(player===actor&&cards.has(index)));
		});
	}

	async apply({sequence,actor,pre_hash:preHash,action})
	{
		if(!Number.isSafeInteger(sequence)||sequence!==this.state.sequence+1)throw new Error('Online Poker action is out of sequence');
		if(actor!==this.currentActor())throw new Error('Online Poker action came from the wrong player');
		if(!action||!ONLINE_ACTION_TYPES.includes(action.type))throw new Error('Unknown online Poker action');
		if(typeof preHash!=='string'||preHash!==await this.hash())throw new Error('Online Poker action has the wrong parent hash');
		if(this.follow)this.saved=this.loadSaved();
		const before={state:clone(this.state),privateCards:clone(this.privateCards),intent:clone(this.intent),saved:clone(this.saved)};
		let stored;
		try{stored=this.storage?.getItem(this.keyName)??null;}catch{stored=undefined;}
		try
		{
			if(this.state.stage==='shuffle')this.applyShuffleAction(actor,action);
			else if(this.state.stage==='deal'||(this.state.stage==='draw'&&this.state.drawAllocations.length))this.applyLayerAction(actor,action);
			else if(this.state.stage==='draw')this.applyDrawChoice(actor,action);
			else if(this.state.stage==='reveal')this.applyRevealAction(actor,action);
			else this.applyPokerAction(actor,action);
		}
		catch(error)
		{
			this.state=before.state;this.privateCards=before.privateCards;this.intent=before.intent;
			this.saved=before.saved;this.assembler=null;
			if(stored!==undefined)try
			{
				if(stored===null)this.storage?.removeItem(this.keyName);else this.storage?.setItem(this.keyName,stored);
			}
			catch{}
			throw error;
		}
		this.state.sequence=sequence;if(actor===this.localPlayer)this.intent=null;
		return {sequence,hash:await this.hash(),nextActor:this.currentActor(),outcome:clone(this.state.outcome)};
	}

	applyShuffleAction(actor,action)
	{
		if(action.type!=='shuffle-packet'||!Array.isArray(action.records)||!action.records.length||action.records.length>5)
		throw new Error('The shuffle expects a bounded proof packet');
		for(const record of action.records)this.applyShuffleRecord(actor,record);
	}

	applyShuffleRecord(actor,action)
	{
		if(action.type==='deck-shuffle')
		{
			if(this.state.pending)throw new Error('A shuffle proof is already in progress');
			this.api.layerDescriptor(this.state.deck,action.deck);
			this.state.pending={actor,output:clone(action.deck),records:[]};
			this.assembler=new this.api.ShuffleProofAssembler({rounds:this.rounds});return;
		}
		if(!this.state.pending||this.state.pending.actor!==actor)throw new Error('Shuffle proof has no candidate deck');
		const {type,...record}=action,chunk={kind:type,...record};this.state.pending.records.push(clone(action));
		if(!this.assembler)
		{
			this.assembler=new this.api.ShuffleProofAssembler({rounds:this.rounds});
			for(const saved of this.state.pending.records.slice(0,-1))
			{
				const {type:savedType,...savedRecord}=saved;this.assembler.add({kind:savedType,...savedRecord});
			}
		}
		const proof=this.assembler.add(chunk);if(!proof)return;
		const input=this.state.deck,output=this.state.pending.output;
		if(!this.api.verifyShuffle(input,output,proof,{context:this.shuffleContext(actor),rounds:this.rounds}))
		throw new Error('Shuffle proof did not verify');
		const completed={hand:this.state.handNumber,shuffleIndex:this.state.shuffleIndex,actor};
		this.state.layers.push({player:actor,descriptor:this.api.layerDescriptor(input,output)});
		this.state.deck=output;this.state.pending=null;this.assembler=null;this.state.shuffleIndex++;
		const plan=this.saved.plan;
		if(actor===this.localPlayer&&plan?.hand===completed.hand&&plan.shuffleIndex===completed.shuffleIndex
			&&plan.actor===completed.actor){delete this.saved.plan;this.saveSaved();}
		if(this.state.shuffleIndex===this.shuffleOrder.length)this.beginDeal();
	}

	beginDeal()
	{
		const order=this.ordered(this.players[this.state.dealer],true);this.state.hands=this.players.map(()=>[]);
		for(let round=0;round<5;round++)for(const recipient of order)
		{
			const id=this.state.allocations.length,deckIndex=this.state.nextDeckIndex++,value=this.state.deck[deckIndex];
			this.state.allocations.push({hand:this.state.handNumber,recipient,deckIndex,value,removed:[]});
			this.state.hands[this.seat(recipient)].push(id);
		}
		this.state.stage='deal';this.state.allocationIndex=0;this.state.message='Dealing five verified private cards.';
	}

	applyLayerAction(actor,action)
	{
		if(action.type!=='card-layers'||!Array.isArray(action.items)||!action.items.length||action.items.length>52)
		throw new Error('The deal expects a layer-removal batch');
		const layer=this.state.layers.find(item=>item.player===actor)?.descriptor;
		const expected=this.relevantAllocations().filter(index=>{
			const allocation=this.state.allocations[index];return allocation.recipient!==actor&&this.requiredRemover(allocation)===actor;
		});
		if(!layer||action.items.length!==expected.length||action.items.some((item,index)=>item?.card!==expected[index]))
		throw new Error('Layer removal batch targets the wrong cards');
		for(const item of action.items)
		{
			const allocation=this.state.allocations[item.card],removal={value:item.value,proof:item.proof};
			if(!this.api.verifyLayerRemoval(allocation.value,removal,layer,{context:this.drawContext(item.card,actor)}))
			throw new Error('Card layer-removal proof did not verify');
		}
		for(const item of action.items)
		{
			const allocation=this.state.allocations[item.card];allocation.value=item.value;allocation.removed.push(actor);
			if(this.allocationComplete(allocation)&&allocation.recipient===this.localPlayer)
			{
				const id=this.readLocalAllocation(item.card),existing=this.privateEntry(item.card);
				if(existing&&existing.id!==id)throw new Error('Private card changed during replay');
				if(!existing)this.privateCards.push({allocation:item.card,id});
			}
		}
		this.state.allocationIndex=this.relevantAllocations().filter(index=>this.allocationComplete(this.state.allocations[index])).length;
		if(!this.relevantAllocations().every(index=>this.allocationComplete(this.state.allocations[index])))return;
		if(this.state.stage==='deal')this.beginBetting();else this.completeDraw(this.state.drawRecipient,this.state.drawAllocations.length);
	}

	applyDrawChoice(actor,action)
	{
		if(action.type!=='draw-choice'||!Array.isArray(action.discards)||action.discards.length>5
			||new Set(action.discards).size!==action.discards.length||this.state.turnQueue[0]!==actor
			||action.discards.some(allocation=>!this.handAllocations(actor).includes(allocation)))throw new Error('Invalid private draw choice');
		const discards=new Set(action.discards);
		this.state.hands[this.seat(actor)]=this.handAllocations(actor).filter(allocation=>!discards.has(allocation));
		this.state.discards.push(...action.discards);
		if(actor===this.localPlayer)this.privateCards=this.privateCards.filter(card=>!discards.has(card.allocation));
		this.state.drawRecipient=actor;this.state.drawAllocations=[];
		for(let count=0;count<action.discards.length;count++)
		{
			if(this.state.nextDeckIndex>=this.state.deck.length)throw new Error('The verified stock is exhausted');
			const id=this.state.allocations.length,deckIndex=this.state.nextDeckIndex++,value=this.state.deck[deckIndex];
			this.state.allocations.push({hand:this.state.handNumber,recipient:actor,deckIndex,value,removed:[]});
			this.state.hands[this.seat(actor)].push(id);this.state.drawAllocations.push(id);
		}
		this.state.allocationIndex=0;
		if(!this.state.drawAllocations.length)this.completeDraw(actor,0);
		else this.state.message=`${this.name(actor)} draws ${this.state.drawAllocations.length}.`;
	}

	completeDraw(actor,count)
	{
		this.bankroll(actor).drawCount=count;this.record(actor,count?`Draws ${count}`:'Stands pat');
		if(this.state.turnQueue[0]!==actor)throw new Error('Draw queue changed unexpectedly');
		this.state.turnQueue.shift();this.state.drawAllocations=[];this.state.drawRecipient=null;this.state.allocationIndex=0;
		if(this.state.turnQueue.length)this.state.message='Choose up to five cards to replace, or stand pat.';
		else this.beginFinalBetting();
	}

	applyRevealAction(actor,action)
	{
		const hand=this.handAllocations(actor),layer=this.state.layers.find(item=>item.player===actor)?.descriptor;
		if(action.type!=='reveal-cards'||!layer||!Array.isArray(action.items)||action.items.length!==5
			||action.items.some((item,index)=>item?.allocation!==hand[index]))throw new Error('Showdown opening targets the wrong cards');
		for(const item of action.items)
		if(!this.api.verifyCardOpening(this.state.allocations[item.allocation].value,item,layer,this.corpus,
			{context:this.openContext(item.allocation,actor)}))throw new Error('Showdown card opening did not verify');
		const cards=action.items.map(item=>this.corpus.definitions[item.card]);evaluateHand(cards,this.wild);this.state.revealed[this.seat(actor)]=cards;
		this.state.turnQueue.shift();
		if(!this.state.turnQueue.length)this.settle(true);else this.state.message='Opening the remaining hand.';
	}

	applyPokerAction(actor,action)
	{
		if(action.type!=='poker-action'||typeof action.move!=='string')throw new Error('Expected a public Poker action');
		const legal=this.legalActionsFor(actor),move=action.move;
		if(move==='next')
		{
			if(!legal.next)throw new Error('The next deal is unavailable');this.beginHand(true);return;
		}
		if(!['fold','pass','check','call','bet'].includes(move)||legal[move]!==true)throw new Error('That Poker action is unavailable');
		if(move==='bet')
		{
			if(!Number.isSafeInteger(action.amount)||action.amount<legal.minBet||action.amount>legal.maxBet)
			throw new Error('Bet is outside the legal range');
			const player=this.bankroll(actor),raising=this.state.highest>0;
			this.pay(actor,action.amount-player.bet);player.bet=action.amount;this.state.highest=action.amount;
			if(raising){this.state.raises++;this.state.raiseCounts[this.seat(actor)]++;}
			if(this.state.stage==='betting'&&this.state.opener===null)this.state.opener=actor;
			this.state.turnQueue=this.ordered(actor,false)
				.filter(player=>!this.bankroll(player).folded&&this.bankroll(player).stack>0);
			this.record(actor,(raising?'Raises to ':'Bets ')+action.amount);
		}
		else
		{
			if(this.state.turnQueue[0]!==actor)throw new Error('Betting queue changed unexpectedly');
			this.state.turnQueue.shift();
			if(move==='call')
			{
				const player=this.bankroll(actor);this.pay(actor,legal.callAmount);player.bet+=legal.callAmount;
				this.record(actor,`Calls ${legal.callAmount}`);
			}
			else if(move==='fold'){this.bankroll(actor).folded=true;this.record(actor,'Folds');}
			else this.record(actor,move==='pass'?'Passes':'Checks');
		}
		const active=this.activePlayers();
		if(active.length===1){this.settle(false);return;}
		if(this.state.turnQueue.length)return;
		this.normalizeRound();
		if(this.state.stage==='final-betting'){this.beginReveal();return;}
		if(this.state.opener===null)
		{
			this.state.stage='redeal';this.state.message='Everyone passed. The pot carries to the next deal.';
			this.history(this.state.message);return;
		}
		this.beginDraw();
	}

	beginHand(advance)
	{
		if(advance){this.retireLocalKey(this.state.handNumber);this.state.dealer=(this.state.dealer+1)%this.players.length;}
		this.state.handNumber++;this.state.stage='shuffle';this.state.shuffleIndex=0;this.state.deck=this.api.corpusDeck(this.corpus);
		this.state.layers=[];this.state.pending=null;this.state.allocations=[];this.state.allocationIndex=0;this.state.nextDeckIndex=1;
		this.state.hands=this.players.map(()=>[]);this.state.discards=[];this.state.drawAllocations=[];this.state.drawRecipient=null;
		this.state.highest=0;this.state.raises=0;this.state.raiseCounts=this.players.map(()=>0);this.state.opener=null;this.state.turnQueue=[];this.state.awards=[];
		this.state.reveal=false;this.state.revealed=this.players.map(()=>null);this.state.outcome=null;this.privateCards=[];this.intent=null;
		for(const player of this.state.bankrolls)
		{player.folded=false;player.bet=0;player.totalBet=0;player.drawCount=null;player.lastAction='';}
		const dealer=this.players[this.state.dealer],ante=Math.min(this.settings.ante,this.bankroll(dealer).stack);if(ante)this.pay(dealer,ante);
		this.state.message='Cryptographically shuffling deck.';this.history(`Hand ${this.state.handNumber}: ${this.name(dealer)} deals.`);
		if(this.saved.plan?.hand<this.state.handNumber)delete this.saved.plan;
		this.saveSaved();
	}

	beginBetting()
	{
		const funded=this.activePlayers().filter(player=>this.bankroll(player).stack>0);
		if(funded.length<2){this.beginDraw();return;}
		this.state.stage='betting';this.state.turnQueue=this.ordered(this.players[this.state.dealer],true)
			.filter(player=>funded.includes(player));
		this.state.message='Any hand may open.';
	}

	beginDraw()
	{
		this.state.stage='draw';this.state.turnQueue=this.ordered(this.players[this.state.dealer],true).filter(player=>!this.bankroll(player).folded);
		this.state.message='Choose up to five cards to replace, or stand pat.';
	}

	beginFinalBetting()
	{
		this.state.stage='final-betting';this.state.highest=0;this.state.raises=0;this.state.raiseCounts=this.players.map(()=>0);
		for(const player of this.state.bankrolls)player.bet=0;
		const active=this.activePlayers(),funded=active.filter(player=>this.bankroll(player).stack>0),opener=this.state.opener;
		if(funded.length<2){this.beginReveal();return;}
		this.state.turnQueue=this.bankroll(opener).folded?this.ordered(opener,false).filter(player=>funded.includes(player))
			:[opener,...this.ordered(opener,false).filter(player=>funded.includes(player))];
		this.state.message='Final betting round.';
	}

	beginReveal()
	{
		this.state.stage='reveal';this.state.turnQueue=this.ordered(this.players[this.state.dealer],true).filter(player=>!this.bankroll(player).folded);
		this.state.message='Opening the remaining hands.';
	}

	settle(reveal)
	{
		const active=this.activePlayers();let winners=active,best=-1;
		if(reveal)
		{
			winners=[];
			for(const player of active)
			{
				const score=evaluateHand(this.state.revealed[this.seat(player)],this.wild).score;
				if(score>best){best=score;winners=[player];}else if(score===best)winners.push(player);
			}
		}
		const order=this.ordered(this.players[this.state.dealer],true),orderedWinners=order.filter(player=>winners.includes(player));
		const each=Math.floor(this.state.pot/winners.length),odd=this.state.pot%winners.length;this.state.awards=[];
		for(const [index,player] of orderedWinners.entries())
		{
			const amount=each+(index<odd?1:0),handName=reveal?evaluateHand(this.state.revealed[this.seat(player)],this.wild).name:null;
			this.bankroll(player).stack+=amount;this.state.awards.push({player,amount,handName});
		}
		const names=orderedWinners.map(player=>this.name(player));
		this.state.message=names.join(' and ')+(winners.length>1?' split ':' wins ')+this.state.pot+' chips'
			+(reveal?' with '+evaluateHand(this.state.revealed[this.seat(orderedWinners[0])],this.wild).name.toLowerCase():' uncontested')+'.';
		this.state.pot=0;this.state.stage='showdown';this.state.turnQueue=[];this.state.reveal=reveal;this.history(this.state.message);
		this.retireLocalKey(this.state.handNumber);
		const solvent=this.players.filter(player=>this.bankroll(player).stack>0);
		if(solvent.length===1)
		{
			this.state.stage='match-over';this.state.outcome={winner:solvent[0],reason:'bankroll'};
			this.state.message+=` ${this.name(solvent[0])} wins the table.`;
		}
	}

	legalActionsFor(player)
	{
		if(player!==this.currentActor())return clone(EMPTY_LEGAL);
		if(['showdown','redeal'].includes(this.state.stage))return {...clone(EMPTY_LEGAL),next:true,loan:0};
		if(this.state.stage==='draw'&&!this.state.drawAllocations.length)return {...clone(EMPTY_LEGAL),draw:true,maximumDiscards:5};
		if(!['betting','final-betting'].includes(this.state.stage))return clone(EMPTY_LEGAL);
		const seat=this.bankroll(player),due=this.state.highest-seat.bet,opening=this.state.stage==='betting'&&this.state.opener===null;
		const final=this.state.stage==='final-betting';
		const increment=final?this.settings.finalMinimum:this.settings.minimum,largest=final?this.settings.finalMaximum:this.settings.maximum;
		const minimum=this.state.highest+increment,maximum=Math.min(seat.bet+seat.stack,this.state.highest+largest);
		const canRaise=seat.stack>due&&maximum>=minimum&&(this.state.highest===0||(this.state.raises<this.settings.raiseLimit
			&&this.state.raiseCounts[this.seat(player)]<this.settings.playerRaiseLimit));
		return {fold:true,pass:opening&&due===0,check:!opening&&due===0,call:due>0&&seat.stack>0
			,callAmount:Math.min(due,seat.stack),bet:canRaise,minBet:minimum,maxBet:maximum,betIncrement:increment
			,betLabel:this.state.highest>0?'Raise to':opening?'Open for':'Bet',openingRequired:false};
	}

	activePlayers(){return this.players.filter(player=>!this.bankroll(player).folded);}
	ordered(after,include)
	{
		const result=[],start=this.seat(after),count=this.players.length;
		for(let step=1;step<=(include?count:count-1);step++)result.push(this.players[(start+step)%count]);
		return result;
	}

	pay(player,amount)
	{
		const seat=this.bankroll(player);if(!Number.isSafeInteger(amount)||amount<0||amount>seat.stack)throw new Error('Insufficient chips');
		seat.stack-=amount;seat.totalBet+=amount;this.state.pot+=amount;
	}

	normalizeRound()
	{
		const active=this.activePlayers();if(active.length<2)return;
		const matched=Math.min(...active.map(player=>this.bankroll(player).bet));
		for(const player of active)
		{
			const seat=this.bankroll(player),refund=seat.bet-matched;
			if(refund>0){seat.bet-=refund;seat.totalBet-=refund;seat.stack+=refund;this.state.pot-=refund;}
		}
		this.state.highest=matched;
	}

	name(player){return player;}
	record(player,action){this.bankroll(player).lastAction=action;this.history(`${this.name(player)}: ${action}.`);}
	history(line){this.state.history.push(line);this.state.history=this.state.history.slice(-80);}

	/** Replay confirmed room history after a reload. */
	async replay(history=[])
	{
		if(!Array.isArray(history))throw new TypeError('Online game history must be a list');
		for(const action of history)await this.apply(action);
		return this.hash();
	}

	view()
	{
		const ready=!['shuffle','deal'].includes(this.state.stage),phase=({
			shuffle:'ready',deal:'ready',betting:'betting',draw:'draw','final-betting':'final-betting',reveal:'final-betting'
			,showdown:'showdown','match-over':'showdown',redeal:'redeal'
		})[this.state.stage]??'ready';
		const viewPlayers=[this.localPlayer,...this.players.filter(player=>player!==this.localPlayer)],turn=this.currentActor();
		// The shuffle, every private deal and the showdown reveal run between the browsers by themselves, so nobody
		// has a turn to take; the activity names whose browser is working, and what it is doing, for the plates.
		const stage=this.state.stage,automatic=!ready||(stage==='draw'&&this.state.drawAllocations.length>0)||stage==='reveal';
		const activity=automatic?{seat:viewPlayers.indexOf(turn),stage:stage==='shuffle'||stage==='reveal'?stage:'deal'}:null;
		const players=viewPlayers.map((player,index)=>{
			const source=this.bankroll(player),allocations=this.handAllocations(player),revealed=this.state.revealed[this.seat(player)];
			const cards=player===this.localPlayer?allocations.map(allocation=>this.privateEntry(allocation)?.id??null)
				:revealed??Array(allocations.length).fill(null);
			const visible=cards.length===5&&cards.every(Number.isSafeInteger);
			return {id:index,name:player===this.localPlayer?'You':player,stack:source.stack,debt:0,folded:source.folded,bet:source.bet
				,totalBet:source.totalBet,drawCount:source.drawCount,lastAction:source.lastAction,creditUsed:false
				,portrait:index===0?'you':'ada',isHuman:index===0,cards,handName:visible?evaluateHand(cards,this.wild).name:null,net:source.stack-500};
		});
		const awards=this.state.awards.map(award=>({seat:viewPlayers.indexOf(award.player),amount:award.amount,handName:award.handName}));
		return {
			phase,turn:automatic?-1:viewPlayers.indexOf(turn),activity,dealer:viewPlayers.indexOf(this.players[this.state.dealer]),handNumber:this.state.handNumber
			,revision:this.state.sequence,players,pot:this.state.pot,highest:this.state.highest,raises:this.state.raises
			,opener:this.state.opener===null?null:viewPlayers.indexOf(this.state.opener),awards,reveal:this.state.reveal
			,message:this.state.message,history:[...this.state.history],mode:'online'
			,profile:{version:ONLINE_RULES,...clone(this.settings)}
			,legalActions:ready?this.legalActionsFor(this.localPlayer):clone(EMPTY_LEGAL)
			,stockCount:Math.max(0,this.state.deck.length-this.state.nextDeckIndex),discardCount:this.state.discards.length
			,screen:'table',canContinue:false,generation:ready?this.state.handNumber:Math.max(0,this.state.handNumber-1),error:this.localError,pace:'normal'
		};
	}

	progressMessage()
	{
		if(this.state.stage==='shuffle')return `Verifying shuffle ${this.state.shuffleIndex+1} of ${this.players.length}.`;
		return `Dealing private cards ${this.state.allocationIndex+1} of ${this.relevantAllocations().length}.`;
	}
}

/** Install the protocol constructor after the mental-poker primitive and before the browser bridge. */
export function activate({session})
{
	const api=Object.freeze({OnlineDeal,ONLINE_ACTION_TYPES,ONLINE_DEAL_VERSION,ONLINE_RULES,KEPT_TABLES,DEFAULT_ONLINE_SETTINGS
		,cardLabel,describeOnlineSettings,evaluateHand,limitsLabel,onlineDeck,onlineSettingsForm,onlineWildCards,readOnlyStorage
		,validateOnlineSettings,wildSelectionLabel});
	session.pokerOnline=api;
	return()=>{if(session.pokerOnline===api)delete session.pokerOnline;};
}
