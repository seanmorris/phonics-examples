import {test, expect} from '@playwright/test';

test('full page boots the direct application and executes a resettable shot', async ({page}) => {
	const errors = [];
	page.on('pageerror', error => errors.push(error.message));
	page.on('console', message => { if(message.type() === 'error') errors.push(message.text()); });
	await page.goto('/games/php-billiards/');
	const canvas = page.locator('canvas');
	await expect(canvas).toHaveAttribute('data-php-billiards', /"phase":"aim"/, {timeout: 45_000});
	const initial = await canvas.getAttribute('data-php-billiards');
	expect(JSON.parse(initial).balls.filter(ball => ball.active)).toHaveLength(16);
	await page.getByRole('button', {name: 'SHOOT'}).click();
	await expect.poll(async() => JSON.parse(await canvas.getAttribute('data-php-billiards')).shots).toBe(1);
	await page.getByRole('button', {name: 'RESET SHOT'}).click();
	await expect.poll(async() => JSON.parse(await canvas.getAttribute('data-php-billiards')).phase).toBe('aim');
	expect(errors).toEqual([]);
});

test('embed route contains only the game host and cleans up its runtime', async ({page}) => {
	await page.goto('/embed/php-billiards/');
	await expect(page.locator('canvas')).toHaveAttribute('data-php-billiards', /"balls"/, {timeout: 45_000});
	await expect(page.locator('nav')).toHaveCount(0);
	await expect(page.locator('.php-billiards-ui')).toHaveCount(1);
	await page.evaluate(() => dispatchEvent(new PageTransitionEvent('pagehide')));
	await expect(page.locator('.php-billiards-ui')).toHaveCount(0);
});

test('a restored WebGL context restarts the direct application cleanly', async ({page}) => {
	await page.goto('/games/php-billiards/');
	await expect(page.locator('canvas')).toHaveAttribute('data-php-billiards', /"phase":"aim"/, {timeout: 45_000});
	const loaded = page.waitForEvent('load');
	await page.locator('canvas').evaluate(canvas => {
		const extension = canvas.getContext('webgl2').getExtension('WEBGL_lose_context');
		extension.loseContext();
		setTimeout(() => extension.restoreContext(), 100);
	});
	await loaded;
	await expect(page.locator('canvas')).toHaveAttribute('data-php-billiards', /"phase":"aim"/, {timeout: 45_000});
	await expect(page.locator('.php-billiards-ui')).toHaveCount(1);
});
