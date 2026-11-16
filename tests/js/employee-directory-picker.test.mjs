/**
 * Behavioural tests for the employees-page linked-account picker.
 *
 * Pins the 2026-11-16 customer report (Tagestreff Winter, 0.3.14): a directory
 * search that returned zero users rendered nothing at all — no "searching",
 * no "no matches", no hint that an unlinked employee is the fallback — so two
 * of four accounts appeared to fail silently.
 *
 * Run: node --test tests/js/employee-directory-picker.test.mjs
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';

const root = path.join(path.dirname(fileURLToPath(import.meta.url)), '../..');
const read = (rel) => fs.readFileSync(path.join(root, rel), 'utf8');

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

function makeEl(tag) {
	const el = {
		tagName: String(tag).toUpperCase(),
		children: [],
		_listeners: {},
		_attrs: {},
		dataset: {},
		style: {},
		className: '',
		textContent: '',
		value: '',
		hidden: false,
		disabled: false,
		isConnected: true,
		appendChild(c) { this.children.push(c); c.parentNode = this; return c; },
		replaceChildren(...nodes) {
			this.children = [...nodes];
			for (const n of nodes) n.parentNode = this;
		},
		setAttribute(k, v) { this._attrs[k] = String(v); },
		getAttribute(k) { return this._attrs[k] ?? null; },
		removeAttribute(k) { delete this._attrs[k]; },
		addEventListener(type, fn) { (this._listeners[type] ||= []).push(fn); },
		dispatchEvent(ev) {
			for (const fn of this._listeners[ev.type] || []) fn(ev);
			return !ev.defaultPrevented;
		},
		click() {
			for (const fn of this._listeners.click || []) fn({ type: 'click', target: this, preventDefault() {} });
		},
		focus() {},
		select() {},
		scrollIntoView() {},
		remove() {},
		matches(sel) { return matchSelector(this, sel); },
		querySelector(sel) { return findIn(this, sel); },
		querySelectorAll(sel) { return findAllIn(this, sel); },
		contains: () => true,
	};
	return el;
}

function matchSelector(el, sel) {
	const m = sel.match(/^\[role="([a-z]+)"\](?::not\(\[aria-disabled="true"\]\))?$/);
	if (m) {
		if (el._attrs.role !== m[1]) return false;
		if (m[2] !== undefined && el._attrs['aria-disabled'] === 'true') return false;
		return true;
	}
	return false;
}

function findAllIn(rootEl, sel) {
	const out = [];
	const walk = (node) => {
		for (const c of node.children || []) {
			if (matchSelector(c, sel)) out.push(c);
			walk(c);
		}
	};
	walk(rootEl);
	return out;
}

function findIn(rootEl, sel) {
	return findAllIn(rootEl, sel)[0] ?? null;
}

/** Minimal mirror of js/common/components.js createElement(). */
function createElement(tag, props, children) {
	const el = makeEl(tag);
	if (props) {
		for (const [key, value] of Object.entries(props)) {
			if (value === undefined || value === null) continue;
			if (key === 'class' || key === 'className') { el.className = String(value); continue; }
			if (key === 'dataset') { Object.entries(value).forEach(([dk, dv]) => { el.dataset[dk] = String(dv); }); continue; }
			if (key === 'on') { Object.entries(value).forEach(([ev, fn]) => el.addEventListener(ev, fn)); continue; }
			if (key === 'attrs') {
				Object.entries(value).forEach(([ak, av]) => {
					if (av === null || av === undefined || av === false) { el.removeAttribute(ak); return; }
					el.setAttribute(ak, av === true ? '' : String(av));
				});
				continue;
			}
			if (key === 'text') { el.textContent = String(value); continue; }
			el[key] = value;
		}
	}
	for (const c of children || []) el.appendChild(c);
	return el;
}

