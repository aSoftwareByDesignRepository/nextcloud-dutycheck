<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Throwable;

/**
 * Unassigned open shifts that employees may request; planner approves claims (B2).
 *
 * Flow: open → pending (employee claim CAS) → claimed (planner approve + assignment)
 *        pending → open (planner reject / failed assignment)
 *
 * Capacity: one open-shift row = one fillable slot. Claim CAS (open→pending) is the
 * capacity gate — concurrent claims cannot double-book the same slot.
 *
 * Soft conflicts on approve: planner may pass acknowledgements[] (same contract as
 * assignment create). ConflictAckRequiredException is rethrown for UI retry.
 */
class OpenShiftService
{
	public function __construct(
		private readonly IDBConnection $db,
		private readonly RosterService $roster,
		private readonly ?CompanyService $companies = null,
	) {
	}

	/**
	 * @param array<string,mixed> $payload
	 * @return array<string,mixed>
	 */
	public function create(array $payload, string $actor): array
	{
		$periodId = (int) ($payload['periodId'] ?? 0);
		$locationId = (int) ($payload['locationId'] ?? 0);
		$dutyDate = (string) ($payload['dutyDate'] ?? '');
		$start = (string) ($payload['startTime'] ?? '');
		$end = (string) ($payload['endTime'] ?? '');
		$break = max(0, (int) ($payload['breakMinutes'] ?? 0));
		$templateId = isset($payload['templateId']) ? (int) $payload['templateId'] : null;
		if ($periodId <= 0 || $locationId <= 0) {
			throw new \InvalidArgumentException('OPEN_SHIFT_INVALID');
		}
		$this->roster->assertPeriodCompanyAccess($actor, $periodId);
		$this->roster->assertLocationMatchesPeriodCompany($periodId, $locationId);
		$period = $this->periodStatus($periodId);
		if (!in_array($period, ['open', 'published'], true)) {
			throw new \InvalidArgumentException('PERIOD_NOT_OPEN');
		}
		$now = (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s');
		$qb = $this->db->getQueryBuilder();
		$values = [
			'period_id' => $qb->createNamedParameter($periodId, IQueryBuilder::PARAM_INT),
			'location_id' => $qb->createNamedParameter($locationId, IQueryBuilder::PARAM_INT),
			'template_id' => $qb->createNamedParameter($templateId, IQueryBuilder::PARAM_INT),
			'duty_date' => $qb->createNamedParameter($dutyDate),
			'start_time' => $qb->createNamedParameter($start),
			'end_time' => $qb->createNamedParameter($end),
			'break_minutes' => $qb->createNamedParameter($break, IQueryBuilder::PARAM_INT),
			'status' => $qb->createNamedParameter('open'),
			'created_by' => $qb->createNamedParameter($actor),
			'created_at' => $qb->createNamedParameter($now),
		];
		if ($this->companies !== null && \OCA\DutyCheck\Db\SchemaProbe::hasColumn($this->db, 'dc_open_shifts', 'company_id')) {
			$values['company_id'] = $qb->createNamedParameter(
				$this->periodCompanyId($periodId),
				IQueryBuilder::PARAM_INT,
			);
		}
		$qb->insert('dc_open_shifts')->values($values)->executeStatement();
		return $this->getById((int) $qb->getLastInsertId());
	}

	/**
	 * Hard-delete an unused open slot (status=open only). Used to compensate failed pool-swap approve.
	 */
	public function discardOpen(int $openShiftId, string $actor): void
	{
		$open = $this->getById($openShiftId);
		$this->roster->assertPeriodCompanyAccess($actor, (int) $open['periodId']);
		if ($open['status'] !== 'open') {
			throw new \InvalidArgumentException('OPEN_SHIFT_NOT_OPEN');
		}
		$qb = $this->db->getQueryBuilder();
		$affected = $qb->delete('dc_open_shifts')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($openShiftId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('open')))
			->executeStatement();
		if ($affected !== 1) {
			throw new \InvalidArgumentException('OPEN_SHIFT_NOT_OPEN');
		}
	}

