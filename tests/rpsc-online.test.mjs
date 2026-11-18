import test from 'node:test';
import assert from 'node:assert/strict';
import * as api from '../games/php-poker/browser/mental-poker-source.mjs';
import {ONLINE_ACTION_TYPES, OnlineMatch, Rules, readOnlyStorage} from '../games/rpsc/browser/online-match.mjs';

const storage = () => {
	const values = new Map;

	return {
		getItem: key => values.get(key) ?? null
		, setItem: (key, value) => values.set(key, value)
		, removeItem: key => values.delete(key)
		, values
	};
};

/** Small deterministic generator so failures reproduce. */
const random = seed => () => { seed = (seed * 1103515245 + 12345) % 2147483648; return seed / 2147483648; };

/** The match every test table plays: Alice and Bob, with Alice chosen to go first. */
const MATCH = {sessionId: 'match-1', players: ['alice', 'bob'], breaker: 'alice'};

/** Alice's seat at a table, in a browser with nothing stored for it. */
const stranger = root => new OnlineMatch({api, root, ...MATCH, localPlayer: 'alice', rounds: 16, storage: storage()});

/**
 * Send actions from one seat to one browser until it refuses one.
 * @param {OnlineMatch} match The browser that checks them.
 * @param {string} actor The seat they claim to come from.
 * @param {object[]} actions The actions, in order.
 * @param {number} sequence The sequence of the last action the browser has applied.
 * @returns {Promise<Error|null>} The refusal, or null when every action was accepted.
 */
async function refusal(match, actor, actions, sequence)
{
	let parent = await match.hash();

	for(const action of actions)
	{
		const step = {sequence: ++sequence, actor, pre_hash: parent, action};
		const settled = await match.apply(step).catch(error => error);

		if(settled instanceof Error) return settled;

		parent = settled.hash;
	}

	return null;
}

/** Two browsers at one table. Alice goes first, so she plays Black. */
function table({rounds = 16, root = 'table-1'} = {})
{
	const stores = {alice: storage(), bob: storage()};

	/** What one browser is told about its seat. */
	const seat = name => ({api, root, ...MATCH, localPlayer: name, rounds, storage: stores[name]});

	const make = (name, extra = {}) => new OnlineMatch({...seat(name), ...extra});

	const peers = {alice: make('alice'), bob: make('bob')};
	const history = [];

	/** Apply one action to both browsers, as the room does, and check that they agree. */
	const deliver = async(actor, action, target = peers) => {
		const envelope = {sequence: history.length + 1, actor, pre_hash: await target[actor].hash(), action};
		const settled = [];

		for(const name of Object.keys(target)) settled.push(await target[name].apply(structuredClone(envelope)));

		assert.deepEqual(settled[0], settled[1], 'both browsers settle on the same state');
		history.push(envelope);

		return settled[0];
	};

	/** Let whoever owes an action send it, until someone has to decide. */
	const drive = async() => {
		for(let steps = 0; steps < 64; steps++)
		{
			const actor = peers.alice.currentActor();
			const action = peers[actor].nextAction();

			if(!action) return;

			await deliver(actor, action);
		}

		assert.fail('the table never came to rest');
	};

	return {peers, stores, history, make, deliver, drive};
}

/** Place both sides and reach Black's first turn. */
async function started(options)
{
	const game = table(options);

	for(const name of ['alice', 'bob']) assert.equal(game.peers[name].command({type: 'ready'}), true);

	await game.drive();
	assert.equal(game.peers.alice.publicState().stage, 'play');

	return game;
}