function boot(respond) {
	const els = {};
	const elementIds = [
		'dc-employees-table-body',
		'dc-employees-table-wrap',
		'dc-employees-table-status',
		'dc-employee-search',
		'dc-employee-search-results',
		'dc-employee-linked-chips',
		'dc-employee-linked-user',
		'dc-employee-name',
		'dc-employee-form',
		'dc-employee-form-reset',
	];
	for (const id of elementIds) els[id] = makeEl('div');
	els['dc-employee-form'].elements = {
		namedItem: (n) => (n === 'displayName' ? els['dc-employee-name'] : null),
	};
	els['dc-employee-form'].displayName = els['dc-employee-name'];
	els['dc-employee-form'].dataset = {};

	const apiCalls = [];
	const window = {
		OC: { requestToken: 't', generateUrl: (p) => p },
		DutyCheckApi: {
			get: async (path, params) => {
				apiCalls.push({ path, params });
				if (path === '/apps/dutycheck/api/employees') {
					return { ok: true, data: [] };
				}
				if (path === '/apps/dutycheck/api/admin/users') {
					return respond(params?.q ?? '');
				}
				throw new Error(`unexpected GET ${path}`);
			},
			post: async () => { throw new Error('unexpected POST'); },
		},
		DutyCheckMessaging: { announce() {}, handleApiError() {} },
		DutyCheckComponents: {
			createElement,
			setLoadingRow() {},
			clearLoadingRow() {},
			renderTableFetchError() {},
			confirmDialog: async () => true,
		},
		setTimeout,
		clearTimeout,
		addEventListener() {},
		location: {},
	};
	const document = {
		_domReady: null,
		body: makeEl('body'),
		addEventListener(type, fn) { if (type === 'DOMContentLoaded') this._domReady = fn; },
		getElementById: (id) => els[id] ?? null,
		createElement: (tag) => makeEl(tag),
		querySelector: () => null,
		querySelectorAll: () => [],
	};
	const context = {
		window,
		document,
		navigator: { language: 'en' },
		setTimeout,
		clearTimeout,
		console,
		FormData: class {},
		t: (_app, msg) => String(msg),
	};
	context.globalThis = context;
	vm.runInNewContext(read('js/employees.js'), context, { filename: 'employees.js' });

	const type = (value) => {
		els['dc-employee-search'].value = value;
		els['dc-employee-search'].dispatchEvent({ type: 'input' });
	};
	const pressEnter = () => {
		const ev = { type: 'keydown', key: 'Enter', defaultPrevented: false, preventDefault() { this.defaultPrevented = true; } };
		els['dc-employee-search'].dispatchEvent(ev);
		return ev;
	};
	const resultLis = () => els['dc-employee-search-results'].children;
	const emptyText = () => (resultLis().find((li) => li.className === 'dc-entity-results__empty') || {}).textContent;

	return { els, apiCalls, ready: document._domReady(), type, pressEnter, resultLis, emptyText };
}

test('empty search result renders an explanatory empty state instead of silence', async () => {
	const { ready, type, emptyText } = boot(() => ({ ok: true, users: [], truncated: false }));
	await ready;
	type('schmidt');
	await sleep(300);
	assert.match(emptyText() ?? '', /No matching user found/);
	assert.match(emptyText() ?? '', /display name below/);
});

test('sub-2-character input shows the minimum-length hint', async () => {
	const { ready, type, emptyText } = boot(() => ({ ok: true, users: [] }));
	await ready;
	type('s');
	assert.match(emptyText() ?? '', /Type at least 2 characters/);
});

test('an in-flight search shows a searching indicator', async () => {
	let resolveIt;
	const { ready, type, emptyText } = boot(() => new Promise((r) => { resolveIt = r; }));
	await ready;
	type('mu');
	assert.match(emptyText() ?? '', /Searching/);
	await sleep(300);
	assert.match(emptyText() ?? '', /Searching/, 'still shown while the request is pending');
	resolveIt({ ok: true, users: [] });
	await sleep(50);
	assert.match(emptyText() ?? '', /No matching user found/);
});

test('truncated flag adds a keep-typing hint after the options', async () => {
	const { ready, type, resultLis } = boot(() => ({
		ok: true,
		truncated: true,
		users: [{ id: 'p.schmidt', displayName: 'Petra Schmidt', enabled: true }],
	}));
	await ready;
	type('schmidt');
	await sleep(300);
	const lis = resultLis();
	assert.equal(lis.length, 2);
	assert.equal(lis[0]._attrs.role, 'option');
	assert.match(lis[1].textContent, /first 1 matches/);
});

test('Enter picks the first rendered option and links the account', async () => {
	const { ready, type, pressEnter, els, resultLis } = boot(() => ({
		ok: true,
		users: [{ id: 'p.schmidt', displayName: 'Petra Schmidt', enabled: true }],
	}));
	await ready;
	type('schmidt');
	await sleep(300);
	const ev = pressEnter();
	assert.equal(ev.defaultPrevented, true, 'Enter must never submit the employee form');
	assert.equal(els['dc-employee-linked-user'].value, 'p.schmidt');
	assert.equal(els['dc-employee-name'].value, 'Petra Schmidt');
	assert.equal(resultLis().length, 0, 'results list must clear after a pick, not render the empty state');
});

test('a stale earlier response cannot overwrite fresher results', async () => {
	const pending = new Map();
	const { ready, type, resultLis } = boot((q) => new Promise((resolve) => pending.set(q, resolve)));
	await ready;
	type('mu');
	await sleep(300); // first fetch is now in flight
	type('mue');
	await sleep(300); // second fetch joins it
	assert.equal(pending.size, 2);
	pending.get('mue')({ ok: true, users: [{ id: 'mueller', displayName: 'Marta Mueller', enabled: true }] });
	await sleep(50);
	pending.get('mu')({ ok: true, users: [{ id: 'mustermann', displayName: 'Max Mustermann', enabled: true }] });
	await sleep(50);
	const options = resultLis().filter((li) => li._attrs.role === 'option');
	assert.equal(options.length, 1);
	assert.match(options[0].children[1].textContent, /mueller/);
});
