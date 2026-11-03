import {test,expect} from '@playwright/test';

const state = page => page.locator('canvas').evaluate(canvas => ({
	body: JSON.parse(canvas.dataset.body ?? 'null')
	, combo: JSON.parse(canvas.dataset.combo ?? 'null')
	, hud: JSON.parse(canvas.dataset.hud ?? 'null')
}));

async function run(page, source)
{
	return page.evaluate(async source => {
		let output = '';

		const listener = event => output += event.detail.join('');
		enginePhp.addEventListener('output', listener);

		try
		{
			const code = await enginePhp.run('<?php ' + source);

			if(code) throw Error('PHP returned ' + code);

			return output;
		}
		finally
		{
			enginePhp.removeEventListener('output', listener);
		}
	}, source
	);
}

test.afterEach(async({page}) => {
	await page.evaluate(() => window.enginePhp?.refresh()).catch(() => {});
});

test('live flight and grinding use independent look and travel locks', async({page}) => {
	await page.goto('/sane-phonics/');
	await page.evaluate(() => window.bootEngine({intro: false,scene: 'platformer'}));
	await expect.poll(async() => (await state(page)).body).not.toBeNull();

	const result = JSON.parse(await run(page, `
		$engine->userPaused = true;
		$body->x = 1360;
		$body->y = 350;
		$body->vx = 0;
		$body->vy = 3000;
		$body->terminalSpeed = 3000;
		$body->gravity = 0;
		$body->grounded = false;
		$body->grinding = false;
		$engine->controls->reset();
		$engine->controls->set([]);
		$game->level->movement->step($body, $engine->controls, 1 / 60);
		$body->groundSpeed = 600;
		$body->facing = $body->direction = 1;
		$engine->controls->set(['left' => true]);
		$game->level->movement->step($body, $engine->controls, 1 / 60);
		$rail = [$body->facing, $body->direction, $body->groundSpeed];

		$body->x = 0;
		$body->y = -500;
		$body->vx = 120;
		$body->vy = 0;
		$body->profile = 'flyer';
		$body->grounded = false;
		$body->grinding = false;
		$body->jumping = true;
		$body->ability = 'fly';
		$body->facing = $body->direction = 1;
		$body->flightRemaining = 5.8;

		foreach(range(1, 60) as $frame)
		{
			$engine->controls->set(['left' => true]);
			$game->level->movement->step($body, $engine->controls, 1 / 60);
		}

		echo json_encode([$rail, [$body->facing, $body->direction, $body->vx, $body->ability]]);
	`
	));

	expect(result[0][0]).toBe(-1);
	expect(result[0][1]).toBe(1);
	expect(result[0][2]).toBeGreaterThan(0);
	expect(result[1][0]).toBe(1);
	expect(result[1][1]).toBe(1);
	expect(result[1][2]).toBeLessThan(0);
	expect(result[1][3]).toBe('fly');
});

for(const device of ['keyboard', 'gamepad'])
test(device + ' reverses a live glide and turns the sprite with its travel', async({page}) => {
	await page.addInitScript(() => {
		window.glidePad = {
			id: 'Standard Gamepad'
			, index: 0
			, connected: true
			, mapping: 'standard'
			, timestamp: 1
			, axes: [0, 0, 0, 0]
			, buttons: Array.from({length: 17}, () => ({pressed: false, touched: false, value: 0}))
		};

		Object.defineProperty(navigator, 'getGamepads', {value: () => [glidePad]});
	});

	await page.goto('/sane-phonics/');
	await page.evaluate(() => window.bootEngine({intro: false, scene: 'platformer', profile: 'glider'}));
	await expect.poll(async() => (await state(page)).body).not.toBeNull();

	await run(page, `
		$body->x = 0;
		$body->y = -2000;
		$body->grounded = false;
		$body->jumping = true;
		$body->ability = 'glide';
		$body->vx = 300;
		$body->vy = 0;
		$body->facing = $body->direction = 1;
	`
	);

	await page.locator('canvas').focus();
	const focusedFrame = Number(await page.locator('canvas').getAttribute('data-frames'));

	await expect.poll(async() => Number(await page.locator('canvas').getAttribute('data-frames')))
		.toBeGreaterThan(focusedFrame + 3);

	for(const direction of [-1, 1])
	{
		const key = direction < 0 ? 'ArrowLeft' : 'ArrowRight';

		if(device === 'keyboard') await page.keyboard.down(key);
		else
		await page.evaluate(direction => {
			glidePad.axes[0] = direction * .6;
			glidePad.timestamp++;
		}, direction
		);

		await expect.poll(async() => (await state(page)).body?.facing).toBe(direction);
		const before = (await state(page)).body.x;
		await expect.poll(async() => ((await state(page)).body.x - before) * direction).toBeGreaterThan(10);
		expect((await state(page)).body.ability).toBe('glide');
		expect((await state(page)).body.direction).toBe(direction);

		if(device === 'keyboard') await page.keyboard.up(key);
		else
		await page.evaluate(() => {
			glidePad.axes[0] = 0;
			glidePad.timestamp++;
		});

		const flipped = await run(page, `
			$sprite = $engine->entities->get($entity, PhpWasmEngine\\Render\\Sprite::class);
			echo $sprite->flipX ? 'yes' : 'no';
		`
		);

		expect(flipped).toBe(direction < 0 ? 'yes' : 'no');
	}
});

