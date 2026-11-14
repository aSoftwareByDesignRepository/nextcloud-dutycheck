// @ts-check
/**
 * Seed dutycheck-intake-20261114T194222Z (class closed_dialog_painted):
 * the synced license-page pack shipped no `.{p}-license-modal:not([open])`
 * re-hide rule while NC core's author-origin reset `dialog{display:block}`
 * outranks the UA `dialog:not([open])` rule — every closed pack modal
 * (clear / confirm / token) painted visibly mid-page, caught clicks and
 * entered the a11y tree.
 *
 * Fix = `.dc-license-modal:not([open]){display:none}` (donor
 * _shared/license-page @0ba19365d3, synced into css/common/license-page.css).
 * These legs prove firsthand that closed pack dialogs compute display:none,
 * never intercept pointer events, and that a real open→cancel cycle
 * restores the hidden state. Pre-fix the first leg fails: computed display
 * is block and the closed token modal covers the licence form.
 */
import { test, expect } from '@playwright/test'
import AxeBuilder from '@axe-core/playwright'
import { mkdirSync } from 'fs'
import { dirname, resolve } from 'path'
import { fileURLToPath } from 'url'
import { assertNotServerUpdater, loginWithFallback, plannerCredsCandidates } from './helpers/auth.js'

const CRAFT_DIR = resolve(
	dirname(fileURLToPath(import.meta.url)),
	'../../../../../.cursor/atlas-farm-v3/artifacts/dutycheck/craft',
)

function craftStamp() {
	return new Date().toISOString().replace(/[-:]/g, '').replace(/\.\d+Z$/, 'Z')
}

/** @param {import('@playwright/test').Page} page @param {string} slug */
async function craftShot(page, slug) {
	mkdirSync(CRAFT_DIR, { recursive: true })
	const dest = resolve(CRAFT_DIR, `dc-license-${slug}-${craftStamp()}.png`)
	await page.screenshot({ path: dest, fullPage: false })
	return dest
}

const MODAL_IDS = ['#dc-license-clear-modal', '#dc-license-confirm-modal', '#dc-license-token-modal']

/**
 * Computed-style + visibility assert for one closed pack dialog.
 * `toBeHidden` also asserts the node is absent from the a11y tree
 * (Playwright treats display:none as not accessible-visible).
 * @param {import('@playwright/test').Page} page @param {string} id
 */
async function expectClosed(page, id) {
	const el = page.locator(id)
	await expect(el).toBeHidden()
	const display = await el.evaluate((n) => getComputedStyle(n).display)
	expect(display, `${id} closed must compute display:none`).toBe('none')
}

/**
 * The closed pack modals must never sit on top of a page control —
 * probe elementFromPoint at the control's box and require the hit to land
 * inside the license form, not inside a .dc-license-modal subtree.
 * @param {import('@playwright/test').Page} page
 */
async function expectNoClosedModalOverlay(page) {
	const hit = await page.evaluate(() => {
		const anchor =
			document.getElementById('dc-license-key-input') ||
			document.getElementById('dc-license-save') ||
			document.getElementById('dc-license-page')
		if (!anchor) return { ok: false, reason: 'no anchor' }
		const r = anchor.getBoundingClientRect()
		const cx = Math.min(Math.max(r.left + r.width / 2, 1), window.innerWidth - 1)
		const cy = Math.min(Math.max(r.top + r.height / 2, 1), window.innerHeight - 1)
		const el = document.elementFromPoint(cx, cy)
		if (!el) return { ok: false, reason: 'elementFromPoint null' }
		return {
			ok: true,
			hitInModal: Boolean(el.closest('.dc-license-modal')),
			hitTag: el.tagName.toLowerCase(),
			hitId: el.id || '',
		}
	})
	expect(hit.ok, hit.reason || 'elementFromPoint probe failed').toBe(true)
	expect(hit.hitInModal, `closed modal intercepts point (${hit.hitTag}#${hit.hitId})`).toBe(false)
}

test.describe('license pack closed dialogs stay hidden (closed_dialog_painted)', () => {
	test.skip(plannerCredsCandidates().length === 0, 'Requires planner credentials')

	test.beforeEach(async ({ page }) => {
		await page.setViewportSize({ width: 1440, height: 900 })
		await loginWithFallback(page, plannerCredsCandidates())
		await page.goto('/apps/dutycheck/settings/license', { waitUntil: 'domcontentloaded' })
		await assertNotServerUpdater(page)
		await page.waitForSelector('#dc-license-page', { timeout: 30_000 })
	})

	test('all three closed pack modals compute display:none and do not cover the form', async ({ page }) => {
		for (const id of MODAL_IDS) {
			await expect(page.locator(id)).toHaveCount(1)
			await expectClosed(page, id)
		}
		await expectNoClosedModalOverlay(page)
		// Focus must land on the real control — the closed dialogs cannot
		// swallow clicks or tab order any more.
		await page.locator('#dc-license-key-input').click()
		await expect(page.locator('#dc-license-key-input')).toBeFocused()
		await craftShot(page, 'page-closed-modals')
	})

	test('clear modal opens via real trigger, paints, then re-hides on cancel', async ({ page }) => {
		const trigger = page.locator('#dc-license-clear')
		await expect(trigger).toBeVisible({ timeout: 15_000 })

		for (const id of MODAL_IDS) {
			await expectClosed(page, id)
		}
		await trigger.click()

		const modal = page.locator('#dc-license-clear-modal')
		await expect(modal).toBeVisible()
		const openDisplay = await modal.evaluate((n) => getComputedStyle(n).display)
		expect(openDisplay).not.toBe('none')
		// Siblings stay closed while the clear modal is open.
		await expectClosed(page, '#dc-license-confirm-modal')
		await expectClosed(page, '#dc-license-token-modal')
		await craftShot(page, 'clear-modal-open')

		await page.locator('#dc-license-clear-cancel').click()
		await expectClosed(page, '#dc-license-clear-modal')
		await expectNoClosedModalOverlay(page)
	})

	test('seat-remove confirm modal opens, cancel performs zero mutation, then re-hides', async ({ page }) => {
		let deletes = 0
		page.on('request', (req) => {
			if (req.method() === 'DELETE' && /\/apps\/dutycheck\/api\/license\//.test(req.url())) {
				deletes += 1
			}
		})
		const seatRemove = page.locator('.dc-seat-remove').first()
		await expect(seatRemove).toBeVisible({ timeout: 15_000 })
		await seatRemove.click()

		const modal = page.locator('#dc-license-confirm-modal')
		await expect(modal).toBeVisible()
		const openDisplay = await modal.evaluate((n) => getComputedStyle(n).display)
		expect(openDisplay).not.toBe('none')
		await craftShot(page, 'seat-confirm-modal-open')

		await page.locator('#dc-license-confirm-cancel').click()
		await expectClosed(page, '#dc-license-confirm-modal')
		expect(deletes).toBe(0)
		await expectNoClosedModalOverlay(page)
		await page.unrouteAll({ behavior: 'ignoreErrors' }).catch(() => {})
	})

	test('license surface stays WCAG AA with modals closed', async ({ page }) => {
		const results = await new AxeBuilder({ page })
			.include('#dc-license-page, #dc-main-content')
			.withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
			.exclude('.toastify')
			.analyze()
		expect(results.violations, JSON.stringify(results.violations, null, 2)).toEqual([])
	})
})
