import fs from 'node:fs/promises';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
import {classes, presets, templateName} from '../authoring.mjs';
const root = fileURLToPath(new URL('../', import.meta.url));

const read = async file => JSON.parse(await fs.readFile(path.join(root, file)));

const write = async (file, data) => fs.writeFile(path.join(root, file), JSON.stringify(data, null, 2) + '\n');
const project = await read('worlds/demo.tiled-project');
project.propertyTypes = classes.map((type, index) => ({...type, type: 'class', id: index + 1}));
await write('worlds/demo.tiled-project', project);

await write(
	'worlds/phonics.classes.json'
	, {schemaVersion: 1, classes, bindings: {'Player': '@sane/spawn', 'SanePhonics.SpawnPoint': '@sane/spawn'}}
);

const prototypes = new Map;

const visit = (node, map) => {
	for(const object of node.objects ?? [])
	{
		const type = (object.class || object.type || '').replace('SanePhonics.Entity.', '');

		if(type && !prototypes.has(type)) prototypes.set(type, {object, map});
	}

	for(const layer of node.layers ?? []) visit(layer, map);
};

for(const file of (await fs.readdir(path.join(root, 'examples/assets'))).filter(file => file.endsWith('.tmj')).sort())
{
	const map = await read(`examples/assets/${file}`);
	visit(map, map);
}

for(const name of Object.keys(presets))
{
	const sample = prototypes.get(name);
	let object = sample ? structuredClone(sample.object) : {x: 0, y: 0, width: 128, height: 96};
	delete object.id;
	delete object.class;
	delete object.properties;
	object.type = `SanePhonics.Entity.${name}`;

	object.properties = presets[name].map(member => ({
		name: member.name
		, type: 'class'
		, propertytype: member.propertyType
		, value: member.value
	}));

	object.name = name;
	object.x = object.y = 0;
	object.visible = true;
	let tileset;

	if(object.gid && sample)
	{
		const set = sample.map.tilesets.filter(set => set.firstgid <= object.gid).at(-1);
		object.gid -= set.firstgid - 1;
		tileset = {firstgid: 1, source: `../examples/assets/${set.source}`};
	}

	// These point-authored actors also have a tile-object representation for editor previews.
	let art;

	if(name === 'ring') art = ['tiles.tsj', 4, 32, 32];

	if(name === 'CrabMeat') art = ['tiles.tsj', 19, 32, 32];

	if(name === 'MiniBoss') art = ['bosses.tsj', 0, 48, 48];

	if(name.endsWith('Monitor')) art = ['monitors.tsj', 0, 30, 32];

	if(art)
	{
		const [file, id, width, height] = art;
		delete object.point;
		Object.assign(object, {gid: id + 1, width, height});
		tileset = {firstgid: 1, source: `../examples/assets/${file}`};
	}

	// Linked entities deliberately start without map-local IDs. Choose their targets in Tiled.
	// Preserve only the shape and artwork; all tuning comes from the typed preset defaults.
	if(name === 'CurvedCeiling') object.polygon = [{x: 0,y: 0},{x: 128,y: 0},{x: 128,y: 32},{x: 0,y: 32}];

	if(name === 'Grinding') object.polyline = [{x: 0,y: 0},{x: 192,y: 0}];

	await write(`templates/${templateName(name)}.tj`, {type: 'template', ...(tileset ? {tileset} : {}), object});
}
