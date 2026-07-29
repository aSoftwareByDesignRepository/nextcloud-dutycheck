<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\Service;

use OCA\DutyCheck\Exception\ConflictAckRequiredException;
use OCA\DutyCheck\Service\OpenShiftService;
use OCA\DutyCheck\Service\RosterService;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/** B2: soft conflicts on approve must surface CONFLICT_ACK_REQUIRED (not OPEN_SHIFT_CONFLICT). */
final class OpenShiftApproveSoftAckTest extends TestCase
{
	public function testApproveRethrowsConflictAckRequired(): void
	{
		$roster = $this->createMock(RosterService::class);
		$roster->expects($this->once())
			->method('createAssignment')
			->with(
				$this->callback(static function (array $payload): bool {
					return $payload['acknowledgements'] === [];
				}),
				'planner1',
				true,
			)
			->willThrowException(new ConflictAckRequiredException([
				['type' => 'rest_time_violation', 'severity' => 'soft'],
			]));

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
			'location_name' => 'Gate',
		];

		$get1 = $this->createMock(IResult::class);
		$get1->method('fetch')->willReturn($pendingRow);

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

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qbGet1);

		$svc = new OpenShiftService($db, $roster);
		$this->expectException(ConflictAckRequiredException::class);
		$svc->approveClaim(7, 'planner1', []);
	}

	public function testApprovePassesAcknowledgements(): void
	{
		$acks = [['conflictType' => 'rest_time_violation', 'reason' => '1234567890']];
		$roster = $this->createMock(RosterService::class);
		$roster->expects($this->once())
			->method('createAssignment')
			->with(
				$this->callback(static function (array $payload) use ($acks): bool {
					return $payload['acknowledgements'] === $acks;
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
			'location_name' => 'Gate',
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

		$mkGet = function ($result) use ($expr) {
			$qb = $this->createMock(IQueryBuilder::class);
			$qb->method('select')->willReturnSelf();
			$qb->method('from')->willReturnSelf();
			$qb->method('leftJoin')->willReturnSelf();
			$qb->method('where')->willReturnSelf();
			$qb->method('expr')->willReturn($expr);
			$qb->method('createNamedParameter')->willReturn('p');
			$qb->method('executeQuery')->willReturn($result);
			return $qb;
		};

		$qbUpdate = $this->createMock(IQueryBuilder::class);
		$qbUpdate->method('update')->willReturnSelf();
		$qbUpdate->method('set')->willReturnSelf();
		$qbUpdate->method('where')->willReturnSelf();
		$qbUpdate->method('andWhere')->willReturnSelf();
		$qbUpdate->method('expr')->willReturn($expr);
		$qbUpdate->method('createNamedParameter')->willReturn('p');
		$qbUpdate->method('executeStatement')->willReturn(1);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnOnConsecutiveCalls(
			$mkGet($get1),
			$qbUpdate,
			$mkGet($get2),
		);

		$svc = new OpenShiftService($db, $roster);
		$result = $svc->approveClaim(7, 'planner1', $acks);
		self::assertSame('claimed', $result['status']);
		self::assertSame('Gate', $result['locationName']);
	}
}
