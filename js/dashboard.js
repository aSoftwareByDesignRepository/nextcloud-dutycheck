(function () {
	'use strict';

	const Api = window.DutyCheckApi;
	const Msg = window.DutyCheckMessaging;
	const ConflictLabels = window.DutyCheckConflictLabels;
	const C = window.DutyCheckComponents || {};
	// Fallback mirrors the `class`/`text`/`attrs` contract of
	// DutyCheckComponents.createElement so markup (incl. aria-* attributes)
	// stays identical even if components.js failed to load.
	const create = (C.createElement || ((tag, props, children) => {
		const el = document.createElement(tag);
		if (props) Object.entries(props).forEach(([k, v]) => {
			if (v === undefined || v === null) return;
			if (k === 'class') el.className = String(v);
			else if (k === 'text') el.textContent = String(v);
			else if (k === 'attrs') Object.entries(v).forEach(([ak, av]) => {
				if (av === null || av === undefined || av === false) return;
				el.setAttribute(ak, av === true ? '' : String(av));
			});
			else el.setAttribute(k, String(v));
		});
		(Array.isArray(children) ? children : [children]).forEach((c) => {
			if (c == null) return;
			el.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
		});
		return el;
	}));

	function setText(id, value) {
		const node = document.getElementById(id);
		if (!node) return;
		node.textContent = String(value ?? '0');
		node.removeAttribute('aria-label');
	}

	function setMetricsUnavailable() {
		['dc-metric-open-periods', 'dc-metric-published-periods', 'dc-metric-employees', 'dc-metric-assignments'].forEach((id) => {
			const node = document.getElementById(id);
			if (!node) return;
			node.textContent = '\u2014';
			// The em dash is silent for most screen readers; name the state.
			node.setAttribute('aria-label', t('dutycheck', 'Not available'));
		});
	}

	function readUrls() {
		return window.DutyCheckComponents?.getAppUrls?.() || {};
	}

	function setQuickstartSuppressed(suppress) {
		const quickstart = document.getElementById('dc-quickstart');
		if (!quickstart || !suppress) {
			return;
		}
		// One onboarding path while gates remain: Setup progress owns the CTA.
		quickstart.hidden = true;
		quickstart.setAttribute('data-dc-hint-suppress', 'setup');
	}

	function renderSetupProgress(setup) {
		const section = document.getElementById('dc-dashboard-setup');
		const list = document.getElementById('dc-dashboard-setup-list');
		const schemaAlert = document.getElementById('dc-dashboard-setup-schema-alert');
		if (!section || !list) {
			return;
		}
		const urls = readUrls();
		const schemaReady = Boolean(setup?.schemaReady);
		const employees = Number(setup?.activeEmployees ?? 0);
		const locations = Number(setup?.activeLocations ?? 0);
		const openPeriods = Number(setup?.openPeriods ?? 0);
		const ready = Boolean(setup?.readyForPlanning);

		if (schemaAlert) {
			schemaAlert.hidden = schemaReady;
		}
		if (ready) {
			section.hidden = true;
			list.replaceChildren();
			return;
		}
		section.hidden = false;
		setQuickstartSuppressed(true);

		const steps = [
			{
				done: schemaReady,
				label: t('dutycheck', 'Database tables installed'),
				hint: schemaReady
					? t('dutycheck', 'Done — the app can store roster data.')
					: t('dutycheck', 'Waiting for server upgrade — ask an administrator.'),
				url: null,
				cta: null,
			},
			{
				done: employees > 0,
				label: t('dutycheck', 'Add employees'),
				hint: t('dutycheck', 'People who work shifts.'),
				url: urls.employees,
				cta: t('dutycheck', 'Open Employees'),
			},
			{
				done: locations > 0,
				label: t('dutycheck', 'Add locations'),
				hint: t('dutycheck', 'Places where shifts happen.'),
				url: urls.locations,
				cta: t('dutycheck', 'Open Locations'),
			},
			{
				done: openPeriods > 0,
				label: t('dutycheck', 'Create an open planning period'),
				hint: t('dutycheck', 'A date range for new assignments.'),
				url: urls.periods,
				cta: t('dutycheck', 'Open Periods'),
			},
		];

		const currentIndex = steps.findIndex((step) => !step.done);

		list.replaceChildren();
		for (let i = 0; i < steps.length; i++) {
			const step = steps[i];
			const isCurrent = i === currentIndex;
			const classes = ['dc-setup-checklist__item'];
			if (step.done) {
				classes.push('is-done');
			}
			if (isCurrent) {
				classes.push('is-current');
			}
			const li = create('li', { class: classes.join(' ') });
			const statusLabel = step.done
				? t('dutycheck', 'Done')
				: (isCurrent ? t('dutycheck', 'Next step') : t('dutycheck', 'To do'));
			const status = create('span', {
				class: 'dc-setup-checklist__status',
				attrs: { 'aria-label': statusLabel },
				text: step.done ? '\u2713' : (isCurrent ? String(i + 1) : '\u2022'),
			});
			const body = create('div', { class: 'dc-setup-checklist__body' });
			body.appendChild(create('strong', { text: step.label }));
			if (!step.done) {
				body.appendChild(create('p', { class: 'dc-setup-checklist__hint', text: step.hint }));
			}
			// One CTA only — the first incomplete actionable step. Done rows
			// stay compact; blocked schema step explains itself without a dead link.
			if (isCurrent && step.url && step.cta) {
				body.appendChild(create('a', {
					class: 'button primary',
					href: step.url,
					text: step.cta,
				}));
			}
			li.appendChild(status);
			li.appendChild(body);
			list.appendChild(li);
		}
	}

	function renderConflictPulse(data) {
		const root = document.getElementById('dc-dashboard-conflict-pulse');
		if (!root) return;
		root.classList.remove('dc-loading');
		root.removeAttribute('aria-busy');
		root.replaceChildren();
		const periods = Array.isArray(data?.periods) ? data.periods : [];
		if (periods.length === 0) {
			root.appendChild(create('div', { class: 'dc-callout dc-callout--info' }, [
				create('p', { text: t('dutycheck', 'No periods yet. Start by creating one to begin planning.') }),
			]));
			return;
		}
		let mustFix;
		let confirm;
		let pendingOpen;
		if (data.readiness && typeof data.readiness === 'object') {
			mustFix = Number(data.readiness.hardConflicts || 0);
			confirm = Number(data.readiness.softConflicts || 0);
			pendingOpen = Number(data.readiness.unacknowledgedSoftConflicts || 0);
		} else {
			const conflicts = Array.isArray(data?.conflicts) ? data.conflicts : [];
			mustFix = conflicts.filter((c) => String(c?.severity) === 'hard').length;
			confirm = conflicts.filter((c) => String(c?.severity) === 'soft').length;
			pendingOpen = conflicts.filter((c) => String(c?.severity) === 'soft' && !c?.acknowledged).length;
		}
		const tone = mustFix > 0 ? 'critical' : (pendingOpen > 0 ? 'warning' : 'success');
		const title = ConflictLabels
			? ConflictLabels.pulseTitle(mustFix, pendingOpen)
			: t('dutycheck', 'Planning issue status');
		const subtitle = ConflictLabels
			? ConflictLabels.countsSummary(mustFix, confirm, pendingOpen)
			: String(mustFix);
		root.appendChild(create('div', { class: 'dc-callout dc-callout--' + tone }, [
			create('p', {}, [create('strong', { text: title })]),
			create('p', { class: 'dc-callout__hint', text: subtitle }),
		]));
	}

	async function loadSummary() {
		try {
			const summary = await Api.get('/apps/dutycheck/api/dashboard');
			const data = summary?.data || {};
			setText('dc-metric-open-periods', data.openPeriods);
			setText('dc-metric-published-periods', data.publishedPeriods);
			setText('dc-metric-employees', data.activeEmployees);
			setText('dc-metric-assignments', data.assignments);
			renderSetupProgress(data.setup || {});
			if (data.setup && data.setup.schemaReady === false) {
				Msg.announce(
					t('dutycheck', 'DutyCheck database setup is incomplete. Ask an administrator to run the server upgrade.'),
					'error',
				);
			}
		} catch (err) {
			Msg.handleApiError(err);
			setMetricsUnavailable();
		}
	}

	async function loadPulse() {
		try {
			const list = await Api.get('/apps/dutycheck/api/periods');
			const periods = list?.data?.periods || [];
			if (!periods.length) {
				renderConflictPulse({ periods: [] });
				return;
			}
			const selected = periods.find((p) => String(p.status) === 'open') || periods[0];
			const ready = await Api.get(`/apps/dutycheck/api/periods/${Number(selected.id)}/publish-readiness`);
			renderConflictPulse({ periods, readiness: ready?.data || {} });
		} catch (err) {
			Msg.handleApiError(err);
			const root = document.getElementById('dc-dashboard-conflict-pulse');
			if (root) {
				root.classList.remove('dc-loading');
				root.removeAttribute('aria-busy');
				root.replaceChildren(create('div', { class: 'dc-callout dc-callout--warning' }, [
					create('p', { text: t('dutycheck', 'Could not load planning checks. Reload the page to retry.') }),
				]));
			}
		}
	}

	function loadDashboard() {
		// Independent requests rendering disjoint DOM regions; run in parallel.
		// Each helper traps its own failures, so this can never reject.
		return Promise.all([loadSummary(), loadPulse()]);
	}

	document.addEventListener('DOMContentLoaded', () => {
		window.DutyCheckDates?.applyLocaleToTemporalInputs(document);
		loadDashboard();
	});
})();
