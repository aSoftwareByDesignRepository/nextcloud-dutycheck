<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Integration;

use OCA\DutyCheck\Exception\UpgradeBackupException;
use OCA\DutyCheck\Service\UpgradeBackupService;
use OCP\IDBConnection;
use Test\TestCase;

final class UpgradeBackupIntegrationTest extends TestCase
{
	private UpgradeBackupService $backupService;
	private IDBConnection $db;
	private ?string $createdSnapshotId = null;

	protected function setUp(): void
	{
		parent::setUp();
		$this->backupService = \OC::$server->get(UpgradeBackupService::class);
		$this->db = \OC::$server->get(IDBConnection::class);
	}

	protected function tearDown(): void
	{
		// Failsafe: if the test died between the destructive delete and the
		// restore, put the employees back before anything else runs.
		if ($this->createdSnapshotId !== null
			&& $this->db->tableExists('dc_employees')
			&& $this->countRows('dc_employees') === 0) {
			try {
				$this->backupService->restoreSnapshot($this->createdSnapshotId, false);
			} catch (\Throwable) {
				// best-effort — the snapshot dir is still on disk either way
			}
		}
		if ($this->createdSnapshotId !== null) {
			try {
				$this->backupService->deleteSnapshot($this->createdSnapshotId);
			} catch (\Throwable) {
			}
			$this->createdSnapshotId = null;
		}
		parent::tearDown();
	}

	public function testCreateListAndRestoreRoundTrip(): void
	{
		if (!$this->db->tableExists('dc_employees')) {
			self::markTestSkipped('DutyCheck tables not present in this instance.');
		}

		$before = $this->countRows('dc_employees');

		$result = $this->backupService->createSnapshot('integration-test');
		$snapshotId = $result['id'];
		$this->createdSnapshotId = $snapshotId;
		self::assertNotSame('', $snapshotId);
		self::assertTrue($result['manifest']['complete'] ?? false);
		self::assertNotEmpty($result['manifest']['tables'] ?? [], 'Snapshot must include table metadata when tables exist.');

		$snapshots = $this->backupService->listSnapshots();
		$ids = array_map(static fn (array $snapshot): string => (string)($snapshot['id'] ?? ''), $snapshots);
		self::assertContains($snapshotId, $ids, 'listSnapshots must find the snapshot just created');

		$this->db->getQueryBuilder()
			->delete('dc_employees')
			->executeStatement();
		self::assertSame(0, $this->countRows('dc_employees'));

		$this->backupService->restoreSnapshot($snapshotId, false);
		self::assertSame($before, $this->countRows('dc_employees'));

		// The test snapshot is evidence-of-run only — delete it so repeated runs
		// do not accumulate upgrade-backups/ folders in appdata.
		$this->backupService->deleteSnapshot($snapshotId);
		$this->createdSnapshotId = null;
		$ids = array_map(static fn (array $snapshot): string => (string)($snapshot['id'] ?? ''), $this->backupService->listSnapshots());
		self::assertNotContains($snapshotId, $ids, 'deleteSnapshot must remove the just-created snapshot');
	}

	public function testRestoreRejectsInvalidSnapshotId(): void
	{
		$this->expectException(UpgradeBackupException::class);
		$this->backupService->restoreSnapshot('../evil', false);
	}

	private function countRows(string $table): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'cnt'))
			->from($table);
		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();

		return $count;
	}
}
