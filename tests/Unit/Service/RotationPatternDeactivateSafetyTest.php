<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\Service;

use OCA\DutyCheck\Db\SchemaProbe;
use OCA\DutyCheck\Service\CompanyService;
use OCA\DutyCheck\Service\RotationAnchorService;
use OCA\DutyCheck\Service\RotationPatternService;
use OCA\DutyCheck\Service\SelfServiceSettingsService;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * Regression for the deactivate safety valve: a soft-delete must never be
 * blocked by validation of the pattern's *stored* state. Two live-reproduced
 * blockers:
 *
 *  1. rotation_patterns_enabled=false  -> ROTATION_DISABLED (cannot delete
 *     patterns in exactly the state where a company turned the feature off)
 *  2. rotation_allowed_cycle_weeks narrowed after creation -> the stored
 *     cycle_weeks fails assertCycleWeeksAllowed -> CYCLE_WEEKS_UNSUPPORTED
 *     (pattern becomes undeletable)
 *
 * Verified live against the dev instance before the fix; this test pins the
 * service-level behavior without a DB.
 */
final class RotationPatternDeactivateSafetyTest extends TestCase
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

	/**
	 * @return array{id:int,company_id:int,name:string,cycle_weeks:int,anchor_type:string,
	 *   anchor_iso_week_index:?int,anchor_date:?string,anchor_effective_from:?string,
	 *   contract_avg_minutes:?int,is_active:int,created_by:string,updated_by:string,
	 *   created_at:string,updated_at:string}
	 */
	private function patternRowFixture(int $isActive = 1): array
	{
		return [
			'id' => 74,
			'company_id' => 1,
			'name' => 'GA-E2E',
			'cycle_weeks' => 2,
			'anchor_type' => 'iso_week_parity',
			'anchor_iso_week_index' => 0,
			'anchor_date' => null,
			'anchor_effective_from' => null,
			'contract_avg_minutes' => null,
			'is_active' => $isActive,
			'created_by' => 'admin',
			'updated_by' => 'admin',
			'created_at' => '2026-01-01 00:00:00',
			'updated_at' => '2026-01-01 00:00:00',
		];
	}

	private function qb(IResult $result): IQueryBuilder
	{
		$expr = $this->createMock(\OCP\DB\QueryBuilder\IExpressionBuilder::class);
		$expr->method('eq')->willReturn('eq');

		$qb = $this->createMock(IQueryBuilder::class);
		foreach (['select', 'from', 'where', 'andWhere', 'orderBy', 'addOrderBy',
			'update', 'set', 'insert', 'values', 'delete'] as $m) {
			$qb->method($m)->willReturnSelf();
		}
		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturn('p');
		$qb->method('executeQuery')->willReturn($result);
		$qb->method('executeStatement')->willReturn(1);
		return $qb;
	}

	private function result(?array $row, array $rows = []): IResult
	{
		$res = $this->createMock(IResult::class);
		$res->method('fetch')->willReturn($row ?? false);
		$res->method('fetchAll')->willReturn($rows);
		return $res;
	}

	public function testDeactivateSucceedsWhenRotationDisabled(): void
	{
		$existing = $this->patternRowFixture(1);
		$after = $this->patternRowFixture(0);

		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturn(true);
		// QB sequence: patternRow read -> update -> audit insert -> getPattern's
		// patternRow re-read -> loadWeekDays.
		$db->method('getQueryBuilder')->willReturnOnConsecutiveCalls(
			$this->qb($this->result($existing)),
			$this->qb($this->result(null)),
			$this->qb($this->result(null)),
			$this->qb($this->result($after)),
			$this->qb($this->result(null, [])),
		);
		$db->method('beginTransaction');
		$db->method('inTransaction')->willReturn(false);
		$db->method('commit');
		$db->method('rollBack');

		$companies = $this->createMock(CompanyService::class);
		$companies->method('assertCanAccessCompany');

		$settings = $this->createMock(SelfServiceSettingsService::class);
		// The flag is OFF — deactivation must not consult it at all.
		$settings->expects($this->never())->method('isRotationEnabled');
		$settings->method('allowedCycleWeeks')->willReturn([1, 2, 3, 4]);

		$anchors = $this->createMock(RotationAnchorService::class);

		$svc = new RotationPatternService($db, $companies, $settings, $anchors);
		$out = $svc->deactivatePattern(74, 'admin');

		self::assertFalse($out['isActive'], 'pattern must be inactive after deactivatePattern');
	}

	public function testDeactivateSucceedsWhenStoredCycleWeeksNoLongerAllowed(): void
	{
		$existing = $this->patternRowFixture(1); // stored cycle_weeks = 2
		$after = $this->patternRowFixture(0);

		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturn(true);
		$db->method('getQueryBuilder')->willReturnOnConsecutiveCalls(
			$this->qb($this->result($existing)),
			$this->qb($this->result(null)),
			$this->qb($this->result(null)),
			$this->qb($this->result($after)),
			$this->qb($this->result(null, [])),
		);
		$db->method('beginTransaction');
		$db->method('inTransaction')->willReturn(false);
		$db->method('commit');
		$db->method('rollBack');

		$companies = $this->createMock(CompanyService::class);
		$companies->method('assertCanAccessCompany');

		$settings = $this->createMock(SelfServiceSettingsService::class);
		$settings->method('isRotationEnabled')->willReturn(true);
		// Admin narrowed allowed cycles to [3] AFTER the pattern was created —
		// the unchanged stored value must not be re-validated.
		$settings->method('allowedCycleWeeks')->willReturn([3]);

		$anchors = $this->createMock(RotationAnchorService::class);
		// cycleWeeks is unchanged by a deactivation — validation must not run.
		$anchors->expects($this->never())->method('assertCycleWeeksAllowed');

		$svc = new RotationPatternService($db, $companies, $settings, $anchors);
		$out = $svc->deactivatePattern(74, 'admin');

		self::assertFalse($out['isActive']);
	}
}
