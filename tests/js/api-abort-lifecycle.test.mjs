/**
 * Behavioural unit tests for navigation/abort handling in DutyCheckApi + Messaging.
 *
 * Proves the reported bug: leaving a page while requests are in flight must NOT
 * surface a “Network error” toast. Real AbortError, page unload TypeError, and
 * genuine connectivity failures are classified distinctly.
 *
 * Run: node --test tests/js/api-abort-lifecycle.test.mjs
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';

const root = path.join(path.dirname(fileURLToPath(import.meta.url)), '../..');

function read(rel) {
	return fs.readFileSync(path.join(root, rel), 'utf8');
}

/**
 * Minimal browser harness for the IIFE scripts under Node.
 * @param {{ fetchImpl?: Function }} [opts]
 */
function boot(opts = {}) {
	const listeners = new Map();
	const toasts = [];

	const body = {
		_children: [],
		appendChild(node) {
			node.parentNode = this;
			this._children.push(node);
			return node;
		},
		contains(node) {
			return this._children.includes(node) || node === this;
		},
		removeChild(node) {
			const idx = this._children.indexOf(node);
			if (idx >= 0) this._children.splice(idx, 1);
			if (node) node.parentNode = null;
			return node;
		},
		classList: { add() {}, remove() {} },
	};

	const document = {
		body,
		querySelector() {
			return null;
		},
		querySelectorAll() {
			return [];
		},
		getElementById(id) {
			if (id === 'dc-live-region' || id === 'dc-alert-region') {
				return { textContent: '' };
			}
			return null;
		},
		createElement(tag) {
			const el = {
				tagName: String(tag).toUpperCase(),
				className: '',
				textContent: '',
				type: '',
				children: [],
				style: {},
				parentNode: null,
				setAttribute(k, v) {
					this['data-' + k] = v;
					this[k] = v;
				},
				getAttribute(k) {
					return this[k] ?? null;
				},
				appendChild(child) {
					child.parentNode = this;
					this.children.push(child);
					return child;
				},
				removeChild(child) {
					const idx = this.children.indexOf(child);
					if (idx >= 0) this.children.splice(idx, 1);
					if (child) child.parentNode = null;
					return child;
				},
				remove() {
					if (this.parentNode && typeof this.parentNode.removeChild === 'function') {
						this.parentNode.removeChild(this);
						return;
					}
					const bi = body._children.indexOf(this);
					if (bi >= 0) body._children.splice(bi, 1);
					this.parentNode = null;
				},
				addEventListener() {},
			};
			return el;
		},
	};

	const window = {
		OC: {
			requestToken: 'test-token',
			generateUrl(p) {
				return p;
			},
		},
		addEventListener(type, fn, capture) {
			const key = String(type);
			if (!listeners.has(key)) listeners.set(key, []);
			listeners.get(key).push({ fn, capture: !!capture });
		},
		setTimeout(_fn, _ms) {
			// Do not run dismiss timers synchronously — tests inspect mounted toasts.
			return 1;
		},
		location: { reload() {} },
	};

	function dispatch(type, event = {}) {
		const list = listeners.get(type) || [];
		for (const { fn } of list) {
			fn(event);
		}
	}

	const fetchImpl = opts.fetchImpl || (async () => {
		throw new TypeError('Failed to fetch');
	});

	const context = {
		window,
		document,
		fetch: fetchImpl,
		Headers: globalThis.Headers,
		AbortController: globalThis.AbortController,
		AbortSignal: globalThis.AbortSignal,
		DOMException: globalThis.DOMException,
		URLSearchParams: globalThis.URLSearchParams,
		setTimeout: window.setTimeout,
		clearTimeout() {},
		console,
		t(_app, msg) {
			return msg;
		},
	};
	context.globalThis = context;
	context.self = window;

	vm.runInNewContext(read('js/common/api.js'), context, { filename: 'api.js' });
	vm.runInNewContext(read('js/common/messaging.js'), context, { filename: 'messaging.js' });

	// Capture toasts via announce spy (messaging mutates DOM; assert on messages).
	const originalAnnounce = window.DutyCheckMessaging.announce;
	window.DutyCheckMessaging.announce = (message, kind) => {
		toasts.push({ message: String(message), kind: String(kind || '') });
		return originalAnnounce(message, kind);
	};

	return {
		Api: window.DutyCheckApi,
		Msg: window.DutyCheckMessaging,
		toasts,
		dispatch,
		window: Object.assign(window, { document }),
		document,
	};
}

