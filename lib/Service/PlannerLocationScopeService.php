<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Optional per-location planner scope (Wave B3).
 * Empty scope = all locations (legacy / global planners).
 */
class PlannerLocationScopeService
{
	public function __construct(
		private readonly IDBConnection $db,
		private readonly AccessControlService $access,
	) {
	}

	/**
	 * @return list<int> empty = unrestricted
	 */
	public function locationIdsFor(string $userId): array
	{
		if ($this->access->isAppAdmin($userId)) {
			return [];
		}
		if (!$this->db->tableExists('dc_planner_locs')) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('location_id')->from('dc_planner_locs')
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		$rows = $qb->executeQuery()->fetchAll();
		return array_values(array_map(static fn (array $r): int => (int) $r['location_id'], $rows));
	}

	public function assertCanPlanLocation(string $userId, int $locationId): void
	{
		$allowed = $this->locationIdsFor($userId);
		if ($allowed === []) {
			return;
		}
		if (!in_array($locationId, $allowed, true)) {
			throw new \InvalidArgumentException('FORBIDDEN');
		}
	}

	/**
	 * Replace scope for a planner. Empty list clears restrictions (global).
	 *
	 * @param list<int> $locationIds
	 */
	public function setScope(string $userId, array $locationIds): void
	{
		if (!$this->db->tableExists('dc_planner_locs')) {
			throw new \InvalidArgumentException('SCHEMA_NOT_READY');
		}
		$qb = $this->db->getQueryBuilder();
		$qb->delete('dc_planner_locs')
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->executeStatement();
		foreach (array_unique(array_map('intval', $locationIds)) as $locationId) {
			if ($locationId <= 0) {
				continue;
			}
			$ins = $this->db->getQueryBuilder();
			$ins->insert('dc_planner_locs')->values([
				'user_id' => $ins->createNamedParameter($userId),
				'location_id' => $ins->createNamedParameter($locationId, IQueryBuilder::PARAM_INT),
			])->executeStatement();
		}
	}
}
