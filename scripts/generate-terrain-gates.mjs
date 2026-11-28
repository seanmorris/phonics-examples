#!/usr/bin/env node
// Paints the role prints of the Landscape Sandbox's race gates into the atlases of their meshes. Each atlas stacks
// four bands, one per role in the order of gates.json, and the meshes sample the top band, so a draw picks a role by
// offsetting its texture coordinates. The road and air gates share one design: a lettered label and the board's
// three colors. The water gate's arch print is drawn in gate meters and edged by a trim that follows the arch
// outline, found by rasterizing the arch's own faces.
//
// scripts/import_terrain_gates.py writes the geometry and gates.json and then runs this script. Only the pixels
// change here, so `--check` can verify the committed meshes without the source models. Files are written only when
// their decompressed bytes change. `--preview DIR` also writes every role print as a PNG for review.
import fs from 'node:fs/promises';
import path from 'node:path';
import zlib from 'node:zlib';
import {Resvg} from '@resvg/resvg-js';
import {PNG} from 'pngjs';

const assets = new URL('../games/terrain-sandbox/assets/gates/', import.meta.url);
const check = process.argv.includes('--check');
const previewAt = process.argv.indexOf('--preview');
const previewDirectory = previewAt < 0 ? null : process.argv[previewAt + 1];
const contract = JSON.parse(await fs.readFile(new URL('gates.json', assets), 'utf8'));
// Barlow Semi Condensed Bold, under the SIL Open Font License 1.1, as extracted for the billiards HUD.
const typography = new URL('../games/php-billiards/art/typography/barlow-semi-condensed.json', import.meta.url);
const font = JSON.parse(await fs.readFile(typography, 'utf8')).faces.bold;

/** Bytes of one vertex record: position, normal, texture coordinates, paint and surface. */
const STRIDE = 28;
// The gantry palette's own white, black and frame gray, and the role colors.
const WHITE = '#f5f5f5', BLACK = '#1f1f1f', FRAME = '#cecece';
const GREEN = '#17924a', YELLOW = '#f6cf16', RED = '#c3201f';

/**
 * Each role's lettering and colors. `board` colors the gantry's light and dark squares and its frame, `label` is
 * the label's ground and lettering, and `arch` is the arch's ground, its edge trim, the banner's ground and
 * lettering, and the pattern on its legs.
 */
const ROLES = {
	start: {
		text: 'START'
		, board: {light: WHITE, dark: GREEN, frame: FRAME}
		, label: [GREEN, WHITE]
		, arch: {ground: GREEN, trim: WHITE, banner: [WHITE, GREEN], legs: ['chevrons', WHITE]}
	}

	, checkpoint: {
		text: 'CHECKPOINT'
		, board: {light: YELLOW, dark: BLACK, frame: FRAME}
		, label: [BLACK, YELLOW]
		, arch: {ground: YELLOW, trim: BLACK, banner: [BLACK, YELLOW], legs: ['stripes', BLACK]}
	}

	, finish: {
		text: 'FINISH'
		, board: {light: WHITE, dark: BLACK, frame: FRAME}
		, label: [RED, WHITE]
		, arch: {ground: BLACK, trim: WHITE, banner: [RED, WHITE], legs: ['checkers', WHITE]}
	}

	, 'start-finish': {
		text: 'START / FINISH'
		, board: {light: WHITE, dark: BLACK, frame: FRAME}
		, label: [GREEN, WHITE]
		, arch: {ground: BLACK, trim: WHITE, banner: [GREEN, WHITE], legs: ['checkers', WHITE]}
	}
};

// The arch's trim is this wide inside its outline, and the banner sits this far above the window, this tall.
const TRIM = .3, BANNER_GAP = .15, BANNER_HEIGHT = 1.6, RULE = .1;

const rect = (x, y, width, height, fill) =>
	`<rect x="${x}" y="${y}" width="${width}" height="${height}" fill="${fill}"/>`;

