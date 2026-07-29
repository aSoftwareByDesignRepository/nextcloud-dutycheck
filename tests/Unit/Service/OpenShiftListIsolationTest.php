<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\Service;

use OCA\DutyCheck\Db\SchemaProbe;
use OCA\DutyCheck\Service\CompanyService;
use OCA\DutyCheck\Service\OpenShiftService;
use OCA\DutyCheck\Service\RosterService;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class OpenShiftListIsolationTest extends TestCase
{
	protected function setUp(): void
	{
		SchemaProbe::resetCache();
		$ref = new ReflectionClass(SchemaProbe::class);
		$prop = $ref->getProperty('columnCache');
		$prop->setAccessible(true);
		$prop->setValue(null, ['dc_open_shifts.company_id' => true]);
	}

	protected function tearDown(): void
	{
		SchemaProbe::resetCache();
	}

	public function testListOpenAppliesCompanyRestrictQuery(): void
	{
		$expr = new class {
			public function eq(...$a)
			{
				return 'eq';
			}
		};

		$result = $this->createMock(IResult::class);
		$result->method('fetchAll')->willReturn([]);

		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('select')->willReturnSelf();
		$qb->method('from')->willReturnSelf();
		$qb->method('leftJoin')->willReturnSelf();
		$qb->method('where')->willReturnSelf();
		$qb->method('andWhere')->willReturnSelf();
		$qb->method('orderBy')->willReturnSelf();
		$qb->method('addOrderBy')->willReturnSelf();
		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturn('p');
		$qb->method('executeQuery')->willReturn($result);

		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturn(true);
		$db->method('getQueryBuilder')->willReturn($qb);

		$companies = $this->createMock(CompanyService::class);
		$companies->expects(self::once())
			->method('restrictQuery')
			->with($qb, 'o.company_id', 'alice');

		$svc = new OpenShiftService($db, $this->createMock(RosterService::class), $companies);
		self::assertSame([], $svc->listOpen(null, 'alice'));
	}

	public function testPoolSwapCreateReceivesCompanyService(): void
	{
		// Construction with companies must not throw; stamps rely on SchemaProbe + write path.
		$svc = new OpenShiftService(
			$this->createMock(IDBConnection::class),
			$this->createMock(RosterService::class),
			$this->createMock(CompanyService::class),
		);
		self::assertInstanceOf(OpenShiftService::class, $svc);
	}
}
