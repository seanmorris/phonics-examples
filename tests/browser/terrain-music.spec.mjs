import {expect, test} from '@playwright/test';

test('streamed songs show their ID3 credits, clear the minimap, and remember the next title', async({page}, testInfo) => {
	test.setTimeout(150_000);
	await page.setViewportSize({width: 760,height: 490});
	const requested = new Set(), errors = [];
	page.on('pageerror', error => errors.push(error.message));

	page.on('request', request => {
		if(request.url().endsWith('.mp3')) requested.add(new URL(request.url()).pathname);
	});

	const view = page.locator('canvas[data-terrain-sandbox]');
	const credit = page.locator('[data-music-credit]');
	const map = page.locator('canvas.terrain-flight-map');
	const next = page.locator('[data-music-next]');
	await page.goto('/games/terrain-sandbox/');
	await expect(view).toHaveAttribute('data-terrain-sandbox', /"recipe":/, {timeout: 60_000});
	// Hosts can already have user activation by the time PHP finishes loading.
	expect(requested.size).toBeLessThanOrEqual(1);
	await view.focus();
	await page.keyboard.press('Control');
	await expect(credit).toContainText('Peak Shiawase Remix');
	await expect(credit).toContainText('NayzeeGD');
	await expect.poll(() => credit.locator('img').evaluate(img => img.naturalWidth)).toBeGreaterThan(0);
	expect(requested.size).toBe(1);
	await view.evaluate(el => el.terrainSandboxDebug.action('car'));
	await expect(map).toBeVisible({timeout: 45_000});
	const explore = page.locator('[aria-controls="terrain-section-explore"]');
	await explore.click();
	await next.click();
	await expect(credit).toContainText('Dimension');
	await expect(credit).toContainText('Creo');

	async function space()
	{
		return page.evaluate(() => {
			const root = document.querySelector('.terrain-sandbox');
			const popup = root.querySelector('[data-music-credit]').getBoundingClientRect();
			const chart = root.querySelector('.terrain-flight-map').getBoundingClientRect();

			return {clearance: popup.left-chart.right,left: popup.left-root.getBoundingClientRect().left};
		});
	}

	expect((await space()).clearance).toBeGreaterThanOrEqual(6);
	await testInfo.attach('music-map', {body: await page.screenshot(), contentType: 'image/png'});
	await view.focus();
	await page.keyboard.press('m');
	await expect(map).toBeHidden();
	await next.click();
	await expect(credit).toContainText('Tokyo Drift 東京ドリフト');
	await expect(credit).toBeVisible();
	expect((await space()).left).toBeCloseTo(12, 0);
	await page.setViewportSize({width: 520,height: 440});
	await view.focus();
	await page.keyboard.press('m');
	await expect(map).toBeVisible();
	await next.click();
	await expect(credit).toContainText('Unity');
	expect((await space()).clearance).toBeGreaterThanOrEqual(6);
	await expect(credit).toBeHidden({timeout: 10_000});
	await page.reload();
	await expect(view).toHaveAttribute('data-terrain-sandbox', /"recipe":/, {timeout: 60_000});
	await view.focus();
	await page.keyboard.press('Control');
	await expect(credit).toContainText('Ghost Nova');
	await expect(credit).toContainText('JAMATAR');
	await expect(credit).toContainText('Midorisuki');
	expect(requested.size).toBe(5);
	await view.evaluate(el => el.dispatchEvent(new CustomEvent('phonics-music-track', {
		detail: {id: 'encore', title: 'Encore', artist: 'Soloist'}
	})));
	await expect(credit).toContainText('Soloist');
	await expect(credit.locator('img')).toBeHidden();
	await expect(credit.locator('[data-album]')).toBeEmpty();
	expect(errors).toEqual([]);
});
