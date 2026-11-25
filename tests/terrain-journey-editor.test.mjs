import assert from 'node:assert/strict';
import test from 'node:test';
import {appendJourneyLayer,routeLayers} from '../games/terrain-sandbox/browser/journey-editor.mjs';

test('each pair of inside exits shares one named landscape throughout the Pascal triangle',()=>{
	const nodes={A:{preset:'coast',exits:[]}};
	let parents=['A'];
	let counts=[1];
	for(let depth=1;depth<=6;depth++) {
		const children=appendJourneyLayer(nodes);
		assert.equal(children.length,depth+1);
		const ways=new Map();
		for(const [i,id] of parents.entries()) {
			assert.deepEqual(nodes[id].exits,children.slice(i,i+2));
			for(const exit of nodes[id].exits) ways.set(exit,(ways.get(exit)??0)+counts[i]);
			if(i) assert.equal(nodes[parents[i-1]].exits[1],nodes[id].exits[0]);
		}
		parents=children;
		counts=children.map(id=>ways.get(id));
	}
	assert.deepEqual(counts,[1,6,15,20,15,6,1]);
	assert.deepEqual(routeLayers(nodes).map(layer=>layer.length),[1,2,3,4,5,6,7]);
	assert.equal(Object.keys(nodes).length,28);
});

test('extending a hand-authored graph preserves earlier exits, names and landscapes',()=>{
	const nodes={A:{preset:'coast',exits:['Mesa','S27']},Mesa:{preset:'canyon',exits:['Deep']},
		S27:{preset:'river-valley',exits:['Deep']},Deep:{preset:'salt-pans',exits:[]}};
	const before=structuredClone(nodes);
	const children=appendJourneyLayer(nodes);
	assert.equal(children.length,2);
	for(const id of ['A','Mesa','S27']) assert.deepEqual(nodes[id],before[id]);
	for(const id of children) assert.deepEqual(nodes[id],{preset:'salt-pans',exits:[]});
});
