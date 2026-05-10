(function () {
	'use strict';

	/**
	 * Centralised JSON API client for DutyCheck.
	 *
	 * - Same-origin credentials, JSON Content-Type on bodies, JSON parsing.
	 * - Always sends the CSRF token (`requesttoken`) on mutations.
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

	function buildUrl(path, params) {
		const built = (typeof OC !== 'undefined' && typeof OC.generateUrl === 'function')
			? OC.generateUrl(path)
			: path;
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

	async function request(pathOrUrl, options) {
		const opts = options || {};
		const method = String(opts.method || 'GET').toUpperCase();
		const isMutation = MUTATION_METHODS.has(method);
		const token = csrfToken();
		const headers = new Headers(opts.headers || {});
		headers.set('Accept', 'application/json');
		headers.set('X-Requested-With', 'XMLHttpRequest');
		headers.set('OCS-APIRequest', 'true');
		if (token && !headers.has('requesttoken')) {
			// Some Nextcloud deployments enforce requesttoken checks beyond classic
			// mutation verbs for app routes; providing it consistently avoids 412s.
			headers.set('requesttoken', token);
		}
		if (isMutation) {
			if (!token) {
				const err = new Error('MISSING_CSRF');
				err.status = 0;
				err.code = 'MISSING_CSRF';
				throw err;
			}
		}
		const hasBody = opts.body !== undefined && opts.body !== null;
		if (hasBody && !headers.has('Content-Type')) {
			headers.set('Content-Type', 'application/json');
		}

		let url = pathOrUrl;
		if (opts.params && typeof pathOrUrl === 'string' && /^\//.test(pathOrUrl) && !pathOrUrl.includes('://')) {
			url = buildUrl(pathOrUrl, opts.params);
		}

		let response;
		try {
			response = await fetch(url, {
				method,
				credentials: 'same-origin',
				headers,
				body: hasBody
					? (typeof opts.body === 'string' ? opts.body : JSON.stringify(opts.body))
					: undefined,
				signal: opts.signal,
			});
		} catch (cause) {
			const err = new Error('NETWORK_ERROR');
			err.payload = { ok: false, error: { code: 'NETWORK_ERROR' } };
			err.status = 0;
			err.code = 'NETWORK_ERROR';
			err.cause = cause;
			throw err;
		}

		const contentType = (response.headers.get('content-type') || '').toLowerCase();
		const isJson = contentType.includes('application/json');
		const data = isJson
			? await response.json().catch(() => ({ ok: false, error: { code: 'INVALID_JSON' } }))
			: await response.text().catch(() => '');

		if (!response.ok) {
			const code = (data && typeof data === 'object' && data.error && data.error.code) || null;
			const message = (data && typeof data === 'object' && data.message) ? String(data.message) : 'REQUEST_FAILED';
			const err = new Error(code || message);
			err.status = response.status;
			err.payload = data;
			err.code = code;
			throw err;
		}
		return data;
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

	window.DutyCheckApi = { request, get, post, put, del, buildUrl };
})();
