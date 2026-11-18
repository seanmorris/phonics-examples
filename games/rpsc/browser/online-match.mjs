/**
 * RPSC played between two browsers with no dealer.
 *
 * Each player places twelve pieces the other must not see, yet must be unable to change them later or to bring five
 * rocks. The private-deck primitive Poker uses does both. Twelve public tokens stand for the pieces: four rocks, four
 * papers, four scissors. A player's placement is one shuffle of those tokens under a secret key, in an order the
 * player chooses: the order is the placement. The proof published with it shows the result is some rearrangement of
 * exactly those twelve tokens, without showing which. A challenge then opens one position with a proof that it holds
 * the token the player says it does.
 *
 * Both browsers run this same reducer over the same actions and compare a hash of the state after each one, so the
 * rules here are a second copy of `Rpsc\Game`. `tests/rpsc-parity.test.mjs` plays PHP's games through them.
 */

export const ONLINE_VERSION = 1;
export const ONLINE_RULES = 'rpsc-v1';
export const ONLINE_ACTION_TYPES = Object.freeze(['placement-packet', 'move', 'challenge', 'answer', 'reveal']);
/** Tables whose private records this browser keeps; older ones are forgotten. */
export const KEPT_TABLES = 20;
export const KINDS = Object.freeze(['Rock', 'Paper', 'Scissors']);
export const SIDES = Object.freeze(['Black', 'Red']);

const SIZE = 8;
const SQUARES = 64;
const PIECES = 12;
const PER_KIND = 4;
const GOALS = 4;
const QUIET_LIMIT = 80;
/** A room action may carry 49152 bytes; placement packets stay well under it. */
const PACKET_BYTES = 28000;

const encoder = new TextEncoder;

/** Copy a plain value, so a caller can never reach into the match's state. */
const clone = value => structuredClone(value);

/** Write a value as JSON with every object's keys in order, so equal states give equal text on both browsers. */
const stable = value => JSON.stringify(
	value
	, (_, item) => item && typeof item === 'object' && !Array.isArray(item)
		? Object.fromEntries(Object.entries(item).sort(([a], [b]) => a.localeCompare(b)))
		: item
);

/** Write bytes as lowercase hex. */
const hex = bytes => [...bytes].map(value => value.toString(16).padStart(2, '0')).join('');

/** The SHA-256 of a string, as hex. */
const sha256 = async value => hex(new Uint8Array(await crypto.subtle.digest('SHA-256', encoder.encode(value))));

/** The square at a file and rank, numbered from a1 along each rank, or -1 off the board. */
const square = (x, y) => x < 0 || y < 0 || x >= SIZE || y >= SIZE ? -1 : y * SIZE + x;

/** A square's file, from 0 (a) to 7 (h). */
const file = at => at % SIZE;

/** A square's rank, from 0 (rank 1) to 7 (rank 8). */
const rank = at => Math.floor(at / SIZE);

/** Black plays on the red squares, where file plus rank is odd; Red plays on the black ones. */
const owner = at => (file(at) + rank(at)) % 2 === 1 ? 0 : 1;

/** The rank a square stands on, counted from a side's own back rank: 7 is the far rank. */
const advance = (side, at) => side === 0 ? rank(at) : SIZE - 1 - rank(at);

/**
 * A side's twelve starting squares in placement order: from its own back rank forward, and from file a to file h.
 * @param {number} side 0 for Black, 1 for Red.
 * @returns {number[]} Twelve squares.
 */
function home(side)
{
	const squares = [];

	for(let row = 0; row < 3; row++)
	{
		const y = side === 0 ? row : SIZE - 1 - row;

		for(let x = 0; x < SIZE; x++) if(owner(square(x, y)) === side) squares.push(square(x, y));
	}

	return squares;
}

/**
 * The four squares a side must fill on the far rank, from file a to file h.
 * @param {number} side 0 for Black, 1 for Red.
 * @returns {number[]} Four squares.
 */
function goal(side)
{
	const squares = [], y = side === 0 ? SIZE - 1 : 0;

	for(let x = 0; x < SIZE; x++) if(owner(square(x, y)) === side) squares.push(square(x, y));

	return squares;
}

/**
 * The squares a piece could step to on an empty board, forward steps first.
 * @param {number} at The square the piece stands on.
 * @param {number} side The side it belongs to.
 * @param {boolean} king Whether it is crowned and may also step backward.
 * @returns {number[]} Diagonal neighbours on the board.
 */
function steps(at, side, king)
{
	const forward = side === 0 ? 1 : -1, targets = [];

	for(const dy of king ? [forward, -forward] : [forward])
	for(const dx of [-1, 1])
	{
		const target = square(file(at) + dx, rank(at) + dy);

		if(target >= 0) targets.push(target);
	}

	return targets;
}

