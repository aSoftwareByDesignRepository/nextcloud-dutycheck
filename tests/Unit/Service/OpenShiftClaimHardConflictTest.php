<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\Service;

use OCA\DutyCheck\Service\OpenShiftService;
use OCA\DutyCheck\Service\RosterService;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

final class OpenShiftClaimHardConflictTest extends TestCase
{
	public function testHardConflictBlocksClaimBeforeCas(): void
	{
		$roster = $this->createMock(RosterService::class);
		$roster->expects($this->once())
			->method('assertHardMarketplaceSlot')
			->willThrowException(new \InvalidArgumentException('ASSIGNMENT_OVERLAP'));
		$roster->expects($this->never())->method('createAssignment');

		$openRow = [
			'id' => 7,
			'period_id' => 1,
			'location_id' => 2,
			'template_id' => null,
			'duty_date' => '2099-03-01',
			'start_time' => '08:00:00',
			'end_time' => '12:00:00',
			'break_minutes' => 0,
			'status' => 'open',
			'claimed_by_emp' => null,
			'assignment_id' => null,
		];
		$empResult = $this->createMock(IResult::class);
		$empResult->method('fetch')->willReturn(['id' => 9]);
		$openResult = $this->createMock(IResult::class);
		$openResult->method('fetch')->willReturn($openRow);

		$expr = new class {
			public function eq(...$a) { return 'eq'; }
		};

		$qbEmp = $this->createMock(IQueryBuilder::class);
		$qbEmp->method('select')->willReturnSelf();
		$qbEmp->method('from')->willReturnSelf();
		$qbEmp->method('leftJoin')->willReturnSelf();
		$qbEmp->method('where')->willReturnSelf();
		$qbEmp->method('andWhere')->willReturnSelf();
		$qbEmp->method('expr')->willReturn($expr);
		$qbEmp->method('createNamedParameter')->willReturn('p');
		$qbEmp->method('executeQuery')->willReturn($empResult);

		$qbGet = $this->createMock(IQueryBuilder::class);
		$qbGet->method('select')->willReturnSelf();
		$qbGet->method('from')->willReturnSelf();
		$qbGet->method('leftJoin')->willReturnSelf();
		$qbGet->method('where')->willReturnSelf();
		$qbGet->method('expr')->willReturn($expr);
		$qbGet->method('createNamedParameter')->willReturn('p');
		$qbGet->method('executeQuery')->willReturn($openResult);

		$periodResult = $this->createMock(IResult::class);
		$periodResult->method('fetch')->willReturn(['status' => 'published']);
		$qbPeriod = $this->createMock(IQueryBuilder::class);
		$qbPeriod->method('select')->willReturnSelf();
		$qbPeriod->method('from')->willReturnSelf();
		$qbPeriod->method('leftJoin')->willReturnSelf();
		$qbPeriod->method('where')->willReturnSelf();
		$qbPeriod->method('expr')->willReturn($expr);
		$qbPeriod->method('createNamedParameter')->willReturn('p');
		$qbPeriod->method('executeQuery')->willReturn($periodResult);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnOnConsecutiveCalls($qbEmp, $qbGet, $qbPeriod);
		// CAS must never run when hard conflict fires first.
		$db->expects($this->exactly(3))->method('getQueryBuilder');

		$svc = new OpenShiftService($db, $roster);
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('OPEN_SHIFT_CONFLICT');
		$svc->claim(7, 'alice');
	}
}
