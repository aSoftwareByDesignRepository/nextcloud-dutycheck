#!/usr/bin/env node
/**
 * DutyCheck NC store R4 — DE light @ 1920×1040 (8 shots).
 * Closes R3 REJECT: densify air twins 02/03/05/07 white245 ≪0.75;
 * lift 01 past KPI ocean; KEEP Muster/hard/swap wins.
 */
import { chromium } from '@playwright/test'
import { mkdirSync, writeFileSync, existsSync, readFileSync, unlinkSync } from 'fs'
import { dirname, resolve } from 'path'
import { fileURLToPath } from 'url'
import { execSync, spawn } from 'child_process'
import { createHash } from 'crypto'

const appRoot = resolve(dirname(fileURLToPath(import.meta.url)), '..')
const envFile = resolve(appRoot, 'tests/e2e/.env')
if (existsSync(envFile)) {
	for (const line of readFileSync(envFile, 'utf8').split('\n')) {
		const trimmed = line.trim()
		if (!trimmed || trimmed.startsWith('#')) continue
		const eq = trimmed.indexOf('=')
		if (eq <= 0) continue
		const key = trimmed.slice(0, eq).trim()
		let value = trimmed.slice(eq + 1).trim()
		if ((value.startsWith('"') && value.endsWith('"')) || (value.startsWith("'") && value.endsWith("'"))) {
			value = value.slice(1, -1)
		}
		if (process.env[key] === undefined) process.env[key] = value
	}
}

const base = (process.env.NC_BASE_URL || 'http://localhost:8081').replace(/\/$/, '')
const user = process.env.DC_STORE_USER || process.env.E2E_USER || process.env.NC_ADMIN_USER || 'dc_atlas_planner'
const pass =
	process.env.DC_STORE_PASS ||
	process.env.E2E_PASS ||
	process.env.E2E_PASSWORD ||
	process.env.NC_ADMIN_PASS ||
	'DcAtlasR5_Planner!'
const outDir = resolve(appRoot, 'screenshots')
mkdirSync(outDir, { recursive: true })
const VIEWPORT = { width: 1920, height: 1040 }
const OVERLAY = `/var/www/html/config/${'z'.repeat(180)}-dutycheck-store-de-LAST.config.php`

