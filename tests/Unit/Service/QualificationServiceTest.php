<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\Service;

use OCA\DutyCheck\Service\QualificationService;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

final class QualificationServiceTest extends TestCase
{
	public function testConflictsEmptyWhenNoLocQualsTable(): void
	{
		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->with('dc_loc_quals')->willReturn(false);
		$svc = new QualificationService($db);
		self::assertSame([], $svc->conflictsForAssignment(1, 2, '2026-07-01'));
	}

	public function testMissingQualificationIsHardConflict(): void
	{
		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturn(true);

		$reqResult = $this->createMock(IResult::class);
		$reqResult->method('fetchAll')->willReturn([
			['id' => 9, 'name' => 'First Aid', 'required' => 1],
		]);
		$heldResult = $this->createMock(IResult::class);
		$heldResult->method('fetchAll')->willReturn([]);

		$qbReq = $this->createMock(IQueryBuilder::class);
		$qbReq->method('select')->willReturnSelf();
		$qbReq->method('from')->willReturnSelf();
		$qbReq->method('innerJoin')->willReturnSelf();
		$qbReq->method('where')->willReturnSelf();
		$qbReq->method('andWhere')->willReturnSelf();
		$qbReq->method('expr')->willReturn(new class {
			public function eq(...$a) { return 'eq'; }
			public function createNamedParameter(...$a) { return 'p'; }
		});
		$qbReq->method('createNamedParameter')->willReturn('p');
		$qbReq->method('executeQuery')->willReturn($reqResult);

		$qbHeld = $this->createMock(IQueryBuilder::class);
		$qbHeld->method('select')->willReturnSelf();
		$qbHeld->method('from')->willReturnSelf();
		$qbHeld->method('where')->willReturnSelf();
		$qbHeld->method('expr')->willReturn(new class {
			public function eq(...$a) { return 'eq'; }
		});
		$qbHeld->method('createNamedParameter')->willReturn('p');
		$qbHeld->method('executeQuery')->willReturn($heldResult);

		$db->method('getQueryBuilder')->willReturnOnConsecutiveCalls($qbReq, $qbHeld);

		$svc = new QualificationService($db);
		$conflicts = $svc->conflictsForAssignment(3, 4, '2026-07-10');
		self::assertCount(1, $conflicts);
		self::assertSame('qualification_missing', $conflicts[0]['type']);
		self::assertSame('hard', $conflicts[0]['severity']);
	}

	public function testExpiredQualificationIsSoftConflict(): void
	{
		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturn(true);

		$reqResult = $this->createMock(IResult::class);
		$reqResult->method('fetchAll')->willReturn([
			['id' => 9, 'name' => 'First Aid', 'required' => 1],
		]);
		$heldResult = $this->createMock(IResult::class);
		$heldResult->method('fetchAll')->willReturn([
			['qualification_id' => 9, 'expires_on' => '2026-01-01'],
		]);

		$qbReq = $this->createMock(IQueryBuilder::class);
		$qbReq->method('select')->willReturnSelf();
		$qbReq->method('from')->willReturnSelf();
		$qbReq->method('innerJoin')->willReturnSelf();
		$qbReq->method('where')->willReturnSelf();
		$qbReq->method('andWhere')->willReturnSelf();
		$qbReq->method('expr')->willReturn(new class {
			public function eq(...$a) { return 'eq'; }
		});
		$qbReq->method('createNamedParameter')->willReturn('p');
		$qbReq->method('executeQuery')->willReturn($reqResult);

		$qbHeld = $this->createMock(IQueryBuilder::class);
		$qbHeld->method('select')->willReturnSelf();
		$qbHeld->method('from')->willReturnSelf();
		$qbHeld->method('where')->willReturnSelf();
		$qbHeld->method('expr')->willReturn(new class {
			public function eq(...$a) { return 'eq'; }
		});
		$qbHeld->method('createNamedParameter')->willReturn('p');
		$qbHeld->method('executeQuery')->willReturn($heldResult);

		$db->method('getQueryBuilder')->willReturnOnConsecutiveCalls($qbReq, $qbHeld);

		$svc = new QualificationService($db);
		$conflicts = $svc->conflictsForAssignment(3, 4, '2026-07-10');
		self::assertCount(1, $conflicts);
		self::assertSame('qualification_expired', $conflicts[0]['type']);
		self::assertSame('soft', $conflicts[0]['severity']);
	}

	public function testDeactivateMarksInactive(): void
	{
		$db = $this->createMock(IDBConnection::class);
		$expr = new class {
			public function eq(...$a) { return 'eq'; }
		};

		$qbUpd = $this->createMock(IQueryBuilder::class);
		$qbUpd->method('update')->willReturnSelf();
		$qbUpd->method('set')->willReturnSelf();
		$qbUpd->method('where')->willReturnSelf();
		$qbUpd->method('expr')->willReturn($expr);
		$qbUpd->method('createNamedParameter')->willReturn('p');
		$qbUpd->expects($this->once())->method('executeStatement')->willReturn(1);

		$getResult = $this->createMock(IResult::class);
		$getResult->method('fetch')->willReturn([
			'id' => 4,
			'name' => 'First Aid',
			'code' => 'FA',
			'active' => 0,
		]);
		$qbGet = $this->createMock(IQueryBuilder::class);
		$qbGet->method('select')->willReturnSelf();
		$qbGet->method('from')->willReturnSelf();
		$qbGet->method('where')->willReturnSelf();
		$qbGet->method('expr')->willReturn($expr);
		$qbGet->method('createNamedParameter')->willReturn('p');
		$qbGet->method('executeQuery')->willReturn($getResult);

		$db->method('getQueryBuilder')->willReturnOnConsecutiveCalls($qbUpd, $qbGet);
		$svc = new QualificationService($db);
		$row = $svc->deactivate(4);
		self::assertSame(0, $row['active']);
	}

	public function testDetachRemovesEmployeeQualification(): void
	{
		$db = $this->createMock(IDBConnection::class);
		$expr = new class {
			public function eq(...$a) { return 'eq'; }
		};
		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('delete')->willReturnSelf();
		$qb->method('where')->willReturnSelf();
		$qb->method('andWhere')->willReturnSelf();
		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturn('p');
		$qb->expects($this->once())->method('executeStatement')->willReturn(1);
		$db->method('getQueryBuilder')->willReturn($qb);

		$svc = new QualificationService($db);
		$svc->detachFromEmployee(3, 9);
		self::assertTrue(true);
	}
}
