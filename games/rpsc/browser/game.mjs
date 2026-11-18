const SOUNDS = Object.freeze({
	move: ['assets/move.wav', .55]
	, flip: ['assets/flip.wav', .6]
	, take: ['assets/take.wav', .75]
	, tie: ['assets/tie.wav', .5]
	, crown: ['assets/crown.wav', .55]
	, win: ['assets/win.wav', .6]
	, lose: ['assets/lose.wav', .55]
});

const LEVELS = Object.freeze(['easy', 'normal', 'hard']);
const SIDES = Object.freeze(['black', 'red', 'random']);

/** Room rejections that sending again cannot fix. */
export const TERMINAL_ROOM_ERRORS = Object.freeze([
	'It is not your turn'
	, 'Local state differs from the confirmed turn state'
	, 'The game supplied an invalid quantized action'
	, 'No matching action is settling', 'This browser is not seated at a table'
]);

/** The default for a table that never ends on its own. */
const always = () => true;

/** The default for a caller that does not want to hear about retries. */
const nothing = () => undefined;

/** Wait a number of milliseconds. */
const pause = delay => new Promise(resolve => setTimeout(resolve, delay));

/**
 * Publish one room write, trying again while the connection is down. A dropped connection is not a dispute: the
 * table waits, says it is reconnecting from the third failure on, and carries on when the room accepts the write.
 * @param {function(): Promise<*>} operation The write.
 * @param {object} [options] When to give up and how to report.
 * @param {function(): boolean} [options.live] False once the table has ended or the lobby has closed.
 * @param {function(boolean): void} [options.reconnecting] Told when retries start and when they succeed.
 * @param {function(number): Promise<void>} [options.sleep] Waits between tries; tests replace it.
 * @returns {Promise<*>} The write's result, or undefined when the table ended first.
 */
export async function retryRoomWrite(operation, options = {})
{
	const {live = always, reconnecting = nothing, sleep = pause} = options;

	for(let attempt = 0; ; attempt++)
	{
		try
		{
			const result = await operation();

			if(attempt) reconnecting(false);

			return result;
		}
		catch(error)
		{
			if(TERMINAL_ROOM_ERRORS.includes(error instanceof Error ? error.message : String(error))) throw error;

			if(!live()) return undefined;

			if(attempt >= 2) reconnecting(true);

			await sleep(Math.min(30000, 500 * 2 ** attempt));

			if(!live()) return undefined;
		}
	}
}

/**
 * Storage for one run of a table in this tab: reads always pass, writes only while `playing()` holds. The private
 * record is shared by every tab and the room lets one tab play a seat, so a tab that loses the seat stops writing at
 * once and cannot erase what the new tab keeps.
 * @param {Storage} storage The browser's storage.
 * @param {function(): boolean} playing Whether this run still holds the seat.
 * @returns {object} A storage-shaped object.
 */
export function seatStorage(storage, playing)
{
	return Object.freeze({
		getItem: key => storage?.getItem(key) ?? null
		, setItem: (key, value) => { if(playing()) storage?.setItem(key, value); }
		, removeItem: key => { if(playing()) storage?.removeItem(key); }
	});
}

/**
 * A short name for a seat: the profile's display name, or the local part of a Matrix id.
 * @param {string} id The seat's id in the room.
 * @param {object} [profile] The provider's profile for it.
 * @returns {string} At most sixteen characters.
 */
export function playerName(id, profile = null)
{
	const value = String(id ?? '').trim();
	const name = profile?.displayName?.trim() || /^@([^:]+):/.exec(value)?.[1] || value || 'Player';

	return name.length > 16 ? `${name.slice(0, 15)}.` : name;
}

/**
 * Words for a table the room ended before the rules did: someone resigned, left, or ran out of time.
 * @param {object} detail What the room reported: the winning seat if it named one, the reason, and whether it was a
 * timeout. The player who leaves is told only `{reason: 'left'}`.
 * @param {boolean} won Whether this browser's seat is the winner.
 * @param {string} opponent The other player's name.
 * @returns {string} One line for the status row.
 */
export function endedNotice(detail, won, opponent)
{
	const reason = detail?.reason;

	if(detail?.timeout || reason === 'timeout')
	return won ? `You win: ${opponent} ran out of time` : 'You ran out of time';

	if(reason === 'left') return won ? `You win: ${opponent} left the table` : 'You left the table';

	if(reason === 'resigned') return won ? `You win: ${opponent} resigned` : 'You resigned';

	return 'The table ended';
}

