<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\Service;

use OCA\DutyCheck\Db\SchemaProbe;
use OCA\DutyCheck\Service\RosterService;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Acknowledge only on published/closed periods; idempotent when already acknowledged.
 */
final class AcknowledgePeriodGateTest extends TestCase
{
	protected function setUp(): void
	{
		SchemaProbe::resetCache();
		$ref = new ReflectionClass(SchemaProbe::class);
		$prop = $ref->getProperty('columnCache');
		$prop->setAccessible(true);
		$prop->setValue(null, ['dc_assignments.status' => true]);
	}

	protected function tearDown(): void
	{
		SchemaProbe::resetCache();
	}

	private function expr(): object
	{
		return new class {
			public function eq(...$a)
			{
				return 'eq';
			}
			public function isNull(...$a)
			{
				return 'null';
			}
		};
	}

	private function qb(IResult $result): IQueryBuilder
	{
		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('select')->willReturnSelf();
		$qb->method('from')->willReturnSelf();
		$qb->method('where')->willReturnSelf();
		$qb->method('andWhere')->willReturnSelf();
		$qb->method('update')->willReturnSelf();
		$qb->method('set')->willReturnSelf();
		$qb->method('expr')->willReturn($this->expr());
		$qb->method('createNamedParameter')->willReturn('p');
		$qb->method('setMaxResults')->willReturnSelf();
		$qb->method('executeQuery')->willReturn($result);
		$qb->method('executeStatement')->willReturn(1);
		return $qb;
	}

	public function testOpenPeriodRejectsAcknowledge(): void
	{
		$asgResult = $this->createMock(IResult::class);
		$asgResult->method('fetch')->willReturn([
			'id' => 42,
			'employee_id' => 7,
			'period_id' => 1,
			'status' => 'active',
			'acknowledged_at' => null,
			'acknowledged_by' => null,
		]);
		$empResult = $this->createMock(IResult::class);
		$empResult->method('fetchOne')->willReturn(7);
		$periodResult = $this->createMock(IResult::class);
		$periodResult->method('fetch')->willReturn([
			'id' => 1,
			'start_date' => '2099-01-01',
			'end_date' => '2099-01-07',
			'status' => 'open',
			'created_by' => 'p',
			'created_at' => '2099-01-01 00:00:00',
			'published_at' => null,
			'closed_at' => null,
			'close_snapshot_id' => null,
		]);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnOnConsecutiveCalls(
			$this->qb($asgResult),
			$this->qb($empResult),
			$this->qb($periodResult),
		);

		$svc = new RosterService($db);
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('PERIOD_NOT_PUBLISHED');
		$svc->acknowledgeAssignment(42, 'alice');
	}

	public function testIdempotentWhenAlreadyAcknowledged(): void
	{
		$asgResult = $this->createMock(IResult::class);
		$asgResult->method('fetch')->willReturn([
			'id' => 42,
			'employee_id' => 7,
			'period_id' => 1,
			'status' => 'active',
			'acknowledged_at' => '2099-01-02 10:00:00',
			'acknowledged_by' => 'alice',
		]);
		$empResult = $this->createMock(IResult::class);
		$empResult->method('fetchOne')->willReturn(7);
		$periodResult = $this->createMock(IResult::class);
		$periodResult->method('fetch')->willReturn([
			'id' => 1,
			'start_date' => '2099-01-01',
			'end_date' => '2099-01-07',
			'status' => 'published',
			'created_by' => 'p',
			'created_at' => '2099-01-01 00:00:00',
			'published_at' => '2099-01-01 00:00:00',
			'closed_at' => null,
			'close_snapshot_id' => null,
		]);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnOnConsecutiveCalls(
			$this->qb($asgResult),
			$this->qb($empResult),
			$this->qb($periodResult),
		);

		$svc = new RosterService($db);
		$out = $svc->acknowledgeAssignment(42, 'alice');
		self::assertSame(42, $out['assignmentId']);
		self::assertSame('2099-01-02 10:00:00', $out['acknowledgedAt']);
		self::assertSame('alice', $out['acknowledgedBy']);
	}
}
