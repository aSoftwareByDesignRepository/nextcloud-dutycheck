(function () {
	'use strict';

	const Api = window.DutyCheckApi;
	const Msg = window.DutyCheckMessaging;
	const C = window.DutyCheckComponents;
	const TzPicker = window.DutyCheckTimezonePicker;
	const create = C.createElement;

	let editingId = null;
	/** @type {{ setValue: (tz: string) => void, getValue: () => string, reset: () => void } | null} */
	let timezonePicker = null;

	function readTimezone() {
		const app = document.getElementById('app-content');
		const fromApp = app ? app.getAttribute('data-timezone') : '';
		if (fromApp && fromApp.trim() !== '') return fromApp;
		const fromHtml = document.documentElement.getAttribute('data-timezone');
		return (fromHtml && fromHtml.trim() !== '') ? fromHtml : 'Europe/Berlin';
	}
	const defaultTimezone = readTimezone();

	function setEditMode(editing) {
		const cancelBtn = document.getElementById('dc-location-form-reset');
		if (cancelBtn) cancelBtn.hidden = !editing;
		const submitBtn = document.querySelector('#dc-location-form button[type="submit"]');
		if (submitBtn) {
			submitBtn.textContent = editing
				? t('dutycheck', 'Update location')
				: t('dutycheck', 'Save location');
		}
	}

	function resetForm() {
		editingId = null;
		const form = document.getElementById('dc-location-form');
		if (!form) return;
		form.reset();
		if (form.active) form.active.checked = true;
		timezonePicker?.reset();
		setEditMode(false);
	}

	function hydrateForm(row) {
		editingId = Number(row.id);
		const form = document.getElementById('dc-location-form');
		if (!form) return;
		form.name.value = row.name || '';
		timezonePicker?.setValue(row.timezone || defaultTimezone);
		form.active.checked = Boolean(row.active);
		setEditMode(true);
		form.scrollIntoView({ behavior: 'smooth', block: 'start' });
		form.name.focus();
	}

	function renderRows(rows) {
		const tbody = document.getElementById('dc-locations-table-body');
		if (!tbody) return;
		tbody.replaceChildren();
		if (!rows.length) {
			const tr = create('tr');
			const td = create('td', { text: t('dutycheck', 'No locations yet. Add one above.') });
			td.colSpan = 4;
			tr.appendChild(td);
			tbody.appendChild(tr);
			return;
		}
		for (const row of rows) {
			const tr = create('tr');
			const cells = [
				{ label: t('dutycheck', 'Name'), value: row.name },
				{ label: t('dutycheck', 'Timezone'), value: row.timezone },
			];
			cells.forEach((entry) => {
				const td = create('td', { text: String(entry.value || '') });
				td.dataset.cell = entry.label;
				tr.appendChild(td);
			});
			const statusTd = create('td');
			statusTd.dataset.cell = t('dutycheck', 'Status');
			statusTd.appendChild(create('span', {
				class: 'dc-status-badge dc-status-badge--' + (row.active ? 'published' : 'closed'),
				text: row.active ? t('dutycheck', 'Active') : t('dutycheck', 'Inactive'),
			}));
			tr.appendChild(statusTd);

			const actionsTd = create('td', { class: 'dc-table__col--actions' });
			actionsTd.dataset.cell = t('dutycheck', 'Actions');
			const wrap = create('div', { class: 'dc-row-actions' });
			const editBtn = create('button', { type: 'button', class: 'button', text: t('dutycheck', 'Edit') });
			editBtn.addEventListener('click', () => hydrateForm(row));
			wrap.appendChild(editBtn);
			const toggleBtn = create('button', {
				type: 'button',
				class: 'button',
				text: row.active ? t('dutycheck', 'Deactivate') : t('dutycheck', 'Activate'),
			});
			toggleBtn.addEventListener('click', async () => {
				const ok = row.active ? await C.confirmDialog({
					title: t('dutycheck', 'Deactivate location?'),
					body: t('dutycheck', 'It will be hidden from new assignments. Existing assignments are kept.'),
					confirmLabel: t('dutycheck', 'Deactivate'),
					danger: true,
				}) : true;
				if (!ok) return;
				try {
					await save({ name: row.name, timezone: row.timezone, active: !row.active }, row.id);
				} catch (err) {
					Msg.handleApiError(err);
				}
			});
			wrap.appendChild(toggleBtn);
			actionsTd.appendChild(wrap);
			tr.appendChild(actionsTd);
			tbody.appendChild(tr);
		}
	}

	async function load() {
		const tbody = document.getElementById('dc-locations-table-body');
		C.setLoadingRow(tbody, 4);
		try {
			const response = await Api.get('/apps/dutycheck/api/locations');
			renderRows(response?.data || []);
		} catch (err) {
			Msg.handleApiError(err);
			C.renderTableFetchError(tbody, 4, t('dutycheck', 'Could not load locations. Reload the page or contact an administrator if this keeps happening.'));
		} finally {
			C.clearLoadingRow(tbody);
		}
	}

	async function save(payload, id) {
		const isUpdate = Number.isInteger(id) && id > 0;
		const url = isUpdate ? `/apps/dutycheck/api/locations/${id}` : '/apps/dutycheck/api/locations';
		const method = isUpdate ? 'put' : 'post';
		const response = await Api[method](url, payload);
		renderRows(response?.data || []);
		resetForm();
		Msg.announce(t('dutycheck', 'Location saved.'));
	}

	document.addEventListener('DOMContentLoaded', async () => {
		const pickerRoot = document.querySelector('[data-dc-timezone-picker]');
		if (TzPicker && pickerRoot) {
			timezonePicker = await TzPicker.attach(pickerRoot, { defaultTimezone });
		}

		try {
			await load();
		} catch (err) {
			Msg.handleApiError(err);
			return;
		}
		document.getElementById('dc-location-form')?.addEventListener('submit', async (event) => {
			event.preventDefault();
			const formData = new FormData(event.currentTarget);
			const name = String(formData.get('name') || '').trim();
			const timezone = timezonePicker
				? timezonePicker.getValue()
				: String(formData.get('timezone') || '').trim();
			if (name === '') {
				Msg.announce(t('dutycheck', 'Location name is required.'), 'error');
				return;
			}
			if (timezone === '') {
				Msg.announce(t('dutycheck', 'Please choose a timezone.'), 'error');
				document.getElementById('dc-location-timezone-input')?.focus();
				return;
			}
			try {
				await save({ name, timezone, active: formData.get('active') !== null }, editingId);
			} catch (err) {
				const code = String(err?.payload?.error?.code || '');
				if (code === 'INVALID_TIMEZONE') {
					Msg.announce(t('dutycheck', 'Timezone must be a recognised IANA name.'), 'error');
					return;
				}
				Msg.handleApiError(err);
			}
		});
		document.getElementById('dc-location-form-reset')?.addEventListener('click', resetForm);
	});
})();
