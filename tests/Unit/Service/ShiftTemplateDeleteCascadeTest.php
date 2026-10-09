<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\Service;

use OCA\DutyCheck\Db\SchemaProbe;
use OCA\DutyCheck\Service\ShiftTemplateService;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Deleting a shift template must not leave template_id /
 * shift_template_id references dangling on dc_open_shifts and
 * dc_rotation_week_days. Both columns are nullable — "no template" is a
 * legal state — so the delete nulls them first.
 */
final class ShiftTemplateDeleteCascadeTest extends TestCase
{
	protected function setUp(): void
	{
		parent::setUp();
		SchemaProbe::resetCache();
		$ref = new ReflectionClass(SchemaProbe::class);
		$tables = $ref->getProperty('tableCache');
		$tables->setValue(null, [
			'dc_open_shifts' => true,
			'dc_rotation_week_days' => true,
		]);
		$cols = $ref->getProperty('columnCache');
		$cols->setValue(null, [
			'dc_open_shifts.template_id' => true,
			'dc_rotation_week_days.shift_template_id' => true,
		]);
	}

	protected function tearDown(): void
	{
		SchemaProbe::resetCache();
		parent::tearDown();
	}

	public function testDeleteNullsChildTemplateRefsBeforeDeletingTemplate(): void
	{
		$expr = new class {
			public function eq(...$a) { return 'eq'; }
		};

		$order = [];

		$resGet = $this->createMock(IResult::class);
		$resGet->method('fetch')->willReturn([
			'id' => 7, 'location_id' => 2, 'name' => 'Early',
			'start_time' => '06:00', 'end_time' => '14:00', 'break_minutes' => 30,
			'min_headcount' => 1, 'active' => 1,
		]);
		$qbGet = $this->createMock(IQueryBuilder::class);
		$qbGet->method('select')->willReturnSelf();
		$qbGet->method('from')->willReturnSelf();
		$qbGet->method('where')->willReturnSelf();
		$qbGet->method('expr')->willReturn($expr);
		$qbGet->method('createNamedParameter')->willReturn('p');
		$qbGet->method('executeQuery')->willReturn($resGet);

		$mkUpdate = function (string $expectTable, string $expectCol) use (&$order, $expr): IQueryBuilder {
			$qb = $this->createMock(IQueryBuilder::class);
			$qb->expects($this->once())->method('update')->with($expectTable)->willReturnSelf();
			$qb->method('set')->willReturnCallback(function (string $col) use (&$order, $expectTable, $expectCol, $qb) {
				self::assertSame($expectCol, $col);
				$order[] = 'update:' . $expectTable;
				return $qb;
			});
			$qb->method('where')->willReturnSelf();
			$qb->method('expr')->willReturn($expr);
			$qb->method('createNamedParameter')->willReturn('p');
			$qb->method('executeStatement')->willReturn(1);
			return $qb;
		};

		$qbNullOpen = $mkUpdate('dc_open_shifts', 'template_id');
		$qbNullWeek = $mkUpdate('dc_rotation_week_days', 'shift_template_id');

		$qbDel = $this->createMock(IQueryBuilder::class);
		$qbDel->method('delete')->willReturnCallback(function (string $t) use (&$order, $qbDel) {
			$order[] = 'delete:' . $t;
			return $qbDel;
		});
		$qbDel->method('where')->willReturnSelf();
		$qbDel->method('expr')->willReturn($expr);
		$qbDel->method('createNamedParameter')->willReturn('p');
		$qbDel->method('executeStatement')->willReturn(1);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnOnConsecutiveCalls($qbGet, $qbNullOpen, $qbNullWeek, $qbDel);

		$svc = new ShiftTemplateService($db);
		$svc->delete(7);

		self::assertSame(
			['update:dc_open_shifts', 'update:dc_rotation_week_days', 'delete:dc_shift_templates'],
			$order,
			'child template references must be nulled before the template row is deleted',
		);
	}
}
