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

	const STORAGE_KEY = 'dutycheck.today.lastLocationId';

	function root() {
		return document.getElementById('dc-today-board');
	}

	function msg(key, fallback) {
		const el = root();
		return (el && el.getAttribute('data-' + key)) || fallback || '';
	}

	function setStatus(text, kind) {
		const el = document.getElementById('dc-today-status');
		if (!el) return;
		el.textContent = text || '';
		el.classList.toggle('dc-roster-flash--error', kind === 'error');
	}

	function setBusy(busy) {
		const sk = document.getElementById('dc-today-skeleton');
		if (sk) sk.hidden = !busy;
		document.getElementById('dc-today-timeline')?.setAttribute('aria-busy', busy ? 'true' : 'false');
	}

	function todayIso() {
		const d = new Date();
		const p = (n) => String(n).padStart(2, '0');
		return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`;
	}

	function clock(t) {
		return String(t || '').slice(0, 5);
	}

	async function loadLocations() {
		const sel = document.getElementById('dc-today-location');
		if (!sel) return;
		const res = await Api.get('/apps/dutycheck/api/locations');
		const list = Array.isArray(res?.data) ? res.data : (res?.data?.locations || []);
		sel.replaceChildren();
		if (!list.length) {
			sel.appendChild(create('option', { value: '', text: t('dutycheck', 'No locations') }));
			return;
		}
		let last = 0;
		try { last = Number(window.localStorage.getItem(STORAGE_KEY) || 0); } catch (_) {}
		list.forEach((loc) => {
			const id = Number(loc.id);
			const opt = create('option', { value: String(id), text: String(loc.name || id) });
			if (id === last) opt.selected = true;
			sel.appendChild(opt);
		});
		if (!sel.value && list[0]) sel.value = String(list[0].id);
	}

	function updateRosterLink(locationId) {
		const link = document.getElementById('dc-today-open-roster');
		const base = root()?.getAttribute('data-roster-url') || '#';
		if (!link) return;
		if (!locationId) { link.href = base; return; }
		const url = new URL(base, window.location.origin);
		url.searchParams.set('locationId', String(locationId));
		link.href = url.pathname + url.search;
	}

	function renderGaps(gaps) {
		const list = document.getElementById('dc-today-gaps');
		if (!list) return;
		list.replaceChildren();
		if (!gaps || !gaps.length) { list.hidden = true; return; }
		list.hidden = false;
		gaps.forEach((gap) => {
			const title = normalizeBandLabel(gap.templateName || gap.name || msg('msg-gap'));
			const need = Number(gap.minHeadcount ?? gap.needed ?? 0);
			const have = Number(gap.assignedCount ?? gap.assigned ?? gap.have ?? 0);
			const time = `${clock(gap.startTime)}–${clock(gap.endTime)}`;
			const text = t('dutycheck', '{title}: {have} of {need} staff ({time})')
				.replace('{title}', title).replace('{have}', String(have))
				.replace('{need}', String(need)).replace('{time}', time);
			list.appendChild(create('li', { class: 'dc-today__gap' }, [
				create('span', { class: 'dc-today__gap-icon', attrs: { 'aria-hidden': 'true' }, text: '!' }),
				create('span', { text }),
			]));
		});
	}

	function normalizeBandLabel(raw) {
		const s = String(raw || '').trim();
		if (!s) return '';
		// Suite vocabulary: Nacht (not Abend) — matches Schichtband-Lage NACHT.
		if (/^abend$/i.test(s) || /^evening$/i.test(s)) {
			return t('dutycheck', 'Nacht');
		}
		return s;
	}

	function renderShifts(shifts) {
		const list = document.getElementById('dc-today-timeline');
		const empty = document.getElementById('dc-today-empty');
		if (!list) return;
		list.replaceChildren();
		if (!shifts || !shifts.length) { if (empty) empty.hidden = false; return; }
		if (empty) empty.hidden = true;
		shifts.forEach((shift) => {
			const name = String(shift.displayName || shift.employeeName || '—');
			const time = `${clock(shift.startTime)}–${clock(shift.endTime)}`;
			const template = normalizeBandLabel(shift.templateName || '');
			const draft = String(shift.periodStatus || '') === 'open';
			const statusLabel = draft ? msg('msg-draft') : msg('msg-published');
			const meta = [time, template, statusLabel].filter(Boolean).join(' · ');
			list.appendChild(create('li', {
				class: 'dc-today__shift' + (draft ? ' dc-today__shift--draft' : ''),
			}, [
				create('div', { class: 'dc-today__shift-main' }, [
					create('strong', { class: 'dc-today__shift-name', text: name }),
					create('span', { class: 'dc-today__shift-meta', text: meta }),
				]),
			]));
		});
	}

	async function loadBoard() {
		const locationId = Number(document.getElementById('dc-today-location')?.value || 0);
		const date = String(document.getElementById('dc-today-date')?.value || todayIso());
		if (!locationId) { setStatus(t('dutycheck', 'Pick a location.'), 'error'); return; }
		try { window.localStorage.setItem(STORAGE_KEY, String(locationId)); } catch (_) {}
		updateRosterLink(locationId);
		setBusy(true);
		setStatus(msg('msg-loading'));
		document.getElementById('dc-today-empty')?.setAttribute('hidden', '');
		try {
			const res = await Api.get('/apps/dutycheck/api/today-board', { locationId, date });
			const data = res?.data || {};
			renderGaps(data.gaps || []);
			renderShifts(data.shifts || []);
			const count = (data.shifts || []).length;
			// Avoid doubled empty theater: body copy owns the empty message; status stays quiet.
			if (count) {
				setStatus(t('dutycheck', '{n} people on duty.').replace('{n}', String(count)));
			} else {
				setStatus('');
			}
		} catch (err) {
			const code = String(err?.code || err?.payload?.error?.code || '');
			renderGaps([]);
			renderShifts([]);
			if (code === 'TODAY_BOARD_DISABLED') {
				setStatus(msg('msg-disabled'), 'error');
				const disabled = document.getElementById('dc-today-disabled');
				const text = document.getElementById('dc-today-disabled-text');
				if (text) text.textContent = msg('msg-disabled');
				if (disabled) disabled.hidden = false;
			} else {
				document.getElementById('dc-today-disabled')?.setAttribute('hidden', '');
				setStatus(msg('msg-error'), 'error');
				Msg.handleApiError(err);
			}
		} finally {
			setBusy(false);
		}
	}

	function shiftDate(iso, deltaDays) {
		const [y, m, d] = String(iso).split('-').map(Number);
		const dt = new Date(y, m - 1, d);
		dt.setDate(dt.getDate() + deltaDays);
		const p = (n) => String(n).padStart(2, '0');
		return `${dt.getFullYear()}-${p(dt.getMonth() + 1)}-${p(dt.getDate())}`;
	}

	document.addEventListener('DOMContentLoaded', async () => {
		if (!root()) return;
		D?.applyLocaleToTemporalInputs?.(document);
		const dateInput = document.getElementById('dc-today-date');
		if (dateInput && !dateInput.value) dateInput.value = todayIso();
		document.getElementById('dc-today-filters')?.addEventListener('submit', (e) => {
			e.preventDefault();
			void loadBoard();
		});
		const jump = (delta) => {
			if (!dateInput) return;
			dateInput.value = delta === 0 ? todayIso() : shiftDate(dateInput.value || todayIso(), delta);
			void loadBoard();
		};
		document.getElementById('dc-today-prev')?.addEventListener('click', () => jump(-1));
		document.getElementById('dc-today-now')?.addEventListener('click', () => jump(0));
		document.getElementById('dc-today-next')?.addEventListener('click', () => jump(1));
		try {
			await loadLocations();
			await loadBoard();
		} catch (err) {
			Msg.handleApiError(err);
			setStatus(msg('msg-error'), 'error');
		}
	});
})();
