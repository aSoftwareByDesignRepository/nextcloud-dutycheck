<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Service;

use OCA\DutyCheck\AppInfo\Application;
use OCA\DutyCheck\Db\SchemaProbe;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Notification\IManager as INotificationManager;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Shift swap requests (employee → employee or open pool) with planner review.
 * Notifies both parties on request / approve / reject (B1).
 *
 * Bilateral accept (GA): acceptByCounterparty → accepted_by_counterparty →
 * applied (bilateral_auto + guards) or pending_planner / needs_planner.
 * Planner review and bilateral_auto share {@see applyApprovedSwap} (CAS + transfer).
 *
 * DI note — new optional ctor params (nullable, append-only; existing Application.php
 * registration remains valid without changes):
 *   7. ?SelfServiceSettingsService $settings = null
 *   8. ?QualificationService $qualifications = null
 *   9. ?PushQuietHoursService $quiet = null
 *  10. ?PeriodLockService $locks = null
 */
class SwapService
{
	/** Statuses a planner may approve/reject. */
	private const REVIEWABLE = [
		'pending',
		'pending_planner',
		'needs_planner',
		'accepted_by_counterparty',
	];

	/** Statuses that block a second swap on the same assignment. */
	private const OPEN_STATUSES = [
		'pending',
		'pending_planner',
		'needs_planner',
		'accepted_by_counterparty',
	];

	public function __construct(
		private readonly IDBConnection $db,
		private readonly RosterService $roster,
		private readonly ?INotificationManager $notifications = null,
		private readonly ?IURLGenerator $urlGenerator = null,
		private readonly ?LoggerInterface $logger = null,
		private readonly ?CompanyService $companies = null,
		private readonly ?SelfServiceSettingsService $settings = null,
		private readonly ?QualificationService $qualifications = null,
		private readonly ?PushQuietHoursService $quiet = null,
		private readonly ?PeriodLockService $locks = null,
		private readonly ?PlannerLocationScopeService $plannerScope = null,
	) {
	}

