import {test, expect} from '@playwright/test';

const HUD_WIDTH = 230, PLAYFIELD_LEFT = 8, PLAYFIELD_WIDTH = 624;
const SCREEN_WIDTH = HUD_WIDTH + PLAYFIELD_WIDTH;

const run = (page, code) => page.evaluate(async code => {
	const result = await previewSession.php.run('<?php ' + code);

	if(result) throw new Error('PHP gameplay probe failed');
}, code
);

const probe = async page => {
	await run(page, String.raw`
		$p = $field->registry()->get($field->paddle, \PhpNoid\Component\Paddle::class);
		$balls = array_map(fn($id) => $field->registry()->get($id, \PhpNoid\Component\Ball::class),
			$field->registry()->query(\PhpNoid\Component\Ball::class));
		(vrzno_env('canvas'))->setAttribute('data-probe', json_encode([
			'x'=>$p->x, 'width'=>$p->width, 'balls'=>$balls,
			'shots'=>count($field->registry()->query(\PhpNoid\Component\Projectile::class))
		]));
	`
	);

	return JSON.parse(await page.locator('canvas').getAttribute('data-probe'));
};

const state = page => page.locator('canvas').evaluate(canvas => JSON.parse(canvas.dataset.phpNoid));

const loadGame = async (page, query = '') => {
	await page.goto('/php-noid/' + query);
	await expect(page.locator('#status')).toHaveText('Ready', {timeout: 60000});
	await expect(page.locator('.php-noid-title')).toBeVisible();
	await page.locator('[data-action="start"]').click();
	await expect(page.locator('.php-noid-title')).toBeHidden();
};

test('title menu starts the campaign and renders scrollable PHP info', async ({page}) => {
	await page.goto('/php-noid/');
	await expect(page.locator('#status')).toHaveText('Ready', {timeout: 60000});
	const canvas = page.locator('canvas');
	const title = page.locator('.php-noid-title');
	await expect(title).toBeVisible();
	expect((await state(page)).title).toBe(true);
	expect((await state(page)).paused).toBe(true);
	await canvas.focus();
	await page.keyboard.press('ArrowDown');
	await expect(page.locator('[data-action="php-info"]')).toHaveClass(/is-selected/);
	await page.keyboard.press('Enter');
	await expect(title).toBeHidden();
	await expect.poll(async () => (await state(page)).phpInfo.ready).toBe(true);
	expect((await state(page)).phpInfo.markupBytes).toBeGreaterThan(10000);
	expect((await state(page)).hud).toMatchObject({mode: 'title', selection: 'php-info'});
	await page.keyboard.press('PageDown');
	await expect.poll(async () => (await state(page)).phpInfo.scroll).toBeGreaterThan(0);
	await expect(page.locator('.php-noid-info-back')).toBeVisible();
	expect((await state(page)).phpInfo.theme).toBe('keyboard');
	await page.locator('.php-noid-info-back').click();
	await expect(title).toBeVisible();
	await expect(page.locator('.php-noid-info-back')).toBeHidden();
	await page.keyboard.press('ArrowUp');
	await expect(page.locator('[data-action="start"]')).toHaveClass(/is-selected/);
	await page.keyboard.press('Enter');
	await expect(title).toBeHidden();
	await expect.poll(async () => (await state(page)).title).toBe(false);
	await expect.poll(async () => (await state(page)).frames).toBeGreaterThan(2);
	await page.click('#stop');
});

