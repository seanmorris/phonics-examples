// Generates games/phlappy-phant's pixel art and sounds: the elePHPant frames copied from the hand-pixelled sheet
// art/elephpant.png (see art/ELEPHPANT-LICENSE.md); the backdrop, pillar, ground and scenery tilesets cut from
// GrafxKid's CC0 terrain sheet (art/terrain.png, with the parallax band mask and touched-up scenery in
// art/terrain-parallax.png, and hand-graded dusk/dawn and night copies; see art/TERRAIN-LICENSE.md); the tileset
// descriptions; three synthesised PCM sounds and the
// catalog poster. Every byte is deterministic so `--check` can verify the committed output; `--preview DIR` also
// writes nearest-neighbour enlargements.
import fs from 'node:fs/promises';
import path from 'node:path';
import {PNG} from 'pngjs';

const root = new URL('../games/phlappy-phant/', import.meta.url);
const engine = new URL('../node_modules/@seanmorris/phonics/resources/', import.meta.url);
const check = process.argv.includes('--check');
const previewIndex = process.argv.indexOf('--preview');
const previewDirectory = previewIndex > 0 ? process.argv[previewIndex + 1] : null;
const generated = new Map;

// The title shares the elephant's ink and the menus' cream.
const palette = {
	ink: [0x36, 0x32, 0x54]
	, cream: [0xf1, 0xe4, 0xbf]
};

// The elephant sheet holds two rows of two 64x52 frames (wing folded, wing spread): the top row faces right for
// flight, the bottom row faces left for poses that would otherwise mirror the sprite and its lettering.
const PHANT_W = 64, PHANT_H = 52, PHANT_FRAMES = 2, PHANT_ROWS = 2;

// The terrain sheets are 224 pixels square on a 32-pixel grid. The left five columns and five rows are the backdrop;
// the two rows under it are animation frames for the backdrop's fourth row (the river). The right two columns hold
// the foreground: a tree, a shrub and a fence, then the pillar (cap row, then walls repeating every 16 rows), the row
// where a pillar meets the ground, and the ground with its grass top beside plain dirt. The parallax copy paints each
// backdrop band in a flat colour and carries the latest scenery, whose bottoms sit on the 8-pixel grid.
const SHEET = 224, BACKDROP = 160, OVERLAY_ROW = 96, OVERLAY_H = 32, FG_X = 160, FG_W = 64;
const PILLAR_SLICES = {cap: 120, body0: 128, body1: 136, base: 160}, SLICE_H = 8;
const GROUND = {grass: [160, 192], dirt: [192, 192]}, GROUND_TILE = 32;
// Scenery boxes are on the 8-pixel grid so a piece can stand on the ground or a pillar top; each ships bottom-left
// aligned in a 48x32 cell.
const SCENERY = [['tree', 160, 24, 32, 32], ['shrub', 200, 40, 24, 16], ['fence', 168, 80, 48, 8]];
const SCENERY_W = 48, SCENERY_H = 32;
// Farther bands drift slower; the ground and pillars move at the full scroll speed.
const PARALLAX = [.08, .16, .24, .45, .7];
const RIVER_FRAME_MS = 400;
// Three hand-graded keyframes: day, dusk (also dawn), and night. Day takes its backdrop from art/terrain.png and its
// foreground from the parallax copy; the other two are whole sheets. Between each pair the generator grades
// STEPS - 1 in-between palettes, so each terrain tileset stacks 2 * STEPS + 1 palettes top to bottom.
const KEYFRAMES = ['day', 'duskdawn', 'night'], STEPS = 3;
// A pixel whose hue swings further than WIDE_SWING between keyframes, with one end a warm colour (within WARM_SPREAD of
// WARM_HUE, the reds through golds), travels through the warm side of the wheel instead of the shorter way through
// cyan and green, so the sky blushes on its way to dusk and night. Other swings, such as foliage turning from green to
// night navy, take the short way through teal.
const WARM_HUE = 20, WARM_SPREAD = 80, WIDE_SWING = 90;

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

/** Read a terrain sheet and check its size. */
async function sheet(name)
{
	const image = PNG.sync.read(await fs.readFile(new URL(`art/${name}`, root)));

	if(image.width !== SHEET || image.height !== SHEET)
		throw Error(`art/${name} must be ${SHEET}x${SHEET}, found ${image.width}x${image.height}`);

	const raster = new Raster(image.width, image.height);
	raster.data.set(image.data);

	return raster;
}

/** Copy a rectangle of one raster into another at a destination corner, alpha included. */
function copy(source, [sx, sy, width, height], target, dx, dy)
{
	for(let y = 0; y < height; y++) for(let x = 0; x < width; x++)
	{
		const [r, g, b, a] = source.get(sx + x, sy + y);

		if(a) target.put(dx + x, dy + y, [r, g, b], a);
	}
}

