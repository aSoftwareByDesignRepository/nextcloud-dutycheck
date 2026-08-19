<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Service;

use OCA\DutyCheck\Db\SchemaProbe;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Wave C1 — multi-company foundation.
 *
 * Legacy (one Default company): companyIdsForUser() returns [] → unrestricted.
 * Multi-tenant: lists/mutations are scoped to membership; creates stamp company_id.
 * Multi-company with no membership rows is deny-all (empty list + restrictQuery
 * matches no company_id). Never fall back to Default company (id=1).
 */
class CompanyService
{
	public const DEFAULT_COMPANY_ID = 1;
	public const DEFAULT_NAME = 'Default';

	/** Impossible company_id used to fail-closed IN/eq filters (ids start at 1). */
	public const DENY_ALL_COMPANY_ID = 0;

	private ?bool $schemaReadyCache = null;

	private ?bool $secondarySchemaReadyCache = null;

	private ?bool $multiCompanyActiveCache = null;

	/** @var array<string, list<int>> */
	private array $companyIdsByUser = [];

	public function __construct(
		private readonly IDBConnection $db,
	) {
	}

	public function schemaReady(): bool
	{
		if ($this->schemaReadyCache !== null) {
			return $this->schemaReadyCache;
		}
		$this->schemaReadyCache = SchemaProbe::tableExists($this->db, 'dc_companies')
			&& SchemaProbe::hasColumn($this->db, 'dc_employees', 'company_id')
			&& SchemaProbe::hasColumn($this->db, 'dc_locations', 'company_id')
			&& SchemaProbe::hasColumn($this->db, 'dc_periods', 'company_id');
		return $this->schemaReadyCache;
	}

	/** Secondary catalogs (templates/quals/marketplace/absences) after Version1013. */
	public function secondarySchemaReady(): bool
	{
		if ($this->secondarySchemaReadyCache !== null) {
			return $this->secondarySchemaReadyCache;
		}
		$this->secondarySchemaReadyCache = $this->schemaReady()
			&& SchemaProbe::hasColumn($this->db, 'dc_shift_templates', 'company_id')
			&& SchemaProbe::hasColumn($this->db, 'dc_qualifications', 'company_id')
			&& SchemaProbe::hasColumn($this->db, 'dc_open_shifts', 'company_id')
			&& SchemaProbe::hasColumn($this->db, 'dc_swap_requests', 'company_id')
			&& SchemaProbe::hasColumn($this->db, 'dc_absences', 'company_id');
		return $this->secondarySchemaReadyCache;
	}

