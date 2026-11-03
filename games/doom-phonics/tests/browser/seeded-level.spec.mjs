import {test, expect} from '@playwright/test';
import fs from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import {execFileSync} from 'node:child_process';

test('generation records timings separately while retaining reproducible output', async() => {
	const directory = await fs.mkdtemp(path.join(os.tmpdir(), 'phonics-seeded-timing-'));

	try
	{
		const args = ['packages/doom-phonics/tools/generate-seeded.php'
			, '--output=' + directory
			, '--timings='
			+ path.join(directory, 'timing.json')
		];

		const output = execFileSync('php', args, {encoding: 'utf8'});
		expect(output).toMatch(/Time: [\d.]+ ms total/);
		const measured = JSON.parse(await fs.readFile(path.join(directory, 'timing.json'), 'utf8'));
		expect(measured.seed).toBe('first-light');
		expect(measured.doorChance).toBe(30);
		expect(measured.roomsPerLayer).toBe(14);
		const {totalMs,...phases} = measured.timings;
		expect(totalMs).toBeGreaterThan(0);

		for(const value of Object.values(phases)) expect(value).toBeGreaterThanOrEqual(0);
		expect(Object.values(phases).reduce((a, b) => a+b, 0)).toBeCloseTo(totalMs, 5);
		const files = ['SEEDED.WAD','seeded-level.phonics-world.json','seeded-navigation.html'];
		const before = await Promise.all(files.map(name => fs.readFile(path.join(directory, name))));
		execFileSync('php', args);

		for(const [i,name] of files.entries()) expect(await fs.readFile(path.join(directory, name))).toEqual(before[i]);
		expect(measured.wadBytes).toBe(before[0].length);
	}
	finally
	{
		await fs.rm(directory, {recursive: true,force: true});
	}
});

test('seed report exposes layered geometry, repairs and the flood prerequisite', async({page}, testInfo) => {
	await page.goto('/engine/packages/doom-phonics/demo/seeded-navigation.html');
	const report = await page.locator('#data').textContent().then(JSON.parse);
	const regionCount = Object.keys(report.regions).length;

	await expect(page.locator('#verdict')).toContainText(`${regionCount}/${regionCount} regions reachable`);
	await expect(page.locator('#repairs')).toContainText('clearance');
	await expect(page.locator('#repairs > li')).toHaveCount(report.repairs.length);
	await expect(page.locator('#repairs')).toContainText('layer-height');
	await expect(page.locator('#route')).toContainText('fill:');
	await expect(page.locator('#maps svg')).toHaveCount(4);
	expect(await page.locator('#maps polygon').count()).toBeGreaterThan(20);

	expect(report.layers[report.roles.courtyard[0]].cluster.kind).toBe('conforming');
	expect(report.layers[report.roles.courtyard[0]].cluster.intrusion.entrances.length).toBeGreaterThanOrEqual(2);
	expect(report.layers[report.roles.courtyard[0]].cluster.outdoors.rim).not.toBeNull();
	expect(report.layers[report.roles.courtyard[0]].cluster.outdoors.trench).toBeUndefined();
	expect(Object.values(report.layers).filter(layer => layer.elevation).length).toBeGreaterThan(0);
	expect(report.layers[report.roles.courtyard[0]].cluster.pattern).toBe('outline-first');
	expect(report.layers[report.roles.courtyard[0]].cluster.minimumRoomSize).toBe(384);
	expect(report.layers[report.roles.courtyard[0]].cluster.outline.length).toBeGreaterThan(4);
	expect(report.layers[report.roles.hub[0]].cluster.doorways).toHaveLength(4);

	expect(report.doors.some(door => door.kind === 'connection')).toBe(true);
	await expect(page.locator('p').first()).toContainText(`${report.doors.length} doors`);
	expect(report.doors.some(door => door.width === 128)).toBe(true);
	expect(report.verticalConnections.length).toBeGreaterThanOrEqual(3);
	await expect(page.locator('[data-portal]')).toHaveCount(2*report.verticalConnections.length);

	expect(Object.values(report.topology).reduce((n, network) => n+network.loops, 0)).toBeGreaterThan(0);
	expect(report.requestedCandidates).toBe(42);
	expect(report.survivingRooms).toBeGreaterThan(6);

	await expect(page.locator('#play')).toHaveAttribute('href', /\/games\/doom-phonics\/\?world=.*seeded-level/);
	await page.locator('#actions').uncheck();
	await expect(page.locator('#verdict')).toContainText('Destination currently inaccessible');
	await page.locator('#water').selectOption('full');
	await expect(page.locator('#verdict')).toContainText(`${regionCount}/${regionCount} regions reachable`);

	const cluster = report.layers[report.roles.courtyard[0]];
	const [x,y] = cluster.architectural;
	const size = cluster.cluster.envelopeSize;
	const groundPlan = page.locator('#maps svg').nth(1);

	await page.locator('#maps').evaluate(container => {
		container.style.display = 'block';

		for(const [index,card] of [...container.children].entries())
			card.style.display = index === 1 ? 'block' : 'none';
	});

	await groundPlan.evaluate(svg => { svg.style.height = '720px'; });

	await groundPlan.evaluate((svg, bounds) => svg.setAttribute('viewBox', bounds), `${x} ${-y-size} ${size} ${size}`);
	await groundPlan.screenshot({path: testInfo.outputPath('seeded-cluster-plan.png')});
});

