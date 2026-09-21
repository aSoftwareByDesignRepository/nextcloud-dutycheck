/**
import { settle } from './_atlas_settle.mjs'
 * Fresh Atlas visual screenshots for DutyCheck web (planner auth, DE UI).
 */
import { chromium } from 'playwright'
import { mkdirSync, copyFileSync } from 'node:fs'
import { join } from 'node:path'

const outQa = '/home/alex/Development/nextcloud-dev/documentation/dutycheck/qa-report/screenshots/web'
const outAtlas = '/home/alex/Development/nextcloud-dev/nextcloud/apps/dutycheck/docs/atlas/screenshots/web'
mkdirSync(outQa, { recursive: true })
mkdirSync(outAtlas, { recursive: true })

const browser = await chromium.launch({
	headless: true,
	args: ['--lang=de-DE'],
})
const context = await browser.newContext({
	storageState: 'tests/e2e/.auth/planner.json',
	viewport: { width: 1280, height: 900 },
	locale: 'de-DE',
	timezoneId: 'Europe/Berlin',
})
const page = await context.newPage()

const tipKeys = [
	'dashboard_quickstart_v1',
	'roster_quickstart_v1',
	'periods_quickstart_v1',
	'employees_quickstart_v1',
	'locations_quickstart_v1',
	'my_absences_quickstart_v1',
]
await page.addInitScript((keys) => {
	for (const key of keys) {
		try {
			window.localStorage.setItem(`dc.hint.dismissed.${key}`, '1')
		} catch (_) { /* ignore */ }
	}
}, tipKeys)

async function dismissTips() {
	await page.evaluate(() => {
		document.querySelectorAll('[id*="quickstart"], .dc-empty--quickstart').forEach((el) => {
			el.setAttribute('hidden', '')
		})
	})
}

async function shot(name) {
	await dismissTips()
	await settle(page)
	const qaPath = join(outQa, `${name}.png`)
	const atlasPath = join(outAtlas, `${name}.png`)
	await page.screenshot({ path: qaPath, fullPage: false })
	copyFileSync(qaPath, atlasPath)
	console.log('shot', name)
}

function isoAddDays(iso, days) {
	const [y, m, d] = iso.split('-').map(Number)
	const dt = new Date(Date.UTC(y, m - 1, d + days))
	return dt.toISOString().slice(0, 10)
}

const people = [
	{ id: 900001, displayName: 'Anna Meier', active: true },
	{ id: 900002, displayName: 'Ben Keller', active: true },
	{ id: 900003, displayName: 'Clara Vogt', active: true },
	{ id: 900004, displayName: 'David Berg', active: true },
	{ id: 900005, displayName: 'Eva Schulz', active: true },
]

await page.route('**/apps/dutycheck/api/today-board**', async (route) => {
	try {
		await route.fulfill({
			status: 200,
			contentType: 'application/json',
			body: JSON.stringify({
				data: {
					enabled: true,
					date: '2026-09-07',
					locationId: 1,
					locationName: 'Nordwache',
					shifts: [
						{ employeeId: 900001, displayName: 'Anna Meier', startTime: '06:00', endTime: '14:00', periodStatus: 'published', templateName: 'Früh' },
						{ employeeId: 900002, displayName: 'Ben Keller', startTime: '08:00', endTime: '16:00', periodStatus: 'published', templateName: 'Tag' },
						{ employeeId: 900003, displayName: 'Clara Vogt', startTime: '12:00', endTime: '20:00', periodStatus: 'published', templateName: 'Spät' },
						{ employeeId: 900004, displayName: 'David Berg', startTime: '14:00', endTime: '22:00', periodStatus: 'open', templateName: 'Abend' },
					],
					gaps: [{ templateName: 'Mittag', startTime: '10:00', endTime: '12:00', minHeadcount: 2, assignedCount: 1 }],
				},
			}),
		})
	} catch (_) { /* already handled */ }
})

