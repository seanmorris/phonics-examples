// Generates games/phlappy-phant's pixel art and sounds: the elePHPant sprite frames rasterised from the GPL
// vector in art/elephpant.svg (see art/ELEPHPANT-LICENSE.md), the procedural DB-silo barrel, ground and cloud
// tiles, the tileset description, three synthesised PCM sounds and the catalog poster. Every byte is
// deterministic so `--check` can verify the committed output; `--preview DIR` also writes 8x nearest-neighbour
// enlargements for eyeballing the sheet.
import fs from 'node:fs/promises';
import path from 'node:path';
import {Resvg} from '@resvg/resvg-js';
import {PNG} from 'pngjs';

const root = new URL('../games/phlappy-phant/', import.meta.url);
const engine = new URL('../node_modules/@seanmorris/phonics/resources/', import.meta.url);
const check = process.argv.includes('--check');
const previewIndex = process.argv.indexOf('--preview');
const previewDirectory = previewIndex > 0 ? process.argv[previewIndex + 1] : null;
const generated = new Map;

// The whole game uses these ten colours. The elePHPant keeps its own blues and ink; the barrels are steel teal
// with pale rims; the ground is cream over tan.
const palette = {
	ink: [0x36, 0x32, 0x54]
	, body: [0x8b, 0x9d, 0xd7]
	, light: [0xa6, 0xbd, 0xe6]
	, shade: [0x50, 0x5e, 0x9b]
	, white: [0xff, 0xff, 0xff]
	, teal: [0x2f, 0x6f, 0x73]
	, tealShade: [0x1f, 0x4f, 0x54]
	, rim: [0xbf, 0xe3, 0xdf]
	, cream: [0xf1, 0xe4, 0xbf]
	, tan: [0xc9, 0xa9, 0x6a]
};

const TILE_W = 56, TILE_H = 32, COLUMNS = 4, TILES = 8;
const SHEET_W = TILE_W * COLUMNS, SHEET_H = TILE_H * Math.ceil(TILES / COLUMNS);
const PHANT_W = 40, PHANT_H = 28, PHANT_OFFSET = [8, 2];
const SUPERSAMPLE = 8;
// Ear region of the right-facing 40x28 sprite, in sprite pixels; the flap frames shift it by two rows.
const EAR = {x: 11, y: 1, w: 12, h: 14};
// Flank region that carried the "php" lettering, and the eye pixels painted after cleanup (sprite pixels).
const TEXT = {x: 8, y: 6, w: 23, h: 12};
const SHADE_Y = 21;
const EYE = [[34, 7, 'ink'], [35, 7, 'ink'], [34, 8, 'ink'], [35, 8, 'white']];
const same = (a, b) => a[0] === b[0] && a[1] === b[1] && a[2] === b[2];

/** A simple RGBA raster with out-of-range writes ignored. */
class Raster
{
	constructor(width, height)
	{
		this.width = width;
		this.height = height;
		this.data = new Uint8Array(width * height * 4);
	}

	get(x, y)
	{
		if(x < 0 || y < 0 || x >= this.width || y >= this.height) return [0, 0, 0, 0];

		const at = (y * this.width + x) * 4;

		return [this.data[at], this.data[at + 1], this.data[at + 2], this.data[at + 3]];
	}

	put(x, y, rgb, alpha = 255)
	{
		if(x < 0 || y < 0 || x >= this.width || y >= this.height) return;

		const at = (y * this.width + x) * 4;
		this.data[at] = rgb[0];
		this.data[at + 1] = rgb[1];
		this.data[at + 2] = rgb[2];
		this.data[at + 3] = alpha;
	}

	fill(x0, y0, width, height, rgb)
	{
		for(let y = y0; y < y0 + height; y++) for(let x = x0; x < x0 + width; x++) this.put(x, y, rgb);
	}

	blit(source, dx, dy, scale = 1, tint = null, region = null)
	{
		const [sx, sy, sw, sh] = region ?? [0, 0, source.width, source.height];

		for(let y = 0; y < sh; y++) for(let x = 0; x < sw; x++)
		{
			const pixel = source.get(sx + x, sy + y);

			if(pixel[3] < 128) continue;

			const rgb = tint ?? pixel;

			for(let oy = 0; oy < scale; oy++) for(let ox = 0; ox < scale; ox++)
				this.put(dx + x * scale + ox, dy + y * scale + oy, rgb);
		}
	}

