<?php

declare(strict_types=1);

/**
 * DutyCheck transfer manifest — every appconfig key and every dc_* table
 * classified exactly once
 * (gate: scripts/check-config-transfer-manifest.py).
 */

namespace OCA\DutyCheck\ConfigTransfer;

final class TransferManifest extends AbstractTransferManifest
{
	private const SCHEMA_VERSION = 1;

	public function schemaVersion(): int
	{
		return self::SCHEMA_VERSION;
	}

	/** @return array<string,array> */
	public function keys(): array
	{
		return self::normaliseKeys([
			// ── access door / admin policy — written atomically through
			// AccessControlService::saveAppPolicy (never raw setAppValue;
			// the restricted-door-requires-allowlist invariant is cross-key) ──
			'access_restriction_enabled' => ['class' => 'mirrored', 'security' => true, 'section' => 'access'],
			'access_allowed_user_ids' => ['class' => 'mirrored', 'security' => true, 'refs' => ['kind' => 'uid_list_json'], 'section' => 'access'],
			'access_allowed_group_ids' => ['class' => 'mirrored', 'security' => true, 'refs' => ['kind' => 'gid_list_json'], 'section' => 'access'],
			'app_admin_user_ids' => ['class' => 'mirrored', 'security' => true, 'refs' => ['kind' => 'uid_list_json'], 'section' => 'access'],
			// ── planning defaults (Settings → Planning) ──
			'default_break_minutes' => ['class' => 'plain', 'section' => 'planning'],
			// ── operations (Settings → Notifications & retention) ──
			'threshold_approach_notify' => ['class' => 'plain', 'section' => 'operations'],
			'threshold_approach_ratio' => ['class' => 'plain', 'section' => 'operations'],
			'mc_onduty_hook_enabled' => ['class' => 'plain', 'section' => 'operations'],
			'snapshot_retention_days' => ['class' => 'plain', 'section' => 'operations'],
			// Widening the HR roster-minutes export is a privacy-relevant
			// grant (payroll-adjacent data leaves the app) → highlighted.
			'hr_roster_minutes_export_enabled' => ['class' => 'plain', 'security' => true, 'section' => 'operations'],
			// ── ArbeitszeitCheck integration (Settings → Integration) ──
			// include_pii widens personal data flowing into the mirror —
			// security-relevant grant → highlighted in the report.
			'integration_at_intent_enabled' => ['class' => 'plain', 'section' => 'integration'],
			'integration_arbeitszeitcheck_enabled' => ['class' => 'plain', 'section' => 'integration'],
			'integration_arbeitszeitcheck_include_pii' => ['class' => 'plain', 'security' => true, 'section' => 'integration'],
			'block_publish_when_stale' => ['class' => 'plain', 'section' => 'integration'],
			'integration_t_stale_seconds' => ['class' => 'plain', 'section' => 'integration'],
			'integration_t_stale_publish_block_seconds' => ['class' => 'plain', 'section' => 'integration'],
			// ── internal bookkeeping — never transferred ──
			'uninstall_repair_pass' => 'internal',
			'upgrade_backup_last_snapshot_id' => 'internal',
			'upgrade_backup_max_snapshots' => 'internal',
			'transfer_receipts' => 'internal',
			'transfer_job_state' => 'internal',
			'transfer_last_import_at' => 'internal',
			// Integration runtime state — sync lease, circuit breaker, error
			// counters and cooldown timestamps are per-instance, never policy.
			'integration_at_last_reconcile_at' => 'internal',
			'integration_at_sync_lease' => 'internal',
			'integration_at_breaker_until' => 'internal',
			'integration_at_err_window_start' => 'internal',
			'integration_at_err_count' => 'internal',
			'integration_at_breaker_trips' => 'internal',
			'integration_at_detection_fail_at' => 'internal',
			'integration_at_purge_legacy_cooldown' => 'internal',
			'integration_at_sync_rl_instance' => 'internal',
			// Dynamic/prefix key families — prefixes, not keys; members are
			// dynamic and never transferred by definition:
			//   'integration_at_sync_rl_a_*'  per-admin sync rate-limit buckets
			//   'thresh_appr_*'               per-day threshold-notify markers
			//   'upgrade_backup_*'            snapshot-restore rewrites restored keys
			//   'dutycheck-upgrade-backup'    ILockingProvider lock name, not appconfig
		]);
	}

