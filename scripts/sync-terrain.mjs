import fs from 'node:fs/promises';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
import {createHash} from 'node:crypto';

const root = fileURLToPath(new URL('../', import.meta.url));
const destination = path.join(root, 'packages/terrain-phonics');
const check = process.argv.includes('--check');
const source = process.env.PHONICS_ENGINE_ROOT
	? path.join(path.resolve(process.env.PHONICS_ENGINE_ROOT), 'packages/terrain-phonics')
	: null;

if(!check && !source) throw Error('Set PHONICS_ENGINE_ROOT to the canonical engine checkout before syncing terrain.');

const manifest = JSON.parse(await fs.readFile(path.join(source ?? destination, 'phonics.json')));

const files = [...new Set([...Object.keys(manifest.integrity)
	, 'phonics.json'
	, 'package.json'
	, 'composer.json'
	, 'README.md', 'LICENSE'
])];

for(const name of files)
{
	if(name.startsWith('/') || name.split('/').includes('..')) throw Error('Unsafe terrain package path');

	const target = path.join(destination, name);

	if(!check)
	{
		await fs.mkdir(path.dirname(target), {recursive: true});
		await fs.copyFile(path.join(source, name), target);
		continue;
	}

	const bytes = await fs.readFile(target);

	if(source && !bytes.equals(await fs.readFile(path.join(source, name))))
	throw Error(`Terrain mirror differs from canonical source: ${name}`);

	if(manifest.integrity[name] && manifest.integrity[name] !== 'sha256-'
		+ createHash('sha256').update(bytes).digest('base64')
	)
	throw Error(`Terrain package integrity mismatch: ${name}`);
}

console.log(`Terrain package ${check ? 'verified' : 'synced'} (${files.length} files).`);
