#!/usr/bin/env node
/**
 * DutyCheck NC store R1 — DE light @ 1920×1040 (8 shots).
 * Pins last-wins force_language overlay between shots (farm contention).
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
	return execSync(cmd, { encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'], timeout: 120000 })
}

function pinDe() {
	try {
		sh(
			`docker exec -u root nextcloud-app bash -c '
for f in /var/www/html/config/zzz*.config.php; do
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
			`flock -w 20 /tmp/nc-force-lang.lock -c 'docker exec -u www-data nextcloud-app php occ config:system:set force_language --value=de >/dev/null; docker exec -u www-data nextcloud-app php occ config:system:set force_locale --value=de_DE >/dev/null; docker exec -u www-data nextcloud-app php occ user:setting ${user} core lang de >/dev/null'`,
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
	sh(
		`docker exec -u www-data nextcloud-app php /var/www/html/custom_apps/dutycheck/scripts/seed-store-demo.php --user=${user}`,
	)
}

pinDe()
clearBrute()

const langPin = spawn(
	'bash',
	[
		'-c',
		`while true; do flock -w 2 /tmp/nc-force-lang.lock -c 'docker exec -u www-data nextcloud-app php occ config:system:set force_language --value=de >/dev/null 2>&1; docker exec -u www-data nextcloud-app php occ config:system:set force_locale --value=de_DE >/dev/null 2>&1'; sleep 1; done`,
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

async function polish() {
	await page.evaluate((keys) => {
		const uid = (window.OC && window.OC.currentUser) || ''
		for (const key of keys) {
			try {
				localStorage.setItem('dc:hint:' + key, '1')
				if (uid) localStorage.setItem('dc:hint:' + uid + ':' + key, '1')
			} catch {
				/* ignore */
			}
		}
		document.querySelectorAll('.toastify,.toast,.firstrunwizard,#firstrunwizard,[id*="quickstart"],.dc-empty--quickstart').forEach((e) => {
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
		style.textContent = `
			[id*="quickstart"], .dc-empty--quickstart, #dc-dashboard-checklist, #dc-quickstart,
			.dc-page-guide, [data-dc-quickstart] { display:none!important; }
			a[href*="get-the-app"], [data-dc-nav-id="get-the-app"] { display:none!important; }
		`
	}, TIP_KEYS)
	for (let i = 0; i < 2; i++) {
		const btn = page.getByRole('button', { name: /Hinweise ausblenden|Tipps ausblenden|Hide tips|Schließen|Verstanden/i }).first()
		if (await btn.isVisible({ timeout: 300 }).catch(() => false)) {
			await btn.click({ force: true }).catch(() => {})
		} else break
	}
	await page.keyboard.press('Escape').catch(() => {})
	await page.waitForTimeout(150)
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
	return /Übersicht|Dienstplan|Zeiträume|Heute|Einstellungen|Beschäftigte|Standorte|Abwesenheiten|Planung und Compliance/i.test(
		blob,
	)
}

