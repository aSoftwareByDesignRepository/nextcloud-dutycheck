/**
 * Catalog chrome must stay translated: windowed-table / roster status lines
 * and “opens in a new tab” must not ship as English identity leftovers.
 *
 * Run: node --test tests/js/l10n-catalog.test.mjs
 */
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, it } from 'node:test';

const root = path.join(path.dirname(fileURLToPath(import.meta.url)), '../..');
const l10nDir = path.join(root, 'l10n');

const MUST_TRANSLATE = [
	'(opens in a new tab)',
	'All {total} rows are on screen.',
	'All {total} people are on screen.',
	'All {total} shifts are on screen.',
	'Showing rows {from}–{to} of {total}. Scroll to see the rest.',
	'Showing people {from}–{to} of {total}. Scroll to see everyone.',
	'Showing shifts {from}–{to} of {total}. Scroll to see everyone.',
];

const BASE = ['de', 'fr', 'es', 'da', 'nl', 'it', 'pl', 'sv', 'nb', 'pt_BR'];

function translations(lang) {
	const data = JSON.parse(fs.readFileSync(path.join(l10nDir, `${lang}.json`), 'utf8'));
	assert.equal(typeof data.translations, 'object');
	return data.translations;
}

describe('l10n visible chrome', () => {
	it('window and new-tab strings are translated in every shipped locale', () => {
		const leftovers = [];
		for (const lang of BASE) {
			const tr = translations(lang);
			for (const msgid of MUST_TRANSLATE) {
				assert.ok(msgid in tr, `${lang} missing ${msgid}`);
				if (msgid.includes('{total}')) {
					assert.match(tr[msgid], /\{total\}/, `${lang} dropped {total} in ${msgid}`);
				}
				if (tr[msgid] === msgid) {
					leftovers.push(`${lang}: ${msgid}`);
				}
			}
			assert.match(tr['Showing rows {from}–{to} of {total}. Scroll to see the rest.'], /\{from\}/);
			assert.match(tr['Showing rows {from}–{to} of {total}. Scroll to see the rest.'], /\{to\}/);
		}
		assert.deepEqual(leftovers, []);
	});

	it('employees/locations/absences and roster use the catalog msgids', () => {
		const employees = fs.readFileSync(path.join(root, 'js/employees.js'), 'utf8');
		const roster = fs.readFileSync(path.join(root, 'js/roster.js'), 'utf8');
		assert.match(employees, /All \{total\} rows are on screen\./);
		assert.match(employees, /Showing rows \{from\}–\{to\} of \{total\}\. Scroll to see the rest\./);
		assert.match(roster, /All \{total\} people are on screen\./);
		assert.match(roster, /All \{total\} shifts are on screen\./);
		assert.match(roster, /Showing people \{from\}–\{to\} of \{total\}\. Scroll to see everyone\./);
		assert.match(roster, /Showing shifts \{from\}–\{to\} of \{total\}\. Scroll to see everyone\./);
	});
});