	mirrored()
	{
		const copy = new Raster(this.width, this.height);

		for(let y = 0; y < this.height; y++) for(let x = 0; x < this.width; x++)
		{
			const [r, g, b, a] = this.get(x, y);
			copy.put(this.width - 1 - x, y, [r, g, b], a);
		}

		return copy;
	}

	flippedVertically()
	{
		const copy = new Raster(this.width, this.height);

		for(let y = 0; y < this.height; y++) for(let x = 0; x < this.width; x++)
		{
			const [r, g, b, a] = this.get(x, y);
			copy.put(x, this.height - 1 - y, [r, g, b], a);
		}

		return copy;
	}

	png()
	{
		const image = new PNG({width: this.width, height: this.height});
		image.data = Buffer.from(this.data);

		return PNG.sync.write(image, {deflateLevel: 9});
	}
}

const nearest = (rgb, colours) => {
	let best = colours[0], bestDistance = Infinity;

	for(const colour of colours)
	{
		const distance = (rgb[0] - colour[0]) ** 2 + (rgb[1] - colour[1]) ** 2 + (rgb[2] - colour[2]) ** 2;

		if(distance < bestDistance) [best, bestDistance] = [colour, distance];
	}

	return best;
};

/** Rasterise the elePHPant, shrink it with a box filter, snap it to the sprite palette and ink its outline. */
async function phant()
{
	const svg = await fs.readFile(new URL('art/elephpant.svg', root));
	const rendered = new Resvg(svg, {fitTo: {mode: 'width', value: PHANT_W * SUPERSAMPLE}, font: {loadSystemFonts: false}})
		.render();
	const image = PNG.sync.read(Buffer.from(rendered.asPng()));
	const sprite = new Raster(PHANT_W, PHANT_H);

	for(let y = 0; y < PHANT_H; y++) for(let x = 0; x < PHANT_W; x++)
	{
		let r = 0, g = 0, b = 0, a = 0, samples = 0;

		for(let oy = 0; oy < SUPERSAMPLE; oy++) for(let ox = 0; ox < SUPERSAMPLE; ox++)
		{
			const sx = x * SUPERSAMPLE + ox, sy = y * SUPERSAMPLE + oy;

			if(sx >= image.width || sy >= image.height) continue;

			const at = (sy * image.width + sx) * 4;
			const alpha = image.data[at + 3];
			r += image.data[at] * alpha;
			g += image.data[at + 1] * alpha;
			b += image.data[at + 2] * alpha;
			a += alpha;
			samples++;
		}

		if(!samples || a / samples < 128) continue;

		const rgb = [r / a, g / a, b / a];
		const luminance = (.299 * rgb[0] + .587 * rgb[1] + .114 * rgb[2]) / 255;
		// Keep only the vector's ink strokes (ear edge, trunk, legs) and its white glint; flatten every gradient
		// to the body tone, and add the sprite's own two-band shading afterwards.
		sprite.put(x, y, luminance < .40 ? palette.ink : luminance > .95 ? palette.white : palette.body);
	}

	// Gradients quantise to speckle: let each interior pixel take the majority tone of its neighbourhood, twice.
	for(let pass = 0; pass < 2; pass++)
	{
		const smoothed = new Raster(PHANT_W, PHANT_H);
		smoothed.data.set(sprite.data);

		for(let y = 0; y < PHANT_H; y++) for(let x = 0; x < PHANT_W; x++)
		{
			const pixel = sprite.get(x, y);

			if(pixel[3] < 128 || same(pixel, palette.ink)) continue;

			const votes = new Map;

			for(let dy = -1; dy <= 1; dy++) for(let dx = -1; dx <= 1; dx++)
			{
				const neighbour = sprite.get(x + dx, y + dy);

				if(neighbour[3] < 128 || same(neighbour, palette.ink)) continue;

				const key = neighbour.slice(0, 3).join(',');
				votes.set(key, (votes.get(key) ?? 0) + 1);
			}

			const [winner] = [...votes.entries()].sort((a, b) => b[1] - a[1])[0] ?? [];

			if(winner) smoothed.put(x, y, winner.split(',').map(Number));
		}

		sprite.data.set(smoothed.data);
	}

	// Every opaque pixel that touches transparency becomes ink, which gives the shrunken vector a crisp outline.
	// The vector faces left; the game scrolls to the right.
	const facing = outline(sprite).mirrored();

	// Flat shading: a lighter back, a darker belly and legs.
	for(let y = 0; y < PHANT_H; y++) for(let x = 0; x < PHANT_W; x++)
	{
		if(!same(facing.get(x, y), palette.body)) continue;

		if(y <= 3) facing.put(x, y, palette.light);
		else if(y >= SHADE_Y) facing.put(x, y, palette.shade);
	}

	// The "php" lettering on the flank turns to speckle at this size: paint it out with the surrounding body
	// tones so the flank reads as one shape, leaving the outline, eye and trunk alone.
	for(let y = TEXT.y; y < TEXT.y + TEXT.h; y++) for(let x = TEXT.x; x < TEXT.x + TEXT.w; x++)
	{
		const pixel = facing.get(x, y);

		if(pixel[3] < 128 || !(same(pixel, palette.ink) || same(pixel, palette.white))) continue;

		const edge = [[1, 0], [-1, 0], [0, 1], [0, -1]].some(([dx, dy]) => facing.get(x + dx, y + dy)[3] < 128);

		if(!edge) facing.put(x, y, palette.body);
	}

	for(const [x, y, colour] of EYE) facing.put(x, y, palette[colour]);

	return facing;
}

