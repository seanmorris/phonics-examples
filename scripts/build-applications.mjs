import {execFile} from 'node:child_process';
import {promisify} from 'node:util';
import {fileURLToPath} from 'node:url';
import path from 'node:path';

const execute = promisify(execFile);
const root = fileURLToPath(new URL('../', import.meta.url));
const game = path.join(root, 'games/php-billiards');
const binary = path.join(root, 'node_modules/.bin/phonics');
const {stdout} = await execute(binary, ['pack',game], {cwd: root});
process.stdout.write(stdout);

