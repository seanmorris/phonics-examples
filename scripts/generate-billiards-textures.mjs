import fs from 'node:fs/promises';
import path from 'node:path';
import {createHash} from 'node:crypto';
import {Resvg} from '@resvg/resvg-js';
import {PNG} from 'pngjs';
import {buildFontAtlas} from './billiards-font.mjs';

const root = new URL('../games/php-billiards/', import.meta.url);
const source = new URL('art/svg/', root);
const output = new URL('assets/generated/', root);
const check = process.argv.includes('--check');
const hash = bytes => createHash('sha256').update(bytes).digest('hex');
const generated = new Map;

/**
 * Make a tile wrap seamlessly by crossfading its borders toward copies shifted half a tile.
 *
 * resvg's turbulence does not stitch at the tile edge, so the wood showed a seam every repeat along the rails. One pass
 * per axis: near the left and right borders the output becomes the copy shifted half a tile along x, whose pixels there
 * were interior neighbours, and likewise for top and bottom. Each copy is used only away from its own centre seam, and
 * the second pass mixes pixels within a column, so it keeps the first pass's wrap.
 */
const seamless = image => {
	const {width, height, data} = image;
	const weight = (position, size) => {
		const edge = Math.min(position + .5, size - position - .5) / (size / 2);
		return 1 - Math.min(1, Math.max(0, (edge - .1) / .4));
	};
	for(const axis of ['x','y'])
	{
		const source = Buffer.from(data);
		for(let y = 0; y < height; y++) for(let x = 0; x < width; x++)
		{
			const mix = axis === 'x' ? weight(x, width) : weight(y, height);
			const own = (y * width + x) * 4;
			const shifted = axis === 'x'
				? (y * width + (x + width / 2) % width) * 4
				: (((y + height / 2) % height) * width + x) * 4;
			for(let channel = 0; channel < 4; channel++)
			data[own + channel] = Math.round(source[own + channel] * (1 - mix) + source[shifted + channel] * mix);
		}
	}
	return image;
};

const textures=[['ballAtlas','ball-atlas.svg'],['wood','wood.svg'],['felt','felt.svg']];
for(const [name,file] of textures)
{
	const svg = await fs.readFile(new URL(file, source));
	let png = new Resvg(svg, {fitTo: {mode: 'original'}}).render().asPng();
	let decoded = PNG.sync.read(png);
	if(name === 'wood')
	{
		decoded = seamless(decoded);
		png = PNG.sync.write(decoded);
	}
	const stem = file.replace('.svg', '');
	generated.set(`${stem}.png`, png);
	generated.set(`${stem}.rgba`, decoded.data);
	generated.set(name, {
		file: `${stem}.rgba`
		, width: decoded.width
		, height: decoded.height
		, sha256: hash(decoded.data)
	});
}

const typography = new URL('art/typography/', root);
const font = buildFontAtlas(JSON.parse(await fs.readFile(new URL('barlow-semi-condensed.json', typography), 'utf8')));
const fontPng = new PNG({width: font.width, height: font.height});
font.rgba.copy(fontPng.data);
const fontMetrics = Buffer.from(JSON.stringify(font.metrics) + '\n');
generated.set('ui-font.png', PNG.sync.write(fontPng, {deflateLevel: 9}));
generated.set('ui-font.rgba', font.rgba);
generated.set('ui-font.json', fontMetrics);
// The atlas is derived from Barlow, so its Open Font License ships beside it.
generated.set('ui-font-OFL.txt', await fs.readFile(new URL('OFL.txt', typography)));

const manifest = Object.fromEntries([...generated].filter(([name]) => textures.some(([texture])=>texture===name)));
for(const [name] of textures) generated.delete(name);
manifest.uiFont = {
	file: 'ui-font.rgba'
	, width: font.width
	, height: font.height
	, sha256: hash(font.rgba)
	, metrics: 'ui-font.json'
	, metricsSha256: hash(fontMetrics)
};
generated.set('textures.json', Buffer.from(JSON.stringify(manifest, null, 2) + '\n'));
generated.set('poster.png', await fs.readFile(new URL('art/screenshots/title.png', root)));

if(check)
{
	const changed = [];

	for(const [name,bytes] of generated)
	{
		const current = await fs.readFile(new URL(name, output)).catch(() => null);

		if(!current?.equals(bytes)) changed.push(name);
	}

	if(changed.length) throw Error(`Generated billiards assets are stale: ${changed.join(', ')}`);

	console.log(`Verified ${generated.size} generated billiards assets`);
}
else
{
	await fs.mkdir(output, {recursive: true});

	for(const [name,bytes] of generated) await fs.writeFile(new URL(name, output), bytes);

	console.log(`Generated ${generated.size} billiards assets in ${path.dirname(new URL('textures.json', output).pathname)}`);
}
