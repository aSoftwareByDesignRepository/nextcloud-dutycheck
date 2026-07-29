<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Integration;

interface IArbeitszeitCheckAbsenceReader
{
	/**
	 * @param list<string> $userIds Nextcloud UIDs (batch ≤ IntegrationOpsConstants::RD_BATCH_USER_CHUNK)
	 * @return list<array<string,mixed>> normalized rows
	 */
	public function listAbsencesOverlapping(
		array $userIds,
		string $fromYmd,
		string $toYmd,
		?AbsenceReadOptions $options = null,
	): array;
}
