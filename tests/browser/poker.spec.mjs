import {test, expect} from '@playwright/test';
import {execFileSync} from 'node:child_process';
import {fileURLToPath} from 'node:url';

const root=fileURLToPath(new URL('../../',import.meta.url));
const saveKey='php-poker.local.v1';

// A real native rules session reaches a human draw without patching its state. The browser
// must restore and continue the same versioned package snapshot through its normal save path.
const drawSave=execFileSync('php',['-r',`
require $argv[1].'/games/php-poker/autoload.php';
require $argv[1].'/node_modules/@seanmorris/phonics/autoload.php';
$s=new PhpPoker\\Session;
$s->command(['type'=>'start','players'=>2,'variant'=>'anything'],'browser-fixture-1');
$s->command(['type'=>'pace','value'=>'fast']);
for($i=0;$i<200;$i++) {
 $v=$s->view();
 if($v['phase']==='draw' && $v['turn']===0) { echo $s->save(); exit; }
 if($v['turn']<0) throw new RuntimeException('Fixture ended before drawing');
 if($v['turn']>0) { $s->tick(.1); continue; }
 $l=$v['legalActions'];
 $s->command(['type'=>$l['call']?'call':($l['bet']?'bet':($l['check']?'check':'pass')),
  'amount'=>$l['minBet'],'generation'=>$v['generation'],'revision'=>$v['revision']]);
}
throw new RuntimeException('Fixture did not reach a draw');
`,root],{encoding:'utf8'});

async function boot(page)
{
	await page.goto('/games/php-poker/');
	const canvas=page.locator('canvas');
	await expect(canvas).toHaveAttribute('data-php-poker',/"screen":"menu"/,{timeout:45_000});
	return canvas;
}

const state=canvas=>canvas.evaluate(element=>JSON.parse(element.dataset.phpPoker));
const command=(canvas,intent)=>canvas.evaluate((element,intent)=>element.phpPokerDebug.command(intent),intent);

test('native saved draw continues through keyboard input, rejects stale actions and settles idle',async({page})=>{
	test.setTimeout(120_000);
	const errors=[];
	page.on('pageerror',error=>errors.push(error.message));
	await page.addInitScript(()=>{
		window.pokerDrawCalls=0;
		const draw=WebGL2RenderingContext.prototype.drawArraysInstanced;
		WebGL2RenderingContext.prototype.drawArraysInstanced=function(...args){window.pokerDrawCalls++;return draw.apply(this,args);};
	});
	await page.addInitScript(({key,save})=>{
		if(!localStorage.getItem(key)) localStorage.setItem(key,save);
	},{key:saveKey,save:drawSave});
	const canvas=await boot(page);
	expect((await state(canvas)).canContinue).toBe(true);
	await command(canvas,{type:'resume'});
	await expect.poll(async()=>(await state(canvas)).screen).toBe('table');
	const before=await state(canvas);
	expect(before.phase).toBe('draw');
	expect(before.players[1].cards).toEqual([null,null,null,null,null]);
	expect(before.players[0].cards).toEqual([15,5,18,22,51]);
	expect(before).not.toHaveProperty('originalOpenerCards');
	expect(before).not.toHaveProperty('piles');
	const handDescription=page.locator('#'+await canvas.getAttribute('aria-describedby'));
	await expect(handDescription).toHaveText('Your hand: 1: four of diamonds; 2: seven of clubs; 3: seven of diamonds; 4: jack of diamonds; 5: ace of spades.');
	await command(canvas,{type:'draw',cards:[],revision:before.revision-1});
	await expect.poll(async()=>(await state(canvas)).error).not.toBe('');
	expect((await state(canvas)).revision).toBe(before.revision);
	await canvas.focus();
	await page.keyboard.down('1');
	await expect.poll(async()=>(await state(canvas)).ui.discards).toEqual([before.players[0].cards[0]]);
	await page.keyboard.down('1');
	await page.keyboard.up('1');
	await page.waitForTimeout(100);
	expect((await state(canvas)).ui.discards).toEqual([before.players[0].cards[0]]);
	// Selecting a card is reversible presentation state until the distinct Draw action.
	expect((await state(canvas)).revision).toBe(before.revision);
	await page.keyboard.press('d');
	await expect.poll(async()=>(await state(canvas)).revision).toBeGreaterThan(before.revision);
	await expect.poll(async()=>(await state(canvas)).players[0].drawCount).toBe(1);
	const after=await state(canvas);
	expect(after.players[0].cards).not.toContain(before.players[0].cards[0]);
	expect(after.players[0].cards.filter(card=>before.players[0].cards.includes(card))).toHaveLength(4);

	for(let i=0;i<30;i++)
	{
		await expect.poll(async()=>{
			const s=await state(canvas);
			return s.turn===0 || s.turn===-1;
		}).toBe(true);
		const s=await state(canvas);
		if(s.turn===-1) break;
		const type=s.legalActions.call?'call':s.legalActions.check?'check':s.legalActions.pass?'pass':'fold';
		await command(canvas,{type});
		await expect.poll(async()=>(await state(canvas)).revision).toBeGreaterThan(s.revision);
	}
	await expect.poll(async()=>(await state(canvas)).phase).toBe('showdown');
	const settled=await state(canvas);
	expect(settled.awards.length).toBeGreaterThan(0);
	expect(settled.players.reduce((sum,p)=>sum+p.stack-p.debt,settled.pot)).toBe(1000);
	const saved=await page.evaluate(key=>localStorage.getItem(key),saveKey);
	await page.waitForTimeout(800);
	const idle=await canvas.evaluate(element=>new Promise(resolve=>{
		let updates=0;
		const draws=window.pokerDrawCalls;
		const observer=new MutationObserver(records=>updates+=records.length);
		observer.observe(element,{attributes:true,attributeFilter:['data-php-poker']});
		setTimeout(()=>{observer.disconnect();resolve({updates,draws:window.pokerDrawCalls-draws});},600);
	}));
	expect(idle).toEqual({updates:0,draws:0});
	expect(await page.evaluate(key=>localStorage.getItem(key),saveKey)).toBe(saved);
	await page.reload();
	await expect(canvas).toHaveAttribute('data-php-poker',/"screen":"menu"/,{timeout:45_000});
	const restored=await state(canvas);
	expect(restored.revision).toBe(settled.revision);
	expect(restored.players).toEqual(settled.players);
	expect(restored.phase).toBe('showdown');
	expect(errors).toEqual([]);
});

