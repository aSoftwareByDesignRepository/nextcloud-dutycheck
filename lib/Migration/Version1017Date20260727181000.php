<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Unique index on slot_key after Version1016 backfill.
 * Index name ≤ 30 chars for Oracle-safe identifier limits.
 */
class Version1017Date20260727181000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('dc_assignments')) {
			$table = $schema->getTable('dc_assignments');
			if ($table->hasColumn('slot_key') && !$table->hasIndex('dc_asg_skey_uidx')) {
				$table->addUniqueIndex(['slot_key'], 'dc_asg_skey_uidx');
			}
		}

		return $schema;
	}
}
