// @ts-check
/**
 * Atlas POLICY ≥3.5.10 — executed click-path legs for the agent-auth
 * settings surface (/settings/agent). Closes dc-vis-r59-agent-surface-
 * no-craft-no-legs (dialog_matrix_proof_gap): the pack ships three native
 * <dialog> surfaces (mint / secret / revoke) that had zero rendered proof.
 *
 * Legs: mint open→cancel (zero POST), mint validation field-errors,
 * choice-cards rendered chrome (post-heal card recipe), mint→201→secret
 * dialog→table row (rebuildTable path), first-mint pendingReload reload,
 * secret reveal/copy chrome, revoke open→cancel→confirm durable postcondition,
 * armEscape dialog close, closed-dialogs-not-painted, 320px card transform.
 */
import { test, expect } from '@playwright/test'
import { execSync } from 'child_process'
import { mkdirSync } from 'fs'
import { dirname, resolve } from 'path'
import { fileURLToPath } from 'url'
import { credsFromEnv, login } from './helpers/auth.js'

const AGENT_URL = '/apps/dutycheck/settings/agent'
const CRAFT_DIR = resolve(
	dirname(fileURLToPath(import.meta.url)),
	'../../../../../.cursor/atlas-farm-v3/artifacts/dutycheck/craft/web',
)
// Unique label per run — parallel lanes must never share a fixture row.
const RUN = `atlas-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 6)}`

function craftStamp() {
	return new Date().toISOString().replace(/[-:]/g, '').replace(/\.\d+Z$/, 'Z')
}

/** @param {import('@playwright/test').Page} page @param {string} slug */
async function craftShot(page, slug) {
	mkdirSync(CRAFT_DIR, { recursive: true })
	const dest = resolve(CRAFT_DIR, `dc-agent-${slug}-${craftStamp()}.png`)
	await page.screenshot({ path: dest, fullPage: false })
	console.log(`craft: ${dest}`)
	return dest
}

function db(sql) {
	return execSync(
		`docker exec nextcloud-mariadb mariadb -u nextcloud -pnextcloud_password nextcloud -N -e "${sql}"`,
		{ encoding: 'utf8' },
	).trim()
}

/** POST helper bound to the page's session requesttoken. */
async function agentApi(page, method, path, body) {
	const rt = await page.evaluate(() => window.OC?.requestToken || '')
	const res = await page.request.fetch(`/apps/dutycheck${path}`, {
		method,
		headers: { 'requesttoken': rt, 'Content-Type': 'application/json' },
		data: body,
	})
	return { status: res.status(), json: await res.json().catch(() => ({})) }
}

