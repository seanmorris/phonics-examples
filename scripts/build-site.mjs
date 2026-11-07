import fs from 'node:fs/promises';
import path from 'node:path';
import {fileURLToPath, pathToFileURL} from 'node:url';
import {runtimeDirectory, engineRoot} from './runtime.mjs';
import {createHash} from 'node:crypto';

const root = fileURLToPath(new URL('../', import.meta.url));
const gamesRoot = path.join(root, 'games'), destination = path.join(root, 'site/dist');
const entries = [];

for(const directory of (await fs.readdir(gamesRoot, {withFileTypes: true})).filter(entry => entry.isDirectory()))
{
	const gameRoot = path.join(gamesRoot, directory.name);
	const metadata = JSON.parse(await fs.readFile(path.join(gameRoot, 'example.json')));

	if(metadata.schemaVersion !== 1 || !/^[a-z0-9]+(?:-[a-z0-9]+)*$/.test(metadata.slug))
	throw Error(`${directory.name}: invalid example metadata`);

	if(entries.some(entry => entry.slug === metadata.slug)) throw Error(`Duplicate example slug: ${metadata.slug}`);

	for(const name of [metadata.application,metadata.poster]) await fs.access(path.join(gameRoot, name));
	const posterExtension = path.extname(metadata.poster);

	if(!posterExtension) throw Error(`${directory.name}: poster must have a file extension`);
	entries.push({...metadata, directory: directory.name, posterRoute: `posters/${metadata.slug}${posterExtension}`});
}

entries.sort((a,b) => a.title.localeCompare(b.title));
await fs.rm(destination, {recursive: true, force: true});
await fs.mkdir(destination, {recursive: true});

async function copyTree(source, target)
{
	await fs.mkdir(path.dirname(target), {recursive: true});
	await fs.cp(source, target, {recursive: true});
}

/**
 * List each packaged world's default resources that live outside the PHAR, with the site path the runtime requests.
 *
 * Pack records the application's base relative to the PHAR's place in the game directory, and the runtime resolves
 * that base, then each world and its resources, against the published PHAR URL. Following the same steps keeps the
 * published files where the game asks for them.
 */
async function worldResources(gameRoot, entry, pharRoute)
{
	const manifest = JSON.parse(await fs.readFile(path.join(gameRoot, 'phonics.json')));
	const packed = new Set([...manifest.php?.files ?? [], ...manifest.browser?.files ?? [], ...manifest.assets ?? []
		, ...manifest.application?.assets ?? [], ...manifest.preload ?? []]);
	const site = new URL('https://site.invalid/');
	const base = path.relative(path.dirname(path.join(gameRoot, entry.application)), gameRoot).split(path.sep).join('/');
	const applicationRoot = new URL(base ? `${base}/` : './', new URL(pharRoute, site));
	const resources = [];

	for(const world of [...packed].filter(name => name.endsWith('.phonics-world.json')))
	{
		const data = JSON.parse(await fs.readFile(path.join(gameRoot, world)));

		for(const slot of Object.values(data.resourceSlots ?? {}))
		{
			const location = slot.defaultUrl;

			if(typeof location !== 'string' || /^[a-z]+:/i.test(location)) continue;

			const file = path.resolve(gameRoot, path.dirname(world), location);

			if(packed.has(path.relative(gameRoot, file).split(path.sep).join('/'))) continue;

			const url = new URL(location, new URL(world, applicationRoot));

			if(url.origin === site.origin) resources.push([file, decodeURIComponent(url.pathname.slice(1))]);
		}
	}

	return resources;
}

await copyTree(path.join(engineRoot, 'browser'), path.join(destination, 'engine/browser'));
const runtime = await runtimeDirectory();
const manifest = JSON.parse(await fs.readFile(path.join(runtime, 'php8.4-sdl.manifest.json')));

for(const name of [...manifest.files.map(file => file.path), 'php8.4-sdl.manifest.json'])
await copyTree(path.join(runtime, name), path.join(destination, 'runtime', name));

let zlib = path.join(runtime, 'extensions/zlib');

try { await fs.access(zlib); } catch { zlib = path.resolve(runtime, '../zlib'); }

for(const name of ['8.4.mjs','php8.4-zlib.so','libz.so','LICENSE','LICENSE-GPL','NOTICE','package.json'])
await copyTree(path.join(zlib, name), path.join(destination, 'runtime/extensions/zlib', name));

const {pharExtension} = await import(pathToFileURL(path.join(engineRoot, 'cli/phar-extension.mjs')));
const phar = await pharExtension();

for(const name of ['php8.4-phar.so','LICENSE','LICENSE-GPL','NOTICE','package.json'])
await copyTree(path.join(phar, name), path.join(destination, 'runtime/extensions/phar', name));

await copyTree(path.join(root, 'site/src/host.mjs'), path.join(destination, 'host.mjs'));
await copyTree(path.join(root, 'site/src/offline.mjs'), path.join(destination, 'offline.mjs'));
await copyTree(path.join(root, 'site/src/site.css'), path.join(destination, 'site.css'));