const svg = (width, height, body) =>
	`<svg xmlns="http://www.w3.org/2000/svg" width="${width}" height="${height}">${body.join('')}</svg>`;

/** Resvg options for every print: the outlines carry the lettering, so no system fonts load. */
const RENDERING = {fitTo: {mode: 'original'}, shapeRendering: 2, font: {loadSystemFonts: false}};

/** Rasterize an SVG to RGBA bytes. Every print is opaque. */
function render(source)
{
	return new Resvg(source, RENDERING).render().pixels;
}

/**
 * Set a line of capitals centered on a point.
 *
 * @param {string} text Capitals, spaces and slashes.
 * @param {number} x Center in pixels.
 * @param {number} y Center of the capitals in pixels.
 * @param {number} size Capital height in pixels, reduced if the line would be wider than `width`.
 * @param {number} width Widest the inked line may be, in pixels.
 * @param {string} fill Lettering color.
 */
function lettering(text, x, y, size, width, fill)
{
	const characters = [...text];
	const glyphs = [];
	let pen = 0, left = Infinity, right = -Infinity;

	characters.forEach((character, index) => {
		const glyph = font.glyphs[character];

		if(!glyph) throw Error(`The gate lettering has no ${JSON.stringify(character)}`);

		if(glyph.path)
		{
			glyphs.push(`<path transform="translate(${pen} 0)" d="${glyph.path}"/>`);
			left = Math.min(left, pen + glyph.bounds[0]);
			right = Math.max(right, pen + glyph.bounds[1]);
		}

		pen += glyph.advance + (font.kerning[character + (characters[index + 1] ?? '')] ?? 0);
	});

	const scale = Math.min(size / font.capHeight, width / (right - left));
	// Glyph outlines are in ems with Y down from the baseline, and capitals rise capHeight above it.
	const origin = [x - (left + right) / 2 * scale, y + font.capHeight * scale / 2];

	return `<g fill="${fill}" transform="translate(${origin.join(' ')}) scale(${scale})">${glyphs.join('')}</g>`;
}

/** One role band of a gantry atlas: the label and a strip of each board color, from the layout in gates.json. */
function boardBand(role, print, width, height)
{
	const {text, board, label: [ground, ink]} = ROLES[role];
	const [x, y, w, h] = print.label;
	const strips = Object.entries(print.swatches).map(([name, area]) => rect(...area, board[name]));

	// The label's ground also fills the gutters, so filtering at its edges keeps its color.
	return svg(width, height, [rect(0, 0, width, height, ground), ...strips
		, lettering(text, x + w / 2, y + h / 2, h * .68, w - h * .5, ink)
	]);
}

/** Decompress a gate mesh and find where its geometry ends and its pixels begin. */
async function readMesh(file)
{
	const data = zlib.gunzipSync(await fs.readFile(file));
	const [vertices, indices, width, height] = [0, 4, 8, 12].map(offset => data.readUInt32LE(offset));
	const geometry = 16 + vertices * STRIDE + indices * 2;

	if(data.length !== geometry + width * height * 4) throw Error(`${file.pathname} has the wrong length`);

	return {data, vertices, indices, width, height, geometry};
}

/**
 * The band pixels covered by the arch faces that use the unmirrored mapping, which face +Z.
 * @returns {Uint8Array} 1 inside the arch, 0 outside, for every pixel of one band.
 */
