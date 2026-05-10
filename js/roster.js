(function () {
	'use strict';

	const Api = window.DutyCheckApi;
	const Msg = window.DutyCheckMessaging;
	const C = window.DutyCheckComponents;
	const D = window.DutyCheckDates;
	const create = C.createElement;

	const state = {
		periods: [],
		employees: [],
		locations: [],
	};

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
		const label = sev === 'hard'
			? t('dutycheck', 'Hard')
			: (sev === 'soft' ? t('dutycheck', 'Soft') : t('dutycheck', 'Info'));
		return create('span', { class: 'dc-severity dc-severity--' + sev, text: label });
	}

	function renderConflicts(conflicts) {
		const list = document.getElementById('dc-conflict-list');
		const summary = document.getElementById('dc-conflict-summary');
		if (!list) return;
		list.replaceChildren();
		const hard = conflicts.filter((c) => c?.severity === 'hard').length;
		const soft = conflicts.filter((c) => c?.severity === 'soft').length;
		const softUnack = conflicts.filter((c) => c?.severity === 'soft' && !c?.acknowledged).length;
		if (summary) {
			summary.textContent = !conflicts.length
				? t('dutycheck', 'No conflicts detected.')
				: t('dutycheck', '{hard} hard · {soft} soft ({unack} unacknowledged)')
					.replace('{hard}', String(hard))
					.replace('{soft}', String(soft))
					.replace('{unack}', String(softUnack));
		}
		if (!conflicts.length) {
			return;
		}
		for (const conflict of conflicts) {
			const li = create('li', { class: 'dc-conflict dc-conflict--' + (conflict?.severity || 'info') });
			li.appendChild(severityBadge(conflict?.severity));
			const conflictMessage = String(conflict?.message || '');
			const body = create('div', { class: 'dc-conflict__body' }, [
				create('span', {
					class: 'dc-conflict__title',
					text: conflictMessage
						? translateConflictMessage(conflictMessage)
						: t('dutycheck', 'Unknown conflict'),
				}),
			]);
			if (conflict?.acknowledged && conflict?.ackReason) {
				body.appendChild(create('span', {
					class: 'dc-conflict__ack',
					text: t('dutycheck', 'Acknowledged: {reason}').replace('{reason}', String(conflict.ackReason)),
				}));
			} else if (conflict?.ackInvalidated) {
				body.appendChild(create('span', {
					class: 'dc-conflict__ack-invalid',
					text: t('dutycheck', 'Acknowledgement invalidated by changes - re-acknowledge required'),
				}));
			}
			li.appendChild(body);

			const actions = create('div', { class: 'dc-conflict__actions' });
			if (conflict?.severity === 'soft' && conflict?.id && !conflict?.acknowledged) {
				const ackBtn = create('button', {
					type: 'button',
					class: 'button',
					text: t('dutycheck', 'Acknowledge'),
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
			title: t('dutycheck', 'Acknowledge conflict'),
			label: t('dutycheck', 'Acknowledgement reason (minimum 10 characters)'),
			confirmLabel: t('dutycheck', 'Acknowledge'),
			cancelLabel: t('dutycheck', 'Cancel'),
			minLength: 10,
		});
		if (reason === null) return;
		try {
			const response = await Api.post(`/apps/dutycheck/api/conflicts/${conflictId}/acknowledge`, { reason });
			render(response?.data || {});
			Msg.announce(t('dutycheck', 'Conflict acknowledged.'));
		} catch (err) {
			Msg.handleApiError(err);
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
			const tr = create('tr');
			const td = create('td', { text: t('dutycheck', 'No assignments in this period yet.') });
			td.colSpan = 7;
			tr.appendChild(td);
			tbody.appendChild(tr);
			return;
		}
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

	async function submitAssignment(payload, retryWithAck = true) {
		try {
			return await Api.post('/apps/dutycheck/api/assignments', payload);
		} catch (error) {
			const code = String(error?.payload?.error?.code || '');
			if (retryWithAck && code === 'CONFLICT_ACK_REQUIRED') {
				const conflictTypes = uniqueConflictTypes(error?.payload?.error?.conflicts || []);
				if (!conflictTypes.length) throw error;
				const reason = await C.promptReason({
					title: t('dutycheck', 'Soft conflicts require acknowledgement'),
					label: t('dutycheck', 'Enter acknowledgement reason (minimum 10 characters)'),
					confirmLabel: t('dutycheck', 'Continue'),
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

	function updateActiveScopeBanner(selectedPeriodId) {
		const el = document.getElementById('dc-roster-active-scope');
		if (!el) return;
		const period = state.periods.find((p) => Number(p.id) === Number(selectedPeriodId));
		if (!period) {
			el.textContent = '';
			el.hidden = true;
			return;
		}
		const statusLabelMap = {
			open: t('dutycheck', 'Open'),
			published: t('dutycheck', 'Published'),
			closed: t('dutycheck', 'Closed'),
		};
		const statusKey = String(period.status || '').toLowerCase();
		const status = statusLabelMap[statusKey] || String(period.status || '').toUpperCase();
		const start = D?.formatDisplayDate(period.startDate) || period.startDate;
		const end = D?.formatDisplayDate(period.endDate) || period.endDate;
		const tpl = getRosterPhpL10n()?.selectedPeriod
			|| t('dutycheck', 'Selected period: {start} – {end} · {status}');
		el.textContent = tpl
			.replace('{start}', start)
			.replace('{end}', end)
			.replace('{status}', status);
		el.hidden = false;
	}

	function render(data) {
		state.periods = data.periods || [];
		state.employees = data.employees || [];
		state.locations = data.locations || [];
		fillPeriodSwitcher(state.periods, data.selectedPeriodId);
		updateActiveScopeBanner(data.selectedPeriodId);
		const periodHidden = document.getElementById('dc-assignment-period');
		if (periodHidden) {
			periodHidden.value = data.selectedPeriodId ? String(data.selectedPeriodId) : '';
		}
		const dateInput = document.getElementById('dc-assignment-date');
		const selectedPeriod = state.periods.find((p) => Number(p.id) === Number(data.selectedPeriodId));
		if (dateInput && selectedPeriod?.startDate && selectedPeriod?.endDate) {
			dateInput.min = String(selectedPeriod.startDate);
			dateInput.max = String(selectedPeriod.endDate);
		} else if (dateInput) {
			dateInput.removeAttribute('min');
			dateInput.removeAttribute('max');
		}
		fillSelect('dc-assignment-employee', state.employees);
		fillSelect('dc-assignment-location', state.locations);
		renderAssignments(data.assignments || []);
		renderConflicts(data.conflicts || []);
		renderSetupCallout(data);
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
		const br = document.getElementById('dc-assignment-break');
		if (br) br.value = '0';
		D?.applyLocaleToTemporalInputs(form);
		Msg.announce(t('dutycheck', 'Form cleared.'));
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
		if (!Number.isInteger(payload.employeeId) || payload.employeeId <= 0) {
			return t('dutycheck', 'Select an employee.');
		}
		if (!Number.isInteger(payload.locationId) || payload.locationId <= 0) {
			return t('dutycheck', 'Select a location.');
		}
		if (!payload.dutyDate || !payload.startTime || !payload.endTime) {
			return t('dutycheck', 'Date, start time, and end time are required.');
		}
		if (payload.startTime === payload.endTime) {
			return t('dutycheck', 'Start and end time must be different.');
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
		return null;
	}

	function errorMessageFor(error) {
		const code = String(error?.payload?.error?.code || error?.code || '');
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
				return t('dutycheck', 'Acknowledgement is required for soft conflicts.');
			case 'REASON_TOO_SHORT':
				return t('dutycheck', 'Acknowledgement reason must contain at least 10 characters.');
			case 'INVALID_DATE':
			case 'INVALID_TIME':
				return t('dutycheck', 'Please check the dates and times.');
			default:
				return null;
		}
	}

	document.addEventListener('DOMContentLoaded', async () => {
		D?.applyLocaleToTemporalInputs(document);
		wireAdminExportGuards();
		await loadRoster(selectedPeriodIdFromUrl());

		const switcher = document.getElementById('dc-roster-period-switcher');
		switcher?.addEventListener('change', async () => {
			const periodId = Number(switcher.value);
			await loadRoster(Number.isInteger(periodId) && periodId > 0 ? periodId : null);
		});

		document.getElementById('dc-assignment-form-clear')?.addEventListener('click', () => {
			clearAssignmentFormFields();
		});

		const form = document.getElementById('dc-assignment-form');
		form?.addEventListener('submit', async (event) => {
			event.preventDefault();
			const payload = readForm(form);
			const validation = validate(payload);
			if (validation) {
				Msg.announce(validation, 'error');
				return;
			}
			try {
				const response = await submitAssignment(payload, true);
				render(response?.data || {});
				form.reset();
				const periodHidden = document.getElementById('dc-assignment-period');
				if (periodHidden && response?.data?.selectedPeriodId) {
					periodHidden.value = String(response.data.selectedPeriodId);
				}
				const br = document.getElementById('dc-assignment-break');
				if (br) br.value = '0';
				D?.applyLocaleToTemporalInputs(form);
				Msg.announce(t('dutycheck', 'Assignment saved.'));
			} catch (err) {
				const friendly = errorMessageFor(err);
				if (friendly) {
					Msg.announce(friendly, 'error');
				} else {
					Msg.handleApiError(err, { reloadOnConflict: false });
				}
			}
		});
	});
})();
