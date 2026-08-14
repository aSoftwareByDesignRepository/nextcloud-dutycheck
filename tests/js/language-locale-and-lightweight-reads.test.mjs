/**
 * Language vs locale, and lightweight reads (no full roster on Periods/Dashboard/Absences).
 */
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, it } from 'node:test';
import vm from 'node:vm';

const root = path.join(path.dirname(fileURLToPath(import.meta.url)), '../..');
const read = (rel) => fs.readFileSync(path.join(root, rel), 'utf8');

describe('lightweight list endpoints', () => {
	it('periods.js loads GET /api/periods instead of the full roster', () => {
		const src = read('js/periods.js');
		assert.match(src, /Api\.get\('\/apps\/dutycheck\/api\/periods'\)/);
		assert.doesNotMatch(src, /Api\.get\('\/apps\/dutycheck\/api\/roster'\)/);
	});

	it('dashboard pulse uses periods + publish-readiness, not rosterData', () => {
		const src = read('js/dashboard.js');
		assert.match(src, /Api\.get\('\/apps\/dutycheck\/api\/periods'\)/);
		assert.match(src, /publish-readiness/);
		assert.match(src, /data\.readiness/);
		assert.doesNotMatch(src, /Api\.get\('\/apps\/dutycheck\/api\/roster'\)/);
	});

	it('absences.js loads the employee catalog, not the full roster', () => {
		const src = read('js/absences.js');
		assert.match(src, /Api\.get\('\/apps\/dutycheck\/api\/employees'\)/);
		assert.doesNotMatch(src, /Api\.get\('\/apps\/dutycheck\/api\/roster'\)/);
	});

	it('routes register GET /api/periods separately from POST create', () => {
		const src = read('appinfo/routes.php');
		assert.match(src, /rosterApi#listPeriods['"]\s*,\s*'url'\s*=>\s*'\/api\/periods'/);
		assert.match(src, /'verb'\s*=>\s*'GET'/);
		assert.match(src, /rosterApi#createPeriod['"]\s*,\s*'url'\s*=>\s*'\/api\/periods'/);
	});
});

describe('dates.js language vs locale', () => {
	const src = read('js/common/dates.js');

	it('formats calendar dates with locale and relative time with language', () => {
		assert.match(src, /Intl\.DateTimeFormat\(currentLocale\(\)/);
		assert.match(src, /Intl\.RelativeTimeFormat\(currentLanguage\(\)/);
		assert.doesNotMatch(src, /Intl\.RelativeTimeFormat\(currentLocale\(\)/);
		assert.match(src, /data-language/);
		assert.match(src, /data-first-day-of-week/);
		assert.match(src, /OC\.getLanguage/);
	});

	it('never treats OC.getLocale as the UI language helper', () => {
		assert.match(src, /function currentLanguage\s*\(/);
		assert.match(src, /function currentFirstDayOfWeek\s*\(/);
		const languageFn = src.slice(src.indexOf('function currentLanguage'), src.indexOf('function currentFirstDayOfWeek'));
		assert.doesNotMatch(languageFn, /OC\.getLocale/);
	});

	function load(attrs) {
		const document = {
			getElementById: (id) => (id === 'app-content'
				? {
					getAttribute: (name) => (Object.prototype.hasOwnProperty.call(attrs, name) ? attrs[name] : ''),
				}
				: null),
			documentElement: { getAttribute: () => '' },
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
		vm.runInNewContext(src, sandbox, { filename: 'dates.js' });
		return sandbox.window.DutyCheckDates;
	}

	it('keeps Dutch locale dates while English relative language', () => {
		const d = load({
			'data-locale': 'nl-NL',
			'data-language': 'en',
			lang: 'en-US',
			'data-first-day-of-week': '1',
			'data-timezone': 'UTC',
			'data-dc-time-24h': '1',
		});
		assert.equal(d.currentLocale(), 'nl-NL');
		assert.equal(d.currentLanguage(), 'en');
		assert.equal(d.currentFirstDayOfWeek(), 1);
		const dateOut = d.formatDisplayDate('2026-08-14T12:00:00Z');
		assert.match(dateOut, /14/);
		assert.match(dateOut, /8|08|aug/i);
		const rel = d.formatRelativeMinutes(-5);
		assert.match(rel, /minute|minutes|ago|in /i);
		assert.doesNotMatch(rel, /minuut|minuten|geleden/i);
	});

	it('rejects out-of-range first-day values and falls back', () => {
		const d = load({
			'data-locale': 'de-DE',
			'data-language': 'en',
			'data-first-day-of-week': '9',
			'data-timezone': 'UTC',
		});
		assert.equal(d.currentFirstDayOfWeek(), 1);
	});

	it('names weekdays in the UI language, not the date locale', () => {
		const src = read('js/common/dates.js');
		assert.match(src, /function formatWeekday\s*\(/);
		assert.match(src, /Intl\.DateTimeFormat\(currentLanguage\(\)/);
		const roster = read('js/my-roster.js');
		assert.match(roster, /D\?\.formatWeekday|currentLanguage/);
		assert.doesNotMatch(roster, /DateTimeFormat\(D\?\.currentLocale/);

		const d = load({
			'data-locale': 'nl-NL',
			'data-language': 'en',
			lang: 'en-US',
			'data-first-day-of-week': '1',
			'data-timezone': 'UTC',
		});
		const label = d.formatWeekday('2026-08-17');
		assert.match(label, /monday/i);
		assert.doesNotMatch(label, /maandag/i);
	});
});

describe('publish readiness is a read of persisted conflicts', () => {
	it('does not recompute on GET', () => {
		const src = read('lib/Service/RosterService.php');
		const fnStart = src.indexOf('public function publishReadiness');
		assert.ok(fnStart > 0);
		const fn = src.slice(fnStart, src.indexOf('private function computePublishReadinessFromConflicts', fnStart));
		assert.match(fn, /listPersistedConflicts/);
		assert.doesNotMatch(fn, /refreshAndListConflicts/);
	});
});

describe('roster GET is a read of persisted conflicts', () => {
	it('does not recompute on rosterData', () => {
		const src = read('lib/Service/RosterService.php');
		const fnStart = src.indexOf('public function rosterData');
		assert.ok(fnStart > 0);
		const fnEnd = src.indexOf('Prefer the newest open period', fnStart);
		assert.ok(fnEnd > fnStart);
		const fn = src.slice(fnStart, fnEnd);
		assert.match(fn, /listPersistedConflicts/);
		assert.doesNotMatch(fn, /refreshAndListConflicts/);
	});
});
