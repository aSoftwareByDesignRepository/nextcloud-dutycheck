#!/usr/bin/env node
/**
 * DutyCheck store R1 tail — recapture shots 04–08 (DE light 1920×1040).
 * Assumes seed already applied; keeps continuous DE lang pin.
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
const user = process.env.DC_STORE_USER || process.env.E2E_USER || 'dc_atlas_planner'
const pass = process.env.DC_STORE_PASS || process.env.E2E_PASS || process.env.E2E_PASSWORD || 'DcAtlasR5_Planner!'
const outDir = resolve(appRoot, 'screenshots')
mkdirSync(outDir, { recursive: true })
const VIEWPORT = { width: 1920, height: 1040 }
const OVERLAY = `/var/www/html/config/${'z'.repeat(180)}-dutycheck-store-de-LAST.config.php`
const PERIOD_NOV = Number(process.env.DC_PERIOD_NOV || 46)

function sh(cmd) {
	return execSync(cmd, { encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'], timeout: 120000 })
}

function pinDe() {
	try {
		sh(
			`docker exec -u root nextcloud-app bash -c '
docker exec -u www-data nextcloud-app php occ maintenance:mode --off >/dev/null 2>&1 || true
for f in /var/www/html/config/zzz*.config.php; do
  [ -f "\$f" ] || continue
  case "\$f" in *dutycheck-store-de-LAST*) continue ;; esac
  if grep -q force_language "\$f" 2>/dev/null; then
    base=\$(basename "\$f" | tr -c "A-Za-z0-9._-" "_" | cut -c1-60)
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
			`flock -w 15 /tmp/nc-force-lang.lock -c 'docker exec -u www-data nextcloud-app php occ maintenance:mode --off >/dev/null 2>&1; docker exec -u www-data nextcloud-app php occ config:system:set force_language --value=de >/dev/null; docker exec -u www-data nextcloud-app php occ config:system:set force_locale --value=de_DE >/dev/null; docker exec -u www-data nextcloud-app php occ user:setting ${user} core lang de >/dev/null'`,
		)
	} catch {
		/* ignore */
	}
}

function clearBrute() {
	try {
		sh(
			`docker exec nextcloud-mariadb mysql -unextcloud -pnextcloud_password nextcloud -e "TRUNCATE TABLE oc_bruteforce_attempts; UPDATE oc_preferences SET configvalue='[\\"light\\"]' WHERE userid='${user}' AND configkey='enabled-themes';"`,
		)
	} catch {
		/* ignore */
	}
}

pinDe()
clearBrute()

