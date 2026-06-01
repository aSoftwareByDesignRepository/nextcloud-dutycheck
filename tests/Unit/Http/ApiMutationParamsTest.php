<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\Http;

use OCA\DutyCheck\Http\ApiMutationParams;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

class ApiMutationParamsTest extends TestCase
{
	public function testGetReadsMergedRequestParams(): void
	{
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn([
			'periodId' => 12,
			'employeeId' => 3,
			'acknowledgements' => [['conflictType' => 'rest_time_violation', 'reason' => '1234567890']],
		]);

		self::assertSame(12, ApiMutationParams::get($request, 'periodId'));
		self::assertSame(3, ApiMutationParams::get($request, 'employeeId'));
		self::assertIsArray(ApiMutationParams::get($request, 'acknowledgements'));
		self::assertNull(ApiMutationParams::get($request, 'missing', null));
	}

	public function testAcknowledgementsNormalisesNestedRows(): void
	{
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn([
			'acknowledgements' => [
				['conflictType' => 'rest_time_violation', 'reason' => '1234567890'],
				['conflictType' => '', 'reason' => 'ignored'],
				'not-an-array',
			],
		]);

		self::assertSame(
			[['conflictType' => 'rest_time_violation', 'reason' => '1234567890']],
			ApiMutationParams::acknowledgements($request),
		);
	}
}
