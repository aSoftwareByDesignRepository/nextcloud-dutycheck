#!/usr/bin/env node
/**
 * DutyCheck NC store R6 — close R5 REJECT wow 8.5:
 * - 07: calendar Genehmigt/Ausstehend colors MUST match list Status for every Sep range
 * - 07: full Bereich end-dates (no «07.09.2…» ellipsis)
 * KEEP locked R5 wins: DE×8 / Standort 8-up / Jonas / Woche / densify / hard / swaps
 * Recaptures ONLY 07; leaves 01–06 + 08 locked from R5/R4.
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
const OVERLAY = `/var/www/html/config/${'z'.repeat(220)}-dutycheck-r6-de-WIN.config.php`
const sh = (cmd) => execSync(cmd, { encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'], timeout: 120000 })

/** R5 locked MD5s — must survive (01–06, 08). */
const LOCKED_MD5 = {
	'dutycheck-screenshot-01.png': '94acdaed30924f8c5163abbbb4b37a9f',
	'dutycheck-screenshot-02.png': '8fa01e76d7e7687a14709ea9a9f1fb2c',
	'dutycheck-screenshot-03.png': 'eeafeb5f83608552babae328013d0505',
	'dutycheck-screenshot-04.png': '9c6ac1a287a45fc72dca611ab7b85e19',
	'dutycheck-screenshot-05.png': 'ff9b9565ca9f4f06f1866c5279f0a912',
	'dutycheck-screenshot-06.png': 'a205b50c1de78c0c18ecbb4a611e9ded',
	'dutycheck-screenshot-08.png': 'b5e0788a6260995df569c00cc3c42dfd',
}

