<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud DB-Standards (auto-generated)
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Tables that must exist after dutycheck's current migrations ran —
 * every table ever created minus those intentionally dropped by later
 * migrations. Schema-repair (Ensure*Schema) and schema-readiness probes
 * use EXPECTED_TABLES; uninstall drop lists use UninstallDropTables::TABLES
 * (which deliberately keeps dropped legacy tables for complete cleanup).
 *
 * Regenerate via:
 *     php scripts/check-nextcloud-db-standards.php sync-uninstall --app=dutycheck
 */
namespace OCA\DutyCheck\Repair;

final class SchemaTableCatalog
{
	/** @var list<string> */
	public const EXPECTED_TABLES = [
		'dc_absences',
		'dc_api_rate_limits',
		'dc_assignments',
		'dc_at_absence_mirror',
		'dc_avail_blackouts',
		'dc_blackout_overrides',
		'dc_companies',
		'dc_company_members',
		'dc_conflict_policy',
		'dc_conflicts',
		'dc_emp_quals',
		'dc_emp_rot_assign',
		'dc_employees',
		'dc_license_state',
		'dc_loc_quals',
		'dc_locations',
		'dc_mobile_seats',
		'dc_open_shifts',
		'dc_period_audit_log',
		'dc_period_locks',
		'dc_periods',
		'dc_planner_locs',
		'dc_push_quiet_queue',
		'dc_qualifications',
		'dc_roster_snapshots',
		'dc_rotation_patterns',
		'dc_rotation_week_days',
		'dc_shift_preferences',
		'dc_shift_templates',
		'dc_standby_categories',
		'dc_swap_requests',
		'dc_user_preferences',
		'dc_user_roles',
	];

	/** @var list<string> tables dropped intentionally by later migrations */
	public const DROPPED_LEGACY_TABLES = [

	];
}
