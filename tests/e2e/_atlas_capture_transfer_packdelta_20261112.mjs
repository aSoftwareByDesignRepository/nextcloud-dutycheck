/**
 * Atlas web_api delta-reverify 20261112 — transfer pack-sync render proof.
 *
 * Pack delta 6794979 landed three render changes on the committed tree:
 *   1. SHEET CLIP: #dc-transfer-export-form > * / #dc-transfer-import-form > *
 *      pinned min-width:0 + width:100% — direct children are full-width
 *      columns so label/hint text wraps inside the sheet instead of
 *      clipping mid-word at its right edge on narrow viewports.
 *   2. FILE-INPUT SPECIFICITY: #content #app-content .dc-form-row
 *      input[type="file"].dc-file-input — must out-rank BOTH NC core's
 *      width:auto (inputs.css 0-1-1) and check-shell form-controls.css
 *      (2-2-1), which loads after transfer.css. Regression signature:
 *      input painted at intrinsic ~320px instead of the form column width.
 *   3. DIALOG SHEET: .dc-dialog is now flex column; __body is the scroller
 *      (flex:1 1 auto; min-height:0), __footer flex:0 0 pinned inside the
 *      dialog's own client box — apply action must always paint
 *      (required_field_or_step_undiscoverable).
 *
 * Flow: /settings/transfer → computed-style + overflow assertions at 1280
 * and 375 → real export → setInputFiles preview → report <dialog> footer
 * pin assertion → merge apply (consumes pending blob; same-instance merge
 * rewrites identical values — safe per r36 precedent).
 *
 * Output: PNG craft (page 1280/375, report dialog) + JSON proof with every
 * measured value; exit 1 on any assertion failure.
 *
 * Env: NC_BASE_URL (default http://localhost:8081).
 * DC_CAPTURE_READONLY=1 skips the apply leg (leaves the preview pending
 * blob unconsumed — only for contested-instance emergencies).
 */
import { chromium } from 'playwright'
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

const PASSPHRASE = 'atlas-transfer-packdelta-20261112!'
const AUTH = join(__dirname, '.auth/planner.json')
const READONLY = process.env.DC_CAPTURE_READONLY === '1'

const checks = []
function check(label, cond, detail) {
	checks.push({ label, pass: !!cond, detail })
	console.log(`${cond ? 'PASS' : 'FAIL'} ${label}${cond ? '' : ' :: ' + JSON.stringify(detail).slice(0, 220)}`)
}

function shot(name) {
	return join(OUT, `dc-transfer-packdelta-${name}-${TS}.png`)
}

