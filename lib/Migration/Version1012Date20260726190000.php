<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Wave C1 foundation — companies (workspaces) with legacy-safe default.
 * Existing rows get company_id=1 via column default. No silent multi-tenant merge.
 * Default company row is ensured by CompanyService::ensureDefaultCompany().
 */
class Version1012Date20260726190000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('dc_companies')) {
			$t = $schema->createTable('dc_companies');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('name', Types::STRING, ['length' => 120, 'notnull' => true]);
			$t->addColumn('active', Types::SMALLINT, ['notnull' => true, 'default' => 1]);
			$t->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'dc_co_pk');
			$t->addUniqueIndex(['name'], 'dc_co_name_uq');
		}

		if (!$schema->hasTable('dc_company_members')) {
			$t = $schema->createTable('dc_company_members');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('company_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('user_id', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->addColumn('role', Types::STRING, ['length' => 16, 'notnull' => true, 'default' => 'member']);
			$t->setPrimaryKey(['id'], 'dc_cm_pk');
			$t->addUniqueIndex(['company_id', 'user_id'], 'dc_cm_uq');
			$t->addIndex(['user_id'], 'dc_cm_uid_idx');
		}

		foreach (['dc_employees', 'dc_locations', 'dc_periods'] as $table) {
			if ($schema->hasTable($table)) {
				$t = $schema->getTable($table);
				if (!$t->hasColumn('company_id')) {
					$t->addColumn('company_id', Types::BIGINT, ['notnull' => true, 'default' => 1]);
					$idx = match ($table) {
						'dc_employees' => 'dc_emp_co_idx',
						'dc_locations' => 'dc_loc_co_idx',
						default => 'dc_per_co_idx',
					};
					if (!$t->hasIndex($idx)) {
						$t->addIndex(['company_id'], $idx);
					}
				}
			}
		}

		return $schema;
	}
}