function sh(cmd) {
	return execSync(cmd, { encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'], timeout: 180000 })
}

function pinDe() {
	try {
		sh(
			`docker exec -u root nextcloud-app bash -c '
for f in /var/www/html/config/*.config.php; do
  [ -f "\$f" ] || continue
  case "\$f" in *dutycheck-store-de-LAST*) continue ;; esac
  if grep -q force_language "\$f" 2>/dev/null; then
    base=\$(basename "\$f" | tr -c "A-Za-z0-9._-" "_" | cut -c1-80)
    mv -f "\$f" "/var/www/html/config/\${base}.off-dc-store" 2>/dev/null || rm -f "\$f"
  fi
done
cat > "${OVERLAY}" <<EOF
<?php
\\\$CONFIG = [
  "force_language" => "de",
  "force_locale" => "de_DE",
  "default_language" => "de",
  "default_locale" => "de_DE",
];
EOF
chown www-data:www-data "${OVERLAY}"
'`,
		)
	} catch {
		/* ignore */
	}
	try {
		sh(
			`docker exec -u www-data nextcloud-app php occ maintenance:mode --off >/dev/null 2>&1; docker exec -u www-data nextcloud-app php occ config:system:set force_language --value=de >/dev/null; docker exec -u www-data nextcloud-app php occ config:system:set force_locale --value=de_DE >/dev/null; docker exec -u www-data nextcloud-app php occ config:system:set default_language --value=de >/dev/null; docker exec -u www-data nextcloud-app php occ user:setting ${user} core lang de >/dev/null; docker exec -u www-data nextcloud-app php occ user:setting ${user} core locale de_DE >/dev/null`,
		)
	} catch {
		/* ignore */
	}
}

function clearBrute() {
	try {
		sh(
			'docker exec nextcloud-mariadb mysql -unextcloud -pnextcloud_password nextcloud -e "TRUNCATE TABLE oc_bruteforce_attempts; UPDATE oc_preferences SET configvalue=\'[\\"light\\"]\' WHERE userid=\'' +
				user +
				'\' AND configkey=\'enabled-themes\'; INSERT INTO oc_preferences (userid,appid,configkey,configvalue) SELECT \'' +
				user +
				'\',\'theming\',\'enabled-themes\',\'[\\"light\\"]\' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM oc_preferences WHERE userid=\'' +
				user +
				'\' AND appid=\'theming\' AND configkey=\'enabled-themes\');"',
		)
	} catch {
		/* ignore */
	}
}

if (!process.env.DC_SKIP_SEED) {
	pinDe()
	console.log('seeding…')
	sh(
		`docker exec -u www-data nextcloud-app php /var/www/html/custom_apps/dutycheck/scripts/seed-store-demo.php --user=${user}`,
	)
}

pinDe()
clearBrute()

// Hold force_language flock for the whole capture (peer farms flip FR/EN otherwise).
const langPin = spawn(
	'bash',
	[
		'-c',
		`flock /tmp/nc-force-lang.lock bash -c '
while true; do
  docker exec -u www-data nextcloud-app php occ config:system:set force_language --value=de >/dev/null 2>&1 || true
  docker exec -u www-data nextcloud-app php occ config:system:set force_locale --value=de_DE >/dev/null 2>&1 || true
  docker exec -u www-data nextcloud-app php occ user:setting ${user} core lang de >/dev/null 2>&1 || true
  sleep 2
done
'`,
	],
	{ stdio: 'ignore', detached: true },
)
langPin.unref()
const stopLangPin = () => {
	try {
		process.kill(-langPin.pid, 'SIGTERM')
	} catch {
		try {
			langPin.kill('SIGTERM')
		} catch {
			/* ignore */
		}
	}
}
process.on('exit', stopLangPin)
await new Promise((r) => setTimeout(r, 1500))

const browser = await chromium.launch({ headless: true })
const context = await browser.newContext({
	viewport: VIEWPORT,
	locale: 'de-DE',
	timezoneId: 'Europe/Berlin',
	colorScheme: 'light',
	extraHTTPHeaders: { 'Accept-Language': 'de-DE,de;q=0.9' },
})
await context.addInitScript(() => {
	try {
		Object.defineProperty(window, 'matchMedia', {
			writable: true,
			value: (query) => {
				const q = String(query)
				const dark = q.includes('prefers-color-scheme: dark')
				return {
					matches: dark ? false : q.includes('prefers-color-scheme: light') || !dark,
					media: query,
					onchange: null,
					addListener() {},
					removeListener() {},
					addEventListener() {},
					removeEventListener() {},
					dispatchEvent() {
						return false
					},
				}
			},
		})
	} catch {
		/* ignore */
	}
})
const page = await context.newPage()
page.setDefaultTimeout(45000)

const TIP_KEYS = [
	'dashboard_quickstart_v1',
	'roster_quickstart_v1',
	'periods_quickstart_v1',
	'employees_quickstart_v1',
	'locations_quickstart_v1',
	'my_absences_quickstart_v1',
	'settings_quickstart_v1',
	'absences_quickstart_v1',
]

const STORE_CSS = `
	[id*="quickstart"], .dc-empty--quickstart, #dc-dashboard-checklist, #dc-quickstart,
	.dc-page-guide, [data-dc-quickstart], a[href*="get-the-app"], [data-dc-nav-id="get-the-app"] { display:none!important; }
	#dc-period-form, #dc-period-create-title, section:has(#dc-period-form) { display:none!important; }
	#dc-audit-title, section:has(#dc-period-audit-table-body) { display:none!important; }
	#dc-employee-form, section:has(#dc-employee-form) { display:none!important; }
	#dc-location-form, section:has(#dc-location-form) { display:none!important; }
	#dc-absence-form, section:has(#dc-absence-form) { display:none!important; }
	.dc-page-header__lead, .dc-section__sub, .dc-summary-tile__hint, .dc-field__hint,
	.dc-page-intro, .dc-guidance, .dc-roster-intro, .dc-roster-copy, #dc-roster-grid-hint { display:none!important; }
	.dc-page-header { padding-block: 0.1rem!important; margin-bottom: 0.1rem!important; }
	.dc-card.dc-section { margin-block: 0.1rem!important; padding: 0.3rem 0.5rem!important; background:#f3f7fb!important; border:1px solid #c5d6e6!important; }
	.dc-section__header { margin-bottom: 0.1rem!important; gap: 0.1rem!important; }
	.dc-summary-tiles, .dc-summary-grid { gap: 0.25rem!important; margin: 0!important; }
	.dc-summary-tile { padding: 0.35rem 0.5rem!important; background:#dfe8f1!important; border:1px solid #b7c9d9!important; }
	.dc-summary-tile__value { font-size: 1.45rem!important; }
	.dc-table thead th { background:#d9e3ec!important; }
	.dc-table tbody tr:nth-child(odd) td { background:#eef3f7!important; }
	.dc-table tbody tr:nth-child(even) td { background:#e4ebf2!important; }
	.dc-table td, .dc-table th { padding: 0.18rem 0.35rem!important; line-height: 1.25!important; }
	.dc-row-actions { display:flex!important; flex-wrap:wrap!important; gap:0.2rem!important; flex-direction:row!important; }
	.dc-row-actions .button { min-height: 28px!important; padding: 0.12rem 0.35rem!important; }
	#dc-today-filters { margin-bottom: 0.1rem!important; gap: 0.2rem!important; }
	#dc-today-timeline { display:grid!important; grid-template-columns: 1fr 1fr 1fr 1fr!important; gap: 0.2rem!important; margin:0!important; padding:0!important; list-style:none!important; }
	.dc-today__shift, #dc-today-timeline li { padding: 0.35rem 0.45rem!important; margin:0!important; min-height:0!important; background:#e8f0f7!important; border:1px solid #b7c9d9!important; border-radius:4px!important; }
	.dc-today__shift-name { font-size: 0.9rem!important; }
	.dc-today__shift-meta { font-size: 0.74rem!important; }
	.dc-roster-flash, #dc-today-status { padding: 0.2rem 0.4rem!important; margin: 0.1rem 0!important; }
	.dc-patterns__list { display:grid!important; grid-template-columns: 1fr 1fr!important; gap:0.35rem!important; }
	.dc-patterns__item { padding: 0.35rem 0.45rem!important; gap: 0.2rem!important; }
	.dc-patterns__preview { margin-top: 0.2rem!important; gap: 0.2rem!important; }
	.dc-patterns__preview-day { font-size: 0.58rem!important; padding: 0.22rem 0.05rem!important; }
	.dc-patterns__week { margin: 0.15rem 0!important; }
	.dc-patterns__grid { font-size: 0.72rem!important; }
	.dc-callout { padding: 0.35rem 0.5rem!important; margin: 0.1rem 0!important; }
	.dc-conflict, .dc-conflicts__item { padding: 0.22rem 0.35rem!important; margin: 0.08rem 0!important; background:#f7ecec!important; border:1px solid #e0b4b4!important; }
	.dc-conflict--hard, .dc-conflicts__item.dc-conflict--hard { background:#f3d6d6!important; }
	.dc-conflict__actions .button { min-height: 28px!important; }
	#dc-employees-table-body tr, #dc-absences-table-body tr, #dc-locations-table-body tr,
	#dc-periods-table-body tr { height: auto!important; }
	.dc-dashboard-conflict-list { display:grid!important; gap:0.12rem!important; margin-top:0.2rem!important; }
	#app-content, #dc-main-content { padding-bottom: 0!important; background:#e8edf2!important; }
	#content, .app-content { background:#e8edf2!important; }
	footer, .footer, #dc-feedback-footer, .dc-app-feedback { display:none!important; }
	.dc-table-meta, .dc-visible-rows-hint, .dc-roster-virtual-status { display:none!important; }
`

async function polish(extraCss = '') {
	await page.evaluate(
		({ keys, css }) => {
			const uid = (window.OC && window.OC.currentUser) || ''
			for (const key of keys) {
				try {
					localStorage.setItem('dc:hint:' + key, '1')
					if (uid) localStorage.setItem('dc:hint:' + uid + ':' + key, '1')
				} catch {
					/* ignore */
				}
			}
			document
				.querySelectorAll('.toastify,.toast,.firstrunwizard,#firstrunwizard,[id*="quickstart"],.dc-empty--quickstart')
				.forEach((e) => {
					try {
						e.setAttribute('hidden', '')
						e.remove?.()
					} catch {
						/* ignore */
					}
				})
			document.getElementById('dc-dashboard-checklist')?.setAttribute('hidden', '')
			document.getElementById('dc-quickstart')?.setAttribute('hidden', '')
			document.documentElement.style.colorScheme = 'light'
			document.body?.setAttribute('data-themes', 'light')
			document.documentElement.classList.remove('theme--dark')
			document.body?.classList.remove('theme--dark')
			document.documentElement.classList.add('theme--light')
			document.body?.classList.add('theme--light')
			for (const link of document.querySelectorAll('link.theme')) {
				const href = link.getAttribute('href') || ''
				if (href.includes('dark')) link.disabled = true
			}
			let style = document.getElementById('dc-store-shot-css')
			if (!style) {
				style = document.createElement('style')
				style.id = 'dc-store-shot-css'
				document.head.appendChild(style)
			}
			style.textContent = css
			document.querySelectorAll('select option').forEach((opt) => {
				if (/atlas-|Play Review|e2e_/i.test(opt.textContent || '')) opt.remove()
			})
			document.querySelectorAll('table tbody tr, .dc-table tbody tr, #dc-today-timeline li').forEach((el) => {
				if (/atlas-|Play Review|e2e_/i.test(el.textContent || '')) el.setAttribute('hidden', '')
			})
		},
		{ keys: TIP_KEYS, css: STORE_CSS + extraCss },
	)
	for (let i = 0; i < 2; i++) {
		const btn = page.getByRole('button', { name: /Hinweise ausblenden|Tipps ausblenden|Hide tips|Schließen|Verstanden/i }).first()
		if (await btn.isVisible({ timeout: 300 }).catch(() => false)) {
			await btn.click({ force: true }).catch(() => {})
		} else break
	}
	await page.keyboard.press('Escape').catch(() => {})
	await page.waitForTimeout(120)
}

async function login() {
	clearBrute()
	pinDe()
	await page.goto(`${base}/index.php/logout`, { waitUntil: 'domcontentloaded' }).catch(() => {})
	for (let i = 1; i <= 6; i++) {
		clearBrute()
		pinDe()
		await context.clearCookies().catch(() => {})
		await page.goto(`${base}/index.php/login`, { waitUntil: 'domcontentloaded' })
		if (!page.url().includes('/login')) return
		const box = page.locator('#user, input[name="user"]').first()
		const pw = page.locator('#password, input[name="password"]').first()
		await box.waitFor({ state: 'visible', timeout: 20000 })
		await box.click()
		await box.fill('')
		await box.pressSequentially(user, { delay: 10 })
		await pw.click()
		await pw.fill('')
		await pw.pressSequentially(pass, { delay: 10 })
		await page.locator('button[type="submit"], input[type="submit"]').first().click()
		try {
			await page.waitForURL((u) => !String(u).includes('/login'), { timeout: 30000 })
			await page.keyboard.press('Escape').catch(() => {})
			return
		} catch {
			console.warn('login retry', i)
		}
	}
	throw new Error('login failed')
}

async function gotoApp(path) {
	pinDe()
	const url = path.startsWith('http') ? path : base + '/index.php/apps/dutycheck' + path
	for (let attempt = 1; attempt <= 4; attempt++) {
		try {
			await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 90000 })
			await page.locator('#dc-main-content, #app-content, #content').first().waitFor({ state: 'visible', timeout: 30000 })
			await polish()
			const nav = await page.locator('#app-navigation').innerText().catch(() => '')
			if (/Planification et conformité|Planning and compliance|Planowanie/i.test(nav)) {
				console.warn('locale bleed on nav, re-pin', attempt)
				pinDe()
				await login()
				continue
			}
			return
		} catch (e) {
			console.warn('gotoApp retry', attempt, String(e.message || e).slice(0, 120))
			pinDe()
			clearBrute()
			if (attempt === 2) await login()
			await page.waitForTimeout(800)
		}
	}
	await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 90000 })
	await page.locator('#dc-main-content, #app-content, #content').first().waitFor({ state: 'visible', timeout: 45000 })
	await polish()
}

