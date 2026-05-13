<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\Integration;

use OCA\DutyCheck\AppInfo\Application;
use OCA\DutyCheck\Exception\IntegrationLegacyConflictException;
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

class ArbeitszeitCheckIntegrationServiceTest extends TestCase
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
		$config->method('deleteAppValue')->willReturnCallback(function (string $appId, string $key) use (&$store): void {
			if ($appId === Application::APP_ID) {
				unset($store[$key]);
			}
		});
		return $config;
	}

	private function appManagerPeerOk(): IAppManager
	{
		$app = $this->createMock(IAppManager::class);
		$app->method('isInstalled')->with(ArbeitszeitCheckIntegrationService::PEER_APP_ID)->willReturn(true);
		$app->method('isEnabledForUser')->willReturn(true);
		$app->method('getAppVersion')->with(ArbeitszeitCheckIntegrationService::PEER_APP_ID)->willReturn('1.0.0');
		return $app;
	}

	private function service(
		IDBConnection $db,
		IConfig $config,
		?IAppManager $appManager = null,
		?ITimeFactory $time = null,
		?IArbeitszeitCheckAbsenceReader $reader = null,
	): ArbeitszeitCheckIntegrationService {
		$time ??= $this->timeAt(1_000_000);
		$appManager ??= $this->appManagerPeerOk();
		$reader ??= $this->createMock(IArbeitszeitCheckAbsenceReader::class);
		$url = $this->createMock(IURLGenerator::class);
		$logger = $this->createMock(LoggerInterface::class);
		return new ArbeitszeitCheckIntegrationService(
			$db,
			$config,
			$appManager,
			$url,
			$time,
			$logger,
			$reader,
		);
	}

	private function timeAt(int $ts): ITimeFactory
	{
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn($ts);
		return $time;
	}

	public function testSetIntentEnabledThrowsWhenLegacyAbsencesExistOnLinkedEmployees(): void
	{
		$store = [
			'integration_at_intent_enabled' => '0',
		];
		$config = $this->configWithStore($store);
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn(
			$this->qbFetchOne(4),
		);

		$svc = $this->service($db, $config);
		try {
			$svc->setIntentEnabled(true, 'admin');
			self::fail('expected IntegrationLegacyConflictException');
		} catch (IntegrationLegacyConflictException $e) {
			self::assertSame(4, $e->getLegacyAbsenceCount());
		}
	}

	public function testPurgeLegacyAbsencesReturnsZeroWithoutTransactionWhenNoRows(): void
	{
		$store = ['integration_at_intent_enabled' => '0'];
		$config = $this->configWithStore($store);
		$db = $this->createMock(IDBConnection::class);
		$db->expects(self::never())->method('beginTransaction');
		$db->method('getQueryBuilder')->willReturn($this->qbFetchOne(0));
		$svc = $this->service($db, $config);
		self::assertSame(0, $svc->purgeLegacyAbsencesForLinkedEmployees('admin'));
	}

	public function testPurgeLegacyAbsencesThrowsWhenIntentEnabled(): void
	{
		$store = ['integration_at_intent_enabled' => '1'];
		$config = $this->configWithStore($store);
		$db = $this->createMock(IDBConnection::class);
		$db->expects(self::never())->method('beginTransaction');
		$svc = $this->service($db, $config);
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('INTEGRATION_PURGE_BLOCKED');
		$svc->purgeLegacyAbsencesForLinkedEmployees('admin');
	}

	public function testPurgeLegacyAbsencesDeletesAndCommits(): void
	{
		$store = ['integration_at_intent_enabled' => '0'];
		$config = $this->configWithStore($store);
		$db = $this->createMock(IDBConnection::class);
		$db->expects(self::once())->method('beginTransaction');
		$db->expects(self::once())->method('commit');
		$db->method('rollBack');
		$db->method('getQueryBuilder')->willReturnOnConsecutiveCalls(
			$this->qbFetchOne(2),
			$this->qbFetchAllAssociative([['id' => 10], ['id' => 11]]),
			$this->qbExecuteStatement(null, 2),
			$this->qbExecuteStatement(null, 1),
		);
		$svc = $this->service($db, $config);
		self::assertSame(2, $svc->purgeLegacyAbsencesForLinkedEmployees('admin'));
	}

	public function testRunReconcileSkipsAndReleasesLeaseWhenNotEffective(): void
	{
		$store = ['integration_at_intent_enabled' => '0'];
		$config = $this->configWithStore($store);
		$db = $this->createMock(IDBConnection::class);
		$svc = $this->service($db, $config);
		$result = $svc->runReconcile('lease-token');
		self::assertTrue($result['ok']);
		self::assertSame('skipped_not_effective', $result['code']);
		self::assertSame(0, $result['rows'] ?? null);
	}

	public function testAcquireSyncLeaseFailsWhenExistingLeaseStillValid(): void
	{
		$store = [];
		$config = $this->configWithStore($store);
		$db = $this->createMock(IDBConnection::class);
		$time = $this->timeAt(1000);
		$svc = $this->service($db, $config, null, $time);

		$first = $svc->acquireSyncLease(600);
		self::assertTrue($first['acquired']);
		self::assertNotEmpty($first['token'] ?? null);

		$second = $svc->acquireSyncLease(600);
		self::assertFalse($second['acquired']);
		self::assertSame('INTEGRATION_SYNC_ALREADY_RUNNING', $second['message'] ?? null);
	}

	public function testAcquireSyncLeaseSucceedsAfterPreviousLeaseExpired(): void
	{
		$store = [];
		$config = $this->configWithStore($store);
		$db = $this->createMock(IDBConnection::class);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturnOnConsecutiveCalls(1000, 2000);
		$svc = $this->service($db, $config, null, $time);

		self::assertTrue($svc->acquireSyncLease(100)['acquired']);
		self::assertTrue($svc->acquireSyncLease(100)['acquired']);
	}

	public function testReconcileUserReloadsFromReaderWhenMirrorExistsButBatchWasEmpty(): void
	{
		$store = [];
		$config = $this->configWithStore($store);
		$row = [
			'atAbsenceId' => 42,
			'userId' => 'user-1',
			'type' => 'vacation',
			'startDate' => '2026-04-01',
			'endDate' => '2026-04-02',
			'days' => 2.0,
			'status' => 'approved',
			'createdAt' => '2026-03-01T10:00:00+00:00',
			'updatedAt' => '2026-03-01T10:00:00+00:00',
		];
		$reader = $this->createMock(IArbeitszeitCheckAbsenceReader::class);
		$reader->expects(self::once())->method('listAbsencesOverlapping')
			->with(['user-1'], '2025-06-01', '2027-06-01')
			->willReturn([$row]);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnOnConsecutiveCalls(
			$this->qbFetchOne(1),
			$this->qbFetchOne(false),
			$this->qbExecuteStatement(self::once()),
			$this->qbFetchAllAssociative([['at_absence_id' => 9998]]),
			$this->qbExecuteStatement(self::once()),
		);

		$svc = $this->service($db, $config, $this->createMock(IAppManager::class), null, $reader);
		$m = new ReflectionMethod(ArbeitszeitCheckIntegrationService::class, 'reconcileUser');
		$m->setAccessible(true);
		$m->invoke($svc, 'user-1', [], '2025-06-01', '2027-06-01');
	}

	public function testReconcileUserUpdatesWhenMirrorRowAlreadyExists(): void
	{
		$store = [];
		$config = $this->configWithStore($store);
		$reader = $this->createMock(IArbeitszeitCheckAbsenceReader::class);
		$reader->expects(self::never())->method('listAbsencesOverlapping');

		$row = [
			'atAbsenceId' => 9001,
			'userId' => 'user-1',
			'type' => 'sick_leave',
			'startDate' => '2026-05-01',
			'endDate' => '2026-05-03',
			'days' => 3.0,
			'status' => 'approved',
			'createdAt' => '2026-04-01T10:00:00+00:00',
			'updatedAt' => '2026-04-02T10:00:00+00:00',
		];

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnOnConsecutiveCalls(
			$this->qbFetchOne(0),
			$this->qbFetchOne(500),
			$this->qbExecuteStatement(self::once()),
			$this->qbFetchAllAssociative([['at_absence_id' => 8888]]),
			$this->qbExecuteStatement(self::once()),
		);

		$svc = $this->service($db, $config, $this->createMock(IAppManager::class), null, $reader);
		$m = new ReflectionMethod(ArbeitszeitCheckIntegrationService::class, 'reconcileUser');
		$m->setAccessible(true);
		$m->invoke($svc, 'user-1', [$row], '2025-06-01', '2027-06-01');
	}

	public function testReleaseSyncLeaseDeletesOnlyWhenTokenMatches(): void
	{
		$store = [
			'integration_at_sync_lease' => json_encode([
				'token' => 'good',
				'until' => 999999,
				'startedAt' => '2026-01-01T00:00:00Z',
			], JSON_THROW_ON_ERROR),
		];
		$config = $this->configWithStore($store);
		$db = $this->createMock(IDBConnection::class);
		$svc = $this->service($db, $config);
		$svc->releaseSyncLease('wrong');
		self::assertArrayHasKey('integration_at_sync_lease', $store);
		$svc->releaseSyncLease('good');
		self::assertArrayNotHasKey('integration_at_sync_lease', $store);
	}

	public function testRunReconcileReturnsBreakerWhenReaderThrows(): void
	{
		$store = [
			'integration_at_intent_enabled' => '1',
			'integration_at_breaker_until' => '',
			'integration_t_stale_seconds' => '3600',
		];
		$config = $this->configWithStore($store);
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn(
			$this->qbFetchAllAssociative([['linked_user_id' => 'user-1']]),
		);

		$reader = $this->createMock(IArbeitszeitCheckAbsenceReader::class);
		$reader->method('listAbsencesOverlapping')->willThrowException(new \RuntimeException('at db down'));

		$svc = $this->service($db, $config, null, null, $reader);
		$result = $svc->runReconcile('tok');
		self::assertFalse($result['ok']);
		self::assertSame('INTEGRATION_SYNC_BREAKER_TRIPPED', $result['code']);
	}

	public function testReconcileUserDeletesMirrorWhenBatchEmptyButMirrorExistedAndRetryStillEmpty(): void
	{
		$store = [];
		$config = $this->configWithStore($store);
		$reader = $this->createMock(IArbeitszeitCheckAbsenceReader::class);
		$reader->expects(self::once())->method('listAbsencesOverlapping')
			->with(['user-1'], '2025-06-01', '2027-06-01')
			->willReturn([]);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnOnConsecutiveCalls(
			$this->qbFetchOne(1),
			$this->qbExecuteStatement(self::once()),
		);

		$svc = $this->service($db, $config, $this->createMock(IAppManager::class), null, $reader);
		$m = new ReflectionMethod(ArbeitszeitCheckIntegrationService::class, 'reconcileUser');
		$m->setAccessible(true);
		$m->invoke($svc, 'user-1', [], '2025-06-01', '2027-06-01');
	}

	public function testReconcileUserDoesNotTouchDatabaseWhenNoMirrorAndNoRows(): void
	{
		$store = [];
		$config = $this->configWithStore($store);
		$reader = $this->createMock(IArbeitszeitCheckAbsenceReader::class);
		$reader->expects(self::never())->method('listAbsencesOverlapping');

		$db = $this->createMock(IDBConnection::class);
		$db->expects(self::once())->method('getQueryBuilder')->willReturn($this->qbFetchOne(0));

		$svc = $this->service($db, $config, $this->createMock(IAppManager::class), null, $reader);
		$m = new ReflectionMethod(ArbeitszeitCheckIntegrationService::class, 'reconcileUser');
		$m->setAccessible(true);
		$m->invoke($svc, 'user-1', [], '2025-06-01', '2027-06-01');
	}

	public function testReconcileUserInsertsNewMirrorRowAndPrunesStaleIds(): void
	{
		$store = [];
		$config = $this->configWithStore($store);
		$reader = $this->createMock(IArbeitszeitCheckAbsenceReader::class);
		$reader->expects(self::never())->method('listAbsencesOverlapping');

		$row = [
			'atAbsenceId' => 9001,
			'userId' => 'user-1',
			'type' => 'vacation',
			'startDate' => '2026-03-01',
			'endDate' => '2026-03-10',
			'days' => 8.0,
			'status' => 'approved',
			'createdAt' => '2026-02-01T10:00:00+00:00',
			'updatedAt' => '2026-02-02T10:00:00+00:00',
		];

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnOnConsecutiveCalls(
			$this->qbFetchOne(0),
			$this->qbFetchOne(false),
			$this->qbExecuteStatement(self::once()),
			$this->qbFetchAllAssociative([['at_absence_id' => 7777]]),
			$this->qbExecuteStatement(self::once()),
		);

		$svc = $this->service($db, $config, $this->createMock(IAppManager::class), null, $reader);
		$m = new ReflectionMethod(ArbeitszeitCheckIntegrationService::class, 'reconcileUser');
		$m->setAccessible(true);
		$m->invoke($svc, 'user-1', [$row], '2025-06-01', '2027-06-01');
	}

	public function testCountLegacyAbsencesForEmployeeReturnsFetchOne(): void
	{
		$store = [];
		$config = $this->configWithStore($store);
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($this->qbFetchOne(12));

		$svc = $this->service($db, $config);
		self::assertSame(12, $svc->countLegacyAbsencesForEmployee(42));
	}

	public function testGetAdminIntegrationStatusContainsLegacyCount(): void
	{
		$store = ['integration_at_intent_enabled' => '0'];
		$config = $this->configWithStore($store);
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($this->qbFetchOne(9));

		$app = $this->createMock(IAppManager::class);
		$app->method('isInstalled')->willReturn(false);

		$svc = $this->service($db, $config, $app);
		$st = $svc->getAdminIntegrationStatus();
		self::assertSame(9, $st['legacyAbsenceCount']);
		self::assertFalse($st['effective']);
	}

	public function testIntegrationLocksLinkedWhenIntentAndPeerReadyDespiteBreaker(): void
	{
		$store = [
			'integration_at_intent_enabled' => '1',
			'integration_at_breaker_until' => '2099-01-01 00:00:00',
		];
		$config = $this->configWithStore($store);
		$db = $this->createMock(IDBConnection::class);
		$svc = $this->service($db, $config, $this->appManagerPeerOk(), $this->timeAt(1_000_000));
		self::assertFalse($svc->isEffective());
		self::assertTrue($svc->integrationLocksLinkedDutyCheckAbsences());
		$b = $svc->buildBootstrapForUser('u1', true);
		self::assertTrue($b['readonlyAbsencesForCurrentUser']);
		self::assertTrue($b['integrationLocksLinkedDutyCheckAbsences']);
	}

	public function testIntegrationDoesNotLockLinkedWhenIntentOnButPeerMissing(): void
	{
		$store = ['integration_at_intent_enabled' => '1'];
		$config = $this->configWithStore($store);
		$db = $this->createMock(IDBConnection::class);
		$app = $this->createMock(IAppManager::class);
		$app->method('isInstalled')->willReturn(false);
		$svc = $this->service($db, $config, $app);
		self::assertFalse($svc->integrationLocksLinkedDutyCheckAbsences());
		$b = $svc->buildBootstrapForUser('u1', true);
		self::assertFalse($b['readonlyAbsencesForCurrentUser']);
	}
}
