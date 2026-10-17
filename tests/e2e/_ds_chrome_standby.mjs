// @ts-check
/**
 * ds_chrome lane — standby (Rufbereitschaft) surface craft capture.
 *
 * Real-API craft for the standby feature shipped in 0.3.10 (post-7d61e75):
 *   - settings/dienst-team standby group (flag on, category list + add form)
 *   - roster grid standby cell (dashed border, phone icon, legend chip)
 *   - roster list view standby badge row
 *   - assignment modal "Type of duty" radiogroup + standby fields
 *   - employee my-roster standby row (stubbed /api/my/roster — myRoster()
 *     only serves published periods; the row payload shape is proven by the
 *     live probe, the render path is what this craft captures)
 *
 * Mutations (flag toggle, 2 categories, 1 assignment) are restored in
 * `finally` — prior flag values are re-posted, created rows deleted.
 *
 * Usage: DS_PROBE_PASS=… node tests/e2e/_ds_chrome_standby.mjs
 * Evidence root: FARM_OUT (default artifacts …/probes/ds_chrome).
 */
import { chromium } from 'playwright'
import { createHash } from 'node:crypto'
import { mkdirSync, writeFileSync } from 'node:fs'
import { join } from 'node:path'
import { login } from './helpers/auth.js'

const BASE = process.env.NC_BASE_URL || 'http://localhost:8081'
const PASS = process.env.DS_PROBE_PASS || 'DsProbe!2026'
const OUT = process.env.FARM_OUT
	|| '/home/alex/Development/nextcloud-dev/.cursor/atlas-farm-v3/artifacts/dutycheck/probes/ds_chrome'
mkdirSync(OUT, { recursive: true })

const USERS = {
	planner: { username: 'dc-ds-planner', password: PASS },
	employee: { username: 'dc-ds-employee', password: PASS },
	admin: { username: 'dc-ds-admin', password: PASS },
}

const STANDBY_PERIOD_ID = 639 // open, 2027-04-15 → 2027-04-21, company 1
const STANDBY_EMPLOYEE_ID = 48 // Greta Lorenz (linked: dc-ds-employee)
const STANDBY_LOCATION_ID = 9 // Zentrale
const STANDBY_DATE = '2027-04-16'

const results = { phase: 'standby-craft', startedAt: new Date().toISOString(), cells: [], defects: [], captures: {} }

function sha256(buf) {
	return createHash('sha256').update(buf).digest('hex')
}

async function snap(page, name) {
	const buf = await page.screenshot({ fullPage: false })
	const file = `${name}.png`
	writeFileSync(join(OUT, file), buf)
	results.captures[name] = { file, sha256: sha256(buf), bytes: buf.length }
	return results.captures[name]
}

function record(cell) {
	results.cells.push(cell)
	const tag = cell.status === 'fail' ? 'FAIL' : cell.status === 'warn' ? 'warn' : 'ok'
	console.log(`[${tag}] ${cell.id} :: ${JSON.stringify(cell.checks).slice(0, 300)}`)
}

async function loginState(browser, role) {
	const ctx = await browser.newContext({ baseURL: BASE, viewport: { width: 1440, height: 900 } })
	const page = await ctx.newPage()
	await login(page, USERS[role])
	const state = await ctx.storageState()
	await ctx.close()
	return state
}

async function settle(page) {
	await page.waitForLoadState('domcontentloaded').catch(() => {})
	try { await page.waitForLoadState('networkidle', { timeout: 5000 }) } catch { /* long-polls */ }
	try {
		await page.evaluate(() => new Promise((r) => requestAnimationFrame(() => requestAnimationFrame(r))))
	} catch { /* mid-settle navigation */ }
}

/** Collapse the off-canvas app navigation if it slid open on narrow viewports. */
async function ensureNavClosed(page) {
	const open = await page.evaluate(() => {
		const nav = document.getElementById('app-navigation')
		return nav ? nav.getBoundingClientRect().left >= 0 : false
	}).catch(() => false)
	if (open) {
		await page.locator('.dc-nav-toggle').first().click().catch(() => {})
		await page.waitForTimeout(400)
	}
}