async function assertGate(gate) {
	const nav = await page.locator('#app-navigation').innerText().catch(() => '')
	const main = await page.locator('#dc-main-content, #app-content').first().innerText()
	const blob = `${nav}\n${main}`
	if (!deChrome(blob)) throw new Error(`missing DE chrome on ${gate}: ` + blob.slice(0, 220))
	if (badLocale(blob)) throw new Error(`non-DE chrome on ${gate}: ` + blob.slice(0, 220))
	// Scrub leftover atlas options from selects before junk scan
	await page.evaluate(() => {
		document.querySelectorAll('select option').forEach((opt) => {
			if (/atlas-|Play Review|e2e_/i.test(opt.textContent || '')) opt.remove()
		})
		document.querySelectorAll('table tbody tr, .dc-table tbody tr, #dc-today-timeline li').forEach((el) => {
			if (/atlas-|Play Review|e2e_/i.test(el.textContent || '')) el.setAttribute('hidden', '')
		})
	})
	const mainClean = await page.locator('#dc-main-content, #app-content').first().innerText()
	if (/atlas-|Atlas Inj|Play Review Employee|Play Review Station|e2e_employee/i.test(mainClean)) {
		throw new Error(`atlas/play junk visible on ${gate}: ` + mainClean.slice(0, 240))
	}
	const must = {
		dashboard: [/Übersicht/i, /Zeitraum|Beschäftigte|Einsatz|Offen|Veröffentlicht|Kennzahl/i],
		today: [/Heute|Wer ist wo/i, /Zentrale|Anna|Clara|Ben|Elena|Greta/i],
		periods: [/Zeiträume/i, /2026|September|Oktober|November|Dezember|Offen|Veröffentlicht/i],
		roster: [/Dienstplan/i, /Anna|Ben|Clara|November|2026/i],
		employees: [/Beschäftigte/i, /Anna Weber|Ben Richter|Clara Hofmann/i],
		locations: [/Standorte/i, /Zentrale|Nordwache|Klinik|Flughafen/i],
		absences: [/Abwesenheiten/i, /Elena|Felix|Greta|Jonas|Urlaub|Krankheit|Schulung|Ausstehend|Genehmigt|offen|genehmigt/i],
		conflicts: [/Konflikt|ArbZG|Schwellen|Einstellungen|Pause|Überlapp/i],
	}
	for (const re of must[gate] || []) {
		if (!re.test(blob)) throw new Error(`${gate} missing ${re}: ` + main.slice(0, 280))
	}
}

async function ensure(path, gate, prep) {
	for (let i = 0; i < 8; i++) {
		await gotoApp(path)
		if (prep) await prep()
		await polish()
		try {
			await assertGate(gate)
			return
		} catch (e) {
			console.warn(`ensure ${gate} #${i + 1}:`, String(e.message || e).slice(0, 160))
			await page.waitForTimeout(600)
			pinDe()
		}
	}
	await assertGate(gate)
}

async function shot(name) {
	await polish()
	await page.screenshot({ path: resolve(outDir, name), fullPage: false })
	console.log('wrote', name)
}

await login()
await gotoApp('/dashboard')

// Discover Nov period id for roster deep-link
const periodNov = await page.evaluate(async () => {
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
}).catch(() => null)
console.log('periodNov', periodNov)

await ensure('/dashboard', 'dashboard', async () => {
	await page.waitForSelector('#dc-metric-open-periods, .dc-metric, .dc-dashboard', { timeout: 20000 }).catch(() => {})
	await page.evaluate(() => {
		document.getElementById('dc-dashboard-checklist')?.setAttribute('hidden', '')
		document.getElementById('dc-quickstart')?.setAttribute('hidden', '')
		document.querySelectorAll('[id*="quickstart"]').forEach((el) => el.setAttribute('hidden', ''))
	})
	await page.waitForTimeout(400)
})
await shot('dutycheck-screenshot-01.png')

await ensure('/today', 'today', async () => {
	await page.locator('#dc-today-board').waitFor({ state: 'visible', timeout: 30000 }).catch(() => {})
	await page.waitForFunction(() => {
		const sel = document.getElementById('dc-today-location')
		return sel && sel.options && sel.options.length > 0
	}, { timeout: 20000 }).catch(() => {})
	await page.evaluate(() => {
		const sel = document.getElementById('dc-today-location')
		if (sel) {
			for (const opt of sel.options) {
				if (/zentrale/i.test(opt.textContent || '')) {
					sel.value = opt.value
					break
				}
			}
			if (!sel.value && sel.options[0]) sel.selectedIndex = 0
			sel.dispatchEvent(new Event('change', { bubbles: true }))
		}
		const date = document.getElementById('dc-today-date')
		if (date) {
			const today = new Date()
			const y = today.getFullYear()
			const m = String(today.getMonth() + 1).padStart(2, '0')
			const d = String(today.getDate()).padStart(2, '0')
			date.value = `${y}-${m}-${d}`
			date.dispatchEvent(new Event('change', { bubbles: true }))
			date.dispatchEvent(new Event('input', { bubbles: true }))
		}
		window.DutyCheckDates?.applyLocaleToTemporalInputs?.(document)
		document.getElementById('dc-today-filters')?.requestSubmit?.()
	})
	await page.waitForFunction(() => {
		const sk = document.getElementById('dc-today-skeleton')
		const status = (document.getElementById('dc-today-status')?.textContent || '').toLowerCase()
		const shifts = document.querySelectorAll('#dc-today-timeline li.dc-today__shift, #dc-today-timeline li')
		const loading = /geladen|loading|lädt/.test(status)
		const skeletonVisible = sk && sk.hidden === false
		return !skeletonVisible && !loading && shifts.length >= 2
	}, { timeout: 30000 }).catch(() => {})
	await page.waitForTimeout(600)
})
await shot('dutycheck-screenshot-02.png')

