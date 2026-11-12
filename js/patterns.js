(function () {
	'use strict';

	const Api = window.DutyCheckApi;
	const Msg = window.DutyCheckMessaging;
	const C = window.DutyCheckComponents || window.DutyCheckDom || {};
	const D = window.DutyCheckDates;
	const create = C.createElement;
	if (typeof create !== 'function') {
		throw new Error('DutyCheck components failed to load');
	}

	const API = '/apps/dutycheck/api/rotation-patterns';
	const DOW = [1, 2, 3, 4, 5, 6, 7];
	const state = {
		patterns: [],
		allowedCycleWeeks: [1, 2, 3, 4, 5, 6, 7, 8],
		enabled: true,
		employees: [],
		locations: [],
		defaultBreakMinutes: 0,
	};
	// Week-day objects currently loaded into the editor grid — readGrid()
	// preserves fields the form does not render (e.g. shiftTemplateId).
	let editorDayMap = new Map();

	function root() {
		return document.getElementById('dc-patterns-page');
	}

	function msg(key, fallback) {
		const el = root();
		return (el && el.getAttribute('data-' + key)) || fallback || '';
	}

	function dowLabel(d) {
		const keys = ['', 'label-mon', 'label-tue', 'label-wed', 'label-thu', 'label-fri', 'label-sat', 'label-sun'];
		return msg(keys[d], String(d));
	}

	function pad2(n) {
		return String(n).padStart(2, '0');
	}

	function isoDateStr(d) {
		return d.getFullYear() + '-' + pad2(d.getMonth() + 1) + '-' + pad2(d.getDate());
	}

	function utcDateStr(d) {
		return d.getUTCFullYear() + '-' + pad2(d.getUTCMonth() + 1) + '-' + pad2(d.getUTCDate());
	}

	function parseDateValue(v) {
		const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(v || '').trim());
		return m ? new Date(Date.UTC(Number(m[1]), Number(m[2]) - 1, Number(m[3]))) : null;
	}

	// ISO-8601: week 1 is the week containing the year's first Thursday.
	function isoWeekNumber(dUtc) {
		const t = new Date(dUtc.getTime());
		const day = t.getUTCDay() || 7;
		t.setUTCDate(t.getUTCDate() + 4 - day);
		const yearStart = Date.UTC(t.getUTCFullYear(), 0, 1);
		return Math.floor((t.getTime() - yearStart) / 604800000) + 1;
	}

	// Monday of ISO week `week` (1..53) in `year`, as a UTC-midnight Date.
	function isoMondayOfWeek(year, week) {
		const jan4 = new Date(Date.UTC(year, 0, 4));
		const dow = jan4.getUTCDay() || 7;
		return new Date(jan4.getTime() + ((1 - dow) + (week - 1) * 7) * 86400000);
	}

	function nextMondayIso() {
		const d = new Date();
		d.setDate(d.getDate() + (((8 - d.getDay()) % 7) || 7));
		return isoDateStr(d);
	}

	// The ISO-week residue the server falls back to when the column is null
	// (RotationAnchorService: odd KW → week 0 for biweekly, else residue 0).
	function defaultIsoResidue(cycleWeeks) {
		return Number(cycleWeeks) === 2 ? 1 : 0;
	}

	function setFieldError(input, errEl, message) {
		if (errEl) {
			errEl.textContent = message;
			errEl.hidden = false;
		}
		if (input) {
			input.setAttribute('aria-invalid', 'true');
			if (errEl && errEl.id) input.setAttribute('aria-describedby', errEl.id);
			input.focus();
		}
	}

	function clearFieldError(input, errEl) {
		if (input) {
			input.removeAttribute('aria-invalid');
			input.removeAttribute('aria-describedby');
		}
		if (errEl) {
			errEl.textContent = '';
			errEl.hidden = true;
		}
	}

	function setStatus(text, kind) {
		const el = document.getElementById('dc-patterns-status');
		if (!el) return;
		el.hidden = !text;
		el.textContent = text || '';
		el.classList.toggle('dc-roster-flash--error', kind === 'error');
	}

	function emptyWeekDays(cycleWeeks) {
		const out = [];
		for (let w = 0; w < cycleWeeks; w++) {
			for (const d of DOW) {
				out.push({
					weekIndex: w, dow: d, isWorking: false, netMinutes: 0,
					startLocal: null, endLocal: null, breakMinutes: 0,
					shiftTemplateId: null, locationId: null,
				});
			}
		}
		return out;
	}

	function dayKey(w, d) {
		return w + ':' + d;
	}

	function indexWeekDays(weekDays) {
		const map = new Map();
		(weekDays || []).forEach((day) => map.set(dayKey(day.weekIndex, day.dow), day));
		return map;
	}

	function buildGrid(container, cycleWeeks, weekDays) {
		container.replaceChildren();
		const map = indexWeekDays(weekDays);
		editorDayMap = map;
		for (let w = 0; w < cycleWeeks; w++) {
			const weekTitle = msg('label-week', 'Week {n}').replace('{n}', String(w + 1));
			const week = create('fieldset', { class: 'dc-patterns__week' }, [
				create('legend', { text: weekTitle }),
			]);
			const table = create('div', {
				class: 'dc-patterns__grid',
				attrs: { role: 'table', 'aria-label': weekTitle },
			});
			DOW.forEach((d) => {
				const day = map.get(dayKey(w, d)) || {
					weekIndex: w, dow: d, isWorking: false, netMinutes: 0,
					startLocal: null, endLocal: null, breakMinutes: 0,
				};
				const idBase = 'dc-pat-' + w + '-' + d;
				const cell = create('div', {
					class: 'dc-patterns__day',
					attrs: { role: 'row', 'data-week': String(w), 'data-dow': String(d) },
				});
				cell.appendChild(create('div', { class: 'dc-patterns__day-head', text: dowLabel(d) }));
				const workInput = create('input', {
					type: 'checkbox',
					id: idBase + '-work',
					class: 'dc-patterns__is-working',
				});
				if (day.isWorking) workInput.checked = true;
				cell.appendChild(create('label', {
					class: 'dc-checkbox dc-patterns__work-toggle',
					attrs: { for: idBase + '-work' },
				}, [
					workInput,
					create('span', { class: 'dc-checkbox__text', text: msg('label-working') }),
				]));
				cell.appendChild(create('label', {
					class: 'dc-field__label', attrs: { for: idBase + '-net' }, text: msg('label-net'),
				}));
				const net = create('input', {
					type: 'number', id: idBase + '-net',
					class: 'dc-input dc-input--num dc-patterns__net',
					attrs: { min: '0', max: '1440', step: '15', value: String(day.netMinutes || 0) },
				});
				net.disabled = !day.isWorking;
				cell.appendChild(net);
				cell.appendChild(create('label', {
					class: 'dc-field__label', attrs: { for: idBase + '-start' }, text: msg('label-start'),
				}));
				const start = create('input', {
					type: 'time', id: idBase + '-start',
					class: 'dc-input dc-input--time-24h dc-patterns__start',
					attrs: {
						step: '60', lang: 'en-GB',
						value: day.startLocal ? String(day.startLocal).slice(0, 5) : '',
					},
				});
				start.disabled = !day.isWorking;
				cell.appendChild(start);
				cell.appendChild(create('label', {
					class: 'dc-field__label', attrs: { for: idBase + '-end' }, text: msg('label-end'),
				}));
				const end = create('input', {
					type: 'time', id: idBase + '-end',
					class: 'dc-input dc-input--time-24h dc-patterns__end',
					attrs: {
						step: '60', lang: 'en-GB',
						value: day.endLocal ? String(day.endLocal).slice(0, 5) : '',
					},
				});
				end.disabled = !day.isWorking;
				cell.appendChild(end);
				cell.appendChild(create('label', {
					class: 'dc-field__label', attrs: { for: idBase + '-break' }, text: msg('label-break'),
				}));
				const breakInput = create('input', {
					type: 'number', id: idBase + '-break',
					class: 'dc-input dc-input--num dc-patterns__break',
					attrs: { min: '0', max: '720', step: '5', value: String(day.breakMinutes || 0) },
				});
				breakInput.disabled = !day.isWorking;
				cell.appendChild(breakInput);
				workInput.addEventListener('change', () => {
					const on = !!workInput.checked;
					net.disabled = !on;
					start.disabled = !on;
					end.disabled = !on;
					breakInput.disabled = !on;
				});
				table.appendChild(cell);
			});
			week.appendChild(table);
			container.appendChild(week);
		}
		D?.applyLocaleToTemporalInputs?.(container);
	}

	function readGrid(container, cycleWeeks, defaultLocationId) {
		const locId = defaultLocationId && Number(defaultLocationId) > 0 ? Number(defaultLocationId) : null;
		const out = [];
		for (let w = 0; w < cycleWeeks; w++) {
			for (const d of DOW) {
				const cell = container.querySelector('[data-week="' + w + '"][data-dow="' + d + '"]');
				const prev = editorDayMap.get(dayKey(w, d)) || {};
				const isWorking = !!cell?.querySelector('.dc-patterns__is-working')?.checked;
				const start = cell?.querySelector('.dc-patterns__start')?.value || '';
				const end = cell?.querySelector('.dc-patterns__end')?.value || '';
				const net = Number(cell?.querySelector('.dc-patterns__net')?.value || 0);
				const breakRaw = Number(cell?.querySelector('.dc-patterns__break')?.value || 0);
				out.push({
					weekIndex: w, dow: d, isWorking,
					netMinutes: isWorking ? Math.max(0, net) : 0,
					startLocal: isWorking && start ? start : null,
					endLocal: isWorking && end ? end : null,
					breakMinutes: isWorking ? Math.max(0, Math.min(720, Math.round(breakRaw))) : 0,
					// The form has no template picker — keep an API-set link.
					shiftTemplateId: prev.shiftTemplateId ?? null,
					locationId: isWorking ? locId : null,
				});
			}
		}
		return out;
	}

	function renderList() {
		const list = document.getElementById('dc-patterns-list');
		const empty = document.getElementById('dc-patterns-empty');
		const disabled = document.getElementById('dc-patterns-disabled');
		const disabledText = document.getElementById('dc-patterns-disabled-text');
		if (!list) return;
		list.replaceChildren();
		if (!state.enabled) {
			if (disabled) disabled.hidden = false;
			if (disabledText) disabledText.textContent = msg('msg-disabled');
			if (empty) empty.hidden = true;
			document.getElementById('dc-patterns-create')?.setAttribute('disabled', '');
			return;
		}
		if (disabled) disabled.hidden = true;
		document.getElementById('dc-patterns-create')?.removeAttribute('disabled');
		if (!state.patterns.length) {
			if (empty) empty.hidden = false;
			return;
		}
		if (empty) empty.hidden = true;
		state.patterns.forEach((pat) => {
			const weeks = Number(pat.cycleWeeks || 1);
			const meta = t('dutycheck', '{n}-week cycle').replace('{n}', String(weeks));
			list.appendChild(create('li', { class: 'dc-patterns__item' }, [
				create('div', { class: 'dc-patterns__item-main' }, [
					create('strong', { text: String(pat.name || '') }),
					create('span', { class: 'dc-patterns__item-meta', text: meta }),
					buildWeekPreview(pat),
				]),
				create('div', { class: 'dc-patterns__item-actions' }, [
					create('button', {
						type: 'button', class: 'button', text: t('dutycheck', 'Edit'),
						on: { click: () => { void openEditor(pat); } },
					}),
					create('button', {
						type: 'button', class: 'button dc-pattern-assign-btn', text: t('dutycheck', 'Assign'),
						on: { click: () => openAssign(pat) },
					}),
					create('button', {
						type: 'button', class: 'button danger', text: t('dutycheck', 'Delete'),
						on: { click: () => { void deletePattern(pat); } },
					}),
				]),
			]));
		});
	}

	function buildWeekPreview(pat) {
		const wrap = create('div', { class: 'dc-patterns__preview', attrs: { 'aria-hidden': 'true' } });
		const weeks = Math.max(1, Number(pat.cycleWeeks || 1));
		const map = indexWeekDays(pat.weekDays || []);
		for (let w = 0; w < weeks; w++) {
			const row = create('div', { class: 'dc-patterns__preview-week' });
			row.appendChild(create('span', {
				class: 'dc-patterns__preview-label',
				text: msg('label-week', 'Week {n}').replace('{n}', String(w + 1)),
			}));
			const cells = create('div', { class: 'dc-patterns__preview-cells' });
			DOW.forEach((d) => {
				const day = map.get(dayKey(w, d));
				const working = !!(day && day.isWorking);
				const start = working && day.startLocal ? String(day.startLocal).slice(0, 5) : '';
				const end = working && day.endLocal ? String(day.endLocal).slice(0, 5) : '';
				const band = !working ? 'off' : (Number(String(start).slice(0, 2)) < 12 ? 'frueh' : 'spaet');
				const cell = create('div', {
					class: 'dc-patterns__preview-day dc-patterns__preview-day--' + band,
					text: working ? (start + '–' + end) : '—',
				});
				cell.title = dowLabel(d) + (working ? (' ' + start + '–' + end) : '');
				cells.appendChild(cell);
			});
			row.appendChild(cells);
			wrap.appendChild(row);
		}
		return wrap;
	}

	function preselectLocationId(existing) {
		const days = (existing && existing.weekDays) || [];
		for (const day of days) {
			if (day && day.isWorking && day.locationId && Number(day.locationId) > 0) {
				return Number(day.locationId);
			}
		}
		return null;
	}

	async function ensurePlanningDefaults() {
		if (state.planningDefaultsFetched) return;
		try {
			const res = await Api.get('/apps/dutycheck/api/admin/planning-defaults');
			const minutes = Number(res?.planning?.defaultBreakMinutes);
			if (Number.isFinite(minutes) && minutes >= 0) {
				state.defaultBreakMinutes = Math.min(720, Math.round(minutes));
			}
		} catch (_) {
			/* The hint degrades to the generic inherit rule below. */
		}
		state.planningDefaultsFetched = true;
	}

	async function openEditor(existing) {
		await ensureLocations();
		await ensurePlanningDefaults();
		const isNew = !existing || !existing.id;
		let cycleWeeks = Number((existing && existing.cycleWeeks) || state.allowedCycleWeeks[0] || 2);
		if (!state.allowedCycleWeeks.includes(cycleWeeks)) {
			cycleWeeks = state.allowedCycleWeeks[0] || 2;
		}
		const draftWeekDays = (existing && existing.weekDays) || emptyWeekDays(cycleWeeks);
		const preselectedLoc = preselectLocationId(existing);
		// Field handles shared between render() and onSubmit — const inside
		// render() would be invisible to the sibling onSubmit closure.
		const exIsoIdx = existing && existing.anchorIsoWeekIndex !== null && existing.anchorIsoWeekIndex !== undefined
			? Number(existing.anchorIsoWeekIndex)
			: null;
		let anchorDateInput = null;
		let anchorDateError = null;
		let anchorRefInput = null;
		let anchorEffInput = null;
		let anchorEffError = null;
		let reasonInput = null;
		let reasonError = null;

		C.openModal({
			title: isNew ? t('dutycheck', 'New pattern') : t('dutycheck', 'Edit pattern'),
			dialogClass: 'dc-modal--wide',
			primaryLabel: t('dutycheck', 'Save pattern'),
			render: () => {
				const wrap = create('div', { class: 'dc-patterns__editor' });
				wrap.appendChild(create('div', { class: 'dc-field dc-field--full' }, [
					create('label', { class: 'dc-field__label', attrs: { for: 'dc-pat-name' }, text: t('dutycheck', 'Name') }),
					create('input', {
						type: 'text', id: 'dc-pat-name', class: 'dc-input',
						attrs: { required: '', maxlength: '120', value: String((existing && existing.name) || '') },
					}),
				]));
				const weekSelect = create('select', { id: 'dc-pat-weeks', class: 'dc-input' });
				state.allowedCycleWeeks.forEach((n) => {
					const opt = create('option', { value: String(n), text: String(n) });
					if (n === cycleWeeks) opt.selected = true;
					weekSelect.appendChild(opt);
				});
				wrap.appendChild(create('div', { class: 'dc-field' }, [
					create('label', { class: 'dc-field__label', attrs: { for: 'dc-pat-weeks' }, text: t('dutycheck', 'Cycle weeks') }),
					weekSelect,
				]));
				const anchorSelect = create('select', { id: 'dc-pat-anchor', class: 'dc-input' });
				const a1 = create('option', { value: 'iso_week_parity', text: t('dutycheck', 'ISO week parity') });
				const a2 = create('option', { value: 'fixed_date', text: t('dutycheck', 'Fixed start date') });
				if ((existing && existing.anchorType) === 'fixed_date') a2.selected = true;
				else a1.selected = true;
				anchorSelect.appendChild(a1);
				anchorSelect.appendChild(a2);
				wrap.appendChild(create('div', { class: 'dc-field' }, [
					create('label', { class: 'dc-field__label', attrs: { for: 'dc-pat-anchor' }, text: t('dutycheck', 'Anchor type') }),
					anchorSelect,
				]));
				// Anchor detail fields — the select alone used to submit
				// anchorType:'fixed_date' with no anchorDate, which the server
				// rightly rejected (INVALID_ANCHOR_DATE → generic form toast).
				anchorDateInput = create('input', {
					type: 'date', id: 'dc-pat-anchor-date', class: 'dc-input dc-input--date',
				});
				anchorDateError = create('p', {
					class: 'dc-field__error', attrs: { hidden: true, id: 'dc-pat-anchor-date-error' },
				});
				anchorDateInput.addEventListener('input', () => clearFieldError(anchorDateInput, anchorDateError));
				const anchorDateField = create('div', { class: 'dc-field' }, [
					create('label', {
						class: 'dc-field__label', attrs: { for: 'dc-pat-anchor-date' },
						text: t('dutycheck', 'Week A starts in the week of'),
					}),
					anchorDateInput,
					create('p', {
						class: 'dc-field__hint',
						text: t('dutycheck', 'Cycle week A begins on the Monday of the week containing this date.'),
					}),
					anchorDateError,
				]);
				anchorDateInput.value = (existing && existing.anchorDate) || nextMondayIso();

				anchorRefInput = create('input', {
					type: 'date', id: 'dc-pat-anchor-ref', class: 'dc-input dc-input--date',
				});
				const anchorRefError = create('p', {
					class: 'dc-field__error', attrs: { hidden: true, id: 'dc-pat-anchor-ref-error' },
				});
				anchorRefInput.addEventListener('input', () => clearFieldError(anchorRefInput, anchorRefError));
				const anchorRefHint = create('p', { class: 'dc-field__hint' });
				const anchorRefField = create('div', { class: 'dc-field' }, [
					create('label', {
						class: 'dc-field__label', attrs: { for: 'dc-pat-anchor-ref' },
						text: t('dutycheck', 'A day inside week A'),
					}),
					anchorRefInput,
					anchorRefHint,
					anchorRefError,
				]);
				const prefillResidue = exIsoIdx === null
					? defaultIsoResidue(cycleWeeks)
					: ((exIsoIdx % cycleWeeks) + cycleWeeks) % cycleWeeks;
				anchorRefInput.value = utcDateStr(isoMondayOfWeek(
					new Date().getFullYear(),
					prefillResidue === 0 ? cycleWeeks : prefillResidue,
				));

				const updateRefHint = () => {
					const n = Number(weekSelect.value || cycleWeeks) || 1;
					const d = parseDateValue(anchorRefInput.value);
					if (!d) {
						anchorRefHint.textContent = t('dutycheck', 'Pick any day that falls inside the first week of the cycle.');
						return;
					}
					if (n <= 1) {
						anchorRefHint.textContent = t('dutycheck', 'Every week is week A in a one-week cycle.');
						return;
					}
					const r = ((isoWeekNumber(d) % n) + n) % n;
					if (n === 2) {
						anchorRefHint.textContent = r === 1
							? t('dutycheck', 'Week A falls on odd calendar weeks (KW 1, 3, 5, …).')
							: t('dutycheck', 'Week A falls on even calendar weeks (KW 2, 4, 6, …).');
						return;
					}
					anchorRefHint.textContent = t('dutycheck', 'Week A is every {n}th week (ISO week mod {n} = {r}).')
						.replaceAll('{n}', String(n)).replace('{r}', String(r));
				};
				anchorRefInput.addEventListener('input', updateRefHint);
				weekSelect.addEventListener('change', updateRefHint);

				let anchorEffField = null;
				if (!isNew) {
					anchorEffInput = create('input', {
						type: 'date', id: 'dc-pat-anchor-eff', class: 'dc-input dc-input--date',
						attrs: { min: isoDateStr(new Date()) },
					});
					anchorEffError = create('p', {
						class: 'dc-field__error', attrs: { hidden: true, id: 'dc-pat-anchor-eff-error' },
					});
					anchorEffInput.addEventListener('input', () => clearFieldError(anchorEffInput, anchorEffError));
					anchorEffInput.value = (existing && existing.anchorEffectiveFrom) || '';
					anchorEffField = create('div', { class: 'dc-field' }, [
						create('label', {
							class: 'dc-field__label', attrs: { for: 'dc-pat-anchor-eff' },
							text: t('dutycheck', 'Apply the anchor from'),
						}),
						anchorEffInput,
						create('p', {
							class: 'dc-field__hint',
							text: t('dutycheck', 'Dates before this keep the previous week rhythm. Leave empty to apply from today.'),
						}),
						anchorEffError,
					]);
				}

				const syncAnchorFields = () => {
					const fixed = anchorSelect.value === 'fixed_date';
					anchorDateField.hidden = !fixed;
					anchorRefField.hidden = fixed || Number(weekSelect.value || cycleWeeks) <= 1;
					if (anchorEffField) anchorEffField.hidden = !fixed;
					anchorDateInput.required = fixed;
				};
				anchorSelect.addEventListener('change', syncAnchorFields);
				weekSelect.addEventListener('change', syncAnchorFields);
				updateRefHint();
				syncAnchorFields();
				wrap.appendChild(anchorDateField);
				wrap.appendChild(anchorRefField);
				if (anchorEffField) wrap.appendChild(anchorEffField);
				const locSelect = create('select', {
					id: 'dc-pat-location', class: 'dc-input', attrs: { required: '' },
				});
				locSelect.appendChild(create('option', {
					value: '',
					text: t('dutycheck', 'Choose location'),
				}));
				state.locations.forEach((loc) => {
					if (loc.active === false) return;
					const opt = create('option', {
						value: String(loc.id),
						text: String(loc.name || loc.id),
					});
					if (preselectedLoc !== null && Number(loc.id) === preselectedLoc) {
						opt.selected = true;
					}
					locSelect.appendChild(opt);
				});
				wrap.appendChild(create('div', { class: 'dc-field dc-field--full' }, [
					create('label', {
						class: 'dc-field__label', attrs: { for: 'dc-pat-location' },
						text: t('dutycheck', 'Default location'),
					}),
					locSelect,
				]));
				reasonInput = create('input', {
					type: 'text', id: 'dc-pat-reason', class: 'dc-input', attrs: { maxlength: '200' },
				});
				reasonError = create('p', {
					class: 'dc-field__error', attrs: { hidden: true, id: 'dc-pat-reason-error' },
				});
				reasonInput.addEventListener('input', () => clearFieldError(reasonInput, reasonError));
				wrap.appendChild(create('div', { class: 'dc-field dc-field--full' }, [
					create('label', {
						class: 'dc-field__label', attrs: { for: 'dc-pat-reason' },
						text: t('dutycheck', 'Reason for change (if editing)'),
					}),
					reasonInput,
					create('p', {
						class: 'dc-field__hint',
						text: t('dutycheck', 'Required when the week rhythm changes (min. 10 characters) — it is written to the audit trail.'),
					}),
					reasonError,
				]));
				// Break 0 means "inherit the organisation default at fill time" —
				// say so where the planner sets the value, incl. the live number.
				const breakHintText = state.defaultBreakMinutes > 0
					? t('dutycheck', 'Break 0 inherits the organisation default ({n} min).')
						.replace('{n}', String(state.defaultBreakMinutes))
					: t('dutycheck', 'Break 0 inherits the organisation default.');
				wrap.appendChild(create('p', { class: 'dc-field__hint', text: breakHintText }));
				const gridHost = create('div', { class: 'dc-patterns__grid-host', id: 'dc-pat-grid-host' });
				wrap.appendChild(gridHost);
				buildGrid(gridHost, cycleWeeks, draftWeekDays);
				weekSelect.addEventListener('change', () => {
					cycleWeeks = Number(weekSelect.value || 2);
					buildGrid(gridHost, cycleWeeks, emptyWeekDays(cycleWeeks));
				});
				return wrap;
			},
			onSubmit: async () => {
				const name = String(document.getElementById('dc-pat-name')?.value || '').trim();
				if (!name) {
					Msg.announce(t('dutycheck', 'Enter a pattern name.'), 'warning');
					return false;
				}
				const weeks = Number(document.getElementById('dc-pat-weeks')?.value || 2);
				const anchorType = String(document.getElementById('dc-pat-anchor')?.value || 'iso_week_parity');
				const reason = String(reasonInput?.value || '').trim();
				const defaultLocationId = Number(document.getElementById('dc-pat-location')?.value || 0);
				const weekDays = readGrid(document.getElementById('dc-pat-grid-host'), weeks, defaultLocationId || null);
				const hasWorking = weekDays.some((d) => d.isWorking);
				if (hasWorking && (!Number.isInteger(defaultLocationId) || defaultLocationId <= 0)) {
					Msg.announce(t('dutycheck', 'Pick a location for working days.'), 'warning');
					return false;
				}
				for (const day of weekDays) {
					if (!day.isWorking) continue;
					if (!day.startLocal || !day.endLocal) {
						Msg.announce(t('dutycheck', 'Every working day needs start and end times.'), 'warning');
						return false;
					}
				}
				let anchorIsoWeekIndex = null;
				let anchorDate = null;
				let anchorEffectiveFrom = null;
				if (anchorType === 'fixed_date') {
					anchorDate = String(anchorDateInput.value || '').trim() || null;
					if (!anchorDate) {
						setFieldError(anchorDateInput, anchorDateError,
							t('dutycheck', 'Pick the week in which cycle week A starts.'));
						return false;
					}
					if (!isNew && anchorEffInput) {
						anchorEffectiveFrom = String(anchorEffInput.value || '').trim() || null;
					}
				} else if (weeks > 1) {
					const ref = parseDateValue(anchorRefInput.value);
					if (ref) {
						const residue = ((isoWeekNumber(ref) % weeks) + weeks) % weeks;
						const exEffective = exIsoIdx === null
							? defaultIsoResidue(weeks)
							: ((exIsoIdx % weeks) + weeks) % weeks;
						// Omit when it matches the effective stored residue —
						// otherwise null→int reads as an anchor change to the
						// server and it demands a change reason for nothing.
						if (residue !== exEffective) {
							anchorIsoWeekIndex = residue;
						}
					}
				}
				// Mirror RotationPatternService::anchorFieldsChanged: the
				// server compares type/iso/date against the stored row and
				// demands a ≥10-char reason on any difference — surface that
				// here instead of bouncing off a generic 422 toast.
				const exAnchorType = (existing && existing.anchorType) || 'iso_week_parity';
				const exAnchorDate = existing && existing.anchorDate ? String(existing.anchorDate) : null;
				const serverIso = anchorIsoWeekIndex !== null ? anchorIsoWeekIndex : exIsoIdx;
				const serverDate = anchorDate !== null ? anchorDate : exAnchorDate;
				const anchorChanged = !isNew
					&& (exAnchorType !== anchorType || exIsoIdx !== serverIso || exAnchorDate !== serverDate);
				// Server checks ANCHOR_EFFECTIVE_IN_PAST only when the anchor
				// itself changed — an untouched stored date must not block
				// unrelated edits like a rename.
				if (anchorChanged && anchorEffectiveFrom && anchorEffectiveFrom < isoDateStr(new Date())) {
					setFieldError(anchorEffInput, anchorEffError,
						t('dutycheck', 'The apply-from date cannot be in the past.'));
					return false;
				}
				if (anchorChanged && reason.length < 10) {
					setFieldError(reasonInput, reasonError, t('dutycheck',
						'Explain the rhythm change in at least 10 characters — it is written to the audit trail.'));
					return false;
				}
				const payload = { name, cycleWeeks: weeks, anchorType, weekDays };
				if (anchorIsoWeekIndex !== null) payload.anchorIsoWeekIndex = anchorIsoWeekIndex;
				if (anchorDate !== null) payload.anchorDate = anchorDate;
				// '' is an explicit reset server-side (normalize treats '' as
				// null → anchor change defaults to today). Omitting the key
				// keeps the stored value — a stored past date would otherwise
				// trip ANCHOR_EFFECTIVE_IN_PAST when the anchor changes.
				if (anchorEffectiveFrom !== null || (!isNew && anchorType === 'fixed_date')) {
					payload.anchorEffectiveFrom = anchorEffectiveFrom || '';
				}
				if (!isNew && reason) {
					payload.anchorChangeReason = reason;
					payload.changeReason = reason;
				}
				try {
					if (isNew) await Api.post(API, payload);
					else await Api.put(API + '/' + existing.id, payload);
					Msg.announce(msg('msg-saved'), 'success');
					await load();
					return true;
				} catch (err) {
					Msg.handleApiError(err);
					return false;
				}
			},
		});
	}

	async function ensureLocations() {
		if (state.locations.length) return;
		try {
			const res = await Api.get('/apps/dutycheck/api/locations');
			state.locations = Array.isArray(res?.data) ? res.data : (res?.data?.locations || []);
		} catch (_) {
			state.locations = [];
		}
	}

	async function ensureEmployees() {
		if (state.employees.length) return;
		try {
			const res = await Api.get('/apps/dutycheck/api/employees');
			state.employees = Array.isArray(res?.data) ? res.data : (res?.data?.employees || []);
		} catch (_) {
			state.employees = [];
		}
	}

	async function openAssign(pat) {
		await ensureEmployees();

		function patternLabelFor(id) {
			const p = state.patterns.find((x) => Number(x.id) === Number(id));
			return p
				? String(p.name || p.id)
				: t('dutycheck', 'Pattern #{id}').replace('{id}', String(id));
		}

		C.openModal({
			title: t('dutycheck', 'Assign pattern'),
			primaryLabel: t('dutycheck', 'Assign'),
			render: () => {
				const select = create('select', { id: 'dc-pat-assign-emp', class: 'dc-input', attrs: { required: '' } });
				select.appendChild(create('option', { value: '', text: t('dutycheck', 'Choose employee') }));
				state.employees.forEach((emp) => {
					if (emp.active === false) return;
					select.appendChild(create('option', {
						value: String(emp.id),
						text: String(emp.displayName || emp.name || emp.id),
					}));
				});
				const today = new Date();
				const p = (n) => String(n).padStart(2, '0');
				const todayIso = today.getFullYear() + '-' + p(today.getMonth() + 1) + '-' + p(today.getDate());
				const fromInput = create('input', {
					type: 'date', id: 'dc-pat-assign-from', class: 'dc-input dc-input--date',
					attrs: { required: '', value: todayIso },
				});
				const superBox = create('input', {
					type: 'checkbox', id: 'dc-pat-assign-super',
					attrs: { 'aria-describedby': 'dc-pat-assign-super-hint' },
				});
				const superHint = create('p', { class: 'dc-field__hint', id: 'dc-pat-assign-super-hint' });
				superHint.hidden = true;
				const existingBox = create('div', { class: 'dc-field dc-field--full' });
				let loadSeq = 0;
				let autoChecked = false;

				const refreshExisting = async () => {
					const seq = ++loadSeq;
					const empId = Number(select.value || 0);
					const from = String(fromInput.value || '');
					existingBox.replaceChildren();
					superHint.hidden = true;
					if (!empId) {
						if (autoChecked) {
							superBox.checked = false;
							autoChecked = false;
						}
						return;
					}
					let rows = [];
					try {
						const res = await Api.get('/apps/dutycheck/api/employees/' + empId + '/rotation-assignments');
						rows = res?.data?.assignments || [];
					} catch (_) {
						rows = [];
					}
					if (seq !== loadSeq) return;
					const fmt = (iso) => (D?.formatDisplayDate?.(String(iso)) || String(iso));
					const ul = create('ul', { class: 'dc-patterns__assign-list' });
					let overlap = false;
					rows.forEach((row) => {
						const active = state.patterns.some((x) => Number(x.id) === Number(row.patternId));
						const range = fmt(row.validFrom) + ' – '
							+ (row.validTo ? fmt(row.validTo) : t('dutycheck', 'open-ended'));
						ul.appendChild(create('li', {
							text: patternLabelFor(row.patternId) + ': ' + range
								+ (active ? '' : ' (' + t('dutycheck', 'inactive') + ')'),
						}));
						// Mirrors RotationPatternService: only active-pattern
						// assignments block a new one.
						if (active && from && (!row.validTo || String(row.validTo) >= from)) {
							overlap = true;
						}
					});
					if (rows.length) {
						existingBox.appendChild(create('span', {
							class: 'dc-field__label',
							text: t('dutycheck', 'Existing assignments'),
						}));
						existingBox.appendChild(ul);
					}
					if (overlap) {
						superBox.checked = true;
						autoChecked = true;
						superHint.textContent = t('dutycheck', 'The overlapping assignment above ends the day before the new start date.');
						superHint.hidden = false;
					} else if (autoChecked) {
						// Overlap is gone (different employee/start date) — undo only
						// what we auto-set; a manual tick is the user's choice.
						superBox.checked = false;
						autoChecked = false;
					}
				};
				superBox.addEventListener('change', () => { autoChecked = false; });
				select.addEventListener('change', () => { void refreshExisting(); });
				// 'change' (commit), not 'input' — avoids a request per keystroke.
				fromInput.addEventListener('change', () => { void refreshExisting(); });

				return create('div', { class: 'dc-form-grid' }, [
					create('p', {
						class: 'dc-field__hint',
						text: t('dutycheck', 'Assign “{name}” from a start date.')
							.replace('{name}', String(pat.name || '')),
					}),
					create('div', { class: 'dc-field dc-field--full' }, [
						create('label', {
							class: 'dc-field__label', attrs: { for: 'dc-pat-assign-emp' },
							text: t('dutycheck', 'Employee'),
						}),
						select,
					]),
					existingBox,
					create('div', { class: 'dc-field' }, [
						create('label', {
							class: 'dc-field__label', attrs: { for: 'dc-pat-assign-from' },
							text: t('dutycheck', 'Valid from'),
						}),
						fromInput,
					]),
					create('div', { class: 'dc-field dc-field--full' }, [
						create('label', { class: 'dc-checkbox', attrs: { for: 'dc-pat-assign-super' } }, [
							superBox,
							create('span', {
								class: 'dc-checkbox__text',
								text: t('dutycheck', 'End previous overlapping assignment'),
							}),
						]),
						superHint,
					]),
				]);
			},
			onSubmit: async () => {
				const employeeId = Number(document.getElementById('dc-pat-assign-emp')?.value || 0);
				const validFrom = String(document.getElementById('dc-pat-assign-from')?.value || '');
				const supersede = !!document.getElementById('dc-pat-assign-super')?.checked;
				if (!employeeId || !validFrom) {
					Msg.announce(t('dutycheck', 'Pick an employee and a start date.'), 'warning');
					return false;
				}
				try {
					await Api.post(API + '/' + pat.id + '/assign', { employeeId, validFrom, supersede });
					Msg.announce(msg('msg-assigned'), 'success');
					return true;
				} catch (err) {
					const code = String(err?.code || err?.payload?.error?.code || '');
					if (code === 'ASSIGNMENT_OVERLAP') {
						Msg.announce(t('dutycheck', 'This employee already has a pattern assignment in that period. Tick “End previous overlapping assignment” to replace it.'), 'error');
					} else {
						Msg.handleApiError(err);
					}
					return false;
				}
			},
		});
	}

	async function deletePattern(pat) {
		const ok = await C.confirmDialog({
			title: t('dutycheck', 'Delete pattern'),
			body: t('dutycheck', 'Delete pattern “{name}”? Assigned employees keep their existing shifts, but the pattern will not fill new ones.')
				.replace('{name}', String(pat.name || '')),
			confirmLabel: t('dutycheck', 'Delete'),
			danger: true,
		});
		if (!ok) return;
		try {
			await Api.post(API + '/' + pat.id + '/deactivate', {});
			Msg.announce(t('dutycheck', 'Pattern deleted.'), 'success');
			await load();
		} catch (err) {
			Msg.handleApiError(err);
		}
	}

	async function load() {
		setStatus(t('dutycheck', 'Loading…'));
		try {
			const res = await Api.get(API);
			const data = res?.data || {};
			state.patterns = data.patterns || [];
			state.allowedCycleWeeks = data.allowedCycleWeeks || [1, 2, 3, 4, 5, 6, 7, 8];
			state.enabled = data.rotationPatternsEnabled !== false;
			renderList();
			setStatus('');
		} catch (err) {
			const code = String(err?.code || err?.payload?.error?.code || '');
			if (code === 'ROTATION_DISABLED' || code === 'SCHEMA_NOT_READY') {
				state.enabled = false;
				state.patterns = [];
				renderList();
				// The disabled callout owns this message — no duplicate strip.
				setStatus('');
				return;
			}
			setStatus(msg('msg-load-error'), 'error');
			Msg.handleApiError(err);
		}
	}

	document.addEventListener('DOMContentLoaded', () => {
		if (!root()) return;
		document.getElementById('dc-patterns-create')?.addEventListener('click', () => {
			void openEditor(null);
		});
		void load();
	});
})();
