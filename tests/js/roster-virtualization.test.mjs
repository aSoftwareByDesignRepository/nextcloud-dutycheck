/**
 * Roster DOM virtualization: window math plus source contracts.
 * GET still returns every assignment (SF-06); only the painted DOM is windowed.
 */
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, it } from 'node:test';
import vm from 'node:vm';

const root = path.join(path.dirname(fileURLToPath(import.meta.url)), '../..');
const read = (rel) => fs.readFileSync(path.join(root, rel), 'utf8');

function loadVirtualWindow() {
	vm.runInThisContext(read('js/common/virtual-window.js'));
	const api = globalThis.DutyCheckVirtualWindow;
	assert.equal(typeof api.visibleRange, 'function');
	return api;
}

describe('DutyCheckVirtualWindow.visibleRange', () => {
	const VW = loadVirtualWindow();

	it('returns an empty window for total 0', () => {
		const r = VW.visibleRange({ total: 0, rowHeight: 44, viewportHeight: 400, scrollTop: 80 });
		assert.deepEqual(r, {
			start: 0,
			end: 0,
			padBefore: 0,
			padAfter: 0,
			totalHeight: 0,
			rowHeight: 44,
		});
	});

	it('fails closed on NaN / negative inputs', () => {
		const r = VW.visibleRange({
			total: Number.NaN,
			rowHeight: -10,
			viewportHeight: -5,
			scrollTop: -90,
			overscan: -3,
		});
		assert.equal(r.start, 0);
		assert.equal(r.end, 0);
		assert.equal(r.rowHeight, VW.DEFAULT_ROW_HEIGHT);
	});

	it('applies overscan around the visible slice', () => {
		const r = VW.visibleRange({
			total: 100,
			rowHeight: 10,
			viewportHeight: 50,
			scrollTop: 200,
			overscan: 6,
		});
		assert.equal(r.start, 14);
		assert.equal(r.end, 31);
		assert.equal(r.padBefore, 14 * 10);
		assert.equal(r.padAfter, (100 - 31) * 10);
		assert.equal(r.padBefore + (r.end - r.start) * 10 + r.padAfter, r.totalHeight);
	});

	it('clamps the last row when scrolled past the end', () => {
		const r = VW.visibleRange({
			total: 20,
			rowHeight: 10,
			viewportHeight: 40,
			scrollTop: 5000,
			overscan: 2,
		});
		assert.ok(r.start >= 0);
		assert.equal(r.end, 20);
		assert.ok(r.start < r.end);
	});

	it('paints a fallback window when the viewport is not laid out yet', () => {
		const r = VW.visibleRange({
			total: 200,
			rowHeight: 44,
			viewportHeight: 0,
			scrollTop: 0,
			overscan: VW.DEFAULT_OVERSCAN,
		});
		assert.equal(r.start, 0);
		assert.equal(r.end, VW.UNSIZED_WINDOW_ROWS + VW.DEFAULT_OVERSCAN);
		assert.ok(r.end < 200);
	});

	it('honors scrollTop while the viewport is still unsized (conflict reveal)', () => {
		const r = VW.visibleRange({
			total: 200,
			rowHeight: 44,
			viewportHeight: 0,
			scrollTop: 44 * 100,
			overscan: VW.DEFAULT_OVERSCAN,
		});
		assert.equal(r.start, 100 - VW.DEFAULT_OVERSCAN);
		assert.equal(r.end, 100 + VW.UNSIZED_WINDOW_ROWS + VW.DEFAULT_OVERSCAN);
		assert.ok(r.start > 0);
		assert.ok(r.end < 200);
	});

	it('paintAll returns the full range regardless of scroll', () => {
		const r = VW.visibleRange({
			total: 200,
			rowHeight: 44,
			viewportHeight: 100,
			scrollTop: 800,
			overscan: 6,
			paintAll: true,
		});
		assert.equal(r.start, 0);
		assert.equal(r.end, 200);
		assert.equal(r.padBefore, 0);
		assert.equal(r.padAfter, 0);
	});

	it('never paints more rows than total even with huge overscan', () => {
		const r = VW.visibleRange({
			total: 3,
			rowHeight: 44,
			viewportHeight: 800,
			scrollTop: 0,
			overscan: 50,
		});
		assert.equal(r.start, 0);
		assert.equal(r.end, 3);
	});

	it('keeps the painted window bounded as the roster grows', () => {
		const r = VW.visibleRange({
			total: 5000,
			rowHeight: 44,
			viewportHeight: 400,
			scrollTop: 88000,
			overscan: 6,
		});
		assert.ok(r.end - r.start <= 24, `window ${r.end - r.start} must stay small`);
		assert.equal(r.totalHeight, 5000 * 44);
		assert.ok(r.start > 0);
		assert.ok(r.end < 5000);
	});
});

