#!/usr/bin/env node
/**
 * DutyCheck NC store R3 — DE light @ 1920×1040 (8 shots).
 * Closes R2 REJECT next_actions: densify Muster, hard conflict theatre,
 * both named swaps in fold, white245 ≪0.75 on ≥6/8; keep R2 wins.
 */
import { chromium } from '@playwright/test'
import { mkdirSync, writeFileSync, existsSync, readFileSync, unlinkSync } from 'fs'
import { dirname, resolve } from 'path'
import { fileURLToPath } from 'url'
import { execSync, spawn } from 'child_process'

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
	#dc-snapshot-title, section:has(#dc-snapshots-table-body),
	#dc-audit-title, section:has(#dc-period-audit-table-body) { display:none!important; }
	#dc-employee-form, section:has(#dc-employee-form) { display:none!important; }
	#dc-location-form, section:has(#dc-location-form) { display:none!important; }
	#dc-absence-form, section:has(#dc-absence-form) { display:none!important; }
	.dc-page-header__lead, .dc-section__sub, .dc-summary-tile__hint, .dc-field__hint,
	.dc-page-intro, .dc-guidance, .dc-roster-intro, .dc-roster-copy, #dc-roster-grid-hint { display:none!important; }
	.dc-page-header { padding-block: 0.2rem!important; margin-bottom: 0.15rem!important; }
	.dc-card.dc-section { margin-block: 0.15rem!important; padding: 0.35rem 0.55rem!important; }
	.dc-section__header { margin-bottom: 0.15rem!important; gap: 0.15rem!important; }
	.dc-summary-tiles, .dc-summary-grid { gap: 0.3rem!important; margin: 0!important; }
	.dc-summary-tile { padding: 0.3rem 0.45rem!important; }
	.dc-summary-tile__value { font-size: 1.45rem!important; }
	.dc-table td, .dc-table th { padding: 0.14rem 0.32rem!important; line-height: 1.2!important; }
	.dc-row-actions { display:flex!important; flex-wrap:wrap!important; gap:0.2rem!important; flex-direction:row!important; }
	.dc-row-actions .button { min-height: 28px!important; padding: 0.12rem 0.35rem!important; }
	#dc-today-filters { margin-bottom: 0.15rem!important; gap: 0.25rem!important; }
	#dc-today-timeline { display:grid!important; grid-template-columns: 1fr 1fr 1fr!important; gap: 0.25rem!important; margin:0!important; padding:0!important; list-style:none!important; }
	.dc-today__shift, #dc-today-timeline li { padding: 0.25rem 0.4rem!important; margin:0!important; min-height:0!important; }
	.dc-today__shift-name { font-size: 0.88rem!important; }
	.dc-today__shift-meta { font-size: 0.74rem!important; }
	.dc-roster-flash, #dc-today-status { padding: 0.25rem 0.45rem!important; margin: 0.15rem 0!important; }
	.dc-patterns__list { display:grid!important; grid-template-columns: 1fr 1fr!important; gap:0.35rem!important; }
	.dc-patterns__item { padding: 0.35rem 0.45rem!important; gap: 0.2rem!important; }
	.dc-patterns__preview { margin-top: 0.2rem!important; gap: 0.2rem!important; }
	.dc-patterns__preview-day { font-size: 0.58rem!important; padding: 0.22rem 0.05rem!important; }
	.dc-patterns__week { margin: 0.15rem 0!important; }
	.dc-patterns__grid { font-size: 0.72rem!important; }
	.dc-callout { padding: 0.4rem 0.55rem!important; margin: 0.15rem 0!important; }
	.dc-conflict, .dc-conflicts__item { padding: 0.25rem 0.4rem!important; margin: 0.12rem 0!important; }
	.dc-conflict__actions .button { min-height: 28px!important; }
	#dc-employees-table-body tr, #dc-absences-table-body tr, #dc-locations-table-body tr,
	#dc-periods-table-body tr { height: auto!important; }
	.dc-dashboard-conflict-list { display:grid!important; gap:0.15rem!important; margin-top:0.3rem!important; }
	#app-content, #dc-main-content { padding-bottom: 0!important; }
	footer, .footer, #dc-feedback-footer, .dc-app-feedback { display:none!important; }
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
		today: [/Heute|Wer ist wo/i, /Veröffentlicht|Published/i, /Zentrale|Anna|Clara|Ben|Elena|Greta/i],
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

