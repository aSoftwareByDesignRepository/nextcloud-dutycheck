// @ts-check
/**
 * Regression spec for the 2026-10-22 customer report:
 *
 *  1. The pattern editor used to hardcode breakMinutes:0 — no Pause field
 *     existed, so a stored break could never be entered and re-saving wiped
 *     API-set values. The grid must expose a labelled break input per
 *     working day and round-trip it through save + reopen.
 *  2. The today board offered deactivated locations (catalog returns them
 *     for admin use) and a remembered-but-stale pick produced the generic
 *     "update DutyCheck" toast instead of a location message. Inactive
 *     entries must be filtered, a stale stored id must be cleared, and no
 *     update-app toast may appear.
 */
import { test, expect } from '@playwright/test'
import { assertNotServerUpdater, loginWithFallback, plannerCredsCandidates } from './helpers/auth.js'

const SETTINGS_API = '/apps/dutycheck/api/self-service/settings'
const PATTERNS_API = '/apps/dutycheck/api/rotation-patterns'

test.describe('DutyCheck 2026-10-22 customer-report regressions', () => {
	test.skip(() => plannerCredsCandidates().length === 0, 'Requires E2E_* or NC_ADMIN_* credentials')

	test('pattern editor round-trips break minutes per working day', async ({ page }) => {
		await loginWithFallback(page, plannerCredsCandidates())
		await page.goto('/apps/dutycheck/patterns', { waitUntil: 'domcontentloaded' })
		await assertNotServerUpdater(page)
		await page.waitForSelector('#dc-main-content', { timeout: 30_000 })

		// Same rotation-enabled gate as patterns-settings-regression.spec.js.
		const wasEnabled = await page.evaluate(async (api) => {
			try {
				const res = await window.DutyCheckApi.get(api)
				return res?.data?.rotationPatternsEnabled === true
			} catch {
				return null
			}
		}, SETTINGS_API)
		if (wasEnabled === false) {
			await page.evaluate(async (api) => {
				await window.DutyCheckApi.post(api, { rotationPatternsEnabled: true })
			}, SETTINGS_API).catch(() => {})
			await page.reload({ waitUntil: 'domcontentloaded' })
			await page.waitForSelector('#dc-main-content', { timeout: 30_000 })
		}

		const name = 'E2E-Break-' + Date.now()
		try {
			const createBtn = page.locator('#dc-patterns-create')
			await expect(createBtn).toBeVisible({ timeout: 15_000 })
			if (await createBtn.isDisabled()) {
				test.skip(true, 'rotation patterns disabled and could not be enabled')
			}
			await createBtn.click()
			await expect(page.locator('#dc-pat-name')).toBeVisible({ timeout: 10_000 })
			await page.locator('#dc-pat-name').fill(name)
			const locCount = await page.locator('#dc-pat-location option').count()
			test.skip(locCount < 2, 'no locations configured')
			await page.locator('#dc-pat-location').selectOption({ index: 1 })

			const cell = '[data-week="0"][data-dow="1"]'
			const breakInput = page.locator(`${cell} .dc-patterns__break`)
			await expect(breakInput, 'working day must expose a break input').toHaveCount(1)
			await expect(breakInput, 'break input must start disabled').toBeDisabled()
			await page.locator(`${cell} .dc-patterns__is-working`).check()
			await expect(breakInput, 'break input must enable with the working toggle').toBeEnabled()
			await page.locator(`${cell} .dc-patterns__start`).fill('08:00')
			await page.locator(`${cell} .dc-patterns__end`).fill('16:00')
			await breakInput.fill('30')
			await page.locator('.dc-modal .dc-modal__actions button.primary').click()

			const item = page.locator('.dc-patterns__item', { hasText: name })
			await expect(item).toHaveCount(1, { timeout: 15_000 })

			// Server truth: the stored weekDay carries the entered break.
			const stored = await page.evaluate(async (n) => {
				const res = await window.DutyCheckApi.get('/apps/dutycheck/api/rotation-patterns')
				const pat = (res?.data?.patterns || []).find((p) => String(p.name) === n)
				const day = (pat?.weekDays || []).find(
					(d) => Number(d.weekIndex) === 0 && Number(d.dow) === 1,
				)
				return { patternId: Number(pat?.id || 0), breakMinutes: day?.breakMinutes ?? null }
			}, name)
			expect(stored.breakMinutes, 'saved pattern must persist the entered break')
				.toBe(30)

			// Reopen: the input must show the stored value (no wipe to 0).
			await item.locator('.dc-patterns__item-actions button:not(.danger)').first().click()
			await expect(page.locator('.dc-modal')).toBeVisible({ timeout: 10_000 })
			await expect(page.locator(`${cell} .dc-patterns__break`)).toHaveValue('30')
			await page.keyboard.press('Escape')
			await expect(page.locator('.dc-modal')).toHaveCount(0, { timeout: 10_000 })

			if (stored.patternId > 0) {
				await page.evaluate(async (id) => {
					await window.DutyCheckApi.post(
						'/apps/dutycheck/api/rotation-patterns/' + id + '/deactivate', {},
					)
				}, stored.patternId).catch(() => {})
			}
		} finally {
			if (wasEnabled === false) {
				await page.evaluate(async (api) => {
					await window.DutyCheckApi.post(api, { rotationPatternsEnabled: false })
				}, SETTINGS_API).catch(() => {})
			}
		}
	})

	test('today board drops inactive + stale locations without the update-app toast', async ({ page }) => {
		await loginWithFallback(page, plannerCredsCandidates())

		// Catalog returns an inactive location (admin screens need it); the
		// board must not offer it, and the remembered stale pick must clear.
		await page.route('**/apps/dutycheck/api/locations**', (route) =>
			route.request().method() === 'GET'
				? route.fulfill({
					status: 200,
					contentType: 'application/json',
					body: JSON.stringify({
						data: [
							{ id: 1, name: 'Zentrale', active: true },
							{ id: 2, name: 'Alte Filiale', active: false },
						],
					}),
				})
				: route.continue())
		await page.route('**/apps/dutycheck/api/today-board**', (route) =>
			route.fulfill({
				status: 404,
				contentType: 'application/json',
				body: JSON.stringify({ success: false, error: { code: 'LOCATION_NOT_FOUND', message: 'gone' } }),
			}))
		await page.addInitScript(() => {
			try {
				window.localStorage.setItem('dutycheck.today.lastLocationId', '2')
			} catch (_) {}
		})

		await page.goto('/apps/dutycheck/today', { waitUntil: 'domcontentloaded' })
		await assertNotServerUpdater(page)

		// The inactive entry never becomes an option.
		await expect(
			page.locator('#dc-today-location option'),
		).toHaveCount(1, { timeout: 15_000 })
		await expect(page.locator('#dc-today-location')).toHaveValue('1')

		// The stale stored id is cleared by the LOCATION_NOT_FOUND handling —
		// not remembered again.
		await expect.poll(() =>
			page.evaluate(() => window.localStorage.getItem('dutycheck.today.lastLocationId')),
		).toBeNull()

		// The status strip carries the location message; the update-app toast
		// (the original smartphone screenshot) must not appear.
		await expect(page.locator('#dc-today-status')).not.toBeEmpty({ timeout: 15_000 })
		await expect(page.locator('.dc-toast', { hasText: 'could not find this DutyCheck action' }))
			.toHaveCount(0)
	})

	test('open-shift form exposes a break field prefilled with the org default', async ({ page }) => {
		await loginWithFallback(page, plannerCredsCandidates())

		const period = {
			id: 909031,
			status: 'open',
			startDate: '2026-10-01',
			endDate: '2026-10-31',
			name: 'E2E October month',
		}
		await page.route('**/apps/dutycheck/api/periods/ensure-calendar-month**', (route) =>
			route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({
					ok: true,
					data: { period, created: false, writable: true, yearMonth: '2026-10' },
				}),
			}))
		await page.route('**/apps/dutycheck/api/roster**', async (route) => {
			let raw = ''
			try {
				const response = await route.fetch()
				raw = await response.text()
			} catch {
				// Page/context tearing down — fall through to the empty envelope.
			}
			let parsed = {}
			try { parsed = JSON.parse(raw) } catch { /* fall through to empty */ }
			const envelope = parsed && typeof parsed === 'object' && parsed.data && typeof parsed.data === 'object'
				? parsed
				: { data: {} }
			await route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({
					...envelope,
					data: {
						...(envelope.data || {}),
						periods: [period],
						selectedPeriodId: period.id,
						selectedPeriodStatus: 'open',
						canCreateAssignments: true,
						defaultBreakMinutes: 45,
						employees: [{ id: 900001, displayName: 'Anna Meier', active: true }],
						locations: [{ id: 1, name: 'Hall', timezone: 'Europe/Berlin' }],
						assignments: [],
						openShifts: [],
					},
				}),
			})
		})

		// Capture the create POST — the payload is the contract.
		/** @type {Record<string, unknown>|null} */
		let posted = null
		await page.route('**/apps/dutycheck/api/open-shifts**', (route) => {
			if (route.request().method() === 'POST') {
				try { posted = route.request().postDataJSON() } catch { posted = {} }
				return route.fulfill({
					status: 201,
					contentType: 'application/json',
					body: JSON.stringify({ ok: true, data: { id: 909991 } }),
				})
			}
			return route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({ ok: true, data: [] }),
			})
		})

		await page.goto('/apps/dutycheck/roster?periodId=909031', { waitUntil: 'domcontentloaded' })
		await assertNotServerUpdater(page)
		await page.waitForSelector('#dc-roster-grid', { timeout: 30_000 })
		// #dc-roster-grid is static markup — render() may still be in flight.
		// Wait for the period switcher value: the deterministic signal that
		// state.periods/selectedPeriodId landed (otherwise the save handler
		// bails with "Select a period first." and no POST ever fires).
		await expect(page.locator('#dc-roster-period-switcher'), 'roster data must load before driving the form')
			.toHaveValue('909031', { timeout: 30_000 })

		await page.locator('#dc-open-shift-create').click()
		const breakInput = page.locator('#dc-os-break')
		await expect(breakInput, 'open-shift form must render a break input').toBeVisible()
		await expect(breakInput, 'break input must prefill the organisation default').toHaveValue('45')

		await breakInput.fill('20')
		await page.locator('#dc-os-start').fill('08:00')
		await page.locator('#dc-os-end').fill('12:00')
		await page.locator('#dc-os-save').click()

		await expect.poll(() => posted, { timeout: 10_000 }).not.toBeNull()
		expect(Number(posted?.breakMinutes), 'payload must carry the entered break, not a hardcoded 0')
			.toBe(20)
	})
})
