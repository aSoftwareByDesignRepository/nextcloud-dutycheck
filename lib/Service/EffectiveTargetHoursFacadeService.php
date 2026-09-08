<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Service;

use DateTimeImmutable;
use OCA\DutyCheck\Db\SchemaProbe;
use OCA\DutyCheck\Service\Contract\EffectiveTargetHoursFacade;
use OCA\DutyCheck\Service\Contract\TargetHoursDayDto;
use OCA\DutyCheck\Service\Contract\TargetHoursWeekDto;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Throwable;

/**
 * Read-only Soll resolution (Duty → AZC in-process facade).
 *
 * Never throws 500 for missing data — returns null so AZC can fall back to static model.
 * Company-bound: only the linked employee's own company pattern/roster rows (Argus).
 */
class EffectiveTargetHoursFacadeService implements EffectiveTargetHoursFacade
{
	/** @var array<string, TargetHoursWeekDto|null> */
	private array $weekCache = [];

	/** @var array<string, TargetHoursDayDto|null> */
	private array $dayCache = [];

	/** @var array<string, bool> */
	private array $rotationEnabledCache = [];

	public function __construct(
		private readonly IDBConnection $db,
		private readonly SelfServiceSettingsService $settings,
		private readonly RotationAnchorService $anchors,
	) {
	}

	public function getFacadeVersion(): int
	{
		return 1;
	}

	public function isRotationEnabledForOrg(?string $ncUserId = null): bool
	{
		if ($ncUserId === null || trim($ncUserId) === '') {
			return false;
		}
		if (array_key_exists($ncUserId, $this->rotationEnabledCache)) {
			return $this->rotationEnabledCache[$ncUserId];
		}
		try {
			$employee = $this->resolveEmployee($ncUserId);
			if ($employee === null) {
				return $this->rotationEnabledCache[$ncUserId] = false;
			}
			$companyId = (int) ($employee['company_id'] ?? CompanyService::DEFAULT_COMPANY_ID);
			return $this->rotationEnabledCache[$ncUserId] = $this->settings->isRotationEnabled($companyId);
		} catch (Throwable) {
			return $this->rotationEnabledCache[$ncUserId] = false;
		}
	}

	public function isSollFromDutyEnabledForUser(string $ncUserId): bool
	{
		if (!$this->isRotationEnabledForOrg($ncUserId)) {
			return false;
		}
		try {
			$employee = $this->resolveEmployee($ncUserId);
			if ($employee === null) {
				return false;
			}
			$companyId = (int) ($employee['company_id'] ?? CompanyService::DEFAULT_COMPANY_ID);
			$cfg = $this->settings->getForCompany($companyId);
			return (bool) ($cfg['soll_from_duty'] ?? false);
		} catch (Throwable) {
			return false;
		}
	}

	public function getWeekTarget(string $ncUserId, DateTimeImmutable $isoWeekStart): ?TargetHoursWeekDto
	{
		if (!$this->isSollFromDutyEnabledForUser($ncUserId)) {
			return null;
		}
		$monday = $this->anchors->isoMonday($isoWeekStart);
		$cacheKey = $ncUserId . '|' . $monday->format('Y-m-d');
		if (array_key_exists($cacheKey, $this->weekCache)) {
			return $this->weekCache[$cacheKey];
		}
		try {
			$dto = $this->resolveWeekTarget($ncUserId, $monday);
		} catch (Throwable) {
			$dto = null;
		}
		return $this->weekCache[$cacheKey] = $dto;
	}

	public function getDayTarget(string $ncUserId, DateTimeImmutable $date): ?TargetHoursDayDto
	{
		if (!$this->isSollFromDutyEnabledForUser($ncUserId)) {
			return null;
		}
		$day = $date->setTime(0, 0, 0);
		$cacheKey = $ncUserId . '|d|' . $day->format('Y-m-d');
		if (array_key_exists($cacheKey, $this->dayCache)) {
			return $this->dayCache[$cacheKey];
		}
		try {
			$dto = $this->resolveDayTarget($ncUserId, $day);
		} catch (Throwable) {
			$dto = null;
		}
		return $this->dayCache[$cacheKey] = $dto;
	}

	public function getWeekTargetMinutes(string $ncUserId, DateTimeImmutable $isoWeekStart): ?int
	{
		// AZC Soll path — require Duty soll_from_duty (G2 still gated by AZC provider).
		if (!$this->isSollFromDutyEnabledForUser($ncUserId)) {
			return null;
		}
		$week = $this->getWeekTarget($ncUserId, $isoWeekStart);
		return $week?->requiredNetMinutes;
	}

