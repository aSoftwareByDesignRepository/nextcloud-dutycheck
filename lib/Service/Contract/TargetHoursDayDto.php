<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Service\Contract;

/** Day-level target hours from the Duty→AZC facade. */
final class TargetHoursDayDto
{
	public function __construct(
		public readonly string $ncUserId,
		public readonly string $date,
		public readonly int $netMinutes,
		public readonly bool $isWorking,
		public readonly int $weekIndex,
		public readonly string $weekLabel,
		public readonly string $basis,
	) {
	}

	/**
	 * @return array{ncUserId:string,date:string,netMinutes:int,isWorking:bool,weekIndex:int,weekLabel:string,basis:string}
	 */
	public function toArray(): array
	{
		return [
			'ncUserId' => $this->ncUserId,
			'date' => $this->date,
			'netMinutes' => $this->netMinutes,
			'isWorking' => $this->isWorking,
			'weekIndex' => $this->weekIndex,
			'weekLabel' => $this->weekLabel,
			'basis' => $this->basis,
		];
	}
}
