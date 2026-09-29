// @ts-check
/**
 * Atlas ds_chrome evidence probe — measures computed colors of suspected
 * "theatre/dark-island" surfaces in light theme and captures element PNGs.
 *
 * Usage: node tests/e2e/_atlas_ds_theatre_probe.mjs
 * Env: FARM_OUT (screenshot dir), DS_PROBE_PASS, NC_BASE_URL
 */
import { chromium } from 'playwright'
import { createHash } from 'node:crypto'
import { mkdirSync, writeFileSync } from 'node:fs'
import { join } from 'node:path'
import { login } from './helpers/auth.js'

const BASE = process.env.NC_BASE_URL || 'http://localhost:8081'
const PASS = process.env.DS_PROBE_PASS || ''
const OUT = process.env.FARM_OUT || process.cwd()
mkdirSync(OUT, { recursive: true })

const TARGETS = [
	{ page: '/apps/dutycheck/roster', sels: ['.dc-roster-band-legend', '.dc-roster-band-legend__chip--early', '.dc-roster-grid__shift--early', '.dc-roster-grid__shift--day', '.dc-roster-grid__shift--night', '.dc-roster-grid__cell--filled', '#dc-roster-period-switcher'] },
	{ page: '/apps/dutycheck/periods', sels: ['.dc-publish-ceremony', '.dc-publish-ceremony__title', '.dc-publish-ceremony__chip'] },
	{ page: '/apps/dutycheck/dashboard', sels: ['.dc-dashboard-pulse-card', '.dc-dashboard-pulse', '#dc-dashboard-conflict-pulse > *'] },
	// Access gate is app-admin chrome — planner gets a denied surface.
	{ page: '/apps/dutycheck/settings/access', user: 'dc-ds-admin', sels: ['.dc-access-gate__panel', '.dc-access-gate__label', '.dc-access-gate__state'] },
]

const results = { startedAt: new Date().toISOString(), theme: 'light', probes: [] }

const browser = await chromium.launch({ headless: true })
const ctx = await browser.newContext({ baseURL: BASE, viewport: { width: 1440, height: 900 } })
const page = await ctx.newPage()
await login(page, { username: 'dc-ds-planner', password: PASS })

// force NC light theme for this user session
await page.evaluate(async () => {
	const token = (window.OC && window.OC.requestToken)
		|| document.querySelector('head[data-requesttoken]')?.getAttribute('data-requesttoken') || ''
	const headers = { requesttoken: token, 'OCS-APIRequest': 'true', Accept: 'application/json' }
	for (const id of ['dark', 'light-highcontrast', 'dark-highcontrast']) {
		await fetch(`/ocs/v2.php/apps/theming/api/v1/theme/${id}`, { method: 'DELETE', credentials: 'same-origin', headers }).catch(() => {})
	}
	await fetch('/ocs/v2.php/apps/theming/api/v1/theme/light/enable', { method: 'PUT', credentials: 'same-origin', headers }).catch(() => {})
})

for (const t of TARGETS) {
	let probePage = page
	let extraCtx = null
	if (t.user) {
		extraCtx = await browser.newContext({ baseURL: BASE, viewport: { width: 1440, height: 900 } })
		probePage = await extraCtx.newPage()
		await login(probePage, { username: t.user, password: PASS })
		// Same light-theme pin as the planner context.
		await probePage.evaluate(async () => {
			const token = (window.OC && window.OC.requestToken)
				|| document.querySelector('head[data-requesttoken]')?.getAttribute('data-requesttoken') || ''
			const headers = { requesttoken: token, 'OCS-APIRequest': 'true', Accept: 'application/json' }
			for (const id of ['dark', 'light-highcontrast', 'dark-highcontrast']) {
				await fetch(`/ocs/v2.php/apps/theming/api/v1/theme/${id}`, { method: 'DELETE', credentials: 'same-origin', headers }).catch(() => {})
			}
			await fetch('/ocs/v2.php/apps/theming/api/v1/theme/light/enable', { method: 'PUT', credentials: 'same-origin', headers }).catch(() => {})
		})
	}
	await probePage.goto(`${BASE}${t.page}`, { waitUntil: 'domcontentloaded' })
	await probePage.waitForLoadState('networkidle', { timeout: 8000 }).catch(() => {})
	await probePage.waitForTimeout(1200)
	const row = { page: t.page, user: t.user || 'dc-ds-planner', elements: [] }
	for (const sel of t.sels) {
		const loc = probePage.locator(sel).first()
		const info = await loc.evaluate((el) => {
			const cs = getComputedStyle(el)
			const r = el.getBoundingClientRect()
			return {
				visible: r.width > 0 && r.height > 0 && cs.display !== 'none' && cs.visibility !== 'hidden',
				bg: cs.backgroundImage === 'none' ? cs.backgroundColor : `${cs.backgroundColor} + ${cs.backgroundImage.slice(0, 90)}`,
				color: cs.color, border: cs.borderColor, w: Math.round(r.width), h: Math.round(r.height),
				text: (el.textContent || '').trim().replace(/\s+/g, ' ').slice(0, 60),
			}
		}).catch(() => null)
		row.elements.push({ sel, ...(info || { missing: true }) })
		if (info && info.visible) {
			const buf = await loc.screenshot().catch(() => null)
			if (buf) {
				const safe = sel.replace(/[^a-z0-9_-]+/gi, '_').slice(0, 60)
				const file = `probe__${t.page.split('/').pop()}__${safe}__light.png`
				writeFileSync(join(OUT, file), buf)
				row.elements[row.elements.length - 1].png = file
				row.elements[row.elements.length - 1].sha256 = createHash('sha256').update(buf).digest('hex').slice(0, 16)
			}
		}
	}
	results.probes.push(row)
	console.log(`[probe] ${t.page}`)
	for (const e of row.elements) console.log('   ', e.sel, '→', JSON.stringify(e).slice(0, 220))
	await extraCtx?.close().catch(() => {})
}
await browser.close()
writeFileSync(join(OUT, 'results-theatre-probe.json'), JSON.stringify(results, null, 1))
console.log('done')
