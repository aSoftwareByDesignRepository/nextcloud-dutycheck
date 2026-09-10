// @ts-check
/**
 * Atlas POLICY ≥3.5.9 — executed open→cancel/dismiss + my-roster quick-range chip matrix.
 * Closes dlg-cancel-dismiss-proof-thin, filt-my-roster-quick-range-untoggled,
 * dlg-publish-readiness-cancel-thin, dlg-add-assignment-cancel-thin.
 */
import { test, expect } from '@playwright/test'
import { assertNotServerUpdater, credsFromEnv, login, plannerCredsCandidates } from './helpers/auth.js'

const QUICK_RANGES = ['upcoming', 'today', 'week', 'next-week', '14d', 'month']

test.describe('atlas employee filters + swap dismiss', () => {
	// Planner storageState must not leak into employee self-service routes.
	test.use({ storageState: { cookies: [], origins: [] } })

	test('my-roster quick-range chips toggle each value + empty-range honest copy', async ({ page }) => {
		test.skip(!process.env.NC_EMPLOYEE_USER, 'Requires NC_EMPLOYEE_* credentials')
		await login(page, credsFromEnv('EMPLOYEE'))
		await page.goto('/apps/dutycheck/my-roster', { waitUntil: 'domcontentloaded' })
		await assertNotServerUpdater(page)
		await page.waitForSelector('#dc-my-roster-quickfilters', { timeout: 30_000 })

		const chips = page.locator('#dc-my-roster-quickfilters .dc-quickfilters__btn')
		await expect(chips).toHaveCount(QUICK_RANGES.length)

		for (const key of QUICK_RANGES) {
			const chip = page.locator(`#dc-my-roster-quickfilters .dc-quickfilters__btn[data-range="${key}"]`)
			await expect(chip).toBeVisible()
			await expect(chip).toBeEnabled()
			await chip.click()
			await expect(chip).toHaveAttribute('aria-pressed', 'true')
			for (const other of QUICK_RANGES.filter((k) => k !== key)) {
				await expect(
					page.locator(`#dc-my-roster-quickfilters .dc-quickfilters__btn[data-range="${other}"]`),
				).toHaveAttribute('aria-pressed', 'false')
			}
			await page.waitForFunction(() => {
				const status = document.getElementById('dc-my-roster-status')
				return status && !/Loading/i.test(status.textContent || '')
			}, null, { timeout: 15_000 }).catch(() => {})
		}

		// Force empty result for an honest empty-range copy (independent of seed roster).
		await page.route('**/apps/dutycheck/api/my/roster**', async (route) => {
			await route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({ ok: true, data: [] }),
			})
		})
		await page.locator('#dc-my-roster-quickfilters .dc-quickfilters__btn[data-range="today"]').click()
		await expect(page.locator('#dc-my-roster-table-body .dc-table__empty-row')).toBeVisible({ timeout: 15_000 })
		await expect(page.locator('#dc-my-roster-table-body')).toContainText(/No published shifts in this range/i)
		await expect(page.locator('#dc-my-roster-table-body')).toContainText(/Try a wider range/i)
	})

	test('swap dialog open → cancel dismisses without POST', async ({ page }) => {
		test.skip(!process.env.NC_EMPLOYEE_USER, 'Requires NC_EMPLOYEE_* credentials')
		await login(page, credsFromEnv('EMPLOYEE'))

		const fakeRow = {
			id: 90001,
			dutyDate: '2099-01-15',
			startTime: '08:00:00',
			endTime: '16:00:00',
			breakMinutes: 30,
			locationName: 'Atlas Swap Lab',
			acknowledgedAt: null,
			note: '',
		}
		await page.route('**/apps/dutycheck/api/my/roster**', async (route) => {
			await route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({ ok: true, data: [fakeRow] }),
			})
		})
		await page.route('**/apps/dutycheck/api/my/swap-candidates**', async (route) => {
			await route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({ ok: true, data: [] }),
			})
		})

		let swapPosts = 0
		page.on('request', (req) => {
			if (req.method() === 'POST' && /\/apps\/dutycheck\/api\/swaps/.test(req.url())) {
				swapPosts += 1
			}
		})

		await page.goto('/apps/dutycheck/my-roster', { waitUntil: 'domcontentloaded' })
		await assertNotServerUpdater(page)
		await page.waitForSelector('#dc-my-roster-table-body', { timeout: 30_000 })
		const swapBtn = page.getByRole('button', { name: /Request swap/i }).first()
		await expect(swapBtn).toBeVisible({ timeout: 15_000 })
		await swapBtn.click()

		const dialog = page.locator('#dc-swap-dialog')
		await expect(dialog).toBeVisible()
		await expect(dialog.locator('#dc-swap-dialog-title')).toContainText(/Request a swap/i)
		await dialog.locator('button[type="submit"][value="cancel"]').click()
		await expect(dialog).toBeHidden()
		expect(swapPosts).toBe(0)
	})
})