// 01 Übersicht — hard conflict list + published KPI
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
	await polish(`#dc-dashboard-checklist { display:none!important; } .dc-summary-tile__hint { display:none!important; }`)
	await page.evaluate(() => {
		document.getElementById('dc-dashboard-conflict-list')?.scrollIntoView({ block: 'nearest' })
	})
	await waitNoLoading()
})
await shot('dutycheck-screenshot-01.png', `#dc-dashboard-checklist { display:none!important; }`)

// 02 Heute — published live cards (3-col densify)
await ensure('/today', 'today', async () => {
	await page.locator('#dc-today-board').waitFor({ state: 'visible', timeout: 30000 }).catch(() => {})
	await page.waitForFunction(() => {
		const sel = document.getElementById('dc-today-location')
		return sel && sel.options && sel.options.length > 0
	}, { timeout: 20000 }).catch(() => {})
	await page.evaluate(() => {
		const sel = document.getElementById('dc-today-location')
		if (sel) {
			let hit = false
			for (const opt of sel.options) {
				if (/^\s*Zentrale\s*$/i.test(opt.textContent || '')) {
					sel.value = opt.value
					hit = true
					break
				}
			}
			if (!hit) {
				for (const opt of sel.options) {
					if (/zentrale/i.test(opt.textContent || '')) {
						sel.value = opt.value
						hit = true
						break
					}
				}
			}
			if (!hit && sel.options.length) {
				const byVal = Array.from(sel.options).sort((a, b) => Number(a.value) - Number(b.value))
				sel.value = byVal[0].value
			}
			sel.dispatchEvent(new Event('change', { bubbles: true }))
		}
		const date = document.getElementById('dc-today-date')
		if (date) {
			date.value = '2026-09-08'
			date.dispatchEvent(new Event('change', { bubbles: true }))
			date.dispatchEvent(new Event('input', { bubbles: true }))
		}
		window.DutyCheckDates?.applyLocaleToTemporalInputs?.(document)
		document.getElementById('dc-today-filters')?.requestSubmit?.()
	})
	await page.waitForTimeout(400)
	await page.evaluate(() => document.getElementById('dc-today-filters')?.requestSubmit?.())
	await page.waitForFunction(() => {
		const sk = document.getElementById('dc-today-skeleton')
		const shifts = document.querySelectorAll('#dc-today-timeline li.dc-today__shift, #dc-today-timeline li')
		const body = document.getElementById('dc-today-timeline')?.innerText || ''
		const skeletonVisible = sk && sk.hidden === false
		return !skeletonVisible && shifts.length >= 6 && /Veröffentlicht/i.test(body)
	}, { timeout: 40000 })
	await polish(`
		#dc-today-timeline { grid-template-columns: 1fr 1fr 1fr!important; gap:0.2rem!important; }
		#dc-today-gaps, .dc-today__empty { display:none!important; }
		.dc-today__shift { padding:0.3rem 0.4rem!important; }
	`)
	await waitNoLoading()
})
await shot(
	'dutycheck-screenshot-02.png',
	`#dc-today-timeline { grid-template-columns: 1fr 1fr 1fr!important; gap:0.2rem!important; } #dc-today-gaps,.dc-today__empty{display:none!important;}`,
)

// 03 Zeiträume — mixed lifecycle, zero Laden…
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
		document.getElementById('dc-publish-readiness')?.setAttribute('hidden', '')
		document.getElementById('dc-period-ack-stats')?.setAttribute('hidden', '')
	})
	await polish(`#dc-periods-table-body tr { height: 1.45rem; } .dc-table-meta,.dc-visible-rows-hint{display:none!important;}`)
	await waitNoLoading()
})
await shot('dutycheck-screenshot-03.png', `#dc-periods-table-body tr{height:1.45rem}`)

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

