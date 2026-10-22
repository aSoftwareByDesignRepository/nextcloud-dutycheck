/**
 * date-chrome-repaint — programmatic-write overlay healing contract.
 *
 * Regression net for intake dutycheck-intake-20261022T172140Z: the
 * .dc-date-display overlay is the ONLY visible date text (native input
 * glyphs are color:transparent), and it repainted solely on input/change
 * events. A programmatic `input.value = '…'` or `form.reset()` fires no
 * such event, so the overlay stranded on the placeholder or the previous
 * date — e.g. the post-open-shift form looked empty while filled, and the
 * Today/Team-week prev/next buttons moved the value under a frozen label.
 *
 * The canonical module now (a) shadows the input's `value` accessor so
 * programmatic writes repaint like user edits, (b) wires one `reset`
 * listener per owning form that repaints after the default action, and
 * (c) exports repaintDateChrome/repaintTemporalChrome as the explicit
 * escape hatch. This spec exercises all three through the synced module
 * against a minimal DOM — no jsdom dependency.
 *
 * Run: node --test tests/js/date-chrome-repaint.test.mjs
 */
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, it } from 'node:test';
import vm from 'node:vm';

const root = path.join(path.dirname(fileURLToPath(import.meta.url)), '../..');
const read = (rel) => fs.readFileSync(path.join(root, rel), 'utf8');

/* ---------- minimal DOM ---------- */

