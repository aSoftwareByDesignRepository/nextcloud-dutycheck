// @ts-check
/**
 * Atlas R6 web-pages batch: planner route smoke.
 * Asserts #dc-main-content visible + no axe critical violations.
 */
import { test, expect } from '@playwright/test'
import AxeBuilder from '@axe-core/playwright'
import { assertNotServerUpdater, plannerCredsCandidates } from './helpers/auth.js'

/** @type {{ id: string, path: string, ready?: string }[]} */
const plannerPages = [
	{ id: 'web-dashboard', path: '/apps/dutycheck/dashboard', ready: '#dc-main-content' },
	{ id: 'web-today-board', path: '/apps/dutycheck/today', ready: '#dc-main-content' },
	{ id: 'web-periods', path: '/apps/dutycheck/periods', ready: '#dc-main-content' },
	{ id: 'web-patterns', path: '/apps/dutycheck/patterns', ready: '#dc-main-content' },
	{ id: 'web-absences-planner', path: '/apps/dutycheck/absences', ready: '#dc-main-content' },
	{ id: 'web-employees', path: '/apps/dutycheck/employees', ready: '#dc-main-content' },
	{ id: 'web-locations', path: '/apps/dutycheck/locations', ready: '#dc-main-content' },
]

for (const pageDef of plannerPages) {
	test(`web-pages smoke: ${pageDef.id} (${pageDef.path})`, async ({ page }) => {
		test.skip(plannerCredsCandidates().length === 0, 'Requires E2E_* or NC_ADMIN_* credentials')
		await page.goto(pageDef.path, { waitUntil: 'domcontentloaded' })
		await assertNotServerUpdater(page)
		await page.waitForSelector(pageDef.ready || '#dc-main-content', { timeout: 30_000 })
		await expect(page.locator('#dc-main-content')).toBeVisible()
		expect(page.url()).not.toMatch(/\/login/)

		await page.waitForFunction(() => {
			const body = getComputedStyle(document.body)
			return body.getPropertyValue('--color-main-text').trim() !== ''
				&& body.getPropertyValue('--color-main-background').trim() !== ''
		}, null, { timeout: 10_000 }).catch(() => {})
		await page.locator('#dc-toasts .dc-toast').evaluateAll((nodes) => nodes.forEach((n) => n.remove())).catch(() => {})

		const results = await new AxeBuilder({ page })
			.withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
			.exclude('#dc-toasts')
			.analyze()
		const critical = results.violations.filter((v) => v.impact === 'critical')
		expect(critical, JSON.stringify(critical, null, 2)).toEqual([])
	})
}
