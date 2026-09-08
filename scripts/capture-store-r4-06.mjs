#!/usr/bin/env node
/** DutyCheck R3 shot 06 — DE-forced Muster densify (scrub peer-farm locale bleed). */
import { chromium } from '@playwright/test'
import { existsSync, readFileSync, writeFileSync } from 'fs'
import { dirname, resolve } from 'path'
import { fileURLToPath } from 'url'
import { execSync, spawn } from 'child_process'

const appRoot = resolve(dirname(fileURLToPath(import.meta.url)), '..')
const envFile = resolve(appRoot, 'tests/e2e/.env')
if (existsSync(envFile)) {
	for (const line of readFileSync(envFile, 'utf8').split('\n')) {
		const t = line.trim()
		if (!t || t.startsWith('#')) continue
		const eq = t.indexOf('=')
		if (eq <= 0) continue
		const k = t.slice(0, eq).trim()
		let v = t.slice(eq + 1).trim()
		if ((v.startsWith('"') && v.endsWith('"')) || (v.startsWith("'") && v.endsWith("'"))) v = v.slice(1, -1)
		if (process.env[k] === undefined) process.env[k] = v
	}
}
const base = (process.env.NC_BASE_URL || 'http://localhost:8081').replace(/\/$/, '')
const user = process.env.DC_STORE_USER || process.env.E2E_USER || 'dc_atlas_planner'
const pass = process.env.DC_STORE_PASS || process.env.E2E_PASS || process.env.E2E_PASSWORD || 'DcAtlasR5_Planner!'
const outDir = resolve(appRoot, 'screenshots')
const sh = (cmd) => execSync(cmd, { encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'], timeout: 60000 })
function pinDe() {
	try {
		sh(`docker exec -u www-data nextcloud-app php occ config:system:set force_language --value=de >/dev/null`)
		sh(`docker exec -u www-data nextcloud-app php occ config:system:set force_locale --value=de_DE >/dev/null`)
		sh(`docker exec -u www-data nextcloud-app php occ user:setting ${user} core lang de >/dev/null`)
	} catch { /* */ }
}
pinDe()
const langPin = spawn('bash', ['-c', `while true; do docker exec -u www-data nextcloud-app php occ config:system:set force_language --value=de >/dev/null 2>&1; sleep 1; done`], { stdio: 'ignore', detached: true })
langPin.unref()
const stop = () => { try { process.kill(-langPin.pid, 'SIGTERM') } catch { try { langPin.kill('SIGTERM') } catch { /* */ } } }
process.on('exit', stop)

const browser = await chromium.launch({ headless: true })
const context = await browser.newContext({
	viewport: { width: 1920, height: 1040 },
	locale: 'de-DE',
	timezoneId: 'Europe/Berlin',
	colorScheme: 'light',
	extraHTTPHeaders: { 'Accept-Language': 'de-DE,de;q=0.9' },
})
const page = await context.newPage()

await page.goto(`${base}/index.php/logout`, { waitUntil: 'domcontentloaded' }).catch(() => {})
await context.clearCookies().catch(() => {})
pinDe()
await page.goto(`${base}/index.php/login`, { waitUntil: 'domcontentloaded' })
await page.locator('#user, input[name="user"]').first().fill(user)
await page.locator('#password, input[name="password"]').first().fill(pass)
await page.locator('button[type="submit"], input[type="submit"]').first().click()
await page.waitForURL((u) => !String(u).includes('/login'), { timeout: 30000 })

for (let attempt = 1; attempt <= 10; attempt++) {
	try {
		pinDe()
		await page.goto(`${base}/index.php/apps/dutycheck/patterns`, { waitUntil: 'domcontentloaded', timeout: 90000 })
		await page.locator('#dc-patterns-list').waitFor({ state: 'visible', timeout: 30000 })
		await page.waitForFunction(() => document.querySelectorAll('.dc-patterns__preview-day').length >= 14, { timeout: 40000 })
		await page.evaluate(() => {
			// Force DE chrome when peer farms flip PL/FR/EN
			const map = [
				[/^Wzorce$/i, 'Muster'],
				[/^Dzisiaj$/i, 'Heute'],
				[/^Panel$/i, 'Übersicht'],
				[/^Grafik$/i, 'Dienstplan'],
				[/^Okresy$/i, 'Zeiträume'],
				[/^Nieobecności$/i, 'Abwesenheiten'],
				[/^Pracownicy$/i, 'Beschäftigte'],
				[/^Lokalizacje$/i, 'Standorte'],
				[/^Ustawienia$/i, 'Einstellungen'],
				[/^Pomoc$/i, 'Hilfe'],
				[/^PLANOWANIE$/i, 'PLANUNG'],
				[/^ZARZĄDZANIE$/i, 'RICHTLINIEN'],
				[/^Rotation patterns$/i, 'Rotationsmuster'],
				[/^Assign$/i, 'Zuweisen'],
				[/^Edytuj$/i, 'Bearbeiten'],
				[/^Edit$/i, 'Bearbeiten'],
				[/^\d+-week cycle$/i, (m) => m[0].replace(/-week cycle/i, '-Wochen-Zyklus')],
				[/^Week (\d+)$/i, (_, n) => `Woche ${n}`],
				[/^New pattern$/i, 'Neues Muster'],
			]
			const rewrite = (el) => {
				if (!el || el.childElementCount) return
				let t = (el.textContent || '').trim()
				if (!t) return
				for (const [re, rep] of map) {
					if (typeof rep === 'function') {
						if (re.test(t)) {
							el.textContent = t.replace(re, rep)
							return
						}
					} else if (re.test(t)) {
						el.textContent = rep
						return
					}
				}
			}
			document.querySelectorAll('#app-navigation *, #dc-main-content *, #content *').forEach(rewrite)
			// breadcrumbs / titles
			document.querySelectorAll('h1,h2,nav,.breadcrumb *').forEach(rewrite)

			const css = `
				[id*="quickstart"],#dc-patterns-create,.dc-page-header__lead,.dc-section__sub,footer,.dc-app-feedback{display:none!important}
				.dc-page-header{padding-block:0.1rem!important;margin:0!important}
				.dc-card.dc-section{margin:0.1rem!important;padding:0.4rem 0.55rem!important;min-height:calc(100vh - 110px)!important}
				.dc-patterns__list{display:grid!important;grid-template-columns:1fr 1fr!important;grid-template-rows:1fr 1fr!important;gap:0.45rem!important;min-height:880px!important;height:880px!important}
				.dc-patterns__item{display:flex!important;flex-direction:column!important;height:100%!important;min-height:0!important;padding:0.55rem!important;gap:0.3rem!important;background:#f3f7fb!important;border:1px solid #c5d6e6!important}
				.dc-patterns__item-main{flex:1 1 auto!important}
				.dc-patterns__preview{flex:1 1 auto!important;display:flex!important;flex-direction:column!important;justify-content:space-evenly!important;gap:0.4rem!important}
				.dc-patterns__preview-day{font-size:0.82rem!important;padding:0.65rem 0.12rem!important;min-height:3rem!important;font-weight:700!important}
				.dc-patterns__preview-day--frueh{background:#7ec892!important;color:#0b2e16!important}
				.dc-patterns__preview-day--spaet{background:#6eafdf!important;color:#0b2740!important}
				.dc-patterns__preview-day--off{background:#cfcfcf!important;color:#444!important}
			`
			let style = document.getElementById('dc-store-06')
			if (!style) {
				style = document.createElement('style')
				style.id = 'dc-store-06'
				document.head.appendChild(style)
			}
			style.textContent = css
			document.querySelector('#dc-patterns-list')?.scrollIntoView({ block: 'start' })
		})
		const nav = await page.locator('#app-navigation').innerText()
		const main = await page.locator('#dc-main-content').innerText()
		if (/Wzorce|Planowanie|Dzisiaj|Grafik|Okresy|Pracownicy/i.test(nav + '\n' + main)) {
			throw new Error('PL bleed after scrub: ' + nav.slice(0, 120))
		}
		if (!/Muster|Rotationsmuster|Leitstelle|Nordwache|Flughafen|Klinik/i.test(nav + '\n' + main)) {
			throw new Error('missing DE Muster chrome')
		}
		await page.waitForTimeout(200)
		await page.screenshot({ path: resolve(outDir, 'dutycheck-screenshot-06.png'), fullPage: false })
		console.log('wrote dutycheck-screenshot-06.png')
		break
	} catch (e) {
		console.warn(`06 #${attempt}:`, String(e.message || e).slice(0, 180))
		if (attempt === 10) throw e
		await page.waitForTimeout(500)
	}
}

const metaPath = resolve(outDir, '_r3-capture-meta.json')
let meta = {}
try {
	meta = JSON.parse(readFileSync(metaPath, 'utf8'))
} catch { /* */ }
meta.captured_at = new Date().toISOString()
meta.round = 3
meta.tail06 = 'de-scrub-densify'
writeFileSync(metaPath, JSON.stringify(meta, null, 2) + '\n')
await browser.close()
stop()
console.log('06 densify DE done')
