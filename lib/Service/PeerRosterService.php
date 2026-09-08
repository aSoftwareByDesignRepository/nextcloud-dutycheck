<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Service;

use DateTimeImmutable;
use OCA\DutyCheck\Db\SchemaProbe;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Employee Team week — same-location published assignments only.
 * Privacy: no phones, preference bands, or absence reasons.
 */
final class PeerRosterService
{
	private const MAX_PAGE_SIZE = 50;

	/**
	 * Belonging TTL: last non-cancelled assignment at the location must fall on/after
	 * (today − LOOKBACK). Prevents indefinite peer visibility after leaving a Filiale.
	 */
	public const BELONGING_LOOKBACK_DAYS = 180;

	public function __construct(
		private readonly IDBConnection $db,
		private readonly CompanyService $companies,
		private readonly SelfServiceSettingsService $settings,
	) {
	}

	/**
	 * Locations the actor still “belongs” to for Team week (lookback-bound).
	 *
	 * @return list<array{id:int,name:string}>
	 */
	public function listBelongingLocations(string $actorUserId): array
	{
		$callerEmployeeId = $this->linkedEmployeeId($actorUserId);
		$companyId = $this->employeeCompanyId($callerEmployeeId);
		if (!$this->settings->isPeerVisibilityEnabled($companyId)) {
			throw new \InvalidArgumentException('PEER_VISIBILITY_DISABLED');
		}
		if (!SchemaProbe::tableExists($this->db, 'dc_assignments')
			|| !SchemaProbe::tableExists($this->db, 'dc_locations')) {
			return [];
		}
		$cutoff = $this->belongingCutoffYmd();
		$today = (new DateTimeImmutable('today'))->format('Y-m-d');
		$qb = $this->db->getQueryBuilder();
		$qb->select('l.id', 'l.name')
			->from('dc_assignments', 'a')
			->innerJoin('a', 'dc_locations', 'l', 'a.location_id = l.id')
			->where($qb->expr()->eq('a.employee_id', $qb->createNamedParameter($callerEmployeeId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->gte('a.duty_date', $qb->createNamedParameter($cutoff)))
			->andWhere($qb->expr()->lte('a.duty_date', $qb->createNamedParameter($today)))
			->andWhere($qb->expr()->eq('l.active', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
			->groupBy('l.id', 'l.name')
			->orderBy('l.name', 'ASC');
		if (SchemaProbe::hasColumn($this->db, 'dc_assignments', 'status')) {
			$qb->andWhere($qb->expr()->orX(
				$qb->expr()->neq('a.status', $qb->createNamedParameter('cancelled')),
				$qb->expr()->isNull('a.status'),
			));
		}
		if (SchemaProbe::hasColumn($this->db, 'dc_locations', 'company_id')) {
			$qb->andWhere($qb->expr()->eq('l.company_id', $qb->createNamedParameter($companyId, IQueryBuilder::PARAM_INT)));
		}
		$out = [];
		foreach ($qb->executeQuery()->fetchAll() as $row) {
			$out[] = [
				'id' => (int) $row['id'],
				'name' => (string) $row['name'],
			];
		}
		return $out;
	}

	/**
	 * @return array{
	 *   items: list<array<string,mixed>>,
	 *   page: int,
	 *   pageSize: int,
	 *   total: int,
	 *   weekStart: string,
	 *   weekEnd: string,
	 *   locationId: int,
	 *   locationName: string
	 * }
	 */
	public function listTeamWeek(
		string $actorUserId,
		int $locationId,
		string $weekStartYmd,
		int $page = 1,
		int $pageSize = 50,
	): array {
		$page = max(1, $page);
		$pageSize = max(1, min(self::MAX_PAGE_SIZE, $pageSize));

		if ($locationId < 1) {
			throw new \InvalidArgumentException('LOCATION_NOT_FOUND');
		}
		$weekStart = $this->parseIsoDate($weekStartYmd);
		$weekEnd = $weekStart->modify('+6 days');
		$weekStartIso = $weekStart->format('Y-m-d');
		$weekEndIso = $weekEnd->format('Y-m-d');

		$location = $this->loadLocation($locationId);
		$companyId = (int) ($location['company_id'] ?? CompanyService::DEFAULT_COMPANY_ID);

		// IDOR: other company → FORBIDDEN (or empty for unlinked employees after visibility check).
		try {
			$this->companies->assertCanAccessCompany($actorUserId, $companyId);
		} catch (\InvalidArgumentException $e) {
			if ($e->getMessage() === 'FORBIDDEN') {
				throw $e;
			}
			throw new \InvalidArgumentException('FORBIDDEN');
		}

		if (!$this->settings->isPeerVisibilityEnabled($companyId)) {
			throw new \InvalidArgumentException('PEER_VISIBILITY_DISABLED');
		}

		$callerEmployeeId = $this->linkedEmployeeId($actorUserId);
		$callerCompany = $this->employeeCompanyId($callerEmployeeId);
		if ($callerCompany !== $companyId) {
			throw new \InvalidArgumentException('FORBIDDEN');
		}

		// Employee must belong to this location (has worked / been assigned here).
		if (!$this->employeeBelongsToLocation($callerEmployeeId, $locationId)) {
			throw new \InvalidArgumentException('FORBIDDEN');
		}

		if (!SchemaProbe::tableExists($this->db, 'dc_assignments')
			|| !SchemaProbe::tableExists($this->db, 'dc_periods')) {
			return $this->emptyResult($locationId, (string) $location['name'], $weekStartIso, $weekEndIso, $page, $pageSize);
		}

		$countQb = $this->db->getQueryBuilder();
		$countQb->select($countQb->func()->count('*', 'cnt'))
			->from('dc_assignments', 'a')
			->innerJoin('a', 'dc_periods', 'p', 'a.period_id = p.id')
			->innerJoin('a', 'dc_employees', 'e', 'a.employee_id = e.id')
			->where($countQb->expr()->eq('a.location_id', $countQb->createNamedParameter($locationId, IQueryBuilder::PARAM_INT)))
			->andWhere($countQb->expr()->eq('p.status', $countQb->createNamedParameter('published')))
			->andWhere($countQb->expr()->gte('a.duty_date', $countQb->createNamedParameter($weekStartIso)))
			->andWhere($countQb->expr()->lte('a.duty_date', $countQb->createNamedParameter($weekEndIso)))
			->andWhere($countQb->expr()->eq('e.active', $countQb->createNamedParameter(1, IQueryBuilder::PARAM_INT)));
		if (SchemaProbe::hasColumn($this->db, 'dc_assignments', 'status')) {
			$countQb->andWhere($countQb->expr()->orX(
				$countQb->expr()->neq('a.status', $countQb->createNamedParameter('cancelled')),
				$countQb->expr()->isNull('a.status'),
			));
		}
		if (SchemaProbe::hasColumn($this->db, 'dc_employees', 'company_id')) {
			$countQb->andWhere($countQb->expr()->eq('e.company_id', $countQb->createNamedParameter($companyId, IQueryBuilder::PARAM_INT)));
		}
		$total = (int) $countQb->executeQuery()->fetchOne();

		$listQb = $this->db->getQueryBuilder();
		$listQb->select(
			'a.id',
			'a.employee_id',
			'a.duty_date',
			'a.start_time',
			'a.end_time',
			'a.break_minutes',
			'e.display_name',
		)
			->from('dc_assignments', 'a')
			->innerJoin('a', 'dc_periods', 'p', 'a.period_id = p.id')
			->innerJoin('a', 'dc_employees', 'e', 'a.employee_id = e.id')
			->where($listQb->expr()->eq('a.location_id', $listQb->createNamedParameter($locationId, IQueryBuilder::PARAM_INT)))
			->andWhere($listQb->expr()->eq('p.status', $listQb->createNamedParameter('published')))
			->andWhere($listQb->expr()->gte('a.duty_date', $listQb->createNamedParameter($weekStartIso)))
			->andWhere($listQb->expr()->lte('a.duty_date', $listQb->createNamedParameter($weekEndIso)))
			->andWhere($listQb->expr()->eq('e.active', $listQb->createNamedParameter(1, IQueryBuilder::PARAM_INT)));
		if (SchemaProbe::hasColumn($this->db, 'dc_assignments', 'status')) {
			$listQb->andWhere($listQb->expr()->orX(
				$listQb->expr()->neq('a.status', $listQb->createNamedParameter('cancelled')),
				$listQb->expr()->isNull('a.status'),
			));
		}
		if (SchemaProbe::hasColumn($this->db, 'dc_employees', 'company_id')) {
			$listQb->andWhere($listQb->expr()->eq('e.company_id', $listQb->createNamedParameter($companyId, IQueryBuilder::PARAM_INT)));
		}
		$listQb->orderBy('a.duty_date', 'ASC')
			->addOrderBy('a.start_time', 'ASC')
			->addOrderBy('e.display_name', 'ASC')
			->addOrderBy('a.id', 'ASC')
			->setFirstResult(($page - 1) * $pageSize)
			->setMaxResults($pageSize);

		$templates = $this->templatesByTimeForLocation($locationId, $companyId);
		$items = [];
		foreach ($listQb->executeQuery()->fetchAll() as $row) {
			$start = (string) $row['start_time'];
			$end = (string) $row['end_time'];
			$key = $start . '|' . $end;
			$items[] = [
				'assignmentId' => (int) $row['id'],
				'employeeId' => (int) $row['employee_id'],
				'displayName' => (string) $row['display_name'],
				'dutyDate' => (string) $row['duty_date'],
				'startTime' => $start,
				'endTime' => $end,
				'breakMinutes' => (int) $row['break_minutes'],
				'locationId' => $locationId,
				'locationName' => (string) $location['name'],
				'templateName' => $templates[$key] ?? null,
			];
		}

		return [
			'items' => $items,
			'page' => $page,
			'pageSize' => $pageSize,
			'total' => $total,
			'weekStart' => $weekStartIso,
			'weekEnd' => $weekEndIso,
			'locationId' => $locationId,
			'locationName' => (string) $location['name'],
		];
	}

	/**
	 * @return array{
	 *   items: list<array<string,mixed>>,
	 *   page: int,
	 *   pageSize: int,
	 *   total: int,
	 *   weekStart: string,
	 *   weekEnd: string,
	 *   locationId: int,
	 *   locationName: string
	 * }
	 */
	private function emptyResult(
		int $locationId,
		string $locationName,
		string $weekStart,
		string $weekEnd,
		int $page,
		int $pageSize,
	): array {
		return [
			'items' => [],
			'page' => $page,
			'pageSize' => $pageSize,
			'total' => 0,
			'weekStart' => $weekStart,
			'weekEnd' => $weekEnd,
			'locationId' => $locationId,
			'locationName' => $locationName,
		];
	}

	/**
	 * Belonging = at least one non-cancelled assignment at this location with
	 * duty_date in [today − {@see BELONGING_LOOKBACK_DAYS}, today] (future dates do not count).
	 */
	private function employeeBelongsToLocation(int $employeeId, int $locationId): bool
	{
		if (!SchemaProbe::tableExists($this->db, 'dc_assignments')) {
			return false;
		}
		$today = (new DateTimeImmutable('today'))->format('Y-m-d');
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from('dc_assignments')
			->where($qb->expr()->eq('employee_id', $qb->createNamedParameter($employeeId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('location_id', $qb->createNamedParameter($locationId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->gte('duty_date', $qb->createNamedParameter($this->belongingCutoffYmd())))
			->andWhere($qb->expr()->lte('duty_date', $qb->createNamedParameter($today)))
			->setMaxResults(1);
		if (SchemaProbe::hasColumn($this->db, 'dc_assignments', 'status')) {
			$qb->andWhere($qb->expr()->orX(
				$qb->expr()->neq('status', $qb->createNamedParameter('cancelled')),
				$qb->expr()->isNull('status'),
			));
		}
		return $qb->executeQuery()->fetch() !== false;
	}

	private function belongingCutoffYmd(): string
	{
		return (new DateTimeImmutable('today'))
			->modify('-' . self::BELONGING_LOOKBACK_DAYS . ' days')
			->format('Y-m-d');
	}

	/**
	 * @return array<string,string> keyed by "start|end" → template name
	 */
	private function templatesByTimeForLocation(int $locationId, int $companyId): array
	{
		if (!SchemaProbe::tableExists($this->db, 'dc_shift_templates')) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('name', 'start_time', 'end_time', 'location_id')
			->from('dc_shift_templates')
			->where($qb->expr()->eq('active', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->orX(
				$qb->expr()->eq('location_id', $qb->createNamedParameter($locationId, IQueryBuilder::PARAM_INT)),
				$qb->expr()->isNull('location_id'),
			));
		if (SchemaProbe::hasColumn($this->db, 'dc_shift_templates', 'company_id')) {
			$qb->andWhere($qb->expr()->eq('company_id', $qb->createNamedParameter($companyId, IQueryBuilder::PARAM_INT)));
		}
		$out = [];
		foreach ($qb->executeQuery()->fetchAll() as $row) {
			$key = (string) $row['start_time'] . '|' . (string) $row['end_time'];
			// Prefer location-specific template name over global.
			if (!isset($out[$key]) || $row['location_id'] !== null) {
				$out[$key] = (string) $row['name'];
			}
		}
		return $out;
	}

	/**
	 * @return array{id:int,name:string,company_id?:int|null}
	 */
	private function loadLocation(int $locationId): array
	{
		if (!SchemaProbe::tableExists($this->db, 'dc_locations')) {
			throw new \InvalidArgumentException('LOCATION_NOT_FOUND');
		}
		$qb = $this->db->getQueryBuilder();
		$select = ['id', 'name'];
		if (SchemaProbe::hasColumn($this->db, 'dc_locations', 'company_id')) {
			$select[] = 'company_id';
		}
		$qb->select(...$select)->from('dc_locations')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($locationId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('active', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)));
		$row = $qb->executeQuery()->fetch();
		if ($row === false) {
			throw new \InvalidArgumentException('LOCATION_NOT_FOUND');
		}
		return $row;
	}

	private function parseIsoDate(string $ymd): DateTimeImmutable
	{
		$ymd = trim($ymd);
		if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $ymd) !== 1) {
			throw new \InvalidArgumentException('WEEK_START_INVALID');
		}
		$dt = DateTimeImmutable::createFromFormat('!Y-m-d', $ymd);
		if ($dt === false) {
			throw new \InvalidArgumentException('WEEK_START_INVALID');
		}
		return $dt;
	}

	private function linkedEmployeeId(string $userId): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from('dc_employees')
			->where($qb->expr()->eq('linked_user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('active', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
			->setMaxResults(1);
		$row = $qb->executeQuery()->fetch();
		if ($row === false) {
			throw new \InvalidArgumentException('EMPLOYEE_LINK_NOT_FOUND');
		}
		return (int) $row['id'];
	}

	private function employeeCompanyId(int $employeeId): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('company_id')->from('dc_employees')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($employeeId, IQueryBuilder::PARAM_INT)));
		$row = $qb->executeQuery()->fetch();
		if ($row === false) {
			throw new \InvalidArgumentException('EMPLOYEE_NOT_FOUND');
		}
		return (int) ($row['company_id'] ?? CompanyService::DEFAULT_COMPANY_ID);
	}
}
