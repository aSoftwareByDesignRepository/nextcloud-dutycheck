<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Service;

use OCP\IL10N;

/**
 * Builds UTF-8 CSV (comma-separated, CRLF) with BOM for spreadsheet tools.
 * Escaping follows RFC 4180.
 */
class RosterCsvFormatter
{
	public function __construct(
		private IL10N $l10n,
	) {
	}

	/**
	 * @param array<string, mixed> $period from {@see RosterService::rosterExportBundle}
	 * @param list<array<string, mixed>> $assignments
	 */
	public function buildDutyRosterCsv(
		array $period,
		array $assignments,
		string $exportedByUserId,
		string $exportedAtUtc,
	): string {
		$headers = [
			$this->l10n->t('Period ID'),
			$this->l10n->t('Period start date'),
			$this->l10n->t('Period end date'),
			$this->l10n->t('Period status'),
			$this->l10n->t('Assignment ID'),
			$this->l10n->t('Duty date'),
			$this->l10n->t('Start time'),
			$this->l10n->t('End time'),
			$this->l10n->t('Break minutes'),
			$this->l10n->t('Employee ID'),
			$this->l10n->t('Employee name'),
			$this->l10n->t('Location ID'),
			$this->l10n->t('Location name'),
			$this->l10n->t('Note'),
			$this->l10n->t('Exported at (UTC)'),
			$this->l10n->t('Exported by'),
		];

		$lines = [$this->csvRecord($headers)];
		$pid = (int) ($period['id'] ?? 0);
		$pStart = (string) ($period['startDate'] ?? '');
		$pEnd = (string) ($period['endDate'] ?? '');
		$pStatus = (string) ($period['status'] ?? '');

		foreach ($assignments as $row) {
			$lines[] = $this->csvRecord([
				(string) $pid,
				$pStart,
				$pEnd,
				$pStatus,
				(string) ((int) ($row['id'] ?? 0)),
				(string) ($row['dutyDate'] ?? ''),
				(string) ($row['startTime'] ?? ''),
				(string) ($row['endTime'] ?? ''),
				(string) ((int) ($row['breakMinutes'] ?? 0)),
				(string) ((int) ($row['employeeId'] ?? 0)),
				(string) ($row['employeeName'] ?? ''),
				(string) ((int) ($row['locationId'] ?? 0)),
				(string) ($row['locationName'] ?? ''),
				(string) ($row['note'] ?? ''),
				$exportedAtUtc,
				$exportedByUserId,
			]);
		}

		// If there are no assignments, emit a single header row only (still valid CSV).
		return "\xEF\xBB\xBF" . implode("\r\n", $lines) . "\r\n";
	}

	/**
	 * @param list<string> $fields
	 */
	private function csvRecord(array $fields): string
	{
		$escaped = array_map(fn (string $v): string => $this->escapeField($v), $fields);
		return implode(',', $escaped);
	}

	private function escapeField(string $value): string
	{
		if (str_contains($value, '"')) {
			$value = str_replace('"', '""', $value);
		}
		if (preg_match('/[\r\n",]/', $value) === 1) {
			return '"' . $value . '"';
		}
		return $value;
	}
}