test('AbortError from fetch is REQUEST_ABORTED, not NETWORK_ERROR', async () => {
	const abortErr = new DOMException('The operation was aborted.', 'AbortError');
	const { Api } = boot({
		fetchImpl: async () => {
			throw abortErr;
		},
	});
	await assert.rejects(
		() => Api.get('/apps/dutycheck/api/dashboard'),
		(err) => {
			assert.equal(err.code, 'REQUEST_ABORTED');
			assert.equal(err.name, 'AbortError');
			assert.equal(err.status, 0);
			assert.ok(Api.isAborted(err));
			return true;
		},
	);
});

test('caller AbortController abort yields REQUEST_ABORTED', async () => {
	const ac = new AbortController();
	const { Api } = boot({
		fetchImpl: (_url, init) => new Promise((_resolve, reject) => {
			init.signal.addEventListener('abort', () => {
				reject(new DOMException('Aborted', 'AbortError'));
			});
		}),
	});
	const pending = Api.get('/apps/dutycheck/api/dashboard', null, { signal: ac.signal });
	ac.abort();
	await assert.rejects(pending, (err) => {
		assert.equal(err.code, 'REQUEST_ABORTED');
		assert.ok(Api.isAborted(err));
		return true;
	});
});

test('page unload TypeError("Failed to fetch") is classified as abort', () => {
	const { Api, dispatch } = boot();
	dispatch('pagehide');
	assert.equal(Api.isPageUnloading(), true);
	const classified = Api._classifyFetchFailure(new TypeError('Failed to fetch'), undefined);
	assert.equal(classified.code, 'REQUEST_ABORTED');
	assert.ok(Api.isAborted(classified));
});

test('genuine connectivity failure stays NETWORK_ERROR while page is live', () => {
	const { Api } = boot();
	assert.equal(Api.isPageUnloading(), false);
	const classified = Api._classifyFetchFailure(new TypeError('Failed to fetch'), undefined);
	assert.equal(classified.code, 'NETWORK_ERROR');
	assert.equal(Api.isAborted(classified), false);
});

function countErrorToasts(document) {
	const container = document.body._children.find(
		(n) => n.id === 'dc-toasts' || String(n.className).includes('dc-toasts'),
	);
	if (!container) return 0;
	return (container.children || []).filter((n) => String(n.className).includes('dc-toast--error')).length;
}

test('handleApiError silences REQUEST_ABORTED and never toasts', () => {
	const { Api, Msg, document } = boot();
	Msg.handleApiError(Api._abortedError(new Error('x')));
	assert.equal(countErrorToasts(document), 0);
});

test('handleApiError silences NETWORK_ERROR whose cause is AbortError (isAborted path)', () => {
	const { Msg, document } = boot();
	const err = new Error('NETWORK_ERROR');
	err.code = 'NETWORK_ERROR';
	err.status = 0;
	err.name = 'Error';
	err.cause = new DOMException('The operation was aborted.', 'AbortError');
	Msg.handleApiError(err);
	assert.equal(countErrorToasts(document), 0);
});

test('handleApiError silences NETWORK_ERROR during page unload', () => {
	const { Api, Msg, document, dispatch } = boot();
	dispatch('pagehide');
	const err = new Error('NETWORK_ERROR');
	err.code = 'NETWORK_ERROR';
	err.status = 0;
	Msg.handleApiError(err);
	assert.equal(countErrorToasts(document), 0);
	assert.equal(Api.isPageUnloading(), true);
});

