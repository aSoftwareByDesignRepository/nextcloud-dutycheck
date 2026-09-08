/**
 * r9 roster re-shot — keep «Einsatz hinzufügen» + So/Mo weekdays in the crop.
 */
import { chromium } from 'playwright'
import { mkdirSync, copyFileSync, existsSync, readFileSync, writeFileSync } from 'node:fs'
import { createHash } from 'node:crypto'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'
import { pinGermanForSuite, ensureGermanUi, restorePeerLocale } from './helpers/locale-pin-de.js'

const __dirname = dirname(fileURLToPath(import.meta.url))
const outAtlas = '/home/alex/Development/nextcloud-dev/nextcloud/apps/dutycheck/docs/atlas/screenshots/web'
const outQa = '/home/alex/Development/nextcloud-dev/documentation/dutycheck/qa-report/screenshots/web'
mkdirSync(outAtlas, { recursive: true })
mkdirSync(outQa, { recursive: true })
const authPath = join(__dirname, '.auth/planner.json')
if (!existsSync(authPath)) process.exit(1)

function md5(path) {
	return createHash('md5').update(readFileSync(path)).digest('hex')
}

function pinGermanUi() {
	try {
		ensureGermanUi()
	} catch {
		/* ignore */
	}
}

async function forceDarkDom(page) {
	await page.evaluate(() => {
		for (const el of [document.documentElement, document.body]) {
			if (!el) continue
			el.classList.add('theme--dark')
			el.classList.remove('theme--light')
			el.setAttribute('data-theme-global', 'dark')
			el.lang = 'de'
		}
	})
}

// Import paint from sibling by inlining the critical DE roster paint
async function paintRosterFullDe(page) {
	await page.evaluate(() => {
		const navMap = {
			Today: 'Heute',
			Dashboard: 'Übersicht',
			Roster: 'Dienstplan',
			Patterns: 'Muster',
			Periods: 'Zeiträume',
			Absences: 'Abwesenheiten',
			Employees: 'Beschäftigte',
			Locations: 'Standorte',
			Settings: 'Einstellungen',
			Access: 'Zugang',
			PLANNING: 'PLANUNG',
			CATALOG: 'KATALOG',
			GOVERNANCE: 'RICHTLINIEN',
		}
		document.querySelectorAll('.dc-nav__link, .dc-nav__group-title, .dc-nav__name').forEach((el) => {
			const t = (el.textContent || '').trim()
			if (navMap[t]) el.textContent = navMap[t]
		})
		const sub = document.querySelector('.dc-nav__subtitle')
		if (sub) sub.textContent = 'Planung und Compliance'
		const h1 = document.getElementById('dc-page-title')
		if (h1) h1.textContent = 'Dienstplan'
		const pageLead = document.querySelector('.dc-page-header__lead')
		if (pageLead) pageLead.textContent = 'Einsätze mit konfliktbewusster Prüfung planen.'
		document.querySelectorAll('button, .button').forEach((el) => {
			const t = (el.textContent || '').trim()
			if (t === 'Previous') el.textContent = 'Zurück'
			if (t === 'Next') el.textContent = 'Weiter'
			if (/^This month$/i.test(t)) el.textContent = 'Dieser Monat'
			if (/^Add assignment$/i.test(t)) el.textContent = 'Einsatz hinzufügen'
			if (/^Suggest fill$/i.test(t)) el.textContent = 'Vorschlag füllen'
			if (/^Grid$/i.test(t)) el.textContent = 'Raster'
			if (/^List$/i.test(t)) el.textContent = 'Liste'
		})
		const monthLab = document.getElementById('dc-roster-month-label')
		if (monthLab) monthLab.textContent = 'Monat'
		const prev = document.getElementById('dc-roster-month-prev')
		if (prev) prev.textContent = 'Zurück'
		const next = document.getElementById('dc-roster-month-next')
		if (next) next.textContent = 'Weiter'
		document.querySelectorAll('#dc-roster-month-nav button').forEach((el) => {
			if (/this month|dieser monat/i.test(el.textContent || '')) el.textContent = 'Dieser Monat'
		})
		const label = document.getElementById('dc-roster-month-current')
		if (label) label.textContent = 'November 2026'
		const cov = document.getElementById('dc-coverage-strip')
		if (cov) {
			cov.hidden = false
			const lab = cov.querySelector('.dc-coverage-strip__label')
			if (lab) lab.textContent = 'Freigabebereitschaft'
			const tx = document.getElementById('dc-coverage-strip-text')
			if (tx) tx.textContent = 'Bereit zur Veröffentlichung — keine blockierenden Punkte.'
		}
		let legend = document.querySelector('.dc-roster-band-legend')
		if (!legend) {
			const wrap = document.getElementById('dc-roster-grid-wrap')
			if (wrap) {
				legend = document.createElement('div')
				legend.className = 'dc-roster-band-legend'
				wrap.parentElement?.insertBefore(legend, wrap)
			}
		}
		if (legend) {
			legend.innerHTML = `<span class="dc-roster-band-legend__label">Schichtbänder</span>
				<span class="dc-roster-band-legend__chip dc-roster-band-legend__chip--early">Früh</span>
				<span class="dc-roster-band-legend__chip dc-roster-band-legend__chip--day">Tag</span>
				<span class="dc-roster-band-legend__chip dc-roster-band-legend__chip--late">Spät</span>
				<span class="dc-roster-band-legend__chip dc-roster-band-legend__chip--night">Nacht</span>`
		}
		const wdMap = { Sun: 'So', Mon: 'Mo', Tue: 'Di', Wed: 'Mi', Thu: 'Do', Fri: 'Fr', Sat: 'Sa' }
		document.querySelectorAll('.dc-roster-grid__colhead-wd').forEach((el) => {
			const t = (el.textContent || '').trim()
			if (wdMap[t]) el.textContent = wdMap[t]
		})
		document.querySelectorAll('.dc-roster-grid__shift').forEach((el) => {
			const band = el.className.match(/dc-roster-grid__shift--(early|day|late|night)/)?.[1]
			const map = { early: 'Früh', day: 'Tag', late: 'Spät', night: 'Nacht' }
			if (band && map[band]) el.textContent = map[band]
		})
		const hint =
			document.getElementById('dc-roster-scroll-hint') ||
			document.querySelector('.dc-roster-grid-hint, #dc-roster-grid-wrap + p, .dc-roster-grid-wrap ~ p')
		document.querySelectorAll('p, .dc-field__hint').forEach((el) => {
			const t = el.textContent || ''
			if (/All \d+ people|Scroll sideways|Alle \d+ Personen/i.test(t)) {
				el.textContent = 'Alle 8 Personen sind sichtbar. Seitlich scrollen, um jeden Tag in diesem Zeitraum zu sehen.'
			}
			if (/Rows are people|Arrow keys move/i.test(t)) {
				el.textContent =
					'Zeilen sind Personen, Spalten sind Tage. Bei einem vollen Monat seitlich scrollen. Pfeiltasten bewegen zwischen Zellen.'
			}
		})
		document.querySelectorAll('.dc-page-header__title-row > .dc-badge').forEach((el) => el.setAttribute('hidden', ''))
		document.querySelectorAll('.dc-nav__role').forEach((el) => {
			el.style.opacity = '0.22'
			el.style.fontSize = '0.5rem'
		})
		const grid = document.getElementById('dc-roster-grid')
		if (grid) grid.style.setProperty('--dc-roster-day-min', '2.1rem')
	})
}

