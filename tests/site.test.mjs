import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs/promises';

const dist = new URL('../site/dist/', import.meta.url);

test('built catalog exposes separate full, embed, and application routes', async context => {
	try { await fs.access(new URL('catalog.json', dist)); }
	catch { context.skip('site has not been built'); return; }
	const catalog = JSON.parse(await fs.readFile(new URL('catalog.json', dist)));
	assert.equal(catalog.length, 7);
	const billiards = catalog.find(entry => entry.slug === 'php-billiards');
	assert.deepEqual(billiards.routes, {
		full: 'games/php-billiards/'
		, embed: 'embed/php-billiards/'
		, application: 'applications/php-billiards/game.phar'
	});
	for(const game of catalog)
	await Promise.all(Object.values(game.routes).map(route => fs.access(new URL(route.endsWith('/') ? route + 'index.html' : route, dist))));
});
