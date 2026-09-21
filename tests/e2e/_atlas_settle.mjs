/**
 * Condition-based settle for one-shot Atlas capture helpers.
 * Never use waitForTimeout as a wait strategy — wait for DOM/network/paint.
 *
 * @param {import('@playwright/test').Page} page
 * @param {{ selector?: string, networkIdle?: boolean }} [opts]
 */
export async function settle(page, opts = {}) {
	const { selector, networkIdle = true } = opts
	await page.waitForLoadState('domcontentloaded')
	if (networkIdle) {
		try {
			await page.waitForLoadState('networkidle', { timeout: 5000 })
		} catch {
			/* some NC pages keep long-polls; fall through to paint */
		}
	}
	await page.evaluate(
		() =>
			new Promise((resolve) => {
				requestAnimationFrame(() => requestAnimationFrame(resolve))
			}),
	)
	if (selector) {
		await page.locator(selector).first().waitFor({ state: 'visible', timeout: 15000 })
	}
}