test('PHP-Noid mouse control, capsules, firing, pause, lethal attacks and cleanup', async ({page}) => {
	const errors = [];
	page.on('pageerror', error => errors.push(error.message));
	await loadGame(page);
	const canvas = page.locator('canvas');
	await expect(page.locator('.php-noid-legend span')).toHaveCount(11);
	const box = await canvas.boundingBox();

	const point = x => ({
		x: box.x + box.width * (HUD_WIDTH + x - PLAYFIELD_LEFT) / SCREEN_WIDTH
		, y: box.y + box.height * .85
	});

	let cursor = point(200);
	await page.mouse.move(cursor.x, cursor.y);
	await expect.poll(async () => (await probe(page)).x).toBeCloseTo(200, 0);
	await page.mouse.click(cursor.x, cursor.y);
	await expect.poll(async () => (await probe(page)).balls[0].attached).toBe(false);

	await page.keyboard.press('p');
	await expect.poll(async () => (await state(page)).paused).toBe(true);
	const paused = (await state(page)).frames;
	cursor = point(500);
	await page.mouse.move(cursor.x, cursor.y);
	await page.mouse.click(cursor.x, cursor.y);
	await expect.poll(async () => (await state(page)).frames).toBe(paused);
	await page.mouse.click(cursor.x, cursor.y, {button: 'right'});
	await expect.poll(async () => (await state(page)).paused).toBe(false);
	await expect.poll(async () => (await state(page)).frames).toBeGreaterThan(paused);

	await run(page, String.raw`
		$p=$field->registry()->get($field->paddle, \PhpNoid\Component\Paddle::class);
		$field->registry()->create(new \PhpNoid\Component\Capsule($p->x, $p->y-25, 'expand', 500));
	`
	);

	await expect.poll(async () => (await probe(page)).width).toBe(132);
	await expect(page.locator('.php-noid-hud')).toContainText('Expand');
	await run(page, "$field->power('laser');");
	await page.mouse.down();
	await expect.poll(async () => (await probe(page)).shots).toBeGreaterThanOrEqual(2);
	await page.mouse.up();

	await run(page, String.raw`
		$field->power('split'); $field->power('catch');
		$p=$field->registry()->get($field->paddle, \PhpNoid\Component\Paddle::class);
		$shot=new \PhpNoid\Component\Projectile($p->x, $p->y-35, 'killer', 1500, 'enemy');
		$field->registry()->create($shot);
	`
	);

	await expect.poll(async () => (await state(page)).lives).toBe(2);
	await expect.poll(async () => (await probe(page)).balls.length).toBe(1);
	await expect.poll(async () => (await probe(page)).width).toBe(84);
	await page.click('#stop');
	await expect(page.locator('#status')).toHaveText('Stopped');
	await expect(page.locator('.php-noid-ui')).toHaveCount(0);
	await expect(canvas).not.toHaveAttribute('data-php-noid');
	expect(await canvas.evaluate(element => element.parentElement.id)).toBe('game-frame');
	expect(errors).toEqual([]);
});

test('PHP-Noid keeps a bounded persistent leaderboard through continue and reload', async ({page}) => {
	await loadGame(page);

	for(let attempt = 1; attempt <= 12; attempt++)
	{
		await run(page, `$field->state->score=${attempt * 100}; $field->state->lives=1; $field->loseLife();`);
		await expect.poll(async () => (await state(page)).phase).toBe('gameover');
		// Let the release from the previous continue reach the terminal-state latch.
		await expect.poll(async () => (await state(page)).confirmReady).toBe(true);
		await page.keyboard.press('Space');
		await expect.poll(async () => (await state(page)).phase).toBe('playing');
	}

	await expect(page.locator('.php-noid-scores li')).toHaveCount(10);
	await expect(page.locator('.php-noid-scores li').first()).toContainText('1200 points');
	await expect(page.locator('.php-noid-best')).toHaveText('001200');
	await expect(page.locator('.php-noid-scores li').last()).toContainText('300 points');
	await page.reload();
	await expect(page.locator('#status')).toHaveText('Ready', {timeout: 60000});
	await expect(page.locator('.php-noid-scores li')).toHaveCount(10);
	await expect(page.locator('.php-noid-scores li').first()).toContainText('1200 points');
	await page.click('#stop');
});

