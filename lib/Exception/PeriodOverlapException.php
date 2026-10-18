<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Exception;

/**
 * Thrown when a new period would overlap existing periods and the caller
 * did not pass allowOverlap. Carries the overlapping ranges so the client
 * can render them in the confirmation prompt.
 */
class PeriodOverlapException extends \InvalidArgumentException
{
	/**
	 * @param list<array{id:int,startDate:string,endDate:string}> $overlaps
	 */
	public function __construct(private array $overlaps)
	{
		parent::__construct('PERIOD_OVERLAP');
	}

	/**
	 * @return list<array{id:int,startDate:string,endDate:string}>
	 */
	public function getOverlaps(): array
	{
		return $this->overlaps;
	}
}
