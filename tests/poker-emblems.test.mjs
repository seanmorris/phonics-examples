import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import {PNG} from 'pngjs';

const root = new URL('../games/php-poker/', import.meta.url);
const chunks = bytes => {
	const names = [];
	for(let offset = 8; offset < bytes.length; offset += 12 + bytes.readUInt32BE(offset)) names.push(bytes.toString('latin1', offset + 4, offset + 8));
	return names;
};

// The browser draws each emblem PNG straight into the card atlas. A colour-management chunk would let it shift the
// artist's palette, and any other size would be scaled on the emblems' 32 px grid.
test('every Poker emblem is a 32x32 PNG from the roster that the browser draws without colour correction', () => {
	const textures = JSON.parse(fs.readFileSync(new URL('assets/generated/textures.json', root), 'utf8'));
	const roster = JSON.parse(fs.readFileSync(new URL('art/icons/roster.json', root), 'utf8'));
	assert.deepEqual(Object.keys(textures.cards.icons).sort(), Object.keys(roster.players).sort());
	for(const [key, icon] of Object.entries(textures.cards.icons))
	{
		assert.equal(icon.file, `art/icons/${roster.players[key].file}`, `${key} comes from its roster entry`);
		const bytes = fs.readFileSync(new URL(icon.file, root)), png = PNG.sync.read(bytes);
		assert.deepEqual([png.width, png.height], [32, 32], `${key} is 32x32`);
		assert.deepEqual(chunks(bytes).filter(name => ['gAMA', 'cHRM', 'sRGB', 'iCCP'].includes(name)), [], `${key} has no colour chunks`);
	}
	assert.deepEqual(fs.readdirSync(new URL('art/icons/', root)).filter(name => name.endsWith('.svg')), [], 'no derived SVG emblems remain');
});
