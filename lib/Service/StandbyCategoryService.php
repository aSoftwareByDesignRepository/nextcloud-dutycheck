<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Service;

use OCA\DutyCheck\Db\SchemaProbe;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Per-company standby categories (optional labels like "Bereitschaft Nacht").
 *
 * Security: every id-bearing read/write goes through a company check that
 * collapses "foreign row" and "missing row" into STANDBY_CATEGORY_NOT_FOUND,
 * so category ids cannot be probed across tenants.
 */
final class StandbyCategoryService
{
	public const MAX_LABEL_LENGTH = 120;
	public const MAX_RESPONSE_MINUTES = 10080; // one week

	public function __construct(
		private readonly IDBConnection $db,
		private readonly CompanyService $companies,
	) {
	}

	/**
	 * @return list<array{id:int,companyId:int,label:string,color:?string,defaultResponseTimeMinutes:?int,active:bool}>
	 */
	public function listForCompany(int $companyId, bool $includeInactive = false): array
	{
		if (!$this->schemaReady()) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'company_id', 'label', 'color', 'default_response_time_minutes', 'active')
			->from('dc_standby_categories')
			->where($qb->expr()->eq('company_id', $qb->createNamedParameter($companyId, IQueryBuilder::PARAM_INT)))
			->orderBy('label', 'ASC');
		if (!$includeInactive) {
			$qb->andWhere($qb->expr()->eq('active', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)));
		}
		return array_map(
			fn (array $row): array => $this->toApi($row),
			$qb->executeQuery()->fetchAll(),
		);
	}

	/**
	 * Resolve a category for an assignment write. Null in → null out.
	 * Any id that is missing, foreign, or inactive yields the same
	 * STANDBY_CATEGORY_NOT_FOUND so existence is not enumerable.
	 */
	public function resolveForAssignment(int $companyId, mixed $categoryId): ?array
	{
		if ($categoryId === null || $categoryId === '' || $categoryId === 0) {
			return null;
		}
		if (!is_int($categoryId) && !(is_string($categoryId) && preg_match('/^\d+$/', $categoryId) === 1)) {
			throw new \InvalidArgumentException('STANDBY_CATEGORY_NOT_FOUND');
		}
		$id = (int) $categoryId;
		if ($id < 1) {
			throw new \InvalidArgumentException('STANDBY_CATEGORY_NOT_FOUND');
		}
		if (!$this->schemaReady()) {
			throw new \InvalidArgumentException('SCHEMA_NOT_READY');
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'company_id', 'label', 'color', 'default_response_time_minutes', 'active')
			->from('dc_standby_categories')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$row = $qb->executeQuery()->fetch();
		if ($row === false || (int) $row['company_id'] !== $companyId || (int) $row['active'] !== 1) {
			throw new \InvalidArgumentException('STANDBY_CATEGORY_NOT_FOUND');
		}
		return $this->toApi($row);
	}

	/**
	 * Batch-load categories by id for serialization joins (same company only).
	 *
	 * @param list<int> $ids
	 * @return array<int, array{id:int,companyId:int,label:string,color:?string,defaultResponseTimeMinutes:?int,active:bool}>
	 */
	public function byIdsForCompany(int $companyId, array $ids): array
	{
		if ($ids === [] || !$this->schemaReady()) {
			return [];
		}
		$ids = array_values(array_unique(array_map('intval', $ids)));
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'company_id', 'label', 'color', 'default_response_time_minutes', 'active')
			->from('dc_standby_categories')
			->where($qb->expr()->eq('company_id', $qb->createNamedParameter($companyId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->in('id', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)));
		$out = [];
		foreach ($qb->executeQuery()->fetchAll() as $row) {
			$out[(int) $row['id']] = $this->toApi($row);
		}
		return $out;
	}

	/**
	 * @return array{id:int,companyId:int,label:string,color:?string,defaultResponseTimeMinutes:?int,active:bool}
	 */
	public function create(int $companyId, string $actor, mixed $label, mixed $color, mixed $defaultResponseTimeMinutes): array
	{
		if (!$this->schemaReady()) {
			throw new \InvalidArgumentException('SCHEMA_NOT_READY');
		}
		$this->companies->assertCanAccessCompany($actor, $companyId, 'COMPANY_NOT_FOUND');
		$label = $this->normalizeLabel($label);
		$color = $this->normalizeColor($color);
		$defaultMinutes = $this->normalizeResponseMinutes($defaultResponseTimeMinutes);

		$qb = $this->db->getQueryBuilder();
		$qb->insert('dc_standby_categories')->values([
			'company_id' => $qb->createNamedParameter($companyId, IQueryBuilder::PARAM_INT),
			'label' => $qb->createNamedParameter($label),
			'color' => $color === null ? $qb->createNamedParameter(null) : $qb->createNamedParameter($color),
			'default_response_time_minutes' => $defaultMinutes === null
				? $qb->createNamedParameter(null)
				: $qb->createNamedParameter($defaultMinutes, IQueryBuilder::PARAM_INT),
			'active' => $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT),
		])->executeStatement();

		return [
			'id' => (int) $qb->getLastInsertId(),
			'companyId' => $companyId,
			'label' => $label,
			'color' => $color,
			'defaultResponseTimeMinutes' => $defaultMinutes,
			'active' => true,
		];
	}

	/**
	 * @param array{label?:mixed,color?:mixed,defaultResponseTimeMinutes?:mixed,active?:mixed} $patch
	 * @return array{id:int,companyId:int,label:string,color:?string,defaultResponseTimeMinutes:?int,active:bool}
	 */
	public function update(int $companyId, string $actor, int $id, array $patch): array
	{
		if (!$this->schemaReady()) {
			throw new \InvalidArgumentException('SCHEMA_NOT_READY');
		}
		$this->companies->assertRowCompany($actor, 'dc_standby_categories', $id, 'STANDBY_CATEGORY_NOT_FOUND');
		$current = $this->fetchRow($id);
		if ($current === null || (int) $current['company_id'] !== $companyId) {
			throw new \InvalidArgumentException('STANDBY_CATEGORY_NOT_FOUND');
		}

		$label = array_key_exists('label', $patch)
			? $this->normalizeLabel($patch['label'])
			: (string) $current['label'];
		$color = array_key_exists('color', $patch)
			? $this->normalizeColor($patch['color'])
			: ($current['color'] !== null ? (string) $current['color'] : null);
		$defaultMinutes = array_key_exists('defaultResponseTimeMinutes', $patch)
			? $this->normalizeResponseMinutes($patch['defaultResponseTimeMinutes'])
			: ($current['default_response_time_minutes'] !== null ? (int) $current['default_response_time_minutes'] : null);
		$active = array_key_exists('active', $patch)
			? $this->normalizeActive($patch['active'])
			: ((int) $current['active'] === 1);

		$qb = $this->db->getQueryBuilder();
		$qb->update('dc_standby_categories')
			->set('label', $qb->createNamedParameter($label))
			->set('color', $color === null ? $qb->createNamedParameter(null) : $qb->createNamedParameter($color))
			->set('default_response_time_minutes', $defaultMinutes === null
				? $qb->createNamedParameter(null)
				: $qb->createNamedParameter($defaultMinutes, IQueryBuilder::PARAM_INT))
			->set('active', $qb->createNamedParameter($active ? 1 : 0, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('company_id', $qb->createNamedParameter($companyId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();

		return [
			'id' => $id,
			'companyId' => $companyId,
			'label' => $label,
			'color' => $color,
			'defaultResponseTimeMinutes' => $defaultMinutes,
			'active' => $active,
		];
	}

	/**
	 * Hard delete. Deactivation (active=false) is the safe path when the
	 * category is referenced by assignments; delete is refused while in use.
	 */
	public function delete(int $companyId, string $actor, int $id): void
	{
		if (!$this->schemaReady()) {
			throw new \InvalidArgumentException('SCHEMA_NOT_READY');
		}
		$this->companies->assertRowCompany($actor, 'dc_standby_categories', $id, 'STANDBY_CATEGORY_NOT_FOUND');
		$row = $this->fetchRow($id);
		if ($row === null || (int) $row['company_id'] !== $companyId) {
			throw new \InvalidArgumentException('STANDBY_CATEGORY_NOT_FOUND');
		}
		if ($this->inUseCount($id) > 0) {
			throw new \InvalidArgumentException('STANDBY_CATEGORY_IN_USE');
		}
		$qb = $this->db->getQueryBuilder();
		$qb->delete('dc_standby_categories')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('company_id', $qb->createNamedParameter($companyId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	private function inUseCount(int $id): int
	{
		if (!SchemaProbe::hasColumn($this->db, 'dc_assignments', 'standby_category_id')) {
			return 0;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id', 'n'))
			->from('dc_assignments')
			->where($qb->expr()->eq('standby_category_id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		return (int) $qb->executeQuery()->fetchOne();
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private function fetchRow(int $id): ?array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'company_id', 'label', 'color', 'default_response_time_minutes', 'active')
			->from('dc_standby_categories')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$row = $qb->executeQuery()->fetch();
		return $row === false ? null : $row;
	}

	private function normalizeLabel(mixed $label): string
	{
		if (!is_string($label)) {
			throw new \InvalidArgumentException('STANDBY_CATEGORY_INVALID');
		}
		$label = trim($label);
		if ($label === '' || mb_strlen($label) > self::MAX_LABEL_LENGTH) {
			throw new \InvalidArgumentException('STANDBY_CATEGORY_INVALID');
		}
		return $label;
	}

	private function normalizeColor(mixed $color): ?string
	{
		if ($color === null || $color === '') {
			return null;
		}
		if (!is_string($color)) {
			throw new \InvalidArgumentException('STANDBY_CATEGORY_INVALID');
		}
		$color = trim($color);
		if ($color !== '' && $color[0] !== '#') {
			$color = '#' . $color;
		}
		if (preg_match('/^#[0-9a-fA-F]{6}$/', $color) !== 1) {
			throw new \InvalidArgumentException('STANDBY_CATEGORY_INVALID');
		}
		return strtolower($color);
	}

	private function normalizeResponseMinutes(mixed $value): ?int
	{
		if ($value === null || $value === '') {
			return null;
		}
		if (is_int($value)) {
			$minutes = $value;
		} elseif (is_string($value) && preg_match('/^\d+$/', $value) === 1) {
			$minutes = (int) $value;
		} else {
			throw new \InvalidArgumentException('INVALID_RESPONSE_TIME');
		}
		if ($minutes < 0 || $minutes > self::MAX_RESPONSE_MINUTES) {
			throw new \InvalidArgumentException('INVALID_RESPONSE_TIME');
		}
		return $minutes;
	}

	private function normalizeActive(mixed $active): bool
	{
		if (is_bool($active)) {
			return $active;
		}
		if ($active === 1 || $active === '1' || $active === 'true') {
			return true;
		}
		if ($active === 0 || $active === '0' || $active === 'false') {
			return false;
		}
		throw new \InvalidArgumentException('INVALID_BOOLEAN');
	}

	private function schemaReady(): bool
	{
		return SchemaProbe::tableExists($this->db, 'dc_standby_categories');
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array{id:int,companyId:int,label:string,color:?string,defaultResponseTimeMinutes:?int,active:bool}
	 */
	private function toApi(array $row): array
	{
		return [
			'id' => (int) $row['id'],
			'companyId' => (int) $row['company_id'],
			'label' => (string) $row['label'],
			'color' => $row['color'] !== null ? (string) $row['color'] : null,
			'defaultResponseTimeMinutes' => $row['default_response_time_minutes'] !== null
				? (int) $row['default_response_time_minutes']
				: null,
			'active' => (int) $row['active'] === 1,
		];
	}
}
