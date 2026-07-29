<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\Service;

use OCA\DutyCheck\Db\SchemaProbe;
use OCA\DutyCheck\Service\RosterService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Mutation-hardening tests for the createAssignment() validation gates
 * (payload coercion, id guards, note limits, shift length, period status,
 * duty-date range, existence asserts).
 *
 * Every test pins the exact error code so an escaped mutant that shifts the
 * failure to a later gate produces a different, detectable message.
 */
final class RosterServiceMutationCreateAssignmentGuardsTest extends TestCase
{
	use RosterServiceMutationMockTrait;

	protected function setUp(): void
	{
		parent::setUp();
		SchemaProbe::resetCache();
		// Seed probes so SchemaProbe never consumes getQueryBuilder slots from
		// consecutive-call mocks (periodById may check company_id; create later
		// checks status/version/slot_key).
		$ref = new ReflectionClass(SchemaProbe::class);
		$prop = $ref->getProperty('columnCache');
		$prop->setAccessible(true);
		$prop->setValue(null, [
			'dc_periods.company_id' => false,
			'dc_employees.company_id' => false,
			'dc_locations.company_id' => false,
			'dc_absences.company_id' => false,
			'dc_assignments.status' => true,
			'dc_assignments.version' => true,
			'dc_assignments.slot_key' => true,
			'dc_periods.conflict_thresholds_json' => false,
			'dc_shift_templates.min_headcount' => false,
		]);
	}

	protected function tearDown(): void
	{
		SchemaProbe::resetCache();
		parent::tearDown();
	}

	private function periodRow(string $status = 'open'): array
	{
		return [
			'id' => 10,
			'start_date' => '2026-07-01',
			'end_date' => '2026-07-31',
			'status' => $status,
			'created_by' => 'planner-1',
			'created_at' => '2026-06-01 00:00:00',
			'published_at' => $status === 'published' ? '2026-06-20 00:00:00' : null,
			'closed_at' => null,
			'close_snapshot_id' => null,
		];
	}

	/**
	 * Service whose DB always yields the same builder: periodById() sees the
	 * given period row and every existence probe (fetchOne) reports "missing".
	 */
	private function serviceWithPeriod(string $status = 'open'): RosterService
	{
		$qb = $this->rosterQb(['fetch' => $this->periodRow($status), 'fetchOne' => false]);
		return new RosterService($this->rosterDbAlways($qb));
	}

	private function payload(array $overrides = []): array
	{
		return array_replace([
			'periodId' => 10,
			'employeeId' => 4,
			'locationId' => 6,
			'dutyDate' => '2026-07-15',
			'startTime' => '08:00',
			'endTime' => '16:00',
			'breakMinutes' => 0,
			'note' => '',
		], $overrides);
	}

	private function payloadWithout(string ...$keys): array
	{
		$payload = $this->payload();
		foreach ($keys as $key) {
			unset($payload[$key]);
		}
		return $payload;
	}

	private function expectRosterError(string $code): void
	{
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage($code);
	}

	public function testMissingPeriodIdIsRejected(): void
	{
		$this->expectRosterError('PERIOD_ID_REQUIRED');
		$this->serviceWithPeriod()->createAssignment($this->payloadWithout('periodId', 'employeeId'), 'planner-1');
	}

	public function testZeroPeriodIdIsRejected(): void
	{
		$this->expectRosterError('PERIOD_ID_REQUIRED');
		$this->serviceWithPeriod()->createAssignment($this->payload(['periodId' => 0]), 'planner-1');
	}

	public function testNonNumericPeriodIdIsRejected(): void
	{
		$this->expectRosterError('PERIOD_ID_REQUIRED');
		$this->serviceWithPeriod()->createAssignment($this->payload(['periodId' => 'abc']), 'planner-1');
	}

	public function testMissingEmployeeIdIsRejected(): void
	{
		$this->expectRosterError('EMPLOYEE_ID_REQUIRED');
		$this->serviceWithPeriod()->createAssignment($this->payloadWithout('employeeId'), 'planner-1');
	}

	public function testZeroEmployeeIdIsRejected(): void
	{
		$this->expectRosterError('EMPLOYEE_ID_REQUIRED');
		$this->serviceWithPeriod()->createAssignment($this->payload(['employeeId' => 0]), 'planner-1');
	}

	public function testNonNumericEmployeeIdIsRejected(): void
	{
		$this->expectRosterError('EMPLOYEE_ID_REQUIRED');
		$this->serviceWithPeriod()->createAssignment($this->payload(['employeeId' => 'abc']), 'planner-1');
	}

	public function testMissingLocationIdIsRejected(): void
	{
		$this->expectRosterError('LOCATION_ID_REQUIRED');
		$this->serviceWithPeriod()->createAssignment($this->payloadWithout('locationId'), 'planner-1');
	}

	public function testZeroLocationIdIsRejected(): void
	{
		$this->expectRosterError('LOCATION_ID_REQUIRED');
		$this->serviceWithPeriod()->createAssignment($this->payload(['locationId' => 0]), 'planner-1');
	}

	public function testNonNumericLocationIdIsRejected(): void
	{
		$this->expectRosterError('LOCATION_ID_REQUIRED');
		$this->serviceWithPeriod()->createAssignment($this->payload(['locationId' => 'abc']), 'planner-1');
	}

