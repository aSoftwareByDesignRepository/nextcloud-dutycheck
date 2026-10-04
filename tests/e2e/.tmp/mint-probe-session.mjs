import { chromium } from '@playwright/test'
import { login } from '../helpers/auth.js'
const browser = await chromium.launch()
const ctx = await browser.newContext({ baseURL: 'http://localhost:8081' })
const page = await ctx.newPage()
await login(page, { username: 'dc_atlas_probe', password: 'AtlasProbe!2026' })
await ctx.storageState({ path: 'tests/e2e/.auth/probe.json' })
console.log('probe session stored')
await browser.close()
