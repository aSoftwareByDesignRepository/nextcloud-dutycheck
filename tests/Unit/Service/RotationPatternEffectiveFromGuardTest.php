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
 * Regression for the effective_from forward-only invariant on update:
 * anchorFieldsChanged() compares type/iso/date only, so a payload that moves
 * ONLY anchor_effective_from used to bypass ANCHOR_EFFECTIVE_IN_PAST —
 * a planner could backdate (or on fixed_date clear) the activation point and
 * re-anchor already-past weeks. Live-reproduced via PUT
 * /api/rotation-patterns/{id} {"anchorEffectiveFrom":"2026-11-11"} -> 200;
 * this test pins the reject without a DB.
 */
final class RotationPatternEffectiveFromGuardTest extends TestCase
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

	private function row(array $overrides = []): array
	{
		return array_merge([
			'id' => 74,
			'company_id' => 1,
			'name' => 'GA-E2E',
			'cycle_weeks' => 2,
			'anchor_type' => 'fixed_date',
			'anchor_iso_week_index' => null,
			'anchor_date' => '2026-01-05',
			'anchor_effective_from' => '2199-01-01',
			'contract_avg_minutes' => null,
			'is_active' => 1,
			'created_by' => 'admin',
			'updated_by' => 'admin',
			'created_at' => '2026-01-01 00:00:00',
			'updated_at' => '2026-01-01 00:00:00',
		], $overrides);
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

	/** Guard throws before the write path — a single read QB suffices. */
	private function serviceReadingRow(array $existing): RotationPatternService
	{
		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturn(true);
		$db->method('getQueryBuilder')->willReturn($this->qb($this->result($existing)));

		$companies = $this->createMock(CompanyService::class);
		$companies->method('assertCanAccessCompany');

		$settings = $this->createMock(SelfServiceSettingsService::class);
		$settings->method('isRotationEnabled')->willReturn(true);
		$settings->method('allowedCycleWeeks')->willReturn([1, 2, 3, 4]);

		return new RotationPatternService($db, $companies, $settings,
			$this->createMock(RotationAnchorService::class));
	}

	public function testSoloPastEffectiveFromMoveRejected(): void
	{
		$svc = $this->serviceReadingRow($this->row());
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('ANCHOR_EFFECTIVE_IN_PAST');
		$svc->updatePattern(74, [
			'anchorEffectiveFrom' => (new \DateTimeImmutable('yesterday'))->format('Y-m-d'),
		], 'admin');
	}

	public function testClearEffectiveFromOnFixedDateRejected(): void
	{
		$svc = $this->serviceReadingRow($this->row());
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('ANCHOR_EFFECTIVE_IN_PAST');
		$svc->updatePattern(74, ['anchorEffectiveFrom' => ''], 'admin');
	}

	public function testResendStoredEffectiveFromIsNotAMove(): void
	{
		// Stored value is already in the past; re-sending it verbatim must not
		// trip the guard (an untouched stored date must not block edits).
		// The single-QB stub re-reads the fixture on every fetch, so the whole
		// update path completes — success IS the assertion.
		$svc = $this->serviceReadingRow($this->row(['anchor_effective_from' => '2020-01-01']));
		$out = $svc->updatePattern(74, ['anchorEffectiveFrom' => '2020-01-01'], 'admin');
		self::assertSame(74, $out['id']);
	}
}
