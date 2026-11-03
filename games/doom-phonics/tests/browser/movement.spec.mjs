import {test,expect} from '@playwright/test';

async function exercisePeers(page, scenario = 'movement')
{
	await page.goto('/sane-phonics/');
	await page.waitForFunction(() => typeof window.bootEngine === 'function');
	await page.evaluate(() => window.bootEngine({run: false,version: '8.4'}));

	return page.evaluate(async scenario => {
		const module = await enginePhp.binary;
		const root = '/engine/packages/doom-phonics/';
		const manifest = await (await fetch(root + 'phonics.json')).json();
		const files = [...manifest.php.files, ...manifest.assets
			, 'demo/DOOM1.WAD', 'tests/fixtures/EpisodeMovement.php'
		];

		if(scenario === 'beams') files.push('demo/SKULLTAG-WEAPONS.WAD', 'demo/SKULLTAG-WEAPONS.DEC');

		const shooting = scenario !== 'movement';

		await Promise.all(files.map(async file => {
			const response = await fetch(root + file);

			if(!response.ok) throw Error(`${file}: ${response.status}`);

			const destination = root + file;
			module.FS.mkdirTree(destination.slice(0, destination.lastIndexOf('/')));
			module.FS.writeFile(destination, new Uint8Array(await response.arrayBuffer()));
		}));

		const {WebRtcTransport} = await import('/engine/browser/network/WebRtcTransport.mjs');
		const {NetworkBridge} = await import('/engine/browser/network/NetworkBridge.mjs');
		const {encodePacket} = await import('/engine/browser/network/Protocol.mjs');
		const peers = {};
		const bridges = {host: new NetworkBridge, guest: new NetworkBridge};
		const received = {host: 0, guest: 0};
		const samples = {host: [], guest: []};
		const errors = [];

		const phpError = event => errors.push(event.detail.join(''));
		enginePhp.addEventListener('error', phpError);

		module.recordMovement = (side, json) => samples[String(side)].push(JSON.parse(String(json)));

		const run = async source => {
			const code = await enginePhp.run('<?php ' + source);

			if(code !== 0 || errors.length) throw Error(`PHP ${code}: ${errors.join('')}`);
		};

		try
		{
			// Exchange SDP and ICE directly, with no lobby or Matrix service.
			for(const side of ['host', 'guest'])
			{
				const other = side === 'host' ? 'guest' : 'host';

				peers[side] = new WebRtcTransport({
					polite: side === 'host'
					, sendSignal: signal => peers[other].receiveSignal(JSON.parse(JSON.stringify(signal)))
				});

				peers[side].addEventListener('message', ({detail}) => {
					received[side]++;
					bridges[side].push(detail.channel, detail.data);
				});

				peers[side].addEventListener('error', ({detail}) => errors.push(detail.message));
			}

			await Promise.all([peers.host.start(), peers.guest.start({initiator: true})]);

			await run(String.raw`
				require '/engine/autoload.php';
				require '/engine/packages/doom-phonics/autoload.php';
				require '/engine/packages/doom-phonics/tests/fixtures/EpisodeMovement.php';
				$wad = new DoomPhonics\Archive\Stack(
					new DoomPhonics\Archive\Archive(file_get_contents('/engine/packages/doom-phonics/demo/DOOM1.WAD'), 'DOOM1.WAD')
					${scenario === 'beams'
						? String.raw`, new DoomPhonics\Archive\Archive(file_get_contents('/engine/packages/doom-phonics/demo/SKULLTAG-WEAPONS.WAD'), 'SKULLTAG-WEAPONS.WAD')`
						: ''}
				);
				$source = new DoomPhonics\Adapter\WadSource($wad
					${scenario === 'beams'
						? ", ['skulltag.dec' => file_get_contents('/engine/packages/doom-phonics/demo/SKULLTAG-WEAPONS.DEC')]"
						: ''}
				);
				$scenarios = [];
			`
			);

			for(const side of ['host', 'guest'])
			{
				const slot = side === 'host' ? 1 : 2;
				const other = side === 'host' ? 'guest' : 'host';
				const sequences = {control: 0, realtime: 0};

				module.phonicsNetworkReceive = limit => bridges[side].receive(limit);

				module.phonicsNetworkSend = (channel, family, tick, schema, payload) => peers[side].send(
					String(channel)
					, encodePacket({
						family: Number(family)
						, tick: Number(tick)
						, schema: Number(schema)
						, payload: String(payload)

						, session: 1
						, peer: slot
						, sequence: ++sequences[String(channel)]
					})
				);

				await run(String.raw`
					$scenarios['${side}'] = new DoomPhonics\Tests\EpisodeMovement($source, new DoomPhonics\Game\DoomMatch(
						new Phonics\Network\MatchContext('${side}', new Phonics\Network\PlayerSlot(${slot}),
							new Phonics\Network\PlayerSlot(${3 - slot}), new Phonics\Network\PeerId('${side}'),
							new Phonics\Network\PeerId('${other}'), 'sha256-AAAA=', ['settings' => ['mode' => 'cooperative']])
					));
					${scenario === 'rockets' ? `$scenarios['${side}']->armWeapon();` : ''}
					${scenario === 'beams' ? `$scenarios['${side}']->armWeapon('Railgun', 'Cell');` : ''}
					vrzno_env('recordMovement')('${side}', json_encode($scenarios['${side}']->advance(0)));
				`
				);
			}

			// Independent display cadences share a 35 Hz simulation. Yield for real RTC delivery.
			for(let frame = 1; frame <= (shooting ? 2880 : 1440); frame++)
			{
				const updates = [];

				if(shooting)
				{
					if(frame === 1)
					updates.push("$scenarios['host']->controls->set([DoomPhonics\\Input\\DoomActions::FIRE => 1]);");

					if(frame === 144) updates.push("$scenarios['host']->controls->set([]);");

					if(frame === 1441)
					updates.push("$scenarios['guest']->controls->set([DoomPhonics\\Input\\DoomActions::FIRE => 1]);");

					if(frame === 1584) updates.push("$scenarios['guest']->controls->set([]);");
				}

				if(frame % 12 === 0)
				updates.push("vrzno_env('recordMovement')('host', json_encode($scenarios['host']->advance(1 / 60))); ");

				if(frame % 5 === 0)
				updates.push(
					"vrzno_env('recordMovement')('guest', json_encode($scenarios['guest']->advance(1 / 144))); "
				);

				if(!updates.length) continue;

				await run(updates.join(''));
				await new Promise(resolve => setTimeout(resolve, 0));
			}

			return {samples, received, errors};
		}
		finally
		{
			await enginePhp.run("<?php foreach($scenarios ?? [] as $scenario) $scenario->scope->close();");

			for(const peer of Object.values(peers)) peer.close();

			for(const bridge of Object.values(bridges)) bridge.close();
			enginePhp.removeEventListener('error', phpError);
			await enginePhp.refresh();
		}
	}, scenario
	);
}