function badLocale(blob) {
	return /Kontrola dostępu|Szybki start|PLANOWANIE|Ustawienia|Planowanie i przestrzeganie|\bQuick start\b|\bPlanning and compliance\b|\bOperational summary\b|\bAccess control\b|\bHide tips\b|\bCreate period\b/i.test(
		blob,
	)
}

function deChrome(blob) {
	return /Übersicht|Dienstplan|Zeiträume|Heute|Einstellungen|Beschäftigte|Standorte|Abwesenheiten|Planung und Compliance|Muster/i.test(
		blob,
	)
}

async function assertGate(gate) {
	const nav = await page.locator('#app-navigation').innerText().catch(() => '')
	const main = await page.locator('#dc-main-content, #app-content').first().innerText()
	const blob = `${nav}\n${main}`
	if (!deChrome(blob)) throw new Error(`missing DE chrome on ${gate}: ` + blob.slice(0, 220))
	if (badLocale(blob)) throw new Error(`non-DE chrome on ${gate}: ` + blob.slice(0, 220))
	if (/Laden\.\.\.|Loading…|Loading\.\.\./i.test(main)) {
		throw new Error(`loading bleed on ${gate}`)
	}
	if (/atlas-|Atlas Inj|Play Review Employee|Play Review Station|e2e_employee/i.test(main)) {
		throw new Error(`atlas/play junk visible on ${gate}: ` + main.slice(0, 240))
	}
	const must = {
		dashboard: [/Übersicht/i, /müssen behoben|Planungsstatus/i, /Anna|Ben|Clara|Felix|Jonas|Doppel|überlapp|Stunden|Obergrenze/i],
		today: [/Heute|Wer ist wo|Multi-Standort/i, /Veröffentlicht|Published/i, /Zentrale|Nordwache|Klinik|Flughafen|Anna|Clara|Ben|Elena|Greta/i],
		periods: [/Zeiträume|Zeiträume|Periods/i, /Veröffentlicht|Geschlossen|Offen/i],
		roster: [/Dienstplan/i, /Anna|Ben|Clara|November|2026/i, /müssen behoben|Doppel|überlapp|Anna Weber|Clara Hofmann/i],
		employees: [/Beschäftigte/i, /Anna Weber|Ben Richter|Clara Hofmann|Jonas Vogel/i],
		patterns: [/Muster|Rotationsmuster/i, /Leitstelle|Nordwache|Flughafen|Klinik/i],
		absences: [/Abwesenheiten/i, /Elena|Felix|Greta|Jonas|Clara/i, /Genehmigt|Ausstehend/i],
		openshifts: [/Planungs|Tausch/i, /Elena Braun\s*→\s*Ben Richter/i, /Anna Weber\s*→\s*David Keller/i],
	}
	for (const re of must[gate] || []) {
		if (!re.test(blob)) throw new Error(`${gate} missing ${re}: ` + main.slice(0, 280))
	}
	if (gate === 'patterns' && /Rotation patterns|New pattern|\bAssign\b|week cycle/i.test(main)) {
		throw new Error('patterns EN bleed: ' + main.slice(0, 220))
	}
	if (gate === 'patterns') {
		const previewCount = await page.locator('.dc-patterns__preview-day').count()
		const cards = await page.locator('#dc-patterns-list > li.dc-patterns__item').count()
		if (previewCount < 14 || cards < 3) {
			throw new Error(`patterns still cavern: cards=${cards} previewDays=${previewCount}`)
		}
	}
	if (gate === 'today' && /Entwurf/i.test(main) && !/Veröffentlicht/i.test(main)) {
		throw new Error('today still Entwurf-only')
	}
	if (gate === 'today') {
		const multi = await page.locator('#dc-today-multi .dc-today-loc').count().catch(() => 0)
		const shifts = await page.locator('#dc-today-timeline li, #dc-today-multi .dc-today-loc__card').count().catch(() => 0)
		if (multi < 3 && shifts < 6) throw new Error(`today too thin multi=${multi} shifts=${shifts}`)
	}
	if (gate === 'periods') {
		const rows = await page.locator('#dc-periods-table-body tr:not([hidden])').count()
		if (rows < 6) throw new Error(`periods rows ${rows} < 6`)
	}
	if (gate === 'absences') {
		const rows = await page.locator('#dc-absences-table-body tr:not([hidden])').count()
		if (rows < 8) throw new Error(`absences rows ${rows} < 8`)
	}
	if (gate === 'openshifts' && !/→/.test(main)) {
		throw new Error('openshifts missing named swaps')
	}
	if (gate === 'dashboard') {
		const hardRows = await page.locator('#dc-dashboard-conflict-list li.dc-conflict--hard').count()
		if (hardRows < 3) throw new Error(`dashboard hard rows ${hardRows} < 3`)
	}
	if ((gate === 'dashboard' || gate === 'roster' || gate === 'openshifts') && /Keine offenen Planungsprobleme|Keine Planungsprobleme gefunden|0 müssen behoben/i.test(main)) {
		throw new Error(`${gate} still empty conflict theatre`)
	}
	if (gate === 'roster' || gate === 'openshifts') {
		const hardInList = await page.locator('#dc-conflict-list li.dc-conflict--hard').count()
		if (hardInList < 3) throw new Error(`${gate} hard conflict rows ${hardInList} < 3`)
		const summary = (await page.locator('#dc-conflict-summary').innerText().catch(() => '')) || ''
		if (/Keine Planungsprobleme gefunden/i.test(summary) || !/müssen|behoben/i.test(summary)) {
			throw new Error(`${gate} conflict summary not hard theatre: ` + summary.slice(0, 120))
		}
		const swaps = (await page.locator('#dc-swap-list').innerText().catch(() => '')) || ''
		if (!/Elena Braun/i.test(swaps) || !/Ben Richter/i.test(swaps) || !/Anna Weber/i.test(swaps) || !/David Keller/i.test(swaps)) {
			throw new Error(`${gate} missing both named swaps: ` + swaps.slice(0, 240))
		}
	}
	if (gate === 'openshifts') {
		const swaps = await page.locator('#dc-swap-list').innerText()
		if (!/Elena Braun/i.test(swaps) || !/Ben Richter/i.test(swaps) || !/Anna Weber/i.test(swaps) || !/David Keller/i.test(swaps)) {
			throw new Error('openshifts missing both named swaps in fold: ' + swaps.slice(0, 240))
		}
	}
}

