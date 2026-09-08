<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Service;

use OCA\DutyCheck\Db\SchemaProbe;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Throwable;

/**
 * CRUD for N-week rotation patterns and per-employee assignments.
 */
final class RotationPatternService
{
	public const ANCHOR_ISO_WEEK_PARITY = RotationAnchorService::ANCHOR_ISO_WEEK_PARITY;
	public const ANCHOR_FIXED_DATE = RotationAnchorService::ANCHOR_FIXED_DATE;

	public function __construct(
		private readonly IDBConnection $db,
		private readonly CompanyService $companies,
		private readonly SelfServiceSettingsService $settings,
		private readonly RotationAnchorService $anchors,
		private readonly ?PeriodLockService $locks = null,
	) {
	}

	/**
	 * List patterns for a company (allowed when diagnosing even if feature flag off).
	 *
	 * @return list<array<string,mixed>>
	 */
	public function listPatterns(int $companyId, string $actor): array
	{
		$this->assertSchemaReady();
		$this->companies->assertCanAccessCompany($actor, $companyId);
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('dc_rotation_patterns')
			->where($qb->expr()->eq('company_id', $qb->createNamedParameter($companyId, IQueryBuilder::PARAM_INT)))
			->orderBy('name', 'ASC');
		$rows = $qb->executeQuery()->fetchAll();
		$out = [];
		foreach ($rows as $row) {
			$out[] = $this->normalizePattern($row, $this->loadWeekDays((int) $row['id']));
		}
		return $out;
	}

	/**
	 * @return array<string,mixed>
	 */
	public function getPattern(int $id, string $actor): array
	{
		$this->assertSchemaReady();
		$row = $this->patternRow($id);
		$this->companies->assertCanAccessCompany($actor, (int) $row['company_id']);
		return $this->normalizePattern($row, $this->loadWeekDays($id));
	}

