// @ts-check
/**
 * ds_chrome lane probe — Atlas Farm 3.5.11 (fresh artifacts).
 *
 * Usage: node tests/e2e/_ds_chrome_audit.mjs <phase>
 *   sweep    routes × themes × viewports: overflow, touch targets, axe, PNG+sha256
 *   dialogs  role/aria-modal/focus-trap/Escape/focus-restore (incl. re-render hunt)
 *   states   anon→login, needs-role, denied, fetch-error, empty states
 *
 * Evidence root: FARM_OUT (default .cursor/atlas-farm-v3/artifacts/dutycheck/probes/ds_chrome)
 * Probe users: dc-ds-planner / dc-ds-employee / dc-ds-norole (DS_PROBE_PASS env).
 */
import { chromium } from 'playwright'
import AxeBuilder from '@axe-core/playwright'
import { createHash } from 'node:crypto'
import { mkdirSync, writeFileSync } from 'node:fs'
import { join } from 'node:path'
import { login } from './helpers/auth.js'

const BASE = process.env.NC_BASE_URL || 'http://localhost:8081'
const PASS = process.env.DS_PROBE_PASS || 'DsProbe!2026'
const OUT = process.env.FARM_OUT
	|| '/home/alex/Development/nextcloud-dev/.cursor/atlas-farm-v3/artifacts/dutycheck/probes/ds_chrome'
const PHASE = process.argv[2] || 'sweep'
mkdirSync(OUT, { recursive: true })

const USERS = {
	planner: { username: 'dc-ds-planner', password: PASS },
	employee: { username: 'dc-ds-employee', password: PASS },
	norole: { username: 'dc-ds-norole', password: PASS },
	admin: { username: 'dc-ds-admin', password: PASS },
}

const THEMES = ['light', 'dark', 'light-highcontrast', 'dark-highcontrast']
const VIEWPORTS = [
	{ w: 320, h: 640 },
	{ w: 768, h: 1024 },
	{ w: 1024, h: 768 },
	{ w: 1440, h: 900 },
]

const PLANNER_ROUTES = [
	{ id: 'index', path: '/apps/dutycheck/' },
	{ id: 'dashboard', path: '/apps/dutycheck/dashboard' },
	{ id: 'today', path: '/apps/dutycheck/today' },
	{ id: 'patterns', path: '/apps/dutycheck/patterns' },
	{ id: 'roster', path: '/apps/dutycheck/roster' },
	// app-admin only (requireAppAdmin) — planner must get a 403 denied surface
	{ id: 'roster-print', path: '/apps/dutycheck/roster/print', expectDenied: true },
	{ id: 'periods', path: '/apps/dutycheck/periods' },
	{ id: 'employees', path: '/apps/dutycheck/employees' },
	{ id: 'locations', path: '/apps/dutycheck/locations' },
	{ id: 'absences', path: '/apps/dutycheck/absences' },
	// self-service needs a linked employee row — unlinked planner must get 403
	{ id: 'my-roster', path: '/apps/dutycheck/my-roster', expectDenied: true },
	{ id: 'my-absences', path: '/apps/dutycheck/my-absences', expectDenied: true },
	{ id: 'settings', path: '/apps/dutycheck/settings' },
	{ id: 'needs-role', path: '/apps/dutycheck/needs-role' },
]
const SETTINGS_SECTIONS = [
	'access', 'duty-roles', 'planning', 'companies', 'conflicts', 'shift-templates',
	'qualifications', 'planner-scope', 'operations', 'dienst-team', 'integration',
	'privacy', 'license', 'support',
]
const EMPLOYEE_ROUTES = [
	{ id: 'emp-index', path: '/apps/dutycheck/' },
	{ id: 'emp-my-roster', path: '/apps/dutycheck/my-roster' },
	{ id: 'emp-my-absences', path: '/apps/dutycheck/my-absences' },
	{ id: 'emp-today', path: '/apps/dutycheck/today' },
]

const results = { phase: PHASE, startedAt: new Date().toISOString(), cells: [], defects: [], captures: {} }

function sha256(buf) {
	return createHash('sha256').update(buf).digest('hex')
}

async function snap(page, name) {
	const buf = await page.screenshot({ fullPage: false })
	const file = `${name}.png`
	writeFileSync(join(OUT, file), buf)
	const hash = sha256(buf)
	results.captures[name] = { file, sha256: hash, bytes: buf.length }
	return { file, sha256: hash, bytes: buf.length }
}

async function settle(page, ms = 12000) {
	await page.waitForLoadState('domcontentloaded').catch(() => {})
	try { await page.waitForLoadState('networkidle', { timeout: 5000 }) } catch { /* long-polls */ }
	await page.evaluate(() => new Promise((r) => requestAnimationFrame(() => requestAnimationFrame(r))))
}

function record(cell) {
	results.cells.push(cell)
	const tag = cell.status === 'fail' ? 'FAIL' : cell.status === 'warn' ? 'warn' : 'ok'
	console.log(`[${tag}] ${cell.id} :: ${JSON.stringify(cell.checks).slice(0, 300)}`)
}

async function loginState(browser, role) {
	const ctx = await browser.newContext({ baseURL: BASE, viewport: { width: 1440, height: 900 } })
	const page = await ctx.newPage()
	await login(page, USERS[role])
	const state = await ctx.storageState()
	await ctx.close()
	return state
}

