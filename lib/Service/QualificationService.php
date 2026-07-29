<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Qualification catalog + employee attach + location requirements (Wave B).
 */
class QualificationService
{
	public function __construct(
		private readonly IDBConnection $db,
		private readonly ?CompanyService $companies = null,
	) {
	}

	/** @return list<array<string,mixed>> */
	public function listCatalog(bool $activeOnly = true, ?string $actorUserId = null): array
	{
		if (!$this->db->tableExists('dc_qualifications')) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('dc_qualifications');
		if ($activeOnly) {
			$qb->where($qb->expr()->eq('active', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)));
		}
		if ($actorUserId !== null && $this->companies !== null && \OCA\DutyCheck\Db\SchemaProbe::hasColumn($this->db, 'dc_qualifications', 'company_id')) {
			$this->companies->restrictQuery($qb, 'company_id', $actorUserId);
		}
		$qb->orderBy('name', 'ASC');
		return array_map(static fn (array $r): array => [
			'id' => (int) $r['id'],
			'name' => (string) $r['name'],
			'code' => $r['code'] !== null ? (string) $r['code'] : null,
			'active' => (int) $r['active'],
		], $qb->executeQuery()->fetchAll());
	}

	/** @param array<string,mixed> $payload @return array<string,mixed> */
	public function create(array $payload, ?string $actorUserId = null): array
	{
		$name = trim((string) ($payload['name'] ?? ''));
		if ($name === '' || mb_strlen($name) > 120) {
			throw new \InvalidArgumentException('QUALIFICATION_NAME_INVALID');
		}
		$code = trim((string) ($payload['code'] ?? ''));
		$code = $code === '' ? null : mb_substr($code, 0, 64);
		$now = (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s');
		$qb = $this->db->getQueryBuilder();
		$values = [
			'name' => $qb->createNamedParameter($name),
			'code' => $qb->createNamedParameter($code),
			'active' => $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT),
			'created_at' => $qb->createNamedParameter($now),
		];
		if ($actorUserId !== null && $this->companies !== null && \OCA\DutyCheck\Db\SchemaProbe::hasColumn($this->db, 'dc_qualifications', 'company_id')) {
			$values['company_id'] = $qb->createNamedParameter(
				$this->companies->writeCompanyIdFor($actorUserId),
				IQueryBuilder::PARAM_INT,
			);
		}
		try {
			$qb->insert('dc_qualifications')->values($values)->executeStatement();
		} catch (\Throwable $e) {
			throw new \InvalidArgumentException('QUALIFICATION_NAME_EXISTS', 0, $e);
		}
		$id = (int) $qb->getLastInsertId();
		return ['id' => $id, 'name' => $name, 'code' => $code, 'active' => 1];
	}

	/** @param array<string,mixed> $payload @return array<string,mixed> */
	public function update(int $id, array $payload, ?string $actorUserId = null): array
	{
		if ($actorUserId !== null && $this->companies !== null
			&& \OCA\DutyCheck\Db\SchemaProbe::hasColumn($this->db, 'dc_qualifications', 'company_id')) {
			$this->companies->assertRowCompany($actorUserId, 'dc_qualifications', $id);
		}
		$name = trim((string) ($payload['name'] ?? ''));
		if ($name === '' || mb_strlen($name) > 120) {
			throw new \InvalidArgumentException('QUALIFICATION_NAME_INVALID');
		}
		$code = trim((string) ($payload['code'] ?? ''));
		$code = $code === '' ? null : mb_substr($code, 0, 64);
		$qb = $this->db->getQueryBuilder();
		try {
			$affected = $qb->update('dc_qualifications')
				->set('name', $qb->createNamedParameter($name))
				->set('code', $qb->createNamedParameter($code))
				->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
				->executeStatement();
		} catch (\Throwable $e) {
			throw new \InvalidArgumentException('QUALIFICATION_NAME_EXISTS', 0, $e);
		}
		if ($affected !== 1) {
			throw new \InvalidArgumentException('QUALIFICATION_NOT_FOUND');
		}
		$row = $this->getById($id);
		return $row;
	}

	/** @return array<string,mixed> */
	public function deactivate(int $id, ?string $actorUserId = null): array
	{
		if ($actorUserId !== null && $this->companies !== null
			&& \OCA\DutyCheck\Db\SchemaProbe::hasColumn($this->db, 'dc_qualifications', 'company_id')) {
			$this->companies->assertRowCompany($actorUserId, 'dc_qualifications', $id);
		}
		$qb = $this->db->getQueryBuilder();
		$affected = $qb->update('dc_qualifications')
			->set('active', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->executeStatement();
		if ($affected !== 1) {
			throw new \InvalidArgumentException('QUALIFICATION_NOT_FOUND');
		}
		return $this->getById($id);
	}

	/** @return array<string,mixed> */
	public function getById(int $id): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('dc_qualifications')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$row = $qb->executeQuery()->fetch();
		if ($row === false) {
			throw new \InvalidArgumentException('QUALIFICATION_NOT_FOUND');
		}
		return [
			'id' => (int) $row['id'],
			'name' => (string) $row['name'],
			'code' => $row['code'] !== null ? (string) $row['code'] : null,
			'active' => (int) $row['active'],
		];
	}

	public function attachToEmployee(int $employeeId, int $qualificationId, ?string $expiresOn, ?string $actorUserId = null): void
	{
		if ($actorUserId !== null && $this->companies !== null) {
			$this->companies->assertRowCompany($actorUserId, 'dc_employees', $employeeId);
			if (\OCA\DutyCheck\Db\SchemaProbe::hasColumn($this->db, 'dc_qualifications', 'company_id')) {
				$this->companies->assertRowCompany($actorUserId, 'dc_qualifications', $qualificationId);
			}
		}
		if ($expiresOn !== null && $expiresOn !== '') {
			if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiresOn) !== 1) {
				throw new \InvalidArgumentException('INVALID_EXPIRY_DATE');
			}
		} else {
			$expiresOn = null;
		}
		$now = (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s');
		$existing = $this->db->getQueryBuilder();
		$existing->select('id')->from('dc_emp_quals')
			->where($existing->expr()->eq('employee_id', $existing->createNamedParameter($employeeId, IQueryBuilder::PARAM_INT)))
			->andWhere($existing->expr()->eq('qualification_id', $existing->createNamedParameter($qualificationId, IQueryBuilder::PARAM_INT)));
		$found = $existing->executeQuery()->fetch();
		if ($found !== false) {
			$upd = $this->db->getQueryBuilder();
			$upd->update('dc_emp_quals')
				->set('expires_on', $upd->createNamedParameter($expiresOn))
				->where($upd->expr()->eq('id', $upd->createNamedParameter((int) $found['id'], IQueryBuilder::PARAM_INT)))
				->executeStatement();
			return;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->insert('dc_emp_quals')->values([
			'employee_id' => $qb->createNamedParameter($employeeId, IQueryBuilder::PARAM_INT),
			'qualification_id' => $qb->createNamedParameter($qualificationId, IQueryBuilder::PARAM_INT),
			'expires_on' => $qb->createNamedParameter($expiresOn),
			'created_at' => $qb->createNamedParameter($now),
		])->executeStatement();
	}

	public function detachFromEmployee(int $employeeId, int $qualificationId, ?string $actorUserId = null): void
	{
		if ($actorUserId !== null && $this->companies !== null) {
			$this->companies->assertRowCompany($actorUserId, 'dc_employees', $employeeId);
			if (\OCA\DutyCheck\Db\SchemaProbe::hasColumn($this->db, 'dc_qualifications', 'company_id')) {
				$this->companies->assertRowCompany($actorUserId, 'dc_qualifications', $qualificationId);
			}
		}
		$qb = $this->db->getQueryBuilder();
		$affected = $qb->delete('dc_emp_quals')
			->where($qb->expr()->eq('employee_id', $qb->createNamedParameter($employeeId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('qualification_id', $qb->createNamedParameter($qualificationId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
		if ($affected !== 1) {
			throw new \InvalidArgumentException('EMPLOYEE_QUALIFICATION_NOT_FOUND');
		}
	}

	public function requireForLocation(int $locationId, int $qualificationId, ?string $actorUserId = null): void
	{
		if ($actorUserId !== null && $this->companies !== null) {
			$this->companies->assertRowCompany($actorUserId, 'dc_locations', $locationId);
			if (\OCA\DutyCheck\Db\SchemaProbe::hasColumn($this->db, 'dc_qualifications', 'company_id')) {
				$this->companies->assertRowCompany($actorUserId, 'dc_qualifications', $qualificationId);
			}
		}
		$qb = $this->db->getQueryBuilder();
		$qb->insert('dc_loc_quals')->values([
			'location_id' => $qb->createNamedParameter($locationId, IQueryBuilder::PARAM_INT),
			'qualification_id' => $qb->createNamedParameter($qualificationId, IQueryBuilder::PARAM_INT),
			'required' => $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT),
		])->executeStatement();
	}

	/**
	 * Hard/soft conflicts for missing or expired quals on a location.
	 *
	 * @return list<array<string,mixed>>
	 */
	public function conflictsForAssignment(int $employeeId, int $locationId, string $dutyDate): array
	{
		if (!$this->db->tableExists('dc_loc_quals')) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('q.id', 'q.name', 'lq.required')
			->from('dc_loc_quals', 'lq')
			->innerJoin('lq', 'dc_qualifications', 'q', 'lq.qualification_id = q.id')
			->where($qb->expr()->eq('lq.location_id', $qb->createNamedParameter($locationId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('q.active', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)));
		$required = $qb->executeQuery()->fetchAll();
		if ($required === []) {
			return [];
		}

		$held = $this->db->getQueryBuilder();
		$held->select('qualification_id', 'expires_on')
			->from('dc_emp_quals')
			->where($held->expr()->eq('employee_id', $held->createNamedParameter($employeeId, IQueryBuilder::PARAM_INT)));
		$heldRows = $held->executeQuery()->fetchAll();
		$byQual = [];
		foreach ($heldRows as $row) {
			$byQual[(int) $row['qualification_id']] = $row['expires_on'] !== null ? (string) $row['expires_on'] : null;
		}

		$out = [];
		foreach ($required as $req) {
			$qid = (int) $req['id'];
			if (!array_key_exists($qid, $byQual)) {
				$out[] = [
					'type' => 'qualification_missing',
					'severity' => 'hard',
					'message' => 'Employee is missing a required qualification for this location',
					'employeeId' => $employeeId,
					'payload' => ['qualificationId' => $qid, 'qualificationName' => (string) $req['name']],
				];
				continue;
			}
			$expires = $byQual[$qid];
			if ($expires !== null && $expires < $dutyDate) {
				$out[] = [
					'type' => 'qualification_expired',
					'severity' => 'soft',
					'message' => 'Employee qualification is expired for this location',
					'employeeId' => $employeeId,
					'payload' => ['qualificationId' => $qid, 'expiresOn' => $expires],
				];
			}
		}
		return $out;
	}
}
