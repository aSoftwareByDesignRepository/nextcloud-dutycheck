<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\Service;

use OCA\DutyCheck\Service\RosterService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Mutation-hardening tests for pure RosterService helpers (no database).
 *
 * Each test pins exact values/boundaries that escaped Infection mutants
 * (conflict identity normalisation, publish readiness counting, overlap
 * math, wall-clock parsing, token/display-name validation).
 */
final class RosterServiceMutationPureHelpersTest extends TestCase
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
		return $m->invoke($m->isStatic() ? null : $this->service(), ...$args);
	}

	public function testRosterApiConflictMessageKeysExactCatalog(): void
	{
		self::assertSame([
			'Less than 11 hours rest between consecutive assignments',
			'Employee has overlapping assignments (double booking)',
			'Shift exceeds configured daily threshold',
			'Period total hard cap exceeded for employee',
			'Period total soft cap exceeded for employee',
			'Calendar-week hard cap exceeded for employee',
			'Calendar-week soft cap exceeded for employee',
			'Employee is scheduled for too many consecutive days',
			'Employee assignment collides with approved absence',
			'Employee assignment collides with an ArbeitszeitCheck absence',
			'Period overlaps with another planning period',
			'Employee is missing a required qualification for this location',
			'Employee qualification is expired for this location',
			'Break is shorter than required for this shift length',
			'Location is understaffed relative to template headcount',
		], RosterService::rosterApiConflictMessageKeys());
	}

	public function testPublishReadinessCountsMissingAckKeyAsUnacknowledgedAndAllowsPublishAtZeroHard(): void
	{
		$result = $this->invoke('computePublishReadinessFromConflicts', 12, [
			['severity' => 'soft'],
		]);

		self::assertSame(0, $result['hardConflicts']);
		self::assertSame(1, $result['softConflicts']);
		self::assertSame(1, $result['unacknowledgedSoftConflicts']);
		self::assertTrue($result['canPublish']);
	}

	public function testPublishReadinessDoesNotCountAcknowledgedSoftConflicts(): void
	{
		$result = $this->invoke('computePublishReadinessFromConflicts', 3, [
			['severity' => 'soft', 'acknowledged' => true],
			['severity' => 'soft', 'acknowledged' => true],
			['severity' => 'soft', 'acknowledged' => false],
		]);

		self::assertSame(3, $result['softConflicts']);
		self::assertSame(1, $result['unacknowledgedSoftConflicts']);
	}

	public function testPublishReadinessCastsStringableSeverityBeforeComparing(): void
	{
		$severity = new class {
			public function __toString(): string
			{
				return 'hard';
			}
		};
		$result = $this->invoke('computePublishReadinessFromConflicts', 5, [
			['severity' => $severity],
		]);

		self::assertSame(1, $result['hardConflicts']);
		self::assertFalse($result['canPublish']);
	}

	public function testConflictAckStateTreatsWhitespaceOnlyAckHashAsNoAck(): void
	{
		$result = $this->invoke('conflictAckState', '   ', 'current-hash');

		self::assertFalse($result['acknowledged']);
		self::assertFalse($result['ackInvalidated']);
	}

	public function testConflictIdentityCastsFiltersAndSortsAssignmentIds(): void
	{
		$identity = $this->invoke('conflictIdentity', 7, 'double_booking', 33, ['2', 0, '1', -5]);

		self::assertSame('7|double_booking|33|1,2', $identity);
	}

	public function testMinutesBetweenRangesTouchingRangesHaveZeroGap(): void
	{
		self::assertSame(0, $this->invoke('minutesBetweenRanges', [100, 200], [200, 300]));
	}

	public function testAbsoluteRangesOverlapDetectsPartialOverlapFromEitherSide(): void
	{
		self::assertTrue($this->invoke('absoluteRangesOverlap', [100, 200], [50, 150]));
		self::assertTrue($this->invoke('absoluteRangesOverlap', [50, 150], [100, 200]));
	}

	public function testAbsoluteRangesOverlapTouchingBoundariesDoNotOverlap(): void
	{
		self::assertFalse($this->invoke('absoluteRangesOverlap', [100, 200], [50, 100]));
		self::assertFalse($this->invoke('absoluteRangesOverlap', [100, 200], [200, 300]));
	}

	public function testToMinuteAddsMinutesToHours(): void
	{
		self::assertSame(510, $this->invoke('toMinute', '08:30'));
		self::assertSame(45, $this->invoke('toMinute', '00:45'));
	}

	public function testNormalizeDutyTimeRejectsLeadingGarbage(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('INVALID_TIME');
		$this->invoke('normalizeDutyTime', 'a8:30');
	}

	public function testNormalizeDutyTimeRejectsTrailingGarbage(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('INVALID_TIME');
		$this->invoke('normalizeDutyTime', '08:30x');
	}

	public function testNormalizeDutyTimeAcceptsMidnightHour(): void
	{
		self::assertSame('00:30', $this->invoke('normalizeDutyTime', '00:30'));
	}

	public function testIsValidIcalTokenTrimsSurroundingWhitespace(): void
	{
		$token = str_repeat('a1b2', 12);
		self::assertTrue($this->invoke('isValidIcalToken', '  ' . $token . '  '));
	}

	public function testIsValidIcalTokenRejectsPrefixedAndSuffixedTokens(): void
	{
		$token = str_repeat('a1b2', 12);
		self::assertFalse($this->invoke('isValidIcalToken', 'X' . $token));
		self::assertFalse($this->invoke('isValidIcalToken', $token . 'f'));
	}

	public function testValidateDisplayNameTrimsAndAcceptsBoundaryLengths(): void
	{
		self::assertSame('Alice', $this->invoke('validateDisplayName', ' Alice '));

		$ascii191 = str_repeat('a', 191);
		self::assertSame($ascii191, $this->invoke('validateDisplayName', $ascii191));

		$multibyte191 = str_repeat('ü', 191);
		self::assertSame($multibyte191, $this->invoke('validateDisplayName', $multibyte191));
	}

	public function testValidateDisplayNameRejectsEmptyString(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('INVALID_DISPLAY_NAME');
		$this->invoke('validateDisplayName', '');
	}

	public function testValidateDisplayNameRejectsOverlongName(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('INVALID_DISPLAY_NAME');
		$this->invoke('validateDisplayName', str_repeat('a', 192));
	}

	public function testToActiveFlagBooleanMapping(): void
	{
		self::assertSame(1, $this->invoke('toActiveFlag', true));
		self::assertSame(0, $this->invoke('toActiveFlag', false));
	}

	public function testToActiveFlagStringMapping(): void
	{
		self::assertSame(1, $this->invoke('toActiveFlag', 'yes'));
		self::assertSame(0, $this->invoke('toActiveFlag', ' OFF '));
		self::assertSame(1, $this->invoke('toActiveFlag', '1'));
		self::assertSame(0, $this->invoke('toActiveFlag', '0'));
	}
}
