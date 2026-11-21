#!/usr/bin/env node
// Draws RPSC's pixel art and synthesizes its sounds. The art is the two board squares, both sides' checkers face
// down and showing each kind, the king's gold rim, and the white marks the renderer tints for selection, targets and
// the keyboard cursor. Everything is computed, so the output is deterministic and `--check` can verify the committed
// files; `--preview DIR` also writes an enlarged sheet and a sample board for looking at.
import fs from 'node:fs/promises';
import path from 'node:path';
import {PNG} from 'pngjs';

const root = new URL('../games/rpsc/', import.meta.url);
const engine = new URL('../node_modules/@seanmorris/phonics/resources/', import.meta.url);
const check = process.argv.includes('--check');
const previewAt = process.argv.indexOf('--preview');
const previewDirectory = previewAt < 0 ? null : process.argv[previewAt + 1];

// The engine's bitmap font: the poster is lettered with it, and the renderer centers labels by where its ink falls.
const font = JSON.parse(await fs.readFile(new URL('font.tsj', engine), 'utf8'));
const fontImage = PNG.sync.read(await fs.readFile(new URL('font.png', engine)));

/** The font's glyphs by character: each one's tile, and the width, height and advance its tileset records. */
const glyphTiles = new Map(font.tiles.map(tile => {
	const properties = Object.fromEntries(tile.properties.map(property => [property.name, property.value]));

	return [properties['glyph.char'], {...properties, id: tile.id}];
}));

/** How far a space moves the pen. */
const spaceAdvance = font.properties.find(property => property.name === 'font.spaceAdvance')?.value ?? 6;

/** Where a glyph's cell starts on the font image. */
const glyphCell = glyph => {
	const [column, row] = [glyph.id % font.columns, Math.floor(glyph.id / font.columns)];

	return [column * font.tilewidth, row * font.tileheight];
};

/**
 * Find the box of a glyph's strokes inside its cell: `[left, top, right, bottom]`, all inclusive.
 *
 * The font's tileset gives each glyph an advance but not where its strokes fall. Capitals are ten rows tall on a
 * 16-row line and narrower than their advance, so text centered by its advances and its line height lands left of
 * and above the middle. Only the light strokes count: each glyph also carries a dark shadow one pixel down and to
 * the right, which the eye does not read as part of the letter.
 */
function glyphInk(glyph)
{
	const [cellX, cellY] = glyphCell(glyph);
	const box = [font.tilewidth, font.tileheight, -1, -1];

	for(let y = 0; y < font.tileheight; y++)
	for(let x = 0; x < font.tilewidth; x++)
	{
		const at = ((cellY + y) * fontImage.width + cellX + x) * 4;
		const [red, green, blue, alpha] = fontImage.data.subarray(at, at + 4);

		if(alpha < 128 || red + green + blue < 384) continue;

		box.splice(0, 4, Math.min(box[0], x), Math.min(box[1], y), Math.max(box[2], x), Math.max(box[3], y));
	}

	return box;
}

/** One glyph's entry for the renderer: its ink box and its advance. */
const inkEntry = ([character, glyph]) => [character, [...glyphInk(glyph), glyph['glyph.advance']]];

/** Every glyph's ink box and advance: `{character: [left, top, right, bottom, advance]}`. */
const fontInk = Object.fromEntries([...glyphTiles].map(inkEntry));

/** Edge of one board square, and of every tile on the sheet. */
const TILE = 20;
const COLUMNS = 8;
/** Tile order on the sheet. The renderer reads these names from the tileset, so the order can change freely. */
const TILES = [
	'square-red', 'square-black'
	, 'black-back', 'black-rock', 'black-paper', 'black-scissors'
	, 'red-back', 'red-rock', 'red-paper', 'red-scissors'
	, 'king', 'ring', 'dot', 'reticle', 'cursor', 'crown'
];

/** Read a `#rrggbb` color as three channels from 0 to 255. */
const hex = value => [1, 3, 5].map(at => parseInt(value.slice(at, at + 2), 16));

/** Blend two colors: `amount` 0 gives the first, 1 the second. */
const mix = (from, to, amount) => from.map((channel, index) => Math.round(channel + (to[index] - channel) * amount));

