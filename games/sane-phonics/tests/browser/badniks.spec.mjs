import {test, expect} from '@playwright/test';

const state = page => page.locator('canvas').evaluate(canvas => JSON.parse(canvas.dataset.badniks ?? 'null'));

const lockState = page => page.locator('canvas').evaluate(canvas => JSON.parse(canvas.dataset.bossLock ?? 'null'));

const bossState = page => page.locator('canvas').evaluate(canvas => JSON.parse(canvas.dataset.boss ?? 'null'));

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

test('CrabMeat patrols, fires, damages a walker, and pops under a rolling player', async({page}) => {
	const errors = [];
	page.on('pageerror', error => errors.push(error.message));
	await page.goto('/sane-phonics/');
	await page.evaluate(() => window.bootEngine({intro: false, scene: 'badniks'}));
	await expect.poll(() => state(page), {timeout: 60000}).not.toBeNull();
	await expect.poll(async() => (await state(page)).badniks).toBe(1);
	await expect.poll(async() => (await state(page)).projectiles, {timeout: 10000}).toBeGreaterThanOrEqual(2);

	const hit = JSON.parse(await run(page, `
		$id = $game->level->crabs->entities()[0];
		$badnik = $engine->entities->get($id, PhpWasmEngine\\Gameplay\\Badnik::class);
		$combat = $engine->entities->get($game->entity, PhpWasmEngine\\Gameplay\\PlayerCombat::class);
		$combat->mercy = 0;
		$game->counter->rings = 5;
		$body->x = $badnik->x;
		$body->y = $badnik->y - $badnik->height / 2;
		$body->grounded = true;
		$body->rolling = false;
		$game->level->badniks->sync($game->entity, $body);
		$game->level->badniks->update($engine->entities, 1 / 60);
		echo json_encode([$combat->hits, $combat->mercy, $game->counter->rings,
			$game->level->rings->scatteredCount(),
			$engine->entities->has($id, PhpWasmEngine\\Gameplay\\Badnik::class)]);
	`
	));

	expect(hit[0]).toBe(1);
	expect(hit[1]).toBeGreaterThan(0);
	expect(hit[2]).toBe(0);
	expect(hit[3]).toBe(5);
	expect(hit[4]).toBe(true);
	await expect.poll(async() => (await state(page)).scatteredRings).toBe(5);

	await run(page, `
		$combat = $engine->entities->get($game->entity, PhpWasmEngine\\Gameplay\\PlayerCombat::class);
		$game->menu->menu->open();
		$engine->userPaused = true;
		$combat->mercy = .35;
	`
	);

	await expect.poll(
		async() => page.locator('canvas').evaluate(canvas => JSON.parse(canvas.dataset.body).opacity)
		, {intervals: [20], timeout: 1500}
	).toBe(0);

	const popped = JSON.parse(await run(page, `
		$game->menu->menu->close();
		$engine->userPaused = false;

		foreach($engine->entities->query(PhpWasmEngine\\Gameplay\\BadnikProjectile::class) as $shot)
		{
			$engine->entities->destroy($shot);
		}

		$id = $game->level->crabs->entities()[0];
		$badnik = $engine->entities->get($id, PhpWasmEngine\\Gameplay\\Badnik::class);
		$combat = $engine->entities->get($game->entity, PhpWasmEngine\\Gameplay\\PlayerCombat::class);
		$combat->mercy = 0;
		$body->x = $badnik->x - 100;
		$body->y = $badnik->y - $badnik->height / 2;
		$body->grounded = true;
		$body->rolling = true;
		$game->level->badniks->sync($game->entity, $body);
		$body->x = $badnik->x + 100;
		$game->level->badniks->update($engine->entities, 1 / 60);
		echo json_encode([$combat->defeats, $game->counter->score,
			$engine->entities->has($id, PhpWasmEngine\\Gameplay\\Badnik::class)]);
	`
	));

	expect(popped).toEqual([1, 100, false]);
	await expect.poll(async() => (await state(page)).badniks).toBe(0);
	expect(errors).toEqual([]);
});

