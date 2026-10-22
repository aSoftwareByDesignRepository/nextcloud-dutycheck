/**
 * Behavioural unit tests for the messaging.js 404 ordering fix.
 *
 * Pins the 2026-10-22 customer report: an entity 404 that carries a stable
 * error.code (LOCATION_NOT_FOUND) must announce the mapped friendly message —
 * the generic "update DutyCheck" hint is only for genuinely missing routes.
 *
 * Asserts on the rendered toast DOM because announce() is a private closure.
 *
 * Run: node --test tests/js/messaging-404-ordering.test.mjs
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';

const root = path.join(path.dirname(fileURLToPath(import.meta.url)), '../..');
const read = (rel) => fs.readFileSync(path.join(root, rel), 'utf8');

function boot() {
	const body = {
		_children: [],
		appendChild(node) {
			this._children.push(node);
			return node;
		},
		contains: () => true,
		classList: { add() {}, remove() {} },
	};
	const document = {
		body,
		querySelector: () => null,
		querySelectorAll: () => [],
		getElementById: (id) =>
			(id === 'dc-live-region' || id === 'dc-alert-region') ? { textContent: '' } : null,
		createElement: (tag) => ({
			tagName: String(tag).toUpperCase(),
			className: '',
			textContent: '',
			type: '',
			children: [],
			style: {},
			setAttribute() {},
			appendChild(c) { this.children.push(c); return c; },
			remove() {},
			addEventListener() {},
		}),
	};
	const window = {
		OC: { requestToken: 't', generateUrl: (p) => p },
		addEventListener() {},
		setTimeout: () => 1,
		clearTimeout() {},
		location: { reload() {} },
	};
	const context = {
		window,
		document,
		setTimeout: window.setTimeout,
		clearTimeout() {},
		console,
		t: (_app, msg) => msg,
	};
	context.globalThis = context;
	context.self = window;

	vm.runInNewContext(read('js/common/messaging.js'), context, { filename: 'messaging.js' });

	// announce() writes toast divs into a #dc-toasts container under body.
	const toastTexts = () => {
		const container = body._children.find((el) => el.id === 'dc-toasts');
		return (container?.children || []).map(
			(toast) => (toast.children.find((c) => String(c.className).includes('dc-toast__text')) || {}).textContent,
		);
	};
	return { Msg: window.DutyCheckMessaging, toastTexts };
}

test('entity 404 with LOCATION_NOT_FOUND announces the mapped message', () => {
	const { Msg, toastTexts } = boot();
	Msg.handleApiError({ status: 404, code: 'LOCATION_NOT_FOUND' });
	assert.deepEqual(toastTexts(), ['Location not found or no longer available.']);
});

test('entity 404 with EMPLOYEE_NOT_FOUND announces the mapped message', () => {
	const { Msg, toastTexts } = boot();
	Msg.handleApiError({ status: 404, code: 'EMPLOYEE_NOT_FOUND' });
	assert.deepEqual(toastTexts(), ['Employee not found or no longer available.']);
});

test('bare 404 without a known code keeps the generic action-not-found hint', () => {
	const { Msg, toastTexts } = boot();
	Msg.handleApiError({ status: 404 });
	assert.equal(toastTexts().length, 1);
	assert.match(toastTexts()[0], /could not find this DutyCheck action/);
});

test('404 with unknown code keeps the generic hint (no leak of raw code)', () => {
	const { Msg, toastTexts } = boot();
	Msg.handleApiError({ status: 404, code: 'SOME_FUTURE_CODE' });
	assert.match(toastTexts()[0], /could not find this DutyCheck action/);
});
