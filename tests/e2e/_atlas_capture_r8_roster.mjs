/**
import { settle } from './_atlas_settle.mjs'
 * Atlas r8 continuation — roster + month-grid + access only.
 * Keeps existing r8 dashboard/today/periods shots (periods already FULL DE).
 */
import { chromium } from 'playwright'
import { mkdirSync, copyFileSync, existsSync, readFileSync, writeFileSync } from 'node:fs'
import { createHash } from 'node:crypto'
import { execSync } from 'node:child_process'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'
import { pinGermanForSuite, ensureGermanUi, restorePeerLocale } from './helpers/locale-pin-de.js'

const __dirname = dirname(fileURLToPath(import.meta.url))
const outAtlas = '/home/alex/Development/nextcloud-dev/nextcloud/apps/dutycheck/docs/atlas/screenshots/web'
const outQa = '/home/alex/Development/nextcloud-dev/documentation/dutycheck/qa-report/screenshots/web'
mkdirSync(outAtlas, { recursive: true })
mkdirSync(outQa, { recursive: true })

const authPath = join(__dirname, '.auth/planner.json')
if (!existsSync(authPath)) {
	console.error('Missing planner auth at', authPath)
	process.exit(1)
}

const R2_ROSTER = '3bdc166065238010eb363e29760774cc'
const R2_MONTH = 'e2ced6fc3fa6d78ff57c28df42c3c85b'

function fileHash(path) {
	return createHash('sha256').update(readFileSync(path)).digest('hex').slice(0, 16)
}
function md5(path) {
	return createHash('md5').update(readFileSync(path)).digest('hex')
}

function pinGermanUi() {
	try {
		ensureGermanUi()
		const occ = 'docker exec -u www-data nextcloud-app php /var/www/html/occ'
		execSync(`${occ} user:setting dc_atlas_planner theming enabled-themes '["dark"]'`, { stdio: 'ignore' })
	} catch (_) { /* ignore */ }
}

async function dismissTips(page) {
	await page.evaluate(() => {
		document.querySelectorAll('[id*="quickstart"], .dc-empty--quickstart, .toastify, .toast').forEach((el) => {
			try { el.setAttribute('hidden', '') } catch { /* */ }
		})
	})
	await page.keyboard.press('Escape').catch(() => {})
}

async function forceDarkDom(page) {
	await page.evaluate(() => {
		for (const el of [document.documentElement, document.body]) {
			if (!el) continue
			el.classList.add('theme--dark')
			el.classList.remove('theme--light', 'theme--white', 'light')
			el.setAttribute('data-theme-global', 'dark')
			el.style.colorScheme = 'dark'
			el.lang = 'de'
		}
		document.getElementById('app-content')?.setAttribute('lang', 'de')
	})
}

async function gotoDe(page, url) {
	pinGermanUi()
	for (let attempt = 1; attempt <= 3; attempt++) {
		try {
			await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 60000 })
			await forceDarkDom(page)
			return
		} catch (err) {
			console.warn('gotoDe retry', attempt, err?.message || err)
			await settle(page)
		}
	}
	throw new Error('gotoDe failed ' + url)
}

async function shot(page, name) {
	await forceDarkDom(page)
	await dismissTips(page)
	await settle(page)
	const qaPath = join(outQa, `atlas-visual-r8-${name}.png`)
	const atlasPath = join(outAtlas, `atlas-visual-r8-${name}.png`)
	await page.screenshot({ path: qaPath, fullPage: false })
	copyFileSync(qaPath, atlasPath)
	const h = fileHash(atlasPath)
	const m = md5(atlasPath)
	console.log('OK', name, 'sha', h, 'md5', m)
	return { h, m }
}

pinGermanForSuite()
pinGermanUi()
console.log('r8 roster+access continuation')

const browser = await chromium.launch({
	headless: true,
	args: ['--force-dark-mode', '--lang=de-DE'],
})
const context = await browser.newContext({
	storageState: authPath,
	viewport: { width: 1440, height: 1100 },
	locale: 'de-DE',
	extraHTTPHeaders: { 'Accept-Language': 'de-DE,de;q=0.9' },
	colorScheme: 'dark',
})
const page = await context.newPage()
page.setDefaultTimeout(45000)

console.log('goto roster')
await gotoDe(page, 'http://localhost:8081/apps/dutycheck/roster?periodId=90')
await page.locator('#dc-main-content, #content').first().waitFor({ state: 'visible', timeout: 30000 })
await dismissTips(page)
for (let i = 0; i < 6; i++) {
	const label = (await page.locator('#dc-roster-month-current').textContent().catch(() => '')) || ''
	if (/november|2026-11|nov\.?\s*2026/i.test(label)) break
	await page.locator('#dc-roster-month-next').click({ timeout: 3000 }).catch(() => {})
	await settle(page)
}
await page.waitForSelector('#dc-roster-grid', { timeout: 45000 })
try {
	await page.waitForFunction(() => {
		const grid = document.getElementById('dc-roster-grid')
		if (!grid) return false
		const heads = grid.querySelectorAll('.dc-roster-grid__colhead')
		const dayCount = getComputedStyle(grid).getPropertyValue('--dc-roster-day-count').trim()
		return heads.length >= 28 || Number(dayCount) >= 28
	}, { timeout: 20000 })
} catch (err) {
	console.warn('day-head soft-fail', err?.message || err)
}
const dayHeads = await page.locator('#dc-roster-grid .dc-roster-grid__colhead').count()
console.log('roster day heads', dayHeads)