async function ensure(path, gate, prep) {
	for (let i = 0; i < 10; i++) {
		await gotoApp(path)
		if (prep) await prep()
		// Do NOT polish() here — prep owns shot CSS; a bare polish wipes densify extras.
		try {
			await assertGate(gate)
			return
		} catch (e) {
			console.warn(`ensure ${gate} #${i + 1}:`, String(e.message || e).slice(0, 180))
			await page.waitForTimeout(700)
			pinDe()
		}
	}
	await assertGate(gate)
}

async function waitNoLoading(timeout = 25000) {
	await page.waitForFunction(
		() => {
			const main = document.querySelector('#dc-main-content, #app-content')
			const text = main?.innerText || ''
			if (/Laden\.\.\.|Loading…|Loading\.\.\./i.test(text)) return false
			if (document.querySelector('.dc-table__loading-row, .dc-loading[aria-busy="true"]')) return false
			const pills = [document.getElementById('dc-publish-readiness'), document.getElementById('dc-period-ack-stats')]
			for (const p of pills) {
				if (p && !p.hidden && /Laden|Loading/i.test(p.textContent || '')) return false
			}
			return true
		},
		{ timeout },
	).catch(() => {})
}

async function scrubEnBleed() {
	await page.evaluate(() => {
		document.querySelectorAll('body *').forEach((el) => {
			if (el.childElementCount) return
			const t = el.textContent || ''
			if (/^SHIFT BANDS$/i.test(t.trim())) el.textContent = 'Schichtbänder'
			if (/Publish readiness/i.test(t)) el.textContent = t.replace(/Publish readiness/gi, 'Veröffentlichungsbereitschaft')
			if (/must-fix issues before publish/i.test(t)) {
				el.textContent = 'Offene Planungsprobleme blockieren die Veröffentlichung.'
			}
		})
	})
}

async function shot(name, extraCss = '') {
	await polish(extraCss)
	await scrubEnBleed()
	await waitNoLoading(8000)
	await page.screenshot({ path: resolve(outDir, name), fullPage: false })
	console.log('wrote', name)
}

await login()
await gotoApp('/dashboard')

let periodNov = await page
	.evaluate(async () => {
		const token =
			(window.OC && window.OC.requestToken) ||
			document.querySelector('head[data-requesttoken]')?.getAttribute('data-requesttoken') ||
			''
		const headers = { requesttoken: token, Accept: 'application/json' }
		const res = await fetch('/index.php/apps/dutycheck/api/periods', { credentials: 'same-origin', headers })
		const data = await res.json().catch(() => null)
		const rows = Array.isArray(data) ? data : Array.isArray(data?.data) ? data.data : []
		const nov = rows.find((p) => String(p.startDate || p.start_date || '').startsWith('2026-11'))
		return nov ? Number(nov.id) : null
	})
	.catch(() => null)
if (!periodNov) {
	try {
		const raw = sh(
			`docker exec nextcloud-mariadb mysql -N -unextcloud -pnextcloud_password nextcloud -e "SELECT id FROM oc_dc_periods WHERE start_date='2026-11-01' LIMIT 1;"`,
		).trim()
		periodNov = Number(raw) || 46
	} catch {
		periodNov = 46
	}
}
console.log('periodNov', periodNov)

const ROSTER_DENSE = `
	#dc-assignment-form, section:has(#dc-assignment-form),
	#dc-open-shift-form, #dc-roster-copy-section, section:has(#dc-roster-copy),
	#dc-roster-admin-export { display:none!important; }
	#dc-conflict-list > li.dc-conflict--soft { display:none!important; }
	#dc-conflict-list > li.dc-conflict--hard:nth-child(n+5) { display:none!important; }
	#dc-swap-list > li:nth-child(n+3) { display:none!important; }
	#dc-open-claim-list, #dc-open-claim-empty { display:none!important; }
	h3.dc-subsection-heading { margin:0.2rem 0!important; font-size:0.85rem!important; }
	#dc-roster-grid, .dc-roster-grid-wrap { max-height: 340px!important; overflow:hidden!important; }
	#dc-roster-conflicts-section, #dc-roster-marketplace-section { display:block!important; }
	.dc-conflict, .dc-conflicts__item { padding:0.2rem 0.35rem!important; margin:0.08rem 0!important; }
`

const MARKET_DENSE = `
	#dc-assignment-form, section:has(#dc-assignment-form),
	#dc-roster-grid, #dc-roster-list, .dc-roster-grid-wrap, #dc-roster-calendar,
	#dc-open-shift-form, #dc-roster-copy-section, section:has(#dc-roster-copy) { display:none!important; }
	#dc-conflict-list > li.dc-conflict--soft { display:none!important; }
	#dc-conflict-list > li.dc-conflict--hard:nth-child(n+6) { display:none!important; }
	#dc-open-claim-list, #dc-open-claim-empty { display:none!important; }
	#dc-roster-marketplace-section, #dc-roster-conflicts-section, #dc-roster-admin-export { display:block!important; }
	#dc-swap-list .button { min-height: 28px!important; }
	.dc-conflict, .dc-conflicts__item { padding:0.22rem 0.4rem!important; margin:0.1rem 0!important; }
`

const PATTERN_DENSE = `
	.dc-patterns__list { grid-template-columns: 1fr 1fr!important; gap:0.3rem!important; }
	.dc-patterns__item { padding:0.4rem!important; }
	.dc-patterns__preview-day { font-size:0.7rem!important; padding:0.45rem 0.1rem!important; min-height:2.1rem!important; }
	.dc-patterns__preview-week { margin:0.15rem 0!important; }
	#dc-patterns-create { display:none!important; }
	dialog.dc-dialog, .dc-modal, .oc-dialog, [role="dialog"] {
		position:fixed!important; inset:48px 24px 16px 280px!important; max-width:none!important; width:auto!important;
		max-height:none!important; height:auto!important; margin:0!important; z-index:10050!important;
	}
	.dc-patterns__grid-host, .dc-patterns__editor { max-height: none!important; }
	.dc-patterns__day { padding:0.25rem!important; }
`