// Use the same pure geometry as the PHP renderer, then exercise real browser touch
// events. The checks are in CSS pixels so high-DPI canvases cannot hide tiny targets.
async function regions(canvas)
{
	const input=await canvas.evaluate(element=>({view:element.phpPokerDebug.state,
		width:element.width,height:element.height,dpr:Math.min(2,devicePixelRatio||1)}));
	return JSON.parse(execFileSync('php',['-r',String.raw`
require $argv[1].'/games/php-poker/autoload.php';
$input=json_decode(stream_get_contents(STDIN),true,flags:JSON_THROW_ON_ERROR);
$view=$input['view']; $layout=PhpPoker\Ui\Layout::fit($input['width'],$input['height'],$input['dpr']);
$result=['menu'=>$layout->menu($view['canContinue']),'actions'=>$layout->actions($view,$view['ui']),
 'cards'=>$layout->discardDialog()['cards']];
foreach(['menu','actions'] as $key) foreach($result[$key] as &$button) $button['rect']=$layout->rect(...$button['rect']);
unset($button);
foreach($result['cards'] as &$rect) $rect=$layout->rect(...$rect);
echo json_encode($result,JSON_THROW_ON_ERROR);
`,root],{encoding:'utf8',input:JSON.stringify(input)}));
}

async function point(canvas,rect)
{
	const box=await canvas.boundingBox();
	const size=await canvas.evaluate(element=>[element.width,element.height]);
	return {x:box.x+(rect[0]+rect[2]/2)*box.width/size[0],y:box.y+(rect[1]+rect[3]/2)*box.height/size[1],
		width:rect[2]*box.width/size[0],height:rect[3]*box.height/size[1]};
}

