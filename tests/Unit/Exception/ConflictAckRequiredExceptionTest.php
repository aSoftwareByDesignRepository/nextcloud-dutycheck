<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\Exception;

use OCA\DutyCheck\Exception\ConflictAckRequiredException;
use PHPUnit\Framework\TestCase;

class ConflictAckRequiredExceptionTest extends TestCase
{
	public function testCarriesConflictsPayloadAndStableCode(): void
	{
		$conflicts = [
			[
				'type' => 'rest_time_violation',
				'severity' => 'soft',
				'assignmentIds' => [11, 12],
			],
		];

		$exception = new ConflictAckRequiredException($conflicts);

		self::assertSame('CONFLICT_ACK_REQUIRED', $exception->getMessage());
		self::assertSame($conflicts, $exception->getConflicts());
	}
}
