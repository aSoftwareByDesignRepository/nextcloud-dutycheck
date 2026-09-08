(function () {
	'use strict';

	/**
	 * Locale-aware date/time formatters.
	 *
	 * Nextcloud keeps Language (UI strings) distinct from Locale (date order,
	 * first day of week). The server emits both on `#app-content`:
	 *  - `lang` / `data-language` → translations + RelativeTimeFormat
	 *  - `data-locale` → Intl.DateTimeFormat + date/month pickers
	 *  - `data-first-day-of-week` → 0=Sunday … 6=Saturday from Locale
	 *
	 * Never treat OC.getLocale() as the UI language.
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

	function currentLanguage() {
		const language = htmlAttr('data-language', '');
		if (language) return language;
		const lang = htmlAttr('lang', '');
		if (lang) return lang;
		if (typeof OC !== 'undefined' && OC.getLanguage) {
			const oc = OC.getLanguage();
			if (oc) return String(oc).replace('_', '-');
		}
		return 'en';
	}

	function currentFirstDayOfWeek() {
		const raw = htmlAttr('data-first-day-of-week', '');
		const n = Number(raw);
		if (Number.isInteger(n) && n >= 0 && n <= 6) {
			return n;
		}
		try {
			const locale = currentLocale();
			const Info = Intl.Locale;
			if (typeof Info === 'function') {
				const weekInfo = new Info(locale).weekInfo;
				if (weekInfo && typeof weekInfo.firstDay === 'number') {
					return weekInfo.firstDay === 7 ? 0 : weekInfo.firstDay;
				}
			}
		} catch (e) {
			/* fall through */
		}
		return 1;
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

	/**
	 * Locale for <input type="time"> controls only.
	 * Browsers pick 12h vs 24h picker UI from this attribute; de-DE can still show AM/PM
	 * on some platforms. DutyCheck duty times are always wall-clock HH:mm (24-hour).
	 */
	function timeInputLocale() {
		const app = document.getElementById('app-content');
		const explicit = app ? app.getAttribute('data-dc-time-input-lang') : '';
		if (explicit && explicit.trim() !== '') {
			return explicit.trim();
		}
		return use24HourTimeInputs() ? 'en-GB' : currentLocale();
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

	function formatWeekday(value) {
		const raw = String(value ?? '').trim();
		const iso = raw.match(/^(\d{4}-\d{2}-\d{2})/);
		// Calendar dates use noon UTC so the weekday follows the duty date, not a TZ edge.
		const date = iso ? new Date(`${iso[1]}T12:00:00Z`) : safeDate(value);
		if (!date) return '';
		try {
			return new Intl.DateTimeFormat(currentLanguage(), {
				weekday: 'long',
				timeZone: 'UTC',
			}).format(date);
		} catch (e) {
			return '';
		}
	}

	/**
	 * Calendar ISO (YYYY-MM-DD) for "today" in the company timezone.
	 * en-CA yields a stable YYYY-MM-DD from Intl across engines.
	 */
	function todayIsoDate() {
		try {
			return new Intl.DateTimeFormat('en-CA', {
				timeZone: currentTimezone(),
				year: 'numeric',
				month: '2-digit',
				day: '2-digit',
			}).format(new Date());
		} catch (e) {
			return new Date().toISOString().slice(0, 10);
		}
	}

	/**
	 * Compact planner column header (weekday short + day number).
	 * Full medium date stays in title / aria-label so month grids stay readable.
	 * Weekend/today flags use the duty calendar day (noon UTC), not local midnight.
	 */
	function formatRosterColumnParts(value) {
		const raw = String(value ?? '').trim();
		const isoMatch = raw.match(/^(\d{4}-\d{2}-\d{2})/);
		const iso = isoMatch ? isoMatch[1] : '';
		const date = iso ? new Date(`${iso}T12:00:00Z`) : safeDate(value);
		const full = formatDisplayDate(iso || value) || raw;
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
		try {
			weekdayShort = new Intl.DateTimeFormat(currentLanguage(), {
				weekday: 'short',
				timeZone: 'UTC',
			}).format(date);
			day = new Intl.DateTimeFormat(currentLocale(), {
				day: 'numeric',
				timeZone: 'UTC',
			}).format(date);
		} catch (e) {
			day = iso ? String(Number(iso.slice(8, 10))) : '';
		}
		const weekdayLong = formatWeekday(iso || value) || weekdayShort;
		const dow = date.getUTCDay();
		const isWeekend = dow === 0 || dow === 6;
		const isToday = Boolean(iso) && iso === todayIsoDate();
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

	function formatRelativeMinutes(diffMinutes) {
		if (!Number.isFinite(diffMinutes)) return '';
		try {
			const rtf = new Intl.RelativeTimeFormat(currentLanguage(), { numeric: 'auto' });
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

	/**
	 * Bare two-letter `lang` (e.g. `de`) makes Chromium use en-US-style date fields in some builds;
	 * map to a default region (e.g. `de-DE`). Align with BudgetCheck / LocaleFormatService.
	 */
	function enrichTemporalHtmlLang(tag) {
		const t = String(tag || '').replace(/_/g, '-').trim();
		if (!t) return 'de-DE';
		const parts = t.split('-');
		const n = parts.length;
		if (n >= 2 && parts[1].length === 2 && /^[A-Za-z]{2}$/.test(parts[1])) {
			return parts[0].toLowerCase() + '-' + parts[1].toUpperCase();
		}
		if (n >= 3 && parts[2].length === 2 && /^[A-Za-z]{2}$/.test(parts[2])) {
			return t;
		}
		const base = parts[0].toLowerCase();
		const map = {
			de: 'de-DE', fr: 'fr-FR', it: 'it-IT', es: 'es-ES', nl: 'nl-NL', pl: 'pl-PL', pt: 'pt-PT',
			sv: 'sv-SE', da: 'da-DK', fi: 'fi-FI', cs: 'cs-CZ', sk: 'sk-SK', hu: 'hu-HU', ro: 'ro-RO',
			tr: 'tr-TR', ru: 'ru-RU', uk: 'uk-UA', el: 'el-GR', en: 'en-GB', ja: 'ja-JP', ko: 'ko-KR', zh: 'zh-CN',
			nb: 'nb-NO', nn: 'nb-NO',
		};
		return map[base] || t;
	}

	function resolveTemporalInputLang(locale) {
		const app = document.getElementById('app-content');
		const fromApp = app && app.getAttribute('lang');
		if (fromApp && String(fromApp).trim() !== '') {
			return enrichTemporalHtmlLang(String(fromApp).trim());
		}
		const fromDoc = document.documentElement.getAttribute('lang');
		if (fromDoc && String(fromDoc).trim() !== '') {
			return enrichTemporalHtmlLang(fromDoc);
		}
		return enrichTemporalHtmlLang(locale || currentLocale());
	}

	function dateInputPlaceholder(locale) {
		const tag = String(locale || enrichTemporalHtmlLang(currentLanguage()) || 'en').toLowerCase().replace('_', '-');
		if (
			tag.startsWith('de') || tag.startsWith('nl') || tag.startsWith('da') || tag.startsWith('nb')
			|| tag.startsWith('sv') || tag.startsWith('pl') || tag.startsWith('it') || tag.startsWith('es')
			|| tag.startsWith('fr') || tag.startsWith('pt') || tag.startsWith('fi') || tag.startsWith('cs')
		) {
			return 'TT.MM.JJJJ';
		}
		if (tag.startsWith('en-gb') || tag.startsWith('en-au') || tag.startsWith('en-nz') || tag.startsWith('en-ie')) {
			return 'dd/mm/yyyy';
		}
		return 'mm/dd/yyyy';
	}

	function applyLocaleToTemporalInputs(root) {
		const scope = root || document;
		// Prefer account language for picker chrome when Locale alone still looks US (common NC default).
		const dateLang = enrichTemporalHtmlLang(currentLanguage() || currentLocale());
		const placeholder = dateInputPlaceholder(dateLang);
		scope.querySelectorAll('input[type="date"], input[type="datetime-local"], input[type="month"]').forEach((input) => {
			input.setAttribute('lang', dateLang);
			input.setAttribute('data-dc-date-placeholder', placeholder);
			// Remove legacy under-field TT.MM.JJJJ hints — they leaked under Today
			// quick-select buttons and doubled the overlay placeholder.
			const parent = input.closest('.dc-field') || input.parentElement;
			parent?.querySelectorAll(':scope > .dc-date-locale-hint').forEach((hint) => hint.remove());
			if (input.type === 'date') {
				ensureDateDisplayOverlay(input, dateLang);
			}
		});
		const timeLocale = timeInputLocale();
		scope.querySelectorAll('input[type="time"]').forEach((input) => {
			input.setAttribute('lang', timeLocale);
			if (use24HourTimeInputs()) {
				input.setAttribute('data-dc-time-24h', '1');
			}
		});
	}

	function ensureDateDisplayOverlay(input, locale) {
		if (!input || input.type !== 'date') return;
		let wrap = input.closest('.dc-date-field');
		if (!wrap) {
			wrap = document.createElement('div');
			wrap.className = 'dc-date-field';
			input.parentNode.insertBefore(wrap, input);
			wrap.appendChild(input);
		}
		let overlay = wrap.querySelector(':scope > .dc-date-display');
		const paint = () => {
			const iso = String(input.value || '');
			const text = iso
				? (formatDisplayDate(iso) || iso)
				: (input.getAttribute('data-dc-date-placeholder') || dateInputPlaceholder(locale));
			if (overlay) {
				overlay.textContent = text;
				overlay.classList.toggle('dc-date-display--empty', !iso);
			}
		};
		if (!overlay) {
			overlay = document.createElement('span');
			overlay.className = 'dc-date-display';
			overlay.setAttribute('aria-hidden', 'true');
			wrap.appendChild(overlay);
			input.addEventListener('change', paint);
			input.addEventListener('input', paint);
		}
		paint();
	}

	window.DutyCheckDates = {
		currentLocale,
		currentLanguage,
		currentFirstDayOfWeek,
		currentTimezone,
		timeInputLocale,
		use24HourTimeInputs,
		enrichTemporalHtmlLang,
		resolveTemporalInputLang,
		dateInputPlaceholder,
		formatDisplayDate,
		formatDisplayDateTime,
		formatDisplayTime,
		formatClock24FromTimeString,
		formatClock24Range,
		wallClockMinutesFromTimeString,
		isOvernightWallClockShift,
		formatYearMonth,
		formatWeekday,
		todayIsoDate,
		formatRosterColumnParts,
		rosterDayColumnMin,
		formatRelativeMinutes,
		applyLocaleToTemporalInputs,
	};
})();
