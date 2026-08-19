<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\Service;

use OCA\DutyCheck\Db\SchemaProbe;
use OCA\DutyCheck\Integration\IArbeitszeitCheckIntegration;
use OCA\DutyCheck\Service\RosterService;
use OCP\DB\QueryBuilder\IQueryBuilder;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Periods page GET helpers must stay SQL-count cheap: hydrating assignments or
 * conflict payloads is what made /periods?periodId=N appear to hang.
 */
final class RosterServicePeriodsPageReadTest extends TestCase
{
	use RosterServiceMutationMockTrait;

	protected function setUp(): void
	{
		parent::setUp();
		SchemaProbe::resetCache();
		$ref = new ReflectionClass(SchemaProbe::class);
		$prop = $ref->getProperty('columnCache');
		$prop->setAccessible(true);
		$prop->setValue(null, [
			'dc_periods.conflict_thresholds_json' => true,
			'dc_assignments.status' => true,
		]);
	}

	protected function tearDown(): void
	{
		SchemaProbe::resetCache();
		parent::tearDown();
	}

	/**
	 * @return array<string, mixed>
	 */
	private function periodRow(int $id = 2): array
	{
		return [
			'id' => $id,
			'start_date' => '2026-01-01',
			'end_date' => '2026-01-31',
			'status' => 'open',
			'created_by' => 'planner',
			'created_at' => '2026-01-01 00:00:00',
			'published_at' => null,
			'closed_at' => null,
			'close_snapshot_id' => null,
			'conflict_thresholds_json' => null,
		];
	}

	public function testAcknowledgeStatsCountsWithoutHydratingAssignments(): void
	{
		$periodParams = [];
		$totalParams = [];
		$ackedParams = [];
		$periodQb = $this->rosterQb(['fetch' => $this->periodRow()], $periodParams);
		$totalQb = $this->rosterQb(['fetchOne' => 4], $totalParams);
		$ackedQb = $this->rosterQb(['fetchOne' => 1], $ackedParams);

		$svc = new RosterService($this->rosterDb($periodQb, $totalQb, $ackedQb));
		$out = $svc->periodAcknowledgeStats(2);

		self::assertSame(4, $out['total']);
		self::assertSame(1, $out['acknowledged']);
		self::assertSame(25.0, $out['percent']);
		$this->assertParamCaptured([2, IQueryBuilder::PARAM_INT], $periodParams);
		$this->assertParamCaptured([2, IQueryBuilder::PARAM_INT], $totalParams);
		$this->assertParamCaptured(['cancelled'], $totalParams, 'total must exclude cancelled assignments');
		$this->assertParamCaptured(['cancelled'], $ackedParams, 'acked must exclude cancelled assignments');
	}

	public function testAcknowledgeStatsZeroAssignmentsIsZeroPercent(): void
	{
		$periodQb = $this->rosterQb(['fetch' => $this->periodRow()]);
		$totalQb = $this->rosterQb(['fetchOne' => 0]);
		$ackedQb = $this->rosterQb(['fetchOne' => 0]);

		$out = (new RosterService($this->rosterDb($periodQb, $totalQb, $ackedQb)))->periodAcknowledgeStats(2);

		self::assertSame(0, $out['total']);
		self::assertSame(0, $out['acknowledged']);
		self::assertSame(0.0, $out['percent']);
	}

	public function testAcknowledgeStatsRoundsOneDecimal(): void
	{
		$periodQb = $this->rosterQb(['fetch' => $this->periodRow()]);
		$totalQb = $this->rosterQb(['fetchOne' => 3]);
		$ackedQb = $this->rosterQb(['fetchOne' => 2]);

		$out = (new RosterService($this->rosterDb($periodQb, $totalQb, $ackedQb)))->periodAcknowledgeStats(2);

		self::assertSame(66.7, $out['percent']);
	}

	public function testAcknowledgeStatsRejectsUnknownPeriod(): void
	{
		$periodQb = $this->rosterQb(['fetch' => false]);
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('PERIOD_NOT_FOUND');
		(new RosterService($this->rosterDb($periodQb)))->periodAcknowledgeStats(99);
	}

	public function testPublishReadinessUsesGroupedCountsNotPayloads(): void
	{
		$periodParams = [];
		$countParams = [];
		$unackParams = [];
		$periodQb = $this->rosterQb(['fetch' => $this->periodRow()], $periodParams);
		$countQb = $this->rosterQb([
			'fetchAll' => [
				['severity' => 'hard', 'cnt' => 2],
				['severity' => 'soft', 'cnt' => 5],
			],
		], $countParams);
		$unackQb = $this->rosterQb(['fetchOne' => 3], $unackParams);

		$out = (new RosterService($this->rosterDb($periodQb, $countQb, $unackQb)))->publishReadiness(2);

		self::assertSame(2, $out['periodId']);
		self::assertSame(2, $out['hardConflicts']);
		self::assertSame(5, $out['softConflicts']);
		self::assertSame(3, $out['unacknowledgedSoftConflicts']);
		self::assertFalse($out['canPublish']);
		self::assertFalse($out['integrationPublishStale']);
		$this->assertParamCaptured([2, IQueryBuilder::PARAM_INT], $countParams);
		$this->assertParamCaptured([0, IQueryBuilder::PARAM_INT], $countParams, 'only unresolved rows');
		$this->assertParamCaptured(['soft'], $unackParams);
		$src = (string) file_get_contents((new \ReflectionClass(RosterService::class))->getFileName());
		$start = strpos($src, 'private function countUnresolvedConflictsBySeverity');
		self::assertNotFalse($start);
		$end = strpos($src, 'private function countUnacknowledgedSoftConflicts', $start);
		self::assertNotFalse($end);
		$fn = substr($src, $start, $end - $start);
		self::assertStringNotContainsString('payload_json', $fn);
		self::assertStringContainsString('groupBy', $fn);
	}

