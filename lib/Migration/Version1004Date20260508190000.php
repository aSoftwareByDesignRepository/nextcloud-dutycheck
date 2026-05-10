<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version1004Date20260508190000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('dc_periods')) {
			$t = $schema->getTable('dc_periods');
			if (!$t->hasColumn('close_snapshot_id')) {
				$t->addColumn('close_snapshot_id', 'bigint', ['notnull' => false]);
			}
		}

		if (!$schema->hasTable('dc_roster_snapshots')) {
			$t = $schema->createTable('dc_roster_snapshots');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('period_id', 'bigint', ['notnull' => true]);
			$t->addColumn('snapshot_kind', 'string', ['length' => 16, 'notnull' => true]);
			$t->addColumn('snapshot_json', 'text', ['notnull' => true]);
			$t->addColumn('snapshot_hash', 'string', ['length' => 64, 'notnull' => true]);
			$t->addColumn('meta_json', 'text', ['notnull' => true]);
			$t->addColumn('prev_snapshot_id', 'bigint', ['notnull' => false]);
			$t->addColumn('prev_snapshot_hash', 'string', ['length' => 64, 'notnull' => false]);
			$t->addColumn('generated_at', 'datetime', ['notnull' => true]);
			$t->addColumn('generated_by', 'string', ['length' => 64, 'notnull' => true]);
			$t->setPrimaryKey(['id'], 'dc_snap_pk');
			$t->addIndex(['period_id', 'snapshot_kind', 'generated_at'], 'dc_snap_period_idx');
			$t->addIndex(['snapshot_hash'], 'dc_snap_hash_idx');
		}

		if (!$schema->hasTable('dc_period_audit_log')) {
			$t = $schema->createTable('dc_period_audit_log');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('period_id', 'bigint', ['notnull' => false]);
			$t->addColumn('actor_user_id', 'string', ['length' => 64, 'notnull' => true]);
			$t->addColumn('action', 'string', ['length' => 48, 'notnull' => true]);
			$t->addColumn('target_kind', 'string', ['length' => 32, 'notnull' => true]);
			$t->addColumn('target_id', 'bigint', ['notnull' => false]);
			$t->addColumn('payload_json', 'text', ['notnull' => false]);
			$t->addColumn('created_at', 'datetime', ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'dc_audit_pk');
			$t->addIndex(['period_id', 'created_at'], 'dc_audit_period_idx');
			$t->addIndex(['actor_user_id', 'created_at'], 'dc_audit_actor_idx');
			$t->addIndex(['action', 'created_at'], 'dc_audit_action_idx');
		}

		return $schema;
	}
}
