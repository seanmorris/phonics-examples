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
	// Fixedsys supplies the DOS card-suit glyphs. Reuse those exact outlines in the modern faces too so the
	// wildcard matrix never falls back to a replacement mark when the visual mode changes.
	for(const character of '♠♥♦♣') for(const face of Object.values(outlines.faces))
		face.glyphs[character] = outlines.faces.dos.glyphs[character];
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
// Player emblems: the roster's 32x32 PNGs keyed by players[].portrait, drawn into the atlas beside the cards at boot.
const roster = JSON.parse(await fs.readFile(new URL('art/icons/roster.json', root), 'utf8'));
for(const [key, entry] of Object.entries(roster.players).sort(([left], [right]) => left.localeCompare(right)))
{
	const file = `art/icons/${entry.file}`;
	cardArt.icons[key] = {file, sha256: hash(await fs.readFile(new URL(file, root)))};
}
const manifest = {
	uiFont: {
		file: 'ui-font.rgba', width: font.width, height: font.height, sha256: hash(font.rgba)
		, metrics: 'ui-font.json', metricsSha256: hash(fontMetrics)
	}
	, cards: cardArt
};
generated.set('textures.json', Buffer.from(JSON.stringify(manifest, null, 2) + '\n'));
// Use the bundled Barlow outlines for the poster too. Host font fallback changes the PNG between
// developer machines and CI, even with identical SVG input and resvg versions.
const posterSource = await fs.readFile(new URL('art/poster.svg', root), 'utf8');
const posterSvg = posterSource.replace(/<text\b([^>]*)>([^<]*)<\/text>/g, (_, attributes, text) => {
	const attrs = Object.fromEntries([...attributes.matchAll(/([\w-]+)="([^"]*)"/g)].map(match => [match[1], match[2]]));
	const face = outlines.faces.bold;
	const size = Number(attrs['font-size']);
	const tracking = Number(attrs['letter-spacing'] ?? 0);
	let width = 0, previous = '', paths = '';
	for(const character of text)
	{
		const glyph = face.glyphs[character];
		if(!glyph) throw Error(`Poster uses an unbundled glyph: ${character}`);
		if(previous) width += (face.kerning[previous + character] ?? 0) * size + tracking;
		if(glyph.path) paths += `<path transform="translate(${width} 0) scale(${size})" d="${glyph.path}"/>`;
		width += glyph.advance * size;
		previous = character;
	}
	const shift = attrs['text-anchor'] === 'middle' ? width / 2 : attrs['text-anchor'] === 'end' ? width : 0;
	return `<g fill="${attrs.fill}" opacity="${attrs.opacity ?? 1}" transform="translate(${Number(attrs.x) - shift} ${Number(attrs.y)})">${paths}</g>`;
});
generated.set('poster.png', Buffer.from(new Resvg(posterSvg, {fitTo: {mode: 'width', value: 1280}, font: {loadSystemFonts: false}}).render().asPng()));

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
