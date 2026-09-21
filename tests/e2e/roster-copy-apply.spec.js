// @ts-check
/**
 * Roster copy: Apply CTA hidden until Preview reports something to copy.
 */
import { test, expect } from '@playwright/test'
import { assertNotServerUpdater, plannerCredsCandidates } from './helpers/auth.js'

/**
 * Arm the copy panel and stub DutyCheckApi.post for /copy in one turn so a
 * concurrent roster render cannot wipe the source option mid-click.
 *
 * @param {import('@playwright/test').Page} page
 * @param {{ wouldCreate: number, wouldSkip?: number }} preview
 */
async function runPreviewWithStub(page, preview) {
	await page.waitForSelector('#dc-roster-copy-period[data-dc-copy-ready="1"]', {
		state: 'attached',
		timeout: 60_000,
	})
	const result = await page.evaluate(async (previewData) => {
		const wrap = document.getElementById('dc-roster-copy-period')
		const select = document.getElementById('dc-roster-copy-source')
		const switcher = document.getElementById('dc-roster-period-switcher')
		const apply = document.getElementById('dc-roster-copy-apply')
		const previewBtn = document.getElementById('dc-roster-copy-preview')
		const status = document.getElementById('dc-roster-copy-status')
		const Api = window.DutyCheckApi
		if (!wrap || !select || !switcher || !apply || !previewBtn || !Api?.post) {
			return { ok: false, reason: 'missing-dom-or-api' }
		}
		wrap.hidden = false
		if (!switcher.value) {
			const opt = document.createElement('option')
			opt.value = '101'
			opt.textContent = 'target'
			switcher.appendChild(opt)
			switcher.value = '101'
		}
		select.replaceChildren()
		const sourceOpt = document.createElement('option')
		sourceOpt.value = '202'
		sourceOpt.textContent = 'source'
		select.appendChild(sourceOpt)
		select.value = '202'
		apply.hidden = true
		apply.disabled = true
		apply.setAttribute('aria-disabled', 'true')

		const originalPost = Api.post.bind(Api)
		Api.post = async (path, body, options) => {
			if (String(path).includes('/copy')) {
				return {
					data: {
						wouldCreate: previewData.wouldCreate,
						wouldSkip: previewData.wouldSkip ?? 0,
						skipped: previewData.wouldSkip ?? 0,
						created: previewData.wouldCreate,
					},
				}
			}
			return originalPost(path, body, options)
		}
		try {
			previewBtn.click()
			// Let the async click handler finish.
			await new Promise((r) => setTimeout(r, 50))
			for (let i = 0; i < 40; i += 1) {
				if (status && !status.hidden && String(status.textContent || '').trim() !== '') {
					break
				}
				await new Promise((r) => setTimeout(r, 50))
			}
			return {
				ok: true,
				statusText: status ? String(status.textContent || '') : '',
				statusHidden: status ? status.hidden : true,
				applyHidden: apply.hidden,
				applyDisabled: apply.disabled,
			}
		} finally {
			Api.post = originalPost
		}
	}, preview)
	expect(result.ok, JSON.stringify(result)).toBe(true)
	return result
}

test.describe('roster copy apply visibility', () => {
	test.beforeEach(async ({ page }) => {
		test.skip(plannerCredsCandidates().length === 0, 'Requires E2E_* or NC_ADMIN_* credentials')
	})

	test('Apply copy stays hidden until preview wouldCreate > 0', async ({ page }) => {
		await page.goto('/apps/dutycheck/roster', { waitUntil: 'domcontentloaded' })
		await assertNotServerUpdater(page)
		await page.waitForSelector('#dc-roster-month-label, #dc-roster-period-switcher', { timeout: 30_000 })

		const before = await page.evaluate(() => {
			const apply = document.getElementById('dc-roster-copy-apply')
			return apply ? apply.hidden : null
		})
		expect(before).toBe(true)

		const result = await runPreviewWithStub(page, { wouldCreate: 3, wouldSkip: 1 })
		expect(result.statusText).toMatch(/3|would be copied|würden|Preview|Vorschau/i)
		expect(result.applyHidden).toBe(false)
		expect(result.applyDisabled).toBe(false)
		await expect(page.locator('#dc-roster-copy-apply')).toBeVisible()
	})

	test('empty preview keeps Apply copy hidden', async ({ page }) => {
		await page.goto('/apps/dutycheck/roster', { waitUntil: 'domcontentloaded' })
		await assertNotServerUpdater(page)
		await page.waitForSelector('#dc-roster-month-label, #dc-roster-period-switcher', { timeout: 30_000 })

		const result = await runPreviewWithStub(page, { wouldCreate: 0, wouldSkip: 2 })
		expect(result.statusText).toMatch(/Nothing to copy|nichts zu kopieren/i)
		expect(result.applyHidden).toBe(true)
		await expect(page.locator('#dc-roster-copy-apply')).toBeHidden()
	})
})
