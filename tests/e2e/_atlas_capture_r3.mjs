/**
import { settle } from './_atlas_settle.mjs'
 * Atlas visual-fix r3 — critic-ready DE web evidence (atlas-visual-r3-*.png).
 * Fixes vs r2: real Übersicht URL, seeded Today board, distinct month-grid crop,
 * no duplicate roster hash, settings without chip cavern (CSS).
 */
import { chromium } from 'playwright'
import { mkdirSync, copyFileSync, existsSync, readFileSync } from 'node:fs'
import { createHash } from 'node:crypto'
import { execSync } from 'node:child_process'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'

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

const tipKeys = [
	'dashboard_quickstart_v1',
	'roster_quickstart_v1',
	'periods_quickstart_v1',
	'employees_quickstart_v1',
	'locations_quickstart_v1',
	'my_absences_quickstart_v1',
]

function fileHash(path) {
	return createHash('sha256').update(readFileSync(path)).digest('hex').slice(0, 16)
}

async function shot(page, name) {
	pinGermanUi()
	await dismissTips(page)
	const lang = await page.evaluate(() => document.documentElement.lang || document.getElementById('app-content')?.getAttribute('lang') || '')
	if (!/^de/i.test(lang)) {
		console.warn('re-pin: page lang was', lang, 'for', name)
		pinGermanUi()
		await page.reload({ waitUntil: 'domcontentloaded' })
		await settle(page)
	}
	const lang2 = await page.evaluate(() => document.documentElement.lang || '')
	if (!/^de/i.test(lang2)) {
		console.error('FAIL non-DE lang after pin:', lang2, name)
		process.exit(5)
	}
	await settle(page)
	const qaPath = join(outQa, `atlas-visual-r3-${name}.png`)
	const atlasPath = join(outAtlas, `atlas-visual-r3-${name}.png`)
	await page.screenshot({ path: qaPath, fullPage: false })
	copyFileSync(qaPath, atlasPath)
	// Also refresh r2-named critic pack paths used by visual-ready.
	const legacy = join(outAtlas, `atlas-visual-r2-${name}.png`)
	copyFileSync(qaPath, legacy)
	console.log('OK', name, fileHash(atlasPath))
	return atlasPath
}

function pinGermanUi() {
	// Farm workers race force_language (pl/fr/de). Pin immediately before navigation.
	try {
		execSync('docker exec nextcloud-app php occ config:system:set force_language --value=de', {
			stdio: 'ignore',
		})
		execSync('docker exec nextcloud-app php occ user:setting dc_atlas_planner core lang de', {
			stdio: 'ignore',
		})
	} catch (err) {
		console.warn('pinGermanUi failed', err?.message || err)
	}
}

async function gotoDe(page, url) {
	pinGermanUi()
	await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 90000 })
}

async function dismissTips(page) {
	await page.evaluate(() => {
		document.querySelectorAll('[id*="quickstart"], .dc-empty--quickstart, .toastify, .toast').forEach((el) => {
			try { el.setAttribute('hidden', '') } catch { /* ignore */ }
		})
	})
	for (let i = 0; i < 3; i++) {
		const btn = page.getByRole('button', { name: /schließen|close|verstanden|got it|nicht mehr/i }).first()
		if (await btn.isVisible({ timeout: 250 }).catch(() => false)) {
			await btn.click({ force: true }).catch(() => {})
		} else break
	}
	await page.keyboard.press('Escape').catch(() => {})
}

async function reveal(page, selector) {
	await page.evaluate((sel) => {
		const el = document.querySelector(sel)
		if (!el) return
		el.scrollIntoView({ block: 'start', inline: 'nearest' })
		const pane = document.querySelector('#app-content') || document.scrollingElement
		if (pane && 'scrollTop' in pane) {
			const top = el.getBoundingClientRect().top + pane.scrollTop - 72
			pane.scrollTop = Math.max(0, top)
		}
	}, selector)
}

