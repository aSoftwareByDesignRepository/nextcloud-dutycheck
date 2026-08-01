// @ts-check
import { test, expect } from '@playwright/test'
import { loginWithFallback, plannerCredsCandidates } from './helpers/auth.js'

/**
 * Regression: navigating away while DutyCheck API calls are in flight must not
 * flash a “Network error” toast (aborted fetches were misclassified).
 */
test('navigating away during pending API does not toast network error', async ({ page }) => {
	test.skip(plannerCredsCandidates().length === 0, 'Requires E2E_* or NC_ADMIN_* or NC_EMPLOYEE_* credentials')

	await loginWithFallback(page, plannerCredsCandidates())

	// Hold every DutyCheck API call so navigation cancels in-flight work.
	await page.route('**/apps/dutycheck/api/**', async (route) => {
		await new Promise((r) => setTimeout(r, 8000))
		await route.continue()
	})

	await page.goto('/apps/dutycheck/', { waitUntil: 'domcontentloaded' })
	await page.waitForSelector('#dc-main-content', { timeout: 30000 })

	// Instrument toast + abort classification before leaving.
	await page.evaluate(() => {
		window.__dcNavProbe = { toasts: [], aborted: 0, network: 0 }
		const Msg = window.DutyCheckMessaging
		if (Msg && typeof Msg.announce === 'function') {
			const orig = Msg.announce.bind(Msg)
			Msg.announce = (message, kind) => {
				window.__dcNavProbe.toasts.push({ message: String(message), kind: String(kind || '') })
				return orig(message, kind)
			}
		}
		const Api = window.DutyCheckApi
		if (Api && typeof Api.get === 'function') {
			const origGet = Api.get.bind(Api)
			Api.get = (...args) => origGet(...args).catch((err) => {
				if (Api.isAborted && Api.isAborted(err)) {
					window.__dcNavProbe.aborted += 1
				} else if (err && err.code === 'NETWORK_ERROR') {
					window.__dcNavProbe.network += 1
				}
				throw err
			})
		}
	})

	// Kick a slow request, then leave immediately via in-app navigation.
	await page.evaluate(() => {
		if (window.DutyCheckApi) {
			window.DutyCheckApi.get('/apps/dutycheck/api/dashboard').catch(() => {})
		}
	})

	const rosterLink = page.locator('#app-navigation a[href*="/apps/dutycheck/roster"], a.dc-breadcrumb__brand').first()
	const navTarget = page.locator('#app-navigation a[href*="dutycheck"]').filter({ hasText: /Roster|Dienstplan|Periods|Zeiten|Employees|Mitarbeiter|Settings|Einstellungen/i }).first()

	if (await navTarget.count()) {
		await Promise.all([
			page.waitForURL(/dutycheck/, { timeout: 30000 }),
			navTarget.click(),
		])
	} else if (await rosterLink.count()) {
		await Promise.all([
			page.waitForURL(/dutycheck/, { timeout: 30000 }),
			rosterLink.click(),
		])
	} else {
		await page.goto('/apps/dutycheck/roster', { waitUntil: 'domcontentloaded' })
	}

	// Destination page must not show a network-error toast from the aborted work.
	await page.waitForSelector('#dc-main-content', { timeout: 30000 })
	const errorToasts = page.locator('#dc-toasts .dc-toast--error')
	await expect(errorToasts).toHaveCount(0, { timeout: 2000 })

	const networkToastVisible = await page.evaluate(() => {
		const nodes = Array.from(document.querySelectorAll('#dc-toasts .dc-toast--error'))
		return nodes.some((n) => /network error/i.test(n.textContent || ''))
	})
	expect(networkToastVisible).toBe(false)
})
