<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\Service;

use OCA\DutyCheck\Db\SchemaProbe;
use OCA\DutyCheck\Integration\IArbeitszeitCheckIntegration;
use OCA\DutyCheck\Service\CompanyService;
use OCA\DutyCheck\Service\RosterService;
use OCP\DB\QueryBuilder\ICompositeExpression;
use OCP\DB\QueryBuilder\IQueryBuilder;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Mutation-hardening tests for the absence lifecycle: transitionAbsence()
 * state machine, review-reason gate, approved-overlap guard (including the
 * ArbeitszeitCheck mirror), listAbsences() row mapping and company scoping.
 */
final class RosterServiceMutationAbsenceLifecycleTest extends TestCase
{
	use RosterServiceMutationMockTrait;

	protected function setUp(): void
	{
		SchemaProbe::resetCache();
		$ref = new ReflectionClass(SchemaProbe::class);
		$prop = $ref->getProperty('columnCache');
		$prop->setAccessible(true);
		$prop->setValue(null, [
			'dc_absences.company_id' => true,
			'dc_employees.company_id' => true,
		]);
	}

	protected function tearDown(): void
	{
		SchemaProbe::resetCache();
	}

	private function absenceRow(string $status, array $overrides = []): array
	{
		return array_replace([
			'id' => 42,
			'employee_id' => 7,
			'start_date' => '2026-06-01',
			'end_date' => '2026-06-02',
			'status' => $status,
		], $overrides);
	}

	public function testRejectedAbsenceCanBeReopenedToPending(): void
	{
		$qbAbsence = $this->rosterQb(['fetch' => $this->absenceRow('rejected')]);
		$qbUpdate = $this->rosterQb(['statementOnce' => true], $updateParams);
		$qbList = $this->rosterQb(['fetchAll' => []]);
		$service = new RosterService($this->rosterDb($qbAbsence, $qbUpdate, $qbList));

		$out = $service->transitionAbsence(42, 'pending', '', 'planner-1');

		self::assertSame([], $out);
		// set() parameter order: status, review_reason, reviewed_at, reviewed_by, id.
		self::assertSame('pending', $updateParams[0][0]);
		self::assertSame(null, $updateParams[1][0], 'empty review reason must be stored as NULL');
		self::assertSame('planner-1', $updateParams[3][0]);
		self::assertSame(42, $updateParams[4][0]);
		self::assertSame(IQueryBuilder::PARAM_INT, $updateParams[4][1]);
	}

	public function testCancelledAbsenceCanBeReopenedToPendingWithPaddedStatus(): void
	{
		$qbAbsence = $this->rosterQb(['fetch' => $this->absenceRow('cancelled')]);
		$qbUpdate = $this->rosterQb(['statementOnce' => true]);
		$qbList = $this->rosterQb(['fetchAll' => []]);
		$service = new RosterService($this->rosterDb($qbAbsence, $qbUpdate, $qbList));

		self::assertSame([], $service->transitionAbsence(42, '  pending  ', '', 'planner-1'));
	}

	public function testCancellingApprovedAbsenceRequiresSubstantialReason(): void
	{
		$qbAbsence = $this->rosterQb(['fetch' => $this->absenceRow('approved')]);
		$service = new RosterService($this->rosterDb($qbAbsence));

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('REASON_TOO_SHORT');
		$service->transitionAbsence(42, 'cancelled', '   short   ', 'planner-1');
	}

	public function testRejectingRequiresSubstantialReason(): void
	{
		$qbAbsence = $this->rosterQb(['fetch' => $this->absenceRow('pending')]);
		$service = new RosterService($this->rosterDb($qbAbsence));

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('REASON_TOO_SHORT');
		$service->transitionAbsence(42, 'rejected', 'no', 'planner-1');
	}

	public function testRejectReasonIsMeasuredInCharactersNotBytes(): void
	{
		$qbAbsence = $this->rosterQb(['fetch' => $this->absenceRow('pending')]);
		$service = new RosterService($this->rosterDb($qbAbsence));

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('REASON_TOO_SHORT');
		$service->transitionAbsence(42, 'rejected', 'äëïöü', 'planner-1');
	}

	public function testRejectAcceptsExactlyTenCharacterReason(): void
	{
		$qbAbsence = $this->rosterQb(['fetch' => $this->absenceRow('pending')]);
		$qbUpdate = $this->rosterQb(['statementOnce' => true], $updateParams);
		$qbList = $this->rosterQb(['fetchAll' => []]);
		$service = new RosterService($this->rosterDb($qbAbsence, $qbUpdate, $qbList));

		self::assertSame([], $service->transitionAbsence(42, 'rejected', 'abcdefghij', 'planner-1'));
		self::assertSame('abcdefghij', $updateParams[1][0]);
	}

