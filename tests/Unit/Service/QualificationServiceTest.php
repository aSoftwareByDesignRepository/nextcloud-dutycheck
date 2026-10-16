<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\Service;

use OCA\DutyCheck\Db\SchemaProbe;
use OCA\DutyCheck\Service\CompanyService;
use OCA\DutyCheck\Service\QualificationService;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

final class QualificationServiceTest extends TestCase
{
	/** @var list<string> insert() calls observed on the mocked connection */
	private array $insertedTables = [];

	protected function setUp(): void
	{
		parent::setUp();
		SchemaProbe::resetCache();
	}

	protected function tearDown(): void
	{
		SchemaProbe::resetCache();
		parent::tearDown();
	}

	private function qb(IResult $result): IQueryBuilder
	{
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturn('eq');
		$expr->method('in')->willReturn('in');

		$qb = $this->createMock(IQueryBuilder::class);
		foreach (['select', 'from', 'where', 'andWhere', 'orderBy', 'addOrderBy',
			'setMaxResults', 'values', 'update', 'set', 'delete', 'innerJoin'] as $m) {
			$qb->method($m)->willReturnSelf();
		}
		$qb->method('insert')->willReturnCallback(function (string $table) use ($qb) {
			$this->insertedTables[] = $table;
			return $qb;
		});
		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturn('p');
		$qb->method('executeQuery')->willReturn($result);
		$qb->method('executeStatement')->willReturn(1);
		return $qb;
	}

