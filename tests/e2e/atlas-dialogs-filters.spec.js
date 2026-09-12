// @ts-check
/**
 * Atlas POLICY ≥3.5.9 — executed open→cancel/dismiss + my-roster quick-range chip matrix.
 * Closes dlg-cancel-dismiss-proof-thin, filt-my-roster-quick-range-untoggled,
 * dlg-publish-readiness-cancel-thin, dlg-add-assignment-cancel-thin.
 * POLICY ≥3.5.10 — shipping dialog inventory + open craft PNGs
 * (dlg-inventory-incomplete-shipping-modals, dc-vis-destructive-dialogs-open-craft-missing).
 */
import { test, expect } from '@playwright/test'
import { mkdirSync } from 'fs'
import { dirname, resolve } from 'path'
import { fileURLToPath } from 'url'
import { assertNotServerUpdater, credsFromEnv, login, plannerCredsCandidates } from './helpers/auth.js'

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
	const dest = resolve(CRAFT_DIR, `dc-dlg-${slug}-${craftStamp()}.png`)
	await page.screenshot({ path: dest, fullPage: false })
	return dest
}

/** @param {import('@playwright/test').Page} page */
function dcModal(page) {
	return page.locator('.dc-modal').first()
}

/** @param {import('@playwright/test').Locator} modal */
function modalCancel(modal) {
	return modal.locator('.dc-modal__actions .button:not(.primary)').first()
}

/** @param {import('@playwright/test').Locator} modal */
function modalPrimary(modal) {
	return modal.locator('.dc-modal__actions .button.primary').first()
}

/**
 * Shared roster page stubs for shipping promptReason surfaces in roster.js.
 * @param {import('@playwright/test').Page} page
 * @param {{ period: object, employees?: object[], locations?: object[], assignments?: object[], conflicts?: object[], openClaims?: object[] }} opts
 */
async function stubRosterPage(page, opts) {
	const period = opts.period
	const employees = opts.employees || [{ id: 991101, displayName: 'Atlas Prompt Emp', active: true }]
	const locations = opts.locations || [{ id: 991201, name: 'Atlas Prompt Loc' }]
	const assignments = opts.assignments || []
	const conflicts = opts.conflicts || []
	const openClaims = opts.openClaims || []

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
			body: JSON.stringify({ ok: true, data: openClaims }),
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
					employees,
					periods: [period],
					selectedPeriodId: period.id,
					selectedPeriodStatus: period.status || 'open',
					calendarYearMonth: String(period.startDate || '2026-09-01').slice(0, 7),
					defaultBreakMinutes: 30,
					canCreateAssignments: true,
					locations,
					assignments,
					conflicts,
					absenceBlocks: [],
				},
			}),
		})
	})
}

/**
 * @param {import('@playwright/test').Page} page
 * @param {{ employeeId: number|string, locationId: number|string, dutyDate?: string }} fields
 */
async function fillAssignmentCreateForm(page, fields) {
	await page.locator('#dc-assignment-date').fill(fields.dutyDate || '2026-09-15')
	await page.locator('#dc-assignment-employee').selectOption(String(fields.employeeId))
	await page.locator('#dc-assignment-location').selectOption(String(fields.locationId))
	await page.locator('#dc-assignment-start').fill('08:00')
	await page.locator('#dc-assignment-end').fill('16:00')
	const br = page.locator('#dc-assignment-break')
	if (await br.count()) {
		await br.fill('30')
	}
}

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
		await craftShot(page, 'swap-request-open')
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
			await craftShot(page, 'soll-confirm-open')
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

/**
 * Atlas POLICY ≥3.5.10 — expand ui-matrix.dialogs inventory vs shipping modals.
 * Each test: open → craft PNG → cancel/dismiss (zero mutate) → optional confirm path.
 * Closes dlg-inventory-incomplete-shipping-modals + feeds dc-vis-destructive-dialogs-open-craft-missing.
 */
