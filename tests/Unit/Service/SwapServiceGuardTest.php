<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\Service;

use OCA\DutyCheck\Service\RosterService;
use OCA\DutyCheck\Service\SwapService;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * Swap request fail-closed guards (ownership, same employee, period, cancelled).
 */
final class SwapServiceGuardTest extends TestCase
{
	private function expr(): object
	{
		return new class {
			public function eq(...$a)
			{
				return 'eq';
			}
			public function in(...$a)
			{
				return 'in';
			}
		};
	}

	private function qbReturning(IResult $result): IQueryBuilder
	{
		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('select')->willReturnSelf();
		$qb->method('from')->willReturnSelf();
		$qb->method('where')->willReturnSelf();
		$qb->method('andWhere')->willReturnSelf();
		$qb->method('expr')->willReturn($this->expr());
		$qb->method('createNamedParameter')->willReturn('p');
		$qb->method('executeQuery')->willReturn($result);
		return $qb;
	}

	public function testRequestFailsWhenAssignmentBelongsToSomeoneElse(): void
	{
		$empResult = $this->createMock(IResult::class);
		$empResult->method('fetch')->willReturn(['id' => 10]);
		$asgResult = $this->createMock(IResult::class);
		$asgResult->method('fetch')->willReturn([
			'id' => 5,
			'employee_id' => 99,
			'period_id' => 1,
			'status' => 'active',
		]);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnOnConsecutiveCalls(
			$this->qbReturning($empResult),
			$this->qbReturning($asgResult),
		);

		$svc = new SwapService($db, $this->createMock(RosterService::class));
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('FORBIDDEN');
		$svc->requestSwap(5, 'alice', 11);
	}

	public function testRequestFailsWhenTargetIsSelf(): void
	{
		$empResult = $this->createMock(IResult::class);
		$empResult->method('fetch')->willReturn(['id' => 10]);
		$asgResult = $this->createMock(IResult::class);
		$asgResult->method('fetch')->willReturn([
			'id' => 5,
			'employee_id' => 10,
			'period_id' => 1,
			'status' => 'active',
		]);
		$periodResult = $this->createMock(IResult::class);
		$periodResult->method('fetch')->willReturn(['status' => 'published']);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnOnConsecutiveCalls(
			$this->qbReturning($empResult),
			$this->qbReturning($asgResult),
			$this->qbReturning($periodResult),
		);

		$svc = new SwapService($db, $this->createMock(RosterService::class));
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('SWAP_SAME_EMPLOYEE');
		$svc->requestSwap(5, 'alice', 10);
	}

	public function testRequestFailsOnCancelledAssignment(): void
	{
		$empResult = $this->createMock(IResult::class);
		$empResult->method('fetch')->willReturn(['id' => 10]);
		$asgResult = $this->createMock(IResult::class);
		$asgResult->method('fetch')->willReturn([
			'id' => 5,
			'employee_id' => 10,
			'period_id' => 1,
			'status' => 'cancelled',
		]);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnOnConsecutiveCalls(
			$this->qbReturning($empResult),
			$this->qbReturning($asgResult),
		);

		$svc = new SwapService($db, $this->createMock(RosterService::class));
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('ASSIGNMENT_CANCELLED');
		$svc->requestSwap(5, 'alice', null);
	}

	public function testReviewRejectsNonPending(): void
	{
		$swapResult = $this->createMock(IResult::class);
		$swapResult->method('fetch')->willReturn([
			'id' => 3,
			'assignment_id' => 5,
			'from_employee_id' => 10,
			'to_employee_id' => 11,
			'status' => 'approved',
			'reason' => '',
			'review_reason' => '',
			'reviewed_by' => 'planner',
			'reviewed_at' => '2099-01-01 00:00:00',
			'created_at' => '2099-01-01 00:00:00',
		]);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($this->qbReturning($swapResult));

		$svc = new SwapService($db, $this->createMock(RosterService::class));
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('SWAP_NOT_PENDING');
		$svc->review(3, 'planner', 'approved');
	}

	public function testReviewMapsTransferConflictToSwapConflict(): void
	{
		$swapResult = $this->createMock(IResult::class);
		$swapResult->method('fetch')->willReturn([
			'id' => 3,
			'assignment_id' => 5,
			'from_employee_id' => 10,
			'to_employee_id' => 11,
			'status' => 'pending',
			'reason' => '',
			'review_reason' => null,
			'reviewed_by' => null,
			'reviewed_at' => null,
			'created_at' => '2099-01-01 00:00:00',
		]);
		$asgResult = $this->createMock(IResult::class);
		$asgResult->method('fetch')->willReturn([
			'id' => 5,
			'employee_id' => 10,
			'period_id' => 1,
			'location_id' => 2,
			'duty_date' => '2099-03-01',
			'start_time' => '08:00:00',
			'end_time' => '12:00:00',
			'break_minutes' => 0,
			'status' => 'active',
		]);

		$db = $this->createMock(IDBConnection::class);
		$casQb = $this->createMock(IQueryBuilder::class);
		$casQb->method('update')->willReturnSelf();
		$casQb->method('set')->willReturnSelf();
		$casQb->method('where')->willReturnSelf();
		$casQb->method('andWhere')->willReturnSelf();
		$casQb->method('expr')->willReturn($this->expr());
		$casQb->method('createNamedParameter')->willReturn('p');
		$casQb->method('executeStatement')->willReturn(1);

		$revertQb = $this->createMock(IQueryBuilder::class);
		$revertQb->method('update')->willReturnSelf();
		$revertQb->method('set')->willReturnSelf();
		$revertQb->method('where')->willReturnSelf();
		$revertQb->method('andWhere')->willReturnSelf();
		$revertQb->method('expr')->willReturn($this->expr());
		$revertQb->method('createNamedParameter')->willReturn('p');
		$revertQb->method('executeStatement')->willReturn(1);

		$db->method('getQueryBuilder')->willReturnOnConsecutiveCalls(
			$this->qbReturning($swapResult),
			$this->qbReturning($asgResult),
			$casQb,
			$revertQb,
		);

		$roster = $this->createMock(RosterService::class);
		$roster->expects($this->once())->method('assertPeriodCompanyAccess');
		$roster->method('transferAssignmentEmployee')->willThrowException(
			new \InvalidArgumentException('ASSIGNMENT_OVERLAP'),
		);

		$svc = new SwapService($db, $roster);
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('SWAP_CONFLICT');
		$svc->review(3, 'planner', 'approved');
	}

	public function testRequestFailsWhenPendingSwapAlreadyExists(): void
	{
		$empResult = $this->createMock(IResult::class);
		$empResult->method('fetch')->willReturn(['id' => 10]);
		$asgResult = $this->createMock(IResult::class);
		$asgResult->method('fetch')->willReturn([
			'id' => 5,
			'employee_id' => 10,
			'period_id' => 1,
			'status' => 'active',
		]);
		$periodResult = $this->createMock(IResult::class);
		$periodResult->method('fetch')->willReturn(['status' => 'published']);
		$pendingResult = $this->createMock(IResult::class);
		$pendingResult->method('fetch')->willReturn(['id' => 99]);

		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturn(true);
		$db->method('getQueryBuilder')->willReturnOnConsecutiveCalls(
			$this->qbReturning($empResult),
			$this->qbReturning($asgResult),
			$this->qbReturning($periodResult),
			$this->qbReturning($pendingResult),
		);

		$svc = new SwapService($db, $this->createMock(RosterService::class));
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('SWAP_ALREADY_PENDING');
		$svc->requestSwap(5, 'alice', null);
	}
}
