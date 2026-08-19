<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;

/**
 * Companion wire contract: web first-paint / l10n work must not reshape
 * /api/mobile/* payloads the official app parses.
 */
final class MobileCompanionWireContractTest extends TestCase
{
	private function appRoot(): string
	{
		return dirname(__DIR__, 3);
	}

	private function read(string $rel): string
	{
		$src = (string) file_get_contents($this->appRoot() . '/' . $rel);
		self::assertNotSame('', $src, $rel . ' must not be empty');
		return $src;
	}

	public function testMobileRosterIsOkDataEnvelopeWithCamelCaseFields(): void
	{
		$controller = $this->read('lib/Controller/MobileController.php');
		self::assertStringContainsString("return new JSONResponse(['ok' => true, 'data' => \$data]);", $controller);
		self::assertStringContainsString('function myRoster', $controller);
		self::assertMatchesRegularExpression('/#\[NoCSRFRequired\]/', $controller);

		$roster = $this->read('lib/Service/RosterService.php');
		self::assertStringContainsString("'dutyDate' => (string) \$r['duty_date']", $roster);
		self::assertStringContainsString("'startTime' => (string) \$r['start_time']", $roster);
		self::assertStringContainsString("'endTime' => (string) \$r['end_time']", $roster);
		self::assertStringContainsString("'locationName' => (string) (\$r['location_name'] ?? '')", $roster);
		self::assertStringContainsString("'acknowledgedAt' =>", $roster);
		self::assertStringContainsString("'id' => (int) \$r['id']", $roster);
	}

	public function testAcknowledgeReturnsAssignmentIdAndTimestamp(): void
	{
		$roster = $this->read('lib/Service/RosterService.php');
		self::assertStringContainsString("'assignmentId' => \$assignmentId", $roster);
		self::assertStringContainsString("'acknowledgedAt' =>", $roster);
		self::assertStringContainsString("'acknowledgedBy' =>", $roster);
	}

	public function testBootstrapStaysUngatedAndUnwrapped(): void
	{
		$controller = $this->read('lib/Controller/MobileController.php');
		self::assertStringContainsString('return new JSONResponse($payload);', $controller);
		self::assertStringContainsString('function bootstrap', $controller);
		$gate = $this->read('lib/Service/MobileGateService.php');
		self::assertStringContainsString("'seatAssigned'", $gate);
		self::assertStringContainsString("'dutycheck.acknowledge'", $gate);
		self::assertStringContainsString("'dutycheck.swap'", $gate);
		self::assertStringContainsString("'dutycheck.openShifts'", $gate);
	}
}
