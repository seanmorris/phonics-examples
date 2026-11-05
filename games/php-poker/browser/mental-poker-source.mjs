import {ristretto255, ristretto255_hasher} from '@noble/curves/ed25519.js';
import {sha256, sha512} from '@noble/hashes/sha2.js';

/**
 * Decentralized private-deck primitives for card games.
 *
 * Cards are Ristretto255 points. A shuffle raises every point by the same secret scalar and permutes the cards;
 * scalar multiplication commutes, so players can later remove their layers in any order. Shuffle correctness uses
 * the cut-and-choose proof from Wei and Wang's mental-poker protocol, made non-interactive by a transcript-bound
 * Fiat-Shamir challenge. Layer removal and opening use Chaum-Pedersen equality-of-discrete-log proofs.
 */

export const MENTAL_POKER_VERSION=1;
export const MENTAL_POKER_GROUP='ristretto255-sha512-v1';
export const DEFAULT_SHUFFLE_ROUNDS=64;
export const MAX_CARDS=1024;
export const MAX_SHUFFLE_ROUNDS=128;
export const DEFAULT_PROOF_CHUNK_ROUNDS=12;

const Point=ristretto255.Point;
const ORDER=Point.Fn.ORDER;
const encoder=new TextEncoder;
const POINT_PATTERN=/^[A-Za-z0-9_-]{43}$/;
const SCALAR_PATTERN=/^[A-Za-z0-9_-]{43}$/;
const CARD_DST='PHONICS-MENTAL-POKER-CARD-V1';
const SCALAR_DST='PHONICS-MENTAL-POKER-SCALAR-V1';

const stable=value=>JSON.stringify(value,(_,item)=>item&&typeof item==='object'&&!Array.isArray(item)
	?Object.fromEntries(Object.entries(item).sort(([a],[b])=>a.localeCompare(b))):item);

const bytesToBigInt=bytes=>{
	let value=0n;
	for(const byte of bytes)value=value*256n+BigInt(byte);
	return value;
};

const bigIntToBytes=(value,size=32)=>{
	if(typeof value!=='bigint'||value<0n)throw new TypeError('Expected a non-negative integer');
	const result=new Uint8Array(size);
	for(let index=size-1;index>=0;index--){result[index]=Number(value&255n);value>>=8n;}
	if(value!==0n)throw new RangeError('Integer does not fit the encoding');
	return result;
};

const base64Url=bytes=>{
	let binary='';
	for(const byte of bytes)binary+=String.fromCharCode(byte);
	return btoa(binary).replaceAll('+','-').replaceAll('/','_').replace(/=+$/,'');
};

const fromBase64Url=value=>{
	if(typeof value!=='string'||!/^[A-Za-z0-9_-]+$/.test(value))throw new TypeError('Invalid base64url value');
	const padding='='.repeat((4-value.length%4)%4),binary=atob(value.replaceAll('-','+').replaceAll('_','/')+padding);
	return Uint8Array.from(binary,character=>character.charCodeAt(0));
};

const frame=(...values)=>{
	const parts=values.map(value=>value instanceof Uint8Array?value:encoder.encode(typeof value==='string'?value:stable(value)));
	const result=new Uint8Array(parts.reduce((size,part)=>size+4+part.length,0)),view=new DataView(result.buffer);
	let offset=0;
	for(const part of parts){view.setUint32(offset,part.length,false);offset+=4;result.set(part,offset);offset+=part.length;}
	return result;
};

const hex=bytes=>[...bytes].map(value=>value.toString(16).padStart(2,'0')).join('');
const digest=(...values)=>hex(sha256(frame(...values)));
const scalarChallenge=(...values)=>ristretto255_hasher.hashToScalar(frame(...values),{DST:SCALAR_DST});
const mul=(point,scalar)=>scalar===0n?Point.ZERO:point.multiply(scalar);

const encodePoint=point=>base64Url(point.toBytes());
const decodePoint=value=>{
	if(typeof value!=='string'||!POINT_PATTERN.test(value))throw new TypeError('Invalid card point');
	const bytes=fromBase64Url(value);
	if(bytes.length!==32||base64Url(bytes)!==value)throw new TypeError('Non-canonical card point');
	const point=Point.fromBytes(bytes);
	if(point.is0())throw new TypeError('Identity is not a card point');
	return point;
};

