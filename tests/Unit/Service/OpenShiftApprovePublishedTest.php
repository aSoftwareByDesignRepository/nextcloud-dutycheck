<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\Service;

use OCA\DutyCheck\Service\OpenShiftService;
use OCA\DutyCheck\Service\RosterService;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * B2: claim is pending-only; assignment create happens on planner approve (published OK).
 */
final class OpenShiftApprovePublishedTest extends TestCase
{
	public function testApproveClaimPassesAllowPublishedMarketplace(): void
	{
		$roster = $this->createMock(RosterService::class);
		$roster->expects($this->once())
			->method('createAssignment')
			->with(
				$this->callback(static function (array $payload): bool {
					return (int) $payload['periodId'] === 3
						&& (int) $payload['employeeId'] === 9
						&& (int) $payload['locationId'] === 2;
				}),
				'planner1',
				true,
			)
			->willReturn([
				'createdAssignmentId' => 55,
				'assignments' => [[
					'id' => 55,
					'employeeId' => 9,
					'dutyDate' => '2099-03-01',
					'startTime' => '08:00:00',
				]],
			]);

		$pendingRow = [
			'id' => 7,
			'period_id' => 3,
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
		$claimedRow = $pendingRow;
		$claimedRow['status'] = 'claimed';
		$claimedRow['assignment_id'] = 55;

		$get1 = $this->createMock(IResult::class);
		$get1->method('fetch')->willReturn($pendingRow);
		$get2 = $this->createMock(IResult::class);
		$get2->method('fetch')->willReturn($claimedRow);

		$expr = new class {
			public function eq(...$a)
			{
				return 'eq';
			}
		};

		$qbGet1 = $this->createMock(IQueryBuilder::class);
		$qbGet1->method('select')->willReturnSelf();
		$qbGet1->method('from')->willReturnSelf();
		$qbGet1->method('leftJoin')->willReturnSelf();
		$qbGet1->method('where')->willReturnSelf();
		$qbGet1->method('expr')->willReturn($expr);
		$qbGet1->method('createNamedParameter')->willReturn('p');
		$qbGet1->method('executeQuery')->willReturn($get1);

		$qbUpdate = $this->createMock(IQueryBuilder::class);
		$qbUpdate->method('update')->willReturnSelf();
		$qbUpdate->method('set')->willReturnSelf();
		$qbUpdate->method('where')->willReturnSelf();
		$qbUpdate->method('andWhere')->willReturnSelf();
		$qbUpdate->method('expr')->willReturn($expr);
		$qbUpdate->method('createNamedParameter')->willReturn('p');
		$qbUpdate->method('executeStatement')->willReturn(1);

		$qbGet2 = $this->createMock(IQueryBuilder::class);
		$qbGet2->method('select')->willReturnSelf();
		$qbGet2->method('from')->willReturnSelf();
		$qbGet2->method('leftJoin')->willReturnSelf();
		$qbGet2->method('where')->willReturnSelf();
		$qbGet2->method('expr')->willReturn($expr);
		$qbGet2->method('createNamedParameter')->willReturn('p');
		$qbGet2->method('executeQuery')->willReturn($get2);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnOnConsecutiveCalls($qbGet1, $qbUpdate, $qbGet2);

		$svc = new OpenShiftService($db, $roster);
		$result = $svc->approveClaim(7, 'planner1');
		self::assertSame('claimed', $result['status']);
		self::assertSame(55, $result['assignmentId']);
	}

	public function testClaimDoesNotCreateAssignment(): void
	{
		$roster = $this->createMock(RosterService::class);
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
		$pendingRow = $openRow;
		$pendingRow['status'] = 'pending';
		$pendingRow['claimed_by_emp'] = 9;

		$empResult = $this->createMock(IResult::class);
		$empResult->method('fetch')->willReturn(['id' => 9]);
		$openResult = $this->createMock(IResult::class);
		$openResult->method('fetch')->willReturn($openRow);
		$periodResult = $this->createMock(IResult::class);
		$periodResult->method('fetch')->willReturn(['status' => 'published']);
		$pendingResult = $this->createMock(IResult::class);
		$pendingResult->method('fetch')->willReturn($pendingRow);

		$expr = new class {
			public function eq(...$a)
			{
				return 'eq';
			}
		};

		$mkSelect = function ($result) use ($expr) {
			$qb = $this->createMock(IQueryBuilder::class);
			$qb->method('select')->willReturnSelf();
			$qb->method('from')->willReturnSelf();
			$qb->method('leftJoin')->willReturnSelf();
			$qb->method('where')->willReturnSelf();
			$qb->method('andWhere')->willReturnSelf();
			$qb->method('expr')->willReturn($expr);
			$qb->method('createNamedParameter')->willReturn('p');
			$qb->method('executeQuery')->willReturn($result);
			return $qb;
		};

		$qbCas = $this->createMock(IQueryBuilder::class);
		$qbCas->method('update')->willReturnSelf();
		$qbCas->method('set')->willReturnSelf();
		$qbCas->method('where')->willReturnSelf();
		$qbCas->method('andWhere')->willReturnSelf();
		$qbCas->method('expr')->willReturn($expr);
		$qbCas->method('createNamedParameter')->willReturn('p');
		$qbCas->method('executeStatement')->willReturn(1);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnOnConsecutiveCalls(
			$mkSelect($empResult),
			$mkSelect($openResult),
			$mkSelect($periodResult),
			$qbCas,
			$mkSelect($pendingResult),
		);

		$svc = new OpenShiftService($db, $roster);
		$result = $svc->claim(7, 'alice');
		self::assertSame('pending', $result['status']);
		self::assertSame(9, $result['claimedByEmployeeId']);
	}
}
