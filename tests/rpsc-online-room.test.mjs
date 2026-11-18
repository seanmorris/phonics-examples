import test from 'node:test';
import assert from 'node:assert/strict';
import {InMemoryLobbyHub
	, InMemoryLobbyProvider
} from '../node_modules/@seanmorris/phonics/browser/network/InMemoryLobbyProvider.mjs';
import {RoomGameProtocol} from '../node_modules/@seanmorris/phonics/browser/network/RoomGameLobby.mjs';
import * as api from '../games/php-poker/browser/mental-poker-source.mjs';
import {ONLINE_ACTION_TYPES, ONLINE_RULES, OnlineMatch} from '../games/rpsc/browser/online-match.mjs';
import {endedNotice, playerName, retryRoomWrite, seatStorage} from '../games/rpsc/browser/game.mjs';

const storage = () => {
	const values = new Map;

	return {
		getItem: key => values.get(key) ?? null
		, setItem: (key, value) => values.set(key, value)
		, removeItem: key => values.delete(key)
	};
};

const random = seed => () => { seed = (seed * 1103515245 + 12345) % 2147483648; return seed / 2147483648; };

const until = async(condition, timeout = 120000) => {
	const started = Date.now();

	while(Date.now() - started < timeout)
	{
		if(condition()) return;

		await new Promise(resolve => setTimeout(resolve, 5));
	}

	throw new Error('Timed out waiting for the room');
};

/**
 * Seat two clients at an in-memory room. Each joins the room protocol to an OnlineMatch the way browser/game.mjs
 * does: apply, report, wait for the room's confirmation, then send the next action. `decide` plays the seat.
 */
async function seated(t, decide)
{
	const hub = new InMemoryLobbyHub;
	const game = {
		id: 'org.example.rpsc'
		, protocol: '1.0.0'
		, rules: ONLINE_RULES
		, application: 'rpsc-app'
		, runtime: 'rpsc-runtime'
		, actionTypes: [...ONLINE_ACTION_TYPES]
		, maximumActionBytes: 49152
		, checkpointMode: 'history'
		, actionBatches: true
	};

	const clients = {};

	for(const id of ['alice', 'bob'])
	{
		const provider = new InMemoryLobbyProvider({hub, peerId: id});
		const protocol = new RoomGameProtocol({provider, game, session: {}, storage: storage()});
		const client = clients[id] = {
			id
			, provider
			, protocol
			, match: null
			, port: null
			, work: Promise.resolve()
			, active: false
			, submitting: false
			, awaiting: false
			, error: null
			, stopped: null
			, store: storage()
		};

		const enqueue = task => { client.work = client.work.then(task).catch(error => { client.error ??= error; }); };

		const drive = async() => {
			if(!client.match || !client.active || client.submitting || client.awaiting || client.stopped
				|| client.match.currentActor() !== id
			)
			return;

			let action = client.match.nextAction();

			if(!action)
			{
				decide(client);
				action = client.match.nextAction();
			}

			if(!action) return;

			client.submitting = true;

			await client.port.submitAction(
				action
				, await client.match.hash()
				, {settle: client.match.settlesAction(action)}
			);
		};

		client.drive = () => enqueue(drive);

		client.port = protocol.attachGame({
			start: detail => enqueue(async() => {
				client.match = new OnlineMatch({api, ...detail, localPlayer: id, rounds: 16, storage: client.store});
				await client.port.reportReady(await client.match.replay(detail.history ?? []));
			})
			, action: detail => enqueue(async() => {
				const settlement = await client.match.apply(detail);

				if(detail.batch_end !== false) client.awaiting = true;

				if(await client.port.reportSettled(settlement)) return;

				client.awaiting = false;
				client.submitting = false;
				await drive();
			})
			, confirmed: detail => enqueue(async() => {
				assert.equal(detail.hash, await client.match.hash());
				client.active = true;
				client.awaiting = false;
				client.submitting = false;
				await drive();
			})
			, stop: detail => { client.stopped = detail ?? {}; }
		});

		await protocol.connect();
	}

	t.after(
		() => {
			for(const client of Object.values(clients))
			{
				client.protocol.close();
				client.provider.close();
			} }
	);

	const root = await clients.alice.protocol.create();
	await clients.bob.protocol.join(root);

	return {hub, clients, root};
}

