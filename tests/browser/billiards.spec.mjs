import {test, expect} from '@playwright/test';
import assert from 'node:assert/strict';
import {readFile, writeFile} from 'node:fs/promises';
import {PNG} from 'pngjs';

test('full page boots the direct application and executes a resettable shot', async ({page}) => {
	test.setTimeout(90_000);
	const errors = [];
	page.on('pageerror', error => errors.push(error.message));
	page.on('console', message => { if(message.type() === 'error') errors.push(message.text()); });
	await page.goto('/games/php-billiards/');
	const canvas = page.locator('canvas');
	await expect(canvas).toHaveAttribute('data-php-billiards', /"phase":"aim"/, {timeout: 45_000});
	const initial = await canvas.getAttribute('data-php-billiards');
	const initialState = JSON.parse(initial);
	expect(initialState.balls.filter(ball => ball.active)).toHaveLength(16);
	expect(initialState.table).toEqual([2.54,1.27,.028575,.1158875,.1285875]);
	const hostBox=await page.locator('.game-host').boundingBox(),uiBox=await page.locator('.php-billiards-ui').boundingBox();
	expect(Math.abs(hostBox.width-uiBox.width)).toBeLessThan(1);
	expect(Math.abs(hostBox.height-uiBox.height)).toBeLessThan(1);
	await canvas.hover();
	await page.mouse.wheel(0,-5000);
	await expect.poll(()=>canvas.evaluate(element=>element.phpBilliardsDebug.camera.distance)).toBe(.85);
	const frame=await canvas.boundingBox(),yawBefore=await canvas.evaluate(element=>element.phpBilliardsDebug.camera.yaw);
	await page.mouse.move(frame.x+frame.width*.4,frame.y+frame.height*.25);await page.mouse.down();
	await page.mouse.move(frame.x+frame.width*.45,frame.y+frame.height*.25);await page.mouse.up();
	const yawAbove=await canvas.evaluate(element=>element.phpBilliardsDebug.camera.yaw);
	await page.mouse.move(frame.x+frame.width*.4,frame.y+frame.height*.75);await page.mouse.down();
	await page.mouse.move(frame.x+frame.width*.45,frame.y+frame.height*.75);await page.mouse.up();
	const yawBelow=await canvas.evaluate(element=>element.phpBilliardsDebug.camera.yaw);
	expect(yawAbove).toBeLessThan(yawBefore);
	expect(yawBelow).toBeGreaterThan(yawAbove);
	await page.getByRole('button', {name: 'SHOOT'}).click();
	await expect.poll(async() => JSON.parse(await canvas.getAttribute('data-php-billiards')).shots).toBe(1);
	await page.getByRole('button', {name: 'RESET SHOT'}).click();
	await expect.poll(async() => JSON.parse(await canvas.getAttribute('data-php-billiards')).phase).toBe('aim');
	expect(errors).toEqual([]);
});

test('embed route contains only the game host and cleans up its runtime', async ({page}) => {
	await page.goto('/embed/php-billiards/');
	await expect(page.locator('canvas')).toHaveAttribute('data-php-billiards', /"balls"/, {timeout: 45_000});
	await expect(page.locator('nav')).toHaveCount(0);
	await expect(page.locator('.php-billiards-ui')).toHaveCount(1);
	await page.evaluate(() => dispatchEvent(new PageTransitionEvent('pagehide')));
	await expect(page.locator('.php-billiards-ui')).toHaveCount(0);
});

