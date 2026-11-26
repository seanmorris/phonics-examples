import {test,expect} from '@playwright/test';
import {execFileSync} from 'node:child_process';
import {readFile} from 'node:fs/promises';
import {MatrixFixture} from './fixtures/terrain-race-room.mjs';

test('durable ghost, verified successor, live race, spectator, recovery and rematch',async({browser,baseURL},info)=>{
	test.setTimeout(600000);
	const file=info.outputPath('course.json');
	execFileSync('php',['games/terrain-sandbox/tests/online-circuit.php'],{env:{...process.env,TERRAIN_RACE_FIXTURE:file}});
	const fixture=JSON.parse(await readFile(file,'utf8'));
	const room=new MatrixFixture(), contexts=[];
	const notice=p=>p.locator('.terrain-race-card p[role=status]');
	const button=(p,name)=>p.getByRole('button',{name,exact:true}).first();
	const click=(p,name)=>button(p,name).click({force:true});
	async function player(name) {
		const context=await browser.newContext({viewport:{width:740,height:500},baseURL});
		contexts.push(context);
		await room.attach(context,'@'+name+':example.test');
		await context.addInitScript(()=>{
			// This test exercises PHP simulation and the real network channels. Other specs cover rasterization.
			for(const method of ['drawElements','drawElementsInstanced','drawArrays','drawArraysInstanced'])
				WebGL2RenderingContext.prototype[method]=()=>{};
			const Original=window.RTCPeerConnection;
			window.raceConnections=[];
			window.RTCPeerConnection=class extends Original {
				constructor(...args){super(...args);window.raceConnections.push(this);}
			};
		});
		const p=await context.newPage();
		p.setDefaultTimeout(90000);
		await p.goto('/games/terrain-sandbox/');
		await p.waitForFunction(()=>document.querySelector('canvas')?.terrainSandboxDebug?.state?.mode,null,{timeout:120000});
		await p.evaluate(world=>document.querySelector('canvas').terrainSandboxDebug.action({type:'load',json:JSON.stringify(world)}),fixture.spec.world);
		await p.waitForFunction(()=>document.querySelector('canvas')?.terrainSandboxDebug?.state?.settings?.waterLevel===-5);
		await p.locator('[data-online-race]').evaluate(e=>e.click());
		await p.waitForSelector('.phonics-room-game.is-connected');
		return p;
	}
	try {
		const author=await player('pilot');
		await author.locator('.terrain-race-card input[type=file]').setInputFiles(file);
		await click(author,'Post my ghost');
		await expect(author.locator('.terrain-race-card select option')).toHaveCount(1);
		await author.context().close();

		const challenger=await player('racer');
		await click(challenger,'Preview ghost');
		await expect(notice(challenger)).toContainText('Verified finish',{timeout:90000});
		await click(challenger,'Race selected ghost');
		const start=p=>p.waitForFunction(()=>/^Go!|^0:/.test(document.querySelector('.terrain-race-bar span')?.textContent??''),null,{timeout:120000});
		const accelerate=p=>p.evaluate(()=>document.querySelector('canvas').terrainSandboxDebug.keys({KeyW:true}));
		await start(challenger);
		await accelerate(challenger);
		await expect(notice(challenger)).toContainText('Faster',{timeout:90000});
		await click(challenger,'Post my ghost');
		await expect(challenger.locator('.terrain-race-card select option')).toHaveCount(2);

		const host=await player('leader');
		await click(host,'Preview ghost');
		await expect(notice(host)).toContainText('@racer:example.test',{timeout:90000});
		await click(host,'Open a table');
		await host.getByRole('dialog').getByRole('button',{name:'Open table',exact:true}).click();
		await click(challenger,'Preview course');
		await expect(notice(challenger)).toContainText('Hosted by');
		await click(challenger,'Join');
		await Promise.all([start(host),start(challenger)]);

		const watcher=await player('watcher');
		await click(watcher,'Watch');
		await watcher.waitForFunction(()=>window.raceConnections.some(c=>c.connectionState==='connected'),null,{timeout:90000});
		await host.evaluate(async()=>{
			// Exercise the same recovery notification the browser emits when its interface briefly drops.
			const peer=window.raceConnections[0];
			Object.defineProperty(peer,'connectionState',{configurable:true,value:'disconnected'});
			peer.dispatchEvent(new Event('connectionstatechange'));
			await new Promise(resolve=>setTimeout(resolve,250));
			delete peer.connectionState;
			peer.dispatchEvent(new Event('connectionstatechange'));
		});
		await expect(notice(host)).toContainText('reconnected',{timeout:30000});
		await Promise.all([accelerate(host),accelerate(challenger)]);
		for(const p of [host,challenger,watcher]) await expect(notice(p)).toContainText(' / ',{timeout:180000});
		expect(await notice(host).textContent()).toBe(await notice(challenger).textContent());
		await expect(button(watcher,'Rematch')).toHaveCount(0);
		await click(host,'Rematch');
		await click(challenger,'Decline');
	} finally {
		for(const context of contexts) await context.close();
	}
});