	/**
	 * @return array<string,mixed>
	 */
	public function requestSwap(int $assignmentId, string $actorUserId, ?int $toEmployeeId, string $reason = ''): array
	{
		$employeeId = $this->linkedEmployeeId($actorUserId);
		$row = $this->assignment($assignmentId);
		// Existence-blind: someone else's assignment is reported exactly like a
		// missing one — employees must not be able to enumerate assignment ids.
		if ((int) $row['employee_id'] !== $employeeId) {
			throw new \InvalidArgumentException('ASSIGNMENT_NOT_FOUND');
		}
		if ((string) ($row['status'] ?? 'active') === 'cancelled') {
			throw new \InvalidArgumentException('ASSIGNMENT_CANCELLED');
		}
		$period = $this->periodStatus((int) $row['period_id']);
		if (!in_array($period, ['published', 'open'], true)) {
			throw new \InvalidArgumentException('PERIOD_NOT_OPEN');
		}
		if ($toEmployeeId !== null && $toEmployeeId === $employeeId) {
			throw new \InvalidArgumentException('SWAP_SAME_EMPLOYEE');
		}
		if ($toEmployeeId !== null) {
			$this->assertEmployeeExists($toEmployeeId);
			$this->assertSameCompanyEmployees($employeeId, $toEmployeeId);
		}

		$this->assertNoPendingSwapForAssignment($assignmentId);

		$holder = $actorUserId . ':' . bin2hex(random_bytes(4));
		$locked = $this->locks !== null
			&& $this->locks->acquire($assignmentId, PeriodLockService::KIND_SWAP_REQ, $holder, 15);
		if ($this->locks !== null && !$locked) {
			throw new \InvalidArgumentException('SWAP_ALREADY_PENDING');
		}

		try {
			// Re-check under mutex — closes check-then-insert race.
			$this->assertNoPendingSwapForAssignment($assignmentId);

			$now = (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s');
			$qb = $this->db->getQueryBuilder();
			$values = [
				'assignment_id' => $qb->createNamedParameter($assignmentId, IQueryBuilder::PARAM_INT),
				'from_employee_id' => $qb->createNamedParameter($employeeId, IQueryBuilder::PARAM_INT),
				'to_employee_id' => $qb->createNamedParameter($toEmployeeId, IQueryBuilder::PARAM_INT),
				'status' => $qb->createNamedParameter('pending'),
				'reason' => $qb->createNamedParameter(mb_substr(trim($reason), 0, 512) ?: null),
				'created_at' => $qb->createNamedParameter($now),
			];
			if ($this->companies !== null && SchemaProbe::hasColumn($this->db, 'dc_swap_requests', 'company_id')) {
				$values['company_id'] = $qb->createNamedParameter(
					$this->periodCompanyId((int) $row['period_id']),
					IQueryBuilder::PARAM_INT,
				);
			}
			$qb->insert('dc_swap_requests')->values($values)->executeStatement();

			$swap = $this->getById((int) $qb->getLastInsertId());
			$this->notifyParties($swap, 'swap_requested');
			return $swap;
		} finally {
			if ($locked && $this->locks !== null) {
				$this->locks->release($assignmentId, PeriodLockService::KIND_SWAP_REQ, $holder);
			}
		}
	}

	/**
	 * Counterparty accepts a bilateral swap request.
	 *
	 * @return array<string,mixed>
	 */
	public function acceptByCounterparty(int $swapId, string $actorUserId): array
	{
		// Capability-before-lookup: resolve the actor's link BEFORE the row read.
		// A non-linked user must get EMPLOYEE_LINK_NOT_FOUND for existing and
		// missing ids alike — running getById first leaked existence
		// (missing → SWAP_NOT_FOUND vs existing → EMPLOYEE_LINK_NOT_FOUND).
		$actorEmployeeId = $this->linkedEmployeeId($actorUserId);
		$swap = $this->getById($swapId);
		// Existence-blind: a swap not addressed to this employee is reported
		// exactly like a missing one — swap ids must not be enumerable.
		if ((int) ($swap['toEmployeeId'] ?? 0) !== $actorEmployeeId) {
			throw new \InvalidArgumentException('SWAP_NOT_FOUND');
		}
		if ($swap['status'] !== 'pending') {
			throw new \InvalidArgumentException('SWAP_NOT_PENDING');
		}

		$now = (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s');
		$cas = $this->db->getQueryBuilder();
		$set = $cas->update('dc_swap_requests')
			->set('status', $cas->createNamedParameter('accepted_by_counterparty'))
			->where($cas->expr()->eq('id', $cas->createNamedParameter($swapId, IQueryBuilder::PARAM_INT)))
			->andWhere($cas->expr()->eq('status', $cas->createNamedParameter('pending')));
		if (SchemaProbe::hasColumn($this->db, 'dc_swap_requests', 'counterparty_at')) {
			$set->set('counterparty_at', $cas->createNamedParameter($now));
		}
		if ($set->executeStatement() !== 1) {
			throw new \InvalidArgumentException('SWAP_NOT_PENDING');
		}

		$swap = $this->getById($swapId);
		$companyId = $this->swapCompanyId($swap);
		$mode = $this->settings !== null
			? $this->settings->swapApprovalMode($companyId)
			: SelfServiceSettingsService::SWAP_PLANNER_REQUIRED;

		if ($mode === SelfServiceSettingsService::SWAP_BILATERAL_AUTO) {
			try {
				$row = $this->assignment((int) $swap['assignmentId']);
				$this->assertSwapGuards($swap, $row);
				return $this->applyApprovedSwap($swap, $actorUserId, 'accepted_by_counterparty', 'applied', null, $row);
			} catch (\InvalidArgumentException $e) {
				$code = $e->getMessage();
				if (in_array($code, [
					'SWAP_ABSENCE_CONFLICT',
					'SWAP_QUALIFICATION',
					'SWAP_CROSS_LOCATION_FORBIDDEN',
					'SWAP_CONFLICT',
				], true)) {
					$this->transitionStatus($swapId, 'accepted_by_counterparty', 'needs_planner');
					$updated = $this->getById($swapId);
					$this->notifyParties($updated, 'swap_needs_planner');
					return $updated;
				}
				throw $e;
			}
		}

		$this->transitionStatus($swapId, 'accepted_by_counterparty', 'pending_planner');
		$updated = $this->getById($swapId);
		$this->notifyParties($updated, 'swap_pending_planner');
		return $updated;
	}

	/**
	 * @return array<string,mixed>
	 */
	public function review(int $swapId, string $actor, string $decision, string $reviewReason = ''): array
	{
		$swap = $this->getById($swapId);
		// Company/scope gates BEFORE the status check — a hidden swap must not
		// leak existence via SWAP_NOT_PENDING vs SWAP_NOT_FOUND.
		try {
			$row = $this->assignment((int) $swap['assignmentId']);
		} catch (\InvalidArgumentException $e) {
			if ($e->getMessage() === 'ASSIGNMENT_NOT_FOUND') {
				throw new \InvalidArgumentException('SWAP_NOT_FOUND');
			}
			throw $e;
		}
		$this->roster->assertPeriodCompanyAccess($actor, (int) $row['period_id'], 'SWAP_NOT_FOUND');
		if ($this->plannerScope !== null) {
			// Scoped planners cannot see out-of-scope swaps in listPending, so a
			// scope miss must collapse to the same code as a missing swap.
			try {
				$this->plannerScope->assertCanPlanLocation($actor, (int) $row['location_id']);
			} catch (\InvalidArgumentException $e) {
				if ($e->getMessage() === 'LOCATION_OUT_OF_SCOPE') {
					throw new \InvalidArgumentException('SWAP_NOT_FOUND');
				}
				throw $e;
			}
		}
		if (!in_array($swap['status'], self::REVIEWABLE, true)) {
			throw new \InvalidArgumentException('SWAP_NOT_PENDING');
		}
		$decision = trim($decision);
		if (!in_array($decision, ['approved', 'rejected'], true)) {
			throw new \InvalidArgumentException('INVALID_SWAP_DECISION');
		}

		$fromStatus = (string) $swap['status'];
		$reviewReasonStored = mb_substr(trim($reviewReason), 0, 512) ?: null;

		if ($decision === 'rejected') {
			$now = (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s');
			$cas = $this->db->getQueryBuilder();
			$affected = $cas->update('dc_swap_requests')
				->set('status', $cas->createNamedParameter('rejected'))
				->set('review_reason', $cas->createNamedParameter($reviewReasonStored))
				->set('reviewed_by', $cas->createNamedParameter($actor))
				->set('reviewed_at', $cas->createNamedParameter($now))
				->where($cas->expr()->eq('id', $cas->createNamedParameter($swapId, IQueryBuilder::PARAM_INT)))
				->andWhere($cas->expr()->eq('status', $cas->createNamedParameter($fromStatus)))
				->executeStatement();
			if ($affected !== 1) {
				throw new \InvalidArgumentException('SWAP_NOT_PENDING');
			}
			$updated = $this->getById($swapId);
			$this->notifyParties($updated, 'swap_rejected');
			return $updated;
		}

		$this->assertSwapGuards($swap, $row);
		return $this->applyApprovedSwap($swap, $actor, $fromStatus, 'approved', $reviewReasonStored, $row);
	}

	/** @return list<array<string,mixed>> */
	public function listPending(?string $actorUserId = null): array
	{
		if (!$this->db->tableExists('dc_swap_requests')) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('dc_swap_requests')
			->where($qb->expr()->in(
				'status',
				$qb->createNamedParameter(self::REVIEWABLE, IQueryBuilder::PARAM_STR_ARRAY),
			))
			->orderBy('created_at', 'ASC');
		if ($actorUserId !== null && $this->companies !== null && SchemaProbe::hasColumn($this->db, 'dc_swap_requests', 'company_id')) {
			$this->companies->restrictQuery($qb, 'company_id', $actorUserId);
		}
		$rows = array_map([$this, 'normalize'], $qb->executeQuery()->fetchAll());
		if ($actorUserId !== null && $this->plannerScope !== null) {
			$allowed = $this->plannerScope->locationIdsFor($actorUserId);
			if ($allowed !== []) {
				$out = [];
				foreach ($rows as $swap) {
					try {
						$assignment = $this->assignment((int) $swap['assignmentId']);
					} catch (\InvalidArgumentException) {
						continue;
					}
					if (in_array((int) $assignment['location_id'], $allowed, true)) {
						$out[] = $swap;
					}
				}
				$rows = $out;
			}
		}
		return array_map([$this, 'enrichSwapLabels'], $rows);
	}

	/**
	 * Additive display fields for planner UI (names + duty window) — never remove IDs.
	 *
	 * @param array<string,mixed> $swap
	 * @return array<string,mixed>
	 */
	private function enrichSwapLabels(array $swap): array
	{
		$fromId = (int) ($swap['fromEmployeeId'] ?? 0);
		$toId = isset($swap['toEmployeeId']) ? (int) $swap['toEmployeeId'] : 0;
		$swap['fromEmployeeName'] = $this->employeeDisplayName($fromId);
		$swap['toEmployeeName'] = $toId > 0 ? $this->employeeDisplayName($toId) : null;
		try {
			$assignment = $this->assignment((int) ($swap['assignmentId'] ?? 0));
			$swap['dutyDate'] = (string) ($assignment['duty_date'] ?? '');
			$swap['startTime'] = (string) ($assignment['start_time'] ?? '');
			$swap['endTime'] = (string) ($assignment['end_time'] ?? '');
			$locId = (int) ($assignment['location_id'] ?? 0);
			$swap['locationId'] = $locId > 0 ? $locId : null;
			$swap['locationName'] = $locId > 0 ? $this->locationDisplayName($locId) : null;
		} catch (\InvalidArgumentException) {
			$swap['dutyDate'] = '';
			$swap['startTime'] = '';
			$swap['endTime'] = '';
			$swap['locationId'] = null;
			$swap['locationName'] = null;
		}
		return $swap;
	}

	private function locationDisplayName(int $locationId): string
	{
		if ($locationId <= 0 || !$this->db->tableExists('dc_locations')) {
			return '';
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('name')->from('dc_locations')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($locationId, IQueryBuilder::PARAM_INT)));
		$row = $qb->executeQuery()->fetch();
		if ($row === false) {
			return '';
		}
		return trim((string) ($row['name'] ?? ''));
	}

	private function employeeDisplayName(int $employeeId): string
	{
		if ($employeeId <= 0 || !$this->db->tableExists('dc_employees')) {
			return '';
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('display_name')->from('dc_employees')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($employeeId, IQueryBuilder::PARAM_INT)));
		$row = $qb->executeQuery()->fetch();
		if ($row === false) {
			return '';
		}
		return trim((string) ($row['display_name'] ?? ''));
	}

	/** @return list<array{id:int,displayName:string}> */
	public function listSwapCandidates(string $actorUserId): array
	{
		$selfId = $this->linkedEmployeeId($actorUserId);
		if (!$this->db->tableExists('dc_employees')) {
			return [];
		}
		// Privacy: do not dump the whole company directory — only colleagues who
		// shared a location with the caller in the peer belonging lookback window.
		$myLocs = $this->recentLocationIdsForEmployee($selfId);
		if ($myLocs === []) {
			return [];
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('e.id', 'e.display_name')
			->from('dc_employees', 'e')
			->innerJoin('e', 'dc_assignments', 'a', $qb->expr()->eq('a.employee_id', 'e.id'))
			->where($qb->expr()->eq('e.active', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->neq('e.id', $qb->createNamedParameter($selfId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->in(
				'a.location_id',
				$qb->createNamedParameter($myLocs, IQueryBuilder::PARAM_INT_ARRAY),
			))
			->andWhere($qb->expr()->gte(
				'a.duty_date',
				$qb->createNamedParameter($this->belongingCutoffYmd()),
			))
			->groupBy('e.id', 'e.display_name')
			->orderBy('e.display_name', 'ASC');
		if (SchemaProbe::hasColumn($this->db, 'dc_assignments', 'status')) {
			$qb->andWhere($qb->expr()->orX(
				$qb->expr()->neq('a.status', $qb->createNamedParameter('cancelled')),
				$qb->expr()->isNull('a.status'),
			));
		}
		if ($this->companies !== null
			&& $this->companies->isMultiCompanyActive()
			&& SchemaProbe::hasColumn($this->db, 'dc_employees', 'company_id')) {
			$selfCompany = $this->employeeCompanyId($selfId);
			$qb->andWhere($qb->expr()->eq('e.company_id', $qb->createNamedParameter($selfCompany, IQueryBuilder::PARAM_INT)));
		}
		$rows = $qb->executeQuery()->fetchAll();
		$out = [];
		foreach ($rows as $row) {
			$name = trim((string) ($row['display_name'] ?? ''));
			if ($name === '') {
				continue;
			}
			$out[] = [
				'id' => (int) $row['id'],
				'displayName' => $name,
			];
		}
		return $out;
	}

	/** @return list<int> */
	private function recentLocationIdsForEmployee(int $employeeId): array
	{
		if ($employeeId < 1 || !$this->db->tableExists('dc_assignments')) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->selectDistinct('location_id')
			->from('dc_assignments')
			->where($qb->expr()->eq('employee_id', $qb->createNamedParameter($employeeId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->gte('duty_date', $qb->createNamedParameter($this->belongingCutoffYmd())))
			->andWhere($qb->expr()->lte('duty_date', $qb->createNamedParameter(
				(new \DateTimeImmutable('today'))->format('Y-m-d'),
			)));
		if (SchemaProbe::hasColumn($this->db, 'dc_assignments', 'status')) {
			$qb->andWhere($qb->expr()->orX(
				$qb->expr()->neq('status', $qb->createNamedParameter('cancelled')),
				$qb->expr()->isNull('status'),
			));
		}
		$ids = [];
		foreach ($qb->executeQuery()->fetchAll() as $row) {
			$id = (int) ($row['location_id'] ?? 0);
			if ($id > 0) {
				$ids[] = $id;
			}
		}
		return array_values(array_unique($ids));
	}

	private function belongingCutoffYmd(): string
	{
		return (new \DateTimeImmutable('today'))
			->modify('-' . PeerRosterService::BELONGING_LOOKBACK_DAYS . ' days')
			->format('Y-m-d');
	}

	/**
	 * Shared apply path for planner approve and bilateral_auto (CAS + transfer / pool).
	 *
	 * @param array<string,mixed> $swap
	 * @param array<string,mixed>|null $assignmentRow preloaded assignment (avoids extra SELECT)
	 * @return array<string,mixed>
	 */
	private function applyApprovedSwap(
		array $swap,
		string $actor,
		string $expectedStatus,
		string $finalStatus,
		?string $reviewReason,
		?array $assignmentRow = null,
	): array {
		$swapId = (int) $swap['id'];
		$row = $assignmentRow ?? $this->assignment((int) $swap['assignmentId']);
		$now = (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s');

		$this->db->beginTransaction();
		try {
			// CAS first — concurrent planners / peer accepts cannot double-apply roster mutations.
			$cas = $this->db->getQueryBuilder();
			$cas->update('dc_swap_requests')
				->set('status', $cas->createNamedParameter($finalStatus))
				->set('review_reason', $cas->createNamedParameter($reviewReason))
				->set('reviewed_by', $cas->createNamedParameter($actor))
				->set('reviewed_at', $cas->createNamedParameter($now));
			if (SchemaProbe::hasColumn($this->db, 'dc_swap_requests', 'applied_at')
				&& in_array($finalStatus, ['approved', 'applied'], true)) {
				$cas->set('applied_at', $cas->createNamedParameter($now));
			}
			$affected = $cas
				->where($cas->expr()->eq('id', $cas->createNamedParameter($swapId, IQueryBuilder::PARAM_INT)))
				->andWhere($cas->expr()->eq('status', $cas->createNamedParameter($expectedStatus)))
				->executeStatement();
			if ($affected !== 1) {
				throw new \InvalidArgumentException('SWAP_NOT_PENDING');
			}

			$toEmployeeId = $swap['toEmployeeId'];
			if ($toEmployeeId === null) {
				// Pool path: create the open slot FIRST so a create failure never orphans a cancelled shift.
				$open = new OpenShiftService($this->db, $this->roster, $this->companies, $this->settings);
				$created = $open->create([
					'periodId' => (int) $row['period_id'],
					'locationId' => (int) $row['location_id'],
					'dutyDate' => (string) $row['duty_date'],
					'startTime' => (string) $row['start_time'],
					'endTime' => (string) $row['end_time'],
					'breakMinutes' => (int) $row['break_minutes'],
				], $actor, true);
				try {
					$this->roster->cancelAssignment((int) $swap['assignmentId'], $actor, true, false, true);
				} catch (\Throwable $cancelError) {
					try {
						$open->discardOpen((int) $created['id'], $actor, true);
					} catch (\Throwable) {
						// Best-effort compensation; still surface the cancel failure.
					}
					throw $cancelError;
				}
			} else {
				try {
					$this->roster->transferAssignmentEmployee(
						(int) $swap['assignmentId'],
						(int) $swap['fromEmployeeId'],
						(int) $toEmployeeId,
						$actor,
						false,
						true,
					);
				} catch (\InvalidArgumentException $e) {
					throw new \InvalidArgumentException($this->mapTransferError($e->getMessage()), 0, $e);
				}
			}

			$this->db->commit();
		} catch (\Throwable $e) {
			if ($this->db->inTransaction()) {
				$this->db->rollBack();
			}
			// Safety: if CAS auto-committed on a platform without full TX support, revert status.
			try {
				$revert = $this->db->getQueryBuilder();
				$revert->update('dc_swap_requests')
					->set('status', $revert->createNamedParameter($expectedStatus))
					->set('review_reason', $revert->createNamedParameter(null))
					->set('reviewed_by', $revert->createNamedParameter(null))
					->set('reviewed_at', $revert->createNamedParameter(null));
				if (SchemaProbe::hasColumn($this->db, 'dc_swap_requests', 'applied_at')) {
					$revert->set('applied_at', $revert->createNamedParameter(null));
				}
				$revert->where($revert->expr()->eq('id', $revert->createNamedParameter($swapId, IQueryBuilder::PARAM_INT)))
					->andWhere($revert->expr()->eq('status', $revert->createNamedParameter($finalStatus)))
					->executeStatement();
			} catch (\Throwable) {
				// Best-effort; original error is what callers need.
			}
			throw $e;
		}

		$updated = $this->getById($swapId);
		$this->notifyParties($updated, 'swap_approved');
		return $updated;
	}

	/**
	 * @param array<string,mixed> $swap
	 * @param array<string,mixed>|null $assignmentRow
	 */
	private function assertSwapGuards(array $swap, ?array $assignmentRow = null): void
	{
		$toEmployeeId = $swap['toEmployeeId'];
		$counterId = $swap['counterAssignmentId'] ?? null;

		// Cross-location (true two-shift swap via counter_assignment_id).
		if ($counterId !== null && $this->settings !== null) {
			$companyId = $this->swapCompanyId($swap);
			if (!$this->settings->allowCrossLocationSwaps($companyId)) {
				$row = $assignmentRow ?? $this->assignment((int) $swap['assignmentId']);
				$counter = $this->assignment((int) $counterId);
				if ((int) $row['location_id'] !== (int) $counter['location_id']) {
					throw new \InvalidArgumentException('SWAP_CROSS_LOCATION_FORBIDDEN');
				}
			}
		}

		if ($toEmployeeId === null) {
			return;
		}

		$row = $assignmentRow ?? $this->assignment((int) $swap['assignmentId']);

		// Absence
		if ($this->hasApprovedAbsenceOnDate((int) $toEmployeeId, (string) $row['duty_date'])) {
			throw new \InvalidArgumentException('SWAP_ABSENCE_CONFLICT');
		}

		// Qualification hard conflicts
		if ($this->qualifications !== null) {
			foreach ($this->qualifications->conflictsForAssignment(
				(int) $toEmployeeId,
				(int) $row['location_id'],
				(string) $row['duty_date'],
			) as $qc) {
				if (($qc['severity'] ?? '') === 'hard') {
					throw new \InvalidArgumentException('SWAP_QUALIFICATION');
				}
			}
		}

		// Overlap / marketplace hard gates (maps absence/qual again if quals injected null).
		try {
			$this->roster->assertHardMarketplaceSlot(
				(int) $row['period_id'],
				(int) $toEmployeeId,
				(int) $row['location_id'],
				(string) $row['duty_date'],
				(string) $row['start_time'],
				(string) $row['end_time'],
			);
		} catch (\InvalidArgumentException $e) {
			throw new \InvalidArgumentException($this->mapTransferError($e->getMessage()), 0, $e);
		}
	}

	private function mapTransferError(string $code): string
	{
		return match ($code) {
			'ABSENCE_CONFLICT' => 'SWAP_ABSENCE_CONFLICT',
			'QUALIFICATION_MISSING' => 'SWAP_QUALIFICATION',
			'ASSIGNMENT_OVERLAP',
			'SWAP_CONFLICT',
			'PERIOD_NOT_OPEN',
			'COMPANY_MISMATCH',
			'FORBIDDEN',
			'ASSIGNMENT_TRANSFER_STALE',
			'ASSIGNMENT_DUPLICATE_SLOT',
			'SCHEMA_NOT_READY',
			'ASSIGNMENT_CANCELLED' => 'SWAP_CONFLICT',
			default => str_starts_with($code, 'SWAP_') ? $code : 'SWAP_CONFLICT',
		};
	}

	private function transitionStatus(int $swapId, string $from, string $to): void
	{
		$qb = $this->db->getQueryBuilder();
		$affected = $qb->update('dc_swap_requests')
			->set('status', $qb->createNamedParameter($to))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($swapId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter($from)))
			->executeStatement();
		if ($affected !== 1) {
			throw new \InvalidArgumentException('SWAP_NOT_PENDING');
		}
	}

	/** @param array<string,mixed> $swap */
	private function swapCompanyId(array $swap): int
	{
		$row = $this->assignment((int) $swap['assignmentId']);
		return $this->periodCompanyId((int) $row['period_id']);
	}

	private function hasApprovedAbsenceOnDate(int $employeeId, string $dutyDate): bool
	{
		if (!$this->db->tableExists('dc_absences')) {
			return false;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from('dc_absences')
			->where($qb->expr()->eq('employee_id', $qb->createNamedParameter($employeeId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->lte('start_date', $qb->createNamedParameter($dutyDate)))
			->andWhere($qb->expr()->gte('end_date', $qb->createNamedParameter($dutyDate)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('approved')))
			->setMaxResults(1);
		return $qb->executeQuery()->fetch() !== false;
	}

	private function assertNoPendingSwapForAssignment(int $assignmentId): void
	{
		if (!$this->db->tableExists('dc_swap_requests')) {
			throw new \InvalidArgumentException('SCHEMA_NOT_READY');
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from('dc_swap_requests')
			->where($qb->expr()->eq('assignment_id', $qb->createNamedParameter($assignmentId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->in(
				'status',
				$qb->createNamedParameter(self::OPEN_STATUSES, IQueryBuilder::PARAM_STR_ARRAY),
			))
			->setMaxResults(1);
		if ($qb->executeQuery()->fetch() !== false) {
			throw new \InvalidArgumentException('SWAP_ALREADY_PENDING');
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

	/** @return array<string,mixed> */
	public function getById(int $id): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('dc_swap_requests')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$row = $qb->executeQuery()->fetch();
		if ($row === false) {
			throw new \InvalidArgumentException('SWAP_NOT_FOUND');
		}
		return $this->normalize($row);
	}

	/**
	 * @param array<string,mixed> $swap
	 */
	private function notifyParties(array $swap, string $subject): void
	{
		if ($this->notifications === null || $this->urlGenerator === null) {
			return;
		}
		$uids = [];
		$fromUid = $this->linkedUserForEmployee((int) $swap['fromEmployeeId']);
		if ($fromUid !== null) {
			$uids[$fromUid] = $fromUid;
		}
		if ($swap['toEmployeeId'] !== null) {
			$toUid = $this->linkedUserForEmployee((int) $swap['toEmployeeId']);
			if ($toUid !== null) {
				$uids[$toUid] = $toUid;
			}
		}
		$link = $this->urlGenerator->linkToRouteAbsolute('dutycheck.page.myRoster');
		$companyId = 0;
		try {
			$companyId = $this->swapCompanyId($swap);
		} catch (Throwable) {
			$companyId = 0;
		}
		// Map subjects to quiet-hours defer types (urgent list: swap_decision).
		$deferType = match ($subject) {
			'swap_approved', 'swap_rejected', 'swap_decision',
			'swap_needs_planner', 'swap_pending_planner' => 'swap_decision',
			default => $subject, // swap_requested and others are non-urgent
		};
		foreach ($uids as $uid) {
			try {
				if ($this->quiet !== null
					&& $companyId > 0
					&& $this->quiet->shouldDefer($companyId, $deferType)
				) {
					// Enqueue with Notifier-compatible subject; defer used mapped urgent type.
					$deferred = $this->quiet->enqueue($uid, $companyId, $subject, [
						'swapId' => (string) $swap['id'],
						'type' => $subject,
					]);
					if ($deferred) {
						continue;
					}
				}
				$n = $this->notifications->createNotification();
				$n->setApp(Application::APP_ID)
					->setUser($uid)
					->setDateTime(new \DateTime())
					->setObject('swap', (string) $swap['id'])
					->setSubject($subject, ['swapId' => (string) $swap['id']])
					->setLink($link);
				$this->notifications->notify($n);
			} catch (Throwable $e) {
				$this->logger?->warning('DutyCheck swap notification failed', [
					'app' => Application::APP_ID,
					'userId' => $uid,
					'swapId' => $swap['id'],
					'exception' => $e,
				]);
			}
		}
	}

	/** @param array<string,mixed> $row @return array<string,mixed> */
	private function normalize(array $row): array
	{
		$out = [
			'id' => (int) $row['id'],
			'assignmentId' => (int) $row['assignment_id'],
			'fromEmployeeId' => (int) $row['from_employee_id'],
			'toEmployeeId' => $row['to_employee_id'] !== null ? (int) $row['to_employee_id'] : null,
			'status' => (string) $row['status'],
			'reason' => (string) ($row['reason'] ?? ''),
			'reviewReason' => (string) ($row['review_reason'] ?? ''),
			'reviewedBy' => $row['reviewed_by'] !== null ? (string) $row['reviewed_by'] : null,
			'reviewedAt' => $row['reviewed_at'] !== null ? (string) $row['reviewed_at'] : null,
			'createdAt' => (string) $row['created_at'],
		];
		if (array_key_exists('counterparty_at', $row)) {
			$out['counterpartyAt'] = $row['counterparty_at'] !== null ? (string) $row['counterparty_at'] : null;
		}
		if (array_key_exists('applied_at', $row)) {
			$out['appliedAt'] = $row['applied_at'] !== null ? (string) $row['applied_at'] : null;
		}
		if (array_key_exists('counter_assignment_id', $row)) {
			$out['counterAssignmentId'] = $row['counter_assignment_id'] !== null
				? (int) $row['counter_assignment_id']
				: null;
		} else {
			$out['counterAssignmentId'] = null;
		}
		return $out;
	}

	/** @return array<string,mixed> */
	private function assignment(int $id): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('dc_assignments')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$row = $qb->executeQuery()->fetch();
		if ($row === false) {
			throw new \InvalidArgumentException('ASSIGNMENT_NOT_FOUND');
		}
		return $row;
	}

	private function periodStatus(int $periodId): string
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('status')->from('dc_periods')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($periodId, IQueryBuilder::PARAM_INT)));
		$row = $qb->executeQuery()->fetch();
		if ($row === false) {
			throw new \InvalidArgumentException('PERIOD_NOT_FOUND');
		}
		return (string) $row['status'];
	}

	private function assertEmployeeExists(int $employeeId): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from('dc_employees')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($employeeId, IQueryBuilder::PARAM_INT)));
		if ($qb->executeQuery()->fetch() === false) {
			throw new \InvalidArgumentException('EMPLOYEE_NOT_FOUND');
		}
	}

	private function periodCompanyId(int $periodId): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('company_id')->from('dc_periods')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($periodId, IQueryBuilder::PARAM_INT)));
		$row = $qb->executeQuery()->fetch();
		if ($row === false) {
			throw new \InvalidArgumentException('PERIOD_NOT_FOUND');
		}
		return (int) ($row['company_id'] ?? CompanyService::DEFAULT_COMPANY_ID);
	}

	private function assertSameCompanyEmployees(int $fromEmployeeId, int $toEmployeeId): void
	{
		if ($this->companies === null || !$this->companies->isMultiCompanyActive() || !$this->companies->schemaReady()) {
			return;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'company_id')->from('dc_employees')
			->where($qb->expr()->in('id', $qb->createNamedParameter([$fromEmployeeId, $toEmployeeId], IQueryBuilder::PARAM_INT_ARRAY)));
		$rows = $qb->executeQuery()->fetchAll();
		$byId = [];
		foreach ($rows as $row) {
			$byId[(int) $row['id']] = (int) ($row['company_id'] ?? 0);
		}
		// Existence-blind: a counterparty outside the actor's company is not in
		// their swap-candidate list — report it like a missing employee.
		if (!isset($byId[$fromEmployeeId], $byId[$toEmployeeId]) || $byId[$fromEmployeeId] !== $byId[$toEmployeeId]) {
			throw new \InvalidArgumentException('EMPLOYEE_NOT_FOUND');
		}
	}

	private function linkedEmployeeId(string $userId): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from('dc_employees')
			->where($qb->expr()->eq('linked_user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('active', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)));
		$row = $qb->executeQuery()->fetch();
		if ($row === false) {
			throw new \InvalidArgumentException('EMPLOYEE_LINK_NOT_FOUND');
		}
		return (int) $row['id'];
	}

	private function linkedUserForEmployee(int $employeeId): ?string
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('linked_user_id')->from('dc_employees')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($employeeId, IQueryBuilder::PARAM_INT)));
		$row = $qb->executeQuery()->fetch();
		if ($row === false) {
			return null;
		}
		$uid = trim((string) ($row['linked_user_id'] ?? ''));
		return $uid !== '' ? $uid : null;
	}
}
