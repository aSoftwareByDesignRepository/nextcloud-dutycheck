// @ts-check
/**
 * ATLAS_MOBILE_NAV_CONTRACT — phone Menu → open nav + no h-scroll
 * (in-page #dc-nav-toggle; core #app-navigation-toggle is unreliable).
 */
import { createRequire } from 'module'
import { test } from '@playwright/test'
import { login, credsFromEnv } from './helpers/auth.js'

const require = createRequire(import.meta.url)
const { assertAtlasMobileNav } = require('../../../_shared/e2e/atlas-mobile-nav-contract.js')

test('ATLAS_MOBILE_NAV_CONTRACT in-page Menu opens drawer', async ({ page }) => {
	test.skip(!process.env.NC_ADMIN_USER, 'Requires NC_ADMIN_USER / NC_ADMIN_PASS')
	await page.setViewportSize({ width: 375, height: 812 })
	await login(page, credsFromEnv('ADMIN'))
	await page.goto('/apps/dutycheck/', { waitUntil: 'domcontentloaded' })
	await page.waitForSelector('#dc-main-content, .dc-access-denied', { timeout: 45000 })
	test.skip((await page.locator('.dc-access-denied').count()) > 0, 'Home access denied')
	await page.waitForSelector('[data-dc-nav-toggle], #dc-nav-toggle', { timeout: 30000 })
	await assertAtlasMobileNav(page, {
		toggle: page.locator('[data-dc-nav-toggle], #dc-nav-toggle').first(),
		nav: page.locator('#app-navigation'),
		openClass: /dc-nav--open/,
	})
})
