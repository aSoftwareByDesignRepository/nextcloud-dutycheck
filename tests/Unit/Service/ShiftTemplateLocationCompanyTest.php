<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\Service;

use OCA\DutyCheck\Db\SchemaProbe;
use OCA\DutyCheck\Service\CompanyService;
use OCA\DutyCheck\Service\ShiftTemplateService;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class ShiftTemplateLocationCompanyTest extends TestCase
{
	protected function setUp(): void
	{
		SchemaProbe::resetCache();
		$ref = new ReflectionClass(SchemaProbe::class);
		$prop = $ref->getProperty('columnCache');
		$prop->setAccessible(true);
		$prop->setValue(null, [
			'dc_shift_templates.company_id' => true,
			'dc_locations.company_id' => true,
		]);
	}

	protected function tearDown(): void
	{
		SchemaProbe::resetCache();
	}

	public function testCreateRejectsForeignLocationCompany(): void
	{
		$companies = $this->createMock(CompanyService::class);
		$companies->method('writeCompanyIdFor')->willReturn(1);
		$companies->method('isMultiCompanyActive')->willReturn(true);

		$locResult = $this->createMock(IResult::class);
		$locResult->method('fetch')->willReturn(['id' => 99, 'company_id' => 2]);

		$expr = new class {
			public function eq(...$a) { return 'eq'; }
			public function isNull(...$a) { return 'isNull'; }
			public function neq(...$a) { return 'neq'; }
		};
		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('select')->willReturnSelf();
		$qb->method('from')->willReturnSelf();
		$qb->method('where')->willReturnSelf();
		$qb->method('andWhere')->willReturnSelf();
		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturn('p');
		$qb->method('executeQuery')->willReturn($locResult);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);

		$svc = new ShiftTemplateService($db, $companies);
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('COMPANY_MISMATCH');
		$svc->create([
			'name' => 'Morning',
			'startTime' => '08:00',
			'endTime' => '12:00',
			'breakMinutes' => 0,
			'locationId' => 99,
		], 'planner');
	}
}
