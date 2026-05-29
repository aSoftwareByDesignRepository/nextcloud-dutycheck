(function () {
	'use strict';

	/**
	 * Centralised JSON API client for DutyCheck.
	 *
	 * - Same-origin credentials, JSON Content-Type on bodies, JSON parsing.
	 * - Always sends the CSRF token (`requesttoken`) on mutations.
	 * - Transparently refreshes a stale CSRF token and retries a mutation once
	 *   when Nextcloud answers `412 Precondition Failed` ("CSRF check failed").
	 *   Long-lived or multi-tab sessions rotate the request token; without this
	 *   recovery the very first write after a rotation fails with an opaque
	 *   error and nothing is written to nextcloud.log (CSRF rejections are not
	 *   logged server-side). This mirrors what `@nextcloud/axios` does for the
	 *   rest of the Nextcloud frontend.
	 * - Detects the SameSite-strict-cookie redirect Nextcloud emits when the
	 *   session can no longer be trusted, and surfaces it as a clean
	 *   `SESSION_EXPIRED` error instead of a silently-followed HTML page.
	 * - Normalises errors so callers can branch on `error.status`,
	 *   `error.code`, and `error.payload`.
	 * - Pure-function URL builder so unit tests stay deterministic.
	 *
	 * The raw `request(url, options)` API is preserved for legacy callers;
	 * new code should prefer `get/post/put/del(path, ...)` which speak in
	 * route paths (e.g. `/apps/dutycheck/api/employees`) and let the router
	 * resolve them via `OC.generateUrl`.
	 */

	const MUTATION_METHODS = new Set(['POST', 'PUT', 'PATCH', 'DELETE']);

	function csrfToken() {
		if (window.OC && OC.requestToken) {
			return String(OC.requestToken);
		}
		const input = document.querySelector('input[name="requesttoken"]');
		return input ? String(input.value || '') : '';
	}

	function resolveUrl(path) {
		return (typeof OC !== 'undefined' && typeof OC.generateUrl === 'function')
			? OC.generateUrl(path)
			: path;
	}

	/**
	 * Fetch a fresh CSRF token from Nextcloud and update the in-page token so
	 * subsequent requests (and other app scripts that read `OC.requestToken`)
	 * pick it up. Returns an empty string when no token could be obtained.
	 */
	async function refreshCsrfToken() {
		try {
			const response = await fetch(resolveUrl('/csrftoken'), {
				method: 'GET',
				credentials: 'same-origin',
				headers: {
					Accept: 'application/json',
					'X-Requested-With': 'XMLHttpRequest',
					'OCS-APIRequest': 'true',
				},
			});
			if (!response.ok) {
				return '';
			}
			const data = await response.json().catch(() => null);
			const token = data && typeof data.token === 'string' ? data.token : '';
			if (token && window.OC) {
				OC.requestToken = token;
				if (typeof OC.requestToken !== 'undefined') {
					document
						.querySelectorAll('head meta[name="requesttoken"], input[name="requesttoken"]')
						.forEach((el) => {
							if (el.tagName === 'META') {
								el.setAttribute('content', token);
							} else {
								el.value = token;
							}
						});
				}
			}
			return token;
		} catch (cause) {
			return '';
		}
	}

	function buildUrl(path, params) {
		const built = resolveUrl(path);
		const query = new URLSearchParams();
		Object.entries(params || {}).forEach(([key, value]) => {
			if (value === undefined || value === null || value === '') {
				return;
			}
			if (Array.isArray(value)) {
				value.forEach((entry) => {
					if (entry === undefined || entry === null || entry === '') return;
					query.append(key + '[]', String(entry));
				});
			} else {
				query.append(key, String(value));
			}
		});
		const suffix = query.toString();
		return suffix ? built + '?' + suffix : built;
	}

	function buildHeaders(opts, token, hasBody) {
		const headers = new Headers(opts.headers || {});
		headers.set('Accept', 'application/json');
		headers.set('X-Requested-With', 'XMLHttpRequest');
		headers.set('OCS-APIRequest', 'true');
		if (token) {
			// Some Nextcloud deployments enforce requesttoken checks beyond classic
			// mutation verbs for app routes; providing it consistently avoids 412s.
			headers.set('requesttoken', token);
		}
		if (hasBody && !headers.has('Content-Type')) {
			headers.set('Content-Type', 'application/json');
		}
		return headers;
	}

	function networkError(cause) {
		const err = new Error('NETWORK_ERROR');
		err.payload = { ok: false, error: { code: 'NETWORK_ERROR' } };
		err.status = 0;
		err.code = 'NETWORK_ERROR';
		err.cause = cause;
		return err;
	}

	/**
	 * Whether a non-ok response is a Nextcloud CSRF rejection that we can
	 * recover from by refreshing the token. Nextcloud returns 412 with a JSON
	 * body of `{"message":"CSRF check failed"}` for app routes that send an
	 * `Accept: application/json` header.
	 */
	function isCsrfFailure(status, data) {
		if (status !== 412) {
			return false;
		}
		const message = data && typeof data === 'object' && typeof data.message === 'string'
			? data.message.toLowerCase()
			: '';
		// Be liberal: any 412 on a state-changing call is treated as a token
		// problem. A correctly-formed request should never otherwise 412.
		return message.includes('csrf') || message === '';
	}

	async function readBody(response) {
		const contentType = (response.headers.get('content-type') || '').toLowerCase();
		const isJson = contentType.includes('application/json');
		const data = isJson
			? await response.json().catch(() => ({ ok: false, error: { code: 'INVALID_JSON' } }))
			: await response.text().catch(() => '');
		return { isJson, data };
	}

	async function request(pathOrUrl, options) {
		const opts = options || {};
		const method = String(opts.method || 'GET').toUpperCase();
		const isMutation = MUTATION_METHODS.has(method);
		const hasBody = opts.body !== undefined && opts.body !== null;

		let url = pathOrUrl;
		if (opts.params && typeof pathOrUrl === 'string' && /^\//.test(pathOrUrl) && !pathOrUrl.includes('://')) {
			url = buildUrl(pathOrUrl, opts.params);
		}

		const bodyInit = hasBody
			? (typeof opts.body === 'string' ? opts.body : JSON.stringify(opts.body))
			: undefined;

		let token = csrfToken();
		if (isMutation && !token) {
			// Recover a missing token before failing outright (e.g. a stale page
			// where OC.requestToken was cleared). Only error if recovery fails.
			token = await refreshCsrfToken();
			if (!token) {
				const err = new Error('MISSING_CSRF');
				err.status = 0;
				err.code = 'MISSING_CSRF';
				throw err;
			}
		}

		const maxAttempts = isMutation ? 2 : 1;
		let lastError = null;

		for (let attempt = 0; attempt < maxAttempts; attempt++) {
			const headers = buildHeaders(opts, token, hasBody);

			let response;
			try {
				response = await fetch(url, {
					method,
					credentials: 'same-origin',
					headers,
					body: bodyInit,
					signal: opts.signal,
				});
			} catch (cause) {
				throw networkError(cause);
			}

			// Nextcloud redirects state-changing requests to the web root (and
			// eventually the login page) when the SameSite-strict cookie is
			// missing or the session is no longer trusted. fetch follows that
			// redirect silently; treat a redirected non-JSON answer as a session
			// problem rather than reporting a misleading success/HTML payload.
			if (response.redirected && /\/login(\?|$)/.test(response.url || '')) {
				const err = new Error('SESSION_EXPIRED');
				err.status = 401;
				err.code = 'SESSION_EXPIRED';
				throw err;
			}

			const { isJson, data } = await readBody(response);

			if (response.ok) {
				// Every DutyCheck endpoint answers JSON. A non-JSON 2xx on a write
				// means we silently followed Nextcloud's session/cookie redirect to
				// an HTML page; treat it as an expired session instead of pretending
				// the write succeeded.
				if (isMutation && !isJson) {
					const err = new Error('SESSION_EXPIRED');
					err.status = 401;
					err.code = 'SESSION_EXPIRED';
					throw err;
				}
				return data;
			}

			// Stale CSRF token: refresh once and retry the same request.
			if (isMutation && attempt + 1 < maxAttempts && isCsrfFailure(response.status, data)) {
				const fresh = await refreshCsrfToken();
				if (fresh && fresh !== token) {
					token = fresh;
					continue;
				}
				// Could not obtain a usable token — surface a clean session error.
				const err = new Error('CSRF_FAILED');
				err.status = response.status;
				err.payload = data;
				err.code = 'CSRF_FAILED';
				throw err;
			}

			const code = (data && typeof data === 'object' && data.error && data.error.code)
				|| (isCsrfFailure(response.status, data) ? 'CSRF_FAILED' : null);
			const message = (data && typeof data === 'object' && data.message)
				? String(data.message)
				: 'REQUEST_FAILED';
			const err = new Error(code || message);
			err.status = response.status;
			err.payload = data;
			err.code = code;
			lastError = err;
			throw err;
		}

		// Unreachable in practice, but keep a defined failure mode.
		throw lastError || networkError(new Error('REQUEST_FAILED'));
	}

	function get(path, params, options) {
		return request(path, Object.assign({}, options || {}, { method: 'GET', params }));
	}
	function post(path, body, options) {
		return request(path, Object.assign({}, options || {}, { method: 'POST', body }));
	}
	function put(path, body, options) {
		return request(path, Object.assign({}, options || {}, { method: 'PUT', body }));
	}
	function del(path, body, options) {
		return request(path, Object.assign({}, options || {}, { method: 'DELETE', body }));
	}

	window.DutyCheckApi = { request, get, post, put, del, buildUrl, refreshCsrfToken };
})();
