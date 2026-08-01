<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
use OCP\Server;
use OCA\DutyCheck\Service\AssignmentSlotKey;

/**
 * Soft-cancel must free the assignment slot for recreate.
 *
 * Replaces the status-blind unique index on (period, employee, date, start, end)
 * with a portable `slot_key` unique column:
 * - active → `a:{period}:{employee}:{date}:{start}:{end}`
 * - cancelled → `c:{id}`
 */
class Version1016Date20260727180000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('dc_assignments')) {
			$table = $schema->getTable('dc_assignments');
			if (!$table->hasColumn('slot_key')) {
				// Temporary default; postSchemaChange backfills real keys before 1017 unique index.
				$table->addColumn('slot_key', Types::STRING, [
					'notnull' => true,
					'length' => 96,
					'default' => '',
				]);
			}
			if ($table->hasIndex('dc_asg_slot_uidx')) {
				$table->dropIndex('dc_asg_slot_uidx');
			}
			// Keep a non-unique lookup index for roster day queries (replaces uniqueness duty).
			if (!$table->hasIndex('dc_asg_slot_idx')) {
				$table->addIndex(
					['period_id', 'employee_id', 'duty_date', 'start_time', 'end_time'],
					'dc_asg_slot_idx',
				);
			}
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
		$qb->select('id', 'period_id', 'employee_id', 'duty_date', 'start_time', 'end_time', 'status')
			->from('dc_assignments');
		$result = $qb->executeQuery();
		$rows = $result->fetchAll();
		$result->closeCursor();

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
				);
			$upd = $db->getQueryBuilder();
			$upd->update('dc_assignments')
				->set('slot_key', $upd->createNamedParameter($slotKey))
				->where($upd->expr()->eq('id', $upd->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
				->executeStatement();
		}

		$output->info('DutyCheck: backfilled assignment slot_key for ' . count($rows) . ' row(s).');
	}
}
