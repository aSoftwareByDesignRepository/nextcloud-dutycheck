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
await page.fill('#user', env.E2E_USER)
await page.fill('#password', env.E2E_PASS)
await page.click('button[type="submit"]')
await page.waitForURL('**/apps/**', { timeout: 30000 }).catch(() => {})
const out = await page.evaluate(async () => {
  const body = JSON.stringify({ periodId: 343, employeeId: 10, locationId: 534, dutyDate: '2199-01-05', startTime: '22:00', endTime: '22:00', breakMinutes: 0, kind: 'standby', note: 'atlas-r8-csrf' })
  const post = (headers) => fetch('/apps/dutycheck/api/assignments', { method: 'POST', headers, body }).then(r => r.status)
  return {
    noToken: await post({ 'Content-Type': 'application/json' }),
    badToken: await post({ 'Content-Type': 'application/json', 'requesttoken': 'forged-token-123' }),
    ocsBypassNoToken: await post({ 'Content-Type': 'application/json', 'OCS-APIRequest': 'true' }),
  }
})
console.log(JSON.stringify(out))
await browser.close()