	/** @param array<string,mixed>|null $row */
	private function result(?array $row, array $rows = []): IResult
	{
		$res = $this->createMock(IResult::class);
		$res->method('fetch')->willReturn($row ?? false);
		$res->method('fetchAll')->willReturn($rows);
		$res->method('fetchOne')->willReturn($row !== null ? reset($row) : false);
		return $res;
	}

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
			['id' => 9, 'name' => 'First Aid', 'required' => 1, 'location_id' => 4],
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
			public function in(...$a) { return 'in'; }
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
			public function in(...$a) { return 'in'; }
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
			['id' => 9, 'name' => 'First Aid', 'required' => 1, 'location_id' => 4],
		]);
		$heldResult = $this->createMock(IResult::class);
		$heldResult->method('fetchAll')->willReturn([
			['qualification_id' => 9, 'expires_on' => '2026-01-01', 'employee_id' => 3],
		]);

		$qbReq = $this->createMock(IQueryBuilder::class);
		$qbReq->method('select')->willReturnSelf();
		$qbReq->method('from')->willReturnSelf();
		$qbReq->method('innerJoin')->willReturnSelf();
		$qbReq->method('where')->willReturnSelf();
		$qbReq->method('andWhere')->willReturnSelf();
		$qbReq->method('expr')->willReturn(new class {
			public function eq(...$a) { return 'eq'; }
			public function in(...$a) { return 'in'; }
		});
		$qbReq->method('createNamedParameter')->willReturn('p');
		$qbReq->method('executeQuery')->willReturn($reqResult);

		$qbHeld = $this->createMock(IQueryBuilder::class);
		$qbHeld->method('select')->willReturnSelf();
		$qbHeld->method('from')->willReturnSelf();
		$qbHeld->method('where')->willReturnSelf();
		$qbHeld->method('expr')->willReturn(new class {
			public function eq(...$a) { return 'eq'; }
			public function in(...$a) { return 'in'; }
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

	public function testUnrequireFromLocationDeletesRow(): void
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
		$svc->unrequireFromLocation(4, 9);
		self::assertTrue(true);
	}

	public function testUnrequireFromLocationMissingRowThrows(): void
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
		$qb->method('executeStatement')->willReturn(0);
		$db->method('getQueryBuilder')->willReturn($qb);

		$svc = new QualificationService($db);
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('LOCATION_QUALIFICATION_NOT_FOUND');
		$svc->unrequireFromLocation(4, 9);
	}

	public function testRequireForLocationIsIdempotent(): void
	{
		$db = $this->createMock(IDBConnection::class);
		$expr = new class {
			public function eq(...$a) { return 'eq'; }
		};
		// Existing requirement row → early return, insert must never run.
		$existsResult = $this->createMock(IResult::class);
		$existsResult->method('fetch')->willReturn(['id' => 7]);
		// assertRowExists probes share this qb via fetchOne.
		$existsResult->method('fetchOne')->willReturn(7);
		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('select')->willReturnSelf();
		$qb->method('from')->willReturnSelf();
		$qb->method('where')->willReturnSelf();
		$qb->method('andWhere')->willReturnSelf();
		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturn('p');
		$qb->method('executeQuery')->willReturn($existsResult);
		$qb->expects($this->never())->method('insert');
		$db->method('getQueryBuilder')->willReturn($qb);

		$svc = new QualificationService($db);
		$svc->requireForLocation(4, 9); // must not throw a constraint violation
		self::assertTrue(true);
	}

	public function testListCatalogIncludesRequiredAt(): void
	{
		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturn(true);
		$expr = new class {
			public function eq(...$a) { return 'eq'; }
		};

		$catResult = $this->createMock(IResult::class);
		$catResult->method('fetchAll')->willReturn([
			['id' => 9, 'name' => 'First Aid', 'code' => 'FA', 'active' => 1],
		]);
		$qbCat = $this->createMock(IQueryBuilder::class);
		$qbCat->method('select')->willReturnSelf();
		$qbCat->method('from')->willReturnSelf();
		$qbCat->method('where')->willReturnSelf();
		$qbCat->method('orderBy')->willReturnSelf();
		$qbCat->method('expr')->willReturn($expr);
		$qbCat->method('createNamedParameter')->willReturn('p');
		$qbCat->method('executeQuery')->willReturn($catResult);

		$reqResult = $this->createMock(IResult::class);
		$reqResult->method('fetchAll')->willReturn([
			['qualification_id' => 9, 'location_id' => 4, 'name' => 'Depot Nord'],
		]);
		$qbReq = $this->createMock(IQueryBuilder::class);
		$qbReq->method('select')->willReturnSelf();
		$qbReq->method('from')->willReturnSelf();
		$qbReq->method('innerJoin')->willReturnSelf();
		$qbReq->method('expr')->willReturn($expr);
		$qbReq->method('createNamedParameter')->willReturn('p');
		$qbReq->method('executeQuery')->willReturn($reqResult);

		$db->method('getQueryBuilder')->willReturnOnConsecutiveCalls($qbCat, $qbReq);

		$svc = new QualificationService($db);
		$rows = $svc->listCatalog();
		self::assertSame([['id' => 4, 'name' => 'Depot Nord']], $rows[0]['requiredAt']);
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

	public function testAttachToEmployeeRejectsMissingEmployeeId(): void
	{
		// dc_emp_quals has no FK — a missing employee id must reject BEFORE the
		// link insert. Observed live on a single-company install: assertRowCompany
		// short-circuits and attach wrote an orphan row with HTTP 200.
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnOnConsecutiveCalls(
			$this->qb($this->result(['c' => 1])), // SchemaProbe hasColumn probe
			$this->qb($this->result(null)),       // employee existence → no row
		);
		$svc = new QualificationService($db, $this->createMock(CompanyService::class));

		try {
			$svc->attachToEmployee(424242, 9, null, 'admin');
			self::fail('expected EMPLOYEE_NOT_FOUND');
		} catch (\InvalidArgumentException $e) {
			self::assertSame('EMPLOYEE_NOT_FOUND', $e->getMessage());
		}
		self::assertSame([], $this->insertedTables);
	}

	public function testAttachToEmployeeRejectsMissingQualificationId(): void
	{
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnOnConsecutiveCalls(
			$this->qb($this->result(['c' => 1])), // SchemaProbe hasColumn probe
			$this->qb($this->result(['id' => 5])), // employee exists
			$this->qb($this->result(null)),        // qualification missing
		);
		$svc = new QualificationService($db, $this->createMock(CompanyService::class));

		try {
			$svc->attachToEmployee(5, 424242, null, 'admin');
			self::fail('expected QUALIFICATION_NOT_FOUND');
		} catch (\InvalidArgumentException $e) {
			self::assertSame('QUALIFICATION_NOT_FOUND', $e->getMessage());
		}
		self::assertSame([], $this->insertedTables);
	}

	public function testAttachToEmployeeInsertsLinkRow(): void
	{
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnOnConsecutiveCalls(
			$this->qb($this->result(['c' => 1])),   // SchemaProbe hasColumn probe
			$this->qb($this->result(['id' => 5])),  // employee exists
			$this->qb($this->result(['id' => 9])),  // qualification exists
			$this->qb($this->result(null)),         // no existing link row
			$this->qb($this->result(null)),         // insert
		);
		$svc = new QualificationService($db, $this->createMock(CompanyService::class));

		$svc->attachToEmployee(5, 9, null, 'admin');

		self::assertSame(['dc_emp_quals'], $this->insertedTables);
	}

	public function testRequireForLocationRejectsMissingLocationId(): void
	{
		// Same orphan-write hole as attachToEmployee on dc_loc_quals.
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnOnConsecutiveCalls(
			$this->qb($this->result(['c' => 1])), // SchemaProbe hasColumn probe
			$this->qb($this->result(null)),       // location existence → no row
		);
		$svc = new QualificationService($db, $this->createMock(CompanyService::class));

		try {
			$svc->requireForLocation(424242, 9, 'admin');
			self::fail('expected LOCATION_NOT_FOUND');
		} catch (\InvalidArgumentException $e) {
			self::assertSame('LOCATION_NOT_FOUND', $e->getMessage());
		}
		self::assertSame([], $this->insertedTables);
	}

	public function testRequireForLocationRejectsMissingQualificationId(): void
	{
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnOnConsecutiveCalls(
			$this->qb($this->result(['c' => 1])),   // SchemaProbe hasColumn probe
			$this->qb($this->result(['id' => 4])),  // location exists
			$this->qb($this->result(null)),         // qualification missing
		);
		$svc = new QualificationService($db, $this->createMock(CompanyService::class));

		try {
			$svc->requireForLocation(4, 424242, 'admin');
			self::fail('expected QUALIFICATION_NOT_FOUND');
		} catch (\InvalidArgumentException $e) {
			self::assertSame('QUALIFICATION_NOT_FOUND', $e->getMessage());
		}
		self::assertSame([], $this->insertedTables);
	}
}
