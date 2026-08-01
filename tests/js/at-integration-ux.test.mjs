/**
 * Lightweight contract tests for AT integration UX — asserts against source files.
 * Run: node --test tests/js/at-integration-ux.test.mjs
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.join(path.dirname(fileURLToPath(import.meta.url)), '../..');

function read(rel) {
	return fs.readFileSync(path.join(root, rel), 'utf8');
}

function bannerDismissStorageKey(key) {
	return 'dc.at.banner.dismiss.' + String(key || 'dc-at-integration-banner-v1');
}

test('banner dismiss key is namespaced and stable', () => {
	assert.equal(bannerDismissStorageKey('dc-at-integration-banner-v1'), 'dc.at.banner.dismiss.dc-at-integration-banner-v1');
	assert.equal(bannerDismissStorageKey(''), 'dc.at.banner.dismiss.dc-at-integration-banner-v1');
});

test('planner empty-state copy lives in absences.js', () => {
	const src = read('js/absences.js');
	assert.match(src, /No absences in this list yet\. Linked employees request time off in ArbeitszeitCheck\. Last sync: \{time\}\./);
});

test('employee empty-state copy lives in my-absences.js', () => {
	const src = read('js/my-absences.js');
	assert.match(src, /No absences shown here yet\. Request time off in ArbeitszeitCheck/);
});

test('pii hidden copy lives in absences.js and is honest', () => {
	const src = read('js/absences.js');
	assert.match(src, /Details for this absence are only available in ArbeitszeitCheck\./);
	assert.doesNotMatch(src, /Details for this absence are only available in ArbeitszeitCheck\.[^']*reason/i);
});

test('AT collision recovery message is wired in RosterService + roster.js', () => {
	const php = read('lib/Service/RosterService.php');
	const js = read('js/roster.js');
	assert.match(php, /Employee assignment collides with an ArbeitszeitCheck absence/);
	assert.doesNotMatch(php, /Employee assignment collides with an AT absence/);
	assert.match(js, /recoveryUrl/);
	assert.match(js, /Open ArbeitszeitCheck/);
});

test('print CSS hides integration banners and expands AT outbound URLs after global wipe', () => {
	const css = read('css/app.css');
	const printBlocks = [...css.matchAll(/@media print\s*\{([\s\S]*?)\n\}/g)].map((m) => m[1]);
	assert.ok(printBlocks.some((b) => /\.dc-at-banner[\s\S]*display:\s*none/i.test(b)));
	const wipeIdx = printBlocks.findIndex((b) => /a\[href\]::after[\s\S]*content:\s*none\s*!important/i.test(b));
	assert.ok(wipeIdx >= 0, 'global print link wipe must exist');
	assert.match(
		printBlocks[wipeIdx],
		/\.dc-conflict__actions a\[href\]::after[\s\S]*attr\(href\)[\s\S]*!important/i,
		'AT URL expansion must follow and override the global wipe in the same print block',
	);
});

test('templates must not ship empty peer href placeholders', () => {
	// Settings split: the AT integration section lives on its own sub-page.
	const integrationPartial = 'templates/parts/settings/integration.php';
	assert.match(read(integrationPartial), /id="dc-at-open-peer"/, 'peer link must live on the integration sub-page');
	for (const rel of [integrationPartial, 'templates/my-roster.php']) {
		const src = read(rel);
		assert.doesNotMatch(src, /id="dc-at-open-peer"[^>]*href=""/);
		assert.doesNotMatch(src, /id="dc-ical-open-azc"[^>]*href=""/);
		assert.doesNotMatch(src, /id="dc-at-open-peer"[^>]*href="#"/);
		assert.doesNotMatch(src, /id="dc-ical-open-azc"[^>]*href="#"/);
	}
});
