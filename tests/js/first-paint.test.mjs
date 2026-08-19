/**
 * App-wide first-paint: CSRF, conflict-labels, dashboard SSR, modal cache,
 * settings section gate, catalog windowing.
 *
 * Run: node --test tests/js/first-paint.test.mjs
 */
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, it } from 'node:test';
import vm from 'node:vm';

const root = path.join(path.dirname(fileURLToPath(import.meta.url)), '../..');
const read = (rel) => fs.readFileSync(path.join(root, rel), 'utf8');

describe('session and page script load', () => {
	it('session.js does not prefetch CSRF on every navigation', () => {
		const src = read('js/common/session.js');
		assert.match(src, /csrfPrefetch:\s*false/);
		assert.doesNotMatch(src, /refreshCsrfToken/);
		assert.doesNotMatch(src, /DOMContentLoaded/);
	});

	it('api.js still refreshes CSRF on 412', () => {
		const src = read('js/common/api.js');
		assert.match(src, /function refreshCsrfToken/);
		assert.match(src, /412/);
	});

	it('conflict-labels load only on dashboard, roster, and periods', () => {
		const src = read('lib/Controller/PageController.php');
		assert.match(
			src,
			/in_array\(\$template, \['dashboard', 'roster', 'periods'\], true\)/,
		);
		assert.match(src, /common\/conflict-labels/);
		assert.doesNotMatch(
			src,
			/addScript\(Application::APP_ID, 'common\/messaging'\);\s*Util::addScript\(Application::APP_ID, 'common\/conflict-labels'\)/,
		);
	});
});

describe('dashboard SSR first paint', () => {
	it('skips GET /api/dashboard when SSR JSON is present', () => {
		const src = read('js/dashboard.js');
		assert.match(src, /function readSsrSummary/);
		assert.match(src, /data-dc-dashboard-summary/);
		assert.match(src, /function applySummaryData/);
		assert.match(src, /if \(ssr\) \{\s*applySummaryData\(ssr\);\s*return Promise\.resolve\(\);/s);
		assert.match(src, /Api\.get\('\/apps\/dutycheck\/api\/dashboard'\)/);
	});

	it('PageController SSRs summary only from dashboardPageExtras', () => {
		const src = read('lib/Controller/PageController.php');
		assert.match(src, /function dashboardPageExtras/);
		assert.match(src, /\$this->dashboardPageExtras\(\)/);
		assert.equal([...src.matchAll(/\$this->roster->dashboardSummary/g)].length, 1);
	});
});

describe('roster assignment modal', () => {
	it('loads templates and planning defaults in parallel and caches templates', () => {
		const src = read('js/roster.js');
		assert.match(src, /async function ensureAssignmentModalPrereqs/);
		assert.match(src, /await Promise\.all\(jobs\)/);
		assert.match(src, /if \(state\.templatesLoaded\)/);
		assert.match(src, /if \(state\.planningDefaultsFresh !== true\)/);
		assert.match(src, /await ensureAssignmentModalPrereqs\(\)/);
		assert.doesNotMatch(
			src,
			/await refreshPlanningDefaultFromServer\(\);\s*await loadTemplates\(\);/,
		);
	});
});

describe('settings section gate', () => {
	it('wires only the active settings section', () => {
		const src = read('js/settings.js');
		assert.match(src, /const SECTION_WIRES/);
		assert.match(src, /function wireActiveSettingsSection/);
		assert.match(src, /SECTION_WIRES\[section\]/);
		assert.doesNotMatch(src, /Object\.values\(SECTION_WIRES\)/);
		const boot = src.slice(src.indexOf('DOMContentLoaded'));
		assert.match(boot, /await wireActiveSettingsSection\(\)/);
		assert.doesNotMatch(boot, /await wireCompanies\(\)/);
		assert.doesNotMatch(boot, /await wireAtIntegration\(\)/);
	});
});

describe('catalog windowed tables', () => {
	it('employees, locations, and absences bind DutyCheckWindowedTable', () => {
		for (const file of ['js/employees.js', 'js/locations.js', 'js/absences.js']) {
			const src = read(file);
			assert.match(src, /DutyCheckWindowedTable/);
			assert.match(src, /\.bind\(/);
			assert.match(src, /dc-virtual-spacer|statusWindow/);
			assert.match(src, /All \{total\} rows are on screen\./);
		}
	});

	it('windows a 200-row catalog instead of painting every tr', () => {
		const sandbox = {
			window: {},
			globalThis: null,
			module: { exports: {} },
			console,
			Math,
			Number,
			String,
			Array,
			document: {
				createElement() {
					return {
						style: {},
						className: '',
						appendChild() {},
						setAttribute() {},
					};
				},
				createDocumentFragment() {
					const _nodes = [];
					return {
						appendChild(node) {
							_nodes.push(node);
						},
						_nodes,
					};
				},
			},
			addEventListener() {},
			removeEventListener() {},
			requestAnimationFrame(cb) {
				return setTimeout(cb, 0);
			},
		};
		sandbox.globalThis = sandbox;
		sandbox.window = sandbox;
		vm.runInNewContext(read('js/common/virtual-window.js'), sandbox, { filename: 'virtual-window.js' });
		vm.runInNewContext(read('js/common/windowed-table.js'), sandbox, { filename: 'windowed-table.js' });
		const WT = sandbox.DutyCheckWindowedTable;
		assert.equal(typeof WT.bind, 'function');
		assert.equal(WT.WINDOW_THRESHOLD, 32);

		const rows = Array.from({ length: 200 }, (_, i) => ({ id: i + 1 }));
		let painted = [];
		const tbody = {
			replaceChildren(frag) {
				painted = frag && Array.isArray(frag._nodes) ? [...frag._nodes] : [];
			},
			appendChild() {},
			querySelector() {
				return { getBoundingClientRect() { return { height: 44 }; } };
			},
		};
		const scroller = {
			clientHeight: 220,
			scrollTop: 0,
			addEventListener() {},
			removeEventListener() {},
		};
		const painter = WT.bind({
			tbody,
			scroller,
			createElement(tag, props) {
				return {
					tag,
					kind: props && props.class === 'dc-virtual-spacer' ? 'spacer' : 'row',
					className: props && props.class ? props.class : '',
					appendChild() {},
				};
			},
			getRows: () => rows,
			renderRow(row) {
				return { kind: 'row', id: row.id };
			},
			colSpan: 4,
			windowThreshold: 32,
		});
		painter.paint(true);
		const dataRows = painted.filter((n) => n && n.kind === 'row');
		assert.ok(dataRows.length >= 5, 'must paint the visible slice');
		assert.ok(dataRows.length < 80, 'must not paint all 200 rows');
		assert.ok(painted.some((n) => n && n.kind === 'spacer'), 'must keep virtual spacers');
	});
});
