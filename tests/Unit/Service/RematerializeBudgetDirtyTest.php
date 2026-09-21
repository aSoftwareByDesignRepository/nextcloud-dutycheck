<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\Service;

use OCA\DutyCheck\Db\SchemaProbe;
use OCA\DutyCheck\Service\ConflictPolicyService;
use OCA\DutyCheck\Service\RosterService;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Sync rematerialize budget + conflicts_dirty + lazy roster GET + drain.
 */
final class RematerializeBudgetDirtyTest extends TestCase
{
	use RosterServiceMutationMockTrait;

	protected function setUp(): void
	{
		SchemaProbe::resetCache();
		$ref = new ReflectionClass(SchemaProbe::class);
		$prop = $ref->getProperty('columnCache');
		$prop->setValue(null, [
			'dc_periods.conflict_thresholds_json' => true,
			'dc_periods.conflicts_dirty' => true,
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

	/**
	 * @param list<array{id:int,conflict_thresholds_json?:?string}> $openRows
	 */
	private function recordingService(
		IDBConnection $db,
	): RecordingRematerializeRosterService {
		return new RecordingRematerializeRosterService($db, null, null, null, null, $this->livePolicy());
	}

	public function testRematerializeRespectsSyncBudgetAndMarksRemainderDirty(): void
	{
		$openRows = [];
		for ($id = 1; $id <= 5; $id++) {
			$openRows[] = ['id' => $id, 'conflict_thresholds_json' => '{}'];
		}
		$selectQb = $this->rosterQb(['fetchAll' => $openRows]);
		$dirtyParams = [];
		$dirtyQb3 = $this->rosterQb(['statementOnce' => true], $dirtyParams);
		$dirtyParams4 = [];
		$dirtyQb4 = $this->rosterQb(['statementOnce' => true], $dirtyParams4);
		$dirtyParams5 = [];
		$dirtyQb5 = $this->rosterQb(['statementOnce' => true], $dirtyParams5);
		$countQb = $this->rosterQb(['fetchOne' => 3]);

		$service = $this->recordingService(
			$this->rosterDb($selectQb, $dirtyQb3, $dirtyQb4, $dirtyQb5, $countQb),
		);

		$result = $service->rematerializeOpenPeriodConflicts('admin', 2);

		self::assertSame(2, $result['refreshed']);
		self::assertSame(3, $result['dirtyMarked']);
		self::assertSame(3, $result['dirtyRemaining']);
		self::assertSame([1, 2], $result['periodIds']);
		self::assertSame([1, 2], $service->refreshedIds);
		self::assertParamCaptured([1, IQueryBuilder::PARAM_INT], $dirtyParams);
		self::assertParamCaptured([3, IQueryBuilder::PARAM_INT], $dirtyParams);
	}

	public function testRematerializeWithoutDirtyColumnFinishesSyncForCorrectness(): void
	{
		SchemaProbe::resetCache();
		$ref = new ReflectionClass(SchemaProbe::class);
		$prop = $ref->getProperty('columnCache');
		$prop->setValue(null, [
			'dc_periods.conflict_thresholds_json' => true,
			'dc_periods.conflicts_dirty' => false,
		]);

		$selectQb = $this->rosterQb([
			'fetchAll' => [
				['id' => 10, 'conflict_thresholds_json' => '{}'],
				['id' => 11, 'conflict_thresholds_json' => '{}'],
				['id' => 12, 'conflict_thresholds_json' => '{}'],
			],
		]);
		$service = $this->recordingService($this->rosterDb($selectQb));

		$result = $service->rematerializeOpenPeriodConflicts('admin', 1);

		self::assertSame(3, $result['refreshed']);
		self::assertSame(0, $result['dirtyMarked']);
		self::assertSame(0, $result['dirtyRemaining']);
		self::assertSame([10, 11, 12], $service->refreshedIds);
	}

	public function testDrainDirtyOpenPeriodConflictsRefreshesBatch(): void
	{
		$selectQb = $this->rosterQb([
			'fetchAll' => [['id' => 7], ['id' => 8]],
			'maxResultsOnce' => 20,
		]);
		$service = $this->recordingService($this->rosterDb($selectQb));

		self::assertSame(2, $service->drainDirtyOpenPeriodConflicts(20));
		self::assertSame([7, 8], $service->refreshedIds);
	}

	public function testListConflictsForRosterReadRefreshesWhenDirty(): void
	{
		$dirtySelect = $this->rosterQb(['fetchOne' => 1, 'maxResultsOnce' => 1]);
		$service = $this->recordingService($this->rosterDb($dirtySelect));

		$method = new ReflectionMethod(RosterService::class, 'listConflictsForRosterRead');
		$result = $method->invoke($service, 42);

		self::assertSame([], $result);
		self::assertSame([42], $service->refreshedIds);
	}

	public function testListConflictsForRosterReadUsesPersistedWhenClean(): void
	{
		$dirtySelect = $this->rosterQb(['fetchOne' => 0, 'maxResultsOnce' => 1]);
		$persistedSelect = $this->rosterQb([
			'fetchAll' => [
				[
					'id' => 99,
					'type' => 'overlap',
					'severity' => 'hard',
					'payload_json' => json_encode([
						'message' => 'Employee has overlapping assignments (double booking)',
						'assignmentIds' => [1, 2],
						'employeeId' => 5,
					], JSON_THROW_ON_ERROR),
					'is_resolved' => 0,
					'ack_reason' => null,
					'ack_context_hash' => '',
					'context_hash' => 'abc',
					'employee_id' => 5,
					'display_name' => 'Ada',
				],
			],
		]);
		$service = new RosterService(
			$this->rosterDb($dirtySelect, $persistedSelect),
			null,
			null,
			null,
			null,
			$this->livePolicy(),
		);

		$method = new ReflectionMethod(RosterService::class, 'listConflictsForRosterRead');
		$rows = $method->invoke($service, 42);

		self::assertCount(1, $rows);
		self::assertSame(99, (int) ($rows[0]['id'] ?? 0));
		self::assertSame('hard', $rows[0]['severity'] ?? null);
		self::assertSame('Ada', $rows[0]['employeeName'] ?? null);
	}

	public function testSyncBudgetConstantIsPositive(): void
	{
		self::assertSame(8, RosterService::REMATERIALIZE_SYNC_BUDGET);
		self::assertGreaterThan(0, RosterService::REMATERIALIZE_SYNC_BUDGET);
	}
}

/**
 * Stubs refresh so budget/dirty wiring can be unit-tested without full conflict compute.
 */
final class RecordingRematerializeRosterService extends RosterService
{
	/** @var list<int> */
	public array $refreshedIds = [];

	protected function refreshAndListConflicts(int $periodId): array
	{
		$this->refreshedIds[] = $periodId;
		return [];
	}
}
