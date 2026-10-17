<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\Service;

use OCA\DutyCheck\Db\SchemaProbe;
use OCA\DutyCheck\Service\CompanyService;
use OCA\DutyCheck\Service\StandbyCategoryService;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IFunctionBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Standby category CRUD — uniform not-found (no existence oracle across
 * companies), field validation, and the in-use delete guard.
 */
final class StandbyCategoryServiceTest extends TestCase
{
	protected function setUp(): void
	{
		SchemaProbe::resetCache();
		$ref = new ReflectionClass(SchemaProbe::class);
		$ref->getProperty('tableCache')->setValue(null, [
			'dc_standby_categories' => true,
		]);
		$ref->getProperty('columnCache')->setValue(null, [
			'dc_assignments.standby_category_id' => true,
		]);
	}

	protected function tearDown(): void
	{
		SchemaProbe::resetCache();
	}

	private function qb(mixed $result = null): IQueryBuilder
	{
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturn('eq');
		$expr->method('in')->willReturn('in');
		$func = $this->createMock(IFunctionBuilder::class);
		$func->method('count')->willReturn($this->createMock(IQueryFunction::class));
		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('select')->willReturnSelf();
		$qb->method('insert')->willReturnSelf();
		$qb->method('update')->willReturnSelf();
		$qb->method('delete')->willReturnSelf();
		$qb->method('from')->willReturnSelf();
		$qb->method('where')->willReturnSelf();
		$qb->method('andWhere')->willReturnSelf();
		$qb->method('orderBy')->willReturnSelf();
		$qb->method('set')->willReturnSelf();
		$qb->method('values')->willReturnSelf();
		$qb->method('expr')->willReturn($expr);
		$qb->method('func')->willReturn($func);
		$qb->method('createNamedParameter')->willReturn('p');
		if ($result instanceof IResult) {
			$qb->method('executeQuery')->willReturn($result);
		}
		return $qb;
	}

	private function result(array $row = [], array $rows = [], mixed $one = false): IResult
	{
		$res = $this->createMock(IResult::class);
		$res->method('fetch')->willReturn($row === [] ? false : $row);
		$res->method('fetchAll')->willReturn($rows);
		$res->method('fetchOne')->willReturn($one);
		return $res;
	}

	private function service(IDBConnection $db, ?CompanyService $companies = null): StandbyCategoryService
	{
		return new StandbyCategoryService($db, $companies ?? $this->createMock(CompanyService::class));
	}

	public function testResolveNullIsNull(): void
	{
		$db = $this->createMock(IDBConnection::class);
		$db->expects($this->never())->method('getQueryBuilder');
		$svc = $this->service($db);
		self::assertNull($svc->resolveForAssignment(5, null));
		self::assertNull($svc->resolveForAssignment(5, ''));
		self::assertNull($svc->resolveForAssignment(5, 0));
	}

	public function testResolveRejectsNonNumericAndNegative(): void
	{
		$svc = $this->service($this->createMock(IDBConnection::class));
		foreach (['abc', '2.9', ' 5', -3] as $bad) {
			try {
				$svc->resolveForAssignment(5, $bad);
				self::fail('expected STANDBY_CATEGORY_NOT_FOUND for ' . var_export($bad, true));
			} catch (\InvalidArgumentException $e) {
				self::assertSame('STANDBY_CATEGORY_NOT_FOUND', $e->getMessage());
			}
		}
	}

	public function testResolveCollapsesMissingForeignAndInactiveToSameCode(): void
	{
		$mkService = function (?array $row): StandbyCategoryService {
			$db = $this->createMock(IDBConnection::class);
			$db->method('getQueryBuilder')->willReturn($this->qb($this->result($row ?? [])));
			return $this->service($db);
		};
		$activeRow = ['id' => 7, 'company_id' => 5, 'label' => 'Nacht', 'color' => '#112233', 'default_response_time_minutes' => 30, 'active' => 1];

		// Missing row.
		$this->expectNotFound($mkService(null), 5, 7);
		// Foreign company — same canonical code (no existence oracle).
		$this->expectNotFound($mkService(array_merge($activeRow, ['company_id' => 9])), 5, 7);
		// Inactive — same code again.
		$this->expectNotFound($mkService(array_merge($activeRow, ['active' => 0])), 5, 7);
	}

	private function expectNotFound(StandbyCategoryService $svc, int $companyId, int $id): void
	{
		try {
			$svc->resolveForAssignment($companyId, $id);
			self::fail('expected STANDBY_CATEGORY_NOT_FOUND');
		} catch (\InvalidArgumentException $e) {
			self::assertSame('STANDBY_CATEGORY_NOT_FOUND', $e->getMessage());
		}
	}

