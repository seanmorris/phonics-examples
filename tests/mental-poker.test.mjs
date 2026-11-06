import test,{before} from 'node:test';
import assert from 'node:assert/strict';
import {createHash} from 'node:crypto';
import {
	ShuffleProofAssembler,applyShuffle,corpusDeck,generateLayerKey,layerDescriptor,openCard,prepareCorpus
	,proveShuffle,readPrivateCard,removeLayer,shuffleAndProve,splitShuffleProof,verifyCardOpening
	,verifyLayerRemoval,verifyShuffle
} from '../games/php-poker/browser/mental-poker-source.mjs';

const deterministic=label=>{
	let counter=0,available=new Uint8Array;
	return size=>{
		while(available.length<size)
		{
			const block=createHash('sha256').update(label).update(String(counter++)).digest();
			available=Uint8Array.from([...available,...block]);
		}
		const result=available.slice(0,size);available=available.slice(size);return result;
	};
};

let corpus,plain,alice,bob,aliceShuffle,bobShuffle,aliceLayer,bobLayer;

before(()=>{
	corpus=prepareCorpus(Array.from({length:52},(_,id)=>({id})),{context:'match:test-1'});
	plain=corpusDeck(corpus);
	alice=generateLayerKey({randomBytes:deterministic('alice-key')});
	bob=generateLayerKey({randomBytes:deterministic('bob-key')});
	aliceShuffle=shuffleAndProve(plain,alice,{context:'match:test-1:shuffle:alice',rounds:16,randomBytes:deterministic('alice-shuffle')});
	bobShuffle=shuffleAndProve(aliceShuffle.deck,bob,{context:'match:test-1:shuffle:bob',rounds:16,randomBytes:deterministic('bob-shuffle')});
	aliceLayer=layerDescriptor(plain,aliceShuffle.deck);
	bobLayer=layerDescriptor(aliceShuffle.deck,bobShuffle.deck);
});

test('agreed card definitions produce a canonical 52-card group corpus',()=>{
	assert.equal(corpus.cards.length,52);
	assert.equal(new Set([corpus.base,...corpus.cards]).size,53);
	assert.deepEqual(corpusDeck(structuredClone(corpus)),plain);
	assert.throws(()=>prepareCorpus([1,1],{context:'duplicate'}),/distinct/);
	const altered=structuredClone(corpus);altered.cards.reverse();
	assert.throws(()=>corpusDeck(altered),/does not match/);
});

test('each player proves a private commutative shuffle without exposing its permutation',()=>{
	assert.equal(verifyShuffle(plain,aliceShuffle.deck,aliceShuffle.proof,{context:'match:test-1:shuffle:alice'}),true);
	assert.equal(verifyShuffle(plain,aliceShuffle.deck,aliceShuffle.proof,{context:'match:test-1:shuffle:alice',rounds:64}),false);
	assert.equal(verifyShuffle(aliceShuffle.deck,bobShuffle.deck,bobShuffle.proof,{context:'match:test-1:shuffle:bob'}),true);
	assert.equal(verifyShuffle(plain,aliceShuffle.deck,aliceShuffle.proof,{context:'another-match'}),false);
	assert.equal(aliceShuffle.proof.rounds,16);
	assert.equal('permutation' in aliceShuffle,false);
});

test('shuffle verification rejects card substitution, response edits and proof-base movement',()=>{
	const substituted=[...aliceShuffle.deck];substituted[1]=plain[1];
	assert.equal(verifyShuffle(plain,substituted,aliceShuffle.proof,{context:'match:test-1:shuffle:alice'}),false);
	const response=structuredClone(aliceShuffle.proof);
	[response.responses[0].permutation[0],response.responses[0].permutation[1]]=[response.responses[0].permutation[1],response.responses[0].permutation[0]];
	assert.equal(verifyShuffle(plain,aliceShuffle.deck,response,{context:'match:test-1:shuffle:alice'}),false);
	const moved=[...Array(plain.length).keys()];[moved[0],moved[1]]=[moved[1],moved[0]];
	assert.throws(()=>applyShuffle(plain,alice,moved),/proof base/);
});

