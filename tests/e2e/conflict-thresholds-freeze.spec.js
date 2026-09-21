// @ts-check
/**
 * Conflict thresholds UX: state-driven freeze callout (Apply only when outdated)
 * + axe on settings/conflicts. Uses API route mocks so every state is covered
 * without depending on live period data.
 */
import { test, expect } from '@playwright/test'
import AxeBuilder from '@axe-core/playwright'
import { assertNotServerUpdater, plannerCredsCandidates } from './helpers/auth.js'

const POLICY = {
	minRestMinutes: 660,
	maxDailyHard: 600,
	maxPeriodSoft: 8400,
	maxPeriodHard: 12000,
	maxConsecutiveDays: 6,
}

async function mockConflictPolicy(page, openPeriods) {
	await page.route('**/apps/dutycheck/api/admin/conflict-policy**', async (route) => {
		const method = route.request().method()
		if (method === 'GET' || method === 'POST') {
			await route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({
					data: {
						...POLICY,
						openPeriods,
					},
				}),
			})
			return
		}
		await route.continue()
	})
}

test.describe('conflict thresholds freeze UX', () => {
	test.beforeEach(async ({ page }) => {
		test.skip(plannerCredsCandidates().length === 0, 'Requires E2E_* or NC_ADMIN_* credentials')
	})

	test('synced open periods: success callout, Apply hidden, hour hints', async ({ page }) => {
		await mockConflictPolicy(page, { schemaReady: true, openCount: 2, outdatedCount: 0 })
		await page.goto('/apps/dutycheck/settings/conflicts', { waitUntil: 'domcontentloaded' })
		await assertNotServerUpdater(page)
		await page.waitForSelector('#dc-conflict-policy-form', { timeout: 30_000 })
		await expect(page.locator('#dc-conflict-freeze-callout')).toBeVisible()
		await expect(page.locator('#dc-conflict-freeze-callout')).toHaveClass(/dc-callout--success/)
		await expect(page.locator('#dc-conflict-freeze-title')).toContainText(/already match|entsprechen bereits/i)
		await expect(page.locator('#dc-conflict-open-status')).toContainText(/Nothing to apply|Nichts anzuwenden/i)
		await expect(page.locator('#dc-conflict-apply-open')).toBeHidden()
		await expect(page.locator('#dc-conflict-apply-actions')).toBeHidden()
		await page.waitForFunction(() => {
			const hint = document.querySelector('#dc-policy-max-hard-hint [data-dc-minutes-hint]')
			return hint && /hours|Stunden/i.test(String(hint.textContent || ''))
		}, null, { timeout: 15_000 })
	})

	test('outdated open periods: warning callout, Apply visible and enabled', async ({ page }) => {
		await mockConflictPolicy(page, { schemaReady: true, openCount: 3, outdatedCount: 2 })
		await page.goto('/apps/dutycheck/settings/conflicts', { waitUntil: 'domcontentloaded' })
		await assertNotServerUpdater(page)
		await page.waitForSelector('#dc-conflict-apply-open:not([hidden])', { timeout: 30_000 })
		await expect(page.locator('#dc-conflict-freeze-callout')).toHaveClass(/dc-callout--warning/)
		await expect(page.locator('#dc-conflict-freeze-title')).toContainText(/older limits|ältere Grenzen/i)
		const apply = page.locator('#dc-conflict-apply-open')
		await expect(apply).toBeVisible()
		await expect(apply).toBeEnabled()
		await expect(page.locator('#dc-conflict-open-status')).toContainText(/2/)
	})

	test('no open periods: info callout, Apply hidden', async ({ page }) => {
		await mockConflictPolicy(page, { schemaReady: true, openCount: 0, outdatedCount: 0 })
		await page.goto('/apps/dutycheck/settings/conflicts', { waitUntil: 'domcontentloaded' })
		await assertNotServerUpdater(page)
		await page.waitForSelector('#dc-conflict-freeze-title', { timeout: 30_000 })
		await expect(page.locator('#dc-conflict-freeze-callout')).toHaveClass(/dc-callout--info/)
		await expect(page.locator('#dc-conflict-freeze-title')).toContainText(/No open periods|Keine offenen/i)
		await expect(page.locator('#dc-conflict-apply-open')).toBeHidden()
	})

	test('save with outdated periods prompts Apply below (no dead button)', async ({ page }) => {
		let postCount = 0
		await page.route('**/apps/dutycheck/api/admin/conflict-policy**', async (route) => {
			const method = route.request().method()
			if (method === 'GET') {
				await route.fulfill({
					status: 200,
					contentType: 'application/json',
					body: JSON.stringify({
						data: {
							...POLICY,
							openPeriods: { schemaReady: true, openCount: 2, outdatedCount: 0 },
						},
					}),
				})
				return
			}
			if (method === 'POST') {
				postCount += 1
				await route.fulfill({
					status: 200,
					contentType: 'application/json',
					body: JSON.stringify({
						data: {
							...POLICY,
							maxPeriodHard: 15000,
							openPeriods: { schemaReady: true, openCount: 2, outdatedCount: 2 },
						},
					}),
				})
				return
			}
			await route.continue()
		})
		await page.goto('/apps/dutycheck/settings/conflicts', { waitUntil: 'domcontentloaded' })
		await assertNotServerUpdater(page)
		await page.waitForSelector('#dc-conflict-policy-form', { timeout: 30_000 })
		await expect(page.locator('#dc-conflict-apply-open')).toBeHidden()
		await page.locator('#dc-policy-max-hard').fill('15000')
		await page.locator('#dc-conflict-policy-form button[type="submit"]').click()
		await expect.poll(() => postCount).toBe(1)
		await expect(page.locator('#dc-conflict-policy-status')).toContainText(/Apply them|anwenden/i)
		await expect(page.locator('#dc-conflict-apply-open')).toBeVisible()
		await expect(page.locator('#dc-conflict-apply-open')).toBeEnabled()
		await expect(page.locator('#dc-conflict-freeze-callout')).toHaveClass(/dc-callout--warning/)
	})

	test('conflicts settings page has no critical axe findings', async ({ page }) => {
		await mockConflictPolicy(page, { schemaReady: true, openCount: 2, outdatedCount: 1 })
		await page.goto('/apps/dutycheck/settings/conflicts', { waitUntil: 'domcontentloaded' })
		await assertNotServerUpdater(page)
		await page.waitForSelector('#dc-conflict-apply-open:not([hidden])', { timeout: 30_000 })
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
