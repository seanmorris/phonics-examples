// Generates games/phlappy-phant's pixel art and sounds: the elePHPant frames copied from the hand-pixelled sheet
// art/elephpant.png (see art/ELEPHPANT-LICENSE.md), the procedural DB-silo barrel, ground and cloud tiles, the two
// tileset descriptions, three synthesised PCM sounds and the catalog poster. Every byte is deterministic so
// `--check` can verify the committed output; `--preview DIR` also writes nearest-neighbour enlargements.
import fs from 'node:fs/promises';
import path from 'node:path';
import {PNG} from 'pngjs';

const root = new URL('../games/phlappy-phant/', import.meta.url);
const engine = new URL('../node_modules/@seanmorris/phonics/resources/', import.meta.url);
const check = process.argv.includes('--check');
const previewIndex = process.argv.indexOf('--preview');
const previewDirectory = previewIndex > 0 ? process.argv[previewIndex + 1] : null;
const generated = new Map;

// The tiles use these colours: steel teal barrels with pale rims over a cream-and-tan ground, with the elephant's own
// ink and blues for outlines and clouds.
const palette = {
	ink: [0x36, 0x32, 0x54]
	, light: [0xa6, 0xbd, 0xe6]
	, white: [0xff, 0xff, 0xff]
	, teal: [0x2f, 0x6f, 0x73]
	, tealShade: [0x1f, 0x4f, 0x54]
	, rim: [0xbf, 0xe3, 0xdf]
	, cream: [0xf1, 0xe4, 0xbf]
	, tan: [0xc9, 0xa9, 0x6a]
};

// Barrels, ground and clouds share one 56x32 grid. The elephant sheet holds two rows of two 64x52 frames (wing
// folded, wing spread): the top row faces right for flight, the bottom row faces left for poses that would otherwise
// mirror the sprite and its lettering. Both rows ship as tiles 1 to 4.
const TILE_W = 56, TILE_H = 32, TILE_COLUMNS = 5, TILE_COUNT = 5;
const PHANT_W = 64, PHANT_H = 52, PHANT_FRAMES = 2, PHANT_ROWS = 2;

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

/** Copy the elephant frames from the hand-pixelled sheet, checking its layout: row-major, right-facing row first. */
async function phantFrames()
{
	const image = PNG.sync.read(await fs.readFile(new URL('art/elephpant.png', root)));

	if(image.width !== PHANT_W * PHANT_FRAMES || image.height !== PHANT_H * PHANT_ROWS)
		throw Error(`art/elephpant.png must be ${PHANT_W * PHANT_FRAMES}x${PHANT_H * PHANT_ROWS}, found ${image.width}x${image.height}`);

	const sheet = new Raster(image.width, image.height);
	sheet.data.set(image.data);
	const frames = [];

	for(let row = 0; row < PHANT_ROWS; row++) for(let index = 0; index < PHANT_FRAMES; index++)
	{
		const frame = new Raster(PHANT_W, PHANT_H);

		for(let y = 0; y < PHANT_H; y++) for(let x = 0; x < PHANT_W; x++)
		{
			const [r, g, b, a] = sheet.get(index * PHANT_W + x, row * PHANT_H + y);
			frame.put(x, y, [r, g, b], a);
		}

		frames.push(frame);
	}

	return frames;
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

/** Blit a string from the engine's bitmap font in ink at a pixel scale; a null target only measures. */
async function title(target, text, x, y, scale, colour = palette.ink)
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

		if(target) target.blit(glyphs, cursor, y, scale, colour, [
			column * font.tilewidth, row * font.tileheight, glyph['glyph.width'], glyph['glyph.height']
		]);

		cursor += glyph['glyph.advance'] * scale;
	}

	return cursor - x;
}

