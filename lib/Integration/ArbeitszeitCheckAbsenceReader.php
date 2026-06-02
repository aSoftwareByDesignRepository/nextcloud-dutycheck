<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Integration;

use DateTimeImmutable;
use DateTimeZone;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Read-only SELECT against `at_absences` (ArbeitszeitCheck). No OCA\* imports.
 */
final class ArbeitszeitCheckAbsenceReader implements IArbeitszeitCheckAbsenceReader
{
	public function __construct(
		private IDBConnection $db,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @param list<string> $userIds Nextcloud UIDs (batch)
	 * @return list<array<string,mixed>> normalized rows
	 */
	public function listAbsencesOverlapping(
		array $userIds,
		string $fromYmd,
		string $toYmd,
	): array {
		if ($userIds === []) {
			return [];
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select(
			'id',
			'user_id',
			'type',
			'start_date',
			'end_date',
			'days',
			'status',
			'created_at',
			'updated_at',
		)
			->from('at_absences')
			->where($qb->expr()->lte('start_date', $qb->createNamedParameter($toYmd)))
			->andWhere($qb->expr()->gte('end_date', $qb->createNamedParameter($fromYmd)));

		$or = $qb->expr()->orX();
		foreach ($userIds as $i => $uid) {
			$or->add($qb->expr()->eq('user_id', $qb->createNamedParameter($uid, IQueryBuilder::PARAM_STR, ':uid' . $i)));
		}
		$qb->andWhere($or);

		try {
			$rows = $qb->executeQuery()->fetchAll();
		} catch (\Throwable $e) {
			$this->logger->warning('DutyCheck AT reader query failed: ' . $e->getMessage(), [
				'app' => 'dutycheck',
				'code' => 'INTEGRATION_AT_READER_QUERY',
			]);
			throw $e;
		}

		$out = [];
		foreach ($rows as $r) {
			$row = $this->normalizeRow($r);
			if ($row !== null) {
				$out[] = $row;
			}
		}
		return $out;
	}

	/**
	 * @param array<string,mixed> $r
	 * @return ?array<string,mixed>
	 */
	private function normalizeRow(array $r): ?array
	{
		$id = isset($r['id']) ? (int) $r['id'] : 0;
		if ($id < 1) {
			return null;
		}
		$userId = isset($r['user_id']) ? (string) $r['user_id'] : '';
		if ($userId === '') {
			return null;
		}
		$type = isset($r['type']) ? (string) $r['type'] : '';
		$status = isset($r['status']) ? (string) $r['status'] : '';
		if ($type === '' || $status === '') {
			return null;
		}

		$start = $this->dateToYmd($r['start_date'] ?? null);
		$end = $this->dateToYmd($r['end_date'] ?? null);
		if ($start === null || $end === null || $start > $end) {
			$this->logger->info('DutyCheck skipped invalid AT absence row', [
				'app' => 'dutycheck',
				'code' => 'INTEGRATION_AT_ROW_INVALID',
				'at_absence_id' => $id,
			]);
			return null;
		}
		if ($start < '1970-01-01' || $end > '9999-12-31') {
			$this->logger->info('DutyCheck skipped AT absence row (date bounds)', [
				'app' => 'dutycheck',
				'code' => 'INTEGRATION_AT_ROW_INVALID',
				'at_absence_id' => $id,
			]);
			return null;
		}

		$days = $r['days'];
		$daysVal = $days === null || $days === '' ? null : (float) $days;

		$createdAt = $this->dateTimeToRfc3339Utc($r['created_at'] ?? null);
		$updatedAt = $this->dateTimeToRfc3339Utc($r['updated_at'] ?? null);
		if ($createdAt === null || $updatedAt === null) {
			$this->logger->info('DutyCheck skipped AT absence row (timestamps)', [
				'app' => 'dutycheck',
				'code' => 'INTEGRATION_AT_ROW_INVALID',
				'at_absence_id' => $id,
			]);
			return null;
		}

		return [
			'atAbsenceId' => $id,
			'userId' => $userId,
			'type' => $type,
			'startDate' => $start,
			'endDate' => $end,
			'days' => $daysVal,
			'status' => $status,
			'createdAt' => $createdAt,
			'updatedAt' => $updatedAt,
		];
	}

	private function dateToYmd(mixed $v): ?string
	{
		if ($v instanceof \DateTimeInterface) {
			return $v->format('Y-m-d');
		}
		if (is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}/', $v) === 1) {
			return substr($v, 0, 10);
		}
		return null;
	}

	private function dateTimeToRfc3339Utc(mixed $v): ?string
	{
		if ($v instanceof \DateTimeInterface) {
			$utc = DateTimeImmutable::createFromInterface($v)->setTimezone(new DateTimeZone('UTC'));
			return $utc->format('Y-m-d\TH:i:s\Z');
		}
		if (is_string($v) && trim($v) !== '') {
			try {
				$dt = new DateTimeImmutable($v);
				return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
			} catch (\Throwable) {
				return null;
			}
		}
		return null;
	}
}