	/**
	 * Employee requests a claim — CAS open → pending. No assignment yet (approval required).
	 *
	 * @return array<string,mixed>
	 */
	public function claim(int $openShiftId, string $actorUserId): array
	{
		$employeeId = $this->linkedEmployeeId($actorUserId);
		$open = $this->getById($openShiftId);
		if ($open['status'] !== 'open') {
			throw new \InvalidArgumentException('OPEN_SHIFT_NOT_OPEN');
		}
		$this->assertEmployeeMatchesOpenShiftCompany($employeeId, (int) $open['periodId']);
		$period = $this->periodStatus((int) $open['periodId']);
		if (!in_array($period, ['open', 'published'], true)) {
			throw new \InvalidArgumentException('PERIOD_NOT_OPEN');
		}

		try {
			$this->roster->assertHardMarketplaceSlot(
				(int) $open['periodId'],
				$employeeId,
				(int) $open['locationId'],
				(string) $open['dutyDate'],
				(string) $open['startTime'],
				(string) $open['endTime'],
			);
		} catch (\InvalidArgumentException $e) {
			if (in_array($e->getMessage(), ['ASSIGNMENT_OVERLAP', 'ABSENCE_CONFLICT', 'QUALIFICATION_MISSING'], true)) {
				throw new \InvalidArgumentException('OPEN_SHIFT_CONFLICT');
			}
			throw $e;
		}

		$cas = $this->db->getQueryBuilder();
		$affected = $cas->update('dc_open_shifts')
			->set('status', $cas->createNamedParameter('pending'))
			->set('claimed_by_emp', $cas->createNamedParameter($employeeId, IQueryBuilder::PARAM_INT))
			->where($cas->expr()->eq('id', $cas->createNamedParameter($openShiftId, IQueryBuilder::PARAM_INT)))
			->andWhere($cas->expr()->eq('status', $cas->createNamedParameter('open')))
			->executeStatement();
		if ($affected !== 1) {
			throw new \InvalidArgumentException('OPEN_SHIFT_NOT_OPEN');
		}

		return $this->getById($openShiftId);
	}

	/**
	 * Planner approves a pending claim — creates assignment (published periods allowed).
	 *
	 * @param list<mixed> $acknowledgements Soft-conflict acks (≥10 char reason), same as assignment create
	 * @return array<string,mixed>
	 */
	public function approveClaim(int $openShiftId, string $actor, array $acknowledgements = []): array
	{
		$open = $this->getById($openShiftId);
		$this->roster->assertPeriodCompanyAccess($actor, (int) $open['periodId']);
		if ($open['status'] !== 'pending') {
			throw new \InvalidArgumentException('OPEN_SHIFT_NOT_PENDING');
		}
		$employeeId = (int) ($open['claimedByEmployeeId'] ?? 0);
		if ($employeeId <= 0) {
			throw new \InvalidArgumentException('OPEN_SHIFT_NO_CLAIMANT');
		}

		$assignmentId = null;
		try {
			$data = $this->roster->createAssignment([
				'periodId' => $open['periodId'],
				'employeeId' => $employeeId,
				'locationId' => $open['locationId'],
				'dutyDate' => $open['dutyDate'],
				'startTime' => $open['startTime'],
				'endTime' => $open['endTime'],
				'breakMinutes' => $open['breakMinutes'],
				'note' => '',
				'acknowledgements' => $acknowledgements,
			], $actor, true);
			$assignmentId = (int) ($data['createdAssignmentId'] ?? 0);
			if ($assignmentId <= 0) {
				foreach ($data['assignments'] ?? [] as $row) {
					if ((int) ($row['employeeId'] ?? 0) === $employeeId
						&& (string) ($row['dutyDate'] ?? '') === (string) $open['dutyDate']
						&& substr((string) ($row['startTime'] ?? ''), 0, 5) === substr((string) $open['startTime'], 0, 5)
					) {
						$assignmentId = (int) $row['id'];
						break;
					}
				}
			}
		} catch (\OCA\DutyCheck\Exception\ConflictAckRequiredException $e) {
			// Soft conflicts need planner reason — surface CONFLICT_ACK_REQUIRED (409), do not collapse.
			throw $e;
		} catch (\InvalidArgumentException $e) {
			if (in_array($e->getMessage(), ['ASSIGNMENT_OVERLAP', 'ABSENCE_CONFLICT', 'PERIOD_NOT_OPEN', 'QUALIFICATION_MISSING', 'ASSIGNMENT_DUPLICATE_SLOT'], true)) {
				throw new \InvalidArgumentException('OPEN_SHIFT_CONFLICT');
			}
			throw $e;
		}

		if ($assignmentId <= 0) {
			// Fail closed — never leave a claimed open shift without a roster row.
			throw new \InvalidArgumentException('OPEN_SHIFT_ASSIGNMENT_MISSING');
		}

		$link = $this->db->getQueryBuilder();
		$affected = $link->update('dc_open_shifts')
			->set('status', $link->createNamedParameter('claimed'))
			->set('assignment_id', $link->createNamedParameter($assignmentId, IQueryBuilder::PARAM_INT))
			->where($link->expr()->eq('id', $link->createNamedParameter($openShiftId, IQueryBuilder::PARAM_INT)))
			->andWhere($link->expr()->eq('status', $link->createNamedParameter('pending')))
			->executeStatement();
		if ($affected !== 1) {
			// Lost race against reject/re-approve — roll back orphan assignment (no late-change spam).
			try {
				$this->roster->cancelAssignmentSilent($assignmentId, $actor);
			} catch (\Throwable) {
				// Best-effort cleanup; still fail closed on the open-shift state.
			}
			throw new \InvalidArgumentException('OPEN_SHIFT_NOT_PENDING');
		}

		return $this->getById($openShiftId);
	}

