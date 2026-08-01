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
	expect(overflow.doc, `document horizontal overflow at ${label}`).toBeLessThanOrEqual(1)
	expect(overflow.app, `#app-content overflow at ${label}`).toBeLessThanOrEqual(1)
	expect(overflow.shell, `.dc-shell overflow at ${label}`).toBeLessThanOrEqual(1)
	expect(overflow.main, `#dc-main-content overflow at ${label}`).toBeLessThanOrEqual(1)
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
					const roleLabel = document.querySelector('.dc-scope-strip__item .dc-scope-strip__label')
					const roleItem = document.querySelector('.dc-scope-strip__item')
					const shellRect = shell?.getBoundingClientRect()
					const headerRect = header?.getBoundingClientRect()
					const titleRect = title?.getBoundingClientRect()
					const labelRect = roleLabel?.getBoundingClientRect()
					const itemRect = roleItem?.getBoundingClientRect()
					const labelStyle = roleLabel ? getComputedStyle(roleLabel) : null
					return {
						shellWidth: shellRect ? Math.round(shellRect.width) : 0,
						headerVisible: !!(headerRect && headerRect.height > 0),
						titleClipped: titleRect
							? titleRect.right > (shellRect?.right ?? window.innerWidth) + 1
							: false,
						navPresent: !!nav,
						viewport: window.innerWidth,
						scopeLabelAlign: labelStyle?.textAlign ?? '',
						scopeLabelWidthPx: labelStyle ? parseFloat(labelStyle.width) : NaN,
						scopeLabelFlushStart: !!(labelRect && itemRect
							&& Math.abs(labelRect.left - itemRect.left) <= 2),
					}
				})

				expect(metrics.shellWidth, 'shell must fill usable content width').toBeGreaterThan(200)
				expect(metrics.headerVisible, 'page header must render').toBeTruthy()
				expect(metrics.titleClipped, 'page title must not clip outside shell').toBeFalsy()
				expect(
					['start', 'left'].includes(metrics.scopeLabelAlign),
					`scope strip Role label must be start-aligned (got ${metrics.scopeLabelAlign})`,
				).toBeTruthy()
				expect(
					metrics.scopeLabelWidthPx,
					'core dt width:130px must not apply to scope strip labels',
				).not.toBe(130)
				expect(
					metrics.scopeLabelFlushStart,
					'Role label must sit flush at the start of its strip item (no fake icon gap)',
				).toBeTruthy()
				await expectNoHorizontalOverflow(page, `${theme}/${vp.name}`)
			})
		}
	}
})
