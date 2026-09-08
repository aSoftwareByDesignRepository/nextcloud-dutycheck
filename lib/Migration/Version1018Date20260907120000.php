<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Roster Self-Service GA — schema floor.
 *
 * Adds rotation patterns, preferences, blackouts, quiet-push queue,
 * company settings_json (legacy-safe quiet defaults), assignment source,
 * and widens swap status for bilateral flow.
 */
class Version1018Date20260907120000 extends SimpleMigrationStep
{
	public function __construct(
		private readonly IDBConnection $db,
	) {
	}

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('dc_companies')) {
			$t = $schema->getTable('dc_companies');
			if (!$t->hasColumn('settings_json')) {
				$t->addColumn('settings_json', Types::TEXT, ['notnull' => false]);
			}
		}

		if ($schema->hasTable('dc_assignments')) {
			$t = $schema->getTable('dc_assignments');
			if (!$t->hasColumn('source')) {
				$t->addColumn('source', Types::STRING, [
					'length' => 32,
					'notnull' => true,
					'default' => 'manual',
				]);
			}
		}

		if ($schema->hasTable('dc_swap_requests')) {
			$t = $schema->getTable('dc_swap_requests');
			if ($t->hasColumn('status')) {
				// Widen for accepted_by_counterparty / pending_planner / needs_planner / applied
				$t->changeColumn('status', ['length' => 32, 'notnull' => true, 'default' => 'pending']);
			}
			if (!$t->hasColumn('counterparty_at')) {
				$t->addColumn('counterparty_at', Types::DATETIME, ['notnull' => false]);
			}
			if (!$t->hasColumn('applied_at')) {
				$t->addColumn('applied_at', Types::DATETIME, ['notnull' => false]);
			}
			if (!$t->hasColumn('counter_assignment_id')) {
				// Optional second leg for true two-shift swaps (nullable = one-way transfer / pool).
				$t->addColumn('counter_assignment_id', Types::BIGINT, ['notnull' => false]);
			}
		}

		if (!$schema->hasTable('dc_rotation_patterns')) {
			$t = $schema->createTable('dc_rotation_patterns');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('company_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('name', Types::STRING, ['length' => 120, 'notnull' => true]);
			$t->addColumn('cycle_weeks', Types::SMALLINT, ['notnull' => true, 'default' => 2]);
			$t->addColumn('anchor_type', Types::STRING, ['length' => 32, 'notnull' => true, 'default' => 'iso_week_parity']);
			$t->addColumn('anchor_iso_week_index', Types::SMALLINT, ['notnull' => false]);
			$t->addColumn('anchor_date', Types::DATE, ['notnull' => false]);
			$t->addColumn('anchor_effective_from', Types::DATE, ['notnull' => false]);
			$t->addColumn('contract_avg_minutes', Types::INTEGER, ['notnull' => false]);
			$t->addColumn('is_active', Types::SMALLINT, ['notnull' => true, 'default' => 1]);
			$t->addColumn('created_by', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->addColumn('updated_by', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
			$t->addColumn('updated_at', Types::DATETIME, ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'dc_rotpat_pk');
			$t->addUniqueIndex(['company_id', 'name'], 'dc_rotpat_co_name');
			$t->addIndex(['company_id', 'is_active'], 'dc_rotpat_co_act');
		}

		if (!$schema->hasTable('dc_rotation_week_days')) {
			$t = $schema->createTable('dc_rotation_week_days');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('pattern_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('week_index', Types::SMALLINT, ['notnull' => true]);
			$t->addColumn('dow', Types::SMALLINT, ['notnull' => true]); // 1=Mon .. 7=Sun ISO
			$t->addColumn('is_working', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
			$t->addColumn('net_minutes', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$t->addColumn('shift_template_id', Types::BIGINT, ['notnull' => false]);
			$t->addColumn('start_local', Types::STRING, ['length' => 5, 'notnull' => false]);
			$t->addColumn('end_local', Types::STRING, ['length' => 5, 'notnull' => false]);
			$t->addColumn('break_minutes', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$t->addColumn('location_id', Types::BIGINT, ['notnull' => false]);
			$t->setPrimaryKey(['id'], 'dc_rotday_pk');
			$t->addUniqueIndex(['pattern_id', 'week_index', 'dow'], 'dc_rotday_pat_wd');
			$t->addIndex(['pattern_id'], 'dc_rotday_pat_idx');
		}

		if (!$schema->hasTable('dc_emp_rot_assign')) {
			$t = $schema->createTable('dc_emp_rot_assign');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('employee_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('pattern_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('company_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('valid_from', Types::DATE, ['notnull' => true]);
			$t->addColumn('valid_to', Types::DATE, ['notnull' => false]);
			$t->addColumn('created_by', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'dc_emprot_pk');
			$t->addUniqueIndex(['employee_id', 'valid_from'], 'dc_emprot_emp_from');
			$t->addIndex(['pattern_id'], 'dc_emprot_pat_idx');
			$t->addIndex(['company_id', 'employee_id'], 'dc_emprot_co_emp');
		}

		if (!$schema->hasTable('dc_shift_preferences')) {
			$t = $schema->createTable('dc_shift_preferences');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('company_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('employee_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('location_id', Types::BIGINT, ['notnull' => false]);
			$t->addColumn('band', Types::STRING, ['length' => 16, 'notnull' => true]); // early|mid|late|any
			$t->addColumn('weekday_mask', Types::SMALLINT, ['notnull' => true, 'default' => 127]); // bits 0..6 Mon..Sun
			$t->addColumn('priority', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
			$t->addColumn('valid_from', Types::DATE, ['notnull' => false]);
			$t->addColumn('valid_to', Types::DATE, ['notnull' => false]);
			$t->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
			$t->addColumn('updated_at', Types::DATETIME, ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'dc_pref_pk');
			$t->addIndex(['employee_id'], 'dc_pref_emp_idx');
			$t->addIndex(['company_id', 'employee_id'], 'dc_pref_co_emp');
		}

		if (!$schema->hasTable('dc_avail_blackouts')) {
			$t = $schema->createTable('dc_avail_blackouts');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('company_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('employee_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('location_id', Types::BIGINT, ['notnull' => false]);
			$t->addColumn('start_at', Types::DATETIME, ['notnull' => true]);
			$t->addColumn('end_at', Types::DATETIME, ['notnull' => true]);
			$t->addColumn('label_enum', Types::STRING, ['length' => 16, 'notnull' => true, 'default' => 'personal']);
			$t->addColumn('created_by', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
			$t->addColumn('updated_at', Types::DATETIME, ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'dc_blk_pk');
			$t->addIndex(['employee_id', 'start_at', 'end_at'], 'dc_blk_emp_span');
			$t->addIndex(['company_id', 'start_at'], 'dc_blk_co_start');
		}

		if (!$schema->hasTable('dc_push_quiet_queue')) {
			$t = $schema->createTable('dc_push_quiet_queue');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('user_id', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->addColumn('company_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('notif_type', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->addColumn('payload_hash', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->addColumn('payload_json', Types::TEXT, ['notnull' => true]);
			$t->addColumn('deliver_after', Types::DATETIME, ['notnull' => true]);
			$t->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
			$t->addColumn('attempts', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
			$t->addColumn('delivered_at', Types::DATETIME, ['notnull' => false]);
			$t->setPrimaryKey(['id'], 'dc_pq_pk');
			$t->addUniqueIndex(['user_id', 'payload_hash'], 'dc_pq_uid_hash');
			$t->addIndex(['deliver_after', 'delivered_at'], 'dc_pq_drain');
			$t->addIndex(['user_id', 'delivered_at'], 'dc_pq_user_pend');
		}

		if (!$schema->hasTable('dc_period_locks')) {
			// Suggest-fill / bulk mutex (period-scoped).
			$t = $schema->createTable('dc_period_locks');
			$t->addColumn('period_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('lock_kind', Types::STRING, ['length' => 32, 'notnull' => true]);
			$t->addColumn('holder', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->addColumn('acquired_at', Types::DATETIME, ['notnull' => true]);
			$t->addColumn('expires_at', Types::DATETIME, ['notnull' => true]);
			$t->setPrimaryKey(['period_id', 'lock_kind'], 'dc_plock_pk');
		}

		if (!$schema->hasTable('dc_blackout_overrides')) {
			$t = $schema->createTable('dc_blackout_overrides');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('company_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('assignment_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('blackout_id', Types::BIGINT, ['notnull' => false]);
			$t->addColumn('employee_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('reason', Types::STRING, ['length' => 200, 'notnull' => true]);
			$t->addColumn('created_by', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'dc_blkover_pk');
			$t->addIndex(['assignment_id'], 'dc_blkover_asg');
			$t->addIndex(['company_id', 'created_at'], 'dc_blkover_co_at');
		}

		return $schema;
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
	{
		// Existing companies: quiet hours OFF until admin opts in (D-23 / AC-H05-2/3).
		// New companies get quiet ON via SelfServiceSettingsService defaults at create time.
		if (!\OCA\DutyCheck\Db\SchemaProbe::tableExists($this->db, 'dc_companies')) {
			return;
		}
		$legacyQuiet = json_encode([
			'push_quiet_hours_enabled' => false,
			'push_quiet_hours_start' => '22:00',
			'push_quiet_hours_end' => '06:00',
			'push_allow_urgent_during_quiet' => false,
			'user_may_disable_quiet' => false,
			'_ga_quiet_seeded' => true,
		], JSON_THROW_ON_ERROR);

		$qb = $this->db->getQueryBuilder();
		$qb->update('dc_companies')
			->set('settings_json', $qb->createNamedParameter($legacyQuiet))
			->where($qb->expr()->isNull('settings_json'))
			->executeStatement();
	}
}
