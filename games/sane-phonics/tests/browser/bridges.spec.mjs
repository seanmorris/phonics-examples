import {expect, test} from '@playwright/test';

/**
 * Execute PHP inside the running demo and require a clean process result.
 * @param {import('@playwright/test').Page} page Browser page owning the PHP runtime.
 * @param {string} source PHP statements to execute after the opening tag.
 * @returns {Promise<string>} Captured standard output.
 */
async function run(page, source)
{
	const result = await page.evaluate(async source => {
		let output = '';
		let errors = '';

		const out = event => output += event.detail.join('');

		const error = event => errors += event.detail.join('');
		enginePhp.addEventListener('output', out);
		enginePhp.addEventListener('error', error);

		try
		{
			const code = await enginePhp.run('<?php ' + source);

			return {output, errors, code};
		}
		finally
		{
			enginePhp.removeEventListener('output', out);
			enginePhp.removeEventListener('error', error);
		}
	}, source
	);

	expect(result.errors).toBe('');
	expect(result.code).toBe(0);

	return result.output;
}

test('live bendable spans add body loads and expose a deforming grinding rail', async({page}) => {
	const errors = [];
	page.on('pageerror', error => errors.push(error.message));
	await page.goto('/sane-phonics/');
	await page.waitForFunction(() => typeof window.bootEngine === 'function');
	await page.evaluate(() => window.bootEngine({intro: false, scene: 'bridges', motionBlur: false}));
	await expect(page.locator('canvas')).toHaveAttribute('data-scene', 'bridges', {timeout: 60000});

	let spans = JSON.parse(await page.locator('canvas').getAttribute('data-bridges'));
	expect(spans).toHaveLength(3);
	expect(spans[2].grinding).toBe(true);

	await run(page, `
		$body->x = 448;
		$body->y = 430;
		$body->vx = $body->vy = $body->groundSpeed = 0;
		$body->angle = 0;
		$body->grounded = true;
		$secondBridgeBody = new \\PhpWasmEngine\\Physics\\Body(352, 430);
		$secondBridgeBody->grounded = true;
		$secondBridgeEntity = $engine->entities->create($secondBridgeBody);
	`
	);

	await expect.poll(async() => {
		spans = JSON.parse(await page.locator('canvas').getAttribute('data-bridges'));

		return spans[0].riders.length;
	}).toBe(2);

	expect(Math.max(...spans[0].offsets)).toBeGreaterThan(5);
	expect(spans[0].offsets[0]).toBe(0);
	expect(spans[0].offsets.at(-1)).toBe(0);

	await run(page, `
		$engine->entities->destroy($secondBridgeEntity);
		$body->x = 1408;
		$body->y = 430;
		$body->vx = $body->vy = $body->groundSpeed = 0;
		$body->angle = 0;
		$body->grounded = true;
		$body->grinding = false;
	`
	);

	await expect.poll(
		async() => JSON.parse(await page.locator('canvas').getAttribute('data-body')).grinding
	).toBe(true);

	expect(errors).toEqual([]);
});
