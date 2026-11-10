import fs from 'node:fs/promises';
import path from 'node:path';
import {createHash} from 'node:crypto';
import {execFileSync} from 'node:child_process';
import {fileURLToPath} from 'node:url';

const root = fileURLToPath(new URL('../', import.meta.url));
const engineEntry = fileURLToPath(import.meta.resolve('@seanmorris/phonics'));
export const engineRoot = path.dirname(path.dirname(engineEntry));

/** Reject SDL packages built before the browser controller-rumble port landed. */
async function requireControllerRumble(directory)
{
	const manifest = JSON.parse(await fs.readFile(path.join(directory, 'php8.4-sdl.manifest.json')));
	const runtime = await fs.readFile(path.join(directory, manifest.runtime), 'utf8');

	if(!runtime.includes('vibrationActuator') || !runtime.includes('dual-rumble') || !runtime.includes('playEffect'))
	throw Error('php-sdl-wasm runtime lacks browser controller rumble');

	return directory;
}

export async function runtimeDirectory()
{
	const candidates = [process.env.PHP_SDL_ROOT, path.join(root, '.cache/runtime'), path.join(root, 'node_modules/php-sdl-wasm')]
		.filter(Boolean).map(value => path.resolve(value));

	for(const directory of candidates)
	try
	{
		return await requireControllerRumble(directory);
	}
	catch { /* Try the next source. */ }

	const lock = JSON.parse(await fs.readFile(path.join(engineRoot, 'runtime-lock.json')));
	const response = await fetch(lock.url);

	if(!response.ok) throw Error(`Runtime download failed: ${response.status}`);

	const bytes = Buffer.from(await response.arrayBuffer());

	if(createHash('sha256').update(bytes).digest('hex') !== lock.sha256)
	throw Error('Runtime archive checksum mismatch');

	const cache = path.join(root, '.cache'), archive = path.join(cache, 'runtime.tgz');
	const staging = path.join(cache, 'runtime-staging'), destination = path.join(cache, 'runtime');
	await fs.mkdir(cache, {recursive: true});
	await fs.writeFile(archive, bytes);
	await fs.rm(staging, {recursive: true, force: true});
	await fs.mkdir(staging, {recursive: true});
	const entries = execFileSync('tar', ['-tzf',archive], {encoding: 'utf8'}).split('\n').filter(Boolean);

	if(entries.some(name => name.startsWith('/') || name.split('/').includes('..')))
	throw Error('Unsafe runtime archive');

	execFileSync('tar', ['-xzf',archive,'-C',staging]);
	const manifest = JSON.parse(await fs.readFile(path.join(staging, 'php8.4-sdl.manifest.json')));

	for(const file of manifest.files)
	{
		const data = await fs.readFile(path.join(staging, file.path));

		if(createHash('sha256').update(data).digest('hex') !== file.sha256)
		throw Error(`Runtime checksum mismatch: ${file.path}`);
	}

	await fs.rm(destination, {recursive: true, force: true});
	await fs.rename(staging, destination);
	await fs.rm(archive, {force: true});

	return requireControllerRumble(destination);
}
