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

/**
 * Press and release controller buttons. The game reads the pad once a frame, so each state is held for whole
 * animation frames as well as its minimum time: on a busy machine a frame can outlast a hold timed in milliseconds,
 * and then the press is never seen, or two presses of one button run together.
 */
async function padPulse(page,buttons)
{
	const held=(buttons,minimum)=>page.evaluate(({buttons,minimum})=>new Promise(resolve=>{
		const until=performance.now()+minimum;
		let frames=0;
		const step=()=>++frames>3&&performance.now()>=until?resolve():requestAnimationFrame(step);
		changePokerPad({buttons});
		requestAnimationFrame(step);
	}),{buttons,minimum});
	await held(buttons,80);
	await held([],50);
}

test('Bicycle PC-speaker cues reach Web Audio and the shared mute control',async({page})=>{
	await page.addInitScript(()=>{
		window.pokerOscillators=[];
		class Param{setValueAtTime(value,at){this.value=value;this.at=at;}linearRampToValueAtTime(){}}
		window.AudioContext=class{
			constructor(){this.currentTime=1;this.state='running';this.destination={};}
			createOscillator(){const source={frequency:new Param,type:'',starts:[],stops:[],connect:node=>node,
				start(at){this.starts.push(at);},stop(at){this.stops.push(at);}};pokerOscillators.push(source);return source;}
			createGain(){return {gain:new Param,connect:node=>node};}
			resume(){return Promise.resolve();}close(){return Promise.resolve();}
		};
	});
	const canvas=await boot(page),mute=page.locator('.quick-controls #mute');
	await expect(mute).toBeVisible();
	await canvas.focus();await page.keyboard.press('Enter');
	await expect.poll(async()=>(await state(canvas)).ui.local).toBe('setup');
	await page.keyboard.press('Enter');
	await expect.poll(async()=>(await state(canvas)).screen).toBe('table');
	await expect.poll(()=>canvas.evaluate(element=>element.phpPokerDebug.audio.played)).toBeGreaterThan(0);
	const frequencies=await page.evaluate(()=>[...new Set(pokerOscillators.map(source=>source.frequency.value))].sort((a,b)=>a-b));
	expect(frequencies).toEqual([200,500,900]);
	await mute.click();
	await expect(mute).toHaveAttribute('aria-pressed','true');
	expect(await canvas.evaluate(element=>element.phpPokerDebug.audio.muted)).toBe(true);
	expect(await page.evaluate(()=>localStorage.getItem('php-poker.muted.v1'))).toBe('1');
});

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
/**
 * Read the canvas box and its drawing buffer while the two agree.
 *
 * The page header changes height for a moment when its offline status wraps on a narrow screen. The box follows at
 * once and the buffer a frame later, and a layout rectangle scaled between the two comes out the wrong size.
 */
async function steady(canvas)
{
	let read;
	await expect.poll(async()=>{
		read=await canvas.evaluate(element=>{
			const box=element.getBoundingClientRect();
			return {box:{x:box.x,y:box.y,width:box.width,height:box.height},size:[element.width,element.height]};
		});
		return Math.abs(read.size[0]/read.box.width-read.size[1]/read.box.height);
	}).toBeLessThan(.01);
	return read;
}

