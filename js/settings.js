(function () {
	'use strict';

	const Api = window.DutyCheckApi;
	const Msg = window.DutyCheckMessaging;
	const C = window.DutyCheckComponents;
	const create = C.createElement;

	const state = {
		allowedUsers: [],
		allowedGroups: [],
		appAdmins: [],
		restrictionEnabled: false,
	};
	let baseline = null;
	let dirty = false;

	function deepCopy(value) {
		return JSON.parse(JSON.stringify(value));
	}

	function dedupeById(items) {
		const map = new Map();
		for (const item of items || []) {
			if (!item || !item.id) continue;
			map.set(String(item.id), { id: String(item.id), displayName: String(item.displayName || item.id) });
		}
		return Array.from(map.values());
	}

	function removeById(items, id) {
		return (items || []).filter((item) => String(item.id) !== String(id));
	}

	function setDirty(value) {
		dirty = Boolean(value);
		const indicator = document.getElementById('dc-policy-dirty');
		const discard = document.getElementById('dc-policy-discard');
		if (indicator) indicator.hidden = !dirty;
		if (discard) discard.disabled = !dirty;
	}

	function renderPolicyStateBadge() {
		const badge = document.getElementById('dc-policy-state-badge');
		if (!badge) return;
		badge.classList.remove('dc-status-badge--published', 'dc-status-badge--open', 'dc-status-badge--rejected', 'dc-status-badge--closed');
		if (state.restrictionEnabled) {
			badge.classList.add('dc-status-badge--rejected');
			badge.textContent = t('dutycheck', 'Restricted');
		} else {
			badge.classList.add('dc-status-badge--published');
			badge.textContent = t('dutycheck', 'Open to all members');
		}
	}

	function snapshotMatchesBaseline() {
		if (!baseline) return false;
		const current = {
			allowedUsers: state.allowedUsers.map((u) => u.id).sort(),
			allowedGroups: state.allowedGroups.map((g) => g.id).sort(),
			appAdmins: state.appAdmins.map((a) => a.id).sort(),
			restrictionEnabled: state.restrictionEnabled,
		};
		const base = {
			allowedUsers: baseline.allowedUsers.map((u) => u.id).sort(),
			allowedGroups: baseline.allowedGroups.map((g) => g.id).sort(),
			appAdmins: baseline.appAdmins.map((a) => a.id).sort(),
			restrictionEnabled: baseline.restrictionEnabled,
		};
		return JSON.stringify(current) === JSON.stringify(base);
	}

	function recomputeDirty() {
		setDirty(!snapshotMatchesBaseline());
	}

	function renderChips(containerId, items, onRemove) {
		const container = document.getElementById(containerId);
		if (!container) return;
		container.replaceChildren();
		if (!items.length) {
			container.appendChild(create('span', { class: 'dc-pill', text: t('dutycheck', 'None selected') }));
			return;
		}
		for (const item of items) {
			const text = item.displayName === item.id ? item.id : `${item.displayName} (${item.id})`;
			const chip = create('li', { class: 'dc-chip' }, [
				create('span', { class: 'dc-chip__text', text }),
				create('button', {
					type: 'button',
					class: 'dc-chip__remove',
					attrs: { 'aria-label': t('dutycheck', 'Remove {name}').replace('{name}', text) },
					text: '\u00d7',
					on: { click: () => onRemove(item.id) },
				}),
			]);
			container.appendChild(chip);
		}
	}

	function renderResults(containerId, items, onPick, options) {
		const opts = options || {};
		const container = document.getElementById(containerId);
		if (!container) return;
		container.replaceChildren();
		if (opts.status === 'short') {
			container.appendChild(create('li', {
				class: 'dc-entity-results__empty',
				attrs: { 'aria-disabled': 'true' },
				text: t('dutycheck', 'Type at least 2 characters to search.'),
			}));
			return;
		}
		if (opts.status === 'searching') {
			container.appendChild(create('li', {
				class: 'dc-entity-results__empty',
				attrs: { 'aria-disabled': 'true', 'aria-busy': 'true' },
				text: t('dutycheck', 'Searching…'),
			}));
			return;
		}
		if (!items.length) {
			container.appendChild(create('li', {
				class: 'dc-entity-results__empty',
				attrs: { 'aria-disabled': 'true' },
				text: t('dutycheck', 'No matches found. Keep typing to refine your search.'),
			}));
			return;
		}
		items.forEach((item, index) => {
			const text = item.displayName === item.id ? item.id : `${item.displayName} (${item.id})`;
			const li = create('li', {
				attrs: {
					role: 'option',
					tabindex: index === 0 ? '0' : '-1',
					'aria-selected': index === 0 ? 'true' : 'false',
					'data-index': String(index),
				},
			}, [
				create('span', { class: 'dc-entity-results__title', text }),
			]);
			const pick = () => onPick(item);
			li.addEventListener('click', pick);
			li.addEventListener('keydown', (event) => {
				if (event.key === 'Enter' || event.key === ' ') {
					event.preventDefault();
					pick();
					return;
				}
				if (event.key !== 'ArrowDown' && event.key !== 'ArrowUp') {
					return;
				}
				event.preventDefault();
				const optionsEls = Array.from(container.querySelectorAll('[role="option"]'));
				const currentIdx = optionsEls.indexOf(li);
				const nextIdx = event.key === 'ArrowDown'
					? Math.min(currentIdx + 1, optionsEls.length - 1)
					: Math.max(currentIdx - 1, 0);
				optionsEls.forEach((el, i) => {
					el.tabIndex = i === nextIdx ? 0 : -1;
					el.setAttribute('aria-selected', i === nextIdx ? 'true' : 'false');
				});
				optionsEls[nextIdx]?.focus();
			});
			container.appendChild(li);
		});
	}

	async function fetchUsers(query) {
		const response = await Api.get('/apps/dutycheck/api/admin/users', { q: query });
		return (response?.users || [])
			.filter((u) => u && u.enabled !== false)
			.map((u) => ({ id: String(u.id), displayName: String(u.displayName || u.id) }));
	}

	async function fetchGroups(query) {
		const response = await Api.get('/apps/dutycheck/api/admin/groups', { q: query });
		return (response?.groups || [])
			.map((g) => ({ id: String(g.id), displayName: String(g.displayName || g.id) }));
	}

	function wireSearch(inputId, resultsId, fetcher, onPick, options) {
		const opts = options || {};
		const input = document.getElementById(inputId);
		if (!input) return;
		let timer = null;
		let activeQuery = '';
		let lastResults = [];

		const pickAndReset = (item) => {
			onPick(item);
			const label = item.displayName === item.id ? item.id : `${item.displayName} (${item.id})`;
			Msg.announce(t('dutycheck', 'Added {name} to the selection.').replace('{name}', label));
			input.value = '';
			activeQuery = '';
			renderResults(resultsId, [], onPick, { status: 'short' });
			input.focus();
		};

		const runSearch = async (q) => {
			renderResults(resultsId, [], onPick, { status: 'searching' });
			const items = await fetcher(q);
			lastResults = items;
			renderResults(resultsId, items, pickAndReset);
			return items;
		};

		const pickBestMatch = (q) => {
			const query = String(q || '').trim().toLowerCase();
			if (query.length < 1) return false;
			const exact = lastResults.find((item) => {
				const id = String(item?.id || '').toLowerCase();
				const display = String(item?.displayName || '').toLowerCase();
				return id === query || display === query;
			});
			if (exact) {
				pickAndReset(exact);
				return true;
			}
			if (lastResults.length === 1) {
				pickAndReset(lastResults[0]);
				return true;
			}
			if (opts.allowDirectEntry) {
				const rawId = String(q || '').trim();
				const normalized = typeof opts.normalizeDirectEntry === 'function'
					? opts.normalizeDirectEntry(rawId)
					: rawId;
				if (normalized) {
					pickAndReset({ id: normalized, displayName: normalized });
					return true;
				}
			}
			return false;
		};

		input.addEventListener('keydown', (event) => {
			if (event.key !== 'Enter' && event.key !== 'Tab' && event.key !== ',') return;
			const container = document.getElementById(resultsId);
			const first = container?.querySelector('[role="option"]:not([aria-disabled="true"])');
			const q = input.value.trim();
			if (q.length < 1) return;
			if (event.key === 'Enter' || event.key === ',') {
				event.preventDefault();
			}
			if (event.key === 'Tab' && !first && pickBestMatch(q)) {
				event.preventDefault();
				return;
			}
			if (first) {
				first.click();
				return;
			}
			// If Enter is pressed before the debounce finished, perform an immediate
			// lookup so admins still get deterministic keyboard selection feedback.
			if (q.length < 2) {
				pickBestMatch(q);
				return;
			}
			(async () => {
				try {
					const items = await runSearch(q);
					if (items.length > 0) {
						pickAndReset(items[0]);
						return;
					}
					// Deterministic keyboard workflow: when search returns no results,
					// still allow exact/manual identifiers on Enter (same as Tab/blur).
					pickBestMatch(q);
				} catch (err) {
					Msg.handleApiError(err);
				}
			})();
		});
		input.addEventListener('blur', () => {
			const q = input.value.trim();
			if (q.length < 1) return;
			pickBestMatch(q);
		});
		input.addEventListener('input', () => {
			if (timer) window.clearTimeout(timer);
			const q = input.value.trim();
			activeQuery = q;
			if (q.length < 2) {
				lastResults = [];
				renderResults(resultsId, [], onPick, { status: 'short' });
				return;
			}
			renderResults(resultsId, [], onPick, { status: 'searching' });
			timer = window.setTimeout(async () => {
				if (input.value.trim() !== q) return;
				try {
					await runSearch(q);
				} catch (err) {
					Msg.handleApiError(err);
				}
			}, 240);
		});

		return async function finalizePendingInput() {
			const q = input.value.trim();
			if (q.length < 1) {
				return false;
			}
			// Resolve stale debounce state before save so manually typed values are
			// deterministically committed (exact ID/display name or single match).
			if (q.length >= 2) {
				try {
					await runSearch(q);
				} catch (err) {
					Msg.handleApiError(err);
					return false;
				}
			}
			return pickBestMatch(q);
		};
	}

	function renderAll() {
		renderChips('dc-policy-user-chips', state.allowedUsers, (id) => {
			state.allowedUsers = removeById(state.allowedUsers, id);
			renderAll();
			recomputeDirty();
		});
		renderChips('dc-policy-group-chips', state.allowedGroups, (id) => {
			state.allowedGroups = removeById(state.allowedGroups, id);
			renderAll();
			recomputeDirty();
		});
		renderChips('dc-policy-admin-chips', state.appAdmins, (id) => {
			state.appAdmins = removeById(state.appAdmins, id);
			renderAll();
			recomputeDirty();
		});
		renderPolicyStateBadge();
	}

	async function loadPolicy(form) {
		const response = await Api.get('/apps/dutycheck/api/admin/policy');
		const policy = response?.policy || {};
		state.allowedUsers = dedupeById((policy.allowedUserIds || []).map((id) => ({ id, displayName: id })));
		state.allowedGroups = dedupeById((policy.allowedGroupIds || []).map((id) => ({ id, displayName: id })));
		state.appAdmins = dedupeById((policy.appAdminUserIds || []).map((id) => ({ id, displayName: id })));
		state.restrictionEnabled = Boolean(policy.accessRestrictionEnabled);
		form.accessRestrictionEnabled.checked = state.restrictionEnabled;
		baseline = deepCopy(state);
		renderAll();
		setDirty(false);
	}

	function applyBaseline(form) {
		if (!baseline) return;
		state.allowedUsers = deepCopy(baseline.allowedUsers);
		state.allowedGroups = deepCopy(baseline.allowedGroups);
		state.appAdmins = deepCopy(baseline.appAdmins);
		state.restrictionEnabled = Boolean(baseline.restrictionEnabled);
		if (form?.accessRestrictionEnabled) form.accessRestrictionEnabled.checked = state.restrictionEnabled;
		renderAll();
		setDirty(false);
	}

	function renderAtAdminState(d, els) {
		const { intent, hint, peerLink, meta, banner, purgeBtn, syncBtn, retryBtn } = els;
		if (retryBtn) {
			retryBtn.hidden = true;
		}
		if (banner) {
			banner.setAttribute('role', 'status');
			banner.setAttribute('aria-live', 'polite');
		}
		if (intent) {
			intent.checked = Boolean(d.intentEnabled);
			const prereq = Boolean(d.peerInstalled && d.peerEnabled && d.peerVersionOk);
			intent.disabled = !prereq;
		}
		const minV = (d.peerVersionRange && d.peerVersionRange.min) ? String(d.peerVersionRange.min) : '1.0.0';
		if (hint) {
			hint.textContent = t('dutycheck', 'ArbeitszeitCheck must be installed, enabled, and at least version {version}.').replace('{version}', minV);
		}
		const peerOk = Boolean(d.peerInstalled && d.peerEnabled && d.peerVersionOk);
		if (syncBtn) {
			syncBtn.disabled = !peerOk;
			if (!peerOk && hint) {
				syncBtn.title = hint.textContent || '';
			} else {
				syncBtn.removeAttribute('title');
			}
		}
		let bannerMsg = '';
		if (!d.peerInstalled) {
			bannerMsg = t('dutycheck', 'Peer app is not installed.');
		} else if (!d.peerEnabled) {
			bannerMsg = t('dutycheck', 'Peer app disabled for this instance.');
		} else if (!d.peerVersionOk) {
			bannerMsg = t('dutycheck', 'Peer app version is too old.');
		}
		const legacy = Number(d.legacyDcAbsencesOnLinkedEmployees || 0);
		if (legacy > 0 && d.intentEnabled) {
			bannerMsg = t('dutycheck', 'Legacy DutyCheck absences exist on linked employees. Resolve them before enabling integration ({count} rows).').replace('{count}', String(legacy));
		} else if (legacy > 0 && !d.intentEnabled) {
			if (peerOk && !bannerMsg) {
				bannerMsg = t('dutycheck', 'DutyCheck still has {count} legacy absence record(s) for linked employees. Remove them below before enabling integration.').replace('{count}', String(legacy));
			}
		}
		if (banner) {
			banner.classList.remove('dc-callout--critical');
			banner.classList.add('dc-callout--warning');
			banner.textContent = bannerMsg;
			banner.hidden = !bannerMsg;
		}
		if (peerLink && d.peerPlannerOutboundUrl) {
			peerLink.href = String(d.peerPlannerOutboundUrl);
			peerLink.hidden = false;
		} else if (peerLink) {
			peerLink.hidden = true;
		}
		const last = d.integrationLastReconcileAt ? String(d.integrationLastReconcileAt) : t('dutycheck', 'Never synced');
		let metaText = t('dutycheck', 'Last sync: {time}').replace('{time}', last);
		if (d.integrationBreakerTripped) {
			metaText += ' ' + t('dutycheck', 'Circuit breaker open — sync is paused. Try again later or check server logs.');
		}
		if (d.integrationStale) {
			metaText += ' ' + t('dutycheck', 'Stale mirror — run sync or wait for the scheduled job.');
		}
		const totalEmp = Number(d.activeEmployeesTotal ?? 0);
		const unlinkedEmp = Number(d.activeEmployeesUnlinked ?? 0);
		if (totalEmp > 0 && d.integrationLocksLinkedDutyCheckAbsences) {
			if (unlinkedEmp > 0) {
				metaText += ' ' + t('dutycheck', '{unlinked} of {total} active employees have no linked Nextcloud account — absences for them stay in DutyCheck until accounts are linked on the Employees page.')
					.replace('{unlinked}', String(unlinkedEmp))
					.replace('{total}', String(totalEmp));
			} else {
				metaText += ' ' + t('dutycheck', 'All {total} active employees are linked — absences for them are entered in ArbeitszeitCheck.').replace('{total}', String(totalEmp));
			}
		}
		if (meta) {
			meta.textContent = metaText;
		}
		if (intent) {
			if (intent.disabled && hint && hint.textContent) {
				intent.title = hint.textContent;
			} else {
				intent.removeAttribute('title');
			}
		}
		if (purgeBtn) {
			const show = legacy > 0 && !d.intentEnabled;
			purgeBtn.hidden = !show;
			purgeBtn.disabled = !show;
			purgeBtn.dataset.legacyCount = String(legacy);
		}
	}

	async function wireAtIntegration() {
		const root = document.getElementById('dc-at-integration');
		if (!root) return;
		const banner = document.getElementById('dc-at-integration-banner');
		const intent = document.getElementById('dc-at-intent-enabled');
		const hint = document.getElementById('dc-at-intent-hint');
		const syncBtn = document.getElementById('dc-at-sync-btn');
		const peerLink = document.getElementById('dc-at-open-peer');
		const meta = document.getElementById('dc-at-meta');
		const purgeBtn = document.getElementById('dc-at-purge-legacy-btn');
		const retryBtn = document.getElementById('dc-at-retry-load-btn');
		const els = { banner, intent, hint, syncBtn, peerLink, meta, purgeBtn, retryBtn };

		async function loadAtIntegrationState() {
			if (retryBtn) {
				retryBtn.hidden = true;
			}
			try {
				const res = await Api.get('/apps/dutycheck/api/admin/integration');
				renderAtAdminState(res?.data || {}, els);
				return true;
			} catch (err) {
				const code = String(err?.payload?.error?.code || err?.code || '');
				if (code === 'INSUFFICIENT_ROLE' || err?.status === 403) {
					root.hidden = true;
					return false;
				}
				if (banner) {
					banner.textContent = t('dutycheck', 'Could not load this section. Retry or contact an administrator if it continues.');
					banner.hidden = false;
					banner.classList.remove('dc-callout--warning');
					banner.classList.add('dc-callout--critical');
					banner.setAttribute('role', 'alert');
					banner.setAttribute('aria-live', 'assertive');
				}
				if (meta) {
					meta.textContent = '';
				}
				if (retryBtn) {
					retryBtn.hidden = false;
				}
				Msg.handleApiError(err);
				return false;
			}
		}

		retryBtn?.addEventListener('click', () => {
			loadAtIntegrationState();
		});

		intent?.addEventListener('change', async () => {
			const next = Boolean(intent?.checked);
			try {
				const res = await Api.post('/apps/dutycheck/api/admin/integration/intent', { enabled: next });
				Msg.announce(t('dutycheck', 'Integration settings updated.'));
				renderAtAdminState(res?.data || {}, els);
			} catch (err) {
				if (intent) intent.checked = !next;
				const code = String(err?.payload?.error?.code || '');
				if (code === 'INTEGRATION_LEGACY_CONFLICT') {
					const c = err?.payload?.error?.legacyAbsenceCount;
					const countStr = c !== undefined && c !== null ? String(c) : '?';
					Msg.announce(t('dutycheck', 'Legacy DutyCheck absences exist on linked employees. Resolve them before enabling integration ({count} rows).').replace('{count}', countStr), 'error');
					await loadAtIntegrationState();
					return;
				}
				if (code === 'INTEGRATION_PEER_NOT_INSTALLED') {
					Msg.announce(t('dutycheck', 'Peer app is not installed.'), 'error');
					await loadAtIntegrationState();
					return;
				}
				if (code === 'INTEGRATION_PEER_DISABLED') {
					Msg.announce(t('dutycheck', 'Peer app disabled for this instance.'), 'error');
					await loadAtIntegrationState();
					return;
				}
				if (code === 'INTEGRATION_PEER_VERSION') {
					Msg.announce(t('dutycheck', 'Peer app version is too old.'), 'error');
					await loadAtIntegrationState();
					return;
				}
				Msg.handleApiError(err);
				await loadAtIntegrationState();
			}
		});

		syncBtn?.addEventListener('click', async () => {
			if (!syncBtn || syncBtn.disabled) return;
			syncBtn.disabled = true;
			let needReload = false;
			try {
				const res = await Api.post('/apps/dutycheck/api/admin/integration/sync', {});
				Msg.announce(t('dutycheck', 'Sync completed.'));
				renderAtAdminState(res?.data?.integration || {}, els);
			} catch (err) {
				needReload = true;
				const code = String(err?.payload?.error?.code || '');
				if (code === 'INTEGRATION_SYNC_THROTTLED') {
					Msg.announce(t('dutycheck', 'Manual sync was rate-limited. Wait a moment and try again.'), 'error');
				} else if (code === 'INTEGRATION_SYNC_ALREADY_RUNNING') {
					Msg.announce(t('dutycheck', 'Sync is already running.'), 'error');
				} else if (code === 'INTEGRATION_SYNC_BREAKER_TRIPPED' || code === 'INTEGRATION_SYNC_FAILED') {
					Msg.announce(t('dutycheck', 'Sync failed — see logs.'), 'error');
				} else {
					Msg.handleApiError(err);
				}
			} finally {
				if (needReload) {
					await loadAtIntegrationState();
				}
			}
		});

		purgeBtn?.addEventListener('click', async () => {
			if (!purgeBtn) return;
			const legacy = Number(purgeBtn.dataset.legacyCount || '0');
			const ok = await C.confirmDialog({
				title: t('dutycheck', 'Remove legacy DutyCheck absences'),
				body: t('dutycheck', 'Permanently delete all DutyCheck absence records for employees linked to accounts ({count} row(s)). After integration is enabled, absences are managed in ArbeitszeitCheck. This cannot be undone.').replace('{count}', String(legacy)),
				confirmLabel: t('dutycheck', 'Remove permanently'),
				danger: true,
			});
			if (!ok) return;
			purgeBtn.disabled = true;
			try {
				const res = await Api.post('/apps/dutycheck/api/admin/integration/purge-legacy-absences', {});
				const n = Number(res?.data?.deleted ?? legacy);
				Msg.announce(t('dutycheck', 'Removed {count} legacy absence record(s).').replace('{count}', String(n)));
				renderAtAdminState(res?.data?.integration || {}, els);
			} catch (err) {
				const code = String(err?.payload?.error?.code || '');
				if (code === 'INTEGRATION_PURGE_BLOCKED') {
					Msg.announce(t('dutycheck', 'Cannot remove legacy absences while integration is enabled. Disable integration first.'), 'error');
					await loadAtIntegrationState();
					return;
				}
				if (code === 'INTEGRATION_PURGE_THROTTLED') {
					Msg.announce(t('dutycheck', 'This action was rate-limited. Wait a moment and try again.'), 'error');
					await loadAtIntegrationState();
					return;
				}
				Msg.handleApiError(err);
				await loadAtIntegrationState();
			} finally {
				if (purgeBtn) {
					purgeBtn.disabled = purgeBtn.hidden;
				}
			}
		});

		await loadAtIntegrationState();
	}

	async function wirePlanningDefaults() {
		const form = document.getElementById('dc-planning-defaults-form');
		const input = document.getElementById('dc-planning-default-break');
		if (!form || !input) {
			return;
		}
		try {
			const response = await Api.get('/apps/dutycheck/api/admin/planning-defaults');
			const minutes = Number(response?.planning?.defaultBreakMinutes);
			input.value = String(Number.isFinite(minutes) ? Math.max(0, Math.min(720, minutes)) : 0);
		} catch (err) {
			Msg.handleApiError(err);
			return;
		}
		form.addEventListener('submit', async (event) => {
			event.preventDefault();
			const statusEl = document.getElementById('dc-planning-defaults-status');
			if (statusEl) {
				statusEl.hidden = true;
				statusEl.textContent = '';
			}
			const raw = Number(input.value);
			if (!Number.isFinite(raw) || raw < 0 || raw > 720) {
				const msg = t('dutycheck', 'Enter break minutes between 0 and 720.');
				Msg.announce(msg, 'error');
				input.focus();
				return;
			}
			const saveBtn = document.getElementById('dc-planning-defaults-save');
			if (saveBtn) {
				saveBtn.disabled = true;
				saveBtn.setAttribute('aria-busy', 'true');
			}
			try {
				const response = await Api.post('/apps/dutycheck/api/admin/planning-defaults', {
					defaultBreakMinutes: Math.round(raw),
				});
				const minutes = Number(response?.planning?.defaultBreakMinutes);
				if (!response?.ok || !Number.isFinite(minutes)) {
					const failMsg = t('dutycheck', 'Could not save planning defaults. Try again or contact an administrator.');
					Msg.announce(failMsg, 'error');
					if (statusEl) {
						statusEl.textContent = failMsg;
						statusEl.hidden = false;
					}
					return;
				}
				const saved = Math.max(0, Math.min(720, minutes));
				input.value = String(saved);
				window.dispatchEvent(new CustomEvent('dc-planning-defaults-changed', {
					detail: { defaultBreakMinutes: saved },
				}));
				const msg = t('dutycheck', 'Planning defaults saved.');
				if (statusEl) {
					statusEl.textContent = msg;
					statusEl.hidden = false;
				}
				Msg.announce(msg, 'success');
			} catch (err) {
				Msg.handleApiError(err);
			} finally {
				if (saveBtn) {
					saveBtn.disabled = false;
					saveBtn.removeAttribute('aria-busy');
				}
			}
		});
	}

	document.addEventListener('DOMContentLoaded', async () => {
		await wireAtIntegration();
		await wirePlanningDefaults();
		const form = document.getElementById('dc-app-policy-form');
		if (!form) return;
		try {
			await loadPolicy(form);
		} catch (err) {
			Msg.handleApiError(err);
			return;
		}
		const finalizeAllowedUserInput = wireSearch('dc-policy-user-search', 'dc-policy-user-results', fetchUsers, (item) => {
			state.allowedUsers = dedupeById([...state.allowedUsers, item]);
			renderAll();
			recomputeDirty();
		}, { allowDirectEntry: true });
		const finalizeAllowedGroupInput = wireSearch('dc-policy-group-search', 'dc-policy-group-results', fetchGroups, (item) => {
			state.allowedGroups = dedupeById([...state.allowedGroups, item]);
			renderAll();
			recomputeDirty();
		}, { allowDirectEntry: true });
		const finalizeAdminInput = wireSearch('dc-policy-admin-search', 'dc-policy-admin-results', fetchUsers, (item) => {
			state.appAdmins = dedupeById([...state.appAdmins, item]);
			renderAll();
			recomputeDirty();
		}, { allowDirectEntry: true });
		form.accessRestrictionEnabled.addEventListener('change', () => {
			state.restrictionEnabled = Boolean(form.accessRestrictionEnabled.checked);
			renderPolicyStateBadge();
			recomputeDirty();
		});
		document.getElementById('dc-policy-discard')?.addEventListener('click', () => applyBaseline(form));

		window.addEventListener('beforeunload', (event) => {
			if (dirty) {
				event.preventDefault();
				event.returnValue = '';
			}
		});

		form.addEventListener('submit', async (event) => {
			event.preventDefault();
			await Promise.all([
				finalizeAllowedUserInput?.(),
				finalizeAllowedGroupInput?.(),
				finalizeAdminInput?.(),
			]);
			if (state.restrictionEnabled && state.allowedUsers.length === 0 && state.allowedGroups.length === 0) {
				// The server enforces this too (ACCESS_LIST_REQUIRED). We refuse client-side
				// to give an immediate, descriptive error and to focus the search input.
				Msg.announce(t('dutycheck', 'Add at least one allowed user or group when restriction is enabled.'), 'error');
				document.getElementById('dc-policy-user-search')?.focus();
				return;
			}
			const saveBtn = document.getElementById('dc-policy-save');
			if (saveBtn) {
				saveBtn.disabled = true;
				saveBtn.setAttribute('aria-busy', 'true');
			}
			try {
				const response = await Api.post('/apps/dutycheck/api/admin/policy', {
					accessRestrictionEnabled: state.restrictionEnabled,
					allowedUserIds: state.allowedUsers.map((item) => item.id),
					allowedGroupIds: state.allowedGroups.map((item) => item.id),
					appAdminUserIds: state.appAdmins.map((item) => item.id),
				});
				const policy = response?.policy || {};
				state.allowedUsers = dedupeById((policy.allowedUserIds || []).map((id) => ({ id, displayName: id })));
				state.allowedGroups = dedupeById((policy.allowedGroupIds || []).map((id) => ({ id, displayName: id })));
				state.appAdmins = dedupeById((policy.appAdminUserIds || []).map((id) => ({ id, displayName: id })));
				state.restrictionEnabled = Boolean(policy.accessRestrictionEnabled);
				baseline = deepCopy(state);
				renderAll();
				setDirty(false);
				Msg.announce(t('dutycheck', 'App policy saved.'));
			} catch (err) {
				const code = String(err?.payload?.error?.code || '');
				switch (code) {
					case 'ACCESS_LIST_REQUIRED':
						Msg.announce(t('dutycheck', 'Add at least one allowed user or group when restriction is enabled.'), 'error');
						break;
					case 'INVALID_APP_ADMIN_USER':
						Msg.announce(t('dutycheck', 'One of the app administrators no longer exists. Remove or replace it.'), 'error');
						break;
					case 'INVALID_ALLOWED_USER':
						Msg.announce(t('dutycheck', 'One of the allowed users no longer exists. Remove or replace it.'), 'error');
						break;
					case 'INVALID_ALLOWED_GROUP':
						Msg.announce(t('dutycheck', 'One of the allowed groups no longer exists. Remove or replace it.'), 'error');
						break;
					default:
						Msg.handleApiError(err);
				}
			} finally {
				if (saveBtn) {
					saveBtn.disabled = false;
					saveBtn.removeAttribute('aria-busy');
				}
			}
		});
	});
})();
