<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Service\Contract;

/**
 * Weekly target hours DTO returned by {@see EffectiveTargetHoursFacade}.
 * Read-only; never includes other employees' data or notes PII.
 */
final class TargetHoursWeekDto
{
	public function __construct(
		public readonly string $ncUserId,
		public readonly string $isoWeekStart,
		public readonly int $requiredNetMinutes,
		public readonly int $weekIndex,
		public readonly string $weekLabel,
		public readonly string $basis,
		public readonly int $patternId,
		public readonly ?string $patternName = null,
	) {
	}

	/**
	 * @return array{ncUserId:string,isoWeekStart:string,requiredNetMinutes:int,weekIndex:int,weekLabel:string,basis:string,patternId:int,patternName:?string}
	 */
	public function toArray(): array
	{
		return [
			'ncUserId' => $this->ncUserId,
			'isoWeekStart' => $this->isoWeekStart,
			'requiredNetMinutes' => $this->requiredNetMinutes,
			'weekIndex' => $this->weekIndex,
			'weekLabel' => $this->weekLabel,
			'basis' => $this->basis,
			'patternId' => $this->patternId,
			'patternName' => $this->patternName,
		];
	}
}
