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

const openingSave=execFileSync('php',['-r',String.raw`
require $argv[1].'/games/php-poker/autoload.php';
require $argv[1].'/node_modules/@seanmorris/phonics/autoload.php';
$s=new PhpPoker\Session;
$s->command(['type'=>'start','players'=>4,'variant'=>'jackpots'],'opening-fixture-3');
$s->command(['type'=>'pace','value'=>'fast']);
for($i=0;$i<100 && $s->view()['turn']>0;$i++) $s->tick(.1);
if(empty($s->view()['legalActions']['openingRequired'])) throw new RuntimeException('Expected a Fold/Pass opening');
echo $s->save();
`,root],{encoding:'utf8'});

async function boot(page)
{
	await page.goto('/games/php-poker/');
	const canvas=page.locator('canvas');
	await expect(canvas).toHaveAttribute('data-php-poker',/"screen":"menu"/,{timeout:45_000});
	await expect(page.locator('.loading')).toHaveClass(/complete/);
	return canvas;
}

const state=canvas=>canvas.evaluate(element=>JSON.parse(element.dataset.phpPoker));
const command=(canvas,intent)=>canvas.evaluate((element,intent)=>element.phpPokerDebug.command(intent),intent);

async function installGamepad(page)
{
	await page.addInitScript(()=>{
		window.pokerPads=[];
		const pad={id:'Standard Gamepad',index:0,connected:true,mapping:'standard',timestamp:performance.now(),axes:[0,0,0,0],
			buttons:Array.from({length:17},()=>({pressed:false,touched:false,value:0}))};
		pokerPads[0]=pad;
		window.changePokerPad=({axes=[],buttons=[]}={})=>{
			pad.axes=[0,0,0,0].map((_,index)=>axes[index]??0);
			pad.buttons=pad.buttons.map((_,index)=>({pressed:buttons.includes(index),touched:buttons.includes(index),value:buttons.includes(index)?1:0}));
			pad.timestamp=performance.now();
		};
		Object.defineProperty(navigator,'getGamepads',{value:()=>pokerPads});
		dispatchEvent(Object.assign(new Event('gamepadconnected'),{gamepad:pad}));
	});
}

async function padPulse(page,buttons)
{
	await page.evaluate(buttons=>changePokerPad({buttons}),buttons);
	await page.waitForTimeout(80);
	await page.evaluate(()=>changePokerPad());
	await page.waitForTimeout(50);
}

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
		await expect.poll(async()=>(await state(canvas)).ui.locked).toBe(false);
		const type=s.legalActions.call?'call':s.legalActions.check?'check':s.legalActions.pass?'pass':'fold';
		await command(canvas,{type});
		await expect.poll(async()=>(await state(canvas)).revision).toBeGreaterThan(s.revision);
	}
	await expect.poll(async()=>(await state(canvas)).phase).toBe('showdown');
	const settled=await state(canvas);
	expect(settled.ui.hint).toBe('');
	// The native motion suite checks the 0.3-second off/on cadence exactly. A loaded browser
	// may resume after the finite four-second flash, so only its completion is synchronized here.
	expect(settled.awards.length).toBeGreaterThan(0);
	expect(settled.players.reduce((sum,p)=>sum+p.stack-p.debt,settled.pot)).toBe(1000);
	const saved=await page.evaluate(key=>localStorage.getItem(key),saveKey);
	await expect.poll(async()=>(await state(canvas)).ui.winnerFlash.active,{timeout:90_000}).toBe(false);
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
$view=$input['view']; $layout=PhpPoker\Ui\Layout::fit($input['width'],$input['height'],$input['dpr'],max(2,count($view['players']??[])));
	$result=['menu'=>$layout->menu($view['canContinue']),'actions'=>$layout->actions($view,$view['ui']),
	 'cards'=>$layout->discardDialog()['cards'],'information'=>$layout->information()];
	foreach(['menu','actions'] as $key) foreach($result[$key] as &$button) $button['rect']=$layout->rect(...$button['rect']);
	unset($button);
	foreach($result['cards'] as &$rect) $rect=$layout->rect(...$rect);
	foreach($result['information'] as $key=>&$rect) $rect=$layout->rect(...$rect);
	unset($rect);
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

