(function () {
	'use strict';

	const Api = window.DutyCheckApi;
	const Msg = window.DutyCheckMessaging;
	const C = window.DutyCheckComponents;
	const D = window.DutyCheckDates;
	const create = C.createElement;

	const TABLE_COLSPAN = 7;
	const QUICK_RANGES = ['upcoming', 'today', 'week', 'next-week', '14d', 'month'];
	const DEFAULT_RANGE = 'upcoming';

	const state = {
		from: '',
		to: '',
		range: DEFAULT_RANGE,
	};

	/** Whether the server already stores an iCal secret (URL is usually masked in the UI). */
	let icalHasToken = false;

	function showAccountAlert(message) {
		const el = document.getElementById('dc-employee-account-alert');
		if (!el) return;
		el.textContent = message;
		el.hidden = false;
	}

	function hideAccountAlert() {
		const el = document.getElementById('dc-employee-account-alert');
		if (!el) return;
		el.hidden = true;
		el.textContent = '';
	}

	function pad2(value) {
		return String(value).padStart(2, '0');
	}

	function isoFromDate(date) {
		return `${date.getFullYear()}-${pad2(date.getMonth() + 1)}-${pad2(date.getDate())}`;
	}

	function startOfDay(date) {
		const d = new Date(date.getTime());
		d.setHours(0, 0, 0, 0);
		return d;
	}

	function addDays(date, days) {
		const d = new Date(date.getTime());
		d.setDate(d.getDate() + days);
		return d;
	}

	function startOfIsoWeek(date) {
		const d = startOfDay(date);
		const dayOfWeek = (d.getDay() + 6) % 7; // Mon = 0 ... Sun = 6
		return addDays(d, -dayOfWeek);
	}

	function startOfMonth(date) {
		const d = startOfDay(date);
		return new Date(d.getFullYear(), d.getMonth(), 1);
	}

	function endOfMonth(date) {
		const d = startOfDay(date);
		return new Date(d.getFullYear(), d.getMonth() + 1, 0);
	}

	function rangeForKey(key) {
		const today = startOfDay(new Date());
		switch (key) {
			case 'today':
				return { from: today, to: today };
			case 'week': {
				const start = startOfIsoWeek(today);
				return { from: start, to: addDays(start, 6) };
			}
			case 'next-week': {
				const start = addDays(startOfIsoWeek(today), 7);
				return { from: start, to: addDays(start, 6) };
			}
			case '14d':
				return { from: today, to: addDays(today, 14) };
			case 'month':
				return { from: startOfMonth(today), to: endOfMonth(today) };
			case 'upcoming':
			default:
				return { from: today, to: addDays(today, 365) };
		}
	}

	function applyRangeKey(key) {
		const safeKey = QUICK_RANGES.includes(key) ? key : DEFAULT_RANGE;
		const range = rangeForKey(safeKey);
		state.range = safeKey;
		state.from = isoFromDate(range.from);
		state.to = isoFromDate(range.to);
		syncQuickButtons();
		syncFormInputs();
	}

	function syncQuickButtons() {
		document.querySelectorAll('#dc-my-roster-quickfilters .dc-quickfilters__btn').forEach((btn) => {
			const isActive = btn.dataset.range === state.range;
			btn.setAttribute('aria-pressed', isActive ? 'true' : 'false');
		});
	}

	function syncFormInputs() {
		const fromInput = document.getElementById('dc-my-roster-from');
		const toInput = document.getElementById('dc-my-roster-to');
		if (fromInput) fromInput.value = state.from;
		if (toInput) toInput.value = state.to;
	}

	function clearQuickButtons() {
		state.range = '';
		document.querySelectorAll('#dc-my-roster-quickfilters .dc-quickfilters__btn').forEach((btn) => {
			btn.setAttribute('aria-pressed', 'false');
		});
	}

	function isValidIsoDate(value) {
		if (typeof value !== 'string') return false;
		if (!/^\d{4}-\d{2}-\d{2}$/.test(value)) return false;
		const date = new Date(`${value}T00:00:00`);
		if (Number.isNaN(date.getTime())) return false;
		return isoFromDate(date) === value;
	}

	function weekdayLabel(isoDate) {
		const date = new Date(`${isoDate}T00:00:00`);
		if (Number.isNaN(date.getTime())) return '';
		try {
			return new Intl.DateTimeFormat(D?.currentLocale?.() || 'en', {
				weekday: 'long',
				timeZone: D?.currentTimezone?.() || undefined,
			}).format(date);
		} catch (e) {
			return '';
		}
	}

	function rangeLabel(fromIso, toIso) {
		const from = D?.formatDisplayDate?.(fromIso) || fromIso;
		const to = D?.formatDisplayDate?.(toIso) || toIso;
		if (!from && !to) return '';
		if (from === to) return from;
		return t('dutycheck', '{from} – {to}').replace('{from}', from).replace('{to}', to);
	}

	function setStatus(text) {
		const el = document.getElementById('dc-my-roster-status');
		if (!el) return;
		el.textContent = text;
	}

	function renderRoster(rows) {
		const tbody = document.getElementById('dc-my-roster-table-body');
		if (!tbody) return;
		const overnightHintText = t('dutycheck', 'Continues into the next day.');
		const startClock = (s) => (D?.formatClock24FromTimeString?.(s) || String(s ?? ''));
		tbody.replaceChildren();
		const summary = rangeLabel(state.from, state.to);
		if (!rows.length) {
			setStatus(
				summary
					? t('dutycheck', 'No published shifts in {range}.').replace('{range}', summary)
					: t('dutycheck', 'No published shifts in the selected range.'),
			);
			const tr = create('tr', { class: 'dc-table__empty-row' });
			const td = create('td');
			td.colSpan = TABLE_COLSPAN;
			td.appendChild(create('strong', { text: t('dutycheck', 'No published shifts in this range.') }));
			td.appendChild(create('p', {
				class: 'dc-field__hint',
				text: t('dutycheck', 'Try a wider range or check back after the next publication.'),
			}));
			tr.appendChild(td);
			tbody.appendChild(tr);
			return;
		}
		const countLabel = rows.length === 1
			? t('dutycheck', '1 published shift')
			: t('dutycheck', '{n} published shifts').replace('{n}', String(rows.length));
		setStatus(
			summary
				? t('dutycheck', '{count} in {range}.').replace('{count}', countLabel).replace('{range}', summary)
				: t('dutycheck', '{count}.').replace('{count}', countLabel),
		);
		for (const row of rows) {
			const tr = create('tr');

			const date = D?.formatDisplayDate?.(row.dutyDate) || row.dutyDate;
			const thDate = create('th', {
				class: 'dc-table__rowhead',
				attrs: { scope: 'row' },
				dataset: { cell: t('dutycheck', 'Date') },
				text: date,
			});
			tr.appendChild(thDate);

			const tdDay = create('td', { text: weekdayLabel(row.dutyDate) });
			tdDay.dataset.cell = t('dutycheck', 'Day');
			tr.appendChild(tdDay);

			const tdStart = create('td');
			tdStart.dataset.cell = t('dutycheck', 'Start');
			tdStart.appendChild(create('span', { class: 'dc-time-cell__value', text: startClock(row.startTime) }));
			tr.appendChild(tdStart);

			const tdEnd = create('td');
			tdEnd.dataset.cell = t('dutycheck', 'End');
			tdEnd.appendChild(create('span', { class: 'dc-time-cell__value', text: startClock(row.endTime) }));
			if (D?.isOvernightWallClockShift?.(row.startTime, row.endTime)) {
				tdEnd.appendChild(create('span', { class: 'dc-row-meta', text: overnightHintText }));
			}
			tr.appendChild(tdEnd);

			const rest = [
				{ label: t('dutycheck', 'Location'), value: row.locationName || '' },
				{ label: t('dutycheck', 'Break'), value: t('dutycheck', '{n} min').replace('{n}', String(row.breakMinutes ?? 0)) },
				{ label: t('dutycheck', 'Note'), value: row.note || '' },
			];
			for (const cell of rest) {
				const td = create('td', { text: String(cell.value ?? '') });
				td.dataset.cell = cell.label;
				tr.appendChild(td);
			}
			tbody.appendChild(tr);
		}
	}

	function syncIcalActionButton() {
		const btn = document.getElementById('dc-ical-rotate-button');
		if (!btn) return;
		if (icalHasToken) {
			btn.className = 'button danger';
			btn.textContent = t('dutycheck', 'Replace calendar link');
		} else {
			btn.className = 'button primary';
			btn.textContent = t('dutycheck', 'Create calendar link');
		}
	}

	function setCopyLinkEnabled(on) {
		const copy = document.getElementById('dc-ical-copy-button');
		if (!copy) return;
		copy.disabled = !on;
		copy.className = on ? 'button primary' : 'button';
	}

	function renderIcalMeta(meta) {
		const input = document.getElementById('dc-ical-url');
		const note = document.getElementById('dc-ical-note');
		if (!input || !note) return;
		icalHasToken = meta?.hasToken === true;
		syncIcalActionButton();
		if (!icalHasToken || !meta?.icalUrl) {
			input.value = '';
			note.textContent = t('dutycheck', 'No calendar link yet. Tap “Create calendar link” above.');
			setCopyLinkEnabled(false);
			return;
		}
		input.value = String(meta.icalUrl).replace('__TOKEN__', '••••••••');
		note.textContent = t('dutycheck', 'A link is active. The full address is hidden here for safety. Replace the link if you need to copy it again.');
		setCopyLinkEnabled(false);
	}

	async function loadIcalMeta() {
		try {
			const response = await Api.get('/apps/dutycheck/api/me/ical-token');
			renderIcalMeta(response?.data || {});
		} catch (err) {
			const code = String(err?.payload?.error?.code || err?.code || '');
			if (code === 'EMPLOYEE_LINK_NOT_FOUND' || code === 'EMPLOYEE_RECORD_LINK_REQUIRED') {
				renderUnlinkedFallback();
				return;
			}
			Msg.handleApiError(err);
		}
	}

	async function rotateIcalToken() {
		const isFirst = !icalHasToken;
		const ok = await C.confirmDialog({
			title: isFirst
				? t('dutycheck', 'Create calendar link?')
				: t('dutycheck', 'Replace calendar link?'),
			body: isFirst
				? t('dutycheck', 'This creates a secret web address for your calendar app. Anyone with the address can see your published shifts. Only continue if this device is private to you.')
				: t('dutycheck', 'Your old address stops working right away. Paste the new one into your calendar app so it keeps updating.'),
			confirmLabel: isFirst ? t('dutycheck', 'Create link') : t('dutycheck', 'Replace link'),
			danger: !isFirst,
		});
		if (!ok) return;
		try {
			const response = await Api.post('/apps/dutycheck/api/me/ical-token/rotate', {});
			const url = String(response?.data?.icalUrl || '');
			const input = document.getElementById('dc-ical-url');
			const note = document.getElementById('dc-ical-note');
			if (input) input.value = url;
			if (note) note.textContent = t('dutycheck', 'Your new link is ready. Copy it now and paste it into your calendar app. It will be hidden again after you leave this page.');
			icalHasToken = true;
			syncIcalActionButton();
			setCopyLinkEnabled(url !== '');
			Msg.announce(t('dutycheck', 'Calendar link ready.'));
			window.setTimeout(() => {
				if (input && document.body.contains(input)) {
					input.focus();
					try {
						input.select();
					} catch (_) { /* readonly inputs: select may fail in some UAs */ }
				}
			}, 50);
		} catch (err) {
			Msg.handleApiError(err);
		}
	}

	async function copyIcalUrl() {
		const input = document.getElementById('dc-ical-url');
		if (!input || !input.value) return;
		if (input.value.includes('\u2022')) {
			Msg.announce(t('dutycheck', 'The address is hidden here. Use “Replace calendar link” to create a fresh address you can copy.'), 'error');
			return;
		}
		try {
			if (navigator.clipboard && window.isSecureContext) {
				await navigator.clipboard.writeText(input.value);
			} else {
				input.select();
				input.setSelectionRange(0, input.value.length);
				document.execCommand('copy');
			}
			Msg.announce(t('dutycheck', 'Calendar link copied.'));
		} catch (err) {
			Msg.announce(t('dutycheck', 'Could not copy. Select the address manually.'), 'error');
		}
	}

	function renderUnlinkedFallback() {
		showAccountAlert(t('dutycheck', 'Your account is not linked to an employee record. Ask a planner to link your Nextcloud account before you can see duties or calendar links.'));
		const tbody = document.getElementById('dc-my-roster-table-body');
		if (tbody) {
			tbody.replaceChildren();
			const tr = create('tr', { class: 'dc-table__empty-row' });
			const td = create('td', { text: t('dutycheck', 'No roster data — account not linked to an employee.') });
			td.colSpan = TABLE_COLSPAN;
			tr.appendChild(td);
			tbody.appendChild(tr);
		}
		setStatus(t('dutycheck', 'Account not linked — no shifts to show.'));
		const filterForm = document.getElementById('dc-my-roster-filter');
		if (filterForm) {
			filterForm.querySelectorAll('input,button').forEach((el) => { el.disabled = true; });
		}
		document.querySelectorAll('#dc-my-roster-quickfilters .dc-quickfilters__btn').forEach((btn) => { btn.disabled = true; });
		const icalNote = document.getElementById('dc-ical-note');
		const rotateBtn = document.getElementById('dc-ical-rotate-button');
		const copyBtn = document.getElementById('dc-ical-copy-button');
		const urlInput = document.getElementById('dc-ical-url');
		if (icalNote) icalNote.textContent = t('dutycheck', 'Calendar link is unavailable until your account is linked to an employee.');
		if (rotateBtn) rotateBtn.disabled = true;
		if (copyBtn) {
			copyBtn.disabled = true;
			copyBtn.className = 'button';
		}
		if (urlInput) urlInput.value = '';
	}

	async function fetchAndRender() {
		const tbody = document.getElementById('dc-my-roster-table-body');
		C.setLoadingRow(tbody, TABLE_COLSPAN);
		setStatus(t('dutycheck', 'Loading…'));
		try {
			const response = await Api.get('/apps/dutycheck/api/my/roster', {
				from: state.from,
				to: state.to,
			});
			renderRoster(Array.isArray(response?.data) ? response.data : []);
		} catch (err) {
			const code = String(err?.payload?.error?.code || err?.code || '');
			if (code === 'EMPLOYEE_LINK_NOT_FOUND' || code === 'EMPLOYEE_RECORD_LINK_REQUIRED') {
				renderUnlinkedFallback();
				return;
			}
			Msg.handleApiError(err);
			C.renderTableFetchError(tbody, TABLE_COLSPAN, t('dutycheck', 'Could not load your roster. Reload the page or contact an administrator if this keeps happening.'));
			setStatus(t('dutycheck', 'Could not load roster.'));
		} finally {
			C.clearLoadingRow(tbody);
		}
	}

	function bindQuickFilters() {
		document.querySelectorAll('#dc-my-roster-quickfilters .dc-quickfilters__btn').forEach((btn) => {
			btn.addEventListener('click', () => {
				const key = btn.dataset.range || DEFAULT_RANGE;
				applyRangeKey(key);
				fetchAndRender();
			});
		});
	}

	function bindCustomRangeForm() {
		const form = document.getElementById('dc-my-roster-filter');
		if (!form) return;
		form.addEventListener('submit', (event) => {
			event.preventDefault();
			const fromInput = document.getElementById('dc-my-roster-from');
			const toInput = document.getElementById('dc-my-roster-to');
			const fromValue = String(fromInput?.value || '').trim();
			const toValue = String(toInput?.value || '').trim();
			if (!isValidIsoDate(fromValue) || !isValidIsoDate(toValue)) {
				Msg.announce(t('dutycheck', 'Enter both dates in the format the input expects.'), 'error');
				return;
			}
			if (fromValue > toValue) {
				Msg.announce(t('dutycheck', 'The "From" date must not be after the "To" date.'), 'error');
				return;
			}
			state.from = fromValue;
			state.to = toValue;
			clearQuickButtons();
			fetchAndRender();
		});
	}

	document.addEventListener('DOMContentLoaded', async () => {
		D?.applyLocaleToTemporalInputs?.(document);
		hideAccountAlert();
		applyRangeKey(DEFAULT_RANGE);
		bindQuickFilters();
		bindCustomRangeForm();
		await fetchAndRender();
		await loadIcalMeta();
		document.getElementById('dc-ical-rotate-button')?.addEventListener('click', rotateIcalToken);
		document.getElementById('dc-ical-copy-button')?.addEventListener('click', copyIcalUrl);
	});
})();
