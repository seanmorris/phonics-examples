import {test, expect} from '@playwright/test';

const state = page => page.locator('canvas').evaluate(canvas => ({
	body: JSON.parse(canvas.dataset.body ?? 'null')
	, hud: JSON.parse(canvas.dataset.hud ?? 'null')
	, vehicles: JSON.parse(canvas.dataset.vehicles ?? 'null')
}));

test.afterEach(async({page}) => {
	await page.evaluate(() => window.enginePhp?.refresh()).catch(() => {});
});

test('Tornado course supports thrust, pitch, gear, fuel HUD and long-distance flight', async({page}) => {
	test.setTimeout(90000);
	const errors = [];
	page.on('pageerror', error => errors.push(error.message));
	await page.goto('/sane-phonics/');
	await page.evaluate(() => window.bootEngine({intro: false, scene: 'tornado', motionBlur: false}));
	await expect.poll(async() => (await state(page)).body?.ability, {timeout: 60000}).toBe('riding');
	await page.locator('canvas').focus();

	const start = await state(page);
	const aircraft = start.vehicles.find(vehicle => vehicle.occupant !== null);
	expect(aircraft.type).toBe('tornado');
	expect(start.body.x).toBeCloseTo(aircraft.x, 5);
	expect(aircraft.landingGear).toBe(true);
	expect(start.hud.rows['FUEL:']).toBe('100%');

	await page.keyboard.down('c');
	await expect.poll(async() => (await state(page)).vehicles[0].thrusting).toBe(true);
	await expect.poll(async() => (await state(page)).vehicles[0].flying).toBe(true);
	await expect.poll(async() => (await state(page)).vehicles[0].fuel).toBeLessThan(100);

	await expect.poll(async() => Number.parseInt((await state(page)).hud.rows['FUEL:'], 10)).toBeLessThan(100);
	await page.keyboard.down('ArrowUp');
	await expect.poll(async() => (await state(page)).vehicles[0].pitch, {intervals: [16]}).toBeLessThan(-.3);
	await page.keyboard.up('ArrowUp');
	await page.keyboard.up('c');
	await page.keyboard.press('x');
	await expect.poll(async() => (await state(page)).vehicles[0].landingGear).toBe(true);

	const fuelBeforeRefire = (await state(page)).vehicles[0].fuel;
	await page.keyboard.down('c');
	await expect.poll(async() => (await state(page)).vehicles[0].thrusting).toBe(true);
	await expect.poll(async() => (await state(page)).vehicles[0].fuel).toBeLessThan(fuelBeforeRefire);
	await page.keyboard.up('c');

	// Start the route independently of the time spent checking the controls above.
	await page.keyboard.press('Shift+R');
	await expect.poll(async() => (await state(page)).vehicles[0].x).toBeLessThan(200);
	await expect.poll(async() => (await state(page)).body?.ability).toBe('riding');

	// Observe route thresholds in the rendering frame. Browser automation round
	// trips can otherwise hold a climb hundreds of pixels past the intended height.
	await page.evaluate(() => new Promise((resolve, reject) => {
		const canvas = document.querySelector('canvas');

		const key = (type, value, code, keyCode) => canvas.dispatchEvent(
			new KeyboardEvent(type, {key: value,code,keyCode,which: keyCode,bubbles: true})
		);

		let climbing = true;
		const deadline = performance.now() + 30000;
		key('keydown', 'c', 'KeyC', 67);
		key('keydown', 'ArrowUp', 'ArrowUp', 38);

		const frame = () => {
			const aircraft = JSON.parse(canvas.dataset.vehicles)[0];

			if(climbing && aircraft.y < 2400)
			{
				key('keyup', 'ArrowUp', 'ArrowUp', 38);
				climbing = false;
			}

			if(aircraft.x > 7600)
			{
				key('keyup', 'c', 'KeyC', 67);

				return resolve();
			}

			if(performance.now() > deadline) return reject(new Error('Tornado did not reach the final runway'));

			requestAnimationFrame(frame);
		};

		requestAnimationFrame(frame);
	}));

	const travel = await state(page);

	expect(travel.body.vehicle).not.toBe(null);
	expect(travel.vehicles[0].crashed).toBe(false);

	await page.keyboard.up('c');
	await page.keyboard.press('x');
	await page.keyboard.down('ArrowDown');
	await expect.poll(async() => (await state(page)).vehicles[0].grounded, {timeout: 20000}).toBe(true);
	await page.keyboard.up('ArrowDown');

	const landing = await state(page);
	expect(landing.vehicles[0].x).toBeGreaterThanOrEqual(8192);
	expect(landing.vehicles[0].x).toBeLessThanOrEqual(10192);
	expect(landing.vehicles[0].crashed).toBe(false);
	await page.screenshot({path: 'test-results/tornado-flight.png'});
	expect(errors).toEqual([]);
});