test('menu shortcuts, settings and setup remain usable after returning to the menu',async({page})=>{
	await page.setViewportSize({width:390,height:844});
	const canvas=await boot(page);
	await expect.poll(async()=>(await state(canvas)).ui.cardArt).toBe(true);
	await canvas.focus();await page.keyboard.press('m');
	await expect.poll(async()=>(await state(canvas)).screen).toBe('settings');
	await page.keyboard.press('m');
	await expect.poll(async()=>(await state(canvas)).ui.mode).toBe('modern');
	await page.keyboard.press('p');
	await expect.poll(async()=>(await state(canvas)).pace).toBe('fast');
	await page.keyboard.press('Escape');
	await expect.poll(async()=>(await state(canvas)).screen).toBe('menu');
	await page.setViewportSize({width:844,height:390});
	await page.keyboard.press('h');
	await expect.poll(async()=>(await state(canvas)).screen).toBe('help');
	await expect.poll(async()=>(await state(canvas)).ui.helpScrollMax).toBeGreaterThan(0);
	const canvasBox=await canvas.boundingBox(),hostScroll=await page.evaluate(()=>scrollY);
	await page.mouse.move(canvasBox.x+canvasBox.width/2,canvasBox.y+canvasBox.height/2);
	await page.mouse.wheel(0,200);
	await expect.poll(async()=>(await state(canvas)).ui.helpScroll).toBe(1);
	expect(await page.evaluate(()=>scrollY)).toBe(hostScroll);
	await page.keyboard.press('ArrowUp');
	await expect.poll(async()=>(await state(canvas)).ui.helpScroll).toBe(0);
	const helpBack=await point(canvas,(await regions(canvas)).information.back);
	await page.mouse.click(helpBack.x,helpBack.y);
	await expect.poll(async()=>(await state(canvas)).screen).toBe('menu');
	await page.setViewportSize({width:390,height:844});
	await page.keyboard.press('Enter');
	await expect.poll(async()=>(await state(canvas)).ui.local).toBe('setup');
	await page.keyboard.press('ArrowRight');await page.keyboard.press('v');
	await expect.poll(async()=>(await state(canvas)).ui.setup).toEqual({players:5,variant:'anything'});
	await page.keyboard.press('Enter');
	await expect.poll(async()=>(await state(canvas)).screen).toBe('table');
	expect((await state(canvas)).players).toHaveLength(5);
	await page.keyboard.press('Escape');
	await expect.poll(async()=>(await state(canvas)).screen).toBe('menu');
	await page.keyboard.press('h');
	await expect.poll(async()=>(await state(canvas)).screen).toBe('help');
	const savedHelpBack=await point(canvas,(await regions(canvas)).information.back);
	await page.mouse.click(savedHelpBack.x,savedHelpBack.y);
	await expect.poll(async()=>(await state(canvas)).screen).toBe('menu');
});

test('Play Online opens the embedded Matrix table lobby',async({page})=>{
	const canvas=await boot(page),view=await state(canvas),box=await canvas.boundingBox();
	const [left,top,width,height]=view.ui.hitRegions[1];
	await page.mouse.click(box.x+(left+width/2)*box.width,box.y+(top+height/2)*box.height);
	await expect.poll(async()=>(await state(canvas)).ui.local).toBe('online');
	await expect(page.locator('.phonics-room-game')).toBeVisible();
	await expect(page.locator('.phonics-room-game')).toHaveClass(/is-open/);
});