/** Lift or drop the ear by two rows over a body-coloured patch. */
function earFrame(base, dy)
{
	const frame = new Raster(base.width, base.height);
	frame.data.set(base.data);

	for(let y = EAR.y; y < EAR.y + EAR.h; y++) for(let x = EAR.x; x < EAR.x + EAR.w; x++)
		if(base.get(x, y)[3] >= 128) frame.put(x, y, palette.body);

	frame.blit(base, EAR.x, EAR.y + dy, 1, null, [EAR.x, EAR.y, EAR.w, EAR.h]);

	return outline(frame);
}

/** Ink every opaque pixel that touches transparency. */
function outline(raster)
{
	const inked = new Raster(raster.width, raster.height);

	for(let y = 0; y < raster.height; y++) for(let x = 0; x < raster.width; x++)
	{
		const pixel = raster.get(x, y);

		if(pixel[3] < 128) continue;

		const edge = [[1, 0], [-1, 0], [0, 1], [0, -1]].some(([dx, dy]) => raster.get(x + dx, y + dy)[3] < 128);
		inked.put(x, y, edge ? palette.ink : pixel);
	}

	return inked;
}

/** Colour of one row of the stacked barrel ring; the cap reuses rows 16 to 31 so stacks join seamlessly. */
function ringPixel(x, y)
{
	if(x < 2 || x > 53) return null;
	if(x === 2 || x === 53) return palette.ink;
	if(y < 2) return palette.rim;
	if(y > 28) return palette.tealShade;
	if(x >= 6 && x <= 7) return palette.rim;
	if(x >= 47 && x <= 50) return palette.tealShade;

	return palette.teal;
}

function barrelCap()
{
	const tile = new Raster(TILE_W, TILE_H);
	const inset = [6, 3, 1, 0];

	for(let y = 0; y < 16; y++)
	{
		const left = inset[Math.min(y, 3)], right = TILE_W - 1 - left;

		for(let x = left; x <= right; x++)
		{
			let colour = palette.teal;

			if(y === 0 || x === left || x === right || y >= 14) colour = palette.ink;
			else if(y <= 5) colour = palette.rim;
			else if(x >= 6 && x <= 8) colour = palette.rim;
			else if(x >= 46 && x <= 50) colour = palette.tealShade;

			tile.put(x, y, colour);
		}
	}

	for(let y = 16; y < TILE_H; y++) for(let x = 0; x < TILE_W; x++)
	{
		const colour = ringPixel(x, y);

		if(colour) tile.put(x, y, colour);
	}

	return tile;
}

function barrelRing()
{
	const tile = new Raster(TILE_W, TILE_H);

	for(let y = 0; y < TILE_H; y++) for(let x = 0; x < TILE_W; x++)
	{
		const colour = ringPixel(x, y);

		if(colour) tile.put(x, y, colour);
	}

	return tile;
}

