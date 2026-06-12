<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Repair;

use OC\DB\Connection;
use OC\DB\MigrationService;
use OCA\DutyCheck\Migration\DutyCheckTableCatalog;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Server;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Safety net when migrations were marked complete without creating every table
 * (partial install, restored backup, or manual DB edits).
 *
 * Runs on fresh install and on every upgrade (post-migration) so schema is verified
 * even when post-migration repair did not run on first enable.
 */
final class EnsureDutyCheckSchema implements IRepairStep
{
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
		if ($missingBefore === []) {
			$output->info('DutyCheck: all ' . count(DutyCheckTableCatalog::TABLES) . ' tables are present.');
			return;
		}

		$output->info(sprintf(
			'DutyCheck: %d table(s) missing (%s); running pending migrations.',
			count($missingBefore),
			implode(', ', $missingBefore),
		));

		$migrationService = new MigrationService(
			DutyCheckTableCatalog::APP_ID,
			Server::get(Connection::class),
		);
		$migrationService->migrate('latest', false);

		$missingAfter = $this->missingTables();
		if ($missingAfter === []) {
			$output->info('DutyCheck: schema repair completed; all tables are now present.');
			return;
		}

		throw new \RuntimeException(sprintf(
			'DutyCheck schema is still incomplete after migrate("latest"). Missing: %s. '
			. 'Run `php occ upgrade` or re-enable the app and check nextcloud.log.',
			implode(', ', $missingAfter),
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
}
