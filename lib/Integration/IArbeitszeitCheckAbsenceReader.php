<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Integration;

/**
 * Read-only access to ArbeitszeitCheck absence rows (for tests: mock this instead of the concrete reader).
 */
interface IArbeitszeitCheckAbsenceReader
{
	/**
	 * @param list<string> $userIds Nextcloud UIDs (batch)
	 * @return list<array<string,mixed>> normalized rows
	 */
	public function listAbsencesOverlapping(
		array $userIds,
		string $fromYmd,
		string $toYmd,
	): array;
}
