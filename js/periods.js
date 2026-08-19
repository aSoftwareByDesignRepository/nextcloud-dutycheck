(function () {
	'use strict';

	const Api = window.DutyCheckApi;
	const Msg = window.DutyCheckMessaging;
	const ConflictLabels = window.DutyCheckConflictLabels;
	const C = window.DutyCheckComponents || window.DutyCheckDom || {};
	const D = window.DutyCheckDates;
	const create = C.createElement;
	if (typeof create !== 'function') {
		throw new Error('DutyCheck components failed to load');
	}

	const STATUS_ACTIONS = {
		open: ['published'],
		published: ['closed'],
		closed: ['open'],
	};
	let currentPeriodId = null;
	let isBusy = false;
	let detailsAbort = null;
	let detailsSeq = 0;

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

	function setBusy(value) {
		isBusy = Boolean(value);
		const verifyButton = document.getElementById('dc-verify-snapshots-button');
		if (verifyButton) {
			verifyButton.disabled = isBusy || !currentPeriodId;
		}
		const form = document.getElementById('dc-period-form');
		if (form) {
			Array.from(form.elements).forEach((el) => {
				if ('disabled' in el) el.disabled = isBusy;
			});
		}
		document.querySelectorAll('#dc-periods-table-body button').forEach((btn) => {
			btn.disabled = isBusy;
		});
	}

	function actionLabel(next) {
		if (next === 'published') return t('dutycheck', 'Publish');
		if (next === 'closed') return t('dutycheck', 'Close');
		if (next === 'open') return t('dutycheck', 'Re-open');
		return t('dutycheck', 'Set to {status}').replace('{status}', String(next));
	}

	function statusBadge(status) {
		const cls = String(status || '').toLowerCase();
		const labelMap = {
			open: t('dutycheck', 'Open'),
			published: t('dutycheck', 'Published'),
			closed: t('dutycheck', 'Closed'),
		};
		const label = labelMap[cls] || String(status || '').toUpperCase();
		return create('span', { class: 'dc-status-badge dc-status-badge--' + cls, text: label });
	}

	function translateSnapshotKind(kind) {
		const k = String(kind || '').toLowerCase();
		if (k === 'publish') {
			return t('dutycheck', 'Publish snapshot');
		}
		if (k === 'close') {
			return t('dutycheck', 'Close snapshot');
		}
		return String(kind || '');
	}

	function translateAuditAction(action) {
		if (String(action || '') === 'period_transition') {
			return t('dutycheck', 'Period status change');
		}
		return String(action || '');
	}

	function translateAuditTargetKind(targetKind) {
		if (String(targetKind || '') === 'period') {
			return t('dutycheck', 'Period');
		}
		return String(targetKind || '');
	}

	function renderPeriods(periods) {
		const tbody = document.getElementById('dc-periods-table-body');
		if (!tbody) return;
		C.clearLoadingRow?.(tbody);
		const emptyCallout = document.getElementById('dc-period-empty-callout');
		if (emptyCallout) emptyCallout.hidden = periods.length > 0;
		const canReopen = String(tbody.dataset.canReopen || '0') === '1';
		tbody.replaceChildren();
		if (!periods.length) {
			const tr = create('tr');
			const td = create('td', { text: t('dutycheck', 'No periods yet. Create one to start planning.') });
			td.colSpan = 3;
			tr.appendChild(td);
			tbody.appendChild(tr);
			return;
		}
		for (const period of periods) {
			const tr = create('tr');
			const periodId = Number(period.id);
			const isSelected = Boolean(currentPeriodId && periodId === currentPeriodId);
			tr.classList.add('dc-row--selectable');
			if (isSelected) {
				tr.classList.add('is-selected');
			}
			const start = D?.formatDisplayDate(period.startDate) || period.startDate;
			const end = D?.formatDisplayDate(period.endDate) || period.endDate;
			const onSelect = async () => {
				if (isBusy || currentPeriodId === periodId) return;
				currentPeriodId = periodId;
				updateUrlPeriodId(currentPeriodId);
				renderPeriods(periods);
				await loadPeriodDetails(currentPeriodId);
			};
			// Keyboard/AT path: a real button in the first cell. A role="button"
			// on the <tr> is invalid ARIA (nested interactives, aria-selected
			// not allowed outside grids) and was flagged by axe.
			const selectBtn = create('button', {
				type: 'button',
				class: 'button button--text dc-period-select',
				text: `${start} – ${end}`,
				attrs: {
					'aria-label': t('dutycheck', 'Select period {start} to {end}').replace('{start}', start).replace('{end}', end),
					'aria-current': isSelected ? 'true' : null,
				},
				on: { click: onSelect },
			});
			// Pointer convenience only: clicking anywhere in the row selects too.
			tr.addEventListener('click', (event) => {
				if (event.target.closest('button')) return;
				onSelect();
			});

			const range = create('td', { class: 'dc-period-select-cell' });
			range.dataset.cell = t('dutycheck', 'Range');
			range.appendChild(selectBtn);
			tr.appendChild(range);

			const status = create('td');
			status.dataset.cell = t('dutycheck', 'Status');
			status.appendChild(statusBadge(period.status));
			tr.appendChild(status);

			const actions = create('td', { class: 'dc-table__col--actions' });
			actions.dataset.cell = t('dutycheck', 'Actions');
			const next = STATUS_ACTIONS[period.status] || [];
			const wrap = create('div', { class: 'dc-row-actions' });
			for (const target of next) {
				if (target === 'open' && !canReopen) {
					continue;
				}
				const btn = create('button', {
					type: 'button',
					class: 'button',
					text: actionLabel(target),
					attrs: { 'aria-label': t('dutycheck', 'Set period {start} to {status}').replace('{start}', start).replace('{status}', target) },
				});
				btn.addEventListener('click', (event) => {
					event.stopPropagation();
					transitionPeriod(period.id, target);
				});
				wrap.appendChild(btn);
			}
			actions.appendChild(wrap);
			tr.appendChild(actions);
			tbody.appendChild(tr);
		}
	}

	function renderPublishReadiness(readiness) {
		const node = document.getElementById('dc-publish-readiness');
		if (!node) return;
		node.removeAttribute('aria-busy');
		if (!readiness) {
			node.textContent = '';
			return;
		}
		const mustFix = Number(readiness.hardConflicts || 0);
		const confirm = Number(readiness.softConflicts || 0);
		const pendingOpen = Number(readiness.unacknowledgedSoftConflicts || 0);
		const canPublish = Boolean(readiness.canPublish);
		const integrationStale = Boolean(readiness.integrationPublishStale || readiness.integrationStale);
		node.textContent = ConflictLabels
			? ConflictLabels.publishReadinessLine(canPublish, mustFix, confirm, pendingOpen, integrationStale)
			: (canPublish ? t('dutycheck', 'Ready to publish') : t('dutycheck', 'Publishing blocked'));
	}

	function renderSnapshots(snapshots) {
		const tbody = document.getElementById('dc-snapshots-table-body');
		if (!tbody) return;
		C.clearLoadingRow?.(tbody);
		tbody.replaceChildren();
		if (!snapshots.length) {
			const tr = create('tr');
			const td = create('td', { text: t('dutycheck', 'No snapshots for this period yet.') });
			td.colSpan = 4;
			tr.appendChild(td);
			tbody.appendChild(tr);
			return;
		}
		for (const snap of snapshots) {
			const tr = create('tr');
			const generatedAt = D?.formatDisplayDateTime(snap.generatedAt) || snap.generatedAt || '';
			const cells = [
				{ label: t('dutycheck', 'Type'), value: translateSnapshotKind(snap.kind), badge: true },
				{ label: t('dutycheck', 'Hash'), value: snap.hash, mono: true },
				{ label: t('dutycheck', 'Generated at'), value: generatedAt },
				{ label: t('dutycheck', 'Generated by'), value: snap.generatedBy || '' },
			];
			for (const cell of cells) {
				const td = create('td');
				td.dataset.cell = cell.label;
				if (cell.badge) {
					td.appendChild(create('span', { class: 'dc-badge dc-badge--info', text: String(cell.value || '') }));
				} else if (cell.mono) {
					const fullHash = String(cell.value || '');
					const wrap = create('div', { class: 'dc-hash-cell' });
					wrap.appendChild(create('code', {
						class: 'dc-hash-cell__code',
						attrs: { title: fullHash },
						text: fullHash.length > 20 ? (fullHash.slice(0, 20) + '…') : fullHash,
					}));
					if (fullHash !== '') {
						const copyBtn = create('button', {
							type: 'button',
							class: 'button button--text dc-hash-cell__copy',
							text: t('dutycheck', 'Copy'),
							attrs: { 'aria-label': t('dutycheck', 'Copy hash to clipboard') },
						});
						copyBtn.addEventListener('click', async (event) => {
							event.stopPropagation();
							try {
								await navigator.clipboard.writeText(fullHash);
								Msg.announce(t('dutycheck', 'Hash copied to clipboard.'));
							} catch (_) {
								Msg.announce(t('dutycheck', 'Could not copy. Select the URL manually.'), 'error');
							}
						});
						wrap.appendChild(copyBtn);
					}
					td.appendChild(wrap);
				} else {
					td.textContent = String(cell.value ?? '');
				}
				tr.appendChild(td);
			}
			tbody.appendChild(tr);
		}
	}

	function renderAudit(events) {
		const tbody = document.getElementById('dc-period-audit-table-body');
		if (!tbody) return;
		C.clearLoadingRow?.(tbody);
		tbody.replaceChildren();
		if (!events.length) {
			const tr = create('tr');
			const td = create('td', { text: t('dutycheck', 'No audit events for this period yet.') });
			td.colSpan = 4;
			tr.appendChild(td);
			tbody.appendChild(tr);
			return;
		}
		for (const event of events) {
			const tr = create('tr');
			const createdAt = D?.formatDisplayDateTime(event.createdAt) || event.createdAt;
			const targetKindLabel = translateAuditTargetKind(event.targetKind);
			const target = event.targetId
				? `${targetKindLabel} #${event.targetId}`
				: targetKindLabel;
			const cells = [
				{ label: t('dutycheck', 'Time'), value: createdAt },
				{ label: t('dutycheck', 'Actor'), value: event.actorUserId || '' },
				{ label: t('dutycheck', 'Action'), value: translateAuditAction(event.action || '') },
				{ label: t('dutycheck', 'Target'), value: target },
			];
			for (const cell of cells) {
				const td = create('td', { text: String(cell.value ?? '') });
				td.dataset.cell = cell.label;
				tr.appendChild(td);
			}
			tbody.appendChild(tr);
		}
	}

	function setIntegrityBanner(message) {
		const banner = document.getElementById('dc-snapshot-integrity-banner');
		if (!banner) return;
		if (!message) {
			banner.hidden = true;
			banner.textContent = '';
			return;
		}
		banner.hidden = false;
		banner.textContent = message;
	}

	async function verifySnapshots(periodId) {
		const resultNode = document.getElementById('dc-snapshot-verify-result');
		if (!periodId) {
			if (resultNode) resultNode.textContent = t('dutycheck', 'Select a period first.');
			setIntegrityBanner('');
			return;
		}
		try {
			const response = await Api.get(`/apps/dutycheck/api/periods/${periodId}/snapshots/verify`);
			const count = Number(response?.data?.count || 0);
			if (resultNode) {
				resultNode.textContent = t('dutycheck', 'Integrity verified for {n} snapshot(s).').replace('{n}', String(count));
			}
			setIntegrityBanner('');
			Msg.announce(t('dutycheck', 'Snapshot integrity verified.'));
		} catch (err) {
			const code = String(err?.payload?.error?.code || '');
			if (resultNode) {
				resultNode.textContent = t('dutycheck', 'Integrity verification failed.');
			}
			setIntegrityBanner(code === 'SNAPSHOT_HASH_MISMATCH'
				? t('dutycheck', 'Security alert: snapshot integrity mismatch detected. Do not trust this period until resolved.')
				: t('dutycheck', 'Integrity verification could not be completed. Retry, and review server logs if this persists.'));
			Msg.announce(t('dutycheck', 'Snapshot integrity verification failed.'), 'error');
		}
	}

	async function loadPeriodDetails(periodId) {
		if (detailsAbort && typeof detailsAbort.abort === 'function') {
			try {
				detailsAbort.abort();
			} catch (_) {
				/* ignore */
			}
		}
		const seq = ++detailsSeq;
		const controller = typeof AbortController === 'function' ? new AbortController() : null;
		detailsAbort = controller;
		const signal = controller ? controller.signal : undefined;
		const getOpts = signal ? { signal } : {};

		if (!periodId) {
			renderSnapshots([]);
			renderPublishReadiness(null);
			renderAudit([]);
			renderAcknowledgeStats(null);
			setIntegrityBanner('');
			const verifyButton = document.getElementById('dc-verify-snapshots-button');
			if (verifyButton) verifyButton.disabled = true;
			return;
		}

		C.setLoadingRow?.(document.getElementById('dc-snapshots-table-body'), 4);
		C.setLoadingRow?.(document.getElementById('dc-period-audit-table-body'), 4);
		setPublishReadinessLoading();
		setAcknowledgeStatsLoading();

		const tableErr = t('dutycheck', 'Could not load this section. Retry or contact an administrator if it continues.');
		const [snapR, pubR, audR, ackR] = await Promise.allSettled([
			Api.get(`/apps/dutycheck/api/periods/${periodId}/snapshots`, null, getOpts).then((r) => {
				if (seq !== detailsSeq) return;
				C.clearLoadingRow?.(document.getElementById('dc-snapshots-table-body'));
				renderSnapshots(r?.data || []);
			}),
			Api.get(`/apps/dutycheck/api/periods/${periodId}/publish-readiness`, null, getOpts).then((r) => {
				if (seq !== detailsSeq) return;
				renderPublishReadiness(r?.data || null);
			}),
			Api.get(`/apps/dutycheck/api/periods/${periodId}/audit`, null, getOpts).then((r) => {
				if (seq !== detailsSeq) return;
				C.clearLoadingRow?.(document.getElementById('dc-period-audit-table-body'));
				renderAudit(r?.data || []);
			}),
			Api.get(`/apps/dutycheck/api/periods/${periodId}/acknowledge-stats`, null, getOpts).then((r) => {
				if (seq !== detailsSeq) return;
				renderAcknowledgeStats(r?.data || null);
			}),
		]);

		if (seq !== detailsSeq) {
			return;
		}

		if (snapR.status === 'rejected') {
			if (!(Api.isAborted && Api.isAborted(snapR.reason))) {
				C.renderTableFetchError(document.getElementById('dc-snapshots-table-body'), 4, tableErr);
			}
		}
		if (audR.status === 'rejected') {
			if (!(Api.isAborted && Api.isAborted(audR.reason))) {
				C.renderTableFetchError(document.getElementById('dc-period-audit-table-body'), 4, tableErr);
			}
		}
		if (pubR.status === 'rejected' && !(Api.isAborted && Api.isAborted(pubR.reason))) {
			const pill = document.getElementById('dc-publish-readiness');
			if (pill) {
				pill.textContent = t('dutycheck', 'Could not load publish readiness.');
				pill.removeAttribute('aria-busy');
			}
		}
		if (ackR.status === 'rejected' && !(Api.isAborted && Api.isAborted(ackR.reason))) {
			renderAcknowledgeStats(null);
		}

		const failed = [snapR, pubR, audR].filter((r) => {
			if (r.status !== 'rejected') return false;
			return !(Api.isAborted && Api.isAborted(r.reason));
		});
		if (failed.length) {
			setIntegrityBanner(t('dutycheck', 'Some period details failed to load. Retry, or review server logs if this persists.'));
			Msg.handleApiError(failed[0].reason);
		} else {
			setIntegrityBanner('');
		}

		const verifyButton = document.getElementById('dc-verify-snapshots-button');
		if (verifyButton) verifyButton.disabled = !periodId || isBusy;
	}

	function setPublishReadinessLoading() {
		const node = document.getElementById('dc-publish-readiness');
		if (!node) return;
		node.textContent = t('dutycheck', 'Loading…');
		node.setAttribute('aria-busy', 'true');
	}

	function setAcknowledgeStatsLoading() {
		const el = document.getElementById('dc-period-ack-stats');
		if (!el) return;
		el.hidden = false;
		el.textContent = t('dutycheck', 'Loading…');
		el.setAttribute('aria-busy', 'true');
	}

	function renderAcknowledgeStats(data) {
		const el = document.getElementById('dc-period-ack-stats');
		if (!el) return;
		el.removeAttribute('aria-busy');
		if (!data || Number(data.total || 0) <= 0) {
			el.hidden = true;
			el.textContent = '';
			return;
		}
		el.hidden = false;
		el.textContent = t('dutycheck', 'Staff seen: {acked}/{total} ({pct}%)')
			.replace('{acked}', String(data.acknowledged ?? 0))
			.replace('{total}', String(data.total ?? 0))
			.replace('{pct}', String(data.percent ?? 0));
	}

	async function loadPeriods(preferredPeriodId) {
		const tableErr = t('dutycheck', 'Could not load this section. Retry or contact an administrator if it continues.');
		const periodsBody = document.getElementById('dc-periods-table-body');
		try {
			C.setLoadingRow?.(periodsBody, 3);
			const response = await Api.get('/apps/dutycheck/api/periods');
			const periods = response?.data?.periods || [];
			const preferred = preferredPeriodId || selectedPeriodIdFromUrl();
			const selected = periods.find((p) => Number(p.id) === Number(preferred)) || periods[0] || null;
			currentPeriodId = selected ? Number(selected.id) : null;
			C.clearLoadingRow?.(periodsBody);
			renderPeriods(periods);
			if (currentPeriodId) {
				updateUrlPeriodId(currentPeriodId);
				// Do not await: the list must paint even if details are slow.
				void loadPeriodDetails(currentPeriodId);
			} else {
				renderSnapshots([]);
				renderPublishReadiness(null);
				renderAudit([]);
				renderAcknowledgeStats(null);
				setIntegrityBanner('');
				const verifyButton = document.getElementById('dc-verify-snapshots-button');
				if (verifyButton) verifyButton.disabled = true;
			}
		} catch (err) {
			if (Api.isAborted && Api.isAborted(err)) {
				return;
			}
			C.clearLoadingRow?.(periodsBody);
			C.renderTableFetchError(periodsBody, 3, tableErr);
			Msg.handleApiError(err);
		}
	}

	function transitionErrorMessage(code) {
		switch (code) {
			case 'PERIOD_HAS_HARD_CONFLICTS':
				return t('dutycheck', 'Publishing is blocked until every “Must fix” issue is resolved.');
			case 'INTEGRATION_PUBLISH_STALE':
				return t('dutycheck', 'Publishing blocked: ArbeitszeitCheck absences are stale or the sync breaker is open. Sync in Settings, then try again.');
			case 'PERIOD_STATUS_CONFLICT':
				return t('dutycheck', 'Someone else changed this period’s status. Reload the page and try again.');
			case 'REASON_TOO_SHORT':
				return t('dutycheck', 'Reason must contain at least 10 characters.');
			case 'INVALID_PERIOD_TRANSITION':
				return t('dutycheck', 'This status transition is not allowed.');
			case 'NOT_APPLICABLE_FOR_ROLE':
				return t('dutycheck', 'You do not have permission for this action.');
			default:
				return t('dutycheck', 'Failed to update period status.');
		}
	}

	function createPeriodErrorMessage(code) {
		switch (code) {
			case 'INVALID_PERIOD_RANGE':
				return t('dutycheck', 'End date must be on or after start date.');
			case 'DUPLICATE_PERIOD_RANGE':
				return t('dutycheck', 'A period with the same date range already exists.');
			case 'INVALID_DATE':
				return t('dutycheck', 'Please provide valid dates.');
			default:
				return t('dutycheck', 'Failed to create period.');
		}
	}

	async function transitionPeriod(id, status) {
		if (isBusy) return;
		try {
			setBusy(true);
			let reason = '';
			if (status === 'open' || status === 'closed') {
				const titleMap = {
					open: t('dutycheck', 'Re-open period'),
					closed: t('dutycheck', 'Close period'),
				};
				const labelMap = {
					open: t('dutycheck', 'Reason for re-opening (minimum 10 characters)'),
					closed: t('dutycheck', 'Reason for closing (minimum 10 characters)'),
				};
				reason = await C.promptReason({
					title: titleMap[status] || t('dutycheck', 'Provide a reason'),
					label: labelMap[status] || t('dutycheck', 'Reason (minimum 10 characters)'),
					confirmLabel: status === 'open' ? t('dutycheck', 'Re-open') : t('dutycheck', 'Close'),
					cancelLabel: t('dutycheck', 'Cancel'),
					minLength: 10,
				});
				if (reason === null) {
					setBusy(false);
					return;
				}
			} else if (status === 'published') {
				const ok = await C.confirmDialog({
					title: t('dutycheck', 'Publish period?'),
					body: t('dutycheck', 'A snapshot will be created and the period will be visible to employees. Confirmed “Confirm to continue” exceptions remain in the audit trail.'),
					confirmLabel: t('dutycheck', 'Publish'),
					cancelLabel: t('dutycheck', 'Cancel'),
				});
				if (!ok) {
					setBusy(false);
					return;
				}
			}
			const endpoint = status === 'published'
				? `/apps/dutycheck/api/periods/${id}/publish`
				: (status === 'closed' ? `/apps/dutycheck/api/periods/${id}/close` : `/apps/dutycheck/api/periods/${id}/reopen`);
			await Api.post(endpoint, { reason });
			await loadPeriods(Number(id));
			Msg.announce(t('dutycheck', 'Period status updated.'));
		} catch (err) {
			const code = String(err?.payload?.error?.code || '');
			Msg.announce(transitionErrorMessage(code), 'error');
		} finally {
			setBusy(false);
		}
	}

	document.addEventListener('DOMContentLoaded', async () => {
		D?.applyLocaleToTemporalInputs(document);
		await loadPeriods();
		document.getElementById('dc-verify-snapshots-button')?.addEventListener('click', async () => {
			if (isBusy) return;
			setBusy(true);
			try {
				await verifySnapshots(currentPeriodId);
			} finally {
				setBusy(false);
			}
		});
		const form = document.getElementById('dc-period-form');
		form?.addEventListener('submit', async (event) => {
			event.preventDefault();
			if (isBusy) return;
			const data = new FormData(form);
			const startDate = String(data.get('startDate') || '');
			const endDate = String(data.get('endDate') || '');
			if (!startDate || !endDate) {
				Msg.announce(t('dutycheck', 'Please choose both dates.'), 'error');
				return;
			}
			if (startDate > endDate) {
				Msg.announce(t('dutycheck', 'End date must be on or after start date.'), 'error');
				return;
			}
			try {
				setBusy(true);
				await Api.post('/apps/dutycheck/api/periods', { startDate, endDate });
				form.reset();
				await loadPeriods();
				Msg.announce(t('dutycheck', 'Period created.'));
			} catch (err) {
				const code = String(err?.payload?.error?.code || '');
				Msg.announce(createPeriodErrorMessage(code), 'error');
			} finally {
				setBusy(false);
			}
		});
	});
})();