/**
 * The squares that share an edge with a square. They always belong to the other side.
 * @param {number} at A square.
 * @returns {number[]} Up to four squares.
 */
function neighbours(at)
{
	const targets = [];

	for(const [dx, dy] of [[0, 1], [1, 0], [0, -1], [-1, 0]])
	{
		const target = square(file(at) + dx, rank(at) + dy);

		if(target >= 0) targets.push(target);
	}

	return targets;
}

/** Whether one kind beats another: rock scissors, scissors paper, paper rock. Kinds are 0, 1, 2 in that cycle. */
const beats = (kind, against) => (kind - against + 3) % 3 === 1;

/**
 * The rules of RPSC over a plain object, without any hidden kinds: the public half of `Rpsc\Game`.
 *
 * A game is `{phase, turn, challenged, quiet, ply, winner, reason, placed, lost, pieces}`, where `pieces` maps a
 * square to `{side, id, king}`. Kinds are not part of it: a challenge is handed the two kinds that were shown.
 */
export const Rules = Object.freeze({
	QUIET_LIMIT
	, home
	, goal
	, steps
	, neighbours
	, beats
	, owner
	, advance

	/** A new game, before either side has placed. */

	, create() {
		return {
			phase: 'placement'
			, turn: 0
			, challenged: false
			, quiet: 0
			, ply: 0
			, winner: null
			, reason: ''
			, placed: [false, false]
			, lost: [[], []]
			, pieces: {}
		};
	}

	/** Stand a side's twelve on its home squares; play begins when both sides have. */

	, place(game, side) {
		if(game.phase !== 'placement' || game.placed[side]) throw new Error('That side cannot place now');

		home(side).forEach((at, id) => { game.pieces[at] = {side, id, king: false}; });

		game.placed[side] = true;

		if(game.placed[0] && game.placed[1])
		{
			game.phase = 'play';
			game.turn = 0;
		}
	}

	/** How many pieces a side has on the board. */

	, count(game, side) {
		return Object.values(game.pieces).filter(piece => piece.side === side).length;
	}

	/** How many of its four far-rank squares a side stands on. */

	, filled(game, side) {
		return goal(side).filter(at => game.pieces[at]?.side === side).length;
	}

	/** The moves open to a side, as [from, to] pairs in board order of the moving piece. */

	, moves(game, side = game.turn) {
		const moves = [];

		if(game.phase !== 'play') return moves;

		for(let at = 0; at < SQUARES; at++)
		{
			const piece = game.pieces[at];

			if(piece?.side !== side) continue;

			for(const target of steps(at, side, piece.king)) if(!game.pieces[target]) moves.push([at, target]);
		}

		return moves;
	}

	/** Every place a side's piece shares an edge with an opposing piece, as [own square, opposing square]. */

	, contacts(game, side) {
		const pairs = [];

		for(let at = 0; at < SQUARES; at++)
		{
			if(game.pieces[at]?.side !== side) continue;

			for(const target of neighbours(at)) if(game.pieces[target]) pairs.push([at, target]);
		}

		return pairs;
	}

	/** The challenges open to the side to act; none once this turn's challenge is used. */

	, challenges(game) {
		return game.phase !== 'play' || game.challenged ? [] : Rules.contacts(game, game.turn);
	}

	/** Throw unless the side to act may challenge from one square to the other. */

	, checkChallenge(game, from, to) {
		if(game.phase !== 'play') throw new Error('The game is not in play');

		if(game.challenged) throw new Error('This turn has already had its challenge');

		if(game.pieces[from]?.side !== game.turn) throw new Error('No piece of the side to act stands there');

		if(!game.pieces[to] || !neighbours(from).includes(to))
		throw new Error('No opposing piece shares an edge with the challenger');
	}

	/** Resolve a challenge between two shown kinds and return the event `Rpsc\Game::challenge()` returns. */

	, challenge(game, from, to, attacker, defender) {
		Rules.checkChallenge(game, from, to);
		const [attacking, defending] = [game.pieces[from], game.pieces[to]];
		let outcome = 'tie', removed = null;

		if(beats(attacker, defender)) [outcome, removed] = ['win', to];
		else if(beats(defender, attacker)) [outcome, removed] = ['loss', from];

		if(removed !== null)
		{
			game.lost[game.pieces[removed].side].push(removed === to ? defender : attacker);
			delete game.pieces[removed];
			game.quiet = 0;
		}

		game.challenged = true;
		const event = {
			type: 'challenge'
			, side: game.turn
			, from
			, to
			, attacker
			, defender
			, attackerId: attacking.id
			, defenderId: defending.id
			, outcome
			, removed
		};

		Rules.settle(game);

		return event;
	}

	/** Move one piece, crown it on the far rank, and end the turn. */

	, move(game, from, to) {
		if(game.phase !== 'play') throw new Error('The game is not in play');

		const piece = game.pieces[from], side = game.turn;

		if(piece?.side !== side) throw new Error('No piece of the side to act stands there');

		if(game.pieces[to] || !steps(from, side, piece.king).includes(to))
		throw new Error('That piece cannot step there');

		const crowned = !piece.king && advance(side, to) === SIZE - 1;
		game.quiet = piece.king ? game.quiet + 1 : 0;
		delete game.pieces[from];
		game.pieces[to] = {...piece, king: piece.king || crowned};
		game.ply++;
		const event = {type: 'move', side, from, to, id: piece.id, crowned};

		if(Rules.filled(game, side) === GOALS)
		{
			Rules.finish(game, side, 'home-row');

			return event;
		}

		game.turn = 1 - side;
		game.challenged = false;
		Rules.settle(game);

		return event;
	}

	/** End the game where the position already decides it: a side with no pieces, or eighty quiet turns. */

	, settle(game) {
		if(game.phase !== 'play') return;

		const side = game.turn;

		if(Rules.count(game, 1 - side) === 0) Rules.finish(game, side, 'elimination');
		else if(Rules.count(game, side) === 0) Rules.finish(game, 1 - side, 'elimination');
		else if(game.quiet >= QUIET_LIMIT) Rules.finish(game, null, 'draw');
	}

	/** Record the result: the winning side or null for a draw, and elimination, home-row, or draw. */

	, finish(game, winner, reason) {
		game.phase = 'over';
		game.winner = winner;
		game.reason = reason;
	}
});

