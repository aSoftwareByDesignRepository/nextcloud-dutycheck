// @ts-check
/**
 * ATLAS ds_chrome live contrast probe (dutycheck).
 *
 * Logs in, walks representative pages, and measures the COMPUTED WCAG 2.1
 * contrast of semantic chrome: status badges, callouts, danger buttons,
 * invalid-field borders, and control borders — across light / dark /
 * light-highcontrast / dark-highcontrast user themes (server-pinned via
 * OCS theming, body[data-theme-*] marker asserted — never client-emulated).
 *
 *   text ink   >= 4.5:1  (WCAG 1.4.3 AA; >=3:1 for large icon glyphs)
 *   borders    >= 3.0:1  (WCAG 1.4.11)
 *
 * Usage (from the app dir):
 *   DS_PROBE_PASS=... node tests/e2e/helpers/atlas-contrast-probe.mjs [--out <path.json>]
 *
 * Probe users: dc-ds-planner / dc-ds-employee (artifacts/dutycheck/probes/ds-probe.env).
 */
import { writeFileSync, mkdirSync, existsSync, readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { chromium } from '@playwright/test'
import { login } from './auth.js'
import { setUserTheme, resetUserTheme, USER_THEMES } from './theming.js'

const HERE = dirname(fileURLToPath(import.meta.url))
const APP_ROOT = resolve(HERE, '../../..')
const ENV_PATH = resolve(APP_ROOT, 'tests/e2e/.env')
if (existsSync(ENV_PATH)) {
	for (const line of readFileSync(ENV_PATH, 'utf8').split('\n')) {
		const t = line.trim()
		if (!t || t.startsWith('#')) continue
		const eq = t.indexOf('=')
		if (eq <= 0) continue
		const k = t.slice(0, eq).trim()
		let v = t.slice(eq + 1).trim()
		if ((v.startsWith('"') && v.endsWith('"')) || (v.startsWith("'") && v.endsWith("'"))) v = v.slice(1, -1)
		if (process.env[k] === undefined) process.env[k] = v
	}
}

const BASE = process.env.NC_BASE_URL || 'http://localhost:8081'
const PASS = process.env.DS_PROBE_PASS || 'DsProbe!2026'

// ── WCAG contrast helpers (injected into the page for computed colors) ──
const EVAL_FN = String.raw`
function hexToRgb(c) {
  c = c.trim()
  if (c.startsWith('color(')) {
    const m = c.match(/[\d.]+/g)
    if (m && m.length >= 3) {
      const s = m.map(parseFloat)
      const scale = s.every((v) => v <= 1) ? 255 : 1
      return [s[0] * scale, s[1] * scale, s[2] * scale]
    }
    return null
  }
  if (c.startsWith('rgb')) {
    const m = c.match(/[\d.]+/g)
    if (m && m.length >= 3) return [parseFloat(m[0]), parseFloat(m[1]), parseFloat(m[2])]
    return null
  }
  if (c.startsWith('#')) {
    let h = c.slice(1)
    if (h.length === 3) h = h.split('').map(x => x + x).join('')
    if (h.length === 4) h = h.split('').map(x => x + x).join('')
    if (h.length === 6 || h.length === 8) {
      return [parseInt(h.slice(0,2),16), parseInt(h.slice(2,4),16), parseInt(h.slice(4,6),16)]
    }
  }
  return null
}
function lum(rgb) {
  const f = v => {
    v /= 255
    return v <= 0.04045 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4)
  }
  return 0.2126 * f(rgb[0]) + 0.7152 * f(rgb[1]) + 0.0722 * f(rgb[2])
}
function effBg(el) {
  let n = el
  while (n && n !== document.documentElement) {
    const bg = getComputedStyle(n).backgroundColor
    const m = bg && bg.match(/[\d.]+/g)
    if (m && m.length >= 4 && parseFloat(m[3]) > 0) return bg
    if (m && m.length === 3 && !bg.includes('transparent')) return bg
    n = n.parentElement
  }
  return getComputedStyle(document.body).backgroundColor
}
function alphaOf(c) {
  const m = c && c.match(/[\d.]+/g)
  if (m && m.length >= 4) return parseFloat(m[3])
  if (c && c.startsWith('color(')) {
    const parts = c.match(/[\d.]+/g)
    if (parts && parts.length >= 4) return parseFloat(parts[3])
  }
  return 1
}
function blend(fgRgb, bgRgb, a) {
  return [
    a * fgRgb[0] + (1 - a) * bgRgb[0],
    a * fgRgb[1] + (1 - a) * bgRgb[1],
    a * fgRgb[2] + (1 - a) * bgRgb[2],
  ]
}
function ratio(fg, bg) {
  const a = hexToRgb(fg), b = hexToRgb(bg)
  if (!a || !b) return null
  const l1 = lum(a), l2 = lum(b)
  const hi = Math.max(l1, l2), lo = Math.min(l1, l2)
  return (hi + 0.05) / (lo + 0.05)
}
function borderRatio(border, bg) {
  const f = hexToRgb(border), b = hexToRgb(bg)
  if (!f || !b) return null
  const alpha = alphaOf(border)
  const eff = alpha >= 1 ? f : blend(f, b, alpha)
  const l1 = lum(eff), l2 = lum(b)
  const hi = Math.max(l1, l2), lo = Math.min(l1, l2)
  return (hi + 0.05) / (lo + 0.05)
}
window.__dcProbe = { effBg, ratio, alphaOf, borderRatio }
`

/** Elements to measure per page (planner role). */
const PROBES = [
	{
		page: '/apps/dutycheck/',
		anchor: '#dc-main-content',
		label: 'index',
		rows: [
			{ sel: '.dc-status-badge, .dc-badge', kind: 'badge', what: 'text' },
			{ sel: 'button.primary, .button.primary', kind: 'primary-cta', what: 'text' },
			{ sel: '#dc-main-content input:not([type="hidden"]), #dc-main-content select, #dc-main-content textarea', kind: 'control-border', what: 'border' },
		],
	},
	{
		page: '/apps/dutycheck/roster',
		anchor: '#dc-main-content',
		label: 'roster',
		rows: [
			{ sel: '.dc-status-badge, .dc-badge, .dc-pill', kind: 'badge', what: 'text' },
			{ sel: '#dc-roster-period-switcher, .dc-roster-month-nav .button, #dc-main-content select', kind: 'control-border', what: 'border' },
			{ sel: '.dc-callout--warning, .dc-callout--critical', kind: 'callout', what: 'text' },
		],
	},
	{
		page: '/apps/dutycheck/absences',
		anchor: '#dc-main-content',
		label: 'absences',
		rows: [
			{ sel: '.dc-status-badge, .dc-badge', kind: 'badge', what: 'text' },
			{ sel: '.dc-absence-transition-btn, #dc-main-content button', kind: 'control-border', what: 'border' },
		],
	},
	{
		page: '/apps/dutycheck/settings/access',
		anchor: '#dc-main-content',
		label: 'settings-access',
		rows: [
			{ sel: '#dc-main-content input:not([type="hidden"]), #dc-main-content select, #dc-main-content textarea', kind: 'control-border', what: 'border' },
			{ sel: 'button.primary, .button.primary', kind: 'primary-cta', what: 'text' },
		],
	},
]

async function settle(page) {
	await page.waitForLoadState('domcontentloaded').catch(() => {})
	try { await page.waitForLoadState('networkidle', { timeout: 5000 }) } catch { /* long-polls */ }
	await page.evaluate(() => new Promise((r) => requestAnimationFrame(() => requestAnimationFrame(r))))
}

async function measure(page, probes) {
	const results = []
	for (const p of probes) {
		const resp = await page.goto(p.page, { waitUntil: 'domcontentloaded' })
		if (!resp || resp.status() >= 400) {
			results.push({ page: p.label, error: `http ${resp ? resp.status() : 'nav-fail'}`, rows: [] })
			continue
		}
		// Surface anchor assert BEFORE any measurement (fabricated-capture guard).
		await page.waitForSelector(p.anchor, { timeout: 30_000 })
		await settle(page)
		const pageRes = { page: p.label, rows: [] }
		for (const row of p.rows) {
			const found = await page.evaluate(
				async ({ sel, what }) => {
					const els = Array.from(document.querySelectorAll(sel)).filter(
						(n) => n.offsetParent !== null,
					)
					const out = []
					for (const el of els.slice(0, 6)) {
						const cs = getComputedStyle(el)
						const bg = window.__dcProbe.effBg(el)
						const item = {
							tag: el.tagName.toLowerCase(),
							cls: (el.getAttribute('class') || '').slice(0, 80),
							fg: cs.color,
							bg,
							borderColor: cs.borderColor,
							borderWidth: cs.borderWidth,
						}
						if (what !== 'border') {
							item.textRatio = window.__dcProbe.ratio(cs.color, bg)
						}
						if (what !== 'text' && parseFloat(cs.borderWidth) > 0) {
							item.borderRatio = window.__dcProbe.borderRatio(cs.borderColor, bg)
							item.borderAlpha = window.__dcProbe.alphaOf(cs.borderColor)
						}
						out.push(item)
					}
					return out
				},
				{ sel: row.sel, what: row.what },
			)
			pageRes.rows.push({ kind: row.kind, selector: row.sel, what: row.what, found: found.length, samples: found })
		}
		results.push(pageRes)
	}
	return results
}

/**
 * Modal controls + promptReason invalid state. `.dc-modal` mounts on
 * document.body — OUTSIDE #app-content — so the #app-content control-border
 * baseline does not reach it. Measure .dc-input/.dc-field textarea borders
 * inside the modal, then submit an under-length reason and assert the
 * invalid state paints a >=3:1 border + aria-invalid.
 */
async function measureModalAndFieldError(page) {
	await page.goto('/apps/dutycheck/absences', { waitUntil: 'domcontentloaded' })
	await page.waitForSelector('#dc-main-content', { timeout: 30_000 })
	await settle(page)
	const trigger = page.locator('.dc-absence-transition-btn[data-dc-transition="rejected"]').first()
	if (!(await trigger.count())) {
		return { skipped: 'no absence reject trigger (empty fixture)' }
	}
	await trigger.click()
	const textarea = page.locator('.dc-modal textarea')
	await textarea.waitFor({ state: 'visible', timeout: 15_000 })
	await page.waitForTimeout(300)

	const out = {}
	// Pre-submit: modal control border contrast (the baseline gap probe).
	out.modalControls = await page.evaluate(() => {
		const els = [...document.querySelectorAll('.dc-modal input:not([type="hidden"]), .dc-modal select, .dc-modal textarea')]
			.filter((n) => n.offsetParent !== null)
		return els.slice(0, 6).map((el) => {
			const cs = getComputedStyle(el)
			const bg = window.__dcProbe.effBg(el)
			return {
				tag: el.tagName.toLowerCase(),
				cls: (el.getAttribute('class') || '').slice(0, 60),
				borderColor: cs.borderColor,
				borderWidth: cs.borderWidth,
				bg,
				borderRatio: window.__dcProbe.borderRatio(cs.borderColor, bg),
			}
		})
	})
	// Also measure the modal action buttons (Cancel/secondary border).
	out.modalButtons = await page.evaluate(() => {
		const els = [...document.querySelectorAll('.dc-modal button, .dc-modal .button')]
			.filter((n) => n.offsetParent !== null)
		return els.slice(0, 6).map((el) => {
			const cs = getComputedStyle(el)
			const bg = window.__dcProbe.effBg(el)
			return {
				tag: el.tagName.toLowerCase(),
				cls: (el.getAttribute('class') || '').slice(0, 60),
				fg: cs.color,
				borderColor: cs.borderColor,
				borderWidth: cs.borderWidth,
				bg,
				textRatio: window.__dcProbe.ratio(cs.color, bg),
				borderRatio: parseFloat(cs.borderWidth) > 0 ? window.__dcProbe.borderRatio(cs.borderColor, bg) : null,
			}
		})
	})

	// Under-length submit → local validation (minLength 10).
	await textarea.fill('x')
	const submit = page.locator('.dc-modal [role="dialog"] .dc-modal__actions .button.primary, .dc-modal dialog .dc-modal__actions .button.primary').first()
	await submit.click()
	await page.waitForSelector('.dc-modal .dc-field__error:not([hidden])', { timeout: 10_000 }).catch(() => {})
	await page.waitForTimeout(200)
	out.invalidState = await page.evaluate(() => {
		const input = document.querySelector('.dc-modal textarea')
		const err = document.querySelector('.dc-modal .dc-field__error:not([hidden])')
		if (!input) return { error: 'textarea gone' }
		const cs = getComputedStyle(input)
		const bg = window.__dcProbe.effBg(input)
		const res = {
			ariaInvalid: input.getAttribute('aria-invalid'),
			ariaDescribedby: input.getAttribute('aria-describedby'),
			errorShown: !!err,
			border: {
				borderColor: cs.borderColor,
				borderWidth: cs.borderWidth,
				borderRatio: window.__dcProbe.borderRatio(cs.borderColor, bg),
			},
		}
		if (err) {
			const ecs = getComputedStyle(err)
			res.errorText = {
				text: (err.textContent || '').slice(0, 120),
				textRatio: window.__dcProbe.ratio(ecs.color, window.__dcProbe.effBg(err)),
			}
		}
		return res
	})
	// Leave the modal cleanly.
	await page.keyboard.press('Escape').catch(() => {})
	return out
}

/** Employee → planner route denied surface: icon ink on tint-critical well. */
async function measureDeniedSurface(browser) {
	const ctx = await browser.newContext({ baseURL: BASE, viewport: { width: 1440, height: 900 } })
	const page = await ctx.newPage()
	await login(page, { username: 'dc-ds-employee', password: PASS })
	const resp = await page.goto('/apps/dutycheck/employees', { waitUntil: 'domcontentloaded' })
	await page.evaluate(new Function(EVAL_FN)) // this context has no initScript
	await settle(page)
	const out = { http: resp ? resp.status() : 'nav-fail' }
	out.deniedShown = await page.locator('.dc-denied, #dc-denied-main, [role="alert"]').first().isVisible().catch(() => false)
	if (out.deniedShown) {
		out.samples = await page.evaluate(() => {
			const res = []
			const icon = document.querySelector('.dc-denied .dc-page-header__icon')
			if (icon) {
				const cs = getComputedStyle(icon)
				res.push({
					kind: 'denied-icon-ink',
					fg: cs.color,
					bg: window.__dcProbe.effBg(icon),
					ratio: window.__dcProbe.ratio(cs.color, window.__dcProbe.effBg(icon)),
				})
			}
			for (const el of document.querySelectorAll('.dc-denied a.button, .dc-denied .button')) {
				if (el.offsetParent === null) continue
				const cs = getComputedStyle(el)
				res.push({
					kind: 'denied-cta',
					cls: (el.getAttribute('class') || '').slice(0, 60),
					fg: cs.color,
					bg: window.__dcProbe.effBg(el),
					borderColor: cs.borderColor,
					borderWidth: cs.borderWidth,
					textRatio: window.__dcProbe.ratio(cs.color, window.__dcProbe.effBg(el)),
					borderRatio: parseFloat(cs.borderWidth) > 0 ? window.__dcProbe.borderRatio(cs.borderColor, window.__dcProbe.effBg(el)) : null,
				})
			}
			return res
		})
	}
	await ctx.close()
	return out
}

async function main() {
	const browser = await chromium.launch()
	const context = await browser.newContext({ baseURL: BASE, viewport: { width: 1440, height: 900 } })
	const page = await context.newPage()
	const report = { app: 'dutycheck', probe: 'live-computed-contrast', base: BASE, generated_at: new Date().toISOString(), themes: {} }

	try {
		await login(page, { username: 'dc-ds-planner', password: PASS })
		await context.addInitScript(EVAL_FN)
		await page.goto('/apps/dutycheck/', { waitUntil: 'domcontentloaded' })
		// initScript fires on next nav only — run once now (function body, not
		// a bare declaration list, or evaluate() throws on 'function').
		await page.evaluate(new Function(EVAL_FN))

		for (const theme of USER_THEMES) {
			await page.goto(`${BASE}/apps/dutycheck/`, { waitUntil: 'domcontentloaded' })
			await setUserTheme(page, theme)
			// Marker assert — server-pinned theme must be painted, not assumed.
			const markerOk = await page.evaluate((t) => document.body.hasAttribute(`data-theme-${t}`), theme)
			const themeRes = { themeMarker: markerOk, pages: await measure(page, PROBES) }
			if (theme === 'light' || theme === 'dark') {
				themeRes.modal = await measureModalAndFieldError(page)
			}
			report.themes[theme] = themeRes
		}
		await resetUserTheme(page)
		report.deniedSurface = await measureDeniedSurface(browser)
	} finally {
		await context.close()
		await browser.close()
	}

	const TEXT_MIN = 4.5
	const BORDER_MIN = 3.0
	const findings = []
	for (const [theme, t] of Object.entries(report.themes)) {
		if (t.themeMarker === false) {
			findings.push({ theme, kind: 'theme-marker-missing', ratio: null, min: null })
		}
		for (const pr of t.pages || []) {
			if (pr.error) {
				findings.push({ theme, page: pr.page, kind: 'page-error', detail: pr.error })
				continue
			}
			for (const row of pr.rows || []) {
				for (const s of row.samples || []) {
					if (s.textRatio !== undefined && s.textRatio !== null && s.textRatio < TEXT_MIN) {
						findings.push({ theme, page: pr.page, kind: row.kind, cls: s.cls, ratio: s.textRatio, min: TEXT_MIN })
					}
					if (s.borderRatio !== undefined && s.borderRatio !== null && s.borderRatio < BORDER_MIN) {
						findings.push({ theme, page: pr.page, kind: row.kind + '-border', cls: s.cls, ratio: s.borderRatio, min: BORDER_MIN })
					}
				}
			}
		}
		const m = t.modal
		if (m && !m.skipped) {
			for (const s of m.modalControls || []) {
				if (s.borderRatio !== null && s.borderRatio < BORDER_MIN) {
					findings.push({ theme, page: 'modal', kind: 'modal-control-border', cls: s.cls, ratio: s.borderRatio, min: BORDER_MIN })
				}
			}
			for (const s of m.modalButtons || []) {
				if (s.textRatio !== null && s.textRatio < TEXT_MIN) {
					findings.push({ theme, page: 'modal', kind: 'modal-button-ink', cls: s.cls, ratio: s.textRatio, min: TEXT_MIN })
				}
				if (s.borderRatio !== null && s.borderRatio !== undefined && s.borderRatio < BORDER_MIN) {
					findings.push({ theme, page: 'modal', kind: 'modal-button-border', cls: s.cls, ratio: s.borderRatio, min: BORDER_MIN })
				}
			}
			const inv = m.invalidState || {}
			if (inv.errorShown) {
				if (inv.ariaInvalid !== 'true') {
					findings.push({ theme, page: 'modal', kind: 'aria-invalid-missing', detail: 'field__error shown without aria-invalid on control' })
				}
				if (inv.border && inv.border.borderRatio !== null && inv.border.borderRatio < BORDER_MIN) {
					findings.push({ theme, page: 'modal', kind: 'invalid-border', ratio: inv.border.borderRatio, min: BORDER_MIN })
				}
				if (inv.errorText && inv.errorText.textRatio !== null && inv.errorText.textRatio < TEXT_MIN) {
					findings.push({ theme, page: 'modal', kind: 'field-error-text', ratio: inv.errorText.textRatio, min: TEXT_MIN })
				}
			}
		}
	}
	const ds = report.deniedSurface
	if (ds && ds.samples) {
		for (const s of ds.samples) {
			const lim = s.kind === 'denied-icon-ink' ? 3.0 : TEXT_MIN // large decorative glyph
			const r = s.textRatio !== undefined ? s.textRatio : s.ratio
			if (r !== null && r !== undefined && r < lim) {
				findings.push({ theme: 'light', page: 'denied', kind: s.kind, cls: s.cls, ratio: r, min: lim })
			}
			if (s.borderRatio !== null && s.borderRatio !== undefined && s.borderRatio < BORDER_MIN) {
				findings.push({ theme: 'light', page: 'denied', kind: s.kind + '-border', cls: s.cls, ratio: s.borderRatio, min: BORDER_MIN })
			}
		}
	}
	report.findings = findings
	report.verdict = findings.length === 0 ? 'PASS' : 'FAIL'

	const outIdx = process.argv.indexOf('--out')
	const outPath = outIdx > 0 ? process.argv[outIdx + 1] : null
	if (outPath) {
		mkdirSync(dirname(outPath), { recursive: true })
		writeFileSync(outPath, JSON.stringify(report, null, 2))
		console.log(`wrote ${outPath}`)
	} else {
		console.log(JSON.stringify(report, null, 2).slice(0, 4000))
	}
	console.log(`contrast probe: ${report.verdict} (${findings.length} findings)`)
	process.exit(findings.length > 0 ? 1 : 0)
}

main().catch((e) => {
	console.error(e)
	process.exit(2)
})