/** Play random legal moves and challenges until the game is over, checking every view on the way. */
async function playOut(game, next)
{
	const {peers} = game;
	const shown = [new Map, new Map];

	for(let turn = 0; turn < 4000 && peers.alice.publicState().stage !== 'over'; turn++)
	{
		const actor = peers.alice.currentActor(), match = peers[actor], view = match.view();

		// A view never names an opposing kind before the final reveal.
		for(const name of ['alice', 'bob'])
		{
			const seen = peers[name].view();

			assert.ok(
				seen.pieces.filter(piece => piece.side === seen.human).every(piece => piece.kind !== null)
				, 'a player sees their own pieces'
			);

			if(peers[name].publicState().stage !== 'reveal')
			assert.ok(
				seen.pieces.filter(piece => piece.side !== seen.human).every(piece => piece.kind === null)
				, 'and no opposing kind'
			);
		}

		assert.equal(view.human, match.side);
		const challenge = view.challenges.length && next() < .4;
		const pairs = challenge ? view.challenges : view.moves;
		const [from, to] = pairs[Math.floor(next() * pairs.length)];
		assert.equal(match.command({type: challenge ? 'challenge' : 'move', from, to}), true);
		assert.equal(match.command({type: 'move', from, to}), false, 'one intent at a time');
		await game.drive();

		for(const name of ['alice', 'bob'])
		for(const event of peers[name].takeEvents())
		{
			if(event.type !== 'challenge' || name !== 'alice') continue;

			// Each piece that is shown twice shows the same kind both times.
			const attacker = [event.side, event.attackerId, event.attacker];
			const defender = [1 - event.side, event.defenderId, event.defender];

			for(const [side, id, kind] of [attacker, defender])
			{
				assert.equal(shown[side].get(id) ?? kind, kind, 'a piece never changes kind');
				shown[side].set(id, kind);
			}
		}
	}

	return shown;
}

test('two browsers place, play, and finish a game with every state hash in agreement', async() => {
	const outcomes = {};

	for(const seed of [3, 11, 29])
	{
		const game = await started({root: `table-${seed}`});
		const {peers} = game;
		const arrangements = [peers.alice.ownKinds(), peers.bob.ownKinds()];

		for(const kinds of arrangements)
		assert.deepEqual([0, 1, 2].map(kind => kinds.filter(value => value === kind).length), [4, 4, 4]);

		// The decks are public; nothing in them is a token of the public corpus, so they say nothing by themselves.
		const {decks} = peers.alice.publicState();
		assert.equal(decks[0].length, 13);
		assert.equal(decks[0].some(point => peers.alice.corpus.cards.includes(point)), false);
		assert.notDeepEqual(decks[0], decks[1]);

		const shown = await playOut(game, random(seed));
		const state = peers.alice.publicState();
		assert.equal(state.stage, 'over');
		assert.deepEqual(state, peers.bob.publicState());
		assert.equal(await peers.alice.hash(), await peers.bob.hash());
		assert.ok(['elimination', 'home-row', 'draw'].includes(state.outcome.reason));
		assert.equal(state.outcome.winner, state.game.winner === null ? null : ['alice', 'bob'][state.game.winner]);
		outcomes[state.outcome.reason] = (outcomes[state.outcome.reason] ?? 0) + 1;

		for(const side of [0, 1])
		{
			// Everything shown on the way, lost, or turned up at the end agrees with what that player placed.
			for(const [id, kind] of shown[side]) assert.equal(kind, arrangements[side][id]);

			const final = state.revealed[side] ?? {};

			for(const [id, kind] of Object.entries(final)) assert.equal(kind, arrangements[side][Number(id)]);

			const total = [0, 1, 2].map(
				kind => state.game.lost[side].filter(value => value === kind).length
					+ Object.values(final).filter(value => value === kind).length
			);

			assert.deepEqual(total, [4, 4, 4], 'pieces lost and pieces left make four of each kind');
		}

		// At the end both players see every surviving piece.
		for(const name of ['alice', 'bob']) assert.ok(peers[name].view().pieces.every(piece => piece.kind !== null));

		assert.equal(peers.alice.nextAction(), null);
		assert.equal(peers.bob.nextAction(), null);

		assert.ok(
			['alice', 'bob'].includes(peers.alice.currentActor())
			, 'a finished game still names a seat for the room'
		);
	}

	assert.ok(Object.keys(outcomes).length >= 1);
});

