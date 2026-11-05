// Renders the opponents' emblems from Pxlkit icon data into whole-pixel SVGs under games/php-poker/art/icons.
// Pxlkit icons are 16x16 grids with a palette; each is written at 4x (64x64) with shape-rendering crispEdges and
// recoloured to the player's own material. The assets are free with a visible "Icons by Pxlkit" credit
// (see art/icons/LICENSE-ASSETS); the packs are not a dependency of the examples, so install them first:
//   npm install --no-save @pxlkit/gamification @pxlkit/feedback
//   node scripts/import-pxlkit-icons.mjs [path/to/node_modules]
import fs from 'node:fs/promises';
import {createRequire} from 'node:module';

const modules = process.argv[2] ?? new URL('../node_modules/', import.meta.url).pathname;
const require = createRequire(import.meta.url);
const icons = new URL('../games/php-poker/art/icons/', import.meta.url);
const SCALE = 4;

// Player → Pxlkit icon, with the colours remapped so no two emblems share a material.
const roster = {
	ada: {pack: 'feedback', icon: 'hourglass', object: 'Hourglass', style: 'Patient', recolour: {'#DAA520': '#8a93a6', '#F4D03F': '#d8dde6', '#FFF8DC': '#f2f5fa', '#B8860B': '#5f6778'}}
	, jules: {pack: 'gamification', icon: 'dice', object: 'Dice', style: 'Bold', recolour: {}}
	, mara: {pack: 'gamification', icon: 'quest-compass', object: 'Compass', style: 'Balanced', recolour: {}}
	, otis: {pack: 'gamification', icon: 'shield', object: 'Shield', style: 'Defensive', recolour: {}}
	, cleo: {pack: 'gamification', icon: 'fire', object: 'Fire', style: 'Aggressive', recolour: {}}
	, felix: {pack: 'gamification', icon: 'target', object: 'Target', style: 'Measured', recolour: {}}
	, nora: {pack: 'gamification', icon: 'key', object: 'Key', style: 'Opportunistic', recolour: {'#FFD700': '#b095db', '#B8860B': '#6f55a8'}}
};

const packs = {};
const load = pack => packs[pack] ??= require(`${modules}/@pxlkit/${pack}/dist/index.cjs`);
const find = (pack, name) => Object.values(load(pack)).find(value => value && typeof value === 'object' && value.name === name);

for(const [key, entry] of Object.entries(roster))
{
	const icon = find(entry.pack, entry.icon);
	if(!icon) throw Error(`Pxlkit ${entry.pack} has no icon ${entry.icon}`);
	const grid = icon.grid ?? icon.frames?.[0]?.grid ?? icon.frames?.[0];
	const palette = icon.palette ?? icon.frames?.[0]?.palette;
	if(!Array.isArray(grid) || !palette) throw Error(`Pxlkit ${entry.icon} has no static grid`);
	let rects = '';
	grid.forEach((row, y) => [...row].forEach((cell, x) => {
		if(cell === '.' || !palette[cell]) return;
		const colour = entry.recolour[palette[cell].toUpperCase()] ?? palette[cell];
		rects += `<rect x="${x * SCALE}" y="${y * SCALE}" width="${SCALE}" height="${SCALE}" fill="${colour}"/>`;
	}));
	const size = grid.length * SCALE;
	const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="${size}" height="${size}" viewBox="0 0 ${size} ${size}" shape-rendering="crispEdges"><title>${entry.object}, Pxlkit ${entry.icon}</title>${rects}</svg>\n`;
	await fs.writeFile(new URL(`${key}.svg`, icons), svg);
	entry.source = `@pxlkit/${entry.pack}/${entry.icon}`;
}

await fs.writeFile(new URL('roster.json', icons), JSON.stringify({
	source: 'Pxlkit pixel art icons, https://pxlkit.xyz, rendered from @pxlkit pack data at 4x with per-player recolours'
	, license: 'Pxlkit Asset License (free with visible attribution: Icons by Pxlkit, https://pxlkit.xyz); see LICENSE-ASSETS'
	, grid: 16, scale: SCALE
	, players: Object.fromEntries(Object.entries(roster).map(([key, entry]) => [key, {icon: entry.source, object: entry.object, style: entry.style, recolour: entry.recolour}]))
}, null, 2) + '\n');
console.log(`Wrote ${Object.keys(roster).length} emblems`);
