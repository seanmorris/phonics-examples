import fs from 'node:fs/promises';
const root = new URL('./', import.meta.url);

const property = (name, value) => ({
	name
	, type: typeof value === 'boolean'
		? 'bool'
		: Number.isInteger(value) ? 'int' : typeof value === 'number' ? 'float' : 'string'
	, value
});

const names = ['First Light'
	, 'Copper Steps'
	, 'Magnetic Field'
	, 'Prism Split'
	, 'Laser Lattice'
	, 'Column Fire'
	, 'Menacer Crossing'
	, 'Verdant Circuit'
	, 'Destroyer Orbit'
	, 'Pulse Finale'
	, 'Copper Cascade'
	, 'Prism Gardens'
	, 'Flux Gates'
	, 'Solar Sails'
	, 'Missile Wells'
	, 'Silver Spiral'
	, 'Crossfire'
	, 'Greenhouse'
	, 'Ion Bridges'
	, 'Twin Reactors'
	, 'Copper Crown'
	, 'Shard Storm'
	, 'Night Watch'
	, 'Core Breach'
	, 'Last Light'
];

// Original layouts: digits are hit counts, X is gold, G is green, dots are open flight paths.
// The reference's separated clusters, diagonal bands and chambers informed these silhouettes;
// no DOS level bytes or assets are used by the generator or distributed with the game.
const layouts = [
	'..111111../.11111111./1111..1111/111....111/.11....11./..11..11../...1111...'
	, '22......22/221....122/.221..122./..221122../...2222.../..112211../.112..211./112....211'
	, '.222..222./2112..2112/21......12/21..11..12/21..11..12/211....112/.111..111./..11..11..'
	, '..1....1../.121..121./1222112221/.121..121./..1....1../.X......X./1111..1111/.11....11./..1....1..'
	, '1111111111/.2..22..2./.2..22..2./1111..1111/.X..XX..X./11.1111.11/.2..22..2./.2..22..2./1111111111'
	, '.11.22.11./.11.22.11./.22.11.22./.22.11.22./.X..XX..X./.11.22.11./.11.22.11./.22.11.22.'
	, '111....111/1221..1221/.12211221./..122221../X..1221..X/..112211../.112..211./112....211/11......11'
	, '..22..22../.2GG22GG2./.211..112./X.21..12.X/..21..12../.211..112./.21111112./..11..11../..X....X..'
	, '..333333../.322..223./321....123/32..XX..23/32......23/321....123/.322..223./..333333../1........1/.11....11.'
	, '3321..1233/32.1..1.23/2.G1XX1G.2/1112..2111/..X.33.X../1112..2111/2.21..12.2/32.1..1.23/3321..1233/..11..11..'
	, '22......../122......./1122....../..122...../...122..../....122.../.....122../......2211/.......221/........22'
	, '.121..121./1222112221/.121..121./........../..121121../.12222221./..121121../........../.11....11.'
	, '222....222/2........2/2.11..11.2/..11..11../X...22...X/..11..11../2.11..11.2/2........2/111....111'
	, '....11..../...1221.../..123321../.12333321./1233..3321/..12..21../...2..2.../..12..21../.11....11.'
	, '33..33..33/23..23..23/12..12..12/11..11..11/........../22..22..22/12..12..12/11..11..11/..11..11..'
	, '.33333333./.3......3./.3.2222.3./.3.2..2.3./.3.2..2.3./.3.222..3./.3......3./.33333333./....11....'
	, '22......22/.22....22./..22..22../...2332.../...2332.../..22..22../.22....22./22......22/..11..11..'
	, '.22.22.22./.2G.2G.2G./.21.21.21./........../..22..22../..G2..G2../..12..12../........../1111..1111'
	, '11......11/2211..1122/..22..22../..X....X../..111111../.22....22./22......22/........../.111..111.'
	, '.333..333./3213..3123/31......13/312....213/.33....33./....XX..../..22..22../.211..112./..11..11..'
	, '3...33...3/23..33..32/1232332321/.12222221./..111111../........../..X....X../.22....22./112....211'
	, '.3.....2../232...121./.3.....2../....3...../...232..../....3...../..2.....3./.121...232/..2.....3.'
	, '333....333/3.2....2.3/..21..12../...2112.../X...11...X/...2112.../..21..12../.12....21./11......11'
	, '33.2222.33/3.22..22.3/..2G..G2../.12....21./.1..XX..1./.12....21./..22..22../3..1111..3/33......33'
	, '....33..../..332233../.321..123./321....123/21..GG..12/321....123/.321..123./..332233../...1111.../..11..11..'
].map(layout => layout.split('/'));

