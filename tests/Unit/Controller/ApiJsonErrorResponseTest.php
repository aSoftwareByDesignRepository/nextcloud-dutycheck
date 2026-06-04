<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\Controller;

use OCA\DutyCheck\Controller\ApiJsonErrorResponse;
use OCA\DutyCheck\Exception\AppAccessDeniedException;
use OCA\DutyCheck\Exception\IntegrationLegacyConflictException;
use OCA\DutyCheck\Service\AccessControlService;
use OCP\AppFramework\Http\DataResponse;
use PHPUnit\Framework\TestCase;

class ApiJsonErrorResponseTest extends TestCase
{
	public function testMapsEmployeeNotLinkedToStableApiCode(): void
	{
		$response = ApiJsonErrorResponse::fromThrowable(
			new AppAccessDeniedException(AccessControlService::DENIAL_EMPLOYEE_NOT_LINKED),
		);
		self::assertInstanceOf(DataResponse::class, $response);
		self::assertSame(403, $response->getStatus());
		$data = $response->getData();
		self::assertFalse($data['ok']);
		self::assertSame('EMPLOYEE_RECORD_LINK_REQUIRED', $data['error']['code']);
	}

	public function testMapsInsufficientRoleToInsufficentRoleCode(): void
	{
		$response = ApiJsonErrorResponse::fromThrowable(
			new AppAccessDeniedException(AccessControlService::DENIAL_INSUFFICIENT_ROLE),
		);
		$data = $response->getData();
		self::assertSame('INSUFFICIENT_ROLE', $data['error']['code']);
	}

	public function testMapsEqualDutyTimesTo422(): void
	{
		self::assertSame(422, ApiJsonErrorResponse::statusForInvalidArgument('EQUAL_DUTY_TIMES'));
	}

	public function testMapsIntegrationAbsenceReadonlyTo403(): void
	{
		$response = ApiJsonErrorResponse::fromThrowable(
			new \InvalidArgumentException('INTEGRATION_ABSENCE_READONLY'),
		);
		self::assertSame(403, $response->getStatus());
		self::assertSame('INTEGRATION_ABSENCE_READONLY', $response->getData()['error']['code']);
	}

	public function testMapsIntegrationLegacyConflictTo409(): void
	{
		$response = ApiJsonErrorResponse::fromThrowable(
			new \InvalidArgumentException('INTEGRATION_LEGACY_CONFLICT'),
		);
		self::assertSame(409, $response->getStatus());
		self::assertSame('INTEGRATION_LEGACY_CONFLICT', $response->getData()['error']['code']);
	}

	public function testMapsIntegrationLegacyConflictExceptionTo409WithCount(): void
	{
		$response = ApiJsonErrorResponse::fromThrowable(new IntegrationLegacyConflictException(12));
		self::assertSame(409, $response->getStatus());
		$data = $response->getData();
		self::assertSame('INTEGRATION_LEGACY_CONFLICT', $data['error']['code']);
		self::assertSame(12, $data['error']['legacyAbsenceCount']);
	}

	public function testMapsIntegrationPurgeBlockedTo409(): void
	{
		$response = ApiJsonErrorResponse::fromThrowable(new \InvalidArgumentException('INTEGRATION_PURGE_BLOCKED'));
		self::assertSame(409, $response->getStatus());
		self::assertSame('INTEGRATION_PURGE_BLOCKED', $response->getData()['error']['code']);
	}

	public function testMapsIntegrationPeerNotInstalledTo400(): void
	{
		$response = ApiJsonErrorResponse::fromThrowable(
			new \InvalidArgumentException('INTEGRATION_PEER_NOT_INSTALLED'),
		);
		self::assertSame(400, $response->getStatus());
		self::assertSame('INTEGRATION_PEER_NOT_INSTALLED', $response->getData()['error']['code']);
	}

	public function testMapsSchemaNotReadyTo503(): void
	{
		$response = ApiJsonErrorResponse::fromThrowable(
			new \InvalidArgumentException('SCHEMA_NOT_READY'),
		);
		self::assertSame(503, $response->getStatus());
		self::assertSame('SCHEMA_NOT_READY', $response->getData()['error']['code']);
	}

	public function testMapsEmployeeNotFoundTo404(): void
	{
		$response = ApiJsonErrorResponse::fromThrowable(
			new \InvalidArgumentException('EMPLOYEE_NOT_FOUND'),
		);
		self::assertSame(404, $response->getStatus());
		self::assertSame('EMPLOYEE_NOT_FOUND', $response->getData()['error']['code']);
	}

	public function testMapsAssignmentRequiredFieldsTo400(): void
	{
		foreach (['PERIOD_ID_REQUIRED', 'EMPLOYEE_ID_REQUIRED', 'LOCATION_ID_REQUIRED'] as $code) {
			$response = ApiJsonErrorResponse::fromThrowable(new \InvalidArgumentException($code));
			self::assertSame(400, $response->getStatus(), $code);
			self::assertSame($code, $response->getData()['error']['code'], $code);
		}
	}
}
