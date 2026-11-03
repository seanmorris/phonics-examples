import test from 'node:test';
import assert from 'node:assert/strict';
import {activate} from '../games/php-billiards/browser/game.mjs';

test('terminal room action rejection is acknowledged once instead of retried every frame', async () => {
	const previous = {
		location: globalThis.location
		, devicePixelRatio: globalThis.devicePixelRatio
		, ResizeObserver: globalThis.ResizeObserver
	};
	let submissions = 0, errors = 0;
	const protocol = new EventTarget;
	const parent = {getBoundingClientRect: () => ({width: 1280,height: 720})};
	const attributes = new Map;
	const canvas = {
		style: {}
		, dataset: {}
		, tabIndex: -1
		, parentElement: parent
		, getContext: () => ({})
		, getBoundingClientRect: () => ({width:1280,height:720})
		, getAttribute: name => attributes.get(name) ?? null
		, setAttribute: (name, value) => attributes.set(name, String(value))
		, removeAttribute: name => attributes.delete(name)
		, focus: () => {}
		, addEventListener: () => {}
		, removeEventListener: () => {}
	};
	const module = {}, observer = {observe: () => {},disconnect: () => {}};
	const session = {lobby: {
		protocol
		, open: async () => {}
		, attachGame: () => ({
			submitAction: async () => { submissions++; throw new Error('It is not your turn'); }
			, reportReady: async () => {}
			, reportSettled: async () => {}
			, resign: async () => {}
		})
	}};
	const originalError = console.error;

	globalThis.location = {hash: ''};
	globalThis.devicePixelRatio = 1;
	globalThis.ResizeObserver = class { constructor() { return observer; } };
	console.error = () => { errors++; };

	try
	{
		activate({module,canvas,session});
		const state = JSON.stringify({events: [],onlineRequested: false,network: {outbox: [{
			id: 'action-1'
			, type: 'action-request'
			, action: {type: 'shot'}
			, preHash: 'confirmed-hash'
		}]}});
		module.billiardsStatus(state);
		await new Promise(resolve => setImmediate(resolve));
		const first = JSON.parse(module.billiardsInput());
		assert.deepEqual(first.networkAcks, ['action-1']);
		assert.deepEqual(first.network, [{
			type: 'write-rejected'
			, detail: {messageType: 'action-request',message: 'It is not your turn'}
		}]);

		module.billiardsStatus(state);
		await new Promise(resolve => setImmediate(resolve));
		const next = JSON.parse(module.billiardsInput());
		assert.deepEqual(next.networkAcks, []);
		assert.deepEqual(next.network, []);
		assert.equal(submissions, 1);
		assert.equal(errors, 0);
	}
	finally
	{
		console.error = originalError;
		for(const [name, value] of Object.entries(previous))
		{
			if(value === undefined) delete globalThis[name];
			else globalThis[name] = value;
		}
	}
});
