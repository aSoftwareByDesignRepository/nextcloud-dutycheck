// @ts-check
import { test, expect } from '@playwright/test'
import AxeBuilder from '@axe-core/playwright'
import { assertNotServerUpdater, loginWithFallback, plannerCredsCandidates } from './helpers/auth.js'

const MONTH_DAYS = 31

/**
 * Stub a full October 2026 period — the Tobias Link month-grid repro.
 * @param {import('@playwright/test').Page} page
 */
async function stubMonthRoster(page) {
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
		const period = {
			id: 909031,
			status: 'open',
			startDate: '2026-10-01',
			endDate: '2026-10-31',
			name: 'E2E October month',
		}
		await route.fulfill({
			status: 200,
			contentType: 'application/json',
			body: JSON.stringify({
				...envelope,
				data: {
					...data,
					employees: [
						{ id: 900001, displayName: 'Anna Meier', active: true },
						{ id: 900002, displayName: 'Ben Keller', active: true },
						{ id: 900003, displayName: 'Clara Vogt', active: true },
						{ id: 900004, displayName: 'David Berg', active: true },
					],
					periods: [period],
					selectedPeriodId: period.id,
					selectedPeriodStatus: 'open',
					canCreateAssignments: true,
					locations: Array.isArray(data.locations) && data.locations.length
						? data.locations
						: [{ id: 1, name: 'Hall' }],
					assignments: [
						{
							id: 909201,
							employeeId: 900001,
							employeeName: 'Anna Meier',
							dutyDate: '2026-10-01',
							startTime: '08:00:00',
							endTime: '16:00:00',
							breakMinutes: 30,
							locationName: 'Hall',
							note: '',
						},
						{
							id: 909202,
							employeeId: 900002,
							employeeName: 'Ben Keller',
							dutyDate: '2026-10-01',
							startTime: '09:00:00',
							endTime: '17:00:00',
							breakMinutes: 30,
							locationName: 'Hall',
							note: '',
						},
						{
							id: 909203,
							employeeId: 900001,
							employeeName: 'Anna Meier',
							dutyDate: '2026-10-02',
							startTime: '08:00:00',
							endTime: '16:00:00',
							breakMinutes: 30,
							locationName: 'Hall',
							note: '',
						},
						{
							id: 909204,
							employeeId: 900003,
							employeeName: 'Clara Vogt',
							dutyDate: '2026-10-02',
							startTime: '12:00:00',
							endTime: '20:00:00',
							breakMinutes: 30,
							locationName: 'Hall',
							note: '',
						},
						{
							id: 909205,
							employeeId: 900004,
							employeeName: 'David Berg',
							dutyDate: '2026-10-03',
							startTime: '06:00:00',
							endTime: '14:00:00',
							breakMinutes: 30,
							locationName: 'Hall',
							note: '',
						},
						{
							id: 909206,
							employeeId: 900002,
							employeeName: 'Ben Keller',
							dutyDate: '2026-10-06',
							startTime: '08:00:00',
							endTime: '16:00:00',
							breakMinutes: 30,
							locationName: 'Hall',
							note: '',
						},
						{
							id: 909207,
							employeeId: 900003,
							employeeName: 'Clara Vogt',
							dutyDate: '2026-10-07',
							startTime: '10:00:00',
							endTime: '18:00:00',
							breakMinutes: 30,
							locationName: 'Hall',
							note: '',
						},
						{
							id: 909208,
							employeeId: 900004,
							employeeName: 'David Berg',
							dutyDate: '2026-10-08',
							startTime: '08:00:00',
							endTime: '16:00:00',
							breakMinutes: 30,
							locationName: 'Hall',
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

test('month period keeps one column per day without overlapping headers', async ({ page }) => {
	test.skip(plannerCredsCandidates().length === 0, 'Requires E2E_* or NC_ADMIN_* or NC_EMPLOYEE_* credentials')
	await stubMonthRoster(page)
	await loginWithFallback(page, plannerCredsCandidates())
	await page.setViewportSize({ width: 1100, height: 900 })
	// Pin periodId so rolling-month auto-ensure does not race the October stub.
	await page.goto('/apps/dutycheck/roster?periodId=909031', { waitUntil: 'domcontentloaded' })
	await assertNotServerUpdater(page)
	await page.waitForSelector('#dc-roster-grid[role="grid"]', { timeout: 30000 })
	await page.waitForSelector('.dc-roster-grid__colhead', { timeout: 15000 })

	const geometry = await page.evaluate((expectedDays) => {
		const grid = document.getElementById('dc-roster-grid')
		const scroller = document.getElementById('dc-roster-grid-scroller')
		const heads = Array.from(grid?.querySelectorAll('.dc-roster-grid__colhead') || [])
		const rects = heads.map((el) => {
			const r = el.getBoundingClientRect()
			return { left: r.left, right: r.right, top: r.top, bottom: r.bottom, width: r.width, height: r.height }
		})
		let overlaps = 0
		for (let i = 0; i < rects.length; i++) {
			for (let j = i + 1; j < rects.length; j++) {
				const a = rects[i]
				const b = rects[j]
				const xOverlap = a.left < b.right - 1 && b.left < a.right - 1
				const yOverlap = a.top < b.bottom - 1 && b.top < a.bottom - 1
				if (xOverlap && yOverlap) {
					overlaps++
				}
			}
		}
		const tops = new Set(rects.map((r) => Math.round(r.top)))
		const dayCountVar = grid ? getComputedStyle(grid).getPropertyValue('--dc-roster-day-count').trim() : ''
		const dayMinVar = grid ? getComputedStyle(grid).getPropertyValue('--dc-roster-day-min').trim() : ''
		const weekendHeads = grid?.querySelectorAll('.dc-roster-grid__colhead--weekend').length || 0
		const status = document.getElementById('dc-roster-grid-status')?.textContent || ''
		const hint = document.getElementById('dc-roster-grid-hint')?.textContent || ''
		return {
			colheadCount: heads.length,
			ariaColCount: Number(grid?.getAttribute('aria-colcount') || 0),
			dayCountVar,
			dayMinVar,
			isMonthClass: grid?.classList.contains('dc-roster-grid--month') === true,
			overlaps,
			uniqueTops: tops.size,
			weekendHeads,
			canScrollX: scroller ? scroller.scrollWidth > scroller.clientWidth + 8 : false,
			statusHasSideways: /sideways|seitlich|horizontal/i.test(status),
			hintHasSideways: /sideways|seitlich|horizontal|scroll/i.test(hint),
			firstLabel: heads[0]?.getAttribute('aria-label') || heads[0]?.textContent || '',
			lastLabel: heads[heads.length - 1]?.getAttribute('aria-label') || heads[heads.length - 1]?.textContent || '',
			expectedDays,
		}
	}, MONTH_DAYS)

	expect(geometry.colheadCount).toBe(MONTH_DAYS)
	expect(geometry.ariaColCount).toBe(MONTH_DAYS + 1)
	expect(geometry.dayCountVar).toBe(String(MONTH_DAYS))
	expect(geometry.dayMinVar).toMatch(/3\.25rem/)
	expect(geometry.isMonthClass).toBeTruthy()
	expect(geometry.overlaps).toBe(0)
	expect(geometry.uniqueTops).toBe(1)
	expect(geometry.weekendHeads).toBeGreaterThanOrEqual(8)
	expect(geometry.canScrollX).toBeTruthy()
	expect(geometry.statusHasSideways || geometry.hintHasSideways).toBeTruthy()
	expect(geometry.firstLabel).toMatch(/1|01/)
	expect(geometry.lastLabel).toMatch(/31/)

	const shotDir = 'docs/atlas/evidence/screenshots/web'
	await page.locator('#dc-roster-grid-wrap').screenshot({
		path: `${shotDir}/roster-month-grid.png`,
	})

	const scroller = page.locator('#dc-roster-grid-scroller')
	await scroller.evaluate((el) => {
		el.scrollLeft = el.scrollWidth
	})
	await expect(page.locator('.dc-roster-grid__colhead').nth(MONTH_DAYS - 1)).toBeVisible()

	const results = await new AxeBuilder({ page })
		.withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
		.include('#dc-roster-grid-wrap')
		.exclude('#dc-toasts')
		.analyze()
	expect(results.violations, JSON.stringify(results.violations, null, 2)).toEqual([])
})
