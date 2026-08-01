// @ts-check
import { test, expect } from '@playwright/test'
import AxeBuilder from '@axe-core/playwright'
import { credsFromEnv, login, loginWithFallback, plannerCredsCandidates } from './helpers/auth.js'

/**
 * WCAG 2.1 A/AA smoke for primary DutyCheck surfaces.
 * Skips cleanly when NC_* credentials are not configured (CI without E2E secrets).
 * Employee self-service routes require NC_EMPLOYEE_* (linked account).
 */
// Split settings (SettingsSectionCatalog::SECTIONS): every sub-page gets its
// own axe pass — each one is a distinct document with its own forms/tables.
const settingsSections = [
  'access',
  'duty-roles',
  'planning',
  'companies',
  'conflicts',
  'shift-templates',
  'qualifications',
  'planner-scope',
  'operations',
  'integration',
  'privacy',
  'license',
  'support',
]

const plannerRoutes = [
  '/apps/dutycheck/',
  '/apps/dutycheck/roster',
  '/apps/dutycheck/absences',
  ...settingsSections.map((section) => `/apps/dutycheck/settings/${section}`),
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

test('legacy /settings URL redirects to the default sub-page', async ({ page }) => {
  test.skip(plannerCredsCandidates().length === 0, 'Requires E2E_* or NC_ADMIN_* or NC_EMPLOYEE_* credentials')
  await loginWithFallback(page, plannerCredsCandidates())
  await page.goto('/apps/dutycheck/settings', { waitUntil: 'domcontentloaded' })
  await page.waitForSelector('#dc-main-content', { timeout: 30000 })
  await expect(page).toHaveURL(/\/apps\/dutycheck\/settings\/access$/)
})

test('legacy /settings#anchor bookmark forwards to the owning sub-page', async ({ page }) => {
  test.skip(plannerCredsCandidates().length === 0, 'Requires E2E_* or NC_ADMIN_* or NC_EMPLOYEE_* credentials')
  await loginWithFallback(page, plannerCredsCandidates())
  await page.goto('/apps/dutycheck/settings#dc-settings-quals', { waitUntil: 'domcontentloaded' })
  // Server redirect lands on /settings/access#dc-settings-quals, then
  // settings-legacy-redirect.js forwards to the qualifications sub-page.
  await page.waitForURL(/\/apps\/dutycheck\/settings\/qualifications#dc-settings-quals$/, { timeout: 30000 })
  await page.waitForSelector('#dc-settings-quals', { timeout: 30000 })
})

test('settings sidebar sub-navigation marks the active sub-page', async ({ page }) => {
  test.skip(plannerCredsCandidates().length === 0, 'Requires E2E_* or NC_ADMIN_* or NC_EMPLOYEE_* credentials')
  await loginWithFallback(page, plannerCredsCandidates())
  await page.goto('/apps/dutycheck/settings/companies', { waitUntil: 'domcontentloaded' })
  await page.waitForSelector('#dc-main-content', { timeout: 30000 })
  const active = page.locator('.dc-nav__sublink[aria-current="page"]')
  await expect(active).toHaveCount(1)
  await expect(active).toHaveAttribute('href', /\/settings\/companies$/)
  // All sections must be reachable from the sub-navigation.
  await expect(page.locator('.dc-nav__sublink')).toHaveCount(settingsSections.length)
})

test('settings in-page chip bar mirrors the catalog and marks the active page', async ({ page }) => {
  test.skip(plannerCredsCandidates().length === 0, 'Requires E2E_* or NC_ADMIN_* or NC_EMPLOYEE_* credentials')
  await loginWithFallback(page, plannerCredsCandidates())
  await page.goto('/apps/dutycheck/settings/planning', { waitUntil: 'domcontentloaded' })
  await page.waitForSelector('#dc-settings-pages', { timeout: 30000 })
  const chips = page.locator('#dc-settings-pages .dc-settings-nav__link')
  await expect(chips).toHaveCount(settingsSections.length)
  const activeChip = page.locator('#dc-settings-pages .dc-settings-nav__link[aria-current="page"]')
  await expect(activeChip).toHaveCount(1)
  await expect(activeChip).toHaveAttribute('href', /\/settings\/planning$/)
  // Chip bar is the mobile path when the sidebar collapses — hopping must work.
  await page.locator('#dc-settings-pages .dc-settings-nav__link[href*="/settings/privacy"]').click()
  await page.waitForURL(/\/apps\/dutycheck\/settings\/privacy$/, { timeout: 30000 })
  await page.waitForSelector('#dc-settings-privacy', { timeout: 30000 })
})