const toLinear = channel => { channel /= 255; return channel <= .04045 ? channel / 12.92 : ((channel + .055) / 1.055) ** 2.4; };
const toSrgb = channel => Math.round(255 * Math.min(1, Math.max(0, channel <= .0031308 ? 12.92 * channel : 1.055 * channel ** (1 / 2.4) - .055)));

/** Convert an sRGB triple to OKLCH: perceptual lightness, chroma and hue in degrees. */
function oklch(rgb)
{
	const [r, g, b] = rgb.map(toLinear);
	const l = Math.cbrt(.4122214708 * r + .5363325363 * g + .0514459929 * b);
	const m = Math.cbrt(.2119034982 * r + .6806995451 * g + .1073969566 * b);
	const s = Math.cbrt(.0883024619 * r + .2817188376 * g + .6299787005 * b);
	const a = 1.9779984951 * l - 2.4285922050 * m + .4505937099 * s;
	const bb = .0259040371 * l + .7827717662 * m - .8086757660 * s;

	return [.2104542553 * l + .7936177850 * m - .0040720468 * s, Math.hypot(a, bb), (Math.atan2(bb, a) * 180 / Math.PI + 360) % 360];
}

/** Convert OKLCH back to an sRGB triple, clipping out-of-gamut channels. */
function srgb([lightness, chroma, hue])
{
	const a = chroma * Math.cos(hue * Math.PI / 180), b = chroma * Math.sin(hue * Math.PI / 180);
	const l = (lightness + .3963377774 * a + .2158037573 * b) ** 3;
	const m = (lightness - .1055613458 * a - .0638541728 * b) ** 3;
	const s = (lightness - .0894841775 * a - 1.2914855480 * b) ** 3;

	return [
		4.0767416621 * l - 3.3077115913 * m + .2309699292 * s
		, -1.2684380046 * l + 2.6097574011 * m - .3413193965 * s
		, -.0041960863 * l - .7034186147 * m + 1.7076147010 * s
	].map(toSrgb);
}

const graded = new Map;

/** Grade one colour part of the way to another in OKLCH, taking wide hue swings through the warm side. */
function grade(from, to, t)
{
	const key = `${from}|${to}|${t}`;

	if(graded.has(key)) return graded.get(key);

	const [l1, c1, h1] = oklch(from), [l2, c2, h2] = oklch(to);
	// A nearly grey end has no meaningful hue; borrow the other end's so only lightness and chroma move.
	const start = c1 < .02 ? h2 : h1, end = c2 < .02 ? h1 : h2;
	const rising = (end - start + 360) % 360;
	let swing = rising <= 180 ? rising : rising - 360;

	const warm = hue => Math.min((hue - WARM_HUE + 360) % 360, (WARM_HUE - hue + 360) % 360) <= WARM_SPREAD;

	if(Math.abs(swing) > WIDE_SWING && (warm(start) || warm(end)))
		swing = (WARM_HUE - start + 360) % 360 <= rising ? rising : rising - 360;

	const colour = srgb([l1 + (l2 - l1) * t, c1 + (c2 - c1) * t, start + swing * t]);
	graded.set(key, colour);

	return colour;
}