const encodeScalar=value=>base64Url(bigIntToBytes(value));
const decodeScalar=(value,{nonzero=false}={})=>{
	if(typeof value!=='string'||!SCALAR_PATTERN.test(value))throw new TypeError('Invalid scalar');
	const bytes=fromBase64Url(value),scalar=bytesToBigInt(bytes);
	if(bytes.length!==32||base64Url(bytes)!==value||scalar>=ORDER||(nonzero&&scalar===0n))throw new TypeError('Non-canonical scalar');
	return scalar;
};

const secureRandom=size=>{
	const result=new Uint8Array(size);
	globalThis.crypto.getRandomValues(result);
	return result;
};

const randomScalar=(randomBytes=secureRandom)=>{
	for(let attempt=0;attempt<1024;attempt++)
	{
		const bytes=Uint8Array.from(randomBytes(32));
		if(bytes.length!==32)throw new TypeError('Random source returned the wrong byte count');
		bytes[0]&=15;
		const value=bytesToBigInt(bytes);
		if(value>0n&&value<ORDER)return value;
	}
	throw new Error('Random source could not produce a scalar');
};

const randomBelow=(bound,randomBytes=secureRandom)=>{
	if(!Number.isSafeInteger(bound)||bound<1||bound>MAX_CARDS+1)throw new RangeError('Invalid random bound');
	const bytes=bound<=256?1:2,space=256**bytes,limit=space-space%bound;
	for(let attempt=0;attempt<1024;attempt++)
	{
		const source=Uint8Array.from(randomBytes(bytes));
		if(source.length!==bytes)throw new TypeError('Random source returned the wrong byte count');
		let value=0;for(const byte of source)value=value*256+byte;
		if(value<limit)return value%bound;
	}
	throw new Error('Random source could not choose a permutation');
};

const permutation=(size,randomBytes=secureRandom,{fixFirst=false}={})=>{
	const result=Array.from({length:size},(_,index)=>index),start=fixFirst?1:0;
	for(let index=size-1;index>start;index--)
	{
		const chosen=start+randomBelow(index-start+1,randomBytes);
		[result[index],result[chosen]]=[result[chosen],result[index]];
	}
	return result;
};

const validatePermutation=(value,size,{fixFirst=false}={})=>{
	if(!Array.isArray(value)||value.length!==size||!value.every(Number.isSafeInteger))throw new TypeError('Invalid permutation');
	if(fixFirst&&value[0]!==0)throw new TypeError('Shuffle moved the proof base');
	const sorted=[...value].sort((a,b)=>a-b);
	for(let index=0;index<size;index++)if(sorted[index]!==index)throw new TypeError('Invalid permutation');
	return value;
};

const validateContext=value=>{
	if(typeof value!=='string'||value.length<1||value.length>4096)throw new TypeError('Protocol context must be 1 to 4096 characters');
	return value;
};

const decodeDeck=(deck,{minimum=2}={})=>{
	if(!Array.isArray(deck)||deck.length<minimum||deck.length>MAX_CARDS+1)throw new TypeError('Invalid encrypted deck');
	const points=deck.map(decodePoint);
	if(new Set(deck).size!==deck.length)throw new TypeError('Deck contains duplicate points');
	return points;
};

const inverse=value=>{
	let oldR=value,r=ORDER,oldS=1n,s=0n;
	while(r!==0n){const q=oldR/r;[oldR,r]=[r,oldR-q*r];[oldS,s]=[s,oldS-q*s];}
	const result=(oldS%ORDER+ORDER)%ORDER;
	if(oldR!==1n||result===0n)throw new TypeError('Scalar has no inverse');
	return result;
};

/** Derive an agreed face-up corpus from public card definitions and a match-specific context. */
export function prepareCorpus(definitions,{context})
{
	context=validateContext(context);
	if(!Array.isArray(definitions)||definitions.length<1||definitions.length>MAX_CARDS)throw new TypeError('Corpus needs 1 to 1024 cards');
	const labels=definitions.map(value=>stable(value));
	if(labels.some(value=>value===undefined||value.length>4096)||new Set(labels).size!==labels.length)throw new TypeError('Card definitions must be distinct and bounded');
	const corpusDigest=digest('Phonics mental-poker corpus v1',definitions),points=[];
	for(let index=-1;index<definitions.length;index++)
	{
		const point=ristretto255_hasher.hashToCurve(frame('Phonics mental-poker card v1',context,corpusDigest,index,index<0?'base':labels[index]),{DST:CARD_DST});
		if(point.is0())throw new Error('Hash-to-group produced the identity');
		points.push(encodePoint(point));
	}
	if(new Set(points).size!==points.length)throw new Error('Hash-to-group collision in card corpus');
	return Object.freeze({v:MENTAL_POKER_VERSION,group:MENTAL_POKER_GROUP,context,digest:corpusDigest,definitions:structuredClone(definitions),base:points[0],cards:points.slice(1)});
}

