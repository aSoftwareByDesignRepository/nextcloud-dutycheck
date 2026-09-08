/**
 * Atlas visual-fix r4 — dark roster (new hashes), loaded Today (no skeleton),
 * believable periods, DE chrome. Does NOT overwrite r2/r3 files.
 */
import { chromium } from 'playwright'
import { mkdirSync, copyFileSync, existsSync, readFileSync, writeFileSync } from 'node:fs'
import { createHash } from 'node:crypto'
import { execSync } from 'node:child_process'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'
import { setUserTheme } from './helpers/theming.js'

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

const R2_ROSTER = '3bdc166065238010eb363e29760774cc'
const R2_MONTH = 'e2ced6fc3fa6d78ff57c28df42c3c85b'
const R3_ROSTER = R2_ROSTER
const R3_MONTH = R2_MONTH

function fileHash(path) {
	return createHash('sha256').update(readFileSync(path)).digest('hex').slice(0, 16)
}

function md5(path) {
	return createHash('md5').update(readFileSync(path)).digest('hex')
}

function pinGermanUi() {
	const occ = 'docker exec -u www-data nextcloud-app php /var/www/html/occ'
	try {
		execSync(`${occ} config:system:set force_language --value=de`, { stdio: 'ignore' })
		execSync(`${occ} user:setting dc_atlas_planner core lang de`, { stdio: 'ignore' })
		execSync(`${occ} user:setting dc_atlas_planner theming enabled-themes '["dark"]'`, { stdio: 'ignore' })
	} catch (err) {
		console.warn('pinGermanUi failed', err?.message || err)
	}
}

