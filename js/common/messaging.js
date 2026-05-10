(function () {
	'use strict';

	let toastContainer = null;

	function ensureToastContainer() {
		if (toastContainer && document.body.contains(toastContainer)) {
			return toastContainer;
		}
		toastContainer = document.createElement('div');
		toastContainer.className = 'dc-toasts';
		toastContainer.id = 'dc-toasts';
		document.body.appendChild(toastContainer);
		return toastContainer;
	}

	function announce(message, kind) {
		const k = kind === 'error'
			? 'error'
			: (kind === 'warning' ? 'warning' : (kind === 'info' ? 'info' : 'success'));
		const polite = document.getElementById('dc-live-region');
		const assertive = document.getElementById('dc-alert-region');
		const target = (k === 'error' ? assertive : polite);
		if (target) {
			target.textContent = '';
			window.setTimeout(() => { target.textContent = String(message); }, 10);
		}
		const container = ensureToastContainer();
		const toast = document.createElement('div');
		toast.className = 'dc-toast dc-toast--' + k;
		toast.setAttribute('role', k === 'error' ? 'alert' : 'status');
		const text = document.createElement('span');
		text.textContent = String(message);
		const close = document.createElement('button');
		close.type = 'button';
		close.className = 'dc-toast__close';
		close.setAttribute('aria-label', t('dutycheck', 'Dismiss'));
		close.textContent = '\u2715';
		close.addEventListener('click', () => toast.remove());
		toast.appendChild(text);
		toast.appendChild(close);
		container.appendChild(toast);
		window.setTimeout(() => {
			if (toast.parentNode) toast.parentNode.removeChild(toast);
		}, k === 'error' ? 7000 : 4000);
	}

	function handleApiError(err, options) {
		const status = Number((err && err.status) || 0);
		const code = err && err.code ? String(err.code) : null;
		const messageRaw = String((err && err.message) || t('dutycheck', 'Request failed.'));
		if (status === 0 && code === 'NETWORK_ERROR') {
			announce(t('dutycheck', 'Network error. Please check your connection and retry.'), 'error');
			return;
		}
		if (status === 0 && code === 'MISSING_CSRF') {
			announce(t('dutycheck', 'Security token missing. Please reload the page.'), 'error');
			return;
		}
		if (status === 401) {
			announce(t('dutycheck', 'Your session expired. Please reload and sign in again.'), 'error');
			return;
		}
		if (code === 'INSUFFICIENT_ROLE') {
			announce(t('dutycheck', 'You do not have permission for this DutyCheck action. Open a section that matches your role, or ask an administrator.'), 'error');
			return;
		}
		if (code === 'EMPLOYEE_RECORD_LINK_REQUIRED') {
			announce(t('dutycheck', 'DutyCheck is unavailable until your Nextcloud account is linked to an employee record. Ask a planner or administrator.'), 'error');
			return;
		}
		if (code === 'INTEGRATION_ABSENCE_READONLY') {
			announce(t('dutycheck', 'Absences for linked accounts are managed in ArbeitszeitCheck while the integration is on. DutyCheck cannot apply this change.'), 'error');
			return;
		}
		if (status === 403 || code === 'access_denied' || code === 'app_access_denied') {
			announce(t('dutycheck', 'You are not authorized to perform that action.'), 'error');
			return;
		}
		if (status === 429 || code === 'rate_limit_exceeded') {
			announce(t('dutycheck', 'Too many requests. Please wait and retry.'), 'warning');
			return;
		}
		if (status === 409 && code === 'conflict_ack_required') {
			// soft-conflict that needs the operator to acknowledge in a modal
			// is handled by the page-level workflow, not here
			return;
		}
		if (status === 409 || code === 'version_conflict') {
			announce(t('dutycheck', 'Someone else changed this entry. Reloading...'), 'warning');
			if (!options || options.reloadOnConflict !== false) {
				window.setTimeout(() => window.location.reload(), 600);
			}
			return;
		}
		if (status === 422) {
			announce(messageRaw || t('dutycheck', 'Validation failed.'), 'error');
			return;
		}
		announce(messageRaw, 'error');
	}

	// Backwards-compatible alias: pre-refactor callers used `toast(message, kind)`.
	// Internally identical to `announce`; we keep the shorter name for brevity in
	// per-page scripts but route both to the same toast/live-region pipeline.
	function toast(message, kind) {
		announce(message, kind);
	}

	window.DutyCheckMessaging = { announce, toast, handleApiError };
})();
