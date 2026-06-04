<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\Service;

use OCA\DutyCheck\Service\RosterService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Wall-clock duty time rules used by createAssignment (no database).
 */
class RosterServiceDutyTimeTest extends TestCase
{
	private function service(): RosterService
	{
		$reflection = new ReflectionClass(RosterService::class);
		/** @var RosterService $service */
		$service = $reflection->newInstanceWithoutConstructor();
		return $service;
	}

	private function invoke(string $method, mixed ...$args): mixed
	{
		$m = new ReflectionMethod(RosterService::class, $method);
		$m->setAccessible(true);
		return $m->invoke($this->service(), ...$args);
	}

	public function testNormalizeDutyTimeAcceptsHourMinute(): void
	{
		self::assertSame('08:30', $this->invoke('normalizeDutyTime', '8:30'));
		self::assertSame('22:00', $this->invoke('normalizeDutyTime', '22:00:00'));
	}

	public function testEffectiveMinutesTreatsOvernightShift(): void
	{
		// 22:00–06:00 with 30 min break => 7h30 = 450 minutes
		self::assertSame(450, $this->invoke('effectiveMinutes', '22:00', '06:00', 30));
	}

	public function testEffectiveMinutesSameClockTimeCountsAsTwentyFourHours(): void
	{
		// Equal wall times are treated like overnight (24h). createAssignment rejects them via EQUAL_DUTY_TIMES.
		self::assertSame(1440, $this->invoke('effectiveMinutes', '09:00', '09:00', 0));
	}

	public function testEffectiveMinutesBreakCanConsumeShift(): void
	{
		self::assertSame(0, $this->invoke('effectiveMinutes', '08:00', '09:00', 60));
	}

	public function testDateWithinInclusiveRange(): void
	{
		self::assertTrue(RosterService::dateWithinInclusiveRange('2026-07-15', '2026-07-01', '2026-07-31'));
		self::assertFalse(RosterService::dateWithinInclusiveRange('2026-08-01', '2026-07-01', '2026-07-31'));
	}
}