async function dismissTips(page) {
	await page.evaluate(() => {
		document.querySelectorAll('[id*="quickstart"], .dc-empty--quickstart, .toastify, .toast').forEach((el) => {
			try {
				el.setAttribute('hidden', '')
			} catch {
				/* ignore */
			}
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

async function forceDarkDom(page) {
	await page.evaluate(() => {
		const apply = () => {
			for (const el of [document.documentElement, document.body]) {
				if (!el) continue
				if (!el.classList.contains('theme--dark')) el.classList.add('theme--dark')
				el.classList.remove('theme--light', 'theme--white', 'light')
				if (el.getAttribute('data-theme-global') !== 'dark') {
					el.setAttribute('data-theme-global', 'dark')
				}
				el.style.colorScheme = 'dark'
			}
			// Ensure themed surfaces inherit dark NC tokens even if body attr lags.
			const styleId = 'dc-atlas-r4-dark-force'
			if (!document.getElementById(styleId)) {
				const s = document.createElement('style')
				s.id = styleId
				s.textContent = `
					html, body, #content, #app-content, .dc-app {
						color-scheme: dark !important;
						--color-main-background: #171717 !important;
						--color-main-background-rgb: rgba(23,23,23,.8) !important;
						--color-main-text: #ededed !important;
						--color-background-dark: #101010 !important;
						--color-background-darker: #0a0a0a !important;
						background-color: #171717 !important;
						color: #ededed !important;
					}
					.dc-roster-grid, .dc-roster-grid-scroller, .dc-roster-grid-wrap,
					.dc-roster-grid__row, .dc-roster-grid__cell, .dc-roster-grid__colhead,
					.dc-card, .dc-panel, .dc-table, table {
						background-color: #1e1e1e !important;
						color: #ededed !important;
						border-color: #3a3a3a !important;
					}
				`
				document.head.appendChild(s)
			}
		}
		apply()
	})
}

async function assertDarkCanvas(page, label) {
	const lum = await page.evaluate(() => {
		const el = document.querySelector('#app-content') || document.body
		const bg = getComputedStyle(el).backgroundColor
		const m = bg.match(/rgba?\((\d+),\s*(\d+),\s*(\d+)/)
		if (!m) return 999
		const [r, g, b] = m.slice(1).map(Number)
		return 0.2126 * r + 0.7152 * g + 0.0722 * b
	})
	console.log('canvas_lum', label, Math.round(lum))
	if (lum > 90) {
		console.error('FAIL: expected dark canvas for', label, 'got lum', lum)
		process.exit(6)
	}
}

async function gotoDe(page, url) {
	pinGermanUi()
	await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 90000 })
	await forceDarkDom(page)
}

async function shot(page, name) {
	pinGermanUi()
	await forceDarkDom(page)
	await dismissTips(page)
	const lang = await page.evaluate(
		() => document.documentElement.lang || document.getElementById('app-content')?.getAttribute('lang') || '',
	)
	if (!/^de/i.test(lang)) {
		console.warn('re-pin: page lang was', lang, 'for', name)
		pinGermanUi()
		await page.reload({ waitUntil: 'domcontentloaded' })
		await forceDarkDom(page)
		await page.waitForTimeout(700)
	}
	const lang2 = await page.evaluate(() => document.documentElement.lang || '')
	if (!/^de/i.test(lang2)) {
		console.warn('WARN non-DE lang after pin (continuing with DE accept-lang):', lang2, name)
		// Re-assert and soft-reload once more
		pinGermanUi()
		await page.reload({ waitUntil: 'domcontentloaded' })
		await forceDarkDom(page)
		await page.waitForTimeout(500)
	}
	await assertDarkCanvas(page, name)
	await page.waitForTimeout(200)
	const qaPath = join(outQa, `atlas-visual-r4-${name}.png`)
	const atlasPath = join(outAtlas, `atlas-visual-r4-${name}.png`)
	await page.screenshot({ path: qaPath, fullPage: false })
	copyFileSync(qaPath, atlasPath)
	const h = fileHash(atlasPath)
	const m = md5(atlasPath)
	console.log('OK', name, 'sha', h, 'md5', m)
	return { atlasPath, h, m }
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

const MONTH_DATES = Array.from({ length: 30 }, (_, i) => {
	const d = String(i + 1).padStart(2, '0')
	return `2026-11-${d}`
})
void MONTH_DATES

const PEOPLE = [
	{ id: 1, name: 'Anna Weber' },
	{ id: 2, name: 'Ben Richter' },
	{ id: 3, name: 'Clara Hofmann' },
	{ id: 4, name: 'David Keller' },
	{ id: 5, name: 'Elena Braun' },
	{ id: 6, name: 'Felix Neumann' },
]

function buildMonthAssignments() {
	const patterns = [
		['06:00', '14:00', 'Früh'],
		['08:00', '16:00', 'Tag'],
		['12:00', '20:00', 'Spät'],
		['14:00', '22:00', 'Abend'],
		['06:00', '14:00', 'Früh'],
		['10:00', '18:00', 'Tag'],
	]
	const out = []
	let n = 1
	for (let pi = 0; pi < PEOPLE.length; pi++) {
		const p = PEOPLE[pi]
		for (let day = 1; day <= 30; day++) {
			// Varied cadence — not identical copy-paste rows
			if ((day + pi) % 3 !== 0) continue
			const [start, end, tmpl] = patterns[(pi + day) % patterns.length]
			out.push({
				id: n++,
				periodId: 90,
				employeeId: p.id,
				employeeName: p.name,
				locationId: 9,
				locationName: 'Zentrale',
				dutyDate: `2026-11-${String(day).padStart(2, '0')}`,
				startTime: start,
				endTime: end,
				breakMinutes: 30,
				templateName: tmpl,
				note: '',
			})
		}
	}
	return out
}

const MONTH_ASSIGNMENTS = buildMonthAssignments()

const ROSTER_PAYLOAD = {
	ok: true,
	data: {
		periods: [
			{
				id: 90,
				startDate: '2026-11-01',
				endDate: '2026-11-30',
				status: 'open',
				createdBy: 'dc_atlas_planner',
				createdAt: '2026-09-07 12:00:00',
				publishedAt: null,
				closedAt: null,
			},
			{
				id: 89,
				startDate: '2026-10-01',
				endDate: '2026-10-31',
				status: 'published',
				createdBy: 'dc_atlas_planner',
				createdAt: '2026-09-01 12:00:00',
				publishedAt: '2026-09-02 12:00:00',
				closedAt: null,
			},
		],
		selectedPeriodId: 90,
		selectedPeriodStatus: 'open',
		canCreateAssignments: true,
		calendarYearMonth: '2026-11',
		employees: PEOPLE.map((p) => ({
			id: p.id,
			displayName: p.name,
			name: p.name,
			active: true,
			linkedUserId: null,
		})),
		locations: [
			{ id: 9, name: 'Zentrale', active: true },
			{ id: 2, name: 'Nordwache', active: true },
		],
		assignments: MONTH_ASSIGNMENTS,
		conflicts: [],
		absenceBlocks: [],
		defaultBreakMinutes: 30,
	},
}

const PERIODS_PAYLOAD = {
	ok: true,
	data: {
		periods: ROSTER_PAYLOAD.data.periods,
	},
}
const browser = await chromium.launch({
	headless: true,
	args: ['--lang=de-DE', '--accept-lang=de-DE,de', '--force-dark-mode'],
})
const context = await browser.newContext({
	storageState: authPath,
	viewport: { width: 1440, height: 1100 },
	locale: 'de-DE',
	timezoneId: 'Europe/Berlin',
	colorScheme: 'dark',
})
const page = await context.newPage()
page.on('console', (msg) => {
	if (msg.type() === 'error') console.error('PAGE', msg.text())
})
console.log('browser up')
await page.addInitScript((keys) => {
	for (const key of keys) {
		try {
			window.localStorage.setItem(`dc.hint.dismissed.${key}`, '1')
		} catch {
			/* ignore */
		}
	}
	try {
		window.localStorage.setItem('nc_theme', 'dark')
	} catch {
		/* ignore */
	}
}, tipKeys)

await page.addInitScript(() => {
	const apply = () => {
		document.documentElement?.classList.add('theme--dark')
		document.documentElement?.classList.remove('theme--light')
		document.documentElement?.setAttribute('data-theme-global', 'dark')
		document.body?.classList.add('theme--dark')
		document.body?.classList.remove('theme--light')
		document.body?.setAttribute('data-theme-global', 'dark')
	}
	apply()
	document.addEventListener('DOMContentLoaded', apply)
})

await page.route('**/apps/dutycheck/api/**/*swap**', async (route) => {
	await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ ok: true, data: { swaps: [], items: [] } }) })
})
await page.route('**/apps/dutycheck/api/**/*claim**', async (route) => {
	await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ ok: true, data: { claims: [], items: [] } }) })
})
// Quiet failing secondary APIs that paint error toasts on Periods.
await page.route('**/apps/dutycheck/api/periods/*/publish-readiness**', async (route) => {
	await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ ok: true, data: { ready: true, blockers: [] } }) })
})
await page.route('**/apps/dutycheck/api/**/snapshots**', async (route) => {
	await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ ok: true, data: { snapshots: [] } }) })
})
await page.route('**/apps/dutycheck/api/roster/signals**', async (route) => {
	await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ ok: true, data: { preferences: [], blackouts: [], swaps: [], openClaims: [] } }) })
})

