<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\Service;

use OCA\DutyCheck\Service\RosterService;
use OCA\DutyCheck\Service\SwapService;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

final class SwapReviewCasTest extends TestCase
{
	public function testConcurrentReviewSecondPlannerLosesCasBeforeMutation(): void
	{
		$roster = $this->createMock(RosterService::class);
		$roster->expects($this->once())->method('assertPeriodCompanyAccess');
		$roster->expects($this->never())->method('cancelAssignment');
		$roster->expects($this->never())->method('transferAssignmentEmployee');

		$swapRow = [
			'id' => 3,
			'assignment_id' => 10,
			'from_employee_id' => 1,
			'to_employee_id' => null,
			'reason' => '',
			'status' => 'pending',
			'created_by' => 'alice',
			'created_at' => '2099-01-01 00:00:00',
			'review_reason' => null,
			'reviewed_by' => null,
			'reviewed_at' => null,
			'company_id' => 1,
		];
		$asgRow = [
			'id' => 10,
			'period_id' => 5,
			'employee_id' => 1,
			'location_id' => 2,
			'duty_date' => '2099-03-01',
			'start_time' => '08:00:00',
			'end_time' => '12:00:00',
			'break_minutes' => 0,
			'status' => 'active',
		];

		$expr = new class {
			public function eq(...$a) { return 'eq'; }
		};

		$swapResult = $this->createMock(IResult::class);
		$swapResult->method('fetch')->willReturn($swapRow);
		$asgResult = $this->createMock(IResult::class);
		$asgResult->method('fetch')->willReturn($asgRow);

		$qbSwap = $this->createMock(IQueryBuilder::class);
		$qbSwap->method('select')->willReturnSelf();
		$qbSwap->method('from')->willReturnSelf();
		$qbSwap->method('where')->willReturnSelf();
		$qbSwap->method('expr')->willReturn($expr);
		$qbSwap->method('createNamedParameter')->willReturn('p');
		$qbSwap->method('executeQuery')->willReturn($swapResult);

		$qbAsg = $this->createMock(IQueryBuilder::class);
		$qbAsg->method('select')->willReturnSelf();
		$qbAsg->method('from')->willReturnSelf();
		$qbAsg->method('where')->willReturnSelf();
		$qbAsg->method('expr')->willReturn($expr);
		$qbAsg->method('createNamedParameter')->willReturn('p');
		$qbAsg->method('executeQuery')->willReturn($asgResult);

		$qbCas = $this->createMock(IQueryBuilder::class);
		$qbCas->method('update')->willReturnSelf();
		$qbCas->method('set')->willReturnSelf();
		$qbCas->method('where')->willReturnSelf();
		$qbCas->method('andWhere')->willReturnSelf();
		$qbCas->method('expr')->willReturn($expr);
		$qbCas->method('createNamedParameter')->willReturn('p');
		$qbCas->method('executeStatement')->willReturn(0);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnOnConsecutiveCalls($qbSwap, $qbAsg, $qbCas);
		$db->method('tableExists')->willReturn(true);

		$svc = new SwapService($db, $roster);
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('SWAP_NOT_PENDING');
		$svc->review(3, 'planner', 'approved', 'ok reason');
	}
}
