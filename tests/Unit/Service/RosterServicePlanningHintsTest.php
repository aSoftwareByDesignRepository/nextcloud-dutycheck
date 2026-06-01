<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\Service;

use OCA\DutyCheck\Service\RosterService;
use PHPUnit\Framework\TestCase;

class RosterServicePlanningHintsTest extends TestCase
{
	public function testDateWithinInclusiveRange(): void
	{
		self::assertTrue(RosterService::dateWithinInclusiveRange('2026-06-15', '2026-06-01', '2026-06-30'));
		self::assertTrue(RosterService::dateWithinInclusiveRange('2026-06-01', '2026-06-01', '2026-06-30'));
		self::assertTrue(RosterService::dateWithinInclusiveRange('2026-06-30', '2026-06-01', '2026-06-30'));
		self::assertFalse(RosterService::dateWithinInclusiveRange('2026-05-31', '2026-06-01', '2026-06-30'));
		self::assertFalse(RosterService::dateWithinInclusiveRange('2026-07-01', '2026-06-01', '2026-06-30'));
	}
}
