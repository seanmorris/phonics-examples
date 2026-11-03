import {test, expect} from '@playwright/test';
import assert from 'node:assert/strict';
import {readFile, writeFile} from 'node:fs/promises';
import {PNG} from 'pngjs';

async function installGamepad(page)
{
	await page.addInitScript(()=>{
		window.inputPads=[];
		window.connectPad=()=>{
			const pad={
				id:'Standard Gamepad',index:0,connected:true,mapping:'standard',timestamp:performance.now()
				,axes:[0,0,0,0]
				,buttons:Array.from({length:17},()=>({pressed:false,touched:false,value:0}))
			};
			inputPads[0]=pad;
			dispatchEvent(Object.assign(new Event('gamepadconnected'),{gamepad:pad}));
		};
		window.changePad=({axes=[],buttons=[]}={})=>{
			const pad=inputPads[0];
			pad.axes=[0,0,0,0].map((_,index)=>axes[index]??0);
			pad.buttons=pad.buttons.map((_,index)=>({
				pressed:buttons.includes(index),touched:buttons.includes(index),value:buttons.includes(index)?1:0
			}));
			pad.timestamp=performance.now();
		};
		Object.defineProperty(navigator,'getGamepads',{value:()=>inputPads});
		connectPad();
	});
}

test('full page boots the direct application and executes a resettable shot', async ({page}) => {
	test.setTimeout(180_000);
	const errors = [];
	page.on('pageerror', error => errors.push(error.message));
	page.on('console', message => { if(message.type() === 'error') errors.push(message.text()); });
	await page.goto('/games/php-billiards/');
	const canvas = page.locator('canvas');
	await expect(canvas).toHaveAttribute('data-php-billiards', /"phase":"aim"/, {timeout: 45_000});
	const initial = await canvas.getAttribute('data-php-billiards');
	const initialState = JSON.parse(initial);
	expect(initialState.started).toBe(false);
	expect(initialState.status).toBe('Free Play');
	expect(initialState.balls.filter(ball => ball.active)).toHaveLength(16);
	expect(initialState.table).toEqual([2.54,1.27,.028575,.1158875,.1285875]);
	const sampling=await canvas.evaluate(element=>{const gl=element.getContext('webgl2');return {
		antialias:gl.getContextAttributes().antialias,samples:gl.getParameter(gl.SAMPLES)
	};});
	expect(sampling.antialias).toBe(true);
	expect(sampling.samples).toBeGreaterThan(0);
	const hostBox=await page.locator('.game-host').boundingBox(),canvasBox=await canvas.boundingBox();
	expect(Math.abs(hostBox.width-canvasBox.width)).toBeLessThan(1);
	expect(Math.abs(hostBox.height-canvasBox.height)).toBeLessThan(1);
	await expect(page.locator('.phonics-room-game')).toHaveCount(1);
	await expect(page.locator('.phonics-room-game')).toBeHidden();
	await canvas.focus();
	await page.keyboard.press('Space');
	await expect.poll(async() => JSON.parse(await canvas.getAttribute('data-php-billiards')).started).toBe(true);
	expect(JSON.parse(await canvas.getAttribute('data-php-billiards')).shots).toBe(0);
	await expect.poll(
		async() => JSON.parse(await canvas.getAttribute('data-php-billiards')).camera.transitioning
		, {timeout:45_000}
	).toBe(false);
	await canvas.hover();
	await page.mouse.wheel(0,-5000);
	await expect.poll(()=>canvas.evaluate(element=>element.phpBilliardsDebug.camera.distance)).toBe(.85);
	const frame=await canvas.boundingBox(),yawBefore=await canvas.evaluate(element=>element.phpBilliardsDebug.camera.yaw);
	// Left-drag on the cloth aims; right-drag orbits, with its direction fixed by where the drag starts.
	// Camera state is republished only when a paced frame renders, so poll rather than read once.
	const yaw=()=>canvas.evaluate(element=>element.phpBilliardsDebug.camera.yaw);
	await page.mouse.move(frame.x+frame.width*.4,frame.y+frame.height*.25);await page.mouse.down({button:'right'});
	await page.mouse.move(frame.x+frame.width*.45,frame.y+frame.height*.25);await page.mouse.up({button:'right'});
	await expect.poll(yaw).toBeLessThan(yawBefore);
	const yawAbove=await yaw();
	await page.mouse.move(frame.x+frame.width*.4,frame.y+frame.height*.75);await page.mouse.down({button:'right'});
	await page.mouse.move(frame.x+frame.width*.45,frame.y+frame.height*.75);await page.mouse.up({button:'right'});
	await expect.poll(yaw).toBeGreaterThan(yawAbove);
	await canvas.evaluate(element=>{
		const trace=[];
		const observer=new MutationObserver(()=>trace.push(JSON.parse(element.dataset.phpBilliards)));
		observer.observe(element,{attributes:true,attributeFilter:['data-php-billiards']});
		Object.assign(element.phpBilliardsDebug,{strokeTrace:trace,strokeObserver:observer});
	});
	await page.keyboard.press('Space');
	await expect.poll(async() => JSON.parse(await canvas.getAttribute('data-php-billiards')).shots).toBe(1);
	await expect.poll(async() => JSON.parse(await canvas.getAttribute('data-php-billiards')).stroke).toBeNull();
	const strokeTrace=await canvas.evaluate(element=>{
		element.phpBilliardsDebug.strokeObserver.disconnect();
		return element.phpBilliardsDebug.strokeTrace;
	});
	expect(strokeTrace.some(state=>state.shots===0&&state.stroke?.offset>.04)).toBe(true);
	expect(strokeTrace.some(state=>state.shots===1&&state.stroke?.contacted)).toBe(true);
	// PHP delivers the recorded samples; the key presses above unlock Web Audio and the break must be audible.
	await expect.poll(()=>canvas.evaluate(element=>element.phpBilliardsDebug.audio.decoded)).toBe(5);
	await expect.poll(()=>canvas.evaluate(element=>element.phpBilliardsDebug.audio.played)).toBeGreaterThan(0);
	expect(await canvas.evaluate(element=>element.phpBilliardsDebug.audio.state)).toBe('running');
	await page.keyboard.press('Backspace');
	await expect.poll(async() => JSON.parse(await canvas.getAttribute('data-php-billiards')).phase).toBe('aim');
	await canvas.evaluate(element=>element.phpBilliardsDebug.setControls({aim:131.5,power:.5}));
	await expect.poll(async()=>JSON.parse(await canvas.getAttribute('data-php-billiards')).aim)
		.toBeCloseTo(131.5*Math.PI/180,5);
	await canvas.evaluate(element=>element.phpBilliardsDebug.action('shoot'));
	await expect.poll(async() => JSON.parse(await canvas.getAttribute('data-php-billiards')).phase,{timeout:15_000}).toBe('placing');
	await expect(canvas).toHaveAttribute('data-placing','true');
	await canvas.evaluate(element=>element.phpBilliardsDebug.setCamera({target:[-.65,0,0]}));
	await expect.poll(()=>canvas.evaluate(element=>element.phpBilliardsDebug.camera?.target?.[0])).toBe(-.65);
	const placementFrame=await canvas.boundingBox();
	await page.mouse.click(placementFrame.x+placementFrame.width/2,placementFrame.y+placementFrame.height/2);
	await expect.poll(async() => JSON.parse(await canvas.getAttribute('data-php-billiards')).phase).toBe('aim');
	const placed=JSON.parse(await canvas.getAttribute('data-php-billiards')).balls.find(ball=>ball.number===0);
	expect(placed.active).toBe(true);
	expect(Math.hypot(placed.x+.65,placed.y)).toBeLessThan(.04);
	expect(errors).toEqual([]);
});

