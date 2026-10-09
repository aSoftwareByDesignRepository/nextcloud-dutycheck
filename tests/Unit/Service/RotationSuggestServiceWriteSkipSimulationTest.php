<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\Service;

use OCA\DutyCheck\Service\CompanyService;
use OCA\DutyCheck\Service\PeriodLockService;
use OCA\DutyCheck\Service\RotationAnchorService;
use OCA\DutyCheck\Service\RotationPatternService;
use OCA\DutyCheck\Service\RotationSuggestService;
use OCA\DutyCheck\Service\RosterService;
use OCA\DutyCheck\Service\SelfServiceSettingsService;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Pins the preview write-skip simulation: candidates needing acknowledgement
 * or hitting hard blocks are counted and removed BEFORE preview reports
 * "created" — so the dialog can never promise shifts confirm then drops
 * (2026-10-09 customer report: "20 shifts" preview -> empty roster).
 */
class RotationSuggestServiceWriteSkipSimulationTest extends TestCase
{
	private function suggestService(RosterService $roster): RotationSuggestService
	{
		$bare = static fn (string $class): object =>
			(new ReflectionClass($class))->newInstanceWithoutConstructor();

		return new RotationSuggestService(
			$this->createMock(IDBConnection::class),
			$roster,
			$bare(CompanyService::class),
			$bare(SelfServiceSettingsService::class),
			$bare(RotationAnchorService::class),
			$bare(RotationPatternService::class),
			$bare(PeriodLockService::class),
		);
	}

	private function simulate(RotationSuggestService $service, array &$plan, array $assignments): void
	{
		$method = new ReflectionMethod(RotationSuggestService::class, 'simulateWriteSkips');
		$method->invokeArgs($service, [&$plan, $assignments]);
	}

	private function cell(int $employeeId, string $date, string $start, string $end): array
	{
		return [
			'periodId' => 5,
			'employeeId' => $employeeId,
			'locationId' => 9,
			'dutyDate' => $date,
			'startTime' => $start,
			'endTime' => $end,
			'breakMinutes' => 30,
			'patternId' => 1,
			'weekIndex' => 0,
		];
	}

	private function plan(array $candidates): array
	{
		return [
			'periodId' => 5,
			'created' => 0,
			'skipped_existing' => 0,
			'skipped_absence' => 0,
			'skipped_blackout' => 0,
			'skipped_no_pattern' => 0,
			'skipped_no_location' => 0,
			'skipped_location_mismatch' => 0,
			'skipped_write' => 0,
			'write_skip_reasons' => [],
			'samples' => [],
			'candidates' => $candidates,
		];
	}

	public function testAcceptedCellsFeedLaterVerdicts(): void
	{
		$roster = $this->createMock(RosterService::class);
		$roster->method('qualificationConflictsForCells')->willReturn([]);
		// Second call sees the first accepted cell in existingRows.
		$roster->method('cellWriteVerdict')->willReturnCallback(
			static fn (int $p, string $d, string $s, string $e, int $b, array $rows): array => count($rows) > 0
				? ['overlap' => false, 'softTypes' => ['rest_time_violation'], 'hardQualification' => false]
				: ['overlap' => false, 'softTypes' => [], 'hardQualification' => false],
		);

		$plan = $this->plan([
			$this->cell(7, '2026-12-07', '13:00', '21:00'),
			$this->cell(7, '2026-12-08', '05:00', '13:00'),
		]);
		$this->simulate($this->suggestService($roster), $plan, []);

		self::assertCount(1, $plan['candidates']);
		self::assertSame('2026-12-07', $plan['candidates'][0]['dutyDate']);
		self::assertSame(1, $plan['skipped_write']);
		self::assertSame(['rest_time_violation' => 1], $plan['write_skip_reasons']);
	}

	public function testOverlapCountsAsExisting(): void
	{
		$roster = $this->createMock(RosterService::class);
		$roster->method('qualificationConflictsForCells')->willReturn([]);
		$roster->method('cellWriteVerdict')->willReturn([
			'overlap' => true,
			'softTypes' => [],
			'hardQualification' => false,
		]);

		$plan = $this->plan([$this->cell(7, '2026-12-07', '05:00', '09:00')]);
		$this->simulate($this->suggestService($roster), $plan, [
			['employeeId' => 7, 'dutyDate' => '2026-12-06', 'startTime' => '21:00', 'endTime' => '06:00'],
		]);

		self::assertSame([], $plan['candidates']);
		self::assertSame(1, $plan['skipped_existing']);
		self::assertSame(0, $plan['skipped_write']);
	}

	public function testHardQualificationCountsAsWriteSkip(): void
	{
		$roster = $this->createMock(RosterService::class);
		$roster->method('qualificationConflictsForCells')->willReturn([
			0 => [['type' => 'qualification_missing', 'severity' => 'hard']],
		]);
		$roster->method('cellWriteVerdict')->willReturn([
			'overlap' => false,
			'softTypes' => [],
			'hardQualification' => true,
		]);

		$plan = $this->plan([$this->cell(7, '2026-12-07', '08:00', '16:00')]);
		$this->simulate($this->suggestService($roster), $plan, []);

		self::assertSame([], $plan['candidates']);
		self::assertSame(1, $plan['skipped_write']);
		self::assertSame(['qualification_missing' => 1], $plan['write_skip_reasons']);
	}
}
