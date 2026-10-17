import { chromium } from '@playwright/test'
import { readFileSync } from 'fs'
const env = {}
for (const line of readFileSync('tests/e2e/.env', 'utf8').split('\n')) {
  const m = line.match(/^\s*([A-Z0-9_]+)\s*=\s*(.*)\s*$/)
  if (m) env[m[1]] = m[2].replace(/^["']|["']$/g, '')
}
const BASE = env.NC_BASE_URL
function lumFromMatch(nums) {
  const [r, g, b] = nums.slice(0, 3).map(Number).map(v => {
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
  await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ ...env2, data: { ...data, standbyEnabled: true, standbyCategories: [], employees: [{ id: 9, displayName: 'X', active: true }], periods: [{ id: 343, status: 'open', startDate: '2199-01-04', endDate: '2199-01-10', name: 'x' }], selectedPeriodId: 343, canCreateAssignments: true, locations: [{ id: 1, name: 'L' }], assignments: [], conflicts: [], absenceBlocks: [] } }) })
})
await page.goto(`${BASE}/apps/dutycheck/roster?periodId=343`, { waitUntil: 'domcontentloaded' })
await page.waitForSelector('#dc-roster-add-assignment:not([disabled])', { timeout: 30000 })
await page.locator('#dc-roster-add-assignment').click()
await page.waitForSelector('.dc-kind-choice label', { timeout: 15000 })
// measure kind-choice cards + radio labels in the open modal, effective bg
const results = await page.evaluate(() => {
  function effectiveBg(el) {
    let cur = el
    while (cur && cur !== document.documentElement) {
      const bg = getComputedStyle(cur).backgroundColor
      if (bg && bg !== 'rgba(0, 0, 0, 0)' && bg !== 'transparent') return bg
      cur = cur.parentElement
    }
    return getComputedStyle(document.body).backgroundColor
  }
  const out = {}
  document.querySelectorAll('.dc-kind-choice label').forEach((el, i) => {
    const cs = getComputedStyle(el)
    out['kindLabel' + i] = { text: el.textContent.trim().slice(0, 30), color: cs.color, effBg: effectiveBg(el), fs: cs.fontSize, fw: cs.fontWeight }
  })
  const desc = document.querySelector('.dc-kind-choice .dc-field__hint, .dc-kind-choice small, .dc-kind-choice [class*="desc"]')
  if (desc) {
    const cs = getComputedStyle(desc)
    out.kindDesc = { text: desc.textContent.trim().slice(0, 40), color: cs.color, effBg: effectiveBg(desc), fs: cs.fontSize }
  }
  return out
})
for (const [k, v] of Object.entries(results)) {
  const fgM = (v.color.match(/[\d.]+/g) || []).map(Number)
  const bgM = (v.effBg.match(/[\d.]+/g) || []).map(Number)
  const fg = lumFromMatch(fgM), bg = lumFromMatch(bgM)
  const ratio = (Math.max(fg, bg) + 0.05) / (Math.min(fg, bg) + 0.05)
  console.log(k, `"${v.text}"`, `${v.fs}/${v.fw}`, `contrast=${ratio.toFixed(2)}:1`, ratio >= 4.5 ? 'AA-pass' : 'FAIL')
}
await browser.close()