test('a stationary ball texture landmark follows its fixed world position while the camera orbits', async ({page}, testInfo) => {
	await page.goto('/games/php-billiards/');
	const canvas=page.locator('canvas');
	await expect(canvas).toHaveAttribute('data-php-billiards', /"phase":"aim"/, {timeout:45_000});
	const rotations=async()=>JSON.parse(await canvas.getAttribute('data-php-billiards')).balls.map(ball=>ball.rotation);
	const before=await rotations();
	await canvas.evaluate(element=>{
		// Five is asymmetric, so the saved frames also make a horizontal atlas reversal obvious.
		const state=element.phpBilliardsDebug.state,ball=state.balls.find(candidate=>candidate.number===5),radius=state.table[2];
		const [x,y,z,w]=ball.rotation;
		// The atlas spot at source UV (.25,.5) maps to the sphere's local +Z pole.
		const normal=[2*(x*z+w*y),2*(y*z-w*x),1-2*(x*x+y*y)];
		const camera=element.phpBilliardsDebug.camera;
		camera.target=[ball.x,radius+.012,ball.y];
		camera.distance=.22;
		camera.yaw=Math.atan2(normal[2],normal[0]);
		camera.pitch=Math.asin(normal[1]);
	});
	await page.waitForTimeout(350);
	const firstPath=testInfo.outputPath('ball-landmark-head-on.png');
	const first=await canvas.screenshot({path:firstPath});
	await canvas.evaluate(element=>element.phpBilliardsDebug.camera.yaw+=.28);
	await page.waitForTimeout(350);
	const secondPath=testInfo.outputPath('ball-landmark-after-orbit.png');
	const second=await canvas.screenshot({path:secondPath});
	await testInfo.attach('ball-landmark-head-on.png',{body:first,contentType:'image/png'});
	await testInfo.attach('ball-landmark-after-orbit.png',{body:second,contentType:'image/png'});

	const whiteSpot=buffer=>{
		const image=PNG.sync.read(buffer),center=[image.width/2,image.height/2],limit=Math.min(image.width,image.height)*.14;
		let count=0,sumX=0,sumY=0;
		for(let y=0;y<image.height;y++)for(let x=0;x<image.width;x++)
		{
			if(Math.hypot(x-center[0],y-center[1])>limit)continue;
			const index=(y*image.width+x)*4,[red,green,blue]=image.data.subarray(index,index+3);
			if(Math.min(red,green,blue)>30&&Math.max(red,green,blue)-Math.min(red,green,blue)<20)
			{count++;sumX+=x;sumY+=y;}
		}
		return {count,x:sumX/count,y:sumY/count,width:image.width,height:image.height};
	};
	const reflectionMoment=points=>{
		const center=points.reduce((sum,[x,y])=>[sum[0]+x,sum[1]+y],[0,0]).map(value=>value/points.length);
		const moment=(p,q)=>points.reduce((sum,[x,y])=>sum+(x-center[0])**p*(y-center[1])**q,0);
		const normalized=(p,q)=>moment(p,q)/points.length**(1+(p+q)/2);
		const e30=normalized(3,0),e12=normalized(1,2),e21=normalized(2,1),e03=normalized(0,3);
		return (3*e21-e03)*(e30+e12)*((e30+e12)**2-3*(e21+e03)**2)
			-(e30-3*e12)*(e21+e03)*(3*(e30+e12)**2-(e21+e03)**2);
	};
	const darkPixels=(image,center,radius,threshold)=>{
		const points=[];
		for(let y=Math.floor(center[1]-radius);y<=center[1]+radius;y++)for(let x=Math.floor(center[0]-radius);x<=center[0]+radius;x++)
		{
			if(Math.hypot(x-center[0],y-center[1])>radius)continue;
			const index=(y*image.width+x)*4;
			if(Math.max(...image.data.subarray(index,index+3))<threshold)points.push([x,y]);
		}
		return points;
	};
	const headOn=whiteSpot(first),orbited=whiteSpot(second),frameImage=PNG.sync.read(first);
	const atlasImage=PNG.sync.read(await readFile(new URL('../../games/php-billiards/assets/generated/ball-atlas.png',import.meta.url)));
	const frameNumeral=darkPixels(frameImage,[headOn.x,headOn.y],45,50);
	const sourceNumeral=darkPixels(atlasImage,[640,384],30,50);
	const frameReflection=reflectionMoment(frameNumeral),sourceReflection=reflectionMoment(sourceNumeral);
	const measurements=Buffer.from(JSON.stringify({headOn,orbited,frameReflection,sourceReflection},null,2));
	await writeFile(testInfo.outputPath('ball-landmark-measurements.json'),measurements);
	await testInfo.attach('ball-landmark-measurements.json',{body:measurements,contentType:'application/json'});
	expect(headOn.count).toBeGreaterThan(100);
	expect(orbited.count).toBeGreaterThan(100);
	expect(Math.abs(headOn.x-headOn.width/2)).toBeLessThan(18);
	expect(Math.abs(headOn.y-headOn.height/2)).toBeLessThan(18);
	expect(frameNumeral.length).toBeGreaterThan(100);
	expect(Math.sign(frameReflection)).toBe(Math.sign(sourceReflection));
	// A fixed point initially facing the camera moves right on screen after this positive yaw orbit.
	expect(orbited.x-headOn.x).toBeGreaterThan(8);
	expect(Math.abs(orbited.y-headOn.y)).toBeLessThan(14);
	assert.deepEqual(await rotations(),before);
});

