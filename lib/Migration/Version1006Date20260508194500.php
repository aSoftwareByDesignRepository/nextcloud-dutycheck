<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version1006Date20260508194500 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('dc_api_rate_limits')) {
			$t = $schema->createTable('dc_api_rate_limits');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('bucket_key', 'string', ['length' => 191, 'notnull' => true]);
			$t->addColumn('created_at', 'datetime', ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'dc_rate_pk');
			$t->addIndex(['bucket_key', 'created_at'], 'dc_rate_bucket_idx');
			$t->addIndex(['created_at'], 'dc_rate_created_idx');
		}

		return $schema;
	}
}
