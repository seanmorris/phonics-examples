import {test, expect} from '@playwright/test';

test('opening controls preserve the transition and pointer follow returns to idle', async ({page}, testInfo) => {
	test.setTimeout(180_000);
	await page.setViewportSize({width:800,height:450});
	await page.goto('/embed/php-billiards/');
	const canvas=page.locator('canvas');
	await expect(canvas).toHaveAttribute('data-php-billiards', /"phase":"aim"/, {timeout:45_000});
	await expect(page.locator('.loading')).toHaveClass(/complete/);
	const state=()=>canvas.evaluate(element=>element.phpBilliardsDebug.state);
	await canvas.focus(); await page.keyboard.press('Space');
	await expect.poll(async()=>(await state()).started).toBe(true);
	expect((await state()).camera.transitioning).toBe(true);
	await page.mouse.move(320,330); await page.mouse.wheel(0,-5000);
	await page.mouse.down({button:'right'});
	await expect.poll(async()=>(await state()).ui.drag).toBe('orbit');
	await page.mouse.move(350,330,{steps:3});
	await page.mouse.up({button:'right'});
	await expect.poll(async()=>(await state()).ui.drag).toBeNull();
	await expect.poll(async()=>(await state()).camera.transitioning,{timeout:60_000}).toBe(false);
	const close=(await state()).camera;
	expect(close.distance).toBe(.85); expect(close.pitch).toBe(.72);
	expect(Math.abs(close.yaw+.42)).toBeGreaterThan(.05);
	await testInfo.attach('close-orbit.png',{body:await canvas.screenshot(),contentType:'image/png'});
	await page.keyboard.down('ArrowRight'); await page.waitForTimeout(100); await page.keyboard.up('ArrowRight');
	await page.mouse.wheel(0,100);
	await expect.poll(async()=>(await state()).camera.distance).toBeGreaterThan(.85);
	expect((await state()).camera.yaw).toBe(close.yaw);
	await canvas.evaluate(element=>element.phpBilliardsDebug.action('camera-reset'));
	await expect.poll(async()=>(await state()).camera.distance).toBe(3.35);
	await page.keyboard.down('ArrowRight'); await page.waitForTimeout(100); await page.keyboard.up('ArrowRight');
	await page.mouse.move(400,225); await page.mouse.down();
	await expect.poll(async()=>(await state()).ui.drag).toBe('aim');
	const held=(await state()).camera;
	await page.waitForTimeout(500);
	expect((await state()).camera).toEqual(held);
	await page.mouse.up();
	await expect.poll(async()=>{
		// Settled means no published update for longer than a slow frame; easing can take several frames to finish.
		const quiet=await canvas.evaluate(element=>new Promise(resolve=>{
			const observer=new MutationObserver(()=>{observer.disconnect();resolve(false);});
			observer.observe(element,{attributes:true,attributeFilter:['data-php-billiards']});
			setTimeout(()=>{observer.disconnect();resolve(true);},1500);
		}));
		const s=await state(),cue=s.balls.find(ball=>ball.number===0),[x,,z]=s.camera.target;
		// Behind the cue, the view frames the table at a point on the line from the cue ball to the table centre.
		return quiet && Math.abs(Math.atan2(Math.sin(s.camera.yaw-s.aim-Math.PI),Math.cos(s.camera.yaw-s.aim-Math.PI)))<1e-8
			&& Math.abs(x*cue.y-z*cue.x)<1e-9 && x/cue.x>=-1e-9 && x/cue.x<=1+1e-9;
	},{timeout:60_000}).toBe(true);
	const updates=await canvas.evaluate(element=>new Promise(resolve=>{
		let count=0;const observer=new MutationObserver(records=>count+=records.length);
		observer.observe(element,{attributes:true,attributeFilter:['data-php-billiards']});
		setTimeout(()=>{observer.disconnect();resolve(count);},700);
	}));
	expect(updates).toBe(0);
});
