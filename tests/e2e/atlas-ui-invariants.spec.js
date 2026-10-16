// @ts-check
/**
 * ATLAS_UI_INVARIANTS — DutyCheck representative surfaces.
 *
 * Wires apps/_shared/e2e/atlas-ui-invariants.js onto the surfaces a
 * planner/admin actually works on. Covered classes: a11y-dom (icon-only
 * names, <24px targets, focusable-in-aria-hidden, unannounced error
 * callouts), stored-xss (location name is a free-text field rendered in the
 * /locations table), console errors, raw i18n keys, mutation freshness,
 * double-submit, form-loss-on-5xx, O(1) list requests.
 *
 * Fixture hygiene: created locations carry the run stamp and are
 * deactivated in finally (locations are soft-deleted — no DELETE route).
 */
import { test, expect } from '@playwright/test'
import { createRequire } from 'node:module'
import { assertNotServerUpdater, loginWithFallback, plannerCredsCandidates } from './helpers/auth.js'

const require = createRequire(import.meta.url)
const {
	ATLAS_XSS_PAYLOADS,
	assertA11yDom,
	assertNoConsoleErrors,
	assertNoDuplicateSubmit,
	assertNoInjection,
	assertNoRawI18nKeys,
	assertFormSurvivesFailure,
	assertSurfaceFresh,
	countApiRequests,
	trackConsoleErrors,
} = require('../../../_shared/e2e/atlas-ui-invariants.js')

const CONTENT = '#dc-main-content'
// `0` prefix keeps fixture rows inside the first paint window of the
// name-ordered virtualized locations table ("Showing rows 1–15 of N"), so
// DOM-text assertions can actually see them instead of passing vacuously.
const STAMP = `0dcinv-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 6)}`
const TZ = 'Europe/Berlin'

/** session-bound call into the app API via the app's own JS client */
async function dcApi(page, path, body) {
	return page.evaluate(async ({ path, body }) => {
		try {
			const res = await window.DutyCheckApi.post(path, body)
			return { ok: true, data: res && res.data }
		} catch (e) {
			return { ok: false, err: String((e && e.message) || e) }
		}
	}, { path, body })
}

/** POST returns the full catalog — find the created row by name. */
async function createLocation(page, name) {
	const r = await dcApi(page, '/apps/dutycheck/api/locations', { name, timezone: TZ, active: true })
	if (!r.ok || !Array.isArray(r.data)) return null
	const row = r.data.find((x) => x && x.name === name)
	return row && row.id ? Number(row.id) : null
}

/** Soft-delete: there is no DELETE route — deactivate the fixture row. */
async function deactivateLocation(page, id) {
	await dcApi(page, `/apps/dutycheck/api/locations/${id}`, {
		name: `${STAMP}-archived`, timezone: TZ, active: false,
	})
}

async function gotoApp(page, path) {
	await page.goto(`/apps/dutycheck${path}`, { waitUntil: 'domcontentloaded' })
	await assertNotServerUpdater(page)
	await page.waitForSelector(CONTENT, { timeout: 30_000 })
}

/**
 * locations.js binds the submit handler only AFTER the initial load()
 * resolves — a click before the table paints performs a native GET submit
 * and reloads the page. Wait for the table body to render real content.
 */
async function gotoLocationsReady(page) {
	await gotoApp(page, '/locations')
	// the skeleton tr exists during load() — wait for a real/empty/error row
	await page.waitForSelector('#dc-locations-table-body tr:not(.dc-table__loading-row)', { timeout: 30_000 })
}

