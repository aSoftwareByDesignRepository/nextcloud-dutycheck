(function () {
	'use strict';

	/**
	 * Centralised JSON API client for DutyCheck.
	 *
	 * - Same-origin credentials, JSON Accept header, form-urlencoded mutation bodies.
	 * - Always sends the CSRF token (`requesttoken`) on mutations (header + body).
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
	 * - Detects AbortError / page unload / AbortSignal cancellation and surfaces
 *   them as `REQUEST_ABORTED` so messaging never toasts a false “Network error”
 *   when the user simply clicks another page (in-flight fetches are cancelled).
 * - Pure-function URL builder so unit tests stay deterministic.
	 *
	 * The raw `request(url, options)` API is preserved for legacy callers;
	 * new code should prefer `get/post/put/del(path, ...)` which speak in
	 * route paths (e.g. `/apps/dutycheck/api/employees`) and let the router
	 * resolve them via `OC.generateUrl`.
	 */

	const MUTATION_METHODS = new Set(['POST', 'PUT', 'PATCH', 'DELETE']);

	/**
	 * Page-lifetime abort + unload tracking.
	 *
	 * Clicking another Nextcloud page cancels in-flight fetches. Without this,
	 * those cancellations surface as scary “Network error” toasts on the way out
	 * (and briefly flash before the document is torn down). We:
	 *  1. Abort a shared page controller on pagehide/beforeunload.
	 *  2. Classify AbortError / aborted signals / unload as REQUEST_ABORTED.
	 *  3. Recreate the controller after bfcache restore (pageshow.persisted).
	 */
	let pageUnloading = false;
	let pageAbortController = typeof AbortController === 'function'
		? new AbortController()
		: null;

	function markPageUnloading() {
		pageUnloading = true;
		if (pageAbortController && !pageAbortController.signal.aborted) {
			try {
				pageAbortController.abort();
			} catch (_) {
				/* ignore */
			}
		}
	}

	function resetPageLifecycle() {
		pageUnloading = false;
		if (typeof AbortController === 'function') {
			pageAbortController = new AbortController();
		}
	}

	function isPageUnloading() {
		return pageUnloading === true;
	}

	function bindPageLifecycle() {
		if (typeof window === 'undefined' || typeof window.addEventListener !== 'function') {
			return;
		}
		// Capture phase so we flip the flag before late bubble-phase listeners
		// try to toast a rejected fetch from the same navigation.
		window.addEventListener('pagehide', markPageUnloading, true);
		window.addEventListener('beforeunload', markPageUnloading, true);
		window.addEventListener('pageshow', (event) => {
			if (pageUnloading || (event && event.persisted)) {
				resetPageLifecycle();
			}
		});
	}
	bindPageLifecycle();

	/**
	 * True when a thrown/rejected value is an intentional cancel (caller abort,
	 * page teardown, or browser AbortError), not a connectivity failure.
	 */
	function isAborted(err) {
		if (!err) {
			return false;
		}
		if (err.code === 'REQUEST_ABORTED' || err.name === 'AbortError') {
			return true;
		}
		if (err.cause && (err.cause.name === 'AbortError' || err.cause.code === 20)) {
			return true;
		}
		// DOMException.ABORT_ERR === 20 in browsers that expose numeric codes.
		if (err.code === 20 || err.code === 'ABORT_ERR') {
			return true;
		}
		return false;
	}

	function abortedError(cause) {
		const err = new Error('REQUEST_ABORTED');
		err.name = 'AbortError';
		err.payload = { ok: false, error: { code: 'REQUEST_ABORTED' } };
		err.status = 0;
		err.code = 'REQUEST_ABORTED';
		if (cause) {
			err.cause = cause;
		}
		return err;
	}

	/**
	 * Merge a caller AbortSignal with the page-lifetime signal so navigation
	 * always cancels outstanding DutyCheck requests with a clean AbortError.
	 */
	function composeAbortSignal(userSignal) {
		const pageSignal = pageAbortController ? pageAbortController.signal : null;
		if (!userSignal) {
			return pageSignal || undefined;
		}
		if (!pageSignal) {
			return userSignal;
		}
		if (userSignal.aborted || pageSignal.aborted) {
			if (typeof AbortController !== 'function') {
				return userSignal.aborted ? userSignal : pageSignal;
			}
			const done = new AbortController();
			done.abort();
			return done.signal;
		}
		if (typeof AbortSignal !== 'undefined' && typeof AbortSignal.any === 'function') {
			return AbortSignal.any([userSignal, pageSignal]);
		}
		const merged = new AbortController();
		const onAbort = () => {
			if (!merged.signal.aborted) {
				merged.abort();
			}
		};
		userSignal.addEventListener('abort', onAbort, { once: true });
		pageSignal.addEventListener('abort', onAbort, { once: true });
		return merged.signal;
	}

	function csrfToken() {
		if (window.OC && window.OC.requestToken) {
			return String(window.OC.requestToken);
		}
		const input = document.querySelector('input[name="requesttoken"]');
		return input ? String(input.value || '') : '';
	}

	function resolveUrl(path) {
		return (window.OC && typeof window.OC.generateUrl === 'function')
			? window.OC.generateUrl(path)
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
				window.OC.requestToken = token;
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

	function buildHeaders(opts, token, hasBody, formEncoded) {
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
			headers.set(
				'Content-Type',
				formEncoded
					? 'application/x-www-form-urlencoded;charset=UTF-8'
					: 'application/json',
			);
		}
		return headers;
	}

	/**
	 * Encode a mutation payload for application/x-www-form-urlencoded.
	 *
	 * Nextcloud always populates $_POST for urlencoded bodies; JSON bodies depend
	 * on Content-Type surviving proxies (Snap / reverse-proxy edge cases). The
	 * CSRF token is duplicated in the body because some hosts strip custom headers.
	 */
	function appendFormField(params, key, value) {
		if (value === undefined || value === null) {
			return;
		}
		if (Array.isArray(value)) {
			value.forEach((entry, index) => {
				appendFormField(params, `${key}[${index}]`, entry);
			});
			return;
		}
		if (typeof value === 'object') {
			Object.entries(value).forEach(([childKey, childValue]) => {
				appendFormField(params, `${key}[${childKey}]`, childValue);
			});
			return;
		}
		params.append(key, String(value));
	}

	function encodeMutationBody(body, token) {
		const params = new URLSearchParams();
		if (token) {
			params.append('requesttoken', token);
		}
		Object.entries(body || {}).forEach(([key, value]) => {
			if (value === undefined || value === null) {
				return;
			}
			if (Array.isArray(value)) {
				if (value.length === 0) {
					return;
				}
				value.forEach((entry, index) => {
					appendFormField(params, `${key}[${index}]`, entry);
				});
				return;
			}
			if (typeof value === 'object') {
				Object.entries(value).forEach(([childKey, childValue]) => {
					appendFormField(params, `${key}[${childKey}]`, childValue);
				});
				return;
			}
			params.append(key, String(value));
		});
		return params.toString();
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
	 * Classify a fetch() rejection: intentional cancel vs real connectivity loss.
	 * During unload, browsers often reject with TypeError("Failed to fetch")
	 * instead of AbortError — that must not become a user-facing network toast.
	 */
	function classifyFetchFailure(cause, signal) {
		if (isPageUnloading()) {
			return abortedError(cause);
		}
		if (signal && signal.aborted) {
			return abortedError(cause || signal.reason);
		}
		if (isAborted(cause)) {
			return abortedError(cause);
		}
		return networkError(cause);
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

	/**
	 * Normalise DutyCheck API error payloads (and a few Nextcloud core shapes).
	 *
	 * @returns {{ code: string|null, payload: object|null }}
	 */
	function extractApiError(data, status) {
		if (data && typeof data === 'object') {
			const code = (data.error && data.error.code)
				|| data.code
				|| (isCsrfFailure(status, data) ? 'CSRF_FAILED' : null);
			return { code: code ? String(code) : null, payload: data };
		}
		if (isCsrfFailure(status, data)) {
			return { code: 'CSRF_FAILED', payload: null };
		}
		return { code: null, payload: null };
	}

	function apiError(code, message, status, payload, cause) {
		const err = new Error(code || message || 'REQUEST_FAILED');
		err.status = status;
		err.payload = payload;
		err.code = code;
		if (cause) {
			err.cause = cause;
		}
		return err;
	}

	function assertSuccessPayload(data, status) {
		if (!data || typeof data !== 'object' || data.ok !== false) {
			return;
		}
		const extracted = extractApiError(data, status);
		throw apiError(
			extracted.code,
			extracted.code || 'REQUEST_FAILED',
			status,
			extracted.payload,
		);
	}

	async function readBody(response, signal) {
		const contentType = (response.headers.get('content-type') || '').toLowerCase();
		const isJson = contentType.includes('application/json');
		try {
			const data = isJson ? await response.json() : await response.text();
			return { isJson, data };
		} catch (cause) {
			if (isPageUnloading() || (signal && signal.aborted) || isAborted(cause)) {
				throw abortedError(cause);
			}
			if (isJson) {
				return { isJson, data: { ok: false, error: { code: 'INVALID_JSON' } } };
			}
			return { isJson, data: '' };
		}
	}

	function resolveAppPath(pathOrUrl, params) {
		if (typeof pathOrUrl !== 'string' || !/^\//.test(pathOrUrl) || pathOrUrl.includes('://')) {
			return pathOrUrl;
		}
		return params ? buildUrl(pathOrUrl, params) : resolveUrl(pathOrUrl);
	}

	async function request(pathOrUrl, options) {
		const opts = options || {};
		const method = String(opts.method || 'GET').toUpperCase();
		const isMutation = MUTATION_METHODS.has(method);
		const hasBody = opts.body !== undefined && opts.body !== null;

		const url = resolveAppPath(pathOrUrl, opts.params);

		const formEncoded = hasBody && typeof opts.body !== 'string';

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
		const signal = composeAbortSignal(opts.signal);

		for (let attempt = 0; attempt < maxAttempts; attempt++) {
			if (isPageUnloading() || (signal && signal.aborted)) {
				throw abortedError(signal && signal.reason);
			}

			const bodyInit = hasBody
				? (typeof opts.body === 'string'
					? opts.body
					: encodeMutationBody(opts.body, token))
				: undefined;
			const headers = buildHeaders(opts, token, hasBody, formEncoded);

			let response;
			try {
				response = await fetch(url, {
					method,
					credentials: 'same-origin',
					headers,
					body: bodyInit,
					signal,
				});
			} catch (cause) {
				throw classifyFetchFailure(cause, signal);
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

			const { isJson, data } = await readBody(response, signal);

			if (response.ok) {
				// Every DutyCheck endpoint answers JSON. A non-JSON 2xx on a write
				// means we silently followed Nextcloud's session/cookie redirect to
				// an HTML page; treat it as an expired session instead of pretending
				// the write succeeded.
				if (isMutation && !isJson) {
					throw apiError('SESSION_EXPIRED', 'SESSION_EXPIRED', 401, null);
				}
				assertSuccessPayload(data, response.status);
				return data;
			}

			// Stale CSRF token: refresh once and retry the same request.
			if (isMutation && attempt + 1 < maxAttempts && isCsrfFailure(response.status, data)) {
				const fresh = await refreshCsrfToken();
				if (fresh) {
					token = fresh;
					continue;
				}
				throw apiError('CSRF_FAILED', 'CSRF_FAILED', response.status, data);
			}

			const extracted = extractApiError(data, response.status);
			lastError = apiError(
				extracted.code,
				extracted.code || (data && typeof data === 'object' && data.message
					? String(data.message)
					: 'REQUEST_FAILED'),
				response.status,
				extracted.payload,
			);
			throw lastError;
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

	window.DutyCheckApi = {
		request,
		get,
		post,
		put,
		del,
		buildUrl,
		resolveAppPath,
		refreshCsrfToken,
		extractApiError,
		isAborted,
		isPageUnloading,
		/** @internal test/lifecycle hooks — do not call from page scripts. */
		_markPageUnloading: markPageUnloading,
		_resetPageLifecycle: resetPageLifecycle,
		_classifyFetchFailure: classifyFetchFailure,
		_composeAbortSignal: composeAbortSignal,
		_abortedError: abortedError,
	};
})();
