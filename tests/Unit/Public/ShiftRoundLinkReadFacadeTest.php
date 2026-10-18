<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\Public;

use OCA\DutyCheck\Db\SchemaProbe;
use OCA\DutyCheck\Public\ShiftRoundLinkReadFacade;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * F17 producer contract: bad ids, absent column, and a DB that throws
 * mid-probe all return [] — the sibling's notification path must never
 * receive an exception from this facade.
 */
class ShiftRoundLinkReadFacadeTest extends TestCase
{
	protected function setUp(): void
	{
		parent::setUp();
		SchemaProbe::resetCache();
	}

	public function testNonPositiveIdNeverTouchesDb(): void
	{
		$db = $this->createMock(IDBConnection::class);
		$db->expects($this->never())->method('tableExists');
		$db->expects($this->never())->method('getQueryBuilder');
		$f = new ShiftRoundLinkReadFacade($db);
		self::assertSame([], $f->shiftsForRound(0));
		self::assertSame([], $f->shiftsForRound(-4));
	}

	public function testMissingColumnReturnsEmpty(): void
	{
		// Mocked IDBConnection has no columnExists/getInner — the probe
		// falls back to a query, which fails on the null builder → false.
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willThrowException(new \RuntimeException('no schema'));
		self::assertSame([], (new ShiftRoundLinkReadFacade($db))->shiftsForRound(7));
	}

	public function testQueryFailureReturnsEmpty(): void
	{
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willThrowException(new \RuntimeException('db down'));
		self::assertSame([], (new ShiftRoundLinkReadFacade($db))->shiftsForRound(42));
	}
}
