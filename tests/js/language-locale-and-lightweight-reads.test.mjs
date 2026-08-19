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

	it('periods.js paints the list without awaiting period details', () => {
		const src = read('js/periods.js');
		const start = src.indexOf('async function loadPeriods');
		assert.ok(start > 0);
		const end = src.indexOf('function transitionErrorMessage', start);
		assert.ok(end > start);
		const fn = src.slice(start, end);
		assert.match(fn, /void loadPeriodDetails\(currentPeriodId\)/);
		assert.doesNotMatch(fn, /await loadPeriodDetails/);
		assert.match(src, /AbortController/);
		assert.match(src, /setLoadingRow/);
		assert.doesNotMatch(
			src.slice(src.indexOf('DOMContentLoaded')),
			/setBusy\(true\);\s*await loadPeriods\(\)/,
		);
	});

	it('dashboard pulse uses dashboard summary, not extra period GETs', () => {
		const src = read('js/dashboard.js');
		assert.match(src, /Api\.get\('\/apps\/dutycheck\/api\/dashboard'\)/);
		assert.match(src, /payload\.pulse/);
		assert.match(src, /renderPulseFromSummary/);
		assert.match(src, /readSsrSummary/);
		assert.match(src, /applySummaryData\(ssr\)/);
		assert.doesNotMatch(src, /Api\.get\('\/apps\/dutycheck\/api\/periods'\)/);
		assert.doesNotMatch(src, /publish-readiness/);
		assert.doesNotMatch(src, /Api\.get\('\/apps\/dutycheck\/api\/roster'\)/);
	});

	it('bootstrap does not hydrate the planner roster catalog', () => {
		const src = read('lib/Controller/ApiController.php');
		const start = src.indexOf('public function bootstrap');
		assert.ok(start > 0);
		const end = src.indexOf('public function', start + 10);
		const fn = end > start ? src.slice(start, end) : src.slice(start);
		assert.match(fn, /catalog/);
		assert.doesNotMatch(fn, /dashboardSummary\(/);
		assert.doesNotMatch(fn, /rosterData\(/);
		assert.doesNotMatch(fn, /listAbsences\(/);
		assert.doesNotMatch(fn, /myRoster\(/);
		assert.doesNotMatch(fn, /myAbsences\(/);
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

describe('publish readiness is a SQL count of persisted conflicts', () => {
	it('does not recompute or hydrate payloads on GET', () => {
		const src = read('lib/Service/RosterService.php');
		const fnStart = src.indexOf('public function publishReadiness');
		assert.ok(fnStart > 0);
		const fn = src.slice(fnStart, src.indexOf('private function computePublishReadinessFromConflicts', fnStart));
		assert.match(fn, /countUnresolvedConflictsBySeverity/);
		assert.match(fn, /countUnacknowledgedSoftConflicts/);
		assert.doesNotMatch(fn, /listPersistedConflicts/);
		assert.doesNotMatch(fn, /refreshAndListConflicts/);
		assert.doesNotMatch(fn, /listAssignments/);
		assert.doesNotMatch(fn, /conflictsForPeriod/);
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

	it('loads the full assignment list for the grid (never paginated)', () => {
		const src = read('lib/Service/RosterService.php');
		const rosterFn = src.slice(
			src.indexOf('public function rosterData'),
			src.indexOf('Prefer the newest open period'),
		);
		assert.match(rosterFn, /listAssignments/);
		const listStart = src.indexOf('private function listAssignments');
		assert.ok(listStart > 0);
		const docStart = src.lastIndexOf('/**', listStart);
		assert.ok(docStart > 0 && docStart < listStart);
		const listEnd = src.indexOf('private function assignmentHasStatusColumn', listStart);
		const block = src.slice(docStart, listEnd);
		assert.match(block, /Never paginated/);
		assert.doesNotMatch(block, /setMaxResults/);
	});
});

describe('app-wide load: no extra round trips on first paint', () => {
	it('roster.js computes ack stats from loaded assignments', () => {
		const src = read('js/roster.js');
		const start = src.indexOf('function refreshAcknowledgeStats');
		assert.ok(start > 0);
		const fn = src.slice(start, src.indexOf('function fillCopySourceSelect', start));
		assert.doesNotMatch(fn, /Api\.get/);
		assert.doesNotMatch(fn, /acknowledge-stats/);
		assert.match(fn, /toLowerCase\(\) === 'cancelled'/);
		assert.match(fn, /acknowledgedAt/);
		assert.match(fn, /Math\.round\(\(acked \/ total\) \* 1000\) \/ 10/);
	});

	it('roster.js loads roster, swaps, and claims in parallel', () => {
		const src = read('js/roster.js');
		assert.match(src, /Promise\.all\(\[\s*loadRoster\(selectedPeriodIdFromUrl\(\)\),\s*loadPendingSwaps\(\),\s*loadPendingOpenClaims\(\),\s*\]\)/);
		assert.match(src, /Promise\.all\(\[\s*loadRoster\(Number\.isInteger\(periodId\) && periodId > 0 \? periodId : null\),\s*loadPendingSwaps\(\),\s*loadPendingOpenClaims\(\),\s*\]\)/);
	});

	it('absences.js fetches employees and absences together', () => {
		const src = read('js/absences.js');
		const start = src.indexOf('async function loadContext');
		assert.ok(start > 0);
		const fn = src.slice(start, src.indexOf('async function transitionAbsence', start));
		assert.match(fn, /Promise\.all\(\[/);
		assert.match(fn, /Api\.get\('\/apps\/dutycheck\/api\/employees'\)/);
		assert.match(fn, /Api\.get\('\/apps\/dutycheck\/api\/absences'\)/);
		assert.doesNotMatch(fn, /const employeesResponse = await Api\.get\('\/apps\/dutycheck\/api\/employees'\)/);
	});

	it('my-roster.js loads roster, open shifts, and calendar meta together', () => {
		const src = read('js/my-roster.js');
		assert.match(src, /Promise\.all\(\[\s*fetchAndRender\(\),\s*loadOpenShifts\(\),\s*loadIcalMeta\(\),\s*\]\)/);
	});

	it('GET persisted conflicts cap assignment ids and drop details', () => {
		const src = read('lib/Service/RosterService.php');
		const start = src.indexOf('private function listPersistedConflicts');
		assert.ok(start > 0);
		const fn = src.slice(start, src.indexOf('private static function conflictAckState', start));
		assert.match(fn, /count\(\$assignmentIds\) >= 2/);
		assert.match(fn, /'details' => \[\]/);
		assert.doesNotMatch(fn, /\$payload\['details'\]/);
	});
});
