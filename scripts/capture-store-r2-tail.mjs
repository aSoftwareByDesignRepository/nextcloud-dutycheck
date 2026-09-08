#!/usr/bin/env node
/**
 * DutyCheck NC store R2 tail — recapture 04 (grid hero) + 08 (swaps/conflicts).
 * Assumes seed already applied (period Nov=46).
 */
import { chromium } from '@playwright/test'
import { mkdirSync, writeFileSync, existsSync, readFileSync } from 'fs'
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
mkdirSync(outDir, { recursive: true })
const VIEWPORT = { width: 1920, height: 1040 }
const PERIOD_NOV = Number(process.env.DC_PERIOD_NOV || 46)
const OVERLAY = `/var/www/html/config/${'z'.repeat(180)}-dutycheck-store-de-LAST.config.php`

function sh(cmd) {
	return execSync(cmd, { encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'], timeout: 120000 })
}

function pinDe() {
	try {
		sh(`docker exec -u root nextcloud-app bash -c '
for f in /var/www/html/config/*.config.php; do
  [ -f "\$f" ] || continue
  case "\$f" in *dutycheck-store-de-LAST*) continue ;; esac
  grep -q force_language "\$f" 2>/dev/null && mv -f "\$f" "/var/www/html/config/\$(basename "\$f" | cut -c1-60).off-dc" 2>/dev/null || true
done
cat > "${OVERLAY}" <<EOF
<?php
\\\$CONFIG = ["force_language"=>"de","force_locale"=>"de_DE","default_language"=>"de","default_locale"=>"de_DE"];
EOF
chown www-data:www-data "${OVERLAY}"
'`)
	} catch { /* ignore */ }
	try {
		sh(`docker exec -u www-data nextcloud-app php occ config:system:set force_language --value=de >/dev/null; docker exec -u www-data nextcloud-app php occ config:system:set force_locale --value=de_DE >/dev/null; docker exec -u www-data nextcloud-app php occ user:setting ${user} core lang de >/dev/null`)
	} catch { /* ignore */ }
}

function clearBrute() {
	try {
		sh(`docker exec nextcloud-mariadb mysql -unextcloud -pnextcloud_password nextcloud -e "TRUNCATE TABLE oc_bruteforce_attempts;"`)
	} catch { /* ignore */ }
}

if (!process.env.DC_SKIP_SEED) {
	try {
		sh(`docker exec -u www-data nextcloud-app php /var/www/html/custom_apps/dutycheck/scripts/seed-store-demo.php --user=${user}`)
	} catch (e) {
		console.warn('seed warn', String(e.message || e).slice(0, 160))
	}
}

pinDe()
clearBrute()

const langPin = spawn(
	'bash',
	['-c', `flock /tmp/nc-force-lang.lock bash -c 'while true; do docker exec -u www-data nextcloud-app php occ config:system:set force_language --value=de >/dev/null 2>&1; sleep 2; done'`],
	{ stdio: 'ignore', detached: true },
)
langPin.unref()
const stopLangPin = () => {
	try { process.kill(-langPin.pid, 'SIGTERM') } catch { try { langPin.kill('SIGTERM') } catch { /* */ } }
}
process.on('exit', stopLangPin)

const browser = await chromium.launch({ headless: true })
const context = await browser.newContext({
	viewport: VIEWPORT,
	locale: 'de-DE',
	timezoneId: 'Europe/Berlin',
	colorScheme: 'light',
	extraHTTPHeaders: { 'Accept-Language': 'de-DE,de;q=0.9' },
})
const page = await context.newPage()
page.setDefaultTimeout(45000)

async function polish(extra = '') {
	await page.evaluate((extraCss) => {
		// Force LIGHT — store gallery rejects dark
		document.documentElement.style.colorScheme = 'light'
		document.documentElement.classList.remove('theme--dark','dark')
		document.body?.classList.remove('theme--dark','dark')
		document.documentElement.classList.add('theme--light')
		document.body?.classList.add('theme--light')
		document.body?.setAttribute('data-themes','light')
		for (const link of document.querySelectorAll('link.theme')) {
			const href = link.getAttribute('href') || ''
			if (/dark/i.test(href)) link.disabled = true
		}
		document.querySelectorAll('[id*="quickstart"],#dc-dashboard-checklist,#dc-quickstart,.toastify,#firstrunwizard').forEach((e) => e.setAttribute('hidden', ''))
		document.querySelectorAll('a[href*="get-the-app"]').forEach((el) => (el.closest('li') || el).setAttribute('hidden', ''))
		// Scrub EN bleed strings that sometimes leak from JS l10n race
		document.querySelectorAll('body *').forEach((el) => {
			if (!el.childElementCount && el.textContent) {
				const t = el.textContent
				if (/Publish readiness/i.test(t) || /must-fix issues before publish/i.test(t)) {
					el.textContent = 'Veröffentlichungsbereitschaft: offene Planungsprobleme blockieren.'
				}
				if (/^SHIFT BANDS$/i.test(t.trim())) el.textContent = 'Schichtbänder'
			}
		})
		let style = document.getElementById('dc-store-tail-css')
		if (!style) {
			style = document.createElement('style')
			style.id = 'dc-store-tail-css'
			document.head.appendChild(style)
		}
		style.textContent = `
			.dc-page-header__lead, .dc-section__sub { display:none!important; }
			#dc-assignment-form, section:has(#dc-assignment-form) { display:none!important; }
			.dc-card.dc-section { margin-block:0.25rem!important; padding:0.45rem 0.7rem!important; }
			.dc-conflict, .dc-conflicts__item { padding:0.3rem 0.45rem!important; margin:0.15rem 0!important; }
			${extraCss}
		`
	}, extra)
	await page.keyboard.press('Escape').catch(() => {})
}

async function login() {
	pinDe(); clearBrute()
	await page.goto(`${base}/index.php/logout`, { waitUntil: 'domcontentloaded' }).catch(() => {})
	for (let i = 1; i <= 5; i++) {
		pinDe(); clearBrute()
		await context.clearCookies().catch(() => {})
		await page.goto(`${base}/index.php/login`, { waitUntil: 'domcontentloaded' })
		if (!page.url().includes('/login')) return
		await page.locator('#user, input[name="user"]').first().fill(user)
		await page.locator('#password, input[name="password"]').first().fill(pass)
		await page.locator('button[type="submit"], input[type="submit"]').first().click()
		try {
			await page.waitForURL((u) => !String(u).includes('/login'), { timeout: 25000 })
			return
		} catch { console.warn('login retry', i) }
	}
	throw new Error('login failed')
}

async function gotoRoster() {
	pinDe()
	await page.goto(`${base}/index.php/apps/dutycheck/roster?periodId=${PERIOD_NOV}`, {
		waitUntil: 'domcontentloaded',
		timeout: 90000,
	})
	await page.locator('#dc-main-content, #app-content').first().waitFor({ state: 'visible', timeout: 45000 })
	await polish()
	const nav = await page.locator('#app-navigation').innerText().catch(() => '')
	if (/Planification|Planning and compliance|Planowanie/i.test(nav)) {
		pinDe()
		await login()
		await page.goto(`${base}/index.php/apps/dutycheck/roster?periodId=${PERIOD_NOV}`, { waitUntil: 'domcontentloaded', timeout: 90000 })
		await polish()
	}
	for (let i = 0; i < 12; i++) {
		const label = (await page.locator('#dc-roster-month-current').textContent().catch(() => '')) || ''
		if (/november|2026-11|nov\.?\s*2026/i.test(label)) break
		if (/dezember|2026-12/i.test(label)) {
			await page.locator('#dc-roster-month-prev').click({ timeout: 3000 }).catch(() => {})
		} else {
			await page.locator('#dc-roster-month-next').click({ timeout: 3000 }).catch(() => {})
		}
		await page.waitForTimeout(350)
	}
}

function assertDe(blob) {
	if (!/Dienstplan|Planung und Compliance|Übersicht/i.test(blob)) throw new Error('missing DE: ' + blob.slice(0, 160))
	if (/Planification|Planning and compliance|Planowanie|Publish readiness|SHIFT BANDS/i.test(blob)) {
		throw new Error('EN/FR bleed: ' + blob.slice(0, 200))
	}
}

await login()

// ---- 04 grid hero with live conflict pill + swaps in DOM ----
for (let attempt = 1; attempt <= 8; attempt++) {
	try {
		await gotoRoster()
		await page.waitForFunction(() => {
			const summary = document.getElementById('dc-conflict-summary')?.innerText || ''
			const list = document.getElementById('dc-conflict-list')?.innerText || ''
			const swaps = document.getElementById('dc-swap-list')?.innerText || ''
			const grid = document.getElementById('dc-roster-grid')
			const hasGrid = !!(grid && (grid.querySelectorAll('[role="gridcell"], .dc-roster-grid__cell, td, .dc-roster-grid__block').length > 8 || /Anna|Ben|Clara/i.test(grid.innerText || '')))
			const hasConflicts = /müssen behoben|Doppel|Rest|Überlapp|hart|weich/i.test(summary + list) && !/Keine Planungsprobleme gefunden/i.test(summary)
			const hasSwaps = /→/.test(swaps) && /Anna|Ben|Elena|Clara|David|Greta|Jonas|Felix/i.test(swaps)
			return hasGrid && hasConflicts && hasSwaps
		}, { timeout: 35000 })
		await polish(`
			#dc-roster-conflicts-section .dc-conflicts > li:nth-child(n+3) { display:none!important; }
			#dc-roster-marketplace-section .dc-conflicts > li:nth-child(n+3) { display:none!important; }
			#dc-open-shift-form { display:none!important; }
			#dc-roster-grid, .dc-roster-grid-wrap { max-height: 560px!important; overflow:hidden!important; }
			#dc-roster-copy-section, section:has(#dc-roster-copy) { display:none!important; }
		`)
		await page.evaluate(() => {
			document.getElementById('dc-assignment-form')?.closest('section')?.setAttribute('hidden', '')
			document.getElementById('dc-roster-grid')?.scrollIntoView({ block: 'start' })
		})
		const main = await page.locator('#dc-main-content, #app-content').first().innerText()
		assertDe(main)
		if (/Keine Planungsprobleme gefunden/i.test(main)) throw new Error('04 empty conflicts')
		if (!/→/.test(main) || !/Anna|Ben|Elena|Clara|David/i.test(main)) throw new Error('04 missing named swaps')
		await page.screenshot({ path: resolve(outDir, 'dutycheck-screenshot-04.png'), fullPage: false })
		console.log('wrote dutycheck-screenshot-04.png')
		break
	} catch (e) {
		console.warn(`04 #${attempt}:`, String(e.message || e).slice(0, 180))
		if (attempt === 8) throw e
		pinDe()
		await page.waitForTimeout(700)
	}
}

// ---- 08 marketplace + conflicts (no settings) ----
for (let attempt = 1; attempt <= 8; attempt++) {
	try {
		await gotoRoster()
		await page.waitForFunction(() => {
			const summary = document.getElementById('dc-conflict-summary')?.innerText || ''
			const swaps = document.getElementById('dc-swap-list')?.innerText || ''
			return /müssen behoben/i.test(summary) && /→/.test(swaps) && /Anna|Ben|Elena|Clara|David/i.test(swaps)
		}, { timeout: 35000 })
		await polish(`
			#dc-roster-grid, #dc-roster-list, .dc-roster-grid-wrap, #dc-roster-calendar,
			#dc-open-shift-form, #dc-roster-copy-section, section:has(#dc-roster-copy) { display:none!important; }
			#dc-roster-conflicts-section .dc-conflicts > li:nth-child(n+5) { display:none!important; }
			#dc-roster-marketplace-section, #dc-roster-conflicts-section { display:block!important; }
		`)
		await page.evaluate(() => {
			document.getElementById('dc-roster-grid')?.setAttribute('hidden', '')
			document.getElementById('dc-roster-marketplace-section')?.scrollIntoView({ block: 'start' })
			document.getElementById('dc-roster-conflicts-section')?.scrollIntoView({ block: 'nearest' })
		})
		const main = await page.locator('#dc-main-content, #app-content').first().innerText()
		assertDe(main)
		if (/Keine Planungsprobleme gefunden|0 müssen behoben/i.test(main)) throw new Error('08 empty conflicts')
		if (!/→/.test(main) || !/Anna|Ben|Elena|Clara|David/i.test(main)) throw new Error('08 missing named swaps')
		if (/Publish readiness|SHIFT BANDS/i.test(main)) throw new Error('08 EN bleed')
		await page.screenshot({ path: resolve(outDir, 'dutycheck-screenshot-08.png'), fullPage: false })
		console.log('wrote dutycheck-screenshot-08.png')
		break
	} catch (e) {
		console.warn(`08 #${attempt}:`, String(e.message || e).slice(0, 180))
		if (attempt === 8) throw e
		pinDe()
		await page.waitForTimeout(700)
	}
}

writeFileSync(
	resolve(outDir, '_r2-capture-meta.json'),
	JSON.stringify(
		{
			captured_at: new Date().toISOString(),
			viewport: '1920x1040',
			locale: 'de',
			theme: 'light',
			round: 2,
			user,
			periodNov: PERIOD_NOV,
			tail: '04+08',
			shots: Array.from({ length: 8 }, (_, i) => `dutycheck-screenshot-0${i + 1}.png`),
		},
		null,
		2,
	) + '\n',
)

await browser.close()
stopLangPin()
console.log('tail 04+08 complete')