test('embed route contains only the game host and cleans up its runtime', async ({page}) => {
	await page.goto('/embed/php-billiards/');
	const canvas=page.locator('canvas');
	await expect(canvas).toHaveAttribute('data-php-billiards', /"balls"/, {timeout: 45_000});
	const idleUpdates=await canvas.evaluate(element=>new Promise(resolve=>{
		let updates=0;const observer=new MutationObserver(records=>updates+=records.length);
		observer.observe(element,{attributes:true,attributeFilter:['data-php-billiards']});
		setTimeout(()=>{observer.disconnect();resolve(updates);},500);
	}));
	expect(idleUpdates).toBe(0);
	await expect(page.locator('nav')).toHaveCount(0);
	await expect(page.locator('.phonics-room-game')).toHaveCount(1);
	await expect(page.locator('.phonics-room-game')).toBeHidden();
	await page.evaluate(() => dispatchEvent(new PageTransitionEvent('pagehide')));
	await expect(page.locator('canvas')).not.toHaveAttribute('data-php-billiards', /balls/);
});

test('joined-room canvas keeps its aspect ratio in a constrained viewport', async ({browser}) => {
	const page=await browser.newPage({viewport:{width:700,height:390}});
	await page.goto('/games/php-billiards/');
	const canvas=page.locator('canvas');
	await expect(canvas).toHaveAttribute('data-php-billiards', /"balls"/, {timeout:45_000});
	const originalBuffer=await canvas.evaluate(element=>[element.width,element.height]);
	await page.evaluate(()=>{
		const room=document.querySelector('.phonics-room-game');
		room.querySelector('.phonics-room-stage').append(document.querySelector('canvas'));
		room.classList.add('is-open');
	});
	await page.waitForTimeout(100);
	expect(await canvas.evaluate(element=>[element.width,element.height])).toEqual(originalBuffer);
	await page.locator('.phonics-room-game').evaluate(room=>room.classList.add('has-game'));
	await expect.poll(()=>canvas.evaluate(element=>[
		element.width,element.height
		, Math.round(element.getBoundingClientRect().width * devicePixelRatio)
		, Math.round(element.getBoundingClientRect().height * devicePixelRatio)
	])).toEqual(await canvas.evaluate(element=>{
		const frame=element.getBoundingClientRect();
		return [Math.round(frame.width*devicePixelRatio),Math.round(frame.height*devicePixelRatio)
			,Math.round(frame.width*devicePixelRatio),Math.round(frame.height*devicePixelRatio)];
	}));
	const stage=await page.locator('.phonics-room-stage').boundingBox();
	const frame=await canvas.boundingBox();
	expect(frame.width / frame.height).toBeCloseTo(16 / 9,2);
	expect(frame.width).toBeLessThanOrEqual(stage.width + 1);
	expect(frame.height).toBeLessThanOrEqual(stage.height + 1);
	await page.setViewportSize({width:500,height:340});
	await expect.poll(()=>canvas.evaluate(element=>element.getBoundingClientRect().width)).toBeLessThan(frame.width);
	const shrunken=await canvas.boundingBox();
	await page.setViewportSize({width:900,height:600});
	await expect.poll(()=>canvas.evaluate(element=>element.getBoundingClientRect().width)).toBeGreaterThan(shrunken.width*1.5);
	const grownStage=await page.locator('.phonics-room-stage').boundingBox();
	const grown=await canvas.boundingBox();
	expect(grown.width/grown.height).toBeCloseTo(16/9,2);
	expect(grown.width).toBeLessThanOrEqual(grownStage.width+1);
	expect(grown.height).toBeLessThanOrEqual(grownStage.height+1);
	const grownBuffer=[
		Math.round(grown.width*await page.evaluate(()=>devicePixelRatio))
		,Math.round(grown.height*await page.evaluate(()=>devicePixelRatio))
	];
	await expect.poll(()=>canvas.evaluate(element=>[element.width,element.height])).toEqual(grownBuffer);
	const freePlay=await canvas.evaluate(element=>{
		// Mirrors Hud::unit() and Hud::titleLayout() for the Free Play item centre.
		const width=element.width,height=element.height,dpr=Math.min(2,devicePixelRatio||1);
		const unit=dpr*Math.max(.8,Math.min(1.25,width / dpr / 1280,height / dpr / 720));
		const margin=16*unit,top=Math.max(margin,height * .05),limit=height * .32;
		const display=Math.max(46,Math.min(64,height * .11 / unit));
		const scale=Math.max(.6,Math.min(1,(limit - top) / ((display * 1.1 + 44) * unit + 56 * unit)));
		const buttonTop=top + display * scale * unit * 1.1 + 44 * unit * scale;
		const buttonHeight=Math.max(44,56 * scale) * unit,gap=12 * unit;
		const buttonWidth=Math.min(260 * unit,(width - 2 * margin - gap) / 2),x=(width - 2 * buttonWidth - gap) / 2;
		return [(x + buttonWidth / 2) / width,(buttonTop + buttonHeight / 2) / height];
	});
	await page.mouse.click(grown.x + grown.width * freePlay[0],grown.y + grown.height * freePlay[1]);
	await expect.poll(async()=>JSON.parse(await canvas.getAttribute('data-php-billiards')).started).toBe(true);
	await page.close();
});

