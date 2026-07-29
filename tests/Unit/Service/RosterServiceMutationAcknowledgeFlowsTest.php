<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\Service;

use OCA\DutyCheck\Db\SchemaProbe;
use OCA\DutyCheck\Service\RosterService;
use OCP\DB\QueryBuilder\IQueryBuilder;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Mutation-hardening tests for acknowledgeAssignment(), acknowledgeConflict()
 * and the linked-employee resolution they rely on.
 */
final class RosterServiceMutationAcknowledgeFlowsTest extends TestCase
{
	use RosterServiceMutationMockTrait;

	protected function setUp(): void
	{
		SchemaProbe::resetCache();
		$ref = new ReflectionClass(SchemaProbe::class);
		$prop = $ref->getProperty('columnCache');
		$prop->setAccessible(true);
		$prop->setValue(null, [
			'dc_assignments.status' => true,
			'dc_assignments.version' => false,
			'dc_periods.conflict_thresholds_json' => false,
			'dc_shift_templates.min_headcount' => false,
		]);
	}

	protected function tearDown(): void
	{
		SchemaProbe::resetCache();
	}

	private function assignmentRow(array $overrides = []): array
	{
		return array_replace([
			'id' => 42,
			'period_id' => 3,
			'employee_id' => 7,
			'location_id' => 2,
			'duty_date' => '2026-07-10',
			'start_time' => '08:00',
			'end_time' => '16:00',
			'break_minutes' => 30,
			'note' => '',
			'status' => 'active',
			'acknowledged_at' => null,
			'acknowledged_by' => null,
		], $overrides);
	}

	private function publishedPeriodRow(): array
	{
		return [
			'id' => 3,
			'start_date' => '2026-07-01',
			'end_date' => '2026-07-31',
			'status' => 'published',
			'created_by' => 'planner-1',
			'created_at' => '2026-06-01 00:00:00',
			'published_at' => '2026-06-20 00:00:00',
			'closed_at' => null,
			'close_snapshot_id' => null,
		];
	}

	public function testCancelledAssignmentCannotBeAcknowledged(): void
	{
		$qbAssignment = $this->rosterQb(['fetch' => $this->assignmentRow(['status' => 'cancelled'])]);
		$service = new RosterService($this->rosterDb($qbAssignment));

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('ASSIGNMENT_CANCELLED');
		$service->acknowledgeAssignment(42, 'alice');
	}

	public function testStringableCancelledStatusIsCastBeforeComparison(): void
	{
		$status = new class {
			public function __toString(): string
			{
				return 'cancelled';
			}
		};
		$qbAssignment = $this->rosterQb(['fetch' => $this->assignmentRow(['status' => $status])]);
		$service = new RosterService($this->rosterDb($qbAssignment));

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('ASSIGNMENT_CANCELLED');
		$service->acknowledgeAssignment(42, 'alice');
	}

	public function testStringRowIdsAreCastForOwnershipAndPeriodLookup(): void
	{
		$qbAssignment = $this->rosterQb(['fetch' => $this->assignmentRow([
			'employee_id' => '7',
			'period_id' => '3',
			'acknowledged_at' => '2099-01-02 10:00:00',
			'acknowledged_by' => 'bob',
		])]);
		$qbEmployee = $this->rosterQb(['fetchOne' => '7']);
		$qbPeriod = $this->rosterQb(['fetch' => $this->publishedPeriodRow()]);
		$service = new RosterService($this->rosterDb($qbAssignment, $qbEmployee, $qbPeriod));

		$out = $service->acknowledgeAssignment(42, 'alice');

		self::assertSame(42, $out['assignmentId']);
		self::assertSame('2099-01-02 10:00:00', $out['acknowledgedAt']);
		self::assertSame('bob', $out['acknowledgedBy']);
	}