for(const device of ['keyboard', 'gamepad'])
test(device + ' respects the air speed gate and reverses slower falls without turning', async({page}) => {
	await page.addInitScript(() => {
		window.airPad = {
			id: 'Standard Gamepad'
			, index: 0
			, connected: true
			, mapping: 'standard'
			, timestamp: 1
			, axes: [0, 0, 0, 0]
			, buttons: Array.from({length: 17}, () => ({pressed: false, touched: false, value: 0}))
		};

		Object.defineProperty(navigator, 'getGamepads', {value: () => [airPad]});
	});

	await page.goto('/sane-phonics/');
	await page.evaluate(() => window.bootEngine({intro: false, scene: 'platformer'}));
	await expect.poll(async() => (await state(page)).body).not.toBeNull();
	await page.locator('canvas').focus();
	const focusedFrame = Number(await page.locator('canvas').getAttribute('data-frames'));

	await expect.poll(async() => Number(await page.locator('canvas').getAttribute('data-frames')))
		.toBeGreaterThan(focusedFrame + 3);

	await run(page, `
		$body->x = 0;
		$body->y = -2000;
		$body->grounded = $body->grinding = $body->jumping = false;
		$body->ability = '';
		$body->gravity = $body->vy = 0;
		$body->vx = 1440;
		$body->facing = $body->direction = 1;
	`
	);

	if(device === 'keyboard') await page.keyboard.down('ArrowLeft');
	else
	await page.evaluate(() => {
		airPad.axes[0] = -.6;
		airPad.timestamp++;
	});

	await expect.poll(async() => Number(await run(page, `echo $engine->controls->axis('left', 'right');`)))
		.toBeLessThan(-.49);

	const inputFrame = Number(await page.locator('canvas').getAttribute('data-frames'));

	await expect.poll(async() => Number(await page.locator('canvas').getAttribute('data-frames')))
		.toBeGreaterThan(inputFrame + 20);

	expect(Number(await run(page, 'echo $body->vx;'))).toBe(1440);

	await run(page, '$body->vx = 240;');
	await expect.poll(async() => Number(await run(page, 'echo $body->vx;'))).toBeLessThan(0);
	const before = (await state(page)).body.x;
	await expect.poll(async() => (await state(page)).body.x).toBeLessThan(before - 10);
	expect((await state(page)).body.facing).toBe(1);
	expect((await state(page)).body.direction).toBe(1);
	expect((await state(page)).body.jumping).toBe(false);

	if(device === 'keyboard') await page.keyboard.up('ArrowLeft');
	else
	await page.evaluate(() => {
		airPad.axes[0] = 0;
		airPad.timestamp++;
	});
});

