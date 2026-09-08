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
		if (btn) btn.disabled = !!locked;
		if (banner) {
			banner.hidden = !locked;
			const p = banner.querySelector('p');
			if (p) p.textContent = reason || msg('msg-admin-only');
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
		if (typeof C.confirmDialog === 'function') {
			return C.confirmDialog({
				title: t('dutycheck', 'Soll from Duty'),
				body: msg('msg-soll-warn', t('dutycheck', 'Turn on Soll from Duty anyway?')),
				confirmLabel: t('dutycheck', 'Turn on'),
				cancelLabel: t('dutycheck', 'Cancel'),
			});
		}
		return window.confirm(msg('msg-soll-warn'));
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
		void load();
	});
})();
