// Run against a served examples build. Set CHROMIUM to select a browser and SOFTWARE_GL=1 for SwiftShader.
import {chromium} from '@playwright/test';

const browser = await chromium.launch({
	executablePath: process.env.CHROMIUM || undefined,
	headless: !process.env.HEADED,
	args: process.env.SOFTWARE_GL === '1' ? ['--use-angle=swiftshader','--enable-unsafe-swiftshader'] : []
});

try {
	const page = await browser.newPage({viewport: {
		width: Number(process.env.VIEW_WIDTH || 640), height: Number(process.env.VIEW_HEIGHT || 399)
	}});
	page.setDefaultTimeout(90_000);
	await page.goto(process.env.TERRAIN_URL || 'http://localhost:4173/games/terrain-sandbox/');
	const canvas = page.locator('canvas[data-terrain-sandbox]');
	await canvas.waitFor();
	await canvas.evaluate(c => {
		c.terrainSandboxDebug.action('pause');
		c.terrainSandboxDebug.values({tool:'road'});
	});
	await page.waitForFunction(() => {
		const s = document.querySelector('canvas').terrainSandboxDebug.state;
		return s.paused && s.settings.tool === 'road';
	});
	const result = await canvas.evaluate(async c => {
		const debug = c.terrainSandboxDebug;
		const points = [];
		for(const pass of ['cold','warm']) {
			for(const [name,y] of [['near',.8],['center',.5],['shallow',.3]]) {
				const count = debug.state.roadPoints.length;
				const start = performance.now();
				const value = await new Promise((resolve,reject) => {
					let pendingMs;
					const observer = new MutationObserver(() => {
						const state = debug.state;
						if(state.picking) pendingMs ??= performance.now() - start;
						if(state.roadPoints.length === count) return;
						observer.disconnect();
						clearTimeout(timeout);
						resolve({pass,name,pendingMs,confirmedMs:performance.now()-start,
							position:state.roadPoints.at(-1),queryMs:state.lastPick?.elapsedMs});
					});
					const timeout = setTimeout(() => {
						observer.disconnect();
						reject(new Error('Timed out awaiting confirmed road point'));
					},90_000);
					observer.observe(c,{attributes:true,attributeFilter:['data-terrain-sandbox']});
					debug.click(.5,y);
				});
				points.push(value);
			}
		}
		debug.action('pause');
		debug.keys({KeyW:true});
		let frames;
		try {
			frames = await new Promise(resolve => {
				const intervals = [];
				const start = performance.now();
				let last;
				const frame = time => {
					if(last !== undefined) intervals.push(time-last);
					last = time;
					if(time-start < 3000 || intervals.length < 2) return requestAnimationFrame(frame);
					intervals.sort((a,b) => a-b);
					const quantile = p => intervals[Math.ceil(intervals.length*p)-1];
					resolve({count:intervals.length,p50Ms:quantile(.5),p95Ms:quantile(.95)});
				};
				requestAnimationFrame(frame);
			});
		} finally { debug.keys({}); }
		return {viewport:debug.state.viewport,graphics:debug.graphics(),points,flightFrameIntervals:frames};
	});
	console.log(JSON.stringify(result,null,2));
} finally { await browser.close(); }
