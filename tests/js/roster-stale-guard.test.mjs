/**
 * stale_response_render_clobber — planner roster generation-guard contract.
 *
 * Every period-scoped loader that paints after an awaited fetch must drop
 * stale responses: loadRoster (rosterViewSeq) gates render/url/signals/
 * coverage/error row/loading-row cleanup; loadRosterSignals and
 * loadCoverageStrip honour the passed isCurrent; loadOpenShiftList carries
 * its own openShiftSeq. Regression net for the class fixed in my-roster
 * (rosterFetchSeq) — the planner surface had the same race.
 */
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, it } from 'node:test';

const root = path.join(path.dirname(fileURLToPath(import.meta.url)), '../..');
const src = fs.readFileSync(path.join(root, 'js/roster.js'), 'utf8');

const loadRosterBody = src.slice(
	src.indexOf('async function loadRoster(periodId)'),
	src.indexOf('function dateOverlapsBlackout'),
);

describe('roster stale-response guards', () => {
	it('declares generation counters', () => {
		assert.match(src, /let rosterViewSeq = 0;/);
		assert.match(src, /let openShiftSeq = 0;/);
	});

	it('loadRoster bumps the generation and gates the success paint', () => {
		assert.match(loadRosterBody, /const seq = \+\+rosterViewSeq;/);
		assert.match(loadRosterBody, /const isCurrent = \(\) => seq === rosterViewSeq;/);
		assert.match(
			loadRosterBody,
			/await Api\.get\('\/apps\/dutycheck\/api\/roster', \{ periodId \}\);\s*if \(!isCurrent\(\)\) return null;/,
			'success path must drop stale responses before render()',
		);
	});

	it('gates the PERIOD_NOT_FOUND fallback retry', () => {
		assert.match(
			loadRosterBody,
			/await Api\.get\('\/apps\/dutycheck\/api\/roster', \{\}\);\s*if \(!isCurrent\(\)\) return null;/,
			'fallback response must also be generation-gated',
		);
	});

	it('gates the error paint and the loading-row cleanup', () => {
		assert.match(loadRosterBody, /catch \(err\) \{\s*if \(!isCurrent\(\)\) return null;/);
		assert.match(loadRosterBody, /catch \(retryErr\) \{\s*if \(!isCurrent\(\)\) return null;/);
		assert.match(loadRosterBody, /finally \{\s*if \(isCurrent\(\)\) C\.clearLoadingRow\(tbody\);/);
	});

	it('forwards isCurrent to the dependent painters', () => {
		assert.match(loadRosterBody, /loadRosterSignals\(response\?\.data \|\| \{\}, isCurrent\)/);
		assert.match(loadRosterBody, /loadCoverageStrip\(response\?\.data\?\.selectedPeriodId, isCurrent\)/);
		assert.match(loadRosterBody, /loadRosterSignals\(fallback\?\.data \|\| \{\}, isCurrent\)/);
		assert.match(loadRosterBody, /loadCoverageStrip\(fallback\?\.data\?\.selectedPeriodId, isCurrent\)/);
	});

	it('loadRosterSignals drops stale state before mutating/painting', () => {
		const body = src.slice(
			src.indexOf('async function loadRosterSignals(data, isCurrent)'),
			src.indexOf('async function loadCoverageStrip'),
		);
		assert.match(
			body,
			/await Api\.get\('\/apps\/dutycheck\/api\/roster\/signals'[\s\S]*?if \(isCurrent && !isCurrent\(\)\) return;/,
		);
	});

	it('loadCoverageStrip drops stale paint and stale hide', () => {
		const body = src.slice(
			src.indexOf('async function loadCoverageStrip(periodId, isCurrent)'),
			src.indexOf('function currentSuggestLocationId'),
		);
		assert.match(
			body,
			/await Api\.get\(`\/apps\/dutycheck\/api\/periods\/\$\{id\}\/publish-readiness`\);\s*if \(isCurrent && !isCurrent\(\)\) return;/,
		);
		assert.match(body, /catch \(_\) \{\s*if \(isCurrent && !isCurrent\(\)\) return;/);
	});

	it('loadOpenShiftList carries its own generation guard', () => {
		const body = src.slice(
			src.indexOf('async function loadOpenShiftList()'),
			src.indexOf('function wireMarketplace'),
		);
		assert.match(body, /const seq = \+\+openShiftSeq;/);
		assert.match(
			body,
			/await Api\.get\('\/apps\/dutycheck\/api\/open-shifts' \+ qs\);\s*if \(seq !== openShiftSeq\) return;/,
		);
		assert.match(body, /catch \(_\) \{\s*if \(seq !== openShiftSeq\) return;/);
	});
});
