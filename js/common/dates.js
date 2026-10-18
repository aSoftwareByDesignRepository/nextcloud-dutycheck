(function () {
	'use strict';

	/**
	 * DutyCheckDates — thin adapter over the synced canonical module
	 * (js/common/check-dates.js → window.CheckDates).
	 *
	 * naive datetime contract: 'utc' — PHP writers emit
	 * (new DateTimeImmutable('now'))->format('Y-m-d H:i:s') under the
	 * Nextcloud default TZ (UTC); ISO instants elsewhere carry Z/offsets.
	 * Verified: lib/Service/RosterService.php:4961, SwapService.php,
	 * PeriodLockService.php (stock php.ini date.timezone=UTC).
	 *
	 * Roster-specific helpers (formatRosterColumnParts, rosterDayColumnMin)
	 * stay local — they are not shared concepts.
	 */

	const D = window.CheckDates.create({
		prefix: 'dc',
		naiveMode: 'utc',
		displayDateStyle: 'medium',
		attrs: {
			locale: 'data-locale',
			language: 'data-language',
			timezone: 'data-timezone',
			firstDay: 'data-first-day-of-week',
			time24h: 'data-dc-time-24h',
			timeInputLang: 'data-dc-time-input-lang',
		},
	});

	function tr(text) {
		return typeof t === 'function' ? t('dutycheck', text) : text;
	}

	function applyLocaleToTemporalInputs(root, options) {
		return D.applyLocaleToTemporalInputs(root, Object.assign({
			pickerLabel: tr('Choose date'),
		}, options || {}));
	}

	/**
	 * Compact planner column header (weekday short + day number).
	 * Weekend/today flags use the duty calendar day (noon-UTC anchor).
	 */
	function formatRosterColumnParts(value) {
		const raw = String(value ?? '').trim();
		const isoMatch = raw.match(/^(\d{4}-\d{2}-\d{2})/);
		const iso = isoMatch ? isoMatch[1] : '';
		const date = (iso && D.isIsoDateOnly(iso))
			? D.toCalendarAnchor(iso)
			: D.toInstant(value);
		const full = D.formatDisplayDate(iso || value) || raw;
		if (!date) {
			return {
				weekdayShort: '',
				day: '',
				full,
				ariaLabel: full,
				isWeekend: false,
				isToday: false,
				iso: iso || '',
			};
		}
		let weekdayShort = '';
		let day = '';
		if (iso && D.isIsoDateOnly(iso)) {
			try {
				weekdayShort = new Intl.DateTimeFormat(D.currentLanguage(), {
					weekday: 'short',
					timeZone: 'UTC',
				}).format(date);
				day = new Intl.DateTimeFormat(D.currentLocale(), {
					day: 'numeric',
					timeZone: 'UTC',
				}).format(date);
			} catch (e) {
				day = String(Number(iso.slice(8, 10)));
			}
		} else {
			try {
				weekdayShort = new Intl.DateTimeFormat(D.currentLanguage(), {
					weekday: 'short',
					timeZone: D.currentTimezone(),
				}).format(date);
				day = new Intl.DateTimeFormat(D.currentLocale(), {
					day: 'numeric',
					timeZone: D.currentTimezone(),
				}).format(date);
			} catch (e) {
				day = '';
			}
		}
		const weekdayLong = D.formatWeekday(iso || value) || weekdayShort;
		const isUtcAnchored = Boolean(iso) && D.isIsoDateOnly(iso);
		const dow = isUtcAnchored ? date.getUTCDay() : date.getDay();
		const isWeekend = dow === 0 || dow === 6;
		const isToday = Boolean(iso) && iso === D.todayIsoDate();
		const ariaLabel = weekdayLong && full ? `${weekdayLong}, ${full}` : full;
		return {
			weekdayShort,
			day,
			full,
			ariaLabel,
			isWeekend,
			isToday,
			iso: iso || '',
		};
	}

	/** Day-column min width for CSS — tighter tracks when the period is long. */
	function rosterDayColumnMin(dayCount) {
		const n = Number(dayCount);
		if (!Number.isFinite(n) || n <= 10) {
			return '4.5rem';
		}
		if (n <= 21) {
			return '3.75rem';
		}
		return '3.25rem';
	}

	window.DutyCheckDates = {
		currentLocale: D.currentLocale,
		currentLanguage: D.currentLanguage,
		currentFirstDayOfWeek: D.currentFirstDayOfWeek,
		currentTimezone: D.currentTimezone,
		timeInputLocale: D.timeInputLocale,
		use24HourTimeInputs: D.use24HourTimeInputs,
		enrichTemporalHtmlLang: D.enrichTemporalHtmlLang,
		resolveTemporalInputLang: D.resolveTemporalInputLang,
		dateInputPlaceholder: D.dateInputPlaceholder,
		formatDisplayDate: D.formatDisplayDate,
		formatDisplayDateTime: D.formatDisplayDateTime,
		formatDisplayTime: D.formatDisplayTime,
		classifyTemporal: D.classifyTemporal,
		toInstant: D.toInstant,
		toCalendarAnchor: D.toCalendarAnchor,
		isoForDateInput: D.isoForDateInput,
		parseDisplayDateToIso: D.parseDisplayDateToIso,
		formatClock24FromTimeString: D.formatClock24FromTimeString,
		formatClock24Range: D.formatClock24Range,
		wallClockMinutesFromTimeString: D.wallClockMinutesFromTimeString,
		isOvernightWallClockShift: D.isOvernightWallClockShift,
		formatYearMonth: D.formatYearMonth,
		formatWeekday: D.formatWeekday,
		todayIsoDate: D.todayIsoDate,
		formatRosterColumnParts,
		rosterDayColumnMin,
		formatRelativeMinutes: D.formatRelativeMinutes,
		applyLocaleToTemporalInputs,
	};
})();
