(function () {
	'use strict';

	/**
	 * Locale-aware date/time formatters.
	 *
	 * The server emits `data-locale` and `data-timezone` on `<html>`; we honour
	 * both whenever Intl.* allows and fall back to ISO strings on failure so
	 * date columns never render as "Invalid Date" in production.
	 */

	function htmlAttr(name, fallback) {
		// Read from #app-content first (where the server emits data-locale and
		// data-timezone for the DutyCheck app shell), then fall back to <html>.
		const app = document.getElementById('app-content');
		const fromApp = app ? app.getAttribute(name) : '';
		if (fromApp && fromApp.trim() !== '') return fromApp;
		const html = document.documentElement;
		const value = html ? html.getAttribute(name) : '';
		return value && value.trim() !== '' ? value : fallback;
	}

	function currentLocale() {
		const locale = htmlAttr('data-locale', '');
		if (locale) return locale;
		if (typeof OC !== 'undefined' && OC.getLocale) {
			const oc = OC.getLocale();
			if (oc) return String(oc).replace('_', '-');
		}
		return navigator.language || 'en';
	}

	function currentTimezone() {
		const tz = htmlAttr('data-timezone', '');
		if (tz) return tz;
		try {
			return Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC';
		} catch (e) {
			return 'UTC';
		}
	}

	function safeDate(value) {
		if (value === null || value === undefined || value === '') return null;
		if (value instanceof Date) {
			return Number.isNaN(value.getTime()) ? null : value;
		}
		const date = new Date(value);
		return Number.isNaN(date.getTime()) ? null : date;
	}

	function use24HourTimeInputs() {
		const app = document.getElementById('app-content');
		return !!(app && app.getAttribute('data-dc-time-24h') === '1');
	}

	function formatDisplayDate(value) {
		const date = safeDate(value);
		if (!date) return '';
		try {
			return new Intl.DateTimeFormat(currentLocale(), {
				dateStyle: 'medium',
				timeZone: currentTimezone(),
			}).format(date);
		} catch (e) {
			return date.toISOString().slice(0, 10);
		}
	}

	function formatDisplayDateTime(value) {
		const date = safeDate(value);
		if (!date) return '';
		const tz = currentTimezone();
		const locale = currentLocale();
		try {
			if (use24HourTimeInputs()) {
				return new Intl.DateTimeFormat(locale, {
					dateStyle: 'medium',
					timeZone: tz,
					hour: '2-digit',
					minute: '2-digit',
					hour12: false,
				}).format(date);
			}
			return new Intl.DateTimeFormat(locale, {
				dateStyle: 'medium',
				timeStyle: 'short',
				timeZone: tz,
			}).format(date);
		} catch (e) {
			return date.toISOString().replace('T', ' ').slice(0, 16);
		}
	}

	function formatDisplayTime(value) {
		const date = safeDate(value);
		if (!date) return '';
		const tz = currentTimezone();
		const locale = currentLocale();
		try {
			if (use24HourTimeInputs()) {
				return new Intl.DateTimeFormat(locale, {
					timeZone: tz,
					hour: '2-digit',
					minute: '2-digit',
					hour12: false,
				}).format(date);
			}
			return new Intl.DateTimeFormat(locale, {
				timeStyle: 'short',
				timeZone: tz,
			}).format(date);
		} catch (e) {
			return date.toISOString().slice(11, 16);
		}
	}

	function formatYearMonth(value) {
		if (typeof value === 'string' && /^\d{4}-\d{2}$/.test(value)) {
			const [year, month] = value.split('-').map((segment) => Number(segment));
			const date = new Date(Date.UTC(year, month - 1, 1));
			try {
				return new Intl.DateTimeFormat(currentLocale(), {
					month: 'long',
					year: 'numeric',
					timeZone: 'UTC',
				}).format(date);
			} catch (e) {
				return value;
			}
		}
		const date = safeDate(value);
		if (!date) return '';
		try {
			return new Intl.DateTimeFormat(currentLocale(), {
				month: 'long',
				year: 'numeric',
				timeZone: currentTimezone(),
			}).format(date);
		} catch (e) {
			return date.toISOString().slice(0, 7);
		}
	}

	/**
	 * Normalise a wall-clock time from the API or an <input type="time"> to HH:mm (24-hour).
	 * Does not convert zones; identifiers like Europe/Berlin are returned unchanged.
	 */
	function formatClock24FromTimeString(value) {
		if (value === null || value === undefined || value === '') {
			return '';
		}
		const s = String(value).trim();
		const m = s.match(/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/);
		if (!m) {
			return s;
		}
		const h = Number(m[1]);
		const min = Number(m[2]);
		if (!Number.isInteger(h) || !Number.isInteger(min) || h > 23 || min > 59) {
			return s;
		}
		return String(h).padStart(2, '0') + ':' + String(min).padStart(2, '0');
	}

	function formatClock24Range(start, end) {
		const a = formatClock24FromTimeString(start);
		const b = formatClock24FromTimeString(end);
		if (!a || !b) {
			return `${start || ''} – ${end || ''}`.trim();
		}
		return `${a} – ${b}`;
	}

	/**
	 * Wall-clock minutes 0–1439 from a HH:mm (or H:mm) duty time string.
	 * Returns null if the value cannot be parsed as a normal duty time.
	 */
	function wallClockMinutesFromTimeString(value) {
		const m = String(value ?? '').trim().match(/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/);
		if (!m) return null;
		const h = Number(m[1]);
		const min = Number(m[2]);
		if (!Number.isInteger(h) || !Number.isInteger(min) || h > 23 || min > 59) return null;
		return h * 60 + min;
	}

	/**
	 * True when end is strictly before start on the same calendar day — the
	 * server's overnight-shift convention (end wraps to the next calendar day).
	 */
	function isOvernightWallClockShift(start, end) {
		const a = wallClockMinutesFromTimeString(start);
		const b = wallClockMinutesFromTimeString(end);
		if (a === null || b === null) return false;
		return b < a;
	}

	function formatRelativeMinutes(diffMinutes) {
		if (!Number.isFinite(diffMinutes)) return '';
		try {
			const rtf = new Intl.RelativeTimeFormat(currentLocale(), { numeric: 'auto' });
			const minutes = Math.round(diffMinutes);
			if (Math.abs(minutes) < 60) return rtf.format(minutes, 'minute');
			const hours = Math.round(minutes / 60);
			if (Math.abs(hours) < 48) return rtf.format(hours, 'hour');
			const days = Math.round(hours / 24);
			return rtf.format(days, 'day');
		} catch (e) {
			return '';
		}
	}

	function applyLocaleToTemporalInputs(root) {
		const scope = root || document;
		const locale = currentLocale();
		scope.querySelectorAll('input[type="date"], input[type="datetime-local"], input[type="month"]').forEach((input) => {
			input.setAttribute('lang', locale);
		});
		scope.querySelectorAll('input[type="time"]').forEach((input) => {
			/* Match page locale; UI hints already state 24-hour (HH:mm) when data-dc-time-24h is set. */
			input.setAttribute('lang', locale);
		});
	}

	window.DutyCheckDates = {
		currentLocale,
		currentTimezone,
		formatDisplayDate,
		formatDisplayDateTime,
		formatDisplayTime,
		formatClock24FromTimeString,
		formatClock24Range,
		wallClockMinutesFromTimeString,
		isOvernightWallClockShift,
		formatYearMonth,
		formatRelativeMinutes,
		applyLocaleToTemporalInputs,
	};
})();
