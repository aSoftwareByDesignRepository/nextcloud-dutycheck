// @ts-check
/**
 * Regression spec for intake dutycheck-intake-20261022T172140Z:
 *
 * .dc-date-display is the ONLY visible caption of a chromed date input
 * (native glyphs are color:transparent under .dc-date-field). Programmatic
 * `input.value = '…'` and form.reset() fire no input/change event, so the
 * overlay used to strand on the placeholder or the previous date:
 *
 *  - roster.js "Post open shift" toggle wrote dc-os-date = period start →
 *    the field looked empty while filled (captured 2026-10-22),
 *  - today.js jump() moved dc-today-date under a frozen overlay,
 *  - my-roster syncFormInputs() / team-week nav did the same,
 *  - form.reset() (periods/absences/my-absences) reverted values under a
 *    still-painted old date.
 *
 * Fix: the shared check-dates module shadows the input's value accessor and
 * wires a per-form reset listener (plus exported repaint helpers), so every
 * programmatic write repaints inline. These probes assert overlay text ===
 * DutyCheckDates.formatDisplayDate(input.value) — computed, never
 * locale-matched — on the real pages.
 */
import { test, expect } from '@playwright/test'
import { assertNotServerUpdater, credsFromEnv, login, loginWithFallback, plannerCredsCandidates } from './helpers/auth.js'

/**
 * Overlay state of one chromed date input.
 * @param {import('@playwright/test').Page} page
 * @param {string} id
 */
const chromeState = (page, id) => page.evaluate((inputId) => {
	const input = document.getElementById(inputId)
	if (!input) return null
	const wrap = input.closest('.dc-date-field')
	const overlay = wrap ? wrap.querySelector(':scope > .dc-date-display') : null
	return {
		value: input.value,
		overlay: overlay ? overlay.textContent : null,
		placeholder: input.getAttribute('data-dc-date-placeholder') || '',
		expected: input.value ? window.DutyCheckDates.formatDisplayDate(input.value) : '',
	}
}, id)

/** Overlay must echo the formatted live value — never the placeholder. */
async function expectChromeInSync(page, id, label) {
	const state = await chromeState(page, id)
	expect(state, `${label}: input #${id} must exist`).not.toBeNull()
	expect(state.value, `${label}: #${id} must carry a value`).not.toBe('')
	expect(state.overlay, `${label}: #${id} must have a mounted .dc-date-display`).not.toBeNull()
	expect(state.overlay, `${label}: overlay shows the live formatted value`)
		.toBe(state.expected)
	expect(state.overlay, `${label}: overlay must not strand on the placeholder`)
		.not.toBe(state.placeholder)
	return state
}

const stubRosterWithPeriod = async (page, period) => {
	await page.route('**/apps/dutycheck/api/periods/ensure-calendar-month**', (route) =>
		route.fulfill({
			status: 200,
			contentType: 'application/json',
			body: JSON.stringify({
				ok: true,
				data: { period, created: false, writable: true, yearMonth: '2026-10' },
			}),
		}))
	await page.route('**/apps/dutycheck/api/roster**', async (route) => {
		let parsed = {}
		try {
			const response = await route.fetch()
			parsed = JSON.parse(await response.text())
		} catch { /* torn-down context — empty envelope */ }
		const envelope = parsed && typeof parsed === 'object' && parsed.data && typeof parsed.data === 'object'
			? parsed
			: { data: {} }
		await route.fulfill({
			status: 200,
			contentType: 'application/json',
			body: JSON.stringify({
				...envelope,
				data: {
					...(envelope.data || {}),
					periods: [period],
					selectedPeriodId: period.id,
					selectedPeriodStatus: 'open',
					canCreateAssignments: true,
					employees: [{ id: 900001, displayName: 'Anna Meier', active: true }],
					locations: [{ id: 1, name: 'Hall', timezone: 'Europe/Berlin' }],
					assignments: [],
					openShifts: [],
				},
			}),
		})
	})
	await page.route('**/apps/dutycheck/api/open-shifts**', (route) =>
		route.fulfill({
			status: 200,
			contentType: 'application/json',
			body: JSON.stringify({ ok: true, data: [] }),
		}))
}

