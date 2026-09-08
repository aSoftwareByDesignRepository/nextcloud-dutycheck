<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\Service;

use OCA\DutyCheck\Service\RotationAnchorService;
use PHPUnit\Framework\TestCase;

final class RotationAnchorServiceTest extends TestCase
{
	private RotationAnchorService $svc;

	protected function setUp(): void
	{
		parent::setUp();
		$this->svc = new RotationAnchorService();
	}

	public function testIsoParityN2OddMapsToWeek0ByDefault(): void
	{
		// ISO week 1 of 2026 is Thursday-start week — use a known Monday in odd KW.
		$date = new \DateTimeImmutable('2026-01-05'); // Monday, ISO week 2 (even)
		$idxEven = $this->svc->weekIndexForDate([
			'cycle_weeks' => 2,
			'anchor_type' => 'iso_week_parity',
			'anchor_iso_week_index' => 1,
		], $date);
		self::assertSame(1, $idxEven);

		$odd = new \DateTimeImmutable('2026-01-12'); // Monday ISO week 3
		$idxOdd = $this->svc->weekIndexForDate([
			'cycle_weeks' => 2,
			'anchor_type' => 'iso_week_parity',
			'anchor_iso_week_index' => 1,
		], $odd);
		self::assertSame(0, $idxOdd);
	}

	public function testFixedDateAnchorCycles(): void
	{
		$pattern = [
			'cycle_weeks' => 4,
			'anchor_type' => 'fixed_date',
			'anchor_date' => '2026-01-05', // Monday
		];
		self::assertSame(0, $this->svc->weekIndexForDate($pattern, new \DateTimeImmutable('2026-01-05')));
		self::assertSame(1, $this->svc->weekIndexForDate($pattern, new \DateTimeImmutable('2026-01-12')));
		self::assertSame(2, $this->svc->weekIndexForDate($pattern, new \DateTimeImmutable('2026-01-19')));
		self::assertSame(3, $this->svc->weekIndexForDate($pattern, new \DateTimeImmutable('2026-01-26')));
		self::assertSame(0, $this->svc->weekIndexForDate($pattern, new \DateTimeImmutable('2026-02-02')));
	}

	public function testCycleWeeksUnsupported(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('CYCLE_WEEKS_UNSUPPORTED');
		$this->svc->assertCycleWeeksAllowed(5, [1, 2, 3, 4]);
	}

	public function testWeekLabelIncludesPlanwoche(): void
	{
		$label = $this->svc->weekLabel(0, 2, 3);
		self::assertStringContainsString('Planwoche', $label);
	}
}