async function regions(canvas)
{
	await steady(canvas);
	const input=await canvas.evaluate(element=>({view:element.phpPokerDebug.state,
		width:element.width,height:element.height,dpr:Math.min(2,devicePixelRatio||1)}));
	return JSON.parse(execFileSync('php',['-r',String.raw`
require $argv[1].'/games/php-poker/autoload.php';
$input=json_decode(stream_get_contents(STDIN),true,flags:JSON_THROW_ON_ERROR);
$view=$input['view']; $layout=PhpPoker\Ui\Layout::fit($input['width'],$input['height'],$input['dpr'],max(2,count($view['players']??[])));
$wild=$layout->wildcardMenu(); $custom=$layout->customWildcards((int)($view['ui']['setup']['customWildPage']??0)); $limits=$layout->limits(); $gameMenu=$layout->gameMenu(4);
	$result=['menu'=>$layout->menu($view['canContinue']),'actions'=>$layout->actions($view,$view['ui']),
	 'cards'=>$layout->discardDialog()['cards'],'information'=>$layout->information(),'gameMenu'=>$gameMenu,'wild'=>$wild,'customWild'=>$custom,'limits'=>$limits,
	 'topMenus'=>$layout->topMenus(),'topGame'=>$layout->topDropdown('game'),'topPoker'=>$layout->topDropdown('poker'),
	 'gameSettings'=>$layout->gameSettingsDialog((int)($view['ui']['gameSettingsWildLines']??1)),'odds'=>$layout->oddsDialog('odds'),'improving'=>$layout->oddsDialog('improving')];
	foreach(['menu','actions'] as $key) foreach($result[$key] as &$button) $button['rect']=$layout->rect(...$button['rect']);
	unset($button);
	foreach($result['cards'] as &$rect) $rect=$layout->rect(...$rect);
	unset($rect);
	foreach($result['information'] as $key=>&$rect) $rect=$layout->rect(...$rect);
	unset($rect);
	foreach($result['gameMenu']['items'] as &$rect) $rect=$layout->rect(...$rect);
	unset($rect);
	foreach(['box','back'] as $key) $result['gameMenu'][$key]=$layout->rect(...$result['gameMenu'][$key]);
	foreach($result['wild']['items'] as &$rect) $rect=$layout->rect(...$rect);
	unset($rect);
	foreach(['box','back','apply'] as $key) $result['wild'][$key]=$layout->rect(...$result['wild'][$key]);
	foreach($result['customWild']['cards'] as &$rect) $rect=$layout->rect(...$rect);
	unset($rect);
	foreach(['box','previous','next','back','done'] as $key) $result['customWild'][$key]=$layout->rect(...$result['customWild'][$key]);
	foreach($result['limits']['fields'] as &$field) foreach(['label','less','count','more'] as $key) $field[$key]=$layout->rect(...$field[$key]);
	unset($field);
	foreach(['box','back','apply'] as $key) $result['limits'][$key]=$layout->rect(...$result['limits'][$key]);
	foreach($result['topMenus'] as &$rect) $rect=$layout->rect(...$rect);
	unset($rect);
	foreach(['topGame','topPoker'] as $name) { $result[$name]['box']=$layout->rect(...$result[$name]['box']); foreach($result[$name]['items'] as &$item) $item['rect']=$layout->rect(...$item['rect']); unset($item); }
	$result['gameSettings']['box']=$layout->rect(...$result['gameSettings']['box']); $result['gameSettings']['ok']=$layout->rect(...$result['gameSettings']['ok']);
	foreach($result['gameSettings']['rows'] as &$rect) $rect=$layout->rect(...$rect);
	unset($rect);
	foreach(['odds','improving'] as $name) { foreach(['box','more','ok'] as $key) $result[$name][$key]=$layout->rect(...$result[$name][$key]); foreach($result[$name]['rows'] as &$rect) $rect=$layout->rect(...$rect); unset($rect); }
	unset($rect);
echo json_encode($result,JSON_THROW_ON_ERROR);
`,root],{encoding:'utf8',input:JSON.stringify(input)}));
}

async function point(canvas,rect)
{
	const {box,size}=await steady(canvas);
	return {x:box.x+(rect[0]+rect[2]/2)*box.width/size[0],y:box.y+(rect[1]+rect[3]/2)*box.height/size[1],
		width:rect[2]*box.width/size[0],height:rect[3]*box.height/size[1]};
}