test('PHP-Noid fullscreen fits the arena, preserves its aspect ratio and keeps controls working', async ({page}) => {
	await page.setViewportSize({width: 1440, height: 900});
	await loadGame(page);
	const canvas = page.locator('canvas');
	const fullscreen = page.locator('#fullscreen');
	const original = await canvas.boundingBox();
	const screen = page.locator('.php-noid-screen');
	const initial = await screen.boundingBox();

	expect(Math.abs(initial.width / initial.height - 16 / 9)).toBeLessThan(.005);
	expect(await canvas.getAttribute('width')).toBe(String(SCREEN_WIDTH));
	expect(await canvas.getAttribute('height')).toBe('480');
	expect((await state(page)).hud).toMatchObject({width: HUD_WIDTH, height: 480, game: 'PHP/NOID'});
	expect((await state(page)).hud.glyphs).toBeGreaterThan(20);
	await fullscreen.click();
	await expect.poll(() => page.evaluate(() => document.fullscreenElement?.id)).toBe('game-frame');
	await expect(fullscreen).toHaveAttribute('aria-label', 'Exit fullscreen');
	await expect(fullscreen).toHaveAttribute('aria-pressed', 'true');
	await expect(canvas).toBeFocused();
	await expect.poll(async () => (await canvas.boundingBox()).height).toBeGreaterThan(700);

	const checkFit = async () => {
		const bounds = await canvas.boundingBox();
		const viewport = page.viewportSize();
		const display = await screen.boundingBox();
		const button = await fullscreen.boundingBox();
		expect(Math.abs(bounds.width / bounds.height - 427 / 240)).toBeLessThan(.005);
		expect(Math.abs(display.width / display.height - 16 / 9)).toBeLessThan(.005);
		expect(bounds.width).toBeCloseTo(display.width, 0);
		expect(bounds.height).toBeCloseTo(display.height, 0);
		expect(display.x).toBeGreaterThanOrEqual(-1);
		expect(display.y).toBeGreaterThanOrEqual(-1);
		expect(display.x + display.width).toBeLessThanOrEqual(viewport.width + 1);
		expect(display.y + display.height).toBeLessThanOrEqual(viewport.height + 1);
		expect(button.y + button.height).toBeLessThan(display.y + display.height);
		expect(button.y + button.height).toBeGreaterThan(display.y + display.height - 20);
		expect(button.y + button.height).toBeLessThanOrEqual(viewport.height + 1);

		return bounds;
	};

	await checkFit();
	await page.keyboard.press('Space');
	await expect.poll(async () => (await probe(page)).balls[0].attached).toBe(false);
	const initialX = (await probe(page)).x;
	await page.keyboard.down('ArrowLeft');
	await expect.poll(async () => (await probe(page)).x).toBeLessThan(initialX - 10);
	await page.keyboard.up('ArrowLeft');

	await page.setViewportSize({width: 600, height: 900});
	await expect.poll(async () => (await screen.boundingBox()).width).toBeCloseTo(600, 0);
	const portrait = await checkFit();
	expect(portrait.width).toBeCloseTo(600, 0);

	await page.mouse.move(
		portrait.x + portrait.width * (HUD_WIDTH + 160 - PLAYFIELD_LEFT) / SCREEN_WIDTH
		, portrait.y + portrait.height * .8
	);

	// SDL integer event coordinates can quantize by two world pixels at this CSS scale.
	await expect.poll(async () => Math.abs((await probe(page)).x - 160)).toBeLessThanOrEqual(2);
	await page.setViewportSize({width: 360, height: 800});
	await expect.poll(async () => (await screen.boundingBox()).width).toBeCloseTo(360, 0);
	await checkFit();
	await page.keyboard.press('p');
	await expect(page.locator('.php-noid-prompt')).toContainText('Paused');
	expect((await state(page)).hud).toMatchObject({minimumScale: 2, integerScale: true});
	await page.setViewportSize({width: 1440, height: 900});
	await fullscreen.click();
	await expect.poll(() => page.evaluate(() => document.fullscreenElement)).toBeNull();
	await expect(fullscreen).toHaveAttribute('aria-label', 'Fullscreen');
	await expect(fullscreen).toHaveAttribute('aria-pressed', 'false');
	await expect(canvas).toBeFocused();
	await expect.poll(async () => (await canvas.boundingBox()).width).toBeCloseTo(original.width, 0);
	await page.click('#stop');
	await expect(page.locator('#status')).toHaveText('Stopped');
});

test('PHP-Noid fullscreen recovers from denial, browser exits and session disposal', async ({page}) => {
	const errors = [];
	page.on('pageerror', error => errors.push(error.message));
	await loadGame(page);
	const fullscreen = page.locator('#fullscreen');
	const canvas = page.locator('canvas');

	await page.evaluate(() => {
		const frame = document.querySelector('#game-frame');

		frame.requestFullscreen = () => Promise.reject(new DOMException('Fullscreen denied', 'NotAllowedError'));
	});

	await fullscreen.click();
	await expect(page.locator('#fullscreen-error')).toHaveText('Fullscreen denied');
	await expect(fullscreen).toBeEnabled();
	await expect(fullscreen).toHaveAttribute('aria-pressed', 'false');
	await expect(canvas).toBeFocused();
	await page.evaluate(() => { delete document.querySelector('#game-frame').requestFullscreen; });

	await fullscreen.click();
	await expect.poll(() => page.evaluate(() => document.fullscreenElement?.id)).toBe('game-frame');
	await expect(page.locator('#fullscreen-error')).toBeEmpty();
	await page.evaluate(() => document.exitFullscreen());
	await expect(fullscreen).toHaveAttribute('aria-pressed', 'false');
	await expect(canvas).toBeFocused();
	await fullscreen.click();
	await expect.poll(() => page.evaluate(() => document.fullscreenElement?.id)).toBe('game-frame');
	await page.evaluate(() => previewSession.dispose());
	await expect.poll(() => page.evaluate(() => document.fullscreenElement)).toBeNull();
	await expect(page.locator('.php-noid-ui')).toHaveCount(0);
	await expect(page.locator('.php-noid-frame')).toHaveCount(0);
	await expect(page.locator('.quick-controls')).toHaveCount(0);
	expect(await canvas.evaluate(element => element.style.getPropertyValue('--php-noid-fullscreen-width'))).toBe('');
	expect(errors).toEqual([]);
});

