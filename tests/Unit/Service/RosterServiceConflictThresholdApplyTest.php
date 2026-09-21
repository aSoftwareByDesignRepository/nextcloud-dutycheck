<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\Service;

use OCA\DutyCheck\Db\SchemaProbe;
use OCA\DutyCheck\Service\ConflictPolicyService;
use OCA\DutyCheck\Service\RosterService;
use OCP\DB\QueryBuilder\IQueryBuilder;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Open-period conflict-threshold freeze status + apply-to-open.
 */
final class RosterServiceConflictThresholdApplyTest extends TestCase
{
	use RosterServiceMutationMockTrait;

	protected function setUp(): void
	{
		SchemaProbe::resetCache();
		$ref = new ReflectionClass(SchemaProbe::class);
		$prop = $ref->getProperty('columnCache');
		$prop->setValue(null, [
			'dc_periods.conflict_thresholds_json' => true,
		]);
	}

	protected function tearDown(): void
	{
		SchemaProbe::resetCache();
	}

	private function livePolicy(): ConflictPolicyService
	{
		$policy = $this->createMock(ConflictPolicyService::class);
		$policy->method('thresholds')->willReturn(ConflictPolicyService::defaults());
		return $policy;
	}

	public function testOpenPeriodStatusCountsOutdatedFrozenCaps(): void
	{
		$live = ConflictPolicyService::defaults();
		$stale = $live;
		$stale['maxPeriodHard'] = 1200;
		$qb = $this->rosterQb([
			'fetchAll' => [
				['id' => 1, 'conflict_thresholds_json' => json_encode($live, JSON_THROW_ON_ERROR)],
				['id' => 2, 'conflict_thresholds_json' => json_encode($stale, JSON_THROW_ON_ERROR)],
				['id' => 3, 'conflict_thresholds_json' => null],
			],
		]);
		$service = new RosterService($this->rosterDb($qb), null, null, null, null, $this->livePolicy());

		$status = $service->conflictThresholdOpenPeriodStatus('admin');

		self::assertTrue($status['schemaReady']);
		self::assertSame(3, $status['openCount']);
		self::assertSame(2, $status['outdatedCount']);
		self::assertSame(3600, $status['live']['maxPeriodHard']);
	}

	public function testApplyRewritesOutdatedOpenPeriodsAndAudits(): void
	{
		$live = ConflictPolicyService::defaults();
		$stale = $live;
		$stale['maxPeriodHard'] = 900;
		$selectQb = $this->rosterQb([
			'fetchAll' => [
				['id' => 10, 'conflict_thresholds_json' => json_encode($live, JSON_THROW_ON_ERROR)],
				['id' => 11, 'conflict_thresholds_json' => json_encode($stale, JSON_THROW_ON_ERROR)],
			],
		]);
		$updateParams = null;
		$updateQb = $this->rosterQb(['statementOnce' => true], $updateParams);
		$auditParams = null;
		$auditQb = $this->rosterQb(['statementOnce' => true], $auditParams);

		$service = new RosterService(
			$this->rosterDb($selectQb, $updateQb, $auditQb),
			null,
			null,
			null,
			null,
			$this->livePolicy(),
		);

		$result = $service->applyLiveConflictThresholdsToOpenPeriods('admin');

		self::assertSame(1, $result['updated']);
		self::assertSame(1, $result['alreadyCurrent']);
		self::assertSame([11], $result['periodIds']);
		self::assertSame(0, $result['outdatedCount']);
		self::assertParamCaptured(
			[json_encode($live, JSON_THROW_ON_ERROR)],
			$updateParams ?? [],
			'update must write live JSON',
		);
		self::assertParamCaptured([11, IQueryBuilder::PARAM_INT], $updateParams ?? []);
		self::assertParamCaptured(['open'], $updateParams ?? []);
		self::assertParamCaptured(['CONFLICT_THRESHOLDS_REAPPLIED'], $auditParams ?? []);
	}

	public function testApplyFailsClosedWithoutFreezeColumn(): void
	{
		SchemaProbe::resetCache();
		$ref = new ReflectionClass(SchemaProbe::class);
		$prop = $ref->getProperty('columnCache');
		$prop->setValue(null, [
			'dc_periods.conflict_thresholds_json' => false,
		]);

		$service = new RosterService($this->rosterDbAlways($this->rosterQb()), null, null, null, null, $this->livePolicy());

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('SCHEMA_NOT_READY');
		$service->applyLiveConflictThresholdsToOpenPeriods('admin');
	}
}
