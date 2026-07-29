(function () {
	'use strict';

	const Api = window.DutyCheckApi;
	const Msg = window.DutyCheckMessaging;
	const C = window.DutyCheckComponents || window.DutyCheckDom || {};
	const create = C.createElement;
	if (typeof create !== 'function') {
		throw new Error('DutyCheck components failed to load');
	}

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
			badge.textContent = t('dutycheck', 'No directory restriction');
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
			// Must be an <li>: the container is a <ul> and stray children break
			// the accessible list semantics (axe "list" rule, WCAG 1.3.1).
			container.appendChild(create('li', {}, [
				create('span', { class: 'dc-pill', text: t('dutycheck', 'None selected') }),
			]));
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
			if (!opts.silentPick) {
				const label = item.displayName === item.id ? item.id : `${item.displayName} (${item.id})`;
				Msg.announce(t('dutycheck', 'Added {name} to the selection.').replace('{name}', label));
			}
			if (!opts.retainInputOnPick) {
				input.value = '';
				activeQuery = '';
				renderResults(resultsId, [], onPick, { status: 'short' });
				input.focus();
			}
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
		const { intent, hint, peerLink, meta, banner, purgeBtn, syncBtn, retryBtn, blockPublish, includePii } = els;
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
		const disableWrap = document.getElementById('dc-at-disable-reason-wrap');
		if (disableWrap) {
			// Show reason field when connection is currently on (admin may turn it off).
			disableWrap.hidden = !Boolean(d.intentEnabled);
		}
		if (blockPublish) {
			blockPublish.checked = Boolean(d.blockPublishWhenStale);
			blockPublish.disabled = false;
		}
		if (includePii) {
			includePii.checked = Boolean(d.includePii);
		}
		const minV = (d.peerVersionRange && d.peerVersionRange.min) ? String(d.peerVersionRange.min) : '1.2.0';
		if (hint) {
			hint.textContent = t('dutycheck', 'ArbeitszeitCheck must be installed, enabled, and at least version {version}.').replace('{version}', minV);
		}
		const peerOk = Boolean(d.peerInstalled && d.peerEnabled && d.peerVersionOk);
		const breaker = Boolean(d.integrationBreakerTripped);
		if (syncBtn) {
			syncBtn.disabled = !peerOk || breaker || Boolean(d.integrationReconcileInProgress);
			if (breaker) {
				syncBtn.title = t('dutycheck', 'Circuit breaker open — sync is paused. Try again later or check server logs.');
			} else if (!peerOk && hint) {
				syncBtn.title = hint.textContent || '';
			} else if (d.integrationReconcileInProgress) {
				syncBtn.title = t('dutycheck', 'Sync is already running.');
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
			peerLink.setAttribute('aria-label', t('dutycheck', 'Open ArbeitszeitCheck'));
		} else if (peerLink) {
			peerLink.removeAttribute('href');
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
		if (d.intentEnabled && !d.effective) {
			if (!d.peerInstalled || !d.peerEnabled || !d.peerVersionOk) {
				metaText += ' ' + t('dutycheck', 'Waiting for ArbeitszeitCheck — the app is missing, disabled, or incompatible. Absence import is paused.');
			} else if (d.integrationReconcileInProgress) {
				metaText += ' ' + t('dutycheck', 'Sync is already running.');
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
		const blockPublish = document.getElementById('dc-at-block-publish-stale');
		const includePii = document.getElementById('dc-at-include-pii');
		const piiJustification = document.getElementById('dc-at-pii-justification');
		const els = { banner, intent, hint, syncBtn, peerLink, meta, purgeBtn, retryBtn, blockPublish, includePii };

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
			const reasonEl = document.getElementById('dc-at-disable-reason');
			const reason = !next ? String(reasonEl?.value || '').trim() : '';
			try {
				const payload = { enabled: next };
				if (!next && reason !== '') {
					payload.reason = reason;
				}
				const res = await Api.post('/apps/dutycheck/api/admin/integration/intent', payload);
				Msg.announce(t('dutycheck', 'Integration settings updated.'));
				if (reasonEl) reasonEl.value = '';
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
				if (code === 'INTEGRATION_DETECTION_FLAPPING') {
					Msg.announce(t('dutycheck', 'We could not check ArbeitszeitCheck just now. Wait a few seconds and try again.'), 'error');
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

		async function savePolicyFlags(partial) {
			try {
				const res = await Api.post('/apps/dutycheck/api/admin/integration/settings', partial);
				Msg.announce(t('dutycheck', 'Integration settings updated.'));
				renderAtAdminState(res?.data || {}, els);
			} catch (err) {
				const code = String(err?.payload?.error?.code || '');
				if (code === 'INTEGRATION_PII_JUSTIFICATION_REQUIRED') {
					Msg.announce(t('dutycheck', 'A written justification is required to include sensitive notes.'), 'error');
				} else {
					Msg.handleApiError(err);
				}
				await loadAtIntegrationState();
			}
		}

		blockPublish?.addEventListener('change', async () => {
			await savePolicyFlags({ blockPublishWhenStale: Boolean(blockPublish.checked) });
		});

		includePii?.addEventListener('change', async () => {
			const next = Boolean(includePii.checked);
			const justification = String(piiJustification?.value || '').trim();
			if (next && justification.length < 3) {
				includePii.checked = false;
				Msg.announce(t('dutycheck', 'A written justification is required to include sensitive notes.'), 'error');
				piiJustification?.focus();
				return;
			}
			await savePolicyFlags({
				includePii: next,
				piiJustification: justification,
			});
		});

		let syncCooldownTimer = null;
		function clearSyncCooldown() {
			if (syncCooldownTimer) {
				window.clearTimeout(syncCooldownTimer);
				syncCooldownTimer = null;
			}
		}
		function armSyncCooldown(seconds) {
			clearSyncCooldown();
			const n = Math.max(1, Number(seconds) || 60);
			if (syncBtn) {
				syncBtn.disabled = true;
				syncBtn.title = t('dutycheck', 'Try again in {n} seconds.').replace('{n}', String(n));
			}
			syncCooldownTimer = window.setTimeout(() => {
				syncCooldownTimer = null;
				loadAtIntegrationState();
			}, n * 1000);
		}

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
				const retryAfter = Number(err?.payload?.error?.retryAfter || 0);
				if (code === 'INTEGRATION_SYNC_RATE_LIMIT' || code === 'INTEGRATION_SYNC_THROTTLED') {
					const n = retryAfter > 0 ? retryAfter : 60;
					Msg.announce(t('dutycheck', 'Try again in {n} seconds.').replace('{n}', String(n)), 'error');
					armSyncCooldown(n);
					needReload = false;
				} else if (code === 'INTEGRATION_SYNC_ALREADY_RUNNING') {
					Msg.announce(t('dutycheck', 'Sync is already running.'), 'error');
				} else if (code === 'INTEGRATION_SYNC_BREAKER_TRIPPED' || code === 'INTEGRATION_SYNC_FAILED') {
					Msg.announce(t('dutycheck', 'Sync failed — see logs.'), 'error');
					if (retryAfter > 0) {
						armSyncCooldown(retryAfter);
						needReload = false;
					}
				} else {
					Msg.handleApiError(err);
				}
			} finally {
				if (needReload) {
					await loadAtIntegrationState();
				}
			}
		});

		window.addEventListener('pagehide', clearSyncCooldown);
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

	async function wireDutyRoles() {
		const assignBtn = document.getElementById('dc-duty-role-assign');
		const tbody = document.getElementById('dc-duty-roles-tbody');
		if (!assignBtn || !tbody) {
			return;
		}
		let selectedUser = null;

		function roleLabel(role) {
			if (role === 'planner') {
				return t('dutycheck', 'Planner');
			}
			if (role === 'employee') {
				return t('dutycheck', 'Employee');
			}
			return String(role || '—');
		}

		function formatAssignedAt(value) {
			const raw = String(value || '').trim();
			if (raw === '') {
				return '—';
			}
			const normalized = raw.includes('T') ? raw : raw.replace(' ', 'T') + 'Z';
			const date = new Date(normalized);
			if (Number.isNaN(date.getTime())) {
				return raw;
			}
			try {
				return date.toLocaleString();
			} catch (_err) {
				return raw;
			}
		}

		function setSelectedUser(user) {
			selectedUser = user;
			assignBtn.disabled = !user;
		}

		function renderDutyRoleTable(assignments) {
			tbody.replaceChildren();
			const rows = Array.isArray(assignments) ? assignments : [];
			if (!rows.length) {
				const tr = create('tr', { class: 'dc-table__empty-row' });
				const td = create('td', {
					attrs: { colspan: '4' },
					class: 'dc-table__empty-cell',
					text: t('dutycheck', 'No planner roles assigned yet.'),
				});
				tr.appendChild(td);
				tbody.appendChild(tr);
				return;
			}
			for (const row of rows) {
				const userId = String(row.userId || '');
				const displayName = String(row.displayName || userId);
				const label = displayName === userId ? userId : `${displayName} (${userId})`;
				const tr = create('tr');
				tr.appendChild(create('td', { text: label }));
				tr.appendChild(create('td', { text: roleLabel(row.role) }));
				tr.appendChild(create('td', { text: formatAssignedAt(row.createdAt) }));
				const actionsTd = create('td', { class: 'dc-table__col--actions' });
				const removeBtn = create('button', {
					type: 'button',
					class: 'button',
					text: t('dutycheck', 'Remove planner role'),
					on: {
						click: async () => {
							removeBtn.disabled = true;
							try {
								const response = await Api.del(`/apps/dutycheck/api/admin/duty-roles/${encodeURIComponent(userId)}`);
								renderDutyRoleTable(response?.assignments || []);
								Msg.announce(t('dutycheck', 'Planner role removed.'));
							} catch (err) {
								Msg.handleApiError(err);
							} finally {
								removeBtn.disabled = false;
							}
						},
					},
				});
				actionsTd.appendChild(removeBtn);
				tr.appendChild(actionsTd);
				tbody.appendChild(tr);
			}
		}

		async function loadDutyRoles() {
			const response = await Api.get('/apps/dutycheck/api/admin/duty-roles');
			renderDutyRoleTable(response?.assignments || []);
		}

		wireSearch('dc-duty-role-user-search', 'dc-duty-role-user-results', fetchUsers, (item) => {
			setSelectedUser(item);
		}, { silentPick: true, retainInputOnPick: true });

		assignBtn.addEventListener('click', async () => {
			if (!selectedUser) {
				return;
			}
			assignBtn.disabled = true;
			assignBtn.setAttribute('aria-busy', 'true');
			try {
				const response = await Api.post('/apps/dutycheck/api/admin/duty-roles', {
					userId: selectedUser.id,
					role: 'planner',
				});
				renderDutyRoleTable(response?.assignments || []);
				const searchInput = document.getElementById('dc-duty-role-user-search');
				if (searchInput) {
					searchInput.value = '';
				}
				renderResults('dc-duty-role-user-results', [], () => {}, { status: 'short' });
				setSelectedUser(null);
				Msg.announce(t('dutycheck', 'Planner role assigned.'));
			} catch (err) {
				const code = String(err?.payload?.error?.code || '');
				if (code === 'INVALID_USER') {
					Msg.announce(t('dutycheck', 'That user no longer exists. Search again and pick a valid account.'), 'error');
				} else if (code === 'INVALID_DUTY_ROLE') {
					Msg.announce(t('dutycheck', 'Invalid duty role.'), 'error');
				} else {
					Msg.handleApiError(err);
				}
			} finally {
				assignBtn.removeAttribute('aria-busy');
				assignBtn.disabled = !selectedUser;
			}
		});

		try {
			await loadDutyRoles();
		} catch (err) {
			Msg.handleApiError(err);
		}
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


	async function wireConflictPolicy() {
		const form = document.getElementById('dc-conflict-policy-form');
		if (!form) return;
		const status = document.getElementById('dc-conflict-policy-status');
		try {
			const res = await Api.get('/apps/dutycheck/api/admin/conflict-policy');
			const d = res?.data || {};
			form.minRestMinutes.value = String(d.minRestMinutes ?? 660);
			form.maxDailyHard.value = String(d.maxDailyHard ?? 600);
			form.maxPeriodSoft.value = String(d.maxPeriodSoft ?? 2880);
			form.maxPeriodHard.value = String(d.maxPeriodHard ?? 3600);
			form.maxConsecutiveDays.value = String(d.maxConsecutiveDays ?? 6);
		} catch (err) {
			Msg.handleApiError(err);
		}
		form.addEventListener('submit', async (event) => {
			event.preventDefault();
			try {
				await Api.post('/apps/dutycheck/api/admin/conflict-policy', {
					minRestMinutes: Number(form.minRestMinutes.value),
					maxDailyHard: Number(form.maxDailyHard.value),
					maxPeriodSoft: Number(form.maxPeriodSoft.value),
					maxPeriodHard: Number(form.maxPeriodHard.value),
					maxConsecutiveDays: Number(form.maxConsecutiveDays.value),
				});
				const msg = t('dutycheck', 'Conflict thresholds saved.');
				if (status) {
					status.hidden = false;
					status.textContent = msg;
				}
				Msg.announce(msg, 'success');
			} catch (err) {
				Msg.handleApiError(err);
			}
		});
	}

	async function wireShiftTemplates() {
		const form = document.getElementById('dc-template-form');
		const list = document.getElementById('dc-template-list');
		if (!form || !list) return;
		const status = document.getElementById('dc-template-status');
		const locSelect = document.getElementById('dc-template-location');

		async function refreshLocations() {
			if (!locSelect) return;
			const keep = locSelect.value;
			locSelect.replaceChildren();
			const globalOpt = document.createElement('option');
			globalOpt.value = '';
			globalOpt.textContent = t('dutycheck', 'Global (all locations)');
			locSelect.appendChild(globalOpt);
			try {
				const res = await Api.get('/apps/dutycheck/api/locations');
				const rows = Array.isArray(res?.data) ? res.data : [];
				for (const row of rows) {
					const opt = document.createElement('option');
					opt.value = String(row.id);
					opt.textContent = row.name || `#${row.id}`;
					locSelect.appendChild(opt);
				}
				if (keep && [...locSelect.options].some((o) => o.value === keep)) {
					locSelect.value = keep;
				}
			} catch (err) {
				Msg.handleApiError(err);
			}
		}

		async function refresh() {
			list.replaceChildren();
			try {
				const res = await Api.get('/apps/dutycheck/api/templates');
				const rows = Array.isArray(res?.data) ? res.data : [];
				for (const row of rows) {
					const li = create('li', { class: 'dc-chip' });
					const locLabel = row.locationId
						? (locSelect?.querySelector(`option[value="${CSS.escape(String(row.locationId))}"]`)?.textContent || `#${row.locationId}`)
						: t('dutycheck', 'Global (all locations)');
					li.appendChild(create('span', {
						text: `${row.name} (${String(row.startTime || '').slice(0, 5)}–${String(row.endTime || '').slice(0, 5)}) · ${locLabel}`
							+ (Number(row.minHeadcount || 0) > 0
								? ` · ${t('dutycheck', 'min {n}').replace('{n}', String(row.minHeadcount))}`
								: ''),
					}));
					const del = create('button', {
						type: 'button',
						class: 'button button--text',
						text: t('dutycheck', 'Delete'),
					});
					del.addEventListener('click', async () => {
						try {
							await Api.del(`/apps/dutycheck/api/templates/${row.id}`);
							await refresh();
							Msg.announce(t('dutycheck', 'Template deleted.'), 'success');
						} catch (err) {
							Msg.handleApiError(err);
						}
					});
					li.appendChild(del);
					list.appendChild(li);
				}
			} catch (err) {
				Msg.handleApiError(err);
			}
		}

		await refreshLocations();
		await refresh();
		form.addEventListener('submit', async (event) => {
			event.preventDefault();
			try {
				await Api.post('/apps/dutycheck/api/templates', {
					name: String(form.name.value || '').trim(),
					startTime: String(form.startTime.value || ''),
					endTime: String(form.endTime.value || ''),
					breakMinutes: Number(form.breakMinutes.value || 0),
					minHeadcount: Number(form.minHeadcount?.value || 0),
					locationId: form.locationId?.value ? Number(form.locationId.value) : null,
				});
				form.reset();
				if (form.breakMinutes) form.breakMinutes.value = '0';
				if (form.minHeadcount) form.minHeadcount.value = '0';
				if (locSelect) locSelect.value = '';
				const msg = t('dutycheck', 'Template saved.');
				if (status) {
					status.hidden = false;
					status.textContent = msg;
				}
				Msg.announce(msg, 'success');
				await refresh();
			} catch (err) {
				Msg.handleApiError(err);
			}
		});
	}

	async function wireCompanies() {
		const form = document.getElementById('dc-company-form');
		const list = document.getElementById('dc-company-list');
		const memberForm = document.getElementById('dc-company-member-form');
		const memberList = document.getElementById('dc-company-member-list');
		const companySelect = document.getElementById('dc-company-member-company');
		const hint = document.getElementById('dc-companies-legacy-hint');
		const status = document.getElementById('dc-company-status');
		if (!form || !list) return;

		async function refreshMembers(companyId) {
			if (!memberList || !companyId) return;
			memberList.replaceChildren();
			try {
				const res = await Api.get(`/apps/dutycheck/api/admin/companies/${companyId}/members`);
				const rows = Array.isArray(res?.data) ? res.data : [];
				for (const row of rows) {
					const li = create('li', { class: 'dc-chip' });
					li.appendChild(create('span', {
						class: 'dc-chip__text',
						text: `${row.userId} (${row.role})`,
					}));
					const remove = create('button', {
						type: 'button',
						class: 'dc-chip__remove',
						'aria-label': t('dutycheck', 'Remove member'),
					});
					remove.textContent = '×';
					remove.addEventListener('click', async () => {
						try {
							await Api.del(`/apps/dutycheck/api/admin/companies/${companyId}/members/${encodeURIComponent(row.userId)}`);
							await refreshMembers(companyId);
							Msg.announce(t('dutycheck', 'Member removed.'), 'success');
						} catch (err) {
							Msg.handleApiError(err);
						}
					});
					li.appendChild(remove);
					memberList.appendChild(li);
				}
			} catch (err) {
				Msg.handleApiError(err);
			}
		}

		async function refresh() {
			list.replaceChildren();
			if (companySelect) companySelect.replaceChildren();
			try {
				const res = await Api.get('/apps/dutycheck/api/admin/companies');
				const companies = Array.isArray(res?.data?.companies) ? res.data.companies : [];
				const multi = Boolean(res?.data?.multiCompanyActive);
				if (hint) {
					hint.hidden = multi;
				}
				for (const row of companies) {
					const li = create('li', { class: 'dc-chip' });
					li.appendChild(create('span', {
						class: 'dc-chip__text',
						text: `#${row.id} ${row.name}${row.active ? '' : ` (${t('dutycheck', 'inactive')})`}`,
					}));
					list.appendChild(li);
					if (companySelect) {
						const opt = document.createElement('option');
						opt.value = String(row.id);
						opt.textContent = `${row.name} (#${row.id})`;
						companySelect.appendChild(opt);
					}
				}
				const selected = companySelect ? Number(companySelect.value || 0) : 0;
				await refreshMembers(selected);
			} catch (err) {
				Msg.handleApiError(err);
			}
		}

		await refresh();
		if (companySelect) {
			companySelect.addEventListener('change', async () => {
				await refreshMembers(Number(companySelect.value || 0));
			});
		}
		form.addEventListener('submit', async (event) => {
			event.preventDefault();
			try {
				await Api.post('/apps/dutycheck/api/admin/companies', {
					name: String(form.name.value || '').trim(),
				});
				form.reset();
				const msg = t('dutycheck', 'Company created. Membership isolation is now active.');
				if (status) {
					status.hidden = false;
					status.textContent = msg;
				}
				Msg.announce(msg, 'success');
				await refresh();
			} catch (err) {
				Msg.handleApiError(err);
			}
		});
		if (memberForm) {
			memberForm.addEventListener('submit', async (event) => {
				event.preventDefault();
				const companyId = Number(memberForm.companyId.value || 0);
				try {
					await Api.post(`/apps/dutycheck/api/admin/companies/${companyId}/members`, {
						userId: String(memberForm.userId.value || '').trim(),
						role: String(memberForm.role.value || 'member'),
					});
					memberForm.userId.value = '';
					Msg.announce(t('dutycheck', 'Member added.'), 'success');
					await refreshMembers(companyId);
				} catch (err) {
					Msg.handleApiError(err);
				}
			});
		}
	}

	async function wireQualifications() {
		const form = document.getElementById('dc-qual-form');
		const list = document.getElementById('dc-qual-list');
		const attach = document.getElementById('dc-qual-attach-form');
		const locForm = document.getElementById('dc-qual-loc-form');
		if (!form || !list) return;

		const attachEmp = document.getElementById('dc-qual-attach-emp');
		const attachQual = document.getElementById('dc-qual-attach-id');
		const detachEmp = document.getElementById('dc-qual-detach-emp');
		const detachQual = document.getElementById('dc-qual-detach-id');
		const locSelect = document.getElementById('dc-qual-loc-id');
		const locQual = document.getElementById('dc-qual-loc-qid');

		function fillSelect(el, rows, labelFn, placeholder) {
			if (!el) return;
			const keep = el.value;
			el.replaceChildren();
			const opt0 = document.createElement('option');
			opt0.value = '';
			opt0.textContent = placeholder;
			el.appendChild(opt0);
			for (const row of rows) {
				const opt = document.createElement('option');
				opt.value = String(row.id);
				opt.textContent = labelFn(row);
				el.appendChild(opt);
			}
			if (keep && [...el.options].some((o) => o.value === keep)) {
				el.value = keep;
			}
		}

		async function refreshCatalog() {
			list.replaceChildren();
			try {
				const res = await Api.get('/apps/dutycheck/api/qualifications');
				const rows = Array.isArray(res?.data) ? res.data : [];
				for (const row of rows) {
					const li = create('li', { class: 'dc-chip' });
					li.appendChild(create('span', {
						text: `${row.name}${row.code ? ` (${row.code})` : ''}`,
					}));
					const deactivate = create('button', {
						type: 'button',
						class: 'button',
						text: t('dutycheck', 'Deactivate'),
					});
					deactivate.style.minHeight = '44px';
					deactivate.style.marginLeft = '0.5rem';
					deactivate.addEventListener('click', async () => {
						try {
							await Api.post(`/apps/dutycheck/api/qualifications/${row.id}/deactivate`, {});
							Msg.announce(t('dutycheck', 'Qualification deactivated.'), 'success');
							await refreshCatalog();
						} catch (err) {
							Msg.handleApiError(err);
						}
					});
					li.appendChild(deactivate);
					list.appendChild(li);
				}
				const qualLabel = (row) => `${row.name}${row.code ? ` (${row.code})` : ''}`;
				const chooseQual = t('dutycheck', 'Choose a qualification…');
				fillSelect(attachQual, rows, qualLabel, chooseQual);
				fillSelect(detachQual, rows, qualLabel, chooseQual);
				fillSelect(locQual, rows, qualLabel, chooseQual);
			} catch (err) {
				Msg.handleApiError(err);
			}
		}

		async function refreshPeopleAndPlaces() {
			try {
				const [empRes, locRes] = await Promise.all([
					Api.get('/apps/dutycheck/api/employees'),
					Api.get('/apps/dutycheck/api/locations'),
				]);
				const employees = Array.isArray(empRes?.data) ? empRes.data : [];
				const locations = Array.isArray(locRes?.data) ? locRes.data : [];
				fillSelect(
					attachEmp,
					employees,
					(row) => row.displayName || row.name || row.linkedUserId || `#${row.id}`,
					t('dutycheck', 'Choose an employee…'),
				);
				fillSelect(
					detachEmp,
					employees,
					(row) => row.displayName || row.name || row.linkedUserId || `#${row.id}`,
					t('dutycheck', 'Choose an employee…'),
				);
				fillSelect(
					locSelect,
					locations,
					(row) => row.name || `#${row.id}`,
					t('dutycheck', 'Choose a location…'),
				);
			} catch (err) {
				Msg.handleApiError(err);
			}
		}

		await Promise.all([refreshCatalog(), refreshPeopleAndPlaces()]);
		form.addEventListener('submit', async (event) => {
			event.preventDefault();
			try {
				await Api.post('/apps/dutycheck/api/qualifications', {
					name: String(form.name.value || '').trim(),
					code: String(form.code.value || '').trim() || null,
				});
				form.reset();
				Msg.announce(t('dutycheck', 'Qualification added.'), 'success');
				await refreshCatalog();
			} catch (err) {
				Msg.handleApiError(err);
			}
		});
		attach?.addEventListener('submit', async (event) => {
			event.preventDefault();
			try {
				const empId = Number(attach.employeeId.value);
				const qualificationId = Number(attach.qualificationId.value);
				if (!empId || !qualificationId) {
					Msg.announce(t('dutycheck', 'Choose an employee and a qualification.'), 'error');
					return;
				}
				await Api.post(`/apps/dutycheck/api/employees/${empId}/qualifications`, {
					qualificationId,
					expiresOn: attach.expiresOn.value || null,
				});
				Msg.announce(t('dutycheck', 'Qualification attached to employee.'), 'success');
				attach.reset();
				await refreshCatalog();
			} catch (err) {
				Msg.handleApiError(err);
			}
		});
		const detach = document.getElementById('dc-qual-detach-form');
		detach?.addEventListener('submit', async (event) => {
			event.preventDefault();
			try {
				const empId = Number(detach.employeeId.value);
				const qualificationId = Number(detach.qualificationId.value);
				if (!empId || !qualificationId) {
					Msg.announce(t('dutycheck', 'Choose an employee and a qualification.'), 'error');
					return;
				}
				await Api.post(`/apps/dutycheck/api/employees/${empId}/qualifications/${qualificationId}/detach`, {});
				Msg.announce(t('dutycheck', 'Qualification removed from employee.'), 'success');
				detach.reset();
			} catch (err) {
				Msg.handleApiError(err);
			}
		});
		locForm?.addEventListener('submit', async (event) => {
			event.preventDefault();
			try {
				const locId = Number(locForm.locationId.value);
				const qualificationId = Number(locForm.qualificationId.value);
				if (!locId || !qualificationId) {
					Msg.announce(t('dutycheck', 'Choose a location and a qualification.'), 'error');
					return;
				}
				await Api.post(`/apps/dutycheck/api/locations/${locId}/qualifications`, {
					qualificationId,
				});
				Msg.announce(t('dutycheck', 'Qualification required at location.'), 'success');
				locForm.reset();
				await refreshCatalog();
			} catch (err) {
				Msg.handleApiError(err);
			}
		});
	}

	async function wirePlannerScope() {
		const form = document.getElementById('dc-planner-scope-form');
		if (!form) return;
		const status = document.getElementById('dc-scope-status');
		const loadBtn = document.getElementById('dc-scope-load');
		const locsHost = document.getElementById('dc-scope-locs');

		async function showStatus(msg) {
			if (status) {
				status.hidden = false;
				status.textContent = msg;
			}
			Msg.announce(msg, 'success');
		}

		function selectedLocationIds() {
			if (!locsHost) return [];
			return [...locsHost.querySelectorAll('input[type="checkbox"][name="locationIds"]:checked')]
				.map((el) => Number(el.value))
				.filter((n) => Number.isFinite(n) && n > 0);
		}

		function setCheckedIds(ids) {
			if (!locsHost) return;
			const set = new Set((ids || []).map((n) => Number(n)));
			locsHost.querySelectorAll('input[type="checkbox"][name="locationIds"]').forEach((el) => {
				el.checked = set.has(Number(el.value));
			});
		}

		async function refreshLocationChecks() {
			if (!locsHost) return;
			locsHost.replaceChildren();
			try {
				const res = await Api.get('/apps/dutycheck/api/locations');
				const rows = Array.isArray(res?.data) ? res.data : [];
				if (rows.length === 0) {
					locsHost.appendChild(create('p', {
						class: 'dc-hint',
						text: t('dutycheck', 'No locations yet. Add locations first.'),
					}));
					return;
				}
				for (const row of rows) {
					const id = String(row.id);
					const wrap = create('label', { class: 'dc-check' });
					const input = document.createElement('input');
					input.type = 'checkbox';
					input.name = 'locationIds';
					input.value = id;
					input.id = `dc-scope-loc-${id}`;
					wrap.appendChild(input);
					wrap.appendChild(document.createTextNode(` ${row.name || `#${id}`}`));
					locsHost.appendChild(wrap);
				}
			} catch (err) {
				Msg.handleApiError(err);
			}
		}

		await refreshLocationChecks();

		loadBtn?.addEventListener('click', async () => {
			const userId = String(form.userId.value || '').trim();
			if (!userId) return;
			try {
				const res = await Api.get(`/apps/dutycheck/api/admin/planner-scope/${encodeURIComponent(userId)}`);
				const ids = Array.isArray(res?.data?.locationIds) ? res.data.locationIds : [];
				setCheckedIds(ids);
				await showStatus(ids.length
					? t('dutycheck', 'Loaded {n} location(s).').replace('{n}', String(ids.length))
					: t('dutycheck', 'Unrestricted (all locations).'));
			} catch (err) {
				Msg.handleApiError(err);
			}
		});

		form.addEventListener('submit', async (event) => {
			event.preventDefault();
			const userId = String(form.userId.value || '').trim();
			const locationIds = selectedLocationIds();
			try {
				await Api.post(`/apps/dutycheck/api/admin/planner-scope/${encodeURIComponent(userId)}`, { locationIds });
				await showStatus(locationIds.length
					? t('dutycheck', 'Planner scope saved.')
					: t('dutycheck', 'Planner scope saved (unrestricted).'));
			} catch (err) {
				Msg.handleApiError(err);
			}
		});
	}

	async function wireOpsFlags() {
		const form = document.getElementById('dc-ops-flags-form');
		if (!form) return;
		const status = document.getElementById('dc-ops-flags-status');
		const pruneBtn = document.getElementById('dc-ops-prune-snapshots');
		try {
			const res = await Api.get('/apps/dutycheck/api/admin/ops-flags');
			const d = res?.data || {};
			form.thresholdApproachNotify.checked = Boolean(d.thresholdApproachNotify);
			form.mcOnDutyHookEnabled.checked = Boolean(d.mcOnDutyHookEnabled);
			if (form.hrRosterMinutesExportEnabled) {
				form.hrRosterMinutesExportEnabled.checked = Boolean(d.hrRosterMinutesExportEnabled);
			}
			form.snapshotRetentionDays.value = String(d.snapshotRetentionDays ?? 0);
		} catch (err) {
			Msg.handleApiError(err);
		}
		form.addEventListener('submit', async (event) => {
			event.preventDefault();
			try {
				await Api.post('/apps/dutycheck/api/admin/ops-flags', {
					thresholdApproachNotify: !!form.thresholdApproachNotify.checked,
					mcOnDutyHookEnabled: !!form.mcOnDutyHookEnabled.checked,
					hrRosterMinutesExportEnabled: !!(form.hrRosterMinutesExportEnabled && form.hrRosterMinutesExportEnabled.checked),
					snapshotRetentionDays: Number(form.snapshotRetentionDays.value || 0),
				});
				const msg = t('dutycheck', 'Notification and retention settings saved.');
				if (status) {
					status.hidden = false;
					status.textContent = msg;
				}
				Msg.announce(msg, 'success');
			} catch (err) {
				Msg.handleApiError(err);
			}
		});
		pruneBtn?.addEventListener('click', async () => {
			try {
				const res = await Api.post('/apps/dutycheck/api/admin/snapshots/prune', {});
				const deleted = Number(res?.data?.deleted ?? 0);
				const msg = t('dutycheck', 'Pruned {n} expired snapshot(s).').replace('{n}', String(deleted));
				if (status) {
					status.hidden = false;
					status.textContent = msg;
				}
				Msg.announce(msg, 'success');
			} catch (err) {
				Msg.handleApiError(err);
			}
		});
	}

	document.addEventListener('DOMContentLoaded', async () => {
		await wireAtIntegration();
		await wirePlanningDefaults();
		await wireCompanies();
		await wireConflictPolicy();
		await wireShiftTemplates();
		await wireQualifications();
		await wirePlannerScope();
		await wireOpsFlags();
		await wireDutyRoles();
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
