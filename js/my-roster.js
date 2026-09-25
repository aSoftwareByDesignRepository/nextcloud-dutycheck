(function () {
	'use strict';

	const Api = window.DutyCheckApi;
	const Msg = window.DutyCheckMessaging;
	const C = window.DutyCheckComponents || window.DutyCheckDom || {};
	const D = window.DutyCheckDates;
	const create = C.createElement;
	if (typeof create !== 'function') {
		throw new Error('DutyCheck components failed to load');
	}

	const TABLE_COLSPAN = 8;
	const QUICK_RANGES = ['upcoming', 'today', 'week', 'next-week', '14d', 'month'];
	const DEFAULT_RANGE = 'upcoming';

	const state = {
		from: '',
		to: '',
		range: DEFAULT_RANGE,
	};

	/** Whether the server already stores an iCal secret (URL is usually masked in the UI). */
	let icalHasToken = false;

	function integrationBootstrapFromDom() {
		const root = document.getElementById('app-content');
		const raw = root?.dataset?.dcIntegrationBootstrap || '';
		if (!raw) return null;
		try {
			return JSON.parse(raw);
		} catch {
			return null;
		}
	}

	function applyIcalAtDisclosure() {
		const box = document.getElementById('dc-ical-at-disclosure');
		const link = document.getElementById('dc-ical-open-azc');
		if (!box) return;
		const integ = integrationBootstrapFromDom();
		const effective = Boolean(integ?.effective || integ?.readonlyAbsencesForCurrentUser || integ?.integrationLocksLinkedDutyCheckAbsences);
		box.hidden = !effective;
		if (link) {
			const url = integ?.peerEmployeeOutboundUrl;
			if (effective && url) {
				link.href = String(url);
				link.hidden = false;
			} else {
				link.removeAttribute('href');
				link.hidden = true;
			}
		}
	}

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
		if (typeof D?.formatWeekday === 'function') {
			return D.formatWeekday(isoDate);
		}
		const date = new Date(`${isoDate}T00:00:00`);
		if (Number.isNaN(date.getTime())) return '';
		try {
			return new Intl.DateTimeFormat(D?.currentLanguage?.() || 'en', {
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

			const tdAck = create('td');
			tdAck.dataset.cell = t('dutycheck', 'Confirm');
			if (row.acknowledged || row.acknowledgedAt) {
				tdAck.appendChild(create('span', {
					class: 'dc-badge dc-badge--success',
					text: t('dutycheck', 'Seen'),
					attrs: { 'aria-label': t('dutycheck', 'You have confirmed this shift') },
				}));
			} else {
				const btn = create('button', {
					class: 'button primary',
					text: t('dutycheck', 'Got it'),
					attrs: {
						type: 'button',
						'aria-label': t('dutycheck', 'Confirm you have seen this shift'),
					},
				});
				btn.style.minHeight = '44px';
				btn.style.minWidth = '44px';
				btn.addEventListener('click', async () => {
					btn.disabled = true;
					try {
						await Api.post(`/apps/dutycheck/api/my/assignments/${row.id}/acknowledge`, {});
						row.acknowledged = true;
						row.acknowledgedAt = new Date().toISOString();
						tdAck.replaceChildren(create('span', {
							class: 'dc-badge dc-badge--success',
							text: t('dutycheck', 'Seen'),
						}));
						C?.announce?.(t('dutycheck', 'Shift confirmed'));
					} catch (err) {
						btn.disabled = false;
						Messaging?.showError?.(err) || console.error(err);
					}
				});
				tdAck.appendChild(btn);
			}
			tr.appendChild(tdAck);

			const tdSwap = create('td');
			tdSwap.dataset.cell = t('dutycheck', 'Swap');
			const swapBtn = create('button', {
				type: 'button',
				class: 'button button--text',
				text: t('dutycheck', 'Request swap'),
			});
			swapBtn.style.minHeight = '44px';
			swapBtn.addEventListener('click', () => {
				openSwapDialog(row.id, swapBtn);
			});
			tdSwap.appendChild(swapBtn);
			tr.appendChild(tdSwap);
			tbody.appendChild(tr);
		}
	}

	let swapCandidatesLoaded = false;
	let swapCandidates = [];

	async function ensureSwapCandidates() {
		if (swapCandidatesLoaded) return swapCandidates;
		try {
			const res = await Api.get('/apps/dutycheck/api/my/swap-candidates');
			swapCandidates = Array.isArray(res?.data) ? res.data : [];
		} catch (err) {
			swapCandidates = [];
			Msg.handleApiError(err);
		}
		swapCandidatesLoaded = true;
		return swapCandidates;
	}

	/**
	 * Focus trap + Escape for the native <dialog id="dc-swap-dialog">.
	 *
	 * Farm defect classes handled here:
	 * - Chrome lets Shift+Tab leave a modal <dialog> backwards onto <body>;
	 *   once focus is outside, Escape never reaches the dialog and the
	 *   notifications app's global Escape handling can suppress the cancel
	 *   default. Trap Tab at both boundaries and close Escape explicitly.
	 * - On close, if the trigger was destroyed by a re-render, focus lands on
	 *   <body> — fall back to the main landmark instead.
	 */
	function wireSwapDialogA11y() {
		const dialog = document.getElementById('dc-swap-dialog');
		if (!dialog || dialog.dataset.dcA11yWired === '1') return;
		dialog.dataset.dcA11yWired = '1';
		const FOCUSABLE = 'a[href],input:not([disabled]):not([type="hidden"]),select:not([disabled]),textarea:not([disabled]),button:not([disabled]),[tabindex]:not([tabindex="-1"])';
		dialog.addEventListener('keydown', (event) => {
			if (event.key === 'Escape') {
				event.preventDefault();
				event.stopPropagation();
				dialog.close();
				return;
			}
			if (event.key !== 'Tab') return;
			const list = Array.from(dialog.querySelectorAll(FOCUSABLE))
				.filter((node) => node.offsetParent !== null || node === document.activeElement);
			if (list.length === 0) {
				event.preventDefault();
				return;
			}
			const first = list[0];
			const last = list[list.length - 1];
			if (event.shiftKey && document.activeElement === first) {
				event.preventDefault();
				last.focus();
			} else if (!event.shiftKey && document.activeElement === last) {
				event.preventDefault();
				first.focus();
			}
		});
		dialog.addEventListener('close', () => {
			const active = document.activeElement;
			if (!active || active === document.body || active === document.documentElement || !active.isConnected) {
				document.getElementById('dc-main-content')?.focus({ preventScroll: true });
			}
		});
	}

	async function openSwapDialog(assignmentId, triggerBtn) {
		const dialog = document.getElementById('dc-swap-dialog');
		const form = document.getElementById('dc-swap-form');
		const select = document.getElementById('dc-swap-colleague');
		const idInput = document.getElementById('dc-swap-assignment-id');
		const reason = document.getElementById('dc-swap-reason');
		if (!dialog || !form || !select || !idInput) {
			Msg.announce(t('dutycheck', 'Swap dialog is not available.'), 'error');
			return;
		}
		idInput.value = String(assignmentId);
		if (reason) reason.value = '';
		const rows = await ensureSwapCandidates();
		select.replaceChildren();
		const pool = document.createElement('option');
		pool.value = '';
		pool.textContent = t('dutycheck', 'Open pool (anyone can claim)');
		select.appendChild(pool);
		for (const row of rows) {
			const opt = document.createElement('option');
			opt.value = String(row.id);
			opt.textContent = row.displayName || `#${row.id}`;
			select.appendChild(opt);
		}
		select.value = '';
		if (typeof dialog.showModal === 'function') {
			dialog.showModal();
		} else {
			dialog.setAttribute('open', 'open');
		}
		form.onsubmit = async (event) => {
			event.preventDefault();
			const submitter = event.submitter;
			const action = submitter && submitter.value ? submitter.value : 'confirm';
			if (action === 'cancel') {
				dialog.close();
				return;
			}
			const toRaw = String(select.value || '').trim();
			const toEmployeeId = toRaw === '' ? null : Number.parseInt(toRaw, 10);
			if (toEmployeeId !== null && (!Number.isFinite(toEmployeeId) || toEmployeeId <= 0)) {
				Msg.announce(t('dutycheck', 'Choose a colleague, or keep Open pool.'), 'error');
				return;
			}
			triggerBtn.disabled = true;
			try {
				await Api.post('/apps/dutycheck/api/swaps', {
					assignmentId,
					toEmployeeId,
					reason: reason ? String(reason.value || '') : '',
				});
				dialog.close();
				Msg.announce(t('dutycheck', 'Swap request sent to planners.'), 'success');
			} catch (err) {
				triggerBtn.disabled = false;
				Msg.handleApiError(err);
			}
		};
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
			C.renderTableFetchError(tbody, TABLE_COLSPAN, t('dutycheck', 'Could not load your roster. Retry, or contact an administrator if this keeps happening.'), { retry: () => fetchAndRender() });
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


	async function loadOpenShifts() {
		const list = document.getElementById('dc-open-shifts-list');
		const empty = document.getElementById('dc-open-shifts-empty');
		if (!list) return;
		list.replaceChildren();
		try {
			const res = await Api.get('/apps/dutycheck/api/open-shifts');
			const rows = Array.isArray(res?.data) ? res.data : [];
			if (empty) empty.hidden = rows.length > 0;
			for (const row of rows) {
				const li = create('li', { class: 'dc-conflicts__item' });
				const when = D?.formatDisplayDate?.(row.dutyDate) || row.dutyDate;
				const times = D?.formatClock24Range?.(row.startTime, row.endTime)
					|| `${row.startTime} – ${row.endTime}`;
				li.appendChild(create('p', {
					text: t('dutycheck', '{date} · {times}').replace('{date}', String(when)).replace('{times}', String(times)),
				}));
				const btn = create('button', {
					type: 'button',
					class: 'button primary',
					text: t('dutycheck', 'Claim shift'),
				});
				btn.style.minHeight = '44px';
				btn.addEventListener('click', async () => {
					btn.disabled = true;
					try {
						await Api.post(`/apps/dutycheck/api/open-shifts/${row.id}/claim`, {});
						Msg.announce(t('dutycheck', 'Claim sent — a planner must approve before it is on your roster.'), 'success');
						await loadOpenShifts();
					} catch (err) {
						btn.disabled = false;
						Msg.handleApiError(err);
					}
				});
				li.appendChild(btn);
				list.appendChild(li);
			}
		} catch (err) {
			C.renderInlineFetchError?.(empty, t('dutycheck', 'Could not load open shifts.'), () => loadOpenShifts());
		}
	}

	async function wireAvailability() {
		const section = document.getElementById('dc-my-availability');
		if (!section) return;
		const prefsBlock = document.getElementById('dc-my-prefs');
		const blackoutsBlock = document.getElementById('dc-my-blackouts');
		const status = document.getElementById('dc-my-availability-status');
		const setAvailStatus = (text) => {
			if (!status) return;
			status.hidden = !text;
			status.textContent = text || '';
		};

		let prefsOk = false;
		let blackoutsOk = false;

		async function loadPrefs() {
			const list = document.getElementById('dc-my-prefs-list');
			if (!list) return;
			list.replaceChildren();
			try {
				const res = await Api.get('/apps/dutycheck/api/my/preferences');
				const rows = res?.data?.preferences || [];
				prefsOk = true;
				if (!rows.length) {
					list.appendChild(create('li', { class: 'dc-field__hint', text: t('dutycheck', 'No wishes yet.') }));
					return;
				}
				rows.forEach((row) => {
					const band = String(row.band || '');
					const label = band === 'early'
						? t('dutycheck', 'Früh')
						: (band === 'late' ? t('dutycheck', 'Spät') : band);
					const li = create('li', { class: 'dc-my-availability__row' }, [
						create('span', { class: 'dc-pref-chip dc-pref-chip--static', text: label }),
						create('button', {
							type: 'button',
							class: 'button',
							text: t('dutycheck', 'Remove'),
							on: {
								click: async () => {
									try {
										await Api.del(`/apps/dutycheck/api/my/preferences/${row.id}`);
										await loadPrefs();
									} catch (err) {
										Msg.handleApiError(err);
									}
								},
							},
						}),
					]);
					list.appendChild(li);
				});
			} catch (err) {
				const code = String(err?.code || err?.payload?.error?.code || '');
				if (code === 'PREFERENCES_DISABLED' || code === 'SCHEMA_NOT_READY') {
					prefsOk = false;
					return;
				}
				Msg.handleApiError(err);
			}
		}

		async function loadBlackouts() {
			const list = document.getElementById('dc-my-blackouts-list');
			if (!list) return;
			list.replaceChildren();
			const from = new Date();
			const to = new Date();
			to.setDate(to.getDate() + 90);
			const p = (n) => String(n).padStart(2, '0');
			const iso = (d) => `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`;
			try {
				const res = await Api.get('/apps/dutycheck/api/my/blackouts', { from: iso(from), to: iso(to) });
				const rows = res?.data?.blackouts || [];
				blackoutsOk = true;
				if (!rows.length) {
					list.appendChild(create('li', { class: 'dc-field__hint', text: t('dutycheck', 'No cannot-work days yet.') }));
					return;
				}
				rows.forEach((row) => {
					const start = String(row.startAt || row.start_at || '').slice(0, 10);
					const end = String(row.endAt || row.end_at || '').slice(0, 10);
					const label = String(row.label || row.labelEnum || '');
					const li = create('li', { class: 'dc-my-availability__row' }, [
						create('span', { text: `${start} → ${end}${label ? ` (${label})` : ''}` }),
						create('button', {
							type: 'button',
							class: 'button',
							text: t('dutycheck', 'Remove'),
							on: {
								click: async () => {
									try {
										await Api.del(`/apps/dutycheck/api/my/blackouts/${row.id}`);
										await loadBlackouts();
									} catch (err) {
										Msg.handleApiError(err);
									}
								},
							},
						}),
					]);
					list.appendChild(li);
				});
			} catch (err) {
				const code = String(err?.code || err?.payload?.error?.code || '');
				if (code === 'BLACKOUTS_DISABLED' || code === 'SCHEMA_NOT_READY' || code === 'ROTATION_DISABLED') {
					blackoutsOk = false;
					return;
				}
				Msg.handleApiError(err);
			}
		}

		await Promise.all([loadPrefs(), loadBlackouts()]);
		if (!prefsOk && !blackoutsOk) {
			section.hidden = true;
			return;
		}
		section.hidden = false;
		if (prefsBlock) prefsBlock.hidden = !prefsOk;
		if (blackoutsBlock) blackoutsBlock.hidden = !blackoutsOk;

		section.querySelectorAll('.dc-pref-chip[data-band]').forEach((btn) => {
			btn.addEventListener('click', async () => {
				const band = btn.getAttribute('data-band');
				try {
					await Api.post('/apps/dutycheck/api/my/preferences', {
						band,
						weekdayMask: 127,
					});
					setAvailStatus(section.getAttribute('data-msg-pref-saved') || '');
					Msg.announce(section.getAttribute('data-msg-pref-saved') || '', 'success');
					await loadPrefs();
				} catch (err) {
					Msg.handleApiError(err);
				}
			});
		});

		document.getElementById('dc-my-blackout-form')?.addEventListener('submit', async (event) => {
			event.preventDefault();
			const from = String(document.getElementById('dc-my-bo-from')?.value || '');
			const to = String(document.getElementById('dc-my-bo-to')?.value || '');
			const label = String(document.getElementById('dc-my-bo-label')?.value || 'personal');
			if (!from || !to) return;
			try {
				await Api.post('/apps/dutycheck/api/my/blackouts', {
					startAt: `${from} 00:00:00`,
					endAt: `${to} 23:59:59`,
					label,
				});
				setAvailStatus(section.getAttribute('data-msg-blackout-saved') || '');
				Msg.announce(section.getAttribute('data-msg-blackout-saved') || '', 'success');
				await loadBlackouts();
			} catch (err) {
				Msg.handleApiError(err);
			}
		});
	}

	async function wireTeamWeek() {
		const section = document.getElementById('dc-my-team');
		if (!section) return;

		const locSelect = document.getElementById('dc-my-team-location');
		const weekInput = document.getElementById('dc-my-team-week');
		const list = document.getElementById('dc-my-team-list');
		const status = document.getElementById('dc-my-team-status');
		const disabledBox = document.getElementById('dc-my-team-disabled');
		const disabledText = document.getElementById('dc-my-team-disabled-text');

		function setTeamStatus(text, kind) {
			if (!status) return;
			status.textContent = text || '';
			status.hidden = !text;
			status.classList.toggle('dc-roster-flash--error', kind === 'error');
		}

		function mondayOf(date) {
			const d = startOfDay(date);
			const day = (d.getDay() + 6) % 7;
			return addDays(d, -day);
		}

		function showDisabled(msgKey) {
			section.hidden = false;
			if (disabledBox) disabledBox.hidden = false;
			if (disabledText) {
				disabledText.textContent = section.getAttribute(msgKey) || '';
			}
			if (list) list.replaceChildren();
			const filters = document.getElementById('dc-my-team-filters');
			if (filters) filters.hidden = true;
		}

		function fillLocations(locations) {
			if (!locSelect) return;
			locSelect.replaceChildren();
			locations.forEach((loc, idx) => {
				const opt = document.createElement('option');
				opt.value = String(loc.id);
				opt.textContent = String(loc.name || loc.id);
				if (idx === 0) opt.selected = true;
				locSelect.appendChild(opt);
			});
		}

		async function loadTeam() {
			if (!locSelect || !weekInput || !list) return;
			const locationId = Number(locSelect.value || 0);
			let weekStart = String(weekInput.value || '');
			if (!weekStart) {
				weekStart = isoFromDate(mondayOf(new Date()));
				weekInput.value = weekStart;
			} else {
				// Snap to Monday of the picked date for granny-simple weeks.
				weekStart = isoFromDate(mondayOf(new Date(weekStart + 'T12:00:00')));
				weekInput.value = weekStart;
			}
			if (locationId < 1) {
				setTeamStatus(section.getAttribute('data-msg-no-loc') || '', 'error');
				return;
			}
			setTeamStatus(section.getAttribute('data-msg-loading') || '');
			list.replaceChildren();
			try {
				const res = await Api.get('/apps/dutycheck/api/team-week', {
					locationId,
					weekStart,
					page: 1,
					pageSize: 50,
				});
				const data = res?.data || {};
				const items = data.items || [];
				setTeamStatus('');
				if (!items.length) {
					list.appendChild(create('li', {
						class: 'dc-field__hint',
						text: section.getAttribute('data-msg-empty') || '',
					}));
					return;
				}
				items.forEach((row) => {
					const name = String(row.displayName || '');
					const date = String(row.dutyDate || '');
					const start = String(row.startTime || '').slice(0, 5);
					const end = String(row.endTime || '').slice(0, 5);
					const loc = String(row.locationName || '');
					const label = `${name}: ${date} ${start}–${end}${loc ? ` · ${loc}` : ''}`;
					list.appendChild(create('li', {
						class: 'dc-my-team__row',
						attrs: { 'aria-label': label },
					}, [
						create('strong', { class: 'dc-my-team__name', text: name }),
						create('span', { class: 'dc-my-team__when', text: `${date} · ${start}–${end}` }),
						loc ? create('span', { class: 'dc-my-team__loc', text: loc }) : null,
					].filter(Boolean)));
				});
			} catch (err) {
				const code = String(err?.code || err?.payload?.error?.code || '');
				if (code === 'PEER_VISIBILITY_DISABLED') {
					showDisabled('data-msg-disabled');
					return;
				}
				if (code === 'FORBIDDEN') {
					showDisabled('data-msg-no-loc');
					return;
				}
				setTeamStatus(section.getAttribute('data-msg-error') || '', 'error');
				Msg.handleApiError(err);
			}
		}

		try {
			const res = await Api.get('/apps/dutycheck/api/team-locations');
			const locations = res?.data?.locations || [];
			if (!locations.length) {
				// Peer may be on but no recent belonging — still show section with hint.
				section.hidden = false;
				showDisabled('data-msg-no-loc');
				return;
			}
			section.hidden = false;
			if (disabledBox) disabledBox.hidden = true;
			const filters = document.getElementById('dc-my-team-filters');
			if (filters) filters.hidden = false;
			fillLocations(locations);
			weekInput.value = isoFromDate(mondayOf(new Date()));
			document.getElementById('dc-my-team-filters')?.addEventListener('submit', (e) => {
				e.preventDefault();
				void loadTeam();
			});
			document.getElementById('dc-my-team-prev')?.addEventListener('click', () => {
				const cur = mondayOf(new Date((weekInput.value || isoFromDate(new Date())) + 'T12:00:00'));
				weekInput.value = isoFromDate(addDays(cur, -7));
				void loadTeam();
			});
			document.getElementById('dc-my-team-next')?.addEventListener('click', () => {
				const cur = mondayOf(new Date((weekInput.value || isoFromDate(new Date())) + 'T12:00:00'));
				weekInput.value = isoFromDate(addDays(cur, 7));
				void loadTeam();
			});
			document.getElementById('dc-my-team-now')?.addEventListener('click', () => {
				weekInput.value = isoFromDate(mondayOf(new Date()));
				void loadTeam();
			});
			await loadTeam();
		} catch (err) {
			const code = String(err?.code || err?.payload?.error?.code || '');
			if (code === 'PEER_VISIBILITY_DISABLED' || code === 'FORBIDDEN') {
				showDisabled('data-msg-disabled');
				return;
			}
			section.hidden = true;
		}
	}

	document.addEventListener('DOMContentLoaded', async () => {
		D?.applyLocaleToTemporalInputs?.(document);
		hideAccountAlert();
		applyRangeKey(DEFAULT_RANGE);
		bindQuickFilters();
		bindCustomRangeForm();
		await Promise.all([
			fetchAndRender(),
			loadOpenShifts(),
			loadIcalMeta(),
			wireAvailability(),
			wireTeamWeek(),
		]);
		applyIcalAtDisclosure();
		wireSwapDialogA11y();
		document.getElementById('dc-ical-rotate-button')?.addEventListener('click', rotateIcalToken);
		document.getElementById('dc-ical-copy-button')?.addEventListener('click', copyIcalUrl);
	});
})();