	public function testIntegerDutyDateIsCastAndRejectedAsInvalidDate(): void
	{
		$this->expectRosterError('INVALID_DATE');
		$this->serviceWithPeriod()->createAssignment($this->payload(['dutyDate' => 20260715]), 'planner-1');
	}

	public function testMalformedDutyDateIsRejected(): void
	{
		$this->expectRosterError('INVALID_DATE');
		$this->serviceWithPeriod()->createAssignment($this->payload(['dutyDate' => 'not-a-date']), 'planner-1');
	}

	public function testIntegerStartTimeIsCastAndRejectedAsInvalidTime(): void
	{
		$this->expectRosterError('INVALID_TIME');
		$this->serviceWithPeriod()->createAssignment($this->payload(['startTime' => 800]), 'planner-1');
	}

	public function testIntegerEndTimeIsCastAndRejectedAsInvalidTime(): void
	{
		$this->expectRosterError('INVALID_TIME');
		$this->serviceWithPeriod()->createAssignment($this->payload(['endTime' => 1600]), 'planner-1');
	}

	public function testNoteLongerThan512CharactersIsRejected(): void
	{
		$this->expectRosterError('NOTE_TOO_LONG');
		$this->serviceWithPeriod()->createAssignment(
			$this->payload(['note' => str_repeat('x', 513)]),
			'planner-1',
		);
	}

	public function testNoteOfExactly512CharactersPassesTheNoteGate(): void
	{
		// The break consumes the whole shift, so the next gate must fire.
		$this->expectRosterError('INVALID_SHIFT_LENGTH');
		$this->serviceWithPeriod()->createAssignment(
			$this->payload([
				'note' => str_repeat('x', 512),
				'startTime' => '08:00',
				'endTime' => '09:00',
				'breakMinutes' => 60,
			]),
			'planner-1',
		);
	}

	public function testNoteLengthIsMeasuredInCharactersNotBytes(): void
	{
		// 400 multibyte chars = 800 bytes; must still pass the 512-char gate.
		$this->expectRosterError('INVALID_SHIFT_LENGTH');
		$this->serviceWithPeriod()->createAssignment(
			$this->payload([
				'note' => str_repeat('ü', 400),
				'startTime' => '08:00',
				'endTime' => '09:00',
				'breakMinutes' => 60,
			]),
			'planner-1',
		);
	}

	public function testWhitespaceOnlyNoteIsTrimmedBeforeLengthCheck(): void
	{
		$this->expectRosterError('INVALID_SHIFT_LENGTH');
		$this->serviceWithPeriod()->createAssignment(
			$this->payload([
				'note' => str_repeat(' ', 600),
				'startTime' => '08:00',
				'endTime' => '09:00',
				'breakMinutes' => 60,
			]),
			'planner-1',
		);
	}

	public function testIntegerNoteIsCastToStringBeforeTrimming(): void
	{
		$this->expectRosterError('INVALID_SHIFT_LENGTH');
		$this->serviceWithPeriod()->createAssignment(
			$this->payload([
				'note' => 12345,
				'startTime' => '08:00',
				'endTime' => '09:00',
				'breakMinutes' => 60,
			]),
			'planner-1',
		);
	}

	public function testPlannerCreateRejectsPublishedPeriod(): void
	{
		$this->expectRosterError('PERIOD_NOT_OPEN');
		$this->serviceWithPeriod('published')->createAssignment($this->payload(), 'planner-1');
	}

	public function testMarketplaceCreateStillAllowsOpenPeriod(): void
	{
		// Marketplace mode must keep 'open' in the allowed statuses; the flow
		// then proceeds until the employee existence probe fails.
		$this->expectRosterError('EMPLOYEE_NOT_FOUND');
		$this->serviceWithPeriod('open')->createAssignment($this->payload(), 'planner-1', true);
	}

	public function testDutyDateOnPeriodStartIsInsidePeriod(): void
	{
		$this->expectRosterError('EMPLOYEE_NOT_FOUND');
		$this->serviceWithPeriod()->createAssignment($this->payload(['dutyDate' => '2026-07-01']), 'planner-1');
	}

	public function testDutyDateOnPeriodEndIsInsidePeriod(): void
	{
		$this->expectRosterError('EMPLOYEE_NOT_FOUND');
		$this->serviceWithPeriod()->createAssignment($this->payload(['dutyDate' => '2026-07-31']), 'planner-1');
	}

	public function testDutyDateBeforePeriodStartIsRejected(): void
	{
		$this->expectRosterError('DATE_OUTSIDE_PERIOD');
		$this->serviceWithPeriod()->createAssignment($this->payload(['dutyDate' => '2026-06-30']), 'planner-1');
	}

	public function testDutyDateAfterPeriodEndIsRejected(): void
	{
		$this->expectRosterError('DATE_OUTSIDE_PERIOD');
		$this->serviceWithPeriod()->createAssignment($this->payload(['dutyDate' => '2026-08-01']), 'planner-1');
	}

	public function testMissingLocationIsRejectedAfterEmployeeCheck(): void
	{
		$qbPeriod = $this->rosterQb(['fetch' => $this->periodRow('open')]);
		$qbEmployee = $this->rosterQb(['fetchOne' => 4]);
		$qbLocation = $this->rosterQb(['fetchOne' => false]);
		$service = new RosterService($this->rosterDb($qbPeriod, $qbEmployee, $qbLocation));

		$this->expectRosterError('LOCATION_NOT_FOUND');
		$service->createAssignment($this->payload(), 'planner-1');
	}
}