describe('DutyCheckVirtualWindow.scrollTopToRevealIndex', () => {
	const VW = loadVirtualWindow();

	it('scrolls up when the row sits above the viewport', () => {
		assert.equal(VW.scrollTopToRevealIndex({
			index: 2,
			rowHeight: 10,
			viewportHeight: 40,
			scrollTop: 50,
			total: 20,
		}), 20);
	});

	it('scrolls down when the row sits below the viewport', () => {
		assert.equal(VW.scrollTopToRevealIndex({
			index: 10,
			rowHeight: 10,
			viewportHeight: 40,
			scrollTop: 0,
			total: 20,
		}), 70);
	});

	it('leaves scrollTop unchanged when the row is already visible', () => {
		assert.equal(VW.scrollTopToRevealIndex({
			index: 3,
			rowHeight: 10,
			viewportHeight: 40,
			scrollTop: 20,
			total: 20,
		}), 20);
	});

	it('exposes the same helper as scrollOffsetToRevealIndex for horizontal reveal', () => {
		assert.equal(VW.scrollOffsetToRevealIndex, VW.scrollTopToRevealIndex);
		assert.equal(VW.scrollOffsetToRevealIndex({
			index: 10,
			rowHeight: 80,
			viewportHeight: 200,
			scrollTop: 0,
			total: 31,
		}), 680);
	});
});

describe('DutyCheckVirtualWindow.pageStride', () => {
	const VW = loadVirtualWindow();

	it('pages by almost a full viewport so the previous row stays as context', () => {
		assert.equal(VW.pageStride({ rowHeight: 40, viewportHeight: 400 }), 9);
	});

	it('never returns a zero stride', () => {
		assert.equal(VW.pageStride({ rowHeight: 44, viewportHeight: 44 }), 1);
		assert.equal(VW.pageStride({ rowHeight: Number.NaN, viewportHeight: -1 }), 1);
	});
});

describe('DutyCheckVirtualWindow.windowCaption', () => {
	const VW = loadVirtualWindow();

	it('uses 1-based inclusive bounds for a partial window', () => {
		assert.deepEqual(
			VW.windowCaption({ start: 14, end: 31, total: 100 }),
			{ mode: 'window', from: 15, to: 31, total: 100 },
		);
	});

	it('reports all when the window covers the list', () => {
		assert.deepEqual(
			VW.windowCaption({ start: 0, end: 12, total: 12 }),
			{ mode: 'all', from: 1, to: 12, total: 12 },
		);
	});

	it('reports empty for an empty list', () => {
		assert.deepEqual(
			VW.windowCaption({ start: 0, end: 0, total: 0 }),
			{ mode: 'empty', from: 0, to: 0, total: 0 },
		);
	});
});