/** Where one seat's private record for one table is kept. */
const storageKey = (root, player) => `rpsc:online:v${ONLINE_VERSION}:${root}:${player}`;
/** Where the list of tables with a private record is kept, with the time each was last written. */
const storageIndex = `rpsc:online:v${ONLINE_VERSION}:index`;

/**
 * The record of a seat another tab plays: readable, never written.
 * @param {Storage} storage The browser's storage.
 * @returns {object} A storage-shaped object whose writes do nothing.
 */
export function readOnlyStorage(storage)
{
	return {
		getItem: key => storage?.getItem(key) ?? null

		, setItem() {}

		, removeItem() {}
	};
}

/** A uniform random integer below a bound of at most 256, from the platform's random source. */
const randomBelow = bound => {
	const limit = 256 - 256 % bound, bytes = new Uint8Array(1);

	for(; ;)
	{
		crypto.getRandomValues(bytes);

		if(bytes[0] < limit) return bytes[0] % bound;
	}
};

/** A copy of a list in a uniformly random order. */
const shuffled = values => {
	const result = [...values];

	for(let index = result.length - 1; index > 0; index--)
	{
		const other = randomBelow(index + 1);
		[result[index], result[other]] = [result[other], result[index]];
	}

	return result;
};

/** Whether a value is twelve kinds with four of each. */
const legalArrangement = value => Array.isArray(value) && value.length === PIECES
	&& [0, 1, 2].every(kind => value.filter(item => item === kind).length === PER_KIND);

/**
 * One online game: the shared state every action is checked against, and this browser's private half of it.
 */
export class OnlineMatch
{

	/**
	 * @param {object} options What the room and the page supply.
	 * @param {object} options.api The private-deck primitive, as `session.mentalPoker`.
	 * @param {string} options.root The table's identity in the room.
	 * @param {string} options.sessionId The match's identity at that table.
	 * @param {string[]} options.players The two seats.
	 * @param {string} options.localPlayer The seat this browser plays or watches.
	 * @param {string} options.breaker The seat the room chose to go first: it plays Black.
	 * @param {number} [options.rounds] Rounds of the placement proof, from 16 to 128.
	 * @param {Storage} [options.storage] Where this browser keeps its key, its placement, and an unfinished proof.
	 * @param {boolean} [options.follow] Another tab plays this seat: read its record before every step, never act.
	 */
	constructor(
		{
			api
			, root
			, sessionId
			, players
			, localPlayer
			, breaker
			, rounds = 64
			, storage = globalThis.localStorage
			, follow = false
		}
	){
		if(!api || typeof api.prepareCorpus !== 'function')
		throw new TypeError('The private-deck primitive is unavailable');

		if(typeof root !== 'string' || !root || typeof sessionId !== 'string' || !sessionId)
		throw new TypeError('An online game needs a room identity');

		if(!Array.isArray(players) || players.length !== 2 || new Set(players).size !== 2
			|| players.some(player => typeof player !== 'string' || !player)
		)
		throw new TypeError('RPSC is for exactly two players');

		if(!players.includes(localPlayer) || !players.includes(breaker))
		throw new TypeError('The local player and the first player must hold seats');

		if(!Number.isSafeInteger(rounds) || rounds < 16 || rounds > 128)
		throw new RangeError('A placement proof needs 16 to 128 rounds');

		this.api = api;
		this.root = root;
		this.players = [...players];
		this.localPlayer = localPlayer;
		// Black moves first, so the seat the room picked to go first is Black.
		this.seats = [breaker, players.find(player => player !== breaker)];
		this.side = this.seats.indexOf(localPlayer);
		this.rounds = rounds;
		this.storage = storage;
		this.follow = Boolean(follow);
		this.keyName = storageKey(root, localPlayer);
		this.context = `rpsc:${ONLINE_VERSION}:${ONLINE_RULES}:${root}:${sessionId}`;

		// Twelve tokens, four of each kind. Token index divided by four is its kind.
		this.corpus = api.prepareCorpus(
			Array.from({length: PIECES}, (_, index) => ({
				kind: KINDS[Math.floor(index / PER_KIND)]
				, copy: index % PER_KIND
			}))
			, {context: `${this.context}:corpus`}
		);

		this.input = api.corpusDeck(this.corpus);
		this.saved = this.loadSaved();
		this.assembler = null;
		this.intent = null;
		this.events = [];
		this.state = {
			v: ONLINE_VERSION
			, rules: ONLINE_RULES
			, sequence: 0
			, stage: 'placement'
			, game: Rules.create()
			, decks: [null, null]
			, layers: [null, null]
			, pending: null
			, duel: null
			, reveal: []
			, revealed: [null, null]
			, outcome: null
		};

		if(!this.follow && !legalArrangement(this.saved.arrangement))
		{
			this.saved.arrangement = shuffled([0, 0, 0, 0, 1, 1, 1, 1, 2, 2, 2, 2]);
			this.save();
		}

		this.pruneTables();
	}

