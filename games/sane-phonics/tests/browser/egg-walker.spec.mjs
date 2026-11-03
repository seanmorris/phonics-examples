import {test, expect} from '@playwright/test';

const state = page => page.locator('canvas').evaluate(canvas => ({
	body: JSON.parse(canvas.dataset.body ?? 'null')
	, vehicles: JSON.parse(canvas.dataset.vehicles ?? 'null')
}));

test.afterEach(async({page}) => {
	await page.evaluate(() => window.enginePhp?.refresh()).catch(() => {});
});

test('EggWalker seats its rider and composes crouch, booster, punch and cannon controls', async({page}) => {
	const errors = [];
	page.on('pageerror', error => errors.push(error.message));
	await page.goto('/sane-phonics/');
	await page.evaluate(() => window.bootEngine({intro: false, scene: 'egg-walker', motionBlur: false}));
	await expect.poll(async() => (await state(page)).body?.ability, {timeout: 60000}).toBe('riding');
	await page.locator('canvas').focus();

	const initial = await state(page);
	const walker = initial.vehicles.find(vehicle => vehicle.occupant !== null);
	expect(walker.type).toBe('egg-walker');
	expect(initial.body.x).toBeCloseTo(walker.x, 5);
	expect(initial.body.y).toBeLessThan(walker.y);

	await page.keyboard.down('ArrowDown');
	await expect.poll(async() => (await state(page)).vehicles[0].crouching).toBe(true);
	await expect.poll(async() => (await state(page)).body.pose).toBe('riding-crouching');
	await page.keyboard.up('ArrowDown');

	await page.keyboard.down('x');
	await page.waitForFunction(() => JSON.parse(document.querySelector('canvas').dataset.vehicles)[0].punching);
	await page.keyboard.up('x');

	await page.keyboard.down('c');
	await expect.poll(async() => (await state(page)).vehicles[0].shots).toBeGreaterThanOrEqual(2);
	await page.keyboard.up('c');

	await page.keyboard.down('Space');
	await expect.poll(async() => (await state(page)).vehicles[0].grounded).toBe(false);
	await expect.poll(async() => (await state(page)).vehicles[0].boosting).toBe(true);
	await page.keyboard.down('ArrowUp');
	await page.waitForTimeout(100);
	await page.keyboard.up('Space');
	await page.keyboard.up('ArrowUp');
	await expect.poll(async() => (await state(page)).vehicles[0].boosting).toBe(true);

	// A crouch only arms the landing drop once the walker is falling; one started while rising never does.
	await expect.poll(async() => (await state(page)).vehicles[0].vy).toBeGreaterThan(0);
	await page.keyboard.down('ArrowDown');
	await expect.poll(async() => (await state(page)).vehicles[0].boosting).toBe(false);
	await expect.poll(async() => Math.abs((await state(page)).vehicles[0].shakeY)).toBeGreaterThan(0);
	await page.keyboard.up('ArrowDown');
	await page.locator('canvas').screenshot({path: 'test-results/egg-walker.png'});
	expect(errors).toEqual([]);
});
