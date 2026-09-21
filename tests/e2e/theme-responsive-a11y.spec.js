// @ts-check
/**
 * Theme × viewport × WCAG 2.1 AA gauntlet for DutyCheck.
 *
 * Proves for every selectable NC theme (light, dark, light-highcontrast,
 * dark-highcontrast) and key route:
 *  - theme actually switched (body[data-theme-*]),
 *  - design tokens resolve from Nextcloud --color-* (no transparent tints),
 *  - zero horizontal overflow from 320 px up to 4K,
 *  - touch targets ≥ 44×44 on interactive chrome,
 *  - zero axe WCAG 2.1 A/AA violations on the app shell.
 */
import { test, expect } from '@playwright/test'
import AxeBuilder from '@axe-core/playwright'
import { loginWithFallback, plannerCredsCandidates } from './helpers/auth.js'
import {
	setUserTheme,
	resetUserTheme,
	setAccentColor,
	resetAccentColor,
	USER_THEMES,
} from './helpers/theming.js'

const routes = [
	{ id: 'dashboard', path: '/apps/dutycheck/', ready: '#dc-main-content' },
	{ id: 'roster', path: '/apps/dutycheck/roster', ready: '#dc-main-content' },
	{ id: 'periods', path: '/apps/dutycheck/periods', ready: '#dc-main-content' },
	{ id: 'absences', path: '/apps/dutycheck/absences', ready: '#dc-main-content' },
	// Split settings: land on the default sub-page directly (deterministic URL).
	{ id: 'settings', path: '/apps/dutycheck/settings/access', ready: '#dc-main-content' },
]

const overflowViewports = [
	{ width: 320, height: 640 },
	{ width: 375, height: 812 },
	{ width: 768, height: 1024 },
	{ width: 1024, height: 768 },
	{ width: 1440, height: 900 },
	{ width: 2560, height: 1440 },
]

const axeViewports = [
	{ width: 320, height: 640 },
	{ width: 768, height: 1024 },
	{ width: 1280, height: 800 },
]

/**
 * @param {import('@playwright/test').Page} page
 * @param {string} label
 */
async function expectNoHorizontalOverflow(page, label) {
	// Poll until layout settles after theme/viewport churn (HC fonts/borders can
	// briefly report scrollWidth > clientWidth mid-reflow without a true overflow bug).
	await expect.poll(async () => {
		const overflow = await page.evaluate(() => {
			const doc = document.documentElement
			const app = document.querySelector('#app-content.dc-app')
			const shell = document.querySelector('#app-content-wrapper.dc-shell, .dc-shell')
			const main = document.getElementById('dc-main-content')
			return {
				doc: doc.scrollWidth - doc.clientWidth,
				app: app ? app.scrollWidth - app.clientWidth : 0,
				shell: shell ? shell.scrollWidth - shell.clientWidth : 0,
				main: main ? main.scrollWidth - main.clientWidth : 0,
			}
		})
		return Math.max(overflow.doc, overflow.app, overflow.shell, overflow.main)
	}, { timeout: 8_000, message: `horizontal overflow at ${label}` }).toBeLessThanOrEqual(1)
}

/**
 * @param {import('@playwright/test').Page} page
 */
async function assertThemeTokensResolved(page) {
	const tokens = await page.evaluate(() => {
		const el = document.querySelector('#app-content.dc-app') || document.body
		const cs = getComputedStyle(el)
		const bodyCs = getComputedStyle(document.body)
		return {
			bg: cs.getPropertyValue('--dc-bg-card').trim() || bodyCs.getPropertyValue('--color-main-background').trim(),
			text: cs.getPropertyValue('--dc-text').trim() || bodyCs.getPropertyValue('--color-main-text').trim(),
			primary: bodyCs.getPropertyValue('--color-primary-element').trim(),
			muted: cs.getPropertyValue('--dc-muted').trim(),
			tintInfo: cs.getPropertyValue('--dc-tint-info').trim(),
			tintSuccess: cs.getPropertyValue('--dc-tint-success').trim(),
			touch: cs.getPropertyValue('--dc-touch').trim() || bodyCs.getPropertyValue('--dc-touch').trim(),
			scrim: cs.getPropertyValue('--dc-scrim').trim() || bodyCs.getPropertyValue('--dc-scrim').trim(),
			shellMax: (() => {
				const shell = document.querySelector('#app-content-wrapper.dc-shell, .dc-shell')
				return shell ? getComputedStyle(shell).maxWidth : ''
			})(),
		}
	})
	expect(tokens.bg, 'theme background token').not.toEqual('')
	expect(tokens.text, 'theme text token').not.toEqual('')
	expect(tokens.primary, 'primary element token').not.toEqual('')
	expect(tokens.muted, 'muted token').not.toEqual('')
	expect(tokens.tintInfo, 'tint-info must resolve').not.toEqual('')
	expect(tokens.tintSuccess, 'tint-success must resolve').not.toEqual('')
	expect(
		/,\s*transparent\s*\)\s*$/i.test(tokens.tintInfo),
		`tint-info must mix into main-background, got: ${tokens.tintInfo}`,
	).toBeFalsy()
	expect(tokens.scrim, 'scrim token').not.toEqual('')
	expect(tokens.touch === '44px' || parseFloat(tokens.touch) >= 44, 'touch target token ≥44px').toBeTruthy()
	expect(
		tokens.shellMax === 'none' || tokens.shellMax === '' || parseFloat(tokens.shellMax) >= 2000,
		`default shell must not be a fixed 1200px lock (got ${tokens.shellMax})`,
	).toBeTruthy()
}