	/**
	 * Read this seat's private record for the table.
	 * @returns {object} The record, or an empty one when nothing usable is stored.
	 */
	loadSaved()
	{
		try
		{
			const value = JSON.parse(this.storage?.getItem(this.keyName) ?? 'null');

			return value && typeof value === 'object' && value.v === ONLINE_VERSION ? value : {v: ONLINE_VERSION};
		}
		catch
		{
			return {v: ONLINE_VERSION};
		}
	}

	/**
	 * Write this seat's private record and note the table as recently used.
	 * @returns {void} A tab that only follows the seat writes nothing.
	 */
	save()
	{
		if(this.follow) return;

		try
		{
			this.storage?.setItem(this.keyName, JSON.stringify(this.saved));
			const index = this.readIndex();
			index[this.keyName] = Date.now();
			this.storage?.setItem(storageIndex, JSON.stringify(index));
		}
		catch
		{
			/* A full or blocked store costs the reload, not the game in this tab. */
		}
	}

	/**
	 * Read the list of tables this browser keeps a record for.
	 * @returns {object} Storage keys mapped to the time each was last written.
	 */
	readIndex()
	{
		try
		{
			const value = JSON.parse(this.storage?.getItem(storageIndex) ?? '{}');

			return value && typeof value === 'object' && !Array.isArray(value) ? value : {};
		}
		catch
		{
			return {};
		}
	}

	/**
	 * Keep the newest tables' records, never this one, and forget the rest.
	 * @param {number} [keep] How many tables to keep, counting this one.
	 * @returns {void} Older records are removed from storage.
	 */
	pruneTables(keep = KEPT_TABLES)
	{
		if(this.follow) return;

		const index = this.readIndex();

		const newestFirst = (a, b) => (Number(index[b]) || 0) - (Number(index[a]) || 0);
		const keys = Object.keys(index).filter(key => key !== this.keyName).sort(newestFirst);

		if(keys.length < keep) return;

		for(const key of keys.slice(Math.max(0, keep - 1)))
		{
			try { this.storage?.removeItem(key); } catch { /* Leave it for the next prune. */ }

			delete index[key];
		}

		try { this.storage?.setItem(storageIndex, JSON.stringify(index)); } catch { /* As above. */ }
	}

	/**
	 * Copy the state both browsers share and hash.
	 * @returns {object} The stage, the game, both decks, and whatever is in progress.
	 */
	publicState() { return clone(this.state); }

	/**
	 * Hash the shared state.
	 * @returns {Promise<string>} SHA-256 of the state written with ordered keys.
	 */
	async hash() { return sha256(stable(this.state)); }

	/**
	 * Find the side a seat plays.
	 * @param {string} player A seat's id in the room.
	 * @returns {number} 0 for Black, 1 for Red, -1 for anyone else.
	 */
	sideOf(player) { return this.seats.indexOf(player); }

	/**
	 * Name the seat that owes the next action.
	 * @returns {string} A seat's id. Once the game is over it names a seat only because the room needs one.
	 */
	currentActor()
	{
		const {stage, game, reveal, outcome} = this.state;

		if(stage === 'placement') return this.seats[game.placed[0] ? 1 : 0];

		if(stage === 'play') return this.seats[game.turn];

		if(stage === 'answer') return this.seats[1 - game.turn];

		if(stage === 'reveal') return this.seats[reveal[0]];

		return outcome?.winner ?? this.seats[0];
	}

