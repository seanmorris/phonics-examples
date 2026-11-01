import fs from 'node:fs/promises';
import path from 'node:path';
import {createHash} from 'node:crypto';
import {Resvg} from '@resvg/resvg-js';
import {PNG} from 'pngjs';

const root = new URL('../games/php-billiards/', import.meta.url);
const source = new URL('art/svg/', root);
const output = new URL('assets/generated/', root);
const check = process.argv.includes('--check');
const hash = bytes => createHash('sha256').update(bytes).digest('hex');
const generated = new Map;

for(const [name,file] of [['ballAtlas','ball-atlas.svg'],['wood','wood.svg']])
{
	const svg = await fs.readFile(new URL(file, source));
	const png = new Resvg(svg, {fitTo: {mode: 'original'}}).render().asPng();
	const decoded = PNG.sync.read(png);
	const stem = file.replace('.svg', '');
	generated.set(`${stem}.png`, png);
	generated.set(`${stem}.rgba`, decoded.data);
	generated.set(name, {
		file: `${stem}.rgba`
		, width: decoded.width
		, height: decoded.height
		, sha256: hash(decoded.data)
	});
}

const manifest = Object.fromEntries([...generated].filter(([name]) => ['ballAtlas','wood'].includes(name)));
for(const name of ['ballAtlas','wood']) generated.delete(name);
generated.set('textures.json', Buffer.from(JSON.stringify(manifest, null, 2) + '\n'));
generated.set('poster.svg', await fs.readFile(new URL('poster.svg', source)));

if(check)
{
	const changed = [];

	for(const [name,bytes] of generated)
	{
		const current = await fs.readFile(new URL(name, output)).catch(() => null);

		if(!current?.equals(bytes)) changed.push(name);
	}

	if(changed.length) throw Error(`Generated billiards assets are stale: ${changed.join(', ')}`);

	console.log(`Verified ${generated.size} generated billiards assets`);
}
else
{
	await fs.mkdir(output, {recursive: true});

	for(const [name,bytes] of generated) await fs.writeFile(new URL(name, output), bytes);

	console.log(`Generated ${generated.size} billiards assets in ${path.dirname(new URL('textures.json', output).pathname)}`);
}

