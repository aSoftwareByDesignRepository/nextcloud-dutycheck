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
 * Regression pin (0.3.4 bug class): the client posts mutations as
 * x-www-form-urlencoded, so `active` arrives as the literal strings
 * "true"/"false". A bare `(int)` cast stores 0 for BOTH — "true" silently
 * deactivated the template. update() must parse strictly via
 * ApiMutationParams::boolValue().
 */
final class ShiftTemplateActiveBoolTest extends TestCase
{
	protected function setUp(): void
	{
		SchemaProbe::resetCache();
		$ref = new ReflectionClass(SchemaProbe::class);
		$prop = $ref->getProperty('columnCache');
		$prop->setValue(null, [
			'dc_shift_templates.min_headcount' => true,
			'dc_shift_templates.company_id' => false,
		]);
	}

	protected function tearDown(): void
	{
		SchemaProbe::resetCache();
	}

	private function existingRow(): array
	{
		return [
			'id' => 5,
			'location_id' => null,
			'name' => 'Morning',
			'start_time' => '08:00',
			'end_time' => '12:00',
			'break_minutes' => 0,
			'min_headcount' => 0,
			'active' => 1,
		];
	}

	/**
	 * @return array{0: ShiftTemplateService, 1: callable(): array<string,mixed>}
	 *   Returns the service plus a getter for the set() column→value map.
	 */
	private function buildService(array &$sets): array
	{
		$expr = new class {
			public function eq(...$a) { return 'eq'; }
			public function isNull(...$a) { return 'isNull'; }
			public function andX(...$a) { return 'andX'; }
			public function neq(...$a) { return 'neq'; }
		};

		$existing = $this->existingRow();

		$qbGet1 = $this->createMock(IQueryBuilder::class);
		$qbGet1->method('select')->willReturnSelf();
		$qbGet1->method('from')->willReturnSelf();
		$qbGet1->method('where')->willReturnSelf();
		$qbGet1->method('expr')->willReturn($expr);
		$qbGet1->method('createNamedParameter')->willReturnCallback(static fn ($v) => ['v' => $v]);
		$rGet1 = $this->createMock(IResult::class);
		$rGet1->method('fetch')->willReturn($existing);
		$qbGet1->method('executeQuery')->willReturn($rGet1);

		// assertNameUnique: no conflicting row.
		$qbUniq = $this->createMock(IQueryBuilder::class);
		$qbUniq->method('select')->willReturnSelf();
		$qbUniq->method('from')->willReturnSelf();
		$qbUniq->method('where')->willReturnSelf();
		$qbUniq->method('andWhere')->willReturnSelf();
		$qbUniq->method('expr')->willReturn($expr);
		$qbUniq->method('createNamedParameter')->willReturnCallback(static fn ($v) => ['v' => $v]);
		$rUniq = $this->createMock(IResult::class);
		$rUniq->method('fetch')->willReturn(false);
		$qbUniq->method('executeQuery')->willReturn($rUniq);

		$qbUpd = $this->createMock(IQueryBuilder::class);
		$qbUpd->method('update')->willReturnSelf();
		$qbUpd->method('set')->willReturnCallback(static function (string $col, $param) use (&$sets, $qbUpd) {
			$sets[$col] = is_array($param) && array_key_exists('v', $param) ? $param['v'] : $param;
			return $qbUpd;
		});
		$qbUpd->method('where')->willReturnSelf();
		$qbUpd->method('expr')->willReturn($expr);
		$qbUpd->method('createNamedParameter')->willReturnCallback(static fn ($v) => ['v' => $v]);
		$qbUpd->method('executeStatement')->willReturn(1);

		$qbGet2 = $this->createMock(IQueryBuilder::class);
		$qbGet2->method('select')->willReturnSelf();
		$qbGet2->method('from')->willReturnSelf();
		$qbGet2->method('where')->willReturnSelf();
		$qbGet2->method('expr')->willReturn($expr);
		$qbGet2->method('createNamedParameter')->willReturnCallback(static fn ($v) => ['v' => $v]);
		$rGet2 = $this->createMock(IResult::class);
		$rGet2->method('fetch')->willReturn($existing);
		$qbGet2->method('executeQuery')->willReturn($rGet2);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnOnConsecutiveCalls($qbGet1, $qbUniq, $qbUpd, $qbGet2);

		return [new ShiftTemplateService($db), static fn () => $sets];
	}

	public function testUpdateActiveFalseStringStoresZero(): void
	{
		$sets = [];
		[$svc] = $this->buildService($sets);
		$svc->update(5, ['active' => 'false']);
		self::assertSame(0, $sets['active']);
	}

	public function testUpdateActiveTrueStringStoresOne(): void
	{
		$sets = [];
		[$svc] = $this->buildService($sets);
		// (int) "true" === 0 — the shipped corruption this pins against.
		$svc->update(5, ['active' => 'true']);
		self::assertSame(1, $sets['active']);
	}

	public function testUpdateRejectsAmbiguousActive(): void
	{
		$sets = [];
		[$svc] = $this->buildService($sets);
		$this->expectException(\InvalidArgumentException::class);
		$svc->update(5, ['active' => 'banana']);
	}
}
