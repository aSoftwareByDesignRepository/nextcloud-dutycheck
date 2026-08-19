// @ts-check
import { test, expect } from '@playwright/test'
import AxeBuilder from '@axe-core/playwright'
import { assertNotServerUpdater, loginWithFallback, plannerCredsCandidates } from './helpers/auth.js'

const EMPLOYEE_COUNT = 80

/**
 * @param {import('@playwright/test').Page} page
 */
async function stubLargeRoster(page) {
	await page.route('**/apps/dutycheck/api/roster**', async (route) => {
		const response = await route.fetch()
		const raw = await response.text()
		/** @type {Record<string, unknown>} */
		let parsed = {}
		try {
			parsed = JSON.parse(raw)
		} catch {
			await route.fulfill({ status: response.status(), body: raw, headers: response.headers() })
			return
		}
		const envelope = parsed && typeof parsed === 'object' && parsed.data && typeof parsed.data === 'object'
			? parsed
			: { data: parsed }
		const data = { ...(/** @type {Record<string, unknown>} */ (envelope.data) || {}) }
		const employees = []
		for (let i = 1; i <= EMPLOYEE_COUNT; i++) {
			employees.push({
				id: 900000 + i,
				displayName: `E2E Person ${String(i).padStart(2, '0')}`,
				active: true,
			})
		}
		const period = {
			id: 909001,
			status: 'open',
			startDate: '2026-08-03',
			endDate: '2026-08-09',
			name: 'E2E virtualization week',
		}
		const assignments = [
			{
				id: 909101,
				employeeId: 900001,
				employeeName: 'E2E Person 01',
				dutyDate: '2026-08-03',
				startTime: '08:00:00',
				endTime: '16:00:00',
				breakMinutes: 30,
				locationName: 'Hall',
				note: '',
			},
			{
				id: 909180,
				employeeId: 900080,
				employeeName: 'E2E Person 80',
				dutyDate: '2026-08-09',
				startTime: '09:00:00',
				endTime: '17:00:00',
				breakMinutes: 30,
				locationName: 'Hall',
				note: '',
			},
		]
		await route.fulfill({
			status: response.status(),
			contentType: 'application/json',
			body: JSON.stringify({
				...envelope,
				data: {
					...data,
					employees,
					periods: [period],
					selectedPeriodId: period.id,
					selectedPeriodStatus: 'open',
					canCreateAssignments: true,
					locations: Array.isArray(data.locations) && data.locations.length
						? data.locations
						: [{ id: 1, name: 'Hall' }],
					assignments,
					conflicts: [],
					absenceBlocks: [],
				},
			}),
		})
	})
}

test('roster page loads virtual window + scroller chrome', async ({ page }) => {
	test.skip(plannerCredsCandidates().length === 0, 'Requires E2E_* or NC_ADMIN_* or NC_EMPLOYEE_* credentials')
	await loginWithFallback(page, plannerCredsCandidates())
	await page.goto('/apps/dutycheck/roster', { waitUntil: 'domcontentloaded' })
	await assertNotServerUpdater(page)
	await page.waitForSelector('#dc-main-content', { timeout: 30000 })
	await page.waitForSelector('#dc-roster-grid-scroller', { timeout: 15000 })
	await page.waitForFunction(() => {
		const grid = document.getElementById('dc-roster-grid')
		const role = grid?.getAttribute('role') || ''
		return role === 'grid' || role === 'status'
	}, null, { timeout: 30000 })

	const loaded = await page.evaluate(() => {
		const api = window.DutyCheckVirtualWindow
		if (!api || typeof api.visibleRange !== 'function') {
			return { ok: false }
		}
		const range = api.visibleRange({
			total: 100,
			rowHeight: 10,
			viewportHeight: 50,
			scrollTop: 200,
			overscan: 6,
		})
		const scroller = document.getElementById('dc-roster-grid-scroller')
		const cs = scroller ? getComputedStyle(scroller) : null
		const gridRole = document.getElementById('dc-roster-grid')?.getAttribute('role') || ''
		const status = document.getElementById('dc-roster-grid-status')
		const stride = typeof api.pageStride === 'function'
			? api.pageStride({ rowHeight: 40, viewportHeight: 400 })
			: 0
		const unsized = api.visibleRange({
			total: 200,
			rowHeight: 44,
			viewportHeight: 0,
			scrollTop: 44 * 100,
			overscan: api.DEFAULT_OVERSCAN,
		})
		return {
			ok: true,
			start: range.start,
			end: range.end,
			overflow: cs ? cs.overflowY : '',
			status: Boolean(status),
			statusHidden: status?.getAttribute('aria-hidden') === 'true',
			scrollerTabIndex: scroller?.getAttribute('tabindex') || '',
			gridRole,
			stride,
			unsizedStart: unsized.start,
			unsizedEnd: unsized.end,
		}
	})
	expect(loaded.ok).toBeTruthy()
	expect(loaded.start).toBe(14)
	expect(loaded.end).toBe(31)
	expect(loaded.status).toBeTruthy()
	expect(loaded.statusHidden).toBeTruthy()
	expect(loaded.scrollerTabIndex).toBe('0')
	expect(loaded.stride).toBe(9)
	expect(loaded.unsizedStart).toBe(94)
	expect(loaded.unsizedEnd).toBe(130)
	expect(loaded.gridRole === 'grid' || loaded.gridRole === 'status').toBeTruthy()
	expect(loaded.overflow === 'auto' || loaded.overflow === 'scroll').toBeTruthy()
})

