<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\Integration;

use OCA\DutyCheck\AppInfo\Application;
use OCA\DutyCheck\Integration\ArbeitszeitCheckIntegrationService;
use OCA\DutyCheck\Integration\IArbeitszeitCheckAbsenceReader;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\IConfig;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * REQ-TST-10 — bootstrap parity across detection scenarios.
 */
final class IntegrationBootstrapParityTest extends TestCase
{
	/**
	 * @param array<string, string> $store
	 */
	private function configWithStore(array &$store): IConfig
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(function (string $appId, string $key, string $default = '') use (&$store): string {
			if ($appId !== Application::APP_ID) {
				return $default;
			}
			return $store[$key] ?? $default;
		});
		$config->method('setAppValue')->willReturnCallback(function (string $appId, string $key, string $value) use (&$store): void {
			if ($appId === Application::APP_ID) {
				$store[$key] = $value;
			}
		});
		return $config;
	}

	private function service(IConfig $config, IAppManager $app): ArbeitszeitCheckIntegrationService
	{
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1_700_000_000);
		$url = $this->createMock(IURLGenerator::class);
		$url->method('linkToRouteAbsolute')->willReturnCallback(static fn (string $route): string => 'https://nc.test/' . $route);
		return new ArbeitszeitCheckIntegrationService(
			$this->createMock(IDBConnection::class),
			$config,
			$app,
			$url,
			$time,
			$this->createMock(LoggerInterface::class),
			$this->createMock(IArbeitszeitCheckAbsenceReader::class),
		);
	}

	public function testPeerNotInstalled(): void
	{
		$store = [];
		$app = $this->createMock(IAppManager::class);
		$app->method('isInstalled')->willReturn(false);
		$b = $this->service($this->configWithStore($store), $app)->buildBootstrapForUser('u', false);
		self::assertFalse($b['effective']);
		self::assertFalse($b['peerInstalled']);
		self::assertNull($b['peerPlannerOutboundUrl']);
	}

	public function testIntentOffPeerOk(): void
	{
		$store = ['integration_at_intent_enabled' => '0'];
		$app = $this->createMock(IAppManager::class);
		$app->method('isInstalled')->willReturn(true);
		$app->method('isEnabledForUser')->willReturn(true);
		$app->method('getAppVersion')->willReturn('1.6.1');
		$b = $this->service($this->configWithStore($store), $app)->buildBootstrapForUser('u', true);
		self::assertFalse($b['effective']);
		self::assertTrue($b['peerVersionOk']);
		self::assertFalse($b['readonlyAbsencesForCurrentUser']);
		self::assertNotNull($b['peerEmployeeOutboundUrl']);
	}

	public function testVersionTooLow(): void
	{
		$store = ['integration_at_intent_enabled' => '1'];
		$app = $this->createMock(IAppManager::class);
		$app->method('isInstalled')->willReturn(true);
		$app->method('isEnabledForUser')->willReturn(true);
		$app->method('getAppVersion')->willReturn('1.0.0');
		$b = $this->service($this->configWithStore($store), $app)->buildBootstrapForUser('u', true);
		self::assertFalse($b['peerVersionOk']);
		self::assertFalse($b['effective']);
		self::assertSame('1.2.0', $b['peerVersionRange']['min']);
	}

	public function testEffectiveHappyPath(): void
	{
		$store = [
			'integration_at_intent_enabled' => '1',
			'integration_at_last_reconcile_at' => '2026-07-27T08:00:00Z',
		];
		$app = $this->createMock(IAppManager::class);
		$app->method('isInstalled')->willReturn(true);
		$app->method('isEnabledForUser')->willReturn(true);
		$app->method('getAppVersion')->willReturn('1.6.1');
		$b = $this->service($this->configWithStore($store), $app)->buildBootstrapForUser('u', true);
		self::assertTrue($b['effective']);
		self::assertTrue($b['readonlyAbsencesForCurrentUser']);
		self::assertStringContainsString('arbeitszeitcheck.page.index', (string) $b['peerPlannerOutboundUrl']);
		self::assertStringContainsString('arbeitszeitcheck.page.absences', (string) $b['peerEmployeeOutboundUrl']);
	}
}
