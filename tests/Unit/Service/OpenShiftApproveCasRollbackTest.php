<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\Service;

use OCA\DutyCheck\Service\OpenShiftService;
use OCA\DutyCheck\Service\RosterService;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

final class OpenShiftApproveCasRollbackTest extends TestCase
{
	public function testLostApproveCasCancelsOrphanAssignment(): void
	{
		$roster = $this->createMock(RosterService::class);
		$roster->expects($this->once())->method('assertPeriodCompanyAccess');
		$roster->expects($this->once())->method('createAssignment')->willReturn([
			'createdAssignmentId' => 55,
			'assignments' => [],
		]);
		$roster->expects($this->once())->method('cancelAssignmentSilent')->with(55, 'planner');

		$pendingRow = [
			'id' => 7,
			'period_id' => 1,
			'location_id' => 2,
			'template_id' => null,
			'duty_date' => '2099-03-01',
			'start_time' => '08:00:00',
			'end_time' => '12:00:00',
			'break_minutes' => 0,
			'status' => 'pending',
			'claimed_by_emp' => 9,
			'assignment_id' => null,
		];
		$openResult = $this->createMock(IResult::class);
		$openResult->method('fetch')->willReturn($pendingRow);

		$expr = new class {
			public function eq(...$a) { return 'eq'; }
		};

		$qbGet = $this->createMock(IQueryBuilder::class);
		$qbGet->method('select')->willReturnSelf();
		$qbGet->method('from')->willReturnSelf();
		$qbGet->method('leftJoin')->willReturnSelf();
		$qbGet->method('where')->willReturnSelf();
		$qbGet->method('expr')->willReturn($expr);
		$qbGet->method('createNamedParameter')->willReturn('p');
		$qbGet->method('executeQuery')->willReturn($openResult);

		$qbCas = $this->createMock(IQueryBuilder::class);
		$qbCas->method('update')->willReturnSelf();
		$qbCas->method('set')->willReturnSelf();
		$qbCas->method('where')->willReturnSelf();
		$qbCas->method('andWhere')->willReturnSelf();
		$qbCas->method('expr')->willReturn($expr);
		$qbCas->method('createNamedParameter')->willReturn('p');
		$qbCas->method('executeStatement')->willReturn(0); // lost race

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnOnConsecutiveCalls($qbGet, $qbCas);

		$svc = new OpenShiftService($db, $roster);
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('OPEN_SHIFT_NOT_PENDING');
		$svc->approveClaim(7, 'planner');
	}
}
