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
	/**
	 * Kind-aware since the standby feature: a shift and a standby covering the
	 * exact same slot are distinct logical slots (allowed only with an explicit
	 * planner acknowledgement), while two rows of the same kind still collide.
	 * Existing keys were backfilled to this format by Version1021.
	 */
	public static function forActive(
		int $periodId,
		int $employeeId,
		string $dutyDate,
		string $startTime,
		string $endTime,
		string $kind = AssignmentKind::SHIFT,
	): string {
		return sprintf(
			'a:%d:%d:%s:%s:%s:%s',
			$periodId,
			$employeeId,
			$dutyDate,
			$startTime,
			$endTime,
			AssignmentKind::isValid($kind) ? $kind : AssignmentKind::SHIFT,
		);
	}

	public static function forCancelled(int $assignmentId): string
	{
		return 'c:' . $assignmentId;
	}
}
