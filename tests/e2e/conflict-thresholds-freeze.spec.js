// @ts-check
/**
 * Conflict thresholds UX: freeze callout + apply-open control + axe on settings/conflicts.
 */
import { test, expect } from '@playwright/test'
import AxeBuilder from '@axe-core/playwright'
import { assertNotServerUpdater, plannerCredsCandidates } from './helpers/auth.js'

test.describe('conflict thresholds freeze UX', () => {
	test.beforeEach(async ({ page }) => {
		test.skip(plannerCredsCandidates().length === 0, 'Requires E2E_* or NC_ADMIN_* credentials')
		await page.goto('/apps/dutycheck/settings/conflicts', { waitUntil: 'domcontentloaded' })
		await assertNotServerUpdater(page)
		await page.waitForSelector('#dc-conflict-policy-form', { timeout: 30_000 })
	})

	test('shows freeze callout, apply control, and hour hints', async ({ page }) => {
		await expect(page.locator('#dc-conflict-freeze-callout')).toBeVisible()
		await expect(page.locator('#dc-conflict-apply-open')).toBeVisible()
		await expect(page.locator('#dc-conflict-open-status')).toBeVisible()
		await expect(page.locator('#dc-policy-max-hard')).toBeVisible()
		await page.waitForFunction(() => {
			const hint = document.querySelector('#dc-policy-max-hard-hint [data-dc-minutes-hint]')
			return hint && String(hint.textContent || '').includes('hours')
		}, null, { timeout: 15_000 })
		await expect(page.locator('#dc-policy-max-hard-hint [data-dc-minutes-hint]')).toContainText(/hours/i)
	})

	test('conflicts settings page has no critical axe findings', async ({ page }) => {
		await page.waitForFunction(() => {
			const body = getComputedStyle(document.body)
			return body.getPropertyValue('--color-main-text').trim() !== ''
		}, null, { timeout: 10_000 }).catch(() => {})
		await page.locator('#dc-toasts .dc-toast').evaluateAll((nodes) => nodes.forEach((n) => n.remove())).catch(() => {})
		const results = await new AxeBuilder({ page })
			.withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
			.exclude('#dc-toasts')
			.analyze()
		const critical = results.violations.filter((v) => v.impact === 'critical')
		expect(critical, JSON.stringify(critical, null, 2)).toEqual([])
	})
})
