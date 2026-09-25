<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Service;

use OCA\DutyCheck\Db\SchemaProbe;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Soft shift-band preferences (Früh/Spät) — structured only, no free-text reasons.
 */
final class ShiftPreferenceService
{
	private const BANDS = ['early', 'mid', 'late', 'any'];
	private const CONFLICT_BANDS = ['early', 'late'];

	public function __construct(
		private readonly IDBConnection $db,
		private readonly CompanyService $companies,
		private readonly AccessControlService $access,
		private readonly SelfServiceSettingsService $settings,
		private readonly ?PeriodLockService $locks = null,
	) {
	}

	/**
	 * @return list<array<string,mixed>>
	 */
	public function listForEmployee(int $employeeId, string $actor): array
	{
		$this->assertSchemaReady();
		$this->assertCanReadEmployee($employeeId, $actor);
		$companyId = $this->employeeCompanyId($employeeId);
		$this->assertPreferencesEnabled($companyId);

		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('dc_shift_preferences')
			->where($qb->expr()->eq('employee_id', $qb->createNamedParameter($employeeId, IQueryBuilder::PARAM_INT)))
			->orderBy('priority', 'ASC')
			->addOrderBy('id', 'ASC');
		return array_map([$this, 'normalize'], $qb->executeQuery()->fetchAll());
	}

