<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Service;

use DateTimeImmutable;
use OCA\DutyCheck\Db\SchemaProbe;
use OCA\DutyCheck\Exception\ConflictAckRequiredException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Throwable;

/**
 * Preview / confirm rotation suggest-fill into an open roster period.
 *
 * Never overwrites occupied cells, never auto-publishes, respects absences + hard blackouts.
 * Confirm is all-or-nothing under a period mutex ({@see PeriodLockService::KIND_SUGGEST}).
 */
final class RotationSuggestService
{
	private const SOURCE_ROTATION_SUGGEST = 'rotation_suggest';
	private const SAMPLE_LIMIT = 25;
	private const LOCK_TTL_SECONDS = 600;
	/** Preview is cheaper — allow more bursts than confirm. */
	private const PREVIEW_RATE_PER_MIN = 30;
	private const CONFIRM_RATE_PER_MIN = 8;

	/**
	 * Soft skip codes — leave cell empty inside the all-or-nothing TX without rolling back.
	 * Hard errors (PERIOD_NOT_OPEN, FORBIDDEN, SCHEMA*, …) must re-throw so the TX rolls back.
	 */
	private const SOFT_WRITE_SKIP = [
		'ASSIGNMENT_OVERLAP',
		'ASSIGNMENT_DUPLICATE_SLOT',
		'ABSENCE_CONFLICT',
		'BLACKOUT_CONFLICT',
		'DATE_OUTSIDE_PERIOD',
		'LOCATION_OUT_OF_SCOPE',
	];

	public function __construct(
		private readonly IDBConnection $db,
		private readonly RosterService $roster,
		private readonly CompanyService $companies,
		private readonly SelfServiceSettingsService $settings,
		private readonly RotationAnchorService $anchors,
		private readonly RotationPatternService $patterns,
		private readonly PeriodLockService $locks,
		private readonly ?AvailabilityBlackoutService $blackouts = null,
		private readonly ?ApiRateLimitService $rateLimits = null,
	) {
	}

	/**
	 * Dry-run: count cells that would be created / skipped. No writes.
	 *
	 * @param list<int>|null $employeeIds
	 * @return array<string,mixed>
	 */
	public function preview(int $periodId, string $actor, ?array $employeeIds = null, ?int $locationId = null): array
	{
		$this->rateLimits?->assertAllowed(
			'suggest-preview:' . $actor,
			self::PREVIEW_RATE_PER_MIN,
		);
		$plan = $this->buildPlan($periodId, $actor, $employeeIds, $locationId);
		$plan['created'] = count($plan['candidates']);
		$plan['samples'] = array_slice(
			array_map(fn (array $cell): array => $this->sampleFromCell($cell, null), $plan['candidates']),
			0,
			self::SAMPLE_LIMIT,
		);
		return $this->summarize($plan, written: false);
	}

	/**
	 * Apply suggest-fill under period mutex; one DB transaction for all creates.
	 *
	 * @param list<int>|null $employeeIds
	 * @return array<string,mixed>
	 */
	public function confirm(int $periodId, string $actor, ?array $employeeIds = null, ?int $locationId = null): array
	{
		$this->rateLimits?->assertAllowed(
			'suggest-confirm:' . $actor,
			self::CONFIRM_RATE_PER_MIN,
		);
		$holder = $actor . ':' . bin2hex(random_bytes(4));

		if (!$this->locks->acquire($periodId, PeriodLockService::KIND_SUGGEST, $holder, self::LOCK_TTL_SECONDS)) {
			throw new \InvalidArgumentException('SUGGEST_IN_PROGRESS');
		}

		try {
			// Build under the mutex so concurrent assign/blackout cannot race the candidate set.
			$plan = $this->buildPlan($periodId, $actor, $employeeIds, $locationId);
			$plan['created'] = 0;
			$plan['skipped_write'] = 0;
			$plan['samples'] = [];

			if ($plan['candidates'] === []
				&& $plan['skipped_no_location'] > 0
				&& ($locationId === null || $locationId < 1)
			) {
				throw new \InvalidArgumentException('SUGGEST_LOCATION_REQUIRED');
			}

			$this->db->beginTransaction();
			try {
				$i = 0;
				foreach ($plan['candidates'] as $cell) {
					// Heartbeat: refresh TTL so long confirms cannot be stolen mid-TX.
					if (($i % 10) === 0) {
						$this->locks->acquire($periodId, PeriodLockService::KIND_SUGGEST, $holder, self::LOCK_TTL_SECONDS);
					}
					$i++;
					$createdId = $this->writeCell($cell, $actor);
					if ($createdId > 0) {
						$plan['created']++;
						if (count($plan['samples']) < self::SAMPLE_LIMIT) {
							$plan['samples'][] = $this->sampleFromCell($cell, $createdId);
						}
					} else {
						$plan['skipped_write']++;
					}
				}
				$this->writeAudit($periodId, $actor, 'ROTATION_SUGGEST_APPLIED', [
					'created' => $plan['created'],
					'skippedExisting' => $plan['skipped_existing'],
					'skippedAbsence' => $plan['skipped_absence'],
					'skippedBlackout' => $plan['skipped_blackout'],
					'skippedNoPattern' => $plan['skipped_no_pattern'],
					'skippedNoLocation' => $plan['skipped_no_location'],
					'skippedLocationMismatch' => $plan['skipped_location_mismatch'],
					'skippedWrite' => $plan['skipped_write'],
				]);
				$this->db->commit();
			} catch (Throwable $e) {
				if ($this->db->inTransaction()) {
					$this->db->rollBack();
				}
				throw $e;
			}
		} finally {
			$this->locks->release($periodId, PeriodLockService::KIND_SUGGEST, $holder);
		}

		return $this->summarize($plan, written: true);
	}