test.describe('atlas planner dialog dismiss', () => {
	test('soll-from-duty confirm open → cancel leaves setting unchecked', async ({ page }) => {
		test.skip(plannerCredsCandidates().length === 0, 'Requires planner credentials')
		await page.goto('/apps/dutycheck/settings/dienst-team', { waitUntil: 'domcontentloaded' })
		await assertNotServerUpdater(page)
		await page.waitForSelector('#dc-dt-soll', { timeout: 30_000 })

		await page.route('**/apps/dutycheck/api/self-service/settings**', async (route) => {
			if (route.request().method() === 'GET') {
				const res = await route.fetch()
				const json = await res.json()
				const data = { ...(json.data || {}), sollFromDuty: false, sollFromDutyWarning: true }
				await route.fulfill({
					status: res.status(),
					contentType: 'application/json',
					body: JSON.stringify({ ok: true, data }),
				})
				return
			}
			throw new Error('soll cancel path must not POST self-service settings')
		})
		await page.reload({ waitUntil: 'domcontentloaded' })
		await page.waitForSelector('#dc-dt-soll', { timeout: 30_000 })

		// Force baseline + warning so confirmSollIfNeeded cannot skip the dialog.
		await page.evaluate(() => {
			const form = document.getElementById('dc-dienst-team-form')
			if (form) {
				form._dcBaseline = Object.assign({}, form._dcBaseline || {}, {
					sollFromDuty: false,
					sollFromDutyWarning: true,
				})
			}
			const warn = document.getElementById('dc-dt-soll-warning')
			if (warn) warn.hidden = false
			const soll = document.getElementById('dc-dt-soll')
			if (soll) soll.checked = false
		})

		const soll = page.locator('#dc-dt-soll')
		await expect(soll).not.toBeChecked()

		// Prefer DutyCheck modal; fall back to dismissing native window.confirm.
		const nativeDismiss = new Promise((resolve) => {
			page.once('dialog', async (dialog) => {
				await dialog.dismiss()
				resolve('native')
			})
		})

		await soll.check()
		await page.locator('#dc-dienst-team-save').click()

		const modal = page.locator('.dc-modal')
		const outcome = await Promise.race([
			modal.waitFor({ state: 'visible', timeout: 8_000 }).then(() => 'modal'),
			nativeDismiss,
		])

		if (outcome === 'modal') {
			await expect(modal).toContainText(/Soll from Duty/i)
			await modal.locator('.dc-modal__actions .button:not(.primary)').click()
			await expect(modal).toHaveCount(0)
		}

		await expect(soll).not.toBeChecked()
		await page.unrouteAll({ behavior: 'ignoreErrors' })
	})

	test('publish readiness open → cancel dismisses without publish mutate', async ({ page }) => {
		test.skip(plannerCredsCandidates().length === 0, 'Requires planner credentials')

		const openPeriod = {
			id: 910001,
			status: 'open',
			startDate: '2099-03-01',
			endDate: '2099-03-31',
			name: 'Atlas publish cancel',
			publishedAt: null,
		}

		await page.route('**/apps/dutycheck/api/periods/**', async (route) => {
			const url = route.request().url()
			const method = route.request().method()
			if (method === 'POST' && /\/periods\/\d+\/publish(?:\?|$)/.test(url)) {
				await route.fulfill({
					status: 500,
					contentType: 'application/json',
					body: JSON.stringify({ ok: false, error: { message: 'publish cancel path must not POST' } }),
				})
				return
			}
			if (method === 'GET' && /\/periods\/\d+\/publish-readiness/.test(url)) {
				await route.fulfill({
					status: 200,
					contentType: 'application/json',
					body: JSON.stringify({
						ok: true,
						data: {
							canPublish: true,
							hardConflicts: 0,
							softConflicts: 0,
							unacknowledgedSoftConflicts: 0,
							integrationPublishStale: false,
							integrationStale: false,
						},
					}),
				})
				return
			}
			if (method === 'GET' && /\/periods\/\d+\/(snapshots|audit|acknowledge-stats)/.test(url)) {
				const empty = /acknowledge-stats/.test(url)
					? { total: 0, acknowledged: 0, percent: 0 }
					: []
				await route.fulfill({
					status: 200,
					contentType: 'application/json',
					body: JSON.stringify({ ok: true, data: empty }),
				})
				return
			}
			await route.fallback()
		})
		await page.route('**/apps/dutycheck/api/periods', async (route) => {
			if (route.request().method() !== 'GET') {
				await route.fallback()
				return
			}
			await route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({ ok: true, data: { periods: [openPeriod] } }),
			})
		})
		await page.route('**/apps/dutycheck/api/periods?*', async (route) => {
			if (route.request().method() !== 'GET') {
				await route.fallback()
				return
			}
			await route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({ ok: true, data: { periods: [openPeriod] } }),
			})
		})

		let publishPosts = 0
		page.on('request', (req) => {
			if (req.method() === 'POST' && /\/apps\/dutycheck\/api\/periods\/\d+\/publish/.test(req.url())) {
				publishPosts += 1
			}
		})

		await page.goto('/apps/dutycheck/periods', { waitUntil: 'domcontentloaded' })
		await assertNotServerUpdater(page)
		await page.waitForSelector('#dc-periods-table-body', { timeout: 30_000 })
		await expect(page.locator('#dc-publish-ceremony')).toBeVisible({ timeout: 15_000 })
		// Locale may be DE (Bereit zur Veröffentlichung) or EN (Ready to publish).
		await expect(page.locator('#dc-publish-readiness')).toContainText(/Ready to publish|Bereit zur Veröffentlichung/i)

		const publishBtn = page.locator('#dc-publish-ceremony-actions button.primary')
		await expect(publishBtn).toBeEnabled()
		await publishBtn.click()

		const modal = page.locator('.dc-modal')
		await expect(modal).toBeVisible({ timeout: 8_000 })
		await expect(modal).toContainText(/Publish period|Zeitraum veröffentlichen/i)
		await modal.locator('.dc-modal__actions .button:not(.primary)').click()
		await expect(modal).toHaveCount(0)

		expect(publishPosts).toBe(0)
		await expect(page.locator('#dc-periods-table-body')).toContainText(/Open|Offen/i)
		await expect(page.locator('#dc-publish-ceremony')).toBeVisible()
		await page.unrouteAll({ behavior: 'ignoreErrors' })
	})

	test('assignment form clear cancels entered fields without persist', async ({ page }) => {
		test.skip(plannerCredsCandidates().length === 0, 'Requires planner credentials')

		// Overlap live month navigator (Sep 2026) so gridDateList is non-empty.
		const period = {
			id: 910011,
			status: 'open',
			startDate: '2026-09-01',
			endDate: '2026-09-30',
			name: 'Atlas assignment clear',
		}

		await page.route('**/apps/dutycheck/api/admin/planning-defaults**', async (route) => {
			await route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({ planning: { defaultBreakMinutes: 30 } }),
			})
		})
		await page.route('**/apps/dutycheck/api/templates**', async (route) => {
			await route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({ ok: true, data: [] }),
			})
		})
		await page.route('**/apps/dutycheck/api/swaps**', async (route) => {
			if (route.request().method() === 'GET') {
				await route.fulfill({
					status: 200,
					contentType: 'application/json',
					body: JSON.stringify({ ok: true, data: [] }),
				})
				return
			}
			await route.fallback()
		})
		await page.route('**/apps/dutycheck/api/open-shifts/pending**', async (route) => {
			await route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({ ok: true, data: [] }),
			})
		})
		await page.route('**/apps/dutycheck/api/roster/signals**', async (route) => {
			await route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({ ok: true, data: { preferences: [], blackouts: [] } }),
			})
		})
		await page.route('**/apps/dutycheck/api/roster**', async (route) => {
			if (/\/roster\/signals/.test(route.request().url())) {
				await route.fallback()
				return
			}
			await route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({
					ok: true,
					data: {
						employees: [{ id: 910101, displayName: 'Atlas Clear Emp', active: true }],
						periods: [period],
						selectedPeriodId: period.id,
						selectedPeriodStatus: 'open',
						calendarYearMonth: '2026-09',
						defaultBreakMinutes: 30,
						canCreateAssignments: true,
						locations: [{ id: 910201, name: 'Atlas Clear Loc' }],
						assignments: [],
						conflicts: [],
						absenceBlocks: [],
					},
				}),
			})
		})

		let assignmentMutates = 0
		page.on('request', (req) => {
			const m = req.method()
			if ((m === 'POST' || m === 'PUT') && /\/apps\/dutycheck\/api\/assignments(?:\/|\?|$)/.test(req.url())) {
				assignmentMutates += 1
			}
		})

		await page.goto('/apps/dutycheck/roster', { waitUntil: 'domcontentloaded' })
		await assertNotServerUpdater(page)
		await page.waitForSelector('#dc-roster-add-assignment', { timeout: 30_000 })
		await expect(page.getByText('Atlas Clear Emp')).toBeVisible({ timeout: 15_000 })
		const addBtn = page.locator('#dc-roster-add-assignment')
		await expect(addBtn).toBeVisible()
		await expect(addBtn).toBeEnabled({ timeout: 15_000 })
		// Listener for Add is registered only after DOMContentLoaded finishes
		// awaiting roster + swaps + open-shifts — click too early is a no-op.
		await expect(async () => {
			await addBtn.click()
			await expect(page.locator('.dc-modal')).toBeVisible({ timeout: 1_500 })
		}).toPass({ timeout: 20_000 })

		const modal = page.locator('.dc-modal')
		await expect(modal).toContainText(/Create assignment|Einsatz erstellen/i)
		await expect(page.locator('#dc-assignment-form-clear')).toBeVisible()

		const note = page.locator('#dc-assignment-note')
		await note.fill('atlas-clear-should-not-persist')
		await expect(note).toHaveValue('atlas-clear-should-not-persist')

		await page.locator('#dc-assignment-form-clear').click()
		await expect(note).toHaveValue('')
		expect(assignmentMutates).toBe(0)
		await expect(modal).toBeVisible()
		await page.unrouteAll({ behavior: 'ignoreErrors' })
	})
})