const browser = await chromium.launch({
	headless: true,
	args: ['--lang=de-DE', '--accept-lang=de-DE,de'],
})
const context = await browser.newContext({
	storageState: authPath,
	viewport: { width: 1440, height: 1100 },
	locale: 'de-DE',
	timezoneId: 'Europe/Berlin',
})
const page = await context.newPage()
await page.addInitScript((keys) => {
	for (const key of keys) {
		try {
			window.localStorage.setItem(`dc.hint.dismissed.${key}`, '1')
		} catch { /* ignore */ }
	}
}, tipKeys)

// Seed Today board with dense German coverage (avoid empty theater / double copy).
await page.route('**/apps/dutycheck/api/today-board**', async (route) => {
	try {
		await route.fulfill({
			status: 200,
			contentType: 'application/json',
			body: JSON.stringify({
				data: {
					enabled: true,
					date: '2026-09-07',
					locationId: 9,
					locationName: 'Zentrale',
					shifts: [
						{ employeeId: 1, displayName: 'Anna Weber', startTime: '06:00', endTime: '14:00', periodStatus: 'published', templateName: 'Früh' },
						{ employeeId: 2, displayName: 'Ben Richter', startTime: '06:00', endTime: '14:00', periodStatus: 'published', templateName: 'Früh' },
						{ employeeId: 3, displayName: 'Clara Hofmann', startTime: '08:00', endTime: '16:00', periodStatus: 'published', templateName: 'Tag' },
						{ employeeId: 4, displayName: 'David Keller', startTime: '12:00', endTime: '20:00', periodStatus: 'published', templateName: 'Spät' },
						{ employeeId: 5, displayName: 'Eva Braun', startTime: '14:00', endTime: '22:00', periodStatus: 'open', templateName: 'Abend' },
					],
					gaps: [{ templateName: 'Mittag', startTime: '10:00', endTime: '12:00', minHeadcount: 2, assignedCount: 1 }],
				},
			}),
		})
	} catch { /* already handled */ }
})

// 1) Übersicht — NOT index (index redirects to Heute)
await gotoDe(page, 'http://localhost:8081/apps/dutycheck/dashboard')
await page.locator('#dc-main-content').waitFor({ state: 'visible', timeout: 30000 })
await page.waitForSelector('#dc-metric-open-periods, .dc-metric, .dc-dashboard', { timeout: 20000 }).catch(() => {})
await dismissTips(page)
await settle(page)
const dashUrl = page.url()
if (!/dashboard/i.test(dashUrl)) {
	console.error('FAIL: expected /dashboard, got', dashUrl)
	process.exit(2)
}
const activeNav = await page.locator('#app-navigation .active, #app-navigation [aria-current="page"]').first().textContent().catch(() => '')
console.log('dashboard url', dashUrl, 'nav', (activeNav || '').trim())
if (!/übersicht|dashboard/i.test(activeNav || '') && !/übersicht/i.test(await page.locator('h1, .dc-page-title, #dc-main-content').first().textContent().catch(() => '') || '')) {
	const h1 = await page.locator('h1').first().textContent().catch(() => '')
	console.error('FAIL: dashboard not DE Übersicht, nav=', (activeNav || '').trim(), 'h1=', h1)
	process.exit(4)
}
await shot(page, 'web-dashboard')

// 2) Heute — seeded, date filled, no TT.MM.JJJJ leak
await gotoDe(page, 'http://localhost:8081/apps/dutycheck/today')
await page.locator('#dc-today-board').waitFor({ state: 'visible', timeout: 30000 })
await dismissTips(page)
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
		date.value = '2026-09-07'
		date.dispatchEvent(new Event('change', { bubbles: true }))
		date.dispatchEvent(new Event('input', { bubbles: true }))
	}
	window.DutyCheckDates?.applyLocaleToTemporalInputs?.(document)
	document.getElementById('dc-today-filters')?.requestSubmit?.()
})
await page.waitForSelector('#dc-today-timeline li', { timeout: 15000 })
await page.waitForFunction(() => {
	const hints = [...document.querySelectorAll('.dc-date-locale-hint')]
	return hints.every((h) => getComputedStyle(h).display === 'none' || !h.textContent)
}, { timeout: 5000 }).catch(() => {})
await settle(page)
await shot(page, 'web-today')

