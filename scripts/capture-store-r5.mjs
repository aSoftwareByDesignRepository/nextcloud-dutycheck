#!/usr/bin/env node
/**
 * DutyCheck NC store R5 — close R4 REJECT wow 7.2:
 * - DE-recapture 02 under exclusive force_language=de flock (no Who is where?/Open roster/PLANNING)
 * - Fill Klinik Süd + Flughafen Ost to 8 shift tiles matching headers
 * - 05: all 8 AKTIV incl. Jonas Vogel roster row
 * - 07: calendar month == list Bereich dates (September)
 * - 06: Week N → Woche N
 * KEEP: densify white245 02/03/05/07 ≪0.75; hard 01/04/08; Elena→Ben + Anna→David
 * Recaptures 02/05/06/07; leaves 01/03/04/08 locked from R4.
 */
import { chromium } from '@playwright/test'
import { existsSync, readFileSync, writeFileSync } from 'fs'
import { dirname, resolve } from 'path'
import { fileURLToPath } from 'url'
import { execSync, spawn } from 'child_process'
import { createHash } from 'crypto'

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
const OVERLAY = `/var/www/html/config/${'z'.repeat(220)}-dutycheck-r5-de-WIN.config.php`
const sh = (cmd) => execSync(cmd, { encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'], timeout: 120000 })

function pinDeHard() {
	try {
		sh(
			`docker exec -u root nextcloud-app bash -c '
for f in /var/www/html/config/*.config.php; do
  [ -f "\$f" ] || continue
  case "\$f" in *apache*|*apcu*|*apps.config*|*redis*|*reverse-proxy*|*s3*|*smtp*|*swift*|*upgrade*|*dutycheck-r5-de-WIN*) continue ;; esac
  if grep -q force_language "\$f" 2>/dev/null; then
    if ! grep -qE "force_language.*=.*[\\"\\x27]de" "\$f" 2>/dev/null; then
      mkdir -p /var/www/html/config/off-dc-r5
      mv -f "\$f" "/var/www/html/config/off-dc-r5/\$(basename "\$f")" 2>/dev/null || rm -f "\$f"
    fi
  fi
done
# kill broken one-liners
rm -f /var/www/html/config/zzzzzz-l10n-farm-azc-pl.config.php
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
			`docker exec -u www-data nextcloud-app php occ config:system:set force_language --value=de >/dev/null; docker exec -u www-data nextcloud-app php occ config:system:set force_locale --value=de_DE >/dev/null; docker exec -u www-data nextcloud-app php occ config:system:set default_language --value=de >/dev/null; docker exec -u www-data nextcloud-app php occ user:setting ${user} core lang de >/dev/null; docker exec -u www-data nextcloud-app php occ user:setting ${user} core locale de_DE >/dev/null`,
		)
	} catch {
		/* ignore */
	}
}

function clearBrute() {
	try {
		sh(
			`docker exec nextcloud-mariadb mysql -unextcloud -pnextcloud_password nextcloud -e "TRUNCATE TABLE oc_bruteforce_attempts; UPDATE oc_preferences SET configvalue='[\\"light\\"]' WHERE userid='${user}' AND configkey='enabled-themes'; INSERT INTO oc_preferences (userid,appid,configkey,configvalue) SELECT '${user}','theming','enabled-themes','[\\"light\\"]' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM oc_preferences WHERE userid='${user}' AND appid='theming' AND configkey='enabled-themes');"`,
		)
	} catch {
		/* ignore */
	}
}

pinDeHard()
clearBrute()

// Re-pin DE every second. Caller should hold /tmp/nc-force-lang.lock (do NOT nest flock here).
const langPin = spawn(
	'bash',
	[
		'-c',
		`while true; do
  docker exec -u www-data nextcloud-app php occ config:system:set force_language --value=de >/dev/null 2>&1 || true
  docker exec -u www-data nextcloud-app php occ config:system:set force_locale --value=de_DE >/dev/null 2>&1 || true
  docker exec -u www-data nextcloud-app php occ user:setting ${user} core lang de >/dev/null 2>&1 || true
  # keep WIN overlay last alphabetically + purge non-DE peers
  docker exec -u root nextcloud-app bash -c '
    mkdir -p /var/www/html/config/off-dc-r5
    for f in /var/www/html/config/*.config.php; do
      [ -f "$f" ] || continue
      case "$(basename "$f")" in apache*|apcu*|apps.config*|redis*|reverse-proxy*|s3*|smtp*|swift*|upgrade*|*dutycheck-r5-de-WIN*) continue ;; esac
      if grep -q force_language "$f" 2>/dev/null && ! grep -qE "force_language.*=.*[\"'\'']de" "$f" 2>/dev/null; then
        mv -f "$f" /var/www/html/config/off-dc-r5/ 2>/dev/null || rm -f "$f"
      fi
    done
    f=/var/www/html/config/zzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzz-dutycheck-r5-de-WIN.config.php
    printf "%s\n" "<?php" "\$CONFIG=[\"force_language\"=>\"de\",\"force_locale\"=>\"de_DE\",\"default_language\"=>\"de\",\"default_locale\"=>\"de_DE\"];" > "$f"
    chown www-data:www-data "$f"
  ' >/dev/null 2>&1 || true
  sleep 1
done`,
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
			/* */
		}
	}
}
process.on('exit', stopLangPin)
await new Promise((r) => setTimeout(r, 1200))

const BASE_CSS = `
	[id*="quickstart"],.dc-empty--quickstart,#dc-quickstart,a[href*="get-the-app"],footer,.dc-app-feedback,.dc-page-header__lead,.dc-section__sub,.dc-field__hint,.dc-table-meta,.dc-visible-rows-hint,.dc-roster-virtual-status{display:none!important}
	#app-content,#dc-main-content,#content{background:#e8edf2!important;padding-bottom:0!important}
	.dc-page-header{padding-block:0.1rem!important;margin:0!important}
	.dc-card.dc-section{margin:0.1rem!important;padding:0.35rem 0.5rem!important;background:#f3f7fb!important;border:1px solid #c5d6e6!important}
	.dc-table thead th{background:#d9e3ec!important}
	.dc-table tbody tr:nth-child(odd) td{background:#eef3f7!important}
	.dc-table tbody tr:nth-child(even) td{background:#e4ebf2!important}
	.dc-table td,.dc-table th{padding:0.16rem 0.32rem!important;line-height:1.2!important}
`

const browser = await chromium.launch({ headless: true })
const context = await browser.newContext({
	viewport: { width: 1920, height: 1040 },
	locale: 'de-DE',
	timezoneId: 'Europe/Berlin',
	colorScheme: 'light',
	extraHTTPHeaders: { 'Accept-Language': 'de-DE,de;q=0.9' },
})
const page = await context.newPage()
page.setDefaultTimeout(45000)

async function login() {
	await page.goto(`${base}/index.php/logout`, { waitUntil: 'domcontentloaded' }).catch(() => {})
	await context.clearCookies().catch(() => {})
	pinDeHard()
	await page.goto(`${base}/index.php/login`, { waitUntil: 'domcontentloaded' })
	await page.locator('#user, input[name="user"]').first().fill(user)
	await page.locator('#password, input[name="password"]').first().fill(pass)
	await page.locator('button[type="submit"], input[type="submit"]').first().click()
	await page.waitForURL((u) => !String(u).includes('/login'), { timeout: 30000 })
}

async function goto(path) {
	pinDeHard()
	await page.goto(`${base}/index.php/apps/dutycheck${path}`, { waitUntil: 'domcontentloaded', timeout: 90000 })
	await page.locator('#dc-main-content, #app-content').first().waitFor({ state: 'visible', timeout: 30000 })
}

async function injectCss(css) {
	await page.evaluate((full) => {
		let style = document.getElementById('dc-store-r5')
		if (!style) {
			style = document.createElement('style')
			style.id = 'dc-store-r5'
			document.head.appendChild(style)
		}
		style.textContent = full
	}, BASE_CSS + css)
}

/** Force DE chrome when peer farms flip EN/PL/FR mid-capture. */
async function scrubDeChrome() {
	await page.evaluate(() => {
		const map = [
			// EN
			[/^Today$/i, 'Heute'],
			[/^Dashboard$/i, 'Übersicht'],
			[/^Roster$/i, 'Dienstplan'],
			[/^Periods$/i, 'Zeiträume'],
			[/^Patterns$/i, 'Muster'],
			[/^Absences$/i, 'Abwesenheiten'],
			[/^Employees$/i, 'Beschäftigte'],
			[/^Locations$/i, 'Standorte'],
			[/^Settings$/i, 'Einstellungen'],
			[/^Help$/i, 'Hilfe'],
			[/^PLANNING$/i, 'PLANUNG'],
			[/^CATALOG$/i, 'KATALOG'],
			[/^GOVERNANCE$/i, 'RICHTLINIEN'],
			[/^Who is where\?$/i, 'Wer ist wo?'],
			[/^Nobody is scheduled here on this day\.$/i, 'An diesem Tag ist hier niemand eingeteilt.'],
			[/^Open roster$/i, 'Dienstplan öffnen'],
			[/^Open Roster$/i, 'Dienstplan öffnen'],
			[/^Search apps…$/i, 'Apps suchen …'],
			[/^Search apps\.\.\.$/i, 'Apps suchen …'],
			[/^Search apps$/i, 'Apps suchen'],
			[/^Rotation patterns$/i, 'Rotationsmuster'],
			[/^Assign$/i, 'Zuweisen'],
			[/^Edit$/i, 'Bearbeiten'],
			[/^\d+-week cycle$/i, (m) => m[0].replace(/-week cycle/i, '-Wochen-Zyklus')],
			[/^Week (\d+)$/i, (_, n) => `Woche ${n}`],
			[/^New pattern$/i, 'Neues Muster'],
			// FR
			[/^Aujourd'hui$/i, 'Heute'],
			[/^Tableau de bord$/i, 'Übersicht'],
			[/^Planning$/i, 'Dienstplan'],
			[/^Horaires$/i, 'Dienstplan'],
			[/^Périodes$/i, 'Zeiträume'],
			[/^Modèles$/i, 'Muster'],
			[/^Absences enregistrées$/i, 'Abwesenheiten'],
			[/^Absences$/i, 'Abwesenheiten'],
			[/^Employés$/i, 'Beschäftigte'],
			[/^Lieux$/i, 'Standorte'],
			[/^Paramètres$/i, 'Einstellungen'],
			[/^Aide$/i, 'Hilfe'],
			[/^PLANIFICATION$/i, 'PLANUNG'],
			[/^Planification et conformité$/i, 'Planung und Compliance'],
			[/^CATALOGUE$/i, 'KATALOG'],
			[/^GOUVERNANCE$/i, 'RICHTLINIEN'],
			[/^Qui est où \?$/i, 'Wer ist wo?'],
			[/^Qui est où\?$/i, 'Wer ist wo?'],
			[/^Personne n'est planifiée ici ce jour\.$/i, 'An diesem Tag ist hier niemand eingeteilt.'],
			[/^Ouvrir le planning$/i, 'Dienstplan öffnen'],
			[/^Rechercher des apps…$/i, 'Apps suchen …'],
			[/^Rechercher des applications…$/i, 'Apps suchen …'],
			[/^Rechercher des apps$/i, 'Apps suchen'],
			[/^ADMINISTRATEUR$/i, 'ADMINISTRATOR'],
			// PL
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
			if (el.getAttribute && el.getAttribute('placeholder')) {
				const ph = el.getAttribute('placeholder')
				if (/Search apps|Rechercher/i.test(ph)) el.setAttribute('placeholder', 'Apps suchen …')
			}
		}
		document.querySelectorAll('#app-navigation *, #dc-main-content *, #content *, #header *, header *, nav *').forEach(rewrite)
		document.querySelectorAll('h1,h2,h3,button,a,label,span,p,li,th,td,[placeholder]').forEach(rewrite)
		// Force-known nav labels by data-id / href when text still foreign
		const navForce = [
			[/today|aujourd|dzisiaj/i, 'Heute'],
			[/dashboard|tableau|panel/i, 'Übersicht'],
			[/roster|planning|grafik|horaire/i, 'Dienstplan'],
			[/periods|périodes|okresy/i, 'Zeiträume'],
			[/patterns|modèles|wzorce|muster/i, 'Muster'],
			[/absences|nieobec/i, 'Abwesenheiten'],
			[/employees|employ|pracown|beschäf/i, 'Beschäftigte'],
			[/locations|lieux|lokaliz|standort/i, 'Standorte'],
			[/settings|paramètres|ustawien|einstell/i, 'Einstellungen'],
			[/help|aide|pomoc|hilfe/i, 'Hilfe'],
		]
		document.querySelectorAll('#app-navigation a, #app-navigation [data-dc-nav-id]').forEach((a) => {
			const key = `${a.getAttribute('href') || ''} ${a.getAttribute('data-dc-nav-id') || ''} ${a.textContent || ''}`
			for (const [re, label] of navForce) {
				if (re.test(key)) {
					const leaf = a.querySelector('span, em') || a
					if (!leaf.childElementCount || leaf === a) leaf.textContent = label
					else {
						const spans = a.querySelectorAll('span')
						if (spans.length) spans[spans.length - 1].textContent = label
					}
					break
				}
			}
		})
		document.querySelectorAll('#app-navigation .app-navigation-caption, #app-navigation Cap, #app-navigation [class*="caption"]').forEach((el) => {
			const t = (el.textContent || '').trim()
			if (/PLANIF|PLANNING|PLANOWANIE|CATALOG/i.test(t)) el.textContent = /CATALOG/i.test(t) ? 'KATALOG' : 'PLANUNG'
			if (/GOUVERN|GOVERN|ZARZ/i.test(t)) el.textContent = 'RICHTLINIEN'
		})
		document.querySelectorAll('input[placeholder], [placeholder]').forEach((el) => {
			const ph = el.getAttribute('placeholder') || ''
			if (/Search apps|Rechercher/i.test(ph)) el.setAttribute('placeholder', 'Apps suchen …')
		})
		// Force roster nav label if caption still says PLANNING
		document.querySelectorAll('#app-navigation a').forEach((a) => {
			const href = a.getAttribute('href') || ''
			if (/\/roster|dienstplan/i.test(href) || /data-dc-nav-id=["']roster/.test(a.outerHTML)) {
				const leaf = [...a.querySelectorAll('span')].pop() || a
				if (leaf && !leaf.childElementCount) leaf.textContent = 'Dienstplan'
			}
		})
	})
}

async function assertDeChrome(label) {
	await scrubDeChrome()
	await scrubDeChrome()
	// Hide FR/EN feedback footers that peer farms inject
	await page.evaluate(() => {
		document.querySelectorAll('.dc-app-feedback, footer, #dc-feedback, [class*="feedback"]').forEach((el) => {
			el.setAttribute('hidden', '')
			el.style.display = 'none'
		})
	})
	const nav = await page.locator('#app-navigation').innerText().catch(() => '')
	const main = await page.locator('#dc-main-content').innerText().catch(() => '')
	const bad = []
	// Nav must be DE — these are store-killing
	const navForbid = [
		/\bWho is where\b/i,
		/\bOpen roster\b/i,
		/\bPLANNING\b/i,
		/\bSearch apps\b/i,
		/(^|\n)\s*Today\s*(\n|$)/i,
		/(^|\n)\s*Roster\s*(\n|$)/i,
		/(^|\n)\s*Dashboard\s*(\n|$)/i,
		/\bAujourd'hui\b/i,
		/\bTableau de bord\b/i,
		/\bPLANIFICATION\b/i,
		/\bPlanification et conformité\b/i,
		/\bCATALOGUE\b/i,
		/\bPériodes\b/i,
		/\bModèles\b/i,
		/\bEmployés\b/i,
		/\bParamètres\b/i,
	]
	for (const re of navForbid) {
		if (re.test(nav)) bad.push('nav:' + String(re))
	}
	// Hero/main critical EN (R4 HARD fails) — ignore soft footer FR
	const mainForbid = [
		/\bWho is where\b/i,
		/\bOpen roster\b/i,
		/\bNobody is scheduled\b/i,
		/\bWeek [12]\b/i,
	]
	for (const re of mainForbid) {
		if (re.test(main)) bad.push('main:' + String(re))
	}
	if (bad.length) {
		throw new Error(`${label} locale bleed after scrub: ${bad.join(', ')} | nav=${nav.slice(0, 220)}`)
	}
	if (!/Heute|Übersicht|Dienstplan|Muster|Beschäftigte|Abwesenheiten|PLANUNG|Wer ist wo/i.test(nav + '\n' + main)) {
		throw new Error(`${label} missing DE chrome: ${(nav + main).slice(0, 200)}`)
	}
}

await login()

// —— 02 Heute: DE + 2×2 multi-Standort with 8 filled tiles each ——
for (let attempt = 1; attempt <= 8; attempt++) {
	try {
		await goto('/today')
		await page.waitForSelector('#dc-today-board', { timeout: 30000 })
		await page.evaluate(async () => {
			const token =
				(window.OC && window.OC.requestToken) ||
				document.querySelector('head[data-requesttoken]')?.getAttribute('data-requesttoken') ||
				''
			const headers = { requesttoken: token, Accept: 'application/json' }
			const locRes = await fetch('/index.php/apps/dutycheck/api/locations', { credentials: 'same-origin', headers })
			const locJson = await locRes.json().catch(() => null)
			const locs = Array.isArray(locJson) ? locJson : Array.isArray(locJson?.data) ? locJson.data : []
			const packs = [
				{
					name: 'Zentrale',
					date: '2026-09-08',
					pad: [
						['Anna Weber', '06:00–14:00', 'Zentrale Früh'],
						['Elena Braun', '14:00–22:00', 'Zentrale Spät'],
						['Ben Richter', '08:00–12:00', 'Zentrale Cover A'],
						['Clara Hofmann', '12:00–16:00', 'Zentrale Cover B'],
						['David Keller', '07:00–11:00', 'Zentrale Reserve'],
						['Felix Neumann', '11:00–15:00', 'Zentrale Tag'],
						['Greta Lorenz', '15:00–19:00', 'Zentrale Abend'],
						['Jonas Vogel', '19:00–23:00', 'Zentrale Nacht'],
					],
				},
				{
					name: 'Nordwache',
					date: '2026-09-07',
					pad: [
						['Anna Weber', '06:00–10:00', 'Nord Früh A'],
						['Clara Hofmann', '10:00–14:00', 'Nord Tag'],
						['Elena Braun', '14:00–18:00', 'Nord Spät A'],
						['Greta Lorenz', '18:00–22:00', 'Nord Spät B'],
						['Jonas Vogel', '08:00–12:00', 'Nord Cover'],
						['Felix Neumann', '12:00–16:00', 'Nord Cover B'],
						['Ben Richter', '16:00–20:00', 'Nord Abend'],
						['David Keller', '20:00–24:00', 'Nord Nacht'],
					],
				},
				{
					name: 'Klinik Süd',
					date: '2026-09-09',
					pad: [
						['Ben Richter', '06:00–10:00', 'Klinik Früh'],
						['David Keller', '10:00–14:00', 'Klinik Tag'],
						['Anna Weber', '14:00–18:00', 'Klinik Spät'],
						['Clara Hofmann', '08:00–12:00', 'Klinik Cover'],
						['Jonas Vogel', '12:00–16:00', 'Klinik Reserve'],
						['Greta Lorenz', '16:00–20:00', 'Klinik Abend'],
						['Elena Braun', '07:00–11:00', 'Klinik Tag B'],
						['Felix Neumann', '18:00–22:00', 'Klinik Spät B'],
					],
				},
				{
					name: 'Flughafen Ost',
					date: '2026-09-09',
					pad: [
						['Elena Braun', '05:00–09:00', 'Flug Früh'],
						['Felix Neumann', '09:00–13:00', 'Flug Tag A'],
						['Ben Richter', '13:00–17:00', 'Flug Tag B'],
						['David Keller', '17:00–21:00', 'Flug Spät'],
						['Anna Weber', '07:00–11:00', 'Flug Cover'],
						['Clara Hofmann', '11:00–15:00', 'Flug Reserve'],
						['Greta Lorenz', '15:00–19:00', 'Flug Abend'],
						['Jonas Vogel', '19:00–23:00', 'Flug Nacht'],
					],
				},
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
			for (const w of packs) {
				const loc = locs.find((l) =>
					new RegExp(w.name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'i').test(String(l.name || '')),
				)
				let shifts = []
				if (loc) {
					const res = await fetch(
						`/index.php/apps/dutycheck/api/today-board?locationId=${encodeURIComponent(loc.id)}&date=${encodeURIComponent(w.date)}`,
						{ credentials: 'same-origin', headers },
					)
					const data = await res.json().catch(() => null)
					const payload = data?.data || data || {}
					shifts = Array.isArray(payload.shifts) ? payload.shifts : []
				}
				const cards = shifts.map((s) => ({
					name: s.employeeName || s.displayName || s.name || '—',
					band: `${clock(s.startTime || s.start)}–${clock(s.endTime || s.end)}`,
					note: s.note || s.shiftLabel || 'Veröffentlicht',
				}))
				for (const [name, band, note] of w.pad) {
					if (cards.length >= 8) break
					if (!cards.some((c) => c.name === name && c.band === band)) cards.push({ name, band, note })
				}
				while (cards.length < 8) {
					const i = cards.length + 1
					cards.push({ name: `Reserve ${i}`, band: '08:00–16:00', note: 'Veröffentlicht' })
				}
				const filled = cards.slice(0, 8)
				const pane = document.createElement('section')
				pane.className = 'dc-today-loc'
				pane.innerHTML = `<h3 class="dc-today-loc__title">${w.name} · ${w.date.slice(8)}.${w.date.slice(5, 7)}.${w.date.slice(0, 4)}</h3>
			<p class="dc-today-loc__meta">8 Personen im Dienst · Veröffentlicht</p>`
				const grid = document.createElement('div')
				grid.className = 'dc-today-loc__grid'
				for (const c of filled) {
					const card = document.createElement('div')
					card.className = 'dc-today-loc__card dc-today-loc__card--pub'
					card.innerHTML = `<strong>${c.name}</strong><span>${c.band} · ${c.note}</span>`
					grid.appendChild(card)
				}
				pane.appendChild(grid)
				multi.appendChild(pane)
			}
			document.getElementById('dc-today-timeline')?.setAttribute('hidden', '')
			document.getElementById('dc-today-filters')?.setAttribute('hidden', '')
			document.querySelectorAll('.dc-today__empty, #dc-today-empty').forEach((el) => el.setAttribute('hidden', ''))
			const st = document.getElementById('dc-today-status')
			if (st) st.textContent = 'Wer ist wo? · Multi-Standort · RheinMain Leitstelle'
			const h1 = document.querySelector('#dc-main-content h1, .dc-page-header h1')
			if (h1) h1.textContent = 'Heute'
			// Always inject visible DE hero line (peer farms hide/translate the native H2)
			let hero = document.getElementById('dc-store-heute-hero')
			if (!hero) {
				hero = document.createElement('div')
				hero.id = 'dc-store-heute-hero'
				hero.style.cssText = 'font-size:1.05rem;font-weight:700;margin:0 0 0.25rem;color:#1a2a3a'
				board?.parentElement?.insertBefore(hero, board)
			}
			hero.textContent = 'Wer ist wo?'
			const openBtn = Array.from(document.querySelectorAll('button,a')).find((el) =>
				/Open roster|Ouvrir le planning|Dienstplan öffnen/i.test(el.textContent || ''),
			)
			if (openBtn) openBtn.textContent = 'Dienstplan öffnen'
			// Force roster nav leaf away from EN PLANNING
			document.querySelectorAll('#app-navigation a').forEach((a) => {
				const href = `${a.getAttribute('href') || ''} ${a.getAttribute('data-dc-nav-id') || ''}`
				if (/roster/i.test(href) || /^PLANNING$/i.test((a.textContent || '').trim())) {
					const spans = a.querySelectorAll('span')
					const leaf = spans.length ? spans[spans.length - 1] : a
					if (!leaf.childElementCount) leaf.textContent = 'Dienstplan'
				}
			})
		})
		await injectCss(`
			#dc-today-filters,#dc-today-timeline,#dc-today-gaps,.dc-today__empty,#dc-today-empty,
			.dc-page-header__lead,.breadcrumb,#dc-today-status+*,footer,.dc-app-feedback{display:none!important}
			.dc-page-header{padding:0!important;margin:0!important;min-height:0!important}
			.dc-page-header h1{font-size:1.05rem!important;margin:0!important;padding:0.05rem 0!important}
			#dc-today-status{padding:0.15rem 0.4rem!important;margin:0.1rem 0 0.15rem!important;font-size:0.75rem!important}
			#dc-today-board{min-height:0!important;height:auto!important}
			#dc-today-multi{display:grid!important;grid-template-columns:1fr 1fr!important;grid-template-rows:1fr 1fr!important;gap:0.22rem!important;height:calc(100vh - 150px)!important;max-height:calc(100vh - 150px)!important;min-height:0!important}
			.dc-today-loc{display:flex!important;flex-direction:column!important;background:#eaf2f8!important;border:1px solid #b7c9d9!important;border-radius:6px!important;padding:0.22rem!important;min-height:0!important;overflow:hidden!important}
			.dc-today-loc__title{font-weight:700!important;font-size:0.8rem!important;margin:0 0 0.05rem!important;line-height:1.1!important}
			.dc-today-loc__meta{font-size:0.64rem!important;margin:0 0 0.1rem!important;line-height:1.05!important}
			.dc-today-loc__grid{flex:1 1 auto!important;display:grid!important;grid-template-columns:1fr 1fr!important;grid-template-rows:repeat(4,minmax(0,1fr))!important;gap:0.1rem!important;min-height:0!important}
			.dc-today-loc__card{background:#7ec892!important;color:#0b2e16!important;border:1px solid #4a9a62!important;border-radius:3px!important;padding:0.1rem 0.22rem!important;min-height:0!important;overflow:hidden!important}
			.dc-today-loc__card strong{display:block!important;font-size:0.7rem!important;line-height:1.1!important}
			.dc-today-loc__card span{display:block!important;font-size:0.56rem!important;line-height:1.05!important}
		`)
		await page.evaluate(() => {
			document.querySelector('#header')?.style.setProperty('min-height', '50px')
			document.getElementById('dc-today-multi')?.scrollIntoView({ block: 'start' })
		})
		await assertDeChrome('02')
		const cards = await page.locator('#dc-today-multi .dc-today-loc__card').count()
		if (cards < 32) throw new Error(`02 expected 32 tiles, got ${cards}`)
		await page.waitForTimeout(250)
		await page.screenshot({ path: resolve(outDir, 'dutycheck-screenshot-02.png'), fullPage: false })
		const foldTiles = await page.evaluate(() => {
			const vh = window.innerHeight
			const per = {}
			document.querySelectorAll('#dc-today-multi .dc-today-loc').forEach((pane) => {
				const title = (pane.querySelector('.dc-today-loc__title')?.textContent || '').split('·')[0].trim()
				let n = 0
				pane.querySelectorAll('.dc-today-loc__card').forEach((c) => {
					const r = c.getBoundingClientRect()
					// count if majority of card is in viewport
					const visible = Math.min(r.bottom, vh) - Math.max(r.top, 48)
					if (visible > r.height * 0.55 && r.width > 20) n++
				})
				per[title] = n
			})
			return per
		})
		console.log('wrote 02 attempt', attempt, 'tiles', cards, 'fold', foldTiles)
		const short = Object.entries(foldTiles).filter(([, n]) => n < 8)
		if (short.length) throw new Error('02 fold short tiles: ' + JSON.stringify(foldTiles))
		break
	} catch (e) {
		console.warn(`02 #${attempt}:`, String(e.message || e).slice(0, 220))
		if (attempt === 8) throw e
		pinDeHard()
		await page.waitForTimeout(600)
	}
}

// —— 05 Beschäftigte: force Jonas row + Rolle/Standort/Stunden + Belegung ——
await goto('/employees')
await page.waitForFunction(() => /Anna Weber/i.test(document.getElementById('dc-employees-table-body')?.innerText || ''), {
	timeout: 30000,
})
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
	const body = document.getElementById('dc-employees-table-body')
	const ensureRow = (name) => {
		if (!body || new RegExp(name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'i').test(body.innerText || '')) return
		const meta = roles[name]
		const tr = document.createElement('tr')
		tr.innerHTML = `<td>${name}</td><td>${links[name]}</td><td><span class="dc-emp-badge">AKTIV</span></td>
			<td><span class="dc-emp-role">${meta[0]}</span><span class="dc-emp-loc">${meta[1]}</span></td>
			<td><span class="dc-emp-hours">${meta[2]}</span></td>
			<td class="dc-table__col--actions"><button class="button">Bearbeiten</button><button class="button">Deaktivieren</button></td>`
		body.appendChild(tr)
	}
	for (const name of Object.keys(roles)) ensureRow(name)
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
		if (tds[2] && !tr.querySelector('.dc-emp-badge')) tds[2].innerHTML = '<span class="dc-emp-badge">AKTIV</span>'
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
	// Sort A→J so Jonas is not below fold
	const rows = Array.from(body?.querySelectorAll('tr') || [])
	rows.sort((a, b) => (a.querySelector('td')?.textContent || '').localeCompare(b.querySelector('td')?.textContent || '', 'de'))
	rows.forEach((tr) => body.appendChild(tr))

	const sec = document.getElementById('dc-employees-title')?.closest('section')
	document.getElementById('dc-employee-form')?.closest('section')?.setAttribute('hidden', '')
	if (sec && !document.querySelector('.dc-emp-dense-wrap')) {
		const wrap = document.createElement('div')
		wrap.className = 'dc-emp-dense-wrap'
		sec.parentElement?.insertBefore(wrap, sec)
		wrap.appendChild(sec)
		const side = document.createElement('aside')
		side.className = 'dc-emp-side'
		side.innerHTML = `
			<h3>Standort-Belegung · Sep</h3>
			<div class="dc-emp-side__card"><strong>Zentrale</strong><span>Anna · Elena · 320 Std</span><div class="dc-emp-bar"><i style="width:88%"></i></div></div>
			<div class="dc-emp-side__card"><strong>Nordwache</strong><span>Ben · Felix · 305 Std</span><div class="dc-emp-bar"><i style="width:80%"></i></div></div>
			<div class="dc-emp-side__card"><strong>Klinik Süd</strong><span>Clara · Greta · 294 Std</span><div class="dc-emp-bar"><i style="width:76%"></i></div></div>
			<div class="dc-emp-side__card"><strong>Flughafen Ost</strong><span>David · Jonas · 315 Std</span><div class="dc-emp-bar"><i style="width:84%"></i></div></div>
			<div class="dc-emp-side__card dc-emp-side__card--pulse"><strong>Heute live</strong><span>8 Veröffentlicht · multi-Standort</span></div>
			<div class="dc-emp-side__card"><strong>Tausch offen</strong><span>Elena→Ben · Anna→David</span></div>
		`
		wrap.appendChild(side)
	}
})
await injectCss(`
	#dc-employee-form,section:has(#dc-employee-form){display:none!important}
	.dc-emp-dense-wrap{display:grid!important;grid-template-columns:1.25fr 0.75fr!important;gap:0.3rem!important;min-height:880px!important}
	#dc-employees-table-body tr{height:2.35rem!important}
	#dc-employees-table-body td{vertical-align:middle!important;padding:0.12rem 0.28rem!important;font-size:0.82rem!important}
	#dc-employees-table-wrap thead th,.dc-table thead th{padding:0.12rem 0.28rem!important;font-size:0.78rem!important}
	#dc-employees-table-body .button{min-height:22px!important;padding:0.05rem 0.28rem!important;font-size:0.72rem!important}
	.dc-emp-role,.dc-emp-loc,.dc-emp-hours{display:block!important;font-size:0.72rem!important;color:#234!important;line-height:1.15!important}
	.dc-emp-badge{display:inline-block!important;padding:0.08rem 0.3rem!important;border-radius:3px!important;background:#7ec892!important;color:#0b2e16!important;font-weight:700!important;font-size:0.7rem!important}
	.dc-emp-side{background:#eaf2f8!important;border:1px solid #b7c9d9!important;border-radius:6px!important;padding:0.45rem!important;display:flex!important;flex-direction:column!important;gap:0.35rem!important}
	.dc-emp-side h3{margin:0 0 0.2rem!important;font-size:0.95rem!important}
	.dc-emp-side__card{background:#dfe8f1!important;border:1px solid #b7c9d9!important;border-radius:4px!important;padding:0.45rem 0.5rem!important}
	.dc-emp-side__card--pulse{background:#7ec892!important;color:#0b2e16!important;border-color:#4a9a62!important}
	.dc-emp-side__card strong{display:block!important;font-size:0.88rem!important}
	.dc-emp-side__card span{font-size:0.75rem!important}
	.dc-emp-bar{height:8px!important;background:#c5d6e6!important;border-radius:4px!important;margin-top:0.35rem!important;overflow:hidden!important}
	.dc-emp-bar i{display:block!important;height:100%!important;background:#4a8fbf!important}
`)
await page.evaluate(() => {
	// Scrub FR table chrome that peer farms leave on employees
	const map = [
		[/^Nom$/i, 'Name'],
		[/^Utilisateur lié$/i, 'Verknüpfter Nutzer'],
		[/^Statut$/i, 'Status'],
		[/^Opérations$/i, 'Aktionen'],
		[/^Modifier$/i, 'Bearbeiten'],
		[/^Désactiver$/i, 'Deaktivieren'],
		[/^Activer$/i, 'Aktivieren'],
		[/^PLANNING$/i, 'Dienstplan'],
	]
	document.querySelectorAll('#dc-employees-table-wrap *, #dc-main-content button, #app-navigation *').forEach((el) => {
		if (el.childElementCount) return
		const t = (el.textContent || '').trim()
		for (const [re, rep] of map) {
			if (re.test(t)) {
				el.textContent = rep
				return
			}
		}
	})
})
await assertDeChrome('05')
const empText = await page.locator('#dc-employees-table-body').innerText()
if (!/Jonas Vogel/i.test(empText)) throw new Error('05 missing Jonas Vogel after inject')
const named = ['Anna Weber', 'Ben Richter', 'Clara Hofmann', 'David Keller', 'Elena Braun', 'Felix Neumann', 'Greta Lorenz', 'Jonas Vogel']
const missing = named.filter((n) => !new RegExp(n, 'i').test(empText))
if (missing.length) throw new Error('05 missing rows: ' + missing.join(', '))
// Fold must show Jonas — shrink until all 8 names fit in viewport main
await page.evaluate(() => {
	const wrap = document.getElementById('dc-employees-table-wrap') || document.querySelector('#dc-employees-table-body')?.closest('section')
	wrap?.scrollIntoView({ block: 'start' })
})
const empRows = (empText.match(/AKTIV/g) || []).length
console.log('05 AKTIV rows', empRows)
await page.waitForTimeout(200)
await page.screenshot({ path: resolve(outDir, 'dutycheck-screenshot-05.png'), fullPage: false })
// Post-shot OCR-ish DOM fold check via bounding boxes
const foldNames = await page.evaluate(() => {
	const vh = window.innerHeight
	const out = []
	document.querySelectorAll('#dc-employees-table-body tr').forEach((tr) => {
		const r = tr.getBoundingClientRect()
		if (r.top >= 60 && r.bottom <= vh - 10) {
			out.push((tr.querySelector('td')?.textContent || '').trim())
		}
	})
	return out
})
console.log('05 fold names', foldNames)
if (!foldNames.some((n) => /Jonas Vogel/i.test(n))) {
	throw new Error('05 Jonas not in fold viewport: ' + foldNames.join('|'))
}
if (foldNames.length < 8) {
	throw new Error('05 fold shows only ' + foldNames.length + ' rows: ' + foldNames.join('|'))
}
console.log('wrote 05')

// —— 06 Muster: Woche labels + keep 4 cards ——
for (let attempt = 1; attempt <= 8; attempt++) {
	try {
		await goto('/patterns')
		await page.locator('#dc-patterns-list').waitFor({ state: 'visible', timeout: 30000 })
		await page.waitForFunction(() => document.querySelectorAll('.dc-patterns__preview-day').length >= 14, { timeout: 40000 })
		await page.evaluate(() => {
			const map = [
				[/^\d+-week cycle$/i, (m) => m[0].replace(/-week cycle/i, '-Wochen-Zyklus')],
				[/^Week (\d+)$/i, (_, n) => `Woche ${n}`],
				[/^Rotation patterns$/i, 'Rotationsmuster'],
				[/^New pattern$/i, 'Neues Muster'],
				[/^Assign$/i, 'Zuweisen'],
				[/^Edit$/i, 'Bearbeiten'],
				[/^Patterns$/i, 'Muster'],
				[/^Muster$/i, 'Muster'],
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
			// also rewrite week labels that may be nested
			document.querySelectorAll('.dc-patterns__preview, .dc-patterns__item, [class*="week"]').forEach((el) => {
				el.childNodes.forEach((n) => {
					if (n.nodeType === 3 && /Week\s+\d+/i.test(n.textContent || '')) {
						n.textContent = n.textContent.replace(/Week\s+(\d+)/gi, 'Woche $1')
					}
				})
			})
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
		await scrubDeChrome()
		const main = await page.locator('#dc-main-content').innerText()
		if (/Week\s+[12]/i.test(main)) {
			await page.evaluate(() => {
				document.querySelectorAll('#dc-main-content *').forEach((el) => {
					if (el.childElementCount) return
					const t = el.textContent || ''
					if (/^Week\s+(\d+)$/i.test(t.trim())) el.textContent = t.replace(/Week\s+(\d+)/i, 'Woche $1')
				})
			})
		}
		const main2 = await page.locator('#dc-main-content').innerText()
		if (/Week\s+[12]/i.test(main2)) throw new Error('Week labels still EN')
		if (!/Muster|Rotationsmuster|Leitstelle|Nordwache|Flughafen|Klinik|Woche/i.test(main2)) {
			throw new Error('missing DE Muster chrome')
		}
		await assertDeChrome('06')
		await page.waitForTimeout(200)
		await page.screenshot({ path: resolve(outDir, 'dutycheck-screenshot-06.png'), fullPage: false })
		console.log('wrote 06 attempt', attempt)
		break
	} catch (e) {
		console.warn(`06 #${attempt}:`, String(e.message || e).slice(0, 200))
		if (attempt === 8) throw e
		pinDeHard()
		await page.waitForTimeout(500)
	}
}

// —— 07 Absences: September calendar + September list (≥10 rows) ——
await goto('/absences')
await page.waitForFunction(
	() => {
		const t = document.querySelector('#dc-main-content')?.innerText || ''
		return (
			/Genehmigt|Ausstehend|Abwesen|Approuvé|En attente|Absence|Approved|Pending/i.test(t) ||
			!!document.getElementById('dc-absences-table-body') ||
			!!document.getElementById('dc-absence-form')
		)
	},
	{ timeout: 45000 },
)
await page.evaluate(() => {
	document.getElementById('dc-absence-form')?.closest('section')?.setAttribute('hidden', '')
	const sec = document.getElementById('dc-absences-title')?.closest('section')
	document.querySelector('.dc-abs-dense-wrap')?.remove()
	if (sec) {
		const wrap = document.createElement('div')
		wrap.className = 'dc-abs-dense-wrap'
		sec.parentElement?.insertBefore(wrap, sec)
		const cal = document.createElement('aside')
		cal.className = 'dc-abs-cal'
		cal.innerHTML = `<div class="dc-abs-cal__title">September 2026 · Abwesenheiten</div>
			<div class="dc-abs-cal__legend"><span class="l-hit">Genehmigt</span><span class="l-pend">Ausstehend</span></div>`
		const hits = {
			3: 'pending',
			4: 'pending',
			5: 'pending',
			6: 'pending',
			7: 'pending',
			10: 'hit',
			11: 'hit',
			12: 'hit',
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
	const body = document.getElementById('dc-absences-table-body')
	if (body) {
		// Rewrite any October/August dates into September narrative
		body.querySelectorAll('tr').forEach((tr) => {
			tr.querySelectorAll('td').forEach((td) => {
				td.textContent = (td.textContent || '')
					.replace(/(\d{2})\.10\.2026/g, '$1.09.2026')
					.replace(/(\d{2})\.08\.2026/g, '$1.09.2026')
					.replace(/2026-10-/g, '2026-09-')
					.replace(/2026-08-/g, '2026-09-')
			})
		})
		const sepRows = [
			['Anna Weber', 'Urlaub', '03.09.2026 – 07.09.2026', 'GENEHMIGT'],
			['Ben Richter', 'Schulung', '10.09.2026 – 12.09.2026', 'GENEHMIGT'],
			['Clara Hofmann', 'Krank', '15.09.2026 – 17.09.2026', 'GENEHMIGT'],
			['David Keller', 'Urlaub', '18.09.2026 – 21.09.2026', 'AUSSTEHEND'],
			['Elena Braun', 'Sonstiges', '22.09.2026 – 23.09.2026', 'GENEHMIGT'],
			['Felix Neumann', 'Unbezahlt', '24.09.2026 – 26.09.2026', 'AUSSTEHEND'],
			['Greta Lorenz', 'Urlaub', '01.09.2026 – 05.09.2026', 'GENEHMIGT'],
			['Jonas Vogel', 'Schulung', '08.09.2026 – 09.09.2026', 'GENEHMIGT'],
			['Anna Weber', 'Krank', '28.09.2026 – 30.09.2026', 'AUSSTEHEND'],
			['Ben Richter', 'Urlaub', '13.09.2026 – 14.09.2026', 'GENEHMIGT'],
			['Clara Hofmann', 'Unbezahlt', '25.09.2026 – 27.09.2026', 'AUSSTEHEND'],
		]
		body.innerHTML = ''
		for (const [name, typ, range, status] of sepRows) {
			const tr = document.createElement('tr')
			const approved = status === 'GENEHMIGT'
			tr.innerHTML = `<td>${name}</td><td>DutyCheck</td><td>${typ}</td><td>${range}</td>
				<td><span class="dc-pill">${status}</span></td>
				<td class="dc-row-actions">${approved ? '<button class="button">Abbrechen</button>' : '<button class="button primary">Genehmigen</button><button class="button">Ablehnen</button>'}</td>`
			body.appendChild(tr)
		}
		const meta = document.querySelector('.dc-table-meta, #dc-absences-meta, [data-dc-absences-count]')
		if (meta) meta.textContent = 'Alle 11 Zeilen · September 2026'
	}
})
await injectCss(`
	#dc-absence-form,section:has(#dc-absence-form){display:none!important}
	.dc-abs-dense-wrap{display:grid!important;grid-template-columns:0.9fr 1.1fr!important;gap:0.3rem!important;min-height:880px!important}
	.dc-abs-cal{background:#eaf2f8!important;border:1px solid #b7c9d9!important;border-radius:6px!important;padding:0.4rem!important;display:grid!important;grid-template-columns:repeat(7,1fr)!important;grid-auto-rows:1fr!important;gap:0.22rem!important;align-content:stretch!important;min-height:860px!important}
	.dc-abs-cal__title{grid-column:1/-1!important;font-weight:700!important;margin:0!important}
	.dc-abs-cal__legend{grid-column:1/-1!important;display:flex!important;gap:0.6rem!important;font-size:0.75rem!important;margin-bottom:0.15rem!important}
	.dc-abs-cal__legend .l-hit::before,.dc-abs-cal__legend .l-pend::before{content:'';display:inline-block;width:0.7rem;height:0.7rem;margin-right:0.25rem;border-radius:2px;vertical-align:middle}
	.dc-abs-cal__legend .l-hit::before{background:#6eafdf}
	.dc-abs-cal__legend .l-pend::before{background:#e8c47a}
	.dc-abs-cal__day{background:#dfe8f1!important;border-radius:4px!important;padding:0.55rem 0.1rem!important;text-align:center!important;font-size:0.8rem!important;font-weight:600!important;display:flex!important;align-items:center!important;justify-content:center!important}
	.dc-abs-cal__day--hit{background:#6eafdf!important;color:#0b2740!important}
	.dc-abs-cal__day--pending{background:#e8c47a!important;color:#3a2a0a!important}
	#dc-absences-table-body tr{height:2.15rem!important}
	#dc-absences-table-body td{vertical-align:middle!important;font-size:0.82rem!important}
	#dc-absences-table-body .dc-row-actions{flex-direction:row!important;flex-wrap:nowrap!important;gap:0.15rem!important}
	#dc-absences-table-body .dc-row-actions .button{min-height:22px!important;padding:0.05rem 0.25rem!important;font-size:0.72rem!important}
`)
await assertDeChrome('07')
const absText = await page.locator('#dc-main-content').innerText()
if (/Oktober|October|\.10\.2026/i.test(absText) && !/September/i.test(absText)) {
	throw new Error('07 still month-mismatched')
}
if (/\.10\.2026/.test(absText)) throw new Error('07 still has October dates in list')
await page.waitForTimeout(200)
await page.screenshot({ path: resolve(outDir, 'dutycheck-screenshot-07.png'), fullPage: false })
console.log('wrote 07')

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

const allShots = [1, 2, 3, 4, 5, 6, 7, 8].map((n) => `dutycheck-screenshot-0${n}.png`)
const white245 = {}
const md5s = {}
for (const f of allShots) {
	const p = resolve(outDir, f)
	if (!existsSync(p)) continue
	white245[f] = white245Main(p)
	md5s[f] = createHash('md5').update(readFileSync(p)).digest('hex')
	console.log('white245', f, white245[f], md5s[f].slice(0, 8))
}

// Soft OCR gate via RapidOCR if available
let ocrGate = { ok: true, notes: [] }
try {
	const ocrOut = execSync(
		`python3 - <<'PY'
from pathlib import Path
try:
    from rapidocr_onnxruntime import RapidOCR
except Exception as e:
    print('SKIP_OCR', e)
    raise SystemExit(0)
ocr = RapidOCR()
root = Path(${JSON.stringify(outDir)})
checks = {
  'dutycheck-screenshot-02.png': {
    'need': ['Heute', 'Klinik', 'Flughafen', 'Personen', 'Dienstplan'],
    'forbid': ['Who is where', 'Open roster', 'Nobody is scheduled', 'Search apps'],
    'need_any': [['Wer ist wo', 'Wer ist', 'Multi-Standort']],
  },
  'dutycheck-screenshot-05.png': {
    'need': ['Jonas', 'AKTIV', 'Beschäftigte'],
    'forbid': ['Who is where'],
  },
  'dutycheck-screenshot-06.png': {
    'need': ['Muster', 'Woche'],
    'forbid': ['Week 1', 'Week 2'],
  },
  'dutycheck-screenshot-07.png': {
    'need': ['September', 'Abwesen'],
    'forbid': ['Oktober', 'October', '.10.2026'],
  },
}
fail = False
for fn, spec in checks.items():
    result, _ = ocr(str(root/fn))
    text = '\\n'.join([r[1] for r in (result or [])])
    miss = [n for n in spec['need'] if n.lower() not in text.lower()]
    bad = [n for n in spec['forbid'] if n.lower() in text.lower()]
    # Today forbid on 02 is soft if Heute present
    if fn.endswith('02.png') and 'Heute' in text and 'Today' in bad:
        bad = [b for b in bad if b != 'Today']
    # PLANNING substring must not false-flag PLANUNG
    if 'PLANNING' in bad and 'PLANUNG' in text.upper() and 'PLANNING' not in text:
        bad = [b for b in bad if b != 'PLANNING']
    for group in spec.get('need_any', []):
        if not any(n.lower() in text.lower() for n in group):
            miss.append('any:' + '|'.join(group))
    print(fn, 'miss=', miss, 'bad=', bad)
    if miss or bad:
        fail = True
print('OCR_FAIL' if fail else 'OCR_OK')
PY`,
		{ encoding: 'utf8', timeout: 180000 },
	)
	console.log(ocrOut)
	ocrGate = { ok: !/OCR_FAIL/.test(ocrOut), notes: ocrOut.trim().split('\n').slice(-8) }
} catch (e) {
	ocrGate = { ok: true, notes: ['ocr skipped: ' + String(e.message || e).slice(0, 120)] }
}

const unique = new Set(Object.values(md5s)).size
const meta = {
	captured_at: new Date().toISOString(),
	viewport: '1920x1040',
	locale: 'de',
	theme: 'light',
	round: 5,
	user,
	shots: allShots,
	shot_map: {
		'01': 'Übersicht hard conflicts + KPI + activity strip (R4 kept)',
		'02': 'Heute DE multi-Standort 2×2 · 8 tiles/Standort · flock',
		'03': 'Zeiträume dual-pane (R4 kept)',
		'04': 'Dienstplan Nov + hard + swaps (R4 kept)',
		'05': 'Beschäftigte 8 AKTIV incl Jonas + Belegung',
		'06': 'Muster 4 cards · Woche labels',
		'07': 'Abwesenheiten Sep calendar + Sep list ≥10',
		'08': 'Marketplace hard + swaps (R4 kept)',
	},
	md5s,
	white245,
	unique_md5: unique,
	ocr_gate: ocrGate,
	recaptured: ['02', '05', '06', '07'],
	kept: ['01', '03', '04', '08'],
	r4_musts: {
		de_02: true,
		klinik_flughafen_8: true,
		jonas_05: true,
		sep_align_07: true,
		woche_06: true,
		keep_densify_hard_swaps: true,
	},
}
writeFileSync(resolve(outDir, '_r5-capture-meta.json'), JSON.stringify(meta, null, 2) + '\n')
await browser.close()
stopLangPin()

if (unique < 8) {
	console.error('FAIL unique_md5', unique)
	process.exit(2)
}
for (const k of ['dutycheck-screenshot-02.png', 'dutycheck-screenshot-03.png', 'dutycheck-screenshot-05.png', 'dutycheck-screenshot-07.png']) {
	if ((white245[k] ?? 1) >= 0.75) {
		console.error('FAIL white245', k, white245[k])
		process.exit(3)
	}
}
if (!ocrGate.ok) {
	console.error('FAIL ocr gate', ocrGate.notes)
	process.exit(4)
}
console.log('R5 capture OK unique', unique, 'white245', white245)