	public function testPublishReadinessAllowsPublishWhenNoHardConflicts(): void
	{
		$periodQb = $this->rosterQb(['fetch' => $this->periodRow()]);
		$countQb = $this->rosterQb(['fetchAll' => [['severity' => 'soft', 'cnt' => 4]]]);
		$unackQb = $this->rosterQb(['fetchOne' => 4]);

		$out = (new RosterService($this->rosterDb($periodQb, $countQb, $unackQb)))->publishReadiness(2);

		self::assertTrue($out['canPublish']);
		self::assertSame(0, $out['hardConflicts']);
		self::assertSame(4, $out['softConflicts']);
		self::assertSame(4, $out['unacknowledgedSoftConflicts']);
	}

	public function testPublishReadinessIgnoresUnknownSeverityBuckets(): void
	{
		$periodQb = $this->rosterQb(['fetch' => $this->periodRow()]);
		$countQb = $this->rosterQb(['fetchAll' => [['severity' => 'info', 'cnt' => 9]]]);
		$unackQb = $this->rosterQb(['fetchOne' => 0]);

		$out = (new RosterService($this->rosterDb($periodQb, $countQb, $unackQb)))->publishReadiness(2);

		self::assertSame(0, $out['hardConflicts']);
		self::assertSame(0, $out['softConflicts']);
		self::assertTrue($out['canPublish']);
	}

	public function testPublishReadinessReadsCountStarAliasFallback(): void
	{
		$periodQb = $this->rosterQb(['fetch' => $this->periodRow()]);
		$countQb = $this->rosterQb(['fetchAll' => [['severity' => 'hard', 'COUNT(*)' => 1]]]);
		$unackQb = $this->rosterQb(['fetchOne' => 0]);

		$out = (new RosterService($this->rosterDb($periodQb, $countQb, $unackQb)))->publishReadiness(2);

		self::assertSame(1, $out['hardConflicts']);
		self::assertFalse($out['canPublish']);
	}

	public function testPublishReadinessEmptyConflictsIsReady(): void
	{
		$periodQb = $this->rosterQb(['fetch' => $this->periodRow()]);
		$countQb = $this->rosterQb(['fetchAll' => []]);
		$unackQb = $this->rosterQb(['fetchOne' => 0]);

		$out = (new RosterService($this->rosterDb($periodQb, $countQb, $unackQb)))->publishReadiness(2);

		self::assertTrue($out['canPublish']);
		self::assertSame(0, $out['hardConflicts']);
		self::assertSame(0, $out['softConflicts']);
		self::assertSame(0, $out['unacknowledgedSoftConflicts']);
	}

	public function testPublishReadinessBlocksWhenIntegrationIsStale(): void
	{
		$at = $this->createMock(IArbeitszeitCheckIntegration::class);
		$at->method('shouldBlockPublishForStale')->willReturn(true);
		$at->method('isStale')->willReturn(true);

		$periodQb = $this->rosterQb(['fetch' => $this->periodRow()]);
		$countQb = $this->rosterQb(['fetchAll' => []]);
		$unackQb = $this->rosterQb(['fetchOne' => 0]);

		$out = (new RosterService($this->rosterDb($periodQb, $countQb, $unackQb), null, $at))->publishReadiness(2);

		self::assertFalse($out['canPublish']);
		self::assertTrue($out['integrationPublishStale']);
		self::assertTrue($out['integrationStale']);
		self::assertSame(0, $out['hardConflicts']);
	}

	public function testPublishReadinessRejectsUnknownPeriod(): void
	{
		$periodQb = $this->rosterQb(['fetch' => false]);
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('PERIOD_NOT_FOUND');
		(new RosterService($this->rosterDb($periodQb)))->publishReadiness(99);
	}

	public function testAcknowledgeStatsSourceDoesNotHydrateAssignmentList(): void
	{
		$src = (string) file_get_contents((new ReflectionClass(RosterService::class))->getFileName());
		$start = strpos($src, 'public function periodAcknowledgeStats');
		self::assertNotFalse($start);
		$end = strpos($src, 'public function copyPeriodAssignments', $start);
		self::assertNotFalse($end);
		$fn = substr($src, $start, $end - $start);
		self::assertStringContainsString('countPeriodAssignments', $fn);
		self::assertStringNotContainsString('listAssignments', $fn);
	}

	public function testListPersistedConflictsCapsAssignmentIdsAndDropsDetails(): void
	{
		$ids = range(1, 40);
		$qb = $this->rosterQb(['fetchAll' => [[
			'id' => 9,
			'type' => 'absence_collision',
			'severity' => 'hard',
			'payload_json' => json_encode([
				'message' => 'Employee assignment collides with approved absence',
				'assignmentIds' => $ids,
				'details' => ['restMinutes' => 480],
			], JSON_THROW_ON_ERROR),
			'is_resolved' => 0,
			'ack_reason' => null,
			'ack_context_hash' => '',
			'context_hash' => 'ctx',
		]]]);
		$svc = new RosterService($this->rosterDb($qb));
		$method = new \ReflectionMethod(RosterService::class, 'listPersistedConflicts');
		$method->setAccessible(true);
		$out = $method->invoke($svc, 2);
		self::assertCount(1, $out);
		self::assertSame([1, 2], $out[0]['assignmentIds']);
		self::assertSame([], $out[0]['details']);
		self::assertSame('Employee assignment collides with approved absence', $out[0]['message']);
		self::assertFalse($out[0]['acknowledged']);
		self::assertFalse($out[0]['ackInvalidated']);
	}
}
