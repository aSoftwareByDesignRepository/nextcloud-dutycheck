<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * ArbeitszeitCheck read-only absence mirror (DutyCheck-owned).
 *
 * @see pm/app-ideas/dutycheck/arbeitszeitcheck-integration.md
 */
class Version1010Date20260511120000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('dc_at_absence_mirror')) {
			$t = $schema->createTable('dc_at_absence_mirror');
			$t->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
				'length' => 20,
			]);
			$t->addColumn('linked_user_id', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);
			$t->addColumn('at_absence_id', Types::BIGINT, [
				'notnull' => true,
				'length' => 20,
			]);
			$t->addColumn('start_date', Types::DATE, ['notnull' => true]);
			$t->addColumn('end_date', Types::DATE, ['notnull' => true]);
			$t->addColumn('type', Types::STRING, ['notnull' => true, 'length' => 32]);
			$t->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 32]);
			$t->addColumn('payload_hash', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('last_seen_at', Types::DATETIME, ['notnull' => true]);
			$t->addColumn('source_updated_at', Types::DATETIME, ['notnull' => false]);
			$t->setPrimaryKey(['id'], 'dc_atmir_pk');
			$t->addUniqueIndex(['at_absence_id'], 'dc_atmir_atid_uidx');
			$t->addIndex(['linked_user_id'], 'dc_atmir_uid_idx');
			$t->addIndex(['linked_user_id', 'start_date', 'end_date'], 'dc_atmir_uid_range_idx');
		}

		return $schema;
	}
}
