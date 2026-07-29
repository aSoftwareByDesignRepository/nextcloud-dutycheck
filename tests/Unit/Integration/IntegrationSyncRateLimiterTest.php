<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\Integration;

use OCA\DutyCheck\AppInfo\Application;
use OCA\DutyCheck\Integration\IntegrationOpsConstants;
use OCA\DutyCheck\Integration\IntegrationSyncRateLimiter;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

final class IntegrationSyncRateLimiterTest extends TestCase
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
}
