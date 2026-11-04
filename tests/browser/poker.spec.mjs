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
	await command(canvas,{type:'draw',cards:[],revision:before.revision-1});
	await expect.poll(async()=>(await state(canvas)).error).not.toBe('');
	expect((await state(canvas)).revision).toBe(before.revision);
	await canvas.focus();
	await page.keyboard.press('1');
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
		const observer=new MutationObserver(records=>updates+=records.length);
		observer.observe(element,{attributes:true,attributeFilter:['data-php-poker']});
		setTimeout(()=>{observer.disconnect();resolve(updates);},600);
	}));
	expect(idle).toBe(0);
	expect(await page.evaluate(key=>localStorage.getItem(key),saveKey)).toBe(saved);
	await page.reload();
	await expect(canvas).toHaveAttribute('data-php-poker',/"screen":"menu"/,{timeout:45_000});
	const restored=await state(canvas);
	expect(restored.revision).toBe(settled.revision);
	expect(restored.players).toEqual(settled.players);
	expect(restored.phase).toBe('showdown');
	expect(errors).toEqual([]);
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