const escape = value => String(value).replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;').replaceAll('"','&quot;');
const page = ({title,body,className='',application='',embed=false,offline=false}) => `<!doctype html>
<html lang="en" class="${className}"${application ? ` data-application="${application}"` : ''}${embed ? ' data-game-embed="true"' : ''}${offline ? ' data-offline-manifest="./offline-manifest.json"' : ''}>
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="dark">
<title>${escape(title)}</title><link rel="icon" href="data:,"><link rel="stylesheet" href="${application ? '../../' : './'}site.css"></head>
<body>${body}${application ? `<script type="module" src="../../host.mjs"></script>` : ''}</body></html>`;

const cards = entries.map(entry => `<article class="game-card"><a href="./games/${entry.slug}/"><img src="./${entry.posterRoute}" alt="" width="1280" height="720"><div><h2>${escape(entry.title)}</h2><p>${escape(entry.description)}</p><span>Play full screen →</span></div></a></article>`).join('\n');
await fs.writeFile(path.join(destination, 'index.html'), page({
	title: 'Phonics Examples'
	, body: `<main class="catalog"><header><p class="eyebrow">PHONICS EXAMPLES</p><h1>Games choose the engine they need.</h1><p>Direct applications, authored worlds, and specialized resource formats can live together without forcing one content model on every game.</p></header><section>${cards}</section></main>`
}));

const published = new Map;

for(const entry of entries)
{
	const gameRoot = path.join(gamesRoot, entry.directory);
	const pharRoute = `applications/${entry.slug}/game.phar`;
	await copyTree(path.join(gameRoot, entry.application), path.join(destination, pharRoute));

	for(const [source, route] of await worldResources(gameRoot, entry, pharRoute))
	{
		if(published.has(route) && published.get(route) !== source)
		throw Error(`${entry.slug}: ${route} is already published from ${published.get(route)}`);

		published.set(route, source);
		await copyTree(source, path.join(destination, route));
	}

	await copyTree(path.join(gameRoot, entry.application + '.integrity'), path.join(destination, 'applications', entry.slug, 'game.phar.integrity'));
	await copyTree(path.join(gameRoot, entry.poster), path.join(destination, entry.posterRoute));
	const application = `../../applications/${entry.slug}/game.phar`;
	// Games read the declared size as their logical resolution before SDL resizes the drawing buffer, and the host
	// focuses the canvas once loaded, so it must be focusable for keyboard games.
	const size = entry.canvas ? ` width="${Number(entry.canvas.width)}" height="${Number(entry.canvas.height)}"` : '';
	const gameBody = `<main class="game-page"><nav><a href="../../">← All examples</a><span>${escape(entry.title)}</span></nav><section class="game-host"><div class="loading"><strong>Preparing ${escape(entry.title)}</strong><span>Loading application…</span></div><canvas aria-label="${escape(entry.title)} game canvas" tabindex="0"${size}></canvas></section></main>`;
	const embedBody = `<main class="embed-host"><div class="game-host"><div class="loading"><strong>${escape(entry.title)}</strong><span>Loading application…</span></div><canvas aria-label="${escape(entry.title)} game canvas" tabindex="0"${size}></canvas></div></main>`;
	await fs.mkdir(path.join(destination, 'games', entry.slug), {recursive: true});
	await fs.mkdir(path.join(destination, 'embed', entry.slug), {recursive: true});
	await fs.writeFile(path.join(destination, 'games', entry.slug, 'index.html'), page({title: entry.title,body: gameBody,className:'full-page',application,offline:entry.slug==='php-poker'}));
	await fs.writeFile(path.join(destination, 'embed', entry.slug, 'index.html'), page({title: entry.title,body: embedBody,className:'embed-page',application,embed:true}));
}

await fs.writeFile(path.join(destination, 'catalog.json'), JSON.stringify(entries.map(({directory,posterRoute,...entry}) => ({
	...entry
	, routes: {full: `games/${entry.slug}/`,embed: `embed/${entry.slug}/`,application: `applications/${entry.slug}/game.phar`}
})), null, 2) + '\n');
await fs.writeFile(path.join(destination, '.nojekyll'), '');
if(entries.some(entry=>entry.slug==='php-poker'))
{
	const paths=['host.mjs','offline.mjs','site.css','games/php-poker/index.html',
		'applications/php-poker/game.phar','applications/php-poker/game.phar.integrity'];
	for(const directory of ['engine/browser','runtime'])
		for(const name of await fs.readdir(path.join(destination,directory),{recursive:true}))
			if((await fs.stat(path.join(destination,directory,name))).isFile()) paths.push(directory+'/'+name.split(path.sep).join('/'));
	const files=[];
	for(const file of paths.sort())
	{
		const bytes=await fs.readFile(path.join(destination,file));
		files.push({path:file,bytes:bytes.length,sha256:createHash('sha256').update(bytes).digest('hex')});
	}
	const build=createHash('sha256').update(JSON.stringify(files)).digest('hex');
	await fs.writeFile(path.join(destination,'games/php-poker/offline-manifest.json'),JSON.stringify({schema:1,build,files}));
	const worker=(await fs.readFile(path.join(root,'site/src/poker-offline-worker.js'),'utf8')).replace('__POKER_BUILD__',build);
	await fs.writeFile(path.join(destination,'games/php-poker/offline-worker.js'),worker);
}
console.log(`Built ${entries.length} game and ${entries.length} embed in ${destination}`);
