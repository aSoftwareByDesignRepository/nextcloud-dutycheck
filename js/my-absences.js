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

	function bannerDismissStorageKey(key) {
		return 'dc.at.banner.dismiss.' + String(key || 'dc-at-integration-banner-v1');
	}

	function isBannerDismissed(key) {
		try {
			return window.localStorage.getItem(bannerDismissStorageKey(key)) === '1';
		} catch {
			return false;
		}
	}

	function dismissBanner(key) {
		try {
			window.localStorage.setItem(bannerDismissStorageKey(key), '1');
		} catch {
			/* private mode */
		}
	}

	function applyReadonlyAbsenceUi(integration) {
		const banner = document.getElementById('dc-my-absences-integration-banner');
		const formSection = document.getElementById('dc-my-absence-form')?.closest('section');
		const readonly = Boolean(integration?.readonlyAbsencesForCurrentUser);
		const dismissKey = integration?.bannerDismissKey || 'dc-at-integration-banner-v1';
		if (banner) {
			if (readonly && !isBannerDismissed(dismissKey)) {
				const titleId = 'dc-my-absences-integration-banner-title';
				banner.setAttribute('role', 'region');
				banner.setAttribute('aria-labelledby', titleId);
				banner.classList.add('dc-at-banner');
				banner.hidden = false;
				const title = create('h2', { id: titleId, class: 'dc-callout__title', text: t('dutycheck', 'Your time off is managed in ArbeitszeitCheck.') });
				const intro = create('p', {
					text: t('dutycheck', 'You can still see your roster in DutyCheck. To request or change time off, use ArbeitszeitCheck.'),
				});
				const parts = [title, intro];
				if (integration?.integrationBreakerTripped) {
					parts.push(create('p', {
						class: 'dc-callout__hint',
						text: t('dutycheck', 'Automatic sync is paused. The table may be slightly out of date until sync works again.'),
					}));
				}
				if (integration?.integrationStale) {
					const last = integration?.integrationLastReconcileAt
						? String(integration.integrationLastReconcileAt)
						: t('dutycheck', 'Never synced — the connector will run shortly.');
					parts.push(create('p', {
						class: 'dc-callout__hint',
						text: t('dutycheck', 'Absence data may be out of date. Last sync: {time}.').replace('{time}', last),
					}));
				}
				const url = integration?.peerEmployeeOutboundUrl;
				const actions = create('div', { class: 'dc-callout__actions' });
				if (url) {
					actions.appendChild(create('a', {
						class: 'button primary',
						href: url,
						text: t('dutycheck', 'View or request absences in ArbeitszeitCheck'),
						attrs: {
							'aria-label': t('dutycheck', 'View or request absences in ArbeitszeitCheck'),
							'aria-describedby': titleId,
						},
					}));
				}
				const dismissBtn = create('button', {
					class: 'button',
					type: 'button',
					text: t('dutycheck', 'Hide this notice'),
					attrs: { 'aria-label': t('dutycheck', 'Hide this notice') },
				});
				dismissBtn.addEventListener('click', () => {
					dismissBanner(dismissKey);
					banner.hidden = true;
					banner.replaceChildren();
					const h1 = document.querySelector('#app-content h1, main h1, h1');
					if (h1 && typeof h1.focus === 'function') {
						if (!h1.hasAttribute('tabindex')) {
							h1.setAttribute('tabindex', '-1');
						}
						h1.focus();
					}
				});
				actions.appendChild(dismissBtn);
				parts.push(actions);
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
		const integ = integrationBootstrapFromDom();
		const readonly = Boolean(integ?.readonlyAbsencesForCurrentUser);
		if (!rows.length) {
			const tr = create('tr');
			let emptyMsg = t('dutycheck', 'No requests yet.');
			if (readonly) {
				emptyMsg = t('dutycheck', 'No absences shown here yet. Request time off in ArbeitszeitCheck — approved leave will appear for your planner after the next sync.');
			}
			const td = create('td', { text: emptyMsg });
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

			const sourceTd = create('td', {
				dataset: { cell: t('dutycheck', 'Source') },
				text: fromAt ? t('dutycheck', 'ArbeitszeitCheck') : t('dutycheck', 'DutyCheck'),
			});
			tr.appendChild(sourceTd);

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
			} else if (fromAt && row.piiHidden) {
				statusTd.appendChild(create('span', {
					class: 'dc-row-meta',
					text: t('dutycheck', 'Details for this absence are only available in ArbeitszeitCheck.'),
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

	// WF-30 / REQ-URL-02: refresh bootstrap on tab focus and every 5 minutes.
	let lastBootstrapRefresh = 0;
	const BOOTSTRAP_REFRESH_MS = 5 * 60 * 1000;
	async function refreshIntegrationBootstrap(force = false) {
		const now = Date.now();
		if (!force && now - lastBootstrapRefresh < BOOTSTRAP_REFRESH_MS) {
			return;
		}
		lastBootstrapRefresh = now;
		try {
			const response = await Api.get('/apps/dutycheck/api/bootstrap');
			const next = response?.data?.arbeitszeitCheckIntegration;
			if (!next || typeof next !== 'object') {
				return;
			}
			const root = document.getElementById('app-content');
			if (root) {
				root.dataset.dcIntegrationBootstrap = JSON.stringify(next);
			}
			applyReadonlyAbsenceUi(next);
		} catch {
			/* keep last good bootstrap */
		}
	}
	document.addEventListener('visibilitychange', () => {
		if (document.visibilityState === 'visible') {
			refreshIntegrationBootstrap(true);
		}
	});
	window.setInterval(() => refreshIntegrationBootstrap(false), BOOTSTRAP_REFRESH_MS);
})();
