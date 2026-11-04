// Gives each opponent emblem its own palette. Codex drew the seven icons on one shared 16-colour palette; this maps
// the gold family (and the rocket's red) to a distinct material per player so no two emblems read alike at a glance.
// Run once after new icons arrive: node scripts/recolour-poker-icons.mjs
import fs from 'node:fs/promises';

const icons = new URL('../games/php-poker/art/icons/', import.meta.url);
const GOLD = {light: '#ffe0a0', mid: '#ffc75e', dark: '#bf843d'};
const materials = {
	ada: {[GOLD.light]: '#f2f5fa', [GOLD.mid]: '#d8dde6', [GOLD.dark]: '#8a93a6'} // silver pocket watch
	, jules: {'#e24b4b': '#3267a0', '#8a2d48': '#1f4a7a'} // white rocket with blue stripes; flames stay gold
	, mara: {} // brass compass keeps the original gold
	, otis: {[GOLD.light]: '#c98c5a', [GOLD.mid]: '#a3663d', [GOLD.dark]: '#7f5144'} // wooden umbrella handle
	, cleo: {} // red chili with a green stem
	, felix: {[GOLD.light]: '#f0b38a', [GOLD.mid]: '#d9895a', [GOLD.dark]: '#8f4d2b'} // copper horseshoe
	, nora: {[GOLD.light]: '#d9c9f2', [GOLD.mid]: '#b095db', [GOLD.dark]: '#6f55a8'} // violet key
};

for(const [name, map] of Object.entries(materials))
{
	const file = new URL(`${name}.svg`, icons);
	const svg = await fs.readFile(file, 'utf8');
	const recoloured = svg.replace(/fill="(#[0-9a-f]{6})"/g, (match, colour) => map[colour] ? `fill="${map[colour]}"` : match);
	await fs.writeFile(file, recoloured);
	console.log(`${name}: ${Object.keys(map).length} colours mapped`);
}
