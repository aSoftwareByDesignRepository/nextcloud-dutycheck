import { chromium } from '@playwright/test'
import { readFileSync } from 'fs'
const env = {}
for (const line of readFileSync('tests/e2e/.env', 'utf8').split('\n')) {
  const m = line.match(/^\s*([A-Z0-9_]+)\s*=\s*(.*)\s*$/)
  if (m) env[m[1]] = m[2].replace(/^["']|["']$/g, '')
}
const BASE = env.NC_BASE_URL
const browser = await chromium.launch()
const page = await (await browser.newContext()).newPage()
await page.goto(`${BASE}/login`, { waitUntil: 'domcontentloaded' })
await page.fill('#user', env.E2E_USER)
await page.fill('#password', env.E2E_PASS)
await page.click('button[type="submit"]')
await page.waitForURL('**/apps/**', { timeout: 30000 }).catch(() => {})
await page.goto(`${BASE}/apps/dutycheck/roster`, { waitUntil: 'domcontentloaded' })
await page.waitForFunction(() => !!window.DutyCheckApi, { timeout: 30000 })
const out = await page.evaluate(async () => {
  const Api = window.DutyCheckApi
  const log = {}
  // delete probe categories (id 1,2 created with label 'x')
  const cats = await Api.get('/apps/dutycheck/api/standby/categories')
  for (const c of cats?.data?.categories || []) {
    if (String(c.label) === 'x') {
      await Api.del(`/apps/dutycheck/api/standby/categories/${c.id}`).then(() => log['del_cat_' + c.id] = 'ok').catch(e => log['del_cat_' + c.id] = e?.payload?.error?.code || String(e))
    }
  }
  // cancel leftover atlas assignment on 2199-01-05 (employee probe created it)
  const roster = await Api.get('/apps/dutycheck/api/roster', { periodId: 343 })
  for (const a of (roster?.data?.assignments || [])) {
    if ((a.dutyDate === '2199-01-05' || a.dutyDate === '2199-01-06') && a.status !== 'cancelled'
        && (a.kind === 'standby' || String(a.note || '').includes('atlas'))) {
      await Api.post(`/apps/dutycheck/api/assignments/${a.id}/cancel`, {}).then(() => log['cancel_' + a.id] = 'ok').catch(e => log['cancel_' + a.id] = e?.payload?.error?.code || String(e))
    }
  }
  return log
})
console.log(JSON.stringify(out, null, 1))
await browser.close()