	/**
	 * Planner chips: preferences for a bounded set of employees in one company.
	 *
	 * @param list<int> $employeeIds
	 * @return list<array<string,mixed>>
	 */
	public function listForCompanyEmployees(int $companyId, array $employeeIds, string $actor): array
	{
		$this->assertSchemaReady();
		if (!$this->access->isPlannerOrAdmin($actor)) {
			throw new \InvalidArgumentException('FORBIDDEN');
		}
		$this->companies->assertCanAccessCompany($actor, $companyId);
		$this->assertPreferencesEnabled($companyId);

		$ids = [];
		foreach ($employeeIds as $id) {
			$id = (int) $id;
			if ($id > 0) {
				$ids[$id] = $id;
			}
		}
		$ids = array_values($ids);
		if ($ids === []) {
			return [];
		}
		// Hard cap — never dump full company preference set via oversized id lists.
		if (count($ids) > 200) {
			$ids = array_slice($ids, 0, 200);
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('dc_shift_preferences')
			->where($qb->expr()->eq('company_id', $qb->createNamedParameter($companyId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->in('employee_id', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)))
			->orderBy('employee_id', 'ASC')
			->addOrderBy('priority', 'ASC')
			->addOrderBy('id', 'ASC');
		return array_map([$this, 'normalize'], $qb->executeQuery()->fetchAll());
	}

	/**
	 * Create or update (when payload.id set). No free-text fields accepted.
	 *
	 * @param array<string,mixed> $payload
	 * @return array<string,mixed>
	 */
	public function save(int $employeeId, array $payload, string $actor): array
	{
		$this->assertSchemaReady();
		$this->assertCanWriteEmployee($employeeId, $actor);
		$companyId = $this->employeeCompanyId($employeeId);
		$this->assertPreferencesEnabled($companyId);

		$existingId = isset($payload['id']) ? (int) $payload['id'] : 0;
		$band = strtolower(trim((string) ($payload['band'] ?? '')));
		if (!in_array($band, self::BANDS, true)) {
			throw new \InvalidArgumentException('PREFERENCE_BAND_INVALID');
		}
		$weekdayMask = (int) ($payload['weekdayMask'] ?? $payload['weekday_mask'] ?? 127);
		if ($weekdayMask < 1 || $weekdayMask > 127) {
			throw new \InvalidArgumentException('PREFERENCE_MASK_INVALID');
		}
		$locationId = $this->optionalLocationId($payload['locationId'] ?? $payload['location_id'] ?? null);
		$this->assertLocationAllowedForCompany($locationId, $companyId);

		$priority = (int) ($payload['priority'] ?? 0);
		if ($priority < 0 || $priority > 100) {
			$priority = 0;
		}
		$validFrom = $this->optionalDate($payload['validFrom'] ?? $payload['valid_from'] ?? null);
		$validTo = $this->optionalDate($payload['validTo'] ?? $payload['valid_to'] ?? null);
		if ($validFrom !== null && $validTo !== null && $validTo < $validFrom) {
			throw new \InvalidArgumentException('PREFERENCE_DATE_RANGE_INVALID');
		}

		$holder = $actor . ':' . bin2hex(random_bytes(4));
		$locked = $this->locks !== null
			&& $this->locks->acquire($employeeId, PeriodLockService::KIND_PREF, $holder, 15);
		if ($this->locks !== null && !$locked) {
			throw new \InvalidArgumentException('PREFERENCE_CONFLICT');
		}

		try {
			$this->assertNoEarlyLateConflict($employeeId, $band, $weekdayMask, $locationId, $existingId > 0 ? $existingId : null);

			$now = (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s');
			if ($existingId > 0) {
				$row = $this->getRawById($existingId);
				// Existence-blind: a preference row that is not the actor's is
				// reported exactly like a missing one (no id enumeration).
				if ((int) $row['employee_id'] !== $employeeId) {
					throw new \InvalidArgumentException('PREFERENCE_NOT_FOUND');
				}
				if ((int) $row['company_id'] !== $companyId) {
					throw new \InvalidArgumentException('PREFERENCE_NOT_FOUND');
				}
				$qb = $this->db->getQueryBuilder();
				$qb->update('dc_shift_preferences')
					->set('location_id', $qb->createNamedParameter($locationId, IQueryBuilder::PARAM_INT))
					->set('band', $qb->createNamedParameter($band))
					->set('weekday_mask', $qb->createNamedParameter($weekdayMask, IQueryBuilder::PARAM_INT))
					->set('priority', $qb->createNamedParameter($priority, IQueryBuilder::PARAM_INT))
					->set('valid_from', $qb->createNamedParameter($validFrom))
					->set('valid_to', $qb->createNamedParameter($validTo))
					->set('updated_at', $qb->createNamedParameter($now))
					->where($qb->expr()->eq('id', $qb->createNamedParameter($existingId, IQueryBuilder::PARAM_INT)))
					->executeStatement();
				return $this->getById($existingId);
			}

			$qb = $this->db->getQueryBuilder();
			$qb->insert('dc_shift_preferences')->values([
				'company_id' => $qb->createNamedParameter($companyId, IQueryBuilder::PARAM_INT),
				'employee_id' => $qb->createNamedParameter($employeeId, IQueryBuilder::PARAM_INT),
				'location_id' => $qb->createNamedParameter($locationId, IQueryBuilder::PARAM_INT),
				'band' => $qb->createNamedParameter($band),
				'weekday_mask' => $qb->createNamedParameter($weekdayMask, IQueryBuilder::PARAM_INT),
				'priority' => $qb->createNamedParameter($priority, IQueryBuilder::PARAM_INT),
				'valid_from' => $qb->createNamedParameter($validFrom),
				'valid_to' => $qb->createNamedParameter($validTo),
				'created_at' => $qb->createNamedParameter($now),
				'updated_at' => $qb->createNamedParameter($now),
			])->executeStatement();

			return $this->getById((int) $qb->getLastInsertId());
		} finally {
			if ($locked && $this->locks !== null) {
				$this->locks->release($employeeId, PeriodLockService::KIND_PREF, $holder);
			}
		}
	}

	public function delete(int $id, string $actor): void
	{
		$this->assertSchemaReady();
		$row = $this->getRawById($id);
		$employeeId = (int) $row['employee_id'];
		// The preference row is the resource — report foreign rows like missing ones.
		$this->assertCanWriteEmployee($employeeId, $actor, 'PREFERENCE_NOT_FOUND');
		$this->assertPreferencesEnabled((int) $row['company_id']);

		$qb = $this->db->getQueryBuilder();
		$qb->delete('dc_shift_preferences')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	/**
	 * Erasure hook for {@see \OCA\DutyCheck\Listener\UserDeletedListener}.
	 */
	public function purgeForUser(string $userId): void
	{
		if ($userId === '' || !SchemaProbe::tableExists($this->db, 'dc_shift_preferences')) {
			return;
		}
		$employeeIds = $this->employeeIdsForUser($userId);
		if ($employeeIds === []) {
			return;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->delete('dc_shift_preferences')
			->where($qb->expr()->in('employee_id', $qb->createNamedParameter($employeeIds, IQueryBuilder::PARAM_INT_ARRAY)))
			->executeStatement();
	}

	/**
	 * @return array<string,mixed>
	 */
	public function getById(int $id): array
	{
		return $this->normalize($this->getRawById($id));
	}

	/**
	 * @return array<string,mixed>
	 */
	private function getRawById(int $id): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('dc_shift_preferences')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$row = $qb->executeQuery()->fetch();
		if ($row === false) {
			throw new \InvalidArgumentException('PREFERENCE_NOT_FOUND');
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
			'band' => (string) $row['band'],
			'weekdayMask' => (int) $row['weekday_mask'],
			'priority' => (int) $row['priority'],
			'validFrom' => $row['valid_from'] !== null ? (string) $row['valid_from'] : null,
			'validTo' => $row['valid_to'] !== null ? (string) $row['valid_to'] : null,
			'createdAt' => (string) $row['created_at'],
			'updatedAt' => (string) $row['updated_at'],
		];
	}

	private function assertNoEarlyLateConflict(
		int $employeeId,
		string $band,
		int $weekdayMask,
		?int $locationId,
		?int $excludeId,
	): void {
		if (!in_array($band, self::CONFLICT_BANDS, true)) {
			return;
		}
		$opposite = $band === 'early' ? 'late' : 'early';
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'weekday_mask', 'location_id')->from('dc_shift_preferences')
			->where($qb->expr()->eq('employee_id', $qb->createNamedParameter($employeeId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('band', $qb->createNamedParameter($opposite)));
		if ($excludeId !== null) {
			$qb->andWhere($qb->expr()->neq('id', $qb->createNamedParameter($excludeId, IQueryBuilder::PARAM_INT)));
		}
		foreach ($qb->executeQuery()->fetchAll() as $row) {
			$otherMask = (int) $row['weekday_mask'];
			if (($weekdayMask & $otherMask) === 0) {
				continue;
			}
			$otherLoc = $row['location_id'] !== null ? (int) $row['location_id'] : null;
			// Same location, or either applies to all locations → conflict.
			if ($locationId === null || $otherLoc === null || $locationId === $otherLoc) {
				throw new \InvalidArgumentException('PREFERENCE_CONFLICT');
			}
		}
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

	private function assertPreferencesEnabled(int $companyId): void
	{
		if (!$this->settings->isPreferencesEnabled($companyId)) {
			throw new \InvalidArgumentException('PREFERENCES_DISABLED');
		}
	}

	private function assertSchemaReady(): void
	{
		if (!SchemaProbe::tableExists($this->db, 'dc_shift_preferences')) {
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

	/**
	 * @return list<int>
	 */
	private function employeeIdsForUser(string $userId): array
	{
		if (!SchemaProbe::tableExists($this->db, 'dc_employees')) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from('dc_employees')
			->where($qb->expr()->eq('linked_user_id', $qb->createNamedParameter($userId)));
		$out = [];
		foreach ($qb->executeQuery()->fetchAll() as $row) {
			$out[] = (int) $row['id'];
		}
		return $out;
	}

	private function optionalLocationId(mixed $raw): ?int
	{
		if ($raw === null || $raw === '') {
			return null;
		}
		$id = (int) $raw;
		return $id > 0 ? $id : null;
	}

	private function optionalDate(mixed $raw): ?string
	{
		if ($raw === null || $raw === '') {
			return null;
		}
		$s = trim((string) $raw);
		if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) !== 1) {
			throw new \InvalidArgumentException('PREFERENCE_DATE_INVALID');
		}
		return $s;
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
