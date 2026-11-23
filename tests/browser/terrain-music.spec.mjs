import {expect, test} from '@playwright/test';
import {readFileSync} from 'node:fs';

const catalog = JSON.parse(readFileSync(new URL('../../games/terrain-sandbox/assets/soundtrack.json', import.meta.url)));

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
	await expect(credit).toHaveCSS('height', '128px');
	await expect(credit.locator('img')).toHaveCSS('width', '96px');
	await expect(credit.locator('strong')).toHaveCSS('font-size', '28px');
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
	await testInfo.attach('music-map-compact', {body: await page.screenshot(), contentType: 'image/png'});
	await page.setViewportSize({width: 360, height: 480});
	await expect(credit).toHaveCSS('height', '96px');
	const popup = await credit.boundingBox(), dial = await page.locator('.terrain-speedometer').boundingBox();
	expect((await space()).clearance).toBeGreaterThanOrEqual(6);
	expect(popup.y+popup.height).toBeLessThanOrEqual(dial.y-4);
	await expect(credit).toBeHidden({timeout: 10_000});
	await page.reload();
	await expect(view).toHaveAttribute('data-terrain-sandbox', /"recipe":/, {timeout: 60_000});
	await view.focus();
	await page.keyboard.press('Control');
	await expect(credit).toContainText('Ghost Nova');
	await expect(credit).toContainText('JAMATAR');
	await expect(credit).toContainText('Midorisuki');
	expect(requested.size).toBe(5);
	await explore.click();
	for(const track of catalog.slice(5)) {
		await next.click();
		await expect(credit.locator('strong')).toHaveText(track.title);
		await expect(credit.locator('[data-artist]')).toHaveText(track.artist ?? '');
		if(track.cover) await expect.poll(() => credit.locator('img').evaluate((img, path) =>
			img.currentSrc.endsWith(path) && img.complete && img.naturalWidth > 0, track.cover)).toBe(true);
		else await expect(credit.locator('img')).toBeHidden();
	}
	expect(requested.size).toBe(catalog.length);
	await next.click();
	await expect(credit.locator('strong')).toHaveText(catalog[0].title);
	await view.evaluate(el => el.dispatchEvent(new CustomEvent('phonics-music-track', {
		detail: {id: 'encore', title: 'Encore', artist: 'Soloist'}
	})));
	await expect(credit).toContainText('Soloist');
	await expect(credit.locator('img')).toBeHidden();
	await expect(credit.locator('[data-album]')).toBeEmpty();
	expect(errors).toEqual([]);
});

test('the browser buffers one song ahead and plays the prepared media element at the handoff', async({page}) => {
	await page.setViewportSize({width:480,height:320});
	await page.addInitScript(() => {
		const create = AudioContext.prototype.createMediaElementSource;
		window.streams = [];
		AudioContext.prototype.createMediaElementSource = function(media) {
			window.streams.push(media);
			return create.call(this,media);
		};
	});
	const requests = new Set();
	page.on('request',r => { if(r.url().endsWith('.mp3')) requests.add(r.url()); });
	await page.goto('/games/terrain-sandbox/');
	const view = page.locator('canvas[data-terrain-sandbox]');
	const title = page.locator('[data-music-credit] strong');
	await expect(view).toHaveAttribute('data-terrain-sandbox', /"recipe":/, {timeout:60_000});
	await view.focus();
	await page.keyboard.press('Control');
	await expect(title).toHaveText(catalog[0].title);
	expect(requests.size).toBe(1);
	// Play through even on a sequential HTTP stream whose server does not advertise seekable byte ranges.
	await page.evaluate(() => { window.streams[0].playbackRate = 12; });
	await expect.poll(() => requests.size, {timeout:45_000}).toBe(2);
	await expect(title).toHaveText(catalog[0].title);
	expect(await page.evaluate(() => window.streams[1].paused)).toBe(true);
	await expect(title).toHaveText(catalog[1].title, {timeout:35_000});
	expect(await page.evaluate(() => window.streams.length === 2 && !window.streams[1].paused)).toBe(true);
	expect(requests.size).toBe(2);
});

test('the Lab title bar plays, pauses and steps through the soundtrack independently of the game', async({page}, testInfo) => {
	await page.setViewportSize({width: 680, height: 460});
	await page.addInitScript(() => {
		const create = AudioContext.prototype.createMediaElementSource;
		window.transportMedia = [];
		AudioContext.prototype.createMediaElementSource = function(media) {
			window.transportMedia.push(media);
			return create.call(this, media);
		};
	});

	await page.goto('/games/terrain-sandbox/');
	const view = page.locator('canvas[data-terrain-sandbox]');
	const panel = page.locator('.terrain-panel');
	const group = panel.getByRole('group', {name: 'Soundtrack controls'});
	const play = group.getByRole('button', {name: 'Play music'});
	const pause = group.getByRole('button', {name: 'Pause music'});
	const previous = group.getByRole('button', {name: 'Previous track'});
	const next = group.getByRole('button', {name: 'Next track'});
	const title = page.locator('[data-music-credit] strong');
	await expect(view).toHaveAttribute('data-terrain-sandbox', /"recipe":/, {timeout: 60_000});
	await play.click();
	await expect(title).toHaveText(catalog[0].title);
	await expect.poll(() => page.evaluate(() => window.transportMedia[0].currentTime)).toBeGreaterThan(.1);
	await pause.focus();
	await page.keyboard.press('Space');
	await expect(pause).toHaveAttribute('aria-pressed', 'true');
	await expect.poll(() => page.evaluate(() => window.transportMedia[0].paused)).toBe(true);
	const parkedAt = await page.evaluate(() => window.transportMedia[0].currentTime);
	const gamePaused = () => view.evaluate(el => JSON.parse(el.dataset.terrainSandbox).paused);
	expect(await gamePaused()).toBe(false);
	await view.focus();
	await page.keyboard.press('p');
	await expect.poll(gamePaused).toBe(true);
	await page.keyboard.press('p');
	await expect.poll(gamePaused).toBe(false);
	expect(await page.evaluate(() => window.transportMedia[0].currentTime)).toBe(parkedAt);

	await panel.getByRole('tab', {name: 'Sky'}).click();
	await play.click();
	await expect(play).toHaveAttribute('aria-pressed', 'true');
	await expect.poll(() => page.evaluate(() => window.transportMedia[0].currentTime)).toBeGreaterThan(parkedAt);
	await pause.click();
	await expect.poll(() => page.evaluate(() => window.transportMedia[0].paused)).toBe(true);
	await previous.click();
	await expect(title).toHaveText(catalog.at(-1).title);
	await next.click();
	await expect(title).toHaveText(catalog[0].title);
	await previous.click();
	await expect(title).toHaveText(catalog.at(-1).title);
	expect(await page.evaluate(() => window.transportMedia.length)).toBe(1);
	await play.click();
	await expect.poll(() => page.evaluate(() => window.transportMedia[1]?.currentTime ?? 0)).toBeGreaterThan(0);
	await next.click();
	await expect(title).toHaveText(catalog[0].title);

	await page.setViewportSize({width: 320, height: 500});
	const heading = panel.locator(':scope > summary');
	const headerBox = await heading.boundingBox(), buttons = await group.boundingBox();
	expect(headerBox.x+headerBox.width).toBeLessThanOrEqual(buttons.x+1);
	expect(buttons.y).toBeCloseTo(headerBox.y);
	await heading.click();
	await expect(group).toBeHidden();
	await heading.click();
	await expect(group).toBeVisible();
	await testInfo.attach('music-transport', {body: await page.screenshot(), contentType: 'image/png'});
});
