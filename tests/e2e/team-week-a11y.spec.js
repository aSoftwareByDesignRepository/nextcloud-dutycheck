// @ts-check
import { test, expect } from '@playwright/test'
import AxeBuilder from '@axe-core/playwright'
import { assertNotServerUpdater, credsFromEnv, login } from './helpers/auth.js'

/**
 * Team week (Kollegenplan) on my-roster — GA surface, employee only.
 */
test.describe('employee team week a11y', () => {
	test.use({ storageState: { cookies: [], origins: [] } })

	test('my-roster Team section is WCAG 2.1 AA when peer visibility is on', async ({ page }) => {
		test.skip(!process.env.NC_EMPLOYEE_USER, 'Requires NC_EMPLOYEE_* credentials')
		await login(page, credsFromEnv('EMPLOYEE'))
		await page.goto('/apps/dutycheck/my-roster', { waitUntil: 'domcontentloaded' })
		await assertNotServerUpdater(page)
		await page.waitForSelector('#dc-main-content', { timeout: 30000 })

		const team = page.locator('#dc-my-team')
		// Team section unhides after /api/team-locations resolves (peer on + belonging).
		try {
			await team.waitFor({ state: 'visible', timeout: 20000 })
		} catch {
			test.skip(true, 'Team week section not shown (peer off or no belonging locations)')
		}

		await expect(team.getByRole('heading', { name: /team this week/i })).toBeVisible()
		const results = await new AxeBuilder({ page })
			.include('#dc-my-team')
			.withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
			.analyze()
		expect(results.violations, JSON.stringify(results.violations, null, 2)).toEqual([])
	})
})
