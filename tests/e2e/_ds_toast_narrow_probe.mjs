// @ts-check
/**
 * Targeted re-probe: periods@dark selected-row button--text contrast
 * (re-verifies the fix for the sweep FAIL after the live CSS edit) and
 * the toast-narrow overlap fix at 320px. Standalone — no spec runner.
 *
 *   DS_PROBE_PASS=… node tests/e2e/_ds_toast_narrow_probe.mjs
 */
import { chromium } from 'playwright'
import AxeBuilder from '@axe-core/playwright'
import { login } from './helpers/auth.js'

const BASE = process.env.NC_BASE_URL || 'http://localhost:8081'
const PASS = process.env.DS_PROBE_PASS || 'DsProbe!2026'

async function setUserTheme(page, themeId) {
	const failures = await page.evaluate(async ({ target, all }) => {
		const headers = { 'OCS-APIRequest': 'true', requesttoken: window.OC.requestToken }
		const out = []
		for (const id of all) {
			if (id === target) continue
			const res = await fetch(`/ocs/v2.php/apps/theming/api/v1/theme/${id}`, { method: 'DELETE', credentials: 'same-origin', headers })
			if (!res.ok && res.status !== 404) out.push(`${id}:${res.status}`)
		}
		const res = await fetch(`/ocs/v2.php/apps/theming/api/v1/theme/${target}/enable`, { method: 'PUT', credentials: 'same-origin', headers })
		if (!res.ok) out.push(`${target}:${res.status}`)
		return out
	}, { target: themeId, all: ['light', 'dark', 'light-highcontrast', 'dark-highcontrast'] })
	if (failures.length) throw new Error(`theme ${themeId}: ${failures.join(';')}`)
}

const browser = await chromium.launch({ headless: true })
try {
	const loginCtx = await browser.newContext({ baseURL: BASE, viewport: { width: 1440, height: 900 } })
	const loginPage = await loginCtx.newPage()
	await login(loginPage, { username: 'dc-ds-planner', password: PASS })
	const state = await loginCtx.storageState()
	await loginCtx.close()
	const ctx = await browser.newContext({ baseURL: BASE, storageState: state, viewport: { width: 1440, height: 900 } })
	const page = await ctx.newPage()

	// 1) periods@dark selected-row contrast re-probe
	await page.goto(`${BASE}/apps/dutycheck/`, { waitUntil: 'domcontentloaded' })
	await page.waitForSelector('#dc-main-content, main', { timeout: 30000 }).catch(() => {})
	await setUserTheme(page, 'dark')
	await page.goto(`${BASE}/apps/dutycheck/periods`, { waitUntil: 'domcontentloaded' })
	await page.waitForSelector('#dc-periods-table-body tr, #dc-main-content', { timeout: 25000 }).catch(() => {})
	// ensure a row is selected (click the select button if none is)
	const selCount = await page.locator('tr.is-selected').count()
	if (!selCount) {
		await page.locator('.dc-period-select').first().click().catch(() => {})
	}
	await page.waitForSelector('tr.is-selected', { timeout: 8000 }).catch(() => {})
	const themeOk = await page.evaluate(() => document.body.hasAttribute('data-theme-dark'))
	const selGeom = await page.evaluate(() => {
		const btn = document.querySelector('tr.is-selected .button--text')
		if (!btn) return null
		const cs = getComputedStyle(btn)
		const td = btn.closest('td')
		return { color: cs.color, bg: td ? getComputedStyle(td).backgroundColor : null, sel: !!btn.closest('tr.is-selected') }
	})
	const axe = await new AxeBuilder({ page }).include('#app-content').withTags(['wcag2a', 'wcag2aa']).analyze()
	const rows = axe.violations.map((v) => ({ id: v.id, impact: v.impact, nodes: v.nodes.map((n) => n.target.join(' ')).slice(0, 5) }))
	console.log(JSON.stringify({ themeOk, selGeom, violations: rows }, null, 1))

	// restore light theme
	await setUserTheme(page, 'light')

	// 2) toast-narrow re-probe at 320 (geometry non-intersection)
	const ctx320 = await browser.newContext({ baseURL: BASE, storageState: state, viewport: { width: 320, height: 640 } })
	const p320 = await ctx320.newPage()
	await p320.goto(`${BASE}/apps/dutycheck/today`, { waitUntil: 'domcontentloaded' })
	await p320.waitForSelector('#dc-main-content, main', { timeout: 30000 }).catch(() => {})
	await p320.evaluate(() => {
		window.DutyCheckMessaging.announce('The duty period could not be published because several assignments overlap with existing entries.', 'error')
	})
	await p320.waitForSelector('.dc-toast--error a[href^="mailto:"]', { timeout: 10000 }).catch(() => {})
	const geom = await p320.evaluate(() => {
		const R = (el) => el ? el.getBoundingClientRect().toJSON() : null
		const toast = document.querySelector('.dc-toast--error')
		const text = toast?.querySelector('.dc-toast__text')
		const link = toast?.querySelector('a[href^="mailto:"]')
		const close = toast?.querySelector('.dc-toast__close')
		const ix = (a, b) => a && b && a.x < b.right && b.x < a.right && a.y < b.bottom && b.y < a.bottom
		return { toast: R(toast), text: R(text), link: R(link), close: R(close), textClose: ix(R(text), R(close)), linkClose: ix(R(link), R(close)) }
	})
	console.log('TOAST320 ' + JSON.stringify(geom))
	await p320.screenshot({ path: '/home/alex/Development/nextcloud-dev/.cursor/atlas-farm-v3/artifacts/dutycheck/probes/ds_chrome/state__toast-narrow-320.png' })
	await browser.close()
} catch (e) {
	console.error('PROBE ERROR', e)
	process.exitCode = 1
	try { await browser.close() } catch {}
}