async function setUserTheme(page, themeId) {
	const failures = await page.evaluate(async ({ target, all }) => {
		const token = (window.OC && window.OC.requestToken)
			|| document.querySelector('head[data-requesttoken]')?.getAttribute('data-requesttoken') || ''
		const headers = { requesttoken: token, 'OCS-APIRequest': 'true', Accept: 'application/json' }
		const problems = []
		for (const id of all.filter((t) => t !== target)) {
			const res = await fetch(`/ocs/v2.php/apps/theming/api/v1/theme/${id}`, { method: 'DELETE', credentials: 'same-origin', headers })
			if (!res.ok && res.status !== 400) problems.push(`disable ${id}: HTTP ${res.status}`)
		}
		const res = await fetch(`/ocs/v2.php/apps/theming/api/v1/theme/${target}/enable`, { method: 'PUT', credentials: 'same-origin', headers })
		if (!res.ok && res.status !== 400) problems.push(`enable ${target}: HTTP ${res.status}`)
		return problems
	}, { target: themeId, all: THEMES })
	if (failures.length) throw new Error(`theme ${themeId}: ${failures.join(';')}`)
}

async function checkOverflow(page) {
	return page.evaluate(() => {
		const doc = document.documentElement
		const app = document.querySelector('#app-content')
		const main = document.getElementById('dc-main-content')
		const probe = (el) => (el ? el.scrollWidth - el.clientWidth : 0)
		return {
			doc: probe(doc),
			app: probe(app),
			main: probe(main),
		}
	})
}

async function checkTouchTargets(page) {
	return page.evaluate(() => {
		const scopes = ['#app-content', '#app-navigation', '.dc-modal', 'dialog[open]']
		const seen = new Set()
		const offenders = []
		const interactiveSel = [
			'button', 'a[href]', 'input:not([type="hidden"])', 'select', 'textarea',
			'[role="button"]', '[role="link"]', '[role="checkbox"]', '[role="tab"]',
			'[role="menuitem"]', '[role="switch"]', 'summary', '[tabindex]:not([tabindex="-1"])',
		].join(',')
		for (const scopeSel of scopes) {
			for (const scope of document.querySelectorAll(scopeSel)) {
				for (const el of scope.querySelectorAll(interactiveSel)) {
					if (seen.has(el)) continue
					seen.add(el)
					const r = el.getBoundingClientRect()
					const style = getComputedStyle(el)
					if (r.width <= 0 || r.height <= 0) continue
					if (style.visibility === 'hidden' || style.display === 'none') continue
					// checkbox/radio native glyphs: NC draws the box small by design token;
					// their <label> provides the hit area — measure label instead.
					if (el.matches('input[type="checkbox"],input[type="radio"]')) {
						const lab = el.closest('label') || (el.id && document.querySelector(`label[for="${el.id}"]`))
						if (lab) {
							const lr = lab.getBoundingClientRect()
							if (lr.height >= 40) continue
						}
					}
					if (r.width < 44 || r.height < 44) {
						const label = (el.textContent || el.getAttribute('aria-label') || el.id || el.tagName)
							.trim().replace(/\s+/g, ' ').slice(0, 60)
						offenders.push({
							tag: el.tagName.toLowerCase(), cls: String(el.className).slice(0, 60),
							label, w: Math.round(r.width), h: Math.round(r.height),
						})
					}
				}
			}
		}
		return offenders.slice(0, 15)
	})
}

async function runAxe(page) {
	try {
		const res = await new AxeBuilder({ page })
			.withTags(['wcag2a', 'wcag2aa'])
			.exclude('#header').exclude('#contactsmenu').exclude('.notifications')
			.analyze()
		return res.violations.map((v) => ({
			id: v.id, impact: v.impact,
			nodes: v.nodes.slice(0, 4).map((n) => String(n.target).slice(0, 120)),
			summary: String(v.help).slice(0, 140),
		}))
	} catch (err) {
		return [{ id: 'axe-error', impact: 'critical', nodes: [], summary: String(err).slice(0, 200) }]
	}
}

