<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\Service;

use OCA\DutyCheck\Service\RosterService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

class RosterServiceConflictHelpersTest extends TestCase
{
	private function service(): RosterService
	{
		$reflection = new ReflectionClass(RosterService::class);
		/** @var RosterService $service */
		$service = $reflection->newInstanceWithoutConstructor();
		return $service;
	}

	public function testMinutesBetweenRangesReturnsGapForNonOverlappingRanges(): void
	{
		$service = $this->service();
		$method = new ReflectionMethod(RosterService::class, 'minutesBetweenRanges');
		$method->setAccessible(true);

		$result = $method->invoke($service, [100, 200], [260, 300]);

		self::assertSame(60, $result);
	}

	public function testMinutesBetweenRangesReturnsMinusOneForOverlaps(): void
	{
		$service = $this->service();
		$method = new ReflectionMethod(RosterService::class, 'minutesBetweenRanges');
		$method->setAccessible(true);

		$result = $method->invoke($service, [100, 240], [200, 320]);

		self::assertSame(-1, $result);
	}

	public function testConflictIdentityNormalizesAssignmentOrder(): void
	{
		$service = $this->service();
		$method = new ReflectionMethod(RosterService::class, 'conflictIdentity');
		$method->setAccessible(true);

		$a = $method->invoke($service, 7, 'double_booking', 33, [90, 11, 42]);
		$b = $method->invoke($service, 7, 'double_booking', 33, [42, 90, 11]);

		self::assertSame($a, $b);
		self::assertSame('7|double_booking|33|11,42,90', $a);
	}

	public function testComputePublishReadinessFromConflicts(): void
	{
		$service = $this->service();
		$method = new ReflectionMethod(RosterService::class, 'computePublishReadinessFromConflicts');
		$method->setAccessible(true);

		$result = $method->invoke($service, 12, [
			['severity' => 'hard'],
			['severity' => 'soft', 'acknowledged' => false],
			['severity' => 'soft', 'acknowledged' => true],
		]);

		self::assertSame(12, $result['periodId']);
		self::assertSame(1, $result['hardConflicts']);
		self::assertSame(2, $result['softConflicts']);
		self::assertSame(1, $result['unacknowledgedSoftConflicts']);
		self::assertFalse($result['canPublish']);
	}

	public function testConflictAckStateAcknowledged(): void
	{
		$method = new ReflectionMethod(RosterService::class, 'conflictAckState');
		$method->setAccessible(true);
		$result = $method->invoke(null, 'abc123', 'abc123');

		self::assertTrue($result['acknowledged']);
		self::assertFalse($result['ackInvalidated']);
	}

	public function testConflictAckStateInvalidated(): void
	{
		$method = new ReflectionMethod(RosterService::class, 'conflictAckState');
		$method->setAccessible(true);
		$result = $method->invoke(null, 'old-hash', 'new-hash');

		self::assertFalse($result['acknowledged']);
		self::assertTrue($result['ackInvalidated']);
	}

	public function testConflictAckStateNoAck(): void
	{
		$method = new ReflectionMethod(RosterService::class, 'conflictAckState');
		$method->setAccessible(true);
		$result = $method->invoke(null, '', 'new-hash');

		self::assertFalse($result['acknowledged']);
		self::assertFalse($result['ackInvalidated']);
	}

	public function testIsValidIcalTokenAcceptsExpectedFormat(): void
	{
		$service = $this->service();
		$method = new ReflectionMethod(RosterService::class, 'isValidIcalToken');
		$method->setAccessible(true);

		$result = $method->invoke($service, str_repeat('a1', 24));

		self::assertTrue($result);
	}

	public function testIsValidIcalTokenRejectsInvalidFormat(): void
	{
		$service = $this->service();
		$method = new ReflectionMethod(RosterService::class, 'isValidIcalToken');
		$method->setAccessible(true);

		self::assertFalse($method->invoke($service, ''));
		self::assertFalse($method->invoke($service, 'abc'));
		self::assertFalse($method->invoke($service, str_repeat('a', 47)));
		self::assertFalse($method->invoke($service, str_repeat('A', 48)));
		self::assertFalse($method->invoke($service, str_repeat('z', 48)));
	}

	public function testNormalizeDutyTimePadsAndStripsSeconds(): void
	{
		$service = $this->service();
		$method = new ReflectionMethod(RosterService::class, 'normalizeDutyTime');
		$method->setAccessible(true);

		self::assertSame('08:00', $method->invoke($service, '8:00'));
		self::assertSame('08:00', $method->invoke($service, '08:00:00'));
		self::assertSame('23:59', $method->invoke($service, ' 23:59 '));
	}

