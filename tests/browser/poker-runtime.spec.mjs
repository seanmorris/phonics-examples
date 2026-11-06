import {test,expect} from '@playwright/test';
import fs from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
import {execFileSync} from 'node:child_process';
import {pack} from '../../node_modules/@seanmorris/phonics/cli/pack.mjs';
import {serve} from '../../node_modules/@seanmorris/phonics/cli/server.mjs';

const root=fileURLToPath(new URL('../../',import.meta.url));
let directory,server,origin,expected;

test.beforeAll(async()=>{
	directory=await fs.mkdtemp(path.join(os.tmpdir(),'poker-runtime-'));
	const files=['autoload.php',...['Game','Hand','Profile','Session','Bot','Adaptation','WildCards'].map(name=>'src/'+name+'.php'),'tests/replay.php'];
	for(const name of files)
	{
		await fs.mkdir(path.dirname(path.join(directory,name)),{recursive:true});
		await fs.copyFile(path.join(root,'games/php-poker',name),path.join(directory,name));
	}
	await fs.writeFile(path.join(directory,'index.html'),'<!doctype html><canvas></canvas>');
	await fs.writeFile(path.join(directory,'application.php'),`<?php
require_once 'phar://'.Phar::running(false).'/core/autoload.php';
require __DIR__.'/autoload.php';
require __DIR__.'/tests/replay.php';
$app=new Phonics\\Application\\Context;
vrzno_env('canvas')->dataset->pokerReplay=json_encode(pokerReplayFixture(),JSON_THROW_ON_ERROR);
vrzno_env('canvas')->dataset->phpIntSize=(string)PHP_INT_SIZE;
$app->ready();
`);
	await fs.writeFile(path.join(directory,'phonics.json'),JSON.stringify({schemaVersion:1,name:'@test/poker-replay',version:'1.0.0',php:{files},application:{entry:'application.php'}}));
	expected=JSON.parse(execFileSync('php',['-r',String.raw`
require $argv[1].'/node_modules/@seanmorris/phonics/autoload.php';
require $argv[1].'/games/php-poker/autoload.php';
require $argv[1].'/games/php-poker/tests/replay.php';
echo json_encode(pokerReplayFixture(),JSON_THROW_ON_ERROR);
`,root],{encoding:'utf8'}));
	await pack(directory);
	server=await serve(directory,{port:0,headers:{'Access-Control-Allow-Origin':'*'}});
	origin='http://127.0.0.1:'+server.address().port;
});

test.afterAll(async()=>{
	await new Promise(resolve=>server?server.close(resolve):resolve());
	await fs.rm(directory,{recursive:true,force:true});
});

test('offline poker replays and saves identically on native PHP and browser WASM',async({page,baseURL})=>{
	test.setTimeout(120_000);
	await page.goto(origin+'/index.html');
	const errors=[];
	page.on('pageerror',error=>errors.push(error.message));
	await page.evaluate(async({origin,baseURL})=>{
		const {Phonics}=await import(baseURL+'/engine/browser/Phonics.mjs');
		window.pokerReplayApp=await Phonics.run(origin+'/dist/game.phar',{
			canvas:document.querySelector('canvas'),
			runtime:{url:baseURL+'/runtime/php8.4-sdl.mjs',id:'poker-runtime-regression'},
			policy:{codeOrigins:[origin,baseURL]}
		});
	},{origin,baseURL});
	const canvas=page.locator('canvas');
	await expect(canvas).toHaveAttribute('data-poker-replay',/jackpots-2/);
	expect(await canvas.evaluate(element=>JSON.parse(element.dataset.pokerReplay))).toEqual(expected);
	await expect(canvas).toHaveAttribute('data-php-int-size','4');
	expect(errors).toEqual([]);
	await page.evaluate(()=>pokerReplayApp.dispose());
});