/* ───────────────────────────── sweep ───────────────────────────── */
async function phaseSweep(browser) {
	const plannerState = await loginState(browser, 'planner')
	const employeeState = await loginState(browser, 'employee')

	const ctx = await browser.newContext({ baseURL: BASE, storageState: plannerState, viewport: { width: 1440, height: 900 } })
	const page = await ctx.newPage()

	// Theme matrix at 1440 for all planner routes (axe + overflow + capture).
	for (const theme of THEMES) {
		// set theme once, reload-less per route
		await page.goto(`${BASE}/apps/dutycheck/`, { waitUntil: 'domcontentloaded' })
		await setUserTheme(page, theme)
		for (const route of [...PLANNER_ROUTES, ...SETTINGS_SECTIONS.map((s) => ({ id: `settings-${s}`, path: `/apps/dutycheck/settings/${s}` }))]) {
			const cell = { id: `${route.id}@${theme}@1440`, role: 'planner', theme, viewport: 1440, checks: {} }
			const resp = await page.goto(`${BASE}${route.path}`, { waitUntil: 'domcontentloaded' }).catch((e) => null)
			await settle(page)
			cell.checks.http = resp ? resp.status() : 'nav-fail'
			cell.checks.finalUrl = page.url().replace(BASE, '')
			cell.checks.hasMain = await page.locator('#dc-main-content, .dc-denied, #dc-swap-dialog, #dc-print-root, main').first().isVisible().catch(() => false)
			const ov = await checkOverflow(page)
			cell.checks.overflow = ov
			cell.checks.overflowOk = Math.max(ov.doc, ov.app, ov.main) <= 1
			cell.checks.themeApplied = await page.evaluate((t) => document.body.hasAttribute(`data-theme-${t}`), theme).catch(() => null)
			cell.checks.axe = await runAxe(page)
			cell.checks.axeViolations = cell.checks.axe.length
			const shot = await snap(page, `sweep__${route.id}__${theme}__1440`)
			cell.proof = shot.sha256.slice(0, 16)
			const failReasons = []
			if (route.expectDenied) {
				// expected authz denial: 403 + a rendered denied surface is a PASS
				cell.checks.deniedShown = await page.locator('.dc-denied, [role="alert"], #dc-denied-main').first().isVisible().catch(() => false)
				if (cell.checks.http !== 403 && cell.checks.http !== 401) failReasons.push(`expected 403, got http ${cell.checks.http}`)
				if (!cell.checks.deniedShown) failReasons.push('expected denied surface not rendered')
			} else if (cell.checks.http >= 400) {
				failReasons.push(`http ${cell.checks.http}`)
			}
			if (!cell.checks.overflowOk) failReasons.push(`overflow ${JSON.stringify(ov)}`)
			if (cell.checks.axeViolations > 0) failReasons.push(`axe ${cell.checks.axeViolations}`)
			cell.status = failReasons.length ? 'fail' : 'ok'
			if (failReasons.length) cell.failReasons = failReasons
			record(cell)
		}
	}

	// Viewport matrix on light for key routes (overflow + touch + capture).
	const KEY_ROUTES = PLANNER_ROUTES.filter((r) => !['settings', 'needs-role', 'roster-print'].includes(r.id))
		.concat([{ id: 'settings-access', path: '/apps/dutycheck/settings/access' }])
	for (const vp of VIEWPORTS) {
		await page.setViewportSize({ width: vp.w, height: vp.h })
		await page.goto(`${BASE}/apps/dutycheck/`, { waitUntil: 'domcontentloaded' })
		await setUserTheme(page, 'light').catch(() => {})
		await page.reload({ waitUntil: 'domcontentloaded' })
		for (const route of KEY_ROUTES) {
			const cell = { id: `${route.id}@light@${vp.w}`, role: 'planner', theme: 'light', viewport: vp.w, checks: {} }
			const resp = await page.goto(`${BASE}${route.path}`, { waitUntil: 'domcontentloaded' }).catch(() => null)
			await settle(page)
			cell.checks.http = resp ? resp.status() : 'nav-fail'
			const ov = await checkOverflow(page)
			cell.checks.overflow = ov
			cell.checks.overflowOk = Math.max(ov.doc, ov.app, ov.main) <= 1
			cell.checks.touchOffenders = await checkTouchTargets(page)
			const shot = await snap(page, `sweep__${route.id}__light__${vp.w}`)
			cell.proof = shot.sha256.slice(0, 16)
			const failReasons = []
			if (!cell.checks.overflowOk) failReasons.push(`overflow ${JSON.stringify(ov)}`)
			if (cell.checks.touchOffenders.length) failReasons.push(`touch<44: ${cell.checks.touchOffenders.length}`)
			cell.status = failReasons.length ? 'fail' : 'ok'
			if (failReasons.length) cell.failReasons = failReasons
			record(cell)
		}
	}

	// Employee routes — light × viewports.
	await ctx.close()
	const ectx = await browser.newContext({ baseURL: BASE, storageState: employeeState, viewport: { width: 1440, height: 900 } })
	const ep = await ectx.newPage()
	for (const vp of [{ w: 320, h: 640 }, { w: 1440, h: 900 }]) {
		await ep.setViewportSize({ width: vp.w, height: vp.h })
		for (const route of EMPLOYEE_ROUTES) {
			const cell = { id: `${route.id}@light@${vp.w}`, role: 'employee', theme: 'light', viewport: vp.w, checks: {} }
			const resp = await ep.goto(`${BASE}${route.path}`, { waitUntil: 'domcontentloaded' }).catch(() => null)
			await settle(ep)
			cell.checks.http = resp ? resp.status() : 'nav-fail'
			cell.checks.finalUrl = ep.url().replace(BASE, '')
			const ov = await checkOverflow(ep)
			cell.checks.overflow = ov
			cell.checks.overflowOk = Math.max(ov.doc, ov.app, ov.main) <= 1
			cell.checks.touchOffenders = await checkTouchTargets(ep)
			cell.checks.axe = await runAxe(ep)
			cell.checks.axeViolations = cell.checks.axe.length
			const shot = await snap(ep, `sweep__${route.id}__light__${vp.w}`)
			cell.proof = shot.sha256.slice(0, 16)
			const failReasons = []
			if (!cell.checks.overflowOk) failReasons.push(`overflow`)
			if (cell.checks.axeViolations) failReasons.push(`axe ${cell.checks.axeViolations}`)
			if (cell.checks.touchOffenders.length) failReasons.push(`touch<44: ${cell.checks.touchOffenders.length}`)
			cell.status = failReasons.length ? 'fail' : 'ok'
			if (failReasons.length) cell.failReasons = failReasons
			record(cell)
		}
	}
	await ectx.close()

	// Admin context: roster/print is app-admin only — cover the real print
	// surface with a resolved periodId (light × 1440 + 320 reflow).
	const adminState = await loginState(browser, 'admin')
	const adctx = await browser.newContext({ baseURL: BASE, storageState: adminState, viewport: { width: 1440, height: 900 } })
	const adp = await adctx.newPage()
	await adp.goto(`${BASE}/apps/dutycheck/roster`, { waitUntil: 'domcontentloaded' })
	await settle(adp)
	const periodId = await adp.evaluate(() => {
		const m = new URL(location.href).searchParams.get('periodId')
		if (m) return m
		const sel = document.getElementById('dc-roster-period-switcher')
		return sel && sel.value ? sel.value : null
	})
	for (const vp of [{ w: 1440, h: 900 }, { w: 320, h: 640 }]) {
		await adp.setViewportSize({ width: vp.w, height: vp.h })
		const cell = { id: `roster-print@light@${vp.w}`, role: 'admin', theme: 'light', viewport: vp.w, checks: {} }
		const url = periodId ? `${BASE}/apps/dutycheck/roster/print?periodId=${periodId}` : `${BASE}/apps/dutycheck/roster/print`
		const resp = await adp.goto(url, { waitUntil: 'domcontentloaded' }).catch(() => null)
		await settle(adp)
		cell.checks.http = resp ? resp.status() : 'nav-fail'
		cell.checks.periodId = periodId
		cell.checks.hasPrintSurface = await adp.locator('#dc-print-root, .dc-print, main, #dc-main-content, .dc-denied').first().isVisible().catch(() => false)
		const ov = await checkOverflow(adp)
		cell.checks.overflow = ov
		cell.checks.overflowOk = Math.max(ov.doc, ov.app, ov.main) <= 1
		cell.checks.axe = await runAxe(adp)
		cell.checks.axeViolations = cell.checks.axe.length
		const shot = await snap(adp, `sweep__roster-print__light__${vp.w}__admin`)
		cell.proof = shot.sha256.slice(0, 16)
		const failReasons = []
		if (cell.checks.http >= 500 || cell.checks.http === 'nav-fail') failReasons.push(`http ${cell.checks.http}`)
		if (!cell.checks.hasPrintSurface) failReasons.push('no print surface rendered')
		if (!cell.checks.overflowOk) failReasons.push(`overflow ${JSON.stringify(ov)}`)
		if (cell.checks.axeViolations > 0) failReasons.push(`axe ${cell.checks.axeViolations}`)
		cell.status = failReasons.length ? 'fail' : 'ok'
		if (failReasons.length) cell.failReasons = failReasons
		record(cell)
	}
	await adctx.close()
	await ctx.close().catch(() => {})
}

