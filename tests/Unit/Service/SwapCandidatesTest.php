<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\Service;

use OCA\DutyCheck\Service\RosterService;
use OCA\DutyCheck\Service\SwapService;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

final class SwapCandidatesTest extends TestCase
{
	public function testListSwapCandidatesExcludesSelfAndBlankNames(): void
	{
		$empResult = $this->createMock(IResult::class);
		$empResult->method('fetch')->willReturn(['id' => 10]);
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
		};

		$mk = function ($result) use ($expr) {
			$qb = $this->createMock(IQueryBuilder::class);
			$qb->method('select')->willReturnSelf();
			$qb->method('from')->willReturnSelf();
			$qb->method('where')->willReturnSelf();
			$qb->method('andWhere')->willReturnSelf();
			$qb->method('orderBy')->willReturnSelf();
			$qb->method('expr')->willReturn($expr);
			$qb->method('createNamedParameter')->willReturn('p');
			$qb->method('executeQuery')->willReturn($result);
			return $qb;
		};

		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->with('dc_employees')->willReturn(true);
		$db->method('getQueryBuilder')->willReturnOnConsecutiveCalls(
			$mk($empResult),
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
