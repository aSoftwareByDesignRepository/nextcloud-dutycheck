(function (root) {
	'use strict';

	/**
	 * DOM window for catalog tables (employees, locations, absences).
	 * Math lives in DutyCheckVirtualWindow. This only slices rows + spacers.
	 *
	 * Lists at or below WINDOW_THRESHOLD paint in full so a 12-person org
	 * never hits virtualization quirks. Larger lists window the tbody.
	 */
	const WINDOW_THRESHOLD = 32;

	const FALLBACK = {
		DEFAULT_ROW_HEIGHT: 44,
		DEFAULT_OVERSCAN: 6,
		visibleRange(opts) {
			const total = Math.max(0, Math.trunc(Number(opts && opts.total) || 0));
			const rowHeight = 44;
			return {
				start: 0,
				end: total,
				padBefore: 0,
				padAfter: 0,
				totalHeight: total * rowHeight,
				rowHeight,
			};
		},
		windowCaption(opts) {
			const total = Math.max(0, Math.trunc(Number(opts && opts.total) || 0));
			if (total <= 0) {
				return { mode: 'empty', from: 0, to: 0, total: 0 };
			}
			return { mode: 'all', from: 1, to: total, total };
		},
	};

	function virtualApi() {
		const api = root.DutyCheckVirtualWindow;
		if (api && typeof api.visibleRange === 'function' && typeof api.windowCaption === 'function') {
			return api;
		}
		return FALLBACK;
	}

	function toColSpan(value) {
		const n = Number(value);
		if (!Number.isFinite(n) || n < 1) {
			return 1;
		}
		return Math.min(24, Math.trunc(n));
	}

	function bind(opts) {
		const options = opts && typeof opts === 'object' ? opts : {};
		const tbody = options.tbody;
		const scroller = options.scroller || null;
		const statusEl = options.statusEl || null;
		const create = typeof options.createElement === 'function' ? options.createElement : null;
		const getRows = options.getRows;
		const renderRow = options.renderRow;
		const emptyRow = options.emptyRow;
		const colSpan = toColSpan(options.colSpan);
		const threshold = Number.isFinite(Number(options.windowThreshold))
			? Math.max(0, Math.trunc(Number(options.windowThreshold)))
			: WINDOW_THRESHOLD;
		const statusAll = typeof options.statusAll === 'function' ? options.statusAll : null;
		const statusWindow = typeof options.statusWindow === 'function' ? options.statusWindow : null;

		if (!tbody || typeof getRows !== 'function' || typeof renderRow !== 'function') {
			return {
				paint() {},
				destroy() {},
			};
		}

		let rowHeight = virtualApi().DEFAULT_ROW_HEIGHT || 44;
		let windowStart = -1;
		let windowEnd = -1;
		let paintedCount = -1;
		let remeasuring = false;
		let paintAll = false;
		let raf = 0;

		function makeSpacer(heightPx) {
			const tr = create
				? create('tr', { class: 'dc-virtual-spacer', attrs: { 'aria-hidden': 'true' } })
				: document.createElement('tr');
			if (!create) {
				tr.className = 'dc-virtual-spacer';
				tr.setAttribute('aria-hidden', 'true');
			}
			const td = document.createElement('td');
			td.colSpan = colSpan;
			td.style.height = Math.max(0, Number(heightPx) || 0) + 'px';
			tr.appendChild(td);
			return tr;
		}

		function updateStatus(range, total) {
			if (!statusEl) {
				return;
			}
			const VW = virtualApi();
			const cap = VW.windowCaption({ start: range.start, end: range.end, total });
			if (cap.mode === 'empty') {
				statusEl.textContent = '';
				return;
			}
			if (cap.mode === 'all') {
				statusEl.textContent = statusAll ? String(statusAll(cap.total)) : '';
				return;
			}
			statusEl.textContent = statusWindow
				? String(statusWindow(cap.from, cap.to, cap.total))
				: '';
		}

		function paint(force) {
			const rows = getRows();
			const list = Array.isArray(rows) ? rows : [];
			if (!list.length) {
				tbody.replaceChildren();
				if (typeof emptyRow === 'function') {
					const tr = emptyRow();
					if (tr) {
						tbody.appendChild(tr);
					}
				}
				windowStart = 0;
				windowEnd = 0;
				paintedCount = 0;
				updateStatus({ start: 0, end: 0 }, 0);
				return;
			}

			const VW = virtualApi();
			const viewportHeight = scroller ? scroller.clientHeight : 0;
			const scrollTop = scroller ? scroller.scrollTop : 0;
			const range = VW.visibleRange({
				total: list.length,
				rowHeight,
				viewportHeight,
				scrollTop,
				overscan: VW.DEFAULT_OVERSCAN,
				paintAll: paintAll === true || list.length <= threshold,
			});
			if (!force
				&& windowStart === range.start
				&& windowEnd === range.end
				&& paintedCount === list.length) {
				updateStatus(range, list.length);
				return;
			}
			const savedTop = scroller ? scroller.scrollTop : 0;
			const frag = document.createDocumentFragment();
			if (range.padBefore > 0) {
				frag.appendChild(makeSpacer(range.padBefore));
			}
			const slice = list.slice(range.start, range.end);
			for (let i = 0; i < slice.length; i++) {
				frag.appendChild(renderRow(slice[i], range.start + i));
			}
			if (range.padAfter > 0) {
				frag.appendChild(makeSpacer(range.padAfter));
			}
			tbody.replaceChildren(frag);
			windowStart = range.start;
			windowEnd = range.end;
			paintedCount = list.length;
			if (scroller) {
				scroller.scrollTop = savedTop;
			}
			const sample = tbody.querySelector('tr:not(.dc-virtual-spacer)');
			const measured = sample ? sample.getBoundingClientRect().height : 0;
			if (measured > 0 && Math.abs(measured - rowHeight) > 1 && !remeasuring) {
				rowHeight = measured;
				remeasuring = true;
				paint(true);
				remeasuring = false;
				return;
			}
			if (measured > 0) {
				rowHeight = measured;
			}
			updateStatus(range, list.length);
		}

		function onScroll() {
			if (raf) {
				return;
			}
			const schedule = root.requestAnimationFrame || function (cb) { return setTimeout(cb, 16); };
			raf = schedule(function () {
				raf = 0;
				paint(false);
			});
		}

		function onBeforePrint() {
			paintAll = true;
			paint(true);
		}

		function onAfterPrint() {
			paintAll = false;
			paint(true);
		}

		if (scroller && typeof scroller.addEventListener === 'function') {
			scroller.addEventListener('scroll', onScroll, { passive: true });
		}
		if (typeof root.addEventListener === 'function') {
			root.addEventListener('resize', onScroll);
			root.addEventListener('beforeprint', onBeforePrint);
			root.addEventListener('afterprint', onAfterPrint);
		}

		return {
			paint(force) {
				paint(force !== false);
			},
			destroy() {
				if (scroller && typeof scroller.removeEventListener === 'function') {
					scroller.removeEventListener('scroll', onScroll);
				}
				if (typeof root.removeEventListener === 'function') {
					root.removeEventListener('resize', onScroll);
					root.removeEventListener('beforeprint', onBeforePrint);
					root.removeEventListener('afterprint', onAfterPrint);
				}
			},
		};
	}

	const api = {
		WINDOW_THRESHOLD,
		bind,
	};

	root.DutyCheckWindowedTable = api;
	if (typeof module !== 'undefined' && module.exports) {
		module.exports = api;
	}
})(typeof window !== 'undefined' ? window : globalThis);
