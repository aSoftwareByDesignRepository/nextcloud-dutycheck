<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\Service;

use OCA\DutyCheck\Service\RosterService;
use OCA\DutyCheck\Tests\Unit\Support\IntegrationQueryBuilderTrait;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

class RosterServiceEmployeeDisplayNameTest extends TestCase
{
	use IntegrationQueryBuilderTrait;

	public function testCreateEmployeeDerivesDisplayNameFromLinkedUserWhenOmitted(): void
	{
		$user = $this->createMock(IUser::class);
		$user->method('getDisplayName')->willReturn('Alex Example');

		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->with('alex')->willReturn($user);

		$insertQb = $this->createMock(IQueryBuilder::class);
		$insertQb->method('expr')->willReturn($this->qbExpr());
		$insertQb->method('createNamedParameter')->willReturn('p');
		$insertQb->method('insert')->willReturnSelf();
		$insertQb->method('values')->willReturnSelf();
		$insertQb->expects(self::once())->method('executeStatement')->willReturn(1);

		$catalogQb = $this->qbFetchAllAssociative([
			[
				'id' => 1,
				'display_name' => 'Alex Example',
				'linked_user_id' => 'alex',
				'active' => 1,
				'created_at' => '2026-06-01 00:00:00',
			],
		]);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnOnConsecutiveCalls(
			$this->qbFetchOne(false), // assertEmployeeDisplayNameUnique
			$this->qbFetchOne(false), // assertLinkedUserUnique
			$insertQb,
			$catalogQb,
		);

		$roster = new RosterService($db, $userManager, null);
		$out = $roster->createEmployee([
			'displayName' => '',
			'linkedUserId' => 'alex',
			'active' => true,
		]);

		self::assertCount(1, $out);
		self::assertSame('Alex Example', $out[0]['displayName']);
		self::assertSame('alex', $out[0]['linkedUserId']);
	}

	public function testCreateEmployeeFallsBackToUidWhenLinkedUserHasNoDisplayName(): void
	{
		$user = $this->createMock(IUser::class);
		$user->method('getDisplayName')->willReturn('');

		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->with('patrol-1')->willReturn($user);

		$insertQb = $this->createMock(IQueryBuilder::class);
		$insertQb->method('expr')->willReturn($this->qbExpr());
		$insertQb->method('createNamedParameter')->willReturn('p');
		$insertQb->method('insert')->willReturnSelf();
		$insertQb->method('values')->willReturnSelf();
		$insertQb->expects(self::once())->method('executeStatement')->willReturn(1);

		$catalogQb = $this->qbFetchAllAssociative([
			[
				'id' => 2,
				'display_name' => 'patrol-1',
				'linked_user_id' => 'patrol-1',
				'active' => 1,
				'created_at' => '2026-06-01 00:00:00',
			],
		]);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnOnConsecutiveCalls(
			$this->qbFetchOne(false),
			$this->qbFetchOne(false),
			$insertQb,
			$catalogQb,
		);

		$roster = new RosterService($db, $userManager, null);
		$out = $roster->createEmployee([
			'linkedUserId' => 'patrol-1',
			'active' => true,
		]);

		self::assertSame('patrol-1', $out[0]['displayName']);
	}

	public function testCreateEmployeeRequiresDisplayNameWhenUnlinked(): void
	{
		$db = $this->createMock(IDBConnection::class);
		$db->expects(self::never())->method('getQueryBuilder');

		$roster = new RosterService($db, null, null);
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('INVALID_DISPLAY_NAME');
		$roster->createEmployee([
			'displayName' => '   ',
			'active' => true,
		]);
	}

	public function testCreateEmployeePrefersExplicitDisplayNameOverLinkedProfile(): void
	{
		$user = $this->createMock(IUser::class);
		$user->method('getDisplayName')->willReturn('Directory Name');

		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->with('alex')->willReturn($user);

		$insertQb = $this->createMock(IQueryBuilder::class);
		$insertQb->method('expr')->willReturn($this->qbExpr());
		$insertQb->method('createNamedParameter')->willReturn('p');
		$insertQb->method('insert')->willReturnSelf();
		$insertQb->method('values')->willReturnSelf();
		$insertQb->expects(self::once())->method('executeStatement')->willReturn(1);

		$catalogQb = $this->qbFetchAllAssociative([
			[
				'id' => 3,
				'display_name' => 'Roster Label',
				'linked_user_id' => 'alex',
				'active' => 1,
				'created_at' => '2026-06-01 00:00:00',
			],
		]);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnOnConsecutiveCalls(
			$this->qbFetchOne(false),
			$this->qbFetchOne(false),
			$insertQb,
			$catalogQb,
		);

		$roster = new RosterService($db, $userManager, null);
		$out = $roster->createEmployee([
			'displayName' => 'Roster Label',
			'linkedUserId' => 'alex',
			'active' => true,
		]);

		self::assertSame('Roster Label', $out[0]['displayName']);
	}

	public function testCreateEmployeeRejectsControlCharactersInDerivedName(): void
	{
		$user = $this->createMock(IUser::class);
		$user->method('getDisplayName')->willReturn("Bad\x07Name");

		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->with('alex')->willReturn($user);

		$db = $this->createMock(IDBConnection::class);
		$db->expects(self::never())->method('getQueryBuilder');

		$roster = new RosterService($db, $userManager, null);
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('INVALID_DISPLAY_NAME');
		$roster->createEmployee([
			'linkedUserId' => 'alex',
			'active' => true,
		]);
	}
}
