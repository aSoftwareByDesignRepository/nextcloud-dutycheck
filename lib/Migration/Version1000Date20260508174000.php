<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version1000Date20260508174000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('dc_user_roles')) {
			$t = $schema->createTable('dc_user_roles');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('user_id', 'string', ['length' => 64, 'notnull' => true]);
			$t->addColumn('role', 'string', ['length' => 16, 'notnull' => true]);
			$t->addColumn('created_at', 'datetime', ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'dc_urole_pk');
			$t->addUniqueIndex(['user_id'], 'dc_urole_uidx');
			$t->addIndex(['role'], 'dc_urole_role_idx');
		}

		return $schema;
	}
}