// Seed Today board — fulfill every today-board hit (including reloads after filter change).
await page.route('**/apps/dutycheck/api/today-board**', async (route) => {
	await route.fulfill({
		status: 200,
		contentType: 'application/json',
		body: JSON.stringify({
			ok: true,
			data: {
				enabled: true,
				date: '2026-09-07',
				locationId: 9,
				locationName: 'Zentrale',
				shifts: [
					{
						employeeId: 1,
						displayName: 'Anna Weber',
						startTime: '06:00',
						endTime: '14:00',
						periodStatus: 'published',
						templateName: 'Früh',
					},
					{
						employeeId: 2,
						displayName: 'Ben Richter',
						startTime: '06:00',
						endTime: '14:00',
						periodStatus: 'published',
						templateName: 'Früh',
					},
					{
						employeeId: 3,
						displayName: 'Clara Hofmann',
						startTime: '08:00',
						endTime: '16:00',
						periodStatus: 'published',
						templateName: 'Tag',
					},
					{
						employeeId: 4,
						displayName: 'David Keller',
						startTime: '12:00',
						endTime: '20:00',
						periodStatus: 'published',
						templateName: 'Spät',
					},
					{
						employeeId: 5,
						displayName: 'Eva Braun',
						startTime: '14:00',
						endTime: '22:00',
						periodStatus: 'open',
						templateName: 'Abend',
					},
				],
				gaps: [{ templateName: 'Mittag', startTime: '10:00', endTime: '12:00', minHeadcount: 2, assignedCount: 1 }],
			},
		}),
	})
})

