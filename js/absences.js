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
	const STATUS_TRANSITIONS = {
		pending: ['approved', 'rejected', 'cancelled'],
		approved: ['cancelled'],
		rejected: ['pending'],
		cancelled: ['pending'],
	};
	const STATUS_LABEL = {
		pending: t('dutycheck', 'Pending'),
		approved: t('dutycheck', 'Approved'),
		rejected: t('dutycheck', 'Rejected'),
		cancelled: t('dutycheck', 'Cancelled'),
	};

	const TABLE_COLS = 6;

	/** @type {{ locksLinked: boolean, linkedEmployeeIds: Set<number> }} */
	let plannerAbsencesRenderContext = {
		locksLinked: false,
		linkedEmployeeIds: new Set(),
	};
	/** @type {Array<object>} */
	let lastPlannerEmployees = [];

	function buildLinkedEmployeeIdSet(employees) {
		const s = new Set();
		for (const e of employees || []) {
			if (e.linkedUserId != null && String(e.linkedUserId).trim() !== '') {
				const id = Number(e.id);
				if (Number.isInteger(id) && id > 0) {
					s.add(id);
				}
			}
		}
		return s;
	}

	function rosterUnlinkedStats(employees) {
		const list = employees || [];
		let unlinked = 0;
		for (const e of list) {
			if (e.linkedUserId == null || String(e.linkedUserId).trim() === '') {
				unlinked++;
			}
		}
		return { unlinked, total: list.length };
	}

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

	function actionLabel(target) {
		switch (target) {
			case 'approved': return t('dutycheck', 'Approve');
			case 'rejected': return t('dutycheck', 'Reject');
			case 'cancelled': return t('dutycheck', 'Cancel');
			case 'pending': return t('dutycheck', 'Re-open');
			default: return target;
		}
	}

	function integrationLocksLinked(integration) {
		return Boolean(integration?.integrationLocksLinkedDutyCheckAbsences);
	}

	function fillEmployeeSelect(employees, locksLinked) {
		const select = document.getElementById('dc-absence-employee');
		if (!select) return;
		select.replaceChildren();
		if (!employees.length) {
			const option = document.createElement('option');
			option.value = '';
			option.textContent = t('dutycheck', 'No active employees');
			select.appendChild(option);
			select.disabled = true;
			return;
		}
		select.disabled = false;
		for (const employee of employees) {
			const option = document.createElement('option');
			option.value = String(employee.id);
			const linked = employee.linkedUserId != null && String(employee.linkedUserId).trim() !== '';
			let label = String(employee.name || employee.displayName || employee.id);
			if (locksLinked && linked) {
				label += ' — ' + t('dutycheck', 'ArbeitszeitCheck');
				option.disabled = true;
				option.title = t('dutycheck', 'This employee is linked. While ArbeitszeitCheck integration is on, their absences are added there, not in DutyCheck.');
			}
			option.textContent = label;
			select.appendChild(option);
		}
	}

	function updatePlannerOnBehalfSection(employees, locksLinked) {
		const formSection = document.getElementById('dc-absence-form')?.closest('section');
		const callout = document.getElementById('dc-absences-all-linked-callout');
		if (!formSection || !callout) return;
		if (!employees.length || !locksLinked) {
			formSection.hidden = false;
			callout.hidden = true;
			callout.replaceChildren();
			return;
		}
		const allLinked = employees.every((e) => e.linkedUserId != null && String(e.linkedUserId).trim() !== '');
		if (allLinked) {
			formSection.hidden = true;
			callout.hidden = false;
			callout.textContent = t('dutycheck', 'Everyone here is linked to a Nextcloud account — add or change their absences in ArbeitszeitCheck. The table below still shows what you need for the roster.');
		} else {
			formSection.hidden = false;
			callout.hidden = true;
			callout.replaceChildren();
		}
	}

	function renderAbsences(absences, renderContextUpdate) {
		if (renderContextUpdate) {
			plannerAbsencesRenderContext = {
				locksLinked: Boolean(renderContextUpdate.locksLinked),
				linkedEmployeeIds: renderContextUpdate.linkedEmployeeIds instanceof Set
					? renderContextUpdate.linkedEmployeeIds
					: new Set(),
			};
		}
		const { locksLinked, linkedEmployeeIds } = plannerAbsencesRenderContext;
		const tbody = document.getElementById('dc-absences-table-body');
		if (!tbody) return;
		tbody.replaceChildren();
		if (!absences.length) {
			const tr = create('tr');
			const integ = integrationBootstrapFromDom();
			const locks = integrationLocksLinked(integ);
			let emptyMsg = t('dutycheck', 'No absence records yet.');
			if (locks) {
				const last = integ?.integrationLastReconcileAt
					? String(integ.integrationLastReconcileAt)
					: t('dutycheck', 'Never synced — the connector will run shortly.');
				emptyMsg = t('dutycheck', 'No absences in this list yet. Linked employees request time off in ArbeitszeitCheck. Last sync: {time}.')
					.replace('{time}', last);
			}
			const td = create('td', { text: emptyMsg });
			td.colSpan = TABLE_COLS;
			tr.appendChild(td);
			tbody.appendChild(tr);
			return;
		}
		for (const absence of absences) {
			const tr = create('tr');
			const start = D?.formatDisplayDate(absence.startDate) || absence.startDate;
			const end = D?.formatDisplayDate(absence.endDate) || absence.endDate;
			const status = String(absence.status || '').toLowerCase();
			const fromAt = String(absence.source || '') === 'arbeitszeitcheck';
			const empId = absence.employeeId != null ? Number(absence.employeeId) : NaN;
			const linkedRow = Number.isInteger(empId) && empId > 0 && linkedEmployeeIds.has(empId);
			const dcRowReadOnlyIntegration = !fromAt && linkedRow && locksLinked;

			const employeeTd = create('td', { text: String(absence.employeeName || '') });
			employeeTd.dataset.cell = t('dutycheck', 'Employee');
			tr.appendChild(employeeTd);

			const sourceTd = create('td', { text: fromAt ? t('dutycheck', 'ArbeitszeitCheck') : t('dutycheck', 'DutyCheck') });
			sourceTd.dataset.cell = t('dutycheck', 'Source');
			tr.appendChild(sourceTd);

			const typeLabel = KIND_LABELS[absence.kind] || String(absence.kind || '—');
			const atNote = fromAt && absence.atType ? ` (${String(absence.atType)})` : '';
			const kindTd = create('td', { text: typeLabel + atNote });
			kindTd.dataset.cell = t('dutycheck', 'Type');
			tr.appendChild(kindTd);

			const rangeTd = create('td', { text: `${start} – ${end}` });
			rangeTd.dataset.cell = t('dutycheck', 'Range');
			tr.appendChild(rangeTd);

			const statusTd = create('td');
			statusTd.dataset.cell = t('dutycheck', 'Status');
			statusTd.appendChild(create('span', {
				class: 'dc-status-badge dc-status-badge--' + status,
				text: STATUS_LABEL[status] || status.toUpperCase(),
			}));
			if ((status === 'rejected' || status === 'cancelled') && String(absence.reviewReason || '').trim() !== '') {
				statusTd.appendChild(create('span', {
					class: 'dc-row-meta',
					text: t('dutycheck', 'Reason: {reason}').replace('{reason}', String(absence.reviewReason)),
				}));
			}
			tr.appendChild(statusTd);

			const actionsTd = create('td', { class: 'dc-table__col--actions' });
			actionsTd.dataset.cell = t('dutycheck', 'Actions');
			const wrap = create('div', { class: 'dc-row-actions' });
			if (!fromAt && absence.id != null && !dcRowReadOnlyIntegration) {
				for (const target of (STATUS_TRANSITIONS[status] || [])) {
					const danger = target === 'rejected' || target === 'cancelled';
					const btn = create('button', {
						type: 'button',
						class: danger ? 'button danger' : 'button',
						text: actionLabel(target),
						attrs: { 'aria-label': t('dutycheck', '{action} absence for {name}').replace('{action}', actionLabel(target)).replace('{name}', String(absence.employeeName || '')) },
					});
					btn.addEventListener('click', () => transitionAbsence(absence.id, target));
					wrap.appendChild(btn);
				}
			} else if (fromAt) {
				const bits = [t('dutycheck', 'Imported absences are read-only. Approve or reject them in ArbeitszeitCheck.')];
				if (absence.piiHidden) {
					bits.push(t('dutycheck', 'Details for this absence are only available in ArbeitszeitCheck.'));
				}
				wrap.appendChild(create('span', {
					class: 'dc-row-meta',
					text: bits.join(' '),
				}));
				const peerUrl = String(integrationBootstrapFromDom()?.peerPlannerOutboundUrl || '').trim();
				if (peerUrl && peerUrl !== '#' && !/^javascript:/i.test(peerUrl)) {
					const openPeer = create('a', {
						class: 'button',
						href: peerUrl,
						text: t('dutycheck', 'Open ArbeitszeitCheck'),
						attrs: {
							target: '_blank',
							rel: 'noopener noreferrer',
							'aria-label': t('dutycheck', 'Open ArbeitszeitCheck'),
						},
					});
					wrap.appendChild(openPeer);
				}
			} else if (dcRowReadOnlyIntegration) {
				const legacyTargets = (STATUS_TRANSITIONS[status] || []).filter(
					(target) => target === 'cancelled' || target === 'rejected',
				);
				for (const target of legacyTargets) {
					const danger = target === 'rejected' || target === 'cancelled';
					const btn = create('button', {
						type: 'button',
						class: danger ? 'button danger' : 'button',
						text: actionLabel(target),
						attrs: {
							'aria-label': t('dutycheck', '{action} absence for {name}')
								.replace('{action}', actionLabel(target))
								.replace('{name}', String(absence.employeeName || '')),
						},
					});
					btn.addEventListener('click', () => transitionAbsence(absence.id, target));
					wrap.appendChild(btn);
				}
				wrap.appendChild(create('span', {
					class: 'dc-row-meta',
					text: t('dutycheck', 'Legacy DutyCheck row for a linked person — cancel it here to clear conflicts. New absences belong in ArbeitszeitCheck.'),
				}));
			}
			actionsTd.appendChild(wrap);
			tr.appendChild(actionsTd);
			tbody.appendChild(tr);
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

	function renderPlannerIntegrationBanner(integration, employees) {
		const el = document.getElementById('dc-absences-integration-banner');
		if (!el) return;
		const locks = integrationLocksLinked(integration);
		const dismissKey = integration?.bannerDismissKey || 'dc-at-integration-banner-v1';
		if (!locks || isBannerDismissed(dismissKey)) {
			el.hidden = true;
			el.removeAttribute('role');
			el.removeAttribute('aria-labelledby');
			el.replaceChildren();
			return;
		}
		const titleId = 'dc-absences-integration-banner-title';
		el.setAttribute('role', 'region');
		el.setAttribute('aria-labelledby', titleId);
		el.classList.add('dc-at-banner');
		el.hidden = false;
		const title = create('h2', { id: titleId, class: 'dc-callout__title', text: t('dutycheck', 'Absences for linked staff live in ArbeitszeitCheck') });
		const intro = create('p', {
			text: t('dutycheck', 'You can still plan shifts here. To add or change time off, open ArbeitszeitCheck.'),
		});
		const parts = [title, intro];
		const { unlinked, total } = rosterUnlinkedStats(employees);
		if (total > 0) {
			if (unlinked > 0) {
				parts.push(create('p', {
					class: 'dc-callout__hint',
					text: t('dutycheck', '{unlinked} of {total} active employees have no linked Nextcloud account — absences for them stay in DutyCheck until accounts are linked on the Employees page.')
						.replace('{unlinked}', String(unlinked))
						.replace('{total}', String(total)),
				}));
			} else {
				parts.push(create('p', {
					class: 'dc-callout__hint',
					text: t('dutycheck', 'All {total} active employees are linked — absences for them are entered in ArbeitszeitCheck.').replace('{total}', String(total)),
				}));
			}
		}
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
		const urls = C.getAppUrls();
		const peerUrl = integration?.peerPlannerOutboundUrl;
		const employeesUrl = urls?.employees;
		const actions = create('div', { class: 'dc-callout__actions' });
		if (peerUrl) {
			actions.appendChild(create('a', {
				class: 'button primary',
				href: peerUrl,
				text: t('dutycheck', 'Open ArbeitszeitCheck'),
				attrs: { 'aria-label': t('dutycheck', 'Open ArbeitszeitCheck'), 'aria-describedby': titleId },
			}));
		}
		if (employeesUrl) {
			actions.appendChild(create('a', {
				class: 'button',
				href: employeesUrl,
				text: t('dutycheck', 'Employees — link accounts'),
				attrs: { 'aria-describedby': titleId },
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
			el.hidden = true;
			el.replaceChildren();
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
		el.replaceChildren(...parts);
	}

	async function loadContext() {
		const tbody = document.getElementById('dc-absences-table-body');
		C.setLoadingRow(tbody, TABLE_COLS);
		const integ = integrationBootstrapFromDom();
		const locksLinked = integrationLocksLinked(integ);
		try {
			const rosterResponse = await Api.get('/apps/dutycheck/api/roster');
			const employees = rosterResponse?.data?.employees || [];
			lastPlannerEmployees = employees;
			renderPlannerIntegrationBanner(integ, employees);
			fillEmployeeSelect(employees, locksLinked);
			updatePlannerOnBehalfSection(employees, locksLinked);
			const absencesResponse = await Api.get('/apps/dutycheck/api/absences');
			const linkedIds = buildLinkedEmployeeIdSet(employees);
			renderAbsences(absencesResponse?.data || [], { locksLinked, linkedEmployeeIds: linkedIds });
		} catch (err) {
			renderPlannerIntegrationBanner(integ, []);
			Msg.handleApiError(err);
			C.renderTableFetchError(tbody, TABLE_COLS, t('dutycheck', 'Could not load absences. Reload the page or contact an administrator if this keeps happening.'));
		} finally {
			C.clearLoadingRow(tbody);
		}
	}

	async function transitionAbsence(id, status) {
		try {
			let reviewReason = '';
			if (status === 'rejected' || status === 'cancelled') {
				const reason = await C.promptReason({
					title: status === 'rejected' ? t('dutycheck', 'Reject absence') : t('dutycheck', 'Cancel absence'),
					label: t('dutycheck', 'Reason (minimum 10 characters)'),
					confirmLabel: status === 'rejected' ? t('dutycheck', 'Reject') : t('dutycheck', 'Cancel'),
					cancelLabel: t('dutycheck', 'Back'),
					minLength: 10,
				});
				if (reason === null) return;
				reviewReason = reason;
			}
			const response = await Api.post(`/apps/dutycheck/api/absences/${id}/transition`, { status, reviewReason });
			renderAbsences(response?.data || []);
			Msg.announce(t('dutycheck', 'Absence status updated.'));
		} catch (err) {
			const code = String(err?.payload?.error?.code || '');
			if (code === 'REASON_TOO_SHORT') {
				Msg.announce(t('dutycheck', 'Reason must contain at least 10 characters.'), 'error');
				return;
			}
			if (code === 'INVALID_ABSENCE_TRANSITION') {
				Msg.announce(t('dutycheck', 'This status transition is not allowed.'), 'error');
				return;
			}
			if (code === 'INTEGRATION_ABSENCE_READONLY') {
				Msg.announce(t('dutycheck', 'Linked accounts use ArbeitszeitCheck for absence decisions while the integration is on. DutyCheck cannot update this row.'), 'error');
				return;
			}
			Msg.handleApiError(err);
		}
	}

	document.addEventListener('DOMContentLoaded', async () => {
		D?.applyLocaleToTemporalInputs(document);
		try {
			await loadContext();
		} catch (err) {
			Msg.handleApiError(err);
			return;
		}
		const form = document.getElementById('dc-absence-form');
		form?.addEventListener('submit', async (event) => {
			event.preventDefault();
			if (form.dataset.dcBusy === '1') {
				return;
			}
			const data = new FormData(form);
			const startDate = String(data.get('startDate') || '');
			const endDate = String(data.get('endDate') || '');
			const employeeId = Number(data.get('employeeId'));
			if (!Number.isInteger(employeeId) || employeeId <= 0) {
				Msg.announce(t('dutycheck', 'Pick an employee.'), 'error');
				return;
			}
			if (!startDate || !endDate) {
				Msg.announce(t('dutycheck', 'Both dates are required.'), 'error');
				return;
			}
			if (startDate > endDate) {
				Msg.announce(t('dutycheck', 'End date must be on or after start date.'), 'error');
				return;
			}
			const submitBtn = form.querySelector('button[type="submit"]');
			form.dataset.dcBusy = '1';
			if (submitBtn) {
				submitBtn.disabled = true;
				submitBtn.setAttribute('aria-busy', 'true');
			}
			try {
				const response = await Api.post('/apps/dutycheck/api/absences', {
					employeeId,
					kind: String(data.get('kind') || 'other'),
					startDate,
					endDate,
				});
				renderAbsences(response?.data || []);
				form.reset();
				if (form.kind) form.kind.value = 'vacation';
				Msg.announce(t('dutycheck', 'Absence created.'));
			} catch (err) {
				const code = String(err?.payload?.error?.code || '');
				if (code === 'ABSENCE_OVERLAP') {
					Msg.announce(t('dutycheck', 'This employee already has an overlapping absence.'), 'error');
					return;
				}
				if (code === 'INTEGRATION_ABSENCE_READONLY') {
					Msg.announce(t('dutycheck', 'You cannot create DutyCheck absences for this employee: linked accounts use ArbeitszeitCheck while the integration is on.'), 'error');
					return;
				}
				if (code === 'EMPLOYEE_LINK_NOT_FOUND') {
					Msg.announce(t('dutycheck', 'The selected employee could not be found. Reload the list and retry.'), 'error');
					return;
				}
				Msg.handleApiError(err);
			} finally {
				delete form.dataset.dcBusy;
				if (submitBtn) {
					submitBtn.disabled = false;
					submitBtn.removeAttribute('aria-busy');
				}
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
			const employees = lastPlannerEmployees;
			const locksLinked = integrationLocksLinked(next);
			renderPlannerIntegrationBanner(next, employees);
			fillEmployeeSelect(employees, locksLinked);
			updatePlannerOnBehalfSection(employees, locksLinked);
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
