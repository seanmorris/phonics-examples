import {execFile} from 'node:child_process';
import fs from 'node:fs/promises';
import path from 'node:path';
import {promisify} from 'node:util';
import {fileURLToPath} from 'node:url';

const execute = promisify(execFile);
const root = fileURLToPath(new URL('../', import.meta.url));
const games = path.join(root, 'games');
const binary = path.join(root, 'node_modules/.bin/phonics');

for(const entry of (await fs.readdir(games, {withFileTypes: true}))
	.filter(entry => entry.isDirectory()).sort((a, b) => a.name.localeCompare(b.name)))
{
	const directory = path.join(games, entry.name);

	try { await fs.access(path.join(directory, 'example.json')); }
	catch { continue; }

	const {stdout} = await execute(binary, ['validate',directory], {cwd: root});
	process.stdout.write(stdout);
}