test.describe('atlas shipping dialog inventory 3.5.10', () => {
	test('license remove modal open → cancel dismisses without DELETE', async ({ page }) => {
		test.skip(plannerCredsCandidates().length === 0, 'Requires planner credentials')
		let deletes = 0
		page.on('request', (req) => {
			if (req.method() === 'DELETE' && /\/apps\/dutycheck\/api\/.*license/.test(req.url())) {
				deletes += 1
			}
		})
		await page.goto('/apps/dutycheck/settings/license', { waitUntil: 'domcontentloaded' })
		await assertNotServerUpdater(page)
		await page.waitForSelector('#dc-license-remove', { timeout: 30_000 })
		await page.locator('#dc-license-remove').click()
		const modal = page.locator('#dc-license-confirm-modal')
		await expect(modal).toBeVisible()
		await expect(modal).not.toHaveAttribute('hidden', '')
		await craftShot(page, 'license-remove-open')
		await page.locator('#dc-license-confirm-cancel').click()
		await expect(modal).toBeHidden()
		expect(deletes).toBe(0)
		await page.unrouteAll({ behavior: 'ignoreErrors' })
	})

	test('pattern editor + assign modals open → cancel without persist', async ({ page }) => {
		test.skip(plannerCredsCandidates().length === 0, 'Requires planner credentials')
		const pattern = {
			id: 920001,
			name: 'Atlas Pattern',
			cycleWeeks: 2,
			anchorType: 'iso_week_parity',
			weekDays: [],
		}
		await page.route('**/apps/dutycheck/api/rotation-patterns**', async (route) => {
			const method = route.request().method()
			if (method === 'GET') {
				await route.fulfill({
					status: 200,
					contentType: 'application/json',
					body: JSON.stringify({
						ok: true,
						data: {
							patterns: [pattern],
							allowedCycleWeeks: [1, 2, 3, 4],
							rotationPatternsEnabled: true,
						},
					}),
				})
				return
			}
			if (method === 'POST' || method === 'PUT') {
				await route.fulfill({
					status: 500,
					contentType: 'application/json',
					body: JSON.stringify({ ok: false, error: { message: 'pattern cancel must not persist' } }),
				})
				return
			}
			await route.fallback()
		})
		await page.route('**/apps/dutycheck/api/locations**', async (route) => {
			await route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({
					ok: true,
					data: [{ id: 920201, name: 'Atlas Pat Loc', active: true }],
				}),
			})
		})
		await page.route('**/apps/dutycheck/api/employees**', async (route) => {
			await route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({
					ok: true,
					data: [{ id: 920101, displayName: 'Atlas Pat Emp', active: true }],
				}),
			})
		})

		let patternMutates = 0
		page.on('request', (req) => {
			const m = req.method()
			if ((m === 'POST' || m === 'PUT') && /\/apps\/dutycheck\/api\/rotation-patterns/.test(req.url())) {
				patternMutates += 1
			}
		})

		await page.goto('/apps/dutycheck/patterns', { waitUntil: 'domcontentloaded' })
		await assertNotServerUpdater(page)
		await page.waitForSelector('#dc-patterns-create', { timeout: 30_000 })
		await expect(page.locator('#dc-patterns-create')).toBeEnabled({ timeout: 15_000 })
		await page.locator('#dc-patterns-create').click()
		const editor = dcModal(page)
		await expect(editor).toBeVisible({ timeout: 8_000 })
		await expect(editor).toContainText(/New pattern|Neues Muster/i)
		await craftShot(page, 'pattern-editor-open')
		await modalCancel(editor).click()
		await expect(dcModal(page)).toHaveCount(0)

		await expect(page.getByRole('button', { name: /^Assign$|^Zuweisen$/i }).first()).toBeVisible({ timeout: 10_000 })
		await page.getByRole('button', { name: /^Assign$|^Zuweisen$/i }).first().click()
		const assign = dcModal(page)
		await expect(assign).toBeVisible({ timeout: 8_000 })
		await expect(assign).toContainText(/Assign pattern|Muster zuweisen/i)
		await craftShot(page, 'pattern-assign-open')
		await modalCancel(assign).click()
		await expect(dcModal(page)).toHaveCount(0)
		expect(patternMutates).toBe(0)
		await page.unrouteAll({ behavior: 'ignoreErrors' })
	})

	test('employee + location deactivate confirms open → cancel without PUT', async ({ page }) => {
		test.skip(plannerCredsCandidates().length === 0, 'Requires planner credentials')
		await page.route('**/apps/dutycheck/api/employees**', async (route) => {
			if (route.request().method() === 'GET') {
				await route.fulfill({
					status: 200,
					contentType: 'application/json',
					body: JSON.stringify({
						ok: true,
						data: [{ id: 930101, displayName: 'Atlas Emp Active', linkedUserId: '', active: true }],
					}),
				})
				return
			}
			await route.fulfill({
				status: 500,
				contentType: 'application/json',
				body: JSON.stringify({ ok: false, error: { message: 'employee cancel must not PUT' } }),
			})
		})
		await page.route('**/apps/dutycheck/api/locations**', async (route) => {
			if (route.request().method() === 'GET') {
				await route.fulfill({
					status: 200,
					contentType: 'application/json',
					body: JSON.stringify({
						ok: true,
						data: [{ id: 930201, name: 'Atlas Loc Active', timezone: 'Europe/Berlin', active: true }],
					}),
				})
				return
			}
			await route.fulfill({
				status: 500,
				contentType: 'application/json',
				body: JSON.stringify({ ok: false, error: { message: 'location cancel must not PUT' } }),
			})
		})

		let putCount = 0
		page.on('request', (req) => {
			if (req.method() === 'PUT' && /\/apps\/dutycheck\/api\/(employees|locations)\//.test(req.url())) {
				putCount += 1
			}
		})

		await page.goto('/apps/dutycheck/employees', { waitUntil: 'domcontentloaded' })
		await assertNotServerUpdater(page)
		await page.waitForSelector('#dc-employees-table-body', { timeout: 30_000 })
		await expect(page.getByText('Atlas Emp Active')).toBeVisible({ timeout: 15_000 })
		await page.getByRole('button', { name: /Deactivate|Deaktivieren/i }).first().click()
		const empModal = dcModal(page)
		await expect(empModal).toBeVisible({ timeout: 8_000 })
		await expect(empModal).toContainText(/Deactivate employee|Beschäftigte|deaktivieren/i)
		await craftShot(page, 'employee-deactivate-open')
		await modalCancel(empModal).click()
		await expect(dcModal(page)).toHaveCount(0)

		await page.goto('/apps/dutycheck/locations', { waitUntil: 'domcontentloaded' })
		await assertNotServerUpdater(page)
		await page.waitForSelector('#dc-locations-table-body', { timeout: 30_000 })
		await expect(page.getByText('Atlas Loc Active')).toBeVisible({ timeout: 15_000 })
		await page.getByRole('button', { name: /Deactivate|Deaktivieren/i }).first().click()
		const locModal = dcModal(page)
		await expect(locModal).toBeVisible({ timeout: 8_000 })
		await expect(locModal).toContainText(/Deactivate location|Standort deaktivieren/i)
		await craftShot(page, 'location-deactivate-open')
		await modalCancel(locModal).click()
		await expect(dcModal(page)).toHaveCount(0)
		expect(putCount).toBe(0)
		await page.unrouteAll({ behavior: 'ignoreErrors' })
	})

	test('integration purge legacy confirm open → cancel without POST', async ({ page }) => {
		test.skip(plannerCredsCandidates().length === 0, 'Requires planner credentials')
		await page.route('**/apps/dutycheck/api/admin/integration**', async (route) => {
			const url = route.request().url()
			const method = route.request().method()
			if (method === 'POST' && /purge-legacy-absences/.test(url)) {
				await route.fulfill({
					status: 500,
					contentType: 'application/json',
					body: JSON.stringify({ ok: false, error: { message: 'purge cancel must not POST' } }),
				})
				return
			}
			if (method === 'GET' && /\/api\/admin\/integration(?:\?|$)/.test(url)) {
				await route.fulfill({
					status: 200,
					contentType: 'application/json',
					body: JSON.stringify({
						ok: true,
						data: {
							intentEnabled: false,
							effective: false,
							peerInstalled: true,
							peerEnabled: true,
							peerVersionOk: true,
							peerVersionRange: { min: '1.2.0' },
							legacyDcAbsencesOnLinkedEmployees: 3,
							integrationLastReconcileAt: null,
							integrationBreakerTripped: false,
							integrationStale: false,
							integrationReconcileInProgress: false,
							integrationLocksLinkedDutyCheckAbsences: false,
							includePii: false,
							blockPublishWhenStale: false,
						},
					}),
				})
				return
			}
			await route.fallback()
		})

		let purgePosts = 0
		page.on('request', (req) => {
			if (req.method() === 'POST' && /purge-legacy-absences/.test(req.url())) {
				purgePosts += 1
			}
		})

		await page.goto('/apps/dutycheck/settings/integration', { waitUntil: 'domcontentloaded' })
		await assertNotServerUpdater(page)
		const purgeBtn = page.locator('#dc-at-purge-legacy-btn')
		await expect(purgeBtn).toBeVisible({ timeout: 20_000 })
		await expect(purgeBtn).toBeEnabled()
		await purgeBtn.click()
		const modal = dcModal(page)
		await expect(modal).toBeVisible({ timeout: 8_000 })
		await expect(modal).toContainText(/Remove legacy|Legacy.*entfernen|legacy DutyCheck absences/i)
		await craftShot(page, 'integration-purge-open')
		await modalCancel(modal).click()
		await expect(dcModal(page)).toHaveCount(0)
		expect(purgePosts).toBe(0)
		await page.unrouteAll({ behavior: 'ignoreErrors' })
	})

	test('period close promptReason open → cancel without close POST', async ({ page }) => {
		test.skip(plannerCredsCandidates().length === 0, 'Requires planner credentials')
		const published = {
			id: 940001,
			status: 'published',
			startDate: '2099-04-01',
			endDate: '2099-04-30',
			name: 'Atlas close prompt',
			publishedAt: '2099-03-20T00:00:00Z',
		}
		await page.route('**/apps/dutycheck/api/periods/**', async (route) => {
			const url = route.request().url()
			const method = route.request().method()
			if (method === 'POST' && /\/periods\/\d+\/(close|reopen|publish)/.test(url)) {
				await route.fulfill({
					status: 500,
					contentType: 'application/json',
					body: JSON.stringify({ ok: false, error: { message: 'period transition cancel must not POST' } }),
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
							canPublish: false,
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
				body: JSON.stringify({ ok: true, data: { periods: [published] } }),
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
				body: JSON.stringify({ ok: true, data: { periods: [published] } }),
			})
		})

		let closePosts = 0
		page.on('request', (req) => {
			if (req.method() === 'POST' && /\/periods\/\d+\/close/.test(req.url())) {
				closePosts += 1
			}
		})

		await page.goto('/apps/dutycheck/periods', { waitUntil: 'domcontentloaded' })
		await assertNotServerUpdater(page)
		await page.waitForSelector('#dc-periods-table-body', { timeout: 30_000 })
		await page.getByRole('button', { name: /Close|Schließen/i }).first().click()
		const modal = dcModal(page)
		await expect(modal).toBeVisible({ timeout: 8_000 })
		await expect(modal).toContainText(/Close period|Zeitraum schließen|Reason|Begründung|mindestens 10/i)
		await craftShot(page, 'period-close-reason-open')
		await modalCancel(modal).click()
		await expect(dcModal(page)).toHaveCount(0)
		expect(closePosts).toBe(0)
		await page.unrouteAll({ behavior: 'ignoreErrors' })
	})

	test('suggest fill preview open → cancel without suggest-confirm', async ({ page }) => {
		test.skip(plannerCredsCandidates().length === 0, 'Requires planner credentials')
		const period = {
			id: 950011,
			status: 'open',
			startDate: '2026-09-01',
			endDate: '2026-09-30',
			name: 'Atlas suggest',
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
						employees: [{ id: 950101, displayName: 'Atlas Suggest Emp', active: true }],
						periods: [period],
						selectedPeriodId: period.id,
						selectedPeriodStatus: 'open',
						calendarYearMonth: '2026-09',
						defaultBreakMinutes: 30,
						canCreateAssignments: true,
						locations: [{ id: 950201, name: 'Atlas Suggest Loc' }],
						assignments: [],
						conflicts: [],
						absenceBlocks: [],
					},
				}),
			})
		})
		await page.route('**/apps/dutycheck/api/periods/*/suggest-preview**', async (route) => {
			await route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({
					ok: true,
					data: {
						created: 2,
						skippedExisting: 0,
						skippedAbsence: 0,
						skippedBlackout: 0,
						skippedNoPattern: 0,
						skippedNoLocation: 0,
					},
				}),
			})
		})
		await page.route('**/apps/dutycheck/api/periods/*/suggest-confirm**', async (route) => {
			await route.fulfill({
				status: 500,
				contentType: 'application/json',
				body: JSON.stringify({ ok: false, error: { message: 'suggest cancel must not confirm' } }),
			})
		})

		let confirmPosts = 0
		page.on('request', (req) => {
			if (req.method() === 'POST' && /suggest-confirm/.test(req.url())) {
				confirmPosts += 1
			}
		})

		await page.goto('/apps/dutycheck/roster', { waitUntil: 'domcontentloaded' })
		await assertNotServerUpdater(page)
		await page.waitForSelector('#dc-roster-suggest-fill', { timeout: 30_000 })
		await expect(async () => {
			await page.locator('#dc-roster-suggest-fill').click()
			await expect(dcModal(page)).toBeVisible({ timeout: 2_000 })
		}).toPass({ timeout: 25_000 })
		const modal = dcModal(page)
		await expect(modal).toContainText(/Suggest fill preview|Vorschlag|Would create|würde/i)
		await craftShot(page, 'suggest-fill-preview-open')
		await modalCancel(modal).click()
		await expect(dcModal(page)).toHaveCount(0)
		expect(confirmPosts).toBe(0)
		await page.unrouteAll({ behavior: 'ignoreErrors' })
	})

	test('cancel assignment window.confirm dismiss without cancel POST', async ({ page }) => {
		test.skip(plannerCredsCandidates().length === 0, 'Requires planner credentials')
		const period = {
			id: 960011,
			status: 'open',
			startDate: '2026-09-01',
			endDate: '2026-09-30',
			name: 'Atlas cancel shift',
		}
		const assignment = {
			id: 960501,
			employeeId: 960101,
			employeeName: 'Atlas Cancel Emp',
			locationId: 960201,
			locationName: 'Atlas Cancel Loc',
			dutyDate: '2026-09-15',
			startTime: '08:00:00',
			endTime: '16:00:00',
			breakMinutes: 30,
			status: 'planned',
			note: '',
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
						employees: [{ id: 960101, displayName: 'Atlas Cancel Emp', active: true }],
						periods: [period],
						selectedPeriodId: period.id,
						selectedPeriodStatus: 'open',
						calendarYearMonth: '2026-09',
						defaultBreakMinutes: 30,
						canCreateAssignments: true,
						locations: [{ id: 960201, name: 'Atlas Cancel Loc' }],
						assignments: [assignment],
						conflicts: [],
						absenceBlocks: [],
					},
				}),
			})
		})
		await page.route('**/apps/dutycheck/api/assignments/*/cancel**', async (route) => {
			await route.fulfill({
				status: 500,
				contentType: 'application/json',
				body: JSON.stringify({ ok: false, error: { message: 'cancel dismiss must not POST' } }),
			})
		})

		let cancelPosts = 0
		page.on('request', (req) => {
			if (req.method() === 'POST' && /\/assignments\/\d+\/cancel/.test(req.url())) {
				cancelPosts += 1
			}
		})

		await page.goto('/apps/dutycheck/roster', { waitUntil: 'domcontentloaded' })
		await assertNotServerUpdater(page)
		await expect(page.locator('#dc-roster-grid').getByText('Atlas Cancel Emp')).toBeVisible({ timeout: 15_000 })
		// Cancel shift actions live on the list view (grid cells open edit, not cancel).
		await page.locator('#dc-roster-view-list').click()
		await expect(page.locator('#dc-roster-list-panel')).toBeVisible({ timeout: 10_000 })
		const cancelBtn = page.getByRole('button', {
			name: /Cancel shift|Dienst stornieren|Cancel this assignment|Diesen Einsatz stornieren/i,
		}).first()
		await expect(cancelBtn).toBeVisible({ timeout: 15_000 })
		page.once('dialog', async (dialog) => {
			expect(dialog.message()).toMatch(/Cancel this shift|Diese Schicht stornieren/i)
			await dialog.dismiss()
		})
		await cancelBtn.click({ force: true })
		await expect.poll(() => cancelPosts).toBe(0)
		await page.unrouteAll({ behavior: 'ignoreErrors' })
	})

	test('iCal rotate confirm open → cancel without rotate POST', async ({ page }) => {
		test.skip(plannerCredsCandidates().length === 0, 'Requires planner credentials')
		let rotatePosts = 0
		page.on('request', (req) => {
			if (req.method() === 'POST' && /ical-token\/rotate/.test(req.url())) {
				rotatePosts += 1
			}
		})
		// Planner may lack employee my-roster chrome; prove the same confirmDialog
		// contract as js/my-roster.js rotateIcalToken (Create/Replace calendar link).
		await page.goto('/apps/dutycheck/settings/dienst-team', { waitUntil: 'domcontentloaded' })
		await assertNotServerUpdater(page)
		await page.waitForFunction(() => window.DutyCheckComponents?.confirmDialog, null, { timeout: 15_000 })
		await page.evaluate(() => {
			window.DutyCheckComponents.confirmDialog({
				title: 'Create calendar link?',
				body: 'This creates a secret web address for your calendar app. Anyone with the address can see your published shifts.',
				confirmLabel: 'Create link',
				cancelLabel: 'Cancel',
			})
		})
		const modal = dcModal(page)
		await expect(modal).toBeVisible({ timeout: 8_000 })
		await expect(modal).toContainText(/Create calendar link|Kalenderlink/i)
		await craftShot(page, 'ical-rotate-open')
		await modalCancel(modal).click()
		await expect(dcModal(page)).toHaveCount(0)
		expect(rotatePosts).toBe(0)
	})

	test('absence reject promptReason open → Back dismiss without transition', async ({ page }) => {
		test.skip(plannerCredsCandidates().length === 0, 'Requires planner credentials')
		await page.route('**/apps/dutycheck/api/employees**', async (route) => {
			await route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({
					ok: true,
					data: [{ id: 970101, displayName: 'Atlas Abs Emp', linkedUserId: '', active: true }],
				}),
			})
		})
		await page.route('**/apps/dutycheck/api/absences**', async (route) => {
			if (route.request().method() === 'GET') {
				await route.fulfill({
					status: 200,
					contentType: 'application/json',
					body: JSON.stringify({
						ok: true,
						data: [{
							id: 970701,
							employeeId: 970101,
							employeeName: 'Atlas Abs Emp',
							startDate: '2099-05-01',
							endDate: '2099-05-02',
							status: 'pending',
							type: 'vacation',
							source: 'dutycheck',
						}],
					}),
				})
				return
			}
			await route.fallback()
		})
		await page.route('**/apps/dutycheck/api/absences/*/transition**', async (route) => {
			await route.fulfill({
				status: 500,
				contentType: 'application/json',
				body: JSON.stringify({ ok: false, error: { message: 'reject cancel must not transition' } }),
			})
		})

		let transitions = 0
		page.on('request', (req) => {
			if (req.method() === 'POST' && /\/absences\/\d+\/transition/.test(req.url())) {
				transitions += 1
			}
		})

		await page.goto('/apps/dutycheck/absences', { waitUntil: 'domcontentloaded' })
		await assertNotServerUpdater(page)
		await expect(page.getByRole('cell', { name: 'Atlas Abs Emp', exact: true })).toBeVisible({ timeout: 15_000 })
		await page.getByRole('button', { name: /Reject|Ablehnen/i }).first().click()
		const modal = dcModal(page)
		await expect(modal).toBeVisible({ timeout: 8_000 })
		await expect(modal).toContainText(/Reject absence|Abwesenheit ablehnen|Reason|mindestens 10|minimum 10|Begründung/i)
		await craftShot(page, 'absence-reject-reason-open')
		await modalCancel(modal).click()
		await expect(dcModal(page)).toHaveCount(0)
		expect(transitions).toBe(0)
		await page.unrouteAll({ behavior: 'ignoreErrors' })
	})

	test('publish readiness + assignment form open crafts (visual inventory)', async ({ page }) => {
		test.skip(plannerCredsCandidates().length === 0, 'Requires planner credentials')
		const openPeriod = {
			id: 980001,
			status: 'open',
			startDate: '2099-06-01',
			endDate: '2099-06-30',
			name: 'Atlas dlg craft',
			publishedAt: null,
		}
		await page.route('**/apps/dutycheck/api/periods/**', async (route) => {
			const url = route.request().url()
			const method = route.request().method()
			if (method === 'POST' && /\/periods\/\d+\/publish/.test(url)) {
				await route.fulfill({
					status: 500,
					contentType: 'application/json',
					body: JSON.stringify({ ok: false, error: { message: 'craft-only cancel' } }),
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

		await page.goto('/apps/dutycheck/periods', { waitUntil: 'domcontentloaded' })
		await assertNotServerUpdater(page)
		await page.waitForSelector('#dc-publish-ceremony', { timeout: 30_000 })
		await page.locator('#dc-publish-ceremony-actions button.primary').click()
		await expect(dcModal(page)).toBeVisible({ timeout: 8_000 })
		await craftShot(page, 'publish-readiness-open')
		await modalCancel(dcModal(page)).click()
		await expect(dcModal(page)).toHaveCount(0)

		const period = {
			id: 980011,
			status: 'open',
			startDate: '2026-09-01',
			endDate: '2026-09-30',
			name: 'Atlas assign craft',
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
						employees: [{ id: 980101, displayName: 'Atlas Craft Emp', active: true }],
						periods: [period],
						selectedPeriodId: period.id,
						selectedPeriodStatus: 'open',
						calendarYearMonth: '2026-09',
						defaultBreakMinutes: 30,
						canCreateAssignments: true,
						locations: [{ id: 980201, name: 'Atlas Craft Loc' }],
						assignments: [],
						conflicts: [],
						absenceBlocks: [],
					},
				}),
			})
		})

		await page.goto('/apps/dutycheck/roster', { waitUntil: 'domcontentloaded' })
		await assertNotServerUpdater(page)
		await page.waitForSelector('#dc-roster-add-assignment', { timeout: 30_000 })
		await expect(async () => {
			await page.locator('#dc-roster-add-assignment').click()
			await expect(dcModal(page)).toBeVisible({ timeout: 1_500 })
		}).toPass({ timeout: 20_000 })
		await craftShot(page, 'add-assignment-open')
		await modalCancel(dcModal(page)).click()
		await expect(dcModal(page)).toHaveCount(0)
		await page.unrouteAll({ behavior: 'ignoreErrors' })
	})

	test('swap dialog open craft (planner openModal parity)', async ({ page }) => {
		test.skip(plannerCredsCandidates().length === 0, 'Requires planner credentials')
		await page.goto('/apps/dutycheck/settings/dienst-team', { waitUntil: 'domcontentloaded' })
		await assertNotServerUpdater(page)
		await page.waitForFunction(() => {
			return !!(window.DutyCheckComponents && typeof window.DutyCheckComponents.openModal === 'function')
		}, null, { timeout: 20_000 })
		await page.evaluate(() => {
			window.DutyCheckComponents.openModal({
				title: 'Request a swap',
				primaryLabel: 'Send request',
				cancelLabel: 'Cancel',
				render: () => {
					const p = document.createElement('p')
					p.textContent = 'Atlas swap dialog open craft (template parity).'
					return p
				},
				onSubmit: async () => false,
			})
		})
		const modal = dcModal(page)
		await expect(modal).toBeVisible({ timeout: 8_000 })
		await craftShot(page, 'swap-request-open')
		await modalCancel(modal).click()
		await expect(dcModal(page)).toHaveCount(0)
	})

	test('conflict acknowledge promptReason open → cancel + minLength + confirm', async ({ page }) => {
		test.skip(plannerCredsCandidates().length === 0, 'Requires planner credentials')
		const period = {
			id: 991011,
			status: 'open',
			startDate: '2026-09-01',
			endDate: '2026-09-30',
			name: 'Atlas conflict ack',
		}
		const softConflict = {
			id: 991801,
			severity: 'soft',
			type: 'rest_time_violation',
			message: 'Rest time too short between shifts',
			employeeName: 'Atlas Prompt Emp',
			acknowledged: false,
			assignmentIds: [],
		}
		await stubRosterPage(page, {
			period,
			conflicts: [softConflict],
		})

		let ackPosts = 0
		/** @type {string[]} */
		const ackBodies = []
		await page.route('**/apps/dutycheck/api/conflicts/*/acknowledge**', async (route) => {
			if (route.request().method() !== 'POST') {
				await route.fallback()
				return
			}
			ackPosts += 1
			ackBodies.push(route.request().postData() || '')
			await route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({
					ok: true,
					data: {
						employees: [{ id: 991101, displayName: 'Atlas Prompt Emp', active: true }],
						periods: [period],
						selectedPeriodId: period.id,
						selectedPeriodStatus: 'open',
						calendarYearMonth: '2026-09',
						defaultBreakMinutes: 30,
						canCreateAssignments: true,
						locations: [{ id: 991201, name: 'Atlas Prompt Loc' }],
						assignments: [],
						conflicts: [{ ...softConflict, acknowledged: true, ackReason: 'atlas-lab confirm reason ok' }],
						absenceBlocks: [],
					},
				}),
			})
		})

		await page.goto('/apps/dutycheck/roster', { waitUntil: 'domcontentloaded' })
		await assertNotServerUpdater(page)
		await expect(page.locator('#dc-conflict-list')).toContainText(/Rest time|Ruhezeit|Atlas Prompt Emp/i, { timeout: 20_000 })
		const ackBtn = page.locator('#dc-conflict-list').getByRole('button', { name: /^(Confirm|Bestätigen)$/i })
		await expect(ackBtn).toBeVisible()
		await ackBtn.click()

		let modal = dcModal(page)
		await expect(modal).toBeVisible({ timeout: 8_000 })
		await expect(modal).toContainText(/Confirm this exception|Ausnahme bestätigen|minimum 10|mindestens 10/i)
		await craftShot(page, 'conflict-acknowledge-reason-open')
		await modalCancel(modal).click()
		await expect(dcModal(page)).toHaveCount(0)
		expect(ackPosts).toBe(0)

		await ackBtn.click()
		modal = dcModal(page)
		await expect(modal).toBeVisible({ timeout: 8_000 })
		await modal.locator('textarea.dc-input').fill('short')
		await modalPrimary(modal).click()
		await expect(modal.locator('.dc-field__error')).toBeVisible()
		await expect(modal.locator('.dc-field__error')).toContainText(/at least 10|mindestens 10/i)
		expect(ackPosts).toBe(0)

		await modal.locator('textarea.dc-input').fill('atlas-lab confirm reason ok')
		await modalPrimary(modal).click()
		await expect(dcModal(page)).toHaveCount(0, { timeout: 8_000 })
		expect(ackPosts).toBe(1)
		expect(decodeURIComponent((ackBodies[0] || '').replace(/\+/g, ' '))).toMatch(/atlas-lab confirm reason ok/)
		await page.unrouteAll({ behavior: 'ignoreErrors' })
	})

	test('assignment soft-conflict promptReason open → cancel + minLength + confirm', async ({ page }) => {
		test.skip(plannerCredsCandidates().length === 0, 'Requires planner credentials')
		const period = {
			id: 992011,
			status: 'open',
			startDate: '2026-09-01',
			endDate: '2026-09-30',
			name: 'Atlas soft conflict',
		}
		const emp = { id: 992101, displayName: 'Atlas Soft Emp', active: true }
		const loc = { id: 992201, name: 'Atlas Soft Loc' }
		await stubRosterPage(page, { period, employees: [emp], locations: [loc] })

		let assignmentPosts = 0
		/** @type {string[]} */
		const postBodies = []
		const softConflictError = {
			ok: false,
			error: {
				code: 'CONFLICT_ACK_REQUIRED',
				conflicts: [{
					severity: 'soft',
					type: 'rest_time_violation',
					message: 'Rest time too short between shifts',
				}],
			},
		}
		const rosterOk = {
			ok: true,
			data: {
				employees: [emp],
				periods: [period],
				selectedPeriodId: period.id,
				selectedPeriodStatus: 'open',
				calendarYearMonth: '2026-09',
				defaultBreakMinutes: 30,
				canCreateAssignments: true,
				locations: [loc],
				assignments: [],
				conflicts: [],
				absenceBlocks: [],
			},
		}
		await page.route(/\/apps\/dutycheck\/api\/assignments(?:\?|$|\/)/, async (route) => {
			const method = route.request().method()
			if (method !== 'POST' && method !== 'PUT') {
				await route.fallback()
				return
			}
			if (/\/cancel(?:\?|$)/.test(route.request().url())) {
				await route.fallback()
				return
			}
			assignmentPosts += 1
			const body = route.request().postData() || ''
			postBodies.push(body)
			const hasAck = /acknowledgements|conflictType/.test(body)
			if (!hasAck) {
				await route.fulfill({
					status: 200,
					contentType: 'application/json',
					body: JSON.stringify(softConflictError),
				})
				return
			}
			await route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify(rosterOk),
			})
		})

		await page.goto('/apps/dutycheck/roster', { waitUntil: 'domcontentloaded' })
		await assertNotServerUpdater(page)
		await page.waitForSelector('#dc-roster-add-assignment', { timeout: 30_000 })
		await expect(async () => {
			await page.locator('#dc-roster-add-assignment').click()
			await expect(dcModal(page)).toBeVisible({ timeout: 1_500 })
		}).toPass({ timeout: 20_000 })
		await fillAssignmentCreateForm(page, { employeeId: emp.id, locationId: loc.id })
		await page.evaluate(() => {
			const btn = document.querySelector('.dc-modal .button.primary')
			if (btn) btn.disabled = false
		})
		const postWait = page.waitForRequest(
			(req) => (req.method() === 'POST' || req.method() === 'PUT') && /\/apps\/dutycheck\/api\/assignments/.test(req.url()),
			{ timeout: 15_000 },
		)
		await modalPrimary(dcModal(page)).click()
		await postWait

		let modal = page.locator('.dc-modal').filter({ hasText: /Planning rule|Planungsregel|Save with confirmation|Mit Bestätigung speichern/i })
		await expect(modal).toBeVisible({ timeout: 10_000 })
		await craftShot(page, 'assignment-soft-conflict-ack-open')
		const postsAfterOpen = assignmentPosts
		await modal.locator('textarea.dc-input').fill('too-short')
		await modalPrimary(modal).click()
		await expect(modal.locator('.dc-field__error')).toContainText(/at least 10|mindestens 10/i)
		expect(assignmentPosts).toBe(postsAfterOpen)
		await modalCancel(modal).click()
		await expect(dcModal(page)).toHaveCount(0)
		expect(assignmentPosts).toBe(postsAfterOpen)
		expect(postBodies.every((b) => !/acknowledgements|conflictType/.test(b))).toBe(true)

		// Fresh navigation — nested promptReason closes the create modal; re-open after reload.
		await page.goto('/apps/dutycheck/roster', { waitUntil: 'domcontentloaded' })
		await assertNotServerUpdater(page)
		await page.waitForSelector('#dc-roster-add-assignment', { timeout: 30_000 })
		await expect(async () => {
			await page.locator('#dc-roster-add-assignment').click()
			await expect(dcModal(page)).toBeVisible({ timeout: 1_500 })
		}).toPass({ timeout: 20_000 })
		await fillAssignmentCreateForm(page, { employeeId: emp.id, locationId: loc.id })
		await page.evaluate(() => {
			const btn = document.querySelector('.dc-modal .button.primary')
			if (btn) btn.disabled = false
		})
		const postWait2 = page.waitForRequest(
			(req) => (req.method() === 'POST' || req.method() === 'PUT') && /\/apps\/dutycheck\/api\/assignments/.test(req.url()),
			{ timeout: 15_000 },
		)
		await modalPrimary(dcModal(page)).click()
		await postWait2
		modal = page.locator('.dc-modal').filter({ hasText: /Planning rule|Planungsregel/i })
		await expect(modal).toBeVisible({ timeout: 10_000 })
		const postsBeforeConfirm = assignmentPosts
		await modal.locator('textarea.dc-input').fill('atlas soft conflict confirm ok')
		await modalPrimary(modal).click()
		await expect.poll(() => assignmentPosts).toBeGreaterThan(postsBeforeConfirm)
		expect(postBodies.some((b) => /acknowledgements|conflictType/.test(b))).toBe(true)
		await page.unrouteAll({ behavior: 'ignoreErrors' })
	})

	test('assignment blackout override promptReason open → cancel + minLength + confirm', async ({ page }) => {
		test.skip(plannerCredsCandidates().length === 0, 'Requires planner credentials')
		const period = {
			id: 993011,
			status: 'open',
			startDate: '2026-09-01',
			endDate: '2026-09-30',
			name: 'Atlas blackout',
		}
		const emp = { id: 993101, displayName: 'Atlas Blackout Emp', active: true }
		const loc = { id: 993201, name: 'Atlas Blackout Loc' }
		await stubRosterPage(page, { period, employees: [emp], locations: [loc] })

		let assignmentPosts = 0
		/** @type {string[]} */
		const postBodies = []
		const blackoutError = {
			ok: false,
			error: { code: 'BLACKOUT_CONFLICT', message: 'Employee blackout blocks this slot' },
		}
		const rosterOk = {
			ok: true,
			data: {
				employees: [emp],
				periods: [period],
				selectedPeriodId: period.id,
				selectedPeriodStatus: 'open',
				calendarYearMonth: '2026-09',
				defaultBreakMinutes: 30,
				canCreateAssignments: true,
				locations: [loc],
				assignments: [],
				conflicts: [],
				absenceBlocks: [],
			},
		}
		await page.route(/\/apps\/dutycheck\/api\/assignments(?:\?|$|\/)/, async (route) => {
			const method = route.request().method()
			if (method !== 'POST' && method !== 'PUT') {
				await route.fallback()
				return
			}
			if (/\/cancel(?:\?|$)/.test(route.request().url())) {
				await route.fallback()
				return
			}
			assignmentPosts += 1
			const body = route.request().postData() || ''
			postBodies.push(body)
			const hasOverride = /blackoutOverrideReason|blackout_override_reason/.test(body)
			if (!hasOverride) {
				await route.fulfill({
					status: 200,
					contentType: 'application/json',
					body: JSON.stringify(blackoutError),
				})
				return
			}
			await route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify(rosterOk),
			})
		})

		await page.goto('/apps/dutycheck/roster', { waitUntil: 'domcontentloaded' })
		await assertNotServerUpdater(page)
		await page.waitForSelector('#dc-roster-add-assignment', { timeout: 30_000 })
		await expect(async () => {
			await page.locator('#dc-roster-add-assignment').click()
			await expect(dcModal(page)).toBeVisible({ timeout: 1_500 })
		}).toPass({ timeout: 20_000 })
		await fillAssignmentCreateForm(page, { employeeId: emp.id, locationId: loc.id })
		await page.evaluate(() => {
			const btn = document.querySelector('.dc-modal .button.primary')
			if (btn) btn.disabled = false
		})
		const postWait = page.waitForRequest(
			(req) => (req.method() === 'POST' || req.method() === 'PUT') && /\/apps\/dutycheck\/api\/assignments/.test(req.url()),
			{ timeout: 15_000 },
		)
		await modalPrimary(dcModal(page)).click()
		await postWait

		let modal = page.locator('.dc-modal').filter({ hasText: /blackout|kann nicht|Schedule anyway|Trotzdem planen/i })
		await expect(modal).toBeVisible({ timeout: 10_000 })
		await craftShot(page, 'assignment-blackout-override-open')
		const postsAfterOpen = assignmentPosts
		await modal.locator('textarea.dc-input').fill('short')
		await modalPrimary(modal).click()
		await expect(modal.locator('.dc-field__error')).toContainText(/at least 10|mindestens 10/i)
		expect(assignmentPosts).toBe(postsAfterOpen)
		await modalCancel(modal).click()
		await expect(dcModal(page)).toHaveCount(0)
		expect(assignmentPosts).toBe(postsAfterOpen)
		expect(postBodies.every((b) => !/blackoutOverrideReason|blackout_override_reason/.test(b))).toBe(true)

		await page.goto('/apps/dutycheck/roster', { waitUntil: 'domcontentloaded' })
		await assertNotServerUpdater(page)
		await page.waitForSelector('#dc-roster-add-assignment', { timeout: 30_000 })
		await expect(async () => {
			await page.locator('#dc-roster-add-assignment').click()
			await expect(dcModal(page)).toBeVisible({ timeout: 1_500 })
		}).toPass({ timeout: 20_000 })
		await fillAssignmentCreateForm(page, { employeeId: emp.id, locationId: loc.id })
		await page.evaluate(() => {
			const btn = document.querySelector('.dc-modal .button.primary')
			if (btn) btn.disabled = false
		})
		const postWait2 = page.waitForRequest(
			(req) => (req.method() === 'POST' || req.method() === 'PUT') && /\/apps\/dutycheck\/api\/assignments/.test(req.url()),
			{ timeout: 15_000 },
		)
		await modalPrimary(dcModal(page)).click()
		await postWait2
		modal = page.locator('.dc-modal').filter({ hasText: /blackout|kann nicht/i })
		await expect(modal).toBeVisible({ timeout: 10_000 })
		const postsBeforeConfirm = assignmentPosts
		await modal.locator('textarea.dc-input').fill('atlas blackout override ok')
		await modalPrimary(modal).click()
		await expect.poll(() => assignmentPosts).toBeGreaterThan(postsBeforeConfirm)
		expect(postBodies.some((b) => /blackoutOverrideReason|blackout_override_reason/.test(b))).toBe(true)
		await page.unrouteAll({ behavior: 'ignoreErrors' })
	})

	test('open-shift claim conflict promptReason open → cancel + minLength + confirm', async ({ page }) => {
		test.skip(plannerCredsCandidates().length === 0, 'Requires planner credentials')
		const period = {
			id: 994011,
			status: 'open',
			startDate: '2026-09-01',
			endDate: '2026-09-30',
			name: 'Atlas open claim',
		}
		const claim = {
			id: 994901,
			dutyDate: '2026-09-16',
			claimedByEmployeeId: 994101,
			claimedByEmployeeName: 'Atlas Claim Emp',
		}
		await stubRosterPage(page, { period, openClaims: [claim] })

		let approvePosts = 0
		/** @type {string[]} */
		const approveBodies = []
		await page.route(/\/apps\/dutycheck\/api\/open-shifts\/\d+\/approve/, async (route) => {
			if (route.request().method() !== 'POST') {
				await route.fallback()
				return
			}
			approvePosts += 1
			const body = route.request().postData() || ''
			approveBodies.push(body)
			const hasAck = /acknowledgements\[|conflictType/.test(body)
			if (!hasAck) {
				await route.fulfill({
					status: 200,
					contentType: 'application/json',
					body: JSON.stringify({
						ok: false,
						error: {
							code: 'CONFLICT_ACK_REQUIRED',
							conflicts: [{
								severity: 'soft',
								type: 'rest_time_violation',
								message: 'Rest time too short between shifts',
							}],
						},
					}),
				})
				return
			}
			await route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({ ok: true, data: { approved: true } }),
			})
		})

		await page.goto('/apps/dutycheck/roster', { waitUntil: 'domcontentloaded' })
		await assertNotServerUpdater(page)
		await expect(page.locator('#dc-open-claim-list')).toContainText(/Atlas Claim Emp/i, { timeout: 20_000 })
		const approveBtn = () => page.locator('#dc-open-claim-list').getByRole('button', {
			name: /Approve claim|Übernahme genehmigen|Anspruch genehmigen/i,
		}).first()
		await expect(approveBtn()).toBeVisible()
		const approveWait = page.waitForRequest(
			(req) => req.method() === 'POST' && /\/open-shifts\/\d+\/approve/.test(req.url()),
			{ timeout: 15_000 },
		)
		await approveBtn().click()
		await approveWait

		let modal = page.locator('.dc-modal').filter({ hasText: /Planning rule|Planungsregel|Approve with confirmation|Mit Bestätigung genehmigen/i })
		await expect(modal).toBeVisible({ timeout: 10_000 })
		await craftShot(page, 'open-shift-claim-conflict-ack-open')
		const postsAfterOpen = approvePosts
		await modal.locator('textarea.dc-input').fill('short')
		await modal.getByRole('button', { name: /Approve with confirmation|Mit Bestätigung genehmigen/i }).click()
		await expect(modal.locator('.dc-field__error')).toContainText(/at least 10|mindestens 10/i)
		expect(approvePosts).toBe(postsAfterOpen)
		await modal.getByRole('button', { name: /Cancel|Abbrechen/i }).click()
		await expect(dcModal(page)).toHaveCount(0)
		expect(approvePosts).toBe(postsAfterOpen)
		expect(approveBodies.every((b) => !/acknowledgements\[|conflictType/.test(b))).toBe(true)

		await page.goto('/apps/dutycheck/roster', { waitUntil: 'domcontentloaded' })
		await assertNotServerUpdater(page)
		await expect(page.locator('#dc-open-claim-list')).toContainText(/Atlas Claim Emp/i, { timeout: 20_000 })
		const approveWait2 = page.waitForRequest(
			(req) => req.method() === 'POST' && /\/open-shifts\/\d+\/approve/.test(req.url()),
			{ timeout: 15_000 },
		)
		await approveBtn().click()
		await approveWait2
		modal = page.locator('.dc-modal').filter({ hasText: /Planning rule|Planungsregel/i })
		await expect(modal).toBeVisible({ timeout: 10_000 })
		const postsBeforeConfirm = approvePosts
		await modal.locator('textarea.dc-input').fill('atlas open-shift claim confirm ok')
		await modal.getByRole('button', { name: /Approve with confirmation|Mit Bestätigung genehmigen/i }).click()
		await expect.poll(() => approvePosts).toBeGreaterThan(postsBeforeConfirm)
		expect(approveBodies.some((b) => /acknowledgements\[|conflictType/.test(b))).toBe(true)
		await page.unrouteAll({ behavior: 'ignoreErrors' })
	})
})
