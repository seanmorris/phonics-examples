import {test, expect} from '@playwright/test';
import fs from 'node:fs/promises';

// The canvas is found by its label, not its place: the online lobby moves it out of the game's frame into a stage.

const canvas = page => page.locator('canvas[aria-label="RPSC game canvas"]');

const status = page => canvas(page).evaluate(element => JSON.parse(element.dataset.rpsc ?? 'null'));

const button = (page, label) => page.locator(`.rpsc-targets button[aria-label="${label}"]`);

const name = square => 'abcdefgh'[square % 8] + (Math.floor(square / 8) + 1);

/** The button over a square, whatever its label currently says about the piece on it. */
const square = (page, number) => page.locator(`.rpsc-targets button[aria-label^="${name(number)} "]`);

async function boot(page)
{
	await page.goto('/games/rpsc/');
	await expect(canvas(page)).toHaveAttribute('data-rpsc', /"page":"title"/, {timeout: 45_000});
	await expect(page.locator('.loading')).toHaveClass(/complete/);
}

/** Wait until the table has finished showing events and it is the player's turn, or the game is over. */
async function settled(page)
{
	await expect.poll(async() => {
		const now = await status(page);

		return now.phase === 'over' || (!now.busy && now.turn === now.human);
	}, {timeout: 30_000}).toBe(true);

	return status(page);
}

/** Wait for an action to show in the status, which follows a click by a frame or two. */
async function acted(page, before)
{
	await expect.poll(async() => {
		const now = await status(page);

		return now.phase === 'over' || now.ply !== before.ply || now.challenged !== before.challenged;
	}).toBe(true);
}

async function start(page)
{
	await button(page, 'New game').click();
	await button(page, 'Start').click();
	await expect.poll(async() => (await status(page)).phase).toBe('placement');
}

