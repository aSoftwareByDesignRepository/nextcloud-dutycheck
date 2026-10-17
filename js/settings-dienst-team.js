(function () {
	'use strict';

	const Api = window.DutyCheckApi;
	const Msg = window.DutyCheckMessaging;
	const C = window.DutyCheckComponents || {};

	const BOOL_FIELDS = [
		'rotationPatternsEnabled',
		'sollFromDuty',
		'todayBoardEnabled',
		'peerRosterVisibility',
		'allowCrossLocationSwaps',
		'claimRequiresPlanner',
		'preferencesEnabled',
		'blackoutsEnabled',
		'pushQuietHoursEnabled',
		'pushAllowUrgentDuringQuiet',
		'standbyEnabled',
		'standbyAcknowledgeEnabled',
	];

	function root() {
		return document.getElementById('dc-settings-dienst-team');
	}

	function form() {
		return document.getElementById('dc-dienst-team-form');
	}

	function msg(key, fallback) {
		const el = root();
		return (el && el.getAttribute('data-' + key)) || fallback || '';
	}

	function setStatus(text, kind) {
		const el = document.getElementById('dc-dienst-team-status');
		if (!el) return;
		el.hidden = !text;
		el.textContent = text || '';
		el.classList.toggle('dc-roster-flash--error', kind === 'error');
	}

	function applyToForm(data) {
		const f = form();
		if (!f || !data) return;
		BOOL_FIELDS.forEach((name) => {
			const input = f.elements.namedItem(name);
			if (input && 'checked' in input) {
				input.checked = Boolean(data[name]);
			}
		});
		if (f.swapApprovalMode) {
			f.swapApprovalMode.value = data.swapApprovalMode === 'bilateral_auto'
				? 'bilateral_auto'
				: 'planner_required';
		}
		if (f.pushQuietHoursStart && data.pushQuietHoursStart) {
			f.pushQuietHoursStart.value = String(data.pushQuietHoursStart).slice(0, 5);
		}
		if (f.pushQuietHoursEnd && data.pushQuietHoursEnd) {
			f.pushQuietHoursEnd.value = String(data.pushQuietHoursEnd).slice(0, 5);
		}
		const badge = document.getElementById('dc-dt-quiet-badge');
		if (badge) {
			badge.hidden = Boolean(data.pushQuietHoursEnabled);
		}
		const warn = document.getElementById('dc-dt-soll-warning');
		if (warn) {
			warn.hidden = !data.sollFromDutyWarning;
		}
		syncStandbyVisibility();
	}

	function syncStandbyVisibility() {
		const f = form();
		const enabled = !!(f && f.elements.namedItem('standbyEnabled') && f.elements.namedItem('standbyEnabled').checked);
		const extra = document.getElementById('dc-dt-standby-extra');
		if (extra) {
			extra.hidden = !enabled;
		}
		if (enabled) {
			void loadStandbyCategories();
		}
	}

	function readPayload() {
		const f = form();
		const out = {};
		BOOL_FIELDS.forEach((name) => {
			const input = f.elements.namedItem(name);
			out[name] = !!(input && input.checked);
		});
		out.swapApprovalMode = f.swapApprovalMode ? String(f.swapApprovalMode.value) : 'planner_required';
		out.pushQuietHoursStart = f.pushQuietHoursStart
			? String(f.pushQuietHoursStart.value || '22:00').slice(0, 5)
			: '22:00';
		out.pushQuietHoursEnd = f.pushQuietHoursEnd
			? String(f.pushQuietHoursEnd.value || '06:00').slice(0, 5)
			: '06:00';
		const rev = form()?._dcBaseline?.settingsRevision;
		if (rev) {
			out.settingsRevision = String(rev);
		}
		return out;
	}

	function setFormLocked(locked, reason) {
		const f = form();
		const btn = document.getElementById('dc-dienst-team-save');
		const banner = document.getElementById('dc-dt-admin-only');
		if (f) {
			Array.from(f.elements).forEach((el) => {
				if (el && 'disabled' in el && el.id !== 'dc-dienst-team-save') {
					el.disabled = !!locked;
				}
			});
		}
		document.querySelectorAll('#dc-dt-standby-cats-list .dc-standby-cats__action').forEach((btn) => {
			btn.disabled = !!locked;
		});
		if (btn) btn.disabled = !!locked;
		if (banner) {
			banner.hidden = !locked;
			const p = banner.querySelector('p');
			if (p) p.textContent = reason || msg('msg-admin-only');
		}
	}

	function setCatStatus(text, kind) {
		const el = document.getElementById('dc-dt-standby-cats-status');
		if (!el) return;
		el.hidden = !text;
		el.textContent = text || '';
		el.classList.toggle('dc-roster-flash--error', kind === 'error');
	}

	async function loadStandbyCategories() {
		const list = document.getElementById('dc-dt-standby-cats-list');
		if (!list) return;
		try {
			const res = await Api.get('/apps/dutycheck/api/standby/categories');
			renderStandbyCategories(res?.data?.categories || []);
		} catch (err) {
			setCatStatus(msg('msg-load-error', t('dutycheck', 'Could not load standby categories.')), 'error');
		}
	}

	function renderStandbyCategories(cats) {
		const list = document.getElementById('dc-dt-standby-cats-list');
		if (!list) return;
		list.textContent = '';
		if (!Array.isArray(cats) || !cats.length) {
			const li = document.createElement('li');
			li.className = 'dc-standby-cats__empty';
			li.textContent = t('dutycheck', 'No categories yet — you can leave this empty.');
			list.appendChild(li);
			return;
		}
		cats.forEach((cat) => {
			const li = document.createElement('li');
			li.className = 'dc-standby-cats__item' + (cat.active ? '' : ' dc-standby-cats__item--inactive');

			const swatch = document.createElement('span');
			swatch.className = 'dc-standby-cats__swatch';
			swatch.setAttribute('aria-hidden', 'true');
			if (cat.color) {
				swatch.style.backgroundColor = String(cat.color);
			}
			li.appendChild(swatch);

			const label = document.createElement('span');
			label.className = 'dc-standby-cats__label';
			label.textContent = String(cat.label || '');
			li.appendChild(label);

			const meta = document.createElement('span');
			meta.className = 'dc-standby-cats__meta';
			const bits = [];
			if (cat.defaultResponseTimeMinutes != null) {
				bits.push(t('dutycheck', '{n} min').replace('{n}', String(cat.defaultResponseTimeMinutes)));
			}
			if (!cat.active) {
				bits.push(t('dutycheck', 'inactive'));
			}
			meta.textContent = bits.join(' · ');
			li.appendChild(meta);

			const toggleBtn = document.createElement('button');
			toggleBtn.type = 'button';
			toggleBtn.className = 'button dc-standby-cats__action';
			toggleBtn.textContent = cat.active
				? t('dutycheck', 'Deactivate')
				: t('dutycheck', 'Activate');
			toggleBtn.addEventListener('click', () => { void setCategoryActive(cat, !cat.active); });
			li.appendChild(toggleBtn);

			const delBtn = document.createElement('button');
			delBtn.type = 'button';
			delBtn.className = 'button dc-standby-cats__action dc-standby-cats__action--danger';
			delBtn.textContent = t('dutycheck', 'Delete');
			delBtn.setAttribute('aria-label', t('dutycheck', 'Delete category {name}').replace('{name}', String(cat.label || '')));
			delBtn.addEventListener('click', () => { void deleteCategory(cat); });
			li.appendChild(delBtn);

			list.appendChild(li);
		});
	}

	async function addStandbyCategory() {
		const labelEl = document.getElementById('dc-dt-cat-label');
		const colorEl = document.getElementById('dc-dt-cat-color');
		const rtEl = document.getElementById('dc-dt-cat-rt');
		const label = String(labelEl?.value || '').trim();
		if (!label) {
			setCatStatus(t('dutycheck', 'Enter a category name.'), 'error');
			labelEl?.focus();
			return;
		}
		const rtRaw = String(rtEl?.value || '').trim();
		if (rtRaw !== '' && !/^\d+$/.test(rtRaw)) {
			setCatStatus(t('dutycheck', 'Response time must be between 0 and 10080 minutes.'), 'error');
			rtEl?.focus();
			return;
		}
		const payload = {
			label,
			color: String(colorEl?.value || '').trim() || null,
			defaultResponseTimeMinutes: rtRaw === '' ? null : Number(rtRaw),
		};
		const btn = document.getElementById('dc-dt-cat-add');
		if (btn) btn.disabled = true;
		try {
			await Api.post('/apps/dutycheck/api/standby/categories', payload);
			if (labelEl) labelEl.value = '';
			if (rtEl) rtEl.value = '';
			setCatStatus(t('dutycheck', 'Category added.'));
			await loadStandbyCategories();
		} catch (err) {
			setCatStatus(categoryErrorMessage(err), 'error');
		} finally {
			if (btn) btn.disabled = false;
		}
	}

	async function setCategoryActive(cat, active) {
		try {
			await Api.post('/apps/dutycheck/api/standby/categories/' + encodeURIComponent(cat.id), { active: !!active });
			setCatStatus(active
				? t('dutycheck', 'Category activated.')
				: t('dutycheck', 'Category deactivated — existing on-call duties keep their label.'));
			await loadStandbyCategories();
		} catch (err) {
			setCatStatus(categoryErrorMessage(err), 'error');
		}
	}

	async function deleteCategory(cat) {
		const ok = await C.confirmDialog({
			title: t('dutycheck', 'Delete category'),
			body: t('dutycheck', 'Really delete “{name}”? On-call duties that use it keep their label.').replace('{name}', String(cat.label || '')),
			confirmLabel: t('dutycheck', 'Delete'),
			cancelLabel: t('dutycheck', 'Cancel'),
		});
		if (!ok) return;
		try {
			await Api.del('/apps/dutycheck/api/standby/categories/' + encodeURIComponent(cat.id));
			setCatStatus(t('dutycheck', 'Category deleted.'));
			await loadStandbyCategories();
		} catch (err) {
			setCatStatus(categoryErrorMessage(err), 'error');
		}
	}

	function categoryErrorMessage(err) {
		const code = String(err?.code || err?.payload?.error?.code || '');
		switch (code) {
			case 'STANDBY_CATEGORY_IN_USE':
				return t('dutycheck', 'This category is still used by on-call duties. Deactivate it instead.');
			case 'STANDBY_CATEGORY_INVALID':
				return t('dutycheck', 'Check the category name and colour.');
			case 'INVALID_RESPONSE_TIME':
				return t('dutycheck', 'Response time must be between 0 and 10080 minutes.');
			case 'FORBIDDEN':
			case 'INSUFFICIENT_ROLE':
			case 'access_denied':
				return msg('msg-admin-only', t('dutycheck', 'Only a DutyCheck app admin can change categories.'));
			default:
				return t('dutycheck', 'Could not save the category.');
		}
	}

	function wireMoreToggle() {
		const details = document.getElementById('dc-dt-more');
		const blocks = document.querySelectorAll('[data-dc-more]');
		const sync = () => {
			const open = !!(details && details.open);
			blocks.forEach((el) => {
				el.hidden = !open;
			});
		};
		details?.addEventListener('toggle', sync);
		sync();
	}

	async function confirmSollIfNeeded(payload, previous) {
		if (!payload.sollFromDuty || (previous && previous.sollFromDuty)) {
			return true;
		}
		const warnNeeded = previous?.sollFromDutyWarning === true
			|| document.getElementById('dc-dt-soll-warning')?.hidden === false;
		if (!warnNeeded) {
			return true;
		}
		return C.confirmDialog({
			title: t('dutycheck', 'Soll from Duty'),
			body: msg('msg-soll-warn', t('dutycheck', 'Turn on Soll from Duty anyway?')),
			confirmLabel: t('dutycheck', 'Turn on'),
			cancelLabel: t('dutycheck', 'Cancel'),
		});
	}

	async function load() {
		try {
			const res = await Api.get('/apps/dutycheck/api/self-service/settings');
			const data = res?.data || {};
			applyToForm(data);
			form()._dcBaseline = data;
			setFormLocked(false);
			setStatus('');
		} catch (err) {
			const code = String(err?.code || err?.payload?.error?.code || '');
			if (code === 'FORBIDDEN') {
				setFormLocked(true, msg('msg-admin-only'));
				setStatus('');
				return;
			}
			setStatus(msg('msg-load-error'), 'error');
			Msg.handleApiError(err);
		}
	}

	async function save(event) {
		event.preventDefault();
		const payload = readPayload();
		const previous = form()?._dcBaseline || {};
		const ok = await confirmSollIfNeeded(payload, previous);
		if (!ok) {
			const soll = form()?.elements?.namedItem('sollFromDuty');
			if (soll) soll.checked = false;
			return;
		}
		const btn = document.getElementById('dc-dienst-team-save');
		if (btn) {
			btn.disabled = true;
			btn.setAttribute('aria-busy', 'true');
		}
		setStatus(msg('msg-saving', t('dutycheck', 'Saving…')));
		try {
			const res = await Api.post('/apps/dutycheck/api/self-service/settings', payload);
			applyToForm(res?.data || payload);
			form()._dcBaseline = res?.data || payload;
			const saved = msg('msg-saved', t('dutycheck', 'Duty & team settings saved.'));
			setStatus(saved);
			Msg.announce(saved, 'success');
		} catch (err) {
			const code = String(err?.code || err?.payload?.error?.code || '');
			if (code === 'SETTINGS_CONFLICT') {
				await load();
				const conflict = msg('msg-conflict', t('dutycheck', 'Someone else saved these settings first. We reloaded — please review and save again.'));
				setStatus(conflict, 'error');
				Msg.announce(conflict, 'error');
			} else {
				setStatus(msg('msg-load-error'), 'error');
				Msg.handleApiError(err);
			}
		} finally {
			if (btn) {
				btn.disabled = false;
				btn.removeAttribute('aria-busy');
			}
		}
	}

	document.addEventListener('DOMContentLoaded', () => {
		if (!root() || !form()) return;
		wireMoreToggle();
		form().addEventListener('submit', (e) => { void save(e); });
		document.getElementById('dc-dt-quiet')?.addEventListener('change', (e) => {
			const badge = document.getElementById('dc-dt-quiet-badge');
			if (badge) badge.hidden = !!e.target.checked;
		});
		document.getElementById('dc-dt-standby')?.addEventListener('change', syncStandbyVisibility);
		document.getElementById('dc-dt-cat-add')?.addEventListener('click', () => { void addStandbyCategory(); });
		['dc-dt-cat-label', 'dc-dt-cat-rt'].forEach((id) => {
			document.getElementById(id)?.addEventListener('keydown', (e) => {
				if (e.key === 'Enter') {
					e.preventDefault();
					void addStandbyCategory();
				}
			});
		});
		void load();
	});
})();
