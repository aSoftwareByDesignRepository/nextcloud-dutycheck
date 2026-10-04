// @ts-check
/**
 * ATLAS_RENDERED_SURFACE_CONTRACT — assert the *rendered* truth of every
 * DutyCheck page surface (list markers, select centring, icon boxes, no
 * inherited centering on form controls). DOM-level specs pass on visually
 * broken pages; this contract does not.
 *
 * Role-gated pages (roster/print for planners, my-* for unlinked planners)
 * degrade to the honest access-denied shell — that surface is asserted too.
 */
import { createRequire } from 'module'
import { test } from '@playwright/test'
import { login, credsFromEnv } from './helpers/auth.js'

const require = createRequire(import.meta.url)
const { assertAtlasRenderedSurface } = require('../../../_shared/e2e/atlas-rendered-surface-contract.js')

const CONTENT = '#app-content'
// .dc-sr-only controls are 1px clipped by design — exempt from geometry checks.
const NAV = '#app-navigation, nav, #header, .dc-sr-only'
// Intentionally marker-less component lists (chips, checklists with icon
// markers, autocomplete dropdowns, card rows, timelines, breadcrumbs) — NOT
// prose lists. Prose lists (.dc-callout__list, .dc-settings-privacy__list,
// .dc-suggest-preview__counts) deliberately keep markers and must stay checked.
const LIST_ALLOW = '.dc-chip-list, .dc-setup-checklist, .dc-entity-results, '
	+ '.dc-conflicts, .dc-today__gaps, .dc-today__timeline, .dc-my-team__list, '
	+ '.dc-patterns__list, .dc-my-availability__list, .dc-dienst-team__summary, '
	+ '.dc-timezone-picker__results, .dc-breadcrumb, .dc-nav-footer__menu, '
	+ '.dc-license-seat-search__listbox'

// Every shipped page route (app mounts into #dc-main-content inside
// #app-content; denied surfaces render .dc-denied instead).
const PAGES = [
	{ name: 'dashboard', url: '/apps/dutycheck/dashboard' },
	{ name: 'today', url: '/apps/dutycheck/today' },
	{ name: 'patterns', url: '/apps/dutycheck/patterns' },
	{ name: 'roster', url: '/apps/dutycheck/roster' },
	{ name: 'roster-print', url: '/apps/dutycheck/roster/print' },
	{ name: 'periods', url: '/apps/dutycheck/periods' },
	{ name: 'employees', url: '/apps/dutycheck/employees' },
	{ name: 'locations', url: '/apps/dutycheck/locations' },
	{ name: 'absences', url: '/apps/dutycheck/absences' },
	{ name: 'my-roster', url: '/apps/dutycheck/my-roster' },
	{ name: 'my-absences', url: '/apps/dutycheck/my-absences' },
	{ name: 'needs-role', url: '/apps/dutycheck/needs-role' },
	{ name: 'settings-access', url: '/apps/dutycheck/settings/access' },
	{ name: 'settings-duty-roles', url: '/apps/dutycheck/settings/duty-roles' },
	{ name: 'settings-planning', url: '/apps/dutycheck/settings/planning' },
	{ name: 'settings-companies', url: '/apps/dutycheck/settings/companies' },
	{ name: 'settings-conflicts', url: '/apps/dutycheck/settings/conflicts' },
	{ name: 'settings-shift-templates', url: '/apps/dutycheck/settings/shift-templates' },
	{ name: 'settings-qualifications', url: '/apps/dutycheck/settings/qualifications' },
	{ name: 'settings-planner-scope', url: '/apps/dutycheck/settings/planner-scope' },
	{ name: 'settings-operations', url: '/apps/dutycheck/settings/operations' },
	{ name: 'settings-dienst-team', url: '/apps/dutycheck/settings/dienst-team' },
	{ name: 'settings-integration', url: '/apps/dutycheck/settings/integration' },
	{ name: 'settings-privacy', url: '/apps/dutycheck/settings/privacy' },
	{ name: 'settings-license', url: '/apps/dutycheck/settings/license' },
	{ name: 'settings-support', url: '/apps/dutycheck/settings/support' },
]

test.describe('ATLAS_RENDERED_SURFACE_CONTRACT', () => {
	test.skip(!process.env.NC_ADMIN_USER, 'Requires NC_ADMIN_USER / NC_ADMIN_PASS')

	for (const p of PAGES) {
		test(`rendered surface: ${p.name}`, async ({ page }) => {
			await login(page, credsFromEnv('ADMIN'))
			await page.goto(p.url, { waitUntil: 'domcontentloaded' })
			// Either the mounted app main or the honest access-denied shell.
			await page
				.locator('#dc-main-content, .dc-denied, #dc-print-root, main')
				.first()
				.waitFor({ state: 'attached', timeout: 30000 })
			await assertAtlasRenderedSurface(page, {
				content: CONTENT,
				navExclude: NAV,
				listAllow: LIST_ALLOW,
			})
		})
	}
})
