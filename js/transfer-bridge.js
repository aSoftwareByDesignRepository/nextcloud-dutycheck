/**
 * Bridge for the shared transfer page (apps/_shared/config-transfer):
 * extends window.DutyCheckApi with the .url/.get/.post/.upload contract
 * the synced js/transfer.js expects — the app's own api.js returns the
 * {ok,data} envelope while the transfer controller answers {success,data}
 * and {success:false,error:'CODE',context}. All other DutyCheckApi
 * methods (put/del/request/isAborted/…) pass through untouched.
 * App-specific — not synced. Loaded after common/api.js, before transfer.js,
 * on the Transfer settings section only.
 */
(function () {
	'use strict';
	const base = window.DutyCheckApi || {};
	function requestToken() {
		return (window.OC && OC.requestToken) || '';
	}
	async function parse(resp) {
		const j = await resp.json().catch(() => null);
		if (!resp.ok || (j && (j.ok === false || j.success === false))) {
			const errObj = j && j.error;
			const ctx = (j && (j.context !== undefined ? j.context : j.data)) || {};
			throw {
				code: (errObj && (errObj.code || errObj)) || 'SERVER_ERROR',
				context: (ctx && typeof ctx === 'object') ? ctx : {},
			};
		}
		return j && Object.prototype.hasOwnProperty.call(j, 'data') ? j.data : j;
	}
	function url(path) {
		if (typeof path === 'string' && path.charAt(0) === '/' && path.indexOf('/apps/') !== 0) {
			path = '/apps/dutycheck' + path;
		}
		if (base && typeof base.url === 'function') {
			return base.url(path);
		}
		if (base && typeof base.resolveAppPath === 'function') {
			return base.resolveAppPath(path);
		}
		return (window.OC && OC.generateUrl) ? OC.generateUrl(path) : path;
	}
	window.DutyCheckApi = Object.assign({}, base, {
		url,
		async get(path, params) {
			let u = url(path);
			if (params) {
				const q = Object.keys(params)
					.filter((k) => params[k] !== undefined && params[k] !== null)
					.map((k) => encodeURIComponent(k) + '=' + encodeURIComponent(params[k]))
					.join('&');
				if (q) u += '?' + q;
			}
			return parse(await fetch(u, { credentials: 'same-origin' }));
		},
		async post(path, body) {
			return parse(await fetch(url(path), {
				method: 'POST', credentials: 'same-origin',
				headers: { 'Content-Type': 'application/json', requesttoken: requestToken() },
				body: JSON.stringify(body || {}),
			}));
		},
		async upload(path, file, extra) {
			const fd = new FormData();
			fd.append('file', file);
			if (extra) {
				Object.keys(extra).forEach((k) => fd.append(k, extra[k]));
			}
			return parse(await fetch(url(path), {
				method: 'POST', credentials: 'same-origin',
				headers: { requesttoken: requestToken() },
				body: fd,
			}));
		},
	});
	// Messaging: common/messaging.js exposes announce/toast/handleApiError —
	// the synced transfer.js asks for .alert/.polite; map both to announce.
	const msg = window.DutyCheckMessaging || {};
	if (typeof msg.alert !== 'function') {
		msg.alert = function (text) {
			if (typeof msg.announce === 'function') { msg.announce(text, 'error'); return; }
			if (typeof msg.toast === 'function') { msg.toast(text, 'error'); }
		};
	}
	if (typeof msg.polite !== 'function') {
		msg.polite = function (text) {
			if (typeof msg.announce === 'function') { msg.announce(text, 'info'); return; }
			if (typeof msg.toast === 'function') { msg.toast(text, 'info'); }
		};
	}
	window.DutyCheckMessaging = msg;
})();