/** A checker's tones by name, from its outline in to the boss at its center. */
const TONES = ['outline', 'light', 'dark', 'groove', 'face', 'boss'];

/** Name six `#rrggbb` colors as one side's tones. */
const tones = colors => Object.fromEntries(TONES.map((name, index) => [name, hex(colors[index])]));

const SIDES = {
	black: tones(['#0b0b0f', '#62627a', '#2f2f3b', '#141419', '#2a2a35', '#4a4a5c'])
	, red: tones(['#35080b', '#f58a6c', '#a82426', '#6e1216', '#c8302e', '#ee6a54'])
};

const EMBLEM = hex('#f6ecd2');
const EMBLEM_SHADE = hex('#c9b388');
const GOLD = [hex('#ffe08a'), hex('#d9a12f'), hex('#8a5a12')];

/** Read a picture drawn as rows of text, one character a pixel. Used as a template tag. */
const bitmap = ([picture]) => picture.trim().split('\n').map(row => row.trim());

/**
 * Emblems: `#` is the light tone and `+` the shaded one. The grid is twelve pixels square because the checker is
 * twenty: an even grid has the same center as the checker, and an odd one would sit half a pixel off it.
 */
const EMBLEMS = {
	rock: bitmap`
		............
		............
		...######...
		..########..
		.###+######.
		.##++######.
		.#++####++#.
		.+++++##+++.
		..++++++++..
		...++++++...
		............
		............
	`
	, paper: bitmap`
		............
		..######....
		..######+...
		..######++..
		..########..
		..#+++++##..
		..########..
		..#+++++##..
		..########..
		..#++++###..
		..########..
		............
	`
	, scissors: bitmap`
		............
		.#........#.
		.##......##.
		..##....##..
		...##..##...
		....####....
		....####....
		..+++..+++..
		.+..+..+..+.
		.+..+..+..+.
		..++....++..
		............
	`
};

/**
 * Check that a picture is centered: its grid shares the tile's center, and its ink leaves equal margins left and
 * right and equal margins above and below. A picture that fails would sit off the middle of its checker.
 */
function centered(name, rows)
{
	const [width, height] = [rows[0].length, rows.length];
	const inked = rows.flatMap((row, y) => [...row].map((mark, x) => mark === '.' ? null : [x, y])).filter(Boolean);
	const [xs, ys] = [inked.map(([x]) => x), inked.map(([, y]) => y)];
	const level = Math.min(...xs) === width - 1 - Math.max(...xs) && Math.min(...ys) === height - 1 - Math.max(...ys);
	const even = (TILE - width) % 2 === 0 && (TILE - height) % 2 === 0;

	if(!even || !level) throw Error(`The ${name} picture is off center`);

	return rows;
}

for(const [name, rows] of Object.entries(EMBLEMS)) centered(name, rows);

/** A small RGBA image to draw into and write out as a PNG. */
class Sheet
{

	/** A transparent sheet of a size in pixels. */
	constructor(width, height)
	{
		this.width = width;
		this.height = height;
		this.data = new Uint8Array(width * height * 4);
	}

	/** Read one pixel as `[red, green, blue, alpha]`. */
	get(x, y)
	{
		const at = (y * this.width + x) * 4;

		return [this.data[at], this.data[at + 1], this.data[at + 2], this.data[at + 3]];
	}

	/** Set one pixel; a position off the sheet is ignored. */
	put(x, y, rgb, alpha = 255)
	{
		if(x < 0 || y < 0 || x >= this.width || y >= this.height) return;

		const at = (y * this.width + x) * 4;
		this.data.set([rgb[0], rgb[1], rgb[2], alpha], at);
	}

	/** Copy another sheet over this one, enlarged by whole pixels, skipping transparent pixels. */
	blit(source, dx, dy, scale = 1, region = [0, 0, source.width, source.height], tint = null)
	{
		const [sx, sy, sw, sh] = region;

		for(let y = 0; y < sh; y++)
		for(let x = 0; x < sw; x++)
		{
			const pixel = source.get(sx + x, sy + y);

			if(pixel[3] < 128) continue;

			const rgb = tint ? tint.map((channel, index) => Math.round(channel * pixel[index] / 255)) : pixel;

			for(let oy = 0; oy < scale; oy++)
			for(let ox = 0; ox < scale; ox++)
			this.put(dx + x * scale + ox, dy + y * scale + oy, rgb);
		}
	}