test('roster grid paints a bounded window for 80 people and keeps full JSON', async ({ page }) => {
	test.skip(plannerCredsCandidates().length === 0, 'Requires E2E_* or NC_ADMIN_* or NC_EMPLOYEE_* credentials')
	await stubLargeRoster(page)
	await loginWithFallback(page, plannerCredsCandidates())
	await page.goto('/apps/dutycheck/roster', { waitUntil: 'domcontentloaded' })
	await assertNotServerUpdater(page)
	await page.waitForSelector('#dc-roster-grid[role="grid"]', { timeout: 30000 })

	const stats = await page.evaluate(() => {
		const grid = document.getElementById('dc-roster-grid')
		const painted = grid
			? grid.querySelectorAll('.dc-roster-grid__row:not(.dc-roster-grid__row--head)').length
			: 0
		const rowCount = Number(grid?.getAttribute('aria-rowcount') || 0)
		const status = document.getElementById('dc-roster-grid-status')?.textContent || ''
		const firstName = grid?.querySelector('.dc-roster-grid__rowhead')?.textContent || ''
		return { painted, rowCount, status, firstName }
	})

	expect(stats.rowCount).toBe(EMPLOYEE_COUNT + 1)
	expect(stats.painted).toBeGreaterThan(0)
	expect(stats.painted).toBeLessThan(EMPLOYEE_COUNT)
	expect(stats.painted).toBeLessThanOrEqual(40)
	expect(stats.status).toMatch(/80/)
	expect(stats.firstName).toMatch(/E2E Person/)

	const scroller = page.locator('#dc-roster-grid-scroller')
	await scroller.evaluate((el) => {
		el.scrollTop = el.scrollHeight
	})
	await page.waitForFunction(() => {
		const status = document.getElementById('dc-roster-grid-status')?.textContent || ''
		return /80/.test(status) && /E2E Person 80/.test(document.getElementById('dc-roster-grid')?.textContent || '')
	}, null, { timeout: 10000 })

	const afterScroll = await page.evaluate(() => {
		const grid = document.getElementById('dc-roster-grid')
		const painted = grid
			? grid.querySelectorAll('.dc-roster-grid__row:not(.dc-roster-grid__row--head)').length
			: 0
		const names = Array.from(grid?.querySelectorAll('.dc-roster-grid__rowhead') || []).map((n) => n.textContent || '')
		return { painted, names }
	})
	expect(afterScroll.painted).toBeLessThan(EMPLOYEE_COUNT)
	expect(afterScroll.names.some((n) => /E2E Person 80/.test(n))).toBeTruthy()
	expect(afterScroll.names.some((n) => /E2E Person 01/.test(n))).toBeFalsy()

	const results = await new AxeBuilder({ page })
		.withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
		.include('#dc-roster-grid-wrap')
		.exclude('#dc-toasts')
		.analyze()
	expect(results.violations, JSON.stringify(results.violations, null, 2)).toEqual([])
})
