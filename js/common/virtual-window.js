(function (root) {
	'use strict';

	/**
	 * Pure row-window math for roster virtualization.
	 *
	 * The GET roster payload stays complete (SF-06). This only decides which
	 * rows to put in the DOM. Range `end` is exclusive.
	 */

	const DEFAULT_ROW_HEIGHT = 44;
	const DEFAULT_OVERSCAN = 6;
	const UNSIZED_WINDOW_ROWS = 24;

	function toNonNegInt(value, fallback) {
		const n = Number(value);
		if (!Number.isFinite(n) || n < 0) {
			return fallback;
		}
		return Math.trunc(n);
	}

	function toPositiveNumber(value, fallback) {
		const n = Number(value);
		if (!Number.isFinite(n) || n <= 0) {
			return fallback;
		}
		return n;
	}

	function visibleRange(opts) {
		const options = opts && typeof opts === 'object' ? opts : {};
		const total = toNonNegInt(options.total, 0);
		const rowHeight = toPositiveNumber(options.rowHeight, DEFAULT_ROW_HEIGHT);
		const overscan = toNonNegInt(options.overscan, DEFAULT_OVERSCAN);
		const paintAll = options.paintAll === true;
		let viewportHeight = Number(options.viewportHeight);
		if (!Number.isFinite(viewportHeight) || viewportHeight < 0) {
			viewportHeight = 0;
		}
		let scrollTop = Number(options.scrollTop);
		if (!Number.isFinite(scrollTop) || scrollTop < 0) {
			scrollTop = 0;
		}

		const totalHeight = total * rowHeight;
		if (total === 0) {
			return {
				start: 0,
				end: 0,
				padBefore: 0,
				padAfter: 0,
				totalHeight: 0,
				rowHeight,
			};
		}
		if (paintAll) {
			return {
				start: 0,
				end: total,
				padBefore: 0,
				padAfter: 0,
				totalHeight,
				rowHeight,
			};
		}

		let first;
		let visible;
		if (viewportHeight <= 0) {
			first = Math.min(total - 1, Math.floor(scrollTop / rowHeight));
			visible = UNSIZED_WINDOW_ROWS;
		} else {
			first = Math.min(total - 1, Math.floor(scrollTop / rowHeight));
			visible = Math.max(1, Math.ceil(viewportHeight / rowHeight));
		}
		const start = Math.max(0, first - overscan);
		const end = Math.min(total, first + visible + overscan);
		return {
			start,
			end,
			padBefore: start * rowHeight,
			padAfter: (total - end) * rowHeight,
			totalHeight,
			rowHeight,
		};
	}

	function scrollTopToRevealIndex(opts) {
		const options = opts && typeof opts === 'object' ? opts : {};
		const index = toNonNegInt(options.index, 0);
		const rowHeight = toPositiveNumber(options.rowHeight, DEFAULT_ROW_HEIGHT);
		const viewportHeight = toPositiveNumber(options.viewportHeight, rowHeight);
		const total = Math.max(index + 1, toNonNegInt(options.total, index + 1));
		let scrollTop = Number(options.scrollTop);
		if (!Number.isFinite(scrollTop) || scrollTop < 0) {
			scrollTop = 0;
		}
		const maxScroll = Math.max(0, total * rowHeight - viewportHeight);
		const rowTop = index * rowHeight;
		const rowBottom = rowTop + rowHeight;
		const viewBottom = scrollTop + viewportHeight;
		let next = scrollTop;
		if (rowTop < scrollTop) {
			next = rowTop;
		} else if (rowBottom > viewBottom) {
			next = rowBottom - viewportHeight;
		}
		if (next < 0) {
			next = 0;
		}
		if (next > maxScroll) {
			next = maxScroll;
		}
		return next;
	}

	function pageStride(opts) {
		const options = opts && typeof opts === 'object' ? opts : {};
		const rowHeight = toPositiveNumber(options.rowHeight, DEFAULT_ROW_HEIGHT);
		const viewportHeight = toPositiveNumber(options.viewportHeight, rowHeight);
		return Math.max(1, Math.floor(viewportHeight / rowHeight) - 1);
	}

	function windowCaption(opts) {
		const options = opts && typeof opts === 'object' ? opts : {};
		const total = toNonNegInt(options.total, 0);
		const start = toNonNegInt(options.start, 0);
		let end = toNonNegInt(options.end, 0);
		if (end < start) {
			end = start;
		}
		if (end > total) {
			end = total;
		}
		if (total <= 0) {
			return { mode: 'empty', from: 0, to: 0, total: 0 };
		}
		if (start <= 0 && end >= total) {
			return { mode: 'all', from: 1, to: total, total };
		}
		const from = Math.min(total, start + 1);
		const to = Math.max(from, end);
		return { mode: 'window', from, to, total };
	}

	const api = {
		DEFAULT_ROW_HEIGHT,
		DEFAULT_OVERSCAN,
		UNSIZED_WINDOW_ROWS,
		visibleRange,
		scrollTopToRevealIndex,
		scrollOffsetToRevealIndex: scrollTopToRevealIndex,
		pageStride,
		windowCaption,
	};

	root.DutyCheckVirtualWindow = api;
	if (typeof module !== 'undefined' && module.exports) {
		module.exports = api;
	}
})(typeof window !== 'undefined' ? window : globalThis);