test('desktop pointer can confirm discards without the dialog covering its action buttons',async({page})=>{
	await page.setViewportSize({width:1365,height:900});
	await page.addInitScript(({key,save})=>localStorage.setItem(key,save),{key:saveKey,save:drawSave});
	const canvas=await boot(page);
	await canvas.focus();await page.keyboard.press('c');
	await expect.poll(async()=>(await state(canvas)).screen).toBe('table');
	const before=await state(canvas),layout=await regions(canvas);
	const card=await point(canvas,layout.cards[0]);
	await page.mouse.click(card.x,card.y);
	await expect.poll(async()=>(await state(canvas)).ui.discards).toEqual([before.players[0].cards[0]]);
	const button=(await regions(canvas)).actions.find(button=>button.id==='draw');
	for(const rect of layout.cards)
	{
		const a=button.rect,b=rect;
		expect(a[0]<b[0]+b[2]&&b[0]<a[0]+a[2]&&a[1]<b[1]+b[3]&&b[1]<a[1]+a[3],'Draw button overlaps a card').toBe(false);
	}
	const draw=await point(canvas,button.rect);
	await page.mouse.click(draw.x,draw.y);
	await expect.poll(async()=>(await state(canvas)).players[0].drawCount).toBe(1);
});

for(const viewport of [{width:390,height:844},{width:844,height:390}])
test(`touch selection and cancellation use readable controls at ${viewport.width}×${viewport.height}`,async({browser,baseURL})=>{
	test.setTimeout(90_000);
	const context=await browser.newContext({baseURL,viewport,deviceScaleFactor:2,hasTouch:true,reducedMotion:'reduce'});
	try
	{
		const page=await context.newPage();
		const errors=[];page.on('pageerror',error=>errors.push(error.message));
		await page.addInitScript(({key,save})=>localStorage.setItem(key,save),{key:saveKey,save:drawSave});
		const canvas=await boot(page);
		await expect.poll(async()=>(await state(canvas)).ui.cardArt).toBe(true);
		const menu=(await regions(canvas)).menu;
		const resume=menu.find(button=>button.intent?.type==='resume');
		expect(resume).toBeTruthy();
		const continuePoint=await point(canvas,resume.rect);
		expect(continuePoint.height).toBeGreaterThanOrEqual(48);
		await page.touchscreen.tap(continuePoint.x,continuePoint.y);
		await expect.poll(async()=>(await state(canvas)).screen).toBe('table');
		const before=await state(canvas),layout=await regions(canvas);
		for(const button of layout.actions)
		{
			const area=await point(canvas,button.rect);
			expect(area.height,button.id+' touch height').toBeGreaterThanOrEqual(48);
			expect(area.width,button.id+' touch width').toBeGreaterThanOrEqual(48);
		}
		const card=await point(canvas,layout.cards[0]);
		expect(card.width).toBeGreaterThanOrEqual(48);
		const cdp=await context.newCDPSession(page);
		await cdp.send('Input.dispatchTouchEvent',{type:'touchStart',touchPoints:[{x:card.x,y:card.y}]});
		await cdp.send('Input.dispatchTouchEvent',{type:'touchCancel',touchPoints:[]});
		await page.waitForTimeout(150);
		expect((await state(canvas)).ui.discards).toEqual([]);
		await page.touchscreen.tap(card.x,card.y);
		await expect.poll(async()=>(await state(canvas)).ui.discards).toEqual([before.players[0].cards[0]]);
		expect((await state(canvas)).revision).toBe(before.revision);
		const draw=await point(canvas,(await regions(canvas)).actions.find(button=>button.id==='draw').rect);
		await page.touchscreen.tap(draw.x,draw.y);
		await expect.poll(async()=>(await state(canvas)).players[0].drawCount).toBe(1);
		expect(errors).toEqual([]);
	}
	finally { await context.close(); }
});

test('verified offline package reopens with the same saved cards after the network disconnects',async({page,context})=>{
	test.setTimeout(180_000);
	await page.addInitScript(({key,save})=>localStorage.setItem(key,save),{key:saveKey,save:drawSave});
	const canvas=await boot(page);
	const original=await state(canvas);
	await expect(page.locator('html')).toHaveAttribute('data-offline-state','ready',{timeout:120_000});
	await page.close();
	await context.setOffline(true);
	const reopened=await context.newPage();
	const offline=await boot(reopened);
	await expect(reopened.locator('html')).toHaveAttribute('data-offline-state','ready');
	const restored=await state(offline);
	expect(restored.revision).toBe(original.revision);
	expect(restored.players).toEqual(original.players);
	await command(offline,{type:'resume'});
	await expect.poll(async()=>(await state(offline)).screen).toBe('table');
	await command(offline,{type:'draw',cards:[]});
	await expect.poll(async()=>(await state(offline)).revision).toBeGreaterThan(original.revision);
	expect((await state(offline)).players[0].drawCount).toBe(0);
});
