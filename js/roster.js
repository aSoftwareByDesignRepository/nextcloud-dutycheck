(function () {
	'use strict';

	const Api = window.DutyCheckApi;
	const Msg = window.DutyCheckMessaging;
	const C = window.DutyCheckComponents;
	const D = window.DutyCheckDates;
	const ConflictLabels = window.DutyCheckConflictLabels;
	const create = C.createElement;

	const BREAK_STORAGE_KEY = 'dutycheck.roster.lastBreakMinutes';

	const state = {
		periods: [],
		employees: [],
		locations: [],
		assignments: [],
		absenceBlocks: [],
		canCreateAssignments: false,
		lastRosterData: null,
		defaultBreakMinutes: 0,
	};
	let assignmentModalInstance = null;
	let suggestedBreakMinutes = 0;

	function clampBreakMinutes(value) {
		const n = Number(value);
		if (!Number.isFinite(n)) {
			return 0;
		}
		return Math.max(0, Math.min(720, Math.round(n)));
	}

	function readStoredBreakMinutes() {
		try {
			const raw = sessionStorage.getItem(BREAK_STORAGE_KEY);
			if (raw === null || raw === '') {
				return null;
			}
			return clampBreakMinutes(raw);
		} catch (_) {
			return null;
		}
	}

	function rememberBreakMinutes(minutes) {
		try {
			sessionStorage.setItem(BREAK_STORAGE_KEY, String(clampBreakMinutes(minutes)));
		} catch (_) {
			/* ignore quota / privacy mode */
		}
	}

	function resolveBreakPrefill() {
		const org = clampBreakMinutes(state.defaultBreakMinutes);
		// Organisation default from Settings always wins when it is greater than zero.
		if (org > 0) {
			return { minutes: org, source: 'settings' };
		}
		const stored = readStoredBreakMinutes();
		if (stored !== null) {
			return { minutes: stored, source: 'last' };
		}
		return { minutes: 0, source: 'settings' };
	}

	async function refreshPlanningDefaultFromServer() {
		try {
			const response = await Api.get('/apps/dutycheck/api/admin/planning-defaults');
			const minutes = Number(response?.planning?.defaultBreakMinutes);
			if (Number.isFinite(minutes)) {
				state.defaultBreakMinutes = clampBreakMinutes(minutes);
			}
		} catch (_) {
			/* Fall back to defaultBreakMinutes from the last roster API load. */
		}
	}

	function breakPrefillMessage(prefill) {
		const n = String(prefill.minutes);
		if (prefill.source === 'last') {
			return t('dutycheck', 'Pre-filled with {n} min — same as your last shift.').replace('{n}', n);
		}
		if (prefill.minutes === 0) {
			return t('dutycheck', 'Pre-filled with 0 min — no break.');
		}
		return t('dutycheck', 'Pre-filled with {n} min — organisation default in Settings.').replace('{n}', n);
	}

	function updateBreakPrefillHint(userEdited) {
		const el = document.getElementById('dc-assignment-break-prefill');
		if (!el) {
			return;
		}
		if (userEdited) {
			el.textContent = t('dutycheck', 'You changed the suggested break for this shift.');
			return;
		}
		el.textContent = breakPrefillMessage(resolveBreakPrefill());
	}

	function applyDefaultBreakToForm() {
		const prefill = resolveBreakPrefill();
		suggestedBreakMinutes = prefill.minutes;
		const br = document.getElementById('dc-assignment-break');
		if (br) {
			br.value = String(prefill.minutes);
		}
		updateBreakPrefillHint(false);
	}

	function wireBreakPrefillHint() {
		const br = document.getElementById('dc-assignment-break');
		if (!br || br.dataset.dcBreakPrefillWired) {
			return;
		}
		br.dataset.dcBreakPrefillWired = '1';
		br.addEventListener('input', () => {
			const current = clampBreakMinutes(br.value);
			updateBreakPrefillHint(current !== suggestedBreakMinutes);
		});
		br.addEventListener('change', () => {
			const current = clampBreakMinutes(br.value);
			if (current === suggestedBreakMinutes) {
				updateBreakPrefillHint(false);
			}
		});
	}

	function dateToDayIndex(isoDate) {
		const parts = String(isoDate || '').split('-').map((segment) => Number(segment));
		if (parts.length !== 3 || parts.some((n) => !Number.isFinite(n))) {
			return null;
		}
		return Math.floor(Date.UTC(parts[0], parts[1] - 1, parts[2]) / 86400000);
	}

	function assignmentAbsoluteRange(dutyDate, startTime, endTime) {
		const dayIndex = dateToDayIndex(dutyDate);
		const start = D?.wallClockMinutesFromTimeString?.(startTime);
		const end = D?.wallClockMinutesFromTimeString?.(endTime);
		if (dayIndex === null || start === null || end === null) {
			return null;
		}
		const absoluteStart = (dayIndex * 1440) + start;
		let absoluteEnd = (dayIndex * 1440) + end;
		if (absoluteEnd <= absoluteStart) {
			absoluteEnd += 1440;
		}
		return [absoluteStart, absoluteEnd];
	}

	function absoluteRangesOverlap(a, b) {
		return a[0] < b[1] && b[0] < a[1];
	}

	function isDateInAbsenceBlock(employeeId, dutyDate) {
		if (!dutyDate) {
			return false;
		}
		for (const block of state.absenceBlocks) {
			if (Number(block?.employeeId) !== Number(employeeId)) {
				continue;
			}
			const start = String(block?.startDate || '');
			const end = String(block?.endDate || '');
			if (dutyDate >= start && dutyDate <= end) {
				return true;
			}
		}
		return false;
	}

	function employeesAvailableOnDate(dutyDate) {
		if (!dutyDate) {
			return [];
		}
		return state.employees.filter((employee) => !isDateInAbsenceBlock(employee.id, dutyDate));
	}

	function countEmployeesBlockedOnDate(dutyDate) {
		if (!dutyDate) {
			return 0;
		}
		return state.employees.length - employeesAvailableOnDate(dutyDate).length;
	}

	function appendHintLink(parent, beforeText, linkText, afterText, href) {
		parent.appendChild(document.createTextNode(beforeText));
		if (href) {
			const link = create('a', {
				class: 'dc-hint-link',
				href,
				text: linkText,
			});
			parent.appendChild(link);
			parent.appendChild(document.createTextNode(afterText));
		} else {
			parent.appendChild(document.createTextNode(linkText + afterText));
		}
	}

	function setEmployeeAvailabilityHint(dutyDate, available, blocked) {
		const hint = document.getElementById('dc-assignment-employee-hint');
		if (!hint) {
			return;
		}
		const urls = readUrls();
		hint.replaceChildren();
		if (!dutyDate) {
			hint.appendChild(document.createTextNode(
				t('dutycheck', 'First pick a date above. Anyone on approved leave that day is left out of the list.'),
			));
			return;
		}
		if (!available.length && blocked > 0) {
			appendHintLink(
				hint,
				t('dutycheck', 'Nobody can work this day — all {n} employees are on approved leave. Pick another date or ')
					.replace('{n}', String(blocked)),
				t('dutycheck', 'review absences'),
				'.',
				String(urls.absences || ''),
			);
			return;
		}
		if (!available.length) {
			appendHintLink(
				hint,
				t('dutycheck', 'No employees are available. Add an active employee on the '),
				t('dutycheck', 'Employees'),
				t('dutycheck', ' page first.'),
				String(urls.employees || ''),
			);
			return;
		}
		if (blocked > 0) {
			hint.appendChild(document.createTextNode(
				t('dutycheck', '{available} can be scheduled · {hidden} on approved leave (not in the list)')
					.replace('{available}', String(available.length))
					.replace('{hidden}', String(blocked)),
			));
			return;
		}
		hint.appendChild(document.createTextNode(
			t('dutycheck', 'All {n} active employees can be scheduled on this date.')
				.replace('{n}', String(available.length)),
		));
	}

	function refreshEmployeeSelectForDate() {
		const dateInput = document.getElementById('dc-assignment-date');
		const dutyDate = dateInput ? String(dateInput.value || '') : '';
		const available = employeesAvailableOnDate(dutyDate);
		const blocked = countEmployeesBlockedOnDate(dutyDate);
		setEmployeeAvailabilityHint(dutyDate, available, blocked);
		fillSelect('dc-assignment-employee', available, {
			emptyText: dutyDate
				? t('dutycheck', 'No one is available on this date (approved leave). Pick another date.')
				: t('dutycheck', 'Pick a date first'),
		});
		const employeeSelect = document.getElementById('dc-assignment-employee');
		if (employeeSelect) {
			employeeSelect.disabled = !dutyDate || !available.length || employeeSelect.dataset.dcLockedBySetup === '1';
		}
	}

	function findOverlapAssignment(payload) {
		const candidate = assignmentAbsoluteRange(payload.dutyDate, payload.startTime, payload.endTime);
		if (!candidate) {
			return null;
		}
		for (const assignment of state.assignments) {
			if (Number(assignment?.employeeId) !== Number(payload.employeeId)) {
				continue;
			}
			const existing = assignmentAbsoluteRange(
				String(assignment?.dutyDate || ''),
				String(assignment?.startTime || ''),
				String(assignment?.endTime || ''),
			);
			if (existing && absoluteRangesOverlap(candidate, existing)) {
				return assignment;
			}
		}
		return null;
	}

	function setFormFeedbackA11y(el, isError) {
		if (!el) {
			return;
		}
		if (isError) {
			el.setAttribute('role', 'alert');
			el.setAttribute('aria-live', 'assertive');
		} else {
			el.setAttribute('role', 'status');
			el.setAttribute('aria-live', 'polite');
		}
	}

	function focusFirstInvalidAssignmentField(payload) {
		if (!payload) {
			return;
		}
		const period = selectedPeriodForPayload(payload);
		const order = [
			{ test: () => !payload.periodId, id: 'dc-roster-period-switcher' },
			{ test: () => period && String(period.status || '').toLowerCase() !== 'open', id: 'dc-roster-period-switcher' },
			{ test: () => !payload.dutyDate, id: 'dc-assignment-date' },
			{
				test: () => period?.startDate && period?.endDate && payload.dutyDate
					&& (payload.dutyDate < String(period.startDate) || payload.dutyDate > String(period.endDate)),
				id: 'dc-assignment-date',
			},
			{ test: () => !payload.employeeId, id: 'dc-assignment-employee' },
			{ test: () => !payload.locationId, id: 'dc-assignment-location' },
			{ test: () => !payload.startTime, id: 'dc-assignment-start' },
			{ test: () => !payload.endTime, id: 'dc-assignment-end' },
			{ test: () => payload.startTime && payload.endTime && payload.startTime === payload.endTime, id: 'dc-assignment-start' },
			{
				test: () => {
					const effective = effectiveWorkMinutes(payload.startTime, payload.endTime, payload.breakMinutes);
					return effective === null || effective <= 0;
				},
				id: 'dc-assignment-end',
			},
		];
		for (const item of order) {
			if (item.test()) {
				document.getElementById(item.id)?.focus();
				return;
			}
		}
	}

	function selectedPeriodForPayload(payload) {
		const periodId = Number(payload?.periodId);
		if (!Number.isInteger(periodId) || periodId <= 0) {
			return null;
		}
		return state.periods.find((p) => Number(p.id) === periodId) || null;
	}

	function clearAssignmentFormSuccess() {
		const el = document.getElementById('dc-assignment-form-feedback');
		if (!el || el.dataset.dcSuccess !== '1') {
			return;
		}
		el.hidden = true;
		el.textContent = '';
		el.dataset.dcSuccess = '';
		el.classList.remove('dc-roster-form__feedback--success', 'dc-roster-form__feedback--info');
		setFormFeedbackA11y(el, false);
	}

	function showAssignmentFormSuccess(message, kind) {
		const el = document.getElementById('dc-assignment-form-feedback');
		const submit = document.querySelector('#dc-assignment-form button[type="submit"]');
		if (!el) {
			return;
		}
		const tone = kind === 'info' ? 'info' : 'success';
		el.hidden = false;
		el.textContent = String(message);
		el.dataset.dcSuccess = '1';
		el.classList.remove('dc-roster-form__feedback--error');
		el.classList.toggle('dc-roster-form__feedback--success', tone === 'success');
		el.classList.toggle('dc-roster-form__feedback--info', tone === 'info');
		setFormFeedbackA11y(el, false);
		if (submit) {
			submit.disabled = submit.dataset.dcLockedBySetup === '1';
		}
		el.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
	}

	function labelsForAssignmentPayload(payload) {
		const employee = state.employees.find((row) => Number(row.id) === Number(payload?.employeeId));
		const location = state.locations.find((row) => Number(row.id) === Number(payload?.locationId));
		const employeeSelect = document.getElementById('dc-assignment-employee');
		const locationSelect = document.getElementById('dc-assignment-location');
		const employeeName = employee
			? String(employee.name || employee.displayName || '')
			: (employeeSelect?.selectedOptions?.[0]?.textContent || '').trim();
		const locationName = location
			? String(location.name || '')
			: (locationSelect?.selectedOptions?.[0]?.textContent || '').trim();
		return { employeeName, locationName };
	}

	function buildAssignmentSavedMessage(payload) {
		const { employeeName, locationName } = labelsForAssignmentPayload(payload);
		const dateLabel = D?.formatDisplayDate?.(payload.dutyDate) || payload.dutyDate;
		const times = D?.formatClock24Range?.(payload.startTime, payload.endTime)
			|| `${payload.startTime} – ${payload.endTime}`;
		return t(
			'dutycheck',
			'Assignment saved — {employee} on {date}, {times}, at {location}. The list above is updated.',
		)
			.replace('{employee}', employeeName || '—')
			.replace('{date}', String(dateLabel || ''))
			.replace('{times}', String(times))
			.replace('{location}', locationName || '—');
	}

	function updateAssignmentFormFeedback(payload) {
		const el = document.getElementById('dc-assignment-form-feedback');
		const submit = document.querySelector('#dc-assignment-form button[type="submit"]');
		if (!el) {
			return;
		}
		if (el.dataset.dcSuccess === '1') {
			return;
		}
		if (typeof payload === 'string') {
			el.hidden = false;
			el.textContent = payload;
			el.classList.remove('dc-roster-form__feedback--success', 'dc-roster-form__feedback--info');
			el.classList.add('dc-roster-form__feedback--error');
			setFormFeedbackA11y(el, true);
			if (submit) {
				submit.disabled = false;
			}
			return;
		}
		if (!payload) {
			el.hidden = true;
			el.textContent = '';
			el.classList.remove('dc-roster-form__feedback--error', 'dc-roster-form__feedback--success', 'dc-roster-form__feedback--info');
			setFormFeedbackA11y(el, false);
			return;
		}
		let message = '';
		let isError = false;
		if (payload && payload.employeeId > 0 && payload.dutyDate && isDateInAbsenceBlock(payload.employeeId, payload.dutyDate)) {
			message = t('dutycheck', 'This person is on approved leave on the selected date and cannot be scheduled.');
			isError = true;
		} else if (payload && payload.employeeId > 0 && payload.dutyDate && payload.startTime && payload.endTime) {
			const overlap = findOverlapAssignment(payload);
			if (overlap) {
				const when = D?.formatDisplayDate?.(overlap.dutyDate) || overlap.dutyDate;
				const times = D?.formatClock24Range?.(overlap.startTime, overlap.endTime)
					|| `${overlap.startTime} – ${overlap.endTime}`;
				message = t('dutycheck', 'These times overlap an existing shift on {date} ({times}). Change the times or choose another employee.')
					.replace('{date}', String(when))
					.replace('{times}', String(times));
				isError = true;
			}
		}
		if (!message) {
			el.hidden = true;
			el.textContent = '';
			el.classList.remove('dc-roster-form__feedback--error', 'dc-roster-form__feedback--success', 'dc-roster-form__feedback--info');
			setFormFeedbackA11y(el, false);
			if (submit && submit.dataset.dcLockedBySetup !== '1') {
				submit.disabled = false;
			}
			return;
		}
		el.hidden = false;
		el.textContent = message;
		el.classList.toggle('dc-roster-form__feedback--error', isError);
		setFormFeedbackA11y(el, isError);
		if (submit && isError) {
			submit.disabled = true;
		}
	}

	function refreshAssignmentFormEligibility() {
		refreshEmployeeSelectForDate();
		updateLocationTimezoneHint();
		const form = document.getElementById('dc-assignment-form');
		if (!form) {
			return;
		}
		updateAssignmentFormFeedback(readForm(form));
	}

	function updateLocationTimezoneHint() {
		const hint = document.getElementById('dc-assignment-location-tz-hint');
		const select = document.getElementById('dc-assignment-location');
		if (!hint || !select) {
			return;
		}
		const locationId = Number(select.value);
		const location = state.locations.find((row) => Number(row.id) === locationId);
		const tz = location && String(location.timezone || '').trim();
		if (!tz) {
			hint.hidden = true;
			hint.replaceChildren();
			return;
		}
		const accountTz = D?.currentTimezone?.() || '';
		hint.textContent = t(
			'dutycheck',
			'Shift times use the location timezone ({locationTimezone}). Change it on the Locations page. Your account timezone ({accountTimezone}) is shown in the header.',
		)
			.replace('{locationTimezone}', tz)
			.replace('{accountTimezone}', accountTz || 'UTC');
		hint.hidden = false;
	}

	function fillSelect(id, items, options) {
		const opts = Object.assign({ labelKey: 'name', emptyText: t('dutycheck', 'No options available') }, options || {});
		const select = document.getElementById(id);
		if (!select) return;
		const previous = String(select.value || '');
		select.replaceChildren();
		if (!items.length) {
			const option = document.createElement('option');
			option.value = '';
			option.textContent = opts.emptyText;
			select.appendChild(option);
			select.disabled = true;
			return;
		}
		select.disabled = false;
		for (const item of items) {
			const option = document.createElement('option');
			option.value = String(item.id);
			option.textContent = String(item[opts.labelKey] || item.name || item.id);
			select.appendChild(option);
		}
		if (previous && Array.from(select.options).some((opt) => opt.value === previous)) {
			select.value = previous;
		}
	}

	function fillPeriodSwitcher(periods, selected) {
		const select = document.getElementById('dc-roster-period-switcher');
		if (!select) return;
		const statusLabelMap = {
			open: t('dutycheck', 'Open'),
			published: t('dutycheck', 'Published'),
			closed: t('dutycheck', 'Closed'),
		};
		select.replaceChildren();
		if (!periods.length) {
			const option = document.createElement('option');
			option.value = '';
			option.textContent = t('dutycheck', 'No periods yet');
			select.appendChild(option);
			select.disabled = true;
			return;
		}
		select.disabled = false;
		for (const period of periods) {
			const option = document.createElement('option');
			option.value = String(period.id);
			const start = D?.formatDisplayDate(period.startDate) || period.startDate;
			const end = D?.formatDisplayDate(period.endDate) || period.endDate;
			const statusKey = String(period.status || '').toLowerCase();
			const status = statusLabelMap[statusKey] || String(period.status || '').toUpperCase();
			option.textContent = `${start} – ${end} · ${status}`;
			select.appendChild(option);
		}
		if (selected) {
			select.value = String(selected);
		}
	}

	function severityBadge(severity) {
		const sev = String(severity || 'info');
		const label = ConflictLabels ? ConflictLabels.severityLabel(severity) : sev;
		const badge = create('span', { class: 'dc-severity dc-severity--' + sev, text: label });
		badge.setAttribute('aria-label', label);
		return badge;
	}

	function renderConflicts(conflicts) {
		const list = document.getElementById('dc-conflict-list');
		const summary = document.getElementById('dc-conflict-summary');
		if (!list) return;
		list.setAttribute('role', 'list');
		list.setAttribute('aria-label', t('dutycheck', 'Planning checks'));
		list.replaceChildren();
		const hard = conflicts.filter((c) => c?.severity === 'hard').length;
		const soft = conflicts.filter((c) => c?.severity === 'soft').length;
		const softUnack = conflicts.filter((c) => c?.severity === 'soft' && !c?.acknowledged).length;
		if (summary) {
			summary.textContent = !conflicts.length
				? t('dutycheck', 'No planning issues found.')
				: (ConflictLabels
					? ConflictLabels.countsSummary(hard, soft, softUnack)
					: String(hard));
		}
		if (!conflicts.length) {
			return;
		}
		for (const conflict of conflicts) {
			const li = create('li', { class: 'dc-conflict dc-conflict--' + (conflict?.severity || 'info') });
			li.appendChild(severityBadge(conflict?.severity));
			const conflictMessage = String(conflict?.message || '');
			const titleText = conflictMessage
				? translateConflictMessage(conflictMessage)
				: t('dutycheck', 'Unknown conflict');
			const titleId = `dc-conflict-title-${String(conflict?.id || conflicts.indexOf(conflict))}`;
			const body = create('div', { class: 'dc-conflict__body' });
			const title = create('span', { class: 'dc-conflict__title', text: titleText });
			title.id = titleId;
			body.appendChild(title);
			li.setAttribute('aria-labelledby', titleId);
			if (conflict?.acknowledged && conflict?.ackReason) {
				body.appendChild(create('span', {
					class: 'dc-conflict__ack',
					text: t('dutycheck', 'Confirmed: {reason}').replace('{reason}', String(conflict.ackReason)),
				}));
			} else if (conflict?.ackInvalidated) {
				body.appendChild(create('span', {
					class: 'dc-conflict__ack-invalid',
					text: t('dutycheck', 'The roster changed — please confirm again with a new reason.'),
				}));
			}
			li.appendChild(body);

			const actions = create('div', { class: 'dc-conflict__actions' });
			if (conflict?.severity === 'soft' && conflict?.id && !conflict?.acknowledged) {
				const ackBtn = create('button', {
					type: 'button',
					class: 'button',
					text: t('dutycheck', 'Confirm'),
				});
				ackBtn.addEventListener('click', () => acknowledgeConflict(conflict.id));
				actions.appendChild(ackBtn);
			}
			li.appendChild(actions);
			list.appendChild(li);
		}
	}

	async function acknowledgeConflict(conflictId) {
		const reason = await C.promptReason({
			title: t('dutycheck', 'Confirm this exception'),
			label: t('dutycheck', 'Briefly explain why you are allowing this (minimum 10 characters)'),
			confirmLabel: t('dutycheck', 'Confirm'),
			cancelLabel: t('dutycheck', 'Cancel'),
			minLength: 10,
		});
		if (reason === null) return;
		try {
			const response = await Api.post(`/apps/dutycheck/api/conflicts/${conflictId}/acknowledge`, { reason });
			render(response?.data || {});
			const ackMsg = t('dutycheck', 'Exception confirmed. Planning checks are updated.');
			Msg.announce(ackMsg, 'success');
		} catch (err) {
			Msg.handleApiError(err);
		}
	}

	function setAssignmentsEmptyPanelVisible(showEmptyPanel) {
		const emptyPanel = document.getElementById('dc-roster-assignments-empty');
		if (emptyPanel) {
			emptyPanel.hidden = !showEmptyPanel;
		}
	}

	function renderAssignments(assignments) {
		const tbody = document.getElementById('dc-assignments-table-body');
		if (!tbody) return;
		const overnightHintText = t('dutycheck', 'Continues into the next day.');
		const startClock = (s) => (D?.formatClock24FromTimeString(s) || String(s ?? ''));
		const endCell = (a) => {
			const td = create('td');
			td.dataset.cell = t('dutycheck', 'End');
			td.appendChild(create('span', { class: 'dc-time-cell__value', text: startClock(a.endTime) }));
			if (D?.isOvernightWallClockShift?.(a.startTime, a.endTime)) {
				td.appendChild(create('span', { class: 'dc-row-meta', text: overnightHintText }));
			}
			return td;
		};
		tbody.replaceChildren();
		if (!assignments.length) {
			setAssignmentsEmptyPanelVisible(true);
			updateAddAssignmentControl();
			return;
		}
		setAssignmentsEmptyPanelVisible(false);
		for (const a of assignments) {
			const tr = create('tr');
			const date = D?.formatDisplayDate(a.dutyDate) || a.dutyDate;
			const tdDate = create('td', { text: date });
			tdDate.dataset.cell = t('dutycheck', 'Date');
			tr.appendChild(tdDate);

			const tdStart = create('td');
			tdStart.dataset.cell = t('dutycheck', 'Start');
			tdStart.appendChild(create('span', { class: 'dc-time-cell__value', text: startClock(a.startTime) }));
			tr.appendChild(tdStart);
			tr.appendChild(endCell(a));
			const rest = [
				{ label: t('dutycheck', 'Employee'), value: a.employeeName || '' },
				{ label: t('dutycheck', 'Location'), value: a.locationName || '' },
				{ label: t('dutycheck', 'Break'), value: t('dutycheck', '{n} min').replace('{n}', String(a.breakMinutes ?? 0)) },
				{ label: t('dutycheck', 'Note'), value: a.note || '' },
			];
			for (const cell of rest) {
				const td = create('td', { text: String(cell.value ?? '') });
				td.dataset.cell = cell.label;
				tr.appendChild(td);
			}
			tbody.appendChild(tr);
		}
	}

	function uniqueConflictTypes(conflicts) {
		const types = new Set();
		for (const conflict of conflicts || []) {
			if (conflict?.severity === 'soft' && conflict?.type) {
				types.add(String(conflict.type));
			}
		}
		return Array.from(types);
	}

	function softConflictSummary(conflicts) {
		const lines = [];
		for (const conflict of conflicts || []) {
			if (conflict?.severity !== 'soft') {
				continue;
			}
			const raw = String(conflict?.message || '');
			const text = raw ? translateConflictMessage(raw) : t('dutycheck', 'Unknown conflict');
			if (text && !lines.includes(text)) {
				lines.push(text);
			}
		}
		return lines.join('\n');
	}

	function errorCodeFrom(err) {
		return String(err?.code || err?.payload?.error?.code || '');
	}

	function syncAssignmentPeriodFromSwitcher() {
		const switcher = document.getElementById('dc-roster-period-switcher');
		const periodHidden = document.getElementById('dc-assignment-period');
		if (!switcher || !periodHidden) {
			return;
		}
		const selected = Number(switcher.value);
		if (Number.isInteger(selected) && selected > 0) {
			periodHidden.value = String(selected);
		}
	}

	function announceAssignmentSaveError(err) {
		clearAssignmentFormSuccess();
		const friendly = errorMessageFor(err);
		const message = friendly || t('dutycheck', 'Something went wrong. Please try again, and contact an administrator if it keeps happening.');
		updateAssignmentFormFeedback(message);
		Msg.announce(message, 'error');
	}

	async function submitAssignment(payload, retryWithAck = true) {
		try {
			return await Api.post('/apps/dutycheck/api/assignments', payload);
		} catch (error) {
			const code = errorCodeFrom(error);
			if (retryWithAck && code === 'CONFLICT_ACK_REQUIRED') {
				const conflicts = error?.payload?.error?.conflicts || [];
				let conflictTypes = uniqueConflictTypes(conflicts);
				if (!conflictTypes.length) {
					conflictTypes = ['rest_time_violation'];
				}
				const summary = softConflictSummary(conflicts);
				const reason = await C.promptReason({
					title: t('dutycheck', 'Planning rule needs your confirmation'),
					label: summary
						? t('dutycheck', 'Briefly explain why you are scheduling anyway (at least 10 characters).\n\n{details}')
							.replace('{details}', summary)
						: t('dutycheck', 'Briefly explain why you are scheduling anyway (at least 10 characters).'),
					confirmLabel: t('dutycheck', 'Save with confirmation'),
					cancelLabel: t('dutycheck', 'Cancel'),
					minLength: 10,
				});
				if (reason === null || reason.length < 10) {
					throw error;
				}
				const acknowledgements = conflictTypes.map((type) => ({ conflictType: type, reason }));
				return submitAssignment({ ...payload, acknowledgements }, false);
			}
			throw error;
		}
	}

	function setAssignmentFormBusy(form, busy) {
		if (!form) return;
		const submit = form.querySelector('button[type="submit"]');
		const clear = document.getElementById('dc-assignment-form-clear');
		if (submit) {
			submit.disabled = busy || submit.dataset.dcLockedBySetup === '1';
			if (busy) {
				submit.setAttribute('aria-busy', 'true');
			} else {
				submit.removeAttribute('aria-busy');
			}
		}
		if (clear) {
			clear.disabled = busy;
		}
	}

	function renderSetupCallout(data) {
		const callout = document.getElementById('dc-roster-setup-callout');
		const list = document.getElementById('dc-roster-setup-checklist');
		const form = document.getElementById('dc-assignment-form');
		if (!callout || !list || !form) return;
		const urls = readUrls();
		const missing = [];
		if (!state.periods.length) {
			missing.push({
				text: t('dutycheck', 'Create a planning period.'),
				url: urls.periods,
				cta: t('dutycheck', 'Go to Periods'),
			});
		}
		if (!state.employees.length) {
			missing.push({
				text: t('dutycheck', 'Add at least one active employee.'),
				url: urls.employees,
				cta: t('dutycheck', 'Go to Employees'),
			});
		}
		if (!state.locations.length) {
			missing.push({
				text: t('dutycheck', 'Add at least one active location.'),
				url: urls.locations,
				cta: t('dutycheck', 'Go to Locations'),
			});
		}
		const selectedPeriod = state.periods.find((p) => Number(p.id) === Number(data?.selectedPeriodId));
		const periodIsOpen = (selectedPeriod?.status || '') === 'open';
		if (state.periods.length && data?.selectedPeriodId && !periodIsOpen) {
			missing.push({
				text: t('dutycheck', 'The selected period is read-only. Pick an open period or re-open this one.'),
				url: urls.periods,
				cta: t('dutycheck', 'Go to Periods'),
			});
		}
		list.replaceChildren();
		if (!missing.length) {
			callout.hidden = true;
			form.querySelectorAll('input, select, button[type="submit"]').forEach((el) => {
				if (el.dataset.dcLockedBySetup === '1') {
					el.disabled = false;
					delete el.dataset.dcLockedBySetup;
				}
			});
			updateAddAssignmentControl();
			return;
		}
		for (const item of missing) {
			const li = create('li');
			li.appendChild(document.createTextNode(item.text + ' '));
			if (item.url) {
				li.appendChild(create('a', {
					class: 'button',
					href: item.url,
					text: item.cta,
				}));
			}
			list.appendChild(li);
		}
		callout.hidden = false;
		form.querySelectorAll('input:not([type="hidden"]), select, button[type="submit"]').forEach((el) => {
			if (!el.disabled) {
				el.dataset.dcLockedBySetup = '1';
				el.disabled = true;
			}
		});
		updateAddAssignmentControl();
	}

	function readUrls() {
		try {
			const raw = document.getElementById('app-content')?.dataset?.dcUrls;
			if (!raw) return {};
			return JSON.parse(raw) || {};
		} catch (_) {
			return {};
		}
	}

	function restoreAssignmentFormPanel() {
		const host = document.getElementById('dc-assignment-form-host');
		const panel = document.getElementById('dc-assignment-form-panel');
		if (host && panel && panel.parentElement !== host) {
			host.appendChild(panel);
		}
	}

	function selectedPeriodFromState() {
		const switcher = document.getElementById('dc-roster-period-switcher');
		const raw = switcher?.value || document.getElementById('dc-assignment-period')?.value;
		const periodId = Number(raw);
		if (!Number.isInteger(periodId) || periodId <= 0) {
			return null;
		}
		return state.periods.find((p) => Number(p.id) === periodId) || null;
	}

	function isAssignmentSetupBlocking() {
		const callout = document.getElementById('dc-roster-setup-callout');
		return !!(callout && !callout.hidden);
	}

	function canAddAssignment() {
		if (!state.canCreateAssignments) {
			return false;
		}
		if (!state.periods.length || !state.employees.length || !state.locations.length) {
			return false;
		}
		const period = selectedPeriodFromState();
		return !!(period && String(period.status || '').toLowerCase() === 'open');
	}

	function addAssignmentDisabledReason() {
		if (!state.periods.length) {
			return t('dutycheck', 'Create a planning period first.');
		}
		if (!state.employees.length) {
			return t('dutycheck', 'Add at least one active employee before planning shifts.');
		}
		if (!state.locations.length) {
			return t('dutycheck', 'Add at least one active location before planning shifts.');
		}
		const period = selectedPeriodFromState();
		if (!period) {
			return t('dutycheck', 'Select a period before adding assignments.');
		}
		if (String(period.status || '').toLowerCase() !== 'open') {
			return t('dutycheck', 'The selected period is not open for planning.');
		}
		if (!state.canCreateAssignments) {
			return t('dutycheck', 'You cannot add assignments for this period.');
		}
		return t('dutycheck', 'Adding assignments is not available right now.');
	}

	function updateAddAssignmentControl() {
		const allowed = canAddAssignment();
		const reason = addAssignmentDisabledReason();
		document.querySelectorAll('.dc-roster-add-assignment-trigger').forEach((btn) => {
			btn.disabled = false;
			btn.setAttribute('aria-disabled', allowed ? 'false' : 'true');
			btn.classList.toggle('dc-is-disabled', !allowed);
			if (allowed) {
				btn.removeAttribute('title');
			} else {
				btn.setAttribute('title', reason);
			}
		});
		const emptyHint = document.getElementById('dc-roster-empty-add-hint');
		if (emptyHint) {
			emptyHint.textContent = allowed
				? t('dutycheck', 'Use “Add assignment” above to plan the first shift.')
				: reason;
		}
	}

	function clearAssignmentsSectionSuccess() {
		const el = document.getElementById('dc-roster-assignments-success');
		if (!el) {
			return;
		}
		el.hidden = true;
		el.textContent = '';
		el.classList.remove('dc-roster-flash--visible');
	}

	function showAssignmentsSectionSuccess(message) {
		const el = document.getElementById('dc-roster-assignments-success');
		if (!el) {
			return;
		}
		el.textContent = String(message);
		el.hidden = false;
		el.classList.add('dc-roster-flash--visible');
	}

	function prepareAssignmentFormForModal() {
		syncAssignmentPeriodFromSwitcher();
		const form = document.getElementById('dc-assignment-form');
		if (!form) {
			return;
		}
		clearAssignmentFormSuccess();
		updateAssignmentFormFeedback(null);
		const period = selectedPeriodFromState();
		const dateInput = document.getElementById('dc-assignment-date');
		if (dateInput && period?.startDate && period?.endDate) {
			dateInput.min = String(period.startDate);
			dateInput.max = String(period.endDate);
			if (!dateInput.value) {
				dateInput.value = String(period.startDate);
			}
		}
		refreshAssignmentFormEligibility();
		applyDefaultBreakToForm();
		wireBreakPrefillHint();
		D?.applyLocaleToTemporalInputs(form);
	}

	function resetAssignmentFormAfterSave(data) {
		const form = document.getElementById('dc-assignment-form');
		if (!form) {
			return;
		}
		form.reset();
		const periodHidden = document.getElementById('dc-assignment-period');
		if (periodHidden && data?.selectedPeriodId) {
			periodHidden.value = String(data.selectedPeriodId);
		}
		applyDefaultBreakToForm();
		const period = selectedPeriodFromState();
		const dateInput = document.getElementById('dc-assignment-date');
		if (dateInput && period?.startDate) {
			dateInput.value = String(period.startDate);
		}
		D?.applyLocaleToTemporalInputs(form);
		updateAssignmentFormFeedback(null);
	}

	async function performAssignmentSave() {
		syncAssignmentPeriodFromSwitcher();
		const form = document.getElementById('dc-assignment-form');
		if (!form) {
			return false;
		}
		if (isAssignmentSetupBlocking()) {
			const msg = t('dutycheck', 'Complete setup before adding assignments. See the checklist in this dialog.');
			clearAssignmentFormSuccess();
			updateAssignmentFormFeedback(msg);
			Msg.announce(msg, 'error');
			return false;
		}
		const payload = readForm(form);
		const validation = validate(payload);
		if (validation) {
			clearAssignmentFormSuccess();
			updateAssignmentFormFeedback(validation);
			Msg.announce(validation, 'error');
			focusFirstInvalidAssignmentField(payload);
			return false;
		}
		updateAssignmentFormFeedback(null);
		setAssignmentFormBusy(form, true);
		try {
			const response = await submitAssignment(payload, true);
			render(response?.data || {});
			const savedMsg = buildAssignmentSavedMessage(payload);
			clearAssignmentFormSuccess();
			showAssignmentsSectionSuccess(savedMsg);
			Msg.announce(savedMsg, 'success');
			rememberBreakMinutes(payload.breakMinutes);
			resetAssignmentFormAfterSave(response?.data || {});
			return true;
		} catch (err) {
			announceAssignmentSaveError(err);
			return false;
		} finally {
			setAssignmentFormBusy(form, false);
		}
	}

	function syncAssignmentModalPrimary(instance) {
		if (!instance?.primaryBtn) {
			return;
		}
		const blocked = isAssignmentSetupBlocking() || !canAddAssignment();
		instance.primaryBtn.disabled = blocked;
		if (blocked) {
			instance.primaryBtn.setAttribute('title', addAssignmentDisabledReason());
		} else {
			instance.primaryBtn.removeAttribute('title');
		}
	}

	async function openAssignmentCreateModal(triggerEl) {
		if (!canAddAssignment()) {
			Msg.announce(addAssignmentDisabledReason(), 'warning');
			if (triggerEl && typeof triggerEl.focus === 'function') {
				triggerEl.focus();
			}
			return;
		}
		await refreshPlanningDefaultFromServer();
		prepareAssignmentFormForModal();
		clearAssignmentsSectionSuccess();
		const panel = document.getElementById('dc-assignment-form-panel');
		if (!panel) {
			return;
		}
		renderSetupCallout(state.lastRosterData || {});
		const instance = C.openModal({
			title: t('dutycheck', 'Create assignment'),
			dialogClass: 'dc-modal__dialog--roster-assignment',
			primaryLabel: t('dutycheck', 'Save assignment'),
			cancelLabel: t('dutycheck', 'Cancel'),
			render: () => panel,
			onSubmit: async () => {
				const ok = await performAssignmentSave();
				return ok;
			},
			onClose: (result) => {
				assignmentModalInstance = null;
				restoreAssignmentFormPanel();
				if (!result) {
					clearAssignmentFormSuccess();
					return;
				}
				const addBtn = document.getElementById('dc-roster-add-assignment');
				if (addBtn && typeof addBtn.focus === 'function') {
					addBtn.focus();
				}
			},
			onCancel: () => {
				clearAssignmentFormSuccess();
			},
		});
		assignmentModalInstance = instance;
		syncAssignmentModalPrimary(instance);
		const form = document.getElementById('dc-assignment-form');
		if (form && !form.dataset.dcModalSyncWired) {
			form.dataset.dcModalSyncWired = '1';
			form.addEventListener('change', () => {
				if (assignmentModalInstance?._open) {
					syncAssignmentModalPrimary(assignmentModalInstance);
				}
			});
		}
	}

	function updateAdminExportLinks(selectedPeriodId) {
		const csv = document.getElementById('dc-roster-export-csv');
		const printLink = document.getElementById('dc-roster-export-print');
		if (!csv || !printLink) {
			return;
		}
		const urls = readUrls();
		const baseCsv = String(urls.rosterExportCsv || '');
		const basePrint = String(urls.rosterPrint || '');
		const id = Number(selectedPeriodId);
		const ok = baseCsv !== '' && basePrint !== '' && Number.isInteger(id) && id > 0;
		if (!ok) {
			csv.href = '#dc-roster-period-section';
			printLink.href = '#dc-roster-period-section';
			csv.setAttribute('aria-disabled', 'true');
			printLink.setAttribute('aria-disabled', 'true');
			return;
		}
		const q = new URLSearchParams({ periodId: String(id) }).toString();
		csv.href = baseCsv + (baseCsv.includes('?') ? '&' : '?') + q;
		printLink.href = basePrint + (basePrint.includes('?') ? '&' : '?') + q;
		csv.removeAttribute('aria-disabled');
		printLink.removeAttribute('aria-disabled');
	}

	function wireAdminExportGuards() {
		const guard = (event) => {
			const el = event.currentTarget;
			if (el && el.getAttribute('aria-disabled') === 'true') {
				event.preventDefault();
				Msg.announce(
					t('dutycheck', 'Choose a period in the selector above before exporting or printing.'),
					'warning',
				);
			}
		};
		document.getElementById('dc-roster-export-csv')?.addEventListener('click', guard);
		document.getElementById('dc-roster-export-print')?.addEventListener('click', guard);
	}

	let rosterPhpL10nCache;
	function getRosterPhpL10n() {
		if (rosterPhpL10nCache !== undefined) {
			return rosterPhpL10nCache;
		}
		const el = document.getElementById('dc-roster-php-l10n');
		const raw = el && el.dataset ? el.dataset.l10n : '';
		if (!raw) {
			rosterPhpL10nCache = null;
			return null;
		}
		try {
			rosterPhpL10nCache = JSON.parse(raw) || null;
		} catch (_) {
			rosterPhpL10nCache = null;
		}
		return rosterPhpL10nCache;
	}

	function translateConflictMessage(message) {
		const raw = String(message || '');
		if (!raw) {
			return t('dutycheck', 'Unknown conflict');
		}
		const fromPhp = getRosterPhpL10n()?.conflictMessages?.[raw];
		if (fromPhp) {
			return fromPhp;
		}
		return t('dutycheck', raw);
	}

	function render(data) {
		state.lastRosterData = data;
		if (data.defaultBreakMinutes !== undefined && data.defaultBreakMinutes !== null) {
			state.defaultBreakMinutes = clampBreakMinutes(data.defaultBreakMinutes);
		}
		state.periods = data.periods || [];
		state.employees = data.employees || [];
		state.locations = data.locations || [];
		state.assignments = data.assignments || [];
		state.absenceBlocks = data.absenceBlocks || [];
		state.canCreateAssignments = Boolean(data.canCreateAssignments);
		fillPeriodSwitcher(state.periods, data.selectedPeriodId);
		const periodHidden = document.getElementById('dc-assignment-period');
		if (periodHidden) {
			periodHidden.value = data.selectedPeriodId ? String(data.selectedPeriodId) : '';
		}
		const dateInput = document.getElementById('dc-assignment-date');
		const selectedPeriod = state.periods.find((p) => Number(p.id) === Number(data.selectedPeriodId));
		if (dateInput && selectedPeriod?.startDate && selectedPeriod?.endDate) {
			dateInput.min = String(selectedPeriod.startDate);
			dateInput.max = String(selectedPeriod.endDate);
			if (!dateInput.value && state.canCreateAssignments) {
				dateInput.value = String(selectedPeriod.startDate);
			}
		} else if (dateInput) {
			dateInput.removeAttribute('min');
			dateInput.removeAttribute('max');
		}
		fillSelect('dc-assignment-location', state.locations, {
			emptyText: t('dutycheck', 'No active locations yet'),
		});
		updateLocationTimezoneHint();
		refreshAssignmentFormEligibility();
		renderAssignments(data.assignments || []);
		renderConflicts(data.conflicts || []);
		renderSetupCallout(data);
		updateAddAssignmentControl();
		updateAdminExportLinks(data.selectedPeriodId);
		D?.applyLocaleToTemporalInputs(document.getElementById('dc-assignment-form') || document);
	}

	function selectedPeriodIdFromUrl() {
		const params = new URLSearchParams(window.location.search || '');
		const raw = params.get('periodId');
		const parsed = Number(raw);
		return Number.isInteger(parsed) && parsed > 0 ? parsed : null;
	}

	function updateUrlPeriodId(periodId) {
		const params = new URLSearchParams(window.location.search || '');
		if (periodId && Number(periodId) > 0) {
			params.set('periodId', String(periodId));
		} else {
			params.delete('periodId');
		}
		const query = params.toString();
		window.history.replaceState({}, '', query ? `${window.location.pathname}?${query}` : window.location.pathname);
	}

	async function loadRoster(periodId) {
		if (C.isModalOpen?.()) {
			C.dismissOpenModal();
		} else {
			restoreAssignmentFormPanel();
		}
		setAssignmentsEmptyPanelVisible(false);
		const tbody = document.getElementById('dc-assignments-table-body');
		C.setLoadingRow(tbody, 7);
		try {
			const response = await Api.get('/apps/dutycheck/api/roster', { periodId });
			render(response?.data || {});
			updateUrlPeriodId(response?.data?.selectedPeriodId || null);
			return response?.data || {};
		} catch (err) {
			const code = String(err?.payload?.error?.code || err?.code || '');
			if (code === 'PERIOD_NOT_FOUND' && periodId != null) {
				try {
					const fallback = await Api.get('/apps/dutycheck/api/roster', {});
					render(fallback?.data || {});
					updateUrlPeriodId(fallback?.data?.selectedPeriodId || null);
					Msg.announce(
						t('dutycheck', 'That planning period is not available. Showing the roster for the current period instead.'),
						'warning',
					);
					return fallback?.data || {};
				} catch (retryErr) {
					Msg.handleApiError(retryErr);
					C.renderTableFetchError(tbody, 7, t('dutycheck', 'Could not load the roster. Reload the page or contact an administrator if this keeps happening.'));
					return null;
				}
			}
			Msg.handleApiError(err);
			C.renderTableFetchError(tbody, 7, t('dutycheck', 'Could not load the roster. Reload the page or contact an administrator if this keeps happening.'));
			return null;
		} finally {
			C.clearLoadingRow(tbody);
		}
	}

	function clearAssignmentFormFields() {
		const form = document.getElementById('dc-assignment-form');
		if (!form) return;
		const periodHidden = document.getElementById('dc-assignment-period');
		const periodVal = periodHidden ? String(periodHidden.value || '') : '';
		form.reset();
		if (periodHidden && periodVal) {
			periodHidden.value = periodVal;
		}
		applyDefaultBreakToForm();
		D?.applyLocaleToTemporalInputs(form);
		refreshAssignmentFormEligibility();
		const clearedMsg = t('dutycheck', 'Form cleared. You can enter another assignment.');
		if (document.getElementById('dc-assignment-form-panel')?.closest('.dc-modal')) {
			showAssignmentFormSuccess(clearedMsg, 'info');
		}
		Msg.announce(clearedMsg, 'info');
	}

	function readForm(form) {
		const data = new FormData(form);
		const rawStart = String(data.get('startTime') || '');
		const rawEnd = String(data.get('endTime') || '');
		const startTime = (D?.formatClock24FromTimeString(rawStart) || rawStart).trim();
		const endTime = (D?.formatClock24FromTimeString(rawEnd) || rawEnd).trim();
		return {
			periodId: Number(data.get('periodId')),
			employeeId: Number(data.get('employeeId')),
			locationId: Number(data.get('locationId')),
			dutyDate: String(data.get('dutyDate') || ''),
			startTime,
			endTime,
			breakMinutes: Number(data.get('breakMinutes') || 0),
			note: String(data.get('note') || '').trim(),
		};
	}

	/**
	 * Match server RosterService::effectiveMinutes — overnight when end <= start wall time.
	 */
	function effectiveWorkMinutes(startTime, endTime, breakMinutes) {
		const start = D?.wallClockMinutesFromTimeString?.(startTime) ?? null;
		const end = D?.wallClockMinutesFromTimeString?.(endTime) ?? null;
		if (start === null || end === null) return null;
		let endAdj = end;
		if (endAdj <= start) {
			endAdj += 24 * 60;
		}
		return (endAdj - start) - breakMinutes;
	}

	function validate(payload) {
		if (!Number.isInteger(payload.periodId) || payload.periodId <= 0) {
			return t('dutycheck', 'Select a period before saving.');
		}
		const period = selectedPeriodForPayload(payload);
		if (!period) {
			return t('dutycheck', 'That planning period was not found.');
		}
		if (String(period.status || '').toLowerCase() !== 'open') {
			return t('dutycheck', 'The selected period is not open for planning.');
		}
		if (!Number.isInteger(payload.employeeId) || payload.employeeId <= 0) {
			return t('dutycheck', 'Select an employee.');
		}
		if (!Number.isInteger(payload.locationId) || payload.locationId <= 0) {
			return t('dutycheck', 'Select a location.');
		}
		if (!payload.dutyDate || !payload.startTime || !payload.endTime) {
			return t('dutycheck', 'Date, start time, and end time are required.');
		}
		if (period.startDate && period.endDate
			&& (payload.dutyDate < String(period.startDate) || payload.dutyDate > String(period.endDate))) {
			return t('dutycheck', 'Date must fall within the selected period.');
		}
		if (payload.startTime === payload.endTime) {
			return t(
				'dutycheck',
				'Start and end time must be different. For overnight shifts, set the end time earlier than the start (e.g. 22:00–06:00).',
			);
		}
		if (!Number.isFinite(payload.breakMinutes) || payload.breakMinutes < 0 || payload.breakMinutes > 720) {
			return t('dutycheck', 'Break minutes must be between 0 and 720.');
		}
		const effective = effectiveWorkMinutes(payload.startTime, payload.endTime, payload.breakMinutes);
		if (effective === null) {
			return t('dutycheck', 'Please check the dates and times.');
		}
		if (effective <= 0) {
			return t('dutycheck', 'Shift length is invalid after break (check overnight times and break minutes).');
		}
		if (isDateInAbsenceBlock(payload.employeeId, payload.dutyDate)) {
			return t('dutycheck', 'This employee has approved leave on the selected date.');
		}
		if (findOverlapAssignment(payload)) {
			return t('dutycheck', 'This employee already has an overlapping assignment for these times.');
		}
		return null;
	}

	function errorMessageFor(error) {
		const code = errorCodeFrom(error);
		switch (code) {
			case 'PERIOD_NOT_OPEN':
				return t('dutycheck', 'The selected period is not open for planning.');
			case 'PERIOD_NOT_FOUND':
				return t('dutycheck', 'That planning period was not found.');
			case 'DATE_OUTSIDE_PERIOD':
				return t('dutycheck', 'Date must fall within the selected period.');
			case 'EMPLOYEE_NOT_FOUND':
				return t('dutycheck', 'Employee not found or no longer available.');
			case 'LOCATION_NOT_FOUND':
				return t('dutycheck', 'Location not found or no longer available.');
			case 'EQUAL_DUTY_TIMES':
				return t(
					'dutycheck',
					'Start and end time must be different. For overnight shifts, set the end time earlier than the start (e.g. 22:00–06:00).',
				);
			case 'INVALID_SHIFT_LENGTH':
				return t('dutycheck', 'Shift length is invalid after break (check overnight times and break minutes).');
			case 'INVALID_BREAK_MINUTES':
				return t('dutycheck', 'Break minutes are out of the allowed range.');
			case 'NOTE_TOO_LONG':
				return t('dutycheck', 'Note is too long (maximum 512 characters).');
			case 'ASSIGNMENT_OVERLAP':
				return t('dutycheck', 'This employee already has an overlapping assignment.');
			case 'ASSIGNMENT_DUPLICATE_SLOT':
				return t('dutycheck', 'This exact assignment already exists. Reload the page to see the latest roster.');
			case 'ABSENCE_CONFLICT':
				return t('dutycheck', 'This employee has an approved absence on that date.');
			case 'CONFLICT_ACK_REQUIRED':
				return t('dutycheck', 'A planning rule needs your confirmation before this shift can be saved.');
			case 'REASON_TOO_SHORT':
				return t('dutycheck', 'Acknowledgement reason must contain at least 10 characters.');
			case 'INTERNAL_ERROR':
				return t('dutycheck', 'The server could not save this assignment. Reload the page and try again, or contact an administrator.');
			case 'INVALID_DATE':
			case 'INVALID_TIME':
				return t('dutycheck', 'Please check the dates and times.');
			case 'PERIOD_ID_REQUIRED':
				return t('dutycheck', 'Select a period before saving.');
			case 'EMPLOYEE_ID_REQUIRED':
				return t('dutycheck', 'Select an employee.');
			case 'LOCATION_ID_REQUIRED':
				return t('dutycheck', 'Select a location.');
			default:
				return null;
		}
	}

	document.addEventListener('DOMContentLoaded', async () => {
		D?.applyLocaleToTemporalInputs(document);
		wireBreakPrefillHint();
		window.addEventListener('dc-planning-defaults-changed', (event) => {
			const minutes = Number(event?.detail?.defaultBreakMinutes);
			if (Number.isFinite(minutes)) {
				state.defaultBreakMinutes = clampBreakMinutes(minutes);
			}
		});
		wireAdminExportGuards();
		await loadRoster(selectedPeriodIdFromUrl());

		const switcher = document.getElementById('dc-roster-period-switcher');
		switcher?.addEventListener('change', async () => {
			clearAssignmentsSectionSuccess();
			const periodId = Number(switcher.value);
			await loadRoster(Number.isInteger(periodId) && periodId > 0 ? periodId : null);
		});

		document.getElementById('dc-roster-assignments-section')?.addEventListener('click', (event) => {
			const btn = event.target.closest('.dc-roster-add-assignment-trigger');
			if (!btn) {
				return;
			}
			openAssignmentCreateModal(btn);
		});

		document.getElementById('dc-assignment-form-clear')?.addEventListener('click', () => {
			clearAssignmentFormFields();
		});

		const form = document.getElementById('dc-assignment-form');
		const scheduleEligibilityRefresh = () => {
			clearAssignmentFormSuccess();
			clearAssignmentsSectionSuccess();
			refreshAssignmentFormEligibility();
		};
		document.getElementById('dc-assignment-date')?.addEventListener('change', scheduleEligibilityRefresh);
		document.getElementById('dc-assignment-employee')?.addEventListener('change', scheduleEligibilityRefresh);
		document.getElementById('dc-assignment-start')?.addEventListener('change', scheduleEligibilityRefresh);
		document.getElementById('dc-assignment-end')?.addEventListener('change', scheduleEligibilityRefresh);
		document.getElementById('dc-assignment-location')?.addEventListener('change', scheduleEligibilityRefresh);
		form?.addEventListener('submit', (event) => {
			event.preventDefault();
			const dialog = form.closest('.dc-modal__dialog--roster-assignment');
			if (!dialog) {
				return;
			}
			const primary = dialog.querySelector('.dc-modal__actions .button.primary');
			if (primary && !primary.disabled) {
				primary.click();
			}
		});
	});
})();