	public function testNormalizeDutyTimeRejectsInvalid(): void
	{
		$service = $this->service();
		$method = new ReflectionMethod(RosterService::class, 'normalizeDutyTime');
		$method->setAccessible(true);

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('INVALID_TIME');
		$method->invoke($service, '24:00');
	}

	public function testPairOverlapEmitsDoubleBookingNotRest(): void
	{
		$service = $this->service();
		$method = new ReflectionMethod(RosterService::class, 'pairOverlapAndRestConflicts');
		$method->setAccessible(true);
		$dedup = [];
		$result = $method->invokeArgs($service, [
			[
				['id' => 1, 'employeeId' => 9, 'dutyDate' => '2026-08-14', 'startTime' => '08:00', 'endTime' => '16:00'],
				['id' => 2, 'employeeId' => 9, 'dutyDate' => '2026-08-14', 'startTime' => '12:00', 'endTime' => '20:00'],
			],
			['minRestMinutes' => 660],
			&$dedup,
		]);
		self::assertCount(1, $result);
		self::assertSame('double_booking', $result[0]['type']);
		self::assertSame('hard', $result[0]['severity']);
		self::assertSame([1, 2], $result[0]['assignmentIds']);
		self::assertArrayNotHasKey('rest_time_violation:1:2', $dedup);
	}

	public function testPairRestGapEmitsSoftRestViolation(): void
	{
		$service = $this->service();
		$method = new ReflectionMethod(RosterService::class, 'pairOverlapAndRestConflicts');
		$method->setAccessible(true);
		$dedup = [];
		$result = $method->invokeArgs($service, [
			[
				['id' => 10, 'employeeId' => 4, 'dutyDate' => '2026-08-14', 'startTime' => '08:00', 'endTime' => '16:00'],
				['id' => 11, 'employeeId' => 4, 'dutyDate' => '2026-08-15', 'startTime' => '00:00', 'endTime' => '08:00'],
			],
			['minRestMinutes' => 660],
			&$dedup,
		]);
		self::assertCount(1, $result);
		self::assertSame('rest_time_violation', $result[0]['type']);
		self::assertSame('soft', $result[0]['severity']);
		self::assertSame(480, $result[0]['details']['restMinutes']);
	}

	public function testPairRespectsDedupAndIgnoresForeignEmployeesWhenGrouped(): void
	{
		$service = $this->service();
		$method = new ReflectionMethod(RosterService::class, 'pairOverlapAndRestConflicts');
		$method->setAccessible(true);
		$dedup = ['double_booking:1:2' => true];
		$result = $method->invokeArgs($service, [
			[
				['id' => 1, 'employeeId' => 9, 'dutyDate' => '2026-08-14', 'startTime' => '08:00', 'endTime' => '16:00'],
				['id' => 2, 'employeeId' => 9, 'dutyDate' => '2026-08-14', 'startTime' => '12:00', 'endTime' => '20:00'],
			],
			['minRestMinutes' => 660],
			&$dedup,
		]);
		self::assertSame([], $result);
	}

	public function testAbsenceCollisionSourceFromSpansPrefersDutycheckAndIgnoresMisses(): void
	{
		self::assertNull(RosterService::absenceCollisionSourceFromSpans('2026-08-14', []));
		self::assertNull(RosterService::absenceCollisionSourceFromSpans('2026-08-14', [
			['startDate' => '2026-08-01', 'endDate' => '2026-08-10', 'source' => 'dutycheck'],
			['startDate' => '', 'endDate' => '2026-08-14', 'source' => 'dutycheck'],
		]));
		self::assertSame('dutycheck', RosterService::absenceCollisionSourceFromSpans('2026-08-14', [
			['startDate' => '2026-08-14', 'endDate' => '2026-08-16', 'source' => 'arbeitszeitcheck'],
			['startDate' => '2026-08-10', 'endDate' => '2026-08-20', 'source' => 'dutycheck'],
		]));
		self::assertSame('arbeitszeitcheck', RosterService::absenceCollisionSourceFromSpans('2026-08-14', [
			['startDate' => '2026-08-14', 'endDate' => '2026-08-14', 'source' => 'arbeitszeitcheck'],
		]));
		self::assertSame('dutycheck', RosterService::absenceCollisionSourceFromSpans('2026-08-14', [
			['startDate' => '2026-08-14', 'endDate' => '2026-08-14', 'source' => 'mystery'],
		]));
	}
}
