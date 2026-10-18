// @ts-check
import { test, expect } from '@playwright/test'
import { plannerCredsCandidates } from './helpers/auth.js'

/**
 * Timezone-matrix regression for the 2026-10-18 customer report:
 * every prior farm context ran Europe/Berlin, where `new Date('YYYY-MM-DD')`
 * (UTC midnight) lands on the same calendar day — a Buffalo user (UTC-4/5)
 * saw "pick N, display N-1". This spec runs the real production code path in
 * real browser engines under a negative-offset and a UTC+14 zone.
 *
 * Invariant: parseDisplayDateToIso(formatDisplayDate(d)) === d for every
 * calendar day, plus the picked value round-trips through <input type=date>
 * untouched, and every date input carries a visible labelled picker button.
 */

const ZONES = ['America/New_York', 'Pacific/Kiritimati']
const DAYS = ['2026-01-01', '2026-03-29', '2026-10-20', '2026-11-01', '2026-12-31']

for (const zone of ZONES) {
  test.describe(`tz dates: ${zone}`, () => {
    test.use({ timezoneId: zone })

    test(`date-only values render on the picked day (${zone})`, async ({ page }) => {
      test.skip(
        plannerCredsCandidates().length === 0,
        'Requires E2E_* or NC_ADMIN_* credentials',
      )
      await page.goto('/apps/dutycheck/periods', { waitUntil: 'domcontentloaded' })
      await page.waitForSelector('#dc-main-content', { timeout: 30000 })
      await page.waitForFunction(() => typeof window.DutyCheckDates !== 'undefined')

      const shifts = await page.evaluate((days) => {
        const D = window.DutyCheckDates
        return days.map((d) => ({
          day: d,
          rendered: D.formatDisplayDate(d),
          roundTrip: D.parseDisplayDateToIso(D.formatDisplayDate(d)),
        }))
      }, DAYS)
      for (const r of shifts) {
        expect(r.roundTrip, `${r.day} rendered '${r.rendered}' in ${zone}`).toBe(r.day)
      }

      // Picked value must not shift in the input itself.
      const start = page.locator('#dc-period-start')
      if (await start.count()) {
        await start.fill('2026-10-20')
        expect(await start.inputValue()).toBe('2026-10-20')
      }
    })

    test(`date inputs expose a visible labelled picker (${zone})`, async ({ page }) => {
      test.skip(
        plannerCredsCandidates().length === 0,
        'Requires E2E_* or NC_ADMIN_* credentials',
      )
      await page.goto('/apps/dutycheck/periods', { waitUntil: 'domcontentloaded' })
      await page.waitForSelector('#dc-main-content', { timeout: 30000 })

      const pickers = page.locator('input[type="date"] >> xpath=../button[contains(@class,"dc-date-picker")]')
      const inputCount = await page.locator('input[type="date"]').count()
      expect(inputCount).toBeGreaterThan(0)
      expect(await pickers.count(), 'every date input needs a .dc-date-picker button').toBe(inputCount)
      for (let i = 0; i < await pickers.count(); i++) {
        const btn = pickers.nth(i)
        await expect(btn).toBeVisible()
        expect((await btn.getAttribute('aria-label')) || '').not.toBe('')
        const box = await btn.boundingBox()
        // CSS targets 44px; assert >=40 to tolerate sub-pixel/scrollbars.
        expect(box && Math.min(box.width, box.height) >= 40, 'picker target too small').toBeTruthy()
      }
    })
  })
}
