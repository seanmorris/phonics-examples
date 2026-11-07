// Prepares the supplied 32x32 PNG portraits for PHP Poker and writes their roster. The game draws the PNGs as they
// are, so each one is checked for its grid and stripped of colour-management chunks (gAMA, cHRM, sRGB, iCCP):
// browsers otherwise colour-correct those files, shifting the palette the artist drew by a few levels. Pixel data
// is never rewritten.
import fs from 'node:fs/promises';
import {PNG} from 'pngjs';

const icons = new URL('../games/php-poker/art/icons/', import.meta.url);
const roster = {
	ada: {file: 'rpg-icons-extra/clock.png', object: 'Clock', style: 'Patient', author: '7Soul', license: 'CC-BY-3.0', source: 'https://opengameart.org/content/98-pixel-art-rpg-icons'},
	jules: {file: 'thekingpheonix/doughnut.png', object: 'Doughnut', style: 'Bold', author: 'thekingphoenix', license: 'CC0-1.0', source: 'https://opengameart.org/content/icons-food'},
	mara: {file: 'thekingpheonix/apple.png', object: 'Apple', style: 'Balanced', author: 'thekingphoenix', license: 'CC0-1.0', source: 'https://opengameart.org/content/icons-food'},
	otis: {file: 'clint-bellanger/coffee.png', object: 'Mug', style: 'Defensive', author: 'Clint Bellanger', license: 'CC-BY-3.0', source: 'https://opengameart.org/content/recycle-items-set'},
	cleo: {file: 'thekingpheonix/gem.png', object: 'Gem', style: 'Aggressive', author: 'thekingphoenix', license: 'CC0-1.0', source: 'https://opengameart.org/node/77641'},
	felix: {file: 'rpg-icons-extra/ring.png', object: 'Ring', style: 'Measured', author: '7Soul', license: 'CC-BY-3.0', source: 'https://opengameart.org/content/98-pixel-art-rpg-icons'},
	nora: {file: 'rpg-icons-extra/shamrock.png', object: 'Shamrock', style: 'Opportunistic', author: '7Soul', license: 'CC-BY-3.0', source: 'https://opengameart.org/content/98-pixel-art-rpg-icons'}
};

const colourChunks = new Set(['gAMA', 'cHRM', 'sRGB', 'iCCP']);

/** Return the PNG without its colour-management chunks; every other chunk keeps its exact bytes. */
const withoutColourChunks = bytes => {
	const kept = [bytes.subarray(0, 8)];
	for(let offset = 8; offset < bytes.length;)
	{
		const length = bytes.readUInt32BE(offset), end = offset + 12 + length;
		if(!colourChunks.has(bytes.toString('latin1', offset + 4, offset + 8))) kept.push(bytes.subarray(offset, end));
		offset = end;
	}
	return Buffer.concat(kept);
};

let stripped = 0;
for(const entry of Object.values(roster))
{
	const url = new URL(entry.file, icons), bytes = await fs.readFile(url), clean = withoutColourChunks(bytes);
	const png = PNG.sync.read(clean);
	if(png.width !== 32 || png.height !== 32) throw Error(`${entry.file} must be 32x32, got ${png.width}x${png.height}`);
	if(!clean.equals(bytes))
	{
		await fs.writeFile(url, clean);
		stripped++;
	}
}

await fs.writeFile(new URL('roster.json', icons), JSON.stringify({
	grid: 32,
	conversion: 'Supplied PNG pixels are drawn as they are, without colour-management chunks so browsers keep the exact palette; 7Soul source images were nearest-neighbor resampled from 34x34 to 32x32.',
	players: Object.fromEntries(Object.entries(roster).map(([key, entry]) => [key, entry]))
}, null, 2) + '\n');
console.log(`Prepared ${Object.keys(roster).length} 32x32 Poker emblems (${stripped} stripped of colour chunks)`);
