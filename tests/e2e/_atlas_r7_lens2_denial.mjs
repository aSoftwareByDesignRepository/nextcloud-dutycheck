// Atlas R7 Lens 2b: unprivileged user hits planner/admin standby endpoints.
import { chromium } from '@playwright/test'
import { readFileSync } from 'fs'
const env = {}
for (const line of readFileSync('tests/e2e/.env', 'utf8').split('\n')) {
  const m = line.match(/^\s*([A-Z0-9_]+)\s*=\s*(.*)\s*$/)
  if (m) env[m[1]] = m[2].replace(/^["']|["']$/g, '')
}
const BASE = env.NC_BASE_URL || 'http://localhost:8081'
const browser = await chromium.launch()
const page = await (await browser.newContext()).newPage()
await page.goto(`${BASE}/login`, { waitUntil: 'domcontentloaded' })
await page.fill('#user', 'atlas_r7_peon')
await page.fill('#password', 'AtlasR7_Peon!x')
await page.click('button[type="submit"]')
await page.waitForURL('**/apps/**', { timeout: 30000 }).catch(() => {})
// app may deny the door entirely — still try API direct via fetch w/ requesttoken
const out = await page.evaluate(async () => {
  const token = document.querySelector('head')?.getAttribute('data-requesttoken')
    || (window.OC && window.OC.requestToken) || ''
  const call = async (method, path, body) => {
    try {
      const res = await fetch(path, {
        method,
        headers: { 'requesttoken': token, 'OCS-APIREQUEST': 'true', 'Content-Type': 'application/json', 'Accept': 'application/json' },
        body: body ? JSON.stringify(body) : undefined,
      })
      let json = null
      try { json = await res.json() } catch {}
      return { status: res.status, code: json?.error?.code || json?.payload?.error?.code || (json?.ocs?.meta?.status ?? null), body: JSON.stringify(json).slice(0, 200) }
    } catch (e) { return { fatal: String(e) } }
  }
  return {
    getCategories: await call('GET', '/apps/dutycheck/api/standby/categories'),
    postCategory: await call('POST', '/apps/dutycheck/api/standby/categories', { label: 'peon' }),
    getStandbyRoster: await call('GET', '/apps/dutycheck/api/standby/roster/343'),
    postAssignment: await call('POST', '/apps/dutycheck/api/assignments', {
      periodId: 343, employeeId: 10, locationId: 534, dutyDate: '2199-01-07',
      startTime: '22:00', endTime: '22:00', breakMinutes: 0, kind: 'standby',
    }),
    rosterRead: await call('GET', '/apps/dutycheck/api/roster'),
    appPage: location.pathname,
  }
})
console.log(JSON.stringify(out, null, 1))
await browser.close()
