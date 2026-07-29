<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\Integration;

use OCA\DutyCheck\Db\SchemaProbe;
use OCA\DutyCheck\Integration\MaintenanceCheckOnDutyReader;
use OCA\DutyCheck\Service\CompanyService;
use OCP\App\IAppManager;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IConfig;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class MaintenanceCheckOnDutyCompanyScopeTest extends TestCase
{
	protected function setUp(): void
	{
		SchemaProbe::resetCache();
	}

	public function testRestrictsByCallerCompanyWhenActorProvided(): void
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturn('1');
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForUser')->willReturn(true);

		$companies = $this->createMock(CompanyService::class);
		$companies->expects($this->once())->method('restrictQuery')
			->with($this->isInstanceOf(IQueryBuilder::class), 'p.company_id', 'planner-a');

		$expr = new class {
			public function eq(...$a) { return 'eq'; }
			public function in(...$a) { return 'in'; }
			public function orX(...$a) { return 'orX'; }
			public function neq(...$a) { return 'neq'; }
			public function isNull(...$a) { return 'isNull'; }
		};
		$result = $this->createMock(IResult::class);
		$result->method('fetchAll')->willReturn([]);
		$result->method('closeCursor');

		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('select')->willReturnSelf();
		$qb->method('from')->willReturnSelf();
		$qb->method('innerJoin')->willReturnSelf();
		$qb->method('leftJoin')->willReturnSelf();
		$qb->method('where')->willReturnSelf();
		$qb->method('andWhere')->willReturnSelf();
		$qb->method('orderBy')->willReturnSelf();
		$qb->method('setMaxResults')->willReturnSelf();
		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturn('p');
		$qb->method('executeQuery')->willReturn($result);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);
		$db->method('tableExists')->willReturn(true);

		$reader = new MaintenanceCheckOnDutyReader(
			$db,
			$config,
			$apps,
			$this->createMock(LoggerInterface::class),
			$companies,
		);
		self::assertSame([], $reader->onDutyToday('2099-01-01', 'planner-a'));
	}
}