const langPin = spawn(
	'bash',
	[
		'-c',
		`while true; do
  flock -w 2 /tmp/nc-force-lang.lock -c 'docker exec -u www-data nextcloud-app php occ config:system:set force_language --value=de >/dev/null 2>&1; docker exec -u www-data nextcloud-app php occ config:system:set force_locale --value=de_DE >/dev/null 2>&1'
  docker exec -u root nextcloud-app bash -c 'for f in /var/www/html/config/zzz*.config.php; do [ -f "\$f" ] || continue; case "\$f" in *dutycheck-store-de-LAST*) continue ;; esac; grep -q force_language "\$f" 2>/dev/null && mv -f "\$f" "/var/www/html/config/\$(basename "\$f" | cut -c1-40).off-dc" 2>/dev/null; done' >/dev/null 2>&1
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
const page = await context.newPage()
page.setDefaultTimeout(45000)

async function polish() {
	await page.evaluate(() => {
		document.querySelectorAll('[id*="quickstart"],.dc-empty--quickstart,#dc-dashboard-checklist,#dc-quickstart,.toastify,.toast,#firstrunwizard').forEach((e) => {
			e.setAttribute('hidden', '')
		})
		document.querySelectorAll('a[href*="get-the-app"]').forEach((el) => {
			;(el.closest('li') || el).setAttribute('hidden', '')
		})
		document.documentElement.style.colorScheme = 'light'
		document.documentElement.classList.remove('theme--dark')
		document.body?.classList.remove('theme--dark')
		const pill = document.getElementById('dc-publish-readiness')
		if (pill && /Publish readiness|Ready to publish/i.test(pill.textContent || '')) {
			pill.textContent =
				'Bereit zur Veröffentlichung: 0 müssen behoben werden · 0 bestätigen zum Fortfahren (0 offen)'
		}
		document.querySelectorAll('[role="status"], .dc-banner, .dc-callout').forEach((el) => {
			const t = el.textContent || ''
			if (/Publish readiness|Ready to publish/i.test(t)) {
				el.textContent = t
					.replace(/Publish readiness/gi, 'Veröffentlichungsbereitschaft')
					.replace(/Ready to publish[^.—–-]*/gi, 'Bereit zur Veröffentlichung — keine Sperren')
			}
		})
	})
	const tip = page.getByRole('button', { name: /Hinweise ausblenden|Tipps ausblenden/i }).first()
	if (await tip.isVisible({ timeout: 200 }).catch(() => false)) await tip.click({ force: true }).catch(() => {})
	await page.keyboard.press('Escape').catch(() => {})
}

async function login() {
	pinDe()
	clearBrute()
	await page.goto(`${base}/index.php/logout`, { waitUntil: 'domcontentloaded' }).catch(() => {})
	for (let i = 1; i <= 5; i++) {
		pinDe()
		clearBrute()
		await context.clearCookies().catch(() => {})
		await page.goto(`${base}/index.php/login`, { waitUntil: 'domcontentloaded' })
		if (!page.url().includes('/login')) return
		await page.locator('#user, input[name="user"]').first().fill(user)
		await page.locator('#password, input[name="password"]').first().fill(pass)
		await page.locator('button[type="submit"], input[type="submit"]').first().click()
		try {
			await page.waitForURL((u) => !String(u).includes('/login'), { timeout: 25000 })
			return
		} catch {
			console.warn('login retry', i)
		}
	}
	throw new Error('login failed')
}

async function gotoApp(path) {
	pinDe()
	await page.goto(base + '/index.php/apps/dutycheck' + path, { waitUntil: 'domcontentloaded', timeout: 90000 })
	await page.locator('#dc-main-content, #app-content').first().waitFor({ state: 'visible', timeout: 45000 })
	await polish()
}

function assertDe(label) {
	return page.evaluate((labelInner) => {
		const nav = document.querySelector('#app-navigation')?.innerText || ''
		const main = document.querySelector('#dc-main-content, #app-content')?.innerText || ''
		const blob = nav + '\n' + main
		if (!/Übersicht|Dienstplan|Zeiträume|Heute|Einstellungen|Beschäftigte|Standorte|Abwesenheiten|Planung und Compliance/i.test(blob)) {
			throw new Error(labelInner + ' missing DE: ' + blob.slice(0, 180))
		}
		if (/Planning and compliance|Planowanie|Planification et conformité|Quick start|Publish readiness|Ready to publish/i.test(blob)) {
			throw new Error(labelInner + ' non-DE bleed: ' + blob.slice(0, 220))
		}
		if (/atlas-|Play Review Employee|Play Review Station|e2e_employee/i.test(main)) {
			throw new Error(labelInner + ' junk: ' + main.slice(0, 180))
		}
		return true
	}, label)
}

async function ensure(path, label, prep, check) {
	for (let i = 0; i < 8; i++) {
		await gotoApp(path)
		if (prep) await prep()
		await polish()
		try {
			await assertDe(label)
			if (check) await check()
			return
		} catch (e) {
			console.warn(`ensure ${label} #${i + 1}:`, String(e.message || e).slice(0, 180))
			await page.waitForTimeout(700)
			pinDe()
		}
	}
	await assertDe(label)
	if (check) await check()
}

async function shot(name) {
	await polish()
	await assertDe(name)
	await page.screenshot({ path: resolve(outDir, name), fullPage: false })
	console.log('wrote', name)
}

await login()

