import {test, expect} from '@playwright/test';

const canvas = page => page.locator('.rpsc-screen > canvas');
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
		const [from, to] = [...now.moves].sort((a, b) => forward * (Math.floor(b[1] / 8) - Math.floor(a[1] / 8)) || a[0] - b[0])[0];
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
		expect(now.challenged).toBe(true);
		expect(now.challenges).toEqual([]);
		expect(now.sounds.flip).toBe(1);
		// One piece left, or neither did: and whatever was shown is hidden again.
		expect(now.counts[0] + now.counts[1]).toBeGreaterThanOrEqual(23);
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
