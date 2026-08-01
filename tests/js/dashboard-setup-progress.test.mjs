/**
 * Contract tests for dashboard setup progress rendering.
 * Mirrors js/dashboard.js renderSetupProgress behaviour without a browser.
 */
import { describe, it } from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..')
const dashboardJs = readFileSync(resolve(root, 'js/dashboard.js'), 'utf8')
const appCss = readFileSync(resolve(root, 'css/app.css'), 'utf8')

describe('dashboard setup progress source contracts', () => {
	it('marks only the first incomplete step as current with a primary CTA', () => {
		assert.match(dashboardJs, /classes\.push\('is-current'\)/)
		assert.match(dashboardJs, /class: 'button primary'/)
		assert.match(dashboardJs, /const currentIndex = steps\.findIndex/)
		// Done rows must not keep competing CTAs.
		assert.doesNotMatch(
			dashboardJs,
			/if \(!step\.done && step\.url && step\.cta\)/,
		)
	})

	it('exposes textual status labels instead of aria-hidden-only icons', () => {
		assert.match(dashboardJs, /'aria-label': statusLabel/)
		assert.match(dashboardJs, /t\('dutycheck', 'Done'\)/)
		assert.match(dashboardJs, /t\('dutycheck', 'Next step'\)/)
		assert.doesNotMatch(
			dashboardJs,
			/attrs: \{ 'aria-hidden': 'true' \},\s*\n\s*text: step\.done/,
		)
	})

	it('suppresses Quick start while setup gates remain', () => {
		assert.match(dashboardJs, /setQuickstartSuppressed\(true\)/)
		assert.match(dashboardJs, /data-dc-hint-suppress', 'setup'/)
	})

	it('uses success-text ink on success surfaces (NC34 AA)', () => {
		assert.match(
			appCss,
			/\.dc-setup-checklist__item\.is-done\s+\.dc-setup-checklist__status\s*\{[^}]*color:\s*var\(--color-success-text/s,
		)
		assert.doesNotMatch(
			appCss,
			/\.dc-setup-checklist__item\.is-done\s+\.dc-setup-checklist__status\s*\{[^}]*color:\s*var\(--color-primary-element-text/s,
		)
		assert.doesNotMatch(
			appCss,
			/\.dc-setup-checklist__item\.is-done\s+\.dc-setup-checklist__status\s*\{[^}]*color:\s*#fff/s,
		)
	})
})