test('live rail jump drop dash follows travel direction while facing backward', async({page}) => {
	await page.goto('/sane-phonics/');
	await page.evaluate(() => window.bootEngine({intro: false,scene: 'platformer'}));
	await expect.poll(async() => (await state(page)).body).not.toBeNull();

	const result = JSON.parse(await run(page, `
		$engine->userPaused = true;
		$body->x = 1360;
		$body->y = 350;
		$body->vx = 0;
		$body->vy = 3000;
		$body->terminalSpeed = 3000;
		$body->gravity = 0;
		$body->profile = 'runner';
		$body->grounded = false;
		$body->grinding = false;
		$engine->controls->reset();
		$engine->controls->set([]);
		$game->level->movement->step($body, $engine->controls, 1 / 60);

		$body->groundSpeed = $body->minGrindSpeed;
		$body->gravity = 1800;
		$body->facing = $body->direction = 1;
		$engine->controls->set(['left' => true,'jump' => true]);
		$game->level->movement->step($body, $engine->controls, 1 / 60);
		$launch = [$body->facing, $body->direction, $body->jumping, $body->grinding];

		for($frame = 0; !$body->grounded && $frame < 120; $frame++)
		{
			$engine->controls->set(['action' => true]);
			$game->level->movement->step($body, $engine->controls, 1 / 60);
		}

		echo json_encode([$launch, [
			$body->grounded
			, $body->rolling
			, $body->groundSpeed
			, $body->dropCharge
		]]);
	`
	));

	expect(result[0]).toEqual([-1,1,true,false]);
	expect(result[1][0]).toBe(true);
	expect(result[1][1]).toBe(true);
	expect(result[1][2]).toBeGreaterThan(0);
	expect(result[1][3]).toBe(0);
});

test('live braking and spindash charge drive signed backdrop displacement without moving the body', async({page}) => {
	await page.goto('/sane-phonics/');
	await page.evaluate(() => window.bootEngine({intro: false,scene: 'platformer'}));
	await expect.poll(async() => (await state(page)).body?.grounded).toBe(true);

	const braking = JSON.parse(await run(page, `
		$game->menu->menu->open();
		$engine->userPaused = true;
		$body->rolling = false;
		$body->grinding = false;
		$body->groundSpeed = 600;
		$body->direction = -1;
		$body->charge = 0;
		$game->render();
		echo json_encode([
			$body->grounded
			, $body->rolling
			, $body->grinding
			, $body->groundSpeed
			, $body->direction
			, $game->level->backdropTwist->draws
			, $game->level->backdropTwist !== null
			, $body->x
			, $body->y
		]);
	`
	));

	expect(braking.slice(0, 7)).toEqual([true,false,false,600,-1,2,true]);
	await expect.poll(async() => (await state(page)).body.backdropTwist).toBe(-220);
	let rendered = (await state(page)).body;
	expect(rendered.backdropTwistX).toBeCloseTo(rendered.supportX + 16);
	expect(rendered.backdropTwistY).toBeCloseTo(rendered.supportY);

	const charged = JSON.parse(await run(page, `
		$body->groundSpeed = 0;
		$body->direction = -1;
		$body->charge = .5;
		$game->render();
		echo json_encode([
			$game->level->backdropTwist->draws
			, $body->x
			, $body->y
		]);
	`
	));

	expect(charged[0]).toBe(2);
	expect(charged[1]).toBe(braking[7]);
	expect(charged[2]).toBe(braking[8]);
	await expect.poll(async() => (await state(page)).body.backdropTwist).toBe(-60);
	rendered = (await state(page)).body;
	expect(rendered.backdropTwistX).toBeCloseTo(rendered.supportX + 13);
	expect(rendered.backdropTwistY).toBeCloseTo(rendered.supportY);

	const full = await run(
		page
		, '$body->direction = 1; $body->charge = 1; $game->render(); '
			+ 'echo $game->level->backdropTwist->draws;'
	);

	expect(Number(full)).toBe(2);
	await expect.poll(async() => (await state(page)).body.backdropTwist).toBe(120);
	rendered = (await state(page)).body;
	expect(rendered.backdropTwistX).toBeCloseTo(rendered.supportX - 13);

	const cleared = await run(
		page
		, '$body->charge = 0; $game->render(); '
			+ 'echo $game->level->backdropTwist->draws;'
	);

	expect(Number(cleared)).toBe(0);
	await expect.poll(async() => (await state(page)).body.backdropTwist).toBe(0);
});

