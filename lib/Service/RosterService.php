<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\DutyCheck\Exception\ConflictAckRequiredException;
use OCA\DutyCheck\Exception\IntegrationLegacyConflictException;
use OCA\DutyCheck\Integration\ArbeitszeitCheckTypeMapper;
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
			'Weekly hard cap exceeded for employee',
			'Weekly soft cap exceeded for employee',
			'Employee is scheduled for too many consecutive days',
			'Employee assignment collides with approved absence',
			'Period overlaps with another planning period',
		];
	}

	public function dashboardSummary(): array
	{
		$openPeriods = $this->count('dc_periods', 'status', 'open');
		$publishedPeriods = $this->count('dc_periods', 'status', 'published');
		$employees = $this->count('dc_employees', 'active', 1);
		$assignments = $this->countAll('dc_assignments');

		return [
			'openPeriods' => $openPeriods,
			'publishedPeriods' => $publishedPeriods,
			'activeEmployees' => $employees,
			'assignments' => $assignments,
		];
	}

	public function listPeriods(): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'start_date', 'end_date', 'status', 'created_by', 'created_at', 'published_at', 'closed_at')
			->from('dc_periods')
			->orderBy('start_date', 'DESC');
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
		$this->assertNoDuplicatePeriodRange($startDate, $endDate);

		$now = $this->now();
		$qb = $this->db->getQueryBuilder();
		$qb->insert('dc_periods')
			->values([
				'start_date' => $qb->createNamedParameter($startDate),
				'end_date' => $qb->createNamedParameter($endDate),
				'status' => $qb->createNamedParameter('open'),
				'created_by' => $qb->createNamedParameter($actor),
				'created_at' => $qb->createNamedParameter($now),
			])
			->executeStatement();

		return $this->periodById((int) $qb->getLastInsertId());
	}

	public function transitionPeriod(int $periodId, string $targetStatus, string $actorUserId, string $reason = ''): array
	{
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
			->where($qb->expr()->eq('id', $qb->createNamedParameter($periodId, IQueryBuilder::PARAM_INT)));
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
		$qb->executeStatement();
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
		return [
			'periodId' => $periodId,
			'hardConflicts' => $hard,
			'softConflicts' => $soft,
			'unacknowledgedSoftConflicts' => $softUnack,
			'canPublish' => $hard === 0,
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
		return [
			'period' => $this->periodById($periodId),
			'assignments' => $this->listAssignments($periodId),
		];
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

	public function rosterData(?int $periodId = null): array
	{
		$periods = $this->listPeriods();
		$selected = $periodId;
		if ($selected !== null) {
			$this->periodById($selected);
			$knownPeriodIds = array_map(static fn (array $period): int => (int) $period['id'], $periods);
			if (!in_array($selected, $knownPeriodIds, true)) {
				throw new \InvalidArgumentException('PERIOD_NOT_FOUND');
			}
		}
		$selected = $this->resolveRosterPeriodSelection($selected, $periods);

		$employees = $this->listEmployees();
		$locations = $this->listLocations();
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

		$qb = $this->db->getQueryBuilder();
		$qb->select('employee_id', 'start_date', 'end_date')
			->from('dc_absences')
			->where($qb->expr()->eq('status', $qb->createNamedParameter('approved')))
			->andWhere($qb->expr()->lte('start_date', $qb->createNamedParameter($periodEnd)))
			->andWhere($qb->expr()->gte('end_date', $qb->createNamedParameter($periodStart)));
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

	public function createAssignment(array $payload, string $actor): array
	{
		$periodId = (int) ($payload['periodId'] ?? 0);
		$employeeId = (int) ($payload['employeeId'] ?? 0);
		$locationId = (int) ($payload['locationId'] ?? 0);
		$dutyDate = (string) ($payload['dutyDate'] ?? '');
		$startTime = (string) ($payload['startTime'] ?? '');
		$endTime = (string) ($payload['endTime'] ?? '');
		$breakMinutes = (int) ($payload['breakMinutes'] ?? 0);
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
			throw new \InvalidArgumentException('INVALID_SHIFT_LENGTH');
		}
		if ($breakMinutes < 0 || $breakMinutes > 720) {
			throw new \InvalidArgumentException('INVALID_BREAK_MINUTES');
		}
		if (mb_strlen($note) > 512) {
			throw new \InvalidArgumentException('NOTE_TOO_LONG');
		}
		if ($this->effectiveMinutes($startTime, $endTime, $breakMinutes) <= 0) {
			throw new \InvalidArgumentException('INVALID_SHIFT_LENGTH');
		}

		$period = $this->periodById($periodId);
		if ($period['status'] !== 'open') {
			throw new \InvalidArgumentException('PERIOD_NOT_OPEN');
		}
		if ($dutyDate < $period['startDate'] || $dutyDate > $period['endDate']) {
			throw new \InvalidArgumentException('DATE_OUTSIDE_PERIOD');
		}
		$this->assertEmployeeExists($employeeId);
		$this->assertLocationExists($locationId);
		$this->assertNoAbsenceConflict($employeeId, $dutyDate);
		$this->assertNoOverlapConflict($periodId, $employeeId, $dutyDate, $startTime, $endTime);
		$softConflicts = $this->candidateSoftConflicts($periodId, $employeeId, $dutyDate, $startTime, $endTime);
		if ($softConflicts !== []) {
			$this->assertAcknowledgedSoftConflicts($softConflicts, $acknowledgements);
		}

		$this->db->beginTransaction();
		try {
			$qb = $this->db->getQueryBuilder();
			try {
				$qb->insert('dc_assignments')
					->values([
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
					])->executeStatement();
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
	private function candidateSoftConflicts(int $periodId, int $employeeId, string $dutyDate, string $startTime, string $endTime): array
	{
		$candidateRange = $this->assignmentAbsoluteRange($dutyDate, $startTime, $endTime);
		$assignments = $this->listAssignments($periodId);
		$soft = [];
		foreach ($assignments as $assignment) {
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
			if ($restMinutes >= 0 && $restMinutes < 660) {
				$soft[] = [
					'type' => 'rest_time_violation',
					'severity' => 'soft',
					'message' => 'Less than 11 hours rest between consecutive assignments',
					'assignmentIds' => [(int) $assignment['id']],
					'payload' => ['restMinutes' => $restMinutes, 'minRestMinutes' => 660],
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
		$qb->insert('dc_absences')
			->values([
				'employee_id' => $qb->createNamedParameter($employeeId, IQueryBuilder::PARAM_INT),
				'kind' => $qb->createNamedParameter($kind),
				'start_date' => $qb->createNamedParameter($startDate),
				'end_date' => $qb->createNamedParameter($endDate),
				'status' => $qb->createNamedParameter('pending'),
				'created_by' => $qb->createNamedParameter($actor),
				'created_at' => $qb->createNamedParameter($this->now()),
			])->executeStatement();

		return $this->listAbsences();
	}

	public function transitionAbsence(int $absenceId, string $targetStatus, string $reviewReason = '', string $actorUserId = ''): array
	{
		$allowedStatuses = ['pending', 'approved', 'rejected', 'cancelled'];
		$targetStatus = trim($targetStatus);
		if (!in_array($targetStatus, $allowedStatuses, true)) {
			throw new \InvalidArgumentException('INVALID_ABSENCE_STATUS');
		}

		$current = $this->absenceById($absenceId);
		$this->assertIntegrationAllowsDcAbsenceForEmployee($current['employeeId']);
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
			->executeStatement();

		return $this->listAbsences();
	}

	public function listAbsences(): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('a.id', 'a.employee_id', 'a.kind', 'a.start_date', 'a.end_date', 'a.status', 'a.review_reason', 'e.display_name')
			->from('dc_absences', 'a')
			->leftJoin('a', 'dc_employees', 'e', 'a.employee_id = e.id')
			->orderBy('a.start_date', 'DESC');
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
			$mapped = array_merge($mapped, $this->atIntegration->listMirrorRowsForPlanner());
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

	public function listEmployeeCatalog(): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'display_name', 'linked_user_id', 'active', 'created_at')
			->from('dc_employees')
			->orderBy('display_name', 'ASC');
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

	public function createEmployee(array $payload): array
	{
		$linkedUserId = $this->normalizeLinkedUserId($payload['linkedUserId'] ?? null);
		$displayName = $this->resolveDisplayNameFromPayload($payload, $linkedUserId);
		$active = $this->toActiveFlag($payload['active'] ?? 1);

		$this->assertEmployeeDisplayNameUnique($displayName);
		$this->assertLinkedUserUnique($linkedUserId, null);

		$qb = $this->db->getQueryBuilder();
		$qb->insert('dc_employees')->values([
			'display_name' => $qb->createNamedParameter($displayName),
			'linked_user_id' => $qb->createNamedParameter($linkedUserId),
			'active' => $qb->createNamedParameter($active, IQueryBuilder::PARAM_INT),
			'created_at' => $qb->createNamedParameter($this->now()),
		])->executeStatement();

		return $this->listEmployeeCatalog();
	}

	public function updateEmployee(int $id, array $payload): array
	{
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

		return $this->listEmployeeCatalog();
	}

	public function listLocationCatalog(): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'name', 'timezone', 'active', 'created_at')
			->from('dc_locations')
			->orderBy('name', 'ASC');
		$rows = $qb->executeQuery()->fetchAll();
		return array_map(static fn (array $r): array => [
			'id' => (int) $r['id'],
			'name' => (string) $r['name'],
			'timezone' => (string) $r['timezone'],
			'active' => (int) $r['active'] === 1,
			'createdAt' => (string) $r['created_at'],
		], $rows);
	}

	public function createLocation(array $payload): array
	{
		$name = $this->validateSimpleLabel((string) ($payload['name'] ?? ''), 'INVALID_LOCATION_NAME');
		$timezone = $this->validateTimezone((string) ($payload['timezone'] ?? ''));
		$active = $this->toActiveFlag($payload['active'] ?? 1);
		$this->assertLocationNameUnique($name);

		$qb = $this->db->getQueryBuilder();
		$qb->insert('dc_locations')->values([
			'name' => $qb->createNamedParameter($name),
			'timezone' => $qb->createNamedParameter($timezone),
			'active' => $qb->createNamedParameter($active, IQueryBuilder::PARAM_INT),
			'created_at' => $qb->createNamedParameter($this->now()),
		])->executeStatement();

		return $this->listLocationCatalog();
	}

	public function updateLocation(int $id, array $payload): array
	{
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

		return $this->listLocationCatalog();
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
		$qb->select('a.id', 'a.duty_date', 'a.start_time', 'a.end_time', 'a.break_minutes', 'a.note', 'l.name AS location_name', 'p.status')
			->from('dc_assignments', 'a')
			->innerJoin('a', 'dc_periods', 'p', 'a.period_id = p.id')
			->leftJoin('a', 'dc_locations', 'l', 'a.location_id = l.id')
			->where($qb->expr()->eq('a.employee_id', $qb->createNamedParameter($employeeId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('p.status', $qb->createNamedParameter('published')))
			->andWhere($qb->expr()->gte('a.duty_date', $qb->createNamedParameter($fromIso)))
			->andWhere($qb->expr()->lte('a.duty_date', $qb->createNamedParameter($toIso)))
			->orderBy('a.duty_date', 'ASC')
			->addOrderBy('a.start_time', 'ASC');
		$rows = $qb->executeQuery()->fetchAll();

		return array_map(static fn (array $r): array => [
			'id' => (int) $r['id'],
			'dutyDate' => (string) $r['duty_date'],
			'startTime' => (string) $r['start_time'],
			'endTime' => (string) $r['end_time'],
			'breakMinutes' => (int) $r['break_minutes'],
			'note' => (string) ($r['note'] ?? ''),
			'locationName' => (string) ($r['location_name'] ?? ''),
		], $rows);
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
		$userId = $this->linkedUserIdByEmployeeId($employeeId);
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
		$qb->select('id', 'start_date', 'end_date', 'status', 'created_by', 'created_at', 'published_at', 'closed_at', 'close_snapshot_id')
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
		return [
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
	}

	private function createSnapshot(int $periodId, string $snapshotKind, string $actorUserId): int
	{
		$period = $this->periodById($periodId);
		$assignments = $this->listAssignments($periodId);
		$conflicts = $this->conflictsForPeriod($periodId);
		$payload = [
			'period' => $period,
			'assignments' => $assignments,
			'conflicts' => $conflicts,
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

	private function listEmployees(): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'display_name', 'linked_user_id')
			->from('dc_employees')
			->where($qb->expr()->eq('active', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
			->orderBy('display_name', 'ASC');
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

	private function listLocations(): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'name', 'timezone')
			->from('dc_locations')
			->where($qb->expr()->eq('active', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
			->orderBy('name', 'ASC');
		$rows = $qb->executeQuery()->fetchAll();
		return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'name' => (string) $r['name'], 'timezone' => (string) $r['timezone']], $rows);
	}

	private function listAssignments(int $periodId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('a.id', 'a.period_id', 'a.employee_id', 'a.location_id', 'a.duty_date', 'a.start_time', 'a.end_time', 'a.break_minutes', 'a.note', 'e.display_name', 'l.name AS location_name')
			->from('dc_assignments', 'a')
			->leftJoin('a', 'dc_employees', 'e', 'a.employee_id = e.id')
			->leftJoin('a', 'dc_locations', 'l', 'a.location_id = l.id')
			->where($qb->expr()->eq('a.period_id', $qb->createNamedParameter($periodId, IQueryBuilder::PARAM_INT)))
			->orderBy('a.duty_date', 'ASC')
			->addOrderBy('a.start_time', 'ASC');
		$rows = $qb->executeQuery()->fetchAll();
		return array_map(static fn (array $r): array => [
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
		], $rows);
	}

	private function conflictsForPeriod(int $periodId): array
	{
		$period = $this->periodById($periodId);
		$assignments = $this->listAssignments($periodId);
		$conflicts = [];
		$conflictDedup = [];
		$thresholds = [
			'maxDailyHard' => 600,
			'maxWeeklySoft' => 2880,
			'maxWeeklyHard' => 3600,
			'maxConsecutiveDays' => 6,
		];
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
				if ($gapMinutes >= 0 && $gapMinutes < 660) {
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
						'details' => ['restMinutes' => $gapMinutes, 'minRestMinutes' => 660],
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

			if ($totalMinutes > $thresholds['maxWeeklyHard']) {
				$key = 'weekly_hours_hard_cap:' . $employeeId;
				if (!isset($conflictDedup[$key])) {
					$conflictDedup[$key] = true;
					$conflicts[] = [
						'type' => 'weekly_hours_hard_cap',
						'severity' => 'hard',
						'message' => 'Weekly hard cap exceeded for employee',
						'employeeId' => (int) $employeeId,
						'assignmentIds' => array_map(static fn (array $item): int => (int) $item['id'], $employeeAssignments),
						'details' => ['totalMinutes' => $totalMinutes, 'maxWeeklyHard' => $thresholds['maxWeeklyHard']],
					];
				}
			} elseif ($totalMinutes > $thresholds['maxWeeklySoft']) {
				$key = 'weekly_hours_exceeded:' . $employeeId;
				if (!isset($conflictDedup[$key])) {
					$conflictDedup[$key] = true;
					$conflicts[] = [
						'type' => 'weekly_hours_exceeded',
						'severity' => 'soft',
						'message' => 'Weekly soft cap exceeded for employee',
						'employeeId' => (int) $employeeId,
						'assignmentIds' => array_map(static fn (array $item): int => (int) $item['id'], $employeeAssignments),
						'details' => ['totalMinutes' => $totalMinutes, 'maxWeeklySoft' => $thresholds['maxWeeklySoft']],
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
			if (!$this->hasApprovedAbsenceOnDate((int) $assignment['employeeId'], (string) $assignment['dutyDate'])) {
				continue;
			}
			$key = 'absence_collision:' . $assignment['id'];
			if (isset($conflictDedup[$key])) {
				continue;
			}
			$conflictDedup[$key] = true;
			$conflicts[] = [
				'type' => 'absence_collision',
				'severity' => 'hard',
				'message' => 'Employee assignment collides with approved absence',
				'employeeId' => (int) $assignment['employeeId'],
				'assignmentIds' => [(int) $assignment['id']],
			];
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

		return $conflicts;
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

	private function assertNoOverlapConflict(int $periodId, int $employeeId, string $dutyDate, string $startTime, string $endTime): void
	{
		$candidateRange = $this->assignmentAbsoluteRange($dutyDate, $startTime, $endTime);
		$assignments = $this->listAssignments($periodId);
		foreach ($assignments as $assignment) {
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
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'cnt'))
			->from($table)
			->where($qb->expr()->eq($column, $qb->createNamedParameter($value)));
		return (int) $qb->executeQuery()->fetchOne();
	}

	private function countAll(string $table): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'cnt'))->from($table);
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
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from('dc_absences')
			->where($qb->expr()->eq('employee_id', $qb->createNamedParameter($employeeId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('approved')))
			->andWhere($qb->expr()->lte('start_date', $qb->createNamedParameter($date)))
			->andWhere($qb->expr()->gte('end_date', $qb->createNamedParameter($date)))
			->setMaxResults(1);
		if ($qb->executeQuery()->fetchOne() !== false) {
			return true;
		}
		return $this->atIntegration?->hasImportedBlockingAbsenceOnDate($employeeId, $date) ?? false;
	}

	private function assertIntegrationAllowsDcAbsenceForEmployee(int $employeeId): void
	{
		if (!$this->atIntegration?->integrationLocksLinkedDutyCheckAbsences()) {
			return;
		}
		$uid = $this->linkedUserIdForEmployeeId($employeeId);
		if ($uid !== null && $uid !== '') {
			throw new \InvalidArgumentException('INTEGRATION_ABSENCE_READONLY');
		}
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

	private function assertNoDuplicatePeriodRange(string $startDate, string $endDate): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from('dc_periods')
			->where($qb->expr()->eq('start_date', $qb->createNamedParameter($startDate)))
			->andWhere($qb->expr()->eq('end_date', $qb->createNamedParameter($endDate)))
			->setMaxResults(1);
		if ($qb->executeQuery()->fetchOne() !== false) {
			throw new \InvalidArgumentException('PERIOD_RANGE_EXISTS');
		}
	}

	private function findOverlappingPeriods(string $startDate, string $endDate, int $ignoreId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'start_date', 'end_date')
			->from('dc_periods')
			->where($qb->expr()->neq('id', $qb->createNamedParameter($ignoreId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->lte('start_date', $qb->createNamedParameter($endDate)))
			->andWhere($qb->expr()->gte('end_date', $qb->createNamedParameter($startDate)));

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