/* ───────────────────────────── dialogs ───────────────────────────── */
async function probeDialogLifecycle(page, name, openFn, opts = {}) {
	const r = { id: name, checks: {}, status: 'ok', fails: [] }
	const fail = (m) => { r.fails.push(m); r.status = 'fail' }

	let trigger = null
	try {
		trigger = await openFn()
	} catch (err) {
		fail(`trigger threw: ${String(err).slice(0, 160)}`)
		record(r)
		return null
	}
	if (!trigger) { fail('no trigger found'); record(r); return null }
	// dialog may open async (candidate fetches) — poll, don't one-shot
	await page.waitForFunction(
		() => [...document.querySelectorAll('.dc-modal:not([hidden]) [role="dialog"], .dc-license-modal:not([hidden]) [role="dialog"], dialog[open], #app-content [role="dialog"]')]
			.some((d) => d.offsetParent !== null || (d.tagName === 'DIALOG' && d.open)),
		{ timeout: 8000 },
	).catch(() => {})
	await settle(page, 8000)

	const VISIBLE_DIALOG = '.dc-modal:not([hidden]) [role="dialog"], .dc-license-modal:not([hidden]) [role="dialog"], dialog[open], #app-content [role="dialog"]'
	const dialogInfo = await page.evaluate((sel) => {
		const dlg = [...document.querySelectorAll(sel)].find((d) => d.offsetParent !== null || d.tagName === 'DIALOG')
		if (!dlg) return null
		const labelId = dlg.getAttribute('aria-labelledby')
		return {
			tag: dlg.tagName.toLowerCase(),
			role: dlg.getAttribute('role'),
			ariaModal: dlg.getAttribute('aria-modal'),
			nativeOpen: dlg.hasAttribute('open'),
			labelId,
			labelText: labelId ? (document.getElementById(labelId)?.textContent || '') : '',
			inModalScope: !!dlg.closest('.dc-modal'),
			isNativeDialog: dlg.tagName === 'DIALOG',
		}
	}, VISIBLE_DIALOG)
	if (!dialogInfo) { fail('dialog not found after trigger'); record(r); return null }
	r.checks.dialog = dialogInfo
	if (dialogInfo.tag !== 'dialog' && dialogInfo.role !== 'dialog') fail('missing role=dialog')
	if (dialogInfo.tag !== 'dialog' && dialogInfo.ariaModal !== 'true') fail('missing aria-modal')
	if (!dialogInfo.labelText.trim()) fail('aria-labelledby unresolved/empty')

	// Focus moved inside?
	r.checks.focusInside = await page.evaluate((sel) => {
		const dlg = [...document.querySelectorAll(sel)].find((d) => d.offsetParent !== null || d.tagName === 'DIALOG')
		const ae = document.activeElement
		return !!(dlg && ae && (dlg === ae || dlg.contains(ae)))
	}, VISIBLE_DIALOG)
	if (!r.checks.focusInside) fail('focus did not move inside dialog')

	// Focus trap: Tab on last wraps to first; Shift+Tab on first wraps to last.
	const trap = await page.evaluate((sel) => {
		const dlg = [...document.querySelectorAll(sel)].find((d) => d.offsetParent !== null || d.tagName === 'DIALOG')
		if (!dlg) return null
		const list = [...dlg.querySelectorAll('a[href],input:not([disabled]):not([type="hidden"]),select:not([disabled]),textarea:not([disabled]),button:not([disabled]),[tabindex]:not([tabindex="-1"])')]
			.filter((n) => n.offsetParent !== null || n === document.activeElement)
		return { count: list.length, firstTag: list[0]?.tagName, lastTag: list[list.length - 1]?.tagName }
	}, VISIBLE_DIALOG)
	r.checks.focusableCount = trap?.count
	if (trap && trap.count > 1) {
		// Tab from last → first (forward wrap)
		await page.evaluate((sel) => {
			const dlg = [...document.querySelectorAll(sel)].find((d) => d.offsetParent !== null || d.tagName === 'DIALOG')
			const list = [...dlg.querySelectorAll('button:not([disabled]),input:not([disabled]):not([type="hidden"]),select,textarea,a[href],[tabindex]:not([tabindex="-1"])')]
				.filter((n) => n.offsetParent !== null)
			list[list.length - 1]?.focus()
		}, VISIBLE_DIALOG)
		await page.keyboard.press('Tab')
		const wrappedForward = await page.evaluate((sel) => {
			const dlg = [...document.querySelectorAll(sel)].find((d) => d.offsetParent !== null || d.tagName === 'DIALOG')
			return !!(dlg && dlg.contains(document.activeElement))
		}, VISIBLE_DIALOG)
		if (!wrappedForward) fail('Tab escaped dialog forward')
		await page.evaluate((sel) => {
			const dlg = [...document.querySelectorAll(sel)].find((d) => d.offsetParent !== null || d.tagName === 'DIALOG')
			const list = [...dlg.querySelectorAll('button:not([disabled]),input:not([disabled]):not([type="hidden"]),select,textarea,a[href],[tabindex]:not([tabindex="-1"])')]
				.filter((n) => n.offsetParent !== null)
			list[0]?.focus()
		}, VISIBLE_DIALOG)
		await page.keyboard.press('Shift+Tab')
		const wrappedBack = await page.evaluate((sel) => {
			const dlg = [...document.querySelectorAll(sel)].find((d) => d.offsetParent !== null || d.tagName === 'DIALOG')
			return !!(dlg && dlg.contains(document.activeElement))
		}, VISIBLE_DIALOG)
		if (!wrappedBack) fail('Shift+Tab escaped dialog backward')
	}
	await snap(page, `dialog__${name}__open`)

	// Escape closes
	await page.keyboard.press('Escape')
	await page.waitForTimeout(350)
	const afterEsc = await page.evaluate((sel) => ({
		stillOpen: [...document.querySelectorAll(sel)].some((d) => d.offsetParent !== null || (d.tagName === 'DIALOG' && d.open)),
		activeTag: document.activeElement?.tagName,
		activeId: document.activeElement?.id || '',
		activeIsTrigger: false,
	}), VISIBLE_DIALOG)
	r.checks.escapeClosed = !afterEsc.stillOpen
	if (afterEsc.stillOpen) fail('Escape did not close dialog')
	r.checks.afterEscapeFocus = afterEsc.activeTag + (afterEsc.activeId ? `#${afterEsc.activeId}` : '')
	// Focus restore: trigger still connected and focused, or at least not body
	const focusOk = await page.evaluate((t) => {
		const el = t && document.querySelector(t)
		return { active: document.activeElement?.tagName, triggerConnected: el ? el.isConnected : null }
	}, opts.triggerSelector || null)
	r.checks.focusAfterClose = focusOk
	if (focusOk.active === 'BODY') fail('focus restored to <body> — trigger likely destroyed by re-render')
	if (opts.triggerSelector && focusOk.triggerConnected === false) {
		r.checks.focusRestoreNote = 'trigger detached post-close'
	}

	// Cancel path: reopen → click Cancel/X → closed + focus back on trigger
	try {
		await openFn()
		await settle(page, 8000)
		const reopened = await page.evaluate((sel) => [...document.querySelectorAll(sel)].some((d) => d.offsetParent !== null || (d.tagName === 'DIALOG' && d.open)), VISIBLE_DIALOG)
		if (!reopened) { fail('dialog did not reopen for cancel path'); record(r); return r }
		const dlgBtnSel = VISIBLE_DIALOG.split(',').map((x) => `${x.trim()} button`).join(',')
		await page.locator(dlgBtnSel)
			.filter({ hasText: /Cancel|Abbrechen|Close|Schließen|Back|Zurück|Keep|Behalten/ }).first()
			.click({ timeout: 5000 })
		await page.waitForTimeout(350)
		const afterCancel = await page.evaluate((sel) => ({
			stillOpen: [...document.querySelectorAll(sel)].some((d) => d.offsetParent !== null || (d.tagName === 'DIALOG' && d.open)),
			active: document.activeElement?.tagName,
			activeId: document.activeElement?.id || '',
			activeText: (document.activeElement?.textContent || '').trim().slice(0, 40),
		}), VISIBLE_DIALOG)
		r.checks.cancelClosed = !afterCancel.stillOpen
		r.checks.focusAfterCancel = afterCancel
		if (afterCancel.stillOpen) fail('Cancel/close did not dismiss dialog')
		if (afterCancel.active === 'BODY') fail('focus lost to <body> after cancel — no restore')
	} catch (err) {
		fail(`cancel path error: ${String(err).slice(0, 160)}`)
	}
	record(r)
	return r
}

