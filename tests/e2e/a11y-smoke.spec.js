// @ts-check
import { test, expect } from '@playwright/test'
import AxeBuilder from '@axe-core/playwright'
import { credsFromEnv, login, loginWithFallback, plannerCredsCandidates } from './helpers/auth.js'

/**
 * WCAG 2.1 A/AA smoke for primary DutyCheck surfaces.
 * Skips cleanly when NC_* credentials are not configured (CI without E2E secrets).
 * Employee self-service routes require NC_EMPLOYEE_* (linked account).
 */
const plannerRoutes = [
  '/apps/dutycheck/',
  '/apps/dutycheck/roster',
  '/apps/dutycheck/absences',
  '/apps/dutycheck/settings',
]

const employeeRoutes = [
  '/apps/dutycheck/my-absences',
  '/apps/dutycheck/my-roster',
]

/**
 * @param {import('@playwright/test').Page} page
 * @param {string} path
 */
async function assertA11y(page, path) {
  await page.goto(path, { waitUntil: 'domcontentloaded' })
  await page.waitForSelector('#dc-main-content', { timeout: 30000 })
  // Wait for body theme variables so axe does not race NC dark/light CSS.
  await page.waitForFunction(() => {
    const body = getComputedStyle(document.body)
    return body.getPropertyValue('--color-main-text').trim() !== ''
      && body.getPropertyValue('--color-main-background').trim() !== ''
  }, null, { timeout: 10000 }).catch(() => {})
  await page.locator('#dc-toasts .dc-toast').evaluateAll((nodes) => nodes.forEach((n) => n.remove())).catch(() => {})
  const results = await new AxeBuilder({ page })
    .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
    .exclude('#dc-toasts')
    .analyze()
  expect(results.violations, JSON.stringify(results.violations, null, 2)).toEqual([])
}

for (const path of plannerRoutes) {
  test(`a11y smoke: ${path}`, async ({ page }) => {
    test.skip(plannerCredsCandidates().length === 0, 'Requires E2E_* or NC_ADMIN_* or NC_EMPLOYEE_* credentials')
    await loginWithFallback(page, plannerCredsCandidates())
    await assertA11y(page, path)
  })
}

for (const path of employeeRoutes) {
  test(`a11y smoke (employee): ${path}`, async ({ page }) => {
    test.skip(!process.env.NC_EMPLOYEE_USER, 'Requires NC_EMPLOYEE_* linked employee credentials')
    await login(page, credsFromEnv('EMPLOYEE'))
    await assertA11y(page, path)
  })
}
