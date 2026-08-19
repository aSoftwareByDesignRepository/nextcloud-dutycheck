<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\Service;

use OCA\DutyCheck\Db\SchemaProbe;
use OCA\DutyCheck\Service\CompanyService;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Company scoping used to re-run schema probes + COUNT(*) on every list
 * (periods, employees, locations) inside one roster GET.
 */
final class CompanyServiceRequestMemoTest extends TestCase
{
	protected function setUp(): void
	{
		SchemaProbe::resetCache();
		$ref = new ReflectionClass(SchemaProbe::class);
		$prop = $ref->getProperty('columnCache');
		$prop->setAccessible(true);
		$prop->setValue(null, [
			'dc_employees.company_id' => true,
			'dc_locations.company_id' => true,
			'dc_periods.company_id' => true,
		]);
	}

	protected function tearDown(): void
	{
		SchemaProbe::resetCache();
	}

	public function testLegacySingleCompanyCountRunsOncePerRequest(): void
	{
		$countResult = $this->createMock(IResult::class);
		$countResult->method('fetchOne')->willReturn(1);
		$defaultResult = $this->createMock(IResult::class);
		$defaultResult->method('fetch')->willReturn(['id' => 1]);

		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturn(true);
		$db->expects(self::exactly(2))->method('getQueryBuilder')->willReturnOnConsecutiveCalls(
			$this->selectQb($defaultResult),
			$this->selectQb($countResult),
		);

		$svc = new CompanyService($db);
		self::assertFalse($svc->isMultiCompanyActive());
		self::assertFalse($svc->isMultiCompanyActive());
		self::assertSame([], $svc->companyIdsForUser('alice'));
		self::assertTrue($svc->schemaReady());
	}

	public function testMembershipListIsReadOncePerUserPerRequest(): void
	{
		$countResult = $this->createMock(IResult::class);
		$countResult->method('fetchOne')->willReturn(2);
		$defaultResult = $this->createMock(IResult::class);
		$defaultResult->method('fetch')->willReturn(['id' => 1]);
		$memberResult = $this->createMock(IResult::class);
		$memberResult->method('fetchAll')->willReturn([['company_id' => 7], ['company_id' => 7]]);

		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturn(true);
		$db->expects(self::exactly(3))->method('getQueryBuilder')->willReturnOnConsecutiveCalls(
			$this->selectQb($defaultResult),
			$this->selectQb($countResult),
			$this->selectQb($memberResult),
		);

		$svc = new CompanyService($db);
		self::assertTrue($svc->isMultiCompanyActive());
		self::assertSame([7], $svc->companyIdsForUser('alice'));
		self::assertSame([7], $svc->companyIdsForUser('alice'));

		$qb = $this->createMock(IQueryBuilder::class);
		$expr = new class {
			public function in(...$a)
			{
				return 'in';
			}
		};
		$qb->method('expr')->willReturn($expr);
		$qb->expects(self::once())->method('andWhere')->willReturnSelf();
		$qb->method('createNamedParameter')->willReturn('p');
		$svc->restrictQuery($qb, 'company_id', 'alice');
	}

	private function selectQb(IResult $result): IQueryBuilder
	{
		$expr = new class {
			public function eq(...$a)
			{
				return 'eq';
			}
		};
		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('select')->willReturnSelf();
		$qb->method('from')->willReturnSelf();
		$qb->method('where')->willReturnSelf();
		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturn('p');
		$qb->method('createFunction')->willReturn('COUNT(*)');
		$qb->method('executeQuery')->willReturn($result);
		return $qb;
	}
}
