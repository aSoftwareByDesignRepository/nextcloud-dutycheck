<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Integration;

use OCA\DutyCheck\Integration\ArbeitszeitCheckMirrorDeleteHelper;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Exercises mirror purge algorithms against SQLite with >1000 rows / binds.
 * Uses the same {@see ArbeitszeitCheckMirrorDeleteHelper} as production plus equivalent DELETE SQL.
 */
final class MirrorDeleteSqliteTest extends TestCase
{
	private function openMemorySqlite(): PDO
	{
		if (!extension_loaded('pdo_sqlite')) {
			self::markTestSkipped('pdo_sqlite extension required for SQLite integration');
		}
		$pdo = new PDO('sqlite::memory:');
		$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		$pdo->exec(
			'CREATE TABLE dc_at_absence_mirror (
				id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
				linked_user_id TEXT NOT NULL,
				at_absence_id INTEGER NOT NULL,
				start_date TEXT NOT NULL,
				end_date TEXT NOT NULL,
				type TEXT NOT NULL,
				status TEXT NOT NULL,
				payload_hash TEXT NOT NULL,
				last_seen_at TEXT NOT NULL,
				source_updated_at TEXT
			)',
		);
		return $pdo;
	}

	private function insertMirrorRow(PDO $pdo, string $linkedUserId, int $atAbsenceId): void
	{
		$st = $pdo->prepare(
			'INSERT INTO dc_at_absence_mirror (
				linked_user_id, at_absence_id, start_date, end_date, type, status, payload_hash, last_seen_at
			) VALUES (?,?,?,?,?,?,?,?)',
		);
		$st->execute([
			$linkedUserId,
			$atAbsenceId,
			'2026-01-01',
			'2026-01-02',
			't',
			's',
			hash('sha256', (string) $atAbsenceId),
			'2026-01-01 00:00:00',
		]);
	}

	/**
	 * Mirrors {@see \OCA\DutyCheck\Integration\ArbeitszeitCheckIntegrationService::deleteMirrorOrphansNotLinked()}
	 * when the active-linked list is non-empty (orphan purge by distinct mirror UID).
	 *
	 * @param list<string> $validLinkedUserIds
	 */
	private function purgeOrphansNotInValidSet(PDO $pdo, array $validLinkedUserIds): void
	{
		$validSet = array_flip($validLinkedUserIds);
		$seen = [];
		$distinct = [];
		foreach ($pdo->query('SELECT linked_user_id FROM dc_at_absence_mirror', PDO::FETCH_ASSOC) as $row) {
			$uid = (string) ($row['linked_user_id'] ?? '');
			if ($uid === '' || isset($seen[$uid])) {
				continue;
			}
			$seen[$uid] = true;
			$distinct[] = $uid;
		}
		foreach (ArbeitszeitCheckMirrorDeleteHelper::orphanLinkedUserIds($distinct, $validLinkedUserIds) as $orphan) {
			$del = $pdo->prepare('DELETE FROM dc_at_absence_mirror WHERE linked_user_id = ?');
			$del->execute([$orphan]);
		}
	}

	/**
	 * Mirrors {@see \OCA\DutyCheck\Integration\ArbeitszeitCheckIntegrationService::deleteMirrorNotIn()}
	 * using chunked IN deletes.
	 *
	 * @param list<int> $keepAtAbsenceIds
	 */
	private function purgeMirrorNotInKeepList(PDO $pdo, string $linkedUserId, array $keepAtAbsenceIds): void
	{
		$sel = $pdo->prepare('SELECT at_absence_id FROM dc_at_absence_mirror WHERE linked_user_id = ?');
		$sel->execute([$linkedUserId]);
		$present = [];
		while (($row = $sel->fetch(PDO::FETCH_ASSOC)) !== false) {
			$n = (int) ($row['at_absence_id'] ?? 0);
			if ($n >= 1) {
				$present[] = $n;
			}
		}
		$toDelete = ArbeitszeitCheckMirrorDeleteHelper::atAbsenceIdsToDelete($present, $keepAtAbsenceIds);
		foreach (array_chunk($toDelete, ArbeitszeitCheckMirrorDeleteHelper::IN_CHUNK) as $chunk) {
			if ($chunk === []) {
				continue;
			}
			$placeholders = implode(',', array_fill(0, count($chunk), '?'));
			$sql = 'DELETE FROM dc_at_absence_mirror WHERE linked_user_id = ? AND at_absence_id IN (' . $placeholders . ')';
			$params = array_merge([$linkedUserId], $chunk);
			$pdo->prepare($sql)->execute($params);
		}
	}

	public function testOrphanPurgeWithManyValidLinkedUsers(): void
	{
		$pdo = $this->openMemorySqlite();
		$valid = [];
		for ($i = 0; $i < 1200; $i++) {
			$valid[] = 'u' . $i;
		}
		foreach ($valid as $uid) {
			$this->insertMirrorRow($pdo, $uid, 100000 + crc32($uid));
		}
		$this->insertMirrorRow($pdo, 'orphan_should_go', 999001);
		$this->insertMirrorRow($pdo, 'orphan_should_go2', 999002);

		$this->purgeOrphansNotInValidSet($pdo, $valid);

		$count = (int) $pdo->query('SELECT COUNT(*) FROM dc_at_absence_mirror')->fetchColumn();
		self::assertSame(1200, $count);
		$orph = $pdo->query("SELECT COUNT(*) FROM dc_at_absence_mirror WHERE linked_user_id LIKE 'orphan%'")->fetchColumn();
		self::assertSame(0, (int) $orph);
	}

	public function testNotInPurgeUsesBoundedInChunksOverThousandRows(): void
	{
		$pdo = $this->openMemorySqlite();
		$uid = 'employee-one';
		$keep = [];
		for ($at = 1; $at <= 50; $at++) {
			$keep[] = $at;
		}
		for ($at = 1; $at <= 1300; $at++) {
			$this->insertMirrorRow($pdo, $uid, $at);
		}

		$this->purgeMirrorNotInKeepList($pdo, $uid, $keep);

		$remaining = (int) $pdo->query('SELECT COUNT(*) FROM dc_at_absence_mirror WHERE linked_user_id = ' . $pdo->quote($uid))->fetchColumn();
		self::assertSame(50, $remaining);
		$minId = (int) $pdo->query('SELECT MIN(at_absence_id) FROM dc_at_absence_mirror WHERE linked_user_id = ' . $pdo->quote($uid))->fetchColumn();
		$maxId = (int) $pdo->query('SELECT MAX(at_absence_id) FROM dc_at_absence_mirror WHERE linked_user_id = ' . $pdo->quote($uid))->fetchColumn();
		self::assertSame(1, $minId);
		self::assertSame(50, $maxId);
	}
}
