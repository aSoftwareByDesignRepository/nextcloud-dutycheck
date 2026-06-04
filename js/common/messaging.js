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

	// Codes that carry a human-meaningful, already-safe explanation. Anything
	// not listed here is treated as an internal detail and replaced with a
	// generic message so raw identifiers (REQUEST_FAILED, INTERNAL_ERROR, …)
	// never reach the user. Keep these in sync with the backend error codes.
	function knownCodeMessage(code) {
		switch (code) {
			case 'INVALID_DISPLAY_NAME':
			case 'INVALID_LOCATION_NAME':
				return t('dutycheck', 'Please enter a valid name (1–191 characters, no control characters).');
			case 'EMPLOYEE_NAME_EXISTS':
				return t('dutycheck', 'An employee with that display name already exists.');
			case 'LOCATION_NAME_EXISTS':
				return t('dutycheck', 'A location with that name already exists.');
			case 'INVALID_LINKED_USER':
				return t('dutycheck', 'The selected user could not be linked. Pick another account.');
			case 'LINKED_USER_EXISTS':
				return t('dutycheck', 'That user is already linked to another employee.');
			case 'INVALID_TIMEZONE':
				return t('dutycheck', 'Please choose a valid timezone.');
			case 'ASSIGNMENT_OVERLAP':
			case 'ASSIGNMENT_DUPLICATE_SLOT':
				return t('dutycheck', 'This shift overlaps an existing assignment.');
			case 'ABSENCE_OVERLAP':
				return t('dutycheck', 'This absence overlaps an existing entry.');
			case 'ABSENCE_CONFLICT':
				return t('dutycheck', 'This employee has approved leave on that date and cannot be scheduled.');
			case 'PERIOD_NOT_OPEN':
				return t('dutycheck', 'This planning period is not open for changes.');
			case 'PERIOD_HAS_HARD_CONFLICTS':
				return t('dutycheck', 'Resolve every “Must fix” issue before continuing.');
			case 'REASON_TOO_SHORT':
				return t('dutycheck', 'Please provide a longer reason.');
			case 'INVALID_DATE':
			case 'INVALID_TIME':
				return t('dutycheck', 'Please check the dates and times.');
			case 'INVALID_SHIFT_LENGTH':
				return t('dutycheck', 'Shift length is invalid after break (check overnight times and break minutes).');
			case 'INVALID_BREAK_MINUTES':
				return t('dutycheck', 'Break minutes are out of the allowed range.');
			case 'INVALID_ACTIVE_FLAG':
				return t('dutycheck', 'Could not read the active/inactive setting. Reload the page and try again.');
			case 'PERIOD_ID_REQUIRED':
				return t('dutycheck', 'Select a period before saving.');
			case 'EMPLOYEE_ID_REQUIRED':
				return t('dutycheck', 'Select an employee.');
			case 'LOCATION_ID_REQUIRED':
				return t('dutycheck', 'Select a location.');
			case 'CONFLICT_ACK_REQUIRED':
				return t('dutycheck', 'A planning rule needs your confirmation before this shift can be saved.');
			case 'INTEGRATION_PEER_NOT_INSTALLED':
				return t('dutycheck', 'Peer app is not installed.');
			case 'INTEGRATION_PEER_DISABLED':
				return t('dutycheck', 'Peer app disabled for this instance.');
			case 'INTEGRATION_PEER_VERSION':
				return t('dutycheck', 'Peer app version is too old.');
			case 'INTEGRATION_LEGACY_CONFLICT':
				return t('dutycheck', 'Legacy DutyCheck absences exist on linked employees. Resolve them before enabling integration.');
			case 'INTEGRATION_PURGE_BLOCKED':
				return t('dutycheck', 'Cannot remove legacy absences while integration is enabled. Disable integration first.');
			case 'INTEGRATION_SYNC_THROTTLED':
				return t('dutycheck', 'Manual sync was rate-limited. Wait a moment and try again.');
			case 'INTEGRATION_SYNC_ALREADY_RUNNING':
				return t('dutycheck', 'Sync is already running.');
			case 'INTEGRATION_SYNC_FAILED':
			case 'INTEGRATION_SYNC_BREAKER_TRIPPED':
				return t('dutycheck', 'Sync failed — see server logs or try again later.');
			case 'ACCESS_LIST_REQUIRED':
				return t('dutycheck', 'Add at least one allowed user or group when restriction is enabled.');
			case 'INTERNAL_ERROR':
				return t('dutycheck', 'The server could not complete this action. Reload the page and try again, or contact an administrator.');
			case 'EMPLOYEE_NOT_FOUND':
				return t('dutycheck', 'Employee not found or no longer available.');
			case 'LOCATION_NOT_FOUND':
				return t('dutycheck', 'Location not found or no longer available.');
			case 'DATE_OUTSIDE_PERIOD':
				return t('dutycheck', 'Date must fall within the selected period.');
			case 'ASSIGNMENT_DUPLICATE_SLOT':
				return t('dutycheck', 'This exact assignment already exists. Reload the page to see the latest roster.');
			default:
				return null;
		}
	}

	function handleApiError(err, options) {
		const status = Number((err && err.status) || 0);
		const code = err && err.code ? String(err.code) : null;
		const friendly = knownCodeMessage(code);
		const genericMessage = t('dutycheck', 'Something went wrong. Please try again, and contact an administrator if it keeps happening.');
		if (status === 0 && code === 'NETWORK_ERROR') {
			announce(t('dutycheck', 'Network error. Please check your connection and retry.'), 'error');
			return;
		}
		if (code === 'MISSING_CSRF' || code === 'CSRF_FAILED') {
			announce(t('dutycheck', 'Your security token expired. Please reload the page and try again.'), 'error');
			return;
		}
		if (status === 401 || code === 'SESSION_EXPIRED' || code === 'NOT_AUTHENTICATED') {
			announce(t('dutycheck', 'Your session expired. Please reload and sign in again.'), 'error');
			return;
		}
		if (status === 412) {
			announce(t('dutycheck', 'Your security token expired. Please reload the page and try again.'), 'error');
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
		if (code === 'CONFLICT_ACK_REQUIRED') {
			// Handled by roster.js (modal acknowledgement workflow).
			return;
		}
		const reloadOnVersionConflict = !options || options.reloadOnConflict !== false;
		if (status === 409 || code === 'version_conflict') {
			if (code === 'INTEGRATION_LEGACY_CONFLICT' || code === 'INTEGRATION_PURGE_BLOCKED') {
				announce(friendly || genericMessage, 'error');
				return;
			}
			announce(t('dutycheck', 'Someone else changed this entry. Reloading...'), 'warning');
			if (reloadOnVersionConflict) {
				window.setTimeout(() => window.location.reload(), 600);
			}
			return;
		}
		if (friendly) {
			announce(friendly, 'error');
			return;
		}
		if (status === 422) {
			announce(friendly || t('dutycheck', 'Some details could not be saved. Please review the form and try again.'), 'error');
			return;
		}
		announce(friendly || genericMessage, 'error');
	}

	// Backwards-compatible alias: pre-refactor callers used `toast(message, kind)`.
	// Internally identical to `announce`; we keep the shorter name for brevity in
	// per-page scripts but route both to the same toast/live-region pipeline.
	function toast(message, kind) {
		announce(message, kind);
	}

	window.DutyCheckMessaging = { announce, toast, handleApiError };
})();
