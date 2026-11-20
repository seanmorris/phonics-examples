/** North-up relief chart made from the terrain meshes that have already reached the scene. */
const cells = 32, pixels = 48, stride = cells+1;

const clamp = n => Math.max(0, Math.min(1, n));

const ramp = (low, high, n) => clamp((n-low)/(high-low));

const lerp = (a, b, t) => a+(b-a)*t;

function palette(settings)
{
	const colors = {};

	for(const [key, value] of Object.entries(settings))
	{
		if(!key.endsWith('Color')) continue;

		const rgb = parseInt(value.slice(1), 16);
		colors[key] = [rgb>>16, (rgb>>8)&255, rgb&255];
	}

	return {...settings, ...colors};
}

function bytes(encoded)
{
	if(Uint8Array.fromBase64) return Uint8Array.fromBase64(encoded);

	return Uint8Array.from(atob(encoded), c => c.charCodeAt(0));
}

/** Sample the grid at pixel centres. Neighbouring corners supply both height and the hillshade gradient. */
function relief(tile, p)
{
	const texture = document.createElement('canvas');
	texture.width = texture.height = pixels;
	const ctx = texture.getContext('2d');
	const image = ctx.createImageData(pixels, pixels);
	const heights = tile.heights, step = tile.size/cells;
	const canyon = p.preset.startsWith('canyon'), mountain = p.preset === 'mountain';
	const painted = [null, p.rockColor, p.grassColor, p.sandColor, p.snowColor, p.roadColor];

	for(let row = 0; row<pixels; row++)
	for(let col = 0; col<pixels; col++)
	{
		const u = (col+.5)*cells/pixels, v = (row+.5)*cells/pixels;
		const i = Math.floor(v)*stride+Math.floor(u), x = u%1, z = v%1;
		const a = heights[i], b = heights[i+1], c = heights[i+stride], d = heights[i+stride+1];
		const h = lerp(lerp(a, b, x), lerp(c, d, x), z);
		const dx = lerp(b-a, d-c, z)/step, dz = lerp(c-a, d-b, x)/step;
		const normalY = 1/Math.sqrt(1+dx*dx+dz*dz), slope = 1-normalY;

		const offset = (row*pixels+col)*4;

		if(h < p.waterLevel)
		{
			const depth = ramp(0, 70, p.waterLevel-h);

			for(let k = 0; k<3; k++)
				image.data[offset+k] = p.waterColor[k]*(1.12-.38*depth)+9*(1-depth);
		}
		else
		{
			let cover = p.grassColor, rock = p.rockColor;
			let bare = ramp(p.rockSlopeStart, p.rockSlopeEnd, slope);

			if(canyon)
			{
				cover = p.preset === 'canyon-v1' ? p.soilColor : p.canyonSandstoneColor;
				rock = p.preset === 'canyon-v1' ? p.rockBandColor : p.canyonRustColor;
			}
			else if(mountain)
			{
				const above = h-p.snowline;

				cover = above < p.treeline ? p.forestColor : above < p.tundraStart ? p.meadowColor : p.tundraColor;
				rock = p.graniteColor;
				bare = Math.max(bare, ramp(p.alpineStart, p.alpineEnd, above));
			}

			const beach = (1-ramp(p.beachHeight, p.beachHeight+p.beachWidth, h-p.waterLevel))*normalY;
			const snow = canyon
				? 0
				: ramp(p.snowline, p.snowline+p.snowTransition, h)
				*(1-ramp(p.snowSlopeStart, p.snowSlopeEnd, slope));

			const ink = painted[tile.paint[i+(x>=.5 ? 1 : 0)+(z>=.5 ? stride : 0)]];
			// Fixed north-west light keeps relief legible when flying at night.
			const shade = Math.max(.58, Math.min(1.45, 1.09+.52*(dx+dz)*normalY))
				*(.94+.14*ramp(-200, 500, h-p.waterLevel));

			for(let k = 0; k<3; k++)
			{
				let value = lerp(cover[k], rock[k], bare);
				value = lerp(lerp(value, p.sandColor[k], beach), p.snowColor[k], snow);
				image.data[offset+k] = (ink ? ink[k] : value)*shade;
			}
		}

		image.data[offset+3] = 255;
	}

	ctx.putImageData(image, 0, 0);

	return texture;
}

