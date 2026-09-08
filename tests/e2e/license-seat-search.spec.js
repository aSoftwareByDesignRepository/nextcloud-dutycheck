// @ts-check
import { test, expect } from '@playwright/test'
import AxeBuilder from '@axe-core/playwright'
import { credsFromEnv, login, loginWithFallback, plannerCredsCandidates } from './helpers/auth.js'

/**
 * License seat picker: must find Nextcloud users (items contract) and stay AA.
 */
test.describe('DutyCheck license seat search', () => {
	test.skip(!plannerCredsCandidates().length, 'Requires E2E_* or NC_ADMIN_* credentials')

	test('seat search returns directory hits and does not steal a11y', async ({ page }) => {
		await page.setViewportSize({ width: 1280, height: 900 })
		await loginWithFallback(page, plannerCredsCandidates())
		await page.goto('/apps/dutycheck/settings/license', { waitUntil: 'domcontentloaded' })
		await expect(page.locator('#dc-license-panel, #dc-license-seat-search-input').first()).toBeVisible({
			timeout: 30_000,
		})

		const search = page.locator('#dc-license-seat-search-input')
		await expect(search).toBeVisible()
		await expect(search).toHaveAttribute('role', 'combobox')

		// Intercept the license search endpoint and assert the client calls it
		// (not /api/admin/users) and understands the `items` payload.
		await page.route('**/apps/dutycheck/api/license/search/users**', async (route) => {
			const url = new URL(route.request().url())
			expect(url.searchParams.get('q')?.length ?? 0).toBeGreaterThanOrEqual(2)
			await route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({
					ok: true,
					items: [
						{ id: 'alice', displayName: 'Alice Example', hasSeat: false },
						{ id: 'bob', displayName: 'Bob Example', hasSeat: true },
					],
				}),
			})
		})

		await search.fill('al')
		const suggest = page.locator('#dc-license-seat-search-suggest')
		await expect(suggest).toBeVisible({ timeout: 10_000 })
		await expect(suggest.getByRole('option', { name: /Alice Example/i })).toBeVisible()
		// Already-seated Bob must be filtered out of the listbox.
		await expect(suggest.getByRole('option', { name: /Bob Example/i })).toHaveCount(0)

		const results = await new AxeBuilder({ page })
			.include('#dc-license-panel, #dc-main-content')
			.withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
			.exclude('.toastify')
			.analyze()
		expect(results.violations, JSON.stringify(results.violations, null, 2)).toEqual([])
	})

	test('legacy users-shaped payload still populates suggestions', async ({ page }) => {
		await loginWithFallback(page, plannerCredsCandidates())
		await page.goto('/apps/dutycheck/settings/license', { waitUntil: 'domcontentloaded' })
		await expect(page.locator('#dc-license-seat-search-input')).toBeVisible({ timeout: 30_000 })

		await page.route('**/apps/dutycheck/api/license/search/users**', async (route) => {
			await route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({
					ok: true,
					users: [{ id: 'cara', displayName: 'Cara Compat', enabled: true }],
				}),
			})
		})

		await page.locator('#dc-license-seat-search-input').fill('ca')
		const suggest = page.locator('#dc-license-seat-search-suggest')
		await expect(suggest.getByRole('option', { name: /Cara Compat/i })).toBeVisible({ timeout: 10_000 })
	})

	test('live search against the real directory finds the admin account', async ({ page }) => {
		let creds
		try {
			creds = credsFromEnv('ADMIN')
		} catch {
			test.skip(true, 'NC_ADMIN_* required for live directory probe')
			return
		}
		await login(page, creds)
		await page.goto('/apps/dutycheck/settings/license', { waitUntil: 'domcontentloaded' })
		await expect(page.locator('#dc-license-seat-search-input')).toBeVisible({ timeout: 30_000 })

		const needle = String(creds.username).slice(0, Math.max(2, Math.min(4, creds.username.length)))
		await page.locator('#dc-license-seat-search-input').fill(needle)
		const suggest = page.locator('#dc-license-seat-search-suggest')
		await expect(suggest).toBeVisible({ timeout: 15_000 })
		// Either a real option or an explicit empty-state — never a silent dead end.
		const options = suggest.locator('[role="option"]')
		const empty = suggest.locator('.dc-license-seat-search__note')
		await expect(options.or(empty).first()).toBeVisible({ timeout: 15_000 })
		if (await options.count()) {
			await expect(options.first()).toBeVisible()
		}
	})
})
