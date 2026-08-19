<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\Service;

use OCA\DutyCheck\Service\RosterService;
use PHPUnit\Framework\TestCase;

/**
 * Contract tests for Wave A0/A message honesty and acknowledge API codes.
 */
final class RosterWaveContractTest extends TestCase
{
	public function testConflictMessagesUsePeriodTotalNotWeeklyLie(): void
	{
		$keys = RosterService::rosterApiConflictMessageKeys();
		self::assertContains('Period total hard cap exceeded for employee', $keys);
		self::assertContains('Period total soft cap exceeded for employee', $keys);
		foreach ($keys as $key) {
			self::assertStringNotContainsString('Weekly hard', $key);
			self::assertStringNotContainsString('Weekly soft', $key);
		}
	}

	public function testApiJsonMapsAcknowledgeErrors(): void
	{
		self::assertSame(404, \OCA\DutyCheck\Controller\ApiJsonErrorResponse::statusForInvalidArgument('ASSIGNMENT_NOT_FOUND'));
		self::assertSame(422, \OCA\DutyCheck\Controller\ApiJsonErrorResponse::statusForInvalidArgument('PERIOD_NOT_PUBLISHED'));
		self::assertSame(422, \OCA\DutyCheck\Controller\ApiJsonErrorResponse::statusForInvalidArgument('ASSIGNMENT_CANCELLED'));
		self::assertSame(422, \OCA\DutyCheck\Controller\ApiJsonErrorResponse::statusForInvalidArgument('QUALIFICATION_MISSING'));
		self::assertSame(403, \OCA\DutyCheck\Controller\ApiJsonErrorResponse::statusForInvalidArgument('FORBIDDEN'));
		self::assertSame(403, \OCA\DutyCheck\Controller\ApiJsonErrorResponse::statusForInvalidArgument('COMPANY_MISMATCH'));
		self::assertSame(403, \OCA\DutyCheck\Controller\ApiJsonErrorResponse::statusForInvalidArgument('COMPANY_MEMBERSHIP_REQUIRED'));
		self::assertSame(409, \OCA\DutyCheck\Controller\ApiJsonErrorResponse::statusForInvalidArgument('CONFLICT_ACK_STALE'));
		self::assertSame(409, \OCA\DutyCheck\Controller\ApiJsonErrorResponse::statusForInvalidArgument('PERIOD_STATUS_CONFLICT'));
		self::assertSame(409, \OCA\DutyCheck\Controller\ApiJsonErrorResponse::statusForInvalidArgument('ABSENCE_STATUS_CONFLICT'));
		self::assertSame(422, \OCA\DutyCheck\Controller\ApiJsonErrorResponse::statusForInvalidArgument('SWAP_CONFLICT'));
		self::assertContains(
			'Employee is missing a required qualification for this location',
			RosterService::rosterApiConflictMessageKeys(),
		);
	}
}
