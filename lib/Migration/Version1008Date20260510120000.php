<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Indexes aligned with hot queries and Nextcloud DB guidance (short names, PK on every table).
 *
 * - Assignments: sort/filter by period + date + time; unique slot prevents duplicate rows / races.
 * - Absences: overlap lookups filter by employee_id, status, and date range.
 *
 * @see https://docs.nextcloud.com/server/latest/developer_manual/basics/storage/database.html
 */
class Version1008Date20260510120000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('dc_assignments')) {
			$table = $schema->getTable('dc_assignments');
			if (!$table->hasIndex('dc_asg_per_day_idx')) {
				$table->addIndex(['period_id', 'duty_date', 'start_time'], 'dc_asg_per_day_idx');
			}
			if (!$table->hasIndex('dc_asg_slot_uidx')) {
				$table->addUniqueIndex(
					['period_id', 'employee_id', 'duty_date', 'start_time', 'end_time'],
					'dc_asg_slot_uidx',
				);
			}
		}

		if ($schema->hasTable('dc_absences')) {
			$table = $schema->getTable('dc_absences');
			if (!$table->hasIndex('dc_abs_emp_stat_idx')) {
				$table->addIndex(['employee_id', 'status', 'start_date'], 'dc_abs_emp_stat_idx');
			}
		}

		return $schema;
	}
}