	/** Encode the sheet as a PNG at the highest compression, so the bytes never vary. */
	png()
	{
		const image = new PNG({width: this.width, height: this.height});
		image.data = Buffer.from(this.data);

		return PNG.sync.write(image, {deflateLevel: 9});
	}
}

/** A repeatable value from 0 to 1 for a pixel, so textures never depend on a random source. */
const noise = (x, y, salt) => {
	let value = (x * 374761393 + y * 668265263 + salt * 2147483647) | 0;
	value = Math.imul(value ^ (value >>> 13), 1274126177);

	return ((value ^ (value >>> 16)) >>> 0) / 4294967295;
};

/** Distance of a pixel's center from the center of its tile. */
const reach = (x, y) => Math.hypot(x - (TILE - 1) / 2, y - (TILE - 1) / 2);

/** One board square: a flat color with faint grain and a darker edge on two sides. */
function square(color, salt)
{
	const tile = new Sheet(TILE, TILE);

	for(let y = 0; y < TILE; y++)
	for(let x = 0; x < TILE; x++)
	{
		// Long, faint streaks along the rank read as a painted wooden board without fighting the pieces for attention.
		const grain = noise(x >> 2, y, salt) * .6 + noise(x, y, salt + 1) * .4;
		const edge = x === 0 || y === 0 ? -.10 : (x === TILE - 1 || y === TILE - 1 ? .05 : 0);
		const shade = (grain - .5) * .12 + edge;

		tile.put(x, y, shade < 0 ? mix(color, [0, 0, 0], -shade) : mix(color, [255, 255, 255], shade));
	}

	return tile;
}

/** One checker seen from above: a ridged rim lit from the top left, a groove, and a flat face for the emblem. */
function checker(side, emblem)
{
	const tile = new Sheet(TILE, TILE);
	const tones = SIDES[side];

	for(let y = 0; y < TILE; y++)
	for(let x = 0; x < TILE; x++)
	{
		const distance = reach(x, y);

		if(distance > 9.3) continue;

		const angle = Math.atan2(y - (TILE - 1) / 2, x - (TILE - 1) / 2);
		// Light falls from the top left: 1 on the lit edge, 0 on the far one.
		const lit = (1 - Math.cos(angle + Math.PI * .75)) / 2;

		if(distance > 8.4) tile.put(x, y, tones.outline);
		else if(distance > 6.5)
		{
			const ridge = Math.floor((angle + Math.PI) / (2 * Math.PI) * 28) % 2;

			tile.put(x, y, mix(tones.dark, tones.light, Math.max(0, Math.min(1, lit * .9 + (ridge ? .12 : -.08)))));
		}
		else if(distance > 5.7) tile.put(x, y, tones.groove);
		else tile.put(x, y, mix(tones.face, tones.boss, lit * .25));
	}

	if(emblem)
	{
		const rows = EMBLEMS[emblem];
		const left = (TILE - rows[0].length) / 2;
		const top = (TILE - rows.length) / 2;

		rows.forEach((row, y) => [...row].forEach((mark, x) => {
			if(mark === '#') tile.put(left + x, top + y, EMBLEM);
			else if(mark === '+') tile.put(left + x, top + y, EMBLEM_SHADE);
		}));
	}
	else
	{
		// Face down: a plain boss ring, the same on every piece.
		for(let y = 0; y < TILE; y++)
		for(let x = 0; x < TILE; x++)
		{
			const distance = reach(x, y);

			if(distance > 2.2 && distance <= 3.6) tile.put(x, y, tones.boss);
			else if(distance <= 1.2) tile.put(x, y, tones.groove);
		}
	}

	return tile;
}