test('RPSC places, moves, challenges, saves, and resigns', async({page}) => {
	// The game is played in real time: each turn waits for the computer to think and for the pieces to move, and the
	// page boots twice. A hosted runner draws WebGL in software, so allow well over the local twenty seconds.
	test.setTimeout(180_000);
	const errors = [];
	page.on('pageerror', error => errors.push(error.message));
	page.on('console', message => { if(message.type() === 'error') errors.push(message.text()); });

	await boot(page);
	await expect(button(page, 'New game')).toBeVisible();
	await expect(button(page, 'Continue')).toHaveCount(0);
	await start(page);

	// Placement: twelve of the player's pieces face up, four of each kind, and the opponent's twelve face down.
	let now = await status(page);
	const own = now.pieces.filter(piece => piece[1] === now.human);
	expect(own).toHaveLength(12);
	expect([0, 1, 2].map(kind => own.filter(piece => piece[2] === kind).length)).toEqual([4, 4, 4]);
	expect(now.pieces.filter(piece => piece[1] !== now.human).every(piece => piece[2] === null)).toBe(true);
	expect(now.counts).toEqual([12, 12]);

	// Swap two pieces of different kinds, then check that they traded squares.
	const first = own[0];
	const second = own.find(piece => piece[2] !== first[2]);
	await square(page, first[0]).click();
	await expect.poll(async() => (await status(page)).selected).toBe(first[0]);
	await square(page, second[0]).click();
	await expect.poll(async() => (await status(page)).pieces.find(piece => piece[0] === first[0])[2]).toBe(second[2]);
	expect((await status(page)).pieces.find(piece => piece[0] === second[0])[2]).toBe(first[2]);

	await button(page, 'Shuffle').click();
	await button(page, 'Ready').click();
	await expect.poll(async() => (await status(page)).phase).toBe('play');
	now = await settled(page);
	expect(now.status).toBe('Your move');
	expect(now.moves).toHaveLength(7);
	await expect(button(page, 'Shuffle')).toHaveCount(0);

	// The keyboard walks a cursor over the board and picks up a piece.
	await canvas(page).focus();
	await page.keyboard.press('ArrowRight');
	await expect.poll(async() => (await status(page)).cursor).toBeGreaterThanOrEqual(0);
	const cursor = (await status(page)).cursor;
	await page.keyboard.press('Enter');
	await expect.poll(async() => (await status(page)).selected).toBe(cursor);
	await page.keyboard.press('Escape');
	await expect.poll(async() => (await status(page)).selected).toBe(null);

	// Advance on the opponent until the armies touch, then challenge.
	let duel = null;

	for(let turn = 0; turn < 40 && !duel; turn++)
	{
		now = await settled(page);
		expect(now.phase).toBe('play');

		if(now.challenges.length)
		{
			const [from, to] = now.challenges[0];
			await square(page, from).click();
			await expect.poll(async() => (await status(page)).victims).toContain(to);
			await expect(square(page, to)).toHaveAttribute('aria-label', /challenge$/);
			await square(page, to).click();
			await acted(page, now);
			duel = {from, to, before: now};
			break;
		}

		const forward = now.human === 0 ? 1 : -1;

		const [from, to] = [...now.moves].sort(
			(a, b) => forward * (Math.floor(b[1] / 8) - Math.floor(a[1] / 8)) || a[0] - b[0]
		)[0];

		await square(page, from).click();
		await expect.poll(async() => (await status(page)).targets).toContain(to);
		await expect(square(page, to)).toHaveAttribute('aria-label', /move here$/);
		await square(page, to).click();
		await acted(page, now);
	}

	expect(duel).not.toBe(null);

	// While the challenge is shown, the status says what met what.
	await expect.poll(async() => (await status(page)).status).toMatch(/ beats | meets /);
	now = await settled(page);

	if(now.phase === 'play')
	{
		// The computer may have challenged on an earlier turn, so everything is counted from just before this one.
		const {before} = duel;
		expect(now.challenged).toBe(true);
		expect(now.challenges).toEqual([]);
		expect(now.sounds.flip).toBe((before.sounds.flip ?? 0) + 1);
		// One piece left, or neither did: and whatever was shown is hidden again.
		expect(now.counts[0] + now.counts[1]).toBeGreaterThanOrEqual(before.counts[0] + before.counts[1] - 1);
		expect(now.lost[0].length + now.lost[1].length).toBe(24 - now.counts[0] - now.counts[1]);
		expect(now.pieces.filter(piece => piece[1] !== now.human).every(piece => piece[2] === null)).toBe(true);
		expect(now.status).toBe('Now move a piece');
	}

	// The game in progress survives a reload.
	const saved = await settled(page);
	await page.reload();
	await expect(canvas(page)).toHaveAttribute('data-rpsc', /"page":"title"/, {timeout: 45_000});
	await button(page, 'Continue').click();
	await expect.poll(async() => (await status(page)).page).toBe('game');
	now = await status(page);
	expect(now.ply).toBe(saved.ply);
	expect(now.pieces).toEqual(saved.pieces);
	expect(now.challenged).toBe(saved.challenged);

	// Resigning ends the game, turns every piece face up, and offers another.
	await button(page, 'Menu').click();
	await expect.poll(async() => (await status(page)).page).toBe('pause');
	await button(page, 'Resign').click();
	await button(page, 'Resign this game').click();
	await expect.poll(async() => (await status(page)).phase).toBe('over');
	await expect(button(page, 'Play again')).toBeVisible();
	now = await status(page);
	expect(now.reason).toBe('resign');
	expect(now.winner).toBe(1 - now.human);
	expect(now.status).toBe('You resigned');
	expect(now.pieces.every(piece => piece[2] !== null)).toBe(true);
	await button(page, 'Play again').click();
	await expect.poll(async() => (await status(page)).phase).toBe('placement');
	expect(errors).toEqual([]);
});

test('RPSC fits tall and wide frames at whole-number scales', async({page}) => {
	for(const [width, height, mode] of [[720, 1000, 'portrait'], [1280, 720, 'landscape'], [390, 760, 'portrait']])
	{
		await page.setViewportSize({width, height});
		await boot(page);
		await start(page);
		await expect.poll(async() => (await status(page)).width).toBeGreaterThan(0);
		const now = await status(page);
		expect(now.layout.mode).toBe(mode);
		expect(Number.isInteger(now.layout.scale)).toBe(true);
		// The board and every button lie inside the frame.
		expect(now.layout.x).toBeGreaterThanOrEqual(0);
		expect(now.layout.y).toBeGreaterThanOrEqual(0);
		expect(now.layout.x + now.layout.size).toBeLessThanOrEqual(now.width);
		expect(now.layout.y + now.layout.size).toBeLessThanOrEqual(now.height);

		for(const region of now.regions)
		{
			const [x, y, w, h] = region.rect;
			expect(x, region.id).toBeGreaterThanOrEqual(0);
			expect(y, region.id).toBeGreaterThanOrEqual(0);
			expect(x + w, region.id).toBeLessThanOrEqual(now.width);
			expect(y + h, region.id).toBeLessThanOrEqual(now.height);
		}

		expect(now.regions.map(region => region.id)).toEqual(expect.arrayContaining(['shuffle', 'ready', 'menu']));
	}
});

