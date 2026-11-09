import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs/promises';

const root = new URL('../', import.meta.url);
const game = new URL('games/php-billiards/', root);
const expectedGames = ['doom-phonics','phlappy-phant','php-billiards','php-buster','php-noid','php-poker','php-slalom','php-tris','sane-phonics'];

test('catalog metadata owns every migrated game and its documentation', async() => {
	const directories = (await fs.readdir(new URL('games/', root), {withFileTypes: true}))
		.filter(entry => entry.isDirectory()).map(entry => entry.name).sort();
	assert.deepEqual(directories, expectedGames);

	for(const slug of directories)
	{
		const directory = new URL(`games/${slug}/`, root);
		const data = JSON.parse(await fs.readFile(new URL('example.json', directory)));
		assert.equal(data.slug, slug);
		assert.ok(data.documentation.chapters.length > 0, `${slug} has documentation chapters`);
		assert.match(data.documentation.api.namespace, /^[A-Z][A-Za-z0-9]*$/, `${slug} has a PHP API namespace`);
		assert.ok(data.documentation.api.description, `${slug} has a PHP API description`);

		for(const chapter of data.documentation.chapters)
		await fs.access(new URL(chapter.path, directory));
	}
});

test('every packaged world imports package manifests that exist in this repository', async() => {
	for(const slug of expectedGames)
	{
		const directory = new URL(`games/${slug}/`, root);
		const manifest = JSON.parse(await fs.readFile(new URL('phonics.json', directory)));
		const worlds = [...manifest.assets ?? [], ...manifest.application?.assets ?? []]
			.filter(name => name.endsWith('.phonics-world.json'));

		// Imports are resolved from the world file, so a path written for another checkout layout 404s at runtime.
		for(const name of new Set(worlds))
		{
			const world = new URL(name, directory);

			for(const [alias, value] of Object.entries(JSON.parse(await fs.readFile(world)).imports ?? {}))
			{
				const location = typeof value === 'string' ? value : value?.url;

				if(!location || /^[a-z]+:|^@/i.test(location)) continue;

				const target = new URL(location.split('#')[0], world);
				assert.ok(target.pathname.endsWith('/phonics.json'), `${slug}/${name} imports ${alias} from a manifest`);
				await assert.doesNotReject(fs.access(target), `${slug}/${name} imports ${alias} from ${location}`);
			}
		}
	}
});

test('billiards metadata declares stable full and embed dimensions', async() => {
	const data = JSON.parse(await fs.readFile(new URL('example.json', game)));
	assert.equal(data.schemaVersion, 1);
	assert.equal(data.slug, 'php-billiards');
	assert.deepEqual(data.canvas, {width: 1280,height: 720});
	assert.equal(data.aspectRatio, '16/9');
	assert.equal(data.poster, 'assets/generated/poster.png');
});

