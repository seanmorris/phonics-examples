import fs from 'node:fs/promises';
import path from 'node:path';
import {fileURLToPath, pathToFileURL} from 'node:url';
import {runtimeDirectory, engineRoot} from './runtime.mjs';

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
	entries.push({...metadata, directory: directory.name});
}

entries.sort((a,b) => a.title.localeCompare(b.title));
await fs.rm(destination, {recursive: true, force: true});
await fs.mkdir(destination, {recursive: true});

async function copyTree(source, target)
{
	await fs.mkdir(path.dirname(target), {recursive: true});
	await fs.cp(source, target, {recursive: true});
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
await copyTree(path.join(root, 'site/src/site.css'), path.join(destination, 'site.css'));

const escape = value => String(value).replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;').replaceAll('"','&quot;');
const page = ({title,body,className='',application='',embed=false}) => `<!doctype html>
<html lang="en" class="${className}"${application ? ` data-application="${application}"` : ''}${embed ? ' data-game-embed="true"' : ''}>
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="dark">
<title>${escape(title)}</title><link rel="stylesheet" href="${application ? '../../' : './'}site.css"></head>
<body>${body}${application ? `<script type="module" src="../../host.mjs"></script>` : ''}</body></html>`;

const cards = entries.map(entry => `<article class="game-card"><a href="./games/${entry.slug}/"><img src="./posters/${entry.slug}.svg" alt="" width="1280" height="720"><div><h2>${escape(entry.title)}</h2><p>${escape(entry.description)}</p><span>Play full screen →</span></div></a></article>`).join('\n');
await fs.writeFile(path.join(destination, 'index.html'), page({
	title: 'Phonics Examples'
	, body: `<main class="catalog"><header><p class="eyebrow">PHONICS EXAMPLES</p><h1>Games choose the engine they need.</h1><p>Direct applications, authored worlds, and specialized resource formats can live together without forcing one content model on every game.</p></header><section>${cards}</section></main>`
}));

for(const entry of entries)
{
	const gameRoot = path.join(gamesRoot, entry.directory);
	await copyTree(path.join(gameRoot, entry.application), path.join(destination, 'applications', entry.slug, 'game.phar'));
	await copyTree(path.join(gameRoot, entry.application + '.integrity'), path.join(destination, 'applications', entry.slug, 'game.phar.integrity'));
	await copyTree(path.join(gameRoot, entry.poster), path.join(destination, 'posters', entry.slug + '.svg'));
	const application = `../../applications/${entry.slug}/game.phar`;
	const gameBody = `<main class="game-page"><nav><a href="../../">← All examples</a><span>${escape(entry.title)}</span></nav><section class="game-host"><div class="loading"><strong>Preparing ${escape(entry.title)}</strong><span>Loading application…</span></div><canvas aria-label="${escape(entry.title)} game canvas"></canvas></section><footer><p>${escape(entry.description)}</p><p>Drag to orbit · wheel to zoom · arrows aim and set power · Space shoots</p></footer></main>`;
	const embedBody = `<main class="embed-host"><div class="game-host"><div class="loading"><strong>${escape(entry.title)}</strong><span>Loading application…</span></div><canvas aria-label="${escape(entry.title)} game canvas"></canvas></div></main>`;
	await fs.mkdir(path.join(destination, 'games', entry.slug), {recursive: true});
	await fs.mkdir(path.join(destination, 'embed', entry.slug), {recursive: true});
	await fs.writeFile(path.join(destination, 'games', entry.slug, 'index.html'), page({title: entry.title,body: gameBody,className:'full-page',application}));
	await fs.writeFile(path.join(destination, 'embed', entry.slug, 'index.html'), page({title: entry.title,body: embedBody,className:'embed-page',application,embed:true}));
}

await fs.writeFile(path.join(destination, 'catalog.json'), JSON.stringify(entries.map(({directory,...entry}) => ({
	...entry
	, routes: {full: `games/${entry.slug}/`,embed: `embed/${entry.slug}/`,application: `applications/${entry.slug}/game.phar`}
})), null, 2) + '\n');
await fs.writeFile(path.join(destination, '.nojekyll'), '');
console.log(`Built ${entries.length} game and ${entries.length} embed in ${destination}`);