/**
 * Seat the page at a table without Matrix. The page's own adapter is moved from the lobby's Matrix-backed protocol
 * to an in-memory room from the engine, where a scripted second player waits: ready at once, then a challenge half
 * the time one is open, otherwise a random step. The canvas is moved into the lobby's stage, as for a seated game.
 */
async function seatOnline(page)
{
	const sources = await Promise.all(
		['mental-poker.mjs', 'online-match.mjs'].map(
			name => fs.readFile(new URL(`../../games/rpsc/browser/${name}`, import.meta.url), 'utf8')
		)
	);

	return page.evaluate(async([primitive, match]) => {

		const load = source => import(URL.createObjectURL(new Blob([source], {type: 'text/javascript'})));
		const {InMemoryLobbyHub, InMemoryLobbyProvider} = await import(
			'/engine/browser/network/InMemoryLobbyProvider.mjs'
		);

		const {RoomGameProtocol} = await import('/engine/browser/network/RoomGameLobby.mjs');
		const api = await load(primitive), {OnlineMatch, ONLINE_ACTION_TYPES, ONLINE_RULES} = await load(match);

		const memory = () => {
			const values = new Map;

			return {
				getItem: key => values.get(key) ?? null
				, setItem: (key, value) => values.set(key, value)
				, removeItem: key => values.delete(key)
			};
		};

		const hub = new InMemoryLobbyHub;
		const game = {
			id: 'org.example.rpsc'
			, protocol: '1.0.0'
			, rules: ONLINE_RULES
			, application: 'app'
			, runtime: 'runtime'
			, actionTypes: [...ONLINE_ACTION_TYPES]
			, maximumActionBytes: 49152
			, checkpointMode: 'history'
			, actionBatches: true
		};

		const make = id => new RoomGameProtocol(
			{provider: new InMemoryLobbyProvider({hub, peerId: id}), game, session: {}, storage: memory()}
		);

		const alice = make('alice'), bob = make('bob');

		const canvas = document.querySelector('canvas[aria-label="RPSC game canvas"]');
		const real = canvas.rpscDebug.lobby.protocol;

		for(const name of ['submitAction', 'reportReady', 'reportSettled', 'dispute', 'resign', 'leave', 'snapshot'])

		real[name] = (...args) => alice[name](...args);

		alice.attachGame(real.adapter);
		const client = globalThis.rpscOpponent = {
			match: null
			, work: Promise.resolve()
			, active: false
			, submitting: false
			, awaiting: false
			, stopped: null
			, error: null
		};

		const enqueue = task => {
			client.work = client.work.then(task).catch(error => { client.error = String(error?.message ?? error); });
		};

		const drive = async() => {
			if(!client.match || !client.active || client.submitting || client.awaiting || client.stopped
				|| client.match.currentActor() !== 'bob'
			)
			return;

			let action = client.match.nextAction();

			if(!action)
			{
				const view = client.match.view();
				const challenge = view.challenges.length && Math.random() < .5, pairs = challenge
					? view.challenges
					: view.moves;

				if(view.arrangement && !view.locked) client.match.command({type: 'ready'});
				else if(pairs.length)
				client.match.command({type: challenge ? 'challenge' : 'move', from: pairs[0][0], to: pairs[0][1]});

				action = client.match.nextAction();
			}

			if(!action) return;

			client.submitting = true;
			await port.submitAction(action, await client.match.hash(), {settle: client.match.settlesAction(action)});
		};

		const port = bob.attachGame({
			start: detail => enqueue(async() => {
				client.match = new OnlineMatch({api, ...detail, localPlayer: 'bob', storage: memory()});
				await port.reportReady(await client.match.replay(detail.history ?? []));
			})
			, action: detail => enqueue(async() => {
				const settlement = await client.match.apply(detail);

				if(detail.batch_end !== false) client.awaiting = true;

				if(await port.reportSettled(settlement)) return;

				client.awaiting = false;
				client.submitting = false;
				await drive();
			})
			, confirmed: () => enqueue(
				async() => { client.active = true; client.awaiting = false; client.submitting = false; await drive(); }
			)
			, stop: detail => { client.stopped = detail ?? {}; }
		});

		await alice.connect();
		await bob.connect();
		await bob.join(await alice.create());
		const room = document.querySelector('.phonics-room-game');
		room.classList.add('is-open', 'has-game');
		room.querySelector('.phonics-room-stage').append(canvas);
	}, sources
	);
}