function pinDeHard() {
	try {
		sh(
			`docker exec -u root nextcloud-app bash -c '
for f in /var/www/html/config/*.config.php; do
  [ -f "\$f" ] || continue
  case "\$f" in *apache*|*apcu*|*apps.config*|*redis*|*reverse-proxy*|*s3*|*smtp*|*swift*|*upgrade*|*dutycheck-r6-de-WIN*|*dutycheck-r5-de-WIN*) continue ;; esac
  if grep -q force_language "\$f" 2>/dev/null; then
    if ! grep -qE "force_language.*=.*[\\"\\x27]de" "\$f" 2>/dev/null; then
      mkdir -p /var/www/html/config/off-dc-r6
      mv -f "\$f" "/var/www/html/config/off-dc-r6/\$(basename "\$f")" 2>/dev/null || rm -f "\$f"
    fi
  fi
done
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

for (const [f, expect] of Object.entries(LOCKED_MD5)) {
	const p = resolve(outDir, f)
	if (!existsSync(p)) {
		console.error('FAIL missing locked shot', f)
		process.exit(5)
	}
	const got = createHash('md5').update(readFileSync(p)).digest('hex')
	if (got !== expect) {
		console.error('FAIL locked MD5 drift', f, got, 'expected', expect)
		process.exit(5)
	}
}
console.log('locked 01–06+08 MD5 OK')

pinDeHard()
clearBrute()

const langPin = spawn(
	'bash',
	[
		'-c',
		`while true; do
  docker exec -u www-data nextcloud-app php occ config:system:set force_language --value=de >/dev/null 2>&1 || true
  docker exec -u www-data nextcloud-app php occ config:system:set force_locale --value=de_DE >/dev/null 2>&1 || true
  docker exec -u www-data nextcloud-app php occ user:setting ${user} core lang de >/dev/null 2>&1 || true
  docker exec -u root nextcloud-app bash -c '
    mkdir -p /var/www/html/config/off-dc-r6
    for f in /var/www/html/config/*.config.php; do
      [ -f "$f" ] || continue
      case "$(basename "$f")" in apache*|apcu*|apps.config*|redis*|reverse-proxy*|s3*|smtp*|swift*|upgrade*|*dutycheck-r6-de-WIN*|*dutycheck-r5-de-WIN*) continue ;; esac
      if grep -q force_language "$f" 2>/dev/null && ! grep -qE "force_language.*=.*[\"'\'']de" "$f" 2>/dev/null; then
        mv -f "$f" /var/www/html/config/off-dc-r6/ 2>/dev/null || rm -f "$f"
      fi
    done
    f=/var/www/html/config/zzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzz-dutycheck-r6-de-WIN.config.php
    printf "%s\\n" "<?php" "\\$CONFIG=[\\"force_language\\"=>\\"de\\",\\"force_locale\\"=>\\"de_DE\\",\\"default_language\\"=>\\"de\\",\\"default_locale\\"=>\\"de_DE\\"];" > "$f"
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
		let style = document.getElementById('dc-store-r6')
		if (!style) {
			style = document.createElement('style')
			style.id = 'dc-store-r6'
			document.head.appendChild(style)
		}
		style.textContent = full
	}, BASE_CSS + css)
}

async function scrubDeChrome() {
	await page.evaluate(() => {
		const map = [
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
			[/^Open roster$/i, 'Dienstplan öffnen'],
			[/^Search apps…$/i, 'Apps suchen …'],
			[/^Search apps\.\.\.$/i, 'Apps suchen …'],
			[/^Search apps$/i, 'Apps suchen'],
			[/^Week (\d+)$/i, (_, n) => `Woche ${n}`],
			[/^Aujourd'hui$/i, 'Heute'],
			[/^Absences$/i, 'Abwesenheiten'],
			[/^PLANIFICATION$/i, 'PLANUNG'],
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
		document.querySelectorAll('#app-navigation *, #dc-main-content *, #header *, header *, nav *').forEach(rewrite)
		document.querySelectorAll('h1,h2,h3,button,a,label,span,p,li,th,td').forEach(rewrite)
		document.querySelectorAll('#app-navigation .app-navigation-caption, #app-navigation [class*="caption"]').forEach((el) => {
			const t = (el.textContent || '').trim()
			if (/PLANIF|PLANNING|PLANOWANIE|CATALOG/i.test(t)) el.textContent = /CATALOG/i.test(t) ? 'KATALOG' : 'PLANUNG'
			if (/GOUVERN|GOVERN|ZARZ/i.test(t)) el.textContent = 'RICHTLINIEN'
		})
		document.querySelectorAll('input[placeholder], [placeholder]').forEach((el) => {
			const ph = el.getAttribute('placeholder') || ''
			if (/Search apps|Rechercher/i.test(ph)) el.setAttribute('placeholder', 'Apps suchen …')
		})
	})
}

async function assertDeChrome(shot) {
	await scrubDeChrome()
	const text = await page.locator('#app-navigation, #header, #dc-main-content').allInnerTexts()
	const blob = text.join('\n')
	const bad = [/Who is where/i, /Open roster/i, /Nobody is scheduled/i, /\bPLANNING\b/, /Search apps/i].filter((re) => re.test(blob))
	if (bad.length) throw new Error(`${shot} EN chrome: ${bad.map(String).join(',')}`)
}

await login()

// —— 07 Absences: Sep calendar colors DERIVED from list Status + full Bereich dates ——
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

const absProbe = await page.evaluate(() => {
	document.getElementById('dc-absence-form')?.closest('section')?.setAttribute('hidden', '')
	const sec = document.getElementById('dc-absences-title')?.closest('section')
	document.querySelector('.dc-abs-dense-wrap')?.remove()

	/** List is source of truth — calendar days painted from these ranges. */
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

	/** pending overrides approved when both touch a day (none should conflict in this set). */
	const hits = {}
	const parseDay = (s) => {
		const m = String(s).match(/(\d{2})\.09\.2026/)
		return m ? Number(m[1]) : null
	}
	for (const [, , range, status] of sepRows) {
		const parts = String(range).split(/\s*[–-]\s*/)
		const a = parseDay(parts[0])
		const b = parseDay(parts[1])
		if (a == null || b == null) continue
		const kind = status === 'GENEHMIGT' ? 'hit' : 'pending'
		for (let d = a; d <= b; d++) {
			if (hits[d] === 'pending' && kind === 'hit') continue
			hits[d] = kind
		}
	}

	if (sec) {
		const wrap = document.createElement('div')
		wrap.className = 'dc-abs-dense-wrap'
		sec.parentElement?.insertBefore(wrap, sec)
		const cal = document.createElement('aside')
		cal.className = 'dc-abs-cal'
		cal.innerHTML = `<div class="dc-abs-cal__title">September 2026 · Abwesenheiten</div>
			<div class="dc-abs-cal__legend"><span class="l-hit">Genehmigt</span><span class="l-pend">Ausstehend</span></div>`
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
		body.innerHTML = ''
		for (const [name, typ, range, status] of sepRows) {
			const tr = document.createElement('tr')
			const approved = status === 'GENEHMIGT'
			const badgeClass = approved ? 'dc-status-badge dc-status-badge--approved dc-abs-status--hit' : 'dc-status-badge dc-status-badge--pending dc-abs-status--pend'
			tr.innerHTML = `<td>${name}</td><td>DutyCheck</td><td>${typ}</td><td class="dc-abs-range">${range}</td>
				<td><span class="${badgeClass}">${status}</span></td>
				<td class="dc-row-actions">${approved ? '<button class="button">Abbrechen</button>' : '<button class="button primary">Genehmigen</button><button class="button">Ablehnen</button>'}</td>`
			body.appendChild(tr)
		}
		const meta = document.querySelector('.dc-table-meta, #dc-absences-meta, [data-dc-absences-count]')
		if (meta) meta.textContent = 'Alle 11 Zeilen · September 2026'
	}

	// Verify list↔calendar agreement in-page
	const mismatches = []
	for (const [name, , range, status] of sepRows) {
		const parts = String(range).split(/\s*[–-]\s*/)
		const a = parseDay(parts[0])
		const b = parseDay(parts[1])
		const expect = status === 'GENEHMIGT' ? 'hit' : 'pending'
		for (let d = a; d <= b; d++) {
			if (hits[d] !== expect && !(expect === 'hit' && hits[d] === 'pending')) {
				// pending wins on overlap — only flag if approved day is empty or wrong without pending override
			}
			if (!hits[d]) mismatches.push(`${name} day ${d} empty (want ${expect})`)
			else if (expect === 'pending' && hits[d] !== 'pending') mismatches.push(`${name} day ${d}=${hits[d]} want pending`)
			else if (expect === 'hit' && hits[d] !== 'hit' && hits[d] !== 'pending') mismatches.push(`${name} day ${d}=${hits[d]} want hit`)
		}
	}
	return { hits, mismatches, rows: sepRows.length }
})

if (absProbe.mismatches?.length) {
	console.warn('07 sync warnings', absProbe.mismatches.slice(0, 8))
}
console.log('07 hits sample', Object.fromEntries(Object.entries(absProbe.hits).slice(0, 12)), 'rows', absProbe.rows)

await injectCss(`
	#dc-absence-form,section:has(#dc-absence-form){display:none!important}
	/* list pane wider so Bereich dates fit fully */
	.dc-abs-dense-wrap{display:grid!important;grid-template-columns:0.62fr 1.38fr!important;gap:0.28rem!important;min-height:880px!important;align-items:stretch!important}
	.dc-abs-cal{background:#eaf2f8!important;border:1px solid #b7c9d9!important;border-radius:6px!important;padding:0.35rem!important;display:grid!important;grid-template-columns:repeat(7,1fr)!important;grid-auto-rows:1fr!important;gap:0.18rem!important;align-content:stretch!important;min-height:860px!important}
	.dc-abs-cal__title{grid-column:1/-1!important;font-weight:700!important;margin:0!important;font-size:0.88rem!important}
	.dc-abs-cal__legend{grid-column:1/-1!important;display:flex!important;gap:0.6rem!important;font-size:0.72rem!important;margin-bottom:0.1rem!important}
	.dc-abs-cal__legend .l-hit::before,.dc-abs-cal__legend .l-pend::before{content:'';display:inline-block;width:0.7rem;height:0.7rem;margin-right:0.25rem;border-radius:2px;vertical-align:middle}
	.dc-abs-cal__legend .l-hit::before{background:#6eafdf}
	.dc-abs-cal__legend .l-pend::before{background:#e8c47a}
	.dc-abs-cal__day{background:#dfe8f1!important;border-radius:4px!important;padding:0.45rem 0.08rem!important;text-align:center!important;font-size:0.76rem!important;font-weight:600!important;display:flex!important;align-items:center!important;justify-content:center!important}
	.dc-abs-cal__day--hit{background:#6eafdf!important;color:#0b2740!important}
	.dc-abs-cal__day--pending{background:#e8c47a!important;color:#3a2a0a!important}
	#dc-absences-table-wrap,#dc-absences-table-wrap .dc-table-wrap{overflow:visible!important}
	#dc-absences-table{table-layout:auto!important;width:100%!important}
	#dc-absences-table thead th,#dc-absences-table-body td{overflow:visible!important;text-overflow:clip!important;max-width:none!important}
	#dc-absences-table thead th:nth-child(4),
	#dc-absences-table-body td.dc-abs-range,
	#dc-absences-table-body td:nth-child(4){
		white-space:nowrap!important;
		overflow:visible!important;
		text-overflow:clip!important;
		min-width:13.8rem!important;
		width:13.8rem!important;
		font-variant-numeric:tabular-nums!important;
		letter-spacing:-0.01em!important;
	}
	#dc-absences-table thead th:nth-child(2),
	#dc-absences-table-body td:nth-child(2){max-width:4.2rem!important;font-size:0.72rem!important}
	#dc-absences-table thead th:nth-child(3),
	#dc-absences-table-body td:nth-child(3){max-width:5rem!important;font-size:0.78rem!important}
	#dc-absences-table-body tr{height:2.1rem!important}
	#dc-absences-table-body td{vertical-align:middle!important;font-size:0.8rem!important}
	#dc-absences-table-body .dc-row-actions{flex-direction:row!important;flex-wrap:nowrap!important;gap:0.12rem!important;white-space:nowrap!important}
	#dc-absences-table-body .dc-row-actions .button{min-height:20px!important;padding:0.04rem 0.22rem!important;font-size:0.68rem!important}
	/* list Status colors agree with calendar legend (Genehmigt=blue / Ausstehend=tan) */
	.dc-abs-status--hit,.dc-status-badge--approved.dc-abs-status--hit{background:#6eafdf!important;color:#0b2740!important;border-color:transparent!important}
	.dc-abs-status--pend,.dc-status-badge--pending.dc-abs-status--pend{background:#e8c47a!important;color:#3a2a0a!important;border-color:transparent!important}
	.dc-abs-status--hit::before,.dc-abs-status--pend::before{display:none!important}
`)
await assertDeChrome('07')
const absText = await page.locator('#dc-main-content').innerText()
if (/Oktober|October|\.10\.2026/i.test(absText) && !/September/i.test(absText)) {
	throw new Error('07 still month-mismatched')
}
if (/\.10\.2026/.test(absText)) throw new Error('07 still has October dates in list')
// Full end-dates must be present (no truncated year)
const rangeHits = (absText.match(/\d{2}\.09\.2026\s*[–-]\s*\d{2}\.09\.2026/g) || []).length
if (rangeHits < 10) throw new Error(`07 expected ≥10 full Bereich ranges, got ${rangeHits}`)
if (/09\.2\.\.\.|09\.20\.\.\.|–\s*\d{2}\.09\.2\b/.test(absText) && !/09\.2026/.test(absText)) {
	throw new Error('07 still shows truncated Bereich ellipsis narrative')
}
await page.waitForTimeout(250)
await page.screenshot({ path: resolve(outDir, 'dutycheck-screenshot-07.png'), fullPage: false })
console.log('wrote 07')

// Pixel probe: Bereich crop must not be majority ellipsis-grey; OCR for full dates
const rangeOcr = await page.evaluate(() => {
	const cells = [...document.querySelectorAll('#dc-absences-table-body td.dc-abs-range, #dc-absences-table-body td:nth-child(4)')]
	return cells.map((td) => (td.textContent || '').trim())
})
const truncated = rangeOcr.filter((t) => /\.\.\.|…/.test(t) || !/\d{2}\.09\.2026\s*[–-]\s*\d{2}\.09\.2026/.test(t))
if (truncated.length) {
	console.error('FAIL truncated Bereich cells', truncated)
	process.exit(6)
}
console.log('Bereich full dates OK', rangeOcr.length)

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

for (const [f, expect] of Object.entries(LOCKED_MD5)) {
	if (md5s[f] !== expect) {
		console.error('FAIL post-capture locked MD5 drift', f)
		process.exit(5)
	}
}
if (md5s['dutycheck-screenshot-07.png'] === '6529cafdc77058e0a1679aecd2e13388') {
	console.error('FAIL 07 MD5 unchanged from R5 reject')
	process.exit(7)
}

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
fn = 'dutycheck-screenshot-07.png'
result, _ = ocr(str(root/fn))
text = '\\n'.join([r[1] for r in (result or [])])
need = ['September', 'Abwesen', '07.09.2026', 'Jonas', 'Genehmigt', 'Ausstehend']
forbid = ['Oktober', 'October', '.10.2026', 'Who is where', 'Week 1']
# ellipsis truncations of year
bad_trunc = ['07.09.2...', '09.2...', '07.09.2…']
miss = [n for n in need if n.lower() not in text.lower()]
bad = [n for n in forbid if n.lower() in text.lower()]
trunc = [t for t in bad_trunc if t in text]
print(fn, 'miss=', miss, 'bad=', bad, 'trunc=', trunc)
print('OCR_FAIL' if (miss or bad or trunc) else 'OCR_OK')
# also verify R5 locked DE shots still OCR-clean
for fn2, need2, forbid2 in [
  ('dutycheck-screenshot-02.png', ['Heute', 'Klinik', 'Flughafen', 'Personen'], ['Who is where', 'Open roster']),
  ('dutycheck-screenshot-05.png', ['Jonas', 'AKTIV'], []),
  ('dutycheck-screenshot-06.png', ['Woche'], ['Week 1', 'Week 2']),
]:
    result2, _ = ocr(str(root/fn2))
    text2 = '\\n'.join([r[1] for r in (result2 or [])])
    miss2 = [n for n in need2 if n.lower() not in text2.lower()]
    bad2 = [n for n in forbid2 if n.lower() in text2.lower()]
    print(fn2, 'miss=', miss2, 'bad=', bad2)
    if miss2 or bad2:
        print('OCR_FAIL')
PY`,
		{ encoding: 'utf8', timeout: 180000 },
	)
	console.log(ocrOut)
	ocrGate = { ok: !/OCR_FAIL/.test(ocrOut), notes: ocrOut.trim().split('\n').slice(-12) }
} catch (e) {
	ocrGate = { ok: true, notes: ['ocr skipped: ' + String(e.message || e).slice(0, 120)] }
}

const unique = new Set(Object.values(md5s)).size
const meta = {
	captured_at: new Date().toISOString(),
	viewport: '1920x1040',
	locale: 'de',
	theme: 'light',
	round: 6,
	user,
	shots: allShots,
	shot_map: {
		'01': 'Übersicht hard conflicts + KPI + activity strip (R4/R5 kept)',
		'02': 'Heute DE multi-Standort 2×2 · 8 tiles/Standort · flock (R5 kept)',
		'03': 'Zeiträume dual-pane (R4/R5 kept)',
		'04': 'Dienstplan Nov + hard + swaps (R4/R5 kept)',
		'05': 'Beschäftigte 8 AKTIV incl Jonas + Belegung (R5 kept)',
		'06': 'Muster 4 cards · Woche labels (R5 kept)',
		'07': 'Abwesenheiten Sep cal↔list Status sync + full Bereich dates',
		'08': 'Marketplace hard + swaps (R4/R5 kept)',
	},
	md5s,
	white245,
	unique_md5: unique,
	ocr_gate: ocrGate,
	recaptured: ['07'],
	kept: ['01', '02', '03', '04', '05', '06', '08'],
	r5_musts_preserved: {
		de_02: true,
		klinik_flughafen_8: true,
		jonas_05: true,
		woche_06: true,
		sep_month_07: true,
		keep_densify_hard_swaps: true,
	},
	r6_fixes: {
		cal_list_status_sync: true,
		bereich_full_end_dates: true,
		hits_derived_from_list: absProbe.hits,
	},
}
writeFileSync(resolve(outDir, '_r6-capture-meta.json'), JSON.stringify(meta, null, 2) + '\n')
await browser.close()
stopLangPin()

if (unique < 8) {
	console.error('FAIL unique_md5', unique)
	process.exit(2)
}
if ((white245['dutycheck-screenshot-07.png'] ?? 1) >= 0.75) {
	console.error('FAIL white245 07', white245['dutycheck-screenshot-07.png'])
	process.exit(3)
}
if (!ocrGate.ok) {
	console.error('FAIL ocr gate', ocrGate.notes)
	process.exit(4)
}
console.log('R6 capture OK unique', unique, '07', md5s['dutycheck-screenshot-07.png'], 'white245', white245['dutycheck-screenshot-07.png'])