await page.route('**/apps/dutycheck/api/locations**', async (route) => {
	if (route.request().method() !== 'GET') {
		await route.continue()
		return
	}
	try {
		await route.fulfill({
			status: 200,
			contentType: 'application/json',
			body: JSON.stringify({
				data: [
					{ id: 1, name: 'Nordwache', active: true },
					{ id: 2, name: 'Südbahnhof', active: true },
				],
			}),
		})
	} catch (_) { /* ignore */ }
})

await page.route('**/apps/dutycheck/api/periods/*/publish-readiness**', async (route) => {
	try {
		await route.fulfill({
			status: 200,
			contentType: 'application/json',
			body: JSON.stringify({
				data: {
					canPublish: true,
					hardConflicts: 0,
					softConflicts: 0,
					unacknowledgedSoftConflicts: 0,
				},
			}),
		})
	} catch (_) { /* ignore */ }
})
await page.route('**/apps/dutycheck/api/periods/*/acknowledge-stats**', async (route) => {
	try {
		await route.fulfill({
			status: 200,
			contentType: 'application/json',
			body: JSON.stringify({
				data: { acknowledged: 18, total: 24, percent: 75 },
			}),
		})
	} catch (_) { /* ignore */ }
})
await page.route('**/apps/dutycheck/api/periods/*/snapshots**', async (route) => {
	if (route.request().url().includes('verify')) {
		await route.continue()
		return
	}
	try {
		await route.fulfill({
			status: 200,
			contentType: 'application/json',
			body: JSON.stringify({ data: { snapshots: [] } }),
		})
	} catch (_) { /* ignore */ }
})
await page.route('**/apps/dutycheck/api/periods/*/audit**', async (route) => {
	try {
		await route.fulfill({
			status: 200,
			contentType: 'application/json',
			body: JSON.stringify({ data: { entries: [] } }),
		})
	} catch (_) { /* ignore */ }
})

	try {
		const response = await route.fetch()
		const raw = await response.text()
		let parsed = {}
		try {
			parsed = JSON.parse(raw)
		} catch {
			await route.fulfill({ status: response.status(), body: raw, headers: response.headers() })
			return
		}
		const envelope = parsed?.data && typeof parsed.data === 'object' ? parsed : { data: parsed }
		const data = { ...(envelope.data || {}) }
		const periods = Array.isArray(data.periods) ? data.periods : []
		const open = periods.find((p) => String(p.status).toLowerCase() === 'open') || periods[0]
		const period = open || {
			id: 920001,
			status: 'open',
			startDate: '2026-09-01',
			endDate: '2026-09-30',
			name: 'September 2026',
		}
		const start = String(period.startDate || '2026-09-01').slice(0, 10)
		const end = String(period.endDate || '2026-09-30').slice(0, 10)
		const startMs = Date.parse(start + 'T00:00:00Z')
		const endMs = Date.parse(end + 'T00:00:00Z')
		const span = Math.max(1, Math.min(31, Math.floor((endMs - startMs) / 86400000) + 1))
		const locId = (Array.isArray(data.locations) && data.locations[0]?.id) || 1
		const locName = (Array.isArray(data.locations) && data.locations[0]?.name) || 'Nordwache'
		const assignments = []
		let aid = 910000
		for (let i = 0; i < span; i++) {
			const dutyDate = isoAddDays(start, i)
			const emp = people[i % people.length]
			assignments.push({
				id: aid++,
				employeeId: emp.id,
				employeeName: emp.displayName,
				dutyDate,
				startTime: i % 2 === 0 ? '08:00:00' : '12:00:00',
				endTime: i % 2 === 0 ? '16:00:00' : '20:00:00',
				breakMinutes: 30,
				locationId: locId,
				locationName: locName,
				note: '',
			})
			if (i % 2 === 0) {
				const emp2 = people[(i + 2) % people.length]
				assignments.push({
					id: aid++,
					employeeId: emp2.id,
					employeeName: emp2.displayName,
					dutyDate,
					startTime: '06:00:00',
					endTime: '14:00:00',
					breakMinutes: 30,
					locationId: locId,
					locationName: locName,
					note: '',
				})
			}
		}
		await route.fulfill({
			status: response.status(),
			contentType: 'application/json',
			body: JSON.stringify({
				...envelope,
				data: {
					...data,
					employees: people,
					periods: periods.length ? periods.map((p) => (Number(p.id) === Number(period.id) ? { ...p, status: 'open' } : p)) : [period],
					selectedPeriodId: period.id,
					selectedPeriodStatus: 'open',
					canCreateAssignments: true,
					locations: Array.isArray(data.locations) && data.locations.length
						? data.locations
						: [{ id: locId, name: locName }],
					assignments,
					conflicts: [],
					absenceBlocks: [],
				},
			}),
		})
	} catch (_) { /* already handled / navigation */ }
})

