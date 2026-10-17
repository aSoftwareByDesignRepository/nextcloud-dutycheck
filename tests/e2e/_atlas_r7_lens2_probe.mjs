// Atlas R7 Lens 2: live security probes on the standby surface.
import { chromium } from '@playwright/test'
import { readFileSync } from 'fs'

const env = {}
for (const line of readFileSync('tests/e2e/.env', 'utf8').split('\n')) {
	const m = line.match(/^\s*([A-Z0-9_]+)\s*=\s*(.*)\s*$/)
	if (m) env[m[1]] = m[2].replace(/^["']|["']$/g, '')
}
const BASE = env.NC_BASE_URL || 'http://localhost:8081'

async function session(user, pass) {
	const ctx = await browser.newContext()
	const page = await ctx.newPage()
	await page.goto(`${BASE}/login`, { waitUntil: 'domcontentloaded' })
	await page.fill('#user', user)
	await page.fill('#password', pass)
	await page.click('button[type="submit"]')
	await page.waitForURL('**/apps/**', { timeout: 30000 }).catch(() => {})
	await page.goto(`${BASE}/apps/dutycheck/roster`, { waitUntil: 'domcontentloaded' })
	return page
}

const browser = await chromium.launch()

// --- Employee session: every standby endpoint must deny a non-planner ---
const empPage = await session(env.NC_EMPLOYEE_USER, env.NC_EMPLOYEE_PASS)
const empOut = await empPage.evaluate(async () => {
	const Api = window.DutyCheckApi
	if (!Api) return { fatal: 'no DutyCheckApi on employee page' }
	const call = (m, p, b) => Api[m](p, b).then(r => ({ ok: true, r: typeof r === 'object' ? { keys: Object.keys(r) } : r })).catch(e => ({ ok: false, status: e?.status, code: e?.payload?.error?.code || e?.code }))
	return {
		getCategories: await call('get', '/apps/dutycheck/api/standby/categories'),
		postCategory: await call('post', '/apps/dutycheck/api/standby/categories', { label: 'x' }),
		getStandbyRoster: await call('get', '/apps/dutycheck/api/standby/roster/343'),
		postAssignmentStandby: await call('post', '/apps/dutycheck/api/assignments', {
			periodId: 343, employeeId: 10, locationId: 534, dutyDate: '2199-01-05',
			startTime: '22:00', endTime: '22:00', breakMinutes: 0, kind: 'standby',
		}),
	}
})
console.log('EMPLOYEE:', JSON.stringify(empOut, null, 1))
await empPage.context().close()

// --- Planner session: flag-off + invalid inputs ---
const plPage = await session(env.E2E_USER, env.E2E_PASS)
const plOut = await plPage.evaluate(async () => {
	const Api = window.DutyCheckApi
	const call = (m, p, b) => Api[m](p, b).then(r => ({ ok: true, data: r?.data ?? r })).catch(e => ({ ok: false, status: e?.status, code: e?.payload?.error?.code || e?.code, fields: e?.payload?.error?.fields }))
	const out = {}
	// flag OFF → standby create must reject with STANDBY_DISABLED
	await Api.put('/apps/dutycheck/api/self-service/settings', { standbyEnabled: false })
	out.flagOffCreate = await call('post', '/apps/dutycheck/api/assignments', {
		periodId: 343, employeeId: 10, locationId: 534, dutyDate: '2199-01-05',
		startTime: '22:00', endTime: '22:00', breakMinutes: 0, kind: 'standby',
	})
	out.flagOffStandbyRoster = await call('get', '/apps/dutycheck/api/standby/roster/343')
	// invalid kind → INVALID_KIND even with flag off (input hygiene before flag check is fine either way)
	out.invalidKind = await call('post', '/apps/dutycheck/api/assignments', {
		periodId: 343, employeeId: 10, locationId: 534, dutyDate: '2199-01-05',
		startTime: '22:00', endTime: '22:00', breakMinutes: 0, kind: 'bogus',
	})
	// flag back ON for the remaining probes
	await Api.put('/apps/dutycheck/api/self-service/settings', { standbyEnabled: true, standbyAcknowledgeEnabled: true })
	// foreign/nonexistent category id → uniform not-found (no oracle)
	out.foreignCategory = await call('post', '/apps/dutycheck/api/assignments', {
		periodId: 343, employeeId: 10, locationId: 534, dutyDate: '2199-01-05',
		startTime: '22:00', endTime: '22:00', breakMinutes: 0, kind: 'standby', standbyCategoryId: 999999,
	})
	// planner (non-admin) attempting category WRITE → denied
	out.plannerCategoryWrite = await call('post', '/apps/dutycheck/api/standby/categories', { label: 'x' })
	// planner category READ (planner-or-admin) → allowed
	out.plannerCategoryRead = await call('get', '/apps/dutycheck/api/standby/categories')
	// nonexistent period standby roster → uniform not-found
	out.standbyRosterBadPeriod = await call('get', '/apps/dutycheck/api/standby/roster/999999')
	// kind immutable on update: create a shift then try to flip kind
	const mk = await call('post', '/apps/dutycheck/api/assignments', {
		periodId: 343, employeeId: 10, locationId: 534, dutyDate: '2199-01-06',
		startTime: '08:00', endTime: '16:00', breakMinutes: 0, kind: 'shift', note: 'atlas-r7-immutable',
	})
	if (mk.ok) {
		const newId = mk.data?.createdAssignmentId || mk.data?.id
		out.immutableKind = await call('put', `/apps/dutycheck/api/assignments/${newId}`, {
			kind: 'standby', version: 1,
		})
		await Api.post(`/apps/dutycheck/api/assignments/${newId}/cancel`, {}).catch(() => {})
		out.cleanup = 'done'
	} else {
		out.immutableSetup = mk
	}
	return out
})
console.log('PLANNER:', JSON.stringify(plOut, null, 1))
await browser.close()
