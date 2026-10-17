import { chromium } from '@playwright/test'
import { readFileSync } from 'fs'
const env = {}
for (const line of readFileSync('tests/e2e/.env', 'utf8').split('\n')) {
  const m = line.match(/^\s*([A-Z0-9_]+)\s*=\s*(.*)\s*$/)
  if (m) env[m[1]] = m[2].replace(/^["']|["']$/g, '')
}
const BASE = env.NC_BASE_URL || 'http://localhost:8081'
const browser = await chromium.launch()
const ctx = await browser.newContext({ locale: 'de-DE' })
const page = await ctx.newPage()
await page.goto(`${BASE}/login`, { waitUntil: 'domcontentloaded' })
await page.fill('#user', env.E2E_USER)
await page.fill('#password', env.E2E_PASS)
await page.click('button[type="submit"]')
await page.waitForURL('**/apps/**', { timeout: 30000 }).catch(() => {})
await page.route('**/apps/dutycheck/api/roster?*', route => route.continue())
await page.route('**/apps/dutycheck/api/roster', async route => {
  const resp = await route.fetch()
  let parsed = {}; try { parsed = JSON.parse(await resp.text()) } catch {}
  const env2 = parsed?.data ? parsed : { data: parsed }
  const data = { ...(env2.data || {}) }
  await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ ...env2, data: { ...data, standbyEnabled: true, standbyCategories: [{ id: 77, label: 'Nachtbereitschaft', color: '#3366aa', defaultResponseTimeMinutes: 45, active: true }], assignments: [{ id: 1, employeeId: 9, employeeName: 'X', dutyDate: '2199-01-05', startTime: '22:00', endTime: '22:00', breakMinutes: 0, locationName: 'L', kind: 'standby', standbyCategoryLabel: 'Nachtbereitschaft', note: '' }] } }) })
})
await page.goto(`${BASE}/apps/dutycheck/roster?periodId=343`, { waitUntil: 'domcontentloaded' })
await page.waitForSelector('#dc-roster-add-assignment', { timeout: 30000 })
// force de via OC l10n? check what language the page renders + scan for key-shaped text
const scan = await page.evaluate(async () => {
  // open the modal for standby strings
  const btn = document.getElementById('dc-roster-add-assignment')
  if (btn && !btn.disabled) btn.click()
  await new Promise(r => setTimeout(r, 1200))
  const text = document.body.innerText
  const keyPattern = /\b[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*\.[a-z][a-z0-9_]+\b/g
  const rawKeys = (text.match(keyPattern) || []).slice(0, 10)
  const standbyBits = {
    hasStandbyLabelDE: /Bereitschaft|Rufbereitschaft|Astreinte|On-call/i.test(text),
    hasEnglishLeak: /On-call \(standby\)|Worked duty|Response time/i.test(text),
    legendStandby: !!document.querySelector('#dc-legend-chip-standby'),
    kindRadios: [...document.querySelectorAll('input[name="assignmentKind"]')].length,
    standbyFieldVisible: !!document.querySelector('#dc-assignment-standby-fields'),
  }
  return { rawKeys, standbyBits, htmlLang: document.documentElement.lang, sample: text.slice(0, 0) }
})
console.log(JSON.stringify(scan, null, 1))
await browser.close()
