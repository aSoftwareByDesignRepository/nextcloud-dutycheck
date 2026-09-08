<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Service;

use DateTimeImmutable;
use OCA\DutyCheck\Db\SchemaProbe;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Portable sliding-window rate limits via {@see dc_api_rate_limits}.
 *
 * Used for ICS spray protection and expensive planner mutations (suggest-fill).
 * Bucket mutations are serialized with {@see PeriodLockService} so concurrent
 * requests cannot exceed the window cap (TOCTOU-safe).
 */
final class ApiRateLimitService
{
	public function __construct(
		private readonly IDBConnection $db,
		private readonly ?PeriodLockService $locks = null,
	) {
	}

	/**
	 * @throws \InvalidArgumentException RATE_LIMITED when the bucket is full
	 */
	public function assertAllowed(string $bucketKey, int $maxPerWindow, int $windowSeconds = 60): void
	{
		$bucketKey = trim($bucketKey);
		if ($bucketKey === '' || $maxPerWindow < 1 || $windowSeconds < 1) {
			throw new \InvalidArgumentException('RATE_LIMIT_CONFIG');
		}
		if (!SchemaProbe::tableExists($this->db, 'dc_api_rate_limits')) {
			// Fail open only when schema predates the table — prefer availability over hard down.
			return;
		}

		$holder = 'rl:' . bin2hex(random_bytes(4));
		$lockId = $this->bucketLockId($bucketKey);
		$locked = false;
		if ($this->locks !== null && SchemaProbe::tableExists($this->db, 'dc_period_locks')) {
			$locked = $this->locks->acquire($lockId, PeriodLockService::KIND_RATE_LIMIT, $holder, 15);
			if (!$locked) {
				throw new \InvalidArgumentException('RATE_LIMITED');
			}
		}

		try {
			$windowStart = (new DateTimeImmutable('now'))
				->modify('-' . $windowSeconds . ' seconds')
				->format('Y-m-d H:i:s');
			$now = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');

			$cleanup = $this->db->getQueryBuilder();
			$cleanup->delete('dc_api_rate_limits')
				->where($cleanup->expr()->lt('created_at', $cleanup->createNamedParameter($windowStart)))
				->executeStatement();

			$qb = $this->db->getQueryBuilder();
			$qb->select($qb->func()->count('*', 'cnt'))
				->from('dc_api_rate_limits')
				->where($qb->expr()->eq('bucket_key', $qb->createNamedParameter($bucketKey)));
			if ((int) $qb->executeQuery()->fetchOne() >= $maxPerWindow) {
				throw new \InvalidArgumentException('RATE_LIMITED');
			}

			$insert = $this->db->getQueryBuilder();
			$insert->insert('dc_api_rate_limits')
				->values([
					'bucket_key' => $insert->createNamedParameter($bucketKey),
					'created_at' => $insert->createNamedParameter($now),
				])->executeStatement();
		} finally {
			if ($locked && $this->locks !== null) {
				$this->locks->release($lockId, PeriodLockService::KIND_RATE_LIMIT, $holder);
			}
		}
	}

	/** Stable positive int for {@see PeriodLockService} entity PK. */
	private function bucketLockId(string $bucketKey): int
	{
		$n = unpack('N', substr(hash('sha256', $bucketKey, true), 0, 4))[1] & 0x7fffffff;
		return $n > 0 ? $n : 1;
	}
}
