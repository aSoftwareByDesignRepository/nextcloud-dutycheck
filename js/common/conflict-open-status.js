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
				body: t('All {count} open period(s) already use the saved limits. Nothing to apply.')
					.replace('{count}', String(openCount)),
				showApply: false,
				applyEnabled: false,
				outdatedCount: 0,
				openCount: openCount,
			};
		}
		return {
			tone: 'warning',
			title: t('Open periods still use older limits'),
			body: t('{outdated} of {open} open period(s) still use older caps. Apply to refresh them.')
				.replace('{outdated}', String(outdatedCount))
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
