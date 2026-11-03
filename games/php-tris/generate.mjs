import fs from 'node:fs/promises';
import {deflateSync} from 'node:zlib';
const base = new URL('./', import.meta.url);
const modes = {
	endless: 'Endless'
	, race: '40 Lines'
	, time: 'Time Attack'
	, squares: 'Squares'
	, cascade: 'Cascade'
	, targets: 'Target Rows'
	, bonds: 'Color Bonds'
	, reactor: 'Reactor'
};

const prop = (
	name
	, value
	, type = typeof value ==='boolean' ? 'bool' : typeof value==='number' ? 'int' : 'string'
) => ({
	name
	, type
	, value
});

const colors = ['#f06b45'
	, '#35d6aa'
	, '#a85ce8'
	, '#459fe0'
	, '#dfbd35'
	, '#e153a5'
	, '#75c936'
	, '#45536b'
	, '#83c7ed'
	, '#ffbf3f'
	, '#a873ff'
];

function crc(bytes)
{
	let c = 0xffffffff;

	for(const b of bytes)
	{
		c ^= b;

		for(let i = 0; i<8; i++) c = (c>>>1)^((c&1) ? 0xedb88320 : 0);
	}

	return (c^0xffffffff)>>>0;
}

function chunk(type, bytes)
{
	const t = Buffer.from(type),n = Buffer.alloc(4),c = Buffer.alloc(4);
	n.writeUInt32BE(bytes.length);
	c.writeUInt32BE(crc(Buffer.concat([t,bytes])));

	return Buffer.concat([n,t,bytes,c]);
}

const width = 264,height = 24,raw = Buffer.alloc((width*4+1)*height);

for(let y = 0; y<height; y++)
for(let x = 0; x<width; x++)
{
	const index = Math.floor(x/24),px = x%24,c = colors[index].match(/[a-f0-9]{2}/gi).map(v => parseInt(v, 16));
	const offset = y*(width*4+1)+1+x*4;

	const blend = (rgb, alpha) => c.forEach((value, i) => { c[i] = value*(1-alpha)+rgb[i]*alpha; });

	for(let step = 0; step<3; step++)
	{
		if(y>=step*2 && y<(step+1)*2) blend([255,255,255], [.48,.30,.14][step]);

		if(y>=24-(step+1)*2 && y<24-step*2) blend([.015*255,.025*255,.05*255], [.42,.26,.12][step]);
	}

	for(let step = 0; step<2; step++)
	{
		if(px===step) blend([255,255,255], [.20,.10][step]);

		if(px===23-step) blend([.015*255,.025*255,.05*255], [.20,.10][step]);
	}

	if(y===2 && px>=4 && px<9) blend([255,255,255], .24);

	for(let i = 0; i<3; i++) raw[offset+i] = Math.round(c[i]);

	raw[offset+3] = 255;
}

const header = Buffer.alloc(13);

header.writeUInt32BE(width);

header.writeUInt32BE(height, 4);

header[8] = 8; header[9] = 6;

await fs.writeFile(
	new URL('assets/blocks.png', base)
	, Buffer.concat(
		[Buffer.from([137,80,78,71,13,10,26,10])
			, chunk('IHDR', header)
			, chunk('IDAT', deflateSync(raw))
			, chunk('IEND', Buffer.alloc(0))
		]
	)
);

const write = (name, value) => fs.writeFile(new URL(name, base), JSON.stringify(value, null, 2) + '\n');

await write(
	'assets/blocks.tsj'
	, {
		type: 'tileset'
		, name: 'PHPTris cells'
		, tilewidth: 24
		, tileheight: 24
		, tilecount: 11
		, columns: 11
		, image: 'blocks.png'
		, imagewidth: width
		, imageheight: height
		, tiles: colors.map((
			color
			, id
		) => ({
			id
			, properties: [prop('color', color, 'color')
				, prop(
					'kind'
					, ['normal','normal','normal','normal','normal','normal','normal','garbage','goal','core','charge'][
						id
					]
				)
			]
		})
		)
	}
);

const maps = [];

for(const [mode,title] of Object.entries(modes))
{
	const count = ['endless','race','time'].includes(mode) ? 1 : 15;

	for(let rank = 1; rank<=count; rank++)
	{
		const data = Array(200).fill(0);

		if(['bonds','reactor'].includes(mode))
		{
			for(let y = 19-rank; y<=19; y++)
			{
				for(let x = 0; x<10; x++)
				{
					// Alternating access shafts remain reachable at every rank.
					const hole = (rank*3+Math.floor((19-y)/3))%8;

					if(x===hole || x===hole+1) continue;

					data[y*10+x] = (mode==='bonds' && y===19) ? 9 : 8;
				}
			}

			if(mode==='reactor')
			{
				data[190+(rank%2 ? 0 : 9)] = 10;
				data[180+(rank%2 ? 0 : 9)] = 0;
			}
		}

		const file = `${mode}-${String(rank).padStart(2, '0')}.tmj`;

		await write(
			'worlds/' + file
			, {
				type: 'map'
				, version: '1.10'
				, tiledversion: '1.12.2'
				, orientation: 'orthogonal'
				, renderorder: 'right-down'
				, infinite: false
				, width: 10
				, height: 20
				, tilewidth: 24
				, tileheight: 24
				, nextlayerid: 3
				, nextobjectid: 2
				, properties: [prop('mode', mode)
					, prop('name', title)
					, prop('rank', rank)
					, prop('seed', 1)
					, prop('lineTarget', 40)
					, prop('duration', 120)
					, prop('ghost', true)
				]
				, layers: [{
					id: 1
					, name: 'Initial cells'
					, type: 'tilelayer'
					, width: 10
					, height: 20
					, x: 0
					, y: 0
					, visible: true
					, opacity: 1
					, data
				}
				, {
					id: 2
					, name: 'Board'
					, type: 'objectgroup'
					, visible: true
					, opacity: 1
					, draworder: 'topdown'
					, objects: [{
						id: 1
						, name: 'Board'
						, type: 'PhpTris.Board'
						, x: 0
						, y: 0
						, width: 240
						, height: 480
						, rotation: 0
						, visible: true
						, properties: [prop('mode', mode)
							, prop('phonics.entity', '@php-tris/board')
						]
					}]
				}
				]
				, tilesets: [{firstgid: 1,source: '../assets/blocks.tsj'}]
			}
		);

		maps.push({fileName: file,x: (rank-1)*280,y: Object.keys(modes).indexOf(mode)*520,width: 240,height: 480});
	}
}

await write(
	'worlds/arcade.world'
	, {
		type: 'world'
		, onlyShowAdjacentMaps: false
		, maps
		, properties: [prop('phonics.import.php-tris', '../phonics.json')
			, prop('phonics.start', 'endless-01.tmj')
		]
	}
);

await write(
	'templates/board.tx'
	, {
		type: 'template'
		, object: {
			id: 1
			, name: 'Board'
			, type: 'PhpTris.Board'
			, width: 240
			, height: 480
			, properties: [prop('mode', 'endless')
				, prop('seed', 1)
				, prop('phonics.entity', '@php-tris/board')
			]
		}
	}
);

console.log(`Generated ${maps.length} original PHPTris maps and cell atlas.`);