	public function ensureDefaultCompany(): int
	{
		if (!SchemaProbe::tableExists($this->db, 'dc_companies')) {
			return self::DEFAULT_COMPANY_ID;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from('dc_companies')
			->where($qb->expr()->eq('id', $qb->createNamedParameter(self::DEFAULT_COMPANY_ID, IQueryBuilder::PARAM_INT)));
		$row = $qb->executeQuery()->fetch();
		if ($row !== false) {
			return self::DEFAULT_COMPANY_ID;
		}
		$now = (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s');
		$ins = $this->db->getQueryBuilder();
		try {
			$ins->insert('dc_companies')->values([
				'id' => $ins->createNamedParameter(self::DEFAULT_COMPANY_ID, IQueryBuilder::PARAM_INT),
				'name' => $ins->createNamedParameter(self::DEFAULT_NAME),
				'active' => $ins->createNamedParameter(1, IQueryBuilder::PARAM_INT),
				'created_at' => $ins->createNamedParameter($now),
			])->executeStatement();
		} catch (\Throwable) {
			// Concurrent seed — ignore unique violations.
		}
		return self::DEFAULT_COMPANY_ID;
	}

	/**
	 * @return list<array{id:int,name:string,active:bool}>
	 */
	public function listCompanies(): array
	{
		$this->ensureDefaultCompany();
		if (!SchemaProbe::tableExists($this->db, 'dc_companies')) {
			return [['id' => self::DEFAULT_COMPANY_ID, 'name' => self::DEFAULT_NAME, 'active' => true]];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('dc_companies')->orderBy('id', 'ASC');
		$rows = $qb->executeQuery()->fetchAll();
		return array_map(static fn (array $r): array => [
			'id' => (int) $r['id'],
			'name' => (string) $r['name'],
			'active' => (int) ($r['active'] ?? 1) === 1,
		], $rows);
	}

	public function isMultiCompanyActive(): bool
	{
		if ($this->multiCompanyActiveCache !== null) {
			return $this->multiCompanyActiveCache;
		}
		if (!$this->schemaReady()) {
			return $this->multiCompanyActiveCache = false;
		}
		$this->ensureDefaultCompany();
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->createFunction('COUNT(*)'))
			->from('dc_companies')
			->where($qb->expr()->eq('active', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)));
		return $this->multiCompanyActiveCache = (int) $qb->executeQuery()->fetchOne() > 1;
	}

	/**
	 * @return list<int> empty = unrestricted legacy when multi-company is off;
	 *                   empty = deny-all when multi-company is on
	 */
	public function companyIdsForUser(string $userId): array
	{
		if (!$this->isMultiCompanyActive()) {
			return [];
		}
		if (array_key_exists($userId, $this->companyIdsByUser)) {
			return $this->companyIdsByUser[$userId];
		}
		if (!SchemaProbe::tableExists($this->db, 'dc_company_members')) {
			return $this->companyIdsByUser[$userId] = [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('company_id')->from('dc_company_members')
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->orderBy('company_id', 'ASC');
		$rows = $qb->executeQuery()->fetchAll();
		$ids = array_values(array_unique(array_map(static fn (array $r): int => (int) $r['company_id'], $rows)));
		return $this->companyIdsByUser[$userId] = $ids;
	}

	/**
	 * Legacy single-company (or schema-not-ready) is always allowed.
	 * Multi-company requires at least one membership row.
	 */
	public function hasCompanyMembership(string $userId): bool
	{
		if (!$this->isMultiCompanyActive()) {
			return true;
		}
		return $this->companyIdsForUser($userId) !== [];
	}

	/** Company id stamped on new employees/locations/periods. */
	public function writeCompanyIdFor(string $userId): int
	{
		if (!$this->isMultiCompanyActive()) {
			return self::DEFAULT_COMPANY_ID;
		}
		$allowed = $this->companyIdsForUser($userId);
		if ($allowed === []) {
			throw new \InvalidArgumentException('COMPANY_MEMBERSHIP_REQUIRED');
		}
		return $allowed[0];
	}

	public function assertCanAccessCompany(string $userId, int $companyId): void
	{
		if (!$this->isMultiCompanyActive()) {
			return;
		}
		$allowed = $this->companyIdsForUser($userId);
		if ($allowed === [] || !in_array($companyId, $allowed, true)) {
			throw new \InvalidArgumentException('FORBIDDEN');
		}
	}

	/**
	 * Restrict a query to the caller's companies when multi-tenant is active.
	 * $column is e.g. 'company_id' or 'p.company_id'.
	 *
	 * Empty membership is deny-all (never match), not unrestricted.
	 */
	public function restrictQuery(IQueryBuilder $qb, string $column, string $userId): void
	{
		if (!$this->isMultiCompanyActive()) {
			return;
		}
		$allowed = $this->companyIdsForUser($userId);
		if ($allowed === []) {
			$qb->andWhere($qb->expr()->eq(
				$column,
				$qb->createNamedParameter(self::DENY_ALL_COMPANY_ID, IQueryBuilder::PARAM_INT),
			));
			return;
		}
		$qb->andWhere($qb->expr()->in(
			$column,
			$qb->createNamedParameter($allowed, IQueryBuilder::PARAM_INT_ARRAY),
		));
	}

	public function assertRowCompany(string $userId, string $table, int $rowId): void
	{
		if (!$this->isMultiCompanyActive() || !$this->schemaReady()) {
			return;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('company_id')->from($table)
			->where($qb->expr()->eq('id', $qb->createNamedParameter($rowId, IQueryBuilder::PARAM_INT)));
		$row = $qb->executeQuery()->fetch();
		if ($row === false) {
			throw new \InvalidArgumentException('NOT_FOUND');
		}
		$this->assertCanAccessCompany($userId, (int) $row['company_id']);
	}

	/**
	 * @return array{id:int,name:string,active:bool}
	 */
	public function createCompany(string $name, string $actor): array
	{
		if (!$this->schemaReady()) {
			throw new \InvalidArgumentException('SCHEMA_NOT_READY');
		}
		$this->ensureDefaultCompany();
		$name = trim($name);
		if ($name === '' || mb_strlen($name) > 120) {
			throw new \InvalidArgumentException('COMPANY_NAME_INVALID');
		}
		$now = (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s');
		$qb = $this->db->getQueryBuilder();
		try {
			$qb->insert('dc_companies')->values([
				'name' => $qb->createNamedParameter($name),
				'active' => $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT),
				'created_at' => $qb->createNamedParameter($now),
			])->executeStatement();
		} catch (\Throwable) {
			throw new \InvalidArgumentException('COMPANY_NAME_TAKEN');
		}
		$id = (int) $qb->getLastInsertId();
		$this->forgetCompanyAccessCaches();
		$this->addMember($id, $actor, 'admin');
		// Keep actor on Default as well so they can still see legacy rows.
		$this->addMember(self::DEFAULT_COMPANY_ID, $actor, 'admin');
		return ['id' => $id, 'name' => $name, 'active' => true];
	}

	/**
	 * @return list<array{companyId:int,userId:string,role:string}>
	 */
	public function listMembers(int $companyId): array
	{
		if (!SchemaProbe::tableExists($this->db, 'dc_company_members')) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('company_id', 'user_id', 'role')->from('dc_company_members')
			->where($qb->expr()->eq('company_id', $qb->createNamedParameter($companyId, IQueryBuilder::PARAM_INT)))
			->orderBy('user_id', 'ASC');
		return array_map(static fn (array $r): array => [
			'companyId' => (int) $r['company_id'],
			'userId' => (string) $r['user_id'],
			'role' => (string) $r['role'],
		], $qb->executeQuery()->fetchAll());
	}

	public function removeMember(int $companyId, string $userId): void
	{
		if (!SchemaProbe::tableExists($this->db, 'dc_company_members') || $companyId <= 0 || trim($userId) === '') {
			return;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->delete('dc_company_members')
			->where($qb->expr()->eq('company_id', $qb->createNamedParameter($companyId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->executeStatement();
		unset($this->companyIdsByUser[$userId]);
	}

	public function addMember(int $companyId, string $userId, string $role = 'member'): void
	{
		if (!SchemaProbe::tableExists($this->db, 'dc_company_members') || $companyId <= 0 || trim($userId) === '') {
			return;
		}
		$role = in_array($role, ['admin', 'member'], true) ? $role : 'member';
		$qb = $this->db->getQueryBuilder();
		try {
			$qb->insert('dc_company_members')->values([
				'company_id' => $qb->createNamedParameter($companyId, IQueryBuilder::PARAM_INT),
				'user_id' => $qb->createNamedParameter($userId),
				'role' => $qb->createNamedParameter($role),
			])->executeStatement();
		} catch (\Throwable) {
			// unique — already member
		}
		unset($this->companyIdsByUser[$userId]);
	}

	private function forgetCompanyAccessCaches(): void
	{
		$this->multiCompanyActiveCache = null;
		$this->companyIdsByUser = [];
	}
}
