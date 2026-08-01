<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\Integration;

use OCA\DutyCheck\AppInfo\Application;
use OCA\DutyCheck\Integration\ArbeitszeitCheckIntegrationService;
use OCA\DutyCheck\Integration\IArbeitszeitCheckAbsenceReader;
use OCA\DutyCheck\Tests\Unit\Support\IntegrationQueryBuilderTrait;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\IConfig;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class ImportedAbsencesSnapshotTest extends TestCase
{
	use IntegrationQueryBuilderTrait;

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

	public function testSnapshotRowsExcludePiiAndRequireEffective(): void
	{
		$store = ['integration_at_intent_enabled' => '1'];
		$config = $this->configWithStore($store);
		$app = $this->createMock(IAppManager::class);
		$app->method('isInstalled')->willReturn(true);
		$app->method('isEnabledForUser')->willReturn(true);
		$app->method('getAppVersion')->willReturn('1.2.0');

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($this->qbFetchAllAssociative([
			[
				'at_absence_id' => 9,
				'start_date' => '2026-07-01',
				'end_date' => '2026-07-03',
				'type' => 'vacation',
				'status' => 'approved',
				'employee_id' => 3,
			],
		]));

		$svc = new ArbeitszeitCheckIntegrationService(
			$db,
			$config,
			$app,
			$this->createMock(IURLGenerator::class),
			$this->createMock(ITimeFactory::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(IArbeitszeitCheckAbsenceReader::class),
		);

		$rows = $svc->listImportedAbsencesForPeriodSnapshot('2026-07-01', '2026-07-31');
		self::assertCount(1, $rows);
		self::assertSame(9, $rows[0]['atAbsenceId']);
		self::assertSame('arbeitszeitcheck', $rows[0]['source']);
		self::assertArrayNotHasKey('reason', $rows[0]);
		self::assertArrayNotHasKey('approverComment', $rows[0]);
	}

	public function testSnapshotEmptyWhenNotEffective(): void
	{
		$store = ['integration_at_intent_enabled' => '0'];
		$config = $this->configWithStore($store);
		$db = $this->createMock(IDBConnection::class);
		$db->expects(self::never())->method('getQueryBuilder');
		$svc = new ArbeitszeitCheckIntegrationService(
			$db,
			$config,
			$this->createMock(IAppManager::class),
			$this->createMock(IURLGenerator::class),
			$this->createMock(ITimeFactory::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(IArbeitszeitCheckAbsenceReader::class),
		);
		self::assertSame([], $svc->listImportedAbsencesForPeriodSnapshot('2026-07-01', '2026-07-31'));
	}

	public function testSnapshotRejectsInvalidDateWindow(): void
	{
		$store = ['integration_at_intent_enabled' => '1'];
		$config = $this->configWithStore($store);
		$app = $this->createMock(IAppManager::class);
		$app->method('isInstalled')->willReturn(true);
		$app->method('isEnabledForUser')->willReturn(true);
		$app->method('getAppVersion')->willReturn('1.2.0');
		$db = $this->createMock(IDBConnection::class);
		$db->expects(self::never())->method('getQueryBuilder');
		$svc = new ArbeitszeitCheckIntegrationService(
			$db,
			$config,
			$app,
			$this->createMock(IURLGenerator::class),
			$this->createMock(ITimeFactory::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(IArbeitszeitCheckAbsenceReader::class),
		);
		self::assertSame([], $svc->listImportedAbsencesForPeriodSnapshot('2026-08-01', '2026-07-01'));
	}
}
