// Atlas R7 Lens 1: real concurrent standby writes through the app's own client.
import { chromium } from '@playwright/test'
import { readFileSync } from 'fs'

const env = {}
for (const line of readFileSync('tests/e2e/.env', 'utf8').split('\n')) {
	const m = line.match(/^\s*([A-Z0-9_]+)\s*=\s*(.*)\s*$/)
	if (m) env[m[1]] = m[2].replace(/^["']|["']$/g, '')
}
const BASE = env.NC_BASE_URL || 'http://localhost:8081'

const browser = await chromium.launch()
const ctx = await browser.newContext()
const page = await ctx.newPage()
await page.goto(`${BASE}/login`, { waitUntil: 'domcontentloaded' })
await page.fill('#user', env.E2E_USER)
await page.fill('#password', env.E2E_PASS)
await page.click('button[type="submit"]')
await page.waitForURL('**/apps/**', { timeout: 30000 }).catch(() => {})
await page.goto(`${BASE}/apps/dutycheck/roster`, { waitUntil: 'domcontentloaded' })
await page.waitForFunction(() => !!window.DutyCheckApi, { timeout: 30000 })

const out = await page.evaluate(async () => {
	const Api = window.DutyCheckApi
	const log = { steps: [] }
	const post = (path, body) => Api.post(path, body).then(r => ({ ok: true, r })).catch(e => ({ ok: false, code: e?.payload?.error?.code || e?.code || String(e), status: e?.status }))

	// 0) Enable standby + acknowledge for the acting company.
	const cfg = await Api.put('/apps/dutycheck/api/self-service/settings', { standbyEnabled: true, standbyAcknowledgeEnabled: true })
	log.steps.push({ step: 'enable-flags', ok: !!cfg })

	// 1) Load roster to learn real period/employee/location ids.
	const roster = await Api.get('/apps/dutycheck/api/roster', {})
	const data = roster?.data || {}
	const period = (data.periods || []).find(p => String(p.status).toLowerCase() === 'open') || data.periods?.[0]
	const employee = (data.employees || []).find(e => e.active !== false) || data.employees?.[0]
	const location = data.locations?.[0]
	if (!period || !employee || !location) {
		return { log, fatal: 'missing period/employee/location', data: { periods: data.periods?.length, employees: data.employees?.length, locations: data.locations?.length } }
	}
	log.context = { periodId: period.id, employeeId: employee.id, locationId: location.id, standbyEnabled: data.standbyEnabled, periodStart: period.startDate, periodEnd: period.endDate }

	// 2) Fire N concurrent identical standby creates — exactly one may win.
	// Pick a date inside the real period window.
	const dutyDate = String(period.startDate || '').slice(0, 10)
	const payload = {
		periodId: Number(period.id),
		employeeId: Number(employee.id),
		locationId: Number(location.id),
		dutyDate,
		startTime: '22:00',
		endTime: '22:00',
		breakMinutes: 0,
		kind: 'standby',
		note: 'atlas-r7-concurrency',
	}
	const N = 6
	const results = await Promise.all(Array.from({ length: N }, () => post('/apps/dutycheck/api/assignments', { ...payload })))
	log.concurrentCreate = results
	log.winners = results.filter(r => r.ok).length
	log.loserCodes = results.filter(r => !r.ok).map(r => r.code)

	// 3) Ground truth: list assignments for the date — exactly one row.
	const roster2 = await Api.get('/apps/dutycheck/api/roster', { periodId: Number(period.id) })
	const rows = (roster2?.data?.assignments || []).filter(a => String(a.note) === 'atlas-r7-concurrency')
	log.dbRows = rows.length
	log.rowKinds = rows.map(r => r.kind)
	log.createdId = rows[0]?.id

	// 4) Idempotent retry proof: re-POST same logical slot once more — must 409, not create.
	const retry = await post('/apps/dutycheck/api/assignments', { ...payload })
	log.retryAfterWin = retry

	// 5) Cancel the winner; concurrent re-create should then succeed exactly once.
	if (log.createdId) {
		const del = await Api.post(`/apps/dutycheck/api/assignments/${log.createdId}/cancel`, {}).then(r => ({ ok: true })).catch(e => ({ ok: false, code: e?.payload?.error?.code || e?.code }))
		log.cancel = del
		const race2 = await Promise.all(Array.from({ length: 4 }, () => post('/apps/dutycheck/api/assignments', { ...payload })))
		log.afterCancelRace = { winners: race2.filter(r => r.ok).length, codes: race2.map(r => r.ok ? 'OK' : r.code) }
		const roster3 = await Api.get('/apps/dutycheck/api/roster', { periodId: Number(period.id) })
		const rows3 = (roster3?.data?.assignments || []).filter(a => String(a.note) === 'atlas-r7-concurrency' && a.status !== 'cancelled')
		log.dbRowsAfterCancelRace = rows3.length
		if (rows3[0]?.id) {
			await Api.del(`/apps/dutycheck/api/assignments/${rows3[0].id}`).catch(() => {})
		}
	}
	return log
})
console.log(JSON.stringify(out, null, 2))
await browser.close()
