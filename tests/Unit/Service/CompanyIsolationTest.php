<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\Service;

use OCA\DutyCheck\Db\SchemaProbe;
use OCA\DutyCheck\Service\CompanyService;
use OCA\DutyCheck\Service\RosterService;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Multi-company isolation fails closed; legacy single-company stays unrestricted.
 */
final class CompanyIsolationTest extends TestCase
{
	protected function setUp(): void
	{
		SchemaProbe::resetCache();
		$ref = new ReflectionClass(SchemaProbe::class);
		$prop = $ref->getProperty('columnCache');
		$prop->setAccessible(true);
		$prop->setValue(null, [
			'dc_periods.company_id' => true,
			'dc_employees.company_id' => true,
			'dc_locations.company_id' => true,
			'dc_assignments.status' => true,
			'dc_assignments.version' => false,
			'dc_periods.conflict_thresholds_json' => false,
			'dc_shift_templates.min_headcount' => false,
		]);
	}

	protected function tearDown(): void
	{
		SchemaProbe::resetCache();
	}

	public function testAssertRowCompanyForbiddenForOtherTenant(): void
	{
		$companies = $this->createMock(CompanyService::class);
		$companies->method('isMultiCompanyActive')->willReturn(true);
		$companies->method('schemaReady')->willReturn(true);
		$companies->expects(self::once())
			->method('assertRowCompany')
			->with('alice', 'dc_periods', 99)
			->willThrowException(new \InvalidArgumentException('FORBIDDEN'));

		$svc = new RosterService(
			$this->createMock(IDBConnection::class),
			null,
			null,
			null,
			null,
			null,
			null,
			null,
			null,
			null,
			$companies,
		);

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('FORBIDDEN');
		$svc->assertPeriodCompanyAccess('alice', 99);
	}

	public function testCreateAssignmentRejectsCrossCompanyEmployee(): void
	{
		$periodResult = $this->createMock(IResult::class);
		$periodResult->method('fetch')->willReturn([
			'id' => 1,
			'start_date' => '2026-07-01',
			'end_date' => '2026-07-07',
			'status' => 'open',
			'created_by' => 'admin',
			'created_at' => '2026-07-01 00:00:00',
			'published_at' => null,
			'closed_at' => null,
			'close_snapshot_id' => null,
		]);

		$empExists = $this->createMock(IResult::class);
		$empExists->method('fetchOne')->willReturn(1);
		$locExists = $this->createMock(IResult::class);
		$locExists->method('fetchOne')->willReturn(1);

		$companyReads = [
			['company_id' => 1], // period
			['company_id' => 2], // employee
			['company_id' => 1], // location
		];
		$companyResults = [];
		foreach ($companyReads as $row) {
			$r = $this->createMock(IResult::class);
			$r->method('fetch')->willReturn($row);
			$companyResults[] = $r;
		}

		$expr = new class {
			public function eq(...$a)
			{
				return 'eq';
			}

			public function andX(...$a)
			{
				return 'andX';
			}

			public function in(...$a)
			{
				return 'in';
			}
		};

		$qbSeq = [];
		$mk = function ($result) use ($expr) {
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

		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturn(true);
		$db->method('getQueryBuilder')->willReturnOnConsecutiveCalls(
			$mk($periodResult),
			$mk($empExists),
			$mk($locExists),
			$mk($companyResults[0]),
			$mk($companyResults[1]),
			$mk($companyResults[2]),
		);

		$companies = $this->createMock(CompanyService::class);
		$companies->method('schemaReady')->willReturn(true);
		$companies->method('isMultiCompanyActive')->willReturn(true);
		$companies->expects(self::once())->method('assertRowCompany');

		$svc = new RosterService($db, null, null, null, null, null, null, null, null, null, $companies);

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('COMPANY_MISMATCH');
		$svc->createAssignment([
			'periodId' => 1,
			'employeeId' => 10,
			'locationId' => 20,
			'dutyDate' => '2026-07-02',
			'startTime' => '08:00',
			'endTime' => '16:00',
			'breakMinutes' => 30,
		], 'planner');
	}

	public function testRestrictQuerySkippedWhenLegacySingleCompany(): void
	{
		$companies = new CompanyService($this->legacySingleCompanyDb());
		self::assertFalse($companies->isMultiCompanyActive());
		self::assertSame([], $companies->companyIdsForUser('anyone'));

		$qb = $this->createMock(IQueryBuilder::class);
		$qb->expects(self::never())->method('andWhere');
		$companies->restrictQuery($qb, 'company_id', 'anyone');
	}

	private function legacySingleCompanyDb(): IDBConnection
	{
		$countResult = $this->createMock(IResult::class);
		$countResult->method('fetchOne')->willReturn(1);
		$defaultResult = $this->createMock(IResult::class);
		$defaultResult->method('fetch')->willReturn(['id' => 1]);

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
			$qb->method('where')->willReturnSelf();
			$qb->method('expr')->willReturn($expr);
			$qb->method('createNamedParameter')->willReturn('p');
			$qb->method('createFunction')->willReturn('COUNT(*)');
			$qb->method('executeQuery')->willReturn($result);
			return $qb;
		};

		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturn(true);
		$db->method('getQueryBuilder')->willReturnCallback(function () use ($mkSelect, $defaultResult, $countResult) {
			static $i = 0;
			$i++;
			return ($i % 2 === 1) ? $mkSelect($defaultResult) : $mkSelect($countResult);
		});
		return $db;
	}
}