function ground()
{
	const tile = new Raster(TILE_W, TILE_H);

	for(let y = 0; y < TILE_H; y++) for(let x = 0; x < TILE_W; x++)
	{
		let colour = palette.tan;

		if(y < 2) colour = palette.ink;
		else if(y < 8) colour = y === 5 && x % 14 < 6 ? palette.tan : palette.cream;
		else if((x * 7 + y * 3) % 23 === 0) colour = palette.tealShade;

		tile.put(x, y, colour);
	}

	return tile;
}

function cloud()
{
	const tile = new Raster(TILE_W, TILE_H);
	const lobes = [[16, 18, 10], [30, 13, 12], [43, 19, 9], [28, 22, 8]];

	for(let y = 0; y < TILE_H; y++) for(let x = 0; x < TILE_W; x++)
	{
		const inside = lobes.some(([cx, cy, radius]) => (x - cx) ** 2 + ((y - cy) * 1.3) ** 2 <= radius ** 2);

		if(inside) tile.put(x, y, y >= 22 ? palette.light : palette.white);
	}

	return tile;
}

/** Deterministic 16-bit mono PCM at 22050 Hz from a sample function of seconds. */
function wav(seconds, sample)
{
	const rate = 22050, count = Math.round(seconds * rate);
	const buffer = Buffer.alloc(44 + count * 2);
	buffer.write('RIFF', 0);
	buffer.writeUInt32LE(36 + count * 2, 4);
	buffer.write('WAVE', 8);
	buffer.write('fmt ', 12);
	buffer.writeUInt32LE(16, 16);
	buffer.writeUInt16LE(1, 20);
	buffer.writeUInt16LE(1, 22);
	buffer.writeUInt32LE(rate, 24);
	buffer.writeUInt32LE(rate * 2, 28);
	buffer.writeUInt16LE(2, 32);
	buffer.writeUInt16LE(16, 34);
	buffer.write('data', 36);
	buffer.writeUInt32LE(count * 2, 40);
	let noise = 0x2545f491;

	for(let index = 0; index < count; index++)
	{
		noise = (Math.imul(noise, 1664525) + 1013904223) >>> 0;
		const value = Math.max(-1, Math.min(1, sample(index / rate, noise / 0x100000000 * 2 - 1)));
		buffer.writeInt16LE(Math.round(value * 32767), 44 + index * 2);
	}

	return buffer;
}

const flapSound = () => wav(.09, t => {
	const frequency = 320 + (900 - 320) * (t / .09);

	return Math.sin(2 * Math.PI * frequency * t) * Math.exp(-t * 28) * .8;
});

const dingSound = () => wav(.16, t => {
	const frequency = t < .08 ? 880 : 1320;

	return Math.sin(2 * Math.PI * frequency * t) * Math.exp(-(t % .08) * 22) * .7;
});

const bonkSound = () => wav(.22, (t, random) => {
	const frequency = 160 - (160 - 55) * Math.min(1, t / .22);
	const body = Math.tanh(Math.sin(2 * Math.PI * frequency * t) * 2.5) * Math.exp(-t * 11) * .9;
	const burst = t < .04 ? random * (1 - t / .04) * .5 : 0;

	return body + burst;
});

/** Blit a string from the engine's bitmap font in ink at a pixel scale. */
async function title(target, text, x, y, scale)
{
	const font = JSON.parse(await fs.readFile(new URL('font.tsj', engine), 'utf8'));
	const image = PNG.sync.read(await fs.readFile(new URL('font.png', engine)));
	const glyphs = new Raster(image.width, image.height);
	glyphs.data.set(image.data);
	const tiles = new Map(font.tiles.map(tile => {
		const properties = Object.fromEntries(tile.properties.map(property => [property.name, property.value]));

		return [properties['glyph.char'], {...properties, id: tile.id}];
	}));
	const space = font.properties.find(property => property.name === 'font.spaceAdvance')?.value ?? 6;
	let cursor = x;

	for(const character of text)
	{
		if(character === ' ') { cursor += space * scale; continue; }

		const glyph = tiles.get(character) ?? tiles.get(character.toLowerCase());

		if(!glyph) continue;

		const column = glyph.id % font.columns, row = Math.floor(glyph.id / font.columns);

		if(target) target.blit(glyphs, cursor, y, scale, palette.ink, [
			column * font.tilewidth, row * font.tileheight, glyph['glyph.width'], glyph['glyph.height']
		]);

		cursor += glyph['glyph.advance'] * scale;
	}

	return cursor - x;
}