	public function testIdempotentReturnCastsStoredAckValuesToStrings(): void
	{
		$qbAssignment = $this->rosterQb(['fetch' => $this->assignmentRow([
			'acknowledged_at' => 20990102,
			'acknowledged_by' => 123,
		])]);
		$qbEmployee = $this->rosterQb(['fetchOne' => 7]);
		$qbPeriod = $this->rosterQb(['fetch' => $this->publishedPeriodRow()]);
		$service = new RosterService($this->rosterDb($qbAssignment, $qbEmployee, $qbPeriod));

		$out = $service->acknowledgeAssignment(42, 'alice');

		self::assertSame('20990102', $out['acknowledgedAt']);
		self::assertSame('123', $out['acknowledgedBy']);
	}

	public function testFalsyNonNullAckTimestampStillWritesAcknowledgement(): void
	{
		$qbAssignment = $this->rosterQb(['fetch' => $this->assignmentRow(['acknowledged_at' => false])]);
		$qbEmployee = $this->rosterQb(['fetchOne' => 7]);
		$qbPeriod = $this->rosterQb(['fetch' => $this->publishedPeriodRow()]);
		$qbUpdate = $this->rosterQb(['statementOnce' => true]);
		$qbFresh = $this->rosterQb(['fetch' => $this->assignmentRow([
			'acknowledged_at' => '2099-01-03 09:00:00',
			'acknowledged_by' => 'alice',
		])]);
		$service = new RosterService($this->rosterDb($qbAssignment, $qbEmployee, $qbPeriod, $qbUpdate, $qbFresh));

		$out = $service->acknowledgeAssignment(42, 'alice');

		self::assertSame('2099-01-03 09:00:00', $out['acknowledgedAt']);
		self::assertSame('alice', $out['acknowledgedBy']);
	}

	public function testUnknownActorLinkFailsWithDedicatedError(): void
	{
		$qbAssignment = $this->rosterQb(['fetch' => $this->assignmentRow()]);
		$qbEmployee = $this->rosterQb(
			['fetchOne' => false, 'selectOnce' => true, 'maxResultsOnce' => 1],
			$employeeParams,
		);
		$service = new RosterService($this->rosterDb($qbAssignment, $qbEmployee));

		try {
			$service->acknowledgeAssignment(42, 'alice');
			self::fail('Expected EMPLOYEE_LINK_NOT_FOUND');
		} catch (\InvalidArgumentException $e) {
			self::assertSame('EMPLOYEE_LINK_NOT_FOUND', $e->getMessage());
		}

		$this->assertParamCaptured(['alice'], $employeeParams);
		$this->assertParamCaptured([1, IQueryBuilder::PARAM_INT], $employeeParams);
	}

	public function testAcknowledgeConflictReasonIsMeasuredInCharacters(): void
	{
		// 5 multibyte characters are 10 bytes; the guard must use mb_strlen.
		$service = new RosterService($this->rosterDb());

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('REASON_TOO_SHORT');
		$service->acknowledgeConflict(5, 'alice', 'äëïöü');
	}

	public function testAcknowledgeConflictReasonIsTrimmedBeforeLengthCheck(): void
	{
		$qbConflict = $this->rosterQb(['fetch' => false]);
		$service = new RosterService($this->rosterDb($qbConflict));

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('REASON_TOO_SHORT');
		$service->acknowledgeConflict(5, 'alice', '   short   ');
	}

	public function testAcknowledgeConflictAcceptsTenCharacterReasonAndQueriesConflict(): void
	{
		$qbConflict = $this->rosterQb(['fetch' => false, 'selectOnce' => true]);
		$service = new RosterService($this->rosterDb($qbConflict));

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('CONFLICT_NOT_FOUND');
		$service->acknowledgeConflict(5, 'alice', 'abcdefghij');
	}

	public function testAcknowledgeConflictCastsPeriodIdAndDetectsResolvedRow(): void
	{
		$qbConflict = $this->rosterQb(['fetch' => [
			'id' => 5,
			'period_id' => '3',
			'context_hash' => 'hash',
			'is_resolved' => '1',
		]]);
		$service = new RosterService($this->rosterDb($qbConflict));

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('CONFLICT_RESOLVED');
		$service->acknowledgeConflict(5, 'alice', 'a valid reason');
	}
}
