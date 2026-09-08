<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Service;

use DateTimeImmutable;
use OCA\DutyCheck\Db\SchemaProbe;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Planner/admin “Wer ist wo?” board — one location × one day.
 * Employees receive FORBIDDEN (not empty 200). No PII beyond shift identity.
 */
final class TodayBoardService
{
	private const MAX_PAGE_SIZE = 200;

	public function __construct(
		private readonly IDBConnection $db,
		private readonly CompanyService $companies,
		private readonly AccessControlService $access,
		private readonly PlannerLocationScopeService $plannerScope,
		private readonly SelfServiceSettingsService $settings,
	) {
	}

	/**
	 * @return array{
	 *   locationId: int,
	 *   locationName: string,
	 *   date: string,
	 *   shifts: list<array<string,mixed>>,
	 *   gaps: list<array<string,mixed>>,
	 *   page: int,
	 *   pageSize: int,
	 *   total: int
	 * }
	 */
	public function getBoard(
		string $actorUserId,
		int $locationId,
		string $dateYmd,
		int $page = 1,
		int $pageSize = 200,
	): array {
		if (!$this->access->isPlannerOrAdmin($actorUserId)) {
			throw new \InvalidArgumentException('FORBIDDEN');
		}
		if ($locationId < 1) {
			throw new \InvalidArgumentException('LOCATION_NOT_FOUND');
		}
		$page = max(1, $page);
		$pageSize = max(1, min(self::MAX_PAGE_SIZE, $pageSize));
		$date = $this->parseIsoDate($dateYmd);
		$dateIso = $date->format('Y-m-d');

		$location = $this->loadLocation($locationId);
		$companyId = (int) ($location['company_id'] ?? CompanyService::DEFAULT_COMPANY_ID);
		$this->companies->assertCanAccessCompany($actorUserId, $companyId);
		$this->plannerScope->assertCanPlanLocation($actorUserId, $locationId);

		$cfg = $this->settings->getForCompany($companyId);
		if (!(bool) ($cfg['today_board_enabled'] ?? false)) {
			throw new \InvalidArgumentException('TODAY_BOARD_DISABLED');
		}

		$shifts = [];
		$total = 0;
		if (SchemaProbe::tableExists($this->db, 'dc_assignments')
			&& SchemaProbe::tableExists($this->db, 'dc_periods')) {
			$total = $this->countShifts($locationId, $companyId, $dateIso);
			$shifts = $this->fetchShifts($locationId, $companyId, $dateIso, $page, $pageSize, (string) $location['name']);
		}

		$gaps = [];
		if ($page === 1) {
			$gaps = $this->computeGaps($locationId, $companyId, $dateIso);
		}

		return [
			'locationId' => $locationId,
			'locationName' => (string) $location['name'],
			'date' => $dateIso,
			'shifts' => $shifts,
			'gaps' => $gaps,
			'page' => $page,
			'pageSize' => $pageSize,
			'total' => $total,
		];
	}

