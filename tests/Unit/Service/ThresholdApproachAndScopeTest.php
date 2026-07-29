<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\Service;

use OCA\DutyCheck\Service\ConflictPolicyService;
use OCA\DutyCheck\Service\PlannerLocationScopeService;
use OCA\DutyCheck\Service\ThresholdApproachNotifier;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Notification\IManager as INotificationManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class ThresholdApproachAndScopeTest extends TestCase
{
	public function testThresholdNotifierNoopsWhenDisabled(): void
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->with('dutycheck', 'threshold_approach_notify', '0')->willReturn('0');
		$notifications = $this->createMock(INotificationManager::class);
		$notifications->expects($this->never())->method('notify');
		$svc = new ThresholdApproachNotifier(
			$this->createMock(IDBConnection::class),
			$config,
			new ConflictPolicyService($this->createMock(IDBConnection::class)),
			$notifications,
			$this->createMock(LoggerInterface::class),
		);
		self::assertFalse($svc->isEnabled());
		$svc->notifyIfApproachingSoftCap(1, 2);
	}

	public function testEmptyPlannerScopeIsUnrestricted(): void
	{
		$access = $this->createMock(\OCA\DutyCheck\Service\AccessControlService::class);
		$access->method('isAppAdmin')->willReturn(false);
		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturn(false);
		$scope = new PlannerLocationScopeService($db, $access);
		self::assertSame([], $scope->locationIdsFor('alice'));
		$scope->assertCanPlanLocation('alice', 99);
		$scope->assertCanPlanLocation('alice', 1);
		self::assertSame([], $scope->locationIdsFor('alice'));
	}

	public function testScopedPlannerForbiddenOutside(): void
	{
		$access = $this->createMock(\OCA\DutyCheck\Service\AccessControlService::class);
		$access->method('isAppAdmin')->willReturn(false);
		$result = $this->createMock(\OCP\DB\IResult::class);
		$result->method('fetchAll')->willReturn([['location_id' => 5]]);
		$qb = $this->createMock(\OCP\DB\QueryBuilder\IQueryBuilder::class);
		$qb->method('select')->willReturnSelf();
		$qb->method('from')->willReturnSelf();
		$qb->method('where')->willReturnSelf();
		$qb->method('expr')->willReturn(new class {
			public function eq(...$a) { return 'eq'; }
		});
		$qb->method('createNamedParameter')->willReturn('p');
		$qb->method('executeQuery')->willReturn($result);
		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturn(true);
		$db->method('getQueryBuilder')->willReturn($qb);
		$scope = new PlannerLocationScopeService($db, $access);
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('FORBIDDEN');
		$scope->assertCanPlanLocation('bob', 9);
	}
}