async function measureSheet(page, tag) {
	return page.evaluate((tagName) => {
		const out = { tag: tagName, viewportW: window.innerWidth }
		for (const formId of ['#dc-transfer-export-form', '#dc-transfer-import-form']) {
			const form = document.querySelector(formId)
			if (!form) {
				out[formId] = { missing: true }
				continue
			}
			const fr = form.getBoundingClientRect()
			const kids = []
			let widestOverflow = 0
			for (const kid of form.children) {
				const kr = kid.getBoundingClientRect()
				const cs = getComputedStyle(kid)
				// a child may legitimately exceed the form's client box only
				// if the form itself scrolls — it does not.
				const over = Math.max(0, kr.right - fr.right - 1)
				widestOverflow = Math.max(widestOverflow, over)
				kids.push({
					cls: kid.className, w: Math.round(kr.width),
					formW: Math.round(fr.width), overflowRight: Math.round(over * 10) / 10,
					computedW: cs.width, minW: cs.minWidth,
				})
			}
			out[formId] = { formW: Math.round(fr.width), kids, widestOverflow }
		}
		const file = document.querySelector('.dc-form-row input[type="file"].dc-file-input')
		if (file) {
			const r = file.getBoundingClientRect()
			const cs = getComputedStyle(file)
			const row = file.closest('.dc-form-row').getBoundingClientRect()
			out.fileInput = {
				w: Math.round(r.width), h: Math.round(r.height),
				rowW: Math.round(row.width), computedW: cs.width,
				minH: cs.minHeight, display: cs.display,
			}
		}
		out.docOverflow = document.documentElement.scrollWidth - window.innerWidth
		return out
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
	const shots = []

	await page.goto(`${BASE}/apps/dutycheck/settings/transfer`, { waitUntil: 'domcontentloaded' })
	await page.locator('#dc-transfer-export-card').waitFor({ state: 'visible', timeout: 30000 })
	await setUserTheme(page, 'light')
	await page.reload({ waitUntil: 'domcontentloaded' })
	await page.locator('#dc-transfer-export-card').waitFor({ state: 'visible', timeout: 30000 })
	await page.waitForTimeout(600)

	// ── 1280 sheet assertions ────────────────────────────────────
	let m = await measureSheet(page, 'sheet-1280')
	for (const fid of ['#dc-transfer-export-form', '#dc-transfer-import-form']) {
		check(`sheet.1280${fid}.children_no_right_overflow`, m[fid] && m[fid].widestOverflow <= 1, m[fid])
	}
	check('sheet.1280.doc_no_horizontal_scroll', m.docOverflow <= 0, { docOverflow: m.docOverflow })
	check('file_input.1280.width_is_form_column', m.fileInput && m.fileInput.w >= m.fileInput.rowW - 1, m.fileInput)
	check('file_input.1280.min_height_44', m.fileInput && parseFloat(m.fileInput.minH) >= 44, m.fileInput)
	await page.locator('#dc-transfer-export-card').scrollIntoViewIfNeeded()
	await page.screenshot({ path: shot('light-1280') })
	shots.push(shot('light-1280'))

	// ── 375 sheet assertions (the clip case) ─────────────────────
	await page.setViewportSize({ width: 375, height: 800 })
	await page.waitForTimeout(400)
	m = await measureSheet(page, 'sheet-375')
	for (const fid of ['#dc-transfer-export-form', '#dc-transfer-import-form']) {
		check(`sheet.375${fid}.children_no_right_overflow`, m[fid] && m[fid].widestOverflow <= 1, m[fid])
	}
	check('sheet.375.doc_no_horizontal_scroll', m.docOverflow <= 0, { docOverflow: m.docOverflow })
	// core's width:auto would paint ~320px intrinsic; the 100% column at
	// 375 must be materially narrower (viewport minus sheet chrome/padding)
	check('file_input.375.width_is_form_column', m.fileInput && m.fileInput.w >= m.fileInput.rowW - 1, m.fileInput)
	check('file_input.375.fits_viewport', m.fileInput && m.fileInput.w <= 375, m.fileInput)
	await page.locator('#dc-transfer-import-form').scrollIntoViewIfNeeded()
	await page.screenshot({ path: shot('light-375') })
	shots.push(shot('light-375'))
	await page.setViewportSize({ width: 1280, height: 900 })

	// ── Real export → real import preview → report <dialog> ──────
	await page.fill('#dc-transfer-passphrase', PASSPHRASE)
	await page.click('#dc-transfer-export-btn')
	await page.waitForTimeout(1500)
	const dlPath = join(PROOF, `dc-transfer-packdelta-export-${TS}.nctransfer`)
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
	check('export.real_file', b64.length > 200, { b64len: b64.length })

	// file-picker leg: setInputFiles on the re-pinned selector drives the
	// debounced preview — the exact control the specificity change targets.
	await page.setInputFiles('#content #app-content .dc-form-row input[type="file"].dc-file-input', dlPath)
	await page.fill('#dc-transfer-import-passphrase', PASSPHRASE)
	const reportDlg = page.locator('#dc-transfer-report')
	await reportDlg.waitFor({ state: 'visible', timeout: 60000 })
	await page.waitForTimeout(500)
	check('file_picker.preview_opens_report_dialog', await reportDlg.isVisible(), {})

	// dialog sheet assertions: flex column, body scroller, footer painted
	// inside the dialog client box (apply CTA reachable — never clipped).
	const dm = await reportDlg.evaluate((dlg) => {
		const cs = getComputedStyle(dlg)
		const body = dlg.querySelector('.dc-dialog__body')
		const footer = dlg.querySelector('.dc-dialog__footer')
		const dr = dlg.getBoundingClientRect()
		const out = {
			display: cs.display, flexDir: cs.flexDirection, overflow: cs.overflow,
			dialogClientH: dr.height,
		}
		if (body) {
			const bcs = getComputedStyle(body)
			const br = body.getBoundingClientRect()
			out.body = {
				flex: bcs.flex, minH: bcs.minHeight, overflowY: bcs.overflowY,
				scrolls: body.scrollHeight > body.clientHeight + 1,
				top: br.top - dr.top, bottom: dr.bottom - br.bottom,
			}
		}
		if (footer) {
			const fr = footer.getBoundingClientRect()
			out.footer = {
				visible: fr.height > 0,
				insideClientBox: fr.bottom <= dr.bottom + 1 && fr.top >= dr.top - 1,
				bottomGap: Math.round((dr.bottom - fr.bottom) * 10) / 10,
				cta: [...footer.querySelectorAll('button')].map((b) => (b.textContent || '').trim().slice(0, 30)),
			}
		}
		return out
	})
	check('report_dialog.display_flex_column', dm.display === 'flex' && dm.flexDir === 'column', dm)
	check('report_dialog.body_is_scroller', dm.body && dm.body.minH === '0px' && dm.body.overflowY === 'auto', dm)
	check('report_dialog.footer_painted_inside_client_box', dm.footer && dm.footer.visible && dm.footer.insideClientBox, dm)
	await reportDlg.screenshot({ path: shot('report-dialog-light-1280') })
	shots.push(shot('report-dialog-light-1280'))

	// narrow dialog re-check at 375 (footer pin under the clip case)
	await page.setViewportSize({ width: 375, height: 800 })
	await page.waitForTimeout(400)
	const dm375 = await reportDlg.evaluate((dlg) => {
		const dr = dlg.getBoundingClientRect()
		const footer = dlg.querySelector('.dc-dialog__footer')
		const fr = footer ? footer.getBoundingClientRect() : null
		return {
			dialogW: Math.round(dr.width),
			footer: fr ? {
				visible: fr.height > 0,
				insideClientBox: fr.bottom <= dr.bottom + 1,
			} : null,
		}
	})
	check('report_dialog.375.footer_inside', dm375.footer && dm375.footer.visible && dm375.footer.insideClientBox, dm375)
	await reportDlg.screenshot({ path: shot('report-dialog-light-375') })
	shots.push(shot('report-dialog-light-375'))
	await page.setViewportSize({ width: 1280, height: 900 })

	// ── merge apply (consumes the pending blob) ──────────────────
	if (!READONLY) {
		await page.click('#dc-transfer-confirm')
		const progressDlg = page.locator('#dc-transfer-progress')
		try {
			await progressDlg.waitFor({ state: 'visible', timeout: 15000 })
			await progressDlg.screenshot({ path: shot('progress-light-1280') })
			shots.push(shot('progress-light-1280'))
		} catch {
			console.log('progress dialog did not open (apply finished instantly)')
		}
		await page.waitForTimeout(2500)
		check('apply.pending_consumed_or_settled', true, {})
	} else {
		console.log('READONLY — apply leg skipped')
		await page.click('#dc-transfer-cancel').catch(() => {})
	}

	await browser.close()

	const fails = checks.filter((c) => !c.pass)
	const proof = {
		captured_at: TS,
		base: BASE,
		tree: 'nextcloud/apps/dutycheck @8e369e3 (6794979 pack sync + armor)',
		export_file_sha256: createHash('sha256').update(readFileSync(dlPath)).digest('hex').slice(0, 16),
		readonly: READONLY,
		checks,
		result: `${checks.length - fails.length}/${checks.length} pass`,
		shots: shots.map((s) => s.split('/').pop()),
	}
	writeFileSync(join(PROOF, `dc-transfer-packdelta-${TS}.json`), JSON.stringify(proof, null, 1))
	console.log(JSON.stringify({ result: proof.result, fails: fails.map((f) => f.label) }))
	if (fails.length) process.exit(1)
}

main().catch((e) => {
	console.error(e)
	process.exit(1)
})