	/**
	 * @return list<array<string,mixed>>
	 */
	private function fetchShifts(
		int $locationId,
		int $companyId,
		string $dateIso,
		int $page,
		int $pageSize,
		string $locationName,
	): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select(
			'a.id',
			'a.employee_id',
			'a.start_time',
			'a.end_time',
			'a.break_minutes',
			'e.display_name',
			'p.status AS period_status',
		)
			->from('dc_assignments', 'a')
			->innerJoin('a', 'dc_periods', 'p', 'a.period_id = p.id')
			->innerJoin('a', 'dc_employees', 'e', 'a.employee_id = e.id')
			->where($qb->expr()->eq('a.location_id', $qb->createNamedParameter($locationId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('a.duty_date', $qb->createNamedParameter($dateIso)))
			->andWhere($qb->expr()->in('p.status', $qb->createNamedParameter(['published', 'open'], IQueryBuilder::PARAM_STR_ARRAY)))
			->andWhere($qb->expr()->eq('e.active', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)));
		if (SchemaProbe::hasColumn($this->db, 'dc_assignments', 'status')) {
			$qb->andWhere($qb->expr()->orX(
				$qb->expr()->neq('a.status', $qb->createNamedParameter('cancelled')),
				$qb->expr()->isNull('a.status'),
			));
		}
		if (SchemaProbe::hasColumn($this->db, 'dc_employees', 'company_id')) {
			$qb->andWhere($qb->expr()->eq('e.company_id', $qb->createNamedParameter($companyId, IQueryBuilder::PARAM_INT)));
		}
		$qb->orderBy('a.start_time', 'ASC')
			->addOrderBy('e.display_name', 'ASC')
			->addOrderBy('a.id', 'ASC')
			->setFirstResult(($page - 1) * $pageSize)
			->setMaxResults($pageSize);

		$templates = $this->templatesForLocation($locationId, $companyId);
		$out = [];
		foreach ($qb->executeQuery()->fetchAll() as $row) {
			$start = (string) $row['start_time'];
			$end = (string) $row['end_time'];
			$matched = $this->matchTemplate($templates, $start, $end);
			$out[] = [
				'assignmentId' => (int) $row['id'],
				'employeeId' => (int) $row['employee_id'],
				'displayName' => (string) $row['display_name'],
				'startTime' => $start,
				'endTime' => $end,
				'breakMinutes' => (int) $row['break_minutes'],
				'periodStatus' => (string) ($row['period_status'] ?? 'published'),
				'locationId' => $locationId,
				'locationName' => $locationName,
				'templateId' => $matched['id'] ?? null,
				'templateName' => $matched['name'] ?? null,
			];
		}
		return $out;
	}

