<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Exception;

class ConflictAckRequiredException extends \RuntimeException
{
	/**
	 * @param list<array<string,mixed>> $conflicts
	 */
	public function __construct(private array $conflicts)
	{
		parent::__construct('CONFLICT_ACK_REQUIRED');
	}

	/**
	 * @return list<array<string,mixed>>
	 */
	public function getConflicts(): array
	{
		return $this->conflicts;
	}
}
