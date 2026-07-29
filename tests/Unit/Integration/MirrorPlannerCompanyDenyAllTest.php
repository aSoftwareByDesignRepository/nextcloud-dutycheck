<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\Integration;

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

final class MirrorPlannerCompanyDenyAllTest extends TestCase
{
	use IntegrationQueryBuilderTrait;

	public function testEmptyAllowedCompanyIdsReturnsNoRowsWithoutQuerying(): void
	{
		$store = [
			'integration_at_intent_enabled' => '1',
		];
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(static function (string $app, string $key, string $default = '') use (&$store): string {
			return $store[$key] ?? $default;
		});

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

		self::assertSame([], $svc->listMirrorRowsForPlanner([]));
	}
}
