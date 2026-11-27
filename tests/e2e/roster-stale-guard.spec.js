// @ts-check
import { test, expect } from '@playwright/test'
import { assertNotServerUpdater, loginWithFallback, plannerCredsCandidates } from './helpers/auth.js'

/**
 * stale_response_render_clobber (planner roster): the period switcher retriggers
 * loadRoster() per change. A slower EARLIER response must not paint over the
 * newer selection — loadRoster/signals/coverage carry a generation guard
 * (rosterViewSeq); loadOpenShiftList carries openShiftSeq.
 *
 * Deterministic repro: hold the FIRST period's request at the route layer, let
 * the SECOND period's reload paint, then release the first — surface must stay
 * on the second.
 *
 * Fixture: two fresh far-future open periods seeded via the real API per run
 * and deleted in afterEach (open + empty periods are deletable). No pinned
 * fixture ids — earlier versions hardcoded purged seed rows (638/639).
 */
const RANGE_FIRST = { start: '2106-04-05', end: '2106-04-11' }
const RANGE_SECOND = { start: '2106-04-12', end: '2106-04-18' }
const FIRST_ONLY_MARKER = '2106-04-07' // column header date only in period FIRST
const SECOND_ONLY_MARKER = '2106-04-14' // column header date only in period SECOND

const createdPeriodIds = []
let periodFirst = 0
let periodSecond = 0

async function dcApi(page, method, path, body) {
	return page.evaluate(async ({ method, path, body }) => {
		const res = await fetch(path, {
			method,
			headers: { requesttoken: OC.requestToken, 'Content-Type': 'application/json' },
			...(body ? { body: JSON.stringify(body) } : {}),
		})
		return res.json().catch(() => ({}))
	}, { method, path, body })
}

async function cleanupPeriods(page) {
	for (const id of createdPeriodIds.splice(0)) {
		await dcApi(page, 'DELETE', `/apps/dutycheck/api/periods/${id}`).catch(() => {})
	}
}

test.beforeEach(async ({ page }) => {
	test.skip(plannerCredsCandidates().length === 0, 'Requires E2E_* or NC_ADMIN_* or NC_EMPLOYEE_* credentials')
	await loginWithFallback(page, plannerCredsCandidates())
	await page.goto('/apps/dutycheck/roster', { waitUntil: 'domcontentloaded' })
	const a = await dcApi(page, 'POST', '/apps/dutycheck/api/periods', { startDate: RANGE_FIRST.start, endDate: RANGE_FIRST.end })
	const b = await dcApi(page, 'POST', '/apps/dutycheck/api/periods', { startDate: RANGE_SECOND.start, endDate: RANGE_SECOND.end })
	periodFirst = Number(a?.data?.id ?? a?.data?.period?.id ?? 0)
	periodSecond = Number(b?.data?.id ?? b?.data?.period?.id ?? 0)
	for (const id of [periodFirst, periodSecond]) {
		if (id) {
			createdPeriodIds.push(id)
		}
	}
	if (!periodFirst || !periodSecond) {
		await cleanupPeriods(page)
		test.skip(true, 'Could not seed e2e periods')
	}
})

test.afterEach(async ({ page }) => {
	await cleanupPeriods(page)
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
		if (url.searchParams.get('periodId') === String(periodFirst)) {
			staleRequestSeen = true
			await staleGate
		}
		return route.continue()
	})

	try {
		await page.goto(`/apps/dutycheck/roster?periodId=${periodSecond}`, { waitUntil: 'domcontentloaded' })
		await assertNotServerUpdater(page)
		await page.waitForSelector(`#dc-roster-grid [data-duty-date="${SECOND_ONLY_MARKER}"]`, { timeout: 30000 })

		// Switch to the other period — its request is held at the route layer.
		await page.locator('#dc-roster-period-switcher').selectOption(String(periodFirst))
		await expect.poll(() => staleRequestSeen, { timeout: 10000 }).toBe(true)

		// Switch back before the held request resolves — the newer selection wins.
		await page.locator('#dc-roster-period-switcher').selectOption(String(periodSecond))
		await page.waitForSelector(`#dc-roster-grid [data-duty-date="${SECOND_ONLY_MARKER}"]`, { timeout: 30000 })

		// Release the stale request — its late response must be dropped, not painted.
		releaseStale?.()
		await page.waitForTimeout(1500)

		await expect(page).toHaveURL(new RegExp(`periodId=${periodSecond}`))
		await expect(page.locator('#dc-roster-period-switcher')).toHaveValue(String(periodSecond))
		await expect(page.locator(`#dc-roster-grid [data-duty-date="${SECOND_ONLY_MARKER}"]`).first()).toBeVisible()
		await expect(page.locator(`#dc-roster-grid [data-duty-date="${FIRST_ONLY_MARKER}"]`)).toHaveCount(0)
	} finally {
		releaseStale?.()
		await page.unrouteAll({ behavior: 'ignoreErrors' }).catch(() => {})
	}
})