test('a Matrix challenge link selects Online Play and opens the embedded lobby', async ({page}) => {
	await page.goto('/games/php-billiards/#phonics-game=linked-session');
	const canvas=page.locator('canvas');
	await expect(canvas).toHaveAttribute('data-php-billiards', /"onlineRequested":true/, {timeout:45_000});
	const state=JSON.parse(await canvas.getAttribute('data-php-billiards'));
	expect(state.started).toBe(false);
	expect(state.status).toBe('Online Play');
	await expect(page.locator('.phonics-room-game')).toBeVisible();
	await expect(page.locator('.phonics-room-game')).toHaveClass(/is-open/);
});

test('Continue with Matrix completes popup OAuth and reconnects the lobby', async ({page, context}) => {
	test.setTimeout(90_000);
	let sync = 0;

	await page.route('https://matrix.org/**', async route => {
		const url = new URL(route.request().url());

		if(url.pathname.endsWith('/auth_metadata'))
		return route.fulfill({json: {
			authorization_endpoint: 'https://auth.example/authorize'
			, registration_endpoint: 'https://auth.example/register'
			, token_endpoint: 'https://auth.example/token'
			, revocation_endpoint: 'https://auth.example/revoke'
			, code_challenge_methods_supported: ['S256']
		}});

		if(url.pathname.endsWith('/account/whoami'))
		return route.fulfill({json: {user_id: '@browser-test:matrix.org', device_id: 'BROWSER'}});

		if(url.pathname.includes('/join/'))
		return route.fulfill({json: {room_id: '!phonics-browser-test:matrix.org'}});

		if(url.pathname.endsWith('/messages')) return route.fulfill({json: {chunk: [], end: null}});

		if(url.pathname.endsWith('/sync'))
		return route.fulfill({json: {next_batch: `sync-${++sync}`, rooms: {join: {}}}});

		return route.fulfill({json: {event_id: '$event'}});
	});

	await context.route('https://auth.example/**', async route => {
		const url = new URL(route.request().url());

		if(url.pathname === '/register') return route.fulfill({status: 201, json: {client_id: 'billiards-test'}});

		if(url.pathname === '/token')
		return route.fulfill({json: {
			access_token: 'oauth-access'
			, refresh_token: 'oauth-refresh'
			, expires_in: 300
		}});

		if(url.pathname === '/authorize')
		{
			const callback = new URL(url.searchParams.get('redirect_uri'));
			callback.hash = new URLSearchParams({
				state: url.searchParams.get('state')
				, code: 'authorization-code'
			}).toString();

			return route.fulfill({status: 302, headers: {location: callback.href}});
		}

		return route.fulfill({json: {}});
	});

	await page.goto('/games/php-billiards/#phonics-game=oauth-test');
	await expect(page.locator('canvas')).toHaveAttribute(
		'data-php-billiards', /"onlineRequested":true/, {timeout: 45_000}
	);
	const original = page.url();
	const opened = page.waitForEvent('popup');
	await page.getByRole('button', {name: 'Continue with Matrix'}).click();
	const popup = await opened;

	await expect(page.locator('.phonics-room-status')).toHaveText('Connected', {timeout: 20_000});
	await expect(page.getByRole('button', {name: 'Continue with Matrix'})).toBeHidden();
	await expect.poll(() => popup.isClosed()).toBe(true);
	expect(page.url()).toBe(original);
});

