<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Service;

use OCA\DutyCheck\AppInfo\Application;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IConfig;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Wave C2 — retention for roster snapshots (never mutates closed evidence in-place).
 *
 * Deletes only snapshots older than the retention window that are NOT:
 * - the latest close tip for any period (including reopened periods whose
 *   `close_snapshot_id` was cleared), or
 * - the current close tip still referenced by a closed period, or
 * - any predecessor reachable via prev_snapshot_id (hash-chain integrity).
 */
class SnapshotRetentionService
{
	private const CONFIG_DAYS = 'snapshot_retention_days';

	public function __construct(
		private readonly IDBConnection $db,
		private readonly IConfig $config,
		private readonly LoggerInterface $logger,
	) {
	}

	public function retentionDays(): int
	{
		$days = (int) $this->config->getAppValue(Application::APP_ID, self::CONFIG_DAYS, '0');
		return max(0, min(3650, $days));
	}

	/**
	 * @return array{enabled:bool,deleted:int,retentionDays:int}
	 */
	public function pruneExpired(): array
	{
		$days = $this->retentionDays();
		if ($days <= 0) {
			return ['enabled' => false, 'deleted' => 0, 'retentionDays' => 0];
		}
		$cutoff = (new \DateTimeImmutable('now'))->modify('-' . $days . ' days')->format('Y-m-d H:i:s');
		$protected = $this->protectedSnapshotIds();
		$deleted = 0;
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->select('id')->from('dc_roster_snapshots')
				->where($qb->expr()->lt('generated_at', $qb->createNamedParameter($cutoff)));
			$rows = $qb->executeQuery()->fetchAll();
			foreach ($rows as $row) {
				$id = (int) $row['id'];
				if (isset($protected[$id])) {
					continue;
				}
				$del = $this->db->getQueryBuilder();
				$del->delete('dc_roster_snapshots')
					->where($del->expr()->eq('id', $del->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
					->executeStatement();
				$deleted++;
			}
		} catch (Throwable $e) {
			$this->logger->error('DutyCheck snapshot retention prune failed', [
				'app' => Application::APP_ID,
				'exception' => $e,
			]);
			throw $e;
		}
		return ['enabled' => true, 'deleted' => $deleted, 'retentionDays' => $days];
	}

	/**
	 * Protect close tips and every predecessor in their prev_snapshot_id chain.
	 *
	 * @return array<int,true>
	 */
	private function protectedSnapshotIds(): array
	{
		$out = [];
		foreach ($this->chainTipSnapshotIds() as $tipId) {
			$this->protectChain($tipId, $out);
		}
		return $out;
	}

	/**
	 * Latest close snapshot per period (survives reopen) plus any still-closed tip pointers.
	 *
	 * @return list<int>
	 */
	private function chainTipSnapshotIds(): array
	{
		$tips = [];
		foreach ($this->latestCloseSnapshotIdsPerPeriod() as $id) {
			$tips[$id] = true;
		}
		foreach ($this->closedPeriodCloseSnapshotIds() as $id) {
			$tips[$id] = true;
		}
		return array_map('intval', array_keys($tips));
	}

	/**
	 * @return list<int>
	 */
	private function latestCloseSnapshotIdsPerPeriod(): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'period_id')
			->from('dc_roster_snapshots')
			->where($qb->expr()->eq('snapshot_kind', $qb->createNamedParameter('close')))
			->orderBy('period_id', 'ASC')
			->addOrderBy('generated_at', 'DESC')
			->addOrderBy('id', 'DESC');
		$ids = [];
		$seenPeriods = [];
		foreach ($qb->executeQuery()->fetchAll() as $row) {
			$periodId = (int) ($row['period_id'] ?? 0);
			if ($periodId <= 0 || isset($seenPeriods[$periodId])) {
				continue;
			}
			$seenPeriods[$periodId] = true;
			$id = (int) ($row['id'] ?? 0);
			if ($id > 0) {
				$ids[] = $id;
			}
		}
		return $ids;
	}

	/** @return list<int> */
	private function closedPeriodCloseSnapshotIds(): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('close_snapshot_id')->from('dc_periods')
			->where($qb->expr()->eq('status', $qb->createNamedParameter('closed')))
			->andWhere($qb->expr()->isNotNull('close_snapshot_id'));
		$ids = [];
		foreach ($qb->executeQuery()->fetchAll() as $row) {
			$id = (int) ($row['close_snapshot_id'] ?? 0);
			if ($id > 0) {
				$ids[] = $id;
			}
		}
		return $ids;
	}

	/**
	 * @param array<int,true> $out
	 */
	private function protectChain(int $snapshotId, array &$out): void
	{
		$id = $snapshotId;
		while ($id > 0 && !isset($out[$id])) {
			$out[$id] = true;
			$prev = $this->prevSnapshotId($id);
			$id = $prev ?? 0;
		}
	}

	private function prevSnapshotId(int $snapshotId): ?int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('prev_snapshot_id')->from('dc_roster_snapshots')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($snapshotId, IQueryBuilder::PARAM_INT)));
		$row = $qb->executeQuery()->fetch();
		if ($row === false || $row['prev_snapshot_id'] === null) {
			return null;
		}
		$prev = (int) $row['prev_snapshot_id'];
		return $prev > 0 ? $prev : null;
	}
}
