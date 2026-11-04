// @ts-check
/**
 * Rendered regression proof for the 2026-11-04 farm class-gate sweep
 * (required_field_or_step_undiscoverable / check-select-background-shorthand.py):
 * the `.dc-input` / `#app-content.dc-app select` control rule in css/app.css
 * painted the bare `background:` shorthand, resetting the NC core picker caret
 * (image/position/repeat/size) — the control read as a plain text field in
 * light and a tiled caret grid in dark.
 *
 * Legs (computed style, light AND dark):
 *  1. Today-board filter select `#dc-today-location` (`select.dc-input` under
 *     #app-content.dc-app) — statically rendered, no fixture needed.
 *  2. Roster period switcher `#dc-roster-period-switcher` — same rule family.
 *
 * Theme is flipped via OCS; restored after each leg.
 */
import { test, expect } from '@playwright/test'
import { assertNotServerUpdater, plannerCredsCandidates } from './helpers/auth.js'
import { setUserTheme, resetUserTheme } from './helpers/theming.js'

const APP = '/apps/dutycheck'
const THEMES = ['light', 'dark']

/** Computed chevron probe: core caret = url() image + no-repeat + positioned. */
async function probeChevron(select, theme) {
	const probe = await select.evaluate((node) => {
		const cs = getComputedStyle(node)
		return {
			image: cs.backgroundImage,
			repeat: cs.backgroundRepeat,
			position: cs.backgroundPosition,
		}
	})
	expect(probe.image, `${theme}: chevron background-image missing (bare background: regression)`).toContain('url(')
	expect(probe.repeat, `${theme}: chevron tiles (dark-grid regression)`).toBe('no-repeat')
	expect(probe.position, `${theme}: chevron unpositioned`).not.toBe('0% 0%')
}

test.describe('select chevron contract', () => {
	test.describe.configure({ mode: 'serial' })
	test.beforeEach(async () => {
		test.skip(plannerCredsCandidates().length === 0, 'Requires E2E_* or NC_ADMIN_* credentials')
	})
	test.afterEach(async ({ page }) => {
		await resetUserTheme(page).catch(() => {})
	})

	test('today-board location select keeps the picker chevron (light+dark)', async ({ page }) => {
		test.setTimeout(120_000)
		await page.setViewportSize({ width: 1280, height: 900 })
		for (const theme of THEMES) {
			await page.goto(`${APP}/today`, { waitUntil: 'domcontentloaded' })
			await assertNotServerUpdater(page)
			await page.waitForSelector('#dc-main-content', { timeout: 30_000 })
			await setUserTheme(page, theme)
			await page.goto(`${APP}/today`, { waitUntil: 'domcontentloaded' })
			const select = page.locator('#dc-today-location')
			await expect(select).toHaveCount(1)
			await probeChevron(select, theme)
		}
	})

	test('roster period switcher select keeps the picker chevron (light+dark)', async ({ page }) => {
		test.setTimeout(120_000)
		await page.setViewportSize({ width: 1280, height: 900 })
		for (const theme of THEMES) {
			await page.goto(`${APP}/roster`, { waitUntil: 'domcontentloaded' })
			await assertNotServerUpdater(page)
			await page.waitForSelector('#dc-main-content', { timeout: 30_000 })
			await setUserTheme(page, theme)
			await page.goto(`${APP}/roster`, { waitUntil: 'domcontentloaded' })
			const select = page.locator('#dc-roster-period-switcher')
			await expect(select).toHaveCount(1)
			await probeChevron(select, theme)
		}
	})
})
