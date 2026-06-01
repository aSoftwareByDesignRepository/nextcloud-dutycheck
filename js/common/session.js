(function () {
	'use strict';

	/**
	 * Refresh the CSRF token when a DutyCheck page loads so the first save after
	 * a long-lived tab does not fail before api.js can retry.
	 */
	document.addEventListener('DOMContentLoaded', () => {
		const refresh = window.DutyCheckApi && window.DutyCheckApi.refreshCsrfToken;
		if (typeof refresh !== 'function') {
			return;
		}
		refresh().catch(() => {
			// Silent: api.js will surface a clear error on the first mutation.
		});
	});
})();
