<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Integration;

use OCA\DutyCheck\AppInfo\Application;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;

/**
 * REQ-RD-12 Sync now rate limits: 1/60s/admin, 6/h/admin, 30/h/instance.
 *
 * Per-admin hit buckets are stored via IConfig::setAppValue(). Nextcloud's
 * oc_appconfig.configkey is varchar(64) (OC\AppConfig::KEY_MAX_LENGTH); keys
 * longer than that throw InvalidArgumentException and abort Sync now with HTTP 400.
 */
final class IntegrationSyncRateLimiter
{
	/** Matches OC\AppConfig::KEY_MAX_LENGTH / oc_appconfig.configkey. */
	public const APP_CONFIG_KEY_MAX = 64;

	private const CFG_INSTANCE = 'integration_at_sync_rl_instance';
	private const ADMIN_KEY_PREFIX = 'integration_at_sync_rl_a_';
	private const LOCK_KEY = 'dutycheck/integration_at_sync_rl';

	public function __construct(
		private IConfig $config,
		private ITimeFactory $timeFactory,
		private ?ILockingProvider $locking = null,
	) {
	}

	/**
	 * @return array{allowed: true}|array{allowed: false, retryAfter: int, code: string}
	 */
	public function check(string $adminUid): array
	{
		$now = $this->timeFactory->getTime();
		$adminUid = trim($adminUid);
		if ($adminUid === '') {
			return ['allowed' => false, 'retryAfter' => IntegrationOpsConstants::SYNC_RL_PER_ADMIN_INTERVAL, 'code' => 'INTEGRATION_SYNC_RATE_LIMIT'];
		}

		$adminKey = $this->adminKey($adminUid);
		$adminHits = $this->readHits($adminKey);
		$instanceHits = $this->readHits(self::CFG_INSTANCE);

		$adminHits = $this->prune($adminHits, $now - 3600);
		$instanceHits = $this->prune($instanceHits, $now - 3600);

		if ($adminHits !== []) {
			$last = max($adminHits);
			$sinceLast = $now - $last;
			if ($sinceLast < IntegrationOpsConstants::SYNC_RL_PER_ADMIN_INTERVAL) {
				return [
					'allowed' => false,
					'retryAfter' => IntegrationOpsConstants::SYNC_RL_PER_ADMIN_INTERVAL - $sinceLast,
					'code' => 'INTEGRATION_SYNC_RATE_LIMIT',
				];
			}
		}

		if (count($adminHits) >= IntegrationOpsConstants::SYNC_RL_PER_ADMIN_HOUR) {
			$oldest = min($adminHits);
			return [
				'allowed' => false,
				'retryAfter' => max(1, ($oldest + 3600) - $now),
				'code' => 'INTEGRATION_SYNC_RATE_LIMIT',
			];
		}

		if (count($instanceHits) >= IntegrationOpsConstants::SYNC_RL_PER_INSTANCE_HOUR) {
			$oldest = min($instanceHits);
			return [
				'allowed' => false,
				'retryAfter' => max(1, ($oldest + 3600) - $now),
				'code' => 'INTEGRATION_SYNC_RATE_LIMIT',
			];
		}

		return ['allowed' => true];
	}

	/**
	 * Atomic check+record under an exclusive lock (REQ-RD-12 / D15).
	 *
	 * @return array{allowed: true}|array{allowed: false, retryAfter: int, code: string}
	 */
	public function tryConsume(string $adminUid): array
	{
		$held = false;
		if ($this->locking !== null) {
			$attempts = 0;
			while (true) {
				try {
					$this->locking->acquireLock(self::LOCK_KEY, ILockingProvider::LOCK_EXCLUSIVE);
					$held = true;
					break;
				} catch (LockedException) {
					if (++$attempts >= 40) {
						return [
							'allowed' => false,
							'retryAfter' => IntegrationOpsConstants::SYNC_RL_PER_ADMIN_INTERVAL,
							'code' => 'INTEGRATION_SYNC_RATE_LIMIT',
						];
					}
					usleep(25_000);
				}
			}
		}
		try {
			$decision = $this->check($adminUid);
			if ($decision['allowed'] !== true) {
				return $decision;
			}
			$this->record($adminUid);
			return ['allowed' => true];
		} finally {
			if ($held && $this->locking !== null) {
				$this->locking->releaseLock(self::LOCK_KEY, ILockingProvider::LOCK_EXCLUSIVE);
			}
		}
	}

	/**
	 * Record a successful / accepted Sync now trigger (after rate check passes).
	 */
	public function record(string $adminUid): void
	{
		$now = $this->timeFactory->getTime();
		$adminUid = trim($adminUid);
		if ($adminUid === '') {
			return;
		}
		$adminKey = $this->adminKey($adminUid);
		$adminHits = $this->prune($this->readHits($adminKey), $now - 3600);
		$instanceHits = $this->prune($this->readHits(self::CFG_INSTANCE), $now - 3600);
		$adminHits[] = $now;
		$instanceHits[] = $now;
		$this->writeHits($adminKey, $adminHits);
		$this->writeHits(self::CFG_INSTANCE, $instanceHits);
	}

	private function adminKey(string $adminUid): string
	{
		// Hash avoids reserved config chars / injection into key space.
		// Truncate digest so prefix + hex always fits oc_appconfig.configkey (64).
		$budget = self::APP_CONFIG_KEY_MAX - strlen(self::ADMIN_KEY_PREFIX);
		if ($budget < 16) {
			// Compile-time invariant: prefix must leave enough entropy for collision resistance.
			throw new \LogicException('ADMIN_KEY_PREFIX leaves insufficient budget for appconfig key');
		}
		return self::ADMIN_KEY_PREFIX . substr(hash('sha256', $adminUid), 0, $budget);
	}

	/**
	 * @return list<int>
	 */
	private function readHits(string $key): array
	{
		$raw = trim($this->config->getAppValue(Application::APP_ID, $key, ''));
		if ($raw === '') {
			return [];
		}
		try {
			$data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
		} catch (\Throwable) {
			return [];
		}
		if (!is_array($data)) {
			return [];
		}
		$out = [];
		foreach ($data as $v) {
			if (is_int($v) || (is_string($v) && ctype_digit($v))) {
				$out[] = (int) $v;
			}
		}
		return $out;
	}

	/**
	 * @param list<int> $hits
	 * @return list<int>
	 */
	private function prune(array $hits, int $minTs): array
	{
		$out = [];
		foreach ($hits as $ts) {
			if ($ts >= $minTs) {
				$out[] = $ts;
			}
		}
		return $out;
	}

	/**
	 * @param list<int> $hits
	 */
	private function writeHits(string $key, array $hits): void
	{
		if (strlen($key) > self::APP_CONFIG_KEY_MAX) {
			throw new \InvalidArgumentException(
				'Value (' . $key . ') for key is too long (' . self::APP_CONFIG_KEY_MAX . ')'
			);
		}
		$this->config->setAppValue(
			Application::APP_ID,
			$key,
			json_encode(array_values($hits), JSON_THROW_ON_ERROR),
		);
	}
}
