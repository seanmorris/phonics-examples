import {test, expect} from '@playwright/test';

const state = page => page.locator('canvas').evaluate(canvas => JSON.parse(canvas.dataset.badniks ?? 'null'));

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

test('live monitors animate, award rings once, rebound the player, and retain a broken casing', async({page}) => {
	const errors = [];
	page.on('pageerror', error => errors.push(error.message));
	await page.goto('/sane-phonics/');
	await page.evaluate(() => window.bootEngine({intro: false, scene: 'badniks'}));
	await expect.poll(() => state(page), {timeout: 60000}).not.toBeNull();
	await expect.poll(async() => (await state(page)).monitors.intact).toBe(7);

	const result = JSON.parse(await run(page, `
		$id = $game->level->monitors->entities()[0];
		$monitor = $engine->entities->get($id, PhpWasmEngine\\Gameplay\\Monitor::class);
		$screen = $engine->entities->get($id, PhpWasmEngine\\Render\\SpriteGroup::class)->sprites[0];
		$game->counter->rings = 0;
		$body->grounded = false;
		$body->vy = 600;
		$first = $game->level->monitors->pop($id, $game->entity, $body, $engine->time * 1000);
		$second = $game->level->monitors->pop($id, $game->entity, $body, $engine->time * 1000);
		echo json_encode([$first, $second, $game->counter->rings, $game->counter->score,
			$body->vy, $monitor->broken, $screen->gid]);
	`
	));

	expect(result).toEqual([true, false, 10, 10, -600, true, 41]);
	await expect.poll(async() => (await state(page)).monitors.broken).toBe(1);
	await expect.poll(async() => (await state(page)).monitors.intact).toBe(6);

	const shield = JSON.parse(await run(page, `
		$id = $game->level->monitors->entities()[2];
		$screen = $engine->entities->get($id, PhpWasmEngine\\Render\\SpriteGroup::class)->sprites[0];
		$game->level->monitors->pop($id, $game->entity, $body, $engine->time * 1000);
		$game->level->shields->update($engine->entities, 1 / 60);
		$state = $engine->entities->get($game->entity, PhpWasmEngine\\Gameplay\\Shield::class);
		$aura = $engine->entities->query(PhpWasmEngine\\Gameplay\\ShieldAura::class)[0];
		echo json_encode([
			$state->active
			, $screen->gid
			, $engine->entities->get($aura, PhpWasmEngine\\Render\\Sprite::class)->gid
		]);
	`
	));

	expect(shield).toEqual(['normal', 47, 52]);
	await expect.poll(async() => (await state(page)).shields.types.normal).toBe(1);
	await expect.poll(async() => (await state(page)).monitors.intact).toBe(5);
	expect(errors).toEqual([]);
});
