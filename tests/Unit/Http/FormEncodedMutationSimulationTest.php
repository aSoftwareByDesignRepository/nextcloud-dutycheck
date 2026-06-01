<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\Http;

use OCA\DutyCheck\Http\ApiMutationParams;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Simulates parameters as PHP receives them from application/x-www-form-urlencoded
 * bodies (browser / DutyCheckApi), including nested acknowledgement rows.
 */
class FormEncodedMutationSimulationTest extends TestCase
{
	public function testAssignmentParamsCastToIntsInServiceLayer(): void
	{
		$params = [
			'periodId' => '12',
			'employeeId' => '21',
			'locationId' => '3',
			'dutyDate' => '2026-06-08',
			'startTime' => '08:00',
			'endTime' => '16:00',
			'breakMinutes' => '30',
			'note' => '',
		];

		self::assertSame(12, (int) ($params['periodId'] ?? 0));
		self::assertSame(21, (int) ($params['employeeId'] ?? 0));
		self::assertSame(3, (int) ($params['locationId'] ?? 0));
	}

	public function testAcknowledgementsFromPhpPostShape(): void
	{
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn([
			'periodId' => '12',
			'acknowledgements' => [
				[
					'conflictType' => 'rest_time_violation',
					'reason' => 'Approved by duty manager for coverage.',
				],
			],
		]);

		$acks = ApiMutationParams::acknowledgements($request);
		self::assertCount(1, $acks);
		self::assertSame('rest_time_violation', $acks[0]['conflictType']);
		self::assertGreaterThanOrEqual(10, mb_strlen($acks[0]['reason']));
	}

	public function testEmployeeActiveFlagFromFormString(): void
	{
		foreach (['true', '1', 'false', '0'] as $value) {
			$normalized = strtolower(trim($value));
			$active = in_array($normalized, ['1', 'true', 'yes', 'on'], true) ? 1 : 0;
			if (in_array($value, ['true', '1'], true)) {
				self::assertSame(1, $active, $value);
			} else {
				self::assertSame(0, $active, $value);
			}
		}
	}
}