test('boss regions stop crossings, pan into the arena, and release after defeat', async({page}) => {
	const errors = [];
	page.on('pageerror', error => errors.push(error.message));
	await page.goto('/sane-phonics/');
	await page.evaluate(() => window.bootEngine({intro: false, scene: 'badniks'}));
	await expect.poll(() => lockState(page), {timeout: 60000}).not.toBeNull();

	const entered = JSON.parse(await run(page, `
		$game->menu->menu->open();
		$engine->userPaused = true;
		$body->x = 800;
		$body->y = 430;
		$body->grounded = true;
		$game->level->bossRegions->sync($body);
		$body->x = 2500;
		$body->vx = 60000;
		$body->groundSpeed = 60000;
		$region = $game->level->bossRegions->update($body);
		$enteredBodyX = $body->x;
		$camera->x = 480;
		$game->update($engine->entities, 1 / 60);
		$firstCameraX = $camera->x;

		foreach(range(1, 240) as $_)
		{
			$game->cameraBehavior->update($camera, $body, 1 / 60);
		}

		foreach(range(1, 60) as $_)
		{
			$game->level->miniBosses->update($engine->entities, 1 / 60);
		}

		echo json_encode([$region?->sourceId, $region?->bossSourceId, $enteredBodyX, $body->vx,
			$body->groundSpeed, $firstCameraX, $camera->x, $game->cameraBehavior->mode]);
	`
	));

	expect(entered.slice(0, 5)).toEqual([11, 3, 960, 0, 0]);
	expect(entered[5]).toBeGreaterThan(480);
	expect(entered[5]).toBeLessThan(960);
	expect(entered[6]).toBeCloseTo(960, 2);
	expect(entered[7]).toBe('boss');
	await expect.poll(async() => (await lockState(page)).active).toBe(true);
	expect((await lockState(page)).bossSourceId).toBe(3);
	await expect.poll(async() => (await bossState(page)).phase).toBe('stalking');
	await expect.poll(async() => (await bossState(page)).bombsDropped, {timeout: 5000}).toBeGreaterThanOrEqual(1);

	const released = JSON.parse(await run(page, `
		$bossEntity = $game->level->miniBosses->entityForSourceId(3);
		$boss = $engine->entities->get($bossEntity, PhpWasmEngine\\Gameplay\\Boss::class);
		$behavior = $engine->entities->get($bossEntity, PhpWasmEngine\\Gameplay\\MiniBoss::class);
		$combat = $engine->entities->get($game->entity, PhpWasmEngine\\Gameplay\\PlayerCombat::class);

		foreach(range(1, 8) as $_)
		{
			$behavior->age += 1;
			$boss->phase = 'stalking';
			$body->x = $boss->x - 100;
			$body->y = $boss->y - $boss->height / 2;
			$body->grounded = true;
			$body->jumping = false;
			$body->rolling = true;
			$combat->mercy = 0;
			$game->level->miniBosses->sync($game->entity, $body);
			$body->x = $boss->x + 100;
			$game->level->miniBosses->update($engine->entities, 1 / 60);
		}

		$body->x = 2500;
		$region = $game->level->bossRegions->update($body);
		$camera->x = 2000;
		$clamped = $game->level->bossRegions->constrainCamera($body, $camera);
		echo json_encode([$region === null, $clamped, $body->x, $camera->x, $boss->hitPoints,
			$boss->phase, $behavior->hits, $combat->defeats, $game->counter->score]);
	`
	));

	expect(released).toEqual([true, false, 2500, 2000, 0, 'damaged', 8, 1, 1000]);
	await expect.poll(async() => (await lockState(page)).active).toBe(false);

	const escaped = JSON.parse(await run(page, `
		$bossEntity = $game->level->miniBosses->entityForSourceId(3);
		$boss = $engine->entities->get($bossEntity, PhpWasmEngine\\Gameplay\\Boss::class);
		$behavior = $engine->entities->get($bossEntity, PhpWasmEngine\\Gameplay\\MiniBoss::class);
		$body->x = 0;
		$body->y = 0;
		$body->rolling = false;
		$game->level->miniBosses->sync($game->entity, $body);
		$game->level->miniBosses->update($engine->entities, $behavior->deathDelay + .001);
		$dead = $boss->phase;
		$body->x = $boss->x - 100;
		$body->y = $boss->y - $boss->height / 2;
		$body->rolling = true;
		$game->level->miniBosses->sync($game->entity, $body);
		$body->x = $boss->x + 100;
		$game->level->miniBosses->update($engine->entities, 1 / 60);
		$finisher = $boss->phase;
		$game->level->miniBosses->update($engine->entities, $behavior->explosionDuration);
		echo json_encode([$dead, $finisher, $boss->phase, $behavior->explosions]);
	`
	));

	expect(escaped).toEqual(['dead', 'exploding', 'escaped', 30]);
	expect(errors).toEqual([]);
});