async function phaseDialogs(browser) {
	const plannerState = await loginState(browser, 'planner')
	const employeeState = await loginState(browser, 'employee')

	const ctx = await browser.newContext({ baseURL: BASE, storageState: plannerState, viewport: { width: 1440, height: 900 } })
	const page = await ctx.newPage()
	page.on('pageerror', (e) => console.log('PAGEEXC:', String(e).slice(0, 200)))

	// 1) employees → Deactivate confirmDialog
	await page.goto(`${BASE}/apps/dutycheck/employees`, { waitUntil: 'domcontentloaded' })
	await settle(page)
	await page.waitForSelector('#dc-main-content', { timeout: 20000 }).catch(() => {})
	await probeDialogLifecycle(page, 'employee-deactivate-confirm', async () => {
		const btn = page.locator('#dc-main-content button', { hasText: /Deactivate|Deaktivieren/ }).first()
		if (!(await btn.count())) return null
		const sel = 'button:has-text("Deactivate")'
		await btn.click()
		return true
	}, { triggerSelector: null })

	// 2) locations → Deactivate confirmDialog
	await page.goto(`${BASE}/apps/dutycheck/locations`, { waitUntil: 'domcontentloaded' })
	await settle(page)
	await probeDialogLifecycle(page, 'location-deactivate-confirm', async () => {
		const btn = page.locator('#dc-main-content button', { hasText: /Deactivate|Deaktivieren/ }).first()
		if (!(await btn.count())) return null
		await btn.click()
		return true
	})

	// 3) periods → a transition dialog (promptReason or publish confirm)
	await page.goto(`${BASE}/apps/dutycheck/periods`, { waitUntil: 'domcontentloaded' })
	await settle(page)
	await page.waitForFunction(() => !document.querySelector('#dc-periods-table-body .dc-loading'), { timeout: 20000 }).catch(() => {})
	await probeDialogLifecycle(page, 'period-transition-dialog', async () => {
		const btn = page.locator('#dc-main-content button').filter({ hasText: /^Publish$|^Close$|^Re-open$|^Veröffentlichen$|^Schließen$|^Öffnen$/ }).first()
		if (!(await btn.count())) return null
		await btn.click()
		return true
	})

	// 4) absences → review promptReason (reject)
	await page.goto(`${BASE}/apps/dutycheck/absences`, { waitUntil: 'domcontentloaded' })
	await settle(page)
	await probeDialogLifecycle(page, 'absence-review-prompt', async () => {
		const btn = page.locator('#dc-main-content button').filter({ hasText: /^Reject$|^Ablehnen$/ }).first()
		if (!(await btn.count())) return null
		await btn.click()
		return true
	})

	// 5) patterns → openModal (new pattern / assign)
	await page.goto(`${BASE}/apps/dutycheck/patterns`, { waitUntil: 'domcontentloaded' })
	await settle(page)
	await probeDialogLifecycle(page, 'pattern-modal', async () => {
		const btn = page.locator('#dc-main-content button').filter({ hasText: /^New pattern$|^Neues Muster$/ }).first()
		if (!(await btn.count())) return null
		await btn.click()
		return true
	})

	// 6) roster → assignment create modal: pick an open period first
	await page.goto(`${BASE}/apps/dutycheck/roster`, { waitUntil: 'domcontentloaded' })
	await settle(page)
	await page.waitForSelector('#dc-roster-period-switcher, #dc-main-content', { timeout: 25000 }).catch(() => {})
	await page.waitForFunction(() => {
		const sel = document.getElementById('dc-roster-period-switcher')
		return sel && sel.options.length > 0
	}, { timeout: 15000 }).catch(() => {})
	await page.waitForTimeout(1500)
	// Pick an open period — option labels carry "· Open"/"· Offen" suffix
	const switcher = page.locator('#dc-roster-period-switcher')
	if (await switcher.count()) {
		const openLabel = await page.evaluate(() => {
			const sel = document.getElementById('dc-roster-period-switcher')
			if (!sel) return null
			const hit = [...sel.options].find((o) => /·\s*(Open|Offen)\b/i.test(o.textContent || ''))
			return hit ? hit.textContent : null
		})
		if (openLabel) {
			await switcher.selectOption({ label: openLabel }).catch(() => {})
			await page.waitForTimeout(2000)
			await settle(page)
		}
	}
	await probeDialogLifecycle(page, 'roster-assignment-modal', async () => {
		const btn = page.locator('#dc-roster-add-assignment')
		if ((await btn.count()) && (await btn.isVisible().catch(() => false)) && (await btn.getAttribute('aria-disabled')) !== 'true' && !(await btn.isDisabled().catch(() => true))) {
			await btn.click()
			return true
		}
		// fallback: click a populated grid cell to open the edit modal
		const cell = page.locator('.dc-roster-grid [role="gridcell"], #dc-roster-grid-wrap td, #dc-roster-grid-wrap .dc-roster-cell').first()
		if (!(await cell.count())) return null
		await cell.click()
		return true
	})

	// 7) license remove modal (settings/license) — app-admin surface
	const adminState = await loginState(browser, 'admin')
	const actx = await browser.newContext({ baseURL: BASE, storageState: adminState, viewport: { width: 1440, height: 900 } })
	const ap = await actx.newPage()
	await ap.goto(`${BASE}/apps/dutycheck/settings/license`, { waitUntil: 'domcontentloaded' })
	await settle(ap)
	await probeDialogLifecycle(ap, 'license-remove-modal', async () => {
		const btn = ap.locator('#dc-license-remove')
		if (!(await btn.count()) || !(await btn.isVisible().catch(() => false))) return null
		await btn.click()
		return true
	})
	await actx.close()

	// 7b) LEARNED-CLASS HUNT: confirm → server mutation → list re-render destroys
	// trigger → where does focus land? (must NOT be <body>)
	{
		const r = { id: 'employee-deactivate-focus-after-rerender', checks: {}, status: 'ok', fails: [] }
		await page.goto(`${BASE}/apps/dutycheck/employees`, { waitUntil: 'domcontentloaded' })
		await settle(page)
		await page.waitForSelector('#dc-main-content', { timeout: 20000 }).catch(() => {})
		await page.waitForTimeout(1500)
		const btn = page.locator('#dc-main-content button').filter({ hasText: /^Deactivate$|^Deaktivieren$/ }).first()
		if (await btn.count()) {
			await btn.click()
			await page.waitForSelector('[role="dialog"]', { timeout: 8000 })
			// Confirm the deactivate (mutates → list re-render → trigger destroyed)
			await page.locator('.dc-modal [role="dialog"] button').filter({ hasText: /Deactivate|Deaktivieren/ }).first().click()
			await page.waitForTimeout(1200)
			await settle(page)
			const post = await page.evaluate(() => ({
				active: document.activeElement?.tagName,
				activeId: document.activeElement?.id || '',
				activeCls: String(document.activeElement?.className || '').slice(0, 60),
				activeText: (document.activeElement?.textContent || '').trim().slice(0, 40),
			}))
			r.checks.focusAfterMutatingConfirm = post
			if (post.active === 'BODY' || post.active === 'HTML') {
				r.status = 'fail'
				r.fails.push(`focus lost to <${post.active.toLowerCase()}> after destructive confirm + re-render`)
			}
			await snap(page, 'dialog__employee-deactivate__after-confirm')
			// restore: re-activate the same employee (first row toggle now says Activate)
			const react = page.locator('#dc-main-content button').filter({ hasText: /^Activate$|^Aktivieren$/ }).first()
			if (await react.count()) {
				await react.click()
				await page.waitForTimeout(1200)
				await settle(page)
			}
		} else {
			r.status = 'fail'; r.fails.push('no Deactivate button to probe')
		}
		record(r)
	}

	await ctx.close()
	const ectx = await browser.newContext({ baseURL: BASE, storageState: employeeState, viewport: { width: 1440, height: 900 } })
	const ep = await ectx.newPage()
	ep.on('pageerror', (e) => console.log('EMP-PAGEEXC:', String(e).slice(0, 200)))
	await ep.goto(`${BASE}/apps/dutycheck/my-roster`, { waitUntil: 'domcontentloaded' })
	await settle(ep)
	await probeDialogLifecycle(ep, 'employee-swap-native-dialog', async () => {
		const btn = ep.locator('#dc-main-content button').filter({ hasText: /Request swap|Tausch/i }).first()
		if (!(await btn.count())) return null
		await btn.click()
		return true
	})
	// 9) iCal rotate confirmDialog
	await probeDialogLifecycle(ep, 'employee-ical-confirm', async () => {
		const btn = ep.locator('#dc-ical-rotate-button')
		if (!(await btn.count()) || !(await btn.isVisible())) return null
		await btn.click()
		return true
	})
	await ectx.close()
}