const stagePowers = [
	['expand', 'catch', 'split', 'laser']
	, ['expand', 'shrink', 'fast', 'slow']
	, ['catch', 'expand', 'slow']
	, ['split', 'catch', 'extra']
	, ['laser', 'split']
	, ['missile', 'laser', 'power']
	, ['laser', 'power', 'catch', 'extra']
	, ['missile', 'split', 'slow', 'extra']
	, ['laser', 'missile', 'fast', 'slow', 'exit']
	, ['missile', 'power', 'extra', 'expand', 'split']
	, ['missile', 'expand', 'slow']
	, ['split', 'catch', 'extra']
	, ['catch', 'laser', 'slow']
	, ['expand', 'split', 'power']
	, ['missile', 'power', 'extra']
	, ['laser', 'catch', 'slow']
	, ['laser', 'expand', 'extra']
	, ['split', 'missile', 'slow']
	, ['catch', 'power', 'expand']
	, ['missile', 'laser', 'extra']
	, ['power', 'expand', 'slow']
	, ['split', 'catch', 'laser']
	, ['laser', 'missile', 'extra', 'slow']
	, ['missile', 'power', 'catch', 'extra']
	, ['expand', 'catch', 'split', 'power', 'extra']
];

// Later stages revisit the original illustrated environments with new authored geometry.
const backgrounds = [1,2,3,4,5,6,7,8,9,10,2,4,3,1,6,4,7,8,5,10,2,4,9,10,1];

