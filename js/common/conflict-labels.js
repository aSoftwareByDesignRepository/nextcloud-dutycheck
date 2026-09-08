(function () {
	'use strict';

	/**
	 * Plain-language labels for planning issues (severity hard/soft in API data).
	 * Shared by Roster, Dashboard, and Periods so wording stays consistent.
	 */
	function severityLabel(severity) {
		const sev = String(severity || 'info');
		if (sev === 'hard') {
			return t('dutycheck', 'Must fix');
		}
		if (sev === 'soft') {
			return t('dutycheck', 'Confirm to continue');
		}
		return t('dutycheck', 'Notice');
	}

	function countsSummary(mustFix, confirm, pendingOpen) {
		return t('dutycheck', '{mustFix} must fix · {confirm} confirm to continue ({pending} open)')
			.replace('{mustFix}', String(mustFix))
			.replace('{confirm}', String(confirm))
			.replace('{pending}', String(pendingOpen));
	}

	function pulseTitle(mustFix, pendingConfirm) {
		if (mustFix > 0) {
			return t('dutycheck', '“Must fix” issues block publishing.');
		}
		if (pendingConfirm > 0) {
			return t('dutycheck', 'Some items still need your confirmation.');
		}
		return t('dutycheck', 'No open planning issues in the active period.');
	}

	function publishReadinessLine(canPublish, mustFix, confirm, pendingOpen, integrationStale) {
		const hard = Number(mustFix) || 0;
		const soft = Number(confirm) || 0;
		const pending = Number(pendingOpen) || 0;
		if (canPublish || (hard === 0 && !integrationStale)) {
			// Never paint “Publishing blocked: 0 …” — zero hard issues means ready copy.
			return t('dutycheck', 'Ready to publish: {mustFix} must fix · {confirm} confirm to continue ({pending} open)')
				.replace('{mustFix}', String(hard))
				.replace('{confirm}', String(soft))
				.replace('{pending}', String(pending));
		}
		if (integrationStale) {
			return t('dutycheck', 'Publishing blocked: ArbeitszeitCheck absences are stale or the sync breaker is open. Sync in Settings, then try again.');
		}
		return t('dutycheck', 'Publishing blocked: {mustFix} “must fix” issue(s) remain')
			.replace('{mustFix}', String(hard));
	}

	window.DutyCheckConflictLabels = {
		severityLabel,
		countsSummary,
		pulseTitle,
		publishReadinessLine,
	};
})();