	/**
	 * The label a side's placement proof is bound to.
	 * @param {number} side 0 for Black, 1 for Red.
	 * @returns {string} A string no other table, match, or seat shares.
	 */
	placementContext(side) { return `${this.context}:placement:${side}`; }

	/**
	 * The label an opening of one piece is bound to.
	 * @param {number} side The side that owns the piece.
	 * @param {number} id The piece's id, which is also its place in the deck.
	 * @returns {string} A string no other piece shares.
	 */
	openContext(side, id) { return `${this.context}:open:${side}:${id}`; }

	/**
	 * Read this browser's own kinds from its private record.
	 * @returns {number[]|null} Kinds by piece id, or null when the record is gone or does not match its deck.
	 */
	ownKinds()
	{
		const order = this.saved.order;

		if(!Array.isArray(order) || order.length !== PIECES + 1) return null;

		return order.slice(1).map(token => Math.floor((token - 1) / PER_KIND));
	}

	/**
	 * Build this browser's placement once: the key, the order, the encrypted deck, its proof, and the packets.
	 * The record is stored before anything is published, so a reload republishes the same placement.
	 * @returns {object[]} The `placement-packet` actions to send, in order.
	 */
	plan()
	{
		if(this.saved.plan) return this.saved.plan;

		if(!legalArrangement(this.saved.arrangement)) throw new Error('This browser has no placement to publish');

		// Each kind's four tokens are dealt to its squares in a random order, so nothing about a square can be read
		// from which copy stands on it.
		const copies = [0, 1, 2].map(kind => shuffled([0, 1, 2, 3].map(copy => kind * PER_KIND + copy)));
		const order = [0, ...this.saved.arrangement.map(kind => 1 + copies[kind].pop())];
		const key = this.api.generateLayerKey();
		const deck = this.api.applyShuffle(this.input, key, order);

		const proof = this.api.proveShuffle(
			this.input
			, deck
			, key
			, order
			, {context: this.placementContext(this.side), rounds: this.rounds}
		);

		const records = [{type: 'deck', deck}
			, ...this.api.splitShuffleProof(proof, {roundsPerChunk: 8}).map((
				{kind, ...rest}
			) => ({
				type: kind
				, ...rest
			})
			)
		];

		const packets = [];

		for(const record of records)
		{
			const last = packets.at(-1);

			if(last
				&& encoder.encode(
					JSON.stringify({type: 'placement-packet', records: [...last.records, record]})
				).length <= PACKET_BYTES
			)
			last.records.push(record);
			else packets.push({type: 'placement-packet', records: [record]});
		}

		this.saved = {...this.saved, key, order, plan: packets};
		this.save();

		return packets;
	}

	/**
	 * Prove what one of this browser's own pieces is.
	 * @param {number} id The piece's id.
	 * @returns {object} The token it holds and a proof that the published point is that token under this deck's key.
	 */
	open(id)
	{
		const {key, order} = this.saved;

		if(!key || !this.ownKinds()) throw new Error('This browser lost its private placement');

		return this.api.openCard(
			this.state.decks[this.side][id + 1]
			, order[id + 1] - 1
			, key
			, this.state.layers[this.side]
			, this.corpus
			, {context: this.openContext(this.side, id)}
		);
	}

	/**
	 * Check a shown piece against its owner's published deck.
	 * @param {number} side The side that owns the piece.
	 * @param {number} id The piece's id.
	 * @param {object} opening The token claimed and its proof.
	 * @returns {number} The piece's kind. Throws when the proof does not hold.
	 */
	verifyOpening(side, id, opening)
	{
		if(!this.api.verifyCardOpening(
			this.state.decks[side][id + 1]
			, opening
			, this.state.layers[side]
			, this.corpus
			, {context: this.openContext(side, id)}
		))
		throw new Error('A shown piece does not match its placement');

		return Math.floor(opening.card / PER_KIND);
	}

	/**
	 * List a side's pieces still on the board.
	 * @param {number} side 0 for Black, 1 for Red.
	 * @returns {number[]} Piece ids in ascending order.
	 */
	survivors(side)
	{
		const pieces = Object.values(this.state.game.pieces).filter(piece => piece.side === side);

		return pieces.map(piece => piece.id).sort((a, b) => a - b);
	}