for(let stage = 1; stage <= names.length; stage++)
{
	const objects = [{
		id: 1
		, name: 'Paddle'
		, type: 'PhpNoid.Paddle'
		, point: true
		, x: 320
		, y: 450
		, properties: [property('phonics.entity', '@php-noid/paddle')]
	}];

	const layout = layouts[stage - 1];
	const droppers = [];

	for(const [row, cells] of layout.entries())
	{
		if(cells.length !== 10 || /[^.123XG]/.test(cells)) throw new Error(`Invalid stage ${stage}, row ${row}`);

		for(const [column, cell] of [...cells].entries())
		{
			if(cell === '.') continue;

			const unbreakable = cell === 'X';
			const hits = Number(cell) || 1;
			const material = cell === 'G' ? 'green' : hits === 3 ? 'silver' : hits === 2 ? 'copper' : 'normal';
			const brick = {
				id: objects.length + 1
				, name: `Brick ${row + 1}:${column + 1}`
				, type: 'PhpNoid.Brick'
				, x: 24 + column * 59
				, y: 70 + row * 27
				, width: 54
				, height: 20
				, properties: [property('hits', hits)
					, property('unbreakable', unbreakable)
					, property('material', material)
					, property('capsule', '')
					, property('score', 100 * hits)
					, property('phonics.entity', '@php-noid/brick')
				]
			};

			objects.push(brick);

			if(!unbreakable && material !== 'green') droppers.push(brick);
		}
	}

	// Seed approachable pickups from the bottom up, cycling independently of grid coordinates.
	// First Light's four lowest bricks each introduce a different helpful power.
	droppers.sort((a, b) => b.y - a.y || a.x - b.x);
	let drop = 0;
	const powers = stagePowers[stage - 1];

	for(const [index, brick] of droppers.entries())
	{
		if(stage === 1 ? index >= 4 && index % 6 !== 0 : index % 4 !== 0) continue;

		brick.properties.find(value => value.name === 'capsule').value = powers[drop++ % powers.length];
	}

	const ships = [[]
		, []
		, []
		, []
		, [['sympathizer', 'cyan']]
		, [['floater', 'pewter']]
		, [['menacer', 'copper'], ['menacer', 'silver'], ['menacer', 'cyan']]
		, [['menacer', 'green'], ['menacer', 'red'], ['menacer', 'pewter']]
		, [['destroyer', ''], ['menacer', 'cyan']]
		, [['annihilator', ''], ['destroyer', ''], ['menacer', 'green'], ['menacer', 'red'], ['sympathizer', 'cyan']]
		, [['floater', 'pewter'], ['sympathizer', 'cyan']]
		, [['menacer', 'silver']]
		, [['menacer', 'cyan'], ['menacer', 'copper']]
		, [['sympathizer', 'cyan'], ['destroyer', '']]
		, [['destroyer', ''], ['floater', 'pewter']]
		, [['menacer', 'silver'], ['sympathizer', 'cyan']]
		, [['destroyer', ''], ['menacer', 'cyan'], ['sympathizer', 'cyan']]
		, [['menacer', 'green'], ['menacer', 'red'], ['sympathizer', 'cyan']]
		, [['menacer', 'copper'], ['floater', 'pewter']]
		, [['destroyer', ''], ['annihilator', ''], ['sympathizer', 'cyan']]
		, [['menacer', 'copper'], ['menacer', 'silver'], ['sympathizer', 'cyan']]
		, [['floater', 'pewter'], ['menacer', 'cyan'], ['destroyer', '']]
		, [['annihilator', ''], ['destroyer', ''], ['sympathizer', 'cyan']]
		, [['menacer', 'green'], ['menacer', 'red'], ['destroyer', ''], ['sympathizer', 'cyan']]
		, [['annihilator', ''], ['menacer', 'red'], ['menacer', 'green'], ['sympathizer', 'cyan']]
	][stage - 1];

	for(const [ship, [family, effect]] of ships.entries())
	objects.push({
		id: objects.length + 1
		, name: `${family} ${effect}`.trim()
		, type: 'PhpNoid.Enemy'
		, x: 70 + ship * 110
		, y: 32
		, width: 28
		, height: 16
		, properties: [property('family', family)
			, property('effect', effect)
			, property('interval', family === 'menacer' ? 7 : 9)
			, property('hits', 3), property('capsule', stage === 5 ? 'laser' : 'extra')
			, property('phonics.entity', '@php-noid/enemy')
		]
	});

	const map = {
		type: 'map'
		, version: '1.10'
		, tiledversion: '1.12.2'
		, orientation: 'orthogonal'
		, renderorder: 'right-down'
		, width: 40
		, height: 30
		, tilewidth: 16
		, tileheight: 16
		, infinite: false
		, nextobjectid: objects.length + 1
		, nextlayerid: 3
		, tilesets: []
		, properties: [property('name', names[stage - 1])]
		, layers: [{
			id: 1
			, name: 'Background'
			, type: 'imagelayer'
			, image: `../assets/backgrounds/stage-${String(backgrounds[stage - 1]).padStart(2, '0')}.png`
			, imagewidth: 640
			, imageheight: 480
			, opacity: 1
			, visible: true
			, x: 0
			, y: 0
		}
		, {id: 2, name: 'Entities', type: 'objectgroup', opacity: 1, visible: true, objects}]
	};

	await fs.writeFile(
		new URL(`worlds/stage-${String(stage).padStart(2, '0')}.tmj`, root)
		, JSON.stringify(map, null, 2) + '\n'
	);
}

const world = {
	type: 'world'
	, onlyShowAdjacentMaps: false
	, maps: names.map((_, i) => ({
		fileName: `stage-${String(i + 1).padStart(2, '0')}.tmj`
		, x: i * 680
		, y: 0
		, width: 640
		, height: 480
	}))
	, properties: [property('phonics.import.php-noid', '../phonics.json')
		, property('phonics.start', 'stage-01.tmj')
	]
};

await fs.writeFile(new URL('worlds/campaign.world', root), JSON.stringify(world, null, 2) + '\n');
