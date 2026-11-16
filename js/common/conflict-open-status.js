(function () {
	'use strict';

	/**
	 * View-model for Settings → Conflict thresholds freeze callout.
	 * Hide Apply when there is nothing to do — a disabled primary CTA looks like
	 * a broken next step (support friction).
	 *
	 * @param {{schemaReady?:boolean,openCount?:number,outdatedCount?:number}|null|undefined} openPeriods
	 * @param {(app:string, text:string)=>string} [translate]
	 * @returns {{
	 *   tone:'info'|'success'|'warning',
	 *   title:string,
	 *   body:string,
	 *   showApply:boolean,
	 *   applyEnabled:boolean,
	 *   outdatedCount:number,
	 *   openCount:number,
	 * }}
	 */
	function resolveConflictOpenCallout(openPeriods, translate) {
		const tFn = typeof translate === 'function'
			? translate
			: function (_app, text) { return text; };
		const t = function (msg) { return tFn('dutycheck', msg); };
		// Plural-aware translate — window.n resolves the _sg_::_pl_ combined
		// catalog pair; the fallback keeps unit tests runnable without OC.L10N.
		const n = function (singular, plural, count) {
			const c = Number.isFinite(Number(count)) ? Number(count) : 0;
			if (typeof window !== 'undefined' && typeof window.n === 'function') {
				return window.n('dutycheck', singular, plural, c);
			}
			return String(c === 1 ? singular : plural).replace('%n', String(c));
		};
		const info = openPeriods && typeof openPeriods === 'object' ? openPeriods : {};
		const schemaReady = info.schemaReady !== false;
		const openCount = Number(info.openCount || 0);
		const outdatedCount = Number(info.outdatedCount || 0);

		if (!schemaReady) {
			return {
				tone: 'warning',
				title: t('Upgrade still running'),
				body: t('Conflict caps cannot be refreshed until the server upgrade finishes.'),
				showApply: false,
				applyEnabled: false,
				outdatedCount: 0,
				openCount: 0,
			};
		}
		if (openCount <= 0) {
			return {
				tone: 'info',
				title: t('No open periods to update'),
				body: t('New periods will use the saved limits automatically.'),
				showApply: false,
				applyEnabled: false,
				outdatedCount: 0,
				openCount: 0,
			};
		}
		if (outdatedCount <= 0) {
			return {
				tone: 'success',
				title: t('Open periods already match these limits'),
				body: n('1 open period already uses the saved limits. Nothing to apply.',
					'All %n open periods already use the saved limits. Nothing to apply.', openCount),
				showApply: false,
				applyEnabled: false,
				outdatedCount: 0,
				openCount: openCount,
			};
		}
		return {
			tone: 'warning',
			title: t('Open periods still use older limits'),
			body: n('1 of {open} open periods still uses older caps. Apply to refresh it.',
				'%n of {open} open periods still use older caps. Apply to refresh them.', outdatedCount)
				.replace('{open}', String(openCount)),
			showApply: true,
			applyEnabled: true,
			outdatedCount: outdatedCount,
			openCount: openCount,
		};
	}

	window.DutyCheckConflictOpenStatus = {
		resolveConflictOpenCallout: resolveConflictOpenCallout,
	};
})();