/** Read the band edges from the mask: each band is a run of rows painted one flat colour. */
function bands(mask)
{
	const key = y => {
		const first = mask.get(0, y).join();

		for(let x = 1; x < BACKDROP; x++) if(mask.get(x, y).join() !== first)
			throw Error(`art/terrain-parallax.png backdrop row ${y} is not one flat colour`);

		return first;
	};
	const edges = [0];

	for(let y = 1; y < BACKDROP; y++) if(key(y) !== key(y - 1)) edges.push(y);

	edges.push(BACKDROP);

	if(edges.length - 1 !== PARALLAX.length)
		throw Error(`The parallax mask has ${edges.length - 1} bands but ${PARALLAX.length} rates are set`);

	return edges;
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
const terrain = await sheet('terrain.png'), mask = await sheet('terrain-parallax.png');
// Day is assembled from the two day sheets: the backdrop and its river frames from terrain.png, the foreground column
// from the parallax copy.
const day = new Raster(SHEET, SHEET);
copy(terrain, [0, 0, FG_X, SHEET], day, 0, 0);
copy(mask, [FG_X, 0, SHEET - FG_X, SHEET], day, FG_X, 0);
const keyframes = [day, await sheet('terrain-duskdawn.png'), await sheet('terrain-night.png')];

// The graded sheets must keep the day shapes exactly, or a crossfade would show two outlines at once.
keyframes.slice(1).forEach((keyframe, index) => {
	for(let y = 0; y < SHEET; y++) for(let x = 0; x < SHEET; x++) if((keyframe.get(x, y)[3] >= 128) !== (day.get(x, y)[3] >= 128))
		throw Error(`art/terrain-${KEYFRAMES[index + 1]}.png differs in shape from the day sheets at ${x},${y}`);
});

// Every palette is one whole sheet: the keyframes, with the graded in-betweens between each pair.
const palettes = [];

for(let index = 0; index < KEYFRAMES.length - 1; index++) for(let step = 0; step < STEPS; step++)
{
	if(step === 0) { palettes.push(keyframes[index]); continue; }

	const sheetFrom = keyframes[index], sheetTo = keyframes[index + 1], between = new Raster(SHEET, SHEET);

	for(let y = 0; y < SHEET; y++) for(let x = 0; x < SHEET; x++)
	{
		const [r, g, b, a] = sheetFrom.get(x, y);

		if(a < 128) continue;

		between.put(x, y, grade([r, g, b], sheetTo.get(x, y).slice(0, 3), step / STEPS), a);
	}

	palettes.push(between);
}

palettes.push(keyframes[keyframes.length - 1]);

const phantSheet = new Raster(PHANT_W * PHANT_FRAMES, PHANT_H * PHANT_ROWS);
frames.forEach((frame, index) => phantSheet.blit(frame, (index % PHANT_FRAMES) * PHANT_W, Math.floor(index / PHANT_FRAMES) * PHANT_H));

const backdrop = new Raster(BACKDROP, BACKDROP * 2 * palettes.length);
const pillarSheet = new Raster(FG_W, SLICE_H * 4 * palettes.length);
const groundSheet = new Raster(GROUND_TILE * 2, GROUND_TILE * palettes.length);
const scenerySheet = new Raster(SCENERY_W * SCENERY.length, SCENERY_H * palettes.length);

palettes.forEach((back, palette) => {
	const front = back;

	// Backdrop: two copies of the 160x160 block per palette, each with one river frame laid over the fourth row;
	// the tile animation swaps them and the renderer crops one band at a time.
	for(const frame of [0, 1])
	{
		const top = (palette * 2 + frame) * BACKDROP;
		copy(back, [0, 0, BACKDROP, BACKDROP], backdrop, 0, top);
		copy(back, [0, BACKDROP + frame * OVERLAY_H, BACKDROP, OVERLAY_H], backdrop, 0, top + OVERLAY_ROW);
	}

	// Pillars: 64x8 slices of the foreground column (the pillar is 56 wide with 4 clear pixels each side).
	Object.values(PILLAR_SLICES).forEach((y, index) => copy(front, [FG_X, y, FG_W, SLICE_H], pillarSheet, 0, (palette * 4 + index) * SLICE_H));
	Object.values(GROUND).forEach(([x, y], index) => copy(front, [x, y, GROUND_TILE, GROUND_TILE], groundSheet, index * GROUND_TILE, palette * GROUND_TILE));
	SCENERY.forEach(([, x, y, width, height], index) => copy(front, [x, y, width, height], scenerySheet, index * SCENERY_W, (palette + 1) * SCENERY_H - height));
});

const edges = bands(mask);
// The sky above the backdrop continues each palette's top row.
const skies = palettes.map(back => '#' + back.get(0, 0).slice(0, 3).map(channel => channel.toString(16).padStart(2, '0')).join(''));

const tileset = (name, image, width, height, columns, count, sheet, extra = {}) => Buffer.from(JSON.stringify({
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
	, ...extra
}, null, 2) + '\n');

generated.set('assets/phant.png', phantSheet.png());
generated.set('assets/phant.tsj', tileset('Phlappy Phant elephant', 'phant.png', PHANT_W, PHANT_H, PHANT_FRAMES, PHANT_FRAMES * PHANT_ROWS, phantSheet));
generated.set('assets/backdrop.png', backdrop.png());
generated.set('assets/backdrop.tsj', tileset('Phlappy Phant backdrop', 'backdrop.png', BACKDROP, BACKDROP, 1, 2 * palettes.length, backdrop, {
	properties: [
		{name: 'phlappy.bands', type: 'string', value: edges.join(',')}
		, {name: 'phlappy.parallax', type: 'string', value: PARALLAX.join(',')}
		, {name: 'phlappy.keyframes', type: 'string', value: KEYFRAMES.join(',')}
		, {name: 'phlappy.steps', type: 'string', value: String(STEPS)}
		, {name: 'phlappy.skies', type: 'string', value: skies.join(',')}
	]
	, tiles: palettes.map((_, palette) => ({id: palette * 2, animation: [
		{tileid: palette * 2, duration: RIVER_FRAME_MS}
		, {tileid: palette * 2 + 1, duration: RIVER_FRAME_MS}
	]}))
}));
generated.set('assets/pillar.png', pillarSheet.png());
generated.set('assets/pillar.tsj', tileset('Phlappy Phant pillar', 'pillar.png', FG_W, SLICE_H, 1, 4 * palettes.length, pillarSheet));
generated.set('assets/ground.png', groundSheet.png());
generated.set('assets/ground.tsj', tileset('Phlappy Phant ground', 'ground.png', GROUND_TILE, GROUND_TILE, 2, 2 * palettes.length, groundSheet));
generated.set('assets/scenery.png', scenerySheet.png());
generated.set('assets/scenery.tsj', tileset('Phlappy Phant scenery', 'scenery.png', SCENERY_W, SCENERY_H, SCENERY.length, SCENERY.length * palettes.length, scenerySheet, {
	properties: [{name: 'phlappy.widths', type: 'string', value: SCENERY.map(([, , , width]) => width).join(',')}]
}));
generated.set('assets/flap.wav', flapSound());
generated.set('assets/ding.wav', dingSound());
generated.set('assets/bonk.wav', bonkSound());

// Poster: a daytime scene at four times pixel scale, world rows 0 to 180, ground at 150, title in the engine font.
const P = 4, groundY = 150;
const poster = new Raster(1280, 720);
poster.fill(0, 0, 1280, 720, [0x48, 0x66, 0xc5]);
const slice = (source, region, wx, wy, flip = false) => {
	const [sx, sy, width, height] = region;

	for(let y = 0; y < height; y++) for(let x = 0; x < width; x++)
	{
		const [r, g, b, a] = source.get(sx + x, sy + (flip ? height - 1 - y : y));

		if(a >= 128) poster.fill((wx + x) * P, (wy + y) * P, P, P, [r, g, b]);
	}
};
for(let x = 0; x < 320; x += BACKDROP) slice(backdrop, [0, 0, BACKDROP, BACKDROP], x, groundY - BACKDROP);
for(let x = 0; x < 320; x += GROUND_TILE) slice(groundSheet, [0, 0, GROUND_TILE, GROUND_TILE], x, groundY);
const pillar = (x, gapTop, gap) => {
	const at = name => [0, Object.keys(PILLAR_SLICES).indexOf(name) * SLICE_H, FG_W, SLICE_H];

	if(gapTop !== null)
	{
		slice(pillarSheet, at('cap'), x - 4, gapTop - SLICE_H, true);
		for(let y = gapTop - 2 * SLICE_H, n = 0; y > -SLICE_H; y -= SLICE_H, n++) slice(pillarSheet, at(n % 2 ? 'body1' : 'body0'), x - 4, y, true);
	}

	const top = (gapTop ?? 0) + gap;
	slice(pillarSheet, at('cap'), x - 4, top);
	for(let y = top + SLICE_H, n = 0; y < groundY; y += SLICE_H, n++) slice(pillarSheet, at(n % 2 ? 'body1' : 'body0'), x - 4, y);
	slice(pillarSheet, at('base'), x - 4, groundY);
};
// A full pair at the right, a ground pillar mid-frame, and scenery between them; the sky stays clear for the title.
pillar(248, 56, 64);
pillar(132, null, 104);
slice(scenerySheet, [0, 0, SCENERY_W, SCENERY_H], 200, groundY - SCENERY_H);
slice(scenerySheet, [SCENERY_W, 0, SCENERY_W, SCENERY_H], 92, groundY - SCENERY_H);
poster.blit(frames[1], 40, 240, 6);
// The wordmark gets the in-game treatment: an ink shadow under a cream fill.
const width = await title(null, 'PHLAPPY PHANT', 0, 48, 6);
await title(poster, 'PHLAPPY PHANT', Math.round((1280 - width) / 2) + 6, 54, 6, palette.ink);
await title(poster, 'PHLAPPY PHANT', Math.round((1280 - width) / 2), 48, 6, palette.cream);
generated.set('poster.png', poster.png());

if(previewDirectory)
{
	await fs.mkdir(previewDirectory, {recursive: true});

	for(const [name, raster] of [['backdrop', backdrop], ['pillar', pillarSheet], ['ground', groundSheet], ['scenery', scenerySheet]])
	{
		const big = new Raster(raster.width * 4, raster.height * 4);
		big.fill(0, 0, big.width, big.height, [0xff, 0xdd, 0xee]);
		big.blit(raster, 0, 0, 4);
		await fs.writeFile(path.join(previewDirectory, `${name}-4x.png`), big.png());
	}

	const closeup = new Raster(PHANT_W * 12 * PHANT_FRAMES + 16, PHANT_H * 12 * PHANT_ROWS + 16);
	closeup.fill(0, 0, closeup.width, closeup.height, [0x48, 0x66, 0xc5]);
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