	public function testResolveReturnsMappedRow(): void
	{
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($this->qb($this->result(
			// Real DB drivers return strings — the casts on company_id/active are load-bearing.
			['id' => '7', 'company_id' => '5', 'label' => 'Nacht', 'color' => '#112233', 'default_response_time_minutes' => '30', 'active' => '1'],
		)));
		$out = $this->service($db)->resolveForAssignment(5, '7');
		self::assertSame(7, $out['id']);
		self::assertSame(5, $out['companyId']);
		self::assertSame('Nacht', $out['label']);
		self::assertSame(30, $out['defaultResponseTimeMinutes']);
		self::assertTrue($out['active']);
	}

	public function testResolveBoundaryIdOneIsLookedUp(): void
	{
		// `$id < 1` guard: id=1 is valid and must reach the DB, not short-circuit.
		$db = $this->createMock(IDBConnection::class);
		$db->expects($this->once())->method('getQueryBuilder')->willReturn($this->qb($this->result(
			['id' => '1', 'company_id' => '5', 'label' => 'Ruf', 'color' => null, 'default_response_time_minutes' => null, 'active' => '1'],
		)));
		self::assertSame(1, $this->service($db)->resolveForAssignment(5, 1)['id']);
	}

	public function testListForCompanyFiltersInactiveByDefault(): void
	{
		$activeOnly = $this->qb($this->result([], [
			['id' => '1', 'company_id' => '5', 'label' => 'Tag', 'color' => null, 'default_response_time_minutes' => null, 'active' => '1'],
		]));
		$activeOnly->expects($this->once())->method('andWhere');
		$all = $this->qb($this->result());
		$all->expects($this->never())->method('andWhere');
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnOnConsecutiveCalls($activeOnly, $all);
		$svc = $this->service($db);
		self::assertCount(1, $svc->listForCompany(5));
		$svc->listForCompany(5, true);
	}

	public function testCreateRejectsInvalidLabelColorAndResponse(): void
	{
		$qb = $this->qb();
		$qb->method('executeStatement')->willReturn(1);
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);
		$svc = $this->service($db);