/** Validate a portable corpus and return its canonical public deck (proof base followed by cards). */
export function corpusDeck(corpus)
{
	if(!corpus||corpus.v!==MENTAL_POKER_VERSION||corpus.group!==MENTAL_POKER_GROUP||!Array.isArray(corpus.definitions))throw new TypeError('Unsupported card corpus');
	const expected=prepareCorpus(corpus.definitions,{context:corpus.context});
	if(stable(expected)!==stable(corpus))throw new TypeError('Card corpus does not match its definitions');
	return [expected.base,...expected.cards];
}

/** Generate one private commutative encryption layer. Never serialize this into public match state. */
export function generateLayerKey({randomBytes=secureRandom}={})
{
	const exponent=randomScalar(randomBytes);
	return Object.freeze({v:MENTAL_POKER_VERSION,group:MENTAL_POKER_GROUP,exponent:encodeScalar(exponent),inverse:encodeScalar(inverse(exponent))});
}

const readKey=key=>{
	if(!key||key.v!==MENTAL_POKER_VERSION||key.group!==MENTAL_POKER_GROUP)throw new TypeError('Unsupported layer key');
	const exponent=decodeScalar(key.exponent,{nonzero:true}),undo=decodeScalar(key.inverse,{nonzero:true});
	if(exponent*undo%ORDER!==1n)throw new TypeError('Layer key inverse is invalid');
	return {exponent,inverse:undo};
};

/** Apply one secret layer and permutation. Index zero is the fixed proof base. */
export function applyShuffle(input,key,order)
{
	const points=decodeDeck(input),{exponent}=readKey(key);
	validatePermutation(order,points.length,{fixFirst:true});
	return order.map(index=>encodePoint(mul(points[index],exponent)));
}

const shuffleTranscript=(context,input,output,commitments,baseCommitment)=>frame(
	'Phonics mental-poker shuffle proof v1',context,input,output,commitments,baseCommitment
);

const shuffleChallenges=(transcript,rounds)=>{
	const bytes=sha512(transcript),result=[];
	for(let index=0;index<rounds;index++)result.push((bytes[index>>3]>>(index&7))&1);
	return result;
};

/** Prove that output is exactly a scalar-encrypted permutation of input without revealing the permutation. */
export function proveShuffle(input,output,key,order,{context,rounds=DEFAULT_SHUFFLE_ROUNDS,randomBytes=secureRandom}={})
{
	context=validateContext(context);
	if(!Number.isSafeInteger(rounds)||rounds<16||rounds>MAX_SHUFFLE_ROUNDS)throw new RangeError('Shuffle proof needs 16 to 128 rounds');
	const before=decodeDeck(input),after=decodeDeck(output),{exponent}=readKey(key);
	if(before.length!==after.length)throw new TypeError('Shuffle changed the deck size');
	validatePermutation(order,before.length,{fixFirst:true});
	const expected=order.map(index=>mul(before[index],exponent));
	if(expected.some((point,index)=>!point.equals(after[index])))throw new TypeError('Output does not match the shuffle witness');
	const witnesses=[],commitments=[];
	for(let round=0;round<rounds;round++)
	{
		const scalar=randomScalar(randomBytes),mix=permutation(before.length,randomBytes);
		witnesses.push({scalar,mix});
		commitments.push(mix.map(index=>encodePoint(mul(after[index],scalar))));
	}
	const baseRandom=randomScalar(randomBytes),baseCommitment=encodePoint(mul(before[0],baseRandom));
	const transcript=shuffleTranscript(context,input,output,commitments,baseCommitment);
	const branches=shuffleChallenges(transcript,rounds),responses=[];
	for(let round=0;round<rounds;round++)
	{
		const {scalar,mix}=witnesses[round];
		responses.push(branches[round]===0
			?{scalar:encodeScalar(scalar),permutation:mix}
			:{scalar:encodeScalar(exponent*scalar%ORDER),permutation:mix.map(index=>order[index])});
	}
	const challenge=scalarChallenge('Phonics mental-poker shuffle base v1',transcript);
	return Object.freeze({
		v:MENTAL_POKER_VERSION,group:MENTAL_POKER_GROUP,rounds,commitments,responses
		,base:{commitment:baseCommitment,response:encodeScalar((baseRandom+challenge*exponent)%ORDER)}
	});
}

