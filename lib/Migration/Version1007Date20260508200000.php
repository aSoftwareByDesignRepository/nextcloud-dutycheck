<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version1007Date20260508200000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('dc_conflicts')) {
			$t = $schema->createTable('dc_conflicts');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('period_id', 'bigint', ['notnull' => true]);
			$t->addColumn('assignment_id', 'bigint', ['notnull' => false]);
			$t->addColumn('secondary_assignment_id', 'bigint', ['notnull' => false]);
			$t->addColumn('employee_id', 'bigint', ['notnull' => true, 'default' => 0]);
			$t->addColumn('type', 'string', ['length' => 32, 'notnull' => true]);
			$t->addColumn('severity', 'string', ['length' => 8, 'notnull' => true]);
			$t->addColumn('detected_at', 'datetime', ['notnull' => true]);
			$t->addColumn('context_hash', 'string', ['length' => 64, 'notnull' => true]);
			$t->addColumn('payload_json', 'text', ['notnull' => false]);
			$t->addColumn('is_resolved', 'smallint', ['notnull' => true, 'default' => 0]);
			$t->addColumn('resolved_at', 'datetime', ['notnull' => false]);
			$t->addColumn('ack_user_id', 'string', ['length' => 64, 'notnull' => false]);
			$t->addColumn('ack_reason', 'text', ['notnull' => false]);
			$t->addColumn('ack_at', 'datetime', ['notnull' => false]);
			$t->addColumn('ack_context_hash', 'string', ['length' => 64, 'notnull' => false]);
			$t->setPrimaryKey(['id'], 'dc_conf_pk');
			$t->addIndex(['period_id', 'severity', 'is_resolved'], 'dc_conf_period_idx');
			$t->addIndex(['employee_id', 'period_id'], 'dc_conf_emp_period_idx');
			$t->addIndex(['type', 'period_id'], 'dc_conf_type_period_idx');
			$t->addIndex(['assignment_id'], 'dc_conf_asg_idx');
		}

		return $schema;
	}
}