test.describe('DutyCheck UI invariants (atlas-ui-invariants)', () => {
	test.skip(() => plannerCredsCandidates().length === 0, 'Requires E2E_* or NC_ADMIN_* credentials')

	test.beforeEach(async ({ page }) => {
		await page.setViewportSize({ width: 1280, height: 900 })
		await loginWithFallback(page, plannerCredsCandidates())
	})

	// ── a11y-dom + console + raw-i18n sweep over representative surfaces ──
	for (const [label, url] of [
		['employees', '/employees'],
		['locations', '/locations'],
		['settings-dienst-team', '/settings/dienst-team'],
	]) {
		test(`a11y-dom ${label}`, async ({ page }) => {
			const errs = trackConsoleErrors(page)
			await gotoApp(page, url)
			// Radios/checkboxes under 24px are legal only with a >=24px label
			// target (WCAG 2.5.8 equivalent-target). Prove that before the sweep.
			const unlabeled = await page.evaluate(() => {
				const bad = []
				document
					.querySelectorAll('#dc-main-content input[type="radio"], #dc-main-content input[type="checkbox"]')
					.forEach((inp) => {
						const ir = inp.getBoundingClientRect()
						const ics = getComputedStyle(inp)
						if (ir.width === 0 || ics.display === 'none' || ics.visibility === 'hidden') return
						const lbl = inp.closest('label')
							|| (inp.id ? document.querySelector(`label[for="${CSS.escape(inp.id)}"]`) : null)
						if (!lbl) {
							bad.push(`no label: ${inp.id || inp.outerHTML.slice(0, 60)}`)
							return
						}
						const r = lbl.getBoundingClientRect()
						const cs = getComputedStyle(lbl)
						if (cs.display === 'none' || cs.visibility === 'hidden' || (r.width === 0 && r.height === 0)) return
						if (r.width < 24 || r.height < 24) {
							bad.push(`label ${Math.round(r.width)}x${Math.round(r.height)} < 24px: ${inp.id}`)
						}
					})
				return bad
			})
			expect(unlabeled, `radio/checkbox without a >=24px label target on ${label}:\n${unlabeled.join('\n')}`).toEqual([])
			const findings = await assertA11yDom(page, {
				content: CONTENT,
				// labelled small inputs are legal — the label is the target;
				// .dc-timezone-picker__native is a deliberate sr-only DOM sync
				// node (1px clip + aria-hidden + tabindex=-1), not a pointer target.
				sizeAllow: 'label, .dc-timezone-picker__native',
			})
			expect(findings, `a11y-dom findings on ${label}:\n${findings.join('\n')}`).toEqual([])
			assertNoConsoleErrors(errs, { allow: [/favicon/i] })
			await assertNoRawI18nKeys(page, { content: CONTENT })
		})
	}

	// ── a11y-dom on an open confirm dialog (error-capable state) ──
	test('a11y-dom on confirm dialog', async ({ page }) => {
		await gotoApp(page, '/locations')
		// confirmDialog resolves only on click — never await it inside evaluate.
		await page.evaluate(() => {
			void window.DutyCheckComponents.confirmDialog({
				title: 'Atlas probe dialog',
				body: 'Probe body',
				confirmLabel: 'OK',
				danger: true,
			})
		})
		const dialog = page.locator('.dc-dialog, .dc-modal').first()
		await dialog.waitFor({ state: 'visible', timeout: 10_000 })
		const findings = await assertA11yDom(page, {
			content: 'body',
			sizeAllow: 'label, .dc-timezone-picker__native',
		})
		expect(findings, `a11y-dom findings in confirm dialog:\n${findings.join('\n')}`).toEqual([])
		await dialog.press('Escape').catch(() => {})
	})

	// ── stored XSS: free-text location name rendered in the table ──
	test('stored-xss escaped on locations table', async ({ page }) => {
		const name = `${STAMP} ${ATLAS_XSS_PAYLOADS[0]}`
		await gotoApp(page, '/locations')
		const id = await createLocation(page, name)
		try {
			expect(id, 'location create via API').toBeTruthy()
			await gotoApp(page, '/locations')
			await assertNoInjection(page)
		} finally {
			if (id) await deactivateLocation(page, id)
		}
	})

	// ── mutation freshness: rename must surface without manual reload ──
	test('mutation freshness on locations table', async ({ page }) => {
		const name = `${STAMP}-fresh`
		const renamed = `${STAMP}-renamed`
		await gotoApp(page, '/locations')
		const id = await createLocation(page, name)
		try {
			expect(id, 'location create via API').toBeTruthy()
			await assertSurfaceFresh(page, {
				content: CONTENT,
				mutate: async () => {},
				visit: async () => { await page.reload({ waitUntil: 'domcontentloaded' }) },
				expect: { present: name },
			})
			const upd = await dcApi(page, `/apps/dutycheck/api/locations/${id}`, {
				name: renamed, timezone: TZ, active: true,
			})
			expect(upd.ok, `rename via API: ${upd.err || ''}`).toBe(true)
			await assertSurfaceFresh(page, {
				content: CONTENT,
				mutate: async () => {},
				visit: async () => { await page.reload({ waitUntil: 'domcontentloaded' }) },
				expect: { present: renamed, absent: name },
			})
		} finally {
			if (id) await deactivateLocation(page, id)
		}
	})

	// ── double-submit: rapid second submit must not reach the server ──
	test('double-submit on location create sends one POST', async ({ page }) => {
		await gotoLocationsReady(page)
		await page.locator('#dc-location-name').fill(`${STAMP}-dbl`)
		await page.locator('#dc-location-timezone').evaluate((el, tz) => { el.value = tz }, TZ)
		const submit = page.locator('#dc-location-form button[type="submit"]').first()
		try {
			await assertNoDuplicateSubmit(page, {
				mutatingUrl: /\/api\/locations$/,
				method: 'POST',
				// force: the correct behavior guards mid-flight — a real user
				// can still machine-gun Enter/clicks regardless of disabled.
				submit: () => submit.click({ force: true }).catch(() => {}),
			})
		} finally {
			// clean up whatever the (single) write created
			const list = await page.evaluate(async () => {
				try {
					const res = await window.DutyCheckApi.get('/apps/dutycheck/api/locations')
					return (res && res.data) || []
				} catch { return [] }
			})
			for (const row of list) {
				if (String(row.name || '').startsWith(STAMP)) {
					await deactivateLocation(page, row.id)
				}
			}
		}
	})

	// ── form survival on 5xx: inputs retained + error rendered ──
	test('location form survives injected 500', async ({ page }) => {
		await gotoLocationsReady(page)
		const name = `${STAMP}-keep`
		await page.locator('#dc-location-name').fill(name)
		await page.locator('#dc-location-timezone').evaluate((el, tz) => { el.value = tz }, TZ)
		await assertFormSurvivesFailure(page, {
			failUrl: /\/api\/locations$/,
			method: 'POST',
			fields: { '#dc-location-name': name },
			submit: () => page.locator('#dc-location-form button[type="submit"]').first().click(),
			errorSel: '#dc-toasts .dc-toast--error',
		})
	})

	// ── O(1) list render: /locations must not fan out per-row requests ──
	test('locations list render is O(1) in API calls', async ({ page }) => {
		const hits = await countApiRequests(page, async () => {
			await gotoApp(page, '/locations')
			await page.waitForLoadState('networkidle', { timeout: 15_000 }).catch(() => {})
		}, /\/api\//)
		const listCalls = hits.filter((h) => /\/api\/locations(\?|$)/.test(h.url) && h.method === 'GET')
		expect(listCalls.length, `location list fetches: ${listCalls.map((h) => h.url).join(' | ')}`).toBeLessThanOrEqual(2)
		const perRow = hits.filter((h) => /\/api\/locations\/\d+/.test(h.url))
		expect(perRow, `per-row N+1 fetches: ${perRow.map((h) => h.url).join(' | ')}`).toEqual([])
	})
})
