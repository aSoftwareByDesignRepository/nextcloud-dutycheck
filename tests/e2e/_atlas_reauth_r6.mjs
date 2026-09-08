
import { chromium } from 'playwright'
import { mkdirSync } from 'fs'
import { dirname } from 'path'
import { fileURLToPath } from 'url'
import { loginWithFallback, plannerCredsCandidates } from './helpers/auth.js'

const authFile = '.auth/planner.json'
const candidates = plannerCredsCandidates()
console.log('candidates', candidates.map(c => c.username))
if (!candidates.length) { console.error('no creds'); process.exit(2) }
mkdirSync(dirname(authFile), { recursive: true })
const browser = await chromium.launch({ headless: true })
const context = await browser.newContext({ baseURL: 'http://localhost:8081', locale: 'de-DE' })
const page = await context.newPage()
await loginWithFallback(page, candidates)
await page.goto('http://localhost:8081/apps/dutycheck/dashboard', { waitUntil: 'domcontentloaded', timeout: 90000 })
const ok = await page.locator('#dc-main-content, #content').first().isVisible().catch(()=>false)
console.log('landed', page.url(), 'content', ok)
await context.storageState({ path: authFile })
await browser.close()
console.log('auth ok')