/* ───────────────────────────── states ───────────────────────────── */
async function phaseStates(browser) {
	// anon → login redirect
	const anon = await browser.newContext({ baseURL: BASE, viewport: { width: 1440, height: 900 } })
	const ap = await anon.newPage()
	const resp = await ap.goto(`${BASE}/apps/dutycheck/`, { waitUntil: 'domcontentloaded' })
	await settle(ap)
	const anonCell = { id: 'anon-index-redirect', checks: { finalUrl: ap.url(), http: resp?.status() }, status: 'ok' }
	anonCell.checks.onLogin = /\/login/.test(ap.url())
	if (!anonCell.checks.onLogin) { anonCell.status = 'fail'; anonCell.fails = ['anon did not land on /login'] }
	await snap(ap, 'state__anon-redirect')
	record(anonCell)
	await anon.close()

	// norole → needs-role page
	const noroleState = await loginState(browser, 'norole')
	const nctx = await browser.newContext({ baseURL: BASE, storageState: noroleState, viewport: { width: 1440, height: 900 } })
	const np = await nctx.newPage()
	await np.goto(`${BASE}/apps/dutycheck/`, { waitUntil: 'domcontentloaded' })
	await settle(np)
	const nrCell = { id: 'norole-needs-role', checks: { finalUrl: np.url().replace(BASE, ''), }, status: 'ok' }
	nrCell.checks.needsRoleVisible = await np.locator('#dc-main-content, .dc-denied, [id*="needs-role"], main').first().isVisible().catch(() => false)
	nrCell.checks.text = (await np.locator('#app-content').textContent().catch(() => ''))?.slice(0, 200)
	await snap(np, 'state__norole-needs-role')
	record(nrCell)
	// norole direct planner page → denied?
	const resp2 = await np.goto(`${BASE}/apps/dutycheck/employees`, { waitUntil: 'domcontentloaded' })
	await settle(np)
	const nrDeny = { id: 'norole-planner-page', checks: { http: resp2?.status(), finalUrl: np.url().replace(BASE, '') }, status: 'ok' }
	nrDeny.checks.deniedShown = await np.locator('.dc-denied, [role="alert"], #dc-denied-main').first().isVisible().catch(() => false)
	await snap(np, 'state__norole-employees-denied')
	record(nrDeny)
	await nctx.close()

	// employee → planner-only page (employees) → denied surface
	const empState = await loginState(browser, 'employee')
	const ectx = await browser.newContext({ baseURL: BASE, storageState: empState, viewport: { width: 1440, height: 900 } })
	const ep = await ectx.newPage()
	const resp3 = await ep.goto(`${BASE}/apps/dutycheck/employees`, { waitUntil: 'domcontentloaded' })
	await settle(ep)
	const empDeny = { id: 'employee-planner-page-denied', checks: { http: resp3?.status(), finalUrl: ep.url().replace(BASE, '') }, status: 'ok' }
	empDeny.checks.deniedShown = await ep.locator('.dc-denied, [role="alert"], #dc-denied-main').first().isVisible().catch(() => false)
	empDeny.checks.hasRecoveryCta = await ep.locator('a.button.primary, a[href*="apps"]').first().isVisible().catch(() => false)
	await snap(ep, 'state__employee-denied-employees')
	if (!empDeny.checks.deniedShown) { empDeny.status = 'fail'; empDeny.fails = ['no denied surface for employee→planner page'] }
	record(empDeny)

	// fetch-error surface: abort periods API → error row + retry CTA, no raw codes
	const pctx = await browser.newContext({ baseURL: BASE, storageState: await loginState(browser, 'planner'), viewport: { width: 1440, height: 900 } })
	const pp = await pctx.newPage()
	await pp.route('**/apps/dutycheck/api/periods**', (route) => route.abort('failed'))
	await pp.goto(`${BASE}/apps/dutycheck/periods`, { waitUntil: 'domcontentloaded' })
	await settle(pp)
	await pp.waitForSelector('.dc-table__fetch-error-row, .dc-inline-retry, [role="alert"]', { timeout: 15000 }).catch(() => {})
	const errCell = { id: 'periods-fetch-error', checks: {}, status: 'ok' }
	errCell.checks.errorRowVisible = await pp.locator('.dc-table__fetch-error-row, .dc-inline-retry, [role="alert"]').first().isVisible().catch(() => false)
	errCell.checks.retryBtn = await pp.locator('button', { hasText: /Retry|Wiederholen|Erneut/ }).first().isVisible().catch(() => false)
	errCell.checks.rawCodeText = await pp.evaluate(() => {
		const main = document.getElementById('dc-main-content') || document.querySelector('#app-content')
		const txt = main ? main.textContent || '' : ''
		return /ERR_|ECONNREFUSED|TypeError|undefined|null|null|null|\b500\b|\b503\b/.test(txt) ? txt.slice(0, 200) : null
	})
	await snap(pp, 'state__periods-fetch-error')
	if (!errCell.checks.errorRowVisible) { errCell.status = 'fail'; errCell.fails = ['no error surface on aborted fetch'] }
	if (!errCell.checks.retryBtn) { errCell.status = 'fail'; (errCell.fails = errCell.fails || []).push('no recovery CTA (Retry)') }
	if (errCell.checks.rawCodeText) { errCell.status = 'fail'; (errCell.fails = errCell.fails || []).push(`raw error code visible: ${errCell.checks.rawCodeText}`) }
	record(errCell)

	// empty state: fresh locations fetch → empty catalog copy
	await pp.unroute('**/apps/dutycheck/api/periods**')
	await pp.route('**/apps/dutycheck/api/locations**', (route) => {
		if (route.request().method() === 'GET') {
			return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [] }) })
		}
		return route.continue()
	})
	await pp.goto(`${BASE}/apps/dutycheck/locations`, { waitUntil: 'domcontentloaded' })
	await settle(pp)
	const emptyCell = { id: 'locations-empty-state', checks: {}, status: 'ok' }
	emptyCell.checks.emptyCopy = await pp.evaluate(() => {
		const main = document.getElementById('dc-main-content')
		const txt = main ? main.textContent || '' : ''
		return /No locations|Keine|empty|Leer/i.test(txt) ? txt.replace(/\s+/g, ' ').slice(0, 200) : null
	})
	await snap(pp, 'state__locations-empty')
	record(emptyCell)
	await pctx.close()
}

/* ───────────────────────────── main ───────────────────────────── */
const browser = await chromium.launch({ headless: true })
try {
	if (PHASE === 'sweep') await phaseSweep(browser)
	else if (PHASE === 'dialogs') await phaseDialogs(browser)
	else if (PHASE === 'states') await phaseStates(browser)
	else if (PHASE === 'all') { await phaseSweep(browser); await phaseDialogs(browser); await phaseStates(browser) }
} finally {
	results.finishedAt = new Date().toISOString()
	writeFileSync(join(OUT, `results-${PHASE}.json`), JSON.stringify(results, null, 1))
	await browser.close()
}
const fails = results.cells.filter((c) => c.status === 'fail')
console.log(`\n== ${PHASE} done: ${results.cells.length} cells, ${fails.length} FAIL ==`)
for (const f of fails) console.log('FAIL:', f.id, JSON.stringify(f.failReasons || f.fails))
process.exit(fails.length ? 2 : 0)
