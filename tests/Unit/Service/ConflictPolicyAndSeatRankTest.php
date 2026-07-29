<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\Service;

use OCA\DutyCheck\Service\ConflictPolicyService;
use OCA\DutyCheck\Service\SeatRank;
use PHPUnit\Framework\TestCase;

final class ConflictPolicyDefaultsTest extends TestCase
{
	public function testDefaultsMatchLegacyHardcodedCaps(): void
	{
		$d = ConflictPolicyService::defaults();
		self::assertSame(600, $d['maxDailyHard']);
		self::assertSame(2880, $d['maxPeriodSoft']);
		self::assertSame(3600, $d['maxPeriodHard']);
		self::assertSame(6, $d['maxConsecutiveDays']);
		self::assertSame(660, $d['minRestMinutes']);
	}
}

final class SeatRankTest extends TestCase
{
	public function testWithinLimitUsesAssignmentOrder(): void
	{
		$ranked = [
			['id' => 1, 'assignedAt' => 100],
			['id' => 2, 'assignedAt' => 200],
			['id' => 3, 'assignedAt' => 300],
		];
		self::assertTrue(SeatRank::isWithinLimit($ranked, 1, 2));
		self::assertTrue(SeatRank::isWithinLimit($ranked, 2, 2));
		self::assertFalse(SeatRank::isWithinLimit($ranked, 3, 2));
	}
}
