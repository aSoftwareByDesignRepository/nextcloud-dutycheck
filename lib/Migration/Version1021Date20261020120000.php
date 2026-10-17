<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Migration;

use Closure;
use OCA\DutyCheck\Service\AssignmentKind;
use OCA\DutyCheck\Service\AssignmentSlotKey;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
use OCP\Server;

/**
 * On-call / standby (Rufbereitschaft) foundation.
 *
 * - `dc_assignments.kind` ('shift'|'standby', default 'shift') — standby rows
 *   share the roster lifecycle but never produce working-time minutes.
 * - `standby_category_id` + `response_time_minutes` optional standby metadata.
 * - `dc_standby_categories` per-company label/color/default response time.
 * - `dc_asg_emp_day_kind_idx` supports kind-aware conflict and roster queries.
 * - postSchemaChange rewrites every active `slot_key` into the kind-aware
 *   format so the unique index stays authoritative after the format change.
 *
 * Identifier lengths stay ≤ 30 chars for Oracle-compatible Nextcloud setups.
 */
class Version1021Date20261020120000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('dc_assignments')) {
			$t = $schema->getTable('dc_assignments');
			if (!$t->hasColumn('kind')) {
				$t->addColumn('kind', Types::STRING, [
					'length' => 32,
					'notnull' => true,
					'default' => AssignmentKind::SHIFT,
				]);
			}
			if (!$t->hasColumn('standby_category_id')) {
				$t->addColumn('standby_category_id', Types::BIGINT, [
					'notnull' => false,
				]);
			}
			if (!$t->hasColumn('response_time_minutes')) {
				$t->addColumn('response_time_minutes', Types::INTEGER, [
					'notnull' => false,
				]);
			}
			if (!$t->hasIndex('dc_asg_emp_day_kind_idx')) {
				$t->addIndex(
					['employee_id', 'duty_date', 'kind'],
					'dc_asg_emp_day_kind_idx',
				);
			}
		}

		if (!$schema->hasTable('dc_standby_categories')) {
			$t = $schema->createTable('dc_standby_categories');
			$t->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
			]);
			$t->addColumn('company_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('label', Types::STRING, ['length' => 120, 'notnull' => true]);
			$t->addColumn('color', Types::STRING, ['length' => 7, 'notnull' => false]);
			$t->addColumn('default_response_time_minutes', Types::INTEGER, ['notnull' => false]);
			$t->addColumn('active', Types::SMALLINT, ['notnull' => true, 'default' => 1]);
			$t->setPrimaryKey(['id'], 'dc_sbcat_pk');
			$t->addIndex(['company_id', 'active'], 'dc_sbcat_co_act_idx');
		}

		return $schema;
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
	{
		$db = Server::get(IDBConnection::class);
		if (!$db->tableExists('dc_assignments')) {
			return;
		}

		$qb = $db->getQueryBuilder();
		$qb->select('id', 'period_id', 'employee_id', 'duty_date', 'start_time', 'end_time', 'status', 'kind')
			->from('dc_assignments');
		$result = $qb->executeQuery();
		$rows = $result->fetchAll();
		$result->closeCursor();

		$rewritten = 0;
		foreach ($rows as $row) {
			$id = (int) $row['id'];
			$status = (string) ($row['status'] ?? 'active');
			$slotKey = $status === 'cancelled'
				? AssignmentSlotKey::forCancelled($id)
				: AssignmentSlotKey::forActive(
					(int) $row['period_id'],
					(int) $row['employee_id'],
					(string) $row['duty_date'],
					(string) $row['start_time'],
					(string) $row['end_time'],
					(string) ($row['kind'] ?? AssignmentKind::SHIFT),
				);
			$upd = $db->getQueryBuilder();
			$upd->update('dc_assignments')
				->set('slot_key', $upd->createNamedParameter($slotKey))
				->where($upd->expr()->eq('id', $upd->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
				->executeStatement();
			$rewritten++;
		}

		$output->info('DutyCheck: rewrote kind-aware slot_key for ' . $rewritten . ' row(s).');
	}
}
