/**
 * DutyCheck license seat search — client contract + normalizeSearchHits logic.
 * Run: node --test tests/js/license-seat-search.test.mjs
 */
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, it } from 'node:test';

const appRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const js = readFileSync(path.join(appRoot, 'js/license-settings.js'), 'utf8');

describe('DutyCheck license seat search client', () => {
	it('wires search to items/users normalization (not items-only blind read)', () => {
		assert.match(js, /function normalizeSearchHits/);
		assert.match(js, /res\.data\.items/);
		assert.match(js, /res\.data\.users/);
		assert.match(js, /normalizeSearchHits\(raw\)/);
		assert.doesNotMatch(
			js,
			/renderSuggestions\(Array\.isArray\(res\.data\.items\) \? res\.data\.items : \[\], null\)/,
		);
	});

	it('shows searching status and busy state while fetching', () => {
		assert.match(js, /seatSearchLoading/);
		assert.match(js, /Searching…/);
		assert.match(js, /aria-busy/);
	});

	it('filters already-seated hits via hasSeat and isAlreadySeated', () => {
		assert.match(js, /!it\.hasSeat && !isAlreadySeated\(it\.id\)/);
		assert.match(js, /userId: item\.id/);
	});

	it('extracts and evaluates normalizeSearchHits against fixtures', () => {
		const start = js.indexOf('function normalizeSearchHits');
		const end = js.indexOf('function assignSeatFromSearch', start);
		assert.ok(start > 0 && end > start);
		// Provide seatRows + isAlreadySeated for the extracted function scope.
		const fnSrc =
			'var seatRows = [{ uid: "bob" }];\n' +
			'function isAlreadySeated(uid) { return seatRows.some(function (s) { return s.uid === uid; }); }\n' +
			js.slice(start, end) +
			'\nreturn normalizeSearchHits;';
		const normalize = new Function(fnSrc)();
		const out = normalize([
			{ id: 'alice', displayName: 'Alice' },
			{ users: 'ignore' },
			{ id: 'bob', displayName: 'Bob', hasSeat: false },
			{ id: 'ghost', displayName: 'Ghost', enabled: false },
			{ uid: 'cara', displayName: 'Cara' },
			{ id: 'alice', displayName: 'Alice Dup' },
		]);
		assert.deepEqual(
			out.map((r) => ({ id: r.id, hasSeat: r.hasSeat })),
			[
				{ id: 'alice', hasSeat: false },
				{ id: 'bob', hasSeat: true },
				{ id: 'cara', hasSeat: false },
			],
		);
	});
});
