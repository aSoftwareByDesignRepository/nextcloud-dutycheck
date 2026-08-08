<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\Integration;

use InvalidArgumentException;
use OCA\DutyCheck\AppInfo\Application;
use OCA\DutyCheck\Integration\IntegrationOpsConstants;
use OCA\DutyCheck\Integration\IntegrationSyncRateLimiter;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

final class IntegrationSyncRateLimiterTest extends TestCase
{
	/**
	 * Mimic OC\AppConfig::assertParams key length (varchar(64)).
	 *
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
			if (strlen($key) > IntegrationSyncRateLimiter::APP_CONFIG_KEY_MAX) {
				throw new InvalidArgumentException(
					'Value (' . $key . ') for key is too long (' . IntegrationSyncRateLimiter::APP_CONFIG_KEY_MAX . ')'
				);
			}
			if ($appId === Application::APP_ID) {
				$store[$key] = $value;
			}
		});
		return $config;
	}

	public function testAllowsFirstTriggerThenBlocksWithinSixtySeconds(): void
	{
		$store = [];
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1_000_000);
		$rl = new IntegrationSyncRateLimiter($this->configWithStore($store), $time);

		self::assertTrue($rl->check('admin')['allowed']);
		$rl->record('admin');
		$blocked = $rl->check('admin');
		self::assertFalse($blocked['allowed']);
		self::assertSame('INTEGRATION_SYNC_RATE_LIMIT', $blocked['code']);
		self::assertSame(IntegrationOpsConstants::SYNC_RL_PER_ADMIN_INTERVAL, $blocked['retryAfter']);
	}

	public function testBlocksAfterSixPerHourPerAdmin(): void
	{
		$store = [];
		$base = 2_000_000;
		$time = $this->createMock(ITimeFactory::class);
		$times = [];
		// Each check+record pair consumes 2 getTime() calls.
		for ($i = 0; $i < 6; $i++) {
			$ts = $base + ($i * 70);
			$times[] = $ts; // check
			$times[] = $ts; // record
		}
		$times[] = $base + (6 * 70); // final check
		$time->method('getTime')->willReturnOnConsecutiveCalls(...$times);
		$rl = new IntegrationSyncRateLimiter($this->configWithStore($store), $time);

		for ($i = 0; $i < 6; $i++) {
			self::assertTrue($rl->check('admin')['allowed'], "trigger $i");
			$rl->record('admin');
		}
		$blocked = $rl->check('admin');
		self::assertFalse($blocked['allowed']);
		self::assertSame('INTEGRATION_SYNC_RATE_LIMIT', $blocked['code']);
		self::assertGreaterThan(0, $blocked['retryAfter']);
	}

	public function testBlocksAfterThirtyPerHourInstanceWide(): void
	{
		$store = [];
		$base = 3_000_000;
		$time = $this->createMock(ITimeFactory::class);
		$seq = [];
		for ($i = 0; $i < 30; $i++) {
			$ts = $base + ($i * 70);
			$seq[] = $ts;
			$seq[] = $ts;
		}
		$seq[] = $base + (30 * 70);
		$time->method('getTime')->willReturnOnConsecutiveCalls(...$seq);
		$rl = new IntegrationSyncRateLimiter($this->configWithStore($store), $time);

		for ($i = 0; $i < 30; $i++) {
			$uid = 'admin-' . $i;
			self::assertTrue($rl->check($uid)['allowed'], "user $uid");
			$rl->record($uid);
		}
		$blocked = $rl->check('admin-other');
		self::assertFalse($blocked['allowed']);
		self::assertSame('INTEGRATION_SYNC_RATE_LIMIT', $blocked['code']);
	}

	public function testTryConsumeRecordsAtomically(): void
	{
		$store = [];
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(4_000_000);
		$rl = new IntegrationSyncRateLimiter($this->configWithStore($store), $time);
		self::assertTrue($rl->tryConsume('admin')['allowed']);
		$blocked = $rl->tryConsume('admin');
		self::assertFalse($blocked['allowed']);
		self::assertSame(IntegrationOpsConstants::SYNC_RL_PER_ADMIN_INTERVAL, $blocked['retryAfter']);
	}

	/**
	 * Regression: full sha256 in the admin key exceeded oc_appconfig.configkey (64)
	 * and crashed Sync now with HTTP 400 ("Value … for key is too long (64)").
	 */
	public function testAdminBucketKeysFitAppConfigKeyLimit(): void
	{
		$store = [];
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(5_000_000);
		$rl = new IntegrationSyncRateLimiter($this->configWithStore($store), $time);

		// Same uid shape as the reported production crash (hashed into the key).
		$adminUid = 'admin';
		$rl->record($adminUid);

		self::assertNotEmpty($store, 'record() must persist at least one config key');
		foreach (array_keys($store) as $key) {
			self::assertLessThanOrEqual(
				IntegrationSyncRateLimiter::APP_CONFIG_KEY_MAX,
				strlen($key),
				'config key exceeds Nextcloud appconfig limit: ' . $key,
			);
		}

		$adminKeys = array_values(array_filter(
			array_keys($store),
			static fn (string $k): bool => str_starts_with($k, 'integration_at_sync_rl_a_'),
		));
		self::assertCount(1, $adminKeys);
		self::assertSame(IntegrationSyncRateLimiter::APP_CONFIG_KEY_MAX, strlen($adminKeys[0]));
		self::assertArrayHasKey('integration_at_sync_rl_instance', $store);
		self::assertLessThanOrEqual(
			IntegrationSyncRateLimiter::APP_CONFIG_KEY_MAX,
			strlen('integration_at_sync_rl_instance'),
		);
	}