test('a draw removes every non-recipient layer with a public proof and only the recipient identifies the card',()=>{
	const encrypted=bobShuffle.deck[1];
	const removal=removeLayer(encrypted,bob,bobLayer,{context:'match:test-1:draw:1:bob',randomBytes:deterministic('bob-remove')});
	assert.equal(verifyLayerRemoval(encrypted,removal,bobLayer,{context:'match:test-1:draw:1:bob'}),true);
	assert.equal(verifyLayerRemoval(encrypted,removal,bobLayer,{context:'match:test-1:draw:2:bob'}),false);
	const card=readPrivateCard(removal.value,alice,corpus);
	assert.equal(card.id>=0&&card.id<52,true);
	assert.deepEqual(card.definition,{id:card.id});
	assert.throws(()=>readPrivateCard(removal.value,bob,corpus),/not in the agreed/);
	const opening=openCard(removal.value,card.id,alice,aliceLayer,corpus,{context:'match:test-1:showdown:1',randomBytes:deterministic('alice-open')});
	assert.equal(verifyCardOpening(removal.value,opening,aliceLayer,corpus,{context:'match:test-1:showdown:1'}),true);
	assert.equal(verifyCardOpening(removal.value,{...opening,card:(card.id+1)%52},aliceLayer,corpus,{context:'match:test-1:showdown:1'}),false);
});

test('decrypting the complete shuffled deck preserves every card exactly once',()=>{
	const ids=[];
	for(let index=1;index<bobShuffle.deck.length;index++)
	{
		const removal=removeLayer(bobShuffle.deck[index],bob,bobLayer,{context:`match:test-1:audit:${index}`,randomBytes:deterministic(`remove-${index}`)});
		assert.equal(verifyLayerRemoval(bobShuffle.deck[index],removal,bobLayer,{context:`match:test-1:audit:${index}`}),true);
		ids.push(readPrivateCard(removal.value,alice,corpus).id);
	}
	assert.deepEqual(ids.sort((a,b)=>a-b),Array.from({length:52},(_,id)=>id));
});

test('shuffle proof chunks reconstruct in any order and reject overlap or digest changes',()=>{
	const chunks=splitShuffleProof(aliceShuffle.proof,{roundsPerChunk:3});
	assert.ok(Math.max(...chunks.map(value=>JSON.stringify(value).length))<32*1024);
	const pinned=new ShuffleProofAssembler({rounds:64});
	assert.throws(()=>pinned.add(chunks.find(value=>value.kind==='shuffle-proof-header')),/wrong round count/);
	const assembler=new ShuffleProofAssembler;
	let assembled=null;
	for(const chunk of [...chunks].reverse())assembled=assembler.add(chunk)??assembled;
	assert.deepEqual(assembled,aliceShuffle.proof);
	assert.equal(verifyShuffle(plain,aliceShuffle.deck,assembled,{context:'match:test-1:shuffle:alice'}),true);
	const conflict=new ShuffleProofAssembler;
	const header=chunks.find(value=>value.kind==='shuffle-proof-header');
	const commitments=chunks.find(value=>value.kind==='shuffle-proof-commitments');
	conflict.add(header);conflict.add(commitments);
	const changed=structuredClone(commitments);changed.items[0][0]=plain[1];
	assert.throws(()=>conflict.add(changed),/conflict/i);
	const finest=splitShuffleProof(aliceShuffle.proof,{roundsPerChunk:1}),fineAssembler=new ShuffleProofAssembler;
	let fineProof=null;
	for(const chunk of finest)fineProof=fineAssembler.add(chunk)??fineProof;
	assert.deepEqual(fineProof,aliceShuffle.proof);
});

test('a shuffle proof cannot be made for a deck that differs from its witness',()=>{
	const order=Array.from({length:plain.length},(_,index)=>index),output=applyShuffle(plain,alice,order);
	output[2]=plain[3];
	assert.throws(()=>proveShuffle(plain,output,alice,order,{context:'bad',rounds:16,randomBytes:deterministic('bad-proof')}),/witness/);
});
