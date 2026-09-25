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

	/**
	 * The 0.3.4 regression: JS `false` over urlencoded becomes the string
	 * "false", and `(bool) "false"` is true — every saved toggle flipped ON.
	 */
	public function testBoolValueParsesUrlencodedStrings(): void
	{
		foreach (['true', '1', 'yes', 'on', 'TRUE', 'Yes', ' ON '] as $value) {
			self::assertTrue(ApiMutationParams::boolValue($value), 'expected true for ' . var_export($value, true));
		}
		foreach (['false', '0', 'no', 'off', 'FALSE', 'No', ' off ', ''] as $value) {
			self::assertFalse(ApiMutationParams::boolValue($value), 'expected false for ' . var_export($value, true));
		}
	}

	public function testBoolValueAcceptsNativeScalars(): void
	{
		self::assertTrue(ApiMutationParams::boolValue(true));
		self::assertFalse(ApiMutationParams::boolValue(false));
		self::assertTrue(ApiMutationParams::boolValue(1));
		self::assertFalse(ApiMutationParams::boolValue(0));
		self::assertTrue(ApiMutationParams::boolValue(1.0));
		self::assertFalse(ApiMutationParams::boolValue(0.0));
	}

	public function testBoolValueRejectsAmbiguousValues(): void
	{
		foreach (['banana', '2', 'null', 2, -1, null, ['x'], 1.5, 0.9, -0.1, NAN, INF] as $value) {
			try {
				ApiMutationParams::boolValue($value);
				self::fail('expected InvalidArgumentException for ' . var_export($value, true));
			} catch (\InvalidArgumentException $e) {
				self::assertSame('INVALID_BOOLEAN', $e->getMessage());
			}
		}
	}

	public function testBoolValueHonoursCallerErrorCode(): void
	{
		try {
			ApiMutationParams::boolValue('maybe', 'INVALID_ACTIVE_FLAG');
			self::fail('expected InvalidArgumentException');
		} catch (\InvalidArgumentException $e) {
			self::assertSame('INVALID_ACTIVE_FLAG', $e->getMessage());
		}
	}

	public function testBoolValueOrDefaultsOnlyOnNull(): void
	{
		self::assertTrue(ApiMutationParams::boolValueOr(null, true));
		self::assertFalse(ApiMutationParams::boolValueOr(null, false));
		// Regression: the string "false" must parse, not fall back to the default.
		self::assertFalse(ApiMutationParams::boolValueOr('false', true));
		self::assertTrue(ApiMutationParams::boolValueOr('true', false));
	}
}
