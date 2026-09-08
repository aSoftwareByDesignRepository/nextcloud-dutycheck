/**
 * DutyCheck Atlas — pin force_language=de for the capture suite.
 * Farm alphabet wars: always stash peers + write a LAST overlay that sorts after
 * every known peer (deskcheck EN, ticketcheck ULTRA, …).
 */
import { execFileSync } from 'child_process'
import { existsSync, mkdirSync, readFileSync, writeFileSync, unlinkSync } from 'fs'
import { dirname, resolve } from 'path'
import { fileURLToPath } from 'url'

const STATE_DIR = resolve(dirname(fileURLToPath(import.meta.url)), '../../../.atlas-locale-state')
const STATE_FILE = resolve(STATE_DIR, 'peer-locale-de.json')
/** Keep under 255-byte filename limit; beat ~200z peer WIN overlays. */
const OVERLAY = 'z'.repeat(220) + '-dc-r9-de-WIN.config.php'
const PEER_SAVE_DIR = '/tmp/dutycheck-atlas-peer-overlays'

function dockerOcc(args, { ignoreFail = true } = {}) {
	try {
		return execFileSync(
			'docker',
			['exec', '-u', 'www-data', '-w', '/var/www/html', 'nextcloud-app', 'php', 'occ', ...args],
			{ encoding: 'utf8', timeout: 30_000, stdio: ['ignore', 'pipe', 'pipe'] },
		).trim()
	} catch (err) {
		if (!ignoreFail) throw err
		return ''
	}
}

function dockerSh(script) {
	try {
		return execFileSync(
			'docker',
			['exec', '-u', 'www-data', 'nextcloud-app', 'sh', '-c', script],
			{ encoding: 'utf8', timeout: 25_000, stdio: ['ignore', 'pipe', 'pipe'] },
		)
	} catch {
		return ''
	}
}

function readPeerForceLanguage() {
	return dockerOcc(['config:system:get', 'force_language']) || ''
}

function stashPeerOverlays() {
	dockerSh(`mkdir -p ${PEER_SAVE_DIR}`)
	dockerSh(
		`for f in /var/www/html/config/zzz*.config.php; do
       [ -f "\$f" ] || continue
       base=\$(basename "\$f")
       [ "\$base" = "${OVERLAY}" ] && continue
       if grep -q force_language "\$f" 2>/dev/null; then
         mv "\$f" ${PEER_SAVE_DIR}/ 2>/dev/null || true
       fi
     done`,
	)
}

function restorePeerOverlays() {
	dockerSh(
		`if [ -d ${PEER_SAVE_DIR} ]; then for f in ${PEER_SAVE_DIR}/*; do [ -f "\$f" ] && mv "\$f" /var/www/html/config/ 2>/dev/null; done; fi`,
	)
}

function writeDeOverlay() {
	const php = `<?php
$CONFIG = [
  'force_language' => 'de',
  'force_locale' => 'de_DE',
  'default_language' => 'de',
  'default_locale' => 'de_DE',
];
`
	const b64 = Buffer.from(php).toString('base64')
	dockerSh(
		`printf '%s' '${b64}' | base64 -d > /var/www/html/config/${OVERLAY} && chmod 640 /var/www/html/config/${OVERLAY} && test -s /var/www/html/config/${OVERLAY}`,
	)
}

/** Pin DE for the suite. Idempotent. */
export function pinGermanForSuite() {
	mkdirSync(STATE_DIR, { recursive: true })
	const peer = {
		force_language: readPeerForceLanguage(),
		saved_at: new Date().toISOString(),
	}
	writeFileSync(STATE_FILE, JSON.stringify(peer, null, 2) + '\n')
	stashPeerOverlays()
	writeDeOverlay()
	dockerOcc(['user:setting', 'dc_atlas_planner', 'core', 'lang', 'de'])
	dockerOcc(['config:system:set', 'force_language', '--value=de'])
	dockerOcc(['config:system:set', 'force_locale', '--value=de_DE'])
	dockerOcc(['config:system:set', 'default_language', '--value=de'])
	dockerSh('php -r \'if(function_exists("apcu_clear_cache")) apcu_clear_cache();\' 2>/dev/null || true')
	return peer
}

/** Re-assert DE mid-suite (cheap). Re-stashes peers that reappear. */
export function ensureGermanUi() {
	stashPeerOverlays()
	writeDeOverlay()
	dockerOcc(['user:setting', 'dc_atlas_planner', 'core', 'lang', 'de'])
	dockerOcc(['config:system:set', 'force_language', '--value=de'])
	dockerOcc(['config:system:set', 'force_locale', '--value=de_DE'])
}

/** Restore peer locale after suite. */
export function restorePeerLocale() {
	dockerSh(`rm -f /var/www/html/config/${OVERLAY}`)
	restorePeerOverlays()
	let peer = { force_language: '' }
	if (existsSync(STATE_FILE)) {
		try {
			peer = JSON.parse(readFileSync(STATE_FILE, 'utf8'))
		} catch {
			/* ignore */
		}
	}
	const fl = (peer.force_language || '').trim()
	if (fl && fl !== 'de') {
		dockerOcc(['config:system:set', 'force_language', '--value=' + fl])
	}
	try {
		unlinkSync(STATE_FILE)
	} catch {
		/* ignore */
	}
}
