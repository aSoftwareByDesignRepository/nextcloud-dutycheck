/**
 * Nextcloud 34 Vue login is async; wait for #user before fill.
 * Supports NC_*_PASS or NC_*_PASSWORD (and E2E_* aliases via caller env).
 * Fail-fast on wrong credentials so a11y does not hang for 30s on /login.
 */

/**
 * @param {import('@playwright/test').Page} page
 * @param {{ username: string, password: string }} creds
 */
export async function login(page, { username, password }) {
  await page.goto('/login', { waitUntil: 'domcontentloaded' })
  // Already authenticated (storageState / prior test).
  if (!page.url().includes('/login')) {
    return
  }
  const userInput = page.locator('input#user, input[name="user"]').first()
  const passInput = page.locator('input#password, input[name="password"]').first()
  try {
    await userInput.waitFor({ state: 'visible', timeout: 30_000 })
  } catch {
    if (!page.url().includes('/login')) {
      return
    }
    throw new Error('Login form not ready (Vue #login)')
  }
  await userInput.fill(username)
  await passInput.fill(password)
  await page.locator('button[type="submit"], input[type="submit"]').first().click()

  const outcome = await Promise.race([
    page.waitForURL((url) => !url.pathname.includes('/login'), { timeout: 45_000 }).then(() => 'ok'),
    page.getByText(/Wrong login or password/i).waitFor({ state: 'visible', timeout: 45_000 }).then(() => 'bad'),
  ])
  if (outcome === 'bad') {
    throw new Error(`Login rejected for user "${username}" (Wrong login or password)`)
  }
  await page.waitForLoadState('domcontentloaded')
}

/**
 * Prefer E2E_* then NC_ADMIN_* then NC_EMPLOYEE_* so stale shell NC_ADMIN_*
 * does not shadow a working E2E password (common local-dev footgun).
 *
 * @returns {Array<{ username: string, password: string }>}
 */
export function plannerCredsCandidates() {
  /** @type {Array<{ username: string, password: string }>} */
  const out = []
  const push = (u, p) => {
    if (u && p) {
      out.push({ username: u, password: p })
    }
  }
  push(process.env.E2E_USER, process.env.E2E_PASSWORD || process.env.E2E_PASS)
  push(process.env.NC_ADMIN_USER, process.env.NC_ADMIN_PASS || process.env.NC_ADMIN_PASSWORD)
  push(process.env.NC_EMPLOYEE_USER, process.env.NC_EMPLOYEE_PASS || process.env.NC_EMPLOYEE_PASSWORD)
  // Deduplicate identical pairs while preserving order.
  const seen = new Set()
  return out.filter((c) => {
    const key = `${c.username}\0${c.password}`
    if (seen.has(key)) {
      return false
    }
    seen.add(key)
    return true
  })
}

/**
 * Try candidates until one logs in; skip silent failures only for wrong password.
 *
 * @param {import('@playwright/test').Page} page
 * @param {Array<{ username: string, password: string }>} candidates
 */
export async function loginWithFallback(page, candidates) {
  if (!candidates.length) {
    throw new Error('No planner credentials configured (E2E_* / NC_ADMIN_* / NC_EMPLOYEE_*)')
  }
  /** @type {Error | null} */
  let last = null
  for (const creds of candidates) {
    try {
      await login(page, creds)
      return
    } catch (err) {
      last = err instanceof Error ? err : new Error(String(err))
      if (!/Wrong login or password/i.test(last.message)) {
        throw last
      }
    }
  }
  throw last || new Error('All planner credential candidates failed')
}

/**
 * @param {'ADMIN' | 'EMPLOYEE' | string} role
 */
export function credsFromEnv(role) {
  const u = process.env[`NC_${role}_USER`] || (role === 'ADMIN' ? process.env.E2E_USER : undefined)
  const p = process.env[`NC_${role}_PASS`]
    || process.env[`NC_${role}_PASSWORD`]
    || (role === 'ADMIN' ? (process.env.E2E_PASSWORD || process.env.E2E_PASS) : undefined)
  if (!u || !p) {
    throw new Error(`Missing env vars NC_${role}_USER / NC_${role}_PASS`)
  }
  return { username: u, password: p }
}