test('online table follows its stage viewport and switches responsive layouts',async({browser})=>{
	const page=await browser.newPage({viewport:{width:1400,height:900}});
	const canvas=await boot(page);
	const room=page.locator('.phonics-room-game'),stage=page.locator('.phonics-room-stage');
	await room.evaluate(element=>{
		element.querySelector('.phonics-room-stage').append(document.querySelector('canvas'));
		element.classList.add('is-open','has-game');
	});
	await expect(room).toHaveClass(/has-responsive-game/);
	await expect.poll(async()=>{
		const [frame,available]=await Promise.all([canvas.boundingBox(),stage.boundingBox()]);
		return [Math.round(frame.width),Math.round(frame.height),Math.round(available.width),Math.round(available.height)];
	}).toEqual(await stage.evaluate(element=>{
		const frame=element.getBoundingClientRect();
		return [Math.round(frame.width),Math.round(frame.height),Math.round(frame.width),Math.round(frame.height)];
	}));
	await expect.poll(async()=>(await state(canvas)).ui.profile).toBe('wide');

	await page.setViewportSize({width:390,height:844});
	await expect.poll(async()=>(await state(canvas)).ui.profile).toBe('tall');
	await expect.poll(async()=>{
		const [frame,available]=await Promise.all([canvas.boundingBox(),stage.boundingBox()]);
		return [Math.round(frame.width),Math.round(frame.height)]
			.join('x') === [Math.round(available.width),Math.round(available.height)].join('x');
	}).toBe(true);

	await page.setViewportSize({width:1000,height:450});
	await expect.poll(async()=>(await state(canvas)).ui.profile).toBe('landscape');
	await page.close();
});

test('controller navigates the menu, selects discards, draws, and toggles the game menu',async({page})=>{
	test.setTimeout(120_000);
	await installGamepad(page);
	await page.addInitScript(({key,save})=>localStorage.setItem(key,save),{key:saveKey,save:drawSave});
	const canvas=await boot(page);
	await canvas.focus();
	await padPulse(page,[13]);
	await expect.poll(async()=>(await state(canvas)).ui.controllerFocus?.id).toBe('menu:continue');
	await padPulse(page,[0]);
	await expect.poll(async()=>(await state(canvas)).screen).toBe('table');
	await expect.poll(async()=>(await state(canvas)).ui.controllerFocus?.id).toBe('card:0');

	await padPulse(page,[9]);
	await expect.poll(async()=>(await state(canvas)).screen).toBe('menu');
	await padPulse(page,[9]);
	await expect.poll(async()=>(await state(canvas)).screen).toBe('table');

	const before=await state(canvas);
	await padPulse(page,[15]);
	await expect.poll(async()=>(await state(canvas)).ui.controllerFocus?.id).toBe('card:1');
	await padPulse(page,[0]);
	await expect.poll(async()=>(await state(canvas)).ui.discards).toEqual([before.players[0].cards[1]]);
	await padPulse(page,[13]);
	await expect.poll(async()=>(await state(canvas)).ui.controllerFocus?.id).toBe('action:clear');
	await padPulse(page,[15]);
	await expect.poll(async()=>(await state(canvas)).ui.controllerFocus?.id).toBe('action:draw');
	await padPulse(page,[0]);
	await expect.poll(async()=>(await state(canvas)).players[0].drawCount).toBe(1);
});

test('graphics restoration retains the saved hand and one set of accessible controls',async({page})=>{
	test.setTimeout(90_000);
	const errors=[];page.on('pageerror',error=>errors.push(error.message));
	await page.addInitScript(({key,save})=>localStorage.setItem(key,save),{key:saveKey,save:drawSave});
	const canvas=await boot(page);
	await expect.poll(async()=>(await state(canvas)).ui.cardArt).toBe(true);
	await canvas.focus();await page.keyboard.press('c');
	await expect.poll(async()=>(await state(canvas)).screen).toBe('table');
	await expect.poll(async()=>(await state(canvas)).ui.cardArt).toBe(true);
	const before=await state(canvas);
	await canvas.evaluate(element=>new Promise(resolve=>{
		const extension=element.getContext('webgl2').getExtension('WEBGL_lose_context');
		element.addEventListener('webglcontextrestored',()=>requestAnimationFrame(()=>resolve()),{once:true});
		element.addEventListener('webglcontextlost',()=>setTimeout(()=>extension.restoreContext(),100),{once:true});
		extension.loseContext();
	}));
	await expect.poll(async()=>(await state(canvas)).ui.cardArt).toBe(true);
	const restored=await state(canvas);
	expect(restored.players).toEqual(before.players);
	expect(restored.revision).toBe(before.revision);
	expect(restored.phase).toBe(before.phase);
	await expect(page.locator('[id^="php-poker-hand-"]')).toHaveCount(1);
	if(restored.screen==='menu') {await canvas.focus();await page.keyboard.press('c');}
	await expect.poll(async()=>(await state(canvas)).screen).toBe('table');
	await page.keyboard.press('d');
	await expect.poll(async()=>(await state(canvas)).revision).toBeGreaterThan(before.revision);
	expect(errors).toEqual([]);
});

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

