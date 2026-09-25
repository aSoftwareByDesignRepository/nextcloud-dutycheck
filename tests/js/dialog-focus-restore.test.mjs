/**
 * Contract tests for the dialog focus-restore / focus-trap fixes.
 *
 * Pins the ds_chrome lane findings (farm recurring defect class):
 * focus() on a detached or still-disabled trigger is a silent no-op that
 * strands keyboard users on <body>. These tests fail if the source loses
 * any of the guards that keep focus inside dialogs and land it on a stable
 * landmark after destructive re-renders.
 *
 * Run: node --test tests/js/dialog-focus-restore.test.mjs
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

test('openModal close() guards trigger focus with isConnected', () => {
	const src = read('js/common/components.js');
	// The trigger may have been detached by a re-render between open and close.
	assert.match(src, /previousFocus\.isConnected/, 'close() must check previousFocus.isConnected before focusing');
});

test('openModal close() falls back to the main landmark when focus is stranded on body', () => {
	const src = read('js/common/components.js');
	assert.match(src, /document\.activeElement/, 'close() must inspect activeElement after restore');
	assert.match(src, /getElementById\('dc-main-content'\)/, 'close() must fall back to #dc-main-content');
});

test('openModal close() retries focus restore in a macrotask (trigger re-enable)', () => {
	const src = read('js/common/components.js');
	// setBusy(false) commonly re-enables the trigger inside resolve() — retry after it.
	assert.match(src, /setTimeout\(restoreFocus,\s*0\)/, 'close() must retry restoreFocus in a macrotask');
});

test('openModal close() observes re-renders that destroy the restored trigger', () => {
	const src = read('js/common/components.js');
	// Destructive confirms re-render after the awaited API call — past the
	// macrotask window. A bounded MutationObserver must rescue focus.
	assert.match(src, /new MutationObserver/, 'close() must watch for the trigger being detached by re-render');
	assert.match(src, /mo\.observe\(document\.body/, 'observer must watch document.body subtree');
});

test('roster assignment modal restores the detached form panel via the captured node', () => {
	const src = read('js/roster.js');
	// After openModal removes the overlay, the reparented panel is unreachable
	// by getElementById — the onClose must pass the captured node reference.
	assert.match(src, /restoreAssignmentFormHost\(panel\)/, 'onClose must pass the captured panel to restoreAssignmentFormHost');
});

test('native swap dialog has an explicit Tab boundary trap and Escape close', () => {
	const src = read('js/my-roster.js');
	assert.match(src, /wireSwapDialogA11y/, 'my-roster must wire a11y handlers on #dc-swap-dialog');
	assert.match(src, /event\.key === 'Escape'/, 'swap dialog must handle Escape explicitly');
	assert.match(src, /dialog\.close\(\)/, 'swap dialog Escape path must call dialog.close()');
	assert.match(src, /event\.key !== 'Tab'/, 'swap dialog must trap Tab at boundaries');
	assert.match(src, /event\.shiftKey && document\.activeElement === first/, 'Shift+Tab on first focusable must wrap to last');
});

test('native swap dialog close falls back to main landmark', () => {
	const src = read('js/my-roster.js');
	// Focus lost to <body> on close (trigger destroyed by re-render) must be rescued.
	const idx = src.indexOf("dialog.addEventListener('close'");
	assert.ok(idx > -1, 'swap dialog must register a close listener');
	const tail = src.slice(idx, idx + 800);
	assert.match(tail, /dc-main-content/, 'swap dialog close handler must fall back to #dc-main-content');
});

test('license confirm modal guards trigger focus with isConnected + main fallback', () => {
	const src = read('js/license-settings.js');
	assert.match(src, /modalReturnFocusEl\.isConnected/, 'license closeModal must check isConnected');
	assert.match(src, /getElementById\('dc-main-content'\)/, 'license closeModal must fall back to #dc-main-content');
});
