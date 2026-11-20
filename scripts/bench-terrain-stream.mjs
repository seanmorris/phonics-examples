import {chromium} from '@playwright/test';

const frames = Number(process.env.TERRAIN_BENCH_FRAMES || 90);
const quality = Number(process.env.TERRAIN_QUALITY || 1);
if(!Number.isInteger(frames) || frames < 2) throw new Error('TERRAIN_BENCH_FRAMES must be an integer above 1.');
const browser = await chromium.launch({executablePath:process.env.CHROMIUM || undefined,
	args:process.env.SOFTWARE_GL === '1' ? ['--use-angle=swiftshader','--enable-unsafe-swiftshader'] : []});
try {
	const page = await browser.newPage({viewport:{width:640,height:400}});
	await page.addInitScript(() => {
		const counts = window.terrainTransfers = {bytes:0, draws:0};
		const gl = WebGL2RenderingContext.prototype;
		for (const name of ['drawElements','drawArrays','drawArraysInstanced','bufferData','bufferSubData']) {
			const original = gl[name];
			gl[name] = function(...args) {
				if (name.startsWith('draw')) counts.draws++;
				else if (args[0] === this.ARRAY_BUFFER) {
					const data = args[name === 'bufferData' ? 1 : 2];
					counts.bytes += typeof data === 'number' ? data : (args[4] || data?.length || 0) * (data?.BYTES_PER_ELEMENT || 1);
				}
				return original.apply(this,args);
			};
		}
	});
	await page.goto(process.env.TERRAIN_URL || 'http://localhost:4173/games/terrain-sandbox/');
	const canvas = page.locator('canvas[data-terrain-sandbox]');
	await canvas.waitFor({timeout:120000});
	for (const [preset,version] of [['coast',1],['mountain',1],['canyon',1],['canyon',2]]) {
		const seed = 'stream-' + preset + version;
		const recipe = {seed,preset,version,heightScale:{mountain:1400,canyon:650,coast:420}[preset],
			baseFrequency:1/({mountain:2200,canyon:4000,coast:6000}[preset]),terraceSpacing:preset==='canyon'?80:0};
		const doc = {schema:'phonics-landscape-sandbox',version:1,landscape:{format:'terrain-phonics',version:1,
			recipe,history:[],cursor:0,revision:0},settings:{quality,speed:300},camera:{position:[0,750,0],yaw:.25,pitch:-.25}};
		const loadStarted = performance.now();
		const beforeLoad = await page.evaluate(() => ({...window.terrainTransfers}));
		await page.locator('.terrain-sandbox [data-file]').setInputFiles({
			name: 'landscape.json', mimeType: 'application/json', buffer: Buffer.from(JSON.stringify(doc))
		});
		await page.waitForFunction(seed => {
			const state = document.querySelector('canvas[data-terrain-sandbox]')?.terrainSandboxDebug?.state;
			return state?.recipe?.seed === seed && state.renderer.visible > 0;
		}, seed, {timeout: 120000});
		console.log(JSON.stringify({preset, version, quality, kind: 'first coverage',
			milliseconds: performance.now() - loadStarted,
			vertexBytes: await page.evaluate(start => window.terrainTransfers.bytes - start, beforeLoad.bytes)}));
		for (const moving of [false,true]) {
			const report = await canvas.evaluate(async(c,{moving,frames})=>{
				const api=c.terrainSandboxDebug, start={...window.terrainTransfers};
				api.keys(moving ? {KeyW:true} : {});
				const samples=[];
				let last=performance.now();
				for(let i=0;i<frames;i++) {
					const t=await new Promise(requestAnimationFrame);
					if(i) samples.push(t-last);
					last=t;
				}
				api.keys({});
				samples.sort((a,b)=>a-b);
				return {levels:api.state.renderer.visible, meshBytes:api.state.renderer.meshBytes,
					terrainTextureBytes:api.state.renderer.terrainTextureBytes, p95:samples[Math.ceil(samples.length*.95)-1],p99:samples[Math.ceil(samples.length*.99)-1],
					vertexBytes:window.terrainTransfers.bytes-start.bytes,draws:window.terrainTransfers.draws-start.draws,
					submissionMs:api.state.renderMs, graphics:api.graphics().renderer};
			},{moving,frames});
			console.log(JSON.stringify({preset,version,quality,moving,...report}));
		}
	}
} finally {await browser.close();}
