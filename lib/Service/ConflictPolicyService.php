<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Configurable conflict thresholds (ArbZG-oriented defaults). Singleton row in dc_conflict_policy.
 */
class ConflictPolicyService
{
	public function __construct(
		private readonly IDBConnection $db,
	) {
	}

	/**
	 * @return array{maxDailyHard:int,maxPeriodSoft:int,maxPeriodHard:int,maxConsecutiveDays:int,minRestMinutes:int}
	 */
	public static function defaults(): array
	{
		return [
			'maxDailyHard' => 600,
			'maxPeriodSoft' => 2880,
			'maxPeriodHard' => 3600,
			'maxConsecutiveDays' => 6,
			'minRestMinutes' => 660,
		];
	}

	/**
	 * @return array{maxDailyHard:int,maxPeriodSoft:int,maxPeriodHard:int,maxConsecutiveDays:int,minRestMinutes:int,updatedAt:?string,updatedBy:?string}
	 */
	public function get(): array
	{
		$thresholds = $this->thresholds();
		$row = $this->fetchRow();
		return $thresholds + [
			'updatedAt' => $row['updated_at'] ?? null,
			'updatedBy' => $row['updated_by'] ?? null,
		];
	}

	/**
	 * @return array{maxDailyHard:int,maxPeriodSoft:int,maxPeriodHard:int,maxConsecutiveDays:int,minRestMinutes:int}
	 */
	public function thresholds(): array
	{
		$row = $this->fetchRow();
		if ($row === null) {
			return self::defaults();
		}
		return [
			'maxDailyHard' => (int) $row['max_daily_hard'],
			'maxPeriodSoft' => (int) $row['max_period_soft'],
			'maxPeriodHard' => (int) $row['max_period_hard'],
			'maxConsecutiveDays' => (int) $row['max_consec_days'],
			'minRestMinutes' => (int) $row['min_rest_minutes'],
		];
	}

	/**
	 * @param array<string,mixed> $payload
	 * @return array{maxDailyHard:int,maxPeriodSoft:int,maxPeriodHard:int,maxConsecutiveDays:int,minRestMinutes:int,updatedAt:?string,updatedBy:?string}
	 */
	public function save(array $payload, string $actor): array
	{
		$defaults = self::defaults();
		$maxDailyHard = $this->clampInt($payload['maxDailyHard'] ?? $defaults['maxDailyHard'], 60, 24 * 60);
		$maxPeriodSoft = $this->clampInt($payload['maxPeriodSoft'] ?? $defaults['maxPeriodSoft'], 60, 14 * 24 * 60);
		$maxPeriodHard = $this->clampInt($payload['maxPeriodHard'] ?? $defaults['maxPeriodHard'], 60, 21 * 24 * 60);
		$maxConsecutiveDays = $this->clampInt($payload['maxConsecutiveDays'] ?? $defaults['maxConsecutiveDays'], 1, 31);
		$minRestMinutes = $this->clampInt($payload['minRestMinutes'] ?? $defaults['minRestMinutes'], 0, 24 * 60);
		if ($maxPeriodHard < $maxPeriodSoft) {
			throw new \InvalidArgumentException('INVALID_CONFLICT_POLICY');
		}

		$now = (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s');
		$row = $this->fetchRow();
		if ($row === null) {
			$qb = $this->db->getQueryBuilder();
			$qb->insert('dc_conflict_policy')->values([
				'max_daily_hard' => $qb->createNamedParameter($maxDailyHard, IQueryBuilder::PARAM_INT),
				'max_period_soft' => $qb->createNamedParameter($maxPeriodSoft, IQueryBuilder::PARAM_INT),
				'max_period_hard' => $qb->createNamedParameter($maxPeriodHard, IQueryBuilder::PARAM_INT),
				'max_consec_days' => $qb->createNamedParameter($maxConsecutiveDays, IQueryBuilder::PARAM_INT),
				'min_rest_minutes' => $qb->createNamedParameter($minRestMinutes, IQueryBuilder::PARAM_INT),
				'updated_at' => $qb->createNamedParameter($now),
				'updated_by' => $qb->createNamedParameter($actor),
			])->executeStatement();
		} else {
			$qb = $this->db->getQueryBuilder();
			$qb->update('dc_conflict_policy')
				->set('max_daily_hard', $qb->createNamedParameter($maxDailyHard, IQueryBuilder::PARAM_INT))
				->set('max_period_soft', $qb->createNamedParameter($maxPeriodSoft, IQueryBuilder::PARAM_INT))
				->set('max_period_hard', $qb->createNamedParameter($maxPeriodHard, IQueryBuilder::PARAM_INT))
				->set('max_consec_days', $qb->createNamedParameter($maxConsecutiveDays, IQueryBuilder::PARAM_INT))
				->set('min_rest_minutes', $qb->createNamedParameter($minRestMinutes, IQueryBuilder::PARAM_INT))
				->set('updated_at', $qb->createNamedParameter($now))
				->set('updated_by', $qb->createNamedParameter($actor))
				->where($qb->expr()->eq('id', $qb->createNamedParameter((int) $row['id'], IQueryBuilder::PARAM_INT)))
				->executeStatement();
		}

		return $this->get();
	}

	/**
	 * @return array<string,mixed>|null
	 */
	private function fetchRow(): ?array
	{
		if (!$this->db->tableExists('dc_conflict_policy')) {
			return null;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('dc_conflict_policy')->orderBy('id', 'ASC')->setMaxResults(1);
		$row = $qb->executeQuery()->fetch();
		return $row === false ? null : $row;
	}

	private function clampInt(mixed $value, int $min, int $max): int
	{
		$n = (int) $value;
		return max($min, min($max, $n));
	}
}