/** Verify a shuffle proof. A false return means the transcript is well formed but cryptographically invalid. */
export function verifyShuffle(input,output,proof,{context}={})
{
	try
	{
		context=validateContext(context);
		const before=decodeDeck(input),after=decodeDeck(output);
		if(before.length!==after.length||!proof||proof.v!==MENTAL_POKER_VERSION||proof.group!==MENTAL_POKER_GROUP
			||!Number.isSafeInteger(proof.rounds)||proof.rounds<16||proof.rounds>MAX_SHUFFLE_ROUNDS
			||!Array.isArray(proof.commitments)||proof.commitments.length!==proof.rounds
			||!Array.isArray(proof.responses)||proof.responses.length!==proof.rounds||!proof.base)return false;
		const commitments=proof.commitments.map(deck=>decodeDeck(deck,{minimum:before.length}));
		if(commitments.some(deck=>deck.length!==before.length))return false;
		const baseCommitment=decodePoint(proof.base.commitment),transcript=shuffleTranscript(context,input,output,proof.commitments,proof.base.commitment);
		const branches=shuffleChallenges(transcript,proof.rounds);
		for(let round=0;round<proof.rounds;round++)
		{
			const response=proof.responses[round],scalar=decodeScalar(response?.scalar,{nonzero:true});
			validatePermutation(response?.permutation,before.length);
			const source=branches[round]===0?after:before;
			for(let index=0;index<source.length;index++)
			if(!mul(source[response.permutation[index]],scalar).equals(commitments[round][index]))return false;
		}
		const challenge=scalarChallenge('Phonics mental-poker shuffle base v1',transcript),response=decodeScalar(proof.base.response);
		return mul(before[0],response).equals(baseCommitment.add(mul(after[0],challenge)));
	}
	catch{return false;}
}

/** Encrypt, secretly permute and prove one complete deck in one operation. */
export function shuffleAndProve(input,key,{context,rounds=DEFAULT_SHUFFLE_ROUNDS,randomBytes=secureRandom}={})
{
	const order=permutation(input.length,randomBytes,{fixFirst:true}),deck=applyShuffle(input,key,order);
	return Object.freeze({deck,proof:proveShuffle(input,deck,key,order,{context,rounds,randomBytes})});
}

const equalityTranscript=(context,g,h,u,v,a,b)=>frame('Phonics mental-poker equality proof v1',context,g,h,u,v,a,b);

/** Prove equality of two discrete logarithms with a transcript-bound Chaum-Pedersen proof. */
export function proveEquality({g,h,u,v},key,{context,randomBytes=secureRandom}={})
{
	context=validateContext(context);
	const points=[g,h,u,v].map(decodePoint),{exponent}=readKey(key),nonce=randomScalar(randomBytes);
	if(!mul(points[0],exponent).equals(points[1])||!mul(points[2],exponent).equals(points[3]))throw new TypeError('Equality witness does not match the statement');
	const a=encodePoint(mul(points[0],nonce)),b=encodePoint(mul(points[2],nonce));
	const challenge=scalarChallenge(equalityTranscript(context,g,h,u,v,a,b));
	return Object.freeze({v:MENTAL_POKER_VERSION,group:MENTAL_POKER_GROUP,a,b,s:encodeScalar((nonce+challenge*exponent)%ORDER)});
}

/** Verify a Chaum-Pedersen equality-of-discrete-log proof. */
export function verifyEquality({g,h,u,v},proof,{context}={})
{
	try
	{
		context=validateContext(context);
		if(!proof||proof.v!==MENTAL_POKER_VERSION||proof.group!==MENTAL_POKER_GROUP)return false;
		const [gp,hp,up,vp,a,b]=[g,h,u,v,proof.a,proof.b].map(decodePoint),response=decodeScalar(proof.s);
		const challenge=scalarChallenge(equalityTranscript(context,g,h,u,v,proof.a,proof.b));
		return mul(gp,response).equals(a.add(mul(hp,challenge)))&&mul(up,response).equals(b.add(mul(vp,challenge)));
	}
	catch{return false;}
}

