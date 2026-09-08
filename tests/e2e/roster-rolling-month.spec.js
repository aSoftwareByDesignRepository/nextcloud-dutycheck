// @ts-check
import { test, expect } from '@playwright/test'
import { assertNotServerUpdater, loginWithFallback, plannerCredsCandidates } from './helpers/auth.js'

test('rolling month navigator ensures periods without manual Zeitraum creation', async ({ page }) => {
	test.setTimeout(120_000)
	test.skip(plannerCredsCandidates().length === 0, 'Requires planner credentials')
	await loginWithFallback(page, plannerCredsCandidates())
	await page.goto('/apps/dutycheck/roster', { waitUntil: 'domcontentloaded' })
	await assertNotServerUpdater(page)
	await page.waitForSelector('#dc-roster-month-current', { timeout: 30000 })
	await page.waitForSelector('#dc-roster-grid', { timeout: 30000 })

	const nextBtn = page.locator('#dc-roster-month-next')
	const prevBtn = page.locator('#dc-roster-month-prev')
	const todayBtn = page.locator('#dc-roster-month-today')
	const monthLabel = page.locator('#dc-roster-month-current')

	// Init ensures the current month and briefly disables nav — wait for that cycle.
	await expect(nextBtn).toBeEnabled({ timeout: 45000 })
	await expect.poll(async () => (await monthLabel.innerText()).trim().length, { timeout: 45000 }).toBeGreaterThan(2)

	const before = (await monthLabel.innerText()).trim()

	await nextBtn.scrollIntoViewIfNeeded()
	const ensureNext = page.waitForResponse(
		(res) => res.url().includes('/api/periods/ensure-calendar-month') && res.request().method() === 'POST',
		{ timeout: 45000 },
	)
	await nextBtn.click()
	const nextRes = await ensureNext
	expect(nextRes.status(), 'ensure-calendar-month after Next').toBe(200)
	await expect.poll(async () => (await monthLabel.innerText()).trim(), { timeout: 30000 }).not.toBe(before)
	await expect(nextBtn).toBeEnabled({ timeout: 30000 })

	const afterNext = (await monthLabel.innerText()).trim()
	const ensurePrev = page.waitForResponse(
		(res) => res.url().includes('/api/periods/ensure-calendar-month') && res.request().method() === 'POST',
		{ timeout: 45000 },
	)
	await prevBtn.click()
	const prevRes = await ensurePrev
	expect(prevRes.status(), 'ensure-calendar-month after Previous').toBe(200)
	await expect.poll(async () => {
		const label = (await monthLabel.innerText()).trim()
		return label === before || label !== afterNext
	}, { timeout: 30000 }).toBeTruthy()

	const ensureToday = page.waitForResponse(
		(res) => res.url().includes('/api/periods/ensure-calendar-month') && res.request().method() === 'POST',
		{ timeout: 45000 },
	)
	await todayBtn.click()
	const todayRes = await ensureToday
	expect(todayRes.status(), 'ensure-calendar-month after This month').toBe(200)
	await page.waitForSelector('#dc-roster-grid[role="grid"], #dc-roster-grid[role="status"]', { timeout: 20000 })

	// Navigated month must clamp the grid to that calendar month (not a multi-month open Zeitraum).
	const colStats = await page.evaluate(() => {
		const grid = document.getElementById('dc-roster-grid')
		const heads = Array.from(grid?.querySelectorAll('.dc-roster-grid__colhead') || [])
		const dayCount = grid ? getComputedStyle(grid).getPropertyValue('--dc-roster-day-count').trim() : ''
		return {
			colheads: heads.length,
			dayCount,
			first: heads[0]?.getAttribute('data-duty-date') || '',
			last: heads[heads.length - 1]?.getAttribute('data-duty-date') || '',
		}
	})
	if (colStats.colheads > 0) {
		expect(colStats.colheads).toBeLessThanOrEqual(31)
		expect(Number(colStats.dayCount || colStats.colheads)).toBe(colStats.colheads)
	}

	const switcher = page.locator('#dc-roster-period-switcher')
	await expect(switcher).toBeVisible()
	const options = await switcher.locator('option').count()
	expect(options).toBeGreaterThan(0)

	await page.locator('#dc-roster-grid-wrap').screenshot({
		path: 'docs/atlas/evidence/screenshots/web/roster-rolling-month.png',
	})
})