/** The king's rim: the checker's ridged band redrawn in gold, laid over any checker. */
function king()
{
	const tile = new Sheet(TILE, TILE);

	for(let y = 0; y < TILE; y++)
	for(let x = 0; x < TILE; x++)
	{
		const distance = reach(x, y);

		if(distance <= 6.5 || distance > 8.4) continue;

		const angle = Math.atan2(y - (TILE - 1) / 2, x - (TILE - 1) / 2);
		const lit = (1 - Math.cos(angle + Math.PI * .75)) / 2;
		const ridge = Math.floor((angle + Math.PI) / (2 * Math.PI) * 28) % 2;
		const tone = lit * .8 + (ridge ? .2 : 0);

		tile.put(x, y, tone > .62 ? GOLD[0] : (tone > .3 ? GOLD[1] : GOLD[2]));
	}

	return tile;
}

/** A white mark the renderer tints: `test` says which pixels belong to it. */
function mark(test)
{
	const tile = new Sheet(TILE, TILE);

	for(let y = 0; y < TILE; y++) for(let x = 0; x < TILE; x++) if(test(x, y)) tile.put(x, y, [255, 255, 255]);

	return tile;
}

/** Whether a pixel lies on one of the four corner brackets set `inset` pixels in, with arms `arm` pixels long. */
const corner = (x, y, inset, arm) => {

	const near = value => value < TILE / 2 ? value - inset : TILE - 1 - inset - value;
	const [dx, dy] = [near(x), near(y)];

	return (dx === 0 && dy >= 0 && dy < arm) || (dy === 0 && dx >= 0 && dx < arm);
};

/** The crown that marks a far-rank square: eight by six, so it too shares the tile's center. */
const CROWN = bitmap`
	#..##..#
	#..##..#
	##.##.##
	########
	########
	.######.
`;

centered('crown', CROWN);

const tiles = {
	'square-red': square(hex('#9f2c2a'), 3)
	, 'square-black': square(hex('#1c1a22'), 7)
	, king: king()
	// Selection: a ring just outside the checker's own outline.
	, ring: mark((x, y) => reach(x, y) > 9.3 && reach(x, y) <= 10.4)
	// A square this piece may step to.
	, dot: mark((x, y) => reach(x, y) <= 2.7)
	// An opposing piece this one may challenge: brackets on the corners of its square.
	, reticle: mark((x, y) => corner(x, y, 0, 5) || corner(x, y, 1, 4))
	// The keyboard and controller cursor: the square's outline.
	, cursor: mark((x, y) => x === 0 || y === 0 || x === TILE - 1 || y === TILE - 1)
	// The far-rank squares a side has to fill.
	, crown: mark((x, y) => CROWN[y - (TILE - CROWN.length) / 2]?.[x - (TILE - CROWN[0].length) / 2] === '#')
};

for(const side of Object.keys(SIDES))
{
	tiles[`${side}-back`] = checker(side, null);

	for(const emblem of Object.keys(EMBLEMS)) tiles[`${side}-${emblem}`] = checker(side, emblem);
}

const sheet = new Sheet(COLUMNS * TILE, Math.ceil(TILES.length / COLUMNS) * TILE);

TILES.forEach((name, index) => sheet.blit(tiles[name], index % COLUMNS * TILE, Math.floor(index / COLUMNS) * TILE));

const generated = new Map;
generated.set('assets/pieces.png', sheet.png());

// The tileset in Tiled's own key order. The renderer finds each tile by the name recorded in `rpsc.tiles`, so adding
// or reordering tiles never needs a matching edit in PHP.
const grid = {tilewidth: TILE, tileheight: TILE, tilecount: TILES.length, columns: COLUMNS};
const image = {image: 'pieces.png', imagewidth: sheet.width, imageheight: sheet.height};
const properties = [
	{name: 'rpsc.tiles', type: 'string', value: TILES.join(',')}
	// Where the engine font's ink falls, so the renderer can center a label by what is drawn, not by its cell.
	, {name: 'rpsc.ink', type: 'string', value: JSON.stringify({...fontInk, ' ': [0, 0, -1, -1, spaceAdvance]})}
];

const tileset = {type: 'tileset', version: '1.10', name: 'RPSC pieces', ...grid, ...image, properties};

generated.set('assets/pieces.tsj', Buffer.from(JSON.stringify(tileset, null, 2) + '\n'));

