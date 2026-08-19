(function () {
	'use strict';

	const Api = window.DutyCheckApi;
	const Msg = window.DutyCheckMessaging;
	const C = window.DutyCheckComponents || window.DutyCheckDom || {};
	const D = window.DutyCheckDates;
	const ConflictLabels = window.DutyCheckConflictLabels;
	const create = C.createElement;
	if (typeof create !== 'function') {
		throw new Error('DutyCheck components failed to load');
	}

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
		planningDefaultsFresh: false,
		templates: [],
		templatesLoaded: false,
		editingAssignmentId: null,
		editingAssignmentVersion: null,
		copyPreviewReady: false,
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
			state.planningDefaultsFresh = true;
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
		const ignoreId = Number(payload.assignmentId || state.editingAssignmentId || 0);
		for (const assignment of state.assignments) {
			if (ignoreId > 0 && Number(assignment?.id) === ignoreId) {
				continue;
			}
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
			const recoveryUrl = String(conflict?.recoveryUrl || '').trim();
			if (recoveryUrl && recoveryUrl !== '#' && !/^javascript:/i.test(recoveryUrl)) {
				const recoveryLabel = String(conflict?.recoveryLabel || '').trim()
					|| t('dutycheck', 'Open ArbeitszeitCheck');
				const recoveryLink = create('a', {
					class: 'button primary',
					href: recoveryUrl,
					text: recoveryLabel,
					attrs: {
						target: '_blank',
						rel: 'noopener noreferrer',
						'aria-label': recoveryLabel,
					},
				});
				actions.appendChild(recoveryLink);
			}
			const assignmentIds = Array.isArray(conflict?.assignmentIds) ? conflict.assignmentIds : [];
			if (conflict?.type === 'absence_collision' && assignmentIds.length) {
				const focusBtn = create('button', {
					type: 'button',
					class: 'button',
					text: t('dutycheck', 'Show assignment'),
				});
				focusBtn.addEventListener('click', () => {
					revealAssignmentRow(Number(assignmentIds[0]));
				});
				actions.appendChild(focusBtn);
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

	const VIRTUAL_FALLBACK = {
		DEFAULT_ROW_HEIGHT: 44,
		DEFAULT_OVERSCAN: 6,
		UNSIZED_WINDOW_ROWS: 24,
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
		scrollTopToRevealIndex() {
			return 0;
		},
		pageStride() {
			return 1;
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
		const api = window.DutyCheckVirtualWindow;
		if (api
			&& typeof api.visibleRange === 'function'
			&& typeof api.scrollTopToRevealIndex === 'function'
			&& typeof api.windowCaption === 'function') {
			return api;
		}
		return VIRTUAL_FALLBACK;
	}

	function cellSelectionKey(employeeId, dutyDate) {
		return `${Number(employeeId)}|${String(dutyDate)}`;
	}

	function prefersReducedMotion() {
		try {
			return window.matchMedia('(prefers-reduced-motion: reduce)').matches;
		} catch (_) {
			return false;
		}
	}

	function measureHeight(el) {
		if (!(el instanceof HTMLElement)) {
			return 0;
		}
		const h = el.getBoundingClientRect().height;
		return Number.isFinite(h) && h > 0 ? h : 0;
	}

	function assignmentIndex(assignments) {
		const byKey = new Map();
		for (const a of assignments || []) {
			const key = cellSelectionKey(a.employeeId, a.dutyDate);
			if (!byKey.has(key)) {
				byKey.set(key, []);
			}
			byKey.get(key).push(a);
		}
		return byKey;
	}

	function setAssignmentsEmptyPanelVisible(showEmptyPanel) {
		const emptyPanel = document.getElementById('dc-roster-assignments-empty');
		if (emptyPanel) {
			emptyPanel.hidden = !showEmptyPanel;
		}
	}

	const gridState = {
		view: 'grid',
		selected: new Set(), // `${employeeId}|${dutyDate}`
		focusRow: 0,
		focusCol: 0,
		paintAll: false,
		rowHeight: 44,
		headerHeight: 44,
		windowStart: -1,
		windowEnd: -1,
		paintedEmployeeCount: -1,
		paintedDateCount: -1,
		remeasuring: false,
	};

	const listState = {
		windowStart: -1,
		windowEnd: -1,
		rowHeight: 48,
		paintedCount: -1,
		remeasuring: false,
	};

	function renderAssignments(assignments) {
		paintListWindow({ assignments: assignments || [], force: true });
		renderRosterGrid(assignments || []);
		updateAddAssignmentControl();
	}

	function buildAssignmentRow(a, periodOpen) {
		const overnightHintText = t('dutycheck', 'Continues into the next day.');
		const startClock = (s) => (D?.formatClock24FromTimeString(s) || String(s ?? ''));
		const tr = create('tr');
		if (a?.id != null) {
			tr.dataset.assignmentId = String(a.id);
			tr.setAttribute('tabindex', '-1');
		}
		const date = D?.formatDisplayDate(a.dutyDate) || a.dutyDate;
		const tdDate = create('td', { text: date });
		tdDate.dataset.cell = t('dutycheck', 'Date');
		tr.appendChild(tdDate);

		const tdStart = create('td');
		tdStart.dataset.cell = t('dutycheck', 'Start');
		tdStart.appendChild(create('span', { class: 'dc-time-cell__value', text: startClock(a.startTime) }));
		tr.appendChild(tdStart);

		const tdEnd = create('td');
		tdEnd.dataset.cell = t('dutycheck', 'End');
		tdEnd.appendChild(create('span', { class: 'dc-time-cell__value', text: startClock(a.endTime) }));
		if (D?.isOvernightWallClockShift?.(a.startTime, a.endTime)) {
			tdEnd.title = overnightHintText;
			tdEnd.appendChild(create('span', { class: 'dc-row-meta', text: overnightHintText }));
		}
		tr.appendChild(tdEnd);

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
		const tdActions = create('td');
		tdActions.dataset.cell = t('dutycheck', 'Actions');
		tdActions.className = 'dc-table__actions';
		if (periodOpen && a?.id) {
			const editBtn = create('button', {
				type: 'button',
				class: 'button button--text',
				text: t('dutycheck', 'Edit'),
			});
			editBtn.setAttribute('aria-label', t('dutycheck', 'Edit assignment'));
			editBtn.addEventListener('click', () => openAssignmentEditModal(a, editBtn));
			const cancelBtn = create('button', {
				type: 'button',
				class: 'button button--text',
				text: t('dutycheck', 'Cancel shift'),
			});
			cancelBtn.setAttribute('aria-label', t('dutycheck', 'Cancel this assignment'));
			cancelBtn.addEventListener('click', () => cancelAssignmentRow(a, cancelBtn));
			tdActions.appendChild(editBtn);
			tdActions.appendChild(cancelBtn);
		} else {
			tdActions.appendChild(create('span', { class: 'dc-row-meta', text: t('dutycheck', 'Read-only') }));
		}
		tr.appendChild(tdActions);
		return tr;
	}

	function createListSpacer(px) {
		const tr = create('tr', { class: 'dc-virtual-spacer' });
		tr.setAttribute('aria-hidden', 'true');
		tr.style.height = `${Math.max(0, px)}px`;
		const td = create('td');
		td.colSpan = 8;
		td.style.height = `${Math.max(0, px)}px`;
		td.style.padding = '0';
		td.style.border = '0';
		td.style.lineHeight = '0';
		tr.appendChild(td);
		return tr;
	}

	function updateListWindowStatus(range, total) {
		const el = document.getElementById('dc-roster-list-status');
		if (!el) {
			return;
		}
		const cap = virtualApi().windowCaption({ start: range.start, end: range.end, total });
		if (cap.mode === 'empty') {
			el.textContent = '';
			return;
		}
		if (cap.mode === 'all') {
			el.textContent = t('dutycheck', 'All {total} shifts are on screen.').replace('{total}', String(cap.total));
			return;
		}
		el.textContent = t('dutycheck', 'Showing shifts {from}–{to} of {total}. Scroll to see everyone.')
			.replace('{from}', String(cap.from))
			.replace('{to}', String(cap.to))
			.replace('{total}', String(cap.total));
	}

	function paintListWindow(options) {
		const tbody = document.getElementById('dc-assignments-table-body');
		if (!tbody) {
			return;
		}
		const assignments = options && Array.isArray(options.assignments)
			? options.assignments
			: (state.assignments || []);
		const force = !!(options && options.force);
		if (!assignments.length) {
			tbody.replaceChildren();
			setAssignmentsEmptyPanelVisible(true);
			listState.windowStart = 0;
			listState.windowEnd = 0;
			listState.paintedCount = 0;
			updateListWindowStatus({ start: 0, end: 0 }, 0);
			return;
		}
		setAssignmentsEmptyPanelVisible(false);
		const scroller = document.getElementById('dc-assignments-table-wrap');
		const VW = virtualApi();
		const rowHeight = listState.rowHeight > 0 ? listState.rowHeight : VW.DEFAULT_ROW_HEIGHT;
		const viewportHeight = scroller && gridState.view === 'list' ? scroller.clientHeight : 0;
		const scrollTop = scroller ? scroller.scrollTop : 0;
		const range = VW.visibleRange({
			total: assignments.length,
			rowHeight,
			viewportHeight,
			scrollTop,
			overscan: VW.DEFAULT_OVERSCAN,
			paintAll: gridState.paintAll === true,
		});
		if (!force
			&& listState.windowStart === range.start
			&& listState.windowEnd === range.end
			&& listState.paintedCount === assignments.length) {
			updateListWindowStatus(range, assignments.length);
			return;
		}
		const savedTop = scroller ? scroller.scrollTop : 0;
		const periodOpen = canAddAssignment();
		const frag = document.createDocumentFragment();
		if (range.padBefore > 0) {
			frag.appendChild(createListSpacer(range.padBefore));
		}
		const windowRows = assignments.slice(range.start, range.end);
		for (const a of windowRows) {
			frag.appendChild(buildAssignmentRow(a, periodOpen));
		}
		if (range.padAfter > 0) {
			frag.appendChild(createListSpacer(range.padAfter));
		}
		tbody.replaceChildren(frag);
		listState.windowStart = range.start;
		listState.windowEnd = range.end;
		listState.paintedCount = assignments.length;
		if (scroller) {
			scroller.scrollTop = savedTop;
		}
		const sample = tbody.querySelector('tr:not(.dc-virtual-spacer)');
		const measured = measureHeight(sample);
		if (measured > 0 && Math.abs(measured - rowHeight) > 1 && !listState.remeasuring) {
			listState.rowHeight = measured;
			listState.remeasuring = true;
			paintListWindow({ assignments, force: true });
			listState.remeasuring = false;
			return;
		}
		if (measured > 0) {
			listState.rowHeight = measured;
		}
		updateListWindowStatus(range, assignments.length);
	}

	function selectedPeriodId() {
		const switcher = document.getElementById('dc-roster-period-switcher');
		const fromSwitcher = Number(switcher?.value || 0);
		if (Number.isInteger(fromSwitcher) && fromSwitcher > 0) {
			return fromSwitcher;
		}
		return Number(state.lastRosterData?.selectedPeriodId || 0) || null;
	}

	function periodDateList() {
		const periodId = selectedPeriodId();
		const period = (state.periods || []).find((p) => Number(p.id) === Number(periodId));
		if (!period?.startDate || !period?.endDate) {
			return [];
		}
		const out = [];
		const cursor = new Date(`${period.startDate}T00:00:00Z`);
		const end = new Date(`${period.endDate}T00:00:00Z`);
		while (cursor <= end) {
			out.push(cursor.toISOString().slice(0, 10));
			cursor.setUTCDate(cursor.getUTCDate() + 1);
			if (out.length > 62) {
				break;
			}
		}
		return out;
	}

	function updateGridWindowStatus(range, total) {
		const el = document.getElementById('dc-roster-grid-status');
		if (!el) {
			return;
		}
		const cap = virtualApi().windowCaption({ start: range.start, end: range.end, total });
		if (cap.mode === 'empty') {
			el.textContent = '';
			return;
		}
		if (cap.mode === 'all') {
			el.textContent = t('dutycheck', 'All {total} people are on screen.').replace('{total}', String(cap.total));
			return;
		}
		el.textContent = t('dutycheck', 'Showing people {from}–{to} of {total}. Scroll to see everyone.')
			.replace('{from}', String(cap.from))
			.replace('{to}', String(cap.to))
			.replace('{total}', String(cap.total));
	}

	function createGridSpacer(px) {
		const el = create('div', { class: 'dc-virtual-spacer dc-roster-grid__spacer' });
		el.setAttribute('aria-hidden', 'true');
		el.setAttribute('role', 'presentation');
		el.style.height = `${Math.max(0, px)}px`;
		return el;
	}

	function setGridEmptyState(root, message) {
		root.removeAttribute('aria-rowcount');
		root.removeAttribute('aria-colcount');
		root.removeAttribute('tabindex');
		root.setAttribute('role', 'status');
		root.setAttribute('aria-live', 'polite');
		root.replaceChildren(create('p', {
			class: 'dc-roster-empty-state__text',
			text: message,
		}));
	}

	function prepareGridForRows(root, employees, dates) {
		root.setAttribute('role', 'grid');
		root.removeAttribute('tabindex');
		root.removeAttribute('aria-live');
		root.setAttribute('aria-rowcount', String(employees.length + 1));
		root.setAttribute('aria-colcount', String(Math.max(1, dates.length + 1)));
	}

	function buildGridRow(emp, rowIdx, dates, byKey) {
		const row = create('div', {
			class: 'dc-roster-grid__row',
			role: 'row',
			attrs: { 'aria-rowindex': String(rowIdx + 2) },
		});
		row.appendChild(create('div', {
			class: 'dc-roster-grid__rowhead',
			role: 'rowheader',
			text: String(emp.name || emp.displayName || emp.id),
			attrs: { 'aria-colindex': '1' },
		}));
		dates.forEach((day, colIdx) => {
			const key = cellSelectionKey(emp.id, day);
			const cellAssignments = byKey.get(key) || [];
			const cell = create('div', {
				class: 'dc-roster-grid__cell',
				role: 'gridcell',
				attrs: {
					tabindex: rowIdx === gridState.focusRow && colIdx === gridState.focusCol ? '0' : '-1',
					'data-employee-id': String(emp.id),
					'data-duty-date': day,
					'data-row': String(rowIdx),
					'data-col': String(colIdx),
					'aria-colindex': String(colIdx + 2),
					'aria-selected': gridState.selected.has(key) ? 'true' : 'false',
				},
			});
			if (cellAssignments.length) {
				cell.classList.add('dc-roster-grid__cell--filled');
				const first = cellAssignments[0];
				const label = `${String(first.startTime || '').slice(0, 5)}–${String(first.endTime || '').slice(0, 5)}`;
				cell.appendChild(create('span', {
					class: 'dc-roster-grid__shift',
					text: label,
				}));
				const editLabel = canAddAssignment()
					? t('dutycheck', 'Edit assignment')
					: t('dutycheck', 'View assignment (read-only)');
				cell.setAttribute('aria-label', `${editLabel}: ${label}`);
				if (cellAssignments.length > 1) {
					cell.appendChild(create('span', {
						class: 'dc-roster-grid__more',
						text: `+${cellAssignments.length - 1}`,
					}));
				}
			} else {
				cell.classList.add('dc-roster-grid__cell--empty');
				cell.setAttribute(
					'aria-label',
					canAddAssignment()
						? t('dutycheck', 'Empty cell — Space to select for bulk fill')
						: t('dutycheck', 'Empty cell'),
				);
			}
			if (gridState.selected.has(key)) {
				cell.classList.add('is-selected');
			}
			row.appendChild(cell);
		});
		return row;
	}

	function syncVisibleGridChrome() {
		const root = document.getElementById('dc-roster-grid');
		if (!root) {
			return;
		}
		root.querySelectorAll('[role="gridcell"]').forEach((cell) => {
			const empId = cell.getAttribute('data-employee-id');
			const day = cell.getAttribute('data-duty-date');
			const key = cellSelectionKey(empId, day);
			const selected = gridState.selected.has(key);
			cell.classList.toggle('is-selected', selected);
			cell.setAttribute('aria-selected', selected ? 'true' : 'false');
			const rowIdx = Number(cell.getAttribute('data-row'));
			const colIdx = Number(cell.getAttribute('data-col'));
			const isFocus = rowIdx === gridState.focusRow && colIdx === gridState.focusCol;
			cell.setAttribute('tabindex', isFocus ? '0' : '-1');
		});
	}

	function focusGridCellIfVisible(root) {
		const node = root.querySelector(`[data-row="${gridState.focusRow}"][data-col="${gridState.focusCol}"]`);
		if (node instanceof HTMLElement) {
			node.scrollIntoView({
				block: 'nearest',
				inline: 'nearest',
				behavior: 'auto',
			});
			node.focus();
		}
	}

	function activateGridCell(cell) {
		if (!(cell instanceof HTMLElement)) {
			return;
		}
		const rowIdx = Number(cell.getAttribute('data-row'));
		const colIdx = Number(cell.getAttribute('data-col'));
		if (Number.isInteger(rowIdx) && rowIdx >= 0) {
			gridState.focusRow = rowIdx;
		}
		if (Number.isInteger(colIdx) && colIdx >= 0) {
			gridState.focusCol = colIdx;
		}
		const empId = cell.getAttribute('data-employee-id');
		const day = cell.getAttribute('data-duty-date');
		const key = cellSelectionKey(empId, day);
		const filled = cell.classList.contains('dc-roster-grid__cell--filled');
		if (!filled) {
			if (canAddAssignment()) {
				toggleGridSelection(key);
				syncVisibleGridChrome();
				updateBulkBar();
			}
			return;
		}
		const byKey = assignmentIndex(state.assignments || []);
		const cellAssignments = byKey.get(key) || [];
		if (canAddAssignment() && cellAssignments[0]) {
			openAssignmentEditModal(cellAssignments[0], cell);
			return;
		}
		Msg.announce(t('dutycheck', 'This period is read-only. Re-open it from Periods to edit shifts.'), 'info');
	}

	function ensureGridFocusVisible() {
		const scroller = document.getElementById('dc-roster-grid-scroller');
		const employees = state.employees || [];
		if (!scroller || !employees.length) {
			return;
		}
		const VW = virtualApi();
		const rowHeight = gridState.rowHeight > 0 ? gridState.rowHeight : VW.DEFAULT_ROW_HEIGHT;
		const headerHeight = gridState.headerHeight > 0 ? gridState.headerHeight : rowHeight;
		const viewportHeight = Math.max(rowHeight, scroller.clientHeight - headerHeight);
		const next = VW.scrollTopToRevealIndex({
			index: gridState.focusRow,
			rowHeight,
			viewportHeight,
			scrollTop: scroller.scrollTop,
			total: employees.length,
		});
		if (Math.abs(next - scroller.scrollTop) > 0.5) {
			scroller.scrollTop = next;
		}
	}

	function renderRosterGrid(assignments) {
		paintGridWindow({ assignments: assignments || state.assignments || [], force: true });
	}

	function paintGridWindow(options) {
		const root = document.getElementById('dc-roster-grid');
		if (!root) {
			return;
		}
		const assignments = options && Array.isArray(options.assignments)
			? options.assignments
			: (state.assignments || []);
		const force = !!(options && options.force);
		const employees = state.employees || [];
		const dates = periodDateList();
		const byKey = assignmentIndex(assignments);

		if (!employees.length || !dates.length) {
			setGridEmptyState(
				root,
				t('dutycheck', 'Add employees and an open period to use the planning grid.'),
			);
			gridState.windowStart = 0;
			gridState.windowEnd = 0;
			gridState.paintedEmployeeCount = employees.length;
			gridState.paintedDateCount = dates.length;
			updateGridWindowStatus({ start: 0, end: 0 }, 0);
			updateBulkBar();
			return;
		}

		prepareGridForRows(root, employees, dates);
		gridState.focusRow = Math.max(0, Math.min(employees.length - 1, gridState.focusRow));
		gridState.focusCol = Math.max(0, Math.min(dates.length - 1, gridState.focusCol));

		const VW = virtualApi();
		const scroller = document.getElementById('dc-roster-grid-scroller');
		const rowHeight = gridState.rowHeight > 0 ? gridState.rowHeight : VW.DEFAULT_ROW_HEIGHT;
		const headerHeight = gridState.headerHeight > 0 ? gridState.headerHeight : rowHeight;
		const viewportHeight = scroller && gridState.view === 'grid'
			? Math.max(0, scroller.clientHeight - headerHeight)
			: 0;
		const scrollTop = scroller ? scroller.scrollTop : 0;
		const range = VW.visibleRange({
			total: employees.length,
			rowHeight,
			viewportHeight,
			scrollTop,
			overscan: VW.DEFAULT_OVERSCAN,
			paintAll: gridState.paintAll === true,
		});
		if (!force
			&& gridState.windowStart === range.start
			&& gridState.windowEnd === range.end
			&& gridState.paintedEmployeeCount === employees.length
			&& gridState.paintedDateCount === dates.length) {
			syncVisibleGridChrome();
			updateGridWindowStatus(range, employees.length);
			updateBulkBar();
			return;
		}

		const savedTop = scroller ? scroller.scrollTop : 0;
		const savedLeft = scroller ? scroller.scrollLeft : 0;
		const frag = document.createDocumentFragment();
		const header = create('div', {
			class: 'dc-roster-grid__row dc-roster-grid__row--head',
			role: 'row',
			attrs: { 'aria-rowindex': '1' },
		});
		header.appendChild(create('div', {
			class: 'dc-roster-grid__corner',
			role: 'columnheader',
			text: t('dutycheck', 'Person'),
			attrs: { 'aria-colindex': '1' },
		}));
		dates.forEach((day, colIdx) => {
			const label = D?.formatDisplayDate?.(day) || day;
			header.appendChild(create('div', {
				class: 'dc-roster-grid__colhead',
				role: 'columnheader',
				text: label,
				attrs: { 'data-col': String(colIdx), 'aria-colindex': String(colIdx + 2) },
			}));
		});
		frag.appendChild(header);
		if (range.padBefore > 0) {
			frag.appendChild(createGridSpacer(range.padBefore));
		}
		const windowEmployees = employees.slice(range.start, range.end);
		windowEmployees.forEach((emp, offset) => {
			frag.appendChild(buildGridRow(emp, range.start + offset, dates, byKey));
		});
		if (range.padAfter > 0) {
			frag.appendChild(createGridSpacer(range.padAfter));
		}
		root.replaceChildren(frag);
		gridState.windowStart = range.start;
		gridState.windowEnd = range.end;
		gridState.paintedEmployeeCount = employees.length;
		gridState.paintedDateCount = dates.length;
		if (scroller) {
			scroller.scrollTop = savedTop;
			scroller.scrollLeft = savedLeft;
		}
		const headerEl = root.querySelector('.dc-roster-grid__row--head');
		const bodyRow = root.querySelector('.dc-roster-grid__row:not(.dc-roster-grid__row--head)');
		const measuredHeader = measureHeight(headerEl);
		const measuredRow = measureHeight(bodyRow);
		if (((measuredHeader > 0 && Math.abs(measuredHeader - headerHeight) > 1)
			|| (measuredRow > 0 && Math.abs(measuredRow - rowHeight) > 1))
			&& !gridState.remeasuring) {
			if (measuredHeader > 0) {
				gridState.headerHeight = measuredHeader;
			}
			if (measuredRow > 0) {
				gridState.rowHeight = measuredRow;
			}
			gridState.remeasuring = true;
			paintGridWindow({ assignments, force: true });
			gridState.remeasuring = false;
			return;
		}
		if (measuredHeader > 0) {
			gridState.headerHeight = measuredHeader;
		}
		if (measuredRow > 0) {
			gridState.rowHeight = measuredRow;
		}
		bindGridInteractions(root);
		updateGridWindowStatus(range, employees.length);
		updateBulkBar();
	}

	function bindGridInteractions(root) {
		if (!(root instanceof HTMLElement) || root.dataset.dcGridBound === '1') {
			return;
		}
		root.dataset.dcGridBound = '1';
		root.addEventListener('click', (ev) => {
			const cell = ev.target.closest('[role="gridcell"]');
			if (!(cell instanceof HTMLElement) || !root.contains(cell)) {
				return;
			}
			activateGridCell(cell);
		});
		root.addEventListener('keydown', (ev) => {
			const employees = state.employees || [];
			const dates = periodDateList();
			const rowCount = employees.length;
			const colCount = dates.length;
			if (!rowCount || !colCount) {
				return;
			}
			const gridKeys = ['ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight', 'Enter', ' ', 'Spacebar', 'Home', 'End', 'PageUp', 'PageDown'];
			if (!gridKeys.includes(ev.key)) {
				return;
			}
			ev.preventDefault();
			if (ev.key === 'ArrowUp') {
				gridState.focusRow = Math.max(0, gridState.focusRow - 1);
			} else if (ev.key === 'ArrowDown') {
				gridState.focusRow = Math.min(rowCount - 1, gridState.focusRow + 1);
			} else if (ev.key === 'ArrowLeft') {
				gridState.focusCol = Math.max(0, gridState.focusCol - 1);
			} else if (ev.key === 'ArrowRight') {
				gridState.focusCol = Math.min(colCount - 1, gridState.focusCol + 1);
			} else if (ev.key === 'Home') {
				gridState.focusCol = 0;
				if (ev.ctrlKey || ev.metaKey) {
					gridState.focusRow = 0;
				}
			} else if (ev.key === 'End') {
				gridState.focusCol = colCount - 1;
				if (ev.ctrlKey || ev.metaKey) {
					gridState.focusRow = rowCount - 1;
				}
			} else if (ev.key === 'PageUp' || ev.key === 'PageDown') {
				const VW = virtualApi();
				const scroller = document.getElementById('dc-roster-grid-scroller');
				const rowHeight = gridState.rowHeight > 0 ? gridState.rowHeight : VW.DEFAULT_ROW_HEIGHT;
				const headerHeight = gridState.headerHeight > 0 ? gridState.headerHeight : rowHeight;
				const viewportHeight = scroller
					? Math.max(rowHeight, scroller.clientHeight - headerHeight)
					: rowHeight;
				const stride = typeof VW.pageStride === 'function'
					? VW.pageStride({ rowHeight, viewportHeight })
					: 1;
				if (ev.key === 'PageDown') {
					gridState.focusRow = Math.min(rowCount - 1, gridState.focusRow + stride);
				} else {
					gridState.focusRow = Math.max(0, gridState.focusRow - stride);
				}
			} else if (ev.key === 'Enter') {
				ensureGridFocusVisible();
				paintGridWindow({ force: false });
				const cell = root.querySelector(`[data-row="${gridState.focusRow}"][data-col="${gridState.focusCol}"]`);
				activateGridCell(cell);
				focusGridCellIfVisible(root);
				return;
			} else if (ev.key === ' ' || ev.key === 'Spacebar') {
				ensureGridFocusVisible();
				paintGridWindow({ force: false });
				const cell = root.querySelector(`[data-row="${gridState.focusRow}"][data-col="${gridState.focusCol}"]`);
				const empId = cell?.getAttribute('data-employee-id');
				const day = cell?.getAttribute('data-duty-date');
				const isEmpty = cell?.classList.contains('dc-roster-grid__cell--empty');
				if (empId && day && isEmpty && canAddAssignment()) {
					toggleGridSelection(cellSelectionKey(empId, day));
					syncVisibleGridChrome();
					updateBulkBar();
				}
				focusGridCellIfVisible(root);
				return;
			}
			ensureGridFocusVisible();
			paintGridWindow({ force: false });
			focusGridCellIfVisible(root);
		});
	}

	function toggleGridSelection(key) {
		if (gridState.selected.has(key)) {
			gridState.selected.delete(key);
		} else {
			gridState.selected.add(key);
		}
	}

	function updateBulkBar() {
		const bar = document.getElementById('dc-roster-bulk-bar');
		const countEl = document.getElementById('dc-roster-bulk-count');
		if (!bar || !countEl) {
			return;
		}
		const n = gridState.selected.size;
		if (n < 1 || !canAddAssignment()) {
			bar.hidden = true;
			return;
		}
		bar.hidden = false;
		countEl.textContent = t('dutycheck', '{n} cells selected').replace('{n}', String(n));
		const sel = document.getElementById('dc-roster-bulk-template');
		if (sel && !(sel.options && sel.options.length)) {
			populateBulkTemplates();
		}
	}

	async function populateBulkTemplates() {
		const sel = document.getElementById('dc-roster-bulk-template');
		if (!sel) {
			return;
		}
		await loadTemplates();
		sel.replaceChildren();
		const templates = state.templates || [];
		if (!templates.length) {
			sel.appendChild(create('option', { value: '', text: t('dutycheck', 'No templates yet') }));
			return;
		}
		for (const tpl of templates) {
			sel.appendChild(create('option', {
				value: String(tpl.id),
				text: `${tpl.name} (${tpl.startTime}–${tpl.endTime})`,
			}));
		}
	}

	function revealAssignmentRow(id) {
		const assignmentId = Number(id);
		const assignments = state.assignments || [];
		const idx = assignments.findIndex((a) => Number(a.id) === assignmentId);
		if (idx < 0) {
			return;
		}
		applyRosterViewChrome('list');
		const run = () => {
			const scroller = document.getElementById('dc-assignments-table-wrap');
			const VW = virtualApi();
			const rowHeight = listState.rowHeight > 0 ? listState.rowHeight : VW.DEFAULT_ROW_HEIGHT;
			if (scroller) {
				scroller.scrollTop = VW.scrollTopToRevealIndex({
					index: idx,
					rowHeight,
					viewportHeight: Math.max(rowHeight, scroller.clientHeight),
					scrollTop: scroller.scrollTop,
					total: assignments.length,
				});
			}
			paintListWindow({ assignments, force: true });
			const row = document.querySelector(`[data-assignment-id="${assignmentId}"]`);
			if (row instanceof HTMLElement) {
				row.scrollIntoView({
					block: 'nearest',
					behavior: 'auto',
				});
				row.focus();
				row.classList.add('dc-row--flash');
				window.setTimeout(() => row.classList.remove('dc-row--flash'), 1600);
			}
		};
		window.requestAnimationFrame(run);
	}

	function setRosterPaintAll(paintAll) {
		gridState.paintAll = paintAll === true;
		paintListWindow({ force: true });
		paintGridWindow({ force: true });
	}

	function wireRosterVirtualization() {
		const gridScroller = document.getElementById('dc-roster-grid-scroller');
		const listScroller = document.getElementById('dc-assignments-table-wrap');
		let gridRaf = 0;
		let listRaf = 0;
		if (gridScroller) {
			gridScroller.addEventListener('scroll', () => {
				if (gridRaf) {
					return;
				}
				gridRaf = window.requestAnimationFrame(() => {
					gridRaf = 0;
					paintGridWindow({ force: false });
				});
			}, { passive: true });
		}
		if (listScroller) {
			listScroller.addEventListener('scroll', () => {
				if (listRaf) {
					return;
				}
				listRaf = window.requestAnimationFrame(() => {
					listRaf = 0;
					paintListWindow({ force: false });
				});
			}, { passive: true });
		}
		const resize = () => {
			if (gridState.view === 'grid') {
				paintGridWindow({ force: true });
			} else {
				paintListWindow({ force: true });
			}
		};
		if (typeof ResizeObserver === 'function') {
			const ro = new ResizeObserver(resize);
			if (gridScroller) {
				ro.observe(gridScroller);
			}
			if (listScroller) {
				ro.observe(listScroller);
			}
		} else {
			window.addEventListener('resize', resize);
		}
		window.addEventListener('beforeprint', () => setRosterPaintAll(true));
		window.addEventListener('afterprint', () => setRosterPaintAll(false));
		if (typeof window.matchMedia === 'function') {
			try {
				const printMq = window.matchMedia('print');
				const onPrintMq = (ev) => setRosterPaintAll(!!ev.matches);
				if (typeof printMq.addEventListener === 'function') {
					printMq.addEventListener('change', onPrintMq);
				} else if (typeof printMq.addListener === 'function') {
					printMq.addListener(onPrintMq);
				}
			} catch (_) {
				/* print media query is not available in every test/jsdom shim */
			}
		}
	}

	function applyRosterViewChrome(view) {
		gridState.view = view === 'list' ? 'list' : 'grid';
		const gridWrap = document.getElementById('dc-roster-grid-wrap');
		const listPanel = document.getElementById('dc-roster-list-panel');
		const gridBtn = document.getElementById('dc-roster-view-grid');
		const listBtn = document.getElementById('dc-roster-view-list');
		if (gridWrap) {
			gridWrap.hidden = gridState.view !== 'grid';
		}
		if (listPanel) {
			listPanel.hidden = gridState.view !== 'list';
		}
		if (gridBtn) {
			gridBtn.setAttribute('aria-pressed', gridState.view === 'grid' ? 'true' : 'false');
		}
		if (listBtn) {
			listBtn.setAttribute('aria-pressed', gridState.view === 'list' ? 'true' : 'false');
		}
	}

	function setRosterView(view) {
		applyRosterViewChrome(view);
		window.requestAnimationFrame(() => {
			if (gridState.view === 'list') {
				paintListWindow({ force: true });
			} else {
				paintGridWindow({ force: true });
			}
		});
	}

	async function applyBulkFromTemplate() {
		const sel = document.getElementById('dc-roster-bulk-template');
		const tplId = Number(sel?.value || 0);
		const tpl = (state.templates || []).find((t) => Number(t.id) === tplId);
		if (!tpl || gridState.selected.size < 1) {
			Msg.announce(t('dutycheck', 'Pick a template and select empty cells first.'), 'warning');
			return;
		}
		if (!Number(tpl.locationId || 0)) {
			Msg.announce(t('dutycheck', 'This template has no location. Edit the template in Settings, then try again.'), 'warning');
			return;
		}
		const periodId = Number(selectedPeriodId() || 0);
		let ok = 0;
		let fail = 0;
		for (const key of Array.from(gridState.selected)) {
			const sep = key.indexOf('|');
			if (sep < 1) {
				fail += 1;
				continue;
			}
			const employeeId = Number(key.slice(0, sep));
			const dutyDate = key.slice(sep + 1);
			if (!Number.isInteger(employeeId) || employeeId < 1 || !dutyDate) {
				fail += 1;
				continue;
			}
			const locationId = Number(tpl.locationId || 0);
			if (!locationId) {
				fail += 1;
				continue;
			}
			try {
				await submitAssignment({
					periodId,
					employeeId: Number(employeeId),
					locationId,
					dutyDate,
					startTime: String(tpl.startTime).slice(0, 5),
					endTime: String(tpl.endTime).slice(0, 5),
					breakMinutes: Number(tpl.breakMinutes || 0),
					note: '',
					assignmentId: 0,
				}, true);
				ok += 1;
			} catch {
				fail += 1;
			}
		}
		gridState.selected.clear();
		await loadRoster(periodId || null);
		Msg.announce(
			t('dutycheck', 'Bulk fill finished: {ok} saved, {fail} skipped.')
				.replace('{ok}', String(ok))
				.replace('{fail}', String(fail)),
			fail ? 'warning' : 'info',
		);
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
		const editId = Number(payload.assignmentId || state.editingAssignmentId || 0);
		try {
			if (editId > 0) {
				return await Api.put(`/apps/dutycheck/api/assignments/${editId}`, payload);
			}
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
			// Prefer omit over disable (CORE §7.1) — hide when the period cannot accept writes.
			btn.hidden = !allowed;
			btn.disabled = !allowed;
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
		fillTemplateSelect();
		D?.applyLocaleToTemporalInputs(form);
	}

	function resetAssignmentFormAfterSave(data) {
		const form = document.getElementById('dc-assignment-form');
		if (!form) {
			return;
		}
		form.reset();
		state.editingAssignmentId = null;
		const idHidden = document.getElementById('dc-assignment-id');
		if (idHidden) {
			idHidden.value = '';
		}
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
		state.editingAssignmentId = null;
		const idHidden = document.getElementById('dc-assignment-id');
		if (idHidden) {
			idHidden.value = '';
		}
		await ensureAssignmentModalPrereqs();
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
				state.editingAssignmentId = null;
				restoreAssignmentFormHost();
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

	async function openAssignmentEditModal(assignment, triggerEl) {
		if (!canAddAssignment() || !assignment?.id) {
			Msg.announce(addAssignmentDisabledReason(), 'warning');
			return;
		}
		state.editingAssignmentId = Number(assignment.id);
		state.editingAssignmentVersion = Number(assignment.version ?? 0);
		await ensureAssignmentModalPrereqs();
		prepareAssignmentFormForModal();
		const idHidden = document.getElementById('dc-assignment-id');
		if (idHidden) {
			idHidden.value = String(assignment.id);
		}
		const dateInput = document.getElementById('dc-assignment-date');
		if (dateInput) {
			dateInput.value = String(assignment.dutyDate || '');
		}
		refreshAssignmentFormEligibility();
		const emp = document.getElementById('dc-assignment-employee');
		if (emp) {
			emp.value = String(assignment.employeeId || '');
		}
		const loc = document.getElementById('dc-assignment-location');
		if (loc) {
			loc.value = String(assignment.locationId || '');
		}
		const start = document.getElementById('dc-assignment-start');
		if (start) {
			start.value = String(assignment.startTime || '').slice(0, 5);
		}
		const end = document.getElementById('dc-assignment-end');
		if (end) {
			end.value = String(assignment.endTime || '').slice(0, 5);
		}
		const br = document.getElementById('dc-assignment-break');
		if (br) {
			br.value = String(assignment.breakMinutes ?? 0);
		}
		const note = document.getElementById('dc-assignment-note');
		if (note) {
			note.value = String(assignment.note || '');
		}
		D?.applyLocaleToTemporalInputs(document.getElementById('dc-assignment-form') || document);
		clearAssignmentsSectionSuccess();
		const panel = document.getElementById('dc-assignment-form-panel');
		if (!panel) {
			return;
		}
		const instance = C.openModal({
			title: t('dutycheck', 'Edit assignment'),
			dialogClass: 'dc-modal__dialog--roster-assignment',
			primaryLabel: t('dutycheck', 'Save changes'),
			cancelLabel: t('dutycheck', 'Cancel'),
			render: () => panel,
			onSubmit: async () => performAssignmentSave(),
			onClose: () => {
				assignmentModalInstance = null;
				state.editingAssignmentId = null;
				restoreAssignmentFormHost();
				clearAssignmentFormSuccess();
				if (triggerEl && typeof triggerEl.focus === 'function') {
					triggerEl.focus();
				}
			},
		});
		assignmentModalInstance = instance;
		syncAssignmentModalPrimary(instance);
	}

	async function cancelAssignmentRow(assignment, triggerEl) {
		if (!assignment?.id || !canAddAssignment()) {
			return;
		}
		const ok = window.confirm(
			t('dutycheck', 'Cancel this shift? It will be removed from the open period and staff will not see it after publish.'),
		);
		if (!ok) {
			return;
		}
		try {
			const response = await Api.post(`/apps/dutycheck/api/assignments/${assignment.id}/cancel`, {});
			render(response?.data || {});
			showAssignmentsSectionSuccess(t('dutycheck', 'Shift cancelled.'));
			Msg.announce(t('dutycheck', 'Shift cancelled.'), 'success');
		} catch (err) {
			Msg.handleApiError(err);
		} finally {
			if (triggerEl && typeof triggerEl.focus === 'function') {
				triggerEl.focus();
			}
		}
	}

	let templatesInflight = null;

	async function ensureAssignmentModalPrereqs() {
		const jobs = [loadTemplates()];
		if (state.planningDefaultsFresh !== true) {
			jobs.push(refreshPlanningDefaultFromServer());
		}
		await Promise.all(jobs);
	}

	async function loadTemplates() {
		if (state.templatesLoaded) {
			fillTemplateSelect();
			return;
		}
		if (templatesInflight) {
			await templatesInflight;
			fillTemplateSelect();
			return;
		}
		templatesInflight = (async () => {
			try {
				const response = await Api.get('/apps/dutycheck/api/templates', {});
				state.templates = Array.isArray(response?.data) ? response.data : [];
				state.templatesLoaded = true;
			} catch (_) {
				state.templates = [];
				state.templatesLoaded = false;
			} finally {
				templatesInflight = null;
			}
		})();
		await templatesInflight;
		fillTemplateSelect();
	}

	function fillTemplateSelect() {
		const select = document.getElementById('dc-assignment-template');
		if (!select) {
			return;
		}
		const current = select.value;
		select.replaceChildren();
		select.appendChild(create('option', { value: '', text: t('dutycheck', 'No template — enter times manually') }));
		for (const tpl of state.templates) {
			const label = `${tpl.name || ''} (${String(tpl.startTime || '').slice(0, 5)}–${String(tpl.endTime || '').slice(0, 5)})`;
			select.appendChild(create('option', { value: String(tpl.id), text: label }));
		}
		if (current && [...select.options].some((o) => o.value === current)) {
			select.value = current;
		}
	}

	function applySelectedTemplate() {
		const select = document.getElementById('dc-assignment-template');
		if (!select || !select.value) {
			return;
		}
		const tpl = state.templates.find((t) => String(t.id) === String(select.value));
		if (!tpl) {
			return;
		}
		const start = document.getElementById('dc-assignment-start');
		const end = document.getElementById('dc-assignment-end');
		const br = document.getElementById('dc-assignment-break');
		const loc = document.getElementById('dc-assignment-location');
		if (start) start.value = String(tpl.startTime || '').slice(0, 5);
		if (end) end.value = String(tpl.endTime || '').slice(0, 5);
		if (br) br.value = String(tpl.breakMinutes ?? 0);
		if (loc && tpl.locationId) loc.value = String(tpl.locationId);
		D?.applyLocaleToTemporalInputs(document.getElementById('dc-assignment-form') || document);
		refreshAssignmentFormEligibility();
	}

	function refreshAcknowledgeStats(periodId) {
		const el = document.getElementById('dc-roster-ack-stats');
		if (!el) {
			return;
		}
		const id = Number(periodId);
		if (!Number.isInteger(id) || id <= 0) {
			el.hidden = true;
			el.textContent = '';
			return;
		}
		const period = state.periods.find((p) => Number(p.id) === id);
		const status = String(period?.status || '').toLowerCase();
		if (status !== 'published' && status !== 'closed') {
			el.hidden = true;
			el.textContent = '';
			return;
		}
		const rows = Array.isArray(state.assignments) ? state.assignments : [];
		let total = 0;
		let acked = 0;
		for (const row of rows) {
			if (String(row?.status || '').toLowerCase() === 'cancelled') {
				continue;
			}
			total += 1;
			if (row?.acknowledged || row?.acknowledgedAt) {
				acked += 1;
			}
		}
		const pct = total === 0 ? 0 : Math.round((acked / total) * 1000) / 10;
		el.hidden = false;
		el.textContent = t('dutycheck', 'Staff seen: {acked}/{total} ({pct}%)')
			.replace('{acked}', String(acked))
			.replace('{total}', String(total))
			.replace('{pct}', String(pct));
	}

	function fillCopySourceSelect(selectedPeriodId) {
		const select = document.getElementById('dc-roster-copy-source');
		const wrap = document.getElementById('dc-roster-copy-period');
		const applyBtn = document.getElementById('dc-roster-copy-apply');
		if (!select || !wrap) {
			return;
		}
		const period = state.periods.find((p) => Number(p.id) === Number(selectedPeriodId));
		const open = period && String(period.status || '').toLowerCase() === 'open';
		wrap.hidden = !open;
		state.copyPreviewReady = false;
		if (applyBtn) applyBtn.disabled = true;
		select.replaceChildren();
		select.appendChild(create('option', { value: '', text: t('dutycheck', 'Choose a source period…') }));
		for (const p of state.periods) {
			if (Number(p.id) === Number(selectedPeriodId)) {
				continue;
			}
			const label = `${p.startDate || ''} → ${p.endDate || ''} (${p.status || ''})`;
			select.appendChild(create('option', { value: String(p.id), text: label }));
		}
	}

	async function runCopyPeriod(dryRun) {
		const targetId = Number(document.getElementById('dc-roster-period-switcher')?.value || 0);
		const sourceId = Number(document.getElementById('dc-roster-copy-source')?.value || 0);
		const status = document.getElementById('dc-roster-copy-status');
		const applyBtn = document.getElementById('dc-roster-copy-apply');
		if (!targetId || !sourceId) {
			Msg.announce(t('dutycheck', 'Choose a source period first.'), 'warning');
			return;
		}
		try {
			const response = await Api.post(`/apps/dutycheck/api/periods/${targetId}/copy`, {
				sourcePeriodId: sourceId,
				dryRun: !!dryRun,
			});
			const data = response?.data || {};
			const created = Number(data.created ?? data.wouldCreate ?? data.count ?? 0);
			const skipped = Number(data.skipped ?? 0);
			const msg = dryRun
				? t('dutycheck', 'Preview: {created} would be copied, {skipped} skipped. Review, then Apply copy.')
					.replace('{created}', String(data.wouldCreate ?? data.previewCreated ?? created))
					.replace('{skipped}', String(data.wouldSkip ?? skipped))
				: t('dutycheck', 'Copied {created} assignment(s). Conflicts were recomputed.')
					.replace('{created}', String(created));
			if (status) {
				status.hidden = false;
				status.textContent = msg;
			}
			Msg.announce(msg, dryRun ? 'info' : 'success');
			if (dryRun) {
				state.copyPreviewReady = true;
				if (applyBtn) applyBtn.disabled = false;
			} else {
				state.copyPreviewReady = false;
				if (applyBtn) applyBtn.disabled = true;
				render(data.roster || data || {});
				if (!data.roster && data.assignments) {
					render(data);
				} else if (!data.roster) {
					await loadRoster(targetId);
				}
			}
		} catch (err) {
			Msg.handleApiError(err);
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
			state.planningDefaultsFresh = true;
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
		fillCopySourceSelect(data.selectedPeriodId);
		refreshAcknowledgeStats(data.selectedPeriodId);
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
					C.renderTableFetchError(tbody, 8, t('dutycheck', 'Could not load the roster. Reload the page or contact an administrator if this keeps happening.'));
					return null;
				}
			}
			Msg.handleApiError(err);
			C.renderTableFetchError(tbody, 8, t('dutycheck', 'Could not load the roster. Reload the page or contact an administrator if this keeps happening.'));
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
		const assignmentId = Number(data.get('assignmentId') || state.editingAssignmentId || 0);
		const out = {
			periodId: Number(data.get('periodId')),
			employeeId: Number(data.get('employeeId')),
			locationId: Number(data.get('locationId')),
			dutyDate: String(data.get('dutyDate') || ''),
			startTime,
			endTime,
			breakMinutes: Number(data.get('breakMinutes') || 0),
			note: String(data.get('note') || '').trim(),
			assignmentId: Number.isInteger(assignmentId) && assignmentId > 0 ? assignmentId : 0,
		};
		if (out.assignmentId > 0 && state.editingAssignmentVersion !== null && state.editingAssignmentVersion !== undefined) {
			out.expectedVersion = Number(state.editingAssignmentVersion);
		}
		return out;
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
			case 'STALE_VERSION':
				return t('dutycheck', 'Someone else changed this assignment. Reload the roster and try again.');
			case 'CONFLICT_ACK_STALE':
				return t('dutycheck', 'Someone else already confirmed this exception. Reload to see the latest checks.');
			case 'COMPANY_MEMBERSHIP_REQUIRED':
				return t('dutycheck', 'Your account is not on a company yet. Ask an administrator to add you under Settings → Companies.');
			case 'EXPECTED_VERSION_REQUIRED':
				return t('dutycheck', 'This edit is out of date. Reload the roster and open the assignment again.');
			case 'ABSENCE_CONFLICT':
				return t('dutycheck', 'This employee has an approved absence on that date.');
			case 'CONFLICT_ACK_REQUIRED':
				return t('dutycheck', 'A planning rule needs your confirmation before this shift can be saved.');
			case 'REASON_TOO_SHORT':
				return t('dutycheck', 'Acknowledgement reason must contain at least 10 characters.');
			case 'INTERNAL_ERROR':
				return t('dutycheck', 'The server could not save this assignment. Reload the page and try again, or contact an administrator.');
			case 'QUALIFICATION_MISSING':
				return t('dutycheck', 'This employee is missing a required qualification for that location.');
			case 'ASSIGNMENT_NOT_FOUND':
				return t('dutycheck', 'That assignment no longer exists. Reload the page.');
			case 'SCHEMA_NOT_READY':
				return t('dutycheck', 'DutyCheck database upgrade is incomplete. Ask an administrator to run occ upgrade.');
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


	async function loadPendingSwaps() {
		const list = document.getElementById('dc-swap-list');
		const empty = document.getElementById('dc-swap-empty');
		if (!list) return;
		list.replaceChildren();
		try {
			const res = await Api.get('/apps/dutycheck/api/swaps');
			const rows = Array.isArray(res?.data) ? res.data : [];
			if (empty) empty.hidden = rows.length > 0;
			for (const row of rows) {
				const li = create('li', { class: 'dc-conflicts__item' });
				li.appendChild(create('p', {
					text: row.toEmployeeId
						? t('dutycheck', 'Assignment #{id} · from #{from} → employee #{to}')
							.replace('{id}', String(row.assignmentId))
							.replace('{from}', String(row.fromEmployeeId))
							.replace('{to}', String(row.toEmployeeId))
						: t('dutycheck', 'Assignment #{id} · from employee #{from} → open pool')
							.replace('{id}', String(row.assignmentId))
							.replace('{from}', String(row.fromEmployeeId)),
				}));
				const approve = create('button', { type: 'button', class: 'button primary', text: t('dutycheck', 'Approve') });
				const reject = create('button', { type: 'button', class: 'button', text: t('dutycheck', 'Reject') });
				approve.style.minHeight = '44px';
				reject.style.minHeight = '44px';
				approve.addEventListener('click', async () => {
					try {
						await Api.post(`/apps/dutycheck/api/swaps/${row.id}/review`, { decision: 'approved' });
						Msg.announce(t('dutycheck', 'Swap approved.'), 'success');
						await loadPendingSwaps();
						await loadRoster(selectedPeriodFromState()?.id || null);
					} catch (err) {
						Msg.handleApiError(err);
					}
				});
				reject.addEventListener('click', async () => {
					try {
						await Api.post(`/apps/dutycheck/api/swaps/${row.id}/review`, { decision: 'rejected' });
						Msg.announce(t('dutycheck', 'Swap rejected.'), 'info');
						await loadPendingSwaps();
					} catch (err) {
						Msg.handleApiError(err);
					}
				});
				li.appendChild(approve);
				li.appendChild(reject);
				list.appendChild(li);
			}
		} catch (_) {
			if (empty) {
				empty.hidden = false;
				empty.textContent = t('dutycheck', 'Could not load swap requests.');
			}
		}
	}

	async function approveOpenShiftClaim(openShiftId, acknowledgements = [], retryWithAck = true) {
		try {
			return await Api.post(`/apps/dutycheck/api/open-shifts/${openShiftId}/approve`, {
				acknowledgements,
			});
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
						? t('dutycheck', 'Briefly explain why you are approving this claim anyway (at least 10 characters).\n\n{details}')
							.replace('{details}', summary)
						: t('dutycheck', 'Briefly explain why you are approving this claim anyway (at least 10 characters).'),
					confirmLabel: t('dutycheck', 'Approve with confirmation'),
					cancelLabel: t('dutycheck', 'Cancel'),
					minLength: 10,
				});
				if (reason === null || reason.length < 10) {
					throw error;
				}
				const acks = conflictTypes.map((type) => ({ conflictType: type, reason }));
				return approveOpenShiftClaim(openShiftId, acks, false);
			}
			throw error;
		}
	}

	async function loadPendingOpenClaims() {
		const list = document.getElementById('dc-open-claim-list');
		const empty = document.getElementById('dc-open-claim-empty');
		if (!list) return;
		list.replaceChildren();
		try {
			const res = await Api.get('/apps/dutycheck/api/open-shifts/pending');
			const rows = Array.isArray(res?.data) ? res.data : [];
			if (empty) empty.hidden = rows.length > 0;
			for (const row of rows) {
				const li = create('li', { class: 'dc-conflicts__item' });
				const when = D?.formatDisplayDate?.(row.dutyDate) || row.dutyDate;
				li.appendChild(create('p', {
					text: t('dutycheck', '{date} · employee #{emp}')
						.replace('{date}', String(when))
						.replace('{emp}', String(row.claimedByEmployeeId || '—')),
				}));
				const approve = create('button', { type: 'button', class: 'button primary', text: t('dutycheck', 'Approve claim') });
				const reject = create('button', { type: 'button', class: 'button', text: t('dutycheck', 'Reject claim') });
				approve.style.minHeight = '44px';
				reject.style.minHeight = '44px';
				approve.addEventListener('click', async () => {
					try {
						await approveOpenShiftClaim(row.id);
						Msg.announce(t('dutycheck', 'Claim approved — assignment created.'), 'success');
						await loadPendingOpenClaims();
						await loadRoster(selectedPeriodFromState()?.id || null);
					} catch (err) {
						Msg.handleApiError(err);
					}
				});
				reject.addEventListener('click', async () => {
					try {
						await Api.post(`/apps/dutycheck/api/open-shifts/${row.id}/reject`, {});
						Msg.announce(t('dutycheck', 'Claim rejected.'), 'info');
						await loadPendingOpenClaims();
					} catch (err) {
						Msg.handleApiError(err);
					}
				});
				li.appendChild(approve);
				li.appendChild(reject);
				list.appendChild(li);
			}
		} catch (_) {
			if (empty) {
				empty.hidden = false;
				empty.textContent = t('dutycheck', 'Could not load pending claims.');
			}
		}
	}

	function wireMarketplace() {
		const toggle = document.getElementById('dc-open-shift-create');
		const form = document.getElementById('dc-open-shift-form');
		toggle?.addEventListener('click', () => {
			if (!form) return;
			form.hidden = !form.hidden;
			const loc = document.getElementById('dc-os-location');
			if (loc && state.locations?.length) {
				loc.replaceChildren();
				for (const l of state.locations) {
					loc.appendChild(create('option', { value: String(l.id), text: String(l.name || l.id) }));
				}
			}
			const period = selectedPeriodFromState();
			const date = document.getElementById('dc-os-date');
			if (date && period?.startDate) date.value = String(period.startDate);
		});
		document.getElementById('dc-os-save')?.addEventListener('click', async () => {
			const period = selectedPeriodFromState();
			if (!period?.id) {
				Msg.announce(t('dutycheck', 'Select a period first.'), 'warning');
				return;
			}
			try {
				await Api.post('/apps/dutycheck/api/open-shifts', {
					periodId: Number(period.id),
					locationId: Number(document.getElementById('dc-os-location')?.value || 0),
					dutyDate: String(document.getElementById('dc-os-date')?.value || ''),
					startTime: String(document.getElementById('dc-os-start')?.value || ''),
					endTime: String(document.getElementById('dc-os-end')?.value || ''),
					breakMinutes: 0,
				});
				Msg.announce(t('dutycheck', 'Open shift posted.'), 'success');
				if (form) form.hidden = true;
			} catch (err) {
				Msg.handleApiError(err);
			}
		});
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
		wireMarketplace();
		setRosterView('grid');
		wireRosterVirtualization();
		document.getElementById('dc-roster-view-grid')?.addEventListener('click', () => setRosterView('grid'));
		document.getElementById('dc-roster-view-list')?.addEventListener('click', () => setRosterView('list'));
		document.getElementById('dc-roster-bulk-clear')?.addEventListener('click', () => {
			gridState.selected.clear();
			syncVisibleGridChrome();
			updateBulkBar();
		});
		document.getElementById('dc-roster-bulk-apply')?.addEventListener('click', () => {
			void applyBulkFromTemplate();
		});
		await Promise.all([
			loadRoster(selectedPeriodIdFromUrl()),
			loadPendingSwaps(),
			loadPendingOpenClaims(),
		]);

		const switcher = document.getElementById('dc-roster-period-switcher');
		switcher?.addEventListener('change', async () => {
			clearAssignmentsSectionSuccess();
			const periodId = Number(switcher.value);
			await Promise.all([
				loadRoster(Number.isInteger(periodId) && periodId > 0 ? periodId : null),
				loadPendingSwaps(),
				loadPendingOpenClaims(),
			]);
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

		document.getElementById('dc-assignment-template')?.addEventListener('change', () => {
			applySelectedTemplate();
		});
		document.getElementById('dc-roster-copy-preview')?.addEventListener('click', () => {
			runCopyPeriod(true);
		});
		document.getElementById('dc-roster-copy-apply')?.addEventListener('click', async () => {
			if (!state.copyPreviewReady) {
				Msg.announce(t('dutycheck', 'Run Preview copy first.'), 'warning');
				return;
			}
			await runCopyPeriod(false);
		});
		document.getElementById('dc-roster-copy-source')?.addEventListener('change', () => {
			state.copyPreviewReady = false;
			const applyBtn = document.getElementById('dc-roster-copy-apply');
			if (applyBtn) applyBtn.disabled = true;
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
