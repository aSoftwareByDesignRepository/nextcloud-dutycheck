<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version1001Date20260508190000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('dc_employees')) {
			$t = $schema->createTable('dc_employees');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('display_name', 'string', ['length' => 191, 'notnull' => true]);
			$t->addColumn('linked_user_id', 'string', ['length' => 64, 'notnull' => false]);
			$t->addColumn('active', 'smallint', ['default' => 1, 'notnull' => true]);
			$t->addColumn('created_at', 'datetime', ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'dc_emp_pk');
			$t->addUniqueIndex(['linked_user_id'], 'dc_emp_uidx');
			$t->addIndex(['active'], 'dc_emp_active_idx');
		}

		if (!$schema->hasTable('dc_locations')) {
			$t = $schema->createTable('dc_locations');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('name', 'string', ['length' => 191, 'notnull' => true]);
			$t->addColumn('timezone', 'string', ['length' => 64, 'notnull' => true, 'default' => 'Europe/Berlin']);
			$t->addColumn('active', 'smallint', ['default' => 1, 'notnull' => true]);
			$t->addColumn('created_at', 'datetime', ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'dc_loc_pk');
			$t->addIndex(['active'], 'dc_loc_active_idx');
		}

		if (!$schema->hasTable('dc_periods')) {
			$t = $schema->createTable('dc_periods');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('start_date', 'date', ['notnull' => true]);
			$t->addColumn('end_date', 'date', ['notnull' => true]);
			$t->addColumn('status', 'string', ['length' => 16, 'notnull' => true, 'default' => 'open']);
			$t->addColumn('created_by', 'string', ['length' => 64, 'notnull' => true]);
			$t->addColumn('created_at', 'datetime', ['notnull' => true]);
			$t->addColumn('published_at', 'datetime', ['notnull' => false]);
			$t->addColumn('closed_at', 'datetime', ['notnull' => false]);
			$t->setPrimaryKey(['id'], 'dc_per_pk');
			$t->addIndex(['status'], 'dc_per_status_idx');
			$t->addIndex(['start_date', 'end_date'], 'dc_per_range_idx');
		}

		if (!$schema->hasTable('dc_absences')) {
			$t = $schema->createTable('dc_absences');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('employee_id', 'bigint', ['notnull' => true]);
			$t->addColumn('kind', 'string', ['length' => 32, 'notnull' => true]);
			$t->addColumn('start_date', 'date', ['notnull' => true]);
			$t->addColumn('end_date', 'date', ['notnull' => true]);
			$t->addColumn('status', 'string', ['length' => 16, 'notnull' => true, 'default' => 'pending']);
			$t->addColumn('created_by', 'string', ['length' => 64, 'notnull' => true]);
			$t->addColumn('created_at', 'datetime', ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'dc_abs_pk');
			$t->addIndex(['employee_id'], 'dc_abs_emp_idx');
			$t->addIndex(['status'], 'dc_abs_status_idx');
			$t->addIndex(['start_date', 'end_date'], 'dc_abs_range_idx');
		}

		if (!$schema->hasTable('dc_assignments')) {
			$t = $schema->createTable('dc_assignments');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('period_id', 'bigint', ['notnull' => true]);
			$t->addColumn('employee_id', 'bigint', ['notnull' => true]);
			$t->addColumn('location_id', 'bigint', ['notnull' => true]);
			$t->addColumn('duty_date', 'date', ['notnull' => true]);
			$t->addColumn('start_time', 'string', ['length' => 5, 'notnull' => true]);
			$t->addColumn('end_time', 'string', ['length' => 5, 'notnull' => true]);
			$t->addColumn('break_minutes', 'integer', ['notnull' => true, 'default' => 0]);
			$t->addColumn('note', 'string', ['length' => 512, 'notnull' => false]);
			$t->addColumn('created_by', 'string', ['length' => 64, 'notnull' => true]);
			$t->addColumn('created_at', 'datetime', ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'dc_asg_pk');
			$t->addIndex(['period_id'], 'dc_asg_period_idx');
			$t->addIndex(['employee_id', 'duty_date'], 'dc_asg_emp_day_idx');
			$t->addIndex(['location_id', 'duty_date'], 'dc_asg_loc_day_idx');
		}

		return $schema;
	}
}