test.describe('agent-auth settings surface — executed dialog legs', () => {
	test.skip(!process.env.NC_ADMIN_USER, 'Requires NC_ADMIN_USER / NC_ADMIN_PASS')
	test.setTimeout(90_000)

	test.beforeAll(() => {
		// Deterministic start: no token rows → first mint exercises the
		// pendingReload heal (no server-rendered tbody to rebuild into).
		db("DELETE FROM oc_dc_agent_tokens WHERE label LIKE 'atlas-%' OR user_id='dc_atlas_planner'")
	})

	test.afterAll(() => {
		db("DELETE FROM oc_dc_agent_tokens WHERE label LIKE 'atlas-%'")
	})

	test('surface renders; all three dialogs closed and unpainted', async ({ page }) => {
		const consoleErrors = []
		page.on('pageerror', (e) => consoleErrors.push(String(e)))
		await login(page, credsFromEnv('ADMIN'))
		await page.goto(AGENT_URL, { waitUntil: 'domcontentloaded' })
		await expect(page.locator('#dc-agent-access')).toBeVisible()
		for (const id of ['#dc-agent-mint-dialog', '#dc-agent-secret-dialog', '#dc-agent-revoke-dialog']) {
			await expect(page.locator(id)).toBeAttached()
			await expect(page.locator(id)).not.toBeVisible()
			await expect(page.locator(`${id}[open]`)).toHaveCount(0)
		}
		await expect(page.locator('#dc-agent-empty')).toBeVisible()
		await expect(page.locator('#dc-agent-connect')).toBeVisible()
		await craftShot(page, 'settings-agent-closed')
		expect(consoleErrors).toEqual([])
	})

	test('mint open→cancel + Escape: zero mutation requests', async ({ page }) => {
		await login(page, credsFromEnv('ADMIN'))
		await page.goto(AGENT_URL, { waitUntil: 'domcontentloaded' })
		const posts = []
		page.on('request', (r) => {
			if (r.method() === 'POST' && r.url().includes('/agent/v1/tokens')) posts.push(r.url())
		})
		const dlg = page.locator('#dc-agent-mint-dialog')
		await page.locator('#dc-agent-connect').click()
		await expect(dlg).toBeVisible()
		// armEscape heal: NC notification handlers can eat the global Escape
		// keydown — the dialog-level binding must still close it.
		await page.keyboard.press('Escape')
		await expect(dlg).not.toBeVisible()
		await page.locator('#dc-agent-connect').click()
		await expect(dlg).toBeVisible()
		await page.locator('#dc-agent-mint-cancel').click()
		await expect(dlg).not.toBeVisible()
		expect(posts, 'cancel/Escape legs must never mint').toEqual([])
	})

	test('mint dialog: choice-cards rendered as cards; validation field errors', async ({ page }) => {
		await login(page, credsFromEnv('ADMIN'))
		await page.goto(AGENT_URL, { waitUntil: 'domcontentloaded' })
		await page.locator('#dc-agent-connect').click()
		const dlg = page.locator('#dc-agent-mint-dialog')
		await expect(dlg).toBeVisible()

		// Post-heal chrome: the three preset options render as CARDS —
		// bordered, ≥48px tall, checked state tinted (was bare radio+text).
		const cards = dlg.locator('.dc-choice-card')
		await expect(cards).toHaveCount(3)
		const cardMetrics = await cards.first().evaluate((el) => {
			const cs = getComputedStyle(el)
			const r = el.getBoundingClientRect()
			return { borderWidth: parseFloat(cs.borderWidth), height: r.height, display: cs.display }
		})
		expect(cardMetrics.borderWidth, 'choice-card has card chrome (border)').toBeGreaterThanOrEqual(1)
		expect(cardMetrics.height, 'choice-card ≥48px target').toBeGreaterThanOrEqual(48)
		const checkedTint = await dlg.locator('.dc-choice-card:has(> input[type="radio"]:checked)').first()
			.evaluate((el) => getComputedStyle(el).borderColor)
		expect(checkedTint, 'checked card carries primary border').not.toBe('rgb(118, 118, 118)')
		// dialog chrome: body/footer have padding rules (was unstyled)
		const dlgMetrics = await dlg.locator('.dc-dialog__body').evaluate((el) => {
			const cs = getComputedStyle(el)
			return { padding: parseFloat(cs.paddingTop), maxH: cs.maxHeight }
		})
		expect(dlgMetrics.padding, 'dialog body padded').toBeGreaterThanOrEqual(12)
		await craftShot(page, 'mint-dialog-open')

		// empty label → inline field error + aria-invalid, zero requests
		await page.locator('#dc-agent-mint-submit').click()
		const labelErr = page.locator('#dc-agent-label-error')
		await expect(labelErr).toBeVisible()
		await expect(labelErr).not.toBeEmpty()
		await expect(page.locator('#dc-agent-label')).toHaveAttribute('aria-invalid', 'true')
		await craftShot(page, 'mint-validation-label')

		// custom preset → scopes wrap unhidden; valid label + no scope picked
		// → the scopes field error renders (label error would mask it first)
		await page.locator('#dc-agent-label').fill(`${RUN}-scopes`)
		await page.locator('input[name="preset"][value="custom"]').check()
		await expect(page.locator('#dc-agent-custom-wrap')).toBeVisible()
		await expect(page.locator('#dc-agent-chips .dc-agent-chip').first()).toBeVisible()
		await craftShot(page, 'mint-dialog-custom-scopes')
		await page.locator('#dc-agent-mint-submit').click()
		await expect(page.locator('#dc-agent-scopes-error')).toBeVisible()
		await page.locator('#dc-agent-mint-cancel').click()
	})

	test('first mint on empty state: 201 → secret dialog → reload shows row', async ({ page }) => {
		await login(page, credsFromEnv('ADMIN'))
		await page.goto(AGENT_URL, { waitUntil: 'domcontentloaded' })
		await expect(page.locator('#dc-agent-empty')).toBeVisible()

		await page.locator('#dc-agent-connect').click()
		await page.locator('#dc-agent-label').fill(`${RUN}-first`)
		const resp = page.waitForResponse((r) => r.url().includes('/agent/v1/tokens') && r.request().method() === 'POST')
		await page.locator('#dc-agent-mint-submit').click()
		expect((await resp).status()).toBe(201)

		// secret dialog: token + config populated, reveal toggles type
		const secret = page.locator('#dc-agent-secret-dialog')
		await expect(secret).toBeVisible()
		const secretInput = page.locator('#dc-agent-secret')
		await expect(secretInput).toHaveValue(/^ckst_dutycheck_/)
		await expect(secretInput).toHaveAttribute('type', 'password')
		await page.locator('#dc-agent-secret-reveal').click()
		await expect(secretInput).toHaveAttribute('type', 'text')
		const cfg = await page.locator('#dc-agent-config').inputValue()
		const parsed = JSON.parse(cfg)
		expect(parsed?.mcpServers?.checksuite?.command).toBe('python3')
		await craftShot(page, 'secret-dialog')

		// pendingReload heal: no tbody existed → closing reloads the page and
		// the canonical server-rendered row appears (expect polls through the
		// reload until the row attaches).
		await page.locator('#dc-agent-secret-close').click()
		await expect(page.locator('#dc-agent-tbody tr', { hasText: `${RUN}-first` })).toHaveCount(1, { timeout: 20_000 })
		await craftShot(page, 'table-after-first-mint')
	})

	test('scope badges render as whole pills — clipped_chips guard', async ({ page }) => {
		// badge_min_content_collapse: the family .dc-badge recipe carries
		// white-space:normal + overflow-wrap:anywhere; as flex items of a
		// squeezed table cell the badges fragmented into per-character
		// columns (dc-vis-r60 must_fix). The .dc-agent-scope nowrap guard
		// must keep every pill's min-content at its full label so lines
		// break BETWEEN badges. Self-contained: mints its own token so a
		// worker-restart retry (new RUN) never depends on earlier tests.
		await login(page, credsFromEnv('ADMIN'))
		await page.goto(AGENT_URL, { waitUntil: 'domcontentloaded' })
		const label = `${RUN}-badges`
		const minted = await agentApi(page, 'POST', '/agent/v1/tokens', {
			label,
			preset: 'custom',
			scopes: ['dutycheck:roster:read', 'dutycheck:absences:read', 'dutycheck:open_shifts:read', 'dutycheck:swaps:read'],
		})
		expect(minted.status, 'badge fixture mints').toBe(201)
		await page.reload({ waitUntil: 'domcontentloaded' })
		const row = page.locator('#dc-agent-tbody tr', { hasText: label })
		await expect(row).toHaveCount(1)
		const badges = row.locator('.dc-agent-scope')
		const count = await badges.count()
		expect(count, 'default preset mints several scope badges').toBeGreaterThanOrEqual(2)
		const metrics = await badges.evaluateAll((els) => els.map((el) => {
			const cs = getComputedStyle(el)
			const r = el.getBoundingClientRect()
			return {
				whiteSpace: cs.whiteSpace,
				height: r.height,
				width: r.width,
				lineHeight: parseFloat(cs.lineHeight) || parseFloat(cs.fontSize) * 1.6,
			}
		}))
		for (const m of metrics) {
			expect(m.whiteSpace, 'scope badge never wraps mid-label').toBe('nowrap')
			// A single-line pill is ~1 line + padding/border; anything past
			// 2.5 line-heights means the label fragmented vertically.
			expect(m.height, 'scope badge stays a one-line pill, not a letter column')
				.toBeLessThan(m.lineHeight * 2.5)
			expect(m.width, 'pill is wider than a character column').toBeGreaterThan(40)
		}

		// table_actions_fit (clipped_control_text_at_screen_edge, dc-vis-r61):
		// at desktop width the whole row — label, pills, and the Rotate/Revoke
		// actions — must fit inside the wrap with no sideways scroll. This
		// fixture mints four scope pills, the widest realistic row.
		const wrap = page.locator('.dc-agent-tablewrap')
		const fit = await wrap.evaluate((el) => ({
			scrollWidth: el.scrollWidth,
			clientWidth: el.clientWidth,
		}))
		expect(fit.scrollWidth, 'agent table fits its wrap — no sideways scroll clips row actions')
			.toBeLessThanOrEqual(fit.clientWidth)
		const actionsBox = await row.locator('td.dc-row-actions').boundingBox()
		const wrapBox = await wrap.boundingBox()
		expect(actionsBox, 'actions cell painted').not.toBeNull()
		expect(actionsBox.x + actionsBox.width, 'Rotate/Revoke fully inside the wrap at rest')
			.toBeLessThanOrEqual(wrapBox.x + wrapBox.width + 1)
	})

	test('mint on populated table: row injected without reload', async ({ page }) => {
		await login(page, credsFromEnv('ADMIN'))
		await page.goto(AGENT_URL, { waitUntil: 'domcontentloaded' })
		await expect(page.locator('#dc-agent-tbody')).toBeVisible()
		await page.locator('#dc-agent-connect').click()
		await page.locator('#dc-agent-label').fill(`${RUN}-second`)
		const resp = page.waitForResponse((r) => r.url().includes('/agent/v1/tokens') && r.request().method() === 'POST')
		await page.locator('#dc-agent-mint-submit').click()
		expect((await resp).status()).toBe(201)
		await expect(page.locator('#dc-agent-secret-dialog')).toBeVisible()
		await page.locator('#dc-agent-secret-close').click()
		// rebuildTable injects the row in place — no navigation
		await expect(page.locator('#dc-agent-tbody tr', { hasText: `${RUN}-second` })).toHaveCount(1)
		expect(page.url()).toContain('/settings/agent')
	})

	test('revoke open→cancel keeps active row; confirm revokes durably', async ({ page }) => {
		await login(page, credsFromEnv('ADMIN'))
		await page.goto(AGENT_URL, { waitUntil: 'domcontentloaded' })
		const row = page.locator('#dc-agent-tbody tr', { hasText: `${RUN}-first` })
		await expect(row).toHaveCount(1)
		const tokenId = await row.getAttribute('data-token-id')
		expect(tokenId).toBeTruthy()

		// open→cancel: dialog shows label + destructive copy, nothing mutates
		await row.locator('.dc-agent-revoke').click()
		const dlg = page.locator('#dc-agent-revoke-dialog')
		await expect(dlg).toBeVisible()
		await expect(dlg.locator('#dc-agent-revoke-text')).toContainText(`${RUN}-first`)
		await expect(dlg.locator('#dc-agent-revoke-confirm')).toHaveClass(/dc-btn--danger/)
		await craftShot(page, 'revoke-dialog')
		await page.locator('#dc-agent-revoke-cancel').click()
		await expect(dlg).not.toBeVisible()
		const stillActive = await agentApi(page, 'GET', `/agent/v1/tokens`)
		expect(stillActive.json.tokens.find((t) => String(t.id) === String(tokenId))?.state).toBe('active')

		// open→confirm: durable postcondition — API re-read says revoked and
		// the DOM row loses its action buttons + gains the Revoked badge.
		await row.locator('.dc-agent-revoke').click()
		await expect(dlg).toBeVisible()
		const resp = page.waitForResponse((r) => r.url().includes(`/agent/v1/tokens/${tokenId}/revoke`) && r.request().method() === 'POST')
		await page.locator('#dc-agent-revoke-confirm').click()
		expect((await resp).status()).toBe(200)
		const after = await agentApi(page, 'GET', '/agent/v1/tokens')
		expect(after.json.tokens.find((t) => String(t.id) === String(tokenId))?.state).toBe('revoked')
		await expect(row).toHaveClass(/dc-agent-row--inactive/)
		await expect(row.locator('.dc-agent-revoke')).toHaveCount(0)
		await craftShot(page, 'table-after-revoke')
	})

	test('mint dialog at 320px: full-viewport card transform', async ({ page }) => {
		await page.setViewportSize({ width: 320, height: 720 })
		await login(page, credsFromEnv('ADMIN'))
		await page.goto(AGENT_URL, { waitUntil: 'domcontentloaded' })
		await page.locator('#dc-agent-connect').click()
		const dlg = page.locator('#dc-agent-mint-dialog')
		await expect(dlg).toBeVisible()
		const box = await dlg.boundingBox()
		expect(box.width, 'dialog fills the small viewport').toBeGreaterThanOrEqual(315)
		// cards collapse to a single column and stay ≥48px targets
		const cards = dlg.locator('.dc-choice-card')
		const heights = await cards.evaluateAll((els) => els.map((e) => e.getBoundingClientRect().height))
		for (const h of heights) expect(h).toBeGreaterThanOrEqual(48)
		await craftShot(page, 'mint-dialog-320')
	})
})
