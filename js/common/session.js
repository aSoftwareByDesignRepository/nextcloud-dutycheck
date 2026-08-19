(function () {
	'use strict';

	/**
	 * Session helpers. CSRF is refreshed lazily in api.js on 412 — prefetching
	 * GET /csrftoken on every DutyCheck navigation added a round trip to every
	 * page (and every modal-bearing load) without making the first write safer.
	 *
	 * This file still ships after common/api so script-order contracts hold.
	 */
	window.DutyCheckSession = Object.freeze({
		csrfPrefetch: false,
	});
})();
