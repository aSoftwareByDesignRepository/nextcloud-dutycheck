<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\Service;

use OCA\DutyCheck\Service\CompanyService;
use OCA\DutyCheck\Service\RotationAnchorService;
use OCA\DutyCheck\Service\RotationPatternService;
use OCA\DutyCheck\Service\SelfServiceSettingsService;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * Covers the 0.3.4 regression: patterns saved via the web UI posted
 * application/x-www-form-urlencoded bodies where unchecked days arrived as the
 * string "false" — and `(bool) "false"` flipped every day to working.
 *
 * normalizeWeekDaysPayload() is private; reflection keeps the test at the
 * service boundary without standing up a DB.
 */
final class RotationPatternWeekDaysNormalizeTest extends TestCase
{
	private function service(): RotationPatternService
	{
		return new RotationPatternService(
			$this->createMock(IDBConnection::class),
			$this->createMock(CompanyService::class),
			$this->createMock(SelfServiceSettingsService::class),
			$this->createMock(RotationAnchorService::class),
		);
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function invokeNormalize(RotationPatternService $svc, mixed $raw, int $cycleWeeks): array
	{
		$m = (new \ReflectionClass($svc))->getMethod('normalizeWeekDaysPayload');
		return $m->invoke($svc, $raw, $cycleWeeks);
	}

	public function testUrlencodedFalseStaysNotWorking(): void
	{
		$out = $this->invokeNormalize($this->service(), [
			['weekIndex' => '0', 'dow' => '1', 'isWorking' => 'true', 'startLocal' => '08:00', 'endLocal' => '16:00'],
			['weekIndex' => '0', 'dow' => '2', 'isWorking' => 'false'],
			['weekIndex' => '0', 'dow' => '3', 'isWorking' => 'false'],
			['weekIndex' => '0', 'dow' => '4', 'isWorking' => 'false'],
			['weekIndex' => '0', 'dow' => '5', 'isWorking' => 'false'],
			['weekIndex' => '0', 'dow' => '6', 'isWorking' => 'false'],
			['weekIndex' => '0', 'dow' => '7', 'isWorking' => 'false'],
		], 1);

		$working = array_values(array_filter($out, static fn (array $d): bool => $d['isWorking']));
		self::assertCount(1, $working, 'only Monday must be working — "false" strings must not coerce to true');
		self::assertSame(1, $working[0]['dow']);
		foreach ($out as $day) {
			if ($day['dow'] !== 1) {
				self::assertFalse($day['isWorking'], 'dow ' . $day['dow'] . ' must stay non-working');
				self::assertSame(0, $day['netMinutes']);
			}
		}
	}

	public function testAllBoolSpellingsNormalize(): void
	{
		$cases = [
			'true' => true, '1' => true, 'on' => true, 'yes' => true, 'TRUE' => true,
			'false' => false, '0' => false, 'off' => false, 'no' => false, '' => false,
		];
		$svc = $this->service();
		foreach ($cases as $raw => $expected) {
			$out = $this->invokeNormalize($svc, [
				['weekIndex' => 0, 'dow' => 1, 'isWorking' => $raw],
			], 1);
			self::assertSame($expected, $out[0]['isWorking'], var_export($raw, true));
		}
	}

	public function testNativeBoolAndIntPayloadsStillWork(): void
	{
		$out = $this->invokeNormalize($this->service(), [
			['weekIndex' => 0, 'dow' => 1, 'isWorking' => true, 'startLocal' => '09:00', 'endLocal' => '17:00'],
			['weekIndex' => 0, 'dow' => 2, 'isWorking' => false],
			['weekIndex' => 0, 'dow' => 3, 'is_working' => 1, 'start_local' => '10:00', 'end_local' => '18:00'],
			['weekIndex' => 0, 'dow' => 4, 'is_working' => 0],
		], 1);
		$byDow = [];
		foreach ($out as $day) {
			$byDow[$day['dow']] = $day['isWorking'];
		}
		self::assertTrue($byDow[1]);
		self::assertFalse($byDow[2]);
		self::assertTrue($byDow[3]);
		self::assertFalse($byDow[4]);
	}

	public function testMissingIsWorkingDefaultsToNotWorking(): void
	{
		$out = $this->invokeNormalize($this->service(), [
			['weekIndex' => 0, 'dow' => 1],
		], 1);
		self::assertFalse($out[0]['isWorking']);
	}

	public function testSparseInputFillsRemainingDaysAsOff(): void
	{
		$out = $this->invokeNormalize($this->service(), [
			['weekIndex' => 0, 'dow' => 3, 'isWorking' => 'true', 'startLocal' => '08:00', 'endLocal' => '12:00'],
		], 2);
		self::assertCount(14, $out);
		$working = array_filter($out, static fn (array $d): bool => $d['isWorking']);
		self::assertCount(1, $working);
	}

	public function testAmbiguousIsWorkingRejected(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('INVALID_BOOLEAN');
		$this->invokeNormalize($this->service(), [
			['weekIndex' => 0, 'dow' => 1, 'isWorking' => 'sometimes'],
		], 1);
	}
}