test('handleApiError still announces real NETWORK_ERROR on a live page', () => {
	const { Msg, document } = boot();
	const err = new Error('NETWORK_ERROR');
	err.code = 'NETWORK_ERROR';
	err.status = 0;
	Msg.handleApiError(err);
	assert.equal(countErrorToasts(document), 1);
	const container = document.body._children.find((n) => n.id === 'dc-toasts' || String(n.className).includes('dc-toasts'));
	const errorToast = (container.children || []).find((n) => String(n.className).includes('dc-toast--error'));
	const text = (errorToast.children || []).map((c) => c.textContent).join(' ');
	assert.match(text, /Network error/i);
});

test('pagehide aborts the shared page signal so in-flight requests cancel cleanly', async () => {
	const { Api, dispatch } = boot({
		fetchImpl: (_url, init) => new Promise((_resolve, reject) => {
			init.signal.addEventListener('abort', () => {
				reject(new DOMException('Aborted', 'AbortError'));
			});
		}),
	});
	const pending = Api.get('/apps/dutycheck/api/roster');
	dispatch('pagehide');
	await assert.rejects(pending, (err) => {
		assert.equal(err.code, 'REQUEST_ABORTED');
		return true;
	});
});

test('pageshow after unload resets lifecycle so new requests work', async () => {
	let calls = 0;
	const { Api, dispatch } = boot({
		fetchImpl: async () => {
			calls += 1;
			return {
				ok: true,
				status: 200,
				redirected: false,
				url: '/apps/dutycheck/api/dashboard',
				headers: { get: () => 'application/json' },
				async json() {
					return { ok: true, data: {} };
				},
			};
		},
	});
	dispatch('pagehide');
	assert.equal(Api.isPageUnloading(), true);
	dispatch('pageshow', { persisted: true });
	assert.equal(Api.isPageUnloading(), false);
	const data = await Api.get('/apps/dutycheck/api/dashboard');
	assert.equal(data.ok, true);
	assert.equal(calls, 1);
});

test('composeAbortSignal merges caller + page signals', () => {
	const { Api, dispatch } = boot();
	const user = new AbortController();
	const composed = Api._composeAbortSignal(user.signal);
	assert.ok(composed);
	assert.equal(composed.aborted, false);
	user.abort();
	assert.equal(composed.aborted, true);
	Api._resetPageLifecycle();
	const user2 = new AbortController();
	const composed2 = Api._composeAbortSignal(user2.signal);
	dispatch('pagehide');
	assert.equal(composed2.aborted, true);
});

test('isAborted recognises nested cause AbortError', () => {
	const { Api } = boot();
	const wrapped = new Error('NETWORK_ERROR');
	wrapped.code = 'NETWORK_ERROR';
	wrapped.cause = new DOMException('Aborted', 'AbortError');
	// Without REQUEST_ABORTED code, isAborted still detects cause.name.
	assert.equal(Api.isAborted(wrapped), true);
});

test('CONFLICT_ACK_STALE warns without reloading the page', () => {
	const { Msg, window, document } = boot();
	let reloads = 0;
	window.location.reload = () => {
		reloads += 1;
	};
	Msg.handleApiError({ code: 'CONFLICT_ACK_STALE', status: 409 });
	assert.equal(reloads, 0);
	const container = document.body._children.find(
		(n) => n.id === 'dc-toasts' || String(n.className).includes('dc-toasts'),
	);
	assert.ok(container);
	const toast = (container.children || [])[0];
	assert.match(String(toast.className), /dc-toast--warning/);
	const text = (toast.children || []).map((c) => String(c.textContent || '')).join(' ');
	assert.match(text, /already confirmed this exception/i);
});

test('COMPANY_MEMBERSHIP_REQUIRED explains the company banner path', () => {
	const { Msg, document } = boot();
	Msg.handleApiError({ code: 'COMPANY_MEMBERSHIP_REQUIRED', status: 403 });
	const container = document.body._children.find(
		(n) => n.id === 'dc-toasts' || String(n.className).includes('dc-toasts'),
	);
	assert.ok(container);
	const toast = (container.children || [])[0];
	assert.match(String(toast.className), /dc-toast--error/);
	const text = (toast.children || []).map((c) => String(c.textContent || '')).join(' ');
	assert.match(text, /Settings → Companies/);
});