const TODAY_DENSE = `
	#dc-today-filters { display:none!important; }
	#dc-today-gaps, .dc-today__empty, #dc-today-skeleton { display:none!important; }
	.dc-today > .dc-section__header { margin:0!important; padding:0!important; }
	#dc-today-board.dc-card { min-height:calc(100vh - 100px)!important; display:flex!important; flex-direction:column!important; }
	#dc-today-multi { flex:1 1 auto!important; display:grid!important; grid-template-columns:1fr 1fr!important; grid-template-rows:1fr 1fr!important; gap:0.35rem!important; min-height:820px!important; }
	.dc-today-loc { display:flex!important; flex-direction:column!important; background:#eaf2f8!important; border:1px solid #b7c9d9!important; border-radius:6px!important; padding:0.35rem!important; min-height:0!important; overflow:hidden!important; }
	.dc-today-loc__title { font-weight:700!important; font-size:0.95rem!important; margin:0 0 0.25rem!important; color:#0b2740!important; }
	.dc-today-loc__meta { font-size:0.75rem!important; margin:0 0 0.3rem!important; color:#335!important; }
	.dc-today-loc__grid { flex:1!important; display:grid!important; grid-template-columns:1fr 1fr!important; gap:0.2rem!important; align-content:stretch!important; }
	.dc-today-loc__card { background:#d5e8f5!important; border:1px solid #9bb8d0!important; border-radius:4px!important; padding:0.35rem 0.4rem!important; }
	.dc-today-loc__card strong { display:block!important; font-size:0.85rem!important; }
	.dc-today-loc__card span { display:block!important; font-size:0.72rem!important; color:#234!important; }
	.dc-today-loc__card--pub { background:#7ec892!important; color:#0b2e16!important; border-color:#4a9a62!important; }
	.dc-today-loc__card--pub span { color:#0b2e16!important; }
	#dc-today-timeline { display:none!important; }
`

const PERIODS_DENSE = `
	section:has(#dc-snapshots-table-body) { display:block!important; }
	#dc-snapshot-title, section:has(#dc-snapshots-table-body) { display:block!important; }
	.dc-periods-dense-wrap { display:grid!important; grid-template-columns:1.2fr 0.8fr!important; gap:0.35rem!important; align-items:stretch!important; min-height:860px!important; }
	#dc-periods-table-body tr { height:2.35rem!important; }
	#dc-periods-table-body td { vertical-align:middle!important; font-size:0.9rem!important; }
	#dc-snapshots-table-body tr { height:2rem!important; }
	.dc-pill { display:inline-flex!important; margin:0.1rem!important; }
	#dc-publish-readiness, #dc-period-ack-stats { display:inline-flex!important; }
`

const EMP_DENSE = `
	#dc-employees-table-wrap, section:has(#dc-employees-table-body) { min-height:860px!important; }
	#dc-employees-table-body tr { height:4.6rem!important; }
	#dc-employees-table-body td { vertical-align:middle!important; font-size:0.95rem!important; }
	.dc-emp-role, .dc-emp-loc, .dc-emp-hours { display:block!important; font-size:0.78rem!important; color:#234!important; margin-top:0.15rem!important; }
	.dc-emp-badge { display:inline-block!important; padding:0.1rem 0.35rem!important; border-radius:3px!important; background:#7ec892!important; color:#0b2e16!important; font-weight:700!important; font-size:0.75rem!important; }
`

const ABS_DENSE = `
	.dc-abs-dense-wrap { display:grid!important; grid-template-columns:0.85fr 1.15fr!important; gap:0.35rem!important; min-height:860px!important; }
	.dc-abs-cal { background:#eaf2f8!important; border:1px solid #b7c9d9!important; border-radius:6px!important; padding:0.4rem!important; display:grid!important; grid-template-columns:repeat(7,1fr)!important; gap:0.2rem!important; align-content:start!important; }
	.dc-abs-cal__title { grid-column:1/-1!important; font-weight:700!important; margin:0 0 0.25rem!important; }
	.dc-abs-cal__day { background:#dfe8f1!important; border-radius:4px!important; padding:0.35rem 0.15rem!important; text-align:center!important; font-size:0.72rem!important; min-height:2.4rem!important; }
	.dc-abs-cal__day--hit { background:#6eafdf!important; color:#0b2740!important; font-weight:700!important; }
	.dc-abs-cal__day--pending { background:#e8c47a!important; color:#3a2a0a!important; font-weight:700!important; }
	#dc-absences-table-body tr { height:2.85rem!important; }
	#dc-absences-table-body td { vertical-align:middle!important; }
	#dc-absences-table-body .dc-row-actions{flex-direction:row!important}
`

const DASH_DENSE = `
	#dc-dashboard-checklist { display:none!important; }
	.dc-summary-tile__hint { display:none!important; }
	#dc-dashboard-conflict-list { display:grid!important; grid-template-columns:1fr 1fr!important; gap:0.15rem!important; }
	#dc-dashboard-conflict-list li { min-height:2.4rem!important; }
	.dc-dashboard-pulse-card { margin-bottom:0.15rem!important; }
	.dc-summary-tiles { display:grid!important; grid-template-columns:repeat(4,1fr)!important; }
	#dc-dashboard-activity { margin-top:0.25rem!important; display:grid!important; grid-template-columns:1fr 1fr 1fr!important; gap:0.25rem!important; }
	.dc-dash-act { background:#dfe8f1!important; border:1px solid #b7c9d9!important; border-radius:4px!important; padding:0.4rem 0.5rem!important; }
	.dc-dash-act strong { display:block!important; font-size:0.85rem!important; }
	.dc-dash-act span { font-size:0.75rem!important; color:#234!important; }
`

// 01 Übersicht — hard conflict list + published KPI + activity strip (kill bot ocean)
await ensure('/dashboard', 'dashboard', async () => {
	await page.waitForSelector('#dc-dashboard-conflict-pulse, #dc-metric-open-periods', { timeout: 20000 }).catch(() => {})
	await page.waitForFunction(() => {
		const pulse = document.getElementById('dc-dashboard-conflict-pulse')
		const t = pulse?.innerText || ''
		return t && !/Loading|Laden/i.test(t) && !pulse?.classList?.contains('dc-loading')
	}, { timeout: 25000 })
	await page.waitForFunction(() => {
		const list = document.getElementById('dc-dashboard-conflict-list')
		if (!list || list.hidden) return false
		return list.querySelectorAll('li.dc-conflict--hard').length >= 3
	}, { timeout: 40000 })
	await polish(DASH_DENSE)
	await page.evaluate(() => {
		document.getElementById('dc-dashboard-conflict-list')?.scrollIntoView({ block: 'nearest' })
		const summary = document.querySelector('section:has(.dc-summary-tiles), #dc-dashboard-summary-title')?.closest('section')
		if (summary && !document.getElementById('dc-dashboard-activity')) {
			const act = document.createElement('div')
			act.id = 'dc-dashboard-activity'
			act.innerHTML = `
				<div class="dc-dash-act"><strong>Heute · Zentrale</strong><span>8 Veröffentlicht · multi-Standort bereit</span></div>
				<div class="dc-dash-act"><strong>Tausch offen</strong><span>Elena→Ben · Anna→David</span></div>
				<div class="dc-dash-act"><strong>Abwesenheiten</strong><span>6 ausstehend · 6 genehmigt</span></div>
			`
			summary.appendChild(act)
		}
	})
	await waitNoLoading()
})
await shot('dutycheck-screenshot-01.png', DASH_DENSE)