	public function testApproveRunsOverlapGuardWithScopedStatusAndIgnoreId(): void
	{
		$at = $this->createMock(IArbeitszeitCheckIntegration::class);
		$at->method('integrationLocksLinkedDutyCheckAbsences')->willReturn(false);
		$at->expects(self::once())
			->method('mirrorOverlapsEmployeeRange')
			->with(7, '2026-06-01', '2026-06-02', ['approved'], null)
			->willReturn(false);
		$at->method('isEffective')->willReturn(false);

		$composite = $this->createMock(ICompositeExpression::class);
		$composite->expects(self::once())->method('add')->willReturnSelf();

		$qbAbsence = $this->rosterQb(['fetch' => $this->absenceRow('pending')]);
		$qbOverlap = $this->rosterQb([
			'fetchOne' => false,
			'selectOnce' => true,
			'maxResultsOnce' => 1,
			'andWhereExactly' => 4,
			'composite' => $composite,
		], $overlapParams);
		$qbUpdate = $this->rosterQb(['statementOnce' => true]);
		$qbList = $this->rosterQb(['fetchAll' => []]);
		$service = new RosterService(
			$this->rosterDb($qbAbsence, $qbOverlap, $qbUpdate, $qbList),
			null,
			$at,
		);

		self::assertSame([], $service->transitionAbsence(42, 'approved', 'approved because valid', 'planner-1'));

		$this->assertParamCaptured([7, IQueryBuilder::PARAM_INT], $overlapParams);
		$this->assertParamCaptured(['approved', IQueryBuilder::PARAM_STR, ':status0'], $overlapParams);
		$this->assertParamCaptured([42, IQueryBuilder::PARAM_INT], $overlapParams, 'ignoreId must exclude the row itself');
	}

	public function testApproveSucceedsWithoutIntegrationConfigured(): void
	{
		$qbAbsence = $this->rosterQb(['fetch' => $this->absenceRow('pending')]);
		$qbOverlap = $this->rosterQb(['fetchOne' => false]);
		$qbUpdate = $this->rosterQb(['statementOnce' => true]);
		$qbList = $this->rosterQb(['fetchAll' => []]);
		$service = new RosterService($this->rosterDb($qbAbsence, $qbOverlap, $qbUpdate, $qbList));

		self::assertSame([], $service->transitionAbsence(42, 'approved', 'approved because valid', 'planner-1'));
	}

	public function testIntegrationLockRejectsNumericLinkedUser(): void
	{
		$at = $this->createMock(IArbeitszeitCheckIntegration::class);
		$at->method('integrationLocksLinkedDutyCheckAbsences')->willReturn(true);

		$qbAbsence = $this->rosterQb(['fetch' => $this->absenceRow('pending', ['employee_id' => 55])]);
		$qbLinked = $this->rosterQb(
			['fetchOne' => 123, 'selectOnce' => true, 'maxResultsOnce' => 1],
			$linkedParams,
		);
		$service = new RosterService($this->rosterDb($qbAbsence, $qbLinked), null, $at);

		try {
			$service->transitionAbsence(42, 'approved', 'approved because valid', 'planner-1');
			self::fail('Expected INTEGRATION_ABSENCE_READONLY');
		} catch (\InvalidArgumentException $e) {
			self::assertSame('INTEGRATION_ABSENCE_READONLY', $e->getMessage());
		}

		$this->assertParamCaptured([55, IQueryBuilder::PARAM_INT], $linkedParams);
		$this->assertParamCaptured([1, IQueryBuilder::PARAM_INT], $linkedParams);
	}

	public function testIntegrationLockIgnoresWhitespaceOnlyLinkedUser(): void
	{
		$at = $this->createMock(IArbeitszeitCheckIntegration::class);
		$at->method('integrationLocksLinkedDutyCheckAbsences')->willReturn(true);
		$at->method('mirrorOverlapsEmployeeRange')->willReturn(false);
		$at->method('isEffective')->willReturn(false);

		$qbAbsence = $this->rosterQb(['fetch' => $this->absenceRow('pending')]);
		$qbLinked = $this->rosterQb(['fetchOne' => '   ']);
		$qbOverlap = $this->rosterQb(['fetchOne' => false]);
		$qbUpdate = $this->rosterQb(['statementOnce' => true]);
		$qbList = $this->rosterQb(['fetchAll' => []]);
		$service = new RosterService(
			$this->rosterDb($qbAbsence, $qbLinked, $qbOverlap, $qbUpdate, $qbList),
			null,
			$at,
		);

		self::assertSame([], $service->transitionAbsence(42, 'approved', 'approved because valid', 'planner-1'));
	}