test('billiards is a direct application without world adapters', async() => {
	const manifest = JSON.parse(await fs.readFile(new URL('phonics.json', game)));
	const files = [
		new URL('application.php', game)
		, new URL('browser/game.mjs', game)
		, ...manifest.php.files.map(name => new URL(name, game))
	];
	const source = (await Promise.all(files.map(file => fs.readFile(file, 'utf8')))).join('\n');
	assert.equal(manifest.application.entry, 'application.php');
	assert.equal(manifest.tiled, undefined);
	assert.doesNotMatch(source, /Tiled|Doom|WAD|->compose\s*\(/i);
});

test('every Billiards class documents its summary and detailed responsibility', async() => {
	const sourceRoot=new URL('src/',game);
	const files=(await fs.readdir(sourceRoot,{recursive:true})).filter(file=>file.endsWith('.php'));
	let classes=0;

	for(const file of files)
	{
		const source=await fs.readFile(new URL(file,sourceRoot),'utf8');

		for(const match of source.matchAll(/\/\*\*([\s\S]*?)\*\/\s*final class\s+([A-Za-z_][A-Za-z0-9_]*)/g))
		{
			classes++;
			const lines=match[1].split(/\r?\n/).map(line=>line.replace(/^\s*\* ?/,'').trim());
			while(lines[0]==='')lines.shift();
			while(lines.at(-1)==='')lines.pop();
			assert.ok(lines[0],`${file}: ${match[2]} needs a one-line summary`);
			assert.equal(lines[1],'',`${file}: ${match[2]} needs a blank line after its summary`);
			assert.ok(lines.slice(2).filter(Boolean).length>=2,
				`${file}: ${match[2]} needs a multiline description below its summary`);
		}
	}

	assert.equal(classes,26,'The Billiards class-doc audit missed a source class');
});

test('every public Billiards parameter has a short PHPDoc description', async() => {
	const sourceRoot=new URL('src/',game);
	const files=(await fs.readdir(sourceRoot,{recursive:true})).filter(file=>file.endsWith('.php'));
	let parameters=0;

	for(const file of files)
	{
		const source=await fs.readFile(new URL(file,sourceRoot),'utf8');
		const methods=/public\s+(?:static\s+)?function\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(([\s\S]*?)\)\s*(?::[^\{]+)?\{/g;

		for(const method of source.matchAll(methods))
		{
			const names=[...method[2].matchAll(/\$([A-Za-z_][A-Za-z0-9_]*)/g)].map(match=>match[1]);
			if(!names.length)continue;

			const prefix=source.slice(0,method.index);
			const doc=prefix.match(/\/\*\*([\s\S]*?)\*\/\s*$/);
			assert.ok(doc,`${file}: ${method[1]} needs PHPDoc for its parameters`);
			const documented=new Map;

			for(const line of doc[1].split(/\r?\n/))
			{
				const tag=line.replace(/^\s*\* ?/,'').trim()
					.match(/^@param\s+\S+\s+(?:&)?(?:\.\.\.)?\$([A-Za-z_][A-Za-z0-9_]*)\s+(.+)$/);
				if(tag)documented.set(tag[1],tag[2]);
			}

			for(const name of names)
			{
				parameters++;
				assert.ok(documented.get(name),`${file}: ${method[1]}() needs a short description for $${name}`);
			}
		}
	}

	assert.ok(parameters>100,'The public parameter audit did not cover the complete Billiards API');
});

test('every public Billiards property has a short PHPDoc description', async() => {
	const sourceRoot=new URL('src/',game);
	const files=(await fs.readdir(sourceRoot,{recursive:true})).filter(file=>file.endsWith('.php'));
	let properties=0;

	for(const file of files)
	{
		const source=await fs.readFile(new URL(file,sourceRoot),'utf8');
		const declarations=/^\tpublic\s+(?!(?:const|function)\b)(?:static\s+)?(?:readonly\s+)?[?A-Za-z_\\][A-Za-z0-9_\\|&?]*\s+\$([A-Za-z_][A-Za-z0-9_]*)/gm;

		for(const property of source.matchAll(declarations))
		{
			properties++;
			const prefix=source.slice(0,property.index);
			const doc=prefix.match(/\/\*\*([\s\S]*?)\*\/\s*$/);
			assert.ok(doc,`${file}: $${property[1]} needs a PHPDoc description`);
			const lines=doc[1].split(/\r?\n/).map(line=>line.replace(/^\s*\* ?/,'').trim()).filter(Boolean);
			const prose=lines.find(line=>!line.startsWith('@'));
			const typed=lines.find(line=>/^@var\s+\S+\s+.+/.test(line));
			assert.ok(prose||typed,`${file}: $${property[1]} needs a short description`);
		}
	}

	assert.equal(properties,58,'The public property audit missed a Billiards property');
});

test('framework dependencies are pinned to an immutable commit', async() => {
	const packageData = JSON.parse(await fs.readFile(new URL('package.json', root)));
	const composer = JSON.parse(await fs.readFile(new URL('composer.json', root)));
	const npmCommit = packageData.dependencies['@seanmorris/phonics'].match(/([0-9a-f]{40})(?:\.tar\.gz|$)/)?.[1];
	const composerCommit = composer.require['seanmorris/phonics'].match(/#([0-9a-f]{40})$/)?.[1];
	assert.ok(npmCommit, 'npm dependency must contain a complete commit SHA');
	assert.equal(composerCommit, npmCommit, 'npm and Composer must resolve the same Phonics commit');
});
