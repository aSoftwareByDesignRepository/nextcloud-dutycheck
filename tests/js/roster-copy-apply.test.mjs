/**
 * Contract: roster copy Apply CTA is hidden until preview has something to copy.
 * Run: node --test tests/js/roster-copy-apply.test.mjs
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.join(path.dirname(fileURLToPath(import.meta.url)), '../..');
const rosterJs = fs.readFileSync(path.join(root, 'js/roster.js'), 'utf8');
const rosterTpl = fs.readFileSync(path.join(root, 'templates/roster.php'), 'utf8');

test('Apply copy starts hidden in markup (no dead primary)', () => {
	assert.match(rosterTpl, /id="dc-roster-copy-apply"[^>]*\bhidden\b/);
	assert.match(rosterTpl, /Apply appears after a preview that has something to copy/);
});

test('roster.js hides Apply until preview wouldCreate > 0', () => {
	assert.match(rosterJs, /function setCopyApplyVisible/);
	assert.match(rosterJs, /setCopyApplyVisible\(false\)/);
	assert.match(rosterJs, /setCopyApplyVisible\(canApply\)/);
	assert.match(rosterJs, /const canApply = wouldCreate > 0/);
	assert.match(rosterJs, /Nothing to copy from that period/);
	assert.match(rosterJs, /Prefer omit over disable/);
	assert.match(rosterJs, /data-dc-copy-ready/);
});
