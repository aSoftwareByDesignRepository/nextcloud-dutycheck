(function () {
	'use strict';

	/**
	 * Accessible primitives shared across DutyCheck pages.
	 *
	 * - createElement(tag, props, children): tiny DOM helper with attribute/data
	 *   handling, never sets innerHTML.
	 * - openModal({ title, render, primary, onSubmit, onCancel }): focus-trapped
	 *   dialog with a labelled title, Escape closes, click-on-backdrop cancels,
	 *   focus is restored to the trigger.
	 * - confirmDialog({ title, body, danger }): boolean Promise convenience.
	 * - promptReason({ title, hint }): textual prompt that returns the trimmed
	 *   string or null when cancelled. Used for absence transitions and any
	 *   workflow that records a free-text justification.
	 * - openSelectFromCatalog: build a single-select picker from a catalog
	 *   shape `[{ id, name, ... }]`, returns the selected entry or null.
	 *
	 * All modals enforce 4.5:1 contrast via the `.dc-modal` styles, ship with
	 * an `aria-modal=true` dialog and an `aria-labelledby` heading id, and
	 * expose `lang`/`data-locale` so number/date inputs format correctly.
	 */

	function createElement(tag, props, children) {
		const el = document.createElement(tag);
		if (props) {
			Object.entries(props).forEach(([key, value]) => {
				if (value === undefined || value === null) return;
				if (key === 'class' || key === 'className') {
					el.className = String(value);
					return;
				}
				if (key === 'dataset') {
					Object.entries(value).forEach(([dk, dv]) => { el.dataset[dk] = String(dv); });
					return;
				}
				if (key === 'on') {
					Object.entries(value).forEach(([eventName, handler]) => el.addEventListener(eventName, handler));
					return;
				}
				if (key === 'attrs') {
					Object.entries(value).forEach(([ak, av]) => {
						if (av === null || av === undefined || av === false) {
							el.removeAttribute(ak);
							return;
						}
						if (av === true) {
							el.setAttribute(ak, '');
							return;
						}
						el.setAttribute(ak, String(av));
					});
					return;
				}
				if (key === 'text') {
					el.textContent = String(value);
					return;
				}
				if (key in el && typeof el[key] !== 'object') {
					try { el[key] = value; return; } catch (_) { /* fall back to attribute */ }
				}
				el.setAttribute(key, String(value));
			});
		}
		if (children !== undefined && children !== null) {
			(Array.isArray(children) ? children : [children]).forEach((child) => {
				if (child === null || child === undefined || child === false) return;
				if (typeof child === 'string' || typeof child === 'number') {
					el.appendChild(document.createTextNode(String(child)));
				} else {
					el.appendChild(child);
				}
			});
		}
		return el;
	}

	function focusables(root) {
		return Array.from(root.querySelectorAll(
			'a[href], area[href], input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), button:not([disabled]), iframe, object, embed, [tabindex]:not([tabindex="-1"]), [contenteditable]'
		)).filter((node) => node.offsetParent !== null || node === document.activeElement);
	}

	let openInstance = null;

	function openModal(options) {
		const opts = Object.assign({
			title: '',
			render: () => createElement('div'),
			primaryLabel: t('dutycheck', 'Save'),
			cancelLabel: t('dutycheck', 'Cancel'),
			showCancel: true,
			danger: false,
			dialogClass: '',
			onSubmit: null,
			onCancel: null,
		}, options || {});

		if (openInstance) {
			openInstance.close(false);
		}
		const previousFocus = document.activeElement;

		const labelId = 'dc-modal-title-' + Math.random().toString(36).slice(2);
		const dialog = createElement('div', {
			class: ('dc-modal__dialog ' + String(opts.dialogClass || '')).trim(),
			attrs: { role: 'dialog', 'aria-modal': 'true', 'aria-labelledby': labelId },
		});
		const header = createElement('div', { class: 'dc-modal__header' }, [
			createElement('h2', { id: labelId, text: opts.title }),
			createElement('button', {
				type: 'button',
				class: 'dc-modal__close',
				attrs: { 'aria-label': t('dutycheck', 'Close') },
				text: '\u2715',
				on: { click: () => instance.close(false) },
			}),
		]);
		const bodyContainer = createElement('div', { class: 'dc-modal__body' });
		const userBody = opts.render({ close: (result) => instance.close(result) });
		if (userBody) bodyContainer.appendChild(userBody);

		const submitContext = () => ({
			close: (r) => instance.close(r),
			body: userBody || null,
			dialog,
		});

		const appLang = document.getElementById('app-content')?.getAttribute('lang')
			|| document.documentElement.getAttribute('lang');
		if (appLang) {
			dialog.setAttribute('lang', appLang);
		}
		if (window.DutyCheckDates && typeof window.DutyCheckDates.applyLocaleToTemporalInputs === 'function') {
			window.DutyCheckDates.applyLocaleToTemporalInputs(dialog);
		}

		const actionChildren = [];
		if (opts.showCancel) {
			const cancelBtn = createElement('button', {
				type: 'button',
				class: 'button',
				text: opts.cancelLabel,
				on: { click: () => instance.close(false) },
			});
			actionChildren.push(cancelBtn);
		}
		const primaryBtn = createElement('button', {
			type: 'button',
			class: opts.danger ? 'button danger primary' : 'button primary',
			text: opts.primaryLabel,
			on: {
				click: async () => {
					if (typeof opts.onSubmit !== 'function') {
						instance.close(true);
						return;
					}
					try {
						primaryBtn.disabled = true;
						const result = await opts.onSubmit(submitContext());
						if (result !== false) instance.close(true);
					} catch (err) {
						if (window.DutyCheckMessaging) {
							window.DutyCheckMessaging.handleApiError(err, { reloadOnConflict: false });
						}
					} finally {
						primaryBtn.disabled = false;
					}
				},
			},
		});
		actionChildren.push(primaryBtn);
		const actions = createElement('div', { class: 'dc-modal__actions dc-form-actions' }, actionChildren);
		dialog.appendChild(header);
		dialog.appendChild(bodyContainer);
		dialog.appendChild(actions);

		const overlay = createElement('div', {
			class: 'dc-modal',
			on: {
				click: (event) => { if (event.target === overlay) instance.close(false); },
			},
		}, [dialog]);

		document.body.appendChild(overlay);
		document.body.classList.add('dc-modal-open');

		const onKey = (event) => {
			if (event.key === 'Escape') {
				event.preventDefault();
				instance.close(false);
				return;
			}
			if (event.key !== 'Tab') return;
			const list = focusables(dialog);
			if (list.length === 0) {
				event.preventDefault();
				return;
			}
			const first = list[0];
			const last = list[list.length - 1];
			if (event.shiftKey && document.activeElement === first) {
				event.preventDefault();
				last.focus();
			} else if (!event.shiftKey && document.activeElement === last) {
				event.preventDefault();
				first.focus();
			}
		};
		dialog.addEventListener('keydown', onKey);

		const instance = {
			dialog,
			overlay,
			primaryBtn,
			close(result) {
				if (!instance._open) return;
				instance._open = false;
				dialog.removeEventListener('keydown', onKey);
				if (overlay.parentNode) overlay.parentNode.removeChild(overlay);
				document.body.classList.remove('dc-modal-open');
				openInstance = null;
				if (previousFocus && typeof previousFocus.focus === 'function') {
					try { previousFocus.focus(); } catch (_) { /* element may be gone */ }
				}
				if (typeof opts.resolve === 'function') opts.resolve(result);
				if (result === false && typeof opts.onCancel === 'function') opts.onCancel();
			},
		};
		instance._open = true;
		openInstance = instance;

		const firstField = focusables(dialog)[0] || primaryBtn;
		firstField.focus();
		return instance;
	}

	function confirmDialog(options) {
		const opts = Object.assign({
			title: t('dutycheck', 'Are you sure?'),
			body: '',
			confirmLabel: t('dutycheck', 'Confirm'),
			cancelLabel: t('dutycheck', 'Cancel'),
			danger: false,
		}, options || {});
		return new Promise((resolve) => {
			openModal({
				title: opts.title,
				primaryLabel: opts.confirmLabel,
				cancelLabel: opts.cancelLabel,
				danger: opts.danger,
				render: () => createElement('p', { text: opts.body }),
				onSubmit: () => true,
				onCancel: () => resolve(false),
				resolve,
			});
		});
	}

	function promptReason(options) {
		const raw = options || {};
		const minLength = Number(raw.minLength) > 0 ? Number(raw.minLength) : 0;
		const opts = Object.assign({
			title: t('dutycheck', 'Provide a reason'),
			hint: raw.label || t('dutycheck', 'A short note for the audit log (optional).'),
			placeholder: '',
			confirmLabel: t('dutycheck', 'Confirm'),
			cancelLabel: t('dutycheck', 'Cancel'),
			required: minLength > 0,
			maxLength: 500,
			minLength,
		}, raw);
		return new Promise((resolve) => {
			let textarea;
			let errorEl;
			openModal({
				title: opts.title,
				primaryLabel: opts.confirmLabel,
				cancelLabel: opts.cancelLabel,
				render: () => {
					const id = 'dc-prompt-' + Math.random().toString(36).slice(2);
					textarea = createElement('textarea', {
						id,
						class: 'dc-input',
						rows: 4,
						placeholder: opts.placeholder,
						attrs: { maxlength: String(opts.maxLength) },
					});
					errorEl = createElement('p', {
						class: 'dc-field__error',
						text: '',
						attrs: { hidden: true },
					});
					return createElement('div', { class: 'dc-field' }, [
						createElement('label', { for: id, text: opts.hint, class: 'dc-field__label' }),
						textarea,
						errorEl,
					]);
				},
				onSubmit: () => {
					const value = (textarea?.value || '').trim();
					if (opts.minLength > 0 && value.length < opts.minLength) {
						if (errorEl) {
							errorEl.textContent = t('dutycheck', 'Please enter at least {n} characters.').replace('{n}', String(opts.minLength));
							errorEl.hidden = false;
						}
						textarea?.focus();
						return false;
					}
					if (opts.required && value === '') {
						textarea?.focus();
						return false;
					}
					resolve(value);
					return true;
				},
				onCancel: () => resolve(null),
			});
		});
	}

	function setLoadingRow(tbody, columns, options) {
		if (!tbody) return;
		const opts = Object.assign({ message: t('dutycheck', 'Loading…') }, options || {});
		const tr = createElement('tr', { class: 'dc-table__loading-row' });
		const td = createElement('td', { class: 'dc-loading', text: opts.message });
		td.colSpan = Math.max(1, Number(columns) || 1);
		tr.appendChild(td);
		tbody.replaceChildren(tr);
		const table = tbody.closest('table');
		if (table) table.setAttribute('aria-busy', 'true');
	}

	function clearLoadingRow(tbody) {
		if (!tbody) return;
		const table = tbody.closest('table');
		if (table) table.removeAttribute('aria-busy');
		// Remove skeleton row so failed requests do not leave the table stuck on "Loading…"
		tbody.querySelectorAll('tr.dc-table__loading-row').forEach((tr) => tr.remove());
	}

	/** Replace tbody with one error row (same styling as catalog pages). */
	function renderTableFetchError(tbody, colspan, message) {
		if (!tbody) return;
		tbody.replaceChildren();
		const tr = createElement('tr', { class: 'dc-table__fetch-error-row' });
		const td = createElement('td', { text: message, class: 'dc-table__fetch-error-cell' });
		td.colSpan = Math.max(1, Number(colspan) || 1);
		tr.appendChild(td);
		tbody.appendChild(tr);
	}

	function getAppUrls() {
		const root = document.getElementById('app-content');
		if (!root) return {};
		const raw = root.getAttribute('data-dc-urls') || '{}';
		try { return JSON.parse(raw); } catch (_) { return {}; }
	}

	function getCurrentUid() {
		try {
			if (typeof OC !== 'undefined') {
				if (typeof OC.getCurrentUser === 'function') {
					const u = OC.getCurrentUser();
					if (u && u.uid) return String(u.uid);
				}
				if (OC.currentUser) return String(OC.currentUser);
			}
		} catch (_) { /* ignore */ }
		return 'anon';
	}

	document.addEventListener('click', (event) => {
		const trigger = event.target.closest('[data-dc-link]');
		if (!trigger) return;
		const name = trigger.getAttribute('data-dc-link');
		if (!name) return;
		const urls = getAppUrls();
		const target = urls[name];
		if (!target) return;
		event.preventDefault();
		window.location.assign(target);
	});

	function wireDismissibleHint(button) {
		if (!button || button.dataset.dcHintWired === '1') return;
		button.dataset.dcHintWired = '1';
		const key = button.getAttribute('data-dc-dismiss-hint');
		if (!key) return;
		const card = button.closest('.dc-card');
		if (!card) return;
		if (card.getAttribute('data-dc-hint-suppress') === 'integration') {
			card.hidden = true;
			return;
		}
		const storageKey = 'dc:hint:' + getCurrentUid() + ':' + key;
		let dismissed = false;
		try { dismissed = window.localStorage.getItem(storageKey) === '1'; } catch (_) { /* ignore */ }
		if (dismissed) {
			card.hidden = true;
			return;
		}
		card.hidden = false;
		button.addEventListener('click', () => {
			try { window.localStorage.setItem(storageKey, '1'); } catch (_) { /* ignore */ }
			card.hidden = true;
		});
	}

	function wireAllDismissibleHints(root) {
		const scope = root || document;
		scope.querySelectorAll('[data-dc-dismiss-hint]').forEach(wireDismissibleHint);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', () => wireAllDismissibleHints());
	} else {
		wireAllDismissibleHints();
	}

	window.DutyCheckComponents = {
		createElement,
		openModal,
		confirmDialog,
		promptReason,
		setLoadingRow,
		clearLoadingRow,
		renderTableFetchError,
		wireDismissibleHint,
		wireAllDismissibleHints,
		getAppUrls,
	};
})();
