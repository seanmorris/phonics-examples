/**
 * Deterministic public reducer for a private, jointly shuffled five-card deal.
 *
 * The room transcript contains encrypted cards and zero-knowledge proofs. Each browser keeps only its own
 * commutative layer key and the five faces it is entitled to decrypt.
 */

export const ONLINE_DEAL_VERSION=1;
export const ONLINE_RULES='five-card-draw-anything-v1';
export const ONLINE_ACTION_TYPES=Object.freeze([
	'deck-shuffle','shuffle-proof-header','shuffle-proof-commitments','shuffle-proof-responses'
	,'shuffle-proof-tail','card-layer'
]);

const encoder=new TextEncoder;
const stable=value=>JSON.stringify(value,(_,item)=>item&&typeof item==='object'&&!Array.isArray(item)
	?Object.fromEntries(Object.entries(item).sort(([a],[b])=>a.localeCompare(b))):item);
const clone=value=>structuredClone(value);
const rotate=(values,start)=>[...values.slice(start),...values.slice(0,start)];
const hex=bytes=>[...bytes].map(value=>value.toString(16).padStart(2,'0')).join('');
const sha256=async value=>hex(new Uint8Array(await crypto.subtle.digest('SHA-256',encoder.encode(value))));

const validatePlayers=(players,localPlayer,breaker)=>{
	if(!Array.isArray(players)||players.length<2||players.length>8||new Set(players).size!==players.length
		||!players.every(player=>typeof player==='string'&&player.length>0&&player.length<=255)
	)throw new TypeError('Online deal needs 2 to 8 distinct players');
	if(!players.includes(localPlayer)||!players.includes(breaker))throw new TypeError('Local player and breaker need seats');
};

const storageKey=(root,player)=>`php-poker:mental-deal:v1:${root}:${player}`;

export class OnlineDeal
{
	constructor({api,root,sessionId,players,localPlayer,breaker,rounds=64,storage=globalThis.sessionStorage})
	{
		if(!api||typeof api.prepareCorpus!=='function')throw new TypeError('Mental-poker API is unavailable');
		if(typeof root!=='string'||!root||typeof sessionId!=='string'||!sessionId)throw new TypeError('Online deal needs a room identity');
		validatePlayers(players,localPlayer,breaker);
		if(!Number.isSafeInteger(rounds)||rounds<16||rounds>128)throw new RangeError('Shuffle proof needs 16 to 128 rounds');
		this.api=api;this.root=root;this.sessionId=sessionId;this.players=[...players];this.localPlayer=localPlayer;
		this.breaker=breaker;this.rounds=rounds;this.storage=storage;this.keyName=storageKey(root,localPlayer);
		this.context=`php-poker:${ONLINE_DEAL_VERSION}:${ONLINE_RULES}:${root}:${sessionId}`;
		this.corpus=api.prepareCorpus(Array.from({length:52},(_,id)=>id),{context:`${this.context}:corpus`});
		this.shuffleOrder=rotate(this.players,this.players.indexOf(breaker));
		this.dealOrder=rotate(this.players,(this.players.indexOf(breaker)+1)%this.players.length);
		this.state={
			v:ONLINE_DEAL_VERSION,rules:ONLINE_RULES,context:this.context,players:[...this.players],breaker
			,sequence:0,stage:'shuffle',shuffleIndex:0,deck:api.corpusDeck(this.corpus),layers:[]
			,pending:null,allocations:[],allocationIndex:0
		};
		this.assembler=null;this.privateCards=[];this.saved=this.loadSaved();
	}

	loadSaved()
	{
		try
		{
			const value=this.storage?.getItem(this.keyName);if(!value)return {};
			const saved=JSON.parse(value);
			return saved&&saved.v===ONLINE_DEAL_VERSION&&saved.context===this.context?saved:{};
		}
		catch{return {};}
	}

	saveSaved()
	{
		try{this.storage?.setItem(this.keyName,JSON.stringify({...this.saved,v:ONLINE_DEAL_VERSION,context:this.context}));}
		catch{}
	}

	publicState(){return clone(this.state);}
	privateHand(){return this.privateCards.sort((a,b)=>a.slot-b.slot).map(card=>card.id);}
	async hash(){return sha256(stable(this.state));}

	currentActor()
	{
		if(this.state.stage==='shuffle')return this.shuffleOrder[this.state.shuffleIndex];
		if(this.state.stage==='deal')return this.requiredRemover(this.state.allocations[this.state.allocationIndex]);
		return this.dealOrder[0];
	}

	requiredRemover(allocation)
	{
		if(!allocation)return this.dealOrder[0];
		return this.shuffleOrder.find(player=>player!==allocation.recipient&&!allocation.removed.includes(player))??allocation.recipient;
	}