/** Run an API call inside a logged-in page (session cookie + requesttoken). */
async function api(page, method, path, body) {
	return page.evaluate(async ({ method, path, body }) => {
		const api = window.DutyCheckApi
		if (!api) return { ok: false, error: 'DutyCheckApi missing' }
		try {
			const res = method === 'GET' ? await api.get(path)
				: method === 'DELETE' ? await api.del(path)
					: method === 'PUT' ? await api.put(path, body || {})
						: await api.post(path, body || {})
			return { ok: true, data: res?.data ?? res }
		} catch (err) {
			return { ok: false, status: err?.status ?? err?.response?.status, code: err?.code, error: String(err?.message || err).slice(0, 200), payload: err?.payload }
		}
	}, { method, path, body })
}

async function checkOverflow(page) {
	return page.evaluate(() => {
		const probe = (el) => (el ? el.scrollWidth - el.clientWidth : 0)
		return {
			doc: probe(document.documentElement),
			app: probe(document.querySelector('#app-content')),
			main: probe(document.getElementById('dc-main-content')),
		}
	})
}

async function main() {
	const browser = await chromium.launch()
	const adminState = await loginState(browser, 'admin')
	const plannerState = await loginState(browser, 'planner')

	const actx = await browser.newContext({ baseURL: BASE, storageState: adminState, viewport: { width: 1440, height: 900 } })
	const ap = await actx.newPage()
	ap.on('pageerror', (e) => console.log('ADMIN-PAGEEXC:', String(e).slice(0, 200)))
	await ap.goto(`${BASE}/apps/dutycheck/settings/dienst-team`, { waitUntil: 'domcontentloaded' })
	await settle(ap)

	// ── Provision: flag on, two categories, one standby assignment ──
	const prior = await api(ap, 'GET', '/apps/dutycheck/api/self-service/settings')
	if (!prior.ok) throw new Error(`settings GET failed: ${JSON.stringify(prior)}`)
	const priorData = prior.data?.data ?? prior.data ?? {}
	const priorStandby = priorData.standbyEnabled === true
	const priorAck = priorData.standbyAcknowledgeEnabled === true
	console.log(`[info] prior flags: standby=${priorStandby} ack=${priorAck}`)

	const setRes = await api(ap, 'POST', '/apps/dutycheck/api/self-service/settings', {
		standbyEnabled: true,
		standbyAcknowledgeEnabled: true,
	})
	if (!setRes.ok) throw new Error(`settings POST failed: ${JSON.stringify(setRes)}`)

	const catIds = []
	const created = { assignmentId: null }
	try {
		for (const cat of [
			{ label: 'Nachtbereitschaft', color: '#3366aa', defaultResponseTimeMinutes: 45 },
			{ label: 'Wochenenddienst', color: '#7c3aed' },
		]) {
			const res = await api(ap, 'POST', '/apps/dutycheck/api/standby/categories', cat)
			const catId = res.data?.id ?? res.data?.data?.id
			if (!res.ok || !catId) throw new Error(`category create failed: ${JSON.stringify(res)}`)
			catIds.push(catId)
		}
		console.log(`[info] categories created: ${catIds.join(',')}`)

		const asg = await api(ap, 'POST', '/apps/dutycheck/api/assignments', {
			periodId: STANDBY_PERIOD_ID,
			employeeId: STANDBY_EMPLOYEE_ID,
			locationId: STANDBY_LOCATION_ID,
			dutyDate: STANDBY_DATE,
			startTime: '00:00',
			endTime: '00:00',
			kind: 'standby',
			standbyCategoryId: catIds[0],
			responseTimeMinutes: 45,
		})
		if (!asg.ok) throw new Error(`standby assignment create failed: ${JSON.stringify(asg)}`)
		// createAssignment returns hydrated rosterData + createdAssignmentId
		created.assignmentId = asg.data?.createdAssignmentId ?? asg.data?.id ?? asg.data?.data?.id
		if (!created.assignmentId) throw new Error(`standby assignment id missing: ${JSON.stringify(asg)}`)
		console.log(`[info] standby assignment id=${created.assignmentId}`)

		// ── 1) settings/dienst-team — admin sees flag + categories + form ──
		for (const theme of ['light', 'dark']) {
			const cell = { id: `settings-dienst-team-standby@${theme}@1440`, checks: {}, status: 'ok', fails: [] }
			await ap.goto(`${BASE}/apps/dutycheck/settings/dienst-team`, { waitUntil: 'domcontentloaded' })
			await settle(ap)
			if (theme === 'dark') {
				await ap.evaluate(async () => {
					const token = document.querySelector('head[data-requesttoken]')?.getAttribute('data-requesttoken') || ''
					const headers = { requesttoken: token, 'OCS-APIRequest': 'true' }
					await fetch('/ocs/v2.php/apps/theming/api/v1/theme/dark/enable', { method: 'PUT', credentials: 'same-origin', headers })
				})
				await ap.reload({ waitUntil: 'domcontentloaded' })
				await settle(ap)
			}
			await ap.waitForSelector('#dc-dt-standby-extra:not([hidden])', { timeout: 15000 }).catch(() => {})
			cell.checks.extraPanelVisible = await ap.locator('#dc-dt-standby-extra').isVisible().catch(() => false)
			cell.checks.catCount = await ap.locator('#dc-dt-standby-cats-list .dc-standby-cats__item').count()
			cell.checks.addFormVisible = await ap.locator('#dc-dt-standby-cat-form').isVisible().catch(() => false)
			cell.checks.overflow = await checkOverflow(ap)
			cell.checks.overflowOk = Math.max(cell.checks.overflow.doc, cell.checks.overflow.app, cell.checks.overflow.main) <= 1
			const shot = await snap(ap, `standby__settings-dienst-team__${theme}__1440`)
			cell.proof = shot.sha256.slice(0, 16)
			if (!cell.checks.extraPanelVisible) cell.fails.push('standby extra panel hidden')
			if (cell.checks.catCount < 2) cell.fails.push(`category rows=${cell.checks.catCount}`)
			if (!cell.checks.addFormVisible) cell.fails.push('add form not visible')
			if (!cell.checks.overflowOk) cell.fails.push('overflow')
			if (cell.fails.length) cell.status = 'fail'
			record(cell)
		}
		// restore admin theme
		await ap.evaluate(async () => {
			const token = document.querySelector('head[data-requesttoken]')?.getAttribute('data-requesttoken') || ''
			const headers = { requesttoken: token, 'OCS-APIRequest': 'true' }
			await fetch('/ocs/v2.php/apps/theming/api/v1/theme/dark', { method: 'DELETE', credentials: 'same-origin', headers })
		}).catch(() => {})

		// ── 2) planner roster — grid standby cell + legend, list badge ──
		const pctx = await browser.newContext({ baseURL: BASE, storageState: plannerState, viewport: { width: 1440, height: 900 } })
		const pp = await pctx.newPage()
		pp.on('pageerror', (e) => console.log('PLANNER-PAGEEXC:', String(e).slice(0, 200)))
		await pp.goto(`${BASE}/apps/dutycheck/roster?periodId=${STANDBY_PERIOD_ID}`, { waitUntil: 'domcontentloaded' })
		await settle(pp)
		await pp.waitForSelector('#dc-roster-grid[role="grid"], #dc-roster-grid-wrap', { timeout: 30000 }).catch(() => {})
		await pp.waitForTimeout(2000)

		const gridCell = { id: 'roster-grid-standby@light@1440', checks: {}, status: 'ok', fails: [] }
		gridCell.checks.legendChip = await pp.locator('#dc-legend-chip-standby').isVisible().catch(() => false)
		gridCell.checks.standbyCells = await pp.locator('.dc-roster-grid__cell--standby').count()
		gridCell.checks.standbyIcon = await pp.locator('.dc-roster-grid__standby-icon').first().isVisible().catch(() => false)
		gridCell.checks.standbyTag = await pp.locator('.dc-roster-grid__standby-tag').first().textContent().catch(() => null)
		gridCell.checks.overflow = await checkOverflow(pp)
		gridCell.checks.overflowOk = Math.max(gridCell.checks.overflow.doc, gridCell.checks.overflow.app, gridCell.checks.overflow.main) <= 1
		await pp.locator('.dc-roster-grid__cell--standby').first().scrollIntoViewIfNeeded().catch(() => {})
		await pp.locator('#dc-legend-chip-standby').scrollIntoViewIfNeeded().catch(() => {})
		await pp.waitForTimeout(300)
		const shotG = await snap(pp, 'standby__roster-grid__light__1440')
		gridCell.proof = shotG.sha256.slice(0, 16)
		if (!gridCell.checks.legendChip) gridCell.fails.push('legend chip hidden')
		if (gridCell.checks.standbyCells < 1) gridCell.fails.push('no standby cell rendered')
		if (!gridCell.checks.overflowOk) gridCell.fails.push('overflow')
		if (gridCell.fails.length) gridCell.status = 'fail'
		record(gridCell)

		// 320px viewport of the same grid — horizontal scroll region must not
		// push document/app overflow (the scroller owns the overflow).
		await pp.setViewportSize({ width: 320, height: 640 })
		await settle(pp)
		await ensureNavClosed(pp)
		const grid320 = { id: 'roster-grid-standby@light@320', checks: {}, status: 'ok', fails: [] }
		grid320.checks.overflow = await checkOverflow(pp)
		grid320.checks.overflowOk = Math.max(grid320.checks.overflow.doc, grid320.checks.overflow.app, grid320.checks.overflow.main) <= 1
		grid320.checks.scrollerScrollable = await pp.evaluate(() => {
			const s = document.getElementById('dc-roster-grid-scroller')
			return s ? s.scrollWidth > s.clientWidth : false
		})
		const shotG320 = await snap(pp, 'standby__roster-grid__light__320')
		grid320.proof = shotG320.sha256.slice(0, 16)
		if (!grid320.checks.overflowOk) grid320.fails.push(`overflow ${JSON.stringify(grid320.checks.overflow)}`)
		if (grid320.fails.length) grid320.status = 'fail'
		record(grid320)
		await pp.setViewportSize({ width: 1440, height: 900 })

		// List view: standby badge + dash break.
		const listBtn = pp.locator('#dc-roster-view-list')
		if (await listBtn.count()) {
			await listBtn.click()
			await settle(pp)
			const listCell = { id: 'roster-list-standby@light@1440', checks: {}, status: 'ok', fails: [] }
			listCell.checks.badge = await pp.locator('#dc-assignments-table-body .dc-badge--standby').first().isVisible().catch(() => false)
			listCell.checks.overflow = await checkOverflow(pp)
			listCell.checks.overflowOk = Math.max(listCell.checks.overflow.doc, listCell.checks.overflow.app, listCell.checks.overflow.main) <= 1
			await pp.locator('#dc-assignments-table-body .dc-badge--standby').first().scrollIntoViewIfNeeded().catch(() => {})
			await pp.waitForTimeout(300)
			const shotL = await snap(pp, 'standby__roster-list__light__1440')
			listCell.proof = shotL.sha256.slice(0, 16)
			if (!listCell.checks.badge) listCell.fails.push('standby badge not rendered in list')
			if (!listCell.checks.overflowOk) listCell.fails.push('overflow')
			if (listCell.fails.length) listCell.status = 'fail'
			record(listCell)
		}

		// ── 3) assignment modal — kind radiogroup + standby fields ──
		const modalCell = { id: 'assignment-modal-standby@light@1440', checks: {}, status: 'ok', fails: [] }
		const addBtn = pp.locator('#dc-roster-add-assignment')
		if ((await addBtn.count()) && await addBtn.isEnabled().catch(() => false)) {
			await addBtn.click()
			await pp.waitForSelector('.dc-modal [role="dialog"], #dc-assignment-kind-group', { timeout: 10000 }).catch(() => {})
			await settle(pp)
			modalCell.checks.kindGroupVisible = await pp.locator('#dc-assignment-kind-group').isVisible().catch(() => false)
			const shotM1 = await snap(pp, 'standby__assignment-modal__kind-choice')
			// select standby kind → standby fields reveal, break hides
			await pp.locator('label[for="dc-assignment-kind-standby"]').click().catch(() => {})
			await pp.waitForTimeout(400)
			modalCell.checks.standbyFieldsVisible = await pp.locator('#dc-assignment-standby-fields').isVisible().catch(() => false)
			modalCell.checks.breakFieldHidden = await pp.locator('#dc-assignment-break-field').isHidden().catch(() => false)
			modalCell.checks.catOptions = await pp.locator('#dc-assignment-standby-category option').count()
			const shotM2 = await snap(pp, 'standby__assignment-modal__standby-fields')
			modalCell.proof = shotM2.sha256.slice(0, 16)
			if (!modalCell.checks.kindGroupVisible) modalCell.fails.push('kind group hidden')
			if (!modalCell.checks.standbyFieldsVisible) modalCell.fails.push('standby fields not revealed')
			if (!modalCell.checks.breakFieldHidden) modalCell.fails.push('break field still visible')
			await pp.keyboard.press('Escape')
		} else {
			modalCell.fails.push('add-assignment button unavailable')
		}
		if (modalCell.fails.length) modalCell.status = 'fail'
		record(modalCell)
		await pctx.close()

		// ── 4) employee my-roster — stubbed standby row (published-only API) ──
		const ectx = await browser.newContext({ baseURL: BASE, storageState: await loginState(browser, 'employee'), viewport: { width: 1440, height: 900 } })
		const ep = await ectx.newPage()
		await ep.route('**/apps/dutycheck/api/my/roster**', (route) => route.fulfill({
			status: 200,
			contentType: 'application/json',
			body: JSON.stringify({
				ok: true,
				data: [
					{
						id: 909302,
						periodId: STANDBY_PERIOD_ID,
						periodStatus: 'published',
						dutyDate: '2026-10-20',
						startTime: '00:00',
						endTime: '00:00',
						breakMinutes: 0,
						note: '',
						locationName: 'Zentrale',
						acknowledgedAt: null,
						acknowledged: false,
						kind: 'standby',
						acknowledgeable: true,
						standbyCategoryId: catIds[0],
						standbyCategoryLabel: 'Nachtbereitschaft',
						standbyCategoryColor: '#3366aa',
						responseTimeMinutes: 45,
					},
					{
						id: 909301,
						periodId: STANDBY_PERIOD_ID,
						periodStatus: 'published',
						dutyDate: '2026-10-21',
						startTime: '08:00:00',
						endTime: '16:00:00',
						breakMinutes: 30,
						note: '',
						locationName: 'Zentrale',
						acknowledgedAt: null,
						acknowledged: false,
						kind: 'shift',
						acknowledgeable: true,
					},
				],
			}),
		}))
		await ep.goto(`${BASE}/apps/dutycheck/my-roster`, { waitUntil: 'domcontentloaded' })
		await settle(ep)
		await ep.waitForSelector('#dc-my-roster-table-body tr', { timeout: 20000 }).catch(() => {})
		const myCell = { id: 'my-roster-standby@light@1440', checks: {}, status: 'ok', fails: [] }
		myCell.checks.standbyBadge = await ep.locator('.dc-badge--standby').first().isVisible().catch(() => false)
		myCell.checks.standbyRow = await ep.locator('.dc-my-roster__row--standby').count()
		myCell.checks.meta24h = await ep.locator('#dc-my-roster-table-body', { hasText: '24 h' }).count().catch(() => 0)
		myCell.checks.overflow = await checkOverflow(ep)
		myCell.checks.overflowOk = Math.max(myCell.checks.overflow.doc, myCell.checks.overflow.app, myCell.checks.overflow.main) <= 1
		await ep.locator('.dc-my-roster__row--standby').first().scrollIntoViewIfNeeded().catch(() => {})
		await ep.waitForTimeout(300)
		const shotE = await snap(ep, 'standby__my-roster__light__1440')
		myCell.proof = shotE.sha256.slice(0, 16)
		if (!myCell.checks.standbyBadge) myCell.fails.push('standby badge missing')
		if (myCell.checks.standbyRow < 1) myCell.fails.push('no standby row class')
		if (!myCell.checks.overflowOk) myCell.fails.push('overflow')
		if (myCell.fails.length) myCell.status = 'fail'
		record(myCell)

		await ep.setViewportSize({ width: 320, height: 640 })
		await settle(ep)
		await ensureNavClosed(ep)
		const my320 = { id: 'my-roster-standby@light@320', checks: {}, status: 'ok', fails: [] }
		my320.checks.overflow = await checkOverflow(ep)
		my320.checks.overflowOk = Math.max(my320.checks.overflow.doc, my320.checks.overflow.app, my320.checks.overflow.main) <= 1
		await ep.locator('.dc-my-roster__row--standby').first().scrollIntoViewIfNeeded().catch(() => {})
		await ep.waitForTimeout(300)
		const shotE320 = await snap(ep, 'standby__my-roster__light__320')
		my320.proof = shotE320.sha256.slice(0, 16)
		if (!my320.checks.overflowOk) my320.fails.push(`overflow ${JSON.stringify(my320.checks.overflow)}`)
		if (my320.fails.length) my320.status = 'fail'
		record(my320)
		await ectx.close()
	} finally {
		// ── Restore: cancel assignment, delete categories, restore flags ──
		if (created.assignmentId) {
			const c = await api(ap, 'POST', `/apps/dutycheck/api/assignments/${created.assignmentId}/cancel`, {})
			console.log(`[cleanup] cancel assignment ${created.assignmentId}: ${c.ok ? 'ok' : JSON.stringify(c)}`)
		}
		for (const id of catIds) {
			const d = await api(ap, 'DELETE', `/apps/dutycheck/api/standby/categories/${id}`)
			// inUseCount() counts cancelled rows: once the fixture assignment
			// referenced the category it can only be deactivated, not deleted.
			if (!d.ok && d.code === 'STANDBY_CATEGORY_IN_USE') {
				const off = await api(ap, 'PUT', `/apps/dutycheck/api/standby/categories/${id}`, { active: false })
				console.log(`[cleanup] category ${id} in-use (cancelled fixture rows retain FK) — deactivated: ${off.ok ? 'ok' : JSON.stringify(off)}`)
			} else {
				console.log(`[cleanup] delete category ${id}: ${d.ok ? 'ok' : JSON.stringify(d)}`)
			}
		}
		const restore = await api(ap, 'POST', '/apps/dutycheck/api/self-service/settings', {
			standbyEnabled: priorStandby,
			standbyAcknowledgeEnabled: priorAck,
		})
		console.log(`[cleanup] restore flags standby=${priorStandby} ack=${priorAck}: ${restore.ok ? 'ok' : JSON.stringify(restore)}`)
		// theme restore (dark may still be enabled for admin)
		await ap.evaluate(async () => {
			const token = document.querySelector('head[data-requesttoken]')?.getAttribute('data-requesttoken') || ''
			const headers = { requesttoken: token, 'OCS-APIRequest': 'true' }
			await fetch('/ocs/v2.php/apps/theming/api/v1/theme/dark', { method: 'DELETE', credentials: 'same-origin', headers })
		}).catch(() => {})
		await actx.close()
	}

	results.finishedAt = new Date().toISOString()
	results.defects = results.cells.filter((c) => c.status === 'fail')
	writeFileSync(join(OUT, 'results-standby-craft.json'), JSON.stringify(results, null, 2))
	console.log(`done: ${results.cells.length} cells, ${results.defects.length} FAIL`)
	await browser.close()
	process.exit(results.defects.length ? 1 : 0)
}

main().catch((e) => {
	console.error('FATAL:', e)
	process.exit(2)
})
