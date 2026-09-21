/**
 * Conflict open-status callout view-model + settings wiring contracts.
 * Run: node --test tests/js/conflict-thresholds.test.mjs
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';

const root = path.join(path.dirname(fileURLToPath(import.meta.url)), '../..');
const settingsJs = fs.readFileSync(path.join(root, 'js/settings.js'), 'utf8');
const conflictsTpl = fs.readFileSync(path.join(root, 'templates/parts/settings/conflicts.php'), 'utf8');
const openStatusJs = fs.readFileSync(path.join(root, 'js/common/conflict-open-status.js'), 'utf8');
const pageController = fs.readFileSync(path.join(root, 'lib/Controller/PageController.php'), 'utf8');
const dashboardJs = fs.readFileSync(path.join(root, 'js/dashboard.js'), 'utf8');
const dashboardTpl = fs.readFileSync(path.join(root, 'templates/dashboard.php'), 'utf8');

function loadResolver() {
	const sandbox = { window: {}, console };
	vm.runInNewContext(openStatusJs, sandbox, { filename: 'conflict-open-status.js' });
	return sandbox.window.DutyCheckConflictOpenStatus.resolveConflictOpenCallout;
}

const resolve = loadResolver();
const identityT = (_app, text) => text;

test('settings wires apply-open, open-status module, and minute→hour hints', () => {
	assert.match(settingsJs, /conflict-policy\/apply-open/);
	assert.match(settingsJs, /About \{hours\} hours/);
	assert.match(settingsJs, /renderOpenPeriodStatus/);
	assert.match(settingsJs, /DutyCheckConflictOpenStatus/);
	assert.match(settingsJs, /Conflict thresholds saved\. Apply them to open periods below/);
	assert.match(settingsJs, /Planning checks for open periods were recalculated/);
	assert.match(settingsJs, /conflictsRefreshed/);
	assert.match(settingsJs, /conflictsDirtyRemaining/);
	assert.match(settingsJs, /will finish in the background/);
	assert.match(settingsJs, /form\.maxPeriodHard\.value = String\(d\.maxPeriodHard\)/);
	assert.match(settingsJs, /Hard period cap must be at least the soft period cap/);
	assert.match(conflictsTpl, /dc-conflict-apply-open/);
	assert.match(conflictsTpl, /dc-conflict-apply-actions/);
	assert.match(conflictsTpl, /data-dc-minutes-hint/);
	assert.match(conflictsTpl, /Checking open periods/);
	assert.match(conflictsTpl, /max="20160"/);
	assert.match(conflictsTpl, /max="30240"/);
	assert.match(pageController, /common\/conflict-open-status/);
});

test('apply CTA starts hidden — no dead primary button on first paint', () => {
	assert.match(conflictsTpl, /id="dc-conflict-apply-actions" hidden/);
	assert.match(conflictsTpl, /id="dc-conflict-apply-open"[^>]*hidden/);
	assert.match(settingsJs, /applyBtn\.hidden = !view\.showApply/);
	assert.match(settingsJs, /applyActions\.hidden = !view\.showApply/);
});

test('synced open periods: success tone, Apply hidden', () => {
	const view = resolve({ schemaReady: true, openCount: 2, outdatedCount: 0 }, identityT);
	assert.equal(view.tone, 'success');
	assert.equal(view.showApply, false);
	assert.equal(view.applyEnabled, false);
	assert.match(view.title, /already match/i);
	assert.match(view.body, /Nothing to apply/i);
	assert.match(view.body, /2/);
});

test('outdated open periods: warning tone, Apply shown', () => {
	const view = resolve({ schemaReady: true, openCount: 3, outdatedCount: 2 }, identityT);
	assert.equal(view.tone, 'warning');
	assert.equal(view.showApply, true);
	assert.equal(view.applyEnabled, true);
	assert.match(view.title, /older limits/i);
	assert.match(view.body, /2 of 3/);
});

test('no open periods: info tone, Apply hidden', () => {
	const view = resolve({ schemaReady: true, openCount: 0, outdatedCount: 0 }, identityT);
	assert.equal(view.tone, 'info');
	assert.equal(view.showApply, false);
	assert.match(view.title, /No open periods/i);
});

test('schema not ready: warning, Apply hidden', () => {
	const view = resolve({ schemaReady: false, openCount: 5, outdatedCount: 5 }, identityT);
	assert.equal(view.tone, 'warning');
	assert.equal(view.showApply, false);
	assert.match(view.title, /Upgrade still running/i);
});

test('resolver uses translate callback', () => {
	const view = resolve({ schemaReady: true, openCount: 1, outdatedCount: 0 }, (app, text) => {
		assert.equal(app, 'dutycheck');
		return `DE:${text}`;
	});
	assert.match(view.title, /^DE:/);
});

test('dashboard surfaces cap freeze hint for hour-cap hard conflicts', () => {
	assert.match(dashboardJs, /period_total_hard_cap/);
	assert.match(dashboardJs, /weekly_hours_hard_cap/);
	assert.match(dashboardJs, /dc-dashboard-cap-hint/);
	assert.match(dashboardTpl, /id="dc-dashboard-cap-hint"/);
	assert.match(dashboardTpl, /Open Conflict thresholds/);
});