test('scheduled host and guest movement stays continuous over direct WebRTC', async({page}) => {
	const result = await exercisePeers(page);
	expect(result.errors).toEqual([]);

	for(const [side, slot, renderRate] of [['host', 1, 60], ['guest', 2, 144]])
	{
		expect(result.received[side]).toBeGreaterThan(10);
		const frames = result.samples[side];
		expect(frames).toHaveLength(renderRate * 2 + 1);
		const spawnX = frames[0].players[slot].x;

		for(let frame = 1; frame < frames.length; frame++)
		{
			const player = frames[frame].players[slot];
			expect(player.local).toBe(true);
			expect(player.x - player.previousX, `${side} interpolation span`).toBeLessThanOrEqual(420 / 35 + 1e-8);

			for(const id of [1, 2])
			expect(frames[frame].players[id].renderX, `${side} player ${id}, render frame ${frame}`)
				.toBeGreaterThanOrEqual(frames[frame - 1].players[id].renderX - 1e-8);
		}

		expect(frames.at(-1).players[slot].x).toBeGreaterThan(spawnX + 100);
		const remote = 3 - slot;
		expect(frames.at(-1).players[remote].local).toBe(false);
		expect(frames.at(-1).players[remote].x).toBeGreaterThan(frames[0].players[remote].x + 100);
	}
});