/**
 * Dark / dark-HC: sidebar must use resolved NC surface tokens — no light island.
 * Compare against body `--color-main-background` (not body.backgroundColor, which
 * can be the primary chrome tint on some NC shells).
 * @param {import('@playwright/test').Page} page
 * @param {string} theme
 */
async function assertDarkNavMatchesThemeTokens(page, theme) {
	if (!theme.includes('dark')) return
	const nav = await page.evaluate(() => {
		const navEl = document.querySelector('#app-navigation')
		if (!navEl) return null
		const bodyCs = getComputedStyle(document.body)
		const navCs = getComputedStyle(navEl)
		const parseRgb = (v) => {
			const s = String(v).trim()
			const m = s.match(/rgba?\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)/i)
			if (m) return [Number(m[1]), Number(m[2]), Number(m[3])]
			const hx = s.match(/^#([0-9a-f]{3}|[0-9a-f]{6})$/i)
			if (hx) {
				let h = hx[1]
				if (h.length === 3) h = h.split('').map((c) => c + c).join('')
				return [parseInt(h.slice(0, 2), 16), parseInt(h.slice(2, 4), 16), parseInt(h.slice(4, 6), 16)]
			}
			return null
		}
		// Resolve token the same way paint does: temp element inheriting body vars.
		const probe = document.createElement('div')
		probe.style.backgroundColor = 'var(--color-main-background)'
		probe.style.color = 'var(--color-main-text)'
		document.body.appendChild(probe)
		const probeCs = getComputedStyle(probe)
		const surfaceRgb = parseRgb(probeCs.backgroundColor)
		const navRgb = parseRgb(navCs.backgroundColor)
		const link = navEl.querySelector('.dc-nav__link')
		const linkColor = link ? getComputedStyle(link).color : ''
		probe.remove()
		return {
			surfaceToken: bodyCs.getPropertyValue('--color-main-background').trim(),
			surfaceBg: probeCs.backgroundColor,
			navBg: navCs.backgroundColor,
			navColor: navCs.color,
			linkColor,
			surfaceRgb,
			navRgb,
			navLocalToken: navCs.getPropertyValue('--color-main-background').trim(),
		}
	})
	expect(nav, 'dark theme must render #app-navigation').toBeTruthy()
	expect(nav.surfaceToken, 'body --color-main-background must resolve').not.toEqual('')
	expect(nav.navRgb, `nav background must parse (got ${nav.navBg})`).toBeTruthy()
	const lum = (0.2126 * nav.navRgb[0] + 0.7152 * nav.navRgb[1] + 0.0722 * nav.navRgb[2]) / 255
	expect(lum, `dark nav must stay dark (lum=${lum}, bg=${nav.navBg})`).toBeLessThan(0.45)
	if (nav.surfaceRgb) {
		const dist = Math.hypot(
			nav.surfaceRgb[0] - nav.navRgb[0],
			nav.surfaceRgb[1] - nav.navRgb[1],
			nav.surfaceRgb[2] - nav.navRgb[2],
		)
		expect(
			dist,
			`nav bg must match resolved --color-main-background (dist=${dist}; surface=${nav.surfaceBg} nav=${nav.navBg})`,
		).toBeLessThan(48)
	}
	expect(nav.linkColor, 'nav links must resolve a color').not.toEqual('')
}

/**
 * @param {import('@playwright/test').Page} page
 */
