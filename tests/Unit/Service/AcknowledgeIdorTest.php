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
 * Acknowledge is self-only — IDOR against another employee's assignment fails closed.
 */
final class AcknowledgeIdorTest extends TestCase
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

	public function testAcknowledgeOtherEmployeesAssignmentIsForbidden(): void
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
		$empResult->method('fetch')->willReturn(['id' => 99]);

		$expr = new class {
			public function eq(...$a)
			{
				return 'eq';
			}
		};

		$mk = function ($result) use ($expr) {
			$qb = $this->createMock(IQueryBuilder::class);
			$qb->method('select')->willReturnSelf();
			$qb->method('from')->willReturnSelf();
			$qb->method('where')->willReturnSelf();
			$qb->method('andWhere')->willReturnSelf();
			$qb->method('expr')->willReturn($expr);
			$qb->method('createNamedParameter')->willReturn('p');
			$qb->method('executeQuery')->willReturn($result);
			return $qb;
		};

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnOnConsecutiveCalls(
			$mk($asgResult),
			$mk($empResult),
		);

		$svc = new RosterService($db);
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('FORBIDDEN');
		$svc->acknowledgeAssignment(42, 'eve');
	}
}
