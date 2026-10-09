<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\Service;

use OCA\DutyCheck\Db\SchemaProbe;
use OCA\DutyCheck\Service\RosterService;
use PHPUnit\Framework\TestCase;

/**
 * Pins the write-path verdict shared with the suggest-fill preview:
 * overlap (incl. overnight tails) is hard, rest/break/daily-limit conflicts
 * and soft qualifications are ack-required, hard qualifications block.
 */
class RosterServiceCellWriteVerdictTest extends TestCase
{
	use RosterServiceMutationMockTrait;

	protected function setUp(): void
	{
		parent::setUp();
		// Static schema caches leak between tests; force a fresh probe so the
		// frozen-thresholds lookup falls through to ConflictPolicyService::defaults().
		SchemaProbe::resetCache();
	}

	private function service(): RosterService
	{
		// fetchOne=false: no frozen thresholds row -> defaults
		// (maxDailyHard=600, minRestMinutes=660).
		return new RosterService($this->rosterDbAlways($this->rosterQb()));
	}

	public function testCleanCellIsWritable(): void
	{
		$verdict = $this->service()->cellWriteVerdict(
			1, '2026-12-07', '08:00', '16:00', 30,
			[['dutyDate' => '2026-12-06', 'startTime' => '08:00', 'endTime' => '16:00']],
		);

		self::assertFalse($verdict['overlap']);
		self::assertSame([], $verdict['softTypes']);
		self::assertFalse($verdict['hardQualification']);
	}

	public function testRestViolationAgainstExistingRow(): void
	{
		// 21:00 end -> next-day 05:00 start is an 8h gap (< 11h minimum).
		$verdict = $this->service()->cellWriteVerdict(
			1, '2026-12-07', '05:00', '13:00', 30,
			[['dutyDate' => '2026-12-06', 'startTime' => '13:00', 'endTime' => '21:00']],
		);

		self::assertFalse($verdict['overlap']);
		self::assertSame(['rest_time_violation'], $verdict['softTypes']);
	}

	public function testOvernightTailOverlapIsHard(): void
	{
		// Existing night shift spills into the candidate's morning — the
		// per-day occupied map cannot see this; the absolute range can.
		$verdict = $this->service()->cellWriteVerdict(
			1, '2026-12-07', '05:00', '09:00', 0,
			[['dutyDate' => '2026-12-06', 'startTime' => '21:00', 'endTime' => '06:00']],
		);

		self::assertTrue($verdict['overlap']);
	}

	public function testBreakTooShortOnLongShift(): void
	{
		// 21:00-06:00 = 540min effective (>360) with 0min break (<30).
		$verdict = $this->service()->cellWriteVerdict(1, '2026-12-07', '21:00', '06:00', 0, []);

		self::assertContains('break_too_short', $verdict['softTypes']);
	}

	public function testShiftTooLongOverDailyLimit(): void
	{
		// 18:00-08:00 minus 60min break = 780min > 600min default cap.
		$verdict = $this->service()->cellWriteVerdict(1, '2026-12-07', '18:00', '08:00', 60, []);

		self::assertContains('shift_too_long', $verdict['softTypes']);
	}

	public function testQualificationSeverityClassification(): void
	{
		$hard = $this->service()->cellWriteVerdict(1, '2026-12-07', '08:00', '16:00', 30, [], [
			['type' => 'qualification_missing', 'severity' => 'hard'],
		]);
		self::assertTrue($hard['hardQualification']);
		self::assertSame([], $hard['softTypes']);

		$soft = $this->service()->cellWriteVerdict(1, '2026-12-07', '08:00', '16:00', 30, [], [
			['type' => 'qualification_expired', 'severity' => 'soft'],
		]);
		self::assertFalse($soft['hardQualification']);
		self::assertSame(['qualification_expired'], $soft['softTypes']);
	}
}