	/**
	 * Build the action this browser owes now.
	 * @returns {object|null} The action, or null while it owes nothing or the player has not decided.
	 */
	nextAction()
	{
		if(this.follow || this.currentActor() !== this.localPlayer) return null;

		const {stage, game, pending, duel} = this.state;

		if(stage === 'placement') return this.saved.ready ? this.plan()[pending?.packets ?? 0] ?? null : null;

		if(stage === 'play')
		{
			if(!this.intent) return null;

			const {type, from, to} = this.intent;

			return type === 'move' ? {type, from, to} : {type, from, to, opening: this.open(game.pieces[from].id)};
		}

		if(stage === 'answer') return {type: 'answer', opening: this.open(game.pieces[duel.to].id)};

		if(stage === 'reveal')
		return {
			type: 'reveal'
			, openings: this.survivors(this.side).map(id => ({id, ...this.open(id)}))
		};

		return null;
	}

	/**
	 * Say whether an action ends its batch. A placement is one batch that ends with the proof's tail; every other
	 * action is a step of its own.
	 * @param {object} action An action about to be sent.
	 * @returns {boolean} Whether the room should settle after it.
	 */
	settlesAction(action)
	{
		return action?.type !== 'placement-packet'
			|| Boolean(action.records?.some(record => record.type === 'shuffle-proof-tail'));
	}

	/**
	 * Take one of the player's intents.
	 * @param {object} intent `swap` with home indexes `a` and `b`, `shuffle`, `ready`, or `move` or `challenge` with
	 * its two squares.
	 * @returns {boolean} Whether it was legal now. An accepted move or challenge is sent by the next `nextAction()`.
	 */
	command(intent)
	{
		if(this.follow || !intent) return false;

		const {stage, game} = this.state;

		if(['swap', 'shuffle', 'ready'].includes(intent.type))
		{
			if(stage !== 'placement' || this.saved.ready || game.placed[this.side]) return false;

			const arrangement = [...this.saved.arrangement];

			if(intent.type === 'swap')
			{
				const {a, b} = intent;

				if(!Number.isSafeInteger(a) || !Number.isSafeInteger(b) || a === b || !(a in arrangement)
					|| !(b in arrangement)
				)
				return false;

				[arrangement[a], arrangement[b]] = [arrangement[b], arrangement[a]];
			}

			this.saved = {
				...this.saved
				, arrangement: intent.type === 'shuffle' ? shuffled(arrangement) : arrangement
				, ready: intent.type === 'ready'
			};

			this.save();

			return true;
		}

		if(stage !== 'play' || this.intent || this.currentActor() !== this.localPlayer) return false;

		const pairs = intent.type === 'move'
			? Rules.moves(game)
			: intent.type === 'challenge' ? Rules.challenges(game) : [];

		if(!pairs.some(([from, to]) => from === intent.from && to === intent.to)) return false;

		this.intent = {type: intent.type, from: intent.from, to: intent.to};

		return true;
	}

	/**
	 * Apply one action from the room. Both browsers call this with the same action and must reach the same state.
	 * @param {object} envelope The room's record: its sequence, its actor, the hash it was made against, the action.
	 * @returns {Promise<object>} The settlement to report: the new hash, who acts next, and the outcome if any.
	 */
	async apply({sequence, actor, pre_hash: preHash, action})
	{
		if(!Number.isSafeInteger(sequence) || sequence !== this.state.sequence + 1)
		throw new Error('Online RPSC action is out of sequence');

		if(actor !== this.currentActor()) throw new Error('Online RPSC action came from the wrong player');

		if(!action || !ONLINE_ACTION_TYPES.includes(action.type)) throw new Error('Unknown online RPSC action');

		if(typeof preHash !== 'string' || preHash !== await this.hash())
		throw new Error('Online RPSC action has the wrong parent hash');

		if(this.follow) this.saved = this.loadSaved();

		const before = {state: clone(this.state), events: this.events.length};

		try
		{
			const {stage} = this.state;
			const expected = {placement: 'placement-packet', answer: 'answer', reveal: 'reveal'}[stage];

			if(stage === 'over' || (expected ? action.type !== expected : !['move', 'challenge'].includes(action.type)))
			throw new Error(`A ${action.type} action does not belong to the ${stage} stage`);

			if(stage === 'placement') this.applyPlacement(actor, action);
			else if(action.type === 'move') this.applyMove(action);
			else if(action.type === 'challenge') this.applyChallenge(action);
			else if(action.type === 'answer') this.applyAnswer(action);
			else this.applyReveal(action);
		}
		catch(error)
		{
			this.state = before.state;
			this.events.length = before.events;
			this.assembler = null;

			throw error;
		}

		this.state.sequence = sequence;

		if(actor === this.localPlayer) this.intent = null;

		return {sequence, hash: await this.hash(), nextActor: this.currentActor(), outcome: clone(this.state.outcome)};
	}