/** Deterministic 16-bit mono PCM at 22050 Hz from a sample function of seconds. */
function wav(seconds, sample)
{
	const rate = 22050, count = Math.round(seconds * rate);
	const buffer = Buffer.alloc(44 + count * 2);
	buffer.write('RIFF', 0);
	buffer.writeUInt32LE(36 + count * 2, 4);
	buffer.write('WAVE', 8);

	// The format chunk: uncompressed, one channel, two bytes a sample.
	buffer.write('fmt ', 12);
	buffer.writeUInt32LE(16, 16);
	buffer.writeUInt16LE(1, 20);
	buffer.writeUInt16LE(1, 22);
	buffer.writeUInt32LE(rate, 24);
	buffer.writeUInt32LE(rate * 2, 28);
	buffer.writeUInt16LE(2, 32);
	buffer.writeUInt16LE(16, 34);

	// The data chunk: its length, then the samples.
	buffer.write('data', 36);
	buffer.writeUInt32LE(count * 2, 40);

	for(let index = 0; index < count; index++)
	{
		const value = Math.max(-1, Math.min(1, sample(index / rate, index)));
		buffer.writeInt16LE(Math.round(value * 32767), 44 + index * 2);
	}

	return buffer;
}

/** Repeatable white noise from -1 to 1 for a sample index. */
const hiss = index => noise(index, 0, 11) * 2 - 1;

/** A sine wave of a pitch in hertz at a time in seconds. */
const tone = (time, pitch) => Math.sin(2 * Math.PI * pitch * time);

/** A note that starts at `from` seconds and rings down over `length`. */
const note = (time, from, length, pitch) => time < from || time > from + length
	? 0
	: tone(time - from, pitch) * Math.exp(-5 * (time - from) / length) * Math.min(1, (time - from) * 400);

// A checker set down on wood: a knock with a little grit at the front.
generated.set(
	'assets/move.wav'
	, wav(
		.09
		, (time, index) => (tone(time, 190 - 600 * time) * .8 + hiss(index) * .25 * Math.exp(-time * 90))
			* Math.exp(-time * 45)
	)
);

// Two checkers turned over: a soft double tick.
generated.set(
	'assets/flip.wav'
	, wav(
		.16
		, (time, index) => hiss(index) * .5 * (Math.exp(-time * 70) + (time > .07 ? Math.exp(-(time - .07) * 70) : 0))
			+ note(time, .07, .08, 520) * .25
	)
);

// A piece taken: a lower, longer knock.
generated.set(
	'assets/take.wav'
	, wav(
		.22
		, (time, index) => (tone(time, 120 - 180 * time) * .9 + hiss(index) * .3 * Math.exp(-time * 40))
			* Math.exp(-time * 16)
	)
);

// A tie: the same note twice.
generated.set('assets/tie.wav', wav(.3, time => (note(time, 0, .13, 392) + note(time, .14, .15, 392)) * .5));
// A crowning: a rising pair.
generated.set('assets/crown.wav', wav(.34, time => (note(time, 0, .15, 660) + note(time, .11, .22, 990)) * .45));

generated.set(
	'assets/win.wav'
	, wav(
		.75
		, time => [523, 659, 784, 1047].reduce(
			(sum, pitch, index) => sum + note(time, index * .11, index === 3 ? .4 : .2, pitch)
			, 0
		) * .4
	)
);

generated.set(
	'assets/lose.wav'
	, wav(
		.7
		, time => [392, 330, 262].reduce(
			(sum, pitch, index) => sum + note(time, index * .15, index === 2 ? .38 : .22, pitch)
			, 0
		) * .4
	)
);

/** Blit a string from the engine's bitmap font at a pixel scale; a null target only measures. */
function title(target, text, x, y, scale, color)
{
	const glyphs = new Sheet(fontImage.width, fontImage.height);
	glyphs.data.set(fontImage.data);
	let cursor = x;

	for(const character of text)
	{
		const glyph = glyphTiles.get(character);

		if(!glyph)
		{
			cursor += character === ' ' ? spaceAdvance * scale : 0;
			continue;
		}

		const region = [...glyphCell(glyph), glyph['glyph.width'], glyph['glyph.height']];

		if(target) target.blit(glyphs, cursor, y, scale, region, color);

		cursor += glyph['glyph.advance'] * scale;
	}

	return cursor - x;
}

