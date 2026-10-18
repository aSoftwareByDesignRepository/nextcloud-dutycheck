/**
 * check-dates timezone correctness — dutycheck.
 * Loads the synced canonical module in a vm sandbox; vectors are shared.
 */
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, it } from 'node:test';
import vm from 'node:vm';
import { runTimezoneMatrix } from './check-dates-vectors.mjs';

const root = path.join(path.dirname(fileURLToPath(import.meta.url)), '../..');
const read = (rel) => fs.readFileSync(path.join(root, rel), 'utf8');

let currentTz = 'UTC';

function makeInstance(timezone) {
	currentTz = timezone;
	const attrs = {
		'data-locale': 'en-US',
		'data-language': 'en',
		'data-timezone': currentTz,
		'data-first-day-of-week': '1',
		'data-dc-time-24h': '1',
	};
	const document = {
		getElementById: (id) => (id === 'app-content'
			? { getAttribute: (n) => (Object.prototype.hasOwnProperty.call(attrs, n) ? attrs[n] : '') }
			: null),
		documentElement: { getAttribute: () => '', lang: 'en-US' },
	};
	const sandbox = {
		window: {},
		document,
		Intl,
		Date,
		Number,
		Math,
		String,
		console,
		OC: undefined,
		navigator: { language: 'en' },
	};
	sandbox.window = sandbox;
	vm.runInNewContext(read('js/common/check-dates.js'), sandbox, { filename: 'check-dates.js' });
	return sandbox.CheckDates.create({ prefix: 'dc', naiveMode: 'utc' });
}

runTimezoneMatrix({ assert, describe, it, makeInstance, naiveMode: 'utc' });

describe('dutycheck adapter contract', () => {
	it('dates.js delegates to CheckDates and keeps the DutyCheckDates API', () => {
		const src = read('js/common/dates.js');
		assert.match(src, /window\.CheckDates\.create\(/);
		assert.match(src, /naiveMode: 'utc'/);
		for (const key of [
			'formatDisplayDate', 'formatDisplayDateTime', 'formatWeekday',
			'todayIsoDate', 'formatRosterColumnParts', 'applyLocaleToTemporalInputs',
		]) {
			assert.match(src, new RegExp(key));
		}
	});

	it('check-dates is loaded before common/dates on app pages', () => {
		const src = read('lib/Controller/PageController.php');
		const iShared = src.indexOf("'common/check-dates'");
		const iDates = src.indexOf("'common/dates'");
		assert.ok(iShared > -1 && iDates > -1);
		assert.ok(iShared < iDates, 'check-dates must load before dates');
	});

	it('old conflicting overlay CSS was removed from app.css', () => {
		const src = read('css/app.css');
		assert.doesNotMatch(src, /\.dc-date-display\s*\{/);
		assert.doesNotMatch(src, /webkit-calendar-picker-indicator/);
	});
});