test('table-bar menus save, load and show the settings and odds tables',async({page})=>{
	await page.addInitScript(()=>{
		window.savedPokerFile='';
		window.showSaveFilePicker=async()=>({createWritable:async()=>({write:async value=>{savedPokerFile=value;},close:async()=>{}})});
		window.showOpenFilePicker=async()=>[{getFile:async()=>new File([savedPokerFile],'php-poker-save.json',{type:'application/json'})}];
	});
	await page.addInitScript(({key,save})=>localStorage.setItem(key,save),{key:saveKey,save:openingSave});
	const canvas=await boot(page);
	await command(canvas,{type:'resume'});
	await expect.poll(async()=>(await state(canvas)).screen).toBe('table');
	let geometry=await regions(canvas);
	let target=await point(canvas,geometry.topMenus.game); await page.mouse.click(target.x,target.y);
	await expect.poll(async()=>(await state(canvas)).ui.topMenu).toBe('game');
	target=await point(canvas,geometry.topGame.items.find(item=>item.id==='save').rect); await page.mouse.click(target.x,target.y);
	await expect.poll(()=>page.evaluate(()=>savedPokerFile.length)).toBeGreaterThan(100);
	const generation=(await state(canvas)).generation;
	await canvas.focus();await page.keyboard.press('Alt+g');
	await expect.poll(async()=>(await state(canvas)).ui.topMenu).toBe('game');
	geometry=await regions(canvas);
	target=await point(canvas,geometry.topGame.items.find(item=>item.id==='load').rect); await page.mouse.click(target.x,target.y);
	await expect.poll(async()=>(await state(canvas)).generation).toBeGreaterThan(generation);

	await canvas.focus();await page.keyboard.press('Alt+p');
	await expect.poll(async()=>(await state(canvas)).ui.topMenu).toBe('poker');
	geometry=await regions(canvas);
	target=await point(canvas,geometry.topPoker.items.find(item=>item.id==='game-settings').rect); await page.mouse.click(target.x,target.y);
	await expect.poll(async()=>(await state(canvas)).ui.infoDialog).toBe('game-settings');
	expect((await state(canvas)).ui.gameSettings).toMatchObject({table:'4 players · Offline',opening:'Jacks or better',wild:'No wild cards'});
	target=await point(canvas,geometry.gameSettings.ok); await page.mouse.click(target.x,target.y);
	await expect.poll(async()=>(await state(canvas)).ui.infoDialog).toBe(null);
	await canvas.focus();await page.keyboard.press('Alt+p');
	await expect.poll(async()=>(await state(canvas)).ui.topMenu).toBe('poker');
	geometry=await regions(canvas);
	target=await point(canvas,geometry.topPoker.items.find(item=>item.id==='history').rect); await page.mouse.click(target.x,target.y);
	await expect.poll(async()=>(await state(canvas)).ui.history).toBe(true);
	await canvas.focus();await page.keyboard.press('Enter');
	await expect.poll(async()=>(await state(canvas)).ui.history).toBe(false);
	await page.keyboard.press('Alt+p');
	await expect.poll(async()=>(await state(canvas)).ui.topMenu).toBe('poker');
	geometry=await regions(canvas);
	target=await point(canvas,geometry.topPoker.items.find(item=>item.id==='odds').rect); await page.mouse.click(target.x,target.y);
	await expect.poll(async()=>(await state(canvas)).ui.infoDialog).toBe('odds');
	target=await point(canvas,geometry.odds.more); await page.mouse.click(target.x,target.y);
	await expect.poll(async()=>(await state(canvas)).ui.infoDialog).toBe('improving');
	target=await point(canvas,geometry.improving.ok); await page.mouse.click(target.x,target.y);
	await expect.poll(async()=>(await state(canvas)).ui.infoDialog).toBe(null);
});

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
	await canvas.focus();
	await page.keyboard.press('h');
	await expect.poll(async()=>(await state(canvas)).screen).toBe('help');
	await expect.poll(async()=>(await state(canvas)).ui.helpGame).toBe(null);
	for(const [key,game] of [['1','draw'],['2','holdem'],['3','stud5'],['4','stud7']]){
		await page.keyboard.press(key);
		await expect.poll(async()=>(await state(canvas)).ui.helpGame).toBe(game);
		await page.keyboard.press('Escape');
		await expect.poll(async()=>(await state(canvas)).ui.helpGame).toBe(null);
	}
	await page.keyboard.press('1');
	await expect.poll(async()=>(await state(canvas)).ui.helpGame).toBe('draw');
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
	await expect.poll(async()=>(await state(canvas)).ui.helpGame).toBe(null);
	const helpMenuBack=await point(canvas,(await regions(canvas)).gameMenu.back);
	await page.mouse.click(helpMenuBack.x,helpMenuBack.y);
	await expect.poll(async()=>(await state(canvas)).screen).toBe('menu');
	await page.setViewportSize({width:390,height:844});
	await canvas.focus();
	await page.keyboard.press('Enter');
	await expect.poll(async()=>(await state(canvas)).ui.local).toBe('setup');
	await page.keyboard.press('ArrowRight');await page.keyboard.press('v');await page.keyboard.press('2');
	await expect.poll(async()=>(await state(canvas)).ui.setup).toMatchObject({players:5,variant:'anything',wild:[],customWild:[]});
	await page.keyboard.press('w');
	await expect.poll(async()=>(await state(canvas)).ui.local).toBe('wildcards');
	await page.keyboard.press('1');await page.keyboard.press('7');
	await expect.poll(async()=>(await state(canvas)).ui.local).toBe('custom-wildcards');
	let custom=await regions(canvas),customCard=await point(canvas,custom.customWild.cards[0]);
	await page.mouse.click(customCard.x,customCard.y);
	for(let pageIndex=0;pageIndex<4;pageIndex++)await page.keyboard.press('ArrowRight');
	await expect.poll(async()=>(await state(canvas)).ui.setup.customWildPage).toBe(4);
	custom=await regions(canvas);customCard=await point(canvas,custom.customWild.cards[52]);
	await page.mouse.click(customCard.x,customCard.y);
	customCard=await point(canvas,custom.customWild.cards[53]);
	await page.mouse.click(customCard.x,customCard.y);
	const customDone=await point(canvas,custom.customWild.done);
	await page.mouse.click(customDone.x,customDone.y);
	await expect.poll(async()=>(await state(canvas)).ui.setup).toMatchObject({wild:['joker','custom'],customWild:[0,52,53]});
	await page.keyboard.press('l');
	await expect.poll(async()=>(await state(canvas)).ui.local).toBe('limits');
	let limitRegions=await regions(canvas);
	for(const field of ['ante','finalMinimum','raiseLimit']){
		const plus=await point(canvas,limitRegions.limits.fields[field].more);await page.mouse.click(plus.x,plus.y);
	}
	const playerLess=await point(canvas,limitRegions.limits.fields.playerRaiseLimit.less);await page.mouse.click(playerLess.x,playerLess.y);
	const limitsApply=await point(canvas,limitRegions.limits.apply);await page.mouse.click(limitsApply.x,limitsApply.y);
	await expect.poll(async()=>(await state(canvas)).ui.setup).toMatchObject({ante:2,minimum:2,maximum:20,finalMinimum:3,finalMaximum:20,raiseLimit:4,playerRaiseLimit:2});
	await page.keyboard.press('Enter');
	await expect.poll(async()=>(await state(canvas)).screen).toBe('table');
	expect((await state(canvas)).players).toHaveLength(5);
	expect((await state(canvas)).profile).toMatchObject({ante:2,minimum:2,maximum:20,finalMinimum:3,finalMaximum:20,raiseLimit:4,playerRaiseLimit:2,wild:['joker','custom'],customWild:[0,52,53]});
	// The offline table is saved, so Escape returns to the title at once.
	await page.keyboard.press('Escape');
	await expect.poll(async()=>(await state(canvas)).screen).toBe('menu');
	expect((await state(canvas)).ui.confirmDialog).toBeNull();
	await page.keyboard.press('h');
	await expect.poll(async()=>(await state(canvas)).screen).toBe('help');
	const savedHelpBack=await point(canvas,(await regions(canvas)).gameMenu.back);
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
	// Keep every controller focus the page publishes. At the fast pace the wait for the opponent lasts about a tenth
	// of a second, shorter than one test round trip on a busy machine, so separate reads can miss it entirely.
	await page.addInitScript(()=>{
		window.pokerFocus=[];
		new MutationObserver(records=>{
			for(const record of records)
			{
				const focus=JSON.parse(record.target.dataset.phpPoker||'null')?.ui?.controllerFocus;
				if(!focus)continue;
				const entry={id:focus.id,rect:focus.rect!==undefined};
				if(JSON.stringify(entry)!==JSON.stringify(window.pokerFocus.at(-1)))window.pokerFocus.push(entry);
			}
		}).observe(document,{subtree:true,attributes:true,attributeFilter:['data-php-poker']});
	});
	await page.addInitScript(({key,save})=>localStorage.setItem(key,save),{key:saveKey,save:drawSave});
	const canvas=await boot(page);
	await canvas.focus();
	await padPulse(page,[13]);
	await expect.poll(async()=>(await state(canvas)).ui.controllerFocus?.id).toBe('menu:continue');
	await padPulse(page,[0]);
	await expect.poll(async()=>(await state(canvas)).screen).toBe('table');
	await expect.poll(async()=>(await state(canvas)).ui.controllerFocus?.id).toBe('action:draw');

	// An offline table is saved, so Start returns to the title without a confirmation.
	await padPulse(page,[9]);
	await expect.poll(async()=>(await state(canvas)).screen).toBe('menu');
	await padPulse(page,[9]);
	await expect.poll(async()=>(await state(canvas)).screen).toBe('table');

	const before=await state(canvas);
	await padPulse(page,[12]);
	await expect.poll(async()=>/^card:\d$/.test((await state(canvas)).ui.controllerFocus?.id??'')).toBe(true);
	const cardIndex=Number((await state(canvas)).ui.controllerFocus.id.slice(5));
	await padPulse(page,[0]);
	await expect.poll(async()=>(await state(canvas)).ui.discards).toEqual([before.players[0].cards[cardIndex]]);
	await padPulse(page,[13]);
	if((await state(canvas)).ui.controllerFocus?.id==='action:clear')await padPulse(page,[15]);
	await expect.poll(async()=>(await state(canvas)).ui.controllerFocus?.id).toBe('action:draw');
	await page.evaluate(()=>{window.pokerFocus.length=0;});
	await padPulse(page,[0]);
	await expect.poll(()=>page.evaluate(()=>window.pokerFocus.some(focus=>focus.id==='table:waiting'&&!focus.rect))).toBe(true);
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

