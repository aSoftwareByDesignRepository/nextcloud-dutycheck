<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Service;

use OCA\DutyCheck\Db\SchemaProbe;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IConfig;
use OCP\IDBConnection;

/**
 * Wave C3 — opt-in roster-minutes export (no salary math).
 * DATEV/Personio-friendly CSV of planned duty minutes per employee/day.
 */
class RosterMinutesExportService
{
	public const CONFIG_ENABLED = 'hr_roster_minutes_export_enabled';

	public function __construct(
		private readonly IDBConnection $db,
		private readonly IConfig $config,
	) {
	}

	public function isEnabled(): bool
	{
		return $this->config->getAppValue('dutycheck', self::CONFIG_ENABLED, '0') === '1';
	}

	public function setEnabled(bool $enabled, string $actor): void
	{
		$this->config->setAppValue('dutycheck', self::CONFIG_ENABLED, $enabled ? '1' : '0');
		if (!$this->db->tableExists('dc_period_audit_log')) {
			return;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->insert('dc_period_audit_log')->values([
			'period_id' => $qb->createNamedParameter(null, IQueryBuilder::PARAM_INT),
			'actor_user_id' => $qb->createNamedParameter($actor),
			'action' => $qb->createNamedParameter('hr_export_flag'),
			'target_kind' => $qb->createNamedParameter('settings'),
			'target_id' => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
			'payload_json' => $qb->createNamedParameter(json_encode(['enabled' => $enabled], JSON_THROW_ON_ERROR)),
			'created_at' => $qb->createNamedParameter((new \DateTimeImmutable('now'))->format('Y-m-d H:i:s')),
		])->executeStatement();
	}

	/**
	 * @return array{headers:list<string>,rows:list<list<string>>}
	 */
	public function buildMinutesMatrix(int $periodId): array
	{
		if (!$this->isEnabled()) {
			throw new \InvalidArgumentException('HR_EXPORT_DISABLED');
		}
		$qb = $this->db->getQueryBuilder();
		$select = ['a.duty_date', 'a.start_time', 'a.end_time', 'a.break_minutes', 'e.display_name', 'e.linked_user_id', 'e.id AS employee_id'];
		if (SchemaProbe::hasColumn($this->db, 'dc_assignments', 'status')) {
			$select[] = 'a.status';
		}
		$qb->select(...$select)
			->from('dc_assignments', 'a')
			->innerJoin('a', 'dc_employees', 'e', 'a.employee_id = e.id')
			->where($qb->expr()->eq('a.period_id', $qb->createNamedParameter($periodId, IQueryBuilder::PARAM_INT)))
			->orderBy('e.display_name', 'ASC')
			->addOrderBy('a.duty_date', 'ASC')
			->addOrderBy('a.start_time', 'ASC');
		$rows = $qb->executeQuery()->fetchAll();
		$headers = ['linkedUserId', 'employeeName', 'employeeId', 'dutyDate', 'startTime', 'endTime', 'breakMinutes', 'effectiveMinutes'];
		$out = [];
		foreach ($rows as $row) {
			if (isset($row['status']) && (string) $row['status'] === 'cancelled') {
				continue;
			}
			$start = (string) $row['start_time'];
			$end = (string) $row['end_time'];
			$break = (int) $row['break_minutes'];
			$out[] = [
				(string) ($row['linked_user_id'] ?? ''),
				(string) ($row['display_name'] ?? ''),
				(string) (int) $row['employee_id'],
				(string) $row['duty_date'],
				substr($start, 0, 5),
				substr($end, 0, 5),
				(string) $break,
				(string) $this->effectiveMinutes($start, $end, $break),
			];
		}
		return ['headers' => $headers, 'rows' => $out];
	}

	public function toCsv(int $periodId): string
	{
		$matrix = $this->buildMinutesMatrix($periodId);
		$lines = [implode(';', $matrix['headers'])];
		foreach ($matrix['rows'] as $row) {
			$lines[] = implode(';', array_map(static function (string $cell): string {
				$escaped = str_replace('"', '""', $cell);
				return '"' . $escaped . '"';
			}, $row));
		}
		return implode("\n", $lines) . "\n";
	}

	private function effectiveMinutes(string $start, string $end, int $breakMinutes): int
	{
		$s = $this->toMinutes($start);
		$e = $this->toMinutes($end);
		if ($e <= $s) {
			$e += 24 * 60;
		}
		return max(0, $e - $s - max(0, $breakMinutes));
	}

	private function toMinutes(string $hhmm): int
	{
		$parts = explode(':', substr($hhmm, 0, 5));
		return ((int) ($parts[0] ?? 0)) * 60 + (int) ($parts[1] ?? 0);
	}
}
