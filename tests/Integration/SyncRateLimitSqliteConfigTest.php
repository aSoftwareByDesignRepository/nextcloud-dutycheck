<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Integration;

use OCA\DutyCheck\AppInfo\Application;
use OCA\DutyCheck\Integration\IntegrationOpsConstants;
use OCA\DutyCheck\Integration\IntegrationSyncRateLimiter;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * REQ-TST-13 — rate-limit buckets survive across limiter instances (config-backed).
 */
final class SyncRateLimitSqliteConfigTest extends TestCase
{
	public function testPerAdminIntervalAndHourBuckets(): void
	{
		$store = [];
		$config = $this->configStore($store);
		$clock = new class implements ITimeFactory {
			public int $now = 1_000_000;
			public function getTime(): int
			{
				return $this->now;
			}
			public function now(): \DateTimeImmutable
			{
				return (new \DateTimeImmutable('@' . $this->now))->setTimezone(new \DateTimeZone('UTC'));
			}
			public function getDateTime(string $time = 'now', ?\DateTimeZone $timezone = null): \DateTime
			{
				$tz = $timezone ?? new \DateTimeZone('UTC');
				$dt = new \DateTime('@' . $this->now);
				$dt->setTimezone($tz);
				return $dt;
			}
			public function withTimeZone(\DateTimeZone $timezone): static
			{
				return $this;
			}
			public function getTimeZone(?string $timezone = null): \DateTimeZone
			{
				return new \DateTimeZone($timezone ?? 'UTC');
			}
		};

		$rl = new IntegrationSyncRateLimiter($config, $clock);
		self::assertTrue($rl->check('admin-a')['allowed']);
		$rl->record('admin-a');

		$blocked = $rl->check('admin-a');
		self::assertFalse($blocked['allowed']);
		self::assertSame('INTEGRATION_SYNC_RATE_LIMIT', $blocked['code']);
		self::assertSame(IntegrationOpsConstants::SYNC_RL_PER_ADMIN_INTERVAL, $blocked['retryAfter']);

		// New instance must read the same config hits (no in-memory-only throttle).
		$rl2 = new IntegrationSyncRateLimiter($config, $clock);
		self::assertFalse($rl2->check('admin-a')['allowed']);

		$clock->now += IntegrationOpsConstants::SYNC_RL_PER_ADMIN_INTERVAL + 1;
		for ($i = 0; $i < IntegrationOpsConstants::SYNC_RL_PER_ADMIN_HOUR - 1; $i++) {
			self::assertTrue($rl2->check('admin-a')['allowed'], "hour slot $i");
			$rl2->record('admin-a');
			$clock->now += IntegrationOpsConstants::SYNC_RL_PER_ADMIN_INTERVAL + 1;
		}
		$hourBlock = $rl2->check('admin-a');
		self::assertFalse($hourBlock['allowed']);
		self::assertGreaterThan(0, $hourBlock['retryAfter']);
	}

	/**
	 * @param array<string, string> $store
	 */
	private function configStore(array &$store): IConfig
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
}
