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

	public function testCanUseAppDeniesEmployeeRoleWithoutLink(): void
	{
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnOnConsecutiveCalls(
			$this->queryBuilderWithResults($this->resultWithFetchOne(false)),
			$this->queryBuilderWithResults($this->resultWithFetchRow(['role' => 'employee'])),
		);

		$access = $this->service($db);
		self::assertFalse($access->canUseApp('employee-unlinked'));
	}

	public function testCanUseAppDeniesNoRoleAndNoLink(): void
	{
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnOnConsecutiveCalls(
			$this->queryBuilderWithResults($this->resultWithFetchOne(false)),
			$this->queryBuilderWithResults($this->resultWithFetchRow(false)),
		);

		$access = $this->service($db);
		self::assertFalse($access->canUseApp('nobody'));
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
}