	private function resolveWeekTarget(string $ncUserId, DateTimeImmutable $monday): ?TargetHoursWeekDto
	{
		if (!$this->isRotationEnabledForOrg($ncUserId)) {
			return null;
		}
		$employee = $this->resolveEmployee($ncUserId);
		if ($employee === null) {
			return null;
		}
		$employeeId = (int) $employee['id'];
		$companyId = (int) ($employee['company_id'] ?? CompanyService::DEFAULT_COMPANY_ID);
		$weekEnd = $monday->modify('+6 days');
		$ctx = $this->resolvePatternContext($employeeId, $companyId, $monday);
		if ($ctx === null) {
			return null;
		}

		$published = $this->sumAssignmentMinutesForWeek(
			$employeeId,
			$companyId,
			$monday,
			$weekEnd,
			['published', 'closed'],
		);
		if ($published !== null) {
			return new TargetHoursWeekDto(
				$ncUserId,
				$monday->format('Y-m-d'),
				$published,
				$ctx['weekIndex'],
				$ctx['weekLabel'],
				'published_roster',
				$ctx['patternId'],
				$ctx['patternName'],
			);
		}

		$open = $this->sumAssignmentMinutesForWeek(
			$employeeId,
			$companyId,
			$monday,
			$weekEnd,
			['open'],
		);
		if ($open !== null) {
			return new TargetHoursWeekDto(
				$ncUserId,
				$monday->format('Y-m-d'),
				$open,
				$ctx['weekIndex'],
				$ctx['weekLabel'],
				'open_roster',
				$ctx['patternId'],
				$ctx['patternName'],
			);
		}

		$patternMinutes = $this->sumPatternWeekMinutes($ctx['patternId'], $ctx['weekIndex']);
		if ($patternMinutes === null) {
			return null;
		}

		return new TargetHoursWeekDto(
			$ncUserId,
			$monday->format('Y-m-d'),
			$patternMinutes,
			$ctx['weekIndex'],
			$ctx['weekLabel'],
			'rotation_pattern',
			$ctx['patternId'],
			$ctx['patternName'],
		);
	}

	private function resolveDayTarget(string $ncUserId, DateTimeImmutable $day): ?TargetHoursDayDto
	{
		if (!$this->isRotationEnabledForOrg($ncUserId)) {
			return null;
		}
		$employee = $this->resolveEmployee($ncUserId);
		if ($employee === null) {
			return null;
		}
		$employeeId = (int) $employee['id'];
		$companyId = (int) ($employee['company_id'] ?? CompanyService::DEFAULT_COMPANY_ID);
		$ctx = $this->resolvePatternContext($employeeId, $companyId, $day);
		if ($ctx === null) {
			return null;
		}

		$dateStr = $day->format('Y-m-d');
		$published = $this->sumAssignmentMinutesForDay($employeeId, $companyId, $dateStr, ['published', 'closed']);
		if ($published !== null) {
			return new TargetHoursDayDto(
				$ncUserId,
				$dateStr,
				$published,
				$published > 0,
				$ctx['weekIndex'],
				$ctx['weekLabel'],
				'published_roster',
			);
		}

		$open = $this->sumAssignmentMinutesForDay($employeeId, $companyId, $dateStr, ['open']);
		if ($open !== null) {
			return new TargetHoursDayDto(
				$ncUserId,
				$dateStr,
				$open,
				$open > 0,
				$ctx['weekIndex'],
				$ctx['weekLabel'],
				'open_roster',
			);
		}

		$dow = (int) $day->format('N');
		$dayRow = $this->patternDayRow($ctx['patternId'], $ctx['weekIndex'], $dow);
		if ($dayRow === null) {
			return null;
		}
		$net = (int) ($dayRow['net_minutes'] ?? 0);
		$isWorking = (int) ($dayRow['is_working'] ?? 0) === 1 || $net > 0;

		return new TargetHoursDayDto(
			$ncUserId,
			$dateStr,
			max(0, $net),
			$isWorking,
			$ctx['weekIndex'],
			$ctx['weekLabel'],
			'rotation_pattern',
		);
	}

