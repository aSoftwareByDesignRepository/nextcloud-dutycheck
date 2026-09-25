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
			await item.locator('button', { hasText: /Edit|Bearbeiten/ }).click()
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
			await item.locator('button', { hasText: /Delete|Löschen/ }).click()
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
