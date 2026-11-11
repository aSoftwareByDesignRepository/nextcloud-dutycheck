/**
 * Atlas r36 transfer-surface recapture — .dc-btn variant-armor proof.
 *
 * Re-runs the r36 critic's surface set after landing the .dc-btn taxonomy
 * armor in css/app.css: settings/transfer page (light, 1280 + 375), the
 * opened import-preview report <dialog>, and the apply progress <dialog>.
 * Every .dc-btn element gets a computed-style dump into a JSON proof so the
 * variant distinction is falsifiable, not just pixel-visible.
 *
 * Real end-to-end flow: export a .nctransfer (strong passphrase) → re-import
 * it → merge preview opens the report dialog → Apply import opens the
 * progress dialog. Same-instance merge rewrites identical values (critic
 * already ran the same leg on this fixture).
 *
 * Env: NC_BASE_URL (default http://localhost:8081).
 * Run under nc-e2e-instance-lock.sh (shared instance serialization).
 */
import { chromium } from 'playwright'
import { execFileSync } from 'node:child_process'
import { createHash } from 'node:crypto'
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'
import { setUserTheme } from './helpers/theming.js'

const __dirname = dirname(fileURLToPath(import.meta.url))
const BASE = process.env.NC_BASE_URL || 'http://localhost:8081'
const TS = new Date().toISOString().replace(/[-:]/g, '').replace(/\..+/, 'Z')
const OUT = '/home/alex/Development/nextcloud-dev/.cursor/atlas-farm-v3/artifacts/dutycheck/craft/web'
const PROOF = '/home/alex/Development/nextcloud-dev/.cursor/atlas-farm-v3/artifacts/dutycheck/proofs'
mkdirSync(OUT, { recursive: true })
mkdirSync(PROOF, { recursive: true })

const PASSPHRASE = 'atlas-r36-recapture-passphrase-2026!'
const AUTH = join(__dirname, '.auth/planner.json')
const OCC = ['exec', '-u', 'www-data', 'nextcloud-app', 'php', '/var/www/html/occ']

function shot(name) {
	return join(OUT, `dc-transfer-${name}-${TS}.png`)
}

async function dumpBtnStyles(page, tag) {
	return page.evaluate((tagName) => {
		const rows = []
		for (const el of document.querySelectorAll('button.dc-btn, a.dc-btn')) {
			const cs = getComputedStyle(el)
			const r = el.getBoundingClientRect()
			rows.push({
				tag: tagName,
				id: el.id || null,
				classes: el.className,
				text: (el.textContent || '').trim().slice(0, 40),
				visible: r.width > 0 && r.height > 0 && cs.visibility !== 'hidden' && cs.display !== 'none',
				background: cs.backgroundColor,
				borderColor: cs.borderColor,
				borderWidth: cs.borderWidth,
				color: cs.color,
			})
		}
		return rows
	}, tag)
}

