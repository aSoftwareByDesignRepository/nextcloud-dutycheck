// @ts-check
/**
 * long_locale_layout_clip mini-pass: force the ds probe planner to German
 * (compound-length copy) and sweep the densest routes at 320px — document/
 * app/main horizontal overflow + clipped-text heuristic + PNG evidence.
 *
 *   DS_PROBE_PASS=… node tests/e2e/_ds_locale_stress.mjs
 *
 * Locale is set/restored via occ outside this script (see lane report).
 */
import { chromium } from 'playwright'
import { createHash } from 'node:crypto'
import { writeFileSync, mkdirSync } from 'node:fs'
import { join } from 'node:path'
import { login } from './helpers/auth.js'

const BASE = process.env.NC_BASE_URL || 'http://localhost:8081'
const PASS = process.env.DS_PROBE_PASS || 'DsProbe!2026'
const OUT = process.env.FARM_OUT
	|| '/home/alex/Development/nextcloud-dev/.cursor/atlas-farm-v3/artifacts/dutycheck/probes/ds_chrome'
mkdirSync(OUT, { recursive: true })

const ROUTES = [
	{ id: 'today', path: '/apps/dutycheck/today' },
	{ id: 'roster', path: '/apps/dutycheck/roster' },
	{ id: 'employees', path: '/apps/dutycheck/employees' },
	{ id: 'periods', path: '/apps/dutycheck/periods' },
	{ id: 'absences', path: '/apps/dutycheck/absences' },
	{ id: 'settings-planning', path: '/apps/dutycheck/settings/planning' },
	{ id: 'settings-access', path: '/apps/dutycheck/settings/access' },
	{ id: 'settings-dienst-team', path: '/apps/dutycheck/settings/dienst-team' },
]

const results = []

const browser = await chromium.launch({ headless: true })
try {
	const loginCtx = await browser.newContext({ baseURL: BASE, viewport: { width: 320, height: 640 } })
	const loginPage = await loginCtx.newPage()
	await login(loginPage, { username: 'dc-ds-planner', password: PASS })
	const state = await loginCtx.storageState()
	await loginCtx.close()

	const ctx = await browser.newContext({ baseURL: BASE, storageState: state, viewport: { width: 320, height: 640 } })
	const page = await ctx.newPage()

	for (const route of ROUTES) {
		const resp = await page.goto(`${BASE}${route.path}`, { waitUntil: 'domcontentloaded' }).catch(() => null)
		await page.waitForSelector('#dc-main-content, main', { timeout: 25000 }).catch(() => {})
		await page.evaluate(() => new Promise((r) => setTimeout(r, 1200)))
		const checks = await page.evaluate(() => {
			const doc = document.documentElement
			const app = document.getElementById('app-content')
			const main = document.getElementById('dc-main-content') || document.querySelector('main')
			return {
				docOverflow: doc.scrollWidth - doc.clientWidth,
				appOverflow: app ? app.scrollWidth - app.clientWidth : 0,
				mainOverflow: main ? main.scrollWidth - main.clientWidth : 0,
				lang: doc.lang || '',
			}
		})
		// clipped text: any element whose text visually overflows its box
		const clips = await page.evaluate(() => {
			const out = []
			const els = document.querySelectorAll('#app-content .button, #app-content th, #app-content td, #app-content h1, #app-content h2, #app-content .dc-nav__link, #app-content label')
			for (const el of els) {
				if (el.scrollWidth - el.clientWidth > 2 && getComputedStyle(el).overflow === 'hidden' && (el.textContent || '').trim()) {
					out.push(`${el.tagName}.${String(el.className).split(' ')[0]}: ${(el.textContent || '').trim().slice(0, 60)}`)
				}
			}
			return out.slice(0, 8)
		})
		const buf = await page.screenshot({ fullPage: false })
		const name = `locale-de__${route.id}__320`
		writeFileSync(join(OUT, name + '.png'), buf)
		const sha = createHash('sha256').update(buf).digest('hex').slice(0, 16)
		const cell = { id: `${route.id}@de@320`, http: resp?.status(), ...checks, clipped: clips, sha }
		cell.status = (resp && resp.status() === 200 && checks.docOverflow <= 1 && checks.appOverflow <= 1 && checks.mainOverflow <= 1) ? 'ok' : 'fail'
		results.push(cell)
		console.log(`[${cell.status}] ${cell.id} :: ${JSON.stringify(checks)} clips=${clips.length}`)
	}
	writeFileSync(join(OUT, 'results-locale-de.json'), JSON.stringify({ phase: 'locale-de', finishedAt: new Date().toISOString(), cells: results }, null, 1))
	const fails = results.filter((c) => c.status !== 'ok')
	console.log(`== locale-de done: ${results.length} cells, ${fails.length} FAIL ==`)
	fails.forEach((f) => console.log('FAIL:', f.id, JSON.stringify(f).slice(0, 300)))
	process.exitCode = fails.length ? 1 : 0
} catch (e) {
	console.error('PROBE ERROR', e)
	process.exitCode = 1
} finally {
	try { await browser.close() } catch {}
}