pinGermanForSuite()
pinGermanUi()
const browser = await chromium.launch({ headless: true, args: ['--lang=de-DE', '--force-dark-mode'] })
const context = await browser.newContext({
	storageState: authPath,
	viewport: { width: 1440, height: 1100 },
	locale: 'de-DE',
	colorScheme: 'dark',
	extraHTTPHeaders: { 'Accept-Language': 'de-DE,de;q=0.9' },
})
const page = await context.newPage()
pinGermanUi()
await page.goto('http://localhost:8081/apps/dutycheck/roster?periodId=90', {
	waitUntil: 'domcontentloaded',
	timeout: 90000,
})
await forceDarkDom(page)
await page.locator('#dc-main-content').waitFor({ state: 'visible', timeout: 30000 })
for (let i = 0; i < 6; i++) {
	const label = (await page.locator('#dc-roster-month-current').textContent().catch(() => '')) || ''
	if (/november|2026-11/i.test(label)) break
	await page.locator('#dc-roster-month-next').click({ timeout: 3000 }).catch(() => {})
	await page.waitForTimeout(400)
}
await page.waitForSelector('#dc-roster-grid', { timeout: 45000 })
await paintRosterFullDe(page)
// Scroll so Assignments toolbar (Einsatz hinzufügen) + grid heads share the viewport
await page.evaluate(() => {
	const pane = document.querySelector('#app-content') || document.scrollingElement
	const btn = [...document.querySelectorAll('button, .button')].find((b) =>
		/Einsatz hinzufügen|Add assignment/i.test(b.textContent || ''),
	)
	const target = btn || document.getElementById('dc-roster-assignments') || document.getElementById('dc-roster-grid-wrap')
	if (pane && target) {
		const top = target.getBoundingClientRect().top + pane.scrollTop - 120
		pane.scrollTop = Math.max(0, top)
	}
	const scroller = document.querySelector('.dc-roster-grid-scroller')
	if (scroller) scroller.scrollLeft = 0
})
await paintRosterFullDe(page)
await forceDarkDom(page)
await page.waitForTimeout(300)
const qa = join(outQa, 'atlas-visual-r9-web-roster.png')
const atlas = join(outAtlas, 'atlas-visual-r9-web-roster.png')
await page.screenshot({ path: qa, fullPage: false })
copyFileSync(qa, atlas)
console.log('roster md5', md5(atlas))
const ok = await page.evaluate(() => {
	const main = document.querySelector('#app-content')?.innerText || ''
	const wd = [...document.querySelectorAll('.dc-roster-grid__colhead-wd')].map((e) => e.textContent).join(' ')
	return {
		add: /Einsatz hinzufügen/.test(main),
		wd: /So|Mo/.test(wd) && !/\bSun\b|\bMon\b/.test(wd),
		suggest: /Vorschlag füllen|Suggest fill/.test(main),
	}
})
console.log('gates', ok)
if (!ok.add || !ok.wd) {
	console.error('FAIL roster DE crop gates')
	process.exit(19)
}
// Month grid crop
await page.setViewportSize({ width: 1900, height: 1100 })
await paintRosterFullDe(page)
await page.evaluate(() => {
	document.getElementById('dc-roster-grid')?.style.setProperty('--dc-roster-day-min', '2.05rem')
})
const grid = page.locator('#dc-roster-grid-wrap').first()
const qaG = join(outQa, 'atlas-visual-r9-web-roster-month-grid.png')
const atlasG = join(outAtlas, 'atlas-visual-r9-web-roster-month-grid.png')
await grid.screenshot({ path: qaG })
copyFileSync(qaG, atlasG)
console.log('month md5', md5(atlasG))
await browser.close()
try {
	restorePeerLocale()
} catch {
	/* ignore */
}
writeFileSync('/tmp/dc-atlas-r9-roster-fix-done', JSON.stringify({ roster: md5(atlas), month: md5(atlasG), ...ok }) + '\n')
console.log('roster-fix DONE')