test('an authenticated lobby remains responsive while Matrix returns immediate syncs', async ({page}) => {
	test.setTimeout(90_000);
	const requests = [];

	await page.addInitScript(() => localStorage.setItem(
		'phonics.matrix.session:https://matrix.org'
		, JSON.stringify({
			accessToken: 'browser-test-token'
			, userId: '@browser-test:matrix.org'
			, deviceId: 'BROWSER'
			, displayName: 'Browser Test'
			, authApi: 'legacy'
		})
	));

	await page.route('https://matrix.org/**', async route => {
		const request = route.request(), url = new URL(request.url());
		requests.push(url);

		if(url.pathname.endsWith('/account/whoami'))
		return route.fulfill({json: {user_id: '@browser-test:matrix.org', device_id: 'BROWSER'}});

		if(url.pathname.includes('/join/'))
		return route.fulfill({json: {room_id: '!phonics-browser-test:matrix.org'}});

		if(url.pathname.endsWith('/messages'))
		return route.fulfill({json: {chunk: Array.from({length: 100}, (_, index) => ({
			event_id: `$chat-${index}`
			, type: 'm.room.message'
			, sender: '@speaker:matrix.org'
			, origin_server_ts: index
			, content: {msgtype: 'm.text', body: `History ${index}`}
		})), end: null}});

		if(url.pathname.endsWith('/sync'))
		return route.fulfill({json: {next_batch: `sync-${requests.length}`, rooms: {join: {}}}});

		return route.fulfill({json: {event_id: `$event-${requests.length}`}});
	});

	await page.goto('/games/php-billiards/#phonics-game=browser-test');
	await expect(page.locator('canvas')).toHaveAttribute(
		'data-php-billiards', /"onlineRequested":true/, {timeout: 45_000}
	);
	await expect(page.locator('.phonics-room-status')).toHaveText('Connected');

	const frameAdvanced = await page.evaluate(() => new Promise(resolve => {
		const started = performance.now();
		requestAnimationFrame(() => requestAnimationFrame(() => resolve(performance.now() - started)));
	}));

	expect(frameAdvanced).toBeLessThan(1000);
	await expect.poll(
		() => requests.filter(url => url.pathname.endsWith('/sync')).length
		, {timeout: 10_000}
	).toBeGreaterThanOrEqual(2);
	const history = requests.filter(url => url.pathname.endsWith('/messages'));
	const syncs = requests.filter(url => url.pathname.endsWith('/sync'));
	const related = requests.filter(url => url.pathname.includes('/relations/') || url.pathname.includes('/event/'));
	expect(history).toHaveLength(1);
	expect(JSON.parse(history[0].searchParams.get('filter')).types).toEqual([
		'm.room.message', 'org.seanmorris.phonics.game.v1', 'org.seanmorris.phonics.chat.v1'
	]);
	expect(syncs.length).toBeGreaterThanOrEqual(2);
	expect(syncs.length).toBeLessThanOrEqual(6);
	expect(related).toHaveLength(0);
});

