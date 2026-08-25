<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Service;

/**
 * @psalm-type MobileDemoSeedResultArray = array{
 *   employeeUserId: string,
 *   unseatedUserId: string,
 *   employeeId: int,
 *   locationId: int,
 *   periodId: int,
 *   shiftDate: string,
 *   openShiftDate: string,
 *   assignmentId: int|null,
 *   openShiftId: int|null,
 *   periodStatus: string,
 *   licenseApplied: bool,
 *   seatAssigned: bool,
 * }
 */
final class MobileDemoSeedResult
{
	public function __construct(
		public readonly string $employeeUserId,
		public readonly string $unseatedUserId,
		public readonly int $employeeId,
		public readonly int $locationId,
		public readonly int $periodId,
		public readonly string $shiftDate,
		public readonly string $openShiftDate,
		public readonly ?int $assignmentId,
		public readonly ?int $openShiftId,
		public readonly string $periodStatus,
		public readonly bool $licenseApplied,
		public readonly bool $seatAssigned,
	) {
	}

	/**
	 * @return MobileDemoSeedResultArray
	 */
	public function toArray(): array
	{
		return [
			'employeeUserId' => $this->employeeUserId,
			'unseatedUserId' => $this->unseatedUserId,
			'employeeId' => $this->employeeId,
			'locationId' => $this->locationId,
			'periodId' => $this->periodId,
			'shiftDate' => $this->shiftDate,
			'openShiftDate' => $this->openShiftDate,
			'assignmentId' => $this->assignmentId,
			'openShiftId' => $this->openShiftId,
			'periodStatus' => $this->periodStatus,
			'licenseApplied' => $this->licenseApplied,
			'seatAssigned' => $this->seatAssigned,
		];
	}
}