	/** @return array<string,array> */
	public function tables(): array
	{
		return self::normaliseTables([
			// ══ org structure + catalogs ══
			'dc_companies' => ['class' => 'catalog', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['name']],
			'dc_locations' => ['class' => 'catalog', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['company_id', 'name'],
				'fk' => ['company_id' => 'dc_companies'], 'depends_on' => ['dc_companies']],
			// Qualification names are globally unique (dc_qual_name_uq).
			'dc_qualifications' => ['class' => 'catalog', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['name'],
				'fk' => ['company_id' => 'dc_companies'], 'depends_on' => ['dc_companies']],
			'dc_standby_categories' => ['class' => 'catalog', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['company_id', 'label'],
				'fk' => ['company_id' => 'dc_companies'], 'depends_on' => ['dc_companies']],
			// location_id is nullable (a template may be company-wide).
			'dc_shift_templates' => ['class' => 'catalog', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['company_id', 'location_id', 'name'],
				'fk' => ['company_id' => 'dc_companies', 'location_id' => 'dc_locations'],
				'depends_on' => ['dc_companies', 'dc_locations']],
			'dc_rotation_patterns' => ['class' => 'catalog', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['company_id', 'name'],
				'fk' => ['company_id' => 'dc_companies'], 'depends_on' => ['dc_companies']],
			'dc_rotation_week_days' => ['class' => 'catalog', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['pattern_id', 'week_index', 'dow'],
				'fk' => [
					'pattern_id' => 'dc_rotation_patterns',
					'shift_template_id' => 'dc_shift_templates',
					'location_id' => 'dc_locations',
				],
				'depends_on' => ['dc_rotation_patterns', 'dc_shift_templates', 'dc_locations']],
			'dc_loc_quals' => ['class' => 'catalog', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['location_id', 'qualification_id'],
				'fk' => ['location_id' => 'dc_locations', 'qualification_id' => 'dc_qualifications'],
				'depends_on' => ['dc_locations', 'dc_qualifications']],
			// Singleton policy row — nk 'id' matches the app's single row on
			// merge (upsert) instead of seed-if-empty, which would never
			// update an existing policy, or wipe-load, which is replace-only.
			'dc_conflict_policy' => ['class' => 'catalog', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['id']],

			// ══ people ══
			// Employee catalog records: (company, display_name) is the human
			// identity. linked_user_id keeps ''→null; a non-empty uid that does
			// not exist on the target skips the row (fail closed — a ghost
			// link is a worse lie than a reported skip; dependents cascade).
			'dc_employees' => ['class' => 'business', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['company_id', 'display_name'],
				'fk' => ['company_id' => 'dc_companies'], 'depends_on' => ['dc_companies'],
				'uid_ref' => ['linked_user_id'],
				'uid_ref_nullable' => ['linked_user_id']],
			'dc_company_members' => ['class' => 'user-data', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['company_id', 'user_id'],
				'fk' => ['company_id' => 'dc_companies'], 'depends_on' => ['dc_companies'],
				'uid_ref' => ['user_id']],
			// Duty roles are authorization — security-relevant rows that still
			// travel only for users present on both instances (D1).
			'dc_user_roles' => ['class' => 'user-data', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['user_id'],
				'uid_ref' => ['user_id']],
			'dc_planner_locs' => ['class' => 'user-data', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['user_id', 'location_id'],
				'fk' => ['location_id' => 'dc_locations'], 'depends_on' => ['dc_locations'],
				'uid_ref' => ['user_id']],
			'dc_user_preferences' => ['class' => 'user-data', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['user_id', 'pref_key'],
				'uid_ref' => ['user_id']],

			// ══ planning data ══
			// close_snapshot_id → dc_roster_snapshots is derived (snapshot_json
			// embeds source-row ids that no remap can fix) → env-dropped:
			// NULL on insert, untouched on update.
			'dc_periods' => ['class' => 'business', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['company_id', 'start_date', 'end_date'],
				'fk' => ['company_id' => 'dc_companies'], 'depends_on' => ['dc_companies'],
				'env_columns' => ['close_snapshot_id']],
			'dc_emp_quals' => ['class' => 'user-data', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['employee_id', 'qualification_id'],
				'fk' => ['employee_id' => 'dc_employees', 'qualification_id' => 'dc_qualifications'],
				'depends_on' => ['dc_employees', 'dc_qualifications']],
			'dc_emp_rot_assign' => ['class' => 'business', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['employee_id', 'valid_from'],
				'fk' => [
					'employee_id' => 'dc_employees',
					'pattern_id' => 'dc_rotation_patterns',
					'company_id' => 'dc_companies',
				],
				'depends_on' => ['dc_employees', 'dc_rotation_patterns', 'dc_companies']],
			'dc_shift_preferences' => ['class' => 'user-data', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['employee_id', 'location_id', 'band', 'weekday_mask', 'valid_from'],
				'fk' => [
					'company_id' => 'dc_companies',
					'employee_id' => 'dc_employees',
					'location_id' => 'dc_locations',
				],
				'depends_on' => ['dc_companies', 'dc_employees', 'dc_locations']],
			'dc_avail_blackouts' => ['class' => 'user-data', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['employee_id', 'location_id', 'start_at', 'end_at'],
				'fk' => [
					'company_id' => 'dc_companies',
					'employee_id' => 'dc_employees',
					'location_id' => 'dc_locations',
				],
				'depends_on' => ['dc_companies', 'dc_employees', 'dc_locations']],
			// nk is slot_key — the app's designed portable unique identity.
			// The logical slot is NOT unique: cancelled tombstones share it
			// (slot_key 'c:<id>'), so column-based keys mis-address children.
			// slot_key embeds the SOURCE period/employee ids ('a:<p>:<e>:…'
			// or 'c:<id>'); it carries verbatim — the engine has no
			// derived-column recompute seam. Uniqueness within the file and
			// vs existing target rows still holds; the residual is that
			// same-slot duplicate detection against NEW rows degrades until
			// the app rewrites keys (see audit note).
			// tck_round_id references TourCheck (foreign app) → env-dropped.
			'dc_assignments' => ['class' => 'business', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['slot_key'],
				'fk' => [
					'period_id' => 'dc_periods',
					'employee_id' => 'dc_employees',
					'location_id' => 'dc_locations',
					'standby_category_id' => 'dc_standby_categories',
				],
				'depends_on' => ['dc_periods', 'dc_employees', 'dc_locations', 'dc_standby_categories'],
				'env_columns' => ['tck_round_id']],
			// created_by/reviewed_by are provenance — carried verbatim like
			// every *_by column below: a ghost uid degrades a label, never
			// sinks a row.
			'dc_absences' => ['class' => 'business', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['employee_id', 'kind', 'start_date', 'end_date'],
				'fk' => ['employee_id' => 'dc_employees', 'company_id' => 'dc_companies'],
				'depends_on' => ['dc_employees', 'dc_companies']],
			'dc_open_shifts' => ['class' => 'business', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['period_id', 'location_id', 'duty_date', 'start_time', 'end_time'],
				'fk' => [
					'period_id' => 'dc_periods',
					'location_id' => 'dc_locations',
					'template_id' => 'dc_shift_templates',
					'claimed_by_emp' => 'dc_employees',
					'assignment_id' => 'dc_assignments',
					'company_id' => 'dc_companies',
				],
				'depends_on' => ['dc_periods', 'dc_locations', 'dc_shift_templates', 'dc_employees', 'dc_assignments', 'dc_companies']],
			'dc_swap_requests' => ['class' => 'business', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['assignment_id', 'from_employee_id', 'to_employee_id', 'created_at'],
				'fk' => [
					'assignment_id' => 'dc_assignments',
					'from_employee_id' => 'dc_employees',
					'to_employee_id' => 'dc_employees',
					'counter_assignment_id' => 'dc_assignments',
					'company_id' => 'dc_companies',
				],
				'depends_on' => ['dc_assignments', 'dc_employees', 'dc_companies']],
			'dc_blackout_overrides' => ['class' => 'business', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['assignment_id', 'blackout_id'],
				'fk' => [
					'assignment_id' => 'dc_assignments',
					'blackout_id' => 'dc_avail_blackouts',
					'employee_id' => 'dc_employees',
					'company_id' => 'dc_companies',
				],
				'depends_on' => ['dc_assignments', 'dc_avail_blackouts', 'dc_employees', 'dc_companies']],

			// ══ derived / internal / log — NEVER transferred ══
			// Mirror of the peer app's absences (at_absence_id + payload_hash
			// are foreign-instance ids) — reconcile rebuilds it on the target.
			'dc_at_absence_mirror' => ['class' => 'derived'],
			// Computed from roster state — payload_json/context_hash embed
			// source assignment ids; ConflictDirtyRematerializeJob regenerates.
			'dc_conflicts' => ['class' => 'derived'],
			// Publish/close artifacts — snapshot_json embeds source employee/
			// assignment ids; republish/re-close regenerates them.
			'dc_roster_snapshots' => ['class' => 'derived'],
			// Device/license pairing + vendor signature are bound to this
			// instance — never transferable (payload_b64/signature_b64 are
			// secrets on top).
			'dc_license_state' => ['class' => 'internal'],
			'dc_mobile_seats' => ['class' => 'internal'],
			// Pending push queue — device-bound delivery state.
			'dc_push_quiet_queue' => ['class' => 'internal'],
			'dc_api_rate_limits' => ['class' => 'internal'],
			'dc_period_locks' => ['class' => 'internal'],
			'dc_period_audit_log' => ['class' => 'log'],
		]);
	}
}
