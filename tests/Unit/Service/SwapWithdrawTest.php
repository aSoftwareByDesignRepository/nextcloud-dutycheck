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
 * Requester withdrawal (delete-parity): a swap the requester raised can be
 * taken back while unresolved — CAS-guarded, existence-blind for others' ids.
 */
final class SwapWithdrawTest extends TestCase
{
	private function swapRow(string $status = 'pending', int $fromEmployee = 1): array
	{
		return [
			'id' => 7,
			'assignment_id' => 10,
			'from_employee_id' => $fromEmployee,
			'to_employee_id' => 2,
			'reason' => '',
			'status' => $status,
			'created_by' => 'alice',
			'created_at' => '2099-01-01 00:00:00',
			'review_reason' => null,
			'reviewed_by' => null,
			'reviewed_at' => null,
			'company_id' => 1,
		];
	}

	private function exprStub(): object
	{
		return new class {
			public function eq(...$a) { return 'eq'; }
			public function in(...$a) { return 'in'; }
		};
	}

	private function selectQb(array $rows): IQueryBuilder
	{
		$result = $this->createMock(IResult::class);
		$result->method('fetch')->willReturnOnConsecutiveCalls(...$rows);
		$result->method('fetchAll')->willReturn($rows);
		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('select')->willReturnSelf();
		$qb->method('from')->willReturnSelf();
		$qb->method('where')->willReturnSelf();
		$qb->method('andWhere')->willReturnSelf();
		$qb->method('orderBy')->willReturnSelf();
		$qb->method('expr')->willReturn($this->exprStub());
		$qb->method('createNamedParameter')->willReturn('p');
		$qb->method('executeQuery')->willReturn($result);
		return $qb;
	}

	private function updateQb(int $affected): IQueryBuilder
	{
		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('update')->willReturnSelf();
		$qb->method('set')->willReturnSelf();
		$qb->method('where')->willReturnSelf();
		$qb->method('andWhere')->willReturnSelf();
		$qb->method('expr')->willReturn($this->exprStub());
		$qb->method('createNamedParameter')->willReturn('p');
		$qb->method('executeStatement')->willReturn($affected);
		return $qb;
	}

	private function service(array $qbs): SwapService
	{
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnOnConsecutiveCalls(...$qbs);
		$db->method('tableExists')->willReturn(true);
		return new SwapService($db, $this->createMock(RosterService::class));
	}

	public function testRequesterWithdrawsOwnPendingSwap(): void
	{
		$svc = $this->service([
			$this->selectQb([['id' => 1]]),                       // linkedEmployeeId
			$this->selectQb([$this->swapRow('pending', 1)]),      // getById
			$this->updateQb(1),                                   // CAS
			$this->selectQb([$this->swapRow('withdrawn', 1)]),    // getById reload
		]);
		$out = $svc->withdrawSwap(7, 'alice');
		self::assertSame('withdrawn', $out['status']);
	}

	public function testWithdrawingAnotherEmployeesSwapIsExistenceBlind(): void
	{
		$svc = $this->service([
			$this->selectQb([['id' => 9]]),                       // actor links to employee 9
			$this->selectQb([$this->swapRow('pending', 1)]),      // swap belongs to employee 1
		]);
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('SWAP_NOT_FOUND');
		$svc->withdrawSwap(7, 'mallory');
	}

	public function testWithdrawRejectsTerminalStatus(): void
	{
		$svc = $this->service([
			$this->selectQb([['id' => 1]]),
			$this->selectQb([$this->swapRow('applied', 1)]),
		]);
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('SWAP_NOT_PENDING');
		$svc->withdrawSwap(7, 'alice');
	}

	public function testCasMissMeansConcurrentTransitionWon(): void
	{
		$svc = $this->service([
			$this->selectQb([['id' => 1]]),
			$this->selectQb([$this->swapRow('pending', 1)]),
			$this->updateQb(0),                                   // planner approved concurrently
		]);
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('SWAP_NOT_PENDING');
		$svc->withdrawSwap(7, 'alice');
	}

	public function testListMineReturnsOnlyOwnOpenSwaps(): void
	{
		$svc = $this->service([
			$this->selectQb([['id' => 1]]),                       // linkedEmployeeId
			$this->selectQb([$this->swapRow('pending', 1), $this->swapRow('pending_planner', 1)]),
		]);
		$rows = $svc->listMine('alice');
		self::assertCount(2, $rows);
		self::assertSame(10, $rows[0]['assignmentId']);
	}
}