test('SDL gamepad uses left-stick controls, right-stick camera, and face buttons', async ({page}) => {
	test.setTimeout(180_000);
	await installGamepad(page);
	const errors=[];
	page.on('pageerror',error=>errors.push(error.message));
	page.on('console',message=>{if(message.type()==='error')errors.push(message.text());});
	await page.goto('/games/php-billiards/');
	const canvas=page.locator('canvas');
	await expect(canvas).toHaveAttribute('data-php-billiards', /"phase":"aim"/, {timeout:45_000});
	await canvas.focus();
	let before=JSON.parse(await canvas.getAttribute('data-php-billiards'));
	expect(before.started).toBe(false);
	await page.evaluate(()=>changePad({buttons:[0]}));
	await expect.poll(async()=>JSON.parse(await canvas.getAttribute('data-php-billiards')).started).toBe(true);
	expect(JSON.parse(await canvas.getAttribute('data-php-billiards')).shots).toBe(0);
	await page.evaluate(()=>changePad());
	await page.waitForTimeout(50);
	await expect.poll(
		async()=>JSON.parse(await canvas.getAttribute('data-php-billiards')).camera.transitioning
		, {timeout:45_000}
	).toBe(false);
	before=JSON.parse(await canvas.getAttribute('data-php-billiards'));
	await page.evaluate(()=>changePad({axes:[.75,-.7,.8,-.55]}));
	await expect.poll(async()=>JSON.parse(await canvas.getAttribute('data-php-billiards')).camera.yaw).toBeGreaterThan(before.camera.yaw+.2);
	const moved=JSON.parse(await canvas.getAttribute('data-php-billiards'));
	expect(moved.camera.pitch).toBeLessThan(before.camera.pitch);
	expect(moved.aim).toBeGreaterThan(before.aim);
	expect(moved.power).toBeGreaterThan(before.power);
	expect(moved.inputTheme).toBe('generic');
	await page.evaluate(()=>changePad({buttons:[5,7]}));
	await expect.poll(async()=>JSON.parse(await canvas.getAttribute('data-php-billiards')).top).toBeGreaterThan(.03);
	await expect.poll(async()=>JSON.parse(await canvas.getAttribute('data-php-billiards')).side).toBeGreaterThan(.03);
	await page.evaluate(()=>changePad({buttons:[2]}));
	await expect.poll(async()=>JSON.parse(await canvas.getAttribute('data-php-billiards')).top).toBe(0);
	await expect.poll(async()=>JSON.parse(await canvas.getAttribute('data-php-billiards')).side).toBe(0);
	await page.evaluate(()=>changePad());
	await page.waitForTimeout(50);
	await page.evaluate(()=>changePad({buttons:[0]}));
	await expect.poll(async()=>JSON.parse(await canvas.getAttribute('data-php-billiards')).shots).toBe(1);
	await page.evaluate(()=>changePad());
	await page.waitForTimeout(50);
	await page.evaluate(()=>changePad({buttons:[1]}));
	await expect.poll(async()=>JSON.parse(await canvas.getAttribute('data-php-billiards')).phase).toBe('aim');
	await page.evaluate(()=>changePad());
	expect(errors).toEqual([]);
});