test('both peers see rocket flight, explosion and removal over direct WebRTC', async({page}) => {
	const result = await exercisePeers(page, 'rockets');
	expect(result.errors).toEqual([]);

	for(const side of ['host', 'guest'])
	{
		const frames = result.samples[side];
		const midpoint = Math.floor(frames.length / 2);

		for(const [shooter, samples] of [['host', frames.slice(0, midpoint)], ['guest', frames.slice(midpoint)]])
		{
			const rockets = samples.flatMap(frame => frame.rockets);
			expect(rockets.length, `${side} sees ${shooter}'s rocket`).toBeGreaterThan(0);
			expect(new Set(rockets.map(rocket => rocket.id)).size, 'one host identity per shot').toBe(1);
			expect(rockets.some(rocket => rocket.sprite === 'MISL' && rocket.label === 'Spawn')).toBe(true);
			expect(rockets.some(rocket => rocket.label === 'Death')).toBe(true);
			expect(new Set(rockets.map(rocket => rocket.x)).size).toBeGreaterThan(2);
			expect(samples.at(-1).rockets).toEqual([]);

			for(const rocket of rockets)
			{
				expect(rocket.replica).toBe(side === 'guest');
				expect(rocket.collides).toBe(side === 'host');
				expect(rocket.id).toBeGreaterThan(2);
				expect(Math.abs(rocket.x - rocket.previousX)).toBeLessThan(100);
			}
		}
	}

	const ids = side => [...new Set(result.samples[side].flatMap(frame => frame.rockets.map(rocket => rocket.id)))];
	expect(ids('guest')).toEqual(ids('host'));
});

test('both peers see one rail beam that fades without restarting over direct WebRTC', async({page}) => {
	const result = await exercisePeers(page, 'beams');
	expect(result.errors).toEqual([]);
	const shots = {host: [], guest: []};

	for(const side of ['host', 'guest'])
	{
		expect(result.received[side]).toBeGreaterThan(10);
		const frames = result.samples[side];
		const midpoint = Math.floor(frames.length / 2);

		for(const [shooter, samples] of [['host', frames.slice(0, midpoint)], ['guest', frames.slice(midpoint)]])
		{
			const beams = samples.flatMap(frame => frame.beams);
			expect(beams.length, `${side} sees ${shooter}'s beam`).toBeGreaterThan(5);
			expect(new Set(beams.map(beam => beam.id)).size, 'one host identity per rail shot').toBe(1);
			expect(samples.every(frame => frame.beams.length <= 1), 'no locally predicted duplicate').toBe(true);
			expect(samples.at(-1).beams).toEqual([]);
			expect(beams[0].remaining).toBeGreaterThan(.5);
			expect(beams.at(-1).remaining).toBeLessThan(.1);
			shots[side].push(beams[0]);

			for(const [index, beam] of beams.entries())
			{
				expect(beam.id).toBeGreaterThan(2);
				expect(beam.replica).toBe(side === 'guest');
				expect(beam.duration).toBe(.75);
				expect(beam.width).toBe(8);
				expect(beam.from).toEqual(beams[0].from);
				expect(beam.to).toEqual(beams[0].to);
				expect(beam.remaining).toBeGreaterThanOrEqual(0);

				if(index > 0)
				expect(beam.remaining, `${side} beam fade never restarts`)
					.toBeLessThanOrEqual(beams[index - 1].remaining + 1e-8);
			}
		}
	}

	for(const [index, host] of shots.host.entries())
	{
		const guest = shots.guest[index];
		expect(guest.id).toBe(host.id);

		for(const endpoint of ['from', 'to'])
		for(let axis = 0; axis < 3; axis++)
		expect(Math.abs(guest[endpoint][axis] - host[endpoint][axis])).toBeLessThanOrEqual(1 / 256);
	}
});
