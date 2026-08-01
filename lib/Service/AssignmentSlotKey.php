<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Service;

/**
 * Portable unique slot identity for duty assignments.
 *
 * Soft-cancelled rows must free the logical slot so the same employee/date/times
 * can be recreated. Encoding cancelled rows as `c:{id}` keeps uniqueness across
 * MySQL/MariaDB/PostgreSQL/SQLite (and Oracle) without filtered unique indexes.
 */
final class AssignmentSlotKey
{
	public static function forActive(
		int $periodId,
		int $employeeId,
		string $dutyDate,
		string $startTime,
		string $endTime,
	): string {
		return sprintf(
			'a:%d:%d:%s:%s:%s',
			$periodId,
			$employeeId,
			$dutyDate,
			$startTime,
			$endTime,
		);
	}

	public static function forCancelled(int $assignmentId): string
	{
		return 'c:' . $assignmentId;
	}
}