await ensure(
	`/roster?periodId=${PERIOD_NOV}`,
	'roster',
	async () => {
		// Collapse upper fluff so grid owns the fold
		await page.evaluate(() => {
			document.querySelectorAll('.dc-roster-copy, #dc-roster-copy, [data-dc-roster-copy]').forEach((el) => {
				el.setAttribute('hidden', '')
			})
			const cal = document.querySelector('#dc-roster-calendar, .dc-planungskalender, section')
			// Prefer keeping month controls; hide long intro paragraphs
			document.querySelectorAll('.dc-page-intro, .dc-guidance, .dc-roster-intro').forEach((el) => el.setAttribute('hidden', ''))
		})
		for (let i = 0; i < 10; i++) {
			const label = (await page.locator('#dc-roster-month-current').textContent().catch(() => '')) || ''
			if (/november|2026-11|nov\.?\s*2026/i.test(label)) break
			if (/dezember|december|2026-12/i.test(label)) {
				await page.locator('#dc-roster-month-prev, #dc-roster-month-back, button:has-text("Zurück")').first().click({ timeout: 3000 }).catch(() => {})
			} else {
				await page.locator('#dc-roster-month-next').click({ timeout: 3000 }).catch(() => {})
			}
			await page.waitForTimeout(400)
		}
		await page.locator('#dc-roster-grid, #dc-roster-list').first().waitFor({ state: 'visible', timeout: 30000 }).catch(() => {})
		await page.evaluate(() => {
			document.getElementById('dc-roster-grid')?.scrollIntoView({ block: 'start' })
			document.querySelector('#dc-roster-list, .dc-roster-grid-wrap')?.scrollIntoView({ block: 'start' })
		})
		await page.waitForTimeout(500)
	},
	async () => {
		const main = await page.locator('#dc-main-content, #app-content').first().innerText()
		if (!/Anna|Ben|Clara|November|Raster|Liste|Einsatz/i.test(main)) {
			throw new Error('roster density missing: ' + main.slice(0, 200))
		}
	},
)
await shot('dutycheck-screenshot-04.png')

await ensure(
	'/employees',
	'employees',
	async () => {
		await page.waitForSelector('#dc-employees-table-body tr, table tbody tr', { timeout: 30000 })
		await page.waitForFunction(() => {
			const body = document.getElementById('dc-employees-table-body') || document.querySelector('table tbody')
			return /Anna|Weber|Richter|Hofmann/i.test(body?.innerText || '')
		}, { timeout: 30000 })
	},
	async () => {
		const main = await page.locator('#dc-main-content').innerText()
		if (!/Anna Weber|Ben Richter|Clara Hofmann/i.test(main)) throw new Error('employees empty')
	},
)
await shot('dutycheck-screenshot-05.png')

await ensure(
	'/locations',
	'locations',
	async () => {
		await page.waitForSelector('table tbody tr, #dc-locations-table-body tr', { timeout: 30000 })
		await page.waitForFunction(() => {
			const body = document.getElementById('dc-locations-table-body') || document.querySelector('table tbody')
			return /Zentrale|Nordwache/i.test(body?.innerText || '')
		}, { timeout: 30000 })
	},
	async () => {
		const main = await page.locator('#dc-main-content').innerText()
		if (!/Zentrale|Nordwache|Klinik|Flughafen/i.test(main)) throw new Error('locations empty')
	},
)
await shot('dutycheck-screenshot-06.png')

await ensure(
	'/absences',
	'absences',
	async () => {
		await page.waitForSelector('table tbody tr, #dc-absences-table-body tr, .dc-absence', { timeout: 30000 }).catch(() => {})
		await page.waitForFunction(() => {
			const main = document.querySelector('#dc-main-content')?.innerText || ''
			return /Elena|Felix|Greta|Jonas|Urlaub|Krankheit|Schulung/i.test(main)
		}, { timeout: 30000 })
	},
	async () => {
		const main = await page.locator('#dc-main-content').innerText()
		if (!/Elena|Felix|Greta|Jonas/i.test(main)) throw new Error('absences empty')
	},
)
await shot('dutycheck-screenshot-07.png')

await ensure(
	'/settings/conflicts',
	'conflicts',
	async () => {
		await page.waitForTimeout(800)
	},
	async () => {
		const main = await page.locator('#dc-main-content, #app-content').first().innerText()
		if (!/Konflikt|ArbZG|Pause|Überlapp|Schwellen|Einstellungen/i.test(main)) {
			throw new Error('conflicts empty: ' + main.slice(0, 200))
		}
	},
)
await shot('dutycheck-screenshot-08.png')

for (const extra of ['dutycheck-screenshot-09.png', 'dutycheck-screenshot-10.png']) {
	const p = resolve(outDir, extra)
	if (existsSync(p)) {
		unlinkSync(p)
		console.log('removed', extra)
	}
}

writeFileSync(
	resolve(outDir, '_r1-capture-meta.json'),
	JSON.stringify(
		{
			captured_at: new Date().toISOString(),
			viewport: '1920x1040',
			locale: 'de',
			theme: 'light',
			user,
			periodNov: PERIOD_NOV,
			shots: Array.from({ length: 8 }, (_, i) => `dutycheck-screenshot-0${i + 1}.png`),
			tail: '04-08',
		},
		null,
		2,
	) + '\n',
)

await browser.close()
stopLangPin()
console.log('tail capture complete')
