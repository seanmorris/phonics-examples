import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs/promises';

const dist = new URL('../site/dist/', import.meta.url);

test('built catalog exposes separate full, embed, and application routes', async context => {
	try { await fs.access(new URL('catalog.json', dist)); }
	catch { context.skip('site has not been built'); return; }
	const catalog = JSON.parse(await fs.readFile(new URL('catalog.json', dist)));
	assert.equal(catalog.length, 9);
	const billiards = catalog.find(entry => entry.slug === 'php-billiards');
	assert.deepEqual(billiards.routes, {
		full: 'games/php-billiards/'
		, embed: 'embed/php-billiards/'
		, application: 'applications/php-billiards/game.phar'
	});
	for(const game of catalog)
	await Promise.all(Object.values(game.routes).map(route => fs.access(new URL(route.endsWith('/') ? route + 'index.html' : route, dist))));
});

test('built pages size each canvas and publish Doom’s world resources', async context => {
	try { await fs.access(new URL('catalog.json', dist)); }
	catch { context.skip('site has not been built'); return; }

	for(const route of ['games/doom-phonics/index.html', 'embed/doom-phonics/index.html'])
	assert.match(await fs.readFile(new URL(route, dist), 'utf8'), /<canvas [^>]*tabindex="0" width="960" height="540"/);

	// The packaged online world requests its default WADs relative to the PHAR's application base.
	for(const file of ['applications/demo/DOOM1.WAD', 'applications/DOOM1_OGG.wad', 'applications/demo/DBIGFONT.WAD'])
	await fs.access(new URL(file, dist));
});

test('built SDL runtime includes browser controller rumble', async context => {
	try { await fs.access(new URL('runtime/php8.4-sdl.manifest.json', dist)); }
	catch { context.skip('site has not been built'); return; }
	const runtime = await fs.readFile(new URL('runtime/php8.4-sdl-runtime.mjs', dist), 'utf8');

	for(const token of ['vibrationActuator', 'dual-rumble', 'playEffect'])
	assert.ok(runtime.includes(token), `SDL runtime is missing ${token}`);
});
