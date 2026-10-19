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
 * Permanent destructive-clarity probe (learned regression
 * dc-vis-destructive-confirm-missing-danger-variant): a destructive
 * confirmDialog primary must carry .danger AND paint the resolved danger
 * fill — a missing `danger: true` leaves NC primary blue and fails this
 * leg instead of silently regressing a third site.
 * @param {import('@playwright/test').Locator} modal
 */
async function expectDangerPrimary(modal) {
	const primary = modalPrimary(modal)
	await expect(primary).toBeVisible()
	await expect(primary).toHaveClass(/\bdanger\b/)
	const { bg, dangerBg } = await primary.evaluate((btn) => {
		const probe = document.createElement('div')
		probe.style.backgroundColor = 'var(--dc-danger-fill, var(--color-element-error, #ba1b1b))'
		document.body.appendChild(probe)
		const resolved = getComputedStyle(probe).backgroundColor
		probe.remove()
		return { bg: getComputedStyle(btn).backgroundColor, dangerBg: resolved }
	})
	expect(bg).toBe(dangerBg)
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

/**
 * Desktop shell for chip/dialog clicks: closed drawer + ≥1024 viewport so
 * sticky #app-navigation sits beside content (not over it).
 * @param {import('@playwright/test').Page} page
 */
async function ensureDesktopShell(page) {
	await page.setViewportSize({ width: 1280, height: 720 })
	await page.evaluate(() => {
		if (window.DutyCheckMobileNav && typeof window.DutyCheckMobileNav.close === 'function') {
			window.DutyCheckMobileNav.close()
		}
		document.body.classList.remove('dc-nav-open')
		document.getElementById('app-navigation')?.classList.remove('dc-nav--open')
	}).catch(() => {})
}

test.describe('atlas employee filters + swap dismiss', () => {
	// Planner storageState must not leak into employee self-service routes.
	test.use({ storageState: { cookies: [], origins: [] } })

	test.beforeEach(async ({ page }) => {
		await ensureDesktopShell(page)
	})

	test('my-roster quick-range chips toggle each value + empty-range honest copy', async ({ page }) => {
		test.skip(!process.env.NC_EMPLOYEE_USER, 'Requires NC_EMPLOYEE_* credentials')
		await login(page, credsFromEnv('EMPLOYEE'))
		await page.goto('/apps/dutycheck/my-roster', { waitUntil: 'domcontentloaded' })
		await assertNotServerUpdater(page)
		await ensureDesktopShell(page)
		await page.waitForSelector('#dc-my-roster-quickfilters', { timeout: 30_000 })

		const chips = page.locator('#dc-my-roster-quickfilters .dc-quickfilters__btn')
		await expect(chips).toHaveCount(QUICK_RANGES.length)

		for (const key of QUICK_RANGES) {
			const chip = page.locator(`#dc-my-roster-quickfilters .dc-quickfilters__btn[data-range="${key}"]`)
			await expect(chip).toBeVisible()
			await expect(chip).toBeEnabled()
			// Settle on the roster fetch itself — the Loading status text is
			// localized, so a text wait can no-op and let a stale in-flight
			// response clobber the render under test.
			const rosterResponse = page.waitForResponse(
				(r) => /\/apps\/dutycheck\/api\/my\/roster(\?|$)/.test(r.url()) && r.request().method() === 'GET',
				{ timeout: 15_000 },
			).catch(() => null)
			await chip.click()
			await expect(chip).toHaveAttribute('aria-pressed', 'true')
			for (const other of QUICK_RANGES.filter((k) => k !== key)) {
				await expect(
					page.locator(`#dc-my-roster-quickfilters .dc-quickfilters__btn[data-range="${other}"]`),
				).toHaveAttribute('aria-pressed', 'false')
			}
			await rosterResponse
		}

		// Force empty result for an honest empty-range copy (independent of seed roster).
		await page.route(/\/apps\/dutycheck\/api\/my\/roster(\?|$)/, async (route) => {
			await route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({ ok: true, data: [] }),
			})
		})
		await Promise.all([
			page.waitForResponse((r) => /\/apps\/dutycheck\/api\/my\/roster/.test(r.url()) && r.ok(), {
				timeout: 20_000,
			}),
			page.locator('#dc-my-roster-quickfilters .dc-quickfilters__btn[data-range="today"]').click(),
		])
		const emptyRow = page.locator('#dc-my-roster-table-body .dc-table__empty-row')
		await expect(emptyRow).toBeVisible({ timeout: 15_000 })
		// Honest copy is rendered in the fixture's locale — assert the
		// structural contract (headline + next-step hint), not EN/DE text.
		await expect(emptyRow.locator('strong')).toHaveText(/\S/)
		await expect(emptyRow.locator('.dc-field__hint')).toHaveText(/\S/)
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
		await page.route(/\/apps\/dutycheck\/api\/my\/roster(\?|$)/, async (route) => {
			await route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({ ok: true, data: [fakeRow] }),
			})
		})
		await page.route(/\/apps\/dutycheck\/api\/my\/swap-candidates(\?|$)/, async (route) => {
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
		await ensureDesktopShell(page)
		await page.waitForSelector('#dc-my-roster-table-body', { timeout: 30_000 })
		const swapBtn = page.locator('.dc-swap-request-btn').first()
		await expect(swapBtn).toBeVisible({ timeout: 15_000 })
		await swapBtn.click()

		const dialog = page.locator('#dc-swap-dialog')
		await expect(dialog).toBeVisible()
		await expect(dialog.locator('#dc-swap-dialog-title')).toHaveText(/\S/)
		await craftShot(page, 'swap-request-open')
		await dialog.locator('button[type="submit"][value="cancel"]').click()
		await expect(dialog).toBeHidden()
		expect(swapPosts).toBe(0)
	})

	const WITHDRAW_ROW = {
		id: 90002,
		dutyDate: '2099-01-16',
		startTime: '08:00:00',
		endTime: '16:00:00',
		breakMinutes: 30,
		locationName: 'Atlas Withdraw Lab',
		acknowledgedAt: null,
		note: '',
	}
	const WITHDRAW_SWAP = { id: 980002, assignmentId: 90002, status: 'open' }

	/**
	 * My-roster stub with one roster row carrying an open swap request, so the
	 * swap cell renders .dc-swap-withdraw-btn. state.swaps backs the GET and is
	 * mutated by the withdraw hook so the confirm leg re-reads a durable
	 * postcondition (withdraw button gone, request button back).
	 * @param {import('@playwright/test').Page} page
	 * @param {{ swaps: object[], onWithdraw?: Function }} state
	 */
	async function stubMyRosterWithSwap(page, state) {
		await page.route(/\/apps\/dutycheck\/api\/my\/roster(\?|$)/, async (route) => {
			await route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({ ok: true, data: [{ ...WITHDRAW_ROW }] }),
			})
		})
		// No end anchor: /my/swaps/{id}/withdraw must be caught too.
		await page.route(/\/apps\/dutycheck\/api\/my\/swaps/, async (route) => {
			const req = route.request()
			if (req.method() === 'GET' && /\/my\/swaps(\?|$)/.test(req.url())) {
				await route.fulfill({
					status: 200,
					contentType: 'application/json',
					body: JSON.stringify({ ok: true, data: state.swaps }),
				})
				return
			}
			if (req.method() === 'POST' && /\/my\/swaps\/\d+\/withdraw/.test(req.url())) {
				if (typeof state.onWithdraw === 'function') {
					await state.onWithdraw(route)
					return
				}
			}
			await route.fallback()
		})
	}

	/** @param {import('@playwright/test').Page} page */
	async function openMyRoster(page) {
		await page.goto('/apps/dutycheck/my-roster', { waitUntil: 'domcontentloaded' })
		await assertNotServerUpdater(page)
		await ensureDesktopShell(page)
		await page.waitForSelector('#dc-my-roster-table-body', { timeout: 30_000 })
		await expect(page.locator('.dc-swap-withdraw-btn')).toHaveCount(1, { timeout: 15_000 })
	}

	test('withdraw swap confirm open → cancel keeps request, no withdraw POST', async ({ page }) => {
		test.skip(!process.env.NC_EMPLOYEE_USER, 'Requires NC_EMPLOYEE_* credentials')
		await login(page, credsFromEnv('EMPLOYEE'))
		const state = { swaps: [{ ...WITHDRAW_SWAP }] }
		let withdrawPosts = 0
		page.on('request', (req) => {
			if (req.method() === 'POST' && /\/my\/swaps\/\d+\/withdraw/.test(req.url())) {
				withdrawPosts += 1
			}
		})
		await stubMyRosterWithSwap(page, state)
		await openMyRoster(page)

		await page.locator('.dc-swap-withdraw-btn').first().click()
		const modal = dcModal(page)
		await expect(modal).toBeVisible({ timeout: 8_000 })
		await expectDangerPrimary(modal)
		await expect(modalCancel(modal)).toBeVisible()
		await craftShot(page, 'swap-withdraw-open')
		await modalCancel(modal).click()
		await expect(dcModal(page)).toHaveCount(0)

		expect(withdrawPosts).toBe(0)
		// Request survives an aborted withdraw.
		await expect(page.locator('.dc-swap-withdraw-btn')).toHaveCount(1)
		await page.unrouteAll({ behavior: 'ignoreErrors' })
	})

	test('withdraw swap confirm → withdraw POST fires, request gone after refetch', async ({ page }) => {
		test.skip(!process.env.NC_EMPLOYEE_USER, 'Requires NC_EMPLOYEE_* credentials')
		await login(page, credsFromEnv('EMPLOYEE'))
		const state = {
			swaps: [{ ...WITHDRAW_SWAP }],
			onWithdraw: async (route) => {
				state.swaps = []
				await route.fulfill({
					status: 200,
					contentType: 'application/json',
					body: JSON.stringify({ ok: true, data: {} }),
				})
			},
		}
		const withdrawUrls = []
		page.on('request', (req) => {
			if (req.method() === 'POST' && /\/my\/swaps\/\d+\/withdraw/.test(req.url())) {
				withdrawUrls.push(req.url())
			}
		})
		await stubMyRosterWithSwap(page, state)
		await openMyRoster(page)

		await page.locator('.dc-swap-withdraw-btn').first().click()
		const modal = dcModal(page)
		await expect(modal).toBeVisible({ timeout: 8_000 })
		await expectDangerPrimary(modal)
		await modalPrimary(modal).click()

		await expect.poll(() => withdrawUrls.length, { timeout: 10_000 }).toBe(1)
		expect(withdrawUrls[0]).toMatch(new RegExp(`/apps/dutycheck/api/my/swaps/${WITHDRAW_SWAP.id}/withdraw`))
		// Durable postcondition: refetched swaps list is empty, so the row now
		// offers Request swap instead of Withdraw.
		await expect(page.locator('.dc-swap-withdraw-btn')).toHaveCount(0, { timeout: 15_000 })
		await expect(page.locator('.dc-swap-request-btn')).toHaveCount(1)
		await page.unrouteAll({ behavior: 'ignoreErrors' })
	})
})

