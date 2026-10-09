<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\Service;

use DG\BypassFinals;
use OCA\DutyCheck\Db\SchemaProbe;
use OCA\DutyCheck\Service\AccessControlService;
use OCA\DutyCheck\Service\AvailabilityBlackoutService;
use OCA\DutyCheck\Service\CompanyService;
use OCA\DutyCheck\Service\SelfServiceSettingsService;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

BypassFinals::enable();

/**
 * Deleting (or GDPR-purging) an availability blackout must not leave
 * dc_blackout_overrides.blackout_id dangling. The override audit row
 * (assignment + planner reason) is kept; only the dead reference is nulled —
 * blackout_id is nullable by design (recordOverride stores null for unbound).
 */
final class BlackoutDeleteOverrideCascadeTest extends TestCase
{
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

	public function testDeleteNullsOverrideBlackoutRefsBeforeDeletingBlackout(): void
	{
		$expr = new class {
			public function eq(...$a) { return 'eq'; }
			public function in(...$a) { return 'in'; }
		};

		$order = [];

		$blackoutRow = [
			'id' => 5, 'company_id' => 1, 'employee_id' => 9, 'location_id' => null,
			'start_at' => '2099-01-01 00:00:00', 'end_at' => '2099-01-02 00:00:00',
			'label_enum' => 'personal', 'created_by' => 'admin',
			'created_at' => '2099-01-01 00:00:00', 'updated_at' => '2099-01-01 00:00:00',
		];

		$resBlackout = $this->createMock(IResult::class);
		$resBlackout->method('fetch')->willReturn($blackoutRow);
		$qbGet = $this->stubSelect($expr, $resBlackout);

		$resCompany = $this->createMock(IResult::class);
		$resCompany->method('fetch')->willReturn(['company_id' => 1]);
		$qbCompany = $this->stubSelect($expr, $resCompany);

		$qbNull = $this->createMock(IQueryBuilder::class);
		$qbNull->method('update')->willReturnCallback(function (string $t) use (&$order, $qbNull) {
			$order[] = 'update:' . $t;
			return $qbNull;
		});
		$qbNull->method('set')->willReturnSelf();
		$qbNull->method('where')->willReturnSelf();
		$qbNull->method('expr')->willReturn($expr);
		$qbNull->method('createNamedParameter')->willReturn('p');
		$qbNull->method('executeStatement')->willReturn(2);

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
		$db->method('tableExists')->willReturn(true);
		$db->method('getQueryBuilder')->willReturnOnConsecutiveCalls($qbGet, $qbCompany, $qbNull, $qbDel);

		$access = $this->createMock(AccessControlService::class);
		$access->method('isPlannerOrAdmin')->willReturn(true);
		$access->method('isAppAdmin')->willReturn(true);
		$companies = $this->createMock(CompanyService::class);
		$companies->method('assertCanAccessCompany');
		$settings = $this->createMock(SelfServiceSettingsService::class);
		$settings->method('isBlackoutsEnabled')->willReturn(true);

		$svc = new AvailabilityBlackoutService($db, $companies, $access, $settings);
		$svc->delete(5, 'admin-actor');

		self::assertSame(
			['update:dc_blackout_overrides', 'delete:dc_avail_blackouts'],
			$order,
			'override refs must be nulled before the blackout row is deleted',
		);
	}

	public function testPurgeForUserNullsOverrideBlackoutRefs(): void
	{
		$expr = new class {
			public function eq(...$a) { return 'eq'; }
			public function in(...$a) { return 'in'; }
		};

		$resLink = $this->createMock(IResult::class);
		$resLink->method('fetch')->willReturn(['id' => 9]);
		$qbLink = $this->stubSelect($expr, $resLink);

		$resIds = $this->createMock(IResult::class);
		$resIds->method('fetchAll')->willReturn([['id' => 5], ['id' => 6]]);
		$qbIds = $this->stubSelect($expr, $resIds);

		$nulls = 0;
		$qbNull = $this->createMock(IQueryBuilder::class);
		$qbNull->expects($this->once())->method('update')->with('dc_blackout_overrides')->willReturnSelf();
		$qbNull->method('set')->willReturnCallback(function () use (&$nulls, $qbNull) {
			$nulls++;
			return $qbNull;
		});
		$qbNull->method('where')->willReturnSelf();
		$qbNull->method('expr')->willReturn($expr);
		$qbNull->method('createNamedParameter')->willReturn('p');
		$qbNull->method('executeStatement')->willReturn(3);

		$qbDel = $this->createMock(IQueryBuilder::class);
		$qbDel->expects($this->once())->method('delete')->with('dc_avail_blackouts')->willReturnSelf();
		$qbDel->method('where')->willReturnSelf();
		$qbDel->method('expr')->willReturn($expr);
		$qbDel->method('createNamedParameter')->willReturn('p');
		$qbDel->method('executeStatement')->willReturn(2);

		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturn(true);
		$db->method('getQueryBuilder')->willReturnOnConsecutiveCalls($qbLink, $qbIds, $qbNull, $qbDel);

		$svc = new AvailabilityBlackoutService(
			$db,
			$this->createMock(CompanyService::class),
			$this->createMock(AccessControlService::class),
			$this->createMock(SelfServiceSettingsService::class),
		);
		$svc->purgeForUser('employee-uid');

		self::assertSame(1, $nulls, 'purge must null blackout_id on referencing overrides');
	}

	private function stubSelect(object $expr, IResult $result): IQueryBuilder
	{
		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('select')->willReturnSelf();
		$qb->method('from')->willReturnSelf();
		$qb->method('where')->willReturnSelf();
		$qb->method('andWhere')->willReturnSelf();
		$qb->method('setMaxResults')->willReturnSelf();
		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturn('p');
		$qb->method('executeQuery')->willReturn($result);
		return $qb;
	}
}