const frames = await phantFrames();
const cap = barrelCap(), ring = barrelRing(), bottom = cap.flippedVertically(), soil = ground(), puff = cloud();
const phantSheet = new Raster(PHANT_W * PHANT_FRAMES, PHANT_H * PHANT_ROWS);
frames.forEach((frame, index) => phantSheet.blit(frame, (index % PHANT_FRAMES) * PHANT_W, Math.floor(index / PHANT_FRAMES) * PHANT_H));
const tileSheet = new Raster(TILE_W * TILE_COLUMNS, TILE_H);
[cap, ring, bottom, soil, puff].forEach((tile, index) => tileSheet.blit(tile, index * TILE_W, 0));

const tileset = (name, image, width, height, columns, count, sheet) => Buffer.from(JSON.stringify({
	type: 'tileset'
	, version: '1.10'
	, name
	, tilewidth: width
	, tileheight: height
	, tilecount: count
	, columns
	, image
	, imagewidth: sheet.width
	, imageheight: sheet.height
}, null, 2) + '\n');

generated.set('assets/phant.png', phantSheet.png());
generated.set('assets/phant.tsj', tileset('Phlappy Phant elephant', 'phant.png', PHANT_W, PHANT_H, PHANT_FRAMES, PHANT_FRAMES * PHANT_ROWS, phantSheet));
generated.set('assets/tiles.png', tileSheet.png());
generated.set('assets/tiles.tsj', tileset('Phlappy Phant tiles', 'tiles.png', TILE_W, TILE_H, TILE_COLUMNS, TILE_COUNT, tileSheet));
generated.set('assets/flap.wav', flapSound());
generated.set('assets/ding.wav', dingSound());
generated.set('assets/bonk.wav', bonkSound());

// Poster: a daytime scene at four times pixel scale with the title set in the engine font.
const poster = new Raster(1280, 720);
poster.fill(0, 0, 1280, 720, [0xcf, 0xe6, 0xf5]);
for(const [x, y] of [[90, 90], [420, 150], [760, 70], [1080, 170], [300, 260]]) poster.blit(puff, x, y, 4);
poster.fill(0, 560, 1280, 32, palette.cream);
for(let x = 0; x < 1280; x += TILE_W * 4) poster.blit(soil, x, 592, 4);
// One full pair at the right edge and a ground-only stack mid-frame leave the sky clear for the title.
for(const [x, gapTop, gap] of [[1036, 260, 180], [586, -40, 300]])
{
	for(let y = gapTop - TILE_H * 4; y > -TILE_H * 4; y -= TILE_H * 4) poster.blit(y === gapTop - TILE_H * 4 ? bottom : ring, x, y, 4);
	for(let y = gapTop + gap; y < 592; y += TILE_H * 4) poster.blit(y === gapTop + gap ? cap : ring, x, y, 4);
}
poster.blit(frames[1], 60, 250, 6);
// The wordmark gets the in-game treatment: an ink shadow under a cream fill, so it reads over the barrels.
const width = await title(null, 'PHLAPPY PHANT', 0, 48, 6);
await title(poster, 'PHLAPPY PHANT', Math.round((1280 - width) / 2) + 6, 54, 6, palette.ink);
await title(poster, 'PHLAPPY PHANT', Math.round((1280 - width) / 2), 48, 6, palette.cream);
generated.set('poster.png', poster.png());

if(previewDirectory)
{
	await fs.mkdir(previewDirectory, {recursive: true});
	const big = new Raster(tileSheet.width * 8, tileSheet.height * 8);
	big.fill(0, 0, big.width, big.height, [0xff, 0xdd, 0xee]);
	big.blit(tileSheet, 0, 0, 8);
	await fs.writeFile(path.join(previewDirectory, 'tiles-8x.png'), big.png());
	const closeup = new Raster(PHANT_W * 12 * PHANT_FRAMES + 16, PHANT_H * 12 * PHANT_ROWS + 16);
	closeup.fill(0, 0, closeup.width, closeup.height, [0xcf, 0xe6, 0xf5]);
	frames.forEach((frame, index) => closeup.blit(frame, (index % PHANT_FRAMES) * (PHANT_W * 12 + 16), Math.floor(index / PHANT_FRAMES) * (PHANT_H * 12 + 16), 12));
	await fs.writeFile(path.join(previewDirectory, 'phant-12x.png'), closeup.png());
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