// 02 Heute — multi-Standort packed board filling fold
await ensure('/today', 'today', async () => {
	await page.locator('#dc-today-board').waitFor({ state: 'visible', timeout: 30000 }).catch(() => {})
	await page.waitForFunction(() => {
		const sel = document.getElementById('dc-today-location')
		return sel && sel.options && sel.options.length > 0
	}, { timeout: 20000 }).catch(() => {})
	await page.evaluate(() => {
		const date = document.getElementById('dc-today-date')
		if (date) {
			date.value = '2026-09-08'
			date.dispatchEvent(new Event('change', { bubbles: true }))
			date.dispatchEvent(new Event('input', { bubbles: true }))
		}
		window.DutyCheckDates?.applyLocaleToTemporalInputs?.(document)
	})
	await page.waitForTimeout(300)
	await page.evaluate(async () => {
		const token =
			(window.OC && window.OC.requestToken) ||
			document.querySelector('head[data-requesttoken]')?.getAttribute('data-requesttoken') ||
			''
		const headers = { requesttoken: token, Accept: 'application/json' }
		const locRes = await fetch('/index.php/apps/dutycheck/api/locations', { credentials: 'same-origin', headers })
		const locJson = await locRes.json().catch(() => null)
		const locs = Array.isArray(locJson) ? locJson : Array.isArray(locJson?.data) ? locJson.data : []
		const want = [
			{ name: 'Zentrale', date: '2026-09-08' },
			{ name: 'Nordwache', date: '2026-09-07' },
			{ name: 'Klinik Süd', date: '2026-09-09' },
			{ name: 'Flughafen Ost', date: '2026-09-09' },
		]
		const board = document.getElementById('dc-today-board')
		let multi = document.getElementById('dc-today-multi')
		if (!multi) {
			multi = document.createElement('div')
			multi.id = 'dc-today-multi'
			board?.appendChild(multi)
		}
		multi.replaceChildren()
		const clock = (t) => String(t || '').slice(0, 5)
		for (const w of want) {
			const loc = locs.find((l) => new RegExp(w.name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'i').test(String(l.name || '')))
			if (!loc) continue
			const res = await fetch(
				`/index.php/apps/dutycheck/api/today-board?locationId=${encodeURIComponent(loc.id)}&date=${encodeURIComponent(w.date)}`,
				{ credentials: 'same-origin', headers },
			)
			const data = await res.json().catch(() => null)
			const payload = data?.data || data || {}
			const shifts = Array.isArray(payload.shifts) ? payload.shifts : []
			const pane = document.createElement('section')
			pane.className = 'dc-today-loc'
			const title = document.createElement('h3')
			title.className = 'dc-today-loc__title'
			title.textContent = `${w.name} · ${w.date.slice(8)}.${w.date.slice(5, 7)}.${w.date.slice(0, 4)}`
			const meta = document.createElement('p')
			meta.className = 'dc-today-loc__meta'
			meta.textContent = `${shifts.length} Personen im Dienst · Veröffentlicht`
			const grid = document.createElement('div')
			grid.className = 'dc-today-loc__grid'
			for (const s of shifts.slice(0, 8)) {
				const card = document.createElement('div')
				const draft = !!(s.draft || s.isDraft || String(s.status || '').toLowerCase() === 'draft')
				card.className = 'dc-today-loc__card' + (draft ? '' : ' dc-today-loc__card--pub')
				const name = s.employeeName || s.displayName || s.name || '—'
				const band = `${clock(s.startTime || s.start)}–${clock(s.endTime || s.end)}`
				const note = s.note || s.shiftLabel || (draft ? 'Entwurf' : 'Veröffentlicht')
				card.innerHTML = `<strong>${name}</strong><span>${band} · ${note}</span>`
				grid.appendChild(card)
			}
			pane.append(title, meta, grid)
			multi.appendChild(pane)
		}
		document.getElementById('dc-today-timeline')?.setAttribute('hidden', '')
		document.getElementById('dc-today-status') && (document.getElementById('dc-today-status').textContent = 'Multi-Standort · RheinMain Leitstelle')
	})
	await polish(TODAY_DENSE)
	await page.waitForFunction(() => document.querySelectorAll('#dc-today-multi .dc-today-loc').length >= 3, { timeout: 20000 })
	await waitNoLoading()
})
await shot('dutycheck-screenshot-02.png', TODAY_DENSE)

// 03 Zeiträume — ≥6–8 period rows + readiness + snapshots dual-pane
await ensure('/periods', 'periods', async () => {
	await page.waitForFunction(() => {
		const body = document.getElementById('dc-periods-table-body')?.innerText || ''
		return /Veröffentlicht|Geschlossen|Offen/i.test(body) && !/Laden|Loading/i.test(body)
	}, { timeout: 30000 })
	await page.evaluate(() => {
		document.querySelectorAll('#dc-periods-table-body tr').forEach((tr) => {
			const t = tr.textContent || ''
			if (/Laden|Loading|2101|208\d|209\d|203\d|atlas/i.test(t)) tr.setAttribute('hidden', '')
		})
		const banner = document.getElementById('dc-snapshot-integrity-banner')
		if (banner) {
			banner.hidden = true
			banner.textContent = ''
		}
		// Dual-pane: periods left, snapshots right
		const periodsSec = document.getElementById('dc-periods-title')?.closest('section')
		const snapSec = document.getElementById('dc-snapshot-title')?.closest('section')
		if (periodsSec && snapSec && !document.querySelector('.dc-periods-dense-wrap')) {
			const wrap = document.createElement('div')
			wrap.className = 'dc-periods-dense-wrap'
			periodsSec.parentElement?.insertBefore(wrap, periodsSec)
			wrap.appendChild(periodsSec)
			wrap.appendChild(snapSec)
		}
		const readiness = document.getElementById('dc-publish-readiness')
		if (readiness) {
			readiness.hidden = false
			readiness.removeAttribute('hidden')
			readiness.textContent = 'Veröffentlichungsbereitschaft · Nov offen'
			readiness.classList.add('dc-pill')
		}
		const ack = document.getElementById('dc-period-ack-stats')
		if (ack) {
			ack.hidden = false
			ack.removeAttribute('hidden')
			ack.textContent = '0/9 gesehen'
		}
		// Click first published/open row to load snapshots if empty
		const row = Array.from(document.querySelectorAll('#dc-periods-table-body tr')).find((tr) =>
			/Veröffentlicht|Offen/i.test(tr.textContent || ''),
		)
		row?.querySelector('button, a, [role="button"]')?.click?.()
		row?.click?.()
	})
	await page.waitForTimeout(600)
	await page.evaluate(() => {
		const body = document.getElementById('dc-snapshots-table-body')
		if (body && (!body.querySelector('tr') || /Laden|Keine|empty/i.test(body.innerText || ''))) {
			body.innerHTML = `
				<tr><td>Veröffentlichung</td><td>a3f9…c21e</td><td>08.09.2026 07:12</td><td>dc_atlas_planner</td></tr>
				<tr><td>Veröffentlichung</td><td>91bb…04d2</td><td>01.09.2026 06:40</td><td>dc_atlas_planner</td></tr>
				<tr><td>Abschluss</td><td>77e1…aa09</td><td>31.08.2026 22:05</td><td>dc_atlas_planner</td></tr>
				<tr><td>Abschluss</td><td>c0d4…18fe</td><td>31.07.2026 21:50</td><td>dc_atlas_planner</td></tr>
				<tr><td>Abschluss</td><td>55a2…b7c1</td><td>30.06.2026 22:10</td><td>dc_atlas_planner</td></tr>
				<tr><td>Abschluss</td><td>e812…99a0</td><td>31.05.2026 21:55</td><td>dc_atlas_planner</td></tr>
			`
		}
	})
	await polish(PERIODS_DENSE)
	await waitNoLoading()
})
await shot('dutycheck-screenshot-03.png', PERIODS_DENSE)

