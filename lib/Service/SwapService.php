<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Service;

use OCA\DutyCheck\AppInfo\Application;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Notification\IManager as INotificationManager;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Shift swap requests (employee → employee or open pool) with planner review.
 * Notifies both parties on request / approve / reject (B1).
 */
class SwapService
{
	public function __construct(
		private readonly IDBConnection $db,
		private readonly RosterService $roster,
		private readonly ?INotificationManager $notifications = null,
		private readonly ?IURLGenerator $urlGenerator = null,
		private readonly ?LoggerInterface $logger = null,
		private readonly ?CompanyService $companies = null,
	) {
	}

	/**
	 * @return array<string,mixed>
	 */
	public function requestSwap(int $assignmentId, string $actorUserId, ?int $toEmployeeId, string $reason = ''): array
	{
		$employeeId = $this->linkedEmployeeId($actorUserId);
		$row = $this->assignment($assignmentId);
		if ((int) $row['employee_id'] !== $employeeId) {
			throw new \InvalidArgumentException('FORBIDDEN');
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
		if ($this->companies !== null && \OCA\DutyCheck\Db\SchemaProbe::hasColumn($this->db, 'dc_swap_requests', 'company_id')) {
			$values['company_id'] = $qb->createNamedParameter(
				$this->periodCompanyId((int) $row['period_id']),
				IQueryBuilder::PARAM_INT,
			);
		}
		$qb->insert('dc_swap_requests')->values($values)->executeStatement();

		$swap = $this->getById((int) $qb->getLastInsertId());
		$this->notifyParties($swap, 'swap_requested');
		return $swap;
	}

	/**
	 * @return array<string,mixed>
	 */
	public function review(int $swapId, string $actor, string $decision, string $reviewReason = ''): array
	{
		$swap = $this->getById($swapId);
		if ($swap['status'] !== 'pending') {
			throw new \InvalidArgumentException('SWAP_NOT_PENDING');
		}
		$row = $this->assignment((int) $swap['assignmentId']);
		$this->roster->assertPeriodCompanyAccess($actor, (int) $row['period_id']);
		$decision = trim($decision);
		if (!in_array($decision, ['approved', 'rejected'], true)) {
			throw new \InvalidArgumentException('INVALID_SWAP_DECISION');
		}

		$now = (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s');
		$reviewReasonStored = mb_substr(trim($reviewReason), 0, 512) ?: null;
		// CAS first — concurrent planners cannot double-apply roster mutations.
		$cas = $this->db->getQueryBuilder();
		$affected = $cas->update('dc_swap_requests')
			->set('status', $cas->createNamedParameter($decision))
			->set('review_reason', $cas->createNamedParameter($reviewReasonStored))
			->set('reviewed_by', $cas->createNamedParameter($actor))
			->set('reviewed_at', $cas->createNamedParameter($now))
			->where($cas->expr()->eq('id', $cas->createNamedParameter($swapId, IQueryBuilder::PARAM_INT)))
			->andWhere($cas->expr()->eq('status', $cas->createNamedParameter('pending')))
			->executeStatement();
		if ($affected !== 1) {
			throw new \InvalidArgumentException('SWAP_NOT_PENDING');
		}

		if ($decision === 'approved') {
			try {
				$toEmployeeId = $swap['toEmployeeId'];
				if ($toEmployeeId === null) {
					// Pool path: create the open slot FIRST so a create failure never orphans a cancelled shift.
					$open = new OpenShiftService($this->db, $this->roster, $this->companies);
					$created = $open->create([
						'periodId' => (int) $row['period_id'],
						'locationId' => (int) $row['location_id'],
						'dutyDate' => (string) $row['duty_date'],
						'startTime' => (string) $row['start_time'],
						'endTime' => (string) $row['end_time'],
						'breakMinutes' => (int) $row['break_minutes'],
					], $actor);
					try {
						$this->roster->cancelAssignment((int) $swap['assignmentId'], $actor);
					} catch (\Throwable $cancelError) {
						try {
							$open->discardOpen((int) $created['id'], $actor);
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
							$toEmployeeId,
							$actor,
						);
					} catch (\InvalidArgumentException $e) {
						if (in_array($e->getMessage(), [
							'ASSIGNMENT_OVERLAP',
							'ABSENCE_CONFLICT',
							'SWAP_CONFLICT',
							'PERIOD_NOT_OPEN',
							'QUALIFICATION_MISSING',
							'COMPANY_MISMATCH',
							'FORBIDDEN',
							'ASSIGNMENT_TRANSFER_STALE',
							'ASSIGNMENT_DUPLICATE_SLOT',
							'SCHEMA_NOT_READY',
							'ASSIGNMENT_CANCELLED',
						], true)) {
							throw new \InvalidArgumentException('SWAP_CONFLICT');
						}
						throw $e;
					}
				}
			} catch (\Throwable $e) {
				// Revert CAS so a planner can retry after resolving the conflict.
				$revert = $this->db->getQueryBuilder();
				$revert->update('dc_swap_requests')
					->set('status', $revert->createNamedParameter('pending'))
					->set('review_reason', $revert->createNamedParameter(null))
					->set('reviewed_by', $revert->createNamedParameter(null))
					->set('reviewed_at', $revert->createNamedParameter(null))
					->where($revert->expr()->eq('id', $revert->createNamedParameter($swapId, IQueryBuilder::PARAM_INT)))
					->andWhere($revert->expr()->eq('status', $revert->createNamedParameter($decision)))
					->executeStatement();
				throw $e;
			}
		}

		$updated = $this->getById($swapId);
		$this->notifyParties($updated, $decision === 'approved' ? 'swap_approved' : 'swap_rejected');
		return $updated;
	}

	/** @return list<array<string,mixed>> */
	public function listPending(?string $actorUserId = null): array
	{
		if (!$this->db->tableExists('dc_swap_requests')) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('dc_swap_requests')
			->where($qb->expr()->eq('status', $qb->createNamedParameter('pending')))
			->orderBy('created_at', 'ASC');
		if ($actorUserId !== null && $this->companies !== null && \OCA\DutyCheck\Db\SchemaProbe::hasColumn($this->db, 'dc_swap_requests', 'company_id')) {
			$this->companies->restrictQuery($qb, 'company_id', $actorUserId);
		}
		return array_map([$this, 'normalize'], $qb->executeQuery()->fetchAll());
	}

	/** @return list<array{id:int,displayName:string}> */
	public function listSwapCandidates(string $actorUserId): array
	{
		$selfId = $this->linkedEmployeeId($actorUserId);
		if (!$this->db->tableExists('dc_employees')) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'display_name')
			->from('dc_employees')
			->where($qb->expr()->eq('active', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->neq('id', $qb->createNamedParameter($selfId, IQueryBuilder::PARAM_INT)))
			->orderBy('display_name', 'ASC');
		if ($this->companies !== null
			&& $this->companies->isMultiCompanyActive()
			&& \OCA\DutyCheck\Db\SchemaProbe::hasColumn($this->db, 'dc_employees', 'company_id')) {
			$selfCompany = $this->employeeCompanyId($selfId);
			$qb->andWhere($qb->expr()->eq('company_id', $qb->createNamedParameter($selfCompany, IQueryBuilder::PARAM_INT)));
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

	private function assertNoPendingSwapForAssignment(int $assignmentId): void
	{
		if (!$this->db->tableExists('dc_swap_requests')) {
			throw new \InvalidArgumentException('SCHEMA_NOT_READY');
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from('dc_swap_requests')
			->where($qb->expr()->eq('assignment_id', $qb->createNamedParameter($assignmentId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('pending')))
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
		foreach ($uids as $uid) {
			try {
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
		return [
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
		if (!isset($byId[$fromEmployeeId], $byId[$toEmployeeId]) || $byId[$fromEmployeeId] !== $byId[$toEmployeeId]) {
			throw new \InvalidArgumentException('COMPANY_MISMATCH');
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
