<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\DutyCheck\Exception\ConflictAckRequiredException;
use OCA\DutyCheck\Exception\IntegrationLegacyConflictException;
use OCA\DutyCheck\Db\SchemaProbe;
use OCA\DutyCheck\Integration\ArbeitszeitCheckTypeMapper;
use OCA\DutyCheck\Repair\UninstallDropTables;
use OCA\DutyCheck\Integration\IArbeitszeitCheckIntegration;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IUserManager;
use Throwable;

class RosterService
{
	public function __construct(
		private IDBConnection $db,
		private ?IUserManager $userManager = null,
		private ?IArbeitszeitCheckIntegration $atIntegration = null,
		private ?TimezoneCatalog $timezoneCatalog = null,
		private ?PlanningDefaultsService $planningDefaults = null,
		private ?ConflictPolicyService $conflictPolicy = null,
		private ?PublishNotificationService $publishNotifications = null,
		private ?QualificationService $qualifications = null,
		private ?ThresholdApproachNotifier $thresholdNotifier = null,
		private ?LateChangeNotificationService $lateChangeNotifications = null,
		private ?CompanyService $companies = null,
	) {
	}

	/**
	 * Canonical English `message` strings in roster API conflict payloads (persisted + computed).
	 * Keep in sync with `message` literals in conflictsForPeriod() and candidateSoftConflicts().
	 *
	 * @return list<string>
	 */
	public static function rosterApiConflictMessageKeys(): array
	{
		return [
			'Less than 11 hours rest between consecutive assignments',
			'Employee has overlapping assignments (double booking)',
			'Shift exceeds configured daily threshold',
			'Period total hard cap exceeded for employee',
			'Period total soft cap exceeded for employee',
			'Calendar-week hard cap exceeded for employee',
			'Calendar-week soft cap exceeded for employee',
			'Employee is scheduled for too many consecutive days',
			'Employee assignment collides with approved absence',
			'Employee assignment collides with an ArbeitszeitCheck absence',
			'Period overlaps with another planning period',
			'Employee is missing a required qualification for this location',
			'Employee qualification is expired for this location',
			'Break is shorter than required for this shift length',
			'Location is understaffed relative to template headcount',
		];
	}

	public function dashboardSummary(?string $actorUserId = null): array
	{
		$openPeriods = $this->countScoped('dc_periods', 'status', 'open', $actorUserId);
		$publishedPeriods = $this->countScoped('dc_periods', 'status', 'published', $actorUserId);
		$employees = $this->countScoped('dc_employees', 'active', 1, $actorUserId);
		$locations = $this->countScoped('dc_locations', 'active', 1, $actorUserId);
		$assignments = $this->countActiveAssignments($actorUserId);
		$schemaReady = $this->isSchemaReady();

		return [
			'openPeriods' => $openPeriods,
			'publishedPeriods' => $publishedPeriods,
			'activeEmployees' => $employees,
			'activeLocations' => $locations,
			'assignments' => $assignments,
			'setup' => self::deriveSetupState($schemaReady, $employees, $locations, $openPeriods),
		];
	}

	/**
	 * Pure derivation of the dashboard setup checklist state. The UI hides the
	 * whole "Setup progress" section iff `readyForPlanning` — every gate must
	 * genuinely hold, so this stays a strict conjunction of positive counts.
	 *
	 * @return array{schemaReady: bool, activeEmployees: int, activeLocations: int, openPeriods: int, readyForPlanning: bool}
	 */
	public static function deriveSetupState(bool $schemaReady, int $activeEmployees, int $activeLocations, int $openPeriods): array
	{
		return [
			'schemaReady' => $schemaReady,
			'activeEmployees' => $activeEmployees,
			'activeLocations' => $activeLocations,
			'openPeriods' => $openPeriods,
			'readyForPlanning' => $schemaReady && $activeEmployees > 0 && $activeLocations > 0 && $openPeriods > 0,
		];
	}

	public function isSchemaReady(): bool
	{
		foreach (UninstallDropTables::TABLES as $table) {
			if (!$this->db->tableExists($table)) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Fail closed when multi-company is active and the actor cannot access the period's company.
	 */
	public function assertPeriodCompanyAccess(string $actorUserId, int $periodId): void
	{
		if ($this->companies !== null) {
			$this->companies->assertRowCompany($actorUserId, 'dc_periods', $periodId);
		}
	}

	public function listPeriods(?string $actorUserId = null): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'start_date', 'end_date', 'status', 'created_by', 'created_at', 'published_at', 'closed_at')
			->from('dc_periods')
			->orderBy('start_date', 'DESC');
		if ($actorUserId !== null && $this->companies !== null) {
			$this->companies->restrictQuery($qb, 'company_id', $actorUserId);
		}
		$rows = $qb->executeQuery()->fetchAll();
		return array_map(fn (array $r): array => $this->normalizePeriod($r), $rows);
	}

	public function createPeriod(string $startDate, string $endDate, string $actor): array
	{
		$this->assertDate($startDate);
		$this->assertDate($endDate);
		if ($startDate > $endDate) {
			throw new \InvalidArgumentException('INVALID_PERIOD_RANGE');
		}
		$companyId = ($this->companies !== null && $this->companies->schemaReady())
			? $this->companies->writeCompanyIdFor($actor)
			: null;
		$this->assertNoDuplicatePeriodRange($startDate, $endDate, $companyId);

		$now = $this->now();
		$frozenThresholds = $this->policyThresholds();
		$qb = $this->db->getQueryBuilder();
		$values = [
			'start_date' => $qb->createNamedParameter($startDate),
			'end_date' => $qb->createNamedParameter($endDate),
			'status' => $qb->createNamedParameter('open'),
			'created_by' => $qb->createNamedParameter($actor),
			'created_at' => $qb->createNamedParameter($now),
		];
		if ($companyId !== null) {
			$values['company_id'] = $qb->createNamedParameter($companyId, IQueryBuilder::PARAM_INT);
		}
		if ($this->periodHasFrozenThresholdsColumn()) {
			$values['conflict_thresholds_json'] = $qb->createNamedParameter(
				json_encode($frozenThresholds, JSON_THROW_ON_ERROR),
			);
		}
		$qb->insert('dc_periods')->values($values)->executeStatement();

		return $this->periodById((int) $qb->getLastInsertId());
	}