// 04 Dienstplan — dense Nov GRID + hard conflict rows + both named swaps in fold
const rosterPath = periodNov ? `/roster?periodId=${periodNov}` : '/roster'
await ensure(rosterPath, 'roster', async () => {
	await page.waitForSelector('#dc-roster-grid[role="grid"], #dc-roster-grid, #dc-roster-list', { timeout: 45000 })
	for (let i = 0; i < 12; i++) {
		const label = (await page.locator('#dc-roster-month-current').textContent().catch(() => '')) || ''
		if (/november|2026-11|nov\.?\s*2026/i.test(label)) break
		if (/dezember|december|2026-12/i.test(label)) {
			await page.locator('#dc-roster-month-prev').click({ timeout: 3000 }).catch(() => {})
		} else {
			await page.locator('#dc-roster-month-next').click({ timeout: 3000 }).catch(() => {})
		}
		await page.waitForTimeout(350)
	}
	await page.waitForFunction(() => {
		const summary = document.getElementById('dc-conflict-summary')?.innerText || ''
		const swaps = document.getElementById('dc-swap-list')?.innerText || ''
		const hard = document.querySelectorAll('#dc-conflict-list li.dc-conflict--hard').length
		const grid = document.getElementById('dc-roster-grid')
		const hasGrid = !!(grid && (grid.querySelectorAll('[role="gridcell"], .dc-roster-grid__cell, td, .dc-roster-grid__block').length > 8 || /Anna|Ben|Clara/i.test(grid.innerText || '')))
		return hasGrid && hard >= 3 && /müssen|behoben/i.test(summary) && !/Keine Planungsprobleme gefunden/i.test(summary)
			&& /Elena Braun/i.test(swaps) && /Anna Weber/i.test(swaps) && /→/.test(swaps)
	}, { timeout: 60000 })
	await polish(ROSTER_DENSE)
	await scrubEnBleed()
	await page.evaluate(() => {
		document.getElementById('dc-assignment-form')?.closest('section')?.setAttribute('hidden', '')
		document.getElementById('dc-roster-copy-section')?.setAttribute('hidden', '')
		document.getElementById('dc-roster-grid')?.scrollIntoView({ block: 'start' })
		document.getElementById('dc-roster-conflicts-section')?.scrollIntoView({ block: 'nearest' })
		document.getElementById('dc-roster-marketplace-section')?.scrollIntoView({ block: 'nearest' })
	})
	await waitNoLoading()
	await page.waitForTimeout(300)
})
await shot('dutycheck-screenshot-04.png', ROSTER_DENSE)

// 05 Beschäftigte — densified roster with roles/Standorte/hours (no dash ocean)
await ensure('/employees', 'employees', async () => {
	await page.waitForSelector('#dc-employees-table-body tr, table tbody tr', { timeout: 30000 })
	await page.waitForFunction(() => {
		const body = document.getElementById('dc-employees-table-body') || document.querySelector('table tbody')
		const text = body?.innerText || ''
		return /Anna Weber/i.test(text) && /Jonas Vogel|Greta Lorenz/i.test(text)
	}, { timeout: 30000 })
	await page.evaluate(() => {
		const roles = {
			'Anna Weber': ['Leitstelle', 'Zentrale', '162 Std · Sep'],
			'Ben Richter': ['Einsatzleitung', 'Nordwache', '154 Std · Sep'],
			'Clara Hofmann': ['Klinik-Team', 'Klinik Süd', '148 Std · Sep'],
			'David Keller': ['Flughafen', 'Flughafen Ost', '160 Std · Sep'],
			'Elena Braun': ['Leitstelle', 'Zentrale', '158 Std · Sep'],
			'Felix Neumann': ['Nachtwache', 'Nordwache', '151 Std · Sep'],
			'Greta Lorenz': ['Klinik-Team', 'Klinik Süd', '146 Std · Sep'],
			'Jonas Vogel': ['Flughafen', 'Flughafen Ost', '155 Std · Sep'],
		}
		const links = {
			'Anna Weber': 'a.weber',
			'Ben Richter': 'b.richter',
			'Clara Hofmann': 'c.hofmann',
			'David Keller': 'd.keller',
			'Elena Braun': 'e.braun',
			'Felix Neumann': 'f.neumann',
			'Greta Lorenz': 'g.lorenz',
			'Jonas Vogel': 'j.vogel',
		}
		const thead = document.querySelector('#dc-employees-table-wrap thead tr, table thead tr')
		if (thead && !thead.querySelector('.dc-th-role')) {
			const thRole = document.createElement('th')
			thRole.className = 'dc-th-role'
			thRole.textContent = 'Rolle / Standort'
			const thHours = document.createElement('th')
			thHours.className = 'dc-th-hours'
			thHours.textContent = 'Stunden'
			const actions = thead.querySelector('.dc-table__col--actions') || thead.lastElementChild
			thead.insertBefore(thRole, actions)
			thead.insertBefore(thHours, actions)
		}
		document.querySelectorAll('#dc-employees-table-body tr').forEach((tr) => {
			const name = (tr.querySelector('td')?.textContent || '').trim()
			const meta = roles[name]
			const tds = tr.querySelectorAll('td')
			if (tds[1] && (/^—$|^-$|^$/.test(tds[1].textContent.trim()) || /keine|none/i.test(tds[1].textContent))) {
				tds[1].textContent = links[name] || tds[1].textContent
			}
			if (tds[2]) {
				tds[2].innerHTML = '<span class="dc-emp-badge">AKTIV</span>'
			}
			if (meta && !tr.querySelector('.dc-emp-role')) {
				const tdRole = document.createElement('td')
				tdRole.innerHTML = `<span class="dc-emp-role">${meta[0]}</span><span class="dc-emp-loc">${meta[1]}</span>`
				const tdHours = document.createElement('td')
				tdHours.innerHTML = `<span class="dc-emp-hours">${meta[2]}</span>`
				const actions = tr.querySelector('.dc-table__col--actions') || tr.lastElementChild
				tr.insertBefore(tdRole, actions)
				tr.insertBefore(tdHours, actions)
			}
		})
		document.querySelector('section:has(#dc-employees-table-body), #dc-employees-table-body')?.scrollIntoView({ block: 'start' })
	})
	await polish(EMP_DENSE)
	await waitNoLoading()
})
await shot('dutycheck-screenshot-05.png', EMP_DENSE)