test('a placement is one batch of packets under the room limit, and waits for Ready', async() => {
	const game = table({rounds: 64});
	const {peers} = game;
	assert.equal(peers.alice.currentActor(), 'alice');
	assert.equal(peers.alice.nextAction(), null, 'nothing is published before Ready');
	assert.equal(peers.bob.nextAction(), null);

	// Before Ready the arrangement can be edited; afterwards it is locked.
	const before = peers.alice.view().arrangement;
	assert.equal(before.length, 12);
	const other = before.findIndex(kind => kind !== before[0]);
	assert.equal(peers.alice.command({type: 'swap', a: 0, b: other}), true);
	assert.equal(peers.alice.view().arrangement[0], before[other]);
	assert.equal(peers.alice.command({type: 'swap', a: 0, b: 12}), false);
	assert.equal(peers.alice.command({type: 'shuffle'}), true);
	assert.equal(peers.alice.command({type: 'move', from: 17, to: 24}), false, 'no move during placement');

	// Ready locks whatever is arranged at that moment.
	const chosen = peers.alice.view().arrangement;
	assert.equal(peers.alice.command({type: 'ready'}), true);
	assert.equal(peers.alice.command({type: 'shuffle'}), false);
	assert.equal(peers.alice.view().locked, true);
	assert.equal(peers.alice.view().notice, 'Placing your pieces');
	assert.equal(peers.bob.view().notice, '', 'Bob is still arranging');

	const packets = [];

	for(let action; (action = peers.alice.nextAction()) && peers.alice.currentActor() === 'alice';)
	{
		assert.equal(action.type, 'placement-packet');
		assert.ok(new TextEncoder().encode(JSON.stringify(action)).length < 49152, 'a packet fits a room action');
		packets.push(action);
		const settled = await game.deliver('alice', action);

		assert.equal(
			peers.alice.settlesAction(action)
			, settled.nextActor === 'bob'
			, 'only the last packet hands the turn over'
		);
	}

	assert.equal(packets.length, 2, 'a full-length placement is two packets');
	assert.deepEqual(peers.alice.ownKinds(), chosen, 'the published placement is the one the player arranged');
	assert.equal(peers.bob.view().pieces.filter(piece => piece.side === 0).length, 12);
	assert.ok(peers.bob.view().pieces.every(piece => piece.kind === null));
	assert.equal(peers.alice.view().notice, 'Waiting for Red to place');
	assert.equal(peers.alice.view().arrangement, null);
	assert.equal(peers.alice.view().counts[1], 0);
	assert.equal(peers.bob.view().counts[1], 12, 'Bob still holds his twelve');

	assert.equal(peers.bob.command({type: 'ready'}), true);
	await game.drive();
	assert.equal(peers.alice.publicState().stage, 'play');
	assert.deepEqual(peers.alice.takeEvents(), [{type: 'start'}]);
	assert.equal(peers.alice.view().notice, '');
	assert.equal(peers.bob.view().notice, 'Black is thinking');
	assert.equal(peers.alice.view().moves.length, 7);
	assert.deepEqual(peers.bob.view().moves, []);
});

