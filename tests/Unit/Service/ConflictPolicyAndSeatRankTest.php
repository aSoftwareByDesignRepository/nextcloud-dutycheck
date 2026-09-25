<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\Service;

use OCA\DutyCheck\Service\ConflictPolicyService;
use OCA\DutyCheck\Service\SeatRank;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

final class ConflictPolicyDefaultsTest extends TestCase
{
	public function testDefaultsMatchLegacyHardcodedCaps(): void
	{
		$d = ConflictPolicyService::defaults();
		self::assertSame(600, $d['maxDailyHard']);
		self::assertSame(2880, $d['maxPeriodSoft']);
		self::assertSame(3600, $d['maxPeriodHard']);
		self::assertSame(6, $d['maxConsecutiveDays']);
		self::assertSame(660, $d['minRestMinutes']);
	}
}

final class SeatRankTest extends TestCase
{
	public function testWithinLimitUsesAssignmentOrder(): void
	{
		$ranked = [
			['id' => 1, 'assignedAt' => 100],
			['id' => 2, 'assignedAt' => 200],
			['id' => 3, 'assignedAt' => 300],
		];
		self::assertTrue(SeatRank::isWithinLimit($ranked, 1, 2));
		self::assertTrue(SeatRank::isWithinLimit($ranked, 2, 2));
		self::assertFalse(SeatRank::isWithinLimit($ranked, 3, 2));
	}
}

final class ConflictPolicyClampTest extends TestCase
{
	/**
	 * The hard bounds in save() are safety-critical (rest/limits law). Mutating
	 * any literal must change the clamped value written to the DB.
	 */
	public function testSaveClampsToHardBounds(): void
	{
		$expr = new class {
			public function eq(...$a)
			{
				return 'eq';
			}
		};
		$mkRead = function (mixed $fetchRow) use ($expr) {
			$res = $this->createMock(IResult::class);
			$res->method('fetch')->willReturn($fetchRow);
			$qb = $this->createMock(IQueryBuilder::class);
			$qb->method('select')->willReturnSelf();
			$qb->method('from')->willReturnSelf();
			$qb->method('orderBy')->willReturnSelf();
			$qb->method('setMaxResults')->willReturnSelf();
			$qb->method('expr')->willReturn($expr);
			$qb->method('createNamedParameter')->willReturnArgument(0);
			$qb->method('executeQuery')->willReturn($res);
			return $qb;
		};

		$captured = null;
		$insertQb = $this->createMock(IQueryBuilder::class);
		$insertQb->method('insert')->willReturnSelf();
		$insertQb->method('values')->willReturnCallback(static function (array $v) use (&$captured, $insertQb) {
			$captured = $v;
			return $insertQb;
		});
		$insertQb->method('executeStatement')->willReturn(1);
		$insertQb->method('createNamedParameter')->willReturnArgument(0);

		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturn(true);
		$db->method('getQueryBuilder')->willReturnOnConsecutiveCalls(
			$mkRead(false), // fetchRow in save(): no existing policy row
			$insertQb,
			$mkRead(false), // thresholds() inside get()
			$mkRead(false), // fetchRow() inside get()
		);

		$svc = new ConflictPolicyService($db);
		$svc->save([
			'maxDailyHard' => 99999,
			'maxPeriodSoft' => 999999,
			'maxPeriodHard' => 999999,
			'maxConsecutiveDays' => 365,
			'minRestMinutes' => -50,
		], 'admin');

		self::assertIsArray($captured);
		self::assertSame(24 * 60, $captured['max_daily_hard']);
		self::assertSame(14 * 24 * 60, $captured['max_period_soft']);
		self::assertSame(21 * 24 * 60, $captured['max_period_hard']);
		self::assertSame(31, $captured['max_consec_days']);
		self::assertSame(0, $captured['min_rest_minutes']);
	}

	public function testSaveRejectsHardBelowSoftPeriodCap(): void
	{
		$db = $this->createMock(IDBConnection::class);
		$db->expects(self::never())->method('getQueryBuilder');
		$svc = new ConflictPolicyService($db);
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('INVALID_CONFLICT_POLICY');
		$svc->save(['maxPeriodSoft' => 500, 'maxPeriodHard' => 60], 'admin');
	}
}