test('side console follows power expiry, pause, stage changes, life loss and continue', async ({page}) => {
	await loadGame(page);
	await run(page, "$field->state->score=12345; $field->power('laser');");
	await expect(page.locator('.php-noid-score')).toHaveText('012345');
	await expect(page.locator('.php-noid-best')).toHaveText('012345');
	await expect(page.locator('.php-noid-power')).toHaveText('Twin lasers');
	await expect(page.locator('.php-noid-power-meter')).toHaveAttribute('max', '20');
	await page.keyboard.press('p');
	await expect(page.locator('.php-noid-prompt')).toContainText('Paused');
	const remaining = await page.locator('.php-noid-power-meter').evaluate(meter => meter.value);
	await page.waitForTimeout(1100);
	expect(await page.locator('.php-noid-power-meter').evaluate(meter => meter.value)).toBe(remaining);

	await run(
		page
		, String.raw`$field->registry()->get($field->paddle, \PhpNoid\Component\Paddle::class)->powers['laser']=.05;`
	);

	await page.keyboard.press('p');
	await expect(page.locator('.php-noid-standard')).not.toHaveAttribute('hidden');
	await expect(page.locator('.php-noid-power-meter')).toHaveCount(0);
	await run(page, '($field->loadStage)(7);');
	await expect(page.locator('.php-noid-level')).toHaveText('08 / 25');
	await expect(page.locator('.php-noid-stage-name')).toHaveText('Verdant Circuit');
	await run(page, '$field->state->lives=1; $field->loseLife();');
	await expect(page.locator('.php-noid-lives')).toHaveText('× 0');
	await expect(page.locator('.php-noid-life-icons i:not([hidden])')).toHaveCount(0);
	await expect(page.locator('.php-noid-prompt')).toContainText('Game over');
	await expect.poll(async () => (await state(page)).hud.promptLines).toEqual(['GAME OVER', 'FIRE TO CONTINUE']);
	await page.keyboard.press('Space');
	await expect(page.locator('.php-noid-lives')).toHaveText('× 3');
	await expect(page.locator('.php-noid-life-icons i:not([hidden])')).toHaveCount(3);
	await expect(page.locator('.php-noid-level')).toHaveText('08 / 25');
	await page.click('#stop');
});

test('stacked powers keep separate HUD timers and fit the compact console', async ({page}) => {
	await loadGame(page);
	await run(page, "foreach(['expand','catch','laser','power','slow'] as $kind) $field->power($kind);");
	await page.keyboard.press('p');
	const powers = page.locator('.php-noid-power-row');
	await expect(powers).toHaveCount(5);
	await expect(page.locator('[data-power="expand"]')).toContainText('Expand');
	await expect(page.locator('[data-power="catch"]')).toContainText('Catch');
	await expect(page.locator('[data-power="laser"]')).toContainText('Twin lasers');
	await expect(page.locator('[data-power="power"]')).toContainText('Power ball');
	await expect(page.locator('[data-power="slow"]')).toContainText('Slow ball');
	await page.setViewportSize({width: 360, height: 800});
	await page.click('#fullscreen');
	await expect.poll(() => page.evaluate(() => !!document.fullscreenElement)).toBe(true);
	expect((await state(page)).hud.powers).toBe(5);
	expect((await state(page)).hud).toMatchObject({minimumScale: 2, integerScale: true});
	const before = (await state(page)).powers;

	await run(
		page
		, "$field->power('shrink'); $field->power('missile'); $field->power('fast'); $field->power('extra');"
	);

	await expect(powers).toHaveCount(5);
	await expect(page.locator('[data-power="expand"], [data-power="laser"], [data-power="slow"]')).toHaveCount(0);
	const after = (await state(page)).powers;
	expect(after.catch).toBe(before.catch);
	expect(after.power).toBe(before.power);
	expect(after.shrink).toBe(15);
	expect(after.missile).toBe(15);
	expect(after.fast).toBe(15);

	await run(
		page
		, String.raw`$field->registry()->get($field->paddle, \PhpNoid\Component\Paddle::class)->powers['missile']=.05;`
	);

	await page.keyboard.press('p');
	await expect(page.locator('[data-power="missile"]')).toHaveCount(0);
	await expect(powers).toHaveCount(4);
	await expect.poll(async () => (await probe(page)).width).toBe(52);
	await run(page, '$field->loseLife();');
	await expect(powers).toHaveCount(0);
	await expect(page.locator('.php-noid-standard')).not.toHaveAttribute('hidden');
	await page.evaluate(() => previewSession.dispose());
});

