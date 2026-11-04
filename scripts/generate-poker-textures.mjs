// Generates games/php-poker/assets/generated: the signed-distance UI font atlas, its manifest and the catalog
// poster. Card faces and backs are not baked here: the browser rasterises art/cards/*.svg at the device's pixel
// ratio when the game boots, so the package ships the small SVGs instead of a raw atlas. `--check` verifies the
// committed output.
import fs from 'node:fs/promises';
import path from 'node:path';
import {createHash} from 'node:crypto';
import {Resvg} from '@resvg/resvg-js';
import {PNG} from 'pngjs';
import {buildFontAtlas} from './billiards-font.mjs';

const root = new URL('../games/php-poker/', import.meta.url);
const output = new URL('assets/generated/', root);
const check = process.argv.includes('--check');
const hash = bytes => createHash('sha256').update(bytes).digest('hex');
const generated = new Map;

const typography = new URL('art/typography/', root);
const outlines = JSON.parse(await fs.readFile(new URL('barlow-semi-condensed.json', typography), 'utf8'));
// The DOS face is optional until its outlines are extracted; the classic mode falls back to the body face without it.
try
{
	const dos = JSON.parse(await fs.readFile(new URL('fixedsys-excelsior.json', typography), 'utf8'));
	outlines.faces.dos = dos.faces.dos ?? dos.faces.body;
}
catch(error)
{
	if(error.code !== 'ENOENT') throw error;
}
const font = buildFontAtlas(outlines);
const fontPng = new PNG({width: font.width, height: font.height});
font.rgba.copy(fontPng.data);
const fontMetrics = Buffer.from(JSON.stringify(font.metrics) + '\n');
generated.set('ui-font.png', PNG.sync.write(fontPng, {deflateLevel: 9}));
generated.set('ui-font.rgba', font.rgba);
generated.set('ui-font.json', fontMetrics);
generated.set('ui-font-OFL.txt', await fs.readFile(new URL('OFL.txt', typography)));

// Card art manifest for the runtime rasteriser: the sheet is 9x6 cells of 750x1050 in the order spades, hearts,
// clubs, diamonds, then two jokers; the game's suit ids are clubs, diamonds, hearts, spades.
const cardArt = {
	sheet: {file: 'art/cards/deck.svg', columns: 9, rows: 6, sha256: hash(await fs.readFile(new URL('art/cards/deck.svg', root)))}
	, backs: {
		red: {file: 'art/cards/back-red.svg', sha256: hash(await fs.readFile(new URL('art/cards/back-red.svg', root)))}
		, blue: {file: 'art/cards/back-blue.svg', sha256: hash(await fs.readFile(new URL('art/cards/back-blue.svg', root)))}
	}
	, suitRows: [2,3,1,0]
	, aspect: [5,7]
	, icons: {}
};
// Player emblems: whole-pixel SVGs keyed by players[].portrait, rasterised beside the cards at boot.
for(const entry of (await fs.readdir(new URL('art/icons/', root))).filter(name => name.endsWith('.svg')).sort())
{
	const key = entry.slice(0, -4);
	cardArt.icons[key] = {file: `art/icons/${entry}`, sha256: hash(await fs.readFile(new URL(`art/icons/${entry}`, root)))};
}
const manifest = {
	uiFont: {
		file: 'ui-font.rgba', width: font.width, height: font.height, sha256: hash(font.rgba)
		, metrics: 'ui-font.json', metricsSha256: hash(fontMetrics)
	}
	, cards: cardArt
};
generated.set('textures.json', Buffer.from(JSON.stringify(manifest, null, 2) + '\n'));
const posterSvg = await fs.readFile(new URL('art/poster.svg', root), 'utf8');
generated.set('poster.png', Buffer.from(new Resvg(posterSvg, {fitTo: {mode: 'width', value: 1280}}).render().asPng()));

if(check)
{
	const changed = [];
	for(const [name,bytes] of generated)
	{
		const current = await fs.readFile(new URL(name, output)).catch(() => null);
		if(!current?.equals(bytes)) changed.push(name);
	}
	if(changed.length) throw Error(`Generated poker assets are stale: ${changed.join(', ')}`);
	console.log(`Verified ${generated.size} generated poker assets`);
}
else
{
	await fs.mkdir(output, {recursive: true});
	for(const [name,bytes] of generated) await fs.writeFile(new URL(name, output), bytes);
	console.log(`Generated ${generated.size} poker assets in ${path.dirname(new URL('textures.json', output).pathname)}`);
}
