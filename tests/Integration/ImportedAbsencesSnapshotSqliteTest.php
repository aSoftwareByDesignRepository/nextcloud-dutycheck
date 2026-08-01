<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;

/**
 * WF-23 overlap selection contract against SQLite (same SQL shape as production snapshot query).
 */
final class ImportedAbsencesSnapshotSqliteTest extends TestCase
{
	public function testOverlapWindowAndNoT3Columns(): void
	{
		if (!extension_loaded('pdo_sqlite')) {
			self::markTestSkipped('pdo_sqlite extension required');
		}
		$pdo = new PDO('sqlite::memory:');
		$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		$pdo->exec(
			'CREATE TABLE dc_employees (
				id INTEGER PRIMARY KEY,
				linked_user_id TEXT,
				active INTEGER NOT NULL
			)'
		);
		$pdo->exec(
			'CREATE TABLE dc_at_absence_mirror (
				id INTEGER PRIMARY KEY AUTOINCREMENT,
				linked_user_id TEXT NOT NULL,
				at_absence_id INTEGER NOT NULL,
				start_date TEXT NOT NULL,
				end_date TEXT NOT NULL,
				type TEXT NOT NULL,
				status TEXT NOT NULL,
				reason TEXT,
				approver_comment TEXT
			)'
		);
		$pdo->exec("INSERT INTO dc_employees (id, linked_user_id, active) VALUES (1, 'alice', 1)");
		$pdo->exec("INSERT INTO dc_at_absence_mirror (linked_user_id, at_absence_id, start_date, end_date, type, status, reason, approver_comment)
			VALUES ('alice', 10, '2026-07-01', '2026-07-05', 'vacation', 'approved', 'SECRET', 'SECRET2')");
		$pdo->exec("INSERT INTO dc_at_absence_mirror (linked_user_id, at_absence_id, start_date, end_date, type, status, reason, approver_comment)
			VALUES ('alice', 11, '2026-08-01', '2026-08-02', 'vacation', 'approved', 'SECRET', 'SECRET2')");

		$stmt = $pdo->prepare(
			'SELECT m.at_absence_id, m.start_date, m.end_date, m.type, m.status, e.id AS employee_id
			 FROM dc_at_absence_mirror m
			 INNER JOIN dc_employees e ON m.linked_user_id = e.linked_user_id
			 WHERE e.active = 1
			   AND m.start_date <= :to
			   AND m.end_date >= :from
			 ORDER BY m.start_date ASC, m.at_absence_id ASC'
		);
		$stmt->execute([':from' => '2026-07-01', ':to' => '2026-07-31']);
		$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
		self::assertCount(1, $rows);
		self::assertSame(10, (int) $rows[0]['at_absence_id']);
		self::assertArrayNotHasKey('reason', $rows[0]);
		self::assertArrayNotHasKey('approver_comment', $rows[0]);
	}
}
