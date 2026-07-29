<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Service;

use OCA\DutyCheck\Db\SchemaProbe;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Named shift templates (global or per-location), company-scoped when multi-tenant.
 */
class ShiftTemplateService
{
	public function __construct(
		private readonly IDBConnection $db,
		private readonly ?CompanyService $companies = null,
	) {
	}

	/**
	 * @return list<array<string,mixed>>
	 */
	public function list(?int $locationId = null, bool $activeOnly = true, ?string $actorUserId = null): array
	{
		if (!$this->db->tableExists('dc_shift_templates')) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('dc_shift_templates');
		if ($activeOnly) {
			$qb->where($qb->expr()->eq('active', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)));
		}
		if ($locationId !== null) {
			$qb->andWhere($qb->expr()->orX(
				$qb->expr()->eq('location_id', $qb->createNamedParameter($locationId, IQueryBuilder::PARAM_INT)),
				$qb->expr()->isNull('location_id'),
			));
		}
		if ($actorUserId !== null && $this->companies !== null && SchemaProbe::hasColumn($this->db, 'dc_shift_templates', 'company_id')) {
			$this->companies->restrictQuery($qb, 'company_id', $actorUserId);
		}
		$qb->orderBy('name', 'ASC');
		$rows = $qb->executeQuery()->fetchAll();
		return array_map([$this, 'normalize'], $rows);
	}

	/**
	 * @param array<string,mixed> $payload
	 * @return array<string,mixed>
	 */
	public function create(array $payload, ?string $actorUserId = null): array
	{
		$name = trim((string) ($payload['name'] ?? ''));
		if ($name === '' || mb_strlen($name) > 120) {
			throw new \InvalidArgumentException('TEMPLATE_NAME_INVALID');
		}
		$start = $this->normalizeTime((string) ($payload['startTime'] ?? ''));
		$end = $this->normalizeTime((string) ($payload['endTime'] ?? ''));
		if ($start === $end) {
			throw new \InvalidArgumentException('EQUAL_DUTY_TIMES');
		}
		$break = max(0, (int) ($payload['breakMinutes'] ?? 0));
		$minHeadcount = max(0, (int) ($payload['minHeadcount'] ?? 0));
		$locationId = isset($payload['locationId']) && $payload['locationId'] !== '' && $payload['locationId'] !== null
			? (int) $payload['locationId']
			: null;
		if ($locationId !== null && $locationId <= 0) {
			$locationId = null;
		}
		$companyId = $this->resolveWriteCompanyId($actorUserId);
		$this->assertLocationAllowedForCompany($locationId, $companyId);
		$this->assertNameUnique($name, $locationId, null, $companyId);

		$now = (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s');
		$qb = $this->db->getQueryBuilder();
		$values = [
			'location_id' => $qb->createNamedParameter($locationId, IQueryBuilder::PARAM_INT),
			'name' => $qb->createNamedParameter($name),
			'start_time' => $qb->createNamedParameter($start),
			'end_time' => $qb->createNamedParameter($end),
			'break_minutes' => $qb->createNamedParameter($break, IQueryBuilder::PARAM_INT),
			'active' => $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT),
			'created_at' => $qb->createNamedParameter($now),
		];
		if (SchemaProbe::hasColumn($this->db, 'dc_shift_templates', 'min_headcount')) {
			$values['min_headcount'] = $qb->createNamedParameter($minHeadcount, IQueryBuilder::PARAM_INT);
		}
		if ($companyId !== null) {
			$values['company_id'] = $qb->createNamedParameter($companyId, IQueryBuilder::PARAM_INT);
		}
		$qb->insert('dc_shift_templates')->values($values)->executeStatement();

		return $this->getById((int) $qb->getLastInsertId());
	}

	/**
	 * @param array<string,mixed> $payload
	 * @return array<string,mixed>
	 */
	public function update(int $id, array $payload, ?string $actorUserId = null): array
	{
		if ($actorUserId !== null && $this->companies !== null) {
			$this->companies->assertRowCompany($actorUserId, 'dc_shift_templates', $id);
		}
		$existing = $this->getById($id);
		$name = array_key_exists('name', $payload) ? trim((string) $payload['name']) : (string) $existing['name'];
		if ($name === '' || mb_strlen($name) > 120) {
			throw new \InvalidArgumentException('TEMPLATE_NAME_INVALID');
		}
		$start = array_key_exists('startTime', $payload) ? $this->normalizeTime((string) $payload['startTime']) : (string) $existing['startTime'];
		$end = array_key_exists('endTime', $payload) ? $this->normalizeTime((string) $payload['endTime']) : (string) $existing['endTime'];
		if ($start === $end) {
			throw new \InvalidArgumentException('EQUAL_DUTY_TIMES');
		}
		$break = array_key_exists('breakMinutes', $payload) ? max(0, (int) $payload['breakMinutes']) : (int) $existing['breakMinutes'];
		$minHeadcount = array_key_exists('minHeadcount', $payload)
			? max(0, (int) $payload['minHeadcount'])
			: (int) ($existing['minHeadcount'] ?? 0);
		$locationId = array_key_exists('locationId', $payload)
			? (($payload['locationId'] === null || $payload['locationId'] === '') ? null : (int) $payload['locationId'])
			: $existing['locationId'];
		$active = array_key_exists('active', $payload) ? (((int) $payload['active']) ? 1 : 0) : ((int) $existing['active']);
		$companyId = $existing['companyId'] ?? $this->resolveWriteCompanyId($actorUserId);
		$this->assertLocationAllowedForCompany($locationId, is_int($companyId) ? $companyId : null);
		$this->assertNameUnique($name, $locationId, $id, is_int($companyId) ? $companyId : null);

		$now = (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s');
		$qb = $this->db->getQueryBuilder();
		$qb->update('dc_shift_templates')
			->set('name', $qb->createNamedParameter($name))
			->set('start_time', $qb->createNamedParameter($start))
			->set('end_time', $qb->createNamedParameter($end))
			->set('break_minutes', $qb->createNamedParameter($break, IQueryBuilder::PARAM_INT))
			->set('location_id', $qb->createNamedParameter($locationId, IQueryBuilder::PARAM_INT))
			->set('active', $qb->createNamedParameter($active, IQueryBuilder::PARAM_INT))
			->set('updated_at', $qb->createNamedParameter($now));
		if (SchemaProbe::hasColumn($this->db, 'dc_shift_templates', 'min_headcount')) {
			$qb->set('min_headcount', $qb->createNamedParameter($minHeadcount, IQueryBuilder::PARAM_INT));
		}
		$qb->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->executeStatement();

		return $this->getById($id);
	}

	public function delete(int $id, ?string $actorUserId = null): void
	{
		if ($actorUserId !== null && $this->companies !== null) {
			$this->companies->assertRowCompany($actorUserId, 'dc_shift_templates', $id);
		}
		$this->getById($id);
		$qb = $this->db->getQueryBuilder();
		$qb->delete('dc_shift_templates')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	/**
	 * @return array<string,mixed>
	 */
	public function getById(int $id): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('dc_shift_templates')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$row = $qb->executeQuery()->fetch();
		if ($row === false) {
			throw new \InvalidArgumentException('TEMPLATE_NOT_FOUND');
		}
		return $this->normalize($row);
	}

	/**
	 * @param array<string,mixed> $row
	 * @return array<string,mixed>
	 */
	private function normalize(array $row): array
	{
		$out = [
			'id' => (int) $row['id'],
			'locationId' => $row['location_id'] !== null ? (int) $row['location_id'] : null,
			'name' => (string) $row['name'],
			'startTime' => (string) $row['start_time'],
			'endTime' => (string) $row['end_time'],
			'breakMinutes' => (int) $row['break_minutes'],
			'minHeadcount' => (int) ($row['min_headcount'] ?? 0),
			'active' => (int) $row['active'],
		];
		if (array_key_exists('company_id', $row)) {
			$out['companyId'] = (int) $row['company_id'];
		}
		return $out;
	}

	private function normalizeTime(string $value): string
	{
		$trim = trim($value);
		if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $trim) !== 1) {
			throw new \InvalidArgumentException('INVALID_DUTY_TIME');
		}
		return $trim;
	}

	private function resolveWriteCompanyId(?string $actorUserId): ?int
	{
		if ($actorUserId === null || $this->companies === null || !SchemaProbe::hasColumn($this->db, 'dc_shift_templates', 'company_id')) {
			return null;
		}
		return $this->companies->writeCompanyIdFor($actorUserId);
	}

	/**
	 * When a location is set, it must exist/be active and match the template company (multi-tenant).
	 */
	private function assertLocationAllowedForCompany(?int $locationId, ?int $companyId): void
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
		if (
			$companyId !== null
			&& $this->companies !== null
			&& $this->companies->isMultiCompanyActive()
			&& SchemaProbe::hasColumn($this->db, 'dc_locations', 'company_id')
			&& (int) ($row['company_id'] ?? 0) !== $companyId
		) {
			throw new \InvalidArgumentException('COMPANY_MISMATCH');
		}
	}

	private function assertNameUnique(string $name, ?int $locationId, ?int $excludeId, ?int $companyId): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from('dc_shift_templates')
			->where($qb->expr()->eq('name', $qb->createNamedParameter($name)));
		if ($locationId === null) {
			$qb->andWhere($qb->expr()->isNull('location_id'));
		} else {
			$qb->andWhere($qb->expr()->eq('location_id', $qb->createNamedParameter($locationId, IQueryBuilder::PARAM_INT)));
		}
		if ($companyId !== null && SchemaProbe::hasColumn($this->db, 'dc_shift_templates', 'company_id')) {
			$qb->andWhere($qb->expr()->eq('company_id', $qb->createNamedParameter($companyId, IQueryBuilder::PARAM_INT)));
		}
		if ($excludeId !== null) {
			$qb->andWhere($qb->expr()->neq('id', $qb->createNamedParameter($excludeId, IQueryBuilder::PARAM_INT)));
		}
		if ($qb->executeQuery()->fetch() !== false) {
			throw new \InvalidArgumentException('TEMPLATE_NAME_EXISTS');
		}
	}
}