test(
	'the room protocol carries a whole game: placements in batches, challenges, the final reveal, and the outcome'
	, async t => {
		t.mock.method(console, 'log', () => {});

		const next = random(20261117);

		const {hub, clients} = await seated(t, client => {
			const view = client.match.view();

			if(view.arrangement && !view.locked)
			{
				client.match.command({type: 'ready'});

				return;
			}

			const challenge = view.challenges.length && next() < .5;
			const pairs = challenge ? view.challenges : view.moves;

			if(!pairs.length) return;

			const [from, to] = pairs[Math.floor(next() * pairs.length)];
			client.match.command({type: challenge ? 'challenge' : 'move', from, to});
		});

		await until(() => Object.values(clients).every(client => client.error || client.stopped));

		for(const client of Object.values(clients)) assert.ifError(client.error);

		const [alice, bob] = [clients.alice.match, clients.bob.match];
		const state = alice.publicState();
		assert.equal(state.stage, 'over');
		assert.equal(await alice.hash(), await bob.hash());
		assert.ok(['elimination', 'home-row', 'draw'].includes(state.outcome.reason));

		// The room ended the table with the game's own outcome, and told both seats the same thing.
		assert.deepEqual(clients.alice.stopped, state.outcome);
		assert.deepEqual(clients.bob.stopped, state.outcome);
		assert.equal(clients.alice.protocol.snapshot().tables[0].sequence, state.sequence);

		const events = hub.history(clients.alice.provider.room).map(item => item.content);
		const actions = events.filter(item => item.kind === 'action');
		assert.equal(actions.length, state.sequence);

		const types = type => actions.filter(item => item.action.type === type);
		// Each placement is one batch: only its last packet asks the room to settle.
		const packets = types('placement-packet');
		assert.ok(packets.length >= 2);
		assert.equal(packets.filter(item => item.batch_end).length, 2);
		assert.equal(types('challenge').length, types('answer').length, 'every challenge was answered');
		assert.ok(types('challenge').length > 0 && types('move').length > 0);

		// Both sides turned their survivors face up before the outcome was reported.
		const survivors = [0, 1].filter(
			side => Object.values(state.game.pieces).some(piece => piece.side === side)
		).length;

		assert.equal(types('reveal').length, survivors);
		assert.equal(alice.view().pieces.every(piece => piece.kind !== null), true);
		assert.equal(bob.view().pieces.every(piece => piece.kind !== null), true);

		// Two reports for every settled step: one from each seat.
		assert.equal(
			events.filter(item => item.kind === 'settled').length
			, 2 * actions.filter(item => item.batch_end !== false).length
		);
	}
);

test('a player who resigns or leaves concedes, and the other seat is told', async t => {
	t.mock.method(console, 'log', () => {});

	const ready = client => {
		const view = client.match.view();

		if(view.arrangement && !view.locked) client.match.command({type: 'ready'});
	};

	for(const way of ['resign', 'leave'])
	{
		const {clients} = await seated(t, ready);

		await until(
			() => Object.values(clients).every(client => client.error || client.match?.publicState().stage === 'play')
		);

		await (way === 'resign' ? clients.bob.port.resign('resigned') : clients.bob.port.leave());
		await until(() => Object.values(clients).every(client => client.error || client.stopped));

		for(const client of Object.values(clients)) assert.ifError(client.error);

		assert.equal(clients.alice.match.publicState().outcome, null, 'the rules did not end this game; the room did');
		assert.equal(clients.alice.stopped.winner, 'alice');

		if(way === 'resign')
		{
			// Both seats stay at the table and are told who won.
			assert.deepEqual(clients.bob.stopped, clients.alice.stopped);
			assert.equal(endedNotice(clients.alice.stopped, true, 'bob'), 'You win: bob resigned');
			assert.equal(endedNotice(clients.bob.stopped, false, 'alice'), 'You resigned');
		}
		else
		{
			// The player who walks away is told only that they left.
			assert.deepEqual(clients.bob.stopped, {reason: 'left'});
			assert.equal(endedNotice(clients.alice.stopped, true, 'bob'), 'You win: bob left the table');
			assert.equal(endedNotice(clients.bob.stopped, false, 'alice'), 'You left the table');
		}
	}
});

test('the browser module\'s room helpers retry dropped writes and guard a lost seat', async() => {
	// A dropped connection is retried, with a notice from the third failure; a rejection the room will repeat is not.
	let calls = 0;
	const notices = [];

	const result = await retryRoomWrite(
		async() => { if(++calls < 4) throw new Error('network down'); return 'sent'; }
		, {reconnecting: value => notices.push(value), sleep: async() => {}}
	);

	assert.equal(result, 'sent');
	assert.equal(calls, 4);
	assert.deepEqual(notices, [true, false]);

	await assert.rejects(
		retryRoomWrite(async() => { throw new Error('It is not your turn'); }, {sleep: async() => {}})
		, /not your turn/
	);

	let live = true;

	assert.equal(
		await retryRoomWrite(
			async() => { live = false; throw new Error('network down'); }
			, {live: () => live, sleep: async() => {}}
		)
		, undefined
	);

	// A tab that lost its seat can still read the table's record but no longer writes it.
	const store = storage();
	let playing = true;
	const guarded = seatStorage(store, () => playing);
	guarded.setItem('key', 'mine');
	playing = false;
	guarded.setItem('key', 'late');
	guarded.removeItem('key');
	assert.equal(guarded.getItem('key'), 'mine');

	assert.equal(playerName('@sean:matrix.org'), 'sean');
	assert.equal(playerName('@sean:matrix.org', {displayName: ' Sean '}), 'Sean');
	assert.equal(playerName('alice'), 'alice');
	assert.equal(playerName('@a-very-long-matrix-name:matrix.org'), 'a-very-long-mat.');

	assert.equal(
		endedNotice({winner: 'a', reason: 'timeout', timeout: true}, true, 'Bob')
		, 'You win: Bob ran out of time'
	);

	assert.equal(endedNotice({winner: 'a', reason: 'timeout', timeout: true}, false, 'Bob'), 'You ran out of time');
	assert.equal(endedNotice(null, false, 'Bob'), 'The table ended');
	assert.equal(endedNotice({reason: 'frozen'}, false, 'Bob'), 'The table ended');
});