describe('roster.js virtualizes grid and list without paginating the API', () => {
	const src = read('js/roster.js');
	const page = read('lib/Controller/PageController.php');
	const tpl = read('templates/roster.php');
	const css = read('css/app.css');
	const rosterService = read('lib/Service/RosterService.php');

	it('loads the virtual-window helper before roster.js', () => {
		const vw = page.indexOf("addScript(Application::APP_ID, 'common/virtual-window')");
		const roster = page.indexOf("Util::addScript(Application::APP_ID, $script)");
		assert.ok(vw > 0);
		assert.ok(page.includes("if ($template === 'roster')"));
		assert.ok(vw < roster, 'virtual-window must load before the page script');
	});

	it('windows employee rows and assignment rows', () => {
		assert.match(src, /DutyCheckVirtualWindow/);
		assert.match(src, /VIRTUAL_FALLBACK/);
		assert.match(src, /end:\s*total/);
		assert.match(src, /cellSelectionKey/);
		assert.match(src, /employees\.slice\(range\.start,\s*range\.end\)/);
		assert.match(src, /assignments\.slice\(range\.start,\s*range\.end\)/);
		assert.match(src, /DEFAULT_OVERSCAN/);
		assert.match(src, /addEventListener\('beforeprint',\s*\(\) => setRosterPaintAll\(true\)\)/);
		assert.match(src, /addEventListener\('afterprint',\s*\(\) => setRosterPaintAll\(false\)\)/);
		assert.match(src, /prefers-reduced-motion/);
		assert.match(src, /revealAssignmentRow/);
		assert.match(src, /aria-rowcount/);
		assert.match(src, /aria-colcount/);
		assert.match(src, /dc-roster-grid-scroller/);
		assert.match(src, /inline:\s*'nearest'/);
		assert.match(src, /'PageUp',\s*'PageDown'/);
		assert.match(src, /pageStride/);
		assert.match(src, /applyRosterViewChrome\('list'\)/);
		assert.match(src, /window\.matchMedia\('print'\)/);
		assert.doesNotMatch(src, /revealAssignmentRow[\s\S]{0,900}behavior:\s*'smooth'/);
		assert.doesNotMatch(src, /throw new Error\('DutyCheck virtual window failed to load'\)/);
		assert.doesNotMatch(src, /employees\.forEach\(\(emp, rowIdx\)/);
	});

	it('does not paginate listAssignments', () => {
		const listStart = rosterService.indexOf('private function listAssignments');
		assert.ok(listStart > 0);
		const docStart = rosterService.lastIndexOf('/**', listStart);
		const listEnd = rosterService.indexOf('private function assignmentHasStatusColumn', listStart);
		const block = rosterService.slice(docStart, listEnd);
		assert.match(block, /Never paginated/);
		assert.doesNotMatch(block, /setMaxResults/);
	});

	it('assigns grid role only when rows exist; empty shell stays role=status', () => {
		assert.match(src, /setAttribute\('role', 'grid'\)/);
		assert.match(src, /setAttribute\('role', 'status'\)/);
		assert.doesNotMatch(tpl, /id="dc-roster-grid"[\s\S]{0,160}role="grid"/);
		assert.doesNotMatch(src, /dc-roster-grid__table[\s\S]{0,40}role:\s*'presentation'/);
	});

	it('exposes a granny-readable status and a bounded scroller', () => {
		assert.match(tpl, /id="dc-roster-grid-status"/);
		assert.match(tpl, /id="dc-roster-list-status"/);
		assert.match(tpl, /aria-hidden="true"/);
		assert.match(tpl, /id="dc-roster-grid-scroller"/);
		assert.match(tpl, /id="dc-roster-grid-scroller"[^>]*tabindex="0"/);
		assert.match(tpl, /id="dc-assignments-table-wrap"[^>]*tabindex="0"/);
		assert.match(tpl, /id="dc-roster-list-panel"/);
		assert.match(css, /\.dc-roster-grid-scroller[\s\S]{0,80}overflow:\s*auto/);
		assert.match(css, /\.dc-roster-virtual-status[\s\S]{0,200}color:\s*var\(--color-main-text\)/);
		assert.match(
			css,
			/\.dc-roster-grid-scroller,[\s\S]{0,60}\.dc-roster-list-scroller \{\s*overflow:\s*visible !important;\s*max-height:\s*none !important/,
		);
		assert.match(css, /#dc-assignments-table-wrap\.dc-roster-list-scroller \.dc-table tbody tr\.dc-virtual-spacer \{\s*display:\s*table-row/);
		assert.match(css, /\.dc-roster-grid__row--head[\s\S]{0,80}position:\s*sticky/);
	});
});
