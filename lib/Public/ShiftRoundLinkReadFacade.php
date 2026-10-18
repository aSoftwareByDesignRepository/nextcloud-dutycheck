<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Public;

use OCA\DutyCheck\Db\SchemaProbe;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * F17 producer half — TourCheck annotates its missed-round notifications
 * and round views with the DutyCheck shift(s) linked to a round. Server-
 * side contract, never public HTTP. Read-only on `dc_assignments`; the
 * soft link itself is owned (and written) by DutyCheck.
 *
 * shiftsForRound(7) → [{assignmentId, periodId, employeeId, workDate, startTime, endTime}]
 */
class ShiftRoundLinkReadFacade
{
	public const FACADE_VERSION = 1;

	public function __construct(private IDBConnection $db)
	{
	}

	/**
	 * Duty shifts linked to a TourCheck round. Schema-probe fail-closed: a
	 * partially-upgraded DutyCheck (column absent) returns [] rather than
	 * exploding inside the sibling's notification path.
	 *
	 * @return list<array{assignmentId:int, periodId:int, employeeId:int, workDate:string, startTime:string, endTime:string}>
	 */
	public function shiftsForRound(int $roundId): array
	{
		if ($roundId <= 0) {
			return [];
		}
		try {
			if (!SchemaProbe::hasColumn($this->db, 'dc_assignments', 'tck_round_id')) {
				return [];
			}
			$qb = $this->db->getQueryBuilder();
			$qb->select('id', 'period_id', 'employee_id', 'work_date', 'start_time', 'end_time')
				->from('dc_assignments')
				->where($qb->expr()->eq('tck_round_id', $qb->createNamedParameter($roundId, IQueryBuilder::PARAM_INT)))
				->setMaxResults(50);
			$out = [];
			foreach ($qb->executeQuery()->fetchAll() as $row) {
				$out[] = [
					'assignmentId' => (int)$row['id'],
					'periodId' => (int)$row['period_id'],
					'employeeId' => (int)$row['employee_id'],
					'workDate' => (string)$row['work_date'],
					'startTime' => (string)$row['start_time'],
					'endTime' => (string)$row['end_time'],
				];
			}
			return $out;
		} catch (\Throwable) {
			return [];
		}
	}
}