function archMask(mesh, width, height)
{
	const {data, vertices} = mesh;

	const corner = index => {
		const at = 16 + index * STRIDE;

		return {
			normalZ: data.readInt16LE(at + 16) / 32767
			, u: data.readUInt16LE(at + 18) / 65535 * width
			, v: data.readUInt16LE(at + 20) / 65535 * mesh.height
		};
	};

	const polygon = corners => `<polygon points="${corners.map(c => `${c.u},${c.v}`).join(' ')}"/>`;
	const polygons = [];

	for(let k = 0; k < mesh.indices; k += 3)
	{
		const corners = [0, 1, 2].map(i => corner(data.readUInt16LE(16 + vertices * STRIDE + (k + i) * 2)));

		if(corners.every(c => c.normalZ > .9)) polygons.push(polygon(corners));
	}

	// The face is pieced together with T-junctions, so each triangle is also stroked to close the seams between them.
	const faces = `<g fill="#fff" stroke="#fff" stroke-width="1.5" stroke-linejoin="round">${polygons.join('')}</g>`;
	const pixels = render(svg(width, height, [faces]));
	const mask = new Uint8Array(width * height);

	for(let index = 0; index < mask.length; index++) mask[index] = pixels[index * 4 + 3] >= 128 ? 1 : 0;

	return mask;
}

/** One-dimensional squared distance transform of Felzenszwalb and Huttenlocher, in place on `values`. */
function transform1d(values, length, vertices, boundaries, output)
{
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
			s = ((values[q] + q * q) - (values[r] + r * r)) / (2 * q - 2 * r);
		}
		while(s <= boundaries[k] && --k >= 0);

		vertices[++k] = q;
		boundaries[k] = s;
		boundaries[k + 1] = Infinity;
	}

	k = 0;

	for(let q = 0; q < length; q++)
	{
		while(boundaries[k + 1] < q) k++;
		output[q] = (q - vertices[k]) ** 2 + values[vertices[k]];
	}

	values.set(output.subarray(0, length));
}

/** Distance in pixels from each pixel to the nearest pixel outside the mask. */
function insideDistance(mask, width, height)
{
	const grid = new Float64Array(width * height);
	const size = Math.max(width, height);
	const line = new Float64Array(size), output = new Float64Array(size);
	const vertices = new Int32Array(size), boundaries = new Float64Array(size + 1);

	for(let index = 0; index < grid.length; index++) grid[index] = mask[index] ? 1e20 : 0;

	for(let x = 0; x < width; x++)
	{
		for(let y = 0; y < height; y++) line[y] = grid[y * width + x];
		transform1d(line, height, vertices, boundaries, output);

		for(let y = 0; y < height; y++) grid[y * width + x] = line[y];
	}

	for(let y = 0; y < height; y++)
	{
		const row = grid.subarray(y * width, (y + 1) * width);
		line.set(row);
		transform1d(line, width, vertices, boundaries, output);
		row.set(line.subarray(0, width));
	}

	return grid.map(Math.sqrt);
}

/**
 * The pattern on the arch's legs in band pixels. `area` gives the visible part of each leg in meters, between the
 * trims at its sides and from the banner down. The pattern runs on under the trim and the banner, which are painted
 * over it, so no edge of its own shows.
 */
function legPattern([kind, ink], area, toPixels)
{
	const {inner, outer, bottom, top} = area;
	const under = TRIM / 2, across = (outer - inner) / 2;
	const near = inner - under, far = outer + under;
	const shapes = [];

	for(const side of [-1, 1])
	{
		if(kind === 'checkers')
		{
			// Two columns of squares as wide as the visible leg, the outer squares of each widened under the trim.
			for(let row = 0; top - row * across > bottom; row++)
			for(let column = 0; column < 2; column++)
			{
				if((row + column) % 2) continue;

				const [from, to] = column ? [inner + across, far] : [near, inner + across];
				shapes.push(toPixels.rect(side > 0 ? from : -to, top - (row + 1) * across, to - from, across, ink));
			}
		}
		else if(kind === 'stripes')
		{
			// Bands at 45 degrees that fall toward the window on both legs.
			const width = .45, rise = far - near;

			const [a, b] = [side * near, side * far];

			for(let y = bottom - rise; y < top + under; y += width * 2)
				shapes.push(toPixels.polygon([[a, y], [a, y + width], [b, y + width + rise], [b, y + rise]], ink));
		}
		else
		{
			// Chevrons pointing up the legs, their arms reaching under the trim.
			const middle = side * (inner + outer) / 2, half = across + under, rise = .4, weight = .26;
			const [a, b] = [middle - half, middle + half];

			for(let y = bottom + .2; y + rise + weight < top - .2; y += .85)
			{
				const apex = y + rise, base = y + weight, crown = apex + weight;
				const corners = [[a, y], [middle, apex], [b, y], [b, base], [middle, crown], [a, base]];
				shapes.push(toPixels.polygon(corners, ink));
			}
		}
	}

	const height = top + BANNER_HEIGHT / 2 - bottom;
	const clip = [-far, near].map(x => toPixels.rect(x, bottom, far - near, height, '#000')).join('');

	return `<clipPath id="legs">${clip}</clipPath><g clip-path="url(#legs)">${shapes.join('')}</g>`;
}