test('PHP-Noid uses shared icon shortcuts with persistent mute and keyboard focus', async ({page}) => {
	const requests = [];
	page.on('request', request => requests.push(request.url()));
	await loadGame(page);
	const mute = page.getByRole('button', {name: 'Mute audio', exact: true});
	await expect(page.locator('.quick-controls button')).toHaveCount(2);
	await expect(mute.locator('svg')).toHaveCount(1);
	await expect(mute.locator('.sound-waves')).toBeVisible();
	await expect(mute.locator('.sound-off')).toBeHidden();
	const controlBox = await mute.boundingBox();
	expect(controlBox.width).toBe(40);
	expect(controlBox.height).toBe(40);
	await mute.click();
	await expect(page.locator('#mute')).toHaveAttribute('aria-label', 'Unmute audio');
	await expect(page.locator('#mute .sound-off')).toBeVisible();
	await expect(page.locator('#mute .sound-waves')).toBeHidden();
	await expect(page.locator('canvas')).toBeFocused();
	await page.reload();
	await expect(page.locator('#status')).toHaveText('Ready', {timeout: 60000});
	await expect(page.locator('#mute')).toHaveAttribute('aria-pressed', 'true');
	await page.locator('#mute').focus();
	await page.keyboard.press('Enter');
	await expect(page.locator('#mute')).toHaveAttribute('aria-pressed', 'false');
	await expect(page.locator('canvas')).toBeFocused();
	expect(requests.some(url => url.includes('sane-phonics'))).toBe(false);
	await page.click('#stop');
	await expect(page.locator('.quick-controls')).toHaveCount(0);
	await page.getByRole('button', {name: 'Run application', exact: true}).click();
	await expect(page.locator('#status')).toHaveText('Ready', {timeout: 60000});
	await expect(page.locator('.quick-controls')).toHaveCount(1);
	await page.click('#stop');
});

test('Level 6 ball escapes the gold lane without damaging gold or losing a life', async ({page}) => {
	await loadGame(page);
	await run(page, '($field->loadStage)(5);');
	await expect(page.locator('.php-noid-level')).toHaveText('06 / 25');

	await run(page, String.raw`
		$b=$field->registry()->get($field->registry()->query(\PhpNoid\Component\Ball::class)[0], \PhpNoid\Component\Ball::class);
		$b->x=200; $b->y=188; $b->vx=400; $b->vy=0; $b->attached=false;
	`
	);

	await expect.poll(async () => Math.abs((await probe(page)).balls[0].y - 188)).toBeGreaterThan(16);
	expect((await state(page)).lives).toBe(3);

	await run(page, String.raw`
		$gold=array_filter($field->registry()->query(\PhpNoid\Component\Brick::class), fn($id) => $field->registry()->get($id, \PhpNoid\Component\Brick::class)->unbreakable);
		if(count($gold)!==4) throw new \RuntimeException('Recovery damaged the gold');
	`
	);

	await page.click('#stop');
});

test('Level 5 escapes a diagonal corner-to-gold cycle at unchanged speed', async ({page}) => {
	await loadGame(page);
	await run(page, '($field->loadStage)(4);');
	await expect(page.locator('.php-noid-level')).toHaveText('05 / 25');

	// Reproduce the late-level loop after the destructible lattice has been cleared.
	await run(page, String.raw`
		$r=$field->registry();
		foreach($r->query(\PhpNoid\Component\Brick::class) as $id)
			if(!$r->get($id, \PhpNoid\Component\Brick::class)->unbreakable) $r->destroy($id);
		foreach($r->query(\PhpNoid\Component\Enemy::class) as $id) $r->destroy($id);
		$r->create(new \PhpNoid\Component\Brick(280,350,80,20,100));
		$b=$r->get($r->query(\PhpNoid\Component\Ball::class)[0], \PhpNoid\Component\Ball::class);
		$b->x=523; $b->y=173-1/256; $b->vx=-400; $b->vy=-400*160/614; $b->attached=false;
	`
	);

	await expect.poll(async () => (await probe(page)).balls[0].y, {timeout: 12000, intervals: [100]})
		.toBeGreaterThan(204);

	const ball = (await probe(page)).balls[0];
	expect(Math.hypot(ball.vx, ball.vy)).toBeCloseTo(Math.hypot(400, 400 * 160 / 614), 5);
	expect((await state(page)).lives).toBe(3);
	await page.click('#stop');
});

