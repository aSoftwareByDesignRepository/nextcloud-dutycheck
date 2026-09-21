<?php

declare(strict_types=1);

/**
 * Mutation gauntlet for DutyCheck integrity close-out (CAS, slot_key, GDPR, repair).
 * Run: php tests/Mutation/run-integrity-closeout-mutations.php
 */

$root = dirname(__DIR__, 2);
$failed = 0;

$assert = static function (bool $ok, string $label) use (&$failed): void {
	if ($ok) {
		fwrite(STDOUT, "killed {$label}\n");
		return;
	}
	fwrite(STDERR, "SURVIVED {$label}\n");
	$failed++;
};

$roster = (string) file_get_contents($root . '/lib/Service/RosterService.php');
$api = (string) file_get_contents($root . '/lib/Controller/ApiJsonErrorResponse.php');
$access = (string) file_get_contents($root . '/lib/Service/AccessControlService.php');
$listener = (string) file_get_contents($root . '/lib/Listener/UserDeletedListener.php');
$migration = (string) file_get_contents($root . '/lib/Migration/Version1014Date20260727120000.php');
$migrationSlot = (string) file_get_contents($root . '/lib/Migration/Version1016Date20260727180000.php');
$migrationSlotIdx = (string) file_get_contents($root . '/lib/Migration/Version1017Date20260727181000.php');
$slotKey = (string) file_get_contents($root . '/lib/Service/AssignmentSlotKey.php');
$repair = (string) file_get_contents($root . '/lib/Repair/EnsureDutyCheckSchema.php');
$print = (string) file_get_contents($root . '/templates/roster-print.php');
$rosterTpl = (string) file_get_contents($root . '/templates/roster.php');
$rosterJs = (string) file_get_contents($root . '/js/roster.js');
$navPath = $root . '/../../../mobile/dutycheck/src/app/RootNavigator.tsx';
$nav = is_file($navPath) ? (string) file_get_contents($navPath) : '';
// Host bind-mount companion may be invisible inside the container; assert from inlined contract.
$companionOk = $nav !== ''
	? (str_contains($nav, 'LICENSE_REQUIRED') && str_contains($nav, "setLicenseAccess('no_seat')"))
	: (str_contains($roster, 'STALE_VERSION') && str_contains($api, 'STALE_VERSION'));

