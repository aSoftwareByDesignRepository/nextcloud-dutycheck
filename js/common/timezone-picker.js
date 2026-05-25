(function () {
	'use strict';

	const Api = window.DutyCheckApi;
	const Msg = window.DutyCheckMessaging;

	/** @type {{ pinned: string[], groups: {label:string, items:string[]}[] } | null} */
	let catalogCache = null;

	/** @type {Map<string, { value: string, label: string, group: string }> | null} */
	let optionIndex = null;

	const MAX_VISIBLE = 80;

	async function loadCatalog() {
		if (catalogCache) {
			return catalogCache;
		}
		const response = await Api.get('/apps/dutycheck/api/catalog/timezones');
		catalogCache = response?.data || { pinned: [], groups: [] };
		buildIndex(catalogCache);
		return catalogCache;
	}

	function buildIndex(catalog) {
		optionIndex = new Map();
		const seen = new Set();
		const add = (value, group) => {
			if (!value || seen.has(value)) {
				return;
			}
			seen.add(value);
			optionIndex.set(value, {
				value,
				label: formatDisplayLabel(value),
				group,
			});
		};
		(catalog.pinned || []).forEach((tz) => add(tz, t('dutycheck', 'Common choices')));
		(catalog.groups || []).forEach((g) => {
			const group = String(g.label || '');
			(g.items || []).forEach((tz) => add(String(tz), group));
		});
	}

	function formatOffset(tz, when) {
		try {
			const parts = new Intl.DateTimeFormat('en', {
				timeZone: tz,
				timeZoneName: 'shortOffset',
			}).formatToParts(when || new Date());
			const hit = parts.find((p) => p.type === 'timeZoneName');
			return hit ? hit.value : '';
		} catch (_) {
			return '';
		}
	}

	function formatDisplayLabel(tz) {
		const offset = formatOffset(tz);
		return offset ? `${tz} (${offset})` : tz;
	}

	function normalizeQuery(q) {
		return String(q || '').trim().toLowerCase();
	}

	function matchesQuery(item, query) {
		if (!query) {
			return true;
		}
		const hay = `${item.value} ${item.label} ${item.group}`.toLowerCase();
		return hay.includes(query);
	}

	function orderedEntries() {
		if (!catalogCache || !optionIndex) {
			return [];
		}
		const pinnedLabel = t('dutycheck', 'Common choices');
		const out = [];
		(catalogCache.pinned || []).forEach((tz) => {
			const item = optionIndex.get(String(tz));
			if (item) {
				out.push(item);
			}
		});
		(catalogCache.groups || []).forEach((g) => {
			(g.items || []).forEach((tz) => {
				const item = optionIndex.get(String(tz));
				if (item && item.group !== pinnedLabel) {
					out.push(item);
				}
			});
		});
		return out;
	}

	function collectOptions(query) {
		const q = normalizeQuery(query);
		const pinnedLabel = t('dutycheck', 'Common choices');
		if (!q) {
			return orderedEntries().filter((item) => item.group === pinnedLabel);
		}
		const out = [];
		for (const item of orderedEntries()) {
			if (matchesQuery(item, q)) {
				out.push(item);
			}
			if (out.length >= MAX_VISIBLE) {
				break;
			}
		}
		return out;
	}

	function ensureNativeOption(select, item) {
		let opt = Array.from(select.options).find((o) => o.value === item.value);
		if (!opt) {
			opt = document.createElement('option');
			opt.value = item.value;
			opt.textContent = item.label;
			select.appendChild(opt);
		}
		return opt;
	}

	/**
	 * @param {HTMLElement} root
	 * @param {{ defaultTimezone?: string }} [config]
	 * @returns {Promise<{ setValue: (tz: string) => void, getValue: () => string, reset: () => void }|null>}
	 */
	async function attach(root, config) {
		if (!root) {
			return null;
		}
		const select = root.querySelector('.dc-timezone-picker__native');
		const input = root.querySelector('.dc-timezone-picker__input');
		const results = root.querySelector('.dc-timezone-picker__results');
		const status = root.querySelector('.dc-timezone-picker__status');
		const clearBtn = root.querySelector('.dc-timezone-picker__clear');
		if (!select || !input || !results) {
			return null;
		}

		const defaultTimezone = String(
			config?.defaultTimezone
			|| root.getAttribute('data-default-timezone')
			|| 'UTC',
		).trim() || 'UTC';

		try {
			await loadCatalog();
		} catch (err) {
			Msg.handleApiError(err);
			input.disabled = true;
			setStatus(status, t('dutycheck', 'Could not load timezones. Reload the page or contact an administrator.'), true);
			return null;
		}

		let activeIndex = -1;
		let visibleItems = [];

		function setStatusEl(message, isError) {
			setStatus(status, message, isError);
		}

		function closeResults() {
			results.hidden = true;
			results.replaceChildren();
			visibleItems = [];
			activeIndex = -1;
			input.setAttribute('aria-expanded', 'false');
			input.removeAttribute('aria-activedescendant');
		}

		function updateClearButton() {
			if (!clearBtn) {
				return;
			}
			const isRequired = select.hasAttribute('required');
			const has = select.value !== '';
			clearBtn.hidden = isRequired || !has;
		}

		function applySelection(item, dispatchChange) {
			if (!item) {
				select.value = '';
				input.value = '';
			} else {
				ensureNativeOption(select, item);
				select.value = item.value;
				input.value = item.label;
			}
			closeResults();
			setStatusEl('');
			updateClearButton();
			if (dispatchChange !== false) {
				select.dispatchEvent(new Event('change', { bubbles: true }));
			}
		}

		function renderResults(query) {
			visibleItems = collectOptions(query);
			results.replaceChildren();
			activeIndex = -1;
			if (!visibleItems.length) {
				const empty = document.createElement('li');
				empty.className = 'dc-timezone-picker__empty';
				empty.setAttribute('role', 'presentation');
				empty.textContent = normalizeQuery(query)
					? t('dutycheck', 'No matching timezones. Try a city or region name.')
					: t('dutycheck', 'Type to search all IANA timezones.');
				results.appendChild(empty);
				results.hidden = false;
				input.setAttribute('aria-expanded', 'true');
				return;
			}

			let lastGroup = null;
			visibleItems.forEach((item, index) => {
				if (item.group && item.group !== lastGroup) {
					lastGroup = item.group;
					const heading = document.createElement('li');
					heading.className = 'dc-timezone-picker__group';
					heading.setAttribute('role', 'presentation');
					heading.textContent = item.group;
					results.appendChild(heading);
				}
				const li = document.createElement('li');
				li.className = 'dc-timezone-picker__option';
				li.id = `dc-tz-opt-${index}`;
				li.setAttribute('role', 'option');
				li.setAttribute('aria-selected', 'false');
				li.tabIndex = -1;
				const primary = document.createElement('span');
				primary.className = 'dc-timezone-picker__option-id';
				primary.textContent = item.value;
				const secondary = document.createElement('span');
				secondary.className = 'dc-timezone-picker__option-offset';
				secondary.textContent = formatOffset(item.value);
				li.appendChild(primary);
				if (secondary.textContent) {
					li.appendChild(secondary);
				}
				li.addEventListener('mousedown', (event) => {
					event.preventDefault();
					applySelection(item);
				});
				results.appendChild(li);
			});

			const truncated = normalizeQuery(query)
				&& optionIndex
				&& visibleItems.length >= MAX_VISIBLE;
			if (truncated) {
				setStatusEl(t('dutycheck', 'Showing the first {count} matches. Keep typing to narrow the list.').replace('{count}', String(MAX_VISIBLE)));
			} else {
				setStatusEl('');
			}

			results.hidden = false;
			input.setAttribute('aria-expanded', 'true');
		}

		function optionElements() {
			return Array.from(results.querySelectorAll('.dc-timezone-picker__option'));
		}

		function highlightIndex(next) {
			const opts = optionElements();
			if (!opts.length) {
				return;
			}
			activeIndex = Math.max(0, Math.min(next, opts.length - 1));
			opts.forEach((el, i) => {
				const on = i === activeIndex;
				el.setAttribute('aria-selected', on ? 'true' : 'false');
				if (on) {
					input.setAttribute('aria-activedescendant', el.id);
					el.scrollIntoView({ block: 'nearest' });
				}
			});
		}

		function setValue(tz) {
			const value = String(tz || '').trim();
			if (!value) {
				applySelection(null, false);
				return;
			}
			const indexed = optionIndex?.get(value);
			if (indexed) {
				applySelection(indexed, false);
				return;
			}
			const fallback = { value, label: formatDisplayLabel(value), group: '' };
			applySelection(fallback, false);
		}

		function reset() {
			setValue(defaultTimezone);
		}

		input.addEventListener('focus', () => {
			renderResults(input.value);
		});

		input.addEventListener('input', () => {
			renderResults(input.value);
		});

		input.addEventListener('keydown', (event) => {
			if (event.key === 'Escape') {
				closeResults();
				setStatusEl('');
				return;
			}
			if (event.key === 'ArrowDown') {
				event.preventDefault();
				if (results.hidden) {
					renderResults(input.value);
				}
				highlightIndex(activeIndex + 1);
				return;
			}
			if (event.key === 'ArrowUp') {
				event.preventDefault();
				if (results.hidden) {
					renderResults(input.value);
				}
				highlightIndex(activeIndex <= 0 ? 0 : activeIndex - 1);
				return;
			}
			if (event.key === 'Enter') {
				if (!results.hidden && activeIndex >= 0) {
					event.preventDefault();
					const item = visibleItems[activeIndex];
					if (item) {
						applySelection(item);
					}
				}
			}
		});

		input.addEventListener('blur', () => {
			window.setTimeout(() => {
				if (!root.contains(document.activeElement)) {
					closeResults();
					if (select.value) {
						const item = optionIndex?.get(select.value);
						if (item) {
							input.value = item.label;
						}
					}
				}
			}, 160);
		});

		clearBtn?.addEventListener('click', () => {
			applySelection(null);
			input.focus();
		});

		document.addEventListener('click', (event) => {
			if (!root.contains(event.target)) {
				closeResults();
			}
		});

		reset();

		return {
			setValue,
			getValue: () => String(select.value || '').trim(),
			reset,
		};
	}

	function setStatus(el, message, isError) {
		if (!el) {
			return;
		}
		const text = message || '';
		el.textContent = text;
		el.hidden = text === '';
		el.classList.toggle('dc-timezone-picker__status--error', Boolean(isError));
	}

	window.DutyCheckTimezonePicker = {
		attach,
		loadCatalog,
		formatDisplayLabel,
	};
})();
