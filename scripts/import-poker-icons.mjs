// Converts the supplied 32x32 PNG portraits into deterministic, whole-pixel SVGs for PHP Poker.
// Keep the original pixels under art/icons/<artist>/ so the generated portraits can be reproduced and audited.
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

const colour = (data, offset) => {
	const hex = value => value.toString(16).padStart(2, '0');
	return `#${hex(data[offset])}${hex(data[offset + 1])}${hex(data[offset + 2])}`;
};

for(const [key, entry] of Object.entries(roster))
{
	const png = PNG.sync.read(await fs.readFile(new URL(entry.file, icons)));
	if(png.width !== 32 || png.height !== 32) throw Error(`${entry.file} must be 32x32, got ${png.width}x${png.height}`);
	let rects = '';
	for(let y = 0; y < 32; y++)
	{
		for(let x = 0; x < 32;)
		{
			const offset = (y * 32 + x) * 4;
			const rgba = png.data.subarray(offset, offset + 4);
			let run = 1;
			while(x + run < 32)
			{
				const next = (y * 32 + x + run) * 4;
				if(!rgba.every((value, channel) => value === png.data[next + channel])) break;
				run++;
			}
			if(rgba[3] !== 0)
			{
				const opacity = rgba[3] === 255 ? '' : ` fill-opacity="${(rgba[3] / 255).toFixed(4)}"`;
				rects += `<rect x="${x}" y="${y}" width="${run}" height="1" fill="${colour(png.data, offset)}"${opacity}/>`;
			}
			x += run;
		}
	}
	const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 32 32" shape-rendering="crispEdges"><title>${entry.object}, pixel art by ${entry.author}</title>${rects}</svg>\n`;
	await fs.writeFile(new URL(`${key}.svg`, icons), svg);
}

await fs.writeFile(new URL('roster.json', icons), JSON.stringify({
	grid: 32,
	conversion: 'Supplied PNG pixels converted to run-length SVG rectangles without smoothing; 7Soul source images were nearest-neighbor resampled from 34x34 to 32x32.',
	players: Object.fromEntries(Object.entries(roster).map(([key, entry]) => [key, entry]))
}, null, 2) + '\n');
console.log(`Wrote ${Object.keys(roster).length} 32x32 Poker emblems`);
