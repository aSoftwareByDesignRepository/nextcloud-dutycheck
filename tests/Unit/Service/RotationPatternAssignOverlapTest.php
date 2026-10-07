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
 * Regression for the invisible blocker behind the customer report
 * "Muster zuweisen" always failing with ASSIGNMENT_OVERLAP:
 *
 *  Deactivating ("deleting") a pattern leaves its dc_emp_rot_assign rows in
 *  place — by design, so a re-activated pattern resumes. But the overlap
 *  check counted those rows, so a deleted pattern kept blocking every new
 *  assignment, and no web view could show the blocking row.
 *
 *  Only assignments on *active* patterns may block; rows pointing at
 *  inactive or missing patterns are inert everywhere else (suggest-fill and
 *  target-hours already skip them via is_active).
 */
final class RotationPatternAssignOverlapTest extends TestCase
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

	private function patternRowFixture(): array
	{
		return [
			'id' => 74,
			'company_id' => 1,
			'name' => 'NR',
			'cycle_weeks' => 4,
			'anchor_type' => 'iso_week_parity',
			'anchor_iso_week_index' => 0,
			'anchor_date' => null,
			'anchor_effective_from' => null,
			'contract_avg_minutes' => null,
			'is_active' => 1,
			'created_by' => 'admin',
			'updated_by' => 'admin',
			'created_at' => '2026-01-01 00:00:00',
			'updated_at' => '2026-01-01 00:00:00',
		];
	}

	/**
	 * @param array<string,mixed>|null $patternIsActive flag joined onto the row
	 */
	private function assignmentRowFixture(?int $patternIsActive): array
	{
		return [
			'id' => 9,
			'employee_id' => 5,
			'pattern_id' => 61,
			'company_id' => 1,
			'valid_from' => '2026-10-01',
			'valid_to' => null,
			'created_by' => 'admin',
			'created_at' => '2026-10-01 10:00:00',
			'pattern_is_active' => $patternIsActive,
		];
	}

	private function qb(IResult $result, int $lastInsertId = 0): IQueryBuilder
	{
		$expr = $this->createMock(\OCP\DB\QueryBuilder\IExpressionBuilder::class);
		$expr->method('eq')->willReturn('eq');
		$expr->method('neq')->willReturn('neq');

		$qb = $this->createMock(IQueryBuilder::class);
		foreach (['select', 'from', 'leftJoin', 'where', 'andWhere',
			'orderBy', 'addOrderBy', 'setMaxResults',
			'update', 'set', 'insert', 'values', 'delete'] as $m) {
			$qb->method($m)->willReturnSelf();
		}
		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturn('p');
		$qb->method('executeQuery')->willReturn($result);
		$qb->method('executeStatement')->willReturn(1);
		$qb->method('getLastInsertId')->willReturn($lastInsertId);
		return $qb;
	}

	private function result(?array $row, array $rows = []): IResult
	{
		$res = $this->createMock(IResult::class);
		$res->method('fetch')->willReturn($row ?? false);
		$res->method('fetchAll')->willReturn($rows);
		$res->method('fetchOne')->willReturn($row !== null ? reset($row) : false);
		return $res;
	}

	private function service(IDBConnection $db): RotationPatternService
	{
		$companies = $this->createMock(CompanyService::class);
		$companies->method('assertCanAccessCompany');
		$companies->method('assertRowCompany');
		$companies->method('isMultiCompanyActive')->willReturn(false);

		$settings = $this->createMock(SelfServiceSettingsService::class);
		$settings->method('isRotationEnabled')->willReturn(true);

		return new RotationPatternService(
			$db,
			$companies,
			$settings,
			$this->createMock(RotationAnchorService::class),
			null,
		);
	}

	private function assignmentRowResult(): array
	{
		return [
			'id' => 42,
			'employee_id' => 5,
			'pattern_id' => 74,
			'company_id' => 1,
			'valid_from' => '2026-11-01',
			'valid_to' => null,
			'created_by' => 'admin',
			'created_at' => '2026-10-07 10:00:00',
		];
	}

	private function employeeRowQb(): IQueryBuilder
	{
		// assertEmployeeInCompany existence probe (single-company path selects id).
		return $this->qb($this->result(['id' => 5]));
	}

	public function testAssignSucceedsWhenOnlyOverlapPointsAtInactivePattern(): void
	{
		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturn(true);
		// QB sequence: patternRow -> employee existence -> findOverlappingAssignments ->
		// insert -> audit insert -> empAssignmentRow.
		$db->method('getQueryBuilder')->willReturnOnConsecutiveCalls(
			$this->qb($this->result($this->patternRowFixture())),
			$this->employeeRowQb(),
			$this->qb($this->result(null, [$this->assignmentRowFixture(0)])),
			$this->qb($this->result(null), 42),
			$this->qb($this->result(null)),
			$this->qb($this->result($this->assignmentRowResult())),
		);
		$db->method('beginTransaction');
		$db->method('inTransaction')->willReturn(false);
		$db->method('commit');
		$db->method('rollBack');

		$out = $this->service($db)->assignToEmployee(5, 74, '2026-11-01', null, 'admin');

		self::assertSame(42, $out['id']);
		self::assertSame('2026-11-01', $out['validFrom']);
	}

	public function testAssignSucceedsWhenOnlyOverlapPointsAtMissingPattern(): void
	{
		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturn(true);
		// LEFT JOIN yields pattern_is_active=null when the pattern row is gone.
		$db->method('getQueryBuilder')->willReturnOnConsecutiveCalls(
			$this->qb($this->result($this->patternRowFixture())),
			$this->employeeRowQb(),
			$this->qb($this->result(null, [$this->assignmentRowFixture(null)])),
			$this->qb($this->result(null), 42),
			$this->qb($this->result(null)),
			$this->qb($this->result($this->assignmentRowResult())),
		);
		$db->method('beginTransaction');
		$db->method('inTransaction')->willReturn(false);
		$db->method('commit');
		$db->method('rollBack');

		$out = $this->service($db)->assignToEmployee(5, 74, '2026-11-01', null, 'admin');

		self::assertSame(42, $out['id']);
	}

	public function testSupersedeDeletesStaleOverlapSharingValidFrom(): void
	{
		// A stale row with the SAME valid_from cannot surface via the overlap
		// block (inactive pattern) but still hits the (employee_id,
		// valid_from) unique index on insert — supersede must remove it.
		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturn(true);
		$deletedIds = [];
		$deleteQb = $this->qb($this->result(null));
		$deleteQb->method('delete')->willReturnCallback(function () use ($deleteQb) {
			return $deleteQb;
		});
		$deleteQb->method('createNamedParameter')->willReturnCallback(
			function ($v) use (&$deletedIds) {
				$deletedIds[] = $v;
				return 'p';
			}
		);
		$stale = $this->assignmentRowFixture(0);
		$stale['valid_from'] = '2026-10-01';
		$db->method('getQueryBuilder')->willReturnOnConsecutiveCalls(
			$this->qb($this->result($this->patternRowFixture())),
			$this->employeeRowQb(),
			$this->qb($this->result(null, [$stale])),
			$deleteQb,
			$this->qb($this->result(null), 42),
			$this->qb($this->result(null)),
			$this->qb($this->result($this->assignmentRowResult())),
		);
		$db->method('beginTransaction');
		$db->method('inTransaction')->willReturn(false);
		$db->method('commit');
		$db->method('rollBack');

		$out = $this->service($db)->assignToEmployee(5, 74, '2026-10-01', null, 'admin', true);

		self::assertSame(42, $out['id']);
		self::assertContains(9, $deletedIds);
	}

	public function testAssignStillFailsWhenOverlapOnActivePattern(): void
	{
		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturn(true);
		// QBs consumed before the throw: patternRow + employee existence + overlaps.
		$db->method('getQueryBuilder')->willReturnOnConsecutiveCalls(
			$this->qb($this->result($this->patternRowFixture())),
			$this->employeeRowQb(),
			$this->qb($this->result(null, [$this->assignmentRowFixture(1)])),
		);
		$db->method('beginTransaction');
		$db->method('inTransaction')->willReturn(true);
		$db->method('rollBack');

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('ASSIGNMENT_OVERLAP');

		$this->service($db)->assignToEmployee(5, 74, '2026-11-01', null, 'admin');
	}
}
