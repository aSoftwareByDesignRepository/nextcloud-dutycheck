// @ts-check
/**
 * Atlas seed dutycheck-intake-20261127T160556Z pixel-verify (one-shot probe).
 * de 'Widerrufen' Revoke button clipped mid-glyph @1280px: tableW 968 >
 * wrapCW 914, button right edge 1282 > viewport 1280. Post-heal (donor
 * actions cell normal wrapping): assert every action button paints fully
 * inside the wrap and inside the viewport; table <= wrap clientWidth.
 *
 * Run: node tests/e2e/_atlas_agent_actions_probe.mjs
 * Requires: tests/e2e/.auth/planner.json, user lang already pinned (de).
 */
import { chromium } from 'playwright'
import { execSync } from 'child_process'
import { mkdirSync } from 'fs'

const BASE = process.env.NC_BASE_URL || 'http://localhost:8081'
const LABEL = `atlas-act-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 6)}`
const CRAFT = '/home/alex/Development/nextcloud-dev/.cursor/atlas-farm-v3/artifacts/dutycheck/craft/web'

function db(sql) {
	return execSync(
		`docker exec nextcloud-mariadb mariadb -u nextcloud -pnextcloud_password nextcloud -N -e "${sql}"`,
		{ encoding: 'utf8' },
	).trim()
}

const browser = await chromium.launch({ headless: true })
const context = await browser.newContext({
	storageState: 'tests/e2e/.auth/planner.json',
	viewport: { width: 1280, height: 900 },
})
const page = await context.newPage()
const result = { label: LABEL }

try {
	await page.goto(BASE + '/apps/dutycheck/settings/agent', { waitUntil: 'domcontentloaded' })
	await page.locator('#dc-agent-access').waitFor({ state: 'visible', timeout: 30000 })

	// Mint a token via the real API so the PHP render carries a full row.
	const rt = await page.evaluate(() => window.OC?.requestToken || '')
	const res = await page.request.fetch(BASE + '/apps/dutycheck/agent/v1/tokens', {
		method: 'POST',
		headers: { requesttoken: rt, 'Content-Type': 'application/json' },
		data: { label: LABEL, preset: 'read' },
	})
	result.mintStatus = res.status()
	if (res.status() !== 201) {
		result.mintBody = await res.text()
		throw new Error(`mint failed ${res.status()}: ${result.mintBody}`)
	}

	await page.reload({ waitUntil: 'domcontentloaded' })
	const row = page.locator('#dc-agent-tbody tr', { hasText: LABEL })
	await row.waitFor({ state: 'visible', timeout: 20000 })

	result.measure = await page.evaluate(() => {
		const wrap = document.querySelector('.dc-agent-tablewrap')
		const table = document.querySelector('.dc-agent-table')
		const wrapR = wrap.getBoundingClientRect()
		const tableR = table.getBoundingClientRect()
		const actions = Array.from(table.querySelectorAll('td.dc-row-actions'))
		const buttons = []
		for (const td of actions) {
			for (const b of td.querySelectorAll('button')) {
				const r = b.getBoundingClientRect()
				buttons.push({
					text: (b.textContent || '').trim(),
					left: Math.round(r.left), right: Math.round(r.right),
					top: Math.round(r.top), bottom: Math.round(r.bottom),
					sw: b.scrollWidth, cw: b.clientWidth,
					clipX: r.right > wrapR.right + 1 || r.right > window.innerWidth,
					glyphClipped: b.scrollWidth > b.clientWidth + 1,
				})
			}
		}
		return {
			viewport: window.innerWidth,
			wrapRight: Math.round(wrapR.right), wrapCW: wrap.clientWidth,
			tableW: Math.round(tableR.width), tableRight: Math.round(tableR.right),
			tableOverflow: tableR.width - wrap.clientWidth,
			buttons,
		}
	})

	mkdirSync(CRAFT, { recursive: true })
	const ts = new Date().toISOString().replace(/[-:]/g, '').replace(/\.\d+Z$/, 'Z')
	result.craft = `${CRAFT}/dc-agent-actions-wrap-de-1280-${ts}.png`
	await page.locator('.dc-agent-tablewrap').first().screenshot({ path: result.craft })
	result.fullshot = `${CRAFT}/dc-agent-actions-wrap-de-1280-full-${ts}.png`
	await page.screenshot({ path: result.fullshot, fullPage: false })
} finally {
	// revoke + delete fixture token (uniform-404 path exercises real code)
	try {
		const rows = db(`SELECT id FROM oc_dc_agent_tokens WHERE label='${LABEL}'`)
		if (rows) {
			db(`UPDATE oc_dc_agent_tokens SET state='revoked', revoked_at=UNIX_TIMESTAMP() WHERE label='${LABEL}'`)
		}
	} catch { /* best-effort */ }
	await browser.close()
}

const m = result.measure || {}
const bad = (m.buttons || []).filter((b) => b.clipX || b.glyphClipped)
result.pass = result.mintStatus === 201 && m.tableOverflow <= 0 && bad.length === 0
result.offenders = bad
console.log(JSON.stringify(result, null, 2))
process.exit(result.pass ? 0 : 1)
