// @ts-check
import { test, expect } from '@playwright/test'
import { assertNotServerUpdater, loginWithFallback, plannerCredsCandidates } from './helpers/auth.js'

const PERIOD = {
	id: 909031,
	status: 'open',
	startDate: '2026-10-01',
	endDate: '2026-10-31',
	name: 'E2E October month',
}

const STANDBY_CATEGORY = {
	id: 77,
	companyId: 1,
	label: 'Nachtbereitschaft',
	color: '#3366aa',
	defaultResponseTimeMinutes: 45,
	active: true,
}

/**
 * Stub the calendar-month ensure + roster so the standby UI renders against a
 * deterministic payload regardless of live company settings.
 * @param {import('@playwright/test').Page} page
 * @param {{ standbyEnabled?: boolean }} opts
 */
async function stubStandbyRoster(page, { standbyEnabled = true } = {}) {
	await page.route('**/apps/dutycheck/api/periods/ensure-calendar-month**', async (route) => {
		await route.fulfill({
			status: 200,
			contentType: 'application/json',
			body: JSON.stringify({
				ok: true,
				data: { period: PERIOD, created: false, writable: true, yearMonth: '2026-10' },
			}),
		})
	})
	// Optional enrichment endpoints: stub empty so no passthrough fetch can
	// outlive the test and flake on teardown.
	await page.route('**/apps/dutycheck/api/roster/signals**', async (route) => {
		await route.fulfill({
			status: 200,
			contentType: 'application/json',
			body: JSON.stringify({ ok: true, data: { preferences: [], blackouts: [] } }),
		})
	})
	await page.route('**/apps/dutycheck/api/periods/*/publish-readiness', async (route) => {
		await route.fulfill({
			status: 200,
			contentType: 'application/json',
			body: JSON.stringify({ ok: true, data: { ready: true, hardConflicts: 0 } }),
		})
	})
	// Exact roster endpoint only — must NOT swallow /api/roster/signals.
	await page.route(/\/apps\/dutycheck\/api\/roster(\?.*)?$/, async (route) => {
		let parsed = {}
		try {
			const response = await route.fetch()
			const raw = await response.text()
			parsed = JSON.parse(raw)
		} catch {
			parsed = {}
		}
		const envelope = parsed && typeof parsed === 'object' && parsed.data && typeof parsed.data === 'object'
			? parsed
			: { data: parsed }
		const data = { ...(/** @type {Record<string, unknown>} */ (envelope.data) || {}) }
		await route.fulfill({
			status: 200,
			contentType: 'application/json',
			body: JSON.stringify({
				...envelope,
				data: {
					...data,
					standbyEnabled,
					standbyAcknowledgeEnabled: standbyEnabled,
					standbyCategories: standbyEnabled ? [STANDBY_CATEGORY] : [],
					employees: [{ id: 900001, displayName: 'Anna Meier', active: true }],
					periods: [PERIOD],
					selectedPeriodId: PERIOD.id,
					selectedPeriodStatus: 'open',
					canCreateAssignments: true,
					locations: [{ id: 1, name: 'Hall' }],
					assignments: [
						{
							id: 909301,
							employeeId: 900001,
							employeeName: 'Anna Meier',
							dutyDate: '2026-10-01',
							startTime: '08:00:00',
							endTime: '16:00:00',
							breakMinutes: 30,
							locationName: 'Hall',
							kind: 'shift',
							note: '',
						},
						{
							id: 909302,
							employeeId: 900001,
							employeeName: 'Anna Meier',
							dutyDate: '2026-10-02',
							startTime: '00:00:00',
							endTime: '00:00:00',
							breakMinutes: 0,
							locationName: 'Hall',
							kind: 'standby',
							standbyCategoryId: STANDBY_CATEGORY.id,
							standbyCategoryLabel: STANDBY_CATEGORY.label,
							standbyCategoryColor: STANDBY_CATEGORY.color,
							responseTimeMinutes: 45,
							note: '',
						},
					],
					conflicts: [],
					absenceBlocks: [],
				},
			}),
		})
	})
}

test.beforeEach(async ({ page }) => {
	test.skip(plannerCredsCandidates().length === 0, 'Requires E2E_* or NC_ADMIN_* or NC_EMPLOYEE_* credentials')
	await loginWithFallback(page, plannerCredsCandidates())
})

test('standby duty renders with distinct styling, tag, and legend chip', async ({ page }) => {
	await stubStandbyRoster(page)
	await page.goto('/apps/dutycheck/roster?periodId=909031', { waitUntil: 'domcontentloaded' })
	await assertNotServerUpdater(page)
	await page.waitForSelector('#dc-roster-grid[role="grid"]', { timeout: 30000 })

	// Legend chip becomes visible when the company flag is on.
	await expect(page.locator('#dc-legend-chip-standby')).toBeVisible()

	// The standby cell carries the kind marker + phone icon + category label.
	const standbyCell = page.locator('.dc-roster-grid__cell--standby')
	await expect(standbyCell).toHaveCount(1)
	await expect(standbyCell).toHaveAttribute('data-shift-band', 'standby')
	await expect(standbyCell.locator('.dc-roster-grid__standby-icon')).toBeVisible()
	await expect(standbyCell.locator('.dc-roster-grid__standby-tag')).toHaveText('Nachtbereitschaft')

	// Assignment list: standby badge + 24h label + dash for break.
	// The table only renders in the "List" view (grid is the default).
	await page.locator('#dc-roster-view-list').click()
	const row = page.locator('#dc-assignments-table-body tr', { hasText: 'Nachtbereitschaft' })
	await expect(row.locator('.dc-badge--standby')).toBeVisible()
	await expect(row).toContainText('—')
})