function simpleMatch(el, sel) {
	const m = sel.match(/^([a-zA-Z]*)(\.[\w-]+)?(\[[\w-]+="[\w-]+"\])?$/);
	if (!m || (!m[1] && !m[2] && !m[3])) return false;
	const [, tag, cls, attr] = m;
	if (tag && el.tagName !== tag.toUpperCase()) return false;
	if (cls && !el.classSet.has(cls.slice(1))) return false;
	if (attr) {
		const [, name, value] = attr.match(/\[([\w-]+)="([\w-]+)"/);
		if (String(el[name === 'type' ? 'type' : `attr_${name}`] ?? el.attributes[name] ?? '') !== value) {
			return false;
		}
	}
	return true;
}

function deepQueryAll(el, pred, out = []) {
	for (const child of el.children) {
		if (pred(child)) out.push(child);
		deepQueryAll(child, pred, out);
	}
	return out;
}

class El {
	constructor(tag) {
		this.tagName = String(tag).toUpperCase();
		this.nodeType = 1;
		this.children = [];
		this.parentNode = null;
		this.attributes = {};
		this.listeners = {};
		this.classSet = new Set();
		this.textContent = '';
		this.type = '';
		this._value = '';
	}

	get value() { return this._value; }
	set value(v) { this._value = String(v); }

	get className() { return [...this.classSet].join(' '); }
	set className(v) {
		this.classSet = new Set(String(v).split(/\s+/).filter(Boolean));
	}

	get classList() {
		const self = this;
		return {
			add: (c) => self.classSet.add(c),
			remove: (c) => self.classSet.delete(c),
			contains: (c) => self.classSet.has(c),
			toggle: (c, on) => {
				const want = on === undefined ? !self.classSet.has(c) : Boolean(on);
				if (want) self.classSet.add(c); else self.classSet.delete(c);
				return want;
			},
		};
	}

	get form() { return this.closest('form'); }

	setAttribute(name, value) {
		this.attributes[name] = String(value);
		if (name === 'class') this.className = value;
	}

	getAttribute(name) {
		return Object.prototype.hasOwnProperty.call(this.attributes, name)
			? this.attributes[name]
			: null;
	}

	removeAttribute(name) { delete this.attributes[name]; }

	appendChild(child) {
		if (child.parentNode) child.parentNode.children = child.parentNode.children.filter((c) => c !== child);
		child.parentNode = this;
		this.children.push(child);
		return child;
	}

	insertBefore(child, ref) {
		if (child.parentNode) child.parentNode.children = child.parentNode.children.filter((c) => c !== child);
		const idx = this.children.indexOf(ref);
		child.parentNode = this;
		if (idx === -1) this.children.push(child);
		else this.children.splice(idx, 0, child);
		return child;
	}

	matches(sel) { return simpleMatch(this, sel); }

	closest(sel) {
		let node = this;
		while (node) {
			if (simpleMatch(node, sel)) return node;
			node = node.parentNode;
		}
		return null;
	}

	querySelectorAll(sel) {
		if (sel.startsWith(':scope > ')) {
			const inner = sel.slice(9);
			return this.children.filter((c) => simpleMatch(c, inner));
		}
		const preds = sel.split(',').map((s) => (el) => simpleMatch(el, s.trim()));
		return deepQueryAll(this, (el) => preds.some((p) => p(el)));
	}

	querySelector(sel) { return this.querySelectorAll(sel)[0] || null; }

	addEventListener(type, fn) { (this.listeners[type] ||= []).push(fn); }

	dispatch(type) {
		for (const fn of this.listeners[type] || []) fn({ type, target: this, preventDefault() {} });
	}

	focus() {}
	showPicker() {}
}

function makeDom() {
	const byId = new Map();
	const pageRoot = new El('div');
	const register = (el, id) => { if (id) { el.setAttribute('id', id); byId.set(id, el); } return el; };

	const rootAttrs = {
		'data-locale': 'de-DE',
		'data-language': 'de',
		'data-timezone': 'Europe/Berlin',
		'data-first-day-of-week': '1',
		'data-dc-time-24h': '1',
	};
	const appContent = register(new El('div'), 'app-content');
	appContent.getAttribute = (n) => (Object.prototype.hasOwnProperty.call(rootAttrs, n) ? rootAttrs[n] : '');
	pageRoot.appendChild(appContent);

	const document = {
		documentElement: { getAttribute: () => '', lang: 'de' },
		getElementById: (id) => byId.get(id) || null,
		createElement: (tag) => new El(tag),
		createElementNS: (ns, tag) => new El(tag),
		querySelectorAll: (sel) => pageRoot.querySelectorAll(sel),
	};
	return { document, pageRoot, register, byId };
}

function makeInstance(dom) {
	const sandbox = {
		document: dom.document,
		Intl, Date, Number, Math, String, console, navigator: { language: 'de' },
		setTimeout,
		OC: undefined,
	};
	sandbox.window = sandbox;
	vm.runInNewContext(read('js/common/check-dates.js'), sandbox, { filename: 'check-dates.js' });
	return sandbox.CheckDates.create({ prefix: 'dc', naiveMode: 'utc' });
}

function overlayOf(input, prefix = 'dc') {
	const wrap = input.closest('.' + prefix + '-date-field');
	return wrap ? wrap.querySelector(':scope > .' + prefix + '-date-display') : null;
}

const tick = () => new Promise((resolve) => setTimeout(resolve, 5));

/* ---------- tests ---------- */

describe('date chrome programmatic-write healing', () => {
	it('applyLocaleToTemporalInputs mounts the overlay and paints the placeholder', () => {
		const dom = makeDom();
		const form = dom.register(new El('form'), 'f');
		const field = new El('div');
		field.className = 'dc-field';
		const input = dom.register(new El('input'), 'dc-x');
		input.type = 'date';
		field.appendChild(input);
		form.appendChild(field);
		dom.pageRoot.appendChild(form);

		const D = makeInstance(dom);
		D.applyLocaleToTemporalInputs(dom.pageRoot);
		const overlay = overlayOf(input);
		assert.ok(overlay, 'overlay must exist');
		assert.equal(overlay.textContent, 'TT.MM.JJJJ');
		assert.equal(overlay.classSet.has('dc-date-display--empty'), true);
	});

	it('programmatic .value set repaints the overlay without any event', () => {
		const dom = makeDom();
		const input = dom.register(new El('input'), 'dc-os-date');
		input.type = 'date';
		dom.pageRoot.appendChild(input);
		const D = makeInstance(dom);
		D.applyLocaleToTemporalInputs(dom.pageRoot);
		const overlay = overlayOf(input);
		assert.equal(overlay.textContent, 'TT.MM.JJJJ');

		// The reported defect: roster.js:3984 wrote the period start while the
		// overlay kept the placeholder. The setter patch must repaint inline.
		input.value = '2026-10-19';
		assert.equal(overlay.textContent, D.formatDisplayDate('2026-10-19'));
		assert.equal(overlay.classSet.has('dc-date-display--empty'), false);

		input.value = '';
		assert.equal(overlay.textContent, 'TT.MM.JJJJ');
		assert.equal(overlay.classSet.has('dc-date-display--empty'), true);
	});

	it('a second programmatic set repaints the moved value (edit path)', () => {
		const dom = makeDom();
		const input = dom.register(new El('input'), 'dc-assignment-date');
		input.type = 'date';
		dom.pageRoot.appendChild(input);
		const D = makeInstance(dom);
		D.applyLocaleToTemporalInputs(dom.pageRoot);
		input.value = '2026-10-01';
		input.value = '2026-10-15';
		assert.equal(overlayOf(input).textContent, D.formatDisplayDate('2026-10-15'));
	});

	it('form.reset() repaints to the reverted default after the macrotask', async () => {
		const dom = makeDom();
		const form = dom.register(new El('form'), 'f');
		const input = dom.register(new El('input'), 'dc-period-start');
		input.type = 'date';
		form.appendChild(input);
		dom.pageRoot.appendChild(form);
		const D = makeInstance(dom);
		D.applyLocaleToTemporalInputs(dom.pageRoot);
		input.value = '2026-10-20';
		assert.equal(overlayOf(input).textContent, D.formatDisplayDate('2026-10-20'));

		// A real reset reverts internals WITHOUT routing through the JS
		// accessor — mirror that by writing the backing store directly,
		// then dispatch the event the browser fires.
		input._value = '';
		form.dispatch('reset');
		await tick();
		assert.equal(overlayOf(input).textContent, 'TT.MM.JJJJ');
	});

	it('reset rewires once per form — a second reset still repaints', async () => {
		const dom = makeDom();
		const form = dom.register(new El('form'), 'f');
		const a = dom.register(new El('input'), 'a');
		const b = dom.register(new El('input'), 'b');
		a.type = 'date'; b.type = 'date';
		form.appendChild(a); form.appendChild(b);
		dom.pageRoot.appendChild(form);
		const D = makeInstance(dom);
		D.applyLocaleToTemporalInputs(dom.pageRoot);
		a.value = '2026-10-01'; b.value = '2026-10-02';
		a._value = ''; b._value = '';
		form.dispatch('reset');
		await tick();
		assert.equal(overlayOf(a).classSet.has('dc-date-display--empty'), true);
		assert.equal(overlayOf(b).classSet.has('dc-date-display--empty'), true);
	});

	it('repaintDateChrome is the explicit hatch for unpatched writes', () => {
		const dom = makeDom();
		const input = dom.register(new El('input'), 'late');
		input.type = 'date';
		dom.pageRoot.appendChild(input);
		const D = makeInstance(dom);
		D.applyLocaleToTemporalInputs(dom.pageRoot);
		input._value = '2026-11-02'; // write that bypassed the accessor
		assert.equal(overlayOf(input).textContent, 'TT.MM.JJJJ', 'sanity: still stale before repaint');
		D.repaintDateChrome(input);
		assert.equal(overlayOf(input).textContent, D.formatDisplayDate('2026-11-02'));
	});

	it('repaintDateChrome mounts chrome on an input that never saw applyLocale', () => {
		const dom = makeDom();
		const input = dom.register(new El('input'), 'late2');
		input.type = 'date';
		dom.pageRoot.appendChild(input);
		const D = makeInstance(dom);
		input.value = '2026-11-03';
		D.repaintDateChrome(input, 'Choose date');
		const overlay = overlayOf(input);
		assert.ok(overlay, 'chrome must mount on demand');
		assert.equal(overlay.textContent, D.formatDisplayDate('2026-11-03'));
	});

	it('repaintTemporalChrome repaints every chromed input under a root', () => {
		const dom = makeDom();
		const form = dom.register(new El('form'), 'f');
		const a = dom.register(new El('input'), 'a');
		const b = dom.register(new El('input'), 'b');
		a.type = 'date'; b.type = 'date';
		form.appendChild(a); form.appendChild(b);
		dom.pageRoot.appendChild(form);
		const D = makeInstance(dom);
		D.applyLocaleToTemporalInputs(dom.pageRoot);
		a._value = '2026-10-05'; b._value = '2026-10-06';
		D.repaintTemporalChrome(form);
		assert.equal(overlayOf(a).textContent, D.formatDisplayDate('2026-10-05'));
		assert.equal(overlayOf(b).textContent, D.formatDisplayDate('2026-10-06'));
	});

	it('repeated applyLocale keeps the patch single and repaints (locale re-target)', () => {
		const dom = makeDom();
		const input = dom.register(new El('input'), 'multi');
		input.type = 'date';
		dom.pageRoot.appendChild(input);
		const D = makeInstance(dom);
		D.applyLocaleToTemporalInputs(dom.pageRoot);
		D.applyLocaleToTemporalInputs(dom.pageRoot);
		input.value = '2026-12-24';
		assert.equal(overlayOf(input).textContent, D.formatDisplayDate('2026-12-24'));
	});
});

describe('date chrome contract pins', () => {
	it('synced module registers paint, patches the value setter, and wires reset', () => {
		const src = read('js/common/check-dates.js');
		assert.match(src, /dateChromePaints\.set\(input, paint\)/);
		assert.match(src, /patchProgrammaticValueSet\(input\)/);
		assert.match(src, /wireFormResetRepaint\(input\)/);
		assert.match(src, /Object\.defineProperty\(input, 'value'/);
		assert.match(src, /addEventListener\('reset'/);
		assert.match(src, /repaintDateChrome,/);
		assert.match(src, /repaintTemporalChrome,/);
	});

	it('DutyCheckDates adapter exposes the repaint helpers', () => {
		const src = read('js/common/dates.js');
		assert.match(src, /repaintDateChrome/);
		assert.match(src, /repaintTemporalChrome/);
	});

	it('the app no longer relies on event dispatch after programmatic date writes', () => {
		// Every in-scope .value target is a chromed input or covered by a
		// form reset — the shared module heals both. Pin that the reported
		// sites still route through the shared chrome (no bespoke copy).
		const roster = read('js/roster.js');
		assert.doesNotMatch(roster, /\.dc-date-display/);
		assert.doesNotMatch(roster, /new Event\('(input|change)'\)/);
	});
});
