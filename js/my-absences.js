(function () {
	'use strict';

	const Api = window.DutyCheckApi;
	const Msg = window.DutyCheckMessaging;
	const C = window.DutyCheckComponents;
	const D = window.DutyCheckDates;
	const create = C.createElement;

	const KIND_LABELS = {
		vacation: t('dutycheck', 'Vacation'),
		sick: t('dutycheck', 'Sick'),
		training: t('dutycheck', 'Training'),
		unpaid: t('dutycheck', 'Unpaid'),
		other: t('dutycheck', 'Other'),
	};
	const STATUS_LABEL = {
		pending: t('dutycheck', 'Pending'),
		approved: t('dutycheck', 'Approved'),
		rejected: t('dutycheck', 'Rejected'),
		cancelled: t('dutycheck', 'Cancelled'),
	};

	const TABLE_COLS = 4;

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

	function applyReadonlyAbsenceUi(integration) {
		const banner = document.getElementById('dc-my-absences-integration-banner');
		const formSection = document.getElementById('dc-my-absence-form')?.closest('section');
		const readonly = Boolean(integration?.readonlyAbsencesForCurrentUser);
		if (banner) {
			if (readonly) {
				const titleId = 'dc-my-absences-integration-banner-title';
				banner.setAttribute('role', 'region');
				banner.setAttribute('aria-labelledby', titleId);
				banner.hidden = false;
				const title = create('h2', { id: titleId, class: 'dc-callout__title', text: t('dutycheck', 'ArbeitszeitCheck integration') });
				const intro = create('p', {
					text: t('dutycheck', 'Your time off is managed in ArbeitszeitCheck. This page shows it to your planner. To request time off, use ArbeitszeitCheck.'),
				});
				const parts = [title, intro];
				if (integration?.integrationBreakerTripped) {
					parts.push(create('p', {
						class: 'dc-callout__hint',
						text: t('dutycheck', 'Automatic sync is paused. The table may be slightly out of date until sync works again.'),
					}));
				}
				if (integration?.integrationStale) {
					parts.push(create('p', {
						class: 'dc-callout__hint',
						text: t('dutycheck', 'Stale mirror — run sync or wait for the scheduled job.'),
					}));
				}
				const url = integration?.peerEmployeeOutboundUrl;
				if (url) {
					const actions = create('div', { class: 'dc-callout__actions' });
					actions.appendChild(create('a', {
						class: 'button primary',
						href: url,
						text: t('dutycheck', 'Open ArbeitszeitCheck'),
						attrs: { 'aria-describedby': titleId },
					}));
					parts.push(actions);
				}
				banner.replaceChildren(...parts);
			} else {
				banner.hidden = true;
				banner.removeAttribute('role');
				banner.removeAttribute('aria-labelledby');
				banner.replaceChildren();
			}
		}
		if (formSection) {
			formSection.hidden = readonly;
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

	function renderAbsences(rows) {
		const tbody = document.getElementById('dc-my-absences-table-body');
		if (!tbody) return;
		tbody.replaceChildren();
		if (!rows.length) {
			const tr = create('tr');
			const td = create('td', { text: t('dutycheck', 'No requests yet.') });
			td.colSpan = TABLE_COLS;
			tr.appendChild(td);
			tbody.appendChild(tr);
			return;
		}
		for (const row of rows) {
			const tr = create('tr');
			const start = D?.formatDisplayDate(row.startDate) || row.startDate;
			const end = D?.formatDisplayDate(row.endDate) || row.endDate;
			const status = String(row.status || '').toLowerCase();
			const fromAt = String(row.source || '') === 'arbeitszeitcheck';

			const sourceTh = create('th', {
				class: 'dc-table__rowhead',
				attrs: { scope: 'row' },
				dataset: { cell: t('dutycheck', 'Source') },
				text: fromAt ? t('dutycheck', 'ArbeitszeitCheck') : t('dutycheck', 'DutyCheck'),
			});
			tr.appendChild(sourceTh);

			const kindLabel = KIND_LABELS[row.kind] || String(row.kind || '—');
			const atNote = fromAt && row.atType ? ` (${String(row.atType)})` : '';
			const thKind = create('th', {
				class: 'dc-table__rowhead',
				attrs: { scope: 'row' },
				dataset: { cell: t('dutycheck', 'Type') },
				text: kindLabel + atNote,
			});
			tr.appendChild(thKind);

			const rangeTd = create('td', { text: `${start} – ${end}` });
			rangeTd.dataset.cell = t('dutycheck', 'Range');
			tr.appendChild(rangeTd);

			const statusTd = create('td');
			statusTd.dataset.cell = t('dutycheck', 'Status');
			statusTd.appendChild(create('span', {
				class: 'dc-status-badge dc-status-badge--' + status,
				text: STATUS_LABEL[status] || status.toUpperCase(),
			}));
			if ((status === 'rejected' || status === 'cancelled') && String(row.reviewReason || '').trim() !== '') {
				statusTd.appendChild(create('span', {
					class: 'dc-row-meta',
					text: t('dutycheck', 'Reason: {reason}').replace('{reason}', String(row.reviewReason)),
				}));
			}
			tr.appendChild(statusTd);
			tbody.appendChild(tr);
		}
	}

	async function loadAbsences() {
		const tbody = document.getElementById('dc-my-absences-table-body');
		C.setLoadingRow(tbody, TABLE_COLS);
		hideAccountAlert();
		applyReadonlyAbsenceUi(integrationBootstrapFromDom());
		try {
			const response = await Api.get('/apps/dutycheck/api/my/absences');
			renderAbsences(response?.data || []);
		} catch (err) {
			const code = String(err?.payload?.error?.code || '');
			if (code === 'EMPLOYEE_LINK_NOT_FOUND') {
				showAccountAlert(t('dutycheck', 'Your account is not linked to an employee record. Ask a planner to link your Nextcloud account before you can manage absences.'));
				const bodyEl = document.getElementById('dc-my-absences-table-body');
				if (bodyEl) {
					bodyEl.replaceChildren();
					const tr = create('tr');
					const td = create('td', { text: t('dutycheck', 'No request data — account not linked to an employee.') });
					td.colSpan = TABLE_COLS;
					tr.appendChild(td);
					bodyEl.appendChild(tr);
				}
				return;
			}
			Msg.handleApiError(err);
			C.renderTableFetchError(tbody, TABLE_COLS, t('dutycheck', 'Could not load your absences. Reload the page or contact an administrator if this keeps happening.'));
		} finally {
			C.clearLoadingRow(tbody);
		}
	}

	document.addEventListener('DOMContentLoaded', async () => {
		D?.applyLocaleToTemporalInputs(document);
		const form = document.getElementById('dc-my-absence-form');
		const submitBtn = document.getElementById('dc-my-absence-submit');
		const kindSelect = document.getElementById('dc-my-absence-kind');

		try {
			await loadAbsences();
		} catch (err) {
			Msg.handleApiError(err);
			return;
		}
		form?.addEventListener('submit', async (event) => {
			event.preventDefault();
			const data = new FormData(form);
			const startDate = String(data.get('startDate') || '');
			const endDate = String(data.get('endDate') || '');
			if (!startDate || !endDate) {
				Msg.announce(t('dutycheck', 'Both dates are required.'), 'error');
				return;
			}
			if (startDate > endDate) {
				Msg.announce(t('dutycheck', 'End date must be on or after start date.'), 'error');
				return;
			}
			if (submitBtn) submitBtn.disabled = true;
			form?.setAttribute('aria-busy', 'true');
			try {
				const response = await Api.post('/apps/dutycheck/api/my/absences', {
					kind: String(data.get('kind') || 'other'),
					startDate,
					endDate,
				});
				renderAbsences(response?.data || []);
				form.reset();
				if (kindSelect) kindSelect.value = 'vacation';
				Msg.announce(t('dutycheck', 'Request submitted.'));
			} catch (err) {
				const code = String(err?.payload?.error?.code || '');
				if (code === 'ABSENCE_OVERLAP') {
					Msg.announce(t('dutycheck', 'You already have an overlapping absence.'), 'error');
					return;
				}
				if (code === 'INVALID_DATE') {
					Msg.announce(t('dutycheck', 'Please provide valid dates.'), 'error');
					return;
				}
				if (code === 'INVALID_ABSENCE_RANGE') {
					Msg.announce(t('dutycheck', 'End date must be on or after start date.'), 'error');
					return;
				}
				if (code === 'INVALID_ABSENCE_KIND') {
					Msg.announce(t('dutycheck', 'Please choose a valid absence type.'), 'error');
					return;
				}
				if (code === 'EMPLOYEE_LINK_NOT_FOUND') {
					Msg.announce(t('dutycheck', 'Your account is not linked to an employee. Ask a planner to set this up.'), 'error');
					return;
				}
				if (code === 'INTEGRATION_ABSENCE_READONLY') {
					Msg.announce(t('dutycheck', 'Request time off in ArbeitszeitCheck. DutyCheck only lists it here for your planner.'), 'error');
					return;
				}
				Msg.handleApiError(err);
			} finally {
				form?.removeAttribute('aria-busy');
				if (submitBtn) submitBtn.disabled = false;
			}
		});
	});
})();