export function activate({module, canvas})
{
	const map = document.createElement('canvas');
	map.className = 'terrain-flight-map';
	map.setAttribute('role', 'img');
	map.setAttribute('aria-label', 'North-up terrain elevation map with aircraft heading and balloon positions');
	map.hidden = true;

	canvas.parentElement.append(map);
	const frame = map.closest('.terrain-sandbox'), ctx = map.getContext('2d');
	const tiles = new Map();
	let ordered = [], style, span, wide;

	function hide()
	{
		if(map.hidden) return;

		map.hidden = true;
		frame.classList.remove('has-flight-map');
		tiles.clear();
		ordered = [];
	}

	module.drawTerrainFlightMap = data => {
		const delta = data?.map;

		if(!delta)
		{
			hide();

			return;
		}

		if(delta.reset)
		{
			tiles.clear();
			ordered = [];
		}

		if(delta.span)
		{
			span = delta.span;
			wide = delta.wide;
		}

		if(delta.style)
		{
			style = palette(delta.style);

			for(const tile of tiles.values()) tile.image = null;
		}

		for(const key of delta.drop ?? []) tiles.delete(key);

		for(const [key,x,z,size,elevations,materials] of delta.tiles ?? [])
			tiles.set(key, {x,z,size, heights: new Float32Array(bytes(elevations).buffer), paint: bytes(materials)});

		if(delta.tiles || delta.drop) ordered = [...tiles.values()].sort((a, b) => b.size-a.size);

		const size = Math.round(Math.min(184, data.width*.235, data.height*.245));

		if(map.hidden || map.width !== size)
		{
			map.hidden = false;
			map.width = map.height = size;
			frame.style.setProperty('--flight-map-size', size + 'px');
			frame.classList.add('has-flight-map');
		}

		const mid = size/2, scale = size/span, jet = data.jet;

		const centerX = wide ? 0 : jet.x, centerZ = wide ? 0 : jet.z;

		const px = x => mid+(x-centerX)*scale, py = z => mid+(z-centerZ)*scale;
		ctx.fillStyle = '#132328';
		ctx.fillRect(0, 0, size, size);

		for(const tile of ordered)
		{
			const x = Math.round(px(tile.x)), y = Math.round(py(tile.z));
			const right = Math.round(px(tile.x+tile.size)), bottom = Math.round(py(tile.z+tile.size));

			if(x >= size || y >= size || right <= 0 || bottom <= 0) continue;

			tile.image ??= relief(tile, style);
			ctx.drawImage(tile.image, x, y, right-x, bottom-y);
		}

		ctx.lineWidth = 1;
		ctx.strokeStyle = '#bad5b5';
		ctx.strokeRect(.5, .5, size-1, size-1);

		const meters = span >= 20000 ? 5000 : span >= 4000 ? 1000 : span >= 1000 ? 250 : span >= 400 ? 100 : 25;
		const label = meters >= 1000 ? (meters/1000) + ' km' : meters + ' m';
		const bar = meters*scale;
		ctx.font = 'bold 10px monospace';
		ctx.fillStyle = '#10201edc';
		ctx.fillRect(1, 1, 16, 15);
		ctx.fillRect(size-bar-11, size-19, bar+10, 18);

		ctx.fillStyle = '#edf8db';
		ctx.textAlign = 'center';
		ctx.fillText('N', 9, 12);
		ctx.fillText(label, size-bar/2-6, size-8);
		ctx.fillRect(size-bar-6, size-5, bar, 1);
		ctx.fillRect(size-bar-6, size-7, 1, 3);
		ctx.fillRect(size-7, size-7, 1, 3);

		ctx.lineJoin = 'round';

		for(const [id,pos] of Object.entries(data.targets))
		{
			const x = px(pos[0])-mid, y = py(pos[2])-mid;
			const edge = (mid-7)/Math.max(1, Math.abs(x), Math.abs(y));

			ctx.save();
			ctx.translate(mid+x*Math.min(1, edge), mid+y*Math.min(1, edge));

			ctx.fillStyle = Number(id) === data.range.selected ? '#ff6357' : '#ffdc69';
			ctx.strokeStyle = '#1e1d17';
			ctx.lineWidth = 1.5;
			ctx.beginPath();

			if(edge < 1)
			{
				ctx.rotate(Math.atan2(y, x));

				ctx.moveTo(4, 0);
				ctx.lineTo(-3, -3.5);
				ctx.lineTo(-1, 0);
				ctx.lineTo(-3, 3.5);
			}
			else
			{
				ctx.moveTo(0, -4.5);
				ctx.lineTo(4, 0);
				ctx.lineTo(0, 4.5);
				ctx.lineTo(-4, 0);
			}

			ctx.closePath();
			ctx.fill();
			ctx.stroke();
			ctx.restore();
		}

		ctx.save();
		ctx.translate(px(jet.x), py(jet.z));
		ctx.rotate(Math.PI-jet.yaw);

		ctx.beginPath();
		ctx.moveTo(0, -7);
		ctx.lineTo(4.5, 5);
		ctx.lineTo(0, 3);
		ctx.lineTo(-4.5, 5);
		ctx.closePath();
		ctx.fillStyle = '#fffce4';
		ctx.strokeStyle = '#1b2824';

		ctx.lineWidth = 1.5;
		ctx.fill();
		ctx.stroke();
		ctx.restore();
	};

	return () => {
		hide();
		delete module.drawTerrainFlightMap;
		map.remove();
	};
}
