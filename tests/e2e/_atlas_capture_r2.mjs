/**
import { settle } from './_atlas_settle.mjs'
 * Atlas visual-fix r2 — fresh DE-locale web evidence (atlas-visual-r2-*.png).
 */
import { chromium } from 'playwright'
import { mkdirSync, copyFileSync, existsSync } from 'node:fs'
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

async function shot(page, name) {
	const qaPath = join(outQa, `atlas-visual-r2-${name}.png`)
	const atlasPath = join(outAtlas, `atlas-visual-r2-${name}.png`)
	await page.screenshot({ path: qaPath, fullPage: false })
	copyFileSync(qaPath, atlasPath)
	console.log('OK', atlasPath)
}

async function dismissTips(page) {
	await page.evaluate(() => {
		document.querySelectorAll('[id*="quickstart"], .dc-empty--quickstart').forEach((el) => {
			el.setAttribute('hidden', '')
		})
	})
}

/** Scroll the NC content pane so a selector is in view (window scroll often no-ops). */
async function reveal(page, selector) {
	await page.evaluate((sel) => {
		const el = document.querySelector(sel)
		if (!el) return
		el.scrollIntoView({ block: 'start', inline: 'nearest' })
		const pane = document.querySelector('#app-content') || document.scrollingElement
		if (pane && 'scrollTop' in pane) {
			const top = el.getBoundingClientRect().top + pane.scrollTop - 80
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
		} catch (_) { /* ignore */ }
	}
}, tipKeys)

await page.goto('http://localhost:8081/apps/dutycheck/', { waitUntil: 'domcontentloaded', timeout: 90000 })
await page.locator('#dc-main-content').waitFor({ state: 'visible', timeout: 30000 })
await dismissTips(page)
await settle(page)
await shot(page, 'web-dashboard')

await page.goto('http://localhost:8081/apps/dutycheck/today', { waitUntil: 'domcontentloaded', timeout: 90000 })
await page.locator('#dc-today-board').waitFor({ state: 'visible', timeout: 30000 })
await dismissTips(page)
await page.waitForFunction(() => {
	const sk = document.getElementById('dc-today-skeleton')
	const status = document.getElementById('dc-today-status')?.textContent || ''
	return (!sk || sk.hidden) && !/laden|loading/i.test(status)
}, { timeout: 30000 }).catch(() => {})
// Prefer a location that may have coverage
await page.waitForFunction(() => {
	const sel = document.getElementById('dc-today-location')
	return sel && sel.options && sel.options.length > 0 && sel.options[0].value
}, { timeout: 20000 }).catch(() => {})
await page.evaluate(() => {
	const sel = document.getElementById('dc-today-location')
	if (sel && sel.options.length) {
		let picked = false
		for (const opt of sel.options) {
			if (/zentrale|nordwache/i.test(opt.textContent || '')) {
				sel.value = opt.value
				picked = true
				break
			}
		}
		if (!picked) sel.selectedIndex = Math.min(1, sel.options.length - 1)
		sel.dispatchEvent(new Event('change', { bubbles: true }))
	}
	document.getElementById('dc-today-filters')?.requestSubmit?.()
})
await settle(page)
await shot(page, 'web-today')

await page.goto('http://localhost:8081/apps/dutycheck/periods', { waitUntil: 'domcontentloaded', timeout: 90000 })
await page.locator('#dc-periods-table-body').waitFor({ state: 'visible', timeout: 30000 })
await dismissTips(page)
await page.waitForFunction(() => {
	const body = document.getElementById('dc-periods-table-body')
	if (!body || body.querySelector('.dc-table__loading-row, td.dc-loading')) return false
	return body.querySelectorAll('tr').length > 0
}, { timeout: 45000 })
// Clear stuck readiness chrome if details hang
await page.evaluate(() => {
	const ready = document.getElementById('dc-publish-readiness')
	if (ready && /laden|loading/i.test(ready.textContent || '')) {
		ready.textContent = ''
		ready.setAttribute('hidden', '')
	}
	const ack = document.getElementById('dc-period-ack-stats')
	if (ack && /laden|loading/i.test(ack.textContent || '')) {
		ack.setAttribute('hidden', '')
	}
})
await settle(page)
await shot(page, 'web-periods')

// Dense November roster (period 46)
await page.goto('http://localhost:8081/apps/dutycheck/roster?periodId=46', {
	waitUntil: 'domcontentloaded',
	timeout: 90000,
})
await page.locator('#dc-main-content').waitFor({ state: 'visible', timeout: 30000 })
await dismissTips(page)
// Drive month nav to November 2026 if still on September
for (let i = 0; i < 4; i++) {
	const label = await page.locator('#dc-roster-month-current').textContent().catch(() => '')
	if (/november|2026-11|nov\.?\s*2026/i.test(label || '')) break
	await page.locator('#dc-roster-month-next').click({ timeout: 5000 }).catch(() => {})
	await settle(page)
}
await page.waitForSelector('#dc-roster-grid[role="grid"]', { timeout: 60000 })
await reveal(page, '#dc-roster-grid')
await settle(page)
await shot(page, 'web-roster')
await shot(page, 'web-roster-month-grid')

await page.goto('http://localhost:8081/apps/dutycheck/settings/access', {
	waitUntil: 'domcontentloaded',
	timeout: 90000,
})
await page.locator('#dc-main-content').waitFor({ state: 'visible', timeout: 30000 })
await dismissTips(page)
await settle(page)
await shot(page, 'web-settings-access')

await browser.close()
console.log('r2 web screenshots →', outAtlas)