test('RPSC plays an online game through the room: hidden placements, a challenge, and a resignation', async({page}) => {
	// Both placements are proved and checked in the page, and every turn waits on a second player.
	test.setTimeout(240_000);
	const errors = [];
	page.on('pageerror', error => errors.push(error.message));
	page.on('console', message => { if(message.type() === 'error') errors.push(message.text()); });

	await page.setViewportSize({width: 1100, height: 760});
	await boot(page);

	// Play online opens the engine's lobby, which asks a signed-out player to sign in.
	await button(page, 'Play online').click();
	await expect(page.locator('.phonics-room-game')).toHaveClass(/is-open/);
	await seatOnline(page);

	// Seated: the title gives way to a placement that is the player's to arrange.
	await expect.poll(
		async() => { const now = await status(page); return `${now.online} ${now.page} ${now.phase}`; }
		, {timeout: 60_000}
	).toBe('true game placement');

	let now = await status(page);
	const me = now.human, other = 1 - me;
	expect(now.pieces.filter(piece => piece[1] === me && piece[2] !== null)).toHaveLength(12);
	// The buttons followed the canvas into the lobby's stage and still lie over it.
	const [frame, ready] = await Promise.all([canvas(page).boundingBox(), button(page, 'Ready').boundingBox()]);
	expect(ready.x).toBeGreaterThanOrEqual(frame.x);
	expect(ready.y + ready.height).toBeLessThanOrEqual(frame.y + frame.height + 1);
	await button(page, 'Ready').click();
	await expect(button(page, 'Shuffle')).toHaveCount(0);

	// Both placements arrive as proofs; then all twenty-four pieces stand, and the other twelve are face down.
	await expect.poll(async() => (await status(page)).phase, {timeout: 120_000}).toBe('play');
	now = await status(page);
	expect(now.pieces).toHaveLength(24);
	expect(now.pieces.filter(piece => piece[1] === other).every(piece => piece[2] === null)).toBe(true);

	expect(
		await button(
			page
			, `${name(now.pieces.find(piece => piece[1] === other)[0])} ${['Black', 'Red'][other]} piece face down`
		).count()
	).toBe(1);

	// Play until this page has made a challenge, or the game ends first.
	let challenged = false;

	for(let turn = 0; turn < 60 && !challenged; turn++)
	{
		await expect.poll(
			async() => {
				const state = await status(page);

				return state.phase === 'over' || (!state.busy && state.moves.length > 0);
			}
			, {timeout: 60_000}
		).toBe(true);

		now = await status(page);

		if(now.phase === 'over') break;

		const forward = me === 0 ? 1 : -1;
		const [from, to] = now.challenges[0]
			?? [...now.moves].sort((a, b) => forward * (Math.floor(b[1] / 8) - Math.floor(a[1] / 8)) || a[0] - b[0])[0];

		challenged = now.challenges.length > 0;
		await square(page, from).click();
		await expect.poll(async() => (await status(page)).selected).toBe(from);
		await square(page, to).click();
		await acted(page, now);
	}

	if(challenged)
	{
		// Both pieces were opened with proofs and are shown; afterwards the survivor is hidden again.
		await expect.poll(async() => (await status(page)).status, {timeout: 30_000}).toMatch(/ beats | meets /);

		await expect.poll(
			async() => {
				const state = await status(page);

				return state.phase === 'over' || (!state.busy && state.challenged);
			}
			, {timeout: 30_000}
		).toBe(true);

		now = await status(page);

		if(now.phase === 'play')
		expect(now.pieces.filter(piece => piece[1] === other).every(piece => piece[2] === null)).toBe(true);
	}

	now = await status(page);

	if(now.phase === 'play')
	{
		// Resigning online goes through the room: both players are told, and the other one wins.
		await button(page, 'Menu').click();
		await expect.poll(async() => (await status(page)).page).toBe('pause');
		await expect(button(page, 'Quit')).toHaveCount(0);
		await button(page, 'Resign').click();
		await button(page, 'Resign this game').click();
		await expect.poll(async() => (await status(page)).phase, {timeout: 30_000}).toBe('over');
		now = await status(page);
		expect(now.status).toBe('You resigned');
		expect(now.winner).toBe(other);

		const told = () => page.evaluate(() => globalThis.rpscOpponent.stopped);
		await expect.poll(told).toEqual({winner: 'bob', reason: 'resigned'});
	}

	await expect(button(page, 'Play again')).toHaveCount(0);
	expect(await page.evaluate(() => globalThis.rpscOpponent.error)).toBe(null);
	expect(errors).toEqual([]);
});