const base = await phant();
const frames = [base, earFrame(base, -2), earFrame(base, 2)];
const cap = barrelCap(), ring = barrelRing(), bottom = cap.flippedVertically(), soil = ground(), puff = cloud();
const sheet = new Raster(SHEET_W, SHEET_H);
const tiles = [...frames.map(frame => ({raster: frame, offset: PHANT_OFFSET})), cap, ring, bottom, soil, puff]
	.map(tile => tile.raster ? tile : {raster: tile, offset: [0, 0]});

tiles.forEach(({raster, offset}, index) => {
	sheet.blit(raster, (index % COLUMNS) * TILE_W + offset[0], Math.floor(index / COLUMNS) * TILE_H + offset[1]);
});

generated.set('assets/sprites.png', sheet.png());
generated.set('assets/sprites.tsj', Buffer.from(JSON.stringify({
	type: 'tileset'
	, version: '1.10'
	, name: 'Phlappy Phant sprites'
	, tilewidth: TILE_W
	, tileheight: TILE_H
	, tilecount: TILES
	, columns: COLUMNS
	, image: 'sprites.png'
	, imagewidth: SHEET_W
	, imageheight: SHEET_H
}, null, 2) + '\n'));
generated.set('assets/flap.wav', flapSound());
generated.set('assets/ding.wav', dingSound());
generated.set('assets/bonk.wav', bonkSound());

// Poster: a daytime scene at four times pixel scale with the title set in the engine font.
const poster = new Raster(1280, 720);
poster.fill(0, 0, 1280, 720, [0xcf, 0xe6, 0xf5]);
for(const [x, y] of [[90, 90], [420, 150], [760, 70], [1080, 170], [300, 260]]) poster.blit(puff, x, y, 4);
poster.fill(0, 560, 1280, 32, palette.cream);
for(let x = 0; x < 1280; x += TILE_W * 4) poster.blit(soil, x, 592, 4);
for(const [x, gapTop, gap] of [[700, 150, 190], [1000, 260, 180]])
{
	for(let y = gapTop - TILE_H * 4; y > -TILE_H * 4; y -= TILE_H * 4) poster.blit(y === gapTop - TILE_H * 4 ? cap : ring, x, y, 4);
	for(let y = gapTop + gap; y < 592; y += TILE_H * 4) poster.blit(y === gapTop + gap ? bottom : ring, x, y, 4);
}
poster.blit(frames[1], 240, 300, 8);
const width = await title(null, 'PHLAPPY PHANT', 0, 48, 6);
await title(poster, 'PHLAPPY PHANT', Math.round((1280 - width) / 2), 48, 6);
generated.set('poster.png', poster.png());

if(previewDirectory)
{
	await fs.mkdir(previewDirectory, {recursive: true});
	const big = new Raster(SHEET_W * 8, SHEET_H * 8);
	big.fill(0, 0, big.width, big.height, [0xff, 0xdd, 0xee]);
	big.blit(sheet, 0, 0, 8);
	await fs.writeFile(path.join(previewDirectory, 'sheet-8x.png'), big.png());
	const closeup = new Raster(PHANT_W * 16 * 3 + 32, PHANT_H * 16);
	closeup.fill(0, 0, closeup.width, closeup.height, [0xcf, 0xe6, 0xf5]);
	frames.forEach((frame, index) => closeup.blit(frame, index * (PHANT_W * 16 + 16), 0, 16));
	await fs.writeFile(path.join(previewDirectory, 'phant-16x.png'), closeup.png());
	await fs.writeFile(path.join(previewDirectory, 'poster.png'), poster.png());
}

if(check)
{
	const changed = [];

	for(const [name, bytes] of generated)
	{
		const current = await fs.readFile(new URL(name, root)).catch(() => null);

		if(!current?.equals(bytes)) changed.push(name);
	}

	if(changed.length) throw Error(`Generated Phlappy Phant assets are stale: ${changed.join(', ')}`);

	console.log(`Verified ${generated.size} generated Phlappy Phant assets`);
}
else
{
	await fs.mkdir(new URL('assets/', root), {recursive: true});

	for(const [name, bytes] of generated) await fs.writeFile(new URL(name, root), bytes);

	console.log(`Generated ${generated.size} Phlappy Phant assets in games/phlappy-phant`);
}