test('forged, misplaced, and replayed actions are refused and leave the state untouched', async() => {
	const game = await started();
	const {peers, deliver} = game;

	const refused = async(actor, action, pattern, envelope = {}) => {
		const hash = await peers.bob.hash();
		const record = {sequence: game.history.length + 1, actor, pre_hash: hash, action, ...envelope};
		await assert.rejects(peers.bob.apply(structuredClone(record)), pattern);
		assert.equal(await peers.bob.hash(), hash, 'a refused action changes nothing');
	};

	await refused('bob', {type: 'move', from: 41, to: 32}, /wrong player/);
	await refused('alice', {type: 'move', from: 17, to: 24}, /out of sequence/, {sequence: 99});
	await refused('alice', {type: 'move', from: 17, to: 24}, /wrong parent hash/, {pre_hash: 'x'.repeat(64)});
	await refused('alice', {type: 'peek'}, /Unknown online RPSC action/);
	await refused('alice', {type: 'move', from: 17, to: 25}, /cannot step there/);
	await refused('alice', {type: 'move', from: 41, to: 32}, /No piece of the side to act/);
	await refused('alice', {type: 'answer', opening: {}}, /does not belong to the play stage/);
	await refused('alice', {type: 'placement-packet', records: []}, /does not belong to the play stage/);
	assert.deepEqual(ONLINE_ACTION_TYPES, ['placement-packet', 'move', 'challenge', 'answer', 'reveal']);

	// Bring b3 and d6 together on c4 and c5.
	await deliver('alice', {type: 'move', from: 17, to: 26});
	await deliver('bob', {type: 'move', from: 43, to: 34});
	assert.deepEqual(peers.alice.view().challenges, [[26, 34]]);
	const id = peers.alice.publicState().game.pieces[26].id;
	const honest = peers.alice.open(id);
	const truth = peers.alice.ownKinds()[id];

	// Claiming another token, another piece's proof, or a challenge with no contact all fail.
	const lie = (honest.card + 4) % 12;

	await refused(
		'alice'
		, {type: 'challenge', from: 26, to: 34, opening: {...honest, card: lie}}
		, /does not match its placement/
	);

	await refused(
		'alice'
		, {type: 'challenge', from: 26, to: 34, opening: peers.alice.open((id + 1) % 12)}
		, /does not match its placement/
	);

	await refused(
		'alice'
		, {type: 'challenge', from: 26, to: 34, opening: {card: honest.card}}
		, /does not match its placement/
	);

	await refused('alice', {type: 'challenge', from: 19, to: 34, opening: honest}, /shares an edge/);

	await deliver('alice', {type: 'challenge', from: 26, to: 34, opening: honest});
	assert.equal(peers.alice.publicState().stage, 'answer');
	assert.equal(peers.alice.currentActor(), 'bob');
	assert.equal(peers.alice.view().notice, 'Waiting for Red to show a piece');
	assert.equal(peers.bob.view().notice, 'Showing your piece');
	assert.deepEqual(peers.alice.view().moves, [], 'nothing else is legal until the answer');

	// Only the challenged player may answer, and only with the challenged piece.
	const defender = peers.bob.publicState().game.pieces[34].id;
	const hash = await peers.alice.hash();

	const attempt = action => assert.rejects(
		peers.alice.apply({sequence: game.history.length + 1, actor: 'bob', pre_hash: hash, action})
		, /does not match its placement|does not belong/
	);

	await attempt({type: 'answer', opening: peers.bob.open((defender + 1) % 12)});
	await attempt({type: 'move', from: 41, to: 32});
	assert.equal(await peers.alice.hash(), hash);

	const answer = peers.bob.nextAction();
	assert.equal(answer.type, 'answer');
	await deliver('bob', answer);

	// The event names both kinds truthfully, and the game goes on with the challenger.
	const [event] = peers.alice.takeEvents().filter(item => item.type === 'challenge');
	assert.equal(event.attacker, truth);
	assert.equal(event.defender, peers.bob.ownKinds()[defender]);
	assert.equal(event.outcome, truth === event.defender ? 'tie' : Rules.beats(truth, event.defender) ? 'win' : 'loss');
	assert.equal(peers.alice.publicState().stage, peers.alice.publicState().game.phase === 'over' ? 'over' : 'play');
	assert.equal(peers.alice.currentActor(), 'alice', 'the challenger still has to move');
	assert.deepEqual(peers.alice.view().challenges, [], 'one challenge a turn');
});