test('kind selector toggles standby fields and forces zero break', async ({ page }) => {
	await stubStandbyRoster(page)
	await page.goto('/apps/dutycheck/roster?periodId=909031', { waitUntil: 'domcontentloaded' })
	await assertNotServerUpdater(page)
	await page.waitForSelector('#dc-roster-add-assignment:not([disabled])', { timeout: 30000 })
	await page.locator('#dc-roster-add-assignment').click()

	// Kind group visible only when the flag is on; shift selected by default.
	const kindGroup = page.locator('#dc-assignment-kind-group')
	await expect(kindGroup).toBeVisible()
	await expect(page.locator('#dc-assignment-kind-shift')).toBeChecked()
	await expect(page.locator('#dc-assignment-standby-fields')).toBeHidden()
	await expect(page.locator('#dc-assignment-break-field')).toBeVisible()

	// Picking standby reveals category/response fields, hides break + templates.
	await page.locator('label[for="dc-assignment-kind-standby"]').click()
	await expect(page.locator('#dc-assignment-standby-fields')).toBeVisible()
	await expect(page.locator('#dc-assignment-break-field')).toBeHidden()
	await expect(page.locator('#dc-assignment-template-field')).toBeHidden()

	// Choosing a category prefills its default response time when empty.
	await page.locator('#dc-assignment-standby-category').selectOption(String(STANDBY_CATEGORY.id))
	await expect(page.locator('#dc-assignment-response-time')).toHaveValue('45')
})

test('standby create posts kind=standby with zero break and allows equal times', async ({ page }) => {
	await stubStandbyRoster(page)
	/** @type {Record<string, unknown>|null} */
	let posted = null
	await page.route('**/apps/dutycheck/api/assignments', async (route) => {
		if (route.request().method() !== 'POST') {
			await route.continue()
			return
		}
		posted = route.request().postDataJSON()
		await route.fulfill({
			status: 200,
			contentType: 'application/json',
			body: JSON.stringify({ ok: true, data: { id: 909399, version: 1 } }),
		})
	})
	await page.goto('/apps/dutycheck/roster?periodId=909031', { waitUntil: 'domcontentloaded' })
	await assertNotServerUpdater(page)
	await page.waitForSelector('#dc-roster-add-assignment:not([disabled])', { timeout: 30000 })
	await page.locator('#dc-roster-add-assignment').click()

	await page.locator('label[for="dc-assignment-kind-standby"]').click()
	await page.locator('#dc-assignment-employee').selectOption('900001')
	await page.locator('#dc-assignment-location').selectOption('1')
	await page.locator('#dc-assignment-date').fill('2026-10-05')
	// 24h standby: equal start/end must not be rejected for standby.
	await page.locator('#dc-assignment-start').fill('00:00')
	await page.locator('#dc-assignment-end').fill('00:00')
	await page.locator('#dc-assignment-standby-category').selectOption(String(STANDBY_CATEGORY.id))

	await page.getByRole('button', { name: /Save assignment|[Ss]peichern/ }).click()
	await expect.poll(() => posted !== null, { timeout: 15000 }).toBe(true)
	// The API client transports ids/minutes as form strings; assert on the
	// semantic values, not the transport types.
	expect(posted?.kind).toBe('standby')
	expect(Number(posted?.employeeId)).toBe(900001)
	expect(Number(posted?.locationId)).toBe(1)
	expect(Number(posted?.breakMinutes)).toBe(0)
	expect(Number(posted?.standbyCategoryId)).toBe(STANDBY_CATEGORY.id)
	expect(Number(posted?.responseTimeMinutes)).toBe(45)
})

test('flag off hides the kind selector and standby legend', async ({ page }) => {
	await stubStandbyRoster(page, { standbyEnabled: false })
	await page.goto('/apps/dutycheck/roster?periodId=909031', { waitUntil: 'domcontentloaded' })
	await assertNotServerUpdater(page)
	await page.waitForSelector('#dc-roster-add-assignment:not([disabled])', { timeout: 30000 })
	await page.locator('#dc-roster-add-assignment').click()

	await expect(page.locator('#dc-assignment-kind-group')).toBeHidden()
	await expect(page.locator('#dc-legend-chip-standby')).toBeHidden()
	await expect(page.locator('#dc-assignment-standby-fields')).toBeHidden()
	await expect(page.locator('#dc-assignment-break-field')).toBeVisible()
})