test.describe('date chrome repaint — planner surfaces', () => {
	test.skip(() => plannerCredsCandidates().length === 0, 'Requires E2E_* or NC_ADMIN_* credentials')

	test('post-open-shift overlay shows the prefilled period start (reported evidence)', async ({ page }) => {
		await loginWithFallback(page, plannerCredsCandidates())
		const period = {
			id: 909031,
			status: 'open',
			startDate: '2026-10-01',
			endDate: '2026-10-31',
			name: 'E2E October month',
		}
		await stubRosterWithPeriod(page, period)

		await page.goto('/apps/dutycheck/roster?periodId=909031', { waitUntil: 'domcontentloaded' })
		await assertNotServerUpdater(page)
		await page.waitForSelector('#dc-roster-grid', { timeout: 30_000 })
		await expect(page.locator('#dc-roster-period-switcher'), 'roster data must load before driving the form')
			.toHaveValue('909031', { timeout: 30_000 })

		await page.locator('#dc-open-shift-create').click()
		await expect(page.locator('#dc-os-date')).toBeVisible()

		// The defect: dc-os-date rendered 'TT.MM.JJJJ' while .value was filled.
		const state = await expectChromeInSync(page, 'dc-os-date', 'post-open-shift date')
		expect(state.value).toBe('2026-10-01')
	})

	test('assignment modal date overlay repaints on open (edit path)', async ({ page }) => {
		await loginWithFallback(page, plannerCredsCandidates())
		const period = {
			id: 909032,
			status: 'open',
			startDate: '2026-10-01',
			endDate: '2026-10-31',
			name: 'E2E October month',
		}
		const assignment = {
			id: 909041,
			version: 3,
			kind: 'shift',
			dutyDate: '2026-10-17',
			employeeId: 900001,
			employeeName: 'Anna Meier',
			locationId: 1,
			locationName: 'Hall',
			startTime: '08:00',
			endTime: '12:00',
			breakMinutes: 30,
			note: '',
		}
		await stubRosterWithPeriod(page, period)
		await page.unroute('**/apps/dutycheck/api/roster**')
		await page.route('**/apps/dutycheck/api/roster**', async (route) => {
			await route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({
					data: {
						periods: [period],
						selectedPeriodId: period.id,
						selectedPeriodStatus: 'open',
						canCreateAssignments: true,
						employees: [{ id: 900001, displayName: 'Anna Meier', active: true }],
						locations: [{ id: 1, name: 'Hall', timezone: 'Europe/Berlin' }],
						assignments: [assignment],
						openShifts: [],
					},
				}),
			})
		})
		await page.route('**/apps/dutycheck/api/templates**', (route) =>
			route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [] }) }))
		await page.route('**/apps/dutycheck/api/planning-defaults**', (route) =>
			route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: {} }) }))
		await page.route('**/apps/dutycheck/api/roster/period/**', (route) =>
			route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: {} }) }))

		await page.goto('/apps/dutycheck/roster?periodId=909032', { waitUntil: 'domcontentloaded' })
		await assertNotServerUpdater(page)
		await page.waitForSelector('#dc-roster-grid', { timeout: 30_000 })
		await expect(page.locator('#dc-roster-period-switcher'))
			.toHaveValue('909032', { timeout: 30_000 })

		// A filled grid cell opens the edit modal for that assignment.
		const cell = page.locator('#dc-roster-grid .dc-roster-grid__cell--filled')
		await expect(cell.first(), 'stubbed assignment must render a filled cell')
			.toBeVisible({ timeout: 15_000 })
		await cell.first().click()
		await expect(page.locator('.dc-modal'), 'edit modal must open')
			.toBeVisible({ timeout: 10_000 })

		const state = await expectChromeInSync(page, 'dc-assignment-date', 'edit-assignment date')
		expect(state.value).toBe('2026-10-17')
	})

	test('today board prev/next/now keeps the date overlay in sync', async ({ page }) => {
		await loginWithFallback(page, plannerCredsCandidates())
		await page.goto('/apps/dutycheck/today', { waitUntil: 'domcontentloaded' })
		await assertNotServerUpdater(page)
		await page.waitForSelector('#dc-today-date', { timeout: 30_000 })

		const before = await expectChromeInSync(page, 'dc-today-date', 'today initial')
		await page.locator('#dc-today-next').click()
		const next = await expectChromeInSync(page, 'dc-today-date', 'today next-day')
		expect(next.value, 'next-day click must move the input value').not.toBe(before.value)
		await page.locator('#dc-today-now').click()
		const now = await expectChromeInSync(page, 'dc-today-date', 'today now')
		expect(now.value).toBe(before.value)
	})

	test('period form reset repaints cleared date overlays', async ({ page }) => {
		await loginWithFallback(page, plannerCredsCandidates())
		await page.goto('/apps/dutycheck/periods', { waitUntil: 'domcontentloaded' })
		await assertNotServerUpdater(page)
		await page.waitForSelector('#dc-period-start', { timeout: 30_000 })

		const start = page.locator('#dc-period-start')
		await start.fill('2031-04-01')
		await page.locator('#dc-period-end').fill('2031-04-30')
		let state = await chromeState(page, 'dc-period-start')
		expect(state.overlay).toBe(state.expected)

		// Direct reset() — the path every create handler takes on success.
		await page.evaluate(() => document.getElementById('dc-period-form').reset())
		await expect.poll(async () => (await chromeState(page, 'dc-period-start')).overlay)
			.toBe((await chromeState(page, 'dc-period-start')).placeholder)
		state = await chromeState(page, 'dc-period-end')
		expect(state.overlay, 'end-date overlay must clear to placeholder too')
			.toBe(state.placeholder)
	})
})

test.describe('date chrome repaint — employee surfaces', () => {
	test.use({ storageState: { cookies: [], origins: [] } })

	test('my-roster quick range buttons repaint the from/to overlays', async ({ page }) => {
		test.skip(!process.env.NC_EMPLOYEE_USER, 'Requires NC_EMPLOYEE_* credentials')
		await login(page, credsFromEnv('EMPLOYEE'))
		await page.goto('/apps/dutycheck/my-roster', { waitUntil: 'domcontentloaded' })
		await assertNotServerUpdater(page)
		await page.waitForSelector('#dc-my-roster-from', { timeout: 30_000 })

		await expectChromeInSync(page, 'dc-my-roster-from', 'my-roster from (initial)')
		await expectChromeInSync(page, 'dc-my-roster-to', 'my-roster to (initial)')

		await page.locator('#dc-my-roster-quickfilters .dc-quickfilters__btn[data-range="week"]').click()
		const from = await expectChromeInSync(page, 'dc-my-roster-from', 'my-roster from (week)')
		const to = await expectChromeInSync(page, 'dc-my-roster-to', 'my-roster to (week)')
		expect(from.value <= to.value, 'week range must stay ordered').toBe(true)

		await page.locator('#dc-my-roster-quickfilters .dc-quickfilters__btn[data-range="today"]').click()
		const today = await expectChromeInSync(page, 'dc-my-roster-from', 'my-roster from (today)')
		expect(today.value, 'today range from must equal to').toBe(
			(await chromeState(page, 'dc-my-roster-to')).value,
		)
	})
})