	/**
	 * Take one packet of a placement, and check the whole proof when its last record arrives.
	 * @param {string} actor The seat that sent it.
	 * @param {object} action A `placement-packet` action.
	 * @returns {void} The side's twelve pieces stand on the board once the proof holds.
	 */
	applyPlacement(actor, action)
	{
		const side = this.sideOf(actor);

		if(!Array.isArray(action.records) || !action.records.length || action.records.length > 16)
		throw new Error('A placement packet carries 1 to 16 records');

		const pending = this.state.pending ?? {side, deck: null, records: [], packets: 0};

		if(pending.side !== side || pending.packets >= 16)
		throw new Error('This placement does not continue the one in progress');

		// After a reload the proof so far is rebuilt from the records the shared state kept.
		if(!this.assembler)
		{
			this.assembler = new this.api.ShuffleProofAssembler({rounds: this.rounds});

			for(const record of pending.records) this.assembler.add(record);
		}

		let proof = null;

		for(const record of action.records)
		{
			if(record?.type === 'deck')
			{
				if(pending.deck || !Array.isArray(record.deck) || record.deck.length !== PIECES + 1
					|| record.deck.some(point => typeof point !== 'string')
				)
				throw new Error('A placement names its thirteen points once');

				pending.deck = [...record.deck];

				continue;
			}

			if(!pending.deck) throw new Error('A placement starts with its deck');

			const {type, ...rest} = record ?? {};
			const chunk = {kind: type, ...rest};
			proof = this.assembler.add(chunk) ?? proof;
			pending.records.push(chunk);
		}

		pending.packets++;

		if(!proof)
		{
			this.state.pending = pending;

			return;
		}

		// The proof is bound to this seat, so a player cannot answer with a copy of the other's deck.
		if(!this.api.verifyShuffle(
			this.input
			, pending.deck
			, proof
			, {context: this.placementContext(side), rounds: this.rounds}
		))
		throw new Error('The placement proof does not hold');

		this.state.decks[side] = pending.deck;
		this.state.layers[side] = this.api.layerDescriptor(this.input, pending.deck);
		this.state.pending = null;
		this.assembler = null;
		Rules.place(this.state.game, side);

		if(side === this.side && !this.follow)
		{
			// The published deck must be the one this browser can open. A record from another table would not be.
			const {key, order} = this.saved;
			const mine = key && this.ownKinds() ? this.api.applyShuffle(this.input, key, order) : null;

			if(!mine || mine.some((point, index) => point !== pending.deck[index]))
			this.saved = {
				...this.saved
				, key: null
				, order: null
			};

			// The packets are public now; only the key and the order are still needed.
			this.saved = {...this.saved, plan: null, ready: true};
			this.save();
		}

		if(this.state.game.phase === 'play')
		{
			this.state.stage = 'play';
			this.events.push({type: 'start'});
		}
	}

	/**
	 * Apply a move by the side to act.
	 * @param {object} action A `move` action with its two squares.
	 * @returns {void} The turn passes, or the game is decided.
	 */
	applyMove({from, to})
	{
		this.events.push(Rules.move(this.state.game, from, to));
		this.decided();
	}

	/**
	 * Take a challenge: check the challenger's piece and wait for the other player to show theirs.
	 * @param {object} action A `challenge` action with its two squares and the challenger's opening.
	 * @returns {void} The stage becomes `answer`.
	 */
	applyChallenge({from, to, opening})
	{
		const {game} = this.state;
		Rules.checkChallenge(game, from, to);
		const kind = this.verifyOpening(game.turn, game.pieces[from].id, opening);
		this.state.duel = {from, to, kind};
		this.state.stage = 'answer';
	}

	/**
	 * Take the answer to a challenge: check the challenged piece and resolve the two kinds.
	 * @param {object} action An `answer` action with the challenged player's opening.
	 * @returns {void} The loser is gone, and the challenger still has to move unless the game is decided.
	 */
	applyAnswer({opening})
	{
		const {game, duel} = this.state;
		const kind = this.verifyOpening(1 - game.turn, game.pieces[duel.to].id, opening);
		this.state.duel = null;
		this.state.stage = 'play';
		this.events.push(Rules.challenge(game, duel.from, duel.to, duel.kind, kind));
		this.decided();
	}

	/**
	 * Take one side's final reveal of every piece it has left.
	 * @param {object} action A `reveal` action with one opening for each surviving piece, in id order.
	 * @returns {void} The side's kinds are public; the table is done once both sides have revealed.
	 */
	applyReveal({openings})
	{
		const side = this.state.reveal[0], ids = this.survivors(side);

		if(!Array.isArray(openings) || openings.length !== ids.length
			|| openings.some((opening, index) => opening?.id !== ids[index])
		)
		throw new Error('The final reveal shows each surviving piece once');

		this.state.revealed[side] = Object.fromEntries(
			openings.map(({id, ...opening}) => [id, this.verifyOpening(side, id, opening)])
		);

		this.state.reveal.shift();

		if(!this.state.reveal.length) this.finish();
	}