	public function testTransitionScopesEmployeeCompanyAndListsForActor(): void
	{
		$companies = $this->createMock(CompanyService::class);
		$companies->expects(self::once())
			->method('assertRowCompany')
			->with('planner-1', 'dc_employees', 7);
		$companies->expects(self::once())
			->method('restrictQuery')
			->with(self::anything(), 'a.company_id', 'planner-1');

		$qbAbsence = $this->rosterQb(['fetch' => $this->absenceRow('pending')]);
		$qbUpdate = $this->rosterQb(['statementOnce' => true]);
		$qbList = $this->rosterQb(['fetchAll' => []]);
		$service = new RosterService(
			$this->rosterDb($qbAbsence, $qbUpdate, $qbList),
			companies: $companies,
		);

		self::assertSame([], $service->transitionAbsence(42, 'rejected', 'rejected for cause', 'planner-1'));
	}

	public function testListAbsencesWithoutActorSkipsCompanyScope(): void
	{
		$companies = $this->createMock(CompanyService::class);
		$companies->expects(self::never())->method('restrictQuery');

		$qbList = $this->rosterQb(['fetchAll' => []]);
		$service = new RosterService($this->rosterDb($qbList), companies: $companies);

		self::assertSame([], $service->listAbsences(null));
	}

	public function testListAbsencesMapsAndCastsRows(): void
	{
		$qbList = $this->rosterQb(['selectOnce' => true, 'fetchAll' => [
			[
				'id' => '5',
				'employee_id' => '9',
				'kind' => 'vacation',
				'start_date' => '2026-07-02',
				'end_date' => '2026-07-03',
				'status' => 'approved',
				'review_reason' => null,
				'display_name' => null,
			],
			[
				'id' => 6,
				'employee_id' => 10,
				'kind' => 'sick',
				'start_date' => '2026-07-01',
				'end_date' => '2026-07-01',
				'status' => 'rejected',
				'review_reason' => 'because reasons',
				'display_name' => 'Bob',
			],
			[
				'id' => '7',
				'employee_id' => '11',
				'kind' => 8,
				'start_date' => 20260628,
				'end_date' => 20260629,
				'status' => 9,
				'review_reason' => 42,
				'display_name' => 456,
			],
		]]);
		$service = new RosterService($this->rosterDb($qbList));

		self::assertSame([
			[
				'id' => 5,
				'source' => 'dutycheck',
				'employeeId' => 9,
				'employeeName' => '',
				'kind' => 'vacation',
				'startDate' => '2026-07-02',
				'endDate' => '2026-07-03',
				'status' => 'approved',
				'reviewReason' => '',
			],
			[
				'id' => 6,
				'source' => 'dutycheck',
				'employeeId' => 10,
				'employeeName' => 'Bob',
				'kind' => 'sick',
				'startDate' => '2026-07-01',
				'endDate' => '2026-07-01',
				'status' => 'rejected',
				'reviewReason' => 'because reasons',
			],
			[
				'id' => 7,
				'source' => 'dutycheck',
				'employeeId' => 11,
				'employeeName' => '456',
				'kind' => '8',
				'startDate' => '20260628',
				'endDate' => '20260629',
				'status' => '9',
				'reviewReason' => '42',
			],
		], $service->listAbsences(null));
	}

	public function testAbsenceByIdMapsAndCastsRow(): void
	{
		$qb = $this->rosterQb(['selectOnce' => true, 'fetch' => [
			'id' => '42',
			'employee_id' => '7',
			'start_date' => 20260601,
			'end_date' => 20260602,
			'status' => 5,
		]]);
		$service = new RosterService($this->rosterDb($qb));

		$m = new ReflectionMethod(RosterService::class, 'absenceById');
		$m->setAccessible(true);

		self::assertSame([
			'id' => 42,
			'employeeId' => 7,
			'startDate' => '20260601',
			'endDate' => '20260602',
			'status' => '5',
		], $m->invoke($service, 42));
	}

	public function testAbsenceByIdThrowsForMissingRow(): void
	{
		$qb = $this->rosterQb(['fetch' => false]);
		$service = new RosterService($this->rosterDb($qb));

		$m = new ReflectionMethod(RosterService::class, 'absenceById');
		$m->setAccessible(true);

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('ABSENCE_NOT_FOUND');
		$m->invoke($service, 42);
	}

	public function testUnknownTargetStatusIsRejected(): void
	{
		$service = new RosterService($this->rosterDb());

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('INVALID_ABSENCE_STATUS');
		$service->transitionAbsence(42, 'archived', '', 'planner-1');
	}

	public function testApprovedAbsenceCannotBeApprovedAgain(): void
	{
		$qbAbsence = $this->rosterQb(['fetch' => $this->absenceRow('approved')]);
		$service = new RosterService($this->rosterDb($qbAbsence));

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('INVALID_ABSENCE_TRANSITION');
		$service->transitionAbsence(42, 'approved', '', 'planner-1');
	}
}
