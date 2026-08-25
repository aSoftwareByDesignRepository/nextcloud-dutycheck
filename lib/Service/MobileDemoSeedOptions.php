<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Service;

/**
 * Inputs for {@see MobileDemoSeedService} — Play reviewer + local mobile QA.
 */
final class MobileDemoSeedOptions
{
	public const DEFAULT_EMPLOYEE_USER = 'dc.review.employee';
	public const DEFAULT_EMPLOYEE_PASSWORD = 'DcReviewEmployee2026!';
	public const DEFAULT_UNSEATED_USER = 'dc.review.noseat';
	public const DEFAULT_UNSEATED_PASSWORD = 'DcReviewNoSeat2026!';
	public const DEFAULT_CUSTOMER_ID = 'demo-dutycheck-mobile';
	public const DEFAULT_EMPLOYEE_NAME = 'Play Review Employee';
	public const DEFAULT_LOCATION_NAME = 'Play Review Station';

	public function __construct(
		public readonly string $adminUserId = 'admin',
		public readonly string $employeeUserId = self::DEFAULT_EMPLOYEE_USER,
		public readonly string $employeePassword = self::DEFAULT_EMPLOYEE_PASSWORD,
		public readonly string $unseatedUserId = self::DEFAULT_UNSEATED_USER,
		public readonly string $unseatedPassword = self::DEFAULT_UNSEATED_PASSWORD,
		public readonly string $customerId = self::DEFAULT_CUSTOMER_ID,
		public readonly int $mobileSeats = 10,
		public readonly string $validUntil = '2027-12-31',
		/** Pre-minted DTY2 wire key; when null the seeder tries sbdlicenseops. */
		public readonly ?string $licenseWireKey = null,
		public readonly string $employeeDisplayName = self::DEFAULT_EMPLOYEE_NAME,
		public readonly string $locationName = self::DEFAULT_LOCATION_NAME,
		public readonly string $timezone = 'Europe/Berlin',
	) {
		if ($this->mobileSeats < 1) {
			throw new \InvalidArgumentException('mobileSeats must be >= 1');
		}
		if (trim($this->employeeUserId) === '' || trim($this->unseatedUserId) === '') {
			throw new \InvalidArgumentException('Demo user ids must be non-empty');
		}
		if ($this->employeeUserId === $this->unseatedUserId) {
			throw new \InvalidArgumentException('Employee and unseated demo users must differ');
		}
	}
}