test('SEEDED loads, walks its flood and spiral route, and renders its portals', async({page}, testInfo) => {
	// Include every portal and the additional room-feature/stair inspection views.
	test.setTimeout(240000);
	const errors = [];
	page.on('pageerror', error => errors.push(error.message));

	page.on(
		'console'
		, message => { if(message.type() === 'error') errors.push(`${message.text()} (${message.location().url})`); }
	);

	await page.goto('/engine/packages/doom-phonics/demo/seeded-navigation.html');
	const report = await page.locator('#data').textContent().then(JSON.parse);
	await page.locator('#play').click();
	await page.locator('.game-embed-poster').click();
	await expect(page.locator('#status')).toHaveText('Ready', {timeout: 60000});

	const state = () => page.evaluate(() => previewSession.doomState);
	await expect.poll(async() => (await state())?.menu?.screen).toBe('title');
	await page.locator('canvas').focus();
	await page.keyboard.press('Enter');
	await expect.poll(async() => (await state()).menu.screen).toBe('skill');
	await page.keyboard.press('Enter');
	await expect.poll(async() => (await state()).menu.open).toBe(false);
	await expect.poll(async() => (await state()).wipe.active, {timeout: 30000}).toBe(false);
	expect((await state()).map).toBe('SEEDED');
	expect((await state()).unsupportedSpecials).toEqual([]);

	const run = source => page.evaluate(async source => {
		let result;
		const runtime = await previewSession.php.binary;

		runtime.seedProbe = json => { result = JSON.parse(String(json)); };

		const code = await previewSession.php.run('<?php ' + source);

		if(code !== 0) throw new Error(`Seed probe exited with ${code}`);

		return result;
	}, source
	).catch(error => {
		throw new Error(`${error.message}\n${errors.join('\n')}`);
	});

	const capture = async name => {
		// Inspection teleports bypass movement's camera reset. Wait for rendered
		// frames so the stair-height follower settles even on a slow renderer.
		await page.evaluate(async() => {
			for(let frame = 0; frame < 30; frame++) await new Promise(requestAnimationFrame);
		});

		await page.locator('canvas').screenshot({path: testInfo.outputPath(name)});
	};

	await run(String.raw`
		$engine->userPaused = true;
		$engine->setInputHandler(static fn() => true);
		$seedContent = (new ReflectionProperty($game,'lease'))->getValue($game)->context->content();
		$seedLevel = $seedContent->level;
		$seedPlayer = $engine->entities->query(DoomPhonics\Game\PlayerState::class)[0];
		$seedPose = $engine->entities->get($seedPlayer,DoomPhonics\Component\Transform::class);
		$seedView = $engine->entities->get($seedPlayer,DoomPhonics\Component\ViewInput::class);
	`
	);

	for(const name of ['../../tools/PlayerWalk'
		, '../../tools/RouteTraversal'
		, '../fixtures/LayeredWalkthrough'
		, '../fixtures/SeededWalkthrough'
	]){
		const fixture = await fs.readFile(new URL(`${name}.php`, import.meta.url), 'utf8');
		await run(fixture.replace(/^<\?php\s*/, '').replace(/^require_once .*;$/mg, ''));
	}

	const base64 = Buffer.from(JSON.stringify(report)).toString('base64');

	const trace = await run(`
		$seedReport = json_decode(base64_decode('${base64}'),true);
		$seedPrototype = $seedReport;
		$seedPrototype['regions'] = $seedReport['sectorIds'];
		$seedTrace = DoomPhonics\\Tests\\SeededWalkthrough::run($seedLevel,$seedPrototype,$seedReport['goalRoute']);
		$seedSpiral = DoomPhonics\\Tests\\SeededWalkthrough::spiral($seedLevel,$seedReport['layers']['spiral']);
		(vrzno_env('seedProbe'))(json_encode(['goal'=>end($seedTrace),'spiral'=>end($seedSpiral)]));
	`
	);

	expect(trace.goal.region).toBe(report.goal);
	expect(trace.spiral.z).toBe(report.layers.spiral.height);

	for(const portal of report.verticalConnections)
	{
		if(report.cells[report.sectorIds[portal.to]].ceiling > 0) continue;

		const encoded = Buffer.from(JSON.stringify(portal)).toString('base64');

		const swim = await run(`
			$seedSwim=DoomPhonics\\Tests\\SeededWalkthrough::swimPortal($seedLevel,$seedPrototype,json_decode(base64_decode('${encoded}'),true));
			(vrzno_env('seedProbe'))(json_encode($seedSwim));
		`
		);

		const crossing = swim.frames.findIndex(frame => frame.sector === swim.target);
		expect(crossing).toBeGreaterThanOrEqual(0);
		expect(swim.frames.slice(crossing).every(frame => frame.sector === swim.target)).toBe(true);
		expect(swim.end.sector).toBe(swim.rim);
		const frame = swim.frames[crossing];

		await run(`
			$seedPose->x=$seedPose->previousX=${frame.x}; $seedPose->y=$seedPose->previousY=${frame.y};
			$seedPose->z=$seedPose->previousZ=${frame.z}; $seedPose->angle=.4; $seedView->setPitch(.7);
			$engine->entities->get($seedPlayer,DoomPhonics\\Component\\Body::class)->grounded=false;
		`
		);

		await capture(`seeded-swim-exit-${portal.from}.png`);
	}

	const wellStart = `${report.roles.hub[0]}.ring-7`;
	const wellEnd = `${report.roles.pool[0]}.arrival`;
	const wellQueue = [{region: wellStart, route: []}];
	const wellSeen = new Set();
	let wellRoute;

	for(const item of wellQueue)
	{
		if(wellSeen.has(item.region)) continue;

		wellSeen.add(item.region);

		if(item.region === wellEnd)
		{
			wellRoute = item.route;
			break;
		}

		for(const edge of report.edges.filter(e => e.from === item.region && !Object.keys(e.effects).length))
			wellQueue.push({region: edge.to, route: [...item.route, edge]});
	}

	expect(wellRoute.some(edge => edge.mode === 'door')).toBe(true);
	const wellBase64 = Buffer.from(JSON.stringify(wellRoute)).toString('base64');

	const fall = await run(`
		$seedWell = $seedPrototype;
		$seedWell['start'] = '${wellStart}';
		$seedDrop = DoomPhonics\\Tests\\SeededWalkthrough::run($seedLevel,$seedWell,json_decode(base64_decode('${wellBase64}'),true));
		(vrzno_env('seedProbe'))(json_encode(end($seedDrop)));
	`
	);

	expect(fall.region).toBe(wellEnd);

	// Exercise every extra shaft with real movement from its surrounding floor.
	// Fresh fixtures begin dry; drain the live basin through its actual control.
	await run(String.raw`
		$drain=array_values(array_filter($seedPrototype['actions'],static fn($a)=>$a['id']==='drain-high'))[0];
		$drainPose=new DoomPhonics\Component\Transform(...[...$drain['point'],$drain['angle']]);
		$drainSpecials=new DoomPhonics\Game\LineSpecials($seedLevel);
		$drainSpecials->use($drainPose,new DoomPhonics\Game\PlayerState);
		for($i=0;$i<200;$i++) $drainSpecials->update(1/35);
	`
	);

	for(const [index,portal] of report.verticalConnections.entries())
	{
		if(!['shaft','gallery'].includes(portal.kind)) continue;

		const start = portal.from.replace(/\.(shaft|gallery-aperture)$/, '.core');
		const drop = report.edges.find(e => e.mode === 'floor-portal' && e.from === portal.from);
		const destination = drop.to;
		const queue = [{region: start,route: []}];
		const seen = new Set();
		let route;

		for(const item of queue)
		{
			if(seen.has(item.region)) continue;

			seen.add(item.region);

			if(item.region === destination)
			{
				route = item.route;
				break;
			}

			for(const edge of report.edges.filter(
				e => e.from === item.region && !Object.keys(e.effects).length && (!e.requires.water
					|| e.requires.water.includes('dry')
				)
			))
				queue.push({region: edge.to,route: [...item.route,edge]});
		}

		expect(route.some(edge => edge.mode === 'floor-portal' && edge.from === portal.from)).toBe(true);
		const encoded = Buffer.from(JSON.stringify(route)).toString('base64');

		const landing = await run(`
			$shaftPrototype=$seedPrototype; $shaftPrototype['start']='${start}';
			$shaftTrace=DoomPhonics\\Tests\\SeededWalkthrough::run($seedLevel,$shaftPrototype,json_decode(base64_decode('${encoded}'),true));
			(vrzno_env('seedProbe'))(json_encode(end($shaftTrace)));
		`
		);

		expect(landing.sector).toBe(report.sectorIds[destination]);

		// Inspect the floor hole from its rim and the matching ceiling from below.
		const views = [
			['floor',portal.from,-.45,portal.kind === 'gallery' ? -280 : -160]
			, ['ceiling',portal.to,.65,-192]
		];

		for(const [side,name,pitch,offset] of views)
		{
			const cell = report.cells[report.sectorIds[name]];

			await run(`
				$seedPose->x=$seedPose->previousX=${cell.center[0]+offset};
				$seedPose->y=$seedPose->previousY=${cell.center[1]};
				$seedPose->z=$seedPose->previousZ=${cell.floor+(side === 'floor' ? 32 : 0)};
				$seedPose->angle=0; $seedView->setPitch(${pitch});
			`
			);

			await capture(`seeded-shaft-${index}-${side}.png`);
		}
	}

	const door = report.doors.find(door => door.kind === 'portal');
	const face = door.faces.find(f => f.neighbor.endsWith('.outer'));
	const poseX = face.point[0] - Math.cos(face.angle)*64 - Math.sin(face.angle)*56;
	const poseY = face.point[1] - Math.sin(face.angle)*64 + Math.cos(face.angle)*56;
	const doorZ = report.cells[report.sectorIds[door.region]].floor;
	const doorAngle = Math.atan2((face.from[1]+face.to[1])/2-poseY, (face.from[0]+face.to[0])/2-poseX);

	await run(`
		$seedPose->x = $seedPose->previousX = ${poseX};
		$seedPose->y = $seedPose->previousY = ${poseY};
		$seedPose->z = $seedPose->previousZ = ${doorZ};
		$seedPose->angle = ${doorAngle}; $seedView->setPitch(.4);
	`
	);

	await capture('seeded-door-lintel.png');

	const openDoor = await run(`
		$seedDoorUse = new DoomPhonics\\Game\\LineSpecials($seedLevel);
		$seedDoorControl = new DoomPhonics\\Component\\Transform(${face.point[0]},${face.point[1]},${doorZ},${face.angle});
		$seedDoorActivated = $seedDoorUse->use($seedDoorControl,new DoomPhonics\\Game\\PlayerState);
		for($i=0;$i<80;$i++) $seedDoorUse->update(1/35);
		(vrzno_env('seedProbe'))(json_encode(['used'=>$seedDoorActivated,'ceiling'=>$seedLevel->sectors[${report.sectorIds[door.region]}]->ceiling]));
	`
	);

	expect(openDoor.used).toBe(true);
	expect(openDoor.ceiling).toBe(doorZ+door.height-4);
	await capture('seeded-door-open.png');

	const switchAction = report.actions.find(a => a.id === 'fill-high');

	await run(`
		$seedPose->x = $seedPose->previousX = ${switchAction.point[0]-64};
		$seedPose->y = $seedPose->previousY = ${switchAction.point[1]-32};
		$seedPose->z = $seedPose->previousZ = ${switchAction.point[2]};
		$seedPose->angle = ${Math.atan2(32, 112)}; $seedView->setPitch(0);
	`
	);

	await capture('seeded-switch-patch.png');
	const [x, y] = report.layers[report.roles.pool[0]].packed;

	await run(`
		$seedPose->x = $seedPose->previousX = ${x + 640};
		$seedPose->y = $seedPose->previousY = ${y + 960};
		$seedPose->z = $seedPose->previousZ = 0;
		$seedPose->angle = -M_PI / 2;
		$seedView->setPitch(-.5);
	`
	);

	await expect.poll(async() => (await state()).position[0]).toBe(x + 640);
	await capture('seeded-pool.png');
	const spiral = report.layers.spiral;
	const [sx, sy] = spiral.packed;
	const landing = spiral.cells.find(cell => cell.id === 'spiral.landing-in');

	await run(`
		$seedPose->x = $seedPose->previousX = ${sx + (landing.x0 + landing.x1) / 2};
		$seedPose->y = $seedPose->previousY = ${sy + (landing.y0 + landing.y1) / 2};
		$seedPose->z = $seedPose->previousZ = ${landing.floor};
		$seedPose->angle = M_PI / 2;
		$seedView->setPitch(.25);
	`
	);

	await expect.poll(async() => (await state()).position[2]).toBe(landing.floor);
	await capture('seeded-spiral.png');

	// Select authored roles and reservations instead of fixed room indices.
	const detailedRooms = Object.entries(report.layers).filter(
		([,l]) => l.feature || l.terrace || l.reservations?.length
	);

	for(const [name,layer] of detailedRooms)
	{
		const hall = layer.reservations?.find(r => r.kind === 'stair-hall');
		const stairs = layer.cells.filter(c => c.role === 'stair-hall');
		const cell = layer.terrace
			? layer.cells.find(c => c.id === layer.terrace.route[0])
			: (hall ? stairs.at(-1) : layer.cells.find(c => c.id.endsWith('.core')) ?? layer.cells[0]);

		const next = layer.terrace && layer.cells.find(c => c.id === layer.terrace.route[1]);
		const angle = next
			? Math.atan2(next.center[1]-cell.center[1], next.center[0]-cell.center[0])
			: hall ? Math.atan2(-hall.inward[1], -hall.inward[0]) : .5;

		await run(`
			$seedPose->x=$seedPose->previousX=${layer.packed[0]+cell.center[0]};
			$seedPose->y=$seedPose->previousY=${layer.packed[1]+cell.center[1]};
			$seedPose->z=$seedPose->previousZ=${layer.packed[2]+cell.floor};
			$seedPose->angle=${angle}; $seedView->setPitch(.1);
		`
		);

		await capture(`seeded-feature-or-stairs-${name}.png`);
	}

	for(const [name,local,angle,pitch] of [
		[report.roles.courtyard[0],report.layers[report.roles.courtyard[0]].cells[0].center,Math.PI/4,0]
		, [report.roles.hub[0]
			, report.layers[report.roles.hub[0]].cells.find(c => c.id === wellStart).center
			, Math.PI/2,-.65
		]
	]) {
		const layer = report.layers[name];

		await run(`
			$seedPose->x = $seedPose->previousX = ${layer.packed[0]+local[0]};
			$seedPose->y = $seedPose->previousY = ${layer.packed[1]+local[1]};
			$seedPose->z = $seedPose->previousZ = ${layer.packed[2]+layer.localFloor};
			$seedPose->angle = ${angle}; $seedView->setPitch(${pitch});
		`
		);

		await capture(`seeded-${name}.png`);
	}

	// Inspect exterior roofs and openings from the courtyard and garden.
	const outdoor = report.layers[report.roles.courtyard[0]].cluster.outdoors;

	for(const [label,id] of [['courtyard', outdoor.courtyard[0]], ['garden', outdoor.garden[0]]])
	{
		const target = report.cells[report.sectorIds[id]];
		const ledge = target;
		const [x,y] = ledge.center;
		const angle = label === 'garden' ? Math.atan2(target.center[1]-y, target.center[0]-x) : .7;

		await run(`
			$seedPose->x = $seedPose->previousX = ${x};
			$seedPose->y = $seedPose->previousY = ${y};
			$seedPose->z = $seedPose->previousZ = ${ledge.floor};
			$seedPose->angle = ${angle}; $seedView->setPitch(-.2);
		`
		);

		await capture(`seeded-${label}.png`);
	}

	for(const [name,layer] of Object.entries(report.layers).filter(([,l]) => l.elevation?.kind === 'stairs'))
	{
		const route = layer.elevation.route;
		const first = report.cells[report.sectorIds[route[0]]];
		const last = report.cells[report.sectorIds[route.at(-1)]];
		const low = first.floor < last.floor ? first : last;
		const high = low === first ? last : first;
		const angle = Math.atan2(high.center[1]-low.center[1], high.center[0]-low.center[0]);

		await run(`
			$seedPose->x=$seedPose->previousX=${low.center[0]};
			$seedPose->y=$seedPose->previousY=${low.center[1]};
			$seedPose->z=$seedPose->previousZ=${low.floor};
			$seedPose->angle=${angle}; $seedView->setPitch(0);
		`
		);

		await capture(`seeded-elevation-${name}.png`);
	}

	const fullDoor = report.doors.find(d => d.width === 128);

	for(const [index,side] of fullDoor.faces.entries())
	{
		const floor = report.cells[report.sectorIds[fullDoor.region]].floor;

		await run(`
			$seedPose->x=$seedPose->previousX=${side.point[0]};
			$seedPose->y=$seedPose->previousY=${side.point[1]};
			$seedPose->z=$seedPose->previousZ=${floor};
			$seedPose->angle=${side.angle}; $seedView->setPitch(.2);
		`
		);

		await capture(`seeded-wide-door-${index}.png`);
	}

	const render = await run(String.raw`
		(vrzno_env('seedProbe'))(json_encode(['error'=>glGetError()]));
	`
	);

	expect(render.error).toBe(0);
	expect(errors).toEqual([]);
	await page.click('#stop');
	await expect(page.locator('#status')).toHaveText('Stopped');
});


