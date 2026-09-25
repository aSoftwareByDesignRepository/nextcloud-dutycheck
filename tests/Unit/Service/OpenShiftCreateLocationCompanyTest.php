<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\Service;

use OCA\DutyCheck\Service\OpenShiftService;
use OCA\DutyCheck\Service\RosterService;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

final class OpenShiftCreateLocationCompanyTest extends TestCase
{
	public function testCreateRejectsLocationCompanyMismatchBeforeInsert(): void
	{
		$roster = $this->createMock(RosterService::class);
		$roster->expects($this->once())->method('assertPeriodCompanyAccess')->with('planner', 1);
		$roster->expects($this->once())->method('assertLocationMatchesPeriodCompany')->with(1, 99)
			->willThrowException(new \InvalidArgumentException('LOCATION_NOT_FOUND'));

		$db = $this->createMock(IDBConnection::class);
		$db->expects($this->never())->method('getQueryBuilder');

		$svc = new OpenShiftService($db, $roster);
		// Existence-blind: a foreign-company location reports like a missing one.
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('LOCATION_NOT_FOUND');
		$svc->create([
			'periodId' => 1,
			'locationId' => 99,
			'dutyDate' => '2099-03-01',
			'startTime' => '08:00',
			'endTime' => '12:00',
			'breakMinutes' => 0,
		], 'planner');
	}
}
