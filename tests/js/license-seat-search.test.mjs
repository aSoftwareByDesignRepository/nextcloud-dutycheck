/**
 * DutyCheck license seat search — client contract on the shared license page
 * (js/common/license-page.js, synced from _shared/license-page).
 * Run: node --test tests/js/license-seat-search.test.mjs
 */
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, it } from 'node:test';

const appRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const js = readFileSync(path.join(appRoot, 'js/common/license-page.js'), 'utf8');

describe('DutyCheck license seat search client', () => {
	it('wires search to the tolerant normalizeUsers (users/items/data, not a single shape)', () => {
		assert.match(js, /function normalizeUsers/);
		// accepts {users}, {items}, and bare-data envelopes from per-app endpoints
		assert.match(js, /data\.users[\s\S]{0,400}data\.items|data\.items[\s\S]{0,400}data\.users/);
		assert.match(js, /normalizeUsers\(data\)/);
	});

	it('shows searching status and busy state while fetching', () => {
		assert.match(js, /Searching…/);
		assert.match(js, /aria-busy/);
		assert.match(js, /aria-expanded/);
	});

	it('debounces, aborts the in-flight search, and filters seated hits', () => {
		assert.match(js, /new AbortController/);
		assert.match(js, /signal: searchAbort\.signal/);
		assert.match(js, /u\.hasSeat === true \|\| assignedUids\.has\(uid\)/);
		assert.match(js, /encodeURIComponent\(q\)/);
	});

	it('extracts and evaluates normalizeUsers against fixtures', () => {
		const start = js.indexOf('function normalizeUsers');
		assert.ok(start > 0);
		// Slice the whole function by brace matching so nested blocks survive.
		let depth = 0;
		let end = -1;
		const bodyStart = js.indexOf('{', start);
		for (let i = bodyStart; i < js.length; i++) {
			if (js[i] === '{') depth++;
			else if (js[i] === '}') {
				depth--;
				if (depth === 0) { end = i + 1; break; }
			}
		}
		assert.ok(end > bodyStart);
		const normalize = new Function(js.slice(start, end) + '\nreturn normalizeUsers;')();
		const out = normalize({ users: [
			{ id: 'alice', displayName: 'Alice' },
			{ id: 'bob', displayName: 'Bob', hasSeat: true },
			{ uid: 'cara', displayName: 'Cara' },
		] });
		assert.ok(Array.isArray(out));
		assert.ok(out.length >= 2, 'normalizer must return the user rows');
		const fromItems = normalize({ items: [{ id: 'dora', displayName: 'Dora' }] });
		assert.equal(fromItems.length, 1);
	});
});
