import test from 'node:test';
import assert from 'node:assert/strict';
import {setImmediate as settle} from 'node:timers/promises';
import {activate} from '../games/terrain-sandbox/browser/screen-awake.mjs';

function fixture(request)
{
	const doc = new EventTarget(), page = new EventTarget();
	const controller = new AbortController();
	doc.defaultView = page;
	doc.visibilityState = 'visible';
	page.navigator = {wakeLock: request && {request}};
	activate({canvas: {ownerDocument: doc}, signal: controller.signal});

	function visible(value)
	{
		doc.visibilityState = value ? 'visible' : 'hidden';
		doc.dispatchEvent(new Event('visibilitychange'));
	}

	return {doc, page, controller, visible};
}

function sentinel()
{
	return {
		released: false

		, async release() { this.released = true; }
	};
}

test('a visible sandbox holds one screen lock and releases it on hide and shutdown', async() => {
	const locks = [];

	const {page, controller, visible} = fixture(async type => {
		assert.equal(type, 'screen');
		const lock = sentinel();
		locks.push(lock);

		return lock;
	});

	await settle();
	page.dispatchEvent(new Event('focus'));
	assert.equal(locks.length, 1);
	visible(false);
	assert.equal(locks[0].released, true);
	visible(true);
	await settle();
	assert.equal(locks.length, 2);
	controller.abort();
	assert.equal(locks[1].released, true);
	visible(true);
	assert.equal(locks.length, 2);
});

test('late grants cannot replace a newer lock or survive a closed sandbox', async() => {
	const pending = [];
	const {visible, controller} = fixture(() => new Promise(resolve => pending.push(resolve)));
	visible(false);
	visible(true);
	const stale = sentinel(), current = sentinel();
	pending[1](current);
	pending[0](stale);
	await settle();
	assert.equal(stale.released, true);
	assert.equal(current.released, false);

	visible(false);
	visible(true);
	controller.abort();
	const late = sentinel();
	pending[2](late);
	await settle();
	assert.equal(late.released, true);
});

test('unavailable and denied wake locks leave the sandbox usable', async() => {
	const unsupported = fixture(null);
	unsupported.visible(false);
	unsupported.controller.abort();
	let requests = 0;

	const {doc, page, controller} = fixture(async() => {
		requests++;
		throw new Error('Denied');
	});

	await settle();
	doc.dispatchEvent(new Event('keydown'));
	assert.equal(requests, 1);
	page.dispatchEvent(new Event('pageshow'));
	await settle();
	assert.equal(requests, 2);
	controller.abort();
});
