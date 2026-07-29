<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\Service;

use OCA\DutyCheck\Service\AccessControlService;
use OCA\DutyCheck\Service\PlannerLocationScopeService;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

final class PlannerLocationScopeServiceTest extends TestCase
{
	public function testAppAdminIsUnrestricted(): void
	{
		$access = $this->createMock(AccessControlService::class);
		$access->method('isAppAdmin')->with('admin')->willReturn(true);
		$db = $this->createMock(IDBConnection::class);
		$db->expects($this->never())->method('tableExists');
		$svc = new PlannerLocationScopeService($db, $access);
		self::assertSame([], $svc->locationIdsFor('admin'));
		// App admins stay unrestricted — must not throw for any location id.
		$svc->assertCanPlanLocation('admin', 99);
		$svc->assertCanPlanLocation('admin', 1);
		self::assertSame([], $svc->locationIdsFor('admin'), 'admin scope list stays empty (= unrestricted)');
	}

	public function testEmptyScopeAllowsAnyLocation(): void
	{
		$access = $this->createMock(AccessControlService::class);
		$access->method('isAppAdmin')->willReturn(false);
		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturn(false);
		$svc = new PlannerLocationScopeService($db, $access);
		self::assertSame([], $svc->locationIdsFor('planner'));
		$svc->assertCanPlanLocation('planner', 1);
		$svc->assertCanPlanLocation('planner', 999);
		self::assertSame([], $svc->locationIdsFor('planner'));
	}

	public function testSetScopeFailsClosedWhenTableMissing(): void
	{
		$access = $this->createMock(AccessControlService::class);
		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->with('dc_planner_locs')->willReturn(false);
		$svc = new PlannerLocationScopeService($db, $access);
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('SCHEMA_NOT_READY');
		$svc->setScope('planner', [1, 2]);
	}
}
