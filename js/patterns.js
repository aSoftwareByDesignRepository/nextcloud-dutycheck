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
		allowedCycleWeeks: [1, 2, 3, 4],
		enabled: true,
		employees: [],
		locations: [],
	};

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
				workInput.addEventListener('change', () => {
					const on = !!workInput.checked;
					net.disabled = !on;
					start.disabled = !on;
					end.disabled = !on;
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
				const isWorking = !!cell?.querySelector('.dc-patterns__is-working')?.checked;
				const start = cell?.querySelector('.dc-patterns__start')?.value || '';
				const end = cell?.querySelector('.dc-patterns__end')?.value || '';
				const net = Number(cell?.querySelector('.dc-patterns__net')?.value || 0);
				out.push({
					weekIndex: w, dow: d, isWorking,
					netMinutes: isWorking ? Math.max(0, net) : 0,
					startLocal: isWorking && start ? start : null,
					endLocal: isWorking && end ? end : null,
					breakMinutes: 0, shiftTemplateId: null,
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
						type: 'button', class: 'button', text: t('dutycheck', 'Assign'),
						on: { click: () => openAssign(pat) },
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

	async function openEditor(existing) {
		await ensureLocations();
		const isNew = !existing || !existing.id;
		let cycleWeeks = Number((existing && existing.cycleWeeks) || state.allowedCycleWeeks[0] || 2);
		if (!state.allowedCycleWeeks.includes(cycleWeeks)) {
			cycleWeeks = state.allowedCycleWeeks[0] || 2;
		}
		const draftWeekDays = (existing && existing.weekDays) || emptyWeekDays(cycleWeeks);
		const preselectedLoc = preselectLocationId(existing);

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
				wrap.appendChild(create('div', { class: 'dc-field dc-field--full' }, [
					create('label', {
						class: 'dc-field__label', attrs: { for: 'dc-pat-reason' },
						text: t('dutycheck', 'Reason for change (if editing)'),
					}),
					create('input', { type: 'text', id: 'dc-pat-reason', class: 'dc-input', attrs: { maxlength: '200' } }),
				]));
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
				const reason = String(document.getElementById('dc-pat-reason')?.value || '').trim();
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
				const payload = { name, cycleWeeks: weeks, anchorType, weekDays };
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
					create('div', { class: 'dc-field' }, [
						create('label', {
							class: 'dc-field__label', attrs: { for: 'dc-pat-assign-from' },
							text: t('dutycheck', 'Valid from'),
						}),
						create('input', {
							type: 'date', id: 'dc-pat-assign-from', class: 'dc-input dc-input--date',
							attrs: { required: '', value: todayIso },
						}),
					]),
					create('label', { class: 'dc-checkbox', attrs: { for: 'dc-pat-assign-super' } }, [
						create('input', { type: 'checkbox', id: 'dc-pat-assign-super' }),
						create('span', {
							class: 'dc-checkbox__text',
							text: t('dutycheck', 'End previous overlapping assignment'),
						}),
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
					Msg.handleApiError(err);
					return false;
				}
			},
		});
	}

	async function load() {
		setStatus(t('dutycheck', 'Loading…'));
		try {
			const res = await Api.get(API);
			const data = res?.data || {};
			state.patterns = data.patterns || [];
			state.allowedCycleWeeks = data.allowedCycleWeeks || [1, 2, 3, 4];
			state.enabled = data.rotationPatternsEnabled !== false;
			renderList();
			setStatus('');
		} catch (err) {
			const code = String(err?.code || err?.payload?.error?.code || '');
			if (code === 'ROTATION_DISABLED' || code === 'SCHEMA_NOT_READY') {
				state.enabled = false;
				state.patterns = [];
				renderList();
				setStatus(msg('msg-disabled'), 'error');
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
