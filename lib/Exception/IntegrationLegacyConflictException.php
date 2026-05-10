<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Exception;

/**
 * Raised when enabling integration or linking employees would leave conflicting
 * legacy `dc_absences` rows that must be resolved first.
 */
class IntegrationLegacyConflictException extends \InvalidArgumentException
{
	public function __construct(private int $legacyAbsenceCount)
	{
		parent::__construct('INTEGRATION_LEGACY_CONFLICT');
	}

	public function getLegacyAbsenceCount(): int
	{
		return $this->legacyAbsenceCount;
	}
}