test('level changes fade around delayed loading, show a title, and pause before play resumes', async ({page}) => {
	let release;
	const ready = new Promise(resolve => { release = resolve; });

	await page.route('**/php-noid/worlds/campaign.world?delayed', async route => {
		const response = await route.fetch();
		const world = await response.json();
		world.maps[1].fileName += '?delayed';
		await route.fulfill({response, json: world});
	});

	await page.route('**/php-noid/worlds/stage-02.tmj?delayed', async route => {
		await ready;
		await route.continue().catch(() => {});
	});

	try
	{
		await loadGame(page, '?world=' + encodeURIComponent('/engine/packages/php-noid/worlds/campaign.world?delayed'));
		await run(page, "$field->power('expand'); $field->power('exit');");
		await expect.poll(async () => (await state(page)).phase).toBe('clearing');
		await expect(page.locator('.php-noid-transition-title')).toHaveText('Level clear');
		await expect(page.locator('.php-noid-level')).toHaveText('01 / 25');
		await expect.poll(async () => (await state(page)).phase).toBe('loading');
		expect((await state(page)).fade).toBe(1);
		const powers = (await state(page)).powers;
		await page.keyboard.down('Space');
		await page.waitForTimeout(350);
		expect((await state(page)).powers).toEqual(powers);
		await expect(page.locator('.php-noid-level')).toHaveText('01 / 25');
		release();
		await expect.poll(async () => (await state(page)).phase).toBe('entering');
		await expect(page.locator('.php-noid-transition-label')).toHaveText('LEVEL 02');
		await expect(page.locator('.php-noid-transition-title')).toHaveText('Copper Steps');
		await page.keyboard.press('p');
		await expect.poll(async () => (await state(page)).paused).toBe(true);
		const paused = await state(page);
		expect(paused.phase).toBe('entering');
		await page.waitForTimeout(500);
		expect((await state(page)).transitionTime).toBe(paused.transitionTime);
		expect((await probe(page)).balls[0].attached).toBe(true);
		await page.keyboard.press('p');
		await expect.poll(async () => (await state(page)).phase).toBe('playing');
		await expect(page.locator('.php-noid-transition')).toBeHidden();
		expect((await state(page)).fade).toBe(0);
		expect((await probe(page)).balls[0].attached).toBe(true);
		await page.keyboard.up('Space');
		await page.keyboard.press('Space');
		await expect.poll(async () => (await probe(page)).balls[0].attached).toBe(false);
		await run(page, "$field->power('exit');");
		await expect.poll(async () => (await state(page)).phase).toBe('clearing');
		await page.click('#stop');
		await expect(page.locator('#status')).toHaveText('Stopped');
		await expect(page.locator('.php-noid-ui, .quick-controls')).toHaveCount(0);
	}
	finally
	{
		release();
	}
});


test('all 25 campaign stages stream, advance, finish and replay', async ({page}) => {
	test.setTimeout(180000);
	const errors = [];
	page.on('pageerror', error => errors.push(error.message));
	await loadGame(page);
	expect((await state(page)).stageCount).toBe(25);

	for(let stage = 0; stage < 25; stage++)
	{
		await expect.poll(async () => (await state(page)).phase).toBe('playing');
		expect((await state(page)).stage).toBe(stage);

		await run(page, String.raw`
			$r=$field->registry();
			foreach($r->query(\PhpNoid\Component\Brick::class) as $id) {
				$b=$r->get($id,\PhpNoid\Component\Brick::class);
				if(!$b->unbreakable) $field->damage($id,$b->hits,false,'red');
			}
		`
		);

		if(stage < 24)
		await expect.poll(async () => (await state(page)).stage, {timeout: 15000}).toBe(stage + 1);
	}

	await expect.poll(async () => (await state(page)).phase).toBe('complete');
	await expect.poll(async () => (await state(page)).confirmReady).toBe(true);
	await page.locator('canvas').focus();
	await page.keyboard.press('Space');
	await expect.poll(async () => (await state(page)).stage).toBe(0);
	await expect(page.locator('.php-noid-level')).toHaveText('01 / 25');
	await page.click('#stop');
	expect(errors).toEqual([]);
});

