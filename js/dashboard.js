(function () {
	'use strict';

	const Api = window.DutyCheckApi;
	const Msg = window.DutyCheckMessaging;
	const ConflictLabels = window.DutyCheckConflictLabels;
	const C = window.DutyCheckComponents || {};
	const create = (C.createElement || ((tag, props, children) => {
		const el = document.createElement(tag);
		if (props) Object.entries(props).forEach(([k, v]) => {
			if (k === 'class') el.className = String(v);
			else if (k === 'text') el.textContent = String(v);
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
		if (node) node.textContent = String(value ?? '0');
	}

	function renderConflictPulse(data) {
		const root = document.getElementById('dc-dashboard-conflict-pulse');
		if (!root) return;
		root.classList.remove('dc-loading');
		root.removeAttribute('aria-busy');
		root.replaceChildren();
		const periods = Array.isArray(data?.periods) ? data.periods : [];
		const conflicts = Array.isArray(data?.conflicts) ? data.conflicts : [];
		if (periods.length === 0) {
			root.appendChild(create('div', { class: 'dc-callout dc-callout--info' }, [
				create('p', { text: t('dutycheck', 'No periods yet. Start by creating one to begin planning.') }),
			]));
			return;
		}
		const mustFix = conflicts.filter((c) => String(c?.severity) === 'hard').length;
		const confirm = conflicts.filter((c) => String(c?.severity) === 'soft').length;
		const pendingOpen = conflicts.filter((c) => String(c?.severity) === 'soft' && !c?.acknowledged).length;
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

	async function loadDashboard() {
		try {
			const summary = await Api.request(OC.generateUrl('/apps/dutycheck/api/dashboard'));
			const data = summary?.data || {};
			setText('dc-metric-open-periods', data.openPeriods);
			setText('dc-metric-published-periods', data.publishedPeriods);
			setText('dc-metric-employees', data.activeEmployees);
			setText('dc-metric-assignments', data.assignments);
		} catch (err) {
			Msg.handleApiError(err);
			['dc-metric-open-periods', 'dc-metric-published-periods', 'dc-metric-employees', 'dc-metric-assignments'].forEach((id) => {
				const n = document.getElementById(id);
				if (n) n.textContent = '\u2014';
			});
		}
		try {
			const roster = await Api.request(OC.generateUrl('/apps/dutycheck/api/roster'));
			renderConflictPulse(roster?.data || {});
		} catch (err) {
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

	document.addEventListener('DOMContentLoaded', () => {
		window.DutyCheckDates?.applyLocaleToTemporalInputs(document);
		loadDashboard();
	});
})();
