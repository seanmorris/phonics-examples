import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs/promises';

const root = new URL('../', import.meta.url);
const game = new URL('games/php-billiards/', root);

test('billiards metadata declares stable full and embed dimensions', async() => {
	const data = JSON.parse(await fs.readFile(new URL('example.json', game)));
	assert.equal(data.schemaVersion, 1);
	assert.equal(data.slug, 'php-billiards');
	assert.deepEqual(data.canvas, {width: 1280,height: 720});
	assert.equal(data.aspectRatio, '16/9');
});

test('billiards is a direct application without world adapters', async() => {
	const manifest = JSON.parse(await fs.readFile(new URL('phonics.json', game)));
	const files = [
		new URL('application.php', game)
		, new URL('browser/game.mjs', game)
		, ...manifest.php.files.map(name => new URL(name, game))
	];
	const source = (await Promise.all(files.map(file => fs.readFile(file, 'utf8')))).join('\n');
	assert.equal(manifest.application.entry, 'application.php');
	assert.equal(manifest.tiled, undefined);
	assert.doesNotMatch(source, /Tiled|Doom|WAD|->compose\s*\(/i);
});

test('framework dependencies are pinned to an immutable commit', async() => {
	const packageData = JSON.parse(await fs.readFile(new URL('package.json', root)));
	const composer = JSON.parse(await fs.readFile(new URL('composer.json', root)));
	assert.match(packageData.dependencies['@seanmorris/phonics'], /#[0-9a-f]{40}$/);
	assert.match(composer.require['seanmorris/phonics'], /#[0-9a-f]{40}$/);
});

