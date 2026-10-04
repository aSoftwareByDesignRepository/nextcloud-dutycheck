import { chromium } from '@playwright/test'
import { login } from '../helpers/auth.js'
import { readFileSync } from 'fs'
const env = readFileSync('tests/e2e/.env', 'utf8')
const g = (k) => { const m = env.match(new RegExp(`^${k}=(.*)$`, 'm')); return m ? m[1].trim() : '' }
const browser = await chromium.launch()
const ctx = await browser.newContext({ baseURL: 'http://localhost:8081' })
const page = await ctx.newPage()
await login(page, { username: g('NC_EMPLOYEE_USER'), password: g('NC_EMPLOYEE_PASS') })
await ctx.storageState({ path: 'tests/e2e/.auth/employee.json' })
console.log('employee session stored, user=', g('NC_EMPLOYEE_USER'))
await browser.close()