test('generated terminal lift works repeatedly through live keyboard input', async({page}) => {
	test.setTimeout(150000);
	const directory = await fs.mkdtemp(path.join(os.tmpdir(), 'phonics-seeded-lift-'));
	let report;

	try
	{
		execFileSync(
			'php'
			, ['packages/doom-phonics/tools/generate-seeded.php'
				, '--seed=1'
				, '--rooms=3'
				, '--layout=building'
				, '--output='
				+ directory
			]
		);

		for(const name of ['SEEDED.WAD','seeded-level.phonics-world.json','seeded-navigation.html'])
		{
			const body = await fs.readFile(path.join(directory, name));
			// A distinct world URL keeps this generated seed outside the PHAR's bundled demo.
			const servedName = name.endsWith('.json') ? 'seeded-lift.phonics-world.json' : name;

			await page.route(
				url => url.pathname.endsWith('/' + servedName)
				, route => route.fulfill(
					{
						body

						, contentType: name.endsWith('.json')
							? 'application/json'
							: name.endsWith('.html') ? 'text/html' : 'application/octet-stream'
					}
				)
			);

			if(name.endsWith('.html')) report = JSON.parse(body.toString().match(/id="data">(.*?)<\/script>/s)[1]);
		}
	}
	finally
	{
		await fs.rm(directory, {recursive: true,force: true});
	}

	await page.goto('/games/doom-phonics/?world=/engine/packages/doom-phonics/demo/seeded-lift.phonics-world.json');
	await page.locator('.game-embed-poster').click();
	await expect(page.locator('#status')).toHaveText('Ready', {timeout: 60000});
	await expect.poll(() => page.evaluate(() => previewSession.doomState?.menu?.screen)).toBe('title');

	await page.locator('canvas').focus();
	await page.keyboard.press('Enter');
	await expect.poll(() => page.evaluate(() => previewSession.doomState?.menu?.screen)).toBe('skill');
	await page.keyboard.press('Enter');
	await expect.poll(() => page.evaluate(() => previewSession.doomState?.menu?.open)).toBe(false);
	await expect.poll(() => page.evaluate(() => previewSession.doomState?.wipe?.active)).toBe(false);
	const lift = report.lifts[0];
	expect(lift).toBeTruthy();

	const run = source => page.evaluate(async source => {
		let value;
		const runtime = await previewSession.php.binary;

		runtime.liftTest = json => {value = JSON.parse(String(json));};

		const code = await previewSession.php.run('<?php ' + source);

		if(code !== 0) throw Error('Lift probe failed: ' + code);

		return value;
	}, source
	);

	const platformId = report.sectorIds[lift.platform];

	await run(`
		$liveContent = (new ReflectionProperty($game,'lease'))->getValue($game)->context->content();
		$liveLevel = $liveContent->level;
		$liveId = $engine->entities->query(DoomPhonics\\Game\\PlayerState::class)[0];
		$livePose = $engine->entities->get($liveId,DoomPhonics\\Component\\Transform::class);
		$livePose->x = $livePose->previousX = ${lift.lowPoint[0]};
		$livePose->y = $livePose->previousY = ${lift.lowPoint[1]};
		$livePose->z = $livePose->previousZ = ${lift.lowPoint[2]};
		$livePose->angle = ${lift.angle};
	`
	);

	const floor = () => run(`(vrzno_env('liftTest'))(json_encode($liveLevel->sectors[${platformId}]->floor));`);

	const walkInto = async name => {
		await page.keyboard.down('w');
		const c = report.regions[name];

		await page.waitForFunction(c => {
			const [x,y] = previewSession.doomState.position;

			return x>c.x0+2&&x<c.x1-2&&y>c.y0+2&&y<c.y1-2;
		}, c, {timeout: 10000,polling: 'raf'});

		await page.keyboard.up('w');
	};

	const approachCallPoint = async point => {
		await expect.poll(() => run(String.raw`
			$velocity=$engine->entities->get($liveId,DoomPhonics\Component\Velocity::class);
			(vrzno_env('liftTest'))(json_encode(hypot($velocity->x,$velocity->y)));
		`
		), {timeout: 10000}).toBeLessThan(1);

		const [x,y] = await page.evaluate(() => previewSession.doomState.position);

		if(Math.hypot(x-point[0], y-point[1])<24) return;

		await run(`$livePose->angle = ${Math.atan2(point[1]-y, point[0]-x)};`);
		await page.keyboard.down('w');

		await page.waitForFunction(point => {
			const [x,y] = previewSession.doomState.position;

			return Math.hypot(x-point[0], y-point[1])<24;
		}, point, {timeout: 10000,polling: 'raf'}).catch(async error => {
			const position = await page.evaluate(() => previewSession.doomState.position);
			throw new Error(
				`${error.message}; call point ${JSON.stringify(point)}, position ${JSON.stringify(position)}`
			);
		});

		await page.keyboard.up('w');
	};

	for(let visit = 0; visit<2; visit++)
	{
		await run(`$livePose->angle = ${lift.angle};`);
		// Walking out carries momentum past the call range; approach each face again.
		await approachCallPoint(lift.lowPoint);
		await run(`$livePose->angle = ${lift.angle};`);
		await page.keyboard.press('e', {delay: 100});
		await expect.poll(floor, {timeout: 10000}).toBe(lift.lowPoint[2]);
		await walkInto(lift.platform);
		await expect.poll(floor, {timeout: 10000}).toBe(lift.highPoint[2]);
		await walkInto(lift.high);

		await expect.poll(() => run(String.raw`
			$velocity=$engine->entities->get($liveId,DoomPhonics\Component\Velocity::class);
			(vrzno_env('liftTest'))(json_encode(hypot($velocity->x,$velocity->y)));
		`
		), {timeout: 10000}).toBeLessThan(1);

		await run(`$livePose->angle = ${lift.angle+Math.PI};`);
		await approachCallPoint(lift.highPoint);
		await run(`$livePose->angle = ${lift.angle+Math.PI};`);
		await page.keyboard.press('e', {delay: 100});
		await expect.poll(floor, {timeout: 10000}).toBe(lift.lowPoint[2]);
		await walkInto(lift.platform);
		await walkInto(lift.low);
		await expect.poll(floor, {timeout: 10000}).toBe(lift.highPoint[2]);
	}

	await page.click('#stop');
});
