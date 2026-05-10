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
}
