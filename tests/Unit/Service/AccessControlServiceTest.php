<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\Service;

use OCA\DutyCheck\Service\AccessControlService;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

class AccessControlServiceTest extends TestCase
{
	private function enabledUserMock(): IUser
	{
		$user = $this->createMock(IUser::class);
		$user->method('isEnabled')->willReturn(true);
		return $user;
	}

	private function service(IDBConnection $db): AccessControlService
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(function (string $appId, string $key, string $default): string {
			if ($key === 'access_restriction_enabled') {
				return '0';
			}
			if (str_ends_with($key, '_user_ids') || str_ends_with($key, '_group_ids')) {
				return '[]';
			}
			return $default;
		});

		return new AccessControlService(
			$db,
			$config,
			$this->createMock(IGroupManager::class),
			$this->createMock(IUserManager::class),
			$this->createMock(IUserSession::class),
		);
	}

	private function queryBuilderWithResults(IResult ...$results): IQueryBuilder
	{
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturn('expr');

		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturn('p');
		foreach (['select', 'from', 'where', 'andWhere', 'setMaxResults'] as $method) {
			$qb->method($method)->willReturnSelf();
		}
		$qb->method('executeQuery')->willReturnOnConsecutiveCalls(...$results);
		return $qb;
	}

	private function resultWithFetchOne(mixed $value): IResult
	{
		$r = $this->createMock(IResult::class);
		$r->method('fetchOne')->willReturn($value);
		return $r;
	}

	private function resultWithFetchRow(false|array $row): IResult
	{
		$r = $this->createMock(IResult::class);
		$r->method('fetch')->willReturn($row);
		$r->method('closeCursor')->willReturn(true);
		return $r;
	}

	public function testCanUseAppAllowsLinkedUserWithoutDcUserRolesRow(): void
	{
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn(
			$this->queryBuilderWithResults($this->resultWithFetchOne(42)),
		);

		$access = $this->service($db);
		self::assertTrue($access->canUseApp('only-linked'));
	}

	public function testCanUseAppAllowsPlannerWithoutEmployeeLink(): void
	{
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnOnConsecutiveCalls(
			$this->queryBuilderWithResults($this->resultWithFetchOne(false)),
			$this->queryBuilderWithResults($this->resultWithFetchRow(['role' => 'planner'])),
		);

		$access = $this->service($db);
		self::assertTrue($access->canUseApp('planner-unlinked'));
	}

	public function testCanUseAppAllowsEmployeeRoleWithoutLinkThroughOpenDoor(): void
	{
		$db = $this->createMock(IDBConnection::class);
		// needsRoleEnrollment + hasDutyMembership each hit link lookup then role lookup.
		$db->method('getQueryBuilder')->willReturnOnConsecutiveCalls(
			$this->queryBuilderWithResults($this->resultWithFetchOne(false)),
			$this->queryBuilderWithResults($this->resultWithFetchRow(['role' => 'employee'])),
			$this->queryBuilderWithResults($this->resultWithFetchOne(false)),
			$this->queryBuilderWithResults($this->resultWithFetchRow(['role' => 'employee'])),
		);

		$access = $this->service($db);
		self::assertTrue($access->canUseApp('employee-unlinked'), 'Open mode opens the door without membership');
		self::assertTrue($access->needsRoleEnrollment('employee-unlinked'));
		self::assertFalse($access->hasDutyMembership('employee-unlinked'));
	}

	public function testCanUseAppAllowsNoRoleThroughOpenDoorButNeedsEnrollment(): void
	{
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnOnConsecutiveCalls(
			$this->queryBuilderWithResults($this->resultWithFetchOne(false)),
			$this->queryBuilderWithResults($this->resultWithFetchRow(false)),
		);

		$access = $this->service($db);
		self::assertTrue($access->canUseApp('nobody'), 'Open mode must open the door without a DutyCheck role');
		self::assertTrue($access->needsRoleEnrollment('nobody'));
	}

	public function testSaveAppPolicyParsesStringFalseAsDisabledRestriction(): void
	{
		$db = $this->createMock(IDBConnection::class);
		$config = $this->createMock(IConfig::class);
		$groupManager = $this->createMock(IGroupManager::class);
		$userManager = $this->createMock(IUserManager::class);

		$config->method('getAppValue')->willReturnCallback(function (string $appId, string $key, string $default): string {
			if ($key === 'access_restriction_enabled') {
				return '0';
			}
			if (str_ends_with($key, '_user_ids') || str_ends_with($key, '_group_ids')) {
				return '[]';
			}
			return $default;
		});
		$userManager->method('get')->willReturn($this->enabledUserMock());
		$groupManager->method('groupExists')->willReturn(true);

		$writes = [];
		$config->method('setAppValue')->willReturnCallback(function (string $appId, string $key, string $value) use (&$writes): void {
			$writes[$key] = $value;
		});

		$access = new AccessControlService(
			$db,
			$config,
			$groupManager,
			$userManager,
			$this->createMock(IUserSession::class),
		);

		$policy = $access->saveAppPolicy([
			'appAdminUserIds' => ['admin1'],
			'accessRestrictionEnabled' => 'false',
			'allowedUserIds' => [],
			'allowedGroupIds' => [],
		]);

		self::assertFalse($policy['accessRestrictionEnabled']);
		self::assertSame('0', $writes['access_restriction_enabled'] ?? null);
	}

	public function testSaveAppPolicyRejectsInvalidRestrictionFlag(): void
	{
		$db = $this->createMock(IDBConnection::class);
		$config = $this->createMock(IConfig::class);
		$groupManager = $this->createMock(IGroupManager::class);
		$userManager = $this->createMock(IUserManager::class);

		$config->method('getAppValue')->willReturn('[]');
		$groupManager->method('groupExists')->willReturn(true);
		$userManager->method('get')->willReturn($this->enabledUserMock());

		$access = new AccessControlService(
			$db,
			$config,
			$groupManager,
			$userManager,
			$this->createMock(IUserSession::class),
		);

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('INVALID_ACCESS_RESTRICTION');

		$access->saveAppPolicy([
			'accessRestrictionEnabled' => 'maybe',
			'appAdminUserIds' => [],
			'allowedUserIds' => [],
			'allowedGroupIds' => [],
		]);
	}

	public function testSaveAppPolicyAcceptsSingleStringIdsFromFormParsers(): void
	{
		$db = $this->createMock(IDBConnection::class);
		$config = $this->createMock(IConfig::class);
		$groupManager = $this->createMock(IGroupManager::class);
		$userManager = $this->createMock(IUserManager::class);

		$config->method('getAppValue')->willReturn('[]');
		$groupManager->method('groupExists')->with('crew')->willReturn(true);
		$userManager->method('get')->willReturn($this->enabledUserMock());
		$writes = [];
		$config->method('setAppValue')->willReturnCallback(function (string $appId, string $key, string $value) use (&$writes): void {
			$writes[$key] = $value;
		});

		$access = new AccessControlService(
			$db,
			$config,
			$groupManager,
			$userManager,
			$this->createMock(IUserSession::class),
		);

		$access->saveAppPolicy([
			'appAdminUserIds' => 'admin1',
			'accessRestrictionEnabled' => '1',
			'allowedUserIds' => 'alice',
			'allowedGroupIds' => 'crew',
		]);

		self::assertSame('["admin1"]', $writes['app_admin_user_ids'] ?? null);
		self::assertSame('["alice"]', $writes['access_allowed_user_ids'] ?? null);
		self::assertSame('["crew"]', $writes['access_allowed_group_ids'] ?? null);
		self::assertSame('1', $writes['access_restriction_enabled'] ?? null);
	}

	public function testSaveAppPolicyAcceptsAssociativeArrayIds(): void
	{
		$db = $this->createMock(IDBConnection::class);
		$config = $this->createMock(IConfig::class);
		$groupManager = $this->createMock(IGroupManager::class);
		$userManager = $this->createMock(IUserManager::class);

		$config->method('getAppValue')->willReturn('[]');
		$groupManager->method('groupExists')->willReturnMap([
			['ops', true],
			['night', true],
		]);
		$userManager->method('get')->willReturn($this->enabledUserMock());
		$writes = [];
		$config->method('setAppValue')->willReturnCallback(function (string $appId, string $key, string $value) use (&$writes): void {
			$writes[$key] = $value;
		});

		$access = new AccessControlService(
			$db,
			$config,
			$groupManager,
			$userManager,
			$this->createMock(IUserSession::class),
		);

		$access->saveAppPolicy([
			'appAdminUserIds' => ['primary' => 'admin1', 'secondary' => 'admin2'],
			'accessRestrictionEnabled' => true,
			'allowedUserIds' => ['first' => 'alice', 'second' => 'bob'],
			'allowedGroupIds' => ['a' => 'ops', 'b' => 'night'],
		]);

		self::assertSame('["admin1","admin2"]', $writes['app_admin_user_ids'] ?? null);
		self::assertSame('["alice","bob"]', $writes['access_allowed_user_ids'] ?? null);
		self::assertSame('["ops","night"]', $writes['access_allowed_group_ids'] ?? null);
	}

	public function testSetDutyRoleUpsertsPlannerAndReturnsAssignments(): void
	{
		$user = $this->createMock(IUser::class);
		$user->method('getDisplayName')->willReturn('Pat Planner');
		$user->method('isEnabled')->willReturn(true);

		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->with('planner1')->willReturn($user);

		$deleteQb = $this->createMock(IQueryBuilder::class);
		$deleteQb->method('expr')->willReturn($this->createMock(IExpressionBuilder::class));
		$deleteQb->method('createNamedParameter')->willReturn('p');
		foreach (['delete', 'where'] as $method) {
			$deleteQb->method($method)->willReturnSelf();
		}
		$deleteQb->expects(self::once())->method('executeStatement');

		$insertQb = $this->createMock(IQueryBuilder::class);
		$insertQb->method('createNamedParameter')->willReturn('p');
		foreach (['insert', 'values'] as $method) {
			$insertQb->method($method)->willReturnSelf();
		}
		$insertQb->expects(self::once())->method('executeStatement');

		$listQb = $this->createMock(IQueryBuilder::class);
		$listExpr = $this->createMock(IExpressionBuilder::class);
		$listExpr->method('in')->willReturn('expr');
		$listQb->method('expr')->willReturn($listExpr);
		$listQb->method('createNamedParameter')->willReturn('p');
		foreach (['select', 'from', 'where', 'orderBy'] as $method) {
			$listQb->method($method)->willReturnSelf();
		}
		$listResult = $this->createMock(IResult::class);
		$listResult->method('fetch')->willReturnOnConsecutiveCalls(
			['user_id' => 'planner1', 'role' => 'planner', 'created_at' => '2026-06-15 10:00:00'],
			false,
		);
		$listResult->method('closeCursor')->willReturn(true);
		$listQb->method('executeQuery')->willReturn($listResult);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnOnConsecutiveCalls($deleteQb, $insertQb, $listQb);

		$access = new AccessControlService(
			$db,
			$this->createMock(IConfig::class),
			$this->createMock(IGroupManager::class),
			$userManager,
			$this->createMock(IUserSession::class),
		);

		$assignments = $access->setDutyRole('planner1', 'planner');
		self::assertCount(1, $assignments);
		self::assertSame('planner1', $assignments[0]['userId']);
		self::assertSame('Pat Planner', $assignments[0]['displayName']);
		self::assertSame('planner', $assignments[0]['role']);
		self::assertSame('2026-06-15 10:00:00', $assignments[0]['createdAt']);
	}

	public function testSetDutyRoleRejectsInvalidRole(): void
	{
		$access = $this->service($this->createMock(IDBConnection::class));
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('INVALID_DUTY_ROLE');
		$access->setDutyRole('planner1', 'admin');
	}

	public function testRemoveDutyRoleDeletesAndReturnsAssignments(): void
	{
		$deleteQb = $this->createMock(IQueryBuilder::class);
		$deleteQb->method('expr')->willReturn($this->createMock(IExpressionBuilder::class));
		$deleteQb->method('createNamedParameter')->willReturn('p');
		foreach (['delete', 'where'] as $method) {
			$deleteQb->method($method)->willReturnSelf();
		}
		$deleteQb->expects(self::once())->method('executeStatement');

		$listQb = $this->createMock(IQueryBuilder::class);
		$listExpr = $this->createMock(IExpressionBuilder::class);
		$listExpr->method('in')->willReturn('expr');
		$listQb->method('expr')->willReturn($listExpr);
		$listQb->method('createNamedParameter')->willReturn('p');
		foreach (['select', 'from', 'where', 'orderBy'] as $method) {
			$listQb->method($method)->willReturnSelf();
		}
		$listResult = $this->createMock(IResult::class);
		$listResult->method('fetch')->willReturn(false);
		$listResult->method('closeCursor')->willReturn(true);
		$listQb->method('executeQuery')->willReturn($listResult);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnOnConsecutiveCalls($deleteQb, $listQb);

		$access = new AccessControlService(
			$db,
			$this->createMock(IConfig::class),
			$this->createMock(IGroupManager::class),
			$this->createMock(IUserManager::class),
			$this->createMock(IUserSession::class),
		);

		$assignments = $access->removeDutyRole('planner1');
		self::assertSame([], $assignments);
	}

	public function testSystemAdminLookupIsMemoizedPerRequest(): void
	{
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->expects(self::once())->method('isAdmin')->with('root')->willReturn(true);

		$access = new AccessControlService(
			$this->createMock(IDBConnection::class),
			$this->openConfig(),
			$groupManager,
			$this->createMock(IUserManager::class),
			$this->createMock(IUserSession::class),
		);

		self::assertTrue($access->isAppAdmin('root'));
		self::assertTrue($access->isAppAdmin('root'));
		self::assertTrue($access->isPlannerOrAdmin('root'));
	}

	public function testGlobalRoleAndEmployeeLinkAreMemoizedPerRequest(): void
	{
		$db = $this->createMock(IDBConnection::class);
		$db->expects(self::exactly(2))->method('getQueryBuilder')->willReturnOnConsecutiveCalls(
			$this->queryBuilderWithResults($this->resultWithFetchRow(['role' => 'planner'])),
			$this->queryBuilderWithResults($this->resultWithFetchOne(false)),
		);

		$access = $this->service($db);
		self::assertTrue($access->isPlannerOrAdmin('pat'));
		self::assertTrue($access->isPlannerOrAdmin('pat'));
		self::assertFalse($access->isEmployee('pat'));
		self::assertFalse($access->hasActiveLinkedEmployee('pat'));
		self::assertTrue($access->hasDutyMembership('pat'));
	}

	public function testSaveAppPolicyInvalidatesAdminListCache(): void
	{
		$store = [
			'access_restriction_enabled' => '0',
			'app_admin_user_ids' => '[]',
			'access_allowed_user_ids' => '[]',
			'access_allowed_group_ids' => '[]',
		];
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static function (string $appId, string $key, string $default) use (&$store): string {
				return $store[$key] ?? $default;
			}
		);
		$config->method('setAppValue')->willReturnCallback(
			static function (string $appId, string $key, string $value) use (&$store): void {
				$store[$key] = $value;
			}
		);

		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->willReturn($this->enabledUserMock());

		$access = new AccessControlService(
			$this->createMock(IDBConnection::class),
			$config,
			$this->createMock(IGroupManager::class),
			$userManager,
			$this->createMock(IUserSession::class),
		);

		self::assertFalse($access->isAppAdmin('admin1'));
		$policy = $access->saveAppPolicy([
			'appAdminUserIds' => ['admin1'],
			'accessRestrictionEnabled' => false,
			'allowedUserIds' => [],
			'allowedGroupIds' => [],
		]);
		self::assertSame(['admin1'], $policy['appAdminUserIds']);
		self::assertTrue($access->isAppAdmin('admin1'));
	}

	private function openConfig(): IConfig
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(function (string $appId, string $key, string $default): string {
			if ($key === 'access_restriction_enabled') {
				return '0';
			}
			if (str_ends_with($key, '_user_ids') || str_ends_with($key, '_group_ids')) {
				return '[]';
			}
			return $default;
		});
		return $config;
	}
}
