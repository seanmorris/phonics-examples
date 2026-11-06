import {Resvg} from '@resvg/resvg-js';

/**
 * Bake self-contained HUD glyph outlines into one single-channel signed-distance atlas for the native PHP HUD.
 *
 * Outlines and metrics come from `extractFontOutlines()`, run once against the OFL source faces, so builds never
 * depend on an installed or bundled font. Glyphs are rasterized at four times the atlas density, converted to exact
 * Euclidean distances, and averaged down, so one mipmapped texture stays crisp at any size and pixel ratio.
 */

const EM = 64;
const SPREAD = 8;
const SUPER = 4;
const ATLAS_WIDTH = 1024;
const CHARACTERS = [...Array.from({length: 95}, (_, index) => String.fromCharCode(32 + index)), ...'·—–…•×’“”'];
// Barlow has no arrows; these outlines share its stroke weight and cap height.
const ARROWS = {
	'↑': [[300,0],[400,0],[400,520],[560,360],[630,430],[350,710],[70,430],[140,360],[300,520]]
	, '↓': [[300,700],[400,700],[400,180],[560,340],[630,270],[350,-10],[70,270],[140,340],[300,180]]
	, '←': [[700,300],[700,400],[180,400],[340,560],[270,630],[-10,350],[270,70],[340,140],[180,300]]
	, '→': [[0,300],[0,400],[520,400],[360,560],[430,630],[710,350],[430,70],[360,140],[520,300]]
};
const round = value => Math.round(value * 10000) / 10000;

/** One-dimensional squared Euclidean distance transform (Felzenszwalb and Huttenlocher). */
const transform1d = (source, length, output, vertices, boundaries) => {
	let k = 0;
	vertices[0] = 0;
	boundaries[0] = -Infinity;
	boundaries[1] = Infinity;

	for(let q = 1; q < length; q++)
	{
		let s;

		do
		{
			const r = vertices[k];
			s = ((source[q] + q * q) - (source[r] + r * r)) / (2 * q - 2 * r);
		}
		while(s <= boundaries[k] && --k >= 0);

		k++;
		vertices[k] = q;
		boundaries[k] = s;
		boundaries[k + 1] = Infinity;
	}

	k = 0;

	for(let q = 0; q < length; q++)
	{
		while(boundaries[k + 1] < q) k++;
		const r = vertices[k];
		output[q] = (q - r) * (q - r) + source[r];
	}
};

/** Squared distance from each pixel to the nearest pixel where `inside` matches `target`. */
const distanceField = (inside, width, height, target) => {
	const grid = new Float64Array(width * height);
	const size = Math.max(width, height);
	const column = new Float64Array(size), result = new Float64Array(size);
	const vertices = new Int32Array(size), boundaries = new Float64Array(size + 1);

	for(let index = 0; index < grid.length; index++) grid[index] = inside[index] === target ? 0 : 1e20;

	for(let x = 0; x < width; x++)
	{
		for(let y = 0; y < height; y++) column[y] = grid[y * width + x];
		transform1d(column, height, result, vertices, boundaries);
		for(let y = 0; y < height; y++) grid[y * width + x] = result[y];
	}

	for(let y = 0; y < height; y++)
	{
		for(let x = 0; x < width; x++) column[x] = grid[y * width + x];
		transform1d(column, width, result, vertices, boundaries);
		for(let x = 0; x < width; x++) grid[y * width + x] = result[x];
	}

	return grid;
};