test('overlapping discard cards activate the face painted on top',async({page})=>{
	await page.setViewportSize({width:1280,height:1285});
	await page.addInitScript(({key,save})=>localStorage.setItem(key,save),{key:saveKey,save:drawSave});
	const canvas=await boot(page);
	await canvas.focus();await page.keyboard.press('c');
	await expect.poll(async()=>(await state(canvas)).screen).toBe('table');
	const before=await state(canvas),layout=await regions(canvas),[first,second]=layout.cards;
	expect(first[0]+first[2]).toBeGreaterThan(second[0]);
	const overlap=await point(canvas,[second[0]+2,Math.max(first[1],second[1])+20,2,2]);
	await page.mouse.click(overlap.x,overlap.y);
	await expect.poll(async()=>(await state(canvas)).ui.discards).toEqual([before.players[0].cards[1]]);
});

test('the lifted edge of a selected card can deselect it',async({page})=>{
	await page.setViewportSize({width:1365,height:900});
	await page.addInitScript(({key,save})=>localStorage.setItem(key,save),{key:saveKey,save:drawSave});
	const canvas=await boot(page);
	await canvas.focus();await page.keyboard.press('c');
	await expect.poll(async()=>(await state(canvas)).screen).toBe('table');
	const layout=await regions(canvas),card=await point(canvas,layout.cards[0]);
	await page.mouse.click(card.x,card.y);
	await expect.poll(async()=>(await state(canvas)).ui.discards.length).toBe(1);
	// Painting raises a selected card by 12 design pixels; its exposed upper edge must remain clickable.
	const scale=layout.cards[0][3]/174;
	const edge=await point(canvas,[layout.cards[0][0],layout.cards[0][1]-8*scale,layout.cards[0][2],4*scale]);
	await page.mouse.click(edge.x,edge.y);
	await expect.poll(async()=>(await state(canvas)).ui.discards).toEqual([]);
});

test('clickable cards and controls show a hand cursor and the table background does not',async({page})=>{
	await page.setViewportSize({width:1365,height:900});
	await page.addInitScript(({key,save})=>localStorage.setItem(key,save),{key:saveKey,save:drawSave});
	const canvas=await boot(page);
	const resume=await point(canvas,(await regions(canvas)).menu.find(button=>button.id==='continue').rect);
	await page.mouse.move(resume.x,resume.y);
	await expect(canvas).toHaveCSS('cursor','pointer');
	await page.mouse.click(resume.x,resume.y);
	await expect.poll(async()=>(await state(canvas)).screen).toBe('table');
	const card=await point(canvas,(await regions(canvas)).cards[0]);
	await page.mouse.move(card.x,card.y);
	await expect(canvas).toHaveCSS('cursor','pointer');
	const rect=(await regions(canvas)).cards[0];
	const lowerEdge=await point(canvas,[rect[0],rect[1]+rect[3]-4,rect[2],2]);
	await page.mouse.click(lowerEdge.x,lowerEdge.y);
	await expect.poll(async()=>(await state(canvas)).ui.discards.length).toBe(1);
	// Selection moves the card away from this stationary pointer. Keyboard deselection moves it back.
	await expect(canvas).toHaveCSS('cursor','default');
	await page.keyboard.press('1');
	await expect.poll(async()=>(await state(canvas)).ui.discards).toEqual([]);
	await expect(canvas).toHaveCSS('cursor','pointer');
	const draw=await point(canvas,(await regions(canvas)).actions.find(button=>button.id==='draw').rect);
	await page.mouse.move(draw.x,draw.y);
	await expect(canvas).toHaveCSS('cursor','pointer');
	const box=await canvas.boundingBox();
	await page.mouse.move(box.x+box.width-10,box.y+box.height-10);
	await expect(canvas).toHaveCSS('cursor','default');
});