	/**
	 * @param list<int>|null $employeeIds
	 * @return array{
	 *   periodId:int,
	 *   created:int,
	 *   skipped_existing:int,
	 *   skipped_absence:int,
	 *   skipped_blackout:int,
	 *   skipped_no_pattern:int,
	 *   skipped_no_location:int,
	 *   skipped_location_mismatch:int,
	 *   samples:list<array<string,mixed>>,
	 *   candidates:list<array<string,mixed>>
	 * }
	 */
	private function buildPlan(int $periodId, string $actor, ?array $employeeIds, ?int $locationId): array
	{
		if (!SchemaProbe::tableExists($this->db, 'dc_rotation_patterns')
			|| !SchemaProbe::tableExists($this->db, 'dc_emp_rot_assign')) {
			throw new \InvalidArgumentException('SCHEMA_NOT_READY');
		}

		$this->roster->assertPeriodCompanyAccess($actor, $periodId);
		$roster = $this->roster->rosterData($periodId, $actor);
		$period = null;
		foreach ($roster['periods'] as $p) {
			if ((int) $p['id'] === $periodId) {
				$period = $p;
				break;
			}
		}
		if ($period === null) {
			throw new \InvalidArgumentException('PERIOD_NOT_FOUND');
		}
		if (($period['status'] ?? '') !== 'open') {
			throw new \InvalidArgumentException('SUGGEST_PERIOD_NOT_OPEN');
		}

		$companyId = $this->periodCompanyId($periodId, $actor);
		if (!$this->settings->isRotationEnabled($companyId)) {
			throw new \InvalidArgumentException('ROTATION_DISABLED');
		}

		$settings = $this->settings->getForCompany($companyId);
		$rankPrefs = (bool) ($settings['preference_ranking_on_suggest'] ?? false)
			&& (bool) ($settings['preferences_enabled'] ?? false);
		$blackoutsOn = (bool) ($settings['blackouts_enabled'] ?? false);
		$earlyEnd = (string) ($settings['early_end_latest'] ?? '12:00');
		$lateStart = (string) ($settings['late_start_earliest'] ?? '14:00');

		$startDate = (string) $period['startDate'];
		$endDate = (string) $period['endDate'];

		/** @var array<int, true> occupied empId|date → true (any active assignment that day) */
		$occupied = [];
		foreach ($roster['assignments'] as $a) {
			$key = (int) $a['employeeId'] . '|' . (string) $a['dutyDate'];
			$occupied[$key] = true;
		}

		/** @var array<int, list<array{startDate:string,endDate:string}>> */
		$absencesByEmp = [];
		foreach ($roster['absenceBlocks'] as $span) {
			$eid = (int) ($span['employeeId'] ?? 0);
			if ($eid < 1) {
				continue;
			}
			$absencesByEmp[$eid][] = [
				'startDate' => (string) $span['startDate'],
				'endDate' => (string) $span['endDate'],
			];
		}

		$employeeFilter = null;
		if ($employeeIds !== null) {
			$employeeFilter = [];
			foreach ($employeeIds as $id) {
				$id = (int) $id;
				if ($id > 0) {
					$employeeFilter[$id] = true;
				}
			}
		}

		$employees = [];
		foreach ($roster['employees'] as $emp) {
			$eid = (int) $emp['id'];
			if ($employeeFilter !== null && !isset($employeeFilter[$eid])) {
				continue;
			}
			$employees[] = $emp;
		}

		if ($rankPrefs) {
			$prefsByEmp = $this->loadPreferences($companyId, array_map(static fn (array $e): int => (int) $e['id'], $employees));
			usort($employees, function (array $a, array $b) use ($prefsByEmp, $earlyEnd, $lateStart): int {
				$sa = $this->preferenceScore((int) $a['id'], $prefsByEmp, $earlyEnd, $lateStart);
				$sb = $this->preferenceScore((int) $b['id'], $prefsByEmp, $earlyEnd, $lateStart);
				return $sb <=> $sa;
			});
		}

		$blackoutsByEmp = $blackoutsOn
			? $this->loadBlackouts($companyId, array_map(static fn (array $e): int => (int) $e['id'], $employees), $startDate, $endDate)
			: [];

		$templateCache = [];
		$patternCache = [];
		$weekDayCache = [];

		$plan = [
			'periodId' => $periodId,
			'created' => 0,
			'skipped_existing' => 0,
			'skipped_absence' => 0,
			'skipped_blackout' => 0,
			'skipped_no_pattern' => 0,
			'skipped_no_location' => 0,
			'skipped_location_mismatch' => 0,
			'skipped_write' => 0,
			'samples' => [],
			'candidates' => [],
		];

		$cursor = new DateTimeImmutable($startDate . ' 00:00:00');
		$end = new DateTimeImmutable($endDate . ' 00:00:00');

		foreach ($employees as $emp) {
			$employeeId = (int) $emp['id'];
			$sawActivePattern = false;

			for ($day = $cursor; $day <= $end; $day = $day->modify('+1 day')) {
				$date = $day->format('Y-m-d');
				$assignment = $this->patterns->activeAssignmentForDate($employeeId, $date, $companyId);
				if ($assignment === null) {
					continue;
				}
				$patternId = (int) $assignment['patternId'];
				if (!isset($patternCache[$patternId])) {
					try {
						$patternCache[$patternId] = $this->patterns->getPattern($patternId, $actor);
					} catch (\InvalidArgumentException) {
						$patternCache[$patternId] = null;
					}
				}
				$pattern = $patternCache[$patternId];
				if ($pattern === null || !($pattern['isActive'] ?? false)) {
					continue;
				}
				$sawActivePattern = true;

				$weekIndex = $this->anchors->weekIndexForDate([
					'cycle_weeks' => (int) $pattern['cycleWeeks'],
					'anchor_type' => (string) $pattern['anchorType'],
					'anchor_iso_week_index' => $pattern['anchorIsoWeekIndex'],
					'anchor_date' => $pattern['anchorDate'],
					'anchor_effective_from' => $pattern['anchorEffectiveFrom'],
				], $day);
				$dow = (int) $day->format('N');

				$dayKey = $patternId . ':' . $weekIndex . ':' . $dow;
				if (!isset($weekDayCache[$dayKey])) {
					$weekDayCache[$dayKey] = $this->findWeekDay($pattern['weekDays'] ?? [], $weekIndex, $dow);
				}
				$weekDay = $weekDayCache[$dayKey];
				if ($weekDay === null || !($weekDay['isWorking'] ?? false)) {
					continue;
				}

				$occKey = $employeeId . '|' . $date;
				if (isset($occupied[$occKey])) {
					$plan['skipped_existing']++;
					continue;
				}

				if ($this->dateInAbsenceSpans($date, $absencesByEmp[$employeeId] ?? [])) {
					$plan['skipped_absence']++;
					continue;
				}

				$resolved = $this->resolveShiftTimes($weekDay, $templateCache);
				if ($resolved === null) {
					continue;
				}

				$cellLocationId = $resolved['locationId'];
				if ($cellLocationId === null && $locationId !== null && $locationId > 0) {
					$cellLocationId = $locationId;
				}
				// Fail closed: never invent a Filiale via lowest-id company fallback.
				if ($cellLocationId === null || $cellLocationId < 1) {
					$plan['skipped_no_location']++;
					continue;
				}
				if ($locationId !== null && $locationId > 0 && $cellLocationId !== $locationId) {
					// Explicit filter drop — count it; an uncounted continue made
					// the preview report "nothing to fill" with all-zero skips.
					$plan['skipped_location_mismatch']++;
					continue;
				}

				if ($blackoutsOn && $this->overlapsBlackout(
					$blackoutsByEmp[$employeeId] ?? [],
					$date,
					$resolved['startLocal'],
					$resolved['endLocal'],
					$cellLocationId,
				)) {
					$plan['skipped_blackout']++;
					continue;
				}

				$plan['candidates'][] = [
					'periodId' => $periodId,
					'employeeId' => $employeeId,
					'locationId' => $cellLocationId,
					'dutyDate' => $date,
					'startTime' => $resolved['startLocal'],
					'endTime' => $resolved['endLocal'],
					'breakMinutes' => $resolved['breakMinutes'],
					'patternId' => $patternId,
					'weekIndex' => $weekIndex,
				];
			}

			if (!$sawActivePattern) {
				$plan['skipped_no_pattern']++;
			}
		}

		return $plan;
	}

