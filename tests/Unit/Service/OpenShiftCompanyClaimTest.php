<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\Service;

use OCA\DutyCheck\Db\SchemaProbe;
use OCA\DutyCheck\Service\CompanyService;
use OCA\DutyCheck\Service\OpenShiftService;
use OCA\DutyCheck\Service\RosterService;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class OpenShiftCompanyClaimTest extends TestCase
{
	protected function setUp(): void
	{
		SchemaProbe::resetCache();
		$ref = new ReflectionClass(SchemaProbe::class);
		$prop = $ref->getProperty('columnCache');
		$prop->setAccessible(true);
		$prop->setValue(null, [
			'dc_open_shifts.company_id' => true,
			'dc_employees.company_id' => true,
			'dc_periods.company_id' => true,
		]);
	}

	protected function tearDown(): void
	{
		SchemaProbe::resetCache();
	}

	public function testClaimRejectsCrossCompanyEmployee(): void
	{
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
			$qb->method('leftJoin')->willReturnSelf();
			$qb->method('where')->willReturnSelf();
			$qb->method('andWhere')->willReturnSelf();
			$qb->method('expr')->willReturn($expr);
			$qb->method('createNamedParameter')->willReturn('p');
			$qb->method('executeQuery')->willReturn($result);
			return $qb;
		};

		$link = $this->createMock(IResult::class);
		$link->method('fetch')->willReturn(['id' => 10]);

		$open = $this->createMock(IResult::class);
		$open->method('fetch')->willReturn([
			'id' => 5,
			'period_id' => 2,
			'location_id' => 3,
			'template_id' => null,
			'duty_date' => '2026-07-28',
			'start_time' => '08:00',
			'end_time' => '16:00',
			'break_minutes' => 0,
			'status' => 'open',
			'claimed_by_emp' => null,
			'assignment_id' => null,
		]);

		$periodCo = $this->createMock(IResult::class);
		$periodCo->method('fetch')->willReturn(['company_id' => 1]);
		$empCo = $this->createMock(IResult::class);
		$empCo->method('fetch')->willReturn(['company_id' => 2]);

		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturn(true);
		$db->method('getQueryBuilder')->willReturnOnConsecutiveCalls(
			$mk($link),
			$mk($open),
			$mk($periodCo),
			$mk($empCo),
		);

		$companies = $this->createMock(CompanyService::class);
		$companies->method('isMultiCompanyActive')->willReturn(true);
		$companies->method('schemaReady')->willReturn(true);

		$roster = $this->createMock(RosterService::class);
		$svc = new OpenShiftService($db, $roster, $companies);

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('COMPANY_MISMATCH');
		$svc->claim(5, 'alice');
	}
}