const readLayer=layer=>{
	if(!layer||typeof layer!=='object')throw new TypeError('Missing encryption layer');
	decodePoint(layer.beforeBase);decodePoint(layer.afterBase);
	return layer;
};

/** Public descriptor tying one player's secret exponent to a verified shuffle stage. */
export function layerDescriptor(before,after)
{
	decodeDeck(before);decodeDeck(after);
	if(before.length!==after.length)throw new TypeError('Layer changed the deck size');
	return Object.freeze({v:MENTAL_POKER_VERSION,group:MENTAL_POKER_GROUP,beforeBase:before[0],afterBase:after[0]});
}

/** Remove one player's layer from one card and prove that the removal used the shuffle's exponent. */
export function removeLayer(value,key,layer,{context,randomBytes=secureRandom}={})
{
	layer=readLayer(layer);
	const point=decodePoint(value),{inverse:undo}=readKey(key),opened=encodePoint(mul(point,undo));
	const statement={g:layer.beforeBase,h:layer.afterBase,u:opened,v:value};
	return Object.freeze({value:opened,proof:proveEquality(statement,key,{context,randomBytes})});
}

/** Verify one public layer-removal step. */
export function verifyLayerRemoval(before,removal,layer,{context}={})
{
	try
	{
		layer=readLayer(layer);decodePoint(before);decodePoint(removal?.value);
		return verifyEquality({g:layer.beforeBase,h:layer.afterBase,u:removal.value,v:before},removal.proof,{context});
	}
	catch{return false;}
}

/** Remove the final private layer and map the resulting point back to one agreed card definition. */
export function readPrivateCard(value,key,corpus)
{
	const {inverse:undo}=readKey(key),plain=encodePoint(mul(decodePoint(value),undo));
	const id=corpus?.cards?.indexOf(plain)??-1;
	if(id<0)throw new Error('Decrypted point is not in the agreed card corpus');
	return Object.freeze({id,definition:structuredClone(corpus.definitions[id]),point:plain});
}

/** Prove a concealed card's face when game rules require a public reveal. */
export function openCard(value,card,key,layer,corpus,{context,randomBytes=secureRandom}={})
{
	layer=readLayer(layer);
	if(!Number.isSafeInteger(card)||card<0||card>=corpus.cards.length)throw new RangeError('Card is outside the corpus');
	const statement={g:layer.beforeBase,h:layer.afterBase,u:corpus.cards[card],v:value};
	return Object.freeze({card,proof:proveEquality(statement,key,{context,randomBytes})});
}

/** Verify a public card opening without learning the player's layer key. */
export function verifyCardOpening(value,opening,layer,corpus,{context}={})
{
	try
	{
		layer=readLayer(layer);
		if(!Number.isSafeInteger(opening?.card)||opening.card<0||opening.card>=corpus.cards.length)return false;
		return verifyEquality({g:layer.beforeBase,h:layer.afterBase,u:corpus.cards[opening.card],v:value},opening.proof,{context});
	}
	catch{return false;}
}

/** Split a proof into Matrix-safe protocol records. Chunks remain bound by the proof transcript. */
export function splitShuffleProof(proof,{roundsPerChunk=DEFAULT_PROOF_CHUNK_ROUNDS}={})
{
	if(!proof||proof.v!==MENTAL_POKER_VERSION||!Number.isSafeInteger(proof.rounds)
		||!Array.isArray(proof.commitments)||!Array.isArray(proof.responses))throw new TypeError('Invalid shuffle proof');
	if(!Number.isSafeInteger(roundsPerChunk)||roundsPerChunk<1||roundsPerChunk>32)throw new RangeError('Proof chunks hold 1 to 32 rounds');
	const id=digest('Phonics mental-poker proof chunks v1',proof),chunks=[{
		v:MENTAL_POKER_VERSION,kind:'shuffle-proof-header',id,rounds:proof.rounds,baseCommitment:proof.base.commitment
	}];
	for(let offset=0;offset<proof.rounds;offset+=roundsPerChunk)chunks.push({
		v:MENTAL_POKER_VERSION,kind:'shuffle-proof-commitments',id,offset,items:structuredClone(proof.commitments.slice(offset,offset+roundsPerChunk))
	});
	for(let offset=0;offset<proof.rounds;offset+=roundsPerChunk)chunks.push({
		v:MENTAL_POKER_VERSION,kind:'shuffle-proof-responses',id,offset,items:structuredClone(proof.responses.slice(offset,offset+roundsPerChunk))
	});
	chunks.push({v:MENTAL_POKER_VERSION,kind:'shuffle-proof-tail',id,baseResponse:proof.base.response});
	return chunks;
}

