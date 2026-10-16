// @ts-check
/**
 * Regression spec for the visual r1 must_fix pair (verdict-visual-dutycheck.json):
 *
 *  - dc-vis-today-disabled-empty-stack: a failed or disabled Today-board load
 *    must never unhide #dc-today-empty — "nobody scheduled" on a dead board is a
 *    fabricated data state. TODAY_BOARD_DISABLED renders exactly ONE disabled
 *    message (the warning callout owns it; the flash strip stays quiet).
 *    patterns.js repeats the same dedupe for ROTATION_DISABLED.
 *  - dc-vis-flash-error-success-tint: .dc-roster-flash--error must paint the
 *    critical-tint well — computed background resolves to var(--dc-tint-critical),
 *    never the success tint (status_well_semantic_inversion).
 *
 * All selectors are structural (ids/classes) + the app's own data-msg-* strings —
 * no locale-coupled text matching.
 */
import { test, expect } from '@playwright/test'
import { assertNotServerUpdater, loginWithFallback, plannerCredsCandidates } from './helpers/auth.js'

const stubLocations = (page) =>
	page.route('**/apps/dutycheck/api/locations**', (route) =>
		route.request().method() === 'GET'
			? route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({ data: [{ id: 1, name: 'Zentrale' }] }),
			})
			: route.continue())

const failBoard = (page, status, code) =>
	page.route('**/apps/dutycheck/api/today-board**', (route) =>
		route.fulfill({
			status,
			contentType: 'application/json',
			body: JSON.stringify({ success: false, error: { code, message: code } }),
		}))

/** Count visible elements inside #dc-today-board carrying `msg` as direct text. */
const disabledMessageCarriers = (page, msg) =>
	page.evaluate((message) => {
		const root = document.getElementById('dc-today-board')
		if (!root || !message) return -1
		return [...root.querySelectorAll('*')].filter((el) => {
			const own = [...el.childNodes]
				.filter((n) => n.nodeType === 3)
				.map((n) => n.textContent)
				.join('')
			const visible = el.offsetParent !== null || el.getClientRects().length > 0
			return visible && own.includes(message)
		}).length
	}, msg)

test.describe('Today board error/disabled states (visual r1 must_fix)', () => {
	test.skip(() => plannerCredsCandidates().length === 0, 'Requires E2E_* or NC_ADMIN_* credentials')

	test('TODAY_BOARD_DISABLED renders one callout, no fabricated empty state', async ({ page }) => {
		await loginWithFallback(page, plannerCredsCandidates())
		await stubLocations(page)
		await failBoard(page, 422, 'TODAY_BOARD_DISABLED')
		await page.goto('/apps/dutycheck/today', { waitUntil: 'domcontentloaded' })
		await assertNotServerUpdater(page)

		await expect(page.locator('#dc-today-disabled')).toBeVisible({ timeout: 15_000 })
		await expect(page.locator('#dc-today-empty')).toBeHidden()
		await expect(page.locator('#dc-today-status')).toBeHidden()
		await expect(page.locator('#dc-today-timeline > *')).toHaveCount(0)

		const msg = await page.locator('#dc-today-board').getAttribute('data-msg-disabled')
		expect(msg, 'data-msg-disabled attribute').toBeTruthy()
		expect(
			await disabledMessageCarriers(page, msg),
			'disabled message must render exactly once (callout owns it)',
		).toBe(1)
	})

	test('generic board failure: error flash on critical tint, empty panel stays hidden', async ({ page }) => {
		await loginWithFallback(page, plannerCredsCandidates())
		await stubLocations(page)
		await failBoard(page, 500, 'INTERNAL_ERROR')
		await page.goto('/apps/dutycheck/today', { waitUntil: 'domcontentloaded' })
		await assertNotServerUpdater(page)

		const status = page.locator('#dc-today-status')
		await expect(status).toBeVisible({ timeout: 15_000 })
		await expect(status).toHaveClass(/dc-roster-flash--error/)
		await expect(page.locator('#dc-today-empty')).toBeHidden()
		await expect(page.locator('#dc-today-disabled')).toBeHidden()
		await expect(page.locator('#dc-today-timeline > *')).toHaveCount(0)

		const probe = await page.evaluate(() => {
			const el = document.getElementById('dc-today-status')
			const strip = el ? getComputedStyle(el).backgroundColor : null
			const resolve = (token) => {
				const d = document.createElement('div')
				d.style.background = `var(${token})`
				document.body.appendChild(d)
				const c = getComputedStyle(d).backgroundColor
				d.remove()
				return c
			}
			return { strip, critical: resolve('--dc-tint-critical'), success: resolve('--dc-tint-success') }
		})
		expect(probe.strip, 'error flash must paint the critical tint well').toBe(probe.critical)
		expect(probe.strip, 'error flash must not stay on the success tint').not.toBe(probe.success)
	})

	test('ROTATION_DISABLED on patterns: callout owns the message, strip stays hidden', async ({ page }) => {
		await loginWithFallback(page, plannerCredsCandidates())
		await page.route('**/apps/dutycheck/api/rotation-patterns**', (route) =>
			route.fulfill({
				status: 422,
				contentType: 'application/json',
				body: JSON.stringify({ success: false, error: { code: 'ROTATION_DISABLED', message: 'off' } }),
			}))
		await page.goto('/apps/dutycheck/patterns', { waitUntil: 'domcontentloaded' })
		await assertNotServerUpdater(page)

		await expect(page.locator('#dc-patterns-disabled')).toBeVisible({ timeout: 15_000 })
		await expect(page.locator('#dc-patterns-status')).toBeHidden()
		await expect(page.locator('#dc-patterns-empty')).toBeHidden()
		await expect(page.locator('#dc-patterns-create')).toBeDisabled()
	})
})