/** Rasterize one outline and return its downsampled distance cell plus plane metrics in em units. */
const bakeGlyph = (pathData, bounds) => {
	const left = Math.floor(bounds.x1 * EM) - SPREAD;
	const top = Math.floor(bounds.y1 * EM) - SPREAD;
	const width = Math.ceil(bounds.x2 * EM) - Math.floor(bounds.x1 * EM) + SPREAD * 2;
	const height = Math.ceil(bounds.y2 * EM) - Math.floor(bounds.y1 * EM) + SPREAD * 2;
	const hiWidth = width * SUPER, hiHeight = height * SUPER, scale = EM * SUPER;
	const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="${hiWidth}" height="${hiHeight}">`
		+ `<path transform="translate(${-left * SUPER} ${-top * SUPER}) scale(${scale})" d="${pathData}" fill="#fff"/></svg>`;
	// `pixels` copies the whole image on every access, so read it once.
	const pixels = new Resvg(svg, {fitTo: {mode: 'original'}, shapeRendering: 2}).render().pixels;
	const inside = new Uint8Array(hiWidth * hiHeight);

	for(let index = 0; index < inside.length; index++) inside[index] = pixels[index * 4 + 3] >= 128 ? 1 : 0;

	const outside = distanceField(inside, hiWidth, hiHeight, 1);
	const interior = distanceField(inside, hiWidth, hiHeight, 0);
	const cell = new Uint8Array(width * height);

	for(let y = 0; y < height; y++) for(let x = 0; x < width; x++)
	{
		let total = 0;

		for(let sy = 0; sy < SUPER; sy++) for(let sx = 0; sx < SUPER; sx++)
		{
			const index = (y * SUPER + sy) * hiWidth + x * SUPER + sx;
			total += inside[index] ? .5 - Math.sqrt(interior[index]) : Math.sqrt(outside[index]) - .5;
		}

		const distance = total / (SUPER * SUPER) / SUPER;
		cell[y * width + x] = Math.max(0, Math.min(255, Math.round((.5 - distance / (SPREAD * 2)) * 255)));
	}

	return {cell, width, height, left: left / EM, top: top / EM};
};

/** Arrow outlines are authored in font units with y up; return em-unit, y-down path data and bounds. */
const arrowPath = points => {
	const scaled = points.map(([x, y]) => [x / 1000, -y / 1000]);
	const xs = scaled.map(([x]) => x), ys = scaled.map(([, y]) => y);

	return {
		data: 'M' + scaled.map(([x, y]) => `${x} ${y}`).join(' L') + ' Z'
		, bounds: {x1: Math.min(...xs), x2: Math.max(...xs), y1: Math.min(...ys), y2: Math.max(...ys)}
		, advance: Math.max(...xs) + .07
	};
};

/**
 * Extract em-unit outlines, advances, and kerning from TrueType faces.
 *
 * @param {Record<string,string>} faces Face name to TrueType path.
 * @returns {Promise<object>} Self-contained outline document for `buildFontAtlas()`.
 */
export async function extractFontOutlines(faces, extraCharacters = '')
{
	const {default: opentype} = await import('opentype.js');
	const document = {faces: {}};
	const path = data => data.replace(/-?\d+\.\d+/g, number => String(round(Number(number))));

	for(const [faceName, file] of Object.entries(faces))
	{
		const font = opentype.loadSync(file);
		const units = font.unitsPerEm;
		const face = {
			ascender: round(font.ascender / units)
			, descender: round(font.descender / units)
			, capHeight: round((font.tables.os2.sCapHeight || font.ascender * .7) / units)
			, glyphs: {}
			, kerning: {}
		};

		const characters = [...CHARACTERS, ...extraCharacters];
		for(const character of [...characters, ...Object.keys(ARROWS)])
		{
			if(ARROWS[character])
			{
				const {data, bounds, advance} = arrowPath(ARROWS[character]);
				face.glyphs[character] = {advance: round(advance), path: path(data), bounds: Object.values(bounds).map(round)};
				continue;
			}

			const glyph = font.charToGlyph(character);
			if(!glyph.index && character !== ' ') throw Error(`${faceName} lacks ${JSON.stringify(character)}`);
			const outline = glyph.getPath(0, 0, 1);
			const bounds = outline.getBoundingBox();
			const entry = {advance: round(glyph.advanceWidth / units)};

			if(Number.isFinite(bounds.x1) && bounds.x2 > bounds.x1)
			Object.assign(entry, {path: path(outline.toPathData(6)), bounds: [bounds.x1, bounds.x2, bounds.y1, bounds.y2].map(round)});

			face.glyphs[character] = entry;
		}

		for(const left of characters) for(const right of characters)
		{
			const value = font.getKerningValue(font.charToGlyph(left), font.charToGlyph(right)) / units;
			if(Math.abs(value) >= .001) face.kerning[left + right] = round(value);
		}

		document.faces[faceName] = face;
	}

	return document;
}

/**
 * Build the HUD font atlas from an extracted outline document.
 *
 * @param {object} outlines Document produced by `extractFontOutlines()`.
 * @returns {{rgba: Buffer, width: number, height: number, metrics: object}}
 */
export function buildFontAtlas(outlines)
{
	const cells = [];
	const metrics = {spread: SPREAD, em: EM, faces: {}};

	for(const [faceName, source] of Object.entries(outlines.faces))
	{
		const {glyphs, ...face} = source;
		face.glyphs = {};
		metrics.faces[faceName] = face;

		for(const [character, glyph] of Object.entries(glyphs))
		{
			const entry = {advance: glyph.advance};
			face.glyphs[character] = entry;
			if(!glyph.path) continue;
			const [x1, x2, y1, y2] = glyph.bounds;
			cells.push({entry, ...bakeGlyph(glyph.path, {x1, x2, y1, y2})});
		}
	}

	// Shelf-pack tallest cells first; ties keep source order so output bytes are stable.
	const order = cells.map((cell, index) => [cell, index])
		.sort(([a, ai], [b, bi]) => b.height - a.height || ai - bi).map(([cell]) => cell);
	let x = 0, y = 0, shelf = 0;

	for(const cell of order)
	{
		if(x + cell.width > ATLAS_WIDTH) {x = 0; y += shelf + 1; shelf = 0;}
		cell.x = x;
		cell.y = y;
		x += cell.width + 1;
		shelf = Math.max(shelf, cell.height);
	}

	const height = Math.ceil((y + shelf) / 4) * 4;
	const rgba = Buffer.alloc(ATLAS_WIDTH * height * 4);

	for(const cell of cells)
	{
		for(let row = 0; row < cell.height; row++) for(let column = 0; column < cell.width; column++)
		{
			const value = cell.cell[row * cell.width + column];
			const offset = ((cell.y + row) * ATLAS_WIDTH + cell.x + column) * 4;
			rgba[offset] = rgba[offset + 1] = rgba[offset + 2] = rgba[offset + 3] = value;
		}

		Object.assign(cell.entry, {
			atlas: [cell.x, cell.y, cell.width, cell.height]
			, plane: [round(cell.left), round(cell.top), round(cell.width / EM), round(cell.height / EM)]
		});
	}

	metrics.width = ATLAS_WIDTH;
	metrics.height = height;

	return {rgba, width: ATLAS_WIDTH, height, metrics};
}
