<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Wave A0/A/B + companion S0 schema:
 * assignment status/ack, shift templates, conflict policy, qualifications,
 * planner location scope, swap/open-shift, DTY2 license tables.
 */
class Version1011Date20260726180000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('dc_assignments')) {
			$t = $schema->getTable('dc_assignments');
			if (!$t->hasColumn('status')) {
				$t->addColumn('status', Types::STRING, ['length' => 16, 'notnull' => true, 'default' => 'active']);
			}
			if (!$t->hasColumn('cancelled_at')) {
				$t->addColumn('cancelled_at', Types::DATETIME, ['notnull' => false]);
			}
			if (!$t->hasColumn('cancelled_by')) {
				$t->addColumn('cancelled_by', Types::STRING, ['length' => 64, 'notnull' => false]);
			}
			if (!$t->hasColumn('acknowledged_at')) {
				$t->addColumn('acknowledged_at', Types::DATETIME, ['notnull' => false]);
			}
			if (!$t->hasColumn('acknowledged_by')) {
				$t->addColumn('acknowledged_by', Types::STRING, ['length' => 64, 'notnull' => false]);
			}
			if (!$t->hasIndex('dc_asg_status_idx')) {
				$t->addIndex(['status'], 'dc_asg_status_idx');
			}
		}

		if (!$schema->hasTable('dc_shift_templates')) {
			$t = $schema->createTable('dc_shift_templates');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('location_id', Types::BIGINT, ['notnull' => false]);
			$t->addColumn('name', Types::STRING, ['length' => 120, 'notnull' => true]);
			$t->addColumn('start_time', Types::STRING, ['length' => 5, 'notnull' => true]);
			$t->addColumn('end_time', Types::STRING, ['length' => 5, 'notnull' => true]);
			$t->addColumn('break_minutes', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$t->addColumn('active', Types::SMALLINT, ['notnull' => true, 'default' => 1]);
			$t->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
			$t->addColumn('updated_at', Types::DATETIME, ['notnull' => false]);
			$t->setPrimaryKey(['id'], 'dc_tmpl_pk');
			$t->addIndex(['location_id', 'active'], 'dc_tmpl_loc_idx');
			$t->addIndex(['name'], 'dc_tmpl_name_idx');
		}

		if (!$schema->hasTable('dc_conflict_policy')) {
			$t = $schema->createTable('dc_conflict_policy');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('max_daily_hard', Types::INTEGER, ['notnull' => true, 'default' => 600]);
			$t->addColumn('max_period_soft', Types::INTEGER, ['notnull' => true, 'default' => 2880]);
			$t->addColumn('max_period_hard', Types::INTEGER, ['notnull' => true, 'default' => 3600]);
			$t->addColumn('max_consec_days', Types::INTEGER, ['notnull' => true, 'default' => 6]);
			$t->addColumn('min_rest_minutes', Types::INTEGER, ['notnull' => true, 'default' => 660]);
			$t->addColumn('updated_at', Types::DATETIME, ['notnull' => false]);
			$t->addColumn('updated_by', Types::STRING, ['length' => 64, 'notnull' => false]);
			$t->setPrimaryKey(['id'], 'dc_cpol_pk');
		}

		if (!$schema->hasTable('dc_qualifications')) {
			$t = $schema->createTable('dc_qualifications');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('name', Types::STRING, ['length' => 120, 'notnull' => true]);
			$t->addColumn('code', Types::STRING, ['length' => 64, 'notnull' => false]);
			$t->addColumn('active', Types::SMALLINT, ['notnull' => true, 'default' => 1]);
			$t->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'dc_qual_pk');
			$t->addUniqueIndex(['name'], 'dc_qual_name_uq');
		}

		if (!$schema->hasTable('dc_emp_quals')) {
			$t = $schema->createTable('dc_emp_quals');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('employee_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('qualification_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('expires_on', Types::DATE, ['notnull' => false]);
			$t->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'dc_eq_pk');
			$t->addUniqueIndex(['employee_id', 'qualification_id'], 'dc_eq_uq');
			$t->addIndex(['qualification_id'], 'dc_eq_qual_idx');
		}

		if (!$schema->hasTable('dc_loc_quals')) {
			$t = $schema->createTable('dc_loc_quals');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('location_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('qualification_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('required', Types::SMALLINT, ['notnull' => true, 'default' => 1]);
			$t->setPrimaryKey(['id'], 'dc_lq_pk');
			$t->addUniqueIndex(['location_id', 'qualification_id'], 'dc_lq_uq');
		}

		if (!$schema->hasTable('dc_planner_locs')) {
			$t = $schema->createTable('dc_planner_locs');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('user_id', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->addColumn('location_id', Types::BIGINT, ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'dc_ploc_pk');
			$t->addUniqueIndex(['user_id', 'location_id'], 'dc_ploc_uq');
			$t->addIndex(['user_id'], 'dc_ploc_uid_idx');
		}

		if (!$schema->hasTable('dc_open_shifts')) {
			$t = $schema->createTable('dc_open_shifts');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('period_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('location_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('template_id', Types::BIGINT, ['notnull' => false]);
			$t->addColumn('duty_date', Types::DATE, ['notnull' => true]);
			$t->addColumn('start_time', Types::STRING, ['length' => 5, 'notnull' => true]);
			$t->addColumn('end_time', Types::STRING, ['length' => 5, 'notnull' => true]);
			$t->addColumn('break_minutes', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$t->addColumn('status', Types::STRING, ['length' => 16, 'notnull' => true, 'default' => 'open']);
			$t->addColumn('claimed_by_emp', Types::BIGINT, ['notnull' => false]);
			$t->addColumn('assignment_id', Types::BIGINT, ['notnull' => false]);
			$t->addColumn('created_by', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'dc_os_pk');
			$t->addIndex(['period_id', 'status'], 'dc_os_per_idx');
			$t->addIndex(['duty_date'], 'dc_os_day_idx');
		}

		if (!$schema->hasTable('dc_swap_requests')) {
			$t = $schema->createTable('dc_swap_requests');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('assignment_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('from_employee_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('to_employee_id', Types::BIGINT, ['notnull' => false]);
			$t->addColumn('status', Types::STRING, ['length' => 16, 'notnull' => true, 'default' => 'pending']);
			$t->addColumn('reason', Types::STRING, ['length' => 512, 'notnull' => false]);
			$t->addColumn('review_reason', Types::STRING, ['length' => 512, 'notnull' => false]);
			$t->addColumn('reviewed_by', Types::STRING, ['length' => 64, 'notnull' => false]);
			$t->addColumn('reviewed_at', Types::DATETIME, ['notnull' => false]);
			$t->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'dc_swap_pk');
			$t->addIndex(['status'], 'dc_swap_status_idx');
			$t->addIndex(['assignment_id'], 'dc_swap_asg_idx');
		}

		if (!$schema->hasTable('dc_license_state')) {
			$t = $schema->createTable('dc_license_state');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('customer_id', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->addColumn('issued_at', Types::STRING, ['length' => 10, 'notnull' => true]);
			$t->addColumn('valid_until', Types::STRING, ['length' => 10, 'notnull' => true]);
			$t->addColumn('mobile_seats', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$t->addColumn('payload_b64', Types::TEXT, ['notnull' => true]);
			$t->addColumn('signature_b64', Types::STRING, ['length' => 255, 'notnull' => true]);
			$t->addColumn('applied_at', Types::INTEGER, ['notnull' => true, 'unsigned' => true, 'default' => 0]);
			$t->addColumn('applied_by', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->setPrimaryKey(['id'], 'dc_lic_pk');
		}

		if (!$schema->hasTable('dc_mobile_seats')) {
			$t = $schema->createTable('dc_mobile_seats');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('uid', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->addColumn('assigned_at', Types::INTEGER, ['notnull' => true, 'unsigned' => true, 'default' => 0]);
			$t->addColumn('assigned_by', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->setPrimaryKey(['id'], 'dc_seat_pk');
			$t->addUniqueIndex(['uid'], 'dc_seat_uid_uq');
		}

		return $schema;
	}
}
