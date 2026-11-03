/**
 * Let the menu consume the previous key press and its release before another selection.
 *
 * @param {import('@playwright/test').Page} page Page running the demo menu.
 * @returns {Promise<void>} Resolves after two published menu frames, including during loading.
 */
export async function waitForMenuFrames(page)
{
	await page.locator('canvas').evaluate(canvas => new Promise(resolve => {
		let frames = 0;

		const observer = new MutationObserver(records => {
			frames += records.length;

			if(frames >= 2)
			{
				observer.disconnect();
				resolve();
			}
		});

		observer.observe(canvas, {attributes: true, attributeFilter: ['data-menu']});
	}));
}
