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
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

class AccessControlServiceTest extends TestCase
{
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
}
