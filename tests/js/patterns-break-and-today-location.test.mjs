/**
 * Source contracts for the 2026-10-22 customer-report fixes:
 *
 *  - patterns.js must expose a break (Pause) field per working day and round-
 *    trip it — the previous version hardcoded breakMinutes:0, which is why a
 *    planner could never give a pattern day a break and every >6h suggest
 *    candidate died on break_too_short.
 *  - readGrid must not drop API-set fields it does not render
 *    (shiftTemplateId) — re-saving a pattern used to wipe them.
 *  - today.js must not offer deactivated locations and must forget a stored
 *    pick that the board now reports as LOCATION_NOT_FOUND.
 *
 * Run: node --test tests/js/patterns-break-and-today-location.test.mjs
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.join(path.dirname(fileURLToPath(import.meta.url)), '../..');
const read = (rel) => fs.readFileSync(path.join(root, rel), 'utf8');

test('patterns.js renders a labelled break input per day cell', () => {
	const src = read('js/patterns.js');
	assert.match(src, /dc-patterns__break/, 'break input class missing');
	assert.match(src, /-break'/, 'break input id suffix missing');
	assert.match(src, /msg\('label-break'\)/, 'break label must come from the translated data attribute');
	assert.match(src, /breakInput\.disabled = !day\.isWorking/, 'break input must start disabled on non-working days');
	assert.match(src, /breakInput\.disabled = !on/, 'working toggle must enable/disable the break input too');
});

test('readGrid round-trips breakMinutes instead of hardcoding 0', () => {
	const src = read('js/patterns.js');
	const start = src.indexOf('function readGrid(');
	assert.ok(start > 0);
	const fn = src.slice(start, src.indexOf('\n\tfunction ', start + 10));
	assert.match(fn, /\.dc-patterns__break'\)\?\.value/, 'readGrid must read the break input');
	assert.match(fn, /breakMinutes: isWorking \? Math\.max\(0, Math\.min\(720/, 'breakMinutes must be clamped to the server range');
	assert.doesNotMatch(fn, /breakMinutes:\s*0,/, 'hardcoded breakMinutes:0 is the regression');
});

test('readGrid preserves API-set shiftTemplateId on re-save', () => {
	const src = read('js/patterns.js');
	const start = src.indexOf('function readGrid(');
	const fn = src.slice(start, src.indexOf('\n\tfunction ', start + 10));
	assert.match(fn, /editorDayMap\.get\(dayKey\(w, d\)\)/, 'readGrid must look up the previously loaded day');
	assert.match(fn, /shiftTemplateId: prev\.shiftTemplateId \?\? null/, 'shiftTemplateId must survive a re-save');
});

test('buildGrid exposes the loaded day map for readGrid', () => {
	const src = read('js/patterns.js');
	assert.match(src, /editorDayMap = map/, 'buildGrid must store the week-day map');
});

test('patterns template ships the translated break label', () => {
	const tpl = read('templates/patterns.php');
	assert.match(tpl, /data-label-break="<\?php p\(\$l->t\('Break \(min\)'\)\); \?>"/);
});

test('editor surfaces the organisation break default next to the grid', () => {
	const src = read('js/patterns.js');
	assert.match(src, /planning-defaults/, 'editor must fetch the org planning defaults');
	assert.match(src, /defaultBreakMinutes/, 'editor must read defaultBreakMinutes');
	assert.match(src, /inherits the organisation default/, 'inherit hint copy missing');
});

test('today.js hides deactivated locations from the picker', () => {
	const src = read('js/today.js');
	const start = src.indexOf('async function loadLocations(');
	const fn = src.slice(start, src.indexOf('\n\tfunction ', start + 10));
	assert.match(fn, /\.filter\(\(loc\) => loc && loc\.active !== false\)/, 'inactive locations must be filtered out');
});

test('today.js forgets a stale stored location on LOCATION_NOT_FOUND', () => {
	const src = read('js/today.js');
	const start = src.indexOf("code === 'LOCATION_NOT_FOUND'");
	assert.ok(start > 0, 'LOCATION_NOT_FOUND branch missing');
	const block = src.slice(start, start + 900);
	assert.match(block, /localStorage\.removeItem\(STORAGE_KEY\)/, 'stale stored id must be cleared');
	assert.match(block, /await loadLocations\(\)/, 'options must reload so the dead entry disappears');
});