test('Konami code grants infinite lives through SDL keyboard input', async ({page}) => {
	await loadGame(page);
	await page.locator('canvas').focus();
	const code = [
		'ArrowUp', 'ArrowUp', 'ArrowDown', 'ArrowDown'
		, 'ArrowLeft', 'ArrowRight', 'ArrowLeft', 'ArrowRight', 'b', 'a'
	];

	for(const key of code.slice(0, -1)) await page.keyboard.press(key, {delay: 50});
	expect((await state(page)).infiniteLives).toBe(false);
	await page.keyboard.press('a', {delay: 50});
	await expect.poll(async () => (await state(page)).infiniteLives).toBe(true);
	await run(page, '$field->state->lives = 1; for($i = 0; $i < 5; $i++) $field->loseLife();');
	await expect.poll(async () => (await state(page)).lives).toBe(1);
	expect((await state(page)).phase).toBe('playing');
	await run(page, '$field->restart();');
	await expect.poll(async () => (await state(page)).infiniteLives).toBe(false);
});

test('title uses one SDL layout with readable integer-scaled labels at window sizes', async ({page}) => {
	await page.goto('/php-noid/');
	await expect(page.locator('#status')).toHaveText('Ready', {timeout: 60000});

	for(const width of [1440, 640, 390])
	{
		await page.setViewportSize({width, height: 900});

		const screen = page.locator('.php-noid-screen');
		const canvasWidth = await screen.evaluate(element => element.getBoundingClientRect().width);

		await expect.poll(async () => (await state(page)).hud.displayWidth).toBe(canvasWidth);
		const before = (await state(page)).hud;
		expect(before.mode).toBe('title');
		expect(Number.isInteger(before.textScale)).toBe(true);
		expect(7 * before.textScale / before.pixelRatio).toBeGreaterThanOrEqual(10);
		await page.screenshot({path: `/tmp/php-noid-title-${width}.png`});
		await page.locator('[data-action="php-info"]').click();
		await expect.poll(async () => (await state(page)).phpInfo.ready).toBe(true);
		const modal = (await state(page)).hud;
		expect(modal).toMatchObject({...before, selection: 'php-info'});
		await page.screenshot({path: `/tmp/php-noid-info-${width}.png`});
		await page.locator('.php-noid-info-back').click();
		await expect(page.locator('.php-noid-title')).toBeVisible();
	}
});

for(const deviceScaleFactor of [1, 1.25, 2])
test(`title preserves every N pixel at display density ${deviceScaleFactor}`, async ({browser}) => {
	const context = await browser.newContext({viewport: {width: 640, height: 900}, deviceScaleFactor});
	const page = await context.newPage();

	try
	{
		await page.goto('/php-noid/');
		await expect(page.locator('#status')).toHaveText('Ready', {timeout: 60000});

		for(const fullscreen of [false, true])
		{
			if(fullscreen) await page.locator('#fullscreen').click();

			await expect.poll(() => page.locator('canvas').evaluate(canvas => {
				const box = canvas.getBoundingClientRect();

				return canvas.width === Math.round(box.width * devicePixelRatio)
					&& canvas.height === Math.round(box.height * devicePixelRatio);
			})).toBe(true);

			const png = (await page.screenshot({fullPage: true})).toString('base64');

			const evidence = await page.evaluate(async png => {
				const canvas = document.querySelector('canvas');
				const hud = JSON.parse(canvas.dataset.phpNoid).hud;
				const bounds = canvas.getBoundingClientRect();
				const originX = Math.round((bounds.x + scrollX) * devicePixelRatio);
				const originY = Math.round((bounds.y + scrollY) * devicePixelRatio);
				const image = new Image();
				image.src = 'data:image/png;base64,' + png;
				await image.decode();
				const copy = document.createElement('canvas');
				copy.width = image.width;
				copy.height = image.height;
				const ctx = copy.getContext('2d');
				ctx.drawImage(image, 0, 0);
				const pixels = ctx.getImageData(0, 0, copy.width, copy.height).data;
				const rows = ['10001', '11001', '10101', '10011', '10001', '10001', '10001'];
				const results = [];

				for(const [text, advance] of [['PHP INFO', 28], ['UP DOWN SELECT', 34], ['ENTER / START', 6]])
				{
					const label = hud.labels[text], scale = label.scale;
					const left = originX + label.x + advance * scale, top = originY + label.y;
					let best = Infinity;

					// CSS clip bounds and physical rounding can add a boundary pixel.
					const boundary = Math.ceil(devicePixelRatio) + 1;

					for(let dy = -boundary; dy <= boundary; dy++)
					for(let dx = -boundary; dx <= boundary; dx++)
					{
						let missed = 0;

						for(let y = 0; y < 7 * scale; y++)
						for(let x = 0; x < 5 * scale; x++)
						{
							const offset = ((top + y + dy) * copy.width + left + x + dx) * 4;
							const ink = pixels[offset] > 100 && pixels[offset + 1] > 100;

							if(ink !== (rows[Math.floor(y / scale)][Math.floor(x / scale)] === '1')) missed++;
						}

						best = Math.min(best, missed);
					}

					results.push(
						{text, missed: best, left, top, scale, imageWidth: image.width, imageHeight: image.height}
					);
				}

				return results;
			}, png
			);

			for(const glyph of evidence) expect(glyph.missed, JSON.stringify(glyph)).toBe(0);
		}
	}
	finally
	{
		await context.close();
	}
});