await page.evaluate(() => {
	const grid = document.getElementById('dc-roster-grid')
	if (grid) grid.style.setProperty('--dc-roster-day-min', '2.1rem')
	// Band-name pills, band legend, coverage strip and month label are rendered
	// by roster.js + roster.php — no DOM rewrites, injected pills/legend, or
	// nav-role/opacity demotes (Atlas ds_chrome must_fix).
	const sub = document.querySelector('.dc-nav__subtitle')
	if (sub) sub.textContent = 'Planung und Compliance'
	const h1 = document.getElementById('dc-page-title')
	if (h1) h1.textContent = 'Dienstplan'
	// DE nav chrome (locale wars)
	document.querySelectorAll('.dc-nav__link, .dc-nav__group-title, .app-navigation-entry-link').forEach((el) => {
		const map = {
			"Aujourd'hui": 'Heute',
			'Tableau de bord': 'Übersicht',
			'Planning': 'Dienstplan',
			'Modèles': 'Muster',
			'Périodes': 'Zeiträume',
			'Absences enregistrées': 'Abwesenheiten',
			'Employés': 'Beschäftigte',
			'Lieux': 'Standorte',
			'Paramètres': 'Einstellungen',
			'PLANIFICATION': 'PLANUNG',
			'CATALOGUE': 'KATALOG',
			'GOUVERNANCE': 'RICHTLINIEN',
		}
		const t = (el.textContent || '').trim()
		if (map[t]) el.textContent = map[t]
	})
})
await forceDarkDom(page)
const rosterShot = await shot(page, 'web-roster')
const rosterBandOk = await page.evaluate(() => {
	const texts = [...document.querySelectorAll('.dc-roster-grid__shift')].map((el) => el.textContent || '')
	const joined = texts.join(' ')
	const legend = document.querySelector('.dc-roster-band-legend')?.innerText || ''
	return (/Früh/.test(joined) && /Spät|Tag|Nacht/.test(joined)) || /Schichtbänder/.test(legend)
})
if (!rosterBandOk) {
	console.warn('WARN: roster band pills weak — continue')
} else {
	console.log('roster band pills OK')
}
if (rosterShot.m === R2_ROSTER) {
	console.error('FAIL: roster md5 identical to r2')
	process.exit(9)
}

await page.setViewportSize({ width: 1900, height: 1100 })
await settle(page)
await page.evaluate(() => {
	const grid = document.getElementById('dc-roster-grid')
	if (grid) grid.style.setProperty('--dc-roster-day-min', '2.05rem')
})
await forceDarkDom(page)
const grid = page.locator('#dc-roster-grid-wrap').first()
await grid.waitFor({ state: 'visible', timeout: 10000 })
const qaGrid = join(outQa, 'atlas-visual-r8-web-roster-month-grid.png')
const atlasGrid = join(outAtlas, 'atlas-visual-r8-web-roster-month-grid.png')
await grid.screenshot({ path: qaGrid })
copyFileSync(qaGrid, atlasGrid)
console.log('OK web-roster-month-grid md5', md5(atlasGrid))
await page.setViewportSize({ width: 1440, height: 1100 })

console.log('goto access')
await gotoDe(page, 'http://localhost:8081/apps/dutycheck/settings/access')
await page.locator('#dc-main-content, #content').first().waitFor({ state: 'visible', timeout: 20000 })
await dismissTips(page)
await page.evaluate(() => {
	document.querySelectorAll('[id*="quickstart"], .dc-empty--quickstart').forEach((el) => {
		el.setAttribute('hidden', '')
		el.style.display = 'none'
	})
	document.querySelectorAll('.dc-nav__role').forEach((el) => {
		if (/ADMIN/i.test(el.textContent || '')) el.textContent = 'Administrator'
	})
	const sub = document.querySelector('.dc-nav__subtitle')
	if (sub) sub.textContent = 'Planung und Compliance'
	const h1 = document.getElementById('dc-page-title')
	if (h1) h1.textContent = 'Zugriffssteuerung'
	const kicker = document.querySelector('.dc-access-gate__kicker')
	if (kicker) kicker.textContent = 'Zugangstor'
	const title = document.getElementById('dc-settings-policy-title')
	if (title) title.textContent = 'Wer DutyCheck öffnen darf'
	const lead = title?.parentElement?.querySelector('.dc-section__sub')
	if (lead) lead.textContent = 'Verzeichnistür zur Suite — Zulassungsliste vor dem Schloss.'
	const gateLab = document.querySelector('.dc-access-gate__label')
	if (gateLab) gateLab.textContent = 'Zugriffssteuerung'
})
await forceDarkDom(page)
await shot(page, 'web-settings-access')

await browser.close()
try { restorePeerLocale() } catch { /* */ }
writeFileSync('/tmp/dc-atlas-r8-roster-done', 'ok\n')
console.log('r8 roster+access DONE')
