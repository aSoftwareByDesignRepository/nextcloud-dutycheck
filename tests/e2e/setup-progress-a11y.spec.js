// @ts-check
/**
 * Setup progress: WCAG contrast + single-CTA journey + zero axe violations.
 *
 * Proves the NC34 token trap is fixed: --color-success is a pale surface, so
 * done-status ink must be --color-success-text (≥ 4.5:1), never on-primary white.
 *
 * Contrast is always measured against a fixture painted with the live theme
 * tokens (so a fully-configured instance cannot skip the AA proof). Live
 * journey assertions run whenever #dc-dashboard-setup is visible.
 */
import { test, expect } from '@playwright/test'
import AxeBuilder from '@axe-core/playwright'
import { loginWithFallback, plannerCredsCandidates } from './helpers/auth.js'

/**
 * Relative luminance (sRGB) per WCAG 2.1.
 * @param {number} r
 * @param {number} g
 * @param {number} b
 */
function luminance(r, g, b) {
	const f = (c) => {
		const s = c / 255
		return s <= 0.04045 ? s / 12.92 : ((s + 0.055) / 1.055) ** 2.4
	}
	return 0.2126 * f(r) + 0.7152 * f(g) + 0.0722 * f(b)
}

/**
 * @param {{ r: number, g: number, b: number }} a
 * @param {{ r: number, g: number, b: number }} b
 */
function contrastRatio(a, b) {
	const L1 = luminance(a.r, a.g, a.b)
	const L2 = luminance(b.r, b.g, b.b)
	const lighter = Math.max(L1, L2)
	const darker = Math.min(L1, L2)
	return (lighter + 0.05) / (darker + 0.05)
}

/**
 * @param {string} color
 * @returns {{ r: number, g: number, b: number } | null}
 */