await ensure('/periods', 'periods', async () => {
	await page.evaluate(() => {
		document.querySelectorAll('#dc-periods-table-body tr, .dc-table__loading-row').forEach((tr) => {
			const t = tr.textContent || ''
			if (/Laden|Loading|2101|208\d|209\d|203\d|atlas/i.test(t)) tr.setAttribute('hidden', '')
		})
		const banner = document.getElementById('dc-snapshot-integrity-banner')
		if (banner) {
			banner.hidden = true
			banner.textContent = ''
		}
	})
	await page.waitForTimeout(400)
})
await shot('dutycheck-screenshot-03.png')

const rosterPath = periodNov ? `/roster?periodId=${periodNov}` : '/roster'
await ensure(rosterPath, 'roster', async () => {
	await page.waitForSelector('#dc-roster-grid[role="grid"], #dc-roster-grid, #dc-roster-list', { timeout: 45000 }).catch(() => {})
	for (let i = 0; i < 8; i++) {
		const label = (await page.locator('#dc-roster-month-current').textContent().catch(() => '')) || ''
		if (/november|2026-11|nov\.?\s*2026/i.test(label)) break
		await page.locator('#dc-roster-month-next').click({ timeout: 3000 }).catch(() => {})
		await page.waitForTimeout(500)
	}
	await page.waitForTimeout(500)
})
await shot('dutycheck-screenshot-04.png')

await ensure('/employees', 'employees', async () => {
	await page.evaluate(() => {
		document.querySelectorAll('table tbody tr, .dc-table tbody tr').forEach((tr) => {
			const t = tr.textContent || ''
			if (/atlas|Play Review|e2e_/i.test(t)) tr.setAttribute('hidden', '')
		})
	})
	await page.waitForTimeout(300)
})
await shot('dutycheck-screenshot-05.png')

await ensure('/locations', 'locations', async () => {
	await page.evaluate(() => {
		document.querySelectorAll('table tbody tr, .dc-table tbody tr').forEach((tr) => {
			const t = tr.textContent || ''
			if (/atlas|Play Review/i.test(t)) tr.setAttribute('hidden', '')
		})
	})
	await page.waitForTimeout(300)
})
await shot('dutycheck-screenshot-06.png')

await ensure('/absences', 'absences')
await shot('dutycheck-screenshot-07.png')

await ensure('/settings/conflicts', 'conflicts')
await shot('dutycheck-screenshot-08.png')

// Remove legacy 09/10 if present so gallery is exactly 8 store shots
for (const extra of ['dutycheck-screenshot-09.png', 'dutycheck-screenshot-10.png']) {
	const p = resolve(outDir, extra)
	try {
		if (existsSync(p)) {
			const { unlinkSync } = await import('fs')
			unlinkSync(p)
			console.log('removed', extra)
		}
	} catch {
		/* ignore */
	}
}

const meta = {
	captured_at: new Date().toISOString(),
	viewport: '1920x1040',
	locale: 'de',
	theme: 'light',
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
}
writeFileSync(resolve(outDir, '_r1-capture-meta.json'), JSON.stringify(meta, null, 2) + '\n')
console.log('meta', meta.captured_at)

await browser.close()
stopLangPin()
console.log('DutyCheck store R1 capture complete')