	shuffleContext(player){return `${this.context}:shuffle:${this.state.shuffleIndex}:${player}`;}
	drawContext(index,player){return `${this.context}:draw:${index}:${player}`;}

	keyForLocal({required=true}={})
	{
		const key=this.saved.key;
		if(required&&(!key||typeof key!=='object'))throw new Error('This browser lost its private shuffle key');
		return key??null;
	}

	makeShufflePlan()
	{
		const actor=this.currentActor();
		if(actor!==this.localPlayer)throw new Error('Only the current player can prepare this shuffle');
		if(this.saved.plan)
		{
			if(this.saved.plan.shuffleIndex!==this.state.shuffleIndex||this.saved.plan.actor!==actor)
			throw new Error('Stored shuffle plan belongs to another stage');
			return this.saved.plan;
		}
		const key=this.api.generateLayerKey();
		const result=this.api.shuffleAndProve(this.state.deck,key,{context:this.shuffleContext(actor),rounds:this.rounds});
		const chunks=this.api.splitShuffleProof(result.proof);
		const actions=[{type:'deck-shuffle',deck:result.deck},...chunks.map(({kind,...chunk})=>({type:kind,...chunk}))];
		this.saved={...this.saved,key,plan:{shuffleIndex:this.state.shuffleIndex,actor,actions}};this.saveSaved();
		return this.saved.plan;
	}

	nextAction()
	{
		if(this.currentActor()!==this.localPlayer||this.state.stage==='ready')return null;
		if(this.state.stage==='shuffle')
		{
			const plan=this.makeShufflePlan(),index=this.state.pending?1+this.state.pending.records.length:0;
			const action=plan.actions[index];
			if(!action)throw new Error('Stored shuffle proof is incomplete');
			return clone(action);
		}
		const index=this.state.allocationIndex,allocation=this.state.allocations[index];
		const layer=this.state.layers.find(item=>item.player===this.localPlayer)?.descriptor;
		if(!layer)throw new Error('Local encryption layer is unavailable');
		const removal=this.api.removeLayer(allocation.value,this.keyForLocal(),layer,{context:this.drawContext(index,this.localPlayer)});
		return {type:'card-layer',card:index,...removal};
	}

	/** End one durable batch after each verified shuffle and after the complete initial private deal. */
	settlesAction(action)
	{
		if(this.state.stage==='shuffle')return action?.type==='shuffle-proof-tail';
		if(this.state.stage!=='deal'||action?.type!=='card-layer')return true;
		const allocation=this.state.allocations[this.state.allocationIndex];
		const remaining=this.shuffleOrder.filter(player=>player!==allocation?.recipient&&!allocation?.removed.includes(player));
		return this.state.allocationIndex===this.state.allocations.length-1&&remaining.length===1;
	}

	async apply({sequence,actor,pre_hash:preHash,action})
	{
		if(!Number.isSafeInteger(sequence)||sequence!==this.state.sequence+1)throw new Error('Online deal action is out of sequence');
		if(actor!==this.currentActor())throw new Error('Online deal action came from the wrong player');
		if(!action||!ONLINE_ACTION_TYPES.includes(action.type))throw new Error('Unknown online deal action');
		if(preHash!==undefined&&preHash!==await this.hash())throw new Error('Online deal action has the wrong parent hash');
		if(this.state.stage==='shuffle')this.applyShuffleAction(actor,action);
		else if(this.state.stage==='deal')this.applyLayerAction(actor,action);
		else throw new Error('The private deal is already complete');
		this.state.sequence=sequence;
		return {sequence,hash:await this.hash(),nextActor:this.currentActor(),outcome:null};
	}

	applyShuffleAction(actor,action)
	{
		if(action.type==='deck-shuffle')
		{
			if(this.state.pending)throw new Error('A shuffle proof is already in progress');
			this.api.layerDescriptor(this.state.deck,action.deck);
			this.state.pending={actor,output:clone(action.deck),records:[]};this.assembler=new this.api.ShuffleProofAssembler;
			return;
		}
		if(!this.state.pending||this.state.pending.actor!==actor)throw new Error('Shuffle proof has no candidate deck');
		const {type,...record}=action,chunk={kind:type,...record};
		this.state.pending.records.push(clone(action));
		if(!this.assembler)
		{
			this.assembler=new this.api.ShuffleProofAssembler;
			for(const saved of this.state.pending.records.slice(0,-1))
			{
				const {type:savedType,...savedRecord}=saved;this.assembler.add({kind:savedType,...savedRecord});
			}
		}
		const proof=this.assembler.add(chunk);
		if(!proof)return;
		const input=this.state.deck,output=this.state.pending.output,index=this.state.shuffleIndex;
		if(!this.api.verifyShuffle(input,output,proof,{context:this.shuffleContext(actor)}))throw new Error('Shuffle proof did not verify');
		this.state.layers.push({player:actor,descriptor:this.api.layerDescriptor(input,output)});
		this.state.deck=output;this.state.pending=null;this.assembler=null;this.state.shuffleIndex++;
		if(actor===this.localPlayer){delete this.saved.plan;this.saveSaved();}
		if(this.state.shuffleIndex===this.shuffleOrder.length)this.beginDeal();
	}