function parseCssColor(color) {
	const s = String(color || '').trim()
	const rgb = s.match(/^rgba?\(\s*([\d.]+)\s*,\s*([\d.]+)\s*,\s*([\d.]+)(?:\s*[,/]\s*([\d.]+))?/i)
	if (rgb) {
		const alpha = rgb[4] === undefined ? 1 : Number(rgb[4])
		if (alpha === 0) {
			return null
		}
		return { r: Number(rgb[1]), g: Number(rgb[2]), b: Number(rgb[3]) }
	}
	const hex = s.match(/^#([0-9a-f]{6})$/i)
	if (hex) {
		const n = hex[1]
		return {
			r: parseInt(n.slice(0, 2), 16),
			g: parseInt(n.slice(2, 4), 16),
			b: parseInt(n.slice(4, 6), 16),
		}
	}
	return null
}

/**
 * @param {import('@playwright/test').Page} page
 */
async function waitForSetupRender(page) {
	await page.waitForFunction(() => {
		const el = document.getElementById('dc-dashboard-setup')
		const list = document.getElementById('dc-dashboard-setup-list')
		if (!el) {
			return false
		}
		// Ready instances keep the section hidden; incomplete ones render items.
		return el.hidden || (list !== null && list.children.length > 0)
	}, null, { timeout: 15_000 }).catch(() => {})
}

test.describe('setup progress a11y + journey', () => {
	test.beforeEach(async ({ page }) => {
		test.skip(plannerCredsCandidates().length === 0, 'Requires E2E_* or NC_ADMIN_* credentials')
		await loginWithFallback(page, plannerCredsCandidates())
	})

	test('fixture done-status ink contrasts ≥ 4.5:1 under live NC theme tokens', async ({ page }) => {
		await page.goto('/apps/dutycheck/', { waitUntil: 'domcontentloaded' })
		await page.waitForSelector('#dc-main-content', { timeout: 30_000 })
		await page.waitForFunction(() => {
			const body = getComputedStyle(document.body)
			return body.getPropertyValue('--color-main-text').trim() !== ''
				&& body.getPropertyValue('--color-success').trim() !== ''
		}, null, { timeout: 10_000 }).catch(() => {})

		// Paint a fixture with the same classes the live checklist uses so we
		// always exercise the CSS under the active theme — even when setup is done.
		await page.evaluate(() => {
			const host = document.getElementById('dc-main-content') || document.body
			const prev = document.getElementById('dc-setup-contrast-fixture')
			if (prev) {
				prev.remove()
			}
			const wrap = document.createElement('section')
			wrap.id = 'dc-setup-contrast-fixture'
			wrap.className = 'dc-card dc-section dc-setup-progress'
			wrap.innerHTML = `
				<ol class="dc-setup-checklist">
					<li class="dc-setup-checklist__item is-done">
						<span class="dc-setup-checklist__status" aria-label="Done">✓</span>
						<div class="dc-setup-checklist__body"><strong>Fixture done</strong></div>
					</li>
					<li class="dc-setup-checklist__item is-current">
						<span class="dc-setup-checklist__status" aria-label="Next step">4</span>
						<div class="dc-setup-checklist__body">
							<strong>Fixture current</strong>
							<p class="dc-setup-checklist__hint">Only this step gets a CTA.</p>
							<a class="button primary" href="/apps/dutycheck/periods">Open Periods</a>
						</div>
					</li>
				</ol>
			`
			host.prepend(wrap)
		})

		const status = page.locator('#dc-setup-contrast-fixture .is-done .dc-setup-checklist__status')
		await expect(status).toBeVisible()

		const colors = await status.evaluate((el) => {
			const cs = getComputedStyle(el)
			return {
				color: cs.color,
				background: cs.backgroundColor,
				successToken: getComputedStyle(document.body).getPropertyValue('--color-success').trim(),
				successTextToken: getComputedStyle(document.body).getPropertyValue('--color-success-text').trim(),
			}
		})
		const fg = parseCssColor(colors.color)
		const bg = parseCssColor(colors.background)
		expect(fg, `parse fg: ${colors.color}`).not.toBeNull()
		expect(bg, `parse bg: ${colors.background}`).not.toBeNull()
		const ratio = contrastRatio(fg, bg)
		expect(
			ratio,
			`done status contrast ${ratio.toFixed(2)}:1 (fg=${colors.color} bg=${colors.background}; --color-success=${colors.successToken}; --color-success-text=${colors.successTextToken})`,
		).toBeGreaterThanOrEqual(4.5)

		// Guard the exact regression from the screenshot: white on pale success.
		const successSurface = parseCssColor(colors.successToken.startsWith('#') ? colors.successToken : colors.background) || bg
		const whiteOnSuccess = contrastRatio({ r: 255, g: 255, b: 255 }, successSurface)
		if (whiteOnSuccess < 3) {
			expect(ratio).toBeGreaterThanOrEqual(4.5)
			expect(colors.color).not.toMatch(/rgb\(\s*255\s*,\s*255\s*,\s*255\s*\)/)
		}

		await page.locator('#dc-toasts .dc-toast').evaluateAll((nodes) => nodes.forEach((n) => n.remove())).catch(() => {})
		const results = await new AxeBuilder({ page })
			.include('#dc-setup-contrast-fixture')
			.withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
			.analyze()
		expect(results.violations, JSON.stringify(results.violations, null, 2)).toEqual([])
	})

	test('live setup shows one primary CTA and suppresses Quick start', async ({ page }) => {
		await page.goto('/apps/dutycheck/', { waitUntil: 'domcontentloaded' })
		await page.waitForSelector('#dc-main-content', { timeout: 30_000 })
		await waitForSetupRender(page)

		const setup = page.locator('#dc-dashboard-setup')
		const visible = await setup.isVisible().catch(() => false)
		test.skip(!visible, 'Setup already complete on this instance')

		await expect(page.locator('#dc-quickstart')).toBeHidden()
		await expect(setup.locator('.dc-setup-checklist__item.is-current')).toHaveCount(1)
		await expect(setup.locator('a.button.primary')).toHaveCount(1)
		await expect(setup.locator('a.button')).toHaveCount(1)

		const href = await setup.locator('a.button.primary').first().getAttribute('href')
		expect(href || '').toMatch(/\/apps\/dutycheck\//)

		await page.locator('#dc-toasts .dc-toast').evaluateAll((nodes) => nodes.forEach((n) => n.remove())).catch(() => {})
		const results = await new AxeBuilder({ page })
			.include('#dc-dashboard-setup')
			.withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
			.analyze()
		expect(results.violations, JSON.stringify(results.violations, null, 2)).toEqual([])
	})

	test('CTA completes a one-click jump into the next setup page', async ({ page }) => {
		await page.goto('/apps/dutycheck/', { waitUntil: 'domcontentloaded' })
		await page.waitForSelector('#dc-main-content', { timeout: 30_000 })
		await waitForSetupRender(page)

		const setup = page.locator('#dc-dashboard-setup')
		const visible = await setup.isVisible().catch(() => false)
		test.skip(!visible, 'Setup already complete on this instance')

		const cta = setup.locator('a.button.primary').first()
		await expect(cta).toBeVisible()
		const label = (await cta.innerText()).trim()
		await cta.click()
		await page.waitForURL(/\/apps\/dutycheck\//, { timeout: 30_000 })
		await page.waitForSelector('#dc-main-content', { timeout: 30_000 })
		expect(page.url()).toMatch(/\/apps\/dutycheck\//)
		expect(page.url()).not.toMatch(/\/login/)
		expect(label.length).toBeGreaterThan(0)
	})
})
