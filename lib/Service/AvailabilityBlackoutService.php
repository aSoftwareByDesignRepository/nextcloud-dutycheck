<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\DutyCheck\Db\SchemaProbe;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Hard availability blackouts (“kann nicht”). Never auto-cancels published assignments.
 */
final class AvailabilityBlackoutService
{
	private const LABELS = ['personal', 'care', 'other'];
	private const MAX_SPAN_DAYS = 31;
	private const MAX_FUTURE_HORIZON_DAYS = 366;

	public function __construct(
		private readonly IDBConnection $db,
		private readonly CompanyService $companies,
		private readonly AccessControlService $access,
		private readonly SelfServiceSettingsService $settings,
		private readonly ?PeriodLockService $locks = null,
		private readonly ?PlannerLocationScopeService $plannerScope = null,
	) {
	}

	/**
	 * @return list<array<string,mixed>>
	 */
	public function listForEmployee(int $employeeId, string $from, string $to, string $actor): array
	{
		$this->assertSchemaReady();
		$this->assertCanReadEmployee($employeeId, $actor);
		$companyId = $this->employeeCompanyId($employeeId);
		$this->assertBlackoutsAllowed($companyId);

		$fromAt = $this->parseBoundaryDate($from) . ' 00:00:00';
		$toAt = $this->parseBoundaryDate($to) . ' 23:59:59';
		$fromDay = new DateTimeImmutable($this->parseBoundaryDate($from) . ' 00:00:00', new DateTimeZone('UTC'));
		$toDay = new DateTimeImmutable($this->parseBoundaryDate($to) . ' 00:00:00', new DateTimeZone('UTC'));
		if ($toDay < $fromDay) {
			throw new \InvalidArgumentException('INVALID_DATE_RANGE');
		}
		$maxEnd = $fromDay->modify('+' . self::MAX_FUTURE_HORIZON_DAYS . ' days');
		if ($toDay > $maxEnd) {
			throw new \InvalidArgumentException('BLACKOUT_HORIZON_EXCEEDED');
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('dc_avail_blackouts')
			->where($qb->expr()->eq('employee_id', $qb->createNamedParameter($employeeId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->lt('start_at', $qb->createNamedParameter($toAt)))
			->andWhere($qb->expr()->gt('end_at', $qb->createNamedParameter($fromAt)))
			->orderBy('start_at', 'ASC')
			->addOrderBy('id', 'ASC');
		return array_map([$this, 'normalize'], $qb->executeQuery()->fetchAll());
	}

	/**
	 * @return array<string,mixed>
	 */
	public function create(
		int $employeeId,
		string $startAt,
		string $endAt,
		string $labelEnum,
		?int $locationId,
		string $actor,
	): array {
		$this->assertSchemaReady();
		// Employees create own only; planners may create for company employees.
		$this->assertCanWriteEmployee($employeeId, $actor);
		$companyId = $this->employeeCompanyId($employeeId);
		$this->assertBlackoutsAllowed($companyId);

		$label = strtolower(trim($labelEnum));
		if (!in_array($label, self::LABELS, true)) {
			throw new \InvalidArgumentException('BLACKOUT_LABEL_INVALID');
		}
		$start = $this->parseUtcDateTime($startAt);
		$end = $this->parseUtcDateTime($endAt);
		if ($end <= $start) {
			throw new \InvalidArgumentException('BLACKOUT_RANGE_INVALID');
		}
		$maxEnd = $start->modify('+' . self::MAX_SPAN_DAYS . ' days');
		if ($end > $maxEnd) {
			throw new \InvalidArgumentException('BLACKOUT_SPAN_EXCEEDED');
		}
		$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
		$horizon = $now->modify('+' . self::MAX_FUTURE_HORIZON_DAYS . ' days');
		if ($end > $horizon) {
			throw new \InvalidArgumentException('BLACKOUT_HORIZON_EXCEEDED');
		}
		if ($locationId !== null && $locationId <= 0) {
			$locationId = null;
		}
		$this->assertLocationAllowedForCompany($locationId, $companyId);
		// Scope-hidden location param reports like a missing one.
		$this->assertPlannerLocationWrite($actor, $locationId, 'LOCATION_NOT_FOUND');

		$startSql = $start->format('Y-m-d H:i:s');
		$endSql = $end->format('Y-m-d H:i:s');

		$holder = $actor . ':' . bin2hex(random_bytes(4));
		$locked = $this->locks !== null
			&& $this->locks->acquire($employeeId, PeriodLockService::KIND_BLACKOUT, $holder, 30);
		if ($this->locks !== null && !$locked) {
			throw new \InvalidArgumentException('BLACKOUT_OVERLAP');
		}

		$this->db->beginTransaction();
		try {
			$merged = $this->mergeOverlapping($employeeId, $startSql, $endSql, $label, $locationId, $companyId, $actor);
			$this->db->commit();
			return $merged;
		} catch (\Throwable $e) {
			if ($this->db->inTransaction()) {
				$this->db->rollBack();
			}
			throw $e;
		} finally {
			if ($locked && $this->locks !== null) {
				$this->locks->release($employeeId, PeriodLockService::KIND_BLACKOUT, $holder);
			}
		}
	}

	public function delete(int $id, string $actor): void
	{
		$this->assertSchemaReady();
		$row = $this->getRawById($id);
		$employeeId = (int) $row['employee_id'];
		// The blackout row is the resource — report foreign rows like missing ones.
		$this->assertCanWriteEmployee($employeeId, $actor, 'BLACKOUT_NOT_FOUND');
		$this->assertBlackoutsAllowed((int) $row['company_id']);
		$rowLoc = $row['location_id'] !== null ? (int) $row['location_id'] : null;
		// Scope-hidden row reports like a missing one.
		$this->assertPlannerLocationWrite($actor, $rowLoc > 0 ? $rowLoc : null, 'BLACKOUT_NOT_FOUND');

		// Past blackouts are read-only for employees; planners may still remove.
		if (!$this->access->isPlannerOrAdmin($actor)) {
			$end = new DateTimeImmutable((string) $row['end_at'], new DateTimeZone('UTC'));
			$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
			if ($end <= $now) {
				throw new \InvalidArgumentException('BLACKOUT_PAST_READONLY');
			}
		}

		$qb = $this->db->getQueryBuilder();
		$qb->delete('dc_avail_blackouts')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	/** GDPR erasure — drop blackouts for the linked employee before link purge. */
	public function purgeForUser(string $userId): void
	{
		if (!SchemaProbe::tableExists($this->db, 'dc_avail_blackouts') || trim($userId) === '') {
			return;
		}
		try {
			$employeeId = $this->linkedEmployeeId($userId);
		} catch (\InvalidArgumentException) {
			return;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->delete('dc_avail_blackouts')
			->where($qb->expr()->eq('employee_id', $qb->createNamedParameter($employeeId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	/**
	 * True when a duty window overlaps a hard blackout (location null = all).
	 * Does not mutate or cancel assignments.
	 */
	public function blocks(int $employeeId, string $dutyDate, string $startTime, string $endTime, ?int $locationId = null): bool
	{
		return $this->findBlocking($employeeId, $dutyDate, $startTime, $endTime, $locationId) !== null;
	}

	/**
	 * Like findBlocking, but returns null when the company feature flag is off
	 * (assign must not hard-fail for legacy tenants).
	 *
	 * @return array<string,mixed>|null
	 */
	public function findBlockingForAssign(
		int $employeeId,
		string $dutyDate,
		string $startTime,
		string $endTime,
		?int $locationId = null,
	): ?array {
		try {
			$companyId = $this->employeeCompanyId($employeeId);
		} catch (\InvalidArgumentException) {
			return null;
		}
		if (!$this->settings->isBlackoutsEnabled($companyId)) {
			return null;
		}
		return $this->findBlocking($employeeId, $dutyDate, $startTime, $endTime, $locationId);
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public function findBlocking(
		int $employeeId,
		string $dutyDate,
		string $startTime,
		string $endTime,
		?int $locationId = null,
	): ?array {
		if (!SchemaProbe::tableExists($this->db, 'dc_avail_blackouts')) {
			return null;
		}
		if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dutyDate) !== 1) {
			return null;
		}
		if (
			preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $startTime) !== 1
			|| preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $endTime) !== 1
		) {
			return null;
		}

		$tzName = $this->timezoneForLocation($locationId);
		[$dutyStartUtc, $dutyEndUtc] = $this->dutyWindowUtc($dutyDate, $startTime, $endTime, $tzName);
		$startSql = $dutyStartUtc->format('Y-m-d H:i:s');
		$endSql = $dutyEndUtc->format('Y-m-d H:i:s');

		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('dc_avail_blackouts')
			->where($qb->expr()->eq('employee_id', $qb->createNamedParameter($employeeId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->lt('start_at', $qb->createNamedParameter($endSql)))
			->andWhere($qb->expr()->gt('end_at', $qb->createNamedParameter($startSql)))
			->orderBy('start_at', 'ASC')
			->setMaxResults(25);
		foreach ($qb->executeQuery()->fetchAll() as $row) {
			$rowLoc = $row['location_id'] !== null ? (int) $row['location_id'] : null;
			if ($rowLoc !== null && $locationId !== null && $rowLoc !== $locationId) {
				continue;
			}
			// Blackout location null = all locations; duty location null only blocked by all-location blackouts.
			if ($rowLoc !== null && $locationId === null) {
				continue;
			}
			return $this->normalize($row);
		}
		return null;
	}

	/**
	 * Compare preloaded blackout rows against a duty window using the same UTC
	 * conversion as {@see findBlocking} (location timezone → UTC).
	 *
	 * @param list<array{start_at:string,end_at:string,location_id:?int}> $blackouts
	 */
	public function anyOverlapsDuty(
		array $blackouts,
		string $dutyDate,
		string $startTime,
		string $endTime,
		?int $locationId = null,
	): bool {
		if ($blackouts === []) {
			return false;
		}
		if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dutyDate) !== 1) {
			return false;
		}
		if (
			preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $startTime) !== 1
			|| preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $endTime) !== 1
		) {
			return false;
		}
		$tzName = $this->timezoneForLocation($locationId);
		[$dutyStartUtc, $dutyEndUtc] = $this->dutyWindowUtc($dutyDate, $startTime, $endTime, $tzName);
		$startSql = $dutyStartUtc->format('Y-m-d H:i:s');
		$endSql = $dutyEndUtc->format('Y-m-d H:i:s');
		foreach ($blackouts as $b) {
			$rowLoc = $b['location_id'] ?? null;
			$rowLoc = $rowLoc !== null ? (int) $rowLoc : null;
			if ($rowLoc !== null && $locationId !== null && $rowLoc !== $locationId) {
				continue;
			}
			if ($rowLoc !== null && $locationId === null) {
				continue;
			}
			if ((string) $b['start_at'] < $endSql && (string) $b['end_at'] > $startSql) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Audit trail when a planner assigns onto a blackout. Never clears the blackout or cancels publish.
	 *
	 * @return array<string,mixed>
	 */
	public function recordOverride(
		int $assignmentId,
		?int $blackoutId,
		int $employeeId,
		string $reason,
		string $actor,
	): array {
		$this->assertSchemaReady();
		if (!SchemaProbe::tableExists($this->db, 'dc_blackout_overrides')) {
			throw new \InvalidArgumentException('SCHEMA_NOT_READY');
		}
		if (!$this->access->isPlannerOrAdmin($actor)) {
			throw new \InvalidArgumentException('FORBIDDEN');
		}
		$companyId = $this->employeeCompanyId($employeeId);
		$this->companies->assertCanAccessCompany($actor, $companyId, 'EMPLOYEE_NOT_FOUND');
		$this->assertBlackoutsAllowed($companyId);

		$reason = trim($reason);
		$len = mb_strlen($reason);
		if ($len < 10) {
			throw new \InvalidArgumentException('REASON_TOO_SHORT');
		}
		if ($len > 200) {
			throw new \InvalidArgumentException('REASON_TOO_LONG');
		}

		// Bind assignment → employee + company + location scope (IDOR / audit integrity).
		// Existence-blind: an assignment/blackout the actor cannot see reports the
		// same code as a missing row — no id enumeration.
		$assignment = $this->loadAssignmentForOverride($assignmentId);
		if ((int) $assignment['employee_id'] !== $employeeId) {
			throw new \InvalidArgumentException('ASSIGNMENT_NOT_FOUND');
		}
		if ((int) ($assignment['company_id'] ?? $companyId) !== $companyId) {
			throw new \InvalidArgumentException('ASSIGNMENT_NOT_FOUND');
		}
		$this->companies->assertCanAccessCompany($actor, (int) ($assignment['company_id'] ?? $companyId), 'ASSIGNMENT_NOT_FOUND');
		if ($this->plannerScope !== null && (int) ($assignment['location_id'] ?? 0) > 0) {
			// Scope-hidden assignment reports like a missing one.
			$this->plannerScope->assertCanPlanLocationOr($actor, (int) $assignment['location_id'], 'ASSIGNMENT_NOT_FOUND');
		}

		if ($blackoutId !== null && $blackoutId > 0) {
			$blk = $this->getRawById($blackoutId);
			if ((int) $blk['employee_id'] !== $employeeId) {
				throw new \InvalidArgumentException('BLACKOUT_NOT_FOUND');
			}
		} else {
			$blackoutId = null;
		}

		$now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
		$qb = $this->db->getQueryBuilder();
		$qb->insert('dc_blackout_overrides')->values([
			'company_id' => $qb->createNamedParameter($companyId, IQueryBuilder::PARAM_INT),
			'assignment_id' => $qb->createNamedParameter($assignmentId, IQueryBuilder::PARAM_INT),
			'blackout_id' => $qb->createNamedParameter($blackoutId, IQueryBuilder::PARAM_INT),
			'employee_id' => $qb->createNamedParameter($employeeId, IQueryBuilder::PARAM_INT),
			'reason' => $qb->createNamedParameter($reason),
			'created_by' => $qb->createNamedParameter($actor),
			'created_at' => $qb->createNamedParameter($now),
		])->executeStatement();

		$id = (int) $qb->getLastInsertId();
		return [
			'id' => $id,
			'companyId' => $companyId,
			'assignmentId' => $assignmentId,
			'blackoutId' => $blackoutId,
			'employeeId' => $employeeId,
			'reason' => $reason,
			'createdBy' => $actor,
			'createdAt' => $now,
		];
	}

	/**
	 * Merge preferred: same location scope + overlapping/touching intervals → one row.
	 * Different concrete locations overlapping → BLACKOUT_OVERLAP.
	 *
	 * @return array<string,mixed>
	 */
	private function mergeOverlapping(
		int $employeeId,
		string $startSql,
		string $endSql,
		string $label,
		?int $locationId,
		int $companyId,
		string $actor,
	): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('dc_avail_blackouts')
			->where($qb->expr()->eq('employee_id', $qb->createNamedParameter($employeeId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->lte('start_at', $qb->createNamedParameter($endSql)))
			->andWhere($qb->expr()->gte('end_at', $qb->createNamedParameter($startSql)))
			->orderBy('start_at', 'ASC');
		$candidates = $qb->executeQuery()->fetchAll();

		$toMerge = [];
		foreach ($candidates as $row) {
			$rowLoc = $row['location_id'] !== null ? (int) $row['location_id'] : null;
			if ($this->locationScopesCompatible($locationId, $rowLoc)) {
				$toMerge[] = $row;
				continue;
			}
			// Overlap in time but incompatible location scopes → reject.
			throw new \InvalidArgumentException('BLACKOUT_OVERLAP');
		}

		$mergeStart = $startSql;
		$mergeEnd = $endSql;
		$mergeLabel = $label;
		$mergeLocationId = $locationId;
		$idsToDelete = [];
		foreach ($toMerge as $row) {
			if ((string) $row['start_at'] < $mergeStart) {
				$mergeStart = (string) $row['start_at'];
			}
			if ((string) $row['end_at'] > $mergeEnd) {
				$mergeEnd = (string) $row['end_at'];
			}
			// Prefer non-personal label when merging mixed enums.
			$rowLabel = (string) $row['label_enum'];
			if ($mergeLabel === 'personal' && in_array($rowLabel, ['care', 'other'], true)) {
				$mergeLabel = $rowLabel;
			}
			if ($row['location_id'] === null) {
				$mergeLocationId = null;
			}
			$idsToDelete[] = (int) $row['id'];
		}

		// Re-check span after merge expansion.
		$mergedStart = new DateTimeImmutable($mergeStart, new DateTimeZone('UTC'));
		$mergedEnd = new DateTimeImmutable($mergeEnd, new DateTimeZone('UTC'));
		if ($mergedEnd > $mergedStart->modify('+' . self::MAX_SPAN_DAYS . ' days')) {
			throw new \InvalidArgumentException('BLACKOUT_SPAN_EXCEEDED');
		}

		if ($idsToDelete !== []) {
			$del = $this->db->getQueryBuilder();
			$del->delete('dc_avail_blackouts')
				->where($del->expr()->in('id', $del->createNamedParameter($idsToDelete, IQueryBuilder::PARAM_INT_ARRAY)))
				->executeStatement();
		}

		$now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
		$ins = $this->db->getQueryBuilder();
		$ins->insert('dc_avail_blackouts')->values([
			'company_id' => $ins->createNamedParameter($companyId, IQueryBuilder::PARAM_INT),
			'employee_id' => $ins->createNamedParameter($employeeId, IQueryBuilder::PARAM_INT),
			'location_id' => $ins->createNamedParameter($mergeLocationId, IQueryBuilder::PARAM_INT),
			'start_at' => $ins->createNamedParameter($mergeStart),
			'end_at' => $ins->createNamedParameter($mergeEnd),
			'label_enum' => $ins->createNamedParameter($mergeLabel),
			'created_by' => $ins->createNamedParameter($actor),
			'created_at' => $ins->createNamedParameter($now),
			'updated_at' => $ins->createNamedParameter($now),
		])->executeStatement();

		return $this->normalize($this->getRawById((int) $ins->getLastInsertId()));
	}

	private function locationScopesCompatible(?int $a, ?int $b): bool
	{
		if ($a === $b) {
			return true;
		}
		// null (all) is compatible with a concrete location for merge → widen to null.
		if ($a === null || $b === null) {
			return true;
		}
		return false;
	}

	/**
	 * @return array{0:DateTimeImmutable,1:DateTimeImmutable}
	 */
	private function dutyWindowUtc(string $dutyDate, string $startTime, string $endTime, string $tzName): array
	{
		$tz = new DateTimeZone($tzName);
		$start = new DateTimeImmutable($dutyDate . ' ' . $startTime . ':00', $tz);
		$end = new DateTimeImmutable($dutyDate . ' ' . $endTime . ':00', $tz);
		if ($end <= $start) {
			$end = $end->modify('+1 day');
		}
		$utc = new DateTimeZone('UTC');
		return [$start->setTimezone($utc), $end->setTimezone($utc)];
	}

	private function timezoneForLocation(?int $locationId): string
	{
		if ($locationId === null || $locationId <= 0 || !SchemaProbe::tableExists($this->db, 'dc_locations')) {
			return 'Europe/Berlin';
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('timezone')->from('dc_locations')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($locationId, IQueryBuilder::PARAM_INT)));
		$tz = $qb->executeQuery()->fetchOne();
		if (!is_string($tz) || trim($tz) === '') {
			return 'Europe/Berlin';
		}
		try {
			new DateTimeZone($tz);
			return $tz;
		} catch (\Throwable) {
			return 'Europe/Berlin';
		}
	}

	/**
	 * @return array<string,mixed>
	 */
	private function getRawById(int $id): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('dc_avail_blackouts')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$row = $qb->executeQuery()->fetch();
		if ($row === false) {
			throw new \InvalidArgumentException('BLACKOUT_NOT_FOUND');
		}
		return $row;
	}

	/**
	 * @param array<string,mixed> $row
	 * @return array<string,mixed>
	 */
	private function normalize(array $row): array
	{
		return [
			'id' => (int) $row['id'],
			'companyId' => (int) $row['company_id'],
			'employeeId' => (int) $row['employee_id'],
			'locationId' => $row['location_id'] !== null ? (int) $row['location_id'] : null,
			'startAt' => (string) $row['start_at'],
			'endAt' => (string) $row['end_at'],
			'labelEnum' => (string) $row['label_enum'],
			'createdBy' => (string) $row['created_by'],
			'createdAt' => (string) $row['created_at'],
			'updatedAt' => (string) $row['updated_at'],
		];
	}

	private function parseUtcDateTime(string $raw): DateTimeImmutable
	{
		$raw = trim($raw);
		// Accept "Y-m-d H:i:s" or ISO-8601 with Z / offset; store as UTC wall clock.
		try {
			if (preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(:\d{2})?$/', $raw) === 1) {
				$normalized = str_replace('T', ' ', $raw);
				if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $normalized) === 1) {
					$normalized .= ':00';
				}
				return new DateTimeImmutable($normalized, new DateTimeZone('UTC'));
			}
			$dt = new DateTimeImmutable($raw);
			return $dt->setTimezone(new DateTimeZone('UTC'));
		} catch (\Throwable) {
			throw new \InvalidArgumentException('BLACKOUT_DATETIME_INVALID');
		}
	}

	private function parseBoundaryDate(string $raw): string
	{
		$raw = trim($raw);
		if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) !== 1) {
			throw new \InvalidArgumentException('BLACKOUT_DATE_INVALID');
		}
		return $raw;
	}

	/**
	 * @return array{employee_id:int,company_id:int,location_id:int}
	 */
	private function loadAssignmentForOverride(int $assignmentId): array
	{
		if ($assignmentId < 1 || !SchemaProbe::tableExists($this->db, 'dc_assignments')) {
			throw new \InvalidArgumentException('ASSIGNMENT_NOT_FOUND');
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('a.employee_id', 'a.period_id', 'a.location_id')
			->from('dc_assignments', 'a')
			->where($qb->expr()->eq('a.id', $qb->createNamedParameter($assignmentId, IQueryBuilder::PARAM_INT)));
		$row = $qb->executeQuery()->fetch();
		if ($row === false) {
			throw new \InvalidArgumentException('ASSIGNMENT_NOT_FOUND');
		}
		$companyId = $this->employeeCompanyId((int) $row['employee_id']);
		if (SchemaProbe::hasColumn($this->db, 'dc_periods', 'company_id')) {
			$pq = $this->db->getQueryBuilder();
			$pq->select('company_id')->from('dc_periods')
				->where($pq->expr()->eq('id', $pq->createNamedParameter((int) $row['period_id'], IQueryBuilder::PARAM_INT)));
			$raw = $pq->executeQuery()->fetchOne();
			if ($raw !== false && $raw !== null) {
				$companyId = (int) $raw;
			}
		}
		return [
			'employee_id' => (int) $row['employee_id'],
			'company_id' => $companyId,
			'location_id' => (int) ($row['location_id'] ?? 0),
		];
	}

	private function assertCanReadEmployee(int $employeeId, string $actor, string $notFoundCode = 'EMPLOYEE_NOT_FOUND'): void
	{
		if ($this->access->isPlannerOrAdmin($actor)) {
			$this->companies->assertCanAccessCompany($actor, $this->employeeCompanyId($employeeId), $notFoundCode);
			return;
		}
		// Existence-blind: another employee's data is reported like a missing row.
		if ($this->linkedEmployeeId($actor) !== $employeeId) {
			throw new \InvalidArgumentException($notFoundCode);
		}
	}

	private function assertCanWriteEmployee(int $employeeId, string $actor, string $notFoundCode = 'EMPLOYEE_NOT_FOUND'): void
	{
		// Employees may mutate own rows only; planners/admins may mutate within company.
		if ($this->access->isPlannerOrAdmin($actor)) {
			$this->companies->assertCanAccessCompany($actor, $this->employeeCompanyId($employeeId), $notFoundCode);
			return;
		}
		if ($this->linkedEmployeeId($actor) !== $employeeId) {
			throw new \InvalidArgumentException($notFoundCode);
		}
	}

	/**
	 * Scoped planners may only write location-bound blackouts inside their Filiale set.
	 * Company-wide blackouts (null location) require an unrestricted planner / admin.
	 */
	private function assertPlannerLocationWrite(string $actor, ?int $locationId, string $notFoundCode): void
	{
		if (!$this->access->isPlannerOrAdmin($actor) || $this->access->isAppAdmin($actor)) {
			return;
		}
		if ($this->plannerScope === null) {
			return;
		}
		$allowed = $this->plannerScope->locationIdsFor($actor);
		if ($allowed === []) {
			return;
		}
		if ($locationId === null || $locationId < 1) {
			throw new \InvalidArgumentException($notFoundCode);
		}
		$this->plannerScope->assertCanPlanLocationOr($actor, $locationId, $notFoundCode);
	}

	/**
	 * Guard: blackouts CRUD/enforcement only when company flag is on.
	 * Rotation patterns alone must not unlock silent blackout rows that never block.
	 */
	private function assertBlackoutsAllowed(int $companyId): void
	{
		if ($this->settings->isBlackoutsEnabled($companyId)) {
			return;
		}
		throw new \InvalidArgumentException('BLACKOUTS_DISABLED');
	}

	private function assertSchemaReady(): void
	{
		if (!SchemaProbe::tableExists($this->db, 'dc_avail_blackouts')) {
			throw new \InvalidArgumentException('SCHEMA_NOT_READY');
		}
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

	private function assertLocationAllowedForCompany(?int $locationId, int $companyId): void
	{
		if ($locationId === null) {
			return;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'company_id')->from('dc_locations')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($locationId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('active', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)));
		$row = $qb->executeQuery()->fetch();
		if ($row === false) {
			throw new \InvalidArgumentException('LOCATION_NOT_FOUND');
		}
		// Existence-blind: a location outside the actor's company is invisible to
		// them — report it like a missing location (no COMPANY_MISMATCH oracle).
		if (
			$this->companies->isMultiCompanyActive()
			&& SchemaProbe::hasColumn($this->db, 'dc_locations', 'company_id')
			&& (int) ($row['company_id'] ?? 0) !== $companyId
		) {
			throw new \InvalidArgumentException('LOCATION_NOT_FOUND');
		}
	}
}
