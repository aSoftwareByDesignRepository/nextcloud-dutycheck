import { chromium } from '@playwright/test'
import { readFileSync } from 'fs'
const env = {}
for (const line of readFileSync('tests/e2e/.env', 'utf8').split('\n')) {
  const m = line.match(/^\s*([A-Z0-9_]+)\s*=\s*(.*)\s*$/)
  if (m) env[m[1]] = m[2].replace(/^["']|["']$/g, '')
}
const BASE = env.NC_BASE_URL
function lum(c) {
  const [r, g, b] = c.match(/\d+(\.\d+)?/g).slice(0, 3).map(Number).map(v => {
    v /= 255; return v <= 0.04045 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4)
  })
  return 0.2126 * r + 0.7152 * g + 0.0722 * b
}
const browser = await chromium.launch()
const page = await (await browser.newContext()).newPage()
await page.goto(`${BASE}/login`, { waitUntil: 'domcontentloaded' })
await page.fill('#user', env.E2E_USER)
await page.fill('#password', env.E2E_PASS)
await page.click('button[type="submit"]')
await page.waitForURL('**/apps/**', { timeout: 30000 }).catch(() => {})
await page.route('**/apps/dutycheck/api/roster**', async route => {
  if (route.request().url().includes('signals')) { await route.continue(); return }
  const resp = await route.fetch(); let parsed = {}; try { parsed = JSON.parse(await resp.text()) } catch {}
  const env2 = parsed?.data ? parsed : { data: parsed }
  const data = { ...(env2.data || {}) }
  await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ ...env2, data: { ...data, standbyEnabled: true, standbyCategories: [], employees: [{ id: 9, displayName: 'X', active: true }], periods: [{ id: 343, status: 'open', startDate: '2199-01-04', endDate: '2199-01-10', name: 'x' }], selectedPeriodId: 343, canCreateAssignments: true, locations: [{ id: 1, name: 'L' }], assignments: [{ id: 1, employeeId: 9, employeeName: 'X', dutyDate: '2199-01-05', startTime: '00:00:00', endTime: '00:00:00', breakMinutes: 0, locationName: 'L', kind: 'standby', standbyCategoryLabel: 'Nacht', note: '' }], conflicts: [], absenceBlocks: [] } }) })
})
await page.goto(`${BASE}/apps/dutycheck/roster?periodId=343`, { waitUntil: 'domcontentloaded' })
await page.waitForSelector('.dc-badge--standby, .dc-roster-grid__cell--standby', { timeout: 30000 }).catch(() => {})
// measure standby badge + legend chip + kind-choice card contrast
await page.locator('#dc-roster-view-list').click().catch(() => {})
await page.waitForTimeout(500)
const results = await page.evaluate(() => {
  const els = {
    badge: document.querySelector('.dc-badge--standby'),
    legend: document.querySelector('#dc-legend-chip-standby'),
    cell: document.querySelector('.dc-roster-grid__cell--standby'),
    kindCard: document.querySelector('.dc-kind-choice label'),
  }
  const out = {}
  for (const [k, el] of Object.entries(els)) {
    if (!el) { out[k] = null; continue }
    const cs = getComputedStyle(el)
    out[k] = { color: cs.color, bg: cs.backgroundColor, border: cs.borderColor, fs: cs.fontSize, fw: cs.fontWeight }
  }
  return out
})
for (const [k, v] of Object.entries(results)) {
  if (!v) { console.log(k, 'MISSING'); continue }
  const fg = lum(v.color), bg = v.bg === 'rgba(0, 0, 0, 0)' ? 1 : lum(v.bg)
  const ratio = (Math.max(fg, bg) + 0.05) / (Math.min(fg, bg) + 0.05)
  console.log(k, JSON.stringify(v), `contrast=${ratio.toFixed(2)}:1`, ratio >= 4.5 ? 'AA-pass' : (ratio >= 3 ? 'AA-large-only' : 'FAIL'))
}
await browser.close()
