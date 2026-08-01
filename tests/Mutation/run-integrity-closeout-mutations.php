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
$assert(str_contains($roster, 'weekly_hours_hard_cap'), 'calendar_week_hard_cap');
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
$assert(str_contains($rosterTpl, 'role="grid"') && str_contains($rosterTpl, 'dc-roster-bulk-apply'), 'roster_grid_markup');
$assert($companionOk, 'companion_license_required_gate');
$assert(str_contains($roster, 'understaffed_shift'), 'understaffed_shift_rule');
$settingsJs = (string) file_get_contents($root . '/js/settings.js');
$assert(str_contains($settingsJs, 'minHeadcount'), 'template_min_headcount_ui');
$periodsJs = (string) file_get_contents($root . '/js/periods.js');
$assert(str_contains($periodsJs, 'INTEGRATION_PUBLISH_STALE'), 'publish_stale_ux');
$rosterJs = (string) file_get_contents($root . '/js/roster.js');
$assert(str_contains($rosterJs, 'This template has no location'), 'bulk_fill_requires_template_location');
$assert(!str_contains($rosterJs, 'locations?.[0]?.id'), 'bulk_fill_no_location_fallback');

exit($failed === 0 ? 0 : 1);