	/**
	 * @param array<string,mixed> $payload
	 * @return array<string,mixed>
	 */
	public function createPattern(array $payload, string $actor): array
	{
		$this->assertSchemaReady();
		$companyId = $this->resolveCompanyId($payload, $actor);
		$this->assertRotationEnabled($companyId);

		$name = $this->normalizeName((string) ($payload['name'] ?? ''));
		$cycleWeeks = (int) ($payload['cycleWeeks'] ?? $payload['cycle_weeks'] ?? 2);
		$this->anchors->assertCycleWeeksAllowed($cycleWeeks, $this->settings->allowedCycleWeeks($companyId));

		$anchor = $this->normalizeAnchorFields($payload, null);
		$weekDays = $this->normalizeWeekDaysPayload(
			$payload['weekDays'] ?? $payload['week_days'] ?? null,
			$cycleWeeks,
		);
		$contractAvg = $this->optionalInt($payload['contractAvgMinutes'] ?? $payload['contract_avg_minutes'] ?? null);

		$this->assertNameUnique($companyId, $name, null);

		$now = $this->now();
		$this->db->beginTransaction();
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->insert('dc_rotation_patterns')->values([
				'company_id' => $qb->createNamedParameter($companyId, IQueryBuilder::PARAM_INT),
				'name' => $qb->createNamedParameter($name),
				'cycle_weeks' => $qb->createNamedParameter($cycleWeeks, IQueryBuilder::PARAM_INT),
				'anchor_type' => $qb->createNamedParameter($anchor['anchorType']),
				'anchor_iso_week_index' => $qb->createNamedParameter($anchor['anchorIsoWeekIndex'], IQueryBuilder::PARAM_INT),
				'anchor_date' => $qb->createNamedParameter($anchor['anchorDate']),
				'anchor_effective_from' => $qb->createNamedParameter($anchor['anchorEffectiveFrom']),
				'contract_avg_minutes' => $qb->createNamedParameter($contractAvg, IQueryBuilder::PARAM_INT),
				'is_active' => $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT),
				'created_by' => $qb->createNamedParameter($actor),
				'updated_by' => $qb->createNamedParameter($actor),
				'created_at' => $qb->createNamedParameter($now),
				'updated_at' => $qb->createNamedParameter($now),
			])->executeStatement();
			$patternId = (int) $qb->getLastInsertId();
			$this->replaceWeekDays($patternId, $weekDays);
			$this->writeAudit(null, $actor, 'ROTATION_PATTERN_CREATED', 'rotation_pattern', $patternId, [
				'companyId' => $companyId,
				'name' => $name,
				'cycleWeeks' => $cycleWeeks,
			]);
			$this->db->commit();
		} catch (Throwable $e) {
			if ($this->db->inTransaction()) {
				$this->db->rollBack();
			}
			if ($this->isUniqueConstraintViolation($e)) {
				throw new \InvalidArgumentException('PATTERN_NAME_CONFLICT');
			}
			throw $e;
		}

		return $this->getPattern($patternId, $actor);
	}

	/**
	 * @param array<string,mixed> $payload
	 * @return array<string,mixed>
	 */
	public function updatePattern(int $id, array $payload, string $actor): array
	{
		$this->assertSchemaReady();
		$existing = $this->patternRow($id);
		$companyId = (int) $existing['company_id'];
		$this->companies->assertCanAccessCompany($actor, $companyId);
		$this->assertRotationEnabled($companyId);

		$name = array_key_exists('name', $payload)
			? $this->normalizeName((string) $payload['name'])
			: (string) $existing['name'];
		$cycleWeeks = array_key_exists('cycleWeeks', $payload) || array_key_exists('cycle_weeks', $payload)
			? (int) ($payload['cycleWeeks'] ?? $payload['cycle_weeks'])
			: (int) $existing['cycle_weeks'];
		$this->anchors->assertCycleWeeksAllowed($cycleWeeks, $this->settings->allowedCycleWeeks($companyId));

		$anchor = $this->normalizeAnchorFields($payload, $existing);
		$anchorChanged = $this->anchorFieldsChanged($existing, $anchor);
		if ($anchorChanged) {
			$reason = trim((string) ($payload['reason'] ?? $payload['anchorChangeReason'] ?? ''));
			if (mb_strlen($reason) < 10) {
				throw new \InvalidArgumentException('REASON_TOO_SHORT');
			}
			// Forward-only: effective_from defaults to today; never rewrite the past.
			if ($anchor['anchorEffectiveFrom'] === null) {
				$anchor['anchorEffectiveFrom'] = (new \DateTimeImmutable('today'))->format('Y-m-d');
			}
			$today = (new \DateTimeImmutable('today'))->format('Y-m-d');
			if ($anchor['anchorEffectiveFrom'] < $today) {
				throw new \InvalidArgumentException('ANCHOR_EFFECTIVE_IN_PAST');
			}
		}

		if ($name !== (string) $existing['name']) {
			$this->assertNameUnique($companyId, $name, $id);
		}

		$contractAvg = array_key_exists('contractAvgMinutes', $payload) || array_key_exists('contract_avg_minutes', $payload)
			? $this->optionalInt($payload['contractAvgMinutes'] ?? $payload['contract_avg_minutes'] ?? null)
			: ($existing['contract_avg_minutes'] !== null ? (int) $existing['contract_avg_minutes'] : null);

		$isActive = array_key_exists('isActive', $payload) || array_key_exists('is_active', $payload)
			? (((int) ($payload['isActive'] ?? $payload['is_active'])) ? 1 : 0)
			: (int) $existing['is_active'];

		$weekDaysPayload = $payload['weekDays'] ?? $payload['week_days'] ?? null;
		$replaceDays = $weekDaysPayload !== null;
		$weekDays = $replaceDays
			? $this->normalizeWeekDaysPayload($weekDaysPayload, $cycleWeeks)
			: null;

		$now = $this->now();
		$this->db->beginTransaction();
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->update('dc_rotation_patterns')
				->set('name', $qb->createNamedParameter($name))
				->set('cycle_weeks', $qb->createNamedParameter($cycleWeeks, IQueryBuilder::PARAM_INT))
				->set('anchor_type', $qb->createNamedParameter($anchor['anchorType']))
				->set('anchor_iso_week_index', $qb->createNamedParameter($anchor['anchorIsoWeekIndex'], IQueryBuilder::PARAM_INT))
				->set('anchor_date', $qb->createNamedParameter($anchor['anchorDate']))
				->set('anchor_effective_from', $qb->createNamedParameter($anchor['anchorEffectiveFrom']))
				->set('contract_avg_minutes', $qb->createNamedParameter($contractAvg, IQueryBuilder::PARAM_INT))
				->set('is_active', $qb->createNamedParameter($isActive, IQueryBuilder::PARAM_INT))
				->set('updated_by', $qb->createNamedParameter($actor))
				->set('updated_at', $qb->createNamedParameter($now))
				->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
				->executeStatement();

			if ($replaceDays && $weekDays !== null) {
				$this->replaceWeekDays($id, $weekDays);
			} elseif ((int) $existing['cycle_weeks'] !== $cycleWeeks && !$replaceDays) {
				throw new \InvalidArgumentException('WEEK_DAYS_REQUIRED_FOR_CYCLE_CHANGE');
			}

			$this->writeAudit(null, $actor, 'ROTATION_PATTERN_UPDATED', 'rotation_pattern', $id, [
				'companyId' => $companyId,
				'anchorChanged' => $anchorChanged,
				'reason' => $anchorChanged ? mb_substr(trim((string) ($payload['reason'] ?? $payload['anchorChangeReason'] ?? '')), 0, 200) : null,
			]);
			$this->db->commit();
		} catch (Throwable $e) {
			if ($this->db->inTransaction()) {
				$this->db->rollBack();
			}
			if ($this->isUniqueConstraintViolation($e)) {
				throw new \InvalidArgumentException('PATTERN_NAME_CONFLICT');
			}
			throw $e;
		}

		return $this->getPattern($id, $actor);
	}

	/**
	 * Soft-deactivate a pattern (is_active=0).
	 *
	 * @return array<string,mixed>
	 */
	public function deactivatePattern(int $id, string $actor): array
	{
		return $this->updatePattern($id, ['isActive' => 0], $actor);
	}

	/**
	 * Assign a pattern to an employee. Overlapping open-ended / dated ranges →
	 * ASSIGNMENT_OVERLAP unless $supersede ends previous ranges the day before validFrom.
	 *
	 * @return array<string,mixed>
	 */
	public function assignToEmployee(
		int $employeeId,
		int $patternId,
		string $validFrom,
		?string $validTo,
		string $actor,
		bool $supersede = false,
	): array {
		$this->assertSchemaReady();
		$this->assertDate($validFrom);
		if ($validTo !== null && $validTo !== '') {
			$this->assertDate($validTo);
			if ($validTo < $validFrom) {
				throw new \InvalidArgumentException('INVALID_DATE_RANGE');
			}
		} else {
			$validTo = null;
		}

		$pattern = $this->patternRow($patternId);
		$companyId = (int) $pattern['company_id'];
		$this->companies->assertCanAccessCompany($actor, $companyId);
		$this->assertRotationEnabled($companyId);
		if ((int) $pattern['is_active'] !== 1) {
			throw new \InvalidArgumentException('PATTERN_INACTIVE');
		}
		$this->assertEmployeeInCompany($employeeId, $companyId, $actor);

		$holder = $actor . ':' . bin2hex(random_bytes(4));
		$locked = $this->locks !== null
			&& $this->locks->acquire($employeeId, PeriodLockService::KIND_ROT_ASSIGN, $holder, 30);
		if ($this->locks !== null && !$locked) {
			throw new \InvalidArgumentException('ASSIGNMENT_OVERLAP');
		}

		$this->db->beginTransaction();
		try {
			$overlaps = $this->findOverlappingAssignments($employeeId, $validFrom, $validTo, null);
			if ($overlaps !== []) {
				if (!$supersede) {
					throw new \InvalidArgumentException('ASSIGNMENT_OVERLAP');
				}
				$endDay = (new \DateTimeImmutable($validFrom . ' 00:00:00'))
					->modify('-1 day')
					->format('Y-m-d');
				foreach ($overlaps as $row) {
					$prevFrom = (string) $row['valid_from'];
					$qb = $this->db->getQueryBuilder();
					if ($prevFrom >= $validFrom) {
						// Same-or-later start: replace by deleting the superseded row.
						$qb->delete('dc_emp_rot_assign')
							->where($qb->expr()->eq('id', $qb->createNamedParameter((int) $row['id'], IQueryBuilder::PARAM_INT)))
							->executeStatement();
						continue;
					}
					$qb->update('dc_emp_rot_assign')
						->set('valid_to', $qb->createNamedParameter($endDay))
						->where($qb->expr()->eq('id', $qb->createNamedParameter((int) $row['id'], IQueryBuilder::PARAM_INT)))
						->executeStatement();
				}
			}

			$now = $this->now();
			try {
				$qb = $this->db->getQueryBuilder();
				$qb->insert('dc_emp_rot_assign')->values([
					'employee_id' => $qb->createNamedParameter($employeeId, IQueryBuilder::PARAM_INT),
					'pattern_id' => $qb->createNamedParameter($patternId, IQueryBuilder::PARAM_INT),
					'company_id' => $qb->createNamedParameter($companyId, IQueryBuilder::PARAM_INT),
					'valid_from' => $qb->createNamedParameter($validFrom),
					'valid_to' => $qb->createNamedParameter($validTo),
					'created_by' => $qb->createNamedParameter($actor),
					'created_at' => $qb->createNamedParameter($now),
				])->executeStatement();
				$assignmentId = (int) $qb->getLastInsertId();
			} catch (Throwable $e) {
				if ($this->isUniqueConstraintViolation($e)) {
					throw new \InvalidArgumentException('ASSIGNMENT_OVERLAP');
				}
				throw $e;
			}

			$this->writeAudit(null, $actor, 'ROTATION_ASSIGNMENT_CREATED', 'emp_rot_assign', $assignmentId, [
				'employeeId' => $employeeId,
				'patternId' => $patternId,
				'validFrom' => $validFrom,
				'validTo' => $validTo,
				'supersede' => $supersede,
			]);
			$this->db->commit();
		} catch (Throwable $e) {
			if ($this->db->inTransaction()) {
				$this->db->rollBack();
			}
			throw $e;
		} finally {
			if ($locked && $this->locks !== null) {
				$this->locks->release($employeeId, PeriodLockService::KIND_ROT_ASSIGN, $holder);
			}
		}

		return $this->normalizeEmpAssignment($this->empAssignmentRow($assignmentId));
	}

	/**
	 * @return list<array<string,mixed>>
	 */
	public function listEmployeeAssignments(int $employeeId, string $actor): array
	{
		$this->assertSchemaReady();
		$this->companies->assertRowCompany($actor, 'dc_employees', $employeeId);
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('dc_emp_rot_assign')
			->where($qb->expr()->eq('employee_id', $qb->createNamedParameter($employeeId, IQueryBuilder::PARAM_INT)))
			->orderBy('valid_from', 'ASC');
		$this->companies->restrictQuery($qb, 'company_id', $actor);
		$rows = $qb->executeQuery()->fetchAll();
		return array_map(fn (array $r): array => $this->normalizeEmpAssignment($r), $rows);
	}

	/**
	 * End an assignment by setting valid_to (inclusive).
	 *
	 * @return array<string,mixed>
	 */
	public function endAssignment(int $id, string $validTo, string $actor): array
	{
		$this->assertSchemaReady();
		$this->assertDate($validTo);
		$row = $this->empAssignmentRow($id);
		$this->companies->assertCanAccessCompany($actor, (int) $row['company_id']);
		$this->assertRotationEnabled((int) $row['company_id']);
		if ($validTo < (string) $row['valid_from']) {
			throw new \InvalidArgumentException('INVALID_DATE_RANGE');
		}
		$qb = $this->db->getQueryBuilder();
		$qb->update('dc_emp_rot_assign')
			->set('valid_to', $qb->createNamedParameter($validTo))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->executeStatement();
		$this->writeAudit(null, $actor, 'ROTATION_ASSIGNMENT_ENDED', 'emp_rot_assign', $id, [
			'validTo' => $validTo,
		]);
		return $this->normalizeEmpAssignment($this->empAssignmentRow($id));
	}

	/**
	 * Active pattern assignment covering a calendar date (inclusive range).
	 *
	 * @return array<string,mixed>|null camelCase assignment + nested pattern (without weekDays for speed)
	 */
	public function activeAssignmentForDate(int $employeeId, string $date, int $companyId): ?array
	{
		if (!SchemaProbe::tableExists($this->db, 'dc_emp_rot_assign')) {
			return null;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('a.*')
			->from('dc_emp_rot_assign', 'a')
			->where($qb->expr()->eq('a.employee_id', $qb->createNamedParameter($employeeId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('a.company_id', $qb->createNamedParameter($companyId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->lte('a.valid_from', $qb->createNamedParameter($date)))
			->andWhere($qb->expr()->orX(
				$qb->expr()->isNull('a.valid_to'),
				$qb->expr()->gte('a.valid_to', $qb->createNamedParameter($date)),
			))
			->orderBy('a.valid_from', 'DESC')
			->setMaxResults(1);
		$row = $qb->executeQuery()->fetch();
		if ($row === false) {
			return null;
		}
		return $this->normalizeEmpAssignment($row);
	}

	/**
	 * @return list<array<string,mixed>>
	 */
	public function weekDaysForPattern(int $patternId): array
	{
		return $this->loadWeekDays($patternId);
	}

	private function assertSchemaReady(): void
	{
		if (!SchemaProbe::tableExists($this->db, 'dc_rotation_patterns')
			|| !SchemaProbe::tableExists($this->db, 'dc_rotation_week_days')
			|| !SchemaProbe::tableExists($this->db, 'dc_emp_rot_assign')) {
			throw new \InvalidArgumentException('SCHEMA_NOT_READY');
		}
	}

	private function assertRotationEnabled(int $companyId): void
	{
		if (!$this->settings->isRotationEnabled($companyId)) {
			throw new \InvalidArgumentException('ROTATION_DISABLED');
		}
	}

	/**
	 * @param array<string,mixed> $payload
	 */
	private function resolveCompanyId(array $payload, string $actor): int
	{
		$raw = $payload['companyId'] ?? $payload['company_id'] ?? null;
		if ($raw !== null && $raw !== '') {
			$companyId = (int) $raw;
			$this->companies->assertCanAccessCompany($actor, $companyId);
			return $companyId;
		}
		return $this->companies->writeCompanyIdFor($actor);
	}

	private function normalizeName(string $name): string
	{
		$name = trim($name);
		if ($name === '' || mb_strlen($name) > 120) {
			throw new \InvalidArgumentException('PATTERN_NAME_INVALID');
		}
		return $name;
	}

	private function assertNameUnique(int $companyId, string $name, ?int $excludeId): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from('dc_rotation_patterns')
			->where($qb->expr()->eq('company_id', $qb->createNamedParameter($companyId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('name', $qb->createNamedParameter($name)))
			->setMaxResults(1);
		if ($excludeId !== null) {
			$qb->andWhere($qb->expr()->neq('id', $qb->createNamedParameter($excludeId, IQueryBuilder::PARAM_INT)));
		}
		if ($qb->executeQuery()->fetchOne() !== false) {
			throw new \InvalidArgumentException('PATTERN_NAME_CONFLICT');
		}
	}

	/**
	 * @param array<string,mixed> $payload
	 * @param array<string,mixed>|null $existing
	 * @return array{anchorType:string,anchorIsoWeekIndex:?int,anchorDate:?string,anchorEffectiveFrom:?string}
	 */
	private function normalizeAnchorFields(array $payload, ?array $existing): array
	{
		$anchorType = (string) ($payload['anchorType'] ?? $payload['anchor_type']
			?? $existing['anchor_type'] ?? self::ANCHOR_ISO_WEEK_PARITY);
		if (!in_array($anchorType, [self::ANCHOR_ISO_WEEK_PARITY, self::ANCHOR_FIXED_DATE], true)) {
			throw new \InvalidArgumentException('INVALID_ANCHOR_TYPE');
		}

		$isoIdx = $payload['anchorIsoWeekIndex'] ?? $payload['anchor_iso_week_index']
			?? ($existing['anchor_iso_week_index'] ?? null);
		$isoIdx = ($isoIdx === null || $isoIdx === '') ? null : (int) $isoIdx;

		$anchorDate = $payload['anchorDate'] ?? $payload['anchor_date']
			?? ($existing['anchor_date'] ?? null);
		$anchorDate = ($anchorDate === null || $anchorDate === '') ? null : (string) $anchorDate;
		if ($anchorDate !== null) {
			$this->assertDate($anchorDate);
		}
		if ($anchorType === self::ANCHOR_FIXED_DATE && $anchorDate === null) {
			throw new \InvalidArgumentException('INVALID_ANCHOR_DATE');
		}

		$effective = $payload['anchorEffectiveFrom'] ?? $payload['anchor_effective_from']
			?? ($existing['anchor_effective_from'] ?? null);
		$effective = ($effective === null || $effective === '') ? null : (string) $effective;
		if ($effective !== null) {
			$this->assertDate($effective);
		}

		return [
			'anchorType' => $anchorType,
			'anchorIsoWeekIndex' => $isoIdx,
			'anchorDate' => $anchorDate,
			'anchorEffectiveFrom' => $effective,
		];
	}

	/**
	 * @param array<string,mixed> $existing
	 * @param array{anchorType:string,anchorIsoWeekIndex:?int,anchorDate:?string,anchorEffectiveFrom:?string} $anchor
	 */
	private function anchorFieldsChanged(array $existing, array $anchor): bool
	{
		$exType = (string) ($existing['anchor_type'] ?? self::ANCHOR_ISO_WEEK_PARITY);
		$exIso = $existing['anchor_iso_week_index'] !== null ? (int) $existing['anchor_iso_week_index'] : null;
		$exDate = $existing['anchor_date'] !== null ? (string) $existing['anchor_date'] : null;
		return $exType !== $anchor['anchorType']
			|| $exIso !== $anchor['anchorIsoWeekIndex']
			|| $exDate !== $anchor['anchorDate'];
	}

	/**
	 * Require exactly cycleWeeks × 7 day rows (week_index 0..N-1, dow 1..7).
	 *
	 * @param mixed $raw
	 * @return list<array{
	 *   weekIndex:int,dow:int,isWorking:bool,netMinutes:int,
	 *   shiftTemplateId:?int,startLocal:?string,endLocal:?string,breakMinutes:int,locationId:?int
	 * }>
	 */
	private function normalizeWeekDaysPayload(mixed $raw, int $cycleWeeks): array
	{
		if (!is_array($raw)) {
			throw new \InvalidArgumentException('WEEK_DAYS_REQUIRED');
		}
		$byKey = [];
		foreach ($raw as $item) {
			if (!is_array($item)) {
				continue;
			}
			$weekIndex = (int) ($item['weekIndex'] ?? $item['week_index'] ?? -1);
			$dow = (int) ($item['dow'] ?? $item['dayOfWeek'] ?? -1);
			if ($weekIndex < 0 || $weekIndex >= $cycleWeeks || $dow < 1 || $dow > 7) {
				throw new \InvalidArgumentException('WEEK_DAY_OUT_OF_RANGE');
			}
			$isWorking = (bool) ($item['isWorking'] ?? $item['is_working'] ?? false);
			$start = $this->optionalTime($item['startLocal'] ?? $item['start_local'] ?? null);
			$end = $this->optionalTime($item['endLocal'] ?? $item['end_local'] ?? null);
			$break = max(0, (int) ($item['breakMinutes'] ?? $item['break_minutes'] ?? 0));
			$net = array_key_exists('netMinutes', $item) || array_key_exists('net_minutes', $item)
				? max(0, (int) ($item['netMinutes'] ?? $item['net_minutes']))
				: ($isWorking && $start !== null && $end !== null
					? $this->netMinutesFromTimes($start, $end, $break)
					: 0);
			$templateId = $this->optionalPositiveInt($item['shiftTemplateId'] ?? $item['shift_template_id'] ?? null);
			$locationId = $this->optionalPositiveInt($item['locationId'] ?? $item['location_id'] ?? null);
			if ($isWorking && $start !== null && $end !== null && $start === $end) {
				throw new \InvalidArgumentException('EQUAL_DUTY_TIMES');
			}
			$key = $weekIndex . ':' . $dow;
			$byKey[$key] = [
				'weekIndex' => $weekIndex,
				'dow' => $dow,
				'isWorking' => $isWorking,
				'netMinutes' => $isWorking ? $net : 0,
				'shiftTemplateId' => $templateId,
				'startLocal' => $isWorking ? $start : null,
				'endLocal' => $isWorking ? $end : null,
				'breakMinutes' => $isWorking ? $break : 0,
				'locationId' => $locationId,
			];
		}

		$out = [];
		for ($w = 0; $w < $cycleWeeks; $w++) {
			for ($d = 1; $d <= 7; $d++) {
				$key = $w . ':' . $d;
				if (!isset($byKey[$key])) {
					// Allow sparse input: fill non-working placeholders.
					$out[] = [
						'weekIndex' => $w,
						'dow' => $d,
						'isWorking' => false,
						'netMinutes' => 0,
						'shiftTemplateId' => null,
						'startLocal' => null,
						'endLocal' => null,
						'breakMinutes' => 0,
						'locationId' => null,
					];
				} else {
					$out[] = $byKey[$key];
				}
			}
		}
		return $out;
	}

	/**
	 * @param list<array{
	 *   weekIndex:int,dow:int,isWorking:bool,netMinutes:int,
	 *   shiftTemplateId:?int,startLocal:?string,endLocal:?string,breakMinutes:int,locationId:?int
	 * }> $weekDays
	 */
	private function replaceWeekDays(int $patternId, array $weekDays): void
	{
		$del = $this->db->getQueryBuilder();
		$del->delete('dc_rotation_week_days')
			->where($del->expr()->eq('pattern_id', $del->createNamedParameter($patternId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
		foreach ($weekDays as $day) {
			$qb = $this->db->getQueryBuilder();
			$qb->insert('dc_rotation_week_days')->values([
				'pattern_id' => $qb->createNamedParameter($patternId, IQueryBuilder::PARAM_INT),
				'week_index' => $qb->createNamedParameter($day['weekIndex'], IQueryBuilder::PARAM_INT),
				'dow' => $qb->createNamedParameter($day['dow'], IQueryBuilder::PARAM_INT),
				'is_working' => $qb->createNamedParameter($day['isWorking'] ? 1 : 0, IQueryBuilder::PARAM_INT),
				'net_minutes' => $qb->createNamedParameter($day['netMinutes'], IQueryBuilder::PARAM_INT),
				'shift_template_id' => $qb->createNamedParameter($day['shiftTemplateId'], IQueryBuilder::PARAM_INT),
				'start_local' => $qb->createNamedParameter($day['startLocal']),
				'end_local' => $qb->createNamedParameter($day['endLocal']),
				'break_minutes' => $qb->createNamedParameter($day['breakMinutes'], IQueryBuilder::PARAM_INT),
				'location_id' => $qb->createNamedParameter($day['locationId'], IQueryBuilder::PARAM_INT),
			])->executeStatement();
		}
	}

	/**
	 * @return list<array<string,mixed>>
	 */
	private function loadWeekDays(int $patternId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('dc_rotation_week_days')
			->where($qb->expr()->eq('pattern_id', $qb->createNamedParameter($patternId, IQueryBuilder::PARAM_INT)))
			->orderBy('week_index', 'ASC')
			->addOrderBy('dow', 'ASC');
		$rows = $qb->executeQuery()->fetchAll();
		return array_map(static function (array $r): array {
			return [
				'id' => (int) $r['id'],
				'weekIndex' => (int) $r['week_index'],
				'dow' => (int) $r['dow'],
				'isWorking' => (int) $r['is_working'] === 1,
				'netMinutes' => (int) $r['net_minutes'],
				'shiftTemplateId' => $r['shift_template_id'] !== null ? (int) $r['shift_template_id'] : null,
				'startLocal' => $r['start_local'] !== null ? (string) $r['start_local'] : null,
				'endLocal' => $r['end_local'] !== null ? (string) $r['end_local'] : null,
				'breakMinutes' => (int) $r['break_minutes'],
				'locationId' => $r['location_id'] !== null ? (int) $r['location_id'] : null,
			];
		}, $rows);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function patternRow(int $id): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('dc_rotation_patterns')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$row = $qb->executeQuery()->fetch();
		if ($row === false) {
			throw new \InvalidArgumentException('PATTERN_NOT_FOUND');
		}
		return $row;
	}

	/**
	 * @param array<string,mixed> $row
	 * @param list<array<string,mixed>> $weekDays
	 * @return array<string,mixed>
	 */
	private function normalizePattern(array $row, array $weekDays): array
	{
		return [
			'id' => (int) $row['id'],
			'companyId' => (int) $row['company_id'],
			'name' => (string) $row['name'],
			'cycleWeeks' => (int) $row['cycle_weeks'],
			'anchorType' => (string) $row['anchor_type'],
			'anchorIsoWeekIndex' => $row['anchor_iso_week_index'] !== null ? (int) $row['anchor_iso_week_index'] : null,
			'anchorDate' => $row['anchor_date'] !== null ? (string) $row['anchor_date'] : null,
			'anchorEffectiveFrom' => $row['anchor_effective_from'] !== null ? (string) $row['anchor_effective_from'] : null,
			'contractAvgMinutes' => $row['contract_avg_minutes'] !== null ? (int) $row['contract_avg_minutes'] : null,
			'isActive' => (int) $row['is_active'] === 1,
			'createdBy' => (string) $row['created_by'],
			'updatedBy' => (string) $row['updated_by'],
			'createdAt' => (string) $row['created_at'],
			'updatedAt' => (string) $row['updated_at'],
			'weekDays' => $weekDays,
		];
	}

	/**
	 * @return array<string,mixed>
	 */
	private function empAssignmentRow(int $id): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('dc_emp_rot_assign')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$row = $qb->executeQuery()->fetch();
		if ($row === false) {
			throw new \InvalidArgumentException('ROTATION_ASSIGNMENT_NOT_FOUND');
		}
		return $row;
	}

	/**
	 * @param array<string,mixed> $row
	 * @return array<string,mixed>
	 */
	private function normalizeEmpAssignment(array $row): array
	{
		return [
			'id' => (int) $row['id'],
			'employeeId' => (int) $row['employee_id'],
			'patternId' => (int) $row['pattern_id'],
			'companyId' => (int) $row['company_id'],
			'validFrom' => (string) $row['valid_from'],
			'validTo' => $row['valid_to'] !== null ? (string) $row['valid_to'] : null,
			'createdBy' => (string) $row['created_by'],
			'createdAt' => (string) $row['created_at'],
		];
	}

	/**
	 * @return list<array<string,mixed>>
	 */
	private function findOverlappingAssignments(int $employeeId, string $validFrom, ?string $validTo, ?int $excludeId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('dc_emp_rot_assign')
			->where($qb->expr()->eq('employee_id', $qb->createNamedParameter($employeeId, IQueryBuilder::PARAM_INT)));
		if ($excludeId !== null) {
			$qb->andWhere($qb->expr()->neq('id', $qb->createNamedParameter($excludeId, IQueryBuilder::PARAM_INT)));
		}
		$rows = $qb->executeQuery()->fetchAll();
		$out = [];
		foreach ($rows as $row) {
			$from = (string) $row['valid_from'];
			$to = $row['valid_to'] !== null ? (string) $row['valid_to'] : null;
			if ($this->dateRangesOverlap($validFrom, $validTo, $from, $to)) {
				$out[] = $row;
			}
		}
		return $out;
	}

	private function dateRangesOverlap(string $aFrom, ?string $aTo, string $bFrom, ?string $bTo): bool
	{
		// Inclusive dates; null end = open.
		if ($aTo !== null && $aTo < $bFrom) {
			return false;
		}
		if ($bTo !== null && $bTo < $aFrom) {
			return false;
		}
		return true;
	}

	private function assertEmployeeInCompany(int $employeeId, int $companyId, string $actor): void
	{
		$this->companies->assertRowCompany($actor, 'dc_employees', $employeeId);
		if (!$this->companies->isMultiCompanyActive() || !SchemaProbe::hasColumn($this->db, 'dc_employees', 'company_id')) {
			return;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('company_id')->from('dc_employees')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($employeeId, IQueryBuilder::PARAM_INT)));
		$row = $qb->executeQuery()->fetch();
		if ($row === false) {
			throw new \InvalidArgumentException('EMPLOYEE_NOT_FOUND');
		}
		if ((int) ($row['company_id'] ?? 0) !== $companyId) {
			throw new \InvalidArgumentException('COMPANY_MISMATCH');
		}
	}

	/**
	 * @param array<string,mixed> $payload
	 */
	private function writeAudit(?int $periodId, string $actor, string $action, string $targetKind, ?int $targetId, array $payload): void
	{
		if (!SchemaProbe::tableExists($this->db, 'dc_period_audit_log')) {
			return;
		}
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->insert('dc_period_audit_log')->values([
				'period_id' => $qb->createNamedParameter($periodId, IQueryBuilder::PARAM_INT),
				'actor_user_id' => $qb->createNamedParameter($actor),
				'action' => $qb->createNamedParameter($action),
				'target_kind' => $qb->createNamedParameter($targetKind),
				'target_id' => $qb->createNamedParameter($targetId, IQueryBuilder::PARAM_INT),
				'payload_json' => $qb->createNamedParameter(json_encode($payload, JSON_THROW_ON_ERROR)),
				'created_at' => $qb->createNamedParameter($this->now()),
			])->executeStatement();
		} catch (Throwable) {
			// Audit must never block the primary write.
		}
	}

	private function assertDate(string $date): void
	{
		if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
			throw new \InvalidArgumentException('INVALID_DATE');
		}
		$dt = \DateTimeImmutable::createFromFormat('Y-m-d', $date);
		if ($dt === false || $dt->format('Y-m-d') !== $date) {
			throw new \InvalidArgumentException('INVALID_DATE');
		}
	}

	private function optionalTime(mixed $raw): ?string
	{
		if ($raw === null || $raw === '') {
			return null;
		}
		$trim = trim((string) $raw);
		if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $trim) !== 1) {
			throw new \InvalidArgumentException('INVALID_DUTY_TIME');
		}
		return $trim;
	}

	private function optionalInt(mixed $raw): ?int
	{
		if ($raw === null || $raw === '') {
			return null;
		}
		return (int) $raw;
	}

	private function optionalPositiveInt(mixed $raw): ?int
	{
		if ($raw === null || $raw === '') {
			return null;
		}
		$n = (int) $raw;
		return $n > 0 ? $n : null;
	}

	private function netMinutesFromTimes(string $start, string $end, int $breakMinutes): int
	{
		[$sh, $sm] = array_map('intval', explode(':', $start));
		[$eh, $em] = array_map('intval', explode(':', $end));
		$startM = $sh * 60 + $sm;
		$endM = $eh * 60 + $em;
		if ($endM <= $startM) {
			$endM += 24 * 60; // overnight
		}
		return max(0, $endM - $startM - max(0, $breakMinutes));
	}

	private function now(): string
	{
		return (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s');
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