	/**
	 * Planner rejects a pending claim — returns slot to open.
	 *
	 * @return array<string,mixed>
	 */
	public function rejectClaim(int $openShiftId, string $actor): array
	{
		$open = $this->getById($openShiftId);
		$this->roster->assertPeriodCompanyAccess($actor, (int) $open['periodId']);
		if ($open['status'] !== 'pending') {
			throw new \InvalidArgumentException('OPEN_SHIFT_NOT_PENDING');
		}
		if (!$this->releaseToOpen($openShiftId, 'pending')) {
			throw new \InvalidArgumentException('OPEN_SHIFT_NOT_PENDING');
		}
		return $this->getById($openShiftId);
	}

	/** @return list<array<string,mixed>> */
	public function listOpen(?int $periodId = null, ?string $actorUserId = null): array
	{
		return $this->listByStatus('open', $periodId, $actorUserId);
	}

	/** @return list<array<string,mixed>> */
	public function listPending(?int $periodId = null, ?string $actorUserId = null): array
	{
		return $this->listByStatus('pending', $periodId, $actorUserId);
	}

	/** @return array<string,mixed> */
	public function getById(int $id): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('o.*', 'l.name AS location_name')
			->from('dc_open_shifts', 'o')
			->leftJoin('o', 'dc_locations', 'l', $qb->expr()->eq('o.location_id', 'l.id'))
			->where($qb->expr()->eq('o.id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$row = $qb->executeQuery()->fetch();
		if ($row === false) {
			throw new \InvalidArgumentException('OPEN_SHIFT_NOT_FOUND');
		}
		return $this->normalize($row);
	}

	/**
	 * @return list<array<string,mixed>>
	 */
	private function listByStatus(string $status, ?int $periodId, ?string $actorUserId = null): array
	{
		if (!$this->db->tableExists('dc_open_shifts')) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('o.*', 'l.name AS location_name')
			->from('dc_open_shifts', 'o')
			->leftJoin('o', 'dc_locations', 'l', $qb->expr()->eq('o.location_id', 'l.id'))
			->where($qb->expr()->eq('o.status', $qb->createNamedParameter($status)));
		if ($periodId !== null) {
			$qb->andWhere($qb->expr()->eq('o.period_id', $qb->createNamedParameter($periodId, IQueryBuilder::PARAM_INT)));
		}
		if ($actorUserId !== null && $this->companies !== null && \OCA\DutyCheck\Db\SchemaProbe::hasColumn($this->db, 'dc_open_shifts', 'company_id')) {
			$this->companies->restrictQuery($qb, 'o.company_id', $actorUserId);
		}
		$qb->orderBy('o.duty_date', 'ASC')->addOrderBy('o.start_time', 'ASC');
		return array_map([$this, 'normalize'], $qb->executeQuery()->fetchAll());
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

	private function assertEmployeeMatchesOpenShiftCompany(int $employeeId, int $periodId): void
	{
		if ($this->companies === null || !$this->companies->isMultiCompanyActive() || !$this->companies->schemaReady()) {
			return;
		}
		$periodCompany = $this->periodCompanyId($periodId);
		$qb = $this->db->getQueryBuilder();
		$qb->select('company_id')->from('dc_employees')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($employeeId, IQueryBuilder::PARAM_INT)));
		$row = $qb->executeQuery()->fetch();
		if ($row === false) {
			throw new \InvalidArgumentException('EMPLOYEE_NOT_FOUND');
		}
		if ((int) ($row['company_id'] ?? 0) !== $periodCompany) {
			throw new \InvalidArgumentException('COMPANY_MISMATCH');
		}
	}

	private function releaseToOpen(int $openShiftId, string $fromStatus): bool
	{
		$qb = $this->db->getQueryBuilder();
		$affected = $qb->update('dc_open_shifts')
			->set('status', $qb->createNamedParameter('open'))
			->set('claimed_by_emp', $qb->createNamedParameter(null))
			->set('assignment_id', $qb->createNamedParameter(null))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($openShiftId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter($fromStatus)))
			->executeStatement();
		return $affected === 1;
	}

	/** @param array<string,mixed> $row @return array<string,mixed> */
	private function normalize(array $row): array
	{
		$locationName = isset($row['location_name']) ? trim((string) $row['location_name']) : '';
		return [
			'id' => (int) $row['id'],
			'periodId' => (int) $row['period_id'],
			'locationId' => (int) $row['location_id'],
			'locationName' => $locationName !== '' ? $locationName : null,
			'templateId' => $row['template_id'] !== null ? (int) $row['template_id'] : null,
			'dutyDate' => (string) $row['duty_date'],
			'startTime' => (string) $row['start_time'],
			'endTime' => (string) $row['end_time'],
			'breakMinutes' => (int) $row['break_minutes'],
			'status' => (string) $row['status'],
			'claimedByEmployeeId' => $row['claimed_by_emp'] !== null ? (int) $row['claimed_by_emp'] : null,
			'assignmentId' => $row['assignment_id'] !== null ? (int) $row['assignment_id'] : null,
		];
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
}