		foreach ([null, '', '   ', 42] as $badLabel) {
			try {
				$svc->create(5, 'alice', $badLabel, null, null);
				self::fail('expected STANDBY_CATEGORY_INVALID for label');
			} catch (\InvalidArgumentException $e) {
				self::assertSame('STANDBY_CATEGORY_INVALID', $e->getMessage());
			}
		}
		try {
			$svc->create(5, 'alice', str_repeat('x', 121), null, null);
			self::fail('expected STANDBY_CATEGORY_INVALID for long label');
		} catch (\InvalidArgumentException $e) {
			self::assertSame('STANDBY_CATEGORY_INVALID', $e->getMessage());
		}
		foreach (['red', '#12345', '#12345g', 'x#aabbcc', '#aabbccx', '#aabbcc extra', 12] as $badColor) {
			try {
				$svc->create(5, 'alice', 'Nacht', $badColor, null);
				self::fail('expected STANDBY_CATEGORY_INVALID for color ' . var_export($badColor, true));
			} catch (\InvalidArgumentException $e) {
				self::assertSame('STANDBY_CATEGORY_INVALID', $e->getMessage());
			}
		}
		foreach ([-1, 10081, 'abc', '3.5'] as $badMinutes) {
			try {
				$svc->create(5, 'alice', 'Nacht', null, $badMinutes);
				self::fail('expected INVALID_RESPONSE_TIME for ' . var_export($badMinutes, true));
			} catch (\InvalidArgumentException $e) {
				self::assertSame('INVALID_RESPONSE_TIME', $e->getMessage());
			}
		}
	}

	public function testCreateNormalizesColorAndBoundaryMinutes(): void
	{
		$qb = $this->qb();
		$qb->method('executeStatement')->willReturn(1);
		$qb->method('getLastInsertId')->willReturn(11);
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);
		$svc = $this->service($db);

		$out = $svc->create(5, 'alice', ' Nacht ', 'AABBCC', '10080');
		self::assertSame('Nacht', $out['label']);
		self::assertSame('#aabbcc', $out['color']);
		self::assertSame(10080, $out['defaultResponseTimeMinutes']);
		self::assertTrue($out['active']);
		self::assertSame(0, $svc->create(5, 'alice', 'Tag', null, 0)['defaultResponseTimeMinutes']);
		// Padded color is trimmed before validation.
		self::assertSame('#aabbcc', $svc->create(5, 'alice', 'Tag', ' #AABBCC ', null)['color']);
	}

	public function testCreateAcceptsMultibyteLabelAtBoundary(): void
	{
		$qb = $this->qb();
		$qb->method('executeStatement')->willReturn(1);
		$qb->method('getLastInsertId')->willReturn(11);
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);
		$svc = $this->service($db);

		// mb_strlen counts characters, not bytes — a 120-char umlaut label is legal.
		self::assertSame(120, mb_strlen($svc->create(5, 'alice', str_repeat('ä', 120), null, null)['label']));
		try {
			$svc->create(5, 'alice', str_repeat('ä', 121), null, null);
			self::fail('expected STANDBY_CATEGORY_INVALID at 121 chars');
		} catch (\InvalidArgumentException $e) {
			self::assertSame('STANDBY_CATEGORY_INVALID', $e->getMessage());
		}
	}

	public function testUpdateKeepsCurrentValuesOnPartialPatch(): void
	{
		$row = ['id' => '7', 'company_id' => '5', 'label' => 'Nacht', 'color' => '#112233', 'default_response_time_minutes' => '45', 'active' => '1'];
		$updateQb = $this->qb();
		$updateQb->method('executeStatement')->willReturn(1);
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnOnConsecutiveCalls($this->qb($this->result($row)), $updateQb);
		$out = $this->service($db)->update(5, 'alice', 7, ['label' => 'Tag']);
		self::assertSame('Tag', $out['label']);
		self::assertSame('#112233', $out['color']);
		self::assertSame(45, $out['defaultResponseTimeMinutes']);
		self::assertTrue($out['active']);
	}

	public function testUpdateRejectsInvalidActiveValue(): void
	{
		$row = ['id' => '7', 'company_id' => '5', 'label' => 'Nacht', 'color' => null, 'default_response_time_minutes' => null, 'active' => '1'];
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($this->qb($this->result($row)));
		foreach (['yes', 2, []] as $bad) {
			try {
				$this->service($db)->update(5, 'alice', 7, ['active' => $bad]);
				self::fail('expected INVALID_BOOLEAN');
			} catch (\InvalidArgumentException $e) {
				self::assertSame('INVALID_BOOLEAN', $e->getMessage());
			}
		}
	}

	public function testUpdateMissingRowIsNotFound(): void
	{
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($this->qb($this->result([])));
		try {
			$this->service($db)->update(5, 'alice', 7, ['label' => 'X']);
			self::fail('expected STANDBY_CATEGORY_NOT_FOUND');
		} catch (\InvalidArgumentException $e) {
			self::assertSame('STANDBY_CATEGORY_NOT_FOUND', $e->getMessage());
		}
	}

	public function testUpdateParsesFormEncodedBooleans(): void
	{
		$row = ['id' => '7', 'company_id' => '5', 'label' => 'Nacht', 'color' => null, 'default_response_time_minutes' => null, 'active' => '1'];
		$fetchQb = $this->qb($this->result($row));
		$updateQb = $this->qb();
		$updateQb->method('executeStatement')->willReturn(1);
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnOnConsecutiveCalls($fetchQb, $updateQb, $fetchQb, $updateQb, $fetchQb, $updateQb);
		$svc = $this->service($db);

		// Form-encoded "false" must deactivate — never be treated as truthy.
		self::assertFalse($svc->update(5, 'alice', 7, ['active' => 'false'])['active']);
		self::assertFalse($svc->update(5, 'alice', 7, ['active' => '0'])['active']);
		self::assertTrue($svc->update(5, 'alice', 7, ['active' => 'true'])['active']);
	}

	public function testDeleteRefusedWhileInUse(): void
	{
		$row = ['id' => '7', 'company_id' => '5', 'label' => 'Nacht', 'color' => null, 'default_response_time_minutes' => null, 'active' => '1'];
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnOnConsecutiveCalls(
			$this->qb($this->result($row)),          // fetchRow
			$this->qb($this->result([], [], 3)),     // inUseCount → 3
		);
		try {
			$this->service($db)->delete(5, 'alice', 7);
			self::fail('expected STANDBY_CATEGORY_IN_USE');
		} catch (\InvalidArgumentException $e) {
			self::assertSame('STANDBY_CATEGORY_IN_USE', $e->getMessage());
		}
	}

	public function testDeleteExecutesWhenUnused(): void
	{
		$row = ['id' => '7', 'company_id' => '5', 'label' => 'Nacht', 'color' => null, 'default_response_time_minutes' => null, 'active' => '1'];
		$deleteQb = $this->qb();
		$deleteQb->expects($this->once())->method('executeStatement')->willReturn(1);
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnOnConsecutiveCalls(
			$this->qb($this->result($row)),
			$this->qb($this->result([], [], 0)),
			$deleteQb,
		);
		$this->service($db)->delete(5, 'alice', 7);
	}

	public function testMissingSchemaFailsClosed(): void
	{
		SchemaProbe::resetCache();
		$ref = new ReflectionClass(SchemaProbe::class);
		$ref->getProperty('tableCache')->setValue(null, ['dc_standby_categories' => false]);
		$svc = $this->service($this->createMock(IDBConnection::class));
		try {
			$svc->resolveForAssignment(5, 7);
			self::fail('expected SCHEMA_NOT_READY');
		} catch (\InvalidArgumentException $e) {
			self::assertSame('SCHEMA_NOT_READY', $e->getMessage());
		}
		self::assertSame([], $svc->listForCompany(5));
	}
}
