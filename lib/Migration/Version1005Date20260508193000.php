<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version1005Date20260508193000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('dc_user_preferences')) {
			$t = $schema->createTable('dc_user_preferences');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('user_id', 'string', ['length' => 64, 'notnull' => true]);
			$t->addColumn('pref_key', 'string', ['length' => 64, 'notnull' => true]);
			$t->addColumn('value_json', 'text', ['notnull' => true]);
			$t->addColumn('updated_at', 'datetime', ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'dc_upref_pk');
			$t->addUniqueIndex(['user_id', 'pref_key'], 'dc_upref_user_key_uidx');
		}

		return $schema;
	}
}