/**
 * The browser half of RPSC: the frame, storage, sounds, and the accessible buttons over the canvas.
 *
 * PHP owns the offline rules, the bot, and every pixel. This module gives it what PHP cannot reach: the size of the
 * frame, a place to keep the unfinished game and the options, a random seed, and sample playback. It mirrors the
 * regions PHP reports as real buttons, so the board works with a pointer, a keyboard, and a screen reader. For online
 * play it joins the engine's room lobby to the match in online-match.mjs and hands PHP that match's view to draw.
 * @param {object} host What the engine's host hands a game module.
 * @returns {Promise<void>} Resolves once the bridges are in place.
 */
export async function activate({module, canvas, assets, packageUrl, services, session, onClose})
{
	const shell = new services.GameShell({module, canvas, session, title: 'RPSC'});
	shell.context({antialias: false});
	const {signal} = shell;

	const gameKey = 'rpsc.game.v1';
	const optionsKey = 'rpsc.options.v1';
	const muteKey = 'rpsc.mute.v1';
	const storage = new services.SafeStorage;

	/** Make an element with a class. */
	const element = (tag, className) => Object.assign(document.createElement(tag), {className});

	const ui = element('section', 'rpsc-ui');
	const screen = element('div', 'rpsc-screen');
	const targets = element('div', 'rpsc-targets');
	const status = element('p', 'rpsc-sr');
	const style = document.createElement('style');
	ui.setAttribute('aria-label', 'Rock Paper Scissors Checkers');
	status.setAttribute('aria-live', 'polite');
	style.textContent = `
		.rpsc-ui{position:relative;display:flex;align-items:center;justify-content:center;width:100%;height:100%;
			max-width:none;margin:auto;background:#11131a;color:#f5ebd1}
		.rpsc-screen{position:relative;width:100%;height:100%;isolation:isolate;overflow:hidden;background:#11131a}
		.rpsc-screen>canvas{position:absolute;inset:0;display:block;width:100%;height:100%;max-width:none;
			max-height:none;margin:0;border:0;border-radius:0;image-rendering:pixelated;touch-action:none}
		.rpsc-targets{position:absolute;inset:0;pointer-events:none;z-index:1}
		.rpsc-targets button{position:absolute;pointer-events:auto;padding:0;border:0;border-radius:0;
			background:transparent;color:transparent;box-shadow:none;touch-action:manipulation;cursor:pointer}
		.rpsc-targets button:is(:hover,:focus,:active){background:transparent;color:transparent;box-shadow:none}
		.rpsc-targets button:focus-visible{outline:2px solid #ffdc80;outline-offset:-2px}
		.rpsc-sr{position:absolute!important;width:1px!important;height:1px!important;padding:0!important;
			margin:-1px!important;overflow:hidden!important;clip-path:inset(50%)!important;white-space:nowrap!important;border:0!important}
		.rpsc-screen .quick-controls{right:6px;top:4px;bottom:auto;z-index:4;padding:0;opacity:.8}
		.rpsc-screen .quick-controls button{width:30px;height:30px;padding:4px}
		.rpsc-ui:fullscreen{display:flex;align-items:center;justify-content:center;width:100%;height:100%;max-width:none}
		@media(pointer:coarse){.rpsc-screen .quick-controls button{width:44px;height:44px}}
	`;

	shell.mount(ui, {screen});
	ui.append(style, screen, status);
	screen.append(targets);
	const quick = new services.QuickControls(screen, ui);
	let state = null;
	const sounded = Object.fromEntries(Object.keys(SOUNDS).map(name => [name, 0]));

	const safeJson = (value, fallback) => {
		try { return JSON.parse(value); } catch { return fallback; }
	};

	const stored = safeJson(storage.get(optionsKey) ?? '{}', {});
	const options = {
		version: 1
		, level: LEVELS.includes(stored?.level) ? stored.level : 'normal'
		, side: SIDES.includes(stored?.side) ? stored.side : 'black'
		, effects: Number.isFinite(stored?.effects) ? Math.max(0, Math.min(100, Math.round(stored.effects))) : 60
	};

	const saveOptions = () => storage.set(optionsKey, JSON.stringify(options));

	// PHP draws at the frame's own pixel size, so the pixel art and the text are both set in whole device pixels. The
	// canvas itself is measured, not the frame around it: the online lobby moves the canvas into its own stage.
	const view = shell.measure({
		observe: canvas
		, cssSize: false
		, dprCap: 2
		, initial: '[720,960,1,false]'
		, format: (width, height, dpr, box, coarse) => JSON.stringify(
			[Math.max(1, Math.round(width)), Math.max(1, Math.round(height)), dpr, coarse]
		)
	});

	let samples = null;

	const mute = shell.mute({
		key: muteKey
		, parse: value => {
			const saved = safeJson(value, {});

			return saved?.version === 1 && saved.muted === true;
		}
		, serialize: muted => JSON.stringify({version: 1, muted})
		, quick
		, onChange: muted => {
			samples?.setMuted(muted);

			if(samples && !muted) void unlock();
		}
	});

	samples = new services.SamplePlayer(module, assets, packageUrl, {muted: mute.muted});

	/**
	 * Start audio after the first gesture and load the samples once.
	 * @returns {Promise<void>} Resolves when the samples can play, or at once when audio is unavailable.
	 */
	async function unlock()
	{
		if(!await samples.unlock()) return;

		await Promise.all(Object.entries(SOUNDS).map(([name, [path]]) => samples.load(name, path)));
	}

	const play = name => {
		void unlock().then(() => samples.play(name, {gain: SOUNDS[name][1] * options.effects / 100}));
	};

	shell.fullscreen({target: ui, quick, onChange: () => view.measure()});
	shell.suppressContextMenu();

	canvas.addEventListener('focus', () => {
		if(document.fullscreenElement === ui) return;

		// Centering honours the page's scroll padding, so a sticky header never covers the top of the board.
		screen.scrollIntoView({block: 'center', inline: 'nearest', behavior: 'auto'});
	}, {signal});

	canvas.addEventListener('keydown', () => { void unlock(); }, {signal});

	canvas.addEventListener('pointerdown', () => {
		canvas.focus({preventScroll: true});
		void unlock();
	}, {signal});

	const focus = () => { canvas.focus({preventScroll: true}); void unlock(); };

	const action = shell.action('rpscAction');
	const buttons = new services.RegionButtons(targets, {signal, focus, onPress: id => action.act(id)});

	shell.bridge('rpscViewport', () => view.viewport());
	shell.bridge('rpscSeed', () => shell.seed31());
	shell.bridge('rpscLoad', () => storage.get(gameKey) ?? '');

	shell.bridge('rpscSave', value => {
		value = String(value);

		return value === '' ? storage.remove(gameKey) : storage.set(gameKey, value);
	});

	shell.bridge('rpscOptionGet', name => String(options[String(name)] ?? ''));

	shell.bridge('rpscOptionSet', (name, value) => {
		name = String(name);
		value = String(value);

		if(name === 'level' && LEVELS.includes(value)) options.level = value;
		else if(name === 'side' && SIDES.includes(value)) options.side = value;
		else if(name === 'effects' && Number.isFinite(Number(value)))
		options.effects = Math.max(0, Math.min(100, Math.round(Number(value))));
		else return false;

		return saveOptions();
	});

	// Online play. The engine's lobby owns the room and its protocol, online-match.mjs owns the game, and this
	// section carries actions between them the way the room expects: apply, report, wait for both reports, then act.
	// The lobby and the two online modules are installed after this one, so they are looked up when first needed.

	const onlineApi = () => session.rpscOnline ?? null;
	let port = null, online = null, onlineJson = '', onlineEvents = [];
	let work = Promise.resolve(), seatRun = 0, names = ['Black', 'Red'];
	// `active`: the room has confirmed both browsers start from the same state, so actions may be sent. `awaiting`:
	// an action is applied here but the room has not confirmed it, so nothing may be sent yet. `ended`: the room
	// closed the table.
	let active = false, watching = false, submitting = false, awaiting = false, reconnecting = false, tableEnded = true
		, ended = null, failure = '';

	const publish = () => {
		if(!online)
		{
			onlineJson = '';

			return;
		}

		onlineEvents.push(...online.takeEvents());
		const next = online.view(names);

		// The player may decide as soon as the other player's action is shown, even while the room is still confirming
		// it: the decision is kept and sent when the confirmation arrives. Only a table that is over, or one this tab
		// merely watches, offers nothing.
		if(watching || tableEnded)
		{
			next.moves = [];
			next.challenges = [];
		}

		if(watching) next.notice = 'Watching: this seat is being played in another tab';

		if(reconnecting) next.notice = 'Reconnecting';

		if(ended)
		{
			const {winner, notice} = ended;
			Object.assign(next, {phase: 'over', winner, reason: 'resign', notice});
			Object.assign(next, {arrangement: null, moves: [], challenges: []});
		}

		if(failure) next.notice = 'The table stopped: an action could not be verified';

		onlineJson = JSON.stringify(next);
	};

	const fail = error => {
		failure = error instanceof Error ? error.message : String(error);
		console.error('RPSC online protocol failed', error);

		// A transcript this browser cannot verify freezes the table; it does not concede the game.
		try { port?.dispute(failure); } catch { /* The room is already gone. */ }

		submitting = false;
		publish();
	};

	// Work queued before the latest start belongs to a retired run: a tab that lost its seat expects its writes
	// to fail.

	const enqueue = task => {
		const run = seatRun;
		work = work.then(task).catch(error => { if(run === seatRun) fail(error); });

		return work;
	};

	const roomWrite = operation => {
		const run = seatRun;

		return retryRoomWrite(operation, {
			live: () => Boolean(online) && !tableEnded && run === seatRun
			, reconnecting: value => {
				if(reconnecting !== value)
				{
					reconnecting = value;
					publish();
				} }
		});
	};

	const drive = async() => {
		if(!online || !active || watching || submitting || awaiting || tableEnded
			|| online.currentActor() !== online.localPlayer
		)
		return;

		const move = online.nextAction();

		if(!move) return;

		const preHash = await online.hash(), settle = online.settlesAction(move);
		submitting = true;
		publish();

		try
		{
			await roomWrite(() => port.submitAction(move, preHash, {settle}));
		}
		catch(error)
		{
			submitting = false;
			throw error;
		}
	};

	/** Forget the table: no game is held, nothing is owed, and nothing is waiting to be shown. */
	const idle = () => {
		online = null;
		ended = null;
		failure = '';
		onlineEvents = [];

		active = false;
		watching = false;
		awaiting = false;
		submitting = false;
		reconnecting = false;
		tableEnded = true;
	};

	const attach = () => {
		if(port || !session.lobby || !onlineApi() || !session.mentalPoker) return Boolean(port);

		port = session.lobby.attachGame({
			// A standby start means another tab plays this seat: show its pieces read-only. The room starts again
			// when the seat moves between tabs.
			start: detail => {
				const run = ++seatRun, standby = Boolean(detail.standby), seat = standby
					? detail.seat
					: detail.localPlayer;

				return enqueue(async() => {
					const snapshot = port.snapshot?.();
					const known = [snapshot?.profile, ...(snapshot?.peers ?? [])].filter(Boolean);
					const profiles = new Map(known.map(profile => [profile.id, profile]));

					tableEnded = false;
					reconnecting = false;
					watching = standby;
					ended = null;
					failure = '';
					active = false;
					onlineEvents = [];

					// A seat this tab plays keeps its record until another tab takes the seat; a seat it only
					// watches is read from the other tab's record.
					const record = standby
						? {storage: onlineApi().readOnlyStorage(globalThis.localStorage), follow: true}
						: {storage: seatStorage(globalThis.localStorage, () => run === seatRun)};

					const {root, sessionId, players, breaker} = detail;
					const table = {api: session.mentalPoker, root, sessionId, players, localPlayer: seat, breaker};
					const match = new (onlineApi().OnlineMatch)({...table, ...record});

					const hash = await match.replay(detail.history ?? []);

					if(detail.sequence !== match.publicState().sequence)
					throw new Error('Room history ended at the wrong sequence');

					if(detail.sequence > 0 && detail.hash !== hash)
					throw new Error('Room history does not match its confirmed hash');

					names = match.seats.map(id => playerName(id, profiles.get(id)));
					online = match;
					submitting = false;
					awaiting = false;
					publish();

					// The room answers both players' reports with a confirmation, and play starts from there.
					if(!standby) await roomWrite(() => port.reportReady(hash));
				});
			}
			, action: detail => enqueue(async() => {
				if(!online) throw new Error('Received an RPSC action before the table started');

				const settlement = await online.apply(detail);

				if(watching)
				{
					publish();

					return;
				}

				if(detail.batch_end !== false) awaiting = true;

				publish();
				const waiting = await roomWrite(() => port.reportSettled(settlement));

				if(waiting) return;

				// A packet inside a batch needs no confirmation: the next one may follow at once.
				awaiting = false;
				submitting = false;
				publish();
				await drive();
			})
			, confirmed: detail => enqueue(async() => {
				if(!online) throw new Error('Received an RPSC confirmation before the table started');

				if(detail.sequence !== online.publicState().sequence || detail.hash !== await online.hash())
				throw new Error('Room confirmation does not match this browser\'s game');

				active = true;
				awaiting = false;
				submitting = false;
				publish();
				await drive();
			})
			, stop: detail => {
				tableEnded = true;

				return enqueue(async() => {
					reconnecting = false;
					awaiting = false;
					submitting = false;

					// The rules finish most games themselves. The room finishes the rest: a player left or timed out.
					if(online && !online.publicState().outcome && !ended)
					{
						// The room names the winner to everyone except a player who walks away, who has conceded.
						const mine = online.side, named = detail?.winner ? online.sideOf(detail.winner) : -1;
						const winner = named >= 0 ? named : detail?.reason === 'left' ? 1 - mine : null;
						ended = {winner, notice: endedNotice(detail, winner === mine, names[1 - mine])};
						onlineEvents.push({type: 'over', winner, reason: 'resign'});
					}

					publish();
				});
			}
		});

		session.lobby.protocol.addEventListener('closed', () => {
			idle();
			seatRun++;
			publish();
		}, {signal});

		return true;
	};

	shell.bridge('rpscOnlineReady', () => Boolean(session.lobby && onlineApi() && session.mentalPoker));

	shell.bridge('rpscLobby', () => {
		if(!attach()) return false;

		void unlock();

		// A signed-out player is shown the sign-in card; that is the lobby working, not a failure.
		session.lobby.open().catch(
			error => { if(error?.code !== 'matrix_auth_required') console.error('RPSC lobby failed to open', error); }
		);

		return true;
	});

	shell.bridge('rpscOnline', () => onlineJson);

	shell.bridge('rpscOnlineEvents', () => {
		const json = JSON.stringify(onlineEvents);
		onlineEvents = [];

		return json;
	});

	shell.bridge('rpscCommand', json => {
		const intent = JSON.parse(String(json));

		if(!online || watching) return false;

		// Resigning concedes the game and stays at the table to see it; leaving the table is the lobby's own button.
		if(intent?.type === 'resign')
		{
			if(tableEnded || online.publicState().outcome) return false;

			void enqueue(() => roomWrite(() => port.resign('resigned')));

			return true;
		}

		// The match refuses an intent that is not legal now, and keeps at most one. `drive` sends it at once, or when
		// the room confirms the step before it.
		if(tableEnded || !online.command(intent)) return false;

		publish();
		void enqueue(drive);

		return true;
	});

	// The lobby lifts the canvas out of this frame into its own stage for a seated game. The buttons over the canvas
	// follow it, as a sibling laid over the canvas's own box.

	const follow = () => {
		const parent = canvas.parentElement;

		if(!parent) return;

		if(targets.parentElement !== parent) parent.append(targets);

		const away = parent !== screen;

		const lengths = [canvas.offsetLeft, canvas.offsetTop, canvas.offsetWidth, canvas.offsetHeight];
		const [left, top, width, height] = lengths.map(length => away ? `${length}px` : '');
		Object.assign(targets.style, {inset: away ? 'auto' : '0', left, top, width, height});
	};

	shell.status('rpscStatus', {
		dataset: 'rpsc'
		, handler: next => {
			const first = state === null;
			state = next;

			for(const name of Object.keys(SOUNDS))
			{
				const count = next.sounds?.[name] ?? 0;

				// A page that opens on a game already in progress has nothing to catch up on, and a stalled tab
				// replays at most two of what it missed.
				if(first || count - sounded[name] > 2) sounded[name] = first ? count : count - 2;

				while(sounded[name] < count)
				{
					sounded[name]++;
					play(name);
				}
			}

			follow();
			buttons.sync(next.width, next.height, next.regions);

			if(status.textContent !== next.status) status.textContent = next.status;
		}
	});

	/** What a test may read of the online table: the shared state, the view PHP was given, and the step flags. */
	const tableDebug = () => {
		if(!online) return null;

		const flags = {awaiting, submitting, watching, ended};

		return {state: online.publicState(), view: JSON.parse(onlineJson || 'null'), ...flags};
	};

	const debug = {
		get state() { return state; }
		, get options() { return structuredClone(options); }
		, get saved() { return storage.get(gameKey) ?? ''; }
		, get online() { return tableDebug(); }
		// Tests seat this page at an in-memory room through the lobby's own protocol.
		, get lobby() { return session.lobby ?? null; }
	};

	session.rpsc = debug;
	Object.defineProperty(canvas, 'rpscDebug', {configurable: true, value: debug});

	shell.onClose(async() => {
		quick.close();
		await samples.close();
		delete canvas.rpscDebug;
		delete session.rpsc;
	});

	onClose(() => shell.close());
}