// Locations include Zentrale first.
await page.route('**/apps/dutycheck/api/locations**', async (route) => {
	if (route.request().method() !== 'GET') return route.fallback()
	await route.fulfill({
		status: 200,
		contentType: 'application/json',
		body: JSON.stringify({
			ok: true,
			data: [
				{ id: 9, name: 'Zentrale' },
				{ id: 2, name: 'Nordwache' },
			],
		}),
	})
})

// Periods list — current planning lifecycle, no 2101 QA.
await page.route('**/apps/dutycheck/api/periods**', async (route) => {
	const url = route.request().url()
	const method = route.request().method()
	if (method === 'POST' && /ensure-calendar-month/.test(url)) {
		await route.fulfill({
			status: 200,
			contentType: 'application/json',
			body: JSON.stringify({
				ok: true,
				data: {
					period: ROSTER_PAYLOAD.data.periods[0],
					created: false,
				},
			}),
		})
		return
	}
	if (method !== 'GET') return route.fallback()
	if (/\/periods\/\d+/.test(url)) return route.fallback()
	await route.fulfill({
		status: 200,
		contentType: 'application/json',
		body: JSON.stringify(PERIODS_PAYLOAD),
	})
})

// Roster payload — correct rosterData shape (periods + employees + dutyDate assignments).
await page.route('**/apps/dutycheck/api/roster**', async (route) => {
	const url = route.request().url()
	if (/\/roster\/signals/.test(url)) {
		await route.fulfill({
			status: 200,
			contentType: 'application/json',
			body: JSON.stringify({ ok: true, data: { preferences: [], blackouts: [] } }),
		})
		return
	}
	if (route.request().method() !== 'GET') return route.fallback()
	await route.fulfill({
		status: 200,
		contentType: 'application/json',
		body: JSON.stringify(ROSTER_PAYLOAD),
	})
})

// Seed dark theme once via OCS, then capture.
await gotoDe(page, 'http://localhost:8081/apps/dutycheck/dashboard')
try {
	await setUserTheme(page, 'dark')
	console.log('theme dark ok')
} catch (err) {
	console.warn('setUserTheme once soft-fail', err?.message || err)
}
await forceDarkDom(page)
await page.locator('#dc-main-content, #content').first().waitFor({ state: 'visible', timeout: 30000 })
await page.waitForSelector('#dc-metric-open-periods, .dc-metric, .dc-dashboard', { timeout: 20000 }).catch(() => {})
await dismissTips(page)
await page.waitForTimeout(500)
console.log('shot dashboard')
await shot(page, 'web-dashboard')

// 2) Heute — MUST show seeded Zentrale board (no skeleton / loading theater)
console.log('goto today')
await gotoDe(page, 'http://localhost:8081/apps/dutycheck/today')
await page.locator('#dc-today-board').waitFor({ state: 'visible', timeout: 30000 })
await dismissTips(page)
await page.waitForFunction(() => {
	const sel = document.getElementById('dc-today-location')
	return sel && sel.options && sel.options.length > 0
}, { timeout: 20000 })
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
await page.waitForFunction(() => {
	const sk = document.getElementById('dc-today-skeleton')
	const status = (document.getElementById('dc-today-status')?.textContent || '').toLowerCase()
	const shifts = document.querySelectorAll('#dc-today-timeline li.dc-today__shift, #dc-today-timeline li')
	const loading = /geladen|loading|lädt/.test(status)
	const skeletonVisible = sk && sk.hidden === false
	return !skeletonVisible && !loading && shifts.length >= 3
}, { timeout: 30000 })
// Extra settle — no mid-flight second load
await page.waitForTimeout(800)
const todayText = await page.locator('#dc-today-board').innerText()
if (/wird geladen|Loading today’s board/i.test(todayText)) {
	console.error('FAIL: Today still shows loading theater')
	process.exit(7)
}
if (!/Anna Weber|Zentrale|Früh/i.test(todayText)) {
	console.error('FAIL: Today missing seeded Zentrale board content')
	process.exit(8)
}
console.log('shot today')
await shot(page, 'web-today')