test('a stationary ball texture landmark follows its fixed world position while the camera orbits', async ({page}, testInfo) => {
	test.setTimeout(180_000);
	await page.goto('/games/php-billiards/');
	const canvas=page.locator('canvas');
	await expect(canvas).toHaveAttribute('data-php-billiards', /"phase":"aim"/, {timeout:45_000});
	await canvas.evaluate(element=>element.phpBilliardsDebug.action('start'));
	await expect.poll(async()=>JSON.parse(await canvas.getAttribute('data-php-billiards')).started).toBe(true);
	const rotations=async()=>JSON.parse(await canvas.getAttribute('data-php-billiards')).balls.map(ball=>ball.rotation);
	const before=await rotations();
	await canvas.evaluate(element=>{
		// Lobster's seven has a strong asymmetric moment, making an atlas reversal obvious.
		const state=element.phpBilliardsDebug.state,ball=state.balls.find(candidate=>candidate.number===7),radius=state.table[2];
		const [x,y,z,w]=ball.rotation;
		// The atlas spot at source UV (.25,.5) maps to the sphere's local +Z pole.
		const normal=[2*(x*z+w*y),2*(y*z-w*x),1-2*(x*x+y*y)];
		element.phpBilliardsDebug.setCamera({
			target:[ball.x,radius+.012,ball.y]
			, distance:.22
			, yaw:Math.atan2(normal[2],normal[0])
			, pitch:Math.asin(normal[1])
		});
	});
	await expect.poll(
		()=>canvas.evaluate(element=>element.phpBilliardsDebug.camera?.distance)
		, {timeout:45_000}
	).toBe(.22);
	await page.waitForTimeout(350);
	const firstPath=testInfo.outputPath('ball-landmark-head-on.png');
	const first=await canvas.screenshot({path:firstPath});
	await canvas.evaluate(element=>{
		const {yaw}=element.phpBilliardsDebug.camera;element.phpBilliardsDebug.setCamera({yaw:yaw+.28});
	});
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
			// The number spot is aged ivory: light and warm but barely saturated, unlike the maroon ball, the dark numeral,
			// or a pure-white specular highlight.
			if(red>60&&Math.abs(red-green)<25&&red-blue>8&&red-blue<70)
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
	const sourceNumeral=darkPixels(atlasImage,[1664,384],30,50);
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
	await page.locator('canvas').evaluate(canvas => {
		const extension = canvas.getContext('webgl2').getExtension('WEBGL_lose_context');
		extension.loseContext();
		setTimeout(() => extension.restoreContext(), 100);
	});
	await expect(page.locator('canvas')).toHaveAttribute('data-php-billiards', /"phase":"aim"/, {timeout: 45_000});
	await expect(page.locator('.php-billiards-ui')).toHaveCount(0);
});

test('an energetic off-centre side-pocket shot rattles back onto the table', async ({page}) => {
	await page.goto('/games/php-billiards/');
	const canvas = page.locator('canvas');
	await expect(canvas).toHaveAttribute('data-php-billiards', /"phase":"aim"/, {timeout: 45_000});
	await canvas.evaluate(element=>element.phpBilliardsDebug.action('start'));
	await expect.poll(async()=>JSON.parse(await canvas.getAttribute('data-php-billiards')).started).toBe(true);
	await canvas.evaluate(element=>element.phpBilliardsDebug.setControls({aim:-42,power:1}));
	await canvas.evaluate(element=>{
		const trace=[];
		const record=()=>trace.push(JSON.parse(element.dataset.phpBilliards));
		const observer=new MutationObserver(record);
		observer.observe(element,{attributes:true,attributeFilter:['data-php-billiards']});
		Object.assign(element.phpBilliardsDebug,{pocketTrace:trace,pocketObserver:observer});
	});
	await canvas.evaluate(element=>element.phpBilliardsDebug.action('shoot'));
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
