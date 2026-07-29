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

final class CompanyServiceLegacyTest extends TestCase
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

	public function testSingleCompanyMeansUnrestrictedLegacyAccess(): void
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
		// ensureDefault + count, twice (isMultiCompanyActive + companyIdsForUser → isMultiCompanyActive)
		$db->method('getQueryBuilder')->willReturnOnConsecutiveCalls(
			$mkSelect($defaultResult),
			$mkSelect($countResult),
			$mkSelect($defaultResult),
			$mkSelect($countResult),
		);

		$svc = new CompanyService($db);
		self::assertFalse($svc->isMultiCompanyActive());
		self::assertSame([], $svc->companyIdsForUser('alice'));
	}
}