/** One role band of the arch atlas. */
function archBand(role, kind, mask, distance, width, height)
{
	const {pixelsPerMeter: k, origin: [ox, oy]} = kind.print;
	const {window, extent} = kind;
	const {text, arch} = ROLES[role];

	const point = ([x, y]) => `${ox + x * k},${oy - y * k}`;

	const toPixels = {
		rect: (x, y, w, h, fill) => rect(ox + x * k, oy - (y + h) * k, w * k, h * k, fill)
		, polygon: (points, fill) => `<polygon points="${points.map(point).join(' ')}" fill="${fill}"/>`
	};

	// The banner and its rules run past both sides of the arch, and the lettering fits between the legs.
	const half = extent.halfWidth + 1, banner = window.top + BANNER_GAP;

	const strip = (y, h, fill) => toPixels.rect(-half, y, 2 * half, h, fill);

	const room = (2 * window.halfWidth - .6) * k, capitals = BANNER_HEIGHT * .62 * k;
	const caption = lettering(text, ox, oy - (banner + BANNER_HEIGHT / 2) * k, capitals, room, arch.banner[1]);
	const [inner, outer] = [window.halfWidth + TRIM, extent.halfWidth - TRIM];
	const legs = {inner, outer, bottom: extent.bottom - 1, top: banner};

	const design = render(svg(width, height, [
		rect(0, 0, width, height, arch.ground)
		, legPattern(arch.legs, legs, toPixels)
		, strip(banner, BANNER_HEIGHT, arch.banner[0])
		, strip(banner, RULE, arch.trim)
		, strip(banner + BANNER_HEIGHT - RULE, RULE, arch.trim)
		, caption
	]));

	const trim = Buffer.from(arch.trim.slice(1), 'hex');
	const edge = TRIM * k;

	// Blend the trim over the last pixel of its width, and fill everything outside the arch with it.
	for(let index = 0; index < mask.length; index++)
	{
		const amount = Math.max(0, Math.min(1, edge - distance[index] + .5));

		for(let channel = 0; channel < 3; channel++)
		{
			const at = index * 4 + channel;
			design[at] = Math.round(design[at] * (1 - amount) + trim[channel] * amount);
		}
	}

	return design;
}

/** Every role's band of one gate kind, stacked into its atlas. */
function atlas(name, kind, mesh)
{
	const {width, height} = mesh;
	const band = height / contract.roles.length;
	const pixels = Buffer.alloc(width * height * 4);
	const bands = {};
	let mask = null, distance = null;

	if(name === 'water')
	{
		const front = archMask(mesh, width, band);
		// The faces toward -Z use the mapping mirrored about the origin's column, so the trim is measured from the
		// outline both mappings share, and is at least TRIM wide on either face.
		const [ox] = kind.print.origin;

		mask = front.map((inside, index) => {
			const x = index % width, mirrored = 2 * ox - 1 - x;

			return inside && mirrored >= 0 && mirrored < width ? front[index - x + mirrored] : 0;
		});

		distance = insideDistance(mask, width, band);
		bands.mask = front;
	}

	contract.roles.forEach(role => {
		const [u, v] = contract.atlas.roles[role];

		if(u !== 0 || !Number.isInteger(v * height) || (v * height) % band) throw Error(`Role ${role} is not a band`);

		const rgba = name === 'water'
			? archBand(role, kind, mask, distance, width, band)
			: render(boardBand(role, kind.print, width, band));

		Buffer.from(rgba.buffer, rgba.byteOffset, rgba.byteLength).copy(pixels, v * height * width * 4);
		bands[role] = rgba;
	});

	return {pixels, bands, band};
}