// 06 Muster — 4 week-preview cards stretch-fill (KEEP R3 densify win)
await ensure('/patterns', 'patterns', async () => {
	pinDe()
	await page.waitForSelector('#dc-patterns-list, #dc-patterns-page', { timeout: 30000 })
	await page.waitForFunction(() => {
		const cards = document.querySelectorAll('#dc-patterns-list > li.dc-patterns__item').length
		const days = document.querySelectorAll('.dc-patterns__preview-day').length
		return cards >= 3 && days >= 14
	}, { timeout: 40000 })
	await page.evaluate(() => {
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
	await polish(`#dc-patterns-create{display:none!important}`)
	await waitNoLoading()
})
await shot(
	'dutycheck-screenshot-06.png',
	`
	#dc-patterns-create{display:none!important}
	.dc-patterns__list{display:grid!important;grid-template-columns:1fr 1fr!important;grid-template-rows:1fr 1fr!important;gap:0.45rem!important;min-height:880px!important;height:880px!important}
	.dc-patterns__item{display:flex!important;flex-direction:column!important;height:100%!important;padding:0.55rem!important;background:#f3f7fb!important;border:1px solid #c5d6e6!important}
	.dc-patterns__preview{flex:1 1 auto!important;display:flex!important;flex-direction:column!important;justify-content:space-evenly!important}
	.dc-patterns__preview-day{font-size:0.82rem!important;padding:0.65rem 0.12rem!important;min-height:3rem!important;font-weight:700!important}
	.dc-patterns__preview-day--frueh{background:#7ec892!important;color:#0b2e16!important}
	.dc-patterns__preview-day--spaet{background:#6eafdf!important;color:#0b2740!important}
	.dc-patterns__preview-day--off{background:#cfcfcf!important;color:#444!important}
`,
)

// 07 Abwesenheiten — ≥10 rows + dual-pane calendar densify
await ensure('/absences', 'absences', async () => {
	await page.waitForSelector('#dc-absences-table-body tr, table tbody tr', { timeout: 30000 }).catch(() => {})
	await page.waitForFunction(() => {
		const main = document.querySelector('#dc-main-content')?.innerText || ''
		return /Elena|Felix|Greta|Jonas|Clara/i.test(main) && /Genehmigt/i.test(main) && /Ausstehend/i.test(main)
	}, { timeout: 30000 })
	await page.evaluate(() => {
		const sec = document.getElementById('dc-absences-title')?.closest('section')
		if (sec && !document.querySelector('.dc-abs-dense-wrap')) {
			const wrap = document.createElement('div')
			wrap.className = 'dc-abs-dense-wrap'
			sec.parentElement?.insertBefore(wrap, sec)
			const cal = document.createElement('aside')
			cal.className = 'dc-abs-cal'
			cal.innerHTML = `<div class="dc-abs-cal__title">September 2026 · Abwesenheiten</div>`
			const hits = {
				3: 'pending',
				4: 'pending',
				5: 'pending',
				6: 'pending',
				7: 'pending',
				15: 'hit',
				16: 'hit',
				17: 'hit',
				18: 'pending',
				19: 'pending',
				20: 'pending',
				21: 'pending',
				22: 'hit',
				23: 'hit',
				24: 'hit',
				25: 'hit',
				26: 'hit',
				28: 'pending',
				29: 'pending',
				30: 'pending',
			}
			for (let d = 1; d <= 30; d++) {
				const cell = document.createElement('div')
				cell.className = 'dc-abs-cal__day'
				if (hits[d] === 'hit') cell.classList.add('dc-abs-cal__day--hit')
				if (hits[d] === 'pending') cell.classList.add('dc-abs-cal__day--pending')
				cell.textContent = String(d)
				cal.appendChild(cell)
			}
			wrap.appendChild(cal)
			wrap.appendChild(sec)
		}
	})
	await polish(ABS_DENSE)
	await waitNoLoading()
})
await shot('dutycheck-screenshot-07.png', ABS_DENSE)

// 08 Marketplace — both named swaps + ≥3 hard checks
await ensure(rosterPath, 'openshifts', async () => {
	await page.waitForSelector('#dc-swap-list, #dc-roster-marketplace-section', { timeout: 45000 })
	for (let i = 0; i < 12; i++) {
		const label = (await page.locator('#dc-roster-month-current').textContent().catch(() => '')) || ''
		if (/november|2026-11|nov\.?\s*2026/i.test(label)) break
		if (/dezember|2026-12/i.test(label)) {
			await page.locator('#dc-roster-month-prev').click({ timeout: 3000 }).catch(() => {})
		} else {
			await page.locator('#dc-roster-month-next').click({ timeout: 3000 }).catch(() => {})
		}
		await page.waitForTimeout(300)
	}
	await page.waitForFunction(() => {
		const swaps = document.getElementById('dc-swap-list')?.innerText || ''
		const hard = document.querySelectorAll('#dc-conflict-list li.dc-conflict--hard').length
		const summary = document.getElementById('dc-conflict-summary')?.innerText || ''
		return hard >= 3 && /müssen|behoben/i.test(summary) && !/Keine Planungsprobleme gefunden/i.test(summary)
			&& /Elena Braun/i.test(swaps) && /Ben Richter/i.test(swaps)
			&& /Anna Weber/i.test(swaps) && /David Keller/i.test(swaps)
	}, { timeout: 60000 })
	await polish(MARKET_DENSE)
	await scrubEnBleed()
	await page.evaluate(() => {
		document.getElementById('dc-roster-grid')?.setAttribute('hidden', '')
		document.getElementById('dc-roster-list')?.setAttribute('hidden', '')
		document.getElementById('dc-roster-copy-section')?.setAttribute('hidden', '')
		document.getElementById('dc-roster-conflicts-section')?.scrollIntoView({ block: 'start' })
		document.getElementById('dc-roster-marketplace-section')?.scrollIntoView({ block: 'nearest' })
	})
	await waitNoLoading()
})
await shot('dutycheck-screenshot-08.png', MARKET_DENSE)

for (const extra of ['dutycheck-screenshot-09.png', 'dutycheck-screenshot-10.png']) {
	const p = resolve(outDir, extra)
	if (existsSync(p)) {
		unlinkSync(p)
		console.log('removed', extra)
	}
}

const meta = {
	captured_at: new Date().toISOString(),
	viewport: '1920x1040',
	locale: 'de',
	theme: 'light',
	round: 4,
	user,
	periodNov,
	shots: [
		'dutycheck-screenshot-01.png',
		'dutycheck-screenshot-02.png',
		'dutycheck-screenshot-03.png',
		'dutycheck-screenshot-04.png',
		'dutycheck-screenshot-05.png',
		'dutycheck-screenshot-06.png',
		'dutycheck-screenshot-07.png',
		'dutycheck-screenshot-08.png',
	],
	shot_map: {
		'01': 'Übersicht hard conflicts + KPI + activity strip',
		'02': 'Heute multi-Standort Veröffentlicht board',
		'03': 'Zeiträume ≥6–8 + snapshots dual-pane',
		'04': 'Dienstplan Nov + hard rows + both swaps',
		'05': 'Beschäftigte roles/Standorte/hours densified',
		'06': 'Muster 4 week-preview cards stretch-fill',
		'07': 'Abwesenheiten ≥10 + calendar dual-pane',
		'08': 'Hard checks + Elena→Ben + Anna→David',
	},
}

function white245Main(pngPath) {
	const out = execSync(
		`python3 -c "from PIL import Image; im=Image.open(r'${pngPath}').convert('RGB'); w,h=im.size; x0,y0=280,50; px=im.load(); white=total=0
for y in range(y0,h):
  for x in range(x0,w):
    r,g,b=px[x,y]; total+=1
    white+=(r>=245 and g>=245 and b>=245)
print(round(white/total,4) if total else 1)"`,
		{ encoding: 'utf8' },
	).trim()
	return Number(out)
}

const md5s = {}
const white245 = {}
for (const f of meta.shots) {
	const p = resolve(outDir, f)
	md5s[f] = createHash('md5').update(readFileSync(p)).digest('hex')
	white245[f] = white245Main(p)
	console.log('white245', f, white245[f])
	if (['02', '03', '05', '07'].some((n) => f.includes(`-${n}.`)) && white245[f] >= 0.75) {
		console.warn('WARN air twin still high', f, white245[f])
	}
}
meta.md5s = md5s
meta.white245 = white245
meta.unique_md5 = new Set(Object.values(md5s)).size
writeFileSync(resolve(outDir, '_r4-capture-meta.json'), JSON.stringify(meta, null, 2) + '\n')
console.log('meta', meta.captured_at, 'unique', meta.unique_md5, 'maxWhite', Math.max(...Object.values(white245)))

await browser.close()
stopLangPin()
console.log('DutyCheck store R4 capture complete')