test('Keep all confirms an empty discard selection',async({page})=>{
	await page.addInitScript(({key,save})=>localStorage.setItem(key,save),{key:saveKey,save:drawSave});
	const canvas=await boot(page);
	await canvas.focus();await page.keyboard.press('c');
	await expect.poll(async()=>(await state(canvas)).screen).toBe('table');
	const before=await state(canvas),actions=(await regions(canvas)).actions;
	expect(actions.map(button=>[button.id,button.label])).toEqual([['draw','Keep all']]);
	const keep=await point(canvas,actions[0].rect);
	await page.mouse.click(keep.x,keep.y);
	await expect.poll(async()=>(await state(canvas)).revision).toBeGreaterThan(before.revision);
	await expect.poll(async()=>(await state(canvas)).players[0].drawCount).toBe(0);
});

test('desktop pointer can confirm discards without the dialog covering its action buttons',async({page})=>{
	await page.setViewportSize({width:1365,height:900});
	await page.addInitScript(({key,save})=>localStorage.setItem(key,save),{key:saveKey,save:drawSave});
	const canvas=await boot(page);
	await canvas.focus();await page.keyboard.press('c');
	await expect.poll(async()=>(await state(canvas)).screen).toBe('table');
	const before=await state(canvas);let layout=await regions(canvas);
	const card=await point(canvas,layout.cards[0]);
	await page.mouse.click(card.x,card.y);
	await expect.poll(async()=>(await state(canvas)).ui.discards).toEqual([before.players[0].cards[0]]);
	let controls=(await regions(canvas)).actions;
	expect(controls.map(button=>[button.id,button.label])).toEqual([['clear','Clear'],['draw','Draw 1']]);
	const clear=await point(canvas,controls[0].rect);
	await page.mouse.click(clear.x,clear.y);
	await expect.poll(async()=>(await state(canvas)).ui.discards).toEqual([]);
	expect((await regions(canvas)).actions.map(button=>[button.id,button.label])).toEqual([['draw','Keep all']]);
	layout=await regions(canvas);
	const selectAgain=await point(canvas,layout.cards[0]);
	await page.mouse.click(selectAgain.x,selectAgain.y);
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

test('discard cards stand apart in a compressed window and each edge activates its own face',async({page})=>{
	await page.setViewportSize({width:1280,height:1285});
	await page.addInitScript(({key,save})=>localStorage.setItem(key,save),{key:saveKey,save:drawSave});
	const canvas=await boot(page);
	await canvas.focus();await page.keyboard.press('c');
	await expect.poll(async()=>(await state(canvas)).screen).toBe('table');
	const before=await state(canvas),layout=await regions(canvas),[first,second]=layout.cards;
	// The dialog keeps its CSS size on a compressed oval, so neighbouring faces never cover one another.
	expect(first[0]+first[2]).toBeLessThanOrEqual(second[0]);
	const leading=await point(canvas,[second[0]+2,Math.max(first[1],second[1])+20,2,2]);
	await page.mouse.click(leading.x,leading.y);
	await expect.poll(async()=>(await state(canvas)).ui.discards).toEqual([before.players[0].cards[1]]);
	const trailing=await point(canvas,[first[0]+first[2]-4,Math.max(first[1],second[1])+20,2,2]);
	await page.mouse.click(trailing.x,trailing.y);
	await expect.poll(async()=>(await state(canvas)).ui.discards)
		.toEqual([before.players[0].cards[0],before.players[0].cards[1]].sort((a,b)=>a-b));
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

// The street games are chosen on the setup card and played from the keyboard and the pointer. Seeds are fresh in
// the page, so these follow whatever is dealt and assert the shape of each street rather than particular cards.
async function startGame(page,canvas,choice,{seatsRight=0}={})
{
	await canvas.focus();await page.keyboard.press('Enter');
	await expect.poll(async()=>(await state(canvas)).ui.local).toBe('setup');
	for(let i=0;i<seatsRight;i++) await page.keyboard.press('ArrowRight');
	// V opens the game selector, a column of buttons; each has a number key.
	await page.keyboard.press('v');
	await expect.poll(async()=>(await state(canvas)).ui.local).toBe('games');
	await page.keyboard.press(String(choice));
	await expect.poll(async()=>(await state(canvas)).ui.local).toBe('setup');
	const setup=(await state(canvas)).ui.setup;
	await page.keyboard.press('Enter');
	await expect.poll(async()=>(await state(canvas)).screen).toBe('table');
	return setup;
}

/**
 * Record the first state the table publishes for each revision, in window.pokerViews.
 *
 * Bots act on their own clock, so the table a test reads after a round trip is some later state: a bot may have
 * folded and turned its exposed card down, or a street may have gone by. The record holds every state in order, and
 * its first entry is the deal itself, published while the cards are still moving and before any bot can act.
 */
const recordViews=page=>page.addInitScript(()=>{
	window.pokerViews=[];
	new MutationObserver(records=>{
		const s=JSON.parse(records.at(-1).target.dataset.phpPoker||'null');
		if(s?.screen!=='table'||s.revision===window.pokerViews.at(-1)?.revision)return;
		window.pokerViews.push({
			revision:s.revision,game:s.game,street:s.street,phase:s.phase,board:s.board
			,players:s.players.map(({id,cards,faces,folded,lastAction,bet,totalBet})=>({id,cards,faces,folded,lastAction,bet,totalBet}))
		});
	}).observe(document,{subtree:true,attributes:true,attributeFilter:['data-php-poker']});
});
const views=page=>page.evaluate(()=>window.pokerViews);
/** @returns The deal as the table first published it, before any bot has acted. */
async function opening(page)
{
	await expect.poll(async()=>(await views(page)).length).toBeGreaterThan(0);
	return (await views(page))[0];
}

async function settled(canvas)
{
	let snapshot;
	await expect.poll(async()=>{
		snapshot=await state(canvas);
		// The final animation frame still owns the prompt and input. Return the same unlocked snapshot we checked;
		// reading it again afterwards can instead observe the next bot action's chip animation.
		return !snapshot.ui.animation&&!snapshot.ui.locked;
	},{timeout:20_000}).toBe(true);
	return snapshot;
}

test('hold\'em is chosen at setup, deals a board street by street and names each street',async({page})=>{
	await page.addInitScript(()=>{
		window.pokerDeals=[];
		new MutationObserver(records=>{
			for(const record of records)
			{
				const s=JSON.parse(record.target.dataset.phpPoker||'null');
				if(s?.ui?.animation?.kind==='deal'&&!window.pokerDeals.includes(s.street))window.pokerDeals.push(s.street);
			}
		}).observe(document,{subtree:true,attributes:true,attributeFilter:['data-php-poker']});
	});
	await recordViews(page);
	const canvas=await boot(page);
	const setup=await startGame(page,canvas,3);
	expect(setup).toMatchObject({game:'holdem',players:4});
	let view=await opening(page);
	expect(view).toMatchObject({game:'holdem',street:'preflop',board:[],phase:'betting'});
	expect(view.players[0].cards).toHaveLength(2);
	expect(view.players[0].cards.every(Number.isInteger)).toBe(true);
	expect(view.players.slice(1).every(player=>player.cards.length===2&&player.cards.every(card=>card===null))).toBe(true);
	expect(view.players.filter(player=>player.lastAction.startsWith('Small blind')||player.lastAction.startsWith('Big blind')||player.bet>0).length).toBeGreaterThanOrEqual(2);
	const seen=new Set();
	let showdown=null,clicked=false;
	for(let step=0;step<160&&!(showdown&&seen.has('river'));step++)
	{
		view=await settled(canvas);
		if(view.phase==='showdown')
		{
			if(view.reveal&&view.board.length===5)showdown=view;
			else await page.keyboard.press('Enter');
			await page.waitForTimeout(120);
			continue;
		}
		seen.add(view.street);
		expect(view.board).toHaveLength({preflop:0,flop:3,turn:4,river:5}[view.street]);
		if(view.turn!==0){await page.waitForTimeout(120);continue;}
		expect(view.ui.hint).toMatch(new RegExp('^'+{preflop:'Pre-flop',flop:'Flop',turn:'Turn',river:'River'}[view.street]+': '));
		const actions=(await regions(canvas)).actions;
		expect(actions.map(button=>button.id)).toEqual(expect.arrayContaining(['fold']));
		expect(actions.some(button=>['draw','pass','clear'].includes(button.id))).toBe(false);
		const stay=actions.find(button=>button.id==='check'||button.id==='call');
		if(!clicked&&view.street!=='preflop')
		{
			// One decision goes through the pointer: the board must not cover the action column.
			const target=await point(canvas,stay.rect);
			await page.mouse.click(target.x,target.y);clicked=true;
		}
		else await page.keyboard.press('c');
		await expect.poll(async()=>(await state(canvas)).revision).toBeGreaterThan(view.revision);
	}
	expect([...seen]).toEqual(expect.arrayContaining(['preflop','flop','turn','river']));
	expect(clicked).toBe(true);
	expect(showdown.board).toHaveLength(5);
	expect(showdown.players[0].handName).toBeTruthy();
	expect(showdown.awards.reduce((sum,award)=>sum+award.amount,0)).toBeGreaterThan(0);
	expect(await page.evaluate(()=>window.pokerDeals)).toEqual(expect.arrayContaining(['preflop','flop','turn','river']));
	await expect(page.locator('[id^="poker-hand"],[aria-live="polite"]').filter({hasText:'Board:'}).first()).toContainText(/Your cards: .+\. Board: .+, .+, .+/);
	// The Poker menu drops the five-card odds tables for this game, and House Rules names the blinds.
	await page.keyboard.press('Enter');
	await settled(canvas);
	await page.keyboard.press('Alt+p');
	await expect.poll(async()=>(await state(canvas)).ui.topMenu).toBe('poker');
	await page.keyboard.press('o');
	expect((await state(canvas)).ui.infoDialog??null).toBeNull();
	await page.keyboard.press('r');
	await expect.poll(async()=>(await state(canvas)).ui.infoDialog).toBe('game-settings');
	expect((await state(canvas)).ui.gameSettings).toMatchObject({table:'4 players · Offline',opening:"Texas Hold'em",ante:'1 and 2 chips'});
});

test('seven-card stud seats seven, shows exposed cards and keeps hole cards private',async({page})=>{
	await recordViews(page);
	const canvas=await boot(page);
	const setup=await startGame(page,canvas,5,{seatsRight:4});
	expect(setup).toMatchObject({game:'stud7',players:7});
	let view=await opening(page);
	expect(view).toMatchObject({game:'stud7',street:'third',phase:'betting'});
	expect(view.players).toHaveLength(7);
	expect(view.players[0].faces).toEqual(['down','down','up']);
	expect(view.players[0].cards.every(Number.isInteger)).toBe(true);
	for(const player of view.players.slice(1))
	{
		expect(player.folded).toBe(false);
		expect(player.cards.slice(0,2)).toEqual([null,null]);
		expect(Number.isInteger(player.cards[2])).toBe(true);
	}
	expect(view.players.filter(player=>player.lastAction.startsWith('Brings in'))).toHaveLength(1);
	expect(view.players.every(player=>player.totalBet>=1)).toBe(true);
	let showdown=null;
	for(let step=0;step<240&&!showdown;step++)
	{
		view=await settled(canvas);
		if(view.phase==='showdown')
		{
			if(view.reveal&&view.street==='seventh')showdown=view;
			else await page.keyboard.press('Enter');
			await page.waitForTimeout(120);
			continue;
		}
		if(view.turn!==0){await page.waitForTimeout(120);continue;}
		expect(view.ui.hint).toMatch(/^\dth street: |^3rd street: /i);
		await page.keyboard.press('c');
		await expect.poll(async()=>(await state(canvas)).revision).toBeGreaterThan(view.revision);
	}
	// Every state of every hand played, not only the ones this test happened to read: each street deals its card
	// with the right face, a live opponent shows exactly its exposed cards, and a folded one shows nothing.
	const order=['down','down','up','up','up','up','down'];
	const counts={third:3,fourth:4,fifth:5,sixth:6,seventh:7};
	const seen=new Set();
	for(const played of (await views(page)).filter(played=>played.phase==='betting'))
	{
		const at=`revision ${played.revision}, ${played.street} street`;
		seen.add(played.street);
		expect(played.players[0].cards.every(Number.isInteger),at).toBe(true);
		for(const player of played.players.filter(player=>!player.folded))
		{
			expect(player.faces,at).toEqual(order.slice(0,counts[played.street]));
			if(player.id!==0)expect(player.cards.map(card=>card===null?'down':'up'),at).toEqual(player.faces);
		}
		for(const player of played.players.filter(player=>player.folded&&player.id!==0))
			expect(player.cards.every(card=>card===null),at).toBe(true);
	}
	expect([...seen]).toEqual(expect.arrayContaining(['third','fourth','fifth','sixth','seventh']));
	expect(showdown.players[0].faces).toEqual(['down','down','up','up','up','up','down']);
	const live=showdown.players.filter(player=>!player.folded);
	expect(live.every(player=>player.cards.length===7&&player.cards.every(Number.isInteger)&&player.handName)).toBe(true);
	await expect(page.locator('[aria-live="polite"]').filter({hasText:'Your cards:'}).first()).toContainText(/\(down\).+\(up\)/);
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