test('Fold/Pass explains the opening round and a later bet leads to selectable discards',async({page})=>{
	await page.addInitScript(({key,save})=>localStorage.setItem(key,save),{key:saveKey,save:openingSave});
	// Record short transitions inside the page. A busy build machine may deliver the next test RPC after
	// a 0.25-second slide has already finished, so separate reads cannot describe one animation frame.
	await page.addInitScript(()=>{
		window.pokerMotions=[];
		new MutationObserver(records=>{
			for(const record of records)
			{
				const s=JSON.parse(record.target.dataset.phpPoker||'null');
				if(!s?.ui?.animation)continue;
				const entry={kind:s.ui.animation.kind,locked:s.ui.locked,revision:s.revision};
				if(JSON.stringify(entry)!==JSON.stringify(window.pokerMotions.at(-1)))window.pokerMotions.push(entry);
			}
		}).observe(document,{subtree:true,attributes:true,attributeFilter:['data-php-poker']});
	});
	const canvas=await boot(page);
	await canvas.focus();await page.keyboard.press('c');
	await expect.poll(async()=>(await state(canvas)).screen).toBe('table');
	expect((await regions(canvas)).actions.map(button=>button.id)).toEqual(['fold','pass']);
	expect((await state(canvas)).ui.hint).toBe('Need jacks or better. Pass to stay in.');
	const pass=await point(canvas,(await regions(canvas)).actions.find(button=>button.id==='pass').rect);
	await page.mouse.click(pass.x,pass.y);
	await expect.poll(async()=>(await state(canvas)).phase).toBe('redeal');
	expect((await state(canvas)).players[0].folded).toBe(false);
	const deal=await point(canvas,(await regions(canvas)).actions.find(button=>button.id==='next').rect);
	await page.mouse.click(deal.x,deal.y);
	await expect.poll(()=>page.evaluate(()=>window.pokerMotions.some(s=>s.kind==='deal'&&s.locked))).toBe(true);
	for(let i=0;i<12;i++)
	{
		await expect.poll(async()=>(await state(canvas)).turn).toBe(0);
		await expect.poll(async()=>(await state(canvas)).ui.locked).toBe(false);
		const s=await state(canvas);
		if(s.legalActions.draw)break;
		const actions=(await regions(canvas)).actions;
		const button=actions.find(button=>button.id==='call')??actions.find(button=>button.id==='bet');
		expect(button,'betting must finish before discarding').toBeTruthy();
		const click=await point(canvas,button.rect);await page.mouse.click(click.x,click.y);
		await expect.poll(async()=>(await state(canvas)).revision).toBeGreaterThan(s.revision);
		await expect.poll(()=>page.evaluate(revision=>window.pokerMotions.some(s=>s.kind==='chips'&&s.locked&&s.revision===revision),s.revision+1)).toBe(true);
	}
	await expect.poll(async()=>(await state(canvas)).legalActions.draw).toBe(true);
	const before=await state(canvas);
	const card=await point(canvas,(await regions(canvas)).cards[2]);
	await page.mouse.click(card.x,card.y);
	await expect.poll(async()=>(await state(canvas)).ui.discards).toEqual([before.players[0].cards[2]]);
	const draw=await point(canvas,(await regions(canvas)).actions.find(button=>button.id==='draw').rect);
	await page.mouse.click(draw.x,draw.y);
	await expect.poll(async()=>(await state(canvas)).players[0].drawCount).toBe(1);
	await expect.poll(()=>page.evaluate(revision=>window.pokerMotions.some(s=>s.kind==='draw'&&s.locked&&s.revision===revision),before.revision+1)).toBe(true);
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
