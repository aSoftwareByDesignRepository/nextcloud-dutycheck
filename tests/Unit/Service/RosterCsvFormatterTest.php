<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\Service;

use OCA\DutyCheck\Service\RosterCsvFormatter;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

class RosterCsvFormatterTest extends TestCase
{
	public function testEscapesQuotesCommasAndNewlines(): void
	{
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s): string => $s);

		$formatter = new RosterCsvFormatter($l10n);
		$period = [
			'id' => 1,
			'startDate' => '2026-05-01',
			'endDate' => '2026-05-31',
			'status' => 'open',
		];
		$assignments = [[
			'id' => 9,
			'dutyDate' => '2026-05-10',
			'startTime' => '08:00',
			'endTime' => '16:00',
			'breakMinutes' => 30,
			'employeeId' => 2,
			'employeeName' => 'O\'Neil, Dana "D."',
			'locationId' => 3,
			'locationName' => 'North, East',
			'note' => "Line1\nLine2",
		]];

		$csv = $formatter->buildDutyRosterCsv($period, $assignments, 'admin-1', '2026-05-10T12:00:00Z');
		self::assertStringStartsWith("\xEF\xBB\xBF", $csv);
		self::assertStringContainsString('"O\'Neil, Dana ""D.""",3,"North, East"', $csv);
		self::assertStringContainsString('"Line1' . "\n" . 'Line2"', $csv);
	}

	public function testHeaderOnlyWhenNoAssignments(): void
	{
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s): string => $s);

		$formatter = new RosterCsvFormatter($l10n);
		$period = [
			'id' => 4,
			'startDate' => '2026-06-01',
			'endDate' => '2026-06-30',
			'status' => 'published',
		];
		$csv = $formatter->buildDutyRosterCsv($period, [], 'u', '2026-06-01T00:00:00Z');
		$withoutBom = preg_replace('/^\xEF\xBB\xBF/', '', $csv) ?? $csv;
		$lines = preg_split("/\r\n|\n|\r/", trim($withoutBom)) ?: [];
		self::assertCount(1, $lines);
		self::assertStringStartsWith('Period ID,', $lines[0]);
	}
}