test('title text grows with its buttons in fullscreen and returns to its compact size', async ({page}) => {
	await page.setViewportSize({width: 1600, height: 1000});
	await page.goto('/php-noid/');
	await expect(page.locator('#status')).toHaveText('Ready', {timeout: 60000});
	const before = (await state(page)).hud;
	const button = page.locator('[data-action="start"]');
	const windowedButton = await button.boundingBox();
	await page.locator('canvas').screenshot({path: '/tmp/php-noid-compact-title.png'});
	expect(7 * before.textScale / before.pixelRatio).toBeLessThanOrEqual(16);
	expect(windowedButton.width).toBeLessThanOrEqual(282);
	await page.locator('#fullscreen').click();
	await expect.poll(async () => (await state(page)).hud.displayWidth).toBeGreaterThan(before.displayWidth * 1.5);
	await expect.poll(async () => (await state(page)).hud.textScale).toBeGreaterThan(before.textScale);
	const expanded = (await state(page)).hud;
	const expandedButton = await button.boundingBox();
	await page.locator('canvas').screenshot({path: '/tmp/php-noid-expanded-title.png'});
	const textGrowth = expanded.textScale / before.textScale;
	const buttonGrowth = expandedButton.width / windowedButton.width;
	expect(Math.abs(textGrowth - buttonGrowth)).toBeLessThanOrEqual(.5);
	await page.locator('#fullscreen').click();
	await expect.poll(async () => (await state(page)).hud.textScale).toBe(before.textScale);
	await expect.poll(async () => (await state(page)).hud.width).toBe(before.width);
});

test('title hit targets stay transparent on hover, focus and press', async ({page}) => {
	await page.goto('/php-noid/');
	await expect(page.locator('#status')).toHaveText('Ready', {timeout: 60000});

	for(const action of ['start', 'php-info'])
	{
		const button = page.locator(`[data-action="${action}"]`);
		await button.hover();
		await expect(button).toHaveCSS('background-color', 'rgba(0, 0, 0, 0)');
		await button.focus();
		await expect(button).toHaveCSS('background-color', 'rgba(0, 0, 0, 0)');
		await page.mouse.down();
		await expect(button).toHaveCSS('background-color', 'rgba(0, 0, 0, 0)');
		await page.mouse.move(0, 0);
		await page.mouse.up();
	}

	expect((await state(page)).title).toBe(true);
});

for(const deviceScaleFactor of [1, 2])
test(`PHP-info back hint is a large top-right click target at density ${deviceScaleFactor}`, async ({browser}) => {
	const context = await browser.newContext({viewport: {width: 1139, height: 985}, deviceScaleFactor});
	const page = await context.newPage();

	try
	{
		await page.goto('/php-noid/');
		await expect(page.locator('#status')).toHaveText('Ready', {timeout: 60000});
		await page.locator('[data-action="php-info"]').click();
		await expect.poll(async () => (await state(page)).phpInfo.ready).toBe(true);
		const back = page.locator('.php-noid-info-back');
		await expect(back).toHaveCount(1);
		await expect(back).toBeVisible();
		const canvas = await page.locator('canvas').boundingBox();
		const button = await back.boundingBox();
		const info = (await state(page)).phpInfo;
		expect(info.hintScale / deviceScaleFactor).toBeGreaterThanOrEqual(2);
		expect(button.height).toBeGreaterThanOrEqual(44);
		expect(button.y).toBeLessThan(canvas.y + canvas.height / 4);
		expect(button.x).toBeGreaterThan(canvas.x + canvas.width / 2);
		await back.hover();
		await expect(back).toHaveCSS('background-color', 'rgba(0, 0, 0, 0)');
		await page.screenshot({path: `/tmp/php-noid-back-${deviceScaleFactor}.png`, fullPage: true});
		await back.click();
		await expect(page.locator('.php-noid-title')).toBeVisible();
		await expect(back).toBeHidden();
		expect((await state(page)).phpInfo.open).toBe(false);
	}
	finally
	{
		await context.close();
	}
});