test('a placement that is not the twelve tokens, or is copied from the other seat, is refused', async() => {
	const game = table();
	const {peers} = game;
	peers.alice.command({type: 'ready'});
	const plan = structuredClone(peers.alice.plan());

	// Bob cannot present Alice's deck as his own: her proof is bound to her seat.
	for(const action of plan) await game.deliver('alice', action);

	assert.equal(peers.alice.currentActor(), 'bob');
	const copy = stranger('table-1');
	await copy.replay(game.history);
	const copied = await refusal(copy, 'bob', plan, game.history.length);
	assert.match(copied?.message ?? '', /placement proof does not hold/);

	// A deck with a rock where a paper should be has no proof at all: an honest proof of another deck does not fit it.
	const forged = structuredClone(peers.bob.command({type: 'ready'}) && peers.bob.plan());
	const key = api.generateLayerKey();
	const five = api.applyShuffle(peers.bob.input, key, [0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12]);
	forged[0].records[0].deck[5] = five[1];
	const refused = await refusal(peers.alice, 'bob', forged, game.history.length);
	assert.match(refused?.message ?? '', /placement proof does not hold/);
	assert.equal(peers.alice.publicState().game.placed[1], false);
});

test('a reload rebuilds the game from the room history and keeps the private placement', async() => {
	const game = await started();
	const {peers, stores, deliver} = game;
	await deliver('alice', {type: 'move', from: 17, to: 26});
	await deliver('bob', {type: 'move', from: 43, to: 34});
	peers.alice.takeEvents();

	// The same browser, started again in the middle of the game.
	const again = game.make('alice');
	assert.equal(await again.replay(game.history), await peers.bob.hash());
	assert.deepEqual(again.takeEvents(), [], 'nothing replayed is shown as new');
	assert.deepEqual(again.ownKinds(), peers.alice.ownKinds());
	assert.deepEqual(again.view(), peers.alice.view());
	assert.equal(again.command({type: 'challenge', from: 26, to: 34}), true);
	const challenge = again.nextAction();
	await deliver('alice', challenge, {alice: again, bob: peers.bob});
	await deliver('bob', peers.bob.nextAction(), {alice: again, bob: peers.bob});
	assert.equal(again.takeEvents().at(-1).type === 'challenge' || again.publicState().game.phase === 'over', true);

	// A reload in the middle of a placement republishes the same packets. A full-length proof takes several.
	const fresh = table({root: 'table-2', rounds: 64});
	fresh.peers.alice.command({type: 'ready'});
	const plan = structuredClone(fresh.peers.alice.plan());
	assert.ok(plan.length >= 2);
	await fresh.deliver('alice', plan[0]);
	const resumed = fresh.make('alice');
	await resumed.replay(fresh.history);
	assert.deepEqual(resumed.nextAction(), plan[1]);

	// A browser that lost its record still follows the game, but cannot name or open its own pieces.
	const lost = stranger('table-1');

	await lost.replay(game.history);
	assert.equal(lost.ownKinds(), null);
	assert.ok(lost.view().pieces.filter(piece => piece.side === 0).every(piece => piece.kind === null));
	assert.throws(() => lost.open(0), /lost its private placement/);

	// A second tab watching the same seat reads the first tab's record and never acts.
	const standby = game.make('alice', {storage: readOnlyStorage(stores.alice), follow: true});
	await standby.replay(game.history);
	assert.deepEqual(standby.ownKinds(), peers.alice.ownKinds());
	assert.equal(standby.command({type: 'move', from: 19, to: 28}), false);
	assert.equal(standby.nextAction(), null);
	assert.deepEqual(standby.view().moves, []);
});

test('a table needs exactly two seats and a usable primitive', () => {
	const base = {
		api
		, root: 'r'
		, sessionId: 's'
		, players: ['alice', 'bob']
		, localPlayer: 'alice'
		, breaker: 'bob'
		, storage: storage()
	};

	assert.equal(new OnlineMatch(base).side, 1, 'the seat that does not go first plays Red');
	assert.throws(() => new OnlineMatch({...base, players: ['alice']}), /exactly two players/);
	assert.throws(() => new OnlineMatch({...base, players: ['alice', 'alice']}), /exactly two players/);
	assert.throws(() => new OnlineMatch({...base, localPlayer: 'carol'}), /must hold seats/);
	assert.throws(() => new OnlineMatch({...base, rounds: 8}), /16 to 128 rounds/);
	assert.throws(() => new OnlineMatch({...base, api: {}}), /primitive is unavailable/);
	assert.throws(() => new OnlineMatch({...base, root: ''}), /room identity/);
});
