import {test,expect} from '@playwright/test';
import {readFile} from 'node:fs/promises';
import {createHash} from 'node:crypto';

test('accelerated contacts preserve complete PHP/WASM shot states and event transcripts',async({page})=>{
	test.setTimeout(180_000);
	const root=new URL('../../games/php-billiards/',import.meta.url),files={};
	for(const file of ['autoload.php','src/Physics/Ball.php','src/Physics/ContactModel.php','src/Physics/ContactKernel.php',
		'src/Physics/PocketModel.php','src/Physics/Simulation.php','src/Physics/Table.php','src/Online/BinaryState.php','benchmarks/browser.php'])
	files[file]=await readFile(new URL(file,root),'utf8');
	const kernelSource=await readFile(new URL('browser/contact-kernel.mjs',root),'utf8');
	await page.route('**/contact-test.mjs',route=>route.fulfill({contentType:'text/javascript',body:kernelSource}));
	// A catalog page supplies the origin without starting another game or its animation loop.
	await page.goto('/');
	const results=await page.evaluate(async files=>{
		const canvas=document.body.appendChild(document.createElement('canvas'));
		const {PhpSdl}=await import('/runtime/php8.4-sdl.mjs');
		const php=new PhpSdl({canvas,persist:false,ini:'display_errors=0\nlog_errors=1\nerror_log=/dev/stderr\nmemory_limit=512M'});
		let output='',errors='';
		php.addEventListener('output',e=>output+=e.detail.join(''));php.addEventListener('error',e=>errors+=e.detail.join(''));
		const module=await php.binary,kernel=await import('/contact-test.mjs');
		module.contactGeometry=kernel.configureGeometry;module.contactBatch=kernel.contactBatchBase64;
		for(const[file,text]of Object.entries(files)){
			const name='/bench/'+file;module.FS.mkdirTree(name.slice(0,name.lastIndexOf('/')));module.FS.writeFile(name,text);
		}
		const results=[];
		for(const reference of [true,false]){
			output='';errors='';
			const code=await php.run(`<?php $quick=true;$exact=true;$reference=${reference?'true':'false'};require '/bench/benchmarks/browser.php';`);
			if(code||errors)throw new Error(`${code}: ${errors} ${output}`);
			results.push(JSON.parse(output).physics);
		}
		return results;
	},files);
	const pinned=JSON.parse(await readFile(new URL('../fixtures/billiards-wasm-traces.json',import.meta.url),'utf8')).cases;
	for(let i=0;i<pinned.length;i++){
		expect(results[1][i].hashes,`Case ${i}: first differing frame`).toEqual(results[0][i].hashes);
		expect(results[0][i].steps).toBe(pinned[i].steps);
		expect(createHash('sha256').update(results[0][i].hashes.join('\n')).digest('hex')).toBe(pinned[i].transcriptHash);
	}
});
