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
use ReflectionMethod;

final class UnknownEnumAuditTest extends TestCase
{
	use IntegrationQueryBuilderTrait;

	public function testNoteUnknownEnumLogsOnceAndWritesAudit(): void
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturn('');

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('warning')->with(
			self::stringContains('unknown enum'),
			self::callback(static function (array $ctx): bool {
				return ($ctx['code'] ?? '') === 'INTEGRATION_AT_UNKNOWN_ENUM'
					&& ($ctx['field'] ?? '') === 'type'
					&& ($ctx['value'] ?? '') === 'totally_new_type';
			}),
		);

		$db = $this->createMock(IDBConnection::class);
		$db->expects(self::once())->method('getQueryBuilder')->willReturn(
			$this->qbExecuteStatement(self::once()),
		);

		$svc = new ArbeitszeitCheckIntegrationService(
			$db,
			$config,
			$this->createMock(IAppManager::class),
			$this->createMock(IURLGenerator::class),
			$this->createMock(ITimeFactory::class),
			$logger,
			$this->createMock(IArbeitszeitCheckAbsenceReader::class),
		);

		$m = new ReflectionMethod(ArbeitszeitCheckIntegrationService::class, 'noteUnknownEnum');
		$m->setAccessible(true);
		$m->invoke($svc, 'type', 'totally_new_type', 42);
		$m->invoke($svc, 'type', 'totally_new_type', 99); // debounced
	}

	public function testUpsertMirrorRowNotesUnknownStatus(): void
	{
		$store = [
			'integration_arbeitszeitcheck_include_pii' => '0',
		];
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(static function (string $app, string $key, string $default = '') use (&$store): string {
			if ($app !== Application::APP_ID) {
				return $default;
			}
			return $store[$key] ?? $default;
		});

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('warning');

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnOnConsecutiveCalls(
			$this->qbExecuteStatement(self::once()), // audit from noteUnknownEnum
			$this->qbFetchOne(false),
			$this->qbExecuteStatement(self::once()), // insert mirror
		);

		$svc = new ArbeitszeitCheckIntegrationService(
			$db,
			$config,
			$this->createMock(IAppManager::class),
			$this->createMock(IURLGenerator::class),
			$this->createMock(ITimeFactory::class),
			$logger,
			$this->createMock(IArbeitszeitCheckAbsenceReader::class),
		);

		$m = new ReflectionMethod(ArbeitszeitCheckIntegrationService::class, 'upsertMirrorRow');
		$m->setAccessible(true);
		$m->invoke($svc, 'u1', [
			'atAbsenceId' => 7,
			'userId' => 'u1',
			'startDate' => '2026-07-01',
			'endDate' => '2026-07-02',
			'type' => 'vacation',
			'status' => 'brand_new_status',
			'days' => 2.0,
			'createdAt' => '2026-07-01T00:00:00Z',
			'updatedAt' => '2026-07-01T00:00:00Z',
		]);
	}
}
