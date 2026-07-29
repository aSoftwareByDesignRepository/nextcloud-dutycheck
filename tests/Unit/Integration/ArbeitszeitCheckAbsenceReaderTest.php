<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\Integration;

use OCA\DutyCheck\Integration\AbsenceReadOptions;
use OCA\DutyCheck\Integration\ArbeitszeitCheckAbsenceReader;
use OCA\DutyCheck\Tests\Unit\Support\IntegrationQueryBuilderTrait;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class ArbeitszeitCheckAbsenceReaderTest extends TestCase
{
	use IntegrationQueryBuilderTrait;

	public function testEmptyUserIdsReturnsEmptyWithoutQuery(): void
	{
		$db = $this->createMock(IDBConnection::class);
		$db->expects(self::never())->method('getQueryBuilder');
		$reader = new ArbeitszeitCheckAbsenceReader($db, $this->createMock(LoggerInterface::class));
		self::assertSame([], $reader->listAbsencesOverlapping([], '2026-01-01', '2026-12-31'));
	}

	public function testNormalizesValidRowAndSkipsInvalidDates(): void
	{
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($this->qbFetchAllAssociative([
			[
				'id' => 10,
				'user_id' => 'alice',
				'type' => 'vacation',
				'start_date' => '2026-07-01',
				'end_date' => '2026-07-05',
				'days' => '5.00',
				'status' => 'approved',
				'created_at' => '2026-06-01 10:00:00',
				'updated_at' => '2026-06-02 11:00:00',
			],
			[
				'id' => 11,
				'user_id' => 'alice',
				'type' => 'sick_leave',
				'start_date' => '2026-08-05',
				'end_date' => '2026-08-01', // invalid range
				'days' => null,
				'status' => 'approved',
				'created_at' => '2026-06-01 10:00:00',
				'updated_at' => '2026-06-02 11:00:00',
			],
		]));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::atLeastOnce())->method('info');
		$reader = new ArbeitszeitCheckAbsenceReader($db, $logger);
		$rows = $reader->listAbsencesOverlapping(['alice'], '2026-01-01', '2026-12-31');
		self::assertCount(1, $rows);
		self::assertSame(10, $rows[0]['atAbsenceId']);
		self::assertSame('alice', $rows[0]['userId']);
		self::assertArrayNotHasKey('reason', $rows[0]);
	}

	public function testIncludePiiAddsReasonFields(): void
	{
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($this->qbFetchAllAssociative([
			[
				'id' => 20,
				'user_id' => 'bob',
				'type' => 'vacation',
				'start_date' => '2026-09-01',
				'end_date' => '2026-09-02',
				'days' => 2,
				'status' => 'approved',
				'created_at' => '2026-06-01 10:00:00',
				'updated_at' => '2026-06-02 11:00:00',
				'reason' => 'family',
				'approver_comment' => 'ok',
			],
		]));
		$reader = new ArbeitszeitCheckAbsenceReader($db, $this->createMock(LoggerInterface::class));
		$rows = $reader->listAbsencesOverlapping(
			['bob'],
			'2026-01-01',
			'2026-12-31',
			new AbsenceReadOptions(true),
		);
		self::assertCount(1, $rows);
		self::assertSame('family', $rows[0]['reason']);
		self::assertSame('ok', $rows[0]['approverComment']);
	}
}
