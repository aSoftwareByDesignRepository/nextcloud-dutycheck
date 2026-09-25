<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\Service;

use OCA\DutyCheck\Db\SchemaProbe;
use OCA\DutyCheck\Service\RosterService;
use OCA\DutyCheck\Service\SwapService;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

final class SwapCandidatesTest extends TestCase
{
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

	public function testListSwapCandidatesEmptyWithoutSharedLocations(): void
	{
		$empResult = $this->createMock(IResult::class);
		$empResult->method('fetch')->willReturn(['id' => 10]);
		$locsResult = $this->createMock(IResult::class);
		$locsResult->method('fetchAll')->willReturn([]); // no belonging locations

		$expr = new class {
			public function eq(...$a)
			{
				return 'eq';
			}
			public function neq(...$a)
			{
				return 'neq';
			}
			public function gte(...$a)
			{
				return 'gte';
			}
			public function lte(...$a)
			{
				return 'lte';
			}
			public function in(...$a)
			{
				return 'in';
			}
			public function orX(...$a)
			{
				return 'orX';
			}
			public function isNull(...$a)
			{
				return 'isNull';
			}
		};

		$mk = function ($result) use ($expr) {
			$qb = $this->createMock(IQueryBuilder::class);
			$qb->method('select')->willReturnSelf();
			$qb->method('selectDistinct')->willReturnSelf();
			$qb->method('from')->willReturnSelf();
			$qb->method('where')->willReturnSelf();
			$qb->method('andWhere')->willReturnSelf();
			$qb->method('innerJoin')->willReturnSelf();
			$qb->method('groupBy')->willReturnSelf();
			$qb->method('orderBy')->willReturnSelf();
			$qb->method('expr')->willReturn($expr);
			$qb->method('createNamedParameter')->willReturn('p');
			$qb->method('setMaxResults')->willReturnSelf();
			$qb->method('executeQuery')->willReturn($result);
			return $qb;
		};

		$probeResult = $this->createMock(IResult::class);

		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturnCallback(static fn (string $t): bool => in_array($t, ['dc_employees', 'dc_assignments'], true));
		$db->method('getQueryBuilder')->willReturnOnConsecutiveCalls(
			$mk($empResult),
			$mk($locsResult),
			$mk($probeResult), // SchemaProbe::hasColumn probe query (cold static cache)
		);

		$svc = new SwapService($db, $this->createMock(RosterService::class));
		$out = $svc->listSwapCandidates('alice');
		self::assertSame([], $out);
	}

	public function testListSwapCandidatesExcludesSelfAndBlankNames(): void
	{
		$empResult = $this->createMock(IResult::class);
		$empResult->method('fetch')->willReturn(['id' => 10]);
		$locsResult = $this->createMock(IResult::class);
		$locsResult->method('fetchAll')->willReturn([
			['location_id' => 3],
		]);
		$listResult = $this->createMock(IResult::class);
		$listResult->method('fetchAll')->willReturn([
			['id' => 11, 'display_name' => 'Bob'],
			['id' => 12, 'display_name' => '  '],
			['id' => 13, 'display_name' => 'Cara'],
		]);

		$expr = new class {
			public function eq(...$a)
			{
				return 'eq';
			}
			public function neq(...$a)
			{
				return 'neq';
			}
			public function gte(...$a)
			{
				return 'gte';
			}
			public function lte(...$a)
			{
				return 'lte';
			}
			public function in(...$a)
			{
				return 'in';
			}
			public function orX(...$a)
			{
				return 'orX';
			}
			public function isNull(...$a)
			{
				return 'isNull';
			}
		};

		$mk = function ($result) use ($expr) {
			$qb = $this->createMock(IQueryBuilder::class);
			$qb->method('select')->willReturnSelf();
			$qb->method('selectDistinct')->willReturnSelf();
			$qb->method('from')->willReturnSelf();
			$qb->method('where')->willReturnSelf();
			$qb->method('andWhere')->willReturnSelf();
			$qb->method('innerJoin')->willReturnSelf();
			$qb->method('groupBy')->willReturnSelf();
			$qb->method('orderBy')->willReturnSelf();
			$qb->method('expr')->willReturn($expr);
			$qb->method('createNamedParameter')->willReturn('p');
			$qb->method('setMaxResults')->willReturnSelf();
			$qb->method('executeQuery')->willReturn($result);
			return $qb;
		};

		$probeResult = $this->createMock(IResult::class);

		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturnCallback(static fn (string $t): bool => in_array($t, ['dc_employees', 'dc_assignments'], true));
		$db->method('getQueryBuilder')->willReturnOnConsecutiveCalls(
			$mk($empResult),
			$mk($locsResult),
			$mk($probeResult), // SchemaProbe::hasColumn probe query (cold static cache)
			$mk($listResult),
		);

		$svc = new SwapService($db, $this->createMock(RosterService::class));
		$out = $svc->listSwapCandidates('alice');
		self::assertSame([
			['id' => 11, 'displayName' => 'Bob'],
			['id' => 13, 'displayName' => 'Cara'],
		], $out);
	}
}