	public function testAdminKeysAreStableAndDistinctPerUid(): void
	{
		$storeA = [];
		$storeB = [];
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(6_000_000);

		$rlA = new IntegrationSyncRateLimiter($this->configWithStore($storeA), $time);
		$rlA->record('alice');
		$rlA->record('alice'); // second record within same second still same key

		$rlB = new IntegrationSyncRateLimiter($this->configWithStore($storeB), $time);
		$rlB->record('bob');

		$keysFor = static function (array $store): string {
			foreach (array_keys($store) as $k) {
				if (str_starts_with($k, 'integration_at_sync_rl_a_')) {
					return $k;
				}
			}
			self::fail('missing per-admin rate-limit key');
		};

		$keyAlice = $keysFor($storeA);
		$keyBob = $keysFor($storeB);
		self::assertNotSame($keyAlice, $keyBob);
		self::assertLessThanOrEqual(IntegrationSyncRateLimiter::APP_CONFIG_KEY_MAX, strlen($keyAlice));
		self::assertLessThanOrEqual(IntegrationSyncRateLimiter::APP_CONFIG_KEY_MAX, strlen($keyBob));

		// Stable: re-record into a fresh store for alice must reuse the same key.
		$storeA2 = [];
		$rlA2 = new IntegrationSyncRateLimiter($this->configWithStore($storeA2), $time);
		$rlA2->record('alice');
		self::assertSame($keyAlice, $keysFor($storeA2));
	}

	public function testEmptyAdminUidIsDeniedAndDoesNotWrite(): void
	{
		$store = [];
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(7_000_000);
		$rl = new IntegrationSyncRateLimiter($this->configWithStore($store), $time);

		$denied = $rl->check('  ');
		self::assertFalse($denied['allowed']);
		$rl->record('   ');
		self::assertSame([], $store);
	}

	public function testTryConsumePersistsWithinKeyLimit(): void
	{
		$store = [];
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(8_000_000);
		$rl = new IntegrationSyncRateLimiter($this->configWithStore($store), $time);

		self::assertTrue($rl->tryConsume('unicode-admin-üñîçødë')['allowed']);
		foreach (array_keys($store) as $key) {
			self::assertLessThanOrEqual(IntegrationSyncRateLimiter::APP_CONFIG_KEY_MAX, strlen($key));
		}
	}
}
