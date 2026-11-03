import {test, expect} from '@playwright/test';

for(const role of ['host', 'guest'])
test(`a disconnected ${role} returns to the menu and can start another game`, async ({page}, testInfo) => {
	const errors = [];
	page.on('pageerror', error => errors.push(error.message));

	page.on('console', message => {
		if(message.type() === 'error' && /PHP Fatal|Uncaught|ExitStatus/.test(message.text()))
		errors.push(message.text());
	});

	await page.goto('/doom-phonics/');
	await expect(page.locator('#status')).toHaveText('Ready', {timeout: 60000});
	const canvas = page.locator('canvas');

	const state = async () => page.evaluate(() => window.previewSession?.doomState);
	await expect.poll(async () => (await state())?.menu?.screen).toBe('title');

	await page.evaluate(async role => {
		const {LobbyController, NetworkSession, ResourceNegotiator, WebRtcTransport, MessageFamily}
			= await import('/engine/browser/Networking.mjs');

		const {ResourceStore} = await import('/engine/browser/ResourceStore.mjs');
		const local = previewSession.network;
		const provider = new EventTarget;

		provider.connect = async () => ({id: 'remote'});

		provider.close = () => {};

		const remote = new NetworkSession({
			controller: new LobbyController({provider, transportFactory: options => new WebRtcTransport(options)})
			, negotiator: new ResourceNegotiator({
				world: local.world
				, store: new ResourceStore
				, inspectors: local.negotiator.inspectors
			})
			, world: local.world
			, packages: local.packages
			, application: local.application
			, startDelay: 50
		});

		local.startDelay = 50;

		// Route SDP/ICE directly between real RTC peers, without contacting Matrix.

		local.controller.provider.connect = async () => ({id: 'local'});

		local.controller.provider.signalPeer = async (peerId, payload) => remote.controller.receiveSignal(
			{from: 'local', payload}
		);

		provider.signalPeer = async (peerId, payload) => local.controller.receiveSignal({from: 'remote', payload});
		await Promise.all([local.controller.connect(), remote.controller.connect()]);
		remote.addEventListener('resourcesready', () => remote.gameReady());
		window.disconnectErrors = [];
		previewSession.php.addEventListener('error', event => disconnectErrors.push(event.detail.join('')));

		for(const peer of [local, remote])
		peer.addEventListener('error', ({detail}) => disconnectErrors.push(detail.message));

		const wait = (target, type) => new Promise(resolve => target.addEventListener(type, resolve, {once: true}));

		const connect = async () => {
			const host = role === 'host' ? local : remote, guest = role === 'guest' ? local : remote;
			const prepared = await host.prepareInvitation({settings: {mode: 'deathmatch'}});
			const sessionId = crypto.randomUUID();
			const invitation = {payload: {...prepared.publicPayload, sessionId}};
			host.controller.outgoing = {privatePayload: prepared.privatePayload};
			const started = Promise.all([wait(local, 'start'), wait(remote, 'start')]);

			await Promise.all([host, guest].map(peer => peer.controller.connectPeer({
				peerId: peer === local ? 'remote' : 'local'
				, sessionId
				, initiator: peer === host
				, invitation
			})));

			let timer;

			try
			{
				await Promise.race([
					started
					, new Promise((resolve, reject) => {
						timer = setTimeout(() => {
							reject(new Error(JSON.stringify({
								local: local.controller.state
								, remote: remote.controller.state
								, errors: disconnectErrors
							})));
						}, 15000
						);
					})
				]);
			}
			finally
			{
				clearTimeout(timer);
			}
		};

		window.disconnectPeers = {local, remote, connect, MessageFamily};
		await connect();
	}, role
	);

	await expect.poll(async () => (await state()).menu.open).toBe(false);
	await expect.poll(async () => (await state()).wipe.active).toBe(false);

	await expect.poll(
		() => page.evaluate(() => disconnectPeers.remote.bridge.diagnostics().queuedPackets)
	).toBeGreaterThan(0);

	// Closing a data channel exercises the abrupt path; the host case receives a clean goodbye.
	await page.evaluate(role => {
		const {remote} = disconnectPeers;

		if(role === 'guest') remote.transport.channels.get('control').close();
		else remote.leaveMatch();
	}, role
	);

	await expect.poll(async () => (await state()).menu.screen).toBe('disconnected');
	await expect.poll(async () => (await state()).wipe.active).toBe(false);
	expect((await state()).paused).toBe(true);
	await canvas.screenshot({path: testInfo.outputPath('disconnected.png')});

	const cleaned = await page.evaluate(async () => {
		const module = await previewSession.php.binary;
		let result;

		module.recordDisconnectState = value => { result = JSON.parse(String(value)); };

		const exit = await previewSession.php.run(String.raw`<?php
			(vrzno_env('recordDisconnectState'))(json_encode([
				'networkPlayers' => count($engine->entities->query(DoomPhonics\Component\NetworkPlayer::class)),
				'receive' => $engine->systems->has('episode:E1M1/network-receive'),
				'send' => $engine->systems->has('episode:E1M1/network-send'),
			]));
		`
		);

		delete module.recordDisconnectState;
		const {local, MessageFamily} = disconnectPeers;

		return {
			exit
			, result
			, match: local.matchState()
			, packets: local.bridge.receive()
			, sent: module.phonicsNetworkSend('realtime', MessageFamily.input, 9244, 1, '{}')
		};
	});

	expect(cleaned).toEqual({
		exit: 0
		, result: {networkPlayers: 0, receive: false, send: false}
		, match: null
		, packets: '[]'
		, sent: false
	});

	// Reuse this PHP runtime and network session for a second match, then leave via the actual pause menu.
	await page.evaluate(() => disconnectPeers.connect());
	await expect.poll(async () => (await state()).menu.open).toBe(false);
	await expect.poll(async () => (await state()).wipe.active).toBe(false);
	await canvas.focus();
	await page.keyboard.press('Escape');
	await expect.poll(async () => (await state()).menu.screen).toBe('pause');
	await page.keyboard.press('ArrowUp');
	await expect.poll(async () => (await state()).menu.selected).toBe('title');
	await page.keyboard.press('Enter');
	await expect.poll(async () => (await state()).menu.screen).toBe('title');
	await expect.poll(() => page.evaluate(() => disconnectPeers.remote.matchState())).toBeNull();
	await expect.poll(async () => (await state()).wipe.active).toBe(false);
	await page.keyboard.press('Enter');
	await expect.poll(async () => (await state()).menu.screen).toBe('skill');
	// Let SDL observe the first confirm's release before the next menu press.
	await page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));
	await page.keyboard.press('Enter');
	await expect.poll(async () => (await state()).menu.open).toBe(false);
	await expect.poll(async () => (await state()).wipe.active).toBe(false);
	// The final wipe frame can precede the menu releasing its input capture.
	await expect.poll(async () => (await state()).paused).toBe(false);
	await expect.poll(async () => (await state()).frame.simulationSteps).toBeGreaterThan(0);
	const position = (await state()).position;
	await page.keyboard.down('w');
	await expect.poll(async () => (await state()).position.slice(0, 2)).not.toEqual(position.slice(0, 2));
	await page.keyboard.up('w');
	expect(await page.evaluate(() => disconnectErrors)).toEqual([]);
	expect(errors).toEqual([]);
	await page.evaluate(() => disconnectPeers.remote.close());
	await page.click('#stop');
	await expect(page.locator('#status')).toHaveText('Stopped');
});