	beginDeal()
	{
		const allocations=[];let deckIndex=1;
		for(let round=0;round<5;round++)for(const recipient of this.dealOrder)
		{
			const value=this.state.deck[deckIndex];allocations.push({recipient,deckIndex,value,removed:[]});deckIndex++;
		}
		this.state.stage='deal';this.state.allocations=allocations;this.state.allocationIndex=0;
	}

	applyLayerAction(actor,action)
	{
		if(action.type!=='card-layer')throw new Error('The deal expects a layer-removal proof');
		const index=this.state.allocationIndex,allocation=this.state.allocations[index];
		if(action.card!==index||actor!==this.requiredRemover(allocation))throw new Error('Layer removal targets the wrong card');
		const layer=this.state.layers.find(item=>item.player===actor)?.descriptor;
		const removal={value:action.value,proof:action.proof};
		if(!layer||!this.api.verifyLayerRemoval(allocation.value,removal,layer,{context:this.drawContext(index,actor)}))
		throw new Error('Card layer-removal proof did not verify');
		allocation.value=action.value;allocation.removed.push(actor);
		if(this.requiredRemover(allocation)!==allocation.recipient)return;
		if(allocation.recipient===this.localPlayer)
		{
			const card=this.api.readPrivateCard(allocation.value,this.keyForLocal(),this.corpus);
			if(this.privateCards.some(value=>value.slot===index&&value.id!==card.id))throw new Error('Private card changed during replay');
			if(!this.privateCards.some(value=>value.slot===index))this.privateCards.push({slot:index,id:card.id});
		}
		this.state.allocationIndex++;
		if(this.state.allocationIndex===this.state.allocations.length)this.state.stage='ready';
	}

	/** Replay confirmed room history after a reload. */
	async replay(history=[])
	{
		if(!Array.isArray(history))throw new TypeError('Online deal history must be a list');
		for(const action of history)await this.apply(action);
		return this.hash();
	}

	view()
	{
		const hand=this.privateHand(),ready=this.state.stage==='ready';
		const viewPlayers=[this.localPlayer,...this.players.filter(player=>player!==this.localPlayer)];
		return {
			phase:ready?'initial-betting':'dealing',turn:-1,dealer:viewPlayers.indexOf(this.breaker),handNumber:1
			,revision:this.state.sequence,players:viewPlayers.map((id,index)=>({
				id:index,name:id===this.localPlayer?'You':id,stack:500,debt:0,folded:false,bet:0,totalBet:0
				,drawCount:null,lastAction:'',creditUsed:false,portrait:index===0?'you':'ada',isHuman:index===0
				,cards:id===this.localPlayer?(ready?hand:Array(5).fill(null)):Array(5).fill(null),handName:null,net:0
			})),pot:0,highest:0,raises:0,opener:null,awards:[],reveal:false
			,message:ready?'Private deal verified. Betting support is the next online milestone.':this.progressMessage()
			,history:[],mode:'online',profile:{version:ONLINE_RULES,variant:'anything',ante:1,minimum:2,maximum:20,raiseLimit:3}
			,legalActions:{fold:false,pass:false,check:false,call:false,callAmount:0,bet:false,minBet:0,maxBet:0,betLabel:'Bet',openingRequired:false}
			,stockCount:52-this.state.allocations.length,discardCount:0,screen:'table',canContinue:false,generation:1,error:'',pace:'normal'
		};
	}

	progressMessage()
	{
		if(this.state.stage==='shuffle')return `Verifying shuffle ${this.state.shuffleIndex+1} of ${this.players.length}.`;
		return `Dealing private cards ${this.state.allocationIndex+1} of ${this.state.allocations.length}.`;
	}
}

/** Install the protocol constructor after the mental-poker primitive and before the browser bridge. */
export function activate({session})
{
	const api=Object.freeze({OnlineDeal,ONLINE_ACTION_TYPES,ONLINE_DEAL_VERSION,ONLINE_RULES});
	session.pokerOnline=api;
	return()=>{if(session.pokerOnline===api)delete session.pokerOnline;};
}
