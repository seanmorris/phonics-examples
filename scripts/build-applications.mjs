import {execFile} from 'node:child_process';
import fs from 'node:fs/promises';
import {promisify} from 'node:util';
import {fileURLToPath} from 'node:url';
import path from 'node:path';

const execute = promisify(execFile);
const root = fileURLToPath(new URL('../', import.meta.url));
const binary = path.join(root, 'node_modules/.bin/phonics');
const games = path.join(root, 'games');

for(const entry of (await fs.readdir(games, {withFileTypes: true}))
	.filter(entry => entry.isDirectory()).sort((a, b) => a.name.localeCompare(b.name)))
{
	const game = path.join(games, entry.name);

	try { await fs.access(path.join(game, 'example.json')); }
	catch { continue; }

	const {stdout} = await execute(binary, ['pack',game], {cwd: root});
	process.stdout.write(stdout);
}