/** Dim the pixels outside a mask. Outside the arch's +Z face the print only lends its color to filtering. */
function dimOutside(rgba, mask)
{
	for(let index = 0; index < mask.length; index++)
	{
		if(mask[index]) continue;

		for(let c = 0; c < 3; c++) rgba[index * 4 + c] = rgba[index * 4 + c] * .35 + 24;
	}
}

/** Write a PNG of RGBA bytes. */
async function writePng(file, width, height, rgba)
{
	const png = new PNG({width, height});
	png.data = Buffer.from(rgba);
	await fs.writeFile(file, PNG.sync.write(png));
}

const stale = [];
let written = 0;

for(const [name, kind] of Object.entries(contract.kinds))
{
	const file = new URL(kind.mesh, assets);
	const mesh = await readMesh(file);
	const {pixels, bands, band} = atlas(name, kind, mesh);
	const content = Buffer.concat([mesh.data.subarray(0, mesh.geometry), pixels]);

	if(previewDirectory)
	{
		await fs.mkdir(previewDirectory, {recursive: true});

		for(const role of contract.roles)
		{
			const rgba = Buffer.from(bands[role]);

			if(bands.mask) dimOutside(rgba, bands.mask);

			await writePng(path.join(previewDirectory, `${name}-${role}.png`), mesh.width, band, rgba);
		}
	}

	if(content.equals(mesh.data)) continue;

	if(check) stale.push(kind.mesh);
	else
	{
		await fs.writeFile(file, zlib.gzipSync(content, {level: 9}));
		written++;
	}
}

// The signed instance code picks a cell; its sign mirrors the artwork for the driver's approach.
const signs = ['curve-hazard','curve','hairpin','swerve','kink','merge','hill','dip'];
const roads = new URL('../roads/', assets);
const cellWidth = 264, cellHeight = 328, atlasWidth = cellWidth*4, atlasHeight = cellHeight*2;
const pixels = Buffer.alloc(atlasWidth*atlasHeight*4);

for(const [i, name] of signs.entries())
{
	const face = new Resvg(
		await fs.readFile(new URL(name + '-sign.svg', roads))
		, {fitTo: {mode: 'width', value: 256}}
	).render();

	if(face.height !== (i === 0 ? 320 : 256)) throw Error('Unexpected sign proportions: ' + name);

	const rgba = Buffer.from(face.pixels);
	const x = (i%4)*cellWidth+4, y = Math.floor(i/4)*cellHeight+4;

	for(let row = 0; row<face.height; row++)
		rgba.copy(pixels, 4*((y+row)*atlasWidth+x), row*face.width*4, (row+1)*face.width*4);
}

const header = Buffer.alloc(8);
header.writeUInt32LE(atlasWidth);
header.writeUInt32LE(atlasHeight, 4);
const texture = Buffer.concat([header, pixels]);
const output = new URL('warning-signs.rgba.gz', roads);
const previous = await fs.readFile(output).catch(error => { if(error.code !== 'ENOENT') throw error; });

if(!previous || !texture.equals(zlib.gunzipSync(previous)))
{
	if(check) stale.push('warning-signs.rgba.gz');
	else await fs.writeFile(output, zlib.gzipSync(texture, {level: 9}));
}

if(stale.length) throw Error(`Road artwork is stale: ${stale.join(', ')}. Run node scripts/generate-terrain-gates.mjs`);

console.log(
	check
		? `Verified the prints of ${Object.keys(contract.kinds).length} gate meshes`
		: `Painted ${written} of ${Object.keys(contract.kinds).length} gate meshes`
);