	/**
	 * @param array<string,mixed> $cell
	 */
	private function writeCell(array $cell, string $actor): int
	{
		$payload = [
			'periodId' => (int) $cell['periodId'],
			'employeeId' => (int) $cell['employeeId'],
			'locationId' => (int) $cell['locationId'],
			'dutyDate' => (string) $cell['dutyDate'],
			'startTime' => (string) $cell['startTime'],
			'endTime' => (string) $cell['endTime'],
			'breakMinutes' => (int) $cell['breakMinutes'],
			'note' => '',
			'acknowledgements' => [],
		];

		try {
			$result = $this->roster->createAssignment($payload, $actor, false, false, false, false);
		} catch (ConflictAckRequiredException) {
			// Soft planning conflicts: leave cell empty (never invent an acknowledgement reason).
			return 0;
		} catch (\InvalidArgumentException $e) {
			if ($this->isSoftWriteSkip($e->getMessage())) {
				return 0;
			}
			throw $e;
		}

		$createdId = (int) ($result['createdAssignmentId'] ?? 0);
		if ($createdId > 0) {
			$this->stampSource($createdId);
		}
		return $createdId;
	}

	private function isSoftWriteSkip(string $code): bool
	{
		return in_array($code, self::SOFT_WRITE_SKIP, true);
	}