test('Tiled rail contact drives the top-right combo HUD and banks into score', async({page}) => {
	await page.goto('/sane-phonics/');
	await page.evaluate(() => window.bootEngine({intro: false,scene: 'platformer'}));
	await expect.poll(async() => (await state(page)).body).not.toBeNull();

	const contact = JSON.parse(await run(page, `
		$game->menu->menu->open();
		$engine->userPaused = true;
		$body->x = 1360;
		$body->y = 350;
		$body->vx = 0;
		$body->vy = 3000;
		$body->terminalSpeed = 3000;
		$body->gravity = 0;
		$body->grounded = false;
		$body->grinding = false;
		$engine->controls->reset();
		$engine->controls->set([]);
		$game->level->movement->step($body, $engine->controls, 1 / 60);
		$game->combo->update($body, 1 / 60);
		echo json_encode([$body->grounded, $body->grinding, $body->y]);
	`
	));

	expect(contact[0]).toBe(true);
	expect(contact[1]).toBe(true);
	expect(contact[2]).toBeCloseTo(398, 2);
	await expect.poll(async() => (await state(page)).combo.visible).toBe(true);
	let current = await state(page);
	expect(current.body.grinding).toBe(true);
	expect(current.body.pose).toBe('grinding');
	expect(current.combo.labels).toEqual(['Rail Grind']);
	expect(current.combo.draws).toBe(1);

	await run(page, `
		foreach(range(1, 5) as $index)
		{
			$game->combo->add(new PhpWasmEngine\\Gameplay\\ComboReward('Trick ' . $index, 10, 1));
		}
	`
	);

	await expect.poll(async() => (await state(page)).combo.overflow).toBe(2);
	current = await state(page);
	expect(current.combo.labels).toEqual(['Trick 2','Trick 3','Trick 4','Trick 5']);
	const before = current.hud.score;

	await run(page, `
		$body->grinding = false;
		$body->grounded = true;
		$score = $game->combo->update($body, .1);
		$counter->score += $score ?? 0;
	`
	);

	await expect.poll(async() => (await state(page)).combo.result).toBeGreaterThan(0);
	current = await state(page);
	expect(current.combo.failed).toBe(false);
	expect(current.combo.labels).toEqual([]);
	expect(current.hud.score).toBeGreaterThan(before);
});

test('the live grinding course takes every bend and collision switch in both directions', async({page}) => {
	test.setTimeout(60000);
	await page.goto('/sane-phonics/');
	await page.evaluate(() => window.bootEngine({intro: false, scene: 'grinding', motionBlur: false}));
	await expect.poll(async() => (await state(page)).body?.grinding, {timeout: 30000}).toBe(true);

	await run(page, `
		$grindingTrace = [];
		$engine->systems->add('browser-grinding-trace', static function() use ($body) {
			global $grindingTrace;
			$grindingTrace[] = [
				'x' => $body->x
				, 'y' => $body->y
				, 'angle' => $body->angle
				, 'grinding' => $body->grinding
				, 'collisionLayer' => $body->collisionLayer
			];
		}, after: ['movement']);
	`
	);

	await page.keyboard.press('r');
	await expect.poll(async() => (await state(page)).body.x).toBeLessThan(100);
	await expect.poll(async() => (await state(page)).body.grounded).toBe(true);
	await run(page, '$grindingTrace = [];');

	await page.keyboard.down('ArrowRight');
	await expect.poll(async() => (await state(page)).body.x, {timeout: 20000,intervals: [50]}).toBeGreaterThan(1920);
	await page.keyboard.up('ArrowRight');

	const forward = JSON.parse(await run(page, `
		echo json_encode($grindingTrace);
		$grindingTrace = [];
	`
	));

	expect(forward.some(body => body.x > 340 && body.x < 480 && body.grinding)).toBe(true);
	expect(forward.filter(body => body.x > 320 && body.x < 1920).every(body => body.grinding)).toBe(true);
	expect(forward.some(body => body.x < 1280 && Math.abs(Math.abs(body.angle) - Math.PI / 2) < .2)).toBe(true);
	expect(forward.some(body => body.x > 768 && body.x < 1024 && body.y < 190)).toBe(true);
	expect(forward.some(body => body.y < 260 && Math.abs(body.angle) > 2.6)).toBe(true);
	expect(forward.some(body => body.y < 300 && body.x < 1680 && body.collisionLayer === 1)).toBe(true);
	expect((await state(page)).body.collisionLayer).toBe(2);

	await page.keyboard.down('ArrowLeft');
	await expect.poll(async() => (await state(page)).body.x, {timeout: 20000,intervals: [50]}).toBeLessThan(160);
	await page.keyboard.up('ArrowLeft');
	const backward = JSON.parse(await run(page, 'echo json_encode($grindingTrace);'));
	expect(backward.filter(body => body.x > 320 && body.x < 1920).every(body => body.grinding)).toBe(true);
	expect(backward.some(body => body.x < 170 && body.grinding)).toBe(true);
	expect(backward.some(body => body.y < 300 && body.collisionLayer === 2)).toBe(true);
	expect((await state(page)).body.collisionLayer).toBe(1);
});