async function main() {
	const browser = await chromium.launch()
	const ctx = await browser.newContext({
		storageState: AUTH,
		viewport: { width: 1280, height: 900 },
		acceptDownloads: true,
	})
	const page = await ctx.newPage()
	const styles = []
	const shots = []

	await page.goto(`${BASE}/apps/dutycheck/settings/transfer`, { waitUntil: 'domcontentloaded' })
	await page.locator('#dc-transfer-export-card').waitFor({ state: 'visible', timeout: 30000 })
	await setUserTheme(page, 'light')
	await page.reload({ waitUntil: 'domcontentloaded' })
	await page.locator('#dc-transfer-export-card').waitFor({ state: 'visible', timeout: 30000 })
	await page.waitForTimeout(600)

	// ── Page craft 1280 ──────────────────────────────────────────
	await page.locator('#dc-transfer-export-card').scrollIntoViewIfNeeded()
	await page.screenshot({ path: shot('light-1280'), fullPage: false })
	shots.push(shot('light-1280'))
	styles.push(...await dumpBtnStyles(page, 'page-1280'))

	// ── Page craft 375 ───────────────────────────────────────────
	await page.setViewportSize({ width: 375, height: 800 })
	await page.waitForTimeout(400)
	await page.locator('#dc-transfer-export-card').scrollIntoViewIfNeeded()
	await page.screenshot({ path: shot('light-375'), fullPage: false })
	shots.push(shot('light-375'))
	styles.push(...await dumpBtnStyles(page, 'page-375'))
	await page.setViewportSize({ width: 1280, height: 900 })

	// ── Export a real transfer file ──────────────────────────────
	// Click the real button (exercises the UI path; the synthetic
	// blob-anchor download event is unreliable headless, so the file
	// bytes for the import leg are pulled through the same endpoint
	// directly afterwards).
	await page.fill('#dc-transfer-passphrase', PASSPHRASE)
	await page.click('#dc-transfer-export-btn')
	await page.waitForTimeout(1500)
	const dlPath = join(PROOF, `dc-transfer-export-${TS}.nctransfer`)
	const b64 = await page.evaluate(async (pass) => {
		const resp = await fetch(window.DutyCheckApi.url('/api/admin/transfer/export'), {
			method: 'POST', credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', requesttoken: (window.OC && OC.requestToken) || '' },
			body: JSON.stringify({ passphrase: pass }),
		})
		if (!resp.ok) throw new Error('export ' + resp.status)
		const bytes = new Uint8Array(await resp.arrayBuffer())
		let bin = ''
		for (let i = 0; i < bytes.length; i += 8192) {
			bin += String.fromCharCode.apply(null, bytes.subarray(i, i + 8192))
		}
		return btoa(bin)
	}, PASSPHRASE)
	writeFileSync(dlPath, Buffer.from(b64, 'base64'))
	console.log('exported', b64.length, 'b64 chars ->', dlPath)

	// ── Import it back: preview → report <dialog> (merge) ───────
	await page.setInputFiles('#dc-transfer-file', dlPath)
	await page.fill('#dc-transfer-import-passphrase', PASSPHRASE)
	const reportDlg = page.locator('#dc-transfer-report')
	await reportDlg.waitFor({ state: 'visible', timeout: 60000 })
	await page.waitForTimeout(400)
	await reportDlg.screenshot({ path: shot('report-dialog-light-1280') })
	shots.push(shot('report-dialog-light-1280'))
	styles.push(...await dumpBtnStyles(page, 'report-dialog'))

	// Mutating legs (apply → progress dialog, maintenance-mode replace
	// preview) only run when the instance lease is held — DC_CAPTURE_
	// READONLY=1 skips them so a contested shared instance never 503s a
	// sibling lane mid-leg. The button-variant proof is complete without
	// them: page + merge-report dialog carry every emitted variant.
	// DC_CAPTURE_READONLY=1 skips every write; DC_CAPTURE_APPLY=0
	// additionally skips the merge-apply leg (app-scoped writes — safe
	// even on a contested instance); the maintenance-mode replace leg
	// only runs with DC_CAPTURE_MAINTENANCE=1 + a held lease.
	const applyOk = process.env.DC_CAPTURE_READONLY !== '1' && process.env.DC_CAPTURE_APPLY !== '0'
	const maintenanceOk = applyOk && process.env.DC_CAPTURE_MAINTENANCE === '1'

	// ── Apply import → progress <dialog> ─────────────────────────
	if (applyOk) {
		await page.click('#dc-transfer-confirm')
		const progressDlg = page.locator('#dc-transfer-progress')
		try {
			await progressDlg.waitFor({ state: 'visible', timeout: 15000 })
			await progressDlg.screenshot({ path: shot('progress-light-1280') })
			shots.push(shot('progress-light-1280'))
			styles.push(...await dumpBtnStyles(page, 'progress-dialog'))
		} catch {
			console.log('progress dialog did not open (apply finished instantly)')
		}
		// settle: report dialog closes on apply
		await page.waitForTimeout(2500)
	} else {
		console.log('READONLY mode — apply/progress leg skipped (no lease)')
		await page.click('#dc-transfer-cancel').catch(() => {})
	}

	// ── Replace mode preview (maintenance window, brief) ─────────
	let replaceDone = false
	if (maintenanceOk) try {
		execFileSync('docker', [...OCC, 'maintenance:mode', '--on'], { stdio: 'pipe' })
		await page.setInputFiles('#dc-transfer-file', dlPath)
		await page.fill('#dc-transfer-import-passphrase', PASSPHRASE)
		await page.locator('input[name="mode"][value="replace"]').check({ force: true })
		await reportDlg.waitFor({ state: 'visible', timeout: 60000 })
		await page.waitForTimeout(400)
		await reportDlg.screenshot({ path: shot('report-replace-light-1280') })
		shots.push(shot('report-replace-light-1280'))
		styles.push(...await dumpBtnStyles(page, 'report-dialog-replace'))
		replaceDone = true
	} catch (e) {
		console.log('replace-mode capture skipped:', e.message?.slice(0, 200))
	} finally {
		try {
			execFileSync('docker', [...OCC, 'maintenance:mode', '--off'], { stdio: 'pipe' })
		} catch (e2) {
			console.log('maintenance --off failed:', e2.message)
		}
	}

	await browser.close()

	// ── computed-style proof ─────────────────────────────────────
	const proof = {
		captured_at: TS,
		base: BASE,
		export_file_sha256: createHash('sha256').update(readFileSync(dlPath)).digest('hex').slice(0, 16),
		replace_capture: replaceDone,
		shots: shots.map((s) => s.split('/').pop()),
		styles,
	}
	writeFileSync(join(PROOF, `dc-transfer-btn-styles-${TS}.json`), JSON.stringify(proof, null, 1))
	console.log(JSON.stringify(proof, null, 1))
}

main().catch((e) => {
	console.error(e)
	process.exit(1)
})
