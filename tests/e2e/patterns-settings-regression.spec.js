// @ts-check
/**
 * Regression specs for the 0.3.4 customer report (form-encoded "false" → PHP
 * (bool) true corruption, missing pattern delete, suggest-fill location leak).
 *
 * 1. Saving a pattern with sparse working days must reopen with only those
 *    days checked — not all seven.
 * 2. Patterns must be deletable from the UI (soft-deactivate; list hides them).
 * 3. Duty & team settings must keep unchecked checkboxes unchecked after save.
 * 4. "Suggest fill" must not send an implicit location filter.
 */
import { test, expect } from '@playwright/test'
import { assertNotServerUpdater, loginWithFallback, plannerCredsCandidates } from './helpers/auth.js'

const SETTINGS_API = '/apps/dutycheck/api/self-service/settings'

test.describe('DutyCheck 0.3.4 customer-report regressions', () => {
	test.skip(() => plannerCredsCandidates().length === 0, 'Requires E2E_* or NC_ADMIN_* credentials')

	test('pattern working days round-trip, then delete removes it', async ({ page }) => {
		await loginWithFallback(page, plannerCredsCandidates())
		await page.goto('/apps/dutycheck/patterns', { waitUntil: 'domcontentloaded' })
		await assertNotServerUpdater(page)
		await page.waitForSelector('#dc-main-content', { timeout: 30_000 })

		// Rotation patterns must be enabled for this spec; flip on via the real
		// client stack and restore afterwards.
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
		try {
			const createBtn = page.locator('#dc-patterns-create')
			await expect(createBtn).toBeVisible({ timeout: 15_000 })
			if (await createBtn.isDisabled()) {
				test.skip(true, 'rotation patterns disabled and could not be enabled')
			}

			const name = 'E2E-Bool-' + Date.now()
			await createBtn.click()
			await expect(page.locator('#dc-pat-name')).toBeVisible({ timeout: 10_000 })
			await page.locator('#dc-pat-name').fill(name)
			const locCount = await page.locator('#dc-pat-location option').count()
			test.skip(locCount < 2, 'no locations configured — pattern editor requires a default location')
			await page.locator('#dc-pat-location').selectOption({ index: 1 })

			// Check Monday + Wednesday of week 0 only — the reported repro.
			for (const dow of [1, 3]) {
				const cell = `[data-week="0"][data-dow="${dow}"]`
				await page.locator(`${cell} .dc-patterns__is-working`).check()
				await page.locator(`${cell} .dc-patterns__start`).fill('08:00')
				await page.locator(`${cell} .dc-patterns__end`).fill('16:00')
			}
			await page.locator('.dc-modal .dc-modal__actions button.primary').click()

			const item = page.locator('.dc-patterns__item', { hasText: name })
			await expect(item).toHaveCount(1, { timeout: 15_000 })

			// Reopen the editor: only the two marked days may be checked.
			// Locale-agnostic: Edit is the first non-danger action, Delete is
			// the .danger one — do not match on translated labels.
			await item.locator('.dc-patterns__item-actions button:not(.danger)').first().click()
			await expect(page.locator('.dc-modal')).toBeVisible({ timeout: 10_000 })
			for (let dow = 1; dow <= 7; dow++) {
				const box = page.locator(`[data-week="0"][data-dow="${dow}"] .dc-patterns__is-working`)
				if (dow === 1 || dow === 3) {
					await expect(box).toBeChecked()
				} else {
					await expect(box).not.toBeChecked()
				}
			}
			await page.keyboard.press('Escape')
			await expect(page.locator('.dc-modal')).toHaveCount(0, { timeout: 10_000 })

			// Delete via the new action: confirm dialog → pattern leaves the list.
			await item.locator('.dc-patterns__item-actions button.danger').click()
			await expect(page.locator('.dc-modal')).toBeVisible({ timeout: 10_000 })
			await page.locator('.dc-modal .dc-modal__actions button.primary').click()
			await expect(page.locator('.dc-patterns__item', { hasText: name }))
				.toHaveCount(0, { timeout: 15_000 })
		} finally {
			if (wasEnabled === false) {
				await page.evaluate(async (api) => {
					await window.DutyCheckApi.post(api, { rotationPatternsEnabled: false })
				}, SETTINGS_API).catch(() => {})
			}
		}
	})

	test('duty & team settings keep unchecked checkboxes after save', async ({ page }) => {
		await loginWithFallback(page, plannerCredsCandidates())
		await page.goto('/apps/dutycheck/settings/dienst-team', { waitUntil: 'domcontentloaded' })
		await assertNotServerUpdater(page)
		await page.waitForSelector('#dc-dienst-team-form', { timeout: 30_000 })

		const fields = ['peerRosterVisibility', 'blackoutsEnabled', 'claimRequiresPlanner']
		const before = await page.evaluate((names) => {
			const form = /** @type {HTMLFormElement} */ (document.getElementById('dc-dienst-team-form'))
			const out = {}
			for (const n of names) {
				const el = form?.elements.namedItem(n)
				out[n] = !!(el && 'checked' in el && el.checked)
				if (el && 'checked' in el) el.checked = false
			}
			return out
		}, fields)
		if (Object.values(before).every((v) => v === null || v === undefined)) {
			test.skip(true, 'settings form locked (no app admin) — cannot exercise save')
		}

		try {
			await page.evaluate(() => {
				/** @type {HTMLFormElement} */ (document.getElementById('dc-dienst-team-form')).requestSubmit()
			})
			await page.waitForFunction(() => {
				const el = document.getElementById('dc-dienst-team-status')
				return !!el && !el.hidden && (el.textContent || '').trim().length > 0
					&& !/Saving|Speichern/i.test(el.textContent || '')
			}, null, { timeout: 15_000 })

			await page.reload({ waitUntil: 'domcontentloaded' })
			await page.waitForSelector('#dc-dienst-team-form', { timeout: 30_000 })
			const after = await page.evaluate((names) => {
				const form = /** @type {HTMLFormElement} */ (document.getElementById('dc-dienst-team-form'))
				return names.map((n) => {
					const el = form?.elements.namedItem(n)
					return !!(el && 'checked' in el && el.checked)
				})
			}, fields)
			expect(after, 'unchecked checkboxes must stay unchecked after save+reload')
				.toEqual([false, false, false])
		} finally {
			// Restore prior state so the spec leaves company settings as found.
			await page.evaluate((payload) => {
				const form = /** @type {HTMLFormElement} */ (document.getElementById('dc-dienst-team-form'))
				for (const [n, v] of Object.entries(payload)) {
					const el = form?.elements.namedItem(n)
					if (el && 'checked' in el) el.checked = !!v
				}
				form?.requestSubmit()
			}, before)
			await page.waitForTimeout(1200)
		}
	})

	test('edit-assignment modal offers Cancel shift (delete parity)', async ({ page }) => {
		await loginWithFallback(page, plannerCredsCandidates())
		await page.goto('/apps/dutycheck/roster', { waitUntil: 'domcontentloaded' })
		await assertNotServerUpdater(page)
		await page.waitForSelector('#dc-roster-period-switcher', { timeout: 30_000 })

		// Self-contained fixture: the modal only opens when the selected period
		// is `open` (canAddAssignment gate), so the spec creates its own period,
		// fetches an employee + location, and adds one assignment via the API.
		const fixture = await page.evaluate(async () => {
			const api = window.DutyCheckApi
			const [emps, locs] = await Promise.all([
				api.get('/apps/dutycheck/api/employees'),
				api.get('/apps/dutycheck/api/locations'),
			])
			const emp = (emps?.data || []).find((r) => r.active !== false)
			const loc = (locs?.data || []).find((r) => r.active !== false)
			if (!emp || !loc) return { stage: 'catalog', emp: !!emp, loc: !!loc }
			const fmt = (d) => d.toISOString().slice(0, 10)
			let periodId = 0
			let dutyDate = ''
			const errs = []
			// PERIOD_RANGE_EXISTS: periods cannot overlap — walk forward in
			// 7-day steps until a free week is found.
			for (let offset = 60; offset <= 60 + 7 * 20 && !periodId; offset += 7) {
				const start = new Date()
				start.setDate(start.getDate() + offset - start.getDay())
				const end = new Date(start)
				end.setDate(end.getDate() + 6)
				const p = await api.post('/apps/dutycheck/api/periods', { startDate: fmt(start), endDate: fmt(end) }).catch((e) => { errs.push(`${fmt(start)}:${e?.code || e?.message}`); return null })
				periodId = Number(p?.data?.id || p?.data?.period?.id || 0)
				if (periodId) dutyDate = fmt(start)
			}
			if (!periodId) return { stage: 'period', errs }
			const body = {
				periodId,
				employeeId: emp.id,
				locationId: loc.id,
				dutyDate,
				startTime: '08:00',
				endTime: '16:00',
				breakMinutes: 0,
			}
			let a = null
			try {
				a = await api.post('/apps/dutycheck/api/assignments', body)
			} catch (e) {
				// Soft planning conflicts need an explicit acknowledgement, same
				// flow the UI takes after the planner confirms a reason.
				if (e?.code === 'CONFLICT_ACK_REQUIRED') {
					const conflicts = e?.payload?.error?.conflicts || []
					const types = [...new Set(conflicts.map((c) => c?.conflictType || c?.type).filter(Boolean))]
					body.acknowledgements = (types.length ? types : ['rest_time_violation'])
						.map((t) => ({ conflictType: t, reason: 'E2E regression fixture shift' }))
					try {
						a = await api.post('/apps/dutycheck/api/assignments', body)
					} catch (e2) {
						return { stage: 'assignment', err: e2?.code || e2?.message }
					}
				} else {
					return { stage: 'assignment', err: e?.code || e?.message }
				}
			}
			if (!a) return { stage: 'assignment', err: 'empty' }
			return { periodId, employeeId: emp.id, dutyDate }
		})
		if (!fixture?.periodId) console.log('FIXTURE FAIL', JSON.stringify(fixture))
		test.skip(!fixture?.periodId, `could not create fixture: ${JSON.stringify(fixture)}`)

		await page.reload({ waitUntil: 'domcontentloaded' })
		await page.waitForSelector('#dc-roster-period-switcher', { timeout: 30_000 })
		await page.waitForFunction((pid) => {
			const sel = document.getElementById('dc-roster-period-switcher')
			return !!sel && Array.from(sel.options).some((o) => o.value === String(pid))
		}, fixture.periodId, { timeout: 30_000 })
		// The switcher listener is bound during async init — dispatch `change`
		// and retry until the grid actually re-renders the fixture period (a
		// cell carrying our dutyDate only exists once loadRoster resolved).
		const anyCell = page.locator(
			`.dc-roster-grid__cell[data-duty-date="${fixture.dutyDate}"]`,
		).first()
		const cell = page.locator(
			`.dc-roster-grid__cell--filled[data-employee-id="${fixture.employeeId}"][data-duty-date="${fixture.dutyDate}"]`,
		)
		for (let attempt = 0; attempt < 5; attempt++) {
			await page.evaluate((pid) => {
				const sel = document.getElementById('dc-roster-period-switcher')
				sel.value = String(pid)
				sel.dispatchEvent(new Event('change', { bubbles: true }))
			}, fixture.periodId)
			const found = await anyCell.waitFor({ state: 'attached', timeout: 10_000 }).then(() => true).catch(() => false)
			if (found) break
		}
		await expect(cell, 'grid must render the fixture assignment cell after selecting its period')
			.toBeVisible({ timeout: 10_000 })
		await cell.click()

		const modal = page.locator('.dc-modal')
		await expect(modal).toBeVisible({ timeout: 10_000 })
		const cancelShift = modal.locator('.dc-modal__secondary.danger')
		await expect(cancelShift, 'edit modal must expose a danger Cancel shift action').toBeVisible()

		// Pressing it must ask first (native confirm) — dismiss and the modal
		// must stay open so unsaved edits survive an aborted delete.
		page.once('dialog', (dialog) => void dialog.dismiss())
		await cancelShift.click()
		await page.waitForTimeout(400)
		await expect(modal, 'aborted delete must keep the edit modal open').toBeVisible()
		await page.keyboard.press('Escape')
	})

	test('suggest fill sends no implicit location filter', async ({ page }) => {
		await loginWithFallback(page, plannerCredsCandidates())
		await page.goto('/apps/dutycheck/roster', { waitUntil: 'domcontentloaded' })
		await assertNotServerUpdater(page)
		await page.waitForSelector('#dc-roster-period-switcher', { timeout: 30_000 })
		// Options are filled async after rosterData resolves.
		await page.waitForFunction(() => {
			const sel = document.getElementById('dc-roster-period-switcher')
			return !!sel && sel.options.length > 0
		}, null, { timeout: 30_000 }).catch(() => {})

		// A period must be selected for the button to reach the API.
		const hasPeriod = await page.evaluate(() => {
			const sel = /** @type {HTMLSelectElement|null} */ (document.getElementById('dc-roster-period-switcher'))
			if (!sel) return false
			if (sel.value) return true
			const opt = Array.from(sel.options).find((o) => o.value)
			if (!opt) return false
			sel.value = opt.value
			sel.dispatchEvent(new Event('change', { bubbles: true }))
			return true
		})
		test.skip(!hasPeriod, 'no period available in this instance')

		const requestPromise = page.waitForRequest(
			(req) => req.url().includes('/suggest-preview') && req.method() === 'POST',
			{ timeout: 20_000 },
		)
		await page.locator('#dc-roster-suggest-fill').click()
		const req = await requestPromise
		const body = req.postData() || ''
		expect(
			/locationId=/.test(body),
			`suggest-preview must not send an implicit locationId (leaked from the assignment form): ${body}`,
		).toBe(false)

		// Whatever the preview shows (modal or announcement), leave it closed.
		if (await page.locator('.dc-modal').isVisible().catch(() => false)) {
			await page.keyboard.press('Escape')
		}
	})
})
