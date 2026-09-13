// @ts-check
/**
 * ATLAS_VERTICAL_SCROLL_CONTRACT — tall license settings must scroll to the end.
 * Guards CSS Overflow L3 unpaired overflow-x:clip truncating the seats block.
 */
import { createRequire } from 'module'
import { test } from '@playwright/test'
import { loginWithFallback, plannerCredsCandidates } from './helpers/auth.js'

const require = createRequire(import.meta.url)
const { assertAtlasVerticalScrollReachable } = require('../../../_shared/e2e/atlas-vertical-scroll-contract.js')

test.describe('ATLAS_VERTICAL_SCROLL_CONTRACT', () => {
	test.skip(!plannerCredsCandidates().length, 'Requires E2E_* or NC_ADMIN_* credentials')

	test('license settings page scrolls to seat table end', async ({ page }) => {
		// Short viewport so the license panel overflows and must scroll.
		await page.setViewportSize({ width: 1280, height: 640 })
		await loginWithFallback(page, plannerCredsCandidates())
		await page.goto('/apps/dutycheck/settings/license', { waitUntil: 'domcontentloaded' })
		await page.waitForSelector('#dc-license-panel, .dc-access-denied', { timeout: 45_000 })
		test.skip((await page.locator('.dc-access-denied').count()) > 0, 'License access denied')
		await page.waitForSelector('#dc-license-panel', { timeout: 30_000 })

		await assertAtlasVerticalScrollReachable(page, {
			scrollport: '#app-content',
			target: '#dc-license-seat-table, #dc-license-seat-empty-row, #dc-license-panel',
			bottomSlopPx: 12,
		})
	})

	test('license settings stays reachable at phone height', async ({ page }) => {
		await page.setViewportSize({ width: 390, height: 667 })
		await loginWithFallback(page, plannerCredsCandidates())
		await page.goto('/apps/dutycheck/settings/license', { waitUntil: 'domcontentloaded' })
		await page.waitForSelector('#dc-license-panel, .dc-access-denied', { timeout: 45_000 })
		test.skip((await page.locator('.dc-access-denied').count()) > 0, 'License access denied')
		await page.waitForSelector('#dc-license-panel', { timeout: 30_000 })

		await assertAtlasVerticalScrollReachable(page, {
			scrollport: '#app-content',
			target: '#dc-license-seat-table, #dc-license-seat-empty-row, #dc-license-panel',
			bottomSlopPx: 16,
		})
	})
})