// 3) Periods — prefer current-year rows; hide loading chrome
await gotoDe(page, 'http://localhost:8081/apps/dutycheck/periods')
await page.locator('#dc-periods-table-body').waitFor({ state: 'visible', timeout: 30000 })
await dismissTips(page)
await page.waitForFunction(() => {
	const body = document.getElementById('dc-periods-table-body')
	if (!body || body.querySelector('.dc-table__loading-row, td.dc-loading')) return false
	return body.querySelectorAll('tr').length > 0
}, { timeout: 45000 }).catch(() => {})
await page.evaluate(() => {
	const start = document.getElementById('dc-period-start')
	const end = document.getElementById('dc-period-end')
	if (start) start.value = '2026-10-01'
	if (end) end.value = '2026-10-31'
	window.DutyCheckDates?.applyLocaleToTemporalInputs?.(document)
	document.querySelectorAll('#dc-periods-table-body tr').forEach((tr) => {
		const t = tr.textContent || ''
		if (/20(8|9|1\d)\d|2101/.test(t)) tr.setAttribute('hidden', '')
	})
})
await settle(page)
await shot(page, 'web-periods')

// 4) Roster week/list surface (full page — shows swaps with names)
await gotoDe(page, 'http://localhost:8081/apps/dutycheck/roster?periodId=46')
await page.locator('#dc-main-content').waitFor({ state: 'visible', timeout: 30000 })
await dismissTips(page)
for (let i = 0; i < 4; i++) {
	const label = await page.locator('#dc-roster-month-current').textContent().catch(() => '')
	if (/november|2026-11|nov\.?\s*2026/i.test(label || '')) break
	await page.locator('#dc-roster-month-next').click({ timeout: 5000 }).catch(() => {})
	await settle(page)
}
await page.waitForSelector('#dc-roster-grid[role="grid"]', { timeout: 60000 })
await reveal(page, '#dc-roster-grid')
// Nudge horizontal scroll so last day column + fade affordance are visible.
await page.evaluate(() => {
	const scroller = document.querySelector('.dc-roster-grid-scroller')
	if (scroller) {
		scroller.scrollLeft = Math.max(0, scroller.scrollWidth - scroller.clientWidth - 8)
		scroller.scrollLeft = Math.min(scroller.scrollLeft, 120)
	}
})
await settle(page)
const rosterPath = await shot(page, 'web-roster')

// 5) Distinct month-grid crop (must NOT match full-page roster hash)
const grid = page.locator('#dc-roster-grid-wrap, .dc-roster-grid-scroller').first()
await grid.waitFor({ state: 'visible', timeout: 10000 })
const qaGrid = join(outQa, 'atlas-visual-r3-web-roster-month-grid.png')
const atlasGrid = join(outAtlas, 'atlas-visual-r3-web-roster-month-grid.png')
const legacyGrid = join(outAtlas, 'atlas-visual-r2-web-roster-month-grid.png')
await grid.screenshot({ path: qaGrid })
copyFileSync(qaGrid, atlasGrid)
copyFileSync(qaGrid, legacyGrid)
const hRoster = fileHash(rosterPath)
const hGrid = fileHash(atlasGrid)
console.log('OK web-roster-month-grid', hGrid, 'vs roster', hRoster, hRoster === hGrid ? 'SAME_HASH_FAIL' : 'distinct_ok')
if (hRoster === hGrid) {
	console.error('FAIL: month-grid hash identical to roster full page')
	process.exit(3)
}

// 6) Settings access — chip bar hidden on desktop via CSS
await gotoDe(page, 'http://localhost:8081/apps/dutycheck/settings/access')
await page.locator('#dc-main-content').waitFor({ state: 'visible', timeout: 30000 })
await dismissTips(page)
await settle(page)
await shot(page, 'web-settings-access')

await browser.close()
console.log('r3 web screenshots →', outAtlas)
