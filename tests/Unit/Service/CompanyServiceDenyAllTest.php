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
 * SF-03 — multi-company with no membership is deny-all, never Default company.
 * Legacy (one active company) stays unrestricted.
 */
final class CompanyServiceDenyAllTest extends TestCase
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

	public function testEmptyMembershipIsDenyAllNotDefaultCompany(): void
	{
		$svc = $this->multiCompanyService([]);
		self::assertSame([], $svc->companyIdsForUser('nobody'));
		self::assertFalse($svc->hasCompanyMembership('nobody'));
	}

	public function testMissingMembersTableIsDenyAllWhenMultiCompanyActive(): void
	{
		$countResult = $this->createMock(IResult::class);
		$countResult->method('fetchOne')->willReturn(2);
		$defaultResult = $this->createMock(IResult::class);
		$defaultResult->method('fetch')->willReturn(['id' => 1]);

		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturnCallback(
			static fn (string $table): bool => $table !== 'dc_company_members',
		);
		$db->method('getQueryBuilder')->willReturnOnConsecutiveCalls(
			$this->selectQb($defaultResult),
			$this->selectQb($countResult),
		);

		$svc = new CompanyService($db);
		self::assertTrue($svc->isMultiCompanyActive());
		self::assertSame([], $svc->companyIdsForUser('alice'));
		self::assertFalse($svc->hasCompanyMembership('alice'));
	}

	public function testRestrictQueryDenyAllNeverMatchesARealCompanyId(): void
	{
		$svc = $this->multiCompanyService([]);
		$captured = [];
		$expr = new class {
			public function eq(...$a)
			{
				return 'eq';
			}

			public function in(...$a)
			{
				return 'in';
			}
		};
		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('expr')->willReturn($expr);
		$qb->expects(self::once())->method('andWhere')->with('eq')->willReturnSelf();
		$qb->method('createNamedParameter')->willReturnCallback(
			static function (...$args) use (&$captured): string {
				$captured[] = $args;
				return 'p';
			}
		);

		$svc->restrictQuery($qb, 'company_id', 'nobody');
		self::assertContains(
			[CompanyService::DENY_ALL_COMPANY_ID, IQueryBuilder::PARAM_INT],
			array_map(static fn (array $call): array => array_slice($call, 0, 2), $captured),
		);
		self::assertNotContains(
			[CompanyService::DEFAULT_COMPANY_ID, IQueryBuilder::PARAM_INT],
			array_map(static fn (array $call): array => array_slice($call, 0, 2), $captured),
		);
	}

	public function testWriteCompanyIdForThrowsWhenMultiCompanyAndNoMembership(): void
	{
		$svc = $this->multiCompanyService([]);
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('COMPANY_MEMBERSHIP_REQUIRED');
		$svc->writeCompanyIdFor('nobody');
	}

	public function testAssertCanAccessCompanyThrowsWhenNoMembership(): void
	{
		$svc = $this->multiCompanyService([]);
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('FORBIDDEN');
		$svc->assertCanAccessCompany('nobody', CompanyService::DEFAULT_COMPANY_ID);
	}

	public function testMembershipStillScopesToListedCompanies(): void
	{
		$svc = $this->multiCompanyService([['company_id' => 4], ['company_id' => 9]]);
		self::assertSame([4, 9], $svc->companyIdsForUser('alice'));
		self::assertTrue($svc->hasCompanyMembership('alice'));
		self::assertSame(4, $svc->writeCompanyIdFor('alice'));
		$svc->assertCanAccessCompany('alice', 9);
	}

	public function testLegacySingleCompanyWriteStampsDefault(): void
	{
		$countResult = $this->createMock(IResult::class);
		$countResult->method('fetchOne')->willReturn(1);
		$defaultResult = $this->createMock(IResult::class);
		$defaultResult->method('fetch')->willReturn(['id' => 1]);

		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturn(true);
		$db->method('getQueryBuilder')->willReturnCallback(function () use ($defaultResult, $countResult) {
			static $i = 0;
			$i++;
			return ($i % 2 === 1) ? $this->selectQb($defaultResult) : $this->selectQb($countResult);
		});

		$svc = new CompanyService($db);
		self::assertFalse($svc->isMultiCompanyActive());
		self::assertTrue($svc->hasCompanyMembership('anyone'));
		self::assertSame(CompanyService::DEFAULT_COMPANY_ID, $svc->writeCompanyIdFor('anyone'));
		$svc->assertCanAccessCompany('anyone', 99);
	}

	/**
	 * @param list<array{company_id:int}> $memberRows
	 */
	private function multiCompanyService(array $memberRows): CompanyService
	{
		$countResult = $this->createMock(IResult::class);
		$countResult->method('fetchOne')->willReturn(2);
		$defaultResult = $this->createMock(IResult::class);
		$defaultResult->method('fetch')->willReturn(['id' => 1]);
		$memberResult = $this->createMock(IResult::class);
		$memberResult->method('fetchAll')->willReturn($memberRows);

		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturn(true);
		$db->method('getQueryBuilder')->willReturnOnConsecutiveCalls(
			$this->selectQb($defaultResult),
			$this->selectQb($countResult),
			$this->selectQb($memberResult),
		);

		$svc = new CompanyService($db);
		self::assertTrue($svc->isMultiCompanyActive());
		return $svc;
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
		$qb->method('orderBy')->willReturnSelf();
		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturn('p');
		$qb->method('createFunction')->willReturn('COUNT(*)');
		$qb->method('executeQuery')->willReturn($result);
		return $qb;
	}
}