/** Bounded, conflict-detecting reconstruction for durable proof chunks received in any order. */
export class ShuffleProofAssembler
{
	constructor(){this.id=null;this.rounds=null;this.baseCommitment=null;this.baseResponse=null;this.commitments=[];this.responses=[];this.records=new Map;}
	add(chunk)
	{
		if(!chunk||chunk.v!==MENTAL_POKER_VERSION||typeof chunk.kind!=='string'||typeof chunk.id!=='string'||!/^[0-9a-f]{64}$/.test(chunk.id))throw new TypeError('Invalid proof chunk');
		if(this.id!==null&&this.id!==chunk.id)throw new Error('Proof chunk belongs to another proof');
		this.id=chunk.id;
		const key=`${chunk.kind}:${chunk.offset??0}`,encoded=stable(chunk);
		if(this.records.has(key)){if(this.records.get(key)!==encoded)throw new Error('Conflicting proof chunk');return this.complete();}
		this.records.set(key,encoded);
		if(this.records.size>MAX_SHUFFLE_ROUNDS*2+2)throw new Error('Too many proof chunks');
		if(chunk.kind==='shuffle-proof-header')
		{
			if(!Number.isSafeInteger(chunk.rounds)||chunk.rounds<16||chunk.rounds>MAX_SHUFFLE_ROUNDS)throw new TypeError('Invalid proof header');
			decodePoint(chunk.baseCommitment);this.rounds=chunk.rounds;this.baseCommitment=chunk.baseCommitment;
		}
		else if(chunk.kind==='shuffle-proof-tail'){decodeScalar(chunk.baseResponse);this.baseResponse=chunk.baseResponse;}
		else if(['shuffle-proof-commitments','shuffle-proof-responses'].includes(chunk.kind))
		{
			if(!Number.isSafeInteger(chunk.offset)||chunk.offset<0||chunk.offset>=MAX_SHUFFLE_ROUNDS||!Array.isArray(chunk.items)||chunk.items.length<1||chunk.items.length>32)throw new TypeError('Invalid proof chunk range');
			const target=chunk.kind.endsWith('commitments')?this.commitments:this.responses;
			for(const [index,item] of chunk.items.entries())
			{
				const position=chunk.offset+index;if(position>=MAX_SHUFFLE_ROUNDS)throw new TypeError('Proof chunk exceeds its bound');
				if(target[position]!==undefined&&stable(target[position])!==stable(item))throw new Error('Overlapping proof chunks conflict');
				target[position]=structuredClone(item);
			}
		}
		else throw new TypeError('Unknown proof chunk kind');
		return this.complete();
	}
	complete()
	{
		if(this.rounds===null||this.baseResponse===null||this.commitments.length!==this.rounds||this.responses.length!==this.rounds
			||this.commitments.some(value=>value===undefined)||this.responses.some(value=>value===undefined))return null;
		const proof={v:MENTAL_POKER_VERSION,group:MENTAL_POKER_GROUP,rounds:this.rounds,commitments:this.commitments,responses:this.responses,base:{commitment:this.baseCommitment,response:this.baseResponse}};
		if(digest('Phonics mental-poker proof chunks v1',proof)!==this.id)throw new Error('Proof chunks do not match their digest');
		return proof;
	}
}

/** Install the primitive on a direct application's private browser session. */
export function activate({session})
{
	const api=Object.freeze({
		prepareCorpus,corpusDeck,generateLayerKey,applyShuffle,proveShuffle,verifyShuffle,shuffleAndProve
		,layerDescriptor,removeLayer,verifyLayerRemoval,readPrivateCard,openCard,verifyCardOpening
		,splitShuffleProof,ShuffleProofAssembler
	});
	session.mentalPoker=api;
	return()=>{if(session.mentalPoker===api)delete session.mentalPoker;};
}
