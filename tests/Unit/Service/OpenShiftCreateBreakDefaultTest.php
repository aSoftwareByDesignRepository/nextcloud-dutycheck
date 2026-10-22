<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\Service;

use OCA\DutyCheck\Service\OpenShiftService;
use OCA\DutyCheck\Service\PlanningDefaultsService;
use OCA\DutyCheck\Service\RosterService;
use OCP\IConfig;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * Sibling of the suggest-fill org-default fix (customer report 2026-10-22):
 * the open-shift create form hardcoded breakMinutes:0 and the service cast
 * with a loose (int), so "Pause global gesetzt" was silently wiped on every
 * open shift and garbage input degraded to 0 instead of failing.
 */
final class OpenShiftCreateBreakDefaultTest extends TestCase
{
	private function roster(): RosterService
	{
		$roster = $this->createMock(RosterService::class);
		$roster->expects($this->never())->method('assertPeriodCompanyAccess');
		return $roster;
	}

	public function testGarbageBreakRejectedStrictly(): void
	{
		$svc = new OpenShiftService(
			$this->createMock(IDBConnection::class),
			$this->roster(),
		);
		try {
			$svc->create([
				'periodId' => 1, 'locationId' => 1, 'dutyDate' => '2099-03-01',
				'startTime' => '08:00', 'endTime' => '12:00',
				'breakMinutes' => 'half an hour',
			], 'planner');
			self::fail('non-numeric break must not degrade to 0');
		} catch (\InvalidArgumentException $e) {
			self::assertSame('INVALID_BREAK_MINUTES', $e->getMessage());
		}
	}

	public function testBoolBreakRejectedStrictly(): void
	{
		$svc = new OpenShiftService(
			$this->createMock(IDBConnection::class),
			$this->roster(),
		);
		try {
			$svc->create([
				'periodId' => 1, 'locationId' => 1, 'dutyDate' => '2099-03-01',
				'startTime' => '08:00', 'endTime' => '12:00',
				'breakMinutes' => true,
			], 'planner');
			self::fail('bool break must be rejected, not cast to 1');
		} catch (\InvalidArgumentException $e) {
			self::assertSame('INVALID_BREAK_MINUTES', $e->getMessage());
		}
	}

	public function testOutOfRangeBreakRejected(): void
	{
		$svc = new OpenShiftService(
			$this->createMock(IDBConnection::class),
			$this->roster(),
		);
		try {
			$svc->create([
				'periodId' => 1, 'locationId' => 1, 'dutyDate' => '2099-03-01',
				'startTime' => '08:00', 'endTime' => '12:00',
				'breakMinutes' => -30,
			], 'planner');
			self::fail('negative break must be rejected');
		} catch (\InvalidArgumentException $e) {
			self::assertSame('INVALID_BREAK_MINUTES', $e->getMessage());
		}
	}
}
