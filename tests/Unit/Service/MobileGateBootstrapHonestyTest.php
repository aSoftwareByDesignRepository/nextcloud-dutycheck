<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\Service;

use OCA\DutyCheck\License\Dty2Codec;
use OCA\DutyCheck\Service\LicenseService;
use OCA\DutyCheck\Service\MobileGateService;
use PHPUnit\Framework\TestCase;

final class MobileGateBootstrapHonestyTest extends TestCase
{
	public function testBootstrapAdvertisesShippedMarketplaceCapabilities(): void
	{
		$license = $this->createMock(LicenseService::class);
		$license->method('gateState')->willReturn([
			'hasLicense' => false,
			'licenseValid' => false,
			'seatAssigned' => false,
			'seatWithinLimit' => false,
			'payloadB64' => null,
			'signatureB64' => null,
		]);
		$license->method('status')->willReturn([
			'state' => null,
			'seats' => ['assigned' => 0, 'limit' => 0],
		]);
		$svc = new MobileGateService($license);
		$payload = $svc->bootstrapPayload('u1', 'User One', '0.1.19');
		self::assertSame(Dty2Codec::PRODUCT, $payload['licensing']['product']);
		self::assertTrue($payload['capabilities']['dutycheck.swap']);
		self::assertTrue($payload['capabilities']['dutycheck.openShifts']);
		self::assertTrue($payload['capabilities']['dutycheck.acknowledge']);
		self::assertFalse($payload['capabilities']['integration.arbeitszeitcheck.effective']);
		self::assertNull($payload['urls']['azcAbsences']);
		self::assertArrayHasKey('myRosterWeb', $payload['urls']);
		self::assertArrayHasKey('enabledForUser', $payload['licensing']);
		self::assertArrayHasKey('mobileSeats', $payload['licensing']);
		self::assertArrayHasKey('mobileSeatsUsed', $payload['licensing']);
	}

	public function testBootstrapReportsAzcEffectiveWhenIntegrationOn(): void
	{
		$license = $this->createMock(LicenseService::class);
		$license->method('gateState')->willReturn([
			'hasLicense' => false,
			'licenseValid' => false,
			'seatAssigned' => false,
			'seatWithinLimit' => false,
			'payloadB64' => null,
			'signatureB64' => null,
		]);
		$license->method('status')->willReturn([
			'state' => null,
			'seats' => ['assigned' => 0, 'limit' => 0],
		]);
		$azc = $this->createMock(\OCA\DutyCheck\Integration\IArbeitszeitCheckIntegration::class);
		$azc->method('isEffective')->willReturn(true);
		$url = $this->createMock(\OCP\IURLGenerator::class);
		$url->method('linkToRouteAbsolute')->willReturnCallback(static function (string $route): string {
			return match ($route) {
				'dutycheck.page.myRoster' => 'https://nc.test/apps/dutycheck/my-roster',
				default => 'https://nc.test/apps/arbeitszeitcheck/absences',
			};
		});
		$svc = new MobileGateService($license, $azc, $url);
		$payload = $svc->bootstrapPayload('u1', 'User One', '0.1.19');
		self::assertTrue($payload['capabilities']['integration.arbeitszeitcheck.effective']);
		self::assertSame('https://nc.test/apps/arbeitszeitcheck/absences', $payload['urls']['azcAbsences']);
		self::assertSame('https://nc.test/apps/dutycheck/my-roster', $payload['urls']['myRosterWeb']);
	}
}
