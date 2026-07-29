<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\Service;

use OCA\DutyCheck\Service\SnapshotRetentionService;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IConfig;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class SnapshotRetentionServiceTest extends TestCase
{
	public function testPruneDisabledWhenRetentionZero(): void
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturn('0');
		$svc = new SnapshotRetentionService(
			$this->createMock(IDBConnection::class),
			$config,
			$this->createMock(LoggerInterface::class),
		);
		$result = $svc->pruneExpired();
		self::assertFalse($result['enabled']);
		self::assertSame(0, $result['deleted']);
	}

	public function testProtectsClosedPeriodSnapshots(): void
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturn('30');

		$latestResult = $this->createMock(IResult::class);
		$latestResult->method('fetchAll')->willReturn([
			['id' => 55, 'period_id' => 1],
		]);
		$closedTipsResult = $this->createMock(IResult::class);
		$closedTipsResult->method('fetchAll')->willReturn([
			['close_snapshot_id' => 55],
		]);
		$prevResult = $this->createMock(IResult::class);
		$prevResult->method('fetch')->willReturn(['prev_snapshot_id' => null]);
		$oldResult = $this->createMock(IResult::class);
		$oldResult->method('fetchAll')->willReturn([
			['id' => 55],
			['id' => 56],
		]);

		$expr = $this->expr();
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnOnConsecutiveCalls(
			$this->selectQb($latestResult, $expr),
			$this->selectQb($closedTipsResult, $expr),
			$this->selectQb($prevResult, $expr),
			$this->selectQb($oldResult, $expr),
			$this->deleteQb($expr, 1),
		);

		$svc = new SnapshotRetentionService($db, $config, $this->createMock(LoggerInterface::class));
		$result = $svc->pruneExpired();
		self::assertTrue($result['enabled']);
		self::assertSame(1, $result['deleted']);
		self::assertSame(30, $result['retentionDays']);
	}

	public function testProtectsHashChainPredecessors(): void
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturn('30');

		$latestResult = $this->createMock(IResult::class);
		$latestResult->method('fetchAll')->willReturn([
			['id' => 56, 'period_id' => 1],
		]);
		$closedTipsResult = $this->createMock(IResult::class);
		$closedTipsResult->method('fetchAll')->willReturn([
			['close_snapshot_id' => 56],
		]);
		$prevOf56 = $this->createMock(IResult::class);
		$prevOf56->method('fetch')->willReturn(['prev_snapshot_id' => 55]);
		$prevOf55 = $this->createMock(IResult::class);
		$prevOf55->method('fetch')->willReturn(['prev_snapshot_id' => null]);
		$oldResult = $this->createMock(IResult::class);
		$oldResult->method('fetchAll')->willReturn([
			['id' => 55],
			['id' => 56],
			['id' => 99],
		]);

		$expr = $this->expr();
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnOnConsecutiveCalls(
			$this->selectQb($latestResult, $expr),
			$this->selectQb($closedTipsResult, $expr),
			$this->selectQb($prevOf56, $expr),
			$this->selectQb($prevOf55, $expr),
			$this->selectQb($oldResult, $expr),
			$this->deleteQb($expr, 1),
		);

		$svc = new SnapshotRetentionService($db, $config, $this->createMock(LoggerInterface::class));
		$result = $svc->pruneExpired();
		self::assertTrue($result['enabled']);
		self::assertSame(1, $result['deleted']);
	}

	public function testProtectsLatestCloseTipAfterReopenClearsPointer(): void
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturn('30');

		// Period reopened: close_snapshot_id cleared, but latest close row must survive.
		$latestResult = $this->createMock(IResult::class);
		$latestResult->method('fetchAll')->willReturn([
			['id' => 55, 'period_id' => 7],
		]);
		$closedTipsResult = $this->createMock(IResult::class);
		$closedTipsResult->method('fetchAll')->willReturn([]);
		$prevResult = $this->createMock(IResult::class);
		$prevResult->method('fetch')->willReturn(['prev_snapshot_id' => null]);
		$oldResult = $this->createMock(IResult::class);
		$oldResult->method('fetchAll')->willReturn([
			['id' => 55],
			['id' => 90],
		]);

		$expr = $this->expr();
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnOnConsecutiveCalls(
			$this->selectQb($latestResult, $expr),
			$this->selectQb($closedTipsResult, $expr),
			$this->selectQb($prevResult, $expr),
			$this->selectQb($oldResult, $expr),
			$this->deleteQb($expr, 1),
		);

		$svc = new SnapshotRetentionService($db, $config, $this->createMock(LoggerInterface::class));
		$result = $svc->pruneExpired();
		self::assertTrue($result['enabled']);
		self::assertSame(1, $result['deleted']);
	}

	private function expr(): object
	{
		return new class {
			public function eq(...$a)
			{
				return 'eq';
			}

			public function lt(...$a)
			{
				return 'lt';
			}

			public function isNotNull(...$a)
			{
				return 'nn';
			}
		};
	}

	private function selectQb(IResult $result, object $expr): IQueryBuilder
	{
		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('select')->willReturnSelf();
		$qb->method('from')->willReturnSelf();
		$qb->method('where')->willReturnSelf();
		$qb->method('andWhere')->willReturnSelf();
		$qb->method('orderBy')->willReturnSelf();
		$qb->method('addOrderBy')->willReturnSelf();
		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturn('p');
		$qb->method('executeQuery')->willReturn($result);
		return $qb;
	}

	private function deleteQb(object $expr, int $expectedDeletes): IQueryBuilder
	{
		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('delete')->willReturnSelf();
		$qb->method('where')->willReturnSelf();
		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturn('p');
		$qb->expects($this->exactly($expectedDeletes))->method('executeStatement')->willReturn(1);
		return $qb;
	}
}
