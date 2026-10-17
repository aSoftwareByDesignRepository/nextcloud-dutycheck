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
  // cleanup all atlas-r7 rows incl. cancelled
  const roster = await Api.get('/apps/dutycheck/api/roster', { periodId: 343 })
  const rows = (roster?.data?.assignments || []).filter(a => String(a.note) === 'atlas-r7-concurrency')
  log.leftover = rows.map(r => ({ id: r.id, status: r.status, kind: r.kind }))
  for (const r of rows) {
    if (r.status !== 'cancelled') {
      await Api.post(`/apps/dutycheck/api/assignments/${r.id}/cancel`, {}).then(() => log['cancel_' + r.id] = 'ok').catch(e => log['cancel_' + r.id] = e?.payload?.error?.code || e?.code || String(e))
    }
  }
  // verify cancelled slot frees for re-create (forCancelled slot-key)
  const re = await Api.post('/apps/dutycheck/api/assignments', {
    periodId: 343, employeeId: 10, locationId: 534, dutyDate: '2199-01-04',
    startTime: '22:00', endTime: '22:00', breakMinutes: 0, kind: 'standby', note: 'atlas-r7-recreate',
  }).then(r => ({ ok: true, id: r?.data?.createdAssignmentId || r?.data?.id })).catch(e => ({ ok: false, code: e?.payload?.error?.code || e?.code }))
  log.recreateAfterCancel = re
  if (re.ok && re.id) {
    await Api.post(`/apps/dutycheck/api/assignments/${re.id}/cancel`, {}).catch(() => {})
    log.finalCleanup = 'done'
  }
  return log
})
console.log(JSON.stringify(out, null, 2))
await browser.close()