test.describe('atlas planner dialog dismiss', () => {
	test.beforeEach(async ({ page }) => {
		await ensureDesktopShell(page)
	})

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
			await expect(modal).toContainText(/Soll (from|aus|z) Duty/i)
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

		// Overlap the month navigator with the fixture month: bootstrapping calls
		// ensure-calendar-month for the REAL current month and sets gridMonthClamp
		// from it, so without this stub the Sep-2026 rows are clamped away.
		const period = {
			id: 910011,
			status: 'open',
			startDate: '2026-09-01',
			endDate: '2026-09-30',
			name: 'Atlas assignment clear',
		}

		await page.route('**/apps/dutycheck/api/periods/ensure-calendar-month**', async (route) => {
			await route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({
					ok: true,
					data: { period, created: false, writable: true, yearMonth: '2026-09' },
				}),
			})
		})
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
	test.beforeEach(async ({ page }) => {
		await ensureDesktopShell(page)
	})

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

		await expect(page.locator('.dc-pattern-assign-btn').first()).toBeVisible({ timeout: 10_000 })
		await page.locator('.dc-pattern-assign-btn').first().click()
		const assign = dcModal(page)
		await expect(assign).toBeVisible({ timeout: 8_000 })
		await expect(assign).toContainText(/Assign pattern|Muster zuweisen/i)
		await craftShot(page, 'pattern-assign-open')
		await modalCancel(assign).click()
		await expect(dcModal(page)).toHaveCount(0)
		expect(patternMutates).toBe(0)
		await page.unrouteAll({ behavior: 'ignoreErrors' })
	})

	test('assign dialog lists existing assignments and auto-checks supersede on active overlap', async ({ page }) => {
		test.skip(plannerCredsCandidates().length === 0, 'Requires planner credentials')
		const pattern = {
			id: 920001,
			name: 'Atlas Pattern',
			cycleWeeks: 2,
			anchorType: 'iso_week_parity',
			weekDays: [],
		}
		await page.route('**/apps/dutycheck/api/rotation-patterns**', async (route) => {
			if (route.request().method() === 'GET') {
				await route.fulfill({
					status: 200,
					contentType: 'application/json',
					body: JSON.stringify({
						ok: true,
						data: {
							patterns: [pattern],
							allowedCycleWeeks: [1, 2, 3, 4, 5, 6, 7, 8],
							rotationPatternsEnabled: true,
						},
					}),
				})
				return
			}
			await route.fallback()
		})
		await page.route('**/apps/dutycheck/api/employees', async (route) => {
			await route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({
					ok: true,
					data: [{ id: 920101, displayName: 'Atlas Pat Emp', active: true }],
				}),
			})
		})
		await page.route('**/apps/dutycheck/api/employees/920101/rotation-assignments', async (route) => {
			await route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({
					ok: true,
					data: {
						assignments: [
							{ id: 11, patternId: 920001, validFrom: '2020-01-01', validTo: null },
							{ id: 12, patternId: 999999, validFrom: '2019-01-01', validTo: null },
						],
					},
				}),
			})
		})

		await page.goto('/apps/dutycheck/patterns', { waitUntil: 'domcontentloaded' })
		await assertNotServerUpdater(page)
		await expect(page.locator('.dc-pattern-assign-btn').first()).toBeVisible({ timeout: 30_000 })
		await page.locator('.dc-pattern-assign-btn').first().click()
		const assign = dcModal(page)
		await expect(assign).toBeVisible({ timeout: 8_000 })

		// No employee selected yet → no existing-assignments block, box unchecked.
		await expect(assign.locator('.dc-patterns__assign-list')).toHaveCount(0)
		await expect(assign.locator('#dc-pat-assign-super')).not.toBeChecked()

		await assign.locator('#dc-pat-assign-emp').selectOption('920101')
		const list = assign.locator('.dc-patterns__assign-list')
		await expect(list).toBeVisible({ timeout: 8_000 })
		await expect(assign).toContainText(/Existing assignments|Zuweisungen/i)
		await expect(list).toContainText('Atlas Pattern')
		// Row on unknown/inactive pattern renders with the inactive marker.
		await expect(list).toContainText(/inactive|inaktiv/i)

		// Open-ended active overlap from 2020 covers the prefilled today → auto-check.
		await expect(assign.locator('#dc-pat-assign-super')).toBeChecked()
		await expect(assign.locator('#dc-pat-assign-super-hint')).toBeVisible()

		await modalCancel(assign).click()
		await expect(dcModal(page)).toHaveCount(0)
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
		await page.locator('.dc-employee-toggle-btn[data-dc-active="1"]').first().click()
		const empModal = dcModal(page)
		await expect(empModal).toBeVisible({ timeout: 8_000 })
		await expect(empModal).toContainText(/Deactivate employee|Beschäftigte|deaktivieren/i)
		await expectDangerPrimary(empModal)
		await craftShot(page, 'employee-deactivate-open')
		await modalCancel(empModal).click()
		await expect(dcModal(page)).toHaveCount(0)

		await page.goto('/apps/dutycheck/locations', { waitUntil: 'domcontentloaded' })
		await assertNotServerUpdater(page)
		await page.waitForSelector('#dc-locations-table-body', { timeout: 30_000 })
		await expect(page.getByText('Atlas Loc Active')).toBeVisible({ timeout: 15_000 })
		await page.locator('.dc-location-toggle-btn[data-dc-active="1"]').first().click()
		const locModal = dcModal(page)
		await expect(locModal).toBeVisible({ timeout: 8_000 })
		await expect(locModal).toContainText(/Deactivate location|Standort deaktivieren/i)
		await expectDangerPrimary(locModal)
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
		await expectDangerPrimary(modal)
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
		await page.locator('.dc-period-transition-btn[data-dc-transition="closed"]').first().click()
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

	test('cancel shift confirm open → cancel keeps assignment, no cancel POST fired', async ({ page }) => {
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
		// Bootstrapping calls ensure-calendar-month for the REAL current month and
		// sets gridMonthClamp from it; without this stub the fixture month is clamped
		// away and the grid paints its empty state instead of the stubbed rows.
		await page.route('**/apps/dutycheck/api/periods/ensure-calendar-month**', async (route) => {
			await route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({
					ok: true,
					data: { period, created: false, writable: true, yearMonth: '2026-09' },
				}),
			})
		})
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
		const cancelBtn = page.locator('#dc-roster-list-panel .dc-assignment-cancel-btn').first()
		await expect(cancelBtn).toBeVisible({ timeout: 15_000 })
		await cancelBtn.click({ force: true })
		// confirmDialog renders a non-native .dc-modal (components.js openModal) —
		// page.once('dialog') can never see it. Drive the dc-modal directly.
		const modal = dcModal(page)
		await expect(modal).toBeVisible({ timeout: 8_000 })
		await expectDangerPrimary(modal)
		await expect(modalCancel(modal)).toBeVisible()
		await craftShot(page, 'cancel-shift-open')
		await modalCancel(modal).click()
		await expect(dcModal(page)).toHaveCount(0)
		expect(cancelPosts).toBe(0)
		// Row survives an aborted cancel.
		await expect(page.locator('#dc-roster-list-panel .dc-assignment-cancel-btn')).toHaveCount(1)
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
		await page.locator('.dc-absence-transition-btn[data-dc-transition="rejected"]').first().click()
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
		const ackBtn = page.locator('#dc-conflict-list .dc-conflict-ack-btn').first()
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
		const approveBtn = () => page.locator('#dc-open-claim-list .dc-open-claim-approve-btn').first()
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
		await modalPrimary(modal).click()
		await expect(modal.locator('.dc-field__error')).toContainText(/at least 10|mindestens 10/i)
		expect(approvePosts).toBe(postsAfterOpen)
		await modalCancel(modal).click()
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
		await modalPrimary(modal).click()
		await expect.poll(() => approvePosts).toBeGreaterThan(postsBeforeConfirm)
		expect(approveBodies.some((b) => /acknowledgements\[|conflictType/.test(b))).toBe(true)
		await page.unrouteAll({ behavior: 'ignoreErrors' })
	})
})

