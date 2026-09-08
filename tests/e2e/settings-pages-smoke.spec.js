// @ts-check
/**
 * Atlas R6 settings batch: planner settings sub-page smoke.
 * Asserts #dc-main-content on every catalog section + chip/nav at 390px.
 * Patterns reused from a11y-smoke.spec.js / web-pages-smoke.spec.js.
 */
import { test, expect } from '@playwright/test'
import AxeBuilder from '@axe-core/playwright'
import { assertNotServerUpdater, plannerCredsCandidates } from './helpers/auth.js'

/** Catalog order matches SettingsSectionCatalog::SECTIONS / a11y-smoke. */
const settingsSections = [
	'access',
	'duty-roles',
	'planning',
	'companies',
	'conflicts',
	'shift-templates',
	'qualifications',
	'planner-scope',
	'operations',
	'dienst-team',
	'integration',
	'privacy',
	'license',
	'support',
]

/** @type {{ id: string, path: string, section: string }[]} */
const settingsPages = settingsSections.map((section) => ({
	id: `web-settings-${section}`,
	path: `/apps/dutycheck/settings/${section}`,
	section,
}))

for (const pageDef of settingsPages) {
	test(`settings-pages smoke: ${pageDef.id} (${pageDef.path})`, async ({ page }) => {
		test.skip(plannerCredsCandidates().length === 0, 'Requires E2E_* or NC_ADMIN_* credentials')
		await page.goto(pageDef.path, { waitUntil: 'domcontentloaded' })
		await assertNotServerUpdater(page)
		await page.waitForSelector('#dc-main-content', { timeout: 30_000 })
		await expect(page.locator('#dc-main-content')).toBeVisible()
		expect(page.url()).not.toMatch(/\/login/)
		await expect(page).toHaveURL(new RegExp(`/apps/dutycheck/settings/${pageDef.section}$`))

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

test('settings chip/nav ok at 390px (planning → privacy hop)', async ({ page }) => {
	test.skip(plannerCredsCandidates().length === 0, 'Requires E2E_* or NC_ADMIN_* credentials')
	// Chip bar is intentionally display:none at ≥1025px (sidebar owns navigation).
	await page.setViewportSize({ width: 390, height: 844 })
	await page.goto('/apps/dutycheck/settings/planning', { waitUntil: 'domcontentloaded' })
	await assertNotServerUpdater(page)
	await page.waitForSelector('#dc-settings-pages', { timeout: 30_000 })
	await expect(page.locator('#dc-settings-pages.dc-settings-nav')).toBeVisible()
	const chipLinks = page.locator('#dc-settings-pages > a.dc-settings-nav__link')
	await expect(chipLinks).toHaveCount(settingsSections.length)
	const activeChip = page.locator('#dc-settings-pages > a.dc-settings-nav__link[aria-current="page"]')
	await expect(activeChip).toHaveCount(1)
	await expect(activeChip).toHaveAttribute('href', /\/settings\/planning$/)
	await page.locator('#dc-settings-pages > a.dc-settings-nav__link[href*="/settings/privacy"]').click()
	await page.waitForURL(/\/apps\/dutycheck\/settings\/privacy$/, { timeout: 30_000 })
	await page.waitForSelector('#dc-main-content', { timeout: 30_000 })
})

test('settings sidebar sub-navigation marks the active sub-page', async ({ page }) => {
	test.skip(plannerCredsCandidates().length === 0, 'Requires E2E_* or NC_ADMIN_* credentials')
	await page.goto('/apps/dutycheck/settings/companies', { waitUntil: 'domcontentloaded' })
	await assertNotServerUpdater(page)
	await page.waitForSelector('#dc-main-content', { timeout: 30_000 })
	const active = page.locator('.dc-nav__sublink[aria-current="page"]')
	await expect(active).toHaveCount(1)
	await expect(active).toHaveAttribute('href', /\/settings\/companies$/)
	await expect(page.locator('.dc-nav__sublink')).toHaveCount(settingsSections.length)
})