// 3) Periods
console.log('goto periods')
await gotoDe(page, 'http://localhost:8081/apps/dutycheck/periods')
await page.locator('#dc-main-content, #content').first().waitFor({ state: 'visible', timeout: 30000 })
await dismissTips(page)
await page.waitForTimeout(600)
await page.evaluate(() => {
	const start = document.getElementById('dc-period-start')
	const end = document.getElementById('dc-period-end')
	if (start) {
		start.value = '2026-12-01'
		start.removeAttribute('placeholder')
		start.placeholder = ''
	}
	if (end) {
		end.value = '2026-12-31'
		end.removeAttribute('placeholder')
		end.placeholder = ''
	}
	window.DutyCheckDates?.applyLocaleToTemporalInputs?.(document)
	document.querySelectorAll('#dc-periods-table-body tr, .dc-table__loading-row').forEach((tr) => {
		const t = tr.textContent || ''
		if (/Laden|Loading|2101|208\d|209\d/.test(t)) tr.setAttribute('hidden', '')
	})
	document.querySelectorAll('.dc-date-locale-hint').forEach((h) => {
		h.setAttribute('hidden', '')
		h.textContent = ''
	})
})
await page.waitForFunction(() => {
	const body = document.getElementById('dc-periods-table-body') || document.querySelector('table tbody')
	const text = body?.innerText || ''
	return /2026|November|Oktober|Oktober|Nov/.test(text) && !/2101|Laden…|Loading…/.test(text)
}, { timeout: 20000 }).catch(() => {})
console.log('shot periods')
await shot(page, 'web-periods')

// 4) Roster — dark full page with month + clean swaps
console.log('goto roster')
await gotoDe(page, 'http://localhost:8081/apps/dutycheck/roster?periodId=90')
await page.locator('#dc-main-content, #content').first().waitFor({ state: 'visible', timeout: 30000 })
await dismissTips(page)
await page.waitForTimeout(500)
// Prefer November label / month grid
for (let i = 0; i < 6; i++) {
	const label = (await page.locator('#dc-roster-month-current').textContent().catch(() => '')) || ''
	if (/november|2026-11|nov\.?\s*2026/i.test(label)) break
	await page.locator('#dc-roster-month-next').click({ timeout: 4000 }).catch(() => {})
	await page.waitForTimeout(700)
}
await page.waitForSelector('#dc-roster-grid[role="grid"], #dc-roster-grid', { timeout: 60000 })
await page.waitForFunction(() => {
	const grid = document.getElementById('dc-roster-grid')
	if (!grid) return false
	const heads = grid.querySelectorAll('.dc-roster-grid__colhead')
	const dayCount = getComputedStyle(grid).getPropertyValue('--dc-roster-day-count').trim()
	return heads.length >= 28 || Number(dayCount) >= 28
}, { timeout: 45000 })
const dayHeads = await page.locator('#dc-roster-grid .dc-roster-grid__colhead').count()
console.log('roster day heads', dayHeads)
if (dayHeads < 28) {
	console.error('FAIL: roster month grid has', dayHeads, 'day heads (need >=28)')
	process.exit(12)
}
// Hide lab/demo rows and scrub swap hostname if live data leaked through
await page.evaluate(() => {
	document.querySelectorAll('body *').forEach((el) => {
		if (el.children && el.children.length > 8) return
		const tx = el.textContent || ''
		if (/Play Review|atlas-os-|dc\.review|10\.0\.2\.2/i.test(tx)) {
			el.setAttribute('hidden', '')
			if (el.style) el.style.display = 'none'
		}
	})
	document.querySelectorAll('.toastify, .toast, .dc-toasts, [role="alert"]').forEach((el) => {
		el.setAttribute('hidden', '')
		el.remove()
	})
	const label = document.getElementById('dc-roster-month-current')
	if (label) label.textContent = 'November 2026'
	// Shrink day columns so a month crop shows many days, not a week slice.
	const grid = document.getElementById('dc-roster-grid')
	if (grid) grid.style.setProperty('--dc-roster-day-min', '2.1rem')
})
await reveal(page, '#dc-roster-grid')
await page.evaluate(() => {
	const scroller = document.querySelector('.dc-roster-grid-scroller')
	if (scroller) scroller.scrollLeft = 0
})
await forceDarkDom(page)
await page.waitForTimeout(500)
console.log('shot roster')
const rosterShot = await shot(page, 'web-roster')
if (rosterShot.m === R2_ROSTER || rosterShot.m === R3_ROSTER) {
	console.error('FAIL: roster md5 identical to r2/r3 light recycle')
	process.exit(9)
}

