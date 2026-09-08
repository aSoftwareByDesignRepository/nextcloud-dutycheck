<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Service;

use OCA\DutyCheck\Db\SchemaProbe;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Throwable;

/**
 * Period-scoped mutex for bulk writers (suggest-fill, copy, …) and
 * entity-scoped writers (rotation assign, blackout merge, swap request).
 *
 * Rows live in {@see dc_period_locks}; primary key is (period_id, lock_kind).
 * For entity locks, `period_id` stores the entity id (employee_id or assignment_id)
 * and `lock_kind` is one of KIND_ROT_ASSIGN / KIND_BLACKOUT / KIND_SWAP_REQ.
 * Acquire is CAS: insert, or overwrite only when the existing lock is expired.
 */
final class PeriodLockService
{
	public const KIND_SUGGEST = 'suggest';

	/** Entity lock: first PK column = employee_id */
	public const KIND_ROT_ASSIGN = 'rot_assign';

	/** Entity lock: first PK column = employee_id */
	public const KIND_BLACKOUT = 'blackout';

	/** Entity lock: first PK column = assignment_id */
	public const KIND_SWAP_REQ = 'swap_req';

	/** Entity lock: first PK column = employee_id */
	public const KIND_PREF = 'pref';

	/** Entity lock: first PK column = hash(company+YYYY-MM) for calendar-month ensure */
	public const KIND_ENSURE_MONTH = 'ensure_mo';

	/** Entity lock: first PK column = hash(bucket_key) for rate-limit rows */
	public const KIND_RATE_LIMIT = 'rate_lim';

	public const DEFAULT_TTL_SECONDS = 600;

	public function __construct(
		private readonly IDBConnection $db,
	) {
	}

	/**
	 * Try to acquire (or refresh if already held by the same holder).
	 * Stale locks (expires_at ≤ now) may be overwritten by another holder.
	 */
	public function acquire(int $periodId, string $kind, string $holder, int $ttlSeconds = self::DEFAULT_TTL_SECONDS): bool
	{
		if ($periodId < 1 || $kind === '' || $holder === '') {
			return false;
		}
		if (!SchemaProbe::tableExists($this->db, 'dc_period_locks')) {
			throw new \InvalidArgumentException('SCHEMA_NOT_READY');
		}

		$ttlSeconds = max(1, $ttlSeconds);
		$now = new \DateTimeImmutable('now');
		$nowStr = $now->format('Y-m-d H:i:s');
		$expiresStr = $now->modify('+' . $ttlSeconds . ' seconds')->format('Y-m-d H:i:s');

		// Fast path: insert when no row exists.
		try {
			$ins = $this->db->getQueryBuilder();
			$ins->insert('dc_period_locks')->values([
				'period_id' => $ins->createNamedParameter($periodId, IQueryBuilder::PARAM_INT),
				'lock_kind' => $ins->createNamedParameter($kind),
				'holder' => $ins->createNamedParameter(mb_substr($holder, 0, 64)),
				'acquired_at' => $ins->createNamedParameter($nowStr),
				'expires_at' => $ins->createNamedParameter($expiresStr),
			])->executeStatement();
			return true;
		} catch (Throwable $e) {
			if (!$this->isUniqueConstraintViolation($e)) {
				throw $e;
			}
		}

		$existing = $this->fetchLock($periodId, $kind);
		if ($existing === null) {
			// Lost race with a delete — retry insert once.
			try {
				$ins = $this->db->getQueryBuilder();
				$ins->insert('dc_period_locks')->values([
					'period_id' => $ins->createNamedParameter($periodId, IQueryBuilder::PARAM_INT),
					'lock_kind' => $ins->createNamedParameter($kind),
					'holder' => $ins->createNamedParameter(mb_substr($holder, 0, 64)),
					'acquired_at' => $ins->createNamedParameter($nowStr),
					'expires_at' => $ins->createNamedParameter($expiresStr),
				])->executeStatement();
				return true;
			} catch (Throwable $e) {
				if ($this->isUniqueConstraintViolation($e)) {
					return false;
				}
				throw $e;
			}
		}

		$holderTrim = mb_substr($holder, 0, 64);
		$expiresAt = (string) ($existing['expires_at'] ?? '');
		$isExpired = $expiresAt !== '' && $expiresAt <= $nowStr;
		$sameHolder = (string) ($existing['holder'] ?? '') === $holderTrim;

		if (!$isExpired && !$sameHolder) {
			return false;
		}

		// CAS overwrite: same holder refresh, or steal expired lock.
		$upd = $this->db->getQueryBuilder();
		$upd->update('dc_period_locks')
			->set('holder', $upd->createNamedParameter($holderTrim))
			->set('acquired_at', $upd->createNamedParameter($nowStr))
			->set('expires_at', $upd->createNamedParameter($expiresStr))
			->where($upd->expr()->eq('period_id', $upd->createNamedParameter($periodId, IQueryBuilder::PARAM_INT)))
			->andWhere($upd->expr()->eq('lock_kind', $upd->createNamedParameter($kind)));
		if ($sameHolder && !$isExpired) {
			$upd->andWhere($upd->expr()->eq('holder', $upd->createNamedParameter($holderTrim)));
		} else {
			// Steal only if still expired (CAS against concurrent refresh).
			$upd->andWhere($upd->expr()->lte('expires_at', $upd->createNamedParameter($nowStr)));
		}
		$affected = $upd->executeStatement();
		return $affected === 1;
	}

	public function release(int $periodId, string $kind, string $holder): void
	{
		if ($periodId < 1 || $kind === '' || $holder === '') {
			return;
		}
		if (!SchemaProbe::tableExists($this->db, 'dc_period_locks')) {
			return;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->delete('dc_period_locks')
			->where($qb->expr()->eq('period_id', $qb->createNamedParameter($periodId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('lock_kind', $qb->createNamedParameter($kind)))
			->andWhere($qb->expr()->eq('holder', $qb->createNamedParameter(mb_substr($holder, 0, 64))))
			->executeStatement();
	}

	/**
	 * @return array{period_id:int|string,lock_kind:string,holder:string,acquired_at:string,expires_at:string}|null
	 */
	private function fetchLock(int $periodId, string $kind): ?array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('dc_period_locks')
			->where($qb->expr()->eq('period_id', $qb->createNamedParameter($periodId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('lock_kind', $qb->createNamedParameter($kind)))
			->setMaxResults(1);
		$row = $qb->executeQuery()->fetch();
		return $row === false ? null : $row;
	}

	private function isUniqueConstraintViolation(Throwable $e): bool
	{
		$chain = $e;
		for ($i = 0; $i < 8 && $chain !== null; $i++) {
			$code = (string) $chain->getCode();
			if ($code === '23000' || $code === '23505') {
				return true;
			}
			$msg = strtolower($chain->getMessage());
			if (str_contains($msg, 'duplicate')
				|| str_contains($msg, 'unique constraint')
				|| str_contains($msg, 'integrity constraint')) {
				return true;
			}
			$prev = $chain->getPrevious();
			$chain = $prev instanceof Throwable ? $prev : null;
		}
		return false;
	}
}
