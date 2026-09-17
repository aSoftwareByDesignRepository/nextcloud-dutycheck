/**
 * Contract: settings conflict policy wiring must keep freeze/apply UX.
 * Run: node --test tests/js/conflict-thresholds.test.mjs
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.join(path.dirname(fileURLToPath(import.meta.url)), '../..');
const settingsJs = fs.readFileSync(path.join(root, 'js/settings.js'), 'utf8');
const conflictsTpl = fs.readFileSync(path.join(root, 'templates/parts/settings/conflicts.php'), 'utf8');
const dashboardJs = fs.readFileSync(path.join(root, 'js/dashboard.js'), 'utf8');
const dashboardTpl = fs.readFileSync(path.join(root, 'templates/dashboard.php'), 'utf8');

test('settings wires apply-open and minute→hour hints', () => {
	assert.match(settingsJs, /conflict-policy\/apply-open/);
	assert.match(settingsJs, /About \{hours\} hours/);
	assert.match(settingsJs, /renderOpenPeriodStatus/);
	assert.match(conflictsTpl, /dc-conflict-apply-open/);
	assert.match(conflictsTpl, /data-dc-minutes-hint/);
	assert.match(conflictsTpl, /Open periods keep the caps from when they were created/);
});

test('dashboard surfaces cap freeze hint for hour-cap hard conflicts', () => {
	assert.match(dashboardJs, /period_total_hard_cap/);
	assert.match(dashboardJs, /weekly_hours_hard_cap/);
	assert.match(dashboardJs, /dc-dashboard-cap-hint/);
	assert.match(dashboardTpl, /id="dc-dashboard-cap-hint"/);
	assert.match(dashboardTpl, /Open Conflict thresholds/);
});
