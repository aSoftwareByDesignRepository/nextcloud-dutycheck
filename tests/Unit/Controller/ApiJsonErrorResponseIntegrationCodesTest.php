<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\Controller;

use OCA\DutyCheck\Controller\ApiJsonErrorResponse;
use PHPUnit\Framework\TestCase;

final class ApiJsonErrorResponseIntegrationCodesTest extends TestCase
{
	public function testIntegrationDetectionFlappingIsConflict(): void
	{
		self::assertSame(409, ApiJsonErrorResponse::statusForInvalidArgument('INTEGRATION_DETECTION_FLAPPING'));
	}

	public function testIntegrationPublishStaleIsConflict(): void
	{
		self::assertSame(409, ApiJsonErrorResponse::statusForInvalidArgument('INTEGRATION_PUBLISH_STALE'));
	}

	public function testIntegrationPiiJustificationIsBadRequest(): void
	{
		self::assertSame(400, ApiJsonErrorResponse::statusForInvalidArgument('INTEGRATION_PII_JUSTIFICATION_REQUIRED'));
	}
}
