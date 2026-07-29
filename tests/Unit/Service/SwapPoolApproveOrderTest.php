<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\Service;

use OCA\DutyCheck\Service\RosterService;
use OCA\DutyCheck\Service\SwapService;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/** Pool-swap approve creates open shift before cancel so create failure never orphans a cancelled shift. */
final class SwapPoolApproveOrderTest extends TestCase
{
	public function testPoolApproveCreateFailureNeverCancelsAssignment(): void
	{
		$roster = $this->createMock(RosterService::class);
		$roster->method('assertPeriodCompanyAccess');
		$roster->expects($this->never())->method('cancelAssignment');
		$roster->method('assertLocationMatchesPeriodCompany')
			->willThrowException(new \InvalidArgumentException('COMPANY_MISMATCH'));

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
			public function eq(...$a)
			{
				return 'eq';
			}
		};

		$qbReturning = function (IResult $result) use ($expr): IQueryBuilder {
			$qb = $this->createMock(IQueryBuilder::class);
			$qb->method('select')->willReturnSelf();
			$qb->method('from')->willReturnSelf();
			$qb->method('where')->willReturnSelf();
			$qb->method('andWhere')->willReturnSelf();
			$qb->method('expr')->willReturn($expr);
			$qb->method('createNamedParameter')->willReturn('p');
			$qb->method('executeQuery')->willReturn($result);
			return $qb;
		};

		$swapResult = $this->createMock(IResult::class);
		$swapResult->method('fetch')->willReturn($swapRow);
		$asgResult = $this->createMock(IResult::class);
		$asgResult->method('fetch')->willReturn($asgRow);

		$qbCas = $this->createMock(IQueryBuilder::class);
		$qbCas->method('update')->willReturnSelf();
		$qbCas->method('set')->willReturnSelf();
		$qbCas->method('where')->willReturnSelf();
		$qbCas->method('andWhere')->willReturnSelf();
		$qbCas->method('expr')->willReturn($expr);
		$qbCas->method('createNamedParameter')->willReturn('p');
		$qbCas->method('executeStatement')->willReturn(1);

		$qbRevert = $this->createMock(IQueryBuilder::class);
		$qbRevert->method('update')->willReturnSelf();
		$qbRevert->method('set')->willReturnSelf();
		$qbRevert->method('where')->willReturnSelf();
		$qbRevert->method('andWhere')->willReturnSelf();
		$qbRevert->method('expr')->willReturn($expr);
		$qbRevert->method('createNamedParameter')->willReturn('p');
		$qbRevert->method('executeStatement')->willReturn(1);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnOnConsecutiveCalls(
			$qbReturning($swapResult),
			$qbReturning($asgResult),
			$qbCas,
			$qbRevert,
		);
		$db->method('tableExists')->willReturn(true);

		$svc = new SwapService($db, $roster);
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('COMPANY_MISMATCH');
		$svc->review(3, 'planner', 'approved', 'pool');
	}
}