/** Draw a position: `pieces` maps a square number from a1 to a tile name. */
function boardInto(target, left, top, scale, pieces, marks = [])
{
	for(let square = 0; square < 64; square++)
	{
		const [x, y] = [left + square % 8 * TILE * scale, top + (7 - (square >> 3)) * TILE * scale];
		target.blit(tiles[(square % 8 + (square >> 3)) % 2 ? 'square-red' : 'square-black'], x, y, scale);

		if(pieces[square]) target.blit(tiles[pieces[square]], x, y, scale);
	}

	for(const [square, name, tint] of marks)
	target.blit(
		tiles[name]
		, left + square % 8 * TILE * scale
		, top + (7 - (square >> 3)) * TILE * scale
		, scale
		, undefined
		, tint
	);
}

// The poster is a game a few turns in, seen from Black's side, with a challenge about to be made.
const poster = new Sheet(1280, 720);

for(let y = 0; y < 720; y++) for(let x = 0; x < 1280; x++) poster.put(x, y, hex('#11131a'));

for(let y = 28; y < 692; y++)
for(let x = 28; x < 692; x++)
{
	const edge = x < 32 || y < 32 ? hex('#805433') : (x >= 688 || y >= 688 ? hex('#331f12') : hex('#5c3821'));
	poster.put(x, y, edge);
}

/** The poster's position: each tile and the squares it stands on, numbered from a1. */
const POSITION = {
	'black-rock': [1, 7, 10, 28]
	, 'black-scissors': [3, 14, 21, 26]
	, 'black-paper': [5, 8, 19, 39]
	, 'red-back': [27, 36, 38, 41, 45, 48, 52, 54, 57, 59, 61, 63]
};

/** One `[square, tile]` pair for each square a tile stands on. */
const standing = ([name, squares]) => squares.map(at => [at, name]);

const gold = [255, 220, 128];
const cream = [246, 236, 210];

// A selected piece, the two squares it can step to, the piece it can challenge, and both sides' far-rank crowns.
const marks = [
	[26, 'ring', gold]
	, [27, 'reticle', gold]
	, ...[33, 35].map(at => [at, 'dot', cream])
	, ...[56, 58, 60, 62].map(at => [at, 'crown', [92, 18, 18]])
	, ...[0, 2, 4, 6].map(at => [at, 'crown', [64, 61, 79]])
];

boardInto(poster, 40, 40, 4, Object.fromEntries(Object.entries(POSITION).flatMap(standing)), marks);

const wordmark = title(null, 'RPSC', 0, 0, 14, cream);
title(poster, 'RPSC', 728 + Math.floor((524 - wordmark) / 2), 96, 14, cream);

for(const [index, line] of ['ROCK PAPER SCISSORS', 'CHECKERS'].entries())
{
	const length = title(null, line, 0, 0, 3, cream);
	title(poster, line, 728 + Math.floor((524 - length) / 2), 336 + index * 54, 3, gold);
}

['black-rock', 'red-paper', 'black-scissors'].forEach(
	(name, index) => poster.blit(tiles[name], 750 + index * 168, 500, 7)
);

generated.set('poster.png', poster.png());

if(previewDirectory)
{
	await fs.mkdir(previewDirectory, {recursive: true});
	const scale = 8;
	const big = new Sheet(sheet.width * scale, sheet.height * scale);
	big.blit(sheet, 0, 0, scale);
	await fs.writeFile(path.join(previewDirectory, 'pieces-8x.png'), big.png());

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

	if(changed.length) throw Error(`Generated RPSC assets are stale: ${changed.join(', ')}`);

	console.log(`Verified ${generated.size} generated RPSC assets`);
}
else
{
	for(const [name, bytes] of generated)
	{
		await fs.mkdir(new URL('.', new URL(name, root)), {recursive: true});
		await fs.writeFile(new URL(name, root), bytes);
	}

	console.log(`Generated ${generated.size} RPSC assets in games/rpsc`);
}