	private function countShifts(int $locationId, int $companyId, string $dateIso): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'cnt'))
			->from('dc_assignments', 'a')
			->innerJoin('a', 'dc_periods', 'p', 'a.period_id = p.id')
			->innerJoin('a', 'dc_employees', 'e', 'a.employee_id = e.id')
			->where($qb->expr()->eq('a.location_id', $qb->createNamedParameter($locationId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('a.duty_date', $qb->createNamedParameter($dateIso)))
			->andWhere($qb->expr()->in('p.status', $qb->createNamedParameter(['published', 'open'], IQueryBuilder::PARAM_STR_ARRAY)))
			->andWhere($qb->expr()->eq('e.active', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)));
		if (SchemaProbe::hasColumn($this->db, 'dc_assignments', 'status')) {
			$qb->andWhere($qb->expr()->orX(
				$qb->expr()->neq('a.status', $qb->createNamedParameter('cancelled')),
				$qb->expr()->isNull('a.status'),
			));
		}
		if (SchemaProbe::hasColumn($this->db, 'dc_employees', 'company_id')) {
			$qb->andWhere($qb->expr()->eq('e.company_id', $qb->createNamedParameter($companyId, IQueryBuilder::PARAM_INT)));
		}
		return (int) $qb->executeQuery()->fetchOne();
	}

	/**
	 * Gaps vs template min_headcount for this location/day when available.
	 *
	 * @return list<array<string,mixed>>
	 */
	private function computeGaps(int $locationId, int $companyId, string $dateIso): array
	{
		if (!SchemaProbe::tableExists($this->db, 'dc_shift_templates')) {
			return [];
		}
		if (!SchemaProbe::hasColumn($this->db, 'dc_shift_templates', 'min_headcount')) {
			return [];
		}

		// Use all-day counts per template time bucket (not just current page).
		$countsByKey = $this->assignmentCountsByTime($locationId, $companyId, $dateIso);
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'name', 'start_time', 'end_time', 'min_headcount', 'location_id')
			->from('dc_shift_templates')
			->where($qb->expr()->eq('active', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->gt('min_headcount', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->orX(
				$qb->expr()->eq('location_id', $qb->createNamedParameter($locationId, IQueryBuilder::PARAM_INT)),
				$qb->expr()->isNull('location_id'),
			));
		if (SchemaProbe::hasColumn($this->db, 'dc_shift_templates', 'company_id')) {
			$qb->andWhere($qb->expr()->eq('company_id', $qb->createNamedParameter($companyId, IQueryBuilder::PARAM_INT)));
		}

		$gaps = [];
		foreach ($qb->executeQuery()->fetchAll() as $tpl) {
			$tplLoc = $tpl['location_id'] !== null ? (int) $tpl['location_id'] : null;
			if ($tplLoc !== null && $tplLoc !== $locationId) {
				continue;
			}
			$min = (int) $tpl['min_headcount'];
			$key = (string) $tpl['start_time'] . '|' . (string) $tpl['end_time'];
			$assigned = $countsByKey[$key] ?? 0;
			if ($assigned >= $min) {
				continue;
			}
			$gaps[] = [
				'templateId' => (int) $tpl['id'],
				'templateName' => (string) $tpl['name'],
				'startTime' => (string) $tpl['start_time'],
				'endTime' => (string) $tpl['end_time'],
				'minHeadcount' => $min,
				'assignedCount' => $assigned,
				'shortfall' => $min - $assigned,
				'dutyDate' => $dateIso,
				'locationId' => $locationId,
			];
		}
		return $gaps;
	}

	/**
	 * @return array<string,int>
	 */
	private function assignmentCountsByTime(int $locationId, int $companyId, string $dateIso): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('a.start_time', 'a.end_time')
			->from('dc_assignments', 'a')
			->innerJoin('a', 'dc_periods', 'p', 'a.period_id = p.id')
			->where($qb->expr()->eq('a.location_id', $qb->createNamedParameter($locationId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('a.duty_date', $qb->createNamedParameter($dateIso)))
			->andWhere($qb->expr()->in('p.status', $qb->createNamedParameter(['published', 'open'], IQueryBuilder::PARAM_STR_ARRAY)));
		if (SchemaProbe::hasColumn($this->db, 'dc_assignments', 'status')) {
			$qb->andWhere($qb->expr()->orX(
				$qb->expr()->neq('a.status', $qb->createNamedParameter('cancelled')),
				$qb->expr()->isNull('a.status'),
			));
		}
		if (SchemaProbe::hasColumn($this->db, 'dc_periods', 'company_id')) {
			$qb->andWhere($qb->expr()->eq('p.company_id', $qb->createNamedParameter($companyId, IQueryBuilder::PARAM_INT)));
		}
		$counts = [];
		foreach ($qb->executeQuery()->fetchAll() as $row) {
			$key = (string) $row['start_time'] . '|' . (string) $row['end_time'];
			$counts[$key] = ($counts[$key] ?? 0) + 1;
		}
		return $counts;
	}

	/**
	 * @return list<array{id:int,name:string,start:string,end:string,locationId:?int}>
	 */
	private function templatesForLocation(int $locationId, int $companyId): array
	{
		if (!SchemaProbe::tableExists($this->db, 'dc_shift_templates')) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'name', 'start_time', 'end_time', 'location_id')
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
			$out[] = [
				'id' => (int) $row['id'],
				'name' => (string) $row['name'],
				'start' => (string) $row['start_time'],
				'end' => (string) $row['end_time'],
				'locationId' => $row['location_id'] !== null ? (int) $row['location_id'] : null,
			];
		}
		return $out;
	}

	/**
	 * @param list<array{id:int,name:string,start:string,end:string,locationId:?int}> $templates
	 * @return array{id:int,name:string}|null
	 */
	private function matchTemplate(array $templates, string $start, string $end): ?array
	{
		$best = null;
		foreach ($templates as $tpl) {
			if ($tpl['start'] !== $start || $tpl['end'] !== $end) {
				continue;
			}
			if ($best === null || ($tpl['locationId'] !== null && ($best['locationId'] ?? null) === null)) {
				$best = $tpl;
			}
		}
		if ($best === null) {
			return null;
		}
		return ['id' => $best['id'], 'name' => $best['name']];
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
			throw new \InvalidArgumentException('DATE_INVALID');
		}
		$dt = DateTimeImmutable::createFromFormat('!Y-m-d', $ymd);
		if ($dt === false) {
			throw new \InvalidArgumentException('DATE_INVALID');
		}
		return $dt;
	}
}