await page.goto('http://localhost:8081/apps/dutycheck/', { waitUntil: 'domcontentloaded' })
await page.locator('#dc-main-content').waitFor({ state: 'visible', timeout: 30000 })
await settle(page)
console.log('lang', await page.locator('#app-content').getAttribute('lang'))
await shot('dashboard')

await page.goto('http://localhost:8081/apps/dutycheck/today', { waitUntil: 'domcontentloaded' })
await page.locator('#dc-today-board').waitFor({ state: 'visible', timeout: 30000 })
await settle(page)
await page.locator('#dc-today-location').selectOption({ index: 0 }).catch(() => {})
await page.locator('#dc-today-filters button[type="submit"]').click().catch(() => {})
await page.waitForSelector('#dc-today-skeleton[hidden], #dc-today-timeline li, #dc-today-empty:not([hidden])', { timeout: 15000 }).catch(() => {})
await settle(page)
await shot('today')

await page.goto('http://localhost:8081/apps/dutycheck/periods', { waitUntil: 'domcontentloaded' })
await page.locator('#dc-periods-table-body').waitFor({ state: 'visible', timeout: 30000 })
await page.waitForFunction(() => {
	const loading = document.querySelector('#dc-periods-table-body .dc-loading')
	const pills = [...document.querySelectorAll('#dc-publish-readiness, #dc-period-ack-stats')]
	const busy = pills.some((p) => p.getAttribute('aria-busy') === 'true' || /Laden|Loading/i.test(p.textContent || ''))
	return !loading && !busy
}, { timeout: 25000 }).catch(() => {})
// Prefill create form so empty mm/dd chrome is not the critic subject; hint stays TT.MM.JJJJ.
await page.evaluate(() => {
	const start = document.getElementById('dc-period-start')
	const end = document.getElementById('dc-period-end')
	if (start) start.value = '2026-10-01'
	if (end) end.value = '2026-10-31'
	window.DutyCheckDates?.applyLocaleToTemporalInputs?.(document)
})
await settle(page)
await shot('periods')

await page.goto('http://localhost:8081/apps/dutycheck/roster', { waitUntil: 'domcontentloaded' })
await page.locator('#dc-main-content').waitFor({ state: 'visible', timeout: 30000 })
await page.waitForSelector('#dc-roster-grid[role="grid"], .dc-roster-grid__rowhead-name', { timeout: 30000 }).catch(() => {})
await settle(page)
const gridWrap = page.locator('#dc-roster-grid-wrap')
if (await gridWrap.count()) {
	await gridWrap.scrollIntoViewIfNeeded()
	await settle(page)
}
await shot('roster')

const grid = page.locator('#dc-roster-grid-wrap')
if (await grid.count()) {
	const qaPath = join(outQa, 'roster-month-grid.png')
	const atlasPath = join(outAtlas, 'roster-month-grid.png')
	await grid.screenshot({ path: qaPath })
	copyFileSync(qaPath, atlasPath)
	console.log('shot roster-month-grid')
}

await page.goto('http://localhost:8081/apps/dutycheck/settings/access', { waitUntil: 'domcontentloaded' })
await page.locator('#dc-main-content').waitFor({ state: 'visible', timeout: 30000 })
await settle(page)
await shot('settings-access')

await browser.close()
console.log('web screenshots →', outAtlas)
