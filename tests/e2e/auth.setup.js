// @ts-check
import { mkdirSync } from 'fs'
import { dirname } from 'path'
import { test as setup } from '@playwright/test'
import { loginWithFallback, plannerCredsCandidates } from './helpers/auth.js'

const authFile = 'tests/e2e/.auth/planner.json'

setup('authenticate planner', async ({ page }) => {
  const candidates = plannerCredsCandidates()
  setup.skip(candidates.length === 0, 'Requires E2E_* or NC_ADMIN_* or NC_EMPLOYEE_* credentials')
  mkdirSync(dirname(authFile), { recursive: true })
  await loginWithFallback(page, candidates)
  await page.context().storageState({ path: authFile })
})