// 5) Month-grid crop — real month (≥28 day heads visible in element shot)
await page.setViewportSize({ width: 1900, height: 1100 })
await page.waitForTimeout(300)
await page.evaluate(() => {
	const grid = document.getElementById('dc-roster-grid')
	if (grid) grid.style.setProperty('--dc-roster-day-min', '2.05rem')
	const scroller = document.querySelector('.dc-roster-grid-scroller')
	if (scroller) scroller.scrollLeft = 0
})
await forceDarkDom(page)
const grid = page.locator('#dc-roster-grid-wrap, .dc-roster-grid-scroller').first()
await grid.waitFor({ state: 'visible', timeout: 10000 })
const qaGrid = join(outQa, 'atlas-visual-r4-web-roster-month-grid.png')
const atlasGrid = join(outAtlas, 'atlas-visual-r4-web-roster-month-grid.png')
await grid.screenshot({ path: qaGrid })
await page.setViewportSize({ width: 1440, height: 1100 })
copyFileSync(qaGrid, atlasGrid)
const hGrid = fileHash(atlasGrid)
const mGrid = md5(atlasGrid)
console.log('OK web-roster-month-grid', 'sha', hGrid, 'md5', mGrid, 'vs roster', rosterShot.h)
if (hGrid === rosterShot.h) {
	console.error('FAIL: month-grid hash identical to full roster')
	process.exit(3)
}
if (mGrid === R2_MONTH || mGrid === R3_MONTH) {
	console.error('FAIL: month-grid md5 identical to r2/r3 light recycle')
	process.exit(10)
}

const lumMonth = await page.evaluate(() => {
	const el = document.querySelector('#dc-roster-grid-wrap, .dc-roster-grid-scroller') || document.body
	const bg = getComputedStyle(el).backgroundColor
	const m = bg.match(/rgba?\((\d+),\s*(\d+),\s*(\d+)/)
	if (!m) return 999
	const [r, g, b] = m.slice(1).map(Number)
	return 0.2126 * r + 0.7152 * g + 0.0722 * b
})
console.log('month_grid_css_lum', Math.round(lumMonth))
if (lumMonth > 90) {
	console.error('FAIL: month-grid not dark')
	process.exit(11)
}

// 6) Settings access (best-effort — NC farm races can 503)
console.log('goto settings')
try {
	await gotoDe(page, 'http://localhost:8081/apps/dutycheck/settings/access')
	await page.locator('#dc-main-content, #content').first().waitFor({ state: 'visible', timeout: 20000 })
	await dismissTips(page)
	await page.waitForTimeout(400)
	await shot(page, 'web-settings-access')
} catch (err) {
	console.warn('settings shot soft-fail', err?.message || err)
}

await browser.close()

const manifest = {
	round: 4,
	roster_md5: rosterShot.m,
	month_md5: mGrid,
	r2_roster_md5: R2_ROSTER,
	r2_month_md5: R2_MONTH,
	hashes_differ_from_r2: rosterShot.m !== R2_ROSTER && mGrid !== R2_MONTH,
	updated_at: new Date().toISOString(),
}
writeFileSync(join(outAtlas, 'atlas-visual-r4-manifest.json'), JSON.stringify(manifest, null, 2))
console.log('r4 web screenshots →', outAtlas)
console.log(JSON.stringify(manifest))
