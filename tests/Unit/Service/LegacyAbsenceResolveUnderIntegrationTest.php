<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\Service;

use OCA\DutyCheck\Service\RosterService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class LegacyAbsenceResolveUnderIntegrationTest extends TestCase
{
	public function testCancelAndRejectAreExemptFromReadonlyLock(): void
	{
		$path = (new ReflectionClass(RosterService::class))->getFileName();
		self::assertNotFalse($path);
		$src = (string) file_get_contents($path);
		$start = strpos($src, 'function assertIntegrationAllowsDcAbsenceForEmployee');
		self::assertNotFalse($start);
		$slice = substr($src, $start, 900);
		self::assertStringContainsString("in_array(\$targetStatus, ['cancelled', 'rejected'], true)", $slice);
		self::assertStringContainsString('INTEGRATION_ABSENCE_READONLY', $slice);
	}

	public function testTransitionPassesTargetStatusIntoGuard(): void
	{
		$path = (new ReflectionClass(RosterService::class))->getFileName();
		self::assertNotFalse($path);
		$src = (string) file_get_contents($path);
		self::assertStringContainsString(
			"assertIntegrationAllowsDcAbsenceForEmployee(\$current['employeeId'], \$targetStatus)",
			$src,
		);
	}

	public function testCreateStillCallsGuardWithoutCancelBypass(): void
	{
		$path = (new ReflectionClass(RosterService::class))->getFileName();
		self::assertNotFalse($path);
		$src = (string) file_get_contents($path);
		$start = strpos($src, 'function createAbsence(');
		self::assertNotFalse($start);
		$slice = substr($src, $start, 1200);
		self::assertStringContainsString('assertIntegrationAllowsDcAbsenceForEmployee($employeeId)', $slice);
		self::assertStringNotContainsString(", \$targetStatus)", $slice);
	}
}