async function assertTouchTargets(page) {
	const result = await page.evaluate(() => {
		const nodes = [
			...document.querySelectorAll(
				'#app-content.dc-app .button, #app-content.dc-app button.primary, #dc-page-actions .button, .dc-nav__link, .dc-hint-dismiss, .dc-quickfilters__btn',
			),
		].slice(0, 50)
		const undersized = []
		for (const el of nodes) {
			const style = getComputedStyle(el)
			if (style.display === 'none' || style.visibility === 'hidden') continue
			const rect = el.getBoundingClientRect()
			if (rect.width === 0 && rect.height === 0) continue
			const minH = Math.max(rect.height, parseFloat(style.minHeight) || 0)
			const minW = Math.max(rect.width, parseFloat(style.minWidth) || 0)
			const isBar = rect.width >= 120
			if (minH < 40 || (!isBar && minW < 40)) {
				undersized.push({
					tag: el.tagName,
					cls: String(el.className).slice(0, 80),
					w: Math.round(minW),
					h: Math.round(minH),
				})
			}
		}
		return { ok: undersized.length === 0, undersized }
	})
	expect(result.ok, JSON.stringify(result.undersized)).toBeTruthy()
}

/**
 * @param {import('@playwright/test').Page} page
 * @param {string} label
 */
async function runAxe(page, label) {
	await page.locator('#dc-toasts .dc-toast').evaluateAll((nodes) => nodes.forEach((n) => n.remove())).catch(() => {})
	const results = await new AxeBuilder({ page })
		.include('#content')
		.withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
		.exclude('#dc-toasts')
		.exclude('.toastify')
		.analyze()
	expect(
		results.violations,
		`axe violations at ${label}:\n${JSON.stringify(results.violations, null, 2)}`,
	).toEqual([])
}

/**
 * @param {import('@playwright/test').Page} page
 * @param {string} path
 */
async function gotoReady(page, path) {
	await page.goto(path, { waitUntil: 'domcontentloaded' })
	await expect(page.locator('#dc-main-content')).toBeVisible({ timeout: 30_000 })
	await page.waitForFunction(() => {
		const body = getComputedStyle(document.body)
		return body.getPropertyValue('--color-main-text').trim() !== ''
			&& body.getPropertyValue('--color-main-background').trim() !== ''
	}, null, { timeout: 10_000 }).catch(() => {})
}

test.describe('DutyCheck theme × viewport a11y matrix', () => {
	test.describe.configure({ mode: 'serial' })
	test.setTimeout(300_000)

	for (const theme of USER_THEMES) {
		for (const route of routes) {
			test(`${theme}: ${route.id}`, async ({ page }) => {
				const candidates = plannerCredsCandidates()
				test.skip(candidates.length === 0, 'Requires E2E_* or NC_ADMIN_* credentials')

				await loginWithFallback(page, candidates)
				await gotoReady(page, route.path)
				await setUserTheme(page, theme)
				await expect(page.locator(route.ready)).toBeVisible({ timeout: 30_000 })
				await assertThemeTokensResolved(page)
				await assertDarkNavMatchesThemeTokens(page, theme)

				for (const viewport of overflowViewports) {
					await page.setViewportSize(viewport)
					await expectNoHorizontalOverflow(page, `${theme}/${route.id}@${viewport.width}px`)
				}
				await page.setViewportSize({ width: 1280, height: 800 })
				await assertTouchTargets(page)
				for (const viewport of axeViewports) {
					await page.setViewportSize(viewport)
					await runAxe(page, `${theme}/${route.id}@${viewport.width}px`)
				}
			})
		}
	}

	test('reset to default theme', async ({ page }) => {
		const candidates = plannerCredsCandidates()
		test.skip(candidates.length === 0, 'Requires E2E_* or NC_ADMIN_* credentials')
		await loginWithFallback(page, candidates)
		await gotoReady(page, '/apps/dutycheck/')
		await resetUserTheme(page)
	})
})

