<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\Service;

use OCA\DutyCheck\Db\SchemaProbe;
use OCA\DutyCheck\Exception\PeriodOverlapException;
use OCA\DutyCheck\Service\RosterService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Overlap guard for period creation + safe delete of open, empty periods.
 * An overlapping range must surface as PERIOD_OVERLAP (409) instead of
 * silently coexisting; deletion is only legal while the period is open and
 * has no assignments/shifts/conflicts/snapshots.
 */
final class RosterServicePeriodOverlapDeleteTest extends TestCase
{
	use RosterServiceMutationMockTrait;

	protected function setUp(): void
	{
		parent::setUp();
		SchemaProbe::resetCache();
	}

	protected function tearDown(): void
	{
		SchemaProbe::resetCache();
		parent::tearDown();
	}

	private function openPeriodRow(): array
	{
		return [
			'id' => '7',
			'start_date' => '2026-11-01',
			'end_date' => '2026-11-30',
			'status' => 'open',
			'created_by' => 'planner-1',
			'created_at' => '2026-10-01 00:00:00',
			'published_at' => null,
			'closed_at' => null,
			'close_snapshot_id' => null,
		];
	}

	public function testCreatePeriodRejectsOverlappingRange(): void
	{
		$qb = $this->rosterQb([
			'fetchOne' => false,
			'fetchAll' => [
				['id' => '3', 'start_date' => '2026-11-15', 'end_date' => '2026-12-15', 'status' => 'open'],
			],
			'fetch' => false,
		]);
		$service = new RosterService($this->rosterDbAlways($qb));

		try {
			$service->createPeriod('2026-11-01', '2026-11-30', 'planner-1');
			self::fail('expected PeriodOverlapException');
		} catch (PeriodOverlapException $e) {
			self::assertSame('PERIOD_OVERLAP', $e->getMessage());
			self::assertSame(
				[['id' => 3, 'startDate' => '2026-11-15', 'endDate' => '2026-12-15', 'status' => 'open']],
				$e->getOverlaps(),
			);
		}
	}

	public function testCreatePeriodAllowsAdjacentNonOverlappingRange(): void
	{
		$period = $this->openPeriodRow();
		$qb = $this->rosterQb([
			'fetchOne' => false,
			'fetchAll' => [],
			'fetch' => $period,
		]);
		$service = new RosterService($this->rosterDbAlways($qb));

		// Adjacent range (previous period ends the day before) must not throw.
		$created = $service->createPeriod('2026-11-01', '2026-11-30', 'planner-1');
		self::assertSame('open', $created['status']);
	}

	public function testCreatePeriodAllowOverlapBypassesGuard(): void
	{
		$period = $this->openPeriodRow();
		$qb = $this->rosterQb([
			'fetchOne' => false,
			'fetchAll' => [
				['id' => '3', 'start_date' => '2026-11-15', 'end_date' => '2026-12-15', 'status' => 'open'],
			],
			'fetch' => $period,
		]);
		$service = new RosterService($this->rosterDbAlways($qb));

		$created = $service->createPeriod('2026-11-01', '2026-11-30', 'planner-1', true);
		self::assertSame(7, $created['id']);
	}

	public function testCreatePeriodStillRejectsExactDuplicateWithAllowOverlap(): void
	{
		$qb = $this->rosterQb([
			'fetchOne' => '12', // duplicate range row found
			'fetchAll' => [],
			'fetch' => false,
		]);
		$service = new RosterService($this->rosterDbAlways($qb));

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('PERIOD_RANGE_EXISTS');
		$service->createPeriod('2026-11-01', '2026-11-30', 'planner-1', true);
	}

	public function testDeletePeriodRemovesOpenEmptyPeriod(): void
	{
		$qb = $this->rosterQb([
			'fetch' => $this->openPeriodRow(),
			'fetchOne' => 0,
			'statementReturn' => 1,
		]);
		$db = $this->rosterDbAlways($qb);
		$db->method('tableExists')->willReturn(true);
		$db->expects(self::once())->method('commit');
		$service = new RosterService($db);

		$deleted = $service->deletePeriod(7, 'planner-1');
		self::assertSame(7, $deleted['id']);
	}

	public function testDeletePeriodRejectsPublishedPeriod(): void
	{
		$period = $this->openPeriodRow();
		$period['status'] = 'published';
		$qb = $this->rosterQb(['fetch' => $period]);
		$service = new RosterService($this->rosterDbAlways($qb));

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('PERIOD_NOT_DELETABLE');
		$service->deletePeriod(7, 'planner-1');
	}

	public function testDeletePeriodRejectsPeriodWithContent(): void
	{
		$qb = $this->rosterQb([
			'fetch' => $this->openPeriodRow(),
			'fetchOne' => 2, // first content table reports rows
		]);
		$db = $this->rosterDbAlways($qb);
		$db->method('tableExists')->willReturn(true);
		$db->expects(self::once())->method('rollBack');
		$service = new RosterService($db);

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('PERIOD_NOT_DELETABLE');
		$service->deletePeriod(7, 'planner-1');
	}

	public function testDeletePeriodMissingRowIsUniformNotFound(): void
	{
		$qb = $this->rosterQb(['fetch' => false]);
		$service = new RosterService($this->rosterDbAlways($qb));

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('PERIOD_NOT_FOUND');
		$service->deletePeriod(999, 'planner-1');
	}
}
