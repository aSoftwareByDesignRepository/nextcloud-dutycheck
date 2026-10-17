// @ts-check
import { test, expect } from '@playwright/test'
import { assertNotServerUpdater, loginWithFallback, plannerCredsCandidates } from './helpers/auth.js'

/**
 * stale_response_render_clobber (planner roster): the period switcher retriggers
 * loadRoster() per change. A slower EARLIER response must not paint over the
 * newer selection — loadRoster/signals/coverage carry a generation guard
 * (rosterViewSeq); loadOpenShiftList carries openShiftSeq.
 *
 * Deterministic repro: hold the periodId=638 REQUEST at the route layer, let
 * the periodId=639 reload paint, then release 638 — surface must stay on 639.
 */
const PERIOD_FIRST = 638 // 2027-04-08 → 2027-04-14
const PERIOD_SECOND = 639 // 2027-04-15 → 2027-04-21
const FIRST_ONLY_MARKER = '2027-04-09' // column header date only in period 638
const SECOND_ONLY_MARKER = '2027-04-16' // column header date only in period 639

test.beforeEach(async ({ page }) => {
	test.skip(plannerCredsCandidates().length === 0, 'Requires E2E_* or NC_ADMIN_* or NC_EMPLOYEE_* credentials')
	await loginWithFallback(page, plannerCredsCandidates())
})

test('stale period response cannot clobber the newer selection', async ({ page }) => {
	/** @type {(() => void)|null} */
	let releaseStale = null
	const staleGate = new Promise((resolve) => { releaseStale = resolve })
	let staleRequestSeen = false

	// Hold the OUTBOUND request for the first-selected period — its response
	// therefore arrives strictly after the newer selection's paint.
	await page.route('**/apps/dutycheck/api/roster**', async (route) => {
		const url = new URL(route.request().url())
		if (url.searchParams.get('periodId') === String(PERIOD_FIRST)) {
			staleRequestSeen = true
			await staleGate
		}
		return route.continue()
	})

	try {
		await page.goto(`/apps/dutycheck/roster?periodId=${PERIOD_SECOND}`, { waitUntil: 'domcontentloaded' })
		await assertNotServerUpdater(page)
		await page.waitForSelector(`#dc-roster-grid [data-duty-date="${SECOND_ONLY_MARKER}"]`, { timeout: 30000 })

		// Switch to the older period — its request is held at the route layer.
		await page.locator('#dc-roster-period-switcher').selectOption(String(PERIOD_FIRST))
		await expect.poll(() => staleRequestSeen, { timeout: 10000 }).toBe(true)

		// Switch back before the held request resolves — the newer selection wins.
		await page.locator('#dc-roster-period-switcher').selectOption(String(PERIOD_SECOND))
		await page.waitForSelector(`#dc-roster-grid [data-duty-date="${SECOND_ONLY_MARKER}"]`, { timeout: 30000 })

		// Release the stale request — its late response must be dropped, not painted.
		releaseStale?.()
		await page.waitForTimeout(1500)

		await expect(page).toHaveURL(new RegExp(`periodId=${PERIOD_SECOND}`))
		await expect(page.locator('#dc-roster-period-switcher')).toHaveValue(String(PERIOD_SECOND))
		await expect(page.locator(`#dc-roster-grid [data-duty-date="${SECOND_ONLY_MARKER}"]`).first()).toBeVisible()
		await expect(page.locator(`#dc-roster-grid [data-duty-date="${FIRST_ONLY_MARKER}"]`)).toHaveCount(0)
	} finally {
		releaseStale?.()
		await page.unrouteAll({ behavior: 'ignoreErrors' }).catch(() => {})
	}
})