	/**
	 * @return array{id:int,company_id:int}|null
	 */
	private function resolveEmployee(string $ncUserId): ?array
	{
		if (!SchemaProbe::tableExists($this->db, 'dc_employees')) {
			return null;
		}
		$qb = $this->db->getQueryBuilder();
		$select = ['id'];
		if (SchemaProbe::hasColumn($this->db, 'dc_employees', 'company_id')) {
			$select[] = 'company_id';
		}
		$qb->select(...$select)
			->from('dc_employees')
			->where($qb->expr()->eq('linked_user_id', $qb->createNamedParameter($ncUserId)))
			->andWhere($qb->expr()->eq('active', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
			->setMaxResults(1);
		$row = $qb->executeQuery()->fetch();
		if ($row === false) {
			return null;
		}
		return [
			'id' => (int) $row['id'],
			'company_id' => (int) ($row['company_id'] ?? CompanyService::DEFAULT_COMPANY_ID),
		];
	}

	/**
	 * @return array{patternId:int,patternName:?string,weekIndex:int,weekLabel:string,cycleWeeks:int}|null
	 */
	private function resolvePatternContext(int $employeeId, int $companyId, DateTimeImmutable $date): ?array
	{
		if (!SchemaProbe::tableExists($this->db, 'dc_emp_rot_assign')
			|| !SchemaProbe::tableExists($this->db, 'dc_rotation_patterns')) {
			return null;
		}
		$dateStr = $date->format('Y-m-d');
		$qb = $this->db->getQueryBuilder();
		$qb->select('a.pattern_id', 'p.name', 'p.cycle_weeks', 'p.anchor_type', 'p.anchor_iso_week_index', 'p.anchor_date', 'p.anchor_effective_from', 'p.company_id', 'p.is_active')
			->from('dc_emp_rot_assign', 'a')
			->innerJoin('a', 'dc_rotation_patterns', 'p', 'a.pattern_id = p.id')
			->where($qb->expr()->eq('a.employee_id', $qb->createNamedParameter($employeeId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('a.company_id', $qb->createNamedParameter($companyId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->lte('a.valid_from', $qb->createNamedParameter($dateStr)))
			->andWhere($qb->expr()->orX(
				$qb->expr()->isNull('a.valid_to'),
				$qb->expr()->gte('a.valid_to', $qb->createNamedParameter($dateStr)),
			))
			->andWhere($qb->expr()->eq('p.is_active', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('p.company_id', $qb->createNamedParameter($companyId, IQueryBuilder::PARAM_INT)))
			->orderBy('a.valid_from', 'DESC')
			->setMaxResults(1);
		$row = $qb->executeQuery()->fetch();
		if ($row === false) {
			return null;
		}
		$pattern = [
			'cycle_weeks' => (int) ($row['cycle_weeks'] ?? 2),
			'anchor_type' => (string) ($row['anchor_type'] ?? RotationAnchorService::ANCHOR_ISO_WEEK_PARITY),
			'anchor_iso_week_index' => $row['anchor_iso_week_index'] !== null ? (int) $row['anchor_iso_week_index'] : null,
			'anchor_date' => $row['anchor_date'] !== null ? (string) $row['anchor_date'] : null,
			'anchor_effective_from' => $row['anchor_effective_from'] !== null ? (string) $row['anchor_effective_from'] : null,
		];
		$weekIndex = $this->anchors->weekIndexForDate($pattern, $date);
		$cycle = max(1, (int) $pattern['cycle_weeks']);
		$isoWeek = (int) $date->format('W');

		return [
			'patternId' => (int) $row['pattern_id'],
			'patternName' => isset($row['name']) ? (string) $row['name'] : null,
			'weekIndex' => $weekIndex,
			'weekLabel' => $this->anchors->weekLabel($weekIndex, $cycle, $isoWeek),
			'cycleWeeks' => $cycle,
		];
	}

	/**
	 * @param list<string> $periodStatuses
	 */
	private function sumAssignmentMinutesForWeek(
		int $employeeId,
		int $companyId,
		DateTimeImmutable $monday,
		DateTimeImmutable $sunday,
		array $periodStatuses,
	): ?int {
		if (!SchemaProbe::tableExists($this->db, 'dc_assignments')
			|| !SchemaProbe::tableExists($this->db, 'dc_periods')) {
			return null;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('a.start_time', 'a.end_time', 'a.break_minutes')
			->from('dc_assignments', 'a')
			->innerJoin('a', 'dc_periods', 'p', 'a.period_id = p.id')
			->where($qb->expr()->eq('a.employee_id', $qb->createNamedParameter($employeeId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->gte('a.duty_date', $qb->createNamedParameter($monday->format('Y-m-d'))))
			->andWhere($qb->expr()->lte('a.duty_date', $qb->createNamedParameter($sunday->format('Y-m-d'))))
			->andWhere($qb->expr()->in(
				'p.status',
				$qb->createNamedParameter($periodStatuses, IQueryBuilder::PARAM_STR_ARRAY),
			));
		if (SchemaProbe::hasColumn($this->db, 'dc_periods', 'company_id')) {
			$qb->andWhere($qb->expr()->eq('p.company_id', $qb->createNamedParameter($companyId, IQueryBuilder::PARAM_INT)));
		}
		if (SchemaProbe::hasColumn($this->db, 'dc_assignments', 'status')) {
			$qb->andWhere($qb->expr()->orX(
				$qb->expr()->neq('a.status', $qb->createNamedParameter('cancelled')),
				$qb->expr()->isNull('a.status'),
			));
		}
		$rows = $qb->executeQuery()->fetchAll();
		if ($rows === []) {
			return null;
		}
		$total = 0;
		foreach ($rows as $row) {
			$total += $this->effectiveMinutes(
				(string) $row['start_time'],
				(string) $row['end_time'],
				(int) $row['break_minutes'],
			);
		}
		return max(0, $total);
	}

	/**
	 * @param list<string> $periodStatuses
	 */
	private function sumAssignmentMinutesForDay(
		int $employeeId,
		int $companyId,
		string $dateStr,
		array $periodStatuses,
	): ?int {
		if (!SchemaProbe::tableExists($this->db, 'dc_assignments')
			|| !SchemaProbe::tableExists($this->db, 'dc_periods')) {
			return null;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('a.start_time', 'a.end_time', 'a.break_minutes')
			->from('dc_assignments', 'a')
			->innerJoin('a', 'dc_periods', 'p', 'a.period_id = p.id')
			->where($qb->expr()->eq('a.employee_id', $qb->createNamedParameter($employeeId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('a.duty_date', $qb->createNamedParameter($dateStr)))
			->andWhere($qb->expr()->in(
				'p.status',
				$qb->createNamedParameter($periodStatuses, IQueryBuilder::PARAM_STR_ARRAY),
			));
		if (SchemaProbe::hasColumn($this->db, 'dc_periods', 'company_id')) {
			$qb->andWhere($qb->expr()->eq('p.company_id', $qb->createNamedParameter($companyId, IQueryBuilder::PARAM_INT)));
		}
		if (SchemaProbe::hasColumn($this->db, 'dc_assignments', 'status')) {
			$qb->andWhere($qb->expr()->orX(
				$qb->expr()->neq('a.status', $qb->createNamedParameter('cancelled')),
				$qb->expr()->isNull('a.status'),
			));
		}
		$rows = $qb->executeQuery()->fetchAll();
		if ($rows === []) {
			return null;
		}
		$total = 0;
		foreach ($rows as $row) {
			$total += $this->effectiveMinutes(
				(string) $row['start_time'],
				(string) $row['end_time'],
				(int) $row['break_minutes'],
			);
		}
		return max(0, $total);
	}

	private function sumPatternWeekMinutes(int $patternId, int $weekIndex): ?int
	{
		if (!SchemaProbe::tableExists($this->db, 'dc_rotation_week_days')) {
			return null;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('net_minutes')
			->from('dc_rotation_week_days')
			->where($qb->expr()->eq('pattern_id', $qb->createNamedParameter($patternId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('week_index', $qb->createNamedParameter($weekIndex, IQueryBuilder::PARAM_INT)));
		$rows = $qb->executeQuery()->fetchAll();
		if ($rows === []) {
			return null;
		}
		$total = 0;
		foreach ($rows as $row) {
			$total += max(0, (int) ($row['net_minutes'] ?? 0));
		}
		return $total;
	}

	/**
	 * @return array<string,mixed>|null
	 */
	private function patternDayRow(int $patternId, int $weekIndex, int $dow): ?array
	{
		if (!SchemaProbe::tableExists($this->db, 'dc_rotation_week_days')) {
			return null;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from('dc_rotation_week_days')
			->where($qb->expr()->eq('pattern_id', $qb->createNamedParameter($patternId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('week_index', $qb->createNamedParameter($weekIndex, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('dow', $qb->createNamedParameter($dow, IQueryBuilder::PARAM_INT)))
			->setMaxResults(1);
		$row = $qb->executeQuery()->fetch();
		return $row === false ? null : $row;
	}

	/** Same overnight rule as RosterService::effectiveMinutes. */
	private function effectiveMinutes(string $startTime, string $endTime, int $breakMinutes): int
	{
		$start = $this->toMinute($startTime);
		$end = $this->toMinute($endTime);
		if ($end <= $start) {
			$end += 24 * 60;
		}
		return max(0, ($end - $start) - max(0, $breakMinutes));
	}

	private function toMinute(string $hhmm): int
	{
		$parts = explode(':', $hhmm);
		$h = (int) ($parts[0] ?? 0);
		$m = (int) ($parts[1] ?? 0);
		return ($h * 60) + $m;
	}
}