/**
 * Atlas POLICY ≥3.5.17 — executed UI legs for the two confirmDialog surfaces
 * shipped in 607d7d4 (period-delete + period-overlap "Create anyway").
 * Closes dc-crit3517-period-dialog-matrix-gap (dialog_matrix_proof_gap):
 * each dialog gets open → cancel (no mutate, state unchanged) AND
 * open → confirm (mutation fires, durable postcondition on refetch), plus the
 * PERIOD_NOT_DELETABLE → friendly-copy mapping row.
 */
test.describe('atlas period delete + overlap dialogs 3.5.17', () => {
	test.beforeEach(async ({ page }) => {
		await ensureDesktopShell(page)
	})

	const OPEN_PERIOD = {
		id: 930001,
		status: 'open',
		startDate: '2099-04-01',
		endDate: '2099-04-30',
		name: 'Atlas delete lab',
		publishedAt: null,
	}
	const OVERLAP_PERIOD = {
		id: 930002,
		status: 'open',
		startDate: '2099-04-10',
		endDate: '2099-04-20',
		name: 'Atlas overlap lab',
		publishedAt: null,
	}

	/**
	 * Periods-page API stub. state.periods backs the GET list and is mutated by
	 * the onDelete/onPost hooks so every leg re-reads a durable postcondition
	 * (row gone / row added) instead of trusting a 2xx alone.
	 * @param {import('@playwright/test').Page} page
	 * @param {{ periods: object[], onDelete?: Function, onPost?: Function }} state
	 */
	async function stubPeriodsApi(page, state) {
		await page.route('**/apps/dutycheck/api/periods**', async (route) => {
			const req = route.request()
			const url = req.url()
			const method = req.method()
			/** @param {number} status @param {object} body */
			const json = (status, body) => route.fulfill({
				status,
				contentType: 'application/json',
				body: JSON.stringify(body),
			})
			if (method === 'GET' && /\/api\/periods(?:\?|$)/.test(url)) {
				return json(200, { ok: true, data: { periods: state.periods } })
			}
			if (method === 'GET' && /\/api\/periods\/\d+\/publish-readiness/.test(url)) {
				return json(200, {
					ok: true,
					data: {
						canPublish: true,
						hardConflicts: 0,
						softConflicts: 0,
						unacknowledgedSoftConflicts: 0,
						integrationPublishStale: false,
						integrationStale: false,
					},
				})
			}
			if (method === 'GET' && /\/api\/periods\/\d+\/(snapshots|audit|acknowledge-stats)/.test(url)) {
				return json(200, {
					ok: true,
					data: /acknowledge-stats/.test(url)
						? { total: 0, acknowledged: 0, percent: 0 }
						: [],
				})
			}
			if (method === 'DELETE' && /\/api\/periods\/\d+/.test(url)) {
				if (typeof state.onDelete === 'function') {
					return state.onDelete(route, json)
				}
				return json(200, { ok: true, data: {} })
			}
			if (method === 'POST' && /\/api\/periods(?:\?|$)/.test(url)) {
				if (typeof state.onPost === 'function') {
					return state.onPost(route, json)
				}
				return json(200, { ok: true, data: {} })
			}
			return route.fallback()
		})
	}

	/** @param {import('@playwright/test').Page} page */
	async function openPeriodsPage(page) {
		await page.goto('/apps/dutycheck/periods', { waitUntil: 'domcontentloaded' })
		await assertNotServerUpdater(page)
		await page.waitForSelector('#dc-periods-table-body .dc-period-select', { timeout: 30_000 })
		await ensureDesktopShell(page)
	}

	test('period delete dialog open → cancel keeps row, no DELETE fired', async ({ page }) => {
		test.skip(plannerCredsCandidates().length === 0, 'Requires planner credentials')
		const state = { periods: [{ ...OPEN_PERIOD }] }
		let deletes = 0
		page.on('request', (req) => {
			if (req.method() === 'DELETE' && /\/apps\/dutycheck\/api\/periods\/\d+/.test(req.url())) {
				deletes += 1
			}
		})
		await stubPeriodsApi(page, state)
		await openPeriodsPage(page)

		await expect(page.locator('#dc-periods-table-body .dc-period-delete-btn')).toHaveCount(1)
		await page.locator('#dc-periods-table-body .dc-period-delete-btn').first().click()
		const modal = dcModal(page)
		await expect(modal).toBeVisible({ timeout: 8_000 })
		// Structural proof the destructive copy rendered: danger primary + cancel.
		await expectDangerPrimary(modal)
		await expect(modalCancel(modal)).toBeVisible()
		await craftShot(page, 'period-delete-open')
		await modalCancel(modal).click()
		await expect(dcModal(page)).toHaveCount(0)

		expect(deletes).toBe(0)
		// Row survives — the period list was never mutated.
		await expect(page.locator('#dc-periods-table-body .dc-period-delete-btn')).toHaveCount(1)
		await expect(page.locator('#dc-periods-table-body .dc-period-select')).toHaveCount(1)
		await page.unrouteAll({ behavior: 'ignoreErrors' })
	})

	test('period delete dialog confirm → DELETE fired, row gone after refetch', async ({ page }) => {
		test.skip(plannerCredsCandidates().length === 0, 'Requires planner credentials')
		const state = {
			periods: [{ ...OPEN_PERIOD }],
			onDelete: (route, json) => {
				state.periods = []
				return json(200, { ok: true, data: {} })
			},
		}
		const deleteUrls = []
		page.on('request', (req) => {
			if (req.method() === 'DELETE' && /\/apps\/dutycheck\/api\/periods\/\d+/.test(req.url())) {
				deleteUrls.push(req.url())
			}
		})
		await stubPeriodsApi(page, state)
		await openPeriodsPage(page)

		await page.locator('#dc-periods-table-body .dc-period-delete-btn').first().click()
		const modal = dcModal(page)
		await expect(modal).toBeVisible({ timeout: 8_000 })
		await expectDangerPrimary(modal)
		await modalPrimary(modal).click()

		await expect.poll(() => deleteUrls.length, { timeout: 10_000 }).toBe(1)
		expect(deleteUrls[0]).toMatch(new RegExp(`/apps/dutycheck/api/periods/${OPEN_PERIOD.id}`))
		// Durable postcondition: the refetched list no longer renders the row.
		await expect(page.locator('#dc-periods-table-body .dc-period-delete-btn')).toHaveCount(0)
		await expect(page.locator('#dc-periods-table-body .dc-period-select')).toHaveCount(0)
		await expect(page.locator('#dc-period-empty-callout')).toBeVisible()
		await page.unrouteAll({ behavior: 'ignoreErrors' })
	})

	test('period delete 409 PERIOD_NOT_DELETABLE → friendly mapped copy, row alive', async ({ page }) => {
		test.skip(plannerCredsCandidates().length === 0, 'Requires planner credentials')
		const state = {
			periods: [{ ...OPEN_PERIOD }],
			onDelete: (route, json) => json(409, {
				ok: false,
				error: {
					code: 'PERIOD_NOT_DELETABLE',
					message: 'raw server detail must not surface',
				},
			}),
		}
		let deletes = 0
		page.on('request', (req) => {
			if (req.method() === 'DELETE' && /\/apps\/dutycheck\/api\/periods\/\d+/.test(req.url())) {
				deletes += 1
			}
		})
		await stubPeriodsApi(page, state)
		await openPeriodsPage(page)

		await page.locator('#dc-periods-table-body .dc-period-delete-btn').first().click()
		const modal = dcModal(page)
		await expect(modal).toBeVisible({ timeout: 8_000 })
		await expectDangerPrimary(modal)
		await modalPrimary(modal).click()

		await expect.poll(() => deletes, { timeout: 10_000 }).toBe(1)
		// role=alert error toast carries the code-mapped copy (EN or DE fixture
		// locale), never the raw server detail string.
		const toast = page.locator('.dc-toast--error[role="alert"]')
		await expect(toast).toBeVisible({ timeout: 10_000 })
		await expect(toast).toContainText(/Only empty, unpublished periods can be deleted|unveröffentlichte Zeiträume/i)
		await expect(toast).not.toContainText('raw server detail')
		await expect(page.locator('#dc-alert-region')).toContainText(/Only empty, unpublished periods|unveröffentlichte Zeiträume/i)
		// Row alive — a rejected delete must not clobber the list.
		await expect(page.locator('#dc-periods-table-body .dc-period-delete-btn')).toHaveCount(1)
		await expect(page.locator('#dc-periods-table-body .dc-period-select')).toHaveCount(1)
		await page.unrouteAll({ behavior: 'ignoreErrors' })
	})

	test('period create overlap → cancel fires single POST, no row added', async ({ page }) => {
		test.skip(plannerCredsCandidates().length === 0, 'Requires planner credentials')
		const state = {
			periods: [{ ...OPEN_PERIOD }],
			onPost: (route, json) => json(409, {
				ok: false,
				error: {
					code: 'PERIOD_OVERLAP',
					message: 'overlap',
					overlaps: [{ startDate: OPEN_PERIOD.startDate, endDate: OPEN_PERIOD.endDate }],
				},
			}),
		}
		const postBodies = []
		page.on('request', (req) => {
			if (req.method() === 'POST' && /\/apps\/dutycheck\/api\/periods(?:\?|$)/.test(req.url())) {
				postBodies.push(req.postData() || '')
			}
		})
		await stubPeriodsApi(page, state)
		await openPeriodsPage(page)

		await page.locator('#dc-period-start').fill(OVERLAP_PERIOD.startDate)
		await page.locator('#dc-period-end').fill(OVERLAP_PERIOD.endDate)
		await page.locator('#dc-period-form .dc-form-actions button[type="submit"]').click()

		const modal = dcModal(page)
		await expect(modal).toBeVisible({ timeout: 8_000 })
		await expect(modal).toContainText(/Period overlaps|überschneidet sich/i)
		await craftShot(page, 'period-overlap-confirm-open')
		await modalCancel(modal).click()
		await expect(dcModal(page)).toHaveCount(0)

		// Informed-consent gate: cancelling must not resubmit (no allowOverlap
		// second POST) and must not produce a row.
		expect(postBodies).toHaveLength(1)
		expect(postBodies[0]).not.toMatch(/allowOverlap/)
		await expect(page.locator('#dc-periods-table-body .dc-period-select')).toHaveCount(1)
		await page.unrouteAll({ behavior: 'ignoreErrors' })
	})

	test('period create overlap → Create anyway resubmits with allowOverlap, row added', async ({ page }) => {
		test.skip(plannerCredsCandidates().length === 0, 'Requires planner credentials')
		const state = {
			periods: [{ ...OPEN_PERIOD }],
			onPost: (route, json) => {
				const body = route.request().postData() || ''
				if (/allowOverlap=(1|true)/.test(body)) {
					state.periods = [...state.periods, { ...OVERLAP_PERIOD }]
					return json(200, { ok: true, data: { period: OVERLAP_PERIOD } })
				}
				return json(409, {
					ok: false,
					error: {
						code: 'PERIOD_OVERLAP',
						message: 'overlap',
						overlaps: [{ startDate: OPEN_PERIOD.startDate, endDate: OPEN_PERIOD.endDate }],
					},
				})
			},
		}
		const postBodies = []
		page.on('request', (req) => {
			if (req.method() === 'POST' && /\/apps\/dutycheck\/api\/periods(?:\?|$)/.test(req.url())) {
				postBodies.push(req.postData() || '')
			}
		})
		await stubPeriodsApi(page, state)
		await openPeriodsPage(page)

		await page.locator('#dc-period-start').fill(OVERLAP_PERIOD.startDate)
		await page.locator('#dc-period-end').fill(OVERLAP_PERIOD.endDate)
		await page.locator('#dc-period-form .dc-form-actions button[type="submit"]').click()

		const modal = dcModal(page)
		await expect(modal).toBeVisible({ timeout: 8_000 })
		await expect(modal).toContainText(/Period overlaps|überschneidet sich/i)
		await modalPrimary(modal).click()

		// The bypass flag is the whole point of this dialog: first POST must
		// lack it, the post-consent resubmit must carry allowOverlap=true.
		await expect.poll(() => postBodies.length, { timeout: 10_000 }).toBe(2)
		expect(postBodies[0]).not.toMatch(/allowOverlap/)
		expect(postBodies[1]).toMatch(/allowOverlap=(1|true)/)
		// Durable postcondition: refetched list renders the created row.
		await expect(page.locator('#dc-periods-table-body .dc-period-select')).toHaveCount(2)
		await page.unrouteAll({ behavior: 'ignoreErrors' })
	})
})

