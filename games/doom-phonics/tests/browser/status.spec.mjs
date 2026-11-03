import {test, expect} from '@playwright/test';

test('diagnostic samples leave the DOM idle until accessible game state changes', async ({page}) => {
	test.setTimeout(20000);

	await page.route('**/doom-status-fixture', route => route.fulfill({
		contentType: 'text/html'
		, body: '<div id="game-frame" style="width:640px;height:400px"><canvas width="640" height="400"></canvas></div>'
	}));

	await page.goto('/doom-status-fixture');

	const result = await page.evaluate(async () => {
		const {activate} = await import('/engine/packages/doom-phonics/browser/game.mjs');
		const {QuickControls} = await import('/engine/browser/QuickControls.mjs');
		const {SafeStorage} = await import('/engine/browser/SafeStorage.mjs');
		const {cacheProgramValidation} = await import('/engine/browser/GlesPrograms.mjs');
		const {cachePixelStoreQueries} = await import('/engine/browser/GlesPixelStore.mjs');
		const canvas = document.querySelector('canvas');
		const module = {}, session = {};

		class SilentAudio
		{

			close() {}
		}

		const close = activate({
			module
			, canvas
			, session
			, services: {
				QuickControls
				, SafeStorage
				, cacheProgramValidation
				, cachePixelStoreQueries
				, SdlAudio: SilentAudio
			}
		});

		// Let the initial resize observation settle before counting live updates.
		await new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve)));
		const readout = document.querySelector('.doom-phonics-readout');
		const back = document.querySelector('.doom-phonics-info-back');
		const state = {map: 'D2ST9', health: 100, armor: 0, weapon: 'Pistol', menu: {open: false}};

		const publish = () => module.doomStatus(JSON.stringify(state));
		publish();
		const observer = new MutationObserver(() => {});

		observer.observe(document.querySelector('#game-frame'), {subtree: true, attributes: true, childList: true});

		for(let index = 0; index < 30; index++)
		{
			state.frame = {renderCpuMs: index};
			state.position = [index, 0, 0, 0];
			publish();
		}

		const idleMutations = observer.takeRecords().length;
		const lastSample = session.doomState;

		state.health = 75;
		publish();
		const healthMutations = observer.takeRecords().length;
		const healthText = readout.textContent;
		publish();
		const repeatedHealthMutations = observer.takeRecords().length;

		state.menu = {
			open: true
			, screen: 'title'
			, selected: 'PHP Info'
			, phpInfo: {open: true, backHint: [10 / 3, 20 / 3, 80 / 3, 30 / 7]}
		};

		publish();
		const infoVisible = !back.hidden;
		const infoText = readout.textContent;
		observer.takeRecords();
		publish();
		const repeatedInfoMutations = observer.takeRecords().length;

		state.menu = {open: true, screen: 'title', selected: 'New Game'};
		publish();
		const infoHidden = back.hidden;
		const menuText = readout.textContent;
		observer.takeRecords();
		publish();
		const repeatedMenuMutations = observer.takeRecords().length;

		const diagnosticsAttribute = canvas.hasAttribute('data-doom-phonics');
		observer.disconnect();
		close();

		return {
			idleMutations
			, lastSample
			, healthMutations
			, healthText
			, repeatedHealthMutations

			, infoVisible
			, infoText
			, repeatedInfoMutations
			, infoHidden
			, menuText
			, repeatedMenuMutations
			, diagnosticsAttribute
			, disposed: !('doomState' in session) && !('doomStatus' in module)
		};
	});

	expect(result.idleMutations).toBe(0);
	expect(result.lastSample.frame.renderCpuMs).toBe(29);
	expect(result.lastSample.position).toEqual([29, 0, 0, 0]);

	expect(result.healthMutations).toBe(1);
	expect(result.healthText).toContain('75%');
	expect(result.repeatedHealthMutations).toBe(0);

	expect(result.infoVisible).toBe(true);
	expect(result.infoText).toBe('PHP information. Back returns to the title screen.');
	expect(result.repeatedInfoMutations).toBe(0);

	expect(result.infoHidden).toBe(true);
	expect(result.menuText).toBe('title: New Game');
	expect(result.repeatedMenuMutations).toBe(0);

	expect(result.diagnosticsAttribute).toBe(false);
	expect(result.disposed).toBe(true);
});
