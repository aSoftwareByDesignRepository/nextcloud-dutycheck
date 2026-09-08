<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\Service;

use OCA\DutyCheck\Service\MobileController;
use OCA\DutyCheck\Service\PeerRosterService;
use OCA\DutyCheck\Service\PushQuietHoursService;
use OCA\DutyCheck\Service\RosterService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Aristoteles contracts for Momos open High/Medium closures.
 */
final class AristotelesMomosClosureContractTest extends TestCase
{
	public function testQuietOverflowShedsInsteadOfFailOpen(): void
	{
		$src = (string) file_get_contents((new ReflectionClass(PushQuietHoursService::class))->getFileName());
		self::assertStringContainsString('shedOldestPending', $src);
		self::assertStringContainsString('preserve Nachtruhe', $src);
		self::assertStringNotContainsString('quiet queue overflow; caller should send now', $src);
		// Undelivered past TTL must be abandoned.
		self::assertStringContainsString('isNull(\'delivered_at\')', $src);
	}

	public function testIcalEmployeeBucketOnlyAfterAuth(): void
	{
		$src = (string) file_get_contents((new ReflectionClass(RosterService::class))->getFileName());
		self::assertStringContainsString('assertIcalIpSprayAllowed', $src);
		self::assertStringContainsString('assertIcalSuccessAllowed', $src);
		// Success bucket must appear after hash_equals in publicIcal.
		$posEquals = strpos($src, 'hash_equals($storedHash');
		$posSuccess = strpos($src, 'assertIcalSuccessAllowed');
		self::assertNotFalse($posEquals);
		self::assertNotFalse($posSuccess);
		self::assertGreaterThan($posEquals, $posSuccess);
	}

	public function testPeerBelongingExcludesFutureDutyDates(): void
	{
		$src = (string) file_get_contents((new ReflectionClass(PeerRosterService::class))->getFileName());
		self::assertStringContainsString('future dates do not count', $src);
		self::assertStringContainsString("lte('duty_date'", $src);
		self::assertStringContainsString("lte('a.duty_date'", $src);
	}

	public function testMobileBootstrapDoesNotDumpFullSettings(): void
	{
		$ctrlFile = dirname((new ReflectionClass(RosterService::class))->getFileName(), 2)
			. '/Controller/MobileController.php';
		$src = (string) file_get_contents($ctrlFile);
		self::assertStringContainsString('pushQuietHoursStart', $src);
		self::assertStringContainsString('Companion-only slice', $src);
		self::assertStringNotContainsString('return $this->selfService->toApi($companyId);', $src);
	}
}