/**
 * Atlas POLICY ≥3.5.17 — class sweep closeout for
 * dc-crit3518-dialog-matrix-sweep-gap (dialog_matrix_proof_gap, sweep:
 * required). Executed open→cancel + open→confirm legs for every confirmDialog/
 * openModal surface that was missing ui-matrix rows: cancel-shift (list entry),
 * open-shift delete, pattern delete, standby-category delete (+ IN_USE error
 * mapping), suggest-fill apply, and conflict-thresholds apply-to-open. The
 * swap-withdraw legs live in the employee describe above; the modal-secondary
 * cancel-shift leg lives in patterns-settings-regression.spec.js.
 */
test.describe('atlas destructive dialog sweep 3.5.18', () => {
	test.beforeEach(async ({ page }) => {
		await ensureDesktopShell(page)
	})

	const SWEEP_PERIOD = {
		id: 960013,
		status: 'open',
		startDate: '2026-09-01',
		endDate: '2026-09-30',
		name: 'Atlas sweep',
	}
	const SWEEP_ASSIGNMENT = {
		id: 960503,
		employeeId: 960103,
		employeeName: 'Atlas Sweep Emp',
		locationId: 960203,
		locationName: 'Atlas Sweep Loc',
		dutyDate: '2026-09-16',
		startTime: '08:00:00',
		endTime: '16:00:00',
		breakMinutes: 30,
		status: 'planned',
		note: '',
	}

	/**
	 * Roster page stubs for the sweep legs. Reuses stubRosterPage and adds the
	 * ensure-calendar-month stub (gridMonthClamp anchor — without it the
	 * fixture month is clamped away) plus hooks for the destructive endpoints
	 * under test.
	 * @param {import('@playwright/test').Page} page
	 * @param {{ assignments?: object[], onCancelPost?: Function }} opts
	 */
	async function stubSweepRoster(page, opts = {}) {
		const assignments = 'assignments' in opts ? opts.assignments : [SWEEP_ASSIGNMENT]
		await page.route('**/apps/dutycheck/api/periods/ensure-calendar-month**', async (route) => {
			await route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({
					ok: true,
					data: { period: SWEEP_PERIOD, created: false, writable: true, yearMonth: '2026-09' },
				}),
			})
		})
		await stubRosterPage(page, {
			period: SWEEP_PERIOD,
			employees: [{ id: 960103, displayName: 'Atlas Sweep Emp', active: true }],
			locations: [{ id: 960203, name: 'Atlas Sweep Loc' }],
			assignments,
		})
		await page.route('**/apps/dutycheck/api/assignments/*/cancel**', async (route) => {
			if (typeof opts.onCancelPost === 'function') {
				return opts.onCancelPost(route)
			}
			await route.fulfill({
				status: 500,
				contentType: 'application/json',
				body: JSON.stringify({ ok: false, error: { message: 'sweep cancel stub' } }),
			})
		})
	}

	/** @param {import('@playwright/test').Page} page */
	async function openRosterListView(page) {
		await page.goto('/apps/dutycheck/roster', { waitUntil: 'domcontentloaded' })
		await assertNotServerUpdater(page)
		await ensureDesktopShell(page)
		await page.locator('#dc-roster-view-list').click()
		await expect(page.locator('#dc-roster-list-panel')).toBeVisible({ timeout: 10_000 })
		await expect(
			page.locator('#dc-roster-list-panel .dc-assignment-cancel-btn'),
		).toHaveCount(1, { timeout: 15_000 })
	}

	test('cancel shift confirm → cancel POST fires, assignment gone after re-render', async ({ page }) => {
		test.skip(plannerCredsCandidates().length === 0, 'Requires planner credentials')
		const cancelledRoster = {
			ok: true,
			data: {
				employees: [{ id: 960103, displayName: 'Atlas Sweep Emp', active: true }],
				periods: [SWEEP_PERIOD],
				selectedPeriodId: SWEEP_PERIOD.id,
				selectedPeriodStatus: 'open',
				calendarYearMonth: '2026-09',
				defaultBreakMinutes: 30,
				canCreateAssignments: true,
				locations: [{ id: 960203, name: 'Atlas Sweep Loc' }],
				assignments: [],
				conflicts: [],
				absenceBlocks: [],
			},
		}
		const cancelUrls = []
		page.on('request', (req) => {
			if (req.method() === 'POST' && /\/assignments\/\d+\/cancel/.test(req.url())) {
				cancelUrls.push(req.url())
			}
		})
		await stubSweepRoster(page, {
			onCancelPost: (route) => route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify(cancelledRoster),
			}),
		})
		await openRosterListView(page)

		await page.locator('#dc-roster-list-panel .dc-assignment-cancel-btn').first().click({ force: true })
		const modal = dcModal(page)
		await expect(modal).toBeVisible({ timeout: 8_000 })
		await expectDangerPrimary(modal)
		await modalPrimary(modal).click()

		await expect.poll(() => cancelUrls.length, { timeout: 10_000 }).toBe(1)
		expect(cancelUrls[0]).toMatch(new RegExp(`/apps/dutycheck/api/assignments/${SWEEP_ASSIGNMENT.id}/cancel`))
		// Durable postcondition: render(response.data) no longer carries the
		// assignment — the cancel affordance is gone from the re-rendered list.
		await expect(page.locator('#dc-roster-list-panel .dc-assignment-cancel-btn')).toHaveCount(0)
		await page.unrouteAll({ behavior: 'ignoreErrors' })
	})

	/**
	 * Open-shift list stub. GET backs #dc-open-shift-list and is mutated by the
	 * DELETE hook so the confirm leg re-reads an empty list (durable
	 * postcondition), not just a 2xx.
	 * @param {import('@playwright/test').Page} page
	 * @param {{ openShifts: object[], onDelete?: Function }} state
	 */
	async function stubOpenShifts(page, state) {
		await page.route('**/apps/dutycheck/api/open-shifts**', async (route) => {
			const req = route.request()
			const url = req.url()
			if (/\/open-shifts\/pending/.test(url)) {
				return route.fallback()
			}
			if (req.method() === 'GET') {
				return route.fulfill({
					status: 200,
					contentType: 'application/json',
					body: JSON.stringify({ ok: true, data: state.openShifts }),
				})
			}
			if (req.method() === 'DELETE' && /\/open-shifts\/\d+/.test(url)) {
				if (typeof state.onDelete === 'function') {
					return state.onDelete(route)
				}
				return route.fulfill({
					status: 200,
					contentType: 'application/json',
					body: JSON.stringify({ ok: true, data: {} }),
				})
			}
			return route.fallback()
		})
	}

	const SWEEP_OPEN_SHIFT = {
		id: 960701,
		dutyDate: '2026-09-17',
		locationId: 960203,
		locationName: 'Atlas Sweep Loc',
		startTime: '08:00:00',
		endTime: '16:00:00',
	}

	test('open shift delete confirm open → cancel keeps row, no DELETE fired', async ({ page }) => {
		test.skip(plannerCredsCandidates().length === 0, 'Requires planner credentials')
		const state = { openShifts: [{ ...SWEEP_OPEN_SHIFT }] }
		let deletes = 0
		page.on('request', (req) => {
			if (req.method() === 'DELETE' && /\/open-shifts\/\d+/.test(req.url())) {
				deletes += 1
			}
		})
		await stubSweepRoster(page, { assignments: [] })
		await stubOpenShifts(page, state)
		await page.goto('/apps/dutycheck/roster', { waitUntil: 'domcontentloaded' })
		await assertNotServerUpdater(page)
		await ensureDesktopShell(page)
		await expect(page.locator('#dc-open-shift-list .dc-conflicts__item')).toHaveCount(1, { timeout: 15_000 })

		await page.locator('#dc-open-shift-list .dc-conflicts__item .button.danger').first().click()
		const modal = dcModal(page)
		await expect(modal).toBeVisible({ timeout: 8_000 })
		await expectDangerPrimary(modal)
		await expect(modalCancel(modal)).toBeVisible()
		await craftShot(page, 'open-shift-delete-open')
		await modalCancel(modal).click()
		await expect(dcModal(page)).toHaveCount(0)

		expect(deletes).toBe(0)
		await expect(page.locator('#dc-open-shift-list .dc-conflicts__item')).toHaveCount(1)
		await page.unrouteAll({ behavior: 'ignoreErrors' })
	})

	test('open shift delete confirm → DELETE fired, list empties after refetch', async ({ page }) => {
		test.skip(plannerCredsCandidates().length === 0, 'Requires planner credentials')
		const state = {
			openShifts: [{ ...SWEEP_OPEN_SHIFT }],
			onDelete: (route) => {
				state.openShifts = []
				return route.fulfill({
					status: 200,
					contentType: 'application/json',
					body: JSON.stringify({ ok: true, data: {} }),
				})
			},
		}
		const deleteUrls = []
		page.on('request', (req) => {
			if (req.method() === 'DELETE' && /\/open-shifts\/\d+/.test(req.url())) {
				deleteUrls.push(req.url())
			}
		})
		await stubSweepRoster(page, { assignments: [] })
		await stubOpenShifts(page, state)
		await page.goto('/apps/dutycheck/roster', { waitUntil: 'domcontentloaded' })
		await assertNotServerUpdater(page)
		await ensureDesktopShell(page)
		await expect(page.locator('#dc-open-shift-list .dc-conflicts__item')).toHaveCount(1, { timeout: 15_000 })

		await page.locator('#dc-open-shift-list .dc-conflicts__item .button.danger').first().click()
		const modal = dcModal(page)
		await expect(modal).toBeVisible({ timeout: 8_000 })
		await expectDangerPrimary(modal)
		await modalPrimary(modal).click()

		await expect.poll(() => deleteUrls.length, { timeout: 10_000 }).toBe(1)
		expect(deleteUrls[0]).toMatch(new RegExp(`/apps/dutycheck/api/open-shifts/${SWEEP_OPEN_SHIFT.id}`))
		// Durable postcondition: loadOpenShiftList re-fetches and paints the
		// empty state.
		await expect(page.locator('#dc-open-shift-list .dc-conflicts__item')).toHaveCount(0)
		await expect(page.locator('#dc-open-shift-empty')).toBeVisible()
		await page.unrouteAll({ behavior: 'ignoreErrors' })
	})

	test('pattern delete confirm open → cancel keeps item, no deactivate POST', async ({ page }) => {
		test.skip(plannerCredsCandidates().length === 0, 'Requires planner credentials')
		const pattern = {
			id: 970003,
			name: 'Atlas Sweep Pattern',
			cycleWeeks: 2,
			anchorType: 'iso_week_parity',
			weekDays: [],
		}
		let deactivatePosts = 0
		page.on('request', (req) => {
			if (req.method() === 'POST' && /\/rotation-patterns\/\d+\/deactivate/.test(req.url())) {
				deactivatePosts += 1
			}
		})
		await page.route('**/apps/dutycheck/api/rotation-patterns**', async (route) => {
			if (route.request().method() === 'GET') {
				return route.fulfill({
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
			}
			return route.fallback()
		})
		await page.goto('/apps/dutycheck/patterns', { waitUntil: 'domcontentloaded' })
		await assertNotServerUpdater(page)
		await ensureDesktopShell(page)
		await expect(page.locator('#dc-patterns-list .dc-patterns__item')).toHaveCount(1, { timeout: 15_000 })

		await page.locator('#dc-patterns-list .dc-patterns__item-actions button.danger').first().click()
		const modal = dcModal(page)
		await expect(modal).toBeVisible({ timeout: 8_000 })
		await expectDangerPrimary(modal)
		await expect(modalCancel(modal)).toBeVisible()
		await craftShot(page, 'pattern-delete-open')
		await modalCancel(modal).click()
		await expect(dcModal(page)).toHaveCount(0)

		expect(deactivatePosts).toBe(0)
		// Item survives an aborted delete.
		await expect(page.locator('#dc-patterns-list .dc-patterns__item')).toHaveCount(1)
		await page.unrouteAll({ behavior: 'ignoreErrors' })
	})

	const SWEEP_CATEGORY = {
		id: 970001,
		label: 'Atlas Sweep Cat',
		color: '#336699',
		active: true,
		defaultResponseTimeMinutes: 60,
	}

	/**
	 * Dienst-team page stubs: self-service settings with standbyEnabled so
	 * syncStandbyVisibility loads the categories list; the GET is backed by
	 * state.cats which the DELETE hook mutates for durable postconditions.
	 * @param {import('@playwright/test').Page} page
	 * @param {{ cats: object[], onDelete?: Function }} state
	 */
	async function stubStandbyCategories(page, state) {
		await page.route('**/apps/dutycheck/api/self-service/settings**', async (route) => {
			if (route.request().method() === 'GET') {
				return route.fulfill({
					status: 200,
					contentType: 'application/json',
					body: JSON.stringify({ ok: true, data: { standbyEnabled: true } }),
				})
			}
			return route.fallback()
		})
		await page.route('**/apps/dutycheck/api/standby/categories**', async (route) => {
			const req = route.request()
			if (req.method() === 'GET') {
				return route.fulfill({
					status: 200,
					contentType: 'application/json',
					body: JSON.stringify({ ok: true, data: { categories: state.cats } }),
				})
			}
			if (req.method() === 'DELETE' && /\/standby\/categories\/\d+/.test(req.url())) {
				if (typeof state.onDelete === 'function') {
					return state.onDelete(route)
				}
				return route.fulfill({
					status: 200,
					contentType: 'application/json',
					body: JSON.stringify({ ok: true, data: {} }),
				})
			}
			return route.fallback()
		})
	}

	/** @param {import('@playwright/test').Page} page */
	async function openDienstTeamWithCategory(page) {
		await page.goto('/apps/dutycheck/settings/dienst-team', { waitUntil: 'domcontentloaded' })
		await assertNotServerUpdater(page)
		await ensureDesktopShell(page)
		await expect(
			page.locator('#dc-dt-standby-cats-list .dc-standby-cats__item'),
		).toHaveCount(1, { timeout: 15_000 })
	}

	test('standby category delete confirm open → cancel keeps row, no DELETE fired', async ({ page }) => {
		test.skip(plannerCredsCandidates().length === 0, 'Requires planner credentials')
		const state = { cats: [{ ...SWEEP_CATEGORY }] }
		let deletes = 0
		page.on('request', (req) => {
			if (req.method() === 'DELETE' && /\/standby\/categories\/\d+/.test(req.url())) {
				deletes += 1
			}
		})
		await stubStandbyCategories(page, state)
		await openDienstTeamWithCategory(page)

		await page.locator('#dc-dt-standby-cats-list .dc-standby-cats__action--danger').first().click()
		const modal = dcModal(page)
		await expect(modal).toBeVisible({ timeout: 8_000 })
		await expectDangerPrimary(modal)
		await expect(modalCancel(modal)).toBeVisible()
		await craftShot(page, 'standby-category-delete-open')
		await modalCancel(modal).click()
		await expect(dcModal(page)).toHaveCount(0)

		expect(deletes).toBe(0)
		await expect(page.locator('#dc-dt-standby-cats-list .dc-standby-cats__item')).toHaveCount(1)
		await page.unrouteAll({ behavior: 'ignoreErrors' })
	})

	test('standby category delete confirm → DELETE fired, item gone after refetch', async ({ page }) => {
		test.skip(plannerCredsCandidates().length === 0, 'Requires planner credentials')
		const state = {
			cats: [{ ...SWEEP_CATEGORY }],
			onDelete: (route) => {
				state.cats = []
				return route.fulfill({
					status: 200,
					contentType: 'application/json',
					body: JSON.stringify({ ok: true, data: {} }),
				})
			},
		}
		const deleteUrls = []
		page.on('request', (req) => {
			if (req.method() === 'DELETE' && /\/standby\/categories\/\d+/.test(req.url())) {
				deleteUrls.push(req.url())
			}
		})
		await stubStandbyCategories(page, state)
		await openDienstTeamWithCategory(page)

		await page.locator('#dc-dt-standby-cats-list .dc-standby-cats__action--danger').first().click()
		const modal = dcModal(page)
		await expect(modal).toBeVisible({ timeout: 8_000 })
		await expectDangerPrimary(modal)
		await modalPrimary(modal).click()

		await expect.poll(() => deleteUrls.length, { timeout: 10_000 }).toBe(1)
		expect(deleteUrls[0]).toMatch(new RegExp(`/apps/dutycheck/api/standby/categories/${SWEEP_CATEGORY.id}`))
		// Durable postcondition: loadStandbyCategories re-fetches and paints the
		// empty item.
		await expect(page.locator('#dc-dt-standby-cats-list .dc-standby-cats__item')).toHaveCount(0)
		await expect(page.locator('#dc-dt-standby-cats-list .dc-standby-cats__empty')).toHaveCount(1)
		await page.unrouteAll({ behavior: 'ignoreErrors' })
	})

	test('standby category delete 409 STANDBY_CATEGORY_IN_USE → mapped copy, row alive', async ({ page }) => {
		test.skip(plannerCredsCandidates().length === 0, 'Requires planner credentials')
		const state = {
			cats: [{ ...SWEEP_CATEGORY }],
			onDelete: (route) => route.fulfill({
				status: 409,
				contentType: 'application/json',
				body: JSON.stringify({
					ok: false,
					error: {
						code: 'STANDBY_CATEGORY_IN_USE',
						message: 'raw server detail must not surface',
					},
				}),
			}),
		}
		let deletes = 0
		page.on('request', (req) => {
			if (req.method() === 'DELETE' && /\/standby\/categories\/\d+/.test(req.url())) {
				deletes += 1
			}
		})
		await stubStandbyCategories(page, state)
		await openDienstTeamWithCategory(page)

		await page.locator('#dc-dt-standby-cats-list .dc-standby-cats__action--danger').first().click()
		const modal = dcModal(page)
		await expect(modal).toBeVisible({ timeout: 8_000 })
		await expectDangerPrimary(modal)
		await modalPrimary(modal).click()

		await expect.poll(() => deletes, { timeout: 10_000 }).toBe(1)
		// The status region carries the code-mapped copy (EN or DE fixture
		// locale), never the raw server detail string, and is painted as an
		// error (.dc-roster-flash--error).
		const status = page.locator('#dc-dt-standby-cats-status')
		await expect(status).toBeVisible({ timeout: 10_000 })
		await expect(status).toHaveClass(/dc-roster-flash--error/)
		await expect(status).toContainText(/still used by on-call duties|Rufbereitschaften verwendet/i)
		await expect(status).not.toContainText('raw server detail')
		// Row alive — a rejected delete must not clobber the list.
		await expect(page.locator('#dc-dt-standby-cats-list .dc-standby-cats__item')).toHaveCount(1)
		await page.unrouteAll({ behavior: 'ignoreErrors' })
	})

	test('suggest fill preview → apply fires suggest-confirm and refetches roster', async ({ page }) => {
		test.skip(plannerCredsCandidates().length === 0, 'Requires planner credentials')
		let rosterGets = 0
		page.on('request', (req) => {
			if (req.method() === 'GET' && /\/apps\/dutycheck\/api\/roster(\?|$)/.test(req.url())) {
				rosterGets += 1
			}
		})
		await stubSweepRoster(page, { assignments: [] })
		await page.route('**/apps/dutycheck/api/periods/*/suggest-preview**', async (route) => {
			await route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({
					ok: true,
					data: {
						created: 2,
						skippedExisting: 0,
						skippedAbsence: 1,
						skippedBlackout: 0,
						skippedNoPattern: 0,
						skippedNoLocation: 0,
					},
				}),
			})
		})
		await page.route('**/apps/dutycheck/api/periods/*/suggest-confirm**', async (route) => {
			await route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({ ok: true, data: { created: 2, skippedWrite: 0 } }),
			})
		})
		const confirmPosts = []
		page.on('request', (req) => {
			if (req.method() === 'POST' && /suggest-confirm/.test(req.url())) {
				confirmPosts.push(req.url())
			}
		})

		await page.goto('/apps/dutycheck/roster', { waitUntil: 'domcontentloaded' })
		await assertNotServerUpdater(page)
		await ensureDesktopShell(page)
		await page.waitForSelector('#dc-roster-suggest-fill', { timeout: 30_000 })
		await expect(async () => {
			await page.locator('#dc-roster-suggest-fill').click()
			await expect(dcModal(page)).toBeVisible({ timeout: 2_000 })
		}).toPass({ timeout: 25_000 })
		const modal = dcModal(page)
		await expect(modal).toContainText(/Suggest fill preview|Vorschlag|Would create|würde/i)
		const rosterGetsBeforeApply = rosterGets
		await modalPrimary(modal).click()

		// Apply must fire the commit leg exactly once, then the roster is
		// re-fetched (loadRoster) — the durable postcondition.
		await expect.poll(() => confirmPosts.length, { timeout: 10_000 }).toBe(1)
		expect(confirmPosts[0]).toMatch(new RegExp(`/apps/dutycheck/api/periods/${SWEEP_PERIOD.id}/suggest-confirm`))
		await expect.poll(() => rosterGets, { timeout: 10_000 }).toBeGreaterThan(rosterGetsBeforeApply)
		await expect(dcModal(page)).toHaveCount(0)
		await page.unrouteAll({ behavior: 'ignoreErrors' })
	})

	/**
	 * Conflict-policy stub: GET returns outdated open periods so the apply
	 * button renders; apply-open is hookable per leg.
	 * @param {import('@playwright/test').Page} page
	 * @param {{ onApply?: Function }} state
	 */
	async function stubConflictPolicy(page, state) {
		await page.route('**/apps/dutycheck/api/admin/conflict-policy**', async (route) => {
			const req = route.request()
			if (req.method() === 'POST' && /conflict-policy\/apply-open/.test(req.url())) {
				if (typeof state.onApply === 'function') {
					return state.onApply(route)
				}
				return route.fulfill({
					status: 200,
					contentType: 'application/json',
					body: JSON.stringify({ ok: true, data: { updated: 0 } }),
				})
			}
			if (req.method() === 'GET' && /conflict-policy(?:\?|$)/.test(req.url())) {
				return route.fulfill({
					status: 200,
					contentType: 'application/json',
					body: JSON.stringify({
						ok: true,
						data: {
							minRestMinutes: 660,
							maxDailyHard: 600,
							maxPeriodSoft: 2880,
							maxPeriodHard: 3600,
							maxConsecutiveDays: 6,
							openPeriods: { schemaReady: true, openCount: 3, outdatedCount: 2 },
						},
					}),
				})
			}
			return route.fallback()
		})
	}

	/** @param {import('@playwright/test').Page} page */
	async function openConflictsWithApply(page) {
		await page.goto('/apps/dutycheck/settings/conflicts', { waitUntil: 'domcontentloaded' })
		await assertNotServerUpdater(page)
		await ensureDesktopShell(page)
		await expect(page.locator('#dc-conflict-apply-open')).toBeVisible({ timeout: 15_000 })
	}

	test('conflict thresholds apply confirm open → cancel, no apply-open POST', async ({ page }) => {
		test.skip(plannerCredsCandidates().length === 0, 'Requires planner credentials')
		let applyPosts = 0
		page.on('request', (req) => {
			if (req.method() === 'POST' && /conflict-policy\/apply-open/.test(req.url())) {
				applyPosts += 1
			}
		})
		await stubConflictPolicy(page, {})
		await openConflictsWithApply(page)

		await page.locator('#dc-conflict-apply-open').click()
		const modal = dcModal(page)
		await expect(modal).toBeVisible({ timeout: 8_000 })
		await expect(modalPrimary(modal)).toBeVisible()
		await expect(modalCancel(modal)).toBeVisible()
		await craftShot(page, 'conflict-apply-open')
		await modalCancel(modal).click()
		await expect(dcModal(page)).toHaveCount(0)

		expect(applyPosts).toBe(0)
		// The apply affordance stays offered after an aborted apply.
		await expect(page.locator('#dc-conflict-apply-open')).toBeVisible()
		await page.unrouteAll({ behavior: 'ignoreErrors' })
	})

	test('conflict thresholds apply confirm → apply-open POST fires, callout settles', async ({ page }) => {
		test.skip(plannerCredsCandidates().length === 0, 'Requires planner credentials')
		const applyUrls = []
		page.on('request', (req) => {
			if (req.method() === 'POST' && /conflict-policy\/apply-open/.test(req.url())) {
				applyUrls.push(req.url())
			}
		})
		await stubConflictPolicy(page, {
			onApply: (route) => route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({
					ok: true,
					data: {
						updated: 2,
						conflictsRefreshed: 2,
						conflictsDirtyRemaining: 0,
						openCount: 3,
						outdatedCount: 0,
					},
				}),
			}),
		})
		await openConflictsWithApply(page)

		await page.locator('#dc-conflict-apply-open').click()
		const modal = dcModal(page)
		await expect(modal).toBeVisible({ timeout: 8_000 })
		await modalPrimary(modal).click()

		await expect.poll(() => applyUrls.length, { timeout: 10_000 }).toBe(1)
		expect(applyUrls[0]).toMatch(/\/apps\/dutycheck\/api\/admin\/conflict-policy\/apply-open/)
		// Durable postcondition: the response reports outdatedCount 0, so the
		// apply callout is withdrawn and the status line carries the outcome.
		await expect(page.locator('#dc-conflict-apply-actions')).toBeHidden({ timeout: 10_000 })
		await expect(page.locator('#dc-conflict-policy-status')).toContainText(/\S/)
		await page.unrouteAll({ behavior: 'ignoreErrors' })
	})
})