	public function transitionPeriod(int $periodId, string $targetStatus, string $actorUserId, string $reason = ''): array
	{
		if ($this->companies !== null) {
			$this->companies->assertRowCompany($actorUserId, 'dc_periods', $periodId);
		}
		$this->db->beginTransaction();
		try {
		$period = $this->periodById($periodId);
		$current = $period['status'];
		$targetStatus = trim($targetStatus);
		$allowed = [
			'open' => ['published'],
			'published' => ['closed'],
			'closed' => ['open'],
		];
		if (!isset($allowed[$current]) || !in_array($targetStatus, $allowed[$current], true)) {
			throw new \InvalidArgumentException('INVALID_PERIOD_TRANSITION');
		}
		$trimmedReason = trim($reason);
		if ($targetStatus === 'open' && mb_strlen($trimmedReason) < 10) {
			throw new \InvalidArgumentException('REASON_TOO_SHORT');
		}

		if ($targetStatus === 'published') {
			$hasHardConflicts = false;
			foreach ($this->conflictsForPeriod($periodId) as $conflict) {
				if (($conflict['severity'] ?? '') === 'hard') {
					$hasHardConflicts = true;
					break;
				}
			}
			if ($hasHardConflicts) {
				throw new \InvalidArgumentException('PERIOD_HAS_HARD_CONFLICTS');
			}
			// WF-7: optional publish gate when AT mirror is stale / breaker open.
			$at = null;
			try {
				$ref = new \ReflectionProperty($this, 'atIntegration');
				if ($ref->isInitialized($this)) {
					$at = $ref->getValue($this);
				}
			} catch (\Throwable) {
				$at = null;
			}
			if ($at !== null && $at->shouldBlockPublishForStale()) {
				throw new \InvalidArgumentException('INTEGRATION_PUBLISH_STALE');
			}
		}
		if ($targetStatus === 'closed' && $current !== 'published') {
			throw new \InvalidArgumentException('INVALID_PERIOD_TRANSITION');
		}

		$snapshotId = null;
		if ($targetStatus === 'published') {
			$snapshotId = $this->createSnapshot($periodId, 'publish', $actorUserId);
		}
		if ($targetStatus === 'closed') {
			$snapshotId = $this->createSnapshot($periodId, 'close', $actorUserId);
		}

		$qb = $this->db->getQueryBuilder();
		$qb->update('dc_periods')
			->set('status', $qb->createNamedParameter($targetStatus))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($periodId, IQueryBuilder::PARAM_INT)))
			// CAS: refuse if another request already flipped the status.
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter($current)));
		if ($targetStatus === 'published') {
			$qb->set('published_at', $qb->createNamedParameter($this->now()));
		}
		if ($targetStatus === 'closed') {
			$qb->set('closed_at', $qb->createNamedParameter($this->now()));
			$qb->set('close_snapshot_id', $qb->createNamedParameter($snapshotId, IQueryBuilder::PARAM_INT));
		}
		if ($targetStatus === 'open') {
			$qb->set('closed_at', $qb->createNamedParameter(null));
			$qb->set('close_snapshot_id', $qb->createNamedParameter(null));
			$qb->set('reopened_at', $qb->createNamedParameter($this->now()));
			$qb->set('reopened_by', $qb->createNamedParameter($actorUserId));
			$qb->set('reopen_reason', $qb->createNamedParameter($trimmedReason));
		}
		$affected = $qb->executeStatement();
		if ($affected !== 1) {
			throw new \InvalidArgumentException('PERIOD_STATUS_CONFLICT');
		}
		$this->writeAuditEvent(
			$periodId,
			$actorUserId,
			'period_transition',
			'period',
			$periodId,
			[
				'from' => $current,
				'to' => $targetStatus,
				'reason' => $trimmedReason,
				'snapshotId' => $snapshotId,
			]
		);
		$this->db->commit();

		if ($targetStatus === 'published' && $this->publishNotifications !== null) {
			try {
				$this->publishNotifications->notifyPeriodPublished($periodId, $actorUserId);
			} catch (Throwable) {
				// Notifications must never roll back a successful publish.
			}
		}

		return $this->periodById($periodId);
		} catch (Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}

	public function listPeriodSnapshots(int $periodId): array
	{
		$this->periodById($periodId);
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'snapshot_kind', 'snapshot_hash', 'prev_snapshot_id', 'generated_at', 'generated_by')
			->from('dc_roster_snapshots')
			->where($qb->expr()->eq('period_id', $qb->createNamedParameter($periodId, IQueryBuilder::PARAM_INT)))
			->orderBy('generated_at', 'DESC')
			->addOrderBy('id', 'DESC');
		$rows = $qb->executeQuery()->fetchAll();
		return array_map(static fn (array $row): array => [
			'id' => (int) $row['id'],
			'kind' => (string) $row['snapshot_kind'],
			'hash' => (string) $row['snapshot_hash'],
			'prevSnapshotId' => $row['prev_snapshot_id'] !== null ? (int) $row['prev_snapshot_id'] : null,
			'generatedAt' => (string) $row['generated_at'],
			'generatedBy' => (string) $row['generated_by'],
		], $rows);
	}

	public function verifyPeriodSnapshots(int $periodId): array
	{
		$this->periodById($periodId);
		$snapshots = $this->snapshotRowsForPeriod($periodId);
		$byId = [];
		foreach ($snapshots as $snapshot) {
			$byId[$snapshot['id']] = $snapshot;
		}
		$results = [];
		foreach ($snapshots as $snapshot) {
			$recomputed = hash('sha256', $this->canonicalizeJson(json_decode($snapshot['snapshot_json'], true, 512, JSON_THROW_ON_ERROR)));
			if (!hash_equals($snapshot['snapshot_hash'], $recomputed)) {
				throw new \InvalidArgumentException('SNAPSHOT_HASH_MISMATCH');
			}
			$chainValid = true;
			if ($snapshot['snapshot_kind'] === 'close' && $snapshot['prev_snapshot_id'] !== null) {
				$prev = $byId[(int) $snapshot['prev_snapshot_id']] ?? null;
				$chainValid = $prev !== null && hash_equals((string) $snapshot['prev_snapshot_hash'], (string) $prev['snapshot_hash']);
				if (!$chainValid) {
					throw new \InvalidArgumentException('SNAPSHOT_HASH_MISMATCH');
				}
			}
			$results[] = [
				'id' => (int) $snapshot['id'],
				'kind' => (string) $snapshot['snapshot_kind'],
				'hash' => (string) $snapshot['snapshot_hash'],
				'verified' => true,
				'chainVerified' => $chainValid,
				'generatedAt' => (string) $snapshot['generated_at'],
			];
		}
		return [
			'ok' => true,
			'count' => count($results),
			'items' => $results,
		];
	}

	public function publishReadiness(int $periodId): array
	{
		$this->periodById($periodId);
		$conflicts = $this->refreshAndListConflicts($periodId);
		return $this->computePublishReadinessFromConflicts($periodId, $conflicts);
	}

	/**
	 * @param list<array<string,mixed>> $conflicts
	 */
	private function computePublishReadinessFromConflicts(int $periodId, array $conflicts): array
	{
		$hard = 0;
		$soft = 0;
		$softUnack = 0;
		foreach ($conflicts as $conflict) {
			$severity = (string) ($conflict['severity'] ?? '');
			if ($severity === 'hard') {
				$hard++;
				continue;
			}
			if ($severity === 'soft') {
				$soft++;
				if (!(bool) ($conflict['acknowledged'] ?? false)) {
					$softUnack++;
				}
			}
		}
		$staleBlocked = false;
		$integrationStale = false;
		// Unit helpers may construct RosterService without __construct (uninitialized promoted props).
		$at = null;
		try {
			$ref = new \ReflectionProperty($this, 'atIntegration');
			if ($ref->isInitialized($this)) {
				$at = $ref->getValue($this);
			}
		} catch (\Throwable) {
			$at = null;
		}
		if ($at !== null) {
			$staleBlocked = $at->shouldBlockPublishForStale();
			$integrationStale = $at->isStale();
		}
		return [
			'periodId' => $periodId,
			'hardConflicts' => $hard,
			'softConflicts' => $soft,
			'unacknowledgedSoftConflicts' => $softUnack,
			'integrationPublishStale' => $staleBlocked,
			'integrationStale' => $integrationStale,
			'canPublish' => $hard === 0 && !$staleBlocked,
		];
	}

	public function periodAudit(int $periodId): array
	{
		$this->periodById($periodId);
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'actor_user_id', 'action', 'target_kind', 'target_id', 'payload_json', 'created_at')
			->from('dc_period_audit_log')
			->where($qb->expr()->eq('period_id', $qb->createNamedParameter($periodId, IQueryBuilder::PARAM_INT)))
			->orderBy('created_at', 'DESC')
			->addOrderBy('id', 'DESC')
			->setMaxResults(100);
		$rows = $qb->executeQuery()->fetchAll();
		return array_map(static function (array $row): array {
			$payload = [];
			try {
				$payload = $row['payload_json'] !== null ? (array) json_decode((string) $row['payload_json'], true, 512, JSON_THROW_ON_ERROR) : [];
			} catch (\Throwable) {
				$payload = [];
			}
			return [
				'id' => (int) $row['id'],
				'actorUserId' => (string) $row['actor_user_id'],
				'action' => (string) $row['action'],
				'targetKind' => (string) $row['target_kind'],
				'targetId' => $row['target_id'] !== null ? (int) $row['target_id'] : null,
				'payload' => $payload,
				'createdAt' => (string) $row['created_at'],
			];
		}, $rows);
	}

	/**
	 * Full roster rows for a single period (admin export / print). Does not run conflict refresh.
	 *
	 * @return array{period: array<string,mixed>, assignments: list<array<string,mixed>>}
	 */
	public function rosterExportBundle(int $periodId): array
	{
		$period = $this->periodById($periodId);
		$assignments = $this->listAssignments($periodId);
		$integrity = $this->latestIntegrityHashForPeriod($periodId);
		return [
			'period' => $period,
			'assignments' => $assignments,
			'snapshotHash' => $integrity['hash'],
			'snapshotKind' => $integrity['kind'],
			'snapshotId' => $integrity['id'],
		];
	}

	/**
	 * Prefer the latest close snapshot hash, else the latest publish snapshot, for print/PDF footers.
	 *
	 * @return array{id:?int,hash:?string,kind:?string}
	 */
	public function latestIntegrityHashForPeriod(int $periodId): array
	{
		foreach (['close', 'publish'] as $kind) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('id', 'snapshot_hash', 'snapshot_kind')
				->from('dc_roster_snapshots')
				->where($qb->expr()->eq('period_id', $qb->createNamedParameter($periodId, IQueryBuilder::PARAM_INT)))
				->andWhere($qb->expr()->eq('snapshot_kind', $qb->createNamedParameter($kind)))
				->orderBy('generated_at', 'DESC')
				->addOrderBy('id', 'DESC')
				->setMaxResults(1);
			$row = $qb->executeQuery()->fetch();
			if ($row !== false) {
				return [
					'id' => (int) $row['id'],
					'hash' => (string) $row['snapshot_hash'],
					'kind' => (string) $row['snapshot_kind'],
				];
			}
		}
		return ['id' => null, 'hash' => null, 'kind' => null];
	}

	/**
	 * Audit trail entry when an administrator exports roster data (CSV download or print view).
	 *
	 * @param array<string, mixed> $meta
	 */
	public function logRosterDataExport(int $periodId, string $actorUserId, string $channel, array $meta = []): void
	{
		$channel = trim($channel);
		if ($channel === '') {
			$channel = 'unknown';
		}
		$this->writeAuditEvent(
			$periodId,
			$actorUserId,
			'roster_data_export',
			'period',
			$periodId,
			array_merge(['channel' => $channel], $meta),
		);
	}

	public function rosterData(?int $periodId = null, ?string $actorUserId = null): array
	{
		$periods = $this->listPeriods($actorUserId);
		$selected = $periodId;
		if ($selected !== null) {
			if ($actorUserId !== null && $this->companies !== null) {
				$this->companies->assertRowCompany($actorUserId, 'dc_periods', $selected);
			}
			$this->periodById($selected);
			$knownPeriodIds = array_map(static fn (array $period): int => (int) $period['id'], $periods);
			if (!in_array($selected, $knownPeriodIds, true)) {
				throw new \InvalidArgumentException('PERIOD_NOT_FOUND');
			}
		}
		$selected = $this->resolveRosterPeriodSelection($selected, $periods);

		$employees = $this->listEmployees($actorUserId);
		$locations = $this->listLocations($actorUserId);
		$assignments = $selected !== null ? $this->listAssignments($selected) : [];
		$conflicts = $selected !== null ? $this->refreshAndListConflicts($selected) : [];
		$absenceBlocks = $selected !== null ? $this->listBlockingAbsenceSpansForPeriod($selected) : [];
		$selectedPeriod = $selected !== null ? $this->periodById($selected) : null;

		return [
			'periods' => $periods,
			'selectedPeriodId' => $selected,
			'selectedPeriodStatus' => $selectedPeriod['status'] ?? null,
			'canCreateAssignments' => $selectedPeriod !== null
				&& ($selectedPeriod['status'] ?? '') === 'open'
				&& $employees !== []
				&& $locations !== [],
			'employees' => $employees,
			'locations' => $locations,
			'assignments' => $assignments,
			'conflicts' => $conflicts,
			'absenceBlocks' => $absenceBlocks,
			'defaultBreakMinutes' => $this->planningDefaults?->getDefaultBreakMinutes() ?? 0,
		];
	}

	/**
	 * Prefer the newest open period when none is selected so planners land on a writable roster.
	 *
	 * @param list<array<string,mixed>> $periods
	 */
	private function resolveRosterPeriodSelection(?int $requested, array $periods): ?int
	{
		if ($requested !== null) {
			return $requested;
		}
		if ($periods === []) {
			return null;
		}
		foreach ($periods as $period) {
			if (($period['status'] ?? '') === 'open') {
				return (int) $period['id'];
			}
		}

		return (int) $periods[0]['id'];
	}

	/**
	 * Approved absences and blocking ArbeitszeitCheck mirror rows overlapping a period.
	 * The roster UI uses this to hide employees who cannot be assigned on a given day.
	 *
	 * @return list<array{employeeId: int, startDate: string, endDate: string, source: string}>
	 */
	public function listBlockingAbsenceSpansForPeriod(int $periodId): array
	{
		$period = $this->periodById($periodId);
		$periodStart = (string) $period['startDate'];
		$periodEnd = (string) $period['endDate'];
		$spans = [];
		$periodCompany = null;
		if ($this->companies !== null && $this->companies->schemaReady()
			&& SchemaProbe::hasColumn($this->db, 'dc_periods', 'company_id')) {
			$periodCompany = $this->readRowCompanyId('dc_periods', $periodId);
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('a.employee_id', 'a.start_date', 'a.end_date')
			->from('dc_absences', 'a')
			->where($qb->expr()->eq('a.status', $qb->createNamedParameter('approved')))
			->andWhere($qb->expr()->lte('a.start_date', $qb->createNamedParameter($periodEnd)))
			->andWhere($qb->expr()->gte('a.end_date', $qb->createNamedParameter($periodStart)));
		if ($periodCompany !== null) {
			if (SchemaProbe::hasColumn($this->db, 'dc_absences', 'company_id')) {
				$qb->andWhere($qb->expr()->eq(
					'a.company_id',
					$qb->createNamedParameter($periodCompany, IQueryBuilder::PARAM_INT),
				));
			} elseif (SchemaProbe::hasColumn($this->db, 'dc_employees', 'company_id')) {
				$qb->innerJoin('a', 'dc_employees', 'e', 'a.employee_id = e.id')
					->andWhere($qb->expr()->eq(
						'e.company_id',
						$qb->createNamedParameter($periodCompany, IQueryBuilder::PARAM_INT),
					));
			}
		}
		foreach ($qb->executeQuery()->fetchAll() as $row) {
			$spans[] = [
				'employeeId' => (int) $row['employee_id'],
				'startDate' => (string) $row['start_date'],
				'endDate' => (string) $row['end_date'],
				'source' => 'dutycheck',
			];
		}

		if ($this->atIntegration?->isEffective() === true) {
			$mirror = $this->db->getQueryBuilder();
			$mirror->select('e.id', 'm.start_date', 'm.end_date', 'm.type', 'm.status')
				->from('dc_at_absence_mirror', 'm')
				->innerJoin('m', 'dc_employees', 'e', 'm.linked_user_id = e.linked_user_id')
				->where($mirror->expr()->eq('e.active', $mirror->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
				->andWhere($mirror->expr()->lte('m.start_date', $mirror->createNamedParameter($periodEnd)))
				->andWhere($mirror->expr()->gte('m.end_date', $mirror->createNamedParameter($periodStart)));
			if ($periodCompany !== null && SchemaProbe::hasColumn($this->db, 'dc_employees', 'company_id')) {
				$mirror->andWhere($mirror->expr()->eq(
					'e.company_id',
					$mirror->createNamedParameter($periodCompany, IQueryBuilder::PARAM_INT),
				));
			}
			foreach ($mirror->executeQuery()->fetchAll() as $row) {
				if (!ArbeitszeitCheckTypeMapper::isBlockingApproved((string) $row['type'], (string) $row['status'])) {
					continue;
				}
				$spans[] = [
					'employeeId' => (int) $row['id'],
					'startDate' => (string) $row['start_date'],
					'endDate' => (string) $row['end_date'],
					'source' => 'arbeitszeitcheck',
				];
			}
		}

		return $spans;
	}

	public static function dateWithinInclusiveRange(string $date, string $rangeStart, string $rangeEnd): bool
	{
		return $date >= $rangeStart && $date <= $rangeEnd;
	}

	/**
	 * Create an assignment.
	 *
	 * Planner create is open-period only. Marketplace claims (open-shift approve)
	 * may also write into a published period via $allowPublishedMarketplace.
	 *
	 * @param array<string,mixed> $payload
	 * @return array<string,mixed>
	 */
	public function createAssignment(array $payload, string $actor, bool $allowPublishedMarketplace = false): array
	{
		$periodId = (int) ($payload['periodId'] ?? 0);
		$employeeId = (int) ($payload['employeeId'] ?? 0);
		$locationId = (int) ($payload['locationId'] ?? 0);
		$dutyDate = (string) ($payload['dutyDate'] ?? '');
		$startTime = (string) ($payload['startTime'] ?? '');
		$endTime = (string) ($payload['endTime'] ?? '');
		$breakMinutes = PlanningDefaultsService::parseAssignmentBreakMinutes($payload['breakMinutes'] ?? null);
		$note = trim((string) ($payload['note'] ?? ''));
		$acknowledgements = is_array($payload['acknowledgements'] ?? null) ? $payload['acknowledgements'] : [];

		if ($periodId <= 0) {
			throw new \InvalidArgumentException('PERIOD_ID_REQUIRED');
		}
		if ($employeeId <= 0) {
			throw new \InvalidArgumentException('EMPLOYEE_ID_REQUIRED');
		}
		if ($locationId <= 0) {
			throw new \InvalidArgumentException('LOCATION_ID_REQUIRED');
		}

		$this->assertDate($dutyDate);
		$startTime = $this->normalizeDutyTime($startTime);
		$endTime = $this->normalizeDutyTime($endTime);
		if ($startTime === $endTime) {
			throw new \InvalidArgumentException('EQUAL_DUTY_TIMES');
		}
		if (mb_strlen($note) > 512) {
			throw new \InvalidArgumentException('NOTE_TOO_LONG');
		}
		if ($this->effectiveMinutes($startTime, $endTime, $breakMinutes) <= 0) {
			throw new \InvalidArgumentException('INVALID_SHIFT_LENGTH');
		}

		$period = $this->periodById($periodId);
		if ($this->companies !== null) {
			$this->companies->assertRowCompany($actor, 'dc_periods', $periodId);
		}
		$allowedStatuses = $allowPublishedMarketplace ? ['open', 'published'] : ['open'];
		if (!in_array($period['status'], $allowedStatuses, true)) {
			throw new \InvalidArgumentException('PERIOD_NOT_OPEN');
		}
		if ($dutyDate < $period['startDate'] || $dutyDate > $period['endDate']) {
			throw new \InvalidArgumentException('DATE_OUTSIDE_PERIOD');
		}
		$this->assertEmployeeExists($employeeId);
		$this->assertLocationExists($locationId);
		$this->assertEntitiesSharePeriodCompany($periodId, $employeeId, $locationId);
		$this->assertNoAbsenceConflict($employeeId, $dutyDate);
		$this->assertNoOverlapConflict($periodId, $employeeId, $dutyDate, $startTime, $endTime);
		$qualConflicts = $this->qualificationConflicts($employeeId, $locationId, $dutyDate);
		foreach ($qualConflicts as $qc) {
			if (($qc['severity'] ?? '') === 'hard') {
				throw new \InvalidArgumentException('QUALIFICATION_MISSING');
			}
		}
		$softConflicts = array_merge(
			$this->candidateSoftConflicts($periodId, $employeeId, $dutyDate, $startTime, $endTime, null, $breakMinutes),
			array_values(array_filter($qualConflicts, static fn (array $c): bool => ($c['severity'] ?? '') === 'soft')),
		);
		if ($softConflicts !== []) {
			$this->assertAcknowledgedSoftConflicts($softConflicts, $acknowledgements);
		}

		// Fail closed: create requires integrity columns (Version1011/1014/1016+).
		if (!$this->assignmentHasStatusColumn()
			|| !$this->assignmentHasVersionColumn()
			|| !$this->assignmentHasSlotKeyColumn()) {
			throw new \InvalidArgumentException('SCHEMA_NOT_READY');
		}

		$this->db->beginTransaction();
		$createdAssignmentId = 0;
		try {
			$qb = $this->db->getQueryBuilder();
			try {
				$values = [
						'period_id' => $qb->createNamedParameter($periodId, IQueryBuilder::PARAM_INT),
						'employee_id' => $qb->createNamedParameter($employeeId, IQueryBuilder::PARAM_INT),
						'location_id' => $qb->createNamedParameter($locationId, IQueryBuilder::PARAM_INT),
						'duty_date' => $qb->createNamedParameter($dutyDate),
						'start_time' => $qb->createNamedParameter($startTime),
						'end_time' => $qb->createNamedParameter($endTime),
						'break_minutes' => $qb->createNamedParameter($breakMinutes, IQueryBuilder::PARAM_INT),
						'note' => $qb->createNamedParameter($note !== '' ? $note : null),
						'created_by' => $qb->createNamedParameter($actor),
						'created_at' => $qb->createNamedParameter($this->now()),
						'status' => $qb->createNamedParameter('active'),
						'version' => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
						'slot_key' => $qb->createNamedParameter(AssignmentSlotKey::forActive(
							$periodId,
							$employeeId,
							$dutyDate,
							$startTime,
							$endTime,
						)),
					];
				$qb->insert('dc_assignments')->values($values)->executeStatement();
				$createdAssignmentId = (int) $qb->getLastInsertId();
			} catch (Throwable $e) {
				if ($this->isUniqueConstraintViolation($e)) {
					throw new \InvalidArgumentException('ASSIGNMENT_DUPLICATE_SLOT');
				}
				throw $e;
			}

			$this->refreshAndListConflicts($periodId);
			$this->db->commit();
		} catch (Throwable $e) {
			if ($this->db->inTransaction()) {
				$this->db->rollBack();
			}
			throw $e;
		}

		if ($this->thresholdNotifier !== null) {
			try {
				$this->thresholdNotifier->notifyIfApproachingSoftCap($periodId, $employeeId);
			} catch (Throwable) {
				// Non-fatal.
			}
		}

		$data = $this->rosterData($periodId, $actor);
		$data['createdAssignmentId'] = $createdAssignmentId;
		return $data;
	}

	/**
	 * Update an active assignment while the period is open.
	 *
	 * @param array<string,mixed> $payload
	 * @return array<string,mixed>
	 */
	public function updateAssignment(int $assignmentId, array $payload, string $actor): array
	{
		$row = $this->assignmentRowById($assignmentId);
		if ($row === null) {
			throw new \InvalidArgumentException('ASSIGNMENT_NOT_FOUND');
		}
		if ((string) ($row['status'] ?? 'active') === 'cancelled') {
			throw new \InvalidArgumentException('ASSIGNMENT_CANCELLED');
		}
		$periodId = (int) $row['period_id'];
		if ($this->companies !== null) {
			$this->companies->assertRowCompany($actor, 'dc_periods', $periodId);
		}
		$period = $this->periodById($periodId);
		if ($period['status'] !== 'open') {
			throw new \InvalidArgumentException('PERIOD_NOT_OPEN');
		}

		$employeeId = array_key_exists('employeeId', $payload) ? (int) $payload['employeeId'] : (int) $row['employee_id'];
		$locationId = array_key_exists('locationId', $payload) ? (int) $payload['locationId'] : (int) $row['location_id'];
		$dutyDate = array_key_exists('dutyDate', $payload) ? (string) $payload['dutyDate'] : (string) $row['duty_date'];
		$startTime = array_key_exists('startTime', $payload) ? (string) $payload['startTime'] : (string) $row['start_time'];
		$endTime = array_key_exists('endTime', $payload) ? (string) $payload['endTime'] : (string) $row['end_time'];
		$breakMinutes = array_key_exists('breakMinutes', $payload)
			? PlanningDefaultsService::parseAssignmentBreakMinutes($payload['breakMinutes'] ?? null)
			: (int) $row['break_minutes'];
		$note = array_key_exists('note', $payload) ? trim((string) $payload['note']) : (string) ($row['note'] ?? '');
		$acknowledgements = is_array($payload['acknowledgements'] ?? null) ? $payload['acknowledgements'] : [];
		$rawExpected = $payload['expectedVersion'] ?? $payload['version'] ?? null;
		$expectedVersion = ($rawExpected === null || $rawExpected === '')
			? null
			: (int) $rawExpected;

		if ($employeeId <= 0) {
			throw new \InvalidArgumentException('EMPLOYEE_ID_REQUIRED');
		}
		if ($locationId <= 0) {
			throw new \InvalidArgumentException('LOCATION_ID_REQUIRED');
		}
		$this->assertDate($dutyDate);
		$startTime = $this->normalizeDutyTime($startTime);
		$endTime = $this->normalizeDutyTime($endTime);
		if ($startTime === $endTime) {
			throw new \InvalidArgumentException('EQUAL_DUTY_TIMES');
		}
		if (mb_strlen($note) > 512) {
			throw new \InvalidArgumentException('NOTE_TOO_LONG');
		}
		if ($this->effectiveMinutes($startTime, $endTime, $breakMinutes) <= 0) {
			throw new \InvalidArgumentException('INVALID_SHIFT_LENGTH');
		}
		if ($dutyDate < $period['startDate'] || $dutyDate > $period['endDate']) {
			throw new \InvalidArgumentException('DATE_OUTSIDE_PERIOD');
		}
		$this->assertEmployeeExists($employeeId);
		$this->assertLocationExists($locationId);
		$this->assertEntitiesSharePeriodCompany($periodId, $employeeId, $locationId);
		$this->assertNoAbsenceConflict($employeeId, $dutyDate);
		$this->assertNoOverlapConflict($periodId, $employeeId, $dutyDate, $startTime, $endTime, $assignmentId);
		$qualConflicts = $this->qualificationConflicts($employeeId, $locationId, $dutyDate);
		foreach ($qualConflicts as $qc) {
			if (($qc['severity'] ?? '') === 'hard') {
				throw new \InvalidArgumentException('QUALIFICATION_MISSING');
			}
		}
		$softConflicts = array_merge(
			$this->candidateSoftConflicts($periodId, $employeeId, $dutyDate, $startTime, $endTime, $assignmentId, $breakMinutes),
			array_values(array_filter($qualConflicts, static fn (array $c): bool => ($c['severity'] ?? '') === 'soft')),
		);
		if ($softConflicts !== []) {
			$this->assertAcknowledgedSoftConflicts($softConflicts, $acknowledgements);
		}

		// Fail closed: update requires integrity columns (aligned with create).
		if (!$this->assignmentHasVersionColumn()
			|| !$this->assignmentHasStatusColumn()
			|| !$this->assignmentHasSlotKeyColumn()) {
			throw new \InvalidArgumentException('SCHEMA_NOT_READY');
		}
		$currentVersion = (int) ($row['version'] ?? 0);
		if ($expectedVersion === null) {
			throw new \InvalidArgumentException('EXPECTED_VERSION_REQUIRED');
		}
		if ($expectedVersion !== $currentVersion) {
			throw new \InvalidArgumentException('STALE_VERSION');
		}
		$casVersion = $expectedVersion;

		$this->db->beginTransaction();
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->update('dc_assignments')
				->set('employee_id', $qb->createNamedParameter($employeeId, IQueryBuilder::PARAM_INT))
				->set('location_id', $qb->createNamedParameter($locationId, IQueryBuilder::PARAM_INT))
				->set('duty_date', $qb->createNamedParameter($dutyDate))
				->set('start_time', $qb->createNamedParameter($startTime))
				->set('end_time', $qb->createNamedParameter($endTime))
				->set('break_minutes', $qb->createNamedParameter($breakMinutes, IQueryBuilder::PARAM_INT))
				->set('note', $qb->createNamedParameter($note !== '' ? $note : null))
				->set('version', $qb->createNamedParameter($casVersion + 1, IQueryBuilder::PARAM_INT))
				->set('slot_key', $qb->createNamedParameter(AssignmentSlotKey::forActive(
					$periodId,
					$employeeId,
					$dutyDate,
					$startTime,
					$endTime,
				)))
				->set('acknowledged_at', $qb->createNamedParameter(null))
				->set('acknowledged_by', $qb->createNamedParameter(null))
				->andWhere($qb->expr()->neq('status', $qb->createNamedParameter('cancelled')));
			$qb->where($qb->expr()->eq('id', $qb->createNamedParameter($assignmentId, IQueryBuilder::PARAM_INT)))
				->andWhere($qb->expr()->eq('version', $qb->createNamedParameter($casVersion, IQueryBuilder::PARAM_INT)));
			try {
				$affected = $qb->executeStatement();
			} catch (Throwable $e) {
				if ($this->isUniqueConstraintViolation($e)) {
					throw new \InvalidArgumentException('ASSIGNMENT_DUPLICATE_SLOT');
				}
				throw $e;
			}
			if ($affected !== 1) {
				throw new \InvalidArgumentException('STALE_VERSION');
			}

			$this->writeAuditEvent($periodId, $actor, 'assignment_updated', 'assignment', $assignmentId, [
				'employeeId' => $employeeId,
				'locationId' => $locationId,
				'dutyDate' => $dutyDate,
				'startTime' => $startTime,
				'endTime' => $endTime,
				'version' => $casVersion + 1,
			]);
			$this->refreshAndListConflicts($periodId);
			$this->db->commit();
		} catch (Throwable $e) {
			if ($this->db->inTransaction()) {
				$this->db->rollBack();
			}
			throw $e;
		}

		if ($this->thresholdNotifier !== null) {
			try {
				$this->thresholdNotifier->notifyIfApproachingSoftCap($periodId, $employeeId);
			} catch (Throwable) {
				// Non-fatal.
			}
		}

		return $this->rosterData($periodId, $actor);
	}

	/**
	 * Soft-cancel an assignment in an open or published period (excluded from publish / my-roster).
	 * Published cancels support Wave B pool-swap → open-shift conversion.
	 *
	 * @return array<string,mixed>
	 */
	/**
	 * Location must exist and (when multi-company) share the period's company.
	 * Used by open-shift create so foreign locations cannot be stamped.
	 */
	public function assertLocationMatchesPeriodCompany(int $periodId, int $locationId): void
	{
		$this->assertLocationExists($locationId);
		if ($this->companies === null || !$this->companies->schemaReady() || !$this->companies->isMultiCompanyActive()) {
			return;
		}
		if ($this->readRowCompanyId('dc_periods', $periodId) !== $this->readRowCompanyId('dc_locations', $locationId)) {
			throw new \InvalidArgumentException('COMPANY_MISMATCH');
		}
	}

	/**
	 * Soft-cancel an assignment without late-change fan-out.
	 * Used to roll back orphan marketplace assignments after a lost approve CAS.
	 */
	public function cancelAssignmentSilent(int $assignmentId, string $actor): void
	{
		$this->cancelAssignment($assignmentId, $actor, false);
	}

	public function cancelAssignment(int $assignmentId, string $actor, bool $notifyLateChange = true): array
	{
		// Fail closed: cancel requires status CAS + version bump + slot_key free.
		if (!$this->assignmentHasStatusColumn()
			|| !$this->assignmentHasVersionColumn()
			|| !$this->assignmentHasSlotKeyColumn()) {
			throw new \InvalidArgumentException('SCHEMA_NOT_READY');
		}
		$row = $this->assignmentRowById($assignmentId);
		if ($row === null) {
			throw new \InvalidArgumentException('ASSIGNMENT_NOT_FOUND');
		}
		if ((string) ($row['status'] ?? 'active') === 'cancelled') {
			return $this->rosterData((int) $row['period_id'], $actor);
		}
		$periodId = (int) $row['period_id'];
		if ($this->companies !== null) {
			$this->companies->assertRowCompany($actor, 'dc_periods', $periodId);
		}
		$period = $this->periodById($periodId);
		if (!in_array($period['status'], ['open', 'published'], true)) {
			throw new \InvalidArgumentException('PERIOD_NOT_OPEN');
		}

		$this->db->beginTransaction();
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->update('dc_assignments')
				->set('status', $qb->createNamedParameter('cancelled'))
				->set('cancelled_at', $qb->createNamedParameter($this->now()))
				->set('cancelled_by', $qb->createNamedParameter($actor))
				->set('acknowledged_at', $qb->createNamedParameter(null))
				->set('acknowledged_by', $qb->createNamedParameter(null))
				->set('version', $qb->createNamedParameter(((int) ($row['version'] ?? 0)) + 1, IQueryBuilder::PARAM_INT))
				// Free the logical slot so the same employee/date/times can be recreated.
				->set('slot_key', $qb->createNamedParameter(AssignmentSlotKey::forCancelled($assignmentId)))
				->where($qb->expr()->eq('id', $qb->createNamedParameter($assignmentId, IQueryBuilder::PARAM_INT)))
				->andWhere($qb->expr()->neq('status', $qb->createNamedParameter('cancelled')));
			$affected = $qb->executeStatement();
			if ($affected !== 1) {
				throw new \InvalidArgumentException('ASSIGNMENT_CANCELLED');
			}

			$this->writeAuditEvent($periodId, $actor, 'assignment_cancelled', 'assignment', $assignmentId, []);
			$this->refreshAndListConflicts($periodId);
			$this->db->commit();
		} catch (Throwable $e) {
			if ($this->db->inTransaction()) {
				$this->db->rollBack();
			}
			throw $e;
		}

		if ($notifyLateChange && $period['status'] === 'published' && $this->lateChangeNotifications !== null) {
			try {
				$this->lateChangeNotifications->notifyAssignmentChanged(
					(int) $row['employee_id'],
					$periodId,
					'assignment_cancelled_late',
				);
			} catch (Throwable) {
				// Non-fatal.
			}
		}

		return $this->rosterData($periodId, $actor);
	}

	/**
	 * Employee marks a published/closed assignment as seen (idempotent).
	 *
	 * @return array{assignmentId:int,acknowledgedAt:string,acknowledgedBy:string}
	 */
	public function acknowledgeAssignment(int $assignmentId, string $actorUserId): array
	{
		if (!$this->assignmentHasStatusColumn()) {
			throw new \InvalidArgumentException('SCHEMA_NOT_READY');
		}
		$row = $this->assignmentRowById($assignmentId);
		if ($row === null) {
			throw new \InvalidArgumentException('ASSIGNMENT_NOT_FOUND');
		}
		if ((string) ($row['status'] ?? 'active') === 'cancelled') {
			throw new \InvalidArgumentException('ASSIGNMENT_CANCELLED');
		}
		$employeeId = $this->linkedEmployeeIdByUserId($actorUserId);
		if ($employeeId !== (int) $row['employee_id']) {
			throw new \InvalidArgumentException('FORBIDDEN');
		}
		$period = $this->periodById((int) $row['period_id']);
		if (!in_array($period['status'], ['published', 'closed'], true)) {
			throw new \InvalidArgumentException('PERIOD_NOT_PUBLISHED');
		}

		$existingAt = $row['acknowledged_at'] ?? null;
		if ($existingAt !== null && (string) $existingAt !== '') {
			return [
				'assignmentId' => $assignmentId,
				'acknowledgedAt' => (string) $existingAt,
				'acknowledgedBy' => (string) ($row['acknowledged_by'] ?? $actorUserId),
			];
		}

		$ackedAt = $this->now();
		$qb = $this->db->getQueryBuilder();
		$qb->update('dc_assignments')
			->set('acknowledged_at', $qb->createNamedParameter($ackedAt))
			->set('acknowledged_by', $qb->createNamedParameter($actorUserId))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($assignmentId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNull('acknowledged_at'))
			->executeStatement();

		$fresh = $this->assignmentRowById($assignmentId);
		return [
			'assignmentId' => $assignmentId,
			'acknowledgedAt' => (string) ($fresh['acknowledged_at'] ?? $ackedAt),
			'acknowledgedBy' => (string) ($fresh['acknowledged_by'] ?? $actorUserId),
		];
	}

	/**
	 * Acknowledge rate for a period (planner view). Cancelled assignments excluded.
	 *
	 * @return array{total:int,acknowledged:int,percent:float}
	 */
	public function periodAcknowledgeStats(int $periodId): array
	{
		$this->periodById($periodId);
		$assignments = $this->listAssignments($periodId);
		$total = count($assignments);
		$acked = 0;
		foreach ($assignments as $a) {
			if (($a['acknowledgedAt'] ?? null) !== null) {
				$acked++;
			}
		}
		return [
			'total' => $total,
			'acknowledged' => $acked,
			'percent' => $total === 0 ? 0.0 : round(($acked / $total) * 100, 1),
		];
	}

	/**
	 * Copy assignments from a source period into an open target (dry-run or apply).
	 *
	 * @return array{dryRun:bool,wouldCreate:int,created:int,skipped:int,conflictsPreview:list<array<string,mixed>>}
	 */
	public function copyPeriodAssignments(int $sourcePeriodId, int $targetPeriodId, string $actor, bool $dryRun = true): array
	{
		if ($this->companies !== null) {
			$this->companies->assertRowCompany($actor, 'dc_periods', $sourcePeriodId);
			$this->companies->assertRowCompany($actor, 'dc_periods', $targetPeriodId);
		}
		$source = $this->periodById($sourcePeriodId);
		$target = $this->periodById($targetPeriodId);
		if ($this->companies !== null && $this->companies->isMultiCompanyActive() && $this->companies->schemaReady()) {
			$srcCo = $this->readRowCompanyId('dc_periods', $sourcePeriodId);
			$tgtCo = $this->readRowCompanyId('dc_periods', $targetPeriodId);
			if ($srcCo !== $tgtCo) {
				throw new \InvalidArgumentException('COMPANY_MISMATCH');
			}
		}
		if ($target['status'] !== 'open') {
			throw new \InvalidArgumentException('PERIOD_NOT_OPEN');
		}
		$sourceAssignments = $this->listAssignments($sourcePeriodId);
		$sourceStart = new DateTimeImmutable($source['startDate']);
		$targetStart = new DateTimeImmutable($target['startDate']);
		$dayShift = (int) $sourceStart->diff($targetStart)->format('%r%a');

		$wouldCreate = 0;
		$created = 0;
		$skipped = 0;
		$previewConflicts = [];

		foreach ($sourceAssignments as $assignment) {
			$dutyDate = (new DateTimeImmutable((string) $assignment['dutyDate']))->modify(($dayShift >= 0 ? '+' : '') . $dayShift . ' days')->format('Y-m-d');
			if ($dutyDate < $target['startDate'] || $dutyDate > $target['endDate']) {
				$skipped++;
				continue;
			}
			$wouldCreate++;
			if ($dryRun) {
				continue;
			}
			try {
				$this->createAssignment([
					'periodId' => $targetPeriodId,
					'employeeId' => $assignment['employeeId'],
					'locationId' => $assignment['locationId'],
					'dutyDate' => $dutyDate,
					'startTime' => $assignment['startTime'],
					'endTime' => $assignment['endTime'],
					'breakMinutes' => $assignment['breakMinutes'],
					'note' => $assignment['note'] ?? '',
					'acknowledgements' => [],
				], $actor);
				$created++;
			} catch (ConflictAckRequiredException $e) {
				$skipped++;
				$previewConflicts = array_merge($previewConflicts, $e->getConflicts());
			} catch (\InvalidArgumentException) {
				$skipped++;
			}
		}

		if (!$dryRun) {
			$this->writeAuditEvent($targetPeriodId, $actor, 'PERIOD_COPY_APPLIED', 'period', $targetPeriodId, [
				'sourcePeriodId' => $sourcePeriodId,
				'created' => $created,
				'skipped' => $skipped,
			]);
		}

		return [
			'dryRun' => $dryRun,
			'wouldCreate' => $wouldCreate,
			'created' => $created,
			'skipped' => $skipped,
			'conflictsPreview' => $previewConflicts,
		];
	}

	/**
	 * Transfer assignment to another employee (swap approval). Allowed on open or published periods.
	 * CAS: status active, employee still the swap donor, version bump + slot_key rewrite.
	 *
	 * @return array<string,mixed>
	 */
	public function transferAssignmentEmployee(int $assignmentId, int $fromEmployeeId, int $toEmployeeId, string $actor): array
	{
		if (!$this->assignmentHasStatusColumn()
			|| !$this->assignmentHasVersionColumn()
			|| !$this->assignmentHasSlotKeyColumn()) {
			throw new \InvalidArgumentException('SCHEMA_NOT_READY');
		}
		if ($fromEmployeeId <= 0 || $toEmployeeId <= 0 || $fromEmployeeId === $toEmployeeId) {
			throw new \InvalidArgumentException('SWAP_SAME_EMPLOYEE');
		}
		$row = $this->assignmentRowById($assignmentId);
		if ($row === null) {
			throw new \InvalidArgumentException('ASSIGNMENT_NOT_FOUND');
		}
		if ((string) ($row['status'] ?? 'active') === 'cancelled') {
			throw new \InvalidArgumentException('ASSIGNMENT_CANCELLED');
		}
		if ((int) $row['employee_id'] !== $fromEmployeeId) {
			throw new \InvalidArgumentException('ASSIGNMENT_TRANSFER_STALE');
		}
		$periodId = (int) $row['period_id'];
		if ($this->companies !== null) {
			$this->companies->assertRowCompany($actor, 'dc_periods', $periodId);
		}
		$period = $this->periodById($periodId);
		if (!in_array($period['status'], ['open', 'published'], true)) {
			throw new \InvalidArgumentException('PERIOD_NOT_OPEN');
		}
		$this->assertEmployeeExists($toEmployeeId);
		$this->assertEntitiesSharePeriodCompany($periodId, $toEmployeeId, (int) $row['location_id']);
		$dutyDate = (string) $row['duty_date'];
		$startTime = (string) $row['start_time'];
		$endTime = (string) $row['end_time'];
		$this->assertNoAbsenceConflict($toEmployeeId, $dutyDate);
		$this->assertNoOverlapConflict($periodId, $toEmployeeId, $dutyDate, $startTime, $endTime, $assignmentId);

		$casVersion = (int) ($row['version'] ?? 0);
		$this->db->beginTransaction();
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->update('dc_assignments')
				->set('employee_id', $qb->createNamedParameter($toEmployeeId, IQueryBuilder::PARAM_INT))
				->set('slot_key', $qb->createNamedParameter(AssignmentSlotKey::forActive(
					$periodId,
					$toEmployeeId,
					$dutyDate,
					$startTime,
					$endTime,
				)))
				->set('version', $qb->createNamedParameter($casVersion + 1, IQueryBuilder::PARAM_INT))
				->set('acknowledged_at', $qb->createNamedParameter(null))
				->set('acknowledged_by', $qb->createNamedParameter(null))
				->where($qb->expr()->eq('id', $qb->createNamedParameter($assignmentId, IQueryBuilder::PARAM_INT)))
				->andWhere($qb->expr()->eq('employee_id', $qb->createNamedParameter($fromEmployeeId, IQueryBuilder::PARAM_INT)))
				->andWhere($qb->expr()->eq('version', $qb->createNamedParameter($casVersion, IQueryBuilder::PARAM_INT)))
				->andWhere($qb->expr()->neq('status', $qb->createNamedParameter('cancelled')));
			try {
				$affected = $qb->executeStatement();
			} catch (Throwable $e) {
				if ($this->isUniqueConstraintViolation($e)) {
					throw new \InvalidArgumentException('ASSIGNMENT_DUPLICATE_SLOT');
				}
				throw $e;
			}
			if ($affected !== 1) {
				throw new \InvalidArgumentException('ASSIGNMENT_TRANSFER_STALE');
			}
			$this->writeAuditEvent($periodId, $actor, 'assignment_transferred', 'assignment', $assignmentId, [
				'fromEmployeeId' => $fromEmployeeId,
				'toEmployeeId' => $toEmployeeId,
				'version' => $casVersion + 1,
			]);
			$conflicts = $this->refreshAndListConflicts($periodId);
			foreach ($conflicts as $conflict) {
				if (($conflict['severity'] ?? '') === 'hard') {
					throw new \InvalidArgumentException('SWAP_CONFLICT');
				}
			}
			$this->db->commit();
		} catch (Throwable $e) {
			if ($this->db->inTransaction()) {
				$this->db->rollBack();
			}
			throw $e;
		}

		if ($period['status'] === 'published' && $this->lateChangeNotifications !== null) {
			try {
				$this->lateChangeNotifications->notifyAssignmentChanged($fromEmployeeId, $periodId, 'assignment_changed_late');
				$this->lateChangeNotifications->notifyAssignmentChanged($toEmployeeId, $periodId, 'assignment_changed_late');
			} catch (Throwable) {
				// Non-fatal.
			}
		}

		return $this->rosterData($periodId);
	}

	public function acknowledgeConflict(int $conflictId, string $actorUserId, string $reason): array
	{
		$trimmed = trim($reason);
		if (mb_strlen($trimmed) < 10) {
			throw new \InvalidArgumentException('REASON_TOO_SHORT');
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'period_id', 'context_hash', 'is_resolved')
			->from('dc_conflicts')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($conflictId, IQueryBuilder::PARAM_INT)));
		$row = $qb->executeQuery()->fetch();
		if ($row === false) {
			throw new \InvalidArgumentException('CONFLICT_NOT_FOUND');
		}
		$this->assertPeriodCompanyAccess($actorUserId, (int) $row['period_id']);
		if ((int) $row['is_resolved'] === 1) {
			throw new \InvalidArgumentException('CONFLICT_RESOLVED');
		}
		$update = $this->db->getQueryBuilder();
		$update->update('dc_conflicts')
			->set('ack_user_id', $update->createNamedParameter($actorUserId))
			->set('ack_reason', $update->createNamedParameter($trimmed))
			->set('ack_at', $update->createNamedParameter($this->now()))
			->set('ack_context_hash', $update->createNamedParameter((string) $row['context_hash']))
			->where($update->expr()->eq('id', $update->createNamedParameter($conflictId, IQueryBuilder::PARAM_INT)))
			->executeStatement();

		return $this->refreshAndListConflicts((int) $row['period_id']);
	}

	/**
	 * @return list<array<string,mixed>>
	 */
	private function candidateSoftConflicts(int $periodId, int $employeeId, string $dutyDate, string $startTime, string $endTime, ?int $excludeAssignmentId = null, ?int $breakMinutes = null): array
	{
		$candidateRange = $this->assignmentAbsoluteRange($dutyDate, $startTime, $endTime);
		$assignments = $this->listAssignments($periodId);
		$thresholds = $this->policyThresholdsForPeriod($periodId);
		$minRest = $thresholds['minRestMinutes'];
		$soft = [];
		foreach ($assignments as $assignment) {
			if ($excludeAssignmentId !== null && (int) $assignment['id'] === $excludeAssignmentId) {
				continue;
			}
			if ((int) $assignment['employeeId'] !== $employeeId) {
				continue;
			}
			$existingRange = $this->assignmentAbsoluteRange(
				(string) $assignment['dutyDate'],
				(string) $assignment['startTime'],
				(string) $assignment['endTime']
			);
			if ($this->absoluteRangesOverlap($candidateRange, $existingRange)) {
				continue;
			}
			$restMinutes = $this->minutesBetweenRanges($existingRange, $candidateRange);
			if ($restMinutes >= 0 && $restMinutes < $minRest) {
				$soft[] = [
					'type' => 'rest_time_violation',
					'severity' => 'soft',
					'message' => 'Less than 11 hours rest between consecutive assignments',
					'assignmentIds' => [(int) $assignment['id']],
					'payload' => ['restMinutes' => $restMinutes, 'minRestMinutes' => $minRest],
				];
			}
		}

		if ($breakMinutes !== null) {
			$effective = $this->effectiveMinutes($startTime, $endTime, $breakMinutes);
			if ($effective > 360 && $breakMinutes < 30) {
				$soft[] = [
					'type' => 'break_too_short',
					'severity' => 'soft',
					'message' => 'Break is shorter than required for this shift length',
					'assignmentIds' => $excludeAssignmentId !== null ? [$excludeAssignmentId] : [],
					'payload' => [
						'effectiveMinutes' => $effective,
						'breakMinutes' => $breakMinutes,
						'minBreakMinutes' => 30,
					],
				];
			}
			if ($effective > $thresholds['maxDailyHard']) {
				$soft[] = [
					'type' => 'shift_too_long',
					'severity' => 'soft',
					'message' => 'Shift exceeds configured daily threshold',
					'assignmentIds' => $excludeAssignmentId !== null ? [$excludeAssignmentId] : [],
					'payload' => [
						'effectiveMinutes' => $effective,
						'maxDailyHard' => $thresholds['maxDailyHard'],
					],
				];
			}
		}

		return $soft;
	}

	/**
	 * @param list<array<string,mixed>> $softConflicts
	 * @param list<mixed> $acknowledgements
	 */
	private function assertAcknowledgedSoftConflicts(array $softConflicts, array $acknowledgements): void
	{
		if ($acknowledgements === []) {
			throw new ConflictAckRequiredException($softConflicts);
		}
		$reasonByType = [];
		foreach ($acknowledgements as $ack) {
			if (!is_array($ack)) {
				continue;
			}
			$type = trim((string) ($ack['conflictType'] ?? ''));
			$reason = trim((string) ($ack['reason'] ?? ''));
			if ($type === '') {
				continue;
			}
			$reasonByType[$type] = $reason;
		}
		foreach ($softConflicts as $conflict) {
			$type = (string) ($conflict['type'] ?? '');
			$reason = $reasonByType[$type] ?? '';
			if (mb_strlen(trim($reason)) < 10) {
				throw new \InvalidArgumentException('REASON_TOO_SHORT');
			}
		}
	}

	public function createAbsence(array $payload, string $actor): array
	{
		$employeeId = (int) ($payload['employeeId'] ?? 0);
		$kind = trim((string) ($payload['kind'] ?? 'other'));
		$startDate = (string) ($payload['startDate'] ?? '');
		$endDate = (string) ($payload['endDate'] ?? '');
		$this->assertEmployeeExists($employeeId);
		if ($this->companies !== null) {
			$this->companies->assertRowCompany($actor, 'dc_employees', $employeeId);
		}
		$this->assertIntegrationAllowsDcAbsenceForEmployee($employeeId);
		$this->assertDate($startDate);
		$this->assertDate($endDate);
		if ($startDate > $endDate) {
			throw new \InvalidArgumentException('INVALID_ABSENCE_RANGE');
		}
		$allowedKinds = ['vacation', 'sick', 'training', 'unpaid', 'other'];
		if (!in_array($kind, $allowedKinds, true)) {
			throw new \InvalidArgumentException('INVALID_ABSENCE_KIND');
		}
		$this->assertNoAbsenceOverlapForStatus($employeeId, $startDate, $endDate, ['pending', 'approved']);

		$qb = $this->db->getQueryBuilder();
		$values = [
				'employee_id' => $qb->createNamedParameter($employeeId, IQueryBuilder::PARAM_INT),
				'kind' => $qb->createNamedParameter($kind),
				'start_date' => $qb->createNamedParameter($startDate),
				'end_date' => $qb->createNamedParameter($endDate),
				'status' => $qb->createNamedParameter('pending'),
				'created_by' => $qb->createNamedParameter($actor),
				'created_at' => $qb->createNamedParameter($this->now()),
		];
		if ($this->companies !== null && SchemaProbe::hasColumn($this->db, 'dc_absences', 'company_id')) {
			$values['company_id'] = $qb->createNamedParameter(
				$this->companies->writeCompanyIdFor($actor),
				IQueryBuilder::PARAM_INT,
			);
			// Prefer employee's company when multi-tenant so planners don't stamp wrong workspace.
			if ($this->companies->isMultiCompanyActive()) {
				$values['company_id'] = $qb->createNamedParameter(
					$this->readRowCompanyId('dc_employees', $employeeId),
					IQueryBuilder::PARAM_INT,
				);
			}
		}
		$qb->insert('dc_absences')->values($values)->executeStatement();

		return $this->listAbsences($actor);
	}

	public function transitionAbsence(int $absenceId, string $targetStatus, string $reviewReason = '', string $actorUserId = ''): array
	{
		$allowedStatuses = ['pending', 'approved', 'rejected', 'cancelled'];
		$targetStatus = trim($targetStatus);
		if (!in_array($targetStatus, $allowedStatuses, true)) {
			throw new \InvalidArgumentException('INVALID_ABSENCE_STATUS');
		}

		$current = $this->absenceById($absenceId);
		if ($actorUserId !== '' && $this->companies !== null) {
			$this->companies->assertRowCompany($actorUserId, 'dc_employees', (int) $current['employeeId']);
		}
		$this->assertIntegrationAllowsDcAbsenceForEmployee($current['employeeId'], $targetStatus);
		$allowedTransitions = [
			'pending' => ['approved', 'rejected', 'cancelled'],
			'approved' => ['cancelled'],
			'rejected' => ['pending'],
			'cancelled' => ['pending'],
		];
		if (!in_array($targetStatus, $allowedTransitions[$current['status']] ?? [], true)) {
			throw new \InvalidArgumentException('INVALID_ABSENCE_TRANSITION');
		}
		$trimmedReviewReason = trim($reviewReason);
		if (in_array($targetStatus, ['rejected', 'cancelled'], true) && mb_strlen($trimmedReviewReason) < 10) {
			throw new \InvalidArgumentException('REASON_TOO_SHORT');
		}
		if ($targetStatus === 'approved') {
			$this->assertNoAbsenceOverlapForStatus(
				$current['employeeId'],
				$current['startDate'],
				$current['endDate'],
				['approved'],
				$current['id'],
			);
		}

		$qb = $this->db->getQueryBuilder();
		$qb->update('dc_absences')
			->set('status', $qb->createNamedParameter($targetStatus))
			->set('review_reason', $qb->createNamedParameter($trimmedReviewReason !== '' ? $trimmedReviewReason : null))
			->set('reviewed_at', $qb->createNamedParameter($this->now()))
			->set('reviewed_by', $qb->createNamedParameter($actorUserId !== '' ? $actorUserId : null))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($absenceId, IQueryBuilder::PARAM_INT)))
			// CAS: concurrent approve/reject must not silently overwrite each other.
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter((string) $current['status'])));
		$affected = $qb->executeStatement();
		if ($affected !== 1) {
			throw new \InvalidArgumentException('ABSENCE_STATUS_CONFLICT');
		}

		return $this->listAbsences($actorUserId !== '' ? $actorUserId : null);
	}

	public function listAbsences(?string $actorUserId = null): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('a.id', 'a.employee_id', 'a.kind', 'a.start_date', 'a.end_date', 'a.status', 'a.review_reason', 'e.display_name')
			->from('dc_absences', 'a')
			->leftJoin('a', 'dc_employees', 'e', 'a.employee_id = e.id')
			->orderBy('a.start_date', 'DESC');
		if ($actorUserId !== null && $this->companies !== null) {
			if (SchemaProbe::hasColumn($this->db, 'dc_absences', 'company_id')) {
				$this->companies->restrictQuery($qb, 'a.company_id', $actorUserId);
			} elseif (SchemaProbe::hasColumn($this->db, 'dc_employees', 'company_id')) {
				$this->companies->restrictQuery($qb, 'e.company_id', $actorUserId);
			}
		}
		$rows = $qb->executeQuery()->fetchAll();

		$mapped = array_map(static fn (array $r): array => [
			'id' => (int) $r['id'],
			'source' => 'dutycheck',
			'employeeId' => (int) $r['employee_id'],
			'employeeName' => (string) ($r['display_name'] ?? ''),
			'kind' => (string) $r['kind'],
			'startDate' => (string) $r['start_date'],
			'endDate' => (string) $r['end_date'],
			'status' => (string) $r['status'],
			'reviewReason' => $r['review_reason'] !== null ? (string) $r['review_reason'] : '',
		], $rows);

		if ($this->atIntegration?->isEffective()) {
			$companyIds = null;
			if ($actorUserId !== null && $actorUserId !== '' && $this->companies !== null
				&& $this->companies->isMultiCompanyActive()) {
				$companyIds = $this->companies->companyIdsForUser($actorUserId);
			}
			$mirror = $this->atIntegration->listMirrorRowsForPlanner($companyIds);
			$mapped = array_merge($mapped, $mirror);
			usort($mapped, static function (array $a, array $b): int {
				$c = strcmp((string) ($b['startDate'] ?? ''), (string) ($a['startDate'] ?? ''));
				if ($c !== 0) {
					return $c;
				}
				$sa = ($a['source'] ?? '') === 'arbeitszeitcheck' ? (int) ($a['atAbsenceId'] ?? 0) : (int) ($a['id'] ?? 0);
				$sb = ($b['source'] ?? '') === 'arbeitszeitcheck' ? (int) ($b['atAbsenceId'] ?? 0) : (int) ($b['id'] ?? 0);
				return $sb <=> $sa;
			});
		}

		return $mapped;
	}

	public function listEmployeeCatalog(?string $actorUserId = null): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'display_name', 'linked_user_id', 'active', 'created_at')
			->from('dc_employees')
			->orderBy('display_name', 'ASC');
		if ($actorUserId !== null && $this->companies !== null) {
			$this->companies->restrictQuery($qb, 'company_id', $actorUserId);
		}
		$rows = $qb->executeQuery()->fetchAll();
		return array_map(static fn (array $r): array => [
			'id' => (int) $r['id'],
			'displayName' => (string) $r['display_name'],
			'linkedUserId' => $r['linked_user_id'] !== null ? (string) $r['linked_user_id'] : null,
			'active' => (int) $r['active'] === 1,
			'createdAt' => (string) $r['created_at'],
		], $rows);
	}

	public function countActiveEmployees(): int
	{
		return $this->count('dc_employees', 'active', 1);
	}

	/** Active employees with no linked Nextcloud account (DutyCheck-only absences until linked). */
	public function countActiveUnlinkedEmployees(): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'cnt'))
			->from('dc_employees')
			->where($qb->expr()->eq('active', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
			->andWhere(
				$qb->expr()->orX(
					$qb->expr()->isNull('linked_user_id'),
					$qb->expr()->eq('linked_user_id', $qb->createNamedParameter('')),
				),
			);
		return (int) $qb->executeQuery()->fetchOne();
	}

	public function createEmployee(array $payload, ?string $actorUserId = null): array
	{
		$linkedUserId = $this->normalizeLinkedUserId($payload['linkedUserId'] ?? null);
		$displayName = $this->resolveDisplayNameFromPayload($payload, $linkedUserId);
		$active = $this->toActiveFlag($payload['active'] ?? 1);

		$this->assertEmployeeDisplayNameUnique($displayName);
		$this->assertLinkedUserUnique($linkedUserId, null);

		$qb = $this->db->getQueryBuilder();
		$values = [
			'display_name' => $qb->createNamedParameter($displayName),
			'linked_user_id' => $qb->createNamedParameter($linkedUserId),
			'active' => $qb->createNamedParameter($active, IQueryBuilder::PARAM_INT),
			'created_at' => $qb->createNamedParameter($this->now()),
		];
		if ($this->companies !== null && $this->companies->schemaReady() && $actorUserId !== null) {
			$values['company_id'] = $qb->createNamedParameter(
				$this->companies->writeCompanyIdFor($actorUserId),
				IQueryBuilder::PARAM_INT,
			);
		}
		$qb->insert('dc_employees')->values($values)->executeStatement();

		return $this->listEmployeeCatalog($actorUserId);
	}

	public function updateEmployee(int $id, array $payload, ?string $actorUserId = null): array
	{
		if ($actorUserId !== null && $this->companies !== null) {
			$this->companies->assertRowCompany($actorUserId, 'dc_employees', $id);
		}
		$this->assertEmployeeRowExists($id);
		$currentLinkedUserId = $this->fetchEmployeeLinkedUserId($id);
		$linkedUserId = $this->normalizeLinkedUserId($payload['linkedUserId'] ?? null, $currentLinkedUserId);
		$displayName = $this->resolveDisplayNameFromPayload($payload, $linkedUserId);
		$active = $this->toActiveFlag($payload['active'] ?? 1);

		$this->assertEmployeeDisplayNameUnique($displayName, $id);
		$this->assertLinkedUserUnique($linkedUserId, $id);
		if ($this->atIntegration?->isEffective() && $linkedUserId !== null && trim($linkedUserId) !== '') {
			$legacy = $this->atIntegration->countLegacyAbsencesForEmployee($id);
			if ($legacy > 0) {
				throw new IntegrationLegacyConflictException($legacy);
			}
		}

		$qb = $this->db->getQueryBuilder();
		$qb->update('dc_employees')
			->set('display_name', $qb->createNamedParameter($displayName))
			->set('linked_user_id', $qb->createNamedParameter($linkedUserId))
			->set('active', $qb->createNamedParameter($active, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->executeStatement();

		return $this->listEmployeeCatalog($actorUserId);
	}

	public function listLocationCatalog(?string $actorUserId = null): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'name', 'timezone', 'active', 'created_at')
			->from('dc_locations')
			->orderBy('name', 'ASC');
		if ($actorUserId !== null && $this->companies !== null) {
			$this->companies->restrictQuery($qb, 'company_id', $actorUserId);
		}
		$rows = $qb->executeQuery()->fetchAll();
		return array_map(static fn (array $r): array => [
			'id' => (int) $r['id'],
			'name' => (string) $r['name'],
			'timezone' => (string) $r['timezone'],
			'active' => (int) $r['active'] === 1,
			'createdAt' => (string) $r['created_at'],
		], $rows);
	}

	public function createLocation(array $payload, ?string $actorUserId = null): array
	{
		$name = $this->validateSimpleLabel((string) ($payload['name'] ?? ''), 'INVALID_LOCATION_NAME');
		$timezone = $this->validateTimezone((string) ($payload['timezone'] ?? ''));
		$active = $this->toActiveFlag($payload['active'] ?? 1);
		$this->assertLocationNameUnique($name);

		$qb = $this->db->getQueryBuilder();
		$values = [
			'name' => $qb->createNamedParameter($name),
			'timezone' => $qb->createNamedParameter($timezone),
			'active' => $qb->createNamedParameter($active, IQueryBuilder::PARAM_INT),
			'created_at' => $qb->createNamedParameter($this->now()),
		];
		if ($this->companies !== null && $this->companies->schemaReady() && $actorUserId !== null) {
			$values['company_id'] = $qb->createNamedParameter(
				$this->companies->writeCompanyIdFor($actorUserId),
				IQueryBuilder::PARAM_INT,
			);
		}
		$qb->insert('dc_locations')->values($values)->executeStatement();

		return $this->listLocationCatalog($actorUserId);
	}

	public function updateLocation(int $id, array $payload, ?string $actorUserId = null): array
	{
		if ($actorUserId !== null && $this->companies !== null) {
			$this->companies->assertRowCompany($actorUserId, 'dc_locations', $id);
		}
		$this->assertLocationRowExists($id);
		$name = $this->validateSimpleLabel((string) ($payload['name'] ?? ''), 'INVALID_LOCATION_NAME');
		$timezone = $this->validateTimezone((string) ($payload['timezone'] ?? ''));
		$active = $this->toActiveFlag($payload['active'] ?? 1);
		$this->assertLocationNameUnique($name, $id);

		$qb = $this->db->getQueryBuilder();
		$qb->update('dc_locations')
			->set('name', $qb->createNamedParameter($name))
			->set('timezone', $qb->createNamedParameter($timezone))
			->set('active', $qb->createNamedParameter($active, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->executeStatement();

		return $this->listLocationCatalog($actorUserId);
	}

	/**
	 * Return published assignments for the given user's linked employee in
	 * the requested date window.
	 *
	 * Both bounds are inclusive ISO dates (YYYY-MM-DD). Defaults are sized
	 * to be useful out of the box: from = today, to = today + 365 days.
	 * The bounds are clamped to today + max 365 days in the past and today
	 * + 730 days in the future so a malicious or buggy caller cannot scan
	 * unbounded history.
	 */
	public function myRoster(string $userId, ?string $from = null, ?string $to = null): array
	{
		$employeeId = $this->linkedEmployeeIdByUserId($userId);
		$today = new DateTimeImmutable('today');
		$fromDate = $this->parseRosterBoundary($from) ?? $today;
		$toDate = $this->parseRosterBoundary($to) ?? $today->modify('+365 days');
		$minDate = $today->modify('-365 days');
		$maxDate = $today->modify('+730 days');
		if ($fromDate < $minDate) {
			$fromDate = $minDate;
		}
		if ($toDate > $maxDate) {
			$toDate = $maxDate;
		}
		if ($toDate < $fromDate) {
			$toDate = $fromDate;
		}
		$fromIso = $fromDate->format('Y-m-d');
		$toIso = $toDate->format('Y-m-d');

		$qb = $this->db->getQueryBuilder();
		$qb->select('a.id', 'a.duty_date', 'a.start_time', 'a.end_time', 'a.break_minutes', 'a.note', 'a.period_id', 'l.name AS location_name', 'p.status AS period_status')
			->from('dc_assignments', 'a')
			->innerJoin('a', 'dc_periods', 'p', 'a.period_id = p.id')
			->leftJoin('a', 'dc_locations', 'l', 'a.location_id = l.id')
			->where($qb->expr()->eq('a.employee_id', $qb->createNamedParameter($employeeId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->in('p.status', $qb->createNamedParameter(['published', 'closed'], IQueryBuilder::PARAM_STR_ARRAY)))
			->andWhere($qb->expr()->gte('a.duty_date', $qb->createNamedParameter($fromIso)))
			->andWhere($qb->expr()->lte('a.duty_date', $qb->createNamedParameter($toIso)));
		if ($this->assignmentHasStatusColumn()) {
			$qb->andWhere($qb->expr()->orX(
				$qb->expr()->neq('a.status', $qb->createNamedParameter('cancelled')),
				$qb->expr()->isNull('a.status'),
			));
			$qb->addSelect('a.acknowledged_at', 'a.acknowledged_by');
		}
		$qb->orderBy('a.duty_date', 'ASC')
			->addOrderBy('a.start_time', 'ASC');
		$rows = $qb->executeQuery()->fetchAll();

		$hasStatus = $this->assignmentHasStatusColumn();
		return array_map(static function (array $r) use ($hasStatus): array {
			return [
				'id' => (int) $r['id'],
				'periodId' => (int) ($r['period_id'] ?? 0),
				'periodStatus' => (string) ($r['period_status'] ?? 'published'),
				'dutyDate' => (string) $r['duty_date'],
				'startTime' => (string) $r['start_time'],
				'endTime' => (string) $r['end_time'],
				'breakMinutes' => (int) $r['break_minutes'],
				'note' => (string) ($r['note'] ?? ''),
				'locationName' => (string) ($r['location_name'] ?? ''),
				'acknowledgedAt' => ($hasStatus && ($r['acknowledged_at'] ?? null) !== null) ? (string) $r['acknowledged_at'] : null,
				'acknowledged' => $hasStatus && ($r['acknowledged_at'] ?? null) !== null,
			];
		}, $rows);
	}

	/**
	 * Parse a YYYY-MM-DD string into a DateTimeImmutable. Returns null for
	 * empty / null / malformed input so callers can fall back to defaults.
	 */
	private function parseRosterBoundary(?string $value): ?DateTimeImmutable
	{
		if ($value === null) {
			return null;
		}
		$trim = trim($value);
		if ($trim === '') {
			return null;
		}
		if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $trim) !== 1) {
			return null;
		}
		$date = DateTimeImmutable::createFromFormat('!Y-m-d', $trim);
		if ($date === false) {
			return null;
		}
		// `!` resets time to 00:00; normalise just in case PHP changes that.
		return $date->setTime(0, 0, 0);
	}

	public function myAbsences(string $userId): array
	{
		$employeeId = $this->linkedEmployeeIdByUserId($userId);
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'kind', 'start_date', 'end_date', 'status', 'review_reason')
			->from('dc_absences')
			->where($qb->expr()->eq('employee_id', $qb->createNamedParameter($employeeId, IQueryBuilder::PARAM_INT)))
			->orderBy('start_date', 'DESC')
			->addOrderBy('id', 'DESC');
		$rows = $qb->executeQuery()->fetchAll();

		$mapped = array_map(static fn (array $r): array => [
			'id' => (int) $r['id'],
			'source' => 'dutycheck',
			'kind' => (string) $r['kind'],
			'startDate' => (string) $r['start_date'],
			'endDate' => (string) $r['end_date'],
			'status' => (string) $r['status'],
			'reviewReason' => $r['review_reason'] !== null ? (string) $r['review_reason'] : '',
		], $rows);

		if ($this->atIntegration?->isEffective()) {
			$linkedUid = $this->linkedUserIdForEmployeeId($employeeId);
			if ($linkedUid !== null && $linkedUid !== '') {
				$mapped = array_merge($mapped, $this->atIntegration->listMirrorRowsForEmployee($linkedUid));
				usort($mapped, static function (array $a, array $b): int {
					$c = strcmp((string) ($b['startDate'] ?? ''), (string) ($a['startDate'] ?? ''));
					if ($c !== 0) {
						return $c;
					}
					$sa = ($a['source'] ?? '') === 'arbeitszeitcheck' ? (int) ($a['atAbsenceId'] ?? 0) : (int) ($a['id'] ?? 0);
					$sb = ($b['source'] ?? '') === 'arbeitszeitcheck' ? (int) ($b['atAbsenceId'] ?? 0) : (int) ($b['id'] ?? 0);
					return $sb <=> $sa;
				});
			}
		}

		return $mapped;
	}

	/**
	 * Employee-visible absence list for the current user only (never the planner-wide catalog).
	 */
	public function createMyAbsence(string $userId, array $payload): array
	{
		$employeeId = $this->linkedEmployeeIdByUserId($userId);
		if ($this->atIntegration?->integrationLocksLinkedDutyCheckAbsences()) {
			throw new \InvalidArgumentException('INTEGRATION_ABSENCE_READONLY');
		}
		$this->createAbsence([
			'employeeId' => $employeeId,
			'kind' => $payload['kind'] ?? 'other',
			'startDate' => $payload['startDate'] ?? '',
			'endDate' => $payload['endDate'] ?? '',
		], $userId);

		return $this->myAbsences($userId);
	}

	public function myIcalTokenMeta(string $userId): array
	{
		$employeeId = $this->linkedEmployeeIdByUserId($userId);
		$tokenHash = $this->readUserPreference($userId, 'ical_token_hash');
		return [
			'employeeId' => $employeeId,
			'hasToken' => $tokenHash !== null,
			'icalUrl' => $tokenHash !== null
				? $this->icalUrlForEmployee($employeeId, '__TOKEN__')
				: null,
		];
	}

	public function rotateMyIcalToken(string $userId): array
	{
		$employeeId = $this->linkedEmployeeIdByUserId($userId);
		$token = bin2hex(random_bytes(24));
		$hash = hash('sha256', $employeeId . ':' . $token);
		$this->writeUserPreference($userId, 'ical_token_hash', $hash);
		return [
			'employeeId' => $employeeId,
			'token' => $token,
			'icalUrl' => $this->icalUrlForEmployee($employeeId, $token),
		];
	}

	public function publicIcal(int $employeeId, string $token, string $remoteAddress = ''): string
	{
		if ($employeeId < 1 || !$this->isValidIcalToken($token)) {
			throw new \InvalidArgumentException('ICAL_TOKEN_INVALID');
		}
		$this->assertIcalRequestAllowed($employeeId, $remoteAddress);
		try {
			$userId = $this->linkedUserIdByEmployeeId($employeeId);
		} catch (\InvalidArgumentException) {
			// Same wire code as a bad token — do not reveal whether the employee exists / is linked.
			throw new \InvalidArgumentException('ICAL_TOKEN_INVALID');
		}
		$storedHash = $this->readUserPreference($userId, 'ical_token_hash');
		if ($storedHash === null || !hash_equals($storedHash, hash('sha256', $employeeId . ':' . $token))) {
			throw new \InvalidArgumentException('ICAL_TOKEN_INVALID');
		}

		$rows = $this->publishedAssignmentsForEmployee($employeeId);
		$ics = [
			'BEGIN:VCALENDAR',
			'VERSION:2.0',
			'PRODID:-//DutyCheck//Duty Roster//EN',
			'CALSCALE:GREGORIAN',
			'METHOD:PUBLISH',
		];
		foreach ($rows as $row) {
			$startUtc = $this->toUtcDateTime((string) $row['duty_date'], (string) $row['start_time']);
			$endUtc = $this->toUtcDateTime((string) $row['duty_date'], (string) $row['end_time']);
			if ($endUtc <= $startUtc) {
				$endUtc = $endUtc->modify('+1 day');
			}
			$uid = sprintf('dc-%d-%d@dutycheck.local', (int) $row['id'], $employeeId);
			$summary = $this->icsEscape('Duty shift - ' . (string) ($row['location_name'] ?? 'Location'));
			$description = $this->icsEscape('Break: ' . (int) $row['break_minutes'] . ' min');
			$ics[] = 'BEGIN:VEVENT';
			$ics[] = 'UID:' . $uid;
			$ics[] = 'DTSTAMP:' . gmdate('Ymd\THis\Z');
			$ics[] = 'DTSTART:' . $startUtc->format('Ymd\THis\Z');
			$ics[] = 'DTEND:' . $endUtc->format('Ymd\THis\Z');
			$ics[] = 'SUMMARY:' . $summary;
			$ics[] = 'DESCRIPTION:' . $description;
			$ics[] = 'END:VEVENT';
		}
		$ics[] = 'END:VCALENDAR';
		return implode("\r\n", $ics) . "\r\n";
	}

	private function assertIcalRequestAllowed(int $employeeId, string $remoteAddress): void
	{
		$ipHash = hash('sha256', trim($remoteAddress) !== '' ? trim($remoteAddress) : 'unknown');
		$windowStart = (new DateTimeImmutable('now -60 seconds'))->format('Y-m-d H:i:s');
		$now = $this->now();
		$cleanup = $this->db->getQueryBuilder();
		$cleanup->delete('dc_api_rate_limits')
			->where($cleanup->expr()->lt('created_at', $cleanup->createNamedParameter($windowStart)))
			->executeStatement();

		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'cnt'))
			->from('dc_api_rate_limits')
			->where($qb->expr()->eq('bucket_key', $qb->createNamedParameter('ical:' . $employeeId . ':' . $ipHash)));
		$current = (int) $qb->executeQuery()->fetchOne();
		if ($current >= 60) {
			throw new \InvalidArgumentException('RATE_LIMITED');
		}

		$insert = $this->db->getQueryBuilder();
		$insert->insert('dc_api_rate_limits')
			->values([
				'bucket_key' => $insert->createNamedParameter('ical:' . $employeeId . ':' . $ipHash),
				'created_at' => $insert->createNamedParameter($now),
			])->executeStatement();
	}

	private function isValidIcalToken(string $token): bool
	{
		$trimmed = trim($token);
		return preg_match('/^[a-f0-9]{48}$/', $trimmed) === 1;
	}

	private function periodById(int $periodId): array
	{
		$qb = $this->db->getQueryBuilder();
		$select = ['id', 'start_date', 'end_date', 'status', 'created_by', 'created_at', 'published_at', 'closed_at', 'close_snapshot_id'];
		if ($this->periodHasFrozenThresholdsColumn()) {
			$select[] = 'conflict_thresholds_json';
		}
		$qb->select(...$select)
			->from('dc_periods')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($periodId, IQueryBuilder::PARAM_INT)));
		$row = $qb->executeQuery()->fetch();
		if ($row === false) {
			throw new \InvalidArgumentException('PERIOD_NOT_FOUND');
		}

		return $this->normalizePeriod($row);
	}

	private function normalizePeriod(array $r): array
	{
		$out = [
			'id' => (int) $r['id'],
			'startDate' => (string) $r['start_date'],
			'endDate' => (string) $r['end_date'],
			'status' => (string) $r['status'],
			'createdBy' => (string) $r['created_by'],
			'createdAt' => (string) $r['created_at'],
			'publishedAt' => $r['published_at'] !== null ? (string) $r['published_at'] : null,
			'closedAt' => $r['closed_at'] !== null ? (string) $r['closed_at'] : null,
			'closeSnapshotId' => $r['close_snapshot_id'] !== null ? (int) $r['close_snapshot_id'] : null,
		];
		if (array_key_exists('conflict_thresholds_json', $r)) {
			$out['conflictThresholds'] = $this->decodeFrozenThresholds($r['conflict_thresholds_json'] ?? null);
		}
		return $out;
	}

	private function createSnapshot(int $periodId, string $snapshotKind, string $actorUserId): int
	{
		$period = $this->periodById($periodId);
		$assignments = $this->listAssignments($periodId);
		$conflicts = $this->conflictsForPeriod($periodId);
		$importedAbsences = [];
		try {
			if ($this->atIntegration !== null) {
				$companyId = null;
				if ($this->companies !== null && $this->companies->schemaReady()
					&& SchemaProbe::hasColumn($this->db, 'dc_periods', 'company_id')) {
					$companyId = $this->readRowCompanyId('dc_periods', $periodId);
				}
				$importedAbsences = $this->atIntegration->listImportedAbsencesForPeriodSnapshot(
					(string) $period['startDate'],
					(string) $period['endDate'],
					$companyId,
				);
			}
		} catch (\Error) {
			$importedAbsences = [];
		}
		$payload = [
			'period' => $period,
			'assignments' => $assignments,
			'conflicts' => $conflicts,
			// WF-23: T1/T2 only — never reason / approver_comment.
			'importedAbsences' => $importedAbsences,
			'generatedAt' => (new DateTimeImmutable('now'))->format('Y-m-d\TH:i:s\Z'),
			'kind' => $snapshotKind,
		];
		$canonicalJson = $this->canonicalizeJson($payload);
		$hash = hash('sha256', $canonicalJson);
		$previousClose = $this->latestCloseSnapshot($periodId);

		$qb = $this->db->getQueryBuilder();
		$qb->insert('dc_roster_snapshots')
			->values([
				'period_id' => $qb->createNamedParameter($periodId, IQueryBuilder::PARAM_INT),
				'snapshot_kind' => $qb->createNamedParameter($snapshotKind),
				'snapshot_json' => $qb->createNamedParameter($canonicalJson),
				'snapshot_hash' => $qb->createNamedParameter($hash),
				'meta_json' => $qb->createNamedParameter(json_encode([
					'assignmentCount' => count($assignments),
					'conflictCount' => count($conflicts),
					'importedAbsenceCount' => count($importedAbsences),
					'hardConflicts' => count(array_filter($conflicts, static fn (array $item): bool => ($item['severity'] ?? '') === 'hard')),
				], JSON_THROW_ON_ERROR)),
				'prev_snapshot_id' => $qb->createNamedParameter($snapshotKind === 'close' ? ($previousClose['id'] ?? null) : null, IQueryBuilder::PARAM_INT),
				'prev_snapshot_hash' => $qb->createNamedParameter($snapshotKind === 'close' ? ($previousClose['hash'] ?? null) : null),
				'generated_at' => $qb->createNamedParameter($this->now()),
				'generated_by' => $qb->createNamedParameter($actorUserId),
			])->executeStatement();

		return (int) $qb->getLastInsertId();
	}

	private function latestCloseSnapshot(int $periodId): ?array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'snapshot_hash')
			->from('dc_roster_snapshots')
			->where($qb->expr()->eq('period_id', $qb->createNamedParameter($periodId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('snapshot_kind', $qb->createNamedParameter('close')))
			->orderBy('generated_at', 'DESC')
			->addOrderBy('id', 'DESC')
			->setMaxResults(1);
		$row = $qb->executeQuery()->fetch();
		if ($row === false) {
			return null;
		}
		return [
			'id' => (int) $row['id'],
			'hash' => (string) $row['snapshot_hash'],
		];
	}

	private function snapshotRowsForPeriod(int $periodId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'snapshot_kind', 'snapshot_json', 'snapshot_hash', 'prev_snapshot_id', 'prev_snapshot_hash', 'generated_at')
			->from('dc_roster_snapshots')
			->where($qb->expr()->eq('period_id', $qb->createNamedParameter($periodId, IQueryBuilder::PARAM_INT)))
			->orderBy('generated_at', 'ASC')
			->addOrderBy('id', 'ASC');
		return $qb->executeQuery()->fetchAll();
	}

	private function canonicalizeJson(array $payload): string
	{
		$sorted = $this->ksortRecursive($payload);
		return json_encode($sorted, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	}

	private function ksortRecursive(array $value): array
	{
		foreach ($value as $key => $item) {
			if (is_array($item)) {
				$value[$key] = $this->ksortRecursive($item);
			}
		}
		if ($this->isAssocArray($value)) {
			ksort($value);
		}
		return $value;
	}

	private function isAssocArray(array $value): bool
	{
		if ($value === []) {
			return false;
		}
		return array_keys($value) !== range(0, count($value) - 1);
	}

	private function writeAuditEvent(
		?int $periodId,
		string $actorUserId,
		string $action,
		string $targetKind,
		?int $targetId,
		array $payload
	): void {
		$qb = $this->db->getQueryBuilder();
		$qb->insert('dc_period_audit_log')
			->values([
				'period_id' => $qb->createNamedParameter($periodId, IQueryBuilder::PARAM_INT),
				'actor_user_id' => $qb->createNamedParameter($actorUserId),
				'action' => $qb->createNamedParameter($action),
				'target_kind' => $qb->createNamedParameter($targetKind),
				'target_id' => $qb->createNamedParameter($targetId, IQueryBuilder::PARAM_INT),
				'payload_json' => $qb->createNamedParameter(json_encode($payload, JSON_THROW_ON_ERROR)),
				'created_at' => $qb->createNamedParameter($this->now()),
			])->executeStatement();
	}

	private function listEmployees(?string $actorUserId = null): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'display_name', 'linked_user_id')
			->from('dc_employees')
			->where($qb->expr()->eq('active', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
			->orderBy('display_name', 'ASC');
		if ($actorUserId !== null && $this->companies !== null) {
			$this->companies->restrictQuery($qb, 'company_id', $actorUserId);
		}
		$rows = $qb->executeQuery()->fetchAll();
		return array_map(static function (array $r): array {
			$link = $r['linked_user_id'];
			$linkStr = $link !== null ? trim((string) $link) : '';
			return [
				'id' => (int) $r['id'],
				'name' => (string) $r['display_name'],
				'linkedUserId' => $linkStr !== '' ? $linkStr : null,
			];
		}, $rows);
	}

	private function listLocations(?string $actorUserId = null): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'name', 'timezone')
			->from('dc_locations')
			->where($qb->expr()->eq('active', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
			->orderBy('name', 'ASC');
		if ($actorUserId !== null && $this->companies !== null) {
			$this->companies->restrictQuery($qb, 'company_id', $actorUserId);
		}
		$rows = $qb->executeQuery()->fetchAll();
		return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'name' => (string) $r['name'], 'timezone' => (string) $r['timezone']], $rows);
	}

	private function listAssignments(int $periodId): array
	{
		$qb = $this->db->getQueryBuilder();
		$hasStatus = $this->assignmentHasStatusColumn();
		$hasVersion = $this->assignmentHasVersionColumn();
		$select = ['a.id', 'a.period_id', 'a.employee_id', 'a.location_id', 'a.duty_date', 'a.start_time', 'a.end_time', 'a.break_minutes', 'a.note', 'e.display_name', 'l.name AS location_name'];
		if ($hasStatus) {
			$select[] = 'a.status';
			$select[] = 'a.acknowledged_at';
			$select[] = 'a.acknowledged_by';
		}
		if ($hasVersion) {
			$select[] = 'a.version';
		}
		$qb->select(...$select)
			->from('dc_assignments', 'a')
			->leftJoin('a', 'dc_employees', 'e', 'a.employee_id = e.id')
			->leftJoin('a', 'dc_locations', 'l', 'a.location_id = l.id')
			->where($qb->expr()->eq('a.period_id', $qb->createNamedParameter($periodId, IQueryBuilder::PARAM_INT)));
		if ($hasStatus) {
			$qb->andWhere($qb->expr()->orX(
				$qb->expr()->neq('a.status', $qb->createNamedParameter('cancelled')),
				$qb->expr()->isNull('a.status'),
			));
		}
		$qb->orderBy('a.duty_date', 'ASC')->addOrderBy('a.start_time', 'ASC');
		$rows = $qb->executeQuery()->fetchAll();
		return array_map(static function (array $r) use ($hasStatus, $hasVersion): array {
			return [
				'id' => (int) $r['id'],
				'periodId' => (int) $r['period_id'],
				'employeeId' => (int) $r['employee_id'],
				'employeeName' => (string) ($r['display_name'] ?? ''),
				'locationId' => (int) $r['location_id'],
				'locationName' => (string) ($r['location_name'] ?? ''),
				'dutyDate' => (string) $r['duty_date'],
				'startTime' => (string) $r['start_time'],
				'endTime' => (string) $r['end_time'],
				'breakMinutes' => (int) $r['break_minutes'],
				'note' => (string) ($r['note'] ?? ''),
				'status' => $hasStatus ? (string) ($r['status'] ?? 'active') : 'active',
				'acknowledgedAt' => ($hasStatus && ($r['acknowledged_at'] ?? null) !== null) ? (string) $r['acknowledged_at'] : null,
				'acknowledgedBy' => ($hasStatus && ($r['acknowledged_by'] ?? null) !== null) ? (string) $r['acknowledged_by'] : null,
				'version' => $hasVersion ? (int) ($r['version'] ?? 0) : 0,
			];
		}, $rows);
	}

	private function assignmentHasStatusColumn(): bool
	{
		return SchemaProbe::hasColumn($this->db, 'dc_assignments', 'status');
	}

	private function assignmentHasVersionColumn(): bool
	{
		return SchemaProbe::hasColumn($this->db, 'dc_assignments', 'version');
	}

	private function assignmentHasSlotKeyColumn(): bool
	{
		return SchemaProbe::hasColumn($this->db, 'dc_assignments', 'slot_key');
	}

	private function periodHasFrozenThresholdsColumn(): bool
	{
		return SchemaProbe::hasColumn($this->db, 'dc_periods', 'conflict_thresholds_json');
	}

	/**
	 * Prefer frozen per-period thresholds; fall back to live policy for legacy periods.
	 *
	 * @return array{maxDailyHard:int,maxPeriodSoft:int,maxPeriodHard:int,maxConsecutiveDays:int,minRestMinutes:int}
	 */
	private function policyThresholdsForPeriod(int $periodId): array
	{
		if ($this->periodHasFrozenThresholdsColumn()) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('conflict_thresholds_json')
				->from('dc_periods')
				->where($qb->expr()->eq('id', $qb->createNamedParameter($periodId, IQueryBuilder::PARAM_INT)));
			$raw = $qb->executeQuery()->fetchOne();
			$decoded = $this->decodeFrozenThresholds($raw === false ? null : $raw);
			if ($decoded !== null) {
				return $decoded;
			}
		}
		return $this->policyThresholds();
	}

	/**
	 * @return array{maxDailyHard:int,maxPeriodSoft:int,maxPeriodHard:int,maxConsecutiveDays:int,minRestMinutes:int}|null
	 */
	private function decodeFrozenThresholds(mixed $raw): ?array
	{
		if (!is_string($raw) || trim($raw) === '') {
			return null;
		}
		try {
			$decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
		} catch (\JsonException) {
			return null;
		}
		if (!is_array($decoded)) {
			return null;
		}
		$defaults = ConflictPolicyService::defaults();
		return [
			'maxDailyHard' => max(1, (int) ($decoded['maxDailyHard'] ?? $defaults['maxDailyHard'])),
			'maxPeriodSoft' => max(1, (int) ($decoded['maxPeriodSoft'] ?? $defaults['maxPeriodSoft'])),
			'maxPeriodHard' => max(1, (int) ($decoded['maxPeriodHard'] ?? $defaults['maxPeriodHard'])),
			'maxConsecutiveDays' => max(1, (int) ($decoded['maxConsecutiveDays'] ?? $defaults['maxConsecutiveDays'])),
			'minRestMinutes' => max(0, (int) ($decoded['minRestMinutes'] ?? $defaults['minRestMinutes'])),
		];
	}

	private function conflictsForPeriod(int $periodId): array
	{
		$period = $this->periodById($periodId);
		$assignments = $this->listAssignments($periodId);
		$conflicts = [];
		$conflictDedup = [];
		$thresholds = $this->policyThresholdsForPeriod($periodId);
		$count = count($assignments);
		for ($i = 0; $i < $count; $i++) {
			for ($j = $i + 1; $j < $count; $j++) {
				$a = $assignments[$i];
				$b = $assignments[$j];
				if ($a['employeeId'] !== $b['employeeId']) {
					continue;
				}
				$aRange = $this->assignmentAbsoluteRange((string) $a['dutyDate'], (string) $a['startTime'], (string) $a['endTime']);
				$bRange = $this->assignmentAbsoluteRange((string) $b['dutyDate'], (string) $b['startTime'], (string) $b['endTime']);
				if ($this->absoluteRangesOverlap($aRange, $bRange)) {
					$key = 'double_booking:' . min($a['id'], $b['id']) . ':' . max($a['id'], $b['id']);
					if (isset($conflictDedup[$key])) {
						continue;
					}
					$conflictDedup[$key] = true;
					$conflicts[] = [
						'type' => 'double_booking',
						'severity' => 'hard',
						'message' => 'Employee has overlapping assignments (double booking)',
						'employeeId' => (int) $a['employeeId'],
						'assignmentIds' => [$a['id'], $b['id']],
					];
				}
				$gapMinutes = $this->minutesBetweenRanges($aRange, $bRange);
				if ($gapMinutes >= 0 && $gapMinutes < $thresholds['minRestMinutes']) {
					$key = 'rest_time_violation:' . min($a['id'], $b['id']) . ':' . max($a['id'], $b['id']);
					if (isset($conflictDedup[$key])) {
						continue;
					}
					$conflictDedup[$key] = true;
					$conflicts[] = [
						'type' => 'rest_time_violation',
						'severity' => 'soft',
						'message' => 'Less than 11 hours rest between consecutive assignments',
						'employeeId' => (int) $a['employeeId'],
						'assignmentIds' => [$a['id'], $b['id']],
						'details' => ['restMinutes' => $gapMinutes, 'minRestMinutes' => $thresholds['minRestMinutes']],
					];
				}
			}
		}

		$byEmployee = [];
		foreach ($assignments as $assignment) {
			$employeeId = (int) $assignment['employeeId'];
			if (!isset($byEmployee[$employeeId])) {
				$byEmployee[$employeeId] = [];
			}
			$byEmployee[$employeeId][] = $assignment;
			$effectiveMinutes = $this->effectiveMinutes(
				(string) $assignment['startTime'],
				(string) $assignment['endTime'],
				(int) $assignment['breakMinutes']
			);
			if ($effectiveMinutes > $thresholds['maxDailyHard']) {
				$key = 'shift_too_long:' . (int) $assignment['id'];
				if (!isset($conflictDedup[$key])) {
					$conflictDedup[$key] = true;
					$conflicts[] = [
						'type' => 'shift_too_long',
						'severity' => 'soft',
						'message' => 'Shift exceeds configured daily threshold',
						'employeeId' => (int) $assignment['employeeId'],
						'assignmentIds' => [(int) $assignment['id']],
						'details' => ['effectiveMinutes' => $effectiveMinutes, 'maxDailyHard' => $thresholds['maxDailyHard']],
					];
				}
			}
		}

		foreach ($byEmployee as $employeeId => $employeeAssignments) {
			usort($employeeAssignments, static function (array $a, array $b): int {
				$cmp = strcmp((string) $a['dutyDate'], (string) $b['dutyDate']);
				if ($cmp !== 0) {
					return $cmp;
				}
				return strcmp((string) $a['startTime'], (string) $b['startTime']);
			});

			$totalMinutes = 0;
			$dates = [];
			foreach ($employeeAssignments as $assignment) {
				$totalMinutes += $this->effectiveMinutes(
					(string) $assignment['startTime'],
					(string) $assignment['endTime'],
					(int) $assignment['breakMinutes']
				);
				$dates[(string) $assignment['dutyDate']] = true;
			}

			if ($totalMinutes > $thresholds['maxPeriodHard']) {
				$key = 'period_total_hard_cap:' . $employeeId;
					if (!isset($conflictDedup[$key])) {
						$conflictDedup[$key] = true;
						$conflicts[] = [
							'type' => 'period_total_hard_cap',
							'severity' => 'hard',
							'message' => 'Period total hard cap exceeded for employee',
							'employeeId' => (int) $employeeId,
							'assignmentIds' => array_map(static fn (array $item): int => (int) $item['id'], $employeeAssignments),
							'details' => ['totalMinutes' => $totalMinutes, 'maxPeriodHard' => $thresholds['maxPeriodHard']],
						];
					}
			} elseif ($totalMinutes > $thresholds['maxPeriodSoft']) {
				$key = 'period_total_soft_cap:' . $employeeId;
					if (!isset($conflictDedup[$key])) {
						$conflictDedup[$key] = true;
						$conflicts[] = [
							'type' => 'period_total_soft_cap',
							'severity' => 'soft',
							'message' => 'Period total soft cap exceeded for employee',
							'employeeId' => (int) $employeeId,
							'assignmentIds' => array_map(static fn (array $item): int => (int) $item['id'], $employeeAssignments),
							'details' => ['totalMinutes' => $totalMinutes, 'maxPeriodSoft' => $thresholds['maxPeriodSoft']],
						];
					}
			}

			$dateList = array_keys($dates);
			sort($dateList);
			$streak = 0;
			$lastDay = null;
			foreach ($dateList as $date) {
				if ($lastDay === null) {
					$streak = 1;
					$lastDay = $date;
					continue;
				}
				$expected = (new DateTimeImmutable($lastDay))->modify('+1 day')->format('Y-m-d');
				if ($date === $expected) {
					$streak++;
				} else {
					$streak = 1;
				}
				$lastDay = $date;
				if ($streak > $thresholds['maxConsecutiveDays']) {
					$key = 'consecutive_days_exceeded:' . $employeeId;
					if (!isset($conflictDedup[$key])) {
						$conflictDedup[$key] = true;
						$conflicts[] = [
							'type' => 'consecutive_days_exceeded',
							'severity' => 'soft',
							'message' => 'Employee is scheduled for too many consecutive days',
							'employeeId' => (int) $employeeId,
							'assignmentIds' => array_map(static fn (array $item): int => (int) $item['id'], $employeeAssignments),
							'details' => ['consecutiveDays' => $streak, 'maxConsecutiveDays' => $thresholds['maxConsecutiveDays']],
						];
					}
					break;
				}
			}
		}

		foreach ($assignments as $assignment) {
			$collisionSource = $this->absenceCollisionSource(
				(int) $assignment['employeeId'],
				(string) $assignment['dutyDate'],
			);
			if ($collisionSource === null) {
				continue;
			}
			$key = 'absence_collision:' . $assignment['id'];
			if (isset($conflictDedup[$key])) {
				continue;
			}
			$conflictDedup[$key] = true;
			$conflict = [
				'type' => 'absence_collision',
				'severity' => 'hard',
				'source' => $collisionSource,
				'message' => $collisionSource === 'arbeitszeitcheck'
					? 'Employee assignment collides with an ArbeitszeitCheck absence'
					: 'Employee assignment collides with approved absence',
				'employeeId' => (int) $assignment['employeeId'],
				'assignmentIds' => [(int) $assignment['id']],
			];
			if ($collisionSource === 'arbeitszeitcheck') {
				$url = null;
				try {
					$url = $this->atIntegration?->getPlannerOutboundUrl();
				} catch (\Throwable) {
					$url = null;
				}
				if (is_string($url) && $url !== '' && $url !== '#') {
					$conflict['recoveryUrl'] = $url;
					$conflict['recoveryLabel'] = 'Open ArbeitszeitCheck';
				}
			}
			$conflicts[] = $conflict;
		}

		$overlappingPeriods = $this->findOverlappingPeriods((string) $period['startDate'], (string) $period['endDate'], $periodId);
		if (!empty($overlappingPeriods)) {
			$conflicts[] = [
				'type' => 'period_overlap',
				'severity' => 'soft',
				'message' => 'Period overlaps with another planning period',
				'employeeId' => 0,
				'periodIds' => array_map(static fn (array $item): int => (int) $item['id'], $overlappingPeriods),
			];
		}

		foreach ($assignments as $assignment) {
			foreach ($this->qualificationConflicts(
				(int) $assignment['employeeId'],
				(int) $assignment['locationId'],
				(string) $assignment['dutyDate'],
			) as $qc) {
				$key = ($qc['type'] ?? 'qualification') . ':' . (int) $assignment['id'] . ':' . (int) ($qc['payload']['qualificationId'] ?? 0);
				if (isset($conflictDedup[$key])) {
					continue;
				}
				$conflictDedup[$key] = true;
				$conflicts[] = [
					'type' => (string) ($qc['type'] ?? 'qualification_missing'),
					'severity' => (string) ($qc['severity'] ?? 'hard'),
					'message' => (string) ($qc['message'] ?? 'Employee is missing a required qualification for this location'),
					'employeeId' => (int) $assignment['employeeId'],
					'assignmentIds' => [(int) $assignment['id']],
					'details' => $qc['payload'] ?? [],
				];
			}
		}

		foreach ($assignments as $assignment) {
			$effective = $this->effectiveMinutes(
				(string) $assignment['startTime'],
				(string) $assignment['endTime'],
				(int) $assignment['breakMinutes'],
			);
			// ArbZG-oriented: shifts longer than 6h need ≥30 min break (soft ack).
			if ($effective > 360 && (int) $assignment['breakMinutes'] < 30) {
				$key = 'break_too_short:' . (int) $assignment['id'];
				if (!isset($conflictDedup[$key])) {
					$conflictDedup[$key] = true;
					$conflicts[] = [
						'type' => 'break_too_short',
						'severity' => 'soft',
						'message' => 'Break is shorter than required for this shift length',
						'employeeId' => (int) $assignment['employeeId'],
						'assignmentIds' => [(int) $assignment['id']],
						'details' => [
							'effectiveMinutes' => $effective,
							'breakMinutes' => (int) $assignment['breakMinutes'],
							'minBreakMinutes' => 30,
						],
					];
				}
			}
		}

		// ISO calendar-week totals (Mon–Sun) — complements period totals.
		foreach ($byEmployee as $employeeId => $employeeAssignments) {
			$byWeek = [];
			foreach ($employeeAssignments as $assignment) {
				$weekKey = (new DateTimeImmutable((string) $assignment['dutyDate']))->format('o-\WW');
				if (!isset($byWeek[$weekKey])) {
					$byWeek[$weekKey] = ['minutes' => 0, 'ids' => []];
				}
				$byWeek[$weekKey]['minutes'] += $this->effectiveMinutes(
					(string) $assignment['startTime'],
					(string) $assignment['endTime'],
					(int) $assignment['breakMinutes'],
				);
				$byWeek[$weekKey]['ids'][] = (int) $assignment['id'];
			}
			foreach ($byWeek as $weekKey => $week) {
				if ($week['minutes'] > $thresholds['maxPeriodHard']) {
					$key = 'weekly_hours_hard_cap:' . $employeeId . ':' . $weekKey;
					if (!isset($conflictDedup[$key])) {
						$conflictDedup[$key] = true;
						$conflicts[] = [
							'type' => 'weekly_hours_hard_cap',
							'severity' => 'hard',
							'message' => 'Calendar-week hard cap exceeded for employee',
							'employeeId' => (int) $employeeId,
							'assignmentIds' => $week['ids'],
							'details' => [
								'isoWeek' => $weekKey,
								'totalMinutes' => $week['minutes'],
								'maxPeriodHard' => $thresholds['maxPeriodHard'],
							],
						];
					}
				} elseif ($week['minutes'] > $thresholds['maxPeriodSoft']) {
					$key = 'weekly_hours_soft_cap:' . $employeeId . ':' . $weekKey;
					if (!isset($conflictDedup[$key])) {
						$conflictDedup[$key] = true;
						$conflicts[] = [
							'type' => 'weekly_hours_soft_cap',
							'severity' => 'soft',
							'message' => 'Calendar-week soft cap exceeded for employee',
							'employeeId' => (int) $employeeId,
							'assignmentIds' => $week['ids'],
							'details' => [
								'isoWeek' => $weekKey,
								'totalMinutes' => $week['minutes'],
								'maxPeriodSoft' => $thresholds['maxPeriodSoft'],
							],
						];
					}
				}
			}
		}

		$this->appendUnderstaffedConflicts($periodId, $assignments, $conflicts, $conflictDedup);

		return $conflicts;
	}

	/**
	 * Soft coverage check: location+day assignment count below any active template min_headcount for that location.
	 *
	 * @param list<array<string,mixed>> $assignments
	 * @param list<array<string,mixed>> $conflicts
	 * @param array<string,bool> $conflictDedup
	 */
	private function appendUnderstaffedConflicts(int $periodId, array $assignments, array &$conflicts, array &$conflictDedup): void
	{
		if (!SchemaProbe::hasColumn($this->db, 'dc_shift_templates', 'min_headcount')) {
			return;
		}
		if (!$this->db->tableExists('dc_shift_templates')) {
			return;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'location_id', 'name', 'min_headcount')
			->from('dc_shift_templates')
			->where($qb->expr()->eq('active', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->gt('min_headcount', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)));
		$templates = $qb->executeQuery()->fetchAll();
		if ($templates === []) {
			return;
		}

		$counts = [];
		foreach ($assignments as $assignment) {
			$loc = (int) $assignment['locationId'];
			$day = (string) $assignment['dutyDate'];
			$key = $loc . ':' . $day;
			$counts[$key] = ($counts[$key] ?? 0) + 1;
		}

		foreach ($templates as $tpl) {
			$tplLoc = $tpl['location_id'] !== null ? (int) $tpl['location_id'] : null;
			$min = (int) $tpl['min_headcount'];
			if ($min < 1 || $tplLoc === null) {
				continue;
			}
			foreach ($counts as $locDay => $count) {
				[$locStr, $day] = explode(':', $locDay, 2);
				if ((int) $locStr !== $tplLoc) {
					continue;
				}
				if ($count >= $min) {
					continue;
				}
				$key = 'understaffed_shift:' . (int) $tpl['id'] . ':' . $tplLoc . ':' . $day;
				if (isset($conflictDedup[$key])) {
					continue;
				}
				$conflictDedup[$key] = true;
				$conflicts[] = [
					'type' => 'understaffed_shift',
					'severity' => 'soft',
					'message' => 'Location is understaffed relative to template headcount',
					'employeeId' => 0,
					'assignmentIds' => [],
					'details' => [
						'templateId' => (int) $tpl['id'],
						'templateName' => (string) $tpl['name'],
						'locationId' => $tplLoc,
						'dutyDate' => $day,
						'assignedCount' => $count,
						'minHeadcount' => $min,
						'periodId' => $periodId,
					],
				];
			}
		}
	}

	private function refreshAndListConflicts(int $periodId): array
	{
		$computed = $this->conflictsForPeriod($periodId);
		$this->materializeConflicts($periodId, $computed);
		return $this->listPersistedConflicts($periodId);
	}

	/**
	 * @param list<array<string,mixed>> $computed
	 */
	private function materializeConflicts(int $periodId, array $computed): void
	{
		$existing = $this->existingConflictRows($periodId);
		$seenIds = [];
		foreach ($computed as $conflict) {
			$identity = $this->conflictIdentity(
				$periodId,
				(string) ($conflict['type'] ?? ''),
				(int) ($conflict['employeeId'] ?? 0),
				$conflict['assignmentIds'] ?? []
			);
			$contextHash = hash('sha256', json_encode($conflict, JSON_THROW_ON_ERROR));
			$existingRow = $existing[$identity] ?? null;
			if ($existingRow !== null) {
				$seenIds[] = (int) $existingRow['id'];
				$update = $this->db->getQueryBuilder();
				$update->update('dc_conflicts')
					->set('severity', $update->createNamedParameter((string) ($conflict['severity'] ?? 'soft')))
					->set('detected_at', $update->createNamedParameter($this->now()))
					->set('context_hash', $update->createNamedParameter($contextHash))
					->set('payload_json', $update->createNamedParameter(json_encode($conflict, JSON_THROW_ON_ERROR)))
					->set('is_resolved', $update->createNamedParameter(0, IQueryBuilder::PARAM_INT))
					->set('resolved_at', $update->createNamedParameter(null))
					->where($update->expr()->eq('id', $update->createNamedParameter((int) $existingRow['id'], IQueryBuilder::PARAM_INT)))
					->executeStatement();
				continue;
			}

			$assignmentIds = is_array($conflict['assignmentIds'] ?? null) ? $conflict['assignmentIds'] : [];
			$assignmentA = isset($assignmentIds[0]) ? (int) $assignmentIds[0] : null;
			$assignmentB = isset($assignmentIds[1]) ? (int) $assignmentIds[1] : null;
			$insert = $this->db->getQueryBuilder();
			$insert->insert('dc_conflicts')
				->values([
					'period_id' => $insert->createNamedParameter($periodId, IQueryBuilder::PARAM_INT),
					'assignment_id' => $insert->createNamedParameter($assignmentA, IQueryBuilder::PARAM_INT),
					'secondary_assignment_id' => $insert->createNamedParameter($assignmentB, IQueryBuilder::PARAM_INT),
					'employee_id' => $insert->createNamedParameter((int) ($conflict['employeeId'] ?? 0), IQueryBuilder::PARAM_INT),
					'type' => $insert->createNamedParameter((string) ($conflict['type'] ?? 'unknown')),
					'severity' => $insert->createNamedParameter((string) ($conflict['severity'] ?? 'soft')),
					'detected_at' => $insert->createNamedParameter($this->now()),
					'context_hash' => $insert->createNamedParameter($contextHash),
					'payload_json' => $insert->createNamedParameter(json_encode($conflict, JSON_THROW_ON_ERROR)),
					'is_resolved' => $insert->createNamedParameter(0, IQueryBuilder::PARAM_INT),
				])->executeStatement();
		}

		foreach ($existing as $row) {
			$id = (int) $row['id'];
			if (in_array($id, $seenIds, true)) {
				continue;
			}
			$resolve = $this->db->getQueryBuilder();
			$resolve->update('dc_conflicts')
				->set('is_resolved', $resolve->createNamedParameter(1, IQueryBuilder::PARAM_INT))
				->set('resolved_at', $resolve->createNamedParameter($this->now()))
				->where($resolve->expr()->eq('id', $resolve->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
				->executeStatement();
		}
	}

	private function listPersistedConflicts(int $periodId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'type', 'severity', 'payload_json', 'is_resolved', 'ack_reason', 'ack_context_hash', 'context_hash')
			->from('dc_conflicts')
			->where($qb->expr()->eq('period_id', $qb->createNamedParameter($periodId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('is_resolved', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->orderBy('severity', 'DESC')
			->addOrderBy('id', 'ASC');
		$rows = $qb->executeQuery()->fetchAll();
		return array_map(static function (array $row): array {
			$payload = [];
			try {
				$payload = $row['payload_json'] !== null ? (array) json_decode((string) $row['payload_json'], true, 512, JSON_THROW_ON_ERROR) : [];
			} catch (\Throwable) {
				$payload = [];
			}
			return [
				'id' => (int) $row['id'],
				'type' => (string) $row['type'],
				'severity' => (string) $row['severity'],
				'message' => (string) ($payload['message'] ?? 'Conflict'),
				'assignmentIds' => $payload['assignmentIds'] ?? [],
				'details' => $payload['details'] ?? [],
				'acknowledged' => self::conflictAckState((string) ($row['ack_context_hash'] ?? ''), (string) $row['context_hash'])['acknowledged'],
				'ackInvalidated' => self::conflictAckState((string) ($row['ack_context_hash'] ?? ''), (string) $row['context_hash'])['ackInvalidated'],
				'ackReason' => $row['ack_reason'] !== null ? (string) $row['ack_reason'] : '',
			];
		}, $rows);
	}

	/**
	 * @return array{acknowledged:bool,ackInvalidated:bool}
	 */
	private static function conflictAckState(string $ackContextHash, string $contextHash): array
	{
		$hasAck = trim($ackContextHash) !== '';
		$acknowledged = $hasAck && $ackContextHash === $contextHash;
		$ackInvalidated = $hasAck && $ackContextHash !== $contextHash;
		return [
			'acknowledged' => $acknowledged,
			'ackInvalidated' => $ackInvalidated,
		];
	}

	private function existingConflictRows(int $periodId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'type', 'employee_id', 'assignment_id', 'secondary_assignment_id', 'ack_context_hash')
			->from('dc_conflicts')
			->where($qb->expr()->eq('period_id', $qb->createNamedParameter($periodId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('is_resolved', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)));
		$rows = $qb->executeQuery()->fetchAll();
		$out = [];
		foreach ($rows as $row) {
			$identity = $this->conflictIdentity(
				$periodId,
				(string) $row['type'],
				(int) $row['employee_id'],
				[(int) ($row['assignment_id'] ?? 0), (int) ($row['secondary_assignment_id'] ?? 0)]
			);
			$out[$identity] = $row;
		}
		return $out;
	}

	/**
	 * @param array<int,mixed> $assignmentIds
	 */
	private function conflictIdentity(int $periodId, string $type, int $employeeId, array $assignmentIds): string
	{
		$ids = array_values(array_filter(array_map('intval', $assignmentIds), static fn (int $id): bool => $id > 0));
		sort($ids);
		return $periodId . '|' . $type . '|' . $employeeId . '|' . implode(',', $ids);
	}

	private function minutesBetweenRanges(array $a, array $b): int
	{
		$first = $a;
		$second = $b;
		if ($b[0] < $a[0]) {
			$first = $b;
			$second = $a;
		}
		if ($this->absoluteRangesOverlap($first, $second)) {
			return -1;
		}
		return max(0, $second[0] - $first[1]);
	}

	private function assertNoAbsenceConflict(int $employeeId, string $dutyDate): void
	{
		if ($this->hasApprovedAbsenceOnDate($employeeId, $dutyDate)) {
			throw new \InvalidArgumentException('ABSENCE_CONFLICT');
		}
	}

	private function assertNoOverlapConflict(int $periodId, int $employeeId, string $dutyDate, string $startTime, string $endTime, ?int $excludeAssignmentId = null): void
	{
		$candidateRange = $this->assignmentAbsoluteRange($dutyDate, $startTime, $endTime);
		$assignments = $this->listAssignments($periodId);
		foreach ($assignments as $assignment) {
			if ($excludeAssignmentId !== null && (int) $assignment['id'] === $excludeAssignmentId) {
				continue;
			}
			if ($assignment['employeeId'] !== $employeeId) {
				continue;
			}
			$existingRange = $this->assignmentAbsoluteRange(
				(string) $assignment['dutyDate'],
				(string) $assignment['startTime'],
				(string) $assignment['endTime'],
			);
			if ($this->absoluteRangesOverlap($candidateRange, $existingRange)) {
				throw new \InvalidArgumentException('ASSIGNMENT_OVERLAP');
			}
		}
	}

	/**
	 * Hard gates for marketplace claim (overlap, absence, hard qualifications).
	 * Soft conflicts remain planner-ack on approve — they must not block claim.
	 */
	public function assertHardMarketplaceSlot(
		int $periodId,
		int $employeeId,
		int $locationId,
		string $dutyDate,
		string $startTime,
		string $endTime,
	): void {
		$this->assertDate($dutyDate);
		$startTime = $this->normalizeDutyTime($startTime);
		$endTime = $this->normalizeDutyTime($endTime);
		$this->assertNoAbsenceConflict($employeeId, $dutyDate);
		$this->assertNoOverlapConflict($periodId, $employeeId, $dutyDate, $startTime, $endTime);
		foreach ($this->qualificationConflicts($employeeId, $locationId, $dutyDate) as $qc) {
			if (($qc['severity'] ?? '') === 'hard') {
				throw new \InvalidArgumentException('QUALIFICATION_MISSING');
			}
		}
	}

	/**
	 * Public peek for authorization (location scope / IDOR checks).
	 *
	 * @return array{id:int,periodId:int,employeeId:int,locationId:int,dutyDate:string,status:string,version:int}
	 */
	public function peekAssignment(int $assignmentId): array
	{
		$row = $this->assignmentRowById($assignmentId);
		if ($row === null) {
			throw new \InvalidArgumentException('ASSIGNMENT_NOT_FOUND');
		}
		return [
			'id' => (int) $row['id'],
			'periodId' => (int) $row['period_id'],
			'employeeId' => (int) $row['employee_id'],
			'locationId' => (int) $row['location_id'],
			'dutyDate' => (string) $row['duty_date'],
			'status' => (string) ($row['status'] ?? 'active'),
			'version' => (int) ($row['version'] ?? 0),
		];
	}

	/**
	 * @return array<string,mixed>|null
	 */
	private function assignmentRowById(int $assignmentId): ?array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from('dc_assignments')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($assignmentId, IQueryBuilder::PARAM_INT)));
		$row = $qb->executeQuery()->fetch();
		return $row === false ? null : $row;
	}

	/**
	 * @return array{maxDailyHard:int,maxPeriodSoft:int,maxPeriodHard:int,maxConsecutiveDays:int,minRestMinutes:int}
	 */
	private function policyThresholds(): array
	{
		if ($this->conflictPolicy !== null) {
			return $this->conflictPolicy->thresholds();
		}
		return ConflictPolicyService::defaults();
	}

	/**
	 * @return list<array<string,mixed>>
	 */
	private function qualificationConflicts(int $employeeId, int $locationId, string $dutyDate): array
	{
		if ($this->qualifications === null) {
			return [];
		}
		return $this->qualifications->conflictsForAssignment($employeeId, $locationId, $dutyDate);
	}

	private function rangesOverlap(string $startA, string $endA, string $startB, string $endB): bool
	{
		$aStart = $this->toMinute($startA);
		$aEnd = $this->toMinute($endA);
		$bStart = $this->toMinute($startB);
		$bEnd = $this->toMinute($endB);
		if ($aEnd <= $aStart) {
			$aEnd += 24 * 60;
		}
		if ($bEnd <= $bStart) {
			$bEnd += 24 * 60;
		}
		return max($aStart, $bStart) < min($aEnd, $bEnd);
	}

	/**
	 * @return array{0:int,1:int}
	 */
	private function assignmentAbsoluteRange(string $dutyDate, string $start, string $end): array
	{
		$dayIndex = $this->dateToDayIndex($dutyDate);
		$startMinute = $this->toMinute($start);
		$endMinute = $this->toMinute($end);
		$absoluteStart = ($dayIndex * 1440) + $startMinute;
		$absoluteEnd = ($dayIndex * 1440) + $endMinute;
		if ($absoluteEnd <= $absoluteStart) {
			$absoluteEnd += 1440;
		}
		return [$absoluteStart, $absoluteEnd];
	}

	private function absoluteRangesOverlap(array $a, array $b): bool
	{
		return $a[0] < $b[1] && $b[0] < $a[1];
	}

	private function toMinute(string $time): int
	{
		[$h, $m] = explode(':', $time, 2);
		return (((int) $h) * 60) + (int) $m;
	}

	private function dateToDayIndex(string $date): int
	{
		$dt = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
		if ($dt === false) {
			throw new \InvalidArgumentException('INVALID_DATE');
		}
		return (int) floor(((int) $dt->format('U')) / 86400);
	}

	private function assertEmployeeExists(int $employeeId): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from('dc_employees')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($employeeId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('active', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)));
		if ($qb->executeQuery()->fetchOne() === false) {
			throw new \InvalidArgumentException('EMPLOYEE_NOT_FOUND');
		}
	}

	private function assertLocationExists(int $locationId): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from('dc_locations')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($locationId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('active', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)));
		if ($qb->executeQuery()->fetchOne() === false) {
			throw new \InvalidArgumentException('LOCATION_NOT_FOUND');
		}
	}

	private function assertDate(string $date): void
	{
		if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
			throw new \InvalidArgumentException('INVALID_DATE');
		}
		$parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
		$errors = DateTimeImmutable::getLastErrors();
		if (
			$parsed === false ||
			($errors !== false && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0)) ||
			$parsed->format('Y-m-d') !== $date
		) {
			throw new \InvalidArgumentException('INVALID_DATE');
		}
	}

	/**
	 * Normalise wall-clock duty times to HH:mm. Accepts H:mm, HH:mm, or HH:mm:ss
	 * from API clients; rejects out-of-range components.
	 */
	private function normalizeDutyTime(string $raw): string
	{
		$raw = trim($raw);
		if (preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', $raw, $m) !== 1) {
			throw new \InvalidArgumentException('INVALID_TIME');
		}
		$h = (int) $m[1];
		$min = (int) $m[2];
		if ($h < 0 || $h > 23 || $min < 0 || $min > 59) {
			throw new \InvalidArgumentException('INVALID_TIME');
		}

		return sprintf('%02d:%02d', $h, $min);
	}

	private function count(string $table, string $column, mixed $value): int
	{
		return $this->countScoped($table, $column, $value, null);
	}

	private function countScoped(string $table, string $column, mixed $value, ?string $actorUserId): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'cnt'))
			->from($table)
			->where($qb->expr()->eq($column, $qb->createNamedParameter($value)));
		if (
			$actorUserId !== null
			&& $this->companies !== null
			&& SchemaProbe::hasColumn($this->db, $table, 'company_id')
		) {
			$this->companies->restrictQuery($qb, 'company_id', $actorUserId);
		}
		return (int) $qb->executeQuery()->fetchOne();
	}

	private function countAll(string $table): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'cnt'))->from($table);
		return (int) $qb->executeQuery()->fetchOne();
	}

	private function countActiveAssignments(?string $actorUserId = null): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'cnt'))->from('dc_assignments', 'a');
		if ($this->assignmentHasStatusColumn()) {
			$qb->where($qb->expr()->orX(
				$qb->expr()->neq('a.status', $qb->createNamedParameter('cancelled')),
				$qb->expr()->isNull('a.status'),
			));
		}
		if (
			$actorUserId !== null
			&& $this->companies !== null
			&& SchemaProbe::hasColumn($this->db, 'dc_periods', 'company_id')
		) {
			$qb->innerJoin('a', 'dc_periods', 'p', 'a.period_id = p.id');
			$this->companies->restrictQuery($qb, 'p.company_id', $actorUserId);
		}
		return (int) $qb->executeQuery()->fetchOne();
	}

	private function effectiveMinutes(string $startTime, string $endTime, int $breakMinutes): int
	{
		$start = $this->toMinute($startTime);
		$end = $this->toMinute($endTime);
		if ($end <= $start) {
			$end += 24 * 60;
		}

		return ($end - $start) - $breakMinutes;
	}

	private function assertNoAbsenceOverlapForStatus(int $employeeId, string $startDate, string $endDate, array $statuses, ?int $ignoreId = null): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from('dc_absences')
			->where($qb->expr()->eq('employee_id', $qb->createNamedParameter($employeeId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->lte('start_date', $qb->createNamedParameter($endDate)))
			->andWhere($qb->expr()->gte('end_date', $qb->createNamedParameter($startDate)))
			->setMaxResults(1);
		$statusExpr = $qb->expr()->orX();
		foreach ($statuses as $index => $status) {
			$statusExpr->add($qb->expr()->eq('status', $qb->createNamedParameter($status, IQueryBuilder::PARAM_STR, ':status' . $index)));
		}
		$qb->andWhere($statusExpr);
		if ($ignoreId !== null) {
			$qb->andWhere($qb->expr()->neq('id', $qb->createNamedParameter($ignoreId, IQueryBuilder::PARAM_INT)));
		}
		$row = $qb->executeQuery()->fetchOne();
		if ($row !== false) {
			throw new \InvalidArgumentException('ABSENCE_OVERLAP');
		}

		$this->assertNoImportedAbsenceOverlap($employeeId, $startDate, $endDate, $statuses);
	}

	private function hasApprovedAbsenceOnDate(int $employeeId, string $date): bool
	{
		return $this->absenceCollisionSource($employeeId, $date) !== null;
	}

	/**
	 * @return 'dutycheck'|'arbeitszeitcheck'|null
	 */
	private function absenceCollisionSource(int $employeeId, string $date): ?string
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from('dc_absences')
			->where($qb->expr()->eq('employee_id', $qb->createNamedParameter($employeeId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('approved')))
			->andWhere($qb->expr()->lte('start_date', $qb->createNamedParameter($date)))
			->andWhere($qb->expr()->gte('end_date', $qb->createNamedParameter($date)))
			->setMaxResults(1);
		if ($qb->executeQuery()->fetchOne() !== false) {
			return 'dutycheck';
		}
		if ($this->atIntegration?->hasImportedBlockingAbsenceOnDate($employeeId, $date) ?? false) {
			return 'arbeitszeitcheck';
		}
		return null;
	}

	private function assertIntegrationAllowsDcAbsenceForEmployee(int $employeeId, string $targetStatus = ''): void
	{
		if (!$this->atIntegration?->integrationLocksLinkedDutyCheckAbsences()) {
			return;
		}
		$uid = $this->linkedUserIdForEmployeeId($employeeId);
		if ($uid === null || $uid === '') {
			return;
		}
		// WF-4 stragglers: allow cancel/reject of legacy DutyCheck rows so planners can clear them.
		if (in_array($targetStatus, ['cancelled', 'rejected'], true)) {
			return;
		}
		throw new \InvalidArgumentException('INTEGRATION_ABSENCE_READONLY');
	}

	/**
	 * @param list<string> $dutyStatuses
	 */
	private function assertNoImportedAbsenceOverlap(int $employeeId, string $startDate, string $endDate, array $dutyStatuses): void
	{
		if ($this->atIntegration?->mirrorOverlapsEmployeeRange($employeeId, $startDate, $endDate, $dutyStatuses, null)) {
			throw new \InvalidArgumentException('ABSENCE_OVERLAP');
		}
	}

	private function linkedUserIdForEmployeeId(int $employeeId): ?string
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('linked_user_id')
			->from('dc_employees')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($employeeId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('active', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
			->setMaxResults(1);
		$row = $qb->executeQuery()->fetchOne();
		if ($row === false || $row === null) {
			return null;
		}
		$s = trim((string) $row);
		return $s !== '' ? $s : null;
	}

	private function linkedEmployeeIdByUserId(string $userId): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from('dc_employees')
			->where($qb->expr()->eq('linked_user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('active', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
			->setMaxResults(1);
		$id = $qb->executeQuery()->fetchOne();
		if ($id === false) {
			throw new \InvalidArgumentException('EMPLOYEE_LINK_NOT_FOUND');
		}
		return (int) $id;
	}

	private function linkedUserIdByEmployeeId(int $employeeId): string
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('linked_user_id')
			->from('dc_employees')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($employeeId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('active', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
			->setMaxResults(1);
		$uid = $qb->executeQuery()->fetchOne();
		if ($uid === false || $uid === null || trim((string) $uid) === '') {
			throw new \InvalidArgumentException('EMPLOYEE_NOT_FOUND');
		}
		return (string) $uid;
	}

	private function readUserPreference(string $userId, string $prefKey): ?string
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('value_json')
			->from('dc_user_preferences')
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('pref_key', $qb->createNamedParameter($prefKey)))
			->setMaxResults(1);
		$value = $qb->executeQuery()->fetchOne();
		return $value !== false && $value !== null ? (string) $value : null;
	}

	private function writeUserPreference(string $userId, string $prefKey, string $valueJson): void
	{
		$existing = $this->readUserPreference($userId, $prefKey);
		$qb = $this->db->getQueryBuilder();
		if ($existing === null) {
			$qb->insert('dc_user_preferences')
				->values([
					'user_id' => $qb->createNamedParameter($userId),
					'pref_key' => $qb->createNamedParameter($prefKey),
					'value_json' => $qb->createNamedParameter($valueJson),
					'updated_at' => $qb->createNamedParameter($this->now()),
				])->executeStatement();
			return;
		}
		$qb->update('dc_user_preferences')
			->set('value_json', $qb->createNamedParameter($valueJson))
			->set('updated_at', $qb->createNamedParameter($this->now()))
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('pref_key', $qb->createNamedParameter($prefKey)))
			->executeStatement();
	}

	private function icalUrlForEmployee(int $employeeId, string $token): string
	{
		return '/index.php/apps/dutycheck/api/ical/' . $employeeId . '?token=' . rawurlencode($token);
	}

	private function publishedAssignmentsForEmployee(int $employeeId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('a.id', 'a.duty_date', 'a.start_time', 'a.end_time', 'a.break_minutes', 'l.name AS location_name')
			->from('dc_assignments', 'a')
			->innerJoin('a', 'dc_periods', 'p', 'a.period_id = p.id')
			->leftJoin('a', 'dc_locations', 'l', 'a.location_id = l.id')
			->where($qb->expr()->eq('a.employee_id', $qb->createNamedParameter($employeeId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('p.status', $qb->createNamedParameter('published')))
			->orderBy('a.duty_date', 'ASC')
			->addOrderBy('a.start_time', 'ASC');
		return $qb->executeQuery()->fetchAll();
	}

	private function toUtcDateTime(string $date, string $time): DateTimeImmutable
	{
		return new DateTimeImmutable($date . ' ' . $time, new DateTimeZone('UTC'));
	}

	private function icsEscape(string $value): string
	{
		return str_replace(
			["\\", ";", ",", "\r\n", "\n", "\r"],
			["\\\\", '\;', '\,', '\n', '\n', '\n'],
			trim($value)
		);
	}

	private function assertNoDuplicatePeriodRange(string $startDate, string $endDate, ?int $companyId = null): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from('dc_periods')
			->where($qb->expr()->eq('start_date', $qb->createNamedParameter($startDate)))
			->andWhere($qb->expr()->eq('end_date', $qb->createNamedParameter($endDate)))
			->setMaxResults(1);
		if ($companyId !== null && SchemaProbe::hasColumn($this->db, 'dc_periods', 'company_id')) {
			$qb->andWhere($qb->expr()->eq('company_id', $qb->createNamedParameter($companyId, IQueryBuilder::PARAM_INT)));
		}
		if ($qb->executeQuery()->fetchOne() !== false) {
			throw new \InvalidArgumentException('PERIOD_RANGE_EXISTS');
		}
	}

	/**
	 * When multi-company is active, employee + location must belong to the same company as the period.
	 */
	private function assertEntitiesSharePeriodCompany(int $periodId, int $employeeId, int $locationId): void
	{
		if ($this->companies === null || !$this->companies->schemaReady() || !$this->companies->isMultiCompanyActive()) {
			return;
		}
		$periodCompany = $this->readRowCompanyId('dc_periods', $periodId);
		$employeeCompany = $this->readRowCompanyId('dc_employees', $employeeId);
		$locationCompany = $this->readRowCompanyId('dc_locations', $locationId);
		if ($periodCompany !== $employeeCompany || $periodCompany !== $locationCompany) {
			throw new \InvalidArgumentException('COMPANY_MISMATCH');
		}
	}

	private function readRowCompanyId(string $table, int $rowId): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('company_id')->from($table)
			->where($qb->expr()->eq('id', $qb->createNamedParameter($rowId, IQueryBuilder::PARAM_INT)));
		$row = $qb->executeQuery()->fetch();
		if ($row === false) {
			throw new \InvalidArgumentException('NOT_FOUND');
		}
		return (int) ($row['company_id'] ?? CompanyService::DEFAULT_COMPANY_ID);
	}

	private function findOverlappingPeriods(string $startDate, string $endDate, int $ignoreId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'start_date', 'end_date')
			->from('dc_periods')
			->where($qb->expr()->neq('id', $qb->createNamedParameter($ignoreId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->lte('start_date', $qb->createNamedParameter($endDate)))
			->andWhere($qb->expr()->gte('end_date', $qb->createNamedParameter($startDate)));
		if (SchemaProbe::hasColumn($this->db, 'dc_periods', 'company_id')) {
			$companyId = $this->readRowCompanyId('dc_periods', $ignoreId);
			$qb->andWhere($qb->expr()->eq('company_id', $qb->createNamedParameter($companyId, IQueryBuilder::PARAM_INT)));
		}

		return array_map(static fn (array $row): array => [
			'id' => (int) $row['id'],
			'startDate' => (string) $row['start_date'],
			'endDate' => (string) $row['end_date'],
		], $qb->executeQuery()->fetchAll());
	}

	private function absenceById(int $absenceId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'employee_id', 'start_date', 'end_date', 'status')
			->from('dc_absences')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($absenceId, IQueryBuilder::PARAM_INT)));
		$row = $qb->executeQuery()->fetch();
		if ($row === false) {
			throw new \InvalidArgumentException('ABSENCE_NOT_FOUND');
		}

		return [
			'id' => (int) $row['id'],
			'employeeId' => (int) $row['employee_id'],
			'startDate' => (string) $row['start_date'],
			'endDate' => (string) $row['end_date'],
			'status' => (string) $row['status'],
		];
	}

	private function now(): string
	{
		return (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
	}

	/**
	 * Resolve the roster display name from the mutation payload.
	 *
	 * An explicit non-empty displayName always wins (audit trail stays independent of
	 * the linked account). When omitted and a linked user is present, the name is
	 * taken once from the Nextcloud account at save time — same rule as the UI auto-fill.
	 */
	private function resolveDisplayNameFromPayload(array $payload, ?string $linkedUserId): string
	{
		$raw = trim((string) ($payload['displayName'] ?? ''));
		if ($raw !== '') {
			return $this->validateDisplayName($raw);
		}
		if ($linkedUserId === null || $linkedUserId === '') {
			throw new \InvalidArgumentException('INVALID_DISPLAY_NAME');
		}

		return $this->validateDisplayName($this->resolveLinkedUserDisplayName($linkedUserId));
	}

	private function resolveLinkedUserDisplayName(string $linkedUserId): string
	{
		if ($this->userManager === null) {
			throw new \InvalidArgumentException('INVALID_DISPLAY_NAME');
		}
		$user = $this->userManager->get($linkedUserId);
		if ($user === null) {
			throw new \InvalidArgumentException('INVALID_LINKED_USER');
		}
		$name = trim((string) $user->getDisplayName());
		if ($name === '') {
			$name = $linkedUserId;
		}

		return $name;
	}

	private function validateDisplayName(string $name): string
	{
		$trimmed = trim($name);
		if ($trimmed === '' || mb_strlen($trimmed) > 191 || $this->hasControlCharacters($trimmed)) {
			throw new \InvalidArgumentException('INVALID_DISPLAY_NAME');
		}
		return $trimmed;
	}

	private function validateSimpleLabel(string $value, string $errorCode): string
	{
		$trimmed = trim($value);
		if ($trimmed === '' || mb_strlen($trimmed) > 191 || $this->hasControlCharacters($trimmed)) {
			throw new \InvalidArgumentException($errorCode);
		}
		return $trimmed;
	}

	private function validateDescription(string $value): string
	{
		$trimmed = trim($value);
		if (mb_strlen($trimmed) > 512 || $this->hasControlCharacters($trimmed)) {
			throw new \InvalidArgumentException('INVALID_DESCRIPTION');
		}
		return $trimmed;
	}

	private function hasControlCharacters(string $value): bool
	{
		return preg_match('/[\x00-\x1F\x7F]/', $value) === 1;
	}

	private function normalizeLinkedUserId(mixed $linkedUserId, ?string $unchangedValue = null): ?string
	{
		$value = trim((string) ($linkedUserId ?? ''));
		if ($value === '') {
			return null;
		}
		if (mb_strlen($value) > 64 || !preg_match('/^[A-Za-z0-9._@-]+$/', $value)) {
			throw new \InvalidArgumentException('INVALID_LINKED_USER');
		}
		// Validate the user actually exists when the link is new or changed. We
		// accept disabled users so an employee record can stay linked while their
		// Nextcloud account is temporarily blocked, and a *new* link to a missing
		// account must always fail loudly so a stale record never silently
		// absorbs a typo'd UID.
		//
		// An already-stored link is intentionally NOT re-validated: a planner
		// must still be able to edit or deactivate an employee whose linked
		// Nextcloud account was deleted afterwards. Without this the record would
		// be frozen and could never be cleaned up.
		if ($value !== $unchangedValue && $this->userManager !== null) {
			$user = $this->userManager->get($value);
			if ($user === null) {
				throw new \InvalidArgumentException('INVALID_LINKED_USER');
			}
		}
		return $value;
	}

	private function toActiveFlag(mixed $value): int
	{
		if (is_bool($value)) {
			return $value ? 1 : 0;
		}
		$normalized = strtolower(trim((string) $value));
		if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
			return 1;
		}
		if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
			return 0;
		}
		throw new \InvalidArgumentException('INVALID_ACTIVE_FLAG');
	}

	private function validateTimezone(string $timezone): string
	{
		$catalog = $this->timezoneCatalog ?? new TimezoneCatalog();
		return $catalog->normalizeOrThrow($timezone);
	}

	private function assertEmployeeDisplayNameUnique(string $displayName, ?int $ignoreId = null): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from('dc_employees')
			->where($qb->expr()->eq('display_name', $qb->createNamedParameter($displayName)))
			->setMaxResults(1);
		$row = $qb->executeQuery()->fetchOne();
		if ($row !== false && (int) $row !== $ignoreId) {
			throw new \InvalidArgumentException('EMPLOYEE_NAME_EXISTS');
		}
	}

	private function assertLinkedUserUnique(?string $linkedUserId, ?int $ignoreId = null): void
	{
		if ($linkedUserId === null) {
			return;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from('dc_employees')
			->where($qb->expr()->eq('linked_user_id', $qb->createNamedParameter($linkedUserId)))
			->setMaxResults(1);
		$row = $qb->executeQuery()->fetchOne();
		if ($row !== false && (int) $row !== $ignoreId) {
			throw new \InvalidArgumentException('LINKED_USER_EXISTS');
		}
	}

	private function assertLocationNameUnique(string $name, ?int $ignoreId = null): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from('dc_locations')
			->where($qb->expr()->eq('name', $qb->createNamedParameter($name)))
			->setMaxResults(1);
		$row = $qb->executeQuery()->fetchOne();
		if ($row !== false && (int) $row !== $ignoreId) {
			throw new \InvalidArgumentException('LOCATION_NAME_EXISTS');
		}
	}

	private function assertEmployeeRowExists(int $id): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from('dc_employees')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		if ($qb->executeQuery()->fetchOne() === false) {
			throw new \InvalidArgumentException('EMPLOYEE_NOT_FOUND');
		}
	}

	/** Currently stored linked Nextcloud account UID for an employee, or null when unlinked. */
	private function fetchEmployeeLinkedUserId(int $id): ?string
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('linked_user_id')->from('dc_employees')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->setMaxResults(1);
		$value = $qb->executeQuery()->fetchOne();
		return ($value === false || $value === null || $value === '') ? null : (string) $value;
	}

	private function assertLocationRowExists(int $id): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from('dc_locations')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		if ($qb->executeQuery()->fetchOne() === false) {
			throw new \InvalidArgumentException('LOCATION_NOT_FOUND');
		}
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