	/**
	 * Move on once the rules have a result: each side with pieces left turns them face up, and then the table is done.
	 * @returns {void} Nothing changes while the game is still in play.
	 */
	decided()
	{
		const {game} = this.state;

		if(game.phase !== 'over') return;

		this.events.push({type: 'over', winner: game.winner, reason: game.reason});
		this.state.reveal = [0, 1].filter(side => this.survivors(side).length);

		if(this.state.reveal.length) this.state.stage = 'reveal';
		else this.finish();
	}

	/**
	 * Close the table with the outcome the room is told.
	 * @returns {void} The outcome names the winning seat, or null for a draw.
	 */
	finish()
	{
		const {game} = this.state;
		this.state.stage = 'over';
		this.state.outcome = {winner: game.winner === null ? null : this.seats[game.winner], reason: game.reason};
	}

	/**
	 * Rebuild the game from the room's history, as after a reload.
	 * @param {object[]} history Every confirmed action, oldest first.
	 * @returns {Promise<string>} The hash of the rebuilt state. Nothing replayed is shown as a new event.
	 */
	async replay(history)
	{
		for(const entry of history) await this.apply(entry);

		this.events = [];

		return this.hash();
	}

	/**
	 * Take the events applied since the last call, for the table to show.
	 * @returns {object[]} start, move, challenge, and over events, oldest first.
	 */
	takeEvents()
	{
		const events = this.events;
		this.events = [];

		return events;
	}

	/**
	 * Describe the game as this seat may see it, in the shape `Rpsc\Session::view()` gives the table.
	 * @param {string[]} [names] Display names for Black and Red.
	 * @returns {object} The view, with `online` set and a `notice` when the player is waiting on something.
	 */
	view(names = SIDES)
	{
		const {stage, game, revealed, pending} = this.state;
		const own = this.ownKinds(), mine = this.side, theirs = 1 - mine;
		const placing = stage === 'placement' && !game.placed[mine];
		const pieces = [];

		for(let at = 0; at < SQUARES; at++)
		{
			const piece = game.pieces[at];

			if(!piece) continue;

			pieces.push({
				key: piece.side * PIECES + piece.id
				, side: piece.side
				, square: at
				, king: piece.king
				, kind: piece.side === mine ? own?.[piece.id] ?? null : revealed[piece.side]?.[piece.id] ?? null
			});
		}

		const acting = stage === 'play' && !this.follow && !this.intent && this.currentActor() === this.localPlayer;
		const counts = [Rules.count(game, 0), Rules.count(game, 1)];
		const opponent = names[theirs];
		let notice = '';

		if(placing && this.saved.ready)
		notice = pending?.side === mine || this.currentActor() === this.localPlayer
			? 'Placing your pieces'
			: `Waiting for ${opponent} to place`;
		else if(stage === 'placement' && !placing) notice = `Waiting for ${opponent} to place`;
		else if(stage === 'answer')
		notice = this.currentActor() === this.localPlayer
			? 'Showing your piece'
			: `Waiting for ${opponent} to show a piece`;
		else if(stage === 'play' && this.intent) notice = 'Sending';
		else if(stage === 'play' && this.currentActor() !== this.localPlayer) notice = `${opponent} is thinking`;

		if(placing) counts[mine] = PIECES;

		return {
			phase: stage === 'placement' ? 'placement' : game.phase
			, turn: game.turn
			, viewer: mine
			, challenged: game.challenged
			, ply: game.ply
			, quiet: game.quiet
			, quietLimit: QUIET_LIMIT
			, winner: game.winner
			, reason: game.reason
			, placed: [...game.placed]
			, pieces
			, counts
			, lost: clone(game.lost)
			, goals: [goal(0), goal(1)]
			, filled: [Rules.filled(game, 0), Rules.filled(game, 1)]
			, moves: acting ? Rules.moves(game) : []
			, challenges: acting ? Rules.challenges(game) : []
			, level: ''
			, human: mine
			, home: home(mine)
			, arrangement: placing && legalArrangement(this.saved.arrangement) ? [...this.saved.arrangement] : null
			, locked: placing && Boolean(this.saved.ready)
			, online: true
			, names: [...names]
			, notice
		};
	}
}

/**
 * Install the online game on the application's browser session.
 * @param {object} host What the engine hands a browser module; only its session is used.
 * @returns {function(): void} Removes the installation.
 */
export function activate({session})
{
	const api = Object.freeze(
		{
			ONLINE_VERSION
			, ONLINE_RULES
			, ONLINE_ACTION_TYPES
			, KEPT_TABLES
			, KINDS
			, SIDES
			, Rules
			, OnlineMatch
			, readOnlyStorage
		}
	);

	session.rpscOnline = api;

	return () => { if(session.rpscOnline === api) delete session.rpscOnline; };
}