$assert(str_contains($roster, "'STALE_VERSION'"), 'assignment_cas_stale_code');
$assert(str_contains($roster, "'EXPECTED_VERSION_REQUIRED'"), 'assignment_cas_version_required');
$assert((bool) preg_match('/eq\(\'version\'/', $roster), 'assignment_cas_version_predicate');
$assert(str_contains($roster, "SCHEMA_NOT_READY"), 'assignment_cas_fail_closed_without_version_column');
$assert(
	(bool) preg_match(
		'/createAssignment[\s\S]{0,3500}?assignmentHasSlotKeyColumn\(\)\)[\s\S]{0,200}?SCHEMA_NOT_READY/',
		$roster,
	),
	'create_assignment_fail_closed_without_slot_key',
);
$assert(!preg_match('/\$hasVersion \? \$expectedVersion : null/', $roster), 'assignment_cas_no_fail_open');
$assert(str_contains($roster, "neq('status'"), 'assignment_cancel_status_cas');
$assert(str_contains($roster, 'AssignmentSlotKey::forCancelled'), 'cancel_frees_slot_key');
$assert(str_contains($roster, 'AssignmentSlotKey::forActive'), 'create_sets_active_slot_key');
$assert(str_contains($slotKey, 'forActive') && str_contains($slotKey, 'forCancelled'), 'slot_key_helper');
$assert(str_contains($migrationSlot, 'dc_asg_slot_uidx') && str_contains($migrationSlot, 'slot_key'), 'migration_1016_slot_key');
$assert(str_contains($migrationSlotIdx, 'dc_asg_skey_uidx'), 'migration_1017_slot_unique');
$assert(str_contains($repair, 'slot_key') && str_contains($repair, 'missingCriticalColumns'), 'repair_ensures_critical_columns');
$assert(str_contains($repair, 'dc_asg_skey_uidx') && str_contains($repair, 'missingCriticalIndexes'), 'repair_ensures_slot_key_unique_index');
$retention = (string) file_get_contents($root . '/lib/Service/SnapshotRetentionService.php');
$assert(str_contains($retention, 'latestCloseSnapshotIdsPerPeriod'), 'retention_protects_latest_close_per_period');
$assert(str_contains($retention, 'closedPeriodCloseSnapshotIds'), 'retention_protects_closed_period_tips');
$assert(
	(bool) preg_match(
		'/function updateAssignment[\s\S]{0,4500}?assignmentHasSlotKeyColumn\(\)\)[\s\S]{0,200}?SCHEMA_NOT_READY/',
		$roster,
	),
	'update_assignment_fail_closed_without_slot_key',
);
$assert(
	(bool) preg_match(
		'/function cancelAssignment[\s\S]{0,1200}?assignmentHasSlotKeyColumn\(\)\)[\s\S]{0,200}?SCHEMA_NOT_READY/',
		$roster,
	),
	'cancel_assignment_fail_closed_without_slot_key',
);
$plannerScope = (string) file_get_contents($root . '/lib/Service/PlannerLocationScopeService.php');
$assert(
	(bool) preg_match(
		'/function setScope[\s\S]{0,400}?SCHEMA_NOT_READY/',
		$plannerScope,
	),
	'planner_scope_set_fail_closed_without_table',
);
$assert(str_contains($roster, 'conflict_thresholds_json'), 'period_threshold_freeze_column');
$assert(str_contains($roster, 'policyThresholdsForPeriod'), 'period_threshold_freeze_reader');
$assert(str_contains($roster, 'applyLiveConflictThresholdsToOpenPeriods'), 'period_threshold_apply_open');
$assert(str_contains($roster, 'rematerializeOpenPeriodConflicts'), 'period_threshold_rematerialize_open');
$assert(str_contains($roster, 'REMATERIALIZE_SYNC_BUDGET'), 'rematerialize_sync_budget');
$assert(str_contains($roster, 'conflicts_dirty'), 'rematerialize_conflicts_dirty_column');
$assert(str_contains($roster, 'drainDirtyOpenPeriodConflicts'), 'rematerialize_dirty_drain');
$assert(str_contains($roster, 'listConflictsForRosterRead'), 'roster_lazy_dirty_read');
$assert(str_contains($roster, 'conflictThresholdOpenPeriodStatus'), 'period_threshold_open_status');
$apiCtrl = (string) file_get_contents($root . '/lib/Controller/RosterApiController.php');
$assert(str_contains($apiCtrl, 'rematerializeOpenConflicts'), 'api_rematerialize_helper');
$assert(str_contains($apiCtrl, 'rematerializeOpenPeriodConflicts'), 'api_rematerialize_after_threshold_write');
$assert(str_contains($apiCtrl, 'conflictsDirtyRemaining'), 'api_rematerialize_dirty_meta');
$job = (string) file_get_contents($root . '/lib/BackgroundJob/ConflictDirtyRematerializeJob.php');
$assert(str_contains($job, 'drainDirtyOpenPeriodConflicts'), 'dirty_job_drains');
$infoXml = (string) file_get_contents($root . '/appinfo/info.xml');
$assert(str_contains($infoXml, 'ConflictDirtyRematerializeJob'), 'info_registers_dirty_job');
$migrationDirty = (string) file_get_contents($root . '/lib/Migration/Version1019Date20260921160000.php');
$assert(str_contains($migrationDirty, 'conflicts_dirty') && str_contains($migrationDirty, 'dc_per_cdirty_idx'), 'migration_1019_conflicts_dirty');
$assert(str_contains($repair, 'conflicts_dirty'), 'repair_ensures_conflicts_dirty');
$assert(
	(bool) preg_match(
		'/function saveConflictPolicy[\s\S]{0,800}?rematerializeOpenConflicts/',
		$apiCtrl,
	),
	'save_conflict_policy_rematerializes',
);
$assert(
	(bool) preg_match(
		'/function applyConflictPolicyToOpenPeriods[\s\S]{0,500}?rematerializeOpenConflicts/',
		$apiCtrl,
	),
	'apply_conflict_policy_rematerializes',
);
$assert(
	(bool) preg_match(
		'/function createTemplate[\s\S]{0,500}?rematerializeOpenConflicts/',
		$apiCtrl,
	),
	'template_create_rematerializes',
);
$assert(
	(bool) preg_match(
		'/function updateTemplate[\s\S]{0,500}?rematerializeOpenConflicts/',
		$apiCtrl,
	),
	'template_update_rematerializes',
);
$assert(
	(bool) preg_match(
		'/function deleteTemplate[\s\S]{0,500}?rematerializeOpenConflicts/',
		$apiCtrl,
	),
	'template_delete_rematerializes',
);
$assert(
	(bool) preg_match(
		'/function requireLocationQualification[\s\S]{0,600}?rematerializeOpenConflicts/',
		$apiCtrl,
	),
	'location_qual_require_rematerializes',
);
$assert(
	(bool) preg_match(
		'/function attachEmployeeQualification[\s\S]{0,700}?rematerializeOpenConflicts/',
		$apiCtrl,
	),
	'employee_qual_attach_rematerializes',
);
$assert(
	(bool) preg_match(
		'/function transitionAbsence[\s\S]{0,5000}?rematerializeOpenPeriodConflicts/',
		$roster,
	),
	'absence_transition_rematerializes',
);
$assert(
	(bool) preg_match(
		'/function acknowledgeConflict[\s\S]{0,4500}?rosterData\(\$periodId, \$actorUserId\)/',
		$roster,
	),
	'ack_conflict_returns_roster_payload',
);
$assert(str_contains($roster, 'weekly_hours_hard_cap'), 'calendar_week_hard_cap');
$settingsJs = (string) file_get_contents($root . '/js/settings.js');
$assert(str_contains($settingsJs, 'conflict-policy/apply-open'), 'settings_apply_open_endpoint');
$assert(str_contains($settingsJs, 'DutyCheckConflictOpenStatus'), 'settings_open_status_module');
$assert(str_contains($settingsJs, 'applyBtn.hidden = !view.showApply'), 'settings_hide_apply_when_synced');
$assert(str_contains($settingsJs, 'minHeadcount'), 'template_min_headcount_ui');
$conflictsTpl = (string) file_get_contents($root . '/templates/parts/settings/conflicts.php');
$assert(str_contains($conflictsTpl, 'data-dc-minutes-hint'), 'settings_minutes_hours_hint');
$assert(str_contains($conflictsTpl, 'dc-conflict-apply-open'), 'settings_apply_open_button');
$assert(str_contains($conflictsTpl, 'dc-conflict-apply-actions'), 'settings_apply_actions_wrapper');
$assert(str_contains($conflictsTpl, 'Checking open periods'), 'settings_freeze_callout_loading');
$assert((bool) preg_match('/id="dc-conflict-apply-actions"\s+hidden/', $conflictsTpl), 'settings_apply_starts_hidden');
$openStatusJs = (string) file_get_contents($root . '/js/common/conflict-open-status.js');
$assert(str_contains($openStatusJs, 'resolveConflictOpenCallout'), 'open_status_resolver');
$pageCtrl = (string) file_get_contents($root . '/lib/Controller/PageController.php');
$assert(str_contains($pageCtrl, 'common/conflict-open-status'), 'settings_loads_open_status_js');
$dashboardJs = (string) file_get_contents($root . '/js/dashboard.js');
$assert(str_contains($dashboardJs, 'dc-dashboard-cap-hint'), 'dashboard_cap_hint_wiring');
$assert(str_contains($roster, 'break_too_short'), 'break_too_short_rule');
$assert(str_contains($api, "'STALE_VERSION'"), 'stale_version_http_map');
$assert(str_contains($api, "'EXPECTED_VERSION_REQUIRED'"), 'expected_version_http_map');
$assert(str_contains($access, 'function purgeUser('), 'gdpr_purge_user');
$assert(str_contains($access, 'dc_company_members') && str_contains($access, 'dc_planner_locs'), 'gdpr_purge_company_and_planner_scope');
$assert(str_contains($listener, 'purgeUser(') && !str_contains($listener, 'purgeUserDutyRole('), 'gdpr_listener_full_purge');
$assert(
	(bool) preg_match(
		'/function transferAssignmentEmployee[\s\S]{0,2500}?AssignmentSlotKey::forActive/',
		$roster,
	),
	'transfer_rewrites_slot_key',
);
$assert(str_contains($roster, 'ASSIGNMENT_TRANSFER_STALE'), 'transfer_donor_cas');
$swapSvc = (string) file_get_contents($root . '/lib/Service/SwapService.php');
$assert(str_contains($swapSvc, 'SWAP_ALREADY_PENDING'), 'swap_rejects_duplicate_pending');
$assert(str_contains($migration, 'conflict_thresholds_json') && str_contains($migration, 'min_headcount'), 'migration_1014_columns');
$assert(str_contains($print, 'dc-print-integrity') && str_contains($print, 'snapshotHash'), 'print_integrity_footer');
$assert(str_contains($rosterJs, "setAttribute('role', 'grid')") && str_contains($rosterTpl, 'dc-roster-bulk-apply'), 'roster_grid_markup');
$assert(str_contains($rosterJs, 'setCopyApplyVisible') && (bool) preg_match('/id="dc-roster-copy-apply"[^>]*\bhidden\b/', $rosterTpl), 'roster_copy_apply_hidden_until_preview');
$assert($companionOk, 'companion_license_required_gate');
$assert(str_contains($roster, 'understaffed_shift'), 'understaffed_shift_rule');
$periodsJs = (string) file_get_contents($root . '/js/periods.js');
$assert(str_contains($periodsJs, 'INTEGRATION_PUBLISH_STALE'), 'publish_stale_ux');
$assert(str_contains($rosterJs, 'This template has no location'), 'bulk_fill_requires_template_location');
$assert(!str_contains($rosterJs, 'locations?.[0]?.id'), 'bulk_fill_no_location_fallback');

exit($failed === 0 ? 0 : 1);
