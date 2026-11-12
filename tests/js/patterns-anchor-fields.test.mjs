/**
 * Source contracts for the 2026-11-12 customer report: creating a rotation
 * pattern with anchor type "Fixed start date" always failed with the generic
 * "please review the form" toast. Root causes fixed in patterns.js:
 *
 *  - the editor offered anchorType 'fixed_date' but never rendered an
 *    anchorDate input and never sent one → server INVALID_ANCHOR_DATE (422)
 *  - anchorIsoWeekIndex was uneditable → parity (odd/even KW) could not be
 *    matched to an existing shift system; a naive null→int submit would have
 *    read as an anchor change and demanded a change reason
 *  - anchorEffectiveFrom was uneditable → forward-only anchor changes could
 *    not be scheduled
 *  - the ≥10-char reason requirement on anchor change was invisible → same
 *    dead-end 422 toast; now mirrored client-side with a field error
 *  - anchor error codes had no friendly message → generic 422 toast
 *
 * Run: node --test tests/js/patterns-anchor-fields.test.mjs
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.join(path.dirname(fileURLToPath(import.meta.url)), '../..');
const read = (rel) => fs.readFileSync(path.join(root, rel), 'utf8');

const src = read('js/patterns.js');
const messaging = read('js/common/messaging.js');
const onSubmitStart = src.indexOf('onSubmit: async () =>');
const onSubmit = src.slice(onSubmitStart, src.indexOf('\n\t\t},', onSubmitStart));

test('editor renders a date input for the fixed-date anchor', () => {
	assert.match(src, /id: 'dc-pat-anchor-date'/, 'anchorDate input missing');
	assert.match(src, /Week A starts in the week of/, 'anchorDate label missing');
});

test('editor renders a week-A reference date input for iso parity', () => {
	assert.match(src, /id: 'dc-pat-anchor-ref'/, 'parity reference input missing');
	assert.match(src, /A day inside week A/, 'parity reference label missing');
});

test('fixed_date without a date fails client-side with a field error', () => {
	assert.match(onSubmit, /if \(!anchorDate\)/, 'empty anchorDate must be rejected before submit');
	assert.match(onSubmit, /setFieldError\(anchorDateInput, anchorDateError/, 'error must land on the control, not a toast');
});

test('payload carries anchorDate / anchorIsoWeekIndex / anchorEffectiveFrom', () => {
	assert.match(onSubmit, /payload\.anchorDate = anchorDate/, 'anchorDate never sent');
	assert.match(onSubmit, /payload\.anchorIsoWeekIndex = anchorIsoWeekIndex/, 'anchorIsoWeekIndex never sent');
	assert.match(onSubmit, /payload\.anchorEffectiveFrom = anchorEffectiveFrom/, 'anchorEffectiveFrom never sent');
});

test('iso residue is derived from the reference date via ISO week number', () => {
	assert.match(src, /function isoWeekNumber\(/, 'ISO-8601 week helper missing');
	assert.match(onSubmit, /isoWeekNumber\(ref\) % weeks/, 'residue must be isoWeek mod cycleWeeks');
});

test('unchanged residue is omitted so null→int is not a phantom anchor change', () => {
	assert.match(onSubmit, /residue !== exEffective/, 'omit-on-equal guard missing');
});

test('anchor change on edit requires a ≥10-char reason client-side', () => {
	assert.match(onSubmit, /anchorChanged && reason\.length < 10/, 'reason mirror check missing');
	assert.match(onSubmit, /setFieldError\(reasonInput, reasonError/, 'reason error must be a field error');
});

test('anchor detail fields toggle with the anchor type select', () => {
	assert.match(src, /anchorDateField\.hidden = !fixed/, 'fixed-date field must only show for fixed_date');
	assert.match(src, /anchorRefField\.hidden = fixed/, 'parity field must only show for iso_week_parity');
	assert.match(src, /anchorSelect\.addEventListener\('change', syncAnchorFields\)/, 'select must re-sync visibility');
});

test('anchor error codes map to friendly messages', () => {
	for (const code of ['INVALID_ANCHOR_DATE', 'INVALID_ANCHOR_TYPE', 'ANCHOR_EFFECTIVE_IN_PAST', 'WEEK_DAYS_REQUIRED_FOR_CYCLE_CHANGE', 'ROTATION_DISABLED']) {
		assert.ok(messaging.includes(`case '${code}'`), `messaging.js misses ${code}`);
	}
});

test('submit-referenced editor handles live in openEditor scope, not render()', () => {
	// Regression guard: the 2026-11-12 delta declared these as `const` inside
	// render(), which made them invisible to the sibling onSubmit closure —
	// every Save threw ReferenceError("reasonInput is not defined") and the
	// modal swallowed it into the generic toast. Hoisted `let`/`const` in
	// openEditor scope is the fix; render() only assigns.
	const editorHead = src.slice(src.indexOf('async function openEditor'), src.indexOf('render: () =>'));
	for (const name of ['reasonInput', 'reasonError', 'anchorDateInput', 'anchorDateError', 'anchorRefInput', 'anchorEffInput', 'anchorEffError', 'exIsoIdx']) {
		assert.match(editorHead, new RegExp(`\\b(let|const)\\s+${name}\\b`), `${name} must be declared in openEditor scope so onSubmit can see it`);
	}
});
