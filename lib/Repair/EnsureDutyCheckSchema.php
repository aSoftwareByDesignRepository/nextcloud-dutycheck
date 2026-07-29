<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Repair;

use OC\DB\Connection;
use OC\DB\MigrationService;
use OCA\DutyCheck\Db\SchemaProbe;
use OCA\DutyCheck\Migration\DutyCheckTableCatalog;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Server;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Safety net when migrations were marked complete without creating every table
 * or critical integrity columns (partial install, restored backup, or manual DB edits).
 *
 * Runs on fresh install and on every upgrade (post-migration) so schema is verified
 * even when post-migration repair did not run on first enable.
 */
final class EnsureDutyCheckSchema implements IRepairStep
{
	/**
	 * Columns required for CAS / soft-cancel recreate / frozen thresholds.
	 *
	 * @var array<string, list<string>>
	 */
	private const CRITICAL_COLUMNS = [
		'dc_assignments' => ['status', 'version', 'slot_key'],
		'dc_periods' => ['conflict_thresholds_json'],
		'dc_shift_templates' => ['min_headcount'],
	];

	/**
	 * Indexes required after Version1016 drops the status-blind slot unique.
	 *
	 * @var array<string, list<string>>
	 */
	private const CRITICAL_INDEXES = [
		'dc_assignments' => ['dc_asg_skey_uidx'],
	];

	public function __construct(
		private readonly IDBConnection $connection,
		private readonly IConfig $config,
	) {
	}

	public function getName(): string
	{
		return 'Ensure DutyCheck database schema is complete';
	}

	public function run(IOutput $output): void
	{
		$this->config->deleteAppValue(UninstallDropTables::APP_ID, UninstallDropTables::REPAIR_PASS_KEY);

		$missingBefore = $this->missingTables();
		$missingColumnsBefore = $this->missingCriticalColumns();
		$missingIndexesBefore = $this->missingCriticalIndexes();
		if ($missingBefore === [] && $missingColumnsBefore === [] && $missingIndexesBefore === []) {
			$output->info('DutyCheck: all ' . count(DutyCheckTableCatalog::TABLES) . ' tables, critical columns, and indexes are present.');
			return;
		}

		if ($missingBefore !== []) {
			$output->info(sprintf(
				'DutyCheck: %d table(s) missing (%s); running pending migrations.',
				count($missingBefore),
				implode(', ', $missingBefore),
			));
		}
		if ($missingColumnsBefore !== []) {
			$output->info(sprintf(
				'DutyCheck: critical column(s) missing (%s); running pending migrations.',
				implode(', ', $missingColumnsBefore),
			));
		}
		if ($missingIndexesBefore !== []) {
			$output->info(sprintf(
				'DutyCheck: critical index(es) missing (%s); running pending migrations.',
				implode(', ', $missingIndexesBefore),
			));
		}

		$migrationService = new MigrationService(
			DutyCheckTableCatalog::APP_ID,
			Server::get(Connection::class),
		);
		$migrationService->migrate('latest', false);
		SchemaProbe::resetCache();

		$missingAfter = $this->missingTables();
		$missingColumnsAfter = $this->missingCriticalColumns();
		$missingIndexesAfter = $this->missingCriticalIndexes();
		if ($missingAfter === [] && $missingColumnsAfter === [] && $missingIndexesAfter === []) {
			$output->info('DutyCheck: schema repair completed; all tables, critical columns, and indexes are now present.');
			return;
		}

		$parts = [];
		if ($missingAfter !== []) {
			$parts[] = 'tables: ' . implode(', ', $missingAfter);
		}
		if ($missingColumnsAfter !== []) {
			$parts[] = 'columns: ' . implode(', ', $missingColumnsAfter);
		}
		if ($missingIndexesAfter !== []) {
			$parts[] = 'indexes: ' . implode(', ', $missingIndexesAfter);
		}
		throw new \RuntimeException(sprintf(
			'DutyCheck schema is still incomplete after migrate("latest"). Missing %s. '
			. 'Run `php occ upgrade` or re-enable the app and check nextcloud.log.',
			implode('; ', $parts),
		));
	}

	/**
	 * @return list<string>
	 */
	private function missingTables(): array
	{
		$missing = [];
		foreach (DutyCheckTableCatalog::TABLES as $table) {
			if (!$this->connection->tableExists($table)) {
				$missing[] = $table;
			}
		}
		return $missing;
	}

	/**
	 * @return list<string> table.column labels
	 */
	private function missingCriticalColumns(): array
	{
		$missing = [];
		foreach (self::CRITICAL_COLUMNS as $table => $columns) {
			if (!$this->connection->tableExists($table)) {
				continue;
			}
			foreach ($columns as $column) {
				if (!SchemaProbe::hasColumn($this->connection, $table, $column)) {
					$missing[] = $table . '.' . $column;
				}
			}
		}
		return $missing;
	}

	/**
	 * @return list<string> table#index labels
	 */
	private function missingCriticalIndexes(): array
	{
		$missing = [];
		foreach (self::CRITICAL_INDEXES as $table => $indexes) {
			if (!$this->connection->tableExists($table)) {
				continue;
			}
			foreach ($indexes as $index) {
				if (!SchemaProbe::hasIndex($this->connection, $table, $index)) {
					$missing[] = $table . '#' . $index;
				}
			}
		}
		return $missing;
	}
}
