/** Run sequentially, without other browser jobs: --quick --exact --reference --firefox. Outputs JSON. */
import fs from 'node:fs/promises';
import http from 'node:http';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium, firefox } from 'playwright';

const root = fileURLToPath(new URL('../../../', import.meta.url));
const source = path.join(root, 'games/php-billiards');
const runtime = process.env.PHP_SDL_ROOT ?? path.join(root, '.cache/runtime');
const flags = process.argv.slice(2);
const server = http.createServer(async (request, response) => {
	try {
		if (request.url === '/') {
			response.end('<canvas></canvas>');
			return;
		}
		const file =
			request.url === '/contact.mjs'
				? path.join(source, 'browser/contact-kernel.mjs')
				: path.join(runtime, request.url);
		response.setHeader('Content-Type', file.endsWith('.mjs') ? 'text/javascript' : 'application/wasm');
		response.end(await fs.readFile(file));
	} catch {
		response.writeHead(404);
		response.end();
	}
});
await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
let browser;
try {
	browser = await (flags.includes('--firefox') ? firefox : chromium).launch({ headless: true });
	const page = await browser.newPage();
	await page.goto(`http://127.0.0.1:${server.address().port}/`);
	const files = {};
	for (const file of [
		'autoload.php',
		'src/Physics/Ball.php',
		'src/Physics/ContactModel.php',
		'src/Physics/ContactKernel.php',
		'src/Physics/PocketModel.php',
		'src/Physics/Simulation.php',
		'src/Physics/Table.php',
		'src/Online/BinaryState.php',
		'benchmarks/browser.php'
	])
		files[file] = await fs.readFile(path.join(source, file), 'utf8');
	const results = await page.evaluate(
		async ({ files, flags }) => {
			const { PhpSdl } = await import('/php8.4-sdl.mjs');
			const php = new PhpSdl({
				canvas: document.querySelector('canvas'),
				persist: false,
				ini: 'display_errors=0\nlog_errors=1\nerror_log=/dev/stderr\nmemory_limit=512M'
			});
			let output = '',
				errors = '';
			php.addEventListener('output', (e) => (output += e.detail.join('')));
			php.addEventListener('error', (e) => (errors += e.detail.join('')));
			const module = await php.binary,
				kernel = await import('/contact.mjs');
			module.contactGeometry = kernel.configureGeometry;
			module.contactBatch = kernel.contactBatchBase64;
			for (const [file, text] of Object.entries(files)) {
				const name = '/bench/' + file;
				module.FS.mkdirTree(name.slice(0, name.lastIndexOf('/')));
				module.FS.writeFile(name, text);
			}
			const exit = await php.run(`<?php $quick=${flags.includes('--quick') ? 'true' : 'false'};
			$exact=${flags.includes('--exact') ? 'true' : 'false'};$reference=${flags.includes('--reference') ? 'true' : 'false'};
			require '/bench/benchmarks/browser.php';`);
			if (exit || errors) throw new Error(`${exit}: ${errors} ${output}`);
			return JSON.parse(output);
		},
		{ files, flags }
	);
	console.log(
		JSON.stringify(
			{ browser: await browser.version(), reference: flags.includes('--reference'), ...results },
			null,
			2
		)
	);
} finally {
	await browser?.close();
	await new Promise((resolve) => server.close(resolve));
}