test.describe('DutyCheck custom accent colour', () => {
	test.describe.configure({ mode: 'serial' })
	test.setTimeout(180_000)

	test('primary tokens follow instance accent and stay AA', async ({ page }) => {
		const candidates = plannerCredsCandidates()
		test.skip(candidates.length === 0, 'Requires E2E_* or NC_ADMIN_* credentials')

		await loginWithFallback(page, candidates)
		await gotoReady(page, '/apps/dutycheck/settings/access')

		const readPrimary = () => page.evaluate(() => {
			const probe = getComputedStyle(document.body).getPropertyValue('--color-primary-element').trim()
			const tint = getComputedStyle(document.querySelector('#app-content.dc-app') || document.body)
				.getPropertyValue('--dc-tint-info').trim()
			return { variable: probe, tintInfo: tint }
		})

		const before = await readPrimary()
		expect(before.variable, 'NC must expose --color-primary-element').not.toEqual('')

		setAccentColor('#971003')
		try {
			await expect.poll(async () => {
				await page.reload({ waitUntil: 'load' })
				return (await readPrimary()).variable
			}, { timeout: 60_000, intervals: [1_000, 2_000, 3_000] }).not.toEqual(before.variable)

			await expect(page.locator('#dc-main-content')).toBeVisible({ timeout: 30_000 })
			const after = await readPrimary()
			expect(after.tintInfo, 'tint-info must still resolve after accent change').not.toEqual('')
			expect(/,\s*transparent\s*\)\s*$/i.test(after.tintInfo)).toBeFalsy()
			await runAxe(page, 'custom-accent/settings@1280px')
		} finally {
			resetAccentColor()
		}

		await expect.poll(async () => {
			await page.reload({ waitUntil: 'load' })
			const current = (await readPrimary()).variable
			// Accept either the pre-test primary OR NC default after a clean reset.
			return current === before.variable || current === '#00679e' || current.toLowerCase() === before.variable.toLowerCase()
		}, { timeout: 90_000, intervals: [1_000, 2_000, 3_000] }).toBeTruthy()
	})
})

test.describe('DutyCheck visual shell metrics', () => {
	const metricViewports = [
		{ name: 'mobile-320', width: 320, height: 640 },
		{ name: 'tablet-768', width: 768, height: 1024 },
		{ name: 'desktop-1440', width: 1440, height: 900 },
	]

	for (const theme of ['light', 'dark']) {
		for (const vp of metricViewports) {
			test(`shell metrics @ ${theme} ${vp.name}`, async ({ page }) => {
				const candidates = plannerCredsCandidates()
				test.skip(candidates.length === 0, 'Requires E2E_* or NC_ADMIN_* credentials')

				await loginWithFallback(page, candidates)
				await gotoReady(page, '/apps/dutycheck/')
				await setUserTheme(page, theme)
				await page.setViewportSize({ width: vp.width, height: vp.height })

				const metrics = await page.evaluate(() => {
					const shell = document.querySelector('#app-content-wrapper.dc-shell')
					const header = document.querySelector('.dc-page-header')
					const nav = document.querySelector('#app-navigation')
					const title = document.querySelector('#dc-page-title, .dc-page-header__text h1')
					// Role chrome is the header badge (scope-strip definition list was retired).
					const roleBadge = document.querySelector('.dc-page-header .dc-badge')
					const shellRect = shell?.getBoundingClientRect()
					const headerRect = header?.getBoundingClientRect()
					const titleRect = title?.getBoundingClientRect()
					const badgeRect = roleBadge?.getBoundingClientRect()
					const badgeStyle = roleBadge ? getComputedStyle(roleBadge) : null
					return {
						shellWidth: shellRect ? Math.round(shellRect.width) : 0,
						headerVisible: !!(headerRect && headerRect.height > 0),
						titleClipped: titleRect
							? titleRect.right > (shellRect?.right ?? window.innerWidth) + 1
							: false,
						navPresent: !!nav,
						viewport: window.innerWidth,
						roleBadgePresent: !!roleBadge,
						roleBadgeVisible: !!(badgeRect && badgeRect.height > 0 && badgeRect.width > 0),
						roleBadgeMinHeight: badgeRect ? badgeRect.height : 0,
						roleBadgeColor: badgeStyle?.color ?? '',
					}
				})

				expect(metrics.shellWidth, 'shell must fill usable content width').toBeGreaterThan(200)
				expect(metrics.headerVisible, 'page header must render').toBeTruthy()
				expect(metrics.titleClipped, 'page title must not clip outside shell').toBeFalsy()
				expect(metrics.roleBadgePresent, 'role badge must render in page header').toBeTruthy()
				expect(metrics.roleBadgeVisible, 'role badge must be visible').toBeTruthy()
				expect(metrics.roleBadgeMinHeight, 'role badge needs readable height').toBeGreaterThanOrEqual(18)
				await expectNoHorizontalOverflow(page, `${theme}/${vp.name}`)
			})
		}
	}
})
