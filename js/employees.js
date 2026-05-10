(function () {
	'use strict';

	const Api = window.DutyCheckApi;
	const Msg = window.DutyCheckMessaging;
	const C = window.DutyCheckComponents;
	const create = C.createElement;

	let editingId = null;
	let directoryUsers = [];
	let directoryUserMap = new Map();
	let selectedUser = null;
	let searchTimer = null;

	function userDisplay(uid) {
		if (!uid) return '—';
		const user = directoryUserMap.get(String(uid));
		if (!user) return String(uid);
		const name = String(user.displayName || uid);
		return name === uid ? uid : `${name} (${uid})`;
	}

	function setLinkedUser(user) {
		selectedUser = user || null;
		const hidden = document.getElementById('dc-employee-linked-user');
		if (hidden) hidden.value = user ? String(user.id) : '';
		renderLinkedChips();
	}

	function renderLinkedChips() {
		const container = document.getElementById('dc-employee-linked-chips');
		if (!container) return;
		container.replaceChildren();
		if (!selectedUser) {
			container.appendChild(create('span', { class: 'dc-pill', text: t('dutycheck', 'No linked account') }));
			return;
		}
		const chip = create('li', { class: 'dc-chip' }, [
			create('span', { class: 'dc-chip__text', text: userDisplay(selectedUser.id) }),
			create('button', {
				type: 'button',
				class: 'dc-chip__remove',
				attrs: { 'aria-label': t('dutycheck', 'Remove linked account') },
				text: '\u00d7',
				on: { click: () => { setLinkedUser(null); } },
			}),
		]);
		container.appendChild(chip);
	}

	function renderResults(items, onPick) {
		const container = document.getElementById('dc-employee-search-results');
		if (!container) return;
		container.replaceChildren();
		if (!items.length) {
			return;
		}
		for (const item of items) {
			const li = create('li', {
				class: 'dc-entity-results__option',
				attrs: { role: 'option', tabindex: '0' },
			}, [
				create('span', { class: 'dc-entity-results__title', text: String(item.displayName || item.id) }),
				create('span', { class: 'dc-entity-results__sub', text: String(item.id) }),
			]);
			const pick = () => { onPick(item); };
			li.addEventListener('click', pick);
			li.addEventListener('keydown', (event) => {
				if (event.key !== 'Enter' && event.key !== ' ') return;
				event.preventDefault();
				pick();
			});
			container.appendChild(li);
		}
	}

	function renderRows(rows) {
		const tbody = document.getElementById('dc-employees-table-body');
		if (!tbody) return;
		tbody.replaceChildren();
		if (!rows.length) {
			const tr = create('tr');
			const td = create('td', { text: t('dutycheck', 'No employees yet. Add the first employee above.') });
			td.colSpan = 4;
			tr.appendChild(td);
			tbody.appendChild(tr);
			return;
		}
		for (const row of rows) {
			const tr = create('tr');
			const nameTd = create('td', { text: String(row.displayName || '—') });
			nameTd.dataset.cell = t('dutycheck', 'Name');
			tr.appendChild(nameTd);

			const linkedTd = create('td', { text: userDisplay(row.linkedUserId || '') });
			linkedTd.dataset.cell = t('dutycheck', 'Linked user');
			tr.appendChild(linkedTd);

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
			const editBtn = create('button', {
				type: 'button',
				class: 'button',
				text: t('dutycheck', 'Edit'),
			});
			editBtn.addEventListener('click', () => hydrateForm(row));
			wrap.appendChild(editBtn);

			const toggleBtn = create('button', {
				type: 'button',
				class: 'button',
				text: row.active ? t('dutycheck', 'Deactivate') : t('dutycheck', 'Activate'),
			});
			toggleBtn.addEventListener('click', async () => {
				const ok = row.active ? await C.confirmDialog({
					title: t('dutycheck', 'Deactivate employee?'),
					body: t('dutycheck', 'They will not appear in the assignment form. Existing assignments are kept.'),
					confirmLabel: t('dutycheck', 'Deactivate'),
					danger: true,
				}) : true;
				if (!ok) return;
				try {
					await save({
						displayName: row.displayName,
						linkedUserId: row.linkedUserId || '',
						active: !row.active,
					}, row.id);
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

	function setEditMode(editing) {
		const cancelBtn = document.getElementById('dc-employee-form-reset');
		if (cancelBtn) cancelBtn.hidden = !editing;
		const submitBtn = document.querySelector('#dc-employee-form button[type="submit"]');
		if (submitBtn) {
			submitBtn.textContent = editing
				? t('dutycheck', 'Update employee')
				: t('dutycheck', 'Save employee');
		}
	}

	function hydrateForm(row) {
		editingId = Number(row.id);
		const form = document.getElementById('dc-employee-form');
		if (!form) return;
		form.displayName.value = row.displayName || '';
		const linkedUserId = String(row.linkedUserId || '');
		if (linkedUserId) {
			const known = directoryUserMap.get(linkedUserId);
			setLinkedUser(known || { id: linkedUserId, displayName: linkedUserId });
		} else {
			setLinkedUser(null);
		}
		form.active.checked = Boolean(row.active);
		setEditMode(true);
		form.scrollIntoView({ behavior: 'smooth', block: 'start' });
		form.displayName.focus();
	}

	function resetForm() {
		editingId = null;
		const form = document.getElementById('dc-employee-form');
		if (!form) return;
		form.reset();
		form.active.checked = true;
		setLinkedUser(null);
		setEditMode(false);
	}

	async function loadDirectoryUsers(query) {
		const params = query ? { q: query } : {};
		const response = await Api.get('/apps/dutycheck/api/admin/users', params);
		const users = Array.isArray(response?.users) ? response.users : [];
		const list = users
			.filter((user) => user && user.enabled !== false && String(user.id || '') !== '')
			.map((user) => ({ id: String(user.id), displayName: String(user.displayName || user.id) }))
			.sort((a, b) => `${a.displayName} ${a.id}`.localeCompare(`${b.displayName} ${b.id}`));
		// Cache results so userDisplay() can resolve IDs in the table later.
		for (const u of list) directoryUserMap.set(u.id, u);
		directoryUsers = list;
		return list;
	}

	async function load() {
		const tbody = document.getElementById('dc-employees-table-body');
		C.setLoadingRow(tbody, 4);
		try {
			const response = await Api.get('/apps/dutycheck/api/employees');
			renderRows(response?.data || []);
		} catch (err) {
			Msg.handleApiError(err);
			C.renderTableFetchError(tbody, 4, t('dutycheck', 'Could not load employees. Reload the page or contact an administrator if this keeps happening.'));
		} finally {
			C.clearLoadingRow(tbody);
		}
	}

	async function save(payload, id) {
		const isUpdate = Number.isInteger(id) && id > 0;
		const url = isUpdate ? `/apps/dutycheck/api/employees/${id}` : '/apps/dutycheck/api/employees';
		const method = isUpdate ? 'put' : 'post';
		const response = await Api[method](url, payload);
		renderRows(response?.data || []);
		resetForm();
		Msg.announce(t('dutycheck', 'Employee saved.'));
	}

	function wireSearch() {
		const input = document.getElementById('dc-employee-search');
		if (!input) return;
		const onPick = (item) => {
			setLinkedUser(item);
			input.value = '';
			renderResults([], onPick);
			input.focus();
		};
		input.addEventListener('input', () => {
			if (searchTimer) window.clearTimeout(searchTimer);
			searchTimer = window.setTimeout(async () => {
				const q = input.value.trim();
				if (q.length < 2) {
					renderResults([], onPick);
					return;
				}
				try {
					const items = await loadDirectoryUsers(q);
					renderResults(items, onPick);
				} catch (err) {
					Msg.handleApiError(err);
				}
			}, 240);
		});
	}

	document.addEventListener('DOMContentLoaded', async () => {
		try {
			await loadDirectoryUsers('');
			renderLinkedChips();
		} catch (err) {
			Msg.handleApiError(err);
		}
		try {
			await load();
		} catch (err) {
			Msg.handleApiError(err);
			return;
		}
		wireSearch();
		document.getElementById('dc-employee-form')?.addEventListener('submit', async (event) => {
			event.preventDefault();
			const formData = new FormData(event.currentTarget);
			const displayName = String(formData.get('displayName') || '').trim();
			if (displayName === '') {
				Msg.announce(t('dutycheck', 'Display name is required.'), 'error');
				return;
			}
			try {
				await save({
					displayName,
					linkedUserId: String(formData.get('linkedUserId') || ''),
					active: formData.get('active') !== null,
				}, editingId);
			} catch (err) {
				const code = String(err?.payload?.error?.code || '');
				if (code === 'INVALID_LINKED_USER') {
					Msg.announce(t('dutycheck', 'The selected user could not be linked. Pick another account.'), 'error');
					return;
				}
				if (code === 'LINKED_USER_EXISTS') {
					Msg.announce(t('dutycheck', 'That user is already linked to another employee.'), 'error');
					return;
				}
				if (code === 'EMPLOYEE_NAME_EXISTS') {
					Msg.announce(t('dutycheck', 'An employee with that display name already exists.'), 'error');
					return;
				}
				Msg.handleApiError(err);
			}
		});
		document.getElementById('dc-employee-form-reset')?.addEventListener('click', resetForm);
	});
})();