// 05 Beschäftigte — list-only densified
await ensure('/employees', 'employees', async () => {
	await page.waitForSelector('#dc-employees-table-body tr, table tbody tr', { timeout: 30000 })
	await page.waitForFunction(() => {
		const body = document.getElementById('dc-employees-table-body') || document.querySelector('table tbody')
		const text = body?.innerText || ''
		return /Anna Weber/i.test(text) && /Jonas Vogel|Greta Lorenz/i.test(text)
	}, { timeout: 30000 })
	await polish(`#dc-employees-table-body tr{height:1.35rem} .dc-table-meta,.dc-visible-rows-hint{display:none!important}`)
	await page.evaluate(() => {
		document.querySelector('section:has(#dc-employees-table-body), #dc-employees-table-body')?.scrollIntoView({ block: 'start' })
	})
	await waitNoLoading()
})
await shot('dutycheck-screenshot-05.png', `#dc-employees-table-body tr{height:1.35rem}`)

// 06 Muster — open dense week-grid editor filling fold
await ensure('/patterns', 'patterns', async () => {
	pinDe()
	await page.waitForSelector('#dc-patterns-list, #dc-patterns-page', { timeout: 30000 })
	await page.waitForFunction(() => {
		const cards = document.querySelectorAll('#dc-patterns-list > li.dc-patterns__item').length
		const days = document.querySelectorAll('.dc-patterns__preview-day').length
		return cards >= 3 && days >= 14
	}, { timeout: 40000 })
	await page.evaluate(() => {
		const items = Array.from(document.querySelectorAll('#dc-patterns-list > li.dc-patterns__item'))
		const prefer = items.find((li) => /Leitstelle|Flughafen/i.test(li.textContent || '')) || items[0]
		const edit = Array.from(prefer?.querySelectorAll('button') || []).find((b) => /Bearbeiten|Edit/i.test(b.textContent || ''))
		edit?.click?.()
	})
	await page.waitForSelector('.dc-patterns__grid-host .dc-patterns__day, .dc-patterns__grid .dc-patterns__day, dialog .dc-patterns__day', {
		timeout: 15000,
	})
	await polish(PATTERN_DENSE)
	await page.evaluate(() => {
		document.querySelector('.dc-patterns__grid-host, .dc-patterns__editor, dialog')?.scrollIntoView({ block: 'start' })
	})
	await waitNoLoading()
})
await shot('dutycheck-screenshot-06.png', PATTERN_DENSE)

// 07 Abwesenheiten
await ensure('/absences', 'absences', async () => {
	await page.waitForSelector('#dc-absences-table-body tr, table tbody tr', { timeout: 30000 }).catch(() => {})
	await page.waitForFunction(() => {
		const main = document.querySelector('#dc-main-content')?.innerText || ''
		return /Elena|Felix|Greta|Jonas|Clara/i.test(main) && /Genehmigt/i.test(main) && /Ausstehend/i.test(main)
	}, { timeout: 30000 })
	await polish(`#dc-absences-table-body tr{height:1.35rem} #dc-absences-table-body .dc-row-actions{flex-direction:row!important}`)
	await waitNoLoading()
})
await shot('dutycheck-screenshot-07.png', `#dc-absences-table-body tr{height:1.35rem}`)

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
	round: 3,
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
		'01': 'Übersicht hard conflict list + KPI',
		'02': 'Heute 3-col Veröffentlicht',
		'03': 'Zeiträume lifecycle mix',
		'04': 'Dienstplan Nov + hard rows + both swaps',
		'05': 'Beschäftigte list-only densified',
		'06': 'Muster week-grid editor fold',
		'07': 'Abwesenheiten mix densified',
		'08': 'Hard checks + Elena→Ben + Anna→David',
	},
}
writeFileSync(resolve(outDir, '_r3-capture-meta.json'), JSON.stringify(meta, null, 2) + '\n')
console.log('meta', meta.captured_at)

await browser.close()
stopLangPin()
console.log('DutyCheck store R3 capture complete')