	private function stampSource(int $assignmentId): void
	{
		if (!SchemaProbe::hasColumn($this->db, 'dc_assignments', 'source')) {
			return;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->update('dc_assignments')
			->set('source', $qb->createNamedParameter(self::SOURCE_ROTATION_SUGGEST))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($assignmentId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	/**
	 * @param array{
	 *   periodId:int,
	 *   created:int,
	 *   skipped_existing:int,
	 *   skipped_absence:int,
	 *   skipped_blackout:int,
	 *   skipped_no_pattern:int,
	 *   skipped_no_location:int,
	 *   skipped_location_mismatch:int,
	 *   samples:list<array<string,mixed>>,
	 *   candidates:list<array<string,mixed>>
	 * } $plan
	 * @return array<string,mixed>
	 */
	private function summarize(array $plan, bool $written): array
	{
		return [
			'periodId' => $plan['periodId'],
			'written' => $written,
			'created' => $plan['created'],
			'skippedExisting' => $plan['skipped_existing'],
			'skippedAbsence' => $plan['skipped_absence'],
			'skippedBlackout' => $plan['skipped_blackout'],
			'skippedNoPattern' => $plan['skipped_no_pattern'],
			'skippedNoLocation' => $plan['skipped_no_location'] ?? 0,
			'skippedLocationMismatch' => $plan['skipped_location_mismatch'] ?? 0,
			'skippedWrite' => $plan['skipped_write'] ?? 0,
			'samples' => $plan['samples'],
		];
	}

	/**
	 * @param array<string,mixed> $cell
	 * @return array<string,mixed>
	 */
	private function sampleFromCell(array $cell, ?int $assignmentId): array
	{
		return [
			'assignmentId' => $assignmentId,
			'employeeId' => (int) $cell['employeeId'],
			'locationId' => (int) $cell['locationId'],
			'dutyDate' => (string) $cell['dutyDate'],
			'startTime' => (string) $cell['startTime'],
			'endTime' => (string) $cell['endTime'],
			'breakMinutes' => (int) $cell['breakMinutes'],
			'patternId' => (int) ($cell['patternId'] ?? 0),
			'weekIndex' => (int) ($cell['weekIndex'] ?? 0),
			'source' => self::SOURCE_ROTATION_SUGGEST,
		];
	}

	/**
	 * @param list<array<string,mixed>> $weekDays
	 * @return array<string,mixed>|null
	 */
	private function findWeekDay(array $weekDays, int $weekIndex, int $dow): ?array
	{
		foreach ($weekDays as $day) {
			if ((int) ($day['weekIndex'] ?? -1) === $weekIndex && (int) ($day['dow'] ?? -1) === $dow) {
				return $day;
			}
		}
		return null;
	}

	/**
	 * @param array<string,mixed> $weekDay
	 * @param array<int, array{startTime:string,endTime:string,breakMinutes:int,locationId:?int}|null> $templateCache
	 * @return array{startLocal:string,endLocal:string,breakMinutes:int,locationId:?int}|null
	 */
	private function resolveShiftTimes(array $weekDay, array &$templateCache): ?array
	{
		$start = $weekDay['startLocal'] ?? null;
		$end = $weekDay['endLocal'] ?? null;
		$break = (int) ($weekDay['breakMinutes'] ?? 0);
		$locationId = isset($weekDay['locationId']) && $weekDay['locationId'] !== null
			? (int) $weekDay['locationId']
			: null;
		$templateId = isset($weekDay['shiftTemplateId']) && $weekDay['shiftTemplateId'] !== null
			? (int) $weekDay['shiftTemplateId']
			: null;

		if (($start === null || $end === null) && $templateId !== null && $templateId > 0) {
			if (!array_key_exists($templateId, $templateCache)) {
				$templateCache[$templateId] = $this->loadTemplate($templateId);
			}
			$tpl = $templateCache[$templateId];
			if ($tpl !== null) {
				$start = $start ?? $tpl['startTime'];
				$end = $end ?? $tpl['endTime'];
				if ($break <= 0) {
					$break = $tpl['breakMinutes'];
				}
				if ($locationId === null) {
					$locationId = $tpl['locationId'];
				}
			}
		}

		if (!is_string($start) || !is_string($end) || $start === '' || $end === '' || $start === $end) {
			return null;
		}

		return [
			'startLocal' => $start,
			'endLocal' => $end,
			'breakMinutes' => max(0, $break),
			'locationId' => $locationId,
		];
	}

	/**
	 * @return array{startTime:string,endTime:string,breakMinutes:int,locationId:?int}|null
	 */
	private function loadTemplate(int $templateId): ?array
	{
		if (!SchemaProbe::tableExists($this->db, 'dc_shift_templates')) {
			return null;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('start_time', 'end_time', 'break_minutes', 'location_id')
			->from('dc_shift_templates')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($templateId, IQueryBuilder::PARAM_INT)))
			->setMaxResults(1);
		$row = $qb->executeQuery()->fetch();
		if ($row === false) {
			return null;
		}
		return [
			'startTime' => (string) $row['start_time'],
			'endTime' => (string) $row['end_time'],
			'breakMinutes' => (int) $row['break_minutes'],
			'locationId' => $row['location_id'] !== null ? (int) $row['location_id'] : null,
		];
	}

	/**
	 * @param list<array{startDate:string,endDate:string}> $spans
	 */
	private function dateInAbsenceSpans(string $date, array $spans): bool
	{
		foreach ($spans as $span) {
			if ($span['startDate'] <= $date && $span['endDate'] >= $date) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param list<int> $employeeIds
	 * @return array<int, list<array{start_at:string,end_at:string,location_id:?int}>>
	 */
	private function loadBlackouts(int $companyId, array $employeeIds, string $periodStart, string $periodEnd): array
	{
		if ($employeeIds === [] || !SchemaProbe::tableExists($this->db, 'dc_avail_blackouts')) {
			return [];
		}
		$windowStart = $periodStart . ' 00:00:00';
		$windowEnd = $periodEnd . ' 23:59:59';
		$qb = $this->db->getQueryBuilder();
		$qb->select('employee_id', 'start_at', 'end_at', 'location_id')
			->from('dc_avail_blackouts')
			->where($qb->expr()->eq('company_id', $qb->createNamedParameter($companyId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->in(
				'employee_id',
				$qb->createNamedParameter($employeeIds, IQueryBuilder::PARAM_INT_ARRAY),
			))
			->andWhere($qb->expr()->lt('start_at', $qb->createNamedParameter($windowEnd)))
			->andWhere($qb->expr()->gt('end_at', $qb->createNamedParameter($windowStart)));
		$out = [];
		foreach ($qb->executeQuery()->fetchAll() as $row) {
			$eid = (int) $row['employee_id'];
			$out[$eid][] = [
				'start_at' => (string) $row['start_at'],
				'end_at' => (string) $row['end_at'],
				'location_id' => $row['location_id'] !== null ? (int) $row['location_id'] : null,
			];
		}
		return $out;
	}

	/**
	 * @param list<array{start_at:string,end_at:string,location_id:?int}> $blackouts
	 */
	private function overlapsBlackout(
		array $blackouts,
		string $date,
		string $startLocal,
		string $endLocal,
		int $locationId,
	): bool {
		if ($this->blackouts !== null) {
			return $this->blackouts->anyOverlapsDuty($blackouts, $date, $startLocal, $endLocal, $locationId);
		}
		// Fallback without DI (tests): compare as UTC wall strings only if times look ISO.
		$shiftStart = $date . ' ' . $startLocal . ':00';
		$shiftEnd = $date . ' ' . $endLocal . ':00';
		if ($shiftEnd <= $shiftStart) {
			$shiftEnd = (new DateTimeImmutable($date . ' 00:00:00'))
				->modify('+1 day')
				->format('Y-m-d') . ' ' . $endLocal . ':00';
		}
		foreach ($blackouts as $b) {
			$bLoc = $b['location_id'];
			if ($bLoc !== null && $bLoc !== $locationId) {
				continue;
			}
			if ($b['start_at'] < $shiftEnd && $b['end_at'] > $shiftStart) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param list<int> $employeeIds
	 * @return array<int, list<array{band:string,weekday_mask:int,priority:int,location_id:?int}>>
	 */
	private function loadPreferences(int $companyId, array $employeeIds): array
	{
		if ($employeeIds === [] || !SchemaProbe::tableExists($this->db, 'dc_shift_preferences')) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('employee_id', 'band', 'weekday_mask', 'priority', 'location_id')
			->from('dc_shift_preferences')
			->where($qb->expr()->eq('company_id', $qb->createNamedParameter($companyId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->in(
				'employee_id',
				$qb->createNamedParameter($employeeIds, IQueryBuilder::PARAM_INT_ARRAY),
			));
		$out = [];
		foreach ($qb->executeQuery()->fetchAll() as $row) {
			$eid = (int) $row['employee_id'];
			$out[$eid][] = [
				'band' => (string) $row['band'],
				'weekday_mask' => (int) $row['weekday_mask'],
				'priority' => (int) $row['priority'],
				'location_id' => $row['location_id'] !== null ? (int) $row['location_id'] : null,
			];
		}
		return $out;
	}

	/**
	 * Soft ranking score only (never used to skip). Higher = process first.
	 *
	 * @param array<int, list<array{band:string,weekday_mask:int,priority:int,location_id:?int}>> $prefsByEmp
	 */
	private function preferenceScore(int $employeeId, array $prefsByEmp, string $earlyEnd, string $lateStart): int
	{
		$prefs = $prefsByEmp[$employeeId] ?? [];
		if ($prefs === []) {
			return 0;
		}
		$score = 0;
		foreach ($prefs as $p) {
			$score += 10 + max(0, (int) $p['priority']);
			$band = $p['band'];
			if ($band === 'early' || $band === 'late' || $band === 'mid') {
				$score += 5;
			}
		}
		// early/late thresholds kept for future cell-level ranking; employee-level sort is enough for GA.
		unset($earlyEnd, $lateStart);
		return $score;
	}

	private function periodCompanyId(int $periodId, string $actor): int
	{
		if (SchemaProbe::hasColumn($this->db, 'dc_periods', 'company_id')) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('company_id')->from('dc_periods')
				->where($qb->expr()->eq('id', $qb->createNamedParameter($periodId, IQueryBuilder::PARAM_INT)));
			$raw = $qb->executeQuery()->fetchOne();
			if ($raw !== false && $raw !== null) {
				return (int) $raw;
			}
		}
		return $this->companies->writeCompanyIdFor($actor);
	}

	/**
	 * @param array<string,mixed> $payload
	 */
	private function writeAudit(int $periodId, string $actor, string $action, array $payload): void
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
				'target_kind' => $qb->createNamedParameter('period'),
				'target_id' => $qb->createNamedParameter($periodId, IQueryBuilder::PARAM_INT),
				'payload_json' => $qb->createNamedParameter(json_encode($payload, JSON_THROW_ON_ERROR)),
				'created_at' => $qb->createNamedParameter(
					(new DateTimeImmutable('now'))->format('Y-m-d H:i:s'),
				),
			])->executeStatement();
		} catch (Throwable) {
			// Non-fatal.
		}
	}
}