test('a restored WebGL context restarts the direct application cleanly', async ({page}) => {
	await page.goto('/games/php-billiards/');
	await expect(page.locator('canvas')).toHaveAttribute('data-php-billiards', /"phase":"aim"/, {timeout: 45_000});
	const loaded = page.waitForEvent('load');
	await page.locator('canvas').evaluate(canvas => {
		const extension = canvas.getContext('webgl2').getExtension('WEBGL_lose_context');
		extension.loseContext();
		setTimeout(() => extension.restoreContext(), 100);
	});
	await loaded;
	await expect(page.locator('canvas')).toHaveAttribute('data-php-billiards', /"phase":"aim"/, {timeout: 45_000});
	await expect(page.locator('.php-billiards-ui')).toHaveCount(1);
});

test('an energetic off-centre side-pocket shot rattles back onto the table', async ({page}) => {
	await page.goto('/games/php-billiards/');
	const canvas = page.locator('canvas');
	await expect(canvas).toHaveAttribute('data-php-billiards', /"phase":"aim"/, {timeout: 45_000});
	await page.locator('input[name="aim"]').fill('-42');
	await page.locator('input[name="power"]').fill('1');
	await canvas.evaluate(element=>{
		const trace=[];
		const record=()=>trace.push(JSON.parse(element.dataset.phpBilliards));
		const observer=new MutationObserver(record);
		observer.observe(element,{attributes:true,attributeFilter:['data-php-billiards']});
		Object.assign(element.phpBilliardsDebug,{pocketTrace:trace,pocketObserver:observer});
	});
	await page.getByRole('button', {name: 'SHOOT'}).click();
	await expect.poll(async() => {
		return canvas.evaluate(element=>{
			const trace=element.phpBilliardsDebug.pocketTrace;
			const entered=trace.some(state=>state.events?.some(event=>event.ball===0&&event.type==='pocket-enter'
				&&[1,4].includes(event.pocket)));
			const rejected=trace.some(state=>state.events?.some(event=>event.ball===0&&event.type==='pocket-reject'));
			const cue=trace.at(-1)?.balls.find(ball=>ball.number===0);
			return entered&&rejected&&cue?.active&&cue.pocket===null&&cue.drop===0
				&&Math.hypot(cue.x+.635,cue.y)>.25;
		});
	},{timeout:30_000}).toBe(true);
	const state = JSON.parse(await canvas.getAttribute('data-php-billiards'));
	const cue = state.balls.find(ball => ball.number === 0);
	const [width,height,radius]=state.table;
	expect(Math.abs(cue.x)).toBeLessThanOrEqual(width/2-radius+1e-6);
	expect(Math.abs(cue.y)).toBeLessThanOrEqual(height/2-radius+1e-6);
	expect(cue.drop).toBe(0);
});
